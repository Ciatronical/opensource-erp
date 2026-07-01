<?php
// backend/api/banking/transfers.php

/**
 * Validiert das execution_date — Sofort-Ueberweisungen duerfen keinen
 * Termin haben, terminierte muessen >= heute und <= +1 Jahr liegen.
 *
 * @return true|string  true bei OK, sonst Fehlermeldung
 */
function validateExecutionDate($executionDate, $instant) {
    if ($executionDate === '' || $executionDate === null) return true;
    if ($instant) {
        return 'Sofort-Ueberweisung erlaubt kein Ausfuehrungsdatum';
    }
    $ts = strtotime($executionDate);
    if ($ts === false) return 'Ungueltiges Ausfuehrungsdatum';
    $today = strtotime('today');
    $maxDate = strtotime('+1 year', $today);
    if ($ts < $today) return 'Ausfuehrungsdatum darf nicht in der Vergangenheit liegen';
    if ($ts > $maxDate) return 'Ausfuehrungsdatum darf maximal 1 Jahr in der Zukunft liegen';
    return true;
}

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

    if ($status !== 'all' && in_array($status, ['draft', 'pending_tan', 'submitted', 'executed', 'rejected', 'cancelled', 'expired'])) {
        $where .= " AND bto.status = :status";
        $params['status'] = $status;
    }

    $result = $db->getOne(<<<SQL
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
                bto.instant,
                bto.batch_id,
                COALESCE(bto.engine, 'php') as engine,
                bto.status,
                bto.source_type,
                bto.source_id,
                bto.employee_id,
                e.name as employee_name,
                bto.error_message,
                bto.submitted_at,
                bto.executed_at,
                bto.expired_at,
                bto.matched_transaction_id,
                bto.itime,
                CASE bto.source_type
                    WHEN 'ap' THEN (SELECT ap.invnumber FROM ap WHERE ap.id = bto.source_id)
                    WHEN 'ar' THEN (SELECT ar.invnumber FROM ar WHERE ar.id = bto.source_id)
                    ELSE NULL
                END as source_invnumber
            FROM bank_transfer_orders bto
            LEFT JOIN bank_accounts ba ON ba.id = bto.bank_account_id
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
    if (!validateIbanMod97($remoteIban)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungueltige IBAN (Format oder Pruefsumme falsch)');
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

    $recipientType = $data['recipient_type'] ?? null;
    if ($recipientType && !in_array($recipientType, ['customer', 'vendor'])) {
        $recipientType = null;
    }
    $recipientId = $recipientType ? intval($data['recipient_id'] ?? 0) : null;
    if ($recipientId !== null && $recipientId <= 0) {
        $recipientId = null;
        $recipientType = null;
    }

    $instant = !empty($data['instant']);
    $remoteBic = trim($data['remote_bic'] ?? '');
    $executionDate = trim($data['execution_date'] ?? '');
    $engine = in_array($data['engine'] ?? 'vop_check', ['php', 'vop_optout', 'vop_check'], true) ? $data['engine'] : 'vop_check';

    $execValidation = validateExecutionDate($executionDate, $instant);
    if ($execValidation !== true) {
        resultInfo(false, 'VALIDATION_ERROR', $execValidation);
        return;
    }

    $result = $db->getOne(<<<SQL
        INSERT INTO bank_transfer_orders (
            bank_account_id, recipient_type, recipient_id,
            remote_iban, remote_bic, remote_name,
            amount, purpose, execution_date, instant, engine, source_type, source_id,
            employee_id, status
        ) VALUES (
            :bank_account_id, :recipient_type, :recipient_id,
            :remote_iban, :remote_bic, :remote_name,
            :amount, :purpose, :execution_date, :instant, :engine, :source_type, :source_id,
            :employee_id, 'draft'
        )
        RETURNING id
    SQL, [
        'bank_account_id' => $bankAccountId,
        'recipient_type' => $recipientType,
        'recipient_id' => $recipientId,
        'remote_iban' => $remoteIban,
        'remote_bic' => $remoteBic !== '' ? $remoteBic : null,
        'remote_name' => $remoteName,
        'amount' => $amount,
        'purpose' => $purpose,
        'execution_date' => $executionDate !== '' ? $executionDate : null,
        'instant' => $instant ? 't' : 'f',
        'engine' => $engine,
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

    // Entwürfe und fehlgeschlagene (rejected) Aufträge dürfen bearbeitet werden —
    // z. B. um die Ursache der Ablehnung (falsche IBAN) zu korrigieren und neu zu senden.
    if (!in_array($existing['status'], ['draft', 'rejected'], true)) {
        resultInfo(false, 'NOT_EDITABLE', 'Nur Entwürfe können bearbeitet werden');
        return;
    }

    $remoteIban = strtoupper(str_replace(' ', '', trim($data['remote_iban'] ?? '')));
    $amount = floatval($data['amount'] ?? 0);
    $purpose = trim($data['purpose'] ?? '');

    if (empty($remoteIban) || $amount <= 0 || empty($purpose) || empty($data['remote_name'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Pflichtfelder fehlen');
        return;
    }

    if (!validateIbanMod97($remoteIban)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungueltige IBAN (Format oder Pruefsumme falsch)');
        return;
    }

    if (mb_strlen($purpose) > 140) {
        resultInfo(false, 'VALIDATION_ERROR', 'Verwendungszweck darf max. 140 Zeichen lang sein');
        return;
    }

    $remoteBic = trim($data['remote_bic'] ?? '');
    $executionDate = trim($data['execution_date'] ?? '');
    $instant = !empty($data['instant']);
    $engine = in_array($data['engine'] ?? 'vop_check', ['php', 'vop_optout', 'vop_check'], true) ? $data['engine'] : 'vop_check';

    $execValidation = validateExecutionDate($executionDate, $instant);
    if ($execValidation !== true) {
        resultInfo(false, 'VALIDATION_ERROR', $execValidation);
        return;
    }

    $db->execute(<<<SQL
        UPDATE bank_transfer_orders
        SET remote_iban = :remote_iban,
            remote_bic = :remote_bic,
            remote_name = :remote_name,
            amount = :amount,
            purpose = :purpose,
            execution_date = :execution_date,
            instant = :instant,
            engine = :engine,
            mtime = now()
        WHERE id = :id
    SQL, [
        'id' => $id,
        'remote_iban' => $remoteIban,
        'remote_bic' => $remoteBic !== '' ? $remoteBic : null,
        'remote_name' => trim($data['remote_name']),
        'amount' => $amount,
        'purpose' => $purpose,
        'execution_date' => $executionDate !== '' ? $executionDate : null,
        'instant' => $instant ? 't' : 'f',
        'engine' => $engine
    ]);

    resultInfo(true, 'Aktualisiert');
}

/**
 * Ueberweisungsauftrag loeschen (alle ausser ausgefuehrte)
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

    if ($existing['status'] === 'executed') {
        resultInfo(false, 'NOT_DELETABLE', 'Ausgefuehrte Ueberweisungen koennen nicht geloescht werden');
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
            v.id as vendor_id,
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
            bank_account_id, recipient_type, recipient_id,
            remote_iban, remote_bic, remote_name,
            amount, purpose, source_type, source_id, status
        ) VALUES (
            :bank_account_id, 'vendor', :recipient_id,
            :remote_iban, :remote_bic, :remote_name,
            :amount, :purpose, 'ap', :source_id, 'draft'
        )
        RETURNING id
    SQL, [
        'bank_account_id' => $bankAccountId,
        'recipient_id' => $invoice['vendor_id'],
        'remote_iban' => $invoice['vendor_iban'],
        'remote_bic' => $invoice['vendor_bic'],
        'remote_name' => $invoice['vendor_name'],
        'amount' => $invoice['open_amount'],
        'purpose' => 'Rechnung ' . $invoice['invnumber'],
        'source_id' => $apId
    ]);

    resultInfo(true, 'Erstellt', ['id' => $result['id']]);
}

/**
 * Validiert eine IBAN nach Format, Laenderlaenge und MOD-97-Pruefsumme.
 * Spiegelt die Logik aus src/core/utils/iban.js — wenn das Frontend ueberbrueckt
 * wird (z.B. via API-Tester), schlaegt die Pruefung trotzdem.
 *
 * @param string $iban IBAN ohne Leerzeichen, gross
 * @return bool true wenn gueltig
 */
function validateIbanMod97($iban) {
    static $countryLengths = [
        'AT'=>20,'BE'=>16,'BG'=>22,'CH'=>21,'CY'=>28,'CZ'=>24,'DE'=>22,'DK'=>18,
        'EE'=>20,'ES'=>24,'FI'=>18,'FR'=>27,'GB'=>22,'GR'=>27,'HR'=>21,'HU'=>28,
        'IE'=>22,'IS'=>26,'IT'=>27,'LI'=>21,'LT'=>20,'LU'=>20,'LV'=>21,'MC'=>27,
        'MT'=>31,'NL'=>18,'NO'=>15,'PL'=>28,'PT'=>25,'RO'=>24,'SE'=>24,'SI'=>19,
        'SK'=>24,'SM'=>27,
    ];
    $iban = strtoupper(preg_replace('/\s+/', '', (string)$iban));
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban)) return false;
    $len = strlen($iban);
    if ($len < 15 || $len > 34) return false;
    $country = substr($iban, 0, 2);
    if (isset($countryLengths[$country]) && $len !== $countryLengths[$country]) return false;

    // MOD-97: ersten 4 ans Ende, Buchstaben durch Zahlen ersetzen (A=10..Z=35), Modulo 97
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    for ($i = 0; $i < strlen($rearranged); $i++) {
        $c = $rearranged[$i];
        $numeric .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c;
    }
    $remainder = '';
    for ($i = 0; $i < strlen($numeric); $i++) {
        $remainder .= $numeric[$i];
        if (strlen($remainder) >= 9) {
            $remainder = (string)((int)$remainder % 97);
        }
    }
    return ((int)$remainder % 97) === 1;
}

/**
 * Suche fuer das Empfaenger-Autocomplete im Ueberweisungs-Dialog.
 * Liefert Kunden UND Lieferanten gemischt — gefiltert nach Name oder Kunden-/
 * Lieferantennummer. Sortiert: exakte Name-Treffer zuerst, dann ILIKE.
 * Gibt IBAN/BIC/Bank/Bank-Code zurueck damit das Frontend Felder vorbefuellen
 * kann ohne zweiten Roundtrip.
 *
 * @param string $data['query']  Suchbegriff (Name oder Nummer), min 2 Zeichen
 * @param int    $data['limit']  optional, default 20, max 50
 * @testdata {"query": "Mus"}
 */
function searchTransferRecipient($data) {
    $db = DbhCompany::begin();

    $q = trim($data['query'] ?? '');
    if (mb_strlen($q) < 2) {
        resultInfo(true, '', ['results' => []]);
        return;
    }
    $limit = min(max(intval($data['limit'] ?? 20), 1), 50);

    $rows = $db->getAll(<<<SQL
        SELECT * FROM (
            SELECT 'customer' AS recipient_type, id, name, customernumber AS number,
                   iban, bic, bank, bank_code,
                   CASE WHEN lower(name) = lower(:exact) THEN 0 ELSE 1 END AS rank
            FROM customer
            WHERE obsolete IS NOT TRUE
              AND (name ILIKE :pattern OR customernumber ILIKE :pattern)
            UNION ALL
            SELECT 'vendor' AS recipient_type, id, name, vendornumber AS number,
                   iban, bic, bank, bank_code,
                   CASE WHEN lower(name) = lower(:exact) THEN 0 ELSE 1 END AS rank
            FROM vendor
            WHERE obsolete IS NOT TRUE
              AND (name ILIKE :pattern OR vendornumber ILIKE :pattern)
        ) combined
        ORDER BY rank ASC, name ASC
        LIMIT $limit
    SQL, ['pattern' => '%' . $q . '%', 'exact' => $q]);

    resultInfo(true, '', ['results' => $rows]);
}

/**
 * Legt einen neuen Kunden oder Lieferanten mit minimalen Pflichtfeldern an.
 * Aufgerufen vom Ueberweisungs-Dialog wenn der Empfaenger noch nicht existiert.
 * Nummer wird automatisch generiert (analog zu vendor_matching.php).
 *
 * @param string $data['type'] 'customer' oder 'vendor'
 * @param string $data['name'] Pflicht
 * @param string $data['street']  optional
 * @param string $data['zipcode'] optional
 * @param string $data['city']    optional
 * @param string $data['country'] optional, default 'DE'
 * @param string $data['email']   optional
 * @param string $data['iban']    optional, wird MOD-97-validiert
 * @param string $data['bic']     optional
 * @testdata {"type": "vendor", "name": "Neuer Lieferant", "iban": "DE89370400440532013000"}
 */
function createTransferRecipient($data) {
    $db = DbhCompany::begin();

    $type = $data['type'] ?? '';
    if (!in_array($type, ['customer', 'vendor'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Typ muss customer oder vendor sein');
        return;
    }
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Name ist Pflicht');
        return;
    }

    $iban = strtoupper(str_replace(' ', '', trim($data['iban'] ?? '')));
    if ($iban !== '' && !validateIbanMod97($iban)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungueltige IBAN (Format oder Pruefsumme falsch)');
        return;
    }

    $bic = trim($data['bic'] ?? '');

    // Naechste Nummer im jeweiligen Bereich (Default-Startwerte wie vendor_matching.php)
    $numberField = $type === 'customer' ? 'customernumber' : 'vendornumber';
    $startValue  = $type === 'customer' ? 10000 : 70000;
    $next = $db->getOne(<<<SQL
        SELECT COALESCE(MAX(CAST($numberField AS INTEGER)), :start) + 1 AS next_nr
        FROM $type WHERE $numberField ~ '^\d+$'
    SQL, ['start' => $startValue]);
    $newNumber = (string)$next['next_nr'];

    $row = $db->getOne(<<<SQL
        INSERT INTO $type (
            name, $numberField, street, zipcode, city, country,
            email, iban, bic, taxzone_id, currency_id
        ) VALUES (
            :name, :number, :street, :zipcode, :city, :country,
            :email, :iban, :bic, 1, 1
        )
        RETURNING id, name, $numberField AS number, iban, bic
    SQL, [
        'name'    => $name,
        'number'  => $newNumber,
        'street'  => trim($data['street'] ?? '') ?: null,
        'zipcode' => trim($data['zipcode'] ?? '') ?: null,
        'city'    => trim($data['city'] ?? '') ?: null,
        'country' => trim($data['country'] ?? '') ?: 'DE',
        'email'   => trim($data['email'] ?? '') ?: null,
        'iban'    => $iban !== '' ? $iban : null,
        'bic'     => $bic !== '' ? $bic : null,
    ]);

    resultInfo(true, 'Empfaenger angelegt', [
        'recipient_type' => $type,
        'id'             => intval($row['id']),
        'name'           => $row['name'],
        'number'         => $row['number'],
        'iban'           => $row['iban'],
        'bic'            => $row['bic'],
    ]);
}

/**
 * Speichert IBAN/BIC dauerhaft am Kunden bzw. Lieferanten.
 * Aufgerufen vom Ueberweisungs-Dialog nach Absenden, wenn die IBAN am Kontakt
 * fehlt oder vom Nutzer geaendert wurde.
 *
 * @param string $data['type'] 'customer' oder 'vendor'
 * @param int    $data['id']   Kontakt-ID
 * @param string $data['iban'] Pflicht, wird MOD-97-validiert
 * @param string $data['bic']  optional
 * @testdata {"type": "vendor", "id": 1, "iban": "DE89370400440532013000", "bic": "COBADEFFXXX"}
 */
function updateRecipientBankDetails($data) {
    $db = DbhCompany::begin();

    $type = $data['type'] ?? '';
    if (!in_array($type, ['customer', 'vendor'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Typ muss customer oder vendor sein');
        return;
    }
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt');
        return;
    }
    $iban = strtoupper(str_replace(' ', '', trim($data['iban'] ?? '')));
    if (!validateIbanMod97($iban)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungueltige IBAN (Format oder Pruefsumme falsch)');
        return;
    }
    $bic = trim($data['bic'] ?? '');

    $db->execute(<<<SQL
        UPDATE $type
        SET iban = :iban, bic = :bic, mtime = now()
        WHERE id = :id
    SQL, [
        'id'   => $id,
        'iban' => $iban,
        'bic'  => $bic !== '' ? $bic : null,
    ]);

    resultInfo(true, 'Bankverbindung gespeichert');
}

/**
 * Maximale Tage zwischen submitted_at und Buchung — nach dieser Zeit ohne
 * Match wird der Auftrag auf 'expired' gesetzt. Faustregel: 14 Tage deckt
 * SEPA-Standardlauf (1-2 BT) plus Pufferzeit fuer manuelle Pruefung der Bank.
 */
const TRANSFER_EXPIRY_DAYS = 14;

/**
 * Matcht 'submitted'-Auftraege gegen importierte Kontoauszuge des Kontos.
 * Eindeutige Matches (genau eine passende Buchung) werden auf 'executed'
 * gesetzt; mehrdeutige bleiben unveraendert und landen ggf. spaeter in der
 * Reconciliation-View.
 *
 * Aufgerufen aus importFintsStatements() nach jedem erfolgreichen Sync und
 * via reconcileTransferOrders() als API.
 *
 * @param Dbh   $db
 * @param int   $bankAccountId
 * @return int Anzahl der gematchten Auftraege
 */
function matchTransfersToTransactions($db, $bankAccountId) {
    $bankAccountId = intval($bankAccountId);
    if ($bankAccountId <= 0) return 0;

    // Offene Auftraege des Kontos. submitted_at wird zur Suchunter-Schranke
    // (kleine Toleranz von 1 Tag falls die Bank rueckdatiert bucht).
    $open = $db->getAll(<<<SQL
        SELECT id, amount, remote_iban, remote_name, submitted_at
        FROM bank_transfer_orders
        WHERE bank_account_id = :acct
          AND status = 'submitted'
    SQL, ['acct' => $bankAccountId]);

    $matched = 0;
    foreach ($open as $order) {
        // Pro Auftrag bis zu 2 Kandidaten — bei mehr als 1 ist das Match
        // mehrdeutig und wir fassen es nicht an.
        $candidates = $db->getAll(<<<SQL
            SELECT bt.id, bt.transdate
            FROM bank_transactions bt
            WHERE bt.local_bank_account_id = :acct
              AND ABS(bt.amount + :amount) < 0.005
              AND bt.transdate >= (:submitted_at)::date - 1
              AND bt.id NOT IN (
                  SELECT matched_transaction_id
                  FROM bank_transfer_orders
                  WHERE matched_transaction_id IS NOT NULL
              )
              AND (
                  UPPER(REPLACE(COALESCE(bt.remote_iban, bt.remote_account_number, ''), ' ', ''))
                      = UPPER(REPLACE(:iban, ' ', ''))
                  OR (:remote_name <> '' AND bt.remote_name ILIKE :name_pattern)
              )
            ORDER BY bt.transdate ASC, bt.id ASC
            LIMIT 2
        SQL, [
            'acct'         => $bankAccountId,
            'amount'       => $order['amount'],
            'submitted_at' => $order['submitted_at'] ?: date('Y-m-d'),
            'iban'         => $order['remote_iban'] ?? '',
            'remote_name'  => $order['remote_name'] ?? '',
            'name_pattern' => '%' . ($order['remote_name'] ?? '') . '%',
        ]);

        if (count($candidates) !== 1) continue;

        $db->execute(<<<SQL
            UPDATE bank_transfer_orders
            SET status = 'executed',
                executed_at = (:transdate)::timestamp,
                matched_transaction_id = :tx_id,
                mtime = now()
            WHERE id = :id
        SQL, [
            'transdate' => $candidates[0]['transdate'],
            'tx_id'     => $candidates[0]['id'],
            'id'        => $order['id'],
        ]);
        $matched++;
    }

    return $matched;
}

/**
 * Setzt 'submitted'-Auftraege auf 'expired', die seit > TRANSFER_EXPIRY_DAYS
 * unbestaetigt sind. Diese Auftraege wurden von der Bank zwar entgegengenommen,
 * sind aber nicht im Kontoauszug aufgetaucht — entweder abgelehnt ohne
 * Rueckmeldung oder das Match war zu unscharf.
 *
 * @param Dbh   $db
 * @param ?int  $bankAccountId Optional auf ein Konto begrenzen
 * @return int  Anzahl expirierter Auftraege
 */
function expireOldSubmittedTransfers($db, $bankAccountId = null) {
    $params = ['days' => TRANSFER_EXPIRY_DAYS];
    $accountFilter = '';
    if ($bankAccountId !== null) {
        $accountFilter = ' AND bank_account_id = :acct';
        $params['acct'] = intval($bankAccountId);
    }
    $rows = $db->getAll(<<<SQL
        UPDATE bank_transfer_orders
        SET status = 'expired',
            expired_at = now(),
            mtime = now()
        WHERE status = 'submitted'
          AND submitted_at IS NOT NULL
          AND submitted_at < now() - (:days || ' days')::interval
          $accountFilter
        RETURNING id
    SQL, $params);
    return count($rows);
}

/**
 * Manueller Trigger fuers Match+Expire — fuer den Fall dass der User in der
 * Liste auf "Status aktualisieren" klicken will, ohne neuen Sync zu starten.
 *
 * @param int $data['bank_account_id']  optional, sonst alle Konten
 * @testdata {"bank_account_id": 1}
 */
function reconcileTransferOrders($data) {
    $db = DbhCompany::begin();
    $bankAccountId = intval($data['bank_account_id'] ?? 0) ?: null;

    $matched = 0;
    if ($bankAccountId) {
        $matched = matchTransfersToTransactions($db, $bankAccountId);
    } else {
        // Alle Konten mit offenen Auftraegen
        $accounts = $db->getAll(
            "SELECT DISTINCT bank_account_id FROM bank_transfer_orders WHERE status = 'submitted'",
            []
        );
        foreach ($accounts as $a) {
            $matched += matchTransfersToTransactions($db, $a['bank_account_id']);
        }
    }
    $expired = expireOldSubmittedTransfers($db, $bankAccountId);

    resultInfo(true, '', ['matched_count' => $matched, 'expired_count' => $expired]);
}
