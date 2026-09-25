<?php
// backend/api/shop/channels/ebay.php
//
// Verkaufskanal eBay (dev/shop-verkaufskanaele.md, Schritt 5). Ersetzt die
// bisherige Anbindung unter backend/api/ebay/ (V15). Zugang und API-Aufrufe
// sind von dort übernommen, das Einstellen liest jetzt den Kanal:
//
//   Preis          shop_channel_price(…, 'ebay'), brutto (eBay rechnet brutto)
//   Titel, Text    Beschreibung und Langbeschreibung im Kanal, sonst Stammdaten
//   Menge          Lagerbestand parts.onhand (V4, V19) statt fester Menge
//   Bilder         Bilder des eBay-Kanals (parts_channel_image_shop, V12)
//   Kategorie,     je Artikel im Kanal (parts_channel_shop.settings), sonst
//   Zustand        die Vorgaben ebay_default_category_id / ebay_default_condition
//
// Eingestellt und beendet wird über die Warteschlange (Aufträge mit Kanal
// ebay); die Trigger im Shop-Schema legen die Aufträge an, wenn sich Preis,
// Texte, Bestand oder die Auswahl ändern. Die Einstellungen bleiben unter
// ihren bisherigen Schlüsseln ebay_* in defaults_oserp; eingeschaltet ist
// der Kanal über sales_channel_shop.active.
//
// Der Bestellimport steht in channels/ebay_orders.php.

const SHOP_EBAY_OAUTH_PROD     = 'https://api.ebay.com/identity/v1/oauth2/token';
const SHOP_EBAY_OAUTH_SANDBOX  = 'https://api.sandbox.ebay.com/identity/v1/oauth2/token';
const SHOP_EBAY_API_PROD       = 'https://api.ebay.com';
const SHOP_EBAY_API_SANDBOX    = 'https://api.sandbox.ebay.com';
// Bestellungen lesen (Import) und Inventar schreiben (Angebote)
const SHOP_EBAY_SCOPES         = 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'
                               .' https://api.ebay.com/oauth/api_scope/sell.inventory';
const SHOP_EBAY_TOKEN_TTL      = 7200; // falls eBay kein expires_in liefert
const SHOP_EBAY_TOKEN_SKEW     = 300;  // Puffer vor Ablauf

// ── Einstellungen und Zugang ──

/**
 * Alle ebay_*-Einstellungen
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Schlüssel => Wert
 */
function shopEbayConfig($db): array {
    $zeilen = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'ebay\\_%' ESCAPE '\\'") ?: [];
    return array_column($zeilen, 'value', 'key');
}

/**
 * Schreibt einen Laufzeitwert (Token-Cache, letzter Abruf)
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $key Schlüssel
 * @param string $value Wert
 * @return void
 */
function shopEbaySetConfig($db, string $key, string $value): void {
    $db->execute(
        "INSERT INTO defaults_oserp (key, value, mtime) VALUES (:key, :value, now())
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
        [':key' => $key, ':value' => $value]
    );
}

/**
 * Basisadresse der REST-API je nach Umgebung
 *
 * @param array $cfg Ergebnis von shopEbayConfig()
 * @return string
 */
function shopEbayApiBase(array $cfg): string {
    return 'sandbox' === ($cfg['ebay_environment'] ?? 'production') ? SHOP_EBAY_API_SANDBOX : SHOP_EBAY_API_PROD;
}

/**
 * Gültiges Zugriffstoken, notfalls über das Refresh-Token neu geholt
 *
 * Zwischengespeichert in ebay_access_token / ebay_access_token_exp — beide
 * gehen nie an den Browser (oserp_config/defaults.php).
 *
 * @param object $db Company-Datenbankverbindung
 * @param bool $erneuern Zwischenspeicher übergehen
 * @return string
 * @throws ApiError EBAY_NO_CREDENTIALS, EBAY_AUTH_FAILED
 */
function shopEbayToken($db, bool $erneuern = false): string {
    $cfg = shopEbayConfig($db);

    if (!$erneuern
        && !empty($cfg['ebay_access_token'])
        && (int)($cfg['ebay_access_token_exp'] ?? 0) > time() + SHOP_EBAY_TOKEN_SKEW) {
        return $cfg['ebay_access_token'];
    }

    $clientId = trim($cfg['ebay_client_id'] ?? '');
    $secret   = trim($cfg['ebay_client_secret'] ?? '');
    $refresh  = trim($cfg['ebay_refresh_token'] ?? '');
    if ('' === $clientId || '' === $secret || '' === $refresh) {
        throw new ApiError('EBAY_NO_CREDENTIALS', 'eBay-Zugangsdaten sind nicht eingerichtet (Einstellungen → Shop → eBay)');
    }

    $curl = curl_init('sandbox' === ($cfg['ebay_environment'] ?? 'production') ? SHOP_EBAY_OAUTH_SANDBOX : SHOP_EBAY_OAUTH_PROD);
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
    shopEbaySetConfig($db, 'ebay_access_token', $daten['access_token']);
    shopEbaySetConfig($db, 'ebay_access_token_exp', (string)(time() + ($dauer > 0 ? $dauer : SHOP_EBAY_TOKEN_TTL)));

    return $daten['access_token'];
}

/**
 * Aufruf der eBay-REST-API
 *
 * Wirft nur bei Transportfehlern; eBay-Fehler (4xx) kommen mit Status und
 * Inhalt zurück, damit der Aufrufer Validierungsfehler selbst auswertet. Bei
 * 401 wird das Token einmal erneuert und der Aufruf wiederholt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $methode GET, POST, PUT oder DELETE
 * @param string $pfad etwa /sell/inventory/v1/offer
 * @param array|null $inhalt JSON-Inhalt
 * @param array $abfrage Abfrageparameter (GET)
 * @param bool $nochmal intern
 * @return array ['status' => int, 'body' => array]
 * @throws ApiError EBAY_API_ERROR bei Transportfehlern
 */
function shopEbayApi($db, string $methode, string $pfad, ?array $inhalt = null, array $abfrage = [], bool $nochmal = true): array {
    $cfg   = shopEbayConfig($db);
    $token = shopEbayToken($db);
    $url   = shopEbayApiBase($cfg).$pfad.($abfrage ? '?'.http_build_query($abfrage) : '');

    $kopf = [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json',
        'Accept: application/json',
        'Content-Language: '.($cfg['ebay_content_language'] ?? 'de-DE'),
    ];
    if (!empty($cfg['ebay_marketplace_id'])) {
        $kopf[] = 'X-EBAY-C-MARKETPLACE-ID: '.$cfg['ebay_marketplace_id'];
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
        shopEbayToken($db, true);
        return shopEbayApi($db, $methode, $pfad, $inhalt, $abfrage, false);
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
 * @param bool $an eingeschaltet
 * @return void
 */
function shopChannelEbaySwitched($db, bool $an): void {
    $db->execute(
        "DELETE FROM batchjob_hugoshop
          WHERE result IS NULL
            AND channel_id = shop_channel_id('ebay')
            AND function = ANY(string_to_array(:gegenrichtung, ','))",
        [':gegenrichtung' => $an ? 'remove_all,remove_part' : 'publish_all,publish_part']
    );
    shopQueueJob($db, $an ? 'publish_all' : 'remove_all', '', null, 'ebay');
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

    switch ($auftrag['function']) {
        case 'publish_part':
        case 'remove_part':
            $artikel = $db->getOne("SELECT id FROM parts WHERE partnumber = :nr", [':nr' => $auftrag['partnumber']]);
            if (!$artikel) {
                throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden: '.$auftrag['partnumber']);
            }
            $ergebnis = 'publish_part' === $auftrag['function']
                ? shopEbayPublishPart($db, (int)$artikel['id'])
                : shopEbayEndPart($db, (int)$artikel['id']);
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
                  WHERE pc.channel_id = shop_channel_id('ebay')
                    AND CASE WHEN :einstellen = 1 THEN pc.active
                             ELSE COALESCE(pc.sync_data ->> 'offer_id', '') <> ''
                                  AND COALESCE(pc.sync_status, '') <> 'ended' END
                  ORDER BY p.id",
                [':einstellen' => $einstellen ? 1 : 0]
            );
            $gut = 0;
            $gescheitert = 0;
            foreach ($artikelListe as $artikel) {
                try {
                    $einstellen ? shopEbayPublishPart($db, (int)$artikel['id']) : shopEbayEndPart($db, (int)$artikel['id']);
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
 * @param int $partsId Artikel
 * @param array $cfg Ergebnis von shopEbayConfig()
 * @return array Adressen, Hauptbild zuerst
 * @throws ApiError EBAY_NO_PUBLIC_HOST
 */
function shopEbayImageUrls($db, int $partsId, array $cfg): array {
    $host = trim((string)($cfg['ebay_public_host'] ?? ''));
    $host = preg_replace('#^https?://#i', '', rtrim($host, '/'));
    if ('' === $host) {
        throw new ApiError('EBAY_NO_PUBLIC_HOST',
            'Öffentliche Adresse für die Bilder fehlt (Einstellungen → Shop → eBay: ebay_public_host)');
    }

    $zeilen = $db->getAll(
        "SELECT '/webhook/part-image.php?db=' || current_database() || '&id=' || i.parts_id
                || '&f=' || i.filename AS pfad
           FROM parts_channel_image_shop i
          WHERE i.parts_id = :parts_id AND i.channel_id = shop_channel_id('ebay')
          ORDER BY i.sort, i.id",
        [':parts_id' => $partsId]
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
 * @param int $partsId Artikel
 * @param string $stand active, ended oder error
 * @param string $meldung Fehlertext, leer bei Erfolg
 * @param array $daten zusätzlich zu merken (offer_id); null-Werte löschen den Eintrag
 * @param string|null $listing Listing-Kennung, null = unverändert
 * @return void
 */
function shopEbayRecord($db, int $partsId, string $stand, string $meldung, array $daten = [], ?string $listing = null): void {
    $db->execute(
        "UPDATE parts_channel_shop
            SET sync_status = :stand,
                sync_error  = NULLIF(:meldung, ''),
                sync_mtime  = now(),
                sync_data   = jsonb_strip_nulls(COALESCE(sync_data, '{}'::jsonb) || :daten::jsonb),
                external_id = CASE WHEN :listing_setzen = 1 THEN :listing ELSE external_id END
          WHERE parts_id = :parts_id AND channel_id = shop_channel_id('ebay')",
        [
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
 * @param int $partsId Artikel
 * @param string $meldung Fehlertext
 * @return never
 * @throws ApiError EBAY_LISTING_FAILED
 */
function shopEbayFail($db, int $partsId, string $meldung): void {
    shopEbayRecord($db, $partsId, 'error', $meldung);
    throw new ApiError('EBAY_LISTING_FAILED', $meldung);
}

/**
 * Stellt einen Artikel bei eBay ein oder aktualisiert sein Angebot
 *
 * Inventory Item → Offer (vorhandenes wiederverwenden) → Publish. Wird der
 * Artikel im Kanal nicht (mehr) angeboten, wird das Angebot beendet.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @return string kurzer Stand für das Auftragsergebnis
 * @throws ApiError EBAY_LISTING_FAILED mit der Meldung von eBay
 */
function shopEbayPublishPart($db, int $partsId): string {
    return shopEbayRecordErrors($db, $partsId, fn() => shopEbayPublishPartNow($db, $partsId));
}

/**
 * Führt einen Abgleich aus und hält jeden Fehler am Artikel fest
 *
 * Fehler aus den Prüfungen hält shopEbayFail() schon fest; die übrigen —
 * fehlende Zugangsdaten, eBay nicht erreichbar — kommen hier dazu, damit die
 * Artikelkarte sie zeigt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param callable $abgleich Einstellen oder Beenden
 * @return string Stand
 * @throws ApiError weitergereicht
 */
function shopEbayRecordErrors($db, int $partsId, callable $abgleich): string {
    try {
        return $abgleich();
    } catch (ApiError $e) {
        if ('EBAY_LISTING_FAILED' !== $e->getId()) {
            shopEbayRecord($db, $partsId, 'error', $e->getMessage());
        }
        throw $e;
    }
}

/**
 * Einstellen ohne Fehlerbuchführung — siehe shopEbayPublishPart()
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @return string
 */
function shopEbayPublishPartNow($db, int $partsId): string {
    $cfg = shopEbayConfig($db);

    // Eine Abfrage für alles, was das Angebot braucht. Der Preis kommt in der
    // Art von parts.sellprice; eBay rechnet brutto.
    $artikel = $db->getOne(
        "SELECT p.partnumber, p.part_type, GREATEST(FLOOR(COALESCE(p.onhand, 0)), 0)::int AS menge,
                COALESCE(NULLIF(pc.title, ''), p.description) AS titel,
                COALESCE(NULLIF(pc.description, ''), p.notes, '') AS text,
                COALESCE(pc.active, false) AND c.active AS angeboten,
                COALESCE(pc.settings ->> 'category_id', '') AS kategorie,
                COALESCE(pc.settings ->> 'condition', '') AS zustand,
                COALESCE(pc.sync_data ->> 'offer_id', '') AS offer_id,
                ROUND(CASE WHEN :brutto = 1 THEN k.preis
                           ELSE k.preis * (1 + shop_tax_rate(p.buchungsgruppen_id, :zone)) END, 2) AS preis
           FROM parts p
           JOIN sales_channel_shop c ON c.type = 'ebay'
           LEFT JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.channel_id = c.id
           CROSS JOIN LATERAL (SELECT shop_channel_price(p.id, 'ebay') AS preis) k
          WHERE p.id = :parts_id",
        [
            ':parts_id' => $partsId,
            ':brutto'   => shopConfigBool($db, 'shop_tax_included') ? 1 : 0,
            ':zone'     => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
        ]
    );
    if (!$artikel) {
        throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden: '.$partsId);
    }
    if (!in_array($artikel['angeboten'], [true, 't', 1, '1'], true)) {
        return shopEbayEndPartNow($db, $partsId);
    }

    $sku = trim((string)$artikel['partnumber']);
    if ('' === $sku) {
        shopEbayFail($db, $partsId, 'Artikel hat keine Artikelnummer (SKU erforderlich).');
    }
    // V19: Dienstleistungen haben keinen Bestand und gehören nicht zu eBay
    if ('service' === $artikel['part_type']) {
        shopEbayFail($db, $partsId, 'Dienstleistungen werden nicht über eBay angeboten.');
    }

    try {
        $bilder = shopEbayImageUrls($db, $partsId, $cfg);
    } catch (ApiError $e) {
        shopEbayFail($db, $partsId, $e->getMessage());
    }
    if (!$bilder) {
        shopEbayFail($db, $partsId, 'Mindestens ein eBay-Bild ist erforderlich.');
    }

    $kategorie = '' !== $artikel['kategorie'] ? $artikel['kategorie'] : trim($cfg['ebay_default_category_id'] ?? '');
    $zustand   = '' !== $artikel['zustand'] ? $artikel['zustand'] : trim($cfg['ebay_default_condition'] ?? 'NEW');
    $lagerort  = trim($cfg['ebay_merchant_location_key'] ?? '');
    $zahlung   = trim($cfg['ebay_payment_policy_id'] ?? '');
    $rueckgabe = trim($cfg['ebay_return_policy_id'] ?? '');
    $versand   = trim($cfg['ebay_fulfillment_policy_id'] ?? '');
    $markt     = trim($cfg['ebay_marketplace_id'] ?? 'EBAY_DE') ?: 'EBAY_DE';
    $waehrung  = trim($cfg['ebay_currency'] ?? 'EUR') ?: 'EUR';

    $fehlt = array_keys(array_filter([
        'Kategorie' => '' === $kategorie, 'Lagerort' => '' === $lagerort, 'Zahlungs-Policy' => '' === $zahlung,
        'Rücknahme-Policy' => '' === $rueckgabe, 'Versand-Policy' => '' === $versand,
    ]));
    if ($fehlt) {
        shopEbayFail($db, $partsId, 'eBay-Einstellungen unvollständig: '.implode(', ', $fehlt).' (Einstellungen → Shop → eBay).');
    }

    // V19: Menge = Bestand. Ein neues Angebot ohne Bestand nimmt eBay nicht
    // an; ein bestehendes bleibt bei 0 als „ausverkauft" stehen, wenn im
    // eBay-Konto die Einstellung „Out-of-Stock Control" gesetzt ist.
    $menge = (int)$artikel['menge'];
    if (0 === $menge && '' === $artikel['offer_id']) {
        shopEbayFail($db, $partsId, 'Kein Bestand — ein neues eBay-Angebot braucht mindestens 1 Stück.');
    }

    $titel = mb_substr(trim((string)$artikel['titel']) ?: $sku, 0, 80);
    $text  = trim((string)$artikel['text']) ?: $titel;
    $preis = number_format((float)$artikel['preis'], 2, '.', '');
    $skuPfad = rawurlencode($sku);

    // 1. Inventory Item
    $antwort = shopEbayApi($db, 'PUT', '/sell/inventory/v1/inventory_item/'.$skuPfad, [
        'availability' => ['shipToLocationAvailability' => ['quantity' => $menge]],
        'condition'    => $zustand,
        'product'      => ['title' => $titel, 'description' => $text, 'imageUrls' => $bilder],
    ]);
    if ($antwort['status'] >= 400) {
        shopEbayFail($db, $partsId, 'Produktdaten abgelehnt: '.shopEbayErrorMessage($antwort));
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
    $offerId = $artikel['offer_id'];
    if ('' === $offerId) {
        $vorhanden = shopEbayApi($db, 'GET', '/sell/inventory/v1/offer', null,
            ['sku' => $sku, 'marketplace_id' => $markt, 'limit' => 1]);
        if ($vorhanden['status'] < 400 && !empty($vorhanden['body']['offers'][0]['offerId'])) {
            $offerId = (string)$vorhanden['body']['offers'][0]['offerId'];
        }
    }
    if ('' !== $offerId) {
        $antwort = shopEbayApi($db, 'PUT', '/sell/inventory/v1/offer/'.rawurlencode($offerId), $angebot);
        if ($antwort['status'] >= 400) {
            shopEbayFail($db, $partsId, 'Angebot (Aktualisierung) abgelehnt: '.shopEbayErrorMessage($antwort));
        }
    } else {
        $antwort = shopEbayApi($db, 'POST', '/sell/inventory/v1/offer', $angebot);
        if ($antwort['status'] >= 400 || empty($antwort['body']['offerId'])) {
            shopEbayFail($db, $partsId, 'Angebot abgelehnt: '.shopEbayErrorMessage($antwort));
        }
        $offerId = (string)$antwort['body']['offerId'];
    }
    shopEbayRecord($db, $partsId, 'pending', '', ['offer_id' => $offerId]);

    // 3. Publish — bei einem schon veröffentlichten Angebot wirkt die
    // Aktualisierung oben sofort, eBay bestätigt das Publish dann erneut
    $antwort = shopEbayApi($db, 'POST', '/sell/inventory/v1/offer/'.rawurlencode($offerId).'/publish');
    if ($antwort['status'] >= 400) {
        shopEbayFail($db, $partsId, 'Veröffentlichen abgelehnt: '.shopEbayErrorMessage($antwort));
    }
    $listing = (string)($antwort['body']['listingId'] ?? '');
    shopEbayRecord($db, $partsId, 'active', '', [], '' !== $listing ? $listing : null);

    return sprintf('eingestellt (%s EUR, %d Stück)', $preis, $menge);
}

/**
 * Beendet das eBay-Angebot eines Artikels (withdraw)
 *
 * Ein bei eBay schon beendetes Angebot (404) gilt als beendet.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @return string kurzer Stand
 * @throws ApiError EBAY_LISTING_FAILED
 */
function shopEbayEndPart($db, int $partsId): string {
    return shopEbayRecordErrors($db, $partsId, fn() => shopEbayEndPartNow($db, $partsId));
}

/**
 * Beenden ohne Fehlerbuchführung — siehe shopEbayEndPart()
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @return string
 */
function shopEbayEndPartNow($db, int $partsId): string {
    $zeile = $db->getOne(
        "SELECT COALESCE(sync_data ->> 'offer_id', '') AS offer_id
           FROM parts_channel_shop
          WHERE parts_id = :parts_id AND channel_id = shop_channel_id('ebay')",
        [':parts_id' => $partsId]
    );
    $offerId = (string)($zeile['offer_id'] ?? '');
    if ('' === $offerId) {
        return 'kein Angebot';
    }

    $antwort = shopEbayApi($db, 'POST', '/sell/inventory/v1/offer/'.rawurlencode($offerId).'/withdraw');
    if ($antwort['status'] >= 400 && 404 !== $antwort['status']) {
        shopEbayFail($db, $partsId, 'Beenden abgelehnt: '.shopEbayErrorMessage($antwort));
    }
    // Die Angebotskennung bleibt: ein Wiedereinstellen verwendet sie weiter
    shopEbayRecord($db, $partsId, 'ended', '');
    return 'beendet';
}

// Bestellimport
require_once __DIR__.'/ebay_orders.php';
