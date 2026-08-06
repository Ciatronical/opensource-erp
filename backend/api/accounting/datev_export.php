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
               c.name AS customer_name, c.customernumber,
               -- Gescannter Beleg zur Buchung: Dateiname im Export-Paket,
               -- Pruefsumme und Ablageort auf der Platte
               d.id AS document_id, d.original_name, d.mime_type,
               d.file_hash AS document_hash, d.stored_path
        FROM accounting_bookings b
        LEFT JOIN vendor v ON v.id = b.vendor_id
        LEFT JOIN customer c ON c.id = b.customer_id
        LEFT JOIN accounting_documents d ON d.id = b.document_id
        WHERE b.status = 'approved'
        AND b.booking_date >= :from_date
        AND b.booking_date <= :to_date
        ORDER BY b.booking_date ASC, b.id ASC
    SQL, [':from_date' => $fromDate, ':to_date' => $toDate]);

    // Belegdateinamen fuer das Export-Paket festlegen: sprechend und eindeutig,
    // damit der Steuerberater den Scan ohne Umweg der Buchung zuordnen kann.
    foreach ($bookings as &$b) {
        $b['document_name'] = _datevBelegDateiname($b);
    }
    unset($b);

    if ($format === 'preview') {
        resultInfo(true, '', [
            'bookings'      => $bookings ?: [],
            'count'         => count($bookings),
            'datev_config'  => $datevConfig,
            'company'       => $defaults['company'] ?? '',
            'period'        => $fromDate . ' - ' . $toDate,
            // Wie viele der Buchungen haben einen Scan? Das entscheidet, ob sich
            // der Paket-Export ueberhaupt lohnt.
            'with_document' => count(array_filter($bookings, fn($x) => !empty($x['document_id']))),
        ]);
        return;
    }

    // Vollstaendiges Paket: CSV + alle Scans + Belegliste (GoBD / Steuerpruefung)
    if ($format === 'package') {
        _exportDatevPackage($db, $bookings, $datevConfig, $defaults, $fromDate, $toDate);
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
 * Sprechender Dateiname des Belegs im Export-Paket.
 *
 * Aufbau: <Belegnummer>_<Rechnungsnummer>.<Endung> — so laesst sich der Scan
 * ohne Nachschlagen der Buchung zuordnen. Sonderzeichen werden ersetzt, damit
 * die Datei auf jedem System und in jedem ZIP-Programm lesbar bleibt.
 */
function _datevBelegDateiname(array $b): string {
    if (empty($b['document_id'])) return '';

    $endung = match ($b['mime_type'] ?? '') {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        default      => 'pdf',
    };

    $teile = array_filter([$b['reference'] ?? '', $b['invoice_number'] ?? '']);
    $name  = $teile ? implode('_', $teile) : ('Beleg_' . $b['document_id']);
    $name  = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $name  = trim($name, '-_');

    return ($name !== '' ? $name : 'Beleg_' . $b['document_id']) . '.' . $endung;
}

/**
 * Export-Paket als ZIP: Buchungsstapel + alle gescannten Belege + Belegliste.
 *
 * Das reine DATEV-CSV enthaelt nur Zahlen. Fuer den Steuerberater und erst recht
 * fuer eine Betriebspruefung (Datentraegerueberlassung) muessen die Belegbilder
 * mitgeliefert werden und einer Buchung eindeutig zuzuordnen sein. Genau das
 * leistet dieses Paket:
 *
 *   Buchungsstapel.csv   DATEV-Format 700, importierbar
 *   Belege/…             die Scans, benannt nach Beleg- und Rechnungsnummer
 *   Belegliste.csv       Zuordnung Buchung <-> Datei inkl. SHA-256
 *   LIESMICH.txt         Inhalt und Pruefanleitung im Klartext
 */
function _exportDatevPackage($db, array $bookings, $datevConfig, $defaults, string $fromDate, string $toDate): void {
    require_once __DIR__.'/../customer_vendor/filemanager.php';

    $csv      = _generateDatevCsv($bookings, $datevConfig, $defaults, $fromDate, $toDate);
    $basis    = rtrim(fmDataDir(), '/');
    $zipDatei = tempnam(sys_get_temp_dir(), 'datev_') . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($zipDatei, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new ApiError('EXPORT_FAILED', 'Export-Paket konnte nicht erstellt werden.');
    }

    $zip->addFromString('Buchungsstapel.csv', "\xEF\xBB\xBF" . $csv);

    // Belegliste: die Bruecke zwischen Buchung und Datei
    $liste = ["Belegnummer;Rechnungsnummer;Buchungsdatum;Betrag;Sollkonto;Habenkonto;Belegdatei;SHA-256;Status"];
    $dabei = 0;
    $fehlend = [];

    foreach ($bookings as $b) {
        $datei  = $b['document_name'] ?? '';
        $status = 'ohne Beleg';

        if ($datei !== '' && !empty($b['stored_path'])) {
            $pfad = $basis . '/' . ltrim($b['stored_path'], '/');
            if (is_readable($pfad)) {
                // Gleicher Dateiname zweimal? Dann durchnummerieren, sonst
                // ueberschreibt der zweite Beleg den ersten im Archiv.
                $ziel = 'Belege/' . $datei;
                $n = 2;
                while ($zip->locateName($ziel) !== false) {
                    $ziel = 'Belege/' . pathinfo($datei, PATHINFO_FILENAME) . '_' . $n++ . '.' . pathinfo($datei, PATHINFO_EXTENSION);
                }
                $zip->addFile($pfad, $ziel);
                $status = 'beigefuegt';
                $dabei++;
            } else {
                $status  = 'Datei fehlt';
                $fehlend[] = $b['reference'] . ' (' . $b['stored_path'] . ')';
            }
        }

        $liste[] = implode(';', [
            _datevQuote($b['reference'] ?? ''),
            _datevQuote($b['invoice_number'] ?? ''),
            _datevQuote(date('d.m.Y', strtotime($b['booking_date']))),
            number_format(abs(floatval($b['amount'])), 2, ',', ''),
            _datevQuote($b['debit_account'] ?? ''),
            _datevQuote($b['credit_account'] ?? ''),
            _datevQuote($status === 'beigefuegt' ? $datei : ''),
            _datevQuote($b['document_hash'] ?? ''),
            _datevQuote($status),
        ]);
    }

    $zip->addFromString('Belegliste.csv', "\xEF\xBB\xBF" . implode("\r\n", $liste));

    $hinweis  = "Buchhaltungs-Export " . $defaults['company'] . "\r\n";
    $hinweis .= "Zeitraum: " . date('d.m.Y', strtotime($fromDate)) . " bis " . date('d.m.Y', strtotime($toDate)) . "\r\n";
    $hinweis .= "Erstellt am: " . date('d.m.Y H:i') . "\r\n";
    $hinweis .= "Steuernummer: " . ($defaults['taxnumber'] ?? '') . "   USt-IdNr.: " . ($defaults['co_ustid'] ?? '') . "\r\n";
    $hinweis .= "Kontenrahmen: " . ($defaults['coa'] ?? '') . "\r\n\r\n";
    $hinweis .= "INHALT\r\n";
    $hinweis .= "  Buchungsstapel.csv  Buchungen im DATEV-Format (EXTF 700), direkt importierbar\r\n";
    $hinweis .= "  Belege/             " . $dabei . " gescannte Belege zu " . count($bookings) . " Buchungen\r\n";
    $hinweis .= "  Belegliste.csv      Zuordnung Buchung <-> Belegdatei mit Pruefsumme\r\n\r\n";
    $hinweis .= "ZUORDNUNG\r\n";
    $hinweis .= "  Im Buchungsstapel steht der Dateiname in 'Beleginfo - Inhalt 1',\r\n";
    $hinweis .= "  die ersten 16 Stellen der Pruefsumme in 'Beleginfo - Inhalt 2'.\r\n";
    $hinweis .= "  Die vollstaendige Pruefsumme steht in der Belegliste.\r\n\r\n";
    $hinweis .= "UNVERAENDERTHEIT PRUEFEN\r\n";
    $hinweis .= "  Der SHA-256 wird beim Einscannen gebildet und mitgefuehrt.\r\n";
    $hinweis .= "  Pruefung einer Datei:  sha256sum \"Belege/<Dateiname>\"\r\n";
    $hinweis .= "  Der Wert muss mit der Spalte SHA-256 der Belegliste uebereinstimmen.\r\n";
    if ($fehlend) {
        $hinweis .= "\r\nWARNUNG: zu folgenden Buchungen fehlt die Belegdatei auf dem Server:\r\n";
        foreach ($fehlend as $f) $hinweis .= '  ' . $f . "\r\n";
    }
    $zip->addFromString('LIESMICH.txt', $hinweis);

    $zip->close();

    $dateiname = 'Buchhaltung_' . str_replace('-', '', $fromDate) . '_' . str_replace('-', '', $toDate) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $dateiname . '"');
    header('Content-Length: ' . filesize($zipDatei));
    readfile($zipDatei);
    unlink($zipDatei);

    // Erst nach erfolgreicher Auslieferung als uebergeben markieren
    foreach ($bookings as $b) {
        $db->execute(
            "UPDATE accounting_bookings SET status = 'booked', mtime = NOW() WHERE id = :id",
            [':id' => $b['id']]
        );
    }

    exit;
}

/**
 * Spaltendefinition des DATEV-Buchungsstapels (Formatversion 700).
 *
 * EINE Quelle fuer Kopfzeile UND Datenzeilen. Vorher wurden beide getrennt
 * gepflegt und liefen auseinander: die Kopfzeile hatte 120 Spalten, die
 * Datenzeilen 116 Felder. DATEV ordnet die Werte dann falschen Spalten zu
 * bzw. weist den Stapel beim Import zurueck.
 *
 * Positionen laut DATEV-Dokumentation: 7 Konto, 8 Gegenkonto, 20 Beleglink,
 * 21/22 Beleginfo 1, 114 Festschreibung.
 *
 * @return string[] 125 Spaltenbezeichnungen in fester Reihenfolge
 */
function _datevColumns(): array {
    $beleginfo = [];
    for ($i = 1; $i <= 8; $i++) {
        $beleginfo[] = "Beleginfo - Art {$i}";
        $beleginfo[] = "Beleginfo - Inhalt {$i}";
    }
    $zusatz = [];
    for ($i = 1; $i <= 20; $i++) {
        $zusatz[] = "Zusatzinformation - Art {$i}";
        $zusatz[] = "Zusatzinformation - Inhalt {$i}";
    }

    return array_merge([
        'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs',
        'Basis-Umsatz', 'WKZ Basis-Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schluessel)',
        'BU-Schluessel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext',
        'Postensperre', 'Diverse Adressnummer', 'Geschaeftspartnerbank', 'Sachverhalt',
        'Zinssperre', 'Beleglink',
    ], $beleginfo, [
        'KOST1 - Kostenstelle', 'KOST2 - Kostenstelle', 'Kost-Menge', 'EU-Land u. UStID',
        'EU-Steuersatz', 'Abw. Versteuerungsart', 'Sachverhalt L+L', 'Funktionsergaenzung L+L',
        'BU 49 Hauptfunktionstyp', 'BU 49 Hauptfunktionsnummer', 'BU 49 Funktionsergaenzung',
    ], $zusatz, [
        'Stueck', 'Gewicht', 'Zahlweise', 'Forderungsart', 'Veranlagungsjahr',
        'Zugeordnete Faelligkeit', 'Skontotyp', 'Auftragsnummer', 'Buchungstyp',
        'USt-Schluessel (Anzahlungen)', 'EU-Land (Anzahlungen)', 'Sachverhalt L+L (Anzahlungen)',
        'EU-Steuersatz (Anzahlungen)', 'Erloeskonto (Anzahlungen)', 'Herkunft-Kz',
        'Buchungs GUID', 'KOST-Datum', 'SEPA-Mandatsreferenz', 'Skontosperre',
        'Gesellschaftername', 'Beteiligtennummer', 'Identifikationsnummer', 'Zeichnernummer',
        'Postensperre bis', 'Bezeichnung SoBil-Sachverhalt', 'Kennzeichen SoBil-Buchung',
        'Festschreibung', 'Leistungsdatum', 'Datum Zuord. Steuerperiode', 'Faelligkeit',
        'Generalumkehr (GU)', 'Steuersatz', 'Land',
        'Abrechnungsreferenz', 'BVV-Position', 'EU-Mitgliedstaat u. UStID (Ursprung)',
        'EU-Steuersatz (Ursprung)', 'Abw. Skontokonto',
    ]);
}

/**
 * Wert DATEV-konform in Anfuehrungszeichen setzen (Textfelder).
 */
function _datevQuote($value): string {
    return '"' . str_replace('"', '""', (string)$value) . '"';
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

    // Header-Zeile 2 (Spaltenbezeichnungen) — aus derselben Definition wie die Daten
    $columns     = _datevColumns();
    $spaltenzahl = count($columns);
    $header2     = implode(';', array_map('_datevQuote', $columns));

    $lines = [$header1, $header2];

    $idx = array_flip($columns);   // Spaltenname -> Position

    foreach ($bookings as $b) {
        // Jede Zeile hat garantiert so viele Felder wie die Kopfzeile
        $f = array_fill(0, $spaltenzahl, '');
        $set = function (string $name, string $value) use (&$f, $idx) {
            if (isset($idx[$name])) $f[$idx[$name]] = $value;
        };

        // Soll/Haben: Bei Eingangsrechnungen steht das Aufwandskonto im Soll
        $sollHaben    = $b['type'] === 'incoming' ? 'S' : 'H';
        $buSchluessel = $b['tax_key'] ? str_pad($b['tax_key'], 2, '0', STR_PAD_LEFT) : '';

        $set('Umsatz (ohne Soll/Haben-Kz)',      number_format(abs(floatval($b['amount'])), 2, ',', ''));
        $set('Soll/Haben-Kennzeichen',           _datevQuote($sollHaben));
        $set('WKZ Umsatz',                       _datevQuote('EUR'));
        $set('Konto',                            (string)$b['debit_account']);
        $set('Gegenkonto (ohne BU-Schluessel)',  (string)$b['credit_account']);
        $set('BU-Schluessel',                    $buSchluessel);
        $set('Belegdatum',                       date('dm', strtotime($b['booking_date'])));  // TTMM
        $set('Belegfeld 1',                      _datevQuote($b['invoice_number'] ?? ''));
        $set('Belegfeld 2',                      _datevQuote($b['reference'] ?? ''));
        $set('Buchungstext',                     _datevQuote(mb_substr($b['description'] ?? '', 0, 60)));
        if (!empty($b['invoice_date'])) {
            $set('Leistungsdatum', date('Ymd', strtotime($b['invoice_date'])));
        }

        // Beleginfo: Verweis auf den Scan im Export-Paket. Der Steuerberater sieht
        // damit zu jeder Buchung, welche Datei dazugehoert; der SHA-256 belegt bei
        // einer Pruefung, dass sie nicht veraendert wurde.
        // Das DATEV-Feld "Beleglink" bleibt bewusst leer: es erwartet das Format
        // BEDI "GUID" von DATEV Unternehmen online und wuerde sonst beanstandet.
        if (!empty($b['document_name'])) {
            $set('Beleginfo - Art 1',    _datevQuote('Belegdatei'));
            $set('Beleginfo - Inhalt 1', _datevQuote($b['document_name']));
        }
        if (!empty($b['document_hash'])) {
            $set('Beleginfo - Art 2',    _datevQuote('SHA256'));
            $set('Beleginfo - Inhalt 2', _datevQuote(substr($b['document_hash'], 0, 16)));
        }

        $lines[] = implode(';', $f);
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
