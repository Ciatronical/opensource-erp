<?php
// opensource-erp/backend/api/oserp_config/bank_accounts.php

function saveBankAccount($data) {
    $db = DbhCompany::begin();
    $ba = $data['data'];

    // Validierung
    if (empty($ba['name'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Name ist erforderlich');
        return;
    }

    if (empty($ba['chart_id'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Fibu-Konto ist erforderlich');
        return;
    }

    // UPDATE existierendes Bankkonto
    if (!empty($ba['id'])) {
        $result = $db->getOne(<<<SQL
            UPDATE bank_accounts
            SET account_number = :account_number,
                bank_code = :bank_code,
                iban = :iban,
                bic = :bic,
                bank = :bank,
                name = :name,
                chart_id = :chart_id,
                reconciliation_starting_date = :reconciliation_starting_date,
                reconciliation_starting_balance = :reconciliation_starting_balance,
                use_with_bank_import = :use_with_bank_import,
                use_for_zugferd = :use_for_zugferd,
                use_for_qrbill = :use_for_qrbill,
                qr_iban = :qr_iban,
                bank_account_id = :bank_account_id
            WHERE id = :id
            RETURNING id
        SQL, [
            'id' => $ba['id'],
            'account_number' => $ba['account_number'] ?? null,
            'bank_code' => $ba['bank_code'] ?? null,
            'iban' => $ba['iban'] ?? null,
            'bic' => $ba['bic'] ?? null,
            'bank' => $ba['bank'] ?? null,
            'name' => $ba['name'],
            'chart_id' => $ba['chart_id'] ?? null,
            'reconciliation_starting_date' => $ba['reconciliation_starting_date'] ?? null,
            'reconciliation_starting_balance' => $ba['reconciliation_starting_balance'] ?? null,
            'use_with_bank_import' => $ba['use_with_bank_import'] ?? false,
            'use_for_zugferd' => $ba['use_for_zugferd'] ?? false,
            'use_for_qrbill' => $ba['use_for_qrbill'] ?? false,
            'qr_iban' => $ba['qr_iban'] ?? null,
            'bank_account_id' => $ba['bank_account_id'] ?? null
        ]);

        $id = $result['id'];
    } else {
        // INSERT neues Bankkonto
        // Ermittle höchsten sortkey
        $maxSortkey = $db->getOne("SELECT COALESCE(MAX(sortkey), -1) + 1 as next_sortkey FROM bank_accounts");

        $result = $db->getOne(<<<SQL
            INSERT INTO bank_accounts (
                account_number, bank_code, iban, bic, bank, name, chart_id,
                reconciliation_starting_date, reconciliation_starting_balance,
                use_with_bank_import, use_for_zugferd, use_for_qrbill,
                qr_iban, bank_account_id, sortkey
            )
            VALUES (
                :account_number, :bank_code, :iban, :bic, :bank, :name, :chart_id,
                :reconciliation_starting_date, :reconciliation_starting_balance,
                :use_with_bank_import, :use_for_zugferd, :use_for_qrbill,
                :qr_iban, :bank_account_id, :sortkey
            )
            RETURNING id
        SQL, [
            'account_number' => $ba['account_number'] ?? null,
            'bank_code' => $ba['bank_code'] ?? null,
            'iban' => $ba['iban'] ?? null,
            'bic' => $ba['bic'] ?? null,
            'bank' => $ba['bank'] ?? null,
            'name' => $ba['name'],
            'chart_id' => $ba['chart_id'] ?? null,
            'reconciliation_starting_date' => $ba['reconciliation_starting_date'] ?? null,
            'reconciliation_starting_balance' => $ba['reconciliation_starting_balance'] ?? null,
            'use_with_bank_import' => $ba['use_with_bank_import'] ?? false,
            'use_for_zugferd' => $ba['use_for_zugferd'] ?? false,
            'use_for_qrbill' => $ba['use_for_qrbill'] ?? false,
            'qr_iban' => $ba['qr_iban'] ?? null,
            'bank_account_id' => $ba['bank_account_id'] ?? null,
            'sortkey' => $maxSortkey['next_sortkey']
        ]);

        $id = $result['id'];
    }

    resultInfo(true, 'Gespeichert', ['id' => $id]);
}

function deleteBankAccount($data) {
    $db = DbhCompany::begin();

    // Prüfe ob verwendet (in bank_transactions, reconciliation_links)
    $used = $db->getOne(<<<SQL
        SELECT EXISTS(
            SELECT 1 FROM bank_transactions WHERE local_bank_account_id = :id
            UNION
            SELECT 1 FROM reconciliation_links WHERE bank_transaction_id IN (
                SELECT id FROM bank_transactions WHERE local_bank_account_id = :id
            )
            LIMIT 1
        ) as used
    SQL, ['id' => $data['id']]);

    if ($used['used'] === 't' || $used['used'] === true) {
        resultInfo(false, 'IN_USE', 'Wird verwendet');
        return;
    }

    // Lösche Bankkonto
    $db->execute("DELETE FROM bank_accounts WHERE id = :id", ['id' => $data['id']]);

    resultInfo(true, 'Gelöscht');
}

function reorderBankAccounts($data) {
    $db = DbhCompany::begin();

    $bankAccountIds = $data['ba_id'] ?? [];

    // Setze sortkey basierend auf Array-Position
    foreach ($bankAccountIds as $index => $id) {
        $db->execute(
            "UPDATE bank_accounts SET sortkey = :sortkey WHERE id = :id",
            ['sortkey' => $index, 'id' => $id]
        );
    }

    resultInfo(true, 'Reihenfolge gespeichert');
}

function getBankDataFromIban($data) {
    $db = DbhCompany::begin();

    $iban = strtoupper(str_replace(' ', '', $data['iban'] ?? ''));

    if (empty($iban)) {
        resultInfo(false, 'IBAN fehlt');
        return;
    }

    // Extrahiere BLZ aus IBAN (Position 5-12 für Deutschland)
    if (substr($iban, 0, 2) !== 'DE') {
        resultInfo(false, 'Nur deutsche IBANs werden unterstützt');
        return;
    }

    // Hole Bankdaten aus blz_de Tabelle
    $result = $db->getOne(<<<SQL
        SELECT
            b.blz,
            b.name as bank,
            b.bic,
            b.plz,
            b.ort,
            b.kurzname
        FROM blz_de b
        WHERE b.blz = substring(regexp_replace(:iban, '\s+', '', 'g') from 5 for 8)::char(8)
    SQL, ['iban' => $iban]);

    if (!$result) {
        resultInfo(false, 'Keine Bankdaten für diese BLZ gefunden');
        return;
    }

    // Sauber mit erweiterter resultInfo
    resultInfo(true, 'OK', $result);
}
