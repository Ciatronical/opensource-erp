<?php
// backend/api/update/update.php

/**
 * Aktualisiert das Datenbankschema basierend auf SQL-Dateien
 *
 * Prüft ob Tabellen und Spalten existieren und fügt fehlende Spalten hinzu.
 * Unterstützt sowohl auth- als auch company-Datenbank.
 *
 * SQL-Dateien werden aus dem Verzeichnis 'backend/upstall/' geladen:
 * - Basis-CRM: upstall/crm/auth_schema.sql und upstall/crm/company_schema.sql
 * - Feature: upstall/{feature}/auth_schema.sql und upstall/{feature}/company_schema.sql
 *
 * Das aktive Feature wird aus der Company-DB gelesen (defaults_oserp).
 */

/**
 * API-Funktion zum Aktualisieren des Schemas
 *
 * Verwendung über API:
 * POST /api/update/
 * {
 *   "action": "updateSchema",
 *   "dry_run": true  // optional
 * }
 *
 * SQL-Dateien werden aus folgenden Verzeichnissen geladen:
 * - Basis: ../../upstall/crm/ (auth_schema.sql, company_schema.sql)
 * - Feature: ../../upstall/{feature}/ (auth_schema.sql, company_schema.sql falls vorhanden)
 *
 * Das aktive Feature wird aus der Company-Datenbank gelesen
 * (defaults_oserp WHERE key = 'features').
 *
 * @param array $data Request-Daten
 * @return void
 */
function updateSchema($data) {
    // Prüfe Berechtigung (optional - anpassen nach Bedarf)
    // permit('admin'); // Nur Admins dürfen Schema aktualisieren

    $dryRun = isset($data['dry_run']) && $data['dry_run'] === true;

    // Basis-Verzeichnis für upstall
    $upstallBaseDir = __DIR__ . '/../../upstall/';

    // Bestimme welche Datenbanken aktualisiert werden sollen
    $updateAuthDb = isset($data['auth_db']) ? $data['auth_db'] === true : true;
    $updateCompanyDb = isset($data['company_db']) ? $data['company_db'] === true : true;

    // Sammle alle zu verarbeitenden Verzeichnisse (crm ist immer dabei)
    $featureDirs = ['crm'];

    // Aktives Feature aus der Company-Datenbank lesen
    if ($updateCompanyDb) {
        try {
            $companyDb = DbhCompany::begin();
            $featureQuery = "SELECT value FROM defaults_oserp WHERE key = 'features'";
            $featureResult = $companyDb->getOne($featureQuery, []);
            if ($featureResult && !empty($featureResult['value'])) {
                $feature = trim($featureResult['value']);
                if (preg_match('/^[a-zA-Z0-9_-]+$/', $feature)) {
                    $featureDirs[] = $feature;
                    writeLog("Aktives Feature aus DB geladen: $feature", true, DLOG_INF);
                } else {
                    writeLog("Ungültiger Feature-Name in defaults_oserp: $feature", true, DLOG_WRN);
                }
            }
        } catch (Exception $e) {
            writeLog("Feature konnte nicht aus Company-DB geladen werden: " . $e->getMessage(), true, DLOG_WRN);
        }
    }

    $sqlFiles = [];
    $csvFiles = ['auth' => [], 'company' => []];

    // Sammle SQL- und CSV-Dateien aus allen Feature-Verzeichnissen
    foreach ($featureDirs as $featureDir) {
        $featurePath = $upstallBaseDir . $featureDir . '/';

        if (!is_dir($featurePath)) {
            if ($featureDir === 'crm') {
                resultInfo(false, "Basis-Verzeichnis nicht gefunden: $featurePath");
                return;
            }
            writeLog("Feature-Verzeichnis nicht gefunden: $featurePath", true, DLOG_WRN);
            continue;
        }

        if ($updateAuthDb) {
            $authFile = $featurePath . 'auth_schema.sql';
            if (file_exists($authFile)) {
                $sqlFiles[] = $authFile;
                writeLog("Auth-Schema gefunden: $authFile", true, DLOG_INF);
            } elseif ($featureDir === 'crm') {
                resultInfo(false, "Basis auth_schema.sql nicht gefunden: $authFile");
                return;
            }

            // Suche CSV-Dateien im auth_data-Unterverzeichnis
            $authCsvDir = $featurePath . 'auth_data/';
            if (is_dir($authCsvDir)) {
                $csvFilesFound = glob($authCsvDir . '*.csv');
                foreach ($csvFilesFound as $csvFile) {
                    $csvFiles['auth'][] = $csvFile;
                    writeLog("Auth-CSV gefunden: $csvFile", true, DLOG_INF);
                }
            }
        }

        if ($updateCompanyDb) {
            $companyFile = $featurePath . 'company_schema.sql';
            if (file_exists($companyFile)) {
                $sqlFiles[] = $companyFile;
                writeLog("Company-Schema gefunden: $companyFile", true, DLOG_INF);
            } elseif ($featureDir === 'crm') {
                resultInfo(false, "Basis company_schema.sql nicht gefunden: $companyFile");
                return;
            }

            // Suche CSV-Dateien im company_data-Unterverzeichnis
            $companyCsvDir = $featurePath . 'company_data/';
            if (is_dir($companyCsvDir)) {
                $csvFilesFound = glob($companyCsvDir . '*.csv');
                foreach ($csvFilesFound as $csvFile) {
                    $csvFiles['company'][] = $csvFile;
                    writeLog("Company-CSV gefunden: $csvFile", true, DLOG_INF);
                }
            }
        }
    }

    if (empty($sqlFiles)) {
        resultInfo(false, 'Keine SQL-Dateien zum Verarbeiten gefunden');
        return;
    }

    // Automatisches Backup vor dem Update (nur bei echtem Update, nicht bei Dry-Run)
    $backupResults = [];
    if (!$dryRun) {
        require_once __DIR__ . '/../developer-tools/database-backup.php';

        if ($updateAuthDb) {
            $authBackup = createAutoBackup('auth');
            $backupResults['auth'] = $authBackup;
            if ($authBackup['success']) {
                writeLog("Auto-Backup Auth-DB erstellt: " . $authBackup['filename'], true, DLOG_INF);
            } else {
                writeLog("Auto-Backup Auth-DB fehlgeschlagen: " . $authBackup['error'], true, DLOG_WRN);
            }
        }

        if ($updateCompanyDb) {
            $companyBackup = createAutoBackup('company');
            $backupResults['company'] = $companyBackup;
            if ($companyBackup['success']) {
                writeLog("Auto-Backup Company-DB erstellt: " . $companyBackup['filename'], true, DLOG_INF);
            } else {
                writeLog("Auto-Backup Company-DB fehlgeschlagen: " . $companyBackup['error'], true, DLOG_WRN);
            }
        }
    }

    // Führe Update aus
    $results = updateDatabaseSchema($sqlFiles, $csvFiles, $dryRun);

    // Backup-Ergebnisse an Results anhängen
    if (!empty($backupResults)) {
        $results['backups'] = $backupResults;
    }

    // Füge verarbeitete Features zu den Ergebnissen hinzu
    $results['processed_features'] = $featureDirs;

    if ($results['success']) {
        $message = 'Schema-Update erfolgreich';
        if ($dryRun) {
            $message .= ' (Dry-Run)';
        }
        resultInfo(true, $message, $results);
    } else {
        resultInfo(false, 'Fehler beim Schema-Update', $results);
    }
}

/**
 * Hauptfunktion zum Aktualisieren des Datenbankschemas
 *
 * Verwendet Transaktionen für jede SQL-Datei. Bei einem Fehler wird
 * ein Rollback durchgeführt und alle Änderungen der Datei rückgängig gemacht.
 *
 * @param array $sqlFiles Array mit SQL-Dateipfaden, z.B. ['auth_schema.sql', 'company_schema.sql']
 * @param array $csvFiles Array mit CSV-Dateien nach Schema gruppiert ['auth' => [...], 'company' => [...]]
 * @param bool $dryRun Wenn true, werden keine Änderungen vorgenommen (nur Anzeige)
 * @return array Ergebnis mit Statusmeldungen
 */
function updateDatabaseSchema($sqlFiles, $csvFiles = [], $dryRun = false, $companyDb = null) {
    $results = [
        'success' => true,
        'messages' => [],
        'errors' => [],
        'dry_run' => $dryRun,
        'sql_statements' => []
    ];

    if ($dryRun) {
        $results['messages'][] = ['type' => 'dry_run_mode'];
        writeLog("Dry-Run Modus aktiviert", true, DLOG_INF);
    }

    foreach ($sqlFiles as $sqlFile) {
        $db = null;
        $transactionStarted = false;

        try {
            writeLog("Verarbeite SQL-Datei: $sqlFile", true, DLOG_INF);

            if (!file_exists($sqlFile)) {
                throw new Exception("SQL-Datei nicht gefunden: $sqlFile");
            }

            $sqlContent = file_get_contents($sqlFile);

            // Bestimme Datenbanktyp anhand des Dateinamens
            $isAuthDb = strpos(strtolower($sqlFile), 'auth') !== false;
            $db = $isAuthDb ? DbhAuth::begin() : ($companyDb ?? DbhCompany::begin());
            $schema = $isAuthDb ? 'auth' : 'public';

            writeLog("Verwende Schema: $schema", true, DLOG_INF);

            // Starte Transaktion (nur wenn kein Dry-Run)
            if (!$dryRun) {
                $db->beginTransaction();
                $transactionStarted = true;
                writeLog("Transaktion gestartet für $schema", true, DLOG_DBG);
            }

            // Parse CREATE TABLE Statements (mit Spaltendefinitionen)
            $tables = parseCreateTableStatements($sqlContent);

            foreach ($tables as $tableInfo) {
                $tableName = $tableInfo['name'];
                $columns = $tableInfo['columns'];

                // Prüfe ob Tabelle existiert
                if (tableExists($db, $schema, $tableName)) {
                    writeLog("Tabelle $schema.$tableName existiert bereits - prüfe Spalten", true, DLOG_INF);

                    // Prüfe und füge fehlende Spalten hinzu
                    $addedColumns = addMissingColumns($db, $schema, $tableName, $columns, $dryRun, $results);

                    if (!empty($addedColumns)) {
                        $results['messages'][] = [
                            'type' => 'columns_added',
                            'table' => "$schema.$tableName",
                            'count' => count($addedColumns),
                            'dry_run' => $dryRun
                        ];
                        foreach ($addedColumns as $col) {
                            $results['messages'][] = [
                                'type' => 'column_added',
                                'table' => "$schema.$tableName",
                                'column' => $col,
                                'dry_run' => $dryRun
                            ];
                        }
                    } else {
                        $results['messages'][] = [
                            'type' => 'table_up_to_date',
                            'table' => "$schema.$tableName"
                        ];
                    }
                } else {
                    writeLog("Tabelle $schema.$tableName existiert nicht - " . ($dryRun ? "würde erstellt" : "wird erstellt"), true, DLOG_INF);

                    // Erstelle Tabelle
                    $createResult = createTable($db, $tableInfo['original_sql'], $dryRun, $results);

                    if ($createResult) {
                        $results['messages'][] = [
                            'type' => 'table_created',
                            'table' => "$schema.$tableName",
                            'dry_run' => $dryRun
                        ];
                    } else {
                        if (!$dryRun) {
                            throw new Exception("Fehler beim Erstellen der Tabelle $schema.$tableName");
                        }
                    }
                }
            }

            // Parse und führe alle anderen SQL-Statements aus
            $otherStatements = parseOtherSqlStatements($sqlContent);

            foreach ($otherStatements as $stmtInfo) {
                $stmtType = $stmtInfo['type'];
                $targetName = $stmtInfo['target'];
                $sql = $stmtInfo['sql'];

                // Prüfe ob Ziel-Objekt bereits existiert (für CREATE-Statements)
                if ($stmtType === 'CREATE_TABLE_AS' || $stmtType === 'CREATE_INDEX' || $stmtType === 'CREATE_VIEW') {
                    $objectExists = false;

                    if ($stmtType === 'CREATE_TABLE_AS') {
                        $objectExists = tableExists($db, $schema, $targetName);
                    } elseif ($stmtType === 'CREATE_INDEX') {
                        $objectExists = indexExists($db, $schema, $targetName);
                    } elseif ($stmtType === 'CREATE_VIEW') {
                        $objectExists = viewExists($db, $schema, $targetName);
                    }

                    if ($objectExists) {
                        writeLog("$stmtType $targetName existiert bereits - übersprungen", true, DLOG_INF);
                        $results['messages'][] = [
                            'type' => 'object_exists',
                            'object_type' => $stmtType,
                            'name' => $targetName
                        ];
                        continue;
                    }
                }

                // Führe Statement aus
                writeLog(($dryRun ? "[DRY-RUN] Würde ausführen: " : "Führe aus: ") . "$stmtType $targetName", true, DLOG_INF);

                if ($dryRun) {
                    $results['sql_statements'][] = [
                        'type' => $stmtType,
                        'target' => $targetName,
                        'sql' => $sql
                    ];
                } else {
                    try {
                        $db->execute($sql, []);
                        $results['messages'][] = [
                            'type' => 'statement_executed',
                            'statement_type' => $stmtType,
                            'target' => $targetName
                        ];
                        writeLog("$stmtType $targetName erfolgreich ausgeführt", true, DLOG_INF);
                    } catch (PDOException $e) {
                        throw new Exception("Fehler bei $stmtType $targetName: " . $e->getMessage());
                    }
                }
            }

            // Commit Transaktion bei Erfolg
            if ($transactionStarted) {
                $db->commit();
                writeLog("Transaktion committed für $schema", true, DLOG_DBG);
            }

        } catch (Exception $e) {
            // Rollback bei Fehler
            if ($transactionStarted && $db !== null) {
                try {
                    $db->rollBack();
                    writeLog("Transaktion rollback durchgeführt", true, DLOG_WRN);
                } catch (Exception $rollbackEx) {
                    writeLog("Rollback fehlgeschlagen: " . $rollbackEx->getMessage(), true, DLOG_ERR);
                }
            }

            $results['success'] = false;
            $results['errors'][] = "Fehler in $sqlFile: " . $e->getMessage();
            writeLog("FEHLER: " . $e->getMessage(), true, DLOG_ERR);
        }
    }

    // CSV-Dateien importieren (nach Schema-Updates)
    if ($results['success'] && !empty($csvFiles)) {
        foreach (['auth', 'company'] as $schemaType) {
            if (empty($csvFiles[$schemaType])) {
                continue;
            }

            $db = null;
            $transactionStarted = false;
            $schema = $schemaType === 'auth' ? 'auth' : 'public';

            try {
                $db = $schemaType === 'auth' ? DbhAuth::begin() : ($companyDb ?? DbhCompany::begin());

                if (!$dryRun) {
                    $db->beginTransaction();
                    $transactionStarted = true;
                    writeLog("CSV-Import Transaktion gestartet für $schema", true, DLOG_DBG);
                }

                foreach ($csvFiles[$schemaType] as $csvFile) {
                    $tableName = pathinfo($csvFile, PATHINFO_FILENAME);
                    writeLog("Verarbeite CSV: $csvFile für Tabelle $tableName", true, DLOG_INF);

                    // Prüfe ob Tabelle existiert
                    if (!tableExists($db, $schema, $tableName)) {
                        writeLog("Tabelle $schema.$tableName existiert nicht - CSV übersprungen", true, DLOG_WRN);
                        $results['messages'][] = [
                            'type' => 'csv_skipped',
                            'file' => basename($csvFile),
                            'reason' => 'table_not_exists'
                        ];
                        continue;
                    }

                    // CSV-Import nur in leere Tabellen — niemals bestehende Daten
                    // ueberschreiben, da IDs von anderen Tabellen referenziert werden
                    // (z.B. cars_lxcars.kba_id -> kba_lxcars.id)
                    $dbRowCount = getTableRowCount($db, $schema, $tableName);

                    if ($dbRowCount > 0) {
                        writeLog("Tabelle $schema.$tableName hat bereits $dbRowCount Zeilen - CSV-Import uebersprungen", true, DLOG_INF);
                        $results['messages'][] = [
                            'type' => 'csv_skipped',
                            'table' => "$schema.$tableName",
                            'reason' => 'table_not_empty',
                            'count' => $dbRowCount
                        ];
                        continue;
                    }

                    $csvRowCount = getCsvRowCount($csvFile);
                    writeLog("Tabelle $schema.$tableName ist leer, importiere $csvRowCount Zeilen aus CSV", true, DLOG_INF);

                    // Hole Spaltennamen der Tabelle
                    $columns = getTableColumnNames($db, $schema, $tableName);
                    if (empty($columns)) {
                        throw new Exception("Keine Spalten für Tabelle $schema.$tableName gefunden");
                    }

                    if ($dryRun) {
                        $results['sql_statements'][] = [
                            'type' => 'CSV_IMPORT',
                            'table' => "$schema.$tableName",
                            'file' => basename($csvFile),
                            'csv_rows' => $csvRowCount,
                            'db_rows' => $dbRowCount,
                            'columns' => $columns
                        ];
                    } else {
                        // Importiere CSV-Daten
                        $importResult = importCsvToTable($db, $schema, $tableName, $csvFile, $columns);
                        $results['messages'][] = [
                            'type' => 'csv_imported',
                            'table' => "$schema.$tableName",
                            'rows_imported' => $importResult['rows']
                        ];
                        writeLog("CSV-Import für $schema.$tableName abgeschlossen: {$importResult['rows']} Zeilen", true, DLOG_INF);
                    }
                }

                if ($transactionStarted) {
                    $db->commit();
                    writeLog("CSV-Import Transaktion committed für $schema", true, DLOG_DBG);
                }

            } catch (Exception $e) {
                if ($transactionStarted && $db !== null) {
                    try {
                        $db->rollBack();
                        writeLog("CSV-Import Rollback durchgeführt für $schema", true, DLOG_WRN);
                    } catch (Exception $rollbackEx) {
                        writeLog("CSV-Import Rollback fehlgeschlagen: " . $rollbackEx->getMessage(), true, DLOG_ERR);
                    }
                }

                $results['success'] = false;
                $results['errors'][] = "CSV-Import Fehler ($schema): " . $e->getMessage();
                writeLog("CSV-Import FEHLER: " . $e->getMessage(), true, DLOG_ERR);
            }
        }
    }

    return $results;
}

/**
 * Prüft ob eine Tabelle existiert
 *
 * @param ApiDatabase|ApiSession $db Datenbankverbindung
 * @param string $schema Schema-Name (auth oder public)
 * @param string $tableName Tabellenname
 * @return bool True wenn Tabelle existiert
 */
function tableExists($db, $schema, $tableName) {
    $query = "SELECT EXISTS (
        SELECT FROM information_schema.tables
        WHERE table_schema = :schema
        AND table_name = :table_name
    ) as exists";

    $result = $db->getOne($query, [
        ':schema' => $schema,
        ':table_name' => $tableName
    ]);

    return $result['exists'] === true || $result['exists'] === 't';
}

/**
 * Holt alle vorhandenen Spalten einer Tabelle aus der Datenbank
 *
 * @param ApiDatabase|ApiSession $db Datenbankverbindung
 * @param string $schema Schema-Name
 * @param string $tableName Tabellenname
 * @return array Array mit Spaltennamen als Keys und Datentypen als Values
 */
function getExistingColumns($db, $schema, $tableName) {
    $query = "SELECT
        column_name,
        data_type,
        character_maximum_length,
        is_nullable,
        column_default
    FROM information_schema.columns
    WHERE table_schema = :schema
    AND table_name = :table_name
    ORDER BY ordinal_position";

    $rows = $db->getAll($query, [
        ':schema' => $schema,
        ':table_name' => $tableName
    ]);

    $columns = [];
    foreach ($rows as $row) {
        $columns[$row['column_name']] = [
            'type' => $row['data_type'],
            'length' => $row['character_maximum_length'],
            'nullable' => $row['is_nullable'],
            'default' => $row['column_default']
        ];
    }

    return $columns;
}

/**
 * Fügt fehlende Spalten zur Tabelle hinzu
 *
 * @param ApiDatabase|ApiSession $db Datenbankverbindung
 * @param string $schema Schema-Name
 * @param string $tableName Tabellenname
 * @param array $columns Array mit Spaltendefinitionen aus SQL-Datei
 * @param bool $dryRun Wenn true, werden keine Änderungen vorgenommen
 * @param array &$results Referenz auf Results-Array für SQL-Statements
 * @return array Array mit Namen der hinzugefügten Spalten
 */
function addMissingColumns($db, $schema, $tableName, $columns, $dryRun = false, &$results = null) {
    $existingColumns = getExistingColumns($db, $schema, $tableName);
    $addedColumns = [];

    foreach ($columns as $columnDef) {
        $columnName = $columnDef['name'];

        // Prüfe ob Spalte bereits existiert
        if (!isset($existingColumns[$columnName])) {
            writeLog(($dryRun ? "[DRY-RUN] Würde Spalte hinzufügen: " : "Füge Spalte hinzu: ") . "$columnName zu $schema.$tableName", true, DLOG_INF);

            // Baue ALTER TABLE Statement
            $alterSql = "ALTER TABLE $schema.$tableName ADD COLUMN " . $columnDef['definition'];

            if ($dryRun) {
                // Im Dry-Run Modus nur Statement sammeln
                if ($results !== null) {
                    $results['sql_statements'][] = [
                        'type' => 'ALTER TABLE',
                        'table' => "$schema.$tableName",
                        'action' => "ADD COLUMN $columnName",
                        'sql' => $alterSql
                    ];
                }
                $addedColumns[] = $columnName;
                writeLog("[DRY-RUN] Statement: $alterSql", true, DLOG_DBG);
            } else {
                // Im normalen Modus ausführen
                try {
                    $db->execute($alterSql, []);
                    $addedColumns[] = $columnName;
                    writeLog("Spalte $columnName erfolgreich hinzugefügt", true, DLOG_INF);
                } catch (PDOException $e) {
                    writeLog("Fehler beim Hinzufügen von Spalte $columnName: " . $e->getMessage(), true, DLOG_ERR);
                    throw new Exception("Fehler beim Hinzufügen von Spalte $columnName: " . $e->getMessage());
                }
            }
        }
    }

    return $addedColumns;
}

/**
 * Erstellt eine neue Tabelle
 *
 * @param ApiDatabase|ApiSession $db Datenbankverbindung
 * @param string $createSql CREATE TABLE Statement
 * @param bool $dryRun Wenn true, werden keine Änderungen vorgenommen
 * @param array &$results Referenz auf Results-Array für SQL-Statements
 * @return bool True bei Erfolg
 */
function createTable($db, $createSql, $dryRun = false, &$results = null) {
    if ($dryRun) {
        // Im Dry-Run Modus nur Statement sammeln
        if ($results !== null) {
            // Extrahiere Tabellenname aus CREATE TABLE Statement
            preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z_][a-zA-Z0-9_.]*)/i', $createSql, $matches);
            $tableName = isset($matches[1]) ? $matches[1] : 'unknown';

            $results['sql_statements'][] = [
                'type' => 'CREATE TABLE',
                'table' => $tableName,
                'action' => 'CREATE',
                'sql' => $createSql
            ];
        }
        writeLog("[DRY-RUN] Statement: " . substr($createSql, 0, 200) . "...", true, DLOG_DBG);
        return true;
    } else {
        // Im normalen Modus ausführen
        try {
            $db->execute($createSql, []);
            return true;
        } catch (PDOException $e) {
            writeLog("Fehler beim Erstellen der Tabelle: " . $e->getMessage(), true, DLOG_ERR);
            throw new Exception("Fehler beim Erstellen der Tabelle: " . $e->getMessage());
        }
    }
}

/**
 * Parst CREATE TABLE Statements aus SQL-Datei
 *
 * @param string $sqlContent Inhalt der SQL-Datei
 * @return array Array mit Tabelleninformationen
 */
function parseCreateTableStatements($sqlContent) {
    $tables = [];

    // Entferne Kommentare (-- und /* */)
    $sqlContent = preg_replace('/--[^\n]*/', '', $sqlContent);
    $sqlContent = preg_replace('/\/\*.*?\*\//s', '', $sqlContent);

    // Finde alle CREATE TABLE Statements
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z_][a-zA-Z0-9_.]*)\s*\((.*?)\);/is', $sqlContent, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $tableName = trim($match[1]);
        $tableContent = $match[2];
        $originalSql = $match[0];

        // Entferne Schema-Präfix wenn vorhanden (z.B. auth.session_oserp -> session_oserp)
        if (strpos($tableName, '.') !== false) {
            $parts = explode('.', $tableName);
            $tableName = end($parts);
        }

        // Parse Spalten
        $columns = parseColumns($tableContent);

        $tables[] = [
            'name' => $tableName,
            'columns' => $columns,
            'original_sql' => $originalSql
        ];

        writeLog("Gefundene Tabelle: $tableName mit " . count($columns) . " Spalten", true, DLOG_DBG);
    }

    return $tables;
}

/**
 * Parst Spaltendefinitionen aus CREATE TABLE Statement
 *
 * @param string $tableContent Inhalt zwischen CREATE TABLE ... ( ... )
 * @return array Array mit Spaltendefinitionen
 */
function parseColumns($tableContent) {
    $columns = [];

    // Teile nach Kommas, aber nicht innerhalb von Klammern
    $lines = preg_split('/,(?![^()]*\))/', $tableContent);

    foreach ($lines as $line) {
        $line = trim($line);

        // Überspringe CONSTRAINT, PRIMARY KEY, FOREIGN KEY, etc.
        if (preg_match('/^\s*(CONSTRAINT|PRIMARY\s+KEY|FOREIGN\s+KEY|UNIQUE|CHECK)/i', $line)) {
            continue;
        }

        // Parse Spaltenname und Typ
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s+(.+)$/i', $line, $colMatch)) {
            $columnName = trim($colMatch[1], '"');
            $columnDef = trim($colMatch[2]);

            // Entferne Inline-Constraints für die Spaltendefinition
            // Behalte aber die komplette Definition für ALTER TABLE
            $columns[] = [
                'name' => $columnName,
                'definition' => $columnName . ' ' . $columnDef
            ];

            writeLog("  Spalte gefunden: $columnName", true, DLOG_DBG);
        }
    }

    return $columns;
}

/**
 * Parst alle anderen SQL-Statements (außer CREATE TABLE mit Spaltendefinitionen)
 *
 * Unterstützt:
 * - CREATE TABLE ... AS SELECT ...
 * - CREATE INDEX ...
 * - CREATE VIEW ...
 * - INSERT INTO ...
 * - UPDATE ...
 * - DELETE FROM ...
 * - COMMENT ON ...
 *
 * @param string $sqlContent Inhalt der SQL-Datei
 * @return array Array mit Statement-Informationen
 */
function parseOtherSqlStatements($sqlContent) {
    $statements = [];

    // Entferne einzeilige Kommentare (-- ...)
    $sqlContent = preg_replace('/--[^\n]*/', '', $sqlContent);
    // Entferne mehrzeilige Kommentare (/* ... */)
    $sqlContent = preg_replace('/\/\*.*?\*\//s', '', $sqlContent);

    // Teile nach ';' aber beachte $$ für Funktionen
    $rawStatements = splitSqlStatements($sqlContent);

    foreach ($rawStatements as $sql) {
        $sql = trim($sql);
        if (empty($sql)) {
            continue;
        }

        // Überspringe CREATE TABLE mit Spaltendefinitionen (werden separat verarbeitet)
        // Erkenne: CREATE TABLE name ( ... ) - aber nicht CREATE TABLE name AS SELECT
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[a-zA-Z_][a-zA-Z0-9_.]*\s*\(/is', $sql)) {
            continue;
        }

        // Bestimme Statement-Typ und Zielobjekt
        $stmtInfo = identifyStatement($sql);
        if ($stmtInfo) {
            $statements[] = $stmtInfo;
            writeLog("Gefunden: {$stmtInfo['type']} {$stmtInfo['target']}", true, DLOG_DBG);
        }
    }

    return $statements;
}

/**
 * Teilt SQL-Inhalt in einzelne Statements
 *
 * Berücksichtigt $$ Dollar-Quoting für Funktionen/Trigger
 *
 * @param string $sqlContent SQL-Inhalt
 * @return array Array mit einzelnen Statements
 */
function splitSqlStatements($sqlContent) {
    $statements = [];
    $currentStatement = '';
    $inDollarQuote = false;
    $dollarTag = '';
    $inSingleQuote = false;

    $length = strlen($sqlContent);
    $i = 0;

    while ($i < $length) {
        $char = $sqlContent[$i];

        // String-Literale ('...') beachten — ein ';' darin darf NICHT splitten.
        // Nur ausserhalb von Dollar-Quoting auswerten. '' ist ein escaptes Quote.
        if (!$inDollarQuote && $char === "'") {
            if ($inSingleQuote && $i + 1 < $length && $sqlContent[$i + 1] === "'") {
                $currentStatement .= "''";
                $i += 2;
                continue;
            }
            $inSingleQuote = !$inSingleQuote;
            $currentStatement .= $char;
            $i++;
            continue;
        }
        if ($inSingleQuote) {
            $currentStatement .= $char;
            $i++;
            continue;
        }

        // Prüfe auf Dollar-Quoting ($$ oder $tag$)
        if ($char === '$' && !$inDollarQuote) {
            $tagEnd = strpos($sqlContent, '$', $i + 1);
            if ($tagEnd !== false) {
                $potentialTag = substr($sqlContent, $i, $tagEnd - $i + 1);
                if (preg_match('/^\$[a-zA-Z0-9_]*\$$/', $potentialTag)) {
                    $inDollarQuote = true;
                    $dollarTag = $potentialTag;
                    $currentStatement .= $potentialTag;
                    $i = $tagEnd + 1;
                    continue;
                }
            }
        } elseif ($inDollarQuote) {
            if ($char === '$') {
                $remaining = substr($sqlContent, $i);
                if (strpos($remaining, $dollarTag) === 0) {
                    $currentStatement .= $dollarTag;
                    $i += strlen($dollarTag);
                    $inDollarQuote = false;
                    $dollarTag = '';
                    continue;
                }
            }
        }

        if ($char === ';' && !$inDollarQuote) {
            $currentStatement .= $char;
            $statements[] = trim($currentStatement);
            $currentStatement = '';
        } else {
            $currentStatement .= $char;
        }

        $i++;
    }

    if (trim($currentStatement) !== '') {
        $statements[] = trim($currentStatement);
    }

    return $statements;
}

/**
 * Identifiziert den Typ und das Zielobjekt eines SQL-Statements
 *
 * @param string $sql SQL-Statement
 * @return array|null Statement-Info oder null wenn nicht erkannt
 */
function identifyStatement($sql) {
    $sql = trim($sql);

    // CREATE TABLE ... AS SELECT
    if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z_][a-zA-Z0-9_.]*)\s+AS\s+/is', $sql, $m)) {
        return ['type' => 'CREATE_TABLE_AS', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // CREATE INDEX
    if (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'CREATE_INDEX', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // CREATE VIEW
    if (preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'CREATE_VIEW', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // CREATE FUNCTION
    if (preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\s+([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'CREATE_FUNCTION', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // CREATE TRIGGER
    if (preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?TRIGGER\s+([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'CREATE_TRIGGER', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // CREATE SEQUENCE
    if (preg_match('/^CREATE\s+SEQUENCE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'CREATE_SEQUENCE', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // CREATE TYPE
    if (preg_match('/^CREATE\s+TYPE\s+([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'CREATE_TYPE', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // DROP
    if (preg_match('/^DROP\s+(TABLE|INDEX|VIEW|FUNCTION|TRIGGER|SEQUENCE|TYPE)\s+(?:IF\s+EXISTS\s+)?([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'DROP_' . strtoupper($m[1]), 'target' => extractName($m[2]), 'sql' => $sql];
    }
    // ALTER TABLE
    if (preg_match('/^ALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'ALTER_TABLE', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // INSERT INTO
    if (preg_match('/^INSERT\s+INTO\s+([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'INSERT', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // UPDATE
    if (preg_match('/^UPDATE\s+([a-zA-Z_][a-zA-Z0-9_.]*)\s+SET/is', $sql, $m)) {
        return ['type' => 'UPDATE', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // DELETE FROM
    if (preg_match('/^DELETE\s+FROM\s+([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'DELETE', 'target' => extractName($m[1]), 'sql' => $sql];
    }
    // COMMENT ON
    if (preg_match('/^COMMENT\s+ON\s+(TABLE|COLUMN|INDEX|VIEW|FUNCTION|TRIGGER|SCHEMA|SEQUENCE|TYPE)\s+([a-zA-Z_][a-zA-Z0-9_.]*)/is', $sql, $m)) {
        return ['type' => 'COMMENT', 'target' => strtoupper($m[1]) . ' ' . extractName($m[2]), 'sql' => $sql];
    }
    // GRANT / REVOKE
    if (preg_match('/^(GRANT|REVOKE)\s+/is', $sql, $m)) {
        return ['type' => strtoupper($m[1]), 'target' => 'permissions', 'sql' => $sql];
    }

    // Unbekanntes Statement - trotzdem ausführen
    $firstWord = strtok(strtoupper($sql), " \t\n\r");
    return ['type' => $firstWord ?: 'UNKNOWN', 'target' => 'statement', 'sql' => $sql];
}

/**
 * Extrahiert den Objektnamen ohne Schema-Präfix
 *
 * @param string $name Vollständiger Name (evtl. mit Schema)
 * @return string Name ohne Schema
 */
function extractName($name) {
    $name = trim($name);
    if (strpos($name, '.') !== false) {
        $parts = explode('.', $name);
        return end($parts);
    }
    return $name;
}

/**
 * Prüft ob ein Index existiert
 *
 * @param ApiDatabase|ApiSession $db Datenbankverbindung
 * @param string $schema Schema-Name
 * @param string $indexName Index-Name
 * @return bool True wenn Index existiert
 */
function indexExists($db, $schema, $indexName) {
    $query = "SELECT EXISTS (
        SELECT FROM pg_indexes
        WHERE schemaname = :schema
        AND indexname = :index_name
    ) as exists";

    $result = $db->getOne($query, [
        ':schema' => $schema,
        ':index_name' => $indexName
    ]);

    return $result['exists'] === true || $result['exists'] === 't';
}

/**
 * Prüft ob eine View existiert
 *
 * @param ApiDatabase|ApiSession $db Datenbankverbindung
 * @param string $schema Schema-Name
 * @param string $viewName View-Name
 * @return bool True wenn View existiert
 */
function viewExists($db, $schema, $viewName) {
    $query = "SELECT EXISTS (
        SELECT FROM information_schema.views
        WHERE table_schema = :schema
        AND table_name = :view_name
    ) as exists";

    $result = $db->getOne($query, [
        ':schema' => $schema,
        ':view_name' => $viewName
    ]);

    return $result['exists'] === true || $result['exists'] === 't';
}

/**
 * Zählt die Anzahl der Datenzeilen in einer CSV-Datei (ohne Header)
 *
 * @param string $filePath Pfad zur CSV-Datei
 * @return int Anzahl der Datenzeilen
 */
function getCsvRowCount($filePath) {
    $lineCount = 0;
    $handle = fopen($filePath, 'r');
    if ($handle) {
        // Header-Zeile überspringen
        fgetcsv($handle);
        while (fgetcsv($handle) !== false) {
            $lineCount++;
        }
        fclose($handle);
    }
    return $lineCount;
}

/**
 * Zählt die Anzahl der Zeilen in einer Datenbanktabelle
 *
 * @param ApiDatabase|ApiSession $db Datenbankverbindung
 * @param string $schema Schema-Name
 * @param string $tableName Tabellenname
 * @return int Anzahl der Zeilen
 */
function getTableRowCount($db, $schema, $tableName) {
    $query = "SELECT COUNT(*) as count FROM $schema.$tableName";
    $result = $db->getOne($query, []);
    return (int) $result['count'];
}

/**
 * Holt die Spaltennamen einer Tabelle in der richtigen Reihenfolge
 *
 * @param ApiDatabase|ApiSession $db Datenbankverbindung
 * @param string $schema Schema-Name
 * @param string $tableName Tabellenname
 * @return array Array mit Spaltennamen
 */
function getTableColumnNames($db, $schema, $tableName) {
    $query = "SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = :schema
        AND table_name = :table_name
        ORDER BY ordinal_position";

    $rows = $db->getAll($query, [
        ':schema' => $schema,
        ':table_name' => $tableName
    ]);

    $columns = [];
    foreach ($rows as $row) {
        $columns[] = $row['column_name'];
    }

    return $columns;
}

/**
 * Importiert CSV-Daten in eine Tabelle mittels INSERT-Statements
 *
 * Löscht zuerst alle bestehenden Daten und importiert dann die CSV
 * zeilenweise per prepared INSERT. Benötigt keine Superuser-Rechte.
 *
 * @param ApiDatabase|ApiSession $db Datenbankverbindung
 * @param string $schema Schema-Name
 * @param string $tableName Tabellenname
 * @param string $csvFile Pfad zur CSV-Datei
 * @param array $columns Array mit Spaltennamen der Tabelle
 * @return array Ergebnis mit Anzahl importierter Zeilen
 */
function importCsvToTable($db, $schema, $tableName, $csvFile, $columns) {
    // Vollständigen Pfad zur CSV-Datei ermitteln
    $absolutePath = realpath($csvFile);
    if (!$absolutePath) {
        throw new Exception("CSV-Datei nicht gefunden: $csvFile");
    }

    // Lese CSV-Header um Spalten-Mapping zu erstellen
    $handle = fopen($absolutePath, 'r');
    if (!$handle) {
        throw new Exception("CSV-Datei konnte nicht geöffnet werden: $absolutePath");
    }

    $csvHeader = fgetcsv($handle);

    if (!$csvHeader) {
        fclose($handle);
        throw new Exception("CSV-Datei hat keinen Header: $absolutePath");
    }

    // Ermittle NOT NULL Spalten aus dem Schema
    $notNullQuery = "SELECT column_name FROM information_schema.columns
        WHERE table_schema = :schema AND table_name = :table_name AND is_nullable = 'NO'";
    $notNullRows = $db->getAll($notNullQuery, [':schema' => $schema, ':table_name' => $tableName]);
    $notNullColumns = array_map(function ($row) { return $row['column_name']; }, $notNullRows);

    // Bestimme welche Spalten aus der CSV verwendet werden können
    // und merke deren Index-Position im CSV-Header
    $csvColumns = [];
    $csvColumnIndices = [];
    foreach ($csvHeader as $index => $csvCol) {
        $csvCol = trim($csvCol);
        if (in_array($csvCol, $columns)) {
            $csvColumns[] = $csvCol;
            $csvColumnIndices[] = $index;
        }
    }

    if (empty($csvColumns)) {
        fclose($handle);
        throw new Exception("Keine passenden Spalten in CSV gefunden für $schema.$tableName");
    }

    // Beim CSV-Import von kba_lxcars: HSN-Trigger temporär für diese Transaktion freigeben
    // (SET LOCAL gilt nur bis zum nächsten COMMIT — danach greift der Trigger wieder)
    if ($tableName === 'kba_lxcars') {
        $db->execute("SET LOCAL \"kba.allow_new_hsn\" = 'on'", []);
    }

    // Lösche bestehende Daten
    $truncateSql = "TRUNCATE TABLE $schema.$tableName";
    $db->execute($truncateSql, []);
    writeLog("Tabelle $schema.$tableName geleert", true, DLOG_DBG);

    // Erstelle prepared INSERT Statement
    $columnList = implode(', ', $csvColumns);
    $placeholders = implode(', ', array_map(function ($col) {
        return ':' . $col;
    }, $csvColumns));
    $insertSql = "INSERT INTO $schema.$tableName ($columnList) VALUES ($placeholders)";

    writeLog("INSERT Statement: $insertSql", true, DLOG_DBG);

    // Lese CSV zeilenweise und füge Daten ein
    $rowCount = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $params = [];
        foreach ($csvColumns as $i => $col) {
            $value = isset($row[$csvColumnIndices[$i]]) ? $row[$csvColumnIndices[$i]] : null;
            if (in_array($col, $notNullColumns)) {
                // NOT NULL Spalten: Leerstring beibehalten
                $params[':' . $col] = $value ?? '';
            } else {
                // Nullable Spalten: Leerstring zu NULL konvertieren
                $params[':' . $col] = ($value === '' || $value === null) ? null : $value;
            }
        }
        $db->execute($insertSql, $params);
        $rowCount++;
    }

    fclose($handle);

    writeLog("$rowCount Zeilen in $schema.$tableName importiert", true, DLOG_INF);

    return ['rows' => $rowCount];
}

/**
 * API-Funktion: Gibt die Namen der Auth- und Company-Datenbank zurück
 *
 * POST /api/update/
 * { "action": "getDatabaseNames" }
 *
 * @param array $data Request-Daten
 * @return void
 * @testdata {}
 */
function getDatabaseNames($data) {
    try {
        $authDbName = defined('DB_AUTH_NAME') ? DB_AUTH_NAME : 'unbekannt';

        // Company-DB-Name aus der aktuellen Verbindung ermitteln
        $companyDb = DbhCompany::begin();
        $result = $companyDb->getOne("SELECT current_database() AS dbname", []);
        $companyDbName = $result['dbname'] ?? 'unbekannt';

        resultInfo(true, '', [
            'auth_db' => $authDbName,
            'company_db' => $companyDbName
        ]);
    } catch (Exception $e) {
        writeLog("Fehler beim Ermitteln der DB-Namen: " . $e->getMessage(), true, DLOG_ERR);
        resultInfo(false, 'Fehler beim Ermitteln der Datenbanknamen: ' . $e->getMessage());
    }
}

/**
 * API-Funktion: Aktualisiert die Auth-DB und ALLE Company-Datenbanken
 *
 * Liest alle Mandanten aus auth.clients und fuehrt fuer jede Datenbank
 * ein Backup und Schema-Update durch.
 *
 * POST /api/update/
 * {
 *   "action": "updateAllDatabases",
 *   "dry_run": true  // optional
 * }
 *
 * @param array $data Request-Daten
 * @return void
 */
function updateAllDatabases($data) {
    set_time_limit(0);

    $dryRun = isset($data['dry_run']) && $data['dry_run'] === true;
    $upstallBaseDir = __DIR__ . '/../../upstall/';

    $allResults = [
        'success' => true,
        'git' => null,
        'auth_backup' => null,
        'auth' => null,
        'clients' => [],
        'dry_run' => $dryRun
    ];

    // ── 1. Backup Auth-DB ──
    require_once __DIR__ . '/../developer-tools/database-backup.php';

    $authCredentials = [
        'dbhost' => DB_HOST,
        'dbport' => DB_PORT,
        'dbname' => DB_AUTH_NAME,
        'dbuser' => DB_AUTH_USER,
        'dbpasswd' => DB_AUTH_PASS
    ];

    if (!$dryRun) {
        $authBackup = createAutoBackupForClient($authCredentials);
        $allResults['auth_backup'] = $authBackup;
        if ($authBackup['success']) {
            writeLog("Auto-Backup Auth-DB erstellt: " . $authBackup['filename'], true, DLOG_INF);
        } else {
            writeLog("Auto-Backup Auth-DB fehlgeschlagen: " . $authBackup['error'], true, DLOG_WRN);
        }
    }

    // ── 3. Auth-DB Schema-Update ──
    $authSqlFiles = [];
    $authCsvFiles = ['auth' => []];

    // Auth-Schema aus CRM (Basis)
    $authSchemaFile = $upstallBaseDir . 'crm/auth_schema.sql';
    if (file_exists($authSchemaFile)) {
        $authSqlFiles[] = $authSchemaFile;
    }

    // Auth-CSV-Daten aus CRM
    $authCsvDir = $upstallBaseDir . 'crm/auth_data/';
    if (is_dir($authCsvDir)) {
        foreach (glob($authCsvDir . '*.csv') as $csvFile) {
            $authCsvFiles['auth'][] = $csvFile;
        }
    }

    // Auth-Schemas aus Feature-Verzeichnissen werden spaeter pro Client geladen
    // (da Features pro Client unterschiedlich sein koennen)

    if (!empty($authSqlFiles)) {
        $authUpdateResult = updateDatabaseSchema($authSqlFiles, $authCsvFiles, $dryRun);
        $allResults['auth'] = $authUpdateResult;
        if (!$authUpdateResult['success']) {
            $allResults['success'] = false;
        }
    }

    // ── 4. Alle Clients aus auth.clients laden ──
    $auth = DbhAuth::begin();
    $clientsQuery = "SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients ORDER BY id";
    $clients = $auth->getAll($clientsQuery, []);

    if (empty($clients)) {
        writeLog("Keine Mandanten in auth.clients gefunden", true, DLOG_WRN);
    }

    // ── 5. Pro Client: Backup + Schema-Update ──
    foreach ($clients as $client) {
        $clientResult = [
            'id' => $client['id'],
            'name' => $client['name'],
            'dbname' => $client['dbname'],
            'backup' => null,
            'update' => null,
            'features' => ['crm']
        ];

        $clientCredentials = [
            'dbhost' => $client['dbhost'],
            'dbport' => $client['dbport'],
            'dbname' => $client['dbname'],
            'dbuser' => $client['dbuser'],
            'dbpasswd' => $client['dbpasswd']
        ];

        // Backup dieser Client-DB
        if (!$dryRun) {
            $clientBackup = createAutoBackupForClient($clientCredentials);
            $clientResult['backup'] = $clientBackup;
            if ($clientBackup['success']) {
                writeLog("Backup fuer Mandant '{$client['name']}' erstellt: " . $clientBackup['filename'], true, DLOG_INF);
            } else {
                writeLog("Backup fuer Mandant '{$client['name']}' fehlgeschlagen: " . $clientBackup['error'], true, DLOG_WRN);
            }
        }

        // Verbindung zu dieser Client-DB herstellen
        try {
            $clientPdo = connectPDO(
                $client['dbhost'],
                $client['dbport'],
                $client['dbname'],
                $client['dbuser'],
                $client['dbpasswd']
            );
            $clientDb = new ApiDatabase($clientPdo);

            // Features aus dieser Client-DB lesen
            $featureDirs = ['crm'];
            try {
                $featureResult = $clientDb->getOne(
                    "SELECT value FROM defaults_oserp WHERE key = 'features'",
                    []
                );
                if ($featureResult && !empty($featureResult['value'])) {
                    $feature = trim($featureResult['value']);
                    if (preg_match('/^[a-zA-Z0-9_-]+$/', $feature)) {
                        $featureDirs[] = $feature;
                        writeLog("Feature '$feature' fuer Mandant '{$client['name']}' geladen", true, DLOG_INF);
                    }
                }
            } catch (Exception $e) {
                writeLog("Feature konnte nicht aus '{$client['dbname']}' gelesen werden: " . $e->getMessage(), true, DLOG_WRN);
            }

            $clientResult['features'] = $featureDirs;

            // SQL- und CSV-Dateien fuer diesen Client sammeln
            $companySqlFiles = [];
            $companyCsvFiles = ['company' => []];

            foreach ($featureDirs as $featureDir) {
                $featurePath = $upstallBaseDir . $featureDir . '/';
                if (!is_dir($featurePath)) {
                    continue;
                }

                // Company-Schema
                $companyFile = $featurePath . 'company_schema.sql';
                if (file_exists($companyFile)) {
                    $companySqlFiles[] = $companyFile;
                }

                // Company-CSV-Daten
                $companyCsvDir = $featurePath . 'company_data/';
                if (is_dir($companyCsvDir)) {
                    foreach (glob($companyCsvDir . '*.csv') as $csvFile) {
                        $companyCsvFiles['company'][] = $csvFile;
                    }
                }

                // Auth-Schema aus Feature-Verzeichnis (falls vorhanden)
                $featureAuthFile = $featurePath . 'auth_schema.sql';
                if ($featureDir !== 'crm' && file_exists($featureAuthFile)) {
                    // Feature-spezifische Auth-Schemas werden einmalig auf die Auth-DB angewendet
                    $featureAuthResult = updateDatabaseSchema([$featureAuthFile], [], $dryRun);
                    if (!$featureAuthResult['success']) {
                        writeLog("Feature-Auth-Schema '$featureDir' fehlgeschlagen", true, DLOG_WRN);
                    }
                }
            }

            // Schema-Update fuer diese Client-DB ausfuehren
            if (!empty($companySqlFiles)) {
                $clientUpdateResult = updateDatabaseSchema($companySqlFiles, $companyCsvFiles, $dryRun, $clientDb);
                $clientResult['update'] = $clientUpdateResult;
                if (!$clientUpdateResult['success']) {
                    $allResults['success'] = false;
                }
            } else {
                $clientResult['update'] = [
                    'success' => true,
                    'messages' => [['type' => 'info', 'text' => 'Keine SQL-Dateien gefunden']],
                    'errors' => []
                ];
            }

        } catch (Exception $e) {
            $clientResult['update'] = [
                'success' => false,
                'messages' => [],
                'errors' => ["Verbindung zu '{$client['dbname']}' fehlgeschlagen: " . $e->getMessage()]
            ];
            $allResults['success'] = false;
            writeLog("Fehler bei Mandant '{$client['name']}': " . $e->getMessage(), true, DLOG_ERR);
        }

        $allResults['clients'][] = $clientResult;
    }

    // Ergebnis zurueckgeben
    $message = $allResults['success']
        ? ($dryRun ? 'Vorschau erfolgreich (Dry-Run)' : 'Alle Datenbanken erfolgreich aktualisiert')
        : 'Fehler bei der Aktualisierung';
    resultInfo($allResults['success'], $message, $allResults);
}