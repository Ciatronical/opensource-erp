<?php
// backend/api/lxcars/hu_serienbrief.php

require_once __DIR__ . '/../print/template_engine.php';
require_once __DIR__ . '/../print/print.php';

/**
 * Lädt die Liste der Kunden mit fälliger HU.
 *
 * Fahrzeuge deren c_hu zwischen heute und heute + Vorlauf-Monate liegt
 * werden gruppiert nach Kunde zurückgegeben.
 *
 * @param array $data['include_excluded'] Optional: auch abgewählte Kunden anzeigen
 * @param string $data['date_from'] Optional: Startdatum (YYYY-MM-DD), Default: 1. des aktuellen Monats
 * @param string $data['date_to'] Optional: Enddatum (YYYY-MM-DD), Default: letzter Tag des Monats in Vorlauf-Monaten
 * @testdata {}
 */
function getHuFaelligList($data) {
    permit('sales_order_edit');

    $mandant = DbhCompany::begin();

    // Vorlauf-Monate aus Konfiguration laden
    $vorlaufRow = $mandant->getOne(
        "SELECT value FROM defaults_oserp WHERE key = :key",
        [':key' => 'lxcars_hu_vorlauf_monate']
    );
    $vorlaufMonate = intval($vorlaufRow['value'] ?? 2);
    if ($vorlaufMonate < 1) $vorlaufMonate = 2;

    // Datumsbereich: genau 1 Monat in der Zukunft, Vorlauf = Anzahl Monate voraus
    // Vorlauf=1 → naechster Monat, Vorlauf=2 → uebernaechster, usw.
    if (!empty($data['date_from'])) {
        $dateFrom = $data['date_from'];
    } else {
        $dateFrom = date('Y-m-01', strtotime("+$vorlaufMonate months"));
    }

    if (!empty($data['date_to'])) {
        $dateTo = $data['date_to'];
    } else {
        $dateTo = date('Y-m-t', strtotime("+$vorlaufMonate months"));
    }

    $includeExcluded = !empty($data['include_excluded']);

    $excludeCondition = $includeExcluded
        ? ''
        : 'AND (cext.hu_serienbrief_excluded IS NULL OR cext.hu_serienbrief_excluded = false)';

    // Fahrzeuge, deren HU-Benachrichtigung im Fahrzeug abgewählt wurde, ausblenden
    $notifyCondition = $includeExcluded
        ? ''
        : 'AND (car.c_hu_notify IS NULL OR car.c_hu_notify = true)';

    $query = <<<SQL
        SELECT
            c.id AS customer_id,
            c.name AS customer_name,
            c.street AS customer_street,
            c.zipcode AS customer_zipcode,
            c.city AS customer_city,
            c.phone AS customer_phone,
            c.email AS customer_email,
            COALESCE(cext.hu_serienbrief_excluded, false) AS hu_excluded,
            json_agg(json_build_object(
                'c_id', car.c_id,
                'c_ln', car.c_ln,
                'c_hu', car.c_hu,
                'c_m', car.c_m,
                'c_t', car.c_t,
                'c_hu_notify', COALESCE(car.c_hu_notify, true)
            ) ORDER BY car.c_hu) AS fahrzeuge
        FROM cars_lxcars car
        JOIN customer c ON c.id = car.c_ow
        LEFT JOIN customer_ext cext ON cext.customer_id = c.id
        WHERE car.c_hu IS NOT NULL
        AND car.c_hu <= :date_to
        AND car.c_hu >= :date_from
        $excludeCondition
        $notifyCondition
        GROUP BY c.id, c.name, c.street, c.zipcode, c.city, c.phone, c.email, cext.hu_serienbrief_excluded
        ORDER BY MIN(car.c_hu) ASC
    SQL;

    try {
        $results = $mandant->getAll($query, [':date_from' => $dateFrom, ':date_to' => $dateTo]);

        // JSON-String zu Array konvertieren
        foreach ($results as &$row) {
            if (is_string($row['fahrzeuge'])) {
                $row['fahrzeuge'] = json_decode($row['fahrzeuge'], true);
            }
            $row['hu_excluded'] = ($row['hu_excluded'] === true || $row['hu_excluded'] === 't');
        }

        resultInfo(true, '', [
            'results' => $results,
            'vorlauf_monate' => $vorlaufMonate,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        echo json_encode([
            'sql_error' => true,
            'error_message' => $errorMessage
        ]);
    }
}

/**
 * Setzt den HU-Serienbrief-Ausschluss für einen Kunden.
 *
 * @param int $data['customer_id'] Kunden-ID
 * @param bool $data['excluded'] true = ausschließen, false = wieder einschließen
 * @testdata {"customer_id": 1, "excluded": true}
 */
function setHuExcluded($data) {
    permit('sales_order_edit');

    $mandant = DbhCompany::begin();
    $customerId = intval($data['customer_id']);
    $excluded = !empty($data['excluded']);

    // UPSERT: customer_ext Zeile anlegen falls nicht vorhanden
    $mandant->execute(
        "INSERT INTO customer_ext (customer_id, hu_serienbrief_excluded)
         VALUES (:customer_id, :excluded)
         ON CONFLICT (customer_id) DO UPDATE SET
            hu_serienbrief_excluded = :excluded,
            mtime = now()",
        [':customer_id' => $customerId, ':excluded' => $excluded ? 't' : 'f']
    );

    resultInfo(true, 'UPDATED');
}

/**
 * Schaltet die HU-Benachrichtigung für ein einzelnes Fahrzeug ein oder aus.
 *
 * Wird sowohl aus der Fahrzeug-Bearbeitung (Schalter neben dem Kennzeichen)
 * als auch aus der Serienbrief-Liste (Fahrzeug abwählen) genutzt.
 *
 * @param int $data['c_id'] Fahrzeug-ID
 * @param bool $data['notify'] true = Benachrichtigung senden, false = abgewählt
 * @testdata {"c_id": 1, "notify": false}
 */
function setCarHuNotify($data) {
    permit('sales_order_edit');

    $mandant = DbhCompany::begin();
    $carId = intval($data['c_id']);
    $notify = !empty($data['notify']);

    $mandant->execute(
        "UPDATE cars_lxcars SET c_hu_notify = :notify WHERE c_id = :c_id",
        [':c_id' => $carId, ':notify' => $notify ? 't' : 'f']
    );

    resultInfo(true, 'UPDATED');
}

/**
 * Interne Hilfsfunktion: Baut das HU-Serienbrief-PDF über die LaTeX-Vorlage.
 *
 * Nutzt hu-serienbrief.tex aus dem aktiven Template-Set. Der Brieftext
 * kommt vollständig aus dem Template, nicht aus der Datenbank.
 *
 * @param array $customerIds Array von Integer-Kunden-IDs
 * @param string $dateFrom Startdatum YYYY-MM-DD (nur Fahrzeuge in diesem Zeitraum)
 * @param string $dateTo Enddatum YYYY-MM-DD
 * @return array ['pdfPath' => string, 'filename' => string, 'engine' => LaTeXTemplateEngine] oder ['error' => string, 'code' => string]
 */
function _buildHuPdf($customerIds, $dateFrom = '', $dateTo = '', &$debug = []) {
    $debug[] = 'Start _buildHuPdf mit IDs: ' . implode(',', $customerIds);
    $idList = implode(',', $customerIds);
    $mandant = DbhCompany::begin();

    // Firmenname für kivicompany (Logo/Briefkopf)
    $companyRow = $mandant->getOne("SELECT value FROM defaults_oserp WHERE key = 'company_name'", []);
    $companyName = $companyRow['value'] ?? '';

    // Optionale Config-Werte für QR-Code und Google-URL
    $qrcodeRow = $mandant->getOne(
        "SELECT value FROM defaults_oserp WHERE key = :key",
        [':key' => 'lxcars_hu_qrcode_url']
    );
    $googleRow = $mandant->getOne(
        "SELECT value FROM defaults_oserp WHERE key = :key",
        [':key' => 'lxcars_hu_google_url']
    );

    // Datumsfilter: Falls nicht übergeben, aus Konfiguration berechnen
    if (empty($dateFrom) || empty($dateTo)) {
        $vorlaufRow = $mandant->getOne(
            "SELECT value FROM defaults_oserp WHERE key = :key",
            [':key' => 'lxcars_hu_vorlauf_monate']
        );
        $vorlaufMonate = max(1, intval($vorlaufRow['value'] ?? 2));
        if (empty($dateFrom)) $dateFrom = date('Y-m-01', strtotime("+$vorlaufMonate months"));
        if (empty($dateTo)) $dateTo = date('Y-m-t', strtotime("+$vorlaufMonate months"));
    }

    // Kundendaten mit Fahrzeugen und Ansprechpartner laden
    $query = <<<SQL
        SELECT
            c.id AS customer_id,
            c.greeting,
            c.name,
            c.street,
            c.zipcode,
            c.city,
            c.country,
            c.customernumber,
            car.c_id,
            car.c_ln,
            car.c_hu,
            car.c_m,
            car.c_t,
            COALESCE(oe_emp.name, e.name, '') AS employee_name
        FROM cars_lxcars car
        JOIN customer c ON c.id = car.c_ow
        LEFT JOIN employee e ON c.employee = e.id
        LEFT JOIN (
            SELECT
                o.customer_id,
                e2.name,
                ROW_NUMBER() OVER (PARTITION BY o.customer_id ORDER BY o.itime DESC, o.id DESC) AS rn
            FROM oe o
            LEFT JOIN employee e2 ON o.employee_id = e2.id
        ) oe_emp ON oe_emp.customer_id = c.id AND oe_emp.rn = 1
        WHERE car.c_hu IS NOT NULL
          AND c.id IN ($idList)
          AND car.c_hu >= :date_from
          AND car.c_hu <= :date_to
          AND (car.c_hu_notify IS NULL OR car.c_hu_notify = true)
        ORDER BY c.name, car.c_hu
    SQL;

    $rows = $mandant->getAll($query, [':date_from' => $dateFrom, ':date_to' => $dateTo]);
    $debug[] = 'Datenbankabfrage: ' . count($rows) . ' Zeilen';
    if (empty($rows)) {
        return ['error' => 'Keine Fahrzeugdaten gefunden', 'code' => 'DATA_ERROR'];
    }

    // Gruppiere nach Kunde
    $customers = [];
    foreach ($rows as $row) {
        $cid = $row['customer_id'];
        if (!isset($customers[$cid])) {
            $customers[$cid] = [
                'greeting'       => $row['greeting'] ?? '',
                'name'           => $row['name'] ?? '',
                'street'         => $row['street'] ?? '',
                'zipcode'        => $row['zipcode'] ?? '',
                'city'           => $row['city'] ?? '',
                'country'        => $row['country'] ?? '',
                'customernumber' => $row['customernumber'] ?? '',
                'employee_name'  => $row['employee_name'] ?? '',
                'fahrzeuge'      => []
            ];
        }
        $customers[$cid]['fahrzeuge'][] = [
            'c_ln' => $row['c_ln'],
            'c_hu' => $row['c_hu'],
            'c_m'  => $row['c_m'] ?? '',
            'c_t'  => $row['c_t'] ?? '',
        ];
    }

    // Template-Set ermitteln
    $templateSet = getTemplateSet($mandant);
    $templateDir = getTemplateDir($templateSet);
    $debug[] = 'Template-Set: ' . $templateSet . ' -> ' . $templateDir;
    $debug[] = 'Template-Dir existiert: ' . (is_dir($templateDir) ? 'JA' : 'NEIN');

    // Parallele Arrays für die Template-Engine aufbauen (kivitendo-Stil)
    $arrays = [
        'customer'       => [],
        'name'           => [],
        'street'         => [],
        'zipcode'        => [],
        'city'           => [],
        'country'        => [],
        'customernumber' => [],
        'employee_name'  => [],
        'salutation'     => [],
        'car'            => [],
        'hu_datum'       => [],
    ];

    $i = 0;
    foreach ($customers as $customer) {
        $arrays['customer'][]       = $i++;
        $arrays['name'][]           = $customer['name'];
        $arrays['street'][]         = $customer['street'];
        $arrays['zipcode'][]        = $customer['zipcode'];
        $arrays['city'][]           = $customer['city'];
        $arrays['country'][]        = $customer['country'];
        $arrays['customernumber'][] = $customer['customernumber'];
        $arrays['employee_name'][]  = $customer['employee_name'];
        $arrays['salutation'][]     = $customer['greeting'];

        // Kennzeichen: alle kommasepariert
        $plates = array_column($customer['fahrzeuge'], 'c_ln');
        $arrays['car'][] = implode(', ', $plates);

        // HU-Datum: frühestes Datum (MM/YYYY)
        $firstHu = $customer['fahrzeuge'][0]['c_hu'];
        $ts = strtotime($firstHu);
        $arrays['hu_datum'][] = $ts ? date('m/Y', $ts) : $firstHu;
    }

    // Template Engine konfigurieren
    $engine = new LaTeXTemplateEngine($templateDir);
    $engine->setArrays($arrays);
    $engine->setVariables([
        'company_name' => $companyName,
        'qrcode'       => $qrcodeRow['value'] ?? '',
        'google_url'   => $googleRow['value'] ?? '',
        'datum'        => date('d.m.Y'),
    ]);

    // Template parsen
    $latex = $engine->parse('hu-serienbrief.tex');
    if ($latex === false) {
        $debug[] = 'Template-Parsing fehlgeschlagen: ' . $engine->getError();
        return ['error' => 'Template-Parsing fehlgeschlagen', 'code' => 'TEMPLATE_ERROR', 'debug' => $debug];
    }

    $debug[] = 'LaTeX-Dokument: ' . strlen($latex) . ' Zeichen, ' . count($customers) . ' Kunden';

    // PDF kompilieren
    $pdfPath = $engine->compile($latex);
    if ($pdfPath === false) {
        $debug[] = 'compile() fehlgeschlagen';
        $debug[] = 'LaTeX-Fehler: ' . ($engine->getError() ?? 'kein Fehlertext');
        return ['error' => 'LaTeX-Kompilierung fehlgeschlagen', 'code' => 'PDF_ERROR', 'debug' => $debug, 'latex' => $latex];
    }

    $debug[] = 'PDF erzeugt: ' . $pdfPath . ' (' . filesize($pdfPath) . ' Bytes)';
    $filename = 'HU-Serienbrief_' . date('Y-m-d') . '.pdf';

    return ['pdfPath' => $pdfPath, 'filename' => $filename, 'engine' => $engine];
}

/**
 * Generiert ein PDF mit HU-Benachrichtigungsbriefen für ausgewählte Kunden.
 *
 * Nutzt den konfigurierbaren Brieftext aus defaults_oserp und die bestehende
 * LaTeX Template Engine. Pro Kunde wird eine Briefseite erzeugt.
 *
 * @param array $data['customer_ids'] Array von Kunden-IDs
 * @param string $data['date_from'] Optional: Startdatum (YYYY-MM-DD)
 * @param string $data['date_to'] Optional: Enddatum (YYYY-MM-DD)
 * @testdata {"customer_ids": [1]}
 */
function generateHuPdf($data) {
    permit('sales_order_edit');

    $customerIds = $data['customer_ids'] ?? [];
    if (empty($customerIds)) {
        resultInfo(false, 'Keine Kunden ausgewählt');
        return;
    }

    $customerIds = array_map('intval', $customerIds);
    $dateFrom = $data['date_from'] ?? '';
    $dateTo = $data['date_to'] ?? '';

    try {
        $debug = [];
        $result = _buildHuPdf($customerIds, $dateFrom, $dateTo, $debug);

        if (isset($result['error'])) {
            resultInfo(false, $result['error'], [
                'debug' => $result['debug'] ?? $debug,
                'latex' => $result['latex'] ?? null
            ]);
            return;
        }

        $pdfContent = file_get_contents($result['pdfPath']);
        $result['engine']->cleanup($result['pdfPath']);

        resultInfo(true, 'OK', [
            'pdf'      => base64_encode($pdfContent),
            'filename' => $result['filename'],
            'debug'    => $debug
        ]);
    } catch (Exception $e) {
        resultInfo(false, 'Exception: ' . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

/**
 * Generiert das HU-Serienbrief-PDF und versendet es per SFTP an den eLetter-Service.
 *
 * Nutzt die eLetter-Konfiguration aus defaults_oserp (eletter_hostname, eletter_username,
 * eletter_folder, eletter_passwd) und die PHP ssh2-Extension.
 *
 * @param array $data['customer_ids'] Array von Kunden-IDs
 * @param string $data['date_from'] Optional: Startdatum (YYYY-MM-DD)
 * @param string $data['date_to'] Optional: Enddatum (YYYY-MM-DD)
 * @testdata {"customer_ids": [1]}
 */
function sendHuPdfViaSftp($data) {
    permit('sales_order_edit');

    $customerIds = $data['customer_ids'] ?? [];
    if (empty($customerIds)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Keine Kunden ausgewählt');
        return;
    }

    $customerIds = array_map('intval', $customerIds);
    $dateFrom = $data['date_from'] ?? '';
    $dateTo = $data['date_to'] ?? '';

    // eLetter-Konfiguration laden
    $mandant = DbhCompany::begin();
    $configKeys = ['eletter_hostname', 'eletter_username', 'eletter_folder', 'eletter_passwd'];
    $eletterConfig = [];
    foreach ($configKeys as $key) {
        $row = $mandant->getOne(
            "SELECT value FROM defaults_oserp WHERE key = :key",
            [':key' => $key]
        );
        $eletterConfig[$key] = $row['value'] ?? '';
    }

    if (empty($eletterConfig['eletter_hostname']) || empty($eletterConfig['eletter_username'])) {
        resultInfo(false, 'CONFIG_ERROR', 'eLetter-Konfiguration unvollständig');
        return;
    }

    // PDF generieren
    $result = _buildHuPdf($customerIds, $dateFrom, $dateTo);
    if (isset($result['error'])) {
        resultInfo(false, $result['code'], $result['error']);
        return;
    }

    // Per SFTP hochladen
    try {
        if (!function_exists('ssh2_connect')) {
            $result['engine']->cleanup($result['pdfPath']);
            resultInfo(false, 'SSH2_MISSING', 'PHP-Extension ssh2 ist nicht installiert');
            return;
        }

        $connection = @ssh2_connect($eletterConfig['eletter_hostname'], 22);
        if (!$connection) {
            throw new Exception('Verbindung zu ' . $eletterConfig['eletter_hostname'] . ' fehlgeschlagen');
        }

        if (!@ssh2_auth_password($connection, $eletterConfig['eletter_username'], $eletterConfig['eletter_passwd'])) {
            throw new Exception('SFTP-Authentifizierung fehlgeschlagen');
        }

        $sftp = @ssh2_sftp($connection);
        if (!$sftp) {
            throw new Exception('SFTP-Subsystem konnte nicht initialisiert werden');
        }

        $remoteFile = '/' . trim($eletterConfig['eletter_folder'], '/') . '/' . $result['filename'];
        $stream = @fopen("ssh2.sftp://$sftp$remoteFile", 'w');
        if (!$stream) {
            throw new Exception('Remote-Datei konnte nicht geöffnet werden: ' . $remoteFile);
        }

        $pdfContent = file_get_contents($result['pdfPath']);
        if (fwrite($stream, $pdfContent) === false) {
            throw new Exception('Fehler beim Schreiben der Datei');
        }
        fclose($stream);

        $result['engine']->cleanup($result['pdfPath']);
        resultInfo(true, 'OK', ['filename' => $result['filename']]);

    } catch (Exception $e) {
        if (isset($result['pdfPath'])) {
            $result['engine']->cleanup($result['pdfPath']);
        }
        resultInfo(false, 'SFTP_ERROR', $e->getMessage());
    }
}

/**
 * Sendet HU-Benachrichtigungen per WhatsApp API an ausgewaehlte Kunden.
 *
 * Nutzt das genehmigte HU-Template und die Meta Cloud API.
 * Parameter: {{1}}=Name, {{2}}=Kennzeichen, {{3}}=HU-Datum
 *
 * @param array $data['customer_ids'] Array von Kunden-IDs
 * @testdata {"customer_ids": [1]}
 */
function sendHuWhatsAppBulk($data) {
    permit('sales_order_edit');

    require_once __DIR__.'/../whatsapp/whatsapp.php';

    $customerIds = $data['customer_ids'] ?? [];
    if (empty($customerIds)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Keine Kunden ausgewählt');
        return;
    }

    $customerIds = array_map('intval', $customerIds);
    $idList = implode(',', $customerIds);

    $db = DbhCompany::begin();
    $config = _getWhatsAppConfig();

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    $countryCode = $config['whatsapp_country_code'] ?? '49';

    if (empty($accessToken) || empty($phoneNumberId)) {
        resultInfo(false, 'WHATSAPP_NOT_CONFIGURED', 'WhatsApp Business API ist nicht konfiguriert');
        return;
    }

    // Genehmigtes HU-Template finden
    $template = $db->getOne(
        "SELECT id, name, body_text FROM whatsapp_templates
         WHERE template_type = 'hu' AND status = 'approved'
         ORDER BY is_default DESC, id ASC LIMIT 1"
    );

    if (!$template) {
        resultInfo(false, 'NO_TEMPLATE', 'Kein genehmigtes HU-Template vorhanden');
        return;
    }

    // Kunden mit Fahrzeugdaten laden
    $rows = $db->getAll(
        "SELECT car.c_id, car.c_ln, car.c_hu,
                c.id AS customer_id, c.name AS customer_name, c.phone
         FROM cars_lxcars car
         JOIN customer c ON c.id = car.c_ow
         WHERE car.c_hu IS NOT NULL
           AND c.id IN ($idList)
           AND c.phone IS NOT NULL AND c.phone != ''
           AND (car.c_hu_notify IS NULL OR car.c_hu_notify = true)
         ORDER BY c.name, car.c_hu"
    );

    $sent = 0;
    $failed = 0;
    $skipped = 0;

    // Gruppiere nach Kunde (nur erstes Fahrzeug pro Kunde senden)
    $processed = [];
    foreach ($rows as $row) {
        $cid = (int)$row['customer_id'];
        if (isset($processed[$cid])) continue;
        $processed[$cid] = true;

        $phone = _normalizePhone($row['phone'], $countryCode);
        if (empty($phone)) {
            $skipped++;
            continue;
        }

        $huFormatted = date('d.m.Y', strtotime($row['c_hu']));
        $parameters = [$row['customer_name'], $row['c_ln'] ?? '', $huFormatted];

        $sendResult = _sendTemplateMessageInternal($phone, $template, $parameters, $cid);

        // Log-Eintrag
        $db->execute(
            "INSERT INTO whatsapp_reminder_log (reminder_type, car_id, customer_id, phone_number, template_id, wa_message_id, status)
             VALUES ('hu', :car_id, :customer_id, :phone, :tpl_id, :wa_id, :status)",
            [
                ':car_id' => (int)$row['c_id'],
                ':customer_id' => $cid,
                ':phone' => $phone,
                ':tpl_id' => (int)$template['id'],
                ':wa_id' => $sendResult['wa_message_id'] ?? null,
                ':status' => $sendResult['success'] ? 'sent' : 'failed'
            ]
        );

        if ($sendResult['success']) {
            $sent++;
        } else {
            $failed++;
        }
    }

    resultInfo(true, '', ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped]);
}
