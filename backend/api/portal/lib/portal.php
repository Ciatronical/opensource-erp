<?php
// backend/api/portal/lib/portal.php
//
// Lädt die Fachschicht des Kundenportals. Eingebunden vom öffentlichen Zugang
// (public/bootstrap.php), vom Admin-Zugang (../index.php) und von den
// Erweiterungen, die darauf aufsetzen (backend/api/shop/lib/shop.php).
//
// ApiError muss vor allem anderen bekannt sein: Klassen, die davon erben,
// brauchen die Basisklasse beim Laden der Datei.

require_once __DIR__.'/../../error.php';
require_once __DIR__.'/../../lib/extensions.php';

require_once __DIR__.'/hooks.php';
require_once __DIR__.'/website.php';
require_once __DIR__.'/context.php';
require_once __DIR__.'/account.php';
require_once __DIR__.'/invoices.php';
require_once __DIR__.'/documents.php';
require_once __DIR__.'/mail.php';
require_once __DIR__.'/activation.php';
require_once __DIR__.'/signing.php';
require_once __DIR__.'/hugocms.php';
require_once __DIR__.'/kit.php';
require_once __DIR__.'/sitecheck.php';

/**
 * Lädt die Haken der aktiven Erweiterungen
 *
 * Eine Erweiterung, die am Kundenportal teilnimmt, liefert
 * backend/api/<name>/lib/portal_hooks.php; die Datei bringt ihre eigenen
 * Abhängigkeiten mit und meldet ihre Haken an (portalHookAdd). Geladen wird
 * je Request einmal, sobald die Firmen-Datenbank bekannt ist — der
 * öffentliche Zugang und die Admin-API rufen das vor der ersten Fachfunktion.
 *
 * @param object $db Company-Datenbankverbindung
 * @return string[] geladene Erweiterungen
 */
function portalExtensionsLoad($db): array {
    static $geladen = null;
    if (null !== $geladen) {
        return $geladen;
    }
    $geladen = [];
    foreach (getActiveExtensions($db) as $erweiterung) {
        if (!preg_match('/^[a-z0-9_]+$/', $erweiterung)) {
            continue;
        }
        $datei = __DIR__.'/../../'.$erweiterung.'/lib/portal_hooks.php';
        if (file_exists($datei)) {
            require_once $datei;
            $geladen[] = $erweiterung;
        }
    }
    return $geladen;
}
