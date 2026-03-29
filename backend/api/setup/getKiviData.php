#!/usr/bin/env php
<?php
// read_kivitendo_conf.php

/**
 * Liest die kivitendo.conf Konfigurationsdatei und gibt die [authentication/database] Sektion aus
 *
 * Sucht in /var/www/STERN/config/kivitendo.conf nach der Konfigurationsdatei
 * und extrahiert die Datenbankverbindungsparameter aus der [authentication/database] Sektion.
 */

/**
 * Findet die kivitendo.conf Datei im /var/www/ Verzeichnis
 * Sucht zuerst in /var/www/kivitendo-erp/config/, dann in allen anderen
 *
 * @return string|null Pfad zur kivitendo.conf oder null wenn nicht gefunden
 */
function findKivitenDoConf() {
    $baseDir = '/var/www/';

    if (!is_dir($baseDir)) {
        return null;
    }

    // Priorisiere kivitendo-erp
    $preferredPath = $baseDir . 'kivitendo-erp/config/kivitendo.conf';
    if (file_exists($preferredPath) && is_readable($preferredPath)) {
        return $preferredPath;
    }

    // Wenn nicht gefunden, durchsuche alle anderen Verzeichnisse
    $dirs = glob($baseDir . '*/config', GLOB_ONLYDIR);

    foreach ($dirs as $configDir) {
        // Überspringe kivitendo-erp, da bereits geprüft
        if ($configDir === $baseDir . 'kivitendo-erp/config') {
            continue;
        }

        $confFile = $configDir . '/kivitendo.conf';
        if (file_exists($confFile) && is_readable($confFile)) {
            return $confFile;
        }
    }

    return null;
}

/**
 * Parst die kivitendo.conf und extrahiert die [authentication/database] Sektion
 *
 * @param string $confFile Pfad zur kivitendo.conf Datei
 * @return array Assoziatives Array mit den Konfigurationswerten
 */
function parseKivitenDoConf($confFile) {
    $content = file_get_contents($confFile);
    $lines = explode("\n", $content);

    $currentSection = '';
    $config = [];
    $inTargetSection = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if (empty($line) || $line[0] === '#') {
            continue;
        }

        if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
            $currentSection = $matches[1];
            $inTargetSection = ($currentSection === 'authentication/database');
            continue;
        }

        if ($inTargetSection && strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $config[$key] = $value;
        }
    }

    return $config;
}

/**
 * Hauptfunktion
 */
function main() {
    $confFile = findKivitenDoConf();

    if ($confFile === null) {
        echo "FEHLER: kivitendo.conf nicht gefunden in /var/www/*/config/\n";
        exit(1);
    }

    echo "Gefunden: $confFile\n\n";

    $config = parseKivitenDoConf($confFile);

    if (empty($config)) {
        echo "FEHLER: [authentication/database] Sektion nicht gefunden oder leer\n";
        exit(1);
    }

    echo "[authentication/database]\n";
    foreach ($config as $key => $value) {
        printf("%-12s = %s\n", $key, $value);
    }
}

main();