<?php
// backend/api/weroni/weroni.php

/**
 * Weroni — KI-Bürokauffrau (Hauptlogik)
 * Verwendet Claude mit Tool Use für autonomes Handeln.
 */

require_once __DIR__.'/tools.php';

/**
 * Sendet eine Nachricht an Weroni und bekommt eine Antwort.
 * Weroni kann dabei Tools aufrufen (DB-Suche, Email senden, etc.)
 *
 * @param string $data['message']    Benutzernachricht
 * @param string $data['session_id'] Session-ID für Konversationskontext
 * @testdata {"message": "Hast du neue Emails?", "session_id": "test-123"}
 */
function weroniChat($data) {
    set_time_limit(120);

    $db = DbhCompany::begin();
    $message = trim($data['message'] ?? '');
    $sessionId = $data['session_id'] ?? ('session_' . time());

    if (empty($message)) {
        throw new ApiError('VALIDATION_ERROR', 'message erforderlich');
    }

    // Konfiguration laden
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', 'weroni_enabled', 'weroni_mode', 'weroni_system_prompt')"
    );

    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert');
    }

    $weroniMode = $config['weroni_mode'] ?? 'assistant';
    $systemPromptConfig = trim($config['weroni_system_prompt'] ?? '');
    if (empty($systemPromptConfig)) {
        $systemPromptConfig = 'Du bist Weroni, die KI-Bürokauffrau.';
    }

    // Erinnerungen laden die relevant sein könnten
    $memories = $db->getAll(
        "SELECT category, subject, content FROM weroni_memory ORDER BY importance DESC, updated_at DESC LIMIT 20",
        []
    );
    $memoryText = '';
    if (!empty($memories)) {
        $lines = [];
        foreach ($memories as $m) {
            $lines[] = "[{$m['category']}] {$m['subject']}: {$m['content']}";
        }
        $memoryText = "\n\nDEIN GEDÄCHTNIS (gespeicherte Informationen):\n" . implode("\n", $lines);
    }

    // Offene Aufgaben laden
    $tasks = $db->getAll(
        "SELECT t.id, t.title, t.status, t.priority, t.assigned_to,
                TO_CHAR(t.due_date, 'DD.MM.YYYY') AS due_date
         FROM weroni_tasks t
         WHERE t.status NOT IN ('done', 'cancelled')
         ORDER BY t.priority DESC, t.due_date ASC NULLS LAST
         LIMIT 10",
        []
    );
    $tasksText = '';
    if (!empty($tasks)) {
        $lines = [];
        foreach ($tasks as $t) {
            $line = "- [{$t['status']}] {$t['title']}";
            if ($t['due_date']) $line .= " (fällig: {$t['due_date']})";
            if ($t['assigned_to']) $line .= " → {$t['assigned_to']}";
            $lines[] = $line;
        }
        $tasksText = "\n\nDEINE OFFENEN AUFGABEN:\n" . implode("\n", $lines);
    }

    // Offene Rückfragen
    $pendingQuestions = $db->getAll(
        "SELECT id, question, answer FROM weroni_questions WHERE status = 'answered' AND created_at > NOW() - INTERVAL '1 day' ORDER BY answered_at DESC LIMIT 5",
        []
    );
    $answersText = '';
    if (!empty($pendingQuestions)) {
        $lines = [];
        foreach ($pendingQuestions as $q) {
            $lines[] = "Frage: {$q['question']} → Antwort: {$q['answer']}";
        }
        $answersText = "\n\nKÜRZLICH BEANTWORTETE RÜCKFRAGEN:\n" . implode("\n", $lines);
    }

    // Aktuelles Datum/Uhrzeit und Modus
    $now = date('d.m.Y H:i');
    $dayNames = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $dayName = $dayNames[date('w')];

    $modeInstruction = $weroniMode === 'autonomous'
        ? "Du arbeitest im AUTONOMEN Modus: Handle selbstständig und erledige Aufgaben direkt. Stelle nur Rückfragen (ask_user) wenn du wirklich unsicher bist."
        : "Du arbeitest im ASSISTENTEN-Modus: Schlage Aktionen vor und erkläre was du tun würdest. Führe Aktionen nur aus wenn der Benutzer es bestätigt oder explizit darum bittet.";

    $systemPrompt = <<<PROMPT
{$systemPromptConfig}

AKTUELL: {$dayName}, {$now}
MODUS: {$modeInstruction}
{$memoryText}
{$tasksText}
{$answersText}

DATENBANK-SCHEMA (PostgreSQL mit pg_trgm-Erweiterung):

Kunden/Lieferanten:
  customer (id, name, greeting, street, zipcode, city, phone, phone3, email, notes)
  customer_ext (customer_id, phone_numbers JSONB [{label,number}], emails JSONB)
  vendor (id, name, street, zipcode, city, phone, email, notes)

Fahrzeuge (LxCars):
  cars_lxcars (c_id, c_ow→customer.id, c_ln=Kennzeichen, c_2=HSN, c_3=TSN, c_d=Erstzulassung, c_hu=TÜV, c_fin=FIN, c_m=Marke, c_mt=Modell, c_mkb=Motorkennbuchstabe, c_color, c_gart=Getriebeart, c_text=Notizen, c_sk=Steuerkette, c_zrk=Zahnriemen-km, c_zrd=Zahnriemen-Datum, kba_id)
  kba_lxcars (id, hsn, tsn, hersteller, marke, name=Modellname, kraftstoff, leistung, hubraum, aufbau, d3=Handelsname)

Aufträge/Rechnungen:
  oe (id, ordnumber, record_type=sales_order|sales_quotation, customer_id, transdate, amount, notes)
  oe_ext (oe_id, c_id→Fahrzeug, km_stand, status, bringetermin, fertigstellung, kfz_ort)
  orderitems (trans_id→oe.id, parts_id, description, qty, sellprice, position)
  oe_instructions_lxcars (id, oe_id, description, done, planned_minutes, actual_minutes, employee_id)
  ar (id, invnumber, customer_id, transdate, amount)
  invoice (trans_id→ar.id, parts_id, description, qty, sellprice)
  parts (id, partnumber, description, sellprice, unit, part_type)

Kommunikation:
  whatsapp_messages (id, wa_message_id, direction=I|O, phone_number, customer_id, contact_name, message_type, message_text, status, itime, hidden)
  crmti (crmti_id, crmti_src, crmti_dst, crmti_number, crmti_caller_id, crmti_caller_typ=C|V|X, crmti_direction=E|A, crmti_init_time, crmti_end_time, unique_call_id)
  email_journal (id, "from", recipients, subject, body, sent_on, status=sent|send_failed|imported)

Kalender/Aufgaben:
  calendar_events (id, title, description, dtstart, dtend, "allDay", location, uid→employee.id, cvp_id, cvp_name, cvp_type)
  employee (id, name, login, email)

Weroni-eigene Tabellen:
  weroni_memory (id, category, subject, content, importance, source, related_id, related_type)
  weroni_tasks (id, parent_id, title, description, status, priority, due_date, assigned_to, recurrence, tags[])
  weroni_actions (id, action_type, description, input_data, output_data, status, error_message)
  weroni_questions (id, question, context, urgency, status, answer)

FAHRZEUG-DATEN — WICHTIG:
- HERSTELLER (VW, Ford, Opel, BMW, Audi, Mercedes) → kba_lxcars.hersteller oder .marke
- MODELL (Golf, Focus, Corsa, 3er, A4, C-Klasse) → kba_lxcars.name oder .d3 (Handelsname)
- Suche Modellnamen IMMER in name UND d3: (k.name ILIKE '%Golf%' OR k.d3 ILIKE '%Golf%')
- cars_lxcars.c_m ist nur ein Kürzel (z.B. "VW"), cars_lxcars.c_mt ist Freitext-Modell

SUCH-STRATEGIEN (sehr wichtig!):
- Nutze word_similarity(suchbegriff, feldwert) für Fuzzy-Suche bei Namen/Orten.
- ILIKE reicht für exakte Teilstrings (Kennzeichen, Modellnamen, Städte).
- GIB NIEMALS AUF nach einer leeren Suche — versuche es anders!
- Wenn 0 Treffer: lockere Bedingungen, entferne ein Kriterium, nutze word_similarity
- Verknüpfe Tabellen: customer → cars_lxcars (c_ow) → kba_lxcars (kba_id)
- Nutze LEFT JOIN wenn Daten optional sind

BEISPIEL-QUERIES:
-- Kunde mit Name + Ort + Automodell:
SELECT c.id, c.name, c.city, k.hersteller, k.name AS modell, car.c_ln
FROM customer c
JOIN cars_lxcars car ON car.c_ow = c.id
LEFT JOIN kba_lxcars k ON k.id = car.kba_id
WHERE word_similarity('Ronny', c.name) > 0.3
  AND c.city ILIKE '%Rehfelde%'
  AND (k.name ILIKE '%Golf%' OR k.d3 ILIKE '%Golf%')
ORDER BY word_similarity('Ronny', c.name) DESC LIMIT 10

-- Alle Aufträge eines Kunden mit Fahrzeug und Positionen:
SELECT o.ordnumber, o.transdate, car.c_ln, string_agg(oi.description, ', ')
FROM oe o
JOIN oe_ext e ON e.oe_id = o.id
JOIN cars_lxcars car ON car.c_id = e.c_id
JOIN orderitems oi ON oi.trans_id = o.id
WHERE o.customer_id = 123
GROUP BY o.id, o.ordnumber, o.transdate, car.c_ln
ORDER BY o.transdate DESC LIMIT 10

REGELN:
- Du bist Teil des Teams und sprichst Deutsch
- Halte Antworten kompakt und auf den Punkt
- Verwende keine Markdown-Überschriften (#), nutze **fett** für Hervorhebungen
- Verwende keine Emojis
- Sage NIEMALS "nicht gefunden" ohne mindestens 2-3 verschiedene Suchstrategien versucht zu haben

LERNEN UND MERKEN (sehr wichtig!):
- Du hast ein Langzeitgedächtnis (remember/recall). NUTZE ES AKTIV:
  * Wenn du etwas Neues über eine Person erfährst → remember(category:'person', subject:'Ronny', content:'geht donnerstags zur Physiotherapie')
  * Wenn ein Fehler passiert → remember(category:'lesson', subject:'WhatsApp Templates', content:'Ohne 24h-Fenster braucht man ein genehmigtes Template')
  * Wenn du eine Vorliebe bemerkst → remember(category:'preference', subject:'Frühstück', content:'Mittwochs Frühstück, Freitags Mittagessen bestellen')
  * Wenn du einen Prozess lernst → remember(category:'process', subject:'GmbH gründen', content:'Schritte: 1. Notar, 2. Handelsregister, ...')
- BEVOR du eine Frage beantwortest: prüfe mit recall ob du dazu schon etwas weißt
- Nach jeder erfolgreichen Interaktion: überlege ob etwas merkenswert ist
- Du wirst mit der Zeit immer klüger weil du aus jeder Interaktion lernst

WHATSAPP WICHTIG:
- WhatsApp Business API erlaubt Freitext nur im 24h-Fenster (Kunde hat uns kürzlich geschrieben)
- Außerhalb: Es wird automatisch ein genehmigtes Template verwendet wenn vorhanden
- Wenn kein Template vorhanden: erkläre dem Benutzer was nötig ist
- Telefonnummern immer im Format +49... verwenden
PROMPT;

    // Konversationsverlauf laden (nur user/assistant Textnachrichten, keine Tool-Calls)
    $history = $db->getAll(
        "SELECT role, content FROM weroni_conversations
         WHERE session_id = :sid AND role IN ('user', 'assistant')
         ORDER BY id DESC LIMIT 20",
        [':sid' => $sessionId]
    );
    $history = array_reverse($history ?: []);

    // Claude-Nachrichten aufbauen
    $messages = [];
    foreach ($history as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // User-Nachricht in DB speichern
    $db->execute(
        "INSERT INTO weroni_conversations (session_id, role, content, employee_id)
         VALUES (:sid, 'user', :content, :eid)",
        [':sid' => $sessionId, ':content' => $message, ':eid' => intval($_SESSION['employee_id'] ?? 0)]
    );

    // Claude API aufrufen mit Tool Use (Agent Loop)
    $toolDefinitions = getWeroniToolDefinitions();
    $maxIterations = 8;
    $allToolResults = [];

    for ($i = 0; $i < $maxIterations; $i++) {
        $response = _callClaudeWithTools($anthropicKey, $systemPrompt, $messages, $toolDefinitions);

        if (isset($response['error'])) {
            throw new ApiError('CLAUDE_API_ERROR', $response['error']);
        }

        $content = $response['content'] ?? [];
        $stopReason = $response['stop_reason'] ?? 'end_turn';

        // Prüfen ob Tool-Aufrufe vorhanden sind
        $toolUseBlocks = array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_use');

        if (empty($toolUseBlocks) || $stopReason === 'end_turn') {
            // Fertig — Textantwort extrahieren
            $textBlocks = array_filter($content, fn($b) => ($b['type'] ?? '') === 'text');
            $finalText = implode("\n", array_map(fn($b) => $b['text'], $textBlocks));

            // Antwort in DB speichern
            $db->execute(
                "INSERT INTO weroni_conversations (session_id, role, content, tool_calls, employee_id)
                 VALUES (:sid, 'assistant', :content, :tools, :eid)",
                [
                    ':sid' => $sessionId,
                    ':content' => $finalText,
                    ':tools' => !empty($allToolResults) ? json_encode($allToolResults, JSON_UNESCAPED_UNICODE) : null,
                    ':eid' => intval($_SESSION['employee_id'] ?? 0)
                ]
            );

            resultInfo(true, 'OK', [
                'message' => $finalText,
                'tools_used' => $allToolResults,
                'session_id' => $sessionId
            ]);
            return;
        }

        // Tools ausführen
        $messages[] = ['role' => 'assistant', 'content' => $content];

        $toolResults = [];
        foreach ($toolUseBlocks as $toolUse) {
            $toolName = $toolUse['name'];
            $toolInput = $toolUse['input'] ?? [];
            $toolId = $toolUse['id'];

            $result = executeWeroniTool($toolName, $toolInput, $db);
            $allToolResults[] = ['tool' => $toolName, 'input' => $toolInput, 'result' => $result];

            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolId,
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE)
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $toolResults];
    }

    throw new ApiError('CLAUDE_API_ERROR', 'Maximale Tool-Iterationen erreicht');
}

/**
 * Lädt den Konversationsverlauf einer Session.
 *
 * @param string $data['session_id'] Session-ID
 * @testdata {"session_id": "test-123"}
 */
function getWeroniConversation($data) {
    $db = DbhCompany::begin();
    $sessionId = $data['session_id'] ?? '';

    $messages = $db->getAll(
        "SELECT id, role, content, TO_CHAR(created_at, 'DD.MM.YYYY HH24:MI') AS created_at
         FROM weroni_conversations
         WHERE session_id = :sid AND role IN ('user', 'assistant')
         ORDER BY id ASC",
        [':sid' => $sessionId]
    );

    resultInfo(true, 'OK', $messages ?: []);
}

/**
 * Lädt Weronis offene Aufgaben.
 *
 * @param bool $data['include_done'] Auch erledigte anzeigen
 * @testdata {"include_done": false}
 */
function getWeroniTasks($data) {
    $db = DbhCompany::begin();
    $includeDone = !empty($data['include_done']);

    $where = $includeDone ? "1=1" : "t.status NOT IN ('done', 'cancelled')";

    $tasks = $db->getAll(
        "SELECT t.id, t.title, t.description, t.status, t.priority, t.assigned_to,
                t.parent_id, t.tags, t.recurrence,
                TO_CHAR(t.due_date, 'DD.MM.YYYY HH24:MI') AS due_date,
                TO_CHAR(t.created_at, 'DD.MM.YYYY') AS created_at,
                TO_CHAR(t.completed_at, 'DD.MM.YYYY') AS completed_at,
                (SELECT COUNT(*) FROM weroni_tasks sub WHERE sub.parent_id = t.id) AS subtask_count,
                (SELECT COUNT(*) FROM weroni_tasks sub WHERE sub.parent_id = t.id AND sub.status = 'done') AS subtask_done
         FROM weroni_tasks t
         WHERE {$where}
         ORDER BY t.parent_id NULLS FIRST, t.priority DESC, t.due_date ASC NULLS LAST",
        []
    );

    resultInfo(true, 'OK', $tasks ?: []);
}

/**
 * Lädt Weronis offene Rückfragen.
 *
 * @testdata {}
 */
function getWeroniQuestions($data) {
    $db = DbhCompany::begin();

    $questions = $db->getAll(
        "SELECT id, question, context, urgency, status,
                TO_CHAR(created_at, 'DD.MM.YYYY HH24:MI') AS created_at
         FROM weroni_questions
         WHERE status = 'pending'
         ORDER BY urgency DESC, created_at ASC",
        []
    );

    resultInfo(true, 'OK', $questions ?: []);
}

/**
 * Beantwortet eine Rückfrage von Weroni.
 *
 * @param int    $data['question_id'] Frage-ID
 * @param string $data['answer']      Antwort
 * @testdata {"question_id": 1, "answer": "Ja, mach das so"}
 */
function answerWeroniQuestion($data) {
    $db = DbhCompany::begin();
    $questionId = intval($data['question_id'] ?? 0);
    $answer = trim($data['answer'] ?? '');

    if (!$questionId || empty($answer)) {
        throw new ApiError('VALIDATION_ERROR', 'question_id und answer erforderlich');
    }

    $db->execute(
        "UPDATE weroni_questions SET status = 'answered', answer = :answer,
                answered_by = :eid, answered_at = NOW()
         WHERE id = :id",
        [':answer' => $answer, ':eid' => intval($_SESSION['employee_id'] ?? 0), ':id' => $questionId]
    );

    resultInfo(true, 'OK', []);
}

/**
 * Gibt die Anzahl der offenen Rückfragen zurück (für Navbar-Badge).
 *
 * @testdata {}
 */
function getWeroniPendingCount($data) {
    $db = DbhCompany::begin();

    $row = $db->getOne(
        "SELECT COUNT(*) AS cnt FROM weroni_questions WHERE status = 'pending'",
        []
    );

    resultInfo(true, 'OK', ['count' => intval($row['cnt'] ?? 0)]);
}

/**
 * Lädt Weronis Aktionsprotokoll.
 *
 * @param int $data['limit'] Anzahl (Standard: 20)
 * @testdata {"limit": 20}
 */
function getWeroniActions($data) {
    $db = DbhCompany::begin();
    $limit = intval($data['limit'] ?? 20);

    $actions = $db->getAll(
        "SELECT id, action_type, description, status, error_message, lesson_learned,
                TO_CHAR(created_at, 'DD.MM.YYYY HH24:MI') AS created_at
         FROM weroni_actions
         ORDER BY created_at DESC
         LIMIT :limit",
        [':limit' => $limit]
    );

    resultInfo(true, 'OK', $actions ?: []);
}

/**
 * Löscht den Konversationsverlauf einer Session.
 *
 * @param string $data['session_id'] Session-ID
 * @testdata {"session_id": "test-123"}
 */
function clearWeroniConversation($data) {
    $db = DbhCompany::begin();
    $sessionId = $data['session_id'] ?? '';

    $db->execute(
        "DELETE FROM weroni_conversations WHERE session_id = :sid",
        [':sid' => $sessionId]
    );

    resultInfo(true, 'OK', []);
}

/**
 * Bestätigt eine Aufgabe im Assistenten-Modus (pending_confirm → open).
 * Falls auto_action gesetzt, wird die Aktion ausgeführt.
 *
 * @param int $data['task_id'] Aufgaben-ID
 * @testdata {"task_id": 1}
 */
function confirmWeroniTask($data) {
    $db = DbhCompany::begin();
    $taskId = intval($data['task_id'] ?? 0);
    if (!$taskId) throw new ApiError('VALIDATION_ERROR', 'task_id erforderlich');

    $task = $db->getOne(
        "SELECT id, title, status, auto_action FROM weroni_tasks WHERE id = :id",
        [':id' => $taskId]
    );
    if (!$task) throw new ApiError('DATA_NOT_FOUND', 'Aufgabe nicht gefunden');
    if ($task['status'] !== 'pending_confirm') throw new ApiError('VALIDATION_ERROR', 'Aufgabe ist nicht im Bestätigungsstatus');

    $db->execute(
        "UPDATE weroni_tasks SET status = 'open', updated_at = NOW() WHERE id = :id",
        [':id' => $taskId]
    );

    // Zugehörige Rückfrage als beantwortet markieren
    $db->execute(
        "UPDATE weroni_questions SET status = 'answered', answer = 'Bestätigt', answered_at = NOW()
         WHERE status = 'pending' AND question LIKE :q",
        [':q' => '%' . $task['title'] . '%']
    );

    resultInfo(true, 'OK', ['task_id' => $taskId, 'new_status' => 'open']);
}

/**
 * Lehnt eine Aufgabe im Assistenten-Modus ab (pending_confirm → cancelled).
 *
 * @param int    $data['task_id'] Aufgaben-ID
 * @param string $data['reason']  Grund (optional)
 * @testdata {"task_id": 1, "reason": "Nicht relevant"}
 */
function rejectWeroniTask($data) {
    $db = DbhCompany::begin();
    $taskId = intval($data['task_id'] ?? 0);
    if (!$taskId) throw new ApiError('VALIDATION_ERROR', 'task_id erforderlich');

    $task = $db->getOne(
        "SELECT id, title FROM weroni_tasks WHERE id = :id",
        [':id' => $taskId]
    );
    if (!$task) throw new ApiError('DATA_NOT_FOUND', 'Aufgabe nicht gefunden');

    $db->execute(
        "UPDATE weroni_tasks SET status = 'cancelled', updated_at = NOW() WHERE id = :id",
        [':id' => $taskId]
    );

    // Zugehörige Rückfrage als abgelehnt markieren
    $db->execute(
        "UPDATE weroni_questions SET status = 'dismissed', answer = :reason, answered_at = NOW()
         WHERE status = 'pending' AND question LIKE :q",
        [':q' => '%' . $task['title'] . '%', ':reason' => $data['reason'] ?? 'Abgelehnt']
    );

    resultInfo(true, 'OK', ['task_id' => $taskId, 'new_status' => 'cancelled']);
}

/**
 * Empfängt ein Dokument (Bild/PDF), analysiert es mit Claude Vision und leitet
 * das Ergebnis an den normalen weroniChat weiter, damit Weroni entscheiden kann
 * wo es abgelegt wird.
 *
 * @param string $data['file_base64']  Base64-kodierter Dateiinhalt
 * @param string $data['filename']     Original-Dateiname
 * @param string $data['mime_type']    MIME-Typ (image/jpeg, image/png, application/pdf)
 * @param string $data['session_id']   Chat-Session
 * @param string $data['message']      Optionale Zusatznachricht vom Benutzer
 * @testdata {"filename": "rechnung.pdf", "mime_type": "application/pdf", "file_base64": "", "session_id": "test"}
 */
function weroniAnalyzeDocument($data) {
    set_time_limit(120);

    $db = DbhCompany::begin();

    $fileBase64 = $data['file_base64'] ?? '';
    $filename = $data['filename'] ?? 'dokument';
    $mimeType = $data['mime_type'] ?? 'image/jpeg';
    $sessionId = $data['session_id'] ?? ('session_' . time());
    $userMessage = trim($data['message'] ?? '');

    if (empty($fileBase64)) {
        throw new ApiError('VALIDATION_ERROR', 'file_base64 erforderlich');
    }

    // API-Key laden
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key = 'anthropic_api_key'"
    );
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key nicht konfiguriert');
    }

    // Datei temporär speichern
    $inboxDir = fmDataDir() . '/weroni_inbox';
    if (!is_dir($inboxDir)) {
        fmMkdir($inboxDir);
    }

    $fileContent = base64_decode($fileBase64, true);
    if ($fileContent === false) {
        throw new ApiError('VALIDATION_ERROR', 'Ungültige Base64-Daten');
    }

    // In DB erfassen
    $db->execute(
        "INSERT INTO weroni_documents (original_name, mime_type, status)
         VALUES (:name, :mime, 'pending')",
        [':name' => $filename, ':mime' => $mimeType]
    );
    $doc = $db->getOne(
        "SELECT id FROM weroni_documents WHERE original_name = :name ORDER BY id DESC LIMIT 1",
        [':name' => $filename]
    );
    $docId = $doc['id'];

    // Temporär speichern
    $tmpFilename = $docId . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '_', $filename);
    file_put_contents($inboxDir . '/' . $tmpFilename, $fileContent);

    // Claude Vision aufrufen — Dokument analysieren
    $visionPrompt = "Analysiere dieses Dokument und extrahiere alle relevanten Informationen.\n\n"
        . "Bestimme:\n"
        . "1. **Dokumenttyp**: Rechnung, Lieferschein, Brief, Quittung, Vertrag, Sonstiges\n"
        . "2. **Absender**: Firma/Person die das Dokument erstellt hat\n"
        . "3. **Empfänger**: An wen es gerichtet ist\n"
        . "4. **Datum**: Dokumentdatum\n"
        . "5. **Betrag**: Falls vorhanden (Brutto, Netto, MwSt)\n"
        . "6. **Belegnummer**: Rechnungsnummer, Lieferscheinnummer, etc.\n"
        . "7. **Zusammenfassung**: Worum geht es in 1-2 Sätzen\n"
        . "8. **Einzelpositionen**: Falls Rechnung/Lieferschein — was wurde bestellt/geliefert\n\n"
        . "Antworte auf Deutsch in strukturiertem Fließtext.";

    if (!empty($userMessage)) {
        $visionPrompt .= "\n\nZusätzliche Anmerkung vom Benutzer: " . $userMessage;
    }

    // PDF: als document-Block senden, Bilder als image-Block
    if (str_starts_with($mimeType, 'application/pdf')) {
        $contentBlock = [
            'type' => 'document',
            'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $fileBase64]
        ];
    } else {
        $contentBlock = [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $fileBase64]
        ];
    }

    $requestBody = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 2048,
        'messages' => [[
            'role' => 'user',
            'content' => [$contentBlock, ['type' => 'text', 'text' => $visionPrompt]]
        ]]
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
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new ApiError('CLAUDE_API_ERROR', _weroniClaudeErrorMessage($httpCode, $response));
    }

    $responseData = json_decode($response, true);
    $analysisText = $responseData['content'][0]['text'] ?? '';

    // Analyse in DB speichern
    $db->execute(
        "UPDATE weroni_documents SET status = 'analyzed', summary = :summary, extracted_data = :data WHERE id = :id",
        [':summary' => mb_substr($analysisText, 0, 500), ':data' => json_encode(['full_analysis' => $analysisText], JSON_UNESCAPED_UNICODE), ':id' => $docId]
    );

    // Jetzt den normalen weroniChat aufrufen mit der Analyse als Kontext
    $chatMessage = "Ich habe ein Dokument hochgeladen: **{$filename}**\n\n"
        . "Hier ist die Analyse:\n{$analysisText}\n\n"
        . "Das Dokument hat die ID {$docId} in weroni_documents.\n"
        . "Bitte finde heraus zu welchem Kunden/Lieferanten es gehört und lege es mit store_document im richtigen Ordner ab. "
        . "Schlage einen aussagekräftigen Dateinamen vor (z.B. '2026-04-05_Rechnung_ATU_234.50.pdf').";

    if (!empty($userMessage)) {
        $chatMessage .= "\n\nHinweis vom Benutzer: " . $userMessage;
    }

    // Weiterleiten an weroniChat
    weroniChat([
        'message' => $chatMessage,
        'session_id' => $sessionId
    ]);
}

/**
 * Übersetzt einen HTTP-Fehler der Anthropic-API in eine verständliche
 * deutsche Meldung. Erkennt insbesondere fehlendes Guthaben (Billing) und
 * ungültige API-Keys und ergänzt einen Link zur Bezahlung.
 *
 * @param int    $httpCode HTTP-Statuscode der API-Antwort
 * @param string $response Roher Antwort-Body (JSON)
 * @return string Benutzerfreundliche Fehlermeldung (Markdown)
 */
function _weroniClaudeErrorMessage($httpCode, $response) {
    $billingUrl = 'https://console.anthropic.com/settings/billing';
    $decoded = json_decode($response, true);
    $apiType = $decoded['error']['type'] ?? '';
    $apiMessage = $decoded['error']['message'] ?? '';
    $lowerMessage = strtolower($apiMessage);

    // Guthaben aufgebraucht — häufigste Ursache, dass Weroni "nicht mehr geht"
    if (str_contains($lowerMessage, 'credit balance') || str_contains($lowerMessage, 'billing')) {
        return "Weroni kann gerade nicht antworten, weil das Anthropic-Guthaben aufgebraucht ist. "
            . "Bitte lade in der Anthropic Console unter **Plans & Billing** Guthaben auf bzw. hinterlege eine Zahlungsmethode: "
            . "[console.anthropic.com/settings/billing]({$billingUrl})";
    }

    // Ungültiger oder fehlender API-Key
    if ($httpCode === 401 || $apiType === 'authentication_error') {
        return "Der Anthropic API-Key ist ungültig oder abgelaufen. "
            . "Bitte prüfe den Key in der Anthropic Console und trage ihn in den Einstellungen ein: "
            . "[console.anthropic.com/settings/keys](https://console.anthropic.com/settings/keys)";
    }

    // Ratenlimit erreicht
    if ($httpCode === 429 || $apiType === 'rate_limit_error') {
        return "Das Anfragelimit der Anthropic-API ist erreicht. Bitte versuche es in einer Minute erneut. "
            . "Höhere Limits gibt es in der Anthropic Console unter [Plans & Billing]({$billingUrl}).";
    }

    // Sonstiger Fehler — Originaltext mitliefern
    $detail = $apiMessage !== '' ? $apiMessage : $response;
    return "Claude API Fehler (HTTP {$httpCode}): {$detail}";
}

/**
 * Ruft die Claude API mit Tool Use auf.
 */
function _callClaudeWithTools($apiKey, $systemPrompt, $messages, $tools) {
    $requestBody = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 4096,
        'system' => $systemPrompt,
        'messages' => $messages,
        'tools' => $tools
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
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
        return ['error' => 'cURL-Fehler: ' . $curlError];
    }
    if ($httpCode !== 200) {
        return ['error' => _weroniClaudeErrorMessage($httpCode, $response)];
    }

    $data = json_decode($response, true);
    return $data;
}
