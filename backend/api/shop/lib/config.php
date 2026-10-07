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
        'shop_job_retention_days'       => 'Erledigte Aufträge aufbewahren (Tage)',
        'shop_hugocms_url'              => 'Adresse von HugoCMS',
        'shop_hugocms_key'              => 'Schlüssel für HugoCMS',
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

// ── Einstellungen je Verkaufskanal (dev/shop-mehrere-kanaele.md) ──
//
// Ein Kanal ist eine Instanz seiner Art. Was nur für eine Instanz gilt —
// Adresse der Webseite, Shop-Schlüssel, PayPal, Mails —, steht in
// sales_channel_shop.settings und, wenn geheim, in sales_channel_secret_shop;
// die Namen ohne Präfix (base_url statt shop_base_url, Liste in
// shop_channel_setting_keys()). Was für den ganzen Mandanten gilt, bleibt in
// defaults_oserp und wird weiter über shopConfig*() gelesen.
//
// Übergang bis Schritt 5: Gepflegt werden die Werte noch im Reiter „Shop"
// unter ihren alten Schlüsseln; ein Trigger auf defaults_oserp überträgt jede
// Änderung sofort in den Standardkanal der Art.

/**
 * Alle Einstellungen eines Kanals, Geheimnisse eingeschlossen
 *
 * Eine Abfrage je Kanal, danach für die Dauer des Requests gehalten — wie
 * shopConfig(), und aus demselben Grund an der Verbindung.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal sales_channel_shop.id; 0 oder unbekannt = keine Einstellungen
 * @param bool $neu neu lesen — nach einem Schreibvorgang im selben Request
 *                  (Token-Cache des eBay-Kanals, letzter Abruf)
 * @return array Schlüssel ohne Präfix, dazu id, type, name, active
 */
function shopChannelConfig($db, int $kanal, bool $neu = false): array {
    static $gehalten = null;
    if (null === $gehalten) {
        $gehalten = new WeakMap();
    }
    if (!isset($gehalten[$db])) {
        $gehalten[$db] = [];
    }
    if (!$neu && isset($gehalten[$db][$kanal])) {
        return $gehalten[$db][$kanal];
    }

    $zeile = $kanal > 0 ? $db->getOne(
        "SELECT COALESCE(c.settings, '{}'::jsonb)
                || COALESCE((SELECT jsonb_object_agg(s.key, s.value)
                               FROM sales_channel_secret_shop s WHERE s.channel_id = c.id), '{}'::jsonb)
                || jsonb_build_object('id', c.id, 'type', c.type, 'name', c.name, 'active', c.active) AS config
           FROM sales_channel_shop c
          WHERE c.id = CAST(:kanal AS integer)",
        [':kanal' => $kanal]
    ) : null;

    $werte = json_decode($zeile['config'] ?? '{}', true) ?: [];
    $alle = $gehalten[$db];
    $alle[$kanal] = $werte;
    $gehalten[$db] = $alle;
    return $werte;
}

/**
 * Ein Einstellungswert eines Kanals als Zeichenkette
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal sales_channel_shop.id
 * @param string $key Schlüssel ohne Präfix, z.B. 'base_url'
 * @param string $default Rückfallwert, wenn der Schlüssel fehlt oder leer ist
 * @return string
 */
function shopChannelValue($db, int $kanal, string $key, string $default = ''): string {
    $wert = shopChannelConfig($db, $kanal)[$key] ?? null;
    return (null === $wert || '' === trim((string)$wert)) ? $default : (string)$wert;
}

/**
 * Ein Einstellungswert eines Kanals als Wahrheitswert
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal sales_channel_shop.id
 * @param string $key Schlüssel ohne Präfix
 * @param bool $default Rückfallwert, wenn der Schlüssel fehlt
 * @return bool
 */
function shopChannelBool($db, int $kanal, string $key, bool $default = false): bool {
    $wert = shopChannelConfig($db, $kanal)[$key] ?? null;
    if (null === $wert || '' === trim((string)$wert)) {
        return $default;
    }
    if (is_bool($wert)) {
        return $wert;
    }
    return in_array(strtolower(trim((string)$wert)), ['t', 'true', '1', 'y', 'yes'], true);
}

/**
 * Ein Einstellungswert eines Kanals, der gesetzt sein muss
 *
 * Die Meldung nennt das Feld und den Kanal — bei mehreren HugoShops sucht der
 * Benutzer sonst im falschen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal sales_channel_shop.id
 * @param string $key Schlüssel ohne Präfix
 * @return string
 * @throws ApiError SHOP_CONFIG_MISSING wenn der Wert fehlt oder leer ist
 */
function shopChannelRequire($db, int $kanal, string $key): string {
    $wert = shopChannelValue($db, $kanal, $key);
    if ('' === $wert) {
        // Die Beschriftungen stehen unter dem alten Schlüssel (shop_*)
        $feld = shopConfigLabel('shop_'.$key);
        $name = shopChannelValue($db, $kanal, 'name', (string)$kanal);
        throw new ApiError(
            "SHOP_CONFIG_MISSING",
            "Die Einstellung '".('shop_'.$key === $feld ? $key : $feld)."' des Verkaufskanals '$name' ist nicht gesetzt"
        );
    }
    return $wert;
}

/**
 * Der erste Kanal einer Art (shop_first_channel_id)
 *
 * Nur für Stellen, die bewusst irgendeinen Kanal der Art meinen — eine
 * Verwaltungsanfrage ohne Kanalangabe, den Zahlungsabgleich für alle
 * HugoShops. Alles andere bekommt den Kanal übergeben.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $art hugoshop oder ebay
 * @return int Kennung, 0 wenn es keinen Kanal der Art gibt
 */
function shopFirstChannelId($db, string $art): int {
    $zeile = $db->getOne("SELECT shop_first_channel_id(:art) AS id", [':art' => $art]);
    return (int)($zeile['id'] ?? 0);
}

/**
 * Verkauft der HugoShop gerade?
 *
 * Nein, wenn sein Verkaufskanal abgeschaltet ist (dev/shop-verkaufskanaele.md,
 * V8, V16). Der öffentliche Zugang lehnt dann alles ab, was einen neuen Kauf
 * beginnt (shopClosedActions).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal sales_channel_shop.id des HugoShops
 * @return bool
 */
function shopIsOpen($db, int $kanal): bool {
    $zeile = $db->getOne(
        "SELECT shop_active_channel_id(CAST(:kanal AS integer)) IS NOT NULL AS offen",
        [':kanal' => $kanal]
    );
    return in_array($zeile['offen'] ?? false, [true, 't', 1, '1'], true);
}

/**
 * Bucht die Waren einer Verkaufsrechnung aus dem Lager (O14, V28)
 *
 * Für Rechnungen aus HugoShop und eBay, nach dem Buchen ins Hauptbuch. Die
 * Regeln stehen in shop_book_stock(): Lagerplatz aus shop_stock_bin_id, nur
 * Waren, höchstens einmal je Rechnung, ohne Lagerplatz keine Buchung.
 *
 * Ein Fehler hier lässt die Bestellung nicht scheitern — Rechnung und
 * Zahlung stehen schon. Er wird protokolliert; die Ware ist dann von Hand
 * auszubuchen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return int Zahl der gebuchten Positionen
 */
function shopBookStock($db, int $arId): int {
    try {
        $zeile = $db->getOne("SELECT shop_book_stock(:ar_id) AS anzahl", [':ar_id' => $arId]);
        return (int)($zeile['anzahl'] ?? 0);
    } catch (\Throwable $e) {
        if (function_exists('writeLog')) {
            writeLog('[SHOP] Lagerbuchung für Rechnung '.$arId.' fehlgeschlagen: '.$e->getMessage(), true, DLOG_ERR);
        }
        return 0;
    }
}
