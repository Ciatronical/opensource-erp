#!/usr/bin/env php
<?php
/**
 * Weroni Monitor — Hintergrundprozess
 *
 * Prüft periodisch neue Emails, WhatsApp-Nachrichten und Anrufe.
 * Weroni analysiert diese und erstellt selbstständig Aufgaben.
 *
 * Im Modus "assistant": Aufgaben mit Status "pending_confirm" → Mensch muss bestätigen
 * Im Modus "autonomous": Aufgaben werden direkt ausgeführt
 *
 * Aufruf:
 *   php backend/cli/weroni-monitor.php
 *
 * Cron (alle 5 Minuten):
 *   */5 * * * * cd /home/work/opensource-erp && php backend/cli/weroni-monitor.php >> backend/log/weroni-monitor.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    die("Nur über die Kommandozeile ausführbar.\n");
}

$baseDir = dirname(__DIR__).'/api';

require_once $baseDir.'/config.php';
require_once $baseDir.'/logging.php';
require_once $baseDir.'/database.php';
require_once $baseDir.'/session.php';
require_once $baseDir.'/weroni/tools.php';

function getClients(): array {
    $pdo = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
    $stmt = $pdo->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE is_default = 'f' OR is_default IS NULL ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function initCompanyDb(PDO $pdo): void {
    $reflection = new ReflectionClass('DbhCompany');
    $prop = $reflection->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, new ApiDatabase($pdo));
}

function resetCompanyDb(): void {
    $reflection = new ReflectionClass('DbhCompany');
    $prop = $reflection->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

/**
 * Liest/schreibt den Monitor-State (letzte verarbeitete IDs etc.)
 */
function getMonitorState($db, $key, $default = null) {
    $row = $db->getOne("SELECT value FROM weroni_monitor_state WHERE key = :k", [':k' => $key]);
    return $row ? $row['value'] : $default;
}

function setMonitorState($db, $key, $value) {
    $db->execute(
        "INSERT INTO weroni_monitor_state (key, value, updated_at) VALUES (:k, :v, NOW())
         ON CONFLICT (key) DO UPDATE SET value = :v2, updated_at = NOW()",
        [':k' => $key, ':v' => $value, ':v2' => $value]
    );
}

/**
 * Sammelt neue (unverarbeitete) Kommunikation ein.
 */
function collectNewItems($db) {
    $items = [];

    // Neue WhatsApp-Nachrichten (eingehend, seit letztem Check)
    $lastWaId = intval(getMonitorState($db, 'last_whatsapp_id', '0'));
    $newWa = $db->getAll(
        "SELECT wm.id, wm.phone_number, wm.contact_name, wm.message_text, wm.message_type,
                TO_CHAR(wm.itime, 'DD.MM.YYYY HH24:MI') AS zeit,
                cu.name AS kunde, cu.id AS customer_id
         FROM whatsapp_messages wm
         LEFT JOIN customer cu ON cu.id = wm.customer_id
         WHERE wm.id > :last_id AND wm.direction = 'I' AND wm.hidden = false
           AND wm.message_type IN ('text', 'document', 'image')
         ORDER BY wm.id ASC LIMIT 20",
        [':last_id' => $lastWaId]
    );
    if (!empty($newWa)) {
        foreach ($newWa as $wa) {
            $items[] = [
                'type' => 'whatsapp',
                'summary' => 'WhatsApp von ' . ($wa['kunde'] ?? $wa['contact_name'] ?? $wa['phone_number'])
                    . ' (' . $wa['zeit'] . '): ' . mb_substr($wa['message_text'] ?? '[Medien]', 0, 200),
                'data' => $wa
            ];
        }
        $maxId = max(array_column($newWa, 'id'));
        setMonitorState($db, 'last_whatsapp_id', $maxId);
    }

    // Neue Anrufe (seit letztem Check)
    $lastCallId = intval(getMonitorState($db, 'last_call_id', '0'));
    $newCalls = $db->getAll(
        "SELECT c.crmti_id, c.crmti_direction, c.crmti_number,
                TO_CHAR(c.crmti_init_time, 'DD.MM.YYYY HH24:MI') AS zeit,
                TO_CHAR(c.crmti_end_time, 'DD.MM.YYYY HH24:MI') AS ende,
                EXTRACT(EPOCH FROM (c.crmti_end_time - c.crmti_init_time))::int AS dauer_sek,
                CASE WHEN c.crmti_caller_typ = 'C' THEN cu.name
                     WHEN c.crmti_caller_typ = 'V' THEN ve.name
                     ELSE NULL END AS kontakt,
                c.crmti_caller_id, c.crmti_caller_typ
         FROM crmti c
         LEFT JOIN customer cu ON cu.id = c.crmti_caller_id AND c.crmti_caller_typ = 'C'
         LEFT JOIN vendor ve ON ve.id = c.crmti_caller_id AND c.crmti_caller_typ = 'V'
         WHERE c.crmti_id > :last_id
         ORDER BY c.crmti_id ASC LIMIT 20",
        [':last_id' => $lastCallId]
    );
    if (!empty($newCalls)) {
        foreach ($newCalls as $call) {
            $richtung = $call['crmti_direction'] === 'E' ? 'Eingehender' : 'Ausgehender';
            $dauer = $call['dauer_sek'] ? ' (' . intval($call['dauer_sek'] / 60) . ' Min.)' : '';
            $items[] = [
                'type' => 'call',
                'summary' => $richtung . ' Anruf ' . ($call['kontakt'] ? 'von ' . $call['kontakt'] : $call['crmti_number'])
                    . ' um ' . $call['zeit'] . $dauer,
                'data' => $call
            ];
        }
        $maxId = max(array_column($newCalls, 'crmti_id'));
        setMonitorState($db, 'last_call_id', $maxId);
    }

    // Neue Emails (aus email_journal, eingehend, seit letztem Check)
    $lastEmailId = intval(getMonitorState($db, 'last_email_id', '0'));
    $newEmails = $db->getAll(
        "SELECT ej.id, ej.\"from\", ej.subject, ej.body,
                TO_CHAR(ej.sent_on, 'DD.MM.YYYY HH24:MI') AS zeit
         FROM email_journal ej
         WHERE ej.id > :last_id AND ej.status = 'imported'
         ORDER BY ej.id ASC LIMIT 10",
        [':last_id' => $lastEmailId]
    );
    if (!empty($newEmails)) {
        foreach ($newEmails as $email) {
            $bodyPreview = mb_substr(strip_tags($email['body'] ?? ''), 0, 300);
            $items[] = [
                'type' => 'email',
                'summary' => 'E-Mail von ' . $email['from'] . ' (' . $email['zeit'] . '): '
                    . $email['subject'] . "\n" . $bodyPreview,
                'data' => $email
            ];
        }
        $maxId = max(array_column($newEmails, 'id'));
        setMonitorState($db, 'last_email_id', $maxId);
    }

    return $items;
}

/**
 * Lässt Weroni die gesammelten Items analysieren und Aufgaben erstellen.
 */
function processWithWeroni($db, $items, $config) {
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) return;

    $mode = $config['weroni_mode'] ?? 'assistant';
    $taskStatus = ($mode === 'autonomous') ? 'open' : 'pending_confirm';

    // Alle Items als Text formatieren
    $itemTexts = [];
    foreach ($items as $i => $item) {
        $itemTexts[] = "[" . ($i + 1) . "] (" . strtoupper($item['type']) . ") " . $item['summary'];
    }
    $itemsText = implode("\n\n", $itemTexts);

    // Bestehendes Gedächtnis laden für Kontext
    $memories = $db->getAll(
        "SELECT category, subject, content FROM weroni_memory ORDER BY importance DESC, updated_at DESC LIMIT 15",
        []
    );
    $memoryText = '';
    if (!empty($memories)) {
        $lines = array_map(fn($m) => "[{$m['category']}] {$m['subject']}: {$m['content']}", $memories);
        $memoryText = "\nDEIN GEDÄCHTNIS:\n" . implode("\n", $lines);
    }

    $now = date('d.m.Y H:i');
    $dayNames = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $dayName = $dayNames[date('w')];

    $prompt = <<<PROMPT
Du bist Weroni, die KI-Bürokauffrau. Es ist {$dayName}, {$now}.

Du hast folgende neue Nachrichten/Anrufe erhalten. Analysiere jede einzelne und entscheide:
- Muss eine Aufgabe erstellt werden? (z.B. "Kundin ruft an wegen Termin" → Aufgabe: Termin vereinbaren)
- Muss jemand erinnert werden?
- Ist es nur informativ (kein Handlungsbedarf)?
- Kannst du etwas Wichtiges daraus lernen und merken?
{$memoryText}

NEUE NACHRICHTEN:
{$itemsText}

Antworte NUR mit einem validen JSON-Array. Jedes Element hat:
- "action": "create_task" | "remember" | "skip"
- Für create_task: "title", "description", "priority" (1-10), "assigned_to" (optional), "source", "source_ref"
- Für remember: "category", "subject", "content", "importance"
- Für skip: "reason" (kurz warum kein Handlungsbedarf)

Beispiel:
[
  {"action":"create_task","title":"Termin mit Frau Müller vereinbaren","description":"Hat angerufen, möchte Ölwechsel nächste Woche","priority":6,"source":"call","source_ref":"033435 12345"},
  {"action":"remember","category":"person","subject":"Frau Müller","content":"Bevorzugt Termine am Vormittag","importance":5},
  {"action":"skip","reason":"Werbeanruf, kein Handlungsbedarf"}
]
PROMPT;

    $requestBody = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 4096,
        'messages' => [['role' => 'user', 'content' => $prompt]]
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
        echo "  [FEHLER] Claude API: HTTP {$httpCode}\n";
        return;
    }

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? '';

    // JSON extrahieren
    if (!preg_match('/\[[\s\S]*\]/', $text, $matches)) {
        echo "  [WARNUNG] Konnte keine Aktionen aus Weroni-Antwort extrahieren\n";
        return;
    }

    $actions = json_decode($matches[0], true);
    if (!is_array($actions)) {
        echo "  [WARNUNG] Ungültiges JSON in Weroni-Antwort\n";
        return;
    }

    $tasksCreated = 0;
    $memoriesStored = 0;

    foreach ($actions as $action) {
        switch ($action['action'] ?? '') {
            case 'create_task':
                $db->execute(
                    "INSERT INTO weroni_tasks (title, description, priority, status, assigned_by, assigned_to, source, source_ref, tags)
                     VALUES (:title, :desc, :prio, :status, 'Weroni (Monitor)', :assigned, :source, :srcref, :tags)",
                    [
                        ':title' => $action['title'] ?? 'Neue Aufgabe',
                        ':desc' => $action['description'] ?? null,
                        ':prio' => intval($action['priority'] ?? 5),
                        ':status' => $taskStatus,
                        ':assigned' => $action['assigned_to'] ?? null,
                        ':source' => $action['source'] ?? 'monitor',
                        ':srcref' => $action['source_ref'] ?? null,
                        ':tags' => !empty($action['tags']) ? '{' . implode(',', $action['tags']) . '}' : null
                    ]
                );
                $tasksCreated++;

                // Im Assistenten-Modus: Rückfrage erstellen damit Icon blinkt
                if ($mode === 'assistant') {
                    $db->execute(
                        "INSERT INTO weroni_questions (question, context, urgency)
                         VALUES (:q, :ctx, :urg)",
                        [
                            ':q' => 'Neue Aufgabe: ' . ($action['title'] ?? ''),
                            ':ctx' => 'Quelle: ' . ($action['source'] ?? 'Monitor') . '. ' . ($action['description'] ?? ''),
                            ':urg' => min(intval($action['priority'] ?? 5), 10)
                        ]
                    );
                }
                break;

            case 'remember':
                // Prüfen ob ähnlich bereits existiert
                $existing = $db->getOne(
                    "SELECT id FROM weroni_memory WHERE category = :cat AND subject ILIKE :subj LIMIT 1",
                    [':cat' => $action['category'] ?? 'fact', ':subj' => '%' . ($action['subject'] ?? '') . '%']
                );
                if ($existing) {
                    $db->execute(
                        "UPDATE weroni_memory SET content = :content, importance = :imp, updated_at = NOW() WHERE id = :id",
                        [':content' => $action['content'] ?? '', ':imp' => intval($action['importance'] ?? 5), ':id' => $existing['id']]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO weroni_memory (category, subject, content, importance, source)
                         VALUES (:cat, :subj, :content, :imp, 'monitor')",
                        [
                            ':cat' => $action['category'] ?? 'fact',
                            ':subj' => $action['subject'] ?? '',
                            ':content' => $action['content'] ?? '',
                            ':imp' => intval($action['importance'] ?? 5)
                        ]
                    );
                }
                $memoriesStored++;
                break;

            case 'skip':
                // Nichts tun
                break;
        }
    }

    // Aktion protokollieren
    $db->execute(
        "INSERT INTO weroni_actions (action_type, description, input_data, output_data, status)
         VALUES ('monitor_run', :desc, :input, :output, 'success')",
        [
            ':desc' => "{$tasksCreated} Aufgaben erstellt, {$memoriesStored} Erinnerungen gespeichert aus " . count($items) . " neuen Nachrichten",
            ':input' => json_encode(['item_count' => count($items), 'types' => array_column($items, 'type')], JSON_UNESCAPED_UNICODE),
            ':output' => json_encode(['tasks_created' => $tasksCreated, 'memories_stored' => $memoriesStored], JSON_UNESCAPED_UNICODE)
        ]
    );

    if ($tasksCreated > 0 || $memoriesStored > 0) {
        echo "  → {$tasksCreated} Aufgaben, {$memoriesStored} Erinnerungen\n";
    }
}

// === Hauptprogramm ===

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] Weroni Monitor gestartet\n";

try {
    $clients = getClients();
} catch (Exception $e) {
    echo "[FEHLER] Auth-DB: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($clients as $client) {
    $clientName = $client['name'] ?? "ID {$client['id']}";

    try {
        $pdo = connectPDO($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd']);
        initCompanyDb($pdo);
        $db = DbhCompany::begin();

        // Prüfen ob Weroni aktiviert ist
        $config = $db->fetchKeyValue(
            "SELECT key, value FROM defaults_oserp WHERE key IN ('weroni_enabled', 'weroni_mode', 'anthropic_api_key')"
        );

        $enabled = ($config['weroni_enabled'] ?? '') === 'true' || ($config['weroni_enabled'] ?? '') === 't';
        if (!$enabled) continue;

        // Neue Items sammeln
        $items = collectNewItems($db);
        if (empty($items)) continue;

        echo "[{$clientName}] " . count($items) . " neue Nachrichten gefunden\n";
        processWithWeroni($db, $items, $config);

    } catch (Exception $e) {
        echo "[{$clientName}] Fehler: " . $e->getMessage() . "\n";
    } finally {
        resetCompanyDb();
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Weroni Monitor beendet\n";
