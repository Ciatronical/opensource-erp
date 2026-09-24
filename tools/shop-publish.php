#!/usr/bin/env php
<?php
// tools/shop-publish.php
//
// Läufer der Shop-Veröffentlichung: arbeitet die offenen Aufträge aus
// batchjob_hugoshop ab, schreibt die Inhaltsdateien und baut anschließend die
// Webseite, wenn in der settings.ini ein Befehl steht.
//
// Gedacht für einen Cron-Eintrag, etwa alle fünf Minuten. Ohne Cron genügt
// „Jetzt ausführen" im Admin-Panel, das diesen Läufer startet; dann braucht
// der Webserver-Benutzer Schreibrechte im Webseiten-Verzeichnis.
//
// Aufruf:
//   php tools/shop-publish.php [--client=<id>] [--db=<name>] [--limit=500]
//                              [--no-build] [--quiet] [--reconcile-payments]
//                              [--no-cleanup] [--ids=<nr>,<nr>,...]
//   php tools/shop-publish.php --list-clients
//
// --ids= arbeitet nur diese Aufträge ab statt aller offenen.
//
// Denselben Läufer startet auch „Jetzt ausführen" im Admin-Panel, dort als
// eigenen Prozess im Hintergrund (shopPublishStartBackground). Jeder Lauf —
// ob aus dem Cron oder aus dem Panel — schreibt deshalb seine Meldungen und
// seinen Stand nach backend/tmp/shop-publish-<db>.log und .json; die
// Oberfläche liest sie von dort.
//
// Nach jedem Lauf räumt das Skript erledigte Aufträge weg: die erfolgreich
// ausgeführten, die älter sind als die Aufbewahrungsfrist aus der
// Firmenkonfiguration (Shop, shop_job_retention_days). Steht dort 0, bleibt
// alles liegen. --no-cleanup lässt es für diesen Lauf bleiben.
//
// --reconcile-payments nimmt den Abgleich schwebender PayPal-Zahlungen als
// Auftrag an und arbeitet ihn im selben Lauf ab. Gedacht für einen eigenen,
// selteneren Cron-Eintrag:
//   */5 * * * *  php tools/shop-publish.php --client=1 --quiet
//   17 * * * *   php tools/shop-publish.php --client=1 --quiet --reconcile-payments

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "Nur auf der Kommandozeile.\n");
    exit(1);
}

require_once __DIR__.'/../backend/api/config.php';
OserpConfig::init();
require_once __DIR__.'/../backend/api/error.php';
require_once __DIR__.'/../backend/api/logging.php';
require_once __DIR__.'/../backend/api/database.php';
require_once __DIR__.'/../backend/api/shop/lib/config.php';
require_once __DIR__.'/../backend/api/shop/lib/payment.php';
require_once __DIR__.'/../backend/api/shop/lib/publish.php';
require_once __DIR__.'/../backend/api/shop/lib/categories.php';
require_once __DIR__.'/../backend/api/shop/lib/hugocms.php';

set_time_limit(0);

$argumente = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $treffer)) {
        $argumente[$treffer[1]] = $treffer[2] ?? true;
    }
}

$leise = isset($argumente['quiet']);

// Das Protokoll öffnet erst der Beginn des Laufs (unten): ein Prozess, der die
// Sperre nicht bekommt, darf das Protokoll des laufenden nicht leeren.
$protokoll = null;
$melden = function (string $zeile) use ($leise, &$protokoll) {
    $text = date('H:i:s').'  '.$zeile."\n";
    if (!$leise) {
        echo $text;
    }
    if (is_resource($protokoll)) {
        fwrite($protokoll, $text);
        fflush($protokoll);
    }
};

// Nur diese Aufträge — kommt vom Panel, das die ausgewählten Zeilen schickt
$nurIds = null;
if (isset($argumente['ids']) && is_string($argumente['ids'])) {
    $nurIds = array_values(array_filter(array_map('intval', explode(',', $argumente['ids'])), fn($id) => $id > 0));
}

// ── Mandant bestimmen ──

try {
    $authPdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME),
        DB_AUTH_USER, DB_AUTH_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $mandanten = $authPdo->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients ORDER BY id")
                         ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "Die Auth-Datenbank ist nicht erreichbar: ".$e->getMessage()."\n");
    exit(1);
}

if (isset($argumente['list-clients'])) {
    foreach ($mandanten as $m) {
        echo $m['id']."\t".$m['name']."\t".$m['dbname']."\n";
    }
    exit(0);
}

$mandant = null;
if (isset($argumente['client'])) {
    foreach ($mandanten as $m) {
        if ((string)$m['id'] === (string)$argumente['client']) { $mandant = $m; }
    }
} elseif (isset($argumente['db'])) {
    foreach ($mandanten as $m) {
        if ($m['dbname'] === $argumente['db']) { $mandant = $m; }
    }
} elseif (1 === count($mandanten)) {
    $mandant = $mandanten[0];
}

if (null === $mandant) {
    fwrite(STDERR, "Mandant angeben: --client=<id> oder --db=<name>. Liste: --list-clients\n");
    exit(1);
}

// ── Verbindung zum Mandanten ──

try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $mandant['dbhost'], $mandant['dbport'], $mandant['dbname']),
        $mandant['dbuser'], $mandant['dbpasswd'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    DbhCompany::begin($pdo);
    $db = DbhCompany::begin();

    // Vor der Sperre: läuft gerade ein anderer Lauf, erledigt er den Auftrag
    // oder spätestens der nächste. Doppelt angenommen wird er nicht.
    if (isset($argumente['reconcile-payments'])) {
        shopQueueJob($db, 'reconcile_payments');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Mandant '.$mandant['dbname'].': '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}

// ── Nur ein Lauf je Mandant ──
//
// Zwei gleichzeitige Läufe schrieben dieselben Dateien und bauten die Webseite
// doppelt; der Bau löscht dabei das ausgelieferte Verzeichnis.

$sperrVerzeichnis = __DIR__.'/../backend/tmp';
if (!is_dir($sperrVerzeichnis)) {
    mkdir($sperrVerzeichnis, 0775, true);
}
$dateien = shopPublishStateFiles($db);

$sperre = fopen($sperrVerzeichnis.'/shop-publish-'.$mandant['dbname'].'.lock', 'c');
if (false === $sperre || !flock($sperre, LOCK_EX | LOCK_NB)) {
    $melden('Ein Lauf für '.$mandant['dbname'].' ist noch unterwegs — nichts zu tun.');
    // Hatte das Panel diesen Prozess angefordert, gilt der Start als erledigt:
    // der laufende Lauf nimmt die Aufträge mit
    shopPublishWriteState($dateien['status'], ['requested' => null]);
    exit(0);
}

// ── Aufträge abarbeiten ──
//
// exit() steht erst am Ende: PHP führt finally-Blöcke bei exit() nicht aus,
// und der Stand muss in jedem Fall geschrieben werden.

$beginn = function () use (&$protokoll, $dateien, $mandant, $melden) {
    // 'w' leert das Protokoll des vorigen Laufs
    $protokoll = @fopen($dateien['log'], 'w') ?: null;
    if ($protokoll) {
        @chmod($dateien['log'], 0664);
    }
    shopPublishWriteState($dateien['status'], [
        'started'  => date(DATE_ATOM),
        'pid'      => getmypid(),
        'finished' => null,
        'summary'  => null,
    ]);
    $melden('Mandant '.$mandant['name'].' ('.$mandant['dbname'].')');
};

$code = 0;
try {
    // Aufträge, Paket, Kategorieübersicht und Bau stehen in shopPublishRun().
    // Die Sperre dort hält Cron und Panel auseinander, auch über Rechner
    // hinweg — die Sperrdatei oben fängt nur zwei Läufer auf diesem Rechner ab.
    $bilanz = shopPublishRun($db, $melden, (int)($argumente['limit'] ?? 500), $nurIds, !isset($argumente['no-build']), $beginn);

    if ($bilanz['gesperrt']) {
        shopPublishWriteState($dateien['status'], ['requested' => null]);
    } else {
        $melden(sprintf('%d Aufträge, %d Seiten geschrieben, %d entfernt, %d Änderungen am Paket, %d Fehler',
            $bilanz['jobs'], $bilanz['seiten'], $bilanz['entfernt'], $bilanz['kit'], $bilanz['fehler']));

        // Aufräumen nach dem Lauf, nicht davor: die eben erledigten Aufträge
        // stehen dann schon mit Ergebnis da und fallen unter dieselbe Frist.
        if (!isset($argumente['no-cleanup'])) {
            $tage = shopJobRetentionDays($db);
            if ($tage > 0) {
                $weg = shopCleanupJobs($db, $tage);
                if ($weg > 0) {
                    $melden(sprintf('%d erledigte Aufträge älter als %d Tage gelöscht', $weg, $tage));
                }
            }
        }

        if (0 !== $bilanz['bau_code']) {
            fwrite(STDERR, "Der Bau der Webseite ist fehlgeschlagen (Rückgabewert ".$bilanz['bau_code'].").\n");
        }

        shopPublishWriteState($dateien['status'], [
            'finished' => date(DATE_ATOM),
            'summary'  => [
                'jobs'        => $bilanz['jobs'],
                'pages'       => $bilanz['seiten'],
                'removed'     => $bilanz['entfernt'],
                'kit'         => $bilanz['kit'],
                'errors'      => $bilanz['fehler'],
                'built'       => $bilanz['gebaut'],
                'error_lines' => $bilanz['fehler_texte'],
            ],
        ]);

        $code = $bilanz['fehler'] > 0 ? 1 : 0;
    }
} catch (Throwable $e) {
    $text = get_class($e).': '.$e->getMessage();
    fwrite(STDERR, $text."\n");
    $melden('Abgebrochen: '.$text);
    shopPublishWriteState($dateien['status'], [
        'finished' => date(DATE_ATOM),
        'summary'  => ['jobs' => 0, 'pages' => 0, 'removed' => 0, 'kit' => 0, 'errors' => 1,
                       'built' => false, 'error_lines' => ['Abgebrochen: '.$text]],
    ]);
    $code = 1;
}

if (is_resource($protokoll)) {
    fclose($protokoll);
}
flock($sperre, LOCK_UN);
fclose($sperre);

exit($code);
