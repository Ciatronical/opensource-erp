<?php
// backend/api/banking/chart_booking.php
//
// Bankumsatz ohne Beleg direkt auf ein Konto buchen — kivitendos Dialogbuchung
// aus dem Bankumsatz heraus: Umbuchung zwischen eigenen Konten, Bareinzahlung,
// Privatentnahme/-einlage, Lohn, Sozialversicherung, Finanzamt, Bankgebühren,
// Darlehen. Buchungssatz wie createCashTransaction (gl + acc_trans): das
// Bankbein trägt den Bruttobetrag, je Position ein Nettobein mit Steuer-
// schlüssel und direkt dahinter das Steuerbein. Die Verknüpfung zum Umsatz
// liegt in der kivitendo-Tabelle bank_transaction_acc_trans (gl_id).
//
// Umbuchung auf ein eigenes Bankkonto erzeugt EINE Buchung Bank A ↔ Bank B.
// Der Gegenumsatz auf Konto B wird mitgebucht, wenn er schon da ist (gleicher
// Betrag umgekehrt, ±5 Tage); kommt er erst später, erkennt getChartBookingOptions
// das vorhandene Bankbein und bietet „nur verknüpfen" an (linkTransactionToGl).

/**
 * Bankumsatz mit Bankkonto und dessen Konto laden.
 */
function _cb_loadTransaction($db, $btId) {
    return $db->getOne(<<<SQL
        SELECT bt.id, bt.amount, bt.transdate, bt.remote_name, bt.remote_account_number, bt.purpose,
               bt.local_bank_account_id, ba.chart_id, ch.link AS chart_link, ch.accno AS bank_accno,
               COALESCE(bte.match_status, 'unmatched') AS match_status
        FROM bank_transactions bt
        JOIN bank_accounts ba ON ba.id = bt.local_bank_account_id
        JOIN chart ch ON ch.id = ba.chart_id
        LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id
        WHERE bt.id = :id
    SQL, ['id' => $btId]);
}

/**
 * Vorlagen, eigene Konten, Kassenkonto und Erkennung für den Dialog
 * „Auf Konto buchen" — alles in einer Abfrage.
 *
 * Erkennung (suggestion), in dieser Reihenfolge:
 *   link          → auf dem Bankkonto liegt bereits ein passendes Bankbein einer
 *                   Dialogbuchung (z. B. Umbuchung vom anderen Konto aus gebucht,
 *                   Bareinzahlung aus der Kasse): nur verknüpfen
 *   transfer_own  → Gegen-IBAN gehört zu einem eigenen Bankkonto
 *   template      → Verwendungszweck/Empfänger passt zum Muster einer Vorlage
 *
 * @param int $data['bank_transaction_id'] Bankumsatz
 * @testdata {"bank_transaction_id": 1}
 */
function getChartBookingOptions($data) {
    $db   = DbhCompany::begin();
    $btId = intval($data['bank_transaction_id'] ?? 0);
    if ($btId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt'); return; }

    $kasse = kassenkontoBedingung('kc');

    $row = $db->getOne(<<<SQL
        WITH bt AS (
            SELECT bt.id, bt.amount, bt.transdate, bt.remote_name, bt.purpose,
                   regexp_replace(COALESCE(bt.remote_account_number, ''), '\s', '', 'g') AS remote_iban,
                   bt.local_bank_account_id, ba.chart_id,
                   COALESCE(bte.match_status, 'unmatched') AS match_status
            FROM bank_transactions bt
            JOIN bank_accounts ba ON ba.id = bt.local_bank_account_id
            LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id
            WHERE bt.id = :id
        ),
        coa AS (
            SELECT CASE WHEN d.coa LIKE '%SKR03%' THEN 'skr03' ELSE 'skr04' END AS v FROM defaults d LIMIT 1
        ),
        cash AS (
            -- echtes Kassenkonto zuerst: Zahlungskonto (AR_paid) und Aktivkonto
            SELECT kc.id AS chart_id, kc.accno, kc.description
            FROM chart kc
            WHERE kc.charttype = 'A' AND {$kasse}
            ORDER BY (kc.link ~ '(^|:)AR_paid($|:)') DESC, (kc.category = 'A') DESC, kc.accno
            LIMIT 1
        ),
        templates AS (
            SELECT t.key, t.label, t.icon, t.direction, t.sort, t.hit_count,
                   ch.id AS chart_id, ch.accno, ch.description AS chart_description,
                   COALESCE(tk.rate, 0)   AS tax_rate,
                   COALESCE(tk.tax_id, 0) AS tax_id,
                   (t.keyword_regex IS NOT NULL
                    AND (COALESCE(bt.purpose, '') ~* t.keyword_regex OR COALESCE(bt.remote_name, '') ~* t.keyword_regex)) AS keyword_hit
            FROM bank_booking_templates t
            CROSS JOIN bt
            LEFT JOIN chart ch
                   ON ch.id = CASE WHEN t.key = 'cash' THEN (SELECT chart_id FROM cash) ELSE NULL END
                   OR (t.key <> 'cash' AND ch.accno = CASE (SELECT v FROM coa) WHEN 'skr03' THEN t.accno_skr03 ELSE t.accno_skr04 END)
            LEFT JOIN LATERAL (
                SELECT tx.rate, tx.id AS tax_id
                FROM taxkeys k JOIN tax tx ON tx.id = k.tax_id
                WHERE k.chart_id = ch.id AND k.startdate <= bt.transdate AND tx.chart_id IS NOT NULL
                ORDER BY k.startdate DESC LIMIT 1
            ) tk ON true
            WHERE t.active
              AND (t.direction = 'any' OR (t.direction = 'in') = (bt.amount > 0))
              AND (ch.id IS NOT NULL OR t.key IN ('transfer_own', 'other'))
        ),
        own_accounts AS (
            -- andere eigene Bankkonten: Ziel einer Umbuchung. Gegenumsatz = gleicher
            -- Betrag umgekehrt auf dem Zielkonto, ±5 Tage, noch nicht gebucht
            SELECT ba.id, ba.name, ba.iban, ba.chart_id, ch.accno, ch.description,
                   (bt.remote_iban <> '' AND regexp_replace(COALESCE(ba.iban, ''), '\s', '', 'g') = bt.remote_iban) AS matches_remote_iban,
                   (SELECT json_build_object('id', b2.id, 'transdate', b2.transdate, 'amount', b2.amount,
                                             'purpose', b2.purpose, 'remote_name', b2.remote_name)
                    FROM bank_transactions b2
                    LEFT JOIN bank_transactions_ext e2 ON e2.bank_transaction_id = b2.id
                    WHERE b2.local_bank_account_id = ba.id AND b2.id <> bt.id
                      AND abs(b2.amount + bt.amount) < 0.005
                      AND b2.transdate BETWEEN bt.transdate - 5 AND bt.transdate + 5
                      AND COALESCE(e2.match_status, 'unmatched') IN ('unmatched', 'matched')
                    ORDER BY abs(b2.transdate - bt.transdate), b2.id LIMIT 1) AS counterpart_transaction
            FROM bank_accounts ba
            JOIN chart ch ON ch.id = ba.chart_id
            CROSS JOIN bt
            WHERE ba.id <> bt.local_bank_account_id
        ),
        existing_leg AS (
            -- vorhandenes Bankbein einer Dialogbuchung, noch keinem Umsatz zugeordnet
            SELECT at.acc_trans_id, at.trans_id AS gl_id, gl.reference, gl.description, at.transdate, at.amount,
                   (SELECT string_agg(c2.accno || ' ' || c2.description, ', ' ORDER BY a2.acc_trans_id)
                    FROM acc_trans a2 JOIN chart c2 ON c2.id = a2.chart_id
                    WHERE a2.trans_id = at.trans_id AND a2.chart_id <> at.chart_id
                      AND c2.link !~ '(^|:)(AP|AR)_tax($|:)') AS counter_accounts
            FROM acc_trans at
            JOIN gl ON gl.id = at.trans_id AND (gl.storno IS NULL OR gl.storno = false)
            CROSS JOIN bt
            WHERE at.chart_id = bt.chart_id
              AND abs(at.amount + bt.amount) < 0.005
              AND at.transdate BETWEEN bt.transdate - 5 AND bt.transdate + 5
              -- noch kein Umsatz DIESES Bankkontos hängt daran (die Gegenseite der
              -- Umbuchung ist mit dem Umsatz des anderen Kontos verknüpft — das zählt nicht)
              AND NOT EXISTS (SELECT 1 FROM bank_transaction_acc_trans m
                              JOIN bank_transactions b3 ON b3.id = m.bank_transaction_id
                              WHERE m.acc_trans_id = at.acc_trans_id
                                AND b3.local_bank_account_id = bt.local_bank_account_id)
            ORDER BY abs(at.transdate - bt.transdate), at.acc_trans_id LIMIT 1
        )
        SELECT json_build_object(
            'transaction',  (SELECT row_to_json(bt) FROM bt),
            -- heute gültige Steuerschlüssel für die Positionen (wie getCashCounterCharts)
            'taxes', COALESCE((
                SELECT json_agg(tx ORDER BY tx.kind, tx.usage DESC, tx.rate DESC, tx.taxkey)
                FROM (
                    SELECT t.id AS tax_id, t.taxkey, t.rate, t.taxdescription,
                           CASE WHEN tc.link ~ '(^|:)AP_tax($|:)' THEN 'expense' ELSE 'income' END AS kind,
                           COUNT(k.chart_id) AS usage
                    FROM tax t
                    JOIN chart tc ON tc.id = t.chart_id
                    JOIN taxkeys k ON k.tax_id = t.id
                                  AND k.startdate = (SELECT MAX(k2.startdate) FROM taxkeys k2
                                                     WHERE k2.chart_id = k.chart_id AND k2.startdate <= CURRENT_DATE)
                    WHERE t.rate > 0 AND tc.link ~ '(^|:)(AP|AR)_tax($|:)'
                    GROUP BY t.id, t.taxkey, t.rate, t.taxdescription, tc.link
                ) tx
            ), '[]'),
            'templates',    COALESCE((SELECT json_agg(templates ORDER BY templates.sort) FROM templates), '[]'),
            'own_accounts', COALESCE((SELECT json_agg(own_accounts ORDER BY own_accounts.name) FROM own_accounts), '[]'),
            'cash_chart',   (SELECT row_to_json(cash) FROM cash),
            'existing_leg', (SELECT row_to_json(existing_leg) FROM existing_leg)
        )::text AS payload
    SQL, ['id' => $btId]);

    $p = json_decode($row['payload'] ?? '{}', true) ?: [];
    if (empty($p['transaction'])) { resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden'); return; }

    // Erkennung: vorhandene Buchung > eigene IBAN > Muster (bei mehreren Treffern
    // die meistgenutzte Vorlage, sonst die erste nach Sortierung)
    $suggestion = null;
    if (!empty($p['existing_leg'])) {
        $suggestion = ['type' => 'link', 'acc_trans_id' => $p['existing_leg']['acc_trans_id']];
    } else {
        foreach ($p['own_accounts'] as $acc) {
            if (!empty($acc['matches_remote_iban'])) { $suggestion = ['type' => 'transfer_own', 'bank_account_id' => $acc['id']]; break; }
        }
        if (!$suggestion) {
            $hits = array_values(array_filter($p['templates'], fn($t) => !empty($t['keyword_hit']) && $t['key'] !== 'transfer_own'));
            usort($hits, fn($a, $b) => [$b['hit_count'], $a['sort']] <=> [$a['hit_count'], $b['sort']]);
            if ($hits) $suggestion = ['type' => 'template', 'key' => $hits[0]['key']];
        }
    }

    resultInfo(true, '', [
        'transaction'  => $p['transaction'],
        'taxes'        => $p['taxes'] ?? [],
        'templates'    => $p['templates'],
        'own_accounts' => $p['own_accounts'],
        'cash_chart'   => $p['cash_chart'],
        'existing_leg' => $p['existing_leg'],
        'suggestion'   => $suggestion,
    ]);
}

/**
 * Bankumsatz auf ein oder mehrere Konten buchen (Dialogbuchung, gl + acc_trans).
 *
 * Entweder `lines` (Positionen mit Konto, Brutto, optional tax_id — Summe muss
 * dem Umsatzbetrag entsprechen) oder `bank_account_id` (Umbuchung: ganzer
 * Betrag auf das Konto des eigenen Bankkontos). Ein Gegenumsatz auf dem
 * Zielkonto wird automatisch mit verknüpft und als gebucht markiert.
 *
 * @param int    $data['bank_transaction_id'] Bankumsatz (Pflicht)
 * @param array  $data['lines']               Positionen [{chart_id, gross, tax_id?}]
 * @param int    $data['bank_account_id']     Ziel-Bankkonto (Alternative zu lines)
 * @param string $data['description']         Buchungstext (optional, sonst Empfänger · Zweck)
 * @param string $data['template_key']        Benutzte Vorlage (Zähler für die Erkennung, optional)
 * @testdata {"bank_transaction_id": 1, "lines": [{"chart_id": 257, "gross": 10}], "description": "Mahngebühr", "template_key": "other"}
 */
function bookTransactionToChart($data) {
    $db   = DbhCompany::begin();
    $btId = intval($data['bank_transaction_id'] ?? 0);
    if ($btId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt'); return; }

    $bt = _cb_loadTransaction($db, $btId);
    if (!$bt) { resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden'); return; }
    if ($bt['match_status'] === 'booked') { resultInfo(false, 'ALREADY_BOOKED', 'Umsatz ist bereits gebucht'); return; }

    $amount = round(floatval($bt['amount']), 2);
    $gross  = abs($amount);
    if ($gross <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Bankumsatz ohne Betrag'); return; }

    // Positionen: Umbuchung auf eigenes Konto = eine Position über den ganzen Betrag
    $lines         = is_array($data['lines'] ?? null) ? array_values($data['lines']) : [];
    $targetAccount = null;
    if (!$lines && intval($data['bank_account_id'] ?? 0) > 0) {
        $targetAccount = $db->getOne(
            "SELECT ba.id, ba.name, ba.chart_id FROM bank_accounts ba WHERE ba.id = :id AND ba.id <> :own",
            ['id' => intval($data['bank_account_id']), 'own' => intval($bt['local_bank_account_id'])]
        );
        if (!$targetAccount) { resultInfo(false, 'NOT_FOUND', 'Ziel-Bankkonto nicht gefunden'); return; }
        $lines = [['chart_id' => $targetAccount['chart_id'], 'gross' => $gross, 'tax_id' => 0]];
    }
    if (!$lines) { resultInfo(false, 'VALIDATION_ERROR', 'Konto fehlt'); return; }

    $sum = 0.0;
    foreach ($lines as $i => $l) {
        $g = round(abs(floatval($l['gross'] ?? 0)), 2);
        if (intval($l['chart_id'] ?? 0) <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Position ' . ($i + 1) . ': Konto fehlt'); return; }
        if ($g <= 0)                           { resultInfo(false, 'VALIDATION_ERROR', 'Position ' . ($i + 1) . ': Betrag muss größer 0 sein'); return; }
        $lines[$i]['gross'] = $g;
        $sum += $g;
    }
    if (abs(round($sum, 2) - $gross) > 0.005) {
        resultInfo(false, 'VALIDATION_ERROR',
            'Die Positionen ergeben ' . number_format($sum, 2, ',', '.') . ' €, der Umsatz lautet über '
            . number_format($gross, 2, ',', '.') . ' €. Bitte die Aufteilung prüfen.');
        return;
    }
    foreach ($lines as $l) {
        if (intval($l['chart_id']) === intval($bt['chart_id'])) { resultInfo(false, 'VALIDATION_ERROR', 'Das Bankkonto des Umsatzes kann nicht sein eigenes Gegenkonto sein'); return; }
    }

    $resolved = _kasse_resolveLineCharts($db, $lines, $bt['transdate']);
    if (count($resolved) !== count($lines)) { resultInfo(false, 'NOT_FOUND', 'Konto nicht gefunden'); return; }

    // Vorzeichen (Soll = negativ, Haben = positiv): Geldeingang → Bank Soll (−),
    // Gegenkonto Haben (+); Geldausgang → Bank Haben (+), Gegenkonto Soll (−).
    $counterSign = $amount > 0 ? 1 : -1;
    $transdate   = $bt['transdate'];
    $beleg       = nextBelegnummer($db, $bt['chart_id'], $transdate);
    $memo        = "Beleg {$beleg} · Dialogbuchung Umsatz #{$btId}";
    $description = trim($data['description'] ?? '') ?: trim(($bt['remote_name'] ?? '') . ' · ' . ($bt['purpose'] ?? ''), ' ·');

    $glRow = $db->getOne(
        "INSERT INTO gl (reference, description, transdate, gldate, employee_id, taxincluded)
         VALUES (:reference, :description, :transdate, :transdate, :employee_id, true)
         RETURNING id",
        ['reference' => $beleg, 'description' => $description, 'transdate' => $transdate, 'employee_id' => mitarbeiterId($data)]
    );
    $glId = intval($glRow['id']);

    $legs = [[$bt['chart_id'], -$amount, $bt['chart_link'], 0, 0]];
    $summary = [];
    foreach ($lines as $i => $l) {
        $r   = $resolved[$i];
        $g   = $counterSign * $l['gross'];
        $net = $r['rate'] > 0 ? round($g / (1 + $r['rate']), 2) : $g;
        $tax = round($g - $net, 2);
        $legs[] = [$r['chart_id'], $net, $r['link'], $r['tax_id'], $r['taxkey']];
        if (abs($tax) > 0.004) $legs[] = [$r['tax_chart_id'], $tax, $r['tax_chart_link'], $r['tax_id'], $r['taxkey']];
        $summary[] = ['chart_id' => $r['chart_id'], 'accno' => $r['accno'], 'description' => $r['description'],
                      'gross' => $l['gross'], 'net' => abs($net), 'tax' => abs($tax), 'tax_rate' => $r['rate']];
    }

    $values = []; $params = ['trans_id' => $glId, 'transdate' => $transdate, 'source' => $beleg, 'memo' => $memo];
    foreach ($legs as $k => [$chartId, $amt, $link, $taxId, $taxkey]) {
        $values[] = "(:trans_id, :c{$k}, :a{$k}, :transdate, :transdate, :source, :memo, :l{$k}, :t{$k}, :k{$k})";
        $params["c{$k}"] = $chartId; $params["a{$k}"] = $amt; $params["l{$k}"] = $link;
        $params["t{$k}"] = $taxId;   $params["k{$k}"] = $taxkey;
    }
    $inserted = $db->getAll(
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, tax_id, taxkey)
         VALUES " . implode(', ', $values) . "
         RETURNING acc_trans_id, chart_id",
        $params
    ) ?: [];

    // Alle Beine mit dem Umsatz verknüpfen (gl_id: kivitendo-Konvention für Dialogbuchungen)
    foreach ($inserted as $leg) {
        $db->execute(
            "INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, gl_id) VALUES (:bt, :at, :gl)",
            ['bt' => $btId, 'at' => intval($leg['acc_trans_id']), 'gl' => $glId]
        );
    }
    _finalizeBankTransactionBooking($db, $btId);

    // Umbuchung auf ein eigenes Konto: Gegenumsatz dort mitbuchen (nur verknüpfen,
    // die Buchung Bank A ↔ Bank B steht ja schon). Kommt er erst später, erkennt
    // ihn getChartBookingOptions als vorhandenes Bankbein.
    $counterpartBooked = _cb_linkCounterpart($db, $btId, $glId, $inserted, $summary, $bt, $counterSign);

    // Erkennung lernt mit: benutzte Vorlage zählt hoch
    $tpl = trim($data['template_key'] ?? '');
    if ($tpl !== '') {
        $db->execute("UPDATE bank_booking_templates SET hit_count = hit_count + 1, mtime = now() WHERE key = :k", ['k' => $tpl]);
    }

    resultInfo(true, 'Umsatz gebucht', [
        'gl_id'               => $glId,
        'beleg'               => $beleg,
        'gross'               => $gross,
        'lines'               => $summary,
        'counterpart_booked'  => $counterpartBooked,
        'target_account'      => $targetAccount ? $targetAccount['name'] : null,
    ]);
}

/**
 * Gegenumsatz einer Umbuchung auf dem Zielkonto verknüpfen und als gebucht
 * markieren (gleicher Betrag umgekehrt, ±5 Tage, noch offen, noch nicht an
 * dieser Buchung). Gemeinsam für Buchen und Ändern.
 *
 * @param array $inserted eingefügte Beine [{acc_trans_id, chart_id}]
 * @param array $summary  Positionen [{chart_id, gross, …}]
 * @return int|null verknüpfter Gegenumsatz
 */
function _cb_linkCounterpart($db, $btId, $glId, array $inserted, array $summary, array $bt, $counterSign) {
    $counterpartBooked = null;
    $ownCharts = $db->getAll(
        "SELECT ba.id AS bank_account_id, ba.chart_id, ba.name FROM bank_accounts ba WHERE ba.id <> :own",
        ['own' => intval($bt['local_bank_account_id'])]
    ) ?: [];
    $transdate = $bt['transdate'];
    foreach ($summary as $line) {
        foreach ($ownCharts as $oc) {
            if (intval($oc['chart_id']) !== intval($line['chart_id'])) continue;
            $counterLeg = null;
            foreach ($inserted as $leg) if (intval($leg['chart_id']) === intval($oc['chart_id'])) $counterLeg = intval($leg['acc_trans_id']);
            $other = $db->getOne(<<<SQL
                SELECT b2.id FROM bank_transactions b2
                LEFT JOIN bank_transactions_ext e2 ON e2.bank_transaction_id = b2.id
                WHERE b2.local_bank_account_id = :acc AND b2.id <> :bt
                  AND abs(b2.amount - :want) < 0.005
                  AND b2.transdate BETWEEN :d::date - 5 AND :d2::date + 5
                  AND COALESCE(e2.match_status, 'unmatched') IN ('unmatched', 'matched')
                  AND NOT EXISTS (SELECT 1 FROM bank_transaction_acc_trans x WHERE x.bank_transaction_id = b2.id AND x.gl_id = :gl)
                ORDER BY abs(b2.transdate - :d3::date), b2.id LIMIT 1
            SQL, ['acc' => $oc['bank_account_id'], 'bt' => $btId, 'want' => -$counterSign * $line['gross'],
                  'd' => $transdate, 'd2' => $transdate, 'd3' => $transdate, 'gl' => $glId]);
            if ($other && $counterLeg) {
                $db->execute(
                    "INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, gl_id) VALUES (:bt, :at, :gl)",
                    ['bt' => intval($other['id']), 'at' => $counterLeg, 'gl' => $glId]
                );
                _finalizeBankTransactionBooking($db, intval($other['id']));
                $counterpartBooked = intval($other['id']);
            }
        }
    }
    return $counterpartBooked;
}

/**
 * Bankumsatz mit einem vorhandenen Bankbein einer Dialogbuchung verknüpfen —
 * die Gegenseite einer Umbuchung, die vom anderen Konto aus gebucht wurde,
 * oder eine Bareinzahlung, die in der Kasse schon gegen die Bank gebucht ist.
 * Es entsteht keine neue Buchung; der Umsatz gilt danach als gebucht.
 *
 * @param int $data['bank_transaction_id'] Bankumsatz
 * @param int $data['acc_trans_id']        Bankbein (aus getChartBookingOptions.existing_leg)
 * @testdata {"bank_transaction_id": 1, "acc_trans_id": 1}
 */
function linkTransactionToGl($data) {
    $db   = DbhCompany::begin();
    $btId = intval($data['bank_transaction_id'] ?? 0);
    $atId = intval($data['acc_trans_id'] ?? 0);
    if ($btId <= 0 || $atId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID und Buchungsbein sind Pflicht'); return; }

    $bt = _cb_loadTransaction($db, $btId);
    if (!$bt) { resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden'); return; }
    if ($bt['match_status'] === 'booked') { resultInfo(false, 'ALREADY_BOOKED', 'Umsatz ist bereits gebucht'); return; }

    $leg = $db->getOne(<<<SQL
        SELECT at.acc_trans_id, at.trans_id, at.chart_id, at.amount,
               EXISTS (SELECT 1 FROM gl WHERE gl.id = at.trans_id) AS is_gl,
               EXISTS (SELECT 1 FROM bank_transaction_acc_trans m
                       JOIN bank_transactions b3 ON b3.id = m.bank_transaction_id
                       WHERE m.acc_trans_id = at.acc_trans_id AND b3.local_bank_account_id = :acc) AS linked
        FROM acc_trans at WHERE at.acc_trans_id = :id
    SQL, ['id' => $atId, 'acc' => intval($bt['local_bank_account_id'])]);
    if (!$leg)                                          { resultInfo(false, 'NOT_FOUND', 'Buchungsbein nicht gefunden'); return; }
    if (empty($leg['is_gl']))                           { resultInfo(false, 'VALIDATION_ERROR', 'Nur Dialogbuchungen (gl) können verknüpft werden'); return; }
    if (!empty($leg['linked']))                         { resultInfo(false, 'ALREADY_LINKED', 'Dieses Buchungsbein ist bereits einem Umsatz zugeordnet'); return; }
    if (intval($leg['chart_id']) !== intval($bt['chart_id'])) { resultInfo(false, 'VALIDATION_ERROR', 'Das Buchungsbein liegt nicht auf dem Bankkonto dieses Umsatzes'); return; }
    if (abs(floatval($leg['amount']) + floatval($bt['amount'])) > 0.005) { resultInfo(false, 'VALIDATION_ERROR', 'Betrag des Buchungsbeins passt nicht zum Umsatz'); return; }

    $db->execute(
        "INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, gl_id) VALUES (:bt, :at, :gl)",
        ['bt' => $btId, 'at' => $atId, 'gl' => intval($leg['trans_id'])]
    );
    _finalizeBankTransactionBooking($db, $btId);

    resultInfo(true, 'Umsatz mit vorhandener Buchung verknüpft', ['gl_id' => intval($leg['trans_id'])]);
}
