<?php
// backend/api/print/print.php

// ===== Template-Verzeichnisse =====
// OSERP_TEMPLATES_DIR und OSERP_TEMPLATES_DIR_NAME werden von config.php gesetzt
// und stehen erst nach inc.php zur Verfuegung — daher nur in Funktionen verwenden.
define('PRINT_BACKEND_ROOT', realpath(__DIR__ . '/../..'));
define('PRINT_MASTER_SETS', ['RB', 'marei', 'mersiha', 'rev-odt']);
define('PRINT_MASTER_BASE', PRINT_BACKEND_ROOT . '/templates-default');

/**
 * Liest das konfigurierte Template-Set aus der defaults Tabelle
 *
 * Liest defaults.templates (Kivitendo-kompatibel), validiert ob
 * das Verzeichnis existiert. Fallback auf 'Standard' Symlink.
 *
 * @return string Template-Set Name (z.B. 'Autoprofis', 'Standard')
 */
function getTemplateSet($db): string {
    $row = $db->getOne("SELECT templates FROM defaults LIMIT 1", []);
    $set = $row['templates'] ?? '';

    // Validiere: Verzeichnis muss existieren
    if ($set) {
        $dir = resolveTemplateDir($set);
        if ($dir !== false) {
            return $set;
        }
    }

    // Fallback: 'Standard' Symlink
    if (is_dir(PRINT_MASTER_BASE . '/Standard')) {
        return 'Standard';
    }

    // Letzter Fallback: erster Master
    return PRINT_MASTER_SETS[0];
}

/**
 * Loest einen Template-Pfad zu einem Verzeichnis auf
 *
 * Unterstuetzt Kivitendo-Stil (z.B. 'templates/oserp') und
 * einfache Namen (z.B. 'RB' fuer Master-Sets).
 *
 * @param string $templateSet Relativer Pfad oder Name
 * @return string|false Absoluter Verzeichnispfad oder false
 */
function resolveTemplateDir(string $templateSet) {
    if (empty($templateSet)) return false;

    // 1. Kivitendo-Stil: relativer Pfad mit '/' (z.B. 'templates/oserp')
    if (strpos($templateSet, '/') !== false) {
        $dir = PRINT_BACKEND_ROOT . '/' . $templateSet;
        if (is_dir($dir)) {
            return $dir;
        }
        return false;
    }

    // 2. Nur Name: in User-Templates suchen (templates/{name}/)
    $userDir = OSERP_TEMPLATES_DIR . '/' . $templateSet;
    if (is_dir($userDir)) {
        return $userDir;
    }

    // 3. Master oder Symlink: templates-default/{name}/
    $masterDir = PRINT_MASTER_BASE . '/' . $templateSet;
    if (is_dir($masterDir)) {
        return $masterDir;
    }

    return false;
}

/**
 * Gibt das Template-Verzeichnis fuer ein Set zurueck
 *
 * Nutzt resolveTemplateDir() fuer die Aufloesung.
 */
function getTemplateDir(string $templateSet): string {
    $dir = resolveTemplateDir($templateSet);
    if ($dir !== false) {
        return $dir;
    }
    // Fallback
    return PRINT_MASTER_BASE . '/' . PRINT_MASTER_SETS[0];
}

/**
 * Gibt die Liste verfuegbarer Template-Sets zurueck
 *
 * Scannt templates/ nach User-Kopien und templates-default/ nach Master-Sets.
 * Gibt Set-NAMEN zurueck, keine einzelnen .tex-Dateien.
 * @testdata {}
 */
function getTemplateList($data) {
    $db = DbhCompany::begin();
    $activeSet = getTemplateSet($db);

    $templateSets = [];
    $masterSets = [];

    // 1. User-Kopien scannen: templates/{name}/
    $baseDir = OSERP_TEMPLATES_DIR;
    if (is_dir($baseDir)) {
        foreach (scandir($baseDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $baseDir . '/' . $entry;
            if (!is_dir($fullPath)) continue;

            $templateSets[] = [
                'name' => OSERP_TEMPLATES_DIR_NAME . '/' . $entry,
                'label' => $entry,
            ];
        }
    }

    // 2. Master-Sets scannen: templates-default/{name}/
    $printDir = PRINT_MASTER_BASE;
    if (is_dir($printDir)) {
        foreach (scandir($printDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $printDir . '/' . $entry;
            if (!is_dir($fullPath)) continue;

            $isMaster = in_array($entry, PRINT_MASTER_SETS);
            $isSymlink = is_link($fullPath);

            if ($isMaster) {
                $masterSets[] = [
                    'name' => $entry,
                    'label' => $entry,
                ];
            } else {
                // Symlinks wie 'Standard' als verfuegbare Sets anbieten
                $resolvedMaster = null;
                if ($isSymlink) {
                    $target = readlink($fullPath);
                    $resolvedMaster = basename($target);
                }
                $templateSets[] = [
                    'name' => $entry,
                    'label' => $entry,
                    'basedOn' => $resolvedMaster,
                ];
            }
        }
    }

    resultInfo(true, 'OK', [
        'activeSet' => $activeSet,
        'templateSets' => $templateSets,
        'masterSets' => $masterSets,
    ]);
}

/**
 * Erstellt ein neues Template-Set durch Kopie eines Master-Sets
 *
 * Erwartet: masterSet (string), newName (string)
 * Kopiert templates-default/{masterSet}/ nach templates/{newName}/
 * und setzt defaults.templates auf den neuen Namen.
 */
function createTemplateSet($data) {
    $masterSet = $data['masterSet'] ?? '';
    $newName = $data['newName'] ?? '';

    // Sanitize: nur sichere Zeichen erlauben
    $newName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $newName);

    if (empty($newName)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Name fuer neues Template-Set ist erforderlich');
        return;
    }

    if (!in_array($masterSet, PRINT_MASTER_SETS)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungueltiges Master-Template-Set: ' . $masterSet);
        return;
    }

    $masterDir = PRINT_MASTER_BASE . '/' . $masterSet;
    $targetDir = OSERP_TEMPLATES_DIR . '/' . $newName;

    if (!is_dir($masterDir)) {
        resultInfo(false, 'NOT_FOUND', 'Master-Template-Verzeichnis nicht gefunden');
        return;
    }

    if (file_exists($targetDir)) {
        resultInfo(false, 'ALREADY_EXISTS', 'Ein Template-Set mit diesem Namen existiert bereits');
        return;
    }

    // Rekursive Kopie
    if (!recursiveCopy($masterDir, $targetDir)) {
        resultInfo(false, 'COPY_ERROR', 'Fehler beim Kopieren des Template-Verzeichnisses');
        return;
    }

    // Neues Set als aktiv setzen (Kivitendo-Stil: 'templates/name')
    $db = DbhCompany::begin();
    $relativePath = OSERP_TEMPLATES_DIR_NAME . '/' . $newName;
    $db->execute("UPDATE defaults SET templates = :name", [':name' => $relativePath]);

    resultInfo(true, 'OK', [
        'name' => $relativePath,
        'basedOn' => $masterSet,
    ]);
}

/**
 * Hilfsfunktion: Rekursives Kopieren eines Verzeichnisses
 *
 * Kopiert alle Dateien und Unterverzeichnisse. Symlinks werden
 * als regulaere Dateien kopiert (wie Kivitendos dircopy).
 */
function recursiveCopy(string $src, string $dst): bool {
    if (!is_dir($src)) return false;
    if (!mkdir($dst, 0775, true)) return false;

    $dir = opendir($src);
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') continue;

        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;

        if (is_dir($srcPath) && !is_link($srcPath)) {
            if (!recursiveCopy($srcPath, $dstPath)) {
                closedir($dir);
                return false;
            }
        } else {
            if (!copy($srcPath, $dstPath)) {
                closedir($dir);
                return false;
            }
        }
    }
    closedir($dir);
    return true;
}

/**
 * Generiert ein PDF aus einer Faktura
 *
 * Erwartet: fakturaID, fakturaType (invoice|order|quotation|delivery_order|credit_note),
 *           templateSet (optional, sonst aus defaults)
 * Gibt Raw-PDF-Binary zurueck wenn content-type: application/pdf gesetzt ist.
 */
function generatePDF($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $fakturaType = $data['fakturaType'] ?? 'invoice';
    $templateName = $data['templateName'] ?? null;
    $isPdfRequest = isset($data['content-type']) && $data['content-type'] === 'application/pdf';

    if (!$fakturaID) {
        if ($isPdfRequest) header('Content-Type: application/json');
        resultInfo(false, 'VALIDATION_ERROR', 'fakturaID required');
        return;
    }

    $db = DbhCompany::begin();

    // Template-Set ermitteln (ggf. aus Request ueberschrieben)
    $templateSet = $data['templateSet'] ?? null;
    if (!$templateSet || resolveTemplateDir($templateSet) === false) {
        $templateSet = getTemplateSet($db);
    }

    // Feature-Flag pruefen
    $lxCars = isLxCarsEnabled($db);

    // Daten laden
    $vars = loadPrintData($db, $fakturaID, $fakturaType, $lxCars);
    if ($vars === false) {
        if ($isPdfRequest) header('Content-Type: application/json');
        resultInfo(false, 'DATA_ERROR', 'Could not load document data');
        return;
    }

    // Template Engine konfigurieren -- Set-spezifisches Verzeichnis
    $templateDir = getTemplateDir($templateSet);

    // Template automatisch erkennen wenn nicht angegeben
    if (!$templateName) {
        $templateName = detectTemplate($fakturaType, $vars, $lxCars, $templateDir);
    }

    // template_code des Druckers als Datei-Suffix anwenden (siehe printToPrinter)
    $printerId = intval($data['printerId'] ?? 0);
    if ($printerId) {
        $printer = $db->getOne(
            "SELECT template_code FROM printers WHERE id = :id",
            [':id' => $printerId]
        );
        if (!empty($printer['template_code'])) {
            $base = pathinfo($templateName, PATHINFO_FILENAME);
            $suffixName = $base . '_' . $printer['template_code'] . '.tex';
            if (is_file($templateDir . '/' . $suffixName)) {
                $templateName = $suffixName;
            }
        }
    }

    $engine = new LaTeXTemplateEngine($templateDir);
    $engine->setVariables($vars['variables']);
    $engine->setArrays($vars['arrays']);

    // PDF generieren
    $pdfPath = $engine->generatePDF($templateName);
    if ($pdfPath === false) {
        if ($isPdfRequest) header('Content-Type: application/json');
        resultInfo(false, 'PDF_ERROR', 'LaTeX-Kompilierung fehlgeschlagen', $engine->getError());
        return;
    }

    // PDF ausgeben
    $pdfContent = file_get_contents($pdfPath);
    $engine->cleanup($pdfPath);

    if ($isPdfRequest) {
        header('Content-Length: ' . strlen($pdfContent));
        header('Content-Disposition: inline; filename="' . $vars['variables']['filename'] . '"');
        echo $pdfContent;
    } else {
        // Base64-Fallback fuer JSON-Response
        resultInfo(true, 'OK', [
            'pdf' => base64_encode($pdfContent),
            'filename' => $vars['variables']['filename']
        ]);
    }
}

/**
 * Rendert ein einzelnes Dokument zu einer PDF-Datei (ohne Cleanup).
 *
 * Hilfsfunktion fuer den Sammeldruck (generateBatchPdf). Gibt die Engine
 * mit zurueck, damit der Aufrufer das Temp-Verzeichnis spaeter aufraeumen kann.
 *
 * @return array|false ['path' => <pdfPath>, 'filename' => <name>, 'engine' => LaTeXTemplateEngine]
 */
function renderDocumentPdfFile($db, int $fakturaID, string $fakturaType, ?string $templateSet, bool $lxCars, &$error = null) {
    if (!$templateSet || resolveTemplateDir($templateSet) === false) {
        $templateSet = getTemplateSet($db);
    }

    $vars = loadPrintData($db, $fakturaID, $fakturaType, $lxCars);
    if ($vars === false) {
        $error = "Belegdaten fuer ID $fakturaID konnten nicht geladen werden";
        return false;
    }

    $templateDir = getTemplateDir($templateSet);
    $templateName = detectTemplate($fakturaType, $vars, $lxCars, $templateDir);

    $engine = new LaTeXTemplateEngine($templateDir);
    $engine->setVariables($vars['variables']);
    $engine->setArrays($vars['arrays']);

    $pdfPath = $engine->generatePDF($templateName);
    if ($pdfPath === false) {
        $error = $engine->getError();
        return false;
    }

    return [
        'path'     => $pdfPath,
        'filename' => $vars['variables']['filename'] ?? "beleg_$fakturaID.pdf",
        'engine'   => $engine,
    ];
}

/**
 * Erzeugt aus mehreren Belegen EIN zusammengefuehrtes PDF (pdfunite) und gibt es aus.
 *
 * Erwartet eine Liste von Belegen mit jeweils id + fakturaType (Verkaufsseite),
 * rendert jeden einzeln und fuegt sie per pdfunite zusammen. Antwort als PDF-Stream
 * (content-type=application/pdf) oder Base64-JSON.
 *
 * @param array $data['documents'] Liste von {id, fakturaType}
 * @param string $data['filename'] Optionaler Dateiname fuer die Ausgabe
 * @testdata {"documents":[{"id":1,"fakturaType":"invoice"}],"content-type":"application/pdf"}
 */
function generateBatchPdf($data) {
    $documents = $data['documents'] ?? [];
    $isPdfRequest = isset($data['content-type']) && $data['content-type'] === 'application/pdf';

    if (!is_array($documents) || count($documents) === 0) {
        if ($isPdfRequest) header('Content-Type: application/json');
        resultInfo(false, 'VALIDATION_ERROR', 'documents required');
        return;
    }

    $db = DbhCompany::begin();
    $lxCars = isLxCarsEnabled($db);
    $templateSet = $data['templateSet'] ?? null;

    $rendered = [];   // ['path','filename','engine'] je Beleg — fuer Cleanup
    $pdfPaths = [];
    $errors = [];

    foreach ($documents as $doc) {
        $id = intval($doc['id'] ?? 0);
        $type = $doc['fakturaType'] ?? 'invoice';
        if (!$id) continue;

        $err = null;
        $res = renderDocumentPdfFile($db, $id, $type, $templateSet, $lxCars, $err);
        if ($res === false) {
            $errors[] = "Beleg $id: $err";
            continue;
        }
        $rendered[] = $res;
        $pdfPaths[] = $res['path'];
    }

    $cleanup = function () use ($rendered) {
        foreach ($rendered as $r) {
            $r['engine']->cleanup($r['path']);
        }
    };

    if (count($pdfPaths) === 0) {
        $cleanup();
        if ($isPdfRequest) header('Content-Type: application/json');
        resultInfo(false, 'PDF_ERROR', 'Keine PDFs erzeugt', implode("\n", $errors));
        return;
    }

    // Zusammenfuehren (ein einzelnes PDF braucht kein Merge)
    $mergedTemp = null;
    if (count($pdfPaths) === 1) {
        $mergedPath = $pdfPaths[0];
    } else {
        $mergedTemp = sys_get_temp_dir() . '/oserp_batch_' . uniqid('', true) . '.pdf';
        $cmd = '/usr/bin/pdfunite '
            . implode(' ', array_map('escapeshellarg', $pdfPaths)) . ' '
            . escapeshellarg($mergedTemp) . ' 2>&1';
        exec($cmd, $out, $rc);

        if ($rc !== 0 || !file_exists($mergedTemp)) {
            $cleanup();
            if ($mergedTemp && file_exists($mergedTemp)) unlink($mergedTemp);
            if ($isPdfRequest) header('Content-Type: application/json');
            resultInfo(false, 'MERGE_ERROR', 'PDF-Zusammenfuehrung fehlgeschlagen', implode("\n", $out));
            return;
        }
        $mergedPath = $mergedTemp;
    }

    $pdfContent = file_get_contents($mergedPath);

    // Aufraeumen (erst nach dem Einlesen!)
    $cleanup();
    if ($mergedTemp && file_exists($mergedTemp)) unlink($mergedTemp);

    $filename = $data['filename'] ?? 'belege.pdf';
    if ($isPdfRequest) {
        header('Content-Length: ' . strlen($pdfContent));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        echo $pdfContent;
    } else {
        resultInfo(true, 'OK', [
            'pdf' => base64_encode($pdfContent),
            'filename' => $filename,
            'errors' => $errors,
        ]);
    }
}

/**
 * Generiert PDF und sendet es an einen Drucker
 *
 * Erwartet: fakturaID, fakturaType, printerId, templateSet (optional)
 * Setzt oe_ext.gedruckt = true nach erfolgreichem Druck.
 */
function printToPrinter($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $fakturaType = $data['fakturaType'] ?? 'invoice';
    $templateName = $data['templateName'] ?? null;
    $printerId = intval($data['printerId'] ?? 0);

    if (!$fakturaID) {
        resultInfo(false, 'VALIDATION_ERROR', 'fakturaID required');
        return;
    }
    if (!$printerId) {
        resultInfo(false, 'VALIDATION_ERROR', 'printerId required');
        return;
    }

    $db = DbhCompany::begin();

    // Template-Set ermitteln
    $templateSet = $data['templateSet'] ?? null;
    if (!$templateSet || resolveTemplateDir($templateSet) === false) {
        $templateSet = getTemplateSet($db);
    }

    // Drucker laden
    $printer = $db->getOne(
        "SELECT id, printer_description, printer_command, template_code FROM printers WHERE id = :id",
        [':id' => $printerId]
    );
    if (!$printer || empty($printer['printer_command'])) {
        resultInfo(false, 'PRINTER_ERROR', 'Printer not found or no command configured');
        return;
    }

    // template_code des Druckers: Datei-Suffix fuer Vorlagenvarianten.
    // Das Vorlagen-Verzeichnis kommt immer aus defaults.templates.
    // Bsp: template_code = "test", Vorlage = invoice.tex
    //      -> invoice_test.tex vorhanden? -> invoice_test.tex verwenden
    //      -> invoice_test.tex nicht vorhanden? -> Fallback auf invoice.tex
    $templateSuffix = $printer['template_code'] ?? '';

    // Feature-Flag pruefen
    $lxCars = isLxCarsEnabled($db);

    // Daten laden
    $vars = loadPrintData($db, $fakturaID, $fakturaType, $lxCars);
    if ($vars === false) {
        resultInfo(false, 'DATA_ERROR', 'Could not load document data');
        return;
    }

    // PDF generieren -- Set-spezifisches Verzeichnis
    $templateDir = getTemplateDir($templateSet);
    if (!$templateName) {
        $templateName = detectTemplate($fakturaType, $vars, $lxCars, $templateDir);
    }
    // Datei-Suffix anwenden: invoice_test.tex falls vorhanden, sonst invoice.tex
    if ($templateSuffix) {
        $base = pathinfo($templateName, PATHINFO_FILENAME);
        $suffixName = $base . '_' . $templateSuffix . '.tex';
        if (is_file($templateDir . '/' . $suffixName)) {
            $templateName = $suffixName;
        }
    }
    $engine = new LaTeXTemplateEngine($templateDir);
    $engine->setVariables($vars['variables']);
    $engine->setArrays($vars['arrays']);

    $pdfPath = $engine->generatePDF($templateName);
    if ($pdfPath === false) {
        resultInfo(false, 'PDF_ERROR', 'LaTeX-Kompilierung fehlgeschlagen', $engine->getError());
        return;
    }

    // An Drucker senden
    $cmd = sprintf('%s %s 2>&1', $printer['printer_command'], escapeshellarg($pdfPath));
    exec($cmd, $output, $returnCode);

    $engine->cleanup($pdfPath);

    if ($returnCode !== 0) {
        resultInfo(false, 'PRINT_ERROR', 'Printer command failed: ' . implode("\n", $output));
        return;
    }

    // gedruckt-Flag setzen (nur bei Auftraegen mit LxCars-Schema)
    if ($fakturaType === 'order' && $lxCars) {
        $db->execute(
            "UPDATE oe_ext SET gedruckt = true WHERE oe_id = :oe_id",
            [':oe_id' => $fakturaID]
        );
    }

    resultInfo(true, 'PRINTED', ['printer' => $printer['printer_description']]);
}

/**
 * Speichert das aktive Template-Set in den Firmen-Defaults
 *
 * Erwartet: templateSet (string) - Name des Template-Set-Verzeichnisses
 */
function saveTemplateSet($data) {
    $templateSet = $data['templateSet'] ?? '';

    // Sanitize: sichere Zeichen und '/' fuer Kivitendo-Stil erlauben
    $templateSet = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', $templateSet);
    $templateSet = trim($templateSet, '/');

    // Validiere: Verzeichnis muss existieren
    if (resolveTemplateDir($templateSet) === false) {
        resultInfo(false, 'VALIDATION_ERROR', 'Template-Set-Verzeichnis nicht gefunden: ' . $templateSet);
        return;
    }

    $db = DbhCompany::begin();
    $db->execute("UPDATE defaults SET templates = :name", [':name' => $templateSet]);

    resultInfo(true, 'OK', ['templateSet' => $templateSet]);
}

// ===== Hilfsfunktionen =====

/**
 * Prueft ob das Feature lxcars aktiviert ist
 */
function isLxCarsEnabled($db): bool {
    $row = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'features'",
        []
    );
    return $row && str_contains($row['value'], 'lxcars');
}

/**
 * Erkennt automatisch das passende Template basierend auf Dokumenttyp
 *
 * Mappt interne fakturaType-Bezeichnungen auf Kivitendo-kompatible Dateinamen.
 */
function detectTemplate(string $fakturaType, array $vars, bool $lxCarsEnabled = false, ?string $templateDir = null): string {
    $isStorno = !empty($vars['variables']['is_storno']);

    switch ($fakturaType) {
        case 'invoice':
            // Storno: bevorzugt storno_invoice.tex (kivitendo-kompatibel), Fallback invoice.tex
            if ($isStorno && $templateDir && is_file($templateDir.'/storno_invoice.tex')) {
                return 'storno_invoice.tex';
            }
            return 'invoice.tex';

        case 'order':
            // Kfz-Auftrag wenn Feature lxcars aktiv oder Fahrzeug verknuepft
            if ($lxCarsEnabled || !empty($vars['variables']['is_kfz'])) {
                return 'kfz_order.tex';
            }
            return 'sales_order.tex';

        case 'quotation':
            return 'sales_quotation.tex';

        case 'delivery_order':
            return 'sales_delivery_order.tex';

        case 'credit_note':
            return 'credit_note.tex';

        case 'purchase_order':
            return 'purchase_order.tex';

        case 'purchase_delivery_order':
            return 'supplier_delivery_order.tex';

        default:
            return 'sales_order.tex';
    }
}

/**
 * Mappt internen fakturaType auf Kivitendo formname
 */
function mapFormname(string $fakturaType): string {
    $map = [
        'invoice'                   => 'invoice',
        'invoice_storno'            => 'storno_invoice',
        'order'                     => 'sales_order',
        'quotation'                 => 'sales_quotation',
        'delivery_order'            => 'sales_delivery_order',
        'credit_note'               => 'credit_note',
        'purchase_order'            => 'purchase_order',
        'purchase_delivery_order'   => 'supplier_delivery_order',
    ];
    return $map[$fakturaType] ?? $fakturaType;
}

/**
 * Laedt alle Druckdaten fuer ein Dokument und mappt sie auf Template-Variablen
 *
 * @return array|false ['variables' => [...], 'arrays' => [...]]
 */
function loadPrintData($db, int $fakturaID, string $fakturaType, bool $lxCarsEnabled = false) {
    $isInvoice = ($fakturaType === 'invoice');
    $isCreditNote = ($fakturaType === 'credit_note');

    // Haupttabelle bestimmen
    if ($isInvoice || $isCreditNote) {
        $mainTable = 'ar';
        $itemsTable = 'invoice';
    } else {
        $mainTable = 'oe';
        $itemsTable = 'orderitems';
    }

    // === Kopfdaten + Kunde + Mitarbeiter ===
    $headQuery = "
        SELECT
            m.id,
            " . (($isInvoice || $isCreditNote) ? "m.invnumber, m.storno, m.storno_id, m.invnumber_for_credit_note, m.type AS ar_type," : "") . "
            m.ordnumber,
            m.quonumber,
            m.transdate,
            " . (($isInvoice || $isCreditNote) ? "m.duedate, m.deliverydate," : "m.reqdate,") . "
            m.notes,
            m.intnotes,
            m.cusordnumber,
            m.taxincluded,
            m.amount,
            m.netamount,
            m.taxzone_id,
            m.payment_id,
            m.cp_id,
            m.transaction_description,
            c.id AS customer_id,
            c.customernumber,
            c.name AS customer_name,
            c.department_1,
            c.department_2,
            c.street AS customer_street,
            c.zipcode AS customer_zipcode,
            c.city AS customer_city,
            c.country AS customer_country,
            c.ustid AS customer_ustid,
            c.phone AS customer_phone,
            c.fax AS customer_fax,
            c.email AS customer_email,
            c.greeting AS customer_greeting,
            c.natural_person,
            e.name AS employee_name,
            e.deleted_tel AS employee_tel,
            e.deleted_email AS employee_email,
            pt.description_long AS payment_terms
        FROM {$mainTable} m
        LEFT JOIN customer c ON c.id = m.customer_id
        LEFT JOIN employee e ON e.id = m.employee_id
        LEFT JOIN payment_terms pt ON pt.id = m.payment_id
        WHERE m.id = :id
    ";
    $head = $db->getOne($headQuery, [':id' => $fakturaID]);
    if (!$head) return false;

    // === Kontaktperson ===
    $cp = null;
    if (!empty($head['cp_id'])) {
        $cp = $db->getOne(
            "SELECT cp_givenname, cp_name, cp_gender, cp_title FROM contacts WHERE cp_id = :id",
            [':id' => $head['cp_id']]
        );
    }

    // === Positionen ===
    $itemsQuery = "
        SELECT
            ROW_NUMBER() OVER (ORDER BY i.position ASC) AS runningnumber,
            p.partnumber,
            p.part_type,
            i.description,
            i.longdescription,
            i.qty,
            i.unit,
            i.sellprice,
            i.discount,
            ROUND((i.qty * i.sellprice * (1.0 - COALESCE(i.discount, 0)))::numeric, 2) AS linetotal
        FROM {$itemsTable} i
        LEFT JOIN parts p ON p.id = i.parts_id
        WHERE i.trans_id = :id
        ORDER BY i.position ASC
    ";
    $items = $db->getAll($itemsQuery, [':id' => $fakturaID]);

    // === Steuer-Aufschluesselung ===
    $taxQuery = "
        SELECT
            t.taxdescription,
            t.rate,
            ROUND((SUM(sub.linetotal * t.rate))::numeric, 2) AS tax_amount
        FROM (
            SELECT
                i.parts_id,
                ROUND((i.qty * i.sellprice * (1.0 - COALESCE(i.discount, 0)))::numeric, 2) AS linetotal,
                (
                    SELECT tk.tax_id
                    FROM parts p2
                    LEFT JOIN buchungsgruppen bg ON bg.id = p2.buchungsgruppen_id
                    LEFT JOIN taxzone_charts tc ON tc.buchungsgruppen_id = bg.id
                    LEFT JOIN chart c ON c.id = tc.income_accno_id
                    LEFT JOIN taxkeys tk ON tk.chart_id = c.id
                    WHERE p2.id = i.parts_id
                        AND tc.taxzone_id = (SELECT taxzone_id FROM {$mainTable} WHERE id = :id2)
                        AND tk.startdate <= (SELECT transdate FROM {$mainTable} WHERE id = :id3)
                    ORDER BY tk.startdate DESC
                    LIMIT 1
                ) AS tax_id
            FROM {$itemsTable} i
            WHERE i.trans_id = :id
        ) sub
        LEFT JOIN tax t ON t.id = sub.tax_id
        WHERE t.id IS NOT NULL
        GROUP BY t.id, t.taxdescription, t.rate
        ORDER BY t.rate ASC
    ";
    $taxes = $db->getAll($taxQuery, [':id' => $fakturaID, ':id2' => $fakturaID, ':id3' => $fakturaID]);

    // === Kfz-Daten (Auftraege und Rechnungen) ===
    $isKfz = false;
    $carData = null;
    $instructions = [];
    $maengel = [];

    // oe_ext/ar_ext existieren nur wenn LxCars-Schema installiert ist
    if (!$isCreditNote && $lxCarsEnabled) {
        // Fahrzeugdaten aus Erweiterungstabelle laden
        if ($isInvoice) {
            $extQuery = "
                SELECT
                    ext.c_id,
                    ext.km_stand,
                    ext.fertigstellung,
                    car.c_ln,
                    car.c_m,
                    car.c_mt,
                    car.c_fin,
                    car.c_t
                FROM ar_ext ext
                LEFT JOIN cars_lxcars car ON car.c_id = ext.c_id
                WHERE ext.ar_id = :id
            ";
        } else {
            $extQuery = "
                SELECT
                    ext.c_id,
                    ext.km_stand,
                    ext.bringetermin,
                    ext.fertigstellung,
                    ext.gedruckt,
                    car.c_ln,
                    car.c_m,
                    car.c_mt,
                    car.c_fin,
                    car.c_t,
                    car.c_2,
                    car.c_3,
                    car.c_d,
                    car.c_hu,
                    car.c_mkb,
                    car.c_st_l,
                    car.c_wt_l,
                    car.c_color,
                    car.c_zrk,
                    car.c_zrd,
                    car.c_bf,
                    car.c_wd
                FROM oe_ext ext
                LEFT JOIN cars_lxcars car ON car.c_id = ext.c_id
                WHERE ext.oe_id = :id
            ";
        }
        $carData = $db->getOne($extQuery, [':id' => $fakturaID]);

        $hasVehicle = $carData && !empty($carData['c_id']);
        $isKfz = $hasVehicle || $lxCarsEnabled;

        // Arbeitsanweisungen und Maengel: bei lxcars oder verknuepftem Fahrzeug laden
        if ($isKfz) {
            if (!$isInvoice) {
                $instructions = $db->getAll(
                    "SELECT instruction_number, description FROM oe_instructions_lxcars
                     WHERE oe_id = :id ORDER BY sort_order ASC, id ASC",
                    [':id' => $fakturaID]
                );
            }

            if ($isInvoice) {
                $maengel = $db->getAll(
                    "SELECT defect_code, defect_description, defect_class FROM ar_defects
                     WHERE ar_id = :id ORDER BY sort_order ASC, id ASC",
                    [':id' => $fakturaID]
                );
            } else {
                $maengel = $db->getAll(
                    "SELECT defect_code, defect_description, defect_class FROM oe_defects
                     WHERE oe_id = :id ORDER BY sort_order ASC, id ASC",
                    [':id' => $fakturaID]
                );
            }
        }
    }

    // === Firmenname fuer Kivitendo-Kompatibilitaet ===
    $companyName = '';
    $companyRow = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'company_name'", []);
    if ($companyRow) {
        $companyName = $companyRow['value'];
    }

    // ===== Template-Variablen mappen =====
    $fmt = function($value) {
        return number_format(floatval($value), 2, ',', '.');
    };

    $isStorno = ($isInvoice || $isCreditNote)
        && (($head['storno'] ?? false) === true
            || ($head['storno'] ?? false) === 't'
            || ($head['ar_type'] ?? '') === 'invoice_storno');

    $formname = mapFormname($isStorno ? 'invoice_storno' : $fakturaType);

    $variables = [
        // Sprache/Waehrung
        'language_code'   => 'DE',
        'currency'        => 'EUR',

        // Kivitendo-Kompatibilitaet
        'media'           => 'printer',
        'employee_company' => $companyName,
        'template_meta'   => [
            'language' => ['template_code' => 'DE'],
            'formname' => $formname,
        ],
        'formname'        => $formname,

        // Dokument-Typ-Flags (kivitendo-kompatibel)
        'storno'          => $isStorno ? '1' : '',
        'is_storno'       => $isStorno ? '1' : '',
        'is_credit_note'  => $isCreditNote ? '1' : '',
        'is_invoice'      => ($isInvoice && !$isStorno) ? '1' : '',
        'invnumber_for_credit_note' => $head['invnumber_for_credit_note'] ?? '',

        // Belegnummern und Daten
        'invnumber'       => $head['invnumber'] ?? '',
        'ordnumber'       => $head['ordnumber'] ?? '',
        'quonumber'       => $head['quonumber'] ?? '',
        'invdate'         => formatDate($head['transdate'] ?? ''),
        'orddate'         => formatDate($head['transdate'] ?? ''),
        'quodate'         => formatDate($head['transdate'] ?? ''),
        'reqdate'         => formatDate($head['reqdate'] ?? ''),
        'duedate'         => formatDate($head['duedate'] ?? ''),
        'deliverydate'    => formatDate($head['deliverydate'] ?? ''),
        'cusordnumber'    => $head['cusordnumber'] ?? '',
        'taxzone_id'      => $head['taxzone_id'] ?? '',
        'transaction_description' => $head['transaction_description'] ?? '',

        // Kunde
        'name'            => $head['customer_name'] ?? '',
        'customernumber'  => $head['customernumber'] ?? '',
        'street'          => $head['customer_street'] ?? '',
        'zipcode'         => $head['customer_zipcode'] ?? '',
        'city'            => $head['customer_city'] ?? '',
        'country'         => $head['customer_country'] ?? '',
        'ustid'           => $head['customer_ustid'] ?? '',
        'department_1'    => $head['department_1'] ?? '',
        'department_2'    => $head['department_2'] ?? '',
        'greeting'        => $head['customer_greeting'] ?? '',
        'natural_person'  => $head['natural_person'] ?? '',
        'customer_phone'  => $head['customer_phone'] ?? '',
        'customer_fax'    => $head['customer_fax'] ?? '',

        // Kontaktperson
        'cp_givenname'    => $cp['cp_givenname'] ?? '',
        'cp_name'         => $cp['cp_name'] ?? '',
        'cp_gender'       => $cp['cp_gender'] ?? '',
        'cp_title'        => $cp['cp_title'] ?? '',

        // Mitarbeiter
        'employee_name'   => $head['employee_name'] ?? '',
        'employee_tel'    => $head['employee_tel'] ?? '',
        'employee_email'  => $head['employee_email'] ?? '',

        // Betraege
        'subtotal'        => $fmt($head['netamount']),
        'invtotal'        => $fmt($head['amount']),
        'ordtotal'        => $fmt($head['amount']),

        // Zahlungsbedingungen
        'payment_terms'   => $head['payment_terms'] ?? '',

        // Notizen
        'notes'           => $head['notes'] ?? '',

        // Kfz
        'is_kfz'          => $isKfz ? '1' : '',

        // Dateiname
        'filename'        => buildFilename($head, $fakturaType),
    ];

    // Kfz-Variablen
    if ($isKfz) {
        $variables['kennzeichen']   = $carData['c_ln'] ?? '';
        $variables['hersteller']    = $carData['c_m'] ?? '';
        $variables['modell']        = $carData['c_mt'] ?? '';
        $variables['fin']           = $carData['c_fin'] ?? '';
        $variables['km_stand']      = !empty($carData['km_stand']) ? number_format(intval($carData['km_stand']), 0, '', '.') : '';
        $variables['bringetermin']  = formatDate($carData['bringetermin'] ?? '');
        $variables['fertigstellung'] = formatDate($carData['fertigstellung'] ?? '');
        $variables['has_instructions'] = count($instructions) > 0 ? '1' : '';
        $variables['has_maengel']   = count($maengel) > 0 ? '1' : '';

        // HU-Status: bestanden wenn keine Mängel mit Klasse EM, VM oder VU
        $huFailed = false;
        foreach ($maengel as $m) {
            if (in_array($m['defect_class'], ['EM', 'VM', 'VU'])) {
                $huFailed = true;
                break;
            }
        }
        $variables['hu_bestanden'] = (count($maengel) > 0 && !$huFailed) ? '1' : '';
        $variables['hu_nicht_bestanden'] = $huFailed ? '1' : '';

        // Erweiterte Fahrzeugdaten
        $variables['kba']               = trim(($carData['c_2'] ?? '') . ' ' . ($carData['c_3'] ?? ''));
        $variables['baujahr']           = formatDate($carData['c_d'] ?? '');
        $variables['hu_au']             = formatDate($carData['c_hu'] ?? '');
        $variables['hu_ueberfaellig']   = (!empty($carData['c_hu']) && $carData['c_hu'] < date('Y-m-d')) ? '1' : '';
        $variables['mkb']               = $carData['c_mkb'] ?? '';
        $variables['sommerraeder_lager'] = $carData['c_st_l'] ?? '';
        $variables['winterraeder_lager'] = $carData['c_wt_l'] ?? '';
        $variables['farbe']             = $carData['c_color'] ?? '';
        $variables['naechster_zr_km']   = $carData['c_zrk'] ?? '';
        $variables['naechster_zr_datum'] = $carData['c_zrd'] ?? '';
        $variables['naechste_bremsfl']  = $carData['c_bf'] ?? '';
        $variables['naechster_wd']      = $carData['c_wd'] ?? '';
        $variables['gedruckt']          = !empty($carData['gedruckt']) ? '1' : '';

        // QR-Code als Bilddatei generieren (benoetigt: apt install qrencode)
        $addr = trim(($head['customer_street'] ?? '') . ', ' . ($head['customer_zipcode'] ?? '') . ' ' . ($head['customer_city'] ?? ''));
        $qrUrl = 'https://www.google.de/maps/place/' . rawurlencode($addr);
        $qrFile = sys_get_temp_dir() . '/oserp_qr_' . $fakturaID . '.png';
        $qrCmd = 'qrencode -o ' . escapeshellarg($qrFile) . ' -s 5 ' . escapeshellarg($qrUrl) . ' 2>&1';
        $qrOutput = shell_exec($qrCmd);
        if (!file_exists($qrFile)) {
            debugLog('QR-Code fehlgeschlagen: ' . $qrCmd . ' => ' . ($qrOutput ?: 'keine Ausgabe'), DLOG_WRN);
        }
        $variables['qr_image'] = file_exists($qrFile) ? $qrFile : '';
    }

    // Positionen-Flag
    $variables['has_positions'] = count($items) > 0 ? '1' : '';

    // ===== Array-Variablen (fuer foreach-Schleifen) =====
    $arrays = [];

    // Positionen
    $arrays['runningnumber'] = array_column($items, 'runningnumber');
    $arrays['number']        = array_column($items, 'partnumber');
    $arrays['description']   = array_column($items, 'description');
    $arrays['longdescription'] = array_column($items, 'longdescription');
    $arrays['qty']           = array_map(function($i) use ($fmt) { return $fmt($i['qty']); }, $items);
    $arrays['unit']          = array_column($items, 'unit');
    $arrays['sellprice']     = array_map(function($i) use ($fmt) { return $fmt($i['sellprice']); }, $items);
    $arrays['p_discount']    = array_map(function($i) {
        $d = floatval($i['discount']) * 100;
        return $d > 0 ? number_format($d, 2, ',', '.') : '0';
    }, $items);
    $arrays['linetotal']     = array_map(function($i) use ($fmt) { return $fmt($i['linetotal']); }, $items);

    // Steuer
    $arrays['taxdescription'] = array_map(function($t) { return $t['taxdescription']; }, $taxes);
    $arrays['tax']            = array_map(function($t) use ($fmt) { return $fmt($t['tax_amount']); }, $taxes);
    $arrays['taxrate']        = array_map(function($t) {
        // Satz aus Dezimalwert (z. B. 0.19) in Prozent (19) ohne unnötige Nachkommastellen
        $prozent = floatval($t['rate']) * 100;
        return rtrim(rtrim(number_format($prozent, 2, ',', '.'), '0'), ',');
    }, $taxes);

    // Kfz-Arrays
    if ($isKfz) {
        $arrays['instruction_number']      = array_column($instructions, 'instruction_number');
        $arrays['instruction_description'] = array_column($instructions, 'description');

        $arrays['defect_code']          = array_column($maengel, 'defect_code');
        $arrays['defect_description']   = array_column($maengel, 'defect_description');
        $arrays['defect_class']         = array_column($maengel, 'defect_class');

        // Items nach Ersatzteile (part) und Arbeiten (service) splitten
        $fmtQty = function($qty) {
            $f = floatval($qty);
            return ($f == intval($f)) ? strval(intval($f)) : number_format($f, 2, ',', '.');
        };
        $ersatzteile = array_filter($items, fn($i) => ($i['part_type'] ?? '') !== 'service');
        $arbeiten    = array_filter($items, fn($i) => ($i['part_type'] ?? '') === 'service');

        $arrays['ersatzteil_qty']         = array_map(fn($i) => $fmtQty($i['qty']) . ' ' . $i['unit'], array_values($ersatzteile));
        $arrays['ersatzteil_description'] = array_column(array_values($ersatzteile), 'description');

        $arrays['arbeit_qty']             = array_map(fn($i) => $fmtQty($i['qty']) . ' ' . $i['unit'], array_values($arbeiten));
        $arrays['arbeit_description']     = array_column(array_values($arbeiten), 'description');

        $variables['has_ersatzteile'] = count($ersatzteile) > 0 ? '1' : '';
        $variables['has_arbeiten']    = count($arbeiten) > 0 ? '1' : '';
    }

    return ['variables' => $variables, 'arrays' => $arrays];
}

/**
 * Formatiert ein Datum aus der DB (YYYY-MM-DD) nach deutschem Format (DD.MM.YYYY)
 */
function formatDate(?string $date): string {
    if (empty($date)) return '';
    $ts = strtotime($date);
    if ($ts === false) return $date;
    return date('d.m.Y', $ts);
}

/**
 * Baut einen sinnvollen PDF-Dateinamen
 */
function buildFilename(array $head, string $fakturaType): string {
    $typeNames = [
        'invoice'       => 'Rechnung',
        'order'         => 'Auftrag',
        'quotation'     => 'Angebot',
        'delivery_order' => 'Lieferschein',
        'credit_note'   => 'Gutschrift',
        'purchase_order' => 'Bestellung',
    ];
    $isStorno = $fakturaType === 'invoice'
        && (($head['storno'] ?? false) === true
            || ($head['storno'] ?? false) === 't'
            || ($head['ar_type'] ?? '') === 'invoice_storno');
    $parts = [];
    $parts[] = $isStorno ? 'Storno' : ($typeNames[$fakturaType] ?? 'Dokument');
    if ($fakturaType === 'invoice' || $fakturaType === 'credit_note') {
        $parts[] = $head['invnumber'] ?? '';
    } elseif ($fakturaType === 'quotation') {
        $parts[] = $head['quonumber'] ?? '';
    } else {
        $parts[] = $head['ordnumber'] ?? '';
    }
    $parts[] = str_replace('-', '', $head['transdate'] ?? date('Y-m-d'));
    return implode('_', array_filter($parts)) . '.pdf';
}
