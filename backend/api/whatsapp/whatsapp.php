<?php
// backend/api/whatsapp/whatsapp.php

/**
 * WhatsApp-Config laden (aus defaults_oserp)
 */
function _getWhatsAppConfig(): array {
    $db = DbhCompany::begin();
    $rows = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'whatsapp_%'");
    $config = [];
    foreach ($rows as $row) {
        $config[$row['key']] = $row['value'];
    }
    return $config;
}

/**
 * Telefonnummer normalisieren (E.164 Format)
 * Entfernt alles außer Ziffern und +, fügt +49 hinzu wenn nötig
 */
function _normalizePhone(string $phone, string $countryCode = '49'): string {
    $cleaned = preg_replace('/[^+\d]/', '', $phone);
    if (empty($cleaned)) return '';

    // Wenn mit 0 beginnt: Landesvorwahl ersetzen
    if ($cleaned[0] === '0') {
        $cleaned = '+' . $countryCode . substr($cleaned, 1);
    }
    // Wenn kein + vorhanden: Landesvorwahl voranstellen
    if ($cleaned[0] !== '+') {
        $cleaned = '+' . $countryCode . $cleaned;
    }
    return $cleaned;
}

/**
 * System-Platzhalter in Texten ersetzen
 * Ersetzt {{mitarbeiter_name}} und {{kunde_name}} durch die tatsaechlichen Werte
 *
 * @param string $text Text mit Platzhaltern
 * @param int $customerId Kunden-ID (optional, fuer {{kunde_name}})
 * @return string Text mit ersetzten Platzhaltern
 */
function _replaceSystemPlaceholders(string $text, int $customerId = 0): string {
    $db = DbhCompany::begin();

    // Mitarbeitername
    if (str_contains($text, '{{mitarbeiter_name}}')) {
        $auth = DbhAuth::begin();
        $auth->fetchSessionData();
        $emp = $db->getOne(
            "SELECT name FROM employee WHERE login = :login",
            [':login' => $auth->getLogin()]
        );
        $text = str_replace('{{mitarbeiter_name}}', $emp['name'] ?? '', $text);
    }

    // Kundenname
    if (str_contains($text, '{{kunde_name}}') && $customerId > 0) {
        $customer = $db->getOne(
            "SELECT name FROM customer WHERE id = :id",
            [':id' => $customerId]
        );
        $text = str_replace('{{kunde_name}}', $customer['name'] ?? '', $text);
    }

    return $text;
}

/**
 * Benannte und nummerierte Platzhalter sequentiell durchnummerieren fuer Meta API
 *
 * Konvertiert {{kunde_name}}, {{mitarbeiter_name}}, {{2}} etc. zu {{1}}, {{2}}, {{3}}
 * in der Reihenfolge ihres Auftretens im Text.
 */
function _convertToNumberedPlaceholders(string $text): string {
    if (empty($text)) return $text;

    // Alle Platzhalter {{...}} finden (benannt und nummeriert)
    if (!preg_match_all('/\{\{([^}]+)\}\}/', $text, $matches)) {
        return $text;
    }

    $counter = 1;
    $mapped = [];
    foreach ($matches[1] as $placeholder) {
        $placeholder = trim($placeholder);
        if (!isset($mapped[$placeholder])) {
            $mapped[$placeholder] = $counter++;
        }
    }

    foreach ($mapped as $name => $num) {
        $text = str_replace('{{'.$name.'}}', '{{'.$num.'}}', $text);
    }

    return $text;
}

/**
 * Kunde anhand Telefonnummer suchen
 */
function _findCustomerByPhone(string $phone): ?array {
    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();
    $countryCode = $config['whatsapp_country_code'] ?? '49';
    $normalized = _normalizePhone($phone, $countryCode);

    if (empty($normalized)) return null;

    // Nur die Ziffern ohne + für den Vergleich
    $digits = ltrim($normalized, '+');

    // Suche in customer.phone und customer_ext.phone_numbers (JSONB)
    $row = $db->getOne(
        "SELECT c.id, c.name FROM customer c
         LEFT JOIN customer_ext ce ON ce.customer_id = c.id
         WHERE REGEXP_REPLACE(c.phone, '[^0-9]', '', 'g') LIKE '%' || :digits
            OR REGEXP_REPLACE(c.phone3, '[^0-9]', '', 'g') LIKE '%' || :digits
            OR EXISTS (
                SELECT 1 FROM jsonb_array_elements(COALESCE(ce.phone_numbers, '[]'::jsonb)) pn
                WHERE REGEXP_REPLACE(pn->>'number', '[^0-9]', '', 'g') LIKE '%' || :digits
            )
         LIMIT 1",
        [':digits' => substr($digits, -8)]  // Letzte 8 Ziffern für flexiblen Match
    );

    if ($row) {
        return ['id' => (int)$row['id'], 'name' => $row['name'], 'type' => 'C'];
    }

    // Auch in vendor suchen
    $row = $db->getOne(
        "SELECT v.id, v.name FROM vendor v
         WHERE REGEXP_REPLACE(v.phone, '[^0-9]', '', 'g') LIKE '%' || :digits
         LIMIT 1",
        [':digits' => substr($digits, -8)]
    );

    if ($row) {
        return ['id' => (int)$row['id'], 'name' => $row['name'], 'type' => 'V'];
    }

    return null;
}

/**
 * WhatsApp-Nachrichten für einen Kunden laden
 *
 * @param array $data customer_id, phone_numbers[], page, limit
 */
function getWhatsAppMessages($data) {
    $db = DbhCompany::begin();

    $customerId = (int)($data['customer_id'] ?? 0);
    $phoneNumbers = $data['phone_numbers'] ?? [];
    $page = max(1, (int)($data['page'] ?? 1));
    $limit = min(100, max(1, (int)($data['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    // Config für Normalisierung
    $config = _getWhatsAppConfig();
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    // Telefonnummern normalisieren für Suche
    $normalizedPhones = [];
    foreach ($phoneNumbers as $phone) {
        $n = _normalizePhone(trim($phone), $countryCode);
        if (!empty($n)) $normalizedPhones[] = $n;
    }

    if ($customerId <= 0 && empty($normalizedPhones)) {
        resultInfo(true, '', ['messages' => [], 'total' => 0]);
        return;
    }

    // WHERE-Bedingungen aufbauen
    $conditions = [];
    $params = [];

    if ($customerId > 0) {
        $conditions[] = 'customer_id = :customer_id';
        $params[':customer_id'] = $customerId;
    }

    if (!empty($normalizedPhones)) {
        $phonePlaceholders = [];
        foreach ($normalizedPhones as $i => $phone) {
            $key = ':phone_' . $i;
            $phonePlaceholders[] = $key;
            $params[$key] = $phone;
        }
        $conditions[] = 'phone_number IN (' . implode(',', $phonePlaceholders) . ')';
    }

    $where = '(' . implode(' OR ', $conditions) . ') AND hidden = FALSE';

    // Total count
    $countRow = $db->getOne("SELECT COUNT(*) AS cnt FROM whatsapp_messages WHERE $where", $params);
    $total = (int)($countRow['cnt'] ?? 0);

    // Neueste Nachrichten holen, dann chronologisch sortieren
    $messages = $db->getAll(
        "SELECT * FROM (
            SELECT id, wa_message_id, direction, phone_number, customer_id, contact_name,
                    message_type, message_text, media_url, media_mime_type, media_caption,
                    status, status_timestamp, error_code, error_message, itime, mtime
             FROM whatsapp_messages
             WHERE $where
             ORDER BY itime DESC
             LIMIT :limit OFFSET :offset
         ) sub ORDER BY itime ASC",
        array_merge($params, [':limit' => $limit, ':offset' => $offset])
    );

    // 24h-Fenster pruefen: letzte eingehende Nachricht innerhalb 24 Stunden?
    $lastInbound = $db->getOne(
        "SELECT itime FROM whatsapp_messages
         WHERE $where AND direction = 'I'
         ORDER BY itime DESC LIMIT 1",
        $params
    );
    $windowOpen = false;
    if ($lastInbound && !empty($lastInbound['itime'])) {
        $windowOpen = (strtotime($lastInbound['itime']) >= strtotime('-24 hours'));
    }

    resultInfo(true, '', [
        'messages' => $messages,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'window_open' => $windowOpen
    ]);
}

/**
 * Neue WhatsApp-Nachrichten seit Datum (für InfoBar)
 *
 * @param array $data since_date, limit
 */
function getNewWhatsAppMessages($data) {
    $db = DbhCompany::begin();

    $sinceDate = $data['since_date'] ?? date('Y-m-d');
    $limit = min(50, max(1, (int)($data['limit'] ?? 20)));

    $messages = $db->getAll(
        "SELECT wm.id, wm.wa_message_id, wm.direction, wm.phone_number, wm.customer_id, wm.contact_name,
                wm.message_type, wm.message_text, wm.status, wm.itime,
                CASE WHEN c.id IS NOT NULL THEN 'C' WHEN v.id IS NOT NULL THEN 'V' ELSE NULL END AS src
         FROM whatsapp_messages wm
         LEFT JOIN customer c ON c.id = wm.customer_id
         LEFT JOIN vendor v ON v.id = wm.customer_id AND c.id IS NULL
         WHERE wm.direction = 'I' AND wm.itime >= :since_date::date AND wm.hidden = FALSE
         ORDER BY wm.itime DESC
         LIMIT :limit",
        [':since_date' => $sinceDate, ':limit' => $limit]
    );

    resultInfo(true, '', [
        'messages' => $messages,
        'total' => count($messages)
    ]);
}

/**
 * Alle WhatsApp-Konversationen laden (gruppiert nach Telefonnummer)
 *
 * @param array $data search, limit
 */
function getWhatsAppConversations($data) {
    $db = DbhCompany::begin();
    $search = trim($data['search'] ?? '');
    $limit = min(200, max(1, (int)($data['limit'] ?? 100)));

    $searchCondition = '';
    $params = [':limit' => $limit];

    if (!empty($search)) {
        $searchCondition = "AND (wm.contact_name ILIKE :search OR wm.phone_number ILIKE :search OR wm.message_text ILIKE :search OR c.name ILIKE :search OR v.name ILIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $conversations = $db->getAll(
        "SELECT
            wm.phone_number,
            MAX(wm.contact_name) AS contact_name,
            MAX(wm.customer_id) AS customer_id,
            COALESCE(MAX(c.name), MAX(v.name)) AS customer_name,
            CASE WHEN MAX(c.id) IS NOT NULL THEN 'C' WHEN MAX(v.id) IS NOT NULL THEN 'V' ELSE NULL END AS src,
            MAX(wm.itime) AS last_message_time,
            (SELECT message_text FROM whatsapp_messages wm2 WHERE wm2.phone_number = wm.phone_number AND wm2.hidden = FALSE ORDER BY wm2.itime DESC LIMIT 1) AS last_message,
            (SELECT direction FROM whatsapp_messages wm2 WHERE wm2.phone_number = wm.phone_number AND wm2.hidden = FALSE ORDER BY wm2.itime DESC LIMIT 1) AS last_direction,
            COUNT(*) AS message_count,
            COUNT(*) FILTER (WHERE wm.direction = 'I' AND wm.status = 'received') AS unread_count,
            MAX(wm.itime) FILTER (WHERE wm.direction = 'I') AS last_inbound_time
         FROM whatsapp_messages wm
         LEFT JOIN customer c ON c.id = wm.customer_id
         LEFT JOIN vendor v ON v.id = wm.customer_id AND c.id IS NULL
         WHERE wm.hidden = FALSE $searchCondition
         GROUP BY wm.phone_number
         ORDER BY MAX(wm.itime) DESC
         LIMIT :limit",
        $params
    );

    // 24h-Fenster pro Konversation berechnen
    foreach ($conversations as &$conv) {
        $conv['window_open'] = false;
        if (!empty($conv['last_inbound_time'])) {
            $conv['window_open'] = (strtotime($conv['last_inbound_time']) >= strtotime('-24 hours'));
        }
    }
    unset($conv);

    resultInfo(true, '', ['conversations' => $conversations]);
}

/**
 * Alle Nachrichten für eine Telefonnummer laden (Chatverlauf)
 *
 * @param array $data phone_number, limit
 */
function getWhatsAppChat($data) {
    $db = DbhCompany::begin();
    $phoneNumber = trim($data['phone_number'] ?? '');
    $limit = min(200, max(1, (int)($data['limit'] ?? 100)));

    if (empty($phoneNumber)) {
        resultInfo(true, '', ['messages' => []]);
        return;
    }

    $messages = $db->getAll(
        "SELECT * FROM (
            SELECT id, wa_message_id, direction, phone_number, customer_id, contact_name,
                    message_type, message_text, media_url, media_mime_type, media_caption,
                    status, status_timestamp, error_code, error_message, itime, mtime
             FROM whatsapp_messages
             WHERE phone_number = :phone AND hidden = FALSE
             ORDER BY itime DESC
             LIMIT :limit
         ) sub ORDER BY itime ASC",
        [':phone' => $phoneNumber, ':limit' => $limit]
    );

    // 24h-Fenster pruefen: letzte eingehende Nachricht innerhalb 24 Stunden?
    $lastInbound = $db->getOne(
        "SELECT itime FROM whatsapp_messages
         WHERE phone_number = :phone AND direction = 'I'
         ORDER BY itime DESC LIMIT 1",
        [':phone' => $phoneNumber]
    );
    $windowOpen = false;
    if ($lastInbound && !empty($lastInbound['itime'])) {
        $windowOpen = (strtotime($lastInbound['itime']) >= strtotime('-24 hours'));
    }

    resultInfo(true, '', ['messages' => $messages, 'window_open' => $windowOpen]);
}

/**
 * Eingehende Nachrichten einer Telefonnummer als gelesen markieren
 *
 * @param string $data['phone_number'] Telefonnummer
 * @testdata {"phone_number": "+491234567890"}
 */
function markWhatsAppRead($data) {
    $db = DbhCompany::begin();
    $phoneNumber = trim($data['phone_number'] ?? '');

    if (empty($phoneNumber)) {
        resultInfo(false, 'INVALID_INPUT', 'Telefonnummer ist erforderlich');
        return;
    }

    $db->execute(
        "UPDATE whatsapp_messages
         SET status = 'read', mtime = NOW()
         WHERE phone_number = :phone AND direction = 'I' AND status = 'received'",
        [':phone' => $phoneNumber]
    );

    resultInfo(true, '');
}

/**
 * WhatsApp-Nachricht ausblenden (Soft-Delete)
 *
 * Setzt hidden=true, damit die Nachricht nicht mehr angezeigt wird.
 * Wiederherstellung nur per Datenbank: UPDATE whatsapp_messages SET hidden=false WHERE id=...
 *
 * @param int $data['message_id'] Nachrichten-ID
 * @testdata {"message_id": 1}
 */
function deleteWhatsAppMessage($data) {
    $db = DbhCompany::begin();
    $messageId = (int)($data['message_id'] ?? 0);

    if ($messageId <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Nachrichten-ID ist erforderlich');
        return;
    }

    $db->execute(
        "UPDATE whatsapp_messages SET hidden = TRUE, mtime = NOW() WHERE id = :id",
        [':id' => $messageId]
    );

    resultInfo(true, '');
}

/**
 * Kunde/Lieferant mit Telefonnummern suchen (für Compose-Dialog)
 *
 * @param array $data query
 */
function searchCvPhones($data) {
    $db = DbhCompany::begin();
    $query = trim($data['query'] ?? '');

    if (strlen($query) < 2) {
        resultInfo(true, '', []);
        return;
    }

    $search = '%' . $query . '%';

    $results = $db->getAll(
        "SELECT c.id, c.name, c.customernumber AS number, c.phone, c.phone3, 'customer' AS type,
                COALESCE((SELECT string_agg(pn->>'number', ', ')
                 FROM customer_ext ce, jsonb_array_elements(COALESCE(ce.phone_numbers, '[]'::jsonb)) pn
                 WHERE ce.customer_id = c.id), '') AS extra_phones
         FROM customer c
         WHERE (c.name ILIKE :search OR c.customernumber ILIKE :search OR c.phone ILIKE :search OR c.phone3 ILIKE :search)
           AND COALESCE(c.obsolete, FALSE) = FALSE
         ORDER BY c.name
         LIMIT 20",
        [':search' => $search]
    );

    $items = [];
    foreach ($results as $row) {
        $phones = [];
        if (!empty($row['phone'])) $phones[] = $row['phone'];
        if (!empty($row['phone3'])) $phones[] = $row['phone3'];
        if (!empty($row['extra_phones'])) {
            foreach (explode(', ', $row['extra_phones']) as $p) {
                if (!empty(trim($p))) $phones[] = trim($p);
            }
        }
        if (empty($phones)) continue;

        $items[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'number' => $row['number'],
            'phones' => array_unique($phones),
            'type' => $row['type'],
            'route' => '/kunde/' . $row['id']
        ];
    }

    resultInfo(true, '', $items);
}

/**
 * WhatsApp-Nachricht senden (via Meta Cloud API)
 *
 * @param array $data to, message, customer_id
 */
function sendWhatsAppMessage($data) {
    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    if (empty($accessToken) || empty($phoneNumberId)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    $to = _normalizePhone($data['to'] ?? '', $countryCode);
    $message = trim($data['message'] ?? '');
    $customerId = (int)($data['customer_id'] ?? 0);

    // writeLog("=== sendWhatsAppMessage ===");
    // writeLog("to: {$to}, customerId: {$customerId}, messageLen: " . strlen($message));

    if (empty($to) || empty($message)) {
        // writeLog("ABORT: Leere Telefonnummer oder Nachricht");
        resultInfo(false, 'INVALID_INPUT', 'Telefonnummer und Nachrichtentext sind erforderlich');
        return;
    }

    // System-Platzhalter ersetzen
    $message = _replaceSystemPlaceholders($message, $customerId);

    // 24h-Fenster: NUR eingehende Kundennachricht oeffnet ein Service-Fenster
    // Ohne Kundenantwort in 24h muss JEDE Nachricht als Template gesendet werden
    $lastInbound = $db->getOne(
        "SELECT itime FROM whatsapp_messages
         WHERE phone_number = :phone AND direction = 'I'
         ORDER BY itime DESC LIMIT 1",
        [':phone' => $to]
    );
    $windowOpen = $lastInbound && strtotime($lastInbound['itime']) >= strtotime('-24 hours');
    // writeLog("24h-Fenster: " . ($windowOpen ? 'OFFEN' : 'GESCHLOSSEN') . " | lastInbound: " . ($lastInbound['itime'] ?? 'keine'));

    // Wenn Fenster abgelaufen: automatisch Chat-Template verwenden
    if (!$windowOpen) {
        $infoTplId = (int)($config['whatsapp_tpl_chat'] ?? 0);
        // writeLog("Template-Fallback: chatTplId={$infoTplId}");
        if ($infoTplId > 0) {
            $infoTpl = $db->getOne(
                "SELECT name, body_text FROM whatsapp_templates WHERE id = :id AND status = 'approved'",
                [':id' => $infoTplId]
            );
            // writeLog("Template geladen: " . ($infoTpl ? "name={$infoTpl['name']}" : 'NICHT GEFUNDEN'));
            if ($infoTpl) {
                $customerName = trim($data['customer_name'] ?? '');

                // Platzhalter im Template zaehlen und Parameter befuellen
                // Konvention: {{1}} = Kundenname, {{2}} = Nachrichtentext
                preg_match_all('/\{\{(\d+)\}\}/', $infoTpl['body_text'] ?? '', $phMatches);
                $paramCount = !empty($phMatches[1]) ? max(array_map('intval', $phMatches[1])) : 1;
                $tplParams = [];
                for ($i = 0; $i < $paramCount; $i++) {
                    $tplParams[] = ($i === 0 && $customerName) ? $customerName : $message;
                }
                // writeLog("Template '{$infoTpl['name']}': {$paramCount} Params => " . json_encode($tplParams, JSON_UNESCAPED_UNICODE));
                $result = _sendTemplateMessageInternal($to, $infoTpl, $tplParams, $customerId);
                // writeLog("Template-Ergebnis: " . json_encode($result, JSON_UNESCAPED_UNICODE));
                if ($result['success']) {
                    resultInfo(true, '', ['wa_message_id' => $result['wa_message_id'], 'status' => 'sent']);
                } else {
                    resultInfo(false, 'WHATSAPP_API_ERROR', $result['error'] ?? 'Template-Versand fehlgeschlagen');
                }
                return;
            }
        }
        // writeLog("Kein Info-Template konfiguriert — versuche Direktversand");
        // Kein Info-Template konfiguriert — trotzdem versuchen (Meta gibt dann Fehler)
    }

    // Meta Cloud API aufrufen (24h-Fenster offen)
    // writeLog("Sende Freitext-Nachricht via Meta API");
    $url = "https://graph.facebook.com/v21.0/{$phoneNumberId}/messages";
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => ltrim($to, '+'),
        'type' => 'text',
        'text' => ['body' => $message]
    ]);
    // writeLog("Payload: " . $payload);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // writeLog("Response HTTP {$httpCode}: " . $response);

    if ($curlError) {
        // writeLog("CURL-Fehler: " . $curlError);
        resultInfo(false, 'WHATSAPP_CURL_ERROR', $curlError);
        return;
    }

    $responseData = json_decode($response, true);

    if ($httpCode !== 200 || !isset($responseData['messages'][0]['id'])) {
        $errorMsg = $responseData['error']['message'] ?? 'Unbekannter Fehler';
        $errorDetail = "HTTP {$httpCode}: {$errorMsg}";
        if (!empty($responseData['error']['error_data']['details'])) {
            $errorDetail .= ' — ' . $responseData['error']['error_data']['details'];
        }
        // writeLog("API-Fehler: " . $errorDetail);
        error_log("WhatsApp API Error: " . json_encode($responseData));
        resultInfo(false, 'WHATSAPP_API_ERROR', $errorDetail);
        return;
    }

    $waMessageId = $responseData['messages'][0]['id'];
    // writeLog("Erfolg: waMessageId={$waMessageId}");

    // In DB speichern
    $db->prepareQuery(
        "INSERT INTO whatsapp_messages (wa_message_id, direction, phone_number, customer_id, message_type, message_text, status, itime)
         VALUES (:wa_id, 'O', :phone, :customer_id, 'text', :message, 'sent', NOW())",
        [
            ':wa_id' => $waMessageId,
            ':phone' => $to,
            ':customer_id' => $customerId > 0 ? $customerId : null,
            ':message' => $message
        ]
    );

    // writeLog("DB-Insert erfolgreich");
    resultInfo(true, '', [
        'wa_message_id' => $waMessageId,
        'status' => 'sent'
    ]);
}

/**
 * Dokument per WhatsApp als Template-Nachricht mit Dokument-Header senden
 * Funktioniert auch ausserhalb des 24h-Fensters, da Templates immer zugestellt werden
 *
 * @param string $data['to'] Telefonnummer
 * @param int    $data['customer_id'] Kunden-ID
 * @param string $data['document_base64'] PDF als Base64
 * @param string $data['filename'] Dateiname
 * @param int    $data['template_id'] Template-ID (muss in Meta mit Dokument-Header registriert sein)
 * @param array  $data['parameters'] Template-Platzhalter-Werte
 * @testdata {"to": "+491234567890", "customer_id": 1, "document_base64": "...", "filename": "Rechnung_123.pdf", "template_id": 1, "parameters": ["Herr Mueller", "R-123", "137.00"]}
 */
/**
 * Standort per WhatsApp senden (native Location-Message)
 *
 * @param string $data['to'] Ziel-Telefonnummer
 * @param float  $data['latitude'] Breitengrad
 * @param float  $data['longitude'] Laengengrad
 * @param int    $data['customer_id'] Kunden-ID (optional)
 * @testdata {"to": "+491234567890", "latitude": 52.5200, "longitude": 13.4050, "customer_id": 0}
 */
function sendWhatsAppLocation($data) {
    $config = _getWhatsAppConfig();
    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    if (empty($accessToken) || empty($phoneNumberId)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    $to = _normalizePhone($data['to'] ?? '', $countryCode);
    $lat = floatval($data['latitude'] ?? 0);
    $lon = floatval($data['longitude'] ?? 0);
    $customerId = (int)($data['customer_id'] ?? 0);

    if (empty($to) || ($lat == 0 && $lon == 0)) {
        resultInfo(false, 'INVALID_INPUT', 'Telefonnummer und Koordinaten sind erforderlich');
        return;
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'location',
        'location' => [
            'latitude' => $lat,
            'longitude' => $lon,
        ],
    ];

    $ch = curl_init("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $err = json_decode($response, true);
        $errMsg = $err['error']['message'] ?? 'Unbekannter Fehler';
        resultInfo(false, 'WHATSAPP_SEND_ERROR', $errMsg);
        return;
    }

    $result = json_decode($response, true);
    $waMessageId = $result['messages'][0]['id'] ?? null;

    // In DB speichern
    $db = DbhCompany::begin();
    $db->execute(
        "INSERT INTO whatsapp_messages (wa_message_id, direction, phone_number, customer_id, message_type, message_text, status, itime)
         VALUES (:wa_id, 'O', :phone, :cid, 'location', :text, 'sent', NOW())",
        [':wa_id' => $waMessageId, ':phone' => $to, ':cid' => $customerId > 0 ? $customerId : null, ':text' => "[Standort: {$lat}, {$lon}]"]
    );

    resultInfo(true, '', ['wa_message_id' => $waMessageId]);
}

function sendWhatsAppDocument($data) {
    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    if (empty($accessToken) || empty($phoneNumberId)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    $to = _normalizePhone($data['to'] ?? '', $countryCode);
    $customerId = (int)($data['customer_id'] ?? 0);
    $documentBase64 = $data['document_base64'] ?? '';
    $filename = trim($data['filename'] ?? 'Dokument.pdf');
    $templateId = (int)($data['template_id'] ?? 0);
    $parameters = $data['parameters'] ?? [];

    // writeLog("=== sendWhatsAppDocument ===");
    // writeLog("to: {$to}, filename: {$filename}, customerId: {$customerId}, templateId: {$templateId}, base64len: " . strlen($documentBase64));

    if (empty($to)) {
        resultInfo(false, 'INVALID_INPUT', 'Telefonnummer ist erforderlich');
        return;
    }

    if (empty($documentBase64)) {
        // writeLog("ABORT: Kein Dokument (base64 leer)");
        resultInfo(false, 'INVALID_INPUT', 'Kein Dokument angegeben');
        return;
    }

    if ($templateId <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Template ist erforderlich');
        return;
    }

    // Template laden
    $template = $db->getOne(
        "SELECT name, language, body_text FROM whatsapp_templates WHERE id = :id AND status = 'approved'",
        [':id' => $templateId]
    );

    if (!$template) {
        resultInfo(false, 'TEMPLATE_NOT_FOUND', 'Genehmigtes Template nicht gefunden');
        return;
    }

    // 1. Dokument bei Meta hochladen
    $binaryData = base64_decode($documentBase64);
    if ($binaryData === false) {
        resultInfo(false, 'INVALID_INPUT', 'Ungültige Base64-Daten');
        return;
    }

    // MIME-Type anhand der Dateiendung bestimmen
    $extLower = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', '3gp' => 'video/3gpp',
        'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg',
        'pdf' => 'application/pdf',
    ];
    $mime = $mimeMap[$extLower] ?? 'application/pdf';
    $isImage = str_starts_with($mime, 'image/');

    $uploadUrl = "https://graph.facebook.com/v21.0/{$phoneNumberId}/media";

    // Temporaere Datei fuer cURL Upload
    $tmpFile = tempnam(sys_get_temp_dir(), 'wa_doc_');
    file_put_contents($tmpFile, $binaryData);

    $cfile = new CURLFile($tmpFile, $mime, $filename);

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'messaging_product' => 'whatsapp',
            'file' => $cfile,
            'type' => $mime
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $uploadResponse = curl_exec($ch);
    $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $uploadError = curl_error($ch);
    curl_close($ch);
    unlink($tmpFile);

    if ($uploadError) {
        resultInfo(false, 'WHATSAPP_UPLOAD_ERROR', $uploadError);
        return;
    }

    $uploadData = json_decode($uploadResponse, true);

    if ($uploadHttpCode !== 200 || empty($uploadData['id'])) {
        $errorMsg = $uploadData['error']['message'] ?? 'Fehler beim Hochladen des Dokuments';
        $errorDetail = "HTTP {$uploadHttpCode}: {$errorMsg}";
        error_log("WhatsApp Upload Error: " . json_encode($uploadData));
        resultInfo(false, 'WHATSAPP_UPLOAD_ERROR', $errorDetail);
        return;
    }

    $mediaId = $uploadData['id'];
    // writeLog("Upload OK: mediaId={$mediaId}");

    // 2. Template-Nachricht mit Dokument-Header senden
    $langCode = ($template['language'] ?? 'de') === 'de' ? 'de' : 'en';
    $components = [];

    // Header: Dokument oder Bild je nach MIME-Type
    if ($isImage) {
        $components[] = [
            'type' => 'header',
            'parameters' => [[
                'type' => 'image',
                'image' => ['id' => $mediaId]
            ]]
        ];
    } else {
        $components[] = [
            'type' => 'header',
            'parameters' => [[
                'type' => 'document',
                'document' => [
                    'id' => $mediaId,
                    'filename' => $filename
                ]
            ]]
        ];
    }

    // Body-Parameter
    if (!empty($parameters)) {
        $bodyParams = [];
        foreach ($parameters as $val) {
            // Meta verbietet Zeilenumbrüche, Tabs und >4 aufeinanderfolgende Leerzeichen in Template-Parametern
            $sanitized = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$val);
            $sanitized = preg_replace('/\s{5,}/', '    ', $sanitized);
            $bodyParams[] = ['type' => 'text', 'text' => $sanitized];
        }
        $components[] = ['type' => 'body', 'parameters' => $bodyParams];
    }

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => ltrim($to, '+'),
        'type' => 'template',
        'template' => [
            'name' => $template['name'],
            'language' => ['code' => $langCode],
            'components' => $components
        ]
    ]);

    // writeLog("Template+Dokument Payload: " . $payload);

    $messageUrl = "https://graph.facebook.com/v21.0/{$phoneNumberId}/messages";
    $ch = curl_init($messageUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        resultInfo(false, 'WHATSAPP_CURL_ERROR', $curlError);
        return;
    }

    $responseData = json_decode($response, true);
    // writeLog("Template+Dokument Response HTTP {$httpCode}: " . mb_substr($response, 0, 300));

    if ($httpCode !== 200 || !isset($responseData['messages'][0]['id'])) {
        $errorMsg = $responseData['error']['message'] ?? 'Unbekannter Fehler';
        $errorDetail = "HTTP {$httpCode}: {$errorMsg}";
        if (!empty($responseData['error']['error_data']['details'])) {
            $errorDetail .= ' — ' . $responseData['error']['error_data']['details'];
        }
        // writeLog("Template+Dokument FEHLER: {$errorDetail}");
        resultInfo(false, 'WHATSAPP_API_ERROR', $errorDetail);
        return;
    }

    $waMessageId = $responseData['messages'][0]['id'];
    // writeLog("Template+Dokument gesendet: waMessageId={$waMessageId}");

    // Ausgehende Datei lokal speichern fuer Chat-Anzeige
    $localMediaUrl = $mediaId;
    if ($customerId > 0) {
        $dataDir = fmDataDir();
        $waDir = $dataDir . '/customers/' . $customerId . '/whatsapp';
        if (!is_dir($waDir)) mkdir($waDir, 0755, true);
        $localFile = $waDir . '/' . $filename;
        if (file_exists($localFile)) {
            $pi = pathinfo($filename);
            $base = $pi['filename'];
            $ext = isset($pi['extension']) ? '.' . $pi['extension'] : '';
            $c = 1;
            while (file_exists($waDir . '/' . $base . '_' . $c . $ext)) $c++;
            $localFile = $waDir . '/' . $base . '_' . $c . $ext;
        }
        if (file_put_contents($localFile, $binaryData) !== false) {
            $localMediaUrl = 'customers/' . $customerId . '/whatsapp/' . basename($localFile);
        }
    }

    // Gerenderten Text fuer Chat-Anzeige
    $renderedText = $template['body_text'];
    foreach ($parameters as $i => $val) {
        $renderedText = str_replace('{{' . ($i + 1) . '}}', $val, $renderedText);
    }
    $renderedText = _replaceSystemPlaceholders($renderedText, $customerId);

    // In DB speichern: message_text = Dateiname (fuer Chat-Anzeige), media_caption = gerenderter Text
    $msgType = $isImage ? 'image' : 'document';
    $db->execute(
        "INSERT INTO whatsapp_messages (wa_message_id, direction, phone_number, customer_id, message_type, message_text, media_url, media_mime_type, media_caption, status, itime)
         VALUES (:wa_id, 'O', :phone, :customer_id, :msg_type, :filename, :media_url, :mime, :caption, 'sent', NOW())",
        [
            ':wa_id' => $waMessageId,
            ':phone' => $to,
            ':customer_id' => $customerId > 0 ? $customerId : null,
            ':msg_type' => $msgType,
            ':filename' => $filename,
            ':media_url' => $localMediaUrl,
            ':mime' => $mime,
            ':caption' => $renderedText
        ]
    );

    resultInfo(true, '', [
        'wa_message_id' => $waMessageId,
        'status' => 'sent',
        'rendered_text' => $renderedText,
        'media_url' => $localMediaUrl,
        'mime_type' => $mime
    ]);
}

/**
 * Dokument im Chat senden (regulaere Document-Message, erfordert 24h-Fenster)
 *
 * @param string $data['to'] Telefonnummer
 * @param string $data['message'] Begleittext (Caption, optional)
 * @param int    $data['customer_id'] Kunden-ID (optional)
 * @param string $data['document_base64'] Datei als Base64
 * @param string $data['filename'] Dateiname
 * @testdata {"to": "+491234567890", "document_base64": "...", "filename": "Dokument.pdf"}
 */
function sendWhatsAppChatDocument($data) {
    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    if (empty($accessToken) || empty($phoneNumberId)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    $to = _normalizePhone($data['to'] ?? '', $countryCode);
    $message = trim($data['message'] ?? '');
    $customerId = (int)($data['customer_id'] ?? 0);
    $documentBase64 = $data['document_base64'] ?? '';
    $filename = trim($data['filename'] ?? 'Dokument.pdf');

    if (empty($to)) { resultInfo(false, 'INVALID_INPUT', 'Telefonnummer ist erforderlich'); return; }
    if (empty($documentBase64)) { resultInfo(false, 'INVALID_INPUT', 'Kein Dokument angegeben'); return; }

    // 24h-Fenster pruefen
    $lastInbound = $db->getOne(
        "SELECT itime FROM whatsapp_messages
         WHERE phone_number = :phone AND direction = 'I'
         ORDER BY itime DESC LIMIT 1",
        [':phone' => $to]
    );
    $windowOpen = $lastInbound && strtotime($lastInbound['itime']) >= strtotime('-24 hours');

    // MIME-Type anhand der Dateiendung bestimmen (frueher, fuer Template-Auswahl)
    $extLower = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', '3gp' => 'video/3gpp',
        'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg',
        'pdf' => 'application/pdf',
    ];
    $mime = $mimeMap[$extLower] ?? 'application/pdf';
    $isImage = str_starts_with($mime, 'image/');

    // Ausserhalb des 24h-Fensters: Fallback auf Template-Versand
    if (!$windowOpen) {
        // Header-Typ muss zum Medientyp passen
        $requiredHeaderType = $isImage ? 'IMAGE' : 'DOCUMENT';
        $configKey = $isImage ? 'whatsapp_tpl_chat_image' : 'whatsapp_tpl_chat_document';

        // Konfiguriertes Template aus defaults_oserp laden
        $configTplId = $db->getOne(
            "SELECT value FROM defaults_oserp WHERE key = :key",
            [':key' => $configKey]
        );
        $tplId = (int)($configTplId['value'] ?? 0);

        $docTemplate = null;
        if ($tplId > 0) {
            $docTemplate = $db->getOne(
                "SELECT id, body_text FROM whatsapp_templates
                 WHERE id = :id AND header_type = :htype AND status = 'approved'",
                [':id' => $tplId, ':htype' => $requiredHeaderType]
            );
        }

        // Fallback: erstbestes genehmigtes Template mit passendem Header-Typ
        if (!$docTemplate) {
            $docTemplate = $db->getOne(
                "SELECT id, body_text FROM whatsapp_templates
                 WHERE header_type = :htype AND status = 'approved'
                 ORDER BY is_default DESC, id ASC LIMIT 1",
                [':htype' => $requiredHeaderType]
            );
        }

        if (!$docTemplate) {
            $typeLabel = $isImage ? 'Bild' : 'Dokument';
            resultInfo(false, 'WHATSAPP_WINDOW_CLOSED', "Das 24h-Nachrichtenfenster ist geschlossen und kein genehmigtes {$typeLabel}-Template vorhanden.");
            return;
        }

        // Anzahl der Platzhalter im Template-Body ermitteln
        $paramCount = 0;
        if (preg_match_all('/\{\{\d+\}\}/', $docTemplate['body_text'] ?? '', $matches)) {
            $paramCount = count(array_unique($matches[0]));
        }

        // Kundenname fuer Platzhalter {{1}} ermitteln
        $customerName = '';
        if ($customerId > 0) {
            $customer = $db->getOne(
                "SELECT name FROM customer WHERE id = :id",
                [':id' => $customerId]
            );
            $customerName = $customer['name'] ?? '';
        }

        // Template-Parameter befuellen: {{1}}=Name, {{2}}=eingegebener Text
        $fallbackParams = [];
        for ($i = 0; $i < $paramCount; $i++) {
            if ($i === 0) {
                $fallbackParams[] = $customerName ?: 'Kunde';
            } elseif ($i === 1) {
                $fallbackParams[] = !empty($message) ? $message : 'anbei Ihr Dokument.';
            } else {
                $fallbackParams[] = '-';
            }
        }

        $data['template_id'] = $docTemplate['id'];
        $data['parameters'] = $fallbackParams;
        sendWhatsAppDocument($data);
        return;
    }

    // Dokument bei Meta hochladen
    $binaryData = base64_decode($documentBase64);
    if ($binaryData === false) { resultInfo(false, 'INVALID_INPUT', 'Ungueltige Base64-Daten'); return; }

    $uploadUrl = "https://graph.facebook.com/v21.0/{$phoneNumberId}/media";
    $tmpFile = tempnam(sys_get_temp_dir(), 'wa_doc_');
    file_put_contents($tmpFile, $binaryData);

    $extLower = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif',
        'mp4' => 'video/mp4', '3gp' => 'video/3gpp',
        'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg',
        'pdf' => 'application/pdf',
    ];
    $mime = $mimeMap[$extLower] ?? 'application/pdf';

    $cfile = new CURLFile($tmpFile, $mime, $filename);
    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['messaging_product' => 'whatsapp', 'file' => $cfile, 'type' => $mime],
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $uploadResponse = curl_exec($ch);
    $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    unlink($tmpFile);

    $uploadData = json_decode($uploadResponse, true);
    if ($uploadHttpCode !== 200 || empty($uploadData['id'])) {
        $errorMsg = $uploadData['error']['message'] ?? 'Fehler beim Hochladen';
        resultInfo(false, 'WHATSAPP_UPLOAD_ERROR', "HTTP {$uploadHttpCode}: {$errorMsg}");
        return;
    }

    $mediaId = $uploadData['id'];

    // Nachrichtentyp bestimmen (Bild oder Dokument)
    $isImage = str_starts_with($mime, 'image/');
    $msgType = $isImage ? 'image' : 'document';

    $payload = ['messaging_product' => 'whatsapp', 'to' => ltrim($to, '+'), 'type' => $msgType];
    if ($isImage) {
        $payload['image'] = ['id' => $mediaId];
        if (!empty($message)) $payload['image']['caption'] = $message;
    } else {
        $payload['document'] = ['id' => $mediaId, 'filename' => $filename];
        if (!empty($message)) $payload['document']['caption'] = $message;
    }

    $messageUrl = "https://graph.facebook.com/v21.0/{$phoneNumberId}/messages";
    $ch = curl_init($messageUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $responseData = json_decode($response, true);
    if ($httpCode !== 200 || !isset($responseData['messages'][0]['id'])) {
        $errorMsg = $responseData['error']['message'] ?? 'Unbekannter Fehler';
        resultInfo(false, 'WHATSAPP_API_ERROR', "HTTP {$httpCode}: {$errorMsg}");
        return;
    }

    $waMessageId = $responseData['messages'][0]['id'];

    // Ausgehende Datei lokal speichern fuer Chat-Anzeige
    $localMediaUrl = $mediaId;
    if ($customerId > 0) {
        $dataDir = fmDataDir();
        $waDir = $dataDir . '/customers/' . $customerId . '/whatsapp';
        if (!is_dir($waDir)) mkdir($waDir, 0755, true);
        $localFile = $waDir . '/' . $filename;
        if (file_exists($localFile)) {
            $pi = pathinfo($filename);
            $base = $pi['filename'];
            $ext = isset($pi['extension']) ? '.' . $pi['extension'] : '';
            $c = 1;
            while (file_exists($waDir . '/' . $base . '_' . $c . $ext)) $c++;
            $localFile = $waDir . '/' . $base . '_' . $c . $ext;
        }
        $binaryData = base64_decode($documentBase64);
        if ($binaryData !== false && file_put_contents($localFile, $binaryData) !== false) {
            $localMediaUrl = 'customers/' . $customerId . '/whatsapp/' . basename($localFile);
        }
    }

    $db->execute(
        "INSERT INTO whatsapp_messages (wa_message_id, direction, phone_number, customer_id, message_type, message_text, media_url, media_mime_type, media_caption, status, itime)
         VALUES (:wa_id, 'O', :phone, :customer_id, :msg_type, :filename, :media_url, :mime, :caption, 'sent', NOW())",
        [
            ':wa_id' => $waMessageId, ':phone' => $to,
            ':customer_id' => $customerId > 0 ? $customerId : null,
            ':msg_type' => $msgType, ':filename' => $filename,
            ':media_url' => $localMediaUrl, ':mime' => $mime,
            ':caption' => $message ?: null
        ]
    );

    resultInfo(true, '', ['wa_message_id' => $waMessageId, 'status' => 'sent', 'media_url' => $localMediaUrl, 'mime_type' => $mime]);
}

// ============================================================================
// TEMPLATE MANAGEMENT
// ============================================================================

/**
 * Alle WhatsApp-Templates laden
 *
 * @testdata {}
 */
function getWhatsAppTemplates($data) {
    $db = DbhCompany::begin();

    $templates = $db->getAll(
        "SELECT id, name, display_name, category, language, header_type, header_text, body_text,
                footer_text, status, meta_template_id, rejection_reason,
                is_default, template_type, example_values, itime, mtime
         FROM whatsapp_templates
         ORDER BY template_type, display_name"
    );

    resultInfo(true, '', ['templates' => $templates]);
}

/**
 * Nur genehmigte Templates laden (fuer Auswahl beim Senden)
 *
 * @param string $data['template_type'] Optional: Filter nach Typ (invoice, reminder, etc.)
 * @testdata {"template_type": ""}
 */
function getApprovedTemplates($data) {
    $db = DbhCompany::begin();
    $templateType = trim($data['template_type'] ?? '');

    $params = [];
    $typeFilter = '';
    if (!empty($templateType)) {
        $typeFilter = 'AND template_type = :type';
        $params[':type'] = $templateType;
    }

    $templates = $db->getAll(
        "SELECT id, name, display_name, body_text, header_text, footer_text, template_type, example_values
         FROM whatsapp_templates
         WHERE status = 'approved' $typeFilter
         ORDER BY template_type, display_name",
        $params
    );

    resultInfo(true, '', ['templates' => $templates]);
}

/**
 * WhatsApp-Template speichern und bei Meta einreichen
 *
 * @param int    $data['id'] Template-ID (0 fuer neu)
 * @param string $data['name'] Template-Name (lowercase, underscores)
 * @param string $data['display_name'] Anzeigename
 * @param string $data['category'] UTILITY oder MARKETING
 * @param string $data['language'] Sprachcode (de, en)
 * @param string $data['header_text'] Header-Text (optional)
 * @param string $data['body_text'] Body mit {{1}}, {{2}} Platzhaltern
 * @param string $data['footer_text'] Footer-Text (optional)
 * @param string $data['template_type'] general, invoice, order, reminder, info
 * @param bool   $data['submit_to_meta'] Bei Meta einreichen
 * @testdata {"id": 0, "name": "test_template", "display_name": "Test", "body_text": "Hallo {{1}}", "submit_to_meta": false}
 */
function saveWhatsAppTemplate($data) {
    $db = DbhCompany::begin();

    $tpl = $data['template'] ?? $data;

    $id = (int)($tpl['id'] ?? 0);
    $name = strtolower(preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($tpl['name'] ?? ''))));
    $displayName = trim($tpl['display_name'] ?? '');
    $category = in_array($tpl['category'] ?? '', ['UTILITY', 'MARKETING', 'AUTHENTICATION']) ? $tpl['category'] : 'UTILITY';
    $language = trim($tpl['language'] ?? 'de');
    $headerType = in_array(strtoupper($tpl['header_type'] ?? ''), ['TEXT', 'DOCUMENT', 'IMAGE', 'VIDEO']) ? strtoupper($tpl['header_type']) : null;
    $headerText = trim($tpl['header_text'] ?? '') ?: null;
    $bodyText = trim($tpl['body_text'] ?? '');
    $footerText = trim($tpl['footer_text'] ?? '') ?: null;
    $templateType = trim($tpl['template_type'] ?? 'general');
    $exampleValues = json_encode($tpl['example_values'] ?? new \stdClass());
    $submitToMeta = (bool)($tpl['submit_to_meta'] ?? false);

    if (empty($name) || empty($bodyText)) {
        resultInfo(false, 'INVALID_INPUT', 'Name und Nachrichtentext sind erforderlich');
        return;
    }

    if (empty($displayName)) {
        $displayName = $name;
    }

    if ($id > 0) {
        // Update
        $db->execute(
            "UPDATE whatsapp_templates
             SET name = :name, display_name = :display_name, category = :category,
                 language = :language, header_type = :header_type, header_text = :header, body_text = :body,
                 footer_text = :footer, template_type = :ttype, example_values = :examples, mtime = NOW()
             WHERE id = :id",
            [
                ':name' => $name, ':display_name' => $displayName, ':category' => $category,
                ':language' => $language, ':header_type' => $headerType, ':header' => $headerText, ':body' => $bodyText,
                ':footer' => $footerText, ':ttype' => $templateType, ':examples' => $exampleValues, ':id' => $id
            ]
        );
    } else {
        // Insert
        $row = $db->getOne(
            "INSERT INTO whatsapp_templates (name, display_name, category, language, header_type, header_text, body_text, footer_text, template_type, example_values, status)
             VALUES (:name, :display_name, :category, :language, :header_type, :header, :body, :footer, :ttype, :examples, 'draft')
             ON CONFLICT (name, language) DO UPDATE
             SET display_name = EXCLUDED.display_name, category = EXCLUDED.category,
                 header_type = EXCLUDED.header_type, header_text = EXCLUDED.header_text, body_text = EXCLUDED.body_text,
                 footer_text = EXCLUDED.footer_text, template_type = EXCLUDED.template_type,
                 example_values = EXCLUDED.example_values, mtime = NOW()
             RETURNING id",
            [
                ':name' => $name, ':display_name' => $displayName, ':category' => $category,
                ':language' => $language, ':header_type' => $headerType, ':header' => $headerText, ':body' => $bodyText,
                ':footer' => $footerText, ':ttype' => $templateType, ':examples' => $exampleValues
            ]
        );
        $id = (int)$row['id'];
    }

    // Bei Meta einreichen
    if ($submitToMeta) {
        $metaResult = _submitTemplateToMeta($id);
        if (!$metaResult['success']) {
            resultInfo(false, 'META_SUBMIT_ERROR', $metaResult['error']);
            return;
        }
    }

    resultInfo(true, '', ['id' => $id]);
}

/**
 * WhatsApp-Template loeschen
 *
 * @param int $data['id'] Template-ID
 * @testdata {"id": 1}
 */
function deleteWhatsAppTemplate($data) {
    $db = DbhCompany::begin();
    $id = (int)($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Template-ID ist erforderlich');
        return;
    }

    // Template-Info laden (fuer Meta-Loeschung)
    $template = $db->getOne("SELECT name, meta_template_id FROM whatsapp_templates WHERE id = :id", [':id' => $id]);
    if (!$template) {
        resultInfo(false, 'NOT_FOUND', 'Template nicht gefunden');
        return;
    }

    // Bei Meta loeschen wenn vorhanden
    if (!empty($template['meta_template_id'])) {
        _deleteTemplateFromMeta($template['name']);
    }

    $db->execute("DELETE FROM whatsapp_templates WHERE id = :id", [':id' => $id]);
    resultInfo(true, '');
}

/**
 * Template bei Meta einreichen
 *
 * @param int $data['id'] Template-ID
 * @testdata {"id": 1}
 */
function submitTemplateToMeta($data) {
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Template-ID ist erforderlich');
        return;
    }

    $result = _submitTemplateToMeta($id);
    if ($result['success']) {
        resultInfo(true, '', ['meta_template_id' => $result['meta_template_id']]);
    } else {
        resultInfo(false, 'META_SUBMIT_ERROR', $result['error']);
    }
}

/**
 * Templates von Meta synchronisieren (Status aktualisieren)
 *
 * @testdata {}
 */
function syncWhatsAppTemplates($data) {
    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $wabaId = $config['whatsapp_business_account_id'] ?? '';

    if (empty($accessToken) || empty($wabaId)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    $url = "https://graph.facebook.com/v21.0/{$wabaId}/message_templates?limit=250";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $responseData = json_decode($response, true);
        $errorMsg = $responseData['error']['message'] ?? 'Fehler beim Abrufen der Templates';
        resultInfo(false, 'META_SYNC_ERROR', "HTTP {$httpCode}: {$errorMsg}");
        return;
    }

    $responseData = json_decode($response, true);
    $metaTemplates = $responseData['data'] ?? [];
    $synced = 0;

    foreach ($metaTemplates as $mt) {
        $metaName = $mt['name'] ?? '';
        $metaStatus = strtolower($mt['status'] ?? 'pending');
        $metaId = $mt['id'] ?? '';
        $metaLang = 'de'; // Default
        if (!empty($mt['language'])) {
            $metaLang = substr($mt['language'], 0, 2); // de_DE -> de
        }
        $rejectionReason = $mt['rejected_reason'] ?? null;

        // Map Meta status to our status
        $statusMap = [
            'approved' => 'approved',
            'rejected' => 'rejected',
            'pending' => 'pending',
            'pending_deletion' => 'rejected',
            'deleted' => 'rejected',
            'disabled' => 'rejected',
            'paused' => 'approved', // paused is still approved, just temporarily disabled
        ];
        $localStatus = $statusMap[$metaStatus] ?? 'pending';

        // Extract body text from components
        $bodyText = '';
        $headerText = null;
        $footerText = null;
        foreach ($mt['components'] ?? [] as $comp) {
            if ($comp['type'] === 'BODY') $bodyText = $comp['text'] ?? '';
            if ($comp['type'] === 'HEADER' && ($comp['format'] ?? '') === 'TEXT') $headerText = $comp['text'] ?? null;
            if ($comp['type'] === 'FOOTER') $footerText = $comp['text'] ?? null;
        }

        if (empty($metaName) || empty($bodyText)) continue;

        // Upsert in lokale DB
        $db->execute(
            "INSERT INTO whatsapp_templates (name, display_name, category, language, header_text, body_text, footer_text, status, meta_template_id, rejection_reason, mtime)
             VALUES (:name, :display_name, :category, :language, :header, :body, :footer, :status, :meta_id, :rejection, NOW())
             ON CONFLICT (name, language) DO UPDATE
             SET status = EXCLUDED.status, meta_template_id = EXCLUDED.meta_template_id,
                 rejection_reason = EXCLUDED.rejection_reason, body_text = EXCLUDED.body_text,
                 header_text = EXCLUDED.header_text, footer_text = EXCLUDED.footer_text,
                 mtime = NOW()",
            [
                ':name' => $metaName, ':display_name' => ucfirst(str_replace('_', ' ', $metaName)),
                ':category' => $mt['category'] ?? 'UTILITY', ':language' => $metaLang,
                ':header' => $headerText, ':body' => $bodyText, ':footer' => $footerText,
                ':status' => $localStatus, ':meta_id' => $metaId,
                ':rejection' => $rejectionReason
            ]
        );
        $synced++;
    }

    resultInfo(true, '', ['synced' => $synced, 'total_from_meta' => count($metaTemplates)]);
}

/**
 * Standard-Templates laden (Vorbelegung)
 *
 * @testdata {}
 */
function loadDefaultTemplates($data) {
    $db = DbhCompany::begin();

    $defaults = [
        ['name' => 'allgemeine_info', 'display_name' => 'Chat', 'header_type' => null, 'body_text' => "Hallo {{1}},\n\nwir moechten Sie ueber Folgendes informieren:\n\n{{2}}\n\nBei Fragen stehen wir Ihnen gerne zur Verfuegung.\n\nMit freundlichen Gruessen\n{{3}}", 'template_type' => 'chat', 'category' => 'UTILITY'],
        ['name' => 'dokument_senden', 'display_name' => 'Dokument senden', 'header_type' => 'DOCUMENT', 'body_text' => 'Hallo {{1}}, anbei erhalten Sie {{2}} ueber {{3}} EUR. Bei Fragen stehen wir Ihnen gerne zur Verfuegung.', 'template_type' => 'document', 'category' => 'UTILITY'],
        ['name' => 'chat_dokument', 'display_name' => 'Chat-Dokument', 'header_type' => 'DOCUMENT', 'body_text' => "Hallo {{1}},\n\n{{2}}", 'template_type' => 'chat_document', 'category' => 'UTILITY'],
        ['name' => 'chat_bild', 'display_name' => 'Chat-Bild', 'header_type' => 'IMAGE', 'body_text' => "Hallo {{1}},\n\n{{2}}", 'template_type' => 'chat_image', 'category' => 'UTILITY'],
        ['name' => 'hu_erinnerung', 'display_name' => 'HU-Erinnerung', 'header_type' => null, 'body_text' => 'Hallo {{1}}, die HU fuer Ihr Fahrzeug ({{2}}) laeuft am {{3}} ab. Bitte vereinbaren Sie einen Termin.', 'template_type' => 'hu', 'category' => 'UTILITY'],
        ['name' => 'termin_erinnerung', 'display_name' => 'Terminerinnerung', 'header_type' => null, 'body_text' => 'Hallo {{1}}, wir moechten Sie an Ihren Termin am {{2}} um {{3}} Uhr erinnern.', 'template_type' => 'reminder', 'category' => 'UTILITY'],
        ['name' => 'termin_bestaetigung', 'display_name' => 'Terminbestaetigung', 'header_type' => null, 'body_text' => 'Hallo {{1}}, Ihr Termin am {{2}} um {{3}} Uhr ist bestaetigt. Wir freuen uns auf Ihren Besuch.', 'template_type' => 'appointment_confirm', 'category' => 'UTILITY'],
        ['name' => 'adresse_senden', 'display_name' => 'Adresse senden (alt)', 'header_type' => null, 'body_text' => "Hallo {{1}},\n\nhier finden Sie unsere Adresse:\n\n{{2}}\n\nMit freundlichen Gruessen\n{{3}}", 'template_type' => 'general', 'category' => 'UTILITY'],
        ['name' => 'adresse_senden_v2', 'display_name' => 'Adresse senden', 'header_type' => null, 'body_text' => "Hallo {{1}},\n\nhier finden Sie unsere Adresse:\n\n{{2}}\n\n{{3}}\n\nMit freundlichen Gruessen\n{{4}}", 'template_type' => 'address', 'category' => 'UTILITY'],
    ];

    $loaded = 0;
    foreach ($defaults as $tpl) {
        $db->execute(
            "INSERT INTO whatsapp_templates (name, display_name, header_type, body_text, template_type, category, is_default, status)
             VALUES (:name, :display_name, :header_type, :body, :ttype, :category, TRUE, 'draft')
             ON CONFLICT (name, language) DO NOTHING",
            [
                ':name' => $tpl['name'], ':display_name' => $tpl['display_name'],
                ':header_type' => $tpl['header_type'],
                ':body' => $tpl['body_text'], ':ttype' => $tpl['template_type'],
                ':category' => $tpl['category']
            ]
        );
        $loaded++;
    }

    resultInfo(true, '', ['loaded' => $loaded]);
}

/**
 * WhatsApp-Template-Nachricht senden (ausserhalb 24h-Fenster)
 *
 * @param string $data['to'] Telefonnummer
 * @param int    $data['template_id'] Template-ID
 * @param array  $data['parameters'] Platzhalter-Werte [{{1}} => "Wert", ...]
 * @param int    $data['customer_id'] Kunden-ID (optional)
 * @testdata {"to": "+491234567890", "template_id": 1, "parameters": ["Herr Müller", "MOL-LX10", "137"]}
 */
function sendWhatsAppTemplateMessage($data) {
    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    if (empty($accessToken) || empty($phoneNumberId)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    $to = _normalizePhone($data['to'] ?? '', $countryCode);
    $templateId = (int)($data['template_id'] ?? 0);
    $parameters = $data['parameters'] ?? [];
    $customerId = (int)($data['customer_id'] ?? 0);

    if (empty($to) || $templateId <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Telefonnummer und Template sind erforderlich');
        return;
    }

    // Template laden
    $template = $db->getOne(
        "SELECT name, language, body_text, header_text FROM whatsapp_templates WHERE id = :id AND status = 'approved'",
        [':id' => $templateId]
    );

    if (!$template) {
        resultInfo(false, 'TEMPLATE_NOT_FOUND', 'Genehmigtes Template nicht gefunden');
        return;
    }

    // Meta API Payload bauen
    $langCode = $template['language'] === 'de' ? 'de' : 'en';
    $components = [];

    // Body-Parameter
    if (!empty($parameters)) {
        $bodyParams = [];
        foreach ($parameters as $val) {
            // Meta verbietet Zeilenumbrüche, Tabs und >4 aufeinanderfolgende Leerzeichen in Template-Parametern
            $sanitized = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$val);
            $sanitized = preg_replace('/\s{5,}/', '    ', $sanitized);
            $bodyParams[] = ['type' => 'text', 'text' => $sanitized];
        }
        $components[] = ['type' => 'body', 'parameters' => $bodyParams];
    }

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => ltrim($to, '+'),
        'type' => 'template',
        'template' => [
            'name' => $template['name'],
            'language' => ['code' => $langCode],
            'components' => $components
        ]
    ]);

    $url = "https://graph.facebook.com/v21.0/{$phoneNumberId}/messages";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        resultInfo(false, 'WHATSAPP_CURL_ERROR', $curlError);
        return;
    }

    $responseData = json_decode($response, true);

    if ($httpCode !== 200 || !isset($responseData['messages'][0]['id'])) {
        $errorMsg = $responseData['error']['message'] ?? 'Unbekannter Fehler';
        $errorDetail = "HTTP {$httpCode}: {$errorMsg}";
        if (!empty($responseData['error']['error_data']['details'])) {
            $errorDetail .= ' — ' . $responseData['error']['error_data']['details'];
        }
        error_log("WhatsApp Template Send Error: " . json_encode($responseData));
        resultInfo(false, 'WHATSAPP_API_ERROR', $errorDetail);
        return;
    }

    $waMessageId = $responseData['messages'][0]['id'];

    // Nachrichtentext mit Parametern zusammenbauen (fuer Anzeige im Chat)
    $renderedText = $template['body_text'];
    foreach ($parameters as $i => $val) {
        $placeholder = '{{' . ($i + 1) . '}}';
        $renderedText = str_replace($placeholder, $val, $renderedText);
    }
    // System-Platzhalter ersetzen
    $renderedText = _replaceSystemPlaceholders($renderedText, $customerId);

    // In DB speichern
    $db->execute(
        "INSERT INTO whatsapp_messages (wa_message_id, direction, phone_number, customer_id, message_type, message_text, status, itime)
         VALUES (:wa_id, 'O', :phone, :customer_id, 'template', :message, 'sent', NOW())",
        [
            ':wa_id' => $waMessageId,
            ':phone' => $to,
            ':customer_id' => $customerId > 0 ? $customerId : null,
            ':message' => $renderedText
        ]
    );

    resultInfo(true, '', [
        'wa_message_id' => $waMessageId,
        'status' => 'sent',
        'rendered_text' => $renderedText
    ]);
}

/**
 * Adresse per WhatsApp-Template an Kunden senden (Google-Maps-Link)
 *
 * Laedt das zugewiesene Address-Template aus den Einstellungen und sendet es
 * mit Kundenname, Google-Maps-URL und Mitarbeitername als Parameter.
 *
 * @param string $data['to'] Telefonnummer des Kunden
 * @param int    $data['customer_id'] Kunden-ID
 * @param string $data['customer_name'] Name des Kunden
 * @param string $data['maps_url'] Google-Maps-URL der Adresse
 * @param string $data['phone_list'] Telefonnummern mit tel:-Prefix (z.B. "tel:+49123 (Note) | tel:+49456 (Werkstatt)")
 * @param string $data['employee_name'] Name des Mitarbeiters
 * @testdata {"to": "+491234567890", "customer_id": 1, "customer_name": "Max Mustermann", "maps_url": "https://www.google.com/maps/search/?api=1&query=Musterstr+1,+12345+Musterstadt", "phone_list": "tel:+491234567890 (Herr Müller) | tel:+491719876543 (Werkstatt)", "employee_name": "Hans Meier"}
 */
function sendWhatsAppAddressMessage($data) {
    $db = DbhCompany::begin();

    error_log("sendWhatsAppAddressMessage: Start — to=" . ($data['to'] ?? 'LEER')
        . ", customer_id=" . ($data['customer_id'] ?? 0)
        . ", customer_name=" . ($data['customer_name'] ?? 'LEER'));

    // Template-ID aus Einstellungen laden
    $row = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = :key",
        [':key' => 'whatsapp_tpl_address']
    );
    $templateId = (int)($row['value'] ?? 0);

    error_log("sendWhatsAppAddressMessage: Template-ID aus Einstellungen = " . $templateId);

    if ($templateId <= 0) {
        error_log("sendWhatsAppAddressMessage: FEHLER — Kein Adress-Template zugewiesen (whatsapp_tpl_address nicht gesetzt)");
        resultInfo(false, 'NO_ADDRESS_TEMPLATE', 'Kein Adress-Template in den Einstellungen zugewiesen. Bitte unter Einstellungen → Vorlagen-Zuordnung → "Adresse senden" ein genehmigtes Template auswaehlen.');
        return;
    }

    // Pruefen ob Template genehmigt ist
    $tplCheck = $db->getOne(
        "SELECT id, status, display_name FROM whatsapp_templates WHERE id = :id",
        [':id' => $templateId]
    );
    if (!$tplCheck) {
        error_log("sendWhatsAppAddressMessage: FEHLER — Template ID {$templateId} existiert nicht in der DB");
        resultInfo(false, 'TEMPLATE_NOT_FOUND', "Template ID {$templateId} existiert nicht");
        return;
    }
    if ($tplCheck['status'] !== 'approved') {
        error_log("sendWhatsAppAddressMessage: FEHLER — Template '{$tplCheck['display_name']}' hat Status '{$tplCheck['status']}' (muss 'approved' sein)");
        resultInfo(false, 'TEMPLATE_NOT_APPROVED', "Template '{$tplCheck['display_name']}' ist noch nicht von Meta genehmigt (Status: {$tplCheck['status']})");
        return;
    }

    // Parameter dynamisch aus dem Template-Body ermitteln
    $tplBody = $db->getOne(
        "SELECT body_text FROM whatsapp_templates WHERE id = :id",
        [':id' => $templateId]
    )['body_text'] ?? '';

    // Zaehlen wie viele {{n}} Platzhalter das Template hat
    preg_match_all('/\{\{(\d+)\}\}/', $tplBody, $matches);
    $paramCount = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;

    // Verfuegbare Werte: {{1}} = Kundenname, {{2}} = Maps-URL, {{3}} = Telefonnummern, {{4}} = Mitarbeitername
    $phoneList = trim($data['phone_list'] ?? '');
    $allParams = [
        $data['customer_name'] ?? '',
        $data['maps_url'] ?? '',
        !empty($phoneList) ? $phoneList : '-',
        $data['employee_name'] ?? ''
    ];

    // Nur so viele Parameter senden wie das Template erwartet
    $data['template_id'] = $templateId;
    $data['parameters'] = array_slice($allParams, 0, $paramCount);

    error_log("sendWhatsAppAddressMessage: Sende Template ID {$templateId} an " . ($data['to'] ?? ''));
    sendWhatsAppTemplateMessage($data);
}

/**
 * Template bei Meta Cloud API einreichen (interne Hilfsfunktion)
 */
function _submitTemplateToMeta(int $templateId): array {
    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $wabaId = $config['whatsapp_business_account_id'] ?? '';

    if (empty($accessToken) || empty($wabaId)) {
        return ['success' => false, 'error' => 'WhatsApp Business API ist nicht konfiguriert'];
    }

    $template = $db->getOne("SELECT * FROM whatsapp_templates WHERE id = :id", [':id' => $templateId]);
    if (!$template) {
        return ['success' => false, 'error' => 'Template nicht gefunden'];
    }

    $langCode = $template['language'] === 'de' ? 'de' : 'en';

    // Benannte Platzhalter ({{kunde_name}}, {{mitarbeiter_name}}) zu {{1}}, {{2}} etc. konvertieren
    // Meta kennt nur nummerierte Platzhalter
    $headerText = _convertToNumberedPlaceholders($template['header_text'] ?? '');
    $bodyText = _convertToNumberedPlaceholders($template['body_text'] ?? '');
    // Footer: Meta erlaubt keine Variablen — benannte Platzhalter mit echten Werten ersetzen
    $footerText = _replaceSystemPlaceholders($template['footer_text'] ?? '');

    $examples = json_decode($template['example_values'] ?? '{}', true) ?: [];

    // Variablen {{1}}, {{2}} etc. zaehlen und Beispielwerte zuordnen
    $extractExamples = function(string $text, string $key) use ($examples): ?array {
        if (preg_match_all('/\{\{(\d+)\}\}/', $text, $matches)) {
            $count = count(array_unique($matches[1]));
            $vals = $examples[$key] ?? [];
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                $val = trim($vals[$i] ?? '');
                $result[] = !empty($val) ? $val : 'Beispiel ' . ($i + 1);
            }
            return $result;
        }
        return null;
    };

    // Components bauen
    $components = [];
    $headerType = strtoupper(trim($template['header_type'] ?? ''));
    if ($headerType === 'DOCUMENT' || $headerType === 'IMAGE' || $headerType === 'VIDEO') {
        $mimeTypes = ['DOCUMENT' => 'application/pdf', 'IMAGE' => 'image/png', 'VIDEO' => 'video/mp4'];
        $handle = _uploadSampleMediaForTemplate($accessToken, $mimeTypes[$headerType]);
        if (!$handle) {
            return ['success' => false, 'error' => 'Beispieldatei konnte nicht bei Meta hochgeladen werden'];
        }
        $components[] = [
            'type' => 'HEADER',
            'format' => $headerType,
            'example' => ['header_handle' => [$handle]]
        ];
    } elseif (!empty($headerText)) {
        $header = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $headerText];
        $headerExamples = $extractExamples($headerText, 'header');
        if ($headerExamples) {
            $header['example'] = ['header_text' => $headerExamples];
        }
        $components[] = $header;
    }

    $body = ['type' => 'BODY', 'text' => $bodyText];
    $bodyExamples = $extractExamples($bodyText, 'body');
    if ($bodyExamples) {
        $body['example'] = ['body_text' => [$bodyExamples]];
    }
    $components[] = $body;

    if (!empty($footerText)) {
        $components[] = ['type' => 'FOOTER', 'text' => $footerText];
    }

    $metaTemplateId = $template['meta_template_id'] ?? null;

    // CREATE: Neues Template bei Meta anlegen
    // Fuer Aenderungen an bestehenden Templates: zuerst loeschen, dann neu erstellen
    $payload = json_encode([
        'name' => $template['name'],
        'language' => $langCode,
        'category' => $template['category'],
        'components' => $components
    ]);
    $url = "https://graph.facebook.com/v21.0/{$wabaId}/message_templates";

    error_log("WhatsApp Meta Submit Payload: " . $payload);
    // writeLog("=== WhatsApp Meta Submit ===");
    // writeLog("URL: " . $url);
    // writeLog("Payload: " . json_encode(json_decode($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $responseData = json_decode($response, true);

    // writeLog("Response HTTP {$httpCode}: " . json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    if ($httpCode !== 200 || (empty($metaTemplateId) && empty($responseData['id']))) {
        $errorMsg = $responseData['error']['message'] ?? 'Fehler beim Einreichen';
        $errorDetail = $responseData['error']['error_user_msg'] ?? $errorMsg;
        error_log("WhatsApp Meta Submit Error: HTTP {$httpCode} — " . json_encode($responseData));

        // Ablehnungsgrund in DB speichern
        $db->execute(
            "UPDATE whatsapp_templates SET status = 'rejected', rejection_reason = :reason, mtime = NOW() WHERE id = :id",
            [':reason' => "HTTP {$httpCode}: {$errorDetail}", ':id' => $templateId]
        );

        return ['success' => false, 'error' => "HTTP {$httpCode}: {$errorDetail}"];
    }

    // Status aktualisieren
    $metaId = $responseData['id'] ?? $metaTemplateId;
    $metaStatus = strtolower($responseData['status'] ?? 'pending');
    $statusMap = ['approved' => 'approved', 'rejected' => 'rejected'];
    $localStatus = $statusMap[$metaStatus] ?? 'pending';

    $db->execute(
        "UPDATE whatsapp_templates SET status = :status, meta_template_id = :meta_id, rejection_reason = NULL, mtime = NOW() WHERE id = :id",
        [':status' => $localStatus, ':meta_id' => $metaId, ':id' => $templateId]
    );

    return ['success' => true, 'meta_template_id' => $metaId, 'status' => $localStatus];
}

/**
 * Template bei Meta loeschen (interne Hilfsfunktion)
 */
function _deleteTemplateFromMeta(string $templateName): bool {
    $config = _getWhatsAppConfig();
    $accessToken = $config['whatsapp_access_token'] ?? '';
    $wabaId = $config['whatsapp_business_account_id'] ?? '';

    if (empty($accessToken) || empty($wabaId)) return false;

    $url = "https://graph.facebook.com/v21.0/{$wabaId}/message_templates?name=" . urlencode($templateName);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/**
 * Laedt eine Beispieldatei ueber die Meta Resumable Upload API hoch
 * und gibt den Handle zurueck (fuer Template-Erstellung mit Media-Header)
 */
function _uploadSampleMediaForTemplate(string $accessToken, string $mimeType): ?string {
    // 1. App-ID ermitteln via debug_token
    $ch = curl_init("https://graph.facebook.com/v21.0/debug_token?input_token={$accessToken}");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $debugData = json_decode($resp, true);
    $appId = $debugData['data']['app_id'] ?? null;
    // writeLog("Resumable Upload: app_id=" . ($appId ?: 'NICHT GEFUNDEN'));
    if (!$appId) return null;

    // 2. Minimale Beispieldatei erzeugen
    if ($mimeType === 'application/pdf') {
        $sampleData = "%PDF-1.0\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n174\n%%EOF";
    } elseif ($mimeType === 'image/png') {
        // 1x1 transparentes PNG
        $sampleData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    } else {
        return null;
    }
    $fileLength = strlen($sampleData);

    // 3. Upload-Session erstellen
    $sessionUrl = "https://graph.facebook.com/v21.0/{$appId}/uploads?file_length={$fileLength}&file_type=" . urlencode($mimeType) . "&access_token=" . urlencode($accessToken);
    // writeLog("Resumable Upload: Session URL: {$sessionUrl}");

    $ch = curl_init($sessionUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $sessionData = json_decode($resp, true);
    $uploadSessionId = $sessionData['id'] ?? null;
    // writeLog("Resumable Upload: Session Response HTTP {$httpCode}: " . $resp);
    if (!$uploadSessionId) return null;

    // 4. Datei hochladen
    $uploadUrl = "https://graph.facebook.com/v21.0/{$uploadSessionId}";
    // writeLog("Resumable Upload: Upload URL: {$uploadUrl}, fileLength: {$fileLength}");

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $sampleData,
        CURLOPT_HTTPHEADER => [
            'Authorization: OAuth ' . $accessToken,
            'file_offset: 0',
            'Content-Type: ' . $mimeType
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $uploadData = json_decode($resp, true);
    $handle = $uploadData['h'] ?? null;
    // writeLog("Resumable Upload: Upload Response HTTP {$httpCode}: " . $resp);
    // writeLog("Resumable Upload: Handle=" . ($handle ?: 'NICHT ERHALTEN'));

    return $handle;
}

/**
 * Terminerinnerungen verarbeiten und versenden
 *
 * @testdata {}
 */
function processWhatsAppReminders($data) {
    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();

    $reminderEnabled = $config['whatsapp_reminder_enabled'] ?? '';
    if ($reminderEnabled !== 'true' && $reminderEnabled !== '1' && $reminderEnabled !== 't') {
        resultInfo(true, '', ['sent' => 0, 'message' => 'Erinnerungen deaktiviert']);
        return;
    }

    $reminderHours = max(1, (int)($config['whatsapp_reminder_hours'] ?? 24));
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    // Genehmigte Reminder-Template finden
    $template = $db->getOne(
        "SELECT id, name, body_text FROM whatsapp_templates
         WHERE template_type = 'reminder' AND status = 'approved'
         ORDER BY is_default DESC, id ASC LIMIT 1"
    );

    if (!$template) {
        resultInfo(true, '', ['sent' => 0, 'message' => 'Kein genehmigtes Reminder-Template vorhanden']);
        return;
    }

    // Termine finden die innerhalb des Erinnerungszeitraums liegen
    // und fuer die noch keine Erinnerung gesendet wurde
    $events = $db->getAll(
        "SELECT ce.id AS event_id, ce.title, ce.dtstart, ce.cvp_id, ce.cvp_name, ce.cvp_type,
                c.phone, c.name AS customer_name
         FROM calendar_events ce
         JOIN customer c ON c.id = ce.cvp_id AND ce.cvp_type = 'C'
         WHERE ce.dtstart BETWEEN NOW() AND NOW() + (:hours || ' hours')::INTERVAL
           AND ce.cvp_id IS NOT NULL
           AND c.phone IS NOT NULL AND c.phone != ''
           AND NOT EXISTS (
               SELECT 1 FROM whatsapp_reminder_log wrl
               WHERE wrl.event_id = ce.id
           )
         ORDER BY ce.dtstart ASC
         LIMIT 50",
        [':hours' => $reminderHours]
    );

    $sent = 0;
    foreach ($events as $event) {
        $phone = _normalizePhone($event['phone'], $countryCode);
        if (empty($phone)) continue;

        $dtstart = new DateTime($event['dtstart']);
        $dateStr = $dtstart->format('d.m.Y');
        $timeStr = $dtstart->format('H:i');

        // Template-Parameter: {{1}}=Name, {{2}}=Datum, {{3}}=Uhrzeit
        $parameters = [$event['customer_name'], $dateStr, $timeStr];

        // Nachricht senden
        $sendResult = _sendTemplateMessageInternal($phone, $template, $parameters, (int)$event['cvp_id']);

        // Log-Eintrag
        $db->execute(
            "INSERT INTO whatsapp_reminder_log (event_id, customer_id, phone_number, template_id, wa_message_id, status)
             VALUES (:event_id, :customer_id, :phone, :tpl_id, :wa_id, :status)",
            [
                ':event_id' => (int)$event['event_id'],
                ':customer_id' => (int)$event['cvp_id'],
                ':phone' => $phone,
                ':tpl_id' => (int)$template['id'],
                ':wa_id' => $sendResult['wa_message_id'] ?? null,
                ':status' => $sendResult['success'] ? 'sent' : 'failed'
            ]
        );

        if ($sendResult['success']) $sent++;
    }

    resultInfo(true, '', ['sent' => $sent, 'total_events' => count($events)]);
}

/**
 * HU-WhatsApp-Erinnerungen automatisch versenden (Cronjob)
 *
 * Findet Fahrzeuge mit faelliger HU im konfigurierten Vorlaufzeitraum
 * und sendet Template-Nachrichten an Kunden, die nicht ausgeschlossen sind.
 *
 * @testdata {}
 */
function processHuWhatsAppReminders($data) {
    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();

    // Pruefen ob HU-WhatsApp-Erinnerungen aktiviert
    $huEnabled = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = :key",
        [':key' => 'lxcars_hu_whatsapp_enabled']
    );
    if (empty($huEnabled) || ($huEnabled['value'] !== 'true' && $huEnabled['value'] !== '1' && $huEnabled['value'] !== 't')) {
        resultInfo(true, '', ['sent' => 0, 'message' => 'HU-WhatsApp-Erinnerungen deaktiviert']);
        return;
    }

    $countryCode = $config['whatsapp_country_code'] ?? '49';

    // Vorlauf-Monate aus Konfiguration
    $vorlaufRow = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = :key",
        [':key' => 'lxcars_hu_vorlauf_monate']
    );
    $vorlaufMonate = max(1, (int)($vorlaufRow['value'] ?? 2));

    // Genehmigtes HU-Template finden
    $template = $db->getOne(
        "SELECT id, name, body_text FROM whatsapp_templates
         WHERE template_type = 'hu' AND status = 'approved'
         ORDER BY is_default DESC, id ASC LIMIT 1"
    );

    if (!$template) {
        resultInfo(true, '', ['sent' => 0, 'message' => 'Kein genehmigtes HU-Template vorhanden']);
        return;
    }

    // Fahrzeuge mit faelliger HU finden, fuer die noch keine Erinnerung gesendet wurde
    // Nur Kunden mit Telefonnummer, die nicht ausgeschlossen sind
    $events = $db->getAll(
        "SELECT car.c_id, car.c_ln, car.c_hu, car.c_m, car.c_t,
                c.id AS customer_id, c.name AS customer_name, c.phone
         FROM cars_lxcars car
         JOIN customer c ON c.id = car.c_ow
         LEFT JOIN customer_ext cext ON cext.customer_id = c.id
         WHERE car.c_hu IS NOT NULL
           AND car.c_hu BETWEEN CURRENT_DATE AND CURRENT_DATE + (:vorlauf || ' months')::INTERVAL
           AND c.phone IS NOT NULL AND c.phone != ''
           AND (cext.hu_serienbrief_excluded IS NULL OR cext.hu_serienbrief_excluded = false)
           AND NOT EXISTS (
               SELECT 1 FROM whatsapp_reminder_log wrl
               WHERE wrl.car_id = car.c_id
                 AND wrl.reminder_type = 'hu'
                 AND wrl.itime > CURRENT_DATE - (:vorlauf2 || ' months')::INTERVAL
           )
         ORDER BY car.c_hu ASC
         LIMIT 100",
        [':vorlauf' => $vorlaufMonate, ':vorlauf2' => $vorlaufMonate]
    );

    $sent = 0;
    foreach ($events as $event) {
        $phone = _normalizePhone($event['phone'], $countryCode);
        if (empty($phone)) continue;

        $huFormatted = date('d.m.Y', strtotime($event['c_hu']));
        $kennzeichen = $event['c_ln'] ?? '';

        // Template-Parameter: {{1}}=Name, {{2}}=Kennzeichen, {{3}}=HU-Datum
        $parameters = [$event['customer_name'], $kennzeichen, $huFormatted];

        $sendResult = _sendTemplateMessageInternal($phone, $template, $parameters, (int)$event['customer_id']);

        // Log-Eintrag
        $db->execute(
            "INSERT INTO whatsapp_reminder_log (reminder_type, car_id, customer_id, phone_number, template_id, wa_message_id, status)
             VALUES ('hu', :car_id, :customer_id, :phone, :tpl_id, :wa_id, :status)",
            [
                ':car_id' => (int)$event['c_id'],
                ':customer_id' => (int)$event['customer_id'],
                ':phone' => $phone,
                ':tpl_id' => (int)$template['id'],
                ':wa_id' => $sendResult['wa_message_id'] ?? null,
                ':status' => $sendResult['success'] ? 'sent' : 'failed'
            ]
        );

        if ($sendResult['success']) $sent++;
    }

    resultInfo(true, '', ['sent' => $sent, 'total_events' => count($events)]);
}

/**
 * Template-Nachricht intern senden (Hilfsfunktion fuer Reminder)
 */
function _sendTemplateMessageInternal(string $phone, array $template, array $parameters, int $customerId = 0): array {
    // writeLog("=== _sendTemplateMessageInternal ===");
    // writeLog("phone: {$phone}, template: {$template['name']}, params: " . json_encode($parameters, JSON_UNESCAPED_UNICODE));

    $config = _getWhatsAppConfig();
    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';

    if (empty($accessToken) || empty($phoneNumberId)) {
        // writeLog("ABORT: Nicht konfiguriert");
        return ['success' => false, 'error' => 'Nicht konfiguriert'];
    }

    $langCode = 'de';
    $components = [];
    if (!empty($parameters)) {
        $bodyParams = [];
        foreach ($parameters as $val) {
            // Meta verbietet Zeilenumbrüche, Tabs und >4 aufeinanderfolgende Leerzeichen in Template-Parametern
            $sanitized = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$val);
            $sanitized = preg_replace('/\s{5,}/', '    ', $sanitized);
            $bodyParams[] = ['type' => 'text', 'text' => $sanitized];
        }
        $components[] = ['type' => 'body', 'parameters' => $bodyParams];
    }

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => ltrim($phone, '+'),
        'type' => 'template',
        'template' => [
            'name' => $template['name'],
            'language' => ['code' => $langCode],
            'components' => $components
        ]
    ]);

    // writeLog("Payload: " . $payload);

    $url = "https://graph.facebook.com/v21.0/{$phoneNumberId}/messages";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // writeLog("Response HTTP {$httpCode}: " . $response);

    $responseData = json_decode($response, true);

    if ($httpCode !== 200 || !isset($responseData['messages'][0]['id'])) {
        $err = $responseData['error']['message'] ?? 'Fehler';
        // writeLog("Template-Fehler: " . $err);
        return ['success' => false, 'error' => $err];
    }

    $waMessageId = $responseData['messages'][0]['id'];

    // Gerenderten Text speichern
    $renderedText = $template['body_text'];
    foreach ($parameters as $i => $val) {
        $renderedText = str_replace('{{' . ($i + 1) . '}}', $val, $renderedText);
    }

    $db = DbhCompany::begin();
    $db->execute(
        "INSERT INTO whatsapp_messages (wa_message_id, direction, phone_number, customer_id, message_type, message_text, status, itime)
         VALUES (:wa_id, 'O', :phone, :cid, 'template', :text, 'sent', NOW())",
        [':wa_id' => $waMessageId, ':phone' => $phone, ':cid' => $customerId > 0 ? $customerId : null, ':text' => $renderedText]
    );

    return ['success' => true, 'wa_message_id' => $waMessageId];
}

/**
 * WhatsApp-Mediendatei abrufen (Bild, Audio, Video, Dokument)
 * Laedt die Datei von der Meta Cloud API und gibt sie als Base64 zurueck
 *
 * @param string $data['media_id'] Meta Media-ID
 * @testdata {"media_id": "123456789"}
 */
function getWhatsAppMedia($data) {
    $mediaId = trim($data['media_id'] ?? '');
    if (empty($mediaId)) {
        resultInfo(false, 'INVALID_INPUT', 'Media-ID ist erforderlich');
        return;
    }

    // Lokale Datei? (Pfad beginnt mit customers/, vendors/, whatsapp_unmatched/ oder whatsapp_cache/)
    if (preg_match('#^(customers|vendors|whatsapp_unmatched|whatsapp_cache)/#', $mediaId)) {
        $dataDir = fmDataDir();
        $localPath = $dataDir . '/' . $mediaId;

        // Path-Traversal verhindern
        $resolved = realpath($localPath);
        if ($resolved === false || strpos($resolved, $dataDir) !== 0) {
            resultInfo(false, 'WHATSAPP_MEDIA_ERROR', 'Datei nicht gefunden');
            return;
        }

        $fileData = file_get_contents($resolved);
        if ($fileData === false) {
            resultInfo(false, 'WHATSAPP_MEDIA_ERROR', 'Datei konnte nicht gelesen werden');
            return;
        }

        $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        $mimeMap = [
            'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            'pdf' => 'application/pdf', '3gp' => 'video/3gpp',
        ];
        $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';

        resultInfo(true, '', ['data' => base64_encode($fileData), 'mime_type' => $mimeType]);
        return;
    }

    // Bereits gecacht? Prüfen ob die Meta-ID schon lokal vorliegt
    $dataDir = fmDataDir();
    if ($dataDir) {
        $cached = glob($dataDir . '/whatsapp_cache/' . $mediaId . '.*');
        if (!empty($cached)) {
            $resolved = realpath($cached[0]);
            if ($resolved && strpos($resolved, $dataDir) === 0) {
                $fileData = file_get_contents($resolved);
                if ($fileData !== false) {
                    $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
                    $mimeMap = [
                        'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
                        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
                        'pdf' => 'application/pdf', '3gp' => 'video/3gpp',
                    ];
                    resultInfo(true, '', ['data' => base64_encode($fileData), 'mime_type' => $mimeMap[$ext] ?? 'application/octet-stream']);
                    return;
                }
            }
        }
    }

    // Meta-Media-ID — von Meta herunterladen und lokal cachen
    $config = _getWhatsAppConfig();
    $accessToken = $config['whatsapp_access_token'] ?? '';

    if (empty($accessToken)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    // Schritt 1: Download-URL von Meta holen
    $ch = curl_init("https://graph.facebook.com/v21.0/{$mediaId}");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        resultInfo(false, 'WHATSAPP_MEDIA_ERROR', 'Mediendatei konnte nicht abgerufen werden');
        return;
    }

    $mediaInfo = json_decode($response, true);
    $downloadUrl = $mediaInfo['url'] ?? '';
    $mimeType = $mediaInfo['mime_type'] ?? 'application/octet-stream';

    if (empty($downloadUrl)) {
        resultInfo(false, 'WHATSAPP_MEDIA_ERROR', 'Keine Download-URL erhalten');
        return;
    }

    // Schritt 2: Datei herunterladen
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $fileData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($fileData)) {
        resultInfo(false, 'WHATSAPP_MEDIA_ERROR', 'Datei konnte nicht heruntergeladen werden');
        return;
    }

    // Schritt 3: Lokal cachen — beim naechsten Aufruf direkt von Platte lesen
    _cacheWhatsAppMedia($mediaId, $fileData, $mimeType);

    resultInfo(true, '', [
        'data' => base64_encode($fileData),
        'mime_type' => $mimeType
    ]);
}

/**
 * Kunden-/Lieferanten-Typ und Ordner-Basispfad ermitteln
 *
 * @param int $customerId Kunden- oder Lieferanten-ID
 * @return array|null ['src' => 'C'|'V', 'basePath' => 'customers'|'vendors']
 */
function _resolveCustomerSrc($customerId) {
    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT 'C' AS src FROM customer WHERE id = :id
         UNION ALL
         SELECT 'V' AS src FROM vendor WHERE id = :id2
         LIMIT 1",
        [':id' => $customerId, ':id2' => $customerId]
    );
    if (!$row) return null;
    return [
        'src' => $row['src'],
        'basePath' => ($row['src'] === 'V') ? 'vendors' : 'customers',
    ];
}

/**
 * WhatsApp-Mediendatei von Meta laden oder lokale Datei lesen
 *
 * @param string $mediaId Meta Media-ID oder lokaler Pfad (customers/... oder vendors/...)
 * @return array|null ['data' => binary, 'mime_type' => string]
 */
function _fetchWhatsAppMediaData($mediaId) {
    if (preg_match('#^(customers|vendors|whatsapp_unmatched|whatsapp_cache)/#', $mediaId)) {
        $dataDir = fmDataDir();
        $localPath = $dataDir . '/' . $mediaId;
        $resolved = realpath($localPath);
        if ($resolved === false || strpos($resolved, $dataDir) !== 0) return null;
        $fileData = file_get_contents($resolved);
        if ($fileData === false) return null;

        $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        $mimeMap = [
            'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
            'pdf' => 'application/pdf', '3gp' => 'video/3gpp',
        ];
        return ['data' => $fileData, 'mime_type' => $mimeMap[$ext] ?? 'application/octet-stream'];
    }

    // Bereits gecacht? Prüfen ob die Meta-ID schon lokal vorliegt
    $dataDir = fmDataDir();
    if ($dataDir) {
        $cached = glob($dataDir . '/whatsapp_cache/' . $mediaId . '.*');
        if (!empty($cached)) {
            $resolved = realpath($cached[0]);
            if ($resolved && strpos($resolved, $dataDir) === 0) {
                $fileData = file_get_contents($resolved);
                if ($fileData !== false) {
                    $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
                    $mimeMap = [
                        'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
                        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
                        'pdf' => 'application/pdf', '3gp' => 'video/3gpp',
                    ];
                    return ['data' => $fileData, 'mime_type' => $mimeMap[$ext] ?? 'application/octet-stream'];
                }
            }
        }
    }

    // Von Meta herunterladen
    $config = _getWhatsAppConfig();
    $accessToken = $config['whatsapp_access_token'] ?? '';
    if (empty($accessToken)) return null;

    $ch = curl_init("https://graph.facebook.com/v21.0/{$mediaId}");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return null;

    $mediaInfo = json_decode($response, true);
    $downloadUrl = $mediaInfo['url'] ?? '';
    $mimeType = $mediaInfo['mime_type'] ?? 'application/octet-stream';
    if (empty($downloadUrl)) return null;

    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $fileData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || empty($fileData)) return null;

    // Lokal cachen fuer zukuenftige Aufrufe
    _cacheWhatsAppMedia($mediaId, $fileData, $mimeType);

    return ['data' => $fileData, 'mime_type' => $mimeType];
}

/**
 * Heruntergeladene Meta-Medien lokal cachen und media_url in der DB aktualisieren
 *
 * Speichert die Datei unter data/whatsapp_cache/{mediaId}.{ext} und aktualisiert
 * alle whatsapp_messages-Eintraege mit dieser Media-ID auf den lokalen Pfad.
 * Beim naechsten Aufruf wird die Datei direkt von Platte gelesen.
 *
 * @param string $metaMediaId Meta Media-ID
 * @param string $fileData Binaerdaten der Datei
 * @param string $mimeType MIME-Type der Datei
 */
function _cacheWhatsAppMedia(string $metaMediaId, string $fileData, string $mimeType): void {
    $dataDir = fmDataDir();
    if (!$dataDir) return;

    $cacheDir = $dataDir . '/whatsapp_cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    // Dateiendung aus MIME-Type
    $extMap = [
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'mp4',
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
        'application/pdf' => 'pdf',
    ];
    $mimeBase = strtolower(trim(explode(';', $mimeType)[0]));
    $ext = $extMap[$mimeBase] ?? 'bin';

    $filename = $metaMediaId . '.' . $ext;
    $fullPath = $cacheDir . '/' . $filename;

    if (file_put_contents($fullPath, $fileData) === false) return;

    // DB aktualisieren: media_url auf lokalen Cache-Pfad setzen
    $localPath = 'whatsapp_cache/' . $filename;
    try {
        $db = DbhCompany::begin();
        $db->execute(
            "UPDATE whatsapp_messages SET media_url = :local WHERE media_url = :meta",
            [':local' => $localPath, ':meta' => $metaMediaId]
        );
    } catch (\Exception $e) {
        // Cache-Update ist nicht kritisch
    }
}

/**
 * Ordnerstruktur fuer Speichern-Dialog laden
 *
 * Ermittelt automatisch ob Kunde oder Lieferant und gibt die Ordner zurueck.
 *
 * @param int    $data['customer_id'] Kunden- oder Lieferanten-ID
 * @param string $data['path'] Optionaler Unterpfad (relativ zum Kundenordner)
 * @testdata {"customer_id": 1, "path": ""}
 */
function getWhatsAppMediaSaveFolders($data) {
    $customerId = intval($data['customer_id'] ?? 0);
    $subPath = trim($data['path'] ?? '');

    if ($customerId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'customer_id ist erforderlich');
        return;
    }

    $cv = _resolveCustomerSrc($customerId);
    if (!$cv) {
        resultInfo(false, 'NOT_FOUND', 'Kunde/Lieferant nicht gefunden');
        return;
    }

    // Ordnerstruktur sicherstellen (Symlinks, Fahrzeuge etc.)
    $db = DbhCompany::begin();
    $table = ($cv['src'] === 'V') ? 'vendor' : 'customer';
    $row = $db->getOne("SELECT name FROM $table WHERE id = :id", [':id' => $customerId]);
    ensureCustomerFolder($customerId, $cv['src'], trim($row['name'] ?? ''));

    $dataDir = fmDataDir();
    $cvDir = $dataDir . '/' . $cv['basePath'] . '/' . $customerId;

    if (!is_dir($cvDir)) {
        fmMkdir($cvDir);
    }

    $targetDir = $cvDir;
    if ($subPath !== '') {
        $subPath = str_replace(['..', "\0"], '', $subPath);
        $subPath = trim($subPath, '/');
        $targetDir = $cvDir . '/' . $subPath;
        if (!is_dir($targetDir)) {
            $targetDir = $cvDir;
            $subPath = '';
        }
    }

    // Interne Ordner ausblenden
    $hiddenFolders = ['whatsapp'];

    $folders = [];
    if (is_dir($targetDir)) {
        foreach (scandir($targetDir) as $item) {
            if ($item === '.' || $item === '..' || $item[0] === '.') continue;
            if ($subPath === '' && in_array($item, $hiddenFolders)) continue;
            $fullPath = $targetDir . '/' . $item;
            if (is_link($fullPath)) {
                $resolved = realpath($fullPath);
                if ($resolved && is_dir($resolved)) {
                    $folders[] = $item;
                }
            } elseif (is_dir($fullPath)) {
                $folders[] = $item;
            }
        }
        sort($folders);
    }

    resultInfo(true, '', [
        'folders' => $folders,
        'path' => $subPath,
        'src' => $cv['src'],
    ]);
}

/**
 * WhatsApp-Mediendatei im Kunden-/Lieferantenordner speichern
 *
 * Laedt die Datei (lokal oder von Meta) und speichert sie im gewaehlten
 * Unterordner des zugeordneten Kunden bzw. Lieferanten.
 *
 * @param string $data['media_id'] Meta Media-ID oder lokaler Pfad
 * @param int    $data['customer_id'] Kunden- oder Lieferanten-ID
 * @param string $data['filename'] Gewuenschter Dateiname
 * @param string $data['path'] Optionaler Unterpfad (Zielordner relativ zum Kundenordner)
 * @testdata {"media_id": "customers/1/whatsapp/test.pdf", "customer_id": 1, "filename": "test.pdf", "path": ""}
 */
function saveWhatsAppMediaToFolder($data) {
    $mediaId = trim($data['media_id'] ?? '');
    $customerId = intval($data['customer_id'] ?? 0);
    $filename = trim($data['filename'] ?? '');
    $subPath = trim($data['path'] ?? '');

    if (empty($mediaId) || $customerId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'media_id und customer_id sind erforderlich');
        return;
    }

    $cv = _resolveCustomerSrc($customerId);
    if (!$cv) {
        resultInfo(false, 'NOT_FOUND', 'Kunde/Lieferant nicht gefunden');
        return;
    }

    // Mediendatei laden
    $media = _fetchWhatsAppMediaData($mediaId);
    if (!$media) {
        resultInfo(false, 'WHATSAPP_MEDIA_ERROR', 'Datei konnte nicht geladen werden');
        return;
    }

    // Dateiname bereinigen
    if (empty($filename)) {
        $ext = strtolower(pathinfo($mediaId, PATHINFO_EXTENSION));
        if (empty($ext)) {
            $extMap = [
                'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
                'image/webp' => 'webp', 'audio/ogg' => 'ogg', 'video/mp4' => 'mp4',
            ];
            $ext = $extMap[$media['mime_type']] ?? 'bin';
        }
        $filename = 'whatsapp_' . date('Y-m-d_H-i-s') . '.' . $ext;
    }
    $filename = basename($filename);
    $filename = preg_replace('/[^\w.\-]/', '_', $filename);

    // Zielverzeichnis bestimmen
    $dataDir = fmDataDir();
    $cvDir = $dataDir . '/' . $cv['basePath'] . '/' . $customerId;
    if (!is_dir($cvDir)) {
        mkdir($cvDir, 0755, true);
    }

    $targetDir = $cvDir;
    if ($subPath !== '') {
        $subPath = str_replace(['..', "\0"], '', $subPath);
        $subPath = trim($subPath, '/');
        $targetDir = $cvDir . '/' . $subPath;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
    }

    $filePath = $targetDir . '/' . $filename;

    // Bei Namenskollision Nummer anhaengen
    if (file_exists($filePath)) {
        $pathInfo = pathinfo($filename);
        $base = $pathInfo['filename'];
        $ext = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        $counter = 1;
        while (file_exists($targetDir . '/' . $base . '_' . $counter . $ext)) {
            $counter++;
        }
        $filename = $base . '_' . $counter . $ext;
        $filePath = $targetDir . '/' . $filename;
    }

    file_put_contents($filePath, $media['data']);

    resultInfo(true, 'SAVED', [
        'filename' => $filename,
        'path' => $subPath,
        'size' => strlen($media['data']),
        'src' => $cv['src'],
    ]);
}

/**
 * Aktuelles WhatsApp-Profilbild abrufen
 *
 * @testdata {}
 */
function getWhatsAppProfilePicture($data) {
    $config = _getWhatsAppConfig();
    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';

    if (empty($accessToken) || empty($phoneNumberId)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    $url = "https://graph.facebook.com/v21.0/{$phoneNumberId}/whatsapp_business_profile?fields=profile_picture_url";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        resultInfo(false, 'CURL_ERROR', $curlError);
        return;
    }

    $respData = json_decode($resp, true);

    if ($httpCode !== 200) {
        $errorMsg = $respData['error']['message'] ?? 'Fehler beim Abrufen des Profilbilds';
        resultInfo(false, 'API_ERROR', "HTTP {$httpCode}: {$errorMsg}");
        return;
    }

    $profileData = $respData['data'][0] ?? [];
    $pictureUrl = $profileData['profile_picture_url'] ?? '';

    resultInfo(true, '', ['profile_picture_url' => $pictureUrl]);
}

/**
 * WhatsApp-Profilbild hochladen und bei Meta einreichen
 *
 * Validiert Bildformat (JPEG/PNG), Groesse (max 5MB) und Abmessungen (640x640).
 * Laedt das Bild ueber die Resumable Upload API hoch und aktualisiert das Business-Profil.
 *
 * @param string $data['image_base64'] Base64-kodiertes Bild (JPEG oder PNG)
 * @param string $data['filename'] Dateiname mit Endung
 * @testdata {"image_base64": "", "filename": "profilbild.jpg"}
 */
function updateWhatsAppProfilePicture($data) {
    $config = _getWhatsAppConfig();
    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';

    if (empty($accessToken) || empty($phoneNumberId)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    $imageBase64 = $data['image_base64'] ?? '';
    $filename = trim($data['filename'] ?? 'profile.jpg');

    if (empty($imageBase64)) {
        resultInfo(false, 'INVALID_INPUT', 'Kein Bild angegeben');
        return;
    }

    // Base64 dekodieren
    $binaryData = base64_decode($imageBase64);
    if ($binaryData === false) {
        resultInfo(false, 'INVALID_INPUT', 'Ungültige Base64-Daten');
        return;
    }

    // Dateigröße prüfen (max 5MB)
    $fileSize = strlen($binaryData);
    if ($fileSize > 5 * 1024 * 1024) {
        resultInfo(false, 'FILE_TOO_LARGE', 'Das Bild darf maximal 5 MB groß sein');
        return;
    }

    // MIME-Type bestimmen und prüfen (nur JPEG/PNG)
    $extLower = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowedMimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];
    if (!isset($allowedMimes[$extLower])) {
        resultInfo(false, 'INVALID_FORMAT', 'Nur JPEG und PNG sind erlaubt');
        return;
    }
    $mime = $allowedMimes[$extLower];

    // Abmessungen prüfen
    $tmpFile = tempnam(sys_get_temp_dir(), 'wa_pp_');
    file_put_contents($tmpFile, $binaryData);
    $imageInfo = getimagesize($tmpFile);

    if ($imageInfo === false) {
        unlink($tmpFile);
        resultInfo(false, 'INVALID_IMAGE', 'Die Datei ist kein gültiges Bild');
        return;
    }

    $width = $imageInfo[0];
    $height = $imageInfo[1];

    if ($width < 192 || $height < 192) {
        unlink($tmpFile);
        resultInfo(false, 'IMAGE_TOO_SMALL', "Das Bild muss mindestens 192x192 Pixel groß sein (aktuell: {$width}x{$height})");
        return;
    }

    if ($width > 640 || $height > 640) {
        unlink($tmpFile);
        resultInfo(false, 'IMAGE_TOO_LARGE', "Das Bild darf maximal 640x640 Pixel groß sein (aktuell: {$width}x{$height})");
        return;
    }

    if ($width !== $height) {
        unlink($tmpFile);
        resultInfo(false, 'IMAGE_NOT_SQUARE', "Das Bild muss quadratisch sein (aktuell: {$width}x{$height})");
        return;
    }

    unlink($tmpFile);

    // writeLog("=== updateWhatsAppProfilePicture ===");
    // writeLog("filename: {$filename}, size: {$fileSize}, dimensions: {$width}x{$height}");

    // 1. App-ID ermitteln via debug_token
    $ch = curl_init("https://graph.facebook.com/v21.0/debug_token?input_token={$accessToken}");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $debugData = json_decode($resp, true);
    $appId = $debugData['data']['app_id'] ?? null;

    if (!$appId) {
        resultInfo(false, 'APP_ID_ERROR', 'App-ID konnte nicht ermittelt werden. Bitte Access Token prüfen.');
        return;
    }

    // 2. Upload-Session erstellen (Resumable Upload API)
    $sessionUrl = "https://graph.facebook.com/v21.0/{$appId}/uploads?file_length={$fileSize}&file_type=" . urlencode($mime) . "&access_token=" . urlencode($accessToken);

    $ch = curl_init($sessionUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $sessionData = json_decode($resp, true);
    $uploadSessionId = $sessionData['id'] ?? null;

    if (!$uploadSessionId) {
        $errorMsg = $sessionData['error']['message'] ?? 'Upload-Session konnte nicht erstellt werden';
        resultInfo(false, 'UPLOAD_SESSION_ERROR', "HTTP {$httpCode}: {$errorMsg}");
        return;
    }

    // writeLog("Upload Session erstellt: {$uploadSessionId}");

    // 3. Bild hochladen
    $uploadUrl = "https://graph.facebook.com/v21.0/{$uploadSessionId}";

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $binaryData,
        CURLOPT_HTTPHEADER => [
            'Authorization: OAuth ' . $accessToken,
            'file_offset: 0',
            'Content-Type: ' . $mime
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        resultInfo(false, 'UPLOAD_ERROR', $curlError);
        return;
    }

    $uploadData = json_decode($resp, true);
    $handle = $uploadData['h'] ?? null;

    if (!$handle) {
        $errorMsg = $uploadData['error']['message'] ?? 'Upload-Handle konnte nicht ermittelt werden';
        resultInfo(false, 'UPLOAD_ERROR', "HTTP {$httpCode}: {$errorMsg}");
        return;
    }

    // writeLog("Upload Handle erhalten: {$handle}");

    // 4. Business-Profil mit neuem Profilbild aktualisieren
    $profileUrl = "https://graph.facebook.com/v21.0/{$phoneNumberId}/whatsapp_business_profile";

    $ch = curl_init($profileUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'messaging_product' => 'whatsapp',
            'profile_picture_handle' => $handle
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        resultInfo(false, 'PROFILE_UPDATE_ERROR', $curlError);
        return;
    }

    $profileData = json_decode($resp, true);

    if ($httpCode !== 200 || empty($profileData['success'])) {
        $errorMsg = $profileData['error']['message'] ?? 'Profilbild konnte nicht aktualisiert werden';
        resultInfo(false, 'PROFILE_UPDATE_ERROR', "HTTP {$httpCode}: {$errorMsg}");
        return;
    }

    // writeLog("Profilbild erfolgreich aktualisiert");

    resultInfo(true, '', ['message' => 'Profilbild erfolgreich aktualisiert']);
}
