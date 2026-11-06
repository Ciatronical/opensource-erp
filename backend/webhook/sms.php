<?php
// backend/webhook/sms.php
// seven.io-Webhook: eingehende SMS (sms_mo) und Zustellberichte (dlr)
// KEIN Session-Check — seven.io sendet keine Cookies!
//
// Angemeldet wird der Webhook aus der Firmenkonfiguration (SMS → Einrichten,
// registerSmsWebhook). Die Adresse trägt Mandant und Geheimnis:
//   https://<host>/webhook/sms.php?db=<dbname>&key=<sms_webhook_secret>
// Ist in der Firmenkonfiguration zusätzlich der Signierschlüssel aus dem
// seven.io-Konto hinterlegt (sms_signing_key), wird X-Signature geprüft.
//
// seven.io wiederholt nur bei 5xx/429 und Verbindungsfehlern – unbekannte
// Mandanten und falsche Schlüssel bekommen deshalb 4xx (endgültig).

header('Content-Type: application/json');

require_once __DIR__.'/../api/config.php';

if (OSERP_SETUP_MODE) {
    http_response_code(503);
    echo json_encode(['error' => 'Setup not complete']);
    exit;
}

OserpConfig::init();
require_once __DIR__.'/../api/error.php';
require_once __DIR__.'/../api/logging.php';
require_once __DIR__.'/../api/database.php';
require_once __DIR__.'/../api/sms/sms_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['status' => 'sms webhook ready']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$dbname = (string)($_GET['db'] ?? '');
$key = (string)($_GET['key'] ?? '');
$body = (string)file_get_contents('php://input');
$input = json_decode($body, true);
if (!is_array($input)) $input = $_POST;

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $dbname) || $key === '') {
    http_response_code(400);
    echo json_encode(['error' => 'bad request']);
    exit;
}

try {
    $auth = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME), DB_AUTH_USER, DB_AUTH_PASS);
    $auth->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $st = $auth->prepare("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE dbname = :db");
    $st->execute([':db' => $dbname]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        http_response_code(404);
        echo json_encode(['error' => 'unknown client']);
        exit;
    }
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $client['dbhost'], $client['dbport'], $client['dbname']),
                   $client['dbuser'], $client['dbpasswd']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db = new ApiDatabase($pdo);
    $cfg = smsSettings($db);

    if ($cfg['webhook_secret'] === '' || !hash_equals($cfg['webhook_secret'], $key)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }

    // Optional: Signatur von seven.io (HMAC-SHA256 über Zeit, Nonce, Methode, Adresse, MD5 des Inhalts)
    if ($cfg['signing_key'] !== '') {
        $ts = (string)($_SERVER['HTTP_X_TIMESTAMP'] ?? '');
        $nonce = (string)($_SERVER['HTTP_X_NONCE'] ?? '');
        $sig = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');
        $url = rtrim($cfg['public_url'], '/').($_SERVER['REQUEST_URI'] ?? '');
        $teile = [$ts, $nonce, 'POST', $url, md5($body)];
        if (!empty($_SERVER['HTTP_X_ACCOUNT_ID'])) $teile[] = (string)$_SERVER['HTTP_X_ACCOUNT_ID'];
        $soll = hash_hmac('sha256', implode("\n", $teile), $cfg['signing_key']);
        if ($sig === '' || abs(time() - (int)$ts) > 300 || !hash_equals($soll, strtolower($sig))) {
            error_log('[SMS WEBHOOK] Signatur ungültig für '.$dbname);
            http_response_code(401);
            echo json_encode(['error' => 'invalid signature']);
            exit;
        }
    }

    $event = (string)($input['webhook_event'] ?? '');
    $d = is_array($input['data'] ?? null) ? $input['data'] : [];

    if ($event === 'sms_mo') {
        // Eingehende SMS: Absender als +…, Kunde über die Rufnummer; doppelte Zustellung über sms_id abfangen
        $sender = trim((string)($d['sender'] ?? ''));
        $phone = preg_match('/^\+?\d{6,}$/', $sender) ? '+'.ltrim($sender, '+') : $sender;
        $db->execute(
            "INSERT INTO sms_messages (sms_id, direction, phone_number, customer_id, contact_name, message_text, status, source, itime)
             SELECT CAST(:sms_id AS text), 'I', CAST(:phone AS text), f.cv_id, CASE WHEN CAST(:phone AS text) !~ '^\\+' THEN CAST(:phone AS text) END,
                    CAST(:text AS text), 'received', 'chat',
                    COALESCE(to_timestamp(NULLIF(CAST(:time AS text), '')::bigint), now())
               FROM (SELECT 1) x LEFT JOIN cv_by_phone(CAST(:phone AS text)) f ON TRUE
              WHERE NOT EXISTS (SELECT 1 FROM sms_messages WHERE direction = 'I' AND sms_id = CAST(:sms_id AS text))",
            [
                ':sms_id' => (string)($d['id'] ?? ''),
                ':phone' => $phone,
                ':text' => (string)($d['text'] ?? ''),
                ':time' => ctype_digit((string)($d['time'] ?? '')) ? (string)$d['time'] : '',
            ]
        );
    } elseif ($event === 'dlr') {
        // Zustellbericht: DELIVERED → delivered, endgültige Fehler → failed, sonst bleibt sent
        $status = strtoupper((string)($d['status'] ?? ''));
        $neu = $status === 'DELIVERED' ? 'delivered'
             : (in_array($status, ['NOTDELIVERED', 'REJECTED', 'FAILED', 'EXPIRED'], true) ? 'failed' : null);
        if ($neu) {
            $db->execute(
                "UPDATE sms_messages
                    SET status = CAST(:status AS text), status_timestamp = now(), mtime = now(),
                        error_code = CASE WHEN CAST(:status AS text) = 'failed' THEN CAST(:code AS text) ELSE error_code END,
                        error_message = CASE WHEN CAST(:status AS text) = 'failed' THEN 'Zustellung fehlgeschlagen (' || CAST(:code AS text) || ')' ELSE error_message END
                  WHERE direction = 'O' AND sms_id = CAST(:sms_id AS text) AND status <> CAST(:status AS text)",
                [':status' => $neu, ':code' => $status, ':sms_id' => (string)($d['msg_id'] ?? '')]
            );
        }
    }
    echo json_encode(['status' => 'ok']);
} catch (\Throwable $e) {
    error_log('[SMS WEBHOOK] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal']);
}
