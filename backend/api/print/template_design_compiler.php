<?php
// backend/api/print/template_design_compiler.php

/**
 * Übersetzt ein Design des Vorlageneditors (JSON) in eine LaTeX-Vorlage.
 *
 * Das Ergebnis ist eine gewöhnliche kivitendo-kompatible .tex-Datei mit
 * <%variable%>-Platzhaltern, <%if%>- und <%foreach%>-Blöcken, die die
 * bestehende LaTeXTemplateEngine genauso verarbeitet wie handgeschriebene
 * Vorlagen. Der Editor erzeugt also keine zweite Druckstrecke, sondern nur
 * die Vorlagendatei.
 *
 * Aufbau eines Designs:
 *
 *   page    Papier, Ränder des Fließbereichs, Schrift, Farben, Falzmarken,
 *           Hintergrundbild, Währungsdarstellung
 *   blocks  frei platzierte Bausteine in Millimetern (x, y, w, h) — Logo,
 *           Absenderzeile, Anschrift, Infobox, Freitext, Linie, Seitenzahl,
 *           GiroCode. Jeder Baustein gilt für alle Seiten, nur die erste oder
 *           nur die Folgeseiten.
 *   body    der Fließbereich: Betreff, Einleitung, Positionstabelle, Summen,
 *           Schlusstext, Unterschrift — in frei wählbarer Reihenfolge.
 *
 * Statischer Text wird hier LaTeX-escaped; Platzhalter bleiben stehen, deren
 * Werte escaped die Engine beim Druck. Zwei kleine Auszeichnungen sind im
 * Text erlaubt: **fett** und *kursiv*. {page} und {pages} ergeben die
 * Seitenzahl bzw. die Gesamtseitenzahl.
 *
 * Die Bausteine liegen über eso-pic auf der Seite (\AddToShipoutPictureBG),
 * der Fließbereich ist der normale Textkörper mit geometry-Rändern. So kann
 * die Positionstabelle über Seiten umbrechen, während Kopf und Fuß auf jeder
 * Seite an derselben Stelle stehen.
 */
class TemplateDesignCompiler {

    /** @var LaTeXTemplateEngine nur fürs Escaping statischer Texte */
    private $engine;

    /** Spalten der Positionstabelle: Schlüssel => [Platzhalter-LaTeX, Standardausrichtung, numerisch] */
    private const TABLE_COLUMNS = [
        'runningnumber' => ['<%runningnumber%>', 'right', false],
        'number'        => ['<%number%>', 'left', false],
        'description'   => ['DESCRIPTION', 'left', false],
        'qty'           => ['<%qty%> <%unit%>', 'right', false],
        'unit'          => ['<%unit%>', 'left', false],
        'sellprice'     => ['<%sellprice%>CURRENCY', 'right', true],
        'p_discount'    => ['\\ifthenelse{\\equal{<%p_discount%>}{0}}{}{<%p_discount%>\\,\\%}', 'right', false],
        'linetotal'     => ['<%linetotal%>CURRENCY', 'right', true],
        'serialnumber'  => ['<%serialnumber%>', 'left', false],
    ];

    public function __construct() {
        // Die Engine braucht ein Verzeichnis, hier wird aber nur escapeLatexPublic() genutzt
        $this->engine = new LaTeXTemplateEngine(sys_get_temp_dir());
    }

    // ===== Öffentliche Schnittstelle =====

    /**
     * Erzeugt den vollständigen LaTeX-Quelltext einer Vorlage
     *
     * @param array $design Design-JSON als Array
     * @return string LaTeX mit <%...%>-Platzhaltern
     */
    public function compile(array $design): string {
        $page = $this->pageDefaults($design['page'] ?? []);
        $blocks = is_array($design['blocks'] ?? null) ? $design['blocks'] : [];
        $sections = is_array($design['body']['sections'] ?? null) ? $design['body']['sections'] : [];

        $out = [];
        $out[] = '% Erzeugt vom OSERP-Vorlageneditor — Änderungen bitte im Editor vornehmen,';
        $out[] = '% diese Datei wird beim nächsten Speichern überschrieben.';
        $out[] = $this->preamble($page);
        $out[] = $this->shipoutBlocks($blocks, $page);
        $out[] = '\\begin{document}';
        $out[] = $this->firstPageBlocks($blocks, $page);
        $out[] = $this->bodyFont($page);

        // Fließbereich beginnt auf Seite 1 tiefer als auf den Folgeseiten
        $offset = $page['bodyTop'] - $page['bodyTopFollowing'];
        if ($offset > 0) {
            $out[] = sprintf('\\vspace*{%smm}', $this->mm($offset));
        }

        foreach ($sections as $section) {
            $out[] = $this->section($section, $page);
        }

        $out[] = '\\end{document}';
        return implode("\n", array_filter($out, fn($s) => $s !== null && $s !== '')) . "\n";
    }

    /**
     * Prüft ein Design auf Vollständigkeit und sinnvolle Werte
     *
     * @return array Liste von Fehlercodes, leer wenn gültig
     */
    public function validate(array $design): array {
        $errors = [];
        $page = $this->pageDefaults($design['page'] ?? []);
        if ($page['bodyTop'] < $page['bodyTopFollowing']) {
            $errors[] = 'BODY_TOP_BELOW_FOLLOWING';
        }
        if ($page['bodyTop'] + $page['bodyBottom'] > 250) {
            $errors[] = 'BODY_TOO_SMALL';
        }
        if ($page['marginLeft'] + $page['marginRight'] > 150) {
            $errors[] = 'MARGINS_TOO_WIDE';
        }
        foreach (($design['blocks'] ?? []) as $block) {
            if (!is_array($block) || empty($block['type'])) {
                $errors[] = 'BLOCK_INVALID';
                break;
            }
        }
        return $errors;
    }

    // ===== Seite =====

    private function pageDefaults(array $page): array {
        $num = fn($key, $default) => isset($page[$key]) && is_numeric($page[$key]) ? (float)$page[$key] : $default;
        return [
            'marginLeft'       => $num('marginLeft', 20),
            'marginRight'      => $num('marginRight', 20),
            'bodyTop'          => $num('bodyTop', 98),
            'bodyTopFollowing' => $num('bodyTopFollowing', 35),
            'bodyBottom'       => $num('bodyBottom', 40),
            'fontSize'         => max(6, min(14, $num('fontSize', 10))),
            'font'             => ($page['font'] ?? 'sans') === 'serif' ? 'serif' : 'sans',
            'textColor'        => $this->hex($page['textColor'] ?? '', '222222'),
            'accentColor'      => $this->hex($page['accentColor'] ?? '', '1F4E79'),
            'foldMarks'        => in_array($page['foldMarks'] ?? '', ['A', 'B'], true) ? $page['foldMarks'] : '',
            'punchMark'        => !empty($page['punchMark']),
            'background'       => $this->imagePath($page['background'] ?? ''),
            'backgroundPages'  => ($page['backgroundPages'] ?? 'all') === 'first' ? 'first' : 'all',
            'currency'         => in_array($page['currency'] ?? '', ['euro', 'EUR', 'none'], true) ? $page['currency'] : 'euro',
        ];
    }

    private function preamble(array $page): string {
        $font = $page['font'] === 'serif'
            ? "\\usepackage{lmodern}"
            : "\\usepackage{helvet}\n\\renewcommand{\\familydefault}{\\sfdefault}";

        return <<<LATEX
\\documentclass[a4paper,fontsize={$this->pt($page['fontSize'])}pt]{scrartcl}
\\usepackage[utf8]{inputenc}
\\usepackage[T1]{fontenc}
\\usepackage[ngerman]{babel}
{$font}
\\usepackage{graphicx}
\\usepackage[table]{xcolor}
\\usepackage{longtable}
\\usepackage{array}
\\usepackage{calc}
\\usepackage{ifthen}
\\usepackage{eurosym}
\\usepackage{lastpage}
\\usepackage{eso-pic}
\\usepackage[a4paper,left={$this->mm($page['marginLeft'])}mm,right={$this->mm($page['marginRight'])}mm,top={$this->mm($page['bodyTopFollowing'])}mm,bottom={$this->mm($page['bodyBottom'])}mm,nohead,nofoot]{geometry}
\\definecolor{oserpText}{HTML}{{$page['textColor']}}
\\definecolor{oserpAccent}{HTML}{{$page['accentColor']}}
\\pagestyle{empty}
\\setlength{\\parindent}{0pt}
\\setlength{\\parskip}{0pt}
\\setlength{\\LTleft}{0pt}
\\setlength{\\LTright}{0pt}
\\setlength{\\LTpre}{2mm}
\\setlength{\\LTpost}{2mm}
% Baustein an der Position (x, y) in mm von der linken oberen Ecke, Breite w, Höhe h
\\newcommand{\\oserpBlock}[5]{\\put(\\LenToUnit{#1mm},\\LenToUnit{-#2mm}){\\raisebox{-\\height}{\\parbox[t][#4mm][t]{#3mm}{\\color{oserpText}#5}}}}
LATEX;
    }

    private function bodyFont(array $page): string {
        return sprintf('\\color{oserpText}\\fontsize{%spt}{%spt}\\selectfont', $this->pt($page['fontSize']), $this->pt($page['fontSize'] * 1.25));
    }

    // ===== Bausteine (eso-pic) =====

    /**
     * Bausteine für alle Seiten und für Folgeseiten, dazu Hintergrund und Marken
     */
    private function shipoutBlocks(array $blocks, array $page): string {
        $all = [];
        $following = [];

        if ($page['background'] !== '' && $page['backgroundPages'] === 'all') {
            $all[] = $this->backgroundLatex($page['background']);
        }
        $all[] = $this->marksLatex($page);

        foreach ($blocks as $block) {
            $pages = $block['pages'] ?? 'all';
            if ($pages === 'first') continue;
            $latex = $this->blockLatex($block, $page);
            if ($latex === '') continue;
            if ($pages === 'following') {
                $following[] = $latex;
            } else {
                $all[] = $latex;
            }
        }

        $all = array_filter($all);
        if (empty($all) && empty($following)) return '';

        $out = "\\AddToShipoutPictureBG{%\n\\AtPageUpperLeft{%\n";
        $out .= implode("\n", $all);
        if (!empty($following)) {
            $out .= "\n\\ifnum\\value{page}>1\n" . implode("\n", $following) . "\n\\fi";
        }
        $out .= "\n}}";
        return $out;
    }

    /**
     * Bausteine nur für die erste Seite (gesternte Form wirkt auf die aktuelle Seite)
     */
    private function firstPageBlocks(array $blocks, array $page): string {
        $first = [];
        if ($page['background'] !== '' && $page['backgroundPages'] === 'first') {
            $first[] = $this->backgroundLatex($page['background']);
        }
        foreach ($blocks as $block) {
            if (($block['pages'] ?? 'all') !== 'first') continue;
            $latex = $this->blockLatex($block, $page);
            if ($latex !== '') $first[] = $latex;
        }
        if (empty($first)) return '';
        return "\\AddToShipoutPictureBG*{%\n\\AtPageUpperLeft{%\n" . implode("\n", $first) . "\n}}";
    }

    private function backgroundLatex(string $image): string {
        return sprintf('\\put(0,\\LenToUnit{-\\paperheight}){\\includegraphics[width=\\paperwidth,height=\\paperheight]{%s}}', $image);
    }

    /**
     * Falzmarken (DIN 5008 Form A: 87/192 mm, Form B: 105/210 mm) und Lochmarke (148,5 mm)
     */
    private function marksLatex(array $page): string {
        $marks = [];
        if ($page['foldMarks'] === 'A') $marks = [87, 192];
        if ($page['foldMarks'] === 'B') $marks = [105, 210];
        $out = [];
        foreach ($marks as $y) {
            $out[] = sprintf('\\put(\\LenToUnit{3mm},\\LenToUnit{-%smm}){\\color{gray}\\rule{5mm}{0.3pt}}', $this->mm($y));
        }
        if ($page['punchMark']) {
            $out[] = '\\put(\\LenToUnit{3mm},\\LenToUnit{-148.5mm}){\\color{gray}\\rule{8mm}{0.3pt}}';
        }
        return implode("\n", $out);
    }

    /**
     * LaTeX eines einzelnen Bausteins
     */
    private function blockLatex(array $block, array $page): string {
        $x = $this->mm($block['x'] ?? 0);
        $y = $this->mm($block['y'] ?? 0);
        $w = $this->mm(max(1, $block['w'] ?? 10));
        $h = $this->mm(max(1, $block['h'] ?? 10));
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        $type = $block['type'] ?? 'text';

        switch ($type) {
            case 'text':
                $content = $this->textContent($props, $page, (float)$w);
                break;
            case 'infobox':
                $content = $this->infoboxContent($props, $page, (float)$w);
                break;
            case 'image':
                $image = $this->imagePath($props['image'] ?? '');
                if ($image === '') return '';
                $align = $this->alignCommand($props['align'] ?? 'left');
                $content = sprintf('%s\\includegraphics[width=%smm,height=%smm,keepaspectratio]{%s}', $align, $w, $h, $image);
                break;
            case 'line':
                $color = $this->hex($props['color'] ?? '', '');
                $thickness = max(0.1, min(5, (float)($props['thickness'] ?? 0.4)));
                $colorCmd = $color !== '' ? sprintf('\\color[HTML]{%s}', $color) : '\\color{oserpAccent}';
                // Vertikal, wenn der Baustein höher als breit ist
                $content = ((float)$h > (float)$w)
                    ? sprintf('%s\\rule{%spt}{%smm}', $colorCmd, $this->pt($thickness), $h)
                    : sprintf('%s\\rule{%smm}{%spt}', $colorCmd, $w, $this->pt($thickness));
                break;
            case 'rect':
                $fill = $this->hex($props['fill'] ?? '', '');
                $border = $this->hex($props['borderColor'] ?? '', '');
                if ($fill === '' && $border === '') return '';
                $inner = sprintf('\\rule{0pt}{%smm}\\hspace{%smm}', $h, $w);
                if ($fill !== '') {
                    $inner = sprintf('\\colorbox[HTML]{%s}{\\parbox[c][%smm][c]{%smm}{}}', $fill, $h, $w);
                }
                if ($border !== '') {
                    $inner = sprintf('{\\setlength{\\fboxsep}{0pt}\\setlength{\\fboxrule}{%spt}\\color[HTML]{%s}\\fbox{%s}}',
                        $this->pt(max(0.1, (float)($props['borderWidth'] ?? 0.4))), $border, $inner);
                }
                $content = $inner;
                break;
            case 'pagenumber':
                $props['text'] = $props['text'] ?? 'Seite {page} von {pages}';
                $content = $this->textContent($props, $page, (float)$w);
                break;
            case 'qrcode':
                // GiroCode entsteht pro Rechnung (print.php: buildGiroCodePng) — fehlt er, bleibt der Platz leer
                $content = sprintf('\\IfFileExists{giroqr.png}{\\includegraphics[width=%smm,height=%smm,keepaspectratio]{giroqr.png}}{}', $w, $h);
                break;
            default:
                return '';
        }

        $latex = sprintf('\\oserpBlock{%s}{%s}{%s}{%s}{%s}', $x, $y, $w, $h, $content);
        return $this->wrapCondition($latex, $props['condition'] ?? '');
    }

    // ===== Inhalte =====

    /**
     * Freitext mit Platzhaltern, Schriftgröße, Ausrichtung, Farbe, Unterstreichung
     */
    private function textContent(array $props, array $page, float $width): string {
        $size = isset($props['fontSize']) && is_numeric($props['fontSize']) ? (float)$props['fontSize'] : $page['fontSize'];
        $lineHeight = isset($props['lineHeight']) && is_numeric($props['lineHeight']) ? max(0.9, min(2.5, (float)$props['lineHeight'])) : 1.25;
        $font = sprintf('\\fontsize{%spt}{%spt}\\selectfont', $this->pt($size), $this->pt($size * $lineHeight));
        $color = $this->hex($props['color'] ?? '', '');
        if ($color !== '') $font .= sprintf('\\color[HTML]{%s}', $color);
        if (!empty($props['bold'])) $font .= '\\bfseries';
        if (!empty($props['italic'])) $font .= '\\itshape';

        $text = $this->paragraphs((string)($props['text'] ?? ''));
        $align = $this->alignCommand($props['align'] ?? 'left');

        // Breite 0 = Fließbereich (volle Zeilenbreite)
        $widthSpec = $width > 0 ? $this->mm($width) . 'mm' : '\\linewidth';
        $innerWidth = $width > 0 ? $this->mm($width - 2) . 'mm' : '\\dimexpr\\linewidth-2mm\\relax';

        $out = $font . $align . $text;
        if (!empty($props['underline'])) {
            $out .= sprintf('\\par\\vspace{0.4mm}\\color{oserpAccent}\\rule{%s}{0.3pt}', $widthSpec);
        }
        if (!empty($props['fill']) && $this->hex($props['fill'], '') !== '') {
            $out = sprintf('\\colorbox[HTML]{%s}{\\parbox{%s}{%s}}', $this->hex($props['fill'], ''), $innerWidth, $out);
        }
        return $out;
    }

    /**
     * Infobox: Beschriftung links, Wert rechts — Zeilen mit leerem Wert verschwinden per <%if%>
     */
    private function infoboxContent(array $props, array $page, float $width): string {
        $size = isset($props['fontSize']) && is_numeric($props['fontSize']) ? (float)$props['fontSize'] : $page['fontSize'];
        $font = sprintf('\\fontsize{%spt}{%spt}\\selectfont', $this->pt($size), $this->pt($size * 1.3));
        $labelWidth = isset($props['labelWidth']) && is_numeric($props['labelWidth']) ? max(10, (float)$props['labelWidth']) : 32;
        $valueWidth = max(10, $width - $labelWidth - 2);
        $labelBold = !empty($props['labelBold']);
        $valueAlign = ($props['valueAlign'] ?? 'right') === 'left' ? '\\raggedright' : '\\raggedleft';

        $rows = [];
        foreach (($props['rows'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $label = $this->inline((string)($row['label'] ?? ''));
            $value = $this->inline((string)($row['value'] ?? ''));
            if ($labelBold) $label = '\\textbf{' . $label . '}';
            $line = sprintf('%s & %s \\\\', $label, $value);
            // Bedingung: ausdrücklich gesetzt, sonst der erste Platzhalter des Werts
            $condition = trim((string)($row['condition'] ?? ''));
            if ($condition === '' && !empty($row['hideEmpty']) && preg_match('/<%\s*([A-Za-z0-9_.]+)/', (string)($row['value'] ?? ''), $m)) {
                $condition = $m[1];
            }
            $rows[] = $this->wrapCondition($line, $condition);
        }
        if (empty($rows)) return '';

        return sprintf("%s\\setlength{\\tabcolsep}{0pt}\\begin{tabular}{@{}p{%smm}@{\\hspace{2mm}}>{%s\\arraybackslash}p{%smm}@{}}\n%s\n\\end{tabular}",
            $font, $this->mm($labelWidth), $valueAlign, $this->mm($valueWidth), implode("\n", $rows));
    }

    // ===== Fließbereich =====

    private function section(array $section, array $page): string {
        $type = $section['type'] ?? 'text';
        $spaceAfter = isset($section['spaceAfter']) && is_numeric($section['spaceAfter']) ? (float)$section['spaceAfter'] : 3;
        $after = sprintf('\\par\\vspace{%smm}', $this->mm($spaceAfter));

        switch ($type) {
            case 'subject':
            case 'text':
                $props = $section;
                if ($type === 'subject' && !isset($props['bold'])) $props['bold'] = true;
                $latex = sprintf('\\begingroup %s\\par\\endgroup', $this->textContent($props, $page, 0));
                return $this->wrapCondition($latex . $after, $section['condition'] ?? '');
            case 'spacer':
                return sprintf('\\vspace{%smm}', $this->mm(max(0, (float)($section['height'] ?? 5))));
            case 'table':
                return $this->tableLatex($section, $page) . $after;
            case 'totals':
                return $this->totalsLatex($section, $page) . $after;
            case 'signature':
                return $this->signatureLatex($section, $page) . $after;
            default:
                return '';
        }
    }

    /**
     * Positionstabelle als longtable — bricht über Seiten um, Kopf wiederholt sich
     */
    private function tableLatex(array $section, array $page): string {
        $columns = [];
        foreach (($section['columns'] ?? []) as $col) {
            if (!is_array($col) || empty($col['key']) || !isset(self::TABLE_COLUMNS[$col['key']])) continue;
            if (isset($col['visible']) && !$col['visible']) continue;
            $columns[] = $col;
        }
        if (empty($columns)) return '';

        $size = isset($section['fontSize']) && is_numeric($section['fontSize']) ? (float)$section['fontSize'] : $page['fontSize'];
        $font = sprintf('\\fontsize{%spt}{%spt}\\selectfont', $this->pt($size), $this->pt($size * 1.25));
        $currency = $this->currency($page);
        $colsep = 1.5; // mm, je Seite einer Spalte
        $hasFill = false;

        // Spaltenbreiten: genau eine Spalte füllt den Rest (sonst die Bezeichnung oder die letzte)
        $fillIndex = null;
        foreach ($columns as $i => $col) {
            if (empty($col['width']) || !is_numeric($col['width'])) { $fillIndex = $i; break; }
        }
        if ($fillIndex === null) {
            foreach ($columns as $i => $col) if ($col['key'] === 'description') $fillIndex = $i;
        }
        if ($fillIndex === null) $fillIndex = count($columns) - 1;

        $fixedSum = 0;
        foreach ($columns as $i => $col) {
            if ($i !== $fillIndex) $fixedSum += (float)$col['width'];
        }
        $n = count($columns);
        $specs = [];
        $headers = [];
        $cells = [];
        foreach ($columns as $i => $col) {
            [$placeholder, $defaultAlign] = self::TABLE_COLUMNS[$col['key']];
            $align = $col['align'] ?? $defaultAlign;
            $alignCmd = ['right' => '\\raggedleft', 'center' => '\\centering'][$align] ?? '\\raggedright';
            $widthSpec = $i === $fillIndex
                ? sprintf('\\dimexpr\\textwidth-%smm-%smm\\relax', $this->mm($fixedSum), $this->mm($colsep * 2 * ($n - 1)))
                : sprintf('%smm', $this->mm((float)$col['width']));
            $specs[] = sprintf('>{%s\\arraybackslash}p{%s}', $alignCmd, $widthSpec);

            $label = $this->inline((string)($col['label'] ?? ''));
            $headers[] = !empty($section['headerBold']) || !isset($section['headerBold']) ? '\\textbf{' . $label . '}' : $label;

            if ($col['key'] === 'description') {
                $cell = !empty($section['descriptionBold']) ? '\\textbf{<%description%>}' : '<%description%>';
                if (!isset($section['longdescription']) || $section['longdescription']) {
                    $cell .= '<%if longdescription%>\\newline{\\footnotesize <%longdescription%>}<%end if%>';
                }
                if (!empty($section['serialnumber'])) {
                    $cell .= '<%if serialnumber%>\\newline{\\footnotesize ' . $this->inline((string)($section['serialnumberLabel'] ?? 'Seriennummer')) . ': <%serialnumber%>}<%end if%>';
                }
                $cells[] = $cell;
            } else {
                $cells[] = str_replace('CURRENCY', $currency, $placeholder);
            }
        }

        $rules = $section['rules'] ?? 'header';
        $headerFill = $this->hex($section['headerFill'] ?? '', '');
        $headRow = ($headerFill !== '' ? sprintf('\\rowcolor[HTML]{%s}', $headerFill) : '')
            . implode(' & ', $headers) . ' \\\\';
        $headRule = $rules !== 'none' ? '\\hline' : '';
        $rowRule = $rules === 'rows' ? '\\hline' : '';
        $zebra = !empty($section['zebra']) ? sprintf('<%%if __odd__%%>\\rowcolor[HTML]{%s}<%%end if%%>', $this->hex($section['zebraFill'] ?? '', 'F3F6FA')) : '';
        $continued = $this->inline((string)($section['continuedText'] ?? 'Fortsetzung auf der nächsten Seite'));

        $head = "{$headRule}\n{$headRow}\n{$headRule}\n";
        $body = sprintf("<%%foreach number%%>\n%s%s \\\\%s\n<%%end number%%>", $zebra, implode(' & ', $cells), $rowRule ? "\n" . $rowRule : '');

        return sprintf(
            "\\begingroup %s\\setlength{\\tabcolsep}{%smm}\\arrayrulecolor{oserpAccent}\n\\begin{longtable}{@{}%s@{}}\n%s\\endfirsthead\n%s\\endhead\n\\multicolumn{%d}{@{}r@{}}{\\footnotesize\\itshape %s}\\\\\n\\endfoot\n%s\\endlastfoot\n%s\n\\end{longtable}\\endgroup",
            $font, $this->mm($colsep), implode('', $specs), $head, $head, $n, $continued,
            $rules !== 'none' ? "\\hline\n" : '', $body
        );
    }

    /**
     * Summenblock rechtsbündig: Netto, Steuern (foreach tax), Gesamtbetrag
     */
    private function totalsLatex(array $section, array $page): string {
        $currency = $this->currency($page);
        $width = isset($section['width']) && is_numeric($section['width']) ? max(40, (float)$section['width']) : 80;
        $valueWidth = 30;
        $size = isset($section['fontSize']) && is_numeric($section['fontSize']) ? (float)$section['fontSize'] : $page['fontSize'];
        $font = sprintf('\\fontsize{%spt}{%spt}\\selectfont', $this->pt($size), $this->pt($size * 1.3));

        $rows = [];
        foreach (($section['rows'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $bold = !empty($row['bold']);
            $wrap = fn($s) => $bold ? '\\textbf{' . $s . '}' : $s;
            if (!empty($row['ruleAbove'])) $rows[] = '\\hline';
            if (($row['type'] ?? '') === 'tax') {
                $label = $this->inline((string)($row['label'] ?? '<%taxdescription%>'));
                $rows[] = sprintf('<%%foreach tax%%>%s & %s \\\\<%%end tax%%>', $wrap($label), $wrap('<%tax%>' . $currency));
                continue;
            }
            $label = $this->inline((string)($row['label'] ?? ''));
            $value = $this->inline((string)($row['value'] ?? ''));
            $value = preg_replace('/(<%\s*(subtotal|invtotal|ordtotal|quototal)\s*%>)/', '$1' . $currency, $value);
            $line = sprintf('%s & %s \\\\', $wrap($label), $wrap($value));
            $rows[] = $this->wrapCondition($line, $row['condition'] ?? '');
        }
        if (empty($rows)) return '';

        $labelWidth = $width - $valueWidth - 4;
        return sprintf("\\par\\nopagebreak\\begingroup %s\\arrayrulecolor{oserpAccent}\\setlength{\\tabcolsep}{2mm}\n\\begin{flushright}\\begin{tabular}{@{}p{%smm}>{\\raggedleft\\arraybackslash}p{%smm}@{}}\n%s\n\\end{tabular}\\end{flushright}\\endgroup",
            $font, $this->mm($labelWidth), $this->mm($valueWidth), implode("\n", $rows));
    }

    /**
     * Unterschriftsfelder: zwei Linien mit Beschriftung darunter
     */
    private function signatureLatex(array $section, array $page): string {
        $width = isset($section['width']) && is_numeric($section['width']) ? max(30, (float)$section['width']) : 60;
        $left = $this->inline((string)($section['left'] ?? ''));
        $right = $this->inline((string)($section['right'] ?? ''));
        $gap = max(0, 170 - $page['marginLeft'] - $page['marginRight'] - 2 * $width);
        $box = fn($label) => sprintf('\\begin{minipage}[t]{%smm}\\rule{%smm}{0.3pt}\\par{\\footnotesize %s}\\end{minipage}', $this->mm($width), $this->mm($width), $label);
        $out = '\\vspace{12mm}\\par\\noindent' . $box($left);
        if ($right !== '') $out .= sprintf('\\hspace{%smm}', $this->mm($gap)) . $box($right);
        return $out;
    }

    // ===== Textverarbeitung =====

    /**
     * Mehrzeiliger Text: Zeilen werden Absätze, Leerzeilen ein Abstand.
     * \par statt \\, damit ein leerer Platzhalter keine Leerzeile hinterlässt
     * (z. B. fehlende Abteilung in der Anschrift).
     */
    private function paragraphs(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                $out[] = '\\vspace{0.6\\baselineskip}';
            } else {
                $out[] = $this->inline($line) . '\\par';
            }
        }
        return implode("\n", $out);
    }

    /**
     * Einzelne Textzeile: Platzhalter bleiben, statischer Text wird escaped,
     * **fett**, *kursiv*, {page}, {pages} werden übersetzt.
     */
    private function inline(string $text): string {
        if ($text === '') return '';
        $parts = preg_split('/(<%.*?%>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = '';
        foreach ($parts as $part) {
            if (substr($part, 0, 2) === '<%') {
                $out .= $part;
                continue;
            }
            $out .= $this->staticText($part);
        }
        return $out;
    }

    private function staticText(string $text): string {
        // Marker für Auszeichnungen vor dem Escaping sichern
        $tokens = [];
        // Marker nur aus Buchstaben: das Escaping entfernt Steuerzeichen und
        // ersetzt Sonderzeichen, Buchstaben bleiben unangetastet
        $protect = function (string $latex) use (&$tokens): string {
            $key = 'QQOSERPTOKEN' . count($tokens) . 'QQ';
            $tokens[$key] = $latex;
            return $key;
        };
        $text = preg_replace_callback('/\{page\}/', fn() => $protect('\\thepage'), $text);
        $text = preg_replace_callback('/\{pages\}/', fn() => $protect('\\pageref{LastPage}'), $text);
        $text = preg_replace_callback('/\*\*(.+?)\*\*/', fn($m) => $protect('\\textbf{') . $m[1] . $protect('}'), $text);
        $text = preg_replace_callback('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', fn($m) => $protect('\\textit{') . $m[1] . $protect('}'), $text);

        $escaped = $this->engine->escapeLatexPublic($text);
        // Zeilenumbrüche kommen hier nicht mehr vor (paragraphs() hat zerlegt)
        return strtr($escaped, $tokens);
    }

    private function wrapCondition(string $latex, $condition): string {
        $condition = trim((string)$condition);
        if ($condition === '' || !preg_match('/^(not\s+)?[A-Za-z0-9_.]+$/', $condition)) return $latex;
        return sprintf('<%%if %s%%>%s<%%end if%%>', $condition, $latex);
    }

    // ===== Kleine Helfer =====

    private function alignCommand(string $align): string {
        // Leerzeichen am Ende: der folgende Text darf nicht mit dem Befehl verschmelzen
        return (['right' => '\\raggedleft', 'center' => '\\centering'][$align] ?? '\\raggedright') . ' ';
    }

    private function currency(array $page): string {
        return ['euro' => '\\,\\euro', 'EUR' => '\\,EUR', 'none' => ''][$page['currency']] ?? '\\,\\euro';
    }

    /** Hex-Farbe ohne '#' in Großbuchstaben, sonst Standard */
    private function hex($value, string $default): string {
        $value = ltrim(trim((string)$value), '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $value)) return strtoupper($value);
        if (preg_match('/^[0-9a-fA-F]{3}$/', $value)) {
            return strtoupper($value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2]);
        }
        return $default;
    }

    /** Bildpfad relativ zum Vorlagensatz — nur einfache Namen ohne Verzeichniswechsel */
    private function imagePath($value): string {
        $value = trim((string)$value);
        if ($value === '' || strpos($value, '..') !== false || $value[0] === '/') return '';
        if (!preg_match('/^[A-Za-z0-9_\-\.\/ ]+\.(png|jpg|jpeg|pdf)$/i', $value)) return '';
        return str_replace(' ', '\\space ', $value);
    }

    private function mm($value): string {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }

    private function pt($value): string {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }
}
