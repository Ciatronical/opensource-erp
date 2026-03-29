<?php
// backend/api/developer-tools/schema-sync.php

/**
 * Exportiert das Datenbankschema als SQL-Datei (menschenlesbar)
 * Speichert die Datei auf dem Server unter backend/schemata/
 *
 * @param array $data Eingabedaten (nicht verwendet)
 *
 * @testdata {}
 */
function schemaToFile($data) {
    $mandant = DbhCompany::begin();

    // Hole DB-Namen direkt aus der Datenbank (100% zuverlässig!)
    $dbNameResult = $mandant->getOne("SELECT current_database() as dbname");
    $dbName = $dbNameResult['dbname'] ?? 'default';

    // DEBUG: Logge DB-Name
    error_log("Schema Export - DB Name from SQL: " . $dbName);

    // Erstelle Verzeichnis falls nicht vorhanden
    $schemaDir = __DIR__ . '/../../schemata';
    if (!is_dir($schemaDir)) {
        mkdir($schemaDir, 0755, true);
        error_log("Schema Export - Created directory: " . $schemaDir);
    }

    $filename = $dbName . '.schema.sql';
    $filepath = $schemaDir . '/' . $filename;

    error_log("Schema Export - Will save to: " . $filepath);

    // Hole alle Tabellen
    $query = <<<SQL
        SELECT
            schemaname,
            tablename
        FROM pg_tables
        WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
        ORDER BY schemaname, tablename
    SQL;

    $tables = $mandant->fetchAll($query);

    $sql = "-- ============================================\n";
    $sql .= "-- OpensourceERP Database Schema Export\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- ============================================\n\n";

    // Schemas (außer public, pg_catalog, information_schema)
    $schemaQuery = <<<SQL
        SELECT schema_name
        FROM information_schema.schemata
        WHERE schema_name NOT IN ('public', 'pg_catalog', 'information_schema', 'pg_toast', 'pg_temp_1', 'pg_toast_temp_1')
        ORDER BY schema_name
    SQL;

    $schemas = $mandant->fetchAll($schemaQuery);

    if (!empty($schemas)) {
        $sql .= "-- ============================================\n";
        $sql .= "-- Schemas\n";
        $sql .= "-- ============================================\n\n";

        foreach ($schemas as $schema) {
            $sql .= "CREATE SCHEMA IF NOT EXISTS " . $schema['schema_name'] . ";\n";
        }

        $sql .= "\n";
    }

    // PostgreSQL Extensions
    $extensionQuery = "SELECT extname FROM pg_extension WHERE extname NOT IN ('plpgsql') ORDER BY extname";
    $extensions = $mandant->fetchAll($extensionQuery);

    if (!empty($extensions)) {
        $sql .= "-- ============================================\n";
        $sql .= "-- PostgreSQL Extensions\n";
        $sql .= "-- ============================================\n\n";

        foreach ($extensions as $ext) {
            $sql .= "CREATE EXTENSION IF NOT EXISTS " . $ext['extname'] . ";\n";
        }

        $sql .= "\n";
    }

    // Custom Types (ENUMs, Composite Types, Domains)
    $typeQuery = <<<SQL
        SELECT
            n.nspname AS schema,
            t.typname AS type_name,
            t.typtype,
            pg_catalog.format_type(t.oid, NULL) AS full_type
        FROM pg_type t
        JOIN pg_namespace n ON t.typnamespace = n.oid
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
        AND t.typtype IN ('e', 'c', 'd')
        ORDER BY n.nspname, t.typname
    SQL;

    $types = $mandant->fetchAll($typeQuery);

    if (!empty($types)) {
        $sql .= "-- ============================================\n";
        $sql .= "-- Custom Types (ENUMs, Composite Types, Domains)\n";
        $sql .= "-- ============================================\n\n";

        foreach ($types as $type) {
            $schemaName = $type['schema'];
            $typeName = $type['type_name'];
            $typeType = $type['typtype'];

            // ENUM Type
            if ($typeType === 'e') {
                $enumQuery = <<<SQL
                    SELECT enumlabel
                    FROM pg_enum
                    WHERE enumtypid = (
                        SELECT t.oid
                        FROM pg_type t
                        JOIN pg_namespace n ON t.typnamespace = n.oid
                        WHERE n.nspname = :schema
                        AND t.typname = :typename
                    )
                    ORDER BY enumsortorder
                SQL;

                $enumValues = $mandant->getAll($enumQuery, [
                    ':schema' => $schemaName,
                    ':typename' => $typeName
                ]);

                if (!empty($enumValues)) {
                    $values = array_map(function($v) {
                        return "'" . str_replace("'", "''", $v['enumlabel']) . "'";
                    }, $enumValues);

                    $sql .= "CREATE TYPE " . $schemaName . "." . $typeName . " AS ENUM (\n";
                    $sql .= "    " . implode(",\n    ", $values) . "\n";
                    $sql .= ");\n\n";
                }
            }
            // Composite Type
            elseif ($typeType === 'c') {
                $sql .= "-- Composite Type: " . $schemaName . "." . $typeName . "\n";
                $sql .= "-- (Composite types are created automatically with their tables)\n\n";
            }
            // Domain
            elseif ($typeType === 'd') {
                $domainQuery = <<<SQL
                    SELECT
                        pg_catalog.format_type(t.typbasetype, t.typtypmod) AS base_type,
                        t.typnotnull,
                        t.typdefault
                    FROM pg_type t
                    JOIN pg_namespace n ON t.typnamespace = n.oid
                    WHERE n.nspname = :schema
                    AND t.typname = :typename
                SQL;

                $domain = $mandant->getOne($domainQuery, [
                    ':schema' => $schemaName,
                    ':typename' => $typeName
                ]);

                if ($domain) {
                    $sql .= "CREATE DOMAIN " . $schemaName . "." . $typeName . " AS " . $domain['base_type'];
                    if ($domain['typdefault']) {
                        $sql .= " DEFAULT " . $domain['typdefault'];
                    }
                    if ($domain['typnotnull']) {
                        $sql .= " NOT NULL";
                    }
                    $sql .= ";\n\n";
                }
            }
        }
    }

    // ERSTER DURCHLAUF: Erstelle alle Tabellen (ohne FKs)
    $sql .= "-- ============================================\n";
    $sql .= "-- CREATE TABLES\n";
    $sql .= "-- ============================================\n\n";

    foreach ($tables as $table) {
        $schemaName = $table['schemaname'];
        $tableName = $table['tablename'];

        $sql .= generateCreateTableStatement($mandant, $schemaName, $tableName, false);
        $sql .= "\n";
    }

    // ZWEITER DURCHLAUF: Erstelle alle Foreign Keys
    $sql .= "\n-- ============================================\n";
    $sql .= "-- FOREIGN KEYS\n";
    $sql .= "-- ============================================\n\n";

    foreach ($tables as $table) {
        $schemaName = $table['schemaname'];
        $tableName = $table['tablename'];

        $sql .= generateForeignKeys($mandant, $schemaName, $tableName);
    }

    // DRITTER DURCHLAUF: Kommentare
    $sql .= "\n-- ============================================\n";
    $sql .= "-- COMMENTS\n";
    $sql .= "-- ============================================\n\n";

    foreach ($tables as $table) {
        $schemaName = $table['schemaname'];
        $tableName = $table['tablename'];

        $sql .= generateComments($mandant, $schemaName, $tableName);
    }

    // Speichere Datei auf dem Server
    $result = file_put_contents($filepath, $sql);

    if ($result === false) {
        throw new ApiError('FILE_WRITE_ERROR', 'Konnte Schema-Datei nicht schreiben');
    }

    resultInfo(true, 'Schema erfolgreich exportiert', [
        'filename' => $filename,
        'filepath' => $filepath,
        'size' => strlen($sql),
        'tables' => count($tables)
    ]);
}

/**
 * Lädt die gespeicherte Schema-Datei herunter
 * Wie downloadDatabaseBackup - direkter Download ohne JSON
 *
 * @param array $data Eingabedaten (nicht verwendet)
 *
 * @testdata {}
 */
function downloadSchema($data) {
    // Hole DB-Namen direkt aus der Datenbank
    $mandant = DbhCompany::begin();
    $dbNameResult = $mandant->getOne("SELECT current_database() as dbname");
    $dbName = $dbNameResult['dbname'] ?? 'default';

    $schemaDir = __DIR__ . '/../../schemata';
    $filename = $dbName . '.schema.sql';
    $filepath = $schemaDir . '/' . $filename;

    if (!file_exists($filepath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Schema-Datei nicht gefunden. Bitte zuerst Schema exportieren.']);
        return;
    }

    // Datei als Download senden (wie im Backup-Tool!)
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));

    readfile($filepath);
    exit;
}

/**
 * Lädt eine bearbeitete Schema-Datei hoch und speichert sie
 *
 * @param string $data['dbName'] Name der Datenbank (aus dem Store)
 *
 * @testdata {"dbName": "startup"}
 */
function uploadSchema($data) {
    if (!isset($_FILES['schema_file'])) {
        throw new ApiError('NO_FILE_UPLOADED', 'Keine Schema-Datei hochgeladen');
    }

    $file = $_FILES['schema_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new ApiError('FILE_UPLOAD_ERROR', 'Datei-Upload fehlgeschlagen');
    }

    // Hole DB-Name aus $data (vom Frontend/Store mitgeschickt)
    if (!isset($data['dbName']) || empty($data['dbName'])) {
        throw new ApiError('NO_DB_NAME', 'Kein Datenbankname angegeben');
    }
    $dbName = $data['dbName'];

    $schemaDir = __DIR__ . '/../../schemata';
    if (!is_dir($schemaDir)) {
        mkdir($schemaDir, 0755, true);
    }

    $filename = $dbName . '.schema.sql';
    $filepath = $schemaDir . '/' . $filename;

    // Verschiebe hochgeladene Datei
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new ApiError('FILE_MOVE_ERROR', 'Konnte hochgeladene Datei nicht speichern');
    }

    resultInfo(true, 'Schema-Datei erfolgreich hochgeladen', [
        'filename' => $filename,
        'size' => filesize($filepath)
    ]);
}

/**
 * Generiert CREATE TABLE Statement für eine Tabelle (im Original-Format)
 *
 * @param ApiDatabase $mandant Datenbankverbindung
 * @param string $schemaName Schema-Name
 * @param string $tableName Tabellen-Name
 * @param bool $includeForeignKeys Ob Foreign Keys inkludiert werden sollen
 * @return string CREATE TABLE Statement
 */
function generateCreateTableStatement($mandant, $schemaName, $tableName, $includeForeignKeys = true) {
    $fullTableName = $schemaName . '.' . $tableName;

    // Hole Table Comment
    $tableCommentQuery = <<<SQL
        SELECT
            obj_description(c.oid) AS comment
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = :schema
        AND c.relname = :table
    SQL;

    $tableComment = $mandant->getOne($tableCommentQuery, [
        ':schema' => $schemaName,
        ':table' => $tableName
    ]);

    $sql = '';

    // Tabellen-Kommentar als Header
    if (!empty($tableComment['comment'])) {
        $sql .= "-- " . $tableComment['comment'] . "\n";
    }

    $sql .= "CREATE TABLE IF NOT EXISTS $fullTableName (\n";

    // Hole Spalten-Informationen
    $columnsQuery = <<<SQL
        SELECT
            c.column_name,
            c.data_type,
            c.character_maximum_length,
            c.numeric_precision,
            c.numeric_scale,
            c.is_nullable,
            c.column_default,
            c.udt_name,
            pg_catalog.col_description(
                (quote_ident(c.table_schema)||'.'||quote_ident(c.table_name))::regclass::oid,
                c.ordinal_position
            ) AS column_comment
        FROM information_schema.columns c
        WHERE c.table_schema = :schema
        AND c.table_name = :table
        ORDER BY c.ordinal_position
    SQL;

    $columns = $mandant->getAll($columnsQuery, [
        ':schema' => $schemaName,
        ':table' => $tableName
    ]);

    $columnDefinitions = [];
    $columnComments = [];

    foreach ($columns as $column) {
        $colName = $column['column_name'];
        $colType = $column['data_type'];
        $maxLength = $column['character_maximum_length'];
        $precision = $column['numeric_precision'];
        $scale = $column['numeric_scale'];
        $nullable = $column['is_nullable'];
        $default = $column['column_default'];
        $udtName = $column['udt_name'];
        $comment = $column['column_comment'];

        // Quote reservierte Schlüsselwörter
        $quotedColName = quoteIfReserved($colName);

        // Datentyp formatieren (SERIAL statt INTEGER mit nextval)
        $typeStr = formatDataType($colType, $maxLength, $precision, $scale, $default, $udtName);

        // NULL/NOT NULL
        $nullStr = ($nullable === 'NO') ? ' NOT NULL' : '';

        // DEFAULT (nur wenn nicht SERIAL)
        $defaultStr = '';
        if ($default !== null && strpos($typeStr, 'SERIAL') === false) {
            // Bereinige DEFAULT: Entferne ::type Casts
            $cleanDefault = preg_replace('/::(character varying|text|integer|bigint|numeric|boolean)/', '', $default);
            $defaultStr = ' DEFAULT ' . $cleanDefault;
        }

        $columnDefinitions[] = "    $quotedColName $typeStr$nullStr$defaultStr";

        // Spalten-Kommentar sammeln
        if (!empty($comment)) {
            $columnComments[$colName] = $comment;
        }
    }

    $sql .= implode(",\n", $columnDefinitions);

    // Primary Key
    $pkQuery = <<<SQL
        SELECT
            kcu.column_name
        FROM information_schema.table_constraints tc
        JOIN information_schema.key_column_usage kcu
            ON tc.constraint_name = kcu.constraint_name
            AND tc.table_schema = kcu.table_schema
        WHERE tc.constraint_type = 'PRIMARY KEY'
        AND tc.table_schema = :schema
        AND tc.table_name = :table
        ORDER BY kcu.ordinal_position
    SQL;

    $pkColumns = $mandant->getAll($pkQuery, [
        ':schema' => $schemaName,
        ':table' => $tableName
    ]);

    if (!empty($pkColumns)) {
        $pkCols = array_map(function($col) {
            return quoteIfReserved($col['column_name']);
        }, $pkColumns);
        $sql .= ",\n    PRIMARY KEY (" . implode(', ', $pkCols) . ")";
    }

    $sql .= "\n);\n";

    // Indizes
    $indexQuery = <<<SQL
        SELECT
            i.relname AS index_name,
            ix.indisunique AS is_unique,
            pg_get_indexdef(i.oid) AS index_def
        FROM pg_class t
        JOIN pg_index ix ON t.oid = ix.indrelid
        JOIN pg_class i ON i.oid = ix.indexrelid
        JOIN pg_namespace n ON t.relnamespace = n.oid
        WHERE n.nspname = :schema
        AND t.relname = :table
        AND NOT ix.indisprimary
        ORDER BY i.relname
    SQL;

    $indexes = $mandant->getAll($indexQuery, [
        ':schema' => $schemaName,
        ':table' => $tableName
    ]);

    foreach ($indexes as $idx) {
        // Extrahiere CREATE INDEX aus pg_get_indexdef
        $indexDef = $idx['index_def'];

        // Ersetze CREATE INDEX durch CREATE INDEX IF NOT EXISTS
        $indexDef = str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $indexDef);
        $indexDef = str_replace('CREATE UNIQUE INDEX', 'CREATE UNIQUE INDEX IF NOT EXISTS', $indexDef);

        $sql .= $indexDef . ";\n";
    }
    return $sql;

}

/**
 * Prüft ob ein Spaltenname ein reserviertes SQL-Schlüsselwort ist
 * und gibt ihn mit Quotes zurück wenn nötig
 *
 * @param string $columnName Spaltenname
 * @return string Gequoteter oder ungequoteter Name
 */
function quoteIfReserved($columnName) {
    // Liste häufiger reservierter PostgreSQL Schlüsselwörter
    $reserved = [
        'from', 'to', 'order', 'user', 'group', 'table', 'column', 'select',
        'where', 'insert', 'update', 'delete', 'create', 'drop', 'alter',
        'grant', 'revoke', 'join', 'union', 'check', 'constraint', 'default',
        'primary', 'foreign', 'unique', 'index', 'key', 'references',
        'type', 'row', 'language', 'precision', 'comment', 'date', 'time',
        'timestamp', 'year', 'month', 'day', 'hour', 'minute', 'second',
        'interval', 'zone', 'current', 'session', 'system', 'array',
        'lateral', 'cross', 'left', 'right', 'inner', 'outer', 'natural',
        'using', 'on', 'as', 'desc', 'asc', 'null', 'not', 'and', 'or',
        'like', 'in', 'exists', 'between', 'is', 'having', 'limit', 'offset',
        'case', 'when', 'then', 'else', 'end', 'cast', 'for', 'do', 'if',
        'while', 'loop', 'return', 'begin', 'commit', 'rollback', 'transaction',
        'isolation', 'level', 'read', 'write', 'only', 'new', 'old', 'each',
        'declare', 'open', 'close', 'fetch', 'move', 'exception', 'raise',
        'notice', 'warning', 'with', 'recursive', 'materialized', 'refresh',
        'concurrently', 'vacuum', 'analyze', 'cluster', 'reindex', 'owner',
        'rename', 'reset', 'values', 'returning', 'conflict', 'excluded',
        'nothing', 'overriding', 'generate', 'generated', 'always', 'identity',
        'sequence', 'restart', 'cache', 'cycle', 'minvalue', 'maxvalue',
        'owned', 'by', 'all', 'any', 'some', 'both', 'leading', 'trailing',
        'extract', 'position', 'substring', 'trim', 'overlay', 'collate',
        'normalize', 'filter', 'within', 'over', 'partition', 'range',
        'rows', 'groups', 'unbounded', 'preceding', 'following', 'current',
        'exclude', 'ties', 'others', 'first', 'last', 'nulls'
    ];

    $lowerName = strtolower($columnName);

    if (in_array($lowerName, $reserved)) {
        return '"' . $columnName . '"';
    }

    return $columnName;
}

/**
 * Formatiert PostgreSQL Datentyp für CREATE TABLE
 * Erkennt SERIAL-Typen automatisch
 *
 * @param string $type Datentyp
 * @param int|null $length Maximale Länge
 * @param int|null $precision Numerische Präzision
 * @param int|null $scale Numerische Skala
 * @param string|null $default DEFAULT Wert
 * @param string|null $udtName UDT Name
 * @return string Formatierter Datentyp
 */
function formatDataType($type, $length, $precision, $scale, $default, $udtName) {
    // ARRAY Types: udt_name hat _ Prefix
    if ($type === 'ARRAY' && !empty($udtName)) {
        // Konvertiere PostgreSQL Array-Namen zu SQL-Syntax
        $arrayTypeMap = [
            '_text' => 'TEXT[]',
            '_varchar' => 'VARCHAR[]',
            '_char' => 'CHAR[]',
            '_int2' => 'SMALLINT[]',
            '_int4' => 'INTEGER[]',
            '_int8' => 'BIGINT[]',
            '_numeric' => 'NUMERIC[]',
            '_float4' => 'REAL[]',
            '_float8' => 'DOUBLE PRECISION[]',
            '_bool' => 'BOOLEAN[]',
            '_date' => 'DATE[]',
            '_timestamp' => 'TIMESTAMP[]',
            '_timestamptz' => 'TIMESTAMPTZ[]',
            '_json' => 'JSON[]',
            '_jsonb' => 'JSONB[]',
            '_uuid' => 'UUID[]',
            '_bytea' => 'BYTEA[]',
        ];

        if (isset($arrayTypeMap[$udtName])) {
            return $arrayTypeMap[$udtName];
        }

        // Fallback: Custom ENUM Arrays oder unbekannte Types
        // _my_enum -> my_enum[]
        if (strpos($udtName, '_') === 0) {
            return substr($udtName, 1) . '[]';
        }

        return $udtName . '[]';
    }

    // USER-DEFINED Types: Verwende udt_name (ENUMs, Custom Types)
    if ($type === 'USER-DEFINED' && !empty($udtName)) {
        return $udtName;
    }

    // Erkenne SERIAL-Typen
    if ($type === 'integer' && $default !== null && strpos($default, 'nextval') !== false) {
        return 'SERIAL';
    }
    if ($type === 'bigint' && $default !== null && strpos($default, 'nextval') !== false) {
        return 'BIGSERIAL';
    }
    if ($type === 'smallint' && $default !== null && strpos($default, 'nextval') !== false) {
        return 'SMALLSERIAL';
    }

    switch ($type) {
        case 'character varying':
            return $length ? "VARCHAR($length)" : 'VARCHAR';

        case 'character':
            return $length ? "CHAR($length)" : 'CHAR';

        case 'numeric':
            if ($precision && $scale !== null) {
                return "NUMERIC($precision,$scale)";
            } elseif ($precision) {
                return "NUMERIC($precision)";
            }
            return 'NUMERIC';

        case 'integer':
            return 'INTEGER';

        case 'bigint':
            return 'BIGINT';

        case 'smallint':
            return 'SMALLINT';

        case 'boolean':
            return 'BOOLEAN';

        case 'text':
            return 'TEXT';

        case 'timestamp without time zone':
            return 'TIMESTAMP WITHOUT TIME ZONE';

        case 'timestamp with time zone':
            return 'TIMESTAMP WITH TIME ZONE';

        case 'date':
            return 'DATE';

        case 'time without time zone':
            return 'TIME WITHOUT TIME ZONE';

        case 'bytea':
            return 'BYTEA';

        case 'json':
            return 'JSON';

        case 'jsonb':
            return 'JSONB';

        case 'uuid':
            return 'UUID';

        case 'double precision':
            return 'DOUBLE PRECISION';

        case 'real':
            return 'REAL';

        default:
            return strtoupper($type);
    }
}

/**
 * Importiert ein Schema aus einer SQL-Datei
 *
 * @param array $data Eingabedaten (nicht verwendet, File kommt aus $_FILES)
 */
function fileToSchema($data) {
    $mandant = DbhCompany::begin();

    if (!isset($_FILES['schema_file'])) {
        throw new ApiError('NO_FILE_UPLOADED', 'No schema file uploaded');
    }

    $file = $_FILES['schema_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new ApiError('FILE_UPLOAD_ERROR', 'File upload failed with error code: ' . $file['error']);
    }

    $tempFile = $file['tmp_name'];

    if (!file_exists($tempFile)) {
        throw new ApiError('FILE_NOT_FOUND', 'Uploaded file not found');
    }

    // Lese SQL-Datei
    $sql = file_get_contents($tempFile);

    if ($sql === false) {
        throw new ApiError('FILE_READ_ERROR', 'Could not read uploaded file');
    }

    // Führe SQL aus
    try {
        // Split bei Semikolon und führe einzelne Statements aus
        $statements = explode(';', $sql);
        $executed = 0;
        $errors = [];

        foreach ($statements as $statement) {
            $statement = trim($statement);

            // Überspringe leere Statements und Kommentare
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue;
            }

            try {
                $mandant->query($statement);
                $executed++;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        resultInfo(true, 'Schema erfolgreich importiert', [
            'executed' => $executed,
            'errors' => $errors
        ]);

    } catch (Exception $e) {
        throw new ApiError('SCHEMA_IMPORT_FAILED', 'Import failed: ' . $e->getMessage());
    }
}

/**
 * Generiert Foreign Keys für eine Tabelle
 *
 * @param ApiDatabase $mandant Datenbankverbindung
 * @param string $schemaName Schema-Name
 * @param string $tableName Tabellen-Name
 * @return string ALTER TABLE Statements
 */
function generateForeignKeys($mandant, $schemaName, $tableName) {
    $fullTableName = $schemaName . '.' . $tableName;
    $sql = '';

    $fkQuery = <<<SQL
        SELECT
            con.conname AS constraint_name,
            array_to_string(
                ARRAY(
                    SELECT a.attname
                    FROM unnest(con.conkey) AS u(attnum)
                    JOIN pg_attribute a ON a.attnum = u.attnum AND a.attrelid = con.conrelid
                    ORDER BY u.attnum
                ),
                ', '
            ) AS column_names,
            nf.nspname AS foreign_schema,
            cf.relname AS foreign_table,
            array_to_string(
                ARRAY(
                    SELECT a.attname
                    FROM unnest(con.confkey) AS u(attnum)
                    JOIN pg_attribute a ON a.attnum = u.attnum AND a.attrelid = con.confrelid
                    ORDER BY u.attnum
                ),
                ', '
            ) AS foreign_columns,
            CASE con.confupdtype
                WHEN 'a' THEN 'NO ACTION'
                WHEN 'r' THEN 'RESTRICT'
                WHEN 'c' THEN 'CASCADE'
                WHEN 'n' THEN 'SET NULL'
                WHEN 'd' THEN 'SET DEFAULT'
            END AS update_rule,
            CASE con.confdeltype
                WHEN 'a' THEN 'NO ACTION'
                WHEN 'r' THEN 'RESTRICT'
                WHEN 'c' THEN 'CASCADE'
                WHEN 'n' THEN 'SET NULL'
                WHEN 'd' THEN 'SET DEFAULT'
            END AS delete_rule
        FROM pg_constraint con
        JOIN pg_class c ON con.conrelid = c.oid
        JOIN pg_namespace n ON c.relnamespace = n.oid
        JOIN pg_class cf ON con.confrelid = cf.oid
        JOIN pg_namespace nf ON cf.relnamespace = nf.oid
        WHERE con.contype = 'f'
        AND n.nspname = :schema
        AND c.relname = :table
        ORDER BY con.conname
    SQL;

    $foreignKeys = $mandant->getAll($fkQuery, [
        ':schema' => $schemaName,
        ':table' => $tableName
    ]);

    if (empty($foreignKeys)) {
        return '';
    }

    foreach ($foreignKeys as $fk) {
        $constraintName = $fk['constraint_name'];
        if ($constraintName === '$1' || empty($constraintName)) {
            $columns = explode(', ', $fk['column_names']);
            $firstCol = $columns[0];
            $constraintName = $tableName . '_' . $firstCol . '_fkey';
        }

        $columnNames = $fk['column_names'];
        $quotedColumnNames = implode(', ', array_map('quoteIfReserved', explode(', ', $columnNames)));

        $foreignColumns = $fk['foreign_columns'];
        $quotedForeignColumns = implode(', ', array_map('quoteIfReserved', explode(', ', $foreignColumns)));

        $sql .= "ALTER TABLE $fullTableName\n";
        $sql .= "    ADD CONSTRAINT " . $constraintName . "\n";
        $sql .= "    FOREIGN KEY (" . $quotedColumnNames . ")\n";
        $sql .= "    REFERENCES " . $fk['foreign_schema'] . "." . $fk['foreign_table'];
        $sql .= " (" . $quotedForeignColumns . ")";

        if ($fk['update_rule'] !== 'NO ACTION') {
            $sql .= "\n    ON UPDATE " . $fk['update_rule'];
        }
        if ($fk['delete_rule'] !== 'NO ACTION') {
            $sql .= "\n    ON DELETE " . $fk['delete_rule'];
        }

        $sql .= ";\n";
    }

    return $sql;
}

/**
 * Generiert COMMENT ON Statements für eine Tabelle
 *
 * @param ApiDatabase $mandant Datenbankverbindung
 * @param string $schemaName Schema-Name
 * @param string $tableName Tabellen-Name
 * @return string COMMENT ON Statements
 */
function generateComments($mandant, $schemaName, $tableName) {
    $fullTableName = $schemaName . '.' . $tableName;
    $sql = '';

    // Table Comment
    $tableCommentQuery = <<<SQL
        SELECT
            obj_description(c.oid) AS comment
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = :schema
        AND c.relname = :table
    SQL;

    $tableComment = $mandant->getOne($tableCommentQuery, [
        ':schema' => $schemaName,
        ':table' => $tableName
    ]);

    if (!empty($tableComment['comment'])) {
        $commentText = str_replace("'", "''", $tableComment['comment']);
        $sql .= "COMMENT ON TABLE $fullTableName IS '$commentText';\n";
    }

    // Column Comments
    $columnsQuery = <<<SQL
        SELECT
            c.column_name,
            pg_catalog.col_description(
                (quote_ident(c.table_schema)||'.'||quote_ident(c.table_name))::regclass::oid,
                c.ordinal_position
            ) AS column_comment
        FROM information_schema.columns c
        WHERE c.table_schema = :schema
        AND c.table_name = :table
        AND pg_catalog.col_description(
            (quote_ident(c.table_schema)||'.'||quote_ident(c.table_name))::regclass::oid,
            c.ordinal_position
        ) IS NOT NULL
        ORDER BY c.ordinal_position
    SQL;

    $columns = $mandant->getAll($columnsQuery, [
        ':schema' => $schemaName,
        ':table' => $tableName
    ]);

    foreach ($columns as $column) {
        $colName = $column['column_name'];
        $comment = $column['column_comment'];
        $commentText = str_replace("'", "''", $comment);
        $sql .= "COMMENT ON COLUMN $fullTableName.$colName IS '$commentText';\n";
    }

    return $sql;
}