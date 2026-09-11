<?php
// backend/api/lib/upstall.php
//
// Erkennt, ob die Upstall-Dateien neuer sind als das Schema einer
// Firmen-Datenbank.
//
// Der Login loeste das Update bisher nur aus, wenn eine seiner eigenen
// Abfragen an einer fehlenden Spalte oder Tabelle scheiterte. Aenderungen, die
// der Login nicht beruehrt — eine Spalte in einer Erweiterungstabelle, neue
// Einstellungszeilen —, blieben unbemerkt, bis irgendwo spaeter ein Fehler
// auftrat.
//
// Deshalb eine Pruefsumme je Upstall-Verzeichnis, gespeichert als Zeile in
// defaults_oserp (upstall_checksum_<verzeichnis>). Das Update schreibt sie
// nach einem erfolgreichen Lauf, der Login vergleicht. Keine Schemaaenderung:
// defaults_oserp gibt es in jeder Firmen-Datenbank mit CRM-Basis.

/** Basisverzeichnis der Upstall-Dateien */
function upstallBaseDir(): string {
    return dirname(__DIR__, 2).'/upstall';
}

/**
 * Pruefsumme der Dateien, die das Update aus einem Verzeichnis anwendet
 *
 * Genau die Dateien, die updateAllDatabases() liest: auth_schema.sql,
 * company_schema.sql und die CSV-Daten. Andere Dateien im Verzeichnis
 * (Sicherungskopien des Editors, anpr_schema.sql) sollen kein Update ausloesen.
 *
 * @param string $verzeichnis Name des Upstall-Verzeichnisses, z.B. 'crm' oder 'shop'
 * @return string SHA-256, leer wenn es das Verzeichnis nicht gibt
 */
function upstallChecksum(string $verzeichnis): string {
    $basis = upstallBaseDir().'/'.$verzeichnis;
    if (!is_dir($basis)) {
        return '';
    }

    $dateien = [];
    foreach (['auth_schema.sql', 'company_schema.sql'] as $name) {
        if (is_file($basis.'/'.$name)) {
            $dateien[] = $basis.'/'.$name;
        }
    }
    foreach (['auth_data', 'company_data'] as $unterverzeichnis) {
        foreach (glob($basis.'/'.$unterverzeichnis.'/*.csv') ?: [] as $csv) {
            $dateien[] = $csv;
        }
    }
    sort($dateien);

    // Der Dateiname geht mit ein: eine umbenannte Datei ist eine Aenderung
    $summe = hash_init('sha256');
    foreach ($dateien as $datei) {
        hash_update($summe, substr($datei, strlen($basis))."\0");
        hash_update_file($summe, $datei);
    }
    return hash_final($summe);
}

/**
 * Die Upstall-Verzeichnisse einer Firmen-Datenbank: CRM-Basis und aktive Erweiterungen
 *
 * Dieselbe Auswahl wie in updateAllDatabases(), sonst verglichen Login und
 * Update verschiedene Dinge.
 *
 * @param object $db Company-Datenbankverbindung
 * @return string[]
 */
function upstallDirsFor($db): array {
    $verzeichnisse = ['crm'];
    foreach (getActiveExtensions($db) as $erweiterung) {
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $erweiterung) && is_dir(upstallBaseDir().'/'.$erweiterung)) {
            $verzeichnisse[] = $erweiterung;
        }
    }
    return $verzeichnisse;
}

/**
 * Muss die Firmen-Datenbank aktualisiert werden?
 *
 * true, sobald eine Pruefsumme fehlt oder abweicht. Scheitert die Pruefung
 * selbst — etwa weil defaults_oserp oder extensions_oserp noch fehlen —, ist
 * die Antwort ebenfalls true: genau das behebt ein Update.
 *
 * @param object $db Company-Datenbankverbindung
 * @return bool
 */
function upstallUpdateNeeded($db): bool {
    try {
        $gespeichert = [];
        foreach ($db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'upstall_checksum_%'") as $zeile) {
            $gespeichert[$zeile['key']] = (string)$zeile['value'];
        }

        foreach (upstallDirsFor($db) as $verzeichnis) {
            if (($gespeichert['upstall_checksum_'.$verzeichnis] ?? '') !== upstallChecksum($verzeichnis)) {
                return true;
            }
        }
        return false;
    } catch (Throwable $e) {
        writeLog('Pruefsummen der Upstall-Dateien nicht lesbar: '.$e->getMessage(), true, DLOG_WRN);
        return true;
    }
}

/**
 * Haelt fest, auf welchem Stand der Upstall-Dateien die Datenbank jetzt ist
 *
 * Nur nach einem erfolgreichen, echten Lauf aufrufen — nicht nach einer
 * Vorschau und nicht nach einem Fehler, sonst gilt ein halbes Update als
 * vollstaendig.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string[] $verzeichnisse Angewendete Upstall-Verzeichnisse
 * @return void
 */
function upstallStoreChecksums($db, array $verzeichnisse): void {
    foreach ($verzeichnisse as $verzeichnis) {
        $db->execute(
            "INSERT INTO defaults_oserp (key, value) VALUES (:key, :value)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
            [':key' => 'upstall_checksum_'.$verzeichnis, ':value' => upstallChecksum($verzeichnis)]
        );
    }
}
