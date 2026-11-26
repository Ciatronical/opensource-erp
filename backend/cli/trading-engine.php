#!/usr/bin/env php
<?php
/**
 * CLI-Script: KI-Trading-Engine
 *
 * Durchläuft alle Mandanten mit aktiver Erweiterung „trading“ und führt je
 * Mandant einen Lauf der Engine aus — darin jeder Mitarbeiter mit eigenem
 * Handelssystem (users_trading) für sich: Marktdaten nachholen, Orders abgleichen,
 * bei neuem Tagesbalken umschichten, Risiko überwachen; dazu die fälligen
 * Arbeiten (Forschungszyklus, Lebenszyklus der Strategien, Tagesbericht).
 * Was fällig ist, entscheidet die Engine selbst anhand ihres Zustands
 * (state_trading) — der Cron-Takt ist nur der Herzschlag.
 *
 * Der Lauf ist wiederholbar und übersteht Abbrüche: offene Orders werden beim
 * nächsten Lauf abgeglichen, eine Entscheidung erzeugt nie zwei Orders
 * (dev/trading.md). Mandanten mit background_services_disabled (Test-Klone)
 * werden übersprungen.
 *
 * Aufruf:
 *   php backend/cli/trading-engine.php                 alle Mandanten
 *   php backend/cli/trading-engine.php --client 10     nur dieser Mandant
 *   php backend/cli/trading-engine.php --research      Forschungszyklus erzwingen
 *   php backend/cli/trading-engine.php --no-research   ohne Forschungszyklus
 *
 * Cron (alle 10 Minuten):
 *   0,10,20,30,40,50 * * * * cd /home/work/opensource-erp && php backend/cli/trading-engine.php >> log/trading-engine.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur ueber die Kommandozeile ausgefuehrt werden.\n");
}

$opt = getopt('', ['client:', 'research', 'no-research']);
$baseDir = dirname(__DIR__).'/api';

// inc.php definiert resultInfo/ApiError und laedt config/database/session.
// Im CLI gibt es keine 'action' -> api.call.php gibt einmal API_ACTION_NOT_SPECIFIED
// aus; diese Ausgabe wird per Output-Buffer verworfen.
ob_start();
require_once $baseDir.'/trading/lib/load.php';
require_once $baseDir.'/inc.php';
ob_get_clean();

/**
 * Hilfsfunktionen des Cronjobs
 */
class TradingCron {

    /**
     * Alle Mandanten aus der Auth-DB
     *
     * @return array
     */
    public static function clients(): array {
        $pdo = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
        return $pdo->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Setzt die Verbindung des DbhCompany-Singletons (null = zurücksetzen)
     *
     * @param PDO|null $pdo Verbindung zur Firmen-Datenbank
     */
    public static function setCompanyDb(?PDO $pdo): void {
        $prop = (new ReflectionClass('DbhCompany'))->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $pdo ? new ApiDatabase($pdo) : null);
        if ($pdo) DbhCompany::setActor(DbhCompany::begin(), null, 'cron:trading');
    }
}

$stamp = date('Y-m-d H:i:s');
try {
    $clients = TradingCron::clients();
} catch (Throwable $e) {
    echo "[{$stamp}] [FEHLER] Auth-DB nicht erreichbar: " . $e->getMessage() . "\n";
    exit(1);
}

$exit = 0;
foreach ($clients as $client) {
    if (isset($opt['client']) && (int)$opt['client'] !== (int)$client['id']) continue;
    $name = $client['name'] ?? ('ID ' . $client['id']);
    try {
        $pdo = connectPDO($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd']);
    } catch (Throwable $e) {
        if (!companyDbMissing($e)) echo "[{$stamp}] [{$name}] Verbindung fehlgeschlagen: " . $e->getMessage() . "\n";
        continue;
    }
    try {
        TradingCron::setCompanyDb($pdo);
        $db = DbhCompany::begin();
        if (!hasExtensionsTable($db) || !isExtensionActive($db, 'trading')) continue;
        if (!existingTables($db, ['settings_trading', 'users_trading'])) continue;   // Schema-Update steht noch aus
        if (backgroundServicesDisabled($pdo)) continue;

        $summary = TradingEngine::run($db, [
            'force_research' => isset($opt['research']),
            'skip_research'  => isset($opt['no-research']),
        ]);
        // Ruhige Läufe nicht protokollieren — sonst füllt der 10-Minuten-Takt das Log
        if (!empty($summary['bars']) || !empty($summary['orders']) || !empty($summary['errors']) || isset($summary['research']) || isset($summary['report'])) {
            echo "[{$stamp}] [{$name}] " . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";
        }
        if (!empty($summary['errors'])) $exit = 1;
    } catch (Throwable $e) {
        echo "[{$stamp}] [{$name}] FEHLER: " . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        $exit = 1;
    } finally {
        TradingCron::setCompanyDb(null);
    }
}
exit($exit);
