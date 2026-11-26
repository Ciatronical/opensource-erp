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

// ── Bilder am Auftrag (docs/features/lxcars.md → Bilder am Auftrag) ──
//
// Der Kunde sieht im Kundenkonto unter „Aufträge“ die freigegebenen Bilder
// seiner Aufträge (oe_images_lxcars.portal_visible). Die Kundennummer steht
// in jeder Bedingung: ohne sie ließe sich durch Hochzählen der Kennung jedes
// fremde Bild lesen.

/**
 * Freigegebene Bilder eines Auftrags des Kunden
 *
 * Ohne Dateiname und Mitarbeiter: der Kunde bekommt Kennung, Unterschrift
 * und Datum, die Datei liefert portalOrderImage (public/actions.php).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int $oeId Auftrag
 * @return array
 */
function lxcarsPortalOrderImages($db, int $customerId, int $oeId): array {
    if (!existingTables($db, ['oe_images_lxcars'])) {
        return [];
    }
    return $db->getAll(
        "SELECT i.id, COALESCE(i.caption, '') AS caption, CAST(i.created_at AS date) AS date
           FROM oe_images_lxcars i
           JOIN oe o ON o.id = i.oe_id
          WHERE i.oe_id = :oe_id AND o.customer_id = :customer_id AND o.vendor_id IS NULL
            AND i.portal_visible
          ORDER BY i.created_at, i.id",
        [':oe_id' => $oeId, ':customer_id' => $customerId]
    ) ?: [];
}

// Liste der Aufträge: Anzahl der freigegebenen Bilder je Auftrag
portalHookAdd('document_rows', function ($zeilen, $db, int $customerId, string $typ, int $website) {
    if ('orders' !== $typ || !is_array($zeilen) || !$zeilen || !existingTables($db, ['oe_images_lxcars'])) {
        return $zeilen;
    }
    $anzahl = array_column($db->getAll(
        "SELECT i.oe_id, COUNT(*) AS images
           FROM oe_images_lxcars i
           JOIN oe o ON o.id = i.oe_id
          WHERE o.customer_id = :customer_id AND o.vendor_id IS NULL AND i.portal_visible
          GROUP BY i.oe_id",
        [':customer_id' => $customerId]
    ) ?: [], 'images', 'oe_id');
    foreach ($zeilen as &$z) {
        $z['images'] = (int)($anzahl[$z['id']] ?? 0);
    }
    unset($z);
    return $zeilen;
});

// Ansicht eines Auftrags: die freigegebenen Bilder
portalHookAdd('document_view', function (array $ansicht, $db, int $customerId, string $typ, int $id, int $website): array {
    if ('orders' === $typ) {
        $ansicht['images'] = lxcarsPortalOrderImages($db, $customerId, $id);
    }
    return $ansicht;
});
