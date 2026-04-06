<?php
// backend/api/banking/transactions.php

/**
 * Bankumsaetze eines Kontos laden mit Zuordnungsstatus
 *
 * @param int    $data['bank_account_id'] Bankkonto-ID
 * @param string $data['from_date']       Von-Datum (optional)
 * @param string $data['to_date']         Bis-Datum (optional)
 * @param string $data['match_status']    Filter: unmatched|matched|booked|ignored|all (default: all)
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

    // Umsaetze laden
    $result = $db->getAll(<<<SQL
        SELECT json_agg(row_to_json(t)) as transactions
        FROM (
            SELECT
                bt.id,
                bt.transdate,
                bt.valutadate,
                bt.amount,
                bt.remote_name,
                bt.remote_iban,
                bt.remote_bic,
                bt.remote_bank_code,
                bt.remote_account_number,
                bt.purpose,
                bt.primanota,
                bt.booking_key,
                bt.end_to_end_id,
                bt.mandate_reference,
                bt.creditor_id,
                bt.match_status,
                bt.cleared,
                bt.transaction_code,
                bt.transaction_text,
                -- Zuordnungsinformationen
                (
                    SELECT json_agg(json_build_object(
                        'ar_id', bta.ar_id,
                        'ap_id', bta.ap_id,
                        'gl_id', bta.gl_id,
                        'invnumber', COALESCE(ar.invnumber, ap.invnumber),
                        'customer_name', c.name,
                        'vendor_name', v.name
                    ))
                    FROM bank_transaction_acc_trans bta
                    LEFT JOIN ar ON ar.id = bta.ar_id
                    LEFT JOIN ap ON ap.id = bta.ap_id
                    LEFT JOIN customer c ON c.id = ar.customer_id
                    LEFT JOIN vendor v ON v.id = ap.vendor_id
                    WHERE bta.bank_transaction_id = bt.id
                ) as assignments
            FROM bank_transactions bt
            WHERE bt.local_bank_account_id = :bank_account_id
                {$whereExtra}
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

    $count = $db->getOne(<<<SQL
        SELECT COUNT(*)::INTEGER as total
        FROM bank_transactions bt
        WHERE bt.local_bank_account_id = :bank_account_id
            {$countWhere}
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
                SELECT json_agg(json_build_object(
                    'ar_id', bta.ar_id,
                    'ap_id', bta.ap_id,
                    'gl_id', bta.gl_id,
                    'acc_trans_id', bta.acc_trans_id,
                    'invnumber', COALESCE(ar.invnumber, ap.invnumber),
                    'customer_name', c.name,
                    'vendor_name', v.name,
                    'amount', COALESCE(ar.amount, ap.amount)
                ))
                FROM bank_transaction_acc_trans bta
                LEFT JOIN ar ON ar.id = bta.ar_id
                LEFT JOIN ap ON ap.id = bta.ap_id
                LEFT JOIN customer c ON c.id = ar.customer_id
                LEFT JOIN vendor v ON v.id = ap.vendor_id
                WHERE bta.bank_transaction_id = bt.id
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
