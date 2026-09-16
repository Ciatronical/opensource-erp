<?php
// backend/api/lib/tenant.php

/**
 * Mandanten- und Benutzerverwaltung auf Datenbankebene (kivitendo-kompatibel)
 *
 * Gemeinsame Grundlage für
 *   - die Administration in der Oberfläche (backend/api/admin)
 *   - das Firmen-Anlegen (backend/api/company)
 *   - den Setup-Assistenten ohne kivitendo (backend/api/setup)
 *   - die Kommandozeile (tools/oserp-setup.php)
 *
 * Alle Funktionen arbeiten mit explizit übergebenen Verbindungen und setzen
 * KEINE Sitzung voraus — im Setup gibt es noch keine settings.ini.
 *
 * Datenmodell (auth-Schema, siehe backend/upstall/crm/auth_schema.sql):
 *   auth.user            Benutzer (login, password-Hash)
 *   auth.user_config     Stammdaten je Benutzer (name, email, ...)
 *   auth.group           Berechtigungsgruppen
 *   auth.group_rights    Rechte je Gruppe
 *   auth.user_group      Mitgliedschaften
 *   auth.clients         Mandanten mit DB-Zugangsdaten
 *   auth.clients_users   Wer darf sich wo anmelden
 *   auth.clients_groups  Welche Gruppen gelten in welchem Mandanten
 *   auth.master_rights   Rechtekatalog
 *
 * Wie kivitendo pflegt OSERP in jeder Firmen-Datenbank die Tabelle employee:
 * jeder Benutzer, der einem Mandanten zugeordnet ist, bekommt dort einen
 * Mitarbeiter-Datensatz (login, name). Belege verweisen auf employee.id.
 */

// Standardzugang einer frisch eingerichteten Auth-Datenbank. Bewusst schlicht, damit
// nach der Einrichtung sofort eine Anmeldung moeglich ist; die Verwaltung fordert so
// lange zum Aendern auf, wie das Kennzeichen oserp_default_password am Benutzer haengt
// (siehe tenantEnsureDefaultAdmin).
if (!defined('OSERP_DEFAULT_ADMIN_LOGIN')) {
    define('OSERP_DEFAULT_ADMIN_LOGIN', 'admin');
    define('OSERP_DEFAULT_ADMIN_PASSWORD', 'admin');
}

/**
 * Quoted einen PostgreSQL-Bezeichner (Datenbank, Rolle, Tabelle)
 *
 * @param string $name Bezeichner
 * @return string Gequoteter Bezeichner
 */
function pgIdent($name) {
    return '"' . str_replace('"', '""', $name) . '"';
}

/**
 * Prüft einen Datenbanknamen: Kleinbuchstaben, Ziffern, Unterstrich, Bindestrich
 *
 * @param string $name Datenbankname
 * @return bool
 */
function isValidDbName($name) {
    return (bool)preg_match('/^[a-z][a-z0-9_\-]{0,62}$/', $name);
}

/**
 * Macht aus einem Firmennamen einen Vorschlag für den Datenbanknamen
 *
 * "Müller & Söhne GmbH" -> "mueller_soehne_gmbh"
 *
 * @param string $companyName Firmenname
 * @return string Datenbankname-Vorschlag
 */
function suggestDbName($companyName) {
    $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'];
    $s = strtr($companyName, $map);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    if ($s === '' || !preg_match('/^[a-z]/', $s)) {
        $s = 'firma_' . $s;
    }
    return substr($s, 0, 63);
}

/**
 * Baut eine nicht-persistente Verbindung zu einer Datenbank auf
 *
 * Nicht persistent, weil Setup und Administration kurzlebige Verbindungen zu
 * wechselnden Datenbanken brauchen (Anlegen, Prüfen, Löschen).
 *
 * @param string $host Host
 * @param int|string $port Port
 * @param string $dbname Datenbankname
 * @param string $user Rolle
 * @param string $pass Passwort
 * @param int $timeout Verbindungs-Timeout in Sekunden
 * @return PDO
 * @throws PDOException
 */
function tenantConnect($host, $port, $dbname, $user, $pass, $timeout = 5) {
    return new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $timeout,
            PDO::ATTR_PERSISTENT => false,
        ]
    );
}

/**
 * Verbindung zur Wartungsdatenbank "postgres" des Servers (für CREATE/DROP DATABASE)
 *
 * @param string $host Host
 * @param int|string $port Port
 * @param string $user Rolle
 * @param string $pass Passwort
 * @return PDO
 */
function tenantAdminConnect($host, $port, $user, $pass) {
    return tenantConnect($host, $port, 'postgres', $user, $pass);
}

/**
 * Liefert die Rechte der verbundenen Rolle (Superuser / darf Datenbanken anlegen)
 *
 * @param PDO $pdo Verbindung
 * @return array ['superuser' => bool, 'createdb' => bool, 'version' => string, 'user' => string]
 */
function tenantRoleInfo(PDO $pdo) {
    $row = $pdo->query(
        "SELECT r.rolsuper AS superuser, r.rolcreatedb AS createdb, r.rolname AS user,
                current_setting('server_version') AS version
         FROM pg_roles r WHERE r.rolname = current_user"
    )->fetch(PDO::FETCH_ASSOC);
    return [
        'superuser' => in_array($row['superuser'], [true, 't', '1', 1], true),
        'createdb'  => in_array($row['createdb'], [true, 't', '1', 1], true),
        'version'   => $row['version'],
        'user'      => $row['user'],
    ];
}

/**
 * Listet alle Datenbanken des Servers (ohne Templates)
 *
 * @param PDO $pdo Verbindung
 * @return array [{name, owner, size_bytes}]
 */
function tenantListDatabases(PDO $pdo) {
    return $pdo->query(
        "SELECT d.datname AS name,
                pg_get_userbyid(d.datdba) AS owner,
                CASE WHEN has_database_privilege(d.datname, 'CONNECT')
                     THEN pg_database_size(d.datname) ELSE NULL END AS size_bytes
         FROM pg_database d
         WHERE NOT d.datistemplate AND d.datname <> 'postgres'
         ORDER BY d.datname"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Prüft, ob eine Datenbank auf dem Server existiert
 *
 * @param PDO $pdo Verbindung (beliebige DB desselben Servers)
 * @param string $dbname Datenbankname
 * @return bool
 */
function tenantDatabaseExists(PDO $pdo, $dbname) {
    $stmt = $pdo->prepare("SELECT 1 FROM pg_database WHERE datname = :name");
    $stmt->execute([':name' => $dbname]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Untersucht eine Datenbank: Auth-DB? Firmen-DB? OSERP-Erweiterungen vorhanden?
 *
 * Wird vom Setup und von "Vorhandene Datenbank verbinden" genutzt, damit der
 * Benutzer nicht raten muss, welche Datenbank was ist.
 *
 * @param string $host Host
 * @param int|string $port Port
 * @param string $dbname Datenbankname
 * @param string $user Rolle
 * @param string $pass Passwort
 * @return array {reachable, error, is_auth, is_company, has_oserp, company, coa, version, users, clients}
 */
function tenantInspectDatabase($host, $port, $dbname, $user, $pass) {
    $info = [
        'name' => $dbname, 'reachable' => false, 'error' => null,
        'is_auth' => false, 'is_company' => false, 'has_oserp' => false, 'is_empty' => false,
        'company' => null, 'coa' => null, 'version' => null, 'users' => null, 'clients' => null,
    ];
    try {
        $pdo = tenantConnect($host, $port, $dbname, $user, $pass, 3);
    } catch (PDOException $e) {
        $info['error'] = $e->getMessage();
        return $info;
    }
    $info['reachable'] = true;

    $row = $pdo->query(
        "SELECT to_regclass('auth.user') AS auth_user,
                to_regclass('auth.clients') AS auth_clients,
                to_regclass('public.defaults') AS defaults,
                to_regclass('public.employee') AS employee,
                to_regclass('public.defaults_oserp') AS oserp"
    )->fetch(PDO::FETCH_ASSOC);

    $info['is_auth'] = !empty($row['auth_user']) && !empty($row['auth_clients']);
    $info['is_company'] = !empty($row['defaults']) && !empty($row['employee']);
    $info['has_oserp'] = !empty($row['oserp']);

    // Voellig leere Datenbank: Kandidat fuer eine Einrichtung. Haeufig bei Anbietern,
    // bei denen man selbst keine Datenbanken anlegen darf und eine leere gestellt bekommt.
    if (!$info['is_auth'] && !$info['is_company']) {
        $tables = $pdo->query(
            "SELECT COUNT(*) AS anzahl FROM information_schema.tables
             WHERE table_schema NOT IN ('pg_catalog', 'information_schema')"
        )->fetch(PDO::FETCH_ASSOC);
        $info['is_empty'] = (int)$tables['anzahl'] === 0;
    }

    if ($info['is_auth']) {
        $c = $pdo->query('SELECT (SELECT COUNT(*) FROM auth."user") AS users, (SELECT COUNT(*) FROM auth.clients) AS clients')->fetch(PDO::FETCH_ASSOC);
        $info['users'] = (int)$c['users'];
        $info['clients'] = (int)$c['clients'];
    }
    if ($info['is_company']) {
        $d = $pdo->query('SELECT company, coa, version FROM defaults LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($d) {
            $info['company'] = $d['company'];
            $info['coa'] = $d['coa'];
            $info['version'] = $d['version'];
        }
    }
    return $info;
}

/**
 * Verfügbare Kontenrahmen: jedes Verzeichnis backend/upstall/<skr>/ mit company_schema.sql
 *
 * @return array [{id, name}]
 */
function tenantAvailableCharts() {
    $base = __DIR__ . '/../../upstall/';
    $charts = [];
    foreach (glob($base . '*/company_schema.sql') as $file) {
        $dir = basename(dirname($file));
        if (!preg_match('/^skr\d+$/', $dir)) {
            continue;
        }
        $charts[] = ['id' => $dir, 'name' => strtoupper($dir)];
    }
    usort($charts, fn($a, $b) => strcmp($a['id'], $b['id']));
    return $charts;
}

/**
 * Spielt das OSERP-Auth-Schema (inkl. kivitendo-Tabellen) in eine Auth-DB ein
 *
 * Idempotent: bestehende Tabellen bleiben, fehlende werden angelegt, Seed-Daten
 * (Rechtekatalog, schema_info) nur in leere Tabellen geladen.
 *
 * @param ApiDatabase $authDb Verbindung zur Auth-Datenbank
 * @return array Ergebnis von updateDatabaseSchema()
 */
function tenantInstallAuthSchema($authDb) {
    require_once __DIR__ . '/../update/update.php';
    $base = __DIR__ . '/../../upstall/crm/';
    $csv = ['auth' => glob($base . 'auth_data/*.csv') ?: [], 'company' => []];
    return updateDatabaseSchema([$base . 'auth_schema.sql'], $csv, false, null, $authDb);
}

/**
 * Setzt alle serial-/identity-Sequenzen eines Schemas auf MAX(id)
 *
 * Nach dem Einspielen eines Dumps mit expliziten IDs Pflicht, sonst liefert der
 * nächste INSERT einen "duplicate key".
 *
 * @param ApiDatabase $db Verbindung
 * @param string $schema Schema
 * @return int Anzahl synchronisierter Sequenzen
 */
function tenantResyncSequences($db, $schema = 'public') {
    $rows = $db->getAll(
        "SELECT c.relname AS tbl, a.attname AS col,
                pg_get_serial_sequence(quote_ident(n.nspname) || '.' || quote_ident(c.relname), a.attname) AS seq
         FROM pg_class c
         JOIN pg_namespace n ON n.oid = c.relnamespace
         JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
         WHERE n.nspname = :schema AND c.relkind = 'r'
           AND pg_get_serial_sequence(quote_ident(n.nspname) || '.' || quote_ident(c.relname), a.attname) IS NOT NULL",
        [':schema' => $schema]
    );
    foreach ($rows as $r) {
        $tbl = pgIdent($schema) . '.' . pgIdent($r['tbl']);
        $col = pgIdent($r['col']);
        $db->execute("SELECT setval(:seq, COALESCE((SELECT MAX($col) FROM $tbl), 0) + 1, false)", [':seq' => $r['seq']]);
    }
    return count($rows);
}

/**
 * Ermittelt den Ausbauzustand einer Auth-Datenbank
 *
 * Grundlage fuer die Entscheidung "Installation fertig oder nicht": ohne Tabellen oder
 * ohne Benutzer ist keine Anmeldung moeglich, dann gehoert der Benutzer in den
 * Einrichtungsassistenten statt auf die Anmeldemaske.
 *
 * @param ApiDatabase $authDb Verbindung zur Auth-Datenbank
 * @return array{has_schema: bool, users: int, clients: int, usable: bool}
 */
function tenantAuthSchemaState($authDb) {
    $row = $authDb->getOne("SELECT to_regclass('auth.user') AS u, to_regclass('auth.clients') AS c", []);
    if (empty($row['u']) || empty($row['c'])) {
        return ['has_schema' => false, 'users' => 0, 'clients' => 0, 'usable' => false];
    }
    $counts = $authDb->getOne(
        'SELECT (SELECT COUNT(*) FROM auth."user") AS users, (SELECT COUNT(*) FROM auth.clients) AS clients', []
    );
    $users = (int)$counts['users'];
    $clients = (int)$counts['clients'];
    return [
        'has_schema' => true,
        'users' => $users,
        'clients' => $clients,
        // Anmelden kann sich nur, wer Benutzer UND Mandant vorfindet
        'usable' => $users > 0 && $clients > 0,
    ];
}

/**
 * Ermittelt, wie weit die Installation gediehen ist
 *
 * Stufen:
 *   fresh        keine settings.ini — klassischer Erstaufruf
 *   no_database  settings.ini vorhanden, die Auth-Datenbank gibt es aber noch nicht
 *   no_schema    Auth-Datenbank vorhanden, aber ohne Tabellen
 *   no_user      Tabellen vorhanden, aber kein Benutzer
 *   no_client    Benutzer vorhanden, aber keine Firma — Anmeldung trotzdem unmoeglich
 *   unreachable  Server antwortet nicht oder die Zugangsdaten stimmen nicht
 *   ready        alles da, normale Anmeldung
 *
 * @return array Zustand mit Verbindungsangaben (ohne Passwort)
 */
function tenantInstallationState(): array {
    $state = [
        'stage' => 'fresh',
        'settings_exists' => setupExists(),
        'host' => null, 'port' => null, 'auth_db' => null, 'auth_user' => null,
        'has_schema' => false, 'users' => 0, 'clients' => 0, 'error' => null,
        'default_admin_login' => OSERP_DEFAULT_ADMIN_LOGIN,
    ];

    if (!$state['settings_exists']) {
        return $state;
    }

    $state['host'] = DB_HOST;
    $state['port'] = (int)DB_PORT;
    $state['auth_db'] = DB_AUTH_NAME;
    $state['auth_user'] = DB_AUTH_USER;

    try {
        $pdo = tenantConnect(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS, 5);
    } catch (PDOException $e) {
        // 3D000 = Datenbank existiert nicht. Das ist ein Einrichtungsfall, kein Fehler:
        // der Assistent darf sie anlegen. Falsche Zugangsdaten dagegen bleiben ein Fehler,
        // sonst stuende der Assistent auf einer gesunden Installation offen.
        $state['error'] = $e->getMessage();
        $state['stage'] = (strpos($e->getMessage(), 'SQLSTATE[3D000]') !== false
            || stripos($e->getMessage(), 'does not exist') !== false) ? 'no_database' : 'unreachable';
        return $state;
    }

    $schema = tenantAuthSchemaState(new ApiDatabase($pdo));
    $state['has_schema'] = $schema['has_schema'];
    $state['users'] = $schema['users'];
    $state['clients'] = $schema['clients'];

    if (!$schema['has_schema']) {
        $state['stage'] = 'no_schema';
    } elseif ($schema['users'] === 0) {
        $state['stage'] = 'no_user';
    } elseif ($schema['clients'] === 0) {
        $state['stage'] = 'no_client';
    } else {
        $state['stage'] = 'ready';
    }
    return $state;
}

/**
 * Ist die Installation unvollstaendig, also der Einrichtungsassistent zustaendig?
 *
 * @return bool
 */
function tenantInstallationIncomplete(): bool {
    return in_array(tenantInstallationState()['stage'],
        ['fresh', 'no_database', 'no_schema', 'no_user', 'no_client'], true);
}

/**
 * Legt den Standardadministrator an, wenn die Auth-Datenbank noch keinen Benutzer hat
 *
 * Damit ist eine frisch eingerichtete Datenbank sofort benutzbar: Anmeldung mit
 * admin/admin. Der Datensatz traegt das Kennzeichen oserp_default_password, solange das
 * Standardpasswort gilt — die Verwaltung weist darauf hin, und sobald ein Passwort
 * gesetzt wird, verschwindet das Kennzeichen (siehe saveUser in admin.php).
 *
 * Ein vorhandener Benutzer wird NIE ueberschrieben: gibt es schon Benutzer, passiert nichts.
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param string $login Anmeldename (Standard: admin)
 * @param string $password Passwort (Standard: admin)
 * @param string $name Anzeigename
 * @return int|null Benutzer-ID oder null, wenn bereits Benutzer vorhanden waren
 */
function tenantEnsureDefaultAdmin($authDb, $login = OSERP_DEFAULT_ADMIN_LOGIN,
                                  $password = OSERP_DEFAULT_ADMIN_PASSWORD, $name = 'Administrator') {
    $vorhanden = $authDb->getOne('SELECT COUNT(*) AS anzahl FROM auth."user"', []);
    if ((int)$vorhanden['anzahl'] > 0) {
        return null;
    }

    $userId = tenantSaveUser($authDb, $login, $password, [
        'name' => $name,
        'countrycode' => 'de',
        'dateformat' => 'dd.mm.yy',
        'numberformat' => '1.000,00',
        'oserp_admin' => '1',
        'oserp_default_password' => '1',
    ]);

    // Vollzugriff, sonst sieht der erste Benutzer nach der Anmeldung nichts
    $groupId = tenantEnsureFullAccessGroup($authDb);
    $authDb->execute(
        'INSERT INTO auth.user_group (user_id, group_id) VALUES (:u, :g) ON CONFLICT DO NOTHING',
        [':u' => $userId, ':g' => $groupId]
    );
    // Bereits vorhandene Mandanten gleich mit zuordnen — Benutzer wie Gruppe
    $authDb->execute(
        'INSERT INTO auth.clients_users (client_id, user_id) SELECT id, :u FROM auth.clients ON CONFLICT DO NOTHING',
        [':u' => $userId]
    );
    $authDb->execute(
        'INSERT INTO auth.clients_groups (client_id, group_id) SELECT id, :g FROM auth.clients ON CONFLICT DO NOTHING',
        [':g' => $groupId]
    );

    writeLog("Standardadministrator '$login' in der Auth-Datenbank angelegt", true, DLOG_INF);
    return $userId;
}

/**
 * Liefert den Rechtekatalog (ohne Kategorien-Überschriften)
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @return array Liste der Rechtenamen
 */
function tenantAllRights($authDb) {
    $rows = $authDb->getAll("SELECT name FROM auth.master_rights WHERE NOT category ORDER BY position", []);
    return array_column($rows, 'name');
}

/**
 * Setzt die Rechte einer Gruppe komplett neu (kivitendo speichert jedes Recht mit granted true/false)
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param int $groupId Gruppen-ID
 * @param array $granted Liste der erteilten Rechte
 */
function tenantSetGroupRights($authDb, $groupId, array $granted) {
    $granted = array_fill_keys($granted, true);
    $authDb->execute('DELETE FROM auth.group_rights WHERE group_id = :id', [':id' => $groupId]);
    foreach (tenantAllRights($authDb) as $right) {
        $authDb->execute(
            'INSERT INTO auth.group_rights (group_id, "right", granted) VALUES (:g, :r, :granted)',
            [':g' => $groupId, ':r' => $right, ':granted' => isset($granted[$right])]
        );
    }
}

/**
 * Sorgt für die Gruppe "Vollzugriff" (alle Rechte) — wie kivitendo beim Anlegen der Auth-DB
 *
 * Existiert bereits irgendeine Gruppe, wird nichts angelegt (Rückgabe: ID der
 * ersten Gruppe mit den meisten Rechten).
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param string $name Gruppenname
 * @param string $description Beschreibung
 * @return int Gruppen-ID
 */
function tenantEnsureFullAccessGroup($authDb, $name = 'Vollzugriff', $description = 'Vollzugriff auf alle Funktionen') {
    $existing = $authDb->getOne(
        'SELECT g.id
         FROM auth."group" g
         LEFT JOIN auth.group_rights gr ON gr.group_id = g.id AND gr.granted
         GROUP BY g.id
         ORDER BY COUNT(gr.*) DESC, g.id ASC
         LIMIT 1', []
    );
    if ($existing) {
        return (int)$existing['id'];
    }
    $row = $authDb->getOne(
        'INSERT INTO auth."group" (name, description) VALUES (:name, :description) RETURNING id',
        [':name' => $name, ':description' => $description]
    );
    $groupId = (int)$row['id'];
    tenantSetGroupRights($authDb, $groupId, tenantAllRights($authDb));
    return $groupId;
}

/**
 * Legt einen Benutzer an oder aktualisiert ihn
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param string $login Anmeldename
 * @param string|null $password Klartext-Passwort (null = unverändert lassen)
 * @param array $config Stammdaten für auth.user_config (name, email, ...); null-Werte löschen den Schlüssel
 * @param int|null $userId Vorhandene ID (Update) oder null (Neuanlage)
 * @return int Benutzer-ID
 */
function tenantSaveUser($authDb, $login, $password, array $config, $userId = null) {
    if ($userId === null) {
        $row = $authDb->getOne(
            'INSERT INTO auth."user" (login, password) VALUES (:login, :password) RETURNING id',
            [':login' => $login, ':password' => $password !== null ? generate_password_hash($login, $password) : null]
        );
        $userId = (int)$row['id'];
    } else {
        $authDb->execute('UPDATE auth."user" SET login = :login WHERE id = :id', [':login' => $login, ':id' => $userId]);
        if ($password !== null && $password !== '') {
            $authDb->execute(
                'UPDATE auth."user" SET password = :password WHERE id = :id',
                [':password' => generate_password_hash($login, $password), ':id' => $userId]
            );
        }
    }
    tenantSetUserConfig($authDb, $userId, $config);
    return $userId;
}

/**
 * Schreibt Schlüssel/Wert-Paare nach auth.user_config (null löscht den Schlüssel)
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param int $userId Benutzer-ID
 * @param array $config Schlüssel => Wert
 */
function tenantSetUserConfig($authDb, $userId, array $config) {
    foreach ($config as $key => $value) {
        if ($value === null) {
            $authDb->execute('DELETE FROM auth.user_config WHERE user_id = :u AND cfg_key = :k', [':u' => $userId, ':k' => $key]);
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        $authDb->execute(
            'INSERT INTO auth.user_config (user_id, cfg_key, cfg_value) VALUES (:u, :k, :v)
             ON CONFLICT (user_id, cfg_key) DO UPDATE SET cfg_value = EXCLUDED.cfg_value',
            [':u' => $userId, ':k' => $key, ':v' => (string)$value]
        );
    }
}

/**
 * Setzt Zuordnungen einer Verknüpfungstabelle komplett neu
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param string $table z. B. auth.clients_users
 * @param string $keyColumn Spalte des festen Schlüssels (z. B. user_id)
 * @param int $keyValue Wert des festen Schlüssels
 * @param string $valueColumn Spalte der Zuordnung (z. B. client_id)
 * @param array $values Liste der IDs
 */
function tenantSetLinks($authDb, $table, $keyColumn, $keyValue, $valueColumn, array $values) {
    // Nur existierende Ziele verknüpfen — eine veraltete ID aus der Oberfläche (z. B. gerade
    // gelöschter Benutzer) soll nicht die ganze Speicherung mit einem FK-Fehler abbrechen.
    $refTables = ['user_id' => 'auth."user"', 'group_id' => 'auth."group"', 'client_id' => 'auth.clients'];
    $refTable = $refTables[$valueColumn] ?? null;

    $authDb->execute("DELETE FROM $table WHERE $keyColumn = :k", [':k' => $keyValue]);
    foreach (array_unique(array_map('intval', $values)) as $v) {
        $sql = $refTable
            ? "INSERT INTO $table ($keyColumn, $valueColumn) SELECT :k, :v WHERE EXISTS (SELECT 1 FROM $refTable WHERE id = :v) ON CONFLICT DO NOTHING"
            : "INSERT INTO $table ($keyColumn, $valueColumn) VALUES (:k, :v) ON CONFLICT DO NOTHING";
        $authDb->execute($sql, [':k' => $keyValue, ':v' => $v]);
    }
}

/**
 * Verbindung zur Firmen-DB eines Mandanten (null, wenn nicht erreichbar)
 *
 * @param array $client Zeile aus auth.clients
 * @return PDO|null
 */
function tenantClientPdo(array $client) {
    try {
        return tenantConnect($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd'], 3);
    } catch (PDOException $e) {
        writeLog("Mandant '{$client['name']}' ({$client['dbname']}) nicht erreichbar: " . $e->getMessage(), true, DLOG_WRN);
        return null;
    }
}

/**
 * Gleicht die Mitarbeiter-Tabelle eines Mandanten mit seinen Benutzern ab
 *
 * Wie kivitendo (SL::DB::Manager::Employee->update_entries_for_authorized_users):
 * jeder zugeordnete Benutzer bekommt einen employee-Datensatz (login, name),
 * bestehende werden reaktiviert (deleted = false) und im Namen aktualisiert.
 * Nicht mehr zugeordnete Benutzer werden NICHT gelöscht — Belege zeigen auf sie.
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param int $clientId Mandanten-ID
 * @return array Warnungen (leer = alles gut)
 */
function tenantSyncClientEmployees($authDb, $clientId) {
    $client = $authDb->getOne('SELECT * FROM auth.clients WHERE id = :id', [':id' => $clientId]);
    if (!$client) {
        return ["Mandant $clientId nicht gefunden"];
    }
    $pdo = tenantClientPdo($client);
    if (!$pdo) {
        return ["Firmen-Datenbank '{$client['dbname']}' nicht erreichbar — Mitarbeiter wurden nicht abgeglichen"];
    }
    $users = $authDb->getAll(
        'SELECT u.id, u.login, (SELECT cfg_value FROM auth.user_config c WHERE c.user_id = u.id AND c.cfg_key = \'name\') AS name
         FROM auth."user" u
         JOIN auth.clients_users cu ON cu.user_id = u.id
         WHERE cu.client_id = :id',
        [':id' => $clientId]
    );
    $hasEmployee = $pdo->query("SELECT to_regclass('public.employee') AS t")->fetchColumn();
    if (!$hasEmployee) {
        return ["Datenbank '{$client['dbname']}' hat keine Tabelle employee (keine kivitendo-/OSERP-Firmen-DB)"];
    }
    $upd = $pdo->prepare('UPDATE employee SET name = :name, deleted = false, mtime = now() WHERE login = :login');
    $ins = $pdo->prepare('INSERT INTO employee (login, name, sales, deleted) VALUES (:login, :name, true, false)');
    foreach ($users as $u) {
        $name = $u['name'] !== null && $u['name'] !== '' ? $u['name'] : $u['login'];
        $upd->execute([':name' => $name, ':login' => $u['login']]);
        if ($upd->rowCount() === 0) {
            $ins->execute([':login' => $u['login'], ':name' => $name]);
        }
    }
    return [];
}

/**
 * Gleicht die Mitarbeiter in allen Mandanten eines Benutzers ab
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param int $userId Benutzer-ID
 * @return array Warnungen
 */
function tenantSyncUserEmployees($authDb, $userId) {
    $warnings = [];
    $clients = $authDb->getAll('SELECT client_id FROM auth.clients_users WHERE user_id = :u', [':u' => $userId]);
    foreach ($clients as $c) {
        $warnings = array_merge($warnings, tenantSyncClientEmployees($authDb, (int)$c['client_id']));
    }
    return $warnings;
}

/**
 * Stellt beim Login sicher, dass der Benutzer im aktuellen Mandanten als Mitarbeiter existiert
 *
 * Leichtgewichtige Variante von tenantSyncClientEmployees() für genau einen
 * Benutzer auf der bereits offenen Firmen-Verbindung.
 *
 * @param ApiDatabase $companyDb Firmen-Verbindung
 * @param string $login Anmeldename
 * @param string|null $name Anzeigename
 * @return int|null employee.id
 */
function tenantEnsureEmployee($companyDb, $login, $name = null) {
    $name = $name !== null && $name !== '' ? $name : $login;
    $row = $companyDb->getOne(
        'INSERT INTO employee (login, name, sales, deleted)
         SELECT :login, :name, true, false
         WHERE NOT EXISTS (SELECT 1 FROM employee WHERE login = :login)
         RETURNING id',
        [':login' => $login, ':name' => $name]
    );
    if ($row) {
        return (int)$row['id'];
    }
    $row = $companyDb->getOne('SELECT id FROM employee WHERE login = :login', [':login' => $login]);
    return $row ? (int)$row['id'] : null;
}

/**
 * Markiert die Mitarbeiter-Datensätze eines Benutzers in allen Mandanten als gelöscht
 *
 * kivitendo-Verhalten (SL::Auth::delete_user): Stammdaten werden in die
 * deleted_*-Spalten gerettet, damit alte Belege den Namen behalten.
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param int $userId Benutzer-ID
 * @return array Warnungen
 */
function tenantMarkEmployeesDeleted($authDb, $userId) {
    $user = $authDb->getOne('SELECT login FROM auth."user" WHERE id = :id', [':id' => $userId]);
    if (!$user) {
        return [];
    }
    $cfg = [];
    foreach ($authDb->getAll('SELECT cfg_key, cfg_value FROM auth.user_config WHERE user_id = :id', [':id' => $userId]) as $r) {
        $cfg[$r['cfg_key']] = $r['cfg_value'];
    }
    $warnings = [];
    $clients = $authDb->getAll(
        'SELECT c.* FROM auth.clients c JOIN auth.clients_users cu ON cu.client_id = c.id WHERE cu.user_id = :u',
        [':u' => $userId]
    );
    foreach ($clients as $client) {
        $pdo = tenantClientPdo($client);
        if (!$pdo) {
            $warnings[] = "Firmen-Datenbank '{$client['dbname']}' nicht erreichbar";
            continue;
        }
        $stmt = $pdo->prepare(
            'UPDATE employee SET deleted = true, name = :name, deleted_email = :email, deleted_tel = :tel,
                    deleted_fax = :fax, deleted_signature = :signature WHERE login = :login'
        );
        $stmt->execute([
            ':name' => $cfg['name'] ?? $user['login'],
            ':email' => $cfg['email'] ?? null,
            ':tel' => $cfg['tel'] ?? null,
            ':fax' => $cfg['fax'] ?? null,
            ':signature' => $cfg['signature'] ?? null,
            ':login' => $user['login'],
        ]);
    }
    return $warnings;
}

/**
 * Registriert einen Mandanten in auth.clients (oder aktualisiert ihn) inkl. Zuordnungen
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param array $client name, dbhost, dbport, dbname, dbuser, dbpasswd, is_default (bool)
 * @param array|null $userIds Benutzer-IDs (null = unverändert)
 * @param array|null $groupIds Gruppen-IDs (null = unverändert)
 * @param int|null $clientId Vorhandene ID (Update) oder null
 * @return int Mandanten-ID
 */
function tenantSaveClient($authDb, array $client, $userIds = null, $groupIds = null, $clientId = null) {
    $params = [
        ':name' => $client['name'],
        ':dbhost' => $client['dbhost'],
        ':dbport' => (int)$client['dbport'],
        ':dbname' => $client['dbname'],
        ':dbuser' => $client['dbuser'],
        ':is_default' => !empty($client['is_default']),
    ];
    if ($clientId === null) {
        $params[':dbpasswd'] = (string)($client['dbpasswd'] ?? '');
        $row = $authDb->getOne(
            'INSERT INTO auth.clients (name, dbhost, dbport, dbname, dbuser, dbpasswd, is_default)
             VALUES (:name, :dbhost, :dbport, :dbname, :dbuser, :dbpasswd, :is_default) RETURNING id',
            $params
        );
        $clientId = (int)$row['id'];
    } else {
        $params[':id'] = $clientId;
        $sql = 'UPDATE auth.clients SET name = :name, dbhost = :dbhost, dbport = :dbport, dbname = :dbname,
                dbuser = :dbuser, is_default = :is_default';
        // Leeres Passwort = unverändert lassen (das Feld ist in der Maske nie vorbelegt)
        if (isset($client['dbpasswd']) && $client['dbpasswd'] !== '') {
            $sql .= ', dbpasswd = :dbpasswd';
            $params[':dbpasswd'] = (string)$client['dbpasswd'];
        }
        $authDb->execute($sql . ' WHERE id = :id', $params);
    }
    // Nur ein Standard-Mandant
    if (!empty($client['is_default'])) {
        $authDb->execute('UPDATE auth.clients SET is_default = false WHERE id <> :id', [':id' => $clientId]);
    }
    if ($userIds !== null) {
        tenantSetLinks($authDb, 'auth.clients_users', 'client_id', $clientId, 'user_id', $userIds);
    }
    if ($groupIds !== null) {
        tenantSetLinks($authDb, 'auth.clients_groups', 'client_id', $clientId, 'group_id', $groupIds);
    }
    return $clientId;
}

/**
 * Legt eine neue Firmen-Datenbank an und registriert sie als Mandant
 *
 * Ablauf:
 *   1. CREATE DATABASE (über die Wartungs-DB, Owner = dbuser)
 *   2. Kontenrahmen-Dump einspielen (backend/upstall/<skr>/company_schema.sql)
 *   3. OSERP-Erweiterungen per Update-Mechanismus (crm/company_schema.sql + CSV)
 *   4. Vollständigen DATEV-Kontenrahmen ergänzen
 *   5. Firmenname in defaults, Sequenzen synchronisieren
 *   6. Mandant registrieren, Benutzer/Gruppen zuordnen, Mitarbeiter anlegen
 *
 * Schlägt ein Schritt nach dem CREATE DATABASE fehl, wird die Datenbank wieder
 * gelöscht — es bleibt kein halber Mandant zurück.
 *
 * @param ApiDatabase $authDb Auth-Verbindung (dort wird der Mandant registriert)
 * @param array $p Parameter:
 *   companyName  Firmenname (Pflicht)
 *   dbname       Datenbankname (Pflicht, isValidDbName)
 *   skr          Kontenrahmen-Verzeichnis, z. B. skr03 (Pflicht)
 *   dbhost, dbport, dbuser, dbpasswd  Zugang, mit dem OSERP die Firmen-DB nutzt (Pflicht)
 *   adminUser, adminPass  Rolle mit CREATEDB (optional, Standard: dbuser/dbpasswd)
 *   userIds[]    Benutzer, die Zugang bekommen
 *   groupIds[]   Gruppen, die im Mandanten gelten
 *   isDefault    Als Standard-Mandant setzen
 * @return array {client_id, dbname, warnings[]}
 * @throws Exception mit sprechender Meldung
 */
function tenantCreateCompany($authDb, array $p) {
    $companyName = trim($p['companyName'] ?? '');
    $dbname = trim($p['dbname'] ?? '');
    $skr = trim($p['skr'] ?? '');
    $warnings = [];

    if ($companyName === '') {
        throw new Exception('Firmenname fehlt');
    }
    if (!isValidDbName($dbname)) {
        throw new Exception('Datenbankname ungültig: nur Kleinbuchstaben, Ziffern, Unterstrich und Bindestrich, muss mit einem Buchstaben beginnen');
    }
    $skrFile = __DIR__ . '/../../upstall/' . $skr . '/company_schema.sql';
    if (!preg_match('/^skr\d+$/', $skr) || !file_exists($skrFile)) {
        throw new Exception("Kontenrahmen '$skr' nicht gefunden");
    }
    if ($authDb->getOne('SELECT 1 FROM auth.clients WHERE name = :n', [':n' => $companyName])) {
        throw new Exception("Firmenname '$companyName' ist bereits vergeben");
    }

    $dbhost = $p['dbhost'];
    $dbport = (int)$p['dbport'];
    $dbuser = $p['dbuser'];
    $dbpasswd = (string)$p['dbpasswd'];
    $adminUser = $p['adminUser'] ?? $dbuser;
    $adminPass = $p['adminPass'] ?? $dbpasswd;

    if ($authDb->getOne(
        'SELECT 1 FROM auth.clients WHERE dbhost = :h AND dbport = :p AND dbname = :d',
        [':h' => $dbhost, ':p' => $dbport, ':d' => $dbname]
    )) {
        throw new Exception("Datenbank '$dbname' ist bereits als Mandant registriert");
    }

    // 1. Datenbank anlegen
    $adminPdo = tenantAdminConnect($dbhost, $dbport, $adminUser, $adminPass);
    if (tenantDatabaseExists($adminPdo, $dbname)) {
        throw new Exception("Datenbank '$dbname' existiert bereits auf dem Server");
    }
    // Encoding wie kivitendo (UNICODE), Template template0 damit die Locale des Clusters gilt
    $adminPdo->exec('CREATE DATABASE ' . pgIdent($dbname) . ' OWNER ' . pgIdent($dbuser) . " ENCODING 'UTF8' TEMPLATE template0");
    writeLog("Datenbank '$dbname' angelegt", true, DLOG_INF);

    try {
        // 2. Kontenrahmen-Dump
        $pdo = tenantConnect($dbhost, $dbport, $dbname, $dbuser, $dbpasswd, 10);
        $pdo->exec(file_get_contents($skrFile));
        $newDb = new ApiDatabase($pdo);
        writeLog("Kontenrahmen '$skr' in '$dbname' eingespielt", true, DLOG_INF);

        // 3. OSERP-Erweiterungen (crm) inkrementell
        require_once __DIR__ . '/../update/update.php';
        $crmDir = __DIR__ . '/../../upstall/crm/';
        $csv = ['auth' => [], 'company' => glob($crmDir . 'company_data/*.csv') ?: []];
        $res = updateDatabaseSchema([$crmDir . 'company_schema.sql'], $csv, false, $newDb);
        if (!$res['success']) {
            $warnings[] = 'OSERP-Schema mit Warnungen: ' . implode('; ', $res['errors']);
        }

        // 4. Vollständiger DATEV-Kontenrahmen
        require_once __DIR__ . '/../accounting/chart_import.php';
        try {
            importChartMaster($newDb, $skr, ['mode' => 'fix', 'dry_run' => false]);
        } catch (\Throwable $e) {
            $warnings[] = 'Kontenrahmen konnte nicht vervollständigt werden: ' . $e->getMessage();
        }

        // 5. Firmenname + Sequenzen
        $newDb->execute('UPDATE defaults SET company = :name', [':name' => $companyName]);
        tenantResyncSequences($newDb, 'public');
    } catch (\Throwable $e) {
        $pdo = null;
        $newDb = null;
        try {
            tenantDropDatabase($adminPdo, $dbname);
        } catch (\Throwable $dropEx) {
            writeLog("Aufräumen von '$dbname' fehlgeschlagen: " . $dropEx->getMessage(), true, DLOG_ERR);
        }
        throw new Exception('Firmen-Datenbank konnte nicht eingerichtet werden: ' . $e->getMessage());
    }

    // 6. Registrieren + Zuordnungen
    $clientId = tenantSaveClient($authDb, [
        'name' => $companyName, 'dbhost' => $dbhost, 'dbport' => $dbport, 'dbname' => $dbname,
        'dbuser' => $dbuser, 'dbpasswd' => $dbpasswd, 'is_default' => !empty($p['isDefault']),
    ], $p['userIds'] ?? [], $p['groupIds'] ?? []);

    $warnings = array_merge($warnings, tenantSyncClientEmployees($authDb, $clientId));
    writeLog("Mandant '$companyName' (ID $clientId, DB $dbname) registriert", true, DLOG_INF);

    return ['client_id' => $clientId, 'dbname' => $dbname, 'warnings' => $warnings];
}

/**
 * Löscht eine Datenbank vom Server (trennt vorher alle Verbindungen)
 *
 * @param PDO $adminPdo Verbindung zur Wartungs-DB
 * @param string $dbname Datenbankname
 */
function tenantDropDatabase(PDO $adminPdo, $dbname) {
    $stmt = $adminPdo->prepare(
        'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :name AND pid <> pg_backend_pid()'
    );
    $stmt->execute([':name' => $dbname]);
    $adminPdo->exec('DROP DATABASE IF EXISTS ' . pgIdent($dbname));
    writeLog("Datenbank '$dbname' gelöscht", true, DLOG_WRN);
}

/**
 * Prüft, ob der Benutzer Systemadministrator ist
 *
 * Reihenfolge:
 *   1. Explizit: auth.user_config oserp_admin = 1 (wird in der Benutzerverwaltung gesetzt)
 *   2. settings.ini [company] admin_users (Altbestand, weiterhin gültig)
 *   3. Übergang für bestehende kivitendo-Installationen: solange NIEMAND explizit
 *      Administrator ist, zählt das kivitendo-Recht "admin" in irgendeiner Gruppe.
 *
 * @param ApiDatabase $authDb Auth-Verbindung
 * @param int $userId Benutzer-ID
 * @param string $login Anmeldename
 * @return array ['is_admin' => bool, 'source' => 'explicit'|'settings'|'legacy_right'|null, 'legacy_mode' => bool]
 */
function tenantAdminStatus($authDb, $userId, $login) {
    $row = $authDb->getOne(
        'SELECT
            EXISTS (SELECT 1 FROM auth.user_config WHERE user_id = :uid AND cfg_key = \'oserp_admin\' AND cfg_value IN (\'1\', \'true\', \'t\')) AS explicit_admin,
            EXISTS (SELECT 1 FROM auth.user_config WHERE cfg_key = \'oserp_admin\' AND cfg_value IN (\'1\', \'true\', \'t\')) AS any_explicit,
            EXISTS (SELECT 1 FROM auth.user_group ug JOIN auth.group_rights gr ON gr.group_id = ug.group_id
                    WHERE ug.user_id = :uid AND gr."right" = \'admin\' AND gr.granted) AS legacy_right',
        [':uid' => $userId]
    );
    $t = fn($v) => in_array($v, [true, 't', '1', 1], true);

    $settingsAdmins = defined('COMPANY_ADMIN_USERS') && COMPANY_ADMIN_USERS !== ''
        ? array_filter(array_map('trim', explode(',', COMPANY_ADMIN_USERS)))
        : [];
    $legacyMode = !$t($row['any_explicit']) && empty($settingsAdmins);

    if ($t($row['explicit_admin'])) {
        return ['is_admin' => true, 'source' => 'explicit', 'legacy_mode' => $legacyMode];
    }
    if (in_array($login, $settingsAdmins, true)) {
        return ['is_admin' => true, 'source' => 'settings', 'legacy_mode' => $legacyMode];
    }
    if ($legacyMode && $t($row['legacy_right'])) {
        return ['is_admin' => true, 'source' => 'legacy_right', 'legacy_mode' => true];
    }
    return ['is_admin' => false, 'source' => null, 'legacy_mode' => $legacyMode];
}
