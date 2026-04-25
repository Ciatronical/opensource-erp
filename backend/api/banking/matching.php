<?php
// backend/api/banking/matching.php

/**
 * Offene Rechnungen laden fuer Zuordnung (AR + AP)
 *
 * @param string $data['type']   ar|ap|all (default: all)
 * @param string $data['search'] Suchbegriff (optional, sucht in Rechnungsnr + Kundenname)
 * @testdata {"type": "all"}
 */
function getOpenInvoicesForMatching($data) {
    $db = DbhCompany::begin();

    $type = $data['type'] ?? 'all';
    $search = trim($data['search'] ?? '');

    $results = [];

    // Offene Ausgangsrechnungen (Zahlungseingaenge)
    if ($type === 'all' || $type === 'ar') {
        $arParams = [];
        $arSearch = '';
        if (!empty($search)) {
            $arSearch = "AND (ar.invnumber ILIKE :search OR c.name ILIKE :search)";
            $arParams['search'] = '%' . $search . '%';
        }

        $arResult = $db->getAll(<<<SQL
            SELECT json_agg(row_to_json(t)) as invoices
            FROM (
                SELECT
                    ar.id,
                    'ar' as type,
                    ar.invnumber,
                    ar.transdate,
                    ar.duedate,
                    ar.amount,
                    ar.paid,
                    (ar.amount - ar.paid) as open_amount,
                    c.name as customer_name,
                    c.iban as customer_iban,
                    c.id as customer_id
                FROM ar
                JOIN customer c ON c.id = ar.customer_id
                WHERE ar.amount > ar.paid
                  AND ar.storno IS NOT TRUE
                  {$arSearch}
                ORDER BY ar.duedate ASC
                LIMIT 100
            ) t
        SQL, $arParams);

        $arInvoices = json_decode($arResult['invoices'] ?? '[]', true) ?: [];
        $results = array_merge($results, $arInvoices);
    }

    // Offene Eingangsrechnungen (Zahlungsausgaenge)
    if ($type === 'all' || $type === 'ap') {
        $apParams = [];
        $apSearch = '';
        if (!empty($search)) {
            $apSearch = "AND (ap.invnumber ILIKE :search OR v.name ILIKE :search)";
            $apParams['search'] = '%' . $search . '%';
        }

        $apResult = $db->getAll(<<<SQL
            SELECT json_agg(row_to_json(t)) as invoices
            FROM (
                SELECT
                    ap.id,
                    'ap' as type,
                    ap.invnumber,
                    ap.transdate,
                    ap.duedate,
                    ap.amount,
                    ap.paid,
                    (ap.amount - ap.paid) as open_amount,
                    v.name as vendor_name,
                    v.iban as vendor_iban,
                    v.id as vendor_id
                FROM ap
                JOIN vendor v ON v.id = ap.vendor_id
                WHERE ap.amount > ap.paid
                  AND ap.storno IS NOT TRUE
                  {$apSearch}
                ORDER BY ap.duedate ASC
                LIMIT 100
            ) t
        SQL, $apParams);

        $apInvoices = json_decode($apResult['invoices'] ?? '[]', true) ?: [];
        $results = array_merge($results, $apInvoices);
    }

    resultInfo(true, '', ['invoices' => $results]);
}

/**
 * Automatisches Matching ausfuehren
 *
 * @param int $data['bank_account_id'] Bankkonto-ID
 * @testdata {"bank_account_id": 1}
 */
function runAutoMatch($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    if ($bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt');
        return;
    }

    $result = $db->getOne(<<<SQL
        SELECT json_agg(row_to_json(t)) as matches
        FROM (
            SELECT * FROM bank_auto_match(:bank_account_id)
        ) t
    SQL, ['bank_account_id' => $bankAccountId]);

    $matches = json_decode($result['matches'] ?? '[]', true) ?: [];

    resultInfo(true, '', [
        'matches' => $matches,
        'count' => count($matches)
    ]);
}

/**
 * Bankumsatz einer Rechnung zuordnen (manuell oder aus Auto-Match)
 *
 * @param int    $data['bank_transaction_id'] Bankumsatz-ID
 * @param string $data['target_type']         ar|ap
 * @param int    $data['target_id']           AR- oder AP-ID
 * @testdata {"bank_transaction_id": 1, "target_type": "ar", "target_id": 100}
 */
function matchTransaction($data) {
    $db = DbhCompany::begin();

    $btId = intval($data['bank_transaction_id'] ?? 0);
    $targetType = $data['target_type'] ?? '';
    $targetId = intval($data['target_id'] ?? 0);

    if ($btId <= 0 || $targetId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID und Ziel-ID sind Pflicht');
        return;
    }

    if (!in_array($targetType, ['ar', 'ap'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Zieltyp muss ar oder ap sein');
        return;
    }

    // Bankumsatz laden
    $bt = $db->getOne(
        "SELECT id, amount, match_status FROM bank_transactions WHERE id = :id",
        ['id' => $btId]
    );

    if (!$bt) {
        resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden');
        return;
    }

    if ($bt['match_status'] === 'booked') {
        resultInfo(false, 'ALREADY_BOOKED', 'Umsatz ist bereits gebucht');
        return;
    }

    // Ziel-Rechnung laden und pruefen
    if ($targetType === 'ar') {
        $invoice = $db->getOne(
            "SELECT id, amount, paid, customer_id FROM ar WHERE id = :id",
            ['id' => $targetId]
        );
    } else {
        $invoice = $db->getOne(
            "SELECT id, amount, paid, vendor_id FROM ap WHERE id = :id",
            ['id' => $targetId]
        );
    }

    if (!$invoice) {
        resultInfo(false, 'NOT_FOUND', 'Rechnung nicht gefunden');
        return;
    }

    // Status auf matched setzen
    $db->execute(
        "UPDATE bank_transactions SET match_status = 'matched' WHERE id = :id",
        ['id' => $btId]
    );

    resultInfo(true, 'Zugeordnet', [
        'bank_transaction_id' => $btId,
        'target_type' => $targetType,
        'target_id' => $targetId
    ]);
}

/**
 * Zugeordnete Umsaetze buchen (in acc_trans verbuchen)
 *
 * @param array $data['transaction_ids'] Array von Bankumsatz-IDs
 * @param int   $data['bank_account_id'] Bankkonto-ID
 * @testdata {"transaction_ids": [1, 2], "bank_account_id": 1}
 */
function bookMatchedTransactions($data) {
    $db = DbhCompany::begin();

    $transactionIds = $data['transaction_ids'] ?? [];
    $bankAccountId = intval($data['bank_account_id'] ?? 0);

    if (empty($transactionIds) || $bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-IDs und Bankkonto-ID sind Pflicht');
        return;
    }

    // Bankkonto-Chart laden (Gegenkonto)
    $bankAccount = $db->getOne(
        "SELECT chart_id FROM bank_accounts WHERE id = :id",
        ['id' => $bankAccountId]
    );

    if (!$bankAccount) {
        resultInfo(false, 'NOT_FOUND', 'Bankkonto nicht gefunden');
        return;
    }

    $bookedCount = 0;
    $errors = [];

    foreach ($transactionIds as $btId) {
        $btId = intval($btId);

        $bt = $db->getOne(
            "SELECT id, amount, match_status, transdate FROM bank_transactions WHERE id = :id",
            ['id' => $btId]
        );

        if (!$bt || $bt['match_status'] !== 'matched') {
            $errors[] = "Umsatz {$btId}: nicht zugeordnet oder nicht gefunden";
            continue;
        }

        // Buchung in acc_trans erstellen
        $accTransResult = $db->getOne(<<<SQL
            INSERT INTO acc_trans (
                trans_id, chart_id, amount, transdate, gldate, source, memo
            )
            VALUES (
                0, :chart_id, :amount, :transdate, CURRENT_DATE,
                'BANK', 'Banking-Modul'
            )
            RETURNING acc_trans_id
        SQL, [
            'chart_id' => $bankAccount['chart_id'],
            'amount' => $bt['amount'],
            'transdate' => $bt['transdate']
        ]);

        if ($accTransResult) {
            // Link in bank_transaction_acc_trans erstellen
            $db->execute(<<<SQL
                INSERT INTO bank_transaction_acc_trans (
                    bank_transaction_id, acc_trans_id
                ) VALUES (:bt_id, :acc_trans_id)
            SQL, [
                'bt_id' => $btId,
                'acc_trans_id' => $accTransResult['acc_trans_id']
            ]);

            // Status aktualisieren
            $db->execute(
                "UPDATE bank_transactions SET match_status = 'booked', cleared = true WHERE id = :id",
                ['id' => $btId]
            );

            $bookedCount++;
        }
    }

    resultInfo(true, "Gebucht", [
        'booked_count' => $bookedCount,
        'errors' => $errors
    ]);
}

/**
 * Zuordnung aufheben
 *
 * @param int $data['bank_transaction_id'] Bankumsatz-ID
 * @testdata {"bank_transaction_id": 1}
 */
function unmatchTransaction($data) {
    $db = DbhCompany::begin();

    $btId = intval($data['bank_transaction_id'] ?? 0);
    if ($btId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt');
        return;
    }

    $bt = $db->getOne(
        "SELECT id, match_status FROM bank_transactions WHERE id = :id",
        ['id' => $btId]
    );

    if (!$bt) {
        resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden');
        return;
    }

    if ($bt['match_status'] === 'booked') {
        resultInfo(false, 'ALREADY_BOOKED', 'Gebuchte Umsaetze koennen nicht zurueckgesetzt werden');
        return;
    }

    $db->execute(
        "UPDATE bank_transactions SET match_status = 'unmatched' WHERE id = :id",
        ['id' => $btId]
    );

    resultInfo(true, 'Zuordnung aufgehoben');
}

/**
 * Zuordnungsregeln laden
 *
 * @param int $data['bank_account_id'] Bankkonto-ID (optional, NULL = alle)
 * @testdata {}
 */
function getMatchingRules($data) {
    $db = DbhCompany::begin();

    $bankAccountId = $data['bank_account_id'] ?? null;
    $params = [];
    $where = '';

    if ($bankAccountId) {
        $where = "WHERE bmr.bank_account_id = :bank_account_id OR bmr.bank_account_id IS NULL";
        $params['bank_account_id'] = intval($bankAccountId);
    }

    $result = $db->getAll(<<<SQL
        SELECT json_agg(row_to_json(t) ORDER BY t.priority) as rules
        FROM (
            SELECT
                bmr.*,
                c.name as customer_name,
                v.name as vendor_name,
                ch.accno as chart_accno,
                ch.description as chart_description
            FROM bank_matching_rules bmr
            LEFT JOIN customer c ON c.id = bmr.action_customer_id
            LEFT JOIN vendor v ON v.id = bmr.action_vendor_id
            LEFT JOIN chart ch ON ch.id = bmr.action_chart_id
            {$where}
        ) t
    SQL, $params);

    $rules = json_decode($result['rules'] ?? '[]', true) ?: [];
    resultInfo(true, '', ['rules' => $rules]);
}

/**
 * Zuordnungsregel speichern
 *
 * @param object $data['rule'] Regel-Daten
 * @testdata {"rule": {"rule_name": "Test", "action_type": "assign_customer", "match_remote_iban": "DE89370400440532013000"}}
 */
function saveMatchingRule($data) {
    $db = DbhCompany::begin();

    $rule = $data['rule'] ?? [];

    if (empty($rule['rule_name']) || empty($rule['action_type'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Name und Aktionstyp sind Pflichtfelder');
        return;
    }

    if (!in_array($rule['action_type'], ['assign_customer', 'assign_vendor', 'assign_chart', 'ignore'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungueltiger Aktionstyp');
        return;
    }

    $params = [
        'bank_account_id' => $rule['bank_account_id'] ?? null,
        'rule_name' => $rule['rule_name'],
        'priority' => intval($rule['priority'] ?? 100),
        'match_remote_iban' => $rule['match_remote_iban'] ?? null,
        'match_remote_name' => $rule['match_remote_name'] ?? null,
        'match_purpose' => $rule['match_purpose'] ?? null,
        'match_amount_min' => $rule['match_amount_min'] ?? null,
        'match_amount_max' => $rule['match_amount_max'] ?? null,
        'match_booking_key' => $rule['match_booking_key'] ?? null,
        'action_type' => $rule['action_type'],
        'action_customer_id' => $rule['action_customer_id'] ?? null,
        'action_vendor_id' => $rule['action_vendor_id'] ?? null,
        'action_chart_id' => $rule['action_chart_id'] ?? null,
        'active' => $rule['active'] ?? true
    ];

    if (!empty($rule['id'])) {
        $params['id'] = intval($rule['id']);
        $db->getOne(<<<SQL
            UPDATE bank_matching_rules
            SET bank_account_id = :bank_account_id,
                rule_name = :rule_name,
                priority = :priority,
                match_remote_iban = :match_remote_iban,
                match_remote_name = :match_remote_name,
                match_purpose = :match_purpose,
                match_amount_min = :match_amount_min,
                match_amount_max = :match_amount_max,
                match_booking_key = :match_booking_key,
                action_type = :action_type,
                action_customer_id = :action_customer_id,
                action_vendor_id = :action_vendor_id,
                action_chart_id = :action_chart_id,
                active = :active
            WHERE id = :id
            RETURNING id
        SQL, $params);
    } else {
        $db->getOne(<<<SQL
            INSERT INTO bank_matching_rules (
                bank_account_id, rule_name, priority,
                match_remote_iban, match_remote_name, match_purpose,
                match_amount_min, match_amount_max, match_booking_key,
                action_type, action_customer_id, action_vendor_id, action_chart_id,
                active
            ) VALUES (
                :bank_account_id, :rule_name, :priority,
                :match_remote_iban, :match_remote_name, :match_purpose,
                :match_amount_min, :match_amount_max, :match_booking_key,
                :action_type, :action_customer_id, :action_vendor_id, :action_chart_id,
                :active
            )
            RETURNING id
        SQL, $params);
    }

    resultInfo(true, 'Gespeichert');
}

/**
 * Zuordnungsregel loeschen
 *
 * @param int $data['rule_id'] Regel-ID
 * @testdata {"rule_id": 1}
 */
function deleteMatchingRule($data) {
    $db = DbhCompany::begin();

    $ruleId = intval($data['rule_id'] ?? 0);
    if ($ruleId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Regel-ID fehlt');
        return;
    }

    $db->execute(
        "DELETE FROM bank_matching_rules WHERE id = :id",
        ['id' => $ruleId]
    );

    resultInfo(true, 'Geloescht');
}
