<?php
/**
 * build-chart-master.php — erzeugt die versionierten Kontenrahmen-Stammdaten
 * backend/upstall/crm/chart_master/skr03.csv und skr04.csv.
 *
 * Eingaben (Zwischenartefakte, deterministisch erzeugt):
 *   $WORK/datev_skrNN.csv     accno|description   (offizielle DATEV-PDF-Extraktion)
 *   $WORK/existing_skrNN.psv  accno|charttype|category|link|taxkey_id|datevautomatik|...|description
 *                             (Bestandskonfiguration aus k9o — bekannte gute Werte)
 *
 * Ableitungsregeln (bewusst konservativ — kein stilles Steuer-Raten):
 *   - charttype = 'A' (Buchungskonto).
 *   - Bestehende accno: Konfiguration (category/link/taxkey/datevautomatik) aus dem Bestand
 *     ÜBERNEHMEN (bekannte gute Werte), Bezeichnung aus DATEV (offiziell).
 *   - Neue accno:
 *       category  : modale Kontoart des 100er-Blocks aus dem Bestand, Fallback 1000er-Block,
 *                   Fallback Schlagwort (Erlöse/Ertrag->I, Aufwand/Kosten->E), Fallback SKR-Konvention.
 *       Steuer    : Automatik NUR, wenn die Bezeichnung Steuersatz + Richtung eindeutig nennt:
 *                     "... 19 % Vorsteuer"  -> taxkey 9, link AP_amount, datevautomatik t
 *                     "... 7 % Vorsteuer"   -> taxkey 8, link AP_amount, datevautomatik t
 *                     "... 19 % USt|Umsatzsteuer" -> taxkey 3, link AR_amount, datevautomatik t
 *                     "... 7 % USt|Umsatzsteuer"  -> taxkey 2, link AR_amount, datevautomatik t
 *                   sonst taxkey 0, link '', datevautomatik f  (sicherer Default).
 *
 * Aufruf:  php scripts/build-chart-master.php [skr03|skr04|all]
 */

$OUT  = __DIR__ . '/../backend/upstall/crm/chart_master';
$WORK = getenv('CHART_WORK') ?: $OUT . '/sources';

$which = $argv[1] ?? 'all';
$skrs  = $which === 'all' ? ['skr03', 'skr04'] : [$which];

@mkdir($OUT, 0775, true);

foreach ($skrs as $skr) {
    buildOne($skr, $WORK, $OUT);
}

function buildOne(string $skr, string $WORK, string $OUT): void {
    $datevFile    = "$WORK/datev_$skr.csv";          // accno|description (validierte DATEV-PDF-Extraktion)
    $existingFile = "$WORK/existing_$skr.psv";
    $lewoFile     = "$WORK/lewo_$skr.json";          // {accno: description} (optional, sauber)
    if (!is_file($datevFile))    { fwrite(STDERR, "FEHLT: $datevFile\n");    exit(1); }
    if (!is_file($existingFile)) { fwrite(STDERR, "FEHLT: $existingFile\n"); exit(1); }

    // ── Bestandskonfiguration einlesen ──
    $existing = [];                 // accno => [category,link,taxkey,datevautomatik,description]
    foreach (file($existingFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $f = explode('|', $line);
        // Felder: accno|charttype|category|link|taxkey_id|datevautomatik|pos_eur|pos_bwa|pos_bilanz|pos_er|description
        $accno = trim($f[0]);
        if ($accno === '') continue;
        $existing[$accno] = [
            'category'       => trim($f[2] ?? ''),
            'link'           => trim($f[3] ?? ''),
            'taxkey'         => (int) trim($f[4] ?? '0'),
            'datevautomatik' => in_array(strtolower(trim($f[5] ?? 'f')), ['t', 'true', '1'], true),
            'description'    => trim($f[10] ?? ''),
        ];
    }

    // ── Empirische Kategorie-Maps (100er- und 1000er-Block, modal) ──
    $cat100 = modalCategoryMap($existing, 2);
    $cat1000 = modalCategoryMap($existing, 1);

    // ── DATEV-PDF-Konten einlesen (validierte Extraktion: accno|description) ──
    $pdf = [];                      // accno => description
    foreach (file($datevFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $p = explode('|', $line, 2);
        $accno = trim($p[0]);
        if (!preg_match('/^\d{3,4}$/', $accno)) continue;
        $pdf[$accno] = trim($p[1] ?? '');
    }

    // ── LEWO-Konten (saubere Bezeichnungen, optional) ──
    $lewo = [];                     // accno => description
    if (is_file($lewoFile)) {
        $j = json_decode(file_get_contents($lewoFile), true);
        if (is_array($j)) foreach ($j as $a => $d) {
            if (preg_match('/^\d{3,4}$/', (string) $a)) $lewo[(string) $a] = trim((string) $d);
        }
    }

    // ── Vereinigung: existing ∪ lewo ∪ pdf(ok) ──
    $accnos = array_unique(array_merge(array_keys($existing), array_keys($lewo), array_keys($pdf)));
    usort($accnos, fn($a, $b) => strcmp($a, $b));

    // Bezeichnungs-Priorität: existing (bekannt) > lewo (sauber) > pdf (offiziell, best effort)
    $descOf = function (string $a) use ($existing, $lewo, $pdf): string {
        if (isset($existing[$a]) && $existing[$a]['description'] !== '') return $existing[$a]['description'];
        if (isset($lewo[$a]) && $lewo[$a] !== '') return $lewo[$a];
        return $pdf[$a] ?? '';
    };

    // ── Zeilen erzeugen ──
    $rows = [];
    $stat = ['total' => 0, 'reused' => 0, 'new' => 0, 'auto' => 0, 'existing_only' => 0];
    foreach ($accnos as $accno) {
        $desc = $descOf($accno);
        $stat['total']++;

        if (isset($existing[$accno])) {
            // Bekannte gute Konfiguration übernehmen.
            $e = $existing[$accno];
            $rows[] = [$accno, $desc, 'A', $e['category'], $e['link'], $e['taxkey'], $e['datevautomatik'] ? 't' : 'f'];
            $stat['reused']++;
            if (!isset($lewo[$accno]) && !isset($pdf[$accno])) $stat['existing_only']++;
            continue;
        }

        // Neues Konto: Kategorie + (konservative) Steuerableitung.
        [$category]                          = deriveCategory($accno, $desc, $cat100, $cat1000, $skr);
        [$link, $taxkey, $auto]              = deriveTax($accno, $desc, $category);
        $rows[] = [$accno, $desc, 'A', $category, $link, $taxkey, $auto ? 't' : 'f'];
        $stat['new']++;
        if ($taxkey !== 0) $stat['auto']++;
    }

    // ── Schreiben ──
    $outFile = "$OUT/$skr.csv";
    $fh = fopen($outFile, 'w');
    fputcsv($fh, ['accno', 'description', 'charttype', 'category', 'link', 'taxkey', 'datevautomatik']);
    foreach ($rows as $r) fputcsv($fh, $r);
    fclose($fh);

    fprintf(STDOUT,
        "%s -> %s\n  gesamt=%d  uebernommen=%d (davon nur-Bestand=%d)  neu=%d  davon Automatik=%d\n",
        $skr, $outFile, $stat['total'], $stat['reused'], $stat['existing_only'], $stat['new'], $stat['auto']);
}

/** Modale Kontoart je Präfix (Länge $len) aus dem Bestand. */
function modalCategoryMap(array $existing, int $len): array {
    $counts = [];
    foreach ($existing as $accno => $e) {
        if ($e['category'] === '') continue;
        $key = substr($accno, 0, $len);
        $counts[$key][$e['category']] = ($counts[$key][$e['category']] ?? 0) + 1;
    }
    $map = [];
    foreach ($counts as $key => $cats) {
        arsort($cats);
        $map[$key] = array_key_first($cats);
    }
    return $map;
}

/** Kategorie eines neuen Kontos ableiten. */
function deriveCategory(string $accno, string $desc, array $cat100, array $cat1000, string $skr): array {
    $p2 = substr($accno, 0, 2);
    $p1 = substr($accno, 0, 1);
    if (isset($cat100[$p2]))  return [$cat100[$p2]];
    if (isset($cat1000[$p1])) return [$cat1000[$p1]];

    $d = mb_strtolower($desc);
    if (preg_match('/erlös|ertr|zinsertr|provisionsertr/u', $d)) return ['I'];
    if (preg_match('/aufwand|aufwendung|kosten|abschreibung|steuer/u', $d)) return ['E'];

    // SKR-Konvention als letzter Fallback.
    if ($skr === 'skr03') {
        $conv = ['0' => 'A', '1' => 'A', '2' => 'E', '3' => 'E', '4' => 'E', '8' => 'I', '9' => 'A'];
    } else { // skr04
        $conv = ['0' => 'A', '1' => 'A', '2' => 'A', '3' => 'A', '4' => 'I', '5' => 'E', '6' => 'E', '7' => 'E'];
    }
    return [$conv[$p1] ?? 'E'];
}

/**
 * Konservative Steuerableitung: Automatik nur bei eindeutigem Satz+Richtung im Namen.
 * @return array [link, taxkey(int), auto(bool)]
 */
function deriveTax(string $accno, string $desc, string $category): array {
    $d = mb_strtolower($desc);

    $has19 = (bool) preg_match('/19\s*%/u', $d);
    $has7  = (bool) preg_match('/\b7\s*%/u', $d);
    $isVorsteuer = (bool) preg_match('/vorsteuer|vost\b/u', $d);
    $isUst       = (bool) preg_match('/\bust\b|umsatzsteuer/u', $d);

    if ($isVorsteuer && $has19) return ['AP_amount', 9, true];
    if ($isVorsteuer && $has7)  return ['AP_amount', 8, true];
    if ($isUst && $has19)       return ['AR_amount', 3, true];
    if ($isUst && $has7)        return ['AR_amount', 2, true];

    return ['', 0, false];
}
