<?php
// backend/api/shop/channels/ebay.php
//
// Verkaufskanal eBay (dev/shop-verkaufskanaele.md, Schritt 5). Ersetzt die
// bisherige Anbindung unter backend/api/ebay/ (V15). Zugang und API-Aufrufe
// sind von dort übernommen, das Einstellen liest jetzt den Kanal:
//
//   Preis          shop_channel_price(…, Kanal), brutto (eBay rechnet brutto)
//   Titel, Text    Beschreibung und Langbeschreibung im Kanal, sonst Stammdaten
//   Menge          Lagerbestand parts.onhand (V4, V19) statt fester Menge;
//                  0, wenn nicht verfügbar (shop_part_available: veraltet
//                  oder im Kanal als nicht verfügbar markiert)
//   Bilder         Bilder des eBay-Kanals (parts_channel_image_shop, V12)
//   Kategorie,     je Artikel im Kanal (parts_channel_shop.settings), sonst
//   Zustand        die Vorgaben default_category_id / default_condition
//
// Eingestellt und beendet wird über die Warteschlange (Aufträge mit Kanal);
// die Trigger im Shop-Schema legen die Aufträge an, wenn sich Preis, Texte,
// Bestand oder die Auswahl ändern. Eingeschaltet ist der Kanal über
// sales_channel_shop.active.
//
// Mehrere eBay-Kanäle (dev/shop-mehrere-kanaele.md, Schritt 4): jeder Kanal
// ist ein eBay-Konto mit eigenem Zugang, eigenen Richtlinien und eigenem
// Bestellabruf. Alle Funktionen bekommen den Kanal ($kanal). Die
// Einstellungen stehen in den Einstellungen des Kanals (Schlüssel ohne
// Präfix: client_id, marketplace_id …), Zugang und Token in
// sales_channel_secret_shop. Für den ganzen Mandanten gilt nur
// ebay_public_host — die Adresse von OSERP, unter der eBay die Bilder abholt.
//
// M4: ein Kanal je eBay-Konto. Der Inventareintrag (Menge, Titel, Los)
// gehört bei eBay dem Konto; zwei Kanäle mit demselben Konto überschrieben
// sich. Das Konto wird nach jedem neuen Token bestimmt (shopEbayAccountCheck)
// und im Kanal gemerkt; ein eindeutiger Index lässt es nur einmal zu.
//
// Der Bestellimport steht in channels/ebay_orders.php.

const SHOP_EBAY_OAUTH_PROD     = 'https://api.ebay.com/identity/v1/oauth2/token';
const SHOP_EBAY_OAUTH_SANDBOX  = 'https://api.sandbox.ebay.com/identity/v1/oauth2/token';
const SHOP_EBAY_API_PROD       = 'https://api.ebay.com';
const SHOP_EBAY_API_SANDBOX    = 'https://api.sandbox.ebay.com';
// Kontokennung (M4): Identity-API — braucht den Scope commerce.identity.readonly.
// Ohne ihn hilft die Verkäuferkennung der letzten Bestellung.
const SHOP_EBAY_IDENTITY_PROD    = 'https://apiz.ebay.com/commerce/identity/v1/user/';
const SHOP_EBAY_IDENTITY_SANDBOX = 'https://apiz.sandbox.ebay.com/commerce/identity/v1/user/';
// Bestellungen lesen (Import) und Inventar schreiben (Angebote)
const SHOP_EBAY_SCOPES         = 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'
                               .' https://api.ebay.com/oauth/api_scope/sell.inventory';
const SHOP_EBAY_TOKEN_TTL      = 7200; // falls eBay kein expires_in liefert
const SHOP_EBAY_TOKEN_SKEW     = 300;  // Puffer vor Ablauf

// ── Einstellungen und Zugang ──

/**
 * Einstellungen eines eBay-Kanals, Zugang und Token eingeschlossen
 *
 * Schlüssel ohne Präfix (shopChannelConfig), dazu public_host aus
 * defaults_oserp (ebay_public_host, für den ganzen Mandanten).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @return array Schlüssel => Wert
 */
function shopEbayConfig($db, int $kanal): array {
    $zeile = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'ebay_public_host'");
    return shopChannelConfig($db, $kanal) + ['public_host' => (string)($zeile['value'] ?? '')];
}

/** Laufzeitwerte, die als Geheimnis gespeichert werden */
const SHOP_EBAY_SECRET_KEYS = ['access_token', 'access_token_exp', 'client_secret', 'refresh_token'];

/**
 * Schreibt einen Laufzeitwert in den Kanal (Token-Cache, letzter Abruf, Konto)
 *
 * Token in sales_channel_secret_shop, alles andere in settings. Danach liest
 * der Zwischenspeicher neu — ein zweiter Aufruf im selben Request holte sonst
 * das Token erneut.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param string $key Schlüssel ohne Präfix
 * @param string $value Wert
 * @return void
 */
function shopEbaySetConfig($db, int $kanal, string $key, string $value): void {
    if (in_array($key, SHOP_EBAY_SECRET_KEYS, true)) {
        $db->execute(
            "INSERT INTO sales_channel_secret_shop (channel_id, key, value)
             VALUES (CAST(:kanal AS integer), :key, :value)
             ON CONFLICT (channel_id, key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
            [':kanal' => $kanal, ':key' => $key, ':value' => $value]
        );
    } else {
        $db->execute(
            "UPDATE sales_channel_shop
                SET settings = COALESCE(settings, '{}'::jsonb) || jsonb_build_object(CAST(:key AS text), CAST(:value AS text))
              WHERE id = CAST(:kanal AS integer)",
            [':kanal' => $kanal, ':key' => $key, ':value' => $value]
        );
    }
    shopChannelConfig($db, $kanal, true);
}

/**
 * Basisadresse der REST-API je nach Umgebung
 *
 * @param array $cfg Ergebnis von shopEbayConfig()
 * @return string
 */
function shopEbayApiBase(array $cfg): string {
    return 'sandbox' === ($cfg['environment'] ?? 'production') ? SHOP_EBAY_API_SANDBOX : SHOP_EBAY_API_PROD;
}

/**
 * Gültiges Zugriffstoken, notfalls über das Refresh-Token neu geholt
 *
 * Zwischengespeichert als access_token / access_token_exp in den Geheimnissen
 * des Kanals — sie gehen nie an den Browser.
 *
 * Nach einem neuen Token wird das eBay-Konto geprüft (M4,
 * shopEbayAccountCheck): steht es schon in einem anderen Kanal, bricht der
 * Aufruf ab, bevor etwas bei eBay geschieht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param bool $erneuern Zwischenspeicher übergehen
 * @return string
 * @throws ApiError EBAY_NO_CREDENTIALS, EBAY_AUTH_FAILED, EBAY_ACCOUNT_IN_USE
 */
function shopEbayToken($db, int $kanal, bool $erneuern = false): string {
    $cfg = shopEbayConfig($db, $kanal);

    if (!$erneuern
        && !empty($cfg['access_token'])
        && (int)($cfg['access_token_exp'] ?? 0) > time() + SHOP_EBAY_TOKEN_SKEW) {
        return $cfg['access_token'];
    }

    $clientId = trim($cfg['client_id'] ?? '');
    $secret   = trim($cfg['client_secret'] ?? '');
    $refresh  = trim($cfg['refresh_token'] ?? '');
    if ('' === $clientId || '' === $secret || '' === $refresh) {
        throw new ApiError('EBAY_NO_CREDENTIALS', "eBay-Zugangsdaten des Kanals '".($cfg['name'] ?? $kanal)."' sind nicht eingerichtet");
    }
    shopEbayRefreshTokenCheck($db, $kanal);

    $curl = curl_init('sandbox' === ($cfg['environment'] ?? 'production') ? SHOP_EBAY_OAUTH_SANDBOX : SHOP_EBAY_OAUTH_PROD);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic '.base64_encode($clientId.':'.$secret),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'scope'         => SHOP_EBAY_SCOPES,
        ]),
    ]);
    $antwort = curl_exec($curl);
    $fehler  = curl_error($curl);
    curl_close($curl);

    if ($fehler) {
        throw new ApiError('EBAY_AUTH_FAILED', 'eBay-Token konnte nicht abgerufen werden: '.$fehler);
    }
    $daten = json_decode((string)$antwort, true);
    if (empty($daten['access_token'])) {
        throw new ApiError('EBAY_AUTH_FAILED', 'eBay-Token-Refresh fehlgeschlagen: '
            .($daten['error_description'] ?? ($daten['error'] ?? 'unbekannter Fehler')));
    }

    $dauer = (int)($daten['expires_in'] ?? 0);
    shopEbaySetConfig($db, $kanal, 'access_token', $daten['access_token']);
    shopEbaySetConfig($db, $kanal, 'access_token_exp', (string)(time() + ($dauer > 0 ? $dauer : SHOP_EBAY_TOKEN_TTL)));

    shopEbayAccountCheck($db, $kanal, $daten['access_token']);

    return $daten['access_token'];
}

/**
 * Derselbe Zugang in zwei eBay-Kanälen? (M4)
 *
 * Ein Refresh-Token gehört genau einem eBay-Konto. Steht derselbe schon in
 * einem anderen eBay-Kanal, ist es dasselbe Konto — ohne einen Aufruf bei
 * eBay erkennbar.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @return void
 * @throws ApiError EBAY_ACCOUNT_IN_USE
 */
function shopEbayRefreshTokenCheck($db, int $kanal): void {
    $andere = $db->getOne(
        "SELECT c.name
           FROM sales_channel_secret_shop eigen
           JOIN sales_channel_secret_shop s ON s.key = 'refresh_token' AND s.value = eigen.value
                                           AND s.channel_id <> eigen.channel_id
           JOIN sales_channel_shop c ON c.id = s.channel_id AND c.type = 'ebay'
          WHERE eigen.channel_id = CAST(:kanal AS integer) AND eigen.key = 'refresh_token'
          LIMIT 1",
        [':kanal' => $kanal]
    );
    if ($andere) {
        throw new ApiError('EBAY_ACCOUNT_IN_USE',
            "Dieser eBay-Zugang ist schon im Verkaufskanal '".$andere['name']."' eingerichtet — je eBay-Konto ein Kanal");
    }
}

/**
 * Bestimmt das eBay-Konto des Kanals und lässt es nur einmal zu (M4)
 *
 * Erst über die Identity-API (Scope commerce.identity.readonly), sonst über
 * die Verkäuferkennung der letzten Bestellung. Gemerkt wird sie in
 * settings.account_id; ein eindeutiger Index auf diesem Wert lässt dasselbe
 * Konto in einem zweiten eBay-Kanal nicht zu. Lässt sich das Konto nicht
 * bestimmen (kein Scope, noch keine Bestellung), bleibt es offen — dann
 * schützt nur der Vergleich der Refresh-Tokens.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param string $token gültiges Zugriffstoken
 * @return string Kontokennung, leer wenn nicht bestimmbar
 * @throws ApiError EBAY_ACCOUNT_IN_USE
 */
function shopEbayAccountCheck($db, int $kanal, string $token): string {
    $cfg = shopEbayConfig($db, $kanal);
    $konto = shopEbayAccountLookup($cfg, $token);
    if ('' === $konto) {
        return trim((string)($cfg['account_id'] ?? ''));
    }

    $andere = $db->getOne(
        "SELECT name FROM sales_channel_shop
          WHERE type = 'ebay' AND id <> CAST(:kanal AS integer) AND settings ->> 'account_id' = :konto
          LIMIT 1",
        [':kanal' => $kanal, ':konto' => $konto]
    );
    if ($andere) {
        // Das eben geholte Token verwerfen: sonst arbeitete der nächste
        // Aufruf mit dem zwischengespeicherten, ohne erneut zu prüfen
        shopEbaySetConfig($db, $kanal, 'access_token_exp', '0');
        throw new ApiError('EBAY_ACCOUNT_IN_USE',
            "Das eBay-Konto '".$konto."' ist schon im Verkaufskanal '".$andere['name']."' eingerichtet — je eBay-Konto ein Kanal");
    }
    if (($cfg['account_id'] ?? '') !== $konto) {
        shopEbaySetConfig($db, $kanal, 'account_id', $konto);
    }
    return $konto;
}

/**
 * Fragt eBay nach dem Konto hinter einem Token
 *
 * @param array $cfg Ergebnis von shopEbayConfig()
 * @param string $token Zugriffstoken
 * @return string Benutzername bzw. Verkäuferkennung, leer wenn nicht bestimmbar
 */
function shopEbayAccountLookup(array $cfg, string $token): string {
    $holen = function (string $url) use ($token, $cfg): array {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER     => array_filter([
                'Authorization: Bearer '.$token,
                'Accept: application/json',
                !empty($cfg['marketplace_id']) ? 'X-EBAY-C-MARKETPLACE-ID: '.$cfg['marketplace_id'] : null,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $antwort = curl_exec($curl);
        $status  = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return $status >= 200 && $status < 300 ? (json_decode((string)$antwort, true) ?: []) : [];
    };

    $sandbox = 'sandbox' === ($cfg['environment'] ?? 'production');
    $ich = $holen($sandbox ? SHOP_EBAY_IDENTITY_SANDBOX : SHOP_EBAY_IDENTITY_PROD);
    $konto = trim((string)($ich['username'] ?? $ich['userId'] ?? ''));
    if ('' !== $konto) {
        return $konto;
    }

    $bestellungen = $holen(shopEbayApiBase($cfg).'/sell/fulfillment/v1/order?limit=1');
    return trim((string)($bestellungen['orders'][0]['sellerId'] ?? ''));
}

/**
 * Aufruf der eBay-REST-API
 *
 * Wirft nur bei Transportfehlern; eBay-Fehler (4xx) kommen mit Status und
 * Inhalt zurück, damit der Aufrufer Validierungsfehler selbst auswertet. Bei
 * 401 wird das Token einmal erneuert und der Aufruf wiederholt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param string $methode GET, POST, PUT oder DELETE
 * @param string $pfad etwa /sell/inventory/v1/offer
 * @param array|null $inhalt JSON-Inhalt
 * @param array $abfrage Abfrageparameter (GET)
 * @param bool $nochmal intern
 * @return array ['status' => int, 'body' => array]
 * @throws ApiError EBAY_API_ERROR bei Transportfehlern
 */
function shopEbayApi($db, int $kanal, string $methode, string $pfad, ?array $inhalt = null, array $abfrage = [], bool $nochmal = true): array {
    $token = shopEbayToken($db, $kanal);
    $cfg   = shopEbayConfig($db, $kanal);
    $url   = shopEbayApiBase($cfg).$pfad.($abfrage ? '?'.http_build_query($abfrage) : '');

    $kopf = [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json',
        'Accept: application/json',
        'Content-Language: '.(trim((string)($cfg['content_language'] ?? '')) ?: 'de-DE'),
    ];
    if (!empty($cfg['marketplace_id'])) {
        $kopf[] = 'X-EBAY-C-MARKETPLACE-ID: '.$cfg['marketplace_id'];
    }

    $optionen = [
        CURLOPT_HTTPHEADER     => $kopf,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CUSTOMREQUEST  => strtoupper($methode),
    ];
    if (null !== $inhalt) {
        $optionen[CURLOPT_POSTFIELDS] = json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, $optionen);
    $antwort = curl_exec($curl);
    $status  = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $fehler  = curl_error($curl);
    curl_close($curl);

    if ($fehler) {
        throw new ApiError('EBAY_API_ERROR', 'eBay-API nicht erreichbar: '.$fehler);
    }
    if (401 === $status && $nochmal) {
        shopEbayToken($db, $kanal, true);
        return shopEbayApi($db, $kanal, $methode, $pfad, $inhalt, $abfrage, false);
    }

    $daten = ('' === $antwort || false === $antwort) ? [] : json_decode($antwort, true);
    return ['status' => $status, 'body' => is_array($daten) ? $daten : []];
}

/**
 * Lesbare Meldung aus einer eBay-Fehlerantwort
 *
 * @param array $antwort Ergebnis von shopEbayApi()
 * @return string
 */
function shopEbayErrorMessage(array $antwort): string {
    $teile = [];
    foreach ($antwort['body']['errors'] ?? [] as $fehler) {
        $teile[] = trim(($fehler['longMessage'] ?? '') !== '' ? $fehler['longMessage'] : ($fehler['message'] ?? ''));
    }
    $teile = array_filter($teile);
    return $teile ? implode(' | ', $teile) : 'HTTP '.($antwort['status'] ?? '?');
}

// ── Modul (channels/channels.php) ──

/**
 * Auftragsarten des eBay-Kanals
 *
 * @return array
 */
function shopChannelEbayJobFunctions(): array {
    return ['publish_part', 'remove_part', 'publish_all', 'remove_all'];
}

/**
 * Reagiert auf Ein- und Ausschalten des eBay-Kanals (V26)
 *
 * Aus: alle Angebote beenden. An: alle angebotenen Artikel neu einstellen.
 * Offene Aufträge der Gegenrichtung werden vorher gelöscht. Der
 * Bestellimport fragt den Schalter selbst ab (shopEbayActive).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param bool $an eingeschaltet
 * @return void
 */
function shopChannelEbaySwitched($db, int $kanal, bool $an): void {
    $db->execute(
        "DELETE FROM batchjob_hugoshop
          WHERE result IS NULL
            AND channel_id = CAST(:kanal AS integer)
            AND function = ANY(string_to_array(:gegenrichtung, ','))",
        [':kanal' => $kanal, ':gegenrichtung' => $an ? 'remove_all,remove_part' : 'publish_all,publish_part']
    );
    shopQueueJob($db, $an ? 'publish_all' : 'remove_all', '', null, $kanal);
}

/**
 * Arbeitet einen Auftrag des eBay-Kanals ab
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $auftrag Zeile aus shopOpenJobs()
 * @param callable $sagen Fortschritt
 * @param callable $fehler Fehlermeldung, wird gezählt
 * @param array $bilanz Zähler des Laufs
 * @return void
 * @throws ApiError wenn ein einzelner Artikel scheitert — der Läufer vermerkt den Auftrag
 */
function shopChannelEbayRunJob($db, array $auftrag, callable $sagen, callable $fehler, array &$bilanz): void {
    $id = (int)$auftrag['id'];
    $kanal = (int)$auftrag['channel_id'];

    switch ($auftrag['function']) {
        case 'publish_part':
        case 'remove_part':
            $artikel = $db->getOne("SELECT id FROM parts WHERE partnumber = :nr", [':nr' => $auftrag['partnumber']]);
            if (!$artikel) {
                throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden: '.$auftrag['partnumber']);
            }
            $ergebnis = 'publish_part' === $auftrag['function']
                ? shopEbayPublishPart($db, $kanal, (int)$artikel['id'])
                : shopEbayEndPart($db, $kanal, (int)$artikel['id']);
            $sagen('eBay '.$auftrag['partnumber'].': '.$ergebnis);
            shopJobResult($db, $id, 'ok: '.$ergebnis);
            break;

        case 'publish_all':
        case 'remove_all':
            // Einstellen nur, was angeboten wird; beenden alles, was bei eBay
            // noch ein nicht beendetes Angebot hat — auch abgewählte Artikel.
            $einstellen = 'publish_all' === $auftrag['function'];
            $artikelListe = $db->getAll(
                "SELECT p.id, p.partnumber
                   FROM parts_channel_shop pc
                   JOIN parts p ON p.id = pc.parts_id
                  WHERE pc.channel_id = CAST(:kanal AS integer)
                    AND CASE WHEN :einstellen = 1 THEN pc.active
                             ELSE COALESCE(pc.sync_data ->> 'offer_id', '') <> ''
                                  AND COALESCE(pc.sync_status, '') <> 'ended' END
                  ORDER BY p.id",
                [':einstellen' => $einstellen ? 1 : 0, ':kanal' => $kanal]
            );
            $gut = 0;
            $gescheitert = 0;
            foreach ($artikelListe as $artikel) {
                try {
                    $einstellen ? shopEbayPublishPart($db, $kanal, (int)$artikel['id']) : shopEbayEndPart($db, $kanal, (int)$artikel['id']);
                    $gut++;
                } catch (ApiError $e) {
                    $gescheitert++;
                    if ($gescheitert <= SHOP_MELDUNGEN_JE_AUFTRAG) {
                        $fehler('eBay '.$artikel['partnumber'].': '.$e->getMessage());
                    }
                }
            }
            $stand = sprintf('eBay: %d %s, %d fehlgeschlagen', $gut, $einstellen ? 'eingestellt' : 'beendet', $gescheitert);
            $sagen($stand);
            shopJobResult($db, $id, ($gescheitert > 0 ? 'Fehler: ' : 'ok: ').$stand);
            break;

        default:
            $fehler('Auftrag '.$id.': unbekannte Funktion '.$auftrag['function']);
            shopJobResult($db, $id, 'Fehler: unbekannte Funktion '.$auftrag['function']);
    }
}

// ── Angebote ──

/**
 * Öffentliche Adressen der eBay-Bilder eines Artikels
 *
 * eBay holt die Bilder selbst ab, über https und die Adresse aus
 * ebay_public_host. Der Läufer kennt ohne Webanfrage keine eigene Adresse —
 * deshalb ist die Einstellung Pflicht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal — seine Bilder
 * @param int $partsId Artikel
 * @param array $cfg Ergebnis von shopEbayConfig()
 * @return array Adressen, Hauptbild zuerst
 * @throws ApiError EBAY_NO_PUBLIC_HOST
 */
function shopEbayImageUrls($db, int $kanal, int $partsId, array $cfg): array {
    $host = trim((string)($cfg['public_host'] ?? ''));
    $host = preg_replace('#^https?://#i', '', rtrim($host, '/'));
    if ('' === $host) {
        throw new ApiError('EBAY_NO_PUBLIC_HOST',
            'Öffentliche Adresse für die Bilder fehlt (Einstellungen → Shop → eBay: ebay_public_host)');
    }

    $zeilen = $db->getAll(
        "SELECT '/webhook/part-image.php?db=' || current_database() || '&id=' || i.parts_id
                || '&f=' || i.filename AS pfad
           FROM parts_channel_image_shop i
          WHERE i.parts_id = :parts_id AND i.channel_id = CAST(:kanal AS integer)
          ORDER BY i.sort, i.id",
        [':parts_id' => $partsId, ':kanal' => $kanal]
    );
    return array_map(fn($zeile) => 'https://'.$host.$zeile['pfad'], $zeilen);
}

/**
 * Hält den Stand des Abgleichs an der Kanalzeile fest
 *
 * Nur sync_*, external_id und sync_data — daran lösen die Trigger keinen
 * neuen Auftrag aus.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $partsId Artikel
 * @param string $stand active, ended oder error
 * @param string $meldung Fehlertext, leer bei Erfolg
 * @param array $daten zusätzlich zu merken (offer_id); null-Werte löschen den Eintrag
 * @param string|null $listing Listing-Kennung, null = unverändert
 * @return void
 */
function shopEbayRecord($db, int $kanal, int $partsId, string $stand, string $meldung, array $daten = [], ?string $listing = null): void {
    $db->execute(
        "UPDATE parts_channel_shop
            SET sync_status = :stand,
                sync_error  = NULLIF(:meldung, ''),
                sync_mtime  = now(),
                sync_data   = jsonb_strip_nulls(COALESCE(sync_data, '{}'::jsonb) || :daten::jsonb),
                external_id = CASE WHEN :listing_setzen = 1 THEN :listing ELSE external_id END
          WHERE parts_id = :parts_id AND channel_id = CAST(:kanal AS integer)",
        [
            ':kanal'          => $kanal,
            ':stand'          => $stand,
            ':meldung'        => $meldung,
            ':daten'          => json_encode((object)$daten),
            ':listing_setzen' => null === $listing ? 0 : 1,
            ':listing'        => $listing,
            ':parts_id'       => $partsId,
        ]
    );
}

/**
 * Fehler festhalten und werfen
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $partsId Artikel
 * @param string $meldung Fehlertext
 * @return never
 * @throws ApiError EBAY_LISTING_FAILED
 */
function shopEbayFail($db, int $kanal, int $partsId, string $meldung): void {
    shopEbayRecord($db, $kanal, $partsId, 'error', $meldung);
    throw new ApiError('EBAY_LISTING_FAILED', $meldung);
}

/**
 * Stellt einen Artikel bei eBay ein oder aktualisiert sein Angebot
 *
 * Inventory Item → Offer (vorhandenes wiederverwenden) → Publish. Wird der
 * Artikel im Kanal nicht (mehr) angeboten, wird das Angebot beendet.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $partsId Artikel
 * @return string kurzer Stand für das Auftragsergebnis
 * @throws ApiError EBAY_LISTING_FAILED mit der Meldung von eBay
 */
function shopEbayPublishPart($db, int $kanal, int $partsId): string {
    return shopEbayRecordErrors($db, $kanal, $partsId, fn() => shopEbayPublishPartNow($db, $kanal, $partsId));
}

/**
 * Führt einen Abgleich aus und hält jeden Fehler am Artikel fest
 *
 * Fehler aus den Prüfungen hält shopEbayFail() schon fest; die übrigen —
 * fehlende Zugangsdaten, eBay nicht erreichbar — kommen hier dazu, damit die
 * Artikelkarte sie zeigt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $partsId Artikel
 * @param callable $abgleich Einstellen oder Beenden
 * @return string Stand
 * @throws ApiError weitergereicht
 */
function shopEbayRecordErrors($db, int $kanal, int $partsId, callable $abgleich): string {
    try {
        return $abgleich();
    } catch (ApiError $e) {
        if ('EBAY_LISTING_FAILED' !== $e->getId()) {
            shopEbayRecord($db, $kanal, $partsId, 'error', $e->getMessage());
        }
        throw $e;
    }
}

/**
 * Einstellen ohne Fehlerbuchführung — siehe shopEbayPublishPart()
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $partsId Artikel
 * @return string
 */
function shopEbayPublishPartNow($db, int $kanal, int $partsId): string {
    $cfg = shopEbayConfig($db, $kanal);

    // Eine Abfrage für alles, was das Angebot braucht. Der Preis kommt in der
    // Art von parts.sellprice; eBay rechnet brutto.
    $artikel = $db->getOne(
        "SELECT p.partnumber, p.part_type,
                CASE WHEN shop_part_available(p.id, c.id)
                     THEN GREATEST(FLOOR(COALESCE(p.onhand, 0)), 0)::int ELSE 0 END AS menge,
                COALESCE(NULLIF(pc.title, ''), p.description) AS titel,
                COALESCE(NULLIF(pc.description, ''), p.notes, '') AS text,
                COALESCE(pc.active, false) AND c.active AS angeboten,
                COALESCE(pc.settings ->> 'category_id', '') AS kategorie,
                COALESCE(pc.settings ->> 'condition', '') AS zustand,
                COALESCE(pc.sync_data ->> 'offer_id', '') AS offer_id,
                ROUND(CASE WHEN :brutto = 1 THEN k.preis
                           ELSE k.preis * (1 + shop_tax_rate(p.buchungsgruppen_id, :zone)) END, 2) AS preis,
                -- Versand (dev/shop-versand.md, Schritt 8): Mindestabnahme als
                -- Los (W5, W10), Versandrichtlinie der zugeordneten Versandart
                -- (W4), ausgeschlossene Lieferbedingung (W11)
                p.unit, ps.min_qty,
                (SELECT NULLIF(btrim(m.ebay_fulfillment_policy_id), '')
                   FROM shipping_method_shop m
                  WHERE m.id = ps.shipping_method_id AND m.active) AS versandrichtlinie,
                (ps.delivery_term_id IS NOT NULL
                 AND COALESCE(c.settings -> 'excluded_delivery_terms', '[]'::jsonb) @> to_jsonb(ps.delivery_term_id))
                    AS lieferzeit_zu_lang
           FROM parts p
           JOIN sales_channel_shop c ON c.id = CAST(:kanal AS integer)
           LEFT JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.channel_id = c.id
           LEFT JOIN parts_shipping_shop ps ON ps.parts_id = p.id
           CROSS JOIN LATERAL (SELECT shop_channel_price(p.id, c.id) AS preis) k
          WHERE p.id = :parts_id",
        [
            ':parts_id' => $partsId,
            ':kanal'    => $kanal,
            ':brutto'   => shopConfigBool($db, 'shop_tax_included') ? 1 : 0,
            ':zone'     => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
        ]
    );
    if (!$artikel) {
        throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden: '.$partsId);
    }
    if (!in_array($artikel['angeboten'], [true, 't', 1, '1'], true)) {
        return shopEbayEndPartNow($db, $kanal, $partsId);
    }

    $sku = trim((string)$artikel['partnumber']);
    if ('' === $sku) {
        shopEbayFail($db, $kanal, $partsId, 'Artikel hat keine Artikelnummer (SKU erforderlich).');
    }
    // V19: Dienstleistungen haben keinen Bestand und gehören nicht zu eBay
    if ('service' === $artikel['part_type']) {
        shopEbayFail($db, $kanal, $partsId, 'Dienstleistungen werden nicht über eBay angeboten.');
    }

    // W11: eine Lieferzeit, die eBay nicht abbilden kann — nicht anbieten,
    // ein bestehendes Angebot beenden
    if (in_array($artikel['lieferzeit_zu_lang'], [true, 't', 1, '1'], true)) {
        if ('' !== $artikel['offer_id']) {
            shopEbayEndPartNow($db, $kanal, $partsId);
        }
        shopEbayFail($db, $kanal, $partsId, 'Lieferzeit zu lang für eBay (Lieferbedingung in der eBay-Kanalkarte ausgeschlossen) — nicht angeboten.');
    }

    try {
        $bilder = shopEbayImageUrls($db, $kanal, $partsId, $cfg);
    } catch (ApiError $e) {
        shopEbayFail($db, $kanal, $partsId, $e->getMessage());
    }
    if (!$bilder) {
        shopEbayFail($db, $kanal, $partsId, 'Mindestens ein eBay-Bild ist erforderlich.');
    }

    $kategorie = '' !== $artikel['kategorie'] ? $artikel['kategorie'] : trim((string)($cfg['default_category_id'] ?? ''));
    $zustand   = '' !== $artikel['zustand'] ? $artikel['zustand'] : (trim((string)($cfg['default_condition'] ?? '')) ?: 'NEW');
    $lagerort  = trim((string)($cfg['merchant_location_key'] ?? ''));
    $zahlung   = trim((string)($cfg['payment_policy_id'] ?? ''));
    $rueckgabe = trim((string)($cfg['return_policy_id'] ?? ''));
    // W4: die Versandrichtlinie der zugeordneten Versandart, sonst die allgemeine
    $versand   = trim((string)($artikel['versandrichtlinie'] ?? '')) ?: trim((string)($cfg['fulfillment_policy_id'] ?? ''));
    $markt     = trim((string)($cfg['marketplace_id'] ?? '')) ?: 'EBAY_DE';
    $waehrung  = trim((string)($cfg['currency'] ?? '')) ?: 'EUR';

    $fehlt = array_keys(array_filter([
        'Kategorie' => '' === $kategorie, 'Lagerort' => '' === $lagerort, 'Zahlungs-Policy' => '' === $zahlung,
        'Rücknahme-Policy' => '' === $rueckgabe, 'Versand-Policy' => '' === $versand,
    ]));
    if ($fehlt) {
        shopEbayFail($db, $kanal, $partsId, 'eBay-Einstellungen unvollständig: '.implode(', ', $fehlt)
            ." (Verkaufskanal '".($cfg['name'] ?? $kanal)."').");
    }

    // V19: Menge = Bestand. Ein neues Angebot ohne Bestand nimmt eBay nicht
    // an; ein bestehendes bleibt bei 0 als „ausverkauft" stehen, wenn im
    // eBay-Konto die Einstellung „Out-of-Stock Control" gesetzt ist.
    //
    // W5, W10: Mit Mindestabnahme wird ein Los angeboten — eBay kennt keine
    // Mindestmenge je Käufer. Preis je Los, Menge in Losen (abgerundet), der
    // Titel nennt die Losgröße; die Bestellung rechnet in Stück zurück
    // (shopEbayImportOrder).
    $los   = null !== $artikel['min_qty'] && (float)$artikel['min_qty'] > 1 ? (int)ceil((float)$artikel['min_qty']) : 1;
    $menge = intdiv((int)$artikel['menge'], $los);
    if (0 === $menge && '' === $artikel['offer_id']) {
        shopEbayFail($db, $kanal, $partsId, 1 === $los
            ? 'Kein Bestand — ein neues eBay-Angebot braucht mindestens 1 Stück.'
            : 'Zu wenig Bestand — ein neues eBay-Angebot braucht mindestens ein Los ('.$los.' Stück).');
    }

    $zusatz = $los > 1 ? ' – '.$los.' '.(trim((string)$artikel['unit']) ?: 'Stück') : '';
    $titel = mb_substr(trim((string)$artikel['titel']) ?: $sku, 0, 80 - mb_strlen($zusatz)).$zusatz;
    $text  = trim((string)$artikel['text']) ?: $titel;
    $preis = number_format((float)$artikel['preis'] * $los, 2, '.', '');
    $skuPfad = rawurlencode($sku);

    // 1. Inventory Item
    $antwort = shopEbayApi($db, $kanal, 'PUT', '/sell/inventory/v1/inventory_item/'.$skuPfad, [
        'availability' => ['shipToLocationAvailability' => ['quantity' => $menge]],
        'condition'    => $zustand,
        'product'      => ['title' => $titel, 'description' => $text, 'imageUrls' => $bilder],
    ]);
    if ($antwort['status'] >= 400) {
        shopEbayFail($db, $kanal, $partsId, 'Produktdaten abgelehnt: '.shopEbayErrorMessage($antwort));
    }

    // 2. Offer — gemerktes wiederverwenden, sonst bei eBay nachsehen, sonst neu
    $angebot = [
        'sku'                 => $sku,
        'marketplaceId'       => $markt,
        'format'              => 'FIXED_PRICE',
        'availableQuantity'   => $menge,
        'categoryId'          => $kategorie,
        'listingDescription'  => $text,
        'listingPolicies'     => [
            'paymentPolicyId'     => $zahlung,
            'returnPolicyId'      => $rueckgabe,
            'fulfillmentPolicyId' => $versand,
        ],
        'pricingSummary'      => ['price' => ['currency' => $waehrung, 'value' => $preis]],
        'merchantLocationKey' => $lagerort,
    ];
    if ($los > 1) {
        $angebot['lotSize'] = $los;
    }
    $offerId = $artikel['offer_id'];
    if ('' === $offerId) {
        $vorhanden = shopEbayApi($db, $kanal, 'GET', '/sell/inventory/v1/offer', null,
            ['sku' => $sku, 'marketplace_id' => $markt, 'limit' => 1]);
        if ($vorhanden['status'] < 400 && !empty($vorhanden['body']['offers'][0]['offerId'])) {
            $offerId = (string)$vorhanden['body']['offers'][0]['offerId'];
        }
    }
    if ('' !== $offerId) {
        $antwort = shopEbayApi($db, $kanal, 'PUT', '/sell/inventory/v1/offer/'.rawurlencode($offerId), $angebot);
        if ($antwort['status'] >= 400) {
            shopEbayFail($db, $kanal, $partsId, 'Angebot (Aktualisierung) abgelehnt: '.shopEbayErrorMessage($antwort));
        }
    } else {
        $antwort = shopEbayApi($db, $kanal, 'POST', '/sell/inventory/v1/offer', $angebot);
        if ($antwort['status'] >= 400 || empty($antwort['body']['offerId'])) {
            shopEbayFail($db, $kanal, $partsId, 'Angebot abgelehnt: '.shopEbayErrorMessage($antwort));
        }
        $offerId = (string)$antwort['body']['offerId'];
    }
    shopEbayRecord($db, $kanal, $partsId, 'pending', '', ['offer_id' => $offerId]);

    // 3. Publish — bei einem schon veröffentlichten Angebot wirkt die
    // Aktualisierung oben sofort, eBay bestätigt das Publish dann erneut
    $antwort = shopEbayApi($db, $kanal, 'POST', '/sell/inventory/v1/offer/'.rawurlencode($offerId).'/publish');
    if ($antwort['status'] >= 400) {
        shopEbayFail($db, $kanal, $partsId, 'Veröffentlichen abgelehnt: '.shopEbayErrorMessage($antwort));
    }
    $listing = (string)($antwort['body']['listingId'] ?? '');
    shopEbayRecord($db, $kanal, $partsId, 'active', '', [], '' !== $listing ? $listing : null);

    return 1 === $los
        ? sprintf('eingestellt (%s EUR, %d Stück)', $preis, $menge)
        : sprintf('eingestellt (%s EUR je Los zu %d, %d Lose)', $preis, $los, $menge);
}

/**
 * Beendet das eBay-Angebot eines Artikels (withdraw)
 *
 * Ein bei eBay schon beendetes Angebot (404) gilt als beendet.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $partsId Artikel
 * @return string kurzer Stand
 * @throws ApiError EBAY_LISTING_FAILED
 */
function shopEbayEndPart($db, int $kanal, int $partsId): string {
    return shopEbayRecordErrors($db, $kanal, $partsId, fn() => shopEbayEndPartNow($db, $kanal, $partsId));
}

/**
 * Beenden ohne Fehlerbuchführung — siehe shopEbayEndPart()
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $partsId Artikel
 * @return string
 */
function shopEbayEndPartNow($db, int $kanal, int $partsId): string {
    $zeile = $db->getOne(
        "SELECT COALESCE(sync_data ->> 'offer_id', '') AS offer_id
           FROM parts_channel_shop
          WHERE parts_id = :parts_id AND channel_id = CAST(:kanal AS integer)",
        [':parts_id' => $partsId, ':kanal' => $kanal]
    );
    $offerId = (string)($zeile['offer_id'] ?? '');
    if ('' === $offerId) {
        return 'kein Angebot';
    }

    $antwort = shopEbayApi($db, $kanal, 'POST', '/sell/inventory/v1/offer/'.rawurlencode($offerId).'/withdraw');
    if ($antwort['status'] >= 400 && 404 !== $antwort['status']) {
        shopEbayFail($db, $kanal, $partsId, 'Beenden abgelehnt: '.shopEbayErrorMessage($antwort));
    }
    // Die Angebotskennung bleibt: ein Wiedereinstellen verwendet sie weiter
    shopEbayRecord($db, $kanal, $partsId, 'ended', '');
    return 'beendet';
}

// Bestellimport
require_once __DIR__.'/ebay_orders.php';
