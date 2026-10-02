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
 * @param array  $data['lines']        [{payout_date, period_from, period_to, gross, fee, net, reference?}, ...]
 *                                     reference = Auszahlungs-Kennung des Dienstleisters (z. B. SumUp "PID1283966"),
 *                                     steht im Verwendungszweck des Bankumsatzes
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

    $employeeId = mitarbeiterId($data);

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
            belegSchreiben(fmDataDir() . '/' . $storedPath, $content);
            belegAblageEintragen($db, $documentId, $storedPath);
            belegProtokoll($db, $documentId, $employeeId, 'ablage', null, $filename);
        }
    }

    $stored = _settlementStore($db, [
        'provider' => $provider, 'vendor_id' => $vendorId, 'document_id' => $documentId,
        'currency' => $currency, 'employee_id' => $employeeId, 'lines' => $lines,
    ]);

    resultInfo(true, '', ['settlement' => $stored['settlement'], 'document_id' => $documentId, 'inserted' => $stored['inserted'], 'skipped' => $stored['skipped']]);
}

/**
 * Gibt es die Spalte payment_settlement_lines.transactions schon? Sie kommt mit
 * dem Schema-Update; bis dahin laufen Upload und Zuordnung ohne Einzelzahlungen
 * weiter (gleiches Muster wie belegAblageEintragen).
 */
function _settlementHasTransactionsColumn($db) {
    static $has = null;
    if ($has === null) {
        $r = $db->getOne("SELECT 1 AS ok FROM information_schema.columns WHERE table_name = 'payment_settlement_lines' AND column_name = 'transactions' LIMIT 1");
        $has = (bool)$r;
    }
    return $has;
}

/**
 * Abrechnungskopf + Auszahlungszeilen speichern — gemeinsamer Kern von
 * Datei-Upload (uploadCardSettlement) und API-Abruf (syncSumupPayouts).
 *
 * @param array $o provider, vendor_id, document_id, currency, employee_id, lines
 * @return array {settlement: row|null, settlement_id, inserted, skipped}
 */
function _settlementStore($db, array $o) {
    $provider   = $o['provider'];
    $vendorId   = $o['vendor_id'] ?? null;
    $documentId = $o['document_id'] ?? null;
    $currency   = $o['currency'] ?? 'EUR';
    $employeeId = $o['employee_id'] ?? null;
    $lines      = $o['lines'] ?? [];

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
    //    Auszahlungen, deren Kennung (SumUp-PID) fuer diesen Kreditor schon aus
    //    einem frueheren Upload vorliegt, werden uebersprungen — SumUp-Berichte
    //    ueberlappen sich (Monatsbericht vs. Transaktionsbericht), sonst haette
    //    jede Auszahlung mehrere offene Zeilen.
    $txCol = _settlementHasTransactionsColumn($db);
    $inserted = $db->getOne(
        "WITH neu AS (
            INSERT INTO payment_settlement_lines (settlement_id, payout_date, period_from, period_to, gross, fee, net, reference" . ($txCol ? ', transactions' : '') . ")
            SELECT :sid, x.payout_date, x.period_from, x.period_to, x.gross, ABS(x.fee), x.net, NULLIF(trim(x.reference), '')" . ($txCol ? ', x.transactions' : '') . "
            FROM jsonb_to_recordset(:lines::jsonb)
                 AS x(payout_date date, period_from date, period_to date, gross numeric, fee numeric, net numeric, reference text, transactions jsonb)
            WHERE x.payout_date IS NOT NULL
              AND (NULLIF(trim(x.reference), '') IS NULL OR NOT EXISTS (
                      SELECT 1 FROM payment_settlement_lines l2
                      JOIN payment_settlements s2 ON s2.id = l2.settlement_id
                      WHERE l2.reference = trim(x.reference)
                        AND s2.vendor_id IS NOT DISTINCT FROM :vid::int))
            ON CONFLICT (settlement_id, payout_date) DO NOTHING
            RETURNING id
         )
         SELECT count(*) AS n FROM neu",
        ['sid' => $settlementId, 'lines' => json_encode(array_values($lines)), 'vid' => $vendorId]
    );
    $insertedCount = intval($inserted['n'] ?? 0);
    $skippedCount  = count($lines) - $insertedCount;

    // Nichts Neues: leeren Kopf wieder entfernen, der Aufrufer zeigt den Hinweis.
    if ($insertedCount === 0) {
        $db->execute("DELETE FROM payment_settlements WHERE id = :sid", ['sid' => $settlementId]);
        return ['settlement' => null, 'settlement_id' => null, 'inserted' => 0, 'skipped' => $skippedCount];
    }

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

    return ['settlement' => $result, 'settlement_id' => $settlementId, 'inserted' => $insertedCount, 'skipped' => $skippedCount];
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
 * Hochgeladene Kartenabrechnung wieder loeschen (falsche Datei).
 *
 * Nur moeglich, solange keine Zeile gebucht ist — gebuchte Zeilen haengen am
 * Hauptbuch (gl_id) und muessen zuerst storniert werden. Die abgelegte Datei
 * (accounting_documents + Datei im Mandantenverzeichnis) wird mitgeloescht,
 * sofern sie nirgends sonst haengt (andere Abrechnung, Eingangs-/Ausgangs-
 * rechnung, Buchung). Eine falsch hochgeladene Abrechnung ist kein Beleg,
 * der aufbewahrt werden muesste.
 *
 * @param int $data['settlement_id'] Abrechnungs-ID
 * @testdata {"settlement_id": 1}
 */
function deleteCardSettlement($data) {
    $db = DbhCompany::begin();

    $id = intval($data['settlement_id'] ?? 0);
    if ($id <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Abrechnungs-ID fehlt'); return; }

    // Loeschen und Sperrpruefung in einem Statement: geloescht wird nur, wenn
    // keine Zeile gebucht ist; die Zeilen fallen per ON DELETE CASCADE mit.
    $row = $db->getOne(
        "WITH s AS (
            SELECT id, document_id,
                   EXISTS (SELECT 1 FROM payment_settlement_lines l
                           WHERE l.settlement_id = payment_settlements.id AND l.status = 'booked') AS gebucht
            FROM payment_settlements WHERE id = :id
         ),
         d AS (
            DELETE FROM payment_settlements
            WHERE id = (SELECT id FROM s WHERE gebucht = false)
            RETURNING id
         )
         SELECT (SELECT id FROM s) AS found, (SELECT gebucht FROM s) AS gebucht,
                (SELECT document_id FROM s) AS document_id, (SELECT id FROM d) AS deleted",
        ['id' => $id]
    );

    if (!$row || !$row['found']) { resultInfo(false, 'NOT_FOUND', 'Abrechnung nicht gefunden'); return; }
    if ($row['gebucht'] && !$row['deleted']) {
        resultInfo(false, 'SETTLEMENT_BOOKED', 'Abrechnung enthält bereits gebuchte Auszahlungen — zuerst stornieren');
        return;
    }

    // Datei mitloeschen, wenn sie nur zu dieser Abrechnung gehoerte. Das
    // Loeschen der Zeile liefert den Pfad; Protokollzeilen fallen per Cascade.
    $fileDeleted = false;
    if (!empty($row['document_id'])) {
        $doc = $db->getOne(
            "DELETE FROM accounting_documents d
             WHERE d.id = :doc
               AND d.ap_id IS NULL AND d.ar_id IS NULL AND d.booking_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM payment_settlements s WHERE s.document_id = d.id)
             RETURNING d.stored_path",
            ['doc' => intval($row['document_id'])]
        );
        if ($doc) {
            $fileDeleted = true;
            if (!empty($doc['stored_path'])) {
                $abs = fmDataDir() . '/' . $doc['stored_path'];
                if (is_file($abs)) @unlink($abs);
            }
        }
    }

    resultInfo(true, 'Abrechnung gelöscht', ['settlement_id' => $id, 'file_deleted' => $fileDeleted]);
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

    // Treffer ueber die Auszahlungs-Kennung im Verwendungszweck (SumUp: "SUMUP
    // PID1283966 PAYOUT 010726") schlaegt den reinen Betragsvergleich; bei
    // gleichem Betrag entscheidet die Naehe des Auszahlungsdatums.
    $match = $db->getOne(
        "SELECT l.id AS line_id, l.settlement_id, l.payout_date, l.gross, l.fee, l.net, l.reference,
                s.provider, s.vendor_id, v.name AS vendor_name,
                s.fee_chart_id, s.clearing_chart_id,
                bt.amount AS bank_amount, bt.transdate AS bank_date
         FROM bank_transactions bt
         JOIN payment_settlement_lines l
              ON l.status = 'open'
             AND (ROUND(l.net, 2) = ROUND(bt.amount, 2)
                  OR (l.reference IS NOT NULL AND bt.purpose ILIKE '%' || l.reference || '%'))
         JOIN payment_settlements s ON s.id = l.settlement_id
         LEFT JOIN vendor v ON v.id = s.vendor_id
         WHERE bt.id = :id
         ORDER BY (l.reference IS NOT NULL AND bt.purpose ILIKE '%' || l.reference || '%') DESC,
                  ABS(l.payout_date - bt.transdate) ASC, l.id ASC
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
 * Offene Ausgangsrechnungen zu einer Abrechnungszeile finden.
 *
 * Bevorzugt je EINZELNER Kartenzahlung (line.transactions aus SumUp-API oder
 * Transaktionsbericht): jede Zahlung wird der offenen Rechnung zugeordnet, die
 * (1) in der SumUp-Beschreibung genannt ist oder (2) exakt den Zahlbetrag offen
 * hat — Rechnungsdatum bis 60 Tage vor der Auszahlung, naechstliegendes zuerst.
 * So ist egal, ob der Kunde am Rechnungstag oder Tage spaeter mit Karte zahlt.
 * Treffer werden vorbelegt; Zahlungen ohne Treffer werden benannt, damit nur
 * dort manuell gewaehlt werden muss.
 *
 * Ohne Einzelzahlungen (Flatpay, Alt-Zeilen): Teilsumme der offenen Rechnungen
 * im abgedeckten Zeitraum (7 Tage Vorlauf), die den Bruttobetrag ergibt.
 *
 * @param int $data['settlement_line_id'] Abrechnungszeile
 * @testdata {"settlement_line_id": 1}
 */
function findInvoicesForSettlementLine($data) {
    $db = DbhCompany::begin();

    $lineId = intval($data['settlement_line_id'] ?? 0);
    if ($lineId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Zeilen-ID fehlt'); return; }

    $txCol = _settlementHasTransactionsColumn($db);
    $line = $db->getOne(
        "SELECT id, gross, payout_date, period_from, period_to, reference" . ($txCol ? ', transactions' : '') . "
         FROM payment_settlement_lines WHERE id = :id",
        ['id' => $lineId]
    );
    if (!$line) { resultInfo(false, 'NOT_FOUND', 'Abrechnungszeile nicht gefunden'); return; }

    // Alt-Zeile einer SumUp-Auszahlung ohne Einzelzahlungen: per API nachladen.
    $transactions = $txCol && !empty($line['transactions']) ? (json_decode($line['transactions'], true) ?: []) : [];
    if ($txCol && count($transactions) === 0 && !empty($line['reference']) && function_exists('_sumupBackfillTransactions')) {
        $transactions = _sumupBackfillTransactions($db, $line);
    }

    $mapInv = function ($r) {
        return [
            'ar_id'         => (int) $r['id'],
            'invnumber'     => $r['invnumber'],
            'transdate'     => $r['transdate'],
            'customer_name' => $r['customer_name'],
            'open_amount'   => (float) $r['open_amount'],
        ];
    };

    // ── Weg 1: je Kartenzahlung ────────────────────────────────────────────
    if (count($transactions) > 0) {
        // Offene Rechnungen bis 60 Tage vor der Auszahlung, juengste zuerst.
        $pool = $db->getAll(
            "SELECT a.id, a.invnumber, a.transdate, a.amount, COALESCE(a.paid,0) AS paid,
                    round((a.amount - COALESCE(a.paid,0))::numeric, 2) AS open_amount, c.name AS customer_name
             FROM ar a
             LEFT JOIN customer c ON c.id = a.customer_id
             WHERE a.storno IS NOT TRUE
               AND a.transdate BETWEEN (:payout::date - 60) AND :payout::date
               AND (a.amount - COALESCE(a.paid,0)) > 0.005
             ORDER BY a.transdate DESC, a.id DESC",
            ['payout' => $line['payout_date']]
        );
        $byId = []; foreach ($pool as $r) $byId[(int)$r['id']] = $r;
        $used = [];
        $matches = [];
        foreach ($transactions as $tx) {
            $gross = round(floatval($tx['gross'] ?? 0), 2);
            $descr = (string)($tx['description'] ?? '');
            $hit = null;
            // (1) Rechnungsnummer in der SumUp-Beschreibung (Checkout aus dem ERP)
            if ($descr !== '') {
                foreach ($pool as $r) {
                    if (isset($used[(int)$r['id']]) || strlen($r['invnumber']) < 4) continue;
                    if (preg_match('/(^|[^[:alnum:]])' . preg_quote($r['invnumber'], '/') . '($|[^[:alnum:]])/', $descr)) { $hit = $r; break; }
                }
            }
            // (2) exakt offener Betrag = Zahlbetrag (Pool ist nach Datum absteigend → naechstliegende Rechnung)
            if (!$hit && $gross > 0) {
                foreach ($pool as $r) {
                    if (isset($used[(int)$r['id']])) continue;
                    if (abs(floatval($r['open_amount']) - $gross) < 0.005) { $hit = $r; break; }
                }
            }
            if ($hit) $used[(int)$hit['id']] = true;
            $matches[] = [
                'code'        => $tx['code'] ?? null,
                'timestamp'   => $tx['timestamp'] ?? null,
                'gross'       => $gross,
                'description' => $descr,
                'invoice'     => $hit ? $mapInv($hit) : null,
            ];
        }
        $matched   = array_values(array_filter(array_map(fn($m) => $m['invoice'], $matches)));
        $unmatched = count(array_filter($matches, fn($m) => $m['invoice'] === null));

        // Weitere offene Rechnungen (14 Tage vor der Auszahlung) fuer die manuelle Wahl.
        $others = [];
        foreach ($pool as $r) {
            if (isset($used[(int)$r['id']])) continue;
            if (strtotime($r['transdate']) < strtotime($line['payout_date'] . ' -14 days')) continue;
            $others[] = $mapInv($r);
            if (count($others) >= 40) break;
        }

        resultInfo(true, '', [
            'gross'           => (float) $line['gross'],
            'payout_date'     => $line['payout_date'],
            'period_from'     => $line['period_from'],
            'period_to'       => $line['period_to'],
            'mode'            => 'transactions',
            'found'           => $unmatched === 0,
            'ambiguous'       => false,
            'transactions'    => $matches,
            'unmatched_count' => $unmatched,
            'candidate_count' => count($matched) + count($others),
            'invoices'        => $matched,
            'all_candidates'  => array_merge($matched, $others),
        ]);
        return;
    }

    // ── Weg 2: Teilsumme im abgedeckten Zeitraum (ohne Einzelzahlungen) ───
    $candidates = $db->getAll(
        "SELECT a.id, a.invnumber, a.transdate, a.amount, COALESCE(a.paid,0) AS paid,
                (a.amount - COALESCE(a.paid,0)) AS open_amount, c.name AS customer_name
         FROM ar a
         LEFT JOIN customer c ON c.id = a.customer_id
         WHERE a.storno IS NOT TRUE
           AND a.transdate BETWEEN (:from::date - 7) AND :to
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

    resultInfo(true, '', [
        'gross'           => (float) $line['gross'],
        'payout_date'     => $line['payout_date'],
        'period_from'     => $line['period_from'],
        'period_to'       => $line['period_to'],
        'mode'            => 'subset',
        'found'           => $count > 0,
        'ambiguous'       => $count > 1,
        'transactions'    => [],
        'unmatched_count' => 0,
        'candidate_count' => count($items),
        'invoices'        => array_map(function ($id) use ($byId, $mapInv) { return $mapInv($byId[$id]); }, $best),
        'all_candidates'  => array_map($mapInv, $candidates),
    ]);
}

/**
 * Buchungsplan einer Kartenabrechnungszeile ermitteln — gemeinsamer Kern von
 * Vorschau (previewCardSettlementBooking) und Buchung (bookCardSettlementLine),
 * damit die Vorschau exakt das zeigt, was gebucht wird.
 *
 * Vorzeichen wie kivitendo (acc_trans): Soll = negativ, Haben = positiv.
 *   Bank            −net    (Geldeingang, Soll)
 *   Gebuehr/Aufwand −fee    (Soll)
 *   Forderungen     +pay    je Rechnung (Haben, Ausgleich) — Summe = gross
 *   oder Verrechnungskonto +gross (Haben), wenn keine Rechnungen gewaehlt sind.
 *
 * @return array {ok:true, bank, line, net, fee, gross, transdate, descr, settleList, legs}
 *               oder {ok:false, code, msg}
 */
function _settlementBookingPlan($db, array $data) {
    $btId       = intval($data['bank_transaction_id'] ?? 0);
    $lineId     = intval($data['settlement_line_id'] ?? 0);
    $feeChartId = intval($data['fee_chart_id'] ?? 0);
    $clrChartId = intval($data['clearing_chart_id'] ?? 0);

    if ($btId <= 0 || $lineId <= 0) return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'msg' => 'Umsatz- und Zeilen-ID erforderlich'];
    if ($feeChartId <= 0)           return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'msg' => 'Gebührenkonto fehlt'];

    // Bankumsatz + Bankkonto-Konto laden.
    $bank = $db->getOne(
        "SELECT bt.id, bt.amount, bt.transdate, COALESCE(bte.match_status, 'unmatched') AS match_status, bt.purpose,
                ba.chart_id AS bank_chart_id, bc.link AS bank_link
         FROM bank_transactions bt
         LEFT JOIN bank_transactions_ext bte ON bte.bank_transaction_id = bt.id
         JOIN bank_accounts ba ON ba.id = bt.local_bank_account_id
         JOIN chart bc ON bc.id = ba.chart_id
         WHERE bt.id = :id",
        ['id' => $btId]
    );
    if (!$bank) return ['ok' => false, 'code' => 'NOT_FOUND', 'msg' => 'Bankumsatz oder Bankkonto nicht gefunden'];
    if ($bank['match_status'] === 'booked') return ['ok' => false, 'code' => 'ALREADY_BOOKED', 'msg' => 'Umsatz ist bereits gebucht'];

    // Abrechnungszeile + Kreditor laden.
    $line = $db->getOne(
        "SELECT l.id, l.settlement_id, l.gross, l.fee, l.net, l.status, l.payout_date, l.reference,
                s.provider, s.vendor_id
         FROM payment_settlement_lines l
         JOIN payment_settlements s ON s.id = l.settlement_id
         WHERE l.id = :id",
        ['id' => $lineId]
    );
    if (!$line) return ['ok' => false, 'code' => 'NOT_FOUND', 'msg' => 'Abrechnungszeile nicht gefunden'];
    if ($line['status'] === 'booked') return ['ok' => false, 'code' => 'ALREADY_BOOKED', 'msg' => 'Zeile ist bereits gebucht'];

    // Plausibilitaet: Nettoauszahlung muss dem Bankbetrag entsprechen.
    if (round((float)$line['net'], 2) !== round((float)$bank['amount'], 2)) {
        return ['ok' => false, 'code' => 'AMOUNT_MISMATCH', 'msg' => 'Nettoauszahlung der Zeile entspricht nicht dem Bankbetrag'];
    }

    $feeChart = $db->getOne("SELECT id, accno, description, link FROM chart WHERE id = :id", ['id' => $feeChartId]);
    if (!$feeChart) return ['ok' => false, 'code' => 'NOT_FOUND', 'msg' => 'Gebührenkonto nicht gefunden'];
    $clrChart = $clrChartId > 0 ? $db->getOne("SELECT id, accno, description, link FROM chart WHERE id = :id", ['id' => $clrChartId]) : null;
    $bankChart = $db->getOne("SELECT id, accno, description, link FROM chart WHERE id = :id", ['id' => $bank['bank_chart_id']]);

    $net   = round((float)$bank['amount'], 2);
    $fee   = round((float)$line['fee'], 2);
    // Konsistenz erzwingen: gross = net + fee (Rundungsdifferenzen der Datei abfangen).
    $gross = round($net + $fee, 2);

    $transdate = $bank['transdate'];
    $descr     = 'Kartenabrechnung ' . $line['provider'] . ' (Auszahlung ' . $line['payout_date'] . ')';

    // Optionaler Rechnungsausgleich vorbereiten + validieren.
    $arIds = $data['ar_ids'] ?? [];
    if (!is_array($arIds)) $arIds = [];
    $arIds = array_values(array_unique(array_map('intval', $arIds)));
    $settleList = [];
    if (count($arIds) > 0) {
        $sumCents = 0;
        foreach ($arIds as $arId) {
            if ($arId <= 0) continue;
            $ar = $db->getOne("SELECT id, invnumber, amount, COALESCE(paid,0) AS paid FROM ar WHERE id = :id AND storno IS NOT TRUE", ['id' => $arId]);
            if (!$ar) return ['ok' => false, 'code' => 'NOT_FOUND', 'msg' => 'Rechnung #' . $arId . ' nicht gefunden'];
            $pay = round((float)$ar['amount'] - (float)$ar['paid'], 2);
            if ($pay <= 0) return ['ok' => false, 'code' => 'ALREADY_PAID', 'msg' => 'Rechnung ' . $ar['invnumber'] . ' ist bereits bezahlt'];
            // Kontroll-Konto traegt den Token 'AR' EXAKT (':'-getrennte Liste).
            // LIKE '%AR%' wuerde auch 'AR_amount'/'AR_tax' treffen und die Zahlung
            // auf dem Erloes- statt dem Forderungskonto ausgleichen.
            $fk = $db->getOne(
                "SELECT c.id AS chart_id, c.accno, c.description FROM acc_trans at JOIN chart c ON c.id = at.chart_id
                 WHERE at.trans_id = :tid AND at.chart_link ~ :token_link
                 ORDER BY at.acc_trans_id ASC LIMIT 1",
                ['tid' => $arId, 'token_link' => '(^|:)AR($|:)']
            );
            // Fallback fuer Rechnungen ohne GL-Erstbuchung: Standard-Forderungskonto.
            if (!$fk) {
                $fk = $db->getOne("SELECT id AS chart_id, accno, description FROM chart WHERE link = 'AR' ORDER BY accno ASC LIMIT 1");
                writeLog("_settlementBookingPlan: AR #{$arId} (Rg {$ar['invnumber']}) ohne acc_trans — Fallback-Forderungskonto " . ($fk['chart_id'] ?? 'KEINS'), true, DLOG_INF);
            }
            if (!$fk) return ['ok' => false, 'code' => 'DATA_ERROR', 'msg' => 'Forderungskonto der Rechnung ' . $ar['invnumber'] . ' nicht ermittelbar'];
            $settleList[] = ['ar_id' => $arId, 'invnumber' => $ar['invnumber'], 'pay' => $pay,
                             'fk_chart_id' => intval($fk['chart_id']), 'fk_accno' => $fk['accno'], 'fk_description' => $fk['description']];
            $sumCents += (int) round($pay * 100);
        }
        if ($sumCents !== (int) round($gross * 100)) {
            return ['ok' => false, 'code' => 'AMOUNT_MISMATCH', 'msg' => 'Summe der gewählten Rechnungen entspricht nicht dem Bruttobetrag'];
        }
    }

    // Ohne Rechnungsauswahl braucht es ein Verrechnungskonto fuer den Bruttobetrag.
    if (count($settleList) === 0 && !$clrChart) {
        return ['ok' => false, 'code' => 'VALIDATION_ERROR', 'msg' => 'Ohne Rechnungsauswahl ist ein Verrechnungskonto erforderlich'];
    }

    // Buchungsbeine (Reihenfolge = Buchungsreihenfolge; Bank zuerst wegen Mapping).
    $legs = [];
    $legs[] = ['role' => 'bank', 'chart_id' => intval($bank['bank_chart_id']), 'accno' => $bankChart['accno'] ?? '', 'description' => $bankChart['description'] ?? '',
               'amount' => -$net, 'memo' => 'Kartenauszahlung Umsatz #' . $btId, 'link' => $bank['bank_link'] ?? ''];
    $legs[] = ['role' => 'fee', 'chart_id' => intval($feeChart['id']), 'accno' => $feeChart['accno'], 'description' => $feeChart['description'],
               'amount' => -$fee, 'memo' => 'Transaktionsgebühr ' . $line['provider'], 'link' => $feeChart['link'] ?? ''];
    if (count($settleList) > 0) {
        foreach ($settleList as $s) {
            $legs[] = ['role' => 'ar', 'chart_id' => $s['fk_chart_id'], 'accno' => $s['fk_accno'], 'description' => $s['fk_description'],
                       'amount' => $s['pay'], 'memo' => 'Kartenzahlung ' . $line['provider'] . ' Rg ' . $s['invnumber'], 'link' => 'AR_paid', 'ar_id' => $s['ar_id']];
        }
    } else {
        $legs[] = ['role' => 'clearing', 'chart_id' => intval($clrChart['id']), 'accno' => $clrChart['accno'], 'description' => $clrChart['description'],
                   'amount' => $gross, 'memo' => 'Kartenumsätze ' . $line['provider'], 'link' => $clrChart['link'] ?? ''];
    }

    return ['ok' => true, 'bank' => $bank, 'line' => $line, 'net' => $net, 'fee' => $fee, 'gross' => $gross,
            'transdate' => $transdate, 'descr' => $descr, 'settleList' => $settleList, 'legs' => $legs,
            'fee_chart_id' => $feeChartId, 'clr_chart_id' => $clrChartId];
}

/**
 * Buchungsvorschau: zeigt die Hauptbuch-Zeilen, die bookCardSettlementLine mit
 * denselben Parametern schreiben wuerde — ohne etwas zu veraendern.
 *
 * @param int   $data['bank_transaction_id'] Bankumsatz
 * @param int   $data['settlement_line_id']  Auszahlungszeile
 * @param int   $data['fee_chart_id']        Gebuehrenkonto
 * @param int   $data['clearing_chart_id']   Verrechnungskonto (nur ohne Rechnungen)
 * @param array $data['ar_ids']              auszugleichende Ausgangsrechnungen
 * @testdata {"bank_transaction_id": 1, "settlement_line_id": 1, "fee_chart_id": 100, "ar_ids": [1,2]}
 */
function previewCardSettlementBooking($data) {
    $db = DbhCompany::begin();
    $plan = _settlementBookingPlan($db, $data);
    if (!$plan['ok']) { resultInfo(false, $plan['code'], $plan['msg']); return; }

    $balance = 0.0;
    $legs = [];
    foreach ($plan['legs'] as $l) {
        $balance += $l['amount'];
        $legs[] = [
            'role' => $l['role'], 'accno' => $l['accno'], 'description' => $l['description'], 'memo' => $l['memo'],
            'debit'  => $l['amount'] < 0 ? round(-$l['amount'], 2) : 0,   // Soll
            'credit' => $l['amount'] > 0 ? round($l['amount'], 2) : 0,    // Haben
        ];
    }
    resultInfo(true, '', [
        'description' => $plan['descr'],
        'transdate'   => $plan['transdate'],
        'legs'        => $legs,
        'balanced'    => abs(round($balance, 2)) < 0.005,
        'invoices'    => array_map(fn($s) => ['ar_id' => $s['ar_id'], 'invnumber' => $s['invnumber'], 'pay' => $s['pay']], $plan['settleList']),
    ]);
}

/**
 * Kartenabrechnungszeile buchen (gl + acc_trans, kivitendo-kompatibel).
 *
 * Beine siehe _settlementBookingPlan. Mit Rechnungsauswahl werden die
 * Forderungen direkt ausgeglichen (ar.paid), ohne Rechnungsauswahl wird der
 * Bruttobetrag aufs Verrechnungskonto gebucht.
 *
 * @param int   $data['bank_transaction_id'] Bankumsatz
 * @param int   $data['settlement_line_id']  Auszahlungszeile
 * @param int   $data['fee_chart_id']        Gebuehrenkonto
 * @param int   $data['clearing_chart_id']   Verrechnungskonto (nur ohne Rechnungen)
 * @param array $data['ar_ids']              auszugleichende Ausgangsrechnungen
 * @testdata {"bank_transaction_id": 1, "settlement_line_id": 1, "fee_chart_id": 100, "ar_ids": [1,2]}
 */
function bookCardSettlementLine($data) {
    $db = DbhCompany::begin();
    $plan = _settlementBookingPlan($db, $data);
    if (!$plan['ok']) { resultInfo(false, $plan['code'], $plan['msg']); return; }

    $btId       = intval($plan['bank']['id']);
    $lineId     = intval($plan['line']['id']);
    $transdate  = $plan['transdate'];
    $employeeId = mitarbeiterId($data);

    $db->beginTransaction();

    // GL-Kopf.
    $gl = $db->getOne(
        "INSERT INTO gl (reference, description, transdate, gldate, employee_id)
         VALUES (:ref, :descr, :td, :td, :eid) RETURNING id",
        ['ref' => 'SETTLEMENT', 'descr' => $plan['descr'], 'td' => $transdate, 'eid' => $employeeId]
    );
    $glId = intval($gl['id']);

    $bankAccTransId = null;
    foreach ($plan['legs'] as $leg) {
        $row = $db->getOne(
            "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
             VALUES (:t, :c, :a, :td, :td, 'SETTLEMENT', :memo, 0, 0, :link) RETURNING acc_trans_id",
            ['t' => $glId, 'c' => $leg['chart_id'], 'a' => $leg['amount'], 'td' => $transdate, 'memo' => $leg['memo'], 'link' => $leg['link']]
        );
        if ($leg['role'] === 'bank') $bankAccTransId = intval($row['acc_trans_id']);
        if ($leg['role'] === 'ar') {
            $db->execute("UPDATE ar SET paid = COALESCE(paid,0) + :inc WHERE id = :id", ['inc' => $leg['amount'], 'id' => $leg['ar_id']]);
        }
    }

    // Bankumsatz mit der Buchung verknuepfen (kivitendo-Mapping, Bank-Bein + gl_id).
    $db->execute(
        "INSERT INTO bank_transaction_acc_trans (bank_transaction_id, acc_trans_id, gl_id) VALUES (:bt, :at, :gl)",
        ['bt' => $btId, 'at' => $bankAccTransId, 'gl' => $glId]
    );
    $db->execute("DELETE FROM bank_transaction_matches WHERE bank_transaction_id = :id", ['id' => $btId]);
    $db->execute("WITH u AS (UPDATE bank_transactions SET cleared = true WHERE id = :id) INSERT INTO bank_transactions_ext (bank_transaction_id, match_status) VALUES (:id2, 'booked') ON CONFLICT (bank_transaction_id) DO UPDATE SET match_status = EXCLUDED.match_status", ['id' => $btId, 'id2' => $btId]);

    // Abrechnungszeile abschliessen + Konten beim Kreditor merken.
    $settleList = $plan['settleList'];
    $db->execute(
        "UPDATE payment_settlement_lines
         SET status = 'booked', gl_id = :gl, matched_bank_transaction_id = :bt, settled_ar_ids = :ar, mtime = NOW()
         WHERE id = :id",
        ['gl' => $glId, 'bt' => $btId, 'id' => $lineId,
         'ar' => count($settleList) ? json_encode(array_map(fn($s) => ['ar_id' => $s['ar_id'], 'pay' => $s['pay']], $settleList)) : null]
    );
    $db->execute(
        "UPDATE payment_settlements SET fee_chart_id = :fee, clearing_chart_id = COALESCE(:clr, clearing_chart_id), mtime = NOW()
         WHERE id = :sid",
        ['fee' => $plan['fee_chart_id'], 'clr' => $plan['clr_chart_id'] > 0 ? $plan['clr_chart_id'] : null, 'sid' => $plan['line']['settlement_id']]
    );

    $db->commit();

    resultInfo(true, 'Gebucht', [
        'gl_id'         => $glId,
        'net'           => $plan['net'],
        'fee'           => $plan['fee'],
        'gross'         => $plan['gross'],
        'settled_count' => count($settleList),
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
        $db->execute("WITH u AS (UPDATE bank_transactions SET cleared = false WHERE id = :id) INSERT INTO bank_transactions_ext (bank_transaction_id, match_status) VALUES (:id2, 'unmatched') ON CONFLICT (bank_transaction_id) DO UPDATE SET match_status = EXCLUDED.match_status", ['id' => $btId, 'id2' => $btId]);
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
