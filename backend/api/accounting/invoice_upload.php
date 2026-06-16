<?php
// backend/api/accounting/invoice_upload.php

/**
 * Eingangsrechnung hochladen und per KI (Weroni) analysieren.
 * Extrahiert Lieferant, Betraege, Steuern, Positionen und generiert Buchungsvorschlag.
 *
 * @param string $data['file_base64']  Base64-kodierter PDF-Inhalt
 * @param string $data['filename']     Original-Dateiname
 * @param string $data['mime_type']    MIME-Typ (application/pdf)
 * @testdata {"filename": "rechnung.pdf", "mime_type": "application/pdf", "file_base64": ""}
 */
function uploadInvoiceDocument($data) {
    set_time_limit(180);

    $db = DbhCompany::begin();

    $fileBase64 = $data['file_base64'] ?? '';
    $filename = $data['filename'] ?? 'dokument.pdf';
    $mimeType = $data['mime_type'] ?? 'application/pdf';

    if (empty($fileBase64)) {
        throw new ApiError('VALIDATION_ERROR', 'file_base64 erforderlich');
    }

    $fileContent = base64_decode($fileBase64, true);
    if ($fileContent === false) {
        throw new ApiError('VALIDATION_ERROR', 'Ungueltige Base64-Daten');
    }

    // Duplikaterkennung per SHA-256
    $fileHash = hash('sha256', $fileContent);
    $existing = $db->getOne(
        "SELECT id, original_name, status FROM accounting_documents WHERE file_hash = :hash",
        [':hash' => $fileHash]
    );
    if ($existing) {
        throw new ApiError('DUPLICATE_DOCUMENT', 'Dieses Dokument wurde bereits hochgeladen (ID: ' . $existing['id'] . ', ' . $existing['original_name'] . ')');
    }

    // Datei speichern
    $accountingDir = fmDataDir() . '/accounting';
    if (!is_dir($accountingDir)) {
        fmMkdir($accountingDir);
    }

    // Dokument in DB anlegen
    $db->execute(
        "INSERT INTO accounting_documents (original_name, mime_type, file_size, file_hash, status, employee_id)
         VALUES (:name, :mime, :size, :hash, 'processing', :eid)",
        [
            ':name' => $filename,
            ':mime' => $mimeType,
            ':size' => strlen($fileContent),
            ':hash' => $fileHash,
            ':eid'  => intval($_SESSION['employee_id'] ?? 0)
        ]
    );
    $doc = $db->getOne(
        "SELECT id FROM accounting_documents WHERE file_hash = :hash ORDER BY id DESC LIMIT 1",
        [':hash' => $fileHash]
    );
    $docId = $doc['id'];

    // Datei auf Disk speichern
    $safeFilename = $docId . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '_', $filename);
    $storedPath = $accountingDir . '/' . $safeFilename;
    file_put_contents($storedPath, $fileContent);

    $db->execute(
        "UPDATE accounting_documents SET stored_path = :path WHERE id = :id",
        [':path' => 'accounting/' . $safeFilename, ':id' => $docId]
    );

    // E-Rechnung-Fast-Path: wenn das Dokument ZUGFeRD/XRechnung-XML enthält,
    // strukturierte Daten direkt extrahieren und den KI-Call überspringen.
    require_once __DIR__.'/../faktura/einvoice_reader.php';
    $einvoiceData = extractEInvoiceData($fileContent, $mimeType);

    if ($einvoiceData !== null) {
        $extracted  = $einvoiceData;
        $confidence = 1.0;
        $db->execute(
            "UPDATE accounting_documents SET status = 'extracted', extracted_data = :data, extraction_confidence = :conf WHERE id = :id",
            [
                ':data' => json_encode($extracted, JSON_UNESCAPED_UNICODE),
                ':conf' => $confidence,
                ':id'   => $docId
            ]
        );
    } else {
    // API-Key laden
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', 'accounting_ai_model', 'accounting_default_tax_rate')"
    );
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        $db->execute("UPDATE accounting_documents SET status = 'error' WHERE id = :id", [':id' => $docId]);
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key nicht konfiguriert');
    }

    $aiModel = $config['accounting_ai_model'] ?? 'claude-sonnet-4-6-20250514';

    // Kontenrahmen laden (fuer KI-Kontext)
    $accounts = $db->getAll(
        "SELECT accno, description, link, category FROM chart WHERE NOT invalid ORDER BY accno LIMIT 200",
        []
    );
    $accountList = [];
    foreach ($accounts as $a) {
        $accountList[] = $a['accno'] . ' ' . $a['description'] . ($a['link'] ? ' [' . $a['link'] . ']' : '');
    }

    // Steuersaetze laden
    $taxes = $db->getAll(
        "SELECT t.id, t.rate, t.taxkey, t.taxdescription, c.accno AS tax_account
         FROM tax t LEFT JOIN chart c ON c.id = t.chart_id
         WHERE t.rate > 0 ORDER BY t.rate",
        []
    );
    $taxList = [];
    foreach ($taxes as $t) {
        $taxList[] = "Steuerschluessel {$t['taxkey']}: {$t['taxdescription']} ({$t['rate']}%) → Konto {$t['tax_account']}";
    }

    // Bekannte Lieferanten fuer Kontext (letzte 50 mit Kontenzuordnung)
    $knownVendors = $db->getAll(
        "SELECT DISTINCT ON (v.id) v.id, v.name, v.iban, v.taxnumber, v.ustid,
                ar.debit_account AS last_debit, ar.credit_account AS last_credit
         FROM vendor v
         LEFT JOIN accounting_bookings ar ON ar.vendor_id = v.id AND ar.type = 'incoming'
         WHERE v.obsolete IS NOT TRUE
         ORDER BY v.id, ar.itime DESC NULLS LAST
         LIMIT 50",
        []
    );
    $vendorContext = [];
    foreach ($knownVendors as $kv) {
        $line = $kv['name'];
        if ($kv['iban']) $line .= ' (IBAN: ' . $kv['iban'] . ')';
        if ($kv['last_debit']) $line .= ' → Konto ' . $kv['last_debit'] . '/' . $kv['last_credit'];
        $vendorContext[] = $line;
    }

    // Claude API aufrufen
    $extractionPrompt = <<<PROMPT
Du bist Weroni, eine KI-Buchhalterin. Analysiere diese Eingangsrechnung und extrahiere alle relevanten Daten.
Antworte AUSSCHLIESSLICH mit einem validen JSON-Objekt (kein Markdown, kein Text drumherum).

KONTENRAHMEN (verfuegbare Sachkonten):
%ACCOUNTS%

STEUERSCHLUESSEL:
%TAXES%

BEKANNTE LIEFERANTEN:
%VENDORS%

Extrahiere folgende Felder und gib sie als JSON zurueck:

{
  "vendor": {
    "name": "Firmenname des Lieferanten",
    "street": "Strasse und Hausnummer",
    "zipcode": "PLZ",
    "city": "Ort",
    "country": "DE",
    "taxnumber": "Steuernummer falls vorhanden",
    "ustid": "USt-IdNr falls vorhanden",
    "iban": "IBAN falls vorhanden",
    "bic": "BIC falls vorhanden",
    "phone": "Telefon falls vorhanden",
    "email": "E-Mail falls vorhanden"
  },
  "invoice": {
    "number": "Rechnungsnummer",
    "date": "YYYY-MM-DD",
    "due_date": "YYYY-MM-DD oder null",
    "delivery_date": "YYYY-MM-DD oder null"
  },
  "amounts": {
    "gross": 119.00,
    "net": 100.00,
    "tax": 19.00,
    "tax_rate": 19.00,
    "currency": "EUR"
  },
  "positions": [
    {
      "description": "Beschreibung der Position",
      "quantity": 1,
      "unit_price": 100.00,
      "net_amount": 100.00,
      "tax_rate": 19.00,
      "gross_amount": 119.00
    }
  ],
  "booking_suggestion": {
    "debit_account": "Sachkonto-Nummer (Aufwandskonto)",
    "credit_account": "Kreditorenkonto oder 1600",
    "tax_key": 9,
    "description": "Kurzer Buchungstext"
  },
  "confidence": 0.95,
  "notes": "Anmerkungen falls etwas unklar ist"
}

REGELN:
- Betraege immer als Zahlen (nicht als Strings)
- Datumsformat immer YYYY-MM-DD
- Steuersatz als Zahl (19, 7, 0)
- Fuer das Sollkonto: waehle das passende Aufwandskonto aus dem Kontenrahmen (z.B. 4980 fuer Reparaturen, 6300 fuer Bueromat., 4530 fuer Kfz-Kosten)
- Fuer das Habenkonto: 1600 (Verbindlichkeiten aus Lieferungen) ist Standard
- Steuerschluessel: 9 fuer 19% VSt, 8 fuer 7% VSt, 0 fuer steuerfrei
- Wenn ein bekannter Lieferant erkannt wird, verwende dessen bisherige Kontenzuordnung
- confidence: 0.0-1.0, wie sicher du dir bei der Extraktion bist
PROMPT;

    $extractionPrompt = str_replace('%ACCOUNTS%', implode("\n", $accountList), $extractionPrompt);
    $extractionPrompt = str_replace('%TAXES%', implode("\n", $taxList), $extractionPrompt);
    $extractionPrompt = str_replace('%VENDORS%', implode("\n", $vendorContext), $extractionPrompt);

    // PDF als Document-Block senden
    if (str_starts_with($mimeType, 'application/pdf')) {
        $contentBlock = [
            'type' => 'document',
            'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $fileBase64]
        ];
    } else {
        $contentBlock = [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => $mimeType, 'data' => $fileBase64]
        ];
    }

    $requestBody = json_encode([
        'model' => $aiModel,
        'max_tokens' => 4096,
        'messages' => [[
            'role' => 'user',
            'content' => [$contentBlock, ['type' => 'text', 'text' => $extractionPrompt]]
        ]]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $anthropicKey,
            'anthropic-version: 2023-06-01'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $db->execute("UPDATE accounting_documents SET status = 'error' WHERE id = :id", [':id' => $docId]);
        throw new ApiError('CLAUDE_API_ERROR', 'KI-Analyse fehlgeschlagen (HTTP ' . $httpCode . ')');
    }

    $responseData = json_decode($response, true);
    $aiText = $responseData['content'][0]['text'] ?? '';

    // JSON aus Antwort extrahieren (falls in Markdown-Block)
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $aiText, $matches)) {
        $aiText = $matches[1];
    }

    $extracted = json_decode($aiText, true);
    if (!$extracted || !isset($extracted['amounts'])) {
        $db->execute(
            "UPDATE accounting_documents SET status = 'error', extracted_data = :data WHERE id = :id",
            [':data' => json_encode(['raw_response' => $aiText], JSON_UNESCAPED_UNICODE), ':id' => $docId]
        );
        throw new ApiError('EXTRACTION_ERROR', 'KI konnte die Rechnung nicht analysieren');
    }

    $confidence = floatval($extracted['confidence'] ?? 0.5);

    // Extraktion in DB speichern
    $db->execute(
        "UPDATE accounting_documents SET status = 'extracted', extracted_data = :data, extraction_confidence = :conf WHERE id = :id",
        [
            ':data' => json_encode($extracted, JSON_UNESCAPED_UNICODE),
            ':conf' => $confidence,
            ':id'   => $docId
        ]
    );
    } // Ende else (KI-Extraktion)

    // Buchungsvorschlag + Beträge (zuerst – für die Kleinbetrags-Entscheidung)
    $booking = $extracted['booking_suggestion'] ?? [];
    $amounts = $extracted['amounts'] ?? [];
    $invoice = $extracted['invoice'] ?? [];
    $gross   = floatval($amounts['gross'] ?? 0);

    // Lieferanten-Auflösung (neue Regeln): IBAN/USt-ID exakt oder Name ≥90 % → auto;
    // 60–90 % → Freigabe mit Kandidaten; sonst echter Kreditor (mit IBAN/USt) bzw.
    // Sammelkreditor „Diverse" (Kleinbeleg/Tankstelle), echter Name im Buchungstext.
    $vendorData = $extracted['vendor'] ?? [];
    $vres = _iv_resolveVendor($db, $vendorData, $gross);
    $vendorId = intval($vres['vendor_id'] ?? 0);
    if ($vres['status'] === 'new') {
        $vendorId = _iv_createVendor($db, $vendorData);
        $vres['vendor_id'] = $vendorId;
    }
    if (!empty($vres['is_collective']) && !empty($vres['original_name']) && empty($booking['description'])) {
        $booking['description'] = $vres['original_name'];
    }
    $vendorMatch = [
        'vendor_id'     => $vendorId ?: null,
        'vendor_name'   => $vres['vendor_name'],
        'match_type'    => $vres['status'],
        'match_score'   => $vres['match_score'],
        'candidates'    => $vres['candidates'],
        'is_new'        => ($vres['status'] === 'new'),
        'is_collective' => !empty($vres['is_collective']),
        'status'        => $vres['status'],
    ];

    if ($vendorId) {
        $db->execute(
            "UPDATE accounting_documents SET vendor_id = :vid WHERE id = :id",
            [':vid' => $vendorId, ':id' => $docId]
        );
        // Kontenzuordnung vom letzten verbuchten Beleg dieses Lieferanten lernen
        $lastBooking = $db->getOne(
            "SELECT debit_account, credit_account, tax_key FROM accounting_bookings
             WHERE vendor_id = :vid AND type = 'incoming' AND ap_id IS NOT NULL
             ORDER BY booking_date DESC LIMIT 1",
            [':vid' => $vendorId]
        );
        if ($lastBooking) {
            $booking['debit_account']  = $lastBooking['debit_account'];
            $booking['credit_account'] = $lastBooking['credit_account'];
            $booking['tax_key']        = $lastBooking['tax_key'];
        }
    }

    // Buchungsnummer generieren
    $bookingRef = $db->getOne("SELECT next_booking_number() AS ref", []);

    // Buchung anlegen (Status: pending = wartet auf Freigabe)
    $db->execute(
        "INSERT INTO accounting_bookings
            (booking_date, invoice_date, due_date, amount, net_amount, tax_amount, tax_rate, tax_key,
             debit_account, credit_account, invoice_number, description, reference,
             type, status, vendor_id, document_id, ai_generated, ai_confidence, ai_notes, employee_id)
         VALUES
            (:bdate, :idate, :ddate, :amount, :net, :tax, :trate, :tkey,
             :debit, :credit, :invnr, :desc, :ref,
             'incoming', 'pending', :vid, :docid, TRUE, :conf, :notes, :eid)",
        [
            ':bdate'  => $invoice['date'] ?? date('Y-m-d'),
            ':idate'  => $invoice['date'] ?? date('Y-m-d'),
            ':ddate'  => $invoice['due_date'] ?? null,
            ':amount' => floatval($amounts['gross'] ?? 0),
            ':net'    => floatval($amounts['net'] ?? 0),
            ':tax'    => floatval($amounts['tax'] ?? 0),
            ':trate'  => floatval($amounts['tax_rate'] ?? 19),
            ':tkey'   => intval($booking['tax_key'] ?? 9),
            ':debit'  => $booking['debit_account'] ?? '4980',
            ':credit' => $booking['credit_account'] ?? '1600',
            ':invnr'  => $invoice['number'] ?? null,
            ':desc'   => $booking['description'] ?? $vendorData['name'] ?? 'Eingangsrechnung',
            ':ref'    => $bookingRef['ref'],
            ':vid'    => $vendorMatch['vendor_id'] ?? null,
            ':docid'  => $docId,
            ':conf'   => $confidence,
            ':notes'  => $extracted['notes'] ?? null,
            ':eid'    => intval($_SESSION['employee_id'] ?? 0)
        ]
    );

    $bookingRow = $db->getOne(
        "SELECT id FROM accounting_bookings WHERE document_id = :docid ORDER BY id DESC LIMIT 1",
        [':docid' => $docId]
    );
    $bookingId = $bookingRow['id'];

    // Dokument mit Buchung verknuepfen
    $db->execute(
        "UPDATE accounting_documents SET booking_id = :bid WHERE id = :id",
        [':bid' => $bookingId, ':id' => $docId]
    );

    // Lieferanten-Auflösung (inkl. Kandidaten bei Unsicherheit) am Dokument ablegen
    // – die Freigabe-UI kann daraus den Kandidaten-Picker bauen.
    $db->execute(
        "UPDATE accounting_documents
         SET extracted_data = jsonb_set(COALESCE(extracted_data, '{}'::jsonb), '{vendor_resolution}', :vr::jsonb)
         WHERE id = :id",
        [':vr' => json_encode($vres, JSON_UNESCAPED_UNICODE), ':id' => $docId]
    );

    // ── Hybrid: bei hoher Sicherheit automatisch ins Hauptbuch buchen (echte ap) ──
    // Sonst bleibt die Buchung 'pending' zur Freigabe.
    $autoBooked = false;
    $autoApId   = null;
    $debitChart = $db->getOne("SELECT id FROM chart WHERE accno = :a", [':a' => ($booking['debit_account'] ?? '')]);
    $canAutoBook = floatval($confidence) >= 0.85
        && $vendorId > 0
        && $vres['status'] !== 'ambiguous'
        && $debitChart
        && $gross > 0;
    if ($canAutoBook) {
        try {
            $autoApId   = _iv_postBooking($db, $bookingId);
            $autoBooked = ($autoApId !== null);
        } catch (ApiError $e) {
            // Buchung scheitert (z. B. Konto/Steuer unklar) → bleibt zur Freigabe liegen.
        }
    }

    // Buchungspositionen anlegen
    $positions = $extracted['positions'] ?? [];
    foreach ($positions as $idx => $pos) {
        $db->execute(
            "INSERT INTO accounting_booking_lines
                (booking_id, position, description, quantity, unit_price, net_amount, tax_rate, tax_amount, gross_amount)
             VALUES (:bid, :pos, :desc, :qty, :price, :net, :trate, :tax, :gross)",
            [
                ':bid'   => $bookingId,
                ':pos'   => $idx + 1,
                ':desc'  => $pos['description'] ?? '',
                ':qty'   => floatval($pos['quantity'] ?? 1),
                ':price' => floatval($pos['unit_price'] ?? 0),
                ':net'   => floatval($pos['net_amount'] ?? 0),
                ':trate' => floatval($pos['tax_rate'] ?? 19),
                ':tax'   => floatval(($pos['gross_amount'] ?? 0) - ($pos['net_amount'] ?? 0)),
                ':gross' => floatval($pos['gross_amount'] ?? 0)
            ]
        );
    }

    resultInfo(true, 'OK', [
        'document_id'  => $docId,
        'booking_id'   => $bookingId,
        'extracted'    => $extracted,
        'vendor'       => $vendorMatch,
        'reference'    => $bookingRef['ref'],
        'auto_booked'  => $autoBooked,           // true = automatisch ins Hauptbuch gebucht (echte ap)
        'ap_id'        => $autoApId,             // ID der echten Eingangsrechnung (falls auto)
        'needs_review' => !$autoBooked,          // false = fertig; true = Freigabe nötig
        'vendor_status' => $vres['status'],      // matched | collective | new | ambiguous
        'vendor_candidates' => $vres['candidates'],
    ]);
}

/**
 * Lieferant per Fuzzy-Match suchen oder neu anlegen
 */
function _matchOrCreateVendor($db, $vendorData) {
    $name = trim($vendorData['name'] ?? '');
    $iban = trim($vendorData['iban'] ?? '');
    $taxnumber = trim($vendorData['taxnumber'] ?? $vendorData['ustid'] ?? '');

    if (empty($name)) {
        return ['vendor_id' => null, 'match_type' => 'none', 'is_new' => false];
    }

    // Fuzzy-Suche
    $matches = $db->getAll(
        "SELECT * FROM find_vendor_fuzzy(:name, :iban, :tax)",
        [':name' => $name, ':iban' => $iban ?: null, ':tax' => $taxnumber ?: null]
    );

    if (!empty($matches) && floatval($matches[0]['match_score']) >= 0.6) {
        $match = $matches[0];
        $vendorId = intval($match['vendor_id']);

        // Alias speichern falls der Name abweicht
        if ($match['match_type'] === 'fuzzy_name' && floatval($match['match_score']) < 0.95) {
            $existingAlias = $db->getOne(
                "SELECT id FROM vendor_aliases WHERE vendor_id = :vid AND LOWER(alias_name) = LOWER(:name)",
                [':vid' => $vendorId, ':name' => $name]
            );
            if (!$existingAlias) {
                $db->execute(
                    "INSERT INTO vendor_aliases (vendor_id, alias_name, alias_iban)
                     VALUES (:vid, :name, :iban)",
                    [':vid' => $vendorId, ':name' => $name, ':iban' => $iban ?: null]
                );
            }
        }

        // IBAN aktualisieren falls leer
        if (!empty($iban)) {
            $vendor = $db->getOne("SELECT iban FROM vendor WHERE id = :id", [':id' => $vendorId]);
            if (empty($vendor['iban'])) {
                $db->execute("UPDATE vendor SET iban = :iban, mtime = NOW() WHERE id = :id",
                    [':iban' => $iban, ':id' => $vendorId]);
            }
        }

        return [
            'vendor_id'  => $vendorId,
            'vendor_name' => $match['vendor_name'],
            'match_type' => $match['match_type'],
            'match_score' => floatval($match['match_score']),
            'is_new'     => false
        ];
    }

    // Kein Match — neuen Lieferanten anlegen
    $vendornumber = $db->getOne(
        "SELECT COALESCE(MAX(CAST(vendornumber AS INTEGER)), 70000) + 1 AS next_nr
         FROM vendor WHERE vendornumber ~ '^\d+$'",
        []
    );

    $db->execute(
        "INSERT INTO vendor (name, street, zipcode, city, country, taxnumber, ustid, iban, bic, phone, email, vendornumber, taxzone_id, currency_id)
         VALUES (:name, :street, :zip, :city, :country, :tax, :ustid, :iban, :bic, :phone, :email, :vnr, 1, 1)",
        [
            ':name'    => $name,
            ':street'  => $vendorData['street'] ?? null,
            ':zip'     => $vendorData['zipcode'] ?? null,
            ':city'    => $vendorData['city'] ?? null,
            ':country' => $vendorData['country'] ?? 'DE',
            ':tax'     => $vendorData['taxnumber'] ?? null,
            ':ustid'   => $vendorData['ustid'] ?? null,
            ':iban'    => $iban ?: null,
            ':bic'     => $vendorData['bic'] ?? null,
            ':phone'   => $vendorData['phone'] ?? null,
            ':email'   => $vendorData['email'] ?? null,
            ':vnr'     => $vendornumber['next_nr']
        ]
    );

    $newVendor = $db->getOne(
        "SELECT id, name FROM vendor WHERE vendornumber = :vnr",
        [':vnr' => $vendornumber['next_nr']]
    );

    return [
        'vendor_id'   => intval($newVendor['id']),
        'vendor_name' => $newVendor['name'],
        'match_type'  => 'new',
        'match_score' => 0,
        'is_new'      => true
    ];
}

/**
 * Liefert ein hochgeladenes Dokument als PDF zurueck
 *
 * @param int $data['document_id'] Dokument-ID
 * @testdata {"document_id": 1}
 */
function getDocumentPdf($data) {
    $db = DbhCompany::begin();
    $docId = intval($data['document_id'] ?? 0);
    if (!$docId) throw new ApiError('VALIDATION_ERROR', 'document_id erforderlich');

    $doc = $db->getOne(
        "SELECT stored_path, original_name, mime_type FROM accounting_documents WHERE id = :id",
        [':id' => $docId]
    );
    if (!$doc || !$doc['stored_path']) throw new ApiError('DATA_NOT_FOUND', 'Dokument nicht gefunden');

    $filePath = fmDataDir() . '/' . $doc['stored_path'];

    if (!file_exists($filePath)) {
        throw new ApiError('DATA_NOT_FOUND', 'Datei nicht gefunden');
    }

    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Disposition: inline; filename="' . $doc['original_name'] . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

/**
 * Bereits gebuchte Eingangsrechnungen (Kreditoren) laden
 *
 * Zeigt die in kivitendo verbuchten Einkaufsrechnungen mit Lieferant,
 * Betrag und Zahlungsstatus — als Historie unter dem Upload-Bereich.
 *
 * @param int $data['limit'] Anzahl (Standard: 50)
 * @testdata {"limit": 50}
 */
function getIncomingInvoices($data) {
    $db = DbhCompany::begin();
    $limit = intval($data['limit'] ?? 50);

    $invoices = $db->getAll(<<<SQL
        SELECT a.id, a.invnumber, a.amount, COALESCE(a.paid, 0) AS paid,
               (a.amount - COALESCE(a.paid, 0)) AS open_amount,
               TO_CHAR(a.transdate, 'DD.MM.YYYY') AS transdate_fmt,
               TO_CHAR(a.duedate, 'DD.MM.YYYY') AS duedate_fmt,
               v.name AS vendor_name
        FROM ap a
        LEFT JOIN vendor v ON v.id = a.vendor_id
        ORDER BY a.transdate DESC, a.id DESC
        LIMIT :limit
    SQL, [':limit' => $limit]);

    resultInfo(true, '', ['invoices' => $invoices ?: []]);
}
