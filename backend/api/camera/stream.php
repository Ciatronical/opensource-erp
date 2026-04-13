<?php
/**
 * Kamera-Snapshot-Proxy
 *
 * Holt ein einzelnes Bild von einer Kamera und liefert es als JPEG aus.
 * Wird verwendet wenn kein WebRTC/MJPEG-Stream direkt verfuegbar ist.
 *
 * Nutzung: /api/camera/stream.php?id=1
 *
 * Fuer Live-Streams empfehlen wir go2rtc (nativ, ohne Docker):
 *   go2rtc -config go2rtc.yaml
 *   # Stellt WebRTC + MJPEG bereit
 */

require_once __DIR__.'/../config.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../database.php';
require_once __DIR__.'/../session.php';

// Session pruefen
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

$camera = $db->getOne("SELECT stream_url FROM camera WHERE id = :id AND active", [':id' => $cameraId]);
if (!$camera || empty($camera['stream_url'])) {
    http_response_code(404);
    exit('Kamera nicht gefunden');
}

$streamUrl = $camera['stream_url'];

// Snapshot-Verzeichnis
$snapshotDir = __DIR__ . '/../../../public/camera-snapshots';
if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);
$snapshotPath = "$snapshotDir/live_cam_{$cameraId}.jpg";

// ffmpeg: ein einzelnes Frame aus dem RTSP-Stream holen
$cmd = sprintf(
    'ffmpeg -rtsp_transport tcp -i %s -frames:v 1 -q:v 2 -y %s 2>/dev/null',
    escapeshellarg($streamUrl),
    escapeshellarg($snapshotPath)
);

// Timeout: max 5 Sekunden
$cmd = "timeout 5 $cmd";
exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !file_exists($snapshotPath)) {
    http_response_code(502);
    exit('Stream nicht erreichbar');
}

// JPEG ausliefern
header('Content-Type: image/jpeg');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
readfile($snapshotPath);
