#!/usr/bin/env php
<?php
/**
 * Nachbuchung ungebuchter Ausgangsrechnungen ins Hauptbuch (acc_trans).
 *
 * Bucht Rechnungen, die zwar existieren (ar + invoice-Positionen), aber keine
 * Sach-Buchung im Hauptbuch haben (z. B. aus convertFaktura erzeugt, nie im
 * Editor gespeichert). Nutzt postArInvoiceToLedger() — exakt dieselbe Logik wie
 * der Faktura-Editor.
 *
 * Aufruf:
 *   php backend/cli/post-unbooked-invoices.php <dbname> [--id=<arId>] [--limit=N] [--commit]
 *
 * Standard ist DRY-RUN (zeigt nur, was gebucht würde). Erst mit --commit wird geschrieben.
 *
 * Beispiele:
 *   php backend/cli/post-unbooked-invoices.php autoprofis_gmbh --id=6349            # eine Rechnung, Dry-Run
 *   php backend/cli/post-unbooked-invoices.php autoprofis_gmbh --limit=5            # 5 Rechnungen, Dry-Run
 *   php backend/cli/post-unbooked-invoices.php autoprofis_gmbh --commit             # ALLE nachbuchen (schreibt!)
 */

if (php_sapi_name() !== 'cli') {
    die("Nur über die Kommandozeile ausführbar.\n");
}

$baseDir = dirname(__DIR__).'/api';
require_once $baseDir.'/config.php';
require_once $baseDir.'/logging.php';
require_once $baseDir.'/database.php';
require_once $baseDir.'/inc.php';
require_once $baseDir.'/faktura/faktura.php';

// ── Argumente ──
$dbname = null;
$onlyId = null;
$limit  = null;
$commit = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--id='))         $onlyId = intval(substr($arg, 5));
    elseif (str_starts_with($arg, '--limit='))  $limit  = intval(substr($arg, 8));
    elseif ($arg === '--commit')                $commit = true;
    elseif (!str_starts_with($arg, '--'))       $dbname = $arg;
}
if (!$dbname) {
    fwrite(STDERR, "Fehler: dbname fehlt.\n  php backend/cli/post-unbooked-invoices.php <dbname> [--id=N] [--limit=N] [--commit]\n");
    exit(1);
}

// ── DB-Verbindung (Company-DB direkt) ──
$pdo = connectPDO(DB_HOST, DB_PORT, $dbname, DB_AUTH_USER, DB_AUTH_PASS);
$ref = new ReflectionClass('DbhCompany');
$prop = $ref->getProperty('instance');
$prop->setAccessible(true);
$prop->setValue(null, new ApiDatabase($pdo));
$db = DbhCompany::begin();

// ── Zu buchende Rechnungen ermitteln ──
if ($onlyId) {
    $rows = [['id' => $onlyId]];
} else {
    $sql = "SELECT a.id FROM ar a
            WHERE NOT EXISTS (SELECT 1 FROM acc_trans x WHERE x.trans_id = a.id)
            ORDER BY a.transdate, a.id";
    if ($limit) $sql .= " LIMIT " . intval($limit);
    $rows = $db->getAll($sql);
}

$mode = $commit ? "COMMIT (schreibt)" : "DRY-RUN (keine Änderung)";
echo "DB: {$dbname} · Modus: {$mode} · Rechnungen: " . count($rows) . "\n";
echo str_repeat('─', 70) . "\n";

$stats = ['posted' => 0, 'skipped' => 0, 'mismatch' => 0, 'error' => 0];
foreach ($rows as $r) {
    $arId = intval($r['id']);
    $inv  = $db->getOne("SELECT invnumber, amount FROM ar WHERE id = :id", ['id' => $arId]);
    $label = "AR #{$arId} (Rg " . ($inv['invnumber'] ?? '?') . ", " . number_format((float)($inv['amount'] ?? 0), 2) . " €)";
    try {
        $res = postArInvoiceToLedger($db, $arId, !$commit);
        if (($res['reason'] ?? '') === 'AMOUNT_MISMATCH') {
            $stats['mismatch']++;
            echo "  ⚠ {$label}: Brutto-Mismatch (berechnet {$res['gross']} € vs. {$res['ar_amount']} €) — übersprungen\n";
        } elseif (($res['reason'] ?? '') === 'DRY_RUN') {
            $stats['posted']++;
            echo "  ✓ {$label}: würde " . count($res['entries']) . " Buchungssätze erzeugen (Brutto {$res['gross']} €)\n";
        } elseif (!empty($res['posted'])) {
            $stats['posted']++;
            echo "  ✓ {$label}: gebucht ({$res['count']} Sätze, Brutto {$res['gross']} €)\n";
        } else {
            $stats['skipped']++;
            echo "  · {$label}: übersprungen ({$res['reason']})\n";
        }
    } catch (\Throwable $e) {
        $stats['error']++;
        echo "  ✗ {$label}: FEHLER " . $e->getMessage() . "\n";
    }
}

echo str_repeat('─', 70) . "\n";
echo "Fertig: gebucht/buchbar={$stats['posted']}, übersprungen={$stats['skipped']}, mismatch={$stats['mismatch']}, fehler={$stats['error']}\n";
if (!$commit) echo "Hinweis: DRY-RUN — nichts geschrieben. Mit --commit tatsächlich buchen.\n";
