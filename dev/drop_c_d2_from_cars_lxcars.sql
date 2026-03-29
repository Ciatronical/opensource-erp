-- Spalte c_d2 aus cars_lxcars entfernen.
-- Der Typschlüssel D.2 wird ausschließlich in kba_lxcars.d2 gespeichert
-- und über resolveKbaWithD2() mit dem Fahrzeug verknüpft.
ALTER TABLE cars_lxcars DROP COLUMN IF EXISTS c_d2;
