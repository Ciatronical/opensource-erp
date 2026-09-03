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
        [':eid' => mitarbeiterId($data), ':id' => $bookingId]
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
            [':eid' => mitarbeiterId($data), ':id' => $id]
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
        "SELECT id, status, vendor_id, ap_id, ar_id, gl_id FROM accounting_bookings WHERE id = :id",
        [':id' => $bookingId]
    );
    if (!$booking) throw new ApiError('DATA_NOT_FOUND', 'Buchung nicht gefunden');
    if ($booking['status'] === 'booked') {
        throw new ApiError('VALIDATION_ERROR', 'Gebuchte Buchungen koennen nicht mehr bearbeitet werden');
    }

    // Ist der Beleg bereits im Hauptbuch (ap/ar/gl), duerfen die zahlungs- und
    // steuerrelevanten Felder NICHT mehr geaendert werden: die Aenderung wuerde
    // nur hier landen, nicht in ap/acc_trans. Der DATEV-Export liest aus dieser
    // Tabelle — der Steuerberater bekaeme sonst einen Betrag, den es im
    // Hauptbuch gar nicht gibt. Korrekturen laufen ueber eine Stornobuchung.
    $isPosted = !empty($booking['ap_id']) || !empty($booking['ar_id']) || !empty($booking['gl_id']);

    $fields = [];
    $params = [':id' => $bookingId];

    // Nach dem Verbuchen bleiben nur beschreibende Felder aenderbar.
    $financialFields = [
        'debit_account', 'credit_account', 'amount', 'net_amount', 'tax_amount',
        'tax_rate', 'tax_key', 'booking_date', 'invoice_date', 'due_date',
        'invoice_number', 'vendor_id', 'customer_id'
    ];
    $descriptiveFields = ['description', 'cost_center'];
    $allowedFields = $isPosted ? $descriptiveFields : array_merge($financialFields, $descriptiveFields);

    if ($isPosted) {
        $blocked = array_values(array_intersect($financialFields, array_keys($data)));
        if ($blocked) {
            throw new ApiError('ALREADY_POSTED',
                'Der Beleg ist bereits im Hauptbuch gebucht — ' . implode(', ', $blocked)
                . ' kann nicht mehr geaendert werden. Bitte stornieren und neu buchen.');
        }
    }

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

    // Ohne korrigiertes Aufwandskonto gibt es nichts zu lernen. Fruehere
    // Ersatzwerte ('4980'/'1600') stammen aus SKR03 und haetten in diesem
    // SKR04-Mandanten falsche Regeln erzeugt (4980 ist dort ein Ertragskonto).
    $debit = trim($data['debit_account'] ?? '');
    if ($debit === '') return;

    // Habenkonto immer aus dem Kontenrahmen (chart.link = 'AP')
    $apAcc  = $db->getOne("SELECT accno FROM chart WHERE link = 'AP' ORDER BY accno ASC LIMIT 1", []);
    $credit = $apAcc['accno'] ?? ($data['credit_account'] ?? '');
    if ($credit === '') return;

    $db->execute(
        "INSERT INTO accounting_account_rules (vendor_id, debit_account, credit_account, tax_key, hit_count)
         VALUES (:vid, :debit, :credit, :tkey, 1)
         ON CONFLICT ON CONSTRAINT accounting_account_rules_pkey DO NOTHING",
        [
            ':vid'    => intval($vendorId),
            ':debit'  => $debit,
            ':credit' => $credit,
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

    // Alles in EINER Abfrage. Die Kennzahlen kommen aus dem echten Hauptbuch
    // (acc_trans/ar/ap/gl), NICHT aus accounting_bookings — dort stehen nur die
    // KI-Buchungsvorschlaege. Wichtig: Viele Betriebe buchen Ausgaben nicht als
    // Kreditorenrechnung (ap), sondern als Dialogbuchung (gl) direkt auf ein
    // Aufwandskonto. Deshalb werden Einnahmen/Ausgaben aus den GuV-Konten
    // ermittelt (Kategorie I = Erlöse, E = Aufwand) und erfassen damit ALLE
    // Buchungsarten — nicht nur ar/ap.
    //
    // Vorzeichen in acc_trans (Soll negativ, Haben positiv): Erlöse (I) und
    // Umsatzsteuer (AR_tax) stehen positiv; Aufwand (E) und Vorsteuer (AP_tax)
    // negativ und werden negiert.
    //
    // Kopfzahl ist das laufende Jahr — am Monatsanfang waeren reine
    // „diesen Monat"-Zahlen sonst durchweg null. Monat + Vormonat kommen
    // zusaetzlich (Vormonat u. a. fuer die UStVA).
    $row = $db->getOne(<<<SQL
        WITH p AS (
            SELECT DATE_TRUNC('month', CURRENT_DATE)::date                        AS cur_from,
                   (DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month')::date AS cur_to,
                   (DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '1 month')::date AS prev_from,
                   DATE_TRUNC('month', CURRENT_DATE)::date                        AS prev_to,
                   DATE_TRUNC('year',  CURRENT_DATE)::date                        AS year_from
        ),
        pl AS (
            SELECT
                COALESCE( SUM(t.amount) FILTER (WHERE c.category = 'I' AND COALESCE(c.link,'') NOT LIKE '%tax%' AND t.transdate >= p.year_from), 0)                                       AS income_year,
                COALESCE( SUM(t.amount) FILTER (WHERE c.category = 'I' AND COALESCE(c.link,'') NOT LIKE '%tax%' AND t.transdate >= p.cur_from  AND t.transdate < p.cur_to), 0)            AS income_cur,
                COALESCE( SUM(t.amount) FILTER (WHERE c.category = 'I' AND COALESCE(c.link,'') NOT LIKE '%tax%' AND t.transdate >= p.prev_from AND t.transdate < p.prev_to), 0)           AS income_prev,
                COALESCE(-SUM(t.amount) FILTER (WHERE c.category = 'E' AND COALESCE(c.link,'') NOT LIKE '%tax%' AND t.transdate >= p.year_from), 0)                                       AS expense_year,
                COALESCE(-SUM(t.amount) FILTER (WHERE c.category = 'E' AND COALESCE(c.link,'') NOT LIKE '%tax%' AND t.transdate >= p.cur_from  AND t.transdate < p.cur_to), 0)            AS expense_cur,
                COALESCE(-SUM(t.amount) FILTER (WHERE c.category = 'E' AND COALESCE(c.link,'') NOT LIKE '%tax%' AND t.transdate >= p.prev_from AND t.transdate < p.prev_to), 0)           AS expense_prev,
                COALESCE(-SUM(t.amount) FILTER (WHERE c.link LIKE '%AP_tax%' AND t.transdate >= p.cur_from  AND t.transdate < p.cur_to), 0)      AS vst_cur,
                COALESCE(-SUM(t.amount) FILTER (WHERE c.link LIKE '%AP_tax%' AND t.transdate >= p.prev_from AND t.transdate < p.prev_to), 0)     AS vst_prev,
                COALESCE( SUM(t.amount) FILTER (WHERE c.link LIKE '%AR_tax%' AND t.transdate >= p.cur_from  AND t.transdate < p.cur_to), 0)      AS ust_cur,
                COALESCE( SUM(t.amount) FILTER (WHERE c.link LIKE '%AR_tax%' AND t.transdate >= p.prev_from AND t.transdate < p.prev_to), 0)     AS ust_prev
            FROM acc_trans t JOIN chart c ON c.id = t.chart_id CROSS JOIN p
            WHERE t.transdate >= LEAST(p.year_from, p.prev_from)
        )
        SELECT
            TO_CHAR(p.cur_from,  'MM/YYYY') AS current_period,
            TO_CHAR(p.prev_from, 'MM/YYYY') AS previous_period,
            TO_CHAR(p.year_from, 'YYYY')    AS current_year,

            -- KI-Buchungsvorschlaege (nur bei Belegscan genutzt)
            (SELECT COUNT(*) FROM accounting_bookings WHERE status = 'pending')  AS pending_count,
            (SELECT COUNT(*) FROM accounting_bookings WHERE status = 'approved') AS approved_count,
            (SELECT COUNT(*) FROM accounting_bookings WHERE status = 'booked')   AS booked_count,

            -- Anzahl echter Buchungen im laufenden Jahr (Ausgangs-, Eingangs-, Dialogbuchungen)
            ( (SELECT COUNT(*) FROM ar WHERE transdate >= p.year_from)
            + (SELECT COUNT(*) FROM ap WHERE transdate >= p.year_from)
            + (SELECT COUNT(*) FROM gl WHERE transdate >= p.year_from) ) AS bookings_year,

            pl.income_year,  pl.income_cur,  pl.income_prev,
            pl.expense_year, pl.expense_cur, pl.expense_prev,
            pl.vst_cur, pl.vst_prev, pl.ust_cur, pl.ust_prev,

            -- Offene Posten
            (SELECT COUNT(*) FROM ar WHERE (amount - COALESCE(paid, 0)) > 0.005)                                   AS receivables_count,
            (SELECT COALESCE(SUM(amount - COALESCE(paid, 0)), 0) FROM ar WHERE (amount - COALESCE(paid, 0)) > 0.005) AS receivables_sum,
            (SELECT COUNT(*) FROM ap WHERE (amount - COALESCE(paid, 0)) > 0.005)                                   AS payables_count,
            (SELECT COALESCE(SUM(amount - COALESCE(paid, 0)), 0) FROM ap WHERE (amount - COALESCE(paid, 0)) > 0.005) AS payables_sum,

            (SELECT COUNT(*) FROM bank_transactions WHERE match_status = 'unmatched') AS unmatched_bank,

            -- Letzte 10 echte Buchungen aus dem Hauptbuch-Journal
            (SELECT COALESCE(json_agg(x), '[]'::json) FROM (
                SELECT j.src, j.id, j.type, j.reference, j.partner, j.description, j.amount,
                       TO_CHAR(j.transdate, 'DD.MM.YYYY') AS transdate_fmt
                FROM (
                    SELECT 'ar' AS src, a.id, a.transdate, a.invnumber AS reference, c.name AS partner,
                           COALESCE(NULLIF(a.transaction_description,''), NULLIF(a.notes,''), 'Ausgangsrechnung') AS description,
                           a.amount, 'outgoing' AS type
                    FROM ar a LEFT JOIN customer c ON c.id = a.customer_id
                    UNION ALL
                    SELECT 'ap', a.id, a.transdate, a.invnumber, v.name,
                           COALESCE(NULLIF(a.transaction_description,''), NULLIF(a.notes,''), 'Eingangsrechnung'),
                           a.amount, 'incoming'
                    FROM ap a LEFT JOIN vendor v ON v.id = a.vendor_id
                    UNION ALL
                    SELECT 'gl', g.id, g.transdate, g.reference, NULL,
                           COALESCE(NULLIF(g.description,''), 'Dialogbuchung'),
                           COALESCE((SELECT SUM(t.amount) FROM acc_trans t WHERE t.trans_id = g.id AND t.amount > 0), 0), 'manual'
                    FROM gl g
                ) j
                ORDER BY j.transdate DESC, j.id DESC
                LIMIT 10
            ) x) AS recent_bookings
        FROM p CROSS JOIN pl
    SQL, []);

    resultInfo(true, '', [
        'stats' => [
            'current_period'      => $row['current_period'],
            'previous_period'     => $row['previous_period'],
            'current_year'        => $row['current_year'],
            'pending_count'       => $row['pending_count'],
            'approved_count'      => $row['approved_count'],
            'booked_count'        => $row['booked_count'],
            'bookings_year'       => intval($row['bookings_year']),
            // Einnahmen (Erlöse) und Ausgaben (Aufwand) — laufendes Jahr, Monat, Vormonat
            'income_year'         => $row['income_year'],
            'income_this_month'   => $row['income_cur'],
            'income_last_month'   => $row['income_prev'],
            'expense_year'        => $row['expense_year'],
            'expense_this_month'  => $row['expense_cur'],
            'expense_last_month'  => $row['expense_prev'],
            'vst_this_month'      => $row['vst_cur'],
            'vst_last_month'      => $row['vst_prev'],
            'ust_this_month'      => $row['ust_cur'],
            'ust_last_month'      => $row['ust_prev'],
            // Zahllast = Umsatzsteuer abzueglich Vorsteuer (negativ = Erstattung)
            'payable_this_month'  => $row['ust_cur']  - $row['vst_cur'],
            'payable_last_month'  => $row['ust_prev'] - $row['vst_prev'],
        ],
        'recent_bookings' => json_decode($row['recent_bookings'], true) ?: [],
        'unmatched_bank'  => intval($row['unmatched_bank']),
        'open_items'      => [
            'receivables_count' => $row['receivables_count'],
            'receivables_sum'   => $row['receivables_sum'],
            'payables_count'    => $row['payables_count'],
            'payables_sum'      => $row['payables_sum'],
        ],
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
        "SELECT c.id, c.accno, c.description, c.link, c.category
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

/**
 * Offene Forderungen (Debitoren) — nicht vollständig bezahlte Ausgangsrechnungen.
 *
 * Sortiert nach Fälligkeit (überfälligste zuerst) — so sieht man sofort, was
 * angemahnt werden muss. Liefert zusätzlich eine Summenzeile.
 *
 * @testdata {}
 */
function getOpenReceivables($data) {
    $db = DbhCompany::begin();

    $rows = $db->getAll(<<<SQL
        SELECT a.id, a.invnumber, a.customer_id AS partner_id, c.name AS partner,
               TO_CHAR(a.transdate, 'DD.MM.YYYY') AS transdate_fmt,
               EXTRACT(YEAR FROM a.transdate)::int AS year,
               TO_CHAR(a.duedate,   'DD.MM.YYYY') AS duedate_fmt,
               a.amount, COALESCE(a.paid, 0) AS paid,
               (a.amount - COALESCE(a.paid, 0)) AS open_amount,
               CASE WHEN a.duedate IS NOT NULL AND a.duedate < CURRENT_DATE
                    THEN (CURRENT_DATE - a.duedate) ELSE 0 END AS days_overdue
        FROM ar a LEFT JOIN customer c ON c.id = a.customer_id
        WHERE (a.amount - COALESCE(a.paid, 0)) > 0.005
        ORDER BY a.duedate ASC NULLS LAST, a.transdate ASC
    SQL, []);

    $sum = $db->getOne(<<<SQL
        SELECT COUNT(*) AS cnt,
               COALESCE(SUM(a.amount - COALESCE(a.paid, 0)), 0) AS total,
               COALESCE(SUM(a.amount - COALESCE(a.paid, 0)) FILTER (
                   WHERE a.duedate IS NOT NULL AND a.duedate < CURRENT_DATE), 0) AS overdue_total
        FROM ar a WHERE (a.amount - COALESCE(a.paid, 0)) > 0.005
    SQL, []);

    resultInfo(true, '', ['items' => $rows ?: [], 'summary' => $sum]);
}

/**
 * Offene Verbindlichkeiten (Kreditoren) — nicht vollständig bezahlte Eingangsrechnungen.
 *
 * @testdata {}
 */
function getOpenPayables($data) {
    $db = DbhCompany::begin();

    $rows = $db->getAll(<<<SQL
        SELECT a.id, a.invnumber, a.vendor_id AS partner_id, v.name AS partner,
               TO_CHAR(a.transdate, 'DD.MM.YYYY') AS transdate_fmt,
               EXTRACT(YEAR FROM a.transdate)::int AS year,
               TO_CHAR(a.duedate,   'DD.MM.YYYY') AS duedate_fmt,
               a.amount, COALESCE(a.paid, 0) AS paid,
               (a.amount - COALESCE(a.paid, 0)) AS open_amount,
               CASE WHEN a.duedate IS NOT NULL AND a.duedate < CURRENT_DATE
                    THEN (CURRENT_DATE - a.duedate) ELSE 0 END AS days_overdue
        FROM ap a LEFT JOIN vendor v ON v.id = a.vendor_id
        WHERE (a.amount - COALESCE(a.paid, 0)) > 0.005
        ORDER BY a.duedate ASC NULLS LAST, a.transdate ASC
    SQL, []);

    $sum = $db->getOne(<<<SQL
        SELECT COUNT(*) AS cnt,
               COALESCE(SUM(a.amount - COALESCE(a.paid, 0)), 0) AS total,
               COALESCE(SUM(a.amount - COALESCE(a.paid, 0)) FILTER (
                   WHERE a.duedate IS NOT NULL AND a.duedate < CURRENT_DATE), 0) AS overdue_total
        FROM ap a WHERE (a.amount - COALESCE(a.paid, 0)) > 0.005
    SQL, []);

    resultInfo(true, '', ['items' => $rows ?: [], 'summary' => $sum]);
}

/**
 * Monatsverlauf Einnahmen/Ausgaben für das Dashboard-Diagramm (letzte 12 Monate).
 *
 * Netto aus den GuV-Konten (Kategorie I/E) ohne Steuerkonten — gleiche Logik wie
 * das Dashboard. Leere Monate werden per generate_series aufgefüllt.
 *
 * @param int $data['months'] Anzahl Monate rückwärts (Default 12)
 * @testdata {"months": 12}
 */
function getAccountingChart($data) {
    $db = DbhCompany::begin();
    $months = max(1, min(36, intval($data['months'] ?? 12)));

    $rows = $db->getAll(<<<SQL
        WITH p AS (
            SELECT (DATE_TRUNC('month', CURRENT_DATE) - (:months - 1) * INTERVAL '1 month')::date AS start_m,
                   DATE_TRUNC('month', CURRENT_DATE)::date AS cur_m
        ),
        months AS (
            SELECT generate_series((SELECT start_m FROM p), (SELECT cur_m FROM p), INTERVAL '1 month')::date AS m
        ),
        pl AS (
            SELECT DATE_TRUNC('month', t.transdate)::date AS m,
                    SUM(t.amount) FILTER (WHERE c.category = 'I' AND COALESCE(c.link,'') NOT LIKE '%tax%') AS income,
                   -SUM(t.amount) FILTER (WHERE c.category = 'E' AND COALESCE(c.link,'') NOT LIKE '%tax%') AS expense
            FROM acc_trans t JOIN chart c ON c.id = t.chart_id
            WHERE t.transdate >= (SELECT start_m FROM p)
            GROUP BY 1
        )
        SELECT TO_CHAR(months.m, 'MM/YYYY')       AS label,
               COALESCE(ROUND(pl.income,  2), 0)  AS income,
               COALESCE(ROUND(pl.expense, 2), 0)  AS expense,
               (months.m = (SELECT cur_m FROM p))::int AS is_current
        FROM months LEFT JOIN pl ON pl.m = months.m
        ORDER BY months.m
    SQL, [':months' => $months]);

    resultInfo(true, '', ['series' => $rows ?: []]);
}

/**
 * Echtes Buchungsjournal aus dem kivitendo-Hauptbuch (ar/ap/gl).
 *
 * Zeigt die tatsächlich verbuchten Geschäftsvorfälle — eine Zeile je Transaktion:
 * Ausgangsrechnungen (ar → ausgehend), Eingangsrechnungen (ap → eingehend) und
 * Dialog-/Hauptbuchbuchungen (gl → manuell). Nicht zu verwechseln mit
 * `accounting_bookings` (KI-Vorerfassung, siehe getAccountingBookings).
 *
 * @param string $data['type']      all | incoming | outgoing | manual
 * @param string $data['from_date'] Von-Datum (YYYY-MM-DD), optional
 * @param string $data['to_date']   Bis-Datum (YYYY-MM-DD), optional
 * @param int    $data['limit']     max. Zeilen (Default 200)
 * @param int    $data['offset']    Offset für Pagination
 * @testdata {"type": "all", "limit": 50}
 */
function getLedgerJournal($data) {
    $db = DbhCompany::begin();

    $type   = $data['type'] ?? 'all';
    $limit  = intval($data['limit'] ?? 200);
    $offset = intval($data['offset'] ?? 0);

    // Filter nur einmal je benanntem Platzhalter verwenden (kein Parameter-Reuse).
    $where  = [];
    $filter = [];
    if (!empty($data['from_date'])) { $where[] = 'j.transdate >= :from_date'; $filter[':from_date'] = $data['from_date']; }
    if (!empty($data['to_date']))   { $where[] = 'j.transdate <= :to_date';   $filter[':to_date']   = $data['to_date']; }
    if ($type !== 'all')            { $where[] = 'j.type = :type';             $filter[':type']      = $type; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Gemeinsame UNION über die drei kivitendo-Buchungsquellen. Vorzeichen:
    // ar.amount/ap.amount sind Brutto (positiv); gl hat keinen Betrag → Summe
    // der Haben-Zeilen (amount > 0) aus acc_trans.
    $union = <<<SQL
        SELECT 'ar' AS src, a.id, a.transdate,
               a.invnumber AS reference,
               c.name AS partner,
               COALESCE(NULLIF(a.transaction_description, ''), NULLIF(a.notes, ''), 'Ausgangsrechnung') AS description,
               a.amount, a.netamount AS net_amount, (a.amount - a.netamount) AS tax_amount,
               a.paid, (a.amount - COALESCE(a.paid, 0)) AS open_amount, a.duedate,
               'outgoing' AS type
        FROM ar a LEFT JOIN customer c ON c.id = a.customer_id
        UNION ALL
        SELECT 'ap' AS src, a.id, a.transdate,
               a.invnumber AS reference,
               v.name AS partner,
               COALESCE(NULLIF(a.transaction_description, ''), NULLIF(a.notes, ''), 'Eingangsrechnung') AS description,
               a.amount, a.netamount AS net_amount, (a.amount - a.netamount) AS tax_amount,
               a.paid, (a.amount - COALESCE(a.paid, 0)) AS open_amount, a.duedate,
               'incoming' AS type
        FROM ap a LEFT JOIN vendor v ON v.id = a.vendor_id
        UNION ALL
        SELECT 'gl' AS src, g.id, g.transdate,
               g.reference AS reference,
               NULL AS partner,
               COALESCE(NULLIF(g.description, ''), 'Dialogbuchung') AS description,
               COALESCE((SELECT SUM(t.amount) FROM acc_trans t WHERE t.trans_id = g.id AND t.amount > 0), 0) AS amount,
               NULL::numeric AS net_amount, NULL::numeric AS tax_amount,
               NULL::numeric AS paid, NULL::numeric AS open_amount, NULL::date AS duedate,
               'manual' AS type
        FROM gl g
    SQL;

    $rows = $db->getAll(<<<SQL
        SELECT j.src, j.id, j.type, j.reference, j.partner, j.description,
               j.amount, j.net_amount, j.tax_amount, j.paid, j.open_amount,
               TO_CHAR(j.transdate, 'DD.MM.YYYY') AS transdate_fmt,
               TO_CHAR(j.duedate,   'DD.MM.YYYY') AS duedate_fmt,
               j.transdate
        FROM ({$union}) j
        {$whereClause}
        ORDER BY j.transdate DESC, j.id DESC
        LIMIT :limit OFFSET :offset
    SQL, array_merge($filter, [':limit' => $limit, ':offset' => $offset]));

    $stats = $db->getOne(<<<SQL
        SELECT COUNT(*) AS total,
               COUNT(*) FILTER (WHERE j.type = 'incoming') AS count_incoming,
               COUNT(*) FILTER (WHERE j.type = 'outgoing') AS count_outgoing,
               COUNT(*) FILTER (WHERE j.type = 'manual')   AS count_manual,
               COALESCE(SUM(j.amount) FILTER (WHERE j.type = 'incoming'), 0) AS sum_incoming,
               COALESCE(SUM(j.amount) FILTER (WHERE j.type = 'outgoing'), 0) AS sum_outgoing
        FROM ({$union}) j
        {$whereClause}
    SQL, $filter);

    resultInfo(true, '', [
        'journal' => $rows ?: [],
        'total'   => intval($stats['total'] ?? 0),
        'stats'   => $stats,
    ]);
}

/**
 * Einzelne Hauptbuch-Transaktion mit allen Soll-/Haben-Zeilen (acc_trans).
 *
 * @param int    $data['id']  trans_id der Buchung
 * @param string $data['src'] ar | ap | gl (bestimmt die Kopfdaten)
 * @testdata {"id": 7019, "src": "gl"}
 */
function getLedgerEntry($data) {
    $db  = DbhCompany::begin();
    $id  = intval($data['id'] ?? 0);
    $src = $data['src'] ?? 'gl';

    // Kopfdaten je nach Quelle
    if ($src === 'ar') {
        $head = $db->getOne(
            "SELECT a.invnumber AS reference, c.name AS partner,
                    COALESCE(NULLIF(a.transaction_description,''), NULLIF(a.notes,''), 'Ausgangsrechnung') AS description,
                    TO_CHAR(a.transdate,'DD.MM.YYYY') AS transdate_fmt, a.amount
             FROM ar a LEFT JOIN customer c ON c.id = a.customer_id WHERE a.id = :id",
            [':id' => $id]
        );
    } elseif ($src === 'ap') {
        $head = $db->getOne(
            "SELECT a.invnumber AS reference, v.name AS partner,
                    COALESCE(NULLIF(a.transaction_description,''), NULLIF(a.notes,''), 'Eingangsrechnung') AS description,
                    TO_CHAR(a.transdate,'DD.MM.YYYY') AS transdate_fmt, a.amount
             FROM ap a LEFT JOIN vendor v ON v.id = a.vendor_id WHERE a.id = :id",
            [':id' => $id]
        );
    } else {
        $head = $db->getOne(
            "SELECT g.reference, NULL AS partner,
                    COALESCE(NULLIF(g.description,''), 'Dialogbuchung') AS description,
                    TO_CHAR(g.transdate,'DD.MM.YYYY') AS transdate_fmt,
                    COALESCE((SELECT SUM(t.amount) FROM acc_trans t WHERE t.trans_id = g.id AND t.amount > 0), 0) AS amount
             FROM gl g WHERE g.id = :id",
            [':id' => $id]
        );
    }

    // Soll = negatives Vorzeichen, Haben = positives (kivitendo-Konvention).
    // Die eigentliche Steuerzeile erkennt man am Konto-Link (AR_tax/AP_tax) —
    // NICHT an t.tax_id, das in kivitendo auf JEDER Zeile einer besteuerten
    // Buchung gesetzt ist.
    $lines = $db->getAll(
        "SELECT t.acc_trans_id, c.accno, c.description AS account_name,
                CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END AS soll,
                CASE WHEN t.amount > 0 THEN  t.amount ELSE 0 END AS haben,
                t.taxkey, COALESCE(c.link,'') LIKE '%tax%' AS is_tax, t.source, t.memo
         FROM acc_trans t JOIN chart c ON c.id = t.chart_id
         WHERE t.trans_id = :id
         ORDER BY t.acc_trans_id",
        [':id' => $id]
    );

    // Gescannter Beleg zu dieser Buchung. Ohne diese Verknuepfung waere das
    // Bild nur ueber die KI-Vorschlagsliste erreichbar — im Hauptbuch-Journal,
    // also dort wo taeglich gearbeitet und bei einer Pruefung nachgesehen wird,
    // haette man nur Zahlen ohne Beleg.
    $document = null;
    if ($src === 'ap' || $src === 'ar') {
        $spalte = $src === 'ap' ? 'ap_id' : 'ar_id';
        $hatSpalte = $db->getOne(
            "SELECT 1 FROM information_schema.columns
             WHERE table_name = 'accounting_documents' AND column_name = :c LIMIT 1",
            [':c' => $spalte]
        );
        if ($hatSpalte) {
            $document = $db->getOne(
                "SELECT id, original_name, mime_type, file_size, file_hash,
                        TO_CHAR(itime, 'DD.MM.YYYY HH24:MI') AS uploaded_fmt
                 FROM accounting_documents
                 WHERE {$spalte} = :id
                 ORDER BY id DESC LIMIT 1",
                [':id' => $id]
            ) ?: null;
        }
    }

    resultInfo(true, '', ['head' => $head, 'lines' => $lines ?: [], 'document' => $document]);
}

/**
 * Kontoblatt / Sachkontoauszug: alle acc_trans-Zeilen EINES Kontos chronologisch
 * mit Eröffnungssaldo und laufendem Saldo. Vorzeichen kivitendo: Soll = amount<0,
 * Haben = amount>0. Saldo als laufende Summe Soll−Haben (= -amount), so wird ein
 * Aktiv-/Aufwandskonto positiv dargestellt.
 *
 * @param string $data['accno']     Kontonummer (Pflicht, alternativ chart_id)
 * @param int    $data['chart_id']  Konto-ID (alternativ zu accno)
 * @param string $data['from_date'] Von-Datum (optional, Default: Jahresanfang)
 * @param string $data['to_date']   Bis-Datum (optional, Default: heute)
 * @testdata {"accno": "5400"}
 */
function getAccountLedger($data) {
    $db = DbhCompany::begin();

    $accno    = trim($data['accno'] ?? '');
    $chartId  = intval($data['chart_id'] ?? 0);
    $from     = !empty($data['from_date']) ? $data['from_date'] : date('Y') . '-01-01';
    $to       = !empty($data['to_date'])   ? $data['to_date']   : date('Y-m-d');

    $chart = $chartId > 0
        ? $db->getOne("SELECT id, accno, description FROM chart WHERE id = :id", [':id' => $chartId])
        : $db->getOne("SELECT id, accno, description FROM chart WHERE accno = :a", [':a' => $accno]);
    if (!$chart) throw new ApiError('DATA_NOT_FOUND', 'Konto nicht gefunden');
    $cid = intval($chart['id']);

    // Eröffnungssaldo = Soll−Haben aller Buchungen VOR dem Von-Datum
    $opening = $db->getOne(
        "SELECT COALESCE(-SUM(amount), 0) AS saldo FROM acc_trans WHERE chart_id = :cid AND transdate < :from",
        [':cid' => $cid, ':from' => $from]
    );
    $openingSaldo = floatval($opening['saldo'] ?? 0);

    // Buchungszeilen im Zeitraum. trans_id ist über ar/ap/gl eindeutig
    // (gemeinsame Sequenz), daher LEFT JOIN auf alle drei gefahrlos.
    $rows = $db->getAll(
        "SELECT t.acc_trans_id, t.trans_id, t.transdate,
                TO_CHAR(t.transdate, 'DD.MM.YYYY') AS transdate_fmt,
                CASE WHEN ar.id IS NOT NULL THEN 'ar' WHEN ap.id IS NOT NULL THEN 'ap' ELSE 'gl' END AS src,
                COALESCE(ar.invnumber, ap.invnumber, gl.reference) AS reference,
                COALESCE(cu.name, ve.name) AS partner,
                COALESCE(NULLIF(t.memo,''), NULLIF(gl.description,''),
                         NULLIF(ar.transaction_description,''), NULLIF(ap.transaction_description,'')) AS memo,
                CASE WHEN t.amount < 0 THEN -t.amount ELSE 0 END AS soll,
                CASE WHEN t.amount > 0 THEN  t.amount ELSE 0 END AS haben,
                t.amount
         FROM acc_trans t
         LEFT JOIN ar ON ar.id = t.trans_id
         LEFT JOIN ap ON ap.id = t.trans_id
         LEFT JOIN gl ON gl.id = t.trans_id
         LEFT JOIN customer cu ON cu.id = ar.customer_id
         LEFT JOIN vendor   ve ON ve.id = ap.vendor_id
         WHERE t.chart_id = :cid AND t.transdate BETWEEN :from AND :to
         ORDER BY t.transdate, t.trans_id, t.acc_trans_id",
        [':cid' => $cid, ':from' => $from, ':to' => $to]
    );

    // Laufenden Saldo in PHP aufaddieren (Soll−Haben = -amount)
    $saldo = $openingSaldo;
    $sumSoll = 0.0; $sumHaben = 0.0;
    foreach ($rows as &$r) {
        $saldo += floatval($r['soll']) - floatval($r['haben']);
        $r['saldo'] = round($saldo, 2);
        $sumSoll  += floatval($r['soll']);
        $sumHaben += floatval($r['haben']);
    }
    unset($r);

    resultInfo(true, '', [
        'account'   => ['id' => $cid, 'accno' => $chart['accno'], 'description' => $chart['description']],
        'from_date' => $from,
        'to_date'   => $to,
        'opening'   => round($openingSaldo, 2),
        'closing'   => round($saldo, 2),
        'sum_soll'  => round($sumSoll, 2),
        'sum_haben' => round($sumHaben, 2),
        'rows'      => $rows ?: [],
    ]);
}
