<?php
// shop-api/index.php — Proxy der Shop-Webseite zu OpensourceERP
//
// Stammt aus dem Vorlagensatz der Shop-Erweiterung (kit/static/). Der Läufer
// von OpensourceERP (tools/shop-publish.php) überträgt ihn nach
// <webseite>/oserp-shop/static/, Hugo veröffentlicht ihn nach
// <webseite>/public/shop-api/index.php. Änderungen hier gehen beim nächsten
// Lauf verloren — geändert wird im Vorlagensatz.
//
// WOFÜR
// Die Widgets rufen /shop-api/ auf derselben Adresse auf, unter der die Seite
// läuft; das Sitzungs-Cookie (SameSite=Strict) ginge sonst verloren. Der Proxy
// hält den Aufruf same-origin und trägt den Shop-Schlüssel nach, damit ihn der
// Browser nie zu sehen bekommt.
//
// Ein Reverse-Proxy im Webserver ist der bessere Weg, wo er zur Verfügung
// steht — er kostet keinen PHP-Prozess je Anfrage.
//
// EINRICHTUNG
// Keine. Adresse und Schlüssel schreibt der Läufer nach
// <webseite>/oserp-shop/config.php — außerhalb des Docroots, und Hugo hängt
// die Datei nicht ein.

$konfiguration = __DIR__.'/../../oserp-shop/config.php';
$cfg = is_file($konfiguration) ? require $konfiguration : [];
$adresse   = is_array($cfg) ? (string)($cfg['url'] ?? '') : '';
$schluessel = is_array($cfg) ? (string)($cfg['key'] ?? '') : '';

if ('' === $adresse || '' === $schluessel) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'text'    => 'SHOP_PROXY_NOT_CONFIGURED',
        'debug'   => 'oserp-shop/config.php fehlt oder ist unvollständig — in den Shop-Einstellungen die Adresse von OpensourceERP und den Shop-Schlüssel setzen, dann den Läufer laufen lassen',
    ]);
    exit;
}

// Die Abfrage aus der Adresse wird mitgereicht: der Rückweg von PayPal kommt
// als GET mit action, token und page.
$ziel = rtrim($adresse, '/').'/';
if (!empty($_SERVER['QUERY_STRING'])) {
    $ziel .= '?'.$_SERVER['QUERY_STRING'];
}

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rumpf   = 'POST' === $methode ? file_get_contents('php://input') : null;

$kopfzeilen = [
    // Der Schlüssel wird hier nachgetragen, nicht vom Browser geschickt
    'X-Shop-Key: '.$schluessel,
];
if (!empty($_SERVER['CONTENT_TYPE'])) {
    $kopfzeilen[] = 'Content-Type: '.$_SERVER['CONTENT_TYPE'];
}
// Das Sitzungs-Cookie des Besuchers muss mit, sonst kennt das Backend ihn nicht
if (!empty($_SERVER['HTTP_COOKIE'])) {
    $kopfzeilen[] = 'Cookie: '.$_SERVER['HTTP_COOKIE'];
}
// Für das Protokoll auf der ERP-Seite: wer hat wirklich angefragt
$kopfzeilen[] = 'X-Forwarded-For: '.($_SERVER['REMOTE_ADDR'] ?? '');
$kopfzeilen[] = 'X-Forwarded-Host: '.($_SERVER['HTTP_HOST'] ?? '');

$ch = curl_init($ziel);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $methode,
    CURLOPT_POSTFIELDS     => $rumpf,
    CURLOPT_HTTPHEADER     => $kopfzeilen,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 60,
    // Weiterleitungen NICHT folgen: die Bezahlung schickt den Besucher zu
    // PayPal und von dort zurück — das gehört in seinen Browser, nicht in
    // diesen Proxy.
    CURLOPT_FOLLOWLOCATION => false,
]);

$antwort = curl_exec($ch);

if (false === $antwort) {
    $fehler = curl_error($ch);
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'text'    => 'SHOP_BACKEND_UNREACHABLE',
        'debug'   => $fehler,
    ]);
    exit;
}

$status     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$kopfLaenge = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

http_response_code($status);

// Kopfzeilen durchreichen, die der Browser braucht: Inhaltstyp, das
// Sitzungs-Cookie, Weiterleitungen und der Dateiname beim PDF-Abruf. Alles
// andere bleibt draußen — Längen und Kodierungen stimmen nach dem Durchreichen
// nicht mehr zwingend.
$durchreichen = ['content-type', 'content-disposition', 'location', 'set-cookie', 'cache-control'];
foreach (explode("\r\n", substr($antwort, 0, $kopfLaenge)) as $zeile) {
    $doppelpunkt = strpos($zeile, ':');
    if (false === $doppelpunkt) {
        continue;
    }
    $name = strtolower(trim(substr($zeile, 0, $doppelpunkt)));
    if (in_array($name, $durchreichen, true)) {
        header($zeile, 'set-cookie' !== $name);
    }
}

echo substr($antwort, $kopfLaenge);
