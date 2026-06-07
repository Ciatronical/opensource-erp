<?php
/**
 * Kamera-Medien (Snapshot / Clip) authentifiziert ausliefern.
 *
 * Nutzung:
 *   /api/camera/media.php?type=snapshot&event=<event_id>
 *   /api/camera/media.php?type=clip&event=<event_id>
 *
 * Sicherheit:
 *  - Erfordert eine gueltige Session (sonst 401).
 *  - Autorisierung ueber die Company-DB der Session: nur Events, die in der
 *    DB des angemeldeten Mandanten existieren, werden ausgeliefert. Da die
 *    event_id eine global eindeutige UUID ist, kann kein Mandant fremde
 *    Medien abrufen (event_id nicht in eigener DB -> 404).
 *  - Dateien liegen ausserhalb des DocumentRoots (backend/data/...) und sind
 *    damit nicht mehr direkt per HTTP erreichbar.
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

$type = $_GET['type'] ?? 'snapshot';
if (!in_array($type, ['snapshot', 'clip'], true)) {
    http_response_code(400);
    exit;
}

// event_id strikt validieren (UUID-Prefix, vom camera_monitor erzeugt)
$eventId = basename($_GET['event'] ?? '');   // basename -> kein Path-Traversal
if (!preg_match('/^[a-f0-9][a-f0-9-]{1,40}$/', $eventId)) {
    http_response_code(400);
    exit;
}

// Session pruefen + Autorisierung/Existenz in der Company-DB der Session
// (eine Query). Bei ungueltiger Session wirft fetchSessionData/connectPDO
// einen ApiError -> als 403 behandeln. inc.php wird hier bewusst NICHT
// eingebunden (das wuerde den JSON-Dispatcher mitstarten), daher faengt
// \Throwable auch einen evtl. nicht geladenen ApiError sauber ab.
try {
    $auth->fetchSessionData();
    $db = DbhCompany::begin();
    $event = $db->getOne(
        "SELECT CASE WHEN :type = 'clip' THEN clip_url ELSE snapshot_url END AS media
         FROM camera_event WHERE event_id = :event",
        [':type' => $type, ':event' => $eventId]
    );
} catch (\Throwable $e) {
    http_response_code(403);
    exit;
}

if (!$event) {
    http_response_code(404);
    exit;
}
if ($type === 'clip' && empty($event['media'])) {
    // Event existiert, aber (noch) kein Clip aufgezeichnet
    http_response_code(404);
    exit;
}

$dir = $type === 'clip' ? 'camera-clips' : 'camera-snapshots';
$ext = $type === 'clip' ? 'mp4' : 'jpg';
$path = __DIR__ . '/../../data/' . $dir . '/' . $eventId . '.' . $ext;

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = $type === 'clip' ? 'video/mp4' : 'image/jpeg';
_streamFile($path, $mime);

/**
 * Datei ausliefern – mit HTTP-Range-Unterstuetzung (fuer Video-Seeking).
 */
function _streamFile(string $path, string $mime): void {
    $size = filesize($path);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    // Privat zwischenspeicherbar (nur im Browser des angemeldeten Nutzers)
    header('Cache-Control: private, max-age=86400');

    $start = 0;
    $end = $size - 1;

    if (isset($_SERVER['HTTP_RANGE']) &&
        preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') $start = (int)$m[1];
        if ($m[2] !== '') $end = (int)$m[2];
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $end = min($end, $size - 1);
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . $length);

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit;
    }
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit;
}
