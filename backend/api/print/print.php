<?php
// backend/api/print/print.php

// ===== Template-Verzeichnisse =====
// OSERP_TEMPLATES_DIR und OSERP_TEMPLATES_DIR_NAME werden von config.php gesetzt
// und stehen erst nach inc.php zur Verfuegung — daher nur in Funktionen verwenden.
define('PRINT_BACKEND_ROOT', realpath(__DIR__ . '/../..'));
define('PRINT_MASTER_SETS', ['RB', 'marei', 'mersiha', 'rev-odt']);
define('PRINT_MASTER_BASE', PRINT_BACKEND_ROOT . '/templates-default');

// ===== Belegarten =====
// Eine Tabelle fuer alle drei Ableitungen, damit Vorlagendatei, Kivitendo-formname
// und Dateiname beim Ergaenzen einer Belegart nicht auseinanderlaufen.
//
//   fakturaType => [Vorlagendatei, Kivitendo-formname, Dateiname-Praefix, Belegnummer-Spalte]
//
// Nicht enthalten: Reklamationen (reclamations), Mahnungen (dunning) und
// Lagerbelege. Die Vorlagen dafuer liegen zwar in den Master-Sets, es gibt aber
// noch keine Maske, die solche Belege anlegt — sie waeren nicht druckbar.
define('PRINT_TEMPLATE_MAP', [
    'invoice'                   => ['invoice.tex',                   'invoice',                   'Rechnung',              'invnumber'],
    'invoice_storno'            => ['storno_invoice.tex',            'storno_invoice',            'Storno',                'invnumber'],
    'credit_note'               => ['credit_note.tex',               'credit_note',               'Gutschrift',            'invnumber'],
    'proforma'                  => ['proforma.tex',                  'proforma',                  'Proformarechnung',      'invnumber'],
    'order'                     => ['sales_order.tex',               'sales_order',               'Auftrag',               'ordnumber'],
    'sales_order_intake'        => ['sales_order.tex',               'sales_order',               'Auftragseingang',       'ordnumber'],
    'quotation'                 => ['sales_quotation.tex',           'sales_quotation',           'Angebot',               'quonumber'],
    'request_quotation'         => ['request_quotation.tex',         'request_quotation',         'Anfrage',               'quonumber'],
    'purchase_quotation_intake' => ['purchase_quotation_intake.tex', 'purchase_quotation_intake', 'Lieferantenangebot',    'quonumber'],
    'purchase_order'            => ['purchase_order.tex',            'purchase_order',            'Bestellung',            'ordnumber'],
    'delivery_order'            => ['sales_delivery_order.tex',      'sales_delivery_order',      'Lieferschein',          'donumber'],
    'purchase_delivery_order'   => ['supplier_delivery_order.tex',   'supplier_delivery_order',   'Lieferantenlieferschein', 'donumber'],
]);

// Belegarten, die im Lieferschein-Schema (delivery_orders) statt in oe/ar liegen
define('PRINT_DELIVERY_TYPES', ['delivery_order', 'purchase_delivery_order']);

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
 * Kopiert templates-default/{masterSet}/ nach templates/{newName}/
 * und setzt defaults.templates auf den neuen Namen.
 *
 * @param string $data['masterSet'] Name des Master-Sets (RB, marei, mersiha, rev-odt)
 * @param string $data['newName'] Name des neuen Sets (nur a-z, A-Z, 0-9, _ und -)
 * @testdata {"masterSet": "RB", "newName": "Testvorlage"}
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
 * Gibt Raw-PDF-Binary zurueck wenn content-type: application/pdf gesetzt ist,
 * sonst Base64 im JSON-Payload.
 *
 * @param int $data['fakturaID'] ID des Belegs
 * @param string $data['fakturaType'] Belegart, siehe PRINT_TEMPLATE_MAP
 * @param string $data['templateSet'] Optional: Vorlagen-Set, sonst aus defaults.templates
 * @param string $data['templateName'] Optional: Vorlagendatei, sonst automatisch erkannt
 * @param int $data['printerId'] Optional: Drucker, dessen template_code als Datei-Suffix dient
 * @param string $data['content-type'] Optional: 'application/pdf' fuer Binaerausgabe
 * @testdata {"fakturaID": 1, "fakturaType": "invoice"}
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

    // GiroCode/EPC-QR fuer Rechnungen: Banking-App scannt -> uebernimmt Empfaenger,
    // IBAN, BIC, Betrag und Verwendungszweck fuer die Ueberweisung.
    $giroPng = buildGiroCodePng($templateDir, $vars, $fakturaType);
    if ($giroPng !== null) {
        $engine->addExtraFile('giroqr.png', $giroPng);
    }

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

    // Versandexemplar aufbewahren, bevor es das Haus verlaesst.
    ausgangsrechnungArchivieren($db, $fakturaID, $fakturaType, $pdfContent,
        $vars['variables']['filename'] ?? null, mitarbeiterId($data));

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
/**
 * Erzeugt den GiroCode/EPC-QR (EPC069-12) fuer eine Rechnung als PNG.
 *
 * Banking-Apps scannen den QR und uebernehmen Empfaenger, IBAN, BIC, Betrag und
 * Verwendungszweck fuer eine SEPA-Ueberweisung. Bankdaten kommen aus der
 * euro_account.tex des Template-Sets (dort liegt die Firmen-IBAN), der Betrag aus
 * dem Beleg (epc_amount, Punkt-Dezimal).
 *
 * @return string|null PNG-Bytes, oder null (kein QR bei falschem Belegtyp/fehlenden Daten)
 */
function buildGiroCodePng(string $templateDir, array $vars, string $fakturaType): ?string {
    // Nur Rechnungen (Geld geht an die eigene Firma).
    if ($fakturaType !== 'invoice') return null;

    $v = $vars['variables'] ?? [];
    $amount = (string)($v['epc_amount'] ?? '');
    if ($amount === '' || (float)$amount <= 0) return null;

    $bank = readTemplateBankData($templateDir);
    if (!$bank || $bank['iban'] === '' || $bank['name'] === '') return null;

    $iban = strtoupper(preg_replace('/\s+/', '', $bank['iban']));   // ohne Leerzeichen
    $bic  = strtoupper(preg_replace('/\s+/', '', $bank['bic']));
    $name = mb_substr(trim($bank['name']), 0, 70);
    $ref  = mb_substr('Rechnung ' . trim((string)($v['invnumber'] ?? '')), 0, 140);

    // EPC069-12: Version 002, Zeichensatz 1 = UTF-8, SEPA Credit Transfer (SCT).
    $epc = implode("\n", ['BCD', '002', '1', 'SCT', $bic, $name, $iban, 'EUR' . $amount, '', '', $ref]);

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open('qrencode -t PNG -l M -8 -s 6 -m 1 -o -', $desc, $pipes);
    if (!is_resource($proc)) return null;
    fwrite($pipes[0], $epc);
    fclose($pipes[0]);
    $png = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0 || $png === '' || substr($png, 1, 3) !== 'PNG') return null;
    return $png;
}

/**
 * Liest IBAN/BIC/Kontoinhaber aus der euro_account.tex des aktiven identpath im Set.
 */
function readTemplateBankData(string $templateDir): ?array {
    $identpath = 'autoprofis';
    $ins = @file_get_contents($templateDir . '/insettings.tex');
    if ($ins !== false && preg_match('/\\\\newcommand\{\\\\identpath\}\{([^}]+)\}/', $ins, $m)) {
        $identpath = trim($m[1]);
    }
    $acc = @file_get_contents($templateDir . '/' . $identpath . '/euro_account.tex');
    if ($acc === false) {
        $g = glob($templateDir . '/*/euro_account.tex');
        $acc = ($g && isset($g[0])) ? @file_get_contents($g[0]) : false;
    }
    if ($acc === false || $acc === '') return null;
    $grab = function ($cmd) use ($acc) {
        return preg_match('/\\\\newcommand\{\\\\' . $cmd . '\}\s*\{([^}]*)\}/', $acc, $mm) ? trim($mm[1]) : '';
    };
    return ['iban' => $grab('iban'), 'bic' => $grab('bic'), 'name' => $grab('kontoinhab')];
}

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

    // GiroCode/EPC-QR fuer Rechnungen: Banking-App scannt -> uebernimmt Empfaenger,
    // IBAN, BIC, Betrag und Verwendungszweck fuer die Ueberweisung.
    $giroPng = buildGiroCodePng($templateDir, $vars, $fakturaType);
    if ($giroPng !== null) {
        $engine->addExtraFile('giroqr.png', $giroPng);
    }

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
        if ($res !== false) {
            ausgangsrechnungArchivieren($db, $id, $type, (string)@file_get_contents($res['path']),
                $res['filename'] ?? null, mitarbeiterId($data));
        }
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
 * Setzt oe_ext.gedruckt = true nach erfolgreichem Druck (nur Auftraege mit LxCars).
 *
 * @param int $data['fakturaID'] ID des Belegs
 * @param string $data['fakturaType'] Belegart, siehe PRINT_TEMPLATE_MAP
 * @param int $data['printerId'] ID des Druckers aus der printers-Tabelle
 * @param string $data['templateSet'] Optional: Vorlagen-Set, sonst aus defaults.templates
 * @param string $data['templateName'] Optional: Vorlagendatei, sonst automatisch erkannt
 * @testdata {"fakturaID": 1, "fakturaType": "invoice", "printerId": 1}
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

    // GiroCode/EPC-QR fuer Rechnungen: Banking-App scannt -> uebernimmt Empfaenger,
    // IBAN, BIC, Betrag und Verwendungszweck fuer die Ueberweisung.
    $giroPng = buildGiroCodePng($templateDir, $vars, $fakturaType);
    if ($giroPng !== null) {
        $engine->addExtraFile('giroqr.png', $giroPng);
    }

    $pdfPath = $engine->generatePDF($templateName);
    if ($pdfPath === false) {
        resultInfo(false, 'PDF_ERROR', 'LaTeX-Kompilierung fehlgeschlagen', $engine->getError());
        return;
    }

    // An Drucker senden
    ausgangsrechnungArchivieren($db, $fakturaID, $fakturaType, (string)@file_get_contents($pdfPath),
        $vars['variables']['filename'] ?? null, mitarbeiterId($data));

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
 * Im Gegensatz zum generischen saveDefaults wird hier geprueft, ob das
 * Verzeichnis wirklich existiert — verhindert einen toten Vorlagen-Pfad.
 *
 * @param string $data['templateSet'] Set-Name ('Standard') oder Kivitendo-Pfad ('templates/oserp')
 * @testdata {"templateSet": "Standard"}
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
    $isKfz    = !empty($vars['variables']['is_kfz']);

    // Kandidaten in absteigender Genauigkeit. Fehlt die Spezialvorlage im Set —
    // etwa in einer aelteren Kopie ohne storno_invoice.tex — wird die Basisvorlage
    // derselben Belegart genommen, statt LaTeX auflaufen zu lassen.
    $candidates = [];

    if ($fakturaType === 'invoice' && $isStorno) {
        $candidates[] = PRINT_TEMPLATE_MAP['invoice_storno'][0];
        $candidates[] = PRINT_TEMPLATE_MAP['invoice'][0];
    }
    elseif (in_array($fakturaType, ['order', 'sales_order_intake'], true) && ($lxCarsEnabled || $isKfz)) {
        // Kfz-Auftrag wenn Feature lxcars aktiv oder Fahrzeug verknuepft
        $candidates[] = 'kfz_order.tex';
        $candidates[] = PRINT_TEMPLATE_MAP[$fakturaType][0];
    }
    elseif (isset(PRINT_TEMPLATE_MAP[$fakturaType])) {
        $candidates[] = PRINT_TEMPLATE_MAP[$fakturaType][0];
    }
    else {
        debugLog('Druck: unbekannte Belegart "' . $fakturaType . '" — Fallback auf sales_order.tex', DLOG_WRN);
        $candidates[] = 'sales_order.tex';
    }

    foreach ($candidates as $candidate) {
        if (!$templateDir || is_file($templateDir . '/' . $candidate)) {
            return $candidate;
        }
    }

    // Keine der Vorlagen liegt im Set. Bewusst KEIN Ausweichen auf eine fremde
    // Belegart — ein Lieferschein darf nicht als Auftrag aus dem Drucker kommen.
    // Die erste Wahl zurueckgeben, damit die LaTeX-Fehlermeldung den fehlenden
    // Dateinamen nennt.
    debugLog('Druck: Vorlage "' . $candidates[0] . '" fehlt in ' . $templateDir, DLOG_WRN);
    return $candidates[0];
}

/**
 * Mappt internen fakturaType auf Kivitendo formname
 */
function mapFormname(string $fakturaType): string {
    return PRINT_TEMPLATE_MAP[$fakturaType][1] ?? $fakturaType;
}

/**
 * Laedt alle Druckdaten fuer ein Dokument und mappt sie auf Template-Variablen
 *
 * @return array|false ['variables' => [...], 'arrays' => [...]]
 */
function loadPrintData($db, int $fakturaID, string $fakturaType, bool $lxCarsEnabled = false) {
    $isInvoice    = ($fakturaType === 'invoice');
    $isCreditNote = ($fakturaType === 'credit_note');
    $isProforma   = ($fakturaType === 'proforma');
    $isDelivery   = in_array($fakturaType, PRINT_DELIVERY_TYPES, true);

    // Rechnungsartige Belege liegen in ar/invoice
    $isArDocument = ($isInvoice || $isCreditNote || $isProforma);

    // Haupttabelle bestimmen. Lieferscheine haben ein eigenes Schema — sie liegen
    // NICHT in oe/orderitems und ihre Positionen haengen an delivery_order_id
    // statt an trans_id.
    if ($isDelivery) {
        $mainTable  = 'delivery_orders';
        $itemsTable = 'delivery_order_items';
        $itemsFk    = 'delivery_order_id';
    }
    elseif ($isArDocument) {
        $mainTable  = 'ar';
        $itemsTable = 'invoice';
        $itemsFk    = 'trans_id';
    }
    else {
        $mainTable  = 'oe';
        $itemsTable = 'orderitems';
        $itemsFk    = 'trans_id';
    }

    // Belegspezifische Kopfspalten — die drei Tabellen haben nicht dieselben Felder
    if ($isDelivery) {
        // delivery_orders kennt weder quonumber noch amount/netamount
        $headColumns = "m.donumber, m.ordnumber, m.transdate, m.reqdate,
                        0 AS amount, 0 AS netamount,";
    }
    elseif ($isArDocument) {
        $headColumns = "m.invnumber, m.storno, m.storno_id, m.invnumber_for_credit_note, m.type AS ar_type,
                        m.ordnumber, m.quonumber, m.transdate, m.duedate, m.deliverydate,
                        m.amount, m.netamount,";
    }
    else {
        $headColumns = "m.ordnumber, m.quonumber, m.transdate, m.reqdate,
                        m.amount, m.netamount,";
    }

    // === Kopfdaten + Kunde/Lieferant + Mitarbeiter + Lieferadresse ===
    // Einkaufsbelege (Bestellung, Lieferantenlieferschein) haengen an vendor
    // statt customer — beide joinen und per COALESCE auf dieselben
    // Template-Variablen legen. ar kennt kein vendor_id (Einkaufsrechnungen
    // liegen in ap), dort laeuft der Join bewusst ins Leere.
    $vendorKey = $isArDocument ? 'NULL' : 'm.vendor_id';
    $headQuery = "
        SELECT
            m.id,
            {$headColumns}
            m.notes,
            m.intnotes,
            m.cusordnumber,
            m.taxincluded,
            m.taxzone_id,
            m.payment_id,
            m.cp_id,
            m.transaction_description,
            COALESCE(c.id, v.id) AS customer_id,
            COALESCE(c.customernumber, v.vendornumber) AS customernumber,
            COALESCE(c.name, v.name) AS customer_name,
            COALESCE(c.department_1, v.department_1) AS department_1,
            COALESCE(c.department_2, v.department_2) AS department_2,
            COALESCE(c.street, v.street) AS customer_street,
            COALESCE(c.zipcode, v.zipcode) AS customer_zipcode,
            COALESCE(c.city, v.city) AS customer_city,
            COALESCE(c.country, v.country) AS customer_country,
            COALESCE(c.ustid, v.ustid) AS customer_ustid,
            COALESCE(c.phone, v.phone) AS customer_phone,
            COALESCE(c.fax, v.fax) AS customer_fax,
            COALESCE(c.email, v.email) AS customer_email,
            COALESCE(c.greeting, v.greeting) AS customer_greeting,
            COALESCE(c.natural_person, v.natural_person) AS natural_person,
            s.shiptoname,
            s.shiptodepartment_1,
            s.shiptodepartment_2,
            s.shiptocontact,
            s.shiptostreet,
            s.shiptozipcode,
            s.shiptocity,
            s.shiptocountry,
            e.name AS employee_name,
            e.deleted_tel AS employee_tel,
            e.deleted_email AS employee_email,
            pt.description_long AS payment_terms
        FROM {$mainTable} m
        LEFT JOIN customer c ON c.id = m.customer_id
        LEFT JOIN vendor v ON v.id = {$vendorKey}
        LEFT JOIN shipto s ON s.shipto_id = m.shipto_id
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
    // Seriennummern fuehrt nur das Lieferschein-Schema
    $serialColumn = $isDelivery ? "i.serialnumber" : "'' AS serialnumber";
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
            {$serialColumn},
            ROUND((i.qty * i.sellprice * (1.0 - COALESCE(i.discount, 0)))::numeric, 2) AS linetotal
        FROM {$itemsTable} i
        LEFT JOIN parts p ON p.id = i.parts_id
        WHERE i.{$itemsFk} = :id
        ORDER BY i.position ASC
    ";
    $items = $db->getAll($itemsQuery, [':id' => $fakturaID]);

    // === Steuer-Aufschluesselung ===
    // Die Umsatzsteuer kommt aus der DB, nicht aus einer LaTeX-/PHP-Nachberechnung:
    //
    // 1. Verbuchte Rechnungen (ar): die tatsaechlichen USt-Betraege stehen in acc_trans
    //    auf den AR_tax-Konten. Sie werden pro Steuersatz uebernommen und stimmen exakt
    //    mit (amount - netamount) ueberein — auch bei taxincluded, wo eine Multiplikation
    //    des Brutto-Zeilenbetrags mit dem Satz die Steuer sonst zu hoch ausweist.
    // 2. Noch nicht verbuchte Belege sowie Auftraege/Angebote (oe) haben keine
    //    acc_trans-Steuerzeilen. Dort wird der DB-Kopfbetrag (amount - netamount)
    //    anhand der Nettoanteile je Steuersatz verteilt — bei nur einem Satz exakt.
    //
    // Lieferscheine weisen keine Betraege aus — die Abfrage waere reine Last.
    $taxes = [];
    if (!$isDelivery) {
        if ($isArDocument) {
            // Pro Steuerkonto den gebuchten USt-Betrag summieren, dann den Satz/die
            // Bezeichnung aus der tax-Tabelle nachschlagen (LATERAL + LIMIT 1 verhindert
            // Doppelzaehlung, falls ein Konto mehrere tax-Eintraege hat).
            $taxes = $db->getAll("
                SELECT tx.taxdescription, tx.rate, s.tax_amount
                FROM (
                    SELECT ac.chart_id, ROUND(SUM(ac.amount)::numeric, 2) AS tax_amount
                    FROM acc_trans ac
                    JOIN chart c ON c.id = ac.chart_id
                    WHERE ac.trans_id = :id AND c.link LIKE '%AR_tax%'
                    GROUP BY ac.chart_id
                    HAVING ROUND(SUM(ac.amount)::numeric, 2) <> 0
                ) s
                JOIN LATERAL (
                    SELECT taxdescription, rate FROM tax
                    WHERE chart_id = s.chart_id ORDER BY rate DESC LIMIT 1
                ) tx ON true
                ORDER BY tx.rate ASC
            ", [':id' => $fakturaID]);
        }

        if (empty($taxes)) {
            // Fallback ohne acc_trans: Nettoanteile je Steuersatz aus den Positionen holen
            // und den DB-Kopf-Steuerbetrag (amount - netamount) proportional verteilen.
            $weights = $db->getAll("
                SELECT sub.rate, sub.taxdescription,
                       ROUND(SUM(sub.linetotal)::numeric, 2) AS net_weight
                FROM (
                    SELECT
                        ROUND((i.qty * i.sellprice * (1.0 - COALESCE(i.discount, 0)))::numeric, 2) AS linetotal,
                        tk.rate, tk.taxdescription
                    FROM {$itemsTable} i
                    LEFT JOIN LATERAL (
                        SELECT t.rate, t.taxdescription
                        FROM parts p2
                        LEFT JOIN buchungsgruppen bg ON bg.id = p2.buchungsgruppen_id
                        LEFT JOIN taxzone_charts tc ON tc.buchungsgruppen_id = bg.id
                        LEFT JOIN chart c ON c.id = tc.income_accno_id
                        LEFT JOIN taxkeys tak ON tak.chart_id = c.id
                        LEFT JOIN tax t ON t.id = tak.tax_id
                        WHERE p2.id = i.parts_id
                            AND tc.taxzone_id = :tz
                            AND tak.startdate <= :td
                        ORDER BY tak.startdate DESC LIMIT 1
                    ) tk ON true
                    WHERE i.{$itemsFk} = :id
                ) sub
                WHERE sub.rate IS NOT NULL AND sub.rate <> 0
                GROUP BY sub.rate, sub.taxdescription
                ORDER BY sub.rate ASC
            ", [':id' => $fakturaID, ':tz' => $head['taxzone_id'], ':td' => $head['transdate']]);

            $headerTax = round(floatval($head['amount']) - floatval($head['netamount']), 2);
            $totalWeight = 0.0;
            foreach ($weights as $w) { $totalWeight += floatval($w['net_weight']); }

            if ($headerTax != 0.0 && abs($totalWeight) > 0.0001) {
                $allocated = 0.0;
                $last = count($weights) - 1;
                foreach ($weights as $idx => $w) {
                    // Letzte Zeile bekommt den Rest, damit die Summe exakt dem Kopf entspricht.
                    if ($idx === $last) {
                        $amt = round($headerTax - $allocated, 2);
                    } else {
                        $amt = round($headerTax * floatval($w['net_weight']) / $totalWeight, 2);
                        $allocated += $amt;
                    }
                    $taxes[] = [
                        'taxdescription' => $w['taxdescription'] ?? 'Umsatzsteuer',
                        'rate'           => $w['rate'],
                        'tax_amount'     => $amt,
                    ];
                }
            }
        }
    }

    // === Kfz-Daten (Auftraege und Rechnungen) ===
    $isKfz = false;
    $carData = null;
    $instructions = [];
    $maengel = [];

    // oe_ext/ar_ext existieren nur wenn LxCars-Schema installiert ist.
    // Lieferscheine haben keine Erweiterungstabelle.
    if (!$isCreditNote && !$isDelivery && $lxCarsEnabled) {
        // Fahrzeugdaten aus Erweiterungstabelle laden
        if ($isArDocument) {
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
            if (!$isArDocument) {
                $instructions = $db->getAll(
                    "SELECT instruction_number, description FROM oe_instructions_lxcars
                     WHERE oe_id = :id ORDER BY sort_order ASC, id ASC",
                    [':id' => $fakturaID]
                );
            }

            if ($isArDocument) {
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
        'donumber'        => $head['donumber'] ?? '',
        'invdate'         => formatDate($head['transdate'] ?? ''),
        'orddate'         => formatDate($head['transdate'] ?? ''),
        'quodate'         => formatDate($head['transdate'] ?? ''),
        'dodate'          => formatDate($head['transdate'] ?? ''),
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

        // Lieferadresse. Bewusst OHNE Fallback auf die Rechnungsadresse:
        // die Vorlagen pruefen selbst auf leeres shiptoname und blenden den
        // Block "abweichende Lieferadresse" dann aus.
        'shiptoname'         => $head['shiptoname'] ?? '',
        'shiptodepartment_1' => $head['shiptodepartment_1'] ?? '',
        'shiptodepartment_2' => $head['shiptodepartment_2'] ?? '',
        'shiptocontact'      => $head['shiptocontact'] ?? '',
        'shiptostreet'       => $head['shiptostreet'] ?? '',
        'shiptozipcode'      => $head['shiptozipcode'] ?? '',
        'shiptocity'         => $head['shiptocity'] ?? '',
        'shiptocountry'      => $head['shiptocountry'] ?? '',

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
        // Roh-Bruttobetrag mit Punkt-Dezimaltrennung fuer den EPC/GiroCode-QR (z.B. "39.15")
        'epc_amount'      => number_format((float)($head['amount'] ?? 0), 2, '.', ''),

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
    $arrays['serialnumber']  = array_column($items, 'serialnumber');
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
    $isStorno = $fakturaType === 'invoice'
        && (($head['storno'] ?? false) === true
            || ($head['storno'] ?? false) === 't'
            || ($head['ar_type'] ?? '') === 'invoice_storno');

    $mapKey = $isStorno ? 'invoice_storno' : $fakturaType;
    $entry  = PRINT_TEMPLATE_MAP[$mapKey] ?? null;

    $parts = [];
    $parts[] = $entry[2] ?? 'Dokument';
    // Belegnummer-Spalte kommt aus derselben Tabelle wie die Vorlage
    $parts[] = $head[$entry[3] ?? 'ordnumber'] ?? '';
    $parts[] = str_replace('-', '', $head['transdate'] ?? date('Y-m-d'));

    return implode('_', array_filter($parts)) . '.pdf';
}
