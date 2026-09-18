<?php
// backend/api/developer-tools/log-viewer.php

/**
 * Maximale Anzahl Bytes, die beim Rückwärtslesen einer Logdatei
 * durchsucht werden. Logdateien werden erst bei
 * OSERP_DEBUG_LOG_MAX_SIZE rotiert (Vorgabe 10 MB) — ohne Riegel
 * würde ein Filter über eine volle Datei den Speicher aufbrauchen.
 */
if (!defined('LOG_VIEWER_MAX_SCAN')) define('LOG_VIEWER_MAX_SCAN', 12 * 1024 * 1024);

/** Blockgröße beim Rückwärtslesen */
if (!defined('LOG_VIEWER_CHUNK')) define('LOG_VIEWER_CHUNK', 256 * 1024);

/** Obergrenze für die gelieferte Seitengröße */
if (!defined('LOG_VIEWER_MAX_LIMIT')) define('LOG_VIEWER_MAX_LIMIT', 500);

/**
 * Sammelt alle lesbaren Logdateien mit ihrem vollen Pfad
 *
 * Der Client kennt nur den Dateinamen, nie den Pfad. Damit lässt
 * sich über den Parameter keine beliebige Datei des Servers öffnen:
 * was hier nicht im Array steht, ist nicht lesbar.
 *
 * @return array [dateiname => vollstaendiger pfad]
 */
function collectLogFiles() {
    $dirs = [];

    if (defined('OSERP_DEBUG_LOG_DIR')) $dirs[] = OSERP_DEBUG_LOG_DIR;
    if (defined('OSERP_API_LOG_DIR')) $dirs[] = OSERP_API_LOG_DIR;
    if (defined('OSERP_DEBUG_LOG_FILE')) $dirs[] = dirname(OSERP_DEBUG_LOG_FILE).'/';
    if (defined('OSERP_API_LOG_FILE')) $dirs[] = dirname(OSERP_API_LOG_FILE).'/';

    // Doppelte Verzeichnisse entfernen (auth_log und debug_log liegen
    // in der Vorgabe im selben Verzeichnis)
    $seenDirs = [];
    $files = [];

    foreach ($dirs as $dir) {
        $real = realpath($dir);
        if (!$real || isset($seenDirs[$real])) {
            continue;
        }
        $seenDirs[$real] = true;

        foreach (glob($real.'/*.log*') as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $name = basename($path);
            // Erster Treffer gewinnt — Namenskollisionen über mehrere
            // Log-Verzeichnisse hinweg sind bei der Vorgabe nicht möglich.
            if (!isset($files[$name])) {
                $files[$name] = $path;
            }
        }
    }

    return $files;
}

/**
 * Löst einen vom Client gelieferten Dateinamen in einen Pfad auf
 *
 * @param string|null $name Dateiname aus dem Request
 * @return string|null Vollständiger Pfad oder null
 */
function resolveLogFile($name) {
    $files = collectLogFiles();

    if (empty($files)) {
        return null;
    }

    if (empty($name)) {
        // Ohne Angabe die konfigurierte Debug-Logdatei, sonst die erste
        if (defined('OSERP_DEBUG_LOG_FILE')) {
            $default = basename(OSERP_DEBUG_LOG_FILE);
            if (isset($files[$default])) {
                return $files[$default];
            }
        }
        return reset($files);
    }

    $name = basename($name);
    return isset($files[$name]) ? $files[$name] : null;
}

/**
 * Zerlegt eine Kopfzeile eines Logeintrags
 *
 * Erkannt werden zwei Formate:
 *   1. writeLog():  [2026-09-18 07:59:49] [pid 5544] [INFO] -> Nachricht
 *   2. PHP-Fehlerlog: [18-Sep-2026 10:44:00 UTC] PHP Warning: ...
 *
 * Zeilen, die zu keinem Muster passen, gehören zum vorherigen Eintrag
 * (mehrzeilige print_r- oder var_dump-Ausgaben).
 *
 * @param string $line Zeile aus der Logdatei
 * @return array|null ['timestamp', 'pid', 'level', 'message'] oder null
 */
function parseLogHeader($line) {
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[pid (\d+)\] \[([A-Z]+)\] -> ?(.*)$/', $line, $m)) {
        return [
            'timestamp' => $m[1],
            'pid'       => (int)$m[2],
            'level'     => $m[3],
            'message'   => $m[4],
        ];
    }

    // PHP-Fehlerlog: die Klammer muss mit einer Ziffer beginnen und eine
    // plausible Datumslänge haben, damit Nachrichtenzeilen wie
    // "[SYSTEM] ..." nicht als neuer Eintrag gelesen werden.
    if (preg_match('/^\[(\d[^\]]{6,30})\]\s+(.*)$/', $line, $m)) {
        $message = $m[2];
        $level = 'INFO';
        if (preg_match('/\b(fatal|error|exception)\b/i', $message)) {
            $level = 'ERROR';
        } elseif (preg_match('/\bwarning\b/i', $message)) {
            $level = 'WARNING';
        } elseif (preg_match('/\b(notice|deprecated)\b/i', $message)) {
            $level = 'DEBUG';
        }
        return [
            'timestamp' => $m[1],
            'pid'       => null,
            'level'     => $level,
            'message'   => $message,
        ];
    }

    return null;
}

/**
 * Prüft, ob ein Eintrag den gesetzten Filtern entspricht
 *
 * @param array $entry Logeintrag
 * @param string $level Gesuchter Log-Level ('' = alle)
 * @param string $search Suchbegriff ('' = alle)
 * @return bool
 */
function logEntryMatches($entry, $level, $search) {
    if ($level !== '' && $entry['level'] !== $level) {
        return false;
    }
    if ($search !== '' && stripos($entry['message'], $search) === false
        && stripos($entry['timestamp'], $search) === false) {
        return false;
    }
    return true;
}

/**
 * Liest die letzten Einträge einer Logdatei, neueste zuerst
 *
 * Die Datei wird blockweise von hinten gelesen, damit auch bei einer
 * 10-MB-Datei nur die tatsächlich benötigten Einträge im Speicher landen.
 *
 * @param string $path Vollständiger Pfad zur Logdatei
 * @param int $limit Anzahl Einträge
 * @param int $offset Wie viele passende Einträge übersprungen werden
 * @param string $level Filter auf Log-Level ('' = alle)
 * @param string $search Filter auf Text ('' = alle)
 * @return array ['entries', 'has_more', 'truncated']
 */
function readLogEntriesReverse($path, $limit, $offset, $level, $search) {
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return ['entries' => [], 'has_more' => false, 'truncated' => false];
    }

    $size = filesize($path);
    $position = $size;
    $rest = '';            // angelesene, noch unvollständige erste Zeile des Blocks
    $tail = [];            // Folgezeilen unterhalb der noch gesuchten Kopfzeile
    $matched = 0;          // passende Einträge insgesamt (inkl. übersprungener)
    $entries = [];
    $hasMore = false;
    $truncated = false;
    $scanned = 0;

    // Sammelt einen fertigen Eintrag ein; liefert false, wenn genug da ist
    $collect = function($header, $lines) use (&$entries, &$matched, &$hasMore, $offset, $limit, $level, $search) {
        $entry = $header;
        if (!empty($lines)) {
            $entry['message'] = rtrim($entry['message']."\n".implode("\n", $lines));
        }
        if (!logEntryMatches($entry, $level, $search)) {
            return true;
        }
        $matched++;
        if ($matched <= $offset) {
            return true;
        }
        if (count($entries) < $limit) {
            $entries[] = $entry;
            return true;
        }
        // Ein Eintrag über die Seite hinaus: es gibt eine nächste Seite
        $hasMore = true;
        return false;
    };

    $done = false;

    while ($position > 0 && !$done) {
        $readSize = min(LOG_VIEWER_CHUNK, $position);
        $position -= $readSize;
        fseek($handle, $position);
        $buffer = fread($handle, $readSize);
        $scanned += $readSize;

        $buffer .= $rest;
        $lines = explode("\n", $buffer);

        // Die erste Zeile kann mitten im Block abgeschnitten sein und wird
        // erst mit dem nächsten (weiter vorn liegenden) Block vollständig.
        $rest = $position > 0 ? array_shift($lines) : '';

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = rtrim($lines[$i], "\r");
            $header = parseLogHeader($line);

            if ($header === null) {
                // Fortsetzungszeile — gehört zum Eintrag darüber
                if ($line !== '' || !empty($tail)) {
                    array_unshift($tail, $line);
                }
                continue;
            }

            if (!$collect($header, $tail)) {
                $done = true;
                break;
            }
            $tail = [];
        }

        if (!$done && $scanned >= LOG_VIEWER_MAX_SCAN && $position > 0) {
            $truncated = true;
            break;
        }
    }

    fclose($handle);

    return [
        'entries'   => $entries,
        'has_more'  => $hasMore,
        'truncated' => $truncated,
    ];
}

/**
 * Liefert die Liste der verfügbaren Logdateien
 *
 * @testdata {}
 */
function getLogFiles($data) {
    $files = collectLogFiles();
    $current = defined('OSERP_DEBUG_LOG_FILE') ? basename(OSERP_DEBUG_LOG_FILE) : '';

    $result = [];
    foreach ($files as $name => $path) {
        $result[] = [
            'name'       => $name,
            'size'       => filesize($path),
            'modified'   => date('Y-m-d H:i:s', filemtime($path)),
            'is_current' => $name === $current,
        ];
    }

    // Neueste Datei zuerst
    usort($result, function($a, $b) {
        return strcmp($b['modified'], $a['modified']);
    });

    resultInfo(true, '', ['files' => $result]);
}

/**
 * Liest Einträge einer Logdatei, neueste zuerst
 *
 * @param string $data['file'] Dateiname der Logdatei (ohne Pfad, optional)
 * @param int $data['limit'] Anzahl Einträge je Seite (Vorgabe 20)
 * @param int $data['offset'] Anzahl zu überspringender Einträge (Vorgabe 0)
 * @param string $data['level'] Filter auf Log-Level: ERROR, WARNING, INFO, DEBUG (optional)
 * @param string $data['search'] Filter auf Text im Eintrag (optional)
 * @testdata {"file": "opensource_erp.api.debug.log", "limit": 20, "offset": 0, "level": "", "search": ""}
 */
function getLogEntries($data) {
    $path = resolveLogFile($data['file'] ?? null);

    if ($path === null) {
        resultInfo(false, 'LOG_FILE_NOT_FOUND', 'Logdatei nicht gefunden');
        return;
    }

    $limit = isset($data['limit']) ? (int)$data['limit'] : 20;
    if ($limit < 1) $limit = 20;
    if ($limit > LOG_VIEWER_MAX_LIMIT) $limit = LOG_VIEWER_MAX_LIMIT;

    $offset = isset($data['offset']) ? (int)$data['offset'] : 0;
    if ($offset < 0) $offset = 0;

    $level = isset($data['level']) ? strtoupper(trim($data['level'])) : '';
    if (!in_array($level, ['ERROR', 'WARNING', 'INFO', 'DEBUG'], true)) {
        $level = '';
    }

    $search = isset($data['search']) ? trim($data['search']) : '';

    $result = readLogEntriesReverse($path, $limit, $offset, $level, $search);

    resultInfo(true, '', [
        'file'      => basename($path),
        'size'      => filesize($path),
        'modified'  => date('Y-m-d H:i:s', filemtime($path)),
        'limit'     => $limit,
        'offset'    => $offset,
        'entries'   => $result['entries'],
        'has_more'  => $result['has_more'],
        'truncated' => $result['truncated'],
    ]);
}
