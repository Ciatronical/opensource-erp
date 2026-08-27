-- ============================================================================
-- Storno der 21 fehlerhaften Bankbuchungen in ap_rebuild (Autoprofis GmbH)
--
-- Ursache siehe bank_auto_match() / matching.php: fehlende Datums- und
-- Betragspruefung im automatischen Zuordnen. Betroffen sind 21 der 45
-- gebuchten Umsaetze (42 acc_trans-Beine, Summe der Beine exakt 0,00).
--
-- Auf ap_dev vollstaendig durchgespielt: danach 0 ueberzahlte Rechnungen,
-- 0 unausgeglichene Rechnungen, 0 Zahlungen vor Rechnungsdatum.
--
-- VOR DEM LAUF: Backup ziehen. Laeuft in einer Transaktion — zum Testen
-- ROLLBACK am Ende stehen lassen, erst danach auf COMMIT aendern.
-- ============================================================================
BEGIN;

CREATE TEMP TABLE bad(id int);
INSERT INTO bad VALUES
    (2534),(2552),(2553),(2627),(2763),(2790),(2802),(2850),(2877),(2900),(2914),
    (3012),(3013),(3027),(3051),(3072),(3093),(3101),(3106),(3110),(3180);

-- Buchungsbeine sammeln — inkl. der Gegenbeine, die die Alt-Buchungen des
-- Bankmoduls nicht in bank_transaction_acc_trans eingetragen haben.
CREATE TEMP TABLE storno AS
WITH verknuepft AS (
    SELECT b.id AS bt_id, at.acc_trans_id, at.trans_id, at.amount, at.transdate, at.chart_id
    FROM bad b
    JOIN acc_trans at ON at.acc_trans_id IN (
            SELECT acc_trans_id FROM bank_transaction_acc_trans WHERE bank_transaction_id = b.id)
        OR at.memo LIKE '%Umsatz #' || b.id
), gegenbein AS (
    SELECT DISTINCT ON (v.acc_trans_id) v.bt_id, at.acc_trans_id, at.trans_id, at.amount
    FROM verknuepft v
    JOIN chart bc ON bc.id = v.chart_id AND bc.link ~ '(^|:)(AR_paid|AP_paid)($|:)'
    JOIN acc_trans at ON at.trans_id = v.trans_id AND at.transdate = v.transdate
                     AND at.amount = -v.amount AND at.acc_trans_id <> v.acc_trans_id
    JOIN chart cc ON cc.id = at.chart_id AND cc.link ~ '(^|:)(AR|AP)($|:)'
    WHERE at.acc_trans_id NOT IN (SELECT acc_trans_id FROM verknuepft)
    ORDER BY v.acc_trans_id, at.acc_trans_id
)
SELECT bt_id, acc_trans_id, trans_id, amount FROM verknuepft
UNION
SELECT bt_id, acc_trans_id, trans_id, amount FROM gegenbein;

-- Kontrolle: muss 21 Umsaetze / 42 Beine / Summe 0,00 ergeben
SELECT count(DISTINCT bt_id) AS umsaetze, count(*) AS beine, round(sum(amount),2) AS summe
FROM storno;

-- paid zuruecknehmen (positive Betraege = gebuchter Zahlbetrag)
WITH pos AS (SELECT trans_id, sum(amount) s FROM storno WHERE amount > 0 GROUP BY trans_id)
UPDATE ar SET paid = GREATEST(0, paid - pos.s) FROM pos WHERE ar.id = pos.trans_id;
WITH pos AS (SELECT trans_id, sum(amount) s FROM storno WHERE amount > 0 GROUP BY trans_id)
UPDATE ap SET paid = GREATEST(0, paid - pos.s) FROM pos WHERE ap.id = pos.trans_id;

-- Reihenfolge wichtig: erst das FK-referenzierende Mapping, dann acc_trans
DELETE FROM bank_transaction_acc_trans WHERE bank_transaction_id IN (SELECT id FROM bad);
DELETE FROM acc_trans WHERE acc_trans_id IN (SELECT acc_trans_id FROM storno);

-- Umsaetze wieder zur Zuordnung freigeben
UPDATE bank_transactions SET match_status = 'unmatched', cleared = false
WHERE id IN (SELECT id FROM bad);

-- Abschlusskontrollen — alle drei muessen 0 liefern
SELECT count(*) AS ueberzahlt FROM ar
 WHERE paid > amount + 0.01 AND storno IS NOT TRUE
   AND EXISTS (SELECT 1 FROM acc_trans a WHERE a.trans_id = ar.id AND a.source = 'Zahlungseingang');
SELECT count(*) AS unausgeglichen FROM (
    SELECT trans_id FROM acc_trans WHERE trans_id IN (SELECT DISTINCT trans_id FROM storno)
    GROUP BY trans_id HAVING abs(sum(amount)) > 0.005) x;
SELECT count(*) AS zahlung_vor_rechnung FROM bank_transaction_acc_trans bta
 JOIN bank_transactions bt ON bt.id = bta.bank_transaction_id
 JOIN ar ON ar.id = bta.ar_id WHERE bt.transdate < ar.transdate;

ROLLBACK;   -- <<< auf COMMIT aendern, wenn die Kontrollen stimmen
