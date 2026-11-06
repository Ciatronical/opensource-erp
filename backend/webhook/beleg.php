<?php
// backend/webhook/beleg.php
//
// Öffentlicher Abruf einer per SMS verschickten Datei (Angebot, Rechnung, …).
// KEIN Session-Check — der Empfänger öffnet den Link aus der SMS im Browser.
//
// URL: https://<host>/webhook/beleg.php?k=<dbname>.<link_token>
// Der Schlüssel ist der link_token aus sms_messages (16 Zeichen, zufällig).
// Die Datei liegt unter backend/data/<dbname>/<media_url>; realpath muss im
// Datenverzeichnis des Mandanten liegen (kein Path-Traversal). Abgelaufene
// Links (link_expires) liefern 410. Der erste Abruf wird festgehalten
// (link_opened_at) – der Chat zeigt dann „geöffnet“.

require_once __DIR__.'/../api/config.php';

function belegFehler(int $code, string $text): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<title>'.htmlspecialchars($text).'</title></head><body style="font-family:sans-serif;padding:2rem;color:#333">'
        .'<p>'.htmlspecialchars($text).'</p></body></html>';
    exit;
}

if (OSERP_SETUP_MODE) belegFehler(503, 'Nicht verfügbar');
OserpConfig::init();

$k = (string)($_GET['k'] ?? '');
if (!preg_match('/^([a-zA-Z0-9_-]+)\.([A-Za-z0-9]{16})$/', $k, $m)) belegFehler(404, 'Link ungültig');
[, $dbname, $token] = $m;

try {
    $auth = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME), DB_AUTH_USER, DB_AUTH_PASS);
    $auth->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $st = $auth->prepare("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE dbname = :db");
    $st->execute([':db' => $dbname]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    if (!$client) belegFehler(404, 'Link ungültig');

    $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $client['dbhost'], $client['dbport'], $client['dbname']),
                   $client['dbuser'], $client['dbpasswd']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Abruf vermerken und Datei in einem Zug lesen
    $st = $pdo->prepare(
        "UPDATE sms_messages SET link_opened_at = COALESCE(link_opened_at, now())
          WHERE link_token = :t AND link_expires > now() AND NOT hidden
          RETURNING media_url, media_name"
    );
    $st->execute([':t' => $token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $st = $pdo->prepare("SELECT 1 FROM sms_messages WHERE link_token = :t");
        $st->execute([':t' => $token]);
        $abgelaufen = (bool)$st->fetch();
        belegFehler($abgelaufen ? 410 : 404, $abgelaufen ? 'Dieser Link ist abgelaufen.' : 'Link ungültig');
    }
} catch (\Throwable $e) {
    error_log('[BELEG LINK] '.$e->getMessage());
    belegFehler(500, 'Fehler beim Abruf');
}

$base = realpath(__DIR__.'/../data/'.$dbname);
$real = $base ? realpath($base.'/'.$row['media_url']) : false;
if ($real === false || strncmp($real, $base.'/', strlen($base) + 1) !== 0 || !is_file($real)) {
    belegFehler(404, 'Datei nicht gefunden');
}

$typen = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
          'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg'];
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$name = $row['media_name'] ?: basename($real);
header('Content-Type: '.($typen[$ext] ?? 'application/octet-stream'));
header('Content-Length: '.filesize($real));
header('Content-Disposition: '.(isset($typen[$ext]) ? 'inline' : 'attachment').'; filename="'.addcslashes($name, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($name));
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
readfile($real);
