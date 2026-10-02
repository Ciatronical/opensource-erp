<?php
// backend/api/banking/matching.php

/**
 * Offene Belege laden fuer Zuordnung (AR + AP). Gutschriften (negativer
 * Betrag) sind enthalten — sie werden in Sammelabbuchungen mit den Rechnungen
 * desselben Lieferanten verrechnet und muessen daher auswaehlbar sein.
 *
 * @param string $data['type']   ar|ap|all (default: all)
 * @param string $data['search'] Suchbegriff (optional, sucht in Rechnungsnr + Kundenname)
 * @testdata {"type": "all"}
 */
function getOpenInvoicesForMatching($data) {
    // Die Abfragen liefern genau eine Zeile mit einem json_agg-Feld. Mit getAll
    // (Liste von Zeilen) griff $result['invoices'] ins Leere, sodass die
    // Funktion immer eine leere Liste zurueckgab.
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

        $arResult = $db->getOne(<<<SQL
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
                    round((ar.amount - ar.paid)::numeric, 2) as open_amount,
                    (ar.amount < 0) as is_credit_note,
                    c.name as customer_name,
                    c.name as contact_name,
                    c.iban as customer_iban,
                    c.id as customer_id
                FROM ar
                JOIN customer c ON c.id = ar.customer_id
                WHERE abs(ar.amount - ar.paid) > 0.005
                  AND ar.storno IS NOT TRUE
                  {$arSearch}
                ORDER BY ar.transdate DESC
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

        $apResult = $db->getOne(<<<SQL
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
                    round((ap.amount - ap.paid)::numeric, 2) as open_amount,
                    (ap.amount < 0) as is_credit_note,
                    v.name as vendor_name,
                    v.name as contact_name,
                    v.iban as vendor_iban,
                    v.id as vendor_id
                FROM ap
                JOIN vendor v ON v.id = ap.vendor_id
                WHERE abs(ap.amount - ap.paid) > 0.005
                  AND ap.storno IS NOT TRUE
                  {$apSearch}
                ORDER BY ap.transdate DESC
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

    // Selbstheilung: Umsätze, die als 'matched' markiert sind, zu denen aber
    // keine Zuordnungszeile (mehr) existiert, hängen fest — der Auto-Match
    // sieht nur 'unmatched', und das Buchen scheitert an der fehlenden
    // Zuordnung. Vor jedem Lauf zurück auf 'unmatched', damit sie wieder
    // mitlaufen.
    $db->execute(<<<SQL
        UPDATE bank_transactions_ext bte
        SET match_status = 'unmatched'
        FROM bank_transactions bt
        WHERE bt.id = bte.bank_transaction_id
          AND bt.local_bank_account_id = :bank_account_id
          AND bte.match_status = 'matched'
          AND NOT EXISTS (
              SELECT 1 FROM bank_transaction_matches m
              WHERE m.bank_transaction_id = bt.id
          )
    SQL, ['bank_account_id' => $bankAccountId]);

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
        "SELECT bt.id, bt.amount, COALESCE(bte.match_status, 'unmatched') AS match_status FROM bank_transactions bt LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id WHERE bt.id = :id",
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

    // Mapping persistieren (UPSERT — User darf Zuordnung aendern bevor gebucht wird)
    $db->execute(<<<SQL
        INSERT INTO bank_transaction_matches (bank_transaction_id, target_type, target_id, matched_by, matched_at)
        VALUES (:bt_id, :target_type, :target_id, :employee_id, now())
        ON CONFLICT (bank_transaction_id) DO UPDATE
        SET target_type = EXCLUDED.target_type,
            target_id   = EXCLUDED.target_id,
            matched_by  = EXCLUDED.matched_by,
            matched_at  = now()
    SQL, [
        'bt_id'       => $btId,
        'target_type' => $targetType,
        'target_id'   => $targetId,
        'employee_id' => $data['employee_id'] ?? null,
    ]);

    // Status auf matched setzen
    $db->execute(
        "INSERT INTO bank_transactions_ext (bank_transaction_id, match_status) VALUES (:id, 'matched') ON CONFLICT (bank_transaction_id) DO UPDATE SET match_status = EXCLUDED.match_status",
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
        writeLog('bookMatchedTransactions: fehlende Parameter transaction_ids=' . json_encode($transactionIds) . ' bank_account_id=' . $bankAccountId, true, DLOG_ERR);
        resultInfo(false, 'VALIDATION_ERROR', 'Keine Umsätze zum Buchen ausgewählt');
        return;
    }

    // Bankkonto-Chart + chart.link laden (chart_link wird in acc_trans gespiegelt,
    // damit kivitendo's Erkennung von Zahlungs-Buchungen via 'AR_paid' / 'AP_paid'
    // funktioniert).
    $bankAccount = $db->getOne(<<<SQL
        SELECT ba.chart_id, ch.link AS chart_link
        FROM bank_accounts ba
        JOIN chart ch ON ch.id = ba.chart_id
        WHERE ba.id = :id
    SQL, ['id' => $bankAccountId]);

    if (!$bankAccount) {
        resultInfo(false, 'NOT_FOUND', 'Bankkonto nicht gefunden');
        return;
    }

    $bookedCount = 0;
    $errors = [];

    foreach ($transactionIds as $btId) {
        $btId = intval($btId);

        $bt = $db->getOne(
            "SELECT bt.id, bt.amount, COALESCE(bte.match_status, 'unmatched') AS match_status, bt.transdate FROM bank_transactions bt LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id WHERE bt.id = :id",
            ['id' => $btId]
        );

        if (!$bt || $bt['match_status'] !== 'matched') {
            $errors[] = "Umsatz {$btId}: nicht zugeordnet oder nicht gefunden";
            continue;
        }

        // Pre-Booking-Mapping holen — sagt uns welche AR/AP zugeordnet wurde.
        $mapping = $db->getOne(<<<SQL
            SELECT target_type, target_id
            FROM bank_transaction_matches
            WHERE bank_transaction_id = :id
        SQL, ['id' => $btId]);

        if (!$mapping) {
            $errors[] = "Umsatz {$btId}: keine Zuordnung gefunden";
            continue;
        }

        $res = _bookBankPaymentAgainstInvoice($db, $bt, $mapping['target_type'], intval($mapping['target_id']), $bankAccount);
        if (!$res['ok']) { $errors[] = "Umsatz {$btId}: " . $res['error']; continue; }
        $bookedCount++;
    }

    resultInfo(true, "Gebucht", [
        'booked_count' => $bookedCount,
        'errors' => $errors
    ]);
}

/**
 * Bucht EINE Bankzahlung gegen einen bestehenden AR/AP-Beleg: schreibt die zwei
 * acc_trans-Beine (Forderungs-/Verbindlichkeitskonto + Bankkonto), erhöht
 * ar.paid/ap.paid, verknüpft beide Beine in bank_transaction_acc_trans, räumt
 * ein evtl. Vor-Mapping ab und markiert den Umsatz als gebucht. Gemeinsamer Kern
 * von bookMatchedTransactions, createApFromBankTransaction und der
 * Sammelbuchung (bookTransactionMultipleInvoices).
 *
 * Gutschriften tragen in kivitendo einen negativen Betrag; ihr Anteil ist dann
 * negativ und die Buchungsbeine drehen sich automatisch um (Verbindlichkeit
 * Haben / Bank Soll). So verrechnet sich eine Gutschrift innerhalb einer
 * Sammelabbuchung sauber gegen die Rechnungen desselben Lieferanten.
 *
 * @param array $bt          {id, amount, transdate}
 * @param array $bankAccount {chart_id, chart_link}
 * @param array $opts        amount:   Anteil dieses Belegs (vorzeichenbehaftet wie
 *                                     der offene Betrag); Default = ganzer Umsatz
 *                           beleg:    gemeinsame Belegnummer (Sammelbuchung)
 *                           memo:     gemeinsamer Memo-Text (Sammelbuchung)
 *                           finalize: Mapping löschen + Umsatz auf 'booked'
 *                                     setzen (Default true; Sammelbuchung macht
 *                                     das einmal am Ende)
 * @return array {ok:bool, error?:string}
 */
function _bookBankPaymentAgainstInvoice($db, array $bt, $targetType, $targetId, array $bankAccount, array $opts = []) {
    $btId       = intval($bt['id']);
    $isAr       = $targetType === 'ar';
    $invTable   = $isAr ? 'ar' : 'ap';
    $linkPrefix = $isAr ? 'AR' : 'AP';

    // Forderungs-/Verbindlichkeitskonto der Erstbuchung ermitteln (gegen genau
    // dieses Konto wird die Zahlung gebucht — kontenrahmen-kompatibel).
    // WICHTIG: Das Kontroll-Konto trägt link EXAKT 'AP' bzw. 'AR' (als Token in
    // einer ':'-getrennten Liste). Ein blosses LIKE '%AP%' würde auch 'AP_amount'
    // (Aufwand) und 'AP_tax' (Vorsteuer) treffen — je nach Buchungsreihenfolge
    // landete die Zahlung dann auf dem falschen Konto. Der Token-Regex
    // '(^|:)AP($|:)' matcht 'AP' und 'AP' in 'X:AP', aber NICHT 'AP_amount'/'AP_paid'.
    $counterChart = $db->getOne(<<<SQL
        SELECT chart_id
        FROM acc_trans
        WHERE trans_id = :tid
          AND chart_link ~ :token_link
        ORDER BY acc_trans_id ASC
        LIMIT 1
    SQL, [
        'tid'        => $targetId,
        'token_link' => '(^|:)' . $linkPrefix . '($|:)',
    ]);

    // Fallback fuer Rechnungen ohne GL-Erstbuchung: Standard-AR/AP-Konto.
    if (!$counterChart) {
        $counterChart = $db->getOne(
            "SELECT id AS chart_id FROM chart WHERE link = :lnk ORDER BY accno ASC LIMIT 1",
            ['lnk' => $linkPrefix]
        );
        writeLog("_bookBankPaymentAgainstInvoice: {$invTable} #{$targetId} ohne acc_trans — Fallback-Konto " . ($counterChart['chart_id'] ?? 'KEINS'), true, DLOG_INF);
    }
    if (!$counterChart) {
        writeLog("_bookBankPaymentAgainstInvoice: Umsatz #{$btId} — kein {$linkPrefix}-Konto ermittelbar", true, DLOG_ERR);
        return ['ok' => false, 'error' => 'Forderungs-/Verbindlichkeitskonto der Rechnung nicht ermittelbar'];
    }

    $invoice = $db->getOne("SELECT id, amount, paid, transdate FROM {$invTable} WHERE id = :id", ['id' => $targetId]);
    if (!$invoice) return ['ok' => false, 'error' => "{$invTable} #{$targetId} nicht gefunden"];

    // ── Plausibilitäts-Sperren (letzte Instanz vor dem Hauptbuch) ───────────
    // 1. Eine Zahlung kann nicht vor der Rechnung liegen, die sie ausgleicht.
    //    Ohne diese Sperre hat der Auto-Match Zahlungseingänge auf Rechnungen
    //    gebucht, die es zum Zahlungszeitpunkt noch gar nicht gab (bis zu 75
    //    Tage Vorlauf). Anzahlungen sind bewusst NICHT erfasst — die gehören
    //    als eigener Geschäftsvorfall gebucht, nicht als Rechnungsausgleich.
    if (strtotime($bt['transdate']) < strtotime($invoice['transdate'])) {
        return ['ok' => false, 'error' => sprintf(
            'Zahlung vom %s liegt vor dem Rechnungsdatum %s — Zuordnung unplausibel',
            date('d.m.Y', strtotime($bt['transdate'])),
            date('d.m.Y', strtotime($invoice['transdate']))
        )];
    }

    // Anteil dieses Belegs: in der Sammelbuchung vorgegeben, sonst der ganze
    // Umsatz — mit dem Vorzeichen des offenen Betrags (Gutschrift = negativ).
    $openAmount = round(floatval($invoice['amount']) - floatval($invoice['paid']), 2);
    $share      = isset($opts['amount'])
        ? round(floatval($opts['amount']), 2)
        : ($openAmount < 0 ? -1 : 1) * abs(floatval($bt['amount']));

    // 2. Es darf nie mehr gebucht werden als offen ist. Sammelzahlungen über
    //    mehrere Belege gehören durch bookTransactionMultipleInvoices,
    //    nicht komplett auf die erstbeste Einzelrechnung.
    if (abs($share) > abs($openAmount) + 0.01) {
        return ['ok' => false, 'error' => sprintf(
            'Betrag %s übersteigt den offenen Rechnungsbetrag %s — evtl. Sammelzahlung über mehrere Rechnungen',
            number_format(abs($share), 2, ',', '.'),
            number_format(abs($openAmount), 2, ',', '.')
        )];
    }

    // Vorzeichen richtungsabhängig (Soll = negativ, Haben = positiv):
    //   AR (Kunde zahlt uns): Forderungskonto +  (klärt die Forderung, Haben),
    //                         Bank −            (Geld kommt rein, Soll).
    //   AP (wir zahlen):      Verbindlichkeit − (klärt die Schuld, Soll),
    //                         Bank +            (Geld geht raus, Haben) — wie bookApAsCash.
    // Ein fixes AR-Vorzeichen (wie zuvor) verbuchte AP-Zahlungen spiegelverkehrt.
    // Bei negativem Anteil (Gutschrift) drehen sich beide Beine um.
    $counterAmount = $isAr ? $share : -$share;
    $bankAmount    = -$counterAmount;

    // 2b. Richtungsprüfung bei Einzelbuchung: ein Geldeingang kann keine
    //     Eingangsrechnung bezahlen und ein Geldausgang keine Ausgangsrechnung.
    //     In der Sammelbuchung prüft der Aufrufer stattdessen die Gesamtsumme
    //     (dort darf eine Gutschrift gegenläufig sein).
    if (!isset($opts['amount']) && (($bankAmount < 0) !== (floatval($bt['amount']) > 0))) {
        return ['ok' => false, 'error' => 'Zahlungsrichtung passt nicht zum Beleg (Geldeingang ↔ Ausgangsrechnung, Geldausgang ↔ Eingangsrechnung)'];
    }

    // 3. Doppelbuchung: Wurde dieselbe Zahlung schon einmal auf diese Rechnung
    //    gebucht (z. B. als kivitendo-Altbestand ohne source), darf sie nicht
    //    ein zweites Mal ins Hauptbuch. Kriterium: gleiches Datum, gleicher
    //    Betrag, Zahlungs-Bein (AR_paid/AP_paid) auf derselben Rechnung.
    $dupe = $db->getOne(<<<SQL
        SELECT at.acc_trans_id
        FROM acc_trans at
        JOIN chart ch ON ch.id = at.chart_id
        WHERE at.trans_id = :tid
          AND at.transdate = :tdate
          AND ch.link ~ '(^|:)(AR_paid|AP_paid)($|:)'
          AND ABS(ABS(at.amount) - :amt) < 0.01
        LIMIT 1
    SQL, ['tid' => $targetId, 'tdate' => $bt['transdate'], 'amt' => abs($share)]);
    if ($dupe) {
        return ['ok' => false, 'error' => sprintf(
            'Auf diese Rechnung ist am %s bereits eine Zahlung über %s gebucht — Doppelbuchung verhindert',
            date('d.m.Y', strtotime($bt['transdate'])),
            number_format(abs($share), 2, ',', '.')
        )];
    }

    $counterLink    = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $counterChart['chart_id']]);
    $bankChartLink  = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $bankAccount['chart_id']]);
    $counterLinkVal = $counterLink['link'] ?? $linkPrefix;
    $bankLinkVal    = $bankChartLink['link'] ?? 'AR_paid:AP_paid';

    $beleg    = $opts['beleg'] ?? nextBelegnummer($db, $bankAccount['chart_id'], $bt['transdate']);
    $bankMemo = $opts['memo']  ?? "Beleg {$beleg} · Bankabstimmung Umsatz #{$btId}";

    $bankEntry = $db->getOne(<<<SQL
        INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
        VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, 0, 0, :chart_link)
        RETURNING acc_trans_id
    SQL, [
        'trans_id' => $targetId, 'chart_id' => $counterChart['chart_id'], 'amount' => $counterAmount,
        'transdate' => $bt['transdate'], 'source' => (string)$beleg, 'memo' => $bankMemo, 'chart_link' => $counterLinkVal,
    ]);

    $bankLeg = $db->getOne(<<<SQL
        INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
        VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, 0, 0, :chart_link)
        RETURNING acc_trans_id
    SQL, [
        'trans_id' => $targetId, 'chart_id' => $bankAccount['chart_id'], 'amount' => $bankAmount,
        'transdate' => $bt['transdate'], 'source' => (string)$beleg, 'memo' => $bankMemo, 'chart_link' => $bankLinkVal,
    ]);

    $db->execute("UPDATE {$invTable} SET paid = COALESCE(paid, 0) + :inc WHERE id = :id",
        ['inc' => $share, 'id' => $targetId]);

    $arIdVal = $isAr ? $targetId : null;
    $apIdVal = $isAr ? null : $targetId;
    foreach ([$bankEntry['acc_trans_id'], $bankLeg['acc_trans_id']] as $linkedAccTransId) {
        $db->execute(<<<SQL
            INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, ar_id, ap_id)
            VALUES (:bt_id, :acc_trans_id, :ar_id, :ap_id)
        SQL, ['bt_id' => $btId, 'acc_trans_id' => $linkedAccTransId, 'ar_id' => $arIdVal, 'ap_id' => $apIdVal]);
    }

    if ($opts['finalize'] ?? true) {
        _finalizeBankTransactionBooking($db, $btId);
    }

    return ['ok' => true];
}

/**
 * Schlussschritt jeder Bankbuchung: Vor-Mapping entfernen, Umsatz als
 * abgeglichen (cleared) und 'booked' markieren.
 */
function _finalizeBankTransactionBooking($db, $btId) {
    $db->execute("DELETE FROM bank_transaction_matches WHERE bank_transaction_id = :id", ['id' => $btId]);
    $db->execute("WITH u AS (UPDATE bank_transactions SET cleared = true WHERE id = :id) INSERT INTO bank_transactions_ext (bank_transaction_id, match_status) VALUES (:id2, 'booked') ON CONFLICT (bank_transaction_id) DO UPDATE SET match_status = EXCLUDED.match_status", ['id' => $btId, 'id2' => $btId]);
}

/**
 * Aufwandskonto + Steuersatz für einen Lieferanten vorschlagen.
 *
 * Bisher blieb das Feld "Aufwandskonto" im Dialog "Als Eingangsrechnung buchen"
 * leer — es gab schlicht keine Vorbelegung. Die Reihenfolge:
 *   1. gelernte Regel aus accounting_account_rules (wird beim Buchen gepflegt)
 *   2. das zuletzt bei diesem Lieferanten benutzte Aufwandskonto
 *   3. der Hausstandard aus defaults_oserp.accounting_default_debit_account
 * Der Steuersatz kommt aus der letzten Eingangsrechnung des Lieferanten, sonst
 * aus defaults_oserp.accounting_default_tax_rate.
 *
 * @param int $data['vendor_id'] Lieferant
 * @testdata {"vendor_id": 25942}
 */
function suggestExpenseAccount($data) {
    $db = DbhCompany::begin();

    $vendorId = intval($data['vendor_id'] ?? 0);
    if ($vendorId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Kein Lieferant angegeben');
        return;
    }

    $row = $db->getOne(<<<SQL
        WITH kandidaten AS (
            -- 1. gelernte Regel zum Lieferanten
            (SELECT ch.id            AS chart_id,
                    ch.accno         AS accno,
                    ch.description   AS description,
                    'rule'::text     AS quelle,
                    1                AS prio
             FROM accounting_account_rules r
             JOIN chart ch ON ch.accno = r.debit_account
             WHERE r.vendor_id = :vendor_id
               AND r.active
               AND r.match_pattern IS NULL
             ORDER BY r.priority ASC, r.hit_count DESC
             LIMIT 1)

            UNION ALL

            -- 2. zuletzt bei diesem Lieferanten bebuchtes Aufwandskonto
            (SELECT ch.id, ch.accno, ch.description, 'history'::text, 2
             FROM ap
             JOIN acc_trans at ON at.trans_id = ap.id
             JOIN chart ch ON ch.id = at.chart_id
                          AND ch.link ~ '(^|:)AP_amount($|:)'
             WHERE ap.vendor_id = :vendor_id
             ORDER BY ap.transdate DESC, at.acc_trans_id DESC
             LIMIT 1)

            UNION ALL

            -- 3. Hausstandard
            (SELECT ch.id, ch.accno, ch.description, 'default'::text, 3
             FROM defaults_oserp d
             JOIN chart ch ON ch.accno = d.value
             WHERE d.key = 'accounting_default_debit_account'
               AND coalesce(d.value, '') <> ''
             LIMIT 1)
        )
        SELECT k.chart_id,
               k.accno,
               k.description,
               k.quelle,
               COALESCE(
                   -- Steuersatz der letzten Eingangsrechnung des Lieferanten
                   (SELECT round((ap.amount - ap.netamount) / nullif(ap.netamount, 0) * 100)
                    FROM ap
                    WHERE ap.vendor_id = :vendor_id AND ap.netamount > 0
                    ORDER BY ap.transdate DESC, ap.id DESC
                    LIMIT 1),
                   (SELECT nullif(value, '')::numeric FROM defaults_oserp
                    WHERE key = 'accounting_default_tax_rate'),
                   19
               ) AS rate
        FROM kandidaten k
        ORDER BY k.prio
        LIMIT 1
    SQL, ['vendor_id' => $vendorId]);

    resultInfo(true, '', [
        'account' => $row ? [
            'id'          => intval($row['chart_id']),
            'accno'       => $row['accno'],
            'description' => $row['description'],
            'label'       => $row['accno'] . ' ' . $row['description'],
            'source'      => $row['quelle'],
        ] : null,
        'rate' => $row ? floatval($row['rate']) : 19.0,
    ]);
}

/**
 * Aus einem (noch offenen) Bankumsatz direkt eine Eingangsrechnung (ap) anlegen
 * UND sie in einem Rutsch mit diesem Umsatz als bezahlt verbuchen. Für die
 * typische Buchhalter-Arbeitsweise „Kontoauszug durchgehen und jede
 * Lieferantenzahlung wegbuchen", ohne dass vorher eine Rechnung erfasst wurde.
 *
 * Der Bruttobetrag kommt aus dem Bankumsatz; Netto/Steuer werden aus dem
 * gewählten Steuersatz abgeleitet. Aufwandskonto per chart_id oder Kontonummer.
 *
 * @param int    $data['bank_transaction_id'] Bankumsatz (Pflicht)
 * @param int    $data['bank_account_id']     Bankkonto (optional; sonst aus dem Umsatz)
 * @param int    $data['vendor_id']           Lieferant (Pflicht)
 * @param int    $data['expense_chart_id']    Aufwandskonto-ID (oder debit_account)
 * @param string $data['debit_account']       Aufwandskonto-Nummer (Alternative)
 * @param float  $data['rate']                Steuersatz 19/7/0 (Default 19)
 * @param string $data['invnumber']           Rechnungsnummer (optional)
 * @param string $data['notes']               Buchungstext (optional)
 * @param array  $data['document']            Beleg {filename, mime_type, file_base64} (optional)
 * @param int    $data['document_id']         bereits hochgeladener Beleg (Alternative)
 * @testdata {"bank_transaction_id": 1, "vendor_id": 1000, "debit_account": "5400", "rate": 19}
 */
function createApFromBankTransaction($data) {
    require_once __DIR__ . '/../accounting/incoming_invoice_posting.php';
    $db = DbhCompany::begin();

    $btId     = intval($data['bank_transaction_id'] ?? 0);
    $vendorId = intval($data['vendor_id'] ?? 0);
    if ($btId <= 0)     { resultInfo(false, 'VALIDATION_ERROR', 'Kein Bankumsatz angegeben'); return; }
    if ($vendorId <= 0) { resultInfo(false, 'VENDOR_REQUIRED', 'Kein Lieferant gewählt'); return; }

    // Aufwandskonto: id direkt oder aus Kontonummer
    $expenseChartId = intval($data['expense_chart_id'] ?? 0);
    if ($expenseChartId <= 0 && !empty($data['debit_account'])) {
        $row = $db->getOne("SELECT id FROM chart WHERE accno = :a", [':a' => trim($data['debit_account'])]);
        if (!$row) { resultInfo(false, 'ACCOUNT_REQUIRED', 'Aufwandskonto (' . $data['debit_account'] . ') nicht im Kontenrahmen'); return; }
        $expenseChartId = intval($row['id']);
    }
    if ($expenseChartId <= 0) { resultInfo(false, 'ACCOUNT_REQUIRED', 'Kein Aufwandskonto gewählt'); return; }

    $bt = $db->getOne(
        "SELECT bt.id, bt.amount, bt.transdate, bt.local_bank_account_id, COALESCE(bte.match_status, 'unmatched') AS match_status, bt.remote_name, bt.purpose
         FROM bank_transactions bt LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id WHERE bt.id = :id",
        ['id' => $btId]
    );
    if (!$bt) { resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden'); return; }
    if ($bt['match_status'] === 'booked') { resultInfo(false, 'ALREADY_BOOKED', 'Umsatz ist bereits gebucht'); return; }

    $bankAccountId = intval($data['bank_account_id'] ?? 0) ?: intval($bt['local_bank_account_id']);
    $bankAccount = $db->getOne(
        "SELECT ba.chart_id, ch.link AS chart_link
         FROM bank_accounts ba JOIN chart ch ON ch.id = ba.chart_id WHERE ba.id = :id",
        ['id' => $bankAccountId]
    );
    if (!$bankAccount) { resultInfo(false, 'NOT_FOUND', 'Bankkonto nicht gefunden'); return; }

    // Brutto aus dem Umsatz, Netto/Steuer aus dem Satz ableiten
    $gross   = round(abs(floatval($bt['amount'])), 2);
    if ($gross <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Bankumsatz ohne Betrag'); return; }
    $ratePct = floatval($data['rate'] ?? 19); if ($ratePct > 0 && $ratePct < 1) $ratePct *= 100;
    $net     = $ratePct > 0 ? round($gross / (1 + $ratePct / 100), 2) : $gross;
    $tax     = round($gross - $net, 2);

    $invnumber = trim($data['invnumber'] ?? '') ?: ('BANK-' . $btId);
    $notes     = trim($data['notes'] ?? '') ?: trim(($bt['remote_name'] ?? '') . ' · ' . ($bt['purpose'] ?? ''), ' ·');

    // 1) echte Eingangsrechnung anlegen
    try {
        $apId = _iv_postAp($db, [
            'vendor_id'        => $vendorId,
            'expense_chart_id' => $expenseChartId,
            'invnumber'        => $invnumber,
            'transdate'        => $bt['transdate'],
            'duedate'          => $bt['transdate'],
            'net'              => $net,
            'tax'              => $tax,
            'gross'            => $gross,
            'rate'             => $data['rate'] ?? 19,
            'notes'            => $notes ?: null,
        ]);
    } catch (ApiError $e) {
        resultInfo(false, $e->getMessage());
        return;
    }

    // 2) sofort mit diesem Bankumsatz als bezahlt verbuchen (gleiche Logik wie Bankabstimmung)
    $res = _bookBankPaymentAgainstInvoice($db, $bt, 'ap', intval($apId), $bankAccount);
    if (!$res['ok']) { resultInfo(false, 'BOOK_FAILED', $res['error']); return; }

    // 3) Beleg an die Eingangsrechnung hängen. Ohne Beleg keine ordnungsgemäße
    //    Buchung — das Ergebnis geht deshalb zurück an die Oberfläche, damit
    //    fehlende Belege sichtbar bleiben.
    $documentId = intval($data['document_id'] ?? 0);
    $docWarning = null;
    if ($documentId <= 0 && !empty($data['document']['file_base64'])) {
        $stored = storeAccountingDocument(
            $db,
            $data['document']['filename']  ?? 'beleg.pdf',
            $data['document']['mime_type'] ?? 'application/octet-stream',
            $data['document']['file_base64'],
            $vendorId,
            mitarbeiterId($data)
        );
        if ($stored['ok']) {
            $documentId = $stored['document_id'];
        } else {
            // Die Buchung steht bereits — ein Beleg-Fehler darf sie nicht kippen.
            $docWarning = $stored['error'];
            writeLog("createApFromBankTransaction: Beleg zu ap #{$apId} nicht gespeichert — " . $stored['error'], true, DLOG_ERR);
        }
    }
    if ($documentId > 0) {
        $db->execute(
            "UPDATE accounting_documents
                SET ap_id = :ap_id, vendor_id = COALESCE(vendor_id, :vendor_id),
                    status = 'booked', mtime = now()
              WHERE id = :id",
            ['ap_id' => $apId, 'vendor_id' => $vendorId, 'id' => $documentId]
        );
    }

    // 4) Aufwandskonto für diesen Lieferanten merken, damit der Dialog es beim
    //    nächsten Mal vorbelegt (siehe suggestExpenseAccount).
    $accno = $db->getOne("SELECT accno FROM chart WHERE id = :id", ['id' => $expenseChartId]);
    if ($accno) {
        $db->execute(<<<SQL
            INSERT INTO accounting_account_rules
                (vendor_id, debit_account, credit_account, priority, active, hit_count)
            SELECT :vendor_id, :debit, :credit, 100, true, 0
            WHERE NOT EXISTS (
                SELECT 1 FROM accounting_account_rules
                WHERE vendor_id = :vendor_id AND match_pattern IS NULL
            )
        SQL, ['vendor_id' => $vendorId, 'debit' => $accno['accno'], 'credit' => '1800']);

        $db->execute(<<<SQL
            UPDATE accounting_account_rules
               SET debit_account = :debit, hit_count = hit_count + 1, active = true, mtime = now()
             WHERE vendor_id = :vendor_id AND match_pattern IS NULL
        SQL, ['vendor_id' => $vendorId, 'debit' => $accno['accno']]);
    }

    resultInfo(true, 'Eingangsrechnung angelegt und bezahlt', [
        'ap_id'       => $apId,
        'vendor_id'   => $vendorId,
        'gross'       => $gross,
        'document_id' => $documentId ?: null,
        'doc_warning' => $docWarning,
    ]);
}

/**
 * Buchung eines Bankumsatzes rueckgaengig machen (Storno der acc_trans-Eintraege)
 *
 * Loescht die acc_trans-Eintraege die durch die Banking-Buchung erzeugt wurden,
 * setzt ar.paid / ap.paid zurueck und stellt den Umsatz auf "unmatched".
 *
 * @param int $data['bank_transaction_id'] Bankumsatz-ID
 * @testdata {"bank_transaction_id": 1}
 */
function unbookTransaction($data) {
    $db = DbhCompany::begin();

    $btId = intval($data['bank_transaction_id'] ?? 0);
    if ($btId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt');
        return;
    }

    $bt = $db->getOne(
        "SELECT bt.id, bt.amount, COALESCE(bte.match_status, 'unmatched') AS match_status FROM bank_transactions bt LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id WHERE bt.id = :id",
        ['id' => $btId]
    );

    if (!$bt) {
        resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden');
        return;
    }

    if ($bt['match_status'] !== 'booked') {
        resultInfo(false, 'NOT_BOOKED', 'Umsatz ist nicht gebucht');
        return;
    }

    // Zu stornierende acc_trans-Eintraege ermitteln — primaer ueber das stabile
    // Mapping bank_transaction_acc_trans (beide Buchungsbeine, unabhaengig vom
    // editierbaren Memo), ergaenzend ueber das Memo (Alt-Buchungen ohne zweites
    // verknuepftes Bein bzw. fehlgeschlagene Buchungen ohne Mapping).
    $memoLike = '%Umsatz #' . $btId;

    // Alt-Buchungen des Bankmoduls haben NUR das Bankbein verknüpft und tragen
    // kein Memo — das Forderungs-/Verbindlichkeitsbein wurde damals nicht in
    // bank_transaction_acc_trans eingetragen. Wird nur das Bankbein gelöscht,
    // bleibt ein unausgeglichener Rest auf 1200/3300 stehen und ar.paid wird
    // nicht zurückgesetzt (das Gegenbein trägt den positiven Betrag). Das
    // Gegenbein wird deshalb über trans_id + Datum + Gegenbetrag nachgezogen.
    $rows = $db->getAll(<<<SQL
        WITH verknuepft AS (
            SELECT at.acc_trans_id, at.trans_id, at.amount, at.transdate, at.chart_id
            FROM acc_trans at
            WHERE at.acc_trans_id IN (
                    SELECT acc_trans_id FROM bank_transaction_acc_trans
                    WHERE bank_transaction_id = :bt_id
                )
                OR at.memo LIKE :memo_like
        ),
        gegenbein AS (
            SELECT DISTINCT ON (v.acc_trans_id)
                   at.acc_trans_id, at.trans_id, at.amount
            FROM verknuepft v
            JOIN chart bc ON bc.id = v.chart_id
                         AND bc.link ~ '(^|:)(AR_paid|AP_paid)($|:)'
            JOIN acc_trans at ON at.trans_id  = v.trans_id
                             AND at.transdate = v.transdate
                             AND at.amount    = -v.amount
                             AND at.acc_trans_id <> v.acc_trans_id
            JOIN chart cc ON cc.id = at.chart_id
                         AND cc.link ~ '(^|:)(AR|AP)($|:)'
            WHERE at.acc_trans_id NOT IN (SELECT acc_trans_id FROM verknuepft)
            ORDER BY v.acc_trans_id, at.acc_trans_id
        )
        SELECT x.acc_trans_id, x.trans_id, x.amount, ch.link AS chart_link
        FROM (
            SELECT acc_trans_id, trans_id, amount, chart_id FROM verknuepft
            UNION
            SELECT g.acc_trans_id, g.trans_id, g.amount, at2.chart_id
            FROM gegenbein g JOIN acc_trans at2 ON at2.acc_trans_id = g.acc_trans_id
        ) x
        JOIN chart ch ON ch.id = x.chart_id
    SQL, ['bt_id' => $btId, 'memo_like' => $memoLike]);

    if (empty($rows)) {
        // Weder Mapping- noch Memo-Eintraege — nur Status (und evtl. verwaistes
        // Mapping) zuruecksetzen.
        $db->execute(
            "DELETE FROM bank_transaction_acc_trans WHERE bank_transaction_id = :id",
            ['id' => $btId]
        );
        $db->execute(
            "WITH u AS (UPDATE bank_transactions SET cleared = false WHERE id = :id) INSERT INTO bank_transactions_ext (bank_transaction_id, match_status) VALUES (:id2, 'unmatched') ON CONFLICT (bank_transaction_id) DO UPDATE SET match_status = EXCLUDED.match_status", ['id' => $btId, 'id2' => $btId]
        );
        resultInfo(true, 'Status zurueckgesetzt (keine acc_trans-Eintraege gefunden)');
        return;
    }

    // Loesch-IDs und je Beleg den gebuchten Zahlanteil (vorzeichenrichtig)
    // sammeln, bevor geloescht wird. Der Anteil steht im Bein auf dem
    // Forderungs-/Verbindlichkeitskonto: AR-Bein = +Anteil, AP-Bein = −Anteil
    // (siehe _bookBankPaymentAgainstInvoice). So werden auch Gutschriften
    // (negativer Anteil) und Teilzahlungen exakt zurueckgenommen. Beine ohne
    // Kontroll-Konto (Altbestand) fallen auf die Summe der positiven Betraege
    // zurueck.
    $ids = [];
    $shareByTrans    = [];   // trans_id => ['ar'|'ap', Anteil]
    $positiveByTrans = [];
    foreach ($rows as $r) {
        $ids[] = intval($r['acc_trans_id']);
        $tid  = intval($r['trans_id']);
        $amt  = floatval($r['amount']);
        $link = (string)($r['chart_link'] ?? '');
        if (preg_match('/(^|:)AR($|:)/', $link)) {
            $shareByTrans[$tid] = ['ar', ($shareByTrans[$tid][1] ?? 0) + $amt];
        } elseif (preg_match('/(^|:)AP($|:)/', $link)) {
            $shareByTrans[$tid] = ['ap', ($shareByTrans[$tid][1] ?? 0) - $amt];
        }
        if ($amt > 0) {
            $positiveByTrans[$tid] = ($positiveByTrans[$tid] ?? 0) + $amt;
        }
    }

    // WICHTIG: Zuerst das FK-referenzierende Mapping loeschen, sonst verletzt
    // das Loeschen der acc_trans-Eintraege die Foreign-Key-Constraint
    // bank_transaction_acc_trans_acc_trans_id_fkey.
    $db->execute(
        "DELETE FROM bank_transaction_acc_trans WHERE bank_transaction_id = :id",
        ['id' => $btId]
    );

    // acc_trans-Eintraege anhand ihrer IDs loeschen (memo-unabhaengig).
    $idPlaceholders = [];
    $idParams = [];
    foreach ($ids as $i => $accTransId) {
        $idPlaceholders[] = ":a{$i}";
        $idParams["a{$i}"] = $accTransId;
    }
    $db->execute(
        "DELETE FROM acc_trans WHERE acc_trans_id IN (" . implode(',', $idPlaceholders) . ")",
        $idParams
    );

    // paid je betroffenem Beleg zuruecknehmen. Die trans_id gehoert entweder zu
    // ar ODER zu ap (gemeinsame id-Sequenz).
    foreach ($shareByTrans as $transId => [$tbl, $share]) {
        if (abs($share) > 0.005) {
            $db->execute(
                "UPDATE {$tbl} SET paid = COALESCE(paid, 0) - :dec WHERE id = :id",
                ['dec' => round($share, 2), 'id' => $transId]
            );
        }
        unset($positiveByTrans[$transId]);
    }
    // Altbestand ohne erkennbares Kontroll-Bein: Summe der positiven Betraege,
    // minimiert auf 0.
    foreach ($positiveByTrans as $transId => $positiveSum) {
        if ($positiveSum > 0) {
            foreach (['ar', 'ap'] as $tbl) {
                $db->execute(
                    "UPDATE {$tbl} SET paid = GREATEST(0, COALESCE(paid, 0) - :dec) WHERE id = :id",
                    ['dec' => $positiveSum, 'id' => $transId]
                );
            }
        }
    }

    // Umsatz-Status zuruecksetzen
    $db->execute(
        "WITH u AS (UPDATE bank_transactions SET cleared = false WHERE id = :id) INSERT INTO bank_transactions_ext (bank_transaction_id, match_status) VALUES (:id2, 'unmatched') ON CONFLICT (bank_transaction_id) DO UPDATE SET match_status = EXCLUDED.match_status", ['id' => $btId, 'id2' => $btId]
    );

    resultInfo(true, 'Buchung rueckgaengig gemacht');
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
        "SELECT bt.id, COALESCE(bte.match_status, 'unmatched') AS match_status FROM bank_transactions bt LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id WHERE bt.id = :id",
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

    // Mapping entfernen + Status zuruecksetzen — beides in einer Aktion,
    // damit die Tabelle nie matched-Status ohne Mapping enthaelt.
    $db->execute(
        "DELETE FROM bank_transaction_matches WHERE bank_transaction_id = :id",
        ['id' => $btId]
    );
    $db->execute(
        "INSERT INTO bank_transactions_ext (bank_transaction_id, match_status) VALUES (:id, 'unmatched') ON CONFLICT (bank_transaction_id) DO UPDATE SET match_status = EXCLUDED.match_status",
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
 * Zuordnungs-Kandidaten für einen Bankumsatz ermitteln.
 *
 * Geldeingang → offene Ausgangsrechnungen (ar/customer), Geldausgang → offene
 * Eingangsrechnungen (ap/vendor); Gutschriften (negativer Betrag) sind dabei,
 * weil sie in Sammelabbuchungen mit verrechnet werden. Strategien absteigend
 * nach Konfidenz, je Beleg nur der beste Treffer. Zusätzlich zwei
 * Sammel-Vorschläge: (a) mehrere Belegnummern im Verwendungszweck, deren
 * Summe den Umsatz ergibt; (b) sonst eine eindeutige Teilsumme aus den offenen
 * Belegen des per IBAN/Name erkannten Kontakts (Subset-Sum).
 *
 * @param int $data['transaction_id'] Bankumsatz-ID
 * @testdata {"transaction_id": 1}
 */
function getMatchCandidatesForTransaction($data) {
    $db = DbhCompany::begin();

    $btId = intval($data['transaction_id'] ?? 0);
    if ($btId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt');
        return;
    }

    $bt = $db->getOne(
        "SELECT bt.id, bt.amount, bt.transdate, bt.remote_name, bt.remote_account_number AS remote_iban, bt.purpose, bt.end_to_end_id, COALESCE(bte.match_status, 'unmatched') AS match_status
         FROM bank_transactions bt LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id WHERE bt.id = :id",
        ['id' => $btId]
    );
    if (!$bt) {
        resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden');
        return;
    }

    $isIncoming = floatval($bt['amount']) > 0;
    // Tabellen-/Kontakt-Platzhalter: identische Strategien für beide Richtungen
    $T   = $isIncoming ? 'ar' : 'ap';
    $C   = $isIncoming ? 'customer' : 'vendor';
    $CID = $isIncoming ? 'customer_id' : 'vendor_id';

    $invnrRegex = "('(^|[^[:alnum:]])' || d.invnumber || '($|[^[:alnum:]])')";
    $nameMatch  = "bt.remote_name IS NOT NULL AND length(trim(bt.remote_name)) >= 3 AND length(c.name) >= 4
                   AND (c.name ILIKE '%' || trim(bt.remote_name) || '%' OR trim(bt.remote_name) ILIKE '%' || c.name || '%')";

    $result = $db->getOne(<<<SQL
        WITH bt AS (
            SELECT id, abs(amount) AS abs_amount, transdate, remote_account_number AS remote_iban, remote_name, purpose, end_to_end_id
            FROM bank_transactions
            WHERE id = :bt_id
        ),
        -- offene Belege (inkl. Gutschriften), nie jünger als der Umsatz
        offen AS (
            SELECT d.id, d.invnumber, d.transdate, d.duedate, d.amount, d.paid,
                   round((d.amount - d.paid)::numeric, 2) AS open_amount,
                   d.{$CID} AS contact_id
            FROM {$T} d, bt
            WHERE abs(d.amount - d.paid) > 0.01
              AND d.storno IS NOT TRUE
              AND d.transdate <= bt.transdate
        )
        SELECT json_agg(row_to_json(top) ORDER BY top.confidence DESC, top.transdate DESC) AS candidates
        FROM (
            SELECT *
            FROM (
                SELECT DISTINCT ON (m.doc_id)
                    '{$T}'::TEXT          AS target_type,
                    d.id                   AS target_id,
                    d.invnumber,
                    d.transdate,
                    d.duedate,
                    d.amount               AS invoice_amount,
                    d.paid,
                    d.open_amount,
                    (d.amount < 0)         AS is_credit_note,
                    c.name                 AS contact_name,
                    c.iban                 AS contact_iban,
                    c.id                   AS contact_id,
                    m.match_type,
                    m.confidence
                FROM (
                    -- 0.99: End-to-End-ID enthaelt Belegnummer (SEPA-Strukturwert)
                    SELECT d.id AS doc_id, 'end_to_end_id'::TEXT AS match_type, 0.99::NUMERIC AS confidence
                    FROM bt
                    JOIN offen d ON length(d.invnumber) >= 4
                        AND bt.end_to_end_id IS NOT NULL
                        AND bt.end_to_end_id ~ {$invnrRegex}

                    UNION ALL

                    -- 0.98: Belegnummer im Verwendungszweck + IBAN des Kontakts bestaetigt
                    SELECT d.id, 'invnumber_and_iban', 0.98
                    FROM bt
                    JOIN {$C} c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
                    JOIN offen d ON d.contact_id = c.id
                        AND length(d.invnumber) >= 4
                        AND bt.purpose IS NOT NULL
                        AND bt.purpose ~ {$invnrRegex}

                    UNION ALL

                    -- 0.94: Belegnummer im Verwendungszweck (ohne IBAN-Bestaetigung)
                    SELECT d.id, 'invnumber_in_purpose', 0.94
                    FROM bt
                    JOIN offen d ON length(d.invnumber) >= 4
                        AND bt.purpose IS NOT NULL
                        AND bt.purpose ~ {$invnrRegex}

                    UNION ALL

                    -- 0.92: IBAN des Kontakts + exakter offener Betrag (Toleranz 0,01 EUR)
                    SELECT d.id, 'iban_amount_match', 0.92
                    FROM bt
                    JOIN {$C} c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
                    JOIN offen d ON d.contact_id = c.id
                        AND abs(d.open_amount - bt.abs_amount) < 0.01

                    UNION ALL

                    -- 0.80: IBAN bekannt, Kontakt hat genau einen offenen Beleg
                    SELECT max(d.id), 'iban_single_open', 0.80
                    FROM bt
                    JOIN {$C} c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
                    JOIN offen d ON d.contact_id = c.id
                    GROUP BY c.id
                    HAVING count(d.id) = 1

                    UNION ALL

                    -- 0.78: Name passt + exakter Betrag (Fallback ohne IBAN)
                    SELECT d.id, 'name_amount_match', 0.78
                    FROM bt
                    JOIN {$C} c ON {$nameMatch}
                    JOIN offen d ON d.contact_id = c.id
                        AND abs(d.open_amount - bt.abs_amount) < 0.01

                    UNION ALL

                    -- 0.65: IBAN bekannt — alle offenen Belege des Kontakts (Sammelauswahl)
                    SELECT d.id, 'iban_contact', 0.65
                    FROM bt
                    JOIN {$C} c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
                    JOIN offen d ON d.contact_id = c.id

                    UNION ALL

                    -- 0.55: Name passt — alle offenen Belege des Kontakts (Sammelauswahl)
                    SELECT d.id, 'name_contact', 0.55
                    FROM bt
                    JOIN {$C} c ON {$nameMatch}
                    JOIN offen d ON d.contact_id = c.id
                ) m
                JOIN offen d ON d.id = m.doc_id
                JOIN {$C} c ON c.id = d.contact_id
                ORDER BY m.doc_id, m.confidence DESC
            ) deduped
            ORDER BY deduped.confidence DESC
            LIMIT 25
        ) top
    SQL, ['bt_id' => $btId]);

    $candidates = json_decode($result['candidates'] ?? '[]', true) ?: [];

    // ── Sammelzahlung (a): mehrere Belegnummern im Zweck, Summe = Umsatz ────
    // Gutschriften zaehlen negativ; die Summe der offenen Betraege muss dem
    // Absolutbetrag des Umsatzes entsprechen.
    $groupResult = $db->getOne(<<<SQL
        WITH bt AS (
            SELECT id, abs(amount) AS abs_amount, transdate, purpose FROM bank_transactions WHERE id = :bt_id
        ),
        purpose_matches AS (
            SELECT DISTINCT '{$T}'::TEXT                         AS target_type,
                            d.id                                 AS target_id,
                            d.invnumber,
                            round((d.amount - d.paid)::numeric, 2) AS open_amount,
                            (d.amount < 0)                       AS is_credit_note,
                            d.duedate,
                            c.name                               AS contact_name
            FROM bt
            JOIN {$T} d ON abs(d.amount - d.paid) > 0.01
                    AND d.storno IS NOT TRUE
                    AND bt.transdate >= d.transdate
                    AND length(d.invnumber) >= 4
                    AND bt.purpose IS NOT NULL
                    AND bt.purpose ~ {$invnrRegex}
            JOIN {$C} c ON c.id = d.{$CID}
        )
        SELECT json_agg(row_to_json(purpose_matches) ORDER BY purpose_matches.invnumber) AS invoices,
               sum(purpose_matches.open_amount)                                           AS total_amount
        FROM purpose_matches
        HAVING count(*) > 1
           AND abs(sum(purpose_matches.open_amount) - (SELECT abs_amount FROM bt)) < 0.01
    SQL, ['bt_id' => $btId]);

    $suggestedGroup = null;
    if ($groupResult && !empty($groupResult['invoices'])) {
        $groupInvoices = json_decode($groupResult['invoices'], true) ?: [];
        if (count($groupInvoices) > 1) {
            $suggestedGroup = _buildGroupSuggestion($groupInvoices, 'purpose_group', 0.96);
        }
    }

    // ── Sammelzahlung (b): eindeutige Teilsumme der offenen Belege des Kontakts ──
    // Lieferanten ziehen oft "alle faelligen Rechnungen abzueglich Gutschriften"
    // ein, ohne jede Nummer in den Zweck zu schreiben. Kontakt per IBAN, sonst
    // per Name; nur ein EINDEUTIGES Ergebnis wird vorgeschlagen.
    if ($suggestedGroup === null) {
        $contactDocs = $db->getOne(<<<SQL
            WITH bt AS (
                SELECT id, abs(amount) AS abs_amount, transdate, remote_account_number AS remote_iban, remote_name
                FROM bank_transactions WHERE id = :bt_id
            ),
            kontakt AS (
                SELECT c.id, 1 AS prio FROM bt JOIN {$C} c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
                UNION ALL
                SELECT c.id, 2 FROM bt JOIN {$C} c ON {$nameMatch}
            ),
            docs AS (
                SELECT '{$T}'::TEXT AS target_type, d.id AS target_id, d.invnumber,
                       round((d.amount - d.paid)::numeric, 2) AS open_amount,
                       (d.amount < 0) AS is_credit_note, d.duedate, c.name AS contact_name
                FROM bt
                JOIN {$T} d ON d.{$CID} IN (SELECT id FROM kontakt WHERE prio = (SELECT min(prio) FROM kontakt))
                    AND abs(d.amount - d.paid) > 0.01
                    AND d.storno IS NOT TRUE
                    AND d.transdate <= bt.transdate
                JOIN {$C} c ON c.id = d.{$CID}
                ORDER BY d.transdate DESC
                LIMIT 30
            )
            SELECT json_agg(row_to_json(docs)) AS docs, (SELECT abs_amount FROM bt) AS abs_amount FROM docs
        SQL, ['bt_id' => $btId]);

        $docs = json_decode($contactDocs['docs'] ?? '[]', true) ?: [];
        if (count($docs) >= 2) {
            $hit = _subsetSumUnique($docs, intval(round(floatval($contactDocs['abs_amount']) * 100)));
            if ($hit !== null) {
                $suggestedGroup = _buildGroupSuggestion($hit, 'subset_sum', 0.90);
            }
        }
    }

    // Aktuelle Zuordnung laden wenn bereits matched
    $currentMatch = null;
    if ($bt['match_status'] === 'matched') {
        $mapping = $db->getOne(
            "SELECT target_type, target_id FROM bank_transaction_matches WHERE bank_transaction_id = :id",
            ['id' => $btId]
        );
        if ($mapping && in_array($mapping['target_type'], ['ar', 'ap'], true)) {
            $mT   = $mapping['target_type'];
            $mC   = $mT === 'ar' ? 'customer' : 'vendor';
            $mCID = $mT === 'ar' ? 'customer_id' : 'vendor_id';
            $invoice = $db->getOne(<<<SQL
                SELECT d.id, d.invnumber, d.amount AS invoice_amount, d.paid,
                       round((d.amount - d.paid)::numeric, 2) AS open_amount,
                       (d.amount < 0) AS is_credit_note, c.name AS contact_name
                FROM {$mT} d JOIN {$mC} c ON c.id = d.{$mCID}
                WHERE d.id = :id
            SQL, ['id' => $mapping['target_id']]);
            if ($invoice) {
                $currentMatch = array_merge($invoice, [
                    'target_type' => $mT,
                    'target_id'   => intval($mapping['target_id'])
                ]);
            }
        }
    }

    resultInfo(true, '', [
        'transaction'      => $bt,
        'direction'        => $isIncoming ? 'incoming' : 'outgoing',
        'candidates'       => $candidates,
        'current_match'    => $currentMatch,
        'suggested_group'  => $suggestedGroup,
        'has_candidates'   => count($candidates) > 0 || $suggestedGroup !== null
    ]);
}

/**
 * Sammel-Vorschlag aus Beleg-Zeilen ({target_type, target_id, open_amount, ...}) bauen.
 */
function _buildGroupSuggestion(array $docs, $matchType, $confidence) {
    $total = 0.0;
    foreach ($docs as $d) $total += floatval($d['open_amount']);
    return [
        'invoices'     => array_values($docs),
        'total_amount' => round($total, 2),
        'confidence'   => $confidence,
        'match_type'   => $matchType,
        'targets'      => array_map(fn($d) => ['target_type' => $d['target_type'], 'target_id' => intval($d['target_id'])], array_values($docs)),
    ];
}

/**
 * Subset-Sum in Cent: liefert die Belege, deren offene Betraege (Gutschriften
 * negativ) GENAU EINMAL den Zielbetrag ergeben — und nur, wenn dazu mindestens
 * zwei Belege gehoeren (Einzeltreffer sind bereits normale Kandidaten).
 * Mehrdeutige Kombinationen liefern null; der Nutzer waehlt dann selbst.
 * Abbruch bei > 300.000 erreichbaren Summen (Laufzeitschutz).
 *
 * @return array|null Teilmenge von $docs
 */
function _subsetSumUnique(array $docs, int $targetCents) {
    if ($targetCents === 0) return null;
    // sum => [count (max 2), path]
    $reach = [0 => [1, []]];
    foreach ($docs as $i => $d) {
        $v = intval(round(floatval($d['open_amount']) * 100));
        if ($v === 0) continue;
        $next = $reach;
        foreach ($reach as $sum => [$cnt, $path]) {
            $ns = $sum + $v;
            if (isset($next[$ns])) {
                $next[$ns][0] = min(2, $next[$ns][0] + $cnt);
            } else {
                $next[$ns] = [$cnt, array_merge($path, [$i])];
            }
        }
        $reach = $next;
        if (count($reach) > 300000) return null;
    }
    if (!isset($reach[$targetCents])) return null;
    [$cnt, $path] = $reach[$targetCents];
    if ($cnt !== 1 || count($path) < 2) return null;
    return array_map(fn($i) => $docs[$i], $path);
}

/**
 * Sammelbuchung: einen Bankumsatz gegen mehrere Belege buchen.
 *
 * Typischer Fall: ein Lieferant zieht mehrere Rechnungen abzüglich Gutschriften
 * in EINER Lastschrift ein. Jeder Beleg erhält seinen offenen Betrag als eigene
 * acc_trans-Buchung (Gutschriften mit negativem Anteil, Beine gedreht), alle
 * unter einer gemeinsamen Belegnummer. AR und AP dürfen gemischt sein; die
 * erwartete Bankbewegung (AR: +offen, AP: −offen) muss dem Umsatzbetrag
 * entsprechen (Toleranz 0,01 EUR). Alles-oder-nichts per DB-Transaktion.
 *
 * @param int   $data['transaction_id']  Bankumsatz-ID
 * @param array $data['targets']         [{target_type: ar|ap, target_id}]
 * @param array $data['target_ids']      (Altform) AR-IDs
 * @param int   $data['bank_account_id'] Bankkonto-ID
 * @testdata {"transaction_id": 1, "targets": [{"target_type": "ap", "target_id": 100}, {"target_type": "ap", "target_id": 101}], "bank_account_id": 1}
 */
function bookTransactionMultipleInvoices($data) {
    $db = DbhCompany::begin();

    $btId          = intval($data['transaction_id'] ?? 0);
    $bankAccountId = intval($data['bank_account_id'] ?? 0);

    // Ziele einsammeln (dedupliziert), Altform target_ids = AR
    $targets = [];
    foreach ((array)($data['targets'] ?? []) as $t) {
        $type = $t['target_type'] ?? '';
        $id   = intval($t['target_id'] ?? 0);
        if (in_array($type, ['ar', 'ap'], true) && $id > 0) $targets["{$type}:{$id}"] = ['type' => $type, 'id' => $id];
    }
    foreach ((array)($data['target_ids'] ?? []) as $id) {
        $id = intval($id);
        if ($id > 0) $targets["ar:{$id}"] = ['type' => 'ar', 'id' => $id];
    }

    if ($btId <= 0 || count($targets) < 1 || $bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'transaction_id, mindestens ein Beleg und bank_account_id sind Pflicht');
        return;
    }

    $bt = $db->getOne(
        "SELECT bt.id, bt.amount, COALESCE(bte.match_status, 'unmatched') AS match_status, bt.transdate FROM bank_transactions bt LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id WHERE bt.id = :id",
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

    $bankAccount = $db->getOne(<<<SQL
        SELECT ba.chart_id, ch.link AS chart_link
        FROM bank_accounts ba JOIN chart ch ON ch.id = ba.chart_id
        WHERE ba.id = :id
    SQL, ['id' => $bankAccountId]);
    if (!$bankAccount) {
        resultInfo(false, 'NOT_FOUND', 'Bankkonto nicht gefunden');
        return;
    }

    // Belege laden, offene Beträge und erwartete Bankbewegung summieren
    $docs         = [];
    $expectedBank = 0.0;
    foreach ($targets as $t) {
        $tbl = $t['type'];
        $inv = $db->getOne(
            "SELECT id, invnumber, amount, paid FROM {$tbl} WHERE id = :id AND storno IS NOT TRUE",
            ['id' => $t['id']]
        );
        if (!$inv) {
            resultInfo(false, 'NOT_FOUND', "Beleg {$tbl} #{$t['id']} nicht gefunden");
            return;
        }
        $open = round(floatval($inv['amount']) - floatval($inv['paid']), 2);
        if (abs($open) < 0.005) {
            resultInfo(false, 'ALREADY_PAID', "Beleg {$inv['invnumber']} ist bereits ausgeglichen");
            return;
        }
        $expectedBank += ($tbl === 'ar') ? $open : -$open;
        $docs[] = ['type' => $tbl, 'id' => intval($inv['id']), 'invnumber' => $inv['invnumber'], 'open' => $open];
    }

    if (abs($expectedBank - floatval($bt['amount'])) > 0.01) {
        resultInfo(false, 'AMOUNT_MISMATCH', sprintf(
            'Belegsumme %s EUR entspricht nicht dem Umsatz %s EUR',
            number_format($expectedBank, 2, ',', '.'),
            number_format(floatval($bt['amount']), 2, ',', '.')
        ));
        return;
    }

    // Eine fortlaufende Belegnummer fuer den gesamten (gesplitteten) Bankumsatz.
    // Sie kommt in acc_trans.source (Beleg-Feld); Erkennung/Storno ueber das Mapping.
    $beleg    = nextBelegnummer($db, $bankAccount['chart_id'], $bt['transdate']);
    $bankMemo = "Beleg {$beleg} · Sammelbuchung Umsatz #{$btId}";

    $db->beginTransaction();
    foreach ($docs as $doc) {
        $res = _bookBankPaymentAgainstInvoice($db, $bt, $doc['type'], $doc['id'], $bankAccount, [
            'amount'   => $doc['open'],
            'beleg'    => $beleg,
            'memo'     => $bankMemo,
            'finalize' => false,
        ]);
        if (!$res['ok']) {
            $db->rollBack();
            writeLog("bookTransactionMultipleInvoices: Umsatz #{$btId} abgebrochen — Beleg {$doc['invnumber']}: {$res['error']}", true, DLOG_ERR);
            resultInfo(false, 'BOOKING_FAILED', "Beleg {$doc['invnumber']}: {$res['error']}");
            return;
        }
    }
    _finalizeBankTransactionBooking($db, $btId);
    $db->commit();

    resultInfo(true, 'Sammelbuchung gebucht', [
        'booked_count' => count($docs),
        'beleg'        => $beleg,
        'errors'       => [],
    ]);
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
