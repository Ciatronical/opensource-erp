<?php
// backend/api/banking/transfers.php

/**
 * Ueberweisungsauftraege laden
 *
 * @param int    $data['bank_account_id'] Bankkonto-ID (optional, alle wenn leer)
 * @param string $data['status']          Filter: draft|submitted|executed|all (default: all)
 * @testdata {"status": "all"}
 */
function getTransferOrders($data) {
    $db = DbhCompany::begin();

    $bankAccountId = $data['bank_account_id'] ?? null;
    $status = $data['status'] ?? 'all';

    $params = [];
    $where = 'WHERE 1=1';

    if ($bankAccountId) {
        $where .= " AND bto.bank_account_id = :bank_account_id";
        $params['bank_account_id'] = intval($bankAccountId);
    }

    if ($status !== 'all' && in_array($status, ['draft', 'pending_tan', 'submitted', 'executed', 'rejected', 'cancelled'])) {
        $where .= " AND bto.status = :status";
        $params['status'] = $status;
    }

    $result = $db->getAll(<<<SQL
        SELECT json_agg(row_to_json(t)) as orders
        FROM (
            SELECT
                bto.id,
                bto.bank_account_id,
                ba.name as account_name,
                ba.iban as account_iban,
                bto.remote_iban,
                bto.remote_bic,
                bto.remote_name,
                bto.amount,
                bto.currency,
                bto.purpose,
                bto.execution_date,
                bto.status,
                bto.source_type,
                bto.source_id,
                bto.employee_id,
                e.name as employee_name,
                bto.error_message,
                bto.submitted_at,
                bto.itime,
                CASE bto.source_type
                    WHEN 'ap' THEN (SELECT ap.invnumber FROM ap WHERE ap.id = bto.source_id)
                    WHEN 'ar' THEN (SELECT ar.invnumber FROM ar WHERE ar.id = bto.source_id)
                    ELSE NULL
                END as source_invnumber
            FROM bank_transfer_orders bto
            JOIN bank_accounts ba ON ba.id = bto.bank_account_id
            LEFT JOIN employee e ON e.id = bto.employee_id
            {$where}
            ORDER BY bto.itime DESC
            LIMIT 200
        ) t
    SQL, $params);

    $orders = json_decode($result['orders'] ?? '[]', true) ?: [];
    resultInfo(true, '', ['orders' => $orders]);
}

/**
 * Ueberweisungsauftrag erstellen (Entwurf)
 *
 * @param int    $data['bank_account_id'] Von welchem Konto
 * @param string $data['remote_iban']     Empfaenger-IBAN
 * @param string $data['remote_bic']      Empfaenger-BIC (optional)
 * @param string $data['remote_name']     Empfaengername
 * @param float  $data['amount']          Betrag
 * @param string $data['purpose']         Verwendungszweck
 * @param string $data['execution_date']  Ausfuehrungsdatum (optional)
 * @param string $data['source_type']     ap|ar|manual (optional)
 * @param int    $data['source_id']       Quell-ID (optional)
 * @testdata {"bank_account_id": 1, "remote_iban": "DE89370400440532013000", "remote_name": "Max Mustermann", "amount": 100.00, "purpose": "Rechnung 2024-001"}
 */
function createTransferOrder($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    $remoteIban = strtoupper(str_replace(' ', '', trim($data['remote_iban'] ?? '')));
    $remoteName = trim($data['remote_name'] ?? '');
    $amount = floatval($data['amount'] ?? 0);
    $purpose = trim($data['purpose'] ?? '');

    // Validierung
    if ($bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt');
        return;
    }

    if (empty($remoteIban)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Empfaenger-IBAN fehlt');
        return;
    }

    // IBAN-Format pruefen (minimale Validierung)
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', $remoteIban)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungueltiges IBAN-Format');
        return;
    }

    if (empty($remoteName)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Empfaengername fehlt');
        return;
    }

    if ($amount <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Betrag muss groesser als 0 sein');
        return;
    }

    if (empty($purpose)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Verwendungszweck fehlt');
        return;
    }

    // Laengenvalidierung Verwendungszweck (SEPA max 140 Zeichen)
    if (mb_strlen($purpose) > 140) {
        resultInfo(false, 'VALIDATION_ERROR', 'Verwendungszweck darf max. 140 Zeichen lang sein');
        return;
    }

    $sourceType = $data['source_type'] ?? null;
    if ($sourceType && !in_array($sourceType, ['ap', 'ar', 'manual'])) {
        $sourceType = null;
    }

    $result = $db->getOne(<<<SQL
        INSERT INTO bank_transfer_orders (
            bank_account_id, remote_iban, remote_bic, remote_name,
            amount, purpose, execution_date, source_type, source_id,
            employee_id, status
        ) VALUES (
            :bank_account_id, :remote_iban, :remote_bic, :remote_name,
            :amount, :purpose, :execution_date, :source_type, :source_id,
            :employee_id, 'draft'
        )
        RETURNING id
    SQL, [
        'bank_account_id' => $bankAccountId,
        'remote_iban' => $remoteIban,
        'remote_bic' => $data['remote_bic'] ?? null,
        'remote_name' => $remoteName,
        'amount' => $amount,
        'purpose' => $purpose,
        'execution_date' => $data['execution_date'] ?? null,
        'source_type' => $sourceType,
        'source_id' => $data['source_id'] ?? null,
        'employee_id' => $data['employee_id'] ?? null
    ]);

    resultInfo(true, 'Erstellt', ['id' => $result['id']]);
}

/**
 * Ueberweisungsauftrag aktualisieren (nur Entwuerfe)
 *
 * @param int    $data['id']              Auftrags-ID
 * @param string $data['remote_iban']     Empfaenger-IBAN
 * @param string $data['remote_bic']      Empfaenger-BIC (optional)
 * @param string $data['remote_name']     Empfaengername
 * @param float  $data['amount']          Betrag
 * @param string $data['purpose']         Verwendungszweck
 * @param string $data['execution_date']  Ausfuehrungsdatum (optional)
 * @testdata {"id": 1, "remote_iban": "DE89370400440532013000", "remote_name": "Max Mustermann", "amount": 100.00, "purpose": "Rechnung 2024-001"}
 */
function updateTransferOrder($data) {
    $db = DbhCompany::begin();

    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Auftrags-ID fehlt');
        return;
    }

    // Pruefen ob noch Entwurf
    $existing = $db->getOne(
        "SELECT id, status FROM bank_transfer_orders WHERE id = :id",
        ['id' => $id]
    );

    if (!$existing) {
        resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden');
        return;
    }

    if ($existing['status'] !== 'draft') {
        resultInfo(false, 'NOT_EDITABLE', 'Nur Entwuerfe koennen bearbeitet werden');
        return;
    }

    $remoteIban = strtoupper(str_replace(' ', '', trim($data['remote_iban'] ?? '')));
    $amount = floatval($data['amount'] ?? 0);
    $purpose = trim($data['purpose'] ?? '');

    if (empty($remoteIban) || $amount <= 0 || empty($purpose) || empty($data['remote_name'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Pflichtfelder fehlen');
        return;
    }

    if (mb_strlen($purpose) > 140) {
        resultInfo(false, 'VALIDATION_ERROR', 'Verwendungszweck darf max. 140 Zeichen lang sein');
        return;
    }

    $db->getOne(<<<SQL
        UPDATE bank_transfer_orders
        SET remote_iban = :remote_iban,
            remote_bic = :remote_bic,
            remote_name = :remote_name,
            amount = :amount,
            purpose = :purpose,
            execution_date = :execution_date,
            mtime = now()
        WHERE id = :id
        RETURNING id
    SQL, [
        'id' => $id,
        'remote_iban' => $remoteIban,
        'remote_bic' => $data['remote_bic'] ?? null,
        'remote_name' => trim($data['remote_name']),
        'amount' => $amount,
        'purpose' => $purpose,
        'execution_date' => $data['execution_date'] ?? null
    ]);

    resultInfo(true, 'Aktualisiert');
}

/**
 * Ueberweisungsauftrag loeschen (nur Entwuerfe)
 *
 * @param int $data['id'] Auftrags-ID
 * @testdata {"id": 1}
 */
function deleteTransferOrder($data) {
    $db = DbhCompany::begin();

    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Auftrags-ID fehlt');
        return;
    }

    $existing = $db->getOne(
        "SELECT id, status FROM bank_transfer_orders WHERE id = :id",
        ['id' => $id]
    );

    if (!$existing) {
        resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden');
        return;
    }

    if ($existing['status'] !== 'draft') {
        resultInfo(false, 'NOT_DELETABLE', 'Nur Entwuerfe koennen geloescht werden');
        return;
    }

    $db->execute(
        "DELETE FROM bank_transfer_orders WHERE id = :id",
        ['id' => $id]
    );

    resultInfo(true, 'Geloescht');
}

/**
 * Ueberweisung aus Eingangsrechnung erstellen
 *
 * @param int $data['ap_id']             Eingangsrechnungs-ID
 * @param int $data['bank_account_id']   Bankkonto-ID
 * @testdata {"ap_id": 1, "bank_account_id": 1}
 */
function createTransferFromInvoice($data) {
    $db = DbhCompany::begin();

    $apId = intval($data['ap_id'] ?? 0);
    $bankAccountId = intval($data['bank_account_id'] ?? 0);

    if ($apId <= 0 || $bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Rechnungs-ID und Bankkonto-ID sind Pflicht');
        return;
    }

    // Rechnungsdaten laden
    $invoice = $db->getOne(<<<SQL
        SELECT
            ap.id,
            ap.invnumber,
            ap.amount,
            ap.paid,
            (ap.amount - ap.paid) as open_amount,
            v.name as vendor_name,
            v.iban as vendor_iban,
            v.bic as vendor_bic
        FROM ap
        JOIN vendor v ON v.id = ap.vendor_id
        WHERE ap.id = :ap_id
    SQL, ['ap_id' => $apId]);

    if (!$invoice) {
        resultInfo(false, 'NOT_FOUND', 'Rechnung nicht gefunden');
        return;
    }

    if (floatval($invoice['open_amount']) <= 0) {
        resultInfo(false, 'ALREADY_PAID', 'Rechnung ist bereits bezahlt');
        return;
    }

    if (empty($invoice['vendor_iban'])) {
        resultInfo(false, 'NO_IBAN', 'Lieferant hat keine IBAN hinterlegt');
        return;
    }

    // Ueberweisungsauftrag erstellen
    $result = $db->getOne(<<<SQL
        INSERT INTO bank_transfer_orders (
            bank_account_id, remote_iban, remote_bic, remote_name,
            amount, purpose, source_type, source_id, status
        ) VALUES (
            :bank_account_id, :remote_iban, :remote_bic, :remote_name,
            :amount, :purpose, 'ap', :source_id, 'draft'
        )
        RETURNING id
    SQL, [
        'bank_account_id' => $bankAccountId,
        'remote_iban' => $invoice['vendor_iban'],
        'remote_bic' => $invoice['vendor_bic'],
        'remote_name' => $invoice['vendor_name'],
        'amount' => $invoice['open_amount'],
        'purpose' => 'Rechnung ' . $invoice['invnumber'],
        'source_id' => $apId
    ]);

    resultInfo(true, 'Erstellt', ['id' => $result['id']]);
}
