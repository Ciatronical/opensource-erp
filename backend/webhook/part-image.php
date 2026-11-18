<?php
// backend/webhook/part-image.php
//
// Öffentliche, anonyme Auslieferung der Artikelbilder für die Marktplätze.
// KEIN Session-Check — eBay und die Sync-Werkzeuge für Kleinanzeigen laden
// die Bilder ohne Cookies. Ohne Datenbank: nur Dateien, die Adressen baut
// shop_channel_images() (dev/shop-medienbibliothek.md, Schritt 3).
//
// Drei Formen:
//   ?db=<dbname>&f=<datei>                 Bild der Medienbibliothek, wie es dort liegt
//                                          (backend/data/<dbname>/shop/media/images/<datei>)
//   ?db=<dbname>&m=<media_id>&v=<format>   Fassung im Format eines Kanals
//                                          (…/shop/media/channel/<format>/<media_id>.<format>)
//   ?db=<dbname>&id=<parts_id>&f=<datei>   Kanalbild von früher, bis es übernommen ist
//                                          (backend/data/<dbname>/parts/<parts_id>/<datei>)
// Der Zusatz &s=<Prüfsumme> ändert die Adresse, wenn sich das Bild ändert —
// der Marktplatz holt es dann neu; ausgewertet wird er nicht.
//
// Härtung: db, Kennungen und Format strikt geprüft, vom Dateinamen zählt nur
// der Name, realpath muss im Datenverzeichnis liegen (kein Path-Traversal),
// nur Bild-Endungen. Downloads und Vorschaubilder der
// Bibliothek sind hier nicht erreichbar.

$BASE = realpath(__DIR__.'/../data');
if (false === $BASE) {
    http_response_code(404);
    exit;
}

$db = (string)($_GET['db'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
    http_response_code(400);
    exit;
}

$types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
];

if (isset($_GET['m'])) {
    // Fassung im Format eines Kanals
    $format = (string)($_GET['v'] ?? '');
    if (!ctype_digit((string)$_GET['m']) || 'jpeg' === $format || !isset($types[$format])) {
        http_response_code(400);
        exit;
    }
    $ext  = $format;
    $path = $BASE.'/'.$db.'/shop/media/channel/'.$format.'/'.(int)$_GET['m'].'.'.$format;
} else {
    $file = basename(str_replace('\\', '/', (string)($_GET['f'] ?? '')));
    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ('' === $file || '.' === $file[0] || !isset($types[$ext])) {
        http_response_code(400);
        exit;
    }
    if (isset($_GET['id'])) {
        // Kanalbild von früher im Artikelordner
        if (!ctype_digit((string)$_GET['id'])) {
            http_response_code(400);
            exit;
        }
        $path = $BASE.'/'.$db.'/parts/'.(int)$_GET['id'].'/'.$file;
    } else {
        // Bild der Medienbibliothek
        $path = $BASE.'/'.$db.'/shop/media/images/'.$file;
    }
}

$real = realpath($path);

// Die Datei muss existieren und innerhalb des Datenverzeichnisses liegen
if (false === $real || 0 !== strncmp($real, $BASE.'/', strlen($BASE) + 1) || !is_file($real)) {
    http_response_code(404);
    exit;
}

header('Content-Type: '.$types[$ext]);
header('Content-Length: '.filesize($real));
header('Cache-Control: public, max-age=86400');
readfile($real);
