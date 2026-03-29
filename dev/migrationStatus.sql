-- ============================================================
-- Migration: oe.status -> oe_ext.status
-- ============================================================
--
-- In der alten LxCars-Datenbank wurde oe.status verwendet.
-- Wenn dort "abgerechnet" steht, soll in oe_ext.status
-- der Wert "Abgerechnet" eingetragen werden.
--
-- Ausfuehren:
--   psql <dbname> -f dev/migrationStatus.sql
--
-- ============================================================

BEGIN;

-- ============================================================
-- 1. Daten uebertragen: oe.status 'abgerechnet' -> oe_ext.status 'Abgerechnet'
--
--    Vorhandene oe_ext-Zeilen werden per UPDATE aktualisiert,
--    fehlende per INSERT angelegt.
--    Bestehende oe_ext.status-Werte werden NICHT ueberschrieben.
-- ============================================================
INSERT INTO oe_ext (oe_id, status)
SELECT
    oe.id,
    'Abgerechnet'
FROM oe
WHERE LOWER(TRIM(oe.status)) = 'abgerechnet'
ON CONFLICT (oe_id) DO UPDATE
    SET status = COALESCE(oe_ext.status, EXCLUDED.status);

-- ============================================================
-- 2. Verifikation
-- ============================================================
SELECT
    'oe mit status abgerechnet' AS pruefung,
    count(*) AS anzahl
FROM oe
WHERE LOWER(TRIM(status)) = 'abgerechnet'
UNION ALL
SELECT
    'oe_ext mit status Abgerechnet',
    count(*)
FROM oe_ext
WHERE status = 'Abgerechnet';

COMMIT;
