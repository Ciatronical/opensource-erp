<?php
// api/database.php

/**
 * Stellt eine Verbindung zur Datenbank her und gibt das PDO-Objekt zurück
 *
 * @param string $dbHost Datenbank-Host
 * @param string $dbPort Datenbank-Port
 * @param string $dbName Datenbank-Name
 * @param string $dbUser Datenbank-Benutzer
 * @param string $dbPass Datenbank-Passwort
 * @return PDO
 */
function connectPDO($dbHost, $dbPort, $dbName, $dbUser, $dbPass) {
    $pdo = new PDO(
        "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName",
        $dbUser,
        $dbPass,
        [PDO::ATTR_PERSISTENT => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

/**
 * Übersetzt technische PostgreSQL-Fehler in verständliche Meldungen.
 *
 * Bekannte SQLSTATEs bekommen einen klaren Text; alles andere wird unverändert
 * durchgereicht, damit bei Entwicklung/Support die Originalmeldung sichtbar bleibt.
 *
 * @param PDOException $e Die abgefangene Datenbank-Ausnahme
 * @return string Verständliche Fehlermeldung
 */
function dbFriendlyError(PDOException $e) {
    $sqlstate = $e->getCode();
    $raw = $e->getMessage();

    // 22003 = numeric_value_out_of_range (z. B. Nummernkreis-Zähler > INT-Bereich)
    if ($sqlstate === '22003' || stripos($raw, 'out of range') !== false) {
        return 'Der interne Nummernkreis wurde überschritten: Der zuletzt vergebene '
             . 'Wert ist zu gross für den Zahlentyp. Bitte die zuletzt vergebene '
             . 'Artikel- bzw. Dienstleistungsnummer in den Einstellungen auf einen '
             . 'kleineren Wert zurücksetzen.';
    }

    // 23505 = unique_violation
    if ($sqlstate === '23505' || stripos($raw, 'duplicate key') !== false) {
        return 'Der Datensatz existiert bereits (eindeutiger Wert doppelt vergeben).';
    }

    return $raw;
}

/**
 * Basisklasse für Datenbankverbindungen
 */
class ApiDatabase {
    protected $pdo = null;

    public function __construct($pdo = null) {
        $this->pdo = $pdo;
    }

    public function getPDO() {
        return $this->pdo;
    }

    public function __destruct() {
        $this->pdo = null;
    }

    public function query($sql) {
        return $this->pdo->query($sql);
    }

    public function prepare($sql) {
        return $this->pdo->prepare($sql);
    }

    public function prepareQuery($sql, $params = []) {
        $stmt = $this->prepare($sql);
        $this->bindTypedParams($stmt, $params);
        $stmt->execute();
        return $stmt;
    }

    public function lastInsertId($name = null) {
        if (null == $name) {
            return $this->pdo->lastInsertId();
        }
        return $this->pdo->lastInsertId($name);
    }

    public function fetch($sql) {
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetchAll($query) {
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchKeyValue($query) {
        $stmt = $this->pdo->query($query);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollBack() {
        return $this->pdo->rollBack();
    }

    /**
     * Bindet Parameter mit expliziten PDO-Typen an ein Statement
     *
     * Verhindert, dass PDO alle Werte als Strings bindet.
     * Ohne explizite Typen wird z.B. PHP false zu "" (leerer String),
     * was PostgreSQL für Boolean-Spalten ablehnt.
     *
     * @param PDOStatement $stmt Prepared Statement
     * @param array $params Key-value Array der Parameter
     */
    private function bindTypedParams($stmt, $params) {
        foreach ($params as $param => $value) {
            if ($value === null) {
                $stmt->bindValue($param, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_BOOL);
            } elseif (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
        }
    }

    public function execute($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $this->bindTypedParams($stmt, $params);
        return $stmt->execute();
    }

    /**
     * Bereinigt einen einzelnen Wert für PostgreSQL
     *
     * Konvertierungen:
     * - Leere Strings → NULL
     * - String "null" → NULL
     * - String "true" → Boolean true
     * - String "false" → Boolean false
     * - Leere Arrays → '{}'
     * - Numerische Arrays → PostgreSQL Array Format
     * - Assoziative Arrays → JSON
     *
     * @param mixed $value Der zu bereinigende Wert
     * @return mixed Bereinigter Wert
     */
    private function cleanValue($value) {
        // String-Bereinigung
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || $trimmed === 'null') {
                return null;
            } elseif ($trimmed === 'false') {
                return false;
            } elseif ($trimmed === 'true') {
                return true;
            }
            return $value;
        }

        // Leere Strings direkt zu NULL
        if ($value === '') {
            return null;
        }

        // Array-Behandlung
        if (is_array($value)) {
            if (empty($value)) {
                return '{}';
            }
            // Numerisches Array → PostgreSQL Array {a,b,c}
            if (array_keys($value) === range(0, count($value) - 1)) {
                return '{' . implode(',', array_map(function($v) {
                    if (is_string($v)) {
                        return '"' . str_replace('"', '\"', $v) . '"';
                    }
                    return $v;
                }, $value)) . '}';
            }
            // Assoziatives Array → JSON
            return json_encode($value);
        }

        // Alle anderen Werte unverändert
        return $value;
    }

    /**
     * Führt eine SQL-Abfrage aus und gibt das Ergebnis als JSON zurück
     * Baut automatisch ein JSON-Objekt mit "success" und "payload"
     *
     * @param string $query SQL-Abfrage
     * @param array $data Übergebene Daten, die im JSON-Objekt enthalten sein sollen
     * @return string JSON-String
     */
    public function get(string $query, array $data = []) {
        $dataJson = empty($data) ? null : json_encode($data);

        $sql = <<<SQL
            WITH payload_sql AS (
                $query
            ),
            payload_input AS (
                SELECT :data::jsonb AS data
            )
            SELECT json_build_object(
                'success', true,
                'payload',
                    coalesce(
                        (SELECT row_to_json(payload_sql)::jsonb FROM payload_sql),
                        '{}'::jsonb
                    )
                    ||
                    coalesce(
                        (SELECT data FROM payload_input),
                        '{}'::jsonb
                    )
            ) AS json_agg;
        SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['data' => $dataJson]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new ApiError("API_DATABASE_ERROR", dbFriendlyError($e));
        }
        if ($row === false) {
            throw new ApiError("DATA_NOT_FOUND", 'Keine Daten gefunden');
        }

        return $row['json_agg'];
    }

    /**
     * Führt eine SQL-Abfrage aus und gibt eine einzelne Zeile zurück
     *
     * @param string $query SQL-Abfrage
     * @return array
     * @throws ApiError wenn keine Daten gefunden wurden oder ein Datenbankfehler auftritt
     */
    public function getRow($query) {
        try {
            $stmt = $this->pdo->query($query);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new ApiError("API_DATABASE_ERROR", dbFriendlyError($e), $query);
        }
        if ($row === false) {
            throw new ApiError("DATA_NOT_FOUND", 'Keine Daten gefunden', $query);
        }

        return $row;
    }

    /**
     * Führt eine SQL-Abfrage mit Platzhaltern aus und gibt das PDOStatement zurück
     *
     * @param string $sql SQL-Abfrage mit Platzhaltern
     * @param array $params Key-value Array der Platzhalter und Werte
     * @return PDOStatement
     * @throws ApiError bei Datenbankfehlern
     */
    //public function prepareQuery($sql, $params = []) {
    //    $queryBuilder = new QueryBuilder($sql);
    //    $queryBuilder->bindParams($params);
    //    $query = $queryBuilder->buildQuery($this);
    //    try {
    //        $stmt = $this->pdo->query($query);
    //    } catch (PDOException $e) {
    //        throw new ApiError("API_DATABASE_ERROR", $e->getMessage(), $queryBuilder->getPreparedQuery());
    //    }
    //    return $stmt;
    //}

    /**
     * Führt eine SQL-Abfrage mit Platzhaltern aus und gibt eine einzelne Zeile zurück
     *
     * @param string $sql SQL-Abfrage mit Platzhaltern
     * @param array $params Key-value Array der Platzhalter und Werte
     * @return array|false
     * @throws ApiError bei Datenbankfehlern
     */
    public function getOne($sql, $params = []) {
        $stmt = $this->prepare($sql);
        $this->bindTypedParams($stmt, $params);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Führt eine SQL-Abfrage mit Platzhaltern aus und gibt alle Zeilen zurück
     *
     * @param string $sql SQL-Abfrage mit Platzhaltern
     * @param array $params Key-value Array der Platzhalter und Werte
     * @return array
     * @throws ApiError bei Datenbankfehlern
     */
    public function getAll($sql, $params = []) {
        $stmt = $this->prepare($sql);
        $this->bindTypedParams($stmt, $params);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fügt einen Datensatz in die angegebene Tabelle ein
     *
     * WICHTIG: Diese Methode bereinigt automatisch alle Werte:
     * - Leere Strings werden zu NULL
     * - String-Booleans ("true"/"false") werden zu echten Booleans
     * - Arrays werden zu PostgreSQL-Arrays oder JSON konvertiert
     *
     * @param string $table Tabellenname
     * @param array $fields Array der Feldnamen
     * @param array $values Array der Werte
     * @param bool $lastInsertId true => gibt die ID des letzten eingefügten Datensatzes zurück
     * @param string|null $sequence_name Name der Sequenz für die ID (optional)
     * @return PDOStatement|int
     */
    public function insert($table, $fields, $values, $lastInsertId = false, $sequence_name = null) {
        // Bereinige alle Werte
        $cleanedValues = array_map(function($value) {
            return $this->cleanValue($value);
        }, $values);

        $field_list = implode(',', $fields);
        $placeholder_list = implode(',', array_map(function ($f) {
            return ':' . $f;
        }, $fields));
        $sql = "INSERT INTO $table ( $field_list ) VALUES ( $placeholder_list )";
        $stmt = $this->pdo->prepare($sql);

        // Binde Parameter MIT expliziten Typen
        $params = array_combine(array_map(function ($f) {
            return ':' . $f;
        }, $fields), $cleanedValues);

        foreach ($params as $param => $value) {
            if ($value === null) {
                $stmt->bindValue($param, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_BOOL);
            } elseif (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();

        if ($lastInsertId) {
            if (null === $sequence_name) {
                return $this->pdo->lastInsertId();
            }
            return $this->pdo->lastInsertId($sequence_name);
        }
        return $stmt;
    }


    /**
    * Das Datenobjekt muss folgendes Schema haben:
    * data[record][tabellename][columnname]: wert
    * data[sequence_name]: id bzw. sequencename
    * @param array $data
    * @param bool $getLastId
    * @param bool $no_output
    */
    function genericSingleInsert( $data, $getLastId = false, $no_output = false ){
        //debugVar( $data, 'genericSingleInsert data' );
        $tableName = array_key_first( $data['record'] );
        $id = $this->insert( $tableName, array_keys( $data['record'][$tableName] ), array_values( $data['record'][$tableName] ),
                                    array_key_exists( 'sequence_name', $data ) || $getLastId, ( array_key_exists( 'sequence_name', $data ) )? $data['sequence_name'] : FALSE );
        $payload = array( "id" => $id );
        if( !$no_output ) resultInfo( true, 'genericSingleInsert()', $payload );
        else return $id;
    }

    /**
     * Aktualisiert einen Datensatz in der angegebenen Tabelle
     *
     * WICHTIG: Diese Methode bereinigt automatisch alle Werte:
     * - Leere Strings werden zu NULL
     * - String-Booleans ("true"/"false") werden zu echten Booleans
     * - Arrays werden zu PostgreSQL-Arrays oder JSON konvertiert
     *
     * @param string $table Tabellenname
     * @param array $fields Array der zu aktualisierenden Feldnamen
     * @param array $values Array der neuen Werte
     * @param string $where_clause WHERE-Klausel (ohne 'WHERE')
     * @return PDOStatement
     */
    public function update($table, $fields, $values, $where_clause) {
        // Bereinige alle Werte
        $cleanedValues = array_map(function($value) {
            return $this->cleanValue($value);
        }, $values);

        $set_list = implode(',', array_map(function ($f) {
            return $f . ' = :' . $f;
        }, $fields));
        $sql = "UPDATE $table SET $set_list WHERE $where_clause";
        $stmt = $this->pdo->prepare($sql);

        // Binde Parameter MIT expliziten Typen
        $params = array_combine(array_map(function ($f) {
            return ':' . $f;
        }, $fields), $cleanedValues);

        foreach ($params as $param => $value) {
            if ($value === null) {
                $stmt->bindValue($param, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_BOOL);
            } elseif (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        return $stmt;
    }

    /**
     * Aktualisiert einen Datensatz mit ECHTEN PDO Prepared Statements
     *
     * Unterschied zu update(): Hier werden echte Prepared Statements mit gebundenen Parametern verwendet.
     * Vorteile: Sichere Typ-Konvertierung, leere Strings werden zu NULL, Boolean werden korrekt behandelt
     *
     * @param string $table Tabellenname
     * @param array $data Key-Value Array der zu aktualisierenden Felder
     * @param array $excludeFields Array von Feldnamen die NICHT aktualisiert werden sollen (z.B. ['id', 'itime'])
     * @param string $where_clause WHERE-Klausel (optional, Standard: keine WHERE = alle Zeilen)
     * @param array $whereParams Parameter für die WHERE-Klausel (optional)
     * @param string $additionalSql Zusätzliche SET-Klauseln (z.B. "mtime = NOW()")
     * @return PDOStatement
     * @throws PDOException bei Datenbankfehlern
     *
     * @example
     * // Einfaches Update aller Zeilen
     * $db->updateRow('defaults', $data, ['id', 'itime', 'mtime']);
     *
     * @example
     * // Update mit WHERE-Klausel
     * $db->updateRow('customer', $data, ['id'], 'id = :id', [':id' => 123]);
     */
    public function updateRow($table, $data, $excludeFields = [], $where_clause = '', $whereParams = [], $additionalSql = '') {
        // Baue die SET-Klausel
        $fields = [];
        $params = [];

        foreach ($data as $key => $value) {
            // Überspringe ausgeschlossene Felder
            if (in_array($key, $excludeFields)) {
                continue;
            }

            // WICHTIG: Bereinige String-Booleans zu echten Booleans
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '' || $trimmed === 'null') {
                    $value = null;
                } elseif ($trimmed === 'false') {
                    $value = false;
                } elseif ($trimmed === 'true') {
                    $value = true;
                }
            }

            $fields[] = "$key = :$key";

            // Typ-Konvertierung mit expliziter Boolean-Behandlung
            if ($value === null) {
                $params[":$key"] = null;
            } elseif ($value === false) {
                $params[":$key"] = false;
            } elseif ($value === true) {
                $params[":$key"] = true;
            } elseif ($value === '') {
                $params[":$key"] = null;
            } elseif (is_array($value)) {
                // Arrays: Numerisch → PostgreSQL Array, Assoziativ → JSON
                if (empty($value)) {
                    $params[":$key"] = '{}';
                } else {
                    if (array_keys($value) === range(0, count($value) - 1)) {
                        // Numerisches Array → PostgreSQL Array {a,b,c}
                        $params[":$key"] = '{' . implode(',', array_map(function($v) {
                            if (is_string($v)) {
                                return '"' . str_replace('"', '\"', $v) . '"';
                            }
                            return $v;
                        }, $value)) . '}';
                    } else {
                        // Assoziatives Array → JSON
                        $params[":$key"] = json_encode($value);
                    }
                }
            } else {
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            throw new PDOException('Keine Felder zum Aktualisieren vorhanden');
        }

        $setClause = implode(', ', $fields);

        // Füge zusätzliche SET-Klauseln hinzu (z.B. mtime = NOW())
        if (!empty($additionalSql)) {
            $setClause .= ', ' . $additionalSql;
        }

        // Baue SQL-Statement
        $sql = "UPDATE $table SET $setClause";
        if (!empty($where_clause)) {
            $sql .= " WHERE $where_clause";
            $params = array_merge($params, $whereParams);
        }

        // Prepare Statement
        $stmt = $this->pdo->prepare($sql);

        // Binde Parameter MIT expliziten Typen (kritisch für Boolean!)
        foreach ($params as $param => $value) {
            if ($value === null) {
                $stmt->bindValue($param, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_BOOL);
            } elseif (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * Fügt einen Datensatz ein oder aktualisiert ihn bei Konflikt
     *
     * WICHTIG: Diese Methode bereinigt automatisch alle Werte:
     * - Leere Strings werden zu NULL
     * - String-Booleans ("true"/"false") werden zu echten Booleans
     * - Arrays werden zu PostgreSQL-Arrays oder JSON konvertiert
     *
     * @param string $table Tabellenname
     * @param array $data Key-value Array der Feldnamen und Werte
     * @param array $conflict_columns Array der Spalten, die einen Konflikt verursachen (z.B. Primärschlüssel)
     * @return PDOStatement
     */
    public function upsert($table, $data, $conflict_columns = []) {
        // Bereinige ALLE Werte vor der Verarbeitung
        $cleanedData = [];
        foreach ($data as $key => $value) {
            $cleanedData[$key] = $this->cleanValue($value);
        }

        $fields = array_keys($cleanedData);
        $values = array_values($cleanedData);
        $field_list = implode(',', $fields);
        $placeholder_list = implode(',', array_map(function ($f) {
            return ':' . $f;
        }, $fields));
        $update_list = implode(',', array_map(function ($f) {
            return $f . ' = EXCLUDED.' . $f;
        }, $fields));

        if (empty($conflict_columns)) {
            $conflict_columns = ['id'];
        }

        $conflict_clause = 'ON CONFLICT (' . implode(',', $conflict_columns) . ') ';

        $sql = "INSERT INTO $table ( $field_list ) VALUES ( $placeholder_list ) $conflict_clause DO UPDATE SET $update_list";

        $stmt = $this->pdo->prepare($sql);

        // Binde Parameter MIT expliziten Typen (kritisch für Boolean!)
        $params = array_combine(
            array_map(function ($f) {
                return ':' . $f;
            }, $fields),
            $values
        );

        foreach ($params as $param => $value) {
            if ($value === null) {
                $stmt->bindValue($param, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_BOOL);
            } elseif (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        return $stmt;
    }

    /**
     * Aktualisiert mehrere Datensätze in einem einzigen Query (Bulk-Update)
     *
     * Verwendet PostgreSQL CTE (WITH ... AS VALUES) für maximale Performance.
     * Statt N einzelner UPDATE-Statements wird nur 1 Query ausgeführt.
     *
     * @param string $table Tabellenname
     * @param array $columns Spalten mit Typen ['id' => 'integer', 'name' => 'text', 'price' => 'numeric']
     * @param array $data Multi-dimensionales Array mit Werten [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']]
     * @param string $where Spalte für WHERE-Bedingung (Standard: 'id')
     * @return bool
     *
     * @example
     * $db->updateAll('invoice', [
     *     'id' => 'integer',
     *     'position' => 'integer',
     *     'qty' => 'numeric',
     *     'sellprice' => 'numeric'
     * ], [
     *     ['id' => 1, 'position' => 1, 'qty' => 5, 'sellprice' => 100.00],
     *     ['id' => 2, 'position' => 2, 'qty' => 3, 'sellprice' => 50.00]
     * ]);
     */
    public function updateAll($table, $columns, $data, $where = 'id') {
        if (empty($data)) {
            return true;
        }

        $columnNames = array_keys($columns);
        $columnTypes = array_values($columns);
        $textTypes = ['text', 'varchar', 'char', 'character'];

        // VALUES-Teil aufbauen
        $valueRows = [];
        $isFirstRow = true;

        foreach ($data as $entry) {
            $values = [];
            foreach ($columnNames as $idx => $col) {
                $value = $entry[$col] ?? null;
                $type = $columnTypes[$idx];

                // Wert formatieren
                if ($value === null) {
                    $formattedValue = 'NULL';
                } elseif (in_array($type, $textTypes)) {
                    // Text escapen (einfache Anführungszeichen verdoppeln)
                    $escaped = str_replace("'", "''", $value);
                    $formattedValue = "'" . $escaped . "'";
                } else {
                    // Numerisch/Integer
                    $formattedValue = is_numeric($value) ? $value : 'NULL';
                }

                // Casting nur beim ersten Row (PostgreSQL leitet Typen davon ab)
                if ($isFirstRow) {
                    $values[] = $formattedValue . '::' . $type;
                } else {
                    $values[] = $formattedValue;
                }
            }
            $valueRows[] = '(' . implode(', ', $values) . ')';
            $isFirstRow = false;
        }

        // SET-Teil aufbauen (ohne WHERE-Spalte)
        $setClause = [];
        foreach ($columnNames as $col) {
            if ($col !== $where) {
                $setClause[] = $col . ' = d.' . $col;
            }
        }

        $sql = "
            WITH new_data(" . implode(', ', $columnNames) . ") AS (
                VALUES " . implode(', ', $valueRows) . "
            )
            UPDATE {$table}
            SET " . implode(', ', $setClause) . "
            FROM new_data d
            WHERE d.{$where} = {$table}.{$where}
        ";

        //debugQuery($sql, [], 'Bulk Update ' . $table);

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute();
        } catch (PDOException $e) {
            throw new ApiError("API_DATABASE_ERROR", $e->getMessage());
        }
    }
}

/**
 * Singleton für die Firmen-Datenbankverbindung
 */
class DbhCompany {
    protected static $instance = null;

    /**
     * Gibt die Singleton-Instanz zurück
     *
     * @param PDO|null $pdo Optionales PDO-Objekt für die Verbindung
     * @return ApiDatabase
     */
    public static function begin($pdo = null) {
        if (null === self::$instance) {
            $session = DbhAuth::begin();
            self::$instance = new ApiDatabase($session->connectPDO());
        }
        return self::$instance;
    }
}
/**
 * Ermittelt die nächste freie Nummer aus dem Nummernkreis.
 *
 * Zählt die defaults-Spalte hoch und prüft ob die Nummer in der Zieltabelle
 * bereits vergeben ist. Falls ja, wird solange hochgezählt bis eine freie
 * Nummer gefunden wird. Aktualisiert defaults auf die tatsächlich verwendete Nummer.
 *
 * NICHT für Rechnungsnummern (invnumber) verwenden — die bleiben unangetastet.
 *
 * @param ApiDatabase $db    DbhCompany-Instanz
 * @param string $defaultsCol  Spaltenname in defaults (z.B. 'sonumber')
 * @param string $targetTable  Zieltabelle (z.B. 'oe')
 * @param string $targetCol    Spalte in der Zieltabelle (z.B. 'ordnumber')
 * @return string Die nächste freie Nummer
 */
function nextFreeNumber($db, $defaultsCol, $targetTable, $targetCol) {
    // Atomisch hochzählen — sperrt gleichzeitig die defaults-Zeile gegen parallele Zugriffe.
    // BIGINT statt INT: der Nummernkreis-Zähler darf gross werden, ohne zu überlaufen.
    $row = $db->getOne(
        "UPDATE defaults SET $defaultsCol = COALESCE({$defaultsCol}::BIGINT, 0) + 1 RETURNING {$defaultsCol}"
    );
    $candidate = (int)$row[$defaultsCol];

    // Solange die Nummer vergeben ist, weiterzählen
    while ($db->getOne(
        "SELECT 1 FROM $targetTable WHERE $targetCol = :num",
        [':num' => (string)$candidate]
    )) {
        $candidate++;
    }

    // defaults auf die tatsächlich verwendete Nummer aktualisieren (falls übersprungen)
    if ($candidate !== (int)$row[$defaultsCol]) {
        $db->execute(
            "UPDATE defaults SET $defaultsCol = :num",
            [':num' => (string)$candidate]
        );
    }

    return (string)$candidate;
}

/**************************************************************************************
 * Diese verwendung ist PFLICHT
Datenbankhandler initialisieren mit:
$company = DbhCompany::begin(); bzw. $auth = DbhAuth::begin();
Beispielaufrufe:
$company->getAll( 'SELECT * FROM table_name WHERE id = :id', [ ':id' => 123 ] );
$company->insert( 'table_name', [ 'col1', 'col2' ], [ val1, val2 ], true, 'table_name_id_seq' );
$company->update( 'table_name', [ 'col1', 'col2' ], [ val1, val2 ], 'id = 123' );
$company->upsert( 'table_name', [ 'col1' => val1, 'col2' => val2 ], [ 'id' ] );
$company->getOne( 'SELECT * FROM table_name WHERE id = :id', [ ':id' => 123 ] );
*/