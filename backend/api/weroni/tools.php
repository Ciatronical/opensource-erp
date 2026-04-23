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
            'name' => 'query_database',
            'description' => 'Führt eine SELECT-Abfrage auf der PostgreSQL-Datenbank aus. Du hast vollen Lesezugriff. Die Erweiterung pg_trgm ist aktiv — nutze word_similarity() für Fuzzy-Suche (z.B. word_similarity(\'Jenny\', name) findet auch Jennifer). Verwende ILIKE für einfache Teilsuchen. Kombiniere mehrere Abfragen wenn nötig. Wenn du nichts findest, versuche es mit lockereren Bedingungen, anderen Schreibweisen oder word_similarity(). LIMIT immer angeben.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'sql' => ['type' => 'string', 'description' => 'SELECT-Abfrage'],
                    'purpose' => ['type' => 'string', 'description' => 'Kurz: Was willst du damit herausfinden?']
                ],
                'required' => ['sql']
            ]
        ],
        [
            'name' => 'store_document',
            'description' => 'Speichert ein analysiertes Dokument im Kunden- oder Lieferantenordner und aktualisiert den Dokumenten-Datensatz.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'document_id' => ['type' => 'integer', 'description' => 'ID aus weroni_documents'],
                    'customer_id' => ['type' => 'integer', 'description' => 'Kunden-ID (oder vendor_id)'],
                    'vendor_id' => ['type' => 'integer', 'description' => 'Lieferanten-ID'],
                    'order_id' => ['type' => 'integer', 'description' => 'Auftrags-ID (optional)'],
                    'folder' => ['type' => 'string', 'description' => 'Unterordner (z.B. "rechnungen", "lieferscheine"). Leer = Hauptordner'],
                    'filename' => ['type' => 'string', 'description' => 'Dateiname für die Ablage (z.B. "2026-04-05_Rechnung_ATU_234.50.pdf")'],
                    'doc_type' => ['type' => 'string', 'enum' => ['invoice', 'delivery_note', 'letter', 'receipt', 'contract', 'other']],
                    'summary' => ['type' => 'string', 'description' => 'Kurze Zusammenfassung des Dokuments']
                ],
                'required' => ['document_id']
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
        case 'query_database':        return _toolQueryDatabase($toolInput, $db);
        case 'store_document':        return _toolStoreDocument($toolInput, $db);
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
 * Führt eine sichere SQL SELECT-Abfrage aus.
 * Weroni hat vollen Lesezugriff und schreibt ihre eigenen Queries.
 */
function _toolQueryDatabase($input, $db) {
    $sql = trim($input['sql'] ?? '');

    // Sicherheitscheck: NUR SELECT erlaubt
    $sqlUpper = strtoupper(ltrim($sql));
    if (!str_starts_with($sqlUpper, 'SELECT') && !str_starts_with($sqlUpper, 'WITH')) {
        return ['error' => 'Nur SELECT/WITH-Abfragen sind erlaubt'];
    }
    $forbidden = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'GRANT', 'EXECUTE', 'COPY'];
    foreach ($forbidden as $kw) {
        if (preg_match('/\b' . $kw . '\b/i', $sql)) {
            return ['error' => $kw . ' ist nicht erlaubt'];
        }
    }

    // LIMIT erzwingen
    if (!preg_match('/\bLIMIT\b/i', $sql)) {
        $sql = rtrim($sql, '; ') . ' LIMIT 25';
    }

    try {
        $results = $db->getAll($sql, []);
        return ['count' => count($results ?: []), 'results' => $results ?: []];
    } catch (Exception $e) {
        return ['error' => 'SQL-Fehler: ' . $e->getMessage(), 'hint' => 'Prüfe Tabellenname und Spalten. Versuche es mit einer einfacheren Query.'];
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

    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('whatsapp_access_token', 'whatsapp_phone_number_id', 'whatsapp_country_code')"
    );

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    if (empty($accessToken) || empty($phoneNumberId)) {
        return ['error' => 'WhatsApp Business API ist nicht konfiguriert. Bitte in den CRM-Einstellungen konfigurieren.'];
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

    // Prüfen ob 24h-Fenster offen ist (Kunde hat uns in den letzten 24h geschrieben)
    $recentIncoming = $db->getOne(
        "SELECT id FROM whatsapp_messages
         WHERE phone_number LIKE :phone AND direction = 'I' AND itime > NOW() - INTERVAL '24 hours'
         LIMIT 1",
        [':phone' => '%' . substr($phonePlain, -8) . '%']
    );

    if ($recentIncoming) {
        // 24h-Fenster offen → Freitext senden
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $phonePlain,
            'type' => 'text',
            'text' => ['body' => $message]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Kein 24h-Fenster → Template nötig. Versuche ein allgemeines "chat" Template zu finden
        $template = $db->getOne(
            "SELECT name, language FROM whatsapp_templates WHERE status = 'approved' AND template_type = 'chat' LIMIT 1",
            []
        );
        if (!$template) {
            // Fallback: irgendein allgemeines Template
            $template = $db->getOne(
                "SELECT name, language FROM whatsapp_templates WHERE status = 'approved' AND template_type = 'general' LIMIT 1",
                []
            );
        }
        if (!$template) {
            return [
                'error' => 'Kein 24h-Fenster offen (Kunde hat uns nicht kürzlich geschrieben) und kein genehmigtes WhatsApp-Template vorhanden.',
                'hint' => 'WhatsApp Business API erlaubt Freitext-Nachrichten nur innerhalb von 24 Stunden nachdem der Kunde uns geschrieben hat. Außerhalb dieses Fensters muss ein von Meta genehmigtes Template verwendet werden. Bitte ein Template vom Typ "chat" in den WhatsApp-Einstellungen anlegen und bei Meta genehmigen lassen.',
                'phone' => $phone,
                'message_not_sent' => $message
            ];
        }

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $phonePlain,
            'type' => 'template',
            'template' => [
                'name' => $template['name'],
                'language' => ['code' => $template['language'] ?? 'de'],
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $message]]]
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

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
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        _logWeroniAction($db, 'whatsapp_sent', 'WhatsApp cURL-Fehler', $input, null, 'failed', $curlError);
        return ['error' => 'Verbindungsfehler: ' . $curlError];
    }

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

    // Fehler mit Details zurückgeben
    $responseData = json_decode($response, true);
    $metaError = $responseData['error']['message'] ?? $response;
    $metaCode = $responseData['error']['code'] ?? $httpCode;

    $errorDetail = "HTTP {$httpCode}, Meta-Code: {$metaCode}: {$metaError}";
    _logWeroniAction($db, 'whatsapp_sent', 'WhatsApp-Fehler an ' . $phone, $input, $responseData, 'failed', $errorDetail);

    // Hilfreiche Fehlermeldung je nach Fehlercode
    $hint = '';
    if ($metaCode == 131030) {
        $hint = 'Die Telefonnummer ist nicht bei WhatsApp registriert.';
    } elseif ($metaCode == 131047 || $metaCode == 131026) {
        $hint = '24h-Fenster abgelaufen. Ein genehmigtes Template wird benötigt.';
    } elseif ($metaCode == 190) {
        $hint = 'WhatsApp Access Token ist ungültig oder abgelaufen. Bitte in den CRM-Einstellungen erneuern.';
    }

    return ['error' => $errorDetail, 'hint' => $hint, 'phone_used' => $phone];
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
/**
 * Speichert ein analysiertes Dokument im Kunden-/Lieferantenordner.
 */
function _toolStoreDocument($input, $db) {
    $docId = intval($input['document_id'] ?? 0);
    if (!$docId) return ['error' => 'document_id erforderlich'];

    // Dokument laden
    $doc = $db->getOne(
        "SELECT id, original_name, mime_type, file_path FROM weroni_documents WHERE id = :id",
        [':id' => $docId]
    );
    if (!$doc) return ['error' => 'Dokument nicht gefunden'];

    // Temporäre Datei prüfen
    $dataDir = fmDataDir();
    $tmpPath = $dataDir . '/weroni_inbox/' . $docId . '_' . $doc['original_name'];
    if (!file_exists($tmpPath)) {
        return ['error' => 'Temporäre Datei nicht gefunden: ' . $tmpPath];
    }

    // Zielordner bestimmen
    $customerId = intval($input['customer_id'] ?? 0);
    $vendorId = intval($input['vendor_id'] ?? 0);

    if ($customerId) {
        $targetDir = $dataDir . '/customers/' . $customerId;
    } elseif ($vendorId) {
        $targetDir = $dataDir . '/vendors/' . $vendorId;
    } else {
        return ['error' => 'customer_id oder vendor_id erforderlich'];
    }

    // Unterordner
    $folder = trim($input['folder'] ?? '');
    if (!empty($folder)) {
        $folder = preg_replace('/[\/\\\\:*?"<>|\x00]/', '_', $folder);
        $targetDir .= '/' . $folder;
    }

    // Ordner erstellen
    if (!is_dir($targetDir)) {
        fmMkdir($targetDir);
    }

    // Dateiname
    $filename = $input['filename'] ?? $doc['original_name'];
    $filename = preg_replace('/[\/\\\\:*?"<>|\x00]/', '_', $filename);
    $targetPath = $targetDir . '/' . $filename;

    // Datei-Kollision vermeiden
    if (file_exists($targetPath)) {
        $pathInfo = pathinfo($filename);
        $filename = $pathInfo['filename'] . '_' . date('His') . '.' . ($pathInfo['extension'] ?? 'pdf');
        $targetPath = $targetDir . '/' . $filename;
    }

    // Verschieben
    if (!rename($tmpPath, $targetPath)) {
        return ['error' => 'Datei konnte nicht verschoben werden'];
    }

    // DB aktualisieren
    $relPath = str_replace($dataDir . '/', '', $targetPath);
    $db->execute(
        "UPDATE weroni_documents SET
            file_path = :path, status = 'filed', filed_at = NOW(),
            customer_id = :cid, vendor_id = :vid, order_id = :oid,
            doc_type = :dtype, summary = :summary
         WHERE id = :id",
        [
            ':path' => $relPath,
            ':cid' => $customerId ?: null,
            ':vid' => $vendorId ?: null,
            ':oid' => intval($input['order_id'] ?? 0) ?: null,
            ':dtype' => $input['doc_type'] ?? 'other',
            ':summary' => $input['summary'] ?? null,
            ':id' => $docId
        ]
    );

    _logWeroniAction($db, 'document_filed', 'Dokument abgelegt: ' . $filename . ' → ' . $relPath, $input, null);
    return ['success' => true, 'message' => 'Dokument abgelegt als: ' . $relPath, 'filename' => $filename];
}

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
