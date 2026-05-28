<?php
/**
 * MJPEG-Proxy für go2rtc
 *
 * Tunnelt den go2rtc MJPEG-Stream durch den HTTPS-Webserver,
 * damit kein Mixed-Content-Fehler entsteht.
 *
 * Nutzung: /api/camera/mjpeg.php?id=1
 */

require_once __DIR__.'/../config.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../database.php';
require_once __DIR__.'/../session.php';

$auth = DbhAuth::begin();
if (!$auth->hasSession()) {
    http_response_code(401);
    exit;
}

$cameraId = (int)($_GET['id'] ?? 0);
if ($cameraId <= 0) {
    http_response_code(400);
    exit;
}

$db = DbhCompany::begin();
$camera = $db->getOne(
    "SELECT cam_key FROM camera WHERE id = :id AND active",
    [':id' => $cameraId]
);
if (!$camera || empty($camera['cam_key'])) {
    http_response_code(404);
    exit;
}

$row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'camera_go2rtc_url'");
$go2rtcUrl = $row ? rtrim($row['value'], '/') : 'http://127.0.0.1:1984';

$mjpegUrl = $go2rtcUrl . '/api/stream.mjpeg?src=' . urlencode($camera['cam_key']);

$ctx = stream_context_create(['http' => ['timeout' => 60]]);
$stream = @fopen($mjpegUrl, 'rb', false, $ctx);
if (!$stream) {
    http_response_code(502);
    exit;
}

foreach ($http_response_header ?? [] as $h) {
    if (stripos($h, 'Content-Type:') === 0) {
        header($h);
        break;
    }
}
header('Cache-Control: no-cache, no-store');

while (!feof($stream) && !connection_aborted()) {
    echo fread($stream, 8192);
    flush();
}
fclose($stream);
