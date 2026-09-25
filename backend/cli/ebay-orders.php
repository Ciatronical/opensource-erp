#!/usr/bin/env php
<?php
/**
 * CLI-Script: eBay-Bestellimport
 *
 * Durchlaeuft alle Mandanten und importiert neue eBay-Bestellungen als
 * Ausgangsrechnungen (Kunde dublettenfrei, Rechnung + Hauptbuch-Buchung).
 * Beruecksichtigt werden Mandanten mit aktiver Shop-Erweiterung und
 * eingeschaltetem eBay-Kanal (Einstellungen → Shop → Verkaufskanaele).
 * Idempotent: bereits importierte Bestellungen werden uebersprungen
 * (ebay_orders.ebay_order_id UNIQUE).
 *
 * Aufruf:
 *   php backend/cli/ebay-orders.php
 *
 * Cron-Beispiel (alle 15 Minuten):
 *   *\/15 * * * * cd /home/work/opensource-erp && php backend/cli/ebay-orders.php >> backend/log/ebay-orders.log 2>&1
 */

// CLI-Modus erzwingen
if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur ueber die Kommandozeile ausgefuehrt werden.\n");
}

$baseDir = dirname(__DIR__).'/api';

// inc.php definiert resultInfo/ApiError und laedt config/database/session.
// Im CLI gibt es keine 'action' -> api.call.php gibt einmal API_ACTION_NOT_SPECIFIED
// aus; diese Ausgabe wird per Output-Buffer verworfen.
ob_start();
require_once $baseDir.'/inc.php';
ob_get_clean();

// eBay-Import: der eBay-Kanal der Shop-Erweiterung (dev/shop-verkaufskanaele.md,
// V15). publish.php bindet die Kanalmodule ein, darunter channels/ebay.php mit
// dem Bestellimport; faktura.php liefert Rechnung und Buchung.
require_once $baseDir.'/faktura/faktura.php';
require_once $baseDir.'/shop/lib/config.php';
require_once $baseDir.'/shop/lib/publish.php';

// Eigene Namen (ebayCron*), da inc.php via auth.php bereits ein getClients()
// fuer den HTTP-Kontext definiert.

/**
 * Alle Mandanten aus der Auth-DB laden
 */
function ebayCronGetClients(): array {
    $pdo = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
    $stmt = $pdo->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE is_default = 'f' OR is_default IS NULL ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * DbhCompany-Singleton mit direkter PDO-Verbindung initialisieren
 * (umgeht Session-Authentifizierung fuer CLI-Betrieb)
 */
function ebayCronInitCompanyDb(PDO $pdo): void {
    $reflection = new ReflectionClass('DbhCompany');
    $prop = $reflection->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, new ApiDatabase($pdo));
}

/**
 * DbhCompany-Singleton zuruecksetzen (fuer naechsten Mandanten)
 */
function ebayCronResetCompanyDb(): void {
    $reflection = new ReflectionClass('DbhCompany');
    $prop = $reflection->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

// --- Hauptprogramm ---

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] eBay-Import Cronjob gestartet\n";

try {
    $clients = ebayCronGetClients();
} catch (Exception $e) {
    echo "[FEHLER] Auth-DB nicht erreichbar: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($clients)) {
    echo "[INFO] Keine Mandanten gefunden.\n";
    exit(0);
}

$totalImported = 0;

foreach ($clients as $client) {
    $clientName = $client['name'] ?? "ID {$client['id']}";

    try {
        $pdo = connectPDO($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd']);
        ebayCronInitCompanyDb($pdo);
        $db = DbhCompany::begin();

        // Nur Mandanten mit Shop-Erweiterung und eingeschaltetem eBay-Kanal
        // (V14) — die uebrigen still ueberspringen
        if (!isExtensionActive($db, 'shop') || !shopEbayActive($db)) {
            continue;
        }

        $summary = shopEbayImportOrders($db);
        $imported = intval($summary['imported'] ?? 0);
        $skipped  = intval($summary['skipped'] ?? 0);
        $totalImported += $imported;

        if ($imported > 0 || $skipped > 0 || !empty($summary['errors'])) {
            echo "[{$clientName}] eBay: {$imported} importiert, {$skipped} uebersprungen, "
               . intval($summary['fetched'] ?? 0) . " abgerufen\n";
        }
        foreach (($summary['errors'] ?? []) as $err) {
            echo "[{$clientName}] eBay-Fehler: {$err}\n";
        }
    } catch (Exception $e) {
        echo "[{$clientName}] Fehler: " . $e->getMessage() . "\n";
    } finally {
        ebayCronResetCompanyDb();
    }
}

if ($totalImported > 0) {
    echo "[{$timestamp}] Gesamt: {$totalImported} eBay-Rechnungen erstellt\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Cronjob beendet\n";
