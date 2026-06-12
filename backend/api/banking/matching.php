<?php
// backend/api/banking/matching.php

/**
 * Offene Rechnungen laden fuer Zuordnung (AR + AP)
 *
 * @param string $data['type']   ar|ap|all (default: all)
 * @param string $data['search'] Suchbegriff (optional, sucht in Rechnungsnr + Kundenname)
 * @testdata {"type": "all"}
 */
function getOpenInvoicesForMatching($data) {
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

        $arResult = $db->getAll(<<<SQL
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
                    (ar.amount - ar.paid) as open_amount,
                    c.name as customer_name,
                    c.iban as customer_iban,
                    c.id as customer_id
                FROM ar
                JOIN customer c ON c.id = ar.customer_id
                WHERE ar.amount > ar.paid
                  AND ar.storno IS NOT TRUE
                  {$arSearch}
                ORDER BY ar.duedate ASC
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

        $apResult = $db->getAll(<<<SQL
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
                    (ap.amount - ap.paid) as open_amount,
                    v.name as vendor_name,
                    v.iban as vendor_iban,
                    v.id as vendor_id
                FROM ap
                JOIN vendor v ON v.id = ap.vendor_id
                WHERE ap.amount > ap.paid
                  AND ap.storno IS NOT TRUE
                  {$apSearch}
                ORDER BY ap.duedate ASC
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
        "SELECT id, amount, match_status FROM bank_transactions WHERE id = :id",
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
        "UPDATE bank_transactions SET match_status = 'matched' WHERE id = :id",
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
            "SELECT id, amount, match_status, transdate FROM bank_transactions WHERE id = :id",
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

        $targetType = $mapping['target_type'];   // 'ar' oder 'ap'
        $targetId   = intval($mapping['target_id']);
        $isAr       = $targetType === 'ar';
        $invTable   = $isAr ? 'ar' : 'ap';
        $linkPrefix = $isAr ? 'AR' : 'AP';
        $paidLink   = $linkPrefix . '_paid';

        // Rechnungs-Stammbuchung lesen — daraus ergibt sich das Forderungs-/
        // Verbindlichkeits-Konto (chart_id), gegen das die Erstbuchung lief.
        // Wir buchen die Zahlung gegen genau dieses Konto, kompatibel mit dem
        // jeweiligen Kontenrahmen des Kunden/Lieferanten.
        $counterChart = $db->getOne(<<<SQL
            SELECT chart_id
            FROM acc_trans
            WHERE trans_id = :tid
              AND chart_link LIKE :base_link
              AND chart_link NOT LIKE :paid_link
            ORDER BY acc_trans_id ASC
            LIMIT 1
        SQL, [
            'tid'       => $targetId,
            'base_link' => '%' . $linkPrefix . '%',
            'paid_link' => '%' . $paidLink . '%',
        ]);

        // Fallback fuer Rechnungen ohne GL-Erstbuchung (z. B. importierte Belege):
        // Standard-Forderungs-/Verbindlichkeitskonto aus dem Kontenrahmen nehmen —
        // chart.link = 'AR' bzw. 'AP', niedrigste Kontonummer (wie die Faktura-UI).
        if (!$counterChart) {
            $counterChart = $db->getOne(
                "SELECT id AS chart_id FROM chart WHERE link = :lnk ORDER BY accno ASC LIMIT 1",
                ['lnk' => $linkPrefix]
            );
            writeLog("bookMatchedTransactions: {$invTable} #{$targetId} ohne acc_trans — Fallback-Konto " . ($counterChart['chart_id'] ?? 'KEINS'), true, DLOG_INF);
        }

        if (!$counterChart) {
            writeLog("bookMatchedTransactions: Umsatz #{$btId} — kein {$linkPrefix}-Konto ermittelbar", true, DLOG_ERR);
            $errors[] = "Umsatz {$btId}: Forderungs-/Verbindlichkeitskonto der Rechnung nicht ermittelbar";
            continue;
        }

        $invoice = $db->getOne(
            "SELECT id, amount, paid FROM {$invTable} WHERE id = :id",
            ['id' => $targetId]
        );
        if (!$invoice) {
            $errors[] = "Umsatz {$btId}: {$invTable} #{$targetId} nicht gefunden";
            continue;
        }

        // Vorzeichen-Konvention gemaess calculatePaymentEntries (Faktura):
        //   AR-Konto-Eintrag:  positiver Betrag  → wird in ar.paid gezaehlt
        //   Bank-Eintrag:      negativer Betrag  → erscheint im Zahlungsbereich der Rechnung
        //                       (chart_link des Bankkontos enthaelt 'AR_paid', wird von
        //                        getFakturaData mit LIKE '%AR_paid%' gefunden)
        // chart_link separat laden, um doppelte Named-Parameter in PDO zu vermeiden.
        $paidIncrement   = abs(floatval($bt['amount']));
        $counterLink     = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $counterChart['chart_id']]);
        $bankChartLink   = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $bankAccount['chart_id']]);
        $counterLinkVal  = $counterLink['link'] ?? 'AR';
        $bankLinkVal     = $bankChartLink['link'] ?? 'AR_paid:AP_paid';

        // Fortlaufende Belegnummer kommt in acc_trans.source (= Beleg-Feld der Rechnung).
        // Die Erkennung "bank-gebucht" (Faktura-Editor) und der Storno laufen ueber die
        // Mapping-Tabelle bank_transaction_acc_trans, nicht mehr ueber source='BANK'.
        $beleg    = nextBelegnummer($db, $bankAccount['chart_id'], $bt['transdate']);
        $bankMemo = "Beleg {$beleg} · Bankabstimmung Umsatz #{$btId}";

        // AR-Konto-Eintrag (positiv) — wird fuer ar.paid gezaehlt
        $bankEntry = $db->getOne(<<<SQL
            INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
            VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, 0, 0, :chart_link)
            RETURNING acc_trans_id
        SQL, [
            'trans_id'   => $targetId,
            'chart_id'   => $counterChart['chart_id'],
            'amount'     => $paidIncrement,
            'transdate'  => $bt['transdate'],
            'source'     => (string)$beleg,
            'memo'       => $bankMemo,
            'chart_link' => $counterLinkVal,
        ]);

        // Bank-Eintrag (negativ) — chart_link des Bankkontos ('AR_paid:AP_paid')
        // wird von getFakturaData via LIKE '%AR_paid%' im Zahlungsbereich gefunden.
        $bankLeg = $db->getOne(<<<SQL
            INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
            VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, 0, 0, :chart_link)
            RETURNING acc_trans_id
        SQL, [
            'trans_id'   => $targetId,
            'chart_id'   => $bankAccount['chart_id'],
            'amount'     => -$paidIncrement,
            'transdate'  => $bt['transdate'],
            'source'     => (string)$beleg,
            'memo'       => $bankMemo,
            'chart_link' => $bankLinkVal,
        ]);

        // ar.paid / ap.paid mit positivem Betrag inkrementieren.
        $db->execute(
            "UPDATE {$invTable} SET paid = COALESCE(paid, 0) + :inc WHERE id = :id",
            ['inc' => $paidIncrement, 'id' => $targetId]
        );

        // kivitendo-Mapping fuellen — BEIDE Buchungsbeine verknuepfen. Das liefert
        // eine stabile, vom (editierbaren) Memo unabhaengige Referenz fuer den Storno
        // (unbookTransaction) und schuetzt beide Eintraege im Faktura-Editor.
        $arIdVal = $isAr ? $targetId : null;
        $apIdVal = $isAr ? null : $targetId;
        foreach ([$bankEntry['acc_trans_id'], $bankLeg['acc_trans_id']] as $linkedAccTransId) {
            $db->execute(<<<SQL
                INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, ar_id, ap_id)
                VALUES (:bt_id, :acc_trans_id, :ar_id, :ap_id)
            SQL, [
                'bt_id'         => $btId,
                'acc_trans_id'  => $linkedAccTransId,
                'ar_id'         => $arIdVal,
                'ap_id'         => $apIdVal,
            ]);
        }

        // Pre-Booking-Mapping konsumiert — Information ist jetzt in
        // bank_transaction_acc_trans und acc_trans persistent.
        $db->execute(
            "DELETE FROM bank_transaction_matches WHERE bank_transaction_id = :id",
            ['id' => $btId]
        );

        $db->execute(
            "UPDATE bank_transactions SET match_status = 'booked', cleared = true WHERE id = :id",
            ['id' => $btId]
        );

        $bookedCount++;
    }

    resultInfo(true, "Gebucht", [
        'booked_count' => $bookedCount,
        'errors' => $errors
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
        "SELECT id, amount, match_status FROM bank_transactions WHERE id = :id",
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

    $rows = $db->getAll(<<<SQL
        SELECT at.acc_trans_id, at.trans_id, at.amount
        FROM acc_trans at
        WHERE at.acc_trans_id IN (
                SELECT acc_trans_id FROM bank_transaction_acc_trans
                WHERE bank_transaction_id = :bt_id
            )
            OR at.memo LIKE :memo_like
    SQL, ['bt_id' => $btId, 'memo_like' => $memoLike]);

    if (empty($rows)) {
        // Weder Mapping- noch Memo-Eintraege — nur Status (und evtl. verwaistes
        // Mapping) zuruecksetzen.
        $db->execute(
            "DELETE FROM bank_transaction_acc_trans WHERE bank_transaction_id = :id",
            ['id' => $btId]
        );
        $db->execute(
            "UPDATE bank_transactions SET match_status = 'unmatched', cleared = false WHERE id = :id",
            ['id' => $btId]
        );
        resultInfo(true, 'Status zurueckgesetzt (keine acc_trans-Eintraege gefunden)');
        return;
    }

    // Loesch-IDs und je Rechnung die Summe der positiven Betraege (= gebuchter
    // Zahlbetrag) sammeln, bevor geloescht wird.
    $ids = [];
    $positiveByTrans = [];
    foreach ($rows as $r) {
        $ids[] = intval($r['acc_trans_id']);
        $amt = floatval($r['amount']);
        if ($amt > 0) {
            $tid = intval($r['trans_id']);
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

    // ar.paid je betroffener Rechnung zuruecksetzen (minimiert auf 0).
    foreach ($positiveByTrans as $transId => $positiveSum) {
        if ($positiveSum > 0) {
            $db->execute(
                "UPDATE ar SET paid = GREATEST(0, COALESCE(paid, 0) - :dec) WHERE id = :id",
                ['dec' => $positiveSum, 'id' => $transId]
            );
        }
    }

    // Umsatz-Status zuruecksetzen
    $db->execute(
        "UPDATE bank_transactions SET match_status = 'unmatched', cleared = false WHERE id = :id",
        ['id' => $btId]
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
        "SELECT id, match_status FROM bank_transactions WHERE id = :id",
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
        "UPDATE bank_transactions SET match_status = 'unmatched' WHERE id = :id",
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
 * Zuordnungs-Kandidaten fuer einen einzelnen Bankumsatz ermitteln
 *
 * Liefert alle passenden AR-Rechnungen (offene Posten) sortiert nach Konfidenz.
 * Nur fuer Zahlungseingaenge (amount > 0). AP folgt spaeter.
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
        "SELECT id, amount, remote_name, remote_iban, purpose, end_to_end_id, match_status
         FROM bank_transactions WHERE id = :id",
        ['id' => $btId]
    );

    if (!$bt) {
        resultInfo(false, 'NOT_FOUND', 'Bankumsatz nicht gefunden');
        return;
    }

    $candidates = [];

    if (floatval($bt['amount']) > 0) {
        // AR-Kandidaten — CTE vermeidet doppelte Named-Parameters in PDO
        $result = $db->getOne(<<<SQL
            WITH bt AS (
                SELECT id, amount, remote_iban, remote_name, purpose, end_to_end_id
                FROM bank_transactions
                WHERE id = :bt_id AND amount > 0
            )
            SELECT json_agg(row_to_json(top) ORDER BY top.confidence DESC) AS candidates
            FROM (
                SELECT *
                FROM (
                    SELECT DISTINCT ON (m.ar_id)
                        'ar'::TEXT             AS target_type,
                        ar.id                  AS target_id,
                        ar.invnumber,
                        ar.transdate,
                        ar.duedate,
                        ar.amount              AS invoice_amount,
                        ar.paid,
                        (ar.amount - ar.paid)  AS open_amount,
                        c.name                 AS contact_name,
                        c.iban                 AS contact_iban,
                        m.match_type,
                        m.confidence
                    FROM (
                        -- 0.99: End-to-End-ID enthaelt Rechnungsnummer (SEPA-Strukturwert)
                        SELECT ar.id AS ar_id, 'end_to_end_id'::TEXT AS match_type, 0.99::NUMERIC AS confidence
                        FROM bt
                        JOIN ar ON (ar.amount - ar.paid) > 0.01
                            AND ar.storno IS NOT TRUE
                            AND length(ar.invnumber) >= 4
                            AND bt.end_to_end_id IS NOT NULL
                            AND bt.end_to_end_id ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')

                        UNION ALL

                        -- 0.98: Rechnungsnummer im Purpose + Kunden-IBAN bestaetigt
                        SELECT ar.id, 'invnumber_and_iban', 0.98
                        FROM bt
                        JOIN customer c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
                        JOIN ar ON ar.customer_id = c.id
                            AND (ar.amount - ar.paid) > 0.01
                            AND ar.storno IS NOT TRUE
                            AND length(ar.invnumber) >= 4
                            AND bt.purpose IS NOT NULL
                            AND bt.purpose ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')

                        UNION ALL

                        -- 0.92: Kunden-IBAN + exakter offener Betrag (Toleranz 0,01 EUR)
                        SELECT ar.id, 'iban_amount_match', 0.92
                        FROM bt
                        JOIN customer c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
                        JOIN ar ON ar.customer_id = c.id
                            AND ABS((ar.amount - ar.paid) - bt.amount) < 0.01
                            AND ar.paid < ar.amount
                            AND ar.storno IS NOT TRUE

                        UNION ALL

                        -- 0.88: Rechnungsnummer im Purpose (ohne IBAN-Bestaetigung)
                        SELECT ar.id, 'invnumber_in_purpose', 0.88
                        FROM bt
                        JOIN ar ON (ar.amount - ar.paid) > 0.01
                            AND ar.storno IS NOT TRUE
                            AND length(ar.invnumber) >= 4
                            AND bt.purpose IS NOT NULL
                            AND bt.purpose ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')

                        UNION ALL

                        -- 0.80: Kunden-IBAN bekannt, Kunde hat genau eine offene Rechnung
                        SELECT max(ar.id), 'iban_single_open', 0.80
                        FROM bt
                        JOIN customer c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
                        JOIN ar ON ar.customer_id = c.id
                            AND (ar.amount - ar.paid) > 0.01
                            AND ar.storno IS NOT TRUE
                        GROUP BY c.id
                        HAVING count(ar.id) = 1

                        UNION ALL

                        -- 0.78: Name passt + exakter Betrag (Fallback ohne IBAN)
                        SELECT ar.id, 'name_amount_match', 0.78
                        FROM bt
                        JOIN customer c ON bt.remote_name IS NOT NULL
                            AND length(trim(bt.remote_name)) >= 3
                            AND (c.name ILIKE '%' || trim(bt.remote_name) || '%'
                             OR trim(bt.remote_name) ILIKE '%' || c.name || '%')
                        JOIN ar ON ar.customer_id = c.id
                            AND ABS((ar.amount - ar.paid) - bt.amount) < 0.01
                            AND ar.paid < ar.amount
                            AND ar.storno IS NOT TRUE

                        UNION ALL

                        -- 0.65: IBAN bekannt — alle offenen Rechnungen (manuelle Auswahl)
                        SELECT ar.id, 'iban_customer', 0.65
                        FROM bt
                        JOIN customer c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
                        JOIN ar ON ar.customer_id = c.id
                            AND (ar.amount - ar.paid) > 0.01
                            AND ar.storno IS NOT TRUE

                    ) m
                    JOIN ar ON ar.id = m.ar_id
                    JOIN customer c ON c.id = ar.customer_id
                    ORDER BY m.ar_id, m.confidence DESC
                ) deduped
                ORDER BY deduped.confidence DESC
                LIMIT 10
            ) top
        SQL, ['bt_id' => $btId]);

        $candidates = json_decode($result['candidates'] ?? '[]', true) ?: [];

        // ── Sammelzahlung-Erkennung ──────────────────────────────────────────
        // Mehrere Rechnungsnummern im Purpose + Summe = Transaktionsbetrag
        // → hoehere Konfidenz als einzelne Treffer, kein KI noetig.
        $groupResult = $db->getOne(<<<SQL
            WITH bt AS (
                SELECT id, amount, purpose FROM bank_transactions WHERE id = :bt_id AND amount > 0
            ),
            purpose_matches AS (
                SELECT DISTINCT ar.id                     AS ar_id,
                                ar.invnumber,
                                (ar.amount - ar.paid)     AS open_amount,
                                ar.duedate,
                                c.name                    AS contact_name
                FROM bt
                JOIN ar ON (ar.amount - ar.paid) > 0.01
                        AND ar.storno IS NOT TRUE
                        AND length(ar.invnumber) >= 4
                        AND bt.purpose IS NOT NULL
                        AND bt.purpose ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')
                JOIN customer c ON c.id = ar.customer_id
            )
            SELECT json_agg(row_to_json(purpose_matches) ORDER BY purpose_matches.invnumber) AS invoices,
                   sum(purpose_matches.open_amount)                                           AS total_amount
            FROM purpose_matches
            HAVING count(*) > 1
               AND abs(sum(purpose_matches.open_amount) - (SELECT amount FROM bt)) < 0.01
        SQL, ['bt_id' => $btId]);

        $suggestedGroup = null;
        if ($groupResult && !empty($groupResult['invoices'])) {
            $groupInvoices = json_decode($groupResult['invoices'], true) ?: [];
            if (count($groupInvoices) > 1) {
                $suggestedGroup = [
                    'invoices'     => $groupInvoices,
                    'total_amount' => floatval($groupResult['total_amount']),
                    'confidence'   => 0.96,
                    'target_type'  => 'ar',
                    'target_ids'   => array_column($groupInvoices, 'ar_id'),
                ];
            }
        }
    } else {
        $suggestedGroup = null;
    }

    // Aktuelle Zuordnung laden wenn bereits matched
    $currentMatch = null;
    if ($bt['match_status'] === 'matched') {
        $mapping = $db->getOne(
            "SELECT target_type, target_id FROM bank_transaction_matches WHERE bank_transaction_id = :id",
            ['id' => $btId]
        );
        if ($mapping && $mapping['target_type'] === 'ar') {
            $invoice = $db->getOne(<<<SQL
                SELECT ar.id, ar.invnumber, ar.amount AS invoice_amount, ar.paid,
                       (ar.amount - ar.paid) AS open_amount, c.name AS contact_name
                FROM ar JOIN customer c ON c.id = ar.customer_id
                WHERE ar.id = :id
            SQL, ['id' => $mapping['target_id']]);
            if ($invoice) {
                $currentMatch = array_merge($invoice, [
                    'target_type' => 'ar',
                    'target_id'   => intval($mapping['target_id'])
                ]);
            }
        }
    }

    resultInfo(true, '', [
        'transaction'      => $bt,
        'candidates'       => $candidates,
        'current_match'    => $currentMatch,
        'suggested_group'  => $suggestedGroup,
        'has_candidates'   => count($candidates) > 0 || $suggestedGroup !== null
    ]);
}

/**
 * Sammelzahlung buchen: einen Bankumsatz gegen mehrere AR-Rechnungen
 *
 * Jede Rechnung erhaelt ihren Anteil als eigene acc_trans-Buchung. Die Summe
 * der offenen Betraege muss dem Transaktionsbetrag entsprechen (Toleranz 0,01 EUR).
 *
 * @param int   $data['transaction_id']  Bankumsatz-ID
 * @param array $data['target_ids']      Array von AR-IDs
 * @param int   $data['bank_account_id'] Bankkonto-ID
 * @testdata {"transaction_id": 1, "target_ids": [100, 101], "bank_account_id": 1}
 */
function bookTransactionMultipleInvoices($data) {
    $db = DbhCompany::begin();

    $btId          = intval($data['transaction_id'] ?? 0);
    $targetIds     = array_map('intval', $data['target_ids'] ?? []);
    $bankAccountId = intval($data['bank_account_id'] ?? 0);

    if ($btId <= 0 || count($targetIds) < 2 || $bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'transaction_id, mindestens 2 target_ids und bank_account_id sind Pflicht');
        return;
    }

    $bt = $db->getOne(
        "SELECT id, amount, match_status, transdate FROM bank_transactions WHERE id = :id",
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

    // Eine fortlaufende Belegnummer fuer den gesamten (gesplitteten) Bankumsatz.
    // Sie kommt in acc_trans.source (Beleg-Feld); Erkennung/Storno ueber das Mapping.
    $beleg    = nextBelegnummer($db, $bankAccount['chart_id'], $bt['transdate']);
    $bankMemo = "Beleg {$beleg} · Sammelzahlung Umsatz #{$btId}";

    // Alle Rechnungen laden und Betraege summieren
    $invoices   = [];
    $totalOpen  = 0.0;
    foreach ($targetIds as $arId) {
        $inv = $db->getOne(
            "SELECT id, amount, paid FROM ar WHERE id = :id AND storno IS NOT TRUE",
            ['id' => $arId]
        );
        if (!$inv) {
            resultInfo(false, 'NOT_FOUND', "Rechnung #{$arId} nicht gefunden");
            return;
        }
        $open = round(floatval($inv['amount']) - floatval($inv['paid']), 2);
        $invoices[] = ['id' => $arId, 'open_amount' => $open];
        $totalOpen += $open;
    }

    // Betragsvalidierung
    if (abs($totalOpen - floatval($bt['amount'])) > 0.01) {
        resultInfo(false, 'AMOUNT_MISMATCH',
            'Rechnungssumme ' . number_format($totalOpen, 2) . ' EUR != Umsatz ' . $bt['amount'] . ' EUR');
        return;
    }

    // Pro Rechnung buchen
    $skipped = [];
    foreach ($invoices as $inv) {
        $arId   = $inv['id'];
        $amount = $inv['open_amount'];

        $counterChart = $db->getOne(<<<SQL
            SELECT chart_id FROM acc_trans
            WHERE trans_id = :tid AND chart_link LIKE '%AR%' AND chart_link NOT LIKE '%AR_paid%'
            ORDER BY acc_trans_id ASC LIMIT 1
        SQL, ['tid' => $arId]);

        // Fallback fuer Rechnungen ohne GL-Erstbuchung: Standard-Forderungskonto
        if (!$counterChart) {
            $counterChart = $db->getOne(
                "SELECT id AS chart_id FROM chart WHERE link = 'AR' ORDER BY accno ASC LIMIT 1"
            );
            writeLog("bookTransactionMultipleInvoices: AR #{$arId} ohne acc_trans — Fallback-Forderungskonto " . ($counterChart['chart_id'] ?? 'KEINS'), true, DLOG_INF);
        }

        if (!$counterChart) {
            writeLog("bookTransactionMultipleInvoices: AR #{$arId} uebersprungen — kein Forderungskonto", true, DLOG_ERR);
            $skipped[] = $arId;
            continue;
        }

        // chart_link separat laden (kein doppelter Named-Parameter in PDO)
        $cLink       = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $counterChart['chart_id']]);
        $bLink       = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $bankAccount['chart_id']]);
        $cLinkVal    = $cLink['link'] ?? 'AR';
        $bLinkVal    = $bLink['link'] ?? 'AR_paid:AP_paid';

        // AR-Konto-Eintrag (positiv) — wird in ar.paid gezaehlt
        $bankEntry = $db->getOne(<<<SQL
            INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
            VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, 0, 0, :chart_link)
            RETURNING acc_trans_id
        SQL, [
            'trans_id'   => $arId,
            'chart_id'   => $counterChart['chart_id'],
            'amount'     => $amount,
            'transdate'  => $bt['transdate'],
            'source'     => (string)$beleg,
            'memo'       => $bankMemo,
            'chart_link' => $cLinkVal,
        ]);

        // Bank-Eintrag (negativ) — chart_link des Bankkontos, wird von Faktura-Anzeige gefunden
        $bankLeg = $db->getOne(<<<SQL
            INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
            VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, 0, 0, :chart_link)
            RETURNING acc_trans_id
        SQL, [
            'trans_id'   => $arId,
            'chart_id'   => $bankAccount['chart_id'],
            'amount'     => -$amount,
            'transdate'  => $bt['transdate'],
            'source'     => (string)$beleg,
            'memo'       => $bankMemo,
            'chart_link' => $bLinkVal,
        ]);

        // ar.paid inkrementieren (positiver Betrag)
        $db->execute(
            "UPDATE ar SET paid = COALESCE(paid, 0) + :inc WHERE id = :id",
            ['inc' => $amount, 'id' => $arId]
        );

        // Mapping Bankumsatz → acc_trans — BEIDE Buchungsbeine verknuepfen (stabile,
        // memo-unabhaengige Referenz fuer Storno und Faktura-Schutz).
        foreach ([$bankEntry['acc_trans_id'], $bankLeg['acc_trans_id']] as $linkedAccTransId) {
            $db->execute(<<<SQL
                INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, ar_id, ap_id)
                VALUES (:bt_id, :acc_trans_id, :ar_id, NULL)
            SQL, [
                'bt_id'        => $btId,
                'acc_trans_id' => $linkedAccTransId,
                'ar_id'        => $arId,
            ]);
        }
    }

    // Wenn keine einzige Rechnung gebucht werden konnte → Fehler statt falscher
    // 'booked'-Status. So bleibt der Umsatz sichtbar und der Nutzer merkt es.
    $bookedCount = count($invoices) - count($skipped);
    if ($bookedCount <= 0) {
        resultInfo(false, 'BOOKING_FAILED',
            'Keine Rechnung konnte gebucht werden (Konto nicht ermittelbar): ' . implode(', ', $skipped));
        return;
    }

    // Einzeln-Zuordnung entfernen falls vorhanden, Status buchen setzen
    $db->execute(
        "DELETE FROM bank_transaction_matches WHERE bank_transaction_id = :id",
        ['id' => $btId]
    );
    $db->execute(
        "UPDATE bank_transactions SET match_status = 'booked', cleared = true WHERE id = :id",
        ['id' => $btId]
    );

    resultInfo(true, 'Sammelzahlung gebucht', [
        'booked_count' => $bookedCount,
        'skipped'      => $skipped
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
