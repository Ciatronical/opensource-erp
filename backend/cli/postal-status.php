#!/usr/bin/env php
<?php
/**
 * CLI-Script: Status der Postsendungen beim Briefdienstleister abfragen
 *
 * Durchläuft alle Mandanten und fragt für jede offene Einlieferung bei
 * LetterXpress (postal_dispatches, provider = letterxpress, status = sent)
 * den Auftragsstatus samt Sendungsverfolgung ab. Gedruckte und versendete
 * Briefe bekommen status = done; Einschreiben tragen anschließend ihre
 * Sendungsnummer und den Zustellstatus.
 *
 * PIN AG (SFTP/FTPS) meldet über die Schnittstelle nichts zurück — diese
 * Einlieferungen bleiben auf „eingeliefert", die Sendungsverfolgung läuft
 * dort über das Kundenportal.
 *
 * Aufruf:
 *   php backend/cli/postal-status.php
 *   php backend/cli/postal-status.php --client 10   # nur dieser Mandant
 *
 * Cron-Beispiel (stündlich zur vollen Stunde):
 *   0 * * * * cd /home/work/opensource-erp && php backend/cli/postal-status.php >> log/postal-status.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur über die Kommandozeile ausgeführt werden.\n");
}

$opt = getopt('', ['client:', 'help']);
if (isset($opt['help'])) {
    echo "Aufruf: php backend/cli/postal-status.php [--client ID]\n";
    exit(0);
}

$baseDir = dirname(__DIR__).'/api';

// inc.php definiert resultInfo/ApiError und lädt config/database/session.
// Im CLI gibt es keine 'action' → api.call.php gibt einmal API_ACTION_NOT_SPECIFIED
// aus; diese Ausgabe wird per Output-Buffer verworfen.
ob_start();
require_once $baseDir.'/inc.php';
ob_get_clean();

require_once $baseDir.'/letters/letters.php';
require_once $baseDir.'/letters/postal.php';

/**
 * Alle Mandanten aus der Auth-DB laden (optional nur einer)
 */
function postalCronGetClients(?int $onlyId): array {
    $pdo = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
    $sql = "SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients";
    if ($onlyId) $sql .= " WHERE id = " . intval($onlyId);
    $stmt = $pdo->query($sql . " ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mandanten-DB für DbhCompany setzen (wie recurring-invoices.php)
 */
function postalCronSetCompanyDb(?PDO $pdo): void {
    $reflection = new ReflectionClass('DbhCompany');
    $prop = $reflection->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo ? new ApiDatabase($pdo) : null);
}

$hadErrors = false;
foreach (postalCronGetClients(isset($opt['client']) ? intval($opt['client']) : null) as $client) {
    $clientName = $client['name'];
    try {
        $pdo = connectPDO($client['dbhost'] ?: DB_HOST, $client['dbport'] ?: DB_PORT, $client['dbname'], $client['dbuser'], $client['dbpasswd']);
    } catch (\Throwable $e) {
        echo "[{$clientName}] übersprungen: " . $e->getMessage() . "\n";
        continue;
    }
    try {
        postalCronSetCompanyDb($pdo);
        $db = DbhCompany::begin();
        $ok = $db->getOne("SELECT to_regclass('public.postal_dispatches') IS NOT NULL AS ok");
        if (empty($ok['ok'])) {
            continue; // Mandant ohne Schema-Update
        }
        $result = postalRefreshOpenDispatches($db);
        if ($result['checked'] > 0 || $result['errors']) {
            echo "[{$clientName}] {$result['checked']} Einlieferungen geprüft, {$result['done']} abgeschlossen\n";
        }
        foreach ($result['errors'] as $err) {
            echo "[{$clientName}] Fehler {$err}\n";
            $hadErrors = true;
        }
    } catch (\Throwable $e) {
        echo "[{$clientName}] Fehler: " . $e->getMessage() . "\n";
        $hadErrors = true;
    } finally {
        postalCronSetCompanyDb(null);
    }
}
exit($hadErrors ? 1 : 0);
