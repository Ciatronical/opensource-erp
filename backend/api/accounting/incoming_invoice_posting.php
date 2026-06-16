<?php
// backend/api/accounting/incoming_invoice_posting.php
//
// Phase 1: Aus extrahierten Belegdaten eine ECHTE kivitendo-Eingangsrechnung
// buchen (ap + acc_trans). Erst dadurch ist die Rechnung über Bank/Kasse
// zahlbar und erscheint in Offenen Posten / USt-Voranmeldung.
//
// Buchungssatz (kivitendo-Konvention Soll = negativ, Haben = positiv):
//   Aufwandskonto    amount = -netto   (Soll, chart_link 'AP_amount…', taxkey/tax_id)
//   Vorsteuerkonto   amount = -steuer  (Soll, chart_link 'AP_tax…',    taxkey/tax_id)   [nur falls Steuer > 0]
//   Verbindlichkeit  amount = +brutto  (Haben, chart_link 'AP',        taxkey 0)
//
// Lieferanten-Regeln (Sammelkreditor + 90%-Schwelle) siehe _iv_resolveVendor().

// ── Steuer-/Konten-Ermittlung ────────────────────────────────────────────────

/**
 * Standard-Inland-Vorsteuer zu einem Steuersatz ermitteln (taxkey, tax_id, Konto).
 * Liefert für 0 % leere Werte (keine Vorsteuerzeile).
 *
 * @param float $rate Steuersatz als Bruchteil (0.19) ODER Prozent (19) – beides erlaubt.
 */
function _iv_resolveTax($db, $rate) {
    $ratePct = floatval($rate);
    if ($ratePct > 0 && $ratePct < 1) $ratePct *= 100;   // 0.19 → 19
    $ratePct = (int) round($ratePct);

    if ($ratePct <= 0) {
        return ['tax_id' => 0, 'taxkey' => 0, 'vorsteuer_chart_id' => null, 'vorsteuer_link' => null, 'rate_pct' => 0];
    }

    // Standard-Inlandsvorsteuer: chart.link 'AP_tax…' und taxdescription = 'Vorsteuer'.
    // Innergemeinschaftliche/§13b-Schlüssel (andere taxdescription) werden so ausgeschlossen.
    $row = $db->getOne("
        SELECT t.id AS tax_id, t.taxkey, t.chart_id AS vorsteuer_chart_id, ch.link AS vorsteuer_link
        FROM tax t
        JOIN chart ch ON ch.id = t.chart_id
        WHERE ch.link LIKE 'AP_tax%'
          AND lower(t.taxdescription) = 'vorsteuer'
          AND round(t.rate * 100) = :rp
        ORDER BY t.taxkey
        LIMIT 1
    ", [':rp' => $ratePct]);

    // Fallback: irgendein AP_tax-Schlüssel mit passendem Satz.
    if (!$row) {
        $row = $db->getOne("
            SELECT t.id AS tax_id, t.taxkey, t.chart_id AS vorsteuer_chart_id, ch.link AS vorsteuer_link
            FROM tax t JOIN chart ch ON ch.id = t.chart_id
            WHERE ch.link LIKE 'AP_tax%' AND round(t.rate * 100) = :rp
            ORDER BY t.taxkey LIMIT 1
        ", [':rp' => $ratePct]);
    }

    if (!$row) {
        return ['tax_id' => 0, 'taxkey' => 0, 'vorsteuer_chart_id' => null, 'vorsteuer_link' => null, 'rate_pct' => $ratePct];
    }

    return [
        'tax_id'             => intval($row['tax_id']),
        'taxkey'             => intval($row['taxkey']),
        'vorsteuer_chart_id' => intval($row['vorsteuer_chart_id']),
        'vorsteuer_link'     => $row['vorsteuer_link'],
        'rate_pct'           => $ratePct,
    ];
}

/**
 * Standard-Verbindlichkeitskonto (Kreditoren-Sammelkonto, chart.link = 'AP', kleinste Nr.)
 */
function _iv_apAccount($db) {
    return $db->getOne("SELECT id, accno, link FROM chart WHERE link = 'AP' ORDER BY accno ASC LIMIT 1");
}

// ── Eingangsrechnung verbuchen (ap + acc_trans) ───────────────────────────────

/**
 * Bucht eine Eingangsrechnung als echte kivitendo-ap (intern, ohne resultInfo).
 *
 * @param array $p {
 *   vendor_id, invnumber, transdate, duedate (optional),
 *   net, tax (optional), gross (optional), rate (Satz, optional wenn tax+net da),
 *   expense_chart_id, notes (optional), employee_id (optional)
 * }
 * @return int Neue ap.id
 * @throws ApiError bei fehlenden Pflichtangaben/Konten.
 */
function _iv_postAp($db, array $p) {
    $vendorId   = intval($p['vendor_id'] ?? 0);
    $expenseId  = intval($p['expense_chart_id'] ?? 0);
    $invnumber  = trim($p['invnumber'] ?? '');
    $transdate  = $p['transdate'] ?? date('Y-m-d');
    $duedate    = !empty($p['duedate']) ? $p['duedate'] : $transdate;
    $notes      = trim($p['notes'] ?? '') ?: null;

    if ($vendorId <= 0)  throw new ApiError('VALIDATION_ERROR', 'vendor_id fehlt');
    if ($expenseId <= 0) throw new ApiError('VALIDATION_ERROR', 'expense_chart_id (Aufwandskonto) fehlt');
    if ($invnumber === '') throw new ApiError('VALIDATION_ERROR', 'invnumber (Rechnungsnummer) fehlt');

    // Beträge konsistent ableiten
    $net   = isset($p['net'])   ? round(floatval($p['net']), 2)   : null;
    $tax   = isset($p['tax'])   ? round(floatval($p['tax']), 2)   : null;
    $gross = isset($p['gross']) ? round(floatval($p['gross']), 2) : null;
    $rate  = $p['rate'] ?? null;

    if ($net === null && $gross !== null && $tax !== null) $net = round($gross - $tax, 2);
    if ($gross === null && $net !== null && $tax !== null) $gross = round($net + $tax, 2);
    if ($net === null) throw new ApiError('VALIDATION_ERROR', 'Betrag (net/gross/tax) unvollständig');

    // Steuer ggf. aus dem Satz rechnen
    $taxInfo = _iv_resolveTax($db, $rate ?? 0);
    if ($tax === null) {
        $tax   = $taxInfo['rate_pct'] > 0 ? round($net * $taxInfo['rate_pct'] / 100, 2) : 0.0;
    }
    if ($gross === null) $gross = round($net + $tax, 2);

    // Bei vorhandener Steuer aber rate=0 (nicht übergeben): Satz aus net/tax rückrechnen
    if ($tax > 0 && $taxInfo['rate_pct'] == 0 && $net > 0) {
        $taxInfo = _iv_resolveTax($db, round($tax / $net * 100));
    }

    $expense = $db->getOne("SELECT id, link FROM chart WHERE id = :id", [':id' => $expenseId]);
    if (!$expense) throw new ApiError('NOT_FOUND', 'Aufwandskonto nicht gefunden');

    $apAcc = _iv_apAccount($db);
    if (!$apAcc) throw new ApiError('DATA_ERROR', 'Verbindlichkeitskonto (AP) nicht im Kontenrahmen');

    $employeeId = intval($p['employee_id'] ?? ($_SESSION['employee_id'] ?? 0)) ?: null;
    if (!$employeeId) {
        $emp = $db->getOne("SELECT id FROM employee ORDER BY id LIMIT 1");
        $employeeId = $emp['id'] ?? null;
    }
    $taxzone = $db->getOne("SELECT taxzone_id FROM vendor WHERE id = :id", [':id' => $vendorId]);
    $taxzoneId = intval($taxzone['taxzone_id'] ?? 0) ?: null;

    // ── ap-Kopf ──
    $apRow = $db->getOne(
        "INSERT INTO ap (invnumber, transdate, gldate, duedate, vendor_id, amount, netamount,
                         paid, taxincluded, invoice, type, notes, employee_id, taxzone_id, currency_id)
         VALUES (:invnumber, :transdate, :transdate, :duedate, :vendor_id, :amount, :netamount,
                 0, true, true, 'invoice', :notes, :eid, :taxzone, 1)
         RETURNING id",
        [
            ':invnumber' => $invnumber,
            ':transdate' => $transdate,
            ':duedate'   => $duedate,
            ':vendor_id' => $vendorId,
            ':amount'    => $gross,
            ':netamount' => $net,
            ':notes'     => $notes,
            ':eid'       => $employeeId,
            ':taxzone'   => $taxzoneId,
        ]
    );
    $apId = intval($apRow['id']);

    $insTrans = function ($chartId, $amount, $chartLink, $taxkey, $taxId) use ($db, $apId, $transdate) {
        $db->execute(
            "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, taxkey, tax_id)
             VALUES (:tid, :cid, :amount, :td, :td, '', '', :clink, :tk, :taxid)",
            [':tid' => $apId, ':cid' => $chartId, ':amount' => $amount, ':td' => $transdate,
             ':clink' => $chartLink, ':tk' => $taxkey, ':taxid' => $taxId]
        );
    };

    // Aufwand (Soll, negativ) – trägt taxkey/tax_id
    $insTrans($expenseId, -$net, $expense['link'] ?: 'AP_amount', $taxInfo['taxkey'], $taxInfo['tax_id']);

    // Vorsteuer (Soll, negativ) – nur falls Steuer > 0
    if ($tax > 0 && $taxInfo['vorsteuer_chart_id']) {
        $insTrans($taxInfo['vorsteuer_chart_id'], -$tax, $taxInfo['vorsteuer_link'], $taxInfo['taxkey'], $taxInfo['tax_id']);
    }

    // Verbindlichkeit (Haben, positiv) – kein taxkey
    $insTrans(intval($apAcc['id']), $gross, 'AP', 0, 0);

    return $apId;
}

// ── Lieferanten-Auflösung (Sammelkreditor + 90%-Schwelle) ─────────────────────

const IV_VENDOR_AUTO_THRESHOLD      = 0.90;  // Name ≥ 90 % → automatisch zuordnen
const IV_VENDOR_CANDIDATE_THRESHOLD = 0.60;  // 60–90 % → Freigabe mit Kandidaten
const IV_COLLECTIVE_MAX_AMOUNT      = 250.0;  // Kleinbeträge ohne IBAN/USt → Sammelkreditor

/**
 * Sammelkreditor „Diverse Lieferanten" finden oder anlegen.
 * Für Kleinbeleg-/Bargeschäfte ohne dauerhafte Lieferantenbeziehung
 * (z. B. Tankstelle, Parken) – verhindert einen Kreditor pro Beleg.
 */
function _iv_diverseVendor($db) {
    $v = $db->getOne("
        SELECT id, name FROM vendor
        WHERE obsolete IS NOT TRUE
          AND lower(name) IN ('diverse','diverse lieferanten','sammelkreditor','barlieferant','diverse kreditoren')
        ORDER BY id LIMIT 1
    ");
    if ($v) return ['vendor_id' => intval($v['id']), 'vendor_name' => $v['name']];

    $nr = $db->getOne("SELECT COALESCE(MAX(CAST(vendornumber AS INTEGER)),70000)+1 AS n FROM vendor WHERE vendornumber ~ '^\\d+$'");
    $db->execute(
        "INSERT INTO vendor (name, country, vendornumber, taxzone_id, currency_id)
         VALUES ('Diverse Lieferanten', 'DE', :nr, 1, 1)",
        [':nr' => $nr['n']]
    );
    $v = $db->getOne("SELECT id, name FROM vendor WHERE vendornumber = :nr", [':nr' => $nr['n']]);
    return ['vendor_id' => intval($v['id']), 'vendor_name' => $v['name'], 'created' => true];
}

/**
 * Lieferanten zu Belegdaten auflösen – nach den festgelegten Regeln:
 *  - IBAN/USt-ID exakt  → automatisch zuordnen (status 'matched')
 *  - Name ≥ 90 %        → automatisch zuordnen + abweichende Schreibweise als Alias lernen
 *  - Name 60–90 %       → 'ambiguous' (Freigabe mit Kandidaten – NICHT still zuordnen/anlegen)
 *  - kein Treffer:
 *      • mit IBAN/USt    → 'new' (echten Kreditor anlegen, dauerhafte Beziehung)
 *      • sonst/Kleinbetrag → 'collective' (Sammelkreditor „Diverse", echter Name im Buchungstext)
 *
 * @param array $vendorData {name, iban, taxnumber/ustid, ...}
 * @param float $gross      Bruttobetrag (für Kleinbetrags-Entscheidung)
 * @return array {status, vendor_id, vendor_name, match_score, candidates[], is_collective, original_name}
 */
function _iv_resolveVendor($db, array $vendorData, $gross = 0.0) {
    $name = trim($vendorData['name'] ?? '');
    $iban = trim($vendorData['iban'] ?? '');
    $tax  = trim($vendorData['taxnumber'] ?? $vendorData['ustid'] ?? '');
    $hasStrongId = ($iban !== '' || $tax !== '');

    $matches = [];
    if ($name !== '' || $iban !== '' || $tax !== '') {
        $matches = $db->getAll(
            "SELECT * FROM find_vendor_fuzzy(:name, :iban, :tax)",
            [':name' => $name ?: ' ', ':iban' => $iban ?: null, ':tax' => $tax ?: null]
        );
    }
    $best  = $matches[0] ?? null;
    $score = $best ? floatval($best['match_score']) : 0.0;
    $type  = $best['match_type'] ?? '';

    // 1) Sicherer Treffer: IBAN/USt-ID exakt ODER Name ≥ 90 %
    $strongMatch = in_array($type, ['iban_exact', 'iban_alias', 'taxnumber'], true);
    if ($best && ($strongMatch || $score >= IV_VENDOR_AUTO_THRESHOLD)) {
        $vid = intval($best['vendor_id']);
        // abweichende Schreibweise lernen
        if ($name !== '' && $type === 'fuzzy_name' && $score < 0.999) {
            $exists = $db->getOne("SELECT id FROM vendor_aliases WHERE vendor_id=:v AND lower(alias_name)=lower(:n)", [':v'=>$vid, ':n'=>$name]);
            if (!$exists) $db->execute("INSERT INTO vendor_aliases (vendor_id, alias_name, alias_iban) VALUES (:v,:n,:i)", [':v'=>$vid, ':n'=>$name, ':i'=>$iban?:null]);
        }
        return ['status'=>'matched','vendor_id'=>$vid,'vendor_name'=>$best['vendor_name'],'match_score'=>$score,'candidates'=>[],'is_collective'=>false,'original_name'=>$name];
    }

    // 2) Unsicher: 60–90 % Name → Freigabe mit Kandidaten
    if ($best && $score >= IV_VENDOR_CANDIDATE_THRESHOLD) {
        $cands = array_map(fn($m) => [
            'vendor_id' => intval($m['vendor_id']), 'vendor_name' => $m['vendor_name'],
            'match_score' => round(floatval($m['match_score']), 2), 'match_type' => $m['match_type'],
        ], array_slice($matches, 0, 5));
        return ['status'=>'ambiguous','vendor_id'=>null,'vendor_name'=>$name,'match_score'=>$score,'candidates'=>$cands,'is_collective'=>false,'original_name'=>$name];
    }

    // 3) Kein Treffer: echter Kreditor nur bei IBAN/USt-ID, sonst Sammelkreditor
    if ($hasStrongId && $name !== '') {
        return ['status'=>'new','vendor_id'=>null,'vendor_name'=>$name,'match_score'=>0.0,'candidates'=>[],'is_collective'=>false,'original_name'=>$name];
    }

    $diverse = _iv_diverseVendor($db);
    return ['status'=>'collective','vendor_id'=>$diverse['vendor_id'],'vendor_name'=>$diverse['vendor_name'],
            'match_score'=>0.0,'candidates'=>[],'is_collective'=>true,'original_name'=>$name];
}

/**
 * Echten Kreditor aus Belegdaten anlegen (für status 'new').
 */
function _iv_createVendor($db, array $vendorData) {
    $nr = $db->getOne("SELECT COALESCE(MAX(CAST(vendornumber AS INTEGER)),70000)+1 AS n FROM vendor WHERE vendornumber ~ '^\\d+$'");
    $db->execute(
        "INSERT INTO vendor (name, street, zipcode, city, country, taxnumber, ustid, iban, bic, phone, email, vendornumber, taxzone_id, currency_id)
         VALUES (:name,:street,:zip,:city,:country,:tax,:ustid,:iban,:bic,:phone,:email,:nr,1,1)",
        [
            ':name'=>trim($vendorData['name']), ':street'=>$vendorData['street']??null, ':zip'=>$vendorData['zipcode']??null,
            ':city'=>$vendorData['city']??null, ':country'=>$vendorData['country']??'DE', ':tax'=>$vendorData['taxnumber']??null,
            ':ustid'=>$vendorData['ustid']??null, ':iban'=>trim($vendorData['iban']??'')?:null, ':bic'=>$vendorData['bic']??null,
            ':phone'=>$vendorData['phone']??null, ':email'=>$vendorData['email']??null, ':nr'=>$nr['n'],
        ]
    );
    $v = $db->getOne("SELECT id, name FROM vendor WHERE vendornumber=:nr", [':nr'=>$nr['n']]);
    return intval($v['id']);
}

// ── Orchestrierung: Eingangsrechnung verbuchen (API) ──────────────────────────

/**
 * Eingangsrechnung buchen – führt Lieferanten-Auflösung + Buchung zusammen.
 *
 * Ist die Lieferantenzuordnung unsicher (status 'ambiguous') und wurde kein
 * vendor_id bestätigt, wird NICHT gebucht, sondern die Kandidaten zur Freigabe
 * zurückgegeben. Bei Sammelkreditor wird der echte Belegname in die notes/Buchungstext
 * geschrieben. Optional wird ein Beleg (accounting_documents) verknüpft.
 *
 * @param int    $data['vendor_id']        bestätigter Lieferant (optional; sonst Auflösung)
 * @param array  $data['vendor']           Belegdaten des Lieferanten {name,iban,ustid,...}
 * @param string $data['invnumber']        Rechnungsnummer (Pflicht)
 * @param string $data['transdate']        Belegdatum (optional)
 * @param string $data['duedate']          Fälligkeit (optional)
 * @param float  $data['net']              Nettobetrag
 * @param float  $data['tax']              Steuerbetrag (optional)
 * @param float  $data['gross']            Bruttobetrag (optional)
 * @param float  $data['rate']             Steuersatz 19/7/0 (optional)
 * @param int    $data['expense_chart_id'] Aufwandskonto (Pflicht)
 * @param int    $data['document_id']      Beleg-ID (optional)
 * @testdata {"vendor":{"name":"Baumarkt Rehfelde"},"invnumber":"ER-2026-001","net":100,"rate":19,"expense_chart_id":1}
 */
function postIncomingInvoice($data) {
    $db = DbhCompany::begin();

    // Beträge wie übergeben lassen (gross darf null sein → _iv_postAp leitet aus rate ab).
    $gross = isset($data['gross']) ? floatval($data['gross']) : null;
    $net   = isset($data['net'])   ? floatval($data['net'])   : null;
    $tax   = isset($data['tax'])   ? floatval($data['tax'])   : null;
    $rate  = $data['rate'] ?? null;

    // Nur für die Kleinbetrags-Entscheidung (Sammelkreditor) einen Brutto schätzen.
    $ratePct = floatval($rate ?? 0); if ($ratePct > 0 && $ratePct < 1) $ratePct *= 100;
    $grossForDecision = $gross
        ?? (($net !== null && $tax !== null) ? $net + $tax
            : (($net !== null) ? round($net * (1 + $ratePct / 100), 2) : 0.0));

    // Lieferant: bestätigte ID hat Vorrang, sonst auflösen
    $vendorId   = intval($data['vendor_id'] ?? 0);
    $vendorData = $data['vendor'] ?? ['name' => $data['vendor_name'] ?? ''];
    $notes      = null;

    if ($vendorId <= 0) {
        $res = _iv_resolveVendor($db, $vendorData, $grossForDecision);
        if ($res['status'] === 'ambiguous') {
            resultInfo(false, 'VENDOR_AMBIGUOUS', [
                'message'    => 'Lieferant nicht eindeutig – bitte zuordnen',
                'candidates' => $res['candidates'],
                'original_name' => $res['original_name'],
            ]);
            return;
        }
        if ($res['status'] === 'new') {
            $vendorId = _iv_createVendor($db, $vendorData);
        } else {
            $vendorId = intval($res['vendor_id']);
        }
        // Sammelkreditor: echten Namen in den Buchungstext
        if (!empty($res['is_collective']) && !empty($res['original_name'])) {
            $notes = $res['original_name'];
        }
    }

    try {
        $apId = _iv_postAp($db, [
            'vendor_id'        => $vendorId,
            'expense_chart_id' => intval($data['expense_chart_id'] ?? 0),
            'invnumber'        => $data['invnumber'] ?? '',
            'transdate'        => $data['transdate'] ?? date('Y-m-d'),
            'duedate'          => $data['duedate'] ?? null,
            'net'              => $net,
            'tax'              => $tax,
            'gross'            => $gross,
            'rate'             => $data['rate'] ?? null,
            'notes'            => $notes ?? ($data['notes'] ?? null),
        ]);
    } catch (ApiError $e) {
        resultInfo(false, $e->getMessage());
        return;
    }

    // Beleg revisionssicher mit der Buchung verknüpfen
    $docId = intval($data['document_id'] ?? 0);
    if ($docId > 0) {
        $hasApId = $db->getOne("SELECT 1 FROM information_schema.columns WHERE table_name='accounting_documents' AND column_name='ap_id' LIMIT 1");
        if ($hasApId) {
            $db->execute("UPDATE accounting_documents SET ap_id=:ap, status='booked', mtime=NOW() WHERE id=:id", [':ap'=>$apId, ':id'=>$docId]);
        }
    }

    resultInfo(true, 'OK', ['ap_id' => $apId, 'vendor_id' => $vendorId]);
}

/**
 * Aus einer Staging-Buchung (accounting_bookings) die echte Eingangsrechnung (ap)
 * buchen und verknüpfen. Idempotent: ist ap_id schon gesetzt, passiert nichts.
 *
 * @param int   $bookingId accounting_bookings.id
 * @param array $opts      ['vendor_id' => override, 'debit_account' => override-accno]
 * @return int|null        ap.id (oder null wenn bereits gebucht)
 * @throws ApiError bei fehlendem Lieferanten/Konto.
 */
function _iv_postBooking($db, $bookingId, array $opts = []) {
    $b = $db->getOne(
        "SELECT id, vendor_id, ap_id, status, amount AS gross, net_amount AS net, tax_amount AS tax,
                tax_rate, debit_account, invoice_number, invoice_date, due_date, document_id, description, reference
         FROM accounting_bookings WHERE id = :id AND type = 'incoming'",
        [':id' => intval($bookingId)]
    );
    if (!$b)              throw new ApiError('NOT_FOUND', 'Buchung nicht gefunden');
    if (!empty($b['ap_id'])) return null;                 // schon gebucht

    $vendorId = intval($opts['vendor_id'] ?? $b['vendor_id'] ?? 0);
    if ($vendorId <= 0)  throw new ApiError('VENDOR_REQUIRED', 'Kein Lieferant zugeordnet');

    $debitAccno = trim($opts['debit_account'] ?? $b['debit_account'] ?? '');
    $expense = $debitAccno !== '' ? $db->getOne("SELECT id FROM chart WHERE accno = :a", [':a' => $debitAccno]) : null;
    if (!$expense)       throw new ApiError('ACCOUNT_REQUIRED', 'Aufwandskonto (' . $debitAccno . ') nicht im Kontenrahmen');

    $apId = _iv_postAp($db, [
        'vendor_id'        => $vendorId,
        'expense_chart_id' => intval($expense['id']),
        'invnumber'        => $b['invoice_number'] ?: $b['reference'] ?: ('ER-' . $bookingId),
        'transdate'        => $b['invoice_date'] ?: date('Y-m-d'),
        'duedate'          => $b['due_date'] ?: null,
        'net'              => $b['net']   !== null ? floatval($b['net'])   : null,
        'tax'              => $b['tax']   !== null ? floatval($b['tax'])   : null,
        'gross'            => $b['gross'] !== null ? floatval($b['gross']) : null,
        'rate'             => $b['tax_rate'],
        'notes'            => $b['description'],
    ]);

    // Staging-Buchung: ap verknüpfen, Status auf 'approved' (Hauptbuch gebucht, für DATEV bereit)
    $db->execute(
        "UPDATE accounting_bookings
         SET ap_id = :ap, status = CASE WHEN status = 'pending' THEN 'approved' ELSE status END, mtime = NOW()
         WHERE id = :id",
        [':ap' => $apId, ':id' => intval($bookingId)]
    );

    // Beleg revisionssicher mit der echten Rechnung verknüpfen
    if (!empty($b['document_id'])) {
        $hasApId = $db->getOne("SELECT 1 FROM information_schema.columns WHERE table_name='accounting_documents' AND column_name='ap_id' LIMIT 1");
        if ($hasApId) {
            $db->execute("UPDATE accounting_documents SET ap_id = :ap, status = 'booked', mtime = NOW() WHERE id = :id",
                [':ap' => $apId, ':id' => intval($b['document_id'])]);
        }
    }

    return $apId;
}
