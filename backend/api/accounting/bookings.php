<?php
// backend/api/accounting/bookings.php

/**
 * Alle Buchungen laden (mit Filtern)
 *
 * @param string $data['status']    Filter: pending, approved, booked, rejected, all (Standard: all)
 * @param string $data['type']      Filter: incoming, outgoing, manual, bank, all (Standard: all)
 * @param string $data['from_date'] Von-Datum (YYYY-MM-DD)
 * @param string $data['to_date']   Bis-Datum (YYYY-MM-DD)
 * @param int    $data['limit']     Anzahl (Standard: 100)
 * @param int    $data['offset']    Offset (Standard: 0)
 * @testdata {"status": "all", "type": "all", "limit": 100}
 */
function getAccountingBookings($data) {
    $db = DbhCompany::begin();

    $status   = $data['status'] ?? 'all';
    $type     = $data['type'] ?? 'all';
    $fromDate = $data['from_date'] ?? null;
    $toDate   = $data['to_date'] ?? null;
    $limit    = intval($data['limit'] ?? 100);
    $offset   = intval($data['offset'] ?? 0);

    $where = ['1=1'];
    $params = [':limit' => $limit, ':offset' => $offset];

    if ($status !== 'all') {
        $where[] = 'b.status = :status';
        $params[':status'] = $status;
    }
    if ($type !== 'all') {
        $where[] = 'b.type = :type';
        $params[':type'] = $type;
    }
    if ($fromDate) {
        $where[] = 'b.booking_date >= :from_date';
        $params[':from_date'] = $fromDate;
    }
    if ($toDate) {
        $where[] = 'b.booking_date <= :to_date';
        $params[':to_date'] = $toDate;
    }

    $whereClause = implode(' AND ', $where);

    $bookings = $db->getAll(<<<SQL
        SELECT b.id, b.booking_date, b.invoice_date, b.due_date,
               b.amount, b.net_amount, b.tax_amount, b.tax_rate, b.tax_key,
               b.debit_account, b.credit_account,
               b.invoice_number, b.description, b.reference,
               b.type, b.status, b.ai_generated, b.ai_confidence, b.ai_notes,
               b.cost_center,
               TO_CHAR(b.booking_date, 'DD.MM.YYYY') AS booking_date_fmt,
               TO_CHAR(b.invoice_date, 'DD.MM.YYYY') AS invoice_date_fmt,
               TO_CHAR(b.due_date, 'DD.MM.YYYY') AS due_date_fmt,
               TO_CHAR(b.approved_at, 'DD.MM.YYYY HH24:MI') AS approved_at_fmt,
               v.id AS vendor_id, v.name AS vendor_name,
               c.id AS customer_id, c.name AS customer_name,
               d.id AS document_id, d.original_name AS document_name,
               d.extraction_confidence,
               ch_d.description AS debit_account_name,
               ch_c.description AS credit_account_name,
               e.name AS approved_by_name
        FROM accounting_bookings b
        LEFT JOIN vendor v ON v.id = b.vendor_id
        LEFT JOIN customer c ON c.id = b.customer_id
        LEFT JOIN accounting_documents d ON d.id = b.document_id
        LEFT JOIN chart ch_d ON ch_d.accno = b.debit_account
        LEFT JOIN chart ch_c ON ch_c.accno = b.credit_account
        LEFT JOIN employee e ON e.id = b.approved_by
        WHERE {$whereClause}
        ORDER BY b.booking_date DESC, b.id DESC
        LIMIT :limit OFFSET :offset
    SQL, $params);

    // Gesamtanzahl fuer Pagination
    $countRow = $db->getOne(
        "SELECT COUNT(*) AS total FROM accounting_bookings b WHERE {$whereClause}",
        array_filter($params, fn($k) => !in_array($k, [':limit', ':offset']), ARRAY_FILTER_USE_KEY)
    );

    // Statistiken
    $stats = $db->getOne(<<<SQL
        SELECT
            COUNT(*) FILTER (WHERE status = 'pending') AS pending_count,
            COUNT(*) FILTER (WHERE status = 'approved') AS approved_count,
            COUNT(*) FILTER (WHERE status = 'booked') AS booked_count,
            COUNT(*) FILTER (WHERE status = 'rejected') AS rejected_count,
            COALESCE(SUM(amount) FILTER (WHERE type = 'incoming' AND status != 'rejected'), 0) AS total_incoming,
            COALESCE(SUM(amount) FILTER (WHERE type = 'outgoing' AND status != 'rejected'), 0) AS total_outgoing
        FROM accounting_bookings
    SQL, []);

    resultInfo(true, '', [
        'bookings' => $bookings ?: [],
        'total'    => intval($countRow['total'] ?? 0),
        'stats'    => $stats
    ]);
}

/**
 * Einzelne Buchung mit Details laden
 *
 * @param int $data['booking_id'] Buchungs-ID
 * @testdata {"booking_id": 1}
 */
function getAccountingBooking($data) {
    $db = DbhCompany::begin();
    $bookingId = intval($data['booking_id'] ?? 0);
    if (!$bookingId) throw new ApiError('VALIDATION_ERROR', 'booking_id erforderlich');

    $booking = $db->getOne(<<<SQL
        SELECT b.*,
               TO_CHAR(b.booking_date, 'DD.MM.YYYY') AS booking_date_fmt,
               TO_CHAR(b.invoice_date, 'DD.MM.YYYY') AS invoice_date_fmt,
               v.name AS vendor_name, v.iban AS vendor_iban,
               c.name AS customer_name,
               d.original_name AS document_name, d.extracted_data,
               ch_d.description AS debit_account_name,
               ch_c.description AS credit_account_name
        FROM accounting_bookings b
        LEFT JOIN vendor v ON v.id = b.vendor_id
        LEFT JOIN customer c ON c.id = b.customer_id
        LEFT JOIN accounting_documents d ON d.id = b.document_id
        LEFT JOIN chart ch_d ON ch_d.accno = b.debit_account
        LEFT JOIN chart ch_c ON ch_c.accno = b.credit_account
        WHERE b.id = :id
    SQL, [':id' => $bookingId]);

    if (!$booking) throw new ApiError('DATA_NOT_FOUND', 'Buchung nicht gefunden');

    // Positionen laden
    $lines = $db->getAll(
        "SELECT * FROM accounting_booking_lines WHERE booking_id = :id ORDER BY position",
        [':id' => $bookingId]
    );

    $booking['lines'] = $lines ?: [];
    if ($booking['extracted_data']) {
        $booking['extracted_data'] = json_decode($booking['extracted_data'], true);
    }

    resultInfo(true, '', ['booking' => $booking]);
}

/**
 * Buchung freigeben (approve) — der Mensch klickt OK
 *
 * @param int $data['booking_id'] Buchungs-ID
 * @testdata {"booking_id": 1}
 */
function approveBooking($data) {
    $db = DbhCompany::begin();
    $bookingId = intval($data['booking_id'] ?? 0);
    if (!$bookingId) throw new ApiError('VALIDATION_ERROR', 'booking_id erforderlich');

    $booking = $db->getOne(
        "SELECT id, status, ap_id, type FROM accounting_bookings WHERE id = :id",
        [':id' => $bookingId]
    );
    if (!$booking) throw new ApiError('DATA_NOT_FOUND', 'Buchung nicht gefunden');

    // Optionale Korrekturen aus der Freigabe (Lieferant/Aufwandskonto) übernehmen –
    // z. B. wenn der Lieferant unsicher war (Kandidaten-Picker).
    $vendorOverride = intval($data['vendor_id'] ?? 0);
    $debitOverride  = trim($data['debit_account'] ?? '');
    $sets = []; $params = [':id' => $bookingId];
    if ($vendorOverride > 0) { $sets[] = 'vendor_id = :vid';     $params[':vid'] = $vendorOverride; }
    if ($debitOverride !== '') { $sets[] = 'debit_account = :da'; $params[':da']  = $debitOverride; }
    if ($sets) {
        $db->execute("UPDATE accounting_bookings SET " . implode(', ', $sets) . ", mtime = NOW() WHERE id = :id", $params);
    }

    // Eingangsrechnung: echte ap ins Hauptbuch buchen (idempotent). Andere Typen nur freigeben.
    if (empty($booking['ap_id']) && $booking['type'] === 'incoming') {
        try {
            _iv_postBooking($db, $bookingId);
        } catch (ApiError $e) {
            resultInfo(false, $e->getMessage());
            return;
        }
    }

    $db->execute(
        "UPDATE accounting_bookings
         SET status = CASE WHEN status = 'pending' THEN 'approved' ELSE status END,
             approved_by = :eid, approved_at = NOW(), mtime = NOW()
         WHERE id = :id",
        [':eid' => intval($_SESSION['employee_id'] ?? 0), ':id' => $bookingId]
    );

    $ap = $db->getOne("SELECT ap_id FROM accounting_bookings WHERE id = :id", [':id' => $bookingId]);
    resultInfo(true, 'Buchung freigegeben und gebucht', ['booking_id' => $bookingId, 'ap_id' => $ap['ap_id'] ?? null]);
}

/**
 * Mehrere Buchungen auf einmal freigeben
 *
 * @param array $data['booking_ids'] Array von Buchungs-IDs
 * @testdata {"booking_ids": [1, 2, 3]}
 */
function approveBookingsBatch($data) {
    $db = DbhCompany::begin();
    $ids = $data['booking_ids'] ?? [];
    if (empty($ids)) throw new ApiError('VALIDATION_ERROR', 'booking_ids erforderlich');

    $count = 0; $skipped = [];
    foreach ($ids as $id) {
        $id = intval($id);
        $booking = $db->getOne(
            "SELECT id, status, ap_id, type FROM accounting_bookings WHERE id = :id AND status = 'pending'",
            [':id' => $id]
        );
        if (!$booking) continue;

        // Echte ap buchen (idempotent). Scheitert es (Lieferant/Konto unklar) → überspringen.
        if (empty($booking['ap_id']) && $booking['type'] === 'incoming') {
            try { _iv_postBooking($db, $id); }
            catch (ApiError $e) { $skipped[] = $id; continue; }
        }
        $db->execute(
            "UPDATE accounting_bookings
             SET status = CASE WHEN status = 'pending' THEN 'approved' ELSE status END,
                 approved_by = :eid, approved_at = NOW(), mtime = NOW()
             WHERE id = :id",
            [':eid' => intval($_SESSION['employee_id'] ?? 0), ':id' => $id]
        );
        $count++;
    }

    resultInfo(true, $count . ' Buchungen freigegeben und gebucht', ['approved_count' => $count, 'skipped' => $skipped]);
}

/**
 * Buchung ablehnen
 *
 * @param int    $data['booking_id'] Buchungs-ID
 * @param string $data['reason']     Ablehnungsgrund (optional)
 * @testdata {"booking_id": 1, "reason": "Falsches Konto"}
 */
function rejectBooking($data) {
    $db = DbhCompany::begin();
    $bookingId = intval($data['booking_id'] ?? 0);
    if (!$bookingId) throw new ApiError('VALIDATION_ERROR', 'booking_id erforderlich');

    $db->execute(
        "UPDATE accounting_bookings SET status = 'rejected', ai_notes = COALESCE(ai_notes, '') || E'\nAbgelehnt: ' || :reason, mtime = NOW() WHERE id = :id",
        [':reason' => $data['reason'] ?? 'Ohne Angabe', ':id' => $bookingId]
    );

    resultInfo(true, 'Buchung abgelehnt', ['booking_id' => $bookingId]);
}

/**
 * Buchung bearbeiten (vor der Freigabe)
 *
 * @param int    $data['booking_id']     Buchungs-ID
 * @param string $data['debit_account']  Sollkonto (optional)
 * @param string $data['credit_account'] Habenkonto (optional)
 * @param float  $data['amount']         Betrag (optional)
 * @param float  $data['tax_rate']       Steuersatz (optional)
 * @param int    $data['tax_key']        Steuerschluessel (optional)
 * @param string $data['description']    Buchungstext (optional)
 * @param string $data['booking_date']   Buchungsdatum YYYY-MM-DD (optional)
 * @param int    $data['vendor_id']      Lieferant (optional)
 * @param string $data['cost_center']    Kostenstelle (optional)
 * @testdata {"booking_id": 1, "debit_account": "4980", "description": "Reparatur"}
 */
function updateBooking($data) {
    $db = DbhCompany::begin();
    $bookingId = intval($data['booking_id'] ?? 0);
    if (!$bookingId) throw new ApiError('VALIDATION_ERROR', 'booking_id erforderlich');

    $booking = $db->getOne(
        "SELECT id, status FROM accounting_bookings WHERE id = :id",
        [':id' => $bookingId]
    );
    if (!$booking) throw new ApiError('DATA_NOT_FOUND', 'Buchung nicht gefunden');
    if ($booking['status'] === 'booked') {
        throw new ApiError('VALIDATION_ERROR', 'Gebuchte Buchungen koennen nicht mehr bearbeitet werden');
    }

    $fields = [];
    $params = [':id' => $bookingId];

    $allowedFields = [
        'debit_account', 'credit_account', 'amount', 'net_amount', 'tax_amount',
        'tax_rate', 'tax_key', 'description', 'booking_date', 'invoice_date',
        'due_date', 'invoice_number', 'vendor_id', 'customer_id', 'cost_center'
    ];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $data)) {
            $fields[] = "{$field} = :{$field}";
            $params[":{$field}"] = $data[$field];
        }
    }

    if (empty($fields)) {
        throw new ApiError('VALIDATION_ERROR', 'Keine Felder zum Aktualisieren');
    }

    $fields[] = "mtime = NOW()";
    $fieldStr = implode(', ', $fields);

    $db->execute(
        "UPDATE accounting_bookings SET {$fieldStr} WHERE id = :id",
        $params
    );

    // Kontenzuordnung als Regel speichern (fuer zukuenftige KI-Vorschlaege)
    if (isset($data['debit_account']) && isset($booking['vendor_id'])) {
        _updateAccountRule($db, $booking, $data);
    }

    resultInfo(true, 'Buchung aktualisiert', ['booking_id' => $bookingId]);
}

/**
 * Kontenzuordnungsregel aktualisieren (lernt aus manuellen Korrekturen)
 */
function _updateAccountRule($db, $booking, $data) {
    $vendorId = $data['vendor_id'] ?? $booking['vendor_id'] ?? null;
    if (!$vendorId) return;

    $db->execute(
        "INSERT INTO accounting_account_rules (vendor_id, debit_account, credit_account, tax_key, hit_count)
         VALUES (:vid, :debit, :credit, :tkey, 1)
         ON CONFLICT ON CONSTRAINT accounting_account_rules_pkey DO NOTHING",
        [
            ':vid'    => intval($vendorId),
            ':debit'  => $data['debit_account'] ?? '4980',
            ':credit' => $data['credit_account'] ?? '1600',
            ':tkey'   => intval($data['tax_key'] ?? 9)
        ]
    );
}

/**
 * Buchungsuebersicht / Dashboard-Daten
 *
 * @testdata {}
 */
function getAccountingDashboard($data) {
    $db = DbhCompany::begin();

    $stats = $db->getOne(<<<SQL
        SELECT
            COUNT(*) FILTER (WHERE status = 'pending') AS pending_count,
            COUNT(*) FILTER (WHERE status = 'approved') AS approved_count,
            COUNT(*) FILTER (WHERE status = 'booked') AS booked_count,
            COALESCE(SUM(amount) FILTER (WHERE type = 'incoming' AND status != 'rejected'
                AND booking_date >= DATE_TRUNC('month', CURRENT_DATE)), 0) AS incoming_this_month,
            COALESCE(SUM(amount) FILTER (WHERE type = 'outgoing' AND status != 'rejected'
                AND booking_date >= DATE_TRUNC('month', CURRENT_DATE)), 0) AS outgoing_this_month,
            COALESCE(SUM(tax_amount) FILTER (WHERE type = 'incoming' AND status != 'rejected'
                AND booking_date >= DATE_TRUNC('month', CURRENT_DATE)), 0) AS vst_this_month,
            COALESCE(SUM(tax_amount) FILTER (WHERE type = 'outgoing' AND status != 'rejected'
                AND booking_date >= DATE_TRUNC('month', CURRENT_DATE)), 0) AS ust_this_month
        FROM accounting_bookings
    SQL, []);

    // Letzte 10 Buchungen
    $recent = $db->getAll(<<<SQL
        SELECT b.id, b.booking_date, b.amount, b.type, b.status, b.description,
               b.invoice_number, b.ai_generated, b.debit_account, b.credit_account,
               TO_CHAR(b.booking_date, 'DD.MM.YYYY') AS booking_date_fmt,
               COALESCE(v.name, c.name) AS partner_name
        FROM accounting_bookings b
        LEFT JOIN vendor v ON v.id = b.vendor_id
        LEFT JOIN customer c ON c.id = b.customer_id
        ORDER BY b.itime DESC
        LIMIT 10
    SQL, []);

    // Offene Eingangsrechnungen (nicht zugeordnete Bankbewegungen)
    $unmatchedBank = $db->getOne(
        "SELECT COUNT(*) AS cnt FROM bank_transactions WHERE match_status = 'unmatched'",
        []
    );

    // Offene Forderungen (Debitoren) und Verbindlichkeiten (Kreditoren) aus den
    // kivitendo-Standardtabellen — liefert auch ohne KI-Buchungen sinnvolle Zahlen
    $openItems = $db->getOne(<<<SQL
        SELECT
            (SELECT COUNT(*) FROM ar WHERE amount <> COALESCE(paid, 0)) AS receivables_count,
            (SELECT COALESCE(SUM(amount - COALESCE(paid, 0)), 0) FROM ar WHERE amount <> COALESCE(paid, 0)) AS receivables_sum,
            (SELECT COUNT(*) FROM ap WHERE amount <> COALESCE(paid, 0)) AS payables_count,
            (SELECT COALESCE(SUM(amount - COALESCE(paid, 0)), 0) FROM ap WHERE amount <> COALESCE(paid, 0)) AS payables_sum
    SQL, []);

    resultInfo(true, '', [
        'stats'            => $stats,
        'recent_bookings'  => $recent ?: [],
        'unmatched_bank'   => intval($unmatchedBank['cnt'] ?? 0),
        'open_items'       => $openItems
    ]);
}

/**
 * Konten-Suche (Typeahead fuer Sollkonto/Habenkonto)
 *
 * @param string $data['query'] Suchbegriff (Kontonummer oder Beschreibung)
 * @param string $data['link']  Optional: Filter nach link-Typ (AP, AR, AP_amount, etc.)
 * @testdata {"query": "4980"}
 */
function searchAccounts($data) {
    $db = DbhCompany::begin();
    $query = trim($data['query'] ?? '');
    $link = $data['link'] ?? null;

    if (strlen($query) < 1) {
        resultInfo(true, '', ['accounts' => []]);
        return;
    }

    $where = "NOT c.invalid AND (c.accno ILIKE :q OR c.description ILIKE :qs)";
    $params = [':q' => $query . '%', ':qs' => '%' . $query . '%'];

    if ($link) {
        $where .= " AND c.link LIKE :link";
        $params[':link'] = '%' . $link . '%';
    }

    $accounts = $db->getAll(
        "SELECT c.accno, c.description, c.link, c.category
         FROM chart c WHERE {$where} ORDER BY c.accno LIMIT 20",
        $params
    );

    resultInfo(true, '', ['accounts' => $accounts ?: []]);
}

/**
 * Lieferanten suchen (für den Kandidaten-Picker in der Freigabe)
 *
 * @param string $data['query'] Suchbegriff (Name oder Lieferantennummer)
 * @testdata {"query": "baumarkt"}
 */
function searchVendors($data) {
    $db = DbhCompany::begin();
    $q = trim($data['query'] ?? '');
    if (mb_strlen($q) < 2) { resultInfo(true, '', ['vendors' => []]); return; }

    $vendors = $db->getAll(
        "SELECT id, name, vendornumber
         FROM vendor
         WHERE obsolete IS NOT TRUE AND (name ILIKE :q OR vendornumber ILIKE :q)
         ORDER BY name LIMIT 20",
        [':q' => '%' . $q . '%']
    );

    resultInfo(true, '', ['vendors' => $vendors ?: []]);
}
