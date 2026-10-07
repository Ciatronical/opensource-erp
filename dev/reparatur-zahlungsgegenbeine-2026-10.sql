-- Reparatur: fehlende Forderungs-Gegenbeine zweier Zahlungen (Stand 06.10.2026)
--
-- Ursache: Der Rechnungseditor (faktura.php, Schritt 2b) löschte beim Speichern
-- von Positionsänderungen auch das 1200-Gegenbein von Bar-/Editor-Zahlungen,
-- weil es denselben chart_link 'AR' trägt wie die Erstbuchung. Das Geldkonto-
-- Bein blieb stehen → Buchungssatz unausgeglichen, Saldenliste Soll ≠ Haben
-- um 504,12 EUR. Code ist korrigiert; diese zwei Altfälle werden hier ergänzt.
--
-- Prüfung vorher:  SELECT trans_id, sum(amount) FROM acc_trans GROUP BY 1 HAVING abs(sum(amount)) > 0.005;
-- Erwartet:        6126 → -354.12, 5605 → -150.00
-- Ausführen mit:   psql -U postgres -d ap_rebuild -f dev/reparatur-zahlungsgegenbeine-2026-10.sql
BEGIN;

-- 251955 (ar 5605): Zahlung 150,00 vom 30.03.2026 auf Bank (Editor, Quelle '03-2026', Memo 'SB')
INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, taxkey, tax_id)
SELECT 5605, c.id, 150.00, '2026-03-30', '2026-03-30', '03-2026', 'SB', 'AR', 0, 0
FROM chart c WHERE c.accno = '1200'
  AND NOT EXISTS (SELECT 1 FROM acc_trans WHERE trans_id = 5605 AND chart_id = c.id AND transdate = '2026-03-30' AND amount = 150.00);

-- 252267 (ar 6126): Barzahlung 354,12 vom 03.06.2026 (Kasse, Beleg 275)
INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, taxkey, tax_id)
SELECT 6126, c.id, 354.12, '2026-06-03', '2026-06-03', '252267', 'Barzahlung Kasse', 'AR', 0, 0
FROM chart c WHERE c.accno = '1200'
  AND NOT EXISTS (SELECT 1 FROM acc_trans WHERE trans_id = 6126 AND chart_id = c.id AND transdate = '2026-06-03' AND amount = 354.12);

-- Kontrolle: muss leer sein
SELECT trans_id, round(sum(amount)::numeric, 2) AS differenz
FROM acc_trans GROUP BY trans_id HAVING abs(sum(amount)) > 0.005;

COMMIT;
