<?php
// backend/api/banking/banking.php

/**
 * Bankkonten-Uebersicht mit Salden und FinTS-Status
 *
 * @testdata {}
 */
function getBankingOverview($data) {
    $db = DbhCompany::begin();

    $result = $db->getOne(<<<SQL
        SELECT json_agg(row_to_json(t)) as accounts
        FROM (
            SELECT
                ba.id,
                ba.name,
                ba.iban,
                ba.bic,
                ba.bank,
                ba.bank_code,
                ba.account_number,
                ba.chart_id,
                baf.id as fints_id,
                baf.fints_url,
                baf.last_sync,
                baf.fints_username,
                EXISTS(SELECT 1 FROM defaults_oserp WHERE key = 'fints_pin_' || ba.id) AS has_saved_pin,
                (
                    SELECT COALESCE(
                        ba.reconciliation_starting_balance, 0
                    ) + COALESCE(
                        (SELECT SUM(bt.amount)
                         FROM bank_transactions bt
                         WHERE bt.local_bank_account_id = ba.id),
                        0
                    )
                ) as balance,
                (
                    SELECT COUNT(*)::INTEGER
                    FROM bank_transactions bt
                    WHERE bt.local_bank_account_id = ba.id
                      AND bt.match_status = 'unmatched'
                ) as unmatched_count,
                (
                    SELECT MAX(bt.transdate)
                    FROM bank_transactions bt
                    WHERE bt.local_bank_account_id = ba.id
                ) as last_transaction_date,
                (
                    SELECT COALESCE(SUM(bt.amount), 0)
                    FROM bank_transactions bt
                    WHERE bt.local_bank_account_id = ba.id
                      AND bt.amount > 0
                      AND bt.transdate >= DATE_TRUNC('month', CURRENT_DATE)
                      AND bt.transdate <  DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month'
                ) as income_this_month,
                (
                    SELECT COALESCE(ABS(SUM(bt.amount)), 0)
                    FROM bank_transactions bt
                    WHERE bt.local_bank_account_id = ba.id
                      AND bt.amount < 0
                      AND bt.transdate >= DATE_TRUNC('month', CURRENT_DATE)
                      AND bt.transdate <  DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month'
                ) as expenses_this_month
            FROM bank_accounts ba
            LEFT JOIN bank_account_fints baf ON baf.bank_account_id = ba.id
            WHERE ba.obsolete IS NOT TRUE
            ORDER BY ba.sortkey
        ) t
    SQL);

    $accounts = json_decode($result['accounts'] ?? '[]', true) ?: [];
    resultInfo(true, '', ['accounts' => $accounts]);
}

/**
 * FinTS-Server-URL fuer eine deutsche Bankleitzahl ermitteln.
 *
 * Liest aus backend/api/banking/fints-banks.json (aus hbci4j/blz.properties
 * generiert, siehe tools/refresh-fints-banks.sh).
 *
 * @param string $data['bank_code'] Bankleitzahl (Leerzeichen werden ignoriert)
 * @testdata {"bank_code": "17054040"}
 */
function getFintsUrlByBlz($data) {
    $blz = preg_replace('/\s+/', '', $data['bank_code'] ?? '');
    if ($blz === '') {
        resultInfo(true, '', ['url' => null, 'name' => null]);
        return;
    }

    $banksFile = __DIR__ . '/fints-banks.json';
    $banks = json_decode(@file_get_contents($banksFile), true) ?: [];

    if (isset($banks['exact'][$blz])) {
        $e = $banks['exact'][$blz];
        resultInfo(true, '', [
            'url'  => $e['url'],
            'name' => $e['name'],
            'bic'  => $e['bic'] ?? null,
        ]);
        return;
    }

    resultInfo(true, '', ['url' => null, 'name' => null]);
}

/**
 * FinTS-Zugangsdaten für ein Bankkonto speichern
 *
 * @param int    $data['bank_account_id']  Bankkonto-ID
 * @param string $data['fints_url']        FinTS-Server URL
 * @param string $data['fints_bank_code']  BLZ
 * @param string $data['fints_username']   Online-Banking Benutzername
 * @param string $data['fints_tan_mode']   TAN-Verfahren (optional)
 * @param string $data['sync_from_date']   Ab-Datum für Umsatzabruf (optional)
 * @testdata {"bank_account_id": 1, "fints_url": "https://banking.sparkasse.de/fints30", "fints_bank_code": "70050000", "fints_username": "testuser"}
 */
function saveFintsConfig($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    if ($bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt');
        return;
    }

    if (empty(getFintsProductId())) {
        resultInfo(false, 'FINTS_NOT_CONFIGURED', 'FinTS Produkt-ID ist nicht konfiguriert. Bitte in der Firmenkonfiguration unter SEPA/Bank eintragen (Registrierung: https://www.fints.org/de/hersteller/produktregistrierung).');
        return;
    }

    $fints_url = trim($data['fints_url'] ?? '');
    $fints_bank_code = trim($data['fints_bank_code'] ?? '');
    $fints_username = trim($data['fints_username'] ?? '');

    if (empty($fints_url) || empty($fints_bank_code) || empty($fints_username)) {
        resultInfo(false, 'VALIDATION_ERROR', 'FinTS-URL, BLZ und Benutzername sind Pflichtfelder');
        return;
    }

    // URL-Validierung: nur HTTPS erlaubt
    if (!preg_match('/^https:\/\//', $fints_url)) {
        resultInfo(false, 'VALIDATION_ERROR', 'FinTS-URL muss mit https:// beginnen');
        return;
    }

    // Prüfen ob bereits Konfiguration existiert
    $existing = $db->getOne(
        "SELECT id FROM bank_account_fints WHERE bank_account_id = :bank_account_id",
        ['bank_account_id' => $bankAccountId]
    );

    $params = [
        'bank_account_id' => $bankAccountId,
        'fints_url' => $fints_url,
        'fints_bank_code' => $fints_bank_code,
        'fints_username' => $fints_username,
        'fints_tan_mode' => $data['fints_tan_mode'] ?? null,
        'sync_from_date' => $data['sync_from_date'] ?? null
    ];

    if ($existing) {
        $params['id'] = $existing['id'];
        $db->getOne(<<<SQL
            UPDATE bank_account_fints
            SET fints_url = :fints_url,
                fints_bank_code = :fints_bank_code,
                fints_username = :fints_username,
                fints_tan_mode = :fints_tan_mode,
                sync_from_date = :sync_from_date,
                mtime = now()
            WHERE id = :id
            RETURNING id
        SQL, $params);
    } else {
        $db->getOne(<<<SQL
            INSERT INTO bank_account_fints (
                bank_account_id, fints_url, fints_bank_code,
                fints_username, fints_tan_mode, sync_from_date
            )
            VALUES (
                :bank_account_id, :fints_url, :fints_bank_code,
                :fints_username, :fints_tan_mode, :sync_from_date
            )
            RETURNING id
        SQL, $params);
    }

    resultInfo(true, 'Gespeichert');
}

/**
 * FinTS-Konfiguration eines Bankkontos laden
 *
 * @param int $data['bank_account_id'] Bankkonto-ID
 * @testdata {"bank_account_id": 1}
 */
function getFintsConfig($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    if ($bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt');
        return;
    }

    $result = $db->getOne(<<<SQL
        SELECT
            baf.id,
            baf.bank_account_id,
            baf.fints_url,
            baf.fints_bank_code,
            baf.fints_username,
            baf.fints_tan_mode,
            baf.last_sync,
            baf.sync_from_date,
            ba.name as account_name,
            ba.iban
        FROM bank_account_fints baf
        JOIN bank_accounts ba ON ba.id = baf.bank_account_id
        WHERE baf.bank_account_id = :bank_account_id
    SQL, ['bank_account_id' => $bankAccountId]);

    resultInfo(true, '', ['fints_config' => $result]);
}

/**
 * FinTS-Konfiguration loeschen
 *
 * @param int $data['bank_account_id'] Bankkonto-ID
 * @testdata {"bank_account_id": 1}
 */
function deleteFintsConfig($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    if ($bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt');
        return;
    }

    $db->execute(
        "DELETE FROM bank_account_fints WHERE bank_account_id = :bank_account_id",
        ['bank_account_id' => $bankAccountId]
    );

    resultInfo(true, 'Geloescht');
}
