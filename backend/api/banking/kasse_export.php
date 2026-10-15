<?php
// backend/api/banking/kasse_export.php
//
// Kassenbuch exportieren und versenden.
//
// Das Kassenbuch geht am Monatsende an den Steuerberater — und bei einer
// Prüfung an das Finanzamt. Beide wollen nicht nur den Ausdruck, sondern die
// Buchungen maschinenlesbar und die Belege dazu. Deshalb gibt es hier:
//
//   CSV           das Kassenbuch als Tabelle (Excel, LibreOffice)
//   DATEV-Paket   ZIP mit Buchungsstapel (EXTF 700), Kassenbuch als PDF und
//                 CSV, allen gescannten Belegen, Belegliste mit Prüfsumme und
//                 LIESMICH — das Format des Buchhaltungs-Exports, nur für die
//                 Kasse
//   E-Mail        PDF, CSV und/oder Paket als Anhang, Empfänger wird gemerkt
//
// Alle Varianten gehen von demselben Bericht aus (_kasse_cashbookReport in
// kasse.php); der DATEV-Stapel entsteht über den Generator des
// Buchhaltungs-Exports (accounting/datev_export.php).

require_once __DIR__.'/../accounting/datev_export.php';
require_once __DIR__.'/../email/mailer.php';
require_once __DIR__.'/../customer_vendor/filemanager.php';

/** Ab dieser Anhangsgröße fragt der Versand nach — Mailserver lehnen größere Mails oft ab. */
const KASSE_MAIL_MAX_BYTES = 20 * 1024 * 1024;

/**
 * Gemeinsamer Einstieg: Kassenbuch-ID prüfen, Zeitraum lesen, Bericht laden.
 *
 * @return array|null [db, registerId, from, to, zeitraum, report] oder null
 *                    nach bereits gesendeter Fehlerantwort
 */
function _kasseExport_context($data) {
    $db = DbhCompany::begin();

    $registerId = intval($data['cash_register_id'] ?? 0);
    if ($registerId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Kassenbuch-ID fehlt'); return null; }

    $from = !empty($data['from_date']) ? $data['from_date'] : null;
    $to   = !empty($data['to_date'])   ? $data['to_date']   : null;

    $report = _kasse_cashbookReport($db, $registerId, $from, $to);
    if (!$report) { resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden'); return null; }

    return [
        'db' => $db, 'registerId' => $registerId, 'from' => $from, 'to' => $to,
        'zeitraum' => _kasse_periodLabel($data, $from, $to), 'report' => $report,
    ];
}

/** Zeitraumteil für Dateinamen: 20260101-20260131, 2026 oder gesamt */
function _kasseExport_dateiZeitraum($from, $to) {
    if (!$from && !$to) return 'gesamt';
    $f = $from ? str_replace('-', '', $from) : 'Beginn';
    $t = $to   ? str_replace('-', '', $to)   : 'heute';
    if ($from && $to && substr($from, 5) === '01-01' && substr($to, 5) === '12-31' && substr($from, 0, 4) === substr($to, 0, 4)) {
        return substr($from, 0, 4);
    }
    return $f . '-' . $t;
}

/** Deutsches Zahlenformat für CSV: 1234,56 */
function _kasseExport_betrag($v) {
    return number_format((float)$v, 2, ',', '');
}

/**
 * Kassenbuch als CSV (Semikolon, UTF-8 mit BOM, deutsches Zahlenformat) —
 * dieselben Spalten wie der Ausdruck, plus Partner und Belegdatei.
 */
function _kasseExport_csv(array $report, $from) {
    $lines = [implode(';', array_map('_datevQuote', [
        'Nr.', 'Datum', 'Beleg-Nr.', 'Buchungstext', 'Partner', 'Gegenkonto', 'Gegenkonto-Bezeichnung',
        'USt %', 'Einnahme', 'Ausgabe', 'Bestand', 'Belegdatei',
    ]))];

    $lines[] = implode(';', [
        '', _datevQuote($from ? _bu_datum($from) : ''), '', _datevQuote('Übertrag / Anfangsbestand'),
        '', '', '', '', '', '', _kasseExport_betrag($report['opening']), '',
    ]);

    foreach ($report['rows'] as $row) {
        $amount  = (float)$row['amount'];
        $taxRate = (float)($row['tax_rate'] ?? 0);
        $gegen   = (string)($row['gegenkonto_accno'] ?? '');
        $gegenBez = (string)($row['gegenkonto_description'] ?? '');
        $steuer  = $taxRate > 0 ? _kasseExport_betrag($taxRate * 100) : '';
        if ((int)($row['split_count'] ?? 0) > 1) {
            $gegen    = 'Split: ' . $row['split_accnos'];
            $gegenBez = '';
            $steuer   = (string)$row['split_rates'];
        }
        $lines[] = implode(';', [
            (string)$row['lfd'],
            _datevQuote(_bu_datum($row['transdate'])),
            _datevQuote($row['reference'] ?? ''),
            _datevQuote(trim((string)($row['description'] ?? $row['invnumber'] ?? ''))),
            _datevQuote($row['partner_name'] ?? ''),
            _datevQuote($gegen),
            _datevQuote($gegenBez),
            $steuer,
            $amount > 0 ? _kasseExport_betrag($amount) : '',
            $amount < 0 ? _kasseExport_betrag(-$amount) : '',
            _kasseExport_betrag($row['saldo']),
            _datevQuote($row['document_file'] ?? ''),
        ]);
    }

    $lines[] = implode(';', [
        '', '', '', _datevQuote('Summe (' . (int)$report['row_count'] . ' Buchungen)'), '', '', '', '',
        _kasseExport_betrag($report['sum_income']), _kasseExport_betrag($report['sum_expense']),
        _kasseExport_betrag($report['closing']), '',
    ]);

    return "\xEF\xBB\xBF" . implode("\r\n", $lines);
}

/**
 * Belegdateinamen festlegen und in den Zeilen vermerken (document_file).
 *
 * Gleiche Namensregel wie das Buchhaltungs-Paket: <Belegnummer>_<Rechnungsnummer>.
 * Zweimal derselbe Name wird durchnummeriert, sonst überschriebe der zweite
 * Beleg im Archiv den ersten.
 */
function _kasseExport_belegnamen(array &$report) {
    $vergeben = [];
    foreach ($report['rows'] as &$row) {
        $row['document_file'] = '';
        if (empty($row['document_id'])) continue;
        $name = _datevBelegDateiname([
            'document_id'    => $row['document_id'],
            'mime_type'      => $row['document_mime'] ?? '',
            'reference'      => $row['reference'] ?? '',
            'invoice_number' => $row['invnumber'] ?? '',
        ]);
        $basis = pathinfo($name, PATHINFO_FILENAME);
        $ext   = pathinfo($name, PATHINFO_EXTENSION);
        $n = 2;
        while (isset($vergeben[$name])) $name = $basis . '_' . $n++ . '.' . $ext;
        $vergeben[$name] = true;
        $row['document_file'] = $name;
    }
    unset($row);
}

/**
 * Kassenbuchzeilen in die Form des DATEV-Generators bringen.
 *
 * Konto ist immer das Kassenkonto, Gegenkonto das Sachkonto der Position.
 * Einnahme = Kasse im Soll → 'incoming' (S), Ausgabe = Kasse im Haben (H).
 * Splitbuchungen liefern je Position eine Zeile mit derselben Belegnummer;
 * Bar bezahlte Rechnungen eine Zeile gegen das Debitoren-/Kreditoren-
 * Sammelkonto mit der Rechnungsnummer in Belegfeld 1.
 */
function _kasseExport_datevBookings(array $report) {
    $kasse = (string)$report['chart_accno'];
    $out   = [];
    foreach ($report['rows'] as $row) {
        $amount = (float)$row['amount'];
        $basis  = [
            'type'           => $amount > 0 ? 'incoming' : 'outgoing',
            'debit_account'  => $kasse,
            'booking_date'   => $row['transdate'],
            'reference'      => (string)($row['reference'] ?? ''),
            'invoice_number' => (string)($row['invnumber'] ?? ''),
            'description'    => _kasse_rowText($row),
            'document_name'  => $row['document_file'] ?? '',
            'document_hash'  => $row['document_hash'] ?? '',
        ];
        // Ohne Rechnungsnummer steht die Kassenbelegnummer in Belegfeld 1 —
        // DATEV sortiert und sucht danach, ein leeres Feld hilft niemandem.
        if ($basis['invoice_number'] === '') {
            $basis['invoice_number'] = $basis['reference'];
            $basis['reference']      = '';
        }

        $lines = is_string($row['datev_lines'] ?? null) ? json_decode($row['datev_lines'], true) : ($row['datev_lines'] ?? null);
        if (is_array($lines) && $lines) {
            foreach ($lines as $l) {
                $out[] = $basis + [
                    'amount'         => (float)$l['gross'],
                    'credit_account' => (string)$l['accno'],
                    'tax_key'        => (!empty($l['automatik']) || (int)$l['taxkey'] === 0) ? '' : (string)$l['taxkey'],
                ];
            }
        } else {
            $out[] = $basis + [
                'amount'         => abs($amount),
                'credit_account' => (string)($row['gegenkonto_accno'] ?? ''),
                'tax_key'        => '',
            ];
        }
    }
    return $out;
}

/**
 * Export-Paket als ZIP-Datei im Temp-Verzeichnis bauen.
 *
 * @return array [pfad, dateiname, anzahlBelege, fehlendeBelege[]]
 */
function _kasseExport_zip($db, array $report, $from, $to, $zeitraum) {
    _kasseExport_belegnamen($report);

    $datevConfig = $db->getOne("SELECT * FROM datev LIMIT 1") ?: [];
    $defaults    = $db->getOne("SELECT company, taxnumber, co_ustid, coa FROM defaults LIMIT 1") ?: [];

    $bookings = _kasseExport_datevBookings($report);
    $csvDatev = _generateDatevCsv($bookings, $datevConfig, $defaults, $from ?: date('Y-01-01'), $to ?: date('Y-m-d'));
    $pdf      = _kasse_cashbookPdf($report, $from, $zeitraum);
    $csv      = _kasseExport_csv($report, $from);

    $basis    = rtrim(fmDataDir(), '/');
    $zipDatei = tempnam(sys_get_temp_dir(), 'kasse_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipDatei, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new ApiError('EXPORT_FAILED', 'Export-Paket konnte nicht erstellt werden.');
    }

    $zip->addFromString('Buchungsstapel.csv', "\xEF\xBB\xBF" . $csvDatev);
    $zip->addFromString('Kassenbuch.pdf', $pdf);
    $zip->addFromString('Kassenbuch.csv', $csv);

    $liste   = ['Nr.;Belegnummer;Rechnungsnummer;Datum;Betrag;Konto;Gegenkonto;Belegdatei;SHA-256;Status'];
    $dabei   = 0;
    $fehlend = [];
    foreach ($report['rows'] as $row) {
        $datei  = $row['document_file'] ?? '';
        $status = 'ohne Beleg';
        if ($datei !== '' && !empty($row['stored_path'])) {
            $pfad = $basis . '/' . ltrim($row['stored_path'], '/');
            if (is_readable($pfad)) {
                $zip->addFile($pfad, 'Belege/' . $datei);
                $status = 'beigefügt';
                $dabei++;
            } else {
                $status    = 'Datei fehlt';
                $fehlend[] = ($row['reference'] ?? '') . ' (' . $row['stored_path'] . ')';
            }
        }
        $gegen = (int)($row['split_count'] ?? 0) > 1 ? 'Split: ' . $row['split_accnos'] : ($row['gegenkonto_accno'] ?? '');
        $liste[] = implode(';', [
            (string)$row['lfd'],
            _datevQuote($row['reference'] ?? ''),
            _datevQuote($row['invnumber'] ?? ''),
            _datevQuote(_bu_datum($row['transdate'])),
            _kasseExport_betrag(abs((float)$row['amount'])),
            _datevQuote($report['chart_accno']),
            _datevQuote($gegen),
            _datevQuote($status === 'beigefügt' ? $datei : ''),
            _datevQuote($row['document_hash'] ?? ''),
            _datevQuote($status),
        ]);
    }
    $zip->addFromString('Belegliste.csv', "\xEF\xBB\xBF" . implode("\r\n", $liste));

    $firma    = (string)($defaults['company'] ?? $report['company'] ?? '');
    $hinweis  = "Kassenbuch-Export " . $firma . "\r\n";
    $hinweis .= "Kasse: " . $report['chart_description'] . " (Konto " . $report['chart_accno'] . ")\r\n";
    $hinweis .= "Zeitraum: " . $zeitraum . "\r\n";
    $hinweis .= "Erstellt am: " . date('d.m.Y H:i') . "\r\n";
    $hinweis .= "Anfangsbestand: " . _kasseExport_betrag($report['opening']) . " EUR   Endbestand: " . _kasseExport_betrag($report['closing']) . " EUR\r\n";
    $hinweis .= "Steuernummer: " . ($defaults['taxnumber'] ?? '') . "   USt-IdNr.: " . ($defaults['co_ustid'] ?? '') . "\r\n";
    $hinweis .= "Kontenrahmen: " . ($defaults['coa'] ?? '') . "\r\n\r\n";
    $hinweis .= "INHALT\r\n";
    $hinweis .= "  Kassenbuch.pdf      Kassenbuch zum Ausdrucken, mit laufendem Bestand\r\n";
    $hinweis .= "  Kassenbuch.csv      dieselben Zeilen als Tabelle (Semikolon, UTF-8)\r\n";
    $hinweis .= "  Buchungsstapel.csv  Buchungen im DATEV-Format (EXTF 700), direkt importierbar;\r\n";
    $hinweis .= "                      Konto = Kasse, Gegenkonto = Sachkonto, Splitbuchungen je Position eine Zeile\r\n";
    $hinweis .= "  Belege/             " . $dabei . " gescannte Belege zu " . (int)$report['row_count'] . " Buchungen\r\n";
    $hinweis .= "  Belegliste.csv      Zuordnung Buchung <-> Belegdatei mit Prüfsumme\r\n\r\n";
    $hinweis .= "ZUORDNUNG\r\n";
    $hinweis .= "  Im Buchungsstapel steht der Dateiname in 'Beleginfo - Inhalt 1',\r\n";
    $hinweis .= "  die ersten 16 Stellen der Prüfsumme in 'Beleginfo - Inhalt 2'.\r\n";
    $hinweis .= "  Die vollständige Prüfsumme steht in der Belegliste.\r\n\r\n";
    $hinweis .= "UNVERÄNDERTHEIT PRÜFEN\r\n";
    $hinweis .= "  Der SHA-256 wird beim Einscannen gebildet und mitgeführt.\r\n";
    $hinweis .= "  Prüfung einer Datei:  sha256sum \"Belege/<Dateiname>\"\r\n";
    $hinweis .= "  Der Wert muss mit der Spalte SHA-256 der Belegliste übereinstimmen.\r\n";
    if (empty($datevConfig['beraternr']) || empty($datevConfig['mandantennr'])) {
        $hinweis .= "\r\nHINWEIS: Berater- und Mandantennummer sind in OpensourceERP nicht hinterlegt;\r\n";
        $hinweis .= "  der Stapel trägt Platzhalter. Beim Import in DATEV die eigenen Nummern setzen.\r\n";
    }
    if ($fehlend) {
        $hinweis .= "\r\nWARNUNG: zu folgenden Buchungen fehlt die Belegdatei auf dem Server:\r\n";
        foreach ($fehlend as $f) $hinweis .= '  ' . $f . "\r\n";
    }
    $zip->addFromString('LIESMICH.txt', $hinweis);
    $zip->close();

    $dateiname = 'Kassenbuch_' . $report['chart_accno'] . '_' . _kasseExport_dateiZeitraum($from, $to) . '.zip';
    return [$zipDatei, $dateiname, $dabei, $fehlend];
}

// ── API ──────────────────────────────────────────────────────────────────────

/**
 * Kassenbuch als CSV (Semikolon, UTF-8 mit BOM, deutsches Zahlenformat).
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param string $data['from_date']        Von-Datum (optional)
 * @param string $data['to_date']          Bis-Datum (optional)
 * @testdata {"cash_register_id": 1, "from_date": "2026-01-01", "to_date": "2026-12-31"}
 */
function getCashbookCsv($data) {
    $c = _kasseExport_context($data);
    if (!$c) return;

    _kasseExport_belegnamen($c['report']);
    $csv = _kasseExport_csv($c['report'], $c['from']);

    resultInfo(true, '', [
        'filename' => 'Kassenbuch_' . $c['report']['chart_accno'] . '_' . _kasseExport_dateiZeitraum($c['from'], $c['to']) . '.csv',
        'mime'     => 'text/csv',
        'data'     => base64_encode($csv),
    ]);
}

/**
 * DATEV-Paket des Kassenbuchs als ZIP-Download (Binärantwort, kein JSON):
 * Buchungsstapel, Kassenbuch als PDF und CSV, Belege, Belegliste, LIESMICH.
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param string $data['from_date']        Von-Datum (optional)
 * @param string $data['to_date']          Bis-Datum (optional)
 * @param string $data['period_label']     Beschriftung des Zeitraums (optional)
 * @testdata {"cash_register_id": 1, "from_date": "2026-01-01", "to_date": "2026-01-31"}
 */
function getCashbookDatevPackage($data) {
    $c = _kasseExport_context($data);
    if (!$c) return;

    [$zipDatei, $dateiname] = _kasseExport_zip($c['db'], $c['report'], $c['from'], $c['to'], $c['zeitraum']);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $dateiname . '"');
    header('Content-Length: ' . filesize($zipDatei));
    readfile($zipDatei);
    unlink($zipDatei);
    exit;
}

/**
 * Was der Versanddialog vorab wissen muss: Umfang des Zeitraums, Belege und
 * ihre Größe, Absender, gemerkter Empfänger, ob Mail und DATEV eingerichtet sind.
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param string $data['from_date']        Von-Datum (optional)
 * @param string $data['to_date']          Bis-Datum (optional)
 * @testdata {"cash_register_id": 1, "from_date": "2026-01-01", "to_date": "2026-01-31"}
 */
function getCashbookExportInfo($data) {
    $c = _kasseExport_context($data);
    if (!$c) return;
    $db = $c['db'];

    $docs  = 0; $bytes = 0;
    foreach ($c['report']['rows'] as $row) {
        if (!empty($row['document_id']) && !empty($row['stored_path'])) {
            $docs++;
            $bytes += (int)($row['file_size'] ?? 0);
        }
    }

    $mail  = _getEmailConfig();
    $datev = $db->getOne("SELECT beraternr, mandantennr, beratername FROM datev LIMIT 1") ?: [];
    $cfg   = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key IN ('kassenbuch_export_email', 'kassenbuch_export_cc')");
    $gemerkt = [];
    foreach ($cfg as $r) $gemerkt[$r['key']] = $r['value'];

    resultInfo(true, '', [
        'company'          => $c['report']['company'] ?? '',
        'chart_accno'      => $c['report']['chart_accno'],
        'row_count'        => (int)$c['report']['row_count'],
        'opening'          => (float)$c['report']['opening'],
        'closing'          => (float)$c['report']['closing'],
        'document_count'   => $docs,
        'document_bytes'   => $bytes,
        'max_mail_bytes'   => KASSE_MAIL_MAX_BYTES,
        'email_configured' => !empty($mail['email_smtp_host']) && !empty($mail['email_username']),
        'sender'           => $mail['email_address'] ?? $mail['email_username'] ?? '',
        'recipient'        => $gemerkt['kassenbuch_export_email'] ?? '',
        'cc'               => $gemerkt['kassenbuch_export_cc'] ?? '',
        'datev_configured' => !empty($datev['beraternr']) && !empty($datev['mandantennr']),
        'datev_berater'    => $datev['beratername'] ?? '',
    ]);
}

/**
 * Kassenbuch per E-Mail versenden — PDF, CSV und/oder DATEV-Paket als Anhang.
 *
 * Der Empfänger wird firmenweit gemerkt (defaults_oserp), damit er beim
 * nächsten Monat vorbelegt ist. Übersteigen die Anhänge KASSE_MAIL_MAX_BYTES,
 * wird nicht gesendet, sondern die Größe gemeldet — außer `force` ist gesetzt.
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param string $data['from_date']        Von-Datum (optional)
 * @param string $data['to_date']          Bis-Datum (optional)
 * @param string $data['period_label']     Beschriftung des Zeitraums (optional)
 * @param string $data['to']               Empfänger, mehrere mit Komma/Semikolon
 * @param string $data['cc']               Kopie (optional)
 * @param string $data['subject']          Betreff
 * @param string $data['body']             Nachrichtentext (Klartext)
 * @param array  $data['attachments']      {"pdf": true, "csv": true, "datev": true}
 * @param bool   $data['force']            Größengrenze ignorieren
 * @param int    $data['employee_id']      Absender (Store)
 * @testdata {"cash_register_id": 1, "from_date": "2026-01-01", "to_date": "2026-01-31", "to": "steuerberater@example.com", "subject": "Kassenbuch Januar 2026", "body": "Anbei das Kassenbuch.", "attachments": {"pdf": true, "csv": true, "datev": true}}
 */
function sendCashbookEmail($data) {
    $c = _kasseExport_context($data);
    if (!$c) return;
    $db = $c['db']; $report = $c['report'];

    $parse = fn($s) => array_values(array_filter(array_map(
        fn($e) => ['email' => trim($e), 'name' => ''], preg_split('/[,;]/', (string)$s)
    ), fn($e) => $e['email'] !== '' && filter_var($e['email'], FILTER_VALIDATE_EMAIL)));
    $toList = $parse($data['to'] ?? '');
    $ccList = $parse($data['cc'] ?? '');
    if (!$toList) { resultInfo(false, 'VALIDATION_ERROR', 'Bitte eine gültige Empfängeradresse angeben'); return; }

    $subject = trim((string)($data['subject'] ?? ''));
    if ($subject === '') $subject = 'Kassenbuch ' . $c['zeitraum'];
    $body = (string)($data['body'] ?? '');

    $want = $data['attachments'] ?? [];
    $wantPdf   = !empty($want['pdf']);
    $wantCsv   = !empty($want['csv']);
    $wantDatev = !empty($want['datev']);
    if (!$wantPdf && !$wantCsv && !$wantDatev) { resultInfo(false, 'VALIDATION_ERROR', 'Bitte mindestens einen Anhang wählen'); return; }

    $config = _getEmailConfig();
    if (empty($config['email_smtp_host']) || empty($config['email_username'])) {
        resultInfo(false, 'EMAIL_NOT_CONFIGURED', 'E-Mail-Versand ist nicht eingerichtet (Einstellungen → E-Mail)');
        return;
    }

    $zeitraumDatei = _kasseExport_dateiZeitraum($c['from'], $c['to']);
    $attachments = [];
    $zipDatei = null;
    try {
        if ($wantPdf) {
            $attachments[] = [
                'filename'       => 'Kassenbuch_' . $report['chart_accno'] . '_' . $zeitraumDatei . '.pdf',
                'content_base64' => base64_encode(_kasse_cashbookPdf($report, $c['from'], $c['zeitraum'])),
                'content_type'   => 'application/pdf',
            ];
        }
        if ($wantCsv) {
            $r = $report; _kasseExport_belegnamen($r);
            $attachments[] = [
                'filename'       => 'Kassenbuch_' . $report['chart_accno'] . '_' . $zeitraumDatei . '.csv',
                'content_base64' => base64_encode(_kasseExport_csv($r, $c['from'])),
                'content_type'   => 'text/csv',
            ];
        }
        if ($wantDatev) {
            [$zipDatei, $zipName] = _kasseExport_zip($db, $report, $c['from'], $c['to'], $c['zeitraum']);
            $attachments[] = [
                'filename'       => $zipName,
                'content_base64' => base64_encode(file_get_contents($zipDatei)),
                'content_type'   => 'application/zip',
            ];
        }
    } finally {
        if ($zipDatei && is_file($zipDatei)) unlink($zipDatei);
    }

    $bytes = 0;
    foreach ($attachments as $a) $bytes += (int)(strlen($a['content_base64']) * 3 / 4);
    if ($bytes > KASSE_MAIL_MAX_BYTES && empty($data['force'])) {
        resultInfo(false, 'ATTACHMENTS_TOO_LARGE', [
            'bytes'     => $bytes,
            'max_bytes' => KASSE_MAIL_MAX_BYTES,
        ]);
        return;
    }

    $fromEmail = $config['email_address'] ?? $config['email_username'] ?? '';
    $fromName  = (string)($report['company'] ?? '');
    $bodyHtml  = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

    try {
        $smtp = _createSmtpClient($config);
        $raw  = $smtp->send($fromEmail, $fromName, $toList, $subject, $bodyHtml, $body, $ccList, [], $attachments);
    } catch (\Throwable $e) {
        resultInfo(false, 'EMAIL_SEND_FAILED', 'Versand fehlgeschlagen: ' . $e->getMessage());
        return;
    }

    // Kopie in „Gesendet" — nicht kritisch, die Mail ist raus
    try {
        $imap = _createImapClient($config);
        $sent = $imap->findSentFolder();
        if ($sent) $imap->appendToFolder($sent, $raw);
        $imap->disconnect();
    } catch (\Throwable $e) {
        error_log('[KASSE] Kopie im Sent-Ordner fehlgeschlagen: ' . $e->getMessage());
    }

    try {
        _logToEmailJournal($fromEmail, $toList, $ccList, $subject, $body, $attachments, 'cashbook', null, null, mitarbeiterId($data));
    } catch (\Throwable $e) {
        error_log('[KASSE] Journal-Eintrag fehlgeschlagen: ' . $e->getMessage());
    }

    // Empfänger merken — beim nächsten Monat ist er vorbelegt
    $db->execute(
        "INSERT INTO defaults_oserp (key, value, mtime) VALUES
            ('kassenbuch_export_email', :to, now()),
            ('kassenbuch_export_cc',    :cc, now())
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
        ['to' => implode(', ', array_column($toList, 'email')), 'cc' => implode(', ', array_column($ccList, 'email'))]
    );

    resultInfo(true, 'Kassenbuch versendet', [
        'recipients'  => array_column($toList, 'email'),
        'attachments' => array_map(fn($a) => $a['filename'], $attachments),
        'bytes'       => $bytes,
    ]);
}
