<?php
// backend/api/lib/extensions.php

/**
 * Prüft welche der beiden Herkunftstabellen in dieser Datenbank vorhanden sind
 *
 * Bewusst ohne Zwischenspeicher und immer gegen die übergebene Verbindung:
 * updateAllDatabases() arbeitet in einem Durchlauf nacheinander mit mehreren
 * Mandanten-Datenbanken, die unterschiedlich weit migriert sein können.
 *
 * Dass beide fehlen, ist kein theoretischer Fall: eine frisch angelegte
 * Mandanten-Datenbank hat vor dem ersten Schema-Update auch kein
 * defaults_oserp (siehe Logmeldung "relation defaults_oserp does not exist").
 *
 * @param object $db Datenbankverbindung
 * @return array{extensions: bool, defaults: bool}
 */
function extensionSourceTables($db): array {
    $row = $db->getOne(
        "SELECT to_regclass('public.extensions_oserp') AS ext,
                to_regclass('public.defaults_oserp')   AS def",
        []
    );
    return [
        'extensions' => !empty($row['ext']),
        'defaults'   => !empty($row['def']),
    ];
}

/**
 * Prüft ob die Tabelle extensions_oserp in dieser Datenbank vorhanden ist
 *
 * @param object $db Datenbankverbindung
 * @return bool
 */
function hasExtensionsTable($db): bool {
    return extensionSourceTables($db)['extensions'];
}

/**
 * Liefert den SQL-Ausdruck, der die aktiven Erweiterungen liest
 *
 * Übergangslösung für den Rollout: Solange extensions_oserp auf einem Mandanten
 * noch fehlt, wird der alte Einzelwert aus defaults_oserp gelesen. Der Tabellenname
 * darf in der Abfrage gar nicht auftauchen, wenn es die Tabelle nicht gibt — sonst
 * scheitert bereits das Parsen, nicht erst die Ausführung.
 *
 * Kann entfernt werden, sobald alle Mandanten das Schema-Update hatten.
 *
 * @param object $db Datenbankverbindung
 * @param bool $asJson true: json_agg-Ausdruck zum Einsetzen in die Session-Abfragen,
 *                     false: vollständiges SELECT mit Spalte "extension"
 * @return string SQL-Teilausdruck
 */
function extensionsSelectSql($db, bool $asJson = false): string {
    $sources = extensionSourceTables($db);

    if ($sources['extensions']) {
        return $asJson
            ? "(SELECT COALESCE(json_agg(extension ORDER BY extension), '[]'::json)
                FROM extensions_oserp WHERE active)"
            : 'SELECT extension FROM extensions_oserp WHERE active ORDER BY extension';
    }

    if ($sources['defaults']) {
        return $asJson
            ? "(SELECT COALESCE(json_agg(trim(value)), '[]'::json)
                FROM defaults_oserp
                WHERE key = 'features' AND trim(COALESCE(value, '')) <> '')"
            : "SELECT trim(value) AS extension FROM defaults_oserp
               WHERE key = 'features' AND trim(COALESCE(value, '')) <> ''";
    }

    // Datenbank noch vor dem ersten Schema-Update: keine Erweiterungen,
    // aber auch kein Fehler — die Anmeldung muss durchlaufen
    return $asJson ? "'[]'::json" : 'SELECT NULL::text AS extension WHERE false';
}

/**
 * Liefert die Namen aller aktiven Erweiterungen
 *
 * Erweiterungen sind eigenständige Module (LxCars und künftige). Der Name
 * entspricht dem Verzeichnis unter backend/upstall/, backend/api/ und
 * src/features/. Mehrere Erweiterungen können gleichzeitig aktiv sein.
 *
 * @param object $db Company-Datenbankverbindung
 * @return string[] Namen der aktiven Erweiterungen, alphabetisch sortiert
 */
function getActiveExtensions($db): array {
    $rows = $db->getAll(extensionsSelectSql($db), []);
    return array_column($rows ?: [], 'extension');
}

/**
 * Prüft ob eine bestimmte Erweiterung aktiv ist
 *
 * Exakter Vergleich — kein str_contains, damit sich Namen mit gemeinsamem
 * Wortbestandteil nicht gegenseitig treffen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $extension Name der Erweiterung
 * @return bool
 */
function isExtensionActive($db, string $extension): bool {
    return in_array($extension, getActiveExtensions($db), true);
}
