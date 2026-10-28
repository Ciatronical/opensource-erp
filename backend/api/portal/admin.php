<?php
// backend/api/portal/admin.php
//
// Zugang des Admin-Panels zum Kundenportal (dev/kundenportal.md): die
// Webseiten (website_oserp) mit ihren Einstellungen und Geheimnissen, die
// Verbindung zu HugoCMS, die Veröffentlichung des Pakets, die Freischaltung
// von Bestandskunden aus der Kundenakte.
//
// Recht: edit_shop_config („Create and edit webshops“) für alles, was die
// Webseiten betrifft; die Freischaltung aus der Kundenakte darf, wer Kunden
// bearbeiten darf.

/**
 * Firmen-Datenbank samt Haken der aktiven Erweiterungen
 *
 * Die Erweiterungen hängen sich an die Veröffentlichung (Paket, Merkmale,
 * Vorlagensätze); sie müssen geladen sein, bevor eine Fachfunktion läuft.
 *
 * @return object
 */
function portalAdminDb() {
    $db = DbhCompany::begin();
    portalExtensionsLoad($db);
    return $db;
}

/**
 * Webseiten des Kundenportals mit Einstellungen und Kennzahlen
 *
 * Geheimnisse kommen hier nicht mit — nur, ob sie hinterlegt sind
 * (secrets_set); ihre Werte holt getWebsiteSecrets in einem eigenen Aufruf.
 * Dazu die Schlüssel der Einstellungen (website_setting_keys), die
 * Vorlagensätze, der Vorschlag für die Adresse des Zugangs, der
 * Signaturschlüssel und — bei aktiver Shop-Erweiterung — die Kanäle je
 * Webseite.
 *
 * @return void
 * @testdata {}
 */
function getWebsites($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();

    // Ohne Shop-Erweiterung gibt es die Kanaltabelle nicht; der Name darf
    // dann nicht in der Abfrage stehen
    $mitShop = null !== ($db->getOne("SELECT to_regclass('sales_channel_shop') AS t")['t'] ?? null);
    $kanaele = $mitShop
        ? "(SELECT COALESCE(json_agg(json_build_object('id', c.id, 'name', c.name, 'type', c.type, 'active', c.active)
                                   ORDER BY c.id), '[]')
             FROM sales_channel_shop c WHERE c.website_id = w.id)"
        : "'[]'::json";

    $webseiten = $db->getAll(
        "SELECT w.id, w.name, w.active, w.sortkey, w.itime, w.mtime,
                website_default_settings() || COALESCE(w.settings, '{}'::jsonb) AS settings,
                (SELECT COALESCE(json_object_agg(s.key, true), '{}')
                   FROM website_secret_oserp s WHERE s.website_id = w.id AND s.value <> '') AS secrets_set,
                $kanaele AS channels,
                (SELECT COUNT(*) FROM portal_context_oserp x
                   JOIN customer_ext ce ON ce.customer_id = x.customer_id
                  WHERE x.website_id = w.id AND NOT ce.portal_guest) AS logins,
                (SELECT COUNT(*) FROM invoice_link_oserp il WHERE il.website_id = w.id) AS invoices,
                (SELECT COUNT(*) FROM portal_activation_oserp a
                  WHERE a.website_id = w.id AND a.used_at IS NULL AND a.expires > now()) AS activations_open
           FROM website_oserp w
          -- Jüngste zuerst: sortkey wächst beim Anlegen, die neue Webseite
          -- steht damit oben, wo man sie gerade einrichtet
          ORDER BY w.sortkey DESC NULLS LAST, w.id DESC",
        []
    ) ?: [];

    resultInfo(true, '', [
        'websites'               => $webseiten,
        'setting_keys'           => websiteSettingKeys($db),
        'template_sets'          => portalTemplateSets($db),
        'backend_url_suggestion' => portalBackendUrlSuggestion(),
        'signing_key'            => portalSigningPublicKey(),
        'extensions'             => portalExtensionsLoad($db),
    ]);
}

/**
 * Legt eine Webseite an
 *
 * Abgeschaltet, mit den Vorgaben (website_default_settings) und einem fertig
 * erzeugten Schlüssel der Webseite (public_key): eingeschaltet
 * wird, wenn Zugang und HugoCMS eingerichtet sind.
 *
 * @param string $data['name'] Name, eindeutig
 * @return void website_id
 * @testdata {"name": "Werkstatt-Webseite"}
 */
function createWebsite($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();

    $name = trim((string)($data['name'] ?? ''));
    if ('' === $name) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Die Webseite braucht einen Namen');
        return;
    }
    try {
        // Der Schlüssel der Webseite entsteht gleich mit: 32 Zufallsbytes als
        // Hex, dasselbe Format wie „Neuen Schlüssel erzeugen“ in der Oberfläche.
        // So fehlt nach dem Anlegen nur noch, was nur der Betreiber weiß.
        $zeile = $db->getOne(
            "WITH seite AS (
                 INSERT INTO website_oserp (name, active, sortkey, settings)
                 VALUES (:name, false, COALESCE((SELECT max(sortkey) FROM website_oserp), 0) + 1, website_default_settings())
                 RETURNING id
             ), schluessel AS (
                 INSERT INTO website_secret_oserp (website_id, key, value)
                 SELECT id, 'public_key', :public_key FROM seite
             )
             SELECT id FROM seite",
            [':name' => $name, ':public_key' => bin2hex(random_bytes(32))]
        );
    } catch (PDOException $e) {
        if ('23505' === $e->getCode()) {
            resultInfo(false, 'WEBSITE_NAME_TAKEN', null, 'Eine Webseite mit diesem Namen gibt es schon');
            return;
        }
        throw $e;
    }
    resultInfo(true, 'WEBSITE_CREATED', ['website_id' => (int)$zeile['id']]);
}

/**
 * Speichert Name und Schalter einer Webseite
 *
 * @param int $data['website_id'] Webseite
 * @param string $data['name'] Name; fehlt = unverändert
 * @param bool $data['active'] eingeschaltet; fehlt = unverändert
 * @return void
 * @testdata {"website_id": 1, "name": "Werkstatt-Webseite", "active": true}
 */
function saveWebsite($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();

    $nameSetzen = array_key_exists('name', $data);
    $name = trim((string)($data['name'] ?? ''));
    if ($nameSetzen && '' === $name) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Die Webseite braucht einen Namen');
        return;
    }
    $aktivSetzen = array_key_exists('active', $data);

    try {
        $zeile = $db->getOne(
            "UPDATE website_oserp
                SET name   = CASE WHEN :name_setzen = 1 THEN CAST(:name AS text) ELSE name END,
                    active = CASE WHEN :aktiv_setzen = 1 THEN CAST(:active AS boolean) ELSE active END,
                    mtime  = now()
              WHERE id = CAST(:website AS integer)
             RETURNING id, name, active",
            [
                ':name_setzen'  => $nameSetzen ? 1 : 0,
                ':name'         => $name,
                ':aktiv_setzen' => $aktivSetzen ? 1 : 0,
                ':active'       => !empty($data['active']) ? 'true' : 'false',
                ':website'      => (int)($data['website_id'] ?? 0),
            ]
        );
    } catch (PDOException $e) {
        if ('23505' === $e->getCode()) {
            resultInfo(false, 'WEBSITE_NAME_TAKEN', null, 'Eine Webseite mit diesem Namen gibt es schon');
            return;
        }
        throw $e;
    }
    if (!$zeile) {
        resultInfo(false, 'WEBSITE_NOT_FOUND', null, 'Diese Webseite gibt es nicht');
        return;
    }
    resultInfo(true, 'WEBSITE_SAVED', ['active' => in_array($zeile['active'], [true, 't', 1, '1'], true)]);
}

/**
 * Geheimnisse der Webseiten: Schlüssel des Zugangs und für HugoCMS
 *
 * Eigener Aufruf neben getWebsites, damit die Werte zum Vergleichen in der
 * Ansicht stehen — lehnt HugoCMS den Schlüssel ab, sieht man hier, welcher
 * geschickt wurde. Nur für edit_shop_config; der öffentliche Zugang bekommt
 * sie nie.
 *
 * @param int $data['website_id'] Nur diese Webseite (optional, 0 = alle)
 * @return void secrets (Webseite => (Schlüssel => Wert))
 * @testdata {"website_id": 0}
 */
function getWebsiteSecrets($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();

    $zeile = $db->getOne(
        "SELECT COALESCE(json_object_agg(x.website_id, x.werte), '{}') AS secrets
           FROM (SELECT s.website_id, json_object_agg(s.key, s.value) AS werte
                   FROM website_secret_oserp s
                  WHERE s.value <> ''
                    AND (CAST(:alle AS integer) = 0 OR s.website_id = :website_id)
                  GROUP BY s.website_id) x",
        [':alle' => (int)($data['website_id'] ?? 0), ':website_id' => (int)($data['website_id'] ?? 0)]
    );

    resultInfo(true, '', [
        'secrets' => json_decode((string)($zeile['secrets'] ?? '{}'), true) ?: (object)[],
    ]);
}

/**
 * Speichert die Einstellungen einer Webseite
 *
 * Angenommen werden nur die Schlüssel aus website_setting_keys() — nicht
 * geheime nach settings, geheime nach website_secret_oserp. Ein leer
 * geschicktes Geheimnis bleibt, wie es ist: ein geleertes Feld löscht keinen
 * Schlüssel. Die Antwort nennt, welche Geheimnisse danach hinterlegt sind,
 * und die abgelehnten Schlüssel.
 *
 * @param int $data['website_id'] Webseite
 * @param array $data['settings'] Schlüssel => Wert, etwa base_url
 * @param array $data['secrets'] Geheimnisse => neuer Wert, etwa public_key
 * @return void secrets_set (Schlüssel => true), rejected_keys (Liste)
 * @testdata {"website_id": 1, "settings": {"base_url": "https://www.example.de"}, "secrets": {}}
 */
function saveWebsiteSettings($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();

    $objekt = fn($wert) => json_encode(
        array_map(fn($v) => is_bool($v) ? ($v ? '1' : '0') : (string)($v ?? ''), is_array($wert) ? $wert : []),
        JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE
    );

    try {
        $zeile = $db->getOne(
            "WITH seite AS (
                 SELECT id FROM website_oserp WHERE id = CAST(:website AS integer)
             ), erlaubt AS (
                 SELECT k.key, k.secret FROM website_setting_keys() k
             ), werte AS (
                 SELECT e.key, e.value
                   FROM jsonb_each_text(CAST(:settings AS jsonb)) e
                   JOIN erlaubt a ON a.key = e.key AND NOT a.secret
             ), geheim AS (
                 INSERT INTO website_secret_oserp (website_id, key, value)
                 SELECT seite.id, e.key, e.value
                   FROM seite
                   CROSS JOIN jsonb_each_text(CAST(:secrets AS jsonb)) e
                   JOIN erlaubt a ON a.key = e.key AND a.secret
                  WHERE btrim(e.value) <> ''
                 ON CONFLICT (website_id, key) DO UPDATE SET value = EXCLUDED.value, mtime = now()
                 RETURNING key
             ), geaendert AS (
                 UPDATE website_oserp w
                    SET settings = COALESCE(w.settings, '{}'::jsonb)
                                   || COALESCE((SELECT jsonb_object_agg(key, value) FROM werte), '{}'::jsonb),
                        mtime = now()
                   FROM seite
                  WHERE w.id = seite.id
                 RETURNING w.id
             )
             SELECT (SELECT id FROM geaendert) AS id,
                    (SELECT COALESCE(json_object_agg(x.key, true), '{}')
                       FROM (SELECT s.key FROM website_secret_oserp s, seite
                              WHERE s.website_id = seite.id AND s.value <> ''
                             UNION
                             SELECT key FROM geheim) x) AS secrets_set,
                    (SELECT COALESCE(json_agg(DISTINCT r.key ORDER BY r.key), '[]')
                       FROM (SELECT e.key, false AS secret
                               FROM jsonb_each_text(CAST(:settings_pruefung AS jsonb)) e
                             UNION ALL
                             SELECT e.key, true
                               FROM jsonb_each_text(CAST(:secrets_pruefung AS jsonb)) e
                              WHERE btrim(e.value) <> '') r
                      WHERE NOT EXISTS (SELECT 1 FROM erlaubt a
                                         WHERE a.key = r.key AND a.secret = r.secret)) AS rejected_keys",
            [
                ':website'           => (int)($data['website_id'] ?? 0),
                ':settings'          => $objekt($data['settings'] ?? []),
                ':secrets'           => $objekt($data['secrets'] ?? []),
                ':settings_pruefung' => $objekt($data['settings'] ?? []),
                ':secrets_pruefung'  => $objekt($data['secrets'] ?? []),
            ]
        );
    } catch (PDOException $e) {
        // Der Schlüssel bestimmt Mandant und Webseite — eindeutig
        // (website_secret_oserp_public_key)
        if ('23505' === $e->getCode()) {
            resultInfo(false, 'WEBSITE_KEY_TAKEN', null, 'Dieser Schlüssel gehört schon einer anderen Webseite');
            return;
        }
        throw $e;
    }

    if (empty($zeile['id'])) {
        resultInfo(false, 'WEBSITE_NOT_FOUND', null, 'Diese Webseite gibt es nicht');
        return;
    }
    $abgelehnt = json_decode((string)($zeile['rejected_keys'] ?? '[]'), true) ?: [];
    if ($abgelehnt) {
        writeLog('saveWebsiteSettings: Schlüssel nicht zugelassen, nicht gespeichert (Upstall ausstehend?): '
                 .implode(', ', $abgelehnt), true, DLOG_WRN);
    }

    resultInfo(true, 'WEBSITE_SETTINGS_SAVED', [
        'secrets_set'   => json_decode((string)($zeile['secrets_set'] ?? '{}'), true) ?: (object)[],
        'rejected_keys' => $abgelehnt,
    ]);
}

/**
 * Löscht eine Webseite
 *
 * Nur abgeschaltet und ohne Verkaufskanal: ein HugoShop verlöre sonst seine
 * Webseite. Sitzungen und Freischaltungen gehen mit, Rechnungslinks bleiben
 * ohne Webseite stehen (die Rechnung bleibt über das Konto erreichbar).
 *
 * @param int $data['website_id'] Webseite
 * @return void
 * @testdata {"website_id": 0}
 */
function deleteWebsite($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();
    $website = (int)($data['website_id'] ?? 0);

    $mitShop = null !== ($db->getOne("SELECT to_regclass('sales_channel_shop') AS t")['t'] ?? null);
    if ($mitShop) {
        $kanal = $db->getOne(
            "SELECT 1 AS da FROM sales_channel_shop WHERE website_id = CAST(:website AS integer) LIMIT 1",
            [':website' => $website]
        );
        if ($kanal) {
            resultInfo(false, 'WEBSITE_IN_USE', null, 'Ein Verkaufskanal läuft auf dieser Webseite — zuerst dort die Webseite wechseln');
            return;
        }
    }

    $zeile = $db->getOne(
        "DELETE FROM website_oserp WHERE id = CAST(:website AS integer) AND NOT active RETURNING id",
        [':website' => $website]
    );
    if (!$zeile) {
        resultInfo(false, 'WEBSITE_NOT_DELETABLE', null, 'Nur eine abgeschaltete Webseite lässt sich löschen');
        return;
    }
    resultInfo(true, 'WEBSITE_DELETED');
}

/**
 * Prüft die Verbindung einer Webseite zu HugoCMS
 *
 * Fragt dort den Baustand ab. Gelingt das, stimmen Adresse, Schlüssel und
 * Zuordnung; die Antwort zeigt zugleich, ob HugoCMS bauen kann, die
 * Freigaben und ob es signiertes PHP und Medien annimmt. Adresse und Schlüssel
 * dürfen aus dem Formular kommen (noch nicht gespeichert); leer heißt: der
 * gespeicherte Wert gilt.
 *
 * @param int $data['website_id'] Webseite
 * @param string $data['url'] Adresse aus dem Formular (optional)
 * @param string $data['key'] Schlüssel aus dem Formular (optional)
 * @return void
 * @testdata {"website_id": 1}
 */
function testWebsiteHugoCms($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();
    $website = (int)($data['website_id'] ?? 0);

    $antwort = portalHugoCmsCall($db, $website, 'shopbuildstatus', 'GET', 20, [
        'url' => (string)($data['url'] ?? ''),
        'key' => (string)($data['key'] ?? ''),
    ]);
    if (!$antwort['ok']) {
        resultInfo(false, 'PORTAL_HUGOCMS_FAILED', null, $antwort['fehler']);
        return;
    }
    resultInfo(true, '', (array)$antwort['data'] + [
        'oserp_signing_key' => portalSigningPublicKey(),
        'grants_error'      => portalHugoCmsGrantsFrom($antwort)['fehler'],
        'features'          => portalKitFeatures($db, $website),
    ]);
}

/**
 * Prüft „Adresse des Portal-Zugangs von OpensourceERP“ (backend_url) einer Webseite
 *
 * Drei Schritte (portalBackendUrlCheck): plausibel, direkt von diesem Server,
 * über die Webseite. Adressen und Schlüssel dürfen aus dem Formular kommen.
 *
 * @param int $data['website_id'] Webseite
 * @param string $data['backend_url'] Adresse aus dem Formular (optional)
 * @param string $data['base_url'] Adresse der Webseite aus dem Formular (optional)
 * @param string $data['hugocms_url'] Adresse von HugoCMS aus dem Formular (optional)
 * @param string $data['key'] Schlüssel aus dem Formular (optional)
 * @return void schritte
 * @testdata {"website_id": 1}
 */
function testWebsiteBackendUrl($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();

    resultInfo(true, '', ['schritte' => portalBackendUrlCheck($db, (int)($data['website_id'] ?? 0), [
        'backend_url' => (string)($data['backend_url'] ?? ''),
        'base_url'    => (string)($data['base_url'] ?? ''),
        'hugocms_url' => (string)($data['hugocms_url'] ?? ''),
        'key'         => (string)($data['key'] ?? ''),
    ])]);
}

/**
 * Öffentlicher Signaturschlüssel dieser Installation (dev/shop-php-signatur.md)
 *
 * Zum Eintragen in HugoCMS: erst damit nimmt HugoCMS Weiterleiter und
 * 404-Seite signiert an. Kein Geheimnis. Leer, solange keiner erzeugt ist.
 *
 * @return void public_key
 * @testdata {}
 */
function getPortalSigningKey($data) {
    permit(['edit_shop_config'], false);

    resultInfo(true, '', ['public_key' => portalSigningPublicKey()]);
}

/**
 * Vorlagensätze für das Paket einer Webseite
 *
 * @return void sets
 * @testdata {}
 */
function getPortalTemplateSets($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();

    resultInfo(true, '', ['sets' => portalTemplateSets($db)]);
}

/**
 * Überträgt das Paket einer Webseite an HugoCMS und lässt bauen
 *
 * Mit HugoShop auf der Webseite übernimmt das der Lauf der Shop-Erweiterung
 * (Haken install_package: Auftrag und Start im Hintergrund); ohne Shop
 * geschieht es gleich hier (portalPublishWebsite), die Meldungen kommen mit
 * der Antwort.
 *
 * @param int $data['website_id'] Webseite
 * @return void queued, started, running, job_id (Shop) oder ok, messages (Kern)
 * @testdata {"website_id": 1}
 */
function installWebsitePackage($data) {
    permit(['edit_shop_config'], false);
    $db = portalAdminDb();
    $website = (int)($data['website_id'] ?? 0);

    if (!websiteConfig($db, $website)) {
        resultInfo(false, 'WEBSITE_NOT_FOUND', null, 'Diese Webseite gibt es nicht');
        return;
    }

    $auftrag = portalHookFilter('install_package', null, $db, $website);
    if (null !== $auftrag) {
        resultInfo(true, '', ['queued' => true] + (array)$auftrag);
        return;
    }

    $meldungen = [];
    $fehler = 0;
    $ok = portalPublishWebsite(
        $db, $website,
        function (string $text) use (&$meldungen): void { $meldungen[] = ['art' => 'info', 'text' => $text]; },
        function (string $text) use (&$meldungen, &$fehler): void { $meldungen[] = ['art' => 'error', 'text' => $text]; $fehler++; }
    );
    resultInfo(true, '', ['queued' => false, 'ok' => $ok && 0 === $fehler, 'messages' => $meldungen]);
}

// ── Freischaltung aus der Kundenakte (dev/shop-konto-freischaltung.md, F3) ──

/**
 * Stand des Kundenkontos im Portal und die Webseiten zur Auswahl
 *
 * Für den Knopf „Zugang zum Kundenkonto senden“ in der Kundenakte: hat der
 * Kunde ein Konto, läuft eine Freischaltung, welche Nummern und Webseiten
 * gibt es. Zur Auswahl stehen nur eingeschaltete Webseiten, die die
 * Freischaltung anbieten (Einstellung activation_enabled).
 *
 * @param int $data['customer_id'] Kunde
 * @return void status (activationStatus), websites, whatsapp (eingerichtet)
 * @testdata {"customer_id": 1}
 */
function getPortalActivationStatus($data) {
    permit(['customer_vendor_edit', 'customer_vendor_all_edit', 'edit_shop_config'], false);
    $db = portalAdminDb();

    $whatsapp = $db->getOne(
        "SELECT COUNT(*) AS n FROM defaults_oserp
          WHERE key IN ('whatsapp_access_token', 'whatsapp_phone_number_id') AND COALESCE(value, '') <> ''"
    );

    resultInfo(true, '', [
        'status'   => activationStatus($db, (int)($data['customer_id'] ?? 0)),
        'websites' => $db->getAll(
            "SELECT id, name, active, settings ->> 'base_url' AS base_url FROM website_oserp
              WHERE active
                AND COALESCE(settings ->> 'activation_enabled', '0') IN ('1', 'true', 't', 'on', 'yes')
              ORDER BY sortkey NULLS LAST, id"
        ) ?: [],
        'whatsapp' => 2 === (int)($whatsapp['n'] ?? 0),
        'template' => (bool)$db->getOne(
            "SELECT 1 AS da FROM whatsapp_templates WHERE template_type = 'portal_activation' AND status = 'approved' LIMIT 1"
        ),
    ]);
}

/**
 * Schickt einem Bestandskunden den Link zur Einrichtung seines Kontos
 *
 * Aus der Kundenakte (F3): per WhatsApp an die gewählte Nummer oder per Mail
 * an die hinterlegte Adresse. Eine offene Kennung des Kunden wird ersetzt.
 *
 * @param int $data['customer_id'] Kunde
 * @param int $data['website_id'] Webseite, deren Seite der Link öffnet
 * @param string $data['via'] whatsapp (Vorgabe) oder email
 * @param string $data['phone'] Nummer für WhatsApp; leer = Nummer des Kundenstamms
 * @return void sent, via, error
 * @testdata {"customer_id": 1, "website_id": 1, "via": "whatsapp"}
 */
function sendPortalActivation($data) {
    permit(['customer_vendor_edit', 'customer_vendor_all_edit', 'edit_shop_config'], false);
    $db = portalAdminDb();

    $customerId = (int)($data['customer_id'] ?? 0);
    $website = (int)($data['website_id'] ?? 0);
    if ($customerId <= 0 || $website <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Kunde und Webseite werden gebraucht');
        return;
    }
    $stand = activationStatus($db, $customerId);
    if ($stand['account']) {
        resultInfo(false, 'ACTIVATION_HAS_ACCOUNT', null, 'Der Kunde hat schon ein Konto');
        return;
    }
    if (!websiteActivationEnabled($db, $website)) {
        resultInfo(false, 'ACTIVATION_DISABLED', null, 'Die Konto-Freischaltung ist für diese Webseite nicht eingeschaltet');
        return;
    }

    $ergebnis = activationSend(
        $db, $customerId, $website,
        'email' === (string)($data['via'] ?? '') ? 'email' : 'whatsapp',
        (string)($data['phone'] ?? ''),
        'staff'
    );
    if (!$ergebnis['sent']) {
        resultInfo(false, 'ACTIVATION_NOT_SENT', null, $ergebnis['error'] ?: 'Die Nachricht konnte nicht versendet werden');
        return;
    }
    resultInfo(true, 'ACTIVATION_SENT', ['via' => $ergebnis['via'], 'status' => activationStatus($db, $customerId)]);
}
