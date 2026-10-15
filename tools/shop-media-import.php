#!/usr/bin/env php
<?php
// tools/shop-media-import.php
//
// Übernimmt vorhandene Medien einer Shop-Webseite in die Medienbibliothek
// (dev/shop-medienbibliothek.md, B6): Produktbilder und Downloads aus einer
// Kopie der Webseite (etwa per rsync geholt) nach
// backend/data/<db>/shop/media/, dazu die Einträge in media_shop und die
// Vorschaubilder. Danach ein Bericht: welche in den Artikeln genannten
// Dateien fehlen und welche Dateien kein Artikel nennt.
//
// Aufruf:
//   php tools/shop-media-import.php --client=<id>|--db=<name>
//       [--images=<verzeichnis>] [--downloads=<verzeichnis>]
//       [--dry-run] [--quiet]
//
// Ohne --images/--downloads wird nichts kopiert, nur die Bibliothek mit
// media_shop abgeglichen und der Bericht ausgegeben. Vorhandene Dateien
// gleichen Namens werden ersetzt, wenn der Inhalt abweicht. Unbrauchbare
// Dateien (falsche Art, zu groß) bleiben außen vor und stehen im Bericht.
//
// Als der Benutzer aufrufen, unter dem auch der Webserver läuft (etwa
// www-data), damit die Dateien für beide lesbar sind.

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
require_once __DIR__.'/../backend/api/customer_vendor/filemanager.php';
require_once __DIR__.'/../backend/api/shop/lib/media.php';

$argumente = getopt('', ['client:', 'db:', 'images:', 'downloads:', 'dry-run', 'quiet', 'list-clients', 'help']);
if (isset($argumente['help'])) {
    echo "php tools/shop-media-import.php --client=<id>|--db=<name> [--images=<dir>] [--downloads=<dir>] [--dry-run] [--quiet]\n";
    exit(0);
}
$leise = isset($argumente['quiet']);
$probe = isset($argumente['dry-run']);
$sagen = function (string $zeile) use ($leise) { if (!$leise) echo $zeile, "\n"; };

// ── Mandant ──

try {
    $authPdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME),
        DB_AUTH_USER, DB_AUTH_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
foreach ($mandanten as $m) {
    if ((isset($argumente['client']) && (string)$m['id'] === (string)$argumente['client'])
        || (isset($argumente['db']) && $m['dbname'] === $argumente['db'])) {
        $mandant = $m;
    }
}
if (null === $mandant && 1 === count($mandanten) && !isset($argumente['client']) && !isset($argumente['db'])) {
    $mandant = $mandanten[0];
}
if (null === $mandant) {
    fwrite(STDERR, "Mandant angeben: --client=<id> oder --db=<name>. Liste: --list-clients\n");
    exit(1);
}
try {
    DbhCompany::begin(new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $mandant['dbhost'], $mandant['dbport'], $mandant['dbname']),
        $mandant['dbuser'], $mandant['dbpasswd'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    ));
    $db = DbhCompany::begin();
} catch (Throwable $e) {
    fwrite(STDERR, 'Mandant '.$mandant['dbname'].': '.$e->getMessage()."\n");
    exit(1);
}

// ── Übernahme ──

$bilanz = ['kopiert' => 0, 'gleich' => 0, 'unbrauchbar' => []];
foreach (['image' => 'images', 'download' => 'downloads'] as $art => $bereich) {
    $quelle = $argumente[$bereich] ?? '';
    if ('' === $quelle) {
        continue;
    }
    if (!is_dir($quelle)) {
        fwrite(STDERR, "Verzeichnis nicht gefunden: $quelle\n");
        exit(1);
    }
    $sagen("Übernahme $bereich aus $quelle …");
    $ziel = shopMediaDir($db, $bereich, !$probe);
    foreach (scandir($quelle) ?: [] as $name) {
        $datei = rtrim($quelle, '/').'/'.$name;
        if (str_starts_with($name, '.') || !is_file($datei)) {
            continue;
        }
        try {
            $bereinigt = shopMediaName($name);
            $vorhanden = $ziel.'/'.$bereinigt;
            if (is_file($vorhanden) && hash_file('sha256', $vorhanden) === hash_file('sha256', $datei)) {
                $bilanz['gleich']++;
                continue;
            }
            if ($probe) {
                shopMediaInspect($datei, $art, $bereinigt);
                $sagen("  würde übernehmen: $name".($bereinigt !== $name ? " als $bereinigt" : ''));
            } else {
                $zeile = shopMediaStore($db, $art, $datei, $name);
                $sagen('  übernommen: '.$name.($zeile['filename'] !== $name ? ' als '.$zeile['filename'] : '')
                    .($zeile['replaced'] ? ' (ersetzt)' : ''));
            }
            $bilanz['kopiert']++;
        } catch (ApiError $e) {
            $bilanz['unbrauchbar'][] = "$bereich/$name: ".$e->getMessage();
        }
    }
}

// ── Abgleich und Bericht ──

if (!$probe) {
    $abgleich = shopMediaSync($db, $sagen);
    $sagen(sprintf('Abgleich: %d neu, %d geändert, %d entfernt, %d Vorschaubilder erzeugt',
        $abgleich['neu'], $abgleich['geaendert'], $abgleich['entfernt'], $abgleich['vorschau']));
    $bilanz['unbrauchbar'] = array_merge($bilanz['unbrauchbar'], $abgleich['unbrauchbar']);
}

// Genannte Dateien, die fehlen — und Dateien, die niemand nennt
$genannt = ['image' => [], 'download' => []];
foreach ($db->getAll(
    "SELECT regexp_replace(e, '^.*/', '') AS name, 'image' AS kind
       FROM parts_ext pe, jsonb_array_elements_text(pe.hugoshop_images) e
      WHERE jsonb_typeof(pe.hugoshop_images) = 'array'
     UNION
     SELECT regexp_replace(d.value, '^.*/', ''), 'download'
       FROM parts_ext pe, jsonb_each_text(pe.hugoshop_downloads) d
      WHERE jsonb_typeof(pe.hugoshop_downloads) = 'object'") ?: [] as $zeile) {
    if ('' !== (string)$zeile['name']) {
        $genannt[$zeile['kind']][$zeile['name']] = true;
    }
}
foreach (['image' => 'images', 'download' => 'downloads'] as $art => $bereich) {
    $verzeichnis = shopMediaDir($db, $bereich, !$probe);
    $da = [];
    foreach (is_dir($verzeichnis) ? scandir($verzeichnis) : [] as $name) {
        if (!str_starts_with($name, '.') && is_file($verzeichnis.'/'.$name)) {
            $da[$name] = true;
        }
    }
    $fehlend = array_keys(array_diff_key($genannt[$art], $da));
    $unbenutzt = array_keys(array_diff_key($da, $genannt[$art]));
    echo sprintf("%s: %d in Artikeln genannt, %d in der Bibliothek, %d fehlen, %d ungenutzt\n",
        $bereich, count($genannt[$art]), count($da), count($fehlend), count($unbenutzt));
    foreach (array_slice($fehlend, 0, 20) as $name) {
        echo "  fehlt: $name\n";
    }
    if (count($fehlend) > 20) {
        echo '  … und '.(count($fehlend) - 20)." weitere\n";
    }
}
echo sprintf("Übernahme: %d kopiert, %d schon vorhanden, %d unbrauchbar%s\n",
    $bilanz['kopiert'], $bilanz['gleich'], count($bilanz['unbrauchbar']), $probe ? ' (Probelauf, nichts geschrieben)' : '');
foreach ($bilanz['unbrauchbar'] as $grund) {
    echo "  unbrauchbar: $grund\n";
}
exit($bilanz['unbrauchbar'] ? 1 : 0);
