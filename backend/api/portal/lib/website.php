<?php
// backend/api/portal/lib/website.php
//
// Die Webseite des Kundenportals (website_oserp): eine Seite in HugoCMS, über
// die Kunden ihr Konto erreichen. Ihre Einstellungen liegen als jsonb in
// settings, die Geheimnisse (public_key, hugocms_key) in website_secret_oserp;
// welche Schlüssel es gibt, sagt website_setting_keys() in der Datenbank.
//
// Eine Erweiterung, die auf der Webseite läuft, hängt ihre eigene Zeile an:
// der HugoShop über sales_channel_shop.website_id. Was er je Webseite braucht
// (Adressmuster, PayPal), steht bei ihm, nicht hier.

/**
 * Alle Einstellungen einer Webseite, Geheimnisse eingeschlossen
 *
 * Eine Abfrage je Webseite, für die Dauer des Requests gehalten — an der
 * Verbindung, weil der Update-Lauf mehrere Mandanten nacheinander bedient.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website website_oserp.id; 0 oder unbekannt = keine Einstellungen
 * @param bool $neu neu lesen — nach einem Schreibvorgang im selben Request
 * @return array Schlüssel, dazu id, name, active
 */
function websiteConfig($db, int $website, bool $neu = false): array {
    static $gehalten = null;
    if (null === $gehalten) {
        $gehalten = new WeakMap();
    }
    if (!isset($gehalten[$db])) {
        $gehalten[$db] = [];
    }
    if (!$neu && isset($gehalten[$db][$website])) {
        return $gehalten[$db][$website];
    }

    $zeile = $website > 0 ? $db->getOne(
        "SELECT website_default_settings()
                || COALESCE(w.settings, '{}'::jsonb)
                || COALESCE((SELECT jsonb_object_agg(s.key, s.value)
                               FROM website_secret_oserp s WHERE s.website_id = w.id), '{}'::jsonb)
                || jsonb_build_object('id', w.id, 'name', w.name, 'active', w.active) AS config
           FROM website_oserp w
          WHERE w.id = CAST(:website AS integer)",
        [':website' => $website]
    ) : null;

    $werte = json_decode($zeile['config'] ?? '{}', true) ?: [];
    $alle = $gehalten[$db];
    $alle[$website] = $werte;
    $gehalten[$db] = $alle;
    return $werte;
}

/**
 * Ein Einstellungswert einer Webseite als Zeichenkette
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website website_oserp.id
 * @param string $key Schlüssel, z.B. 'base_url'
 * @param string $default Rückfallwert, wenn der Schlüssel fehlt oder leer ist
 * @return string
 */
function websiteValue($db, int $website, string $key, string $default = ''): string {
    $wert = websiteConfig($db, $website)[$key] ?? null;
    return (null === $wert || '' === trim((string)$wert)) ? $default : (string)$wert;
}

/**
 * Ein Einstellungswert einer Webseite als Wahrheitswert
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website website_oserp.id
 * @param string $key Schlüssel
 * @param bool $default Rückfallwert
 * @return bool
 */
function websiteBool($db, int $website, string $key, bool $default = false): bool {
    $wert = websiteConfig($db, $website)[$key] ?? null;
    if (null === $wert || '' === $wert) {
        return $default;
    }
    return in_array($wert, [true, 1, '1', 't', 'true', 'on', 'yes'], true);
}

/**
 * Ein Einstellungswert, der da sein muss
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website website_oserp.id
 * @param string $key Schlüssel
 * @return string
 * @throws ApiError WEBSITE_CONFIG_MISSING
 */
function websiteRequire($db, int $website, string $key): string {
    $wert = websiteValue($db, $website, $key);
    if ('' === $wert) {
        throw new ApiError(
            'WEBSITE_CONFIG_MISSING',
            'Die Webseite „'.websiteValue($db, $website, 'name', (string)$website).'“ hat keinen Wert für '.$key
        );
    }
    return $wert;
}

/**
 * Bietet die Webseite die Konto-Freischaltung für Bestandskunden an?
 *
 * Einstellung activation_enabled, Vorgabe aus. Aus heißt: kein Link auf
 * Anmelde- und Registrierseite, keine Seite „Konto freischalten“ im Paket,
 * und der öffentliche Zugang weist die Aktionen der Freischaltung ab.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return bool
 */
function websiteActivationEnabled($db, int $website): bool {
    return websiteBool($db, $website, 'activation_enabled', false);
}

/**
 * Ist die Webseite eingeschaltet?
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website website_oserp.id
 * @return bool
 */
function websiteIsActive($db, int $website): bool {
    return in_array(websiteConfig($db, $website)['active'] ?? false, [true, 't', 1, '1'], true);
}

/**
 * Die Schlüssel der Webseiten-Einstellungen aus der Datenbank
 *
 * @param object $db Company-Datenbankverbindung
 * @return array{key: string, secret: bool}[]
 */
function websiteSettingKeys($db): array {
    return array_map(
        fn(array $z) => ['key' => $z['key'], 'secret' => in_array($z['secret'], [true, 't', 1, '1'], true)],
        $db->getAll("SELECT key, secret FROM website_setting_keys()", []) ?: []
    );
}

/**
 * Adresse einer Seite der Webseite
 *
 * Die Seiten des Portals (Rechnung, Freischaltung) liegen auf der Webseite;
 * ihr Pfad ist dort frei wählbar und steht in den Einstellungen. Eine
 * vollständige Adresse gilt, wie sie ist; ein Pfad hängt an base_url.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website website_oserp.id
 * @param string $key Einstellung mit dem Pfad, etwa 'invoice_page'
 * @param string $vorgabe Pfad, wenn die Einstellung leer ist
 * @param array $query Parameter, die angehängt werden
 * @return string leer, wenn die Webseite keine Basisadresse hat
 */
/**
 * Pfad einer Portalseite ab der Wurzel der Webseite
 *
 * Eine Angabe ohne führenden Schrägstrich hängt am Verzeichnis des
 * Kundenportals (portal_path, Vorgabe /kunden/): „anmelden/“ wird zu
 * /kunden/anmelden/. Mit Schrägstrich steht sie für sich, eine vollständige
 * Adresse (https://…) bleibt, wie sie ist. Immer mit Schrägstrich am Ende.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param string $key Einstellung mit dem Pfad, etwa 'login_page'
 * @param string $vorgabe Rückfall, wenn die Einstellung leer ist (relativ oder absolut)
 * @return string
 */
function websitePagePath($db, int $website, string $key, string $vorgabe): string {
    $pfad = trim(websiteValue($db, $website, $key));
    if ('' === $pfad) {
        $pfad = $vorgabe;
    }
    if (!preg_match('~^(https?://|/)~i', $pfad)) {
        $verzeichnis = trim(websiteValue($db, $website, 'portal_path'));
        $verzeichnis = '/'.trim('' === $verzeichnis ? 'kunden' : $verzeichnis, '/');
        $pfad = ('/' === $verzeichnis ? '' : $verzeichnis).'/'.$pfad;
    }
    return str_ends_with($pfad, '/') ? $pfad : $pfad.'/';
}

function websitePageUrl($db, int $website, string $key, string $vorgabe, array $query = []): string {
    $basis = rtrim(trim(websiteValue($db, $website, 'base_url')), '/');
    if ('' === $basis) {
        return '';
    }
    $pfad = websitePagePath($db, $website, $key, $vorgabe);
    $adresse = preg_match('~^https?://~i', $pfad) ? $pfad : $basis.'/'.ltrim($pfad, '/');
    if (empty($query)) {
        return $adresse;
    }
    return $adresse.(str_contains($adresse, '?') ? '&' : '?').http_build_query($query);
}

/**
 * Adresse der Rechnungsseite einer Bestellung, für Links in Mails
 *
 * Der Rechnungslink (invoice_link_oserp.uuid) ersetzt die Anmeldung — so
 * kommen auch Gäste ohne Kundenkonto wieder auf die Seite.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website website_oserp.id
 * @param string $arLink Kennung aus invoice_link_oserp
 * @return string
 */
function websiteInvoicePageUrl($db, int $website, string $arLink): string {
    if ('' === $arLink) {
        return '';
    }
    return websitePageUrl($db, $website, 'invoice_page', 'rechnung/', ['link' => $arLink]);
}

/**
 * Adresse der Seite „Konto freischalten“ mit Kennung
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website website_oserp.id
 * @param string $token Kennung aus portal_activation_oserp
 * @return string
 */
function websiteActivatePageUrl($db, int $website, string $token): string {
    return websitePageUrl($db, $website, 'activate_page', 'freischalten/', ['token' => $token]);
}
