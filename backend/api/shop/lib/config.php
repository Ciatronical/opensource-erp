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
 * WeakMap und nicht spl_object_id als Schlüssel: die Objektkennung wird nach
 * dem Freigeben eines Objekts neu vergeben. Eine frisch aufgebaute Verbindung
 * kann damit die Kennung einer zerstörten erben und bekäme deren Werte —
 * nachgemessen, genau das trat ein. WeakMap schlüsselt über das Objekt selbst
 * und hält es nicht am Leben.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Schlüssel ohne Präfix-Verlust, also z.B. ['shop_base_url' => '...']
 */
function shopConfig($db): array {
    static $gehalten = null;
    if (null === $gehalten) {
        $gehalten = new WeakMap();
    }

    if (isset($gehalten[$db])) {
        return $gehalten[$db];
    }

    // Der Unterstrich ist in LIKE ein Platzhalter für ein Zeichen und gehört
    // deshalb maskiert — ohne ESCAPE träfe 'shop_%' auch 'shopping_...'.
    $zeile = $db->getOne(
        "SELECT COALESCE(json_object_agg(key, value), '{}'::json) AS config
           FROM defaults_oserp
          WHERE key LIKE 'shop\\_%' ESCAPE '\\'",
        []
    );

    $gehalten[$db] = json_decode($zeile['config'] ?? '{}', true) ?: [];
    return $gehalten[$db];
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
 * Die Beschriftung eines Einstellungsfeldes, wie sie im Einstellungen-Tab steht
 *
 * Für Fehlermeldungen: mit 'shop_paypal_sandbox_client_id' kann nur ein
 * Entwickler etwas anfangen, der Benutzer sucht nach dem Feld, das er auf dem
 * Bildschirm sieht. Die Texte entsprechen crm_fields in
 * src/core/views/config/locales/de.json — ändert sich dort eine Beschriftung,
 * gehört sie hier nachgezogen.
 *
 * Enthalten sind nur die Schlüssel, die als Pflichtwert abgefragt werden.
 * Für alle anderen kommt der Schlüssel zurück.
 *
 * @param string $key Schlüssel, z.B. 'shop_paypal_sandbox_client_id'
 * @return string
 */
function shopConfigLabel(string $key): string {
    static $beschriftung = [
        'shop_paypal_sandbox_client_id' => 'PayPal Client-ID (Testumgebung)',
        'shop_paypal_sandbox_secret'    => 'PayPal Secret (Testumgebung)',
        'shop_paypal_live_client_id'    => 'PayPal Client-ID (Echtbetrieb)',
        'shop_paypal_live_secret'       => 'PayPal Secret (Echtbetrieb)',
        'shop_base_url'                 => 'Adresse der Shop-Webseite',
        'shop_withdrawal_mail_to'       => 'E-Mail-Adresse für Widerrufe',
    ];
    return $beschriftung[$key] ?? $key;
}

/**
 * Ein Einstellungswert, der gesetzt sein muss
 *
 * Für Werte, ohne die der Vorgang nicht sinnvoll weiterlaufen kann — die
 * PayPal-Zugangsdaten etwa. Ein leerer Wert ist dort kein Standardfall,
 * sondern eine unfertige Einrichtung, und die Meldung nennt das Feld.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $key Schlüssel
 * @return string
 * @throws ApiError SHOP_CONFIG_MISSING wenn der Wert fehlt oder leer ist
 */
function shopConfigRequire($db, string $key): string {
    $wert = shopConfigValue($db, $key);
    if ('' === $wert) {
        $feld = shopConfigLabel($key);
        throw new ApiError(
            "SHOP_CONFIG_MISSING",
            "Die Shop-Einstellung '$feld' ist nicht gesetzt (Einstellungen -> Erweiterungen -> Shop)"
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
