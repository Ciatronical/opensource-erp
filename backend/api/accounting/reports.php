<?php
// backend/api/accounting/reports.php
//
// Berichte der Buchhaltung: die Summen- und Saldenliste (alle Konten mit dem,
// was darauf gebucht wurde) und die Druckfassungen von Saldenliste und
// Kontoblatt.
//
// Die Saldenliste ist der Einstieg in jede Prüfung: sie zeigt in einer Zeile
// je Konto den Anfangssaldo, die Bewegungen des Zeitraums und den Endsaldo.
// Von dort geht es per Klick ins Kontoblatt, das die einzelnen Buchungen zeigt.

/**
 * Summen- und Saldenliste: alle bebuchten Konten eines Zeitraums.
 *
 * Vorzeichen wie in kivitendo: Soll = amount < 0, Haben = amount > 0. Der Saldo
 * wird als Soll−Haben geführt, damit Aktiv- und Aufwandskonten positiv
 * dastehen — so, wie es jeder Steuerberater erwartet.
 *
 * @param string $data['from_date'] Von-Datum (optional, Default: Jahresanfang)
 * @param string $data['to_date']   Bis-Datum (optional, Default: heute)
 * @param bool   $data['all']       true = auch Konten ohne jede Bewegung zeigen
 * @testdata {"from_date": "2026-01-01", "to_date": "2026-12-31"}
 */
function getTrialBalance($data) {
    $db = DbhCompany::begin();

    $from = !empty($data['from_date']) ? $data['from_date'] : date('Y') . '-01-01';
    $to   = !empty($data['to_date'])   ? $data['to_date']   : date('Y-m-d');
    $all  = !empty($data['all']);

    $result = $db->getOne(<<<SQL
        WITH p AS (
            SELECT :von::date AS von, :bis::date AS bis, :alle::boolean AS alle
        ),
        salden AS (
            SELECT c.id, c.accno, c.description, c.category,
                   COALESCE(SUM(-t.amount) FILTER (WHERE t.transdate < p.von), 0)              AS opening,
                   COALESCE(SUM(-t.amount) FILTER (WHERE t.amount < 0
                            AND t.transdate BETWEEN p.von AND p.bis), 0)                       AS soll,
                   COALESCE(SUM( t.amount) FILTER (WHERE t.amount > 0
                            AND t.transdate BETWEEN p.von AND p.bis), 0)                       AS haben,
                   COUNT(t.acc_trans_id) FILTER (WHERE t.transdate BETWEEN p.von AND p.bis)::int AS bookings
            FROM chart c
            CROSS JOIN p
            LEFT JOIN acc_trans t ON t.chart_id = c.id AND t.transdate <= p.bis
            GROUP BY c.id, c.accno, c.description, c.category, p.alle
            HAVING p.alle
                OR COUNT(t.acc_trans_id) FILTER (WHERE t.transdate BETWEEN p.von AND p.bis) > 0
                OR ABS(COALESCE(SUM(-t.amount) FILTER (WHERE t.transdate < p.von), 0)) > 0.005
        ),
        mit_saldo AS (
            SELECT s.*, ROUND(s.opening + s.soll - s.haben, 2) AS closing FROM salden s
        )
        SELECT
            COALESCE(json_agg(json_build_object(
                'id',          m.id,
                'accno',       m.accno,
                'description', m.description,
                'category',    m.category,
                'opening',     ROUND(m.opening, 2),
                'soll',        ROUND(m.soll, 2),
                'haben',       ROUND(m.haben, 2),
                'closing',     m.closing,
                'bookings',    m.bookings
            ) ORDER BY m.accno ASC), '[]')            AS accounts,
            COALESCE(ROUND(SUM(m.soll), 2), 0)        AS sum_soll,
            COALESCE(ROUND(SUM(m.haben), 2), 0)       AS sum_haben,
            COUNT(m.*)::int                           AS account_count
        FROM mit_saldo m
    SQL, ['von' => $from, 'bis' => $to, 'alle' => $all]);

    resultInfo(true, '', [
        'from_date' => $from,
        'to_date'   => $to,
        'accounts'  => json_decode($result['accounts'] ?? '[]', true) ?: [],
        'sum_soll'  => floatval($result['sum_soll'] ?? 0),
        'sum_haben' => floatval($result['sum_haben'] ?? 0),
        'count'     => intval($result['account_count'] ?? 0),
    ]);
}

/**
 * Summen- und Saldenliste als PDF.
 *
 * @param string $data['from_date'] Von-Datum (optional)
 * @param string $data['to_date']   Bis-Datum (optional)
 * @param bool   $data['all']       true = auch Konten ohne Bewegung
 * @testdata {"from_date": "2026-01-01", "to_date": "2026-12-31"}
 */
function getTrialBalancePdf($data) {
    $db = DbhCompany::begin();

    $from = !empty($data['from_date']) ? $data['from_date'] : date('Y') . '-01-01';
    $to   = !empty($data['to_date'])   ? $data['to_date']   : date('Y-m-d');
    $all  = !empty($data['all']);

    $result = $db->getOne(<<<SQL
        WITH p AS (
            SELECT :von::date AS von, :bis::date AS bis, :alle::boolean AS alle
        ),
        salden AS (
            SELECT c.accno, c.description, c.category,
                   COALESCE(SUM(-t.amount) FILTER (WHERE t.transdate < p.von), 0) AS opening,
                   COALESCE(SUM(-t.amount) FILTER (WHERE t.amount < 0
                            AND t.transdate BETWEEN p.von AND p.bis), 0)          AS soll,
                   COALESCE(SUM( t.amount) FILTER (WHERE t.amount > 0
                            AND t.transdate BETWEEN p.von AND p.bis), 0)          AS haben
            FROM chart c
            CROSS JOIN p
            LEFT JOIN acc_trans t ON t.chart_id = c.id AND t.transdate <= p.bis
            GROUP BY c.id, c.accno, c.description, c.category, p.alle
            HAVING p.alle
                OR COUNT(t.acc_trans_id) FILTER (WHERE t.transdate BETWEEN p.von AND p.bis) > 0
                OR ABS(COALESCE(SUM(-t.amount) FILTER (WHERE t.transdate < p.von), 0)) > 0.005
        )
        SELECT
            (SELECT company FROM defaults)                                        AS company,
            COALESCE(json_agg(json_build_object(
                'accno', s.accno, 'description', s.description, 'category', s.category,
                'opening', ROUND(s.opening, 2), 'soll', ROUND(s.soll, 2),
                'haben', ROUND(s.haben, 2), 'closing', ROUND(s.opening + s.soll - s.haben, 2)
            ) ORDER BY s.accno ASC), '[]')                                        AS rows,
            COALESCE(ROUND(SUM(s.soll), 2), 0)                                    AS sum_soll,
            COALESCE(ROUND(SUM(s.haben), 2), 0)                                   AS sum_haben,
            COUNT(s.*)::int                                                       AS account_count
        FROM salden s
    SQL, ['von' => $from, 'bis' => $to, 'alle' => $all]);

    $rows = json_decode($result['rows'] ?? '[]', true) ?: [];

    require_once __DIR__ . '/../lib/report_pdf.php';

    $pdf = new ReportPdf('P', 'mm', 'A4');
    $pdf->reportTitle = 'Summen- und Saldenliste';
    $pdf->reportLines = array_filter([
        $result['company'] ?? '',
        'Zeitraum: ' . date('d.m.Y', strtotime($from)) . ' bis ' . date('d.m.Y', strtotime($to)),
        intval($result['account_count'] ?? 0) . ' Konten',
    ]);
    $pdf->columns = [
        ['w' => 18, 'label' => 'Konto'],
        ['w' => 68, 'label' => 'Bezeichnung'],
        ['w' => 23, 'label' => 'Anfangssaldo', 'align' => 'R'],
        ['w' => 23, 'label' => 'Soll',         'align' => 'R'],
        ['w' => 23, 'label' => 'Haben',        'align' => 'R'],
        ['w' => 25, 'label' => 'Endsaldo',     'align' => 'R'],
    ];
    $pdf->footNote = 'Erstellt am ' . date('d.m.Y H:i') . ' · ' . ($result['company'] ?? '');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    foreach ($rows as $row) {
        $pdf->row([
            $row['accno'],
            ['text' => $row['description'], 'wrap' => true, 'maxLines' => 2],
            ReportPdf::money($row['opening'], true),
            ReportPdf::money($row['soll'],    true),
            ReportPdf::money($row['haben'],   true),
            ['text' => ReportPdf::money($row['closing']), 'bold' => true],
        ]);
    }

    if (!$rows) $pdf->row(['', 'Keine Buchungen im gewählten Zeitraum.', '', '', '', '']);

    // Soll und Haben müssen sich decken — stimmt das nicht, ist die Buchhaltung
    // nicht ausgeglichen, und genau deshalb steht die Zeile hier.
    $pdf->totalRow([
        '', 'Summe der Bewegungen', '',
        ['text' => ReportPdf::money($result['sum_soll']  ?? 0), 'align' => 'R'],
        ['text' => ReportPdf::money($result['sum_haben'] ?? 0), 'align' => 'R'],
        '',
    ]);

    resultInfo(true, '', [
        'filename' => 'saldenliste_' . date('Y-m-d', strtotime($from)) . '_' . date('Y-m-d', strtotime($to)) . '.pdf',
        'mime'     => 'application/pdf',
        'data'     => base64_encode($pdf->Output('S')),
    ]);
}

/**
 * Kontoblatt (Sachkontoauszug) eines einzelnen Kontos als PDF.
 *
 * @param string $data['accno']     Kontonummer (alternativ chart_id)
 * @param int    $data['chart_id']  Konto-ID (alternativ zu accno)
 * @param string $data['from_date'] Von-Datum (optional)
 * @param string $data['to_date']   Bis-Datum (optional)
 * @testdata {"accno": "1000", "from_date": "2026-01-01", "to_date": "2026-12-31"}
 */
function getAccountLedgerPdf($data) {
    $db = DbhCompany::begin();

    $accno   = trim($data['accno'] ?? '');
    $chartId = intval($data['chart_id'] ?? 0);
    $from    = !empty($data['from_date']) ? $data['from_date'] : date('Y') . '-01-01';
    $to      = !empty($data['to_date'])   ? $data['to_date']   : date('Y-m-d');

    $report = $db->getOne(<<<SQL
        WITH p AS (
            SELECT :von::date AS von, :bis::date AS bis,
                   :chart_id::int AS cid, NULLIF(:accno, '') AS accno
        ),
        konto AS (
            SELECT c.id, c.accno, c.description
            FROM chart c CROSS JOIN p
            WHERE (p.cid > 0 AND c.id = p.cid) OR (p.cid = 0 AND c.accno = p.accno)
            LIMIT 1
        ),
        eroeffnung AS (
            SELECT COALESCE(-SUM(t.amount), 0) AS opening
            FROM acc_trans t CROSS JOIN p
            WHERE t.chart_id = (SELECT id FROM konto) AND t.transdate < p.von
        ),
        zeilen AS (
            SELECT t.transdate,
                   COALESCE(ar.invnumber, ap.invnumber, gl.reference)        AS reference,
                   COALESCE(cu.name, ve.name)                                AS partner,
                   COALESCE(NULLIF(t.memo,''), NULLIF(gl.description,''),
                            NULLIF(ar.transaction_description,''),
                            NULLIF(ap.transaction_description,''))           AS memo,
                   CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END          AS soll,
                   CASE WHEN t.amount > 0 THEN  t.amount ELSE 0 END          AS haben,
                   (SELECT opening FROM eroeffnung) - SUM(t.amount) OVER (
                       ORDER BY t.transdate, t.trans_id, t.acc_trans_id
                       ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                   )                                                         AS saldo
            FROM acc_trans t
            CROSS JOIN p
            LEFT JOIN ar ON ar.id = t.trans_id
            LEFT JOIN ap ON ap.id = t.trans_id
            LEFT JOIN gl ON gl.id = t.trans_id
            LEFT JOIN customer cu ON cu.id = ar.customer_id
            LEFT JOIN vendor   ve ON ve.id = ap.vendor_id
            WHERE t.chart_id = (SELECT id FROM konto)
              AND t.transdate BETWEEN p.von AND p.bis
        )
        SELECT
            (SELECT accno       FROM konto)      AS accno,
            (SELECT description FROM konto)      AS description,
            (SELECT company     FROM defaults)   AS company,
            (SELECT opening     FROM eroeffnung) AS opening,
            COALESCE((SELECT opening FROM eroeffnung), 0)
                + COALESCE(SUM(z.soll), 0) - COALESCE(SUM(z.haben), 0)   AS closing,
            COALESCE(SUM(z.soll), 0)                                     AS sum_soll,
            COALESCE(SUM(z.haben), 0)                                    AS sum_haben,
            COUNT(z.*)::int                                              AS row_count,
            COALESCE(json_agg(row_to_json(z) ORDER BY z.transdate ASC)
                     FILTER (WHERE z.transdate IS NOT NULL), '[]')       AS rows
        FROM zeilen z
    SQL, ['von' => $from, 'bis' => $to, 'chart_id' => $chartId, 'accno' => $accno]);

    if (!$report || $report['accno'] === null) {
        resultInfo(false, 'DATA_NOT_FOUND', 'Konto nicht gefunden');
        return;
    }

    $rows = json_decode($report['rows'] ?? '[]', true) ?: [];

    require_once __DIR__ . '/../lib/report_pdf.php';

    $pdf = new ReportPdf('P', 'mm', 'A4');
    $pdf->reportTitle = 'Kontoblatt ' . $report['accno'];
    $pdf->reportLines = array_filter([
        $report['company'] ?? '',
        $report['description'] ?? '',
        'Zeitraum: ' . date('d.m.Y', strtotime($from)) . ' bis ' . date('d.m.Y', strtotime($to)),
    ]);
    $pdf->columns = [
        ['w' => 19, 'label' => 'Datum'],
        ['w' => 26, 'label' => 'Beleg'],
        ['w' => 40, 'label' => 'Kunde/Lieferant'],
        ['w' => 45, 'label' => 'Buchungstext'],
        ['w' => 16, 'label' => 'Soll',  'align' => 'R'],
        ['w' => 16, 'label' => 'Haben', 'align' => 'R'],
        ['w' => 18, 'label' => 'Saldo', 'align' => 'R'],
    ];
    $pdf->footNote = 'Erstellt am ' . date('d.m.Y H:i') . ' · ' . ($report['company'] ?? '');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $pdf->row([
        date('d.m.Y', strtotime($from)), '',
        ['text' => 'Eröffnungssaldo', 'bold' => true], '', '', '',
        ['text' => ReportPdf::money($report['opening'] ?? 0), 'bold' => true],
    ], false);

    foreach ($rows as $row) {
        $pdf->row([
            date('d.m.Y', strtotime($row['transdate'])),
            $row['reference'] ?: '',
            ['text' => $row['partner'] ?: '', 'wrap' => true, 'maxLines' => 2],
            ['text' => $row['memo'] ?: '',    'wrap' => true, 'maxLines' => 2],
            ReportPdf::money($row['soll'],  true),
            ReportPdf::money($row['haben'], true),
            ReportPdf::money($row['saldo']),
        ]);
    }

    if (!$rows) $pdf->row(['', '', 'Keine Buchungen im gewählten Zeitraum.', '', '', '', '']);

    $pdf->totalRow([
        '', '', 'Summen und Endsaldo (' . (int)($report['row_count'] ?? 0) . ' Buchungen)', '',
        ['text' => ReportPdf::money($report['sum_soll']  ?? 0), 'align' => 'R'],
        ['text' => ReportPdf::money($report['sum_haben'] ?? 0), 'align' => 'R'],
        ['text' => ReportPdf::money($report['closing']   ?? 0), 'align' => 'R'],
    ]);

    resultInfo(true, '', [
        'filename' => 'kontoblatt_' . $report['accno'] . '_' . date('Y-m-d') . '.pdf',
        'mime'     => 'application/pdf',
        'data'     => base64_encode($pdf->Output('S')),
    ]);
}
