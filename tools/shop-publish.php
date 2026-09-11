#!/usr/bin/env php
<?php
// tools/shop-publish.php
//
// Läufer der Shop-Veröffentlichung: arbeitet die offenen Aufträge aus
// batchjob_hugoshop ab, schreibt die Inhaltsdateien und baut anschließend die
// Webseite, wenn in der settings.ini ein Befehl steht.
//
// Gedacht für einen Cron-Eintrag, etwa alle fünf Minuten. Die Anwendung selbst
// schreibt nur Aufträge: sie braucht dadurch weder Schreibrechte im
// Webseiten-Verzeichnis noch führt sie Befehle aus. Derselbe Schnitt wie beim
// Demo-Reset.
//
// Aufruf:
//   php tools/shop-publish.php [--client=<id>] [--db=<name>] [--limit=500]
//                              [--no-build] [--quiet] [--reconcile-payments]
//   php tools/shop-publish.php --list-clients
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

set_time_limit(0);

$argumente = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $treffer)) {
        $argumente[$treffer[1]] = $treffer[2] ?? true;
    }
}

$leise = isset($argumente['quiet']);
$melden = function (string $zeile) use ($leise) {
    if (!$leise) {
        echo date('H:i:s').'  '.$zeile."\n";
    }
};

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
$sperre = fopen($sperrVerzeichnis.'/shop-publish-'.$mandant['dbname'].'.lock', 'c');
if (false === $sperre || !flock($sperre, LOCK_EX | LOCK_NB)) {
    $melden('Ein Lauf für '.$mandant['dbname'].' ist noch unterwegs — nichts zu tun.');
    exit(0);
}

// ── Aufträge abarbeiten ──

try {
    $melden('Mandant '.$mandant['name'].' ('.$mandant['dbname'].')');

    $bilanz = shopRunJobs($db, $melden, (int)($argumente['limit'] ?? 500));

    // Das Webseiten-Paket bei jedem Lauf abgleichen, nicht nur nach neuen
    // Seiten: beim ersten Lauf entsteht oserp-shop/ überhaupt erst, und nach
    // einem Update von OpensourceERP kommt ein neues Bundle an, ohne dass
    // jemand eine Seite veröffentlicht. Stimmt alles, werden nur Prüfsummen
    // verglichen.
    try {
        $kit = shopSyncKit($db);
        $bilanz['kit'] += shopKitChanges($kit);
        if (shopKitChanges($kit) > 0) {
            $melden(sprintf('Paket abgeglichen: %d kopiert, %d entfernt%s',
                $kit['kopiert'], $kit['entfernt'], $kit['config'] ? ', Konfiguration neu' : ''));
        }
    } catch (Throwable $e) {
        $bilanz['fehler']++;
        $melden('Paketabgleich fehlgeschlagen: '.$e->getMessage());
    }

    // Die Kategorieübersicht nur, wenn sich Seiten geändert haben: jede
    // geschriebene oder entfernte Seite kann eine Kategorie ändern.
    $bilanz['kategorien'] = 0;
    if ($bilanz['seiten'] + $bilanz['entfernt'] > 0) {
        // Eine missratene Übersicht soll den Bau der neuen Seiten nicht aufhalten.
        try {
            $uebersicht = shopWriteCategoryGroups($db);
            if ($uebersicht['changed']) {
                $bilanz['kategorien'] = 1;
                $melden($uebersicht['categories'] > 0
                    ? sprintf('Kategorieübersicht geschrieben: %d Kategorien in %d Gruppen', $uebersicht['categories'], $uebersicht['groups'])
                    : 'Kategorieübersicht entfernt: keine Kategorien');
            }
        } catch (Throwable $e) {
            $bilanz['fehler']++;
            $melden('Kategorieübersicht fehlgeschlagen: '.$e->getMessage());
        }
    }

    $melden(sprintf('%d Aufträge, %d Seiten geschrieben, %d entfernt, %d Änderungen am Paket, %d Fehler',
        $bilanz['jobs'], $bilanz['seiten'], $bilanz['entfernt'], $bilanz['kit'], $bilanz['fehler']));
    $geaendert = $bilanz['seiten'] + $bilanz['entfernt'] + $bilanz['kit'] + $bilanz['kategorien'];

    // ── Webseite bauen ──

    $befehl = defined('OSERP_SHOP_PUBLISH_COMMAND') ? (string)OSERP_SHOP_PUBLISH_COMMAND : '';

    if ($geaendert > 0 && '' !== $befehl && !isset($argumente['no-build'])) {
        $verzeichnis = shopSiteDir($db);
        $melden('Baue die Webseite in '.$verzeichnis);

        $ausgabe = [];
        $rueckgabe = 0;
        exec('cd '.escapeshellarg($verzeichnis).' && '.$befehl.' 2>&1', $ausgabe, $rueckgabe);

        foreach ($ausgabe as $zeile) {
            $melden('  '.$zeile);
        }
        if (0 !== $rueckgabe) {
            fwrite(STDERR, "Der Bau der Webseite ist fehlgeschlagen (Rückgabewert $rueckgabe).\n");
            exit(1);
        }
        $melden('Webseite gebaut.');
    } elseif ($geaendert > 0 && '' === $befehl) {
        $melden('Kein shop_publish_command in der settings.ini — es wurden nur Dateien geschrieben.');
    }

    exit($bilanz['fehler'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
} finally {
    flock($sperre, LOCK_UN);
    fclose($sperre);
}
