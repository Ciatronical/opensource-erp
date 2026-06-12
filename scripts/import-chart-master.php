<?php
/**
 * import-chart-master.php — vollständigen DATEV-Kontenrahmen (SKR03/SKR04) in BESTEHENDE
 * Firmen-Datenbanken einspielen. Idempotent, additiv, transaktional.
 *
 * Standard ist DRY-RUN (nichts wird geschrieben, nur Report). Erst mit --commit wird geschrieben,
 * und zwar NACH einem automatischen pg_dump-Backup der Ziel-DB.
 *
 * Aufruf:
 *   php scripts/import-chart-master.php <dbname|all> [Optionen]
 *
 * Optionen:
 *   --commit                Tatsächlich schreiben (sonst Dry-Run). Erzeugt vorher ein Backup.
 *   --apply-corrections     Freigegebene Konfig-Korrekturen anwenden (nur mit --allow=...).
 *   --allow=8400,8300       Whitelist von accno, die korrigiert werden dürfen.
 *   --skr=skr03|skr04       SKR erzwingen (sonst Autoerkennung über defaults.coa).
 *   --backup-dir=/pfad      Backup-Verzeichnis (Default: ./var/chart-backups).
 *
 * Beispiele:
 *   php scripts/import-chart-master.php ap_dev                 # Dry-Run Report
 *   php scripts/import-chart-master.php ap_dev --commit        # Backup + Import
 *   php scripts/import-chart-master.php all                    # Dry-Run über alle Firmen
 */

require_once __DIR__ . '/../backend/api/inc.php';
require_once __DIR__ . '/../backend/api/config.php';
require_once __DIR__ . '/../backend/api/database.php';
require_once __DIR__ . '/../backend/api/accounting/chart_import.php';

// ── Argumente ──
$argvv  = array_slice($argv, 1);
$target = null;
$opts = ['commit' => false, 'apply' => false, 'allow' => [], 'skr' => null,
         'backup_dir' => __DIR__ . '/../var/chart-backups'];
foreach ($argvv as $a) {
    if ($a === '--commit') $opts['commit'] = true;
    elseif ($a === '--apply-corrections') $opts['apply'] = true;
    elseif (str_starts_with($a, '--allow=')) $opts['allow'] = array_filter(explode(',', substr($a, 8)));
    elseif (str_starts_with($a, '--skr=')) $opts['skr'] = substr($a, 6);
    elseif (str_starts_with($a, '--backup-dir=')) $opts['backup_dir'] = substr($a, 13);
    elseif (!str_starts_with($a, '--')) $target = $a;
}
if ($target === null) {
    fwrite(STDERR, "Aufruf: php scripts/import-chart-master.php <dbname|all> [--commit] [--apply-corrections --allow=..] [--skr=..] [--backup-dir=..]\n");
    exit(2);
}

// ── Firmen-DBs aus der Auth-DB lesen ──
$authPdo = new PDO('pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
$clients = $authPdo->query('SELECT name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients ORDER BY dbname')
                   ->fetchAll(PDO::FETCH_ASSOC);

$targets = $target === 'all' ? $clients : array_values(array_filter($clients, fn($c) => $c['dbname'] === $target));
if (empty($targets)) {
    fwrite(STDERR, "Keine Firmen-DB '$target' in auth.clients gefunden.\n");
    exit(1);
}

$mode = $opts['commit'] ? 'COMMIT' : 'DRY-RUN';
fwrite(STDOUT, "=== Kontenrahmen-Rollout ($mode) — " . count($targets) . " DB(s) ===\n");

foreach ($targets as $c) {
    $db = $c['dbname'];
    fwrite(STDOUT, "\n──────────────────────────────────────────\nFirma: {$c['name']}  (DB: $db)\n");

    try {
        $pdo = new PDO("pgsql:host={$c['dbhost']};port={$c['dbport']};dbname={$db}", $c['dbuser'], $c['dbpasswd']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\Throwable $e) {
        fwrite(STDOUT, "  ÜBERSPRUNGEN (keine Verbindung): " . $e->getMessage() . "\n");
        continue;
    }

    // SKR erkennen
    $skr = $opts['skr'] ?? detectSkr($pdo);
    if ($skr === null) {
        fwrite(STDOUT, "  ÜBERSPRUNGEN: SKR nicht erkennbar (defaults.coa). Mit --skr=skr03|skr04 erzwingen.\n");
        continue;
    }
    fwrite(STDOUT, "  Kontenrahmen: $skr\n");

    // Backup vor jedem echten Commit
    if ($opts['commit']) {
        $backup = backupDb($c, $opts['backup_dir']);
        if ($backup === null) {
            fwrite(STDOUT, "  ABBRUCH: Backup fehlgeschlagen — kein Import.\n");
            continue;
        }
        fwrite(STDOUT, "  Backup: $backup\n");
    }

    $apiDb  = new ApiDatabase($pdo);
    $report = importChartMaster($apiDb, $skr, [
        'mode'              => 'fix',
        'dry_run'           => !$opts['commit'],
        'apply_corrections' => $opts['apply'],
        'correction_allow'  => $opts['allow'],
    ]);

    $s = $report['summary'];
    fwrite(STDOUT, sprintf(
        "  Ergebnis: master=%d  neu=%d  vorhanden=%d  taxkeys-repariert=%d  konflikte=%d  korrektur-vorschlaege=%d\n",
        $s['master_count'], $s['added'], $s['skipped'], $s['repaired_taxkeys'], $s['conflicts'], $s['corrections']));

    if (!empty($report['conflicts'])) {
        fwrite(STDOUT, "  Konflikte (max 10):\n");
        foreach (array_slice($report['conflicts'], 0, 10) as $cf) {
            fwrite(STDOUT, "    {$cf['accno']}: {$cf['reason']}\n");
        }
    }
    if (!empty($report['corrections'])) {
        fwrite(STDOUT, "  Korrektur-Vorschläge (max 15, NICHT angewandt ohne --apply-corrections + --allow):\n");
        foreach (array_slice($report['corrections'], 0, 15) as $cr) {
            fwrite(STDOUT, "    {$cr['accno']}: " . json_encode($cr['diff'], JSON_UNESCAPED_UNICODE) . "\n");
        }
    }
}

fwrite(STDOUT, "\nFertig.\n");

/** SKR aus defaults.coa ableiten ('Germany-DATEV-SKR03EU' -> skr03). */
function detectSkr(PDO $pdo): ?string {
    try {
        $coa = $pdo->query("SELECT coa FROM defaults LIMIT 1")->fetchColumn();
    } catch (\Throwable $e) {
        return null;
    }
    if (!$coa) return null;
    if (stripos($coa, 'SKR03') !== false) return 'skr03';
    if (stripos($coa, 'SKR04') !== false) return 'skr04';
    return null;
}

/** pg_dump-Backup der Ziel-DB. Gibt Dateipfad zurück oder null bei Fehler. */
function backupDb(array $c, string $dir): ?string {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;
    $file = rtrim($dir, '/') . '/' . $c['dbname'] . '-' . date('Ymd-His') . '.sql';
    $cmd = sprintf(
        'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s -f %s',
        escapeshellarg($c['dbpasswd']), escapeshellarg($c['dbhost']), escapeshellarg($c['dbport']),
        escapeshellarg($c['dbuser']), escapeshellarg($c['dbname']), escapeshellarg($file)
    );
    exec($cmd . ' 2>&1', $out, $rc);
    return $rc === 0 && is_file($file) ? $file : null;
}
