<?php
// backend/webhook/part-image.php
//
// Oeffentliche, anonyme Auslieferung von eBay-Artikelbildern.
// KEIN Session-Check — eBay laedt die Bilder ohne Cookies.
//
// URL: https://<host>/webhook/part-image.php?db=<dbname>&id=<parts_id>&f=<datei>
// Datei liegt unter backend/data/<dbname>/parts/<parts_id>/<datei>.
//
// Haertung: db/Dateiname strikt validiert, realpath muss im Datenverzeichnis
// liegen (kein Path-Traversal), nur Bild-Endungen.

$BASE = realpath(__DIR__ . '/../data');
if ($BASE === false) {
    http_response_code(404);
    exit;
}

$db  = $_GET['db'] ?? '';
$id  = $_GET['id'] ?? '';
$f   = $_GET['f'] ?? '';

if (!preg_match('/^[a-zA-Z0-9_]+$/', $db) || !ctype_digit((string)$id)) {
    http_response_code(400);
    exit;
}

$file = basename($f);
$ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];
if (!isset($types[$ext])) {
    http_response_code(400);
    exit;
}

$path = $BASE . '/' . $db . '/parts/' . intval($id) . '/' . $file;
$real = realpath($path);

// Pfad muss existieren und innerhalb des Datenverzeichnisses liegen
if ($real === false || strncmp($real, $BASE . '/', strlen($BASE) + 1) !== 0 || !is_file($real)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($real));
header('Cache-Control: public, max-age=86400');
readfile($real);
