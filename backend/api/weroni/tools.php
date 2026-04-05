<?php
// backend/api/weroni/tools.php

/**
 * Weroni Tool-Definitionen und -Implementierungen.
 * Jedes Tool wird von Claude via tool_use aufgerufen.
 */

/**
 * Gibt die Tool-Definitionen für Claude zurück.
 */
function getWeroniToolDefinitions() {
    return [
        [
            'name' => 'search',
            'description' => 'Universelle Suche über das gesamte ERP-System. Durchsucht gleichzeitig Kunden, Lieferanten, Fahrzeuge, Aufträge, Rechnungen, WhatsApp-Nachrichten und Anrufe. Verwendet Fuzzy-Matching: Jenny findet Jennifer, Müler findet Müller, Waldi findet Waldsieversdorf. Gib einfach alles an was du weißt — die Suche kombiniert intelligent.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'q' => [
                        'type' => 'string',
                        'description' => 'Suchbegriffe, durch Leerzeichen getrennt. Alles was du weißt: Name, Ort, Marke, Kennzeichen, Telefon etc. Beispiel: "Jenny Waldsieversdorf Ford"'
                    ]
                ],
                'required' => ['q']
            ]
        ],
        [
            'name' => 'get_customer_details',
            'description' => 'Lädt ALLE Details zu einem Kunden: Fahrzeuge, Aufträge, Rechnungen, letzte WhatsApp-Nachrichten, letzte Anrufe. Verwende dies nachdem du mit search einen Kunden gefunden hast.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'integer', 'description' => 'Kunden-ID aus der Suche']
                ],
                'required' => ['customer_id']
            ]
        ],
        [
            'name' => 'send_email',
            'description' => 'Sendet eine E-Mail.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Empfänger-Adressen'],
                    'subject' => ['type' => 'string'],
                    'body_html' => ['type' => 'string', 'description' => 'E-Mail-Inhalt als HTML'],
                    'cc' => ['type' => 'array', 'items' => ['type' => 'string']]
                ],
                'required' => ['to', 'subject', 'body_html']
            ]
        ],
        [
            'name' => 'send_whatsapp',
            'description' => 'Sendet eine WhatsApp-Nachricht.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'phone_number' => ['type' => 'string', 'description' => 'Telefonnummer (+49... oder 0...)'],
                    'message' => ['type' => 'string']
                ],
                'required' => ['phone_number', 'message']
            ]
        ],
        [
            'name' => 'create_calendar_event',
            'description' => 'Erstellt einen Kalendereintrag.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'start' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM'],
                    'end' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM'],
                    'all_day' => ['type' => 'boolean'],
                    'location' => ['type' => 'string']
                ],
                'required' => ['title', 'start']
            ]
        ],
        [
            'name' => 'manage_task',
            'description' => 'Erstellt, aktualisiert oder erledigt eine Aufgabe.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['create', 'update', 'complete', 'list']],
                    'task_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'parent_id' => ['type' => 'integer'],
                    'due_date' => ['type' => 'string'],
                    'priority' => ['type' => 'integer', 'description' => '1-10'],
                    'assigned_to' => ['type' => 'string'],
                    'recurrence' => ['type' => 'string'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']]
                ],
                'required' => ['action']
            ]
        ],
        [
            'name' => 'remember',
            'description' => 'Speichert wichtige Informationen im Langzeitgedächtnis.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'category' => ['type' => 'string', 'enum' => ['person', 'preference', 'process', 'lesson', 'fact', 'contact']],
                    'subject' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'importance' => ['type' => 'integer', 'description' => '1-10']
                ],
                'required' => ['category', 'subject', 'content']
            ]
        ],
        [
            'name' => 'recall',
            'description' => 'Durchsucht das Langzeitgedächtnis.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'category' => ['type' => 'string', 'enum' => ['person', 'preference', 'process', 'lesson', 'fact', 'contact']]
                ],
                'required' => ['query']
            ]
        ],
        [
            'name' => 'ask_user',
            'description' => 'Stellt dem Benutzer eine Rückfrage (Icon blinkt).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'question' => ['type' => 'string'],
                    'context' => ['type' => 'string'],
                    'urgency' => ['type' => 'integer']
                ],
                'required' => ['question']
            ]
        ],
        [
            'name' => 'create_order',
            'description' => 'Erstellt einen neuen Auftrag.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'integer'],
                    'notes' => ['type' => 'string'],
                    'vehicle_license' => ['type' => 'string']
                ],
                'required' => ['customer_id']
            ]
        ]
    ];
}

/**
 * Führt ein Tool aus und gibt das Ergebnis zurück.
 */
function executeWeroniTool($toolName, $toolInput, $db) {
    switch ($toolName) {
        case 'search':                return _toolSearch($toolInput, $db);
        case 'get_customer_details':  return _toolGetCustomerDetails($toolInput, $db);
        case 'send_email':            return _toolSendEmail($toolInput, $db);
        case 'send_whatsapp':         return _toolSendWhatsapp($toolInput, $db);
        case 'create_calendar_event': return _toolCreateCalendarEvent($toolInput, $db);
        case 'manage_task':           return _toolManageTask($toolInput, $db);
        case 'remember':              return _toolRemember($toolInput, $db);
        case 'recall':                return _toolRecall($toolInput, $db);
        case 'ask_user':              return _toolAskUser($toolInput, $db);
        case 'create_order':          return _toolCreateOrder($toolInput, $db);
        default:                      return ['error' => 'Unbekanntes Tool: ' . $toolName];
    }
}

// ===== Tool-Implementierungen =====

/**
 * Universelle Fuzzy-Suche über das gesamte ERP.
 * Nutzt pg_trgm similarity() für fehlertolerante Suche.
 * Sucht parallel in Kunden, Lieferanten, Fahrzeugen — und kombiniert die Ergebnisse.
 */
function _toolSearch($input, $db) {
    $q = trim($input['q'] ?? '');
    if (empty($q)) {
        return ['error' => 'Suchbegriff erforderlich'];
    }

    $keywords = preg_split('/\s+/', $q);
    $results = ['kunden' => [], 'lieferanten' => [], 'fahrzeuge' => [], 'auftraege' => []];

    // ── KUNDEN: Fuzzy über Name, Stadt, Straße, Email, Telefon ──
    // Jedes Keyword muss irgendwo matchen (AND-Logik)
    $customerWhere = [];
    $customerParams = [];
    foreach ($keywords as $i => $kw) {
        $p = ':kw' . $i;
        $pLike = ':kwl' . $i;
        $customerParams[$p] = $kw;
        $customerParams[$pLike] = '%' . $kw . '%';
        $customerWhere[] = "(
            c.name ILIKE {$pLike}
            OR c.city ILIKE {$pLike}
            OR c.street ILIKE {$pLike}
            OR c.email ILIKE {$pLike}
            OR c.zipcode LIKE {$pLike}
            OR REPLACE(REPLACE(c.phone, ' ', ''), '-', '') LIKE REPLACE(REPLACE({$pLike}::text, ' ', ''), '-', '')
            OR similarity(c.name, {$p}) > 0.25
            OR similarity(c.city, {$p}) > 0.3
        )";
    }

    $customers = $db->getAll(
        "SELECT c.id, c.name, c.street, c.zipcode, c.city, c.phone, c.email,
                COALESCE(c.greeting, '') AS anrede,
                GREATEST(" . implode(', ', array_map(fn($i) => "similarity(c.name, :kw{$i})", array_keys($keywords))) . ") AS relevanz
         FROM customer c
         WHERE " . implode(' AND ', $customerWhere) . "
         ORDER BY relevanz DESC, c.name
         LIMIT 10",
        $customerParams
    );

    // Für jeden Kunden-Treffer: Fahrzeuge direkt mitladen
    if (!empty($customers)) {
        foreach ($customers as &$cust) {
            $cars = $db->getAll(
                "SELECT car.c_id, car.c_ln AS kennzeichen, car.c_mt AS modell,
                        COALESCE(k.hersteller, '') AS marke, COALESCE(k.name, car.c_mt) AS typ,
                        k.kraftstoff, k.leistung, k.hubraum,
                        TO_CHAR(car.c_d, 'DD.MM.YYYY') AS erstzulassung,
                        TO_CHAR(car.c_hu, 'MM/YYYY') AS tuev
                 FROM cars_lxcars car
                 LEFT JOIN kba_lxcars k ON k.id = car.kba_id
                 WHERE car.c_ow = :cid
                 ORDER BY car.c_ln",
                [':cid' => $cust['id']]
            );
            $cust['fahrzeuge'] = $cars ?: [];
        }
        unset($cust);
        $results['kunden'] = $customers;
    }

    // ── FAHRZEUGE: Fuzzy über Kennzeichen, Marke, Modell, Besitzername ──
    $vehicleWhere = [];
    $vehicleParams = [];
    foreach ($keywords as $i => $kw) {
        $p = ':vkw' . $i;
        $pLike = ':vkwl' . $i;
        $vehicleParams[$p] = $kw;
        $vehicleParams[$pLike] = '%' . $kw . '%';
        $vehicleWhere[] = "(
            car.c_ln ILIKE {$pLike}
            OR cu.name ILIKE {$pLike}
            OR cu.city ILIKE {$pLike}
            OR COALESCE(k.hersteller, '') ILIKE {$pLike}
            OR COALESCE(k.marke, '') ILIKE {$pLike}
            OR COALESCE(k.name, '') ILIKE {$pLike}
            OR similarity(cu.name, {$p}) > 0.25
            OR similarity(COALESCE(k.hersteller, ''), {$p}) > 0.3
        )";
    }

    $vehicles = $db->getAll(
        "SELECT car.c_id, car.c_ln AS kennzeichen, car.c_mt AS modell,
                cu.id AS kunden_id, cu.name AS besitzer, cu.city AS ort,
                COALESCE(k.hersteller, '') AS marke, COALESCE(k.name, '') AS typ,
                k.kraftstoff, k.leistung,
                TO_CHAR(car.c_d, 'DD.MM.YYYY') AS erstzulassung
         FROM cars_lxcars car
         LEFT JOIN customer cu ON cu.id = car.c_ow
         LEFT JOIN kba_lxcars k ON k.id = car.kba_id
         WHERE " . implode(' AND ', $vehicleWhere) . "
         ORDER BY cu.name
         LIMIT 10",
        $vehicleParams
    );
    $results['fahrzeuge'] = $vehicles ?: [];

    // ── LIEFERANTEN ──
    $vendorWhere = [];
    $vendorParams = [];
    foreach ($keywords as $i => $kw) {
        $p = ':lkw' . $i;
        $pLike = ':lkwl' . $i;
        $vendorParams[$p] = $kw;
        $vendorParams[$pLike] = '%' . $kw . '%';
        $vendorWhere[] = "(
            v.name ILIKE {$pLike}
            OR v.city ILIKE {$pLike}
            OR similarity(v.name, {$p}) > 0.25
        )";
    }

    $vendors = $db->getAll(
        "SELECT v.id, v.name, v.street, v.zipcode, v.city, v.phone, v.email
         FROM vendor v
         WHERE " . implode(' AND ', $vendorWhere) . "
         ORDER BY v.name LIMIT 5",
        $vendorParams
    );
    $results['lieferanten'] = $vendors ?: [];

    // ── AUFTRÄGE: Nummer oder Kundenname ──
    $orderWhere = [];
    $orderParams = [];
    foreach ($keywords as $i => $kw) {
        $pLike = ':okwl' . $i;
        $orderParams[$pLike] = '%' . $kw . '%';
        $orderWhere[] = "(o.ordnumber ILIKE {$pLike} OR cu.name ILIKE {$pLike})";
    }

    $orders = $db->getAll(
        "SELECT o.id, o.ordnumber, TO_CHAR(o.transdate, 'DD.MM.YYYY') AS datum,
                o.amount, cu.name AS kunde
         FROM oe o
         JOIN customer cu ON cu.id = o.customer_id
         WHERE o.record_type = 'sales_order' AND " . implode(' AND ', $orderWhere) . "
         ORDER BY o.transdate DESC LIMIT 5",
        $orderParams
    );
    $results['auftraege'] = $orders ?: [];

    // Zusammenfassung
    $total = count($results['kunden']) + count($results['lieferanten'])
           + count($results['fahrzeuge']) + count($results['auftraege']);

    return ['treffer_gesamt' => $total, 'ergebnisse' => $results];
}

/**
 * Lädt ALLE Details zu einem Kunden: Fahrzeuge, Aufträge, Rechnungen, WhatsApp, Anrufe.
 */
function _toolGetCustomerDetails($input, $db) {
    $cid = intval($input['customer_id'] ?? 0);
    if (!$cid) return ['error' => 'customer_id erforderlich'];

    // Kundendaten
    $customer = $db->getOne(
        "SELECT id, name, greeting, street, zipcode, city, phone, email
         FROM customer WHERE id = :id",
        [':id' => $cid]
    );
    if (!$customer) return ['error' => 'Kunde nicht gefunden'];

    // Zusätzliche Telefonnummern
    $ext = $db->getOne(
        "SELECT phone_numbers FROM customer_ext WHERE customer_id = :id",
        [':id' => $cid]
    );
    if ($ext && !empty($ext['phone_numbers'])) {
        $customer['weitere_telefonnummern'] = json_decode($ext['phone_numbers'], true);
    }

    // Fahrzeuge
    $customer['fahrzeuge'] = $db->getAll(
        "SELECT car.c_id, car.c_ln AS kennzeichen, car.c_mt AS modell,
                car.c_mkb AS motorkennbuchstabe, car.c_color AS farbe,
                COALESCE(k.hersteller, '') AS marke, COALESCE(k.name, '') AS typ,
                k.kraftstoff, k.leistung, k.hubraum,
                TO_CHAR(car.c_d, 'DD.MM.YYYY') AS erstzulassung,
                TO_CHAR(car.c_hu, 'MM/YYYY') AS tuev,
                car.c_text AS notizen
         FROM cars_lxcars car
         LEFT JOIN kba_lxcars k ON k.id = car.kba_id
         WHERE car.c_ow = :cid ORDER BY car.c_ln",
        [':cid' => $cid]
    ) ?: [];

    // Letzte 10 Aufträge mit Positionen
    $orders = $db->getAll(
        "SELECT o.id, o.ordnumber, TO_CHAR(o.transdate, 'DD.MM.YYYY') AS datum, o.amount,
                e.km_stand, e.status,
                (SELECT string_agg(oi.description, ', ' ORDER BY oi.position)
                 FROM orderitems oi WHERE oi.trans_id = o.id LIMIT 5) AS positionen,
                (SELECT string_agg(i.description, ', ' ORDER BY i.sort_order)
                 FROM oe_instructions_lxcars i WHERE i.oe_id = o.id) AS anweisungen
         FROM oe o
         LEFT JOIN oe_ext e ON e.oe_id = o.id
         WHERE o.customer_id = :cid AND o.record_type = 'sales_order'
         ORDER BY o.transdate DESC LIMIT 10",
        [':cid' => $cid]
    ) ?: [];
    $customer['auftraege'] = $orders;

    // Letzte 10 Rechnungen
    $customer['rechnungen'] = $db->getAll(
        "SELECT ar.invnumber, TO_CHAR(ar.transdate, 'DD.MM.YYYY') AS datum, ar.amount
         FROM ar WHERE ar.customer_id = :cid
         ORDER BY ar.transdate DESC LIMIT 10",
        [':cid' => $cid]
    ) ?: [];

    // Letzte 10 WhatsApp-Nachrichten
    $customer['whatsapp'] = $db->getAll(
        "SELECT direction, message_text, TO_CHAR(itime, 'DD.MM.YYYY HH24:MI') AS zeit
         FROM whatsapp_messages
         WHERE customer_id = :cid AND hidden = false
         ORDER BY itime DESC LIMIT 10",
        [':cid' => $cid]
    ) ?: [];

    // Letzte 5 Anrufe
    $customer['anrufe'] = $db->getAll(
        "SELECT crmti_direction AS richtung, crmti_number AS nummer,
                TO_CHAR(crmti_init_time, 'DD.MM.YYYY HH24:MI') AS zeit
         FROM crmti
         WHERE crmti_caller_id = :cid AND crmti_caller_typ = 'C'
         ORDER BY crmti_init_time DESC LIMIT 5",
        [':cid' => $cid]
    ) ?: [];

    return $customer;
}

function _toolSendEmail($input, $db) {
    require_once __DIR__.'/../email/email.php';

    try {
        ob_start();
        sendEmail([
            'to' => $input['to'],
            'subject' => $input['subject'],
            'body_html' => $input['body_html'],
            'body_text' => strip_tags($input['body_html']),
            'cc' => $input['cc'] ?? [],
            'bcc' => [],
            'attachments' => []
        ]);
        ob_get_clean();

        _logWeroniAction($db, 'email_sent', 'E-Mail an ' . implode(', ', $input['to']) . ': ' . $input['subject'], $input, null);
        return ['success' => true, 'message' => 'E-Mail gesendet an: ' . implode(', ', $input['to'])];
    } catch (Exception $e) {
        ob_end_clean();
        _logWeroniAction($db, 'email_sent', 'E-Mail-Fehler: ' . $e->getMessage(), $input, null, 'failed', $e->getMessage());
        return ['error' => 'E-Mail konnte nicht gesendet werden: ' . $e->getMessage()];
    }
}

function _toolSendWhatsapp($input, $db) {
    $phone = trim($input['phone_number'] ?? '');
    $message = trim($input['message'] ?? '');

    if (empty($phone) || empty($message)) {
        return ['error' => 'phone_number und message erforderlich'];
    }

    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('whatsapp_access_token', 'whatsapp_phone_number_id', 'whatsapp_country_code')"
    );

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    if (empty($accessToken) || empty($phoneNumberId)) {
        return ['error' => 'WhatsApp Business API ist nicht konfiguriert'];
    }

    // Telefonnummer normalisieren
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (str_starts_with($phone, '0')) {
        $phone = '+' . $countryCode . substr($phone, 1);
    }
    if (!str_starts_with($phone, '+')) {
        $phone = '+' . $phone;
    }
    $phonePlain = ltrim($phone, '+');

    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $phonePlain,
        'type' => 'text',
        'text' => ['body' => $message]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $responseData = json_decode($response, true);
        $waMessageId = $responseData['messages'][0]['id'] ?? null;
        if ($waMessageId) {
            $db->execute(
                "INSERT INTO whatsapp_messages (wa_message_id, direction, phone_number, message_type, message_text, status)
                 VALUES (:wid, 'O', :phone, 'text', :msg, 'sent') ON CONFLICT (wa_message_id) DO NOTHING",
                [':wid' => $waMessageId, ':phone' => $phone, ':msg' => $message]
            );
        }
        _logWeroniAction($db, 'whatsapp_sent', 'WhatsApp an ' . $phone, $input, null);
        return ['success' => true, 'message' => 'WhatsApp gesendet an: ' . $phone];
    }

    $error = 'HTTP ' . $httpCode . ': ' . $response;
    _logWeroniAction($db, 'whatsapp_sent', 'WhatsApp-Fehler', $input, null, 'failed', $error);
    return ['error' => 'WhatsApp konnte nicht gesendet werden: ' . $error];
}

function _toolCreateCalendarEvent($input, $db) {
    $employeeId = intval($_SESSION['employee_id'] ?? 0);

    $db->execute(
        "INSERT INTO calendar_events (title, description, dtstart, dtend, \"allDay\", location, uid, visibility)
         VALUES (:title, :desc, :start, :end, :allday, :loc, :uid, -1)",
        [
            ':title' => $input['title'],
            ':desc' => $input['description'] ?? '',
            ':start' => $input['start'],
            ':end' => $input['end'] ?? $input['start'],
            ':allday' => ($input['all_day'] ?? false) ? 't' : 'f',
            ':loc' => $input['location'] ?? '',
            ':uid' => $employeeId
        ]
    );

    _logWeroniAction($db, 'calendar_created', 'Termin erstellt: ' . $input['title'], $input, null);
    return ['success' => true, 'message' => 'Termin erstellt: ' . $input['title'] . ' am ' . $input['start']];
}

function _toolManageTask($input, $db) {
    $action = $input['action'];

    switch ($action) {
        case 'create':
            $db->execute(
                "INSERT INTO weroni_tasks (title, description, parent_id, due_date, priority, assigned_by, assigned_to, recurrence, tags)
                 VALUES (:title, :desc, :parent, :due, :prio, 'Weroni', :assigned, :recurrence, :tags)",
                [
                    ':title' => $input['title'] ?? 'Neue Aufgabe',
                    ':desc' => $input['description'] ?? null,
                    ':parent' => $input['parent_id'] ?? null,
                    ':due' => $input['due_date'] ?? null,
                    ':prio' => $input['priority'] ?? 5,
                    ':assigned' => $input['assigned_to'] ?? null,
                    ':recurrence' => $input['recurrence'] ?? null,
                    ':tags' => !empty($input['tags']) ? '{' . implode(',', $input['tags']) . '}' : null
                ]
            );
            return ['success' => true, 'message' => 'Aufgabe erstellt: ' . ($input['title'] ?? 'Neue Aufgabe')];

        case 'complete':
            $db->execute(
                "UPDATE weroni_tasks SET status = 'done', completed_at = NOW(), updated_at = NOW() WHERE id = :id",
                [':id' => $input['task_id']]
            );
            return ['success' => true, 'message' => 'Aufgabe als erledigt markiert'];

        case 'update':
            $sets = ["updated_at = NOW()"];
            $params = [':id' => $input['task_id']];
            if (isset($input['title'])) { $sets[] = "title = :title"; $params[':title'] = $input['title']; }
            if (isset($input['description'])) { $sets[] = "description = :desc"; $params[':desc'] = $input['description']; }
            if (isset($input['status'])) { $sets[] = "status = :status"; $params[':status'] = $input['status']; }
            if (isset($input['priority'])) { $sets[] = "priority = :prio"; $params[':prio'] = $input['priority']; }
            if (isset($input['due_date'])) { $sets[] = "due_date = :due"; $params[':due'] = $input['due_date']; }
            $db->execute("UPDATE weroni_tasks SET " . implode(', ', $sets) . " WHERE id = :id", $params);
            return ['success' => true, 'message' => 'Aufgabe aktualisiert'];

        case 'list':
            $tasks = $db->getAll(
                "SELECT t.id, t.title, t.description, t.status, t.priority, t.assigned_to,
                        TO_CHAR(t.due_date, 'DD.MM.YYYY HH24:MI') AS due_date,
                        t.parent_id, t.tags, t.recurrence
                 FROM weroni_tasks t
                 WHERE t.status NOT IN ('done', 'cancelled')
                 ORDER BY t.priority DESC, t.due_date ASC NULLS LAST
                 LIMIT 30",
                []
            );
            return ['tasks' => $tasks ?: []];
    }

    return ['error' => 'Unbekannte Aktion: ' . $action];
}

function _toolRemember($input, $db) {
    $existing = $db->getOne(
        "SELECT id FROM weroni_memory WHERE category = :cat AND subject ILIKE :subj LIMIT 1",
        [':cat' => $input['category'], ':subj' => '%' . $input['subject'] . '%']
    );

    if ($existing) {
        $db->execute(
            "UPDATE weroni_memory SET content = :content, importance = :imp, updated_at = NOW() WHERE id = :id",
            [':content' => $input['content'], ':imp' => $input['importance'] ?? 5, ':id' => $existing['id']]
        );
        return ['success' => true, 'message' => 'Erinnerung aktualisiert: ' . $input['subject']];
    }

    $db->execute(
        "INSERT INTO weroni_memory (category, subject, content, importance) VALUES (:cat, :subj, :content, :imp)",
        [':cat' => $input['category'], ':subj' => $input['subject'], ':content' => $input['content'], ':imp' => $input['importance'] ?? 5]
    );
    return ['success' => true, 'message' => 'Gemerkt: ' . $input['subject']];
}

function _toolRecall($input, $db) {
    $query = $input['query'];
    $params = [':q' => '%' . $query . '%'];
    $sql = "SELECT id, category, subject, content, importance,
                   TO_CHAR(updated_at, 'DD.MM.YYYY') AS aktualisiert
            FROM weroni_memory WHERE (subject ILIKE :q OR content ILIKE :q)";

    if (!empty($input['category'])) {
        $sql .= " AND category = :cat";
        $params[':cat'] = $input['category'];
    }

    $sql .= " ORDER BY importance DESC, updated_at DESC LIMIT 10";
    $results = $db->getAll($sql, $params);
    return ['count' => count($results ?: []), 'memories' => $results ?: []];
}

function _toolAskUser($input, $db) {
    $db->execute(
        "INSERT INTO weroni_questions (question, context, urgency, context_data)
         VALUES (:q, :ctx, :urg, :data)",
        [':q' => $input['question'], ':ctx' => $input['context'] ?? null,
         ':urg' => $input['urgency'] ?? 5, ':data' => json_encode($input)]
    );
    return ['success' => true, 'message' => 'Rückfrage gestellt.'];
}

function _toolCreateOrder($input, $db) {
    $customerId = intval($input['customer_id']);

    $last = $db->getOne("SELECT MAX(CAST(ordnumber AS INTEGER)) AS maxnum FROM oe WHERE ordnumber ~ '^[0-9]+$'", []);
    $nextNum = intval($last['maxnum'] ?? 0) + 1;

    $db->execute(
        "INSERT INTO oe (ordnumber, record_type, customer_id, transdate, reqdate, amount, netamount, notes)
         VALUES (:num, 'sales_order', :cid, CURRENT_DATE, CURRENT_DATE, 0, 0, :notes)",
        [':num' => strval($nextNum), ':cid' => $customerId, ':notes' => $input['notes'] ?? '']
    );

    $newOrder = $db->getOne("SELECT id, ordnumber FROM oe WHERE ordnumber = :num", [':num' => strval($nextNum)]);
    _logWeroniAction($db, 'order_created', 'Auftrag ' . $nextNum . ' erstellt', $input, $newOrder);
    return ['success' => true, 'order_id' => $newOrder['id'] ?? null, 'ordnumber' => strval($nextNum)];
}

/**
 * Protokolliert eine Weroni-Aktion.
 */
function _logWeroniAction($db, $type, $description, $inputData, $outputData, $status = 'success', $error = null) {
    $db->execute(
        "INSERT INTO weroni_actions (action_type, description, input_data, output_data, status, error_message, employee_id)
         VALUES (:type, :desc, :input, :output, :status, :error, :eid)",
        [':type' => $type, ':desc' => $description,
         ':input' => json_encode($inputData, JSON_UNESCAPED_UNICODE),
         ':output' => $outputData ? json_encode($outputData, JSON_UNESCAPED_UNICODE) : null,
         ':status' => $status, ':error' => $error,
         ':eid' => intval($_SESSION['employee_id'] ?? 0)]
    );
}
