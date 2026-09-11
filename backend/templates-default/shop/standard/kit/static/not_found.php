<?php
// not_found.php — 404-Seite der Shop-Webseite mit Umleitungen
//
// Stammt aus dem Vorlagensatz der Shop-Erweiterung (kit/static/) und wird nach
// <webseite>/public/not_found.php veröffentlicht. Der Webserver ruft sie für
// unbekannte Adressen auf (nginx: error_page 404 /not_found.php).
//
// Die Umleitungen stehen in OpensourceERP (redirect_pages_hugoshop). Abgefragt
// werden sie über die öffentliche Aktion resolveRedirect — mit Adresse und
// Schlüssel aus <webseite>/oserp-shop/config.php, wie beim Proxy.
//
// Anders als die Bridge leitet die Seite bei 301 und 302 wirklich um: sie
// sendet einen Location-Kopf, statt das Ziel selbst abzurufen und seinen
// Inhalt unter der alten Adresse auszugeben.
//
// Aussehen: liegt im Docroot eine 404.html, wird sie mit den Platzhaltern
// [!code], [!display], [!link_text] und [!hyperlink] gefüllt.

/**
 * Gibt die Fehlerseite aus
 *
 * @param int $code 404 oder 410
 * @param string $ziel Hinweis auf eine neue Seite, leer wenn es keine gibt
 * @param string $linktext Text zum Hinweis
 */
function seiteNichtGefunden(int $code, string $ziel = '', string $linktext = ''): void {
    http_response_code($code);

    $vorlage = __DIR__.'/404.html';
    if (is_file($vorlage)) {
        echo str_replace(
            ['[!code]', '[!display]', '[!link_text]', '[!hyperlink]'],
            [
                (string)$code,
                '' === $ziel ? 'style="display:none"' : '',
                htmlspecialchars($linktext),
                htmlspecialchars($ziel),
            ],
            (string)file_get_contents($vorlage)
        );
        return;
    }

    echo '<html><head><title>'.$code.' Not Found</title></head><body>'
       . '<center><h1>'.$code.' Not Found</h1></center></body></html>';
}

/**
 * Fragt OpensourceERP nach einer Umleitung für die angefragte Adresse
 *
 * @param string $adresse Host und Pfad, ohne Schema
 * @return array|null code, current_link, link_text — oder null
 */
function umleitungSuchen(string $adresse): ?array {
    $konfiguration = __DIR__.'/../oserp-shop/config.php';
    $cfg = is_file($konfiguration) ? require $konfiguration : [];
    $url = is_array($cfg) ? (string)($cfg['url'] ?? '') : '';
    $schluessel = is_array($cfg) ? (string)($cfg['key'] ?? '') : '';
    if ('' === $url || '' === $schluessel) {
        return null;
    }

    $ch = curl_init(rtrim($url, '/').'/');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => 'resolveRedirect', 'url' => $adresse]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Shop-Key: '.$schluessel],
        CURLOPT_RETURNTRANSFER => true,
        // Eine Fehlerseite darf nicht lange warten — lieber ohne Umleitung
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $antwort = curl_exec($ch);
    if (false === $antwort) {
        return null;
    }

    $daten = json_decode((string)$antwort, true);
    return is_array($daten) && !empty($daten['success']) && is_array($daten['payload'] ?? null)
        ? $daten['payload']
        : null;
}

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$pfad = (string)($_SERVER['REQUEST_URI'] ?? '');
if ('' === $host || '' === $pfad) {
    seiteNichtGefunden(404);
    return;
}

$umleitung = umleitungSuchen($host.$pfad);
$code = (int)($umleitung['code'] ?? 0);
$ziel = (string)($umleitung['current_link'] ?? '');

if (in_array($code, [301, 302, 307, 308], true) && '' !== $ziel) {
    header('Location: '.$ziel, true, $code);
    return;
}

if (in_array($code, [404, 410], true)) {
    seiteNichtGefunden($code, $ziel, (string)($umleitung['link_text'] ?? ''));
    return;
}

seiteNichtGefunden(404);
