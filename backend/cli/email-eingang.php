#!/usr/bin/env php
<?php
/**
 * E-Mail-Eingang — Überwachungsdienst für die Kontakthistorie (kein Cron)
 *
 * Läuft dauerhaft als systemd-Dienst oserp-email-eingang und hält zu jedem
 * eingerichteten IMAP-Postfach (Firmenkonto aus defaults_oserp und abweichende
 * Mitarbeiterkonten aus employee_config_oserp) je eine Verbindung auf den
 * Posteingang und den Gesendet-Ordner. Der Server meldet neue Nachrichten per
 * IMAP IDLE sofort; der Dienst liest die Adressen aus dem Umschlag und ruft in
 * jedem Mandanten mit diesem Postfach contact_history_email_notify() auf
 * (crm-Upstall). Die ordnet die Adressen Kunden/Lieferanten zu und meldet sie
 * über pg_notify('contact_history_change') — der SSE-Server reicht das an die
 * offenen CRM-Ansichten weiter, deren Kontakthistorie die E-Mails neu lädt.
 *
 * Neue Nachrichten im Posteingang von Kunden mit offenem Auftragseingang stellt
 * der Dienst außerdem in die Warteschlange der Auftragsbestätigung
 * (oe_confirmation_email_enqueue, crm-Upstall) — der Dienst
 * oserp-auftragsbestaetigung prüft sie auf eine Zusage. Nur diese Nachrichten
 * werden vollständig geladen, und zwar ohne sie als gelesen zu markieren.
 *
 * Gleiche Postfächer in mehreren Mandanten werden nur einmal überwacht (IMAP-
 * Server begrenzen die Verbindungen je Konto). Server ohne IDLE werden alle
 * 15 s abgefragt. Mandanten und Konten werden jede Minute neu eingelesen,
 * abgebrochene Verbindungen nach 30 s neu aufgebaut.
 *
 * Aufruf:
 *   php backend/cli/email-eingang.php             (Dienst)
 *   ... --client 10   nur ein Mandant (auth.clients.id)
 *
 * Unit-Vorlage: install/oserp-email-eingang.service
 * Installer:    install/install.sh --only emaileingang
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur über die Kommandozeile ausgeführt werden.\n");
}

require_once dirname(__DIR__) . '/api/error.php';
require_once dirname(__DIR__) . '/api/config.php';
require_once dirname(__DIR__) . '/api/database.php';
require_once dirname(__DIR__) . '/api/email/imap.class.php';

const EMAIL_REFRESH_SEC = 60;       // Mandanten und Konten neu einlesen
const EMAIL_RETRY_SEC   = 30;       // abgebrochene Verbindung neu aufbauen
const EMAIL_POLL_SEC    = 15;       // Abfrage bei Servern ohne IDLE
const EMAIL_REIDLE_SEC  = 25 * 60;  // IDLE erneuern (Server trennen nach ~30 min)
const EMAIL_CONFIRM_MAX_BYTES = 15 * 1024 * 1024;  // größere Mails nicht auf Auftragsbestätigung prüfen

$onlyId = 0;
foreach ($argv as $i => $a) {
    if ($a === '--client' && isset($argv[$i + 1])) $onlyId = intval($argv[$i + 1]);
}

function emailLog(string $text): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n";
    flush();
}

class EmailEingangDienst {
    private int $onlyId;
    /** @var array<int, array> auth.clients-Zeile + pdo je Mandant */
    private array $clients = [];
    /**
     * Überwachte Ordner, Schlüssel "<konto>|<inbox|sent>":
     * {account, cfg, role, folder, imap, last, idle, idleSince, lastPoll, retryAt, clients: int[]}
     * @var array<string, array>
     */
    private array $watchers = [];
    /** @var array<string, string> letzte Fehlermeldung je Schlüssel — gleiche Meldung nur einmal protokollieren */
    private array $lastError = [];
    private float $lastRefresh = 0;
    public bool $stop = false;

    public function __construct(int $onlyId) {
        $this->onlyId = $onlyId;
    }

    public function run(): void {
        emailLog('E-Mail-Eingang gestartet');
        while (!$this->stop) {
            if (microtime(true) - $this->lastRefresh >= EMAIL_REFRESH_SEC) $this->refresh();
            $this->connectDue();
            $this->wait(1);
            $this->maintain();
        }
        foreach ($this->watchers as $w) $this->close($w);
        emailLog('E-Mail-Eingang beendet');
    }

    /** Mandanten und ihre Postfächer einlesen, Überwachungen anlegen bzw. beenden */
    private function refresh(): void {
        $this->lastRefresh = microtime(true);
        try {
            $auth = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
            $rows = $auth->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->error('auth', 'Auth-DB nicht erreichbar: ' . $e->getMessage());
            return;
        }

        $wanted = [];   // Konto → {cfg, clients[]}
        foreach ($rows as $row) {
            $id = intval($row['id']);
            if ($this->onlyId && $id !== $this->onlyId) continue;
            try {
                $client = $this->clients[$id] ?? ['row' => $row, 'pdo' => null];
                $client['row'] = $row;
                if (!$client['pdo']) {
                    $client['pdo'] = connectPDO($row['dbhost'], $row['dbport'], $row['dbname'], $row['dbuser'], $row['dbpasswd']);
                }
                $this->clients[$id] = $client;
                foreach ($this->accounts($client['pdo']) as $key => $cfg) {
                    $wanted[$key] ??= ['cfg' => $cfg, 'clients' => []];
                    $wanted[$key]['clients'][] = $id;
                }
                unset($this->lastError['db' . $id]);
            } catch (Throwable $e) {
                $this->error('db' . $id, "[{$row['dbname']}] " . $e->getMessage());
                unset($this->clients[$id]);
            }
        }

        // Neue Konten anlegen, geänderte (Passwort, Server) neu verbinden, entfallene beenden
        foreach ($wanted as $key => $acc) {
            foreach (['inbox', 'sent'] as $role) {
                $wkey = "$key|$role";
                $w = $this->watchers[$wkey] ?? null;
                if ($w && $w['cfg'] !== $acc['cfg']) {
                    $this->close($w);
                    $w = null;
                }
                if (!$w) {
                    $w = ['account' => $key, 'cfg' => $acc['cfg'], 'role' => $role, 'folder' => null, 'imap' => null,
                          'last' => null, 'idle' => false, 'idleSince' => 0, 'lastPoll' => 0, 'retryAt' => 0];
                }
                $w['clients'] = $acc['clients'];
                $this->watchers[$wkey] = $w;
            }
        }
        foreach ($this->watchers as $wkey => $w) {
            if (!isset($wanted[$w['account']])) {
                $this->close($w);
                unset($this->watchers[$wkey]);
                emailLog("[$wkey] Überwachung beendet (Konto nicht mehr eingerichtet)");
            }
        }
    }

    /**
     * Eingerichtete Postfächer eines Mandanten wie _getEmailConfig(): Firmenkonto,
     * dazu je Mitarbeiter mit eigenen Werten das zusammengeführte Konto
     * @return array<string, array> "host:port|benutzer" → Konfiguration
     */
    private function accounts(PDO $pdo): array {
        $rows = $pdo->query(
            "SELECT NULL::integer AS employee_id, key, value FROM defaults_oserp WHERE key LIKE 'email_%'
             UNION ALL
             SELECT employee_id, key, value FROM employee_config_oserp
              WHERE key LIKE 'email_%' AND COALESCE(value, '') <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);
        $company = [];
        $employees = [];
        foreach ($rows as $r) {
            if ($r['employee_id'] === null) $company[$r['key']] = $r['value'];
            else $employees[$r['employee_id']][$r['key']] = $r['value'];
        }
        $result = [];
        foreach (array_merge([$company], array_map(fn($e) => array_merge($company, $e), array_values($employees))) as $c) {
            $cfg = [
                'host' => trim($c['email_imap_host'] ?? ''),
                'port' => (int)($c['email_imap_port'] ?? 993),
                'encryption' => $c['email_imap_encryption'] ?? 'ssl',
                'username' => $c['email_username'] ?? '',
                'password' => $c['email_password'] ?? '',
            ];
            if ($cfg['host'] === '' || $cfg['username'] === '' || $cfg['password'] === '') continue;
            $result[strtolower($cfg['host']) . ':' . $cfg['port'] . '|' . strtolower($cfg['username'])] = $cfg;
        }
        return $result;
    }

    /** Fällige Verbindungen aufbauen: Ordner wählen, Stand merken, IDLE beginnen */
    private function connectDue(): void {
        $now = microtime(true);
        foreach ($this->watchers as $wkey => &$w) {
            if ($w['imap'] || $now < $w['retryAt']) continue;
            try {
                $c = $w['cfg'];
                $imap = new ImapClient($c['host'], $c['port'], $c['encryption'], $c['username'], $c['password']);
                $imap->connect();
                $folder = $w['role'] === 'inbox' ? 'INBOX' : $imap->findSentFolder();
                if (!$folder || ($w['role'] === 'sent' && $folder === 'INBOX')) {
                    // Kein Gesendet-Ordner: nicht jede Minute neu probieren
                    $imap->disconnect();
                    $w['retryAt'] = $now + 3600;
                    continue;
                }
                // Stand vor der Auswahl merken; nach einem Abbruch die Lücke nachholen
                $next = $imap->uidNext($folder);
                $imap->selectFolder($folder);
                $w['imap'] = $imap;
                $w['folder'] = $folder;
                $w['idle'] = $imap->hasCapability('IDLE');
                if ($w['last'] === null) $w['last'] = max(0, $next - 1);
                else $this->check($w);
                if ($w['idle']) $this->idleStart($w);
                $w['lastPoll'] = $now;
                unset($this->lastError[$wkey]);
                emailLog("[$wkey] überwacht $folder (" . ($w['idle'] ? 'IDLE' : 'Abfrage alle ' . EMAIL_POLL_SEC . ' s') . ')');
            } catch (Throwable $e) {
                $this->fail($wkey, $w, $e);
            }
        }
        unset($w);
    }

    /** Auf Meldungen der IDLE-Verbindungen warten (höchstens $sec Sekunden) */
    private function wait(int $sec): void {
        $read = [];
        $map = [];
        foreach ($this->watchers as $wkey => $w) {
            if ($w['imap'] && $w['idle']) {
                $s = $w['imap']->getSocket();
                $read[] = $s;
                $map[(int)$s] = $wkey;
            }
        }
        if (!$read) {
            sleep($sec);
            return;
        }
        $write = $except = null;
        if (@stream_select($read, $write, $except, $sec) < 1) return;
        foreach ($read as $s) {
            $wkey = $map[(int)$s];
            $w = &$this->watchers[$wkey];
            try {
                if ($w['imap']->idleRead()) {
                    $w['imap']->idleDone();
                    $this->check($w);
                    $this->idleStart($w);
                }
            } catch (Throwable $e) {
                $this->fail($wkey, $w, $e);
            }
            unset($w);
        }
    }

    /** IDLE regelmäßig erneuern, Server ohne IDLE abfragen */
    private function maintain(): void {
        $now = microtime(true);
        foreach ($this->watchers as $wkey => &$w) {
            if (!$w['imap']) continue;
            try {
                if ($w['idle'] && $now - $w['idleSince'] >= EMAIL_REIDLE_SEC) {
                    $w['imap']->idleDone();
                    $this->check($w);
                    $this->idleStart($w);
                } elseif (!$w['idle'] && $now - $w['lastPoll'] >= EMAIL_POLL_SEC) {
                    $w['lastPoll'] = $now;
                    $w['imap']->noop();
                    $this->check($w);
                }
            } catch (Throwable $e) {
                $this->fail($wkey, $w, $e);
            }
        }
        unset($w);
    }

    private function idleStart(array &$w): void {
        $w['imap']->idleStart();
        $w['idleSince'] = microtime(true);
    }

    /** Neue Nachrichten seit der letzten bekannten UID den Mandanten melden */
    private function check(array &$w): void {
        $uids = array_values(array_filter(
            $w['imap']->search('UID ' . ($w['last'] + 1) . ':*'),
            fn($uid) => $uid > $w['last']
        ));
        if (!$uids) return;
        $w['last'] = max($uids);
        $this->notifyHistory($w, $uids);
        // Danach — und ohne die Meldung oben zu gefährden — die Auftragsbestätigung.
        // Scheitert hier die Verbindung, baut fail() sie neu auf.
        if ($w['role'] === 'inbox') $this->queueConfirmations($w, $uids);
    }

    /** Kontakthistorie: Adressen der neuen Nachrichten den Mandanten melden */
    private function notifyHistory(array &$w, array $uids): void {
        $addresses = $w['imap']->fetchEnvelopeAddresses($uids);
        if (!$addresses) return;
        foreach ($w['clients'] as $id) {
            $client = $this->clients[$id] ?? null;
            if (!$client || !$client['pdo']) continue;
            try {
                $stmt = $client['pdo']->prepare('SELECT contact_history_email_notify(ARRAY(SELECT json_array_elements_text(CAST(:addresses AS json))))');
                $stmt->execute([':addresses' => json_encode($addresses)]);
                $n = (int)$stmt->fetchColumn();
                if ($n) emailLog("[{$client['row']['dbname']}] " . count($uids) . " neue E-Mail(s) in {$w['folder']} → $n Kunde(n)/Lieferant(en)");
                unset($this->lastError['notify' . $id]);
            } catch (Throwable $e) {
                // Fehlt contact_history_email_notify(), ist der crm-Upstall dort noch nicht gelaufen
                $this->error('notify' . $id, "[{$client['row']['dbname']}] " . $e->getMessage());
                $this->clients[$id]['pdo'] = null;
                $this->lastRefresh = 0;
            }
        }
    }

    /**
     * Auftragsbestätigung: Nachrichten von Kunden mit offenem Auftragseingang
     * in die Warteschlange stellen (je Mandant, in dem das Postfach eingerichtet ist)
     */
    private function queueConfirmations(array &$w, array $uids): void {
        // Nur Mandanten mit eingeschalteter Erkennung — sonst kein zusätzlicher IMAP-Abruf
        $clients = [];
        foreach ($w['clients'] as $id) {
            $client = $this->clients[$id] ?? null;
            if (!$client || !$client['pdo']) continue;
            try {
                if ($client['pdo']->query('SELECT oe_confirmation_mode()')->fetchColumn() !== 'off') $clients[] = $id;
            } catch (Throwable $e) {
                // crm-Upstall dort noch nicht gelaufen — dieser Mandant prüft (noch) nicht
            }
        }
        if (!$clients) return;
        $senders = $w['imap']->fetchFromAddresses($uids);
        $from = array_values(array_unique($senders));
        if (!$from) return;
        $headers = $w['imap']->fetchHeaders(array_keys($senders));
        $bodies = [];
        foreach ($clients as $id) {
            $client = $this->clients[$id];
            try {
                $stmt = $client['pdo']->prepare('SELECT array_to_json(oe_confirmation_email_wanted(ARRAY(SELECT json_array_elements_text(CAST(:addresses AS json)))))');
                $stmt->execute([':addresses' => json_encode($from)]);
                $wanted = json_decode((string)$stmt->fetchColumn(), true) ?: [];
                if (!$wanted) continue;
                $n = 0;
                foreach ($senders as $uid => $sender) {
                    if (!in_array($sender, $wanted, true)) continue;
                    $h = $headers[$uid] ?? [];
                    // Sehr große Nachrichten (Anhänge) nicht laden — eine Zusage steht im Text
                    if (($h['size'] ?? 0) > EMAIL_CONFIRM_MAX_BYTES) {
                        emailLog("[{$client['row']['dbname']}] UID $uid zu groß für die Prüfung auf Auftragsbestätigung");
                        continue;
                    }
                    if (!isset($bodies[$uid])) {
                        $msg = $w['imap']->fetchMessage((int)$uid, true);
                        $text = trim($msg['body_text'] ?? '') !== ''
                            ? $msg['body_text']
                            : html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", $msg['body_html'] ?? '')));
                        $bodies[$uid] = mb_substr(trim($text), 0, 8000);
                    }
                    $ts = strtotime($h['date'] ?? '') ?: time();
                    $enq = $client['pdo']->prepare('SELECT oe_confirmation_email_enqueue(:from, :ref, to_timestamp(:ts), :subject, :body)');
                    $enq->execute([':from' => $sender, ':ref' => $w['account'] . '|' . $w['folder'] . '|' . $uid, ':ts' => $ts,
                                   ':subject' => $h['subject'] ?? '', ':body' => $bodies[$uid]]);
                    $n += (int)$enq->fetchColumn();
                }
                if ($n) emailLog("[{$client['row']['dbname']}] $n E-Mail(s) zur Prüfung auf Auftragsbestätigung");
                unset($this->lastError['confirm' . $id]);
            } catch (Throwable $e) {
                // Fehlen die Funktionen, ist der crm-Upstall dort noch nicht gelaufen
                $this->error('confirm' . $id, "[{$client['row']['dbname']}] Auftragsbestätigung: " . $e->getMessage());
            }
        }
    }

    private function fail(string $wkey, array &$w, Throwable $e): void {
        $this->error($wkey, "[$wkey] " . $e->getMessage() . ' — neuer Versuch in ' . EMAIL_RETRY_SEC . ' s');
        $this->close($w);
        $w['imap'] = null;
        $w['retryAt'] = microtime(true) + EMAIL_RETRY_SEC;
    }

    private function close(array $w): void {
        if ($w['imap']) {
            try { $w['imap']->disconnect(); } catch (Throwable $e) {}
        }
    }

    private function error(string $key, string $text): void {
        if (($this->lastError[$key] ?? '') === $text) return;
        $this->lastError[$key] = $text;
        emailLog('[FEHLER] ' . $text);
    }
}

$dienst = new EmailEingangDienst($onlyId);

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, function () use ($dienst) { $dienst->stop = true; });
    }
}

$dienst->run();
