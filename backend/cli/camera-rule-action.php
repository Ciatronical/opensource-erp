#!/usr/bin/env php
<?php
/**
 * CLI-Script: Aktion einer Kamera-Regel ausführen (WhatsApp, E-Mail)
 *
 * Wird vom Kamera-Monitor (backend/services/camera-monitor/camera_monitor.py)
 * aufgerufen, sobald eine Regel mit der Aktion "whatsapp" oder "email" auslöst.
 * Der Versand läuft bewusst über die vorhandenen Module, damit Zugangsdaten,
 * 24h-Fenster, Vorlagen und SMTP-Einstellungen an genau einer Stelle liegen.
 *
 * Wartezeit und Zähler (last_triggered_at, trigger_count) führt der Monitor in
 * der Datenbank, bevor er dieses Script startet — hier wird nur gesendet.
 *
 * Aufruf:
 *   php backend/cli/camera-rule-action.php --db=<Firmendatenbank> --rule=<Regel-ID> --message=<Text> [--event=<Ereignis-ID>]
 *
 * Mit --event wird bei E-Mails das Standbild des Ereignisses angehängt.
 *
 * Rückgabewert: 0 = gesendet, 1 = Fehler
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script darf nur über die Kommandozeile ausgeführt werden.\n");
}

$opts = getopt('', ['db:', 'rule:', 'message:', 'event:']);
$dbName = $opts['db'] ?? '';
$ruleId = (int)($opts['rule'] ?? 0);
$message = trim($opts['message'] ?? '');
$eventId = trim($opts['event'] ?? '');
if ($dbName === '' || $ruleId <= 0 || $message === '') {
    fwrite(STDERR, "Aufruf: camera-rule-action.php --db=<Firmendatenbank> --rule=<Regel-ID> --message=<Text> [--event=<Ereignis-ID>]\n");
    exit(1);
}

$baseDir = dirname(__DIR__).'/api';

// Einzelne Komponenten laden (OHNE api.call.php, das dispatcht HTTP-Requests)
require_once $baseDir.'/error.php'; // resultInfo()
require_once $baseDir.'/config.php';
require_once $baseDir.'/logging.php';
require_once $baseDir.'/database.php';
require_once $baseDir.'/session.php';
require_once $baseDir.'/whatsapp/whatsapp.php';
require_once $baseDir.'/email/mailer.php';

/**
 * WhatsApp über das WhatsApp-Modul senden (inkl. Vorlagen-Rückfall außerhalb
 * des 24h-Fensters). Liefert eine Fehlermeldung oder null bei Erfolg.
 */
function cameraRuleSendWhatsApp(array $rule, array $config, string $message): ?string {
    $phone = trim($config['phone'] ?? '');
    if ($phone === '') return 'keine WhatsApp-Nummer hinterlegt';

    ob_start();
    _sendWhatsAppMessageRaw([
        'to' => $phone,
        'message' => $message,
        // Für den Vorlagen-Rückfall außerhalb des 24h-Fensters ({{1}} = Anrede)
        'customer_name' => $rule['name'],
    ]);
    $result = json_decode(ob_get_clean(), true);
    if ($result && !empty($result['success'])) return null;
    return trim(($result['text'] ?? 'UNBEKANNT').' '.(is_string($result['payload'] ?? null) ? $result['payload'] : ''));
}

/**
 * E-Mail über den Firmen-SMTP senden, Standbild des Ereignisses als Anhang.
 * Liefert eine Fehlermeldung oder null bei Erfolg.
 */
function cameraRuleSendEmail(array $rule, array $config, string $message, string $eventId): ?string {
    $to = trim($config['email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return 'keine gültige E-Mail-Adresse hinterlegt';

    $db = DbhCompany::begin();
    $emailConfig = _getEmailConfig();
    $smtp = _createSmtpClient($emailConfig);
    $from = $emailConfig['email_address'] ?? $emailConfig['email_username'] ?? '';
    $fromName = trim((string)($db->getOne("SELECT company FROM defaults LIMIT 1")['company'] ?? ''));

    $text = $message."\n\nRegel: ".$rule['name']."\nZeitpunkt: ".date('d.m.Y H:i:s');
    $html = '<p>'.nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')).'</p>';

    // Standbild anhängen — der Dateiname ist die Ereignis-ID, deshalb nur
    // deren erlaubte Zeichen durchlassen.
    $attachments = [];
    if ($eventId !== '' && preg_match('/^[A-Za-z0-9-]+$/', $eventId)) {
        $snapshot = dirname(__DIR__).'/data/camera-snapshots/'.$eventId.'.jpg';
        if (is_file($snapshot)) {
            $attachments[] = [
                'filename' => 'kamera-'.$eventId.'.jpg',
                'content_type' => 'image/jpeg',
                'content' => file_get_contents($snapshot),
            ];
        }
    }

    $smtp->send($from, $fromName, [['email' => $to, 'name' => '']], 'Kamera-Alarm: '.$rule['name'], $html, $text, [], [], $attachments);
    return null;
}

try {
    $auth = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
    $stmt = $auth->prepare("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE dbname = :dbname ORDER BY id LIMIT 1");
    $stmt->execute([':dbname' => $dbName]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        echo "Firmendatenbank '{$dbName}' ist nicht bekannt\n";
        exit(1);
    }

    $pdo = connectPDO($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd']);

    // DbhCompany-Singleton ohne Sitzung initialisieren (wie whatsapp-reminders.php)
    $prop = (new ReflectionClass('DbhCompany'))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, new ApiDatabase($pdo));
    $db = DbhCompany::begin();

    $rule = $db->getOne(
        "SELECT name, action, action_config FROM camera_rule WHERE id = :id AND active",
        [':id' => $ruleId]
    );
    if (!$rule) {
        echo "Regel {$ruleId}: nicht gefunden oder nicht aktiv\n";
        exit(1);
    }
    $config = json_decode($rule['action_config'] ?? '', true) ?: [];

    switch ($rule['action']) {
        case 'whatsapp':
            $label = 'WhatsApp';
            $error = cameraRuleSendWhatsApp($rule, $config, $message);
            break;
        case 'email':
            $label = 'E-Mail';
            $error = cameraRuleSendEmail($rule, $config, $message, $eventId);
            break;
        default:
            echo "Regel '{$rule['name']}': Aktion '{$rule['action']}' wird hier nicht versendet\n";
            exit(1);
    }

    if ($error === null) {
        echo "Regel '{$rule['name']}': {$label} gesendet\n";
        exit(0);
    }
    echo "Regel '{$rule['name']}': {$label}-Versand fehlgeschlagen — {$error}\n";
    writeLog("Kamera-Regel '{$rule['name']}': {$label}-Versand fehlgeschlagen — {$error}", true, DLOG_ERR);
    exit(1);
} catch (Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    echo "Regel {$ruleId}: Fehler — ".$e->getMessage()."\n";
    exit(1);
}
