<?php
// backend/api/faktura/recurring_sql.php
//
// SQL-Bausteine der wiederkehrenden Rechnungen, die mehr als ein Modul
// braucht: die Übersicht und die Erzeugung (recurring.php) ebenso wie die
// Kachel im Buchhaltungs-Cockpit (accounting/cockpit.php). Bewusst ohne
// API-Funktionen, damit die Buchhaltung sie einbinden kann, ohne das ganze
// Faktura-Modul zu laden.

// ───────────────────────────────────────────────────────────────────────────────
// Gemeinsame SQL-Bausteine
// ───────────────────────────────────────────────────────────────────────────────

/**
 * CTE `cfgs`: alle Abrechnungen mit Auftrag, Kunde, Erweiterung, Umrechnung
 * (Abrechnungs- und Auftragswertperiode in Monaten), Positionssummen und
 * dem daraus folgenden Betrag je Periode. Wird von Übersicht, Fälligkeit und
 * Erzeugung gleich verwendet, damit überall dieselben Zahlen stehen.
 *
 * @return string CTE-Definition ohne führendes WITH, endet ohne Komma
 */
function recurringConfigCte(): string {
    return <<<SQL
        cfgs AS (
            SELECT c.id, c.oe_id, c.active, COALESCE(c.terminated, false) AS terminated,
                   c.start_date, c.end_date, c.first_billing_date, c.extend_automatically_by,
                   c.periodicity, c.order_value_periodicity, c.send_email, c.direct_debit,
                   c.print, c.printer_id, c.copies, c.ar_chart_id,
                   c.email_recipient_contact_id, c.email_recipient_address, c.email_sender,
                   c.email_subject, c.email_body,
                   e.id AS ext_id,
                   COALESCE(e.interval_unit, CASE c.periodicity WHEN 'o' THEN 'once' ELSE 'month' END) AS interval_unit,
                   COALESCE(e.interval_count, CASE c.periodicity WHEN 'q' THEN 3 WHEN 'b' THEN 6 WHEN 'y' THEN 12 ELSE 1 END) AS interval_count,
                   COALESCE(e.align_to_calendar, false) AS align_to_calendar,
                   COALESCE(e.prorate_partial, false)   AS prorate_partial,
                   COALESCE(e.billing_timing, 'advance') AS billing_timing,
                   COALESCE(e.billing_offset_days, 0)   AS billing_offset_days,
                   COALESCE(e.price_mode, 'fixed')      AS price_mode,
                   e.price_increase_percent, e.price_increase_month,
                   e.hold_on_overdue_days, e.notice_period_months, e.min_term_months,
                   e.paused_until, COALESCE(e.post_to_ledger, true) AS post_to_ledger, e.notes,
                   o.ordnumber, o.transdate AS order_date, o.customer_id, o.amount AS order_amount,
                   o.netamount AS order_netamount, COALESCE(o.taxincluded, false) AS taxincluded,
                   o.closed AS order_closed, o.transaction_description, o.currency_id,
                   cu.name AS customer_name, cu.customernumber, cu.invoice_mail, cu.email AS customer_email,
                   cu.pricegroup_id,
                   bm.billing_months,
                   CASE c.order_value_periodicity
                        WHEN 'm' THEN 1 WHEN 'q' THEN 3 WHEN 'b' THEN 6 WHEN 'y' THEN 12
                        WHEN '2' THEN 24 WHEN '3' THEN 36 WHEN '4' THEN 48 WHEN '5' THEN 60
                        ELSE bm.billing_months END::numeric AS value_months,
                   COALESCE(sums.recurring_total, 0) AS recurring_total,
                   COALESCE(sums.once_open_total, 0) AS once_open_total,
                   COALESCE(sums.item_count, 0)      AS item_count
            FROM periodic_invoices_configs c
            JOIN oe o ON o.id = c.oe_id
            LEFT JOIN customer cu ON cu.id = o.customer_id
            LEFT JOIN periodic_invoices_configs_ext e ON e.config_id = c.id
            CROSS JOIN LATERAL (
                SELECT CASE COALESCE(e.interval_unit, CASE c.periodicity WHEN 'o' THEN 'once' ELSE 'month' END)
                           WHEN 'once' THEN NULL
                           WHEN 'year' THEN 12 * COALESCE(e.interval_count, 1)
                           WHEN 'week' THEN 7 * COALESCE(e.interval_count, 1) / 30.436875
                           WHEN 'day'  THEN COALESCE(e.interval_count, 1) / 30.436875
                           ELSE COALESCE(e.interval_count, CASE c.periodicity WHEN 'q' THEN 3 WHEN 'b' THEN 6 WHEN 'y' THEN 12 ELSE 1 END)
                       END::numeric AS billing_months
            ) bm
            LEFT JOIN LATERAL (
                SELECT SUM(ROUND((oi.qty * oi.sellprice * (1 - COALESCE(oi.discount, 0)))::numeric, 2))
                           FILTER (WHERE oi.recurring_billing_mode = 'always') AS recurring_total,
                       SUM(ROUND((oi.qty * oi.sellprice * (1 - COALESCE(oi.discount, 0)))::numeric, 2))
                           FILTER (WHERE oi.recurring_billing_mode = 'once' AND oi.recurring_billing_invoice_id IS NULL) AS once_open_total,
                       COUNT(*) FILTER (WHERE oi.recurring_billing_mode <> 'never') AS item_count
                FROM orderitems oi WHERE oi.trans_id = o.id
            ) sums ON true
        ),
        cfgx AS (
            -- Betrag je Periode (Auftragswert auf die Abrechnungsperiode umgerechnet),
            -- Monatsäquivalent (MRR), Zustand und Mahnsperre
            SELECT cfgs.*,
                   CASE WHEN billing_months IS NULL OR value_months IS NULL OR value_months = 0 THEN 1
                        ELSE billing_months / value_months END AS value_factor,
                   ROUND((recurring_total * CASE WHEN billing_months IS NULL OR value_months IS NULL OR value_months = 0 THEN 1
                                                 ELSE billing_months / value_months END)::numeric, 2) AS period_amount,
                   CASE WHEN billing_months IS NULL OR billing_months = 0 THEN 0
                        ELSE ROUND((recurring_total / value_months)::numeric, 2) END AS monthly_amount,
                   (hold_on_overdue_days IS NOT NULL AND EXISTS (
                        SELECT 1 FROM ar a
                        WHERE a.customer_id = cfgs.customer_id AND a.invoice AND NOT COALESCE(a.storno, false)
                          AND a.amount - a.paid > 0.005
                          AND a.duedate < CURRENT_DATE - cfgs.hold_on_overdue_days
                   )) AS blocked,
                   CASE WHEN NOT active AND paused_until IS NOT NULL AND paused_until >= CURRENT_DATE THEN 'paused'
                        WHEN interval_unit = 'once' AND EXISTS (SELECT 1 FROM periodic_invoices pi WHERE pi.config_id = cfgs.id) THEN 'ended'
                        WHEN NOT active THEN 'inactive'
                        WHEN end_date IS NOT NULL AND end_date < CURRENT_DATE
                             AND (terminated OR COALESCE(extend_automatically_by, 0) = 0) THEN 'ended'
                        WHEN terminated THEN 'terminated'
                        ELSE 'active' END AS status
            FROM cfgs
        )
    SQL;
}

/**
 * CTE `due`: alle fälligen, noch nicht erzeugten Perioden aktiver Abrechnungen.
 * Pausierte, gekündigte und beendete Abrechnungen liefern nichts; gesperrte
 * (Mahnsperre) werden mit blocked = true geliefert und erst nach Bestätigung
 * erzeugt.
 *
 * Setzt die CTEs aus recurringConfigCte() voraus.
 */
function recurringDueCte(): string {
    return <<<SQL
        due AS (
            -- Einmalige, noch offene Positionen kommen auf die erste fällige Rechnung
            SELECT x.id AS config_id, x.oe_id, x.ordnumber, x.customer_id, x.customer_name, x.customernumber,
                   x.blocked, x.hold_on_overdue_days, x.send_email, x.taxincluded, x.currency_id,
                   p.n, p.period_start, p.period_end, p.billing_date, p.factor, p.is_partial,
                   ROUND((x.period_amount * p.factor
                          * recurring_index_factor(x.price_increase_percent, x.price_increase_month, x.start_date, p.period_start)
                          + CASE WHEN ROW_NUMBER() OVER (PARTITION BY x.id ORDER BY p.period_start) = 1
                                 THEN x.once_open_total ELSE 0 END)::numeric, 2) AS amount
            FROM cfgx x
            CROSS JOIN LATERAL recurring_config_periods(x.id, CURRENT_DATE, 2000) p
            WHERE x.status = 'active' AND p.state = 'due'
        )
    SQL;
}
