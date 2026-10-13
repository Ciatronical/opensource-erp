<?php
// backend/api/banking/booking_detail.php
//
// Gebuchte Bank- und Kassenvorgänge ansehen und ändern.
//
// Ansehen: alle Buchungsbeine (Soll/Haben, Steuerschlüssel) einer Buchung plus
// die daraus rekonstruierten Positionen (Nettobein + folgendes Steuerbein =
// Brutto je Konto) — für Dialogbuchungen (gl), Eingangs- (ap) und
// Ausgangsrechnungen (ar).
//
// Ändern wie kivitendo (GL.pm/AP.pm bei vorhandener id): alte Beine löschen,
// neue schreiben. Dialogbuchungen (Kasse, „Auf Konto buchen") komplett;
// Eingangsrechnungen aus dem Bankumsatz nur die Aufwands-/Steuer-/
// Verbindlichkeitsbeine — die Zahlungsbeine bleiben, der Bruttobetrag ist durch
// die Zahlung festgelegt. Verknüpfungen zu Bankumsätzen
// (bank_transaction_acc_trans) werden nach dem Umbuchen neu gesetzt.
// Buchungen bis defaults.closedto (kivitendo-Abschlussdatum) sind gesperrt.

/**
 * Sperre für abgeschlossene Zeiträume (defaults.closedto).
 *
 * @return string|null Fehlertext oder null
 */
function _bd_closedError($db, $transdate) {
    $row = $db->getOne("SELECT closedto FROM defaults LIMIT 1");
    if (!empty($row['closedto']) && strtotime($transdate) <= strtotime($row['closedto'])) {
        return 'Der Zeitraum bis ' . date('d.m.Y', strtotime($row['closedto'])) . ' ist abgeschlossen — Buchung nicht änderbar';
    }
    return null;
}

/**
 * Kopf, Beine und Positionen einer Buchung (gl, ar oder ap) in einer Abfrage.
 *
 * @param int      $transId    acc_trans.trans_id (gl.id / ar.id / ap.id)
 * @param int|null $ownChartId Bank- oder Kassenkonto, aus dessen Sicht die
 *                             Positionen gebildet werden (dieses Bein zählt nicht)
 */
function _bd_detail($db, $transId, $ownChartId = null) {
    $row = $db->getOne(<<<SQL
        WITH head AS (
            SELECT 'gl'::text AS type, gl.id, gl.reference, gl.description, gl.transdate, gl.taxincluded,
                   NULL::text AS partner_name, NULL::int AS partner_id, NULL::numeric AS amount, NULL::numeric AS paid,
                   COALESCE(gl.storno, false) AS storno
            FROM gl WHERE gl.id = :id
            UNION ALL
            SELECT 'ar', ar.id, ar.invnumber, ar.notes, ar.transdate, ar.taxincluded,
                   c.name, c.id, ar.amount, ar.paid, COALESCE(ar.storno, false)
            FROM ar LEFT JOIN customer c ON c.id = ar.customer_id WHERE ar.id = :id2
            UNION ALL
            SELECT 'ap', ap.id, ap.invnumber, ap.notes, ap.transdate, ap.taxincluded,
                   v.name, v.id, ap.amount, ap.paid, COALESCE(ap.storno, false)
            FROM ap LEFT JOIN vendor v ON v.id = ap.vendor_id WHERE ap.id = :id3
        ),
        legs AS (
            SELECT at.acc_trans_id, at.chart_id, c.accno, c.description, at.amount, at.transdate,
                   at.source, at.memo, at.taxkey, at.tax_id, COALESCE(t.rate, 0) AS tax_rate,
                   c.link ~ '(^|:)(AP|AR)_tax($|:)'          AS is_tax,
                   c.link ~ '(^|:)(AR_paid|AP_paid)($|:)'    AS is_payment_account,
                   c.link ~ '(^|:)(AR|AP)($|:)'              AS is_control,
                   (SELECT json_agg(m.bank_transaction_id) FROM bank_transaction_acc_trans m WHERE m.acc_trans_id = at.acc_trans_id) AS bank_transaction_ids,
                   LEAD(at.amount) OVER w                              AS next_amount,
                   COALESCE(LEAD(c.link ~ '(^|:)(AP|AR)_tax($|:)') OVER w, false) AS next_is_tax
            FROM acc_trans at
            JOIN chart c ON c.id = at.chart_id
            LEFT JOIN tax t ON t.id = at.tax_id
            WHERE at.trans_id = :id4
            WINDOW w AS (ORDER BY at.acc_trans_id)
        ),
        lines AS (
            -- Positionen aus Sicht des eigenen Geldkontos: Nettobeine ohne
            -- Steuer-, Kontroll- (Forderung/Verbindlichkeit) und eigene Geldbeine;
            -- bei Rechnungen auch ohne fremde Zahlungsbeine
            SELECT l.acc_trans_id, l.chart_id, l.accno, l.description, l.tax_id, l.tax_rate,
                   ABS(l.amount) AS net,
                   CASE WHEN l.next_is_tax THEN ABS(l.next_amount) ELSE 0 END AS tax,
                   ABS(l.amount) + CASE WHEN l.next_is_tax THEN ABS(l.next_amount) ELSE 0 END AS gross
            FROM legs l
            WHERE NOT l.is_tax AND NOT l.is_control
              AND l.chart_id <> COALESCE(:own_chart::int, -1)
              AND ((SELECT type FROM head) = 'gl' OR NOT l.is_payment_account)
        )
        SELECT json_build_object(
            'head',  (SELECT row_to_json(head) FROM head),
            'legs',  COALESCE((SELECT json_agg(legs ORDER BY legs.acc_trans_id) FROM legs), '[]'),
            'lines', COALESCE((SELECT json_agg(lines ORDER BY lines.acc_trans_id) FROM lines), '[]')
        )::text AS payload
    SQL, ['id' => $transId, 'id2' => $transId, 'id3' => $transId, 'id4' => $transId, 'own_chart' => $ownChartId]);

    $p = json_decode($row['payload'] ?? '{}', true) ?: [];
    if (empty($p['head'])) return null;
    $p['closed_error'] = _bd_closedError($db, $p['head']['transdate']);
    return $p;
}

/**
 * Buchung(en) zu einem Bankumsatz oder einer Kassenbuchung ansehen.
 *
 * Bankumsatz: alle über bank_transaction_acc_trans verknüpften Buchungen
 * (Sammelbuchung → mehrere). Kasse: die gl-Buchung bzw. die Rechnung der
 * Barzahlung.
 *
 * @param int $data['bank_transaction_id'] Bankumsatz (Variante Bank)
 * @param int $data['trans_id']            gl.id / ar.id / ap.id (Variante Kasse)
 * @param int $data['cash_register_id']    Kassenkonto (chart.id, Variante Kasse)
 * @testdata {"bank_transaction_id": 1}
 */
function getBookingDetail($data) {
    $db = DbhCompany::begin();

    $btId = intval($data['bank_transaction_id'] ?? 0);
    if ($btId > 0) {
        $bt = $db->getOne(<<<SQL
            SELECT bt.id, bt.amount, bt.transdate, bt.remote_name, bt.purpose, ba.chart_id,
                   COALESCE((SELECT json_agg(DISTINCT at.trans_id)
                             FROM bank_transaction_acc_trans m JOIN acc_trans at ON at.acc_trans_id = m.acc_trans_id
                             WHERE m.bank_transaction_id = bt.id), '[]') AS trans_ids
            FROM bank_transactions bt JOIN bank_accounts ba ON ba.id = bt.local_bank_account_id
            WHERE bt.id = :id
        SQL, ['id' => $btId]);
        if (!$bt) { resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden'); return; }
        $bookings = [];
        foreach (json_decode($bt['trans_ids'], true) ?: [] as $tid) {
            $d = _bd_detail($db, intval($tid), intval($bt['chart_id']));
            if ($d) $bookings[] = $d;
        }
        resultInfo(true, '', ['bookings' => $bookings, 'transaction' => [
            'id' => $bt['id'], 'amount' => $bt['amount'], 'transdate' => $bt['transdate'],
            'remote_name' => $bt['remote_name'], 'purpose' => $bt['purpose'],
        ]]);
        return;
    }

    $transId = intval($data['trans_id'] ?? 0);
    if ($transId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'bank_transaction_id oder trans_id fehlt'); return; }
    $d = _bd_detail($db, $transId, intval($data['cash_register_id'] ?? 0) ?: null);
    if (!$d) { resultInfo(false, 'NOT_FOUND', 'Buchung nicht gefunden'); return; }
    resultInfo(true, '', ['bookings' => [$d]]);
}

/**
 * Dialogbuchung (gl) umbuchen: Kopf aktualisieren, alle Beine neu schreiben,
 * Verknüpfungen zu Bankumsätzen nach Konto neu setzen.
 *
 * @param array $head {reference, description, transdate}
 * @param array $legs [[chart_id, amount, chart_link, tax_id, taxkey], …]
 * @return array eingefügte Beine [{acc_trans_id, chart_id}]
 */
function _gl_repost($db, $glId, array $head, array $legs) {
    // Wer hing bisher dran? Der Ersteller (alle Beine) und ggf. die Gegenseite
    // einer Umbuchung (nur ihr Bankbein) — beide bekommen die neuen Beine wieder.
    $links = $db->getAll(<<<SQL
        SELECT m.bank_transaction_id, COUNT(*)::int AS legs, ba.chart_id AS bank_chart_id
        FROM bank_transaction_acc_trans m
        JOIN acc_trans at ON at.acc_trans_id = m.acc_trans_id
        JOIN bank_transactions bt ON bt.id = m.bank_transaction_id
        JOIN bank_accounts ba ON ba.id = bt.local_bank_account_id
        WHERE at.trans_id = :gl
        GROUP BY m.bank_transaction_id, ba.chart_id
    SQL, ['gl' => $glId]) ?: [];

    $db->execute("DELETE FROM bank_transaction_acc_trans WHERE acc_trans_id IN (SELECT acc_trans_id FROM acc_trans WHERE trans_id = :gl)", ['gl' => $glId]);
    $db->execute("DELETE FROM acc_trans WHERE trans_id = :gl", ['gl' => $glId]);
    $db->execute(
        "UPDATE gl SET reference = :reference, description = :description, transdate = :transdate, gldate = :transdate, taxincluded = true, mtime = now() WHERE id = :gl",
        ['reference' => $head['reference'], 'description' => $head['description'], 'transdate' => $head['transdate'], 'gl' => $glId]
    );

    $values = []; $params = ['trans_id' => $glId, 'transdate' => $head['transdate'], 'source' => $head['reference'], 'memo' => $head['memo'] ?? ''];
    foreach ($legs as $k => [$chartId, $amt, $link, $taxId, $taxkey]) {
        $values[] = "(:trans_id, :c{$k}, :a{$k}, :transdate, :transdate, :source, :memo, :l{$k}, :t{$k}, :k{$k})";
        $params["c{$k}"] = $chartId; $params["a{$k}"] = $amt; $params["l{$k}"] = $link;
        $params["t{$k}"] = $taxId;   $params["k{$k}"] = $taxkey;
    }
    $inserted = $db->getAll(
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, tax_id, taxkey)
         VALUES " . implode(', ', $values) . " RETURNING acc_trans_id, chart_id",
        $params
    ) ?: [];

    foreach ($links as $lnk) {
        $linked = 0;
        foreach ($inserted as $leg) {
            $all = intval($lnk['legs']) > 1;
            if ($all || intval($leg['chart_id']) === intval($lnk['bank_chart_id'])) {
                $db->execute(
                    "INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, gl_id) VALUES (:bt, :at, :gl)",
                    ['bt' => intval($lnk['bank_transaction_id']), 'at' => intval($leg['acc_trans_id']), 'gl' => $glId]
                );
                $linked++;
            }
        }
        // Gegenseite einer Umbuchung, die es nach der Änderung nicht mehr gibt
        // (Konto gewechselt): ihr Umsatz ist wieder offen
        if ($linked === 0) {
            $db->execute(
                "WITH u AS (UPDATE bank_transactions SET cleared = false WHERE id = :id) INSERT INTO bank_transactions_ext (bank_transaction_id, match_status) VALUES (:id2, 'unmatched') ON CONFLICT (bank_transaction_id) DO UPDATE SET match_status = EXCLUDED.match_status",
                ['id' => intval($lnk['bank_transaction_id']), 'id2' => intval($lnk['bank_transaction_id'])]
            );
        }
    }
    return $inserted;
}

/**
 * Manuelle Kassenbuchung ändern (Datum, Richtung, Betrag, Positionen, Text,
 * Belegnummer) — wie createCashTransaction, nur auf eine vorhandene gl.
 *
 * @param int    $data['gl_id']            Buchung
 * @param int    $data['cash_register_id'] Kassenkonto (chart.id)
 * @param string $data['transdate']        Buchungsdatum
 * @param float  $data['amount']           Bruttobetrag gesamt
 * @param string $data['type']             income|expense
 * @param array  $data['lines']            Positionen [{chart_id, gross, tax_id?}]
 * @param string $data['description']      Buchungstext (optional)
 * @param string $data['reference']        Belegnummer (optional, sonst unverändert)
 * @param int    $data['document_id']      zusätzlich verknüpfter Beleg (optional)
 * @testdata {"gl_id": 1, "cash_register_id": 1, "transdate": "2026-06-04", "amount": 50.00, "type": "expense", "lines": [{"chart_id": 1, "gross": 50.00}], "description": "Büromaterial"}
 */
function updateCashTransaction($data) {
    $db = DbhCompany::begin();

    $glId       = intval($data['gl_id'] ?? 0);
    $registerId = intval($data['cash_register_id'] ?? 0);
    $amount     = abs(floatval($data['amount'] ?? 0));
    $type       = $data['type'] ?? 'expense';
    $lines      = is_array($data['lines'] ?? null) ? array_values($data['lines']) : [];
    if ($glId <= 0 || $registerId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'gl_id und Kassenbuch-ID fehlen'); return; }
    if (!$lines) { resultInfo(false, 'VALIDATION_ERROR', 'Gegenkonto fehlt'); return; }

    $register = _kasse_loadRegister($db, $registerId);
    if (!$register) { resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden'); return; }

    // Die Buchung muss eine Kassenbuchung dieses Kontos sein
    $old = $db->getOne(<<<SQL
        SELECT gl.id, gl.reference, gl.transdate, COALESCE(gl.storno, false) AS storno,
               (SELECT at.amount FROM acc_trans at WHERE at.trans_id = gl.id AND at.chart_id = :cash ORDER BY at.acc_trans_id LIMIT 1) AS cash_amount
        FROM gl WHERE gl.id = :gl
    SQL, ['gl' => $glId, 'cash' => $register['chart_id']]);
    if (!$old || $old['cash_amount'] === null) { resultInfo(false, 'NOT_FOUND', 'Kassenbuchung nicht gefunden'); return; }
    if ($old['storno']) { resultInfo(false, 'VALIDATION_ERROR', 'Stornierte Buchung kann nicht geändert werden'); return; }
    $transdate = $data['transdate'] ?? $old['transdate'];
    foreach ([$old['transdate'], $transdate] as $d) {
        if ($err = _bd_closedError($db, $d)) { resultInfo(false, 'PERIOD_CLOSED', $err); return; }
    }

    $sum = 0.0;
    foreach ($lines as $i => $l) {
        $g = round(abs(floatval($l['gross'] ?? 0)), 2);
        if (intval($l['chart_id'] ?? 0) <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Position ' . ($i + 1) . ': Gegenkonto fehlt'); return; }
        if ($g <= 0)                           { resultInfo(false, 'VALIDATION_ERROR', 'Position ' . ($i + 1) . ': Betrag muss größer 0 sein'); return; }
        $lines[$i]['gross'] = $g; $sum += $g;
    }
    $sum = round($sum, 2);
    if ($amount <= 0) $amount = $sum;
    elseif (abs($amount - $sum) > 0.005) {
        resultInfo(false, 'VALIDATION_ERROR', 'Die Positionen ergeben ' . number_format($sum, 2, ',', '.') . ' €, der Betrag ist '
            . number_format($amount, 2, ',', '.') . ' €. Bitte die Aufteilung prüfen.');
        return;
    }

    $resolved = _kasse_resolveLineCharts($db, $lines, $transdate);
    if (count($resolved) !== count($lines)) { resultInfo(false, 'NOT_FOUND', 'Gegenkonto nicht gefunden'); return; }

    // Kasse darf nie ins Minus — ohne die alte Buchung gerechnet
    if ($type === 'expense') {
        $available = _kasse_availableBalance($db, $register['chart_id'], $transdate) + floatval($old['cash_amount']);
        if ($amount > $available + 0.005) {
            resultInfo(false, 'Diese Änderung würde die Kasse ins Minus bringen. Verfügbar: '
                . number_format($available, 2, ',', '.') . ' €, benötigt: ' . number_format($amount, 2, ',', '.') . ' €.',
                ['code' => 'NEGATIVE_CASH', 'available' => $available]);
            return;
        }
    }

    $counterSign = $type === 'expense' ? -1 : 1;
    $legs = [[$register['chart_id'], -$counterSign * $amount, $register['chart_link'], 0, 0]];
    $summary = [];
    foreach ($lines as $i => $l) {
        $r = $resolved[$i]; $g = $counterSign * $l['gross'];
        $net = $r['rate'] > 0 ? round($g / (1 + $r['rate']), 2) : $g; $tax = round($g - $net, 2);
        $legs[] = [$r['chart_id'], $net, $r['link'], $r['tax_id'], $r['taxkey']];
        if (abs($tax) > 0.004) $legs[] = [$r['tax_chart_id'], $tax, $r['tax_chart_link'], $r['tax_id'], $r['taxkey']];
        $summary[] = ['accno' => $r['accno'], 'description' => $r['description'], 'gross' => $l['gross'], 'net' => abs($net), 'tax' => abs($tax), 'tax_rate' => $r['rate']];
    }

    $reference   = trim($data['reference'] ?? '') ?: $old['reference'];
    $description = trim($data['description'] ?? '') ?: ($type === 'expense' ? 'Barausgabe' : 'Bareinnahme');
    _gl_repost($db, $glId, ['reference' => $reference, 'description' => $description, 'transdate' => $transdate], $legs);

    $documentId = intval($data['document_id'] ?? 0);
    if ($documentId && _kasse_documentsEnabled($db)) {
        $db->execute(
            "INSERT INTO cash_gl_documents (gl_id, document_id) SELECT :gl, :doc WHERE NOT EXISTS (SELECT 1 FROM cash_gl_documents WHERE gl_id = :gl2 AND document_id = :doc2)",
            ['gl' => $glId, 'doc' => $documentId, 'gl2' => $glId, 'doc2' => $documentId]
        );
    }

    resultInfo(true, 'Buchung geändert', ['gl_id' => $glId, 'beleg' => $reference, 'gross' => $amount, 'lines' => $summary]);
}

/**
 * Dialogbuchung eines Bankumsatzes ändern („Auf Konto buchen" nachträglich):
 * Konto(en), Steuer, Text. Der Bruttobetrag ist der Umsatzbetrag und bleibt.
 *
 * @param int    $data['bank_transaction_id'] Bankumsatz (gebucht, Dialogbuchung)
 * @param array  $data['lines']               Positionen [{chart_id, gross, tax_id?}]
 * @param int    $data['bank_account_id']     Ziel-Bankkonto (Alternative: Umbuchung)
 * @param string $data['description']         Buchungstext (optional)
 * @testdata {"bank_transaction_id": 1, "lines": [{"chart_id": 257, "gross": 10}], "description": "Mahngebühr"}
 */
function updateChartBooking($data) {
    $db   = DbhCompany::begin();
    $btId = intval($data['bank_transaction_id'] ?? 0);
    if ($btId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt'); return; }

    $bt = _cb_loadTransaction($db, $btId);
    if (!$bt) { resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden'); return; }
    $gl = $db->getOne(<<<SQL
        SELECT gl.id, gl.reference, gl.transdate, COALESCE(gl.storno, false) AS storno
        FROM bank_transaction_acc_trans m JOIN gl ON gl.id = m.gl_id
        WHERE m.bank_transaction_id = :bt LIMIT 1
    SQL, ['bt' => $btId]);
    if (!$gl) { resultInfo(false, 'NOT_FOUND', 'Zu diesem Umsatz gibt es keine Dialogbuchung'); return; }
    if ($gl['storno']) { resultInfo(false, 'VALIDATION_ERROR', 'Stornierte Buchung kann nicht geändert werden'); return; }
    if ($err = _bd_closedError($db, $gl['transdate'])) { resultInfo(false, 'PERIOD_CLOSED', $err); return; }

    $amount = round(floatval($bt['amount']), 2);
    $gross  = abs($amount);
    $lines  = is_array($data['lines'] ?? null) ? array_values($data['lines']) : [];
    $targetAccount = null;
    if (!$lines && intval($data['bank_account_id'] ?? 0) > 0) {
        $targetAccount = $db->getOne("SELECT ba.id, ba.name, ba.chart_id FROM bank_accounts ba WHERE ba.id = :id AND ba.id <> :own",
            ['id' => intval($data['bank_account_id']), 'own' => intval($bt['local_bank_account_id'])]);
        if (!$targetAccount) { resultInfo(false, 'NOT_FOUND', 'Ziel-Bankkonto nicht gefunden'); return; }
        $lines = [['chart_id' => $targetAccount['chart_id'], 'gross' => $gross, 'tax_id' => 0]];
    }
    if (!$lines) { resultInfo(false, 'VALIDATION_ERROR', 'Konto fehlt'); return; }
    $sum = 0.0;
    foreach ($lines as $i => $l) {
        $g = round(abs(floatval($l['gross'] ?? 0)), 2);
        if (intval($l['chart_id'] ?? 0) <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Position ' . ($i + 1) . ': Konto fehlt'); return; }
        if ($g <= 0)                           { resultInfo(false, 'VALIDATION_ERROR', 'Position ' . ($i + 1) . ': Betrag muss größer 0 sein'); return; }
        if (intval($l['chart_id']) === intval($bt['chart_id'])) { resultInfo(false, 'VALIDATION_ERROR', 'Das Bankkonto des Umsatzes kann nicht sein eigenes Gegenkonto sein'); return; }
        $lines[$i]['gross'] = $g; $sum += $g;
    }
    if (abs(round($sum, 2) - $gross) > 0.005) {
        resultInfo(false, 'VALIDATION_ERROR', 'Die Positionen ergeben ' . number_format($sum, 2, ',', '.') . ' €, der Umsatz lautet über '
            . number_format($gross, 2, ',', '.') . ' €. Bitte die Aufteilung prüfen.');
        return;
    }
    $resolved = _kasse_resolveLineCharts($db, $lines, $bt['transdate']);
    if (count($resolved) !== count($lines)) { resultInfo(false, 'NOT_FOUND', 'Konto nicht gefunden'); return; }

    $counterSign = $amount > 0 ? 1 : -1;
    $legs = [[$bt['chart_id'], -$amount, $bt['chart_link'], 0, 0]];
    $summary = [];
    foreach ($lines as $i => $l) {
        $r = $resolved[$i]; $g = $counterSign * $l['gross'];
        $net = $r['rate'] > 0 ? round($g / (1 + $r['rate']), 2) : $g; $tax = round($g - $net, 2);
        $legs[] = [$r['chart_id'], $net, $r['link'], $r['tax_id'], $r['taxkey']];
        if (abs($tax) > 0.004) $legs[] = [$r['tax_chart_id'], $tax, $r['tax_chart_link'], $r['tax_id'], $r['taxkey']];
        $summary[] = ['chart_id' => $r['chart_id'], 'accno' => $r['accno'], 'description' => $r['description'], 'gross' => $l['gross'], 'net' => abs($net), 'tax' => abs($tax), 'tax_rate' => $r['rate']];
    }
    $description = trim($data['description'] ?? '') ?: trim(($bt['remote_name'] ?? '') . ' · ' . ($bt['purpose'] ?? ''), ' ·');
    $inserted = _gl_repost($db, intval($gl['id']), [
        'reference' => $gl['reference'], 'description' => $description, 'transdate' => $bt['transdate'],
        'memo' => "Beleg {$gl['reference']} · Dialogbuchung Umsatz #{$btId}",
    ], $legs);

    // Neues Zielkonto als eigenes Bankkonto: Gegenumsatz dort mit verknüpfen,
    // falls er noch nicht an dieser Buchung hängt
    $counterpartBooked = _cb_linkCounterpart($db, $btId, intval($gl['id']), $inserted, $summary, $bt, $counterSign);

    resultInfo(true, 'Buchung geändert', [
        'gl_id' => intval($gl['id']), 'beleg' => $gl['reference'], 'gross' => $gross, 'lines' => $summary,
        'counterpart_booked' => $counterpartBooked, 'target_account' => $targetAccount ? $targetAccount['name'] : null,
    ]);
}

/**
 * Eingangsrechnung, die aus einem Bankumsatz entstand, ändern: Lieferant,
 * Rechnungsnummer, Text und die Aufwandspositionen (Konto, Brutto, Steuersatz).
 * Die Zahlungsbeine bleiben; die Positionssumme muss dem bezahlten Betrag
 * entsprechen. Wie kivitendos AP.pm: Erstbuchungsbeine löschen und neu schreiben.
 *
 * @param int    $data['bank_transaction_id'] Bankumsatz (gebucht gegen die Eingangsrechnung)
 * @param int    $data['ap_id']               Eingangsrechnung (optional, sonst aus dem Umsatz)
 * @param array  $data['lines']               Positionen [{expense_chart_id|debit_account, gross, rate}]
 * @param int    $data['vendor_id']           Lieferant (optional)
 * @param string $data['invnumber']           Rechnungsnummer (optional)
 * @param string $data['notes']               Buchungstext (optional)
 * @testdata {"bank_transaction_id": 1, "lines": [{"debit_account": "5400", "gross": 167.02, "rate": 19}, {"debit_account": "6436", "gross": 10, "rate": 0}]}
 */
function updateApFromBankTransaction($data) {
    require_once __DIR__ . '/../accounting/incoming_invoice_posting.php';
    $db   = DbhCompany::begin();
    $btId = intval($data['bank_transaction_id'] ?? 0);
    $apId = intval($data['ap_id'] ?? 0);
    if ($btId <= 0 && $apId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt'); return; }

    if ($apId <= 0) {
        $m = $db->getOne("SELECT ap_id FROM bank_transaction_acc_trans WHERE bank_transaction_id = :bt AND ap_id IS NOT NULL LIMIT 1", ['bt' => $btId]);
        $apId = intval($m['ap_id'] ?? 0);
    }
    if ($apId <= 0) { resultInfo(false, 'NOT_FOUND', 'Zu diesem Umsatz gibt es keine Eingangsrechnung'); return; }

    $ap = $db->getOne("SELECT id, invnumber, transdate, amount, paid, vendor_id, notes, COALESCE(storno, false) AS storno FROM ap WHERE id = :id", ['id' => $apId]);
    if (!$ap) { resultInfo(false, 'NOT_FOUND', 'Eingangsrechnung nicht gefunden'); return; }
    if ($ap['storno']) { resultInfo(false, 'VALIDATION_ERROR', 'Stornierte Rechnung kann nicht geändert werden'); return; }
    if ($err = _bd_closedError($db, $ap['transdate'])) { resultInfo(false, 'PERIOD_CLOSED', $err); return; }

    $lines = is_array($data['lines'] ?? null) ? array_values($data['lines']) : [];
    if (!$lines) { resultInfo(false, 'VALIDATION_ERROR', 'Keine Positionen angegeben'); return; }
    foreach ($lines as $i => $l) { $lines[$i]['gross'] = round(abs(floatval($l['gross'] ?? 0)), 2); unset($lines[$i]['net'], $lines[$i]['tax']); }
    try { $norm = _iv_normalizeLines($db, $lines); }
    catch (ApiError $e) { resultInfo(false, $e->getMessage()); return; }

    $gross = round(array_sum(array_column($norm, 'gross')), 2);
    $net   = round(array_sum(array_column($norm, 'net')), 2);
    if (abs($gross - round(floatval($ap['amount']), 2)) > 0.005) {
        resultInfo(false, 'VALIDATION_ERROR', 'Die Positionen ergeben ' . number_format($gross, 2, ',', '.') . ' €, die Rechnung lautet über '
            . number_format(floatval($ap['amount']), 2, ',', '.') . ' € (durch die Zahlung festgelegt). Bitte die Aufteilung prüfen.');
        return;
    }

    $apAcc = _iv_apAccount($db);
    if (!$apAcc) { resultInfo(false, 'DATA_ERROR', 'Verbindlichkeitskonto (AP) nicht im Kontenrahmen'); return; }

    // Erstbuchungsbeine entfernen: alles, was weder Zahlungsbein (verknüpft oder
    // Geldkonto) noch Zahlungs-Gegenbein (Verbindlichkeit mit Belegnummer) ist
    $db->execute(<<<SQL
        DELETE FROM acc_trans at
        WHERE at.trans_id = :ap
          AND NOT EXISTS (SELECT 1 FROM bank_transaction_acc_trans m WHERE m.acc_trans_id = at.acc_trans_id)
          AND at.chart_link !~ '(^|:)(AR_paid|AP_paid)($|:)'
          AND NOT (at.chart_link ~ '(^|:)AP($|:)' AND (COALESCE(at.source, '') <> '' OR COALESCE(at.memo, '') <> ''))
    SQL, ['ap' => $apId]);

    $legs = [];
    foreach ($norm as $l) {
        $ti = $l['taxInfo'];
        $legs[] = [$l['chart_id'], -$l['net'], $l['link'], $ti['taxkey'], $ti['tax_id']];
        if (abs($l['tax']) > 0.004 && $ti['vorsteuer_chart_id']) $legs[] = [$ti['vorsteuer_chart_id'], -$l['tax'], $ti['vorsteuer_link'], $ti['taxkey'], $ti['tax_id']];
    }
    $legs[] = [intval($apAcc['id']), $gross, 'AP', 0, 0];
    $values = []; $params = [':tid' => $apId, ':td' => $ap['transdate']];
    foreach ($legs as $k => [$chartId, $amt, $link, $taxkey, $taxId]) {
        $values[] = "(:tid, :c{$k}, :a{$k}, :td, :td, '', '', :l{$k}, :k{$k}, :t{$k})";
        $params[":c{$k}"] = $chartId; $params[":a{$k}"] = $amt; $params[":l{$k}"] = $link; $params[":k{$k}"] = $taxkey; $params[":t{$k}"] = $taxId;
    }
    $db->execute("INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, taxkey, tax_id) VALUES " . implode(', ', $values), $params);

    $vendorId  = intval($data['vendor_id'] ?? 0) ?: intval($ap['vendor_id']);
    $invnumber = trim($data['invnumber'] ?? '') ?: $ap['invnumber'];
    $notes     = array_key_exists('notes', $data) ? (trim($data['notes']) ?: null) : $ap['notes'];
    $db->execute(
        "UPDATE ap SET vendor_id = :v, invnumber = :inv, notes = :notes, netamount = :net, amount = :gross, mtime = now() WHERE id = :id",
        ['v' => $vendorId, 'inv' => $invnumber, 'notes' => $notes, 'net' => $net, 'gross' => $gross, 'id' => $apId]
    );

    resultInfo(true, 'Eingangsrechnung geändert', ['ap_id' => $apId, 'gross' => $gross, 'line_count' => count($norm)]);
}
