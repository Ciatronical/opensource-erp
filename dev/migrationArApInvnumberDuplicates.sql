-- Migration: Duplizierte invnumber in ar und ap bereinigen
-- Problem: CREATE UNIQUE INDEX auf lower(invnumber) schlägt fehl,
--          weil mehrere Zeilen die gleiche invnumber haben.
-- Lösung: Den ältesten Eintrag (kleinste ID) pro invnumber unverändert
--          lassen, alle weiteren bekommen ein Suffix '-2', '-3', ...

-- ============================================================================
-- 1. Duplikate in ar (Ausgangsrechnungen) anzeigen
-- ============================================================================
SELECT id, invnumber, transdate, customer_id
FROM ar
WHERE lower(invnumber) IN (
    SELECT lower(invnumber) FROM ar GROUP BY lower(invnumber) HAVING count(*) > 1
)
ORDER BY lower(invnumber), id;

-- ============================================================================
-- 2. Duplikate in ar umbenennen (ältester Eintrag bleibt unverändert)
-- ============================================================================
UPDATE ar SET invnumber = invnumber || '-' || sub.rn
FROM (
    SELECT id, row_number() OVER (PARTITION BY lower(invnumber) ORDER BY id) AS rn
    FROM ar
    WHERE lower(invnumber) IN (
        SELECT lower(invnumber) FROM ar GROUP BY lower(invnumber) HAVING count(*) > 1
    )
) sub
WHERE ar.id = sub.id AND sub.rn > 1;

-- ============================================================================
-- 3. Duplikate in ap (Eingangsrechnungen) anzeigen
-- ============================================================================
SELECT id, invnumber, transdate, vendor_id
FROM ap
WHERE lower(invnumber) IN (
    SELECT lower(invnumber) FROM ap GROUP BY lower(invnumber) HAVING count(*) > 1
)
ORDER BY lower(invnumber), id;

-- ============================================================================
-- 4. Duplikate in ap umbenennen (ältester Eintrag bleibt unverändert)
-- ============================================================================
UPDATE ap SET invnumber = invnumber || '-' || sub.rn
FROM (
    SELECT id, row_number() OVER (PARTITION BY lower(invnumber) ORDER BY id) AS rn
    FROM ap
    WHERE lower(invnumber) IN (
        SELECT lower(invnumber) FROM ap GROUP BY lower(invnumber) HAVING count(*) > 1
    )
) sub
WHERE ap.id = sub.id AND sub.rn > 1;
