<?php
/**
 * Kamera-Live-Snapshot
 *
 * Liefert ein aktuelles JPEG-Bild einer Kamera aus.
 * Strategie:
 *   1. go2rtc (http://go2rtc/api/frame.jpeg?src=cam_key) — schnell, kein Prozess-Overhead
 *   2. ffmpeg direkt auf RTSP — Fallback wenn go2rtc nicht konfiguriert ist
 *
 * Nutzung: /api/camera/stream.php?id=1
 */

require_once __DIR__.'/../config.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../database.php';
require_once __DIR__.'/../session.php';

$auth = DbhAuth::begin();
if (!$auth->hasSession()) {
    http_response_code(401);
    exit('Keine Session');
}

$cameraId = (int)($_GET['id'] ?? 0);
if ($cameraId <= 0) {
    http_response_code(400);
    exit('Keine Kamera-ID');
}

$auth->fetchSessionData();
$db = DbhCompany::begin();

$camera = $db->getOne(
    "SELECT cam_key, stream_url FROM camera WHERE id = :id AND active",
    [':id' => $cameraId]
);
if (!$camera) {
    http_response_code(404);
    exit('Kamera nicht gefunden');
}

$snapshotDir = __DIR__ . '/../../data/camera-snapshots';
if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);
$snapshotPath = "$snapshotDir/live_cam_{$cameraId}.jpg";

// --- 1. go2rtc: Frame per HTTP holen (kein eigener Prozess) ------------------

$go2rtcRow = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'camera_go2rtc_url'");
$go2rtcUrl = $go2rtcRow ? rtrim($go2rtcRow['value'], '/') : '';

if ($go2rtcUrl && $camera['cam_key']) {
    $frameUrl = $go2rtcUrl . '/api/frame.jpeg?src=' . urlencode($camera['cam_key']);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $img = @file_get_contents($frameUrl, false, $ctx);

    if ($img !== false && strlen($img) > 0) {
        file_put_contents($snapshotPath, $img);
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $img;
        exit;
    }
}

// --- 2. Fallback: ffmpeg direkt auf RTSP -------------------------------------

if (empty($camera['stream_url'])) {
    if (file_exists($snapshotPath)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Snapshot-Stale: 1');
        readfile($snapshotPath);
        exit;
    }
    http_response_code(502);
    exit('Keine Stream-URL konfiguriert');
}

// -stimeout 8000000   => max. 8s auf RTSP-Verbindung warten (Mikrosekunden)
// -analyzeduration    => Streamanalyse beschleunigen
$cmd = sprintf(
    'timeout 15 ffmpeg -rtsp_transport tcp -stimeout 8000000'
    . ' -analyzeduration 1000000 -probesize 500000'
    . ' -i %s -frames:v 1 -q:v 3 -y %s 2>/dev/null',
    escapeshellarg($camera['stream_url']),
    escapeshellarg($snapshotPath)
);

exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !file_exists($snapshotPath)) {
    if (file_exists($snapshotPath)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Snapshot-Stale: 1');
        readfile($snapshotPath);
        exit;
    }
    http_response_code(502);
    exit('Stream nicht erreichbar');
}

header('Content-Type: image/jpeg');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
readfile($snapshotPath);
