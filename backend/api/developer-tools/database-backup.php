<?php
// backend/api/developer-tools/database-backup.php

/**
 * Erstellt ein automatisches Backup einer Datenbank (ohne API-Ausgabe)
 *
 * Wiederverwendbare Hilfsfunktion für automatische Backups,
 * z.B. vor Schema-Updates. Gibt Ergebnis als Array zurück
 * statt JSON via resultInfo().
 *
 * @param string $database 'company' oder 'auth'
 * @param string|null $backupName Optionaler Name (ohne Endung), sonst automatisch generiert
 * @return array ['success' => bool, 'filename' => string|null, 'error' => string|null]
 */
function createAutoBackup($database = 'company', $backupName = null) {
    $context = getDatabaseContext($database);

    if (!$context) {
        return ['success' => false, 'filename' => null, 'error' => 'Keine gültige Session gefunden'];
    }

    $dbHost = $context['dbhost'];
    $dbPort = $context['dbport'];
    $dbName = $context['dbname'];
    $dbUser = $context['dbuser'];
    $dbPass = $context['dbpasswd'];

    $backupDir = ensureBackupDir($dbName);
    if (!$backupDir) {
        return ['success' => false, 'filename' => null, 'error' => 'Backup-Verzeichnis konnte nicht erstellt werden'];
    }

    if ($backupName) {
        $backupName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $backupName);
        $filename = $backupName . '.sql.gz';
    } else {
        $filename = 'auto_vor_update_' . $dbName . '_' . date('Y-m-d_H-i-s') . '.sql.gz';
    }

    $filepath = $backupDir . $filename;

    putenv("PGPASSWORD=$dbPass");

    $command = sprintf(
        'pg_dump -h %s -p %s -U %s -d %s | gzip > %s 2>&1',
        escapeshellarg($dbHost),
        escapeshellarg($dbPort),
        escapeshellarg($dbUser),
        escapeshellarg($dbName),
        escapeshellarg($filepath)
    );

    exec($command, $output, $returnCode);
    putenv("PGPASSWORD");

    if ($returnCode !== 0) {
        $errorDetails = implode("\n", $output);
        writeLog("Auto-Backup fehlgeschlagen für $dbName (Code: $returnCode): $errorDetails", true, DLOG_ERR);
        return ['success' => false, 'filename' => null, 'error' => "pg_dump fehlgeschlagen (Code: $returnCode)"];
    }

    if (!file_exists($filepath) || filesize($filepath) == 0) {
        writeLog("Auto-Backup Datei nicht erstellt oder leer: $filepath", true, DLOG_ERR);
        return ['success' => false, 'filename' => null, 'error' => 'Backup-Datei wurde nicht erstellt oder ist leer'];
    }

    writeLog("Auto-Backup erstellt: $filepath (" . filesize($filepath) . " Bytes)", true, DLOG_INF);
    return ['success' => true, 'filename' => $filename, 'error' => null];
}

/**
 * Erstellt ein automatisches Backup einer Datenbank mit expliziten Credentials
 *
 * Wiederverwendbare Hilfsfunktion für automatische Backups beliebiger Datenbanken,
 * unabhängig von der aktuellen Session. Wird z.B. beim Update aller Mandanten verwendet.
 *
 * @param array $dbCredentials Array mit Keys: dbhost, dbport, dbname, dbuser, dbpasswd
 * @param string|null $backupName Optionaler Name (ohne Endung), sonst automatisch generiert
 * @return array ['success' => bool, 'filename' => string|null, 'error' => string|null]
 */
function createAutoBackupForClient($dbCredentials, $backupName = null) {
    $dbHost = $dbCredentials['dbhost'];
    $dbPort = $dbCredentials['dbport'];
    $dbName = $dbCredentials['dbname'];
    $dbUser = $dbCredentials['dbuser'];
    $dbPass = $dbCredentials['dbpasswd'];

    $backupDir = ensureBackupDir($dbName);
    if (!$backupDir) {
        return ['success' => false, 'filename' => null, 'error' => "Backup-Verzeichnis konnte nicht erstellt werden fuer $dbName"];
    }

    if ($backupName) {
        $backupName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $backupName);
        $filename = $backupName . '.sql.gz';
    } else {
        $filename = 'auto_vor_update_' . $dbName . '_' . date('Y-m-d_H-i-s') . '.sql.gz';
    }

    $filepath = $backupDir . $filename;

    putenv("PGPASSWORD=$dbPass");

    $command = sprintf(
        'pg_dump -h %s -p %s -U %s -d %s | gzip > %s 2>&1',
        escapeshellarg($dbHost),
        escapeshellarg($dbPort),
        escapeshellarg($dbUser),
        escapeshellarg($dbName),
        escapeshellarg($filepath)
    );

    exec($command, $output, $returnCode);
    putenv("PGPASSWORD");

    if ($returnCode !== 0) {
        $errorDetails = implode("\n", $output);
        writeLog("Auto-Backup fehlgeschlagen fuer $dbName (Code: $returnCode): $errorDetails", true, DLOG_ERR);
        return ['success' => false, 'filename' => null, 'error' => "pg_dump fehlgeschlagen fuer $dbName (Code: $returnCode)"];
    }

    if (!file_exists($filepath) || filesize($filepath) == 0) {
        writeLog("Auto-Backup Datei nicht erstellt oder leer: $filepath", true, DLOG_ERR);
        return ['success' => false, 'filename' => null, 'error' => "Backup-Datei wurde nicht erstellt oder ist leer: $dbName"];
    }

    writeLog("Auto-Backup erstellt: $filepath (" . filesize($filepath) . " Bytes)", true, DLOG_INF);
    return ['success' => true, 'filename' => $filename, 'error' => null];
}

/**
 * Stellt sicher, dass das Backup-Verzeichnis für eine DB existiert
 *
 * @param string $dbName Name der Datenbank (z.B. "startup" oder "kivitendo_auth")
 * @return string Vollständiger Pfad zum Backup-Verzeichnis
 */
function ensureBackupDir($dbName) {
    $backupDir = BACKUP_BASE_DIR . $dbName . '/';
    if (!file_exists($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            // writeLog("ERROR: Backup-Verzeichnis konnte nicht erstellt werden: $backupDir");
            return false;
        }
        // writeLog("INFO: Backup-Verzeichnis erstellt: $backupDir");
    }
    return $backupDir;
}

/**
 * Holt die Datenbankverbindungsdetails basierend auf database-Parameter
 *
 * Auth-DB: Nutzt Config-Konstanten direkt (keine Session nötig)
 * Company-DB: Benötigt gültige Session für Client-Zuordnung
 */
function getDatabaseContext($database = 'company') {
    if ($database === 'auth') {
        // Auth-DB: Credentials direkt aus Config-Konstanten (keine Session nötig)
        return [
            'dbhost' => DB_HOST,
            'dbport' => DB_PORT,
            'dbname' => DB_AUTH_NAME,
            'dbuser' => DB_AUTH_USER,
            'dbpasswd' => DB_AUTH_PASS
        ];
    }

    // Company-DB: Session benötigt für Client-Zuordnung
    $auth = DbhAuth::begin();

    $query = "SELECT c.dbhost, c.dbport, c.dbname, c.dbuser, c.dbpasswd
              FROM auth.session_oserp s
              JOIN auth.user u ON s.user_id = u.id
              JOIN auth.clients c ON s.client_id = c.id
              WHERE s.session_id = :session_id";

    $context = $auth->getOne($query, [':session_id' => $auth->getCookie()]);

    if (!$context) {
        return null;
    }

    return $context;
}

/**
 * Gibt die Liste aller verfügbaren Backups zurück
 * @testdata {}
 */
function getBackupList($data) {
    $database = isset($data['database']) ? $data['database'] : 'company';
    $context = getDatabaseContext($database);

    if (!$context) {
        // writeLog("ERROR: Keine gültige Session gefunden bei getBackupList");
        resultInfo(false, 'Keine gültige Session gefunden');
        return;
    }

    $dbName = $context['dbname'];
    $backupDir = ensureBackupDir($dbName);

    if (!$backupDir) {
        resultInfo(false, 'Backup-Verzeichnis konnte nicht erstellt werden');
        return;
    }

    $backups = [];

    // Lese alle .sql und .sql.gz Dateien
    $files = glob($backupDir . '*.sql*');

    foreach ($files as $filepath) {
        $filename = basename($filepath);
        $filesize = filesize($filepath);
        $filetime = filemtime($filepath);

        // Formatiere Dateigröße
        if ($filesize < 1024) {
            $size = $filesize . ' B';
        } elseif ($filesize < 1048576) {
            $size = round($filesize / 1024, 2) . ' KB';
        } else {
            $size = round($filesize / 1048576, 2) . ' MB';
        }

        // Formatiere Datum
        $date = date('d.m.Y H:i', $filetime);

        // Entferne Dateiendung für Display-Name
        $displayName = preg_replace('/\.(sql|sql\.gz)$/', '', $filename);

        $backups[] = [
            'filename' => $filename,
            'display_name' => $displayName,
            'size' => $size,
            'date' => $date,
            'timestamp' => $filetime
        ];
    }

    // Sortiere nach Datum (neueste zuerst)
    usort($backups, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });

    resultInfo(true, '', ['backups' => $backups]);
}

/**
 * Erstellt ein neues Datenbank-Backup mit pg_dump
 */
function createDatabaseBackup($data) {
    $database = isset($data['database']) ? $data['database'] : 'company';
    $context = getDatabaseContext($database);

    if (!$context) {
        // writeLog("ERROR: Keine gültige Session gefunden bei createDatabaseBackup");
        resultInfo(false, 'Keine gültige Session gefunden');
        return;
    }

    $dbHost = $context['dbhost'];
    $dbPort = $context['dbport'];
    $dbName = $context['dbname'];
    $dbUser = $context['dbuser'];
    $dbPass = $context['dbpasswd'];

    $backupDir = ensureBackupDir($dbName);

    // Generiere Dateinamen
    $backupName = $data['name'] ?? null;
    if ($backupName) {
        $backupName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $backupName);
        $filename = $backupName . '.sql.gz';
    } else {
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql.gz';
    }

    $filepath = $backupDir . $filename;

    // Setze Passwort als Umgebungsvariable
    putenv("PGPASSWORD=$dbPass");

    // pg_dump Befehl (mit gzip Kompression)
    $command = sprintf(
        'pg_dump -h %s -p %s -U %s -d %s | gzip > %s 2>&1',
        escapeshellarg($dbHost),
        escapeshellarg($dbPort),
        escapeshellarg($dbUser),
        escapeshellarg($dbName),
        escapeshellarg($filepath)
    );

    exec($command, $output, $returnCode);
    putenv("PGPASSWORD");

    if ($returnCode !== 0) {
        $errorDetails = implode("\n", $output);
        // writeLog("ERROR: pg_dump fehlgeschlagen (Code: $returnCode)");
        // writeLog("Output: " . $errorDetails);
        resultInfo(false, 'Backup-Erstellung fehlgeschlagen', null, $errorDetails);
        return;
    }

    if (!file_exists($filepath) || filesize($filepath) == 0) {
        // writeLog("ERROR: Backup-Datei nicht erstellt oder leer: $filepath");
        resultInfo(false, 'Backup-Datei wurde nicht erstellt oder ist leer');
        return;
    }

    $filesize = filesize($filepath);

    resultInfo(true, 'Backup erfolgreich erstellt: ' . $filename, [
        'filename' => $filename,
        'size' => $filesize
    ]);
}

/**
 * Stellt ein Datenbank-Backup wieder her
 */
function restoreDatabaseBackup($data) {
    if (!isset($data['filename'])) {
        // writeLog("ERROR: Kein Dateiname für Wiederherstellung angegeben");
        resultInfo(false, 'Kein Dateiname angegeben');
        return;
    }

    $database = isset($data['database']) ? $data['database'] : 'company';
    $context = getDatabaseContext($database);

    if (!$context) {
        // writeLog("ERROR: Keine gültige Session gefunden bei restoreDatabaseBackup");
        resultInfo(false, 'Keine gültige Session gefunden');
        return;
    }

    $dbHost = $context['dbhost'];
    $dbPort = $context['dbport'];
    $dbName = $context['dbname'];
    $dbUser = $context['dbuser'];
    $dbPass = $context['dbpasswd'];

    $backupDir = ensureBackupDir($dbName);
    $filename = $data['filename'];
    $filepath = $backupDir . $filename;

    // Validiere Dateiname
    if (!preg_match('/^[a-zA-Z0-9._-]+\.(sql|sql\.gz)$/', $filename)) {
        // writeLog("ERROR: Ungültiger Dateiname: $filename");
        resultInfo(false, 'Ungültiger Dateiname');
        return;
    }

    if (!file_exists($filepath)) {
        // writeLog("ERROR: Backup-Datei nicht gefunden: $filepath");
        resultInfo(false, 'Backup-Datei nicht gefunden');
        return;
    }

    putenv("PGPASSWORD=$dbPass");

    // SCHRITT 0: Automatisches Backup VOR Wiederherstellung
    $autoBackupFilename = 'automatisches_vor_dem_restore_' . $dbName . '_' . date('Y-m-d_H-i-s') . '.sql.gz';
    $autoBackupPath = $backupDir . $autoBackupFilename;

    $autoBackupCommand = sprintf(
        'pg_dump -h %s -p %s -U %s -d %s | gzip > %s 2>&1',
        escapeshellarg($dbHost),
        escapeshellarg($dbPort),
        escapeshellarg($dbUser),
        escapeshellarg($dbName),
        escapeshellarg($autoBackupPath)
    );

    exec($autoBackupCommand, $autoBackupOutput, $autoBackupCode);

    if ($autoBackupCode !== 0 || !file_exists($autoBackupPath) || filesize($autoBackupPath) == 0) {
        putenv("PGPASSWORD");
        resultInfo(false, 'Automatisches Backup vor Wiederherstellung fehlgeschlagen');
        return;
    }

    // SCHRITT 1: Terminiere alle Verbindungen
    $terminateCommand = sprintf(
        "psql -h %s -p %s -U %s -d postgres -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%s' AND pid <> pg_backend_pid();\" 2>&1",
        escapeshellarg($dbHost),
        escapeshellarg($dbPort),
        escapeshellarg($dbUser),
        $dbName
    );

    exec($terminateCommand, $terminateOutput, $terminateCode);

    // SCHRITT 2: Lösche Datenbank
    $dropCommand = sprintf(
        'psql -h %s -p %s -U %s -d postgres -c "DROP DATABASE IF EXISTS %s;" 2>&1',
        escapeshellarg($dbHost),
        escapeshellarg($dbPort),
        escapeshellarg($dbUser),
        $dbName
    );

    exec($dropCommand, $dropOutput, $dropCode);

    if ($dropCode !== 0) {
        putenv("PGPASSWORD");
        resultInfo(false, 'Datenbank konnte nicht gelöscht werden');
        return;
    }

    // SCHRITT 3: Erstelle neue Datenbank
    $createCommand = sprintf(
        'psql -h %s -p %s -U %s -d postgres -c "CREATE DATABASE %s;" 2>&1',
        escapeshellarg($dbHost),
        escapeshellarg($dbPort),
        escapeshellarg($dbUser),
        $dbName
    );

    exec($createCommand, $createOutput, $createCode);

    if ($createCode !== 0) {
        putenv("PGPASSWORD");
        resultInfo(false, 'Datenbank konnte nicht erstellt werden');
        return;
    }

    // SCHRITT 4: Restore Backup
    if (strpos($filename, '.gz') !== false) {
        $command = sprintf(
            'gunzip -c %s | psql -h %s -p %s -U %s -d %s 2>&1',
            escapeshellarg($filepath),
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbName)
        );
    } else {
        $command = sprintf(
            'psql -h %s -p %s -U %s -d %s -f %s 2>&1',
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbName),
            escapeshellarg($filepath)
        );
    }

    exec($command, $output, $returnCode);
    putenv("PGPASSWORD");

    if ($returnCode !== 0) {
        // writeLog("ERROR: Restore fehlgeschlagen (Code: $returnCode)");
        resultInfo(false, 'Wiederherstellung fehlgeschlagen');
        return;
    }

    resultInfo(true, 'Backup erfolgreich wiederhergestellt: ' . $filename, [
        'auto_backup_name' => $autoBackupFilename,
        'restored_backup' => $filename
    ]);
}

/**
 * Löscht ein Datenbank-Backup
 */
function deleteDatabaseBackup($data) {
    if (!isset($data['filename'])) {
        // writeLog("ERROR: Kein Dateiname zum Löschen angegeben");
        resultInfo(false, 'Kein Dateiname angegeben');
        return;
    }

    $database = isset($data['database']) ? $data['database'] : 'company';
    $context = getDatabaseContext($database);

    if (!$context) {
        // writeLog("ERROR: Keine gültige Session gefunden bei deleteDatabaseBackup");
        resultInfo(false, 'Keine gültige Session gefunden');
        return;
    }

    $dbName = $context['dbname'];
    $backupDir = ensureBackupDir($dbName);
    $filename = $data['filename'];
    $filepath = $backupDir . $filename;

    // Validiere Dateiname
    if (!preg_match('/^[a-zA-Z0-9._-]+\.(sql|sql\.gz)$/', $filename)) {
        // writeLog("ERROR: Ungültiger Dateiname: $filename");
        resultInfo(false, 'Ungültiger Dateiname');
        return;
    }

    if (!file_exists($filepath)) {
        // writeLog("ERROR: Backup-Datei nicht gefunden: $filepath");
        resultInfo(false, 'Backup-Datei nicht gefunden');
        return;
    }

    if (!unlink($filepath)) {
        // writeLog("ERROR: Backup-Datei konnte nicht gelöscht werden: $filepath");
        resultInfo(false, 'Backup-Datei konnte nicht gelöscht werden');
        return;
    }

    resultInfo(true, 'Backup erfolgreich gelöscht: ' . $filename);
}

/**
 * Löscht alle Backups der aktuellen Datenbank
 */
function deleteAllBackups($data) {
    $database = isset($data['database']) ? $data['database'] : 'company';
    $context = getDatabaseContext($database);

    if (!$context) {
        // writeLog("ERROR: Keine gültige Session gefunden bei deleteAllBackups");
        resultInfo(false, 'Keine gültige Session gefunden');
        return;
    }

    $dbName = $context['dbname'];
    $backupDir = ensureBackupDir($dbName);

    // Hole alle Backup-Dateien
    $files = glob($backupDir . '*.sql*');
    $deletedCount = 0;
    $failedFiles = [];

    foreach ($files as $filepath) {
        $filename = basename($filepath);

        // Validiere Dateiname
        if (!preg_match('/^[a-zA-Z0-9._-]+\.(sql|sql\.gz)$/', $filename)) {
            continue;
        }

        if (unlink($filepath)) {
            $deletedCount++;
        } else {
            $failedFiles[] = $filename;
        }
    }

    if (!empty($failedFiles)) {
        // writeLog("WARNING: Einige Backups konnten nicht gelöscht werden: " . implode(', ', $failedFiles));
        resultInfo(false, "Nur $deletedCount von " . count($files) . " Backups gelöscht");
        return;
    }

    resultInfo(true, "Alle $deletedCount Backups erfolgreich gelöscht", [
        'deleted_count' => $deletedCount
    ]);
}

/**
 * Lädt ein Backup zum Download herunter
 */
function downloadDatabaseBackup($data) {
    $filename = $data['filename'] ?? $_GET['filename'] ?? null;
    $database = $data['database'] ?? $_GET['database'] ?? 'company';

    if (!$filename) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Kein Dateiname angegeben']);
        return;
    }

    // Validiere Dateiname
    if (!preg_match('/^[a-zA-Z0-9._-]+\.(sql|sql\.gz)$/', $filename)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültiger Dateiname']);
        return;
    }

    $context = getDatabaseContext($database);
    if (!$context) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Keine gültige Session']);
        return;
    }

    $dbName = $context['dbname'];
    $backupDir = ensureBackupDir($dbName);
    $filepath = $backupDir . $filename;

    if (!file_exists($filepath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Backup-Datei nicht gefunden']);
        return;
    }

    $contentType = (strpos($filename, '.gz') !== false) ? 'application/gzip' : 'text/plain';

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));

    readfile($filepath);
    exit;
}