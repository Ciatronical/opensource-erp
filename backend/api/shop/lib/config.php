<?php
// backend/api/shop/lib/config.php
//
// Die Einstellungen der Shop-Erweiterung. Ersetzt config.php und passwd.php
// der Kivitendo-Bridge: dort standen rund 60 Konstanten in zwei Dateien je
// Shop-Instanz, hier stehen sie als shop_*-Schlüssel in defaults_oserp und
// sind damit pro Mandant getrennt und im Admin-Panel pflegbar.
//
// Gepflegt werden sie über den Einstellungen-Tab (Erweiterungen -> Shop), der
// wie alle anderen Tabs über saveCrmDefaults schreibt. Die Vorgabewerte legt
// backend/upstall/shop/company_schema.sql an.

/**
 * Liest alle Einstellungen der Shop-Erweiterung
 *
 * Eine Abfrage für alle Werte, danach für die Dauer des Requests gehalten:
 * fast jede Fachfunktion braucht mindestens einen Wert, und die Tabelle
 * ändert sich innerhalb eines Aufrufs nicht.
 *
 * Der Zwischenspeicher hängt an der übergebenen Verbindung. Ein Aufruf mit
 * einer anderen Verbindung (anderer Mandant) liest neu — sonst trüge ein
 * Durchlauf über mehrere Mandanten die Werte des ersten weiter.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Schlüssel ohne Präfix-Verlust, also z.B. ['shop_base_url' => '...']
 */
function shopConfig($db): array {
    static $gehalten = [];

    $kennung = spl_object_id($db);
    if (isset($gehalten[$kennung])) {
        return $gehalten[$kennung];
    }

    // Der Unterstrich ist in LIKE ein Platzhalter für ein Zeichen und gehört
    // deshalb maskiert — ohne ESCAPE träfe 'shop_%' auch 'shopping_...'.
    $zeile = $db->getOne(
        "SELECT COALESCE(json_object_agg(key, value), '{}'::json) AS config
           FROM defaults_oserp
          WHERE key LIKE 'shop\\_%' ESCAPE '\\'",
        []
    );

    $gehalten[$kennung] = json_decode($zeile['config'] ?? '{}', true) ?: [];
    return $gehalten[$kennung];
}

/**
 * Ein einzelner Einstellungswert als Zeichenkette
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $key Schlüssel, z.B. 'shop_base_url'
 * @param string $default Rückfallwert, wenn der Schlüssel fehlt oder leer ist
 * @return string
 */
function shopConfigValue($db, string $key, string $default = ''): string {
    $wert = shopConfig($db)[$key] ?? null;
    return (null === $wert || '' === trim((string)$wert)) ? $default : (string)$wert;
}

/**
 * Ein Einstellungswert, der gesetzt sein muss
 *
 * Für Werte, ohne die der Vorgang nicht sinnvoll weiterlaufen kann — die
 * PayPal-Zugangsdaten etwa. Ein leerer Wert ist dort kein Standardfall,
 * sondern eine unfertige Einrichtung, und die Meldung nennt den Schlüssel.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $key Schlüssel
 * @return string
 * @throws ApiError SHOP_CONFIG_MISSING wenn der Wert fehlt oder leer ist
 */
function shopConfigRequire($db, string $key): string {
    $wert = shopConfigValue($db, $key);
    if ('' === $wert) {
        throw new ApiError(
            "SHOP_CONFIG_MISSING",
            "Die Shop-Einstellung '$key' ist nicht gesetzt (Einstellungen -> Erweiterungen -> Shop)"
        );
    }
    return $wert;
}

/**
 * Ein Einstellungswert als Wahrheitswert
 *
 * Die Oberfläche speichert Wahrheitswerte je nach Weg als 't', 'true', '1'
 * oder als leeren Text. Alles andere gilt als "aus".
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $key Schlüssel
 * @param bool $default Rückfallwert, wenn der Schlüssel fehlt
 * @return bool
 */
function shopConfigBool($db, string $key, bool $default = false): bool {
    $wert = shopConfig($db)[$key] ?? null;
    if (null === $wert || '' === trim((string)$wert)) {
        return $default;
    }
    return in_array(strtolower(trim((string)$wert)), ['t', 'true', '1', 'y', 'yes'], true);
}

/**
 * Ein Einstellungswert als Ganzzahl
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $key Schlüssel
 * @param int $default Rückfallwert, wenn der Schlüssel fehlt oder keine Zahl ist
 * @return int
 */
function shopConfigInt($db, string $key, int $default = 0): int {
    $wert = filter_var(shopConfigValue($db, $key), FILTER_VALIDATE_INT);
    return false === $wert ? $default : $wert;
}

/**
 * Ein Einstellungswert als Kommazahl
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $key Schlüssel
 * @param float $default Rückfallwert, wenn der Schlüssel fehlt oder keine Zahl ist
 * @return float
 */
function shopConfigFloat($db, string $key, float $default = 0.0): float {
    $wert = filter_var(shopConfigValue($db, $key), FILTER_VALIDATE_FLOAT);
    return false === $wert ? $default : $wert;
}
