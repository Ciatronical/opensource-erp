<?php
// backend/api/accounting/outgoing_matching.php

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
        ORDER BY ar.transdate DESC
        LIMIT 500
    SQL, []);

    $matches = [];
    $matchCount = 0;

    foreach ($transactions as $tx) {
        $txAmount = floatval($tx['amount']);
        $purpose = strtolower($tx['purpose'] ?? '');
        $bestMatch = null;
        $bestConfidence = 0;

        foreach ($openInvoices as $inv) {
            $openAmount = floatval($inv['open_amount']);
            $confidence = 0;
            $matchReason = '';

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

            // 2. Exakter Betragsabgleich + IBAN
            if ($confidence < 0.85 && abs($txAmount - $openAmount) < 0.01) {
                $txIban = strtolower(str_replace(' ', '', $tx['remote_iban'] ?? ''));
                $custIban = strtolower(str_replace(' ', '', $inv['customer_iban'] ?? ''));

                if (!empty($txIban) && !empty($custIban) && $txIban === $custIban) {
                    $confidence = 0.90;
                    $matchReason = 'Betrag + IBAN stimmen ueberein';
                } else {
                    // Nur Betrag
                    $confidence = 0.60;
                    $matchReason = 'Betrag stimmt ueberein';
                }
            }

            // 3. Betragsabgleich mit Skonto-Toleranz (2-3%)
            if ($confidence < 0.60) {
                $skontoDiff = $openAmount - $txAmount;
                $skontoPercent = ($openAmount > 0) ? ($skontoDiff / $openAmount * 100) : 0;

                if ($skontoPercent >= 1.5 && $skontoPercent <= 3.5 && $txAmount > 10) {
                    $confidence = 0.50;
                    $matchReason = 'Moeglicherweise Skonto (' . round($skontoPercent, 1) . '%)';
                }
            }

            if ($confidence > $bestConfidence) {
                $bestConfidence = $confidence;
                $bestMatch = [
                    'transaction_id' => intval($tx['id']),
                    'invoice_id'     => intval($inv['id']),
                    'invoice_number' => $inv['invnumber'],
                    'customer_name'  => $inv['customer_name'],
                    'tx_amount'      => $txAmount,
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

    resultInfo(true, '', [
        'matches'      => $matches,
        'match_count'  => $matchCount,
        'total_transactions' => count($transactions),
        'total_open_invoices' => count($openInvoices)
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
        "SELECT id, amount, purpose, transdate FROM bank_transactions WHERE id = :id",
        [':id' => $txId]
    );
    if (!$tx) throw new ApiError('DATA_NOT_FOUND', 'Banktransaktion nicht gefunden');

    $inv = $db->getOne(
        "SELECT ar.id, ar.invnumber, ar.amount, ar.paid, ar.customer_id, c.name AS customer_name
         FROM ar JOIN customer c ON c.id = ar.customer_id
         WHERE ar.id = :id",
        [':id' => $invId]
    );
    if (!$inv) throw new ApiError('DATA_NOT_FOUND', 'Rechnung nicht gefunden');

    $db->beginTransaction();

    try {
        // Bank-Transaktion als matched markieren
        $db->execute(
            "UPDATE bank_transactions SET match_status = 'matched' WHERE id = :id",
            [':id' => $txId]
        );

        // Verknuepfung erstellen
        $db->execute(
            "INSERT INTO bank_transaction_acc_trans (bank_transaction_id, ar_id) VALUES (:txid, :arid)",
            [':txid' => $txId, ':arid' => $invId]
        );

        // Buchungsnummer
        $bookingRef = $db->getOne("SELECT next_booking_number() AS ref", []);

        // Buchung erfassen
        $db->execute(<<<SQL
            INSERT INTO accounting_bookings
                (booking_date, invoice_date, amount, net_amount,
                 debit_account, credit_account, invoice_number, description, reference,
                 type, status, customer_id, ar_id, bank_transaction_id, ai_generated, employee_id)
            VALUES
                (:bdate, :idate, :amount, :amount,
                 '1200', '8400', :invnr, :desc, :ref,
                 'outgoing', 'approved', :cid, :arid, :txid, FALSE, :eid)
        SQL, [
            ':bdate'  => $tx['transdate'],
            ':idate'  => $tx['transdate'],
            ':amount' => floatval($tx['amount']),
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

    resultInfo(true, 'Zahlungseingang gebucht', [
        'transaction_id' => $txId,
        'invoice_id'     => $invId,
        'invoice_number' => $inv['invnumber']
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
