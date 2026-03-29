-- ============================================================
-- Migration: oe.shipvia -> oe_ext.km_stand
--            oe.shippingpoint -> oe_ext.kennzeichen
-- ============================================================
--
-- In LxCars/Autoprofis wurden die Felder oe.shipvia und
-- oe.shippingpoint zweckentfremdet fuer KM-Stand und Kennzeichen.
-- Dieses Skript uebertraegt die Werte in die dafuer vorgesehenen
-- Spalten in oe_ext.
--
-- Ausfuehren:
--   psql <dbname> -f dev/migrationShipviaKmStand.sql
--
-- ============================================================

BEGIN;

-- ============================================================
-- 1. Spalte kennzeichen in oe_ext anlegen (falls noch nicht vorhanden)
-- ============================================================
ALTER TABLE oe_ext ADD COLUMN IF NOT EXISTS kennzeichen text;

-- ============================================================
-- 2. Daten uebertragen: oe.shipvia -> km_stand, oe.shippingpoint -> kennzeichen
--
--    shipvia ist TEXT, km_stand ist INTEGER:
--      - Nur numerische Zeichen extrahieren (z.B. "123.456" -> "123456")
--      - Leere/nicht-numerische Werte werden ignoriert (NULL)
--
--    shippingpoint -> kennzeichen: direkter Text-Transfer
--
--    Vorhandene oe_ext-Zeilen werden per UPDATE aktualisiert,
--    fehlende per INSERT angelegt.
-- ============================================================
INSERT INTO oe_ext (oe_id, km_stand, kennzeichen)
SELECT
    oe.id,
    CASE WHEN regexp_replace(oe.shipvia, '[^0-9]', '', 'g') <> ''
         THEN regexp_replace(oe.shipvia, '[^0-9]', '', 'g')::integer
         ELSE NULL END,
    NULLIF(TRIM(oe.shippingpoint), '')
FROM oe
WHERE (oe.shipvia IS NOT NULL AND TRIM(oe.shipvia) <> '')
   OR (oe.shippingpoint IS NOT NULL AND TRIM(oe.shippingpoint) <> '')
ON CONFLICT (oe_id) DO UPDATE
    SET km_stand = COALESCE(
            EXCLUDED.km_stand,
            oe_ext.km_stand
        ),
        kennzeichen = COALESCE(
            EXCLUDED.kennzeichen,
            oe_ext.kennzeichen
        );

-- ============================================================
-- 3. Verifikation
-- ============================================================
SELECT
    'oe mit shipvia' AS pruefung,
    count(*) AS anzahl
FROM oe
WHERE shipvia IS NOT NULL AND TRIM(shipvia) <> ''
UNION ALL
SELECT
    'oe mit shippingpoint',
    count(*)
FROM oe
WHERE shippingpoint IS NOT NULL AND TRIM(shippingpoint) <> ''
UNION ALL
SELECT
    'oe_ext mit km_stand',
    count(*)
FROM oe_ext
WHERE km_stand IS NOT NULL
UNION ALL
SELECT
    'oe_ext mit kennzeichen',
    count(*)
FROM oe_ext
WHERE kennzeichen IS NOT NULL AND TRIM(kennzeichen) <> '';

COMMIT;
