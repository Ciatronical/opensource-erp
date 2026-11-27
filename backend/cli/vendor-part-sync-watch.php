#!/usr/bin/env php
<?php
// backend/cli/vendor-part-sync-watch.php
//
// Wiederanlauf des Artikelabgleichs: schließt Läufe ab, deren Läufer kein
// Lebenszeichen mehr gibt (Neustart des Servers, längerer Ausfall der
// Datenbank, abgestürzter Prozess), und startet für jeden eine Fortsetzung
// mit denselben Einstellungen. Was fortgesetzt wird und was nicht, entscheidet
// die Datenbank: vendor_part_sync_resume().
//
// Aufruf:
//   php backend/cli/vendor-part-sync-watch.php --db=<firmendatenbank> [--quiet]
//
// --quiet schreibt nur, wenn etwas geschehen ist — für den Cron:
//   */5 * * * * php …/backend/cli/vendor-part-sync-watch.php --db=<firmendatenbank> --quiet >>…/backend/log/vendor-part-sync-watch.log 2>&1
//
// Der Aufruf muss als Benutzer des Webservers laufen, wie der Läufer selbst
// (Arbeitsdateien in backend/tmp, Konfiguration des Abrufprogramms).

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "Nur auf der Kommandozeile.\n");
    exit(1);
}

require_once __DIR__.'/../api/config.php';
OserpConfig::init();
require_once __DIR__.'/../api/error.php';
require_once __DIR__.'/../api/logging.php';
require_once __DIR__.'/../api/database.php';
// Frist ohne Lebenszeichen, Arbeitsdateien und Start des Läufers wie in der Oberfläche
require_once __DIR__.'/../api/parts/vendor_part_sync.php';

$argumente = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $treffer)) {
        $argumente[$treffer[1]] = $treffer[2] ?? true;
    }
}
$datenbank = is_string($argumente['db'] ?? null) ? $argumente['db'] : '';
$leise     = isset($argumente['quiet']);

if ('' === $datenbank) {
    fwrite(STDERR, "Aufruf: php backend/cli/vendor-part-sync-watch.php --db=<firmendatenbank> [--quiet]\n");
    exit(1);
}

$melden = function (string $zeile) {
    echo date('Y-m-d H:i:s').'  '.$zeile."\n";
};

// ── Verbindung zum Mandanten ──

try {
    $authPdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME),
        DB_AUTH_USER, DB_AUTH_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $abfrage = $authPdo->prepare("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE dbname = :dbname ORDER BY id LIMIT 1");
    $abfrage->execute([':dbname' => $datenbank]);
    $mandant = $abfrage->fetch(PDO::FETCH_ASSOC);
    if (!$mandant) {
        fwrite(STDERR, "Kein Mandant mit der Datenbank $datenbank.\n");
        exit(1);
    }
    DbhCompany::begin(new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $mandant['dbhost'], $mandant['dbport'], $mandant['dbname']),
        $mandant['dbuser'], $mandant['dbpasswd'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    ));
    $db = DbhCompany::begin();
    DbhCompany::setActor($db, null, 'cron:vendor-part-sync-watch');

    $laeufe = $db->getAll(
        "SELECT old_id, old_pid, new_id FROM vendor_part_sync_resume(CAST(:stall_minutes AS integer))",
        [':stall_minutes' => VENDOR_PART_SYNC_STALL_MINUTES]
    );
} catch (Throwable $e) {
    // Datenbank nicht erreichbar: beim nächsten Aufruf wieder
    $melden('Die Datenbank ist nicht erreichbar: '.$e->getMessage());
    exit(1);
}

if (!$laeufe) {
    if (!$leise) {
        $melden('Kein hängengebliebener Lauf.');
    }
    exit(0);
}

$fehler = 0;
foreach ($laeufe as $lauf) {
    $alt = intval($lauf['old_id']);
    $neu = intval($lauf['new_id'] ?? 0);
    $pid = intval($lauf['old_pid'] ?? 0);

    // Lebt der alte Läufer doch noch (hängt, statt beendet zu sein), samt
    // Abrufprogramm beenden — sonst arbeiteten zwei am selben Lieferanten.
    // Nur wenn der Prozess wirklich der Läufer dieses Laufs ist.
    $befehl = $pid > 0 ? (string)@file_get_contents('/proc/'.$pid.'/cmdline') : '';
    if (str_contains($befehl, 'vendor-part-sync.php') && str_contains($befehl, '--sync='.$alt."\0") && function_exists('posix_kill')) {
        // Der Läufer führt seine eigene Prozessgruppe (setsid beim Start)
        @posix_kill(-$pid, SIGTERM) || @posix_kill($pid, SIGTERM);
        $melden("Lauf $alt: alter Läufer (Prozess $pid) lebte noch und wurde beendet.");
    }

    if ($neu <= 0) {
        $melden("Lauf $alt ohne Lebenszeichen abgeschlossen, keine Fortsetzung.");
        continue;
    }

    $dateien = vendorPartSyncFiles($datenbank, $neu);
    $start = backgroundStartPhp(__DIR__.'/vendor-part-sync.php', ['--db='.$datenbank, '--sync='.$neu], $dateien['out']);
    if ('' !== $start['fehler']) {
        $db->execute(
            "UPDATE vendor_part_syncs SET status = 'error', message = :message, finished_at = now() WHERE id = :id",
            [':message' => $start['fehler'], ':id' => $neu]
        );
        $melden("Lauf $alt ohne Lebenszeichen abgeschlossen; Fortsetzung $neu ließ sich nicht starten: ".$start['fehler']);
        $fehler++;
        continue;
    }
    $melden("Lauf $alt ohne Lebenszeichen abgeschlossen, Fortsetzung als Lauf $neu gestartet.");
}

exit($fehler ? 1 : 0);
