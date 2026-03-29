-- Migration: customer.sw -> customer_ext.keywords
-- Verschiebt Stichwörter aus der Spalte customer.sw in customer_ext.keywords
--
-- Voraussetzung: Die Spalte customer_ext.keywords muss bereits existieren
-- (wird durch das Schema-Update automatisch angelegt).

-- Daten übertragen (nur Kunden mit nicht-leerem sw)
INSERT INTO customer_ext (customer_id, keywords)
SELECT id, sw
FROM customer
WHERE sw IS NOT NULL AND TRIM(sw) <> ''
ON CONFLICT (customer_id)
DO UPDATE SET keywords = EXCLUDED.keywords, mtime = NOW()
WHERE customer_ext.keywords IS NULL OR TRIM(customer_ext.keywords) = '';

-- Spalte sw aus customer entfernen
-- ALTER TABLE customer DROP COLUMN IF EXISTS sw;
