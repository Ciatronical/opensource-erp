<?php
// backend/tests/trading/bootstrap.php

/**
 * Wegwerf-Datenbank für Tests und Probeläufe des Handelssystems
 *
 * Legt eine eigene Datenbank an (nie eine Firmen-Datenbank), spielt das
 * Trading-Schema über denselben Upstall-Code ein wie ein echtes Update und
 * liefert die Verbindung. Zugangsdaten: die der Auth-Datenbank aus settings.ini.
 */

$apiDir = dirname(__DIR__, 2).'/api';
require_once $apiDir.'/error.php';
require_once $apiDir.'/config.php';
require_once $apiDir.'/logging.php';
require_once $apiDir.'/password.php';
require_once $apiDir.'/database.php';
require_once $apiDir.'/lib/ai_model.php';
require_once $apiDir.'/lib/extensions.php';
require_once $apiDir.'/lib/upstall.php';
require_once $apiDir.'/update/update.php';
require_once $apiDir.'/trading/lib/load.php';

class TradingTestDb {

    /**
     * Legt die Datenbank neu an und spielt das Schema ein
     *
     * @param string $name  Datenbankname (muss mit oserp_trading_ beginnen)
     * @param bool   $fresh true = vorhandene Datenbank vorher löschen
     * @return ApiDatabase
     */
    public static function create(string $name, bool $fresh = true) {
        if (strpos($name, 'oserp_trading_') !== 0 || !preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new RuntimeException('Testdatenbanken heissen oserp_trading_*');
        }
        $admin = connectPDO(DB_HOST, DB_PORT, 'postgres', DB_AUTH_USER, DB_AUTH_PASS);
        $exists = $admin->query("SELECT 1 FROM pg_database WHERE datname = '$name'")->fetchColumn();
        if ($exists && $fresh) {
            $admin->exec("DROP DATABASE \"$name\" WITH (FORCE)");
            $exists = false;
        }
        if (!$exists) $admin->exec("CREATE DATABASE \"$name\"");

        $db = new ApiDatabase(connectPDO(DB_HOST, DB_PORT, $name, DB_AUTH_USER, DB_AUTH_PASS));
        // Was das Trading-Schema von der CRM-Basis voraussetzt
        $db->execute("CREATE TABLE IF NOT EXISTS defaults_oserp (key text PRIMARY KEY, value text)", []);
        $db->execute("CREATE TABLE IF NOT EXISTS extensions_oserp (id serial PRIMARY KEY, extension text UNIQUE, active boolean DEFAULT true)", []);
        $db->execute("INSERT INTO extensions_oserp (extension, active) VALUES ('trading', true) ON CONFLICT DO NOTHING", []);
        self::applySchema($db);
        return $db;
    }

    /**
     * Spielt das Trading-Schema über den Upstall-Code ein
     *
     * @param object $db ApiDatabase
     * @return array Ergebnis von updateDatabaseSchema
     */
    public static function applySchema($db): array {
        $result = updateDatabaseSchema([dirname(__DIR__, 2).'/upstall/trading/company_schema.sql'], [], false, $db);
        if (!$result['success']) {
            throw new RuntimeException('Schema-Update fehlgeschlagen: ' . implode('; ', $result['errors']));
        }
        return $result;
    }
}
