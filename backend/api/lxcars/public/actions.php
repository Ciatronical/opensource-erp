<?php
// backend/api/lxcars/public/actions.php
//
// Die Aktionen, die LxCars dem öffentlichen Zugang des Kundenportals
// hinzufügt (backend/api/portal/public/bootstrap.php lädt diese Datei, wenn
// die Erweiterung aktiv ist): die Fahrzeuge des angemeldeten Kunden mit
// Kennzeichen, Hersteller und Typ, Erstzulassung, HU-Termin und
// Kilometerstand — das Widget <portal-vehicles> zeigt sie an. Dazu die
// freigegebenen Bilder am Auftrag: Liste und Ansicht kommen über die Haken
// document_rows und document_view (lib/portal_hooks.php) mit den Belegen des
// Kerns, hier steht nur die Auslieferung der Datei (portalOrderImage).
//
// Signatur wie im Kern: ($db, $uuid, $daten, $website). Kein @testdata: der
// API-Tester erreicht den öffentlichen Zugang nicht.

require_once __DIR__.'/../lib/portal_hooks.php';
// Ablageort und Vorschaubild der Bilder am Auftrag. Beide Dateien bringen
// Aktionen der Mitarbeiter mit — erreichbar sind sie von hier aus nicht:
// darüber entscheidet allein die Aktionsliste (lxcarsPublicActions).
require_once __DIR__.'/../../customer_vendor/filemanager.php';
require_once __DIR__.'/../order_images.php';

/**
 * Fahrzeuge des Kunden, geprüft über den Eigentümer (cars_lxcars.c_ow)
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int $carId ein Fahrzeug, 0 = alle
 * @return array
 */
function lxcarsPortalVehicles($db, int $customerId, int $carId = 0): array {
    return $db->getAll(
        "SELECT c.c_id AS id, c.c_ln AS plate, c.c_2 AS hsn, c.c_3 AS tsn, c.c_fin AS vin,
                c.c_d AS first_registration, c.c_hu AS hu_date, c.c_km AS mileage,
                COALESCE(c.c_mt, '') AS model_text, COALESCE(c.c_color, '') AS color,
                COALESCE(k.hersteller, k.marke, '') AS make, COALESCE(k.name, '') AS model,
                (c.c_hu IS NOT NULL AND c.c_hu < CURRENT_DATE) AS hu_overdue,
                (c.c_hu IS NOT NULL AND c.c_hu >= CURRENT_DATE AND c.c_hu < CURRENT_DATE + interval '60 days') AS hu_soon
           FROM cars_lxcars c
           LEFT JOIN kba_lxcars k ON k.id = c.kba_id
          WHERE c.c_ow = :customer_id
            AND (CAST(:car_id AS integer) = 0 OR c.c_id = CAST(:car_id AS integer))
          ORDER BY c.c_hu NULLS LAST, c.c_ln",
        [':customer_id' => $customerId, ':car_id' => $carId]
    ) ?: [];
}

/** Fahrzeuge des angemeldeten Kunden */
function portalVehicles($db, string $uuid, array $daten) {
    resultInfo(true, '', ['vehicles' => lxcarsPortalVehicles($db, portalCustomerId($db, $uuid))]);
}

/** Ein Fahrzeug des angemeldeten Kunden */
function portalVehicle($db, string $uuid, array $daten) {
    $fahrzeuge = lxcarsPortalVehicles($db, portalCustomerId($db, $uuid), portalVarInt($daten, 'id'));
    if (!$fahrzeuge) {
        throw new ApiError('VEHICLE_NOT_FOUND', 'Dieses Fahrzeug gibt es nicht');
    }
    resultInfo(true, '', ['vehicle' => $fahrzeuge[0]]);
}

/**
 * Liefert ein freigegebenes Bild eines eigenen Auftrags (binär, per GET als
 * Bildquelle nutzbar): id = Bild, thumb = 1 für das Vorschaubild
 *
 * Nur wenn die Webseite Aufträge im Kundenkonto zeigt, nur Bilder mit
 * Freigabe, nur an Aufträgen des angemeldeten Kunden. Alles andere ist 404 —
 * die Antwort verrät nicht, ob es das Bild gibt.
 */
function portalOrderImage($db, string $uuid, array $daten, int $website) {
    documentTypeRequire($db, $website, 'orders');
    $customerId = portalCustomerId($db, $uuid);

    $bild = existingTables($db, ['oe_images_lxcars']) ? $db->getOne(
        "SELECT i.filename, " . _oiRelDirSql() . " AS rel_dir
           FROM oe_images_lxcars i
           JOIN oe o ON o.id = i.oe_id
          WHERE i.id = :id AND o.customer_id = :customer_id AND o.vendor_id IS NULL
            AND i.portal_visible",
        [':id' => portalVarInt($daten, 'id'), ':customer_id' => $customerId]
    ) : null;
    $abs = $bild ? fmDataDir() . '/' . $bild['rel_dir'] . '/' . basename($bild['filename']) : '';
    if (!$bild || !is_file($abs)) {
        http_response_code(404);
        throw new ApiError('IMAGE_NOT_FOUND', 'Dieses Bild gibt es nicht');
    }

    $pfad = portalVarInt($daten, 'thumb') ? (_oiThumb($abs) ?? $abs) : $abs;
    header('Content-Type: ' . fmGetMimeType(basename($pfad)));
    header('Content-Length: ' . (string)filesize($pfad));
    header('X-Content-Type-Options: nosniff');
    // Nur im Browser des Kunden halten, und nur kurz: die Werkstatt kann die
    // Freigabe zurücknehmen
    header('Cache-Control: private, max-age=3600');
    readfile($pfad);
}

/**
 * Die Aktionen, die LxCars dem öffentlichen Zugang hinzufügt
 *
 * @return string[]
 */
function lxcarsPublicActions(): array {
    return ['portalVehicles', 'portalVehicle', 'portalOrderImage'];
}
