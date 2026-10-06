<?php
// backend/api/faktura/recurring.php
//
// Wiederkehrende Rechnungen (Abos, Mieten, Wartungsverträge) — der Nachbau der
// kivitendo-Funktion „Wiederkehrende Rechnungen" auf deren Tabellen:
//
//   periodic_invoices_configs   eine Zeile je Auftrag: Rhythmus, Laufzeit, Versand
//   periodic_invoices           eine Zeile je erzeugter Rechnung (Periodenbeginn =
//                               Merkposten, was schon abgerechnet ist)
//   orderitems.recurring_billing_mode   je Position: always / once / never
//
// Dazu die Zusatztabellen aus backend/upstall/crm/company_schema.sql:
// periodic_invoices_configs_ext (freie Intervalle, Kalender-Ausrichtung,
// anteilige Perioden, nachschüssig, Preisanpassung, Kündigungsfristen,
// Mahnsperre), periodic_invoices_ext (Periode, Faktor, Zustellung je Rechnung)
// und periodic_invoices_skips (bewusst ausgelassene Perioden).
//
// Die Datumslogik liegt vollständig in der Datenbank (recurring_invoice_periods,
// recurring_config_periods, recurring_fill_placeholders) — Vorschau, Fälligkeit
// und Erzeugung rechnen mit denselben Funktionen und kommen deshalb immer auf
// dieselben Perioden.
//
// Anders als kivitendo: beliebige Intervalle (alle 2 Wochen, alle 2 Jahre),
// Perioden an Kalendergrenzen mit anteiliger erster Periode, vor- oder
// nachschüssige Abrechnung mit Datumsversatz, aktuelle Listenpreise statt
// eingefrorener Auftragspreise, jährliche Preisanpassung, Pausieren mit
// Enddatum, Kündigungsfristen mit nächstmöglichem Kündigungstermin,
// Erzeugungsstopp bei überfälligen Kundenrechnungen, Überspringen einzelner
// Perioden und eine Vorschau, die vor dem Speichern zeigt, was wann in welcher
// Höhe berechnet wird.

require_once __DIR__.'/../lib/mitarbeiter.php';
require_once __DIR__.'/../lib/belegablage.php';
require_once __DIR__.'/../print/print.php';
require_once __DIR__.'/../print/template_engine.php';
require_once __DIR__.'/../email/mailer.php';
require_once __DIR__.'/recurring_sql.php';

// Zulässige Werte der Erweiterungsfelder (Validierung beim Speichern)
define('RECURRING_INTERVAL_UNITS', ['day', 'week', 'month', 'year', 'once']);
define('RECURRING_BILLING_TIMINGS', ['advance', 'arrears']);
define('RECURRING_PRICE_MODES', ['fixed', 'current']);
define('RECURRING_VALUE_PERIODICITIES', ['p', 'm', 'q', 'b', 'y', '2', '3', '4', '5']);

/**
 * kivitendo-Periodizität aus Einheit und Anzahl: m / q / b / y / o. Für
 * Intervalle, die kivitendo nicht kennt (Wochen, 2 Monate, 2 Jahre), steht
 * das nächstliegende Kürzel — die Wahrheit liegt in der _ext-Zeile.
 */
function recurringPeriodicityCode(string $unit, int $count): string {
    if ($unit === 'once') return 'o';
    if ($unit === 'year') return 'y';
    if ($unit === 'month') {
        return ['1' => 'm', '3' => 'q', '6' => 'b', '12' => 'y'][(string)$count] ?? 'm';
    }
    return 'm';
}

// ───────────────────────────────────────────────────────────────────────────────
// API: Übersicht
// ───────────────────────────────────────────────────────────────────────────────

/**
 * Übersicht aller wiederkehrenden Abrechnungen: Kennzahlen (aktive Abos,
 * Monats- und Jahresvolumen, fällige Rechnungen), die Liste mit Zustand,
 * nächster und letzter Rechnung, Kündigungsterminen, die fälligen Perioden
 * zum Erzeugen und die erwarteten Einnahmen der nächsten sechs Monate.
 *
 * @testdata {}
 */
function getRecurringOverview($data) {
    permit('sales_order_edit');
    $db = DbhCompany::begin();
    $cfgCte = recurringConfigCte();
    $dueCte = recurringDueCte();

    $row = $db->getOne(<<<SQL
        WITH {$cfgCte},
        {$dueCte},
        allp AS (
            -- Perioden der nächsten zwei Jahre je Abrechnung — einmal berechnet,
            -- daraus Zustand je Abrechnung und die erwarteten Einnahmen
            SELECT x.id, x.status, x.period_amount, x.price_increase_percent, x.price_increase_month, x.start_date, p.*
            FROM cfgx x
            CROSS JOIN LATERAL recurring_config_periods(x.id, (CURRENT_DATE + INTERVAL '2 years')::date, 2000) p
        ),
        per AS (
            -- Zustand der Perioden je Abrechnung: nächste, fällige, letzte Rechnung
            SELECT p.id,
                   MIN(p.billing_date) FILTER (WHERE p.state IN ('due', 'planned'))  AS next_billing_date,
                   MIN(p.period_start) FILTER (WHERE p.state IN ('due', 'planned'))  AS next_period_start,
                   MIN(p.period_end)   FILTER (WHERE p.state IN ('due', 'planned'))  AS next_period_end,
                   COUNT(*) FILTER (WHERE p.state = 'due')                            AS due_count,
                   COUNT(*) FILTER (WHERE p.state = 'created')                        AS invoice_count,
                   COUNT(*) FILTER (WHERE p.state = 'skipped')                        AS skipped_count,
                   MAX(p.ar_transdate)                                                AS last_invoice_date,
                   (ARRAY_AGG(p.invnumber ORDER BY p.ar_transdate DESC NULLS LAST, p.ar_id DESC) FILTER (WHERE p.ar_id IS NOT NULL))[1] AS last_invnumber,
                   (ARRAY_AGG(p.ar_id ORDER BY p.ar_transdate DESC NULLS LAST, p.ar_id DESC) FILTER (WHERE p.ar_id IS NOT NULL))[1]     AS last_ar_id,
                   COALESCE(SUM(p.ar_amount) FILTER (WHERE p.ar_id IS NOT NULL), 0)   AS invoiced_total,
                   COALESCE(SUM(p.ar_amount - p.ar_paid) FILTER (WHERE p.ar_id IS NOT NULL), 0) AS open_total
            FROM allp p
            GROUP BY p.id
        ),
        cancel AS (
            -- Nächstmögliches Laufzeitende: Enddatum, verlängert um ganze Verlängerungs-
            -- schritte, bis die Kündigungsfrist noch eingehalten werden kann; mindestens
            -- das Ende der Mindestlaufzeit. Daraus die Kündigungsfrist (letzter Tag).
            SELECT x.id,
                   GREATEST(
                       CASE WHEN x.end_date IS NULL THEN NULL
                            WHEN x.terminated OR COALESCE(x.extend_automatically_by, 0) = 0 THEN x.end_date
                            ELSE (SELECT MIN(d) FROM generate_series(0, 240) k,
                                  LATERAL (SELECT (x.end_date + make_interval(months => k * x.extend_automatically_by))::date AS d) s
                                  WHERE (d - make_interval(months => COALESCE(x.notice_period_months, 0)))::date >= CURRENT_DATE)
                       END,
                       CASE WHEN x.min_term_months IS NOT NULL THEN (x.start_date + make_interval(months => x.min_term_months))::date - 1 END
                   ) AS next_possible_end
            FROM cfgx x
        ),
        upcoming AS (
            -- Erwartete Einnahmen der nächsten sechs Monate nach Rechnungsmonat
            SELECT to_char(date_trunc('month', p.billing_date), 'YYYY-MM') AS month,
                   COUNT(*) AS invoices,
                   SUM(ROUND((p.period_amount * p.factor
                              * recurring_index_factor(p.price_increase_percent, p.price_increase_month, p.start_date, p.period_start))::numeric, 2)) AS amount
            FROM allp p
            WHERE p.status IN ('active', 'terminated') AND p.state IN ('due', 'planned')
              AND p.billing_date < (date_trunc('month', CURRENT_DATE) + INTERVAL '6 months')::date
            GROUP BY 1 ORDER BY 1
        )
        SELECT json_build_object(
            'kpis', (SELECT json_build_object(
                'active_count',   COUNT(*) FILTER (WHERE status = 'active'),
                'paused_count',   COUNT(*) FILTER (WHERE status = 'paused'),
                'terminated_count', COUNT(*) FILTER (WHERE status = 'terminated'),
                'ended_count',    COUNT(*) FILTER (WHERE status IN ('ended', 'inactive')),
                'monthly_amount', COALESCE(SUM(monthly_amount) FILTER (WHERE status IN ('active', 'terminated')), 0),
                'yearly_amount',  COALESCE(SUM(monthly_amount) FILTER (WHERE status IN ('active', 'terminated')), 0) * 12,
                'blocked_count',  COUNT(*) FILTER (WHERE status = 'active' AND blocked),
                'customers',      COUNT(DISTINCT customer_id) FILTER (WHERE status IN ('active', 'terminated'))
            ) FROM cfgx),
            'due_summary', (SELECT json_build_object(
                'count',   COUNT(*),
                'amount',  COALESCE(SUM(amount), 0),
                'blocked', COUNT(*) FILTER (WHERE blocked),
                'customers', COUNT(DISTINCT customer_id)
            ) FROM due),
            'expiring', (SELECT json_build_object(
                'count', COUNT(*),
                'ids',   COALESCE(json_agg(x.id), '[]'::json)
            ) FROM cfgx x LEFT JOIN cancel ca ON ca.id = x.id
              WHERE x.status IN ('active', 'terminated')
                AND ((x.terminated AND x.end_date BETWEEN CURRENT_DATE AND CURRENT_DATE + 30)
                     OR (NOT x.terminated AND x.notice_period_months IS NOT NULL AND ca.next_possible_end IS NOT NULL
                         AND (ca.next_possible_end - make_interval(months => x.notice_period_months))::date BETWEEN CURRENT_DATE AND CURRENT_DATE + 30))),
            'configs', COALESCE((SELECT jsonb_agg(
                -- alle Spalten der Abrechnung plus Perioden- und Kündigungsangaben
                (to_jsonb(x) - 'ext_id') || jsonb_build_object(
                    'next_billing_date', per.next_billing_date, 'next_period_start', per.next_period_start,
                    'next_period_end', per.next_period_end,
                    'due_count', per.due_count, 'invoice_count', per.invoice_count, 'skipped_count', per.skipped_count,
                    'last_invoice_date', per.last_invoice_date, 'last_invnumber', per.last_invnumber, 'last_ar_id', per.last_ar_id,
                    'invoiced_total', per.invoiced_total, 'open_total', per.open_total,
                    'next_possible_end', ca.next_possible_end,
                    'cancel_deadline', CASE WHEN x.notice_period_months IS NOT NULL AND ca.next_possible_end IS NOT NULL AND NOT x.terminated
                                            THEN (ca.next_possible_end - make_interval(months => x.notice_period_months))::date END
                ) ORDER BY CASE x.status WHEN 'active' THEN 0 WHEN 'terminated' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END,
                           per.next_billing_date NULLS LAST, x.customer_name)
            FROM cfgx x LEFT JOIN per ON per.id = x.id LEFT JOIN cancel ca ON ca.id = x.id), '[]'::jsonb),
            'due', COALESCE((SELECT json_agg(json_build_object(
                'config_id', d.config_id, 'oe_id', d.oe_id, 'ordnumber', d.ordnumber,
                'customer_id', d.customer_id, 'customer_name', d.customer_name, 'customernumber', d.customernumber,
                'period_start', d.period_start, 'period_end', d.period_end, 'billing_date', d.billing_date,
                'factor', d.factor, 'is_partial', d.is_partial, 'amount', d.amount,
                'blocked', d.blocked, 'hold_on_overdue_days', d.hold_on_overdue_days, 'send_email', d.send_email,
                'taxincluded', d.taxincluded
            ) ORDER BY d.billing_date, d.customer_name, d.period_start) FROM due d), '[]'::json),
            'upcoming', COALESCE((SELECT json_agg(json_build_object('month', month, 'invoices', invoices, 'amount', amount)) FROM upcoming), '[]'::json),
            'email_configured', EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'email_smtp_host' AND COALESCE(value, '') <> '')
        ) AS result
    SQL);

    resultInfo(true, '', ['results' => json_decode($row['result'], true)]);
}

// ───────────────────────────────────────────────────────────────────────────────
// API: Konfiguration lesen, Vorschau, speichern
// ───────────────────────────────────────────────────────────────────────────────

/**
 * Konfiguration einer wiederkehrenden Abrechnung zu einem Auftrag — mit
 * Auftrag, Kunde, Positionen (inkl. Abrechnungsart je Position), Kontakten,
 * Debitorenkonten, Druckern, Vorbelegungen und allen Perioden (Verlauf,
 * fällig, geplant). Gibt es noch keine Konfiguration, kommen Vorbelegungen.
 *
 * @param int $data['oe_id'] Auftrag
 * @param int $data['id']    Alternativ: Konfiguration
 * @testdata {"oe_id": 1}
 */
function getRecurringConfig($data) {
    permit('sales_order_edit');
    $db = DbhCompany::begin();
    $cfgCte = recurringConfigCte();

    $oeId = intval($data['oe_id'] ?? 0);
    $id   = intval($data['id'] ?? 0);
    if ($oeId <= 0 && $id <= 0) {
        throw new ApiError('VALIDATION_ERROR', 'oe_id oder id erforderlich');
    }

    $row = $db->getOne(<<<SQL
        WITH {$cfgCte},
        o AS (
            SELECT o.id, o.ordnumber, o.transdate, o.reqdate, o.customer_id, o.amount, o.netamount,
                   COALESCE(o.taxincluded, false) AS taxincluded, o.closed, o.record_type, o.transaction_description,
                   o.cp_id, o.payment_id, o.language_id, o.notes,
                   cu.name AS customer_name, cu.customernumber, cu.invoice_mail, cu.email AS customer_email,
                   cu.postal_invoice, cu.payment_id AS customer_payment_id,
                   pt.description AS payment_terms, pt.terms_netto,
                   l.template_code AS language_code,
                   cur.name AS currency
            FROM oe o
            LEFT JOIN customer cu ON cu.id = o.customer_id
            LEFT JOIN payment_terms pt ON pt.id = COALESCE(o.payment_id, cu.payment_id)
            LEFT JOIN language l ON l.id = o.language_id
            LEFT JOIN currencies cur ON cur.id = o.currency_id
            WHERE o.id = COALESCE(NULLIF(:oe_id, 0), (SELECT oe_id FROM periodic_invoices_configs WHERE id = :id))
        ),
        x AS (SELECT cfgx.* FROM cfgx JOIN o ON o.id = cfgx.oe_id)
        SELECT json_build_object(
            'exists', EXISTS (SELECT 1 FROM x),
            'order', (SELECT row_to_json(o) FROM o),
            'config', (SELECT row_to_json(x) FROM x),
            'items', COALESCE((SELECT json_agg(json_build_object(
                'id', oi.id, 'position', oi.position, 'parts_id', oi.parts_id, 'partnumber', p.partnumber,
                'description', oi.description, 'longdescription', oi.longdescription,
                'qty', oi.qty, 'unit', oi.unit, 'sellprice', oi.sellprice, 'discount', oi.discount,
                'current_price', COALESCE(pr.price, p.sellprice),
                'line_total', ROUND((oi.qty * oi.sellprice * (1 - COALESCE(oi.discount, 0)))::numeric, 2),
                'recurring_billing_mode', oi.recurring_billing_mode,
                'recurring_billing_invoice_id', oi.recurring_billing_invoice_id,
                'billed_invnumber', bar.invnumber
            ) ORDER BY oi.position)
            FROM orderitems oi
            JOIN o ON o.id = oi.trans_id
            JOIN parts p ON p.id = oi.parts_id
            LEFT JOIN customer cu ON cu.id = o.customer_id
            LEFT JOIN prices pr ON pr.parts_id = oi.parts_id AND pr.pricegroup_id = cu.pricegroup_id
            LEFT JOIN ar bar ON bar.id = oi.recurring_billing_invoice_id), '[]'::json),
            'contacts', COALESCE((SELECT json_agg(json_build_object(
                'cp_id', c.cp_id, 'name', TRIM(CONCAT_WS(' ', c.cp_givenname, c.cp_name)), 'email', c.cp_email
            ) ORDER BY c.cp_name) FROM contacts c JOIN o ON c.cp_cv_id = o.customer_id), '[]'::json),
            'ar_charts', COALESCE((SELECT json_agg(json_build_object('id', ch.id, 'accno', ch.accno, 'description', ch.description) ORDER BY ch.accno)
                FROM chart ch WHERE ch.link = 'AR'), '[]'::json),
            'printers', COALESCE((SELECT json_agg(json_build_object('id', pr.id, 'description', pr.printer_description) ORDER BY pr.printer_description)
                FROM printers pr WHERE COALESCE(pr.printer_command, '') <> ''), '[]'::json),
            'defaults', json_build_object(
                'email_subject', (SELECT value FROM defaults_oserp WHERE key = 'recurring_email_subject'),
                'email_body',    (SELECT value FROM defaults_oserp WHERE key = 'recurring_email_body'),
                'email_configured', EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'email_smtp_host' AND COALESCE(value, '') <> ''),
                'email_from', (SELECT value FROM defaults_oserp WHERE key = 'email_address'),
                -- WhatsApp: Business-API eingerichtet, Vorgabe-Template fuer Belege, genehmigte Dokument-Templates
                'whatsapp_configured', EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'whatsapp_access_token' AND COALESCE(value, '') <> '')
                                        AND EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'whatsapp_phone_number_id' AND COALESCE(value, '') <> ''),
                'whatsapp_template_id', NULLIF((SELECT value FROM defaults_oserp WHERE key = 'whatsapp_tpl_faktura'), '')::int,
                'whatsapp_templates', COALESCE((SELECT json_agg(json_build_object('id', wt.id, 'name', COALESCE(wt.display_name, wt.name)) ORDER BY wt.display_name)
                    FROM whatsapp_templates wt WHERE wt.status = 'approved' AND wt.template_type = 'document'), '[]'::json)
            ),
            -- Rufnummern des Kunden fuer den WhatsApp-Versand (Mobil zuerst)
            'phones', COALESCE((SELECT json_agg(json_build_object('number', p.number, 'label', p.label) ORDER BY p.prio, p.number)
                FROM (
                    SELECT pn->>'number' AS number, COALESCE(pn->>'label', pn->>'type', '') AS label,
                           CASE WHEN LOWER(COALESCE(pn->>'type', pn->>'label', '')) LIKE '%mobil%' OR LOWER(COALESCE(pn->>'type', '')) LIKE '%handy%' THEN 0 ELSE 2 END AS prio
                    FROM o JOIN customer_ext ce ON ce.customer_id = o.customer_id
                    CROSS JOIN LATERAL jsonb_array_elements(COALESCE(ce.phone_numbers, '[]'::jsonb)) pn
                    WHERE COALESCE(pn->>'number', '') <> ''
                    UNION ALL
                    SELECT ct.cp_mobile1, TRIM(CONCAT_WS(' ', ct.cp_givenname, ct.cp_name)), 1 FROM contacts ct JOIN o ON ct.cp_cv_id = o.customer_id WHERE COALESCE(ct.cp_mobile1, '') <> ''
                    UNION ALL
                    SELECT cu.phone, 'Telefon', 3 FROM o JOIN customer cu ON cu.id = o.customer_id WHERE COALESCE(cu.phone, '') <> ''
                ) p), '[]'::json),
            'periods', COALESCE((SELECT json_agg(row_to_json(p) ORDER BY p.n)
                FROM x CROSS JOIN LATERAL recurring_config_periods(x.id, (CURRENT_DATE + INTERVAL '18 months')::date, 2000) p), '[]'::json),
            'stats', (SELECT json_build_object(
                'invoice_count', COUNT(pi.id),
                'invoiced_total', COALESCE(SUM(a.amount), 0),
                'paid_total', COALESCE(SUM(a.paid), 0),
                'open_total', COALESCE(SUM(a.amount - a.paid), 0),
                'first_invoice_date', MIN(a.transdate),
                'last_invoice_date', MAX(a.transdate),
                'emails_sent', COUNT(pe.email_sent_at),
                'email_errors', COUNT(pe.email_error)
            ) FROM x JOIN periodic_invoices pi ON pi.config_id = x.id
              LEFT JOIN ar a ON a.id = pi.ar_id
              LEFT JOIN periodic_invoices_ext pe ON pe.periodic_invoice_id = pi.id)
        ) AS result
    SQL, [':oe_id' => $oeId, ':id' => $id]);

    $result = json_decode($row['result'], true);
    if (empty($result['order'])) {
        throw new ApiError('ORDER_NOT_FOUND', 'Auftrag nicht gefunden');
    }
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Vorschau der Perioden für eine (noch nicht gespeicherte) Konfiguration:
 * Rechnungsdaten, Zeiträume, Faktoren und voraussichtliche Beträge aus den
 * Auftragspositionen, dazu ein Beispieltext mit ersetzten Platzhaltern.
 * Rechnet mit denselben SQL-Funktionen wie die spätere Erzeugung.
 *
 * @param int    $data['oe_id']                  Auftrag (für Beträge und Belegsprache)
 * @param string $data['start_date']             Beginn (YYYY-MM-DD)
 * @param string $data['end_date']               Ende oder null
 * @param string $data['interval_unit']          day|week|month|year|once
 * @param int    $data['interval_count']         Anzahl Einheiten
 * @param bool   $data['align_to_calendar']      an Kalendergrenzen ausrichten
 * @param bool   $data['prorate_partial']        angebrochene Perioden anteilig
 * @param string $data['billing_timing']         advance|arrears
 * @param int    $data['billing_offset_days']    Versatz des Rechnungsdatums
 * @param string $data['order_value_periodicity'] p|m|q|b|y|2|3|4|5
 * @param float  $data['price_increase_percent'] jährliche Anpassung in %
 * @param int    $data['price_increase_month']   Monat der Anpassung
 * @param int    $data['extend_automatically_by'] Verlängerung in Monaten
 * @param bool   $data['terminated']             gekündigt
 * @param string $data['sample_text']            Text mit Platzhaltern
 * @param array  $data['items']                  [{id, recurring_billing_mode}] ungespeicherte Abrechnungsart je Position
 * @param int    $data['limit']                  max. Perioden (Standard 24)
 * @testdata {"oe_id": 1, "start_date": "2026-01-15", "interval_unit": "month", "interval_count": 1, "align_to_calendar": true, "prorate_partial": true, "billing_timing": "advance", "billing_offset_days": 0, "order_value_periodicity": "p", "sample_text": "Miete <%period_month%>", "limit": 12}
 */
function previewRecurringPeriods($data) {
    permit('sales_order_edit');
    $db = DbhCompany::begin();

    $start = $data['start_date'] ?? null;
    if (!$start) {
        throw new ApiError('VALIDATION_ERROR', 'start_date erforderlich');
    }
    $unit  = in_array($data['interval_unit'] ?? '', RECURRING_INTERVAL_UNITS, true) ? $data['interval_unit'] : 'month';
    $count = max(1, intval($data['interval_count'] ?? 1));
    $limit = max(1, min(120, intval($data['limit'] ?? 24)));
    $extend = intval($data['extend_automatically_by'] ?? 0);
    $terminated = !empty($data['terminated']);
    // Mit automatischer Verlängerung ist das Ende offen, solange nicht gekündigt
    $endDate = ($extend > 0 && !$terminated) ? null : ($data['end_date'] ?: null);
    $valuePer = in_array((string)($data['order_value_periodicity'] ?? 'p'), RECURRING_VALUE_PERIODICITIES, true)
        ? (string)$data['order_value_periodicity'] : 'p';
    $itemIds = []; $itemModes = [];
    foreach ((is_array($data['items'] ?? null) ? $data['items'] : []) as $it) {
        $mode = $it['recurring_billing_mode'] ?? '';
        if (intval($it['id'] ?? 0) > 0 && in_array($mode, ['always', 'once', 'never'], true)) {
            $itemIds[] = intval($it['id']);
            $itemModes[] = $mode;
        }
    }

    $row = $db->getOne(<<<SQL
        WITH modes AS (
            -- noch nicht gespeicherte Abrechnungsart je Position aus dem Dialog
            SELECT * FROM unnest(:item_ids::int[], :item_modes::text[]) AS v(id, mode)
        ),
        o AS (
            SELECT o.id, COALESCE(o.taxincluded, false) AS taxincluded, l.template_code AS language_code,
                   COALESCE(SUM(ROUND((oi.qty * oi.sellprice * (1 - COALESCE(oi.discount, 0)))::numeric, 2))
                       FILTER (WHERE COALESCE(m.mode, oi.recurring_billing_mode::text) = 'always'), 0) AS recurring_total,
                   COALESCE(SUM(ROUND((oi.qty * oi.sellprice * (1 - COALESCE(oi.discount, 0)))::numeric, 2))
                       FILTER (WHERE COALESCE(m.mode, oi.recurring_billing_mode::text) = 'once' AND oi.recurring_billing_invoice_id IS NULL), 0) AS once_open_total
            FROM oe o
            LEFT JOIN language l ON l.id = o.language_id
            LEFT JOIN orderitems oi ON oi.trans_id = o.id
            LEFT JOIN modes m ON m.id = oi.id
            WHERE o.id = :oe_id
            GROUP BY o.id, o.taxincluded, l.template_code
        ),
        m AS (
            SELECT CASE :unit WHEN 'once' THEN NULL WHEN 'year' THEN 12 * :count
                        WHEN 'week' THEN 7 * :count / 30.436875 WHEN 'day' THEN :count / 30.436875
                        ELSE :count END::numeric AS billing_months
        ),
        v AS (
            SELECT CASE :value_per WHEN 'm' THEN 1 WHEN 'q' THEN 3 WHEN 'b' THEN 6 WHEN 'y' THEN 12
                        WHEN '2' THEN 24 WHEN '3' THEN 36 WHEN '4' THEN 48 WHEN '5' THEN 60
                        ELSE m.billing_months END::numeric AS value_months, m.billing_months
            FROM m
        ),
        f AS (
            SELECT CASE WHEN billing_months IS NULL OR value_months IS NULL OR value_months = 0 THEN 1
                        ELSE billing_months / value_months END AS value_factor, billing_months
            FROM v
        ),
        p AS (
            SELECT p.*, recurring_index_factor(:pct::numeric, :pct_month::int, :start::date, p.period_start) AS index_factor
            FROM recurring_invoice_periods(:start::date, :end_date::date, :unit, :count, :align, :prorate, :timing, :offset,
                                           DATE '2999-12-31', :limit) p
        )
        SELECT json_build_object(
            'periods', COALESCE((SELECT json_agg(json_build_object(
                'n', p.n, 'period_start', p.period_start, 'period_end', p.period_end, 'billing_date', p.billing_date,
                'factor', p.factor, 'is_partial', p.is_partial, 'index_factor', ROUND(p.index_factor, 6),
                'amount', ROUND((o.recurring_total * f.value_factor * p.factor * p.index_factor
                                 + CASE WHEN p.n = 1 THEN o.once_open_total ELSE 0 END)::numeric, 2),
                'state', CASE WHEN p.billing_date <= CURRENT_DATE THEN 'due' ELSE 'planned' END
            ) ORDER BY p.n) FROM p CROSS JOIN o CROSS JOIN f), '[]'::json),
            'period_amount', (SELECT ROUND((o.recurring_total * f.value_factor)::numeric, 2) FROM o CROSS JOIN f),
            'monthly_amount', (SELECT CASE WHEN f.billing_months IS NULL OR f.billing_months = 0 THEN 0
                                           ELSE ROUND((o.recurring_total * f.value_factor / f.billing_months)::numeric, 2) END FROM o CROSS JOIN f),
            'once_open_total', (SELECT once_open_total FROM o),
            'taxincluded', (SELECT taxincluded FROM o),
            'sample', (SELECT recurring_fill_placeholders(:sample, p.period_start, p.period_end, o.language_code)
                       FROM p CROSS JOIN o ORDER BY p.n LIMIT 1)
        ) AS result
    SQL, [
        ':oe_id'     => intval($data['oe_id'] ?? 0),
        ':unit'      => $unit,
        ':count'     => $count,
        ':value_per' => $valuePer,
        ':pct'       => isset($data['price_increase_percent']) && $data['price_increase_percent'] !== '' ? floatval($data['price_increase_percent']) : null,
        ':pct_month' => !empty($data['price_increase_month']) ? intval($data['price_increase_month']) : null,
        ':start'     => $start,
        ':end_date'  => $endDate,
        ':align'     => !empty($data['align_to_calendar']) ? 'true' : 'false',
        ':prorate'   => !empty($data['prorate_partial']) ? 'true' : 'false',
        ':timing'    => in_array($data['billing_timing'] ?? '', RECURRING_BILLING_TIMINGS, true) ? $data['billing_timing'] : 'advance',
        ':offset'    => intval($data['billing_offset_days'] ?? 0),
        ':limit'     => $limit,
        ':sample'    => (string)($data['sample_text'] ?? ''),
        ':item_ids'  => '{' . implode(',', $itemIds) . '}',
        ':item_modes'=> '{' . implode(',', $itemModes) . '}',
    ]);

    resultInfo(true, '', ['results' => json_decode($row['result'], true)]);
}

/**
 * Speichert die wiederkehrende Abrechnung eines Auftrags (anlegen oder
 * ändern) samt Erweiterung und der Abrechnungsart je Position — in einer
 * Transaktion. Die kivitendo-Spalten werden so gefüllt, dass kivitendo die
 * Konfiguration weiterhin versteht (periodicity aus Einheit und Anzahl).
 *
 * @param array $data['config'] Felder aus getRecurringConfig().config (oe_id Pflicht)
 * @param array $data['items']  [{id, recurring_billing_mode}] Abrechnungsart je Position
 * @testdata {"config": {"oe_id": 1, "start_date": "2026-01-01", "interval_unit": "month", "interval_count": 1, "align_to_calendar": true, "prorate_partial": true, "billing_timing": "advance", "billing_offset_days": 0, "order_value_periodicity": "p", "extend_automatically_by": 12, "send_email": false, "active": true}, "items": []}
 */
function saveRecurringConfig($data) {
    permit('sales_order_edit');
    $db = DbhCompany::begin();
    $employeeId = mitarbeiterId($data);

    $c = $data['config'] ?? [];
    $oeId = intval($c['oe_id'] ?? 0);
    if ($oeId <= 0) {
        throw new ApiError('VALIDATION_ERROR', 'oe_id erforderlich');
    }
    if (empty($c['start_date'])) {
        throw new ApiError('VALIDATION_ERROR', 'start_date erforderlich');
    }
    if (!empty($c['end_date']) && $c['end_date'] < $c['start_date']) {
        throw new ApiError('VALIDATION_ERROR', 'end_date liegt vor start_date');
    }

    $order = $db->getOne(
        "SELECT id, customer_id, record_type FROM oe WHERE id = :id",
        [':id' => $oeId]
    );
    if (!$order) {
        throw new ApiError('ORDER_NOT_FOUND', 'Auftrag nicht gefunden');
    }
    if (empty($order['customer_id'])) {
        throw new ApiError('NO_CUSTOMER', 'Der Auftrag hat keinen Kunden');
    }

    $unit  = in_array($c['interval_unit'] ?? '', RECURRING_INTERVAL_UNITS, true) ? $c['interval_unit'] : 'month';
    $count = max(1, intval($c['interval_count'] ?? 1));
    $valuePer = in_array((string)($c['order_value_periodicity'] ?? 'p'), RECURRING_VALUE_PERIODICITIES, true)
        ? (string)$c['order_value_periodicity'] : 'p';
    $timing = in_array($c['billing_timing'] ?? '', RECURRING_BILLING_TIMINGS, true) ? $c['billing_timing'] : 'advance';
    $priceMode = in_array($c['price_mode'] ?? '', RECURRING_PRICE_MODES, true) ? $c['price_mode'] : 'fixed';
    $pctMonth = !empty($c['price_increase_month']) ? max(1, min(12, intval($c['price_increase_month']))) : null;
    $pct = (isset($c['price_increase_percent']) && $c['price_increase_percent'] !== '' && $c['price_increase_percent'] !== null)
        ? floatval($c['price_increase_percent']) : null;
    if ($pct !== null && $pctMonth === null) $pctMonth = intval(substr($c['start_date'], 5, 2)) ?: 1;

    $bool = fn($v) => !empty($v) && $v !== 'false' && $v !== 'f' ? 'true' : 'false';
    $intOrNull = fn($v) => ($v === null || $v === '') ? null : intval($v);

    // Debitorenkonto: gewählt oder das erste Forderungskonto (kivitendo verlangt eines)
    $arChartId = $intOrNull($c['ar_chart_id'] ?? null);
    if (!$arChartId) {
        $chart = $db->getOne("SELECT id FROM chart WHERE link = 'AR' ORDER BY accno LIMIT 1");
        if (!$chart) {
            throw new ApiError('NO_AR_CHART', 'Kein Forderungskonto (AR) im Kontenrahmen');
        }
        $arChartId = intval($chart['id']);
    }

    $configParams = [
        ':periodicity'  => recurringPeriodicityCode($unit, $count),
        ':value_per'    => $valuePer,
        ':start_date'   => $c['start_date'],
        ':end_date'     => $c['end_date'] ?: null,
        ':active'       => $bool($c['active'] ?? true),
        ':terminated'   => $bool($c['terminated'] ?? false),
        ':extend'       => $intOrNull($c['extend_automatically_by'] ?? null) ?: null,
        ':ar_chart_id'  => $arChartId,
        ':direct_debit' => $bool($c['direct_debit'] ?? false),
        ':send_email'   => $bool($c['send_email'] ?? false),
        ':contact_id'   => $intOrNull($c['email_recipient_contact_id'] ?? null) ?: null,
        ':recipients'   => trim((string)($c['email_recipient_address'] ?? '')) ?: null,
        ':sender'       => trim((string)($c['email_sender'] ?? '')) ?: null,
        ':subject'      => $c['email_subject'] ?? null,
        ':body'         => $c['email_body'] ?? null,
        ':print'        => $bool($c['print'] ?? false),
        ':printer_id'   => $intOrNull($c['printer_id'] ?? null) ?: null,
        ':copies'       => max(1, intval($c['copies'] ?? 1)),
    ];
    $extParams = [
        ':interval_unit'  => $unit,
        ':interval_count' => $count,
        ':align'          => $bool($c['align_to_calendar'] ?? false),
        ':prorate'        => $bool($c['prorate_partial'] ?? true),
        ':timing'         => $timing,
        ':offset'         => intval($c['billing_offset_days'] ?? 0),
        ':price_mode'     => $priceMode,
        ':pct'            => $pct,
        ':pct_month'      => $pct !== null ? $pctMonth : null,
        ':hold_days'      => $intOrNull($c['hold_on_overdue_days'] ?? null),
        ':notice'         => $intOrNull($c['notice_period_months'] ?? null),
        ':min_term'       => $intOrNull($c['min_term_months'] ?? null),
        ':paused_until'   => $c['paused_until'] ?: null,
        ':post'           => $bool($c['post_to_ledger'] ?? true),
        ':notes'          => trim((string)($c['notes'] ?? '')) ?: null,
    ];

    $db->beginTransaction();
    try {
        $existing = $db->getOne("SELECT id FROM periodic_invoices_configs WHERE oe_id = :oe ORDER BY id LIMIT 1", [':oe' => $oeId]);

        if ($existing) {
            $configId = intval($existing['id']);
            $db->execute(<<<SQL
                UPDATE periodic_invoices_configs SET
                    periodicity = :periodicity, order_value_periodicity = :value_per,
                    start_date = :start_date, end_date = :end_date, first_billing_date = NULL,
                    active = :active, terminated = :terminated, extend_automatically_by = :extend,
                    ar_chart_id = :ar_chart_id, direct_debit = :direct_debit,
                    send_email = :send_email, email_recipient_contact_id = :contact_id,
                    email_recipient_address = :recipients, email_sender = :sender,
                    email_subject = :subject, email_body = :body,
                    print = :print, printer_id = :printer_id, copies = :copies
                WHERE id = :id
            SQL, $configParams + [':id' => $configId]);
        } else {
            $new = $db->getOne(<<<SQL
                INSERT INTO periodic_invoices_configs (
                    oe_id, periodicity, order_value_periodicity, start_date, end_date, active, terminated,
                    extend_automatically_by, ar_chart_id, direct_debit, send_email, email_recipient_contact_id,
                    email_recipient_address, email_sender, email_subject, email_body, print, printer_id, copies
                ) VALUES (
                    :oe_id, :periodicity, :value_per, :start_date, :end_date, :active, :terminated,
                    :extend, :ar_chart_id, :direct_debit, :send_email, :contact_id,
                    :recipients, :sender, :subject, :body, :print, :printer_id, :copies
                ) RETURNING id
            SQL, $configParams + [':oe_id' => $oeId]);
            $configId = intval($new['id']);
        }

        $db->execute(<<<SQL
            INSERT INTO periodic_invoices_configs_ext (
                config_id, interval_unit, interval_count, align_to_calendar, prorate_partial, billing_timing,
                billing_offset_days, price_mode, price_increase_percent, price_increase_month, hold_on_overdue_days,
                notice_period_months, min_term_months, paused_until, post_to_ledger, notes, created_by,
                send_whatsapp, whatsapp_phone, whatsapp_template_id
            ) VALUES (
                :config_id, :interval_unit, :interval_count, :align, :prorate, :timing,
                :offset, :price_mode, :pct, :pct_month, :hold_days,
                :notice, :min_term, :paused_until, :post, :notes, :employee_id,
                :send_whatsapp, :whatsapp_phone, :whatsapp_template_id
            )
            ON CONFLICT (config_id) DO UPDATE SET
                interval_unit = EXCLUDED.interval_unit, interval_count = EXCLUDED.interval_count,
                align_to_calendar = EXCLUDED.align_to_calendar, prorate_partial = EXCLUDED.prorate_partial,
                billing_timing = EXCLUDED.billing_timing, billing_offset_days = EXCLUDED.billing_offset_days,
                price_mode = EXCLUDED.price_mode, price_increase_percent = EXCLUDED.price_increase_percent,
                price_increase_month = EXCLUDED.price_increase_month, hold_on_overdue_days = EXCLUDED.hold_on_overdue_days,
                notice_period_months = EXCLUDED.notice_period_months, min_term_months = EXCLUDED.min_term_months,
                paused_until = EXCLUDED.paused_until, post_to_ledger = EXCLUDED.post_to_ledger,
                notes = EXCLUDED.notes, send_whatsapp = EXCLUDED.send_whatsapp, whatsapp_phone = EXCLUDED.whatsapp_phone,
                whatsapp_template_id = EXCLUDED.whatsapp_template_id, mtime = now()
        SQL, $extParams + [
            ':config_id' => $configId, ':employee_id' => $employeeId,
            ':send_whatsapp' => $bool($c['send_whatsapp'] ?? false),
            ':whatsapp_phone' => trim((string)($c['whatsapp_phone'] ?? '')) ?: null,
            ':whatsapp_template_id' => $intOrNull($c['whatsapp_template_id'] ?? null),
        ]);

        // Abrechnungsart je Position (kivitendo-Spalte orderitems.recurring_billing_mode)
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $ids = []; $modes = [];
        foreach ($items as $it) {
            $mode = $it['recurring_billing_mode'] ?? 'always';
            if (!in_array($mode, ['always', 'once', 'never'], true) || intval($it['id'] ?? 0) <= 0) continue;
            $ids[] = intval($it['id']);
            $modes[] = $mode;
        }
        if ($ids) {
            $db->execute(<<<SQL
                UPDATE orderitems oi
                SET recurring_billing_mode = v.mode::items_recurring_billing_mode
                FROM unnest(:ids::int[], :modes::text[]) AS v(id, mode)
                WHERE oi.id = v.id AND oi.trans_id = :oe_id
            SQL, [':ids' => '{' . implode(',', $ids) . '}', ':modes' => '{' . implode(',', $modes) . '}', ':oe_id' => $oeId]);
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    resultInfo(true, 'SAVED', ['id' => $configId]);
}

/**
 * Zustand einer Abrechnung ändern: aktivieren, pausieren (optional bis zu
 * einem Datum, danach automatisch weiter), kündigen (Ende setzen) oder die
 * Kündigung zurücknehmen.
 *
 * @param int    $data['id']           Konfiguration
 * @param string $data['status']       active | paused | terminated | reactivate
 * @param string $data['paused_until'] bei paused: Datum, bis zu dem pausiert wird (optional)
 * @param string $data['end_date']     bei terminated: letzter Tag der Laufzeit
 * @testdata {"id": 1, "status": "paused", "paused_until": "2026-12-31"}
 */
function setRecurringStatus($data) {
    permit('sales_order_edit');
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $status = $data['status'] ?? '';
    if ($id <= 0 || !in_array($status, ['active', 'paused', 'terminated', 'reactivate'], true)) {
        throw new ApiError('VALIDATION_ERROR', 'id und status (active|paused|terminated|reactivate) erforderlich');
    }

    $db->beginTransaction();
    try {
        // _ext-Zeile sicherstellen (Konfigurationen aus kivitendo haben noch keine)
        $db->execute("INSERT INTO periodic_invoices_configs_ext (config_id) VALUES (:id) ON CONFLICT (config_id) DO NOTHING", [':id' => $id]);

        if ($status === 'active') {
            $db->execute("UPDATE periodic_invoices_configs SET active = true WHERE id = :id", [':id' => $id]);
            $db->execute("UPDATE periodic_invoices_configs_ext SET paused_until = NULL, mtime = now() WHERE config_id = :id", [':id' => $id]);
        } elseif ($status === 'paused') {
            $db->execute("UPDATE periodic_invoices_configs SET active = false WHERE id = :id", [':id' => $id]);
            $db->execute("UPDATE periodic_invoices_configs_ext SET paused_until = :until, mtime = now() WHERE config_id = :id",
                [':id' => $id, ':until' => $data['paused_until'] ?: null]);
        } elseif ($status === 'terminated') {
            if (empty($data['end_date'])) {
                throw new ApiError('VALIDATION_ERROR', 'end_date erforderlich');
            }
            $db->execute("UPDATE periodic_invoices_configs SET terminated = true, end_date = :end_date WHERE id = :id",
                [':id' => $id, ':end_date' => $data['end_date']]);
        } else { // reactivate: Kündigung zurücknehmen
            $db->execute("UPDATE periodic_invoices_configs SET terminated = false, active = true WHERE id = :id", [':id' => $id]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    resultInfo(true, 'SAVED', ['id' => $id]);
}

/**
 * Löscht eine wiederkehrende Abrechnung. Bereits erzeugte Rechnungen bleiben
 * erhalten (nur die Verknüpfung in periodic_invoices verschwindet per Cascade).
 *
 * @param int $data['id'] Konfiguration
 * @testdata {"id": 1}
 */
function deleteRecurringConfig($data) {
    permit('sales_order_edit');
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        throw new ApiError('VALIDATION_ERROR', 'id erforderlich');
    }
    $db->execute("DELETE FROM periodic_invoices_configs WHERE id = :id", [':id' => $id]);
    resultInfo(true, 'DELETED', ['id' => $id]);
}

/**
 * Eine Periode bewusst überspringen (gilt als erledigt, wird nicht nachgeholt)
 * oder das Überspringen zurücknehmen.
 *
 * @param int    $data['config_id']    Konfiguration
 * @param string $data['period_start'] Periodenbeginn (YYYY-MM-DD)
 * @param string $data['reason']       Grund (optional)
 * @param bool   $data['undo']         true = Überspringen zurücknehmen
 * @testdata {"config_id": 1, "period_start": "2026-03-01", "reason": "Kulanz"}
 */
function skipRecurringPeriod($data) {
    permit('invoice_edit');
    $db = DbhCompany::begin();
    $configId = intval($data['config_id'] ?? 0);
    $periodStart = $data['period_start'] ?? '';
    if ($configId <= 0 || !$periodStart) {
        throw new ApiError('VALIDATION_ERROR', 'config_id und period_start erforderlich');
    }
    if (!empty($data['undo'])) {
        $db->execute("DELETE FROM periodic_invoices_skips WHERE config_id = :c AND period_start_date = :p",
            [':c' => $configId, ':p' => $periodStart]);
        resultInfo(true, 'UNSKIPPED');
        return;
    }
    $db->execute(<<<SQL
        INSERT INTO periodic_invoices_skips (config_id, period_start_date, reason, employee_id)
        VALUES (:c, :p, :reason, :emp)
        ON CONFLICT (config_id, period_start_date) DO UPDATE SET reason = EXCLUDED.reason, employee_id = EXCLUDED.employee_id
    SQL, [':c' => $configId, ':p' => $periodStart, ':reason' => trim((string)($data['reason'] ?? '')) ?: null, ':emp' => mitarbeiterId($data)]);
    resultInfo(true, 'SKIPPED');
}

// ───────────────────────────────────────────────────────────────────────────────
// Erzeugung
// ───────────────────────────────────────────────────────────────────────────────

/**
 * Pflege vor jedem Lauf: pausierte Abrechnungen mit erreichtem Datum wieder
 * aktivieren, abgelaufene Laufzeiten mit automatischer Verlängerung um ganze
 * Verlängerungsschritte bis über heute hinaus verlängern (wie kivitendos
 * handle_automatic_extension).
 */
function recurringMaintenance($db): void {
    $db->execute(<<<SQL
        WITH resumed AS (
            UPDATE periodic_invoices_configs c SET active = true
            FROM periodic_invoices_configs_ext e
            WHERE e.config_id = c.id AND NOT c.active AND e.paused_until IS NOT NULL AND e.paused_until <= CURRENT_DATE
            RETURNING c.id
        )
        UPDATE periodic_invoices_configs_ext e SET paused_until = NULL, mtime = now()
        FROM resumed WHERE e.config_id = resumed.id
    SQL);

    $db->execute(<<<SQL
        UPDATE periodic_invoices_configs c
        SET end_date = (SELECT MIN(d) FROM generate_series(1, 240) k,
                        LATERAL (SELECT (c.end_date + make_interval(months => k * c.extend_automatically_by))::date AS d) s
                        WHERE d >= CURRENT_DATE)
        WHERE c.active AND NOT COALESCE(c.terminated, false) AND COALESCE(c.extend_automatically_by, 0) > 0
          AND c.end_date IS NOT NULL AND c.end_date < CURRENT_DATE
    SQL);
}

/**
 * Erzeugt die Rechnung einer Periode: Kopf aus dem Auftrag (Platzhalter
 * ersetzt, Fälligkeit aus den Zahlungsbedingungen, Leistungszeitraum =
 * Periode), Positionen nach Abrechnungsart mit Preisfaktor (anteilige Tage ×
 * Auftragswert-Umrechnung × Preisanpassung; einmalige Positionen voll),
 * Kopfbeträge aus den Positionen, Verknüpfungen, Merkposten. Danach, außerhalb
 * der Transaktion: Hauptbuch, E-Mail, Druck — jeweils best effort und im
 * Ergebnis vermerkt.
 *
 * @param object   $db          DbhCompany-Handle
 * @param int      $configId    Konfiguration
 * @param string   $periodStart Periodenbeginn (YYYY-MM-DD)
 * @param int|null $employeeId  Mitarbeiter (NULL = Zeitsteuerung)
 * @param string   $source      manual | cron
 * @param bool     $force       auch geplante oder gesperrte Perioden erzeugen
 * @return array ['status' => created|exists|blocked|not_due|error, ...]
 */
function recurringCreateInvoice($db, int $configId, string $periodStart, ?int $employeeId, string $source = 'manual', bool $force = false): array {
    $cfgCte = recurringConfigCte();

    $db->beginTransaction();
    try {
        // Konfiguration sperren, damit zwei Läufe dieselbe Periode nicht doppelt erzeugen
        $db->getOne("SELECT id FROM periodic_invoices_configs WHERE id = :id FOR UPDATE", [':id' => $configId]);

        $cfg = $db->getOne("WITH {$cfgCte} SELECT * FROM cfgx WHERE id = :id", [':id' => $configId]);
        if (!$cfg) {
            throw new ApiError('CONFIG_NOT_FOUND', 'Abrechnung nicht gefunden');
        }

        $period = $db->getOne(<<<SQL
            SELECT * FROM recurring_config_periods(:id, (:ps::date + INTERVAL '400 days')::date, 5000)
            WHERE period_start = :ps::date
        SQL, [':id' => $configId, ':ps' => $periodStart]);
        if (!$period) {
            throw new ApiError('PERIOD_NOT_FOUND', 'Periode gehört nicht zu diesem Rhythmus');
        }
        if (in_array($period['state'], ['created', 'skipped'], true)) {
            $db->rollBack();
            return ['status' => 'exists', 'config_id' => $configId, 'period_start' => $periodStart, 'ar_id' => $period['ar_id'], 'invnumber' => $period['invnumber']];
        }
        if (!$force && $period['state'] !== 'due') {
            $db->rollBack();
            return ['status' => 'not_due', 'config_id' => $configId, 'period_start' => $periodStart];
        }
        if (!$force && ($cfg['blocked'] === true || $cfg['blocked'] === 't')) {
            $db->rollBack();
            return ['status' => 'blocked', 'config_id' => $configId, 'period_start' => $periodStart, 'customer_name' => $cfg['customer_name']];
        }
        if (!$force && $cfg['status'] !== 'active') {
            $db->rollBack();
            return ['status' => 'inactive', 'config_id' => $configId, 'period_start' => $periodStart];
        }

        $periodEnd = $period['period_end'];
        $billingDate = $period['billing_date'];

        // 1. Rechnungskopf — Nummernkreis und INSERT atomar, Kopffelder aus dem
        //    Auftrag, Platzhalter in Bemerkungen ersetzt, Fälligkeit aus den
        //    Zahlungsbedingungen (Auftrag, sonst Kunde)
        $ar = $db->getOne(<<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber),
            o AS (
                SELECT o.*, cu.payment_id AS customer_payment_id, l.template_code AS language_code
                FROM oe o
                LEFT JOIN customer cu ON cu.id = o.customer_id
                LEFT JOIN language l ON l.id = o.language_id
                WHERE o.id = :oe_id
            )
            INSERT INTO ar (
                invnumber, transdate, gldate, duedate, deliverydate, tax_point, orddate,
                employee_id, customer_id, taxzone_id, currency_id, invoice, type, taxincluded,
                notes, intnotes, payment_id, delivery_term_id, language_id, department_id,
                cusordnumber, ordnumber, quonumber, globalproject_id, salesman_id,
                shippingpoint, shipvia, transaction_description, shipto_id, cp_id,
                exchangerate, billing_address_id, direct_debit, amount, netamount, paid, storno
            )
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE,
                   CURRENT_DATE + COALESCE(pt.terms_netto, 0), :ps::date, :pe::date, o.transdate,
                   COALESCE(o.employee_id, :employee_id), o.customer_id, o.taxzone_id, o.currency_id, true, 'invoice', o.taxincluded,
                   recurring_fill_placeholders(o.notes, :ps::date, :pe::date, o.language_code),
                   recurring_fill_placeholders(o.intnotes, :ps::date, :pe::date, o.language_code),
                   COALESCE(o.payment_id, o.customer_payment_id), o.delivery_term_id, o.language_id, o.department_id,
                   o.cusordnumber, o.ordnumber, NULLIF(o.quonumber, ''), o.globalproject_id, o.salesman_id,
                   o.shippingpoint, o.shipvia,
                   recurring_fill_placeholders(o.transaction_description, :ps::date, :pe::date, o.language_code),
                   o.shipto_id, o.cp_id, o.exchangerate, o.billing_address_id, :direct_debit, 0, 0, 0, false
            FROM o
            LEFT JOIN payment_terms pt ON pt.id = COALESCE(o.payment_id, o.customer_payment_id)
            RETURNING id, invnumber
        SQL, [
            ':oe_id' => intval($cfg['oe_id']), ':ps' => $periodStart, ':pe' => $periodEnd,
            ':employee_id' => $employeeId, ':direct_debit' => ($cfg['direct_debit'] === true || $cfg['direct_debit'] === 't') ? 'true' : 'false',
        ]);
        $arId = intval($ar['id']);

        // 2. Positionen: Abrechnungsart beachten, Preis = Auftrags- oder aktueller
        //    Preis × Gesamtfaktor (einmalige Positionen ohne Faktor), Texte mit Platzhaltern
        $db->execute(<<<SQL
            WITH o AS (
                SELECT o.id, l.template_code AS language_code, cu.pricegroup_id
                FROM oe o LEFT JOIN customer cu ON cu.id = o.customer_id LEFT JOIN language l ON l.id = o.language_id
                WHERE o.id = :oe_id
            ),
            f AS (SELECT (:period_factor::numeric * :value_factor::numeric
                          * recurring_index_factor(:pct::numeric, :pct_month::int, :start_date::date, :ps::date)) AS total_factor)
            INSERT INTO invoice (
                trans_id, parts_id, description, longdescription, qty, sellprice, fxsellprice, discount, unit, position,
                project_id, serialnumber, pricegroup_id, lastcost, price_factor_id, price_factor, marge_price_factor,
                subtotal, base_qty, deliverydate, ordnumber, cusordnumber
            )
            SELECT :ar_id, oi.parts_id,
                   recurring_fill_placeholders(oi.description, :ps::date, :pe::date, o.language_code),
                   recurring_fill_placeholders(oi.longdescription, :ps::date, :pe::date, o.language_code),
                   oi.qty,
                   ROUND(((CASE WHEN :price_mode = 'current' THEN COALESCE(pr.price, p.sellprice) ELSE oi.sellprice END)
                          * CASE WHEN oi.recurring_billing_mode = 'once' THEN 1 ELSE f.total_factor END)::numeric, 2),
                   ROUND(((CASE WHEN :price_mode = 'current' THEN COALESCE(pr.price, p.sellprice) ELSE oi.sellprice END)
                          * CASE WHEN oi.recurring_billing_mode = 'once' THEN 1 ELSE f.total_factor END)::numeric, 2),
                   oi.discount, oi.unit, oi.position,
                   oi.project_id, oi.serialnumber, oi.pricegroup_id, oi.lastcost, oi.price_factor_id, oi.price_factor, oi.marge_price_factor,
                   oi.subtotal, oi.base_qty, :ps::date, :ordnumber, oi.cusordnumber
            FROM orderitems oi
            JOIN o ON o.id = oi.trans_id
            JOIN parts p ON p.id = oi.parts_id
            LEFT JOIN prices pr ON pr.parts_id = oi.parts_id AND pr.pricegroup_id = o.pricegroup_id
            CROSS JOIN f
            WHERE oi.recurring_billing_mode <> 'never'
              AND (oi.recurring_billing_mode = 'always' OR oi.recurring_billing_invoice_id IS NULL)
            ORDER BY oi.position
        SQL, [
            ':oe_id' => intval($cfg['oe_id']), ':ar_id' => $arId, ':ps' => $periodStart, ':pe' => $periodEnd,
            ':period_factor' => $period['factor'], ':value_factor' => $cfg['value_factor'],
            ':pct' => $cfg['price_increase_percent'], ':pct_month' => $cfg['price_increase_month'],
            ':start_date' => $cfg['start_date'], ':price_mode' => $cfg['price_mode'], ':ordnumber' => $cfg['ordnumber'],
        ]);

        $itemCount = $db->getOne("SELECT COUNT(*) AS n FROM invoice WHERE trans_id = :id", [':id' => $arId]);
        if (intval($itemCount['n']) === 0) {
            throw new ApiError('NO_ITEMS', 'Der Auftrag hat keine abzurechnenden Positionen');
        }

        // Einmalige Positionen als abgerechnet markieren
        $db->execute(<<<SQL
            UPDATE orderitems SET recurring_billing_invoice_id = :ar_id
            WHERE trans_id = :oe_id AND recurring_billing_mode = 'once' AND recurring_billing_invoice_id IS NULL
        SQL, [':ar_id' => $arId, ':oe_id' => intval($cfg['oe_id'])]);

        // 3. Kopfbeträge aus den Positionen — dieselben Regeln wie der Beleg-Editor
        //    (Zeilensumme auf 2 Stellen, Netto je Steuersatz, Steuer auf der Gruppensumme)
        $db->execute(<<<SQL
            WITH pos AS (
                SELECT COALESCE(a.taxincluded, FALSE) AS taxincluded, COALESCE(bz.rate, 0) AS rate,
                       ROUND((i.qty * i.sellprice * (1 - COALESCE(i.discount, 0)))::numeric, 2) AS total
                FROM invoice i
                JOIN ar a ON a.id = i.trans_id
                JOIN parts p ON p.id = i.parts_id
                LEFT JOIN LATERAL (
                    SELECT tx.rate
                    FROM buchungsgruppen bg
                    JOIN taxzone_charts tc ON tc.buchungsgruppen_id = bg.id AND tc.taxzone_id = a.taxzone_id
                    JOIN chart c2 ON c2.id = tc.income_accno_id
                    LEFT JOIN taxkeys tk ON tk.chart_id = c2.id AND tk.startdate <= a.transdate
                    LEFT JOIN tax tx ON tx.id = tk.tax_id
                    WHERE bg.id = p.buchungsgruppen_id
                    ORDER BY tk.startdate DESC NULLS LAST
                    LIMIT 1
                ) bz ON TRUE
                WHERE i.trans_id = :ar_id
            ), grp AS (
                SELECT taxincluded, rate, SUM(total) AS gross_base,
                       SUM(CASE WHEN taxincluded AND rate <> 0 THEN ROUND(total / (1 + rate), 2) ELSE total END) AS net_base
                FROM pos GROUP BY taxincluded, rate
            ), sums AS (
                SELECT bool_or(taxincluded) AS taxincluded, COALESCE(SUM(gross_base), 0) AS gross_base,
                       COALESCE(SUM(net_base), 0) AS net_base, COALESCE(SUM(ROUND(net_base * rate, 2)), 0) AS tax
                FROM grp
            )
            UPDATE ar SET
                netamount = CASE WHEN sums.taxincluded THEN sums.gross_base - sums.tax ELSE sums.net_base END,
                amount    = CASE WHEN sums.taxincluded THEN sums.gross_base ELSE sums.net_base + sums.tax END
            FROM sums WHERE ar.id = :ar_id2
        SQL, [':ar_id' => $arId, ':ar_id2' => $arId]);

        // 4. Verknüpfungen und Merkposten
        $db->execute("INSERT INTO record_links (from_table, from_id, to_table, to_id) VALUES ('oe', :oe_id, 'ar', :ar_id)",
            [':oe_id' => intval($cfg['oe_id']), ':ar_id' => $arId]);
        $pi = $db->getOne("INSERT INTO periodic_invoices (config_id, ar_id, period_start_date) VALUES (:c, :ar, :ps) RETURNING id",
            [':c' => $configId, ':ar' => $arId, ':ps' => $periodStart]);
        $piId = intval($pi['id']);
        $db->execute(<<<SQL
            INSERT INTO periodic_invoices_ext (periodic_invoice_id, period_end_date, billing_date, factor, created_by, source)
            VALUES (:pi, :pe, :bd, :factor, :emp, :source)
        SQL, [':pi' => $piId, ':pe' => $periodEnd, ':bd' => $billingDate,
              ':factor' => round(floatval($period['factor']) * floatval($cfg['value_factor']), 6), ':emp' => $employeeId, ':source' => $source]);

        // 5. LxCars: Fahrzeug der Rechnung mitgeben, wenn der Auftrag eines hat.
        //    Direkt geprüft statt existingTables(): im Cron laufen mehrere
        //    Mandanten in einem Prozess, dessen Zwischenspeicher wäre falsch.
        $lx = $db->getOne("SELECT to_regclass('public.oe_ext') IS NOT NULL AND to_regclass('public.ar_ext') IS NOT NULL AS ok");
        if ($lx && ($lx['ok'] === true || $lx['ok'] === 't')) {
            $db->execute(<<<SQL
                INSERT INTO ar_ext (ar_id, c_id, km_stand)
                SELECT :ar_id, e.c_id, e.km_stand FROM oe_ext e WHERE e.oe_id = :oe_id AND e.c_id IS NOT NULL
                ON CONFLICT (ar_id) DO NOTHING
            SQL, [':ar_id' => $arId, ':oe_id' => intval($cfg['oe_id'])]);
        }

        // 6. Einmalige Abrechnung: danach inaktiv, Auftrag geschlossen (wie kivitendo)
        if ($cfg['interval_unit'] === 'once') {
            $db->execute("UPDATE periodic_invoices_configs SET active = false WHERE id = :id", [':id' => $configId]);
            $db->execute("UPDATE oe SET closed = true WHERE id = :id", [':id' => intval($cfg['oe_id'])]);
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        writeLog("recurringCreateInvoice: Konfiguration {$configId}, Periode {$periodStart}: " . $e->getMessage(), true, DLOG_ERR);
        return ['status' => 'error', 'config_id' => $configId, 'period_start' => $periodStart, 'message' => $e->getMessage()];
    }

    $result = [
        'status' => 'created', 'config_id' => $configId, 'period_start' => $periodStart, 'period_end' => $periodEnd,
        'ar_id' => $arId, 'invnumber' => $ar['invnumber'], 'periodic_invoice_id' => $piId,
        'customer_name' => $cfg['customer_name'], 'ordnumber' => $cfg['ordnumber'],
        'posted' => false, 'emailed' => false, 'printed' => false,
    ];

    // 7. Hauptbuch — best effort, idempotent (postArInvoiceToLedger)
    if ($cfg['post_to_ledger'] === true || $cfg['post_to_ledger'] === 't') {
        try {
            $post = postArInvoiceToLedger($db, $arId);
            $result['posted'] = !empty($post['posted']);
            $result['post_reason'] = $post['reason'] ?? null;
            $db->execute("UPDATE periodic_invoices_ext SET posted = :posted, post_error = :err, mtime = now() WHERE periodic_invoice_id = :pi",
                [':posted' => $result['posted'] ? 'true' : 'false', ':err' => $result['posted'] ? null : ($post['reason'] ?? null), ':pi' => $piId]);
        } catch (\Throwable $e) {
            writeLog("recurringCreateInvoice: Buchung AR #{$arId} fehlgeschlagen: " . $e->getMessage(), true, DLOG_ERR);
            $db->execute("UPDATE periodic_invoices_ext SET post_error = :err, mtime = now() WHERE periodic_invoice_id = :pi",
                [':err' => $e->getMessage(), ':pi' => $piId]);
        }
    }

    // 8. E-Mail
    if ($cfg['send_email'] === true || $cfg['send_email'] === 't') {
        $err = recurringSendInvoiceEmail($db, $piId, $employeeId);
        $result['emailed'] = $err === null;
        if ($err !== null) $result['email_error'] = $err;
    }

    // 8b. WhatsApp
    if ($cfg['send_whatsapp'] === true || $cfg['send_whatsapp'] === 't') {
        $err = recurringSendInvoiceWhatsApp($db, $piId, $employeeId);
        $result['whatsapped'] = $err === null;
        if ($err !== null) $result['whatsapp_error'] = $err;
    }

    // 9. Druck
    if (($cfg['print'] === true || $cfg['print'] === 't') && !empty($cfg['printer_id'])) {
        $err = recurringPrintInvoice($db, $piId, $employeeId);
        $result['printed'] = $err === null;
        if ($err !== null) $result['print_error'] = $err;
    }

    return $result;
}

/**
 * Ersetzt in E-Mail-Betreff und -Text die Perioden-Platzhalter (SQL-Funktion,
 * Belegsprache) und zusätzlich alle Spalten der Rechnung (<%invnumber%>,
 * <%amount%>, <%duedate%> …). Beträge mit zwei Nachkommastellen, Daten im
 * Format der Belegsprache.
 */
function recurringEmailText($db, ?string $text, array $info): string {
    if ($text === null || $text === '') return '';
    $row = $db->getOne("SELECT recurring_fill_placeholders(:t, :ps::date, :pe::date, :lang) AS t",
        [':t' => $text, ':ps' => $info['period_start_date'], ':pe' => $info['period_end_date'], ':lang' => $info['language_code'] ?? 'de']);
    $text = $row['t'] ?? $text;

    $isEn = str_starts_with(strtolower((string)($info['language_code'] ?? 'de')), 'en');
    $fmtDate = function ($d) use ($isEn) {
        if (!$d) return '';
        $ts = strtotime($d);
        return $ts ? date($isEn ? 'm/d/Y' : 'd.m.Y', $ts) : $d;
    };
    $fmtNum = fn($n) => $isEn ? number_format((float)$n, 2, '.', ',') : number_format((float)$n, 2, ',', '.');

    return preg_replace_callback('/(?:<%|&lt;%)\s*([a-z_]+)\s*(?:%>|%&gt;)/i', function ($m) use ($info, $fmtDate, $fmtNum) {
        $key = strtolower($m[1]);
        if (!array_key_exists($key, $info['ar'])) return $m[0];
        $v = $info['ar'][$key];
        if ($v === null) return '';
        if (in_array($key, ['amount', 'netamount', 'paid'], true)) return $fmtNum($v);
        if (in_array($key, ['transdate', 'duedate', 'deliverydate', 'tax_point', 'orddate', 'gldate'], true)) return $fmtDate($v);
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }, $text);
}

/**
 * Lädt alles, was Versand und Druck einer erzeugten Rechnung brauchen.
 */
function recurringInvoiceInfo($db, int $periodicInvoiceId): ?array {
    $row = $db->getOne(<<<SQL
        SELECT pi.id, pi.config_id, pi.ar_id, pi.period_start_date,
               COALESCE(pe.period_end_date, pi.period_start_date) AS period_end_date,
               c.send_email, c.email_recipient_contact_id, c.email_recipient_address, c.email_sender,
               c.email_subject, c.email_body, c.print, c.printer_id, c.copies,
               COALESCE(ce.send_whatsapp, false) AS send_whatsapp, ce.whatsapp_phone, ce.whatsapp_template_id,
               (SELECT value FROM defaults_oserp WHERE key = 'whatsapp_tpl_faktura') AS default_whatsapp_template_id,
               cu.id AS customer_id, cu.greeting AS customer_greeting, cu.phone AS customer_phone,
               ct.cp_mobile1 AS contact_mobile,
               (SELECT pn->>'number' FROM customer_ext cex CROSS JOIN LATERAL jsonb_array_elements(COALESCE(cex.phone_numbers, '[]'::jsonb)) pn
                 WHERE cex.customer_id = cu.id AND COALESCE(pn->>'number', '') <> ''
                 ORDER BY CASE WHEN LOWER(COALESCE(pn->>'type', pn->>'label', '')) LIKE '%mobil%' OR LOWER(COALESCE(pn->>'type', '')) LIKE '%handy%' THEN 0 ELSE 1 END LIMIT 1) AS customer_mobile,
               ct.cp_email AS contact_email, cu.invoice_mail, cu.email AS customer_email, cu.name AS customer_name,
               l.template_code AS language_code,
               (SELECT value FROM defaults_oserp WHERE key = 'recurring_email_subject') AS default_subject,
               (SELECT value FROM defaults_oserp WHERE key = 'recurring_email_body') AS default_body,
               (SELECT company FROM defaults LIMIT 1) AS company,
               (SELECT signature FROM defaults LIMIT 1) AS signature,
               (SELECT global_bcc FROM defaults LIMIT 1) AS global_bcc,
               row_to_json(a) AS ar_json
        FROM periodic_invoices pi
        JOIN periodic_invoices_configs c ON c.id = pi.config_id
        LEFT JOIN periodic_invoices_configs_ext ce ON ce.config_id = c.id
        LEFT JOIN periodic_invoices_ext pe ON pe.periodic_invoice_id = pi.id
        JOIN ar a ON a.id = pi.ar_id
        LEFT JOIN customer cu ON cu.id = a.customer_id
        LEFT JOIN contacts ct ON ct.cp_id = c.email_recipient_contact_id
        LEFT JOIN language l ON l.id = a.language_id
        WHERE pi.id = :id
    SQL, [':id' => $periodicInvoiceId]);
    if (!$row) return null;
    $row['ar'] = json_decode($row['ar_json'], true) ?: [];
    unset($row['ar_json']);
    return $row;
}

/**
 * Schickt die Rechnung einer Periode per E-Mail: PDF aus der Rechnungsvorlage,
 * Empfänger aus Konfiguration (Adressen, Kontakt) und Kundenstamm
 * (Rechnungs-E-Mail), Betreff und Text mit Platzhaltern, Kopie im
 * Gesendet-Ordner, Eintrag im E-Mail-Journal, Versandexemplar archiviert.
 *
 * @return string|null Fehlertext oder null bei Erfolg
 */
function recurringSendInvoiceEmail($db, int $periodicInvoiceId, ?int $employeeId = null): ?string {
    $info = recurringInvoiceInfo($db, $periodicInvoiceId);
    if (!$info) return 'Rechnung nicht gefunden';

    $recipients = [];
    foreach (preg_split('/[,;\s]+/', (string)($info['email_recipient_address'] ?? '')) as $e) {
        if ($e !== '') $recipients[] = strtolower(trim($e));
    }
    foreach ([$info['contact_email'], $info['invoice_mail']] as $e) {
        if (!empty($e)) $recipients[] = strtolower(trim($e));
    }
    $recipients = array_values(array_unique(array_filter($recipients, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    if (!$recipients) {
        $msg = 'Keine E-Mail-Adresse (Kontakt, Empfänger oder Rechnungs-E-Mail des Kunden)';
        $db->execute("UPDATE periodic_invoices_ext SET email_error = :err, mtime = now() WHERE periodic_invoice_id = :pi", [':err' => $msg, ':pi' => $periodicInvoiceId]);
        return $msg;
    }

    $pdfError = null;
    $rendered = renderDocumentPdfFile($db, intval($info['ar_id']), 'invoice', null, isLxCarsEnabled($db), $pdfError);
    if ($rendered === false) {
        $msg = 'PDF: ' . ($pdfError ?: 'Erzeugung fehlgeschlagen');
        $db->execute("UPDATE periodic_invoices_ext SET email_error = :err, mtime = now() WHERE periodic_invoice_id = :pi", [':err' => $msg, ':pi' => $periodicInvoiceId]);
        return $msg;
    }
    $pdf = (string)@file_get_contents($rendered['path']);
    $filename = $rendered['filename'] ?: ('Rechnung_' . $info['ar']['invnumber'] . '.pdf');
    $rendered['engine']->cleanup($rendered['path']);

    try {
        $config = _getEmailConfig();
        $smtp   = _createSmtpClient($config);
        $from   = trim((string)($info['email_sender'] ?? '')) ?: ($config['email_address'] ?? $config['email_username'] ?? '');

        $subject = recurringEmailText($db, $info['email_subject'] ?: $info['default_subject'], $info);
        $body    = recurringEmailText($db, $info['email_body'] ?: $info['default_body'], $info);
        if (!empty($info['signature'])) {
            $body .= '<p>' . nl2br(htmlspecialchars($info['signature'], ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        $toList = array_map(fn($e) => ['email' => $e, 'name' => ''], $recipients);
        $bccList = !empty($info['global_bcc']) ? [['email' => trim($info['global_bcc']), 'name' => '']] : [];
        $attachments = [['filename' => $filename, 'content_base64' => base64_encode($pdf), 'content_type' => 'application/pdf']];

        $raw = $smtp->send($from, (string)($info['company'] ?? ''), $toList, $subject, $body, '', [], $bccList, $attachments);

        try {
            $imap = _createImapClient($config);
            $sent = $imap->findSentFolder();
            if ($sent) $imap->appendToFolder($sent, $raw);
            $imap->disconnect();
        } catch (\Throwable $e) {
            // Kopie im Gesendet-Ordner ist Komfort, kein Muss
        }
        try {
            _logToEmailJournal($from, $toList, [], $subject, $body, $attachments, 'invoice');
        } catch (\Throwable $e) {
        }
        try {
            ausgangsrechnungArchivieren($db, intval($info['ar_id']), 'invoice', $pdf, $filename, $employeeId);
        } catch (\Throwable $e) {
        }

        $db->execute(<<<SQL
            UPDATE periodic_invoices_ext SET email_to = :to, email_sent_at = now(), email_error = NULL, mtime = now()
            WHERE periodic_invoice_id = :pi
        SQL, [':to' => implode(', ', $recipients), ':pi' => $periodicInvoiceId]);
        return null;
    } catch (\Throwable $e) {
        $db->execute("UPDATE periodic_invoices_ext SET email_error = :err, mtime = now() WHERE periodic_invoice_id = :pi",
            [':err' => $e->getMessage(), ':pi' => $periodicInvoiceId]);
        return $e->getMessage();
    }
}

/**
 * Schickt die Rechnung einer Periode per WhatsApp: PDF aus der Rechnungsvorlage
 * als Dokument-Template (whatsapp_tpl_faktura oder Template der Abrechnung) an
 * die Nummer der Abrechnung, sonst an die Mobilnummer des Kunden bzw. des
 * Ansprechpartners. Platzhalter wie im Rechnungsdialog: Anrede, "Ihre Rechnung
 * Nr. …", Betrag. Nutzt denselben Versandweg wie der manuelle Versand
 * (sendWhatsAppDocument), Ergebnis in periodic_invoices_ext.
 *
 * @return string|null Fehlertext oder null bei Erfolg
 */
function recurringSendInvoiceWhatsApp($db, int $periodicInvoiceId, ?int $employeeId = null): ?string {
    $info = recurringInvoiceInfo($db, $periodicInvoiceId);
    if (!$info) return 'Rechnung nicht gefunden';
    $fail = function (string $msg) use ($db, $periodicInvoiceId) {
        $db->execute("UPDATE periodic_invoices_ext SET whatsapp_error = :err, mtime = now() WHERE periodic_invoice_id = :pi", [':err' => $msg, ':pi' => $periodicInvoiceId]);
        return $msg;
    };

    $to = trim((string)($info['whatsapp_phone'] ?? '')) ?: trim((string)($info['customer_mobile'] ?? ''))
        ?: trim((string)($info['contact_mobile'] ?? '')) ?: trim((string)($info['customer_phone'] ?? ''));
    if ($to === '') return $fail('Keine Mobilnummer (Abrechnung, Kunde oder Ansprechpartner)');

    $templateId = intval($info['whatsapp_template_id'] ?? 0) ?: intval($info['default_whatsapp_template_id'] ?? 0);
    if ($templateId <= 0) {
        $t = $db->getOne("SELECT id FROM whatsapp_templates WHERE status = 'approved' AND template_type = 'document' ORDER BY id LIMIT 1");
        $templateId = intval($t['id'] ?? 0);
    }
    if ($templateId <= 0) return $fail('Kein genehmigtes WhatsApp-Dokument-Template (Firmenkonfiguration → WhatsApp)');

    $pdfError = null;
    $rendered = renderDocumentPdfFile($db, intval($info['ar_id']), 'invoice', null, isLxCarsEnabled($db), $pdfError);
    if ($rendered === false) return $fail('PDF: ' . ($pdfError ?: 'Erzeugung fehlgeschlagen'));
    $pdf = (string)@file_get_contents($rendered['path']);
    $rendered['engine']->cleanup($rendered['path']);
    $invnumber = (string)($info['ar']['invnumber'] ?? '');
    $company   = preg_replace('/\s+/', '_', (string)($info['company'] ?? 'Rechnung'));
    $filename  = $company . '-Rechnung-' . $invnumber . '.pdf';

    // Platzhalter wie im Rechnungsdialog: {{1}} Anrede, {{2}} Beleg, {{3}} Betrag
    $greeting   = trim((string)($info['customer_greeting'] ?? ''));
    $salutation = trim($greeting . ' ' . (string)($info['customer_name'] ?? ''));
    $amount     = number_format(floatval($info['ar']['amount'] ?? 0), 2, ',', '.');
    $tpl = $db->getOne("SELECT body_text FROM whatsapp_templates WHERE id = :id", [':id' => $templateId]);
    preg_match_all('/\{\{\d+\}\}/', (string)($tpl['body_text'] ?? ''), $m);
    $placeholders = array_values(array_unique($m[0] ?? []));
    $values = ['{{1}}' => $salutation, '{{2}}' => 'Ihre Rechnung Nr. ' . $invnumber, '{{3}}' => $amount];
    $parameters = array_map(fn($ph) => $values[$ph] ?? '', $placeholders);

    require_once __DIR__ . '/../whatsapp/whatsapp.php';
    ob_start();
    try {
        sendWhatsAppDocument([
            'to' => $to, 'customer_id' => intval($info['customer_id'] ?? 0), 'document_base64' => base64_encode($pdf),
            'filename' => $filename, 'template_id' => $templateId, 'parameters' => $parameters, 'employee_id' => $employeeId,
        ]);
        $res = json_decode(ob_get_clean(), true);
    } catch (\Throwable $e) {
        ob_end_clean();
        return $fail($e->getMessage());
    }
    if (empty($res['success'])) return $fail((string)($res['text'] ?? 'WhatsApp-Versand fehlgeschlagen'));

    try { ausgangsrechnungArchivieren($db, intval($info['ar_id']), 'invoice', $pdf, $filename, $employeeId); } catch (\Throwable $e) {}
    $db->execute("UPDATE periodic_invoices_ext SET whatsapp_to = :to, whatsapp_sent_at = now(), whatsapp_error = NULL, mtime = now() WHERE periodic_invoice_id = :pi",
        [':to' => $to, ':pi' => $periodicInvoiceId]);
    return null;
}

/**
 * Druckt die Rechnung einer Periode auf dem konfigurierten Drucker
 * (printers.printer_command, Anzahl Exemplare aus der Konfiguration).
 *
 * @return string|null Fehlertext oder null bei Erfolg
 */
function recurringPrintInvoice($db, int $periodicInvoiceId, ?int $employeeId = null): ?string {
    $info = recurringInvoiceInfo($db, $periodicInvoiceId);
    if (!$info) return 'Rechnung nicht gefunden';

    $printer = $db->getOne("SELECT id, printer_description, printer_command FROM printers WHERE id = :id", [':id' => intval($info['printer_id'])]);
    if (!$printer || empty($printer['printer_command'])) {
        $msg = 'Drucker nicht gefunden oder ohne Druckbefehl';
        $db->execute("UPDATE periodic_invoices_ext SET print_error = :err, mtime = now() WHERE periodic_invoice_id = :pi", [':err' => $msg, ':pi' => $periodicInvoiceId]);
        return $msg;
    }

    $pdfError = null;
    $rendered = renderDocumentPdfFile($db, intval($info['ar_id']), 'invoice', null, isLxCarsEnabled($db), $pdfError);
    if ($rendered === false) {
        $msg = 'PDF: ' . ($pdfError ?: 'Erzeugung fehlgeschlagen');
        $db->execute("UPDATE periodic_invoices_ext SET print_error = :err, mtime = now() WHERE periodic_invoice_id = :pi", [':err' => $msg, ':pi' => $periodicInvoiceId]);
        return $msg;
    }

    $error = null;
    $copies = max(1, intval($info['copies'] ?? 1));
    for ($i = 0; $i < $copies && $error === null; $i++) {
        exec(sprintf('%s %s 2>&1', $printer['printer_command'], escapeshellarg($rendered['path'])), $output, $code);
        if ($code !== 0) $error = 'Druckbefehl fehlgeschlagen: ' . implode("\n", $output);
    }
    try {
        ausgangsrechnungArchivieren($db, intval($info['ar_id']), 'invoice', (string)@file_get_contents($rendered['path']), $rendered['filename'] ?? null, $employeeId);
    } catch (\Throwable $e) {
    }
    $rendered['engine']->cleanup($rendered['path']);

    if ($error !== null) {
        $db->execute("UPDATE periodic_invoices_ext SET print_error = :err, mtime = now() WHERE periodic_invoice_id = :pi", [':err' => $error, ':pi' => $periodicInvoiceId]);
        return $error;
    }
    $db->execute("UPDATE periodic_invoices_ext SET printed_at = now(), print_error = NULL, mtime = now() WHERE periodic_invoice_id = :pi", [':pi' => $periodicInvoiceId]);
    return null;
}

/**
 * Erzeugt alle fälligen Rechnungen (Zeitsteuerung und „Alle erzeugen").
 * Gesperrte Abrechnungen (Mahnsperre) werden ausgelassen und gemeldet.
 *
 * @param object   $db         DbhCompany-Handle
 * @param int|null $employeeId Mitarbeiter (NULL = Zeitsteuerung)
 * @param string   $source     manual | cron
 * @return array ['created' => [...], 'blocked' => [...], 'errors' => [...], 'skipped' => int]
 */
function recurringRunDue($db, ?int $employeeId, string $source = 'cron'): array {
    recurringMaintenance($db);
    $cfgCte = recurringConfigCte();
    $dueCte = recurringDueCte();

    $due = $db->getAll("WITH {$cfgCte}, {$dueCte} SELECT config_id, period_start, blocked, customer_name, ordnumber FROM due ORDER BY config_id, period_start");

    $summary = ['created' => [], 'blocked' => [], 'errors' => [], 'skipped' => 0];
    foreach ($due as $d) {
        if ($d['blocked'] === true || $d['blocked'] === 't') {
            $summary['blocked'][] = ['config_id' => intval($d['config_id']), 'period_start' => $d['period_start'], 'customer_name' => $d['customer_name'], 'ordnumber' => $d['ordnumber']];
            continue;
        }
        $res = recurringCreateInvoice($db, intval($d['config_id']), $d['period_start'], $employeeId, $source, false);
        if ($res['status'] === 'created') {
            $summary['created'][] = $res;
        } elseif ($res['status'] === 'error') {
            $summary['errors'][] = $res;
        } else {
            $summary['skipped']++;
        }
    }
    return $summary;
}

/**
 * Erzeugt Rechnungen: alle fälligen (all = true) oder einzelne Perioden —
 * einzelne auch vorzeitig oder trotz Mahnsperre (force = true). Jede Rechnung
 * wird wie im Beleg-Editor sofort gebucht und je nach Konfiguration per
 * E-Mail verschickt oder gedruckt.
 *
 * @param bool  $data['all']   alle fälligen Perioden
 * @param array $data['items'] [{config_id, period_start, force}]
 * @testdata {"all": true}
 */
function createRecurringInvoices($data) {
    permit('invoice_edit');
    $db = DbhCompany::begin();
    $employeeId = mitarbeiterId($data);

    if (!empty($data['all'])) {
        $summary = recurringRunDue($db, $employeeId, 'manual');
        resultInfo(true, 'CREATED', ['results' => $summary]);
        return;
    }

    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    if (!$items) {
        throw new ApiError('VALIDATION_ERROR', 'items oder all erforderlich');
    }
    recurringMaintenance($db);

    $summary = ['created' => [], 'blocked' => [], 'errors' => [], 'skipped' => 0];
    foreach ($items as $it) {
        $configId = intval($it['config_id'] ?? 0);
        $ps = $it['period_start'] ?? '';
        if ($configId <= 0 || !$ps) continue;
        $res = recurringCreateInvoice($db, $configId, $ps, $employeeId, 'manual', !empty($it['force']));
        if ($res['status'] === 'created') {
            $summary['created'][] = $res;
        } elseif ($res['status'] === 'blocked') {
            $summary['blocked'][] = $res;
        } elseif ($res['status'] === 'error') {
            $summary['errors'][] = $res;
        } else {
            $summary['skipped']++;
        }
    }
    resultInfo(true, 'CREATED', ['results' => $summary]);
}

/**
 * Schickt eine bereits erzeugte wiederkehrende Rechnung (erneut) per E-Mail.
 *
 * @param int $data['periodic_invoice_id'] periodic_invoices.id
 * @testdata {"periodic_invoice_id": 1}
 */
function sendRecurringInvoiceWhatsApp($data) {
    permit('sales_order_edit');
    $db = DbhCompany::begin();
    $id = intval($data['periodic_invoice_id'] ?? 0);
    if ($id <= 0) throw new ApiError('VALIDATION_ERROR', 'periodic_invoice_id erforderlich');
    $err = recurringSendInvoiceWhatsApp($db, $id, mitarbeiterId($data));
    if ($err !== null) throw new ApiError('WHATSAPP_FAILED', $err);
    resultInfo(true, 'Per WhatsApp gesendet');
}

/**
 * Rechnung einer Periode erneut per E-Mail senden.
 *
 * @param int $data['periodic_invoice_id']
 * @testdata {"periodic_invoice_id": 1}
 */
function sendRecurringInvoiceEmail($data) {
    permit('invoice_edit');
    $db = DbhCompany::begin();
    $id = intval($data['periodic_invoice_id'] ?? 0);
    if ($id <= 0) {
        throw new ApiError('VALIDATION_ERROR', 'periodic_invoice_id erforderlich');
    }
    $err = recurringSendInvoiceEmail($db, $id, mitarbeiterId($data));
    if ($err !== null) {
        resultInfo(false, 'EMAIL_ERROR', $err);
        return;
    }
    resultInfo(true, 'SENT');
}
