<?php
// backend/api/lxcars/lib/portal_hooks.php
//
// Hier hängt sich LxCars an das Kundenportal (backend/api/portal/lib/
// hooks.php): die Webseite bekommt das Merkmal lxcars — der Partial lädt dann
// das Bündel mit <portal-vehicles> — und das Paket die Shortcodes der
// Fahrzeug-Widgets. Der Kern lädt diese Datei für die aktive Erweiterung.

require_once __DIR__.'/../../portal/lib/portal.php';

// Fahrzeuge zeigt jede Webseite des Mandanten, auf dem LxCars läuft
portalHookAdd('features', function (array $features, $db, int $website): array {
    $features['lxcars'] = true;
    return $features;
});

// Paket: Shortcodes und Partials der Fahrzeug-Widgets
portalHookAdd('publish_files', function (array $dateien, $db, int $website, string $satz): array {
    $basis = dirname(__DIR__, 3).'/templates-default/lxcars';
    portalKitLayer($basis.'/standard/kit', $dateien);
    if ('standard' !== $satz && preg_match('/^[A-Za-z0-9_-]+$/', $satz)) {
        portalKitLayer($basis.'/'.$satz.'/kit', $dateien);
    }
    return $dateien;
});
