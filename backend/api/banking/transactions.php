<?php
// backend/api/banking/transactions.php

/**
 * Bankumsaetze eines Kontos laden mit Zuordnungsstatus
 *
 * @param int    $data['bank_account_id'] Bankkonto-ID
 * @param string $data['from_date']       Von-Datum (optional)
 * @param string $data['to_date']         Bis-Datum (optional)
 * @param string $data['match_status']    Filter: unmatched|matched|booked|ignored|all (default: all)
 * @param string $data['search']          Volltextsuche (optional, mehrere Begriffe UND-verknüpft)
 * @param int    $data['limit']           Max. Ergebnisse (default: 200)
 * @param int    $data['offset']          Offset fuer Paginierung (default: 0)
 * @testdata {"bank_account_id": 1, "match_status": "all"}
 */
function getBankTransactions($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    if ($bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt');
        return;
    }

    $limit = min(intval($data['limit'] ?? 200), 1000);
    $offset = max(intval($data['offset'] ?? 0), 0);
    $matchStatus = $data['match_status'] ?? 'all';
    $fromDate = $data['from_date'] ?? null;
    $toDate = $data['to_date'] ?? null;

    $params = [
        'bank_account_id' => $bankAccountId,
        'limit_val' => $limit,
        'offset_val' => $offset
    ];

    $whereExtra = '';

    if ($matchStatus !== 'all' && in_array($matchStatus, ['unmatched', 'matched', 'booked', 'ignored'])) {
        $whereExtra .= " AND bt.match_status = :match_status";
        $params['match_status'] = $matchStatus;
    }

    if ($fromDate) {
        $whereExtra .= " AND bt.transdate >= :from_date";
        $params['from_date'] = $fromDate;
    }

    if ($toDate) {
        $whereExtra .= " AND bt.transdate <= :to_date";
        $params['to_date'] = $toDate;
    }

    // Volltext-Suche (serverseitig): durchsucht Gegenname, Verwendungszweck, IBAN,
    // Buchungstext, Betrag sowie zugeordnete/zugewiesene Rechnungsnummern und
    // Kunden-/Lieferantennamen. Mehrere Begriffe sind UND-verknüpft. Der Ausdruck
    // ist self-contained (referenziert nur bt) und wird in Liste + Count genutzt.
    $haySql = <<<HAY
        COALESCE(bt.remote_name, '') || ' ' ||
        COALESCE(bt.purpose, '') || ' ' ||
        COALESCE(bt.remote_iban, '') || ' ' ||
        COALESCE(bt.transaction_text, '') || ' ' ||
        bt.amount::text || ' ' ||
        COALESCE((
            SELECT string_agg(DISTINCT
                COALESCE(ar.invnumber, ap.invnumber, '') || ' ' ||
                COALESCE(c.name, '') || ' ' || COALESCE(v.name, ''), ' ')
            FROM bank_transaction_acc_trans bta
            LEFT JOIN ar ON ar.id = bta.ar_id
            LEFT JOIN ap ON ap.id = bta.ap_id
            LEFT JOIN customer c ON c.id = ar.customer_id
            LEFT JOIN vendor v ON v.id = ap.vendor_id
            WHERE bta.bank_transaction_id = bt.id
        ), '') || ' ' ||
        COALESCE((
            SELECT string_agg(DISTINCT
                COALESCE(arp.invnumber, app.invnumber, '') || ' ' ||
                COALESCE(cp.name, '') || ' ' || COALESCE(vp.name, ''), ' ')
            FROM bank_transaction_matches m
            LEFT JOIN ar arp ON m.target_type = 'ar' AND arp.id = m.target_id
            LEFT JOIN ap app ON m.target_type = 'ap' AND app.id = m.target_id
            LEFT JOIN customer cp ON cp.id = arp.customer_id
            LEFT JOIN vendor vp ON vp.id = app.vendor_id
            WHERE m.bank_transaction_id = bt.id
        ), '')
HAY;

    $search = trim($data['search'] ?? '');
    $searchJoin = '';
    $searchWhere = '';
    $searchParams = [];
    if ($search !== '') {
        $searchJoin = "LEFT JOIN LATERAL (SELECT {$haySql} AS hay) srch ON true";
        $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $idx = 0;
        foreach ($tokens as $tok) {
            $key = 'srch' . $idx;
            $searchWhere .= " AND srch.hay ILIKE :{$key}";
            $searchParams[$key] = '%' . $tok . '%';
            $idx++;
        }
    }
    $params = array_merge($params, $searchParams);

    // Umsaetze laden (getOne, weil json_agg nur eine Zeile liefert)
    $result = $db->getOne(<<<SQL
        SELECT json_agg(row_to_json(t)) as transactions
        FROM (
            SELECT
                bt.id,
                bt.transdate,
                bt.valutadate,
                bt.amount,
                bt.remote_name,
                bt.remote_iban,
                bt.remote_bank_code,
                bt.remote_account_number,
                bt.purpose,
                bt.end_to_end_id,
                bt.match_status,
                bt.cleared,
                bt.transaction_code,
                bt.transaction_text,
                -- Pre-Booking-Mapping (matched aber noch nicht gebucht)
                btm.target_type   AS pending_target_type,
                btm.target_id     AS pending_target_id,
                CASE btm.target_type
                    WHEN 'ar' THEN ar_pending.invnumber
                    WHEN 'ap' THEN ap_pending.invnumber
                END AS pending_invnumber,
                CASE btm.target_type
                    WHEN 'ar' THEN c_pending.name
                    WHEN 'ap' THEN v_pending.name
                END AS pending_target_name,
                -- Booking-Zuordnungen (kivitendo bank_transaction_acc_trans).
                -- DISTINCT, weil pro Rechnung mehrere acc_trans-Beine (Forderung +
                -- Bank) verknuepft sind — sonst erscheint die Rechnungsnummer doppelt.
                (
                    SELECT json_agg(a)
                    FROM (
                        SELECT DISTINCT
                            bta.ar_id,
                            bta.ap_id,
                            bta.gl_id,
                            COALESCE(ar.invnumber, ap.invnumber) AS invnumber,
                            c.name AS customer_name,
                            v.name AS vendor_name
                        FROM bank_transaction_acc_trans bta
                        LEFT JOIN ar ON ar.id = bta.ar_id
                        LEFT JOIN ap ON ap.id = bta.ap_id
                        LEFT JOIN customer c ON c.id = ar.customer_id
                        LEFT JOIN vendor v ON v.id = ap.vendor_id
                        WHERE bta.bank_transaction_id = bt.id
                    ) a
                ) as assignments
            FROM bank_transactions bt
            LEFT JOIN bank_transaction_matches btm ON btm.bank_transaction_id = bt.id
            LEFT JOIN ar       ar_pending ON btm.target_type = 'ar' AND ar_pending.id = btm.target_id
            LEFT JOIN ap       ap_pending ON btm.target_type = 'ap' AND ap_pending.id = btm.target_id
            LEFT JOIN customer c_pending  ON c_pending.id = ar_pending.customer_id
            LEFT JOIN vendor   v_pending  ON v_pending.id = ap_pending.vendor_id
            {$searchJoin}
            WHERE bt.local_bank_account_id = :bank_account_id
                {$whereExtra}
                {$searchWhere}
            ORDER BY bt.transdate DESC, bt.id DESC
            LIMIT :limit_val OFFSET :offset_val
        ) t
    SQL, $params);

    // Gesamtanzahl fuer Paginierung
    $countParams = ['bank_account_id' => $bankAccountId];
    $countWhere = '';

    if ($matchStatus !== 'all' && in_array($matchStatus, ['unmatched', 'matched', 'booked', 'ignored'])) {
        $countWhere .= " AND bt.match_status = :match_status";
        $countParams['match_status'] = $matchStatus;
    }
    if ($fromDate) {
        $countWhere .= " AND bt.transdate >= :from_date";
        $countParams['from_date'] = $fromDate;
    }
    if ($toDate) {
        $countWhere .= " AND bt.transdate <= :to_date";
        $countParams['to_date'] = $toDate;
    }

    $countParams = array_merge($countParams, $searchParams);
    $count = $db->getOne(<<<SQL
        SELECT COUNT(*)::INTEGER as total
        FROM bank_transactions bt
        {$searchJoin}
        WHERE bt.local_bank_account_id = :bank_account_id
            {$countWhere}
            {$searchWhere}
    SQL, $countParams);

    $transactions = json_decode($result['transactions'] ?? '[]', true) ?: [];

    resultInfo(true, '', [
        'transactions' => $transactions,
        'total' => $count['total'] ?? 0,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

/**
 * Einzelnen Bankumsatz mit Details laden
 *
 * @param int $data['transaction_id'] Bankumsatz-ID
 * @testdata {"transaction_id": 1}
 */
function getBankTransaction($data) {
    $db = DbhCompany::begin();

    $transactionId = intval($data['transaction_id'] ?? 0);
    if ($transactionId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt');
        return;
    }

    $result = $db->getOne(<<<SQL
        SELECT
            bt.*,
            ba.name as account_name,
            ba.iban as account_iban,
            (
                SELECT json_agg(a)
                FROM (
                    SELECT DISTINCT
                        bta.ar_id,
                        bta.ap_id,
                        bta.gl_id,
                        COALESCE(ar.invnumber, ap.invnumber) AS invnumber,
                        c.name AS customer_name,
                        v.name AS vendor_name,
                        COALESCE(ar.amount, ap.amount) AS amount
                    FROM bank_transaction_acc_trans bta
                    LEFT JOIN ar ON ar.id = bta.ar_id
                    LEFT JOIN ap ON ap.id = bta.ap_id
                    LEFT JOIN customer c ON c.id = ar.customer_id
                    LEFT JOIN vendor v ON v.id = ap.vendor_id
                    WHERE bta.bank_transaction_id = bt.id
                ) a
            ) as assignments
        FROM bank_transactions bt
        JOIN bank_accounts ba ON ba.id = bt.local_bank_account_id
        WHERE bt.id = :transaction_id
    SQL, ['transaction_id' => $transactionId]);

    if (!$result) {
        resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden');
        return;
    }

    resultInfo(true, '', ['transaction' => $result]);
}

/**
 * Bankumsatz als ignoriert markieren / zuruecksetzen
 *
 * @param int    $data['transaction_id'] Bankumsatz-ID
 * @param string $data['status']         Neuer Status: ignored|unmatched
 * @testdata {"transaction_id": 1, "status": "ignored"}
 */
function setTransactionStatus($data) {
    $db = DbhCompany::begin();

    $transactionId = intval($data['transaction_id'] ?? 0);
    $status = $data['status'] ?? '';

    if ($transactionId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt');
        return;
    }

    if (!in_array($status, ['ignored', 'unmatched'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungueltiger Status');
        return;
    }

    $db->execute(
        "UPDATE bank_transactions SET match_status = :status WHERE id = :id",
        ['id' => $transactionId, 'status' => $status]
    );

    resultInfo(true, 'Status aktualisiert');
}

/**
 * Kontostatistik (Zusammenfassung) laden
 *
 * @param int $data['bank_account_id'] Bankkonto-ID
 * @testdata {"bank_account_id": 1}
 */
function getBankAccountStats($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    if ($bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt');
        return;
    }

    $result = $db->getOne(<<<SQL
        SELECT
            ba.name,
            ba.iban,
            ba.bank,
            COALESCE(ba.reconciliation_starting_balance, 0)
                + COALESCE(SUM(bt.amount), 0) as balance,
            COUNT(bt.id)::INTEGER as total_transactions,
            COUNT(bt.id) FILTER (WHERE bt.match_status = 'unmatched')::INTEGER as unmatched,
            COUNT(bt.id) FILTER (WHERE bt.match_status = 'matched')::INTEGER as matched,
            COUNT(bt.id) FILTER (WHERE bt.match_status = 'booked')::INTEGER as booked,
            COUNT(bt.id) FILTER (WHERE bt.match_status = 'ignored')::INTEGER as ignored,
            COALESCE(SUM(bt.amount) FILTER (WHERE bt.amount > 0 AND bt.transdate >= date_trunc('month', CURRENT_DATE)), 0) as income_this_month,
            COALESCE(SUM(bt.amount) FILTER (WHERE bt.amount < 0 AND bt.transdate >= date_trunc('month', CURRENT_DATE)), 0) as expenses_this_month,
            MIN(bt.transdate) as first_transaction,
            MAX(bt.transdate) as last_transaction
        FROM bank_accounts ba
        LEFT JOIN bank_transactions bt ON bt.local_bank_account_id = ba.id
        WHERE ba.id = :bank_account_id
        GROUP BY ba.id, ba.name, ba.iban, ba.bank, ba.reconciliation_starting_balance
    SQL, ['bank_account_id' => $bankAccountId]);

    if (!$result) {
        resultInfo(false, 'NOT_FOUND', 'Bankkonto nicht gefunden');
        return;
    }

    resultInfo(true, '', ['stats' => $result]);
}
