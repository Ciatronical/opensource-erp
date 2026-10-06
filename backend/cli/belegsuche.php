#!/usr/bin/env php
<?php
/**
 * CLI-Script: Belegsuche ("Magisch Buchen")
 *
 * Durchlaeuft alle Mandanten und holt neue Eingangsbelege aus den eingerichteten
 * Quellen (Postfach, WhatsApp, Server-Ordner). Die Belege werden abgelegt,
 * ausgelesen und als Buchungsvorschlag bereitgestellt; gebucht wird erst, wenn
 * der Nutzer im ERP auf "Magisch Buchen" klickt.
 *
 * Laeuft je Mandant einmal taeglich ab der in den Einstellungen gewaehlten
 * Uhrzeit (defaults_oserp: belegsuche_enabled, belegsuche_time). Der Cron darf
 * deshalb engmaschig laufen, der Zeitpunkt wird hier geprueft.
 *
 * Aufruf:
 *   php backend/cli/belegsuche.php            (planmaessig)
 *   php backend/cli/belegsuche.php --force    (sofort, alle aktiven Mandanten)
 *   php backend/cli/belegsuche.php --client 3 (nur ein Mandant)
 *
 * Cron-Beispiel (alle 10 Minuten pruefen; die Zeile steht auch in den
 * Einstellungen unter Belegsuche):
 *   0,10,20,30,40,50 * * * * cd /home/work/opensource-erp && php backend/cli/belegsuche.php >> log/belegsuche.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur ueber die Kommandozeile ausgefuehrt werden.\n");
}

$baseDir = dirname(__DIR__) . '/api';

// Komplettes Buchhaltungsmodul laden; inc.php (am Ende von index.php) will einen
// HTTP-Request bedienen und meldet "keine Aktion" — das wird verschluckt.
ob_start();
require_once $baseDir . '/accounting/index.php';
ob_get_clean();

$force    = in_array('--force', $argv, true);
$onlyId   = 0;
foreach ($argv as $i => $a) {
    if ($a === '--client' && isset($argv[$i + 1])) $onlyId = intval($argv[$i + 1]);
}

function belegsucheClients(): array {
    $pdo = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
    return $pdo->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}
function belegsucheInitDb(PDO $pdo): void {
    $prop = (new ReflectionClass('DbhCompany'))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, new ApiDatabase($pdo));
}
function belegsucheResetDb(): void {
    $prop = (new ReflectionClass('DbhCompany'))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

echo '[' . date('Y-m-d H:i:s') . "] Belegsuche gestartet" . ($force ? ' (--force)' : '') . "\n";

try {
    $clients = belegsucheClients();
} catch (Exception $e) {
    echo "[FEHLER] Auth-DB nicht erreichbar: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($clients as $client) {
    if ($onlyId && intval($client['id']) !== $onlyId) continue;
    try {
        $pdo = connectPDO($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd']);
        belegsucheInitDb($pdo);
        $db = DbhCompany::begin();

        // Tabelle vorhanden? (Schema-Update noch nicht eingespielt → ueberspringen)
        $has = $db->getOne("SELECT to_regclass('public.beleg_quellen') AS t");
        if (empty($has['t'])) { echo "  [{$client['dbname']}] keine Belegsuche-Tabellen, uebersprungen\n"; continue; }

        $cfg = $db->fetchKeyValue("SELECT key, value FROM defaults_oserp WHERE key IN ('belegsuche_enabled', 'belegsuche_time', 'belegsuche_last_run')");
        $enabled = in_array((string)($cfg['belegsuche_enabled'] ?? ''), ['1', 't', 'true'], true);
        if (!$enabled && !$force) continue;

        $time    = preg_match('/^\d{1,2}:\d{2}$/', (string)($cfg['belegsuche_time'] ?? '')) ? $cfg['belegsuche_time'] : '06:00';
        $lastRun = substr((string)($cfg['belegsuche_last_run'] ?? ''), 0, 10);
        $due     = $lastRun !== date('Y-m-d') && strtotime(date('Y-m-d') . ' ' . $time) <= time();
        if (!$due && !$force) continue;

        echo "  [{$client['dbname']}] Belegsuche laeuft ...\n";
        ob_start();
        runBelegSuche(['employee_id' => null]);
        $res = json_decode(ob_get_clean(), true);
        if (!empty($res['success'])) {
            foreach (($res['payload']['sources'] ?? []) as $src) {
                printf("     %-8s %-30s %s: %d importiert, %d Duplikate, %d keine Belege, %d Fehler%s\n",
                    $src['type'], mb_substr($src['name'], 0, 30), $src['status'],
                    $src['imported'] ?? 0, $src['duplicate'] ?? 0, $src['not_invoice'] ?? 0, $src['error'] ?? 0,
                    !empty($src['message']) ? ' — ' . $src['message'] : '');
            }
        } else {
            echo "     [FEHLER] " . ($res['text'] ?? 'unbekannt') . "\n";
        }
    } catch (Throwable $e) {
        echo "  [{$client['dbname']}] [FEHLER] " . $e->getMessage() . "\n";
    } finally {
        belegsucheResetDb();
    }
}
echo '[' . date('Y-m-d H:i:s') . "] Belegsuche beendet\n";
