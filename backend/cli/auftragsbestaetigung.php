#!/usr/bin/env php
<?php
/**
 * Auftragsbestätigung — erkennt Zusagen und Absagen von Kunden (kein Cron)
 *
 * Läuft dauerhaft als systemd-Dienst oserp-auftragsbestaetigung. Eingehende
 * WhatsApp-Nachrichten und Anrufe von Kunden mit offenem Auftragseingang stellt
 * die Datenbank selbst in die Warteschlange oe_confirmation_queue_oserp
 * (Trigger im crm-Upstall), E-Mails meldet der Dienst oserp-email-eingang. Der
 * Dienst hört je Mandant auf pg_notify('order_confirm_queue') und arbeitet die
 * Einträge sofort ab:
 *
 *   1. Anruf: auf die Aufnahme im Asterisk-Monitorverzeichnis warten (nicht
 *      angenommene Anrufe entfallen), WhatsApp-Sprachnachricht: Datei laden —
 *      beides transkribiert der lokale Whisper-Dienst (oserp-whisper)
 *   2. Claude prüft den Text gegen die offenen Auftragseingänge des Kunden
 *      (oe_confirmation_candidates): Zusage, Absage, Rückfrage oder nichts —
 *      und welcher Auftrag gemeint ist
 *   3. oe_confirmation_suggest() legt den Vorschlag an (im Modus „auto“ bei
 *      eindeutiger Zusage gleich die Bestätigung); Auftragsmaske und
 *      Kontakthistorie aktualisieren sich per SSE
 *
 * Ein- und ausgeschaltet wird je Mandant in der Firmenkonfiguration → CRM →
 * Auftragsbestätigung (defaults_oserp.order_confirm_ai: off | suggest | auto).
 * Ohne Anthropic-API-Schlüssel bleibt die Warteschlange liegen.
 *
 * Für Anrufe muss der Dienst das Aufnahmeverzeichnis lesen dürfen
 * (settings.ini [telephony] monitor_dir, meist Gruppe asterisk).
 *
 * Aufruf:
 *   php backend/cli/auftragsbestaetigung.php            (Dienst)
 *   ... --client 10   nur ein Mandant (auth.clients.id)
 *   ... --once        Warteschlange einmal abarbeiten und beenden
 *
 * Unit-Vorlage: install/oserp-auftragsbestaetigung.service
 * Installer:    install/install.sh --only auftragsbestaetigung
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur über die Kommandozeile ausgeführt werden.\n");
}

require_once dirname(__DIR__) . '/api/error.php';
require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/logging.php';
require_once dirname(__DIR__) . '/api/database.php';
require_once dirname(__DIR__) . '/api/lib/ai_model.php';
require_once dirname(__DIR__) . '/api/lib/whisper.php';

const OC_REFRESH_SEC   = 60;        // Mandanten neu einlesen
const OC_SCAN_SEC      = 30;        // Warteschlange auch ohne Meldung prüfen (Anrufe warten auf die Aufnahme)
const OC_CALL_RETRY    = 30;        // Aufnahme noch nicht da → erneut nach … s
const OC_CALL_SETTLE   = 15;        // Aufnahme gilt als fertig, wenn sie so lange unverändert ist
const OC_CALL_MAX_WAIT = 2 * 3600;  // ohne Aufnahme nach … s aufgeben
const OC_AI_RETRY      = 300;       // KI/Whisper nicht erreichbar → erneut nach … s
const OC_MAX_ATTEMPTS  = 6;         // danach status error
const OC_BATCH         = 5;         // Einträge je Mandant und Durchlauf
const OC_PAUSE_SEC     = 600;       // ohne gültigen API-Schlüssel wartet die Warteschlange so lange
const OC_KEEP_DAYS     = 30;        // erledigte Einträge (Mailtexte, Transkripte) danach löschen
const OC_CLEANUP_SEC   = 3600;      // Aufräumen höchstens so oft je Mandant
// Asterisk DIALSTATUS, bei denen kein Gespräch stattfand
const OC_CALL_NOT_ANSWERED = ['NOANSWER', 'CANCEL', 'BUSY', 'CHANUNAVAIL', 'CONGESTION'];

$onlyId = 0;
$once = false;
foreach ($argv as $i => $a) {
    if ($a === '--client' && isset($argv[$i + 1])) $onlyId = intval($argv[$i + 1]);
    if ($a === '--once') $once = true;
}

function ocLog(string $text): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n";
    flush();
}

class AuftragsbestaetigungDienst {
    private int $onlyId;
    /** @var array<int, array{row: array, pdo: ?PDO, scanAt: float}> */
    private array $clients = [];
    /** @var array<string, string> letzte Fehlermeldung je Schlüssel — gleiche Meldung nur einmal protokollieren */
    private array $lastError = [];
    private float $lastRefresh = 0;
    public bool $stop = false;

    public function __construct(int $onlyId) {
        $this->onlyId = $onlyId;
    }

    public function run(bool $once): void {
        ocLog('Auftragsbestätigung gestartet');
        while (!$this->stop) {
            if (microtime(true) - $this->lastRefresh >= OC_REFRESH_SEC) $this->refresh();
            $busy = false;
            foreach (array_keys($this->clients) as $id) {
                if ($this->stop) break;
                $busy = $this->poll($id, $once) || $busy;
            }
            // --once: so lange, bis kein fälliger Eintrag mehr übrig ist
            if ($once && !$busy) break;
            if ($once) continue;
            sleep(1);
        }
        ocLog('Auftragsbestätigung beendet');
    }

    /** Mandanten einlesen, je Mandant eine eigene Verbindung mit LISTEN */
    private function refresh(): void {
        $this->lastRefresh = microtime(true);
        try {
            $auth = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
            $rows = $auth->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            unset($this->lastError['auth']);
        } catch (Throwable $e) {
            $this->error('auth', 'Auth-DB nicht erreichbar: ' . $e->getMessage());
            return;
        }
        $seen = [];
        foreach ($rows as $row) {
            $id = intval($row['id']);
            if ($this->onlyId && $id !== $this->onlyId) continue;
            $seen[$id] = true;
            $client = $this->clients[$id] ?? ['row' => $row, 'pdo' => null, 'scanAt' => 0, 'cleanupAt' => 0];
            // Andere Datenbank für denselben Mandanten (auth.clients geändert): neu verbinden
            $conn = fn($r) => [$r['dbhost'], $r['dbport'], $r['dbname'], $r['dbuser'], $r['dbpasswd']];
            if ($client['pdo'] && $conn($client['row']) !== $conn($row)) $client['pdo'] = null;
            $client['row'] = $row;
            if (!$client['pdo']) {
                try {
                    // Eigene, nicht persistente Verbindung: LISTEN gehört zur Sitzung
                    $pdo = new PDO("pgsql:host={$row['dbhost']};port={$row['dbport']};dbname={$row['dbname']}",
                                   $row['dbuser'], $row['dbpasswd'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $pdo->exec('LISTEN order_confirm_queue');
                    $client['pdo'] = $pdo;
                    $client['scanAt'] = 0;
                    unset($this->lastError['db' . $id]);
                } catch (Throwable $e) {
                    $this->error('db' . $id, "[{$row['dbname']}] " . $e->getMessage());
                }
            }
            $this->clients[$id] = $client;
        }
        foreach (array_keys($this->clients) as $id) {
            if (!isset($seen[$id])) unset($this->clients[$id]);
        }
    }

    /**
     * Meldungen abholen; bei Meldung oder fälligem Durchlauf die Warteschlange abarbeiten
     * @return bool true, wenn Einträge bearbeitet wurden
     */
    private function poll(int $id, bool $force): bool {
        $client = &$this->clients[$id];
        $pdo = $client['pdo'];
        if (!$pdo) return false;
        try {
            $notified = false;
            while ($pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 0)) $notified = true;
            if (!$notified && !$force && microtime(true) < $client['scanAt']) return false;
            $client['scanAt'] = microtime(true) + OC_SCAN_SEC;
            if (microtime(true) >= $client['cleanupAt']) {
                $client['cleanupAt'] = microtime(true) + OC_CLEANUP_SEC;
                $this->cleanup($pdo);
            }
            $n = $this->processQueue($pdo, $client['row']);
            unset($this->lastError['queue' . $id]);
            return $n > 0;
        } catch (Throwable $e) {
            // Fehlt die Warteschlange (crm-Upstall dort noch nicht gelaufen) oder ist die
            // Verbindung weg: beim nächsten Einlesen (höchstens jede Minute) neu verbinden
            $this->error('queue' . $id, "[{$client['row']['dbname']}] " . $e->getMessage());
            $client['pdo'] = null;
            return false;
        }
    }

    /** Erledigte Einträge nach OC_KEEP_DAYS löschen — sie enthalten Mailtexte und Gesprächsmitschriften */
    private function cleanup(PDO $pdo): void {
        $this->stmt($pdo,
            "DELETE FROM oe_confirmation_queue_oserp WHERE status <> 'new' AND processed < now() - make_interval(days => :d)",
            [':d' => OC_KEEP_DAYS]);
    }

    /** @return int Zahl der bearbeiteten Einträge */
    private function processQueue(PDO $pdo, array $client): int {
        $db = $client['dbname'];
        $items = $pdo->query(
            "SELECT q.id, q.channel, q.source_ref, q.customer_id, q.received_at, q.subject, q.content, q.media_path,
                    q.attempts, EXTRACT(EPOCH FROM now() - q.created)::int AS age,
                    oe_confirmation_mode() AS mode
               FROM oe_confirmation_queue_oserp q
              WHERE q.status = 'new' AND (q.next_try IS NULL OR q.next_try <= now())
              ORDER BY q.id
              LIMIT " . OC_BATCH
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) return 0;

        $config = $pdo->query(
            "SELECT key, value FROM defaults_oserp
              WHERE key IN ('anthropic_api_key', 'ai_external_enabled', '" . aiModelConfigKey('order_confirm') . "', '"
                  . implode("', '", whisperConfigKeys()) . "')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($items as $item) {
            if ($this->stop) break;
            try {
                $this->processItem($pdo, $db, $item, $config);
            } catch (ApiError $e) {
                $code = $e->getId();
                if (in_array($code, ['CLAUDE_HTTP_401', 'CLAUDE_HTTP_403'], true)) {
                    // Schlüssel ungültig/gesperrt: nichts verwerfen, die Warteschlange wartet
                    $this->later($pdo, (int)$item['id'], OC_PAUSE_SEC);
                    $this->error("key$db", "[$db] Anthropic-API-Schlüssel abgelehnt ($code) — Warteschlange wartet");
                    continue;
                }
                // Ablehnung durch die KI oder zu große Datei: erneut versuchen bringt nichts
                $permanent = in_array($code, ['CLAUDE_REFUSAL', 'CLAUDE_HTTP_400', 'CLAUDE_HTTP_413', 'WHISPER_HTTP_413'], true);
                $final = $permanent || $item['attempts'] + 1 >= OC_MAX_ATTEMPTS;
                $this->finish($pdo, (int)$item['id'], $final ? 'error' : 'new', null, $code . ': ' . $e->getMessage(), $final ? null : OC_AI_RETRY);
                $this->error("item$db", "[$db] Eintrag {$item['id']}: $code — " . $e->getMessage());
            } catch (PDOException $e) {
                // Datenbank weg: an poll() weiterreichen (neu verbinden), der Eintrag bleibt offen
                throw $e;
            } catch (Throwable $e) {
                // Unerwarteter Fehler in diesem Eintrag: mit Pause erneut, nicht im Sekundentakt
                $final = $item['attempts'] + 1 >= OC_MAX_ATTEMPTS;
                $this->finish($pdo, (int)$item['id'], $final ? 'error' : 'new', null, get_class($e) . ': ' . $e->getMessage(), $final ? null : OC_AI_RETRY);
                $this->error("item$db", "[$db] Eintrag {$item['id']}: " . $e->getMessage());
            }
        }
        return count($items);
    }

    private function processItem(PDO $pdo, string $db, array $item, array $config): void {
        $id = (int)$item['id'];
        if ($item['mode'] === 'off') {
            $this->finish($pdo, $id, 'skipped', null, 'DISABLED');
            return;
        }
        if (trim($config['anthropic_api_key'] ?? '') === '') {
            // Ohne Schlüssel nichts verwerfen — die Einträge warten, bis er eingetragen ist
            $this->later($pdo, $id, OC_PAUSE_SEC);
            $this->error("key$db", "[$db] Kein Anthropic-API-Schlüssel (Firmenkonfiguration) — Warteschlange wartet");
            return;
        }
        unset($this->lastError["key$db"]);

        $text = trim((string)$item['content']);

        // 1. Anruf: Aufnahme abwarten und transkribieren
        if ($item['channel'] === 'phone' && $text === '') {
            $call = $this->stmt($pdo,
                "SELECT crmti_status FROM crmti WHERE unique_call_id = :ref OR 'crmti:' || crmti_id = :ref2 ORDER BY crmti_id DESC LIMIT 1",
                [':ref' => $item['source_ref'], ':ref2' => $item['source_ref']])->fetch(PDO::FETCH_ASSOC);
            if ($call && in_array(strtoupper((string)$call['crmti_status']), OC_CALL_NOT_ANSWERED, true)) {
                $this->finish($pdo, $id, 'skipped', null, 'NOT_ANSWERED');
                return;
            }
            $file = $this->findRecording($item['source_ref'], $db);
            if ($file === false) {
                $this->finish($pdo, $id, 'skipped', null, 'RECORDING_DIR_UNREADABLE');
                return;
            }
            if ($file === null) {
                if ($item['age'] > OC_CALL_MAX_WAIT) $this->finish($pdo, $id, 'skipped', null, 'NO_RECORDING');
                else $this->later($pdo, $id, OC_CALL_RETRY);
                return;
            }
            $text = $this->transcribe($pdo, $id, $file, $config, 900);
        }

        // WhatsApp-Sprachnachricht (auch Altbestand mit Platzhaltertext „[Sprachnachricht]“)
        if ($item['channel'] === 'whatsapp' && $item['media_path'] && ($text === '' || preg_match('/^\[[^\]]*\]$/u', $text))) {
            $text = '';
            $file = dirname(__DIR__) . '/data/' . $db . '/' . ltrim($item['media_path'], '/');
            if (is_file($file)) $text = $this->transcribe($pdo, $id, $file, $config, 300);
        }

        if ($text === '') {
            $this->finish($pdo, $id, 'skipped', null, 'EMPTY');
            return;
        }

        // 2. Offene Aufträge, Kunde und Gesprächsverlauf in einer Abfrage
        $ctx = $this->stmt($pdo,
            "SELECT oe_confirmation_candidates(:cv, CAST(:received AS timestamptz)) AS candidates,
                    (SELECT name FROM customer WHERE id = :cv2) AS customer_name,
                    -- WhatsApp: die Nachrichten davor (beide Richtungen, 7 Tage) — eine Zusage
                    -- bezieht sich meist auf den eben geschickten Kostenvoranschlag
                    CASE WHEN :channel = 'whatsapp' THEN (
                        SELECT json_agg(json_build_object('direction', w.direction, 'time', w.itime,
                                   'text', LEFT(COALESCE(NULLIF(w.message_text, ''), NULLIF(w.media_caption, ''), '[' || w.message_type || ']'), 500))
                                   ORDER BY w.itime)
                          FROM (SELECT p.* FROM whatsapp_messages p
                                  JOIN whatsapp_messages m ON m.id = :wa_id::int
                                 WHERE p.phone_number = m.phone_number AND p.id <> m.id AND p.hidden = FALSE
                                   AND p.itime < m.itime AND p.itime > m.itime - interval '7 days'
                                 ORDER BY p.itime DESC LIMIT 8) w
                    ) END AS context",
            [':cv' => $item['customer_id'], ':received' => $item['received_at'], ':cv2' => $item['customer_id'], ':channel' => $item['channel'],
             ':wa_id' => $item['channel'] === 'whatsapp' && ctype_digit((string)$item['source_ref']) ? $item['source_ref'] : '0'])
            ->fetch(PDO::FETCH_ASSOC);
        $candidates = json_decode($ctx['candidates'] ?? '[]', true) ?: [];
        if (!$candidates) {
            $this->finish($pdo, $id, 'skipped', null, 'NO_OPEN_ORDER');
            return;
        }

        // 3. KI fragen und Ergebnis übernehmen
        $answer = $this->classify($config, [
            'channel'       => $item['channel'],
            'received_at'   => $item['received_at'],
            'customer_name' => $ctx['customer_name'],
            'subject'       => $item['subject'],
            'text'          => mb_substr($text, 0, 12000),
            'context'       => json_decode($ctx['context'] ?? 'null', true),
            'open_orders'   => $candidates,
        ]);

        // Nur geprüfte Felder weiterverwenden und speichern — der Kundentext fließt in die
        // Antwort ein und könnte sonst Steuerzeichen oder unsinnige Zahlen hineintragen
        $clean = fn($v) => mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? ''), 0, 500);
        $confidence = is_numeric($answer['confidence'] ?? null) && is_finite((float)$answer['confidence'])
            ? max(0, min(1, (float)$answer['confidence'])) : 0;
        $answer = [
            'intent'     => in_array($answer['intent'] ?? '', ['accept', 'decline', 'question', 'none'], true) ? $answer['intent'] : 'none',
            'oe_id'      => is_numeric($answer['oe_id'] ?? null) ? (int)$answer['oe_id'] : null,
            'quote'      => $clean($answer['quote'] ?? ''),
            'conditions' => $clean($answer['conditions'] ?? ''),
            'confidence' => $confidence,
            'reason'     => $clean($answer['reason'] ?? ''),
        ];
        $intent = $answer['intent'];
        $outcome = 'ignored';
        if ($intent === 'accept' || $intent === 'decline') {
            $ids = array_map(fn($c) => (int)$c['id'], $candidates);
            $oeId = (int)$answer['oe_id'];
            if (!in_array($oeId, $ids, true)) {
                // Keine (gültige) Zuordnung: der neueste Auftrag, aber als unsicher markiert
                $oeId = $ids[0];
                if (count($ids) > 1) $confidence = min($confidence, 0.5);
            }
            $outcome = $this->stmt($pdo,
                "SELECT oe_confirmation_suggest(:q, :oe, :intent, :quote, :conditions, :confidence)",
                [':q' => $id, ':oe' => $oeId, ':intent' => $intent,
                 ':quote' => $answer['quote'] ?: null,
                 ':conditions' => $answer['conditions'] ?: null,
                 ':confidence' => round($confidence, 3)])->fetchColumn();
            ocLog("[$db] {$item['channel']} {$item['source_ref']}: $intent → Auftrag $oeId ($outcome)");
        }
        $this->finish($pdo, $id, 'done', $answer + ['outcome' => $outcome], null);
    }

    /**
     * Aufnahme eines Anrufs im Monitorverzeichnis — die größte Datei mit der
     * Call-ID (bei getrennten Spuren ist das die gemischte). null = noch nicht
     * da oder wird noch geschrieben, false = Verzeichnis nicht lesbar
     */
    private function findRecording(string $ref, string $db) {
        $dir = TELEPHONY_MONITOR_DIR;
        if (str_starts_with($ref, 'crmti:')) return null;
        if (!is_dir($dir) || !is_readable($dir)) {
            $this->error('monitor', "Aufnahmeverzeichnis $dir nicht lesbar — Anrufe werden übersprungen (Dienst in die Gruppe asterisk aufnehmen)");
            return false;
        }
        unset($this->lastError['monitor']);
        $best = null;
        $bestSize = 44;   // kleiner: leerer WAV-Kopf
        foreach (scandir($dir) ?: [] as $file) {
            if (strpos($file, $ref) === false) continue;
            $path = "$dir/$file";
            clearstatcache(true, $path);
            $size = @filesize($path);
            if ($size > $bestSize) {
                $best = $path;
                $bestSize = $size;
            }
        }
        if ($best && time() - filemtime($best) < OC_CALL_SETTLE) return null;
        return $best;
    }

    /** Datei transkribieren und den Text gleich in der Warteschlange festhalten */
    private function transcribe(PDO $pdo, int $id, string $file, array $config, int $timeout): string {
        $audio = @file_get_contents($file);
        if ($audio === false || $audio === '') return '';
        // Sprache erkennen lassen: nicht jeder Kunde spricht Deutsch
        $text = whisperTranscribe($audio, $config, 'auto', $timeout)['text'];
        $this->stmt($pdo, "UPDATE oe_confirmation_queue_oserp SET content = :c WHERE id = :id", [':c' => $text, ':id' => $id]);
        return $text;
    }

    /**
     * Claude: Zusage, Absage, Rückfrage oder nichts — und zu welchem Auftrag?
     *
     * @return array {intent, oe_id, quote, conditions, confidence, reason}
     */
    private function classify(array $config, array $input): array {
        $heute = date('Y-m-d');
        $system = <<<PROMPT
Du prüfst für einen Betrieb (Kfz-Werkstatt bzw. Handel) Nachrichten und Telefonate mit Kunden: Hat der Kunde einen seiner offenen Aufträge zugesagt (zur Ausführung freigegeben) oder abgesagt?

Heute ist der {$heute}.

Regeln:
- "accept" nur bei klarer Zustimmung zur Ausführung: „Ja, machen Sie das“, „Passt, bitte reparieren“, „Okay, bestellen Sie das Teil“, Freigabe eines genannten Preises oder Kostenvoranschlags.
- Zögern, Rückfragen, Preisverhandlung, „ich überlege noch“, „ich melde mich“, „erst nach Rücksprache“ sind "question" — niemals "accept".
- Zusage mit Bedingung („ja, wenn es unter 500 € bleibt“, „aber nur vorne“) ist "accept"; die Bedingung kommt kurz und auf Deutsch in "conditions".
- "decline" bei klarer Ablehnung („nein, lassen Sie das“, „zu teuer, ich hole das Auto so ab“).
- Alles andere (Terminfragen, Dank, Smalltalk, Rechnungsthemen) ist "none".
- Bei Telefonaten (Transkript, kann Hörfehler enthalten) sprechen Betrieb und Kunde; es zählt nur, was der Kunde sagt.
- "context" sind die Nachrichten davor (direction O = vom Betrieb, I = vom Kunden) — nur zum Verständnis; beurteilt wird allein "text".
- Ordne die Aussage einem Auftrag aus "open_orders" zu ("oe_id"): genannte Arbeiten, Teile, Beträge, Fahrzeug, Datum. Bei nur einem offenen Auftrag nimm diesen. Ist bei mehreren nicht zu entscheiden, setze null.
- "quote": die entscheidende Aussage des Kunden wörtlich, höchstens 200 Zeichen, in der Originalsprache.
- "confidence": 0 bis 1 — wie sicher Absicht und Zuordnung zusammen sind.

Antworte ausschließlich mit JSON:
{"intent": "accept" | "decline" | "question" | "none", "oe_id": 123 oder null, "quote": "…" oder null, "conditions": "…" oder null, "confidence": 0.0, "reason": "kurze Begründung"}
PROMPT;

        // Kurze Einordnung: wenig Denkaufwand genügt. Auf den aktuellen Modellen läuft
        // adaptives Denken standardmäßig und zählt auf max_tokens — daher effort low und
        // genug Spielraum. Claude Haiku 4.5 kennt effort nicht (400) und denkt ohnehin nicht.
        $model = resolveAiModel($config, 'order_confirm');
        $request = [
            'model'      => $model,
            'max_tokens' => 4000,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)]],
        ];
        if ($model !== 'claude-haiku-4-5') $request['output_config'] = ['effort' => 'low'];
        $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . trim($config['anthropic_api_key']),
                'anthropic-version: 2023-06-01',
            ],
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false || $code === 0) throw new ApiError('CLAUDE_UNREACHABLE', $err);
        if ($code !== 200) throw new ApiError('CLAUDE_HTTP_' . $code, substr((string)$response, 0, 300));

        $data = json_decode($response, true);
        $stop = $data['stop_reason'] ?? '';
        // Abgelehnt (Sicherheitsfilter): ein neuer Versuch ändert nichts
        if ($stop === 'refusal') throw new ApiError('CLAUDE_REFUSAL', (string)($data['stop_details']['category'] ?? ''));
        if ($stop === 'max_tokens') throw new ApiError('CLAUDE_MAX_TOKENS', 'Antwort abgeschnitten');
        $text = aiResponseText($data);
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $answer = json_decode($m[0], true);
            if (is_array($answer)) return $answer;
        }
        throw new ApiError('CLAUDE_INVALID_ANSWER', substr($text, 0, 300));
    }

    /** Warten (Aufnahme fehlt noch, kein Schlüssel) — zählt nicht als Fehlversuch */
    private function later(PDO $pdo, int $id, int $seconds): void {
        $this->stmt($pdo,
            "UPDATE oe_confirmation_queue_oserp SET next_try = now() + make_interval(secs => :s) WHERE id = :id",
            [':s' => $seconds, ':id' => $id]);
    }

    /** Eintrag abschließen ($status done|skipped|error) oder mit $retry Sekunden zurückstellen */
    private function finish(PDO $pdo, int $id, string $status, ?array $result, ?string $error, ?int $retry = null): void {
        $this->stmt($pdo,
            "UPDATE oe_confirmation_queue_oserp
                SET status = :status, result = CAST(:result AS jsonb), error = :error, attempts = attempts + 1,
                    next_try = CASE WHEN :retry::int IS NULL THEN NULL ELSE now() + make_interval(secs => :retry2::int) END,
                    processed = CASE WHEN :status2 = 'new' THEN NULL ELSE now() END
              WHERE id = :id",
            [':status' => $status, ':status2' => $status, ':result' => $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
             ':error' => $error, ':retry' => $retry, ':retry2' => $retry, ':id' => $id]);
    }

    private function stmt(PDO $pdo, string $sql, array $params): PDOStatement {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    private function error(string $key, string $text): void {
        if (($this->lastError[$key] ?? '') === $text) return;
        $this->lastError[$key] = $text;
        ocLog('[FEHLER] ' . $text);
    }
}

$dienst = new AuftragsbestaetigungDienst($onlyId);

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, function () use ($dienst) { $dienst->stop = true; });
    }
}

$dienst->run($once);
