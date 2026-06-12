<?php
// backend/api/banking/settlements.php
//
// Kartenabrechnungen von Zahlungsdienstleistern (Flatpay/Rapyd o.ae.).
//
// Eine Sammelauszahlung auf dem Bankkonto = Summe vieler Kartenzahlungen
// abzueglich Dienstleistergebuehr. Die hochgeladene Abrechnung (Excel/CSV im
// Frontend geparst, PDF serverseitig) wird als accounting_documents beim
// Kreditor abgelegt; jede Auszahlungszeile wird ueber net = Bankbetrag einem
// nicht zuordbaren Bankumsatz zugeordnet und als Split-Buchung gebucht:
//
//   Soll  Bank             net      (das was ankam)
//   Soll  Gebuehr (Aufwand) fee     (der fehlende Teil, Kreditor zugeordnet)
//     Haben  Verrechnung    gross   (volle Kartenumsaetze / Ausgangsrechnungen)

/**
 * Auswaehlbare Konten fuer die Settlement-Buchung laden (aus dem Kontenrahmen).
 *
 * Liefert alle gueltigen Konten; das Frontend bietet daraus Gebuehren-
 * (Aufwand) und Verrechnungskonto zur Auswahl an. Kein Hardcoding des
 * Kontenrahmens — die Konten kommen aus der bei der Installation geladenen
 * SKR03/SKR04-chart-Tabelle.
 *
 * @testdata {}
 */
function getSettlementAccounts($data) {
    $db = DbhCompany::begin();

    $charts = $db->getAll(
        "SELECT id, accno, description, category, link
         FROM chart
         WHERE COALESCE(invalid, false) = false
         ORDER BY accno",
        []
    );

    resultInfo(true, '', ['accounts' => $charts]);
}

/**
 * PDF-Kartenabrechnung serverseitig parsen (smalot/pdfparser).
 *
 * Liefert normalisierte Auszahlungszeilen zur Vorschau im Frontend (gleiche
 * Struktur wie der Excel/CSV-Parser). Die Datei wird hier nur gelesen, nicht
 * gespeichert — das Speichern uebernimmt uploadCardSettlement.
 *
 * Heuristik: Whitespace entfernen (haelt umgebrochene Datums-/Betragszellen
 * zusammen), pro Zeile ein Auszahlungsdatum + Zeitraum (von-bis) und die
 * EUR-Betraege; erster Betrag = brutto, vorletzter = Gebuehr, letzter = netto.
 *
 * @param string $data['file_base64'] PDF base64-kodiert
 * @testdata {"file_base64": ""}
 */
function parseSettlementPdf($data) {
    $b64 = $data['file_base64'] ?? '';
    if ($b64 === '') { resultInfo(false, 'VALIDATION_ERROR', 'Keine Datei uebergeben'); return; }

    $content = base64_decode($b64, true);
    if ($content === false) { resultInfo(false, 'VALIDATION_ERROR', 'Ungueltiges Base64-Format'); return; }

    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) { resultInfo(false, 'PDF_PARSER_MISSING', 'PDF-Parser nicht installiert (composer install)'); return; }
    require_once $autoload;
    if (!class_exists('\\Smalot\\PdfParser\\Parser')) { resultInfo(false, 'PDF_PARSER_MISSING', 'PDF-Parser-Klasse fehlt'); return; }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseContent($content);
        $text   = $pdf->getText();
    } catch (\Throwable $e) {
        resultInfo(false, 'PDF_PARSE_FAILED', 'PDF konnte nicht gelesen werden');
        return;
    }

    // Whitespace komplett entfernen — fuegt umgebrochene Zellen wieder zusammen.
    $compact = preg_replace('/\s+/', '', $text);

    // Zeilenkoepfe finden: Auszahlungsdatum direkt gefolgt von Zeitraum von-bis.
    $headerRe = '/(\d{4}-\d{2}-\d{2})(\d{4}-\d{2}-\d{2})-(\d{4}-\d{2}-\d{2})/';
    if (!preg_match_all($headerRe, $compact, $heads, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        resultInfo(true, '', ['rows' => []]);
        return;
    }

    $rows = [];
    $count = count($heads);
    for ($i = 0; $i < $count; $i++) {
        $h        = $heads[$i];
        $payout   = $h[1][0];
        $perFrom  = $h[2][0];
        $perTo    = $h[3][0];
        $startPos = $h[0][1] + strlen($h[0][0]);
        $endPos   = ($i + 1 < $count) ? $heads[$i + 1][0][1] : strlen($compact);
        $segment  = substr($compact, $startPos, $endPos - $startPos);

        // Summenzeile (Total/Gesamt/Summe) abschneiden — sonst verfaelschen ihre
        // Betraege die letzte Datenzeile.
        if (preg_match('/Total|Gesamt|Summe/i', $segment, $tm, PREG_OFFSET_CAPTURE)) {
            $segment = substr($segment, 0, $tm[0][1]);
        }

        // EUR-Betraege im Segment (deutsches Format, optional negativ).
        if (!preg_match_all('/(-?\d[\d.]*,\d{2})/', $segment, $am)) continue;
        $amounts = array_map('_settlement_parse_de_amount', $am[1]);
        $n = count($amounts);
        if ($n < 3) continue;

        $gross = $amounts[0];
        $net   = $amounts[$n - 1];
        $fee   = abs($amounts[$n - 2]);

        $rows[] = [
            'payout_date' => $payout,
            'period_from' => $perFrom,
            'period_to'   => $perTo,
            'gross'       => $gross,
            'fee'         => $fee,
            'net'         => $net,
        ];
    }

    resultInfo(true, '', ['rows' => $rows]);
}

/**
 * Deutschen Geldbetrag ("1.498,26" / "-9,74") in float wandeln.
 */
function _settlement_parse_de_amount($s) {
    $clean = str_replace(['.', ','], ['', '.'], (string)$s);
    return (float)$clean;
}

/**
 * Kartenabrechnung hochladen: Datei beim Kreditor ablegen + Auszahlungszeilen speichern.
 *
 * Die Zeilen werden im Frontend aus Excel/CSV/PDF normalisiert und als JSON
 * uebergeben. Datei-Dedup ueber SHA-256 (gleiche Datei wird nicht doppelt
 * gespeichert -> kein erneuter Upload noetig).
 *
 * @param string $data['provider']     Name des Dienstleisters (z.B. 'Flatpay')
 * @param int    $data['vendor_id']    Kreditor-ID (Kartendienstleister)
 * @param string $data['filename']     Original-Dateiname
 * @param string $data['mime_type']    MIME-Typ der Datei
 * @param string $data['file_base64']  Dateiinhalt base64-kodiert
 * @param array  $data['lines']        [{payout_date, period_from, period_to, gross, fee, net}, ...]
 * @param string $data['currency']     Waehrung (Default EUR)
 * @testdata {"provider":"Flatpay","vendor_id":1,"filename":"flatpay.csv","mime_type":"text/csv","file_base64":"","currency":"EUR","lines":[{"payout_date":"2026-06-03","period_from":"2026-06-02","period_to":"2026-06-02","gross":1262.67,"fee":8.21,"net":1254.46}]}
 */
function uploadCardSettlement($data) {
    $db = DbhCompany::begin();

    $provider = trim($data['provider'] ?? '');
    $vendorId = intval($data['vendor_id'] ?? 0) ?: null;
    $filename = trim($data['filename'] ?? '');
    $mimeType = $data['mime_type'] ?? 'application/octet-stream';
    $fileB64  = $data['file_base64'] ?? '';
    $currency = strtoupper(trim($data['currency'] ?? 'EUR')) ?: 'EUR';
    $lines    = $data['lines'] ?? [];

    if ($provider === '')      { resultInfo(false, 'VALIDATION_ERROR', 'Dienstleister fehlt'); return; }
    if (!is_array($lines) || count($lines) === 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Keine Auszahlungszeilen uebergeben');
        return;
    }

    $employeeId = $_SESSION['employee_id'] ?? null;

    // 1) Datei ablegen (beim Kreditor) — Muster wie uploadInvoiceDocument, Dedup ueber Hash.
    $documentId = null;
    if ($fileB64 !== '' && $filename !== '') {
        $content = base64_decode($fileB64, true);
        if ($content === false) { resultInfo(false, 'VALIDATION_ERROR', 'Ungueltiges Base64-Format'); return; }

        $hash = hash('sha256', $content);
        $existing = $db->getOne("SELECT id FROM accounting_documents WHERE file_hash = :h", ['h' => $hash]);
        if ($existing) {
            $documentId = intval($existing['id']);
        } else {
            // Beim Kreditor ablegen: vendors/{id}/, sonst generischer accounting-Ordner.
            $relDir   = $vendorId ? ('vendors/' . $vendorId) : 'accounting';
            $absDir   = fmDataDir() . '/' . $relDir;
            if (!is_dir($absDir)) mkdir($absDir, 0755, true);

            $db->execute(
                "INSERT INTO accounting_documents (original_name, mime_type, file_size, file_hash, status, vendor_id, employee_id)
                 VALUES (:name, :mime, :size, :hash, 'extracted', :vid, :eid)",
                ['name' => $filename, 'mime' => $mimeType, 'size' => strlen($content), 'hash' => $hash, 'vid' => $vendorId, 'eid' => $employeeId]
            );
            $doc        = $db->getOne("SELECT id FROM accounting_documents WHERE file_hash = :h ORDER BY id DESC LIMIT 1", ['h' => $hash]);
            $documentId = intval($doc['id']);
            $safeName   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
            $storedPath = "{$relDir}/{$documentId}_{$safeName}";
            file_put_contents(fmDataDir() . '/' . $storedPath, $content);
            $db->execute("UPDATE accounting_documents SET stored_path = :p WHERE id = :id", ['p' => $storedPath, 'id' => $documentId]);
        }
    }

    // 2) Zuletzt fuer diesen Kreditor verwendete Konten uebernehmen (Vorbelegung).
    $lastAccts = $vendorId ? $db->getOne(
        "SELECT fee_chart_id, clearing_chart_id FROM payment_settlements
         WHERE vendor_id = :vid AND fee_chart_id IS NOT NULL
         ORDER BY id DESC LIMIT 1",
        ['vid' => $vendorId]
    ) : null;

    // 3) Settlement-Kopf anlegen.
    $head = $db->getOne(
        "INSERT INTO payment_settlements (provider, vendor_id, document_id, currency, fee_chart_id, clearing_chart_id, employee_id)
         VALUES (:prov, :vid, :doc, :cur, :fee, :clr, :eid)
         RETURNING id",
        [
            'prov' => $provider, 'vid' => $vendorId, 'doc' => $documentId, 'cur' => $currency,
            'fee'  => $lastAccts['fee_chart_id'] ?? null, 'clr' => $lastAccts['clearing_chart_id'] ?? null,
            'eid'  => $employeeId,
        ]
    );
    $settlementId = intval($head['id']);

    // 4) Zeilen in EINEM Statement einfuegen (Logik in SQL via jsonb_to_recordset).
    //    fee wird positiv gespeichert; doppelte payout_date werden ignoriert.
    $db->execute(
        "INSERT INTO payment_settlement_lines (settlement_id, payout_date, period_from, period_to, gross, fee, net)
         SELECT :sid, x.payout_date, x.period_from, x.period_to, x.gross, ABS(x.fee), x.net
         FROM jsonb_to_recordset(:lines::jsonb)
              AS x(payout_date date, period_from date, period_to date, gross numeric, fee numeric, net numeric)
         WHERE x.payout_date IS NOT NULL
         ON CONFLICT (settlement_id, payout_date) DO NOTHING",
        ['sid' => $settlementId, 'lines' => json_encode(array_values($lines))]
    );

    // 5) Summen + Zeitraum aus den Zeilen ableiten.
    $db->execute(
        "UPDATE payment_settlements s SET
            total_gross = t.g, total_fee = t.f, total_net = t.n,
            period_from = t.pf, period_to = t.pt, mtime = NOW()
         FROM (SELECT COALESCE(SUM(gross),0) g, COALESCE(SUM(fee),0) f, COALESCE(SUM(net),0) n,
                      MIN(period_from) pf, MAX(period_to) pt
               FROM payment_settlement_lines WHERE settlement_id = :sid) t
         WHERE s.id = :sid",
        ['sid' => $settlementId]
    );

    $result = $db->getOne(
        "SELECT s.*,
                (SELECT COUNT(*) FROM payment_settlement_lines l WHERE l.settlement_id = s.id) AS line_count
         FROM payment_settlements s WHERE s.id = :sid",
        ['sid' => $settlementId]
    );

    resultInfo(true, '', ['settlement' => $result, 'document_id' => $documentId]);
}

/**
 * Gespeicherte Kartenabrechnungen samt Zeilen laden.
 *
 * Dient als Auswahl fuer "vorhandene Abrechnung verwenden" und als Quelle fuer
 * den automatischen Match weiterer Umsaetze (kein erneuter Upload noetig).
 *
 * @param int    $data['vendor_id']  optional: nur Abrechnungen dieses Kreditors
 * @param string $data['only_open']  '1' = nur Abrechnungen mit offenen Zeilen
 * @testdata {}
 */
function getCardSettlements($data) {
    $db = DbhCompany::begin();

    $vendorId = intval($data['vendor_id'] ?? 0) ?: null;
    $onlyOpen = ($data['only_open'] ?? '') === '1';

    $rows = $db->getAll(
        "SELECT s.id, s.provider, s.vendor_id, v.name AS vendor_name, s.document_id,
                s.period_from, s.period_to, s.total_gross, s.total_fee, s.total_net,
                s.currency, s.fee_chart_id, s.clearing_chart_id, s.itime,
                (SELECT json_agg(json_build_object(
                            'id', l.id, 'payout_date', l.payout_date,
                            'period_from', l.period_from, 'period_to', l.period_to,
                            'gross', l.gross, 'fee', l.fee, 'net', l.net,
                            'status', l.status, 'matched_bank_transaction_id', l.matched_bank_transaction_id,
                            'gl_id', l.gl_id)
                         ORDER BY l.payout_date DESC)
                 FROM payment_settlement_lines l WHERE l.settlement_id = s.id) AS lines
         FROM payment_settlements s
         LEFT JOIN vendor v ON v.id = s.vendor_id
         WHERE (:vid::int IS NULL OR s.vendor_id = :vid)
           AND (:only_open = false OR EXISTS (
                   SELECT 1 FROM payment_settlement_lines l2
                   WHERE l2.settlement_id = s.id AND l2.status = 'open'))
         ORDER BY s.id DESC",
        ['vid' => $vendorId, 'only_open' => $onlyOpen]
    );

    resultInfo(true, '', ['settlements' => $rows]);
}

/**
 * Passende Abrechnungszeile zu einem nicht zuordbaren Bankumsatz vorschlagen.
 *
 * Match-Kriterium: offene Zeile mit net = Bankbetrag (auf Cent gerundet),
 * Auszahlungsdatum nahe am Buchungsdatum (+/- 5 Tage bevorzugt). Liefert die
 * Zeile inkl. gemerkter Konten-Vorbelegung des Kreditors.
 *
 * @param int $data['bank_transaction_id'] Bankumsatz-ID
 * @testdata {"bank_transaction_id": 1}
 */
function suggestSettlementMatch($data) {
    $db = DbhCompany::begin();

    $btId = intval($data['bank_transaction_id'] ?? 0);
    if ($btId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Umsatz-ID fehlt'); return; }

    $match = $db->getOne(
        "SELECT l.id AS line_id, l.settlement_id, l.payout_date, l.gross, l.fee, l.net,
                s.provider, s.vendor_id, v.name AS vendor_name,
                s.fee_chart_id, s.clearing_chart_id,
                bt.amount AS bank_amount, bt.transdate AS bank_date
         FROM bank_transactions bt
         JOIN payment_settlement_lines l
              ON l.status = 'open'
             AND ROUND(l.net, 2) = ROUND(bt.amount, 2)
         JOIN payment_settlements s ON s.id = l.settlement_id
         LEFT JOIN vendor v ON v.id = s.vendor_id
         WHERE bt.id = :id
         ORDER BY ABS(l.payout_date - bt.transdate) ASC, l.id ASC
         LIMIT 1",
        ['id' => $btId]
    );

    if (!$match) {
        resultInfo(true, '', ['match' => null]);
        return;
    }

    resultInfo(true, '', ['match' => $match]);
}

/**
 * Teilmengen-Suche (Subset-Sum) in Cent: Subsets, deren Summe == target.
 * Liefert bis zu 2 Loesungen (zur Ambiguitaets-Erkennung). Mit Pruning ueber
 * Suffix-Summen — fuer die wenigen offenen Rechnungen pro Tag problemlos.
 */
function _settlement_subset_sum($items, $target) {
    usort($items, function ($a, $b) { return $b['cents'] - $a['cents']; });
    $n = count($items);
    $suffix = array_fill(0, $n + 1, 0);
    for ($i = $n - 1; $i >= 0; $i--) $suffix[$i] = $suffix[$i + 1] + $items[$i]['cents'];

    $solutions = [];
    $chosen = [];
    $explore = function ($i, $remaining) use (&$explore, &$solutions, &$chosen, $items, $n, $suffix) {
        if (count($solutions) >= 2) return;
        if ($remaining === 0) { $solutions[] = array_map(function ($k) use ($items) { return $items[$k]['id']; }, $chosen); return; }
        if ($i >= $n || $remaining < 0) return;
        if ($suffix[$i] < $remaining) return;            // Rest reicht nicht mehr
        $chosen[] = $i;
        $explore($i + 1, $remaining - $items[$i]['cents']);
        array_pop($chosen);
        $explore($i + 1, $remaining);
    };
    if ($target > 0) $explore(0, $target);
    return $solutions;
}

/**
 * Offene Ausgangsrechnungen finden, die in Summe den Brutto-Betrag einer
 * Abrechnungszeile ergeben (Kartenzahlungen des abgedeckten Tages).
 *
 * Da es keine Karten-Markierung an der Rechnung gibt, erfolgt die Zuordnung
 * ueber die Betragssumme im abgedeckten Zeitraum. Liefert den Vorschlag
 * (eindeutige Teilmenge) UND alle Kandidaten (fuer manuelle Auswahl).
 *
 * @param int $data['settlement_line_id'] Abrechnungszeile
 * @testdata {"settlement_line_id": 1}
 */
function findInvoicesForSettlementLine($data) {
    $db = DbhCompany::begin();

    $lineId = intval($data['settlement_line_id'] ?? 0);
    if ($lineId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Zeilen-ID fehlt'); return; }

    $line = $db->getOne(
        "SELECT id, gross, period_from, period_to FROM payment_settlement_lines WHERE id = :id",
        ['id' => $lineId]
    );
    if (!$line) { resultInfo(false, 'NOT_FOUND', 'Abrechnungszeile nicht gefunden'); return; }

    $candidates = $db->getAll(
        "SELECT a.id, a.invnumber, a.transdate, a.amount, COALESCE(a.paid,0) AS paid,
                (a.amount - COALESCE(a.paid,0)) AS open_amount, c.name AS customer_name
         FROM ar a
         LEFT JOIN customer c ON c.id = a.customer_id
         WHERE a.storno IS NOT TRUE
           AND a.transdate BETWEEN :from AND :to
           AND (a.amount - COALESCE(a.paid,0)) > 0.005
         ORDER BY a.transdate, a.id",
        ['from' => $line['period_from'], 'to' => $line['period_to']]
    );

    $grossCents = (int) round(((float) $line['gross']) * 100);
    $items = [];
    foreach ($candidates as $r) {
        $cents = (int) round(((float) $r['open_amount']) * 100);
        if ($cents > 0) $items[] = ['id' => (int) $r['id'], 'cents' => $cents];
    }

    $solutions = (count($items) > 0 && count($items) <= 40) ? _settlement_subset_sum($items, $grossCents) : [];
    $count = count($solutions);
    $best = $count > 0 ? $solutions[0] : [];

    $byId = [];
    foreach ($candidates as $r) { $byId[(int) $r['id']] = $r; }
    $mapInv = function ($r) {
        return [
            'ar_id'         => (int) $r['id'],
            'invnumber'     => $r['invnumber'],
            'transdate'     => $r['transdate'],
            'customer_name' => $r['customer_name'],
            'open_amount'   => (float) $r['open_amount'],
        ];
    };

    resultInfo(true, '', [
        'gross'           => (float) $line['gross'],
        'period_from'     => $line['period_from'],
        'period_to'       => $line['period_to'],
        'found'           => $count > 0,
        'ambiguous'       => $count > 1,
        'candidate_count' => count($items),
        'invoices'        => array_map(function ($id) use ($byId, $mapInv) { return $mapInv($byId[$id]); }, $best),
        'all_candidates'  => array_map($mapInv, $candidates),
    ]);
}

/**
 * Abrechnungszeile gegen einen Bankumsatz als Split-Buchung verbuchen.
 *
 * Erzeugt eine kivitendo-kompatible gl-Buchung mit drei acc_trans-Beinen:
 *   Bank (+net) / Gebuehr-Aufwand (+fee) / Verrechnungskonto (-gross).
 * Verknuepft den Bankumsatz (bank_transaction_acc_trans), setzt ihn auf
 * 'booked' und merkt die gewaehlten Konten beim Kreditor (Settlement-Kopf).
 *
 * Mit $data['ar_ids'] werden die zugehoerigen Ausgangsrechnungen direkt als
 * bezahlt gebucht: Bank(+net) + Gebuehr(+fee) gegen Forderungen(-gross). Die
 * Summe der offenen Betraege muss == gross sein. Kein Geldtransit noetig, da
 * das Geld bereits auf der Bank ist. Ohne ar_ids wird der Bruttobetrag aufs
 * Verrechnungskonto (clearing_chart_id) gebucht (Fallback).
 *
 * @param int   $data['bank_transaction_id'] Bankumsatz-ID (Nettoauszahlung)
 * @param int   $data['settlement_line_id']  Abrechnungszeile (net muss = Bankbetrag)
 * @param int   $data['fee_chart_id']        Gebuehren-/Aufwandskonto (chart.id)
 * @param int   $data['clearing_chart_id']   Verrechnungskonto (nur Fallback ohne ar_ids)
 * @param array $data['ar_ids']              auszugleichende Ausgangsrechnungen (ar.id)
 * @testdata {"bank_transaction_id": 1, "settlement_line_id": 1, "fee_chart_id": 100, "ar_ids": [1,2]}
 */
function bookCardSettlementLine($data) {
    $db = DbhCompany::begin();

    $btId       = intval($data['bank_transaction_id'] ?? 0);
    $lineId     = intval($data['settlement_line_id'] ?? 0);
    $feeChartId = intval($data['fee_chart_id'] ?? 0);
    $clrChartId = intval($data['clearing_chart_id'] ?? 0);

    if ($btId <= 0 || $lineId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Umsatz- und Zeilen-ID erforderlich'); return; }
    if ($feeChartId <= 0)           { resultInfo(false, 'VALIDATION_ERROR', 'Gebuehrenkonto fehlt'); return; }
    // Geldtransit/Verrechnungskonto wird nur fuer den Fallback OHNE Rechnungs-
    // auswahl gebraucht (siehe unten) — bei Rechnungsausgleich nicht noetig.

    // Bankumsatz + Bankkonto-Konto laden.
    $bank = $db->getOne(
        "SELECT bt.id, bt.amount, bt.transdate, bt.match_status, bt.purpose,
                ba.chart_id AS bank_chart_id, bc.link AS bank_link
         FROM bank_transactions bt
         JOIN bank_accounts ba ON ba.id = bt.local_bank_account_id
         JOIN chart bc ON bc.id = ba.chart_id
         WHERE bt.id = :id",
        ['id' => $btId]
    );
    if (!$bank) { resultInfo(false, 'NOT_FOUND', 'Bankumsatz oder Bankkonto nicht gefunden'); return; }
    if ($bank['match_status'] === 'booked') { resultInfo(false, 'ALREADY_BOOKED', 'Umsatz ist bereits gebucht'); return; }

    // Abrechnungszeile + Kreditor laden.
    $line = $db->getOne(
        "SELECT l.id, l.settlement_id, l.gross, l.fee, l.net, l.status, l.payout_date,
                s.provider, s.vendor_id
         FROM payment_settlement_lines l
         JOIN payment_settlements s ON s.id = l.settlement_id
         WHERE l.id = :id",
        ['id' => $lineId]
    );
    if (!$line) { resultInfo(false, 'NOT_FOUND', 'Abrechnungszeile nicht gefunden'); return; }
    if ($line['status'] === 'booked') { resultInfo(false, 'ALREADY_BOOKED', 'Zeile ist bereits gebucht'); return; }

    // Plausibilitaet: Nettoauszahlung muss dem Bankbetrag entsprechen.
    if (round((float)$line['net'], 2) !== round((float)$bank['amount'], 2)) {
        resultInfo(false, 'AMOUNT_MISMATCH', 'Nettoauszahlung der Zeile entspricht nicht dem Bankbetrag');
        return;
    }

    // Gebuehrenkonto laden; Verrechnungskonto nur falls angegeben (Fallback).
    $feeChart = $db->getOne("SELECT id, accno, link FROM chart WHERE id = :id", ['id' => $feeChartId]);
    if (!$feeChart) { resultInfo(false, 'NOT_FOUND', 'Gebuehrenkonto nicht gefunden'); return; }
    $clrChart = $clrChartId > 0 ? $db->getOne("SELECT id, accno, link FROM chart WHERE id = :id", ['id' => $clrChartId]) : null;

    $net   = round((float)$bank['amount'], 2);
    $fee   = round((float)$line['fee'], 2);
    $gross = round((float)$line['gross'], 2);
    // Konsistenz erzwingen: gross = net + fee (Rundungsdifferenzen der Datei abfangen).
    $gross = round($net + $fee, 2);

    $transdate  = $bank['transdate'];
    $employeeId = $_SESSION['employee_id'] ?? null;
    $descr      = 'Kartenabrechnung ' . $line['provider'] . ' (Auszahlung ' . $line['payout_date'] . ')';

    // Optionaler Rechnungsausgleich vorbereiten + validieren (vor jeder Buchung).
    $arIds = $data['ar_ids'] ?? [];
    if (!is_array($arIds)) $arIds = [];
    $arIds = array_values(array_unique(array_map('intval', $arIds)));
    $settleList = [];
    if (count($arIds) > 0) {
        $sumCents = 0;
        foreach ($arIds as $arId) {
            if ($arId <= 0) continue;
            $ar = $db->getOne("SELECT id, invnumber, amount, COALESCE(paid,0) AS paid FROM ar WHERE id = :id AND storno IS NOT TRUE", ['id' => $arId]);
            if (!$ar) { resultInfo(false, 'NOT_FOUND', 'Rechnung #' . $arId . ' nicht gefunden'); return; }
            $pay = round((float)$ar['amount'] - (float)$ar['paid'], 2);
            if ($pay <= 0) { resultInfo(false, 'ALREADY_PAID', 'Rechnung ' . $ar['invnumber'] . ' ist bereits bezahlt'); return; }
            $fk = $db->getOne(
                "SELECT chart_id FROM acc_trans
                 WHERE trans_id = :tid AND chart_link LIKE '%AR%' AND chart_link NOT LIKE '%AR_paid%'
                 ORDER BY acc_trans_id ASC LIMIT 1",
                ['tid' => $arId]
            );
            // Fallback fuer Rechnungen ohne GL-Erstbuchung (z. B. importierte Belege
            // ohne acc_trans): Standard-Forderungskonto aus dem Kontenrahmen nehmen
            // (chart.link = 'AR', niedrigste Kontonummer) — analog zur Bankabstimmung.
            if (!$fk) {
                $fk = $db->getOne("SELECT id AS chart_id FROM chart WHERE link = 'AR' ORDER BY accno ASC LIMIT 1");
                writeLog("bookCardSettlementLine: AR #{$arId} (Rg {$ar['invnumber']}) ohne acc_trans — Fallback-Forderungskonto " . ($fk['chart_id'] ?? 'KEINS'), true, DLOG_INF);
            }
            if (!$fk) { resultInfo(false, 'DATA_ERROR', 'Forderungskonto der Rechnung ' . $ar['invnumber'] . ' nicht ermittelbar'); return; }
            $settleList[] = ['ar_id' => $arId, 'invnumber' => $ar['invnumber'], 'pay' => $pay, 'fk_chart_id' => $fk['chart_id']];
            $sumCents += (int) round($pay * 100);
        }
        if ($sumCents !== (int) round($gross * 100)) {
            resultInfo(false, 'AMOUNT_MISMATCH', 'Summe der gewaehlten Rechnungen entspricht nicht dem Bruttobetrag');
            return;
        }
    }

    // Ohne Rechnungsauswahl braucht es ein Verrechnungskonto, um den Bruttobetrag
    // gegenzubuchen (Fallback). Mit Rechnungsauswahl werden direkt die Forderungen
    // ausgeglichen — kein Geldtransit noetig.
    if (count($settleList) === 0 && !$clrChart) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ohne Rechnungsauswahl ist ein Verrechnungskonto erforderlich');
        return;
    }

    // GL-Kopf.
    $gl = $db->getOne(
        "INSERT INTO gl (reference, description, transdate, gldate, employee_id)
         VALUES (:ref, :descr, :td, :td, :eid) RETURNING id",
        ['ref' => 'SETTLEMENT', 'descr' => $descr, 'td' => $transdate, 'eid' => $employeeId]
    );
    $glId = intval($gl['id']);

    // Bein 1: Bankkonto +net (Geldeingang, Aktiv-Zunahme).
    $bankLeg = $db->getOne(
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
         VALUES (:t, :c, :a, :td, :td, 'SETTLEMENT', :memo, 0, 0, :link) RETURNING acc_trans_id",
        ['t' => $glId, 'c' => $bank['bank_chart_id'], 'a' => $net, 'td' => $transdate,
         'memo' => 'Kartenauszahlung Umsatz #' . $btId, 'link' => $bank['bank_link'] ?? '']
    );

    // Bein 2: Gebuehr (+fee, Aufwand) — dem Kartendienstleister zugeordnet.
    $db->execute(
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
         VALUES (:t, :c, :a, :td, :td, 'SETTLEMENT', :memo, 0, 0, :link)",
        ['t' => $glId, 'c' => $feeChartId, 'a' => $fee, 'td' => $transdate,
         'memo' => 'Transaktionsgebuehr ' . $line['provider'], 'link' => $feeChart['link'] ?? '']
    );

    // Bein 3: Gegenbuchung zum Geldeingang.
    if (count($settleList) > 0) {
        // Direkt die Forderungen der Kartenkunden ausgleichen (Geld ist bereits
        // auf der Bank → kein Geldtransit noetig). Summe der Forderungs-Beine
        // = -gross und hebt Bank(+net) + Gebuehr(+fee) auf.
        foreach ($settleList as $s) {
            $db->execute(
                "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
                 VALUES (:t, :c, :a, :td, :td, 'SETTLEMENT', :memo, 0, 0, 'AR_paid')",
                ['t' => $glId, 'c' => $s['fk_chart_id'], 'a' => -$s['pay'], 'td' => $transdate,
                 'memo' => 'Kartenzahlung ' . $line['provider'] . ' Rg ' . $s['invnumber']]
            );
            $db->execute("UPDATE ar SET paid = COALESCE(paid,0) + :inc WHERE id = :id", ['inc' => $s['pay'], 'id' => $s['ar_id']]);
        }
    } else {
        // Fallback ohne Rechnungsauswahl: Bruttobetrag aufs Verrechnungskonto
        // (Geldtransit) parken; Rechnungen werden separat ausgeglichen.
        $db->execute(
            "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
             VALUES (:t, :c, :a, :td, :td, 'SETTLEMENT', :memo, 0, 0, :link)",
            ['t' => $glId, 'c' => $clrChartId, 'a' => -$gross, 'td' => $transdate,
             'memo' => 'Kartenumsaetze ' . $line['provider'], 'link' => $clrChart['link'] ?? '']
        );
    }

    // Bankumsatz mit der Buchung verknuepfen (kivitendo-Mapping, Bank-Bein + gl_id).
    $db->execute(
        "INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, gl_id)
         VALUES (:bt, :at, :gl)",
        ['bt' => $btId, 'at' => $bankLeg['acc_trans_id'], 'gl' => $glId]
    );
    $db->execute("DELETE FROM bank_transaction_matches WHERE bank_transaction_id = :id", ['id' => $btId]);
    $db->execute("UPDATE bank_transactions SET match_status = 'booked', cleared = true WHERE id = :id", ['id' => $btId]);

    // Abrechnungszeile abschliessen + Konten beim Kreditor merken.
    $db->execute(
        "UPDATE payment_settlement_lines
         SET status = 'booked', gl_id = :gl, matched_bank_transaction_id = :bt,
             settled_ar_ids = :ar, mtime = NOW()
         WHERE id = :id",
        ['gl' => $glId, 'bt' => $btId, 'id' => $lineId,
         'ar' => count($settleList)
             ? json_encode(array_map(function ($s) { return ['ar_id' => $s['ar_id'], 'pay' => $s['pay']]; }, $settleList))
             : null]
    );
    // Gewaehltes Gebuehrenkonto (und ggf. Verrechnungskonto) beim Kreditor merken.
    $db->execute(
        "UPDATE payment_settlements SET fee_chart_id = :fee,
                clearing_chart_id = COALESCE(:clr, clearing_chart_id), mtime = NOW()
         WHERE id = :sid",
        ['fee' => $feeChartId, 'clr' => $clrChartId > 0 ? $clrChartId : null, 'sid' => $line['settlement_id']]
    );

    resultInfo(true, 'Gebucht', [
        'gl_id'          => $glId,
        'net'            => $net,
        'fee'            => $fee,
        'gross'          => $gross,
        'settled_count'  => count($settleList),
    ]);
}

/**
 * Settlement-Buchung rueckgaengig machen (Storno der acc_trans/gl-Eintraege).
 *
 * @param int $data['settlement_line_id'] Abrechnungszeile, deren Buchung storniert wird
 * @testdata {"settlement_line_id": 1}
 */
function unbookCardSettlementLine($data) {
    $db = DbhCompany::begin();

    $lineId = intval($data['settlement_line_id'] ?? 0);
    if ($lineId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Zeilen-ID fehlt'); return; }

    $line = $db->getOne(
        "SELECT id, gl_id, matched_bank_transaction_id, status, settled_ar_ids FROM payment_settlement_lines WHERE id = :id",
        ['id' => $lineId]
    );
    if (!$line)                         { resultInfo(false, 'NOT_FOUND', 'Zeile nicht gefunden'); return; }
    if ($line['status'] !== 'booked')   { resultInfo(false, 'NOT_BOOKED', 'Zeile ist nicht gebucht'); return; }

    $glId = intval($line['gl_id']);
    $btId = intval($line['matched_bank_transaction_id']);

    if ($glId > 0) {
        $db->execute("DELETE FROM bank_transaction_acc_trans WHERE gl_id = :gl", ['gl' => $glId]);
        $db->execute("DELETE FROM acc_trans WHERE trans_id = :gl", ['gl' => $glId]);
        $db->execute("DELETE FROM gl WHERE id = :gl", ['gl' => $glId]);
    }

    // Rechnungsausgleich zuruecknehmen: ar.paid je Rechnung mindern. Die Forderungs-
    // Beine liegen unter der gl und wurden oben bereits mitgeloescht.
    $settled = [];
    if (!empty($line['settled_ar_ids'])) {
        $decoded = json_decode($line['settled_ar_ids'], true);
        if (is_array($decoded)) $settled = $decoded;
    }
    foreach ($settled as $s) {
        $arId = intval($s['ar_id'] ?? 0);
        $pay  = round((float)($s['pay'] ?? 0), 2);
        if ($arId > 0 && $pay > 0) {
            $db->execute("UPDATE ar SET paid = COALESCE(paid,0) - :dec WHERE id = :id", ['dec' => $pay, 'id' => $arId]);
        }
    }
    if ($btId > 0) {
        $db->execute("UPDATE bank_transactions SET match_status = 'unmatched', cleared = false WHERE id = :id", ['id' => $btId]);
    }
    $db->execute(
        "UPDATE payment_settlement_lines
         SET status = 'open', gl_id = NULL, matched_bank_transaction_id = NULL,
             settled_ar_ids = NULL, mtime = NOW()
         WHERE id = :id",
        ['id' => $lineId]
    );

    resultInfo(true, 'Storniert', []);
}
