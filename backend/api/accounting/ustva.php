<?php
// backend/api/accounting/ustva.php
//
// Umsatzsteuer-Voranmeldung.
//
// Die Kennzahlen werden NICHT aus den Konten-Stammdaten geschaetzt, sondern aus
// den tatsaechlich gebuchten acc_trans-Zeilen ermittelt. Jede Zeile wird ueber
// Steuerschluessel + Steuersatz (optional ueber das Konto) einer Kennzahl
// zugeordnet; die Zuordnung steht in `ustva_mapping` und ist in der
// Oberflaeche editierbar.
//
// Was sich nicht zuordnen laesst, verschwindet nicht — es landet sichtbar im
// Block "nicht zugeordnet". Eine Voranmeldung, bei der stillschweigend Betraege
// fehlen, waere gefaehrlicher als gar keine.
//
// Zwei Besteuerungsarten:
//   accrual (Soll-Versteuerung) — Buchungsdatum entscheidet.
//   cash    (Ist-Versteuerung)  — der Zahlungszeitpunkt entscheidet; die
//                                 Zahlung wird anteilig auf die Steuerstruktur
//                                 der zugehoerigen Rechnung verteilt.

/**
 * Gemeinsames SQL: alle steuerlich relevanten Buchungszeilen eines Zeitraums,
 * bereits einer Kennzahl zugeordnet und vorzeichenrichtig normiert.
 *
 * Liefert die Spalten:
 *   kz, role, direction, taxkey, rate, chart_id, accno, chart_name,
 *   trans_id, transdate, doctype, reference, partner, signed
 *
 * @param string $method accrual|cash
 * @return string SQL mit den Platzhaltern :date_from und :date_to
 */
function _ustva_linesSql($method) {
    // Steuerkonto -> Steuerschluessel/-satz. Ein Steuerkonto kann von mehreren
    // Schluesseln benutzt werden (z. B. 16 % unter 7 und 9); fuer die
    // Kennzahl-Zuordnung ist das gleichwertig.
    $common = <<<SQL
        taxchart AS (
            SELECT chart_id, MIN(taxkey) AS taxkey, MAX(rate) AS rate
            FROM tax WHERE chart_id IS NOT NULL GROUP BY chart_id
        ),
        doc AS (
            SELECT id, 'revenue'::text AS direction, invnumber AS reference, transdate, 'ar'::text AS doctype FROM ar
            UNION ALL
            SELECT id, 'expense'::text,              invnumber,               transdate, 'ap'::text        FROM ap
        ),
        classified AS (
            SELECT
                a.acc_trans_id, a.trans_id, a.transdate, a.amount, a.chart_id, a.taxkey AS booked_taxkey,
                c.accno, c.description AS chart_name, c.link,
                CASE
                    WHEN c.link ~ '(^|:)(AR|AP)_amount(:|\$)' THEN 'base'
                    WHEN c.link ~ '(^|:)(AR|AP)_tax(:|\$)'    THEN 'tax'
                END AS role,
                COALESCE(d.direction,
                    CASE WHEN c.link ~ '(^|:)AR_(amount|tax)(:|\$)' THEN 'revenue' ELSE 'expense' END
                ) AS direction,
                COALESCE(d.doctype, 'gl')   AS doctype,
                COALESCE(d.reference, g.reference) AS reference
            FROM acc_trans a
            JOIN chart c      ON c.id = a.chart_id
            LEFT JOIN doc d   ON d.id = a.trans_id
            LEFT JOIN gl g    ON g.id = a.trans_id
            WHERE c.link ~ '(^|:)(AR|AP)_(amount|tax)(:|\$)'
              AND a.amount <> 0
        ),
        keyed AS (
            SELECT
                cl.*,
                CASE WHEN cl.role = 'tax' THEN tc.taxkey ELSE cl.booked_taxkey END AS taxkey,
                CASE WHEN cl.role = 'tax' THEN tc.rate   ELSE tk.rate           END AS rate
            FROM classified cl
            LEFT JOIN taxchart tc ON tc.chart_id = cl.chart_id
            LEFT JOIN LATERAL (
                -- Steuersatz, der fuer dieses Konto am Buchungstag hinterlegt war
                SELECT t.rate
                FROM taxkeys k JOIN tax t ON t.id = k.tax_id
                WHERE k.chart_id = cl.chart_id AND k.startdate <= cl.transdate
                ORDER BY k.startdate DESC
                LIMIT 1
            ) tk ON cl.role = 'base'
        ),
        mapped AS (
            SELECT k.*, m.kz
            FROM keyed k
            LEFT JOIN LATERAL (
                SELECT mm.kz FROM ustva_mapping mm
                WHERE mm.role = k.role
                  AND mm.direction = k.direction
                  AND (mm.chart_id IS NULL OR mm.chart_id = k.chart_id)
                  AND (mm.taxkey   IS NULL OR mm.taxkey   = k.taxkey)
                  AND (mm.rate     IS NULL OR mm.rate     = k.rate)
                ORDER BY (mm.chart_id IS NOT NULL) DESC,
                         (mm.taxkey   IS NOT NULL) DESC,
                         (mm.rate     IS NOT NULL) DESC
                LIMIT 1
            ) m ON true
        )
    SQL;

    if ($method === 'cash') {
        // Ist-Versteuerung: massgeblich ist die Zahlung. Je Zahlung im Zeitraum
        // wird der Anteil am Bruttobetrag der Rechnung ermittelt und die
        // Steuerstruktur der Rechnung mit diesem Anteil angesetzt.
        return <<<SQL
            WITH $common,
            gross AS (
                SELECT id, direction, NULLIF(amount, 0) AS amount FROM (
                    SELECT id, 'revenue'::text AS direction, amount FROM ar
                    UNION ALL
                    SELECT id, 'expense'::text,              amount FROM ap
                ) g
            ),
            payments AS (
                SELECT
                    a.trans_id,
                    a.transdate AS pay_date,
                    SUM(CASE WHEN gr.direction = 'revenue' THEN -a.amount ELSE a.amount END) AS paid
                FROM acc_trans a
                JOIN chart c  ON c.id = a.chart_id
                JOIN gross gr ON gr.id = a.trans_id
                WHERE c.link ~ '(^|:)(AR|AP)_paid(:|\$)'
                  AND c.category IN ('A', 'L')          -- nur Geldkonten, keine Skonto-/Erloeskonten
                  AND a.transdate BETWEEN :date_from::date AND :date_to::date
                GROUP BY a.trans_id, a.transdate
            )
            SELECT
                m.kz, m.role, m.direction, m.taxkey, m.rate, m.chart_id, m.accno, m.chart_name,
                m.trans_id, p.pay_date AS transdate, m.doctype, m.reference,
                ROUND(
                    (CASE WHEN m.direction = 'revenue' THEN m.amount ELSE -m.amount END)
                    * (p.paid / gr.amount)
                , 2) AS signed,
                ROUND(p.paid, 2)   AS paid,
                ROUND(gr.amount, 2) AS gross
            FROM mapped m
            JOIN payments p ON p.trans_id = m.trans_id
            JOIN gross gr   ON gr.id      = m.trans_id
            WHERE gr.amount IS NOT NULL
        SQL;
    }

    // Soll-Versteuerung: das Buchungsdatum entscheidet.
    return <<<SQL
        WITH $common
        SELECT
            m.kz, m.role, m.direction, m.taxkey, m.rate, m.chart_id, m.accno, m.chart_name,
            m.trans_id, m.transdate, m.doctype, m.reference,
            ROUND(CASE WHEN m.direction = 'revenue' THEN m.amount ELSE -m.amount END, 2) AS signed,
            NULL::numeric AS paid,
            NULL::numeric AS gross
        FROM mapped m
        WHERE m.transdate BETWEEN :date_from::date AND :date_to::date
    SQL;
}

/**
 * Besteuerungsart bestimmen: Vorgabe aus der Firmenkonfiguration, per Parameter
 * uebersteuerbar (fuer den Vergleich Soll/Ist in der Oberflaeche).
 */
function _ustva_method($db, $data) {
    if (isset($data['method']) && in_array($data['method'], ['accrual', 'cash'], true)) {
        return $data['method'];
    }
    $row = $db->getOne("SELECT accounting_method FROM defaults LIMIT 1");
    return ($row && $row['accounting_method'] === 'cash') ? 'cash' : 'accrual';
}

/**
 * Zeitraum aus Jahr + Periodenangabe aufloesen.
 *
 * @return array [start, end, type, label]
 */
function _ustva_period($year, $period) {
    $year = intval($year) ?: intval(date('Y'));
    $period = strtolower(trim((string)$period));

    if ($period === 'year' || $period === '') {
        return [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), 'year', (string)$year];
    }
    if (preg_match('/^q([1-4])$/', $period, $m)) {
        $q = intval($m[1]);
        $startMonth = ($q - 1) * 3 + 1;
        $start = sprintf('%04d-%02d-01', $year, $startMonth);
        $end   = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $startMonth + 2)));
        return [$start, $end, 'quarter', sprintf('%d. Quartal %d', $q, $year)];
    }
    $month = max(1, min(12, intval($period)));
    $start = sprintf('%04d-%02d-01', $year, $month);
    return [$start, date('Y-m-t', strtotime($start)), 'month', date('m/Y', strtotime($start))];
}

/**
 * Abgabefrist: der 10. des Folgemonats, mit Dauerfristverlaengerung einen Monat
 * spaeter. Faellt der Tag auf ein Wochenende, verschiebt sich die Frist auf den
 * naechsten Werktag.
 */
function _ustva_dueDate($periodEnd, $extension) {
    $months = $extension ? 2 : 1;
    $due = new DateTime($periodEnd);
    $due->modify('first day of next month');
    if ($months === 2) $due->modify('first day of next month');
    $due->setDate((int)$due->format('Y'), (int)$due->format('m'), 10);

    $dow = (int)$due->format('N');
    if ($dow === 6) $due->modify('+2 days');
    if ($dow === 7) $due->modify('+1 day');

    return $due->format('Y-m-d');
}

/**
 * Status eines Zeitraums fuer den Zeitstrahl.
 *
 *   filed    — abgegeben
 *   future   — hat noch nicht begonnen
 *   current  — laeuft gerade (Zahlen aendern sich noch)
 *   overdue  — Abgabefrist verstrichen
 *   open     — abgeschlossen, Frist laeuft noch
 */
function _ustva_status($filedAt, $start, $end, $due, $today) {
    if ($filedAt)        return 'filed';
    if ($start > $today) return 'future';
    if ($end >= $today)  return 'current';
    if ($due < $today)   return 'overdue';
    return 'open';
}

/**
 * Ist die Dauerfristverlaengerung aktiv? (defaults_oserp, Schluessel ustva_permanent_extension)
 */
function _ustva_hasExtension($db) {
    $row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'ustva_permanent_extension'");
    return $row && in_array(strtolower((string)$row['value']), ['1', 't', 'true', 'yes'], true);
}

/**
 * Umsatzsteuer-Voranmeldung eines Zeitraums berechnen.
 *
 * Liefert alle Kennzahlen mit Betrag, die Summen (Umsatzsteuer, Vorsteuer,
 * Zahllast), die nicht zugeordneten Betraege und die Plausibilitaetspruefungen.
 *
 * @param int    $data['year']   Jahr, z. B. 2026
 * @param string $data['period'] Monat (1..12), Quartal (q1..q4) oder "year"
 * @param string $data['method'] accrual|cash — ohne Angabe die Vorgabe des Mandanten
 * @testdata {"year": 2026, "period": "7"}
 */
function getUstva($data) {
    $db = DbhCompany::begin();

    $method = _ustva_method($db, $data);
    [$start, $end, $type, $label] = _ustva_period($data['year'] ?? date('Y'), $data['period'] ?? date('n'));
    $lines = _ustva_linesSql($method);

    $query = <<<SQL
        WITH lines AS ($lines),
        per_kz AS (
            SELECT
                kz,
                -- Die Bemessungsgrundlage kommt von den Erloes-/Aufwandszeilen,
                -- der Steuerbetrag vom Steuerkonto. Beides getrennt zu fuehren
                -- erlaubt den Abgleich "gerechnete vs. gebuchte Steuer".
                COALESCE(SUM(signed) FILTER (WHERE role = 'base'), 0) AS base_amount,
                COALESCE(SUM(signed) FILTER (WHERE role = 'tax'),  0) AS tax_amount,
                COUNT(*) AS entries
            FROM lines WHERE kz IS NOT NULL GROUP BY kz
        ),
        rows AS (
            SELECT
                k.kz, k.label, k.section, k.kind, k.rate, k.sortkey,
                COALESCE(p.entries, 0) AS entries,
                -- Bewusst ausgenommene Betraege (durchlaufende Posten) sind zwar
                -- zugeordnet, gehoeren aber in keine Formularzeile.
                (k.section = 'ignoriert') AS excluded,
                -- Der gemeldete Wert einer Kennzahl ist je nach Art die
                -- Bemessungsgrundlage oder der Steuerbetrag.
                CASE WHEN k.kind = 'base' THEN COALESCE(p.base_amount, 0)
                     ELSE COALESCE(p.tax_amount, 0) END::numeric AS amount,
                COALESCE(p.tax_amount, 0)::numeric AS booked_tax,
                -- Bemessungsgrundlagen meldet man ohne Cent (zur Null hin
                -- gekuerzt), Steuerbetraege mit Cent.
                CASE WHEN k.kind = 'base' THEN TRUNC(COALESCE(p.base_amount, 0))
                     ELSE ROUND(COALESCE(p.tax_amount, 0), 2) END AS reported,
                CASE WHEN k.kind = 'base' AND k.rate IS NOT NULL
                     THEN ROUND(TRUNC(COALESCE(p.base_amount, 0)) * k.rate, 2)
                     ELSE NULL END AS computed_tax
            FROM ustva_kennzahlen k
            LEFT JOIN per_kz p ON p.kz = k.kz
        ),
        unmapped AS (
            SELECT
                direction, role, taxkey, rate, chart_id, accno, chart_name,
                SUM(signed) AS amount, COUNT(*) AS entries
            FROM lines WHERE kz IS NULL
            GROUP BY direction, role, taxkey, rate, chart_id, accno, chart_name
            HAVING SUM(signed) <> 0
        ),
        totals AS (
            SELECT
                COALESCE(SUM(CASE WHEN section IN ('umsatz','erwerb','reverse','steuer')
                                  THEN COALESCE(computed_tax, CASE WHEN kind = 'tax' THEN reported END)
                             END), 0) AS vat_out,
                COALESCE(SUM(CASE WHEN section = 'vorsteuer' THEN reported END), 0) AS vat_in,
                COALESCE(SUM(CASE WHEN kz = 39 THEN reported END), 0)               AS prepayment
            FROM rows
        )
        SELECT json_build_object(
            'rows',     COALESCE((SELECT json_agg(r ORDER BY r.sortkey) FROM rows r WHERE NOT r.excluded), '[]'::json),
            'excluded', COALESCE((SELECT json_agg(r) FROM rows r WHERE r.excluded AND r.entries > 0), '[]'::json),
            'unmapped', COALESCE((SELECT json_agg(u ORDER BY ABS(u.amount) DESC) FROM unmapped u), '[]'::json),
            'totals',   (SELECT row_to_json(t) FROM (
                            SELECT vat_out, vat_in, prepayment,
                                   ROUND(vat_out - vat_in - prepayment, 2) AS payable
                            FROM totals
                        ) t),
            'booked_tax', (SELECT json_build_object(
                            'out', COALESCE(SUM(signed) FILTER (WHERE role = 'tax' AND direction = 'revenue'), 0),
                            'in',  COALESCE(SUM(signed) FILTER (WHERE role = 'tax' AND direction = 'expense'), 0)
                          ) FROM lines),
            'no_taxkey', (SELECT json_build_object(
                            'entries', COUNT(*),
                            'amount',  COALESCE(SUM(signed), 0)
                          ) FROM lines WHERE role = 'base' AND COALESCE(taxkey, 0) = 0 AND kz IS NULL)
        ) AS result
    SQL;

    $row = $db->getOne($query, [':date_from' => $start, ':date_to' => $end]);
    $result = json_decode($row['result'] ?? '{}', true);

    // Abgabestatus und spaetere Buchungen — beides braucht die Filing-Zeile.
    $filing = $db->getOne(
        "SELECT id, filed_at, vat_payable, note, method
         FROM ustva_filings WHERE period_start = :s AND period_end = :e",
        [':s' => $start, ':e' => $end]
    );

    $lateBookings = 0;
    if ($filing && $filing['filed_at']) {
        $late = $db->getOne(
            "SELECT COUNT(*) AS n
             FROM acc_trans a JOIN chart c ON c.id = a.chart_id
             WHERE c.link ~ '(^|:)(AR|AP)_(amount|tax)(:|\$)'
               AND a.transdate BETWEEN :s::date AND :e::date
               AND a.itime > :filed_at::timestamp",
            [':s' => $start, ':e' => $end, ':filed_at' => $filing['filed_at']]
        );
        $lateBookings = intval($late['n']);
    }

    $extension = _ustva_hasExtension($db);

    resultInfo(true, '', ['results' => array_merge($result, [
        'period' => [
            'year'      => intval($data['year'] ?? date('Y')),
            'period'    => (string)($data['period'] ?? date('n')),
            'start'     => $start,
            'end'       => $end,
            'type'      => $type,
            'label'     => $label,
            'due_date'  => _ustva_dueDate($end, $extension),
            'extension' => $extension,
        ],
        'method'        => $method,
        'filing'        => $filing ?: null,
        'late_bookings' => $lateBookings,
    ])]);
}

/**
 * Jahresuebersicht: alle Monate und Quartale mit Zahllast und Abgabestatus.
 *
 * Basis fuer den Zeitstrahl in der Oberflaeche — man sieht auf einen Blick,
 * welche Zeitraeume offen, faellig oder abgegeben sind.
 *
 * @param int    $data['year']   Jahr
 * @param string $data['method'] accrual|cash (optional)
 * @testdata {"year": 2026}
 */
function getUstvaYear($data) {
    $db = DbhCompany::begin();

    $year   = intval($data['year'] ?? date('Y')) ?: intval(date('Y'));
    $method = _ustva_method($db, $data);
    $lines  = _ustva_linesSql($method);

    // Ein Aufruf, eine Abfrage: die Zeilen des ganzen Jahres werden einmal
    // ermittelt und dann je Monat aufsummiert.
    $query = <<<SQL
        WITH lines AS ($lines),
        monthly AS (
            SELECT
                EXTRACT(MONTH FROM l.transdate)::int AS month,
                k.section, k.kind, k.rate, k.kz,
                COALESCE(SUM(l.signed) FILTER (WHERE l.role = 'base'), 0) AS base_amount,
                COALESCE(SUM(l.signed) FILTER (WHERE l.role = 'tax'),  0) AS tax_amount
            FROM lines l
            JOIN ustva_kennzahlen k ON k.kz = l.kz
            GROUP BY 1, k.section, k.kind, k.rate, k.kz
        ),
        per_month AS (
            SELECT
                month,
                COALESCE(SUM(CASE WHEN section IN ('umsatz','erwerb','reverse','steuer')
                                  THEN CASE WHEN kind = 'base' AND rate IS NOT NULL THEN ROUND(TRUNC(base_amount) * rate, 2)
                                            WHEN kind = 'tax' THEN ROUND(tax_amount, 2) END
                             END), 0) AS vat_out,
                COALESCE(SUM(CASE WHEN section = 'vorsteuer' THEN ROUND(tax_amount, 2) END), 0) AS vat_in
            FROM monthly GROUP BY month
        ),
        months AS (SELECT generate_series(1, 12) AS month)
        SELECT json_agg(json_build_object(
            'month',   m.month,
            'vat_out', COALESCE(p.vat_out, 0),
            'vat_in',  COALESCE(p.vat_in, 0),
            'payable', ROUND(COALESCE(p.vat_out, 0) - COALESCE(p.vat_in, 0), 2)
        ) ORDER BY m.month) AS result
        FROM months m LEFT JOIN per_month p ON p.month = m.month
    SQL;

    $row = $db->getOne($query, [
        ':date_from' => sprintf('%04d-01-01', $year),
        ':date_to'   => sprintf('%04d-12-31', $year),
    ]);
    $months = json_decode($row['result'] ?? '[]', true) ?: [];

    $filings = $db->getAll(
        "SELECT period_start, period_end, period_type, filed_at, vat_payable
         FROM ustva_filings
         WHERE period_start >= :s::date AND period_end <= :e::date",
        [':s' => sprintf('%04d-01-01', $year), ':e' => sprintf('%04d-12-31', $year)]
    );
    $filedByRange = [];
    foreach ($filings as $f) $filedByRange[$f['period_start'].'_'.$f['period_end']] = $f;

    $extension = _ustva_hasExtension($db);
    $today     = date('Y-m-d');

    // Monate anreichern
    foreach ($months as &$m) {
        [$s, $e, , $label] = _ustva_period($year, $m['month']);
        $key = $s.'_'.$e;
        $m['start']    = $s;
        $m['end']      = $e;
        $m['label']    = $label;
        $m['due_date'] = _ustva_dueDate($e, $extension);
        $m['filed_at'] = $filedByRange[$key]['filed_at'] ?? null;
        $m['status']   = _ustva_status($m['filed_at'], $s, $e, $m['due_date'], $today);
    }
    unset($m);

    // Quartale aus den Monaten aggregieren
    $quarters = [];
    for ($q = 1; $q <= 4; $q++) {
        [$s, $e, , $label] = _ustva_period($year, 'q'.$q);
        $slice = array_slice($months, ($q - 1) * 3, 3);
        $out = array_sum(array_column($slice, 'vat_out'));
        $in  = array_sum(array_column($slice, 'vat_in'));
        $key = $s.'_'.$e;
        $filedAt = $filedByRange[$key]['filed_at'] ?? null;
        $due = _ustva_dueDate($e, $extension);
        $quarters[] = [
            'quarter'  => $q,
            'start'    => $s,
            'end'      => $e,
            'label'    => $label,
            'vat_out'  => round($out, 2),
            'vat_in'   => round($in, 2),
            'payable'  => round($out - $in, 2),
            'due_date' => $due,
            'filed_at' => $filedAt,
            'status'   => _ustva_status($filedAt, $s, $e, $due, $today),
        ];
    }

    resultInfo(true, '', ['results' => [
        'year'      => $year,
        'method'    => $method,
        'extension' => $extension,
        'months'    => $months,
        'quarters'  => $quarters,
        'total'     => [
            'vat_out' => round(array_sum(array_column($months, 'vat_out')), 2),
            'vat_in'  => round(array_sum(array_column($months, 'vat_in')), 2),
            'payable' => round(array_sum(array_column($months, 'payable')), 2),
        ],
    ]]);
}

/**
 * Einzelbuchungen hinter einer Kennzahl — der Nachweis, wie die Zahl zustande kommt.
 *
 * @param int    $data['year']   Jahr
 * @param string $data['period'] Monat, Quartal (q1..q4) oder "year"
 * @param int    $data['kz']     Kennzahl; 0 = alle nicht zugeordneten Zeilen
 * @param string $data['method'] accrual|cash (optional)
 * @testdata {"year": 2026, "period": "7", "kz": 81}
 */
function getUstvaDetails($data) {
    $db = DbhCompany::begin();

    $method = _ustva_method($db, $data);
    [$start, $end] = _ustva_period($data['year'] ?? date('Y'), $data['period'] ?? date('n'));
    $kz = intval($data['kz'] ?? 0);
    $lines = _ustva_linesSql($method);

    // Bemessungsgrundlage und Steuer getrennt ausweisen — zusammengezaehlt
    // ergaebe sich eine Zahl, die zu keiner Kennzahl passt.
    $query = <<<SQL
        WITH lines AS ($lines),
        picked AS (
            SELECT * FROM lines WHERE (:kz = 0 AND kz IS NULL) OR kz = :kz
        )
        SELECT json_build_object(
            'total',      (SELECT COALESCE(SUM(signed), 0) FROM picked),
            'base_total', (SELECT COALESCE(SUM(signed) FILTER (WHERE role = 'base'), 0) FROM picked),
            'tax_total',  (SELECT COALESCE(SUM(signed) FILTER (WHERE role = 'tax'),  0) FROM picked),
            'entries',    (SELECT COUNT(*) FROM picked),
            'items', COALESCE((
                SELECT json_agg(x ORDER BY x.transdate, x.trans_id, x.role DESC)
                FROM (
                    SELECT
                        l.trans_id, l.transdate, l.doctype, l.reference,
                        l.accno, l.chart_name, l.taxkey, l.rate, l.role, l.direction,
                        l.signed, l.paid, l.gross
                    FROM picked l
                    ORDER BY l.transdate, l.trans_id, l.role DESC
                    LIMIT 500
                ) x
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query, [':date_from' => $start, ':date_to' => $end, ':kz' => $kz]);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}

/**
 * Voranmeldung als abgegeben markieren.
 *
 * Der Kennzahlen-Stand wird als Momentaufnahme gesichert. Spaetere Buchungen im
 * selben Zeitraum aendern die abgegebenen Zahlen dadurch nicht mehr, werden
 * aber in der Oberflaeche als Hinweis auf eine noetige Berichtigung angezeigt.
 *
 * @param int    $data['year']        Jahr
 * @param string $data['period']      Monat, Quartal (q1..q4) oder "year"
 * @param array  $data['payload']     Kennzahlen-Momentaufnahme aus der Oberflaeche
 * @param float  $data['vat_payable'] Zahllast (Kennzahl 83)
 * @param string $data['note']        Bemerkung (optional)
 * @param int    $data['employee_id'] Mitarbeiter aus dem Frontend-Store
 * @testdata {"year": 2026, "period": "7", "vat_payable": 0, "note": "Test"}
 */
function fileUstva($data) {
    $db = DbhCompany::begin();

    $method = _ustva_method($db, $data);
    [$start, $end, $type] = _ustva_period($data['year'] ?? date('Y'), $data['period'] ?? date('n'));

    $payload    = isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE) : null;
    $vatPayable = isset($data['vat_payable']) ? round(floatval($data['vat_payable']), 2) : null;
    $note       = trim($data['note'] ?? '') ?: null;
    $employeeId = intval($data['employee_id'] ?? 0) ?: null;

    $row = $db->getOne(
        "INSERT INTO ustva_filings
            (period_start, period_end, period_type, method, payload, vat_payable, filed_at, filed_by, note)
         VALUES (:s, :e, :t, :m, :payload::jsonb, :vat, NOW(), :emp, :note)
         ON CONFLICT (period_start, period_end) DO UPDATE SET
            period_type = EXCLUDED.period_type,
            method      = EXCLUDED.method,
            payload     = EXCLUDED.payload,
            vat_payable = EXCLUDED.vat_payable,
            filed_at    = NOW(),
            filed_by    = EXCLUDED.filed_by,
            note        = EXCLUDED.note,
            mtime       = NOW()
         RETURNING id, filed_at",
        [':s' => $start, ':e' => $end, ':t' => $type, ':m' => $method,
         ':payload' => $payload, ':vat' => $vatPayable, ':emp' => $employeeId, ':note' => $note]
    );

    resultInfo(true, '', ['id' => intval($row['id']), 'filed_at' => $row['filed_at']]);
}

/**
 * Abgabe zuruecknehmen — der Zeitraum ist danach wieder offen.
 *
 * @param int    $data['year']   Jahr
 * @param string $data['period'] Monat, Quartal (q1..q4) oder "year"
 * @testdata {"year": 2026, "period": "7"}
 */
function reopenUstva($data) {
    $db = DbhCompany::begin();
    [$start, $end] = _ustva_period($data['year'] ?? date('Y'), $data['period'] ?? date('n'));

    $db->execute(
        "DELETE FROM ustva_filings WHERE period_start = :s AND period_end = :e",
        [':s' => $start, ':e' => $end]
    );
    resultInfo(true, '', ['period_start' => $start, 'period_end' => $end]);
}

/**
 * Zuordnungstabelle laden: Kennzahlen, bestehende Zuordnungen und alle in den
 * Buchungen tatsaechlich vorkommenden Steuerschluessel.
 *
 * Die letzte Liste macht sichtbar, wofuer noch eine Zuordnung fehlt.
 *
 * @testdata {}
 */
function getUstvaMapping($data) {
    $db = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'kennzahlen', COALESCE((
                SELECT json_agg(k ORDER BY k.sortkey) FROM ustva_kennzahlen k
            ), '[]'::json),
            'mapping', COALESCE((
                SELECT json_agg(x ORDER BY x.direction, x.role, x.taxkey, x.rate)
                FROM (
                    SELECT m.*, c.accno, k.label AS kz_label
                    FROM ustva_mapping m
                    LEFT JOIN chart c            ON c.id = m.chart_id
                    LEFT JOIN ustva_kennzahlen k ON k.kz = m.kz
                ) x
            ), '[]'::json),
            'taxkeys', COALESCE((
                SELECT json_agg(x ORDER BY x.taxkey, x.rate)
                FROM (
                    SELECT DISTINCT t.taxkey, t.rate, t.taxdescription, c.accno, c.description AS chart_name
                    FROM tax t LEFT JOIN chart c ON c.id = t.chart_id
                ) x
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}

/**
 * Eine Zuordnung anlegen oder aendern.
 *
 * @param int    $data['id']        Zuordnungs-ID (0 = neu)
 * @param int    $data['taxkey']    Steuerschluessel (leer = beliebig)
 * @param float  $data['rate']      Steuersatz (leer = beliebig)
 * @param int    $data['chart_id']  Konto (leer = alle Konten)
 * @param string $data['role']      base|tax
 * @param string $data['direction'] revenue|expense
 * @param int    $data['kz']        Ziel-Kennzahl
 * @param string $data['description'] Beschreibung
 * @testdata {"taxkey": 3, "rate": 0.19, "role": "base", "direction": "revenue", "kz": 81, "description": "Umsatzsteuer 19 %"}
 */
function saveUstvaMapping($data) {
    $db = DbhCompany::begin();

    $id        = intval($data['id'] ?? 0);
    $role      = $data['role'] ?? '';
    $direction = $data['direction'] ?? '';
    $kz        = intval($data['kz'] ?? 0);

    if (!in_array($role, ['base', 'tax'], true))            { resultInfo(false, 'VALIDATION_ERROR', 'Rolle muss base oder tax sein'); return; }
    if (!in_array($direction, ['revenue', 'expense'], true)) { resultInfo(false, 'VALIDATION_ERROR', 'Richtung muss revenue oder expense sein'); return; }
    // Kennzahl 0 ist zulaessig: "gehoert bewusst nicht in die Voranmeldung".
    if ($kz < 0 || !isset($data['kz']) || $data['kz'] === '') { resultInfo(false, 'VALIDATION_ERROR', 'Kennzahl fehlt'); return; }

    $exists = $db->getOne("SELECT kz FROM ustva_kennzahlen WHERE kz = :kz", [':kz' => $kz]);
    if (!$exists) { resultInfo(false, 'UNKNOWN_KZ', 'Unbekannte Kennzahl'); return; }

    $params = [
        ':taxkey'      => isset($data['taxkey'])   && $data['taxkey']   !== '' ? intval($data['taxkey'])   : null,
        ':rate'        => isset($data['rate'])     && $data['rate']     !== '' ? floatval($data['rate'])   : null,
        ':chart_id'    => isset($data['chart_id']) && $data['chart_id'] !== '' ? intval($data['chart_id']) : null,
        ':role'        => $role,
        ':direction'   => $direction,
        ':kz'          => $kz,
        ':description' => trim($data['description'] ?? '') ?: null,
    ];

    if ($id > 0) {
        $row = $db->getOne(
            "UPDATE ustva_mapping SET taxkey = :taxkey, rate = :rate, chart_id = :chart_id,
                 role = :role, direction = :direction, kz = :kz, description = :description, mtime = NOW()
             WHERE id = :id RETURNING id",
            $params + [':id' => $id]
        );
        if (!$row) { resultInfo(false, 'NOT_FOUND', 'Zuordnung nicht gefunden'); return; }
    } else {
        $row = $db->getOne(
            "INSERT INTO ustva_mapping (taxkey, rate, chart_id, role, direction, kz, description)
             VALUES (:taxkey, :rate, :chart_id, :role, :direction, :kz, :description)
             RETURNING id",
            $params
        );
    }

    resultInfo(true, '', ['id' => intval($row['id'])]);
}

/**
 * Eine Zuordnung entfernen.
 *
 * @param int $data['id'] Zuordnungs-ID
 * @testdata {"id": 1}
 */
function deleteUstvaMapping($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }

    $db->execute("DELETE FROM ustva_mapping WHERE id = :id", [':id' => $id]);
    resultInfo(true, '', ['id' => $id]);
}

/**
 * Voranmeldung als CSV ausgeben (Base64), z. B. fuer den Steuerberater.
 *
 * @param int    $data['year']   Jahr
 * @param string $data['period'] Monat, Quartal (q1..q4) oder "year"
 * @param string $data['method'] accrual|cash (optional)
 * @testdata {"year": 2026, "period": "7"}
 */
function exportUstvaCsv($data) {
    $db = DbhCompany::begin();

    $method = _ustva_method($db, $data);
    [$start, $end, , $label] = _ustva_period($data['year'] ?? date('Y'), $data['period'] ?? date('n'));
    $lines = _ustva_linesSql($method);

    $rows = $db->getAll(
        "WITH lines AS ($lines),
         per_kz AS (
            SELECT kz,
                   COALESCE(SUM(signed) FILTER (WHERE role = 'base'), 0) AS base_amount,
                   COALESCE(SUM(signed) FILTER (WHERE role = 'tax'),  0) AS tax_amount
            FROM lines WHERE kz IS NOT NULL GROUP BY kz
         )
         SELECT k.kz, k.label, k.kind, k.section,
                CASE WHEN k.kind = 'base' THEN TRUNC(COALESCE(p.base_amount, 0))
                     ELSE ROUND(COALESCE(p.tax_amount, 0), 2) END AS reported
         FROM ustva_kennzahlen k
         LEFT JOIN per_kz p ON p.kz = k.kz
         WHERE k.section <> 'ignoriert'
         ORDER BY k.sortkey",
        [':date_from' => $start, ':date_to' => $end]
    );

    $out = "Kennzahl;Bezeichnung;Art;Betrag\r\n";
    foreach ($rows as $r) {
        $out .= $r['kz'].';"'.str_replace('"', '""', $r['label']).'";'
             .($r['kind'] === 'base' ? 'Bemessungsgrundlage' : 'Steuer').';'
             .number_format((float)$r['reported'], 2, ',', '').
             "\r\n";
    }

    $filename = 'UStVA_'.str_replace(['/', ' ', '.'], ['-', '_', ''], $label).'.csv';

    resultInfo(true, '', [
        'filename' => $filename,
        'mimetype' => 'text/csv',
        'content'  => base64_encode("\xEF\xBB\xBF".$out),
    ]);
}
