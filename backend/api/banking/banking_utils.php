<?php
// backend/api/banking/banking_utils.php
//
// Hilfsfunktionen: BIC-Lookup, Alerts, Liquiditätsplanung, Belegnummern

// ─── Fortlaufende Belegnummern ─────────────────────────────

/**
 * Nächste fortlaufende Belegnummer für einen Belegkreis (Geldkonto) und Jahr.
 *
 * Belegnummern sind fortlaufende Ganzzahlen je Geldkonto (Kasse bzw. Bankkonto)
 * und Kalenderjahr. Es wird die höchste bereits vergebene Nummer gesucht; +1 ist
 * die nächste. Gibt es noch keine (z. B. zu Jahresbeginn), wird 1 zurückgegeben.
 *
 * Die höchste Nummer wird aus ALLEN Speicherorten ermittelt, damit pro Konto
 * eine einzige durchgehende Sequenz entsteht:
 *  - gl.reference (numerisch): native kivitendo-Kassenbuchungen (Dialogbuchen)
 *    legen die Belegnummer hier ab.
 *  - acc_trans.source (numerisch): Kassen-/Faktura-Zahlungen legen die Nummer hier ab.
 *    Native Rechnungs-Barzahlungen tragen aber die Rechnungsnummer in source — diese
 *    werden ausgeschlossen (source = ar/ap-invnumber), damit die Sequenz nicht springt.
 *  - acc_trans.memo  ("Beleg N · …"): Bankabstimmungen behalten source='BANK'
 *    (von Faktura-Schutz/Storno benötigt) und betten die Nummer ins memo ein.
 *
 * Die CASE-Ausdrücke verhindern Cast-Fehler bei nicht-numerischem Wert.
 *
 * @param object $db        DB-Handle (DbhCompany)
 * @param int    $chartId   Geldkonto (Belegkreis)
 * @param string $transdate Buchungsdatum YYYY-MM-DD (bestimmt das Jahr)
 * @return string Belegnummer als String (z. B. "1")
 */
function nextBelegnummer($db, $chartId, $transdate) {
    $year = intval(substr((string)$transdate, 0, 4)) ?: intval(date('Y'));

    $row = $db->getOne("
        SELECT GREATEST(
            -- GL-Kassenbuchungen: kivitendo legt die Belegnummer in gl.reference ab
            COALESCE(MAX(CASE WHEN gl.reference ~ '^[0-9]+$'
                              THEN gl.reference::bigint END), 0),
            -- acc_trans.source, aber OHNE native Rechnungsnummern (source = ar/ap-invnumber)
            COALESCE(MAX(CASE WHEN at.source ~ '^[0-9]+$'
                              AND NOT EXISTS (SELECT 1 FROM ar WHERE invnumber = at.source)
                              AND NOT EXISTS (SELECT 1 FROM ap WHERE invnumber = at.source)
                              THEN at.source::bigint END), 0),
            -- Bankabstimmung: Belegnummer als 'Beleg N · …' im memo
            COALESCE(MAX(CASE WHEN at.memo ~ '^Beleg [0-9]+'
                              THEN substring(at.memo from '^Beleg ([0-9]+)')::bigint END), 0)
        ) + 1 AS next
        FROM acc_trans at
        LEFT JOIN gl ON gl.id = at.trans_id
        WHERE at.chart_id = :cid
          AND EXTRACT(YEAR FROM at.transdate) = :year
    ", ['cid' => $chartId, 'year' => $year]);

    return strval(intval($row['next'] ?? 1) ?: 1);
}

/**
 * Nächste fortlaufende Belegnummer eines Geldkontos (für den Zahlungsbeleg im
 * Rechnungseditor / die Bankmodul-Buchung)
 *
 * @param int    $data['chart_id']  Geldkonto (chart.id)
 * @param string $data['transdate'] Datum (optional, bestimmt das Jahr)
 * @testdata {"chart_id": 1}
 */
function getNextBelegnummer($data) {
    $db = DbhCompany::begin();

    $chartId = intval($data['chart_id'] ?? 0);
    if ($chartId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'chart_id fehlt');
        return;
    }

    $transdate = !empty($data['transdate']) ? $data['transdate'] : date('Y-m-d');

    resultInfo(true, '', ['belegnummer' => nextBelegnummer($db, $chartId, $transdate)]);
}

// ─── BIC-Lookup aus IBAN ──────────────────────────────────

/**
 * BIC und Bankname aus einer deutschen IBAN ermitteln.
 * Extrahiert BLZ (IBAN-Zeichen 5–12), sucht in fints-banks.json.
 *
 * @param string $data['iban']
 * @testdata {"iban":"DE08170540403500005097"}
 */
function lookupBicFromIban($data) {
    $iban = strtoupper(str_replace(' ', '', trim($data['iban'] ?? '')));
    if (!$iban) {
        resultInfo(false, 'VALIDATION_ERROR', 'IBAN fehlt');
        return;
    }
    // Nur DE-IBANs unterstützen BLZ-basierte Suche
    if (substr($iban, 0, 2) !== 'DE' || strlen($iban) !== 22) {
        resultInfo(true, '', ['bic' => null, 'bank_name' => null, 'bank_code' => null]);
        return;
    }
    $blz = substr($iban, 4, 8);
    $banks = json_decode(@file_get_contents(__DIR__ . '/fints-banks.json'), true) ?: [];
    $bank  = $banks['exact'][$blz] ?? null;
    resultInfo(true, '', [
        'bic'       => $bank['bic']  ?? null,
        'bank_name' => $bank['name'] ?? null,
        'bank_code' => $blz,
    ]);
}

// ─── Alerts ───────────────────────────────────────────────

/**
 * Banking-Alerts ermitteln: niedrige Salden, überfällige Daueraufträge,
 * alte nicht-zugeordnete Umsätze, hängende TAN-Aufträge.
 *
 * @param int    $data['bank_account_id']     Optional: nur ein Konto prüfen
 * @param float  $data['balance_threshold']   Mindestsaldo für Warnung (default: 500)
 * @testdata {}
 */
function getBankingAlerts($data) {
    $db        = DbhCompany::begin();
    $accountId = $data['bank_account_id'] ? intval($data['bank_account_id']) : null;
    $threshold = floatval($data['balance_threshold'] ?? 500);
    $alerts    = [];

    $accWhere  = $accountId ? 'AND ba.id = :aid' : '';
    $accParams = $accountId ? ['aid' => $accountId] : [];

    // 1) Niedriger Kontostand
    $balances = $db->getAll(<<<SQL
        SELECT ba.id, ba.name,
               COALESCE(ba.reconciliation_starting_balance, 0)
               + COALESCE((SELECT SUM(bt.amount) FROM bank_transactions bt WHERE bt.local_bank_account_id = ba.id), 0)
               AS balance
        FROM bank_accounts ba
        WHERE ba.obsolete IS NOT TRUE {$accWhere}
    SQL, $accParams);
    foreach ($balances as $b) {
        if ((float)$b['balance'] < $threshold) {
            $alerts[] = [
                'type'    => 'low_balance',
                'level'   => (float)$b['balance'] < 0 ? 'error' : 'warning',
                'account' => $b['name'],
                'message' => "Niedriger Kontostand: " . number_format((float)$b['balance'], 2, ',', '.') . " €",
            ];
        }
    }

    // 2) Überfällige Daueraufträge
    $params2 = array_merge(['today' => date('Y-m-d')], $accParams);
    $overdueWhere = $accountId ? 'AND bank_account_id = :aid' : '';
    $overdue = $db->getAll(<<<SQL
        SELECT so.remote_name, so.amount, so.next_execution_date,
               ba.name AS account_name
        FROM standing_orders so
        JOIN bank_accounts ba ON ba.id = so.bank_account_id
        WHERE so.status = 'active'
          AND so.next_execution_date < :today
          {$overdueWhere}
    SQL, $params2);
    foreach ($overdue as $o) {
        $alerts[] = [
            'type'    => 'overdue_standing_order',
            'level'   => 'warning',
            'account' => $o['account_name'],
            'message' => "Überfälliger Dauerauftrag an {$o['remote_name']}: " . number_format((float)$o['amount'], 2, ',', '.') . " € (fällig seit {$o['next_execution_date']})",
        ];
    }

    // 3) Nicht zugeordnete Umsätze älter als 7 Tage
    $params3 = array_merge(['cutoff' => date('Y-m-d', strtotime('-7 days'))], $accParams);
    $unmatchWhere = $accountId ? 'AND bt.local_bank_account_id = :aid' : '';
    $oldUnmatched = $db->getOne(<<<SQL
        SELECT COUNT(*)::int AS cnt
        FROM bank_transactions bt
        WHERE bt.match_status = 'unmatched'
          AND bt.transdate < :cutoff
          {$unmatchWhere}
    SQL, $params3);
    if (($oldUnmatched['cnt'] ?? 0) > 0) {
        $alerts[] = [
            'type'    => 'old_unmatched',
            'level'   => 'info',
            'account' => null,
            'message' => "{$oldUnmatched['cnt']} nicht zugeordnete Umsätze älter als 7 Tage",
        ];
    }

    // 4) Überweisungen im Status pending_tan > 24h (hängende TAN)
    $stuckWhere = $accountId ? 'AND bto.bank_account_id = :aid' : '';
    $stuckParams = array_merge(['cutoff' => date('Y-m-d H:i:s', strtotime('-1 day'))], $accParams);
    $stuck = $db->getOne(<<<SQL
        SELECT COUNT(*)::int AS cnt
        FROM bank_transfer_orders bto
        WHERE bto.status = 'pending_tan'
          AND bto.mtime < :cutoff
          {$stuckWhere}
    SQL, $stuckParams);
    if (($stuck['cnt'] ?? 0) > 0) {
        $alerts[] = [
            'type'    => 'stuck_transfers',
            'level'   => 'warning',
            'account' => null,
            'message' => "{$stuck['cnt']} Überweisungen warten seit >24h auf TAN-Bestätigung",
        ];
    }

    resultInfo(true, '', ['alerts' => $alerts]);
}

// ─── Liquiditätsplanung ───────────────────────────────────

/**
 * 30-Tage-Liquiditätsvorschau für ein Konto.
 * Kombiniert: Kontostand + geplante Überweisungen + Daueraufträge
 * + offene AR/AP-Rechnungen nach Fälligkeitsdatum.
 *
 * @param int $data['bank_account_id']
 * @param int $data['days']             Vorschau in Tagen (default 30)
 * @testdata {"bank_account_id":1,"days":30}
 */
function getLiquidityForecast($data) {
    $db        = DbhCompany::begin();
    $accountId = intval($data['bank_account_id'] ?? 0);
    $days      = intval($data['days'] ?? 30);
    if (!$accountId) { resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt'); return; }

    // Aktueller Kontostand
    $balRow = $db->getOne(<<<SQL
        SELECT COALESCE(ba.reconciliation_starting_balance, 0)
               + COALESCE((SELECT SUM(bt.amount) FROM bank_transactions bt WHERE bt.local_bank_account_id = :id), 0)
               AS balance,
               ba.name
        FROM bank_accounts ba WHERE ba.id = :id
    SQL, ['id' => $accountId]);

    $startBalance = (float)($balRow['balance'] ?? 0);
    $today        = new \DateTime('today');
    $endDate      = (clone $today)->modify("+{$days} days");

    // Cashflows aggregieren: {dateStr => delta}
    $flows = [];

    // a) Geplante Überweisungs-Entwürfe (outgoing, negative)
    // Überfällige Entwürfe (execution_date in Vergangenheit) werden auf heute angesetzt
    $transfers = $db->getAll(<<<SQL
        SELECT GREATEST(COALESCE(execution_date, CURRENT_DATE), CURRENT_DATE) AS planned_date,
               -SUM(amount) AS delta, 'transfer' AS type
        FROM bank_transfer_orders
        WHERE bank_account_id = :id
          AND status IN ('draft','pending_tan')
        GROUP BY 1
    SQL, ['id' => $accountId]);
    foreach ($transfers as $t) {
        $d = $t['planned_date'];
        if ($d <= $endDate->format('Y-m-d'))
            $flows[$d] = ($flows[$d] ?? 0) + (float)$t['delta'];
    }

    // b) Daueraufträge im Zeitraum (outgoing, negative)
    // Überfällige Daueraufträge (next_execution_date in Vergangenheit) werden auf heute angesetzt
    $soList = $db->getAll(
        "SELECT next_execution_date, -amount AS delta FROM standing_orders WHERE bank_account_id = :id AND status = 'active'",
        ['id' => $accountId]
    );
    foreach ($soList as $so) {
        $d = $so['next_execution_date'];
        if ($d && $d <= $endDate->format('Y-m-d')) {
            $d = max($d, $today->format('Y-m-d'));
            $flows[$d] = ($flows[$d] ?? 0) + (float)$so['delta'];
        }
    }

    $arRows = 0; $apRows = 0; $arError = null; $apError = null;

    // c) Offene Ausgangsrechnungen (AR = Forderungen, incoming, positive)
    // Nur zukünftige Fälligkeiten — überfällige Forderungen haben unbekannten Zahlungseingang
    try {
        $ar = $db->getAll(<<<SQL
            SELECT TO_CHAR(COALESCE(ar.duedate, ar.transdate + interval '30 days'), 'YYYY-MM-DD') AS planned_date,
                   SUM(ar.amount - COALESCE(ar.paid, 0)) AS delta
            FROM ar
            WHERE COALESCE(ar.paid, 0) < ar.amount
              AND COALESCE(ar.storno, FALSE) IS NOT TRUE
            GROUP BY 1
            HAVING SUM(ar.amount - COALESCE(ar.paid, 0)) > 0
        SQL);
        foreach ($ar as $row) {
            $d = $row['planned_date'];
            if ($d >= $today->format('Y-m-d') && $d <= $endDate->format('Y-m-d')) {
                $flows[$d] = ($flows[$d] ?? 0) + (float)$row['delta'];
                $arRows++;
            }
        }
    } catch (\Throwable $e) {
        $arError = $e->getMessage();
        writeLog('[Liquidität] AR-Abfrage fehlgeschlagen: ' . $e->getMessage(), true, DLOG_WRN);
    }

    // d) Offene Eingangsrechnungen (AP = Verbindlichkeiten, outgoing, negative)
    // Überfällige Verbindlichkeiten werden auf heute angesetzt — sie sind sofort fällig
    try {
        $ap = $db->getAll(<<<SQL
            SELECT TO_CHAR(GREATEST(COALESCE(ap.duedate, ap.transdate + interval '30 days'), CURRENT_DATE), 'YYYY-MM-DD') AS planned_date,
                   -SUM(ap.amount - COALESCE(ap.paid, 0)) AS delta
            FROM ap
            WHERE COALESCE(ap.paid, 0) < ap.amount
              AND COALESCE(ap.storno, FALSE) IS NOT TRUE
            GROUP BY 1
            HAVING SUM(ap.amount - COALESCE(ap.paid, 0)) > 0
        SQL);
        foreach ($ap as $row) {
            $d = $row['planned_date'];
            if ($d <= $endDate->format('Y-m-d')) {
                $flows[$d] = ($flows[$d] ?? 0) + (float)$row['delta'];
                $apRows++;
            }
        }
    } catch (\Throwable $e) {
        $apError = $e->getMessage();
        writeLog('[Liquidität] AP-Abfrage fehlgeschlagen: ' . $e->getMessage(), true, DLOG_WRN);
    }

    // e) Wiederkehrende Abgänge aus Transaktionshistorie extrapolieren
    // Erkennt gleiche Empfänger mit regelmäßigem Abstand (5–400 Tage) in den letzten 12 Monaten.
    // Empfänger die bereits als aktiver Dauerauftrag erfasst sind, werden ausgelassen.
    $extrapolated = $db->getAll(<<<SQL
        WITH base AS (
            SELECT bt.remote_name, bt.remote_iban, bt.transdate, bt.amount
            FROM bank_transactions bt
            WHERE bt.local_bank_account_id = :id
              AND bt.amount < 0
              AND bt.remote_name IS NOT NULL AND bt.remote_name <> ''
              AND bt.transdate >= CURRENT_DATE - INTERVAL '12 months'
              AND NOT EXISTS (
                  SELECT 1 FROM standing_orders so
                  WHERE so.bank_account_id = :id
                    AND so.status = 'active'
                    AND so.remote_iban IS NOT NULL
                    AND so.remote_iban = bt.remote_iban
              )
        ),
        with_lag AS (
            SELECT remote_name, remote_iban, transdate, amount,
                   LAG(transdate) OVER (PARTITION BY remote_name, COALESCE(remote_iban, '') ORDER BY transdate) AS prev_date
            FROM base
        )
        SELECT
            remote_name,
            remote_iban,
            ROUND(AVG(amount)::numeric, 2)        AS avg_amount,
            MAX(transdate)                         AS last_date,
            ROUND(AVG(transdate - prev_date))::int AS interval_days
        FROM with_lag
        WHERE prev_date IS NOT NULL
        GROUP BY remote_name, remote_iban
        HAVING ROUND(AVG(transdate - prev_date)) BETWEEN 5 AND 400
    SQL, ['id' => $accountId]);

    $extrapolatedRows = 0;
    foreach ($extrapolated as $row) {
        $interval = (int)$row['interval_days'];
        $dt       = new \DateTime($row['last_date']);
        $dt->modify("+{$interval} days");
        $loops    = 0;
        while ($dt->format('Y-m-d') <= $endDate->format('Y-m-d') && $loops < 5) {
            $d = $dt->format('Y-m-d');
            if ($d >= $today->format('Y-m-d')) {
                $flows[$d] = ($flows[$d] ?? 0) + (float)$row['avg_amount']; // negativ = Abgang
                $extrapolatedRows++;
            }
            $dt->modify("+{$interval} days");
            $loops++;
        }
    }

    // Laufenden Saldo berechnen
    $result   = [];
    $running  = $startBalance;
    $dt       = clone $today;
    while ($dt <= $endDate) {
        $dateStr  = $dt->format('Y-m-d');
        $delta    = $flows[$dateStr] ?? 0;
        $running += $delta;
        $result[] = [
            'date'    => $dateStr,
            'balance' => round($running, 2),
            'delta'   => round($delta, 2),
            'label'   => date('d.m.', strtotime($dateStr)),  // Anzeigedatum für Frontend
        ];
        $dt->modify('+1 day');
    }

    resultInfo(true, '', [
        'account_name'   => $balRow['name'] ?? '',
        'start_balance'  => round($startBalance, 2),
        'forecast'       => $result,
        'debug'          => [
            'ar_rows'      => $arRows,
            'ap_rows'      => $apRows,
            'extrapolated' => $extrapolatedRows,
            'ar_error'     => $arError,
            'ap_error'     => $apError,
        ],
    ]);
}

// ─── Kontoauszug exportieren ──────────────────────────────

/**
 * Kontoauszug als CSV zurückgeben (base64-kodiert im JSON).
 *
 * @param int    $data['bank_account_id']
 * @param string $data['from_date']       optional
 * @param string $data['to_date']         optional
 * @testdata {"bank_account_id":1}
 */
function exportTransactionsCsv($data) {
    $db        = DbhCompany::begin();
    $accountId = intval($data['bank_account_id'] ?? 0);
    if (!$accountId) { resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt'); return; }

    $where  = ['bt.local_bank_account_id = :id'];
    $params = ['id' => $accountId];
    if (!empty($data['from_date'])) { $where[] = 'bt.transdate >= :from'; $params['from'] = $data['from_date']; }
    if (!empty($data['to_date']))   { $where[] = 'bt.transdate <= :to';   $params['to']   = $data['to_date']; }
    $wClause = implode(' AND ', $where);

    $rows = $db->getAll(<<<SQL
        SELECT bt.transdate, bt.valutadate, bt.amount, bt.remote_name, bt.remote_iban,
               bt.purpose, bt.match_status
        FROM bank_transactions bt
        WHERE {$wClause}
        ORDER BY bt.transdate DESC, bt.id DESC
    SQL, $params);

    $acct = $db->getOne("SELECT name, iban FROM bank_accounts WHERE id = :id", ['id' => $accountId]);

    $lines   = [];
    $lines[] = "Konto;" . ($acct['name'] ?? '') . ";" . ($acct['iban'] ?? '');
    $lines[] = "Datum;Valuta;Betrag (EUR);Auftraggeber/Empfänger;IBAN;Verwendungszweck;Status";
    foreach ($rows as $r) {
        $lines[] = implode(';', [
            $r['transdate'],
            $r['valutadate'],
            number_format((float)$r['amount'], 2, ',', '.'),
            '"' . str_replace('"', '""', $r['remote_name'] ?? '') . '"',
            $r['remote_iban'] ?? '',
            '"' . str_replace('"', '""', $r['purpose'] ?? '') . '"',
            $r['match_status'] ?? '',
        ]);
    }
    $csv = "\xEF\xBB\xBF" . implode("\n", $lines); // BOM für Excel

    resultInfo(true, '', [
        'filename' => 'kontoauszug_' . date('Y-m-d') . '.csv',
        'mime'     => 'text/csv',
        'data'     => base64_encode($csv),
    ]);
}

/**
 * Kontoauszug als PDF (FPDF) zurückgeben (base64-kodiert im JSON).
 *
 * @param int    $data['bank_account_id']
 * @param string $data['from_date']       optional
 * @param string $data['to_date']         optional
 * @testdata {"bank_account_id":1}
 */
function exportTransactionsPdf($data) {
    $db        = DbhCompany::begin();
    $accountId = intval($data['bank_account_id'] ?? 0);
    if (!$accountId) { resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt'); return; }

    $where  = ['bt.local_bank_account_id = :id'];
    $params = ['id' => $accountId];
    if (!empty($data['from_date'])) { $where[] = 'bt.transdate >= :from'; $params['from'] = $data['from_date']; }
    if (!empty($data['to_date']))   { $where[] = 'bt.transdate <= :to';   $params['to']   = $data['to_date']; }

    $wClause2 = implode(' AND ', $where);
    $rows = $db->getAll(<<<SQL
        SELECT bt.transdate, bt.amount, bt.remote_name, bt.purpose, bt.match_status
        FROM bank_transactions bt
        WHERE {$wClause2}
        ORDER BY bt.transdate DESC, bt.id DESC
    SQL, $params);

    $acct    = $db->getOne("SELECT name, iban FROM bank_accounts WHERE id = :id", ['id' => $accountId]);
    $company = $db->getOne("SELECT company FROM defaults");

    require_once __DIR__.'/../../vendor/setasign/fpdf/fpdf.php';

    $pdf = new \FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 8, mb_convert_encoding('Kontoauszug', 'ISO-8859-1', 'UTF-8'), 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, mb_convert_encoding(($company['company'] ?? '') . ' · ' . ($acct['name'] ?? ''), 'ISO-8859-1', 'UTF-8'), 0, 1);
    $pdf->Cell(0, 6, mb_convert_encoding('IBAN: ' . ($acct['iban'] ?? ''), 'ISO-8859-1', 'UTF-8'), 0, 1);
    $pdf->Cell(0, 6, mb_convert_encoding('Erstellt: ' . date('d.m.Y H:i'), 'ISO-8859-1', 'UTF-8'), 0, 1);
    $pdf->Ln(3);

    // Tabellen-Header
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(22, 6, 'Datum', 1, 0, 'L', true);
    $pdf->Cell(70, 6, mb_convert_encoding('Auftraggeber/Empfänger', 'ISO-8859-1', 'UTF-8'), 1, 0, 'L', true);
    $pdf->Cell(80, 6, 'Verwendungszweck', 1, 0, 'L', true);
    $pdf->Cell(0,  6, 'Betrag', 1, 1, 'R', true);

    $pdf->SetFont('Arial', '', 8);
    foreach ($rows as $r) {
        $amtFmt = number_format((float)$r['amount'], 2, ',', '.') . ' EUR';
        $pdf->Cell(22, 5, $r['transdate'], 1, 0);
        $pdf->Cell(70, 5, mb_convert_encoding(mb_strimwidth($r['remote_name'] ?? '', 0, 40, '...'), 'ISO-8859-1', 'UTF-8'), 1, 0);
        $pdf->Cell(80, 5, mb_convert_encoding(mb_strimwidth($r['purpose'] ?? '', 0, 55, '...'), 'ISO-8859-1', 'UTF-8'), 1, 0);
        $pdf->Cell(0,  5, $amtFmt, 1, 1, 'R');
    }

    $pdfData = $pdf->Output('S');
    resultInfo(true, '', [
        'filename' => 'kontoauszug_' . date('Y-m-d') . '.pdf',
        'mime'     => 'application/pdf',
        'data'     => base64_encode($pdfData),
    ]);
}

/**
 * Überweisungsbestätigung als PDF (eine Seite).
 *
 * @param int $data['transfer_id']
 * @testdata {"transfer_id":1}
 */
function getTransferConfirmationPdf($data) {
    $db         = DbhCompany::begin();
    $transferId = intval($data['transfer_id'] ?? 0);
    if (!$transferId) { resultInfo(false, 'VALIDATION_ERROR', 'Transfer-ID fehlt'); return; }

    $order = $db->getOne(<<<SQL
        SELECT bto.*, ba.name AS account_name, ba.iban AS account_iban, ba.bic AS account_bic
        FROM bank_transfer_orders bto
        LEFT JOIN bank_accounts ba ON ba.id = bto.bank_account_id
        WHERE bto.id = :id
    SQL, ['id' => $transferId]);
    if (!$order) { resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden'); return; }

    $company = $db->getOne("SELECT company FROM defaults");

    require_once __DIR__.'/../../vendor/setasign/fpdf/fpdf.php';
    $pdf = new \FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(20, 20, 20);
    $pdf->AddPage();

    $e = fn($s) => mb_convert_encoding($s ?? '', 'ISO-8859-1', 'UTF-8');

    // Kopf
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, $e('Überweisungsauftrag'), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $e(($company['company'] ?? 'Autoprofis') . ' · ' . date('d.m.Y H:i')), 0, 1);
    $pdf->Ln(6);

    // Auftraggeber-Box
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, $e('Auftraggeber'), 'B', 1, 'L', true);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(50, 6, 'Konto:');  $pdf->Cell(0, 6, $e($order['account_name'] ?? ''), 0, 1);
    $pdf->Cell(50, 6, 'IBAN:');   $pdf->Cell(0, 6, $e($order['account_iban'] ?? ''), 0, 1);
    $pdf->Ln(4);

    // Empfänger-Box
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, $e('Empfänger'), 'B', 1, 'L', true);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(50, 6, 'Name:');   $pdf->Cell(0, 6, $e($order['remote_name'] ?? ''), 0, 1);
    $pdf->Cell(50, 6, 'IBAN:');   $pdf->Cell(0, 6, $e($order['remote_iban'] ?? ''), 0, 1);
    if ($order['remote_bic']) {
        $pdf->Cell(50, 6, 'BIC:');$pdf->Cell(0, 6, $e($order['remote_bic']), 0, 1);
    }
    $pdf->Ln(4);

    // Zahlungsdetails
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 7, $e('Zahlungsdetails'), 'B', 1, 'L', true);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(50, 6, 'Betrag:');            $pdf->SetFont('Arial', 'B', 12); $pdf->Cell(0, 6, number_format((float)$order['amount'], 2, ',', '.') . ' EUR', 0, 1); $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(50, 6, 'Verwendungszweck:');  $pdf->Cell(0, 6, $e($order['purpose'] ?? ''), 0, 1);
    if ($order['execution_date']) {
        $pdf->Cell(50, 6, $e('Ausführungsdatum:')); $pdf->Cell(0, 6, $e(date('d.m.Y', strtotime($order['execution_date']))), 0, 1);
    }
    $pdf->Cell(50, 6, 'Status:');            $pdf->Cell(0, 6, $e($order['status'] ?? ''), 0, 1);
    if ($order['submitted_at']) {
        $pdf->Cell(50, 6, $e('Gesendet am:')); $pdf->Cell(0, 6, date('d.m.Y H:i', strtotime($order['submitted_at'])), 0, 1);
    }
    $pdf->Ln(10);

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(150);
    $pdf->Cell(0, 5, $e('Auftragsnummer: ' . $order['id'] . ' · Erstellt: ' . date('d.m.Y H:i') . ' · ' . ($company['company'] ?? '')), 0, 1, 'C');

    resultInfo(true, '', [
        'filename' => 'ueberweisung_' . $transferId . '_' . date('Y-m-d') . '.pdf',
        'mime'     => 'application/pdf',
        'data'     => base64_encode($pdf->Output('S')),
    ]);
}
