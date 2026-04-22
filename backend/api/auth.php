<?php
// backend/api/auth.php

/**
 * Prüft ob der aktuelle Benutzer neue Firmen anlegen darf
 *
 * @param string $login Login-Name des Benutzers
 * @return bool
 */
function canUserCreateCompany($login) {
    $adminUsers = array_map('trim', explode(',', COMPANY_ADMIN_USERS));
    return in_array($login, $adminUsers, true);
}

/**
 * Lädt die Liste aller verfügbaren Mandanten
 *
 * @param array $data Eingabedaten (wird nicht verwendet)
 * @return void Gibt JSON mit Mandantenliste aus
 */
function getClients($data) {
    $session = DbhAuth::begin();

    $query = <<<SQL
        SELECT json_agg(clients) AS clients
        FROM (
            SELECT id AS code, name, is_default
            FROM auth.clients
            ORDER BY name
        ) AS clients
    SQL;

    $result = $session->getOne($query, array());
    $clients = json_decode($result['clients'] ?? '[]', true) ?: [];

    resultInfo(true, '', [
        'clients'  => $clients,
        'is_demo'  => defined('DEMO_MODE') && DEMO_MODE
    ]);
}

/**
 * Lädt die Benutzerkonfiguration aus der auth-Datenbank
 *
 * @param bool $json Wenn true, wird das Ergebnis als JSON zurückgegeben
 * @return array|string Benutzerdaten als Array oder JSON-String
 */
function getAuthUserData($json = false) {
    $auth = DbhAuth::begin();
    $userId = $auth->getUserId();

    if ($json) {
        $query = <<<SQL
            SELECT json_object_agg(cfg_key, cfg_value) AS user_data
            FROM auth.user_config
            WHERE user_id = :user_id;
        SQL;
        return $auth->get($query, [':user_id' => $userId]);
    } else {
        $query = <<<SQL
            SELECT *
            FROM auth.user_config
            WHERE user_id = :user_id
        SQL;
    }

    $result = $auth->getAll($query, [':user_id' => $userId]);
    $userData = array();
    foreach ($result as $row) {
        $userData[$row['cfg_key']] = $row['cfg_value'];
    }
    $result = $userData;
    return $result;
}

/**
 * Authentifiziert einen Benutzer und erstellt eine Session
 *
 * @param array $data Array mit 'username', 'password', 'client' und optional 'remember_me'
 * @return void Gibt JSON mit Login-Daten und CV-Daten aus
 * @throws ApiError Bei fehlenden Argumenten, ungültigem Benutzer oder falschem Passwort
 * @testdata {"username": "demo", "password": "demo", "client": 1, "remember_me": false}
 */
function login($data) {
    if (!isset($data['username']) || !isset($data['password']) || !isset($data['client'])) {
        resultInfo(false, "MISSING_ARGUMENTS");
        return;
    }

    $auth = DbhAuth::begin();

    $username = $data['username'];
    $query = <<<SQL
        SELECT *
        FROM auth.user
        WHERE login = :username
    SQL;
    $context = $auth->getOne($query, [':username' => $username]);

    if (!$context) {
        throw new ApiError("USER_NOT_FOUND", "Benutzer nicht gefunden");
    }

    $storedHash = $context['password'];
    $userId = $context['id'];
    $clientId = (int)$data['client'];
    $cleartextPassword = $data['password'];
    $rememberMe = !empty($data['remember_me']);

    $auth->setUserId($userId);
    $auth->setClientId($clientId);

    $query = <<<SQL
        SELECT name
        FROM auth.clients_users cu
        JOIN auth.clients c ON cu.client_id = c.id
        WHERE client_id = :client_id
        AND user_id = :user_id
    SQL;
    $clientName = $auth->getAll($query, [
        ':client_id' => $clientId,
        ':user_id' => $userId
    ]);

    if (empty($clientName)) {
        throw new ApiError("USER_NOT_ASSIGNED_TO_CLIENT", "Benutzer nicht dem Mandanten zugeordnet");
    }

    $loginData = array();

    if (verify_password($username, $cleartextPassword, $storedHash)) {
        if (!$auth->hasSession()) {
            $sessionId = bin2hex(random_bytes(16));
            $auth->setCookie($sessionId);
        } else {
            $sessionId = $auth->getCookie();
        }

        // Ohne remember_me: reiner Session-Cookie (stirbt mit dem Browser)
        // Mit remember_me: persistenter Cookie, der in restoreSession() bei Aktivitaet mit-gesliden wird
        $cookieOptions = ['path' => '/', 'samesite' => COOKIE_SAME_SITE, 'httponly' => true];
        if ($rememberMe) {
            $cookieOptions['expires'] = time() + 120 * 3600;
        }
        setcookie(SESSION_COOKIE, $sessionId, $cookieOptions);

        $query = <<<SQL
            INSERT INTO auth.session_oserp (session_id, user_id, client_id, remember_me)
            VALUES (:session_id, :user_id, :client_id, :remember_me)
            ON CONFLICT (session_id) DO UPDATE SET
                user_id = :user_id,
                client_id = :client_id,
                active = NOW(),
                remember_me = :remember_me
        SQL;
        $auth->execute($query, [
            ':session_id' => $sessionId,
            ':user_id' => $userId,
            ':client_id' => $clientId,
            ':remember_me' => $rememberMe,
        ]);

        $dbhCompany = DbhCompany::begin();
        $query = <<<SQL
            SELECT id
            FROM employee
            WHERE login = :username
        SQL;
        $employeeId = $dbhCompany->getOne($query, [':username' => $username]);

        $loginData = array(
            "login" => $context['login'],
            "client" => $clientName[0]['name'],
            "user_id" => $userId,
            "client_id" => $clientId,
            "employee_id" => $employeeId,
            "auth_user_data" => getAuthUserData(),
            "permissions" => $auth->fetchAllPermissions(),
            "auth_groups" => $auth->fetchClientGroups(),
            "is_demo" => defined('DEMO_MODE') && DEMO_MODE,
            "demo_inactivity_minutes" => defined('DEMO_INACTIVITY_MINUTES') ? DEMO_INACTIVITY_MINUTES : 20,
            "can_create_company" => canUserCreateCompany($context['login'])
        );
    } else {
        throw new ApiError("WRONG_PASSWORD", "Falsches Passwort");
    }

    require __DIR__ . '/customer_vendor/customer_vendor.php';
    getCV($data, $loginData);
}

/**
 * Gibt die Git-Commit-Hashes des lokalen und Remote-Repos zurück
 *
 * @return array ['local' => string|false, 'remote' => string|false]
 */
function getGitHashes() {
    $local = shell_exec('git rev-parse HEAD 2>/dev/null');
    $remote = shell_exec('git ls-remote origin -h refs/heads/main | cut -f1 2>/dev/null');
    return [
        'local' => $local ? trim($local) : false,
        'remote' => $remote ? trim($remote) : false
    ];
}

/**
 * Stellt eine bestehende Session wieder her
 *
 * @param array $data Eingabedaten (kann Filter für CV-Daten enthalten)
 * @return void Gibt JSON mit Session-Daten und CV-Daten aus
 * @throws ApiError Bei ungültiger Session
 */
function restoreSession($data) {

    if (!setupExists()) {
        resultInfo(false, 'SETUP_REQUIRED', null, 'Setup not completed', false);
        return;
    }

    $session = DbhAuth::begin();
    $sessionId = $session->getCookie();

    // Abgelaufene Sessions werden vom DB-Trigger cleanup_session_oserp geputzt (120h Inaktivitaet).
    $query = <<<SQL
        SELECT
            user_id,
            client_id,
            remember_me,
            u.login,
            c.name AS client_name
        FROM auth.session_oserp s
        JOIN auth.user u ON s.user_id = u.id
        JOIN auth.clients c ON s.client_id = c.id
        WHERE s.session_id = :session_id
    SQL;
    $context = $session->getOne($query, [':session_id' => $sessionId]);

    if (!$context) {
        throw new ApiError("INVALID_SESSION", 'Ungültige Sitzung');
    }

    // Session-Aktivitaet aktualisieren (Sliding Window)
    $session->execute(
        "UPDATE auth.session_oserp SET active = NOW() WHERE session_id = :session_id",
        [':session_id' => $sessionId]
    );

    // Cookie-Lifetime fuer "Angemeldet bleiben"-Sessions mit-sliden
    if (!empty($context['remember_me'])) {
        setcookie(SESSION_COOKIE, $sessionId, [
            'expires'  => time() + 120 * 3600,
            'path'     => '/',
            'samesite' => COOKIE_SAME_SITE,
            'httponly' => true,
        ]);
    }

    // Session-Daten setzen (falls noch nicht geschehen)
    if (!$session->getUserId()) {
        $session->setUserId($context['user_id']);
        $session->setClientId($context['client_id']);
    }

    $loginData = array(
        "user_id" => $context['user_id'],
        "client_id" => $context['client_id'],
        "login" => $context['login'],
        "client" => $context['client_name'],
        "auth_user_data" => getAuthUserData(),
        "permissions" => $session->fetchAllPermissions(),
        "auth_groups" => $session->fetchClientGroups(),
        "is_demo" => defined('DEMO_MODE') && DEMO_MODE,
        "demo_inactivity_minutes" => defined('DEMO_INACTIVITY_MINUTES') ? DEMO_INACTIVITY_MINUTES : 20,
        "can_create_company" => canUserCreateCompany($context['login'])
    );

    require __DIR__ . '/customer_vendor/customer_vendor.php';
    getCV($data, $loginData);
}

/**
 * Wechselt den Mandanten in der bestehenden Session
 *
 * @param int $data['client'] Neue Mandanten-ID
 * @testdata {"client": 1}
 */
function switchClient($data) {
    if (!isset($data['client'])) {
        resultInfo(false, 'MISSING_ARGUMENTS');
        return;
    }

    $auth = DbhAuth::begin();
    $sessionId = $auth->getCookie();

    // Aktuelle Session prüfen
    $query = <<<SQL
        SELECT user_id, u.login
        FROM auth.session_oserp s
        JOIN auth.user u ON s.user_id = u.id
        WHERE s.session_id = :session_id
    SQL;
    $context = $auth->getOne($query, [':session_id' => $sessionId]);

    if (!$context) {
        throw new ApiError('INVALID_SESSION', 'Ungültige Sitzung');
    }

    $userId = $context['user_id'];
    $clientId = (int)$data['client'];

    // Prüfen ob Benutzer dem neuen Mandanten zugeordnet ist
    $query = <<<SQL
        SELECT name
        FROM auth.clients_users cu
        JOIN auth.clients c ON cu.client_id = c.id
        WHERE client_id = :client_id
        AND user_id = :user_id
    SQL;
    $clientName = $auth->getAll($query, [
        ':client_id' => $clientId,
        ':user_id' => $userId
    ]);

    if (empty($clientName)) {
        throw new ApiError('USER_NOT_ASSIGNED_TO_CLIENT', 'Benutzer nicht dem Mandanten zugeordnet');
    }

    // Session auf neuen Mandanten umschreiben
    $auth->setUserId($userId);
    $auth->setClientId($clientId);

    $auth->execute(
        "UPDATE auth.session_oserp SET client_id = :client_id, active = NOW() WHERE session_id = :session_id",
        [':client_id' => $clientId, ':session_id' => $sessionId]
    );

    $dbhCompany = DbhCompany::begin();
    $employeeId = $dbhCompany->getOne(
        "SELECT id FROM employee WHERE login = :login",
        [':login' => $context['login']]
    );

    $loginData = array(
        'login' => $context['login'],
        'client' => $clientName[0]['name'],
        'user_id' => $userId,
        'client_id' => $clientId,
        'employee_id' => $employeeId,
        'auth_user_data' => getAuthUserData(),
        'permissions' => $auth->fetchAllPermissions(),
        'auth_groups' => $auth->fetchClientGroups(),
        'is_demo' => defined('DEMO_MODE') && DEMO_MODE,
        'demo_inactivity_minutes' => defined('DEMO_INACTIVITY_MINUTES') ? DEMO_INACTIVITY_MINUTES : 20,
        'can_create_company' => canUserCreateCompany($context['login'])
    );

    require __DIR__ . '/customer_vendor/customer_vendor.php';
    getCV($data, $loginData);
}

/**
 * Beendet die aktuelle Session
 *
 * @param array $data Eingabedaten (wird nicht verwendet)
 * @return void Gibt JSON mit Erfolgs-Status aus
 * @testdata {}
 */
function logout($data) {
    $session = DbhAuth::begin();
    $sessionId = $session->getCookie();

    $session->execute(
        "DELETE FROM auth.session_oserp WHERE session_id = :session_id",
        [':session_id' => $sessionId]
    );

    setcookie(SESSION_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'samesite' => COOKIE_SAME_SITE,
        'httponly' => true,
    ]);

    $session->setUserId(null);

    resultInfo(true);
}