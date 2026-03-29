<?php
// backend/api/lxcars/ai_positions.php

/**
 * KI-gestützte Positionsvorschläge für Werkstattaufträge.
 * Basierend auf Arbeitsanweisungen, KBA-Fahrzeugdaten und historischen Faktura-Daten.
 */

/**
 * Generiert KI-Positionsvorschläge für einen bestehenden Auftrag.
 *
 * @param array $data ['oe_id' => int]
 */
function suggestPositions($data) {
    set_time_limit(60);

    $oeId = intval($data['oe_id'] ?? 0);
    if (!$oeId) {
        throw new ApiError('VALIDATION_ERROR', 'oe_id erforderlich');
    }

    $db = DbhCompany::begin();

    // API-Key laden
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', 'ai_gewicht_rechnungen', 'ai_gewicht_auftraege', 'ai_gewicht_angebote')"
    );
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (CRM-Einstellungen → LxCars)');
    }

    $wInvoice   = intval($config['ai_gewicht_rechnungen'] ?? 5);
    $wOrder     = intval($config['ai_gewicht_auftraege'] ?? 3);
    $wQuotation = intval($config['ai_gewicht_angebote'] ?? 1);

    // Auftrag + Kunde
    $order = $db->getOne("SELECT customer_id FROM oe WHERE id = :oe_id", [':oe_id' => $oeId]);
    if (!$order) {
        throw new ApiError('DATA_NOT_FOUND', 'Auftrag nicht gefunden');
    }
    $customerId = intval($order['customer_id']);

    // Arbeitsanweisungen
    $instructions = $db->getAll(
        "SELECT description FROM oe_instructions_lxcars WHERE oe_id = :oe_id ORDER BY sort_order, id",
        [':oe_id' => $oeId]
    );
    if (empty($instructions)) {
        resultInfo(true, 'OK', ['suggested_items' => []]);
        return;
    }

    // Fahrzeug + KBA
    $vehicle = $db->getOne(<<<SQL
        SELECT c.c_ln AS kennzeichen, c.c_m AS marke_kurz, c.c_mt AS modell_typ, c.c_mkb AS mkb,
               k.hersteller, k.marke, k.name AS modell, k.hubraum, k.leistung,
               k.kraftstoff, k.aufbau, k.d3 AS handelsname
        FROM oe_ext e
        LEFT JOIN cars_lxcars c ON c.c_id = e.c_id
        LEFT JOIN kba_lxcars k ON k.id = c.kba_id
        WHERE e.oe_id = :oe_id
SQL, [':oe_id' => $oeId]);

    // Historische Positionen — Rechnungen
    $invoiceItems = $db->getAll(<<<SQL
        SELECT p.id AS parts_id, p.partnumber, i.description, p.sellprice AS catalog_price,
               p.unit, p.part_type, i.qty, i.sellprice AS used_price,
               to_char(ar.transdate, 'YYYY-MM-DD') AS datum, ar.invnumber AS doc_number
        FROM invoice i
        JOIN ar ON ar.id = i.trans_id
        JOIN parts p ON p.id = i.parts_id
        WHERE ar.customer_id = :cid
          AND ar.transdate >= (CURRENT_DATE - INTERVAL '2 years')
          AND i.parts_id IS NOT NULL
        ORDER BY ar.transdate DESC
        LIMIT 50
SQL, [':cid' => $customerId]);

    // Historische Positionen — Aufträge
    $orderItems = $db->getAll(<<<SQL
        SELECT p.id AS parts_id, p.partnumber, oi.description, p.sellprice AS catalog_price,
               p.unit, p.part_type, oi.qty, oi.sellprice AS used_price,
               to_char(oe.transdate, 'YYYY-MM-DD') AS datum, oe.ordnumber AS doc_number
        FROM orderitems oi
        JOIN oe ON oe.id = oi.trans_id
        JOIN parts p ON p.id = oi.parts_id
        WHERE oe.customer_id = :cid
          AND oe.record_type = 'sales_order'
          AND oe.transdate >= (CURRENT_DATE - INTERVAL '2 years')
          AND oi.parts_id IS NOT NULL
          AND oe.id != :current_oe_id
        ORDER BY oe.transdate DESC
        LIMIT 50
SQL, [':cid' => $customerId, ':current_oe_id' => $oeId]);

    // Historische Positionen — Angebote
    $quotationItems = $db->getAll(<<<SQL
        SELECT p.id AS parts_id, p.partnumber, oi.description, p.sellprice AS catalog_price,
               p.unit, p.part_type, oi.qty, oi.sellprice AS used_price,
               to_char(oe.transdate, 'YYYY-MM-DD') AS datum, oe.quonumber AS doc_number
        FROM orderitems oi
        JOIN oe ON oe.id = oi.trans_id
        JOIN parts p ON p.id = oi.parts_id
        WHERE oe.customer_id = :cid
          AND oe.record_type = 'sales_quotation'
          AND oe.transdate >= (CURRENT_DATE - INTERVAL '2 years')
          AND oi.parts_id IS NOT NULL
        ORDER BY oe.transdate DESC
        LIMIT 30
SQL, [':cid' => $customerId]);

    // Claude API aufrufen
    $suggestions = _callClaudeForPositions(
        $anthropicKey, $instructions, $vehicle,
        $invoiceItems, $orderItems, $quotationItems,
        $wInvoice, $wOrder, $wQuotation
    );

    resultInfo(true, 'OK', ['suggested_items' => $suggestions]);
}

/**
 * Fügt bestätigte KI-Positionsvorschläge in einen Auftrag ein.
 *
 * @param array $data ['oe_id' => int, 'items' => array]
 */
function addSuggestedItems($data) {
    $oeId = intval($data['oe_id'] ?? 0);
    $items = $data['items'] ?? [];

    if (!$oeId || empty($items)) {
        throw new ApiError('VALIDATION_ERROR', 'oe_id und items erforderlich');
    }

    $db = DbhCompany::begin();

    // Prüfen ob Auftrag existiert
    $order = $db->getOne("SELECT id FROM oe WHERE id = :oe_id", [':oe_id' => $oeId]);
    if (!$order) {
        throw new ApiError('DATA_NOT_FOUND', 'Auftrag nicht gefunden');
    }

    $db->beginTransaction();
    try {
        $count = 0;
        foreach ($items as $item) {
            $partsId = intval($item['parts_id'] ?? 0);
            if (!$partsId) continue;

            $nextPos = $db->getOne(
                "SELECT COALESCE(MAX(position), 0) + 1 AS p FROM orderitems WHERE trans_id = :tid",
                [':tid' => $oeId]
            );

            $db->execute(<<<SQL
                INSERT INTO orderitems (trans_id, parts_id, position, description, qty, sellprice, unit, discount)
                VALUES (:tid, :pid, :pos, :desc, :qty, :price, :unit, 0)
SQL, [
                ':tid'   => $oeId,
                ':pid'   => $partsId,
                ':pos'   => intval($nextPos['p']),
                ':desc'  => $item['description'] ?? '',
                ':qty'   => floatval($item['qty'] ?? 1),
                ':price' => floatval($item['sellprice'] ?? 0),
                ':unit'  => $item['unit'] ?? ''
            ]);
            $count++;
        }

        $db->commit();
        resultInfo(true, 'OK', ['count' => $count]);
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('INSERT_ERROR', $e->getMessage());
    }
}

/**
 * Formatiert Fahrzeugdaten als lesbaren Text für Claude-Prompts.
 */
function _formatVehicleText($vehicle) {
    if (!$vehicle) return 'Keine Fahrzeugdaten vorhanden';
    $parts = [];
    if (!empty($vehicle['marke'] ?? $vehicle['marke_kurz'])) $parts[] = 'Marke: ' . ($vehicle['marke'] ?: $vehicle['marke_kurz']);
    if (!empty($vehicle['modell'] ?? $vehicle['modell_typ'])) $parts[] = 'Modell: ' . ($vehicle['modell'] ?: $vehicle['modell_typ']);
    if (!empty($vehicle['handelsname'])) $parts[] = 'Handelsname: ' . $vehicle['handelsname'];
    if (!empty($vehicle['hubraum'])) $parts[] = 'Hubraum: ' . $vehicle['hubraum'] . 'ccm';
    if (!empty($vehicle['leistung'])) $parts[] = 'Leistung: ' . $vehicle['leistung'] . 'kW';
    if (!empty($vehicle['kraftstoff'])) $parts[] = 'Kraftstoff: ' . $vehicle['kraftstoff'];
    if (!empty($vehicle['aufbau'])) $parts[] = 'Aufbau: ' . $vehicle['aufbau'];
    if (!empty($vehicle['mkb'])) $parts[] = 'Motorkennbuchstabe: ' . $vehicle['mkb'];
    if (!empty($vehicle['kennzeichen'])) $parts[] = 'Kennzeichen: ' . $vehicle['kennzeichen'];
    if (!empty($vehicle['erstzulassung'])) $parts[] = 'Erstzulassung: ' . $vehicle['erstzulassung'];
    if (!empty($vehicle['fahrzeugalter'])) $parts[] = 'Fahrzeugalter: ' . $vehicle['fahrzeugalter'] . ' Jahre';
    return !empty($parts) ? implode(', ', $parts) : 'Keine Fahrzeugdaten vorhanden';
}

/**
 * KI-gestützte Zeitvorschläge für Arbeitsanweisungen.
 * Ermittelt geplante Zeiten aus historischen actual_minutes, gewichtet nach KBA-Ähnlichkeit und Fahrzeugalter.
 *
 * @param array $data ['oe_id' => int]
 */
function suggestPlannedTimes($data) {
    set_time_limit(30);

    $oeId = intval($data['oe_id'] ?? 0);
    if (!$oeId) {
        throw new ApiError('VALIDATION_ERROR', 'oe_id erforderlich');
    }

    $db = DbhCompany::begin();

    // API-Key
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key = 'anthropic_api_key'"
    );
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (CRM-Einstellungen → LxCars)');
    }

    // Aktuelle Instructions
    $instructions = $db->getAll(
        "SELECT id, description, planned_minutes FROM oe_instructions_lxcars WHERE oe_id = :oe_id ORDER BY sort_order, id",
        [':oe_id' => $oeId]
    );
    if (empty($instructions)) {
        resultInfo(true, 'OK', ['suggestions' => []]);
        return;
    }

    // Fahrzeug + KBA + Alter
    $vehicle = $db->getOne(<<<SQL
        SELECT c.c_ln AS kennzeichen, c.c_m AS marke_kurz, c.c_mt AS modell_typ, c.c_mkb AS mkb,
               c.c_d AS erstzulassung,
               k.hersteller, k.marke, k.name AS modell, k.hubraum, k.leistung,
               k.kraftstoff, k.aufbau, k.d3 AS handelsname, k.hsn, k.tsn
        FROM oe_ext e
        LEFT JOIN cars_lxcars c ON c.c_id = e.c_id
        LEFT JOIN kba_lxcars k ON k.id = c.kba_id
        WHERE e.oe_id = :oe_id
SQL, [':oe_id' => $oeId]);

    // Fahrzeugalter berechnen
    if ($vehicle && !empty($vehicle['erstzulassung'])) {
        $ez = new DateTime($vehicle['erstzulassung']);
        $now = new DateTime();
        $vehicle['fahrzeugalter'] = round($ez->diff($now)->days / 365.25, 1);
    }

    // Historische Zeiten für die gleichen Beschreibungen laden, mit Fahrzeugdaten
    $descriptions = array_map(fn($i) => $i['description'], $instructions);
    $placeholders = [];
    $params = [];
    foreach ($descriptions as $idx => $desc) {
        $key = ':desc_' . $idx;
        $placeholders[] = $key;
        $params[$key] = $desc;
    }
    $inClause = implode(',', $placeholders);

    $historicalData = $db->getAll(<<<SQL
        SELECT i.description, i.actual_minutes, i.planned_minutes,
               to_char(oe.transdate, 'YYYY-MM-DD') AS datum,
               c.c_m AS marke_kurz, c.c_mt AS modell_typ, c.c_d AS erstzulassung,
               k.hersteller, k.marke, k.name AS modell, k.hubraum, k.leistung,
               k.kraftstoff, k.hsn, k.tsn
        FROM oe_instructions_lxcars i
        JOIN oe ON oe.id = i.oe_id
        LEFT JOIN oe_ext e ON e.oe_id = oe.id
        LEFT JOIN cars_lxcars c ON c.c_id = e.c_id
        LEFT JOIN kba_lxcars k ON k.id = c.kba_id
        WHERE i.description IN ({$inClause})
          AND i.actual_minutes > 0
          AND i.oe_id != :current_oe_id
        ORDER BY oe.transdate DESC
        LIMIT 200
SQL, array_merge($params, [':current_oe_id' => $oeId]));

    // An Claude senden
    $suggestions = _callClaudeForTimes($anthropicKey, $instructions, $vehicle, $historicalData);

    resultInfo(true, 'OK', ['suggestions' => $suggestions]);
}

/**
 * Ruft Claude API auf um Zeitvorschläge zu generieren.
 */
function _callClaudeForTimes($apiKey, $instructions, $vehicle, $historicalData) {
    $vehicleText = _formatVehicleText($vehicle);

    // Instructions formatieren
    $instrLines = [];
    foreach ($instructions as $instr) {
        $instrLines[] = json_encode([
            'id' => intval($instr['id']),
            'description' => $instr['description'],
            'current_planned_minutes' => intval($instr['planned_minutes'])
        ], JSON_UNESCAPED_UNICODE);
    }
    $instrText = implode("\n", $instrLines);

    // Historische Daten formatieren
    $histLines = [];
    foreach ($historicalData as $h) {
        $age = '';
        if (!empty($h['erstzulassung'])) {
            $ez = new DateTime($h['erstzulassung']);
            $transdate = new DateTime($h['datum']);
            $age = round($ez->diff($transdate)->days / 365.25, 1) . ' Jahre';
        }
        $histLines[] = json_encode([
            'description' => $h['description'],
            'actual_minutes' => intval($h['actual_minutes']),
            'datum' => $h['datum'],
            'marke' => $h['marke'] ?: $h['marke_kurz'],
            'modell' => $h['modell'] ?: $h['modell_typ'],
            'hubraum' => $h['hubraum'],
            'kraftstoff' => $h['kraftstoff'],
            'hsn' => $h['hsn'],
            'tsn' => $h['tsn'],
            'fahrzeugalter_bei_arbeit' => $age
        ], JSON_UNESCAPED_UNICODE);
    }
    $histText = !empty($histLines) ? implode("\n", $histLines) : 'Keine historischen Daten vorhanden';

    $systemPrompt = <<<PROMPT
Du bist ein KFZ-Werkstatt-Experte. Schätze die geplante Arbeitszeit (in Minuten) für Arbeitsanweisungen.

REGELN:
- Berücksichtige die historischen Ist-Zeiten für gleiche/ähnliche Arbeiten
- Gewichte Einträge mit gleichem Fahrzeugtyp (HSN/TSN, Marke, Modell) höher
- Gewichte Einträge mit ähnlichem Fahrzeugalter höher (ältere Fahrzeuge brauchen oft länger)
- Neuere Einträge (aktuelleres Datum) sind relevanter
- Wenn keine historischen Daten für eine Arbeit vorliegen, schätze auf Basis deines KFZ-Fachwissens
- Gib eine kurze Begründung an
- Runde auf 15-Minuten-Schritte (15, 30, 45, 60, 75, 90, ...)

Antworte ausschließlich mit einem validen JSON-Array:
[{"id": 123, "suggested_minutes": 60, "reason": "Durchschnitt 55 Min. bei vergleichbaren VW Golf Diesel, aufgerundet"}]
PROMPT;

    $userMessage = <<<MSG
AKTUELLES FAHRZEUG:
{$vehicleText}

ARBEITSANWEISUNGEN (für die Zeitvorschläge benötigt werden):
{$instrText}

HISTORISCHE IST-ZEITEN (gleiche Arbeiten an verschiedenen Fahrzeugen):
{$histText}
MSG;

    $requestBody = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 2048,
        'messages' => [
            ['role' => 'user', 'content' => $userMessage]
        ],
        'system' => $systemPrompt
    ], JSON_UNESCAPED_UNICODE);

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

    if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
        $extracted = json_decode($matches[0], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($extracted)) {
            return $extracted;
        }
    }

    throw new ApiError('CLAUDE_API_ERROR', 'Konnte keine Zeitvorschläge aus der Claude-Antwort extrahieren: ' . $text);
}

/**
 * Ruft die Claude API auf um Positionsvorschläge zu generieren.
 */
function _callClaudeForPositions($apiKey, $instructions, $vehicle, $invoiceItems, $orderItems, $quotationItems, $wInvoice, $wOrder, $wQuotation) {
    // Arbeitsanweisungen formatieren
    $instructionLines = array_map(fn($i) => '- ' . $i['description'], $instructions);
    $instructionText = implode("\n", $instructionLines);

    // Fahrzeugdaten formatieren
    $vehicleText = _formatVehicleText($vehicle);

    // Historische Positionen formatieren
    $formatItems = function($items) {
        if (empty($items)) return 'Keine Daten vorhanden';
        $lines = [];
        foreach ($items as $item) {
            $lines[] = json_encode([
                'parts_id' => intval($item['parts_id']),
                'partnumber' => $item['partnumber'],
                'description' => $item['description'],
                'sellprice' => floatval($item['used_price']),
                'qty' => floatval($item['qty']),
                'unit' => $item['unit'],
                'datum' => $item['datum'],
                'beleg' => $item['doc_number']
            ], JSON_UNESCAPED_UNICODE);
        }
        return implode("\n", $lines);
    };

    $systemPrompt = <<<PROMPT
Du bist ein KFZ-Werkstatt-Assistent. Basierend auf Arbeitsanweisungen, Fahrzeugdaten und historischen Positionen, schlage passende Auftragspositionen vor.

GEWICHTUNG der Datenquellen (höher = wichtiger):
- Rechnungen: {$wInvoice}
- Aufträge: {$wOrder}
- Angebote: {$wQuotation}

REGELN:
- Neuere Belege haben innerhalb jeder Kategorie Vorrang
- Verwende bevorzugt Preise aus den neuesten Rechnungen dieses Kunden
- Verwende NUR Artikel aus dem mitgelieferten Katalog (parts_id muss aus den historischen Daten stammen)
- Erfinde KEINE neuen Artikel oder parts_ids
- Passe qty an wenn aus den Arbeitsanweisungen ersichtlich (z.B. "4 Reifen" → qty: 4)
- Berücksichtige die Fahrzeugdaten für passende Artikel (z.B. Motoröl passend zum Kraftstoff)
- Gib eine kurze Begründung in "reason" an, warum dieser Artikel vorgeschlagen wird
- Schlage nur Artikel vor die zu den Arbeitsanweisungen passen

Antworte ausschließlich mit einem validen JSON-Array:
[{"parts_id": 123, "partnumber": "DL001", "description": "Ölwechsel", "qty": 1, "sellprice": 49.90, "unit": "Std", "reason": "Ölwechsel lt. Arbeitsanweisung, Preis aus Rechnung R2025-042"}]
PROMPT;

    $userMessage = <<<MSG
ARBEITSANWEISUNGEN:
{$instructionText}

FAHRZEUG:
{$vehicleText}

HISTORISCHE POSITIONEN (Rechnungen, Gewicht {$wInvoice}):
{$formatItems($invoiceItems)}

HISTORISCHE POSITIONEN (Aufträge, Gewicht {$wOrder}):
{$formatItems($orderItems)}

HISTORISCHE POSITIONEN (Angebote, Gewicht {$wQuotation}):
{$formatItems($quotationItems)}
MSG;

    $requestBody = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 4096,
        'messages' => [
            ['role' => 'user', 'content' => $userMessage]
        ],
        'system' => $systemPrompt
    ], JSON_UNESCAPED_UNICODE);

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

    // JSON-Array aus der Antwort extrahieren
    if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
        $extracted = json_decode($matches[0], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($extracted)) {
            return $extracted;
        }
    }

    throw new ApiError('CLAUDE_API_ERROR', 'Konnte keine Positionsvorschläge aus der Claude-Antwort extrahieren: ' . $text);
}
