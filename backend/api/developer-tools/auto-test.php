<?php
// backend/api/developer-tools/auto-test.php

// ===== Gemeinsame Listen =====

function _getDangerousFunctions() {
    return [
        'runAllTests', 'discoverRoutes', 'runWorkflowTests',
        // Database Backup
        'restoreBackup', 'deleteBackup', 'deleteAllBackups', 'createBackup',
        'createDatabaseBackup', 'restoreDatabaseBackup', 'deleteDatabaseBackup',
        'downloadDatabaseBackup',
        // SQL & Schema
        'executeSql',
        // CRM
        'saveCV', 'deleteCV',
        'createPart', 'updatePart', 'deletePart',
        'saveTask', 'createTask', 'updateTask', 'deleteTask',
        'markTaskDone', 'markTaskUndone',
        'saveCalendarEvent', 'deleteCalendarEvent',
        'saveFollowUp', 'deleteFollowUp',
        // Faktura
        'createInvoice', 'updateInvoice', 'deleteInvoice',
        'createOrder', 'updateOrder', 'deleteOrder',
        'saveQuotation', 'deleteQuotation',
        'saveDeliveryOrder', 'deleteDeliveryOrder',
        'saveFakturaData', 'createFaktura', 'createFakturaItem', 'deleteFakturaItem', 'deleteFaktura',
        'updateFakturaItems', 'updateFakturaField', 'convertFaktura',
        // Fahrzeuge
        'saveCar', 'updateCar', 'deleteCar', 'updateOeExt',
        'saveScanImages', 'scanFahrzeugschein', 'mapScanData',
        // Config
        'saveBankAccount', 'deleteBankAccount', 'reorderBankAccounts',
        'saveCrmDefaults', 'saveDefaults',
        'saveTaxzone', 'deleteTaxzone', 'reorderTaxzones',
        'saveTax', 'deleteTax',
        'saveBuchungsgruppe', 'deleteBuchungsgruppe', 'reorderBuchungsgruppen',
        // Print
        'createTemplateSet', 'generatePDF', 'printToPrinter', 'saveTemplateSet',
        // E-Mail
        'sendBrevoEmail', 'sendMail',
        // Setup & Update
        'setupDatabase', 'setupCreateAdmin', 'setupCreateClient',
        'performUpdate', 'updateSchema', 'updateAllDatabases',
        'save', 'test', 'getDefaults',
    ];
}

function _getHelperFunctions() {
    return [
        'resultInfo', 'writeLog', 'ensureBackupDir', 'getDatabaseContext',
        'generateExampleValue', 'parseSchemaContent',
        'parseCreateTableStatements', 'createAutoBackup',
        'connectPDO', 'setupExists', 'permit', 'checkPermissions',
        'getTemplateSet', 'resolveTemplateDir', 'getFakturaTableConfig',
        'getPermissionForFakturaType', 'prepareKba',
        'createAutoBackupForClient', 'updateDatabaseSchema',
        'parseK7oConf', 'getK7oConfig', 'mapK7oConfigToSetup',
        'getSetupDefaults', 'setupEnc', 'validateSetupData',
        'testDatabaseConnection', 'createSettingsIni',
        'mapScanToCarFields', '_updateMasterAverage',
        '_getDangerousFunctions', '_getHelperFunctions', '_getCoreFiles',
        '_scanApiFolders',
    ];
}

function _getCoreFiles() {
    return ['inc.php', 'api.call.php', 'session.php', 'config.php',
            'database.php', 'auth.php', 'logging.php', 'password.php'];
}

/**
 * Scannt API-Ordner und extrahiert Funktionen mit Docblocks
 */
function _scanApiFolders($filterFolder = null) {
    $helperFunctions = _getHelperFunctions();
    $coreFiles = _getCoreFiles();
    $apiDir = __DIR__ . '/../';
    $allFunctions = [];

    if ($filterFolder) {
        $folders = [$filterFolder];
    } else {
        $folders = [];
        $items = scandir($apiDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (is_dir($apiDir . $item)) {
                $folders[] = $item;
            }
        }
        sort($folders);
    }

    foreach ($folders as $folder) {
        $folderPath = $apiDir . $folder . '/';
        $indexFile = $folderPath . 'index.php';
        if (!file_exists($indexFile)) continue;

        $indexContent = file_get_contents($indexFile);
        $functions = [];

        preg_match_all('/(?:include|require)(?:_once)?\s*(?:\()?(?:__DIR__\s*\.\s*)?[\'"]([\w\.\-\/]+)[\'"]/i', $indexContent, $includes);

        if (!empty($includes[1])) {
            foreach ($includes[1] as $includeFile) {
                $includeFile = ltrim($includeFile, '/');
                if (strpos($includeFile, '..') !== false) continue;
                if (in_array(basename($includeFile), $coreFiles)) continue;

                $fullIncludePath = $folderPath . $includeFile;
                if (!file_exists($fullIncludePath)) continue;

                $fileContent = file_get_contents($fullIncludePath);
                preg_match_all('/(?:\/\*\*(.*?)\*\/\s*)?function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/s', $fileContent, $funcMatches, PREG_SET_ORDER);

                foreach ($funcMatches as $match) {
                    $docblock = $match[1] ?? '';
                    $funcName = $match[2];
                    if (in_array($funcName, $helperFunctions)) continue;

                    // @testdata extrahieren
                    $testdata = null;
                    if (preg_match('/@testdata\s+(.+?)$/m', $docblock, $exMatch)) {
                        $exJson = trim($exMatch[1]);
                        $exJson = preg_replace('/^\s*\*\s*/m', '', $exJson);
                        $exJson = trim($exJson);
                        $decoded = json_decode($exJson, true);
                        if ($decoded !== null || $exJson === '{}') {
                            $testdata = $decoded ?? [];
                        }
                    }

                    $functions[$funcName] = [
                        'docblock' => $docblock,
                        'testdata' => $testdata,
                        'has_testdata' => $testdata !== null,
                        'file' => basename($includeFile),
                        'include_path' => $fullIncludePath,
                    ];
                }
            }
        }

        $allFunctions[$folder] = $functions;
    }

    return $allFunctions;
}

/**
 * Fuehrt automatische Tests fuer alle API-Funktionen aus
 * Verwendet die @testdata Annotationen aus den Docblocks
 * Ruft Funktionen direkt auf (kein cURL, funktioniert auch mit Single-Thread-Server)
 *
 * @param string $data['folder'] Optionaler Filter: nur diesen Ordner testen
 * @param string $data['function'] Optionaler Filter: nur diese Funktion testen (erfordert folder)
 * @testdata {}
 */
function runAllTests($data) {
    $filterFolder = $data['folder'] ?? null;
    $filterFunction = $data['function'] ?? null;
    $dangerousFunctions = _getDangerousFunctions();

    $allFunctions = _scanApiFolders($filterFolder);
    $results = [];
    $summary = ['total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0, 'missing' => 0];

    foreach ($allFunctions as $folder => $functions) {
        $results[$folder] = [];

        // Einzelne Funktion filtern
        if ($filterFunction) {
            if (isset($functions[$filterFunction])) {
                $functions = [$filterFunction => $functions[$filterFunction]];
            } else {
                $functions = [];
            }
        }

        foreach ($functions as $funcName => $info) {
            $summary['total']++;

            // Gefaehrliche Funktionen ueberspringen
            if (in_array($funcName, $dangerousFunctions)) {
                $results[$folder][$funcName] = [
                    'success' => true,
                    'skipped' => true,
                    'has_testdata' => false,
                    'time_ms' => 0,
                    'error' => 'Uebersprungen: Funktion aendert Daten',
                ];
                $summary['skipped']++;
                continue;
            }

            if (!$info['has_testdata']) {
                $results[$folder][$funcName] = [
                    'success' => false,
                    'skipped' => false,
                    'has_testdata' => false,
                    'time_ms' => 0,
                    'error' => 'Keine @testdata Annotation gefunden',
                ];
                $summary['missing']++;
                continue;
            }

            // Datei einbinden damit die Funktion verfuegbar ist
            include_once $info['include_path'];

            if (!function_exists($funcName)) {
                $results[$folder][$funcName] = [
                    'success' => false,
                    'skipped' => false,
                    'has_testdata' => true,
                    'time_ms' => 0,
                    'error' => 'Funktion nicht verfuegbar nach include',
                ];
                $summary['failed']++;
                continue;
            }

            // Funktion direkt aufrufen mit Output-Buffering
            $startTime = microtime(true);
            ob_start();
            try {
                $funcName(array_merge(['action' => $funcName], $info['testdata']));
                $response = ob_get_clean();
            } catch (PDOException $e) {
                ob_end_clean();
                $response = json_encode(['success' => false, 'text' => 'DB Error: ' . $e->getMessage()]);
            } catch (ApiError $e) {
                ob_end_clean();
                $response = json_encode(['success' => false, 'text' => $e->getId() . ': ' . $e->getMessage()]);
            } catch (Exception $e) {
                ob_end_clean();
                $response = json_encode(['success' => false, 'text' => 'Error: ' . $e->getMessage()]);
            } catch (\Throwable $e) {
                ob_end_clean();
                $response = json_encode(['success' => false, 'text' => 'Fatal: ' . $e->getMessage()]);
            }

            $endTime = microtime(true);
            $timeMs = round(($endTime - $startTime) * 1000, 1);

            $responseData = json_decode($response, true);

            if ($responseData === null) {
                $results[$folder][$funcName] = [
                    'success' => false,
                    'skipped' => false,
                    'has_testdata' => true,
                    'time_ms' => $timeMs,
                    'error' => 'Ungueltige JSON-Antwort: ' . substr($response, 0, 200),
                ];
                $summary['failed']++;
                continue;
            }

            $testSuccess = isset($responseData['success']) && $responseData['success'] === true;

            $results[$folder][$funcName] = [
                'success' => $testSuccess,
                'skipped' => false,
                'has_testdata' => true,
                'time_ms' => $timeMs,
                'error' => $testSuccess ? null : ($responseData['text'] ?? $responseData['message'] ?? 'Unbekannter Fehler'),
                'response' => $responseData,
            ];

            if ($testSuccess) {
                $summary['passed']++;
            } else {
                $summary['failed']++;
            }
        }
    }

    resultInfo(true, '', [
        'results' => $results,
        'summary' => $summary,
    ]);
}

/**
 * Ermittelt alle API-Routen (Ordner + Funktionen) ohne sie auszufuehren
 *
 * Gibt fuer jeden Ordner die verfuegbaren Aktionen zurueck,
 * inkl. Route-Pfad, Testdaten und ob die Funktion gefaehrlich ist.
 * Der Client kann damit Routen per HTTP testen.
 *
 * @testdata {}
 */
function discoverRoutes($data) {
    $dangerousFunctions = _getDangerousFunctions();
    $allFunctions = _scanApiFolders();
    $routes = [];

    foreach ($allFunctions as $folder => $functions) {
        $folderRoutes = [];
        foreach ($functions as $funcName => $info) {
            $folderRoutes[] = [
                'action' => $funcName,
                'route' => '/api/' . $folder . '/',
                'file' => $info['file'],
                'has_testdata' => $info['has_testdata'],
                'testdata' => $info['testdata'],
                'dangerous' => in_array($funcName, $dangerousFunctions),
            ];
        }
        if (!empty($folderRoutes)) {
            $routes[$folder] = $folderRoutes;
        }
    }

    resultInfo(true, '', ['routes' => $routes]);
}

/**
 * Fuehrt Workflow-Tests durch (mehrstufige Geschaeftsprozesse)
 *
 * Testet z.B. Auftrag -> Rechnung Konvertierung:
 * - Auftrag mit Positionen anlegen
 * - In Rechnung umwandeln
 * - Pruefen: Auftragsnummer, Positionen, Betraege
 * - Test-Daten aufraeumen
 *
 * @testdata {}
 */
function runWorkflowTests($data) {
    $filterWorkflow = $data['workflow'] ?? null;
    $results = [];

    // Workflow-Definitionen
    $workflows = [
        'order_to_invoice' => [
            'name' => 'Auftrag → Rechnung',
            'description' => 'Erstellt einen Auftrag mit Positionen und wandelt ihn in eine Rechnung um',
        ],
        'customer_create_read' => [
            'name' => 'Kunde anlegen & lesen',
            'description' => 'Erstellt einen Testkunden und prueft ob er korrekt geladen wird',
        ],
    ];

    // Wenn einzelner Workflow gefiltert
    if ($filterWorkflow && isset($workflows[$filterWorkflow])) {
        $workflows = [$filterWorkflow => $workflows[$filterWorkflow]];
    }

    foreach ($workflows as $key => $wf) {
        $startTime = microtime(true);
        $steps = [];

        try {
            switch ($key) {
                case 'order_to_invoice':
                    $steps = _testWorkflowOrderToInvoice();
                    break;
                case 'customer_create_read':
                    $steps = _testWorkflowCustomerCreateRead();
                    break;
            }

            $allPassed = true;
            foreach ($steps as $step) {
                if (!$step['success']) {
                    $allPassed = false;
                    break;
                }
            }

            $results[$key] = [
                'name' => $wf['name'],
                'description' => $wf['description'],
                'success' => $allPassed,
                'steps' => $steps,
                'time_ms' => round((microtime(true) - $startTime) * 1000, 1),
            ];
        } catch (\Throwable $e) {
            $results[$key] = [
                'name' => $wf['name'],
                'description' => $wf['description'],
                'success' => false,
                'steps' => array_merge($steps, [[
                    'name' => 'Unerwarteter Fehler',
                    'success' => false,
                    'error' => $e->getMessage(),
                ]]),
                'time_ms' => round((microtime(true) - $startTime) * 1000, 1),
            ];
        }
    }

    resultInfo(true, '', ['workflows' => $results]);
}

/**
 * Workflow: Auftrag -> Rechnung
 */
function _testWorkflowOrderToInvoice() {
    $db = DbhCompany::begin();
    $steps = [];
    $orderId = null;
    $invoiceId = null;

    try {
        // Schritt 1: Stammdaten pruefen (Kunde, Employee, Defaults)
        $customer = $db->getOne("SELECT id, name, taxzone_id FROM customer LIMIT 1", []);
        if (!$customer) {
            $steps[] = ['name' => 'Stammdaten pruefen', 'success' => false, 'error' => 'Kein Kunde in der Datenbank'];
            return $steps;
        }
        $employee = $db->getOne("SELECT id FROM employee LIMIT 1", []);
        $defaults = $db->getOne("SELECT currency_id FROM defaults LIMIT 1", []);
        $taxzoneId = intval($customer['taxzone_id'] ?: 0);
        if (!$taxzoneId) {
            $tz = $db->getOne("SELECT id FROM tax_zones LIMIT 1", []);
            $taxzoneId = $tz ? intval($tz['id']) : 0;
        }
        $currencyId = intval($defaults['currency_id'] ?? 0);
        $employeeId = $employee ? intval($employee['id']) : null;

        $steps[] = [
            'name' => 'Stammdaten pruefen',
            'success' => true,
            'detail' => 'Kunde: ' . $customer['name'] . ', Employee: ' . ($employeeId ?? 'keiner') . ', Currency: ' . $currencyId,
        ];

        // Schritt 2: Auftrag erstellen (wie createFaktura in faktura.php)
        $ordnumber = 'TEST-' . date('YmdHis');
        $result = $db->getOne(
            "INSERT INTO oe (ordnumber, customer_id, transdate, reqdate, employee_id, taxincluded, taxzone_id, currency_id, record_type)
             VALUES (:ordnumber, :customer_id, CURRENT_DATE, CURRENT_DATE + INTERVAL '7 days',
                     :employee_id, false, :taxzone_id, :currency_id, 'sales_order')
             RETURNING id",
            [':ordnumber' => $ordnumber, ':customer_id' => $customer['id'],
             ':employee_id' => $employeeId, ':taxzone_id' => $taxzoneId, ':currency_id' => $currencyId]
        );
        $orderId = $result ? intval($result['id']) : null;

        if (!$orderId) {
            $steps[] = ['name' => 'Auftrag erstellen', 'success' => false, 'error' => 'INSERT fehlgeschlagen'];
            return $steps;
        }
        $steps[] = ['name' => 'Auftrag erstellen', 'success' => true, 'detail' => 'Auftrag ' . $ordnumber . ' (ID ' . $orderId . ')'];

        // Schritt 3: Positionen hinzufuegen
        $partsId = 0;
        $part = $db->getOne("SELECT id FROM parts WHERE obsolete = false LIMIT 1", []);
        if ($part) $partsId = intval($part['id']);

        $db->execute(
            "INSERT INTO orderitems (trans_id, parts_id, description, qty, sellprice, unit, position)
             VALUES (:trans_id, :parts_id, 'Test-Position A', 2, 100.00, 'Stk', 1)",
            [':trans_id' => $orderId, ':parts_id' => $partsId]
        );
        $db->execute(
            "INSERT INTO orderitems (trans_id, parts_id, description, qty, sellprice, unit, position)
             VALUES (:trans_id, :parts_id, 'Test-Position B', 1, 250.00, 'Stk', 2)",
            [':trans_id' => $orderId, ':parts_id' => $partsId]
        );
        $steps[] = ['name' => 'Positionen hinzufuegen', 'success' => true, 'detail' => '2x Test-Position A @ 100.00 + 1x Test-Position B @ 250.00'];

        // Schritt 4: Auftrag laden und pruefen
        $order = $db->getOne("SELECT id, ordnumber, customer_id, record_type FROM oe WHERE id = :id", [':id' => $orderId]);
        $orderItems = $db->getAll(
            "SELECT description, qty, sellprice, position FROM orderitems WHERE trans_id = :id ORDER BY position",
            [':id' => $orderId]
        );

        $orderOk = $order && $order['ordnumber'] === $ordnumber
            && $order['record_type'] === 'sales_order'
            && count($orderItems) === 2;
        $steps[] = [
            'name' => 'Auftrag laden & pruefen',
            'success' => $orderOk,
            'detail' => $orderOk
                ? 'Ordnumber: ' . $order['ordnumber'] . ', Typ: ' . $order['record_type'] . ', Positionen: ' . count($orderItems)
                : 'Auftrag nicht korrekt (ordnumber=' . ($order['ordnumber'] ?? '?') . ', items=' . count($orderItems) . ')',
        ];

        if (!$orderOk) return $steps;

        // Schritt 5: In Rechnung umwandeln (wie convertFaktura in faktura.php)
        $invnumber = 'TINV-' . date('YmdHis');
        $invResult = $db->getOne(
            "INSERT INTO ar (invnumber, ordnumber, customer_id, transdate, gldate, duedate,
                             employee_id, taxincluded, taxzone_id, currency_id, invoice)
             SELECT :invnumber, ordnumber, customer_id, CURRENT_DATE, CURRENT_DATE,
                    CURRENT_DATE + INTERVAL '30 days',
                    employee_id, taxincluded, taxzone_id, currency_id, true
             FROM oe WHERE id = :oe_id
             RETURNING id",
            [':invnumber' => $invnumber, ':oe_id' => $orderId]
        );
        $invoiceId = $invResult ? intval($invResult['id']) : null;

        if (!$invoiceId) {
            $steps[] = ['name' => 'Rechnung erstellen', 'success' => false, 'error' => 'INSERT in ar fehlgeschlagen'];
            return $steps;
        }

        // Positionen kopieren (orderitems → invoice)
        $db->execute(
            "INSERT INTO invoice (trans_id, parts_id, description, qty, sellprice, unit, position)
             SELECT :inv_id, parts_id, description, qty, sellprice, unit, position
             FROM orderitems WHERE trans_id = :oe_id",
            [':inv_id' => $invoiceId, ':oe_id' => $orderId]
        );

        $steps[] = ['name' => 'Rechnung erstellen', 'success' => true, 'detail' => 'Rechnung ' . $invnumber . ' (ID ' . $invoiceId . ')'];

        // Schritt 6: Rechnung validieren
        $invoice = $db->getOne(
            "SELECT id, invnumber, ordnumber, customer_id, taxzone_id, currency_id FROM ar WHERE id = :id",
            [':id' => $invoiceId]
        );
        $invoiceItems = $db->getAll(
            "SELECT description, qty, sellprice, position FROM invoice WHERE trans_id = :id ORDER BY position",
            [':id' => $invoiceId]
        );

        $checks = [];
        $allChecksOk = true;

        // Auftragsnummer uebernommen?
        if ($invoice['ordnumber'] === $ordnumber) {
            $checks[] = 'Auftragsnr. korrekt';
        } else {
            $checks[] = 'FEHLER: Auftragsnr. (erwartet: ' . $ordnumber . ', ist: ' . ($invoice['ordnumber'] ?? 'leer') . ')';
            $allChecksOk = false;
        }

        // Kunde uebernommen?
        if (intval($invoice['customer_id']) === intval($customer['id'])) {
            $checks[] = 'Kunde korrekt';
        } else {
            $checks[] = 'FEHLER: Kunde nicht uebernommen';
            $allChecksOk = false;
        }

        // Steuerzone uebernommen?
        if (intval($invoice['taxzone_id']) === $taxzoneId) {
            $checks[] = 'Steuerzone korrekt';
        } else {
            $checks[] = 'FEHLER: Steuerzone abweichend';
            $allChecksOk = false;
        }

        // Positionen vollstaendig?
        if (count($invoiceItems) === count($orderItems)) {
            $checks[] = count($invoiceItems) . ' Positionen uebernommen';
        } else {
            $checks[] = 'FEHLER: Positionen (erwartet: ' . count($orderItems) . ', ist: ' . count($invoiceItems) . ')';
            $allChecksOk = false;
        }

        // Betraege pruefen
        $invTotal = array_sum(array_map(fn($i) => $i['qty'] * $i['sellprice'], $invoiceItems));
        $orderTotal = array_sum(array_map(fn($i) => $i['qty'] * $i['sellprice'], $orderItems));
        if (abs($invTotal - $orderTotal) < 0.01) {
            $checks[] = 'Betrag: ' . number_format($invTotal, 2) . ' EUR';
        } else {
            $checks[] = 'FEHLER: Betrag (Auftrag: ' . number_format($orderTotal, 2) . ', Rechnung: ' . number_format($invTotal, 2) . ')';
            $allChecksOk = false;
        }

        // Positionsreihenfolge pruefen
        $positionsOk = true;
        for ($i = 0; $i < count($invoiceItems); $i++) {
            if ($invoiceItems[$i]['description'] !== $orderItems[$i]['description']) {
                $positionsOk = false;
                break;
            }
        }
        if ($positionsOk) {
            $checks[] = 'Reihenfolge korrekt';
        } else {
            $checks[] = 'FEHLER: Positionsreihenfolge abweichend';
            $allChecksOk = false;
        }

        $steps[] = [
            'name' => 'Rechnung validieren',
            'success' => $allChecksOk,
            'detail' => implode(' | ', $checks),
        ];

    } finally {
        // Aufraeumen
        $cleanupErrors = [];
        if ($invoiceId) {
            try {
                $db->execute("DELETE FROM invoice WHERE trans_id = :id", [':id' => $invoiceId]);
                $db->execute("DELETE FROM ar WHERE id = :id", [':id' => $invoiceId]);
            } catch (\Throwable $e) {
                $cleanupErrors[] = 'Rechnung: ' . $e->getMessage();
            }
        }
        if ($orderId) {
            _cleanupTestOrder($db, $orderId);
        }

        $steps[] = [
            'name' => 'Test-Daten aufraeumen',
            'success' => empty($cleanupErrors),
            'detail' => empty($cleanupErrors) ? 'Auftrag + Rechnung entfernt' : implode(', ', $cleanupErrors),
        ];
    }

    return $steps;
}

function _cleanupTestOrder($db, $orderId) {
    try {
        $db->execute("DELETE FROM orderitems WHERE trans_id = :id", [':id' => $orderId]);
        $db->execute("DELETE FROM oe WHERE id = :id", [':id' => $orderId]);
    } catch (\Throwable $e) {
        // Cleanup-Fehler still ignorieren
    }
}

/**
 * Workflow: Kunde anlegen & lesen
 */
function _testWorkflowCustomerCreateRead() {
    $db = DbhCompany::begin();
    $steps = [];
    $customerId = null;

    try {
        // Schritt 1: Testkunde anlegen
        $testName = 'Testkunde_AutoTest_' . date('YmdHis');
        $taxzone = $db->getOne("SELECT id FROM tax_zones LIMIT 1", []);
        $defaults = $db->getOne("SELECT currency_id FROM defaults LIMIT 1", []);

        $result = $db->getOne(
            "INSERT INTO customer (name, street, zipcode, city, country, contact, phone, email,
                                   taxzone_id, customernumber)
             VALUES (:name, 'Teststrasse 1', '12345', 'Teststadt', 'DE', 'Max Test',
                     '+49 123 456', 'test@example.com', :taxzone_id, :cnumber)
             RETURNING id",
            [':name' => $testName, ':taxzone_id' => intval($taxzone['id'] ?? 0),
             ':cnumber' => 'TAUTO-' . time()]
        );
        $customerId = $result ? intval($result['id']) : null;

        if (!$customerId) {
            $steps[] = ['name' => 'Kunde anlegen', 'success' => false, 'error' => 'INSERT fehlgeschlagen'];
            return $steps;
        }
        $steps[] = ['name' => 'Kunde anlegen', 'success' => true, 'detail' => $testName . ' (ID ' . $customerId . ')'];

        // Schritt 2: Kunde laden
        $loaded = $db->getOne(
            "SELECT id, name, street, zipcode, city, email FROM customer WHERE id = :id",
            [':id' => $customerId]
        );

        $checks = [];
        $allOk = true;
        if ($loaded && $loaded['name'] === $testName) {
            $checks[] = 'Name korrekt';
        } else {
            $checks[] = 'FEHLER: Name abweichend';
            $allOk = false;
        }
        if ($loaded && $loaded['street'] === 'Teststrasse 1') {
            $checks[] = 'Strasse korrekt';
        } else {
            $checks[] = 'FEHLER: Strasse abweichend';
            $allOk = false;
        }
        if ($loaded && $loaded['email'] === 'test@example.com') {
            $checks[] = 'E-Mail korrekt';
        } else {
            $checks[] = 'FEHLER: E-Mail abweichend';
            $allOk = false;
        }

        $steps[] = ['name' => 'Kunde laden & pruefen', 'success' => $allOk, 'detail' => implode(' | ', $checks)];

        // Schritt 3: Kunde suchen
        $searchResult = $db->getAll(
            "SELECT id, name FROM customer WHERE name ILIKE :q",
            [':q' => '%Testkunde_AutoTest_%']
        );
        $found = false;
        foreach ($searchResult as $row) {
            if (intval($row['id']) === $customerId) {
                $found = true;
                break;
            }
        }
        $steps[] = [
            'name' => 'Kunde per Suche finden',
            'success' => $found,
            'detail' => $found ? 'Gefunden in ' . count($searchResult) . ' Ergebnis(sen)' : 'Nicht gefunden',
        ];

    } finally {
        if ($customerId) {
            try {
                $db->execute("DELETE FROM customer WHERE id = :id", [':id' => $customerId]);
                $steps[] = ['name' => 'Testkunde aufraeumen', 'success' => true, 'detail' => 'Testkunde entfernt'];
            } catch (\Throwable $e) {
                $steps[] = ['name' => 'Testkunde aufraeumen', 'success' => false, 'error' => $e->getMessage()];
            }
        }
    }

    return $steps;
}
