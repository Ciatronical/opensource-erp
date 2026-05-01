<?php
// backend/api/lxcars/car_chat.php

/**
 * KI-Chat fuer Fahrzeuge — Werkstattmeister-Assistent.
 * Beantwortet Fragen zum Fahrzeug unter Einbeziehung von Fahrzeugdaten,
 * Auftragshistorie und ausgefuehrten Arbeiten.
 */

/**
 * Laedt den Chat-Verlauf eines Fahrzeugs
 *
 * @param int $data['c_id'] Fahrzeug-ID
 * @testdata {"c_id": 1}
 */
function getCarChat($data) {
    $db = DbhCompany::begin();
    $cId = intval($data['c_id'] ?? 0);
    if (!$cId) {
        throw new ApiError('VALIDATION_ERROR', 'c_id erforderlich');
    }

    $messages = $db->getAll(
        "SELECT id, role, content, TO_CHAR(created_at, 'DD.MM.YYYY HH24:MI') AS created_at
         FROM car_chat_lxcars
         WHERE c_id = :c_id
         ORDER BY id ASC",
        [':c_id' => $cId]
    );

    resultInfo(true, 'OK', $messages ?: []);
}

/**
 * Sendet eine Chat-Nachricht und liefert die KI-Antwort
 *
 * @param int    $data['c_id']      Fahrzeug-ID
 * @param string $data['message']   Benutzernachricht
 * @param array  $data['document']  Optional: {data: base64, media_type: string, name: string}
 * @testdata {"c_id": 1, "message": "Haben wir schon mal die Bremsen gewechselt?"}
 */
function sendCarChatMessage($data) {
    set_time_limit(60);

    $db = DbhCompany::begin();
    $cId = intval($data['c_id'] ?? 0);
    $message = trim($data['message'] ?? '');

    if (!$cId || empty($message)) {
        throw new ApiError('VALIDATION_ERROR', 'c_id und message erforderlich');
    }

    // API-Key laden
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', 'lxcars_chat_system_prompt')"
    );
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (CRM-Einstellungen → LxCars)');
    }

    $systemPromptConfig = trim($config['lxcars_chat_system_prompt'] ?? '');
    if (empty($systemPromptConfig)) {
        $systemPromptConfig = 'Du bist ein erfahrener KFZ-Werkstattmeister und technischer Berater. Du hilfst Mechanikern bei Diagnosen, Reparaturentscheidungen und technischen Fragen. Antworte praxisnah, konkret und auf Deutsch.';
    }

    // Fahrzeugdaten + KBA laden
    $vehicle = $db->getOne(<<<SQL
        SELECT c.c_id, c.c_ln AS kennzeichen, c.c_m AS marke_kurz, c.c_mt AS modell_typ,
               c.c_mkb AS motorkennbuchstabe, c.c_em AS emissionsklasse,
               c.c_d AS erstzulassung, c.c_hu AS hu_datum, c.c_fin AS fin,
               c.c_color AS farbe, c.c_gart AS getriebeart,
               c.c_text AS notizen,
               c.c_sk AS steuerkette, c.c_zrk AS zahnriemen_km, c.c_zrd AS zahnriemen_datum,
               k.hersteller, k.marke, k.name AS modell, k.hubraum, k.leistung,
               k.kraftstoff, k.aufbau, k.d3 AS handelsname
        FROM cars_lxcars c
        LEFT JOIN kba_lxcars k ON k.id = c.kba_id
        WHERE c.c_id = :c_id
SQL, [':c_id' => $cId]);

    if (!$vehicle) {
        throw new ApiError('DATA_NOT_FOUND', 'Fahrzeug nicht gefunden');
    }

    // Auftragshistorie mit Positionen und Arbeitsanweisungen laden
    $orderHistory = $db->getAll(<<<SQL
        SELECT o.id AS oe_id, o.ordnumber, TO_CHAR(o.transdate, 'DD.MM.YYYY') AS datum,
               o.amount, e.km_stand,
               (SELECT string_agg(oi.description || ' (Menge: ' || oi.qty || ', Preis: ' || oi.sellprice || '€)', E'\n' ORDER BY oi.position)
                FROM orderitems oi WHERE oi.trans_id = o.id) AS positionen,
               (SELECT string_agg(
                    i.description || CASE WHEN i.done THEN ' [erledigt]' ELSE '' END
                    || CASE WHEN i.actual_minutes > 0 THEN ' (' || i.actual_minutes || ' Min.)' ELSE '' END,
                    E'\n' ORDER BY i.sort_order, i.id)
                FROM oe_instructions_lxcars i WHERE i.oe_id = o.id) AS anweisungen
        FROM oe_ext e
        JOIN oe o ON o.id = e.oe_id
        WHERE e.c_id = :c_id
        ORDER BY o.transdate DESC
        LIMIT 30
SQL, [':c_id' => $cId]);

    // Rechnungshistorie
    $invoiceHistory = $db->getAll(<<<SQL
        SELECT ar.invnumber, TO_CHAR(ar.transdate, 'DD.MM.YYYY') AS datum,
               ar.amount, ae.km_stand,
               (SELECT string_agg(i.description || ' (Menge: ' || i.qty || ', Preis: ' || i.sellprice || '€)', E'\n' ORDER BY i.id)
                FROM invoice i WHERE i.trans_id = ar.id AND i.parts_id IS NOT NULL) AS positionen
        FROM ar_ext ae
        JOIN ar ON ar.id = ae.ar_id
        WHERE ae.c_id = :c_id
        ORDER BY ar.transdate DESC
        LIMIT 30
SQL, [':c_id' => $cId]);

    // Bisherigen Chat-Verlauf laden (letzte 20 Nachrichten fuer Kontext)
    $chatHistory = $db->getAll(
        "SELECT role, content FROM car_chat_lxcars WHERE c_id = :c_id ORDER BY id DESC LIMIT 20",
        [':c_id' => $cId]
    );
    $chatHistory = array_reverse($chatHistory ?: []);

    // Kontext aufbauen
    $vehicleText = _formatCarChatVehicle($vehicle);
    $ordersText = _formatCarChatOrders($orderHistory);
    $invoicesText = _formatCarChatInvoices($invoiceHistory);

    $systemPrompt = <<<PROMPT
{$systemPromptConfig}

FAHRZEUGDATEN:
{$vehicleText}

AUFTRAGSHISTORIE (Auftraege mit Positionen und Arbeitsanweisungen):
{$ordersText}

RECHNUNGSHISTORIE (abgerechnete Arbeiten):
{$invoicesText}

REGELN:
- Beziehe dich immer auf die konkreten Fahrzeugdaten und die Auftrags-/Rechnungshistorie
- Wenn nach bereits durchgefuehrten Arbeiten gefragt wird, pruefe die Auftrags- und Rechnungshistorie
- Bei Diagnosefragen: beruecksichtige was bereits gewechselt/repariert wurde und schlage die naechsten logischen Schritte vor
- Bei Service-Fragen: berechne anhand der Erstzulassung und km-Staende wann der naechste Service faellig ist
- Antworte praxisnah und konkret — du sprichst mit einem Mechaniker, nicht mit einem Kunden
- Halte die Antworten kompakt und auf den Punkt
- Verwende keine Markdown-Ueberschriften (kein # oder ##), nutze stattdessen fettgedruckten Text mit **
PROMPT;

    // Claude API aufrufen
    $messages = [];
    foreach ($chatHistory as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }

    // Optionales Dokument (PDF oder Text) als Content-Block einbinden
    $doc = $data['document'] ?? null;
    if (!empty($doc['data']) && !empty($doc['media_type'])) {
        $allowedTypes = ['application/pdf', 'text/plain'];
        $mediaType = in_array($doc['media_type'], $allowedTypes, true) ? $doc['media_type'] : 'text/plain';
        $docName = preg_replace('/[^a-zA-Z0-9._\- ]/', '', $doc['name'] ?? 'Dokument');

        $userContent = [
            [
                'type'   => 'document',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $mediaType,
                    'data'       => $doc['data'],
                ],
                'title'  => $docName,
            ],
            ['type' => 'text', 'text' => $message],
        ];
    } else {
        $userContent = $message;
    }

    $messages[] = ['role' => 'user', 'content' => $userContent];

    $requestBody = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 2048,
        'messages' => $messages,
        'system' => $systemPrompt
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $anthropicKey,
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

    $responseData = json_decode($response, true);
    $assistantMessage = $responseData['content'][0]['text'] ?? '';

    if (empty($assistantMessage)) {
        throw new ApiError('CLAUDE_API_ERROR', 'Leere Antwort von Claude');
    }

    // Beide Nachrichten in DB speichern
    $db->execute(
        "INSERT INTO car_chat_lxcars (c_id, role, content) VALUES (:c_id, 'user', :content)",
        [':c_id' => $cId, ':content' => $message]
    );
    $db->execute(
        "INSERT INTO car_chat_lxcars (c_id, role, content) VALUES (:c_id, 'assistant', :content)",
        [':c_id' => $cId, ':content' => $assistantMessage]
    );

    // Gespeicherte Nachrichten zurueckgeben (mit Zeitstempel)
    $saved = $db->getAll(
        "SELECT id, role, content, TO_CHAR(created_at, 'DD.MM.YYYY HH24:MI') AS created_at
         FROM car_chat_lxcars
         WHERE c_id = :c_id
         ORDER BY id DESC LIMIT 2",
        [':c_id' => $cId]
    );

    resultInfo(true, 'OK', array_reverse($saved ?: []));
}

/**
 * Loescht den gesamten Chat-Verlauf eines Fahrzeugs
 *
 * @param int $data['c_id'] Fahrzeug-ID
 * @testdata {"c_id": 1}
 */
function clearCarChat($data) {
    $db = DbhCompany::begin();
    $cId = intval($data['c_id'] ?? 0);
    if (!$cId) {
        throw new ApiError('VALIDATION_ERROR', 'c_id erforderlich');
    }

    $db->execute(
        "DELETE FROM car_chat_lxcars WHERE c_id = :c_id",
        [':c_id' => $cId]
    );

    resultInfo(true, 'OK', []);
}

/**
 * Formatiert Fahrzeugdaten fuer den Chat-Kontext
 */
function _formatCarChatVehicle($v) {
    $lines = [];
    if (!empty($v['marke'] ?? $v['marke_kurz'])) $lines[] = 'Marke: ' . ($v['marke'] ?: $v['marke_kurz']);
    if (!empty($v['modell'] ?? $v['modell_typ'])) $lines[] = 'Modell: ' . ($v['modell'] ?: $v['modell_typ']);
    if (!empty($v['handelsname'])) $lines[] = 'Handelsname: ' . $v['handelsname'];
    if (!empty($v['kennzeichen'])) $lines[] = 'Kennzeichen: ' . $v['kennzeichen'];
    if (!empty($v['hubraum'])) $lines[] = 'Hubraum: ' . $v['hubraum'] . 'ccm';
    if (!empty($v['leistung'])) $lines[] = 'Leistung: ' . $v['leistung'] . 'kW';
    if (!empty($v['kraftstoff'])) $lines[] = 'Kraftstoff: ' . $v['kraftstoff'];
    if (!empty($v['aufbau'])) $lines[] = 'Aufbau: ' . $v['aufbau'];
    if (!empty($v['motorkennbuchstabe'])) $lines[] = 'Motorkennbuchstabe: ' . $v['motorkennbuchstabe'];
    if (!empty($v['getriebeart'])) $lines[] = 'Getriebeart: ' . $v['getriebeart'];
    if (!empty($v['farbe'])) $lines[] = 'Farbe: ' . $v['farbe'];
    if (!empty($v['emissionsklasse'])) $lines[] = 'Emissionsklasse: ' . $v['emissionsklasse'];
    if (!empty($v['fin'])) $lines[] = 'FIN: ' . $v['fin'];
    if (!empty($v['erstzulassung'])) {
        $ez = new DateTime($v['erstzulassung']);
        $lines[] = 'Erstzulassung: ' . $ez->format('d.m.Y');
        $age = round($ez->diff(new DateTime())->days / 365.25, 1);
        $lines[] = 'Fahrzeugalter: ' . $age . ' Jahre';
    }
    if (!empty($v['hu_datum'])) {
        $hu = new DateTime($v['hu_datum']);
        $lines[] = 'HU (TUeV): ' . $hu->format('m/Y');
    }
    if ($v['steuerkette']) $lines[] = 'Steuerkette: Ja';
    if (!empty($v['zahnriemen_km'])) $lines[] = 'Zahnriemenwechsel bei: ' . $v['zahnriemen_km'] . ' km';
    if (!empty($v['zahnriemen_datum'])) $lines[] = 'Zahnriemenwechsel am: ' . (new DateTime($v['zahnriemen_datum']))->format('d.m.Y');
    if (!empty($v['notizen'])) $lines[] = 'Notizen: ' . $v['notizen'];

    return !empty($lines) ? implode("\n", $lines) : 'Keine Fahrzeugdaten vorhanden';
}

/**
 * Formatiert Auftragshistorie fuer den Chat-Kontext
 */
function _formatCarChatOrders($orders) {
    if (empty($orders)) return 'Keine Auftraege vorhanden';

    $lines = [];
    foreach ($orders as $o) {
        $entry = "--- Auftrag {$o['ordnumber']} vom {$o['datum']}";
        if (!empty($o['km_stand'])) $entry .= " (km: {$o['km_stand']})";
        if (!empty($o['amount'])) $entry .= " | Betrag: {$o['amount']}€";
        $lines[] = $entry;
        if (!empty($o['anweisungen'])) {
            $lines[] = "Arbeitsanweisungen:\n{$o['anweisungen']}";
        }
        if (!empty($o['positionen'])) {
            $lines[] = "Positionen:\n{$o['positionen']}";
        }
    }
    return implode("\n", $lines);
}

/**
 * Formatiert Rechnungshistorie fuer den Chat-Kontext
 */
function _formatCarChatInvoices($invoices) {
    if (empty($invoices)) return 'Keine Rechnungen vorhanden';

    $lines = [];
    foreach ($invoices as $inv) {
        $entry = "--- Rechnung {$inv['invnumber']} vom {$inv['datum']}";
        if (!empty($inv['km_stand'])) $entry .= " (km: {$inv['km_stand']})";
        if (!empty($inv['amount'])) $entry .= " | Betrag: {$inv['amount']}€";
        $lines[] = $entry;
        if (!empty($inv['positionen'])) {
            $lines[] = "Positionen:\n{$inv['positionen']}";
        }
    }
    return implode("\n", $lines);
}
