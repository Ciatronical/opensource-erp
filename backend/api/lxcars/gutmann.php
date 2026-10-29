<?php
/**
 * Gutmann-Diagnose: Zugang zur durchgereichten Geräteoberfläche (/gutmann/).
 *
 * Apache lässt /gutmann/ nur mit dem Cookie oserp_gutmann durch, dessen Wert
 * das Geheimnis OSERP_GUTMANN_TOKEN aus install/gutmann-proxy.sh ist
 * (docs/features/gutmann.md). Diese Aktion setzt das Cookie — und weil jede
 * API-Aktion eine gültige Sitzung verlangt (api.call.php), kommt ohne
 * OSERP-Anmeldung niemand an das Gerät. HttpOnly: JavaScript sieht den Wert nie.
 */

const GUTMANN_DEFINES_FILE = '/etc/apache2/conf-available/oserp-gutmann-defines.conf';
const GUTMANN_COOKIE       = 'oserp_gutmann';
const GUTMANN_COOKIE_TTL   = 12 * 3600;

/**
 * Liest die Defines der Apache-Weiterleitung (Gerät, Geheimnis).
 *
 * @return array{device:?string, token:?string}
 */
function gutmannDefines(): array {
    $out = ['device' => null, 'token' => null];
    $text = @file_get_contents(GUTMANN_DEFINES_FILE);
    if ($text === false) return $out;
    if (preg_match('/^Define\s+OSERP_GUTMANN_DEVICE\s+(\S+)/m', $text, $m)) $out['device'] = $m[1];
    if (preg_match('/^Define\s+OSERP_GUTMANN_TOKEN\s+(\S+)/m', $text, $m))  $out['token']  = $m[1];
    return $out;
}

/**
 * Setzt das Zugangs-Cookie für /gutmann/ (12 Stunden, wird bei jedem Öffnen
 * der Seite erneuert). Ohne eingerichtete Weiterleitung kommt configured=false
 * zurück; die Seite zeigt dann den Hinweis mit dem Skriptaufruf.
 *
 * @testdata {}
 */
function getGutmannAccess($data) {
    $defines = gutmannDefines();
    if (empty($defines['token'])) {
        resultInfo(true, '', ['configured' => false, 'device' => $defines['device']]);
        return;
    }
    setcookie(GUTMANN_COOKIE, $defines['token'], [
        'expires'  => time() + GUTMANN_COOKIE_TTL,
        'path'     => '/gutmann',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    resultInfo(true, '', ['configured' => true, 'device' => $defines['device']]);
}
