<?php
// backend/api/lxcars/sales_text.php

/**
 * Verkaufstext-Generator fuer Fahrzeuge.
 * Erstellt einen KI-basierten Verkaufstext auf Basis von Fahrzeugdaten,
 * Auftragshistorie, Wartungsdaten und aktuellen Maengeln.
 */

/**
 * Laed den gespeicherten Verkaufstext eines Fahrzeugs
 *
 * @param int $data['c_id'] Fahrzeug-ID
 * @testdata {"c_id": 1}
 */
function getSalesText($data) {
    $db = DbhCompany::begin();
    $cId = intval($data['c_id'] ?? 0);
    if (!$cId) {
        throw new ApiError('VALIDATION_ERROR', 'c_id erforderlich');
    }

    $car = $db->getOne("SELECT c_id FROM cars_lxcars WHERE c_id = :c_id", [':c_id' => $cId]);
    if (!$car) {
        throw new ApiError('DATA_NOT_FOUND', 'Fahrzeug nicht gefunden');
    }

    $filePath = fmDataDir() . '/fahrzeuge/' . $cId . '/verkaufstext.txt';

    if (!file_exists($filePath)) {
        resultInfo(true, 'OK', ['text' => '', 'exists' => false]);
        return;
    }

    resultInfo(true, 'OK', ['text' => file_get_contents($filePath) ?: '', 'exists' => true]);
}

/**
 * Speichert den Verkaufstext eines Fahrzeugs als verkaufstext.txt
 *
 * @param int    $data['c_id'] Fahrzeug-ID
 * @param string $data['text'] Verkaufstext
 * @testdata {"c_id": 1, "text": "Gut erhaltenes Fahrzeug..."}
 */
function saveSalesText($data) {
    $db = DbhCompany::begin();
    $cId = intval($data['c_id'] ?? 0);
    $text = $data['text'] ?? '';

    if (!$cId) {
        throw new ApiError('VALIDATION_ERROR', 'c_id erforderlich');
    }

    $car = $db->getOne("SELECT c_id FROM cars_lxcars WHERE c_id = :c_id", [':c_id' => $cId]);
    if (!$car) {
        throw new ApiError('DATA_NOT_FOUND', 'Fahrzeug nicht gefunden');
    }

    ensureVehicleFolders($cId);
    $dir = fmDataDir() . '/fahrzeuge/' . $cId;
    file_put_contents($dir . '/verkaufstext.txt', $text);
    resultInfo(true, 'OK', []);
}

/**
 * Generiert einen KI-basierten Verkaufstext fuer ein Fahrzeug.
 * Nutzt Fahrzeugdaten, alle Reparaturen aus Auftraegen, Wartungsdaten
 * und die Maengel des letzten Auftrags. Speichert das Ergebnis als verkaufstext.txt.
 *
 * @param int    $data['c_id']            Fahrzeug-ID
 * @param string $data['current_defects'] Aktuelle Maengel (Freitext)
 * @testdata {"c_id": 1, "current_defects": "Klimaanlage kühlt nicht"}
 */
function generateSalesText($data) {
    set_time_limit(60);

    $db = DbhCompany::begin();
    $cId = intval($data['c_id'] ?? 0);
    $currentDefects = trim($data['current_defects'] ?? '');

    if (!$cId) {
        throw new ApiError('VALIDATION_ERROR', 'c_id erforderlich');
    }

    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', 'lxcars_sell_system_prompt')"
    );
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (CRM-Einstellungen → LxCars)');
    }
    $metaPrompt = trim($config['lxcars_sell_system_prompt'] ?? '');
    if (empty($metaPrompt)) {
        $metaPrompt = 'Schreibe einen sachlichen, ansprechenden Verkaufstext für ein Gebrauchtfahrzeug. Übertreibe nicht. Erwähne alle Reparaturen als Zeichen guter Pflege. Liste Mängel transparent auf.';
    }

    // Fahrzeug- und KBA-Daten
    $vehicle = $db->getOne(<<<SQL
        SELECT c.c_id, c.c_2 AS hsn, c.c_3 AS tsn,
               c.c_m AS marke_kurz, c.c_mt AS modell_typ,
               c.c_mkb AS motorkennbuchstabe, c.c_em AS emissionsklasse,
               c.c_d AS erstzulassung, c.c_hu AS hu_datum, c.c_fin AS fin,
               c.c_color AS farbe, c.c_gart AS getriebeart,
               c.c_text AS notizen,
               c.c_sk AS steuerkette, c.c_zrk AS zahnriemen_km, c.c_zrd AS zahnriemen_datum,
               c.c_bf AS bremsfl_datum, c.c_wd AS scheibenwischer_datum, c.c_km AS km_stand,
               k.hersteller, k.marke, k.name AS modell, k.hubraum, k.leistung,
               k.kraftstoff, k.aufbau, k.d2, k.d3 AS handelsname,
               k.klasse, k.fhzart
        FROM cars_lxcars c
        LEFT JOIN kba_lxcars k ON k.id = c.kba_id
        WHERE c.c_id = :c_id
    SQL, [':c_id' => $cId]);

    if (!$vehicle) {
        throw new ApiError('DATA_NOT_FOUND', 'Fahrzeug nicht gefunden');
    }

    // Alle Reparaturen aus Auftraegen (chronologisch)
    $repairs = $db->getAll(<<<SQL
        SELECT o.ordnumber, TO_CHAR(o.transdate, 'DD.MM.YYYY') AS datum, e.km_stand,
               (SELECT string_agg(
                    i.description
                    || CASE WHEN i.actual_minutes > 0 THEN ' (' || i.actual_minutes || ' Min.)' ELSE '' END,
                    ', ' ORDER BY i.sort_order, i.id)
                FROM oe_instructions_lxcars i
                WHERE i.oe_id = o.id AND i.done = true) AS reparaturen,
               (SELECT string_agg(oi.description || ' (Menge: ' || oi.qty || ')', ', ' ORDER BY oi.position)
                FROM orderitems oi WHERE oi.trans_id = o.id AND oi.parts_id IS NOT NULL) AS ersatzteile
        FROM oe_ext e
        JOIN oe o ON o.id = e.oe_id
        WHERE e.c_id = :c_id
        ORDER BY o.transdate ASC
    SQL, [':c_id' => $cId]);

    // Letzter Auftrag + seine Maengel
    $lastOrder = $db->getOne(<<<SQL
        SELECT o.id AS oe_id, o.ordnumber, TO_CHAR(o.transdate, 'DD.MM.YYYY') AS datum, e.km_stand
        FROM oe_ext e
        JOIN oe o ON o.id = e.oe_id
        WHERE e.c_id = :c_id
        ORDER BY o.transdate DESC
        LIMIT 1
    SQL, [':c_id' => $cId]);

    $lastOrderDefects = [];
    if ($lastOrder) {
        $lastOrderDefects = $db->getAll(
            "SELECT defect_description, defect_class, note FROM oe_defects WHERE oe_id = :oe_id ORDER BY sort_order, id",
            [':oe_id' => $lastOrder['oe_id']]
        ) ?: [];
    }

    // KM-Stände aus Aufträgen für Wartungshistorie
    $maintenanceKm = $db->getAll(<<<SQL
        SELECT TO_CHAR(o.transdate, 'DD.MM.YYYY') AS datum, e.km_stand
        FROM oe_ext e
        JOIN oe o ON o.id = e.oe_id
        WHERE e.c_id = :c_id AND e.km_stand > 0
        ORDER BY o.transdate DESC
        LIMIT 10
    SQL, [':c_id' => $cId]);

    // Prompt-Blöcke aufbauen
    $vehicleBlock  = _stVehicleBlock($vehicle);
    $repairsBlock  = _stRepairsBlock($repairs);
    $defectsBlock  = _stLastOrderDefectsBlock($lastOrder, $lastOrderDefects);
    $maintBlock    = _stMaintenanceBlock($maintenanceKm, $vehicle);
    $currentBlock  = !empty($currentDefects) ? "AKTUELLE MÄNGEL:\n{$currentDefects}" : '';

    $prompt = <<<PROMPT
{$metaPrompt}

Technische Vorgaben (immer einhalten):
- Kein Markdown, keine Sternchen, keine Rauten — reiner Text mit Zeilenumbrüchen
- Beginne mit einer einprägsamen Headline
- Schreibe den Text direkt für eine Fahrzeugbörse (mobile.de, AutoScout24) geeignet
- Erwähne alle durchgeführten Reparaturen als Beleg für gute Pflege
- Liste die Mängel des letzten Auftrags auf
- Wenn aktuelle Mängel angegeben wurden, führe diese klar auf
- Sprache: Deutsch

{$vehicleBlock}

{$maintBlock}

{$repairsBlock}

{$defectsBlock}

{$currentBlock}

Erstelle jetzt den Verkaufstext:
PROMPT;

    $requestBody = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1500,
        'messages'   => [['role' => 'user', 'content' => $prompt]]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $anthropicKey,
            'anthropic-version: 2023-06-01'
        ]
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new ApiError('CLAUDE_API_ERROR', 'cURL-Fehler: ' . $curlError);
    }
    if ($httpCode !== 200) {
        throw new ApiError('CLAUDE_API_ERROR', 'Claude API Fehler (HTTP ' . $httpCode . '): ' . $response);
    }

    $responseData   = json_decode($response, true);
    $generatedText  = $responseData['content'][0]['text'] ?? '';

    if (empty($generatedText)) {
        throw new ApiError('CLAUDE_API_ERROR', 'Leere Antwort von Claude');
    }

    // Ordner sicherstellen (gleiche Logik wie beim Dateimanager)
    ensureVehicleFolders($cId);
    $dir = fmDataDir() . '/fahrzeuge/' . $cId;
    file_put_contents($dir . '/verkaufstext.txt', $generatedText);

    resultInfo(true, 'OK', ['text' => $generatedText]);
}

// ── Hilfs-Formatierungsfunktionen ────────────────────────────────────────────

function _stVehicleBlock($v) {
    // Niemals das Kennzeichen in den Verkaufstext!
    $fhzartMap = [
        'car'     => 'PKW',
        'truck'   => 'LKW',
        'trailer' => 'Anhänger',
        'tractor' => 'Zugmaschine',
        'bike'    => 'Motorrad',
    ];

    $lines = ['FAHRZEUGDATEN:'];
    $marke  = $v['hersteller'] ?: ($v['marke'] ?: $v['marke_kurz']);
    $modell = $v['modell'] ?: $v['modell_typ'];

    // Fahrzeugart (PKW/LKW/Anhänger etc.)
    if (!empty($v['fhzart']))      $lines[] = 'Fahrzeugart: ' . ($fhzartMap[$v['fhzart']] ?? $v['fhzart']);
    if (!empty($v['klasse']))      $lines[] = "Fahrzeugklasse: {$v['klasse']}";

    if ($marke)                    $lines[] = "Marke: $marke";
    if ($modell)                   $lines[] = "Modell: $modell";
    if (!empty($v['d2']))          $lines[] = "Typ (D2): {$v['d2']}";
    if (!empty($v['handelsname'])) $lines[] = "Handelsname: {$v['handelsname']}";

    // HSN/TSN (KBA-Schlüsselnummern)
    if (!empty($v['hsn']) || !empty($v['tsn'])) {
        $lines[] = 'HSN/TSN: ' . trim(($v['hsn'] ?? '') . '/' . ($v['tsn'] ?? ''), '/');
    }

    if (!empty($v['farbe']))            $lines[] = "Farbe: {$v['farbe']}";
    if (!empty($v['kraftstoff']))       $lines[] = "Kraftstoff: {$v['kraftstoff']}";
    if (!empty($v['getriebeart']))      $lines[] = "Getriebe: {$v['getriebeart']}";
    if (!empty($v['hubraum']))          $lines[] = "Hubraum: {$v['hubraum']} ccm";
    if (!empty($v['leistung'])) {
        $ps = round(floatval($v['leistung']) * 1.35962);
        $lines[] = "Leistung: {$v['leistung']} kW ({$ps} PS)";
    }
    if (!empty($v['aufbau']))           $lines[] = "Aufbau: {$v['aufbau']}";
    if (!empty($v['emissionsklasse']))  $lines[] = "Emissionsklasse: {$v['emissionsklasse']}";
    if (!empty($v['motorkennbuchstabe'])) $lines[] = "Motorkennbuchstabe: {$v['motorkennbuchstabe']}";
    if (!empty($v['km_stand']))         $lines[] = "Aktueller KM-Stand: {$v['km_stand']} km";
    if (!empty($v['fin']))              $lines[] = "FIN: {$v['fin']}";
    if (!empty($v['erstzulassung'])) {
        $ez  = new DateTime($v['erstzulassung']);
        $age = round($ez->diff(new DateTime())->days / 365.25, 1);
        $lines[] = "Erstzulassung: " . $ez->format('d.m.Y') . " (Fahrzeugalter: $age Jahre)";
    }
    if (!empty($v['hu_datum'])) {
        $lines[] = "HU (TÜV) fällig: " . (new DateTime($v['hu_datum']))->format('m/Y');
    }
    if (!empty($v['notizen']))          $lines[] = "Notizen: {$v['notizen']}";
    // Wartungsdaten (Zahnriemen/Steuerkette) werden in _stMaintenanceBlock ausgegeben
    return implode("\n", $lines);
}

function _stRepairsBlock($repairs) {
    if (empty($repairs)) return '';
    $lines = ['DURCHGEFÜHRTE REPARATUREN UND WARTUNGSARBEITEN (chronologisch):'];
    foreach ($repairs as $r) {
        $entry = '• ' . $r['datum'];
        if (!empty($r['km_stand']))    $entry .= " bei {$r['km_stand']} km";
        if (!empty($r['reparaturen'])) $entry .= ': ' . $r['reparaturen'];
        if (!empty($r['ersatzteile'])) $entry .= ' | Ersatzteile: ' . $r['ersatzteile'];
        $lines[] = $entry;
    }
    return implode("\n", $lines);
}

function _stLastOrderDefectsBlock($lastOrder, $defects) {
    if (!$lastOrder) return '';
    $lines = ["MÄNGEL AUS LETZTEM AUFTRAG ({$lastOrder['ordnumber']} vom {$lastOrder['datum']}):"];
    if (empty($defects)) {
        $lines[] = 'Keine Mängel dokumentiert';
    } else {
        foreach ($defects as $d) {
            $entry = '• ' . $d['defect_description'];
            if (!empty($d['defect_class'])) $entry .= " [Klasse: {$d['defect_class']}]";
            if (!empty($d['note']))         $entry .= ' — ' . $d['note'];
            $lines[] = $entry;
        }
    }
    return implode("\n", $lines);
}

function _stMaintenanceBlock($maintenanceKm, $vehicle) {
    $entries = [];

    // Direkt am Fahrzeug gespeicherte Wartungsdaten
    if ($vehicle['steuerkette'])
        $entries[] = 'Steuerkette verbaut (kein Zahnriemen)';
    if (!empty($vehicle['zahnriemen_km']))
        $entries[] = 'Zahnriemen gewechselt bei: ' . $vehicle['zahnriemen_km'] . ' km';
    if (!empty($vehicle['zahnriemen_datum']))
        $entries[] = 'Zahnriemenwechsel am: ' . (new DateTime($vehicle['zahnriemen_datum']))->format('d.m.Y');
    if (!empty($vehicle['bremsfl_datum']))
        $entries[] = 'Bremsflüssigkeit gewechselt: ' . (new DateTime($vehicle['bremsfl_datum']))->format('m/Y');
    if (!empty($vehicle['scheibenwischer_datum']))
        $entries[] = 'Scheibenwischer gewechselt: ' . (new DateTime($vehicle['scheibenwischer_datum']))->format('m/Y');

    // KM-Stände aus Auftraegen
    $kmEntries = [];
    foreach (($maintenanceKm ?: []) as $m) {
        $kmEntries[] = "  • {$m['datum']}: {$m['km_stand']} km";
    }

    if (empty($entries) && empty($kmEntries)) return '';

    $lines = ['WARTUNGS- UND SERVICEDATEN:'];
    foreach ($entries as $e) $lines[] = "• $e";
    if (!empty($kmEntries)) {
        $lines[] = 'Werkstattbesuche (KM-Stände):';
        foreach ($kmEntries as $e) $lines[] = $e;
    }
    return implode("\n", $lines);
}
