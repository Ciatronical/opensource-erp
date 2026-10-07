-- Bank-Belegnummern 2026 zurück in die fortlaufende Folge (Stand 06.10.2026)
--
-- Ursache: nextBelegnummer() hat die kivitendo-Dialogbuchung gl 7386 mit der
-- Referenz „252401" (= Rechnungsnummer) als Belegnummer gewertet. Seit dem
-- 29.09.2026 tragen die Bankbuchungen deshalb Belegnummern 252402 ff. — mitten
-- im Rechnungsnummernkreis (DATEV-Belegfeld wäre mehrdeutig). Der Generator ist
-- korrigiert (banking_utils.php); hier werden die betroffenen Nummern in der
-- Reihenfolge ihrer Entstehung auf die nächsten freien Nummern umgesetzt.
--
-- Nur ausführen, wenn diese Belegnummern noch nicht an den Steuerberater
-- gegangen sind. Ausführen mit:
--   psql -U postgres -d ap_rebuild -f dev/belegnummern-bank-2026-neu-nummerieren.sql
BEGIN;

WITH bank AS (
    SELECT id FROM chart WHERE accno = '1800'
),
betroffen AS (
    -- je Belegnummer die Beine (Bank + Gegenbein tragen dieselbe Quelle und dasselbe Memo)
    SELECT at.source AS alt,
           min(at.acc_trans_id) AS erster
    FROM acc_trans at
    WHERE at.memo ~ '^Beleg [0-9]+ · '
      AND at.source ~ '^[0-9]+$'
      AND at.source::bigint >= 10000
      AND EXTRACT(YEAR FROM at.transdate) = 2026
      AND EXISTS (SELECT 1 FROM acc_trans b WHERE b.trans_id = at.trans_id AND b.source = at.source AND b.chart_id = (SELECT id FROM bank))
    GROUP BY at.source
),
basis AS (
    -- höchste „echte" Belegnummer der Bank 2026 (kleine Nummern)
    SELECT COALESCE(max(at.source::bigint), 0) AS n
    FROM acc_trans at, bank
    WHERE at.chart_id = bank.id
      AND EXTRACT(YEAR FROM at.transdate) = 2026
      AND at.source ~ '^[0-9]+$'
      AND at.source::bigint < 10000
      AND NOT EXISTS (SELECT 1 FROM ar WHERE invnumber = at.source)
      AND NOT EXISTS (SELECT 1 FROM ap WHERE invnumber = at.source)
),
neu AS (
    SELECT b.alt,
           (SELECT n FROM basis) + row_number() OVER (ORDER BY b.erster) AS neu
    FROM betroffen b
)
UPDATE acc_trans at
SET source = neu.neu::text,
    memo   = regexp_replace(at.memo, '^Beleg [0-9]+', 'Beleg ' || neu.neu::text)
FROM neu
WHERE at.source = neu.alt
  AND at.memo ~ '^Beleg [0-9]+ · '
  AND EXTRACT(YEAR FROM at.transdate) = 2026;

-- Kontrolle
SELECT at.source, at.memo, at.transdate
FROM acc_trans at JOIN chart c ON c.id = at.chart_id
WHERE c.accno = '1800' AND EXTRACT(YEAR FROM at.transdate) = 2026 AND at.memo LIKE 'Beleg %'
ORDER BY at.source::bigint;

COMMIT;
