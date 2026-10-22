<?php
// backend/api/lxcars/public/actions.php
//
// Die Aktionen, die LxCars dem öffentlichen Zugang des Kundenportals
// hinzufügt (backend/api/portal/public/bootstrap.php lädt diese Datei, wenn
// die Erweiterung aktiv ist): die Fahrzeuge des angemeldeten Kunden mit
// Kennzeichen, Hersteller und Typ, Erstzulassung, HU-Termin und
// Kilometerstand — das Widget <portal-vehicles> zeigt sie an.
//
// Signatur wie im Kern: ($db, $uuid, $daten, $website). Kein @testdata: der
// API-Tester erreicht den öffentlichen Zugang nicht.

require_once __DIR__.'/../lib/portal_hooks.php';

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
 * Die Aktionen, die LxCars dem öffentlichen Zugang hinzufügt
 *
 * @return string[]
 */
function lxcarsPublicActions(): array {
    return ['portalVehicles', 'portalVehicle'];
}
