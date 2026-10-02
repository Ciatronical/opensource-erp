<?php
// backend/api/banking/sumup_payouts.php
//
// Kartenabrechnung SumUp ohne Datei: Auszahlungen werden direkt ueber die
// SumUp-API geholt (GET /v1.0/merchants/{merchant_code}/payouts) und wie ein
// hochgeladener Bericht als payment_settlement_lines gespeichert. Die API liefert
// je Kartenzahlung einen Auszahlungs-Datensatz (type PAYOUT, amount = ausgezahlt,
// fee = Gebuehr, reference = "SUMUP PID1283966 PAYOUT 010726") sowie Abzuege
// (REFUND_DEDUCTION, CHARGE_BACK_DEDUCTION, ...). Alle Datensaetze mit derselben
// PID ergeben zusammen genau eine Bank-Gutschrift — exakt wie beim
// Transaktionsbericht (useSettlements.parseSumupMatrix).
//
// API-Schluessel und Haendlercode kommen aus den Einstellungen (defaults_oserp:
// sumup_api_key, sumup_merchant_code — Tab "CRM-Vorgaben" > SumUp). Der
// Schluessel braucht den Scope "payouts.read".

const SUMUP_PAYOUTS_API = 'https://api.sumup.com/v1.0';

/**
 * SumUp-Auszahlungen rund um einen Bankumsatz abrufen und als Abrechnung speichern.
 *
 * Zeitfenster: Auszahlungsdatum = Bankdatum, zur Sicherheit 3 Tage davor bis
 * 1 Tag danach. Bereits bekannte PIDs werden beim Speichern uebersprungen
 * (_settlementStore). Danach wird der passende Treffer wie bei
 * suggestSettlementMatch zurueckgegeben.
 *
 * @param int $data['bank_transaction_id'] Bankumsatz (SumUp-Gutschrift)
 * @testdata {"bank_transaction_id": 3181}
 */
function syncSumupPayouts($data) {
    $db = DbhCompany::begin();

    $btId = intval($data['bank_transaction_id'] ?? 0);
    if ($btId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt'); return; }

    $cfg = _sumupConfig($db);
    if (empty($cfg['api_key']) || empty($cfg['merchant_code'])) {
        resultInfo(false, 'SUMUP_NOT_CONFIGURED', 'SumUp-API-Schlüssel oder Händlercode fehlt in den Einstellungen');
        return;
    }

    $bt = $db->getOne(
        "SELECT bt.id, bt.amount, bt.transdate, bt.purpose, bt.remote_name,
                (SELECT v.id FROM vendor v WHERE v.name ILIKE 'sumup%' ORDER BY v.id LIMIT 1) AS sumup_vendor_id,
                (SELECT s.vendor_id FROM payment_settlements s WHERE s.provider ILIKE 'sumup%' AND s.vendor_id IS NOT NULL ORDER BY s.id DESC LIMIT 1) AS last_vendor_id
         FROM bank_transactions bt WHERE bt.id = :id",
        ['id' => $btId]
    );
    if (!$bt) { resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden'); return; }

    $start = date('Y-m-d', strtotime($bt['transdate'] . ' -3 days'));
    $end   = date('Y-m-d', strtotime($bt['transdate'] . ' +1 day'));
    $url   = SUMUP_PAYOUTS_API . '/merchants/' . rawurlencode($cfg['merchant_code'])
           . '/payouts?start_date=' . $start . '&end_date=' . $end . '&format=json&limit=1000&order=asc';

    $res = _sumupRequest('GET', $url, $cfg['api_key']);
    if ($res['status'] !== 200 || !is_array($res['body'])) {
        $msg = _sumupErrorMessage($res, 'HTTP ' . $res['status']);
        if ($res['status'] === 401 || $res['status'] === 403) {
            $msg .= ' — API-Schlüssel prüfen (Scope "payouts.read" nötig)';
        }
        writeLog("syncSumupPayouts: Umsatz #{$btId} {$start}..{$end} fehlgeschlagen: {$msg}", true, DLOG_ERR);
        resultInfo(false, 'SUMUP_API_ERROR', 'SumUp-Abruf fehlgeschlagen: ' . $msg);
        return;
    }

    $lines = _sumupPayoutLines($res['body'], _sumupTransactionDetails($cfg, $start, $end));

    // Alt-Zeilen derselben PIDs (Datei-Upload vor der API-Anbindung) um die
    // Einzelzahlungen ergaenzen, damit auch dort je Zahlung zugeordnet wird.
    if (_settlementHasTransactionsColumn($db)) {
        foreach ($lines as $l) {
            $db->execute(
                "UPDATE payment_settlement_lines SET transactions = :tx::jsonb
                 WHERE reference = :ref AND transactions IS NULL AND status <> 'booked'",
                ['tx' => json_encode($l['transactions']), 'ref' => $l['reference']]
            );
        }
    }

    $inserted = 0; $skipped = 0;
    if (count($lines) > 0) {
        $stored = _settlementStore($db, [
            'provider'    => 'SumUp',
            'vendor_id'   => intval($bt['last_vendor_id'] ?: $bt['sumup_vendor_id']) ?: null,
            'document_id' => null,
            'currency'    => 'EUR',
            'employee_id' => mitarbeiterId($data),
            'lines'       => $lines,
        ]);
        $inserted = $stored['inserted']; $skipped = $stored['skipped'];
    }

    // Passenden Treffer liefern (Logik wie suggestSettlementMatch).
    ob_start();
    suggestSettlementMatch(['bank_transaction_id' => $btId]);
    $match = json_decode(ob_get_clean(), true)['payload']['match'] ?? null;

    resultInfo(true, '', [
        'fetched'  => count($res['body']),
        'payouts'  => count($lines),
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'window'   => ['from' => $start, 'to' => $end],
        'match'    => $match,
    ]);
}

/**
 * Auszahlungs-Datensaetze der API je PID zu Abrechnungszeilen buendeln.
 *
 * netto = Summe amount, Gebuehr = Summe fee, brutto = netto + Gebuehr. Abzuege
 * (REFUND_DEDUCTION & Co.) tragen den Transaktionscode statt einer PID als
 * reference und bleiben aussen vor — der Nettobetrag je PID stimmt trotzdem mit
 * der Bank ueberein (live geprueft). Je Datensatz entsteht eine Einzelzahlung
 * {code, timestamp, gross, fee, net, description}; Zeitstempel und Beschreibung
 * kommen aus $details (transactions/history), wenn vorhanden.
 *
 * @param array $records API-Antwort /payouts
 * @param array $details transaction_code => {timestamp, description}
 */
function _sumupPayoutLines(array $records, array $details = []) {
    $byPid = [];
    foreach ($records as $rec) {
        if (($rec['status'] ?? 'SUCCESSFUL') !== 'SUCCESSFUL') continue;
        $ref = (string)($rec['reference'] ?? '');
        if (!preg_match('/PID\d+/i', $ref, $m)) continue;
        $pid    = strtoupper($m[0]);
        $amount = round(floatval($rec['amount'] ?? 0), 2);
        $fee    = round(abs(floatval($rec['fee'] ?? 0)), 2);
        $date   = substr((string)($rec['date'] ?? ''), 0, 10);
        if ($date === '') continue;
        if (!isset($byPid[$pid])) {
            $byPid[$pid] = ['reference' => $pid, 'payout_date' => $date, 'net' => 0.0, 'fee' => 0.0, 'transactions' => []];
        }
        $byPid[$pid]['net'] += $amount;
        $byPid[$pid]['fee'] += $fee;
        $code = (string)($rec['transaction_code'] ?? '');
        $byPid[$pid]['transactions'][] = [
            'code'        => $code,
            'timestamp'   => $details[$code]['timestamp'] ?? null,
            'gross'       => round($amount + $fee, 2),
            'fee'         => $fee,
            'net'         => $amount,
            'description' => $details[$code]['description'] ?? '',
        ];
    }

    $lines = [];
    foreach ($byPid as $l) {
        $net = round($l['net'], 2); $fee = round($l['fee'], 2);
        if ($net == 0.0 && $fee == 0.0) continue;
        $times = array_filter(array_map(fn($t) => $t['timestamp'] ? substr($t['timestamp'], 0, 10) : null, $l['transactions']));
        // Zeitraum = Tage der Kartenzahlungen; ohne Zeitstempel kurz vor der
        // Auszahlung (naechster Bankarbeitstag).
        $lines[] = [
            'payout_date'  => $l['payout_date'],
            'period_from'  => $times ? min($times) : date('Y-m-d', strtotime($l['payout_date'] . ' -4 days')),
            'period_to'    => $times ? max($times) : $l['payout_date'],
            'gross'        => round($net + $fee, 2),
            'fee'          => $fee,
            'net'          => $net,
            'reference'    => $l['reference'],
            'transactions' => $l['transactions'],
        ];
    }
    return $lines;
}

/**
 * Zeitstempel + Beschreibung der Kartenzahlungen eines Zeitraums
 * (GET /v2.1/merchants/{mc}/transactions/history). Scheitert der Abruf (z. B.
 * fehlender Scope transactions.history), geht es ohne weiter — die Zuordnung
 * ueber den Betrag funktioniert auch so.
 *
 * @return array transaction_code => {timestamp, description}
 */
function _sumupTransactionDetails(array $cfg, $start, $end) {
    $url = 'https://api.sumup.com/v2.1/merchants/' . rawurlencode($cfg['merchant_code'])
         . '/transactions/history?limit=1000&order=ascending'
         . '&oldest_time=' . rawurlencode(date('Y-m-d', strtotime($start . ' -7 days')) . 'T00:00:00Z')
         . '&newest_time=' . rawurlencode($end . 'T23:59:59Z');
    try {
        $res = _sumupRequest('GET', $url, $cfg['api_key']);
    } catch (\Throwable $e) {
        return [];
    }
    if ($res['status'] !== 200 || !is_array($res['body'])) return [];
    $out = [];
    foreach (($res['body']['items'] ?? []) as $it) {
        $code = (string)($it['transaction_code'] ?? '');
        if ($code === '') continue;
        $out[$code] = ['timestamp' => $it['timestamp'] ?? null, 'description' => (string)($it['product_summary'] ?? '')];
    }
    return $out;
}

/**
 * Einzelzahlungen einer Alt-Zeile (ohne transactions) per API nachladen und
 * speichern. Wird von findInvoicesForSettlementLine gerufen; liefert [] wenn
 * SumUp nicht konfiguriert ist oder die PID nicht im Fenster liegt.
 */
function _sumupBackfillTransactions($db, array $line) {
    $cfg = _sumupConfig($db);
    if (empty($cfg['api_key']) || empty($cfg['merchant_code'])) return [];
    $pid = strtoupper((string)$line['reference']);
    $start = date('Y-m-d', strtotime($line['payout_date'] . ' -1 day'));
    $end   = date('Y-m-d', strtotime($line['payout_date'] . ' +1 day'));
    $url   = SUMUP_PAYOUTS_API . '/merchants/' . rawurlencode($cfg['merchant_code'])
           . '/payouts?start_date=' . $start . '&end_date=' . $end . '&format=json&limit=1000&order=asc';
    try {
        $res = _sumupRequest('GET', $url, $cfg['api_key']);
    } catch (\Throwable $e) {
        return [];
    }
    if ($res['status'] !== 200 || !is_array($res['body'])) return [];
    foreach (_sumupPayoutLines($res['body'], _sumupTransactionDetails($cfg, $start, $end)) as $l) {
        if ($l['reference'] !== $pid) continue;
        $db->execute(
            "UPDATE payment_settlement_lines SET transactions = :tx::jsonb, period_from = :pf, period_to = :pt WHERE id = :id",
            ['tx' => json_encode($l['transactions']), 'pf' => $l['period_from'], 'pt' => $l['period_to'], 'id' => intval($line['id'])]
        );
        return $l['transactions'];
    }
    return [];
}
