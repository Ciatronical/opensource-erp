<?php
// backend/api/customer_vendor/call_transcription.php

require_once __DIR__.'/../lxcars/instructions.php';

/**
 * Generiert einen Werkstattauftrag aus einer Telefonaufnahme.
 * Ablauf: WAV → Whisper API (Transkription) → Claude API (Datenextraktion) → Auftrag erstellen
 *
 * @param int $data['unique_call_id'] Eindeutige Call-ID (Asterisk)
 * @param int $data['customer_id'] Kunden-ID
 */
function generateOrderFromCall($data) {
    set_time_limit(120);

    $uniqueCallId = $data['unique_call_id'] ?? '';
    $customerId = intval($data['customer_id'] ?? 0);

    if (empty($uniqueCallId) || !$customerId) {
        throw new ApiError('VALIDATION_ERROR', 'unique_call_id und customer_id erforderlich');
    }

    $db = DbhCompany::begin();

    // 1. API-Keys laden
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('openai_api_key', 'anthropic_api_key')"
    );
    $openaiKey = trim($config['openai_api_key'] ?? '');
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');

    if (empty($openaiKey)) {
        throw new ApiError('MISSING_API_KEYS', 'OpenAI API-Key ist nicht konfiguriert (CRM-Einstellungen → LxCars)');
    }
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (CRM-Einstellungen → LxCars)');
    }

    // 2. WAV-Datei finden (Logik aus playPhoneCall)
    $monitorDir = TELEPHONY_MONITOR_DIR;
    $wavFile = null;
    foreach (scandir($monitorDir) as $file) {
        if (strpos($file, $uniqueCallId) !== false && filesize($monitorDir . '/' . $file) > 44) {
            $wavFile = $monitorDir . '/' . $file;
            break;
        }
    }

    if (!$wavFile) {
        throw new ApiError('FILE_NOT_FOUND', 'Keine Aufnahme gefunden für diese Call-ID');
    }

    // 3. Whisper API – Transkription
    $transcript = _callWhisperApi($openaiKey, $wavFile);

    // 4. Claude API – Datenextraktion
    $extractedData = _callClaudeApi($anthropicKey, $transcript);

    // 5. Auftrag erstellen
    $db->beginTransaction();
    try {
        // Employee-ID aus Session
        $auth = DbhAuth::begin();
        $login = $auth->getLogin();
        $employee = $db->getOne("SELECT id FROM employee WHERE login = :login", ['login' => $login]);
        $employeeId = $employee ? intval($employee['id']) : null;

        // Defaults
        $defaults = $db->getOne("SELECT currency_id FROM defaults LIMIT 1");
        $currencyId = intval($defaults['currency_id']);

        // Steuerzone aus Kundenstamm
        $cvData = $db->getOne("SELECT taxzone_id FROM customer WHERE id = :id", ['id' => $customerId]);
        $taxzoneId = $cvData ? intval($cvData['taxzone_id']) : null;
        if (!$taxzoneId) {
            $taxzone = $db->getOne("SELECT id FROM tax_zones ORDER BY id LIMIT 1");
            $taxzoneId = $taxzone ? intval($taxzone['id']) : 4;
        }

        // Auftrag anlegen (atomarer CTE wie in createFaktura)
        $result = $db->getOne(<<<SQL
            WITH tmp AS (UPDATE defaults SET sonumber = COALESCE(sonumber::INT, 0) + 1 RETURNING sonumber)
            INSERT INTO oe (ordnumber, transdate, employee_id, customer_id, taxzone_id, currency_id, record_type)
            SELECT (SELECT sonumber FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, 'sales_order'
            RETURNING id, ordnumber
SQL, [
            ':employee_id' => $employeeId,
            ':customer_id' => $customerId,
            ':taxzone_id' => $taxzoneId,
            ':currency_id' => $currencyId
        ]);

        $oeId = intval($result['id']);
        $ordnumber = $result['ordnumber'];

        // Fahrzeugdaten aus Extraktion
        $kennzeichen = $extractedData['kennzeichen'] ?? null;
        $bringetermin = $extractedData['bringetermin'] ?? null;
        $fertigstellung = $extractedData['fertigstellung'] ?? null;
        $kmStand = isset($extractedData['km_stand']) ? intval($extractedData['km_stand']) : null;

        // Fahrzeug per Kennzeichen suchen (nur Fahrzeuge dieses Kunden)
        $carId = null;
        if ($kennzeichen) {
            $car = $db->getOne(
                "SELECT c_id FROM cars_lxcars WHERE REPLACE(c_ln, ' ', '') = REPLACE(:kz, ' ', '') AND c_ow = :owner",
                [':kz' => $kennzeichen, ':owner' => $customerId]
            );
            $carId = $car ? intval($car['c_id']) : null;
        }

        // oe_ext mit Fahrzeug- und Termindaten
        $db->execute(<<<SQL
            INSERT INTO oe_ext (oe_id, c_id, km_stand, bringetermin, fertigstellung, kennzeichen, status)
            VALUES (:oe_id, :c_id, :km_stand, :bringetermin, :fertigstellung, :kennzeichen, 'Angenommen')
SQL, [
            ':oe_id' => $oeId,
            ':c_id' => $carId,
            ':km_stand' => $kmStand,
            ':bringetermin' => $bringetermin,
            ':fertigstellung' => $fertigstellung,
            ':kennzeichen' => $kennzeichen
        ]);

        // Arbeitsanweisungen hinzufügen
        $arbeiten = $extractedData['arbeiten'] ?? [];
        foreach ($arbeiten as $arbeit) {
            if (!empty(trim($arbeit))) {
                _addInstructionToOe($db, $oeId, trim($arbeit));
            }
        }

        // Notizen als interne Bemerkung
        $notizen = $extractedData['notizen'] ?? null;
        if ($notizen) {
            $db->execute(
                "UPDATE oe SET intnotes = :notes WHERE id = :oe_id",
                [':notes' => $notizen, ':oe_id' => $oeId]
            );
        }

        $db->commit();

        resultInfo(true, 'CREATED', [
            'oe_id' => $oeId,
            'ordnumber' => $ordnumber,
            'transcript' => $transcript,
            'extracted_data' => $extractedData
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('ORDER_CREATION_ERROR', $e->getMessage());
    }
}

/**
 * OpenAI Whisper API – Audiodatei transkribieren
 */
function _callWhisperApi($apiKey, $wavFile) {
    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($wavFile, 'audio/wav'),
            'model' => 'whisper-1',
            'language' => 'de',
            'response_format' => 'text'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new ApiError('WHISPER_API_ERROR', 'cURL-Fehler: ' . $curlError);
    }
    if ($httpCode !== 200) {
        throw new ApiError('WHISPER_API_ERROR', 'Whisper API Fehler (HTTP ' . $httpCode . '): ' . $response);
    }

    return trim($response);
}

/**
 * Anthropic Claude API – Strukturierte Daten aus Transkript extrahieren
 */
function _callClaudeApi($apiKey, $transcript) {
    $heute = date('Y-m-d');
    $wochentag = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'][date('w')];

    $systemPrompt = <<<PROMPT
Du bist ein Assistent der aus Telefongesprächs-Transkripten zwischen einer KFZ-Werkstatt und ihren Kunden strukturierte Daten extrahiert.

Heute ist {$wochentag}, der {$heute}.

Extrahiere NUR explizit genannte Informationen. Setze Felder auf null wenn sie nicht genannt werden.

Regeln:
- Kennzeichen: Normalisiere auf das Format "XX-XX 0000" (Buchstaben Großbuchstaben, Leerzeichen zwischen Bezirk und Rest)
- Relative Datumsangaben ("nächsten Montag", "übermorgen", "Freitag") interpretiere basierend auf dem heutigen Datum
- Datumsformat: YYYY-MM-DD
- Problembeschreibungen, gewünschte Arbeiten und Reparaturen als einzelne Einträge in "arbeiten" aufnehmen
- km_stand: Nur ganzzahlige Kilometerstände, null wenn nicht genannt

Antworte ausschließlich mit validem JSON in diesem Format:
{
  "kennzeichen": "XX-XX 0000" oder null,
  "bringetermin": "YYYY-MM-DD" oder null,
  "fertigstellung": "YYYY-MM-DD" oder null,
  "km_stand": 12345 oder null,
  "arbeiten": ["Arbeit 1", "Arbeit 2"],
  "notizen": "Sonstige relevante Infos" oder null
}
PROMPT;

    $requestBody = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'user', 'content' => "Transkript des Telefongesprächs:\n\n" . $transcript]
        ],
        'system' => $systemPrompt
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new ApiError('CLAUDE_API_ERROR', 'cURL-Fehler: ' . $curlError);
    }
    if ($httpCode !== 200) {
        throw new ApiError('CLAUDE_API_ERROR', 'Claude API Fehler (HTTP ' . $httpCode . '): ' . $response);
    }

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? '';

    // JSON aus der Antwort extrahieren (auch wenn in Markdown-Codeblock)
    if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
        $extracted = json_decode($matches[0], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $extracted;
        }
    }

    throw new ApiError('CLAUDE_API_ERROR', 'Konnte keine strukturierten Daten aus der Claude-Antwort extrahieren: ' . $text);
}
