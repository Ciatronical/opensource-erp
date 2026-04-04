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
            'name' => 'search_database',
            'description' => 'Durchsucht die ERP-Datenbank nach Kunden, Lieferanten, Fahrzeugen, Aufträgen oder Artikeln. Verwende natürlichsprachliche Beschreibungen, die in SQL-Suchen umgewandelt werden.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'entity' => [
                        'type' => 'string',
                        'enum' => ['customer', 'vendor', 'vehicle', 'order', 'invoice', 'part', 'employee'],
                        'description' => 'Was gesucht wird'
                    ],
                    'query' => [
                        'type' => 'string',
                        'description' => 'Suchbegriff (Name, Ort, Kennzeichen, Auftragsnummer, etc.)'
                    ],
                    'filters' => [
                        'type' => 'object',
                        'description' => 'Optionale Filter (city, vehicle_brand, date_from, date_to)',
                        'properties' => [
                            'city' => ['type' => 'string'],
                            'zipcode' => ['type' => 'string'],
                            'vehicle_brand' => ['type' => 'string'],
                            'date_from' => ['type' => 'string'],
                            'date_to' => ['type' => 'string']
                        ]
                    ]
                ],
                'required' => ['entity', 'query']
            ]
        ],
        [
            'name' => 'send_email',
            'description' => 'Sendet eine E-Mail an eine oder mehrere Adressen.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Empfänger-Adressen'],
                    'subject' => ['type' => 'string', 'description' => 'Betreff'],
                    'body_html' => ['type' => 'string', 'description' => 'E-Mail-Inhalt als HTML'],
                    'cc' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'CC-Empfänger']
                ],
                'required' => ['to', 'subject', 'body_html']
            ]
        ],
        [
            'name' => 'send_whatsapp',
            'description' => 'Sendet eine WhatsApp-Nachricht an eine Telefonnummer.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'phone_number' => ['type' => 'string', 'description' => 'Telefonnummer im internationalen Format (+49...)'],
                    'message' => ['type' => 'string', 'description' => 'Nachrichtentext']
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
                    'title' => ['type' => 'string', 'description' => 'Titel des Termins'],
                    'description' => ['type' => 'string'],
                    'start' => ['type' => 'string', 'description' => 'Startzeit (YYYY-MM-DD HH:MM)'],
                    'end' => ['type' => 'string', 'description' => 'Endzeit (YYYY-MM-DD HH:MM)'],
                    'all_day' => ['type' => 'boolean', 'description' => 'Ganztägig?'],
                    'location' => ['type' => 'string']
                ],
                'required' => ['title', 'start']
            ]
        ],
        [
            'name' => 'manage_task',
            'description' => 'Erstellt, aktualisiert oder erledigt eine Aufgabe in Weronis Aufgabenliste.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['create', 'update', 'complete', 'list'],
                        'description' => 'Was soll passieren'
                    ],
                    'task_id' => ['type' => 'integer', 'description' => 'Task-ID (für update/complete)'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'parent_id' => ['type' => 'integer', 'description' => 'Übergeordnete Aufgabe (für Teilaufgaben)'],
                    'due_date' => ['type' => 'string', 'description' => 'Fälligkeitsdatum (YYYY-MM-DD HH:MM)'],
                    'priority' => ['type' => 'integer', 'description' => '1-10 (10=höchste)'],
                    'assigned_to' => ['type' => 'string', 'description' => 'Name des Zuständigen'],
                    'recurrence' => ['type' => 'string', 'description' => 'z.B. weekly:3 für jeden Mittwoch'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']]
                ],
                'required' => ['action']
            ]
        ],
        [
            'name' => 'remember',
            'description' => 'Speichert eine Information im Langzeitgedächtnis. Verwende dies für wichtige Fakten, Vorlieben, Lektionen aus Fehlern oder Prozesswissen.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'enum' => ['person', 'preference', 'process', 'lesson', 'fact', 'contact'],
                        'description' => 'Art der Information'
                    ],
                    'subject' => ['type' => 'string', 'description' => 'Kurze Zusammenfassung'],
                    'content' => ['type' => 'string', 'description' => 'Detaillierte Information'],
                    'importance' => ['type' => 'integer', 'description' => '1-10 (10=sehr wichtig)']
                ],
                'required' => ['category', 'subject', 'content']
            ]
        ],
        [
            'name' => 'recall',
            'description' => 'Durchsucht das Langzeitgedächtnis nach gespeicherten Informationen.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Wonach suchen?'],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['person', 'preference', 'process', 'lesson', 'fact', 'contact'],
                        'description' => 'Optional: Nur in dieser Kategorie suchen'
                    ]
                ],
                'required' => ['query']
            ]
        ],
        [
            'name' => 'get_recent_emails',
            'description' => 'Ruft die neuesten E-Mails ab.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => ['type' => 'string', 'description' => 'IMAP-Ordner (Standard: INBOX)'],
                    'limit' => ['type' => 'integer', 'description' => 'Anzahl (Standard: 10)'],
                    'search' => ['type' => 'string', 'description' => 'Suchbegriff in Betreff/Absender']
                ],
                'required' => []
            ]
        ],
        [
            'name' => 'get_recent_whatsapp',
            'description' => 'Ruft die neuesten WhatsApp-Nachrichten ab.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Anzahl (Standard: 10)'],
                    'unread_only' => ['type' => 'boolean', 'description' => 'Nur ungelesene']
                ],
                'required' => []
            ]
        ],
        [
            'name' => 'get_recent_calls',
            'description' => 'Ruft die neuesten Anrufe ab.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Anzahl (Standard: 10)'],
                    'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound', 'all']]
                ],
                'required' => []
            ]
        ],
        [
            'name' => 'ask_user',
            'description' => 'Stellt dem Benutzer eine Rückfrage. Verwende dies wenn du eine Entscheidung nicht selbst treffen kannst. Die Frage erscheint als blinkende Benachrichtigung.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'question' => ['type' => 'string', 'description' => 'Die Frage an den Benutzer'],
                    'context' => ['type' => 'string', 'description' => 'Hintergrund zur Frage'],
                    'urgency' => ['type' => 'integer', 'description' => '1-10 (10=sehr dringend)']
                ],
                'required' => ['question']
            ]
        ],
        [
            'name' => 'create_order',
            'description' => 'Erstellt einen neuen Auftrag für einen Kunden.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'integer', 'description' => 'Kunden-ID'],
                    'notes' => ['type' => 'string', 'description' => 'Bemerkungen zum Auftrag'],
                    'vehicle_license' => ['type' => 'string', 'description' => 'Kennzeichen (bei KFZ-Aufträgen)']
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
        case 'search_database':
            return _toolSearchDatabase($toolInput, $db);
        case 'send_email':
            return _toolSendEmail($toolInput, $db);
        case 'send_whatsapp':
            return _toolSendWhatsapp($toolInput, $db);
        case 'create_calendar_event':
            return _toolCreateCalendarEvent($toolInput, $db);
        case 'manage_task':
            return _toolManageTask($toolInput, $db);
        case 'remember':
            return _toolRemember($toolInput, $db);
        case 'recall':
            return _toolRecall($toolInput, $db);
        case 'get_recent_emails':
            return _toolGetRecentEmails($toolInput, $db);
        case 'get_recent_whatsapp':
            return _toolGetRecentWhatsapp($toolInput, $db);
        case 'get_recent_calls':
            return _toolGetRecentCalls($toolInput, $db);
        case 'ask_user':
            return _toolAskUser($toolInput, $db);
        case 'create_order':
            return _toolCreateOrder($toolInput, $db);
        default:
            return ['error' => 'Unbekanntes Tool: ' . $toolName];
    }
}

// ===== Tool-Implementierungen =====

function _toolSearchDatabase($input, $db) {
    $entity = $input['entity'];
    $query = trim($input['query']);
    $filters = $input['filters'] ?? [];
    $results = [];

    switch ($entity) {
        case 'customer':
            $sql = "SELECT c.id, c.name, c.street, c.zipcode, c.city, c.phone, c.email,
                           COALESCE(c.greeting, '') AS greeting
                    FROM customer c
                    WHERE (c.name ILIKE :q OR c.city ILIKE :q OR c.zipcode LIKE :qstart
                           OR c.email ILIKE :q OR c.phone LIKE :qphone)";
            $params = [
                ':q' => '%' . $query . '%',
                ':qstart' => $query . '%',
                ':qphone' => '%' . preg_replace('/[^0-9]/', '', $query) . '%'
            ];
            if (!empty($filters['city'])) {
                $sql .= " AND c.city ILIKE :city";
                $params[':city'] = '%' . $filters['city'] . '%';
            }
            $sql .= " ORDER BY c.name LIMIT 15";
            $results = $db->getAll($sql, $params);
            break;

        case 'vendor':
            $results = $db->getAll(
                "SELECT id, name, street, zipcode, city, phone, email
                 FROM vendor WHERE name ILIKE :q OR city ILIKE :q ORDER BY name LIMIT 15",
                [':q' => '%' . $query . '%']
            );
            break;

        case 'vehicle':
            $results = $db->getAll(
                "SELECT c.c_id, c.c_ln AS kennzeichen, c.c_m AS marke, c.c_mt AS modell,
                        c.c_mkb AS motorkennbuchstabe, c.c_d AS erstzulassung,
                        cu.name AS besitzer,
                        k.hersteller, k.name AS typ, k.kraftstoff
                 FROM cars_lxcars c
                 LEFT JOIN customer cu ON cu.id = c.c_ow
                 LEFT JOIN kba_lxcars k ON k.id = c.kba_id
                 WHERE c.c_ln ILIKE :q OR cu.name ILIKE :q
                    OR k.hersteller ILIKE :q OR k.marke ILIKE :q OR k.name ILIKE :q
                    OR cu.city ILIKE :q
                 ORDER BY c.c_ln LIMIT 15",
                [':q' => '%' . $query . '%']
            );
            break;

        case 'order':
            $results = $db->getAll(
                "SELECT o.id, o.ordnumber, TO_CHAR(o.transdate, 'DD.MM.YYYY') AS datum,
                        o.amount, cu.name AS kunde,
                        (SELECT oi.description FROM orderitems oi WHERE oi.trans_id = o.id ORDER BY oi.position LIMIT 1) AS erste_position
                 FROM oe o
                 JOIN customer cu ON cu.id = o.customer_id
                 WHERE o.record_type = 'sales_order'
                   AND (o.ordnumber ILIKE :q OR cu.name ILIKE :q)
                 ORDER BY o.transdate DESC LIMIT 15",
                [':q' => '%' . $query . '%']
            );
            break;

        case 'invoice':
            $results = $db->getAll(
                "SELECT ar.id, ar.invnumber, TO_CHAR(ar.transdate, 'DD.MM.YYYY') AS datum,
                        ar.amount, cu.name AS kunde
                 FROM ar
                 JOIN customer cu ON cu.id = ar.customer_id
                 WHERE ar.invnumber ILIKE :q OR cu.name ILIKE :q
                 ORDER BY ar.transdate DESC LIMIT 15",
                [':q' => '%' . $query . '%']
            );
            break;

        case 'part':
            $results = $db->getAll(
                "SELECT id, partnumber, description, sellprice, unit, part_type
                 FROM parts
                 WHERE partnumber ILIKE :q OR description ILIKE :q
                 ORDER BY description LIMIT 15",
                [':q' => '%' . $query . '%']
            );
            break;

        case 'employee':
            $results = $db->getAll(
                "SELECT id, name, login, email FROM employee WHERE name ILIKE :q OR login ILIKE :q ORDER BY name LIMIT 15",
                [':q' => '%' . $query . '%']
            );
            break;
    }

    return ['entity' => $entity, 'count' => count($results ?: []), 'results' => $results ?: []];
}

function _toolSendEmail($input, $db) {
    // Email-Konfiguration laden
    require_once __DIR__.'/../email/email.php';

    try {
        // sendEmail erwartet $data direkt
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
        $output = ob_get_clean();
        $result = json_decode($output, true);

        // Aktion protokollieren
        _logWeroniAction($db, 'email_sent', 'E-Mail an ' . implode(', ', $input['to']) . ': ' . $input['subject'], $input, $result);

        return ['success' => true, 'message' => 'E-Mail gesendet an: ' . implode(', ', $input['to'])];
    } catch (Exception $e) {
        _logWeroniAction($db, 'email_sent', 'E-Mail-Fehler: ' . $e->getMessage(), $input, null, 'failed', $e->getMessage());
        return ['error' => 'E-Mail konnte nicht gesendet werden: ' . $e->getMessage()];
    }
}

function _toolSendWhatsapp($input, $db) {
    require_once __DIR__.'/../whatsapp/whatsapp.php';

    try {
        ob_start();
        sendWhatsAppMessage([
            'phone_number' => $input['phone_number'],
            'message' => $input['message']
        ]);
        $output = ob_get_clean();

        _logWeroniAction($db, 'whatsapp_sent', 'WhatsApp an ' . $input['phone_number'], $input, null);
        return ['success' => true, 'message' => 'WhatsApp gesendet an: ' . $input['phone_number']];
    } catch (Exception $e) {
        _logWeroniAction($db, 'whatsapp_sent', 'WhatsApp-Fehler: ' . $e->getMessage(), $input, null, 'failed', $e->getMessage());
        return ['error' => 'WhatsApp konnte nicht gesendet werden: ' . $e->getMessage()];
    }
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
    // Prüfen ob ähnlicher Eintrag existiert
    $existing = $db->getOne(
        "SELECT id FROM weroni_memory WHERE category = :cat AND subject ILIKE :subj LIMIT 1",
        [':cat' => $input['category'], ':subj' => '%' . $input['subject'] . '%']
    );

    if ($existing) {
        $db->execute(
            "UPDATE weroni_memory SET content = :content, importance = :imp, updated_at = NOW() WHERE id = :id",
            [':content' => $input['content'], ':imp' => $input['importance'] ?? 5, ':id' => $existing['id']]
        );
        return ['success' => true, 'message' => 'Erinnerung aktualisiert: ' . $input['subject'], 'updated' => true];
    }

    $db->execute(
        "INSERT INTO weroni_memory (category, subject, content, importance) VALUES (:cat, :subj, :content, :imp)",
        [
            ':cat' => $input['category'],
            ':subj' => $input['subject'],
            ':content' => $input['content'],
            ':imp' => $input['importance'] ?? 5
        ]
    );
    return ['success' => true, 'message' => 'Gemerkt: ' . $input['subject']];
}

function _toolRecall($input, $db) {
    $query = $input['query'];
    $params = [':q' => '%' . $query . '%'];
    $sql = "SELECT id, category, subject, content, importance,
                   TO_CHAR(updated_at, 'DD.MM.YYYY') AS aktualisiert
            FROM weroni_memory
            WHERE (subject ILIKE :q OR content ILIKE :q)";

    if (!empty($input['category'])) {
        $sql .= " AND category = :cat";
        $params[':cat'] = $input['category'];
    }

    $sql .= " ORDER BY importance DESC, updated_at DESC LIMIT 10";
    $results = $db->getAll($sql, $params);

    return ['count' => count($results ?: []), 'memories' => $results ?: []];
}

function _toolGetRecentEmails($input, $db) {
    // Email-Konfiguration laden und Mails abrufen
    try {
        require_once __DIR__.'/../email/email.php';
        ob_start();
        getAllEmails([
            'folder' => $input['folder'] ?? 'INBOX',
            'limit' => $input['limit'] ?? 10,
            'page' => 1,
            'search' => $input['search'] ?? ''
        ]);
        $output = ob_get_clean();
        $result = json_decode($output, true);
        return $result['payload'] ?? ['error' => 'Keine Emails verfügbar'];
    } catch (Exception $e) {
        return ['error' => 'Email-Abruf fehlgeschlagen: ' . $e->getMessage()];
    }
}

function _toolGetRecentWhatsapp($input, $db) {
    $limit = intval($input['limit'] ?? 10);
    $sql = "SELECT wm.id, wm.direction, wm.phone_number, wm.contact_name,
                   wm.message_text, wm.message_type, wm.status,
                   TO_CHAR(wm.itime, 'DD.MM.YYYY HH24:MI') AS zeit,
                   cu.name AS kunde
            FROM whatsapp_messages wm
            LEFT JOIN customer cu ON cu.id = wm.customer_id
            WHERE wm.hidden = false";

    if (!empty($input['unread_only'])) {
        $sql .= " AND wm.direction = 'I' AND wm.status = 'received'";
    }

    $sql .= " ORDER BY wm.itime DESC LIMIT :limit";
    $results = $db->getAll($sql, [':limit' => $limit]);
    return ['count' => count($results ?: []), 'messages' => $results ?: []];
}

function _toolGetRecentCalls($input, $db) {
    $limit = intval($input['limit'] ?? 10);
    $params = [':limit' => $limit];
    $sql = "SELECT c.crmti_id, c.crmti_direction AS richtung,
                   c.crmti_number AS nummer,
                   TO_CHAR(c.crmti_init_time, 'DD.MM.YYYY HH24:MI') AS zeit,
                   CASE WHEN c.crmti_caller_typ = 'C' THEN cu.name
                        WHEN c.crmti_caller_typ = 'V' THEN ve.name
                        ELSE 'Unbekannt' END AS kontakt
            FROM crmti c
            LEFT JOIN customer cu ON cu.id = c.crmti_caller_id AND c.crmti_caller_typ = 'C'
            LEFT JOIN vendor ve ON ve.id = c.crmti_caller_id AND c.crmti_caller_typ = 'V'
            WHERE 1=1";

    if (!empty($input['direction']) && $input['direction'] !== 'all') {
        $dir = $input['direction'] === 'inbound' ? 'E' : 'A';
        $sql .= " AND c.crmti_direction = :dir";
        $params[':dir'] = $dir;
    }

    $sql .= " ORDER BY c.crmti_init_time DESC LIMIT :limit";
    $results = $db->getAll($sql, $params);
    return ['count' => count($results ?: []), 'calls' => $results ?: []];
}

function _toolAskUser($input, $db) {
    $db->execute(
        "INSERT INTO weroni_questions (question, context, urgency, context_data)
         VALUES (:q, :ctx, :urg, :data)",
        [
            ':q' => $input['question'],
            ':ctx' => $input['context'] ?? null,
            ':urg' => $input['urgency'] ?? 5,
            ':data' => json_encode($input)
        ]
    );
    return ['success' => true, 'message' => 'Rückfrage gestellt. Der Benutzer wird benachrichtigt.'];
}

function _toolCreateOrder($input, $db) {
    $customerId = intval($input['customer_id']);

    // Nächste Auftragsnummer holen
    $last = $db->getOne("SELECT MAX(CAST(ordnumber AS INTEGER)) AS maxnum FROM oe WHERE ordnumber ~ '^[0-9]+$'", []);
    $nextNum = intval($last['maxnum'] ?? 0) + 1;

    $db->execute(
        "INSERT INTO oe (ordnumber, record_type, customer_id, transdate, reqdate, amount, netamount, notes)
         VALUES (:num, 'sales_order', :cid, CURRENT_DATE, CURRENT_DATE, 0, 0, :notes)",
        [
            ':num' => strval($nextNum),
            ':cid' => $customerId,
            ':notes' => $input['notes'] ?? ''
        ]
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
        [
            ':type' => $type,
            ':desc' => $description,
            ':input' => json_encode($inputData, JSON_UNESCAPED_UNICODE),
            ':output' => $outputData ? json_encode($outputData, JSON_UNESCAPED_UNICODE) : null,
            ':status' => $status,
            ':error' => $error,
            ':eid' => intval($_SESSION['employee_id'] ?? 0)
        ]
    );
}
