<?php
// backend/api/shop/lib/kleinanzeigen_worker.php
//
// Anbindung des Kleinanzeigen-Dienstes (backend/services/kleinanzeigen):
// ein Headless-Chrome, der sich mit dem Konto des Kanals bei kleinanzeigen.de
// anmeldet und als Pseudo-API dient (dev/shop-kleinanzeigen.md, Teil 2).
//
// Der Dienst läuft lokal (Vorgabe http://127.0.0.1:3007) und ist über ein
// Shared-Secret geschützt. Je Kanal gibt es ein Browserprofil
// (account = <Datenbank>-<Kanal>), die Anmeldung bleibt darin erhalten.
//
// Einstellungen je Mandant (defaults_oserp): kleinanzeigen_worker_url,
// kleinanzeigen_worker_token. Je Kanal (sales_channel_shop): login_email
// (settings) und login_cookies (Geheimnis: die Anmelde-IDs, die der
// Benutzer nach seiner Anmeldung im eigenen Browser übergeben hat).

const SHOP_KLEINANZEIGEN_WORKER_DEFAULT = 'http://127.0.0.1:3007';

/**
 * Adresse und Token des Dienstes
 *
 * @param object $db Company-Datenbankverbindung
 * @return array url, token — url leer, wenn nicht eingerichtet
 */
function shopKleinanzeigenWorkerConfig($db): array {
    $zeile = $db->getOne(
        "SELECT (SELECT value FROM defaults_oserp WHERE key = 'kleinanzeigen_worker_url') AS url,
                (SELECT value FROM defaults_oserp WHERE key = 'kleinanzeigen_worker_token') AS token,
                (SELECT value FROM defaults_oserp WHERE key = 'kleinanzeigen_worker_enabled') AS enabled"
    );
    $an = in_array(strtolower(trim((string)($zeile['enabled'] ?? ''))), ['1', 't', 'true', 'on', 'y', 'yes'], true);
    $url = rtrim(trim((string)($zeile['url'] ?? '')), '/');
    return [
        'url'   => $an ? ($url ?: SHOP_KLEINANZEIGEN_WORKER_DEFAULT) : '',
        'token' => trim((string)($zeile['token'] ?? '')),
    ];
}

/**
 * Ist der Dienst für diesen Mandanten eingeschaltet?
 *
 * @param object $db Company-Datenbankverbindung
 * @return bool
 */
function shopKleinanzeigenWorkerEnabled($db): bool {
    return '' !== shopKleinanzeigenWorkerConfig($db)['url'];
}

/**
 * Kontokennung des Kanals beim Dienst: Datenbank und Kanalnummer
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @return string
 */
function shopKleinanzeigenAccount($db, int $kanal): string {
    static $name = null;
    if (null === $name) {
        $name = (string)($db->getOne("SELECT current_database() AS name")['name'] ?? 'oserp');
    }
    return $name.'-'.$kanal;
}

/**
 * Ruft den Dienst auf
 *
 * Jeder Aufruf trägt account und email des Kanals. Fehler des Dienstes
 * (HTTP 4xx/5xx mit error/message) werden als ApiError mit dem Code des
 * Dienstes geworfen — die Oberfläche übersetzt die bekannten Codes.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @param string $pfad etwa /ads/publish
 * @param array $body Nutzdaten
 * @param int $timeout Sekunden — Einstellen mit Bildern dauert
 * @return array Antwort ohne ok
 * @throws ApiError KLEINANZEIGEN_WORKER_OFF, KLEINANZEIGEN_WORKER_DOWN, Codes des Dienstes
 */
function shopKleinanzeigenWorkerCall($db, int $kanal, string $pfad, array $body = [], int $timeout = 180): array {
    $cfg = shopKleinanzeigenWorkerConfig($db);
    if ('' === $cfg['url']) {
        throw new ApiError('KLEINANZEIGEN_WORKER_OFF', 'Der Kleinanzeigen-Dienst ist nicht eingeschaltet (Firmenkonfiguration → Shop)');
    }
    $kanalCfg = shopChannelConfig($db, $kanal);
    $body += [
        'account' => shopKleinanzeigenAccount($db, $kanal),
        'email'   => trim((string)($kanalCfg['login_email'] ?? '')),
    ];

    $curl = curl_init($cfg['url'].$pfad);
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-KA-Token: '.$cfg['token']],
    ]);
    $antwort = curl_exec($curl);
    $status  = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $fehler  = curl_error($curl);
    curl_close($curl);

    if ($fehler) {
        throw new ApiError('KLEINANZEIGEN_WORKER_DOWN', 'Kleinanzeigen-Dienst nicht erreichbar ('.$cfg['url'].'): '.$fehler);
    }
    $daten = json_decode((string)$antwort, true);
    if (!is_array($daten)) {
        throw new ApiError('KLEINANZEIGEN_WORKER_DOWN', 'Kleinanzeigen-Dienst antwortet nicht mit JSON (HTTP '.$status.')');
    }
    if (empty($daten['ok'])) {
        $code = (string)($daten['error'] ?? 'WORKER_ERROR');
        // Bildschirmfoto und Adresse für die Fehlersuche — die API-Funktionen
        // geben sie mit der Fehlermeldung an die Oberfläche (shopKleinanzeigenWorkerDetail)
        $GLOBALS['shop_kleinanzeigen_worker_detail'] = array_intersect_key($daten, array_flip(['url', 'screenshot', 'state', 'kind', 'detail']));
        throw new ApiError('KLEINANZEIGEN_'.$code, (string)($daten['message'] ?? 'Fehler im Kleinanzeigen-Dienst'));
    }
    unset($daten['ok']);
    return $daten;
}

/**
 * Einzelheiten des letzten Fehlers des Dienstes (Bildschirmfoto, Adresse)
 *
 * @return array
 */
function shopKleinanzeigenWorkerDetail(): array {
    return $GLOBALS['shop_kleinanzeigen_worker_detail'] ?? [];
}

/**
 * Ruft den Dienst auf und spielt bei Bedarf vorher die Anmelde-IDs ein
 *
 * Antwortet der Dienst „nicht angemeldet“ (etwa nach Verlust des
 * Browserprofils), bekommt er die im Kanal hinterlegten Anmelde-IDs
 * (shopKleinanzeigenRestoreSession) und der Aufruf wird wiederholt. Der
 * Dienst meldet sich nie selbst mit Passwort an — die Anmeldung macht der
 * Benutzer in seinem eigenen Browser und übergibt die IDs in der Kanalkarte.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @param string $pfad etwa /ads/publish
 * @param array $body Nutzdaten
 * @param int $timeout Sekunden
 * @return array Antwort ohne ok
 * @throws ApiError wie shopKleinanzeigenWorkerCall, dazu KLEINANZEIGEN_NOT_LOGGED_IN
 */
function shopKleinanzeigenWorkerCallLoggedIn($db, int $kanal, string $pfad, array $body = [], int $timeout = 180): array {
    try {
        return shopKleinanzeigenWorkerCall($db, $kanal, $pfad, $body, $timeout);
    } catch (ApiError $e) {
        if ('KLEINANZEIGEN_NOT_LOGGED_IN' !== $e->getId()) {
            throw $e;
        }
    }
    $sitzung = shopKleinanzeigenRestoreSession($db, $kanal);
    if (empty($sitzung['logged_in'])) {
        $GLOBALS['shop_kleinanzeigen_worker_detail'] = array_intersect_key($sitzung, array_flip(['url', 'screenshot', 'state', 'ids']));
        throw new ApiError('KLEINANZEIGEN_NOT_LOGGED_IN', 'Der Browser des Dienstes ist nicht bei Kleinanzeigen angemeldet — in der Kanalkarte die Anmelde-IDs aus dem eigenen Browser übernehmen');
    }
    return shopKleinanzeigenWorkerCall($db, $kanal, $pfad, $body, $timeout);
}

/**
 * Spielt die im Kanal hinterlegten Anmelde-IDs in den Browser des Dienstes ein
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @return array state, logged_in, ids — logged_in false ohne hinterlegte IDs
 * @throws ApiError Fehler des Dienstes
 */
function shopKleinanzeigenRestoreSession($db, int $kanal): array {
    $cookies = json_decode((string)(shopChannelConfig($db, $kanal)['login_cookies'] ?? ''), true);
    if (!is_array($cookies) || !$cookies) {
        return ['state' => 'logged_out', 'logged_in' => false, 'ids' => []];
    }
    return shopKleinanzeigenWorkerCall($db, $kanal, '/session/restore', ['cookies' => array_values($cookies)], 120);
}

/**
 * Lebenszeichen des Dienstes, ohne Kanal
 *
 * @param object $db Company-Datenbankverbindung
 * @return array reachable, version, headless, chrome, sessions
 */
function shopKleinanzeigenWorkerHealth($db): array {
    $cfg = shopKleinanzeigenWorkerConfig($db);
    if ('' === $cfg['url']) {
        return ['enabled' => false, 'reachable' => false];
    }
    $curl = curl_init($cfg['url'].'/health');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['X-KA-Token: '.$cfg['token']],
    ]);
    $antwort = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $daten = json_decode((string)$antwort, true);
    if (!is_array($daten) || empty($daten['ok'])) {
        return ['enabled' => true, 'reachable' => false, 'status' => $status,
                'message' => is_array($daten) ? (string)($daten['message'] ?? '') : ''];
    }
    unset($daten['ok']);
    return ['enabled' => true, 'reachable' => true] + $daten;
}

/**
 * Die Anzeige eines Artikels als Nutzdaten für /ads/publish
 *
 * Aus kleinanzeigen_ad(): Titel, Text, Preis, Preisart, Versand, Zustand,
 * PLZ. Bilder als Dateipfade im Datenverzeichnis (der Dienst läuft auf
 * demselben Rechner). Kategorie: der Beschriftungspfad („Auto, Rad & Boot >
 * Autoteile & Reifen > Weitere Autoteile“) für den Dialog der Webseite.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @param int $partsId Artikel
 * @return array ad (Nutzdaten), anzeige (kleinanzeigen_ad)
 * @throws ApiError PART_NOT_FOUND, KLEINANZEIGEN_AD_INCOMPLETE
 */
function shopKleinanzeigenAdPayload($db, int $kanal, int $partsId): array {
    $zeile = $db->getOne(
        "SELECT kleinanzeigen_ad(CAST(:parts_id AS integer), CAST(:kanal AS integer)) AS ad,
                (SELECT COALESCE(json_agg(i.filename ORDER BY i.sort, i.id), '[]'::json)
                   FROM parts_channel_image_shop i
                  WHERE i.parts_id = CAST(:parts_id_bilder AS integer) AND i.channel_id = CAST(:kanal_bilder AS integer)) AS dateien,
                COALESCE((SELECT c.settings ->> 'location_zip' FROM sales_channel_shop c WHERE c.id = CAST(:kanal_plz AS integer)), '') AS plz,
                COALESCE((SELECT c.settings ->> 'contact_name' FROM sales_channel_shop c WHERE c.id = CAST(:kanal_name AS integer)), '') AS kontakt",
        [':parts_id' => $partsId, ':kanal' => $kanal, ':parts_id_bilder' => $partsId, ':kanal_bilder' => $kanal,
         ':kanal_plz' => $kanal, ':kanal_name' => $kanal]
    );
    $anzeige = json_decode((string)($zeile['ad'] ?? 'null'), true);
    if (!$anzeige) {
        throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden');
    }
    $fehlt = array_diff($anzeige['missing'] ?? [], ['location']);
    if ($fehlt) {
        throw new ApiError('KLEINANZEIGEN_AD_INCOMPLETE', 'Für die Anzeige fehlt noch: '.implode(', ', $fehlt));
    }
    if ('' === trim((string)$zeile['plz'])) {
        throw new ApiError('KLEINANZEIGEN_AD_INCOMPLETE', 'Dem Kanal fehlt die PLZ des Standorts');
    }

    $verzeichnis = shopChannelImageDir($db, $partsId);
    $bilder = [];
    foreach (json_decode((string)$zeile['dateien'], true) ?: [] as $datei) {
        $pfad = $verzeichnis.'/'.basename((string)$datei);
        if (is_file($pfad)) {
            $bilder[] = $pfad;
        }
    }

    // Kategorie: Beschriftungspfad für den Dialog der Webseite. Ist der Baum
    // geladen, muss der Pfad ein Blatt sein — sonst lehnt das Formular ab
    $kategorie = trim((string)($anzeige['category'] ?? ''));
    $labels = '' !== $kategorie ? array_values(array_filter(array_map('trim', preg_split('/\s*(?:>|›|»)\s*/u', $kategorie)))) : [];
    if ($labels) {
        $blatt = $db->getOne(
            "SELECT c.is_leaf, (SELECT count(*) FROM kleinanzeigen_category_shop) AS geladen
               FROM kleinanzeigen_category_shop c WHERE c.path = CAST(:pfad AS text)",
            [':pfad' => implode(' > ', $labels)]
        );
        if ($blatt && !in_array($blatt['is_leaf'], [true, 't', '1', 1], true)) {
            throw new ApiError('KLEINANZEIGEN_CATEGORY_NOT_LEAF', 'Die Kategorie „'.implode(' > ', $labels).'“ hat Unterkategorien — im Artikel oder im Kanal eine davon wählen');
        }
    }

    $preisart = ['fixed' => 'FIXED', 'negotiable' => 'NEGOTIABLE', 'giveaway' => 'GIVE_AWAY'][$anzeige['price_type']] ?? 'FIXED';
    // „Direkt kaufen“ (Bezahlen über Kleinanzeigen): Pflichtfrage im Formular, Vorgabe des Kanals, normalerweise nein
    $direktKaufen = in_array(strtolower(trim((string)(shopChannelConfig($db, $kanal)['buy_now'] ?? ''))), ['1', 't', 'true', 'on', 'y', 'yes'], true);
    $versand = ['pickup' => 'PICKUP', 'shipping' => 'SHIPPING', 'both' => 'SHIPPING'][$anzeige['shipping']] ?? 'SHIPPING';

    return [
        'anzeige' => $anzeige,
        'ad' => [
            'id'              => (string)($anzeige['external_id'] ?? ''),
            'title'           => (string)$anzeige['title'],
            'description'     => (string)$anzeige['text'],
            'price'           => (float)$anzeige['price'],
            'price_type'      => $preisart,
            'shipping'        => $versand,
            'condition'       => (string)($anzeige['condition'] ?? ''),
            'buy_now'         => $direktKaufen,
            'category_labels' => $labels,
            'zipcode'         => trim((string)$zeile['plz']),
            'contact_name'    => trim((string)$zeile['kontakt']),
            'images'          => $bilder,
        ],
    ];
}

/**
 * Stellt die Anzeige eines Artikels über den Dienst ein oder aktualisiert sie
 *
 * Danach steht die Kanalzeile auf online (active) mit Nummer, Adresse,
 * Zeitpunkt und dem Stand der Anzeige (posted_hash) — wie die Rückmeldung
 * „ist online“ von Hand.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @param int $partsId Artikel
 * @return array id, url, updated (bool)
 * @throws ApiError Fehler des Dienstes
 */
function shopKleinanzeigenPublishViaWorker($db, int $kanal, int $partsId): array {
    $nutzdaten = shopKleinanzeigenAdPayload($db, $kanal, $partsId);
    $vorher = $nutzdaten['ad']['id'];
    $antwort = shopKleinanzeigenWorkerCallLoggedIn($db, $kanal, '/ads/publish', ['ad' => $nutzdaten['ad']], 420);
    $nummer = (string)($antwort['id'] ?? $vorher);
    $url = (string)($antwort['url'] ?? ('https://www.kleinanzeigen.de/s-anzeige/'.$nummer));

    $db->execute(
        "UPDATE parts_channel_shop pc
            SET sync_status = CASE WHEN pc.sync_status = 'reserved' THEN 'reserved' ELSE 'active' END,
                external_id = :nummer,
                sync_error  = NULL,
                sync_mtime  = now(),
                sync_data   = jsonb_strip_nulls(COALESCE(pc.sync_data, '{}'::jsonb) || jsonb_build_object(
                                  'url', CAST(:url AS text),
                                  'online_since', COALESCE(pc.sync_data ->> 'online_since', to_char(now(), 'YYYY-MM-DD HH24:MI:SS')),
                                  'refreshed_at', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'),
                                  'posted_hash', kleinanzeigen_ad(pc.parts_id, pc.channel_id) ->> 'hash',
                                  'posted_by', 'worker',
                                  'sold_out', NULL))
          WHERE pc.parts_id = CAST(:parts_id AS integer) AND pc.channel_id = CAST(:kanal AS integer)",
        [':nummer' => $nummer, ':url' => $url, ':parts_id' => $partsId, ':kanal' => $kanal]
    );
    return ['id' => $nummer, 'url' => $url, 'updated' => '' !== $vorher];
}

/**
 * Löscht die Anzeige eines Artikels über den Dienst und beendet die Kanalzeile
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @param int $partsId Artikel
 * @return array deleted
 * @throws ApiError Fehler des Dienstes
 */
function shopKleinanzeigenDeleteViaWorker($db, int $kanal, int $partsId): array {
    $zeile = $db->getOne(
        "SELECT external_id, active FROM parts_channel_shop
          WHERE parts_id = CAST(:parts_id AS integer) AND channel_id = CAST(:kanal AS integer)",
        [':parts_id' => $partsId, ':kanal' => $kanal]
    );
    $nummer = trim((string)($zeile['external_id'] ?? ''));
    $ergebnis = ['deleted' => false];
    if ('' !== $nummer) {
        $ergebnis = shopKleinanzeigenWorkerCallLoggedIn($db, $kanal, '/ads/delete', ['id' => $nummer], 120);
    }
    $db->execute(
        "UPDATE parts_channel_shop pc
            SET sync_status = CASE WHEN pc.active THEN 'draft' ELSE 'ended' END,
                external_id = NULL,
                sync_error  = NULL,
                sync_mtime  = now(),
                sync_data   = jsonb_strip_nulls(COALESCE(pc.sync_data, '{}'::jsonb) || jsonb_build_object(
                                  'url', NULL, 'online_since', NULL, 'refreshed_at', NULL, 'posted_hash', NULL, 'sold_out', NULL,
                                  'ended_at', to_char(now(), 'YYYY-MM-DD HH24:MI:SS')))
          WHERE pc.parts_id = CAST(:parts_id AS integer) AND pc.channel_id = CAST(:kanal AS integer)",
        [':parts_id' => $partsId, ':kanal' => $kanal]
    );
    return $ergebnis;
}

/**
 * Gleicht die Kanalzeilen mit „Meine Anzeigen“ bei Kleinanzeigen ab
 *
 * Anzeigen, die es bei Kleinanzeigen nicht mehr gibt, werden beendet (bzw.
 * wieder Entwurf, wenn der Artikel angeboten bleibt); pausierte gelten als
 * reserviert; das Ablaufdatum wird gemerkt. Online-Anzeigen ohne Zeile in
 * OSERP bleiben unberührt — die Webseite kann mehr enthalten als der Kanal.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @return array fetched, ended, reserved, active
 * @throws ApiError Fehler des Dienstes
 */
function shopKleinanzeigenSyncViaWorker($db, int $kanal): array {
    $antwort = shopKleinanzeigenWorkerCallLoggedIn($db, $kanal, '/ads/list', [], 120);
    $anzeigen = [];
    foreach ($antwort['ads'] ?? [] as $a) {
        if (isset($a['id'])) {
            $anzeigen[] = ['id' => (string)$a['id'], 'state' => (string)($a['state'] ?? ''),
                           'end' => (string)($a['endDate'] ?? ''), 'title' => (string)($a['title'] ?? '')];
        }
    }
    $zeile = $db->getOne(
        "WITH liste AS (
             SELECT * FROM jsonb_to_recordset(CAST(:liste AS jsonb)) AS x(id text, state text, \"end\" text, title text)
         ), geaendert AS (
             UPDATE parts_channel_shop pc
                SET sync_status = CASE WHEN l.id IS NULL THEN CASE WHEN pc.active THEN 'draft' ELSE 'ended' END
                                       WHEN l.state = 'paused' THEN 'reserved'
                                       ELSE 'active' END,
                    external_id = CASE WHEN l.id IS NULL THEN NULL ELSE pc.external_id END,
                    sync_mtime  = now(),
                    sync_data   = jsonb_strip_nulls(COALESCE(pc.sync_data, '{}'::jsonb) || CASE WHEN l.id IS NULL
                                      THEN jsonb_build_object('url', NULL, 'online_since', NULL, 'refreshed_at', NULL, 'posted_hash', NULL,
                                                              'sold_out', NULL, 'ended_at', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'))
                                      ELSE jsonb_build_object('end_date', NULLIF(l.\"end\", ''), 'last_sync', to_char(now(), 'YYYY-MM-DD HH24:MI:SS')) END)
               FROM parts_channel_shop alt
               LEFT JOIN liste l ON l.id = alt.external_id
              WHERE alt.id = pc.id
                AND pc.channel_id = CAST(:kanal AS integer)
                AND COALESCE(pc.sync_status, '') IN ('active', 'reserved')
                AND COALESCE(pc.external_id, '') <> ''
             RETURNING pc.sync_status
         )
         SELECT count(*) FILTER (WHERE sync_status IN ('draft', 'ended')) AS beendet,
                count(*) FILTER (WHERE sync_status = 'reserved') AS reserviert,
                count(*) FILTER (WHERE sync_status = 'active') AS aktiv
           FROM geaendert",
        [':liste' => json_encode($anzeigen), ':kanal' => $kanal]
    );
    $db->execute(
        "UPDATE sales_channel_shop
            SET settings = COALESCE(settings, '{}'::jsonb) || jsonb_build_object('ads_last_sync', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'),
                                                                                  'ads_online_count', CAST(:anzahl AS integer))
          WHERE id = CAST(:kanal AS integer)",
        [':anzahl' => count($anzeigen), ':kanal' => $kanal]
    );
    shopChannelConfig($db, $kanal, true);
    return ['fetched' => count($anzeigen), 'ended' => (int)($zeile['beendet'] ?? 0),
            'reserved' => (int)($zeile['reserviert'] ?? 0), 'active' => (int)($zeile['aktiv'] ?? 0)];
}

/**
 * Holt die Unterhaltungen von Kleinanzeigen über den Dienst und legt Anfragen an
 *
 * Jede Unterhaltung wird eine Anfrage (mail_uid = conv:<id>), zugeordnet über
 * die Anzeigennummer, sonst über den Titel. Bekannte Unterhaltungen bekommen
 * die letzte Nachricht nachgetragen und werden wieder geöffnet, wenn sie
 * ungelesen sind.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @return array fetched, imported, matched
 * @throws ApiError Fehler des Dienstes
 */
function shopKleinanzeigenLeadsViaWorker($db, int $kanal): array {
    $antwort = shopKleinanzeigenWorkerCallLoggedIn($db, $kanal, '/messages/list', ['limit' => 100], 120);
    $bilanz = ['fetched' => 0, 'imported' => 0, 'matched' => 0];
    foreach ($antwort['conversations'] ?? [] as $u) {
        $id = trim((string)($u['id'] ?? ''));
        if ('' === $id) {
            continue;
        }
        $bilanz['fetched']++;
        $zeile = $db->getOne(
            "INSERT INTO kleinanzeigen_lead_shop
                 (channel_id, parts_id, ad_id, ad_title, sender_name, message, mail_uid, received_at)
             SELECT CAST(:kanal AS integer),
                    (SELECT pc.parts_id
                       FROM parts_channel_shop pc
                       JOIN parts p ON p.id = pc.parts_id
                      WHERE pc.channel_id = CAST(:kanal_artikel AS integer)
                        AND ((:ad_id <> '' AND pc.external_id = :ad_id_vergleich)
                             OR (:titel <> '' AND lower(btrim(COALESCE(NULLIF(pc.title, ''), p.description))) = lower(btrim(:titel_vergleich))))
                      ORDER BY (pc.external_id = :ad_id_rang) DESC NULLS LAST, pc.sync_mtime DESC NULLS LAST
                      LIMIT 1),
                    NULLIF(:ad_id_wert, ''), NULLIF(:titel_wert, ''), NULLIF(:absender, ''), CAST(:nachricht AS text),
                    CAST(:mail_uid AS text), now()
             ON CONFLICT (channel_id, mail_uid) DO UPDATE
                SET message = CASE WHEN EXCLUDED.message <> '' THEN EXCLUDED.message ELSE kleinanzeigen_lead_shop.message END,
                    status  = CASE WHEN CAST(:ungelesen AS boolean) AND kleinanzeigen_lead_shop.status = 'answered' THEN 'open'
                                   ELSE kleinanzeigen_lead_shop.status END,
                    parts_id = COALESCE(kleinanzeigen_lead_shop.parts_id, EXCLUDED.parts_id),
                    mtime = now()
             RETURNING (xmax = 0) AS neu, parts_id",
            [
                ':kanal'           => $kanal,
                ':kanal_artikel'   => $kanal,
                ':ad_id'           => (string)($u['ad_id'] ?? ''),
                ':ad_id_vergleich' => (string)($u['ad_id'] ?? ''),
                ':ad_id_rang'      => (string)($u['ad_id'] ?? ''),
                ':ad_id_wert'      => (string)($u['ad_id'] ?? ''),
                ':titel'           => (string)($u['ad_title'] ?? ''),
                ':titel_vergleich' => (string)($u['ad_title'] ?? ''),
                ':titel_wert'      => (string)($u['ad_title'] ?? ''),
                ':absender'        => (string)($u['counterpart'] ?? ''),
                ':nachricht'       => mb_substr((string)($u['last_message'] ?? ''), 0, 1500),
                ':mail_uid'        => 'conv:'.$id,
                ':ungelesen'       => !empty($u['unread']) ? 'true' : 'false',
            ]
        );
        if ($zeile && in_array($zeile['neu'], [true, 't', 1, '1'], true)) {
            $bilanz['imported']++;
            if (!empty($zeile['parts_id'])) {
                $bilanz['matched']++;
            }
        }
    }
    $db->execute(
        "UPDATE sales_channel_shop
            SET settings = COALESCE(settings, '{}'::jsonb) || jsonb_build_object('lead_last_check', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'))
          WHERE id = CAST(:kanal AS integer)",
        [':kanal' => $kanal]
    );
    shopChannelConfig($db, $kanal, true);
    return $bilanz;
}
