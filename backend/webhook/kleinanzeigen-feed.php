<?php
// backend/webhook/kleinanzeigen-feed.php
//
// Anzeigen-Feed des Verkaufskanals Kleinanzeigen (dev/shop-kleinanzeigen.md).
// KEIN Session-Check — Sync-Werkzeuge holen den Feed ohne Anmeldung, geschützt
// durch den Feed-Schlüssel des Kanals (sales_channel_secret_shop, feed_key).
//
// Kleinanzeigen.de selbst hat keine Schnittstelle. Drittwerkzeuge, die
// Anzeigen aus einem Feed pflegen (Kleinanzeigen-Enhanced und ähnliche),
// nehmen CSV oder JSON von einer Adresse. Geliefert werden alle Entwürfe und
// Online-Anzeigen des Kanals, so wie kleinanzeigen_ad() sie zusammenstellt —
// derselbe Stand wie im Board.
//
// URL: https://<host>/webhook/kleinanzeigen-feed.php?db=<dbname>&channel=<id>&key=<feed_key>&format=csv|json
// Bilder: absolute Adressen über part-image.php auf diesem Host.

require_once __DIR__.'/../api/config.php';

if (OSERP_SETUP_MODE) {
    http_response_code(503);
    exit;
}
OserpConfig::init();

$dbname  = (string)($_GET['db'] ?? '');
$kanal   = (int)($_GET['channel'] ?? 0);
$key     = (string)($_GET['key'] ?? '');
$format  = 'json' === ($_GET['format'] ?? '') ? 'json' : 'csv';

if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbname) || $kanal <= 0 || '' === $key) {
    http_response_code(400);
    exit;
}

try {
    $auth = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME), DB_AUTH_USER, DB_AUTH_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $auth->prepare("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE dbname = :name LIMIT 1");
    $stmt->execute([':name' => $dbname]);
    $mandant = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mandant) {
        http_response_code(404);
        exit;
    }
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $mandant['dbhost'], $mandant['dbport'], $mandant['dbname']),
                   $mandant['dbuser'], $mandant['dbpasswd'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Schlüssel prüfen — gegen den Kanal, zeitkonstant
    $stmt = $pdo->prepare(
        "SELECT s.value
           FROM sales_channel_secret_shop s
           JOIN sales_channel_shop c ON c.id = s.channel_id AND c.type = 'kleinanzeigen'
          WHERE s.channel_id = :kanal AND s.key = 'feed_key'"
    );
    $stmt->execute([':kanal' => $kanal]);
    $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$zeile || '' === (string)$zeile['value'] || !hash_equals((string)$zeile['value'], $key)) {
        http_response_code(403);
        exit;
    }

    // Alle Entwürfe und Online-Anzeigen, eine Abfrage
    $stmt = $pdo->prepare(
        "SELECT kleinanzeigen_ad(pc.parts_id, pc.channel_id) AS ad
           FROM parts_channel_shop pc
          WHERE pc.channel_id = :kanal
            AND pc.active
            AND COALESCE(pc.sync_status, 'draft') IN ('draft', 'active', 'reserved')
          ORDER BY pc.parts_id"
    );
    $stmt->execute([':kanal' => $kanal]);
    $anzeigen = array_map(fn($z) => json_decode((string)$z['ad'], true) ?: [], $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (\Throwable $e) {
    error_log('[KLEINANZEIGEN FEED] '.$e->getMessage());
    http_response_code(500);
    exit;
}

$schema = (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS']) || 'https' === ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') ? 'https' : 'http';
$basis = $schema.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');

$zeilen = [];
foreach ($anzeigen as $a) {
    if (!$a) {
        continue;
    }
    $bilder = array_map(fn($b) => $basis.$b['url'], $a['images'] ?? []);
    $zeilen[] = [
        'sku'         => (string)$a['partnumber'],
        'title'       => (string)$a['title'],
        'description' => (string)$a['text'],
        'price'       => (string)$a['price'],
        'price_type'  => (string)$a['price_type'],
        'shipping'    => (string)$a['shipping'],
        'condition'   => (string)$a['condition'],
        'category'    => (string)$a['category'],
        'location'    => (string)$a['location'],
        'quantity'    => (string)($a['onhand'] ?? ''),
        'status'      => (string)$a['sync_status'],
        'ad_id'       => (string)($a['external_id'] ?? ''),
        'images'      => $bilder,
    ];
}

header('Cache-Control: no-store');
if ('json' === $format) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['generated' => date('c'), 'count' => count($zeilen), 'ads' => $zeilen], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: inline; filename="kleinanzeigen-feed.csv"');
$aus = fopen('php://output', 'w');
fwrite($aus, "\xEF\xBB\xBF"); // BOM: Excel und viele Werkzeuge erkennen UTF-8 daran
fputcsv($aus, ['sku', 'title', 'description', 'price', 'price_type', 'shipping', 'condition', 'category', 'location', 'quantity', 'status', 'ad_id', 'images'], ';');
foreach ($zeilen as $z) {
    $z['images'] = implode('|', $z['images']);
    fputcsv($aus, array_values($z), ';');
}
fclose($aus);
