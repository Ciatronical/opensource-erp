<?php
// backend/api/developer-tools/api-tester.php

/**
 * Gibt alle API-Ordner zurück (Ordner unter backend/api)
 */
function getApiFolders($data) {
    $apiDir = __DIR__ . '/../';
    $folders = [];

    if (!is_dir($apiDir)) {
        resultInfo(false, 'API-Verzeichnis nicht gefunden');
        return;
    }

    $items = scandir($apiDir);

    foreach ($items as $item) {
        // Überspringe . und .. und keine Ordner
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $apiDir . $item;

        // Nur Ordner
        if (is_dir($fullPath)) {
            $folders[] = $item;
        }
    }

    sort($folders);

    resultInfo(true, '', ['folders' => $folders]);
}

/**
 * Gibt alle Funktionen aus einem API-Ordner zurück
 * Liest die index.php und extrahiert alle inkludierten Dateien und deren Funktionen
 */
function getApiFunctions($data) {
    if (!isset($data['folder'])) {
        resultInfo(false, 'Kein Ordner angegeben');
        return;
    }

    $folder = $data['folder'];
    $apiDir = __DIR__ . '/../' . $folder . '/';
    $indexFile = $apiDir . 'index.php';

    if (!file_exists($indexFile)) {
        resultInfo(false, 'index.php nicht gefunden in Ordner: ' . $folder);
        return;
    }

    // Lese index.php
    $indexContent = file_get_contents($indexFile);
    $functions = [];

    // Finde alle include/require Statements (auch require_once mit __DIR__)
    preg_match_all('/(?:include|require)(?:_once)?\s*(?:\()?(?:__DIR__\s*\.\s*)?[\'"]([\w\.\-\/]+)[\'"]/i', $indexContent, $includes);

    if (!empty($includes[1])) {
        foreach ($includes[1] as $includeFile) {
            // Entferne führenden Slash falls vorhanden
            $includeFile = ltrim($includeFile, '/');

            // Baue vollständigen Pfad
            $fullIncludePath = $apiDir . $includeFile;

            // writeLog("DEBUG: Prüfe Datei: $fullIncludePath");

            if (file_exists($fullIncludePath)) {
                // Überspringe inc.php
                if (basename($includeFile) === 'inc.php') {
                    continue;
                }

                // Lese die inkludierte Datei
                $fileContent = file_get_contents($fullIncludePath);

                // Extrahiere alle Funktionsnamen
                preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/i', $fileContent, $functionMatches);

                // writeLog("DEBUG: Gefundene Funktionen in $includeFile: " . implode(', ', $functionMatches[1]));

                if (!empty($functionMatches[1])) {
                    foreach ($functionMatches[1] as $functionName) {
                        // Überspringe interne Helper-Funktionen
                        if (!in_array($functionName, ['resultInfo', 'writeLog', 'ensureBackupDir', 'getDatabaseContext'])) {
                            $functions[] = $functionName;
                        }
                    }
                }
            } else {
                // writeLog("WARNING: Datei nicht gefunden: $fullIncludePath");
            }
        }
    }

    // Entferne Duplikate und sortiere
    $functions = array_unique($functions);
    sort($functions);

    // writeLog("DEBUG: Finale Funktionsliste für $folder: " . implode(', ', $functions));

    resultInfo(true, '', ['functions' => $functions]);
}

/**
 * Gibt Parameter-Informationen für eine Funktion zurück
 * Parst Docblocks und extrahiert @testdata Tag für Beispieldaten
 *
 * Unterstützt zwei Docblock-Formate:
 * 1. Detailliert: @param string $data['key'] Beschreibung
 * 2. Einfach: @param array $data mit separater Zeile "In $data['key'] steht..."
 */
function getFunctionParameters($data) {
    if (!isset($data['folder']) || !isset($data['function'])) {
        resultInfo(false, 'Ordner und Funktion müssen angegeben werden');
        return;
    }

    $folder = $data['folder'];
    $functionName = $data['function'];
    $apiDir = __DIR__ . '/../' . $folder . '/';
    $indexFile = $apiDir . 'index.php';

    if (!file_exists($indexFile)) {
        resultInfo(false, 'index.php nicht gefunden');
        return;
    }

    // Lese index.php und finde inkludierte Dateien
    $indexContent = file_get_contents($indexFile);
    preg_match_all('/(?:include|require)(?:_once)?\s*(?:\()?(?:__DIR__\s*\.\s*)?[\'"]([\w\.\-\/]+)[\'"]/i', $indexContent, $includes);

    $functionFound = false;
    $parameters = [];
    $exampleData = null;
    $hasDocblock = false;

    if (!empty($includes[1])) {
        foreach ($includes[1] as $includeFile) {
            $includeFile = ltrim($includeFile, '/');
            $fullIncludePath = $apiDir . $includeFile;

            if (file_exists($fullIncludePath) && basename($includeFile) !== 'inc.php') {
                $fileContent = file_get_contents($fullIncludePath);

                // Suche nach der Funktion mit Docblock
                $pattern = '/\/\*\*(.*?)\*\/\s*function\s+' . preg_quote($functionName) . '\s*\(/s';

                if (preg_match($pattern, $fileContent, $matches)) {
                    $functionFound = true;
                    $docblock = $matches[1];

                    // FORMAT 1: Detaillierte @param Annotationen wie @param string $data['term'] Beschreibung
                    preg_match_all('/@param\s+(\w+)\s+\$data\[[\'"]([\w_]+)[\'"]\]\s*(.*)$/m', $docblock, $paramMatches, PREG_SET_ORDER);

                    if (!empty($paramMatches)) {
                        $hasDocblock = true;

                        foreach ($paramMatches as $match) {
                            $type = $match[1];
                            $name = $match[2];
                            $description = trim($match[3]);

                            $parameters[] = [
                                'name' => $name,
                                'type' => $type,
                                'description' => $description
                            ];
                        }
                    }

                    // FORMAT 2: Einfaches @param array $data mit separaten Zeilen
                    // Suche nach Zeilen wie "In $data['term'] steht der Suchbegriff"
                    if (empty($parameters) && preg_match('/@param\s+array\s+\$data/i', $docblock)) {
                        // Suche nach "In $data['...']" oder "$data['...']" Zeilen
                        preg_match_all('/(?:In\s+)?\$data\[[\'"]([\w_]+)[\'"]\]\s+(.+?)$/m', $docblock, $dataMatches, PREG_SET_ORDER);

                        if (!empty($dataMatches)) {
                            $hasDocblock = true;

                            foreach ($dataMatches as $match) {
                                $name = $match[1];
                                $description = trim($match[2]);

                                // Typ aus Beschreibung ableiten oder 'string' als Default
                                $type = 'string';
                                if (preg_match('/\b(int|integer|bool|boolean|float|double|array|object)\b/i', $description, $typeMatch)) {
                                    $type = strtolower($typeMatch[1]);
                                }

                                $parameters[] = [
                                    'name' => $name,
                                    'type' => $type,
                                    'description' => $description
                                ];
                            }
                        }
                    }

                    // Parse @testdata Tag für Beispieldaten
                    if (preg_match('/@testdata\s+(.+?)$/m', $docblock, $exampleMatch)) {
                        $exampleJson = trim($exampleMatch[1]);

                        // Entferne mögliche führende/trailing Whitespaces und Sternchen
                        $exampleJson = preg_replace('/^\s*\*\s*/m', '', $exampleJson);
                        $exampleJson = trim($exampleJson);

                        // writeLog("DEBUG: @testdata gefunden für $functionName: $exampleJson");

                        // Versuche JSON zu dekodieren
                        $decoded = json_decode($exampleJson, true);

                        if ($decoded !== null) {
                            $exampleData = $decoded;
                            // writeLog("DEBUG: @testdata erfolgreich dekodiert für $functionName");
                        } else {
                            // writeLog("WARNING: Ungültiges JSON in @testdata für $functionName: $exampleJson (Error: " . json_last_error_msg() . ")");
                        }
                    } else {
                        // writeLog("DEBUG: Keine @testdata gefunden für $functionName");
                    }

                    break;
                }
            }
        }
    }

    if (!$functionFound) {
        resultInfo(false, "Funktion '$functionName' nicht gefunden");
        return;
    }

    if (!$hasDocblock) {
        resultInfo(false, "FEHLER: Funktion '$functionName' hat keine @param Docblock-Kommentare! Bitte dokumentieren.");
        return;
    }

    // Wenn kein @testdata vorhanden, generiere Fallback
    if ($exampleData === null) {
        $exampleData = [];
        foreach ($parameters as $param) {
            $exampleData[$param['name']] = generateExampleValue($param['type'], $param['name'], $param['description']);
        }
    }

    resultInfo(true, '', [
        'parameters' => $parameters,
        'example_data' => $exampleData
    ]);
}

/**
 * Generiert Beispielwert basierend auf Typ und Name
 */
function generateExampleValue($type, $name, $description) {
    $lowerName = strtolower($name);
    $lowerDesc = strtolower($description);

    // Basierend auf Typ
    switch (strtolower($type)) {
        case 'int':
        case 'integer':
            if (strpos($lowerName, 'id') !== false) {
                return 1;
            }
            if (strpos($lowerName, 'limit') !== false) {
                return 10;
            }
            if (strpos($lowerName, 'offset') !== false) {
                return 0;
            }
            return 1;

        case 'bool':
        case 'boolean':
            return true;

        case 'float':
        case 'double':
            return 1.0;

        case 'array':
            return [];

        case 'string':
        default:
            // Basierend auf Name
            if (strpos($lowerName, 'term') !== false || strpos($lowerName, 'search') !== false) {
                return 'test';
            }
            if (strpos($lowerName, 'name') !== false) {
                return 'Test Name';
            }
            if (strpos($lowerName, 'email') !== false) {
                return 'test@example.com';
            }
            if (strpos($lowerName, 'phone') !== false) {
                return '+49 123 456789';
            }
            if (strpos($lowerName, 'number') !== false) {
                return 'TEST-001';
            }
            if (strpos($lowerName, 'description') !== false) {
                return 'Test Beschreibung';
            }
            if (strpos($lowerName, 'database') !== false) {
                return 'company';
            }
            if (strpos($lowerName, 'query') !== false) {
                return 'SELECT * FROM customer LIMIT 10';
            }
            if (strpos($lowerName, 'table') !== false) {
                return 'customer';
            }
            if (strpos($lowerName, 'filename') !== false || strpos($lowerName, 'file') !== false) {
                return 'test.txt';
            }
            if (strpos($lowerName, 'folder') !== false) {
                return 'developer-tools';
            }

            // Default
            return 'test';
    }
}