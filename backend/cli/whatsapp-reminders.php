#!/usr/bin/env php
<?php
/**
 * CLI-Script: WhatsApp-Erinnerungen versenden
 *
 * Durchlaeuft alle Mandanten und versendet faellige Terminerinnerungen
 * per WhatsApp Business API (Template-Nachrichten)
 *
 * Aufruf:
 *   php backend/cli/whatsapp-reminders.php
 *
 * Cron-Beispiel (alle 15 Minuten):
 *   Siehe install/README.md
 */

// CLI-Modus erzwingen
if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur ueber die Kommandozeile ausgefuehrt werden.\n");
}

// Basisverzeichnis
$baseDir = dirname(__DIR__).'/api';

// Einzelne Komponenten laden (OHNE api.call.php, das dispatcht HTTP-Requests)
require_once $baseDir.'/config.php';
require_once $baseDir.'/logging.php';
require_once $baseDir.'/database.php';
require_once $baseDir.'/session.php';

// WhatsApp-Funktionen laden
require_once dirname(__DIR__).'/api/whatsapp/whatsapp.php';

// resultInfo() im CLI-Modus: Ausgabe sammeln statt echo
$_cliOutput = null;
// resultInfo ist bereits in inc.php definiert (via config.php -> ... Reihenfolge)
// Wir fangen die Ausgabe per ob_start/ob_get_clean ab

/**
 * Alle Mandanten aus der Auth-DB laden
 */
function getClients(): array {
    $pdo = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
    $stmt = $pdo->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE is_default = 'f' OR is_default IS NULL ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * DbhCompany-Singleton mit direkter PDO-Verbindung initialisieren
 * (umgeht Session-Authentifizierung fuer CLI-Betrieb)
 */
function initCompanyDb(PDO $pdo): void {
    $reflection = new ReflectionClass('DbhCompany');
    $prop = $reflection->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, new ApiDatabase($pdo));
}

/**
 * DbhCompany-Singleton zuruecksetzen (fuer naechsten Mandanten)
 */
function resetCompanyDb(): void {
    $reflection = new ReflectionClass('DbhCompany');
    $prop = $reflection->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

// --- Hauptprogramm ---

$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] WhatsApp-Reminder Cronjob gestartet\n";

try {
    $clients = getClients();
} catch (Exception $e) {
    echo "[FEHLER] Auth-DB nicht erreichbar: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($clients)) {
    echo "[INFO] Keine Mandanten gefunden.\n";
    exit(0);
}

$totalSent = 0;

foreach ($clients as $client) {
    $clientName = $client['name'] ?? "ID {$client['id']}";

    try {
        // Mandanten-DB verbinden
        $pdo = connectPDO($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd']);
        initCompanyDb($pdo);

        // Terminerinnerungen
        ob_start();
        processWhatsAppReminders([]);
        $jsonOutput = ob_get_clean();
        $result = json_decode($jsonOutput, true);

        if ($result && $result['success']) {
            $sent = $result['payload']['sent'] ?? 0;
            if ($sent > 0) {
                $total = $result['payload']['total_events'] ?? 0;
                echo "[{$clientName}] Termine: {$sent}/{$total} Erinnerungen gesendet\n";
                $totalSent += $sent;
            }
        } elseif ($result) {
            $errorText = $result['text'] ?? '';
            if (!empty($errorText)) echo "[{$clientName}] Termin-Fehler: {$errorText}\n";
        }
    } catch (Exception $e) {
        echo "[{$clientName}] Fehler: " . $e->getMessage() . "\n";
    } finally {
        resetCompanyDb();
    }
}

if ($totalSent > 0) {
    echo "[{$timestamp}] Gesamt: {$totalSent} Erinnerungen gesendet\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Cronjob beendet\n";
