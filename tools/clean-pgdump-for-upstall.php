#!/usr/bin/env php
<?php
/**
 * Bereinigt eine pg_dump-Datei für die Verwendung als Upstall-Schema.
 *
 * Entfernt: \restrict/\unrestrict, SET-Statements, ALTER OWNER,
 * pg_catalog.set_config, COMMENT ON EXTENSION, Dump-Header/Footer
 *
 * Konvertiert: COPY ... FROM stdin → INSERT INTO Statements
 * (COPY/stdin ist psql-spezifisch und funktioniert nicht mit PDO)
 *
 * Usage: php clean-pgdump-for-upstall.php <input.sql> <output.sql>
 */
if ($argc < 3) {
    echo "Usage: php $argv[0] <input.sql> <output.sql>\n";
    exit(1);
}

$input = file_get_contents($argv[1]);
if ($input === false) {
    echo "Fehler beim Lesen von $argv[1]\n";
    exit(1);
}

$lines = explode("\n", $input);
$output = [];
$inCopy = false;
$copyTable = '';
$copyColumns = [];
$copyRowCount = 0;

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $trimmed = trim($line);

    // ── COPY-Block verarbeiten ──
    if ($inCopy) {
        // Ende des COPY-Blocks
        if ($trimmed === '\\.') {
            $inCopy = false;
            continue;
        }

        // Datenzeile → INSERT umwandeln
        $values = explode("\t", $line);
        $sqlValues = [];
        foreach ($values as $val) {
            if ($val === '\\N') {
                $sqlValues[] = 'NULL';
            } else {
                // Einfache Anführungszeichen escapen
                $escaped = str_replace("'", "''", $val);
                // Backslash-Escapes aus COPY-Format behandeln
                $escaped = str_replace('\\\\', '\\', $escaped);
                $sqlValues[] = "'" . $escaped . "'";
            }
        }

        $colList = implode(', ', $copyColumns);
        $valList = implode(', ', $sqlValues);
        $output[] = "INSERT INTO {$copyTable} ({$colList}) VALUES ({$valList});";
        $copyRowCount++;
        continue;
    }

    // ── COPY-Statement erkennen ──
    if (preg_match('/^COPY\s+([\w."]+)\s*\((.+?)\)\s*FROM\s+stdin/i', $trimmed, $m)) {
        $copyTable = $m[1];
        $copyColumns = array_map('trim', explode(',', $m[2]));
        $inCopy = true;
        $copyRowCount = 0;
        continue;
    }

    // ── Zeilen filtern ──

    // Überspringe \restrict und \unrestrict
    if (preg_match('/^\\\\(un)?restrict\b/', $trimmed)) continue;

    // Überspringe SET-Statements
    if (preg_match('/^SET\s+(statement_timeout|lock_timeout|idle_in_transaction_session_timeout|client_encoding|standard_conforming_strings|check_function_bodies|xmloption|client_min_messages|row_security|default_tablespace|default_table_access_method)\b/i', $trimmed)) continue;

    // Überspringe SELECT pg_catalog.set_config
    if (preg_match('/^SELECT\s+pg_catalog\.set_config\b/i', $trimmed)) continue;

    // Überspringe ALTER ... OWNER TO
    if (preg_match('/^ALTER\s+\w+\s+.*\s+OWNER\s+TO\s+/i', $trimmed)) continue;

    // Überspringe COMMENT ON EXTENSION
    if (preg_match('/^COMMENT\s+ON\s+EXTENSION\b/i', $trimmed)) continue;

    // Überspringe Dump-Header/Footer-Kommentare
    if (preg_match('/^--\s*PostgreSQL database dump/i', $trimmed)) continue;
    if (preg_match('/^--\s*Dumped from database version/i', $trimmed)) continue;
    if (preg_match('/^--\s*Dumped by pg_dump version/i', $trimmed)) continue;
    if (preg_match('/^--\s*Name:\s.*;\s*Type:\s.*OWNER/i', $trimmed)) continue;

    $output[] = $line;
}

// Schreibe bereinigtes SQL
$result = implode("\n", $output);
// Entferne mehrfache Leerzeilen
$result = preg_replace('/\n{3,}/', "\n\n", $result);

file_put_contents($argv[2], $result);
echo "Bereinigt: $argv[1] -> $argv[2]\n";
echo "Zeilen vorher: " . count($lines) . ", nachher: " . count($output) . "\n";
