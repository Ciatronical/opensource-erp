#!/usr/bin/env php
<?php
// tools/migrate-data-to-tenant.php
//
// Verschiebt bestehende Daten in mandantenspezifische Unterordner:
//   backend/data/customers            -> backend/data/<dbname>/customers
//   backend/data/vendors              -> backend/data/<dbname>/vendors
//   backend/data/fahrzeugschein       -> backend/data/<dbname>/fahrzeugschein
//   backend/data/parts_requests       -> backend/data/<dbname>/parts_requests
//   backend/data/accounting           -> backend/data/<dbname>/accounting
//   backend/data/whatsapp_cache       -> backend/data/<dbname>/whatsapp_cache
//   backend/data/whatsapp_unmatched   -> backend/data/<dbname>/whatsapp_unmatched
//   backend/data/weroni_inbox         -> backend/data/<dbname>/weroni_inbox
//
// Liest die Mandantenliste aus auth.clients. Wenn nur ein Mandant existiert,
// wird ohne Rueckfrage migriert. Bei mehreren Mandanten muss --client=<id>
// angegeben werden, weil eine automatische Zuordnung der vorhandenen Dateien
// nicht moeglich ist.
//
// Verwendung:
//   php tools/migrate-data-to-tenant.php [--client=<id>] [--dry-run]

require_once __DIR__ . '/../backend/api/config.php';
OserpConfig::init();

$dryRun = in_array('--dry-run', $argv, true);
$clientArg = null;
foreach ($argv as $arg) {
    if (preg_match('/^--client=(\d+)$/', $arg, $m)) {
        $clientArg = (int)$m[1];
    }
}

$dataBase = realpath(__DIR__ . '/../backend/data');
if ($dataBase === false) {
    fwrite(STDERR, "Fehler: backend/data nicht gefunden\n");
    exit(1);
}

$authPdo = new PDO(
    "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_AUTH_NAME,
    DB_AUTH_USER,
    DB_AUTH_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$clients = $authPdo->query("SELECT id, dbname FROM auth.clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

if (count($clients) === 0) {
    fwrite(STDERR, "Fehler: Keine Mandanten in auth.clients gefunden\n");
    exit(1);
}

if (count($clients) === 1) {
    $target = $clients[0];
} else {
    if ($clientArg === null) {
        fwrite(STDERR, "Mehrere Mandanten gefunden. Bitte --client=<id> angeben:\n");
        foreach ($clients as $c) {
            fwrite(STDERR, "  id={$c['id']}  dbname={$c['dbname']}\n");
        }
        exit(1);
    }
    $target = null;
    foreach ($clients as $c) {
        if ((int)$c['id'] === $clientArg) { $target = $c; break; }
    }
    if ($target === null) {
        fwrite(STDERR, "Fehler: client_id={$clientArg} nicht in auth.clients\n");
        exit(1);
    }
}

$dbname = $target['dbname'];
if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbname)) {
    fwrite(STDERR, "Fehler: Ungueltiger dbname '{$dbname}'\n");
    exit(1);
}

$tenantDir = $dataBase . '/' . $dbname;
$subdirs = ['customers', 'vendors', 'fahrzeugschein', 'parts_requests',
            'accounting', 'whatsapp_cache', 'whatsapp_unmatched', 'weroni_inbox'];

echo "Ziel-Mandant: {$dbname} (client_id={$target['id']})\n";
echo "Ziel-Verzeichnis: {$tenantDir}\n";
echo $dryRun ? "Modus: DRY-RUN (keine Aenderungen)\n\n" : "Modus: LIVE\n\n";

if (!$dryRun && !is_dir($tenantDir)) {
    if (!mkdir($tenantDir, 0755, true)) {
        fwrite(STDERR, "Fehler: konnte {$tenantDir} nicht anlegen\n");
        exit(1);
    }
}

$moved = 0; $skipped = 0;
foreach ($subdirs as $sub) {
    $src = $dataBase . '/' . $sub;
    $dst = $tenantDir . '/' . $sub;

    if (!is_dir($src)) {
        echo "  [skip] {$sub} (existiert nicht)\n";
        $skipped++;
        continue;
    }
    if (is_dir($dst)) {
        echo "  [skip] {$sub} (Ziel existiert bereits)\n";
        $skipped++;
        continue;
    }

    echo "  [move] {$src}\n         -> {$dst}\n";
    if (!$dryRun) {
        if (!rename($src, $dst)) {
            fwrite(STDERR, "Fehler beim Verschieben von {$src}\n");
            exit(1);
        }
    }
    $moved++;
}

echo "\nFertig. Verschoben: {$moved}, uebersprungen: {$skipped}\n";
