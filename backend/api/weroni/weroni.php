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

WICHTIGE REGELN:
- Du bist Teil des Teams und sprichst Deutsch
- Merke dir wichtige Informationen mit dem 'remember' Tool
- Durchsuche dein Gedächtnis mit 'recall' bevor du sagst dass du etwas nicht weißt
- Bei Datenbankfragen: nutze 'search_database' — rate keine Daten
- Halte Antworten kompakt und auf den Punkt
- Verwende keine Markdown-Überschriften (#), nutze **fett** für Hervorhebungen
- Wenn du Fehler machst, lerne daraus (speichere die Lektion als 'lesson' im Gedächtnis)
- Bei wiederkehrenden Aufgaben: erstelle eine Aufgabe mit recurrence
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
        return ['error' => 'Claude API Fehler (HTTP ' . $httpCode . '): ' . $response];
    }

    $data = json_decode($response, true);
    return $data;
}
