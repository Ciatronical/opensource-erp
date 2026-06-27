<?php
/**
 * API-Health-Check — verhindert "ganze API tot"-Regressionen.
 *
 * Prüft alle Backend-API-PHP-Dateien auf drei Fehlerklassen, die schon mehrfach
 * dazu geführt haben, dass eine komplette API (und damit z. B. die Autocomplete
 * in instructions/Positionen) lautlos ausfiel:
 *
 *   1. SYNTAX        — `php -l` über jede Datei.
 *   2. DANGLING-REQUIRE — jedes `require(_once) __DIR__.'...'` muss existieren.
 *        (Genau so brach einst `require_once '/asanetwork.php'` die ganze
 *         /api/lxcars/-API: jeder Call → Fatal Error → keine Vorschläge mehr.)
 *   3. OUTPUT-BEFORE-TAG — HTTP-Endpunkt-Dateien dürfen vor `<?php` nichts
 *        ausgeben (führender Whitespace landet sonst vor dem JSON → kann
 *        Antworten zerschießen / "headers already sent"). CLI-Skripte mit
 *        Shebang (#!) sind ausgenommen.
 *
 * Aufruf:  php tools/check-api-health.php
 * Exit 0 = alles gut, Exit 1 = Probleme gefunden (für CI / pre-commit geeignet).
 */

$root = dirname(__DIR__);
$apiDir = $root . '/backend/api';
$errors = [];
$checked = 0;

/** Alle PHP-Dateien unter backend/api (ohne vendor). */
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($apiDir, FilesystemIterator::SKIP_DOTS)
);
$files = [];
foreach ($rii as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php') continue;
    if (strpos($path, '/vendor/') !== false) continue;
    $files[] = $path;
}
sort($files);

foreach ($files as $path) {
    $rel = substr($path, strlen($root) + 1);
    $checked++;

    // 1. Syntax
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        $errors[] = "SYNTAX   $rel\n           " . trim(implode("\n", $out));
    }

    $src = file_get_contents($path);

    // 2. require/require_once __DIR__.'...': Ziel muss existieren
    // __DIR__ folgt in PHP dem realen Pfad (Symlinks aufgelöst) → realpath nutzen.
    if (preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*([\'"])(.*?)\1/', $src, $m)) {
        $baseDir = dirname(realpath($path));
        foreach ($m[2] as $relInc) {
            $target = $baseDir . $relInc;
            if (!file_exists($target)) {
                $errors[] = "REQUIRE  $rel\n           fehlende Datei: $relInc";
            }
        }
    }

    // 3. Output vor <?php (nur HTTP-Endpunkte; CLI-Skripte mit Shebang ausgenommen)
    if (substr($src, 0, 2) !== '#!' && substr($src, 0, 5) !== '<?php') {
        $prefix = substr($src, 0, strpos($src, '<?php') === false ? 20 : strpos($src, '<?php'));
        $errors[] = "OUTPUT   $rel\n           Zeichen vor <?php: " . json_encode($prefix);
    }
}

echo "API-Health-Check: $checked Dateien geprüft.\n";
if ($errors) {
    echo "\n❌ " . count($errors) . " Problem(e) gefunden:\n\n";
    echo implode("\n\n", $errors) . "\n";
    exit(1);
}
echo "✅ Keine Probleme.\n";
exit(0);
