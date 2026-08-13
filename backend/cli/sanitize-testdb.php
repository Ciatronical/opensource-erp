#!/usr/bin/env php
<?php
/**
 * Test-Datenbank nach dem nächtlichen Klon entschärfen
 *
 * Der Klon zieht die Produktiv-DB 1:1 in die Test-DB — inklusive Telegram-Zugangsdaten.
 * Das ist gefährlich, weil beide DBs danach dasselbe telegram_webhook_secret tragen:
 * _findCompanyDbBySecret() in backend/webhook/telegram.php durchläuft auth.clients ohne
 * feste Reihenfolge und nimmt den ersten Treffer. Kippt die Zeilenreihenfolge, landen
 * echte Sprachnotizen in der Test-DB und erscheinen auf der Tafel nie.
 *
 * Dieses Skript neutralisiert in der Test-DB:
 *   - telegram_webhook_secret  (leer -> der Webhook lehnt den Mandanten explizit ab)
 *   - telegram_bot_token       (leer -> kein Zugriff auf den echten Bot)
 *   - telegram_enabled         (0)
 *   - voice_notes              (geleert, inkl. Sequenz-Reset)
 *
 * Aufruf:
 *   php backend/cli/sanitize-testdb.php lack_db_test
 *
 * Eingehängt ist das Skript in /usr/local/sbin/storage_backup/backup.sh, und zwar auf
 * der Zwischen-DB <test-db>_new — also bevor sie an ihren endgültigen Namen umbenannt
 * wird. Dadurch ist die Test-DB zu keinem Zeitpunkt mit gültigem Secret erreichbar.
 * Das Ziel muss deshalb nicht in auth.clients stehen; die Zugangsdaten werden dann vom
 * Produktivmandanten geerbt. Die Produktivdatenbank selbst lehnt das Skript immer ab.
 */

if (php_sapi_name() !== 'cli') {
    die("Nur über die Kommandozeile ausführbar.\n");
}

$baseDir = dirname(__DIR__).'/api';
require_once $baseDir.'/config.php';
require_once $baseDir.'/logging.php';
require_once $baseDir.'/database.php';

$target = $argv[1] ?? '';
if ($target === '') {
    fwrite(STDERR, "Aufruf: php backend/cli/sanitize-testdb.php <test-dbname>\n");
    exit(1);
}

$auth = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);

// Ziel und Produktivmandant in einer Abfrage holen. Das Ziel darf auch eine DB sein,
// die (noch) nicht in auth.clients steht — backup.sh importiert den Klon zuerst nach
// <test-db>_new und benennt erst danach um. Genau dort wird entschärft, damit die
// Test-DB nie mit gültigem Telegram-Secret erreichbar ist.
$stmt = $auth->prepare(
    "SELECT (SELECT dbname FROM auth.clients WHERE COALESCE(is_default, FALSE) LIMIT 1) AS prod_db,
            c.dbhost, c.dbport, c.dbuser, c.dbpasswd, c.name
       FROM (SELECT 1) AS dummy
       LEFT JOIN auth.clients c ON c.dbname = :db"
);
$stmt->execute([':db' => $target]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Harte Sperre: niemals die Produktivdatenbank entschärfen.
if ($row && $row['prod_db'] !== null && $row['prod_db'] === $target) {
    fwrite(STDERR, "ABBRUCH: '$target' ist die Produktivdatenbank. Wird nicht angefasst.\n");
    exit(1);
}

// Verbindungsdaten: vom Mandanten selbst, sonst vom Produktivmandanten erben
// (gleicher Server, gleiche Zugangsdaten — nur der DB-Name unterscheidet sich).
if ($row && $row['dbhost'] !== null) {
    $conn = ['host' => $row['dbhost'], 'port' => $row['dbport'], 'user' => $row['dbuser'], 'pass' => $row['dbpasswd']];
    $label = $row['name'];
} else {
    $prod = $auth->query(
        "SELECT dbhost, dbport, dbuser, dbpasswd FROM auth.clients
          WHERE COALESCE(is_default, FALSE) LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$prod) {
        fwrite(STDERR, "FEHLER: Kein Produktivmandant (is_default) in auth.clients gefunden.\n");
        exit(1);
    }
    $conn = ['host' => $prod['dbhost'], 'port' => $prod['dbport'], 'user' => $prod['dbuser'], 'pass' => $prod['dbpasswd']];
    $label = 'Zwischenstand des Klons';
}

try {
    $db = connectPDO($conn['host'], $conn['port'], $target, $conn['user'], $conn['pass']);
} catch (\Throwable $e) {
    fwrite(STDERR, "FEHLER: Verbindung zu '$target' fehlgeschlagen: ".$e->getMessage()."\n");
    exit(1);
}

echo "Entschärfe Test-Datenbank '{$target}' ({$label}) ...\n";

$db->beginTransaction();

// Telegram-Zugangsdaten in einem Rutsch neutralisieren. Nur vorhandene Schlüssel
// werden angefasst — fehlende sind ohnehin unkritisch.
$upd = $db->prepare(
    "UPDATE defaults_oserp
        SET value = CASE key WHEN 'telegram_enabled' THEN '0' ELSE '' END,
            mtime = NOW()
      WHERE key IN ('telegram_webhook_secret', 'telegram_bot_token', 'telegram_enabled')"
);
$upd->execute();
echo "  {$upd->rowCount()} Telegram-Konfigurationswerte neutralisiert.\n";

// Sprachnotizen verwerfen — Testdaten, die sonst als echte Einträge auf der Tafel stehen.
$db->exec("TRUNCATE TABLE voice_notes RESTART IDENTITY");
echo "  voice_notes geleert.\n";

$db->commit();

// Kontrolle: das Secret darf danach nirgends mehr mit dem Produktivmandanten kollidieren.
$rest = $db->query(
    "SELECT key FROM defaults_oserp
      WHERE key IN ('telegram_webhook_secret', 'telegram_bot_token')
        AND COALESCE(value, '') <> ''"
)->fetchAll(PDO::FETCH_COLUMN);

if ($rest) {
    fwrite(STDERR, "WARNUNG: Noch gesetzt: ".implode(', ', $rest)."\n");
    exit(1);
}

echo "Fertig. '{$target}' hat keine Telegram-Zugangsdaten mehr.\n";
