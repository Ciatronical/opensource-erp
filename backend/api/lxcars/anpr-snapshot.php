<?php
/**
 * ANPR-Erkennungs-Snapshot ausgeben
 *
 * Nutzung: /api/lxcars/anpr-snapshot.php?id=123
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

$auth->fetchSessionData();
$db = DbhCompany::begin();
$row = $db->getOne("SELECT current_database() AS dbname");
$dbname = $row['dbname'];

// Debug-Snapshot (Near-miss): ?debug=filename
if (!empty($_GET['debug'])) {
    $fname = basename($_GET['debug']);  // basename verhindert Path-Traversal
    if (!preg_match('/^[0-9_a-zA-Z]+\.jpg$/', $fname)) {
        http_response_code(400);
        exit;
    }
    $path = __DIR__ . '/../../../backend/data/' . $dbname . '/anpr-debug-snapshots/' . $fname;
    if (!file_exists($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-cache');
    readfile($path);
    exit;
}

// Normaler Detection-Snapshot: ?id=123&n=1
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit;
}

$det = $db->getOne("SELECT id FROM anpr_detections_lxcars WHERE id = :id", [':id' => $id]);
if (!$det) {
    http_response_code(404);
    exit;
}

$n = max(1, intval($_GET['n'] ?? 1));
$base = __DIR__ . '/../../../backend/data/' . $dbname . '/anpr-snapshots/' . $id;

$path = $base . '_' . $n . '.jpg';
if (!file_exists($path)) $path = $base . '.jpg';

if (!file_exists($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: max-age=86400, immutable');
readfile($path);
