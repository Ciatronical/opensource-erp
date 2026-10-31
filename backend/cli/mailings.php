#!/usr/bin/env php
<?php
/**
 * CLI-Script: Rundschreiben in Etappen versenden
 *
 * Durchlaeuft alle Mandanten und sendet die faelligen Etappen laufender
 * Rundschreiben (Kontakt → Rundschreiben): je Etappe batch_size Nachrichten,
 * danach batch_pause_minutes Pause, nur im Zeitfenster send_from–send_until.
 * Die Logik steht in backend/api/mailings/mailings.php (mailingProcessDue);
 * dieselbe Funktion stoesst auch die offene Rundschreiben-Seite an, der Cron
 * sorgt dafuer, dass der Versand ohne offenen Browser weiterlaeuft.
 *
 * Aufruf:
 *   php backend/cli/mailings.php            (alle Mandanten)
 *   php backend/cli/mailings.php --client 3 (nur ein Mandant)
 *
 * Cron-Beispiel (alle 5 Minuten):
 *   0-59/5 * * * * cd /home/work/opensource-erp && php backend/cli/mailings.php >> log/mailings.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur ueber die Kommandozeile ausgefuehrt werden.\n");
}

$baseDir = dirname(__DIR__) . '/api';

// Modul laden; inc.php (am Ende von index.php) will einen HTTP-Request bedienen
// und meldet "keine Aktion" — das wird verschluckt.
ob_start();
require_once $baseDir . '/mailings/index.php';
ob_get_clean();

$onlyId = 0;
foreach ($argv as $i => $a) {
    if ($a === '--client' && isset($argv[$i + 1])) $onlyId = intval($argv[$i + 1]);
}

function mailingsClients(): array {
    $pdo = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
    return $pdo->query("SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}
function mailingsInitDb(PDO $pdo): void {
    $prop = (new ReflectionClass('DbhCompany'))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, new ApiDatabase($pdo));
}
function mailingsResetDb(): void {
    $prop = (new ReflectionClass('DbhCompany'))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

$start = date('Y-m-d H:i:s');
$total = ['mailings' => 0, 'sent' => 0, 'failed' => 0];

try {
    $clients = mailingsClients();
} catch (Throwable $e) {
    echo "[{$start}] [FEHLER] Auth-DB nicht erreichbar: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($clients as $client) {
    if ($onlyId && intval($client['id']) !== $onlyId) continue;
    $label = $client['name'] ?? ('ID ' . $client['id']);
    try {
        $pdo = connectPDO($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd']);
        mailingsInitDb($pdo);
        // Mandanten ohne das Modul (Upstall noch nicht gelaufen) still ueberspringen
        $has = $pdo->query("SELECT to_regclass('public.mailings') IS NOT NULL AS ok")->fetch(PDO::FETCH_ASSOC);
        if (empty($has['ok'])) continue;

        $r = mailingProcessDue(DbhCompany::begin());
        if ($r['mailings'] > 0) {
            echo "[{$start}] [{$label}] {$r['mailings']} Etappe(n): {$r['sent']} gesendet, {$r['failed']} fehlgeschlagen, {$r['emails']} E-Mails zusätzlich, {$r['letters']} Briefe zusätzlich\n";
        }
        foreach ($r as $k => $v) $total[$k] = ($total[$k] ?? 0) + $v;
    } catch (Throwable $e) {
        echo "[{$start}] [{$label}] Fehler: " . $e->getMessage() . "\n";
    } finally {
        mailingsResetDb();
    }
}

if ($total['mailings'] > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Gesamt: {$total['sent']} gesendet, {$total['failed']} fehlgeschlagen\n";
}
