<?php
// backend/api/accounting/datev_export.php

/**
 * DATEV-Export generieren (CSV im DATEV-Format)
 *
 * Erzeugt eine CSV-Datei nach DATEV-Spezifikation fuer den Buchungsstapel.
 * Nur freigegebene Buchungen (status = 'approved') werden exportiert.
 *
 * @param string $data['from_date']  Von-Datum (YYYY-MM-DD)
 * @param string $data['to_date']    Bis-Datum (YYYY-MM-DD)
 * @param string $data['format']     Export-Format: 'datev_csv' oder 'preview' (Standard: preview)
 * @testdata {"from_date": "2026-01-01", "to_date": "2026-12-31", "format": "preview"}
 */
function exportDatev($data) {
    $db = DbhCompany::begin();

    $fromDate = $data['from_date'] ?? date('Y-01-01');
    $toDate = $data['to_date'] ?? date('Y-12-31');
    $format = $data['format'] ?? 'preview';

    // DATEV-Konfiguration laden
    $datevConfig = $db->getOne("SELECT * FROM datev LIMIT 1", []);
    $defaults = $db->getOne("SELECT company, taxnumber, co_ustid, coa FROM defaults LIMIT 1", []);

    // Freigegebene Buchungen laden
    $bookings = $db->getAll(<<<SQL
        SELECT b.id, b.booking_date, b.invoice_date, b.amount, b.net_amount,
               b.tax_amount, b.tax_rate, b.tax_key,
               b.debit_account, b.credit_account,
               b.invoice_number, b.description, b.reference,
               b.type, b.cost_center,
               v.name AS vendor_name, v.vendornumber,
               c.name AS customer_name, c.customernumber
        FROM accounting_bookings b
        LEFT JOIN vendor v ON v.id = b.vendor_id
        LEFT JOIN customer c ON c.id = b.customer_id
        WHERE b.status = 'approved'
        AND b.booking_date >= :from_date
        AND b.booking_date <= :to_date
        ORDER BY b.booking_date ASC, b.id ASC
    SQL, [':from_date' => $fromDate, ':to_date' => $toDate]);

    if ($format === 'preview') {
        resultInfo(true, '', [
            'bookings'     => $bookings ?: [],
            'count'        => count($bookings),
            'datev_config' => $datevConfig,
            'company'      => $defaults['company'] ?? '',
            'period'       => $fromDate . ' - ' . $toDate
        ]);
        return;
    }

    // DATEV-CSV generieren
    $csv = _generateDatevCsv($bookings, $datevConfig, $defaults, $fromDate, $toDate);

    // Als Datei zurueckgeben
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="DATEV_' . date('Ymd') . '_' . str_replace('-', '', $fromDate) . '_' . str_replace('-', '', $toDate) . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM fuer Excel
    echo $csv;

    // Buchungen als "booked" markieren
    foreach ($bookings as $b) {
        $db->execute(
            "UPDATE accounting_bookings SET status = 'booked', mtime = NOW() WHERE id = :id",
            [':id' => $b['id']]
        );
    }

    exit;
}

/**
 * DATEV-CSV im Standardformat generieren
 *
 * Format: DATEV-Buchungsstapel (Version 700)
 * Spalten gem. DATEV-Dokumentation
 */
function _generateDatevCsv($bookings, $datevConfig, $defaults, $fromDate, $toDate) {
    $beraterNr = $datevConfig['beraternr'] ?? '0001';
    $mandantenNr = $datevConfig['mandantennr'] ?? '00001';
    $wjBeginn = date('Y') . '0101';    // Wirtschaftsjahrbeginn
    $sachkontenlaenge = 4;               // SKR03/04 Standard
    $coa = $defaults['coa'] ?? 'Germany-DATEV-SKR03';

    // Kontenrahmen-Laenge bestimmen
    if (strpos($coa, 'SKR04') !== false) {
        $sachkontenlaenge = 4;
    }

    // Header-Zeile 1 (Metadaten)
    $header1 = implode(';', [
        '"EXTF"',           // Datei-Typ
        '700',              // Version
        '21',               // Datenkategorie (Buchungsstapel)
        '"Buchungsstapel"', // Bezeichnung
        '13',               // Versionsnummer Format
        date('YmdHisv'),    // Erstellungsdatum
        '',                 // Importiert
        '"RE"',             // Herkunftskennzeichen
        '""',               // Exportiert von
        '""',               // Importiert von
        '"' . $beraterNr . '"',     // Beraternummer
        '"' . $mandantenNr . '"',   // Mandantennummer
        $wjBeginn,          // WJ-Beginn
        $sachkontenlaenge,  // Sachkontennummernlaenge
        str_replace('-', '', $fromDate),  // Datum von
        str_replace('-', '', $toDate),    // Datum bis
        '""',               // Bezeichnung
        '""',               // Diktatkuerzel
        '1',                // Buchungstyp (1=Fibu)
        '0',                // Rechnungslegungszweck
        '0',                // Festschreibung
        '"EUR"'             // Waehrungskennzeichen
    ]);

    // Header-Zeile 2 (Spaltenbezeichnungen)
    $header2 = implode(';', [
        '"Umsatz (ohne Soll/Haben-Kz)"',
        '"Soll/Haben-Kennzeichen"',
        '"WKZ Umsatz"',
        '"Kurs"',
        '"Basis-Umsatz"',
        '"WKZ Basis-Umsatz"',
        '"Konto"',
        '"Gegenkonto (ohne BU-Schluessel)"',
        '"BU-Schluessel"',
        '"Belegdatum"',
        '"Belegfeld 1"',
        '"Belegfeld 2"',
        '"Skonto"',
        '"Buchungstext"',
        '"Postensperre"',
        '"Diverse Adressnummer"',
        '"Geschaeftspartnerbank"',
        '"Sachverhalt"',
        '"Zinssperre"',
        '"Beleglink"',
        '"Beleginfo - Art 1"',
        '"Beleginfo - Inhalt 1"',
        '"Beleginfo - Art 2"',
        '"Beleginfo - Inhalt 2"',
        '"Beleginfo - Art 3"',
        '"Beleginfo - Inhalt 3"',
        '"Beleginfo - Art 4"',
        '"Beleginfo - Inhalt 4"',
        '"Beleginfo - Art 5"',
        '"Beleginfo - Inhalt 5"',
        '"Beleginfo - Art 6"',
        '"Beleginfo - Inhalt 6"',
        '"Beleginfo - Art 7"',
        '"Beleginfo - Inhalt 7"',
        '"Beleginfo - Art 8"',
        '"Beleginfo - Inhalt 8"',
        '"KOST1 - Kostenstelle"',
        '"KOST2 - Kostenstelle"',
        '"Kost-Menge"',
        '"EU-Land u. UStID"',
        '"EU-Steuersatz"',
        '"Abw. Versteuerungsart"',
        '"Sachverhalt L+L"',
        '"Funktionsergaenzung L+L"',
        '"BU 49 Hauptfunktionstyp"',
        '"BU 49 Hauptfunktionsnummer"',
        '"BU 49 Funktionsergaenzung"',
        '"Zusatzinformation - Art 1"',
        '"Zusatzinformation - Inhalt 1"',
        '"Zusatzinformation - Art 2"',
        '"Zusatzinformation - Inhalt 2"',
        '"Zusatzinformation - Art 3"',
        '"Zusatzinformation - Inhalt 3"',
        '"Zusatzinformation - Art 4"',
        '"Zusatzinformation - Inhalt 4"',
        '"Zusatzinformation - Art 5"',
        '"Zusatzinformation - Inhalt 5"',
        '"Zusatzinformation - Art 6"',
        '"Zusatzinformation - Inhalt 6"',
        '"Zusatzinformation - Art 7"',
        '"Zusatzinformation - Inhalt 7"',
        '"Zusatzinformation - Art 8"',
        '"Zusatzinformation - Inhalt 8"',
        '"Zusatzinformation - Art 9"',
        '"Zusatzinformation - Inhalt 9"',
        '"Zusatzinformation - Art 10"',
        '"Zusatzinformation - Inhalt 10"',
        '"Zusatzinformation - Art 11"',
        '"Zusatzinformation - Inhalt 11"',
        '"Zusatzinformation - Art 12"',
        '"Zusatzinformation - Inhalt 12"',
        '"Zusatzinformation - Art 13"',
        '"Zusatzinformation - Inhalt 13"',
        '"Zusatzinformation - Art 14"',
        '"Zusatzinformation - Inhalt 14"',
        '"Zusatzinformation - Art 15"',
        '"Zusatzinformation - Inhalt 15"',
        '"Zusatzinformation - Art 16"',
        '"Zusatzinformation - Inhalt 16"',
        '"Zusatzinformation - Art 17"',
        '"Zusatzinformation - Inhalt 17"',
        '"Zusatzinformation - Art 18"',
        '"Zusatzinformation - Inhalt 18"',
        '"Zusatzinformation - Art 19"',
        '"Zusatzinformation - Inhalt 19"',
        '"Zusatzinformation - Art 20"',
        '"Zusatzinformation - Inhalt 20"',
        '"Stueck"',
        '"Gewicht"',
        '"Zahlweise"',
        '"Forderungsart"',
        '"Veranlagungsjahr"',
        '"Zugeordnete Faelligkeit"',
        '"Skontotyp"',
        '"Auftragsnummer"',
        '"Buchungstyp"',
        '"USt-Schluessel (Anzahlungen)"',
        '"EU-Land (Anzahlungen)"',
        '"Sachverhalt L+L (Anzahlungen)"',
        '"EU-Steuersatz (Anzahlungen)"',
        '"Erloeskonto (Anzahlungen)"',
        '"Herkunft-Kz"',
        '"Buchungs GUID"',
        '"KOST-Datum"',
        '"SEPA-Mandatsreferenz"',
        '"Skontosperre"',
        '"Gesellschaftername"',
        '"Beteiligtennummer"',
        '"Identifikationsnummer"',
        '"Zeichnernummer"',
        '"Postensperre bis"',
        '"Bezeichnung SoBil-Sachverhalt"',
        '"Kennzeichen SoBil-Buchung"',
        '"Festschreibung"',
        '"Leistungsdatum"',
        '"Datum Zuord. Steuerperiode"',
        '"Faelligkeit"',
        '"Generalumkehr (GU)"',
        '"Steuersatz"',
        '"Land"'
    ]);

    $lines = [$header1, $header2];

    foreach ($bookings as $b) {
        $amount = number_format(abs(floatval($b['amount'])), 2, ',', '');

        // Soll/Haben: S=Soll, H=Haben
        // Bei Eingangsrechnungen: Aufwandskonto im Soll, Verbindlichkeiten im Haben
        $sollHaben = $b['type'] === 'incoming' ? 'S' : 'H';

        $belegDatum = date('dm', strtotime($b['booking_date'])); // TTMM
        $buSchluessel = $b['tax_key'] ? str_pad($b['tax_key'], 2, '0', STR_PAD_LEFT) : '';

        // Buchungstext (max 60 Zeichen)
        $buchungstext = mb_substr($b['description'] ?? '', 0, 60);

        $row = implode(';', [
            $amount,                                    // Umsatz
            '"' . $sollHaben . '"',                    // Soll/Haben-Kz
            '"EUR"',                                   // WKZ
            '',                                        // Kurs
            '',                                        // Basis-Umsatz
            '',                                        // WKZ Basis-Umsatz
            $b['debit_account'],                       // Konto
            $b['credit_account'],                      // Gegenkonto
            $buSchluessel,                             // BU-Schluessel
            $belegDatum,                               // Belegdatum (TTMM)
            '"' . str_replace('"', '""', $b['invoice_number'] ?? '') . '"', // Belegfeld 1
            '"' . str_replace('"', '""', $b['reference'] ?? '') . '"',      // Belegfeld 2
            '',                                        // Skonto
            '"' . str_replace('"', '""', $buchungstext) . '"', // Buchungstext
        ]);

        // Restliche Felder leer auffuellen
        $emptyFields = str_repeat(';', 102); // Verbleibende Felder
        $row .= $emptyFields;

        $lines[] = $row;
    }

    return implode("\r\n", $lines);
}

/**
 * DATEV-Konfiguration laden
 *
 * @testdata {}
 */
function getDatevConfig($data) {
    $db = DbhCompany::begin();

    $config = $db->getOne("SELECT * FROM datev LIMIT 1", []);
    $defaults = $db->getOne("SELECT company, taxnumber, co_ustid, coa FROM defaults LIMIT 1", []);

    resultInfo(true, '', [
        'datev'    => $config ?: [],
        'defaults' => $defaults ?: []
    ]);
}

/**
 * DATEV-Konfiguration speichern
 *
 * @param string $data['beraternr']      Beraternummer
 * @param string $data['mandantennr']    Mandantennummer
 * @param string $data['beratername']    Beratername
 * @param string $data['datentraegernr'] Datentraegernummer
 * @testdata {"beraternr": "1234567", "mandantennr": "12345"}
 */
function saveDatevConfig($data) {
    $db = DbhCompany::begin();

    $db->execute(<<<SQL
        INSERT INTO datev (id, beraternr, mandantennr, beratername, datentraegernr, abrechnungsnr)
        VALUES (1, :bnr, :mnr, :bname, :dtnr, :anr)
        ON CONFLICT (id) DO UPDATE SET
            beraternr = :bnr, mandantennr = :mnr, beratername = :bname,
            datentraegernr = :dtnr, abrechnungsnr = :anr, mtime = NOW()
    SQL, [
        ':bnr'   => $data['beraternr'] ?? '',
        ':mnr'   => $data['mandantennr'] ?? '',
        ':bname' => $data['beratername'] ?? '',
        ':dtnr'  => $data['datentraegernr'] ?? '',
        ':anr'   => $data['abrechnungsnr'] ?? ''
    ]);

    resultInfo(true, 'DATEV-Konfiguration gespeichert');
}
