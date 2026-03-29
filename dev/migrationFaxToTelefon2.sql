-- ============================================================
-- Migration: customer_ext.phone_numbers
--            Label "Fax" -> "Telefon 2"
-- ============================================================
--
-- Ausfuehren:
--   psql <dbname> -f dev/migrationFaxToTelefon2.sql
--
-- ============================================================

BEGIN;

UPDATE customer_ext
SET phone_numbers = (
    SELECT jsonb_agg(
        CASE WHEN elem->>'label' = 'Fax'
             THEN jsonb_set(elem, '{label}', '"Telefon 2"')
             ELSE elem
        END
    )
    FROM jsonb_array_elements(phone_numbers) AS elem
),
    mtime = now()
WHERE phone_numbers @> '[{"label": "Fax"}]';

-- Verifikation
SELECT
    'Fax (vorher)' AS pruefung,
    0 AS anzahl
UNION ALL
SELECT
    'Telefon 2 (nachher)',
    count(*)
FROM customer_ext
WHERE phone_numbers @> '[{"label": "Telefon 2"}]';

COMMIT;
