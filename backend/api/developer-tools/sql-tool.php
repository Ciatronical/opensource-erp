<?php
// backend/api/developer-tools/sql-tool.php

/**
 * Führt eine SQL-Abfrage aus (unterstützt mehrere Queries getrennt durch Semikolon)
 *
 * @param string $data['query'] SQL-Query(s) - mehrere Queries mit Semikolon getrennt möglich
 * @param string $data['database'] Datenbank: company oder auth (optional, default: company)
 * @testdata {"query": "SELECT * FROM customer LIMIT 10", "database": "company"}
 */
function executeSql($data) {
    if (!isset($data['query']) || empty(trim($data['query']))) {
        // writeLog("ERROR: Keine SQL-Abfrage angegeben");
        resultInfo(false, 'Keine SQL-Abfrage angegeben');
        return;
    }

    $queryInput = trim($data['query']);
    $database = isset($data['database']) ? $data['database'] : 'company';

    // Kommentare werden im splitSqlStatements()-Parser entfernt,
    // damit Strings mit -- oder /* */ nicht zerstoert werden.

    $auth = DbhAuth::begin();
    $sessionQuery = "SELECT s.user_id
                     FROM auth.session_oserp s
                     WHERE s.session_id = :session_id";

    $context = $auth->getOne($sessionQuery, [':session_id' => $auth->getCookie()]);

    if (!$context) {
        // writeLog("ERROR: Keine Session bei executeSql");
        resultInfo(false, 'Keine gültige Session');
        return;
    }

    $userId = $context['user_id'];

    // Wähle Datenbank basierend auf Parameter für Query-Ausführung
    if ($database === 'auth') {
        $db = DbhAuth::begin();
    } else {
        $db = DbhCompany::begin();
    }

    // Splitte mehrere Queries (getrennt durch Semikolon, aber nicht innerhalb von Strings)
    $queries = splitSqlStatements($queryInput);

    // Filtere leere Queries
    $queries = array_values(array_filter(array_map(function($query) {
        $query = trim($query);
        return empty($query) ? null : $query;
    }, $queries)));

    if (empty($queries)) {
        resultInfo(false, 'Keine gültige SQL-Abfrage');
        return;
    }

    // History immer in Company-DB speichern
    $companyDb = DbhCompany::begin();
    $allResults = [];
    $totalRows = 0;
    $totalExecutionTime = 0;

    try {
        foreach ($queries as $index => $query) {
            if (empty($query)) {
                continue;
            }

            // Automatisches LIMIT für SELECT-Queries ohne LIMIT (verhindert Speicher-Exhaustion)
            $autoLimited = false;
            $autoLimit = 5000;
            $queryUpperCheck = strtoupper(ltrim($query));
            if (strpos($queryUpperCheck, 'SELECT') === 0 && !preg_match('/\bLIMIT\b/i', $query)) {
                $queryToExecute = $query . ' LIMIT ' . $autoLimit;
                $autoLimited = true;
            } else {
                $queryToExecute = $query;
            }

            $startTime = microtime(true);
            $stmt = $db->query($queryToExecute);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $totalExecutionTime += $executionTime;

            if (!empty($result)) {
                $rowCount = count($result);
                $totalRows += $rowCount;
                $columns = array_keys($result[0]);

                // Analysiere Query für Edit-Funktionalität
                $editInfo = analyzeQueryForEditing($query, $db, $database);

                $allResults[] = [
                    'query' => $query,
                    'rows' => $result,
                    'columns' => $columns,
                    'row_count' => $rowCount,
                    'execution_time' => $executionTime,
                    'editable' => $editInfo['editable'],
                    'table_name' => $editInfo['table_name'],
                    'primary_key' => $editInfo['primary_key'],
                    'edit_reason' => $editInfo['reason'],
                    'auto_limited' => $autoLimited,
                    'auto_limit' => $autoLimited ? $autoLimit : null
                ];

                // Speichere Query in History
                saveQueryToHistory($companyDb, $userId, $query, $executionTime, $rowCount, $database);
            } else {
                $affectedRows = $stmt->rowCount();
                $totalRows += $affectedRows;

                $queryUpper = strtoupper($query);
                if (strpos($queryUpper, 'INSERT') === 0) {
                    $message = 'Einfügen erfolgreich';
                } elseif (strpos($queryUpper, 'UPDATE') === 0) {
                    $message = 'Aktualisierung erfolgreich';
                } elseif (strpos($queryUpper, 'DELETE') === 0) {
                    $message = 'Löschen erfolgreich';
                } elseif (strpos($queryUpper, 'CREATE') === 0) {
                    $message = 'Objekt erfolgreich erstellt';
                } elseif (strpos($queryUpper, 'DROP') === 0) {
                    $message = 'Objekt erfolgreich gelöscht';
                } elseif (strpos($queryUpper, 'ALTER') === 0) {
                    $message = 'Objekt erfolgreich geändert';
                } else {
                    $message = 'Befehl erfolgreich ausgeführt';
                }

                $allResults[] = [
                    'query' => $query,
                    'message' => $message,
                    'affected_rows' => $affectedRows,
                    'execution_time' => $executionTime
                ];

                // Speichere Query in History
                saveQueryToHistory($companyDb, $userId, $query, $executionTime, $affectedRows, $database);
            }
        }

        // Wenn nur eine Query: altes Format beibehalten (Kompatibilität)
        if (count($allResults) === 1) {
            $singleResult = $allResults[0];
            if (isset($singleResult['rows'])) {
                resultInfo(true, 'Abfrage erfolgreich', [
                    'rows' => $singleResult['rows'],
                    'columns' => $singleResult['columns'],
                    'row_count' => $singleResult['row_count'],
                    'execution_time' => $singleResult['execution_time'],
                    'editable' => $singleResult['editable'],
                    'table_name' => $singleResult['table_name'],
                    'primary_key' => $singleResult['primary_key'],
                    'edit_reason' => $singleResult['edit_reason'],
                    'auto_limited' => $singleResult['auto_limited'] ?? false,
                    'auto_limit' => $singleResult['auto_limit'] ?? null
                ]);
            } else {
                resultInfo(true, $singleResult['message'], [
                    'success' => true,
                    'message' => $singleResult['message'],
                    'affected_rows' => $singleResult['affected_rows'],
                    'execution_time' => $singleResult['execution_time']
                ]);
            }
        } else {
            // Multiple Queries: neues Format mit Array
            resultInfo(true, count($allResults) . ' Queries erfolgreich ausgeführt', [
                'multiple' => true,
                'results' => $allResults,
                'total_queries' => count($allResults),
                'total_rows' => $totalRows,
                'total_execution_time' => round($totalExecutionTime, 2)
            ]);
        }
    } catch (PDOException $e) {
        $executionTime = isset($startTime) ? round((microtime(true) - $startTime) * 1000, 2) : 0;
        // writeLog("ERROR: SQL-Abfrage fehlgeschlagen: " . $e->getMessage());
        // writeLog("Query: " . $queryInput);
        resultInfo(false, 'SQL-Fehler: ' . $e->getMessage());
    }
}

/**
 * Analysiert einen SELECT-Query, ob er editierbar ist
 */
function analyzeQueryForEditing($query, $db, $database) {
    $result = [
        'editable' => false,
        'table_name' => null,
        'primary_key' => null,
        'reason' => ''
    ];

    $queryUpper = strtoupper(trim($query));

    // Nur SELECT-Queries sind editierbar
    if (strpos($queryUpper, 'SELECT') !== 0) {
        $result['reason'] = 'Nur SELECT-Queries sind editierbar';
        return $result;
    }

    // Prüfe auf nicht-editierbare Konstrukte
    if (preg_match('/\bJOIN\b/i', $query)) {
        $result['reason'] = 'JOINs werden nicht unterstützt';
        return $result;
    }

    if (preg_match('/\bGROUP\s+BY\b/i', $query)) {
        $result['reason'] = 'GROUP BY wird nicht unterstützt';
        return $result;
    }

    if (preg_match('/\bDISTINCT\b/i', $query)) {
        $result['reason'] = 'DISTINCT wird nicht unterstützt';
        return $result;
    }

    if (preg_match('/\bUNION\b/i', $query)) {
        $result['reason'] = 'UNION wird nicht unterstützt';
        return $result;
    }

    // Prüfe auf Aggregatfunktionen
    if (preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $query)) {
        $result['reason'] = 'Aggregatfunktionen werden nicht unterstützt';
        return $result;
    }

    // Extrahiere Tabellennamen aus FROM
    if (!preg_match('/\bFROM\s+([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?)/i', $query, $matches)) {
        $result['reason'] = 'Tabellenname konnte nicht ermittelt werden';
        return $result;
    }

    $tableName = $matches[1];

    // Entferne Schema-Prefix wenn vorhanden
    if (strpos($tableName, '.') !== false) {
        $parts = explode('.', $tableName);
        $tableName = end($parts);
    }

    // Hole Primärschlüssel
    $schema = ($database === 'auth') ? 'auth' : 'public';
    $primaryKey = getTablePrimaryKeyInternal($db, $tableName, $schema);

    if (!$primaryKey) {
        $result['reason'] = 'Kein Primärschlüssel gefunden';
        return $result;
    }

    $result['editable'] = true;
    $result['table_name'] = $tableName;
    $result['primary_key'] = $primaryKey;
    $result['reason'] = '';

    return $result;
}

/**
 * Interne Funktion: Ermittelt den Primärschlüssel einer Tabelle
 */
function getTablePrimaryKeyInternal($db, $tableName, $schema = 'public') {
    $query = "SELECT a.attname as column_name
              FROM pg_index i
              JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
              JOIN pg_class c ON c.oid = i.indrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE i.indisprimary
              AND c.relname = :table_name
              AND n.nspname = :schema";

    $result = $db->getOne($query, [
        ':table_name' => $tableName,
        ':schema' => $schema
    ]);

    return $result ? $result['column_name'] : null;
}

/**
 * Gibt den Primärschlüssel einer Tabelle zurück
 *
 * @param string $data['table'] Name der Tabelle
 * @param string $data['database'] Datenbank: company oder auth (optional, default: company)
 * @testdata {"table": "customer", "database": "company"}
 */
function getTablePrimaryKey($data) {
    if (!isset($data['table']) || empty(trim($data['table']))) {
        resultInfo(false, 'Kein Tabellenname angegeben');
        return;
    }

    $tableName = trim($data['table']);
    $database = isset($data['database']) ? $data['database'] : 'company';

    if ($database === 'auth') {
        $db = DbhAuth::begin();
        $schema = 'auth';
    } else {
        $db = DbhCompany::begin();
        $schema = 'public';
    }

    $primaryKey = getTablePrimaryKeyInternal($db, $tableName, $schema);

    if ($primaryKey) {
        resultInfo(true, '', ['primary_key' => $primaryKey, 'table' => $tableName]);
    } else {
        resultInfo(false, 'Kein Primärschlüssel gefunden für Tabelle: ' . $tableName);
    }
}

/**
 * Aktualisiert eine Zeile in einer Tabelle
 *
 * @param string $data['table'] Name der Tabelle
 * @param string $data['primary_key'] Name der Primärschlüssel-Spalte
 * @param mixed $data['primary_value'] Wert des Primärschlüssels
 * @param array $data['updates'] Assoziatives Array mit Spalte => Wert
 * @param string $data['database'] Datenbank: company oder auth (optional, default: company)
 * @testdata {"table": "customer", "primary_key": "id", "primary_value": 1, "updates": {"name": "Test"}, "database": "company"}
 */
function updateTableRow($data) {
    // Validierung
    if (!isset($data['table']) || empty(trim($data['table']))) {
        resultInfo(false, 'Kein Tabellenname angegeben');
        return;
    }

    if (!isset($data['primary_key']) || empty(trim($data['primary_key']))) {
        resultInfo(false, 'Kein Primärschlüssel angegeben');
        return;
    }

    if (!isset($data['primary_value'])) {
        resultInfo(false, 'Kein Primärschlüssel-Wert angegeben');
        return;
    }

    if (!isset($data['updates']) || !is_array($data['updates']) || empty($data['updates'])) {
        resultInfo(false, 'Keine Änderungen angegeben');
        return;
    }

    $tableName = trim($data['table']);
    $primaryKey = trim($data['primary_key']);
    $primaryValue = $data['primary_value'];
    $updates = $data['updates'];
    $database = isset($data['database']) ? $data['database'] : 'company';

    // Validiere Tabellen- und Spaltennamen (nur alphanumerisch und Unterstrich)
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
        resultInfo(false, 'Ungültiger Tabellenname');
        return;
    }

    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $primaryKey)) {
        resultInfo(false, 'Ungültiger Primärschlüssel-Name');
        return;
    }

    // Validiere Spaltennamen in Updates
    foreach (array_keys($updates) as $column) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            resultInfo(false, 'Ungültiger Spaltenname: ' . $column);
            return;
        }
    }

    // Wähle Datenbank
    if ($database === 'auth') {
        $db = DbhAuth::begin();
        $schema = 'auth';
    } else {
        $db = DbhCompany::begin();
        $schema = 'public';
    }

    // Prüfe ob Tabelle existiert und Primärschlüssel korrekt ist
    $realPrimaryKey = getTablePrimaryKeyInternal($db, $tableName, $schema);
    if ($realPrimaryKey !== $primaryKey) {
        resultInfo(false, 'Primärschlüssel stimmt nicht überein');
        return;
    }

    // Baue UPDATE-Query
    $setClauses = [];
    $params = [':pk_value' => $primaryValue];
    $paramIndex = 0;

    foreach ($updates as $column => $value) {
        $paramName = ':param_' . $paramIndex;
        $setClauses[] = "\"$column\" = $paramName";
        $params[$paramName] = $value;
        $paramIndex++;
    }

    $setClause = implode(', ', $setClauses);
    $fullTableName = ($database === 'auth') ? "auth.\"$tableName\"" : "\"$tableName\"";

    $query = "UPDATE $fullTableName SET $setClause WHERE \"$primaryKey\" = :pk_value";

    try {
        $db->execute($query, $params);

        // Lade aktualisierte Zeile
        $selectQuery = "SELECT * FROM $fullTableName WHERE \"$primaryKey\" = :pk_value";
        $updatedRow = $db->getOne($selectQuery, [':pk_value' => $primaryValue]);

        resultInfo(true, 'Zeile erfolgreich aktualisiert', [
            'updated_row' => $updatedRow
        ]);
    } catch (PDOException $e) {
        // writeLog("ERROR: Update fehlgeschlagen: " . $e->getMessage());
        resultInfo(false, 'Update fehlgeschlagen: ' . $e->getMessage());
    }
}

/**
 * Löscht eine Zeile aus einer Tabelle
 *
 * @param string $data['table'] Name der Tabelle
 * @param string $data['primary_key'] Name der Primärschlüssel-Spalte
 * @param mixed $data['primary_value'] Wert des Primärschlüssels
 * @param string $data['database'] Datenbank: company oder auth (optional, default: company)
 * @testdata {"table": "customer", "primary_key": "id", "primary_value": 1, "database": "company"}
 */
function deleteTableRow($data) {
    // Validierung
    if (!isset($data['table']) || empty(trim($data['table']))) {
        resultInfo(false, 'Kein Tabellenname angegeben');
        return;
    }

    if (!isset($data['primary_key']) || empty(trim($data['primary_key']))) {
        resultInfo(false, 'Kein Primärschlüssel angegeben');
        return;
    }

    if (!isset($data['primary_value'])) {
        resultInfo(false, 'Kein Primärschlüssel-Wert angegeben');
        return;
    }

    $tableName = trim($data['table']);
    $primaryKey = trim($data['primary_key']);
    $primaryValue = $data['primary_value'];
    $database = isset($data['database']) ? $data['database'] : 'company';

    // Validiere Tabellen- und Spaltennamen
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
        resultInfo(false, 'Ungültiger Tabellenname');
        return;
    }

    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $primaryKey)) {
        resultInfo(false, 'Ungültiger Primärschlüssel-Name');
        return;
    }

    // Wähle Datenbank
    if ($database === 'auth') {
        $db = DbhAuth::begin();
        $schema = 'auth';
    } else {
        $db = DbhCompany::begin();
        $schema = 'public';
    }

    // Prüfe ob Tabelle existiert und Primärschlüssel korrekt ist
    $realPrimaryKey = getTablePrimaryKeyInternal($db, $tableName, $schema);
    if ($realPrimaryKey !== $primaryKey) {
        resultInfo(false, 'Primärschlüssel stimmt nicht überein');
        return;
    }

    $fullTableName = ($database === 'auth') ? "auth.\"$tableName\"" : "\"$tableName\"";

    // Hole Zeile vor dem Löschen (für Anzeige)
    $selectQuery = "SELECT * FROM $fullTableName WHERE \"$primaryKey\" = :pk_value";
    $deletedRow = $db->getOne($selectQuery, [':pk_value' => $primaryValue]);

    if (!$deletedRow) {
        resultInfo(false, 'Zeile nicht gefunden');
        return;
    }

    $query = "DELETE FROM $fullTableName WHERE \"$primaryKey\" = :pk_value";

    try {
        $db->execute($query, [':pk_value' => $primaryValue]);

        resultInfo(true, 'Zeile erfolgreich gelöscht', [
            'deleted_row' => $deletedRow
        ]);
    } catch (PDOException $e) {
        // writeLog("ERROR: Delete fehlgeschlagen: " . $e->getMessage());

        // Prüfe auf Foreign Key Constraint
        if (strpos($e->getMessage(), 'foreign key constraint') !== false ||
            strpos($e->getMessage(), 'violates foreign key') !== false) {
            resultInfo(false, 'Löschen nicht möglich: Die Zeile wird von anderen Datensätzen referenziert');
        } else {
            resultInfo(false, 'Löschen fehlgeschlagen: ' . $e->getMessage());
        }
    }
}

/**
 * Speichert einen erfolgreichen Query in der History
 */
function saveQueryToHistory($db, $userId, $query, $executionTime, $rowCount, $databaseType) {
    try {
        $insertQuery = "INSERT INTO sql_query_history
                        (user_id, query, execution_time, row_count, database_type, itime)
                        VALUES (:user_id, :query, :execution_time, :row_count, :database_type, CURRENT_TIMESTAMP)";

        $db->execute($insertQuery, [
            ':user_id' => $userId,
            ':query' => $query,
            ':execution_time' => $executionTime,
            ':row_count' => $rowCount,
            ':database_type' => $databaseType
        ]);
    } catch (Exception $e) {
        // writeLog("WARNING: Query-History konnte nicht gespeichert werden: " . $e->getMessage());
    }
}

/**
 * Gibt die Query-History des aktuellen Users zurück
 *
 * @param int $data['limit'] Maximale Anzahl an Queries (optional, default: 50, max: 500)
 * @param string $data['database'] Datenbank: company oder auth (optional, default: company)
 * @testdata {"limit": 50, "database": "company"}
 */
function getQueryHistory($data) {
    $auth = DbhAuth::begin();
    $sessionQuery = "SELECT s.user_id
                     FROM auth.session_oserp s
                     WHERE s.session_id = :session_id";

    $context = $auth->getOne($sessionQuery, [':session_id' => $auth->getCookie()]);

    if (!$context) {
        // writeLog("ERROR: Keine Session bei getQueryHistory");
        resultInfo(false, 'Keine gültige Session');
        return;
    }

    $userId = $context['user_id'];
    $limit = isset($data['limit']) ? (int)$data['limit'] : 50;
    $limit = min($limit, 500);
    $database = isset($data['database']) ? $data['database'] : 'company';

    $db = DbhCompany::begin();

    $query = "SELECT id, query, execution_time, row_count, database_type,
                     TO_CHAR(itime, 'DD.MM.YYYY HH24:MI:SS') as formatted_time,
                     itime
              FROM sql_query_history
              WHERE user_id = :user_id
              AND database_type = :database_type
              ORDER BY itime DESC
              LIMIT :limit";

    $history = $db->getAll($query, [
        ':user_id' => $userId,
        ':database_type' => $database,
        ':limit' => $limit
    ]);

    resultInfo(true, '', ['history' => $history]);
}

/**
 * Löscht einen Query aus der History
 *
 * @param int $data['id'] ID des zu löschenden Queries
 * @testdata {"id": 1}
 */
function deleteQueryFromHistory($data) {
    if (!isset($data['id'])) {
        resultInfo(false, 'Keine Query-ID angegeben');
        return;
    }

    $queryId = (int)$data['id'];

    $auth = DbhAuth::begin();
    $sessionQuery = "SELECT s.user_id
                     FROM auth.session_oserp s
                     WHERE s.session_id = :session_id";

    $context = $auth->getOne($sessionQuery, [':session_id' => $auth->getCookie()]);

    if (!$context) {
        // writeLog("ERROR: Keine Session bei deleteQueryFromHistory");
        resultInfo(false, 'Keine gültige Session');
        return;
    }

    $userId = $context['user_id'];
    $db = DbhCompany::begin();

    $deleteQuery = "DELETE FROM sql_query_history
                    WHERE id = :id AND user_id = :user_id";

    $db->execute($deleteQuery, [
        ':id' => $queryId,
        ':user_id' => $userId
    ]);

    resultInfo(true, 'Query erfolgreich gelöscht');
}

/**
 * Löscht die gesamte Query-History des aktuellen Users
 *
 * @param string $data['database'] Datenbank: company oder auth (optional, default: company)
 * @testdata {"database": "company"}
 */
function clearQueryHistory($data) {
    $auth = DbhAuth::begin();
    $sessionQuery = "SELECT s.user_id
                     FROM auth.session_oserp s
                     WHERE s.session_id = :session_id";

    $context = $auth->getOne($sessionQuery, [':session_id' => $auth->getCookie()]);

    if (!$context) {
        // writeLog("ERROR: Keine Session bei clearQueryHistory");
        resultInfo(false, 'Keine gültige Session');
        return;
    }

    $userId = $context['user_id'];
    $database = isset($data['database']) ? $data['database'] : 'company';
    $db = DbhCompany::begin();

    $deleteQuery = "DELETE FROM sql_query_history
                    WHERE user_id = :user_id
                    AND database_type = :database_type";

    $db->execute($deleteQuery, [
        ':user_id' => $userId,
        ':database_type' => $database
    ]);

    resultInfo(true, 'Query-History erfolgreich gelöscht');
}

/**
 * Gibt alle Tabellennamen zurück
 *
 * @param string $data['database'] Datenbank: company oder auth (optional, default: company)
 * @testdata {"database": "company"}
 */
function getTableNames($data) {
    $database = isset($data['database']) ? $data['database'] : 'company';

    if ($database === 'auth') {
        $db = DbhAuth::begin();
        $schema = 'auth';
    } else {
        $db = DbhCompany::begin();
        $schema = 'public';
    }

    $query = "SELECT table_name
              FROM information_schema.tables
              WHERE table_schema = :schema
              ORDER BY table_name";

    $result = $db->getAll($query, [':schema' => $schema]);

    $tables = [];
    foreach ($result as $row) {
        $tables[] = $row['table_name'];
    }

    resultInfo(true, '', ['tables' => $tables]);
}

/**
 * Gibt alle Spaltennamen für eine bestimmte Tabelle zurück
 *
 * @param string $data['table'] Name der Tabelle
 * @param string $data['database'] Datenbank: company oder auth (optional, default: company)
 * @testdata {"table": "customer", "database": "company"}
 */
function getColumnNames($data) {
    if (!isset($data['table']) || empty(trim($data['table']))) {
        resultInfo(false, 'Kein Tabellenname angegeben');
        return;
    }

    $tableName = trim($data['table']);
    $database = isset($data['database']) ? $data['database'] : 'company';

    if ($database === 'auth') {
        $db = DbhAuth::begin();
        $schema = 'auth';
    } else {
        $db = DbhCompany::begin();
        $schema = 'public';
    }

    $query = "SELECT column_name, data_type
              FROM information_schema.columns
              WHERE table_schema = :schema
              AND table_name = :table_name
              ORDER BY ordinal_position";

    $result = $db->getAll($query, [
        ':schema' => $schema,
        ':table_name' => $tableName
    ]);

    $columns = [];
    foreach ($result as $row) {
        $columns[] = [
            'name' => $row['column_name'],
            'type' => $row['data_type']
        ];
    }

    resultInfo(true, '', ['columns' => $columns, 'table' => $tableName]);
}

/**
 * Gibt SQL-Keywords zurück
 *
 * @testdata {}
 */
function getSqlKeywords($data) {
    $keywords = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE',
        'FROM', 'WHERE', 'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'OUTER JOIN',
        'ON', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'BETWEEN',
        'GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT', 'OFFSET',
        'DISTINCT', 'AS', 'ASC', 'DESC',
        'CREATE', 'ALTER', 'DROP', 'TRUNCATE',
        'ADD COLUMN', 'DROP COLUMN', 'RENAME COLUMN',
        'PRIMARY KEY', 'FOREIGN KEY', 'UNIQUE', 'NOT NULL', 'DEFAULT',
        'CHECK', 'REFERENCES',
        'INTEGER', 'BIGINT', 'SMALLINT', 'NUMERIC', 'DECIMAL',
        'VARCHAR', 'TEXT', 'CHAR', 'BOOLEAN',
        'DATE', 'TIMESTAMP', 'TIME', 'INTERVAL',
        'JSON', 'JSONB', 'ARRAY',
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX',
        'UPPER', 'LOWER', 'CONCAT', 'SUBSTRING',
        'NOW', 'CURRENT_DATE', 'CURRENT_TIMESTAMP',
        'VALUES', 'SET', 'RETURNING',
        'BEGIN', 'COMMIT', 'ROLLBACK',
        'GRANT', 'REVOKE',
        'INDEX', 'VIEW', 'SEQUENCE'
    ];

    resultInfo(true, '', ['keywords' => $keywords]);
}

/**
 * Gibt die Datenbanknamen zurück
 *
 * @testdata {}
 */
function getDatabaseNames($data) {
    $auth = DbhAuth::begin();
    $sessionQuery = "SELECT c.dbname as company_db
                     FROM auth.session_oserp s
                     JOIN auth.user u ON s.user_id = u.id
                     JOIN auth.clients c ON s.client_id = c.id
                     WHERE s.session_id = :session_id";

    $context = $auth->getOne($sessionQuery, [':session_id' => $auth->getCookie()]);

    if (!$context) {
        // writeLog("ERROR: Keine Session bei getDatabaseNames");
        resultInfo(false, 'Keine gültige Session');
        return;
    }

    // Auth-DB Name aus der aktuellen Connection holen
    $authDbQuery = "SELECT current_database() as auth_db";
    $authDb = $auth->getOne($authDbQuery);

    resultInfo(true, '', [
        'company' => $context['company_db'],
        'auth' => $authDb['auth_db']
    ]);
}

/**
 * Holt die Tabellenstruktur mit Spalten-Kommentaren aus pg_description
 *
 * Gibt Spalteninformationen inkl. COMMENT ON COLUMN zurück.
 * Die Spalten-Kommentare werden über pg_description mit objsubid = ordinal_position geholt.
 *
 * @param array $data Array mit 'table' (Tabellenname) und optional 'database' (company|auth)
 * @return string JSON mit Spaltenstruktur inkl. Kommentare
 * @testdata {"table": "employee_config_oserp", "database": "company"}
 */
function getTableStructure($data) {
    $tableName = $data['table'] ?? null;
    $database = $data['database'] ?? 'company';

    if (empty($tableName)) {
        resultInfo(false, 'Kein Tabellenname angegeben');
        return;
    }

    // Validiere Tabellenname (nur alphanumerisch und Unterstrich)
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
        resultInfo(false, 'Ungültiger Tabellenname');
        return;
    }

    if ($database === 'auth') {
        $db = DbhAuth::begin();
        $schema = 'auth';
    } else {
        $db = DbhCompany::begin();
        $schema = 'public';
    }

    // JSON komplett aus der Datenbank holen
    // Spalten-Kommentare: pg_description mit objsubid = ordinal_position
    // Tabellen-Kommentar: pg_description mit objsubid = 0
    $query = <<<SQL
        SELECT json_build_object(
            'table_name', '$tableName',
            'schema', '$schema',
            'table_comment', (
                SELECT COALESCE(pd.description, '')
                FROM pg_catalog.pg_class pc
                LEFT JOIN pg_catalog.pg_namespace pn ON pn.oid = pc.relnamespace
                LEFT JOIN pg_catalog.pg_description pd ON pd.objoid = pc.oid AND pd.objsubid = 0
                WHERE pc.relname = '$tableName'
                AND pn.nspname = '$schema'
            ),
            'primary_key', (
                SELECT a.attname
                FROM pg_index i
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                JOIN pg_class c ON c.oid = i.indrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE i.indisprimary
                AND c.relname = '$tableName'
                AND n.nspname = '$schema'
                LIMIT 1
            ),
            'columns', (
                SELECT json_agg(
                    json_build_object(
                        'column_name', c.column_name,
                        'data_type', c.data_type,
                        'is_nullable', c.is_nullable,
                        'column_default', c.column_default,
                        'ordinal_position', c.ordinal_position,
                        'comment', COALESCE(pd.description, '')
                    ) ORDER BY c.ordinal_position
                )
                FROM information_schema.columns c
                LEFT JOIN pg_catalog.pg_class pc ON pc.relname = c.table_name
                LEFT JOIN pg_catalog.pg_namespace pn ON pn.oid = pc.relnamespace AND pn.nspname = c.table_schema
                LEFT JOIN pg_catalog.pg_description pd ON pd.objoid = pc.oid AND pd.objsubid = c.ordinal_position
                WHERE c.table_schema = '$schema'
                AND c.table_name = '$tableName'
            )
        ) AS structure
    SQL;

    echo $db->get($query);
}

/**
 * Holt alle Tabellennamen aus der Datenbank mit Beschreibungen (falls vorhanden)
 *
 * Die Beschreibungen kommen aus pg_description (COMMENT ON TABLE)
 * Falls keine DB-Beschreibung vorhanden ist, wird im Frontend ein Fallback verwendet
 *
 * @param array $data Optionale Parameter (database: 'company' oder 'auth')
 * @return string JSON mit Tabellenliste
 */
function getKivitendoTables($data) {
    $database = $data['database'] ?? 'company';

    if ($database === 'auth') {
        $db = DbhAuth::begin();
    } else {
        $db = DbhCompany::begin();
    }

    // JSON komplett aus der Datenbank - get() baut automatisch success/payload drum
    $query = <<<SQL
        SELECT json_agg(
            json_build_object(
                'table_name', t.tablename,
                'schema', t.schemaname,
                'description', COALESCE(pd.description, '')
            ) ORDER BY t.tablename
        ) AS tables
        FROM pg_catalog.pg_tables t
        LEFT JOIN pg_catalog.pg_class c ON c.relname = t.tablename
        LEFT JOIN pg_catalog.pg_description pd ON pd.objoid = c.oid AND pd.objsubid = 0
        WHERE t.schemaname IN ('public', 'auth')
        AND t.tablename NOT LIKE 'pg_%'
        AND t.tablename NOT LIKE 'sql_%'
    SQL;

    echo $db->get($query);
}

/**
 * Splittet SQL-Statements korrekt auf Semikolons,
 * ohne innerhalb von Single-Quoted Strings oder Dollar-Quoting zu trennen.
 * Entfernt SQL-Kommentare (-- und Block-Kommentare) nur ausserhalb von Strings.
 */
function splitSqlStatements($sql) {
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];

        // Einzeiliger Kommentar: -- bis Zeilenende (nur ausserhalb von Strings)
        if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            $eol = strpos($sql, "\n", $i);
            if ($eol !== false) {
                $i = $eol + 1;
            } else {
                $i = $len;
            }
            continue;
        }

        // Block-Kommentar: /* ... */ (nur ausserhalb von Strings)
        if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $close = strpos($sql, '*/', $i + 2);
            if ($close !== false) {
                $i = $close + 2;
            } else {
                $i = $len;
            }
            continue;
        }

        // Single-Quoted String: '...' (mit '' als Escape)
        if ($ch === "'") {
            $current .= $ch;
            $i++;
            while ($i < $len) {
                $current .= $sql[$i];
                if ($sql[$i] === "'") {
                    if ($i + 1 < $len && $sql[$i + 1] === "'") {
                        $current .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $i++;
            }
            continue;
        }

        // Dollar-Quoting: $tag$...$tag$ (oder $$...$$)
        if ($ch === '$') {
            $tagEnd = strpos($sql, '$', $i + 1);
            if ($tagEnd !== false) {
                $tag = substr($sql, $i, $tagEnd - $i + 1);
                $tagInner = substr($tag, 1, -1);
                if ($tagInner === '' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tagInner)) {
                    $current .= $tag;
                    $i = $tagEnd + 1;
                    $closePos = strpos($sql, $tag, $i);
                    if ($closePos !== false) {
                        $current .= substr($sql, $i, $closePos - $i + strlen($tag));
                        $i = $closePos + strlen($tag);
                    } else {
                        $current .= substr($sql, $i);
                        $i = $len;
                    }
                    continue;
                }
            }
            $current .= $ch;
            $i++;
            continue;
        }

        // Statement-Trenner
        if ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}