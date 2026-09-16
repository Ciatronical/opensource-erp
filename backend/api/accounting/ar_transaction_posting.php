<?php
// backend/api/accounting/ar_transaction_posting.php
//
// Debitorenbuchung (AR-Transaktion): Forderung an einen Kunden OHNE Positionen
// buchen – das Gegenstück zur manuellen Eingangsrechnung. Entsteht als echte
// k9o-ar (invoice=false) mit acc_trans und ist damit über Bank/Kasse zahlbar,
// erscheint in Offenen Posten und in der USt-Voranmeldung.
//
// Buchungssatz (k9o-Konvention Soll = negativ, Haben = positiv):
//   Forderungskonto   amount = -brutto  (Soll,  chart_link 'AR',        taxkey 0)
//   Erlöskonto        amount = +netto   (Haben, chart_link 'AR_amount…', taxkey/tax_id)
//   Umsatzsteuerkonto amount = +steuer  (Haben, chart_link 'AR_tax…',    taxkey/tax_id) [nur falls Steuer > 0]

// ── Steuer-/Konten-Ermittlung ────────────────────────────────────────────────

/**
 * Standard-Inland-Umsatzsteuer zu einem Steuersatz ermitteln (taxkey, tax_id, Konto).
 * Liefert für 0 % leere Werte (keine Steuerzeile).
 *
 * @param float $rate Steuersatz als Bruchteil (0.19) ODER Prozent (19) – beides erlaubt.
 */
function _ar_resolveTax($db, $rate) {
    $ratePct = floatval($rate);
    if ($ratePct > 0 && $ratePct < 1) $ratePct *= 100;   // 0.19 → 19
    $ratePct = (int) round($ratePct);

    $empty = ['tax_id' => 0, 'taxkey' => 0, 'ust_chart_id' => null, 'ust_link' => null, 'rate_pct' => $ratePct];
    if ($ratePct <= 0) return $empty;

    // Standard-Inlandsumsatzsteuer: chart.link 'AR_tax…' und taxdescription = 'Umsatzsteuer'.
    // EG-Lieferungen (andere taxdescription) werden so ausgeschlossen.
    $sql = "
        SELECT t.id AS tax_id, t.taxkey, t.chart_id AS ust_chart_id, ch.link AS ust_link
        FROM tax t
        JOIN chart ch ON ch.id = t.chart_id
        WHERE ch.link LIKE 'AR_tax%'
          AND round(t.rate * 100) = :rp
          %s
        ORDER BY t.taxkey
        LIMIT 1";
    $row = $db->getOne(sprintf($sql, "AND lower(t.taxdescription) = 'umsatzsteuer'"), [':rp' => $ratePct]);
    if (!$row) $row = $db->getOne(sprintf($sql, ''), [':rp' => $ratePct]);
    if (!$row) return $empty;

    return [
        'tax_id'       => intval($row['tax_id']),
        'taxkey'       => intval($row['taxkey']),
        'ust_chart_id' => intval($row['ust_chart_id']),
        'ust_link'     => $row['ust_link'],
        'rate_pct'     => $ratePct,
    ];
}

/**
 * Standard-Forderungskonto (Debitoren-Sammelkonto, chart.link = 'AR', kleinste Nr.)
 */
function _ar_arAccount($db) {
    return $db->getOne("SELECT id, accno, link FROM chart WHERE link = 'AR' ORDER BY accno ASC LIMIT 1");
}

// ── API ──────────────────────────────────────────────────────────────────────

/**
 * Debitorenbuchung buchen – Forderung ohne Positionen als echte ar + acc_trans.
 *
 * Netto ist Pflicht; Steuer und Brutto folgen aus dem Satz, falls nicht übergeben.
 * Steuerzone und Währung kommen vom Kunden.
 *
 * @param int    $data['customer_id']      Kunde (Pflicht)
 * @param int    $data['income_chart_id']  Erlöskonto (Pflicht)
 * @param string $data['invnumber']        Belegnummer (Pflicht)
 * @param string $data['transdate']        Belegdatum (optional, Standard heute)
 * @param string $data['duedate']          Fälligkeit (optional, Standard = Belegdatum)
 * @param float  $data['net']              Nettobetrag (Pflicht)
 * @param float  $data['rate']             Steuersatz 19/7/0 (optional)
 * @param float  $data['tax']              Steuerbetrag (optional)
 * @param float  $data['gross']            Bruttobetrag (optional)
 * @param string $data['notes']            Buchungstext (optional)
 * @testdata {"customer_id": 1, "income_chart_id": 1, "invnumber": "DB-2026-001", "net": 100, "rate": 19}
 */
function postArTransaction($data) {
    $db = DbhCompany::begin();

    $customerId = intval($data['customer_id'] ?? 0);
    $incomeId   = intval($data['income_chart_id'] ?? 0);
    $invnumber  = trim($data['invnumber'] ?? '');
    $transdate  = !empty($data['transdate']) ? $data['transdate'] : date('Y-m-d');
    $duedate    = !empty($data['duedate']) ? $data['duedate'] : $transdate;
    $notes      = trim($data['notes'] ?? '') ?: null;

    if ($customerId <= 0) throw new ApiError('VALIDATION_ERROR', 'customer_id fehlt');
    if ($incomeId <= 0)   throw new ApiError('VALIDATION_ERROR', 'income_chart_id (Erlöskonto) fehlt');
    if ($invnumber === '') throw new ApiError('VALIDATION_ERROR', 'invnumber (Belegnummer) fehlt');

    $net   = isset($data['net'])   ? round(floatval($data['net']), 2)   : null;
    $tax   = isset($data['tax'])   ? round(floatval($data['tax']), 2)   : null;
    $gross = isset($data['gross']) ? round(floatval($data['gross']), 2) : null;

    if ($net === null && $gross !== null && $tax !== null) $net = round($gross - $tax, 2);
    if ($net === null || $net <= 0) throw new ApiError('VALIDATION_ERROR', 'Nettobetrag muss größer als 0 sein');

    $taxInfo = _ar_resolveTax($db, $data['rate'] ?? 0);
    if ($tax === null) $tax = $taxInfo['rate_pct'] > 0 ? round($net * $taxInfo['rate_pct'] / 100, 2) : 0.0;
    if ($gross === null) $gross = round($net + $tax, 2);
    if ($tax > 0 && $taxInfo['rate_pct'] == 0) {
        // Steuer übergeben, Satz nicht: Satz aus net/tax rückrechnen
        $taxInfo = _ar_resolveTax($db, round($tax / $net * 100));
    }
    if ($tax > 0 && !$taxInfo['ust_chart_id']) {
        throw new ApiError('DATA_ERROR', 'Kein Umsatzsteuerkonto für ' . $taxInfo['rate_pct'] . ' % im Kontenrahmen');
    }

    $income = $db->getOne("SELECT id, link FROM chart WHERE id = :id AND NOT invalid", [':id' => $incomeId]);
    if (!$income) throw new ApiError('NOT_FOUND', 'Erlöskonto nicht gefunden');

    $arAcc = _ar_arAccount($db);
    if (!$arAcc) throw new ApiError('DATA_ERROR', 'Forderungskonto (AR) nicht im Kontenrahmen');

    $customer = $db->getOne(
        "SELECT c.id, c.name,
                COALESCE(c.taxzone_id, (SELECT min(id) FROM tax_zones)) AS taxzone_id,
                COALESCE(c.currency_id, (SELECT currency_id FROM defaults LIMIT 1), 1) AS currency_id
         FROM customer c WHERE c.id = :id",
        [':id' => $customerId]
    );
    if (!$customer) throw new ApiError('NOT_FOUND', 'Kunde nicht gefunden');

    $employeeId = mitarbeiterId($data);
    if (!$employeeId) {
        $emp = $db->getOne("SELECT id FROM employee ORDER BY id LIMIT 1");
        $employeeId = $emp['id'] ?? null;
    }

    $db->beginTransaction();
    try {
        // ── ar-Kopf (invoice=false → Debitorenbuchung ohne Positionen) ──
        $arRow = $db->getOne(
            "INSERT INTO ar (invnumber, transdate, gldate, duedate, customer_id, amount, netamount,
                             paid, taxincluded, invoice, notes, employee_id, taxzone_id, currency_id)
             VALUES (:invnumber, :transdate, :transdate, :duedate, :customer_id, :amount, :netamount,
                     0, false, false, :notes, :eid, :taxzone, :currency)
             RETURNING id",
            [
                ':invnumber'   => $invnumber,
                ':transdate'   => $transdate,
                ':duedate'     => $duedate,
                ':customer_id' => $customerId,
                ':amount'      => $gross,
                ':netamount'   => $net,
                ':notes'       => $notes,
                ':eid'         => $employeeId,
                ':taxzone'     => intval($customer['taxzone_id']),
                ':currency'    => intval($customer['currency_id']),
            ]
        );
        $arId = intval($arRow['id']);

        $insTrans = function ($chartId, $amount, $chartLink, $taxkey, $taxId) use ($db, $arId, $transdate) {
            $db->execute(
                "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, taxkey, tax_id)
                 VALUES (:tid, :cid, :amount, :td, :td, '', '', :clink, :tk, :taxid)",
                [':tid' => $arId, ':cid' => $chartId, ':amount' => $amount, ':td' => $transdate,
                 ':clink' => $chartLink, ':tk' => $taxkey, ':taxid' => $taxId]
            );
        };

        // Erlös (Haben, positiv) – trägt taxkey/tax_id
        $insTrans($incomeId, $net, $income['link'] ?: 'AR_amount', $taxInfo['taxkey'], $taxInfo['tax_id']);

        // Umsatzsteuer (Haben, positiv) – nur falls Steuer > 0
        if ($tax > 0) {
            $insTrans($taxInfo['ust_chart_id'], $tax, $taxInfo['ust_link'], $taxInfo['taxkey'], $taxInfo['tax_id']);
        }

        // Forderung (Soll, negativ) – kein taxkey
        $insTrans(intval($arAcc['id']), -$gross, 'AR', 0, 0);

        $db->commit();
    } catch (\Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }

    resultInfo(true, '', ['results' => [
        'ar_id'     => $arId,
        'invnumber' => $invnumber,
        'customer'  => $customer['name'],
        'net'       => $net,
        'tax'       => $tax,
        'gross'     => $gross,
    ]]);
}

/**
 * Zuletzt gebuchte Debitorenbuchungen (ar ohne Positionen) mit Zahlungsstatus
 *
 * @param int $data['limit'] Anzahl (Standard: 50)
 * @testdata {"limit": 50}
 */
function getArTransactions($data) {
    $db = DbhCompany::begin();
    $limit = intval($data['limit'] ?? 50);

    $rows = $db->getAll(<<<SQL
        SELECT a.id, a.invnumber, a.amount, COALESCE(a.paid, 0) AS paid,
               (a.amount - COALESCE(a.paid, 0)) AS open_amount,
               TO_CHAR(a.transdate, 'DD.MM.YYYY') AS transdate_fmt,
               TO_CHAR(a.duedate, 'DD.MM.YYYY') AS duedate_fmt,
               c.name AS customer_name
        FROM ar a
        LEFT JOIN customer c ON c.id = a.customer_id
        WHERE a.invoice IS NOT TRUE
        ORDER BY a.transdate DESC, a.id DESC
        LIMIT :limit
    SQL, [':limit' => $limit]);

    resultInfo(true, '', ['results' => $rows ?: []]);
}

/**
 * Kunden suchen (Typeahead für die Debitorenbuchung) – per Suchbegriff oder direkt per ID
 *
 * @param string $data['query'] Suchbegriff (Name oder Kundennummer)
 * @param int    $data['id']    Optional: genau diesen Kunden liefern (Vorauswahl aus dem Workflow)
 * @testdata {"query": "muster"}
 */
function searchArCustomers($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id > 0) {
        $rows = $db->getAll("SELECT id, name, customernumber FROM customer WHERE id = :id", [':id' => $id]);
        resultInfo(true, '', ['results' => $rows ?: []]);
        return;
    }

    $q = trim($data['query'] ?? '');
    if (mb_strlen($q) < 2) { resultInfo(true, '', ['results' => []]); return; }

    $rows = $db->getAll(
        "SELECT id, name, customernumber
         FROM customer
         WHERE obsolete IS NOT TRUE AND (name ILIKE :q OR customernumber ILIKE :q)
         ORDER BY name LIMIT 20",
        [':q' => '%' . $q . '%']
    );

    resultInfo(true, '', ['results' => $rows ?: []]);
}
