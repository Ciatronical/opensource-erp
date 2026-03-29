<?php
// api/logging.php

/**
 * Flag für den Debug-Modus
 */
if (!isset($DEBUG)) $DEBUG = false;

/**
 * Logging Levels
 * DLOG_ERR: Nur Fehler
 * DLOG_WRN: Warnungen und Fehler
 * DLOG_INF: Informationsmeldungen, Warnungen und Fehler
 * DLOG_DBG: Alle Debug-Meldungen, Informationsmeldungen, Warnungen und Fehler
 */
define('DLOG_ERR', 0);
define('DLOG_WRN', 1);
define('DLOG_INF', 2);
define('DLOG_DBG', 3);

if ($DEBUG) {
    $DLOG_LEVEL = DLOG_DBG;
} else {
    $DLOG_LEVEL = DLOG_INF;
}

// Standard-Zeitzone setzen
date_default_timezone_set(OSERP_DEFAULT_TIMEZONE);

/**
 * Schreibt einen String mit Datum und Uhrzeit in die Logdatei
 * Benennt die Logdatei um, wenn sie größer als OSERP_DEBUG_LOG_MAX_SIZE ist
 *
 * @param string $log Nachricht die geloggt werden soll
 * @param bool $append true => anhängen, false => überschreiben
 * @param int $level Log-Level (DLOG_ERR, DLOG_WRN, DLOG_INF, DLOG_DBG)
 */
function writeLog($log, $append = true, $level = DLOG_DBG) {
    if(!defined('OSERP_DEBUG_LOG_FILE')) {
        return;
    }
    $file = OSERP_DEBUG_LOG_FILE;
    if (defined('OSERP_DEBUG_LOG_MAX_SIZE')) {
        if (filesize($file) > OSERP_DEBUG_LOG_MAX_SIZE) {
            $newFile = $file . '.' . date("Ymd_His") . '.log';
            while (file_exists($newFile)) {
                $newFile = $file . '.' . date("Ymd_His") . '_' . bin2hex(random_bytes(4)) . '.log';
            }
            rename($file, $newFile);
        }
    }

    $levelStr = '';
    switch ($level) {
        case DLOG_ERR:
            $levelStr = 'ERROR';
            break;
        case DLOG_WRN:
            $levelStr = 'WARNING';
            break;
        case DLOG_INF:
            $levelStr = 'INFO';
            break;
        case DLOG_DBG:
            $levelStr = 'DEBUG';
            break;
    }
    $str = '[' . date("Y-m-d H:i:s") . '] [pid ' . getmypid() . '] [' . $levelStr . '] -> ' . print_r($log, TRUE) . "\n";
    $append ? file_put_contents($file, $str, FILE_APPEND) : file_put_contents($file, $str);
}

/**
 * Schreibt eine Debug-Nachricht in die Logdatei, abhängig vom gesetzten DLOG_LEVEL
 *
 * @param string $log Nachricht
 * @param int $level Log-Level
 * @param bool $append true => anhängen, false => überschreiben
 */
function debugLog($log, $level = DLOG_DBG, $append = true) {
    global $DLOG_LEVEL;

    if ($level <= $DLOG_LEVEL) {
        writeLog($log, $append);
    }
}

/**
 * Schreibt die Parameterliste eines PDOStatement in die Logdatei
 *
 * @param PDOStatement $stmt PDO Statement
 * @param bool $append true => anhängen, false => überschreiben
 */
function debugPdoParams($stmt, $append = true) {
    ob_start();
    $stmt->debugDumpParams();
    $out = ob_get_clean();
    writeLog($out, $append);
}

/**
 * Schreibt ein var_dump in die Logdatei
 *
 * @param mixed $var Variable die ausgegeben werden soll
 * @param string|null $info Zusätzliche Information (optional)
 * @param bool $append true => anhängen, false => überschreiben
 */
function debugVar($var, $info = null, $append = true) {
    ob_start();
    var_dump($var);
    $out = ob_get_clean();
    if (null != $info) {
        $out = $info . ': ' . $out;
    }
    writeLog($out, $append);
}

/**
* Beispiel 1: Mit named parameters
* $sql = "SELECT * FROM customer WHERE id = :id AND name = :name";
* $params = [':id' => 123, ':name' => 'Test GmbH'];
*
* debugQuery($sql, $params, 'Kunden-Suche');
* //Ausgabe: SELECT * FROM customer WHERE id = 123 AND name = 'Test GmbH'
*
* // Beispiel 2: Mit positional parameters ($1, $2)
* $sql = "UPDATE customer SET name = $1, city = $2 WHERE id = $3";
* $params = ['Neue Firma', 'Berlin', 1121];
*
* debugQuery($sql, $params, 'Kunden-Update');
* // Ausgabe: UPDATE customer SET name = 'Neue Firma', city = 'Berlin' WHERE id = 1121
*
* // Beispiel 3: Mit verschiedenen Datentypen
* $sql = "INSERT INTO parts (partnumber, description, sellprice, obsolete)
*         VALUES (:partnumber, :description, :sellprice, :obsolete)";
* $params = [
*     ':partnumber' => 'ART-001',
*     ':description' => 'Test Artikel',
*     ':sellprice' => 99.50,
*     ':obsolete' => false
* ];
*
* debugQuery($sql, $params, 'Artikel einfügen');
* // Ausgabe: INSERT INTO parts (partnumber, description, sellprice, obsolete)
* //          VALUES ('ART-001', 'Test Artikel', 99.5, FALSE)
* Interpoliert Parameter in eine SQL-Query für Debugging-Zwecke
* ACHTUNG: Nur für Debugging verwenden, NICHT für echte Queries!
*
* @param string $query SQL-Query mit Platzhaltern
* @param array $params Array mit gebundenen Parametern
* @return string SQL-Query mit interpolierten Werten
*/
function interpolateQuery($query, $params) {
    $keys = array();
    $values = array();

    // Sortiere Parameter nach Länge (längste zuerst)
    // um Teilstring-Ersetzungen zu vermeiden
    uksort($params, function($a, $b) {
        return strlen($b) - strlen($a);
    });

    foreach ($params as $key => $value) {
        // Stelle sicher, dass Key mit : beginnt
        if (is_string($key) && substr($key, 0, 1) !== ':') {
            $key = ':' . $key;
        }

        $keys[] = '/' . preg_quote($key, '/') . '(?![a-zA-Z0-9_])/';

        // Konvertiere Wert in SQL-Format
        if (is_null($value)) {
            $values[] = 'NULL';
        } elseif (is_bool($value)) {
            $values[] = $value ? 'TRUE' : 'FALSE';
        } elseif (is_int($value) || is_float($value)) {
            $values[] = $value;
        } elseif (is_array($value)) {
            $values[] = "'" . json_encode($value) . "'";
        } else {
            // String: Escapen und in Anführungszeichen
            $values[] = "'" . str_replace("'", "''", $value) . "'";
        }
    }

    return preg_replace($keys, $values, $query);
}

/**
 * Gibt eine Debug-Query in die Log-Datei aus
 *
 * @param string $query SQL-Query mit Platzhaltern
 * @param array $params Parameter-Array
 * @param string $label Optionale Beschreibung
 */
function debugQuery($query, $params = [], $label = 'DEBUG QUERY') {
    $interpolated = interpolateQuery($query, $params);
    $output = "Parameters:\n";
    ob_start();
    var_dump($params);
    $output .= ob_get_clean();
    $output .= "\n\n=== $label ===\n\n";
    $output .= $interpolated . "\n\n";
    $output .= "==================\n\n";
    writeLog($output);
}
