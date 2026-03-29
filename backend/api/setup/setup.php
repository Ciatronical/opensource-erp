<?php
// api/setup/setup.php


/*
* Erzeugt eine settings.ini Datei basierend auf den Eingaben im Setup
* Versucht Defaults aus einer vorhandenen kivitendo.conf zu lesen
* settings.ini wird im Verzeichnis SETUP_SETTINGS_DIR erstellt
* mit dem Namen SETUP_SETTINGS_INI_FILE
* ist im Stil einer INI-Datei:

[database]
host = "localhost"
port = "5432"
auth_db = "oserp_auth"
auth_user = "postgres"
auth_pass = "xxx"  ; verschlüsseltes Passwort

[session]
cookie_name = "opensource_erp"
cookie_same_site = "Strict"

[logging]
max_log_size = 10485760
debug_log_file = "/var/www/api/setup/../../log/opensource_erp.api.debug.log"

[system]
timezone = "Europe/Berlin"
debug = true
templates_dir = "templates"

*/

/**
 * Setup-Funktionen für OpensourceERP
 *
 * Stellt die Actions für das Setup-System bereit
 */

// ============================================================================
// CONFIG READER - Holt Defaults aus kivitendo.conf
// ============================================================================

/**
 * Findet die kivitendo.conf Datei im /var/www/ Verzeichnis
 * Sucht zuerst in /var/www/kivitendo-erp/config/, dann in allen anderen
 *
 * @return string|null Pfad zur kivitendo.conf oder null wenn nicht gefunden
 */
function findK7oConf() {
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
 * Parst die kivitendo.conf und extrahiert relevante Sektionen
 *
 * Liest [authentication/database] und [paths] Sektion.
 * Keys aus [paths] werden mit 'paths_' prefixed um Konflikte zu vermeiden.
 *
 * @param string $confFile Pfad zur kivitendo.conf Datei
 * @return array Assoziatives Array mit den Konfigurationswerten
 */
function parseK7oConf($confFile) {
    $content = file_get_contents($confFile);
    $lines = explode("\n", $content);

    $currentSection = '';
    $config = [];
    $targetSections = ['authentication/database', 'paths'];

    foreach ($lines as $line) {
        $line = trim($line);

        if (empty($line) || $line[0] === '#') {
            continue;
        }

        if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
            $currentSection = $matches[1];
            continue;
        }

        if (in_array($currentSection, $targetSections) && strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($currentSection === 'paths') {
                $config['paths_' . $key] = $value;
            } else {
                $config[$key] = $value;
            }
        }
    }

    return $config;
}

/**
 * Holt die Config aus kivitendo.conf [authentication/database] Sektion
 * Falls nicht gefunden, wird ein leeres Array zurückgegeben
 *
 * @return array Assoziatives Array mit den Konfigurationswerten
 */
function getK7oConfig() {
    $confFile = findK7oConf();

    if ($confFile === null) {
        return [];
    }

    $config = parseK7oConf($confFile);

    if (empty($config)) {
        return [];
    }

    return $config;
}

/**
 * Mappt kivitendo.conf Felder zu Setup-Feldnamen
 *
 * kivitendo.conf hat: host, port, db, user, password (aus [authentication/database])
 *                     templates (aus [paths])
 * Setup erwartet: host, port, auth_db, auth_user, auth_pass, templates_dir
 *
 * @param array $config Config aus kivitendo.conf
 * @return array Gemappte Config für Setup
 */
function mapK7oConfigToSetup($config) {
    if (empty($config)) {
        return [];
    }

    return [
        'host' => $config['host'] ?? '',
        'port' => $config['port'] ?? '',
        'auth_db' => $config['db'] ?? '',
        'auth_user' => $config['user'] ?? '',
        'auth_pass' => $config['password'] ?? '',
        'templates_dir' => $config['paths_templates'] ?? 'templates'
    ];
}

/**
 * Holt Setup-Defaults aus kivitendo.conf mit Fallback zu App-Defaults
 *
 * @return array Setup-Defaults
 */
function getSetupDefaults() {
    // App-Defaults
    $appDefaults = [
        'host' => 'localhost',
        'port' => '5432',
        'auth_db' => 'oserp_auth',
        'auth_user' => 'postgres',
        'auth_pass' => '',
        'templates_dir' => 'templates'
    ];

    // Hole Config aus kivitendo.conf
    $k7oConfig = getK7oConfig();

    // Mappe kivitendo.conf Felder zu Setup-Feldern
    $mappedConfig = mapK7oConfigToSetup($k7oConfig);

    // Merge: kivitendo.conf Config überschreibt App-Defaults
    return array_merge($appDefaults, $mappedConfig);
}

// ============================================================================
// SETUP HELPER FUNCTIONS
// ============================================================================

/**
 * Verschlüsselt ein Passwort
 *
 * @param string $s String zum Verschlüsseln
 * @return string Verschlüsselter String (Base64)
 */
function setupEnc(string $s): string {
    $key = 'k';
    return base64_encode($s ^ str_repeat($key, strlen($s)));
}

/**
 * Validiert die Setup-Daten
 *
 * @param array $data Setup-Daten
 * @return array Array mit Fehlermeldungen (leer wenn valide)
 */
function validateSetupData($data): array {
    $errors = [];

    // Host
    if (empty($data['host'])) {
        $errors[] = 'Host ist erforderlich';
    }

    // Port
    if (empty($data['port'])) {
        $errors[] = 'Port ist erforderlich';
    } elseif (!is_numeric($data['port']) || $data['port'] < 1 || $data['port'] > 65535) {
        $errors[] = 'Port muss eine Zahl zwischen 1 und 65535 sein';
    }

    // Datenbankname
    if (empty($data['auth_db'])) {
        $errors[] = 'Datenbankname ist erforderlich';
    }

    // Login
    if (empty($data['auth_user'])) {
        $errors[] = 'Login ist erforderlich';
    }

    // Passwort
    if (empty($data['auth_pass'])) {
        $errors[] = 'Passwort ist erforderlich';
    }

    return $errors;
}

/**
 * Testet die Datenbankverbindung
 *
 * @param string $host Host
 * @param string $port Port
 * @param string $dbname Datenbankname
 * @param string $user Benutzername
 * @param string $pass Passwort
 * @return array Ergebnis mit success, message und optional version
 */
function testDatabaseConnection($host, $port, $dbname, $user, $pass): array {
    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);

        // Einfache Testabfrage
        $stmt = $pdo->query('SELECT version()');
        $version = $stmt->fetchColumn();

        return [
            'success' => true,
            'message' => 'Verbindung erfolgreich',
            'version' => $version
        ];

    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Verbindung fehlgeschlagen: '.$e->getMessage()
        ];
    }
}

/**
 * Erstellt die settings.ini
 *
 * @param array $data Setup-Daten
 * @return bool Erfolg
 * @throws Exception bei Schreibfehlern
 */
function createSettingsIni($data): bool {
    $settingsIniPath = SETUP_SETTINGS_DIR.SETUP_SETTINGS_INI_FILE;

    // Passwort verschlüsseln
    $encryptedPass = setupEnc($data['auth_pass']);

    // INI-Datei zusammenbauen
    $content = "; config/".SETUP_SETTINGS_INI_FILE."\n";
    $content .= "; OpensourceERP Konfigurationsdatei\n";
    $content .= ";\n";
    $content .= "; WICHTIG: Diese Datei enthält sensible Zugangsdaten!\n";
    $content .= "; Niemals in Git einchecken oder öffentlich zugänglich machen.\n";
    $content .= ";\n";
    $content .= "; Erstellt durch Setup: ".date('Y-m-d H:i:s')."\n\n";

    // Datenbank-Sektion
    $content .= "[database]\n";
    $content .= 'host = "'.$data['host'].'"'."\n";
    $content .= 'port = "'.$data['port'].'"'."\n";
    $content .= 'auth_db = "'.$data['auth_db'].'"'."\n";
    $content .= 'auth_user = "'.$data['auth_user'].'"'."\n";
    $content .= 'auth_pass = "'.$encryptedPass.'"'."\n";
    $content .= "\n";

    // Session-Sektion
    $content .= "[session]\n";
    $content .= 'cookie_name = "opensource_erp"'."\n";
    $content .= 'cookie_same_site = "Strict"'."\n";
    $content .= "\n";

    // Logging-Sektion
    $content .= "[logging]\n";
    $content .= 'max_log_size = 10485760'."\n";
    $content .= 'debug_log_file = "'.__DIR__.'/../../log/opensource_erp.api.debug.log"'."\n";
    $content .= "\n";

    // System-Sektion
    $content .= "[system]\n";
    $content .= 'timezone = "Europe/Berlin"'."\n";
    $content .= 'debug = true'."\n";
    $content .= 'templates_dir = "'.($data['templates_dir'] ?? 'templates').'"'."\n";
    $content .= "\n";

    // Datei schreiben
    return file_put_contents($settingsIniPath, $content, LOCK_EX) !== false;
}

// ============================================================================
// API ACTIONS
// ============================================================================

/**
 * Action: getDefaults
 *
 * Holt Setup-Defaults aus kivitendo.conf (falls vorhanden) mit Fallback zu App-Defaults
 *
 * @param array $data Request-Daten (nicht verwendet)
 */
function getDefaults($data) {
    $defaults = getSetupDefaults();

    // Prüfen ob kivitendo.conf gefunden wurde
    $k7oConfig = getK7oConfig();
    $configFound = !empty($k7oConfig);

    resultInfo(true, '', [
        'defaults' => $defaults,
        'config_found' => $configFound,
        'config_source' => $configFound ? findK7oConf() : null
    ]);
}

/**
 * Action: test
 *
 * Testet die Datenbankverbindung
 *
 * @param array $data Request-Daten mit 'data' Array
 */
function test($data) {
    if (!isset($data['data'])) {
        throw new ApiError('INVALID_REQUEST', 'Keine Daten übergeben');
    }

    $setupData = $data['data'];

    // Validieren
    $errors = validateSetupData($setupData);
    if (!empty($errors)) {
        resultInfo(false, 'VALIDATION_ERROR', [
            'errors' => $errors
        ]);
        return;
    }

    // Verbindung testen
    $result = testDatabaseConnection(
        $setupData['host'],
        $setupData['port'],
        $setupData['auth_db'],
        $setupData['auth_user'],
        $setupData['auth_pass']
    );

    resultInfo($result['success'], '', $result);
}

/**
 * Action: save
 *
 * Speichert die Konfiguration und erstellt settings.ini
 *
 * @param array $data Request-Daten mit 'data' Array
 */
function save($data) {
    // Prüfen ob Setup bereits durchgeführt wurde
    if (setupExists()) {
        throw new ApiError('SETUP_ALREADY_DONE', 'Setup wurde bereits durchgeführt');
    }

    if (!isset($data['data'])) {
        throw new ApiError('INVALID_REQUEST', 'Keine Daten übergeben');
    }

    $setupData = $data['data'];

    // Validieren
    $errors = validateSetupData($setupData);
    if (!empty($errors)) {
        resultInfo(false, 'VALIDATION_ERROR', [
            'errors' => $errors
        ]);
        return;
    }

    // Verbindung testen
    $testResult = testDatabaseConnection(
        $setupData['host'],
        $setupData['port'],
        $setupData['auth_db'],
        $setupData['auth_user'],
        $setupData['auth_pass']
    );

    if (!$testResult['success']) {
        resultInfo(false, 'DATABASE_CONNECTION_FAILED', $testResult);
        return;
    }

    // settings.ini erstellen
    if (!createSettingsIni($setupData)) {
        throw new ApiError('FILE_WRITE_ERROR', 'Konnte settings.ini nicht erstellen');
    }

    resultInfo(true, 'SETUP_COMPLETE', [
        'message' => 'Setup erfolgreich abgeschlossen',
        'database_version' => $testResult['version']
    ]);
}