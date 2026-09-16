<?php
// backend/api/admin/admin.php

/**
 * Systemadministration: Benutzer, Gruppen, Firmen (Mandanten), Datenbanken
 *
 * Ersetzt kivitendos admin.pl. Alle Aktionen verlangen einen Systemadministrator
 * (requireSystemAdmin() in auth.php). Datenmodell und Semantik sind kivitendo-
 * kompatibel — Hilfsfunktionen in backend/api/lib/tenant.php.
 *
 * Passwörter der Firmen-Datenbanken (auth.clients.dbpasswd) verlassen den Server nie.
 */

/**
 * Wandelt Booleans aus JSON/Formularen robust um
 *
 * @param mixed $v Wert
 * @return bool
 */
function adminBool($v) {
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 't';
}

/**
 * Liefert eine Liste von Ganzzahlen aus einem Request-Feld
 *
 * @param mixed $v Wert (Array oder nichts)
 * @return array
 */
function adminIntList($v) {
    if (!is_array($v)) {
        return [];
    }
    return array_values(array_unique(array_map('intval', $v)));
}

/**
 * Lädt die komplette Verwaltungsübersicht in einer Abfrage
 *
 * Benutzer (mit Stammdaten, Gruppen, Firmen), Gruppen (mit Rechten, Mitgliedern,
 * Firmen), Firmen (mit Zugangsdaten ohne Passwort, Benutzern, Gruppen), Rechtekatalog,
 * Kontenrahmen und der Administrator-Status des Aufrufers.
 *
 * @testdata {}
 */
function getAdminOverview($data) {
    $auth = requireSystemAdmin();

    $query = <<<SQL
        SELECT json_build_object(
            'users', COALESCE((
                SELECT json_agg(u ORDER BY lower(u.login))
                FROM (
                    SELECT
                        us.id,
                        us.login,
                        (us.password IS NOT NULL AND us.password <> '') AS has_password,
                        COALESCE((SELECT json_object_agg(c.cfg_key, c.cfg_value)
                                  FROM auth.user_config c WHERE c.user_id = us.id), '{}'::json) AS config,
                        EXISTS (SELECT 1 FROM auth.user_config c WHERE c.user_id = us.id
                                AND c.cfg_key = 'oserp_admin' AND c.cfg_value IN ('1', 'true', 't')) AS is_admin,
                        EXISTS (SELECT 1 FROM auth.user_config c WHERE c.user_id = us.id
                                AND c.cfg_key = 'oserp_default_password' AND c.cfg_value IN ('1', 'true', 't')) AS has_default_password,
                        COALESCE((SELECT json_agg(ug.group_id ORDER BY ug.group_id)
                                  FROM auth.user_group ug WHERE ug.user_id = us.id), '[]'::json) AS group_ids,
                        COALESCE((SELECT json_agg(cu.client_id ORDER BY cu.client_id)
                                  FROM auth.clients_users cu WHERE cu.user_id = us.id), '[]'::json) AS client_ids,
                        (SELECT MAX(s.active) FROM auth.session_oserp s WHERE s.user_id = us.id) AS last_active
                    FROM auth."user" us
                ) u
            ), '[]'::json),
            'groups', COALESCE((
                SELECT json_agg(g ORDER BY lower(g.name))
                FROM (
                    SELECT
                        gr.id,
                        gr.name,
                        gr.description,
                        COALESCE((SELECT json_agg(r."right" ORDER BY r."right")
                                  FROM auth.group_rights r WHERE r.group_id = gr.id AND r.granted), '[]'::json) AS rights,
                        COALESCE((SELECT json_agg(ug.user_id ORDER BY ug.user_id)
                                  FROM auth.user_group ug WHERE ug.group_id = gr.id), '[]'::json) AS user_ids,
                        COALESCE((SELECT json_agg(cg.client_id ORDER BY cg.client_id)
                                  FROM auth.clients_groups cg WHERE cg.group_id = gr.id), '[]'::json) AS client_ids
                    FROM auth."group" gr
                ) g
            ), '[]'::json),
            'clients', COALESCE((
                SELECT json_agg(c ORDER BY lower(c.name))
                FROM (
                    SELECT
                        cl.id,
                        cl.name,
                        cl.dbhost,
                        cl.dbport,
                        cl.dbname,
                        cl.dbuser,
                        cl.is_default,
                        cl.task_server_user_id,
                        COALESCE((SELECT json_agg(cu.user_id ORDER BY cu.user_id)
                                  FROM auth.clients_users cu WHERE cu.client_id = cl.id), '[]'::json) AS user_ids,
                        COALESCE((SELECT json_agg(cg.group_id ORDER BY cg.group_id)
                                  FROM auth.clients_groups cg WHERE cg.client_id = cl.id), '[]'::json) AS group_ids,
                        (SELECT COUNT(*) FROM auth.session_oserp s WHERE s.client_id = cl.id
                            AND s.active > NOW() - INTERVAL '15 minutes') AS active_sessions
                    FROM auth.clients cl
                ) c
            ), '[]'::json),
            'master_rights', COALESCE((
                SELECT json_agg(json_build_object(
                    'position', mr.position, 'name', mr.name,
                    'description', mr.description, 'category', mr.category
                ) ORDER BY mr.position)
                FROM auth.master_rights mr
            ), '[]'::json)
        ) AS overview
    SQL;

    $row = $auth->getOne($query, []);
    $overview = json_decode($row['overview'], true);

    $status = tenantAdminStatus($auth, (int)$auth->getUserId(), $auth->getLogin());
    $settingsAdmins = defined('COMPANY_ADMIN_USERS') && COMPANY_ADMIN_USERS !== ''
        ? array_values(array_filter(array_map('trim', explode(',', COMPANY_ADMIN_USERS))))
        : [];

    $overview['admin'] = [
        'user_id' => (int)$auth->getUserId(),
        'login' => $auth->getLogin(),
        'client_id' => (int)$auth->getClientId(),
        'source' => $status['source'],
        'legacy_mode' => $status['legacy_mode'],
        'settings_admins' => $settingsAdmins,
    ];
    $overview['charts'] = tenantAvailableCharts();
    $overview['auth_db'] = [
        'host' => DB_HOST,
        'port' => (int)DB_PORT,
        'name' => DB_AUTH_NAME,
        'user' => DB_AUTH_USER,
    ];

    resultInfo(true, '', $overview);
}

/**
 * Legt einen Benutzer an oder speichert ihn
 *
 * Stammdaten landen kivitendo-kompatibel in auth.user_config (name, email, tel, fax,
 * signature, countrycode, dateformat, numberformat, ...). Der Administrator-Status
 * steht als oserp_admin ebenfalls dort (kivitendo ignoriert den Schlüssel).
 * Nach dem Speichern werden die Mitarbeiter-Datensätze aller zugeordneten Firmen
 * abgeglichen.
 *
 * @param int $data['id'] Benutzer-ID (leer = neu)
 * @param string $data['login'] Anmeldename
 * @param string $data['password'] Neues Passwort (leer = unverändert; bei Neuanlage Pflicht)
 * @param string $data['name'] Anzeigename
 * @param string $data['email'] E-Mail
 * @param string $data['tel'] Telefon
 * @param string $data['fax'] Fax
 * @param string $data['signature'] E-Mail-Signatur
 * @param string $data['countrycode'] Sprache (de, en, ...)
 * @param string $data['dateformat'] Datumsformat (dd.mm.yy, ...)
 * @param string $data['numberformat'] Zahlenformat (1.000,00, ...)
 * @param bool $data['is_admin'] Systemadministrator
 * @param array $data['group_ids'] Gruppen-IDs
 * @param array $data['client_ids'] Firmen-IDs
 * @testdata {"login": "max", "password": "geheim123", "name": "Max Mustermann", "email": "max@example.org", "is_admin": false, "group_ids": [1], "client_ids": [1]}
 */
function saveUser($data) {
    $auth = requireSystemAdmin();

    $id = !empty($data['id']) ? (int)$data['id'] : null;
    $login = trim((string)($data['login'] ?? ''));
    $password = isset($data['password']) ? (string)$data['password'] : '';

    if ($login === '' || !preg_match('/^[^\s\/\\\\:*?"<>|]{1,64}$/u', $login)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Anmeldename fehlt oder enthält unzulässige Zeichen');
        return;
    }
    if ($id === null && strlen($password) < 4) {
        resultInfo(false, 'VALIDATION_ERROR', 'Für neue Benutzer ist ein Passwort (mindestens 4 Zeichen) erforderlich');
        return;
    }
    $dupe = $auth->getOne(
        'SELECT id FROM auth."user" WHERE lower(login) = lower(:login) AND id IS DISTINCT FROM :id',
        [':login' => $login, ':id' => $id]
    );
    if ($dupe) {
        resultInfo(false, 'LOGIN_EXISTS', "Anmeldename '$login' ist bereits vergeben");
        return;
    }

    $oldLogin = null;
    if ($id !== null) {
        $existing = $auth->getOne('SELECT login FROM auth."user" WHERE id = :id', [':id' => $id]);
        if (!$existing) {
            resultInfo(false, 'NOT_FOUND', 'Benutzer nicht gefunden');
            return;
        }
        $oldLogin = $existing['login'];
    }

    $isSelf = $id !== null && $id === (int)$auth->getUserId();
    $isAdmin = adminBool($data['is_admin'] ?? false);
    if ($isSelf && !$isAdmin) {
        resultInfo(false, 'CANNOT_DEMOTE_SELF', 'Sie können sich nicht selbst die Administratorrechte entziehen');
        return;
    }

    // Nur bekannte Stammdaten-Schlüssel übernehmen (kivitendo CONFIG_VARS + OSERP-Kennzeichen)
    $configKeys = ['name', 'email', 'tel', 'fax', 'signature', 'countrycode', 'dateformat', 'numberformat', 'timeformat'];
    $config = [];
    foreach ($configKeys as $key) {
        if (array_key_exists($key, $data)) {
            $value = $data[$key];
            $config[$key] = ($value === null || $value === '') ? null : (string)$value;
        }
    }
    $config['oserp_admin'] = $isAdmin ? '1' : null;

    // Wer ein Passwort setzt, benutzt nicht mehr das Standardpasswort — Kennzeichen weg,
    // damit der Hinweis in der Uebersicht verschwindet.
    if ($password !== '') {
        $config['oserp_default_password'] = null;
    }

    // Sinnvolle Vorgaben für neue Benutzer (kivitendo verlangt dateformat/numberformat)
    if ($id === null) {
        $config += ['countrycode' => 'de', 'dateformat' => 'dd.mm.yy', 'numberformat' => '1.000,00'];
        foreach (['countrycode', 'dateformat', 'numberformat'] as $key) {
            if ($config[$key] === null) {
                $config[$key] = ['countrycode' => 'de', 'dateformat' => 'dd.mm.yy', 'numberformat' => '1.000,00'][$key];
            }
        }
    }

    $auth->beginTransaction();
    try {
        $userId = tenantSaveUser($auth, $login, $password !== '' ? $password : null, $config, $id);
        if (array_key_exists('group_ids', $data)) {
            tenantSetLinks($auth, 'auth.user_group', 'user_id', $userId, 'group_id', adminIntList($data['group_ids']));
        }
        if (array_key_exists('client_ids', $data)) {
            tenantSetLinks($auth, 'auth.clients_users', 'user_id', $userId, 'client_id', adminIntList($data['client_ids']));
        }
        $auth->commit();
    } catch (\Throwable $e) {
        $auth->rollBack();
        throw $e;
    }

    // Mitarbeiter in den Firmen-DBs nachziehen (Login-Umbenennung zuerst, sonst entsteht ein zweiter Mitarbeiter)
    $warnings = [];
    if ($oldLogin !== null && $oldLogin !== $login) {
        $warnings = array_merge($warnings, adminRenameEmployeeLogin($auth, $userId, $oldLogin, $login));
    }
    $warnings = array_merge($warnings, tenantSyncUserEmployees($auth, $userId));

    resultInfo(true, $id === null ? 'USER_CREATED' : 'USER_SAVED', ['id' => $userId, 'warnings' => $warnings]);
}

/**
 * Benennt den Mitarbeiter-Login in allen Firmen des Benutzers um
 *
 * @param ApiSession $auth Auth-Verbindung
 * @param int $userId Benutzer-ID
 * @param string $oldLogin Alter Login
 * @param string $newLogin Neuer Login
 * @return array Warnungen
 */
function adminRenameEmployeeLogin($auth, $userId, $oldLogin, $newLogin) {
    $warnings = [];
    $clients = $auth->getAll(
        'SELECT c.* FROM auth.clients c JOIN auth.clients_users cu ON cu.client_id = c.id WHERE cu.user_id = :u',
        [':u' => $userId]
    );
    foreach ($clients as $client) {
        $pdo = tenantClientPdo($client);
        if (!$pdo) {
            $warnings[] = "Firmen-Datenbank '{$client['dbname']}' nicht erreichbar";
            continue;
        }
        $stmt = $pdo->prepare('UPDATE employee SET login = :new WHERE login = :old AND NOT EXISTS (SELECT 1 FROM employee WHERE login = :new)');
        $stmt->execute([':new' => $newLogin, ':old' => $oldLogin]);
    }
    return $warnings;
}

/**
 * Löscht einen Benutzer
 *
 * Wie kivitendo: Mitarbeiter-Datensätze in den Firmen bleiben als "gelöscht"
 * erhalten (Belege zeigen weiter den Namen). Der eigene Benutzer kann nicht
 * gelöscht werden.
 *
 * @param int $data['id'] Benutzer-ID
 * @testdata {"id": 999}
 */
function deleteUser($data) {
    $auth = requireSystemAdmin();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt');
        return;
    }
    if ($id === (int)$auth->getUserId()) {
        resultInfo(false, 'CANNOT_DELETE_SELF', 'Der eigene Benutzer kann nicht gelöscht werden');
        return;
    }
    if (!$auth->getOne('SELECT 1 FROM auth."user" WHERE id = :id', [':id' => $id])) {
        resultInfo(false, 'NOT_FOUND', 'Benutzer nicht gefunden');
        return;
    }

    $warnings = tenantMarkEmployeesDeleted($auth, $id);

    $auth->beginTransaction();
    try {
        $auth->execute('DELETE FROM auth.session_oserp WHERE user_id = :id', [':id' => $id]);
        $auth->execute('UPDATE auth.clients SET task_server_user_id = NULL WHERE task_server_user_id = :id', [':id' => $id]);
        $auth->execute('DELETE FROM auth."user" WHERE id = :id', [':id' => $id]); // Zuordnungen per ON DELETE CASCADE
        $auth->commit();
    } catch (\Throwable $e) {
        $auth->rollBack();
        throw $e;
    }
    resultInfo(true, 'USER_DELETED', ['id' => $id, 'warnings' => $warnings]);
}

/**
 * Legt eine Gruppe an oder speichert sie (Name, Beschreibung, Rechte, Mitglieder, Firmen)
 *
 * @param int $data['id'] Gruppen-ID (leer = neu)
 * @param string $data['name'] Name
 * @param string $data['description'] Beschreibung
 * @param array $data['rights'] Erteilte Rechte (Namen aus auth.master_rights)
 * @param array $data['user_ids'] Mitglieder
 * @param array $data['client_ids'] Firmen, in denen die Gruppe gilt
 * @testdata {"name": "Werkstatt", "description": "Werkstattmitarbeiter", "rights": ["sales_order_edit"], "user_ids": [], "client_ids": [1]}
 */
function saveGroup($data) {
    $auth = requireSystemAdmin();

    $id = !empty($data['id']) ? (int)$data['id'] : null;
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Gruppenname fehlt');
        return;
    }
    $dupe = $auth->getOne(
        'SELECT id FROM auth."group" WHERE lower(name) = lower(:name) AND id IS DISTINCT FROM :id',
        [':name' => $name, ':id' => $id]
    );
    if ($dupe) {
        resultInfo(false, 'GROUP_NAME_EXISTS', "Gruppenname '$name' ist bereits vergeben");
        return;
    }

    $description = isset($data['description']) ? trim((string)$data['description']) : null;

    $auth->beginTransaction();
    try {
        if ($id === null) {
            $row = $auth->getOne(
                'INSERT INTO auth."group" (name, description) VALUES (:name, :description) RETURNING id',
                [':name' => $name, ':description' => $description]
            );
            $id = (int)$row['id'];
        } else {
            if (!$auth->getOne('SELECT 1 FROM auth."group" WHERE id = :id', [':id' => $id])) {
                throw new ApiError('NOT_FOUND', 'Gruppe nicht gefunden');
            }
            $auth->execute(
                'UPDATE auth."group" SET name = :name, description = :description WHERE id = :id',
                [':name' => $name, ':description' => $description, ':id' => $id]
            );
        }
        if (array_key_exists('rights', $data)) {
            $rights = is_array($data['rights']) ? array_map('strval', $data['rights']) : [];
            tenantSetGroupRights($auth, $id, $rights);
        }
        if (array_key_exists('user_ids', $data)) {
            tenantSetLinks($auth, 'auth.user_group', 'group_id', $id, 'user_id', adminIntList($data['user_ids']));
        }
        if (array_key_exists('client_ids', $data)) {
            tenantSetLinks($auth, 'auth.clients_groups', 'group_id', $id, 'client_id', adminIntList($data['client_ids']));
        }
        $auth->commit();
    } catch (\Throwable $e) {
        $auth->rollBack();
        throw $e;
    }
    resultInfo(true, 'GROUP_SAVED', ['id' => $id]);
}

/**
 * Löscht eine Gruppe (Mitgliedschaften und Rechte per ON DELETE CASCADE)
 *
 * @param int $data['id'] Gruppen-ID
 * @testdata {"id": 999}
 */
function deleteGroup($data) {
    $auth = requireSystemAdmin();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0 || !$auth->getOne('SELECT 1 FROM auth."group" WHERE id = :id', [':id' => $id])) {
        resultInfo(false, 'NOT_FOUND', 'Gruppe nicht gefunden');
        return;
    }
    $auth->execute('DELETE FROM auth."group" WHERE id = :id', [':id' => $id]);
    resultInfo(true, 'GROUP_DELETED', ['id' => $id]);
}

/**
 * Registriert eine vorhandene Datenbank als Firma oder speichert eine Firma
 *
 * Für neue Datenbanken siehe createCompany (backend/api/company). Ein leeres
 * DB-Passwort lässt beim Speichern das gespeicherte Passwort unverändert.
 *
 * @param int $data['id'] Firmen-ID (leer = neu registrieren)
 * @param string $data['name'] Firmenname
 * @param string $data['dbhost'] DB-Host
 * @param int $data['dbport'] DB-Port
 * @param string $data['dbname'] Datenbankname
 * @param string $data['dbuser'] DB-Benutzer
 * @param string $data['dbpasswd'] DB-Passwort (leer = unverändert)
 * @param bool $data['is_default'] Standard-Mandant in der Anmeldemaske
 * @param array $data['user_ids'] Benutzer mit Zugang
 * @param array $data['group_ids'] Gruppen, die hier gelten
 * @testdata {"name": "Testfirma", "dbhost": "localhost", "dbport": 5432, "dbname": "testfirma", "dbuser": "postgres", "dbpasswd": "", "is_default": false, "user_ids": [1], "group_ids": [1]}
 */
function saveClient($data) {
    $auth = requireSystemAdmin();

    $id = !empty($data['id']) ? (int)$data['id'] : null;
    $client = [
        'name' => trim((string)($data['name'] ?? '')),
        'dbhost' => trim((string)($data['dbhost'] ?? '')),
        'dbport' => (int)($data['dbport'] ?? 5432),
        'dbname' => trim((string)($data['dbname'] ?? '')),
        'dbuser' => trim((string)($data['dbuser'] ?? '')),
        'dbpasswd' => isset($data['dbpasswd']) ? (string)$data['dbpasswd'] : '',
        'is_default' => adminBool($data['is_default'] ?? false),
    ];
    foreach (['name', 'dbhost', 'dbname', 'dbuser'] as $field) {
        if ($client[$field] === '') {
            resultInfo(false, 'VALIDATION_ERROR', "Feld '$field' fehlt");
            return;
        }
    }
    if ($client['dbport'] < 1 || $client['dbport'] > 65535) {
        resultInfo(false, 'VALIDATION_ERROR', 'Port ungültig');
        return;
    }
    if ($auth->getOne('SELECT id FROM auth.clients WHERE lower(name) = lower(:name) AND id IS DISTINCT FROM :id', [':name' => $client['name'], ':id' => $id])) {
        resultInfo(false, 'COMPANY_NAME_EXISTS', "Firmenname '{$client['name']}' ist bereits vergeben");
        return;
    }
    if ($auth->getOne(
        'SELECT id FROM auth.clients WHERE dbhost = :h AND dbport = :p AND dbname = :d AND id IS DISTINCT FROM :id',
        [':h' => $client['dbhost'], ':p' => $client['dbport'], ':d' => $client['dbname'], ':id' => $id]
    )) {
        resultInfo(false, 'DATABASE_ALREADY_REGISTERED', "Datenbank '{$client['dbname']}' ist bereits einer anderen Firma zugeordnet");
        return;
    }
    if ($id !== null && !$auth->getOne('SELECT 1 FROM auth.clients WHERE id = :id', [':id' => $id])) {
        resultInfo(false, 'NOT_FOUND', 'Firma nicht gefunden');
        return;
    }

    // Neue Registrierung: Verbindung muss stehen und eine Firmen-DB sein
    if ($id === null) {
        $info = tenantInspectDatabase($client['dbhost'], $client['dbport'], $client['dbname'], $client['dbuser'], $client['dbpasswd']);
        if (!$info['reachable']) {
            resultInfo(false, 'DATABASE_UNREACHABLE', 'Verbindung fehlgeschlagen: ' . $info['error']);
            return;
        }
        if (!$info['is_company']) {
            resultInfo(false, 'NOT_A_COMPANY_DATABASE', "'{$client['dbname']}' ist keine Firmen-Datenbank (Tabellen defaults/employee fehlen)");
            return;
        }
    }

    $auth->beginTransaction();
    try {
        $clientId = tenantSaveClient(
            $auth, $client,
            array_key_exists('user_ids', $data) ? adminIntList($data['user_ids']) : null,
            array_key_exists('group_ids', $data) ? adminIntList($data['group_ids']) : null,
            $id
        );
        $auth->commit();
    } catch (\Throwable $e) {
        $auth->rollBack();
        throw $e;
    }

    $warnings = tenantSyncClientEmployees($auth, $clientId);

    // Neu registrierte Firmen-DB sofort auf OSERP-Stand bringen (Tabellen defaults_oserp usw.)
    if ($id === null) {
        $warnings = array_merge($warnings, adminUpgradeClientSchema($auth, $clientId));
    }

    resultInfo(true, $id === null ? 'CLIENT_CREATED' : 'CLIENT_SAVED', ['id' => $clientId, 'warnings' => $warnings]);
}

/**
 * Spielt das OSERP-Schema (crm + aktive Erweiterungen) in die Firmen-DB eines Mandanten ein
 *
 * @param ApiSession $auth Auth-Verbindung
 * @param int $clientId Mandanten-ID
 * @return array Warnungen
 */
function adminUpgradeClientSchema($auth, $clientId) {
    $client = $auth->getOne('SELECT * FROM auth.clients WHERE id = :id', [':id' => $clientId]);
    $pdo = $client ? tenantClientPdo($client) : null;
    if (!$pdo) {
        return ['Firmen-Datenbank nicht erreichbar — OSERP-Schema nicht eingespielt'];
    }
    require_once __DIR__ . '/../update/update.php';
    $db = new ApiDatabase($pdo);
    $base = __DIR__ . '/../../upstall/';
    $dirs = ['crm'];
    try {
        foreach (getActiveExtensions($db) as $ext) {
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $ext)) {
                $dirs[] = $ext;
            }
        }
    } catch (\Throwable $e) {
        // frische DB ohne extensions_oserp — nur crm
    }
    $sql = [];
    $csv = ['auth' => [], 'company' => []];
    foreach ($dirs as $dir) {
        if (file_exists($base . $dir . '/company_schema.sql')) {
            $sql[] = $base . $dir . '/company_schema.sql';
        }
        $csv['company'] = array_merge($csv['company'], glob($base . $dir . '/company_data/*.csv') ?: []);
    }
    $res = updateDatabaseSchema($sql, $csv, false, $db);
    return $res['success'] ? [] : array_map(fn($e) => 'Schema-Update: ' . $e, $res['errors']);
}

/**
 * Löscht eine Firma (Registrierung) — optional samt Datenbank
 *
 * Die Datenbank wird nur gelöscht, wenn drop_database gesetzt ist UND der
 * übergebene Bestätigungsname exakt dem Datenbanknamen entspricht. Vorher wird
 * ein Backup gezogen (pg_dump nach BACKUP_BASE_DIR), außer backup = false.
 *
 * @param int $data['id'] Firmen-ID
 * @param bool $data['drop_database'] Datenbank ebenfalls löschen
 * @param string $data['confirm_dbname'] Zur Sicherheit: Datenbankname wiederholen
 * @param bool $data['backup'] Vorher Backup erstellen (Standard: true)
 * @testdata {"id": 999, "drop_database": false}
 */
function deleteClient($data) {
    $auth = requireSystemAdmin();
    $id = (int)($data['id'] ?? 0);
    $client = $id > 0 ? $auth->getOne('SELECT * FROM auth.clients WHERE id = :id', [':id' => $id]) : null;
    if (!$client) {
        resultInfo(false, 'NOT_FOUND', 'Firma nicht gefunden');
        return;
    }
    if ($id === (int)$auth->getClientId()) {
        resultInfo(false, 'CANNOT_DELETE_CURRENT', 'Die Firma, in der Sie gerade angemeldet sind, kann nicht gelöscht werden — bitte zuerst in eine andere Firma wechseln');
        return;
    }
    $drop = adminBool($data['drop_database'] ?? false);
    $backup = null;
    if ($drop) {
        if (($data['confirm_dbname'] ?? '') !== $client['dbname']) {
            resultInfo(false, 'CONFIRMATION_MISMATCH', 'Der eingegebene Datenbankname stimmt nicht überein');
            return;
        }
        $isAuthDb = $client['dbhost'] === DB_HOST && (int)$client['dbport'] === (int)DB_PORT && $client['dbname'] === DB_AUTH_NAME;
        if ($isAuthDb) {
            resultInfo(false, 'CANNOT_DROP_AUTH_DB', 'Die Auth-Datenbank kann nicht gelöscht werden');
            return;
        }
        if (adminBool($data['backup'] ?? true)) {
            require_once __DIR__ . '/../developer-tools/database-backup.php';
            $backup = createAutoBackupForClient($client, 'vor_loeschung_' . $client['dbname'] . '_' . date('Y-m-d_H-i-s'));
            if (!$backup['success']) {
                resultInfo(false, 'BACKUP_FAILED', 'Backup fehlgeschlagen, Datenbank wurde NICHT gelöscht: ' . $backup['error']);
                return;
            }
        }
    }

    $auth->execute('DELETE FROM auth.session_oserp WHERE client_id = :id', [':id' => $id]);
    $auth->execute('DELETE FROM auth.clients WHERE id = :id', [':id' => $id]); // Zuordnungen per ON DELETE CASCADE

    $dropped = false;
    $dropError = null;
    if ($drop) {
        try {
            $adminPdo = tenantAdminConnect($client['dbhost'], $client['dbport'], $client['dbuser'], $client['dbpasswd']);
            tenantDropDatabase($adminPdo, $client['dbname']);
            $dropped = true;
        } catch (\Throwable $e) {
            $dropError = $e->getMessage();
        }
    }

    resultInfo(true, 'CLIENT_DELETED', [
        'id' => $id,
        'dropped' => $dropped,
        'drop_error' => $dropError,
        'backup' => $backup ? $backup['filename'] : null,
    ]);
}

/**
 * Testet die Verbindung zu einer Firmen-Datenbank und meldet, was drin ist
 *
 * Entweder per Firmen-ID (nutzt das gespeicherte Passwort) oder mit frei
 * eingegebenen Zugangsdaten (leeres Passwort bei bekannter ID = gespeichertes).
 *
 * @param int $data['id'] Firmen-ID (optional)
 * @param string $data['dbhost'] Host
 * @param int $data['dbport'] Port
 * @param string $data['dbname'] Datenbank
 * @param string $data['dbuser'] Benutzer
 * @param string $data['dbpasswd'] Passwort
 * @testdata {"dbhost": "localhost", "dbport": 5432, "dbname": "testfirma", "dbuser": "postgres", "dbpasswd": ""}
 */
function testClientConnection($data) {
    $auth = requireSystemAdmin();
    $stored = null;
    if (!empty($data['id'])) {
        $stored = $auth->getOne('SELECT * FROM auth.clients WHERE id = :id', [':id' => (int)$data['id']]);
    }
    $host = trim((string)($data['dbhost'] ?? ($stored['dbhost'] ?? '')));
    $port = (int)($data['dbport'] ?? ($stored['dbport'] ?? 5432));
    $dbname = trim((string)($data['dbname'] ?? ($stored['dbname'] ?? '')));
    $user = trim((string)($data['dbuser'] ?? ($stored['dbuser'] ?? '')));
    $pass = (string)($data['dbpasswd'] ?? '');
    if ($pass === '' && $stored) {
        $pass = $stored['dbpasswd'];
    }
    if ($host === '' || $dbname === '' || $user === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Host, Datenbank und Benutzer sind erforderlich');
        return;
    }
    $info = tenantInspectDatabase($host, $port, $dbname, $user, $pass);
    resultInfo($info['reachable'], $info['reachable'] ? '' : 'DATABASE_UNREACHABLE', $info);
}

/**
 * Listet die Datenbanken des Auth-Servers mit Einordnung (Firma? registriert?)
 *
 * Grundlage für "Vorhandene Datenbank verbinden": zeigt, welche Datenbanken
 * kivitendo-/OSERP-Firmen sind und welche davon noch keinem Mandanten zugeordnet
 * sind. Die Untersuchung nutzt die Auth-Zugangsdaten aus settings.ini.
 *
 * @param bool $data['inspect'] Jede Datenbank öffnen und einordnen (Standard: true)
 * @testdata {"inspect": true}
 */
function listServerDatabases($data) {
    $auth = requireSystemAdmin();
    $inspect = adminBool($data['inspect'] ?? true);

    try {
        $pdo = tenantConnect(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS, 5);
    } catch (PDOException $e) {
        resultInfo(false, 'DATABASE_UNREACHABLE', $e->getMessage());
        return;
    }
    $role = tenantRoleInfo($pdo);
    $registered = [];
    foreach ($auth->getAll('SELECT id, name, dbhost, dbport, dbname FROM auth.clients', []) as $c) {
        $registered[$c['dbhost'] . ':' . $c['dbport'] . ':' . $c['dbname']] = ['id' => (int)$c['id'], 'name' => $c['name']];
    }
    $databases = [];
    foreach (tenantListDatabases($pdo) as $db) {
        $entry = $db;
        $entry['size_bytes'] = $db['size_bytes'] !== null ? (int)$db['size_bytes'] : null;
        $entry['is_auth_db'] = $db['name'] === DB_AUTH_NAME;
        $key = DB_HOST . ':' . (int)DB_PORT . ':' . $db['name'];
        $entry['client'] = $registered[$key] ?? null;
        if ($inspect && !$entry['is_auth_db']) {
            $info = tenantInspectDatabase(DB_HOST, DB_PORT, $db['name'], DB_AUTH_USER, DB_AUTH_PASS);
            $entry += ['reachable' => $info['reachable'], 'is_company' => $info['is_company'], 'is_auth' => $info['is_auth'],
                       'has_oserp' => $info['has_oserp'], 'company' => $info['company'], 'coa' => $info['coa'], 'version' => $info['version']];
        }
        $databases[] = $entry;
    }
    resultInfo(true, '', [
        'server' => ['host' => DB_HOST, 'port' => (int)DB_PORT, 'user' => DB_AUTH_USER, 'version' => $role['version'],
                     'superuser' => $role['superuser'], 'createdb' => $role['createdb']],
        'databases' => $databases,
    ]);
}

/**
 * Bringt die Firmen-Datenbank eines Mandanten auf den aktuellen OSERP-Schema-Stand
 *
 * @param int $data['id'] Firmen-ID
 * @testdata {"id": 1}
 */
function upgradeClientSchema($data) {
    $auth = requireSystemAdmin();
    set_time_limit(0);
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0 || !$auth->getOne('SELECT 1 FROM auth.clients WHERE id = :id', [':id' => $id])) {
        resultInfo(false, 'NOT_FOUND', 'Firma nicht gefunden');
        return;
    }
    $warnings = adminUpgradeClientSchema($auth, $id);
    $warnings = array_merge($warnings, tenantSyncClientEmployees($auth, $id));

    // Auch mit Hinweisen ist der Vorgang gelaufen — die Oberflaeche zeigt sie an.
    // Ein "success = false" waere hier irrefuehrend: es gaebe nur einen Fehlercode
    // statt der eigentlichen Meldungen.
    resultInfo(true, 'SCHEMA_UPGRADED', ['warnings' => $warnings]);
}
