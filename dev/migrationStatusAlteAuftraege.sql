-- ============================================================
-- Migration: Alte Auftraege vor 2025 als "Abgerechnet" markieren
-- ============================================================
--
-- Alle Auftraege mit transdate vor dem 01.01.2025 erhalten
-- in oe_ext den Status "Abgerechnet", sofern dort noch kein
-- Status gesetzt ist.
--
-- Ausfuehren:
--   psql <dbname> -f dev/migrationStatusAlteAuftraege.sql
--
-- ============================================================

BEGIN;

-- ============================================================
-- 1. Alte Auftraege (vor 01.01.2025) auf "Abgerechnet" setzen
--
--    Vorhandene oe_ext-Zeilen: nur updaten wenn status NULL ist
--    Fehlende oe_ext-Zeilen: neu anlegen mit status "Abgerechnet"
-- ============================================================
INSERT INTO oe_ext (oe_id, status)
SELECT
    oe.id,
    'Abgerechnet'
FROM oe
WHERE oe.transdate < '2025-01-01'
  AND oe.record_type IN ('sales_order', 'purchase_order')
ON CONFLICT (oe_id) DO UPDATE
    SET status = COALESCE(oe_ext.status, EXCLUDED.status);

-- ============================================================
-- 2. Verifikation
-- ============================================================
SELECT
    'Auftraege vor 2025 gesamt' AS pruefung,
    count(*) AS anzahl
FROM oe
WHERE transdate < '2025-01-01'
  AND record_type IN ('sales_order', 'purchase_order')
UNION ALL
SELECT
    'davon jetzt Abgerechnet in oe_ext',
    count(*)
FROM oe
JOIN oe_ext ON oe_ext.oe_id = oe.id
WHERE oe.transdate < '2025-01-01'
  AND oe.record_type IN ('sales_order', 'purchase_order')
  AND oe_ext.status = 'Abgerechnet';

COMMIT;
