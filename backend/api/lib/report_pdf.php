<?php
// backend/api/lib/report_pdf.php
//
// Gemeinsame Grundlage aller gedruckten Buchhaltungslisten (Kontoauszug,
// Kassenbuch, Summen- und Saldenliste).
//
// Warum eine eigene Klasse: FPDF ruft Header()/Footer() bei jedem Seitenumbruch
// selbst auf. Nur so wiederholt sich die Spaltenueberschrift auf Seite 2 und die
// Fusszeile traegt „Seite 1 von 3" — beides ist bei einer Buchhaltungsliste
// Pflicht, weil sie geheftet in den Ordner wandert und vollständig sein muss.

require_once __DIR__ . '/../../vendor/setasign/fpdf/fpdf.php';

class ReportPdf extends \FPDF
{
    /** @var string Titel oben links (z. B. „Kassenbuch") */
    public $reportTitle = '';

    /** @var string[] Zeilen unter dem Titel (Firma, Konto, Zeitraum) */
    public $reportLines = [];

    /** @var array<array{w:float,label:string,align?:string}> Spalten der Tabelle */
    public $columns = [];

    /** @var string Kleingedrucktes ganz unten links */
    public $footNote = '';

    /** @var bool Wechselnde Zeilenfarbe — bei langen Listen hilft das dem Auge */
    private $stripe = false;

    /**
     * Text nach ISO-8859-1 (FPDF-Kernschriften können kein UTF-8).
     * Typografische Zeichen werden vorher ersetzt, sonst fallen sie weg.
     */
    public static function de($text)
    {
        $text = strtr((string)$text, [
            '–' => '-', '—' => '-', '‐' => '-', '−' => '-',
            '„' => '"', '“' => '"', '”' => '"', '‚' => "'", '‘' => "'", '’' => "'",
            '…' => '...', '·' => '-', '€' => 'EUR', "\u{00A0}" => ' ',
        ]);
        return mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    }

    /** Betrag deutsch: 1.234,56 — leer bei 0, wenn $blankZero gesetzt ist */
    public static function money($value, $blankZero = false)
    {
        $value = (float)$value;
        if ($blankZero && abs($value) < 0.005) return '';
        return number_format($value, 2, ',', '.');
    }

    /** Kürzt auf die Spaltenbreite, damit nichts in die Nachbarspalte läuft */
    private function fit($text, $width)
    {
        $text = self::de($text);
        if ($this->GetStringWidth($text) <= $width - 2) return $text;
        while (strlen($text) > 1 && $this->GetStringWidth($text . '...') > $width - 2) {
            $text = substr($text, 0, -1);
        }
        return $text . '...';
    }

    /**
     * Bricht Text an Wortgrenzen auf die Spaltenbreite um.
     * Der Verwendungszweck einer Bank ist regelmäßig länger als jede Spalte —
     * abschneiden würde die Buchung unbelegbar machen, also lieber zwei Zeilen.
     */
    private function splitLines($text, $width, $maxLines)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        if ($text === '') return [''];

        $limit = $width - 2;
        $lines = [];
        $current = '';
        foreach (explode(' ', self::de($text)) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->GetStringWidth($candidate) <= $limit) { $current = $candidate; continue; }
            if ($current !== '') $lines[] = $current;
            // Einzelwort länger als die Spalte (IBAN, Mandatsreferenz): hart trennen
            while ($this->GetStringWidth($word) > $limit && strlen($word) > 1) {
                $cut = strlen($word);
                while ($cut > 1 && $this->GetStringWidth(substr($word, 0, $cut)) > $limit) $cut--;
                $lines[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            $current = $word;
            if (count($lines) >= $maxLines) break;
        }
        if ($current !== '' && count($lines) < $maxLines) $lines[] = $current;

        if (count($lines) > $maxLines) $lines = array_slice($lines, 0, $maxLines);
        return $lines ?: [''];
    }

    public function Header()
    {
        $this->SetY(12);
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 8, self::de($this->reportTitle), 0, 1);

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(70, 70, 70);
        foreach ($this->reportLines as $line) {
            $this->Cell(0, 4.6, self::de($line), 0, 1);
        }
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2.5);

        if ($this->columns) $this->tableHead();
    }

    /** Spaltenüberschrift — auf jeder Seite erneut */
    public function tableHead()
    {
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(232, 234, 238);
        $this->SetDrawColor(150, 150, 150);
        foreach ($this->columns as $column) {
            $this->Cell($column['w'], 6, self::de($column['label']), 'TB', 0, $column['align'] ?? 'L', true);
        }
        $this->Ln();
        $this->SetFont('Arial', '', 8);
        $this->stripe = false;
    }

    /**
     * Eine Datenzeile. $cells ist parallel zu $columns; ein Eintrag darf schlicht
     * Text sein oder ['text' => …, 'bold' => true, 'color' => [r,g,b],
     * 'wrap' => true, 'maxLines' => 2].
     */
    public function row(array $cells, $fill = null)
    {
        $height = 4.4;
        $parts  = [];
        $count  = 1;

        foreach ($this->columns as $index => $column) {
            $cell = $cells[$index] ?? '';
            $text = is_array($cell) ? ($cell['text'] ?? '') : $cell;
            if (is_array($cell) && !empty($cell['wrap'])) {
                $parts[$index] = $this->splitLines($text, $column['w'], $cell['maxLines'] ?? 2);
                $count = max($count, count($parts[$index]));
            } else {
                $parts[$index] = [$this->fit($text, $column['w'])];
            }
        }

        // Eine Buchung darf nicht über den Seitenrand zerfallen — vorher umbrechen.
        if ($this->GetY() + $count * $height > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }

        $this->stripe = !$this->stripe;
        $shade = $fill === null ? $this->stripe : $fill;
        $this->SetFillColor(246, 247, 249);

        for ($line = 0; $line < $count; $line++) {
            foreach ($this->columns as $index => $column) {
                $cell  = $cells[$index] ?? '';
                $bold  = is_array($cell) && !empty($cell['bold']);
                $color = is_array($cell) && !empty($cell['color']) ? $cell['color'] : null;
                $text  = $parts[$index][$line] ?? '';

                // Folgezeilen des Umbruchs stehen zurückhaltender da
                $this->SetFont('Arial', $bold && $line === 0 ? 'B' : '', 8);
                if ($color && $line === 0) $this->SetTextColor($color[0], $color[1], $color[2]);
                elseif ($line > 0) $this->SetTextColor(95, 95, 95);
                $this->Cell($column['w'], $height, $text, 0, 0, $column['align'] ?? 'L', $shade);
                $this->SetTextColor(0, 0, 0);
            }
            $this->Ln();
        }
    }

    /** Abschlusszeile (Summen, Endbestand): Linie darüber, fett, grau hinterlegt */
    public function totalRow(array $cells)
    {
        $this->SetDrawColor(90, 90, 90);
        $this->SetLineWidth(0.3);
        $x = $this->GetX(); $y = $this->GetY();
        $this->Line($x, $y, $x + array_sum(array_column($this->columns, 'w')), $y);
        $this->SetLineWidth(0.2);

        $this->SetFillColor(232, 234, 238);
        foreach ($this->columns as $index => $column) {
            $cell = $cells[$index] ?? '';
            $text = is_array($cell) ? ($cell['text'] ?? '') : $cell;
            $this->SetFont('Arial', 'B', 8);
            $this->Cell($column['w'], 6, $this->fit($text, $column['w']), 0, 0, $column['align'] ?? 'L', true);
        }
        $this->Ln();
        $this->stripe = false;
    }

    public function Footer()
    {
        $this->SetY(-13);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(110, 110, 110);
        $half = ($this->w - $this->lMargin - $this->rMargin) / 2;
        $this->Cell($half, 4, self::de($this->footNote), 0, 0, 'L');
        $this->Cell($half, 4, self::de('Seite ' . $this->PageNo() . ' von {nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }
}
