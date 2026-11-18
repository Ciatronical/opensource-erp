<?php
// backend/api/hr/personnel.pdf.php
//
// Personalfragebogen als PDF: ausgefüllt mit den gespeicherten Angaben oder
// leer zum Ausfüllen von Hand. Der Bogen ist ein deutsches Formular für die
// Lohnabrechnung (Angaben wie im üblichen Personalfragebogen der
// Steuerberater), deshalb stehen die Beschriftungen hier und nicht in den
// Sprachdateien der Oberfläche.

require_once __DIR__.'/../lib/report_pdf.php';

/**
 * Abfrage für den Fragebogen: alle Angaben als fertige Anzeigetexte (Datum
 * deutsch, Auswahlwerte als Wort, Ja/Nein). Ohne Mitarbeiter (id 0) kommt
 * nur die Firma zurück — leerer Bogen.
 */
function hrQuestionnaireSql(): string {
    return <<<SQL
        WITH p AS (SELECT :employee_id::int AS employee_id)
        SELECT json_build_object(
            'company', COALESCE((SELECT company FROM defaults LIMIT 1), ''),
            'employee_id', e.id,
            'full_name', COALESCE(NULLIF(trim(COALESCE(d.first_name, '') || ' ' || COALESCE(d.last_name, '')), ''), e.name, ''),
            'personnel_number', d.personnel_number,
            'salutation', CASE d.salutation WHEN 'mr' THEN 'Herr' WHEN 'mrs' THEN 'Frau' END,
            'first_name', d.first_name, 'last_name', d.last_name, 'birth_name', d.birth_name,
            'birth_date', to_char(d.birth_date, 'DD.MM.YYYY'),
            'birth_place', d.birth_place, 'birth_country', d.birth_country, 'nationality', d.nationality,
            'gender', CASE d.gender WHEN 'male' THEN 'männlich' WHEN 'female' THEN 'weiblich'
                                    WHEN 'diverse' THEN 'divers' WHEN 'unspecified' THEN 'ohne Angabe' END,
            'marital_status', CASE d.marital_status WHEN 'single' THEN 'ledig' WHEN 'married' THEN 'verheiratet'
                                    WHEN 'partnership' THEN 'eingetragene Lebenspartnerschaft'
                                    WHEN 'separated' THEN 'getrennt lebend' WHEN 'divorced' THEN 'geschieden'
                                    WHEN 'widowed' THEN 'verwitwet' END,
            'street', d.street,
            'zip_city', NULLIF(trim(COALESCE(d.zipcode, '') || ' ' || COALESCE(d.city, '')), ''),
            'country', d.country, 'phone', d.phone, 'mobile', d.mobile, 'email', d.email,
            'severely_disabled', CASE WHEN d.id IS NULL THEN NULL WHEN d.severely_disabled THEN 'Ja' ELSE 'Nein' END,
            'iban', d.iban, 'bic', d.bic, 'bank_name', d.bank_name, 'account_holder', d.account_holder,
            'entry_date', to_char(d.entry_date, 'DD.MM.YYYY'),
            'exit_date', to_char(d.exit_date, 'DD.MM.YYYY'),
            'job_title', d.job_title,
            'employment_type', CASE d.employment_type WHEN 'full_time' THEN 'Vollzeit' WHEN 'part_time' THEN 'Teilzeit'
                                    WHEN 'minijob' THEN 'Geringfügige Beschäftigung (Minijob)'
                                    WHEN 'trainee' THEN 'Ausbildung' WHEN 'working_student' THEN 'Werkstudent'
                                    WHEN 'temporary' THEN 'Aushilfe' END,
            'weekly_hours', replace(trim(to_char(d.weekly_hours, 'FM990.00')), '.', ','),
            'probation_end', to_char(d.probation_end, 'DD.MM.YYYY'),
            'limited_until', to_char(d.limited_until, 'DD.MM.YYYY'),
            'notice_period', d.notice_period,
            'main_employment', CASE WHEN d.id IS NULL THEN NULL WHEN d.main_employment THEN 'Ja' ELSE 'Nein' END,
            'other_employments', d.other_employments,
            'school_degree', d.school_degree, 'vocational_training', d.vocational_training,
            'tax_id', d.tax_id,
            'steuerklasse', (SELECT s.steuerklasse::text FROM hr_salary_settings s WHERE s.employee_id = e.id),
            'child_allowance', replace(trim(to_char(d.child_allowance, 'FM90.0')), '.', ','),
            'denomination', d.denomination,
            'sv_number', d.sv_number, 'health_insurance', d.health_insurance,
            'health_insurance_type', CASE d.health_insurance_type WHEN 'statutory' THEN 'gesetzlich'
                                    WHEN 'voluntary' THEN 'freiwillig gesetzlich' WHEN 'private' THEN 'privat' END,
            'has_children', CASE WHEN d.id IS NULL THEN NULL WHEN d.has_children THEN 'Ja' ELSE 'Nein' END,
            'children_count', d.children_count::text,
            'emergency_name', d.emergency_name, 'emergency_phone', d.emergency_phone,
            'driving_license', d.driving_license, 'notes', d.notes
        ) AS result
        FROM p
        LEFT JOIN employee e ON e.id = p.employee_id
        LEFT JOIN hr_employee_data d ON d.employee_id = e.id
    SQL;
}

/**
 * Abschnitte des Bogens: Titel und Felder [Beschriftung, Schlüssel, Breite].
 * Breite 1 = halbe Zeile, 2 = ganze Zeile.
 */
function hrQuestionnaireSections(): array {
    return [
        ['Persönliche Angaben', [
            ['Anrede', 'salutation', 1], ['Personalnummer', 'personnel_number', 1],
            ['Vorname', 'first_name', 1], ['Nachname', 'last_name', 1],
            ['Geburtsname', 'birth_name', 1], ['Geburtsdatum', 'birth_date', 1],
            ['Geburtsort', 'birth_place', 1], ['Geburtsland', 'birth_country', 1],
            ['Staatsangehörigkeit', 'nationality', 1], ['Geschlecht', 'gender', 1],
            ['Familienstand', 'marital_status', 1], ['Schwerbehindert', 'severely_disabled', 1],
            ['Straße und Hausnummer', 'street', 1], ['PLZ und Ort', 'zip_city', 1],
            ['Telefon', 'phone', 1], ['Mobil', 'mobile', 1],
            ['E-Mail (privat)', 'email', 2],
        ]],
        ['Bankverbindung', [
            ['IBAN', 'iban', 1], ['BIC', 'bic', 1],
            ['Kreditinstitut', 'bank_name', 1], ['Kontoinhaber (falls abweichend)', 'account_holder', 1],
        ]],
        ['Beschäftigung', [
            ['Eintrittsdatum', 'entry_date', 1], ['Tätigkeit / Berufsbezeichnung', 'job_title', 1],
            ['Beschäftigungsart', 'employment_type', 1], ['Wöchentliche Arbeitszeit (Stunden)', 'weekly_hours', 1],
            ['Ende der Probezeit', 'probation_end', 1], ['Befristet bis', 'limited_until', 1],
            ['Hauptbeschäftigung', 'main_employment', 1], ['Kündigungsfrist', 'notice_period', 1],
            ['Weitere Beschäftigungen (Arbeitgeber, Art, Umfang)', 'other_employments', 2],
            ['Höchster Schulabschluss', 'school_degree', 1], ['Höchste Berufsausbildung', 'vocational_training', 1],
        ]],
        ['Steuer', [
            ['Steuer-Identifikationsnummer', 'tax_id', 1], ['Steuerklasse', 'steuerklasse', 1],
            ['Kinderfreibeträge', 'child_allowance', 1], ['Konfession', 'denomination', 1],
        ]],
        ['Sozialversicherung', [
            ['Sozialversicherungsnummer', 'sv_number', 1], ['Krankenkasse', 'health_insurance', 1],
            ['Art der Krankenversicherung', 'health_insurance_type', 1], ['Elterneigenschaft', 'has_children', 1],
            ['Anzahl der Kinder', 'children_count', 1],
        ]],
        ['Sonstiges', [
            ['Notfallkontakt (Name)', 'emergency_name', 1], ['Notfallkontakt (Telefon)', 'emergency_phone', 1],
            ['Führerscheinklassen', 'driving_license', 1],
            ['Bemerkungen', 'notes', 2],
        ]],
    ];
}

/** Seitenkopf und -fuß des Fragebogens */
class HrQuestionnaireDoc extends \FPDF
{
    public $company = '';
    public $person = '';

    public function Header()
    {
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 8, ReportPdf::de('Personalfragebogen'), 0, 1);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(0, 5, ReportPdf::de(trim($this->company . ($this->person !== '' ? '  ·  ' . $this->person : ''))), 0, 1);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(3);
    }

    public function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(120, 4, ReportPdf::de('Vertraulich - Personalunterlage. Die Angaben werden für die Lohn- und Gehaltsabrechnung benötigt.'), 0, 0);
        $this->Cell(0, 4, ReportPdf::de('Seite ' . $this->PageNo() . ' von {nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }
}

/**
 * Baut den Fragebogen.
 *
 * @param array $values Anzeigetexte aus hrQuestionnaireSql()
 * @param bool  $blank  Leerer Bogen (Linien zum Ausfüllen)
 * @return string PDF-Inhalt
 */
function hrQuestionnairePdf(array $values, bool $blank): string {
    $pdf = new HrQuestionnaireDoc('P', 'mm', 'A4');
    $pdf->company = (string)($values['company'] ?? '');
    $pdf->person  = $blank ? '' : (string)($values['full_name'] ?? '');
    $pdf->AliasNbPages();
    $pdf->SetMargins(18, 14, 18);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();

    $full = 174;          // nutzbare Breite
    $half = 84;           // Feldbreite bei zwei Spalten
    $gap  = $full - 2 * $half;
    $rowH = 11.5;

    foreach (hrQuestionnaireSections() as [$title, $fields]) {
        // Abschnitt nicht am Seitenende beginnen
        if ($pdf->GetY() > 245) $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(232, 234, 238);
        $pdf->Cell($full, 6.5, ReportPdf::de($title), 0, 1, 'L', true);
        $pdf->Ln(1.5);

        $column = 0;
        foreach ($fields as [$label, $key, $span]) {
            $width = $span === 2 ? $full : $half;
            if ($span === 2 && $column === 1) { $pdf->Ln($rowH); $column = 0; }
            if ($column === 0 && $pdf->GetY() + $rowH > 280) $pdf->AddPage();

            $x = 18 + ($column === 1 ? $half + $gap : 0);
            $y = $pdf->GetY();
            $pdf->SetXY($x, $y);
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell($width, 3.5, ReportPdf::de($label), 0, 0);
            $pdf->SetTextColor(0, 0, 0);

            $text = $blank ? '' : trim((string)($values[$key] ?? ''));
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetXY($x, $y + 3.8);
            // Zu lange Angaben kürzen statt in das Nachbarfeld zu laufen
            $shown = ReportPdf::de(preg_replace('/\s+/u', ' ', $text));
            while ($shown !== '' && $pdf->GetStringWidth($shown) > $width - 2) $shown = substr($shown, 0, -1);
            $pdf->Cell($width, 5, $shown, 0, 0);
            $pdf->SetDrawColor(150, 150, 150);
            $pdf->Line($x, $y + 9.2, $x + $width, $y + 9.2);

            if ($span === 2 || $column === 1) { $pdf->SetXY(18, $y + $rowH); $column = 0; }
            else { $pdf->SetXY(18, $y); $column = 1; }
        }
        if ($column === 1) $pdf->SetXY(18, $pdf->GetY() + $rowH);
        $pdf->Ln(2);
    }

    // Erklärung und Unterschriften zusammenhalten
    if ($pdf->GetY() > 235) $pdf->AddPage();
    $pdf->Ln(2);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell($full, 4.5, ReportPdf::de(
        'Ich versichere, dass die vorstehenden Angaben vollständig und richtig sind. Ich verpflichte mich, '
        . 'meinem Arbeitgeber alle Änderungen, insbesondere zu weiteren Beschäftigungen, Anschrift, Bankverbindung '
        . 'und Krankenkasse, unverzüglich mitzuteilen.'
    ));
    $pdf->Ln(14);
    $y = $pdf->GetY();
    $pdf->SetDrawColor(90, 90, 90);
    $pdf->Line(18, $y, 18 + $half, $y);
    $pdf->Line(18 + $half + $gap, $y, 18 + $full, $y);
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->SetXY(18, $y + 1);
    $pdf->Cell($half + $gap, 4, ReportPdf::de('Ort, Datum'), 0, 0);
    $pdf->Cell($half, 4, ReportPdf::de('Unterschrift Arbeitnehmer'), 0, 1);

    return $pdf->Output('S');
}
