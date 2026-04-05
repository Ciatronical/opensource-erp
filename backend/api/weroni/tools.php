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
            'name' => 'find_person',
            'description' => 'Sucht Kunden oder Lieferanten mit allen verknüpften Daten (Fahrzeuge, Aufträge, Rechnungen). Alle Parameter werden mit UND verknüpft — je mehr du angibst, desto genauer das Ergebnis. Verwende dieses Tool wenn nach Personen, Firmen oder deren Fahrzeugen gefragt wird.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Name oder Teilname (Vor- oder Nachname)'],
                    'city' => ['type' => 'string', 'description' => 'Stadt/Ort'],
                    'zipcode' => ['type' => 'string', 'description' => 'PLZ oder PLZ-Anfang'],
                    'phone' => ['type' => 'string', 'description' => 'Telefonnummer (auch teilweise)'],
                    'email' => ['type' => 'string', 'description' => 'E-Mail-Adresse (auch teilweise)'],
                    'vehicle_brand' => ['type' => 'string', 'description' => 'Fahrzeugmarke (z.B. Ford, VW, BMW)'],
                    'vehicle_license' => ['type' => 'string', 'description' => 'Kennzeichen (auch teilweise)'],
                    'type' => ['type' => 'string', 'enum' => ['customer', 'vendor', 'both'], 'description' => 'Nur Kunden, nur Lieferanten oder beides (Standard: customer)']
                ],
                'required' => []
            ]
        ],
        [
            'name' => 'query_database',
            'description' => 'Führt eine SQL SELECT-Abfrage auf der ERP-Datenbank aus. NUR SELECT erlaubt. Wichtige Tabellen: customer (Kunden: id,name,street,zipcode,city,phone,email,greeting), vendor (Lieferanten: gleiche Struktur), cars_lxcars (Fahrzeuge: c_id,c_ow=Kunden-ID,c_ln=Kennzeichen,c_m=Marke,c_mt=Modell,c_d=Erstzulassung,c_hu=TÜV,c_fin=FIN,c_mkb=Motorkennbuchstabe), kba_lxcars (KBA: id,hersteller,marke,name=Modellname,kraftstoff,leistung,hubraum — JOIN via cars_lxcars.kba_id), oe (Aufträge: id,ordnumber,customer_id,transdate,amount,record_type), oe_ext (Auftrag-Fahrzeug: oe_id,c_id,km_stand,status,bringetermin,fertigstellung), orderitems (Positionen: trans_id,description,qty,sellprice), ar (Rechnungen: id,invnumber,customer_id,transdate,amount), invoice (Rechnungspositionen: trans_id,description,qty,sellprice,parts_id), parts (Artikel: id,partnumber,description,sellprice), employee (Mitarbeiter: id,name,login,email), calendar_events (Termine: id,title,dtstart,dtend,location,uid), whatsapp_messages (WhatsApp: id,direction,phone_number,contact_name,message_text,customer_id,itime), crmti (Anrufe: crmti_id,crmti_number,crmti_caller_id,crmti_caller_typ,crmti_direction,crmti_init_time), customer_ext (Zusatztelefon: customer_id,phone_numbers JSONB), oe_instructions_lxcars (Arbeitsanweisungen: oe_id,description,done,actual_minutes).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sql' => ['type' => 'string', 'description' => 'SQL SELECT-Abfrage. Verwende ILIKE für Textsuche. LIMIT 20 verwenden.']
                ],
                'required' => ['sql']
            ]
        ],
        [
            'name' => 'send_email',
            'description' => 'Sendet eine E-Mail.',
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
            'description' => 'Sendet eine WhatsApp-Nachricht. Die Telefonnummer muss im internationalen Format sein (+49...). Suche vorher die Nummer des Kunden mit find_person oder query_database.',
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
                    'action' => ['type' => 'string', 'enum' => ['create', 'update', 'complete', 'list']],
                    'task_id' => ['type' => 'integer', 'description' => 'Task-ID (für update/complete)'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'parent_id' => ['type' => 'integer', 'description' => 'Übergeordnete Aufgabe (für Teilaufgaben)'],
                    'due_date' => ['type' => 'string', 'description' => 'Fälligkeitsdatum (YYYY-MM-DD HH:MM)'],
                    'priority' => ['type' => 'integer', 'description' => '1-10 (10=höchste)'],
                    'assigned_to' => ['type' => 'string'],
                    'recurrence' => ['type' => 'string', 'description' => 'z.B. weekly:3 für jeden Mittwoch'],
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
                    'subject' => ['type' => 'string', 'description' => 'Kurze Zusammenfassung'],
                    'content' => ['type' => 'string', 'description' => 'Detaillierte Information'],
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
                    'query' => ['type' => 'string', 'description' => 'Wonach suchen?'],
                    'category' => ['type' => 'string', 'enum' => ['person', 'preference', 'process', 'lesson', 'fact', 'contact']]
                ],
                'required' => ['query']
            ]
        ],
        [
            'name' => 'ask_user',
            'description' => 'Stellt dem Benutzer eine Rückfrage. Die Frage erscheint als blinkende Benachrichtigung.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'question' => ['type' => 'string'],
                    'context' => ['type' => 'string'],
                    'urgency' => ['type' => 'integer', 'description' => '1-10']
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
        case 'find_person':       return _toolFindPerson($toolInput, $db);
        case 'query_database':    return _toolQueryDatabase($toolInput, $db);
        case 'send_email':        return _toolSendEmail($toolInput, $db);
        case 'send_whatsapp':     return _toolSendWhatsapp($toolInput, $db);
        case 'create_calendar_event': return _toolCreateCalendarEvent($toolInput, $db);
        case 'manage_task':       return _toolManageTask($toolInput, $db);
        case 'remember':          return _toolRemember($toolInput, $db);
        case 'recall':            return _toolRecall($toolInput, $db);
        case 'ask_user':          return _toolAskUser($toolInput, $db);
        case 'create_order':      return _toolCreateOrder($toolInput, $db);
        default:                  return ['error' => 'Unbekanntes Tool: ' . $toolName];
    }
}

// ===== Tool-Implementierungen =====

/**
 * Kombinierte Personen-/Kundensuche mit Fahrzeug-Verknüpfung.
 * Alle Parameter werden mit AND verknüpft.
 */
function _toolFindPerson($input, $db) {
    $type = $input['type'] ?? 'customer';
    $results = [];

    // Kunden suchen
    if ($type === 'customer' || $type === 'both') {
        $where = ["1=1"];
        $params = [];
        $joinCar = false;
        $joinKba = false;

        if (!empty($input['name'])) {
            $where[] = "c.name ILIKE :name";
            $params[':name'] = '%' . $input['name'] . '%';
        }
        if (!empty($input['city'])) {
            $where[] = "c.city ILIKE :city";
            $params[':city'] = '%' . $input['city'] . '%';
        }
        if (!empty($input['zipcode'])) {
            $where[] = "c.zipcode LIKE :zip";
            $params[':zip'] = $input['zipcode'] . '%';
        }
        if (!empty($input['phone'])) {
            $phoneClean = preg_replace('/[^0-9]/', '', $input['phone']);
            $where[] = "(c.phone LIKE :phone OR c.phone LIKE :phone2)";
            $params[':phone'] = '%' . $phoneClean . '%';
            $params[':phone2'] = '%' . $input['phone'] . '%';
        }
        if (!empty($input['email'])) {
            $where[] = "c.email ILIKE :email";
            $params[':email'] = '%' . $input['email'] . '%';
        }
        if (!empty($input['vehicle_brand'])) {
            $joinCar = true;
            $joinKba = true;
            $where[] = "(k.hersteller ILIKE :brand OR k.marke ILIKE :brand)";
            $params[':brand'] = '%' . $input['vehicle_brand'] . '%';
        }
        if (!empty($input['vehicle_license'])) {
            $joinCar = true;
            $where[] = "car.c_ln ILIKE :license";
            $params[':license'] = '%' . $input['vehicle_license'] . '%';
        }

        // Mindestens ein Suchkriterium
        if (count($where) <= 1) {
            return ['error' => 'Mindestens ein Suchkriterium angeben (name, city, phone, email, vehicle_brand, vehicle_license)'];
        }

        $carJoin = $joinCar ? "LEFT JOIN cars_lxcars car ON car.c_ow = c.id" : "";
        $kbaJoin = $joinKba ? "LEFT JOIN kba_lxcars k ON k.id = car.kba_id" : "";
        $carSelect = $joinCar
            ? ", car.c_ln AS kennzeichen, car.c_mt AS fahrzeug_modell"
              . ($joinKba ? ", k.hersteller AS fahrzeug_marke, k.name AS fahrzeug_typ, k.kraftstoff" : "")
            : "";

        $sql = "SELECT DISTINCT c.id, c.name, c.street, c.zipcode, c.city, c.phone, c.email,
                       COALESCE(c.greeting, '') AS anrede{$carSelect}
                FROM customer c
                {$carJoin}
                {$kbaJoin}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.name LIMIT 20";

        $customers = $db->getAll($sql, $params);

        // Für jeden Treffer: Fahrzeuge nachladen wenn nicht schon gejoined
        if (!empty($customers)) {
            foreach ($customers as &$cust) {
                $cust['typ'] = 'Kunde';
                // Fahrzeuge laden
                $cars = $db->getAll(
                    "SELECT car.c_ln AS kennzeichen, car.c_mt AS modell,
                            k.hersteller, k.name AS typ, k.kraftstoff, k.leistung,
                            TO_CHAR(car.c_d, 'DD.MM.YYYY') AS erstzulassung,
                            TO_CHAR(car.c_hu, 'MM/YYYY') AS tuev
                     FROM cars_lxcars car
                     LEFT JOIN kba_lxcars k ON k.id = car.kba_id
                     WHERE car.c_ow = :cid
                     ORDER BY car.c_ln",
                    [':cid' => $cust['id']]
                );
                $cust['fahrzeuge'] = $cars ?: [];

                // Letzte 5 Aufträge
                $orders = $db->getAll(
                    "SELECT o.ordnumber, TO_CHAR(o.transdate, 'DD.MM.YYYY') AS datum, o.amount
                     FROM oe o WHERE o.customer_id = :cid AND o.record_type = 'sales_order'
                     ORDER BY o.transdate DESC LIMIT 5",
                    [':cid' => $cust['id']]
                );
                $cust['letzte_auftraege'] = $orders ?: [];
            }
            unset($cust);
            $results = array_merge($results, $customers);
        }
    }

    // Lieferanten suchen
    if ($type === 'vendor' || $type === 'both') {
        $where = ["1=1"];
        $params = [];

        if (!empty($input['name'])) {
            $where[] = "v.name ILIKE :name";
            $params[':name'] = '%' . $input['name'] . '%';
        }
        if (!empty($input['city'])) {
            $where[] = "v.city ILIKE :city";
            $params[':city'] = '%' . $input['city'] . '%';
        }
        if (!empty($input['phone'])) {
            $where[] = "v.phone LIKE :phone";
            $params[':phone'] = '%' . $input['phone'] . '%';
        }
        if (!empty($input['email'])) {
            $where[] = "v.email ILIKE :email";
            $params[':email'] = '%' . $input['email'] . '%';
        }

        if (count($where) > 1) {
            $vendors = $db->getAll(
                "SELECT v.id, v.name, v.street, v.zipcode, v.city, v.phone, v.email
                 FROM vendor v WHERE " . implode(' AND ', $where) . " ORDER BY v.name LIMIT 10",
                $params
            );
            if (!empty($vendors)) {
                foreach ($vendors as &$v) { $v['typ'] = 'Lieferant'; }
                unset($v);
                $results = array_merge($results, $vendors);
            }
        }
    }

    return ['count' => count($results), 'results' => $results];
}

/**
 * Führt eine sichere SQL SELECT-Abfrage aus.
 */
function _toolQueryDatabase($input, $db) {
    $sql = trim($input['sql'] ?? '');

    // Sicherheitscheck: NUR SELECT erlaubt
    $sqlUpper = strtoupper(ltrim($sql));
    if (!str_starts_with($sqlUpper, 'SELECT')) {
        return ['error' => 'Nur SELECT-Abfragen sind erlaubt'];
    }
    // Gefährliche Keywords blockieren
    $forbidden = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'GRANT', 'EXECUTE', 'COPY'];
    foreach ($forbidden as $kw) {
        if (preg_match('/\b' . $kw . '\b/i', $sql)) {
            return ['error' => $kw . ' ist nicht erlaubt — nur SELECT'];
        }
    }

    // LIMIT erzwingen wenn nicht vorhanden
    if (!preg_match('/\bLIMIT\b/i', $sql)) {
        $sql = rtrim($sql, '; ') . ' LIMIT 20';
    }

    try {
        $results = $db->getAll($sql, []);
        return ['count' => count($results ?: []), 'results' => $results ?: []];
    } catch (Exception $e) {
        return ['error' => 'SQL-Fehler: ' . $e->getMessage(), 'sql' => $sql];
    }
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

    // WhatsApp-Config direkt laden statt die API-Funktion aufzurufen
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
        // In DB speichern
        $responseData = json_decode($response, true);
        $waMessageId = $responseData['messages'][0]['id'] ?? null;
        if ($waMessageId) {
            $db->execute(
                "INSERT INTO whatsapp_messages (wa_message_id, direction, phone_number, message_type, message_text, status)
                 VALUES (:wid, 'O', :phone, 'text', :msg, 'sent') ON CONFLICT (wa_message_id) DO NOTHING",
                [':wid' => $waMessageId, ':phone' => $phone, ':msg' => $message]
            );
        }
        _logWeroniAction($db, 'whatsapp_sent', 'WhatsApp an ' . $phone . ': ' . mb_substr($message, 0, 50), $input, null);
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
