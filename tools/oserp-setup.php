#!/usr/bin/env php
<?php
// tools/oserp-setup.php

/**
 * OSERP-Installation von der Kommandozeile — ohne kivitendo, ohne Browser
 *
 * Legt Auth-Datenbank, ersten Administrator und erste Firma an und schreibt
 * backend/config/settings.ini. Nutzt exakt dieselben Funktionen wie der
 * Setup-Assistent im Browser (backend/api/setup/setup.php -> setupInstall()).
 *
 * Beispiel:
 *   php tools/oserp-setup.php --host localhost --port 5432 --user postgres --pass geheim \
 *       --auth-db oserp_auth --admin-login admin --admin-password 'Start123!' \
 *       --admin-name "Max Mustermann" --company "Muster GmbH" --company-db muster_gmbh --skr skr03
 *
 * Optionen:
 *   --host, --port, --user, --pass     PostgreSQL-Zugang (Rolle mit CREATEDB)
 *   --auth-db NAME                     Auth-Datenbank (Standard: oserp_auth)
 *   --auth-existing                    vorhandene Auth-DB verwenden statt anlegen
 *   --admin-login, --admin-password, --admin-name, --admin-email
 *                                      ohne Angabe entsteht der Standardzugang admin/admin
 *   --company NAME                     erste Firma anlegen (mit --company-db, --skr)
 *   --company-db NAME                  Datenbankname (Standard: aus dem Firmennamen)
 *   --skr skr03|skr04                  Kontenrahmen (Standard: skr03)
 *   --no-default                       Firma nicht als Standard-Mandant setzen
 *   --settings-file NAME               abweichender Dateiname in backend/config/ (Tests)
 *   --overwrite-settings               vorhandene settings.ini überschreiben
 *   --json                             Ergebnis als JSON ausgeben
 *
 * Umgebungsvariablen als Alternative zu Optionen: OSERP_DB_HOST, OSERP_DB_PORT,
 * OSERP_DB_USER, OSERP_DB_PASSWORD, OSERP_AUTH_DB, OSERP_ADMIN_LOGIN,
 * OSERP_ADMIN_PASSWORD, OSERP_ADMIN_NAME, OSERP_COMPANY_NAME, OSERP_COMPANY_DB, OSERP_SKR
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur auf der Kommandozeile ausführbar\n");
    exit(1);
}

$longopts = [
    'host:', 'port:', 'user:', 'pass:', 'auth-db:', 'auth-existing',
    'admin-login:', 'admin-password:', 'admin-name:', 'admin-email:',
    'company:', 'company-db:', 'skr:', 'no-default',
    'settings-file:', 'overwrite-settings', 'json', 'help',
];
$opt = getopt('h', $longopts);
if (isset($opt['help']) || isset($opt['h'])) {
    echo preg_replace('/^\/\*\*\n|\n \*\/$/', '', substr(file_get_contents(__FILE__), strpos(file_get_contents(__FILE__), '/**'), strpos(file_get_contents(__FILE__), '*/') - strpos(file_get_contents(__FILE__), '/**') + 2));
    echo "\n";
    exit(0);
}

$env = fn($name, $default = null) => (getenv($name) !== false && getenv($name) !== '') ? getenv($name) : $default;
$val = fn($key, $envName, $default = null) => $opt[$key] ?? $env($envName, $default);

if (!empty($opt['settings-file'])) {
    putenv('OSERP_SETTINGS_INI_FILE=' . $opt['settings-file']);
}

// Minimaler Bootstrap ohne Sitzung/Dispatcher
require_once __DIR__ . '/../backend/api/error.php';
require_once __DIR__ . '/../backend/api/config.php';
require_once __DIR__ . '/../backend/api/logging.php';
require_once __DIR__ . '/../backend/api/password.php';
require_once __DIR__ . '/../backend/api/database.php';
require_once __DIR__ . '/../backend/api/lib/extensions.php';
require_once __DIR__ . '/../backend/api/lib/tenant.php';
require_once __DIR__ . '/../backend/api/setup/setup.php';

function setupExists(): bool {
    return file_exists(SETUP_SETTINGS_DIR . SETUP_SETTINGS_INI_FILE);
}

$json = isset($opt['json']);
$say = function ($msg) use ($json) {
    if (!$json) {
        echo $msg . "\n";
    }
};

if (setupExists() && !isset($opt['overwrite-settings'])) {
    fwrite(STDERR, "backend/config/" . SETUP_SETTINGS_INI_FILE . " existiert bereits. Mit --overwrite-settings überschreiben oder --settings-file nutzen.\n");
    exit(2);
}

$params = [
    'host' => $val('host', 'OSERP_DB_HOST', 'localhost'),
    'port' => (int)$val('port', 'OSERP_DB_PORT', 5432),
    'user' => $val('user', 'OSERP_DB_USER', 'postgres'),
    'pass' => (string)$val('pass', 'OSERP_DB_PASSWORD', ''),
    'auth_db' => $val('auth-db', 'OSERP_AUTH_DB', 'oserp_auth'),
    'auth_mode' => isset($opt['auth-existing']) ? 'existing' : 'new',
    'admin_login' => $val('admin-login', 'OSERP_ADMIN_LOGIN', ''),
    'admin_password' => (string)$val('admin-password', 'OSERP_ADMIN_PASSWORD', ''),
    'admin_name' => $val('admin-name', 'OSERP_ADMIN_NAME', ''),
    'admin_email' => $val('admin-email', 'OSERP_ADMIN_EMAIL', ''),
    'company_create' => (bool)$val('company', 'OSERP_COMPANY_NAME', ''),
    'company_name' => $val('company', 'OSERP_COMPANY_NAME', ''),
    'company_db' => $val('company-db', 'OSERP_COMPANY_DB', ''),
    'company_skr' => $val('skr', 'OSERP_SKR', 'skr03'),
    'company_default' => !isset($opt['no-default']),
    'write_settings' => true,
];

$say("OSERP-Installation");
$say("  Server:      {$params['host']}:{$params['port']} als {$params['user']}");
$say("  Auth-DB:     {$params['auth_db']} (" . ($params['auth_mode'] === 'new' ? 'neu' : 'vorhanden') . ")");
$say("  Admin:       " . ($params['admin_login'] ?: 'Standardzugang ' . OSERP_DEFAULT_ADMIN_LOGIN . '/' . OSERP_DEFAULT_ADMIN_PASSWORD));
$say("  Firma:       " . ($params['company_create'] ? "{$params['company_name']} [{$params['company_skr']}]" : '- (keine)'));

try {
    $result = setupInstall($params);
} catch (Exception $e) {
    if ($json) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDERR, "FEHLER: " . $e->getMessage() . "\n");
    }
    exit(1);
}

if ($json) {
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}
foreach ($result['steps'] as $s) {
    $say("  [OK] {$s['step']}: {$s['detail']}");
}
foreach ($result['warnings'] as $w) {
    $say("  [WARNUNG] $w");
}
$say("Fertig. Anmeldung im Browser mit '{$result['admin_login']}'.");
if (!empty($result['admin_default_password'])) {
    $say("ACHTUNG: Das Passwort ist zunaechst '" . OSERP_DEFAULT_ADMIN_PASSWORD . "'. Bitte nach der ersten Anmeldung aendern.");
}
exit(0);
