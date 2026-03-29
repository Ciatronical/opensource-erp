<?php
// backend/api/developer-tools/test-parser.php

/**
 * Test-Funktion für den Schema-Parser mit Test-SQL
 *
 * @param array $data Eingabedaten (nicht verwendet)
 *
 * @testdata {}
 */
function testParser($data) {
    $testSql = <<<SQL
CREATE TABLE IF NOT EXISTS public.currencies (
    id SERIAL NOT NULL,
    name TEXT NOT NULL,
    neuer_name TEXT,
    PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS public.customer (
    id SERIAL NOT NULL,
    name VARCHAR(255) NOT NULL,
    email TEXT,
    PRIMARY KEY (id)
);
SQL;

    $results = parseSchemaContent($testSql);
    $results['source'] = 'test_sql';

    resultInfo(true, 'Parser-Test abgeschlossen', $results);
}

/**
 * Test-Funktion für den Schema-Parser mit echter Schema-Datei
 *
 * @param string $data['dbName'] Name der Datenbank (z.B. "startup")
 *
 * @testdata {"dbName": "startup"}
 */
function testParserReal($data) {
    if (!isset($data['dbName']) || empty($data['dbName'])) {
        throw new ApiError('NO_DB_NAME', 'Kein Datenbankname angegeben');
    }

    $dbName = $data['dbName'];
    $schemaDir = __DIR__ . '/../../schemata';
    $filename = $dbName . '.schema.sql';
    $filepath = $schemaDir . '/' . $filename;

    if (!file_exists($filepath)) {
        throw new ApiError('FILE_NOT_FOUND', "Schema-Datei nicht gefunden: $filename");
    }

    $sql = file_get_contents($filepath);

    if ($sql === false) {
        throw new ApiError('FILE_READ_ERROR', 'Konnte Datei nicht lesen');
    }

    $results = parseSchemaContent($sql);
    $results['source'] = $filepath;
    $results['file_size'] = strlen($sql);

    // Suche spezifisch nach currencies Tabelle
    $results['currencies_search'] = [];
    if (preg_match('/CREATE TABLE[^;]*?public\.currencies[^;]*;/is', $sql, $match)) {
        $results['currencies_search']['found'] = true;
        $results['currencies_search']['raw_match'] = $match[0];
    } else {
        $results['currencies_search']['found'] = false;
    }

    resultInfo(true, 'Parser-Test mit echter Datei abgeschlossen', $results);
}

/**
 * Hilfsfunktion: Parst Schema-Content und gibt Analyse zurück
 */
function parseSchemaContent($sql) {
    $results = [
        'input_sql_preview' => substr($sql, 0, 500) . '...',
        'tables_found' => [],
        'parse_steps' => []
    ];

    // Entferne SQL-Kommentare
    $sql = preg_replace('/--.*$/m', '', $sql);
    $results['parse_steps'][] = "1. SQL-Kommentare entfernt";

    // Regex zum Finden von CREATE TABLE Statements
    preg_match_all(
        '/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+([a-z_]+)\.([a-z_]+)\s*\(([\s\S]*?)\);/i',
        $sql,
        $matches,
        PREG_SET_ORDER
    );

    $results['parse_steps'][] = "2. Gefundene CREATE TABLE Statements: " . count($matches);

    foreach ($matches as $match) {
        $schemaName = strtolower($match[1]);
        $tableName = strtolower($match[2]);
        $columnsDef = $match[3];

        $tableResult = [
            'schema' => $schemaName,
            'table' => $tableName,
            'raw_columns_def' => $columnsDef,
            'columns' => []
        ];

        // Parse Spalten
        $columnsDef = preg_replace('/\s+/', ' ', trim($columnsDef));
        $lines = preg_split('/,\s*(?![^(]*\))/', $columnsDef);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            if (preg_match('/^(PRIMARY KEY|FOREIGN KEY|UNIQUE|CHECK|CONSTRAINT)/i', $line)) {
                $tableResult['columns'][] = ['skipped' => $line, 'reason' => 'constraint'];
                continue;
            }

            if (preg_match('/^"?([a-z_][a-z0-9_]*)"?\s+(\w+(?:\s*\([^)]*\))?)/i', $line, $colMatch)) {
                $columnName = strtolower($colMatch[1]);
                $dataType = strtoupper(trim($colMatch[2]));
                $tableResult['columns'][] = [
                    'name' => $columnName,
                    'type' => $dataType,
                    'raw_line' => $line
                ];
            } else {
                $tableResult['columns'][] = ['not_recognized' => $line];
            }
        }

        $results['tables_found'][] = $tableResult;
    }

    return $results;
}