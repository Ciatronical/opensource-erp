<?php
// backend/api/letters/postal.php
//
// Postversand (Hybridbrief) für Briefe und Belege: Das Dokument wird wie beim
// Drucken als PDF erzeugt und elektronisch beim Briefdienstleister
// eingeliefert — der druckt, kuvertiert, frankiert und stellt zu. Welcher
// Dienstleister, steht in der Firmenkonfiguration (CRM → Briefversand).
//
// Jede Einlieferung ist eine Zeile in postal_dispatches samt Versandexemplar
// (PDF). Über record_table/record_id hängt sie am Dokument; die Faktura zeigt
// sie im Versandstatus neben E-Mail, WhatsApp und DHL, der Briefeditor im
// Versandprotokoll.

require_once __DIR__.'/postal_providers.php';
require_once __DIR__.'/../lib/mitarbeiter.php';
require_once __DIR__.'/../print/print.php';
require_once __DIR__.'/../print/template_engine.php';

/**
 * kivitendo-Tabelle zu einer Belegart (record_type der Druckvorlage).
 */
function postalRecordTable(string $recordType): string {
    static $map = [
        'letter'                    => 'letter',
        'invoice'                   => 'ar',
        'invoice_storno'            => 'ar',
        'credit_note'               => 'ar',
        'proforma'                  => 'ar',
        'purchase_invoice'          => 'ap',
        'order'                     => 'oe',
        'sales_order_intake'        => 'oe',
        'quotation'                 => 'oe',
        'request_quotation'         => 'oe',
        'purchase_quotation_intake' => 'oe',
        'purchase_order'            => 'oe',
        'delivery_order'            => 'delivery_orders',
        'purchase_delivery_order'   => 'delivery_orders',
    ];
    if (!isset($map[$recordType])) {
        throw new ApiError('VALIDATION_ERROR', 'Unbekannte Belegart: ' . $recordType);
    }
    return $map[$recordType];
}

/**
 * Rechte: Briefe brauchen das Briefrecht, Belege ein Belegrecht.
 */
function postalPermit(string $recordType): void {
    if ($recordType === 'letter') {
        permit(['sales_letter_edit', 'purchase_letter_edit'], false);
        return;
    }
    permit(['invoice_edit', 'vendor_invoice_edit', 'sales_order_edit', 'purchase_order_edit',
            'sales_quotation_edit', 'request_quotation_edit',
            'sales_delivery_order_edit', 'purchase_delivery_order_edit'], false);
}

/**
 * Inland oder Ausland — entscheidet über Porto und ob Einschreiben möglich ist.
 */
function postalShipping(?string $country): string {
    $c = strtolower(trim((string)$country));
    if ($c === '' || in_array($c, ['d', 'de', 'deu', 'deutschland', 'germany', 'brd'], true)) {
        return 'national';
    }
    return 'international';
}

/**
 * Seitenzahl eines PDFs (pdfinfo) — der Dienstleister rechnet je Blatt ab.
 */
function postalPdfPages(string $pdf): ?int {
    $tmp = tempnam(sys_get_temp_dir(), 'oserp_postal_');
    file_put_contents($tmp, $pdf);
    $out = @shell_exec('pdfinfo ' . escapeshellarg($tmp) . ' 2>/dev/null');
    @unlink($tmp);
    if ($out && preg_match('/^Pages:\s+(\d+)/m', $out, $m)) {
        return intval($m[1]);
    }
    return null;
}

/**
 * Dokument als PDF erzeugen — genau so, wie es der Druck erzeugt.
 *
 * @return array pdf, filename, recipient_name, recipient_address, country, cv (id/src), label
 */
function postalRenderDocument($db, string $recordType, int $recordId): array {
    if ($recordType === 'letter') {
        $print = letterPrintData($db, ['id' => $recordId]);
        if (empty($print['letter'])) {
            throw new ApiError('LETTER_NOT_FOUND', 'Brief nicht gefunden');
        }
        $render = letterRenderPdf($db, $print);
        if (isset($render['error'])) {
            throw new ApiError('PDF_ERROR', 'PDF konnte nicht erzeugt werden: ' . $render['error']);
        }
        $cv = $print['cv'] ?? [];
        $cp = $print['cp'] ?? [];
        $lines = array_filter([
            $cv['name'] ?? '',
            trim(($cp['cp_givenname'] ?? '') . ' ' . ($cp['cp_name'] ?? '')),
            $cv['street'] ?? '',
            trim(($cv['zipcode'] ?? '') . ' ' . ($cv['city'] ?? '')),
            $cv['country'] ?? '',
        ], fn($l) => trim((string)$l) !== '');
        return [
            'pdf'               => $render['pdf'],
            'filename'          => $render['filename'],
            'recipient_name'    => $cv['name'] ?? '',
            'recipient_address' => implode(', ', $lines),
            'country'           => $cv['country'] ?? '',
            'address_ok'        => trim((string)($cv['street'] ?? '')) !== '' && trim((string)($cv['zipcode'] ?? '')) !== '' && trim((string)($cv['city'] ?? '')) !== '',
            'label'             => 'Brief ' . ($print['letter']['letternumber'] ?? ''),
        ];
    }

    $lxCars = isLxCarsEnabled($db);
    $vars   = loadPrintData($db, $recordId, $recordType, $lxCars);
    if ($vars === false) {
        throw new ApiError('DOCUMENT_NOT_FOUND', 'Beleg nicht gefunden');
    }
    $error = null;
    $res = renderDocumentPdfFile($db, $recordId, $recordType, null, $lxCars, $error);
    if ($res === false) {
        throw new ApiError('PDF_ERROR', 'PDF konnte nicht erzeugt werden: ' . $error);
    }
    $pdf = file_get_contents($res['path']);
    $res['engine']->cleanup($res['path']);

    $v = $vars['variables'];
    $lines = array_filter([
        $v['name'] ?? '',
        trim(($v['cp_givenname'] ?? '') . ' ' . ($v['cp_name'] ?? '')),
        $v['street'] ?? '',
        trim(($v['zipcode'] ?? '') . ' ' . ($v['city'] ?? '')),
        $v['country'] ?? '',
    ], fn($l) => trim((string)$l) !== '');
    return [
        'pdf'               => $pdf,
        'filename'          => $res['filename'],
        'recipient_name'    => $v['name'] ?? '',
        'recipient_address' => implode(', ', $lines),
        'country'           => $v['country'] ?? '',
        'address_ok'        => trim((string)($v['street'] ?? '')) !== '' && trim((string)($v['zipcode'] ?? '')) !== '' && trim((string)($v['city'] ?? '')) !== '',
        'label'             => (PRINT_TEMPLATE_MAP[$recordType][2] ?? $recordType) . ' ' . ($v[PRINT_TEMPLATE_MAP[$recordType][3] ?? ''] ?? ''),
    ];
}

/**
 * Eine Einlieferung als Protokollzeile (ohne PDF).
 */
function postalDispatchRow($db, int $id): ?array {
    $row = $db->getOne(<<<SQL
        SELECT json_build_object(
            'channel', 'post', 'id', pd.id, 'record_type', pd.record_type, 'record_id', pd.record_id,
            'provider', pd.provider, 'status', pd.status, 'provider_job_id', pd.provider_job_id,
            'provider_status', pd.provider_status, 'tracking_code', pd.tracking_code,
            'tracking_status', pd.tracking_status, 'color', pd.color, 'duplex', pd.duplex,
            'registered', pd.registered, 'shipping', pd.shipping, 'pages', pd.pages, 'price', pd.price,
            'filename', pd.filename, 'subject', pd.filename, 'recipient', pd.recipient_name,
            'recipient_address', pd.recipient_address, 'error', pd.error,
            'employee_name', e.name, 'itime', pd.itime, 'sent_at', COALESCE(pd.sent_at, pd.itime),
            'has_pdf', pd.pdf IS NOT NULL,
            'cancelable', pd.provider = 'letterxpress' AND pd.status = 'sent'
                          AND pd.sent_at > now() - INTERVAL '15 minutes'
        ) AS result
        FROM postal_dispatches pd
        LEFT JOIN employee e ON e.id = pd.employee_id
        WHERE pd.id = :id
    SQL, [':id' => $id]);
    return $row ? json_decode($row['result'], true) : null;
}

/**
 * Alle Einlieferungen eines Dokuments (neueste zuerst).
 */
function postalDispatchList($db, string $recordTable, int $recordId): array {
    $row = $db->getOne(<<<SQL
        SELECT COALESCE(json_agg(json_build_object(
            'channel', 'post', 'id', pd.id, 'record_type', pd.record_type, 'record_id', pd.record_id,
            'provider', pd.provider, 'status', pd.status, 'provider_job_id', pd.provider_job_id,
            'provider_status', pd.provider_status, 'tracking_code', pd.tracking_code,
            'tracking_status', pd.tracking_status, 'color', pd.color, 'duplex', pd.duplex,
            'registered', pd.registered, 'shipping', pd.shipping, 'pages', pd.pages, 'price', pd.price,
            'filename', pd.filename, 'subject', pd.filename, 'recipient', pd.recipient_name,
            'recipient_address', pd.recipient_address, 'error', pd.error,
            'employee_name', e.name, 'itime', pd.itime, 'sent_at', COALESCE(pd.sent_at, pd.itime),
            'has_pdf', pd.pdf IS NOT NULL,
            'cancelable', pd.provider = 'letterxpress' AND pd.status = 'sent'
                          AND pd.sent_at > now() - INTERVAL '15 minutes'
        ) ORDER BY pd.itime DESC), '[]'::json) AS result
        FROM postal_dispatches pd
        LEFT JOIN employee e ON e.id = pd.employee_id
        WHERE pd.record_table = :t AND pd.record_id = :id
    SQL, [':t' => $recordTable, ':id' => $recordId]);
    return json_decode($row['result'] ?? '[]', true) ?: [];
}

/**
 * Postversand vorbereiten: Seitenzahl, Empfängeradresse, Anbieter,
 * Vorbelegung der Druckoptionen, Preis und bisherige Einlieferungen — alles,
 * was der Versanddialog zeigt, bevor der Benutzer auf „Einliefern" klickt.
 *
 * @param string $data['record_type'] Belegart: letter, invoice, order, quotation …
 * @param int    $data['record_id']   ID des Briefs bzw. Belegs
 * @testdata {"record_type": "invoice", "record_id": 1}
 */
function preparePostal($data) {
    $recordType = (string)($data['record_type'] ?? '');
    $recordId   = intval($data['record_id'] ?? 0);
    $table      = postalRecordTable($recordType);
    postalPermit($recordType);
    if ($recordId <= 0) throw new ApiError('VALIDATION_ERROR', 'record_id fehlt');

    $db  = DbhCompany::begin();
    $cfg = postalConfig($db);
    $doc = postalRenderDocument($db, $recordType, $recordId);
    $pages    = postalPdfPages($doc['pdf']);
    $shipping = postalShipping($doc['country']);

    $price = null;
    $balance = null;
    $problem = postalConfigProblem($cfg);
    if ($cfg['provider'] === 'letterxpress' && $problem === null && $pages) {
        try {
            $lxp = new LetterXpressClient($cfg['lxp_username'], $cfg['lxp_apikey'], $cfg['lxp_mode']);
            $price = $lxp->price($pages, $cfg['color'], $cfg['duplex'], $shipping, null);
            $balance = $lxp->balance();
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
        }
    }

    resultInfo(true, '', ['results' => [
        'provider'          => $cfg['provider'],
        'protocol'          => $cfg['protocol'],
        'mode'              => $cfg['provider'] === 'letterxpress' ? $cfg['lxp_mode'] : null,
        'config_problem'    => $problem,
        'registered_folder' => $cfg['folder_registered'] !== '',
        'label'             => $doc['label'],
        'filename'          => $doc['filename'],
        'pages'             => $pages,
        'recipient_name'    => $doc['recipient_name'],
        'recipient_address' => $doc['recipient_address'],
        'address_ok'        => $doc['address_ok'],
        'shipping'          => $shipping,
        'color'             => $cfg['color'],
        'duplex'            => $cfg['duplex'],
        'price'             => $price,
        'balance'           => $balance,
        'dispatches'        => postalDispatchList($db, $table, $recordId),
    ]]);
}

/**
 * Preis beim Anbieter abfragen (LetterXpress), wenn der Benutzer im Dialog
 * Farbe, Duplex oder Einschreiben umschaltet.
 *
 * @param int    $data['pages']      Seitenzahl
 * @param bool   $data['color']      Farbdruck
 * @param bool   $data['duplex']     Beidseitig
 * @param string $data['shipping']   national | international
 * @param string $data['registered'] '' | r1 | r2
 * @testdata {"pages": 2, "color": false, "duplex": true, "shipping": "national", "registered": ""}
 */
function getPostalPrice($data) {
    permit(['sales_letter_edit', 'purchase_letter_edit', 'invoice_edit', 'sales_order_edit', 'sales_quotation_edit'], false);
    $db  = DbhCompany::begin();
    $cfg = postalConfig($db);
    if ($cfg['provider'] !== 'letterxpress' || postalConfigProblem($cfg) !== null) {
        resultInfo(true, '', ['results' => ['price' => null]]);
        return;
    }
    $lxp = new LetterXpressClient($cfg['lxp_username'], $cfg['lxp_apikey'], $cfg['lxp_mode']);
    $registered = in_array($data['registered'] ?? '', ['r1', 'r2'], true) ? $data['registered'] : null;
    $price = $lxp->price(max(1, intval($data['pages'] ?? 1)), !empty($data['color']), !empty($data['duplex']),
                         ($data['shipping'] ?? 'national') === 'international' ? 'international' : 'national', $registered);
    resultInfo(true, '', ['results' => ['price' => $price]]);
}

/**
 * Dokument per Post einliefern (oder als selbst gedruckt vermerken).
 *
 * Ablauf: PDF erzeugen → Zeile anlegen (queued) → beim Anbieter einliefern →
 * Zeile mit Auftragsnummer, Status, Preis fortschreiben. Schlägt die
 * Einlieferung fehl, bleibt die Zeile mit status = error und Fehlertext
 * stehen, damit der Vorgang nachvollziehbar ist und wiederholt werden kann.
 *
 * @param string $data['record_type'] Belegart: letter, invoice, order …
 * @param int    $data['record_id']   ID des Briefs bzw. Belegs
 * @param string $data['channel']     provider (Dienstleister aus der Konfiguration) | manual (selbst drucken und frankieren)
 * @param bool   $data['color']       Farbdruck
 * @param bool   $data['duplex']      Beidseitig
 * @param string $data['registered']  '' | r1 (Einwurf-Einschreiben) | r2 (Einschreiben)
 * @testdata {"record_type": "invoice", "record_id": 1, "channel": "manual", "color": false, "duplex": true, "registered": ""}
 */
function sendPostal($data) {
    $recordType = (string)($data['record_type'] ?? '');
    $recordId   = intval($data['record_id'] ?? 0);
    $table      = postalRecordTable($recordType);
    postalPermit($recordType);
    if ($recordId <= 0) throw new ApiError('VALIDATION_ERROR', 'record_id fehlt');

    $db   = DbhCompany::begin();
    $cfg  = postalConfig($db);
    $channel    = ($data['channel'] ?? 'provider') === 'manual' ? 'manual' : 'provider';
    $color      = !empty($data['color']);
    $duplex     = !empty($data['duplex']);
    $registered = in_array($data['registered'] ?? '', ['r1', 'r2'], true) ? $data['registered'] : null;
    $provider   = $channel === 'manual' ? 'manual' : $cfg['provider'];

    if ($channel === 'provider') {
        $problem = postalConfigProblem($cfg);
        if ($problem !== null) throw new ApiError('POSTAL_NOT_CONFIGURED', 'Briefversand nicht eingerichtet: ' . $problem);
        if ($provider === 'pinag' && $registered && $cfg['folder_registered'] === '') {
            throw new ApiError('POSTAL_REGISTERED_UNSUPPORTED', 'Für Einschreiben ist bei PIN AG kein eigener Einlieferungsordner eingetragen');
        }
    }

    $doc      = postalRenderDocument($db, $recordType, $recordId);
    $shipping = postalShipping($doc['country']);
    if ($registered && $shipping === 'international') {
        throw new ApiError('POSTAL_REGISTERED_INTERNATIONAL', 'Einschreiben sind nur im Inland möglich');
    }
    if (!$doc['address_ok'] && $channel === 'provider') {
        throw new ApiError('POSTAL_ADDRESS_INCOMPLETE', 'Empfängeradresse unvollständig (Straße, PLZ, Ort)');
    }
    $pages = postalPdfPages($doc['pdf']);

    $row = $db->getOne(<<<SQL
        INSERT INTO postal_dispatches
            (record_type, record_table, record_id, provider, status, color, duplex, registered, shipping,
             pages, filename, pdf, recipient_name, recipient_address, employee_id)
        VALUES (:rt, :tbl, :rid, :provider, 'queued', :color, :duplex, :registered, :shipping,
                :pages, :filename, decode(:pdf, 'base64'), :rname, :raddr, :emp)
        RETURNING id
    SQL, [
        ':rt' => $recordType, ':tbl' => $table, ':rid' => $recordId, ':provider' => $provider,
        ':color' => $color ? 'true' : 'false', ':duplex' => $duplex ? 'true' : 'false',
        ':registered' => $registered, ':shipping' => $shipping, ':pages' => $pages,
        ':filename' => $doc['filename'], ':pdf' => base64_encode($doc['pdf']),
        ':rname' => $doc['recipient_name'], ':raddr' => $doc['recipient_address'],
        ':emp' => mitarbeiterId($data),
    ]);
    $id = intval($row['id']);

    try {
        if ($provider === 'manual') {
            $db->execute("UPDATE postal_dispatches SET status = 'manual', sent_at = now(), mtime = now() WHERE id = :id", [':id' => $id]);
        } elseif ($provider === 'letterxpress') {
            $lxp = new LetterXpressClient($cfg['lxp_username'], $cfg['lxp_apikey'], $cfg['lxp_mode']);
            $job = $lxp->submit($doc['pdf'], $color, $duplex, $shipping, $registered, $doc['filename'], $doc['label'] . ' · ' . $doc['recipient_name']);
            $m   = postalLxpJobToRow($job);
            $db->execute(<<<SQL
                UPDATE postal_dispatches
                SET status = :status, provider_job_id = :job, provider_status = :pstatus,
                    tracking_code = :tc, tracking_status = :ts, price = :price,
                    pages = COALESCE(:pages, pages), sent_at = now(), mtime = now(), error = NULL
                WHERE id = :id
            SQL, [
                ':status' => $m['status'], ':job' => isset($job['id']) ? (string)$job['id'] : null,
                ':pstatus' => $m['provider_status'], ':tc' => $m['tracking_code'], ':ts' => $m['tracking_status'],
                ':price' => $m['price'], ':pages' => $m['pages'], ':id' => $id,
            ]);
        } else {
            // PIN AG: Dateiname eindeutig je Einlieferung, damit nichts überschrieben wird
            $base     = preg_replace('/\.pdf$/i', '', $doc['filename']);
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) . '_' . $id . '.pdf';
            $folder   = $registered ? $cfg['folder_registered'] : $cfg['folder'];
            postalSftpUpload($cfg, $folder, $filename, $doc['pdf']);
            $db->execute(<<<SQL
                UPDATE postal_dispatches
                SET status = 'sent', provider_job_id = :job, provider_status = 'uploaded',
                    sent_at = now(), mtime = now(), error = NULL
                WHERE id = :id
            SQL, [':job' => rtrim($folder, '/') . '/' . $filename, ':id' => $id]);
        }
    } catch (\Throwable $e) {
        $db->execute("UPDATE postal_dispatches SET status = 'error', error = :err, mtime = now() WHERE id = :id",
            [':err' => $e->getMessage(), ':id' => $id]);
        throw ($e instanceof ApiError) ? $e : new ApiError('POSTAL_SEND_FAILED', $e->getMessage());
    }

    $result = ['entry' => postalDispatchRow($db, $id)];
    if ($provider === 'manual') {
        // Selbst drucken: das Versandexemplar gleich mitgeben
        $result['pdf']      = base64_encode($doc['pdf']);
        $result['filename'] = $doc['filename'];
    }
    resultInfo(true, 'SENT', ['results' => $result]);
}

/**
 * Einlieferungen eines Dokuments.
 *
 * @param string $data['record_type'] Belegart
 * @param int    $data['record_id']   ID
 * @testdata {"record_type": "invoice", "record_id": 1}
 */
function getPostalDispatches($data) {
    $recordType = (string)($data['record_type'] ?? '');
    $table = postalRecordTable($recordType);
    postalPermit($recordType);
    $db = DbhCompany::begin();
    resultInfo(true, '', ['results' => postalDispatchList($db, $table, intval($data['record_id'] ?? 0))]);
}

/**
 * Versandexemplar (PDF) einer Einlieferung.
 *
 * @param int $data['id'] Einlieferung
 * @testdata {"id": 1}
 */
function getPostalPdf($data) {
    $db  = DbhCompany::begin();
    $row = $db->getOne("SELECT record_type, filename, encode(pdf, 'base64') AS pdf FROM postal_dispatches WHERE id = :id", [':id' => intval($data['id'] ?? 0)]);
    if (!$row) throw new ApiError('NOT_FOUND', 'Einlieferung nicht gefunden');
    postalPermit($row['record_type']);
    resultInfo(true, '', ['results' => ['pdf' => $row['pdf'], 'filename' => $row['filename']]]);
}

/**
 * Status einer Einlieferung beim Anbieter aktualisieren (LetterXpress:
 * Auftragsstatus und Sendungsverfolgung). Bei PIN AG gibt es keine
 * Rückmeldung über die Schnittstelle — die Sendungsverfolgung läuft dort
 * über das Kundenportal.
 *
 * @param int $data['id'] Einlieferung
 * @testdata {"id": 1}
 */
function refreshPostalStatus($data) {
    $db  = DbhCompany::begin();
    $id  = intval($data['id'] ?? 0);
    $row = $db->getOne("SELECT record_type, provider, provider_job_id, status FROM postal_dispatches WHERE id = :id", [':id' => $id]);
    if (!$row) throw new ApiError('NOT_FOUND', 'Einlieferung nicht gefunden');
    postalPermit($row['record_type']);
    if ($row['provider'] !== 'letterxpress' || empty($row['provider_job_id'])) {
        throw new ApiError('POSTAL_NO_TRACKING', 'Für diesen Anbieter gibt es keine Statusabfrage');
    }
    postalRefreshOne($db, $id, $row['provider_job_id']);
    resultInfo(true, '', ['results' => ['entry' => postalDispatchRow($db, $id)]]);
}

/**
 * Einen LetterXpress-Auftrag abfragen und die Zeile fortschreiben.
 *
 * @return string neuer Status
 */
function postalRefreshOne($db, int $id, string $jobId): string {
    $cfg = postalConfig($db);
    $lxp = new LetterXpressClient($cfg['lxp_username'], $cfg['lxp_apikey'], $cfg['lxp_mode']);
    $m   = postalLxpJobToRow($lxp->job($jobId));
    $db->execute(<<<SQL
        UPDATE postal_dispatches
        SET status = :status, provider_status = :pstatus, tracking_code = COALESCE(:tc, tracking_code),
            tracking_status = COALESCE(:ts, tracking_status), price = COALESCE(:price, price),
            pages = COALESCE(:pages, pages), mtime = now()
        WHERE id = :id
    SQL, [
        ':status' => $m['status'], ':pstatus' => $m['provider_status'], ':tc' => $m['tracking_code'],
        ':ts' => $m['tracking_status'], ':price' => $m['price'], ':pages' => $m['pages'], ':id' => $id,
    ]);
    return $m['status'];
}

/**
 * Alle offenen LetterXpress-Aufträge dieses Mandanten aktualisieren (Cron).
 *
 * @return array ['checked' => int, 'done' => int, 'errors' => string[]]
 */
function postalRefreshOpenDispatches($db): array {
    $rows = $db->getAll("SELECT id, provider_job_id FROM postal_dispatches WHERE provider = 'letterxpress' AND status = 'sent' AND provider_job_id IS NOT NULL");
    $out = ['checked' => 0, 'done' => 0, 'errors' => []];
    foreach ($rows as $r) {
        try {
            $out['checked']++;
            if (postalRefreshOne($db, intval($r['id']), $r['provider_job_id']) === 'done') $out['done']++;
        } catch (\Throwable $e) {
            $out['errors'][] = '#' . $r['id'] . ': ' . $e->getMessage();
        }
    }
    return $out;
}

/**
 * Einlieferung stornieren bzw. Protokollzeile entfernen.
 *
 * LetterXpress nimmt ein Storno nur in den ersten 15 Minuten an; danach ist
 * der Brief im Druck. Bei PIN AG lässt sich eine hochgeladene Datei nicht
 * über die Schnittstelle zurückrufen. Fehlgeschlagene und händische Einträge
 * werden gelöscht.
 *
 * @param int $data['id'] Einlieferung
 * @testdata {"id": 1}
 */
function cancelPostalDispatch($data) {
    $db  = DbhCompany::begin();
    $id  = intval($data['id'] ?? 0);
    $row = $db->getOne("SELECT record_type, provider, provider_job_id, status FROM postal_dispatches WHERE id = :id", [':id' => $id]);
    if (!$row) throw new ApiError('NOT_FOUND', 'Einlieferung nicht gefunden');
    postalPermit($row['record_type']);

    if (in_array($row['status'], ['error', 'manual', 'queued', 'canceled'], true)) {
        $db->execute("DELETE FROM postal_dispatches WHERE id = :id", [':id' => $id]);
        resultInfo(true, 'DELETED', ['results' => ['deleted' => true]]);
        return;
    }
    if ($row['provider'] !== 'letterxpress') {
        throw new ApiError('POSTAL_CANCEL_UNSUPPORTED', 'Eine eingelieferte Datei kann bei diesem Anbieter nicht über die Schnittstelle zurückgerufen werden');
    }
    $cfg = postalConfig($db);
    $lxp = new LetterXpressClient($cfg['lxp_username'], $cfg['lxp_apikey'], $cfg['lxp_mode']);
    $lxp->delete((string)$row['provider_job_id']);
    $db->execute("UPDATE postal_dispatches SET status = 'canceled', provider_status = 'canceled', mtime = now() WHERE id = :id", [':id' => $id]);
    resultInfo(true, 'CANCELED', ['results' => ['entry' => postalDispatchRow($db, $id)]]);
}

/**
 * Verbindung zum eingerichteten Briefdienstleister prüfen (Konfiguration):
 * LetterXpress → Anmeldung und Guthaben, PIN AG → Anmeldung und Ordner.
 *
 * @testdata {}
 */
function testPostalConnection($data) {
    permit('config');
    $db  = DbhCompany::begin();
    $cfg = postalConfig($db);
    $problem = postalConfigProblem($cfg);
    $out = ['provider' => $cfg['provider'], 'ok' => false, 'message' => $problem, 'mode' => null, 'balance' => null, 'currency' => null, 'files' => null];
    if ($problem === null) {
        try {
            if ($cfg['provider'] === 'letterxpress') {
                $lxp = new LetterXpressClient($cfg['lxp_username'], $cfg['lxp_apikey'], $cfg['lxp_mode']);
                $b = $lxp->balance();
                $out = array_merge($out, ['ok' => true, 'message' => 'Anmeldung erfolgreich', 'mode' => $cfg['lxp_mode'], 'balance' => $b['balance'], 'currency' => $b['currency']]);
            } else {
                $t = postalSftpTest($cfg);
                $out = array_merge($out, ['ok' => $t['ok'], 'message' => $t['message'], 'files' => $t['files'], 'mode' => $cfg['protocol']]);
            }
        } catch (\Throwable $e) {
            $out['message'] = $e->getMessage();
        }
    }
    resultInfo(true, '', ['results' => $out]);
}
