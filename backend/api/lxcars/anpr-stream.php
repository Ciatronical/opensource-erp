<?php
/**
 * ANPR-Kamera-Snapshot-Proxy
 *
 * Holt ein einzelnes Frame von einer ANPR-Kamera via ffmpeg und liefert es als JPEG.
 * Wird fuer die Live-Vorschau in der ANPR-Konfiguration verwendet.
 *
 * Nutzung: /api/lxcars/anpr-stream.php?id=1
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
    "SELECT rtsp_url FROM anpr_cameras_lxcars WHERE id = :id",
    [':id' => $cameraId]
);
if (!$camera || empty($camera['rtsp_url'])) {
    http_response_code(404);
    exit('Kamera nicht gefunden');
}

$snapshotDir = __DIR__ . '/../../data/camera-snapshots';
if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);
$snapshotPath = "$snapshotDir/anpr_cam_{$cameraId}.jpg";

$cmd = sprintf(
    'timeout 15 ffmpeg -rtsp_transport tcp -stimeout 8000000'
    . ' -analyzeduration 1000000 -probesize 500000'
    . ' -i %s -frames:v 1 -q:v 3 -y %s 2>/dev/null',
    escapeshellarg($camera['rtsp_url']),
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
