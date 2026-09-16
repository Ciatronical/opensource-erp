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
// ============================================================================
// INSTALLATION OHNE KIVITENDO
// ============================================================================
// Der Setup-Assistent kann eine komplette Installation aus dem Nichts anlegen:
// Auth-Datenbank (kivitendo-kompatibel), erster Administrator, erste Firma mit
// Kontenrahmen. Ebenso kann er sich an eine vorhandene (kivitendo-)Auth-DB hängen.
// Die Arbeit machen die Funktionen in backend/api/lib/tenant.php — dieselben,
// die auch die Administration in der Oberfläche und tools/oserp-setup.php nutzen.

/**
 * Action: status
 *
 * Sagt der Oberflaeche, was noch fehlt. Ohne Anmeldung erreichbar, solange die
 * Installation unvollstaendig ist (siehe setup/index.php) — es gibt dann noch
 * nichts zu schuetzen.
 *
 * @testdata {}
 */
function status($data) {
    $state = tenantInstallationState();

    // Gibt es schon Benutzer, gehoert die Installation jemandem: dann nur noch die
    // Stufe verraten, keine Verbindungsangaben.
    if ($state['users'] > 0) {
        $state = [
            'stage' => $state['stage'],
            'settings_exists' => $state['settings_exists'],
            'has_schema' => $state['has_schema'],
            'users' => $state['users'],
            'clients' => $state['clients'],
            'default_admin_login' => $state['default_admin_login'],
        ];
    }
    resultInfo(true, '', $state);
}

/**
 * Action: probe
 *
 * Verbindet sich mit den angegebenen Zugangsdaten zum Server und meldet, was
 * es dort gibt: Rechte der Rolle, alle Datenbanken mit Einordnung (Auth-DB,
 * Firmen-DB, sonstiges). Damit kann der Assistent vorschlagen statt fragen.
 *
 * @param string $data['host'] Host
 * @param int $data['port'] Port
 * @param string $data['user'] Rolle
 * @param string $data['pass'] Passwort
 * @testdata {"host": "localhost", "port": 5432, "user": "postgres", "pass": ""}
 */
function probe($data) {
    // Bei vorhandener Konfiguration muss niemand das Datenbank-Passwort erneut
    // eintippen — es steht schon in der settings.ini und verlaesst den Server nicht.
    if (!empty($data['use_stored_credentials']) && setupExists()) {
        $host = DB_HOST;
        $port = (int)DB_PORT;
        $user = DB_AUTH_USER;
        $pass = DB_AUTH_PASS;
    } else {
        $host = trim((string)($data['host'] ?? 'localhost'));
        $port = (int)($data['port'] ?? 5432);
        $user = trim((string)($data['user'] ?? ''));
        $pass = (string)($data['pass'] ?? '');
    }
    if ($host === '' || $user === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Host und Benutzer sind erforderlich');
        return;
    }
    try {
        $pdo = tenantAdminConnect($host, $port, $user, $pass);
    } catch (PDOException $e) {
        resultInfo(false, 'CONNECTION_FAILED', $e->getMessage());
        return;
    }
    $role = tenantRoleInfo($pdo);
    $databases = [];
    foreach (tenantListDatabases($pdo) as $db) {
        $info = tenantInspectDatabase($host, $port, $db['name'], $user, $pass);
        $databases[] = [
            'name' => $db['name'],
            'owner' => $db['owner'],
            'size_bytes' => $db['size_bytes'] !== null ? (int)$db['size_bytes'] : null,
            'reachable' => $info['reachable'],
            'is_auth' => $info['is_auth'],
            'is_company' => $info['is_company'],
            'has_oserp' => $info['has_oserp'],
            'company' => $info['company'],
            'coa' => $info['coa'],
            'users' => $info['users'],
            'clients' => $info['clients'],
        ];
    }
    resultInfo(true, '', [
        'server' => ['host' => $host, 'port' => $port, 'user' => $user, 'version' => $role['version'],
                     'superuser' => $role['superuser'], 'createdb' => $role['createdb']],
        'databases' => $databases,
        'charts' => tenantAvailableCharts(),
        'suggested_auth_db' => 'oserp_auth',
    ]);
}

/**
 * Führt die Installation durch (gemeinsam für Assistent und Kommandozeile)
 *
 * @param array $p Parameter:
 *   host, port, user, pass          Serverzugang (Rolle braucht CREATEDB für neue DBs)
 *   auth_db                         Name der Auth-Datenbank
 *   auth_mode                       'new' (anlegen) oder 'existing' (vorhandene nutzen)
 *   admin_login, admin_password, admin_name, admin_email   Erster Administrator (optional bei existing)
 *   company_create (bool), company_name, company_db, company_skr, company_default
 *   write_settings (bool)           settings.ini schreiben (Standard: true)
 * @return array Zusammenfassung {auth_db, auth_created, admin_user_id, client_id, warnings[], steps[]}
 * @throws Exception mit sprechender Meldung
 */
function setupInstall(array $p) {
    set_time_limit(0);
    $steps = [];
    $warnings = [];

    // Unvollstaendige Installation: Zugang und Datenbankname stehen bereits in der
    // settings.ini. Dann reicht ein Klick, ohne alles noch einmal einzugeben.
    $stored = !empty($p['use_stored_credentials']) && setupExists();
    $host = $stored ? DB_HOST      : trim((string)($p['host'] ?? 'localhost'));
    $port = $stored ? (int)DB_PORT : (int)($p['port'] ?? 5432);
    $user = $stored ? DB_AUTH_USER : trim((string)($p['user'] ?? ''));
    $pass = $stored ? DB_AUTH_PASS : (string)($p['pass'] ?? '');
    $authDbName = trim((string)($p['auth_db'] ?? ($stored ? DB_AUTH_NAME : '')));
    $authMode = ($p['auth_mode'] ?? 'new') === 'existing' ? 'existing' : 'new';

    if ($host === '' || $user === '') {
        throw new Exception('Host und Datenbank-Benutzer sind erforderlich');
    }
    if (!isValidDbName($authDbName)) {
        throw new Exception('Name der Auth-Datenbank ungültig (Kleinbuchstaben, Ziffern, Unterstrich)');
    }

    // ── 1. Serverzugang ──
    try {
        $adminPdo = tenantAdminConnect($host, $port, $user, $pass);
    } catch (PDOException $e) {
        throw new Exception('Verbindung zum Datenbankserver fehlgeschlagen: ' . $e->getMessage());
    }
    $role = tenantRoleInfo($adminPdo);
    $steps[] = ['step' => 'connect', 'ok' => true, 'detail' => $role['version']];

    // ── 2. Auth-Datenbank ──
    $authExists = tenantDatabaseExists($adminPdo, $authDbName);
    $authCreated = false;
    if ($authMode === 'new') {
        if ($authExists) {
            throw new Exception("Datenbank '$authDbName' existiert bereits — entweder anderen Namen wählen oder \"vorhandene Auth-Datenbank verwenden\"");
        }
        if (!$role['createdb'] && !$role['superuser']) {
            throw new Exception("Die Rolle '$user' darf keine Datenbanken anlegen (CREATEDB fehlt)");
        }
        $adminPdo->exec('CREATE DATABASE ' . pgIdent($authDbName) . " ENCODING 'UTF8' TEMPLATE template0");
        $authCreated = true;
        $steps[] = ['step' => 'auth_db_created', 'ok' => true, 'detail' => $authDbName];
    } elseif (!$authExists) {
        throw new Exception("Auth-Datenbank '$authDbName' nicht gefunden");
    }

    try {
        $authPdo = tenantConnect($host, $port, $authDbName, $user, $pass, 10);
        $authDb = new ApiDatabase($authPdo);

        // Schema (kivitendo-Tabellen + OSERP-Tabellen), Seed-Daten
        $res = tenantInstallAuthSchema($authDb);
        if (!$res['success']) {
            throw new Exception('Auth-Schema konnte nicht eingespielt werden: ' . implode('; ', $res['errors']));
        }
        $steps[] = ['step' => 'auth_schema', 'ok' => true, 'detail' => count($res['messages']) . ' Schritte'];

        // ── 3. Gruppe "Vollzugriff" + Administrator ──
        $fullAccessGroupId = tenantEnsureFullAccessGroup($authDb);
        $adminUserId = null;
        $adminDefaultPassword = false;
        $adminLogin = trim((string)($p['admin_login'] ?? ''));

        // Ohne Angabe entsteht der Standardzugang admin/admin, damit die frisch
        // eingerichtete Datenbank sofort benutzbar ist. Gibt es schon Benutzer,
        // bleibt alles, wie es ist.
        if ($adminLogin === '') {
            $adminUserId = tenantEnsureDefaultAdmin($authDb);
            if ($adminUserId !== null) {
                $adminLogin = OSERP_DEFAULT_ADMIN_LOGIN;
                $adminDefaultPassword = true;
                $steps[] = ['step' => 'admin_user', 'ok' => true,
                            'detail' => OSERP_DEFAULT_ADMIN_LOGIN . ' / ' . OSERP_DEFAULT_ADMIN_PASSWORD];
            }
        }

        if ($adminLogin !== '' && $adminUserId === null) {
            $adminPassword = (string)($p['admin_password'] ?? '');
            $existingUser = $authDb->getOne('SELECT id FROM auth."user" WHERE login = :l', [':l' => $adminLogin]);
            if ($existingUser) {
                // Vorhandener Benutzer wird Administrator; Passwort nur setzen, wenn eines übergeben wurde
                $adminUserId = tenantSaveUser($authDb, $adminLogin, $adminPassword !== '' ? $adminPassword : null,
                    ['oserp_admin' => '1'], (int)$existingUser['id']);
                $steps[] = ['step' => 'admin_user', 'ok' => true, 'detail' => "$adminLogin (vorhanden, jetzt Administrator)"];
            } else {
                if (strlen($adminPassword) < 4) {
                    throw new Exception('Passwort des Administrators fehlt (mindestens 4 Zeichen)');
                }
                $adminUserId = tenantSaveUser($authDb, $adminLogin, $adminPassword, [
                    'name' => trim((string)($p['admin_name'] ?? '')) ?: $adminLogin,
                    'email' => trim((string)($p['admin_email'] ?? '')) ?: null,
                    'countrycode' => 'de', 'dateformat' => 'dd.mm.yy', 'numberformat' => '1.000,00',
                    'oserp_admin' => '1',
                ]);
                $steps[] = ['step' => 'admin_user', 'ok' => true, 'detail' => $adminLogin];
            }
            $authDb->execute(
                'INSERT INTO auth.user_group (user_id, group_id) VALUES (:u, :g) ON CONFLICT DO NOTHING',
                [':u' => $adminUserId, ':g' => $fullAccessGroupId]
            );
        }

        // Vorhandene Mandanten bekommen den neuen Administrator gleich mit (sonst steht er vor verschlossener Tür)
        if ($adminUserId !== null) {
            $authDb->execute(
                'INSERT INTO auth.clients_users (client_id, user_id) SELECT id, :u FROM auth.clients ON CONFLICT DO NOTHING',
                [':u' => $adminUserId]
            );
        }

        // ── 4. Erste Firma ──
        $clientId = null;
        if (!empty($p['company_create'])) {
            $companyName = trim((string)($p['company_name'] ?? ''));
            $companyDb = trim((string)($p['company_db'] ?? '')) ?: suggestDbName($companyName);
            $userIds = $adminUserId !== null ? [$adminUserId] : array_map(fn($r) => (int)$r['id'], $authDb->getAll('SELECT id FROM auth."user"', []));
            $result = tenantCreateCompany($authDb, [
                'companyName' => $companyName,
                'dbname' => $companyDb,
                'skr' => (string)($p['company_skr'] ?? 'skr03'),
                'dbhost' => $host, 'dbport' => $port, 'dbuser' => $user, 'dbpasswd' => $pass,
                'userIds' => $userIds,
                'groupIds' => [$fullAccessGroupId],
                'isDefault' => !isset($p['company_default']) || !empty($p['company_default']),
            ]);
            $clientId = $result['client_id'];
            $warnings = array_merge($warnings, $result['warnings']);
            $steps[] = ['step' => 'company', 'ok' => true, 'detail' => "$companyName ($companyDb)"];
        }
        if ($adminUserId !== null) {
            $warnings = array_merge($warnings, tenantSyncUserEmployees($authDb, $adminUserId));
        }
    } catch (\Throwable $e) {
        // Frisch angelegte Auth-DB nicht halb fertig liegen lassen
        if ($authCreated) {
            $authPdo = null;
            $authDb = null;
            try {
                tenantDropDatabase($adminPdo, $authDbName);
            } catch (\Throwable $dropEx) {
                // dann bleibt sie eben stehen — der Fehler unten ist wichtiger
            }
        }
        throw new Exception($e->getMessage());
    }

    // ── 5. settings.ini ──
    // Eine vorhandene Datei bleibt unangetastet: sie enthaelt neben dem Zugang auch
    // Abschnitte wie [demo] oder [company], die ein Neuschreiben verlieren wuerde.
    $writeSettings = isset($p['write_settings']) ? !empty($p['write_settings']) : !setupExists();
    if ($writeSettings) {
        if (!createSettingsIni(['host' => $host, 'port' => $port, 'auth_db' => $authDbName, 'auth_user' => $user, 'auth_pass' => $pass])) {
            throw new Exception('settings.ini konnte nicht geschrieben werden (Schreibrechte auf backend/config/ prüfen)');
        }
        $steps[] = ['step' => 'settings', 'ok' => true, 'detail' => SETUP_SETTINGS_INI_FILE];
    }

    return [
        'auth_db' => $authDbName,
        'auth_created' => $authCreated,
        'admin_user_id' => $adminUserId,
        'admin_login' => $adminLogin,
        // true => es gilt das Standardpasswort, die Oberflaeche weist darauf hin
        'admin_default_password' => $adminDefaultPassword,
        'client_id' => $clientId,
        'warnings' => $warnings,
        'steps' => $steps,
    ];
}

/**
 * Action: install
 *
 * Komplett-Installation aus dem Assistenten: Auth-DB anlegen oder übernehmen,
 * Administrator, erste Firma, settings.ini. Nur solange noch keine settings.ini
 * existiert.
 *
 * @param array $data['data'] Parameter wie bei setupInstall()
 * @testdata {"data": {"host": "localhost", "port": 5432, "user": "postgres", "pass": "", "auth_db": "oserp_auth", "auth_mode": "new", "admin_login": "admin", "admin_password": "admin", "admin_name": "Administrator", "company_create": true, "company_name": "Meine Firma", "company_db": "meine_firma", "company_skr": "skr03"}}
 */
function install($data) {
    $state = tenantInstallationState();

    if ($state['stage'] === 'ready') {
        throw new ApiError('SETUP_ALREADY_DONE', 'Die Installation ist bereits vollständig');
    }
    if ($state['stage'] === 'unreachable') {
        resultInfo(false, 'AUTH_DB_UNREACHABLE',
            'Die Auth-Datenbank ist mit den Angaben aus der Konfiguration nicht erreichbar: ' . $state['error']);
        return;
    }
    if (!isset($data['data']) || !is_array($data['data'])) {
        throw new ApiError('INVALID_REQUEST', 'Keine Daten übergeben');
    }

    // Solange es keinen einzigen Benutzer gibt, ist nichts zu schuetzen — der erste
    // Aufrufer richtet ein. Sobald Benutzer existieren (etwa: Benutzer ja, Firma nein),
    // muss sich ein Administrator ausweisen, bevor der Assistent weiterarbeitet.
    if ($state['users'] > 0 && !setupVerifyAdmin($data['data'])) {
        resultInfo(false, 'ADMIN_LOGIN_REQUIRED',
            'Diese Installation hat bereits Benutzer. Bitte mit einem vorhandenen Administrator anmelden.');
        return;
    }

    try {
        $result = setupInstall($data['data']);
    } catch (Exception $e) {
        resultInfo(false, 'INSTALL_FAILED', $e->getMessage());
        return;
    }
    resultInfo(true, 'SETUP_COMPLETE', $result);
}

/**
 * Prueft die im Assistenten eingegebenen Administrator-Zugangsdaten gegen die Auth-Datenbank
 *
 * Wird nur gebraucht, wenn schon Benutzer existieren — dann darf der Assistent nicht
 * mehr fuer jeden offenstehen.
 *
 * @param array $p Parameter des Assistenten (admin_login, admin_password)
 * @return bool true, wenn der Zugang stimmt und der Benutzer administrieren darf
 */
function setupVerifyAdmin(array $p): bool {
    $login = trim((string)($p['admin_login'] ?? ''));
    $password = (string)($p['admin_password'] ?? '');
    if ($login === '' || $password === '') {
        return false;
    }
    try {
        $authDb = new ApiDatabase(tenantConnect(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS, 5));
    } catch (PDOException $e) {
        return false;
    }
    $user = $authDb->getOne('SELECT id, login, password FROM auth."user" WHERE login = :l', [':l' => $login]);
    if (!$user || !verify_password($login, $password, (string)$user['password'])) {
        writeLog("Einrichtungsassistent: Anmeldung als '$login' fehlgeschlagen", true, DLOG_WRN);
        return false;
    }
    return tenantAdminStatus($authDb, (int)$user['id'], $login)['is_admin'];
}
