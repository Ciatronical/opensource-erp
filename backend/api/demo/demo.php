<?php
// backend/api/demo/demo.php

/**
 * Setzt die Demo-Datenbank auf den Ausgangszustand zurück
 *
 * Stellt den pg_dump-Snapshot wieder her, der beim Container-Start
 * erstellt wurde, und löscht alle aktiven Sessions.
 *
 * @param array $data Eingabedaten (wird nicht verwendet)
 * @testdata {}
 */
function resetDemo($data) {
    if (!defined('DEMO_MODE') || !DEMO_MODE) {
        resultInfo(false, 'DEMO_NOT_ENABLED', 'Demo-Modus ist nicht aktiviert');
        return;
    }

    $snapshotFile = __DIR__ . '/../../data/demo_snapshot.sql';
    if (!file_exists($snapshotFile)) {
        resultInfo(false, 'SNAPSHOT_NOT_FOUND', 'Demo-Snapshot nicht gefunden');
        return;
    }

    $dbHost = DB_HOST;
    $dbUser = DB_AUTH_USER;
    $dbPass = DB_AUTH_PASS;
    $dbName = DEMO_COMPANY_DB;

    if (empty($dbName)) {
        resultInfo(false, 'CONFIG_ERROR', 'Demo company_db nicht konfiguriert');
        return;
    }

    // Company-DB aus Snapshot wiederherstellen
    $cmd = sprintf(
        'PGPASSWORD=%s psql -h %s -U %s -d %s -f %s 2>&1',
        escapeshellarg($dbPass),
        escapeshellarg($dbHost),
        escapeshellarg($dbUser),
        escapeshellarg($dbName),
        escapeshellarg($snapshotFile)
    );

    exec($cmd, $output, $returnCode);

    if ($returnCode !== 0) {
        // writeLog('Demo-Reset fehlgeschlagen: ' . implode("\n", $output));
        resultInfo(false, 'RESET_FAILED', 'Datenbank-Reset fehlgeschlagen');
        return;
    }

    // Alle Sessions löschen (alle Benutzer werden abgemeldet)
    $auth = DbhAuth::begin();
    $auth->execute("DELETE FROM auth.session_oserp", []);

    // writeLog('Demo-Datenbank erfolgreich zurückgesetzt');
    resultInfo(true, 'DEMO_RESET_SUCCESS');
}
