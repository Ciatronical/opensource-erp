-- ============================================================
-- Migration: instructions -> oe_instructions_lxcars
-- ============================================================
--
-- In der alten LxCars-Datenbank wurden Arbeitsanweisungen als
-- normale Positionen in der Tabelle "instructions" gespeichert
-- (verknuepft ueber trans_id mit oe, ueber parts_id mit parts).
--
-- Dieses Skript uebertraegt sie in die neue Tabelle
-- oe_instructions_lxcars.
--
-- Voraussetzung: migrationLxCarsToOpensourceERP.sql wurde
-- bereits ausgefuehrt (instructions_lxcars Mastertabelle gefuellt).
--
-- Ausfuehren:
--   psql <dbname> -f dev/migrationInstructions.sql
--
-- ============================================================

BEGIN;

-- ============================================================
-- 1. Arbeitsanweisungen pro Auftrag migrieren
--
--    instructions.trans_id   -> oe_instructions_lxcars.oe_id
--    instructions.description -> oe_instructions_lxcars.description
--    instructions.position   -> oe_instructions_lxcars.sort_order
--    parts.partnumber        -> oe_instructions_lxcars.instruction_number
--
--    Nur migrieren wenn:
--      - der Auftrag in oe existiert
--      - description nicht leer ist
--      - noch nicht in oe_instructions_lxcars vorhanden
-- ============================================================
INSERT INTO oe_instructions_lxcars (oe_id, description, sort_order, instruction_number)
SELECT
    i.trans_id,
    TRIM(i.description),
    i."position",
    p.partnumber
FROM instructions i
JOIN oe ON oe.id = i.trans_id
LEFT JOIN parts p ON p.id = i.parts_id
WHERE i.description IS NOT NULL
  AND TRIM(i.description) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM oe_instructions_lxcars oil
      WHERE oil.oe_id = i.trans_id
        AND oil.description = TRIM(i.description)
  )
ORDER BY i.trans_id, i."position";

-- ============================================================
-- 2. Verifikation
-- ============================================================
SELECT
    'Alte instructions gesamt' AS pruefung,
    count(*) AS anzahl
FROM instructions
WHERE description IS NOT NULL
  AND TRIM(description) <> ''
UNION ALL
SELECT
    'Davon mit gueltigem Auftrag',
    count(*)
FROM instructions i
JOIN oe ON oe.id = i.trans_id
WHERE i.description IS NOT NULL
  AND TRIM(i.description) <> ''
UNION ALL
SELECT
    'Neue oe_instructions_lxcars gesamt',
    count(*)
FROM oe_instructions_lxcars
UNION ALL
SELECT
    'Auftraege mit Arbeitsanweisungen',
    count(DISTINCT oe_id)
FROM oe_instructions_lxcars;

COMMIT;
