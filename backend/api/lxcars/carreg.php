<?php
// backend/api/lxcars/carreg.php

require_once __DIR__.'/../../lib/fpdf/fpdf.php';
require_once __DIR__.'/../../lib/fpdi/src/autoload.php';

use setasign\Fpdi\Fpdi;

/**
 * Lädt Fahrzeug- und Kundendaten für das Zulassungsformular
 *
 * @param int $data['c_id'] Fahrzeug-ID
 * @testdata {"c_id": 1}
 */
function getCarregData($data) {
    $db = DbhCompany::begin();
    $cId = intval($data['c_id'] ?? 0);

    if ($cId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'c_id ist erforderlich');
        return;
    }

    $row = $db->getOne(
        "SELECT c.c_ln, c.c_fin,
                cu.name, cu.street, cu.zipcode, cu.city,
                cu.iban, cu.bic, cu.bank
         FROM cars_lxcars c
         JOIN customer cu ON cu.id = c.c_ow
         WHERE c.c_id = :c_id",
        [':c_id' => $cId]
    );

    if (!$row) {
        resultInfo(false, 'NOT_FOUND', 'Fahrzeug nicht gefunden');
        return;
    }

    // Name in Vorname/Nachname parsen
    $nameParts = explode(' ', trim($row['name'] ?? ''), 2);
    $vorname = '';
    $nachname = '';
    if (count($nameParts) === 2) {
        $vorname = $nameParts[0];
        $nachname = $nameParts[1];
    } else {
        $nachname = $nameParts[0] ?? '';
    }

    // Strasse in Strasse/Hausnummer parsen
    $strasse = '';
    $hsnr = '';
    $street = trim($row['street'] ?? '');
    if (preg_match('/^(.+?)\s+(\d+\s*[a-zA-Z]?)$/', $street, $m)) {
        $strasse = $m[1];
        $hsnr = $m[2];
    } else {
        $strasse = $street;
    }

    resultInfo(true, 'OK', [
        'kennzeichen' => $row['c_ln'] ?? '',
        'fin'         => $row['c_fin'] ?? '',
        'vorname'     => $vorname,
        'name'        => $nachname,
        'strasse'     => $strasse,
        'hsnr'        => $hsnr,
        'plz'         => $row['zipcode'] ?? '',
        'ort'         => $row['city'] ?? '',
        'iban'        => $row['iban'] ?? '',
        'bic'         => $row['bic'] ?? '',
        'bank'        => $row['bank'] ?? ''
    ]);
}

/**
 * Erzeugt das Zulassungs-PDF mit FPDI
 *
 * @param string $data['auftragsart'] Auftragsart (zulassung|umschreibung|abmeldung|aenderung|ersatz)
 * @param string $data['kennzeichen'] Kennzeichen
 * @param string $data['name'] Nachname
 * @param string $data['vorname'] Vorname
 * @testdata {"auftragsart": "zulassung", "kennzeichen": "MOL-XX123", "name": "Mustermann", "vorname": "Max"}
 */
function generateCarregPdf($data) {
    $vorlagenPfad = __DIR__.'/../../templates/lxcars/vorlage/';

    $pdf = new Fpdi();

    // --- Seite 1: Antrag auf Zulassung/Umschreibung ---
    $pdf->AddPage();
    $pdf->setSourceFile($vorlagenPfad . 'antrag_auf_zulassung_umschreibung.pdf');
    $tplId = $pdf->importPage(1);
    $pdf->useTemplate($tplId);
    $pdf->SetFont('Helvetica', '', 12);
    $height = $pdf->GetPageHeight();

    $auftragsart = $data['auftragsart'] ?? '';
    $get = function($key) use ($data) {
        $val = $data[$key] ?? '';
        if ($val === '') return '';
        return mb_convert_encoding($val, 'ISO-8859-1', 'UTF-8');
    };

    // eVB-Nummer
    if (!empty($data['evb_nummer'])) {
        $pdf->Text(24, $height - 242, 'eVB-Nummer: ' . $get('evb_nummer'));
    }

    // Auftragsart-Kreuze (oben)
    if ($auftragsart === 'zulassung')    $pdf->Text(29, $height - 227, 'X');
    if ($auftragsart === 'umschreibung') $pdf->Text(60, $height - 227, 'X');
    if ($auftragsart === 'abmeldung')    $pdf->Text(101, $height - 227, 'X');
    if ($auftragsart === 'aenderung')    $pdf->Text(136, $height - 227, 'X');
    if ($auftragsart === 'ersatz')       $pdf->Text(167, $height - 227, 'X');

    // Kennzeichen
    if (!empty($data['kennzeichen'])) $pdf->Text(147, $height - 242, $get('kennzeichen'));

    // Firma
    if (!empty($data['firma'])) $pdf->Text(38, $height - 215, $get('firma'));

    // Name, Vorname
    if (!empty($data['name']))    $pdf->Text(38, $height - 207, $get('name'));
    if (!empty($data['vorname'])) $pdf->Text(126, $height - 207, $get('vorname'));

    // Geburtsname
    if (!empty($data['gebname'])) $pdf->Text(53, $height - 199, $get('gebname'));

    // Geburtsdatum, Geburtsort
    if (!empty($data['gebdatum'])) $pdf->Text(53, $height - 190, $get('gebdatum'));
    if (!empty($data['gebort']))   $pdf->Text(126, $height - 190, $get('gebort'));

    // Strasse, Hausnummer
    if (!empty($data['strasse'])) $pdf->Text(40, $height - 182, $get('strasse'));
    if (!empty($data['hsnr']))    $pdf->Text(164, $height - 182, $get('hsnr'));

    // PLZ, Ort
    if (!empty($data['plz'])) $pdf->Text(41, $height - 174, $get('plz'));
    if (!empty($data['ort'])) $pdf->Text(74, $height - 174, $get('ort'));

    // Geschlecht
    $geschlecht = $data['geschlecht'] ?? '';
    if ($geschlecht === 'weiblich')  $pdf->Text(50, $height - 167, 'X');
    if ($geschlecht === 'maennlich') $pdf->Text(77, $height - 167, 'X');
    if ($geschlecht === 'firma')     $pdf->Text(106, $height - 167, 'X');
    // Divers-Kästchen + Text immer sichtbar, Haken nur wenn ausgewählt
    $pdf->Rect(127, $height - 170.5, 5, 5, 'D');
    $pdf->Text(134, $height - 166.5, 'divers');
    if ($geschlecht === 'divers') {
        $pdf->Text(128, $height - 167, 'X');
    }

    // Gewerbe-Anschrift (nur bei Firma)
    if ($geschlecht === 'firma') {
        if (!empty($data['beruf']))           $pdf->Text(75, $height - 158, $get('beruf'));
        if (!empty($data['gewerbe_strasse'])) $pdf->Text(40, $height - 144, $get('gewerbe_strasse'));
        if (!empty($data['gewerbe_hsnr']))    $pdf->Text(164, $height - 144, $get('gewerbe_hsnr'));
        if (!empty($data['gewerbe_plz']))     $pdf->Text(41, $height - 136, $get('gewerbe_plz'));
        if (!empty($data['gewerbe_ort']))     $pdf->Text(74, $height - 136, $get('gewerbe_ort'));
    }

    // Fahrzeug-Identifizierungsnummer
    if (!empty($data['fin'])) $pdf->Text(105, $height - 124, $get('fin'));

    // Auftragsart-Kreuze (unten)
    if ($auftragsart === 'zulassung')    $pdf->Text(28, $height - 67, 'X');
    if ($auftragsart === 'umschreibung') $pdf->Text(54, $height - 67, 'X');
    if ($auftragsart === 'abmeldung')    $pdf->Text(88, $height - 67, 'X');
    if ($auftragsart === 'aenderung')    $pdf->Text(117, $height - 67, 'X');
    if ($auftragsart === 'ersatz')       $pdf->Text(143, $height - 67, 'X');

    // Datum
    $pdf->Text(43, $height - 37, ', ' . date('d.m.Y'));

    // --- Seite 2+3: Versicherung an Eides Statt (nur bei Ersatz) ---
    if ($auftragsart === 'ersatz') {
        // Seite 2: eID Teil 1
        $pdf->AddPage();
        $pdf->setSourceFile($vorlagenPfad . 'versicherung_an_eides_statt_1.pdf');
        $tplId = $pdf->importPage(1);
        $pdf->useTemplate($tplId);
        $height = $pdf->GetPageHeight();
        $pdf->SetFont('Helvetica', '', 10.5);

        $pdf->Text(152, $height - 239.5, 'Rehfelde, ' . date('d.m.Y'));
        if (!empty($data['name']))     $pdf->Text(39, $height - 226.6, $get('name'));
        if (!empty($data['vorname']))  $pdf->Text(100, $height - 226.6, $get('vorname'));
        if (!empty($data['gebdatum'])) $pdf->Text(163, $height - 226.6, $get('gebdatum'));

        $anschrift1 = '';
        $anschrift2 = '';
        if (!empty($data['strasse'])) $anschrift1 = $get('strasse');
        if (!empty($data['hsnr']))    $anschrift1 .= ' ' . $get('hsnr');
        if (!empty($data['plz']))     $anschrift2 = $get('plz');
        if (!empty($data['ort']))     $anschrift2 .= ' ' . $get('ort');

        if (!empty($anschrift1)) $pdf->Text(54, $height - 214, $anschrift1);
        if (!empty($anschrift2)) $pdf->Text(54, $height - 209, $anschrift2);

        if (!empty($data['kennzeichen'])) $pdf->Text(69, $height - 192.6, $get('kennzeichen'));

        // Seite 3: eID Teil 2
        $pdf->AddPage();
        $pdf->setSourceFile($vorlagenPfad . 'versicherung_an_eides_statt_2.pdf');
        $tplId = $pdf->importPage(1);
        $pdf->useTemplate($tplId);
        $height = $pdf->GetPageHeight();
        $pdf->SetFont('Helvetica', '', 10.5);

        if (!empty($data['fahrzeugschein']))      $pdf->Text(30.4, $height - 242.5, 'X');
        if (!empty($data['fahrzeugbrief']))        $pdf->Text(30.4, $height - 238.5, 'X');
        if (!empty($data['amtlicheskenn']))        $pdf->Text(30.4, $height - 234.5, 'X');
        if (!empty($data['roterschein']))          $pdf->Text(30.4, $height - 230, 'X');
        if (!empty($data['fuehrerschein']))        $pdf->Text(30.4, $height - 225.5, 'X');
        if (!empty($data['betriebserlaubnis']))    $pdf->Text(30.4, $height - 221.25, 'X');
        if (!empty($data['sonstiges'])) {
            $pdf->Text(30.4, $height - 217.2, 'X');
            if (!empty($data['sonstiges_text'])) $pdf->Text(59, $height - 217, $get('sonstiges_text'));
        }

        if (!empty($data['erklaerung'])) {
            $pdf->SetXY(25, $height - 197);
            $pdf->MultiCell(160, 4.5, $get('erklaerung'), 0, 'L');
        }
    }

    // --- SEPA-Seite (nur bei Zulassung oder Umschreibung) ---
    if ($auftragsart === 'zulassung' || $auftragsart === 'umschreibung') {
        $pdf->AddPage();
        $pdf->setSourceFile($vorlagenPfad . 'SEPA-Basislastschrift.pdf');
        $tplId = $pdf->importPage(1);
        $pdf->useTemplate($tplId);
        $height = $pdf->GetPageHeight();
        $pdf->SetFont('Helvetica', '', 10);

        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetLineWidth(0.2);

        $mandatsname = '';
        $mandatsstrasse = '';
        $mandatsplz = '';
        $mandatsort = '';

        if (empty($data['mandat_identisch'])) {
            if (!empty($data['mandats_name']))    $mandatsname = $get('mandats_name');
            if (!empty($data['mandats_strasse'])) $mandatsstrasse = $get('mandats_strasse');
            if (!empty($data['mandats_plz']))     $mandatsplz = $get('mandats_plz');
            if (!empty($data['mandats_ort']))     $mandatsort = $get('mandats_ort');
        } else {
            if (!empty($data['vorname'])) $mandatsname = $get('vorname') . ' ';
            if (!empty($data['name']))    $mandatsname .= $get('name');
            if (!empty($data['strasse'])) $mandatsstrasse = $get('strasse') . ' ';
            if (!empty($data['hsnr']))    $mandatsstrasse .= $get('hsnr');
            if (!empty($data['plz']))     $mandatsplz = $get('plz');
            if (!empty($data['ort']))     $mandatsort = $get('ort');
        }

        if (!empty($mandatsname)) {
            $pdf->Rect(36, $height - 194.5, 163, 5, 'F');
            $pdf->Text(34, $height - 191, $mandatsname);
        }
        if (!empty($mandatsstrasse)) {
            $pdf->Rect(36, $height - 184.4, 163, 5, 'F');
            $pdf->Text(34, $height - 181, $mandatsstrasse);
        }
        if (!empty($mandatsplz)) {
            $pdf->Rect(36, $height - 175, 42, 5, 'F');
            $pdf->Text(34, $height - 171, $mandatsplz);
        }
        if (!empty($mandatsort)) {
            $pdf->Rect(86, $height - 175, 113, 5, 'F');
            $pdf->Text(85, $height - 171, $mandatsort);
            $pdf->Text(34, $height - 111, $mandatsort);
        }
        if (!empty($data['mandats_land'])) {
            $pdf->Text(34, $height - 161, $get('mandats_land'));
        }
        if (!empty($data['mandats_iban'])) {
            $pdf->Rect(36, $height - 155, 163, 5.1, 'F');
            $pdf->Text(34, $height - 152, $get('mandats_iban'));
        }
        if (!empty($data['mandats_bic'])) {
            $pdf->Rect(36, $height - 135, 50, 5, 'F');
            $pdf->Text(34, $height - 131, $get('mandats_bic'));
        }
        if (!empty($data['mandats_bank'])) {
            $pdf->Text(94, $height - 131, $get('mandats_bank'));
        }

        $pdf->Rect(94, $height - 115, 38, 5, 'F');
        $pdf->Text(94, $height - 111, date('d.m.Y'));

        // Halter-Name unter dem Ort
        $haltername = '';
        if (!empty($data['vorname'])) $haltername .= $get('vorname') . ' ';
        if (!empty($data['name']))    $haltername .= $get('name');
        if (empty($data['mandat_identisch']) && !empty($haltername)) {
            $pdf->Rect(36, $height - 105, 163, 5, 'F');
            $pdf->Text(34, $height - 101, $haltername);
        } else {
            $pdf->Rect(32, $height - 47, 171.5, 5, 'F');
        }
    }

    // PDF als Base64 zurückgeben
    $pdfContent = $pdf->Output('S', 'Antrag_und_Vollmacht.pdf');

    resultInfo(true, 'OK', [
        'pdf' => base64_encode($pdfContent),
        'filename' => 'Antrag_und_Vollmacht.pdf'
    ]);
}
