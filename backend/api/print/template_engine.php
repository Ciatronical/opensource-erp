<?php
// backend/api/print/template_engine.php

/**
 * LaTeX Template Engine -- kompatibel mit kivitendo <%...%> Syntax
 *
 * Portiert die Logik aus SL::Template::Simple + SL::Template::LaTeX nach PHP.
 *
 * Features:
 * - Variable: <%variable%>, <%variable NOFORMAT%>, <%variable NOESCAPE%>
 * - Bedingungen: <%if var%>...<%else%>...<%end if%>
 * - Vergleiche: <%if var == "value"%>, <%if not var%>, <%if var =~ "pattern"%>
 * - Schleifen: <%foreach arrayname%>...<%end arrayname%>
 * - Magische Schleifenvariablen: __first__, __last__, __odd__, __counter__
 * - Punkt-Notation: <%object.property%>
 * - HTML-zu-LaTeX Konvertierung fuer Notes-Felder
 */
class LaTeXTemplateEngine {

    private $tagStart = '<%';
    private $tagEnd = '%>';
    private $tagStartQm;
    private $tagEndQm;
    private $substituteVarsRe;

    private $variables = [];
    private $arrays = [];
    private $templateDir = '';
    private $tmpDir = '/tmp';
    private $error = null;

    // HTML-Tags zu LaTeX Mappings (aus SL::Template::LaTeX)
    private static $htmlReplace = [
        '</p>'      => "\\par\n",
        '<ul>'      => "\\begin{itemize} ",
        '</ul>'     => "\\end{itemize} ",
        '<ol>'      => "\\begin{enumerate} ",
        '</ol>'     => "\\end{enumerate} ",
        '<li>'      => "\\item ",
        '</li>'     => " ",
        '<b>'       => "\\textbf{",
        '</b>'      => "}",
        '<strong>'  => "\\textbf{",
        '</strong>' => "}",
        '<i>'       => "\\textit{",
        '</i>'      => "}",
        '<em>'      => "\\textit{",
        '</em>'     => "}",
        '<u>'       => "\\underline{",
        '</u>'      => "}",
        '<s>'       => "\\sout{",
        '</s>'      => "}",
        '<sub>'     => "\\textsubscript{",
        '</sub>'    => "}",
        '<sup>'     => "\\textsuperscript{",
        '</sup>'    => "}",
        '<br/>'     => "\\newline ",
        '<br>'      => "\\newline ",
    ];

    /**
     * Konstruktor
     *
     * @param string $templateDir Verzeichnis mit den .tex Templates
     * @param string $tmpDir Temporaeres Verzeichnis fuer LaTeX-Kompilierung
     */
    public function __construct(string $templateDir, string $tmpDir = '/tmp') {
        $this->templateDir = rtrim($templateDir, '/');
        $this->tmpDir = rtrim($tmpDir, '/');
        $this->setTagStyle('<%', '%>');
    }

    /**
     * Setzt Tag-Style (z.B. fuer custom tag-style config in Templates)
     */
    private function setTagStyle(string $start, string $end) {
        $this->tagStart = $start;
        $this->tagEnd = $end;
        $this->tagStartQm = preg_quote($start, '/');
        $this->tagEndQm = preg_quote($end, '/');
        $this->substituteVarsRe = '/' . $this->tagStartQm . '(.+?)' . $this->tagEndQm . '/';
    }

    /**
     * Setzt flache Variablen (Key-Value)
     */
    public function setVariables(array $vars) {
        $this->variables = $vars;
    }

    /**
     * Setzt Array-Variablen fuer foreach-Schleifen
     * Format: ['varname' => [val0, val1, val2, ...], ...]
     */
    public function setArrays(array $arrays) {
        $this->arrays = $arrays;
    }

    /**
     * Gibt den letzten Fehler zurueck
     */
    public function getError(): ?string {
        return $this->error;
    }

    // ===== LaTeX Escaping =====

    /**
     * Escaped LaTeX-Sonderzeichen (Port von quote_special_chars in SL::Locale)
     */
    private function escapeLatex(string $value): string {
        // Reihenfolge wichtig: Backslash zuerst
        $value = str_replace('\\', '\\textbackslash{}', $value);
        $value = str_replace(
            ['&',  '%',  '$',  '#',  '_',  '{',  '}'],
            ['\\&', '\\%', '\\$', '\\#', '\\_', '\\{', '\\}'],
            $value
        );
        $value = str_replace('~', '\\textasciitilde{}', $value);
        $value = str_replace('^', '\\textasciicircum{}', $value);
        // Steuerzeichen entfernen
        $value = preg_replace('/[\x00-\x1f]/', '', $value);
        return $value;
    }

    /**
     * Öffentlicher Zugriff auf LaTeX-Escaping
     */
    public function escapeLatexPublic(string $value): string {
        return $this->escapeLatex($value);
    }

    /**
     * Konvertiert HTML-Markup (aus Notes/tiptap) nach LaTeX
     * Port von _format_html aus SL::Template::LaTeX
     */
    private function formatHtmlToLatex(string $content): string {
        // Whitespace normalisieren
        $content = preg_replace('/\r+/', '', $content);
        $content = preg_replace('/\n+/', ' ', $content);
        $content = preg_replace('/(?:&nbsp;|\s)+/', ' ', $content);
        $content = preg_replace('/(?:&nbsp;|\s)+$/', '', $content);
        // Leere Tags am Ende entfernen
        $content = preg_replace('/(?:<br\/?>)+$/', '', $content);
        $content = preg_replace('/<ul>\s*<\/ul>|<ol>\s*<\/ol>/i', '', $content);
        $content = preg_replace('/(?:<p>\s*<\/p>\s*)+\z/im', '', $content);

        // In Tags und Text aufteilen, Tags ersetzen, Text escapen
        $parts = preg_split('/(<.*?>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $result = '';
        foreach ($parts as $part) {
            if (substr($part, 0, 1) === '<') {
                $tag = preg_replace('/ +/', '', $part);
                $result .= self::$htmlReplace[$tag] ?? '';
            } else {
                $result .= $this->escapeLatex(html_entity_decode($part, ENT_QUOTES, 'UTF-8'));
            }
        }

        // Trailing newlines entfernen
        $result = preg_replace('/(?:[\n\s]|\\\\newline\s)+$/', '', $result);
        $result = preg_replace('/^\s+/', '', $result);

        return $result;
    }

    // ===== Variable Resolution =====

    /**
     * Liest eine Variable (Port von _get_loop_variable aus SL::Template::Simple)
     *
     * Unterstuetzt Punkt-Notation fuer verschachtelte Objekte: object.property
     * Bei Schleifen: Indiziert in das Array mit den aktuellen Indices
     */
    private function getLoopVariable(string $var, bool $getArray, array $indices) {
        $methods = [];
        if (strpos($var, '.') !== false) {
            $parts = explode('.', $var);
            $var = array_shift($parts);
            $methods = $parts;
        }

        // Array-Zugriff oder flache Variable?
        if (($getArray || !empty($indices)) && isset($this->arrays[$var]) && is_array($this->arrays[$var])) {
            $value = $this->arrays[$var];
        } else {
            $value = $this->variables[$var] ?? '';
        }

        // Durch Indices navigieren (fuer verschachtelte Schleifen)
        foreach ($indices as $idx) {
            if (!is_array($value)) break;
            $value = $value[$idx] ?? '';
        }

        // Punkt-Notation: in Sub-Keys navigieren
        foreach ($methods as $method) {
            if (is_array($value)) {
                $value = $value[$method] ?? '';
            } else {
                $value = '';
                break;
            }
        }

        return $value;
    }

    // ===== Template Parsing =====

    /**
     * Ersetzt <%variable%> Tags durch Werte (Port von substitute_vars)
     */
    private function substituteVars(string $text, array $indices = []): string {
        while (preg_match($this->substituteVarsRe, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $fullMatch = $matches[0][0];
            $offset = $matches[0][1];
            $inner = trim($matches[1][0]);

            // Variable + Optionen trennen
            $parts = preg_split('/\s+/', $inner);
            $varName = $parts[0];
            $options = array_flip(array_slice($parts, 1));

            $value = $this->getLoopVariable($varName, false, $indices);
            if (is_array($value)) {
                $value = count($value);
            }
            $value = (string)$value;

            if (!isset($options['NOESCAPE'])) {
                // Pruefen ob HTML enthalten
                if ($value !== strip_tags($value)) {
                    $value = $this->formatHtmlToLatex($value);
                } else {
                    $value = $this->escapeLatex($value);
                }
            }

            $text = substr_replace($text, $value, $offset, strlen($fullMatch));
        }

        return $text;
    }

    /**
     * Findet das passende <%end%> Tag (Port von find_end aus SL::Template::LaTeX)
     *
     * Beruecksichtigt verschachtelte if/foreach Bloecke.
     * Behandelt <%else%> als Block-Trenner auf Tiefe 1.
     *
     * @return array|null [$block, $remainingText] oder null bei Fehler
     */
    private function findEnd(string $text, int $pos, ?string $var, bool $not): ?array {
        $tagStartLen = strlen($this->tagStart);
        $depth = 1;

        while ($pos < strlen($text)) {
            $pos++;
            if (substr($text, $pos - 1, $tagStartLen) !== $this->tagStart) {
                continue;
            }

            $keywordPos = $pos - 1 + $tagStartLen;

            if (substr($text, $keywordPos, 2) === 'if' || substr($text, $keywordPos, 7) === 'foreach') {
                $depth++;
            } elseif (substr($text, $keywordPos, 4) === 'else' && $depth === 1) {
                // else-Block: Text vor else zurueckgeben, Rest als neuen if-Block umbauen
                if (!$var) {
                    $this->error = "{$this->tagStart}else{$this->tagEnd} outside of if block.";
                    return null;
                }

                $block = substr($text, 0, $pos - 1);
                $text = substr($text, $pos - 1);
                // else-Tag entfernen
                $text = preg_replace('/^' . $this->tagStartQm . '.+?' . $this->tagEndQm . '/', '', $text);
                // In invertierten if-Block umwandeln
                $notStr = $not ? " " : "not ";
                $text = $this->tagStart . 'if ' . $notStr . $var . $this->tagEnd . $text;

                return [$block, $text];

            } elseif (substr($text, $keywordPos, 3) === 'end') {
                $depth--;
                if ($depth === 0) {
                    $block = substr($text, 0, $pos - 1);
                    $text = substr($text, $pos - 1);
                    // end-Tag entfernen
                    $text = preg_replace('/^' . $this->tagStartQm . '.+?' . $this->tagEndQm . '/', '', $text);
                    return [$block, $text];
                }
            }
        }

        return null;
    }

    /**
     * Verarbeitet <%if ...%> Block (Port von _parse_block_if)
     *
     * Unterstuetzt: <%if var%>, <%if not var%>, <%if var == "val"%>, <%if var != "val"%>, <%if var =~ "pattern"%>
     *
     * @param string &$contents Restlicher Template-Inhalt (wird modifiziert)
     * @param string &$newContents Bisherige Ausgabe (wird ergaenzt)
     * @param int $posIf Position des <%if im Text
     * @param array $indices Aktuelle Schleifen-Indices
     * @return bool Erfolg
     */
    private function parseBlockIf(string &$contents, string &$newContents, int $posIf, array $indices): bool {
        // Text vor dem if-Block substituieren und anhaengen
        $newContents .= $this->substituteVars(substr($contents, 0, $posIf), $indices);
        $contents = substr($contents, $posIf);

        // if-Tag parsen: <%if [not] varname [== "value"]%>
        $pattern = '/^(' . $this->tagStartQm . 'if'
            . '\s+'
            . '(not\b|\!)?\s*'           // Negierung
            . '(\b.+?\b)'                // Variablenname
            . '('                         // Optionaler Vergleich
            .   '\s*'
            .   '([!=])'                 // Negierung des Vergleichs
            .   '([=~])'                 // Art des Vergleichs
            .   '\s*'
            .   '(?:"(.*?)"|(\b.+?\b))'  // Quoted String oder Bareword
            . ')?'
            . '\s*'
            . $this->tagEndQm . ')/x';

        if (!preg_match($pattern, $contents, $m)) {
            $this->error = "Malformed {$this->tagStart}if{$this->tagEnd}.";
            return false;
        }

        $fullTag = $m[1];
        $not = !empty($m[2]);
        $var = $m[3];
        $comparison = $m[4] ?? null;
        $operatorNeg = $m[5] ?? null;
        $operatorType = $m[6] ?? null;
        $quotedWord = $m[7] ?? null;
        $bareword = $m[8] ?? null;

        if ($operatorNeg === '!') {
            $not = !$not;
        }

        // Tag aus contents entfernen
        $contents = substr($contents, strlen($fullTag));

        // Block bis <%end%> finden (inkl. else-Handling)
        $varComparison = $var . ($comparison ?? '');
        $result = $this->findEnd($contents, 0, $varComparison, $not);
        if ($result === null) {
            if (!$this->error) {
                $this->error = "Unclosed {$this->tagStart}if{$this->tagEnd}.";
            }
            return false;
        }
        [$block, $contents] = $result;

        // Bedingung auswerten
        $value = $this->getLoopVariable($var, false, $indices);
        if (is_array($value)) {
            $value = count($value);
        }

        $hit = false;
        if ($operatorType) {
            $compareTo = $bareword ? $this->getLoopVariable($bareword, false, $indices) : $quotedWord;
            if ($operatorType === '=') {
                // Stringvergleich
                $hit = ($not && $value !== $compareTo) || (!$not && $value === $compareTo);
            } else {
                // Regex-Match
                $match = @preg_match('/' . $compareTo . '/i', (string)$value);
                $hit = ($not && !$match) || (!$not && $match);
            }
        } else {
            // Einfacher Wahrheitstest
            $hit = ($not && !$value) || (!$not && $value);
        }

        if ($hit) {
            $newText = $this->parseBlock($block, $indices);
            if ($newText === null) return false;
            $newContents .= $newText;
        }

        return true;
    }

    /**
     * Verarbeitet <%foreach var%> Schleife (Port von parse_foreach)
     *
     * Setzt magische Variablen: __first__, __last__, __odd__, __counter__
     */
    private function parseForEach(string $var, string $block, array $indices): string {
        $newContents = '';
        $ary = $this->getLoopVariable($var, true, $indices);

        if (!is_array($ary)) {
            return $newContents;
        }

        $count = count($ary);
        for ($i = 0; $i < $count; $i++) {
            // Magische Variablen setzen
            $this->variables['__first__'] = ($i === 0) ? 1 : 0;
            $this->variables['__last__'] = ($i + 1 === $count) ? 1 : 0;
            $this->variables['__odd__'] = (($i + 1) % 2 === 1) ? 1 : 0;
            $this->variables['__counter__'] = $i + 1;

            $newText = $this->parseBlock($block, array_merge($indices, [$i]));
            if ($newText === null) return '';
            $newContents .= $newText;
        }

        // Magische Variablen aufraeumen
        unset(
            $this->variables['__first__'],
            $this->variables['__last__'],
            $this->variables['__odd__'],
            $this->variables['__counter__']
        );

        return $newContents;
    }

    /**
     * Hauptparser -- verarbeitet einen Template-Block rekursiv
     * Port von parse_block aus SL::Template::Simple
     *
     * Sucht nach <%if und <%foreach Tags, verarbeitet den naechsten.
     * Alles andere wird durch substituteVars geschickt.
     */
    private function parseBlock(string $contents, array $indices = []): ?string {
        $newContents = '';

        while ($contents !== '') {
            $posIf = strpos($contents, $this->tagStart . 'if');
            $posForeach = strpos($contents, $this->tagStart . 'foreach');

            // Keine weiteren Kontrollstrukturen? Rest substituieren
            if ($posIf === false && $posForeach === false) {
                $newContents .= $this->substituteVars($contents, $indices);
                break;
            }

            // foreach kommt zuerst (oder if nicht vorhanden)
            if ($posIf === false || ($posForeach !== false && $posIf > $posForeach)) {
                $newContents .= $this->substituteVars(substr($contents, 0, $posForeach), $indices);
                $contents = substr($contents, $posForeach);

                // foreach-Tag parsen
                $foreachPattern = '/^(' . $this->tagStartQm . 'foreach\s+(.+?)' . $this->tagEndQm . ')/';
                if (!preg_match($foreachPattern, $contents, $m)) {
                    $this->error = "Malformed {$this->tagStart}foreach{$this->tagEnd}.";
                    return null;
                }

                $var = $m[2];
                $contents = substr($contents, strlen($m[1]));

                // Block bis <%end%> finden
                $result = $this->findEnd($contents, 0, null, false);
                if ($result === null) {
                    if (!$this->error) {
                        $this->error = "Unclosed {$this->tagStart}foreach{$this->tagEnd}.";
                    }
                    return null;
                }
                [$block, $contents] = $result;

                $newText = $this->parseForEach($var, $block, $indices);
                $newContents .= $newText;

            } else {
                // if kommt zuerst
                if (!$this->parseBlockIf($contents, $newContents, $posIf, $indices)) {
                    return null;
                }
            }
        }

        return $newContents;
    }

    // ===== Public API =====

    /**
     * Liest und verarbeitet ein Template
     *
     * @param string $templateFile Dateiname relativ zum templateDir (z.B. 'invoice.tex')
     * @return string|false Verarbeiteter LaTeX-String oder false bei Fehler
     */
    public function parse(string $templateFile) {
        $filePath = $this->templateDir . '/' . basename($templateFile);
        if (!file_exists($filePath)) {
            $this->error = "Template not found: $filePath";
            return false;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->error = "Cannot read template: $filePath";
            return false;
        }

        // Erste Zeile pruefen auf tag-style config (wie in kivitendo)
        $firstLine = strtok($content, "\n");
        if (preg_match('/([^\s]+)set-tag-style([^\s]+)/', $firstLine, $m)) {
            if ($m[1] !== $m[2]) {
                $this->setTagStyle($m[1], $m[2]);
            }
        }

        $result = $this->parseBlock($content);
        if ($result === null) {
            return false;
        }

        return $result;
    }

    /**
     * Kompiliert einen LaTeX-String zu PDF
     *
     * @param string $latexContent Vollstaendiger LaTeX-Inhalt
     * @return string|false Pfad zur PDF-Datei oder false bei Fehler
     */
    public function compile(string $latexContent) {
        // Einzigartiges Temp-Verzeichnis erstellen
        $workDir = $this->tmpDir . '/oserp_print_' . uniqid('', true);
        if (!mkdir($workDir, 0755, true)) {
            $this->error = "Cannot create temp directory: $workDir";
            return false;
        }

        // Template-Ressourcen verlinken (Bilder, firma/, .sty Dateien)
        $this->symlinkTemplateResources($workDir);

        // .tex Datei schreiben
        $texFile = $workDir . '/document.tex';
        file_put_contents($texFile, $latexContent);

        // TEXINPUTS setzen damit \input{} funktioniert
        $texinputs = $workDir . ':' . $this->templateDir . ':';

        // latexmk ausfuehren
        $cmd = sprintf(
            'cd %s && PATH=/usr/local/bin:/usr/bin:/bin TEXINPUTS=%s /usr/bin/latexmk -pdf -interaction=nonstopmode document.tex 2>&1',
            escapeshellarg($workDir),
            escapeshellarg($texinputs)
        );

        exec($cmd, $output, $returnCode);

        $pdfFile = $workDir . '/document.pdf';
        if (!file_exists($pdfFile)) {
            $this->error = "LaTeX compilation failed (exit code: $returnCode)\n";
            $this->error .= implode("\n", array_slice($output, -30));
            // LaTeX-Log anhaengen falls vorhanden
            $logFile = $workDir . '/document.log';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                // Nur die letzten 50 Zeilen des Logs
                $logLines = explode("\n", $logContent);
                $this->error .= "\n\n--- LaTeX Log (last 50 lines) ---\n";
                $this->error .= implode("\n", array_slice($logLines, -50));
            }
            return false;
        }

        return $pdfFile;
    }

    /**
     * Verlinkt Template-Ressourcen ins Arbeitsverzeichnis
     */
    private function symlinkTemplateResources(string $workDir) {
        // firma/ Verzeichnis
        $firmaDir = $this->templateDir . '/firma';
        if (is_dir($firmaDir)) {
            symlink($firmaDir, $workDir . '/firma');
        }

        // images/ Verzeichnis (fuer Hintergrundbilder etc.)
        $imagesDir = $this->templateDir . '/images';
        if (is_dir($imagesDir)) {
            symlink($imagesDir, $workDir . '/images');
        }

        // .sty Dateien und Bilder
        $extensions = ['*.sty', '*.png', '*.jpg', '*.pdf'];
        foreach ($extensions as $ext) {
            foreach (glob($this->templateDir . '/' . $ext) as $file) {
                $basename = basename($file);
                if (!file_exists($workDir . '/' . $basename)) {
                    symlink($file, $workDir . '/' . $basename);
                }
            }
        }
    }

    /**
     * Kompletter Workflow: Template parsen + LaTeX kompilieren
     *
     * @param string $templateFile Template-Dateiname
     * @return string|false Pfad zur PDF-Datei oder false bei Fehler
     */
    public function generatePDF(string $templateFile) {
        $latex = $this->parse($templateFile);
        if ($latex === false) return false;

        return $this->compile($latex);
    }

    /**
     * Raeumt temporaere Dateien auf
     *
     * @param string $pdfPath Pfad der vom compile/generatePDF zurueckgegeben wurde
     */
    public function cleanup(string $pdfPath) {
        $workDir = dirname($pdfPath);
        if (strpos($workDir, 'oserp_print_') !== false) {
            $this->removeDir($workDir);
        }
    }

    /**
     * Loescht ein Verzeichnis rekursiv
     */
    private function removeDir(string $dir) {
        if (!is_dir($dir)) return;
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
