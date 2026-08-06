<?php
// backend/api/accounting/outgoing_matching.php

/**
 * Passt der Zahler der Bankbewegung zum Kunden der Rechnung?
 *
 * Zwei Belege gelten als zusammengehoerig, wenn entweder die IBAN uebereinstimmt
 * oder ein aussagekraeftiger Teil des Kundennamens im Zahlernamen bzw. im
 * Verwendungszweck auftaucht. Rechtsformen und Vornamen-Kuerzel werden dabei
 * ignoriert, weil "GmbH" sonst jede Firma mit jeder anderen verbinden wuerde.
 *
 * @param array $tx  Zeile aus bank_transactions (remote_name, remote_iban, purpose)
 * @param array $inv Offene Rechnung inkl. customer_name / customer_iban
 * @return bool
 */
function _om_partyMatches(array $tx, array $inv): bool {
    $norm = fn($s) => strtolower(str_replace(' ', '', (string)$s));

    $txIban   = $norm($tx['remote_iban'] ?? '');
    $custIban = $norm($inv['customer_iban'] ?? '');
    if ($txIban !== '' && $txIban === $custIban) return true;

    $haystack = mb_strtolower(($tx['remote_name'] ?? '') . ' ' . ($tx['purpose'] ?? ''));
    if (trim($haystack) === '') return false;

    // Rechtsformen und Allerweltswoerter taugen nicht als Erkennungsmerkmal
    static $stop = [
        'gmbh', 'mbh', 'ohg', 'kgaa', 'gbr', 'e.k.', 'ug', 'co.', 'und', 'der', 'die', 'das',
        'haftungsbeschraenkt', 'haftungsbeschränkt', 'firma', 'herr', 'frau', 'familie',
    ];

    $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($inv['customer_name'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($tokens as $tok) {
        if (mb_strlen($tok) < 4 || in_array($tok, $stop, true)) continue;
        if (mb_strpos($haystack, $tok) !== false) return true;
    }

    return false;
}

/**
 * Ausgangsrechnungen automatisch mit Bankbewegungen abgleichen.
 * Matcht eingehende Zahlungen auf offene AR-Rechnungen anhand
 * von Betrag oder Rechnungsnummer im Verwendungszweck.
 *
 * @param int $data['bank_account_id'] Bankkonto-ID (optional, alle wenn leer)
 * @testdata {"bank_account_id": 1}
 */
function matchOutgoingInvoices($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);

    $where = "bt.match_status = 'unmatched' AND bt.amount > 0"; // Eingaenge (positiv)
    $params = [];

    if ($bankAccountId) {
        $where .= " AND bt.local_bank_account_id = :baid";
        $params[':baid'] = $bankAccountId;
    }

    // Nicht zugeordnete Eingaenge laden
    $transactions = $db->getAll(<<<SQL
        SELECT bt.id, bt.amount, bt.remote_name, bt.remote_iban, bt.purpose,
               bt.transdate, bt.local_bank_account_id
        FROM bank_transactions bt
        WHERE {$where}
        ORDER BY bt.transdate DESC
        LIMIT 200
    SQL, $params);

    // Offene Ausgangsrechnungen laden
    $openInvoices = $db->getAll(<<<SQL
        SELECT ar.id, ar.invnumber, ar.amount, ar.paid,
               (ar.amount - ar.paid) AS open_amount,
               ar.customer_id, c.name AS customer_name, c.iban AS customer_iban,
               ar.transdate, ar.duedate
        FROM ar
        JOIN customer c ON c.id = ar.customer_id
        WHERE ar.amount > ar.paid
        AND ar.storno IS NOT TRUE
        -- Nur ins Hauptbuch verbuchte Rechnungen: nur für diese lässt sich der
        -- Zahlungseingang buchen. Unverbuchte (2025er Vorjahresbelege im
        -- Eröffnungsvortrag) würden beim Buchen abgelehnt und blieben in der Liste.
        AND EXISTS (SELECT 1 FROM acc_trans t WHERE t.trans_id = ar.id)
        ORDER BY ar.transdate DESC
        LIMIT 500
    SQL, []);

    // Alle jemals vergebenen Rechnungsnummern — auch die bereits bezahlten.
    // Nennt der Verwendungszweck eine davon, ist die Zuordnung damit entschieden;
    // ein zufaellig betragsgleicher anderer Beleg darf dann NICHT vorgeschlagen
    // werden. Ohne diese Pruefung zeigte fast die Haelfte der Vorschlaege auf
    // eine Rechnung, die im Verwendungszweck gar nicht steht.
    $invNumberRows = $db->getAll(
        "SELECT invnumber FROM ar WHERE invnumber IS NOT NULL AND invnumber <> ''", []
    );
    $knownInvNumbers = [];
    foreach ($invNumberRows as $r) {
        $knownInvNumbers[strtolower(trim($r['invnumber']))] = true;
    }

    $matches = [];
    $matchCount = 0;

    foreach ($transactions as $tx) {
        $txAmount = floatval($tx['amount']);
        $purpose = strtolower($tx['purpose'] ?? '');
        $bestMatch = null;
        $bestConfidence = 0;

        // Im Zweck genannte, tatsaechlich existierende Rechnungsnummern
        $referenced = [];
        if (preg_match_all('/\d{4,}/', $purpose, $tokens)) {
            foreach ($tokens[0] as $tok) {
                if (isset($knownInvNumbers[$tok])) $referenced[$tok] = true;
            }
        }

        foreach ($openInvoices as $inv) {
            $openAmount = floatval($inv['open_amount']);
            $confidence = 0;
            $matchReason = '';

            // Der Zweck nennt eine konkrete Rechnung — dann kommt nur diese in Frage.
            if ($referenced && !isset($referenced[strtolower(trim($inv['invnumber']))])) {
                continue;
            }

            // Mehr zahlen als offen ist, ergibt als 1:1-Zuordnung keinen Sinn
            // (typisch: Sammelzahlung fuer mehrere Rechnungen). Solche Faelle
            // gehoeren manuell aufgeteilt und werden daher nicht vorgeschlagen —
            // die Buchung wuerde sie ohnehin ablehnen.
            if ($txAmount > $openAmount + 0.01) {
                continue;
            }

            // 1. Rechnungsnummer im Verwendungszweck (hoechste Prioritaet)
            $invNumber = $inv['invnumber'];
            if (!empty($invNumber) && stripos($purpose, strtolower($invNumber)) !== false) {
                $confidence = 0.95;
                $matchReason = 'Rechnungsnummer im Verwendungszweck';

                // Betrag muss auch ungefaehr passen (10% Toleranz fuer Skonto)
                if (abs($txAmount - $openAmount) / max($openAmount, 0.01) <= 0.10) {
                    $confidence = 0.99;
                    $matchReason = 'Rechnungsnummer + Betrag stimmen ueberein';
                }
            }

            // Steht keine Rechnungsnummer im Zweck, ist der Betrag allein KEIN
            // Beleg fuer eine Zuordnung: bei hunderten offenen Rechnungen trifft
            // fast jede Zahlung zufaellig irgendeinen Betrag im Toleranzband.
            // Deshalb muss zusaetzlich der Zahler zum Kunden passen — per IBAN
            // oder weil der Kundenname im Zahler/Verwendungszweck vorkommt.
            $partyMatches = $confidence >= 0.95 ? true : _om_partyMatches($tx, $inv);

            // 2. Exakter Betragsabgleich + IBAN
            if ($confidence < 0.85 && $partyMatches && abs($txAmount - $openAmount) < 0.01) {
                $txIban = strtolower(str_replace(' ', '', $tx['remote_iban'] ?? ''));
                $custIban = strtolower(str_replace(' ', '', $inv['customer_iban'] ?? ''));

                if (!empty($txIban) && !empty($custIban) && $txIban === $custIban) {
                    $confidence = 0.90;
                    $matchReason = 'Betrag + IBAN stimmen ueberein';
                } else {
                    // Betrag + Zahlername
                    $confidence = 0.60;
                    $matchReason = 'Betrag und Zahler stimmen ueberein';
                }
            }

            // 3. Betragsabgleich mit Skonto-Toleranz (2-3%)
            if ($confidence < 0.60 && $partyMatches) {
                $skontoDiff = $openAmount - $txAmount;
                $skontoPercent = ($openAmount > 0) ? ($skontoDiff / $openAmount * 100) : 0;

                if ($skontoPercent >= 1.5 && $skontoPercent <= 3.5 && $txAmount > 10) {
                    $confidence = 0.50;
                    $matchReason = 'Zahler passt, moeglicherweise Skonto (' . round($skontoPercent, 1) . '%)';
                }
            }

            if ($confidence > $bestConfidence) {
                $bestConfidence = $confidence;
                $bestMatch = [
                    'transaction_id' => intval($tx['id']),
                    'invoice_id'     => intval($inv['id']),
                    'invoice_number' => $inv['invnumber'],
                    'customer_id'    => intval($inv['customer_id']),
                    'customer_name'  => $inv['customer_name'],
                    'tx_amount'      => $txAmount,
                    'invoice_total'  => floatval($inv['amount']),
                    'invoice_amount' => $openAmount,
                    'confidence'     => $confidence,
                    'match_reason'   => $matchReason,
                    'transdate'      => $tx['transdate']
                ];
            }
        }

        if ($bestMatch && $bestConfidence >= 0.50) {
            $matches[] = $bestMatch;
            $matchCount++;
        }
    }

    // Nach Confidence sortieren
    usort($matches, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    // Wie viele der offenen Bankeingaenge nennen eine Rechnung, die bereits
    // vollstaendig bezahlt ist? Das sind Zahlungen, die in kivitendo laengst
    // verbucht wurden — nur die Bankzeile wurde nie auf 'erledigt' gesetzt.
    // Ohne diese Zahl wirkt eine leere Vorschlagsliste wie ein Fehler.
    $settled = $db->getOne(<<<SQL
        WITH eingang AS (
            SELECT bt.id, (regexp_matches(COALESCE(bt.purpose, ''), '([0-9]{6})', 'g'))[1] AS nr
            FROM bank_transactions bt
            WHERE bt.match_status = 'unmatched' AND bt.amount > 0
        )
        SELECT COUNT(DISTINCT e.id) AS cnt
        FROM eingang e
        JOIN ar a ON a.invnumber = e.nr
        WHERE a.amount - COALESCE(a.paid, 0) <= 0.005
    SQL, []);

    resultInfo(true, '', [
        'matches'      => $matches,
        'match_count'  => $matchCount,
        'total_transactions' => count($transactions),
        'total_open_invoices' => count($openInvoices),
        // Bereits andernorts verbuchte Zahlungen — erklaert eine leere Liste
        'already_settled' => intval($settled['cnt'] ?? 0),
    ]);
}

/**
 * Ausgangsrechnung-Match bestaetigen und als Buchung erfassen
 *
 * @param int $data['transaction_id'] Bank-Transaktions-ID
 * @param int $data['invoice_id']     AR-Rechnungs-ID
 * @testdata {"transaction_id": 1, "invoice_id": 1}
 */
function confirmOutgoingMatch($data) {
    $db = DbhCompany::begin();

    $txId = intval($data['transaction_id'] ?? 0);
    $invId = intval($data['invoice_id'] ?? 0);

    if (!$txId || !$invId) {
        throw new ApiError('VALIDATION_ERROR', 'transaction_id und invoice_id erforderlich');
    }

    $tx = $db->getOne(
        "SELECT id, amount, purpose, transdate, match_status, local_bank_account_id
         FROM bank_transactions WHERE id = :id",
        [':id' => $txId]
    );
    if (!$tx) throw new ApiError('DATA_NOT_FOUND', 'Banktransaktion nicht gefunden');
    if ($tx['match_status'] === 'booked') {
        throw new ApiError('ALREADY_BOOKED', 'Diese Bankbewegung ist bereits gebucht.');
    }

    $inv = $db->getOne(
        "SELECT ar.id, ar.invnumber, ar.amount, ar.paid, ar.customer_id, c.name AS customer_name
         FROM ar JOIN customer c ON c.id = ar.customer_id
         WHERE ar.id = :id",
        [':id' => $invId]
    );
    if (!$inv) throw new ApiError('DATA_NOT_FOUND', 'Rechnung nicht gefunden');

    $payAmount = round(floatval($tx['amount']), 2);
    if ($payAmount <= 0) {
        throw new ApiError('INVALID_AMOUNT', 'Der Betrag der Bankbewegung ist nicht positiv.');
    }

    // Nie mehr verbuchen als offen ist. Sonst entstuende auf der Rechnung ein
    // negativer Saldo (ar.paid > ar.amount) und im Hauptbuch eine Forderung im
    // Haben, die es nie gab — typischer Fall: eine Sammelzahlung fuer mehrere
    // Rechnungen. Teilzahlungen (weniger als offen) bleiben erlaubt.
    $openAmount = round(floatval($inv['amount']) - floatval($inv['paid'] ?? 0), 2);
    if ($openAmount <= 0) {
        throw new ApiError('ALREADY_PAID',
            'Rechnung ' . $inv['invnumber'] . ' ist bereits vollstaendig bezahlt.');
    }
    if ($payAmount > $openAmount + 0.01) {
        throw new ApiError('AMOUNT_EXCEEDS_OPEN',
            'Der Zahlungsbetrag (' . number_format($payAmount, 2, ',', '.') . ' €) ist groesser als der offene Betrag der Rechnung '
            . $inv['invnumber'] . ' (' . number_format($openAmount, 2, ',', '.')
            . ' €). Sammelzahlungen bitte manuell auf die einzelnen Rechnungen aufteilen.');
    }

    // Hauptbuchkonto der Bank (aus dem Bankkonto der Bewegung)
    $bankChart = $db->getOne(
        "SELECT c.id, c.accno, c.link FROM bank_accounts b JOIN chart c ON c.id = b.chart_id WHERE b.id = :id",
        [':id' => intval($tx['local_bank_account_id'])]
    );
    if (!$bankChart) throw new ApiError('NO_BANK_ACCOUNT', 'Für diese Bankbewegung ist kein Hauptbuchkonto hinterlegt.');

    // Forderungskonto aus der Rechnung selbst — nur verbuchte Rechnungen haben eine AR-Zeile.
    // Unverbuchte (z. B. 2025er Vorjahresbelege im Eröffnungsvortrag) werden bewusst abgelehnt,
    // sonst entstünde eine unausgeglichene Forderungsbuchung ohne zugehörige Rechnungsbuchung.
    $arChart = $db->getOne(
        "SELECT c.id, c.accno, c.link FROM acc_trans t JOIN chart c ON c.id = t.chart_id
         WHERE t.trans_id = :id AND c.link = 'AR' LIMIT 1",
        [':id' => $invId]
    );
    if (!$arChart) {
        throw new ApiError('INVOICE_NOT_POSTED',
            'Die Rechnung ist nicht im Hauptbuch verbucht (z. B. Vorjahr/Eröffnungsvortrag) — die Zahlung kann nicht gebucht werden.');
    }

    $db->beginTransaction();

    try {
        // Zahlung ins Hauptbuch, direkt auf die Rechnung (trans_id = ar.id):
        //   Bank      Soll  → Aktivkonto, Geld rein  → amount negativ
        //   Forderung Haben → Forderung sinkt        → amount positiv
        // (kivitendo-Vorzeichen: Soll = negativ, Haben = positiv; taxkey/tax_id = 0 = keine Steuer)
        $db->execute(
            "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, taxkey, tax_id, chart_link)
             VALUES (:tid, :chart, :amt, :d, :d, :src, 0, 0, :link)",
            [':tid' => $invId, ':chart' => intval($bankChart['id']), ':amt' => -$payAmount,
             ':d' => $tx['transdate'], ':src' => 'Zahlungseingang', ':link' => (string)$bankChart['link']]
        );
        // acc_trans_id der eben eingefügten Bankzeile für die Verknüpfung
        $bankAcc = $db->getOne("SELECT currval('acc_trans_id_seq') AS id", []);

        $db->execute(
            "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, taxkey, tax_id, chart_link)
             VALUES (:tid, :chart, :amt, :d, :d, :src, 0, 0, :link)",
            [':tid' => $invId, ':chart' => intval($arChart['id']), ':amt' => $payAmount,
             ':d' => $tx['transdate'], ':src' => 'Zahlungseingang', ':link' => (string)$arChart['link']]
        );

        // Rechnung als (teil-)bezahlt fortschreiben
        $db->execute(
            "UPDATE ar SET paid = COALESCE(paid, 0) + :amt, datepaid = :d, mtime = now() WHERE id = :id",
            [':amt' => $payAmount, ':d' => $tx['transdate'], ':id' => $invId]
        );

        // Bankbewegung als gebucht markieren und mit der Hauptbuch-Bankzeile verknüpfen
        $db->execute("UPDATE bank_transactions SET match_status = 'booked' WHERE id = :id", [':id' => $txId]);
        $db->execute(
            "INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, ar_id) VALUES (:tx, :acc, :ar)",
            [':tx' => $txId, ':acc' => intval($bankAcc['id']), ':ar' => $invId]
        );

        // Audit-Beleg in der Buchungsübersicht (richtige Richtung: Bank Soll / Forderung Haben, bereits gebucht)
        $bookingRef = $db->getOne("SELECT next_booking_number() AS ref", []);
        $db->execute(<<<SQL
            INSERT INTO accounting_bookings
                (booking_date, invoice_date, amount, net_amount,
                 debit_account, credit_account, invoice_number, description, reference,
                 type, status, customer_id, ar_id, bank_transaction_id, ai_generated, employee_id)
            VALUES
                (:bdate, :idate, :amount, :amount,
                 :debit, :credit, :invnr, :desc, :ref,
                 'outgoing', 'booked', :cid, :arid, :txid, FALSE, :eid)
        SQL, [
            ':bdate'  => $tx['transdate'],
            ':idate'  => $tx['transdate'],
            ':amount' => $payAmount,
            ':debit'  => $bankChart['accno'],
            ':credit' => $arChart['accno'],
            ':invnr'  => $inv['invnumber'],
            ':desc'   => 'Zahlungseingang ' . $inv['customer_name'] . ' - RE ' . $inv['invnumber'],
            ':ref'    => $bookingRef['ref'],
            ':cid'    => intval($inv['customer_id']),
            ':arid'   => $invId,
            ':txid'   => $txId,
            ':eid'    => intval($_SESSION['employee_id'] ?? 0)
        ]);

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        throw new ApiError('BOOKING_ERROR', 'Buchung fehlgeschlagen: ' . $e->getMessage());
    }

    $openAfter = round(floatval($inv['amount']) - floatval($inv['paid']) - $payAmount, 2);
    resultInfo(true, 'Zahlungseingang gebucht', [
        'transaction_id' => $txId,
        'invoice_id'     => $invId,
        'invoice_number' => $inv['invnumber'],
        'paid'           => $payAmount,
        'open_after'     => $openAfter
    ]);
}

/**
 * Alle ungematchten Eingaenge mit moeglichen AR-Matches anzeigen (Vorschau)
 *
 * @testdata {}
 */
function getOutgoingMatchSuggestions($data) {
    // Delegiert an matchOutgoingInvoices mit Preview-Logik
    matchOutgoingInvoices($data);
}
