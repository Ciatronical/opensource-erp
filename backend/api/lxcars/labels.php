<?php
// backend/api/lxcars/labels.php

/**
 * ZPL-Labeldruck für Fahrzeuge (grüne Plakette, Reifenetiketten)
 *
 * Verwendet den in der printers-Tabelle konfigurierten Labeldrucker.
 * Die Drucker-ID wird als Parameter übergeben.
 */

/**
 * Druckt eine grüne Plakette (gelbes Etikett) mit Kennzeichen
 *
 * @param array $data['c_ln'] Kennzeichen
 * @param array $data['printerId'] Drucker-ID aus printers-Tabelle
 * @testdata {"c_ln": "HH-AB 1234", "printerId": 1}
 */
function printYellowLabel($data) {
    $cLn = trim($data['c_ln'] ?? '');
    $printerId = intval($data['printerId'] ?? 0);

    if (empty($cLn)) {
        echo resultInfo(false, 'VALIDATION_ERROR', 'Kennzeichen fehlt');
        return;
    }
    if (!$printerId) {
        echo resultInfo(false, 'VALIDATION_ERROR', 'Drucker-ID fehlt');
        return;
    }

    $db = DbhCompany::begin();
    $printer = $db->getOne(
        "SELECT printer_command FROM printers WHERE id = :id",
        [':id' => $printerId]
    );
    if (!$printer || empty($printer['printer_command'])) {
        echo resultInfo(false, 'PRINTER_ERROR', 'Drucker nicht gefunden oder nicht konfiguriert');
        return;
    }

    $zpl = "^XA\n";
    $zpl .= "^PON\n";
    $zpl .= "^MD25\n";
    $zpl .= "^FO340,75^A0N,115,115^FD" . $cLn . "^FS\n";
    $zpl .= "^FO340,190^A0N,35,60^FDwww.autoprofis24.de^FS\n";
    $zpl .= "^XZ";

    $dir = __DIR__ . '/../../tmp/labels';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filepath = $dir . '/yellow_label.zpl';
    file_put_contents($filepath, $zpl);

    sleep(2);
    $cmd = $printer['printer_command'] . ' -o raw ' . escapeshellarg($filepath) . ' 2>&1';
    // writeLog($cmd);
    exec($cmd, $output, $returnCode);

    if ($returnCode !== 0) {
        echo resultInfo(false, 'PRINT_ERROR', 'Druckfehler: ' . implode(' ', $output));
        return;
    }

    echo resultInfo(true, '', ['message' => 'Plakette gedruckt']);
}

/**
 * Druckt Reifenetiketten (4 Stück: VR, VL, HR, HL)
 *
 * @param array $data['c_ln'] Kennzeichen
 * @param array $data['name'] Kundenname
 * @param array $data['dim'] Reifengröße (z.B. "205/55R16 91V")
 * @param array $data['location'] Lagerort (z.B. "B1D4")
 * @param array $data['hersteller'] Fahrzeughersteller
 * @param array $data['fhz_typ'] Fahrzeugtyp
 * @param array $data['hubraum'] Hubraum in ccm
 * @param array $data['printerId'] Drucker-ID aus printers-Tabelle
 * @testdata {"c_ln": "HH-AB 1234", "name": "Mustermann", "dim": "205/55R16", "location": "B1D4", "hersteller": "VW", "fhz_typ": "Golf", "hubraum": 1984, "printerId": 1}
 */
function printTyreLabel($data) {
    /*** unbedint drin lassen ************
     * https://labelary.com/viewer.html
     *************************************/
    $cLn       = trim($data['c_ln'] ?? '');
    $name      = trim($data['name'] ?? '');
    $dim       = trim($data['dim'] ?? '');
    $location  = trim($data['location'] ?? '');
    $hersteller = trim($data['hersteller'] ?? '');
    $fhzTyp    = trim($data['fhz_typ'] ?? '');
    $hubraum   = intval($data['hubraum'] ?? 0);
    $printerId = intval($data['printerId'] ?? 0);

    if (empty($cLn)) {
        echo resultInfo(false, 'VALIDATION_ERROR', 'Kennzeichen fehlt');
        return;
    }
    if (!$printerId) {
        echo resultInfo(false, 'VALIDATION_ERROR', 'Drucker-ID fehlt');
        return;
    }

    $db = DbhCompany::begin();
    $printer = $db->getOne(
        "SELECT printer_command FROM printers WHERE id = :id",
        [':id' => $printerId]
    );
    if (!$printer || empty($printer['printer_command'])) {
        echo resultInfo(false, 'PRINTER_ERROR', 'Drucker nicht gefunden oder nicht konfiguriert');
        return;
    }

    $hubraumInLiter = $hubraum > 0 ? round($hubraum / 1000, 1) . 'L' : '';

    $dir = __DIR__ . '/../../tmp/labels';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $wheels = ['VR', 'VL', 'HR', 'HL'];
    foreach ($wheels as $wheel) {
        $zpl = "^XA\n";
        $zpl .= "^PW1200\n";   // Etikettenbreite 100mm = 1200 dots (300dpi) erzwingen – Drucker-Reset beim Umzug hatte PRINT WIDTH auf 648 gesetzt (Inhalt sonst zentriert + rechts abgeschnitten)
        $zpl .= "^LH110,0\n";  // linker Rand ~9mm (110 dots bei 300dpi); y-Ursprung 0
        $zpl .= "^CI28\n";
        $zpl .= "^FO20,200^A0N,190,190^FD" . $cLn . "^FS\n";
        $zpl .= "^FO20,400^A0N,110,110^FD" . $name . "^FS\n";
        $zpl .= "^FO20,600^A0N,180,180^FD" . $dim . "^FS\n";
        $zpl .= "^FO20,850^A0N,100,100^FD" . $location . "^FS\n";
        $zpl .= "^FO20,1000^A0N,80,80^FD" . $hersteller . "^FS\n";
        $zpl .= "^FO20,1100^A0N,80,80^FD" . $fhzTyp . "^FS\n";
        $zpl .= "^FO20,1200^A0N,80,80^FD" . $hubraumInLiter . "^FS\n";
        $zpl .= "^FO600,1350^A0N,350,300^FD" . substr($wheel, 0, 1) . "^FS\n";
        $zpl .= "^FO800,1350^A0N,350,300^FD" . substr($wheel, 1, 1) . "^FS\n";
        $zpl .= "^XZ";

        $filepath = $dir . '/label_' . $wheel . '.zpl';
        file_put_contents($filepath, $zpl);

        $cmd = sprintf('%s %s 2>&1', $printer['printer_command'], escapeshellarg($filepath));
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            echo resultInfo(false, 'PRINT_ERROR', 'Druckfehler bei ' . $wheel . ': ' . implode(' ', $output));
            return;
        }

        // Pause zwischen Labels für Druckerverarbeitung
        if ($wheel !== 'HL') {
            sleep(2);
        }
    }

    echo resultInfo(true, '', ['message' => count($wheels) . ' Reifenetikett(en) gedruckt']);
}
