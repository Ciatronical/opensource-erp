<?php
// backend/api/accounting/dunning.php
//
// Mahnwesen — der Nachbau des kivitendo-Mahnwesens (SL/DN.pm, bin/mozilla/dn.pl)
// auf den Tabellen, die kivitendo dafür angelegt hat:
//
//   dunning_config          Mahnstufen (Beschreibung, Fristen, Gebühr, Zinssatz, E-Mail-Text)
//   dunning                 eine Zeile je gemahnter Rechnung; dunning_id = Nummer des Briefs
//   ar.dunning_config_id    aktuelle Mahnstufe der Rechnung
//   customer.dunning_lock   Mahnsperre, customer.dunning_mail eigene Mahn-Adresse
//   defaults.dunning_ar_*   Konten der Gebührenrechnung, dunning_creator, email_sender_dunning
//
// Dazu die beiden Zusatztabellen aus backend/upstall/crm/company_schema.sql:
// dunning_config_ext (Brieftext je Stufe) und dunning_ext (Versand, Ablage und
// Summen je Brief). kivitendo-Tabellen werden nie verändert.
//
// Fachliche Regeln, wortgleich zu kivitendo:
//   * Eine Rechnung ist mahnreif, wenn sie überfällig und offen ist und seit der
//     Fälligkeit der letzten Mahnung (sonst: der Rechnung) mindestens `terms`
//     Tage der nächsten Stufe vergangen sind.
//   * Die nächste Stufe ist die niedrigste aktive Stufe über der aktuellen.
//   * Zinsen = offener Betrag × Verzugstage × Zinssatz / 360.
//   * Gebühr und Zinsen können als eigene Debitorenbuchung (ar ohne Positionen)
//     auf die konfigurierten Konten gebucht werden.
//
// Anders als kivitendo: die Mahngebühr fällt je Brief an, nicht je Rechnung
// (sie steht auf der ersten Rechnungszeile des Briefs), der Brieftext ist je
// Stufe in der Oberfläche pflegbar, das Versandexemplar wird archiviert, und
// jeder Brief kennt seinen Versandweg.

require_once __DIR__.'/../print/print.php';
require_once __DIR__.'/../print/template_engine.php';
require_once __DIR__.'/../email/imap.class.php';
require_once __DIR__.'/../email/smtp.class.php';
require_once __DIR__.'/../email/email.php';

// Platzhalter in E-Mail-Betreff und -Text (kivitendo-Schreibweise <%name%>)
define('DUNNING_PLACEHOLDERS', ['name', 'customernumber', 'dunning', 'dunning_id', 'dunning_date',
                                'dunning_duedate', 'invnumbers', 'open_total', 'fee', 'interest',
                                'total', 'company']);

/**
 * Die gemeinsamen CTEs aller Mahn-Abfragen: Stufen, Mindestbetrag und die
 * Kandidaten (überfällige offene Rechnungen mit Zustand).
 *
 * Wird in getDunningProposal, createDunningRun, previewDunningPdf und im
 * Cockpit gleich verwendet — die Kachel auf der Übersicht muss dieselbe Zahl
 * zeigen wie der Vorschlag.
 *
 * Zustand (state) je Rechnung:
 *   ready        mahnreif
 *   waiting      überfällig, aber die Frist der nächsten Stufe ist noch nicht um
 *   locked       Kunde hat eine Mahnsperre
 *   direct_debit Lastschrift — der Kunde zahlt nicht selbst
 *   min_amount   unter dem Mindestbetrag
 *   max_level    höchste Stufe erreicht, es gibt keine nächste
 *
 * @return string CTE-Liste ohne führendes WITH, endet ohne Komma
 */
function dunningCandidateCtes(): string {
    return <<<SQL
        cfg AS (
            SELECT id, dunning_level, dunning_description,
                   COALESCE(active, false)                   AS active,
                   COALESCE(email, false)                    AS email,
                   COALESCE(terms, 0)                        AS terms,
                   COALESCE(payment_terms, 0)                AS payment_terms,
                   COALESCE(fee, 0)                          AS fee,
                   COALESCE(interest_rate, 0)                AS interest_rate,
                   COALESCE(create_invoices_for_fees, false) AS create_invoices_for_fees,
                   COALESCE(email_attachment, true)          AS email_attachment,
                   email_subject, email_body, template
            FROM dunning_config
        ),
        minamt AS (
            SELECT COALESCE((SELECT NULLIF(TRIM(value), '')::numeric
                             FROM defaults_oserp WHERE key = 'dunning_min_amount'), 0) AS v
        ),
        cand AS (
            SELECT a.id, a.invnumber, a.transdate, a.duedate, a.amount,
                   COALESCE(a.paid, 0)                                  AS paid,
                   ROUND(a.amount - COALESCE(a.paid, 0), 2)             AS open_amount,
                   a.customer_id, a.employee_id, a.cp_id,
                   c.name                                               AS customer_name,
                   c.customernumber,
                   COALESCE(a.direct_debit, false)                      AS direct_debit,
                   COALESCE(c.dunning_lock, false)                      AS dunning_lock,
                   cur.id                                               AS current_config_id,
                   cur.dunning_level                                    AS current_level,
                   cur.dunning_description                              AS current_description,
                   ld.transdate                                         AS last_dunning_date,
                   ld.duedate                                           AS last_dunning_duedate,
                   ld.dunning_id                                        AS last_dunning_id,
                   nx.id                                                AS next_config_id,
                   nx.dunning_level                                     AS next_level,
                   nx.dunning_description                               AS next_description,
                   nx.terms                                             AS next_terms,
                   nx.payment_terms                                     AS next_payment_terms,
                   nx.fee                                               AS next_fee,
                   nx.interest_rate                                     AS next_interest_rate,
                   nx.email                                             AS next_email,
                   (CURRENT_DATE - a.duedate)                           AS days_overdue,
                   (CURRENT_DATE - COALESCE(ld.duedate, a.duedate))     AS days_since_last,
                   ROUND((a.amount - COALESCE(a.paid, 0))
                         * GREATEST(CURRENT_DATE - a.duedate, 0)
                         * COALESCE(nx.interest_rate, 0) / 360, 2)      AS next_interest,
                   CASE
                       WHEN nx.id IS NULL                                            THEN 'max_level'
                       WHEN COALESCE(c.dunning_lock, false)                          THEN 'locked'
                       WHEN COALESCE(a.direct_debit, false)                          THEN 'direct_debit'
                       WHEN (a.amount - COALESCE(a.paid, 0)) < (SELECT v FROM minamt) THEN 'min_amount'
                       WHEN (CURRENT_DATE - COALESCE(ld.duedate, a.duedate)) < nx.terms THEN 'waiting'
                       ELSE 'ready'
                   END                                                  AS state,
                   GREATEST(COALESCE(nx.terms, 0)
                            - (CURRENT_DATE - COALESCE(ld.duedate, a.duedate)), 0) AS ready_in_days
            FROM ar a
            JOIN customer c ON c.id = a.customer_id
            LEFT JOIN cfg cur ON cur.id = a.dunning_config_id
            LEFT JOIN LATERAL (
                SELECT d.transdate, d.duedate, d.dunning_id
                FROM dunning d
                WHERE d.trans_id = a.id
                ORDER BY d.transdate DESC, d.id DESC
                LIMIT 1
            ) ld ON true
            LEFT JOIN LATERAL (
                SELECT x.*
                FROM cfg x
                WHERE x.active AND x.dunning_level > COALESCE(cur.dunning_level, 0)
                ORDER BY x.dunning_level
                LIMIT 1
            ) nx ON true
            WHERE a.storno IS NOT TRUE
              AND a.duedate IS NOT NULL
              AND a.duedate < CURRENT_DATE
              AND (a.amount - COALESCE(a.paid, 0)) > 0.005
              -- Gebührenrechnungen werden nicht ihrerseits gemahnt
              AND NOT EXISTS (SELECT 1 FROM dunning f WHERE f.fee_interest_ar_id = a.id)
        )
    SQL;
}

/**
 * Wandelt einen PHP-Array in ein PostgreSQL-Array-Literal (nur Ganzzahlen).
 */
function dunningIntArray($ids): string {
    $clean = [];
    foreach ((array)$ids as $id) {
        $n = intval($id);
        if ($n > 0) $clean[] = $n;
    }
    return '{' . implode(',', array_unique($clean)) . '}';
}

// ── Konfiguration ────────────────────────────────────────────────────────────

/**
 * Mahnstufen, Konten und Mindestbetrag in einer Abfrage.
 *
 * Liefert daneben die wählbaren Konten (Erlöskonten für Gebühr und Zinsen,
 * Forderungskonten) und einen Vorschlag daraus — gesucht über die
 * Kontenbezeichnung, damit niemand den Kontenrahmen durchblättern muss.
 *
 * @testdata {}
 */
function getDunningConfig($data) {
    $db = DbhCompany::begin();

    $row = $db->getOne(<<<SQL
        SELECT json_build_object(
            'levels', COALESCE((
                SELECT json_agg(json_build_object(
                    'id',                       c.id,
                    'dunning_level',            c.dunning_level,
                    'dunning_description',      c.dunning_description,
                    'active',                   COALESCE(c.active, true),
                    'email',                    COALESCE(c.email, false),
                    'terms',                    COALESCE(c.terms, 0),
                    'payment_terms',            COALESCE(c.payment_terms, 0),
                    'fee',                      COALESCE(c.fee, 0),
                    'interest_rate',            ROUND(COALESCE(c.interest_rate, 0) * 100, 2),
                    'email_subject',            COALESCE(c.email_subject, ''),
                    'email_body',               COALESCE(c.email_body, ''),
                    'email_attachment',         COALESCE(c.email_attachment, true),
                    'create_invoices_for_fees', COALESCE(c.create_invoices_for_fees, false),
                    'template',                 COALESCE(c.template, ''),
                    'letter_text',              COALESCE(e.letter_text, ''),
                    'in_use',                   EXISTS (SELECT 1 FROM dunning d WHERE d.dunning_config_id = c.id)
                ) ORDER BY c.dunning_level)
                FROM dunning_config c
                LEFT JOIN dunning_config_ext e ON e.dunning_config_id = c.id
            ), '[]'::json),
            'accounts', (
                SELECT json_build_object(
                    'fee_chart_id',      dunning_ar_amount_fee,
                    'interest_chart_id', dunning_ar_amount_interest,
                    'ar_chart_id',       dunning_ar,
                    'sender_name',       COALESCE(email_sender_dunning, ''),
                    'creator',           dunning_creator
                )
                FROM defaults LIMIT 1
            ),
            'min_amount', (SELECT COALESCE((SELECT NULLIF(TRIM(value), '')::numeric
                                            FROM defaults_oserp WHERE key = 'dunning_min_amount'), 0)),
            'income_charts', COALESCE((
                SELECT json_agg(json_build_object('id', id, 'accno', accno, 'description', description,
                                                  'title', accno || ' ' || description) ORDER BY accno)
                FROM chart
                WHERE POSITION('AR_amount' IN COALESCE(link, '')) > 0 AND COALESCE(invalid, false) = false
            ), '[]'::json),
            'ar_charts', COALESCE((
                SELECT json_agg(json_build_object('id', id, 'accno', accno, 'description', description,
                                                  'title', accno || ' ' || description) ORDER BY accno)
                FROM chart
                WHERE POSITION(':AR:' IN ':' || COALESCE(link, '') || ':') > 0 AND COALESCE(invalid, false) = false
            ), '[]'::json),
            'suggested', json_build_object(
                'fee_chart_id', (
                    SELECT id FROM chart
                    WHERE POSITION('AR_amount' IN COALESCE(link, '')) > 0
                      AND description ILIKE '%mahn%'
                    ORDER BY accno LIMIT 1),
                'interest_chart_id', (
                    SELECT id FROM chart
                    WHERE POSITION('AR_amount' IN COALESCE(link, '')) > 0
                      AND (description ILIKE '%verzugszins%' OR description ILIKE '%zinsertr%'
                           OR description ILIKE '%zinserträge%' OR description ILIKE '%zinsen%')
                    ORDER BY (description ILIKE '%verzug%') DESC, accno LIMIT 1),
                'ar_chart_id', (
                    SELECT id FROM chart
                    WHERE link = 'AR' ORDER BY accno LIMIT 1)
            ),
            'template_set', (SELECT templates FROM defaults LIMIT 1),
            'company', (SELECT value FROM defaults_oserp WHERE key = 'company_name')
        ) AS result
    SQL, []);

    $result = json_decode($row['result'], true);

    // Vorlagen liegen im Dateisystem, nicht in der Datenbank
    $result['templates'] = dunningTemplateList($db);

    // Ohne SMTP-Zugang kann kein Brief per E-Mail gehen — die Oberfläche
    // blendet den Kanal dann aus, statt beim Lauf zu scheitern.
    try {
        $mail = _getEmailConfig();
        $result['email_configured'] = !empty($mail['email_smtp_host']) && !empty($mail['email_username']);
    } catch (\Throwable $e) {
        $result['email_configured'] = false;
    }

    resultInfo(true, '', ['results' => $result]);
}

/**
 * Speichert Mahnstufen, Konten und Mindestbetrag in einer Anweisung.
 *
 * Stufen mit id werden aktualisiert, ohne id angelegt, fehlende gelöscht —
 * sofern sie noch nie verwendet wurden. Der Zinssatz kommt in Prozent an und
 * wird wie in kivitendo als Bruchteil gespeichert.
 *
 * @param array  $data['levels']            Mahnstufen [{id, dunning_level, dunning_description, active, email, terms, payment_terms, fee, interest_rate, email_subject, email_body, email_attachment, create_invoices_for_fees, template, letter_text}]
 * @param int    $data['fee_chart_id']      Erlöskonto Mahngebühren
 * @param int    $data['interest_chart_id'] Erlöskonto Verzugszinsen
 * @param int    $data['ar_chart_id']       Forderungskonto der Gebührenrechnung
 * @param string $data['sender_name']       Absendername der Mahn-E-Mails
 * @param string $data['creator']           current_employee | invoice_employee
 * @param float  $data['min_amount']        Mindestbetrag, ab dem gemahnt wird
 * @testdata {"levels": [{"id": 0, "dunning_level": 1, "dunning_description": "Zahlungserinnerung", "active": true, "email": true, "terms": 7, "payment_terms": 10, "fee": 0, "interest_rate": 0}], "fee_chart_id": null, "interest_chart_id": null, "ar_chart_id": null, "sender_name": "", "creator": "current_employee", "min_amount": 1}
 */
function saveDunningConfig($data) {
    $db = DbhCompany::begin();

    // Zahlen sauber typisieren, damit jsonb_to_recordset nichts raten muss
    $levels = [];
    foreach ((array)($data['levels'] ?? []) as $i => $l) {
        $description = trim((string)($l['dunning_description'] ?? ''));
        if ($description === '') continue;
        $levels[] = [
            'id'                       => intval($l['id'] ?? 0),
            'dunning_level'            => intval($l['dunning_level'] ?? ($i + 1)),
            'dunning_description'      => $description,
            'active'                   => !empty($l['active']),
            'email'                    => !empty($l['email']),
            'terms'                    => intval($l['terms'] ?? 0),
            'payment_terms'            => intval($l['payment_terms'] ?? 0),
            'fee'                      => round(floatval($l['fee'] ?? 0), 2),
            'interest_rate'            => round(floatval($l['interest_rate'] ?? 0), 4),
            'email_subject'            => (string)($l['email_subject'] ?? ''),
            'email_body'               => (string)($l['email_body'] ?? ''),
            'email_attachment'         => !isset($l['email_attachment']) || !empty($l['email_attachment']),
            'create_invoices_for_fees' => !empty($l['create_invoices_for_fees']),
            'template'                 => trim((string)($l['template'] ?? '')),
            'letter_text'              => (string)($l['letter_text'] ?? ''),
        ];
    }

    $creator = ($data['creator'] ?? '') === 'invoice_employee' ? 'invoice_employee' : 'current_employee';
    $toId = function ($v) { $n = intval($v ?? 0); return $n > 0 ? $n : null; };

    $row = $db->getOne(<<<SQL
        WITH p AS (
            SELECT :levels::jsonb        AS levels,
                   :fee_chart::int       AS fee_chart,
                   :interest_chart::int  AS interest_chart,
                   :ar_chart::int        AS ar_chart,
                   :sender_name::text    AS sender_name,
                   :creator::text        AS creator,
                   :min_amount::numeric  AS min_amount
        ),
        input AS (
            SELECT x.*
            FROM p, jsonb_to_recordset(p.levels) AS x(
                id int, dunning_level int, dunning_description text, active bool, email bool,
                terms int, payment_terms int, fee numeric, interest_rate numeric,
                email_subject text, email_body text, email_attachment bool,
                create_invoices_for_fees bool, template text, letter_text text)
        ),
        upd AS (
            UPDATE dunning_config c
            SET dunning_level = i.dunning_level, dunning_description = i.dunning_description,
                active = i.active, email = i.email, terms = i.terms, payment_terms = i.payment_terms,
                fee = i.fee, interest_rate = i.interest_rate / 100,
                email_subject = i.email_subject, email_body = i.email_body,
                email_attachment = i.email_attachment,
                create_invoices_for_fees = i.create_invoices_for_fees,
                template = NULLIF(i.template, '')
            FROM input i
            WHERE c.id = i.id AND i.id > 0
            RETURNING c.id, i.letter_text
        ),
        ins AS (
            INSERT INTO dunning_config
                (dunning_level, dunning_description, active, auto, email, terms, payment_terms,
                 fee, interest_rate, email_subject, email_body, email_attachment,
                 create_invoices_for_fees, template)
            SELECT i.dunning_level, i.dunning_description, i.active, false, i.email, i.terms,
                   i.payment_terms, i.fee, i.interest_rate / 100, i.email_subject, i.email_body,
                   i.email_attachment, i.create_invoices_for_fees, NULLIF(i.template, '')
            FROM input i
            WHERE COALESCE(i.id, 0) = 0
            RETURNING id, dunning_level
        ),
        ext_new AS (
            INSERT INTO dunning_config_ext (dunning_config_id, letter_text)
            SELECT ins.id, i.letter_text
            FROM ins
            JOIN input i ON i.dunning_level = ins.dunning_level AND COALESCE(i.id, 0) = 0
            RETURNING id
        ),
        ext_upd AS (
            INSERT INTO dunning_config_ext (dunning_config_id, letter_text)
            SELECT upd.id, upd.letter_text FROM upd
            ON CONFLICT (dunning_config_id)
            DO UPDATE SET letter_text = EXCLUDED.letter_text, mtime = now()
            RETURNING id
        ),
        gone AS (
            -- Nur Stufen, die nie verwendet wurden, dürfen verschwinden
            SELECT c.id
            FROM dunning_config c
            WHERE c.id NOT IN (SELECT COALESCE(id, 0) FROM input)
              AND NOT EXISTS (SELECT 1 FROM dunning d WHERE d.dunning_config_id = c.id)
        ),
        del_ext AS (
            DELETE FROM dunning_config_ext WHERE dunning_config_id IN (SELECT id FROM gone) RETURNING id
        ),
        del AS (
            DELETE FROM dunning_config WHERE id IN (SELECT id FROM gone) RETURNING id
        ),
        defs AS (
            UPDATE defaults
            SET dunning_ar_amount_fee      = p.fee_chart,
                dunning_ar_amount_interest = p.interest_chart,
                dunning_ar                 = p.ar_chart,
                email_sender_dunning       = p.sender_name,
                dunning_creator            = p.creator::dunning_creator
            FROM p
            RETURNING 1
        ),
        minamt AS (
            INSERT INTO defaults_oserp (key, value)
            SELECT 'dunning_min_amount', p.min_amount::text FROM p
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()
            RETURNING key
        )
        SELECT (SELECT COUNT(*) FROM upd)  AS updated,
               (SELECT COUNT(*) FROM ins)  AS inserted,
               (SELECT COUNT(*) FROM del)  AS deleted,
               (SELECT COUNT(*) FROM defs) AS defaults_updated
    SQL, [
        ':levels'         => json_encode($levels),
        ':fee_chart'      => $toId($data['fee_chart_id'] ?? null),
        ':interest_chart' => $toId($data['interest_chart_id'] ?? null),
        ':ar_chart'       => $toId($data['ar_chart_id'] ?? null),
        ':sender_name'    => trim((string)($data['sender_name'] ?? '')),
        ':creator'        => $creator,
        ':min_amount'     => round(floatval($data['min_amount'] ?? 0), 2),
    ]);

    resultInfo(true, '', ['results' => [
        'updated'  => intval($row['updated']),
        'inserted' => intval($row['inserted']),
        'deleted'  => intval($row['deleted']),
    ]]);
}

/**
 * Vorlagen für Mahnbriefe im Vorlagen-Set der Firma.
 *
 * kivitendo nennt in dunning_config.template den Dateinamen ohne Endung
 * (z. B. mahnung1). Gelistet wird, was nach Mahnung aussieht; die
 * Standardvorlage dunning.tex kommt aus dem Master-Set, wenn das Set der
 * Firma sie nicht hat.
 */
function dunningTemplateList($db): array {
    $set = getTemplateSet($db);
    $dir = getTemplateDir($set);
    $names = [];
    foreach (glob($dir . '/*.tex') ?: [] as $file) {
        $base = basename($file, '.tex');
        if (preg_match('/^(dunning|mahnung|zahlungserinnerung)/i', $base) && !str_ends_with($base, '_invoice')) {
            $names[] = $base;
        }
    }
    if (!in_array('dunning', $names, true) && is_file(PRINT_MASTER_BASE . '/RB/dunning.tex')) {
        array_unshift($names, 'dunning');
    }
    sort($names);
    return array_values(array_unique($names));
}

// ── Vorschlag ────────────────────────────────────────────────────────────────

/**
 * Mahnvorschlag: alle überfälligen offenen Rechnungen, je Kunde und nächster
 * Stufe zu einem Brief gebündelt — mit Zustand, Gebühr, Zinsen, Kanal und der
 * Mahnhistorie des Kunden. Eine Abfrage.
 *
 * @param string $data['search'] Kundenname oder -nummer (optional)
 * @testdata {"search": ""}
 */
function getDunningProposal($data) {
    $db = DbhCompany::begin();
    $ctes = dunningCandidateCtes();

    $row = $db->getOne(<<<SQL
        WITH p AS (SELECT TRIM(COALESCE(:search::text, '')) AS search),
        {$ctes},
        hit AS (
            SELECT cand.*
            FROM cand, p
            WHERE p.search = ''
               OR cand.customer_name ILIKE '%' || p.search || '%'
               OR cand.customernumber ILIKE '%' || p.search || '%'
               OR cand.invnumber ILIKE '%' || p.search || '%'
        ),
        grp AS (
            SELECT h.customer_id, h.next_config_id,
                   MIN(h.next_level)             AS next_level,
                   MIN(h.next_description)       AS next_description,
                   BOOL_OR(h.next_email)         AS next_email,
                   MIN(h.next_fee)               AS fee,
                   MIN(h.next_payment_terms)     AS payment_terms,
                   BOOL_OR(h.dunning_lock)       AS dunning_lock,
                   COUNT(*)                      AS invoice_count,
                   COUNT(*) FILTER (WHERE h.state = 'ready')                        AS ready_count,
                   COUNT(*) FILTER (WHERE h.state = 'waiting')                      AS waiting_count,
                   COALESCE(SUM(h.open_amount) FILTER (WHERE h.state = 'ready'), 0) AS ready_sum,
                   COALESCE(SUM(h.open_amount), 0)                                  AS open_sum,
                   COALESCE(SUM(h.next_interest) FILTER (WHERE h.state = 'ready'), 0) AS interest,
                   MAX(h.days_overdue)           AS max_days_overdue,
                   MIN(h.ready_in_days) FILTER (WHERE h.state = 'waiting')          AS ready_in_days,
                   json_agg(json_build_object(
                       'id',                  h.id,
                       'invnumber',           h.invnumber,
                       'transdate',           TO_CHAR(h.transdate, 'DD.MM.YYYY'),
                       'duedate',             TO_CHAR(h.duedate, 'DD.MM.YYYY'),
                       'amount',              h.amount,
                       'paid',                h.paid,
                       'open_amount',         h.open_amount,
                       'days_overdue',        h.days_overdue,
                       'current_level',       h.current_level,
                       'current_description', h.current_description,
                       'last_dunning_date',   TO_CHAR(h.last_dunning_date, 'DD.MM.YYYY'),
                       'last_dunning_id',     h.last_dunning_id,
                       'interest',            h.next_interest,
                       'direct_debit',        h.direct_debit,
                       'state',               h.state,
                       'ready_in_days',       h.ready_in_days
                   ) ORDER BY h.duedate, h.id) AS invoices
            FROM hit h
            GROUP BY h.customer_id, h.next_config_id
        )
        SELECT json_build_object(
            'configured', (SELECT COUNT(*) > 0 FROM cfg WHERE active),
            'min_amount', (SELECT v FROM minamt),
            'levels', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', id, 'dunning_level', dunning_level, 'dunning_description', dunning_description,
                    'active', active, 'email', email, 'fee', fee, 'interest_rate', interest_rate,
                    'terms', terms, 'payment_terms', payment_terms
                ) ORDER BY dunning_level) FROM cfg WHERE active
            ), '[]'::json),
            'groups', COALESCE((
                SELECT json_agg(json_build_object(
                    'key',              g.customer_id::text || '-' || COALESCE(g.next_config_id, 0)::text,
                    'customer_id',      g.customer_id,
                    'customer',         c.name,
                    'customernumber',   c.customernumber,
                    'city',             c.city,
                    'email',            COALESCE(NULLIF(TRIM(c.dunning_mail), ''), c.email, ''),
                    'dunning_lock',     g.dunning_lock,
                    'next_config_id',   g.next_config_id,
                    'next_level',       g.next_level,
                    'next_description', g.next_description,
                    'next_email',       COALESCE(g.next_email, false),
                    'payment_terms',    g.payment_terms,
                    'fee',              g.fee,
                    'interest',         g.interest,
                    'invoice_count',    g.invoice_count,
                    'ready_count',      g.ready_count,
                    'waiting_count',    g.waiting_count,
                    'ready_sum',        g.ready_sum,
                    'open_sum',         g.open_sum,
                    'max_days_overdue', g.max_days_overdue,
                    'ready_in_days',    g.ready_in_days,
                    'invoices',         g.invoices,
                    'history', COALESCE((
                        SELECT json_agg(json_build_object(
                            'dunning_id',  h.dunning_id,
                            'level',       h.dunning_level,
                            'description', hc.dunning_description,
                            'transdate',   TO_CHAR(h.transdate, 'DD.MM.YYYY'),
                            'channel',     hx.channel
                        ) ORDER BY h.transdate DESC, h.dunning_id DESC)
                        FROM (
                            SELECT DISTINCT ON (d.dunning_id) d.dunning_id, d.dunning_level,
                                   d.dunning_config_id, d.transdate
                            FROM dunning d
                            JOIN ar a2 ON a2.id = d.trans_id
                            WHERE a2.customer_id = g.customer_id
                            ORDER BY d.dunning_id, d.transdate DESC
                        ) h
                        LEFT JOIN dunning_config hc ON hc.id = h.dunning_config_id
                        LEFT JOIN dunning_ext hx ON hx.dunning_id = h.dunning_id
                    ), '[]'::json)
                ) ORDER BY g.next_level DESC NULLS LAST, g.ready_sum DESC, c.name)
                FROM grp g
                JOIN customer c ON c.id = g.customer_id
            ), '[]'::json),
            'summary', (
                SELECT json_build_object(
                    'ready_invoices',    COUNT(*) FILTER (WHERE state = 'ready'),
                    'ready_customers',   COUNT(DISTINCT customer_id) FILTER (WHERE state = 'ready'),
                    'ready_sum',         COALESCE(SUM(open_amount) FILTER (WHERE state = 'ready'), 0),
                    'ready_fee',         COALESCE(SUM(next_fee) FILTER (WHERE state = 'ready'), 0),
                    'ready_interest',    COALESCE(SUM(next_interest) FILTER (WHERE state = 'ready'), 0),
                    'waiting_invoices',  COUNT(*) FILTER (WHERE state = 'waiting'),
                    'locked_invoices',   COUNT(*) FILTER (WHERE state = 'locked'),
                    'max_level_invoices', COUNT(*) FILTER (WHERE state = 'max_level'),
                    'max_level_sum',     COALESCE(SUM(open_amount) FILTER (WHERE state = 'max_level'), 0),
                    'overdue_invoices',  COUNT(*),
                    'overdue_sum',       COALESCE(SUM(open_amount), 0),
                    'in_dunning',        COUNT(*) FILTER (WHERE current_config_id IS NOT NULL),
                    'in_dunning_sum',    COALESCE(SUM(open_amount) FILTER (WHERE current_config_id IS NOT NULL), 0)
                )
                FROM cand
            )
        ) AS result
    SQL, [':search' => (string)($data['search'] ?? '')]);

    resultInfo(true, '', ['results' => json_decode($row['result'], true)]);
}

// ── Brief: Daten, PDF, Ablage, E-Mail ────────────────────────────────────────

/**
 * Alle Daten eines Mahnbriefs als ein JSON — für PDF, E-Mail und Vorschau.
 *
 * Zwei Betriebsarten, dieselbe Abfrage bis auf die Quelle der Rechnungszeilen:
 *   saved    ein gespeicherter Brief (dunning.dunning_id)
 *   preview  noch nicht gespeichert: Kunde, Stufe und Rechnungen aus dem Vorschlag
 *
 * @param string $mode saved|preview
 * @param array  $params dunning_id bzw. customer_id, config_id, ids, employee_id
 * @return array|null
 */
function dunningLetterData($db, string $mode, array $params): ?array {
    $ctes = dunningCandidateCtes();

    if ($mode === 'saved') {
        $with = <<<SQL
            WITH p AS (SELECT :dunning_id::int AS dunning_id, NULL::int AS employee_id),
            {$ctes},
            rows AS (
                SELECT d.id, d.trans_id, d.dunning_id, d.dunning_config_id, d.dunning_level,
                       d.transdate, d.duedate, COALESCE(d.fee, 0) AS fee, COALESCE(d.interest, 0) AS interest,
                       d.fee_interest_ar_id,
                       a.invnumber, a.transdate AS invdate, a.duedate AS invduedate, a.amount,
                       COALESCE(a.paid, 0) AS paid, ROUND(a.amount - COALESCE(a.paid, 0), 2) AS open_amount,
                       a.customer_id, a.cp_id, a.employee_id AS inv_employee_id
                FROM dunning d
                JOIN ar a ON a.id = d.trans_id
                WHERE d.dunning_id = (SELECT dunning_id FROM p)
            )
        SQL;
        $bind = [':dunning_id' => intval($params['dunning_id'])];
    } else {
        $with = <<<SQL
            WITH p AS (SELECT 0 AS dunning_id, :employee_id::int AS employee_id,
                              :customer_id::int AS customer_id, :config_id::int AS config_id,
                              :ids::int[] AS ids),
            {$ctes},
            rows AS (
                SELECT cand.id, cand.id AS trans_id, 0 AS dunning_id, cf.id AS dunning_config_id,
                       cf.dunning_level, CURRENT_DATE AS transdate,
                       CURRENT_DATE + cf.payment_terms AS duedate,
                       CASE WHEN cand.id = MIN(cand.id) OVER () THEN cf.fee ELSE 0 END AS fee,
                       ROUND(cand.open_amount * GREATEST(cand.days_overdue, 0) * cf.interest_rate / 360, 2) AS interest,
                       NULL::int AS fee_interest_ar_id,
                       cand.invnumber, cand.transdate AS invdate, cand.duedate AS invduedate, cand.amount,
                       cand.paid, cand.open_amount, cand.customer_id, cand.cp_id,
                       cand.employee_id AS inv_employee_id
                FROM cand, p
                JOIN cfg cf ON cf.id = p.config_id
                WHERE cand.customer_id = p.customer_id AND cand.id = ANY(p.ids)
            )
        SQL;
        $bind = [
            ':employee_id' => intval($params['employee_id'] ?? 0) ?: null,
            ':customer_id' => intval($params['customer_id']),
            ':config_id'   => intval($params['config_id']),
            ':ids'         => dunningIntArray($params['ids'] ?? []),
        ];
    }

    $row = $db->getOne($with . <<<SQL
        ,
        head AS (
            SELECT MIN(r.transdate)         AS dunning_date,
                   MAX(r.duedate)           AS dunning_duedate,
                   MIN(r.dunning_config_id) AS config_id,
                   MIN(r.dunning_level)     AS level,
                   MIN(r.customer_id)       AS customer_id,
                   MIN(r.cp_id)             AS cp_id,
                   SUM(r.fee)               AS fee,
                   SUM(r.interest)          AS interest,
                   SUM(r.open_amount)       AS open_total,
                   SUM(r.amount)            AS amount_total,
                   MIN(r.fee_interest_ar_id) AS fee_ar_id,
                   (SELECT r2.inv_employee_id FROM rows r2 ORDER BY r2.invdate, r2.id LIMIT 1) AS inv_employee_id
            FROM rows r
            HAVING COUNT(*) > 0
        )
        SELECT json_build_object(
            'dunning_id',        (SELECT dunning_id FROM p),
            'dunning_date',      TO_CHAR(h.dunning_date, 'DD.MM.YYYY'),
            'dunning_date_iso',  TO_CHAR(h.dunning_date, 'YYYY-MM-DD'),
            'dunning_duedate',   TO_CHAR(h.dunning_duedate, 'DD.MM.YYYY'),
            'level',             h.level,
            'dunning',           COALESCE(cf.dunning_description, ''),
            'letter_text',       COALESCE(ce.letter_text, ''),
            'email_subject',     COALESCE(cf.email_subject, ''),
            'email_body',        COALESCE(cf.email_body, ''),
            'email_attachment',  COALESCE(cf.email_attachment, true),
            'template',          COALESCE(cf.template, ''),
            'fee',               ROUND(COALESCE(h.fee, 0), 2),
            'interest',          ROUND(COALESCE(h.interest, 0), 2),
            'open_total',        ROUND(COALESCE(h.open_total, 0), 2),
            'total',             ROUND(COALESCE(h.open_total, 0) + COALESCE(h.fee, 0) + COALESCE(h.interest, 0), 2),
            'customer', json_build_object(
                'id',             c.id,
                'name',           c.name,
                'customernumber', COALESCE(c.customernumber, ''),
                'department_1',   COALESCE(c.department_1, ''),
                'department_2',   COALESCE(c.department_2, ''),
                'street',         COALESCE(c.street, ''),
                'zipcode',        COALESCE(c.zipcode, ''),
                'city',           COALESCE(c.city, ''),
                'country',        COALESCE(c.country, ''),
                'email',          COALESCE(NULLIF(TRIM(c.dunning_mail), ''), c.email, ''),
                'cc',             COALESCE(c.cc, ''),
                'bcc',            COALESCE(c.bcc, ''),
                'greeting',       COALESCE(c.greeting, ''),
                'natural_person', COALESCE(c.natural_person, false),
                'language_code',  COALESCE(l.template_code, 'DE')
            ),
            'contact', (
                SELECT json_build_object('cp_givenname', COALESCE(cp_givenname, ''), 'cp_name', COALESCE(cp_name, ''),
                                         'cp_gender', COALESCE(cp_gender, ''), 'cp_title', COALESCE(cp_title, ''))
                FROM contacts WHERE cp_id = h.cp_id
            ),
            'employee', (
                SELECT json_build_object('id', e.id, 'name', COALESCE(e.name, ''),
                                         'tel', COALESCE(e.deleted_tel, ''), 'email', COALESCE(e.deleted_email, ''))
                FROM employee e
                WHERE e.id = COALESCE(x.employee_id,
                                      CASE WHEN (SELECT dunning_creator FROM defaults LIMIT 1) = 'invoice_employee'
                                           THEN h.inv_employee_id END,
                                      (SELECT employee_id FROM p),
                                      h.inv_employee_id)
            ),
            'company',      COALESCE((SELECT value FROM defaults_oserp WHERE key = 'company_name'), ''),
            'sender_name',  COALESCE((SELECT email_sender_dunning FROM defaults LIMIT 1), ''),
            'template_set', (SELECT templates FROM defaults LIMIT 1),
            'channel',      x.channel,
            'email_to',     x.email_to,
            'document_id',  x.document_id,
            'sent_at',      TO_CHAR(x.sent_at, 'DD.MM.YYYY HH24:MI'),
            'invoices', (
                SELECT json_agg(json_build_object(
                    'id',           r.trans_id,
                    'invnumber',    r.invnumber,
                    'transdate',    TO_CHAR(r.invdate, 'DD.MM.YYYY'),
                    'duedate',      TO_CHAR(r.invduedate, 'DD.MM.YYYY'),
                    'amount',       ROUND(r.amount, 2),
                    'paid',         ROUND(r.paid, 2),
                    'open_amount',  ROUND(r.open_amount, 2),
                    'days_overdue', h.dunning_date - r.invduedate,
                    'fee',          ROUND(r.fee, 2),
                    'interest',     ROUND(r.interest, 2)
                ) ORDER BY r.invdate, r.invnumber)
                FROM rows r
            ),
            'fee_invoice', (
                SELECT json_build_object('id', fa.id, 'invnumber', fa.invnumber, 'amount', fa.amount,
                                         'paid', COALESCE(fa.paid, 0))
                FROM ar fa WHERE fa.id = h.fee_ar_id
            )
        ) AS result
        FROM head h
        JOIN customer c ON c.id = h.customer_id
        LEFT JOIN language l ON l.id = c.language_id
        LEFT JOIN dunning_config cf ON cf.id = h.config_id
        LEFT JOIN dunning_config_ext ce ON ce.dunning_config_id = cf.id
        LEFT JOIN dunning_ext x ON x.dunning_id = (SELECT dunning_id FROM p) AND (SELECT dunning_id FROM p) > 0
    SQL, $bind);

    if (!$row || empty($row['result'])) return null;
    return json_decode($row['result'], true);
}

/**
 * Vorlage für den Brief ermitteln.
 *
 * Reihenfolge: die in der Stufe eingetragene Vorlage, dann dunning.tex im
 * Set der Firma, dann dunning.tex aus dem Master-Set (wird ins Set kopiert,
 * damit die Firma sie anpassen kann), zuletzt kivitendos zahlungserinnerung.tex.
 */
function dunningTemplateFile(string $templateDir, string $configured): string {
    $configured = trim($configured);
    if ($configured !== '') {
        $name = basename($configured, '.tex') . '.tex';
        if (is_file($templateDir . '/' . $name)) return $name;
    }
    if (is_file($templateDir . '/dunning.tex')) return 'dunning.tex';

    $master = PRINT_MASTER_BASE . '/RB/dunning.tex';
    if (is_file($master)) {
        if (is_writable($templateDir) && @copy($master, $templateDir . '/dunning.tex')) {
            return 'dunning.tex';
        }
    }
    if (is_file($templateDir . '/zahlungserinnerung.tex')) return 'zahlungserinnerung.tex';
    return 'dunning.tex';
}

/**
 * Rendert den Mahnbrief als PDF.
 *
 * @param array $letter Ergebnis von dunningLetterData()
 * @return array ['pdf' => Bytes, 'filename' => Name] oder ['error' => Text]
 */
function dunningRenderPdf($db, array $letter): array {
    $fmt = fn($v) => number_format(floatval($v), 2, ',', '.');
    $cust = $letter['customer'];
    $cp   = $letter['contact'] ?? [];
    $emp  = $letter['employee'] ?? [];
    $lang = strtoupper($cust['language_code'] ?? 'DE') ?: 'DE';

    $variables = [
        'language_code'    => $lang,
        'currency'         => 'EUR',
        'media'            => 'printer',
        'employee_company' => $letter['company'] ?? '',
        'template_meta'    => ['language' => ['template_code' => $lang], 'formname' => 'dunning'],
        'formname'         => 'dunning',

        'dunning_id'       => $letter['dunning_id'] ? (string)$letter['dunning_id'] : '',
        'dunning'          => $letter['dunning'],
        'dunning_level'    => (string)$letter['level'],
        'dunning_date'     => $letter['dunning_date'],
        'dunning_duedate'  => $letter['dunning_duedate'],
        'dunning_text'     => $letter['letter_text'],
        'transdate'        => $letter['dunning_date'],
        'duedate'          => $letter['dunning_duedate'],

        'fee'              => $fmt($letter['fee']),
        'interest'         => $fmt($letter['interest']),
        'has_fee'          => floatval($letter['fee']) > 0 ? '1' : '',
        'has_interest'     => floatval($letter['interest']) > 0 ? '1' : '',
        'open_total'       => $fmt($letter['open_total']),
        'total'            => $fmt($letter['total']),
        'invtotal'         => $fmt($letter['total']),

        'name'             => $cust['name'],
        'customernumber'   => $cust['customernumber'],
        'department_1'     => $cust['department_1'],
        'department_2'     => $cust['department_2'],
        'street'           => $cust['street'],
        'zipcode'          => $cust['zipcode'],
        'city'             => $cust['city'],
        'country'          => $cust['country'],
        'greeting'         => $cust['greeting'],
        'natural_person'   => !empty($cust['natural_person']) ? '1' : '',
        'customer_email'   => $cust['email'],

        'cp_givenname'     => $cp['cp_givenname'] ?? '',
        'cp_name'          => $cp['cp_name'] ?? '',
        'cp_gender'        => $cp['cp_gender'] ?? '',
        'cp_title'         => $cp['cp_title'] ?? '',

        'employee_name'    => $emp['name'] ?? '',
        'employee_tel'     => $emp['tel'] ?? '',
        'employee_email'   => $emp['email'] ?? '',

        'titlebar'         => $letter['dunning'],
        'preview'          => empty($letter['dunning_id']) ? '1' : '',
    ];

    $inv = $letter['invoices'] ?? [];
    $arrays = [
        'dn_invnumber'   => array_column($inv, 'invnumber'),
        'dn_transdate'   => array_column($inv, 'transdate'),
        'dn_duedate'     => array_column($inv, 'duedate'),
        'dn_amount'      => array_map(fn($i) => $fmt($i['amount']), $inv),
        'dn_open_amount' => array_map(fn($i) => $fmt($i['open_amount']), $inv),
        'dn_days'        => array_map(fn($i) => (string)intval($i['days_overdue']), $inv),
        'dn_interest'    => array_map(fn($i) => $fmt($i['interest']), $inv),
    ];

    $templateSet = $letter['template_set'] ?? null;
    if (!$templateSet || resolveTemplateDir($templateSet) === false) {
        $templateSet = getTemplateSet($db);
    }
    $templateDir  = getTemplateDir($templateSet);
    $templateName = dunningTemplateFile($templateDir, $letter['template'] ?? '');

    $engine = new LaTeXTemplateEngine($templateDir);
    $engine->setVariables($variables);
    $engine->setArrays($arrays);

    $pdfPath = $engine->generatePDF($templateName);
    if ($pdfPath === false) {
        return ['error' => $engine->getError()];
    }
    $pdf = file_get_contents($pdfPath);
    $engine->cleanup($pdfPath);

    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $letter['dunning'] . '_' . ($letter['dunning_id'] ?: 'Vorschau') . '_' . $cust['name']);
    return ['pdf' => $pdf, 'filename' => trim($name, '_') . '.pdf'];
}

/**
 * Archiviert das Versandexemplar eines Briefs (einmalig, unveränderlich).
 *
 * Gleiche Ablage wie die Ausgangsrechnung (accounting_documents, Schreibschutz,
 * Protokoll) — ein gemahnter Kunde bestreitet gern, was er bekommen hat.
 */
function dunningArchivieren($db, int $dunningId, string $pdf, string $filename, ?int $employeeId, array $letter): ?int {
    if ($dunningId <= 0 || $pdf === '') return null;

    $vorhanden = $db->getOne("SELECT document_id FROM dunning_ext WHERE dunning_id = :id", [':id' => $dunningId]);
    if (!empty($vorhanden['document_id'])) return intval($vorhanden['document_id']);

    $doc = $db->getOne(<<<SQL
        INSERT INTO accounting_documents
            (original_name, mime_type, file_size, file_hash, status, employee_id, notes)
        VALUES (:name, 'application/pdf', :size, :hash, 'booked', :eid, :notiz)
        RETURNING id
    SQL, [
        ':name'  => $filename,
        ':size'  => strlen($pdf),
        ':hash'  => hash('sha256', $pdf),
        ':eid'   => $employeeId ?: null,
        ':notiz' => $letter['dunning'] . ' Nr. ' . $dunningId . ' an ' . $letter['customer']['name'] . ', Versandexemplar',
    ]);
    $docId = intval($doc['id']);

    $verzeichnis = fmDataDir() . '/accounting';
    if (!is_dir($verzeichnis)) mkdir($verzeichnis, 0755, true);

    $sicher = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    $pfad   = "accounting/{$docId}_{$sicher}";
    if (!belegSchreiben(fmDataDir() . '/' . $pfad, $pdf)) {
        $db->execute("DELETE FROM accounting_documents WHERE id = :id", [':id' => $docId]);
        return null;
    }

    belegAblageEintragen($db, $docId, $pfad);
    belegProtokoll($db, $docId, $employeeId, 'ablage', null, $letter['dunning'] . ' Nr. ' . $dunningId);

    $db->execute("UPDATE dunning_ext SET document_id = :doc, mtime = now() WHERE dunning_id = :id",
        [':doc' => $docId, ':id' => $dunningId]);
    return $docId;
}

/**
 * Liest ein archiviertes Versandexemplar.
 */
function dunningArchivLesen($db, int $documentId): ?array {
    $doc = $db->getOne("SELECT original_name, stored_path FROM accounting_documents WHERE id = :id", [':id' => $documentId]);
    if (!$doc || empty($doc['stored_path'])) return null;
    $pfad = fmDataDir() . '/' . $doc['stored_path'];
    if (!is_file($pfad)) return null;
    return ['pdf' => file_get_contents($pfad), 'filename' => $doc['original_name']];
}

/**
 * Ersetzt die Platzhalter in E-Mail-Betreff und -Text.
 */
function dunningPlatzhalter(string $text, array $letter): string {
    $fmt = fn($v) => number_format(floatval($v), 2, ',', '.') . ' €';
    $map = [
        'name'            => $letter['customer']['name'],
        'customernumber'  => $letter['customer']['customernumber'],
        'dunning'         => $letter['dunning'],
        'dunning_id'      => (string)$letter['dunning_id'],
        'dunning_date'    => $letter['dunning_date'],
        'dunning_duedate' => $letter['dunning_duedate'],
        'invnumbers'      => implode(', ', array_column($letter['invoices'] ?? [], 'invnumber')),
        'open_total'      => $fmt($letter['open_total']),
        'fee'             => $fmt($letter['fee']),
        'interest'        => $fmt($letter['interest']),
        'total'           => $fmt($letter['total']),
        'company'         => $letter['company'],
    ];
    foreach ($map as $key => $value) {
        $text = str_replace('<%' . $key . '%>', $value, $text);
    }
    return $text;
}

/**
 * Schickt einen Brief per E-Mail und vermerkt den Versand.
 *
 * @return string|null Fehlertext oder null bei Erfolg
 */
function dunningEmailSenden($db, array $letter, string $pdf, string $filename, string $to): ?string {
    $to = trim($to);
    if ($to === '') return 'Keine E-Mail-Adresse';

    try {
        $config = _getEmailConfig();
        $smtp   = _createSmtpClient($config);
        $from   = $config['email_address'] ?? $config['email_username'] ?? '';

        $subject = dunningPlatzhalter($letter['email_subject'] ?: '<%dunning%> <%dunning_id%>', $letter);
        $body    = dunningPlatzhalter($letter['email_body'], $letter);
        $toList  = array_values(array_filter(array_map(
            fn($e) => ['email' => trim($e), 'name' => ''], preg_split('/[,;]/', $to)
        ), fn($e) => $e['email'] !== ''));
        $ccList  = array_values(array_filter(array_map(
            fn($e) => ['email' => trim($e), 'name' => ''], preg_split('/[,;]/', $letter['customer']['cc'] ?? '')
        ), fn($e) => $e['email'] !== ''));
        $attachments = [];
        if (!empty($letter['email_attachment'])) {
            $attachments[] = ['filename' => $filename, 'content_base64' => base64_encode($pdf), 'content_type' => 'application/pdf'];
        }

        $raw = $smtp->send($from, $letter['sender_name'] ?: $letter['company'], $toList, $subject, '', $body, $ccList, [], $attachments);

        try {
            $imap = _createImapClient($config);
            $sent = $imap->findSentFolder();
            if ($sent) $imap->appendToFolder($sent, $raw);
            $imap->disconnect();
        } catch (\Throwable $e) {
            // Kopie im Gesendet-Ordner ist Komfort, kein Muss
        }
        try {
            _logToEmailJournal($from, $toList, $ccList, $subject, $body, $attachments, 'dunning');
        } catch (\Throwable $e) {
        }

        $db->execute(<<<SQL
            UPDATE dunning_ext
            SET channel = 'email', email_to = :to, sent_at = now(), error = NULL, mtime = now()
            WHERE dunning_id = :id
        SQL, [':to' => $to, ':id' => intval($letter['dunning_id'])]);
        return null;
    } catch (\Throwable $e) {
        $db->execute("UPDATE dunning_ext SET error = :err, mtime = now() WHERE dunning_id = :id",
            [':err' => $e->getMessage(), ':id' => intval($letter['dunning_id'])]);
        return $e->getMessage();
    }
}

/**
 * Bucht Gebühr und Zinsen eines Briefs als Debitorenbuchung (ar ohne
 * Positionen) auf die konfigurierten Konten — nur wenn die Stufe das will,
 * die Konten eingetragen sind und ein Betrag anfällt. Steuerfrei, wie in
 * kivitendo (Mahngebühren und Verzugszinsen sind kein Entgelt).
 *
 * @return array|null {id, invnumber, amount} oder null, wenn nichts gebucht wurde
 */
function dunningGebuehrenBuchen($db, int $dunningId, array $letter): ?array {
    $row = $db->getOne(<<<SQL
        WITH p AS (SELECT :dunning_id::int AS dunning_id, :notes::text AS notes),
        d AS (
            SELECT d.dunning_id, SUM(COALESCE(d.fee, 0)) AS fee, SUM(COALESCE(d.interest, 0)) AS interest,
                   MAX(d.duedate) AS duedate, MIN(d.trans_id) AS first_trans, MIN(d.dunning_config_id) AS config_id
            FROM dunning d, p
            WHERE d.dunning_id = p.dunning_id AND d.fee_interest_ar_id IS NULL
            GROUP BY d.dunning_id
        ),
        acct AS (
            SELECT dunning_ar_amount_fee AS fee_chart, dunning_ar_amount_interest AS interest_chart,
                   dunning_ar AS ar_chart, currency_id
            FROM defaults LIMIT 1
        ),
        ok AS (
            SELECT (d.fee + d.interest) > 0
                   AND COALESCE(cf.create_invoices_for_fees, false)
                   AND acct.ar_chart IS NOT NULL
                   AND (d.fee = 0 OR acct.fee_chart IS NOT NULL)
                   AND (d.interest = 0 OR acct.interest_chart IS NOT NULL) AS ok
            FROM d CROSS JOIN acct
            JOIN dunning_config cf ON cf.id = d.config_id
        ),
        zero_tax AS (
            SELECT COALESCE((SELECT id FROM tax WHERE taxkey = 0 AND rate = 0 ORDER BY id LIMIT 1), 0) AS id
        ),
        num AS (
            UPDATE defaults SET invnumber = COALESCE(NULLIF(invnumber, '')::int, 0) + 1
            WHERE (SELECT ok FROM ok)
            RETURNING invnumber
        ),
        ins AS (
            INSERT INTO ar (invnumber, transdate, gldate, duedate, customer_id, amount, netamount, paid,
                            taxincluded, invoice, notes, employee_id, taxzone_id, currency_id)
            SELECT (SELECT invnumber FROM num), CURRENT_DATE, CURRENT_DATE, d.duedate, a.customer_id,
                   ROUND(d.fee + d.interest, 2), ROUND(d.fee + d.interest, 2), 0,
                   false, false, p.notes,
                   -- Der Mitarbeiter des Briefs, sonst der erste im Stamm: ar.employee_id ist ein Fremdschlüssel
                   COALESCE((SELECT e.id FROM employee e WHERE e.id = x.employee_id),
                            (SELECT e.id FROM employee e ORDER BY e.id LIMIT 1)),
                   COALESCE(c.taxzone_id, (SELECT MIN(id) FROM tax_zones)),
                   COALESCE(c.currency_id, acct.currency_id)
            FROM d CROSS JOIN p CROSS JOIN acct
            JOIN ar a ON a.id = d.first_trans
            JOIN customer c ON c.id = a.customer_id
            LEFT JOIN dunning_ext x ON x.dunning_id = d.dunning_id
            WHERE (SELECT ok FROM ok)
            RETURNING id, invnumber, amount
        ),
        t_fee AS (
            INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, taxkey, tax_id)
            SELECT ins.id, acct.fee_chart, d.fee, CURRENT_DATE, CURRENT_DATE, ins.invnumber, '',
                   (SELECT link FROM chart WHERE id = acct.fee_chart), 0, (SELECT id FROM zero_tax)
            FROM ins, d, acct WHERE d.fee > 0
            RETURNING trans_id
        ),
        t_int AS (
            INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, taxkey, tax_id)
            SELECT ins.id, acct.interest_chart, d.interest, CURRENT_DATE, CURRENT_DATE, ins.invnumber, '',
                   (SELECT link FROM chart WHERE id = acct.interest_chart), 0, (SELECT id FROM zero_tax)
            FROM ins, d, acct WHERE d.interest > 0
            RETURNING trans_id
        ),
        t_ar AS (
            INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, taxkey, tax_id)
            SELECT ins.id, acct.ar_chart, -ins.amount, CURRENT_DATE, CURRENT_DATE, ins.invnumber, '',
                   'AR', 0, (SELECT id FROM zero_tax)
            FROM ins, acct
            RETURNING trans_id
        ),
        upd AS (
            UPDATE dunning SET fee_interest_ar_id = (SELECT id FROM ins), mtime = now()
            WHERE dunning_id = (SELECT dunning_id FROM p) AND EXISTS (SELECT 1 FROM ins)
            RETURNING id
        )
        SELECT ins.id, ins.invnumber, ins.amount FROM ins
    SQL, [
        ':dunning_id' => $dunningId,
        ':notes'      => 'Mahngebühren und Verzugszinsen zu ' . $letter['dunning'] . ' Nr. ' . $dunningId
                         . ' (' . implode(', ', array_column($letter['invoices'] ?? [], 'invnumber')) . ')',
    ]);

    return $row ? ['id' => intval($row['id']), 'invnumber' => $row['invnumber'], 'amount' => floatval($row['amount'])] : null;
}

// ── Mahnlauf ─────────────────────────────────────────────────────────────────

/**
 * Startet den Mahnlauf: je Brief (Kunde + Stufe) die dunning-Zeilen anlegen,
 * die Rechnungen hochstufen, Gebühren buchen, PDF erzeugen und archivieren,
 * per E-Mail verschicken — und die Briefe für den Drucker gesammelt als ein
 * PDF zurückgeben.
 *
 * Reihenfolge je Brief: erst die Datenbank (eine Transaktion), dann das PDF.
 * Scheitert LaTeX, existiert der Brief trotzdem und lässt sich aus dem
 * Verlauf erneut drucken — ein Mahnlauf darf nicht daran hängen, dass eine
 * Vorlage einen Tippfehler hat.
 *
 * @param array $data['letters'] [{customer_id, config_id, ids: [ar.id], channel: email|print|none, email}]
 * @testdata {"letters": [{"customer_id": 1, "config_id": 1, "ids": [1], "channel": "print", "email": ""}]}
 */
function createDunningRun($data) {
    $db = DbhCompany::begin();
    $employeeId = mitarbeiterId($data);
    $ctes = dunningCandidateCtes();

    $letters = (array)($data['letters'] ?? []);
    if (!$letters) throw new ApiError('VALIDATION_ERROR', 'Keine Briefe ausgewählt');

    $results  = [];
    $printPdf = [];   // Pfade der Briefe für den Sammeldruck

    foreach ($letters as $spec) {
        $customerId = intval($spec['customer_id'] ?? 0);
        $configId   = intval($spec['config_id'] ?? 0);
        $ids        = dunningIntArray($spec['ids'] ?? []);
        $channel    = in_array($spec['channel'] ?? '', ['email', 'print', 'none'], true) ? $spec['channel'] : 'print';
        $email      = trim((string)($spec['email'] ?? ''));

        $result = ['customer_id' => $customerId, 'config_id' => $configId, 'channel' => $channel,
                   'dunning_id' => null, 'invoice_count' => 0, 'pdf' => false, 'email_sent' => false,
                   'fee_invoice' => null, 'error' => null];

        if ($customerId <= 0 || $configId <= 0 || $ids === '{}') {
            $result['error'] = 'Unvollständige Angaben';
            $results[] = $result;
            continue;
        }

        // 1. Datenbank
        $db->beginTransaction();
        try {
            $row = $db->getOne(<<<SQL
                WITH p AS (
                    SELECT :customer_id::int AS customer_id, :config_id::int AS config_id,
                           :ids::int[] AS ids, :employee_id::int AS employee_id,
                           :channel::text AS channel, :email::text AS email
                ),
                {$ctes},
                sel AS (
                    -- Gesperrte Kunden nie, sonst gilt die Auswahl des Benutzers
                    SELECT cand.*
                    FROM cand, p
                    WHERE cand.customer_id = p.customer_id
                      AND cand.id = ANY(p.ids)
                      AND NOT cand.dunning_lock
                ),
                nr AS (
                    SELECT nextval('id') AS dunning_id WHERE EXISTS (SELECT 1 FROM sel)
                ),
                ins AS (
                    INSERT INTO dunning (dunning_id, dunning_config_id, dunning_level, trans_id,
                                         fee, interest, transdate, duedate)
                    SELECT nr.dunning_id, cf.id, cf.dunning_level, s.id,
                           CASE WHEN s.id = (SELECT MIN(id) FROM sel) THEN cf.fee ELSE 0 END,
                           ROUND(s.open_amount * GREATEST(s.days_overdue, 0) * cf.interest_rate / 360, 2),
                           CURRENT_DATE, CURRENT_DATE + cf.payment_terms
                    FROM sel s, nr, p
                    JOIN cfg cf ON cf.id = p.config_id
                    RETURNING trans_id, fee, interest, duedate
                ),
                upd AS (
                    UPDATE ar SET dunning_config_id = (SELECT config_id FROM p)
                    WHERE id IN (SELECT trans_id FROM ins)
                    RETURNING id
                ),
                ext AS (
                    INSERT INTO dunning_ext (dunning_id, customer_id, employee_id, channel, email_to,
                                             open_total, fee_total, interest_total)
                    SELECT nr.dunning_id, p.customer_id,
                           CASE WHEN (SELECT dunning_creator FROM defaults LIMIT 1) = 'invoice_employee'
                                THEN (SELECT employee_id FROM sel ORDER BY transdate, id LIMIT 1)
                                ELSE p.employee_id END,
                           p.channel, NULLIF(p.email, ''),
                           (SELECT SUM(open_amount) FROM sel),
                           (SELECT SUM(fee) FROM ins),
                           (SELECT SUM(interest) FROM ins)
                    FROM nr, p
                    RETURNING id
                )
                SELECT (SELECT dunning_id FROM nr)          AS dunning_id,
                       (SELECT COUNT(*) FROM ins)           AS invoice_count,
                       (SELECT COUNT(*) FROM upd)           AS updated
            SQL, [
                ':customer_id' => $customerId,
                ':config_id'   => $configId,
                ':ids'         => $ids,
                ':employee_id' => $employeeId,
                ':channel'     => $channel,
                ':email'       => $email,
            ]);

            if (!$row || intval($row['invoice_count']) === 0) {
                $db->rollBack();
                $result['error'] = 'Keine mahnbare Rechnung (bezahlt, gesperrt oder nicht überfällig)';
                $results[] = $result;
                continue;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            $result['error'] = $e->getMessage();
            $results[] = $result;
            continue;
        }

        $dunningId = intval($row['dunning_id']);
        $result['dunning_id']    = $dunningId;
        $result['invoice_count'] = intval($row['invoice_count']);

        $letter = dunningLetterData($db, 'saved', ['dunning_id' => $dunningId]);

        // 2. Gebührenrechnung (eigene Anweisung, eigene Fehlerbehandlung)
        try {
            $fee = dunningGebuehrenBuchen($db, $dunningId, $letter);
            if ($fee) $result['fee_invoice'] = $fee;
        } catch (\Throwable $e) {
            $result['error'] = 'Gebührenrechnung: ' . $e->getMessage();
        }

        // 3. PDF, Ablage, Versand
        $render = dunningRenderPdf($db, $letter);
        if (isset($render['error'])) {
            $db->execute("UPDATE dunning_ext SET error = :err, mtime = now() WHERE dunning_id = :id",
                [':err' => mb_substr($render['error'], 0, 2000), ':id' => $dunningId]);
            $result['error'] = 'PDF: ' . mb_substr($render['error'], 0, 300);
            $results[] = $result;
            continue;
        }
        $result['pdf'] = true;
        dunningArchivieren($db, $dunningId, $render['pdf'], $render['filename'], $employeeId, $letter);

        if ($channel === 'email') {
            $err = dunningEmailSenden($db, $letter, $render['pdf'], $render['filename'], $email ?: $letter['customer']['email']);
            if ($err) {
                $result['error'] = 'E-Mail: ' . $err;
                // Ohne E-Mail muss der Brief auf Papier raus
                $printPdf[] = $render['pdf'];
            } else {
                $result['email_sent'] = true;
            }
        } elseif ($channel === 'print') {
            $printPdf[] = $render['pdf'];
            $db->execute("UPDATE dunning_ext SET sent_at = now(), mtime = now() WHERE dunning_id = :id", [':id' => $dunningId]);
        }

        $results[] = $result;
    }

    // Sammel-PDF für den Drucker
    $merged = null;
    if ($printPdf) {
        $merged = dunningPdfsVereinen($printPdf);
    }

    resultInfo(true, '', ['results' => [
        'letters'  => $results,
        'created'  => count(array_filter($results, fn($r) => $r['dunning_id'])),
        'emails'   => count(array_filter($results, fn($r) => $r['email_sent'])),
        'errors'   => count(array_filter($results, fn($r) => $r['error'])),
        'pdf'      => $merged ? base64_encode($merged) : null,
        'filename' => 'Mahnlauf_' . date('Y-m-d') . '.pdf',
    ]]);
}

/**
 * Fügt mehrere PDFs zu einem zusammen (pdfunite, wie der Sammeldruck).
 */
function dunningPdfsVereinen(array $pdfs): ?string {
    if (count($pdfs) === 1) return $pdfs[0];

    $tmp = sys_get_temp_dir() . '/oserp_dunning_' . uniqid('', true);
    mkdir($tmp, 0700, true);
    $paths = [];
    foreach ($pdfs as $i => $pdf) {
        $paths[] = $tmp . '/' . $i . '.pdf';
        file_put_contents(end($paths), $pdf);
    }
    $out = $tmp . '/merged.pdf';
    exec('/usr/bin/pdfunite ' . implode(' ', array_map('escapeshellarg', $paths)) . ' ' . escapeshellarg($out) . ' 2>&1', $o, $rc);
    $merged = ($rc === 0 && is_file($out)) ? file_get_contents($out) : null;
    foreach (glob($tmp . '/*') ?: [] as $f) unlink($f);
    rmdir($tmp);

    // Notlösung: wenigstens den ersten Brief ausgeben
    return $merged ?? $pdfs[0];
}

/**
 * Vorschau eines Briefs, ohne etwas zu speichern.
 *
 * @param int   $data['customer_id'] Kunde
 * @param int   $data['config_id']   Mahnstufe
 * @param array $data['ids']         Rechnungen (ar.id)
 * @testdata {"customer_id": 1, "config_id": 1, "ids": [1], "content-type": "application/pdf"}
 */
function previewDunningPdf($data) {
    $db = DbhCompany::begin();
    $isPdf = isset($data['content-type']) && $data['content-type'] === 'application/pdf';

    $letter = dunningLetterData($db, 'preview', [
        'customer_id' => intval($data['customer_id'] ?? 0),
        'config_id'   => intval($data['config_id'] ?? 0),
        'ids'         => $data['ids'] ?? [],
        'employee_id' => mitarbeiterId($data),
    ]);
    if (!$letter) {
        if ($isPdf) header('Content-Type: application/json');
        resultInfo(false, 'NOT_FOUND', 'Keine mahnbare Rechnung in der Auswahl');
        return;
    }

    $render = dunningRenderPdf($db, $letter);
    dunningPdfAusgeben($render, $isPdf);
}

/**
 * PDF eines gespeicherten Briefs — das archivierte Versandexemplar, sonst
 * neu gerendert und dann archiviert.
 *
 * @param int $data['dunning_id'] Briefnummer
 * @testdata {"dunning_id": 1, "content-type": "application/pdf"}
 */
function getDunningPdf($data) {
    $db = DbhCompany::begin();
    $isPdf = isset($data['content-type']) && $data['content-type'] === 'application/pdf';
    $dunningId = intval($data['dunning_id'] ?? 0);

    $letter = $dunningId > 0 ? dunningLetterData($db, 'saved', ['dunning_id' => $dunningId]) : null;
    if (!$letter) {
        if ($isPdf) header('Content-Type: application/json');
        resultInfo(false, 'NOT_FOUND', 'Mahnung nicht gefunden');
        return;
    }

    $render = null;
    if (!empty($letter['document_id'])) {
        $render = dunningArchivLesen($db, intval($letter['document_id']));
    }
    if (!$render) {
        $render = dunningRenderPdf($db, $letter);
        if (!isset($render['error'])) {
            dunningArchivieren($db, $dunningId, $render['pdf'], $render['filename'], mitarbeiterId($data), $letter);
        }
    }
    dunningPdfAusgeben($render, $isPdf);
}

/**
 * Gibt ein Render-Ergebnis als PDF-Strom oder Base64-JSON aus.
 */
function dunningPdfAusgeben(array $render, bool $isPdf): void {
    if (isset($render['error'])) {
        if ($isPdf) header('Content-Type: application/json');
        resultInfo(false, 'PDF_ERROR', 'LaTeX-Kompilierung fehlgeschlagen', $render['error']);
        return;
    }
    if ($isPdf) {
        header('Content-Length: ' . strlen($render['pdf']));
        header('Content-Disposition: inline; filename="' . $render['filename'] . '"');
        echo $render['pdf'];
        return;
    }
    resultInfo(true, 'OK', ['pdf' => base64_encode($render['pdf']), 'filename' => $render['filename']]);
}

/**
 * Schickt einen gespeicherten Brief (erneut) per E-Mail.
 *
 * @param int    $data['dunning_id'] Briefnummer
 * @param string $data['email']      Empfänger (leer: Mahn-Adresse des Kunden)
 * @testdata {"dunning_id": 1, "email": ""}
 */
function sendDunningEmail($data) {
    $db = DbhCompany::begin();
    $dunningId = intval($data['dunning_id'] ?? 0);

    $letter = $dunningId > 0 ? dunningLetterData($db, 'saved', ['dunning_id' => $dunningId]) : null;
    if (!$letter) throw new ApiError('NOT_FOUND', 'Mahnung nicht gefunden');

    $render = !empty($letter['document_id']) ? dunningArchivLesen($db, intval($letter['document_id'])) : null;
    if (!$render) {
        $render = dunningRenderPdf($db, $letter);
        if (isset($render['error'])) throw new ApiError('PDF_ERROR', $render['error']);
        dunningArchivieren($db, $dunningId, $render['pdf'], $render['filename'], mitarbeiterId($data), $letter);
    }

    $to  = trim((string)($data['email'] ?? '')) ?: $letter['customer']['email'];
    $err = dunningEmailSenden($db, $letter, $render['pdf'], $render['filename'], $to);
    if ($err) throw new ApiError('EMAIL_ERROR', $err);

    resultInfo(true, '', ['results' => ['dunning_id' => $dunningId, 'email' => $to]]);
}

// ── Verlauf ──────────────────────────────────────────────────────────────────

/**
 * Verlauf der Mahnbriefe mit Kennzahlen — eine Abfrage.
 *
 * @param string $data['search']       Kunde, Kundennummer oder Rechnungsnummer
 * @param int    $data['config_id']    nur diese Stufe
 * @param string $data['from']         Mahndatum ab (YYYY-MM-DD)
 * @param string $data['to']           Mahndatum bis
 * @param bool   $data['show_settled'] auch Briefe, deren Rechnungen inzwischen bezahlt sind
 * @param int    $data['limit']        Höchstzahl (Standard 200)
 * @testdata {"search": "", "config_id": 0, "from": "", "to": "", "show_settled": true, "limit": 200}
 */
function getDunningHistory($data) {
    $db = DbhCompany::begin();

    $row = $db->getOne(<<<SQL
        WITH p AS (
            SELECT TRIM(COALESCE(:search::text, ''))  AS search,
                   :config_id::int                    AS config_id,
                   NULLIF(:from_date::text, '')::date AS from_date,
                   NULLIF(:to_date::text, '')::date   AS to_date,
                   :show_settled::bool                AS show_settled,
                   :lim::int                          AS lim
        ),
        letters AS (
            SELECT d.dunning_id,
                   MIN(d.transdate)                              AS transdate,
                   MAX(d.duedate)                                AS duedate,
                   MIN(d.dunning_config_id)                      AS config_id,
                   MIN(d.dunning_level)                          AS level,
                   MIN(a.customer_id)                            AS customer_id,
                   COUNT(*)                                      AS invoice_count,
                   SUM(COALESCE(d.fee, 0))                       AS fee,
                   SUM(COALESCE(d.interest, 0))                  AS interest,
                   SUM(a.amount - COALESCE(a.paid, 0))           AS open_now,
                   MIN(d.fee_interest_ar_id)                     AS fee_ar_id,
                   BOOL_AND(a.dunning_config_id = d.dunning_config_id) AS is_current,
                   STRING_AGG(a.invnumber, ', ' ORDER BY a.invnumber) AS invnumbers,
                   json_agg(json_build_object(
                       'id',          a.id,
                       'invnumber',   a.invnumber,
                       'amount',      ROUND(a.amount, 2),
                       'open_amount', ROUND(a.amount - COALESCE(a.paid, 0), 2),
                       'interest',    ROUND(COALESCE(d.interest, 0), 2)
                   ) ORDER BY a.invnumber)                        AS invoices
            FROM dunning d
            JOIN ar a ON a.id = d.trans_id
            GROUP BY d.dunning_id
        ),
        hit AS (
            SELECT l.*, c.name AS customer, c.customernumber, cf.dunning_description AS description,
                   x.channel, x.email_to, x.sent_at, x.document_id, x.error, x.open_total,
                   e.name AS employee,
                   fa.invnumber AS fee_invnumber, fa.amount AS fee_amount, COALESCE(fa.paid, 0) AS fee_paid
            FROM letters l CROSS JOIN p
            JOIN customer c ON c.id = l.customer_id
            LEFT JOIN dunning_config cf ON cf.id = l.config_id
            LEFT JOIN dunning_ext x ON x.dunning_id = l.dunning_id
            LEFT JOIN employee e ON e.id = x.employee_id
            LEFT JOIN ar fa ON fa.id = l.fee_ar_id
            WHERE (p.search = '' OR c.name ILIKE '%' || p.search || '%'
                   OR c.customernumber ILIKE '%' || p.search || '%'
                   OR l.invnumbers ILIKE '%' || p.search || '%'
                   OR l.dunning_id::text = p.search)
              AND (p.config_id IS NULL OR p.config_id = 0 OR l.config_id = p.config_id)
              AND (p.from_date IS NULL OR l.transdate >= p.from_date)
              AND (p.to_date IS NULL OR l.transdate <= p.to_date)
              AND (p.show_settled OR l.open_now > 0.005)
            ORDER BY l.transdate DESC, l.dunning_id DESC
            LIMIT (SELECT lim FROM p)
        )
        SELECT json_build_object(
            'items', COALESCE((
                SELECT json_agg(json_build_object(
                    'dunning_id',    h.dunning_id,
                    'transdate',     TO_CHAR(h.transdate, 'DD.MM.YYYY'),
                    'transdate_iso', TO_CHAR(h.transdate, 'YYYY-MM-DD'),
                    'duedate',       TO_CHAR(h.duedate, 'DD.MM.YYYY'),
                    'level',         h.level,
                    'config_id',     h.config_id,
                    'description',   COALESCE(h.description, ''),
                    'customer_id',   h.customer_id,
                    'customer',      h.customer,
                    'customernumber', h.customernumber,
                    'invoice_count', h.invoice_count,
                    'invnumbers',    h.invnumbers,
                    'invoices',      h.invoices,
                    'open_then',     ROUND(COALESCE(h.open_total, 0), 2),
                    'open_now',      ROUND(h.open_now, 2),
                    'fee',           ROUND(h.fee, 2),
                    'interest',      ROUND(h.interest, 2),
                    'channel',       h.channel,
                    'email_to',      h.email_to,
                    'sent_at',       TO_CHAR(h.sent_at, 'DD.MM.YYYY HH24:MI'),
                    'document_id',   h.document_id,
                    'error',         h.error,
                    'employee',      h.employee,
                    'settled',       h.open_now <= 0.005,
                    'is_current',    h.is_current,
                    'days_open',     CASE WHEN h.open_now > 0.005 THEN CURRENT_DATE - h.duedate END,
                    'fee_invoice',   CASE WHEN h.fee_ar_id IS NOT NULL THEN json_build_object(
                                         'id', h.fee_ar_id, 'invnumber', h.fee_invnumber,
                                         'amount', h.fee_amount, 'paid', h.fee_paid) END
                ) ORDER BY h.transdate DESC, h.dunning_id DESC)
                FROM hit h
            ), '[]'::json),
            'stats', (
                SELECT json_build_object(
                    'letters',       COUNT(*),
                    'open_letters',  COUNT(*) FILTER (WHERE open_now > 0.005),
                    'open_sum',      COALESCE(SUM(open_now) FILTER (WHERE open_now > 0.005), 0),
                    'fees_total',    COALESCE(SUM(fee + interest), 0),
                    'letters_year',  COUNT(*) FILTER (WHERE transdate >= DATE_TRUNC('year', CURRENT_DATE)),
                    'last_run',      TO_CHAR(MAX(transdate), 'DD.MM.YYYY'),
                    'settled_after_dunning', COUNT(*) FILTER (WHERE open_now <= 0.005)
                )
                FROM letters
            )
        ) AS result
    SQL, [
        ':search'       => (string)($data['search'] ?? ''),
        ':config_id'    => intval($data['config_id'] ?? 0),
        ':from_date'    => (string)($data['from'] ?? ''),
        ':to_date'      => (string)($data['to'] ?? ''),
        ':show_settled' => !isset($data['show_settled']) || !empty($data['show_settled']),
        ':lim'          => min(max(intval($data['limit'] ?? 200), 1), 1000),
    ]);

    resultInfo(true, '', ['results' => json_decode($row['result'], true)]);
}

/**
 * Nimmt einen Mahnbrief zurück: die Rechnungen fallen auf die Stufe davor,
 * eine noch unbezahlte Gebührenrechnung wird storniert (gelöscht), eine
 * bezahlte bleibt. Nur möglich, solange keine der Rechnungen bereits eine
 * spätere Mahnung hat. Das archivierte Versandexemplar bleibt erhalten.
 *
 * @param int $data['dunning_id'] Briefnummer
 * @testdata {"dunning_id": 1}
 */
function deleteDunning($data) {
    $db = DbhCompany::begin();
    $dunningId = intval($data['dunning_id'] ?? 0);
    if ($dunningId <= 0) throw new ApiError('VALIDATION_ERROR', 'dunning_id fehlt');

    $row = $db->getOne(<<<SQL
        WITH p AS (SELECT :dunning_id::int AS did),
        rows AS (
            SELECT d.id, d.trans_id, d.fee_interest_ar_id
            FROM dunning d, p WHERE d.dunning_id = p.did
        ),
        ok AS (
            SELECT EXISTS (SELECT 1 FROM rows)
                   AND NOT EXISTS (
                       SELECT 1 FROM rows r
                       JOIN dunning d2 ON d2.trans_id = r.trans_id AND d2.id > r.id
                       WHERE d2.dunning_id <> (SELECT did FROM p)
                   ) AS ok
        ),
        prev AS (
            SELECT r.trans_id,
                   (SELECT d2.dunning_config_id FROM dunning d2
                    WHERE d2.trans_id = r.trans_id AND d2.dunning_id <> (SELECT did FROM p)
                    ORDER BY d2.transdate DESC, d2.id DESC LIMIT 1) AS prev_cfg
            FROM rows r
            WHERE (SELECT ok FROM ok)
        ),
        upd AS (
            UPDATE ar a SET dunning_config_id = prev.prev_cfg
            FROM prev WHERE a.id = prev.trans_id
            RETURNING a.id
        ),
        fee AS (
            SELECT DISTINCT fee_interest_ar_id AS ar_id FROM rows
            WHERE fee_interest_ar_id IS NOT NULL AND (SELECT ok FROM ok)
        ),
        fee_unpaid AS (
            SELECT ar.id FROM ar JOIN fee ON fee.ar_id = ar.id WHERE COALESCE(ar.paid, 0) = 0
        ),
        del_acc AS (
            DELETE FROM acc_trans WHERE trans_id IN (SELECT id FROM fee_unpaid) RETURNING trans_id
        ),
        del_ar AS (
            DELETE FROM ar WHERE id IN (SELECT id FROM fee_unpaid) RETURNING id, invnumber
        ),
        num AS (
            -- War die Gebührenrechnung die letzte Nummer des Kreises, gibt sie
            -- die Nummer zurück (wie deleteFaktura) — sonst bliebe eine Lücke.
            UPDATE defaults SET invnumber = (invnumber::int - 1)::text
            WHERE invnumber = (SELECT MAX(invnumber) FROM del_ar)
              AND invnumber ~ '^[0-9]+$'
            RETURNING invnumber
        ),
        del_ext AS (
            DELETE FROM dunning_ext WHERE dunning_id = (SELECT did FROM p) AND (SELECT ok FROM ok) RETURNING id
        ),
        del_d AS (
            DELETE FROM dunning WHERE dunning_id = (SELECT did FROM p) AND (SELECT ok FROM ok) RETURNING id
        )
        SELECT (SELECT ok FROM ok)                                           AS ok,
               (SELECT COUNT(*) FROM del_d)                                  AS deleted,
               (SELECT COUNT(*) FROM del_ar)                                 AS fee_deleted,
               (SELECT COUNT(*) FROM fee) - (SELECT COUNT(*) FROM fee_unpaid) AS fee_kept
    SQL, [':dunning_id' => $dunningId]);

    if (empty($row['ok']) || $row['ok'] === 'f' || $row['ok'] === false) {
        throw new ApiError('DUNNING_NOT_DELETABLE', 'Mindestens eine Rechnung hat bereits eine spätere Mahnung — bitte zuerst diese zurücknehmen');
    }

    resultInfo(true, '', ['results' => [
        'dunning_id'  => $dunningId,
        'deleted'     => intval($row['deleted']),
        'fee_deleted' => intval($row['fee_deleted']),
        'fee_kept'    => intval($row['fee_kept']),
    ]]);
}

/**
 * Mahnsperre eines Kunden setzen oder aufheben (customer.dunning_lock).
 *
 * @param int  $data['customer_id'] Kunde
 * @param bool $data['locked']      true = sperren
 * @testdata {"customer_id": 1, "locked": true}
 */
function setCustomerDunningLock($data) {
    $db = DbhCompany::begin();
    $customerId = intval($data['customer_id'] ?? 0);
    if ($customerId <= 0) throw new ApiError('VALIDATION_ERROR', 'customer_id fehlt');

    $row = $db->getOne(
        "UPDATE customer SET dunning_lock = :locked WHERE id = :id RETURNING id, name, dunning_lock",
        [':locked' => !empty($data['locked']), ':id' => $customerId]
    );
    if (!$row) throw new ApiError('NOT_FOUND', 'Kunde nicht gefunden');

    resultInfo(true, '', ['results' => [
        'customer_id' => $customerId,
        'name'        => $row['name'],
        'locked'      => $row['dunning_lock'] === true || $row['dunning_lock'] === 't',
    ]]);
}
