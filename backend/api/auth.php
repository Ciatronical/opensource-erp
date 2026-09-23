<?php
// backend/api/auth.php

/**
 * Prüft ob der Benutzer Systemadministrator ist (Benutzer, Gruppen, Firmen verwalten)
 *
 * Die Regeln stehen in tenantAdminStatus(): explizites Kennzeichen in
 * auth.user_config, settings.ini [company] admin_users oder — solange noch
 * niemand explizit Administrator ist — das kivitendo-Recht "admin".
 *
 * @param int $userId Benutzer-ID
 * @param string $login Login-Name des Benutzers
 * @return bool
 */
function isSystemAdmin($userId, $login) {
    static $cache = [];
    $key = $userId . '|' . $login;
    if (!isset($cache[$key])) {
        $cache[$key] = tenantAdminStatus(DbhAuth::begin(), (int)$userId, $login)['is_admin'];
    }
    return $cache[$key];
}

/**
 * Prüft ob der aktuelle Benutzer neue Firmen anlegen darf (= Systemadministrator)
 *
 * @param string $login Login-Name des Benutzers
 * @return bool
 */
function canUserCreateCompany($login) {
    $auth = DbhAuth::begin();
    return isSystemAdmin($auth->getUserId(), $login);
}

/**
 * Bricht mit NO_PERMISSION ab, wenn der angemeldete Benutzer kein Systemadministrator ist
 *
 * @return ApiSession Die geladene Auth-Sitzung
 * @throws ApiError
 */
function requireSystemAdmin() {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    if (!isSystemAdmin($auth->getUserId(), $auth->getLogin())) {
        throw new ApiError('NO_PERMISSION', 'Nur Systemadministratoren dürfen Benutzer, Gruppen und Firmen verwalten');
    }
    return $auth;
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

    try {
        $result = $session->getOne($query, array());
    } catch (PDOException $e) {
        // Fehlen die auth-Tabellen, ist das kein Datenbankfehler zum Anzeigen,
        // sondern eine unfertige Installation — dafuer gibt es den Assistenten.
        if (tenantInstallationIncomplete()) {
            resultInfo(false, 'SETUP_REQUIRED', null, 'Installation unvollständig', false);
            return;
        }
        throw $e;
    }
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
 * Ermittelt die IP-Adresse des Aufrufers (fuer Rate-Limiting)
 *
 * @return string
 */
function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Prueft, ob die Tabelle auth.login_attempts existiert
 *
 * Das Rate-Limiting haengt an dieser Tabelle. Solange ein Mandant das
 * Schema-Update noch nicht hatte, gibt es sie nicht — dann wird das Limit
 * uebersprungen (fail open), damit die Anmeldung nicht komplett blockiert.
 * Der Rest der Login-Haertung (einheitliche Fehler, Zeitangleich,
 * zeitkonstanter Vergleich) wirkt unabhaengig davon.
 *
 * @param ApiSession $auth
 * @return bool
 */
function loginAttemptsEnabled($auth): bool {
    static $enabled = null;
    if ($enabled === null) {
        $row = $auth->getOne("SELECT to_regclass('auth.login_attempts') AS t", []);
        $enabled = !empty($row['t']);
    }
    return $enabled;
}

/**
 * Prueft, ob fuer diesen Login-Namen oder diese IP zu viele Fehlversuche
 * innerhalb des Zeitfensters vorliegen
 *
 * @param ApiSession $auth
 * @param string $login Eingegebener Benutzername
 * @return bool true => blockieren
 */
function loginRateLimited($auth, string $login): bool {
    if (!loginAttemptsEnabled($auth)) {
        return false;
    }

    $row = $auth->getOne(
        "SELECT
            COUNT(*) FILTER (WHERE login = :login) AS by_login,
            COUNT(*) FILTER (WHERE ip = :ip)       AS by_ip
         FROM auth.login_attempts
         WHERE success = false
           AND attempted_at > NOW() - INTERVAL '15 minutes'",
        [':login' => $login, ':ip' => clientIp()]
    );

    $maxPerLogin = 8;   // Fehlversuche je Benutzername / 15 min
    $maxPerIp    = 30;  // Fehlversuche je IP / 15 min (Schutz vor User-Spraying)

    return ((int)($row['by_login'] ?? 0) >= $maxPerLogin)
        || ((int)($row['by_ip'] ?? 0) >= $maxPerIp);
}

/**
 * Protokolliert einen Anmeldeversuch. Bei Erfolg werden die offenen
 * Fehlversuche dieses Benutzers und dieser IP geloescht.
 *
 * @param ApiSession $auth
 * @param string $login Eingegebener Benutzername
 * @param bool $success Ob die Anmeldung erfolgreich war
 * @return void
 */
function recordLoginAttempt($auth, string $login, bool $success): void {
    if (!loginAttemptsEnabled($auth)) {
        return;
    }

    $auth->execute(
        "INSERT INTO auth.login_attempts (login, ip, success) VALUES (:login, :ip, :success)",
        [':login' => $login, ':ip' => clientIp(), ':success' => $success]
    );

    if ($success) {
        $auth->execute(
            "DELETE FROM auth.login_attempts
             WHERE success = false AND (login = :login OR ip = :ip)",
            [':login' => $login, ':ip' => clientIp()]
        );
    }
}

/**
 * Authentifiziert einen Benutzer und erstellt eine Session
 *
 * @param array $data Array mit 'username', 'password', 'client' und optional 'remember_me'
 * @return void Gibt JSON mit Login-Daten und CV-Daten aus
 * @throws ApiError Bei fehlenden Argumenten, ungültigen Zugangsdaten oder zu vielen Fehlversuchen
 * @testdata {"username": "demo", "password": "demo", "client": 1, "remember_me": false}
 */
function login($data) {
    if (!isset($data['username']) || !isset($data['password']) || !isset($data['client'])) {
        resultInfo(false, "MISSING_ARGUMENTS");
        return;
    }

    $auth = DbhAuth::begin();

    $username = $data['username'];
    $cleartextPassword = $data['password'];
    $clientId = (int)$data['client'];
    $rememberMe = !empty($data['remember_me']);

    // Brute-Force-Bremse: bei zu vielen Fehlversuchen gar nicht erst pruefen.
    if (loginRateLimited($auth, $username)) {
        throw new ApiError("TOO_MANY_ATTEMPTS", "Zu viele Fehlversuche. Bitte spaeter erneut versuchen.");
    }

    $query = <<<SQL
        SELECT id, login, password
        FROM auth.user
        WHERE login = :username
    SQL;
    $context = $auth->getOne($query, [':username' => $username]);

    // Einheitliche Fehlermeldung fuer "Benutzer unbekannt" und "Passwort
    // falsch", damit sich gueltige Logins nicht ueber die Antwort ermitteln
    // lassen. Bei unbekanntem Benutzer trotzdem einen PBKDF2-Hash rechnen,
    // damit die Antwortzeit den Unterschied nicht verraet.
    $DUMMY_HASH = '{PBKDF2}64756d6d79000000000000000000000000:' . str_repeat('0', 64);

    if (!$context) {
        verify_password($username, $cleartextPassword, $DUMMY_HASH); // Zeitangleich
        recordLoginAttempt($auth, $username, false);
        throw new ApiError("INVALID_CREDENTIALS", "Benutzername oder Passwort falsch");
    }

    $storedHash = $context['password'];
    $userId = $context['id'];

    if (!verify_password($username, $cleartextPassword, $storedHash)) {
        recordLoginAttempt($auth, $username, false);
        throw new ApiError("INVALID_CREDENTIALS", "Benutzername oder Passwort falsch");
    }

    // Ab hier sind die Zugangsdaten korrekt. Erst jetzt darf die Antwort
    // verraten, ob der Benutzer dem gewaehlten Mandanten zugeordnet ist.
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
        recordLoginAttempt($auth, $username, false);
        throw new ApiError("USER_NOT_ASSIGNED_TO_CLIENT", "Benutzer nicht dem Mandanten zugeordnet");
    }

    // Erfolgreiche Anmeldung: offene Fehlversuche loeschen.
    recordLoginAttempt($auth, $username, true);

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

    // Wie kivitendo: beim Login den Mitarbeiter-Datensatz im Mandanten sicherstellen.
    // Ohne employee-Zeile fehlt der Verkäufer/Bearbeiter auf allen Belegen.
    $dbhCompany = DbhCompany::begin();
    $authUserData = getAuthUserData();
    $employeeId = tenantEnsureEmployee($dbhCompany, $username, $authUserData['name'] ?? null);

    $isAdmin = isSystemAdmin($userId, $context['login']);
    $loginData = array(
        "login" => $context['login'],
        "client" => $clientName[0]['name'],
        "user_id" => $userId,
        "client_id" => $clientId,
        "employee_id" => $employeeId,
        "auth_user_data" => $authUserData,
        "permissions" => $auth->fetchAllPermissions(),
        "auth_groups" => $auth->fetchClientGroups(),
        "is_demo" => defined('DEMO_MODE') && DEMO_MODE,
        "demo_inactivity_minutes" => defined('DEMO_INACTIVITY_MINUTES') ? DEMO_INACTIVITY_MINUTES : 20,
        "is_admin" => $isAdmin,
        "can_create_company" => $isAdmin,
        // Upstall-Dateien neuer als das Schema dieser Datenbank? Dann
        // stoesst das Frontend das Update an und meldet sich neu an.
        "schema_update_needed" => upstallUpdateNeeded($dbhCompany)
    );

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

    // Ohne Cookie ist die Frage nicht "wer bist du", sondern "steht die Installation
    // ueberhaupt". Fehlen Tabellen, Benutzer oder Firma, fuehrt die Anmeldemaske in
    // eine Sackgasse — dann uebernimmt der Einrichtungsassistent.
    if (!$session->hasSession()) {
        if (tenantInstallationIncomplete()) {
            resultInfo(false, 'SETUP_REQUIRED', null, 'Installation unvollständig', false);
            return;
        }
        throw new ApiError('NO_SESSION', 'Keine Sitzung vorhanden');
    }

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
    try {
        $context = $session->getOne($query, [':session_id' => $sessionId]);
    } catch (PDOException $e) {
        $context = null;   // fehlende Tabellen: unten als Einrichtungsfall behandelt
    }

    if (!$context) {
        // Keine gueltige Sitzung. Bevor die Anmeldemaske erscheint: Steht die
        // Installation ueberhaupt? Ohne Tabellen, ohne Benutzer oder ohne Firma
        // waere die Maske eine Sackgasse — dann uebernimmt der Assistent.
        if (tenantInstallationIncomplete()) {
            resultInfo(false, 'SETUP_REQUIRED', null, 'Installation unvollständig', false);
            return;
        }
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

    $isAdmin = isSystemAdmin($context['user_id'], $context['login']);
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
        "is_admin" => $isAdmin,
        "can_create_company" => $isAdmin
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
    $authUserData = getAuthUserData();
    $employeeId = tenantEnsureEmployee($dbhCompany, $context['login'], $authUserData['name'] ?? null);

    $isAdmin = isSystemAdmin($userId, $context['login']);
    $loginData = array(
        'login' => $context['login'],
        'client' => $clientName[0]['name'],
        'user_id' => $userId,
        'client_id' => $clientId,
        'employee_id' => $employeeId,
        'auth_user_data' => $authUserData,
        'permissions' => $auth->fetchAllPermissions(),
        'auth_groups' => $auth->fetchClientGroups(),
        'is_demo' => defined('DEMO_MODE') && DEMO_MODE,
        'demo_inactivity_minutes' => defined('DEMO_INACTIVITY_MINUTES') ? DEMO_INACTIVITY_MINUTES : 20,
        'is_admin' => $isAdmin,
        'can_create_company' => $isAdmin,
        // Wie bei der Anmeldung: sind die Upstall-Dateien neuer als das Schema
        // dieser Firmen-Datenbank, stößt das Frontend das Update an und
        // wiederholt den Wechsel. Ohne diese Zeile landete der Benutzer beim
        // Firmenwechsel in einer veralteten Datenbank, weil die Anmeldung nur
        // die Datenbank prüft, in die sie selbst hineingeht.
        'schema_update_needed' => upstallUpdateNeeded($dbhCompany)
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