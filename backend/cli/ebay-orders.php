#!/usr/bin/env php
<?php
/**
 * CLI-Script: eBay-Bestellimport
 *
 * Durchlaeuft alle Mandanten und holt die geaenderten eBay-Bestellungen:
 * eindeutige Kaeufer werden gleich abgerechnet (Rechnung + Hauptbuch-Buchung),
 * unklare warten in der Pruefung im eBay-Board (dev/shop-ebay.md). Danach
 * Versandmeldungen an eBay und alle sechs Stunden der Abgleich der Angebote
 * (nur bei Kanaelen, die per „Mit eBay verbinden“ angemeldet sind).
 * Beruecksichtigt werden Mandanten mit aktiver Shop-Erweiterung, dort jeder
 * eingeschaltete eBay-Kanal (Shop-Menue → Verkaufskanaele) — je
 * Kanal ein eBay-Konto (dev/shop-mehrere-kanaele.md). Ein Fehler in einem
 * Kanal haelt die uebrigen nicht auf.
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
    // Belegchronik: Rechnungen aus dem eBay-Import, kein Mitarbeiter
    DbhCompany::setActor(DbhCompany::begin(), null, 'cron:ebay');
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

        // Nur Mandanten mit Shop-Erweiterung, dort die eingeschalteten
        // eBay-Kanaele (V14) — die uebrigen still ueberspringen
        if (!isExtensionActive($db, 'shop')) {
            continue;
        }

        foreach (shopEbayActiveChannels($db) as $kanal) {
            $name = $clientName.' / '.$kanal['name'];
            try {
                $summary = shopEbayImportOrders($db, (int)$kanal['id']);
            } catch (Exception $e) {
                echo "[{$name}] Fehler: " . $e->getMessage() . "\n";
                continue;
            }
            $invoiced = intval($summary['invoiced'] ?? 0);
            $review   = intval($summary['review'] ?? 0);
            $totalImported += $invoiced;

            if ($summary['fetched'] > 0 || !empty($summary['errors'])) {
                echo "[{$name}] eBay: {$invoiced} abgerechnet, {$review} zur Pruefung, "
                   . intval($summary['waiting_payment'] ?? 0) . " warten auf Zahlung, "
                   . intval($summary['shipped'] ?? 0) . " Versand gemeldet, "
                   . intval($summary['fetched'] ?? 0) . " abgerufen\n";
            }
            foreach (($summary['errors'] ?? []) as $err) {
                echo "[{$name}] eBay-Fehler: {$err}\n";
            }

            // Angebote alle sechs Stunden neu lesen: Mengen und Preise bei eBay
            // aendern sich auch durch Verkaeufe und Aenderungen im Seller Hub
            $letzter = shopChannelValue($db, (int)$kanal['id'], 'listings_last_sync');
            if ('' !== shopChannelValue($db, (int)$kanal['id'], 'connected_at') && ('' === $letzter || strtotime($letzter) < time() - 6 * 3600)) {
                try {
                    $angebote = shopEbaySyncListings($db, (int)$kanal['id']);
                    echo "[{$name}] eBay-Angebote: " . intval($angebote['fetched'] ?? 0) . " gelesen, "
                       . intval($angebote['linked'] ?? 0) . " neu zugeordnet, " . intval($angebote['suggested'] ?? 0) . " Vorschlaege\n";
                } catch (Exception $e) {
                    echo "[{$name}] eBay-Angebote: " . $e->getMessage() . "\n";
                }
            }
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
