<?php
// backend/api/faktura/einvoice.php

require_once __DIR__.'/einvoice_builder.php';

use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdDocumentValidator;

/**
 * Erzeugt eine E-Rechnung (ZUGFeRD/Factur-X oder XRechnung) zu einer AR-Rechnung.
 * Profil und Testmodus richten sich nach customer.create_zugferd_invoices bzw. dem
 * Mandanten-Default defaults.create_zugferd_invoices (bei -1 auf Kundenebene).
 *
 * Bei Profil Factur-X Extended (Werte 1/2) wird das bestehende LaTeX-PDF
 * geladen und ein PDF/A-3 mit eingebettetem factur-x.xml erzeugt.
 * Bei Profil XRechnung 3.0 (Werte 3/4) wird nur das XML zurückgegeben.
 *
 * @param int    $data['fakturaID']   AR-ID
 * @param string $data['fakturaType'] 'invoice' (AR) – nur hier relevant
 * @param bool   $data['skipValidation'] optional, überspringt EN16931-Validierung
 * @testdata {"fakturaID": 1, "fakturaType": "invoice"}
 */
function generateEInvoice($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $fakturaType = $data['fakturaType'] ?? 'invoice';

    if ($fakturaID <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', ['message' => 'fakturaID erforderlich']);
        return;
    }
    if ($fakturaType !== 'invoice') {
        resultInfo(false, 'VALIDATION_ERROR', ['message' => 'E-Rechnung nur für Ausgangsrechnungen']);
        return;
    }

    $db = DbhCompany::begin();

    $payload = loadEInvoiceData($db, $fakturaID);
    if ($payload === null) {
        resultInfo(false, 'NOT_FOUND', ['message' => 'Rechnung nicht gefunden']);
        return;
    }

    $builder = new EInvoiceBuilder($payload);

    if (!$builder->isActive()) {
        resultInfo(false, 'EINVOICE_DISABLED',
            ['message' => 'E-Rechnung für diesen Kunden nicht aktiviert']);
        return;
    }

    try {
        $doc = $builder->buildDocument();
    } catch (\Throwable $e) {
        resultInfo(false, 'EINVOICE_BUILD_ERROR', [
            'message' => $e->getMessage(),
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
        ]);
        return;
    }

    if (empty($data['skipValidation'])) {
        $validator = new ZugferdDocumentValidator($doc);
        $violations = $validator->validateDocument();
        if ($violations->count() > 0) {
            $messages = [];
            foreach ($violations as $v) {
                $messages[] = $v->getPropertyPath() . ': ' . $v->getMessage();
            }
            resultInfo(false, 'EINVOICE_INVALID', [
                'message'    => 'Validierung gegen EN 16931 fehlgeschlagen',
                'violations' => $messages,
            ]);
            return;
        }
    }

    $xml = $doc->getContent();

    if ($builder->isXRechnung()) {
        resultInfo(true, 'OK', [
            'format'   => 'xrechnung',
            'mimetype' => 'application/xml',
            'filename' => 'xrechnung-' . $payload['ar']['invnumber'] . '.xml',
            'content'  => base64_encode($xml),
            'testMode' => $builder->isTestMode(),
        ]);
        return;
    }

    // Factur-X: bestehendes LaTeX-PDF rendern und XML einbetten
    require_once __DIR__.'/../print/print.php';
    require_once __DIR__.'/../print/template_engine.php';

    $lxCars = isLxCarsEnabled($db);
    $vars = loadPrintData($db, $fakturaID, 'invoice', $lxCars);
    if ($vars === false) {
        resultInfo(false, 'PDF_DATA_ERROR', ['message' => 'PDF-Daten konnten nicht geladen werden']);
        return;
    }

    $templateSet = getTemplateSet($db);
    $templateDir = getTemplateDir($templateSet);
    $templateName = detectTemplate('invoice', $vars, $lxCars, $templateDir);

    $engine = new LaTeXTemplateEngine($templateDir);
    $engine->setVariables($vars['variables']);
    $engine->setArrays($vars['arrays']);

    $pdfPath = $engine->generatePDF($templateName);
    if ($pdfPath === false) {
        resultInfo(false, 'PDF_BUILD_ERROR', [
            'message' => 'LaTeX-Kompilierung fehlgeschlagen',
            'debug'   => $engine->getError(),
        ]);
        return;
    }

    $flatPdfPath = $pdfPath . '.flat.pdf';
    $gsCmd = sprintf(
        'gs -dBATCH -dNOPAUSE -dQUIET -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/prepress -sOutputFile=%s %s 2>&1',
        escapeshellarg($flatPdfPath),
        escapeshellarg($pdfPath)
    );
    exec($gsCmd, $gsOut, $gsRc);
    if ($gsRc !== 0 || !is_file($flatPdfPath)) {
        $engine->cleanup($pdfPath);
        resultInfo(false, 'EINVOICE_MERGE_ERROR', [
            'message' => 'Ghostscript-Konvertierung fehlgeschlagen: ' . implode("\n", $gsOut),
        ]);
        return;
    }

    try {
        $pdfBuilder = ZugferdDocumentPdfBuilder::fromPdfFile($doc, $flatPdfPath);
        $pdfBuilder->setAdditionalCreatorTool('opensource-erp');
        $pdfBuilder->generateDocument();
        $mergedPdf = $pdfBuilder->downloadString();
    } catch (\Throwable $e) {
        $engine->cleanup($pdfPath);
        @unlink($flatPdfPath);
        resultInfo(false, 'EINVOICE_MERGE_ERROR', [
            'message' => $e->getMessage(),
        ]);
        return;
    }

    $engine->cleanup($pdfPath);
    @unlink($flatPdfPath);

    resultInfo(true, 'OK', [
        'format'   => 'factur-x',
        'mimetype' => 'application/pdf',
        'filename' => 'rechnung-' . $payload['ar']['invnumber'] . '.pdf',
        'content'  => base64_encode($mergedPdf),
        'testMode' => $builder->isTestMode(),
    ]);
}

/**
 * Liefert die horstoeko-XML-Vorschau ohne PDF — nützlich für Debug und Frontend-Preview.
 * Gibt das XML auch zurück, wenn die Validierung Violations meldet.
 *
 * @param int $data['fakturaID']
 * @testdata {"fakturaID": 1}
 */
function previewEInvoiceXml($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    if ($fakturaID <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', ['message' => 'fakturaID erforderlich']);
        return;
    }

    $db = DbhCompany::begin();
    $payload = loadEInvoiceData($db, $fakturaID);
    if ($payload === null) {
        resultInfo(false, 'NOT_FOUND', ['message' => 'Rechnung nicht gefunden']);
        return;
    }

    $builder = new EInvoiceBuilder($payload);
    if (!$builder->isActive()) {
        resultInfo(false, 'EINVOICE_DISABLED',
            ['message' => 'E-Rechnung für diesen Kunden nicht aktiviert']);
        return;
    }

    try {
        $doc = $builder->buildDocument();
    } catch (\Throwable $e) {
        resultInfo(false, 'EINVOICE_BUILD_ERROR', ['message' => $e->getMessage()]);
        return;
    }

    $validator = new ZugferdDocumentValidator($doc);
    $violations = $validator->validateDocument();
    $messages = [];
    foreach ($violations as $v) {
        $messages[] = $v->getPropertyPath() . ': ' . $v->getMessage();
    }

    resultInfo(true, 'OK', [
        'format'     => $builder->isXRechnung() ? 'xrechnung' : 'factur-x',
        'xml'        => $doc->getContent(),
        'testMode'   => $builder->isTestMode(),
        'violations' => $messages,
    ]);
}

/**
 * Ein AJAX-Call, eine Query: liefert alle für die ZUGFeRD-Erzeugung
 * benötigten Daten als einzelnes JSON-Objekt.
 * Gibt null zurück, wenn die Rechnung nicht existiert.
 */
function loadEInvoiceData($db, int $fakturaID): ?array {
    $query = <<<SQL
        SELECT json_build_object(
            'ar', (
                SELECT row_to_json(a) FROM (
                    SELECT ar.*, cur.name AS currency_code
                    FROM ar
                    LEFT JOIN currencies cur ON ar.currency_id = cur.id
                    WHERE ar.id = :fakturaID
                ) a
            ),
            'company', (
                SELECT row_to_json(c) FROM (
                    SELECT company, taxnumber, co_ustid, gln,
                           address_street1, address_street2, address_zipcode,
                           address_city, address_country, templates
                    FROM defaults LIMIT 1
                ) c
            ),
            'customer', (
                SELECT row_to_json(c) FROM (
                    SELECT cu.id, cu.name, cu.customernumber, cu.street, cu.zipcode,
                           cu.city, cu.country, cu.ustid, cu.gln, cu.invoice_mail,
                           cu.department_1, cu.department_2, cu.iban, cu.bic,
                           cu.create_zugferd_invoices, cu.c_vendor_routing_id
                    FROM customer cu
                    JOIN ar ON ar.customer_id = cu.id
                    WHERE ar.id = :fakturaID
                ) c
            ),
            'positions', (
                SELECT COALESCE(json_agg(pos ORDER BY pos.position), '[]'::json) FROM (
                    SELECT i.id, i.trans_id, i.position, i.description, i.longdescription,
                           i.qty, i.sellprice, i.discount, i.unit, i.parts_id,
                           p.partnumber,
                           COALESCE(t.rate, 0) AS tax_rate
                    FROM invoice i
                    LEFT JOIN parts p ON p.id = i.parts_id
                    LEFT JOIN tax t   ON t.id = i.tax_id
                    WHERE i.trans_id = :fakturaID
                ) pos
            ),
            'tax_breakdown', (
                SELECT COALESCE(json_agg(tb), '[]'::json) FROM (
                    SELECT COALESCE(t.rate, 0) AS rate,
                           SUM(i.qty * i.sellprice * (1 - COALESCE(i.discount, 0)))                   AS basis,
                           SUM(i.qty * i.sellprice * (1 - COALESCE(i.discount, 0)) * COALESCE(t.rate, 0)) AS tax_amount
                    FROM invoice i
                    LEFT JOIN tax t ON t.id = i.tax_id
                    WHERE i.trans_id = :fakturaID
                    GROUP BY COALESCE(t.rate, 0)
                ) tb
            ),
            'bank', (
                SELECT row_to_json(b) FROM (
                    SELECT iban, bic, name, bank
                    FROM bank_accounts
                    WHERE use_for_zugferd = true
                    ORDER BY id ASC
                    LIMIT 1
                ) b
            ),
            'einvoice_mode', (
                SELECT CASE
                    WHEN cu.create_zugferd_invoices = -1 THEN d.create_zugferd_invoices
                    ELSE cu.create_zugferd_invoices
                END
                FROM ar
                JOIN customer cu ON ar.customer_id = cu.id
                CROSS JOIN defaults d
                WHERE ar.id = :fakturaID
            )
        ) AS result
SQL;

    $row = $db->getOne($query, ['fakturaID' => $fakturaID]);
    if (!$row || empty($row['result'])) return null;

    $decoded = json_decode($row['result'], true);
    if (!$decoded || empty($decoded['ar'])) return null;

    return $decoded;
}
