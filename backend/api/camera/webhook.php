<?php
/**
 * Frigate Webhook-Endpunkt (ohne Session)
 *
 * Dieser Endpunkt wird direkt von Frigate aufgerufen.
 * Authentifizierung erfolgt über einen API-Key im Header oder Query-Parameter.
 *
 * Frigate-Konfiguration:
 *   notify:
 *     webhook:
 *       url: https://erp.example.com/api/camera/webhook.php?token=DEIN_TOKEN&client=FIRMENCODE
 *
 * Der Token wird in defaults_oserp (key: camera_webhook_token) gespeichert.
 * Der Client-Parameter bestimmt die Company-DB.
 */

header('Content-Type: application/json');

// Token und Client aus Query oder Header
$token = $_GET['token'] ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';
$clientCode = $_GET['client'] ?? $_SERVER['HTTP_X_CLIENT'] ?? '';

if (empty($token) || empty($clientCode)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'text' => 'Token und Client erforderlich']);
    exit;
}

// Core laden (ohne Session-Validierung)
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../database.php';

// Auth-DB: Client-Daten laden
try {
    $authPdo = new PDO(
        'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_AUTH,
        DB_AUTH_USER,
        DB_AUTH_PASS
    );
    $authPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $authPdo->prepare("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE name = :name");
    $stmt->execute([':name' => $clientCode]);
    $clientDb = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$clientDb) {
        http_response_code(404);
        echo json_encode(['success' => false, 'text' => 'Client nicht gefunden']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'text' => 'Auth-DB Fehler']);
    exit;
}

// Company-DB verbinden
try {
    $compPdo = new PDO(
        "pgsql:host={$clientDb['dbhost']};port={$clientDb['dbport']};dbname={$clientDb['dbname']}",
        $clientDb['dbuser'],
        $clientDb['dbpasswd']
    );
    $compPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'text' => 'Company-DB Fehler']);
    exit;
}

// Token prüfen
$stmt = $compPdo->prepare("SELECT value FROM defaults_oserp WHERE key = 'camera_webhook_token'");
$stmt->execute();
$storedToken = $stmt->fetchColumn();

if (empty($storedToken) || !hash_equals($storedToken, $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'text' => 'Ungültiger Token']);
    exit;
}

// Frigate-URL laden
$stmt = $compPdo->prepare("SELECT value FROM defaults_oserp WHERE key = 'camera_frigate_url'");
$stmt->execute();
$frigateUrl = $stmt->fetchColumn() ?: '';

// Event-Daten lesen
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'text' => 'Kein JSON-Body']);
    exit;
}

$type = $data['type'] ?? '';
$eventData = $data['after'] ?? $data['before'] ?? [];

if (empty($eventData) || empty($eventData['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'text' => 'Kein gültiges Frigate-Event']);
    exit;
}

$frigateEventId = $eventData['id'];
$cameraName = $eventData['camera'] ?? '';
$label = $eventData['label'] ?? 'unknown';
$zones = $eventData['zones'] ?? [];
$score = (float)($eventData['score'] ?? 0);
$startTime = isset($eventData['start_time'])
    ? date('Y-m-d H:i:s', (int)$eventData['start_time'])
    : date('Y-m-d H:i:s');
$endTime = isset($eventData['end_time']) && $eventData['end_time']
    ? date('Y-m-d H:i:s', (int)$eventData['end_time'])
    : null;

$snapshotUrl = '';
$clipUrl = '';
if ($frigateUrl) {
    if (!empty($eventData['has_snapshot'])) {
        $snapshotUrl = rtrim($frigateUrl, '/') . '/api/events/' . $frigateEventId . '/snapshot.jpg';
    }
    if (!empty($eventData['has_clip'])) {
        $clipUrl = rtrim($frigateUrl, '/') . '/api/events/' . $frigateEventId . '/clip.mp4';
    }
}

// Kamera-ID ermitteln
$stmt = $compPdo->prepare("SELECT id FROM camera WHERE frigate_name = :fname AND active");
$stmt->execute([':fname' => $cameraName]);
$cameraId = $stmt->fetchColumn() ?: null;

$pgZones = '{' . implode(',', array_map(function($z) { return '"' . str_replace('"', '\\"', $z) . '"'; }, $zones)) . '}';

if ($type === 'new') {
    $stmt = $compPdo->prepare("
        INSERT INTO camera_event (frigate_event_id, camera_id, camera_name, label, zones, score, snapshot_url, clip_url, started_at)
        VALUES (:fid, :cam_id, :cam_name, :label, :zones, :score, :snap, :clip, :started)
        ON CONFLICT (frigate_event_id) DO NOTHING
    ");
    $stmt->execute([
        ':fid' => $frigateEventId, ':cam_id' => $cameraId, ':cam_name' => $cameraName,
        ':label' => $label, ':zones' => $pgZones, ':score' => $score,
        ':snap' => $snapshotUrl, ':clip' => $clipUrl, ':started' => $startTime,
    ]);

    // pg_notify
    $payload = json_encode([
        'type' => 'camera_event', 'event' => 'new',
        'camera' => $cameraName, 'label' => $label,
        'zones' => $zones, 'score' => $score,
        'frigate_event_id' => $frigateEventId,
    ]);
    $compPdo->prepare("SELECT pg_notify('camera_event', :payload)")->execute([':payload' => $payload]);

} elseif ($type === 'end') {
    $stmt = $compPdo->prepare("
        UPDATE camera_event SET
            ended_at = :ended,
            score = GREATEST(score, :score),
            clip_url = CASE WHEN :clip != '' THEN :clip ELSE clip_url END
        WHERE frigate_event_id = :fid
    ");
    $stmt->execute([':fid' => $frigateEventId, ':ended' => $endTime, ':score' => $score, ':clip' => $clipUrl]);

} elseif ($type === 'update') {
    $stmt = $compPdo->prepare("
        UPDATE camera_event SET
            score = GREATEST(score, :score),
            zones = :zones,
            snapshot_url = CASE WHEN :snap != '' THEN :snap ELSE snapshot_url END
        WHERE frigate_event_id = :fid
    ");
    $stmt->execute([':fid' => $frigateEventId, ':score' => $score, ':zones' => $pgZones, ':snap' => $snapshotUrl]);
}

echo json_encode(['success' => true]);
