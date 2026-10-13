<?php
// backend/api/accounting/incoming_invoice_posting.php
//
// Phase 1: Aus extrahierten Belegdaten eine ECHTE kivitendo-Eingangsrechnung
// buchen (ap + acc_trans). Erst dadurch ist die Rechnung über Bank/Kasse
// zahlbar und erscheint in Offenen Posten / USt-Voranmeldung.
//
// Buchungssatz (kivitendo-Konvention Soll = negativ, Haben = positiv), je Position
// wie in kivitendos AP.pm (Kreditorenbuchung mit mehreren Zeilen):
//   Aufwandskonto    amount = -netto   (Soll, chart_link 'AP_amount…', taxkey/tax_id)
//   Vorsteuerkonto   amount = -steuer  (Soll, chart_link 'AP_tax…',    taxkey/tax_id)   [nur falls Steuer > 0]
//   … weitere Positionen (anderes Konto und/oder anderer Steuersatz) …
//   Verbindlichkeit  amount = +brutto  (Haben, chart_link 'AP',        taxkey 0)         [einmal, Summe]
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
 * Positionen einer Eingangsrechnung normalisieren: Konto auflösen, Netto/Steuer/
 * Brutto konsistent ableiten, Steuerschlüssel je Satz ermitteln.
 *
 * Je Position reicht eine der Angaben gross (brutto) oder net; der Rest folgt
 * aus dem Satz. Mit net UND tax wird der Satz daraus zurückgerechnet, falls er
 * fehlt — so bleiben alte Aufrufe mit rate=0 bei vorhandener Steuer korrekt.
 *
 * @param array $lines [{expense_chart_id|debit_account, gross?, net?, tax?, rate?}]
 * @return array je Position: chart_id, link, net, tax, gross, taxInfo
 * @throws ApiError bei fehlendem Konto oder Betrag
 */
function _iv_normalizeLines($db, array $lines) {
    if (!$lines) throw new ApiError('VALIDATION_ERROR', 'Keine Positionen angegeben');

    // Alle Konten in einer Abfrage (per id oder Kontonummer)
    $ids = []; $accnos = [];
    foreach ($lines as $l) {
        $id = intval($l['expense_chart_id'] ?? 0);
        if ($id > 0) $ids[] = $id;
        elseif (trim($l['debit_account'] ?? '') !== '') $accnos[] = trim($l['debit_account']);
    }
    $charts = $db->getAll(
        "SELECT id, accno, link FROM chart
         WHERE id = ANY(:ids::int[]) OR accno = ANY(:accnos::text[])",
        [':ids' => '{' . implode(',', $ids) . '}',
         ':accnos' => '{' . implode(',', array_map(fn($a) => '"' . str_replace('"', '', $a) . '"', $accnos)) . '}']
    ) ?: [];
    $byId = []; $byAccno = [];
    foreach ($charts as $c) { $byId[intval($c['id'])] = $c; $byAccno[$c['accno']] = $c; }

    $taxCache = [];
    $out = [];
    foreach (array_values($lines) as $i => $l) {
        $pos = $i + 1;
        $id  = intval($l['expense_chart_id'] ?? 0);
        $acc = trim($l['debit_account'] ?? '');
        $chart = $id > 0 ? ($byId[$id] ?? null) : ($acc !== '' ? ($byAccno[$acc] ?? null) : null);
        if (!$chart) {
            throw new ApiError('ACCOUNT_REQUIRED',
                $acc !== '' ? "Aufwandskonto ({$acc}) nicht im Kontenrahmen" : "Position {$pos}: Aufwandskonto fehlt");
        }

        $net   = isset($l['net'])   ? round(floatval($l['net']), 2)   : null;
        $tax   = isset($l['tax'])   ? round(floatval($l['tax']), 2)   : null;
        $gross = isset($l['gross']) ? round(floatval($l['gross']), 2) : null;
        $rate  = $l['rate'] ?? null;

        if ($net === null && $gross !== null && $tax !== null) $net = round($gross - $tax, 2);
        if ($net === null && $gross === null) throw new ApiError('VALIDATION_ERROR', "Position {$pos}: Betrag (net/gross) fehlt");

        // Satz bei vorhandener Steuer aber fehlendem/0-Satz aus net/tax rückrechnen
        $ratePct = floatval($rate ?? 0); if ($ratePct > 0 && $ratePct < 1) $ratePct *= 100;
        if ($ratePct == 0 && $tax !== null && abs($tax) > 0.004 && $net !== null && abs($net) > 0.004) {
            $ratePct = round($tax / $net * 100);
        }
        $ratePct = (int) round($ratePct);
        if (!isset($taxCache[$ratePct])) $taxCache[$ratePct] = _iv_resolveTax($db, $ratePct);
        $taxInfo = $taxCache[$ratePct];
        $r = $taxInfo['rate_pct'] / 100;

        if ($net === null) {
            // Brutto vorgegeben (Bankumsatz, Bon): Netto herausrechnen
            $net = $r > 0 ? round($gross / (1 + $r), 2) : $gross;
            $tax = round($gross - $net, 2);
        } else {
            if ($tax === null)   $tax   = $r > 0 ? round($net * $r, 2) : 0.0;
            if ($gross === null) $gross = round($net + $tax, 2);
        }

        $out[] = [
            'chart_id' => intval($chart['id']),
            'accno'    => $chart['accno'],
            'link'     => $chart['link'] ?: 'AP_amount',
            'net'      => $net,
            'tax'      => $tax,
            'gross'    => $gross,
            'taxInfo'  => $taxInfo,
        ];
    }
    return $out;
}

/**
 * Bucht eine Eingangsrechnung als echte kivitendo-ap (intern, ohne resultInfo).
 *
 * Mehrere Positionen (Splitbuchung) werden wie in kivitendos AP.pm gebucht:
 * je Position ein Aufwandsbein mit Steuerschlüssel und direkt dahinter das
 * Vorsteuerbein, zum Schluss EIN Verbindlichkeitsbein über die Summe.
 * ap.amount/netamount sind die Summen aller Positionen.
 *
 * @param array $p {
 *   vendor_id, invnumber, transdate, duedate (optional), notes (optional), employee_id (optional),
 *   lines: [{expense_chart_id|debit_account, gross?|net?, tax?, rate?}, …]
 *   — oder, alte Aufrufform mit genau einer Position:
 *   expense_chart_id, net, tax (optional), gross (optional), rate (Satz, optional wenn tax+net da)
 * }
 * @return int Neue ap.id
 * @throws ApiError bei fehlenden Pflichtangaben/Konten.
 */
function _iv_postAp($db, array $p) {
    $vendorId   = intval($p['vendor_id'] ?? 0);
    $invnumber  = trim($p['invnumber'] ?? '');
    $transdate  = $p['transdate'] ?? date('Y-m-d');
    $duedate    = !empty($p['duedate']) ? $p['duedate'] : $transdate;
    $notes      = trim($p['notes'] ?? '') ?: null;

    if ($vendorId <= 0)  throw new ApiError('VALIDATION_ERROR', 'vendor_id fehlt');
    if ($invnumber === '') throw new ApiError('VALIDATION_ERROR', 'invnumber (Rechnungsnummer) fehlt');

    // Positionen: übergeben oder — alte Aufrufform — eine aus den Kopffeldern
    $lines = is_array($p['lines'] ?? null) ? array_values($p['lines']) : [];
    if (!$lines) {
        if (intval($p['expense_chart_id'] ?? 0) <= 0) throw new ApiError('VALIDATION_ERROR', 'expense_chart_id (Aufwandskonto) fehlt');
        if (!isset($p['net']) && !isset($p['gross'])) throw new ApiError('VALIDATION_ERROR', 'Betrag (net/gross/tax) unvollständig');
        $lines = [[
            'expense_chart_id' => $p['expense_chart_id'],
            'net'   => $p['net']   ?? null,
            'tax'   => $p['tax']   ?? null,
            'gross' => $p['gross'] ?? null,
            'rate'  => $p['rate']  ?? null,
        ]];
    }
    $lines = _iv_normalizeLines($db, $lines);

    $net   = round(array_sum(array_column($lines, 'net')), 2);
    $gross = round(array_sum(array_column($lines, 'gross')), 2);

    $apAcc = _iv_apAccount($db);
    if (!$apAcc) throw new ApiError('DATA_ERROR', 'Verbindlichkeitskonto (AP) nicht im Kontenrahmen');

    $employeeId = mitarbeiterId($p);
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

    // Alle Beine in einem INSERT, Reihenfolge wie kivitendo
    $legs = [];
    foreach ($lines as $l) {
        $ti = $l['taxInfo'];
        // Aufwand (Soll, negativ) – trägt taxkey/tax_id
        $legs[] = [$l['chart_id'], -$l['net'], $l['link'], $ti['taxkey'], $ti['tax_id']];
        // Vorsteuer (Soll, negativ) – nur falls Steuer anfällt. Betrag, nicht
        // Vorzeichen prüfen: eine Gutschrift (negativer Netto) hat negative
        // Vorsteuer, und ohne dieses Bein wäre der Buchungssatz um die Steuer
        // unausgeglichen.
        if (abs($l['tax']) > 0.004 && $ti['vorsteuer_chart_id']) {
            $legs[] = [$ti['vorsteuer_chart_id'], -$l['tax'], $ti['vorsteuer_link'], $ti['taxkey'], $ti['tax_id']];
        }
    }
    // Verbindlichkeit (Haben, positiv) – kein taxkey, einmal über die Summe
    $legs[] = [intval($apAcc['id']), $gross, 'AP', 0, 0];

    $values = []; $params = [':tid' => $apId, ':td' => $transdate];
    foreach ($legs as $k => [$chartId, $amount, $link, $taxkey, $taxId]) {
        $values[] = "(:tid, :c{$k}, :a{$k}, :td, :td, '', '', :l{$k}, :k{$k}, :t{$k})";
        $params[":c{$k}"] = $chartId;
        $params[":a{$k}"] = $amount;
        $params[":l{$k}"] = $link;
        $params[":k{$k}"] = $taxkey;
        $params[":t{$k}"] = $taxId;
    }
    $db->execute(
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, taxkey, tax_id)
         VALUES " . implode(', ', $values),
        $params
    );

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

    // Aufwandskonto: chart-id direkt, sonst aus der Kontonummer (accno) auflösen
    $expenseChartId = intval($data['expense_chart_id'] ?? 0);
    if ($expenseChartId <= 0 && !empty($data['debit_account'])) {
        $row = $db->getOne("SELECT id FROM chart WHERE accno = :a", [':a' => trim($data['debit_account'])]);
        if (!$row) { resultInfo(false, 'Aufwandskonto (' . $data['debit_account'] . ') nicht im Kontenrahmen'); return; }
        $expenseChartId = intval($row['id']);
    }

    try {
        $apId = _iv_postAp($db, [
            'vendor_id'        => $vendorId,
            'expense_chart_id' => $expenseChartId,
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

    // Splitbuchung aus dem Staging: Sobald mindestens einer Position ein eigenes
    // Sachkonto zugewiesen wurde, wird je (Konto, Steuersatz) ein Buchungsbein
    // geschrieben — Positionen ohne Konto laufen auf das Kopfkonto. Die Summe
    // der Positionen muss dann den Belegbetrag treffen; sonst bleibt der Beleg
    // zur Korrektur liegen, statt still mit einer falschen Aufteilung zu buchen.
    // Reine KI-Positionen ohne Kontozuordnung ändern nichts am bisherigen
    // Verhalten (eine Position aus den Kopffeldern).
    $split = $db->getOne(<<<SQL
        SELECT json_agg(json_build_object(
                   'debit_account', g.account_number,
                   'net',   g.net,
                   'tax',   g.tax,
                   'gross', g.gross,
                   'rate',  g.tax_rate
               ) ORDER BY g.account_number, g.tax_rate DESC) AS lines,
               round(SUM(g.gross), 2) AS gross_sum
        FROM (
            SELECT COALESCE(NULLIF(l.account_number, ''), :head) AS account_number,
                   COALESCE(l.tax_rate, 0)                       AS tax_rate,
                   round(SUM(COALESCE(l.net_amount, 0)), 2)      AS net,
                   round(SUM(COALESCE(l.tax_amount, l.gross_amount - l.net_amount, 0)), 2) AS tax,
                   round(SUM(COALESCE(l.gross_amount, l.net_amount + COALESCE(l.tax_amount, 0), 0)), 2) AS gross
            FROM accounting_booking_lines l
            WHERE l.booking_id = :id
              AND EXISTS (SELECT 1 FROM accounting_booking_lines x
                          WHERE x.booking_id = l.booking_id AND NULLIF(x.account_number, '') IS NOT NULL)
            GROUP BY 1, 2
        ) g
    SQL, [':id' => intval($bookingId), ':head' => $debitAccno]);

    $lines = null;
    if (!empty($split['lines'])) {
        $grossSum = floatval($split['gross_sum']);
        if (abs($grossSum - floatval($b['gross'])) > 0.05) {
            throw new ApiError('LINES_MISMATCH',
                'Die Positionen ergeben ' . number_format($grossSum, 2, ',', '.') . ' €, der Beleg lautet über '
                . number_format(floatval($b['gross']), 2, ',', '.') . ' €. Bitte die Aufteilung prüfen.');
        }
        $lines = json_decode($split['lines'], true);
    }

    $apId = _iv_postAp($db, $lines ? [
        'vendor_id' => $vendorId,
        'invnumber' => $b['invoice_number'] ?: $b['reference'] ?: ('ER-' . $bookingId),
        'transdate' => $b['invoice_date'] ?: date('Y-m-d'),
        'duedate'   => $b['due_date'] ?: null,
        'notes'     => $b['description'],
        'lines'     => $lines,
    ] : [
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
