#!/usr/bin/env php
<?php
/**
 * Eingangsverzeichnis für Fahrzeugscheine — Überwachungsdienst (kein Cron)
 *
 * Läuft dauerhaft als systemd-Dienst oserp-fahrzeugschein-eingang und überwacht
 * für jeden Mandanten mit aktiver LxCars-Erweiterung dessen Eingangsverzeichnis
 * (Standard /home/<oserp-benutzer>/Fahrzeugscheine/<db>, Firmenkonfiguration →
 * LxCars, defaults_oserp.lxcars_scan_inbox_dir). Ein Dokumentenscanner — etwa
 * der ScanSnap iX1600 — legt den Fahrzeugschein dort ab;
 * die Datei wird SOFORT erkannt (inotify), durch den konfigurierten Scanner
 * geschickt und in die Scanliste gestellt (SSE → alle offenen Scanlisten).
 *
 * Erkennung neuer Dateien:
 *   - inotify über PHP-FFI direkt auf libc (kein Zusatzpaket, kein Leerlauf-CPU).
 *     Braucht ffi.enable=true (die Unit startet php mit -d ffi.enable=true).
 *   - Ohne FFI: Verzeichnisabfrage im Sekundentakt (gleicher Ablauf, ~1 s später).
 *   In beiden Fällen werden vorhandene Dateien beim Start und alle 60 s mit
 *   erfasst (Dienst war aus, Mandant neu aktiviert). Eine Datei wird erst
 *   verarbeitet, wenn ihre Größe eine Sekunde lang unverändert ist — der
 *   Scanner schreibt sonst womöglich noch.
 *
 * Ergebnis je Datei: verarbeitet/<zeit>_<name> bzw. fehler/<zeit>_<name> plus
 * <name>.fehler.txt mit der Begründung. verarbeitet/ wird nach 30 Tagen geleert.
 * Ein Herzschlag (.oserp-eingang.json) im Eingangsverzeichnis speist die
 * Statusanzeige in Firmenkonfiguration → LxCars.
 *
 * Aufruf:
 *   php -d ffi.enable=true backend/cli/fahrzeugschein-eingang.php   (Dienst)
 *   ... --once        vorhandene Dateien verarbeiten und beenden
 *   ... --client 10   nur ein Mandant (auth.clients.id)
 *   ... --poll        Verzeichnisabfrage statt inotify erzwingen
 *
 * Unit-Vorlage: install/oserp-fahrzeugschein-eingang.service
 * Installer:    install/install.sh --only fseingang
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur über die Kommandozeile ausgeführt werden.\n");
}

// Komplettes LxCars-Modul laden (Scan-Logik, Scanliste, Dateimanager). inc.php am
// Ende von index.php will einen HTTP-Request bedienen und meldet "keine Aktion" —
// das wird verschluckt.
ob_start();
require_once dirname(__DIR__) . '/api/lxcars/index.php';
ob_get_clean();

const EINGANG_REFRESH_SEC = 60;     // Mandantenliste/Verzeichnisse neu einlesen, Dateien nachsammeln
const EINGANG_SETTLE_SEC  = 1.0;    // Dateigröße so lange unverändert → fertig geschrieben
const EINGANG_POLL_MS     = 500;    // Wartezeit je Schleifendurchlauf

$once   = in_array('--once', $argv, true);
$poll   = in_array('--poll', $argv, true);
$onlyId = 0;
foreach ($argv as $i => $a) {
    if ($a === '--client' && isset($argv[$i + 1])) $onlyId = intval($argv[$i + 1]);
}

function eingangLog(string $text): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n";
    flush();
}

/**
 * inotify über FFI auf libc. Gibt [FFI, fd] zurück oder null (kein FFI, ffi.enable
 * nicht gesetzt, kein inotify) — dann läuft die Verzeichnisabfrage.
 */
function eingangInotifyOpen(): ?array {
    if (!class_exists('FFI')) return null;
    try {
        $ffi = FFI::cdef("
            int inotify_init1(int flags);
            int inotify_add_watch(int fd, const char *pathname, unsigned int mask);
            int inotify_rm_watch(int fd, int wd);
            long read(int fd, void *buf, unsigned long count);
            struct pollfd { int fd; short events; short revents; };
            int poll(struct pollfd *fds, unsigned long nfds, int timeout);
        ", "libc.so.6");
    } catch (Throwable $e) {
        eingangLog('inotify nicht verfügbar (' . $e->getMessage() . ') — Verzeichnisabfrage im Sekundentakt');
        return null;
    }
    $fd = $ffi->inotify_init1(0x800); // IN_NONBLOCK
    if ($fd < 0) {
        eingangLog('inotify_init1 fehlgeschlagen — Verzeichnisabfrage im Sekundentakt');
        return null;
    }
    return [$ffi, $fd];
}

/**
 * Der Dienst selbst: Mandanten, Überwachungen, wartende Dateien.
 */
class EingangDienst {
    private ?FFI $ffi = null;
    private int $fd = -1;
    private bool $once;
    private int $onlyId;
    /** @var array<int, array> auth.clients-Zeile + db, paths, wd, last */
    private array $clients = [];
    /** @var array<int, int> inotify-wd → client-id */
    private array $watches = [];
    /** @var array<string, array> Datei → {client, size, since} */
    private array $pending = [];
    private float $lastRefresh = 0;
    private float $lastSweep = 0;
    /** @var array<int, string> letzte Fehlermeldung je Mandant — gleiche Meldung nur einmal protokollieren */
    private array $lastError = [];
    public bool $stop = false;

    public function __construct(bool $once, int $onlyId, bool $forcePoll) {
        $this->once = $once;
        $this->onlyId = $onlyId;
        if (!$forcePoll && !$once) {
            $io = eingangInotifyOpen();
            if ($io) { [$this->ffi, $this->fd] = $io; }
        }
    }

    public function method(): string {
        return $this->ffi ? 'inotify' : 'poll';
    }

    /** Hauptschleife */
    public function run(): void {
        eingangLog('Fahrzeugschein-Eingang gestartet (' . $this->method() . ')');
        $this->refresh();
        if ($this->once) {
            // Alles verarbeiten, was jetzt da ist, und beenden
            while ($this->pending) { $this->processReady(true); }
            eingangLog('Fertig (--once)');
            return;
        }
        while (!$this->stop) {
            if ($this->ffi) {
                $this->readEvents(EINGANG_POLL_MS);
            } else {
                usleep(EINGANG_POLL_MS * 1000);
                if (microtime(true) - $this->lastSweep >= 1.0) $this->sweep();
            }
            $this->processReady(false);
            if (microtime(true) - $this->lastRefresh >= EINGANG_REFRESH_SEC) $this->refresh();
        }
        eingangLog('Fahrzeugschein-Eingang beendet');
    }

    /**
     * Mandantenliste neu einlesen: Verzeichnisse anlegen, Überwachungen
     * anpassen, vorhandene Dateien aufnehmen, Herzschlag und Aufräumen.
     */
    private function refresh(): void {
        $this->lastRefresh = microtime(true);
        try {
            $auth = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
            $rows = $auth->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            eingangLog('[FEHLER] Auth-DB nicht erreichbar: ' . $e->getMessage());
            return;
        }

        $seen = [];
        foreach ($rows as $row) {
            $id = intval($row['id']);
            if ($this->onlyId && $id !== $this->onlyId) continue;
            try {
                $client = $this->clients[$id] ?? null;
                if (!$client || !$client['pdo']) {
                    $pdo = connectPDO($row['dbhost'], $row['dbport'], $row['dbname'], $row['dbuser'], $row['dbpasswd']);
                    $client = ['row' => $row, 'pdo' => $pdo, 'db' => new ApiDatabase($pdo), 'wd' => null, 'paths' => null];
                }
                $this->activate($client);
                if (!isExtensionActive($client['db'], 'lxcars')) {
                    $this->forget($id, 'LxCars nicht aktiv');
                    continue;
                }
                $paths = scanInboxPaths($client['db'], $row['dbname']);
                if (!scanInboxEnsureDirs($paths)) {
                    $this->forget($id, 'Verzeichnis nicht beschreibbar: ' . $paths['dir']);
                    continue;
                }
                if (!$client['paths'] || $client['paths']['dir'] !== $paths['dir']) {
                    // neu oder Pfad geändert → Überwachung (neu) einrichten
                    if ($client['wd'] !== null) $this->removeWatch($client['wd']);
                    $client['wd'] = $this->addWatch($id, $paths['dir']);
                    $client['paths'] = $paths;
                    eingangLog("[{$row['dbname']}] überwacht {$paths['dir']}");
                }
                $this->clients[$id] = $client;
                $seen[$id] = true;
                unset($this->lastError[$id]);
                foreach (scanInboxListFiles($paths['dir']) as $f) $this->enqueue($id, $f);
                scanInboxCleanup($paths);
                scanInboxHeartbeat($paths, ['method' => $this->method(), 'last' => $client['last'] ?? null]);
            } catch (Throwable $e) {
                // Dauerzustände (z. B. gelöschte Mandanten-DB) nicht jede Minute wiederholen
                if (($this->lastError[$id] ?? '') !== $e->getMessage()) {
                    $this->lastError[$id] = $e->getMessage();
                    eingangLog("[{$row['dbname']}] [FEHLER] " . $e->getMessage());
                }
                $this->forget($id, null);
            } finally {
                $this->deactivate();
            }
        }
        foreach (array_keys($this->clients) as $id) {
            if (!isset($seen[$id])) $this->forget($id, 'Mandant entfernt');
        }
        $this->lastSweep = microtime(true);
    }

    /** Überwachung eines Mandanten beenden (Verzeichnis bleibt bestehen) */
    private function forget(int $id, ?string $why): void {
        if (!isset($this->clients[$id])) return;
        $c = $this->clients[$id];
        if ($c['wd'] !== null) $this->removeWatch($c['wd']);
        if ($why && $c['paths']) eingangLog("[{$c['row']['dbname']}] Überwachung beendet: $why");
        foreach ($this->pending as $file => $p) {
            if ($p['client'] === $id) unset($this->pending[$file]);
        }
        unset($this->clients[$id]);
    }

    private function addWatch(int $id, string $dir): ?int {
        if (!$this->ffi) return null;
        $wd = $this->ffi->inotify_add_watch($this->fd, $dir, 0x8 | 0x80); // IN_CLOSE_WRITE | IN_MOVED_TO
        if ($wd < 0) {
            eingangLog("[FEHLER] inotify_add_watch für $dir fehlgeschlagen");
            return null;
        }
        $this->watches[$wd] = $id;
        return $wd;
    }

    private function removeWatch(int $wd): void {
        if ($this->ffi) $this->ffi->inotify_rm_watch($this->fd, $wd);
        unset($this->watches[$wd]);
    }

    /** inotify-Ereignisse lesen (wartet bis zu $timeoutMs) */
    private function readEvents(int $timeoutMs): void {
        $pfd = $this->ffi->new('struct pollfd');
        $pfd->fd = $this->fd;
        $pfd->events = 1; // POLLIN
        $n = $this->ffi->poll(FFI::addr($pfd), 1, $this->pending ? min($timeoutMs, 200) : $timeoutMs);
        if ($n <= 0) return;
        $buf = $this->ffi->new('char[16384]');
        $len = $this->ffi->read($this->fd, $buf, 16384);
        if ($len <= 0) return;
        $bytes = FFI::string($buf, $len);
        $off = 0;
        while ($off + 16 <= $len) {
            $h = unpack('lwd/Lmask/Lcookie/Llen', substr($bytes, $off, 16));
            $name = rtrim(substr($bytes, $off + 16, $h['len']), "\0");
            $off += 16 + $h['len'];
            if ($name === '' || !isset($this->watches[$h['wd']])) continue;
            if ($h['mask'] & 0x40000000) continue; // IN_ISDIR
            $id = $this->watches[$h['wd']];
            $c = $this->clients[$id] ?? null;
            if (!$c) continue;
            $file = $c['paths']['dir'] . '/' . $name;
            if ($name[0] === '.' || !is_file($file)) continue;
            $this->enqueue($id, $file);
        }
    }

    /** Verzeichnisabfrage (ohne inotify) */
    private function sweep(): void {
        $this->lastSweep = microtime(true);
        foreach ($this->clients as $id => $c) {
            foreach (scanInboxListFiles($c['paths']['dir']) as $f) $this->enqueue($id, $f);
        }
    }

    private function enqueue(int $id, string $file): void {
        if (isset($this->pending[$file])) return;
        $this->pending[$file] = ['client' => $id, 'size' => -1, 'since' => microtime(true)];
    }

    /**
     * Wartende Dateien verarbeiten, sobald ihre Größe EINGANG_SETTLE_SEC lang
     * unverändert ist ($now = sofort, für --once).
     */
    private function processReady(bool $now): void {
        $t = microtime(true);
        foreach ($this->pending as $file => $p) {
            clearstatcache(true, $file);
            if (!is_file($file)) { unset($this->pending[$file]); continue; }
            $size = filesize($file);
            if ($size !== $p['size']) {
                $this->pending[$file]['size'] = $size;
                $this->pending[$file]['since'] = $t;
                if (!$now) continue;
            } elseif (!$now && $t - $p['since'] < EINGANG_SETTLE_SEC) {
                continue;
            }
            unset($this->pending[$file]);
            $this->process($p['client'], $file);
        }
        if ($now) usleep(200000);
    }

    /** Eine Datei durch die Erkennung schicken */
    private function process(int $id, string $file): void {
        $c = $this->clients[$id] ?? null;
        if (!$c) return;
        $db = $c['row']['dbname'];
        $t0 = microtime(true);
        eingangLog("[$db] neue Datei " . basename($file));
        try {
            $this->activate($c);
            $res = scanInboxProcessFile($c['db'], $c['paths'], $file);
            $secs = round(microtime(true) - $t0, 1);
            if ($res['ok']) {
                eingangLog("[$db] erkannt in {$secs}s: " . ($res['plate'] !== '' ? $res['plate'] : '(ohne Kennzeichen)')
                    . ", {$res['images']} Bildausschnitte → Scanliste {$res['scan_id']}");
            } else {
                eingangLog("[$db] FEHLER {$res['error']}: {$res['message']} → " . $res['target']);
            }
            $this->clients[$id]['last'] = [
                'file' => basename($file), 'ok' => $res['ok'], 'time' => date('c'),
                'scan_id' => $res['scan_id'] ?? null, 'plate' => $res['plate'] ?? null,
                'message' => $res['ok'] ? null : $res['message'],
            ];
            scanInboxHeartbeat($c['paths'], ['method' => $this->method(), 'last' => $this->clients[$id]['last']]);
        } catch (Throwable $e) {
            eingangLog("[$db] [FEHLER] " . $e->getMessage());
            // Verbindung verloren? Beim nächsten Durchlauf neu aufbauen, Datei bleibt liegen
            $this->clients[$id]['pdo'] = null;
            $this->lastRefresh = 0;
        } finally {
            $this->deactivate();
        }
    }

    /** DbhCompany auf die Verbindung des Mandanten setzen (wie backend/cli/belegsuche.php) */
    private function activate(array $client): void {
        $prop = (new ReflectionClass('DbhCompany'))->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $client['db']);
    }

    private function deactivate(): void {
        $prop = (new ReflectionClass('DbhCompany'))->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}

$dienst = new EingangDienst($once, $onlyId, $poll);

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, function () use ($dienst) { $dienst->stop = true; });
    }
}

$dienst->run();
