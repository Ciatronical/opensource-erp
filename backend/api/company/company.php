<?php
// backend/api/company/company.php

/**
 * Quoted einen PostgreSQL-Bezeichner (Tabelle, DB, User)
 *
 * @param string $name Bezeichner
 * @return string Gequoteter Bezeichner
 */
function quoteIdentifier($name) {
    return '"' . str_replace('"', '""', $name) . '"';
}

/**
 * Legt eine neue Firmendatenbank an und registriert sie als Mandant
 *
 * Nur für Systemadministratoren. Die eigentliche Arbeit macht tenantCreateCompany()
 * (backend/api/lib/tenant.php): Datenbank anlegen, Kontenrahmen einspielen,
 * OSERP-Schema, DATEV-Kontenrahmen, Mandant registrieren, Benutzer/Gruppen zuordnen,
 * Mitarbeiter anlegen.
 *
 * Ohne userIds/groupIds bekommt der anlegende Benutzer Zugang und die Gruppen,
 * die er in der aktuellen Firma hat, gelten auch in der neuen.
 *
 * @param string $data['companyName'] Firmenname
 * @param string $data['dbName'] Datenbankname
 * @param string $data['skr'] Kontenrahmen: "skr03" oder "skr04"
 * @param bool $data['isDefault'] Als Standard-Mandant in der Anmeldemaske vorauswählen (optional)
 * @param array $data['userIds'] Benutzer-IDs mit Zugang (optional)
 * @param array $data['groupIds'] Gruppen-IDs, die im Mandanten gelten (optional)
 * @testdata {"companyName": "Testfirma", "dbName": "oserp_testfirma", "skr": "skr03"}
 */
function createCompany($data) {
    $auth = requireSystemAdmin();
    set_time_limit(0);

    $companyName = trim($data['companyName'] ?? '');
    $dbName = trim($data['dbName'] ?? '');
    $skr = trim($data['skr'] ?? '');

    if ($companyName === '' || $dbName === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Firmenname und Datenbankname sind erforderlich');
        return;
    }
    if (!isValidDbName($dbName)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Datenbankname darf nur Kleinbuchstaben, Ziffern, Unterstriche und Bindestriche enthalten und muss mit einem Buchstaben beginnen');
        return;
    }
    if (!preg_match('/^skr\d+$/', $skr)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Kontenrahmen ungültig');
        return;
    }
    if ($auth->getOne("SELECT id FROM auth.clients WHERE name = :name", [':name' => $companyName])) {
        resultInfo(false, 'COMPANY_NAME_EXISTS', "Firmenname '$companyName' bereits vergeben");
        return;
    }

    // DB-Zugang von der aktuellen Firma übernehmen — derselbe Server, dieselbe Rolle
    $credentials = $auth->getOne(
        "SELECT c.dbhost, c.dbport, c.dbuser, c.dbpasswd
         FROM auth.session_oserp s
         JOIN auth.clients c ON s.client_id = c.id
         WHERE s.session_id = :session_id",
        [':session_id' => $auth->getCookie()]
    );
    if (!$credentials) {
        resultInfo(false, 'SESSION_ERROR', 'Aktuelle Firmen-Zugangsdaten konnten nicht ermittelt werden');
        return;
    }

    $userId = (int)$auth->getUserId();
    $clientId = (int)$auth->getClientId();

    $userIds = isset($data['userIds']) && is_array($data['userIds']) ? array_map('intval', $data['userIds']) : [$userId];
    if (!in_array($userId, $userIds, true)) {
        $userIds[] = $userId; // wer anlegt, sperrt sich nicht selbst aus
    }
    if (isset($data['groupIds']) && is_array($data['groupIds'])) {
        $groupIds = array_map('intval', $data['groupIds']);
    } else {
        $rows = $auth->getAll(
            "SELECT cg.group_id
             FROM auth.clients_groups cg
             JOIN auth.user_group ug ON cg.group_id = ug.group_id
             WHERE cg.client_id = :client_id AND ug.user_id = :user_id",
            [':client_id' => $clientId, ':user_id' => $userId]
        );
        $groupIds = array_map(fn($r) => (int)$r['group_id'], $rows);
    }

    try {
        $result = tenantCreateCompany($auth, [
            'companyName' => $companyName,
            'dbname' => $dbName,
            'skr' => $skr,
            'dbhost' => $credentials['dbhost'],
            'dbport' => $credentials['dbport'],
            'dbuser' => $credentials['dbuser'],
            'dbpasswd' => $credentials['dbpasswd'],
            'userIds' => $userIds,
            'groupIds' => $groupIds,
            'isDefault' => !empty($data['isDefault']),
        ]);
    } catch (PDOException $e) {
        resultInfo(false, 'DATABASE_ERROR', dbFriendlyError($e));
        return;
    } catch (Exception $e) {
        resultInfo(false, 'SCHEMA_ERROR', $e->getMessage());
        return;
    }

    resultInfo(true, 'Firma erfolgreich angelegt', [
        'companyName' => $companyName,
        'dbName' => $dbName,
        'skr' => $skr,
        'clientId' => $result['client_id'],
        'warnings' => $result['warnings']
    ]);
}
