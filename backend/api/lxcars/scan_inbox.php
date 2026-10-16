<?php
// backend/api/lxcars/scan_inbox.php
//
// Eingangsverzeichnis für Fahrzeugscheine (Dokumentenscanner)
//
// Ein Dokumentenscanner (z. B. ScanSnap iX1600) legt den gescannten Fahrzeugschein
// direkt in das Eingangsverzeichnis des Mandanten (Standard
// /home/<oserp-benutzer>/Fahrzeugscheine/<db>, änderbar in der Firmenkonfiguration). Der
// Dienst oserp-fahrzeugschein-eingang (backend/cli/fahrzeugschein-eingang.php)
// bemerkt die Datei per inotify sofort, schickt sie durch die konfigurierte
// Erkennung (eigener Scanner oder fahrzeugschein-scanner.de) und stellt das
// Ergebnis in die Scanliste — dort erscheint es per SSE live, als wäre es
// hochgeladen worden.
//
// Hier liegen die gemeinsamen Helfer (Pfade, Verarbeitung einer Datei, Herzschlag
// des Dienstes) und die API für die Statusanzeige in der Firmenkonfiguration.

const SCAN_INBOX_HEARTBEAT   = '.oserp-eingang.json';   // Herzschlag des Dienstes im Eingangsverzeichnis
const SCAN_INBOX_KEEP_DAYS   = 30;                      // verarbeitete Dateien so lange aufheben
const SCAN_INBOX_ALIVE_SEC   = 150;                     // Herzschlag älter → Dienst gilt als nicht aktiv
const SCAN_INBOX_EXTENSIONS  = ['jpg', 'jpeg', 'png', 'pdf'];

/**
 * Einprägsamer Standardpfad: <Home des OSERP-Benutzers>/Fahrzeugscheine/<Mandanten-DB>,
 * z. B. /home/work/Fahrzeugscheine/ap_rebuild.
 *
 * Der Benutzer ergibt sich aus dem Eigentümer des Projektverzeichnisses — so ist der
 * Pfad im Webserver (www-data) und im Dienst (OSERP-Benutzer) derselbe.
 *
 * @param string $dbname Mandanten-Datenbank
 */
function scanInboxDefaultDir(string $dbname): string {
    $root = realpath(__DIR__ . '/../../..');
    $home = null;
    if (function_exists('posix_getpwuid')) {
        $home = posix_getpwuid(fileowner($root))['dir'] ?? null;
    }
    return rtrim($home ?: dirname($root), '/') . '/Fahrzeugscheine/' . $dbname;
}

/**
 * Pfade des Eingangsverzeichnisses eines Mandanten.
 *
 * Der überwachte Pfad steht in defaults_oserp.lxcars_scan_inbox_dir; leer =
 * scanInboxDefaultDir(). Unterordner verarbeitet/ und fehler/.
 *
 * @param object      $db       Company-Datenbankverbindung
 * @param string      $dbname   Mandanten-Datenbank
 * @param string|null $override Pfad aus einem noch nicht gespeicherten Formularfeld; null = aus der DB
 * @return array {dir, done, failed, default_dir, custom}
 */
function scanInboxPaths($db, string $dbname, $override = null) {
    if ($override === null) {
        $row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'lxcars_scan_inbox_dir'", []);
        $override = $row['value'] ?? '';
    }
    $override = trim((string)$override);
    $default = scanInboxDefaultDir($dbname);
    $dir = $override !== '' ? rtrim($override, '/') : $default;
    return [
        'dir'         => $dir,
        'done'        => $dir . '/verarbeitet',
        'failed'      => $dir . '/fehler',
        'default_dir' => $default,
        'custom'      => $dir !== $default,
    ];
}

/**
 * Legt Eingangs- und Unterverzeichnisse an.
 *
 * Gruppenschreibbar mit gesetztem Gruppenbit (02775): Scanner-Freigabe, Webserver
 * und der Dienst arbeiten als verschiedene Benutzer im selben Verzeichnis.
 *
 * @return bool Eingangsverzeichnis vorhanden und beschreibbar
 */
function scanInboxEnsureDirs(array $paths): bool {
    foreach ([$paths['dir'], $paths['done'], $paths['failed']] as $d) {
        if (!is_dir($d)) {
            if (!@mkdir($d, 02775, true)) return false;
            @chmod($d, 02775);
        }
    }
    return is_writable($paths['dir']);
}

/**
 * Neue Dateien im Eingangsverzeichnis (ohne Unterordner, versteckte und Systemdateien).
 *
 * @return string[] vollständige Pfade
 */
function scanInboxListFiles(string $dir): array {
    $files = [];
    $entries = @scandir($dir) ?: [];
    foreach ($entries as $e) {
        if ($e === '' || $e[0] === '.' || in_array(strtolower($e), ['thumbs.db', 'desktop.ini'], true)) continue;
        $path = $dir . '/' . $e;
        if (is_file($path)) $files[] = $path;
    }
    return $files;
}

/**
 * Verarbeitet eine Datei aus dem Eingangsverzeichnis.
 *
 * Erkennt den Fahrzeugschein über _scanFahrzeugscheinRun (Scanliste, tmp-Cache,
 * SSE-Trigger wie beim Upload) und verschiebt die Datei nach verarbeitet/ bzw.
 * fehler/ (dort mit <datei>.fehler.txt als Begründung). PDF-Scans werden vorab
 * mit pdftoppm zur JPEG-Seite gerastert, damit die Scanliste auch für PDF ein
 * Originalbild hat; fehlt pdftoppm, geht das PDF unverändert an den Scanner.
 *
 * @param object $db    Company-Datenbankverbindung des Mandanten
 * @param array  $paths scanInboxPaths()
 * @param string $file  vollständiger Pfad der Datei
 * @return array {ok, target, scan_id?, plate?, images?, error?, message?}
 */
function scanInboxProcessFile($db, array $paths, string $file): array {
    $name  = basename($file);
    $ext   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $stamp = date('Ymd-His');

    $fail = function (string $code, string $message) use ($paths, $file, $name, $stamp) {
        $target = $paths['failed'] . '/' . $stamp . '_' . $name;
        if (!@rename($file, $target)) $target = $file;
        @file_put_contents($target . '.fehler.txt', date('Y-m-d H:i:s') . "  $code\n$message\n");
        return ['ok' => false, 'target' => $target, 'error' => $code, 'message' => $message];
    };

    if (!in_array($ext, SCAN_INBOX_EXTENSIONS, true)) {
        return $fail('UNSUPPORTED_TYPE', 'Dateityp nicht unterstützt (' . implode(', ', SCAN_INBOX_EXTENSIONS) . ')');
    }
    $raw = @file_get_contents($file);
    if ($raw === false || strlen($raw) < 100) {
        return $fail('FILE_UNREADABLE', 'Datei nicht lesbar oder leer');
    }

    $isPdf = $ext === 'pdf';
    if ($isPdf) {
        $jpeg = _scanInboxPdfToJpeg($file);
        if ($jpeg !== null) {
            $raw = $jpeg;
            $isPdf = false;
        }
    }

    $run = _scanFahrzeugscheinRun($db, base64_encode($raw), $isPdf, [
        'filename'  => $name,
        'temp_file' => false,     // Original liegt im tmp-Cache (original.jpg) und unter verarbeitet/
        'id_prefix' => 'inbox_',
    ]);
    if (!$run['ok']) {
        return $fail($run['error'], $run['message']);
    }

    $target = $paths['done'] . '/' . $stamp . '_' . $name;
    if (!@rename($file, $target)) $target = $file;
    return [
        'ok'      => true,
        'target'  => $target,
        'scan_id' => $run['payload']['scan_id'],
        'plate'   => trim((string)($run['payload']['car']['c_ln'] ?? '')),
        'images'  => count($run['payload']['images']),
    ];
}

/**
 * Erste PDF-Seite als JPEG (200 dpi) über pdftoppm; null wenn nicht möglich.
 */
function _scanInboxPdfToJpeg(string $pdf): ?string {
    $bin = trim((string)@shell_exec('command -v pdftoppm 2>/dev/null'));
    if ($bin === '') return null;
    $prefix = sys_get_temp_dir() . '/oserp-eingang-' . bin2hex(random_bytes(6));
    $cmd = escapeshellcmd($bin) . ' -f 1 -l 1 -r 200 -jpeg -jpegopt quality=90 '
         . escapeshellarg($pdf) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null';
    @exec($cmd, $out, $rc);
    $made = glob($prefix . '*.jpg') ?: [];
    $data = null;
    if ($rc === 0 && $made) {
        $data = @file_get_contents($made[0]) ?: null;
    }
    foreach ($made as $m) @unlink($m);
    return $data;
}

/**
 * Herzschlag des Dienstes: wird im Eingangsverzeichnis abgelegt, die
 * Firmenkonfiguration zeigt daraus Dienststatus und letzte Verarbeitung.
 *
 * @param array $paths scanInboxPaths()
 * @param array $state {method, last?: {file, ok, time, scan_id|message}}
 */
function scanInboxHeartbeat(array $paths, array $state): void {
    $state['time'] = date('c');
    $state['pid']  = getmypid();
    $state['host'] = gethostname();
    @file_put_contents($paths['dir'] . '/' . SCAN_INBOX_HEARTBEAT, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Räumt verarbeitet/ auf (älter als SCAN_INBOX_KEEP_DAYS) und löscht verwaiste
 * Fehlerhinweise, deren Datei der Benutzer bereits entfernt hat.
 */
function scanInboxCleanup(array $paths): void {
    $limit = time() - SCAN_INBOX_KEEP_DAYS * 86400;
    foreach (glob($paths['done'] . '/*') ?: [] as $f) {
        if (is_file($f) && @filemtime($f) < $limit) @unlink($f);
    }
    foreach (glob($paths['failed'] . '/*.fehler.txt') ?: [] as $note) {
        if (!is_file(substr($note, 0, -strlen('.fehler.txt')))) @unlink($note);
    }
}

/**
 * Status des Eingangsverzeichnisses für Fahrzeugscheine (Firmenkonfiguration → LxCars).
 *
 * Liefert Pfad (und Standardpfad), Erreichbarkeit, Dienstzustand (Herzschlag des Dienstes
 * oserp-fahrzeugschein-eingang), wartende Dateien, letzte Verarbeitung und die
 * jüngsten Fehler mit Begründung.
 *
 * @param string $data['dir'] Optional: Pfad aus dem Konfigurationsfeld (noch nicht gespeichert); leer = Standard
 * @testdata {"dir": ""}
 */
function getScanInboxStatus($data) {
    $db = DbhCompany::begin();
    $row = $db->getOne("SELECT current_database() AS dbname");
    $paths = scanInboxPaths($db, $row['dbname'], array_key_exists('dir', $data) ? (string)$data['dir'] : null);

    $exists   = is_dir($paths['dir']);
    $writable = $exists && is_writable($paths['dir']);

    $service = ['alive' => false, 'time' => null, 'method' => null, 'host' => null];
    $last = null;
    $hb = $exists ? @file_get_contents($paths['dir'] . '/' . SCAN_INBOX_HEARTBEAT) : false;
    if ($hb !== false && ($state = json_decode($hb, true))) {
        $age = time() - strtotime($state['time'] ?? '1970-01-01');
        $service = [
            'alive'  => $age >= 0 && $age <= SCAN_INBOX_ALIVE_SEC,
            'time'   => $state['time'] ?? null,
            'method' => $state['method'] ?? null,
            'host'   => $state['host'] ?? null,
        ];
        $last = $state['last'] ?? null;
    }

    $failed = [];
    if ($exists) {
        $notes = glob($paths['failed'] . '/*.fehler.txt') ?: [];
        usort($notes, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($notes, 0, 10) as $note) {
            $lines = file($note, FILE_IGNORE_NEW_LINES) ?: [];
            $failed[] = [
                'file'    => basename(substr($note, 0, -strlen('.fehler.txt'))),
                'time'    => date('c', filemtime($note)),
                'message' => trim(implode(' ', array_slice($lines, 1))) ?: trim($lines[0] ?? ''),
            ];
        }
    }

    resultInfo(true, '', [
        'dir'         => $paths['dir'],
        'default_dir' => $paths['default_dir'],
        'custom'      => $paths['custom'],
        'exists'     => $exists,
        'writable'   => $writable,
        'service'    => $service,
        'last'       => $last,
        'pending'    => $exists ? count(scanInboxListFiles($paths['dir'])) : 0,
        'done_count' => $exists ? count(glob($paths['done'] . '/*') ?: []) : 0,
        'failed'     => $failed,
        'extensions' => SCAN_INBOX_EXTENSIONS,
        'keep_days'  => SCAN_INBOX_KEEP_DAYS,
    ]);
}
