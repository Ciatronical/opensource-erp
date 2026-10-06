<?php
// backend/api/print/template_designer.php

/**
 * Vorlageneditor: Druckvorlagen per Drag & Drop gestalten
 *
 * Der Editor im Frontend beschreibt eine Vorlage als Design-JSON (Seite,
 * frei platzierte Bausteine, Fließbereich). Diese Datei verwaltet die Designs
 * (print_template_designs, versioniert), übersetzt sie beim Speichern mit dem
 * TemplateDesignCompiler in eine .tex-Datei des Vorlagensatzes und rendert
 * Vorschauen mit echten Belegdaten.
 *
 * Alle Aktionen verlangen einen Systemadministrator (requireSystemAdmin()).
 * Geschrieben wird ausschließlich in Vorlagensätze unter OSERP_TEMPLATES_DIR —
 * die Master-Sets in templates-default/ bleiben unangetastet.
 */

/** Kennzeichen in der ersten Zeile jeder vom Editor erzeugten Vorlage */
define('DESIGNER_MARKER', '% Erzeugt vom OSERP-Vorlageneditor');

/** Bilder, die der Editor anbietet und entgegennimmt */
define('DESIGNER_IMAGE_EXT', ['png', 'jpg', 'jpeg', 'pdf']);

/**
 * Löst den Vorlagensatz auf und sagt, ob der Editor hineinschreiben darf
 *
 * @param string $templateSet Set-Name oder Kivitendo-Pfad ('templates/firma')
 * @return array ['set' => normalisierter Name, 'dir' => Verzeichnis, 'writable' => bool, 'master' => bool]
 */
function designerResolveSet(string $templateSet): array {
    $templateSet = trim(preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $templateSet), '/');
    $dir = resolveTemplateDir($templateSet);
    if ($dir === false) {
        throw new ApiError('NOT_FOUND', 'Vorlagensatz nicht gefunden: ' . $templateSet);
    }
    $real = realpath($dir);
    $userBase = realpath(OSERP_TEMPLATES_DIR);
    $writable = $userBase !== false && $real !== false
        && strpos($real . '/', $userBase . '/') === 0
        && is_writable($real);
    $master = strpos($real . '/', realpath(PRINT_MASTER_BASE) . '/') === 0;
    return ['set' => $templateSet, 'dir' => $real, 'writable' => $writable, 'master' => $master];
}

/**
 * Liste der Bilder eines Vorlagensatzes (bis zwei Ebenen tief)
 *
 * @return array [{path, size, width, height}]
 */
function designerListImages(string $dir): array {
    $images = [];
    $patterns = ['/*.{png,jpg,jpeg,pdf,PNG,JPG,JPEG,PDF}', '/*/*.{png,jpg,jpeg,pdf,PNG,JPG,JPEG,PDF}'];
    foreach ($patterns as $pattern) {
        foreach (glob($dir . $pattern, GLOB_BRACE) ?: [] as $file) {
            if (!is_file($file)) continue;
            $rel = substr($file, strlen($dir) + 1);
            // emptyPage.pdf gehört zur LaTeX-Technik, kein Motiv
            if (basename($file) === 'emptyPage.pdf') continue;
            $entry = ['path' => $rel, 'size' => filesize($file), 'width' => null, 'height' => null];
            $info = @getimagesize($file);
            if ($info) {
                $entry['width'] = $info[0];
                $entry['height'] = $info[1];
            }
            $images[] = $entry;
        }
    }
    usort($images, fn($a, $b) => strcasecmp($a['path'], $b['path']));
    return $images;
}

/**
 * Belegarten des Drucks mit Vorlagendatei und Zustand im Set
 *
 * @return array [{key, file, label, exists, designed, handmade, kfzOverride}]
 */
function designerDocumentTypes(string $dir, array $designs, bool $lxCars): array {
    $types = [];
    $kfzExists = is_file($dir . '/kfz_order.tex');
    foreach (PRINT_TEMPLATE_MAP as $key => $entry) {
        $file = $entry[0];
        $path = $dir . '/' . $file;
        $exists = is_file($path);
        $ours = $exists && strncmp((string)file_get_contents($path, false, null, 0, 64), DESIGNER_MARKER, strlen(DESIGNER_MARKER)) === 0;
        $types[] = [
            'key'         => $key,
            'file'        => $file,
            'label'       => $entry[2],
            'exists'      => $exists,
            'designed'    => isset($designs[$key]),
            // Datei vorhanden, aber nicht vom Editor: Handarbeit, die beim ersten Speichern gesichert wird
            'handmade'    => $exists && !$ours,
            // Mit LxCars druckt detectTemplate() Aufträge über kfz_order.tex
            'kfzOverride' => $lxCars && $kfzExists && in_array($key, ['order', 'sales_order_intake'], true),
        ];
    }
    return $types;
}

/**
 * Lädt alles, was der Vorlageneditor zum Start braucht
 *
 * Eine Abfrage liefert die aktuellen Designs und Versionen des Sets sowie je
 * Belegart die neuesten Belege für die Vorschau. Dazu kommen Dateisystem-
 * Informationen (Vorlagensätze, Bilder, Zustand der Vorlagendateien) und die
 * Beispieldaten eines Belegs über die vorhandene Druckdatenstrecke
 * (loadPrintData) — damit zeigt der Editor dieselben Felder und Werte, die
 * später auch im PDF stehen.
 *
 * Firmendaten (defaults, defaults_oserp, bank_accounts) kommen nicht mit:
 * sie liegen bereits im Frontend-Store (company_config).
 *
 * @param string $data['templateSet'] Vorlagensatz, Standard: aktives Set aus defaults.templates
 * @param string $data['documentType'] Belegart für die Beispieldaten, Standard: invoice
 * @param int $data['sampleDocumentId'] Optional: bestimmter Beleg für die Beispieldaten
 * @testdata {"documentType": "invoice"}
 */
function getTemplateDesigner($data) {
    requireSystemAdmin();
    $db = DbhCompany::begin();

    $templateSet = trim((string)($data['templateSet'] ?? ''));
    if ($templateSet === '' || resolveTemplateDir($templateSet) === false) {
        $templateSet = getTemplateSet($db);
    }
    $set = designerResolveSet($templateSet);
    $documentType = (string)($data['documentType'] ?? 'invoice');
    if (!isset(PRINT_TEMPLATE_MAP[$documentType])) $documentType = 'invoice';

    $row = $db->getOne(<<<SQL
        WITH params AS (SELECT CAST(:set AS text) AS template_set)
        SELECT json_build_object(
            'designs', COALESCE((
                SELECT json_object_agg(d.document_type, json_build_object(
                    'design', d.design, 'version', d.version, 'itime', d.itime, 'id', d.id))
                FROM print_template_designs d, params p
                WHERE d.template_set = p.template_set AND d.is_current
            ), '{}'::json),
            'versions', COALESCE((
                SELECT json_object_agg(t.document_type, t.versions)
                FROM (
                    SELECT d.document_type,
                           json_agg(json_build_object(
                               'id', d.id, 'version', d.version, 'itime', d.itime,
                               'is_current', d.is_current, 'employee', e.name)
                           ORDER BY d.version DESC) AS versions
                    FROM print_template_designs d
                    LEFT JOIN employee e ON e.id = d.employee_id, params p
                    WHERE d.template_set = p.template_set
                    GROUP BY d.document_type
                ) t
            ), '{}'::json),
            'sample_documents', COALESCE((
                SELECT json_object_agg(t.document_type, t.docs)
                FROM (
                    SELECT x.document_type,
                           json_agg(json_build_object('id', x.id, 'number', x.number, 'date', x.transdate, 'name', x.name)
                                    ORDER BY x.transdate DESC, x.id DESC) AS docs
                    FROM (
                        (SELECT 'invoice' AS document_type, ar.id, ar.invnumber AS number, ar.transdate, c.name
                         FROM ar JOIN customer c ON c.id = ar.customer_id
                         WHERE ar.invoice AND NOT ar.storno AND COALESCE(ar.type, 'invoice') NOT IN ('credit_note', 'proforma')
                         ORDER BY ar.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'invoice_storno', ar.id, ar.invnumber, ar.transdate, c.name
                         FROM ar JOIN customer c ON c.id = ar.customer_id
                         WHERE ar.invoice AND ar.storno ORDER BY ar.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'credit_note', ar.id, ar.invnumber, ar.transdate, c.name
                         FROM ar JOIN customer c ON c.id = ar.customer_id
                         WHERE ar.type = 'credit_note' ORDER BY ar.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'proforma', ar.id, ar.invnumber, ar.transdate, c.name
                         FROM ar JOIN customer c ON c.id = ar.customer_id
                         WHERE ar.type = 'proforma' ORDER BY ar.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'quotation', oe.id, oe.quonumber, oe.transdate, c.name
                         FROM oe JOIN customer c ON c.id = oe.customer_id
                         WHERE oe.record_type = 'sales_quotation' ORDER BY oe.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'order', oe.id, oe.ordnumber, oe.transdate, c.name
                         FROM oe JOIN customer c ON c.id = oe.customer_id
                         WHERE oe.record_type = 'sales_order' ORDER BY oe.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'sales_order_intake', oe.id, oe.ordnumber, oe.transdate, c.name
                         FROM oe JOIN customer c ON c.id = oe.customer_id
                         WHERE oe.record_type = 'sales_order_intake' ORDER BY oe.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'request_quotation', oe.id, oe.quonumber, oe.transdate, v.name
                         FROM oe JOIN vendor v ON v.id = oe.vendor_id
                         WHERE oe.record_type = 'request_quotation' ORDER BY oe.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'purchase_quotation_intake', oe.id, oe.quonumber, oe.transdate, v.name
                         FROM oe JOIN vendor v ON v.id = oe.vendor_id
                         WHERE oe.record_type = 'purchase_quotation_intake' ORDER BY oe.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'purchase_order', oe.id, oe.ordnumber, oe.transdate, v.name
                         FROM oe JOIN vendor v ON v.id = oe.vendor_id
                         WHERE oe.record_type = 'purchase_order' ORDER BY oe.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'delivery_order', d.id, d.donumber, d.transdate, c.name
                         FROM delivery_orders d JOIN customer c ON c.id = d.customer_id
                         WHERE d.record_type = 'sales_delivery_order' ORDER BY d.id DESC LIMIT 8)
                        UNION ALL
                        (SELECT 'purchase_delivery_order', d.id, d.donumber, d.transdate, v.name
                         FROM delivery_orders d JOIN vendor v ON v.id = d.vendor_id
                         WHERE d.record_type = 'purchase_delivery_order' ORDER BY d.id DESC LIMIT 8)
                    ) x
                    GROUP BY x.document_type
                ) t
            ), '{}'::json)
        ) AS result
    SQL, [':set' => $set['set']]);

    $result = json_decode($row['result'] ?? '{}', true) ?: [];
    $designs = $result['designs'] ?? [];
    $lxCars = isLxCarsEnabled($db);

    // Beispieldaten: gewünschter oder neuester Beleg der Belegart
    $sample = null;
    $sampleId = intval($data['sampleDocumentId'] ?? 0);
    if (!$sampleId) {
        $sampleId = intval($result['sample_documents'][$documentType][0]['id'] ?? 0);
    }
    if ($sampleId) {
        $printData = loadPrintData($db, $sampleId, $documentType, $lxCars);
        if ($printData !== false) {
            // Interne Werte, die kein Vorlagenfeld sind
            unset($printData['variables']['filename'], $printData['variables']['template_meta'],
                  $printData['variables']['qr_image'], $printData['variables']['epc_amount']);
            $sample = ['id' => $sampleId, 'variables' => $printData['variables'], 'arrays' => $printData['arrays']];
        }
    }

    resultInfo(true, '', [
        'templateSet'      => $set['set'],
        'writable'         => $set['writable'],
        'master'           => $set['master'],
        'templateSets'     => scanTemplateSets($db),
        'documentTypes'    => designerDocumentTypes($set['dir'], $designs, $lxCars),
        // (object): leere Zuordnungen muessen als {} ankommen, nicht als []
        'designs'          => (object)$designs,
        'versions'         => (object)($result['versions'] ?? []),
        'sample_documents' => (object)($result['sample_documents'] ?? []),
        'sample'           => $sample,
        'images'           => designerListImages($set['dir']),
        'lxcars'           => $lxCars,
    ]);
}

/**
 * Liefert das Design-JSON einer älteren Version zum Zurückholen
 *
 * @param int $data['id'] ID der Version (print_template_designs.id)
 * @testdata {"id": 1}
 */
function getTemplateDesignVersion($data) {
    requireSystemAdmin();
    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT id, template_set, document_type, design, version, itime FROM print_template_designs WHERE id = :id",
        [':id' => intval($data['id'] ?? 0)]
    );
    if (!$row) {
        resultInfo(false, 'NOT_FOUND', 'Version nicht gefunden');
        return;
    }
    $row['design'] = json_decode($row['design'], true);
    resultInfo(true, '', $row);
}

/**
 * Speichert Designs und schreibt die Vorlagendateien in den Vorlagensatz
 *
 * Mehrere Belegarten in einem Aufruf (z. B. "Alle speichern"). Eine Abfrage
 * legt je Belegart eine neue Version an, setzt die bisherige zurück und
 * behält die letzten 20 Versionen. Danach entsteht aus jedem Design die
 * .tex-Datei. Eine handgeschriebene Vorlage wird vor dem ersten Überschreiben
 * als .tex.bak-<Zeitstempel> gesichert.
 *
 * @param string $data['templateSet'] Vorlagensatz (muss unter templates/ liegen)
 * @param array $data['designs'] Liste von {documentType, design}
 * @param bool $data['alsoKfz'] Auftragsdesign zusätzlich als kfz_order.tex schreiben (LxCars)
 * @testdata {"templateSet": "templates/firma", "designs": [{"documentType": "invoice", "design": {"page": {}, "blocks": [], "body": {"sections": []}}}]}
 */
function saveTemplateDesigns($data) {
    requireSystemAdmin();
    $set = designerResolveSet((string)($data['templateSet'] ?? ''));
    if (!$set['writable']) {
        resultInfo(false, 'READ_ONLY', 'Dieser Vorlagensatz ist schreibgeschützt — bitte zuerst eine Kopie anlegen');
        return;
    }

    $compiler = new TemplateDesignCompiler();
    $items = [];
    foreach ((array)($data['designs'] ?? []) as $item) {
        $type = (string)($item['documentType'] ?? '');
        $design = $item['design'] ?? null;
        if (!isset(PRINT_TEMPLATE_MAP[$type]) || !is_array($design)) continue;
        $errors = $compiler->validate($design);
        if ($errors) {
            resultInfo(false, 'VALIDATION_ERROR', 'Design ungültig (' . $type . '): ' . implode(', ', $errors));
            return;
        }
        $items[] = ['document_type' => $type, 'design' => $design];
    }
    if (empty($items)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Keine Designs übergeben');
        return;
    }

    $db = DbhCompany::begin();
    $row = $db->getOne(<<<SQL
        WITH params AS (
            SELECT CAST(:set AS text) AS template_set, CAST(:employee_id AS integer) AS employee_id
        ),
        input AS (
            SELECT x->>'document_type' AS document_type, x->'design' AS design
            FROM jsonb_array_elements(CAST(:designs AS jsonb)) x
        ),
        retired AS (
            UPDATE print_template_designs d SET is_current = false
            FROM input i, params p
            WHERE d.template_set = p.template_set AND d.document_type = i.document_type AND d.is_current
            RETURNING d.document_type
        ),
        pruned AS (
            DELETE FROM print_template_designs d
            USING params p
            WHERE d.template_set = p.template_set
              AND d.id IN (
                  SELECT z.id FROM (
                      SELECT o.id, row_number() OVER (PARTITION BY o.document_type ORDER BY o.version DESC) AS rn
                      FROM print_template_designs o, params q
                      WHERE o.template_set = q.template_set
                        AND o.document_type IN (SELECT document_type FROM input)
                  ) z WHERE z.rn >= 20
              )
            RETURNING d.id
        ),
        inserted AS (
            INSERT INTO print_template_designs (template_set, document_type, design, version, employee_id)
            SELECT p.template_set, i.document_type, i.design,
                   COALESCE((SELECT MAX(o.version) FROM print_template_designs o
                             WHERE o.template_set = p.template_set AND o.document_type = i.document_type), 0) + 1,
                   p.employee_id
            FROM input i, params p
            RETURNING document_type, version, id
        )
        SELECT json_object_agg(document_type, json_build_object('version', version, 'id', id)) AS result
        FROM inserted
    SQL, [
        ':set'         => $set['set'],
        ':employee_id' => mitarbeiterId($data),
        ':designs'     => json_encode($items),
    ]);
    $saved = json_decode($row['result'] ?? '{}', true) ?: [];

    // Vorlagendateien schreiben
    $written = [];
    $backups = [];
    $alsoKfz = !empty($data['alsoKfz']);
    foreach ($items as $item) {
        $type = $item['document_type'];
        $latex = $compiler->compile($item['design']);
        $files = [PRINT_TEMPLATE_MAP[$type][0]];
        if ($alsoKfz && in_array($type, ['order', 'sales_order_intake'], true)) {
            $files[] = 'kfz_order.tex';
        }
        foreach ($files as $file) {
            $path = $set['dir'] . '/' . $file;
            if (is_file($path)) {
                $head = (string)file_get_contents($path, false, null, 0, 64);
                if (strncmp($head, DESIGNER_MARKER, strlen(DESIGNER_MARKER)) !== 0) {
                    $backup = $path . '.bak-' . date('Ymd-His');
                    if (@copy($path, $backup)) $backups[] = basename($backup);
                }
            }
            if (@file_put_contents($path, $latex) === false) {
                resultInfo(false, 'WRITE_ERROR', 'Vorlagendatei konnte nicht geschrieben werden: ' . $file);
                return;
            }
            $written[] = $file;
        }
    }

    resultInfo(true, 'OK', [
        'saved'   => (object)$saved,
        'written' => $written,
        'backups' => $backups,
    ]);
}

/**
 * Entfernt das Design einer Belegart und stellt die letzte Sicherung wieder her
 *
 * Löscht alle Versionen der Belegart im Set und ersetzt die erzeugte
 * .tex-Datei durch die neueste .tex.bak-Sicherung, falls es eine gibt.
 * Gibt es keine, bleibt die erzeugte Datei stehen — ohne Vorlage wäre die
 * Belegart nicht mehr druckbar.
 *
 * @param string $data['templateSet'] Vorlagensatz
 * @param string $data['documentType'] Belegart
 * @testdata {"templateSet": "templates/firma", "documentType": "invoice"}
 */
function removeTemplateDesign($data) {
    requireSystemAdmin();
    $set = designerResolveSet((string)($data['templateSet'] ?? ''));
    $type = (string)($data['documentType'] ?? '');
    if (!$set['writable'] || !isset(PRINT_TEMPLATE_MAP[$type])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Vorlagensatz schreibgeschützt oder Belegart unbekannt');
        return;
    }

    $db = DbhCompany::begin();
    $db->execute(
        "DELETE FROM print_template_designs WHERE template_set = :set AND document_type = :type",
        [':set' => $set['set'], ':type' => $type]
    );

    $file = PRINT_TEMPLATE_MAP[$type][0];
    $path = $set['dir'] . '/' . $file;
    $restored = null;
    $backups = glob($path . '.bak-*') ?: [];
    if ($backups) {
        sort($backups);
        $latest = end($backups);
        if (@copy($latest, $path)) {
            $restored = basename($latest);
        }
    }

    resultInfo(true, 'OK', ['restored' => $restored]);
}

/**
 * Rendert ein Design mit echten Belegdaten als PDF (ohne zu speichern)
 *
 * Nutzt dieselbe Engine wie der Druck: Design -> .tex -> Platzhalter ->
 * latexmk. Bei Fehlern kommt das LaTeX-Protokoll und der erzeugte Quelltext
 * zurück, damit sich die Ursache im Editor zeigen lässt.
 *
 * @param string $data['templateSet'] Vorlagensatz (für Bilder und Ressourcen)
 * @param string $data['documentType'] Belegart
 * @param array $data['design'] Design-JSON
 * @param int $data['documentId'] Optional: Beleg für die Vorschau, sonst der neueste
 * @param string $data['format'] Optional: 'png' liefert die Seiten gerastert (Browser ohne PDF-Anzeige, Tablets)
 * @param string $data['content-type'] Optional: 'application/pdf' für Binärausgabe
 * @testdata {"templateSet": "Standard", "documentType": "invoice", "design": {"page": {}, "blocks": [], "body": {"sections": []}}}
 */
function previewTemplateDesign($data) {
    requireSystemAdmin();
    $isPdfRequest = isset($data['content-type']) && $data['content-type'] === 'application/pdf';
    $fail = function (string $code, string $text, string $debug = '') use ($isPdfRequest) {
        if ($isPdfRequest) header('Content-Type: application/json');
        resultInfo(false, $code, $text, $debug);
    };

    $set = designerResolveSet((string)($data['templateSet'] ?? ''));
    $type = (string)($data['documentType'] ?? 'invoice');
    $design = $data['design'] ?? null;
    if (!isset(PRINT_TEMPLATE_MAP[$type]) || !is_array($design)) {
        $fail('VALIDATION_ERROR', 'Belegart oder Design fehlt');
        return;
    }

    $compiler = new TemplateDesignCompiler();
    $errors = $compiler->validate($design);
    if ($errors) {
        $fail('VALIDATION_ERROR', 'Design ungültig: ' . implode(', ', $errors));
        return;
    }

    // Auch bei abgebrochener Anfrage zu Ende rendern und aufraeumen
    ignore_user_abort(true);
    $db = DbhCompany::begin();
    $lxCars = isLxCarsEnabled($db);
    $documentId = intval($data['documentId'] ?? 0);
    if (!$documentId) {
        $documentId = designerNewestDocumentId($db, $type);
    }
    if (!$documentId) {
        $fail('NO_DOCUMENT', 'Für diese Belegart gibt es noch keinen Beleg als Vorschaugrundlage');
        return;
    }

    $vars = loadPrintData($db, $documentId, $type, $lxCars);
    if ($vars === false) {
        $fail('DATA_ERROR', 'Belegdaten konnten nicht geladen werden');
        return;
    }

    $latexTemplate = $compiler->compile($design);
    $engine = new LaTeXTemplateEngine($set['dir']);
    $engine->setVariables($vars['variables']);
    $engine->setArrays($vars['arrays']);
    $giroPng = buildGiroCodePng($set['dir'], $vars, $type);
    if ($giroPng !== null) {
        $engine->addExtraFile('giroqr.png', $giroPng);
    }

    $latex = $engine->parseString($latexTemplate);
    if ($latex === false) {
        $fail('TEMPLATE_ERROR', 'Vorlage fehlerhaft: ' . $engine->getError(), $latexTemplate);
        return;
    }
    $pdfPath = $engine->compile($latex);
    if ($pdfPath === false) {
        $fail('PDF_ERROR', 'LaTeX-Kompilierung fehlgeschlagen', $engine->getError() . "\n\n--- Vorlage ---\n" . $latexTemplate);
        return;
    }

    designerOutputPdf($pdfPath, $engine, $data, $documentId);
}

/**
 * Gibt ein gerendertes Vorschau-PDF aus: binaer, als Base64 oder als gerasterte Seiten
 *
 * @param string $pdfPath Pfad der fertigen PDF (Arbeitsordner der Engine)
 * @param LaTeXTemplateEngine $engine Engine zum Aufraeumen
 * @param array $data Anfrage ('content-type' = application/pdf, 'format' = png)
 * @param int $documentId Beleg der Vorschau
 */
function designerOutputPdf(string $pdfPath, LaTeXTemplateEngine $engine, array $data, int $documentId): void {
    $isPdfRequest = isset($data['content-type']) && $data['content-type'] === 'application/pdf';
    $pdf = file_get_contents($pdfPath);

    // Gerasterte Seiten: fuer Browser ohne eingebaute PDF-Anzeige
    if (($data['format'] ?? '') === 'png') {
        $prefix = dirname($pdfPath) . '/page';
        exec('/usr/bin/pdftoppm -png -r 72 -l 6 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($prefix) . ' 2>&1');
        $pages = [];
        foreach (glob($prefix . '-*.png') ?: [] as $file) {
            $pages[] = base64_encode((string)file_get_contents($file));
        }
        $engine->cleanup($pdfPath);
        resultInfo(true, 'OK', ['pages' => $pages, 'documentId' => $documentId]);
        return;
    }
    $engine->cleanup($pdfPath);

    if ($isPdfRequest) {
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: inline; filename="vorschau.pdf"');
        echo $pdf;
    } else {
        resultInfo(true, 'OK', ['pdf' => base64_encode($pdf), 'documentId' => $documentId]);
    }
}

// ===== Quelltext: vorhandene Vorlagendateien bearbeiten =====

/**
 * Prueft einen relativen Dateipfad im Vorlagensatz (nur .tex/.sty, kein Verzeichniswechsel)
 *
 * @return string|null bereinigter Pfad oder null
 */
function designerTexPath($value): ?string {
    $rel = trim((string)$value);
    if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || strpos($rel, "\0") !== false) return null;
    if (!preg_match('/^[A-Za-z0-9_\-\.\/]+\.(tex|sty)$/', $rel)) return null;
    return $rel;
}

/**
 * Ordnet eine Vorlagendatei einer Art zu
 *
 * @return array [kind, documentType|null]
 */
function designerFileKind(string $rel, string $dir): array {
    $base = basename($rel);
    foreach (PRINT_TEMPLATE_MAP as $type => $entry) {
        if ($entry[0] === $base && strpos($rel, '/') === false) return ['document', $type];
    }
    if ($base === 'kfz_order.tex') return ['document', 'order'];
    if (strpos($rel, '/') !== false || in_array($base, ['ident.tex', 'euro_account.tex', 'usd_account.tex', 'chf_account.tex'], true)) return ['identity', null];
    if (in_array($base, ['deutsch.tex', 'english.tex'], true)) return ['language', null];
    if (in_array($base, ['insettings.tex', 'inheaders.tex'], true) || substr($base, -4) === '.sty') return ['settings', null];
    return ['other', null];
}

/**
 * Listet die Vorlagendateien (.tex, .sty) eines Vorlagensatzes
 *
 * Jede Datei mit Art (Belegvorlage, Firmendaten, Sprache, Einstellungen,
 * Sonstiges), Belegart und dem Hinweis, ob sie vom Editor erzeugt wurde.
 *
 * @param string $data['templateSet'] Vorlagensatz
 * @testdata {"templateSet": "Standard"}
 */
function getTemplateFiles($data) {
    requireSystemAdmin();
    $set = designerResolveSet((string)($data['templateSet'] ?? ''));
    $files = [];
    foreach (['/*.{tex,sty}', '/*/*.tex'] as $pattern) {
        foreach (glob($set['dir'] . $pattern, GLOB_BRACE) ?: [] as $file) {
            if (!is_file($file)) continue;
            $rel = substr($file, strlen($set['dir']) + 1);
            [$kind, $type] = designerFileKind($rel, $set['dir']);
            $head = (string)file_get_contents($file, false, null, 0, 64);
            $files[] = [
                'path'         => $rel,
                'kind'         => $kind,
                'documentType' => $type,
                'size'         => filesize($file),
                'mtime'        => date('c', filemtime($file)),
                'generated'    => strncmp($head, DESIGNER_MARKER, strlen(DESIGNER_MARKER)) === 0,
            ];
        }
    }
    usort($files, fn($a, $b) => strcasecmp($a['path'], $b['path']));
    resultInfo(true, '', ['templateSet' => $set['set'], 'writable' => $set['writable'], 'files' => $files]);
}

/**
 * Liest eine Vorlagendatei samt der darin definierten \newcommand-Werte
 *
 * Die Werte (z. B. \firma, \strasse, \iban in ident.tex/euro_account.tex)
 * zeigt der Editor als Formular — so lassen sich Firmendaten pflegen, ohne
 * LaTeX zu lesen.
 *
 * @param string $data['templateSet'] Vorlagensatz
 * @param string $data['path'] Relativer Pfad, z. B. firma/ident.tex
 * @testdata {"templateSet": "Standard", "path": "firma/ident.tex"}
 */
function getTemplateFile($data) {
    requireSystemAdmin();
    $set = designerResolveSet((string)($data['templateSet'] ?? ''));
    $rel = designerTexPath($data['path'] ?? '');
    if ($rel === null || !is_file($set['dir'] . '/' . $rel)) {
        resultInfo(false, 'NOT_FOUND', 'Datei nicht gefunden');
        return;
    }
    $content = (string)file_get_contents($set['dir'] . '/' . $rel);
    [$kind, $type] = designerFileKind($rel, $set['dir']);

    // \newcommand{\name}{Wert} bzw. \renewcommand — Wert ohne geschachtelte Klammern
    $variables = [];
    foreach (explode("\n", $content) as $i => $line) {
        if (preg_match('/^\s*\\\\(?:re)?newcommand\s*\{\\\\([A-Za-z]+)\}\s*\{([^{}]*)\}/', $line, $m)) {
            $variables[] = ['name' => $m[1], 'value' => $m[2], 'line' => $i + 1];
        }
    }

    resultInfo(true, '', [
        'path'         => $rel,
        'content'      => $content,
        'kind'         => $kind,
        'documentType' => $type,
        'generated'    => strncmp($content, DESIGNER_MARKER, strlen(DESIGNER_MARKER)) === 0,
        'writable'     => $set['writable'],
        'variables'    => $variables,
        'backups'      => array_map('basename', glob($set['dir'] . '/' . $rel . '.bak-*') ?: []),
    ]);
}

/**
 * Speichert eine Vorlagendatei; der bisherige Stand wird als .bak-<Zeit> gesichert
 *
 * @param string $data['templateSet'] Vorlagensatz (schreibbar)
 * @param string $data['path'] Relativer Pfad
 * @param string $data['content'] Neuer Inhalt
 * @testdata {"templateSet": "templates/firma", "path": "firma/ident.tex", "content": "..."}
 */
function saveTemplateFile($data) {
    requireSystemAdmin();
    $set = designerResolveSet((string)($data['templateSet'] ?? ''));
    $rel = designerTexPath($data['path'] ?? '');
    if (!$set['writable']) {
        resultInfo(false, 'READ_ONLY', 'Dieser Vorlagensatz ist schreibgeschützt — bitte zuerst eine Kopie anlegen');
        return;
    }
    if ($rel === null || !isset($data['content']) || !is_string($data['content'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Pfad oder Inhalt fehlt');
        return;
    }
    $path = $set['dir'] . '/' . $rel;
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        resultInfo(false, 'WRITE_ERROR', 'Verzeichnis konnte nicht angelegt werden');
        return;
    }
    $backup = null;
    if (is_file($path) && file_get_contents($path) !== $data['content']) {
        $backup = $path . '.bak-' . date('Ymd-His');
        @copy($path, $backup);
        // Nur die letzten zehn Sicherungen je Datei behalten
        $old = glob($path . '.bak-*') ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - 10)) as $stale) @unlink($stale);
    }
    if (@file_put_contents($path, $data['content']) === false) {
        resultInfo(false, 'WRITE_ERROR', 'Datei konnte nicht geschrieben werden');
        return;
    }
    resultInfo(true, 'OK', ['path' => $rel, 'backup' => $backup ? basename($backup) : null]);
}

/**
 * Rendert eine (auch ungespeicherte) Vorlagendatei mit echten Belegdaten als PDF
 *
 * Der Vorlagensatz wird in einen Arbeitsordner kopiert, die bearbeitete Datei
 * dort ersetzt — so wirken Aenderungen an insettings.tex oder ident.tex sofort,
 * ohne den Satz anzufassen.
 *
 * @param string $data['templateSet'] Vorlagensatz
 * @param string $data['path'] Bearbeitete Datei (relativ)
 * @param string $data['content'] Ihr aktueller Inhalt
 * @param string $data['documentType'] Belegart (Standard: aus dem Dateinamen, sonst invoice)
 * @param int $data['documentId'] Optional: Beleg, sonst der neueste
 * @param string $data['format'] Optional: 'png'
 * @param string $data['content-type'] Optional: 'application/pdf'
 * @testdata {"templateSet": "Standard", "path": "invoice.tex", "content": "", "documentType": "invoice"}
 */
function previewTemplateFile($data) {
    requireSystemAdmin();
    $isPdfRequest = isset($data['content-type']) && $data['content-type'] === 'application/pdf';
    $fail = function (string $code, string $text, string $debug = '') use ($isPdfRequest) {
        if ($isPdfRequest) header('Content-Type: application/json');
        resultInfo(false, $code, $text, $debug);
    };
    $set = designerResolveSet((string)($data['templateSet'] ?? ''));
    $rel = designerTexPath($data['path'] ?? '');
    if ($rel === null) { $fail('VALIDATION_ERROR', 'Ungültiger Pfad'); return; }
    [$kind, $fileType] = designerFileKind($rel, $set['dir']);
    $type = (string)($data['documentType'] ?? ($fileType ?: 'invoice'));
    if (!isset(PRINT_TEMPLATE_MAP[$type])) { $fail('VALIDATION_ERROR', 'Belegart unbekannt'); return; }

    // Welche Belegvorlage wird gerendert? Die bearbeitete selbst, sonst die der Belegart
    $templateName = $kind === 'document' ? basename($rel) : PRINT_TEMPLATE_MAP[$type][0];

    $db = DbhCompany::begin();
    $lxCars = isLxCarsEnabled($db);
    $documentId = intval($data['documentId'] ?? 0) ?: designerNewestDocumentId($db, $type);
    if (!$documentId) { $fail('NO_DOCUMENT', 'Für diese Belegart gibt es noch keinen Beleg als Vorschaugrundlage'); return; }
    $vars = loadPrintData($db, $documentId, $type, $lxCars);
    if ($vars === false) { $fail('DATA_ERROR', 'Belegdaten konnten nicht geladen werden'); return; }

    // Arbeitskopie des Satzes mit der bearbeiteten Datei. Bricht der Browser die
    // Anfrage ab (Vorschau wird waehrend des Tippens erneuert), raeumt der
    // Shutdown-Handler trotzdem auf — sonst bleiben Kopien in /tmp liegen.
    ignore_user_abort(true);
    $work = sys_get_temp_dir() . '/oserp_tplsrc_' . uniqid('', true);
    register_shutdown_function('designerRemoveDir', $work);
    if (!recursiveCopy($set['dir'], $work)) { $fail('COPY_ERROR', 'Arbeitskopie fehlgeschlagen'); return; }
    $target = $work . '/' . $rel;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
    file_put_contents($target, (string)($data['content'] ?? ''));
    if (!is_file($work . '/' . $templateName)) {
        designerRemoveDir($work);
        $fail('NOT_FOUND', 'Belegvorlage fehlt im Satz: ' . $templateName);
        return;
    }

    $engine = new LaTeXTemplateEngine($work);
    $engine->setVariables($vars['variables']);
    $engine->setArrays($vars['arrays']);
    $giroPng = buildGiroCodePng($work, $vars, $type);
    if ($giroPng !== null) $engine->addExtraFile('giroqr.png', $giroPng);

    $latex = $engine->parse($templateName);
    if ($latex === false) {
        designerRemoveDir($work);
        $fail('TEMPLATE_ERROR', 'Vorlage fehlerhaft: ' . $engine->getError());
        return;
    }
    $pdfPath = $engine->compile($latex);
    if ($pdfPath === false) {
        $err = $engine->getError();
        designerRemoveDir($work);
        $fail('PDF_ERROR', 'LaTeX-Kompilierung fehlgeschlagen', $err);
        return;
    }
    designerOutputPdf($pdfPath, $engine, $data, $documentId);
    designerRemoveDir($work);
}

/** Loescht ein Arbeitsverzeichnis rekursiv (Symlinks werden nicht verfolgt) */
function designerRemoveDir(string $dir): void {
    if (!is_dir($dir) || strpos($dir, 'oserp_tplsrc_') === false) return;
    foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        if (is_link($path) || is_file($path)) unlink($path);
        else designerRemoveDir($path);
    }
    rmdir($dir);
}

/**
 * Neuester Beleg einer Belegart (für Vorschauen ohne Belegangabe)
 */
function designerNewestDocumentId($db, string $type): int {
    $delivery = in_array($type, PRINT_DELIVERY_TYPES, true);
    $ar = in_array($type, ['invoice', 'invoice_storno', 'credit_note', 'proforma'], true);
    if ($delivery) {
        $sql = "SELECT id FROM delivery_orders WHERE record_type = :rt ORDER BY id DESC LIMIT 1";
        $rt = $type === 'delivery_order' ? 'sales_delivery_order' : 'purchase_delivery_order';
    } elseif ($ar) {
        $sql = "SELECT id FROM ar WHERE invoice AND CASE :rt
                    WHEN 'invoice_storno' THEN storno
                    WHEN 'credit_note' THEN type = 'credit_note'
                    WHEN 'proforma' THEN type = 'proforma'
                    ELSE NOT storno AND COALESCE(type, 'invoice') NOT IN ('credit_note', 'proforma') END
                ORDER BY id DESC LIMIT 1";
        $rt = $type;
    } else {
        $sql = "SELECT id FROM oe WHERE record_type = :rt ORDER BY id DESC LIMIT 1";
        $rt = ['quotation' => 'sales_quotation', 'order' => 'sales_order'][$type] ?? $type;
    }
    $row = $db->getOne($sql, [':rt' => $rt]);
    return intval($row['id'] ?? 0);
}

/**
 * Liefert ein Bild des Vorlagensatzes für die Anzeige im Editor
 *
 * PDF-Logos werden mit pdftoppm zu PNG gerastert, damit der Browser sie zeigt.
 *
 * @param string $data['templateSet'] Vorlagensatz
 * @param string $data['path'] Relativer Pfad innerhalb des Sets
 * @testdata {"templateSet": "Standard", "path": "firma/briefkopf.png"}
 */
function getTemplateDesignImage($data) {
    requireSystemAdmin();
    $set = designerResolveSet((string)($data['templateSet'] ?? ''));
    $rel = (string)($data['path'] ?? '');
    if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungültiger Pfad');
        return;
    }
    $file = $set['dir'] . '/' . $rel;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!is_file($file) || !in_array($ext, DESIGNER_IMAGE_EXT, true)) {
        resultInfo(false, 'NOT_FOUND', 'Bild nicht gefunden');
        return;
    }

    if ($ext === 'pdf') {
        $tmp = sys_get_temp_dir() . '/oserp_tplimg_' . uniqid('', true);
        exec('/usr/bin/pdftoppm -png -r 60 -singlefile -f 1 -l 1 ' . escapeshellarg($file) . ' ' . escapeshellarg($tmp) . ' 2>&1');
        $png = $tmp . '.png';
        if (!is_file($png)) {
            resultInfo(false, 'CONVERT_ERROR', 'PDF konnte nicht gerastert werden');
            return;
        }
        $content = file_get_contents($png);
        unlink($png);
        header('Content-Type: image/png');
    } else {
        $content = file_get_contents($file);
        header('Content-Type: ' . ($ext === 'png' ? 'image/png' : 'image/jpeg'));
    }
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: private, max-age=300');
    echo $content;
}

/**
 * Legt ein Bild (Logo, Briefpapier) im Vorlagensatz ab
 *
 * Speichert unter images/ des Sets. Der Dateityp wird am Inhalt geprüft, der
 * Name auf sichere Zeichen beschränkt.
 *
 * @param string $data['templateSet'] Vorlagensatz (schreibbar)
 * @param string $data['filename'] Gewünschter Dateiname
 * @param string $data['dataUrl'] Bild als data:-URL (PNG, JPEG oder PDF)
 * @testdata {"templateSet": "templates/firma", "filename": "logo.png", "dataUrl": "data:image/png;base64,..."}
 */
function uploadTemplateDesignImage($data) {
    requireSystemAdmin();
    $set = designerResolveSet((string)($data['templateSet'] ?? ''));
    if (!$set['writable']) {
        resultInfo(false, 'READ_ONLY', 'Dieser Vorlagensatz ist schreibgeschützt');
        return;
    }

    $dataUrl = (string)($data['dataUrl'] ?? '');
    if (!preg_match('#^data:([a-z]+/[a-z0-9.+-]+);base64,(.+)$#is', $dataUrl, $m)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bilddaten fehlen');
        return;
    }
    $content = base64_decode($m[2], true);
    if ($content === false || strlen($content) === 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bilddaten unlesbar');
        return;
    }
    if (strlen($content) > 8 * 1024 * 1024) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bild größer als 8 MB');
        return;
    }

    // Typ am Inhalt bestimmen, nicht an der Endung
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);
    $extByMime = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'application/pdf' => 'pdf'];
    if (!isset($extByMime[$mime])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Nur PNG, JPEG oder PDF');
        return;
    }

    $name = pathinfo((string)($data['filename'] ?? 'bild'), PATHINFO_FILENAME);
    $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) ?: 'bild';
    $dir = $set['dir'] . '/images';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        resultInfo(false, 'WRITE_ERROR', 'images/ konnte nicht angelegt werden');
        return;
    }
    $file = $dir . '/' . $name . '.' . $extByMime[$mime];
    if (file_put_contents($file, $content) === false) {
        resultInfo(false, 'WRITE_ERROR', 'Bild konnte nicht gespeichert werden');
        return;
    }

    resultInfo(true, 'OK', [
        'path'   => 'images/' . basename($file),
        'images' => designerListImages($set['dir']),
    ]);
}
