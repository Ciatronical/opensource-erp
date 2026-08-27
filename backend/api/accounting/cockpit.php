<?php
// backend/api/accounting/cockpit.php
//
// Cockpit und Durchlauf — die beiden Bildschirme der neuen Buchhaltung.
//
// Das Cockpit beantwortet in einer Abfrage drei Fragen: Wie steht es (Puls),
// was ist zu tun (Kacheln), wo im Jahr stehe ich (Zeitstrahl-Rohdaten).
// Die Umsatzsteuer-Zahlen holt die Oberflaeche weiterhin aus getUstvaYear —
// dort liegt die massgebliche Kennzahlen-Logik, die hier nicht verdoppelt wird.

/**
 * Cockpit-Kennzahlen: Puls, Arbeitskacheln, Monatsabschluss
 *
 * Alles in EINER Abfrage. Die Zahlen kommen aus dem echten Hauptbuch
 * (acc_trans/ar/ap) und den Arbeitsvorraeten (accounting_bookings,
 * accounting_documents, bank_transactions).
 *
 * Geldbestand: Aktivkonten mit Zahlungs-Verknuepfung (Bank und Kasse tragen in
 * kivitendo beide link = AR_paid:AP_paid). Vorzeichen in acc_trans ist
 * Soll = negativ, deshalb ist der Bestand eines Aktivkontos -SUM(amount).
 *
 * @testdata {}
 */
function getAccountingCockpit($data) {
    $db = DbhCompany::begin();

    $kassenkonto = kassenkontoBedingung('c');

    $row = $db->getOne(<<<SQL
        WITH p AS (
            SELECT DATE_TRUNC('month', CURRENT_DATE)::date                        AS cur_from,
                   (DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month')::date AS cur_to,
                   (DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '1 month')::date AS prev_from,
                   DATE_TRUNC('month', CURRENT_DATE)::date                        AS prev_to,
                   DATE_TRUNC('year',  CURRENT_DATE)::date                        AS year_from
        ),
        geld AS (
            -- Jedes Geldkonto einzeln, eingeteilt in drei Gruppen:
            --
            --   kasse    — Bargeld. Erkennung ueber kassenkontoBedingung(), also
            --              wortgleich zur Kasse selbst. Stuenden im Cockpit andere
            --              Konten als dort, waeren die Bestaende nicht vergleichbar.
            --   bank     — nur Konten, die als Bankkonto eingerichtet sind. Ein
            --              Eintrag in bank_accounts ist eine bewusste Angabe mit
            --              IBAN, nicht geraten.
            --   sonstige — der Rest: Paypal, Zahlungsdienstleister, Verrechnungs-
            --              konten. Fruehere Fassungen haben das alles unter Bank
            --              summiert; das stimmte nie, fiel nur nicht auf.
            --
            -- Reihenfolge der Pruefung: Kasse zuerst. Waere ein Kassenkonto
            -- versehentlich als Bankkonto eingetragen, stuende es sonst in einer
            -- anderen Kachel als auf der Kassenseite.
            SELECT c.id,
                   CASE
                       WHEN {$kassenkonto} THEN 'kasse'
                       WHEN EXISTS (SELECT 1 FROM bank_accounts b WHERE b.chart_id = c.id) THEN 'bank'
                       ELSE 'sonstige'
                   END                                          AS gruppe,
                   c.accno || ' ' || c.description              AS bezeichnung,
                   COALESCE(-SUM(t.amount), 0)                  AS balance,
                   MAX(t.transdate)                             AS last_transdate
            FROM chart c
            -- Ab dem letzten Saldenvortrag rechnen — sonst zaehlt das Cockpit
            -- Jahre mit, die im Vortrag schon enthalten sind, und weicht von der
            -- Kasse und von kivitendo ab.
            LEFT JOIN LATERAL (
                SELECT COALESCE(MAX(o.transdate), '-infinity'::date) AS ab
                FROM acc_trans o
                WHERE o.chart_id = c.id AND o.ob_transaction IS TRUE
            ) v ON true
            LEFT JOIN acc_trans t ON t.chart_id = c.id AND t.transdate >= v.ab
            WHERE c.category = 'A' AND COALESCE(c.link, '') LIKE '%AR_paid%'
            GROUP BY c.id, c.accno, c.description
        ),
        money AS (
            SELECT COALESCE(SUM(g.balance) FILTER (WHERE g.gruppe = 'bank'), 0)     AS bank_balance,
                   COUNT(*) FILTER (WHERE g.gruppe = 'bank')                        AS bank_accounts,
                   COALESCE(SUM(g.balance) FILTER (WHERE g.gruppe = 'kasse'), 0)    AS cash_balance,
                   COUNT(*) FILTER (WHERE g.gruppe = 'kasse')                       AS cash_accounts,
                   MAX(g.last_transdate) FILTER (WHERE g.gruppe = 'kasse')          AS cash_last,
                   COALESCE(SUM(g.balance) FILTER (WHERE g.gruppe = 'sonstige'), 0) AS other_balance,
                   COUNT(*) FILTER (WHERE g.gruppe = 'sonstige')                    AS other_accounts,
                   -- Die Kachel nennt die Konten beim Namen, sonst waere die Zahl
                   -- nicht zu deuten. Drei reichen, danach steht die Restzahl da.
                   (SELECT STRING_AGG(x.bezeichnung, ', ' ORDER BY x.bezeichnung)
                      FROM (SELECT g2.bezeichnung FROM geld g2
                             WHERE g2.gruppe = 'sonstige'
                             ORDER BY g2.bezeichnung LIMIT 3) x)                    AS other_names
            FROM geld g
        ),
        recv AS (
            SELECT COUNT(*)                                                AS cnt,
                   COALESCE(SUM(amount - COALESCE(paid, 0)), 0)            AS sum_open,
                   COUNT(*) FILTER (WHERE duedate < CURRENT_DATE)          AS overdue_cnt,
                   COALESCE(SUM(amount - COALESCE(paid, 0))
                            FILTER (WHERE duedate < CURRENT_DATE), 0)      AS overdue_sum,
                   MAX(CURRENT_DATE - duedate) FILTER (WHERE duedate < CURRENT_DATE) AS overdue_days
            FROM ar
            WHERE (amount - COALESCE(paid, 0)) > 0.005 AND storno IS NOT TRUE
        ),
        pay AS (
            SELECT COUNT(*)                                     AS cnt,
                   COALESCE(SUM(amount - COALESCE(paid, 0)), 0) AS sum_open,
                   MIN(duedate)                                 AS next_due
            FROM ap
            WHERE (amount - COALESCE(paid, 0)) > 0.005 AND storno IS NOT TRUE
        ),
        docs AS (
            SELECT COUNT(*) FILTER (WHERE status = 'extracted') AS extracted,
                   COUNT(*) FILTER (WHERE status = 'error')     AS failed
            FROM accounting_documents
        ),
        book AS (
            SELECT COUNT(*) FILTER (WHERE status = 'pending')                            AS pending,
                   COUNT(*) FILTER (WHERE status = 'pending' AND ai_confidence >= 0.90)  AS pending_sure,
                   COUNT(*) FILTER (WHERE status = 'approved')                           AS approved,
                   -- Automatisch gebucht heisst: die KI hat den Beleg ohne Rueckfrage
                   -- ins Hauptbuch gestellt (ap_id gesetzt). Der Status ist dabei
                   -- 'approved', nicht 'booked' — danach zu filtern liesse das
                   -- Stichproben-Band leer, obwohl gerade etwas gebucht wurde.
                   COUNT(*) FILTER (WHERE ai_generated
                                      AND ap_id IS NOT NULL
                                      -- Der Upload setzt kein approved_at; ohne den
                                      -- Rueckfall auf itime zaehlte das Band nie.
                                      AND COALESCE(approved_at, itime) >= CURRENT_DATE - 7) AS booked_week
            FROM accounting_bookings
        ),
        bank AS (
            SELECT COUNT(*) FILTER (WHERE match_status = 'unmatched')                       AS unmatched,
                   COUNT(*) FILTER (WHERE match_status = 'matched')                         AS matched,
                   COUNT(*) FILTER (WHERE match_status = 'unmatched' AND amount > 0)        AS unmatched_in,
                   COUNT(*) FILTER (WHERE match_status = 'unmatched' AND amount < 0)        AS unmatched_out
            FROM bank_transactions
        ),
        closing AS (
            -- Der abzuschliessende Monat ist der Vormonat. Fertig ist er, wenn
            -- keine Bankumsaetze und keine Belege dieses Monats mehr offen sind.
            SELECT (SELECT COUNT(*) FROM bank_transactions bt CROSS JOIN p
                     WHERE bt.transdate >= p.prev_from AND bt.transdate < p.prev_to)                            AS bank_total,
                   (SELECT COUNT(*) FROM bank_transactions bt CROSS JOIN p
                     WHERE bt.transdate >= p.prev_from AND bt.transdate < p.prev_to
                       AND bt.match_status = 'unmatched')                                                       AS bank_open,
                   (SELECT COUNT(*) FROM accounting_bookings b CROSS JOIN p
                     WHERE b.status = 'pending' AND b.booking_date >= p.prev_from AND b.booking_date < p.prev_to) AS book_open
        ),
        checks AS (
            -- Unstimmigkeiten zwischen Rechnung und Kontrollkonto. Die Zahl
            -- gehört ins Cockpit, weil sie sonst nirgends auffällt: die
            -- Rechnung sagt "bezahlt", das Konto sagt "offen", und beide
            -- Bildschirme für sich sehen richtig aus. Details liefert
            -- getLedgerConsistency() — dort steht auch, warum Rechnungen ohne
            -- Kontrollkonto-Zeile nicht mitgezählt werden.
            SELECT COUNT(*) AS cnt, COALESCE(SUM(ABS(d.diff)), 0) AS sum_diff
            FROM (
                SELECT ROUND(s.saldo + (ar.amount - COALESCE(ar.paid, 0)), 2) AS diff
                FROM ar
                JOIN (SELECT at.trans_id, SUM(at.amount) AS saldo
                        FROM acc_trans at
                       WHERE at.chart_id IN (SELECT id FROM chart
                              WHERE POSITION(':AR:' IN ':' || COALESCE(link, '') || ':') > 0)
                       GROUP BY at.trans_id) s ON s.trans_id = ar.id
                WHERE ar.storno IS NOT TRUE
                UNION ALL
                SELECT ROUND(s.saldo - (ap.amount - COALESCE(ap.paid, 0)), 2)
                FROM ap
                JOIN (SELECT at.trans_id, SUM(at.amount) AS saldo
                        FROM acc_trans at
                       WHERE at.chart_id IN (SELECT id FROM chart
                              WHERE POSITION(':AP:' IN ':' || COALESCE(link, '') || ':') > 0)
                       GROUP BY at.trans_id) s ON s.trans_id = ap.id
                WHERE ap.storno IS NOT TRUE
            ) d
            WHERE ABS(d.diff) > 0.005
        )
        SELECT
            TO_CHAR(p.cur_from,  'MM/YYYY')                     AS current_period,
            TO_CHAR(p.prev_from, 'MM/YYYY')                     AS previous_period,
            TO_CHAR(p.prev_from, 'YYYY-MM-DD')                  AS previous_from,
            EXTRACT(YEAR  FROM p.prev_from)::int                AS previous_year,
            EXTRACT(MONTH FROM p.prev_from)::int                AS previous_month,
            EXTRACT(YEAR  FROM CURRENT_DATE)::int               AS current_year,

            ROUND(money.bank_balance, 2)                        AS bank_balance,
            money.bank_accounts                                 AS bank_accounts,
            ROUND(money.cash_balance, 2)                        AS cash_balance,
            money.cash_accounts                                 AS cash_accounts,
            TO_CHAR(money.cash_last, 'DD.MM.YYYY')              AS cash_last,
            ROUND(money.other_balance, 2)                       AS other_balance,
            money.other_accounts                                AS other_accounts,
            money.other_names                                   AS other_names,

            recv.cnt                                            AS receivables_count,
            ROUND(recv.sum_open, 2)                             AS receivables_sum,
            recv.overdue_cnt                                    AS overdue_count,
            ROUND(recv.overdue_sum, 2)                          AS overdue_sum,
            COALESCE(recv.overdue_days, 0)                      AS overdue_days,

            pay.cnt                                             AS payables_count,
            ROUND(pay.sum_open, 2)                              AS payables_sum,
            TO_CHAR(pay.next_due, 'DD.MM.YYYY')                 AS payables_next_due,

            docs.extracted                                      AS documents_extracted,
            docs.failed                                         AS documents_failed,

            book.pending                                        AS bookings_pending,
            book.pending_sure                                   AS bookings_pending_sure,
            book.approved                                       AS bookings_approved,
            book.booked_week                                    AS bookings_booked_week,

            bank.unmatched                                      AS bank_unmatched,
            bank.matched                                        AS bank_matched,
            bank.unmatched_in                                   AS bank_unmatched_in,
            bank.unmatched_out                                  AS bank_unmatched_out,

            closing.bank_total                                  AS closing_bank_total,
            closing.bank_open                                   AS closing_bank_open,
            closing.book_open                                   AS closing_book_open,

            checks.cnt                                          AS checks_count,
            ROUND(checks.sum_diff, 2)                           AS checks_sum,

            (SELECT COUNT(*) FROM accounting_account_rules WHERE active) AS rules_count
        FROM p CROSS JOIN money CROSS JOIN recv CROSS JOIN pay
               CROSS JOIN docs CROSS JOIN book CROSS JOIN bank CROSS JOIN closing
               CROSS JOIN checks
    SQL, []);

    // Abschluss-Fortschritt: Anteil der bereits zugeordneten Bankumsaetze des
    // Vormonats. Ohne Umsaetze gilt der Monat als fertig, sobald keine Belege
    // mehr offen sind — sonst stuende dort dauerhaft 0 %.
    $bankTotal = intval($row['closing_bank_total']);
    $bankOpen  = intval($row['closing_bank_open']);
    $bookOpen  = intval($row['closing_book_open']);
    if ($bankTotal > 0) {
        $percent = (int) round(100 * ($bankTotal - $bankOpen) / $bankTotal);
    } else {
        $percent = $bookOpen > 0 ? 0 : 100;
    }

    resultInfo(true, '', ['results' => [
        'periods' => [
            'current'        => $row['current_period'],
            'previous'       => $row['previous_period'],
            'previous_year'  => intval($row['previous_year']),
            'previous_month' => intval($row['previous_month']),
            'current_year'   => intval($row['current_year']),
        ],
        'pulse' => [
            'bank_balance'      => floatval($row['bank_balance']),
            'bank_accounts'     => intval($row['bank_accounts']),
            'cash_balance'      => floatval($row['cash_balance']),
            'cash_accounts'     => intval($row['cash_accounts']),
            'cash_last'         => $row['cash_last'],
            'other_balance'     => floatval($row['other_balance']),
            'other_accounts'    => intval($row['other_accounts']),
            'other_names'       => $row['other_names'],
            'receivables_count' => intval($row['receivables_count']),
            'receivables_sum'   => floatval($row['receivables_sum']),
            'payables_count'    => intval($row['payables_count']),
            'payables_sum'      => floatval($row['payables_sum']),
            'payables_next_due' => $row['payables_next_due'],
        ],
        'stacks' => [
            'documents' => [
                'count'   => intval($row['bookings_pending']) + intval($row['documents_extracted']),
                'pending' => intval($row['bookings_pending']),
                'sure'    => intval($row['bookings_pending_sure']),
                'waiting' => intval($row['documents_extracted']),
                'failed'  => intval($row['documents_failed']),
            ],
            'bank' => [
                'count'   => intval($row['bank_unmatched']),
                'incoming' => intval($row['bank_unmatched_in']),
                'outgoing' => intval($row['bank_unmatched_out']),
                'matched'  => intval($row['bank_matched']),
            ],
            'overdue' => [
                'count' => intval($row['overdue_count']),
                'sum'   => floatval($row['overdue_sum']),
                'days'  => intval($row['overdue_days']),
            ],
            'checks' => [
                'count' => intval($row['checks_count']),
                'sum'   => floatval($row['checks_sum']),
            ],
            'closing' => [
                'percent'    => $percent,
                'bank_open'  => $bankOpen,
                'book_open'  => $bookOpen,
                'bank_total' => $bankTotal,
            ],
        ],
        'auto' => [
            'booked_week' => intval($row['bookings_booked_week']),
            'approved'    => intval($row['bookings_approved']),
            'rules_count' => intval($row['rules_count']),
        ],
    ]]);
}

/**
 * Ein Arbeitsstapel fuer den Durchlauf
 *
 * @param string $data['kind']  documents|bank
 * @param int    $data['limit'] Hoechstzahl Positionen (Standard 200)
 * @testdata {"kind": "bank", "limit": 50}
 */
function getAccountingStack($data) {
    $db   = DbhCompany::begin();
    $kind = $data['kind'] ?? 'documents';
    $limit = min(max(intval($data['limit'] ?? 200), 1), 500);

    if ($kind === 'bank') {
        // Offene Bankumsaetze mit dem jeweils besten Vorschlag. Die Bewertung
        // macht die Datenbank: Rechnungsnummer im Verwendungszweck wiegt am
        // schwersten, danach exakter Betrag, Namensaehnlichkeit und Naehe zum
        // Faelligkeitsdatum.
        $items = $db->getAll(<<<SQL
            WITH tx AS (
                -- Erst den Arbeitsvorrat begrenzen, dann bewerten: sonst wuerde
                -- jeder Aufruf saemtliche offenen Umsaetze gegen alle offenen
                -- Rechnungen rechnen.
                SELECT bt.*
                FROM bank_transactions bt
                WHERE bt.match_status = 'unmatched'
                ORDER BY bt.transdate DESC, bt.id DESC
                LIMIT :lim
            )
            SELECT bt.id,
                   TO_CHAR(bt.transdate, 'DD.MM.YYYY')            AS transdate_fmt,
                   bt.transdate,
                   ROUND(bt.amount, 2)                            AS amount,
                   bt.remote_name,
                   bt.remote_iban,
                   bt.purpose,
                   ba.name                                        AS bank_account,
                   bt.local_bank_account_id                       AS bank_account_id,
                   c.target_type, c.target_id, c.invnumber, c.partner,
                   ROUND(c.open_amount, 2)                        AS candidate_amount,
                   TO_CHAR(c.duedate, 'DD.MM.YYYY')               AS candidate_due_fmt,
                   -- LEAST ignoriert NULL: ohne Vorschlag stuende hier sonst 100.
                   CASE WHEN c.score IS NULL THEN NULL
                        ELSE LEAST(c.score, 100) END                AS candidate_score
            FROM tx bt
            JOIN bank_accounts ba ON ba.id = bt.local_bank_account_id
            LEFT JOIN LATERAL (
                SELECT x.target_type, x.target_id, x.invnumber, x.partner,
                       x.open_amount, x.duedate, x.score
                FROM (
                    SELECT 'ar' AS target_type, ar.id AS target_id, ar.invnumber,
                           cu.name AS partner, (ar.amount - COALESCE(ar.paid, 0)) AS open_amount,
                           ar.duedate,
                             (CASE WHEN LENGTH(COALESCE(ar.invnumber, '')) >= 4
                                    AND COALESCE(bt.purpose, '') ILIKE '%' || ar.invnumber || '%' THEN 55 ELSE 0 END)
                           + (CASE WHEN ABS((ar.amount - COALESCE(ar.paid, 0)) - bt.amount) < 0.005 THEN 30 ELSE 0 END)
                           + (CASE WHEN COALESCE(bt.remote_name, '') <> ''
                                    AND similarity(cu.name, bt.remote_name) > 0.35 THEN 20 ELSE 0 END)
                           + (CASE WHEN ABS(bt.transdate - ar.duedate) <= 45 THEN 10 ELSE 0 END) AS score
                    FROM ar
                    JOIN customer cu ON cu.id = ar.customer_id
                    WHERE bt.amount > 0
                      AND (ar.amount - COALESCE(ar.paid, 0)) > 0.005
                      AND ar.storno IS NOT TRUE
                    UNION ALL
                    SELECT 'ap', ap.id, ap.invnumber,
                           ve.name, (ap.amount - COALESCE(ap.paid, 0)),
                           ap.duedate,
                             (CASE WHEN LENGTH(COALESCE(ap.invnumber, '')) >= 4
                                    AND COALESCE(bt.purpose, '') ILIKE '%' || ap.invnumber || '%' THEN 55 ELSE 0 END)
                           + (CASE WHEN ABS((ap.amount - COALESCE(ap.paid, 0)) + bt.amount) < 0.005 THEN 30 ELSE 0 END)
                           + (CASE WHEN COALESCE(bt.remote_name, '') <> ''
                                    AND similarity(ve.name, bt.remote_name) > 0.35 THEN 20 ELSE 0 END)
                           + (CASE WHEN ABS(bt.transdate - ap.duedate) <= 45 THEN 10 ELSE 0 END)
                    FROM ap
                    JOIN vendor ve ON ve.id = ap.vendor_id
                    WHERE bt.amount < 0
                      AND (ap.amount - COALESCE(ap.paid, 0)) > 0.005
                      AND ap.storno IS NOT TRUE
                ) x
                WHERE x.score >= 30
                ORDER BY x.score DESC, x.duedate ASC
                LIMIT 1
            ) c ON TRUE
            -- Ausgabe nach Trefferguete: der Durchlauf beginnt mit dem, was sich
            -- mit einer Taste erledigen laesst, und endet bei dem, was Arbeit macht.
            ORDER BY (c.score IS NULL), c.score DESC, bt.transdate DESC, bt.id DESC
        SQL, [':lim' => $limit]);

        $total = $db->getOne(
            "SELECT COUNT(*) AS n FROM bank_transactions WHERE match_status = 'unmatched'", []
        );

        resultInfo(true, '', ['results' => [
            'kind'  => 'bank',
            'items' => $items ?: [],
            'total' => intval($total['n'] ?? 0),
        ]]);
        return;
    }

    // Belegstapel: KI-Vorschlaege mit Beleg, Konto-Klartext und passendem Bankumsatz
    $items = $db->getAll(<<<SQL
        SELECT b.id,
               b.invoice_number,
               TO_CHAR(b.invoice_date, 'DD.MM.YYYY')  AS invoice_date_fmt,
               b.invoice_date,
               TO_CHAR(b.due_date, 'DD.MM.YYYY')      AS due_date_fmt,
               ROUND(b.amount, 2)                     AS amount,
               ROUND(b.net_amount, 2)                 AS net_amount,
               ROUND(b.tax_amount, 2)                 AS tax_amount,
               b.tax_rate,
               b.debit_account, b.credit_account,
               cd.description                         AS debit_name,
               cc.description                         AS credit_name,
               b.description, b.type, b.status,
               b.ai_confidence, b.ai_notes,
               b.vendor_id, v.name                    AS vendor_name,
               b.customer_id, cu.name                 AS customer_name,
               b.document_id, d.original_name, d.mime_type,
               bk.id                                  AS bank_transaction_id,
               TO_CHAR(bk.transdate, 'DD.MM.YYYY')    AS bank_date_fmt,
               ROUND(bk.amount, 2)                    AS bank_amount,
               (SELECT COUNT(*) FROM accounting_account_rules r
                 WHERE r.active AND r.vendor_id = b.vendor_id) AS rule_hits
        FROM accounting_bookings b
        LEFT JOIN vendor   v  ON v.id  = b.vendor_id
        LEFT JOIN customer cu ON cu.id = b.customer_id
        LEFT JOIN chart    cd ON cd.accno = b.debit_account
        LEFT JOIN chart    cc ON cc.accno = b.credit_account
        LEFT JOIN accounting_documents d ON d.id = b.document_id
        LEFT JOIN LATERAL (
            SELECT bt.id, bt.transdate, bt.amount
            FROM bank_transactions bt
            WHERE bt.match_status = 'unmatched'
              AND ABS(ABS(bt.amount) - b.amount) < 0.005
              AND b.invoice_date IS NOT NULL
              AND bt.transdate BETWEEN b.invoice_date - 10 AND b.invoice_date + 90
            ORDER BY ABS(bt.transdate - b.invoice_date)
            LIMIT 1
        ) bk ON TRUE
        WHERE b.status = 'pending'
        ORDER BY b.ai_confidence DESC NULLS LAST, b.invoice_date NULLS LAST, b.id
        LIMIT :lim
    SQL, [':lim' => $limit]);

    resultInfo(true, '', ['results' => [
        'kind'  => 'documents',
        'items' => $items ?: [],
        'total' => count($items ?: []),
    ]]);
}

/**
 * Suche fuer die Befehlspalette (Strg+K): Konten, Partner, Belegnummern
 *
 * @param string $data['query'] Suchbegriff (mindestens 2 Zeichen)
 * @testdata {"query": "1800"}
 */
function searchAccountingTargets($data) {
    $db = DbhCompany::begin();
    $q  = trim($data['query'] ?? '');
    if (mb_strlen($q) < 2) {
        resultInfo(true, '', ['results' => ['items' => []]]);
        return;
    }

    $items = $db->getAll(<<<SQL
        SELECT * FROM (
          SELECT y.*, ROW_NUMBER() OVER (PARTITION BY y.kind ORDER BY y.score DESC, y.label) AS rn
          FROM (
            SELECT 'account' AS kind, c.accno AS ref, c.accno AS code,
                   c.description AS label, c.category AS extra,
                   -- In einer Buchhaltungssuche wiegt ein Kontotreffer schwerer
                   -- als ein gleichlautender Partnername.
                   (CASE WHEN c.accno = :exact THEN 100
                         WHEN c.accno ILIKE :prefix THEN 95 ELSE 75 END) AS score
            FROM chart c
            WHERE c.charttype = 'A'
              AND (c.accno ILIKE :prefix OR c.description ILIKE :like)
            UNION ALL
            SELECT 'customer', cu.id::text, cu.customernumber, cu.name, cu.city,
                   (CASE WHEN cu.name ILIKE :prefix THEN 70 ELSE 40 END)
            FROM customer cu
            WHERE cu.name ILIKE :like OR cu.customernumber ILIKE :prefix
            UNION ALL
            SELECT 'vendor', ve.id::text, ve.vendornumber, ve.name, ve.city,
                   (CASE WHEN ve.name ILIKE :prefix THEN 70 ELSE 40 END)
            FROM vendor ve
            WHERE ve.name ILIKE :like OR ve.vendornumber ILIKE :prefix
            UNION ALL
            SELECT 'ar', ar.id::text, ar.invnumber, cu.name,
                   TO_CHAR(ar.transdate, 'DD.MM.YYYY'), 90
            FROM ar JOIN customer cu ON cu.id = ar.customer_id
            WHERE ar.invnumber ILIKE :prefix
            UNION ALL
            SELECT 'ap', ap.id::text, ap.invnumber, ve.name,
                   TO_CHAR(ap.transdate, 'DD.MM.YYYY'), 90
            FROM ap JOIN vendor ve ON ve.id = ap.vendor_id
            WHERE ap.invnumber ILIKE :prefix
          ) y
        ) x
        -- Je Art hoechstens sechs Treffer: sonst verdraengen ein paar hundert
        -- gleichnamige Kunden die Konten und Belege ganz aus der Liste.
        WHERE x.rn <= 6
        ORDER BY x.score DESC, x.label
        LIMIT 30
    SQL, [
        ':exact'  => $q,
        ':prefix' => $q . '%',
        ':like'   => '%' . $q . '%',
    ]);

    resultInfo(true, '', ['results' => ['items' => $items ?: []]]);
}

/**
 * Unstimmigkeiten zwischen Rechnung und Kontrollkonto
 *
 * Jede Rechnung muss auf ihrem Forderungs- bzw. Verbindlichkeitskonto genau
 * den offenen Betrag stehen haben: Forderungen im Soll (kivitendo: negativ),
 * Verbindlichkeiten im Haben. Weicht der Saldo davon ab, ist eine Zahlung auf
 * einem falschen Konto gelandet — typisch das Erlöskonto statt des
 * Forderungskontos, weil beide den Link-Präfix 'AR' tragen — oder eine
 * Buchung fehlt ganz. Beides sieht man weder der Rechnung noch dem Kassenbuch
 * an: dort stimmt ar.paid, und die Gegenkonto-Spalte zeigt das Konto der
 * Rechnung, nicht das der Zahlung. Auffallen würde es erst in der
 * Saldenliste, wenn niemand mehr weiss, wo die Differenz herkam.
 *
 * Rechnungen ohne jede Kontrollkonto-Zeile bleiben außen vor. Das sind
 * Altbestände, die nie ins Hauptbuch gebucht wurden — die vollständig zu
 * melden hieße, die echten Fehlbuchungen darin zu begraben.
 *
 * @param int $data['limit'] Höchstzahl gelieferter Positionen (Standard 200)
 * @testdata {"limit": 50}
 */
function getLedgerConsistency($data) {
    $db = DbhCompany::begin();

    $limit = intval($data['limit'] ?? 200);
    if ($limit < 1 || $limit > 1000) $limit = 200;

    $row = $db->getOne(<<<SQL
        WITH kontroll AS (
            -- Das Kontrollkonto trägt den Token 'AR' bzw. 'AP' EXAKT in der
            -- ':'-getrennten Link-Liste. 'AR_amount' (Erlös) und 'AR_tax'
            -- (Umsatzsteuer) sind ausdrücklich NICHT gemeint.
            SELECT id,
                   POSITION(':AR:' IN ':' || COALESCE(link, '') || ':') > 0 AS ist_ar
            FROM chart
            WHERE POSITION(':AR:' IN ':' || COALESCE(link, '') || ':') > 0
               OR POSITION(':AP:' IN ':' || COALESCE(link, '') || ':') > 0
        ),
        saldo AS (
            SELECT at.trans_id, k.ist_ar,
                   SUM(at.amount)                          AS saldo,
                   MIN(c.accno)                            AS accno,
                   MIN(c.description)                      AS account_name
            FROM acc_trans at
            JOIN kontroll k ON k.id = at.chart_id
            JOIN chart    c ON c.id = at.chart_id
            GROUP BY at.trans_id, k.ist_ar
        ),
        items AS (
            SELECT 'ar'::text                              AS kind,
                   ar.id                                   AS invoice_id,
                   ar.invnumber                            AS invnumber,
                   TO_CHAR(ar.transdate, 'DD.MM.YYYY')     AS transdate,
                   ar.transdate                            AS sortdate,
                   cu.name                                 AS partner,
                   ar.amount                               AS amount,
                   COALESCE(ar.paid, 0)                    AS paid,
                   s.accno                                 AS accno,
                   s.account_name                          AS account_name,
                   s.saldo                                 AS balance,
                   -- Erwartet: -(offener Betrag). Die Differenz ist der Betrag,
                   -- der auf dem Kontrollkonto zu viel oder zu wenig steht.
                   ROUND(s.saldo + (ar.amount - COALESCE(ar.paid, 0)), 2) AS difference
            FROM ar
            JOIN saldo    s  ON s.trans_id = ar.id AND s.ist_ar
            JOIN customer cu ON cu.id = ar.customer_id
            WHERE ar.storno IS NOT TRUE

            UNION ALL

            SELECT 'ap', ap.id, ap.invnumber,
                   TO_CHAR(ap.transdate, 'DD.MM.YYYY'), ap.transdate,
                   ve.name, ap.amount, COALESCE(ap.paid, 0),
                   s.accno, s.account_name, s.saldo,
                   ROUND(s.saldo - (ap.amount - COALESCE(ap.paid, 0)), 2)
            FROM ap
            JOIN saldo  s  ON s.trans_id = ap.id AND NOT s.ist_ar
            JOIN vendor ve ON ve.id = ap.vendor_id
            WHERE ap.storno IS NOT TRUE
        ),
        flagged AS (
            SELECT i.*,
                   -- Die häufigste Form: die Rechnung gilt als bezahlt, das
                   -- Kontrollkonto trägt den Betrag aber weiter. Dann ist die
                   -- Zahlung woanders gelandet.
                   CASE WHEN ABS(i.amount - i.paid) < 0.005
                        THEN 'paid_but_open' ELSE 'balance_mismatch' END AS reason
            FROM items i
            WHERE ABS(i.difference) > 0.005
        )
        SELECT
            (SELECT COUNT(*)                            FROM flagged) AS count,
            (SELECT COALESCE(SUM(ABS(difference)), 0)   FROM flagged) AS sum_difference,
            (SELECT json_agg(row_to_json(t)) FROM (
                SELECT kind, invoice_id, invnumber, transdate, partner,
                       amount, paid, accno, account_name, balance, difference, reason
                FROM flagged
                ORDER BY sortdate DESC, invnumber DESC
                LIMIT {$limit}
            ) t) AS items
    SQL, []);

    resultInfo(true, '', ['results' => [
        'count'          => intval($row['count'] ?? 0),
        'sum_difference' => round(floatval($row['sum_difference'] ?? 0), 2),
        'items'          => json_decode($row['items'] ?? '[]', true) ?: [],
    ]]);
}
