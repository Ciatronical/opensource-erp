<?php
// backend/api/banking/standing_orders.php
//
// Daueraufträge (Zahlungsvorlagen) und SEPA-Mandate (Einzugsermächtigungen).
// Daueraufträge erzeugen bei Ausführung einen normalen bank_transfer_order
// und werden dann via FinTS gesendet — keine direkte Bank-API nötig.

/**
 * Daueraufträge laden
 *
 * @param int $data['bank_account_id'] Optional: Filter nach Konto
 * @testdata {}
 */
function getStandingOrders($data) {
    $db  = DbhCompany::begin();
    $accountId = $data['bank_account_id'] ?? null;

    $where  = $accountId ? 'WHERE so.bank_account_id = :account_id' : '';
    $params = $accountId ? ['account_id' => intval($accountId)] : [];

    $result = $db->getOne(<<<SQL
        SELECT json_agg(row_to_json(t) ORDER BY t.next_execution_date, t.id) AS orders
        FROM (
            SELECT so.*,
                   ba.name AS account_name,
                   ba.iban AS account_iban
            FROM standing_orders so
            LEFT JOIN bank_accounts ba ON ba.id = so.bank_account_id
            {$where}
            ORDER BY so.next_execution_date NULLS LAST, so.id
        ) t
    SQL, $params);

    resultInfo(true, '', ['orders' => json_decode($result['orders'] ?? '[]', true) ?: []]);
}

/**
 * Dauerauftrag anlegen
 *
 * @param int    $data['bank_account_id']
 * @param string $data['remote_name']
 * @param string $data['remote_iban']
 * @param string $data['remote_bic']      optional
 * @param float  $data['amount']
 * @param string $data['purpose']
 * @param string $data['frequency']       monthly|quarterly|yearly|weekly
 * @param int    $data['execution_day']   1–31 (Monat) oder 1–7 (Woche)
 * @param string $data['start_date']      YYYY-MM-DD
 * @param string $data['end_date']        optional
 * @testdata {"bank_account_id":1,"remote_name":"Max","remote_iban":"DE89370400440532013000","amount":100,"purpose":"Miete","frequency":"monthly","execution_day":1,"start_date":"2026-05-01"}
 */
function createStandingOrder($data) {
    $db = DbhCompany::begin();

    $accountId = intval($data['bank_account_id'] ?? 0);
    $name      = trim($data['remote_name']  ?? '');
    $iban      = strtoupper(str_replace(' ', '', trim($data['remote_iban'] ?? '')));
    $amount    = floatval($data['amount'] ?? 0);
    $purpose   = trim($data['purpose']   ?? '');
    $freq      = $data['frequency']      ?? 'monthly';
    $execDay   = intval($data['execution_day'] ?? 1);
    $startDate = trim($data['start_date'] ?? '');

    if (!$accountId || !$name || !$iban || $amount <= 0 || !$purpose || !$startDate) {
        resultInfo(false, 'VALIDATION_ERROR', 'Pflichtfelder fehlen');
        return;
    }
    if (!in_array($freq, ['monthly','quarterly','yearly','weekly'], true)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungültiger Rhythmus');
        return;
    }

    $nextDate = calcNextExecution($startDate, $freq, $execDay);

    $row = $db->getOne(<<<SQL
        INSERT INTO standing_orders
            (bank_account_id, recipient_type, recipient_id, remote_name, remote_iban, remote_bic,
             amount, purpose, frequency, execution_day, start_date, end_date,
             status, next_execution_date, note, employee_id)
        VALUES
            (:account_id, :rtype, :rid, :name, :iban, :bic,
             :amount, :purpose, :freq, :exec_day, :start, :end,
             'active', :next_date, :note, :emp)
        RETURNING id
    SQL, [
        'account_id' => $accountId,
        'rtype'      => $data['recipient_type'] ?? null,
        'rid'        => $data['recipient_id']   ? intval($data['recipient_id']) : null,
        'name'       => $name,
        'iban'       => $iban,
        'bic'        => trim($data['remote_bic'] ?? '') ?: null,
        'amount'     => $amount,
        'purpose'    => $purpose,
        'freq'       => $freq,
        'exec_day'   => $execDay,
        'start'      => $startDate,
        'end'        => trim($data['end_date'] ?? '') ?: null,
        'next_date'  => $nextDate,
        'note'       => trim($data['note']     ?? '') ?: null,
        'emp'        => $data['employee_id']   ? intval($data['employee_id']) : null,
    ]);

    resultInfo(true, '', ['id' => $row['id']]);
}

/**
 * Dauerauftrag ändern
 *
 * @param int $data['id']
 * @testdata {"id":1,"remote_name":"Max","remote_iban":"DE89370400440532013000","amount":120,"purpose":"Miete April","frequency":"monthly","execution_day":1,"start_date":"2026-05-01"}
 */
function updateStandingOrder($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }

    $freq    = $data['frequency'] ?? 'monthly';
    $execDay = intval($data['execution_day'] ?? 1);
    $start   = trim($data['start_date'] ?? '');
    $next    = calcNextExecution($start, $freq, $execDay);

    $db->execute(<<<SQL
        UPDATE standing_orders SET
            remote_name         = :name,
            remote_iban         = :iban,
            remote_bic          = :bic,
            amount              = :amount,
            purpose             = :purpose,
            frequency           = :freq,
            execution_day       = :exec_day,
            start_date          = :start,
            end_date            = :end,
            next_execution_date = :next,
            note                = :note,
            mtime               = now()
        WHERE id = :id
    SQL, [
        'id'       => $id,
        'name'     => trim($data['remote_name']  ?? ''),
        'iban'     => strtoupper(str_replace(' ', '', $data['remote_iban'] ?? '')),
        'bic'      => trim($data['remote_bic']   ?? '') ?: null,
        'amount'   => floatval($data['amount']   ?? 0),
        'purpose'  => trim($data['purpose']      ?? ''),
        'freq'     => $freq,
        'exec_day' => $execDay,
        'start'    => $start,
        'end'      => trim($data['end_date']     ?? '') ?: null,
        'next'     => $next,
        'note'     => trim($data['note']         ?? '') ?: null,
    ]);
    resultInfo(true, '');
}

/**
 * Dauerauftrag pausieren / reaktivieren / beenden
 *
 * @param int    $data['id']
 * @param string $data['status']  active|paused|ended
 * @testdata {"id":1,"status":"paused"}
 */
function setStandingOrderStatus($data) {
    $db     = DbhCompany::begin();
    $id     = intval($data['id'] ?? 0);
    $status = $data['status'] ?? 'active';
    if (!$id || !in_array($status, ['active','paused','ended'], true)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungültige Parameter');
        return;
    }
    $db->execute(
        "UPDATE standing_orders SET status = :s, mtime = now() WHERE id = :id",
        ['id' => $id, 's' => $status]
    );
    resultInfo(true, '');
}

/**
 * Dauerauftrag löschen
 *
 * @param int $data['id']
 * @testdata {"id":1}
 */
function deleteStandingOrder($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $db->execute("DELETE FROM standing_orders WHERE id = :id", ['id' => $id]);
    resultInfo(true, '');
}

/**
 * Dauerauftrag jetzt ausführen — erzeugt einen bank_transfer_order (Entwurf).
 * Der Auftrag wird dann normal via FinTS gesendet.
 *
 * @param int $data['id']
 * @testdata {"id":1}
 */
function executeStandingOrder($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }

    $so = $db->getOne("SELECT * FROM standing_orders WHERE id = :id", ['id' => $id]);
    if (!$so) { resultInfo(false, 'NOT_FOUND', 'Dauerauftrag nicht gefunden'); return; }
    if ($so['status'] !== 'active') {
        resultInfo(false, 'NOT_ACTIVE', 'Dauerauftrag ist nicht aktiv'); return;
    }

    // Transfer-Entwurf anlegen
    $transfer = $db->getOne(<<<SQL
        INSERT INTO bank_transfer_orders
            (bank_account_id, recipient_type, recipient_id,
             remote_iban, remote_bic, remote_name,
             amount, purpose, engine, status,
             source_type, source_id, employee_id)
        VALUES
            (:account, :rtype, :rid,
             :iban, :bic, :name,
             :amount, :purpose, 'vop_check', 'draft',
             'standing_order', :so_id, :emp)
        RETURNING id
    SQL, [
        'account' => $so['bank_account_id'],
        'rtype'   => $so['recipient_type'],
        'rid'     => $so['recipient_id'],
        'iban'    => $so['remote_iban'],
        'bic'     => $so['remote_bic'],
        'name'    => $so['remote_name'],
        'amount'  => $so['amount'],
        'purpose' => $so['purpose'],
        'so_id'   => $id,
        'emp'     => $so['employee_id'],
    ]);

    // Nächstes Ausführungsdatum berechnen + letztes Ausführungsdatum setzen
    $today = date('Y-m-d');
    $next  = calcNextExecution($today, $so['frequency'], $so['execution_day']);
    // Wenn end_date erreicht: Status auf 'ended' setzen
    $newStatus = ($so['end_date'] && $next > $so['end_date']) ? 'ended' : 'active';

    $db->execute(<<<SQL
        UPDATE standing_orders
        SET last_executed_date  = :today,
            next_execution_date = :next,
            status              = :status,
            mtime               = now()
        WHERE id = :id
    SQL, ['today' => $today, 'next' => $next, 'status' => $newStatus, 'id' => $id]);

    resultInfo(true, '', ['transfer_id' => $transfer['id']]);
}

/**
 * Berechnet das nächste Ausführungsdatum ab einem Startdatum.
 */
function calcNextExecution(string $from, string $frequency, int $execDay): string {
    $dt = new \DateTime($from);
    switch ($frequency) {
        case 'weekly':
            $dt->modify('+1 week');
            break;
        case 'monthly':
            $dt->modify('+1 month');
            $maxDay = (int)$dt->format('t');
            $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), min($execDay, $maxDay));
            break;
        case 'quarterly':
            $dt->modify('+3 months');
            $maxDay = (int)$dt->format('t');
            $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), min($execDay, $maxDay));
            break;
        case 'yearly':
            $dt->modify('+1 year');
            $maxDay = (int)$dt->format('t');
            $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), min($execDay, $maxDay));
            break;
        default:
            $dt->modify('+1 month');
    }
    return $dt->format('Y-m-d');
}

// ─── SEPA-Mandate (Einzugsermächtigungen) ───────────────────

/**
 * SEPA-Mandate laden
 *
 * @param int $data['bank_account_id'] Optional
 * @testdata {}
 */
function getSepaMandates($data) {
    $db        = DbhCompany::begin();
    $accountId = $data['bank_account_id'] ?? null;
    $where     = $accountId ? 'WHERE sm.bank_account_id = :account_id' : '';
    $params    = $accountId ? ['account_id' => intval($accountId)] : [];

    $result = $db->getOne(<<<SQL
        SELECT json_agg(row_to_json(t) ORDER BY t.status DESC, t.creditor_name) AS mandates
        FROM (
            SELECT sm.*,
                   ba.name AS account_name
            FROM sepa_mandates sm
            LEFT JOIN bank_accounts ba ON ba.id = sm.bank_account_id
            {$where}
            ORDER BY sm.status DESC, sm.creditor_name
        ) t
    SQL, $params);

    resultInfo(true, '', ['mandates' => json_decode($result['mandates'] ?? '[]', true) ?: []]);
}

/**
 * SEPA-Mandat anlegen
 *
 * @param string $data['mandate_reference']  Mandatsreferenz
 * @param string $data['creditor_name']
 * @param string $data['issued_date']        YYYY-MM-DD
 * @testdata {"mandate_reference":"REF-001","creditor_name":"Strom AG","issued_date":"2026-01-01"}
 */
function createSepaMandate($data) {
    $db  = DbhCompany::begin();
    $ref = trim($data['mandate_reference'] ?? '');
    $cn  = trim($data['creditor_name']     ?? '');
    $date= trim($data['issued_date']       ?? '');
    if (!$ref || !$cn || !$date) {
        resultInfo(false, 'VALIDATION_ERROR', 'Mandatsreferenz, Gläubigername und Ausstellungsdatum sind Pflicht');
        return;
    }

    $row = $db->getOne(<<<SQL
        INSERT INTO sepa_mandates
            (bank_account_id, mandate_reference, creditor_name, creditor_id,
             creditor_iban, creditor_bic, max_amount, purpose,
             mandate_type, issued_date, first_collection, note)
        VALUES
            (:account, :ref, :cname, :cid,
             :ciban, :cbic, :maxamt, :purpose,
             :mtype, :issued, :first, :note)
        RETURNING id
    SQL, [
        'account' => $data['bank_account_id'] ? intval($data['bank_account_id']) : null,
        'ref'     => $ref,
        'cname'   => $cn,
        'cid'     => trim($data['creditor_id']   ?? '') ?: null,
        'ciban'   => trim($data['creditor_iban']  ?? '') ?: null,
        'cbic'    => trim($data['creditor_bic']   ?? '') ?: null,
        'maxamt'  => $data['max_amount'] ? floatval($data['max_amount']) : null,
        'purpose' => trim($data['purpose']        ?? '') ?: null,
        'mtype'   => in_array($data['mandate_type'] ?? '', ['RCUR','FRST','FNAL','OOFF']) ? $data['mandate_type'] : 'RCUR',
        'issued'  => $date,
        'first'   => trim($data['first_collection'] ?? '') ?: null,
        'note'    => trim($data['note']             ?? '') ?: null,
    ]);
    resultInfo(true, '', ['id' => $row['id']]);
}

/**
 * SEPA-Mandat aktualisieren
 *
 * @param int $data['id']
 * @testdata {"id":1,"mandate_reference":"REF-001","creditor_name":"Strom AG","issued_date":"2026-01-01"}
 */
function updateSepaMandate($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }

    $db->execute(<<<SQL
        UPDATE sepa_mandates SET
            mandate_reference = :ref,
            creditor_name     = :cname,
            creditor_id       = :cid,
            creditor_iban     = :ciban,
            creditor_bic      = :cbic,
            max_amount        = :maxamt,
            purpose           = :purpose,
            mandate_type      = :mtype,
            issued_date       = :issued,
            first_collection  = :first,
            note              = :note,
            mtime             = now()
        WHERE id = :id
    SQL, [
        'id'     => $id,
        'ref'    => trim($data['mandate_reference'] ?? ''),
        'cname'  => trim($data['creditor_name']     ?? ''),
        'cid'    => trim($data['creditor_id']        ?? '') ?: null,
        'ciban'  => trim($data['creditor_iban']      ?? '') ?: null,
        'cbic'   => trim($data['creditor_bic']       ?? '') ?: null,
        'maxamt' => $data['max_amount'] ? floatval($data['max_amount']) : null,
        'purpose'=> trim($data['purpose']            ?? '') ?: null,
        'mtype'  => in_array($data['mandate_type'] ?? '', ['RCUR','FRST','FNAL','OOFF']) ? $data['mandate_type'] : 'RCUR',
        'issued' => trim($data['issued_date']        ?? ''),
        'first'  => trim($data['first_collection']   ?? '') ?: null,
        'note'   => trim($data['note']               ?? '') ?: null,
    ]);
    resultInfo(true, '');
}

/**
 * SEPA-Mandat widerrufen
 *
 * @param int    $data['id']
 * @param string $data['revoked_date'] YYYY-MM-DD
 * @testdata {"id":1,"revoked_date":"2026-04-27"}
 */
function revokeSepaMandate($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $dt = trim($data['revoked_date'] ?? date('Y-m-d'));
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $db->execute(
        "UPDATE sepa_mandates SET status = 'revoked', revoked_date = :dt, mtime = now() WHERE id = :id",
        ['id' => $id, 'dt' => $dt]
    );
    resultInfo(true, '');
}

/**
 * SEPA-Mandat löschen
 *
 * @param int $data['id']
 * @testdata {"id":1}
 */
function deleteSepaMandate($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $db->execute("DELETE FROM sepa_mandates WHERE id = :id", ['id' => $id]);
    resultInfo(true, '');
}
