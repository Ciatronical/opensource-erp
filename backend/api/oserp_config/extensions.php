<?php
// backend/api/oserp_config/extensions.php

/**
 * Liefert alle verfügbaren Erweiterungen samt Aktivierungszustand
 *
 * Der Katalog wird aus dem Dateisystem gelesen: jedes Verzeichnis unter
 * backend/upstall/ mit einer extension.json ist eine Erweiterung. Verzeichnisse
 * ohne diese Datei (crm, skr03, skr04) sind keine und erscheinen nicht.
 *
 * @testdata {}
 */
function getExtensions($data) {
    $db = DbhCompany::begin();
    $active = getActiveExtensions($db);

    $catalog = [];
    foreach (glob(__DIR__ . '/../../upstall/*/extension.json') as $file) {
        $meta = json_decode(file_get_contents($file), true);
        if (!is_array($meta) || empty($meta['name'])) {
            writeLog("Ungültige extension.json: $file", true, DLOG_WRN);
            continue;
        }

        // Der Name muss dem Verzeichnis entsprechen — er wird als Pfad verwendet
        $dir = basename(dirname($file));
        if ($meta['name'] !== $dir) {
            writeLog("Name '{$meta['name']}' passt nicht zum Verzeichnis '$dir'", true, DLOG_WRN);
            continue;
        }

        $catalog[] = [
            'name'        => $dir,
            'title'       => $meta['title'] ?? $dir,
            'icon'        => $meta['icon'] ?? 'mdi-puzzle',
            'description' => $meta['description'] ?? '',
            'active'      => in_array($dir, $active, true)
        ];
    }

    usort($catalog, fn($a, $b) => strcasecmp($a['title'], $b['title']));

    resultInfo(true, '', ['results' => $catalog]);
}

/**
 * Setzt die aktiven Erweiterungen
 *
 * Nicht gelistete Erweiterungen werden deaktiviert. Die Daten einer
 * deaktivierten Erweiterung bleiben erhalten — beim erneuten Aktivieren ist
 * der alte Stand wieder da.
 *
 * Rechteprüfung bewusst wie im übrigen Konfigurations-Backend: keine. Wer die
 * Firmenkonfiguration öffnen darf, darf sie auch speichern.
 *
 * @param array $data['extensions'] Namen der zu aktivierenden Erweiterungen
 * @testdata {"extensions": ["lxcars"]}
 */
function saveExtensions($data) {
    $db = DbhCompany::begin();

    // Ohne Schema-Update gibt es die Tabelle noch nicht — dann lieber eine
    // verständliche Meldung als ein Datenbankfehler
    if (!hasExtensionsTable($db)) {
        resultInfo(false, 'SCHEMA_UPDATE_REQUIRED',
            'Die Tabelle extensions_oserp fehlt. Bitte zuerst das Schema-Update ausführen.');
        return;
    }

    // Nur Namen zulassen, die als Verzeichnisname taugen
    $names = array_values(array_unique(array_filter(
        $data['extensions'] ?? [],
        fn($n) => is_string($n) && preg_match('/^[a-zA-Z0-9_-]+$/', $n)
    )));

    $query = <<<SQL
        WITH wanted AS (
            SELECT unnest(string_to_array(NULLIF(:names, ''), ',')) AS extension
        ), activated AS (
            INSERT INTO extensions_oserp (extension, active)
            SELECT extension, true FROM wanted
            ON CONFLICT (extension) DO UPDATE SET active = true, mtime = now()
            RETURNING extension
        )
        UPDATE extensions_oserp
        SET active = false, mtime = now()
        WHERE active AND extension NOT IN (SELECT extension FROM wanted)
    SQL;

    $db->execute($query, [':names' => implode(',', $names)]);

    resultInfo(true, '', ['results' => $names]);
}
