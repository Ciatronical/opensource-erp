<?php
// api/session.php

/**
 * Erweiterte Datenbankklasse mit Session-Management
 */
class ApiSession extends ApiDatabase {
    private $sessionCookie = null;
    private $userId = null;
    private $clientId = null;
    private $login = null;

    public function __construct($pdo = null) {
        parent::__construct($pdo);
        $this->sessionCookie = $_COOKIE[SESSION_COOKIE] ?? null;
    }

    public function __destruct() {
        parent::__destruct();
    }

    function hasSession() {
        if (null === $this->sessionCookie) {
            return false;
        }
        return true;
    }

    function getCookie() {
        if (null === $this->sessionCookie) {
            throw new ApiError("NO_SESSION", 'Keine Sitzung vorhanden');
        }
        return $this->sessionCookie;
    }

    function setCookie($cookie) {
        $this->sessionCookie = $cookie;
    }

    function getUserId() {
        return $this->userId;
    }

    function getClientId() {
        return $this->clientId;
    }

    function getLogin() {
        return $this->login;
    }

    function setUserId($userId) {
        $this->userId = $userId;
    }

    function setClientId($clientId) {
        $this->clientId = $clientId;
    }

    function setLogin($login) {
        $this->login = $login;
    }

    /**
     * Stellt eine Verbindung zur Firmen-Datenbank basierend auf der aktuellen Sitzung her
     *
     * @return PDO
     * @throws ApiError wenn die Sitzung ungültig ist
     */
    function connectPDO() {
        $query = <<<SQL
            SELECT c.dbhost, c.dbport, c.dbname, c.dbuser, c.dbpasswd
            FROM auth.session_oserp s
            JOIN auth.user u ON s.user_id = u.id
            JOIN auth.clients c ON s.client_id = c.id
            WHERE s.session_id = :session_id
        SQL;

        $context = $this->getOne($query, [':session_id' => $this->getCookie()]);

        if (!$context) {
            throw new ApiError("INVALID_SESSION", 'Ungültige Sitzung');
        }

        $dbhost = $context['dbhost'];
        $dbport = $context['dbport'];
        $dbname = $context['dbname'];
        $dbuser = $context['dbuser'];
        $dbpasswd = $context['dbpasswd'];

        $pdo = connectPDO($dbhost, $dbport, $dbname, $dbuser, $dbpasswd);
        return $pdo;
    }

    /**
     * Lädt Session-Daten aus der Datenbank
     *
     * @throws ApiError wenn die Sitzung ungültig ist
     */
    function fetchSessionData() {
        $query = <<<SQL
            SELECT s.user_id, s.client_id, u.login
            FROM auth.session_oserp s
            JOIN auth.user u ON s.user_id = u.id
            WHERE s.session_id = :session_id
        SQL;

        $sessionData = $this->getOne($query, [':session_id' => $this->getCookie()]);

        if (!$sessionData) {
            throw new ApiError("INVALID_SESSION", 'Ungültige Sitzung');
        }
        $this->setUserId($sessionData['user_id']);
        $this->setClientId($sessionData['client_id']);
        $this->setLogin($sessionData['login']);
    }

    /**
     * Prüft, ob der angemeldete Benutzer die angegebenen Berechtigungen besitzt
     *
     * @param array|string $permissions Array oder String mit Berechtigungen
     * @param bool $allRequired true => alle Berechtigungen erforderlich, false => eine genügt
     * @return bool
     */
    function checkPermissions($permissions, $allRequired = true) {
        $user_id = $this->getUserId();
        $client_id = $this->getClientId();
        if (null === $user_id || null === $client_id) {
            return false;
        }

        if (!is_array($permissions)) {
            $permissions = array($permissions);
        }

        $query = <<<SQL
            SELECT group_id
            FROM auth.user_group
            WHERE user_id = :user_id
        SQL;
        $userGroups = $this->getAll($query, [':user_id' => $user_id]);

        $permited = [];

        foreach ($userGroups as $group) {
            $query = <<<SQL
                SELECT gr.right
                FROM auth.group_rights gr
                JOIN auth.clients_groups cg ON gr.group_id = cg.group_id
                WHERE cg.client_id = :client_id
                AND cg.group_id = :group_id
                AND gr.granted = true
                ORDER BY gr.right ASC
            SQL;
            $grantedPermissions = $this->getAll($query, [
                ':client_id' => $client_id,
                ':group_id' => $group['group_id']
            ]);

            foreach ($permissions as $permission) {
                if (in_array($permission, array_column($grantedPermissions, 'right'))) {
                    if (!$allRequired) {
                        return true;
                    } else {
                        if (!in_array($permission, $permited, true)) {
                            $permited[] = $permission;
                        }
                    }
                }
            }
        }
        if (count($permited) == count($permissions)) {
            return true;
        }
        return false;
    }

    /**
     * Ruft alle Berechtigungen des angemeldeten Benutzers ab
     *
     * @return array
     */
    function fetchAllPermissions() {
        $user_id = $this->getUserId();
        $client_id = $this->getClientId();
        if (null === $user_id || null === $client_id) {
            return [];
        }

        $query = <<<SQL
            SELECT group_id
            FROM auth.user_group
            WHERE user_id = :user_id
        SQL;
        $userGroups = $this->getAll($query, [':user_id' => $user_id]);

        $permissions = [];

        foreach ($userGroups as $group) {
            $query = <<<SQL
                SELECT gr.right
                FROM auth.group_rights gr
                JOIN auth.clients_groups cg ON gr.group_id = cg.group_id
                WHERE cg.client_id = :client_id
                AND cg.group_id = :group_id
                AND gr.granted = true
                ORDER BY gr.right ASC
            SQL;
            $grantedPermissions = $this->getAll($query, [
                ':client_id' => $client_id,
                ':group_id' => $group['group_id']
            ]);

            foreach ($grantedPermissions as $perm) {
                if (!in_array($perm['right'], $permissions)) {
                    $permissions[] = $perm['right'];
                }
            }
        }
        return $permissions;
    }

    /**
     * Lädt alle Gruppen des aktuellen Mandanten mit den zugehörigen User-Logins
     *
     * @return array Liste der Gruppen [{id, name, logins: [login1, login2, ...]}]
     */
    function fetchClientGroups() {
        $client_id = $this->getClientId();
        if (null === $client_id) {
            return [];
        }

        // Alle Gruppen des Mandanten
        $groups = $this->getAll(
            'SELECT g.id, g.name
             FROM auth."group" g
             JOIN auth.clients_groups cg ON g.id = cg.group_id
             WHERE cg.client_id = :client_id
             ORDER BY g.name ASC',
            [':client_id' => $client_id]
        );

        if (!$groups) return [];

        // User-Logins pro Gruppe laden
        foreach ($groups as &$group) {
            $users = $this->getAll(
                'SELECT u.login
                 FROM auth.user_group ug
                 JOIN auth."user" u ON ug.user_id = u.id
                 WHERE ug.group_id = :group_id
                 ORDER BY u.login ASC',
                [':group_id' => $group['id']]
            );
            $group['logins'] = $users ? array_column($users, 'login') : [];
        }

        return $groups;
    }
}

/**
 * Singleton für Authentifizierungs-Datenbankverbindung
 */
if (!class_exists('DbhAuth')) {
    class DbhAuth {
        protected static $instance = null;

        /**
         * Gibt die Singleton-Instanz zurück
         *
         * @return ApiSession
         */
        public static function begin() {
            if (null === self::$instance) {
                self::$instance = new ApiSession(connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS));
            }
            return self::$instance;
        }
    }
}

if (!function_exists('permit')) {
    /**
     * Prüft Berechtigungen und wirft ApiError bei fehlenden Rechten
     *
     * @param array|string $permissions Berechtigungen
     * @param bool $allRequired true => alle erforderlich, false => eine genügt
     * @throws ApiError
     */
    function permit($permissions, $allRequired = true) {
        $session = DbhAuth::begin();
        $session->fetchSessionData();

        if (!$session->checkPermissions($permissions, $allRequired)) {
            throw new ApiError("NO_PERMISSION", "Keine Berechtigung für diese Aktion(en)");
        }
    }
}

if (!function_exists('checkPermissions')) {
    /**
     * Prüft Berechtigungen und gibt bool zurück
     *
     * @param array|string $permissions Berechtigungen
     * @param bool $allRequired true => alle erforderlich, false => eine genügt
     * @return bool
     */
    function checkPermissions($permissions, $allRequired = true) {
        $session = DbhAuth::begin();
        $session->fetchSessionData();
        return $session->checkPermissions($permissions, $allRequired);
    }
}