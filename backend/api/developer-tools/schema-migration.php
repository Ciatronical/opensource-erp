<?php
// backend/api/developer-tools/schema-migration.php

/**
 * Schema-Migration: Intelligenter Import mit Diff-Generierung
 *
 * Diese Funktion vergleicht das hochgeladene Schema mit der bestehenden Datenbank
 * und generiert automatisch die notwendigen ALTER TABLE Statements um die Datenbank
 * auf den Stand der Datei zu bringen.
 *
 * @param string $data['dbName'] Name der Datenbank (z.B. "startup")
 *
 * @testdata {"dbName": "startup"}
 */
function fileToSchemaWithMigration($data) {
    $mandant = DbhCompany::begin();

    // Hole DB-Name aus $data (vom Frontend/Store mitgeschickt)
    if (!isset($data['dbName']) || empty($data['dbName'])) {
        throw new ApiError('NO_DB_NAME', 'Kein Datenbankname angegeben');
    }
    $dbName = $data['dbName'];

    $schemaDir = __DIR__ . "/../../schemata";
    $filename = $dbName . ".schema.sql";
    $filepath = $schemaDir . "/" . $filename;

    if (!file_exists($filepath)) {
        throw new ApiError("FILE_NOT_FOUND", "Schema-Datei nicht gefunden. Bitte zuerst Schema exportieren.");
    }

    // Lese SQL-Datei
    $sql = file_get_contents($filepath);

    if ($sql === false) {
        throw new ApiError('FILE_READ_ERROR', 'Could not read file');
    }

    // Parse CREATE TABLE Statements aus der Datei
    $fileTables = parseCreateTableStatements($sql);

    // Hole aktuelle Tabellen-Strukturen aus der DB
    $dbTables = getCurrentDatabaseTables($mandant);

    // Generiere Migration-Statements (Diff zwischen File und DB)
    $migrations = generateMigrationStatements($mandant, $fileTables, $dbTables);

    // Gebe Preview zurück für Benutzer-Bestätigung
    resultInfo(true, 'Migration preview generated', [
        'migrations' => $migrations,
        'stats' => [
            'new_tables' => count($migrations['create_tables']),
            'altered_tables' => count($migrations['alter_tables']),
            'new_columns' => array_sum(array_map(function($alters) {
                return count($alters);
            }, $migrations['alter_tables']))
        ]
    ]);
}

/**
 * Führt die Migration nach Benutzer-Bestätigung aus
 *
 * @param array $data['statements'] Array von SQL-Statements die ausgeführt werden sollen
 *
 * @testdata {"statements": ["SELECT 1"]}
 */
function executeMigration($data) {
    $mandant = DbhCompany::begin();

    if (!isset($data['statements']) || !is_array($data['statements'])) {
        throw new ApiError('NO_STATEMENTS', 'No migration statements provided');
    }

    $statements = $data['statements'];
    $executed = [];
    $errors = [];

    // Führe jeden Statement einzeln aus
    foreach ($statements as $statement) {
        try {
            $mandant->query($statement);
            $executed[] = [
                'statement' => $statement,
                'success' => true
            ];
        } catch (Exception $e) {
            $errors[] = [
                'statement' => $statement,
                'error' => $e->getMessage()
            ];
        }
    }

    resultInfo(true, 'Migration completed', [
        'executed' => count($executed),
        'errors' => count($errors),
        'details' => [
            'executed' => $executed,
            'errors' => $errors
        ]
    ]);
}

/**
 * Parst CREATE TABLE Statements aus SQL-Datei
 *
 * Extrahiert alle CREATE TABLE Statements und analysiert ihre Struktur:
 * - Tabellenname
 * - Spalten mit Datentypen
 * - Constraints (PRIMARY KEY, NOT NULL, DEFAULT)
 *
 * @param string $sql SQL-Inhalt der Datei
 * @return array Array von Tabellen-Definitionen
 */
function parseCreateTableStatements($sql) {
    $tables = [];

    // Entferne SQL-Kommentare (-- bis Zeilenende)
    $sql = preg_replace('/--.*$/m', '', $sql);

    // Regex zum Finden von CREATE TABLE Statements
    // Matcht: CREATE TABLE [IF NOT EXISTS] schema.table (...);
    // Verwendet [\s\S] statt . um auch Newlines zu matchen
    preg_match_all(
        '/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+([a-z_]+)\.([a-z_]+)\s*\(([\s\S]*?)\);/i',
        $sql,
        $matches,
        PREG_SET_ORDER
    );

    writeLog("DEBUG: Gefundene CREATE TABLE Statements: " . count($matches));

    foreach ($matches as $match) {
        $schemaName = strtolower($match[1]);
        $tableName = strtolower($match[2]);
        $columnsDef = $match[3];

        writeLog("DEBUG: Parse Tabelle: $schemaName.$tableName");

        // Parse Spalten aus dem CREATE TABLE Body
        $columns = parseColumnDefinitions($columnsDef);

        writeLog("DEBUG: Gefundene Spalten in $tableName: " . implode(', ', array_keys($columns)));

        $tables[$schemaName . '.' . $tableName] = [
            'schema' => $schemaName,
            'table' => $tableName,
            'full_name' => $schemaName . '.' . $tableName,
            'columns' => $columns
        ];
    }

    return $tables;
}

/**
 * Parst Spalten-Definitionen aus CREATE TABLE Body
 *
 * Extrahiert jede Spalte mit:
 * - Name
 * - Datentyp
 * - Constraints (NOT NULL, DEFAULT, PRIMARY KEY)
 *
 * @param string $columnsDef Spalten-Definition aus CREATE TABLE
 * @return array Array von Spalten-Informationen
 */
function parseColumnDefinitions($columnsDef) {
    $columns = [];

    // Entferne Zeilenumbrüche und normalisiere Whitespace
    $columnsDef = preg_replace('/\s+/', ' ', trim($columnsDef));

    // Split bei Komma (aber nicht innerhalb von Klammern)
    $lines = preg_split('/,\s*(?![^(]*\))/', $columnsDef);

    foreach ($lines as $line) {
        $line = trim($line);

        // Überspringe leere Zeilen
        if (empty($line)) {
            continue;
        }

        // Überspringe PRIMARY KEY, FOREIGN KEY, UNIQUE, CHECK Constraints
        if (preg_match('/^(PRIMARY KEY|FOREIGN KEY|UNIQUE|CHECK|CONSTRAINT)/i', $line)) {
            continue;
        }

        // Parse Spalten-Definition
        // Format: spaltenname DATENTYP [NOT NULL] [DEFAULT wert]
        if (preg_match('/^"?([a-z_][a-z0-9_]*)"?\s+(\w+(?:\s*\([^)]*\))?)/i', $line, $colMatch)) {
            $columnName = strtolower($colMatch[1]);
            $dataType = strtoupper(trim($colMatch[2]));

            // NOT NULL?
            $notNull = (bool) preg_match('/NOT NULL/i', $line);

            // DEFAULT?
            $default = null;
            if (preg_match('/DEFAULT\s+([^\s,]+)/i', $line, $defMatch)) {
                $default = trim($defMatch[1]);
            }

            $columns[$columnName] = [
                'name' => $columnName,
                'data_type' => $dataType,
                'default' => $default
            ];
        }
    }

    return $columns;
}

/**
 * Holt aktuelle Tabellen-Strukturen aus der Datenbank
 *
 * @param ApiDatabase $mandant Datenbankverbindung
 * @return array Array von Tabellen mit ihren Spalten
 */
function getCurrentDatabaseTables($mandant) {
    $tables = [];

    // Hole alle Tabellen
    $tablesQuery = <<<SQL
        SELECT
            schemaname,
            tablename
        FROM pg_tables
        WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
        ORDER BY schemaname, tablename
    SQL;

    $tableList = $mandant->fetchAll($tablesQuery);

    foreach ($tableList as $table) {
        $schemaName = $table['schemaname'];
        $tableName = $table['tablename'];
        $fullName = $schemaName . '.' . $tableName;

        // Hole Spalten dieser Tabelle
        $columnsQuery = <<<SQL
            SELECT
                column_name,
                data_type,
                is_nullable,
                column_default,
                udt_name,
                character_maximum_length,
                numeric_precision,
                numeric_scale
            FROM information_schema.columns
            WHERE table_schema = :schema
            AND table_name = :table
            ORDER BY ordinal_position
        SQL;

        $columns = $mandant->getAll($columnsQuery, [
            ':schema' => $schemaName,
            ':table' => $tableName
        ]);

        $columnMap = [];
        foreach ($columns as $col) {
            $columnMap[$col['column_name']] = [
                'name' => $col['column_name'],
                'data_type' => formatDataType(
                    $col['data_type'],
                    $col['character_maximum_length'],
                    $col['numeric_precision'],
                    $col['numeric_scale'],
                    $col['column_default'],
                    $col['udt_name']
                ),
                'not_null' => ($col['is_nullable'] === 'NO'),
                'default' => $col['column_default']
            ];
        }

        $tables[$fullName] = [
            'schema' => $schemaName,
            'table' => $tableName,
            'full_name' => $fullName,
            'columns' => $columnMap
        ];
    }

    return $tables;
}

/**
 * Generiert Migration-Statements durch Vergleich von File und DB
 *
 * Vergleicht jede Tabelle/Spalte aus der Datei mit der DB und generiert:
 * - CREATE TABLE für neue Tabellen
 * - ALTER TABLE ADD COLUMN für neue Spalten
 * - ALTER TABLE ALTER COLUMN für geänderte Spalten (TODO)
 * - ALTER TABLE DROP COLUMN für gelöschte Spalten (NICHT automatisch!)
 *
 * @param ApiDatabase $mandant Datenbankverbindung
 * @param array $fileTables Tabellen aus der SQL-Datei
 * @param array $dbTables Aktuelle Tabellen aus der DB
 * @return array Migration-Statements gruppiert nach Typ
 */
function generateMigrationStatements($mandant, $fileTables, $dbTables) {
    $migrations = [
        'create_tables' => [],
        'alter_tables' => [],
        'warnings' => [],
        'debug' => []  // Debug-Info für Frontend
    ];

    writeLog("DEBUG: Starte Migration-Vergleich");
    writeLog("DEBUG: Tabellen aus Datei: " . implode(', ', array_keys($fileTables)));
    writeLog("DEBUG: Tabellen aus DB: " . implode(', ', array_keys($dbTables)));

    // Durchlaufe alle Tabellen aus der Datei
    foreach ($fileTables as $fullName => $fileTable) {

        writeLog("DEBUG: Prüfe Tabelle: $fullName");

        // Tabelle existiert NICHT in DB -> CREATE TABLE
        if (!isset($dbTables[$fullName])) {
            writeLog("DEBUG: Tabelle $fullName existiert nicht in DB -> CREATE TABLE");
            $migrations['create_tables'][] = [
                'table' => $fullName,
                'statement' => generateCreateTableFromParsed($fileTable)
            ];
            continue;
        }

        // Tabelle existiert -> Vergleiche Spalten
        $dbTable = $dbTables[$fullName];
        $alterStatements = [];

        $fileColumns = array_keys($fileTable['columns']);
        $dbColumns = array_keys($dbTable['columns']);

        writeLog("DEBUG: Spalten in Datei für $fullName: " . implode(', ', $fileColumns));
        writeLog("DEBUG: Spalten in DB für $fullName: " . implode(', ', $dbColumns));

        foreach ($fileTable['columns'] as $columnName => $fileColumn) {

            // Spalte existiert NICHT in DB -> ADD COLUMN
            if (!isset($dbTable['columns'][$columnName])) {
                writeLog("DEBUG: Spalte $columnName existiert nicht in DB -> ADD COLUMN");

                $quotedColumnName = quoteIfReserved($columnName);

                $stmt = "ALTER TABLE " . $fullName . "\n";
                $stmt .= "    ADD COLUMN " . $quotedColumnName . " " . $fileColumn['data_type'];

                if ($fileColumn['not_null']) {
                    $stmt .= " NOT NULL";
                }

                if ($fileColumn['default'] !== null) {
                    $stmt .= " DEFAULT " . $fileColumn['default'];
                }

                $stmt .= ";";

                $alterStatements[] = [
                    'column' => $columnName,
                    'action' => 'ADD COLUMN',
                    'statement' => $stmt
                ];
            }
            // Spalte existiert -> Prüfe ob geändert (TODO: Datentyp-Änderungen)
            else {
                $dbColumn = $dbTable['columns'][$columnName];

                // Einfacher Vergleich: Wenn Datentyp unterschiedlich
                if (normalizeDataType($fileColumn['data_type']) !== normalizeDataType($dbColumn['data_type'])) {
                    $migrations['warnings'][] = [
                        'table' => $fullName,
                        'column' => $columnName,
                        'message' => "Datentyp-Änderung erkannt: {$dbColumn['data_type']} -> {$fileColumn['data_type']}. " .
                                   "Automatische Konvertierung wird nicht durchgeführt. Bitte manuell prüfen!"
                    ];
                }
            }
        }

        if (!empty($alterStatements)) {
            $migrations['alter_tables'][$fullName] = $alterStatements;
        }
    }

    writeLog("DEBUG: Migration-Ergebnis: " . count($migrations['create_tables']) . " neue Tabellen, " .
             count($migrations['alter_tables']) . " geänderte Tabellen");

    return $migrations;
}

/**
 * Generiert CREATE TABLE Statement aus geparsten Daten
 *
 * @param array $table Geparste Tabellen-Definition
 * @return string CREATE TABLE Statement
 */
function generateCreateTableFromParsed($table) {
    $sql = "CREATE TABLE IF NOT EXISTS " . $table['full_name'] . " (\n";

    $columnDefs = [];
    foreach ($table['columns'] as $column) {
        $quotedName = quoteIfReserved($column['name']);
        $def = "    $quotedName " . $column['data_type'];

        if ($column['not_null']) {
            $def .= " NOT NULL";
        }

        if ($column['default'] !== null) {
            $def .= " DEFAULT " . $column['default'];
        }

        $columnDefs[] = $def;
    }

    $sql .= implode(",\n", $columnDefs);
    $sql .= "\n);";

    return $sql;
}

/**
 * Normalisiert Datentypen für Vergleich
 *
 * VARCHAR(255) und VARCHAR(255) sollten gleich sein, aber
 * "VARCHAR(255)" und "character varying(255)" auch
 *
 * @param string $dataType Datentyp
 * @return string Normalisierter Datentyp
 */
function normalizeDataType($dataType) {
    $dataType = strtoupper(trim($dataType));

    // Aliase normalisieren
    $dataType = str_replace('CHARACTER VARYING', 'VARCHAR', $dataType);
    $dataType = str_replace('INT4', 'INTEGER', $dataType);
    $dataType = str_replace('INT8', 'BIGINT', $dataType);
    $dataType = str_replace('INT2', 'SMALLINT', $dataType);
    $dataType = str_replace('BOOL', 'BOOLEAN', $dataType);

    return $dataType;
}