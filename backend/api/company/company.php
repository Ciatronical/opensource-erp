<?php
// backend/api/company/company.php

/**
 * Legt eine neue Firmendatenbank an
 *
 * 1. Prüft Berechtigung (admin_users aus settings.ini)
 * 2. Erstellt die PostgreSQL-Datenbank via CREATE DATABASE
 * 3. Spielt das SKR-Schema via Upstall-Parser ein
 * 4. Registriert die Firma in auth.clients
 * 5. Führt CRM-Upstall auf der neuen Datenbank aus
 *
 * @param string $data['companyName'] Firmenname
 * @param string $data['dbName'] Datenbankname
 * @param string $data['skr'] Kontenrahmen: "skr03" oder "skr04"
 * @testdata {"companyName": "Testfirma", "dbName": "oserp_testfirma", "skr": "skr03"}
 */
function createCompany($data) {
    // ── 1. Berechtigung prüfen ──
    $auth = DbhAuth::begin();
    $login = $auth->getLogin();

    $adminUsers = array_map('trim', explode(',', COMPANY_ADMIN_USERS));
    if (!in_array($login, $adminUsers, true)) {
        resultInfo(false, 'PERMISSION_DENIED', 'Keine Berechtigung zum Anlegen von Firmen');
        return;
    }

    // ── 2. Parameter validieren ──
    $companyName = trim($data['companyName'] ?? '');
    $dbName = trim($data['dbName'] ?? '');
    $skr = trim($data['skr'] ?? '');

    if ($companyName === '' || $dbName === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Firmenname und Datenbankname sind erforderlich');
        return;
    }

    if (!in_array($skr, ['skr03', 'skr04'], true)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Kontenrahmen muss skr03 oder skr04 sein');
        return;
    }

    // Datenbankname: nur lowercase, Ziffern, Unterstriche
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $dbName)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Datenbankname darf nur Kleinbuchstaben, Ziffern und Unterstriche enthalten und muss mit einem Buchstaben beginnen');
        return;
    }

    // Prüfe ob Firmenname schon existiert
    $existing = $auth->getOne(
        "SELECT id FROM auth.clients WHERE name = :name",
        [':name' => $companyName]
    );
    if ($existing) {
        resultInfo(false, 'ALREADY_EXISTS', 'Eine Firma mit diesem Namen existiert bereits');
        return;
    }

    // ── 3. DB-Credentials von aktueller Firma holen ──
    $credentials = $auth->getOne(
        "SELECT c.dbhost, c.dbport, c.dbuser, c.dbpasswd
         FROM auth.session_oserp s
         JOIN auth.clients c ON s.client_id = c.id
         WHERE s.session_id = :session_id",
        [':session_id' => $auth->getCookie()]
    );

    if (!$credentials) {
        resultInfo(false, 'SESSION_ERROR', 'Aktuelle Firmen-Credentials konnten nicht ermittelt werden');
        return;
    }

    $dbHost = $credentials['dbhost'];
    $dbPort = $credentials['dbport'];
    $dbUser = $credentials['dbuser'];
    $dbPass = $credentials['dbpasswd'];

    // ── 4. Prüfe ob Datenbank schon existiert ──
    $authPdo = $auth->getPDO();
    try {
        $checkStmt = $authPdo->prepare("SELECT 1 FROM pg_database WHERE datname = :dbname");
        $checkStmt->execute([':dbname' => $dbName]);
        if ($checkStmt->fetch()) {
            resultInfo(false, 'ALREADY_EXISTS', 'Eine Datenbank mit diesem Namen existiert bereits');
            return;
        }
    } catch (PDOException $e) {
        resultInfo(false, 'DATABASE_ERROR', 'Fehler beim Prüfen der Datenbank: ' . $e->getMessage());
        return;
    }

    // ── 5. Datenbank erstellen ──
    // CREATE DATABASE kann nicht in einer Transaktion ausgeführt werden
    $quotedDbName = pg_escape_identifier($dbName);
    $quotedDbUser = pg_escape_identifier($dbUser);
    try {
        $authPdo->exec("CREATE DATABASE {$quotedDbName} OWNER {$quotedDbUser}");
        writeLog("Datenbank '$dbName' erstellt", true, DLOG_INF);
    } catch (PDOException $e) {
        resultInfo(false, 'DATABASE_ERROR', 'Fehler beim Erstellen der Datenbank: ' . $e->getMessage());
        return;
    }

    // ── 6. Schema einspielen via Upstall-Parser ──
    try {
        $newDbPdo = connectPDO($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
        $newDb = new ApiDatabase($newDbPdo);

        // SKR-Schema einspielen
        $skrSchemaFile = __DIR__ . '/../../upstall/' . $skr . '/company_schema.sql';
        if (!file_exists($skrSchemaFile)) {
            throw new Exception("Schema-Datei nicht gefunden: $skrSchemaFile");
        }

        require_once __DIR__.'/../update/update.php';

        $schemaResult = updateDatabaseSchema([$skrSchemaFile], [], false, $newDb);
        if (!$schemaResult['success']) {
            throw new Exception('Schema-Import fehlgeschlagen: ' . implode(', ', $schemaResult['errors']));
        }
        writeLog("SKR-Schema '$skr' in '$dbName' eingespielt", true, DLOG_INF);

        // ── 7. CRM-Upstall auf neuer DB ausführen ──
        $crmSchemaFile = __DIR__ . '/../../upstall/crm/company_schema.sql';
        $csvFiles = ['auth' => [], 'company' => []];

        $crmCsvDir = __DIR__ . '/../../upstall/crm/company_data/';
        if (is_dir($crmCsvDir)) {
            $csvFilesFound = glob($crmCsvDir . '*.csv');
            foreach ($csvFilesFound as $csvFile) {
                $csvFiles['company'][] = $csvFile;
            }
        }

        $upstallResult = updateDatabaseSchema([$crmSchemaFile], $csvFiles, false, $newDb);
        if (!$upstallResult['success']) {
            writeLog("CRM-Upstall Warnungen für '$dbName': " . implode(', ', $upstallResult['errors']), true, DLOG_WRN);
        }
        writeLog("CRM-Upstall auf '$dbName' ausgeführt", true, DLOG_INF);

    } catch (Exception $e) {
        // Datenbank wieder löschen bei Fehler
        try {
            $authPdo->exec("DROP DATABASE IF EXISTS {$quotedDbName}");
        } catch (PDOException $dropEx) {
            writeLog("Konnte fehlgeschlagene DB '$dbName' nicht löschen: " . $dropEx->getMessage(), true, DLOG_ERR);
        }
        resultInfo(false, 'SCHEMA_ERROR', $e->getMessage());
        return;
    }

    // ── 8. Firma in auth.clients registrieren ──
    try {
        $auth->execute(
            "INSERT INTO auth.clients (name, dbhost, dbport, dbname, dbuser, dbpasswd)
             VALUES (:name, :dbhost, :dbport, :dbname, :dbuser, :dbpasswd)",
            [
                ':name' => $companyName,
                ':dbhost' => $dbHost,
                ':dbport' => $dbPort,
                ':dbname' => $dbName,
                ':dbuser' => $dbUser,
                ':dbpasswd' => $dbPass
            ]
        );
        writeLog("Firma '$companyName' in auth.clients registriert (DB: $dbName)", true, DLOG_INF);
    } catch (PDOException $e) {
        resultInfo(false, 'AUTH_ERROR', 'Fehler beim Registrieren der Firma: ' . $e->getMessage());
        return;
    }

    resultInfo(true, 'Firma erfolgreich angelegt', [
        'companyName' => $companyName,
        'dbName' => $dbName,
        'skr' => $skr
    ]);
}
