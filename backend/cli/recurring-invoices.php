#!/usr/bin/env php
<?php
/**
 * CLI-Script: Wiederkehrende Rechnungen erzeugen
 *
 * Durchläuft alle Mandanten und erzeugt die fälligen Rechnungen aller aktiven
 * wiederkehrenden Abrechnungen (periodic_invoices_configs): Rechnung aus dem
 * Auftrag, Hauptbuch-Buchung, E-Mail-Versand und Druck je Konfiguration.
 * Vorher werden pausierte Abrechnungen mit erreichtem Datum wieder aktiviert
 * und abgelaufene Laufzeiten mit automatischer Verlängerung verlängert.
 *
 * Idempotent: jede Periode wird höchstens einmal abgerechnet
 * (periodic_invoices.period_start_date). Abrechnungen mit Mahnsperre und
 * überfälligen Kundenrechnungen werden ausgelassen und gemeldet — sie erzeugt
 * ein Mitarbeiter nach Prüfung in der Übersicht.
 *
 * Aufruf:
 *   php backend/cli/recurring-invoices.php
 *   php backend/cli/recurring-invoices.php --client 10   # nur dieser Mandant
 *   php backend/cli/recurring-invoices.php --dry-run     # nur zeigen, was fällig ist
 *
 * Cron-Beispiel (täglich 05:30):
 *   30 5 * * * cd /home/work/opensource-erp && php backend/cli/recurring-invoices.php >> log/recurring-invoices.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur über die Kommandozeile ausgeführt werden.\n");
}

$opt = getopt('', ['client:', 'dry-run', 'help']);
if (isset($opt['help'])) {
    echo "Aufruf: php backend/cli/recurring-invoices.php [--client ID] [--dry-run]\n";
    exit(0);
}

$baseDir = dirname(__DIR__).'/api';

// inc.php definiert resultInfo/ApiError und lädt config/database/session.
// Im CLI gibt es keine 'action' → api.call.php gibt einmal API_ACTION_NOT_SPECIFIED
// aus; diese Ausgabe wird per Output-Buffer verworfen.
ob_start();
require_once $baseDir.'/inc.php';
ob_get_clean();

require_once $baseDir.'/faktura/faktura.php';
require_once $baseDir.'/faktura/recurring.php';

// Eigene Namen (recurringCron*), da inc.php via auth.php bereits ein getClients()
// für den HTTP-Kontext definiert.

/**
 * Alle Mandanten aus der Auth-DB laden (optional nur einer)
 */
function recurringCronGetClients(?int $onlyId): array {
    $pdo = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
    // Alle Mandanten, auch der Standardmandant — Abos laufen gerade im Produktivmandanten
    $sql = "SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients";
    if ($onlyId) $sql .= " WHERE id = " . intval($onlyId);
    $stmt = $pdo->query($sql . " ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * DbhCompany-Singleton mit direkter PDO-Verbindung setzen bzw. zurücksetzen
 * (umgeht die Session-Authentifizierung für den CLI-Betrieb)
 */
function recurringCronSetCompanyDb(?PDO $pdo): void {
    $reflection = new ReflectionClass('DbhCompany');
    $prop = $reflection->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo ? new ApiDatabase($pdo) : null);
}

// --- Hauptprogramm ---

$dryRun = isset($opt['dry-run']);
echo "[" . date('Y-m-d H:i:s') . "] Wiederkehrende Rechnungen gestartet" . ($dryRun ? " (dry-run)" : "") . "\n";

try {
    $clients = recurringCronGetClients(isset($opt['client']) ? intval($opt['client']) : null);
} catch (Exception $e) {
    echo "[FEHLER] Auth-DB nicht erreichbar: " . $e->getMessage() . "\n";
    exit(1);
}

$totalCreated = 0;
$hadErrors = false;

foreach ($clients as $client) {
    $clientName = $client['name'] ?? "ID {$client['id']}";

    try {
        try {
            $pdo = connectPDO($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd']);
        } catch (PDOException $e) {
            // Stillgelegte Mandanten (Datenbank gelöscht) sind kein Fehler des Laufs
            echo "[{$clientName}] übersprungen: Datenbank nicht erreichbar\n";
            continue;
        }
        recurringCronSetCompanyDb($pdo);
        $db = DbhCompany::begin();

        // Mandanten ohne die Zusatztabellen (Upstall noch nicht gelaufen) still
        // überspringen. Bewusst nicht existingTables(): dessen Zwischenspeicher
        // gilt je Prozess und würde das Ergebnis des ersten Mandanten auf alle
        // weiteren übertragen.
        $tables = $db->getOne("SELECT to_regclass('public.periodic_invoices_configs') IS NOT NULL AND to_regclass('public.periodic_invoices_configs_ext') IS NOT NULL AS ok");
        if (!$tables || !($tables['ok'] === true || $tables['ok'] === 't')) {
            continue;
        }

        if ($dryRun) {
            $cfgCte = recurringConfigCte();
            $dueCte = recurringDueCte();
            $due = $db->getAll("WITH {$cfgCte}, {$dueCte} SELECT customer_name, ordnumber, period_start, period_end, amount, blocked FROM due ORDER BY customer_name, period_start");
            foreach ($due as $d) {
                echo "[{$clientName}] fällig: {$d['customer_name']} Auftrag {$d['ordnumber']} {$d['period_start']}–{$d['period_end']} "
                   . number_format((float)$d['amount'], 2, ',', '.') . ($d['blocked'] === true || $d['blocked'] === 't' ? ' (Mahnsperre)' : '') . "\n";
            }
            continue;
        }

        $summary = recurringRunDue($db, null, 'cron');
        $created = count($summary['created']);
        $totalCreated += $created;

        foreach ($summary['created'] as $r) {
            echo "[{$clientName}] Rechnung {$r['invnumber']} für {$r['customer_name']} (Auftrag {$r['ordnumber']}, {$r['period_start']}–{$r['period_end']})"
               . ($r['posted'] ? ', gebucht' : '') . ($r['emailed'] ? ', gemailt' : '') . ($r['printed'] ? ', gedruckt' : '')
               . (!empty($r['email_error']) ? ", E-Mail-Fehler: {$r['email_error']}" : '')
               . (!empty($r['print_error']) ? ", Druckfehler: {$r['print_error']}" : '') . "\n";
        }
        foreach ($summary['blocked'] as $b) {
            echo "[{$clientName}] ausgelassen (Mahnsperre): {$b['customer_name']} Auftrag {$b['ordnumber']} Periode {$b['period_start']}\n";
        }
        foreach ($summary['errors'] as $e) {
            $hadErrors = true;
            echo "[{$clientName}] FEHLER Konfiguration {$e['config_id']} Periode {$e['period_start']}: {$e['message']}\n";
        }
        if ($created > 0 || $summary['blocked'] || $summary['errors']) {
            echo "[{$clientName}] {$created} erzeugt, " . count($summary['blocked']) . " ausgelassen, " . count($summary['errors']) . " Fehler\n";
        }
    } catch (Exception $e) {
        $hadErrors = true;
        echo "[{$clientName}] Fehler: " . $e->getMessage() . "\n";
    } finally {
        recurringCronSetCompanyDb(null);
    }
}

if ($totalCreated > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Gesamt: {$totalCreated} Rechnungen erzeugt\n";
}
echo "[" . date('Y-m-d H:i:s') . "] Beendet\n";
exit($hadErrors ? 1 : 0);
