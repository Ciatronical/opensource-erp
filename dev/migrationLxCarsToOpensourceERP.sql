-- ============================================================
-- Migration: LxCars (autoprofis) -> OpenSource-ERP
-- Telefondaten in customer_ext und contacts uebertragen
-- ============================================================
--
-- Backup wiederherstellen, dann dieses Skript ausfuehren:
--   gunzip -c backups/autoprofis-dev/autoprofis-dev_backup_2026-02-26_14-32-48.sql.gz | psql <dbname>
--
-- ============================================================

BEGIN;

-- ============================================================
-- 1. fax + note_fax, phone3 + note_phone3 -> customer_ext.phone_numbers
--    Format: [{"number": "...", "label": "..."}, ...]
--    phone_labels wird nicht benoetigt.
-- ============================================================
INSERT INTO customer_ext (customer_id, phone_numbers)
SELECT
    c.id,
    (
        SELECT jsonb_agg(entry)
        FROM (
            SELECT jsonb_build_object(
                'number', c.fax,
                'label',  COALESCE(NULLIF(TRIM(c.note_fax), ''), 'Fax')
            ) AS entry
            WHERE c.fax IS NOT NULL AND TRIM(c.fax) <> ''

            UNION ALL

            SELECT jsonb_build_object(
                'number', c.phone3,
                'label',  COALESCE(NULLIF(TRIM(c.note_phone3), ''), '')
            )
            WHERE c.phone3 IS NOT NULL AND TRIM(c.phone3) <> ''
        ) sub
    )
FROM customer c
WHERE (c.fax IS NOT NULL AND TRIM(c.fax) <> '')
   OR (c.phone3 IS NOT NULL AND TRIM(c.phone3) <> '')
ON CONFLICT (customer_id) DO UPDATE
    SET phone_numbers = EXCLUDED.phone_numbers,
        mtime = now();

-- ============================================================
-- 2. note_phone -> customer.contact
-- ============================================================
UPDATE customer
SET contact = note_phone,
    mtime   = now()
WHERE note_phone IS NOT NULL AND TRIM(note_phone) <> ''
  AND (contact IS NULL OR TRIM(contact) = '');

COMMIT;


-- ============================================================
-- Migration Teil 2: Fahrzeugtabellen umbenennen
-- lxc_cars / lxckba / lxc_fs_scans -> cars_lxcars / kba_lxcars / fs_scans_lxcars
-- ============================================================
--
-- Problem: Der importierte Dump hat Daten in den alten Tabellennamen,
--          der Code verwendet aber die neuen Namen.
--
--   lxc_cars     (6346 Eintraege) -> cars_lxcars     (leer)
--   lxckba       (97940 Eintraege) -> kba_lxcars     (97937, aber falsche IDs)
--   lxc_fs_scans (2544 Eintraege) -> fs_scans_lxcars (leer)
--
-- ============================================================

-- Hilfsfunktion: Benennt Tabelle/Sequence/Index nur um wenn Quelle existiert UND Ziel noch nicht.
-- Dadurch ist das Script idempotent (mehrfach ausfuehrbar ohne Fehler).
CREATE OR REPLACE FUNCTION _safe_rename(p_type text, p_old text, p_new text) RETURNS text AS '
BEGIN
  IF p_type = ''TABLE'' THEN
    IF EXISTS (SELECT 1 FROM pg_tables WHERE tablename = p_old AND schemaname = ''public'')
       AND NOT EXISTS (SELECT 1 FROM pg_tables WHERE tablename = p_new AND schemaname = ''public'') THEN
      EXECUTE format(''ALTER TABLE %I RENAME TO %I'', p_old, p_new);
      RETURN p_old || '' -> '' || p_new;
    END IF;
  ELSIF p_type = ''SEQ'' THEN
    IF EXISTS (SELECT 1 FROM pg_sequences WHERE sequencename = p_old)
       AND NOT EXISTS (SELECT 1 FROM pg_sequences WHERE sequencename = p_new) THEN
      EXECUTE format(''ALTER SEQUENCE %I RENAME TO %I'', p_old, p_new);
      RETURN p_old || '' -> '' || p_new;
    END IF;
  ELSIF p_type = ''IDX'' THEN
    IF EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = p_old)
       AND NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = p_new) THEN
      EXECUTE format(''ALTER INDEX %I RENAME TO %I'', p_old, p_new);
      RETURN p_old || '' -> '' || p_new;
    END IF;
  END IF;
  RETURN ''skip: '' || p_old;
END;
' LANGUAGE plpgsql;


-- ============================================================
-- 1. CARS: lxc_cars -> cars_lxcars
-- ============================================================

-- FK-Constraints entfernen (idempotent durch IF EXISTS)
ALTER TABLE ar_ext DROP CONSTRAINT IF EXISTS ar_ext_c_id_fkey;
ALTER TABLE oe_ext DROP CONSTRAINT IF EXISTS oe_ext_c_id_fkey;

-- Leere neue Tabelle als Backup behalten (samt Sequence + Indexes)
SELECT _safe_rename('SEQ', 'cars_lxcars_c_id_seq', '_cars_lxcars_empty_backup_c_id_seq');
SELECT _safe_rename('IDX', 'cars_lxcars_pkey', '_cars_lxcars_empty_backup_pkey');
SELECT _safe_rename('IDX', 'cars_lxcars_c_ln_unique', '_cars_lxcars_empty_backup_c_ln_unique');
SELECT _safe_rename('IDX', 'cars_lxcars_c_fin_unique', '_cars_lxcars_empty_backup_c_fin_unique');
SELECT _safe_rename('IDX', 'cars_lxcars_scan_detail_unique', '_cars_lxcars_empty_backup_scan_detail_unique');
SELECT _safe_rename('IDX', 'cars_lxcars_scan_unique', '_cars_lxcars_empty_backup_scan_unique');
SELECT _safe_rename('IDX', 'idx_cars_lxcars_c_ln', '_idx_cars_lxcars_empty_backup_c_ln');
SELECT _safe_rename('IDX', 'idx_cars_lxcars_c_m', '_idx_cars_lxcars_empty_backup_c_m');
SELECT _safe_rename('IDX', 'idx_cars_lxcars_c_ow', '_idx_cars_lxcars_empty_backup_c_ow');
SELECT _safe_rename('IDX', 'idx_cars_lxcars_c_t', '_idx_cars_lxcars_empty_backup_c_t');
SELECT _safe_rename('TABLE', 'cars_lxcars', '_cars_lxcars_empty_backup');

-- Alte Tabelle umbenennen
SELECT _safe_rename('TABLE', 'lxc_cars', 'cars_lxcars');

-- Fehlende Spalten ergaenzen (existieren im neuen Schema, nicht im alten)
ALTER TABLE cars_lxcars ADD COLUMN IF NOT EXISTS chk_c_d boolean DEFAULT true;
ALTER TABLE cars_lxcars ADD COLUMN IF NOT EXISTS c_sk boolean DEFAULT false;

-- Hinweis: c_zrd, c_bf, c_wd bleiben als TEXT (nicht DATE).
-- Die Altdaten enthalten Freitext wie "kette", "faellig", "01/34", "??????".

-- Sequence umbenennen (Code referenziert 'cars_lxcars_c_id_seq')
SELECT _safe_rename('SEQ', 'lxc_cars_c_id_seq', 'cars_lxcars_c_id_seq');

-- Indexes umbenennen
SELECT _safe_rename('IDX', 'cars6_pkey', 'cars_lxcars_pkey');
SELECT _safe_rename('IDX', 'cars6_c_ln_key', 'cars_lxcars_c_ln_unique');
SELECT _safe_rename('IDX', 'lxc_cars_c_fin_key', 'cars_lxcars_c_fin_unique');
SELECT _safe_rename('IDX', 'lxc_cars_scan_detail_id_unique', 'cars_lxcars_scan_detail_unique');
SELECT _safe_rename('IDX', 'lxc_cars_scan_id_unique', 'cars_lxcars_scan_unique');
SELECT _safe_rename('IDX', 'c_ln_idx', 'idx_cars_lxcars_c_ln');
SELECT _safe_rename('IDX', 'c_m_idx', 'idx_cars_lxcars_c_m');
SELECT _safe_rename('IDX', 'c_ow_idx', 'idx_cars_lxcars_c_ow');
SELECT _safe_rename('IDX', 'c_t_idx', 'idx_cars_lxcars_c_t');

-- FK-Constraints wiederherstellen (drop+add ist idempotent)
ALTER TABLE ar_ext DROP CONSTRAINT IF EXISTS ar_ext_c_id_fkey;
ALTER TABLE ar_ext ADD CONSTRAINT ar_ext_c_id_fkey
    FOREIGN KEY (c_id) REFERENCES cars_lxcars(c_id) ON DELETE SET NULL;
ALTER TABLE oe_ext DROP CONSTRAINT IF EXISTS oe_ext_c_id_fkey;
ALTER TABLE oe_ext ADD CONSTRAINT oe_ext_c_id_fkey
    FOREIGN KEY (c_id) REFERENCES cars_lxcars(c_id) ON DELETE SET NULL;


-- ============================================================
-- 2. KBA: lxckba -> kba_lxcars
--    WICHTIG: IDs muessen 1:1 erhalten bleiben, da cars_lxcars.kba_id
--    direkt auf kba_lxcars.id referenziert (5888 Verknuepfungen).
--    Deshalb: Tabelle komplett kopieren (inkl. id-Werte).
-- ============================================================

-- Vom Update-Prozess angelegte kba_lxcars entfernen (hat falsche IDs aus CSV-Import)
DROP TABLE IF EXISTS kba_lxcars CASCADE;

-- Komplette Kopie der Originaltabelle inkl. aller Daten und IDs
CREATE TABLE kba_lxcars AS SELECT * FROM lxckba;

-- Sequence anlegen und mit aktuellem Max-Wert initialisieren
CREATE SEQUENCE IF NOT EXISTS kba_lxcars_id_seq AS integer;
SELECT setval('kba_lxcars_id_seq', COALESCE((SELECT max(id) FROM kba_lxcars), 1));
ALTER TABLE kba_lxcars ALTER COLUMN id SET DEFAULT nextval('kba_lxcars_id_seq');
ALTER TABLE kba_lxcars ALTER COLUMN id SET NOT NULL;

-- Constraints sicherstellen
ALTER TABLE kba_lxcars DROP CONSTRAINT IF EXISTS kba_lxcars_pkey;
ALTER TABLE kba_lxcars ADD CONSTRAINT kba_lxcars_pkey PRIMARY KEY (id);
ALTER TABLE kba_lxcars DROP CONSTRAINT IF EXISTS kba_lxcars_unique;
ALTER TABLE kba_lxcars ADD CONSTRAINT kba_lxcars_unique UNIQUE (hsn, tsn, d2);


-- ============================================================
-- 3. FS_SCANS: lxc_fs_scans -> fs_scans_lxcars
-- ============================================================

-- Leere neue Tabelle als Backup behalten (samt Indexes)
SELECT _safe_rename('IDX', 'fs_scans_lxcars_scan_detail_id_key', '_fs_scans_lxcars_empty_backup_scan_detail_id_key');
SELECT _safe_rename('IDX', 'fs_scans_lxcars_scan_id_key', '_fs_scans_lxcars_empty_backup_scan_id_key');
SELECT _safe_rename('TABLE', 'fs_scans_lxcars', '_fs_scans_lxcars_empty_backup');

-- Alte Tabelle umbenennen
SELECT _safe_rename('TABLE', 'lxc_fs_scans', 'fs_scans_lxcars');

-- Index umbenennen
SELECT _safe_rename('IDX', 'lxc_fs_scans_pkey', 'fs_scans_lxcars_pkey');

-- Unique Constraints hinzufuegen (drop+add ist idempotent)
ALTER TABLE fs_scans_lxcars DROP CONSTRAINT IF EXISTS fs_scans_lxcars_scan_id_key;
ALTER TABLE fs_scans_lxcars ADD CONSTRAINT fs_scans_lxcars_scan_id_key UNIQUE (scan_id);
ALTER TABLE fs_scans_lxcars DROP CONSTRAINT IF EXISTS fs_scans_lxcars_scan_detail_id_key;
ALTER TABLE fs_scans_lxcars ADD CONSTRAINT fs_scans_lxcars_scan_detail_id_key UNIQUE (scan_detail_id);


-- ============================================================
-- 4. Sequence-Werte auf aktuelle Maximalwerte setzen
--    (kba_lxcars_id_seq wird bereits in Abschnitt 2 gesetzt)
-- ============================================================

SELECT setval('cars_lxcars_c_id_seq', COALESCE((SELECT max(c_id) FROM cars_lxcars), 1));


-- ============================================================
-- 5. Verifikation
-- ============================================================

SELECT 'cars_lxcars' AS tabelle, count(*) AS eintraege FROM cars_lxcars
UNION ALL SELECT 'kba_lxcars', count(*) FROM kba_lxcars
UNION ALL SELECT 'fs_scans_lxcars', count(*) FROM fs_scans_lxcars;

-- KBA-ID Integritaet pruefen (muss 0 sein)
SELECT count(*) AS kaputte_kba_referenzen
FROM cars_lxcars c
WHERE c.kba_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM kba_lxcars k WHERE k.id = c.kba_id);

-- Hilfsfunktion aufraeumen
DROP FUNCTION IF EXISTS _safe_rename;


-- ============================================================
-- 6. OE -> OE_EXT: Fahrzeugdaten aus oe in oe_ext kopieren
--    oe_ext ist leer, oe hat 11077 Auftraege mit c_id
--
--    Mapping:
--      oe.id              -> oe_ext.oe_id
--      oe.c_id            -> oe_ext.c_id           (nur wenn Fahrzeug noch existiert)
--      oe.km_stnd         -> oe_ext.km_stand        (Spaltenname anders!)
--      oe.delivery_time   -> oe_ext.bringetermin    (text "DD.MM.YYYY HH:MM" -> date)
--      oe.finish_time     -> oe_ext.fertigstellung  (text "DD.MM.YYYY HH:MM Uhr" -> date, 631 Freitext-Werte werden ignoriert)
--      oe.internalorder   -> oe_ext.intern
--      oe.printed         -> oe_ext.gedruckt
-- ============================================================

BEGIN;

INSERT INTO oe_ext (oe_id, c_id, km_stand, bringetermin, fertigstellung, intern, gedruckt)
SELECT
    oe.id,
    CASE WHEN EXISTS (SELECT 1 FROM cars_lxcars c WHERE c.c_id = oe.c_id)
         THEN oe.c_id ELSE NULL END,
    NULLIF(oe.km_stnd, 0),
    CASE WHEN oe.delivery_time ~ '^\d{2}\.\d{2}\.\d{4}'
         THEN to_date(left(oe.delivery_time, 10), 'DD.MM.YYYY')
         ELSE NULL END,
    CASE WHEN oe.finish_time ~ '^\d{2}\.\d{2}\.\d{4}'
         THEN to_date(left(oe.finish_time, 10), 'DD.MM.YYYY')
         ELSE NULL END,
    COALESCE(oe.internalorder, false),
    COALESCE(oe.printed, false)
FROM oe
WHERE oe.c_id IS NOT NULL
   OR (oe.km_stnd IS NOT NULL AND oe.km_stnd > 0)
   OR (oe.delivery_time IS NOT NULL AND oe.delivery_time != '')
   OR (oe.finish_time IS NOT NULL AND oe.finish_time != '')
   OR oe.internalorder = true
   OR oe.printed = true
ON CONFLICT (oe_id) DO NOTHING;

-- Verifikation
SELECT 'oe_ext' AS tabelle, count(*) AS eintraege FROM oe_ext;

COMMIT;


-- ============================================================
-- 7. OE Spalten entfernen (wenn oe_ext Migration erfolgreich)
--    Erst ausfuehren wenn alles in oe_ext korrekt ist!
-- ============================================================

-- BEGIN;
-- ALTER TABLE oe DROP COLUMN IF EXISTS c_id;
-- ALTER TABLE oe DROP COLUMN IF EXISTS km_stnd;
-- ALTER TABLE oe DROP COLUMN IF EXISTS car_manuf;
-- ALTER TABLE oe DROP COLUMN IF EXISTS car_status;
-- ALTER TABLE oe DROP COLUMN IF EXISTS car_type;
-- ALTER TABLE oe DROP COLUMN IF EXISTS delivery_time;
-- ALTER TABLE oe DROP COLUMN IF EXISTS finish_time;
-- ALTER TABLE oe DROP COLUMN IF EXISTS internalorder;
-- ALTER TABLE oe DROP COLUMN IF EXISTS printed;
-- COMMIT;


-- ============================================================
-- 8. PARTS (instruction=true) -> instructions_lxcars (Master-Tabelle)
--    In LxCars sind Arbeitsanweisungen als parts mit instruction=true
--    gespeichert. Die partnumber wird als instruction_number uebernommen,
--    die description als Beschreibung. usage_count wird aus der alten
--    instructions-Tabelle (Auftrags-Positionen) ermittelt.
-- ============================================================

BEGIN;

-- Alte/falsche Eintraege zuerst loeschen
DELETE FROM instructions_lxcars;

-- Arbeitsanweisungen aus parts uebernehmen (nur instruction=true)
-- Bei doppelten Beschreibungen: usage_count summieren, kleinste partnumber nehmen
INSERT INTO instructions_lxcars (description, usage_count, instruction_number)
SELECT
    sub.description,
    sub.total_usage,
    sub.instruction_number
FROM (
    SELECT
        TRIM(p.description) AS description,
        SUM(COALESCE(usage.cnt, 0)) AS total_usage,
        MIN(p.partnumber) AS instruction_number
    FROM parts p
    LEFT JOIN (
        SELECT i.parts_id, COUNT(*) AS cnt
        FROM instructions i
        WHERE i.parts_id IS NOT NULL
        GROUP BY i.parts_id
    ) usage ON usage.parts_id = p.id
    WHERE p.instruction = true
      AND p.description IS NOT NULL
      AND TRIM(p.description) <> ''
    GROUP BY TRIM(p.description)
) sub
ON CONFLICT (description) DO UPDATE
    SET usage_count = EXCLUDED.usage_count,
        instruction_number = EXCLUDED.instruction_number;

-- Nummernkreis auf naechsten freien Wert setzen
UPDATE defaults_oserp
SET value = (
    SELECT COALESCE(MAX(
        regexp_replace(instruction_number, '[^0-9]', '', 'g')::bigint
    ), 100) + 1
    FROM instructions_lxcars
    WHERE instruction_number ~ '[0-9]'
)::text,
    mtime = now()
WHERE key = 'instructionnumber';

-- Verifikation
SELECT 'instructions_lxcars' AS tabelle, count(*) AS eintraege FROM instructions_lxcars;

COMMIT;


-- ============================================================
-- 9. AUFRAEUMEN (erst ausfuehren, wenn alles funktioniert!)
-- ============================================================

-- -- Backup-Tabellen loeschen:
-- DROP TABLE IF EXISTS _cars_lxcars_empty_backup;
-- DROP TABLE IF EXISTS _kba_lxcars_old_backup;
-- DROP TABLE IF EXISTS _fs_scans_lxcars_empty_backup;

-- -- Alte Tabellen loeschen (nicht mehr verwendet):
-- DROP TABLE IF EXISTS instructions;  -- Arbeitsanweisungen (jetzt in instructions_lxcars)
-- DROP TABLE IF EXISTS kbacars;       -- 69194 Eintraege, alter KBA-Import
-- DROP TABLE IF EXISTS lxc_flex;      -- 0 Eintraege
-- DROP TABLE IF EXISTS lxc_ver;       -- 0 Eintraege
