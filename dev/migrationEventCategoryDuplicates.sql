-- Migration: Duplizierte Kalender-Kategorien bereinigen
-- Problem: INSERT ohne UNIQUE-Constraint hat bei jedem Schema-Update Duplikate erzeugt
-- Lösung: Nur die älteste (kleinste ID) pro Label behalten, FK-Referenzen umhängen

-- 1. Ungewollte Kategorien entfernen (FK vorher nullen)
UPDATE calendar_events SET category_id = NULL WHERE category_id IN (1, 4, 7, 8, 15);
DELETE FROM event_category WHERE id IN (1, 4, 7, 8, 15);

-- 2. calendar_events: category_id auf die älteste ID pro Label umhängen
UPDATE calendar_events ce
SET category_id = keep.min_id
FROM event_category ec
JOIN (SELECT label, MIN(id) AS min_id FROM event_category GROUP BY label) keep
    ON ec.label = keep.label
WHERE ce.category_id = ec.id
  AND ec.id != keep.min_id;

-- 3. Duplikate löschen (nur die älteste pro Label bleibt)
DELETE FROM event_category
WHERE id NOT IN (
    SELECT MIN(id) FROM event_category GROUP BY label
);
