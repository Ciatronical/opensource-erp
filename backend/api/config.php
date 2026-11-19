<?php
// api/config.php

// Setup-Konstanten
define('SETUP_SETTINGS_DIR', __DIR__.'/../config/');
// Dateiname per Umgebungsvariable überschreibbar: eine zweite Instanz (z. B. Test
// gegen eine frische Auth-DB) läuft so mit eigener Konfiguration neben der Produktion.
// Nur der Dateiname, kein Pfad — die Datei liegt immer in backend/config/.
$settingsFileOverride = getenv('OSERP_SETTINGS_INI_FILE');
define('SETUP_SETTINGS_INI_FILE',
    ($settingsFileOverride && preg_match('/^[a-zA-Z0-9_.\-]+\.ini$/', $settingsFileOverride)) ? $settingsFileOverride : 'settings.ini');

if (!function_exists('setupExists')) {
    /**
     * Prüft ob die Konfigurationsdatei bereits existiert (Setup durchgeführt)
     *
     * Steht hier und nicht in inc.php, damit auch Einstiegspunkte, die die
     * Konfiguration früh brauchen (backend/api/setup/index.php), sie nutzen können.
     *
     * @return bool
     */
    function setupExists(): bool {
        return file_exists(SETUP_SETTINGS_DIR . SETUP_SETTINGS_INI_FILE);
    }
}

/**
 * OpensourceERP Konfiguration
 *
 * Liest die Konfigurationswerte aus settings.ini ein und
 * setzt die benötigten PHP-Konstanten.
 *
 * WICHTIG: settings.ini enthält sensible Zugangsdaten!
 * Niemals in Git einchecken oder öffentlich zugänglich machen.
 */

// Prüfen ob settings.ini existiert
$settingsIniPath = SETUP_SETTINGS_DIR.SETUP_SETTINGS_INI_FILE;
define('OSERP_SETUP_MODE', !file_exists($settingsIniPath));

// Wenn Setup-Modus, nicht weitermachen
if (OSERP_SETUP_MODE) {
    // Setup-Modus Flag ist gesetzt - inc.php wird Setup handhaben
    define('OSERP_DEFAULT_TIMEZONE', 'Europe/Berlin');
    return;
}

/**
 * Konfigurationsklasse für OpensourceERP
 *
 * Lädt settings.ini und setzt die benötigten PHP-Konstanten
 */
class OserpConfig {

    /**
     * Flag ob Konfiguration bereits geladen wurde
     */
    private static $initialized = false;

    /**
     * Aktuell geladene Einstellungen
     */
    private static $currentSettings = [];

    /**
     * Verschlüsselt einen String
     *
     * @param string $s String zum Verschlüsseln
     * @return string Verschlüsselter String (Base64)
     */
    private static function enc(string $s): string {
        $key = 'k';
        return base64_encode($s ^ str_repeat($key, strlen($s)));
    }

    /**
     * Entschlüsselt einen String
     *
     * @param string $s Verschlüsselter String (Base64)
     * @return string Entschlüsselter String
     */
    private static function dec(string $s): string {
        $key = 'k';
        return base64_decode($s) ^ str_repeat($key, strlen(base64_decode($s)));
    }

    /**
     * Lädt die Konfiguration aus settings.ini
     *
     * @throws Exception wenn settings.ini nicht gefunden oder ungültig ist
     */
    private static function loadSettings() {
        $iniFile = SETUP_SETTINGS_DIR.SETUP_SETTINGS_INI_FILE;;

        if (!file_exists($iniFile)) {
            throw new Exception('Konfigurationsdatei '.SETTINGS_INI_FILE.' nicht gefunden. Bitte '.SETTINGS_INI_FILE.'.example in '.SETTINGS_INI_FILE.' umbenennen und anpassen.');
        }

        $settings = parse_ini_file($iniFile, true, INI_SCANNER_TYPED);

        if ($settings === false) {
            throw new Exception('Fehler beim Lesen der Konfigurationsdatei '.SETTINGS_INI_FILE.'. Bitte Syntax prüfen.');
        }

        // Passwort entschlüsseln wenn vorhanden
        if (isset($settings['database']['auth_pass']) && !empty($settings['database']['auth_pass'])) {
            $settings['database']['auth_pass'] = self::dec($settings['database']['auth_pass']);
        }

        self::$currentSettings = $settings;
        return $settings;
    }

    /**
     * Initialisiert die Konfiguration und setzt die Konstanten
     *
     * @throws Exception wenn settings.ini nicht gefunden oder ungültig ist
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }

        // Konfiguration aus settings.ini laden
        $settings = self::loadSettings();

        // Datenbank-Konstanten
        define('DB_HOST', $settings['database']['host'] ?? 'localhost');
        define('DB_PORT', $settings['database']['port'] ?? '5432');
        define('DB_AUTH_NAME', $settings['database']['auth_db'] ?? 'oserp_auth');
        define('DB_AUTH_USER', $settings['database']['auth_user'] ?? 'postgres');
        define('DB_AUTH_PASS', $settings['database']['auth_pass'] ?? '');

        // Session-Konstanten
        define('SESSION_COOKIE', $settings['session']['cookie_name'] ?? 'opensource_erp');
        define('COOKIE_SAME_SITE', $settings['session']['cookie_same_site'] ?? 'Strict');

        // Logging-Konstanten
        define('OSERP_DEBUG_LOG_DIR', __DIR__.'/../log/');
        define('OSERP_API_LOG_DIR', __DIR__.'/../log/');
        define('OSERP_DEBUG_LOG_FILE', $settings['logging']['debug_log_file'] ?? OSERP_DEBUG_LOG_DIR.'opensource_erp.api.debug.log');
        define('OSERP_API_LOG_FILE', $settings['logging']['api_log_file'] ?? OSERP_API_LOG_DIR.'opensource_erp.api.log');
        define('OSERP_DEBUG_LOG_MAX_SIZE', $settings['logging']['max_log_size'] ?? 10485760);

        // System-Konstanten
        define('OSERP_DEFAULT_TIMEZONE', $settings['system']['timezone'] ?? 'Europe/Berlin');
        define('BACKUP_BASE_DIR', $settings['system']['backup_dir'] ?? __DIR__ . '/../../backups/');
        // Template-Verzeichnis: relative Pfade vom Backend-Root aufloesen
        $rawTemplatesDir = $settings['system']['templates_dir'] ?? 'templates';
        if ($rawTemplatesDir[0] === '/') {
            define('OSERP_TEMPLATES_DIR', $rawTemplatesDir);
        } else {
            define('OSERP_TEMPLATES_DIR', realpath(__DIR__ . '/..') . '/' . $rawTemplatesDir);
        }
        // Relativer Name fuer DB-Eintraege (Kivitendo-Stil: 'templates/setname')
        define('OSERP_TEMPLATES_DIR_NAME', $rawTemplatesDir);

        // Verzeichnisauswahl: Einstiegspunkte des Auswahldialogs, durch
        // Komma getrennt. Ohne Eintrag leitet browseRootDirs() sie aus der
        // Installation und den eingetragenen Pfaden ab; mit Eintrag gilt
        // allein diese Liste — so laesst sich die Sicht auf einem gemeinsam
        // genutzten Server beschneiden.
        define('OSERP_BROWSE_ROOTS', $settings['system']['browse_roots'] ?? '');

        // Telefonie-Konstanten
        define('TELEPHONY_MONITOR_DIR', $settings['telephony']['monitor_dir'] ?? '/var/spool/asterisk/monitor');

        // Demo-Modus
        define('DEMO_MODE', $settings['demo']['enabled'] ?? false);
        define('DEMO_INACTIVITY_MINUTES', intval($settings['demo']['inactivity_minutes'] ?? 20));
        define('DEMO_COMPANY_DB', $settings['demo']['company_db'] ?? '');

        // Firmen-Administration
        define('COMPANY_ADMIN_USERS', $settings['company']['admin_users'] ?? '');

        // Globale Debug-Variable
        global $DEBUG;
        $DEBUG = $settings['system']['debug'] ?? false;

        self::$initialized = true;
    }

    /**
     * Formatiert einen Wert für die INI-Datei
     *
     * @param mixed $value Wert
     * @return string Formatierter Wert
     */
    private static function formatIniValue($value) {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        // Strings in Anführungszeichen setzen
        return '"'.str_replace('"', '\"', $value).'"';
    }

    /**
     * Speichert die Einstellungen in settings.ini
     *
     * @param array $settings Assoziatives Array mit Sektionen und Werten
     * @param bool $createBackup Backup der alten Datei erstellen (Standard: true)
     * @return bool Erfolg
     * @throws Exception bei Schreibfehlern
     */
    public static function saveSettings($settings, $createBackup = true) {
        $iniFile = SETUP_SETTINGS_DIR.SETUP_SETTINGS_INI_FILE;

        // Backup erstellen
        if ($createBackup && file_exists($iniFile)) {
            $backupFile = $iniFile.'.backup.'.date('YmdHis');
            if (!copy($iniFile, $backupFile)) {
                throw new Exception('Konnte Backup nicht erstellen: '.$backupFile);
            }
        }

        // Passwort verschlüsseln vor dem Speichern
        $settingsToSave = $settings;
        if (isset($settingsToSave['database']['auth_pass']) && !empty($settingsToSave['database']['auth_pass'])) {
            $settingsToSave['database']['auth_pass'] = self::enc($settingsToSave['database']['auth_pass']);
        }

        // INI-Datei zusammenbauen
        $content = "; config/".SETUP_SETTINGS_INI_FILE."\n";
        $content .= "; OpensourceERP Konfigurationsdatei\n";
        $content .= ";\n";
        $content .= "; WICHTIG: Diese Datei enthält sensible Zugangsdaten!\n";
        $content .= "; Niemals in Git einchecken oder öffentlich zugänglich machen.\n";
        $content .= ";\n";
        $content .= "; Erstellt: ".date('Y-m-d H:i:s')."\n\n";

        foreach ($settingsToSave as $section => $values) {
            $content .= "[$section]\n";
            foreach ($values as $key => $value) {
                $content .= $key.' = '.self::formatIniValue($value)."\n";
            }
            $content .= "\n";
        }

        // Datei schreiben
        if (file_put_contents($iniFile, $content, LOCK_EX) === false) {
            throw new Exception('Konnte '.$iniFile.' nicht schreiben');
        }

        // Aktuell geladene Einstellungen aktualisieren (unverschlüsselt!)
        self::$currentSettings = $settings;

        return true;
    }

    /**
     * Aktualisiert einen einzelnen Wert in der Konfiguration
     *
     * @param string $section Sektion (z.B. 'database', 'system')
     * @param string $key Schlüssel (z.B. 'host', 'debug')
     * @param mixed $value Neuer Wert
     * @param bool $createBackup Backup erstellen (Standard: true)
     * @return bool Erfolg
     * @throws Exception bei Fehlern
     */
    public static function updateValue($section, $key, $value, $createBackup = true) {
        // Aktuelle Einstellungen laden wenn noch nicht geschehen
        if (empty(self::$currentSettings)) {
            self::loadSettings();
        }

        // Wert aktualisieren
        if (!isset(self::$currentSettings[$section])) {
            self::$currentSettings[$section] = [];
        }
        self::$currentSettings[$section][$key] = $value;

        // Speichern
        return self::saveSettings(self::$currentSettings, $createBackup);
    }

    /**
     * Aktualisiert mehrere Werte in einer Sektion
     *
     * @param string $section Sektion (z.B. 'database', 'system')
     * @param array $values Assoziatives Array mit Schlüssel-Wert-Paaren
     * @param bool $createBackup Backup erstellen (Standard: true)
     * @return bool Erfolg
     * @throws Exception bei Fehlern
     */
    public static function updateSection($section, $values, $createBackup = true) {
        // Aktuelle Einstellungen laden wenn noch nicht geschehen
        if (empty(self::$currentSettings)) {
            self::loadSettings();
        }

        // Sektion aktualisieren
        if (!isset(self::$currentSettings[$section])) {
            self::$currentSettings[$section] = [];
        }
        self::$currentSettings[$section] = array_merge(
            self::$currentSettings[$section],
            $values
        );

        // Speichern
        return self::saveSettings(self::$currentSettings, $createBackup);
    }

    /**
     * Gibt die aktuell geladenen Einstellungen zurück
     *
     * @param string|null $section Optional: Nur eine bestimmte Sektion zurückgeben
     * @return array|null Einstellungen oder null wenn Sektion nicht existiert
     */
    public static function getCurrentSettings($section = null) {
        if (empty(self::$currentSettings)) {
            self::loadSettings();
        }

        if ($section !== null) {
            return self::$currentSettings[$section] ?? null;
        }

        return self::$currentSettings;
    }
}

/**
 * Befehl, der post_max_size und upload_max_filesize dieser PHP-Instanz anhebt
 *
 * Sucht die Datei, in der post_max_size für den laufenden Prozess gesetzt
 * ist: bei PHP-FPM die Pool-Datei des eigenen Pools (php_admin_value oder
 * php_value — sie geht der php.ini vor), sonst die zuletzt eingelesene
 * ini-Datei, die den Wert nennt, sonst die php.ini. Daraus entsteht eine
 * Befehlszeile für den Administrator; ausgeführt wird hier nichts.
 *
 * Kein Befehl (null), wenn sich die Datei nicht bestimmen lässt oder PHP in
 * einem Container läuft — dort gehört die Änderung in das Image oder die
 * Compose-Datei, und ein systemctl gibt es nicht.
 *
 * @param int $zielBytes Größe, die PHP je Anfrage annehmen soll
 * @return array{file: string, value: string, command: string}|null
 */
function phpLimitFix(int $zielBytes): ?array {
    if ($zielBytes <= 0 || file_exists('/.dockerenv')) {
        return null;
    }
    $wert  = (int)ceil($zielBytes / (1024 * 1024)).'M';
    $datei = null;
    $pool  = false;

    if ('fpm-fcgi' === PHP_SAPI) {
        // Der Prozesstitel nennt den Pool: „php-fpm: pool www“
        $titel = (string)@file_get_contents('/proc/self/cmdline');
        $name  = preg_match('/pool\s+([^\s\0]+)/', $titel, $m) ? $m[1] : '';
        $verzeichnisse = array_unique(array_filter([
            '/etc/php/'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'/fpm/pool.d',
            php_ini_loaded_file() ? dirname((string)php_ini_loaded_file()).'/pool.d' : '',
            '/etc/php-fpm.d',
        ]));
        foreach ($verzeichnisse as $verzeichnis) {
            foreach (glob($verzeichnis.'/*.conf') ?: [] as $kandidat) {
                $inhalt = (string)@file_get_contents($kandidat);
                if ('' !== $name && !preg_match('/^\s*\['.preg_quote($name, '/').'\]/m', $inhalt)) {
                    continue;
                }
                if (preg_match('/^\s*php_(admin_)?value\[post_max_size\]/m', $inhalt)) {
                    $datei = $kandidat;
                    $pool  = true;
                    break 2;
                }
            }
        }
    }

    if (null === $datei) {
        $kandidaten = array_filter(array_map('trim', explode(',', (string)php_ini_scanned_files())));
        // Später eingelesene Dateien gewinnen — also von hinten suchen
        foreach (array_reverse($kandidaten) as $kandidat) {
            if (preg_match('/^\s*post_max_size\s*=/m', (string)@file_get_contents($kandidat))) {
                $datei = $kandidat;
                break;
            }
        }
        if (null === $datei && php_ini_loaded_file()) {
            $datei = (string)php_ini_loaded_file();
        }
    }
    if (null === $datei) {
        return null;
    }

    $muster = $pool
        ? 's/^(php_(admin_)?value\[(post_max_size|upload_max_filesize)\] *= *).*/\1'.$wert.'/'
        : 's/^;? *((post_max_size|upload_max_filesize) *= *).*/\1'.$wert.'/';
    $befehl = 'sudo sed -i -E '.escapeshellarg($muster).' '.escapeshellarg($datei);

    $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    if ('fpm-fcgi' === PHP_SAPI) {
        $befehl .= ' && sudo systemctl reload php'.$version.'-fpm';
    } elseif ('apache2handler' === PHP_SAPI) {
        $befehl .= ' && sudo systemctl reload apache2';
    }

    return ['file' => $datei, 'value' => $wert, 'command' => $befehl];
}

/**
 * php.ini-Größenangabe ("8M", "64M", "1G", "524288") in Bytes umrechnen
 */
function iniBytes(string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1') return 0;
    $unit = strtolower(substr($value, -1));
    $num = (float)$value;
    switch ($unit) {
        case 'g': return (int)($num * 1024 * 1024 * 1024);
        case 'm': return (int)($num * 1024 * 1024);
        case 'k': return (int)($num * 1024);
        default: return (int)$num;
    }
}

// Konfiguration initialisieren und Konstanten setzen
try {
    OserpConfig::init();
} catch (Exception $e) {
    // Fehlerbehandlung für fehlende oder fehlerhafte settings.ini
    if (function_exists('resultInfo')) {
        resultInfo(false, "CONFIG_ERROR", $e->getMessage());
    } else {
        die("Konfigurationsfehler: ".$e->getMessage());
    }
}