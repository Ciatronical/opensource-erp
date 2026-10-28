<?php
// backend/api/shop/channels/ebay_listings.php
//
// Übernommene eBay-Angebote und Anmeldung bei eBay (dev/shop-ebay.md).
//
//   Anmeldung   „Mit eBay verbinden“: OAuth-Zustimmung bei eBay im Popup,
//               Rücksprung über backend/webhook/ebay-oauth.php, der den Code
//               an das OSERP-Fenster gibt; finishEbayConnect tauscht ihn hier
//               gegen Refresh- und Zugriffstoken. Kein Token mehr von Hand.
//   Angebote    GetSellerList (Trading-API) liest alle laufenden Angebote des
//               Kontos — auch die im Seller Hub eingestellten, die die
//               Inventory-API nicht kennt — nach ebay_listing_shop; danach
//               ordnet ebay_listing_autolink() sie den Artikeln zu.
//   Abgleich    Für zugeordnete Angebote ändert shopEbayReviseLegacy() Menge,
//               Preis und Text des bestehenden Angebots (ReviseInventoryStatus,
//               ReviseFixedPriceItem) — je nach legacy_sync des Kanals.
//
// Die Trading-API nimmt dasselbe OAuth-Token wie die REST-Aufrufe.

/** Felder, die GetSellerList liefern soll — ohne Beschreibung, die macht die Antwort riesig */
const SHOP_EBAY_LISTING_FIELDS = [
    'ItemArray.Item.ItemID', 'ItemArray.Item.SKU', 'ItemArray.Item.Title', 'ItemArray.Item.Quantity',
    'ItemArray.Item.SellingStatus', 'ItemArray.Item.ListingDetails', 'ItemArray.Item.PictureDetails',
    'ItemArray.Item.ProductListingDetails', 'ItemArray.Item.ItemSpecifics', 'ItemArray.Item.Variations',
    'ItemArray.Item.ListingType', 'ItemArray.Item.ConditionDisplayName', 'ItemArray.Item.PrimaryCategory',
    'ItemArray.Item.LotSize', 'ItemArray.Item.WatchCount', 'ItemArray.Item.StartPrice', 'ItemArray.Item.Currency',
    'PaginationResult', 'HasMoreItems', 'Ack', 'Errors',
];
const SHOP_EBAY_LISTINGS_PER_PAGE = 100;
/** Merkmalsnamen, aus denen der Abgleich die Herstellernummer und Marke liest */
const SHOP_EBAY_MPN_NAMES = ['herstellernummer', 'hersteller-teilenummer', 'herstellerteilenummer', 'mpn', 'manufacturer part number'];
const SHOP_EBAY_BRAND_NAMES = ['marke', 'brand', 'hersteller'];

// ── Trading-API ──

/**
 * Aufruf der Trading-API (XML)
 *
 * Bei einem abgelaufenen Token (Fehler 21917053, 931, 932) wird es einmal
 * erneuert. Ack = Failure wirft mit den Meldungen von eBay; Warnungen
 * bleiben in der Antwort.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param string $aufruf Name, etwa GetSellerList
 * @param string $inhalt XML im Request-Element (ohne Hülle)
 * @param bool $nochmal intern
 * @return SimpleXMLElement
 * @throws ApiError EBAY_API_ERROR
 */
function shopEbayTrading($db, int $kanal, string $aufruf, string $inhalt, bool $nochmal = true): SimpleXMLElement {
    $token = shopEbayToken($db, $kanal);
    $cfg = shopEbayConfig($db, $kanal);
    $markt = trim((string)($cfg['marketplace_id'] ?? '')) ?: 'EBAY_DE';

    $curl = curl_init('sandbox' === ($cfg['environment'] ?? 'production') ? SHOP_EBAY_TRADING_SANDBOX : SHOP_EBAY_TRADING_PROD);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'X-EBAY-API-CALL-NAME: '.$aufruf,
            'X-EBAY-API-SITEID: '.(SHOP_EBAY_SITE_IDS[$markt] ?? 77),
            'X-EBAY-API-COMPATIBILITY-LEVEL: '.SHOP_EBAY_TRADING_LEVEL,
            'X-EBAY-API-IAF-TOKEN: '.$token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POSTFIELDS     => '<?xml version="1.0" encoding="utf-8"?>'
            .'<'.$aufruf.'Request xmlns="urn:ebay:apis:eBLBaseComponents">'.$inhalt.'</'.$aufruf.'Request>',
    ]);
    $antwort = curl_exec($curl);
    $fehler = curl_error($curl);
    curl_close($curl);
    if ($fehler || false === $antwort) {
        throw new ApiError('EBAY_API_ERROR', 'eBay-API nicht erreichbar: '.$fehler);
    }

    $xml = @simplexml_load_string((string)$antwort);
    if (false === $xml) {
        throw new ApiError('EBAY_API_ERROR', 'Unlesbare Antwort von eBay ('.$aufruf.')');
    }
    if ('Failure' === (string)$xml->Ack) {
        $codes = [];
        foreach ($xml->Errors as $f) {
            $codes[] = (string)$f->ErrorCode;
        }
        if ($nochmal && array_intersect($codes, ['21917053', '931', '932', '21916984'])) {
            shopEbayToken($db, $kanal, true);
            return shopEbayTrading($db, $kanal, $aufruf, $inhalt, false);
        }
        throw new ApiError('EBAY_API_ERROR', 'eBay ('.$aufruf.'): '.shopEbayTradingErrors($xml));
    }
    return $xml;
}

/**
 * Lesbare Fehler einer Trading-Antwort, nur Fehler (keine Warnungen)
 *
 * @param SimpleXMLElement $xml Antwort
 * @return string
 */
function shopEbayTradingErrors(SimpleXMLElement $xml): string {
    $teile = [];
    foreach ($xml->Errors as $f) {
        if ('Warning' === (string)$f->SeverityCode) {
            continue;
        }
        $teile[] = trim((string)$f->LongMessage ?: (string)$f->ShortMessage).' ['.(string)$f->ErrorCode.']';
    }
    return $teile ? implode(' | ', array_unique($teile)) : 'unbekannter Fehler';
}

/**
 * Text für XML
 *
 * @param string $text Text
 * @return string
 */
function shopEbayXml(string $text): string {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ── Anmeldung bei eBay ──

/**
 * Zustand des OAuth-Aufrufs: Kanal und Zeit, signiert mit dem Client-Secret
 *
 * @param int $kanal eBay-Kanal
 * @param string $geheim client_secret des Kanals
 * @param int|null $zeit Zeitpunkt, null = jetzt
 * @return string
 */
function shopEbayOauthState(int $kanal, string $geheim, ?int $zeit = null): string {
    $nutzlast = rtrim(strtr(base64_encode(json_encode(['k' => $kanal, 't' => $zeit ?? time()])), '+/', '-_'), '=');
    return $nutzlast.'.'.substr(hash_hmac('sha256', $nutzlast, $geheim), 0, 32);
}

/**
 * Adresse für die Zustimmung bei eBay
 *
 * Braucht Client-ID, Client-Secret und den RuName (eBay-Name der
 * Rücksprungadresse) des Kanals. Bei eBay muss als „Auth accepted URL“
 * des RuName die Adresse von backend/webhook/ebay-oauth.php stehen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @return string
 * @throws ApiError EBAY_NO_CREDENTIALS
 */
function shopEbayConsentUrl($db, int $kanal): string {
    $cfg = shopEbayConfig($db, $kanal);
    $fehlt = array_keys(array_filter([
        'Client-ID' => '' === trim((string)($cfg['client_id'] ?? '')),
        'Client-Secret' => '' === trim((string)($cfg['client_secret'] ?? '')),
        'RuName' => '' === trim((string)($cfg['ru_name'] ?? '')),
    ]));
    if ($fehlt) {
        throw new ApiError('EBAY_NO_CREDENTIALS', 'Für „Mit eBay verbinden“ fehlt im Kanal: '.implode(', ', $fehlt));
    }
    $basis = 'sandbox' === ($cfg['environment'] ?? 'production') ? SHOP_EBAY_AUTHORIZE_SANDBOX : SHOP_EBAY_AUTHORIZE_PROD;
    return $basis.'?'.http_build_query([
        'client_id'     => trim((string)$cfg['client_id']),
        'redirect_uri'  => trim((string)$cfg['ru_name']),
        'response_type' => 'code',
        'scope'         => SHOP_EBAY_SCOPES_CONNECT,
        'state'         => shopEbayOauthState($kanal, trim((string)$cfg['client_secret'])),
        'prompt'        => 'login',
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Tauscht den Code aus der Zustimmung gegen Token und merkt sie im Kanal
 *
 * Prüft den Zustand (Signatur, höchstens eine Stunde alt). Danach gilt der
 * Kanal als verbunden: Refresh-Token, Rechte (scopes), Konto.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param string $code Code aus dem Rücksprung
 * @param string $zustand state aus dem Rücksprung
 * @return string Konto, leer wenn nicht bestimmbar
 * @throws ApiError EBAY_AUTH_FAILED, EBAY_ACCOUNT_IN_USE
 */
function shopEbayExchangeCode($db, int $kanal, string $code, string $zustand): string {
    $cfg = shopEbayConfig($db, $kanal);
    $geheim = trim((string)($cfg['client_secret'] ?? ''));
    [$nutzlast] = explode('.', $zustand.'.');
    $angaben = json_decode((string)base64_decode(strtr($nutzlast, '-_', '+/')), true) ?: [];
    if ('' === $geheim || (int)($angaben['k'] ?? 0) !== $kanal
        || !hash_equals(shopEbayOauthState($kanal, $geheim, (int)($angaben['t'] ?? 0)), $zustand)
        || (int)($angaben['t'] ?? 0) < time() - 3600) {
        throw new ApiError('EBAY_AUTH_FAILED', 'Die Antwort von eBay gehört nicht zu diesem Kanal oder ist abgelaufen — bitte erneut verbinden');
    }

    $curl = curl_init('sandbox' === ($cfg['environment'] ?? 'production') ? SHOP_EBAY_OAUTH_SANDBOX : SHOP_EBAY_OAUTH_PROD);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic '.base64_encode(trim((string)$cfg['client_id']).':'.$geheim),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => trim((string)($cfg['ru_name'] ?? '')),
        ]),
    ]);
    $antwort = curl_exec($curl);
    $fehler = curl_error($curl);
    curl_close($curl);
    $daten = json_decode((string)$antwort, true) ?: [];
    if ($fehler || empty($daten['refresh_token']) || empty($daten['access_token'])) {
        throw new ApiError('EBAY_AUTH_FAILED', 'eBay hat die Anmeldung nicht bestätigt: '
            .($fehler ?: ($daten['error_description'] ?? ($daten['error'] ?? 'unbekannter Fehler'))));
    }

    shopEbaySetConfig($db, $kanal, 'refresh_token', (string)$daten['refresh_token']);
    shopEbaySetConfig($db, $kanal, 'scopes', SHOP_EBAY_SCOPES_CONNECT);
    shopEbaySetConfig($db, $kanal, 'access_token', (string)$daten['access_token']);
    shopEbaySetConfig($db, $kanal, 'access_token_exp', (string)(time() + max(60, (int)($daten['expires_in'] ?? SHOP_EBAY_TOKEN_TTL))));
    shopEbaySetConfig($db, $kanal, 'connected_at', gmdate('Y-m-d\TH:i:s\Z'));
    // Rechnungen ab jetzt — ältere Verkäufe sind schon anders abgerechnet
    if ('' === trim((string)($cfg['import_since'] ?? ''))) {
        shopEbaySetConfig($db, $kanal, 'import_since', date('Y-m-d\TH:i'));
    }

    shopEbayRefreshTokenCheck($db, $kanal);
    return shopEbayAccountCheck($db, $kanal, (string)$daten['access_token']);
}

// ── Angebote lesen ──

/**
 * Liest alle laufenden Angebote des Kontos nach ebay_listing_shop
 *
 * GetSellerList über das Fenster der Endzeiten (heute bis 119 Tage, länger
 * läuft kein Angebot ohne Verlängerung), Seite für Seite. Varianten werden
 * eigene Zeilen. Was nicht mehr kommt, gilt als beendet — aber nur nach
 * einem vollständigen Lauf. Danach ordnet ebay_listing_autolink() zu und die
 * Einstellung „Nicht vorrätig“ des Kontos wird nachgesehen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @return array fetched, linked, suggested, inventory, ended
 * @throws ApiError EBAY_API_ERROR, EBAY_NO_CREDENTIALS
 */
function shopEbaySyncListings($db, int $kanal): array {
    set_time_limit(600);
    $start = date('Y-m-d H:i:s');
    $von = gmdate('Y-m-d\TH:i:s.000\Z');
    $bis = gmdate('Y-m-d\TH:i:s.000\Z', time() + 119 * 86400);
    $auswahl = implode('', array_map(fn($f) => '<OutputSelector>'.$f.'</OutputSelector>', SHOP_EBAY_LISTING_FIELDS));

    $gelesen = 0;
    $seite = 1;
    do {
        $xml = shopEbayTrading($db, $kanal, 'GetSellerList',
            '<DetailLevel>ReturnAll</DetailLevel>'
            .'<IncludeVariations>true</IncludeVariations>'
            .'<IncludeWatchCount>true</IncludeWatchCount>'
            .'<EndTimeFrom>'.$von.'</EndTimeFrom><EndTimeTo>'.$bis.'</EndTimeTo>'
            .'<Pagination><EntriesPerPage>'.SHOP_EBAY_LISTINGS_PER_PAGE.'</EntriesPerPage><PageNumber>'.$seite.'</PageNumber></Pagination>'
            .$auswahl);

        $zeilen = [];
        foreach ($xml->ItemArray->Item ?? [] as $item) {
            foreach (shopEbayListingRows($item) as $zeile) {
                $zeilen[] = $zeile;
            }
        }
        if ($zeilen) {
            shopEbayStoreListings($db, $kanal, $zeilen);
        }
        $gelesen += count($zeilen);
        $seiten = (int)($xml->PaginationResult->TotalNumberOfPages ?? 1);
        $seite++;
    } while ($seite <= $seiten && 'true' === strtolower((string)$xml->HasMoreItems ?: 'true') && $seite <= 500);

    // Vollständig gelesen: was nicht mehr kam, ist beendet
    $ende = $db->getOne(
        "WITH beendet AS (
             UPDATE ebay_listing_shop SET status = 'ended', mtime = now()
              WHERE channel_id = CAST(:kanal AS integer) AND status = 'active'
                AND (seen_at IS NULL OR seen_at < CAST(:start AS timestamp))
             RETURNING 1
         )
         SELECT count(*) AS n FROM beendet",
        [':kanal' => $kanal, ':start' => $start]
    );
    $zuordnung = $db->getOne("SELECT ebay_listing_autolink(CAST(:kanal AS integer)) AS z", [':kanal' => $kanal]);
    shopEbaySetConfig($db, $kanal, 'listings_last_sync', date('Y-m-d H:i:s'));

    try {
        $vorgabe = shopEbayTrading($db, $kanal, 'GetUserPreferences',
            '<ShowOutOfStockControlPreference>true</ShowOutOfStockControlPreference>');
        shopEbaySetConfig($db, $kanal, 'out_of_stock_control',
            'true' === strtolower((string)$vorgabe->OutOfStockControlPreference) ? '1' : '0');
    } catch (ApiError $e) {
        // Nur ein Hinweis im Board — der Abgleich der Angebote zählt
    }

    return ['fetched' => $gelesen, 'ended' => (int)($ende['n'] ?? 0)]
        + (json_decode((string)($zuordnung['z'] ?? '{}'), true) ?: []);
}

/**
 * Zeilen für ebay_listing_shop aus einem Item von GetSellerList — eine je Variante
 *
 * @param SimpleXMLElement $item Item
 * @return array
 */
function shopEbayListingRows(SimpleXMLElement $item): array {
    $merkmal = function (SimpleXMLElement $quelle, array $namen): string {
        foreach ($quelle->NameValueList ?? [] as $nv) {
            if (in_array(mb_strtolower(trim((string)$nv->Name)), $namen, true)) {
                return trim((string)$nv->Value);
            }
        }
        return '';
    };
    $spezifisch = $item->ItemSpecifics ?? new SimpleXMLElement('<x/>');
    $ean = trim((string)($item->ProductListingDetails->EAN ?? ''));
    $basis = [
        'item_id'        => (string)$item->ItemID,
        'sku'            => trim((string)$item->SKU),
        'title'          => (string)$item->Title,
        'price'          => (float)($item->SellingStatus->CurrentPrice ?? $item->StartPrice ?? 0),
        'currency'       => (string)($item->SellingStatus->CurrentPrice['currencyID'] ?? $item->Currency ?? 'EUR'),
        'lot_size'       => max(1, (int)($item->LotSize ?? 1)),
        'ean'            => preg_match('/^\d{8,14}$/', $ean) ? $ean : $merkmal($spezifisch, ['ean', 'gtin']),
        'mpn'            => $merkmal($spezifisch, SHOP_EBAY_MPN_NAMES) ?: trim((string)($item->ProductListingDetails->BrandMPN->MPN ?? '')),
        'brand'          => $merkmal($spezifisch, SHOP_EBAY_BRAND_NAMES) ?: trim((string)($item->ProductListingDetails->BrandMPN->Brand ?? '')),
        'image_url'      => (string)($item->PictureDetails->GalleryURL ?? $item->PictureDetails->PictureURL[0] ?? ''),
        'view_url'       => (string)($item->ListingDetails->ViewItemURL ?? ''),
        'category_id'    => (string)($item->PrimaryCategory->CategoryID ?? ''),
        'category_name'  => (string)($item->PrimaryCategory->CategoryName ?? ''),
        'condition_name' => (string)($item->ConditionDisplayName ?? ''),
        'listing_type'   => (string)$item->ListingType,
        'watch_count'    => (int)($item->WatchCount ?? 0),
        'start_time'     => (string)($item->ListingDetails->StartTime ?? '') ?: null,
        'end_time'       => (string)($item->ListingDetails->EndTime ?? '') ?: null,
    ];
    // In ebay_listing_shop.raw: was ein Mensch zur Klärung braucht, nicht die ganze Antwort
    $roh = ['specifics' => []];
    foreach ($spezifisch->NameValueList ?? [] as $nv) {
        $roh['specifics'][(string)$nv->Name] = (string)$nv->Value;
    }

    if (!isset($item->Variations->Variation)) {
        $menge = (int)$item->Quantity - (int)($item->SellingStatus->QuantitySold ?? 0);
        return [$basis + [
            'variation_sku'  => '',
            'variation_text' => null,
            'quantity'       => max(0, $menge),
            'quantity_sold'  => (int)($item->SellingStatus->QuantitySold ?? 0),
            'raw'            => $roh,
        ]];
    }

    $zeilen = [];
    foreach ($item->Variations->Variation as $variante) {
        $text = [];
        foreach ($variante->VariationSpecifics->NameValueList ?? [] as $nv) {
            $text[] = (string)$nv->Name.': '.(string)$nv->Value;
        }
        $verkauft = (int)($variante->SellingStatus->QuantitySold ?? 0);
        $sku = trim((string)$variante->SKU);
        $zeilen[] = array_merge($basis, [
            'variation_sku'  => '' !== $sku ? $sku : implode(' / ', $text),
            'variation_text' => implode(', ', $text),
            'price'          => (float)($variante->StartPrice ?? $basis['price']),
            'quantity'       => max(0, (int)$variante->Quantity - $verkauft),
            'quantity_sold'  => $verkauft,
            'raw'            => $roh,
        ]);
    }
    return $zeilen;
}

/**
 * Schreibt gelesene Angebote fest — eine Abfrage je Seite
 *
 * Zuordnung und Vorschläge bleiben, beendete kommen wieder als laufend.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param array $zeilen aus shopEbayListingRows()
 * @return void
 */
function shopEbayStoreListings($db, int $kanal, array $zeilen): void {
    $db->execute(
        "INSERT INTO ebay_listing_shop (channel_id, item_id, variation_sku, sku, title, variation_text, price, currency,
                                        quantity, quantity_sold, lot_size, ean, mpn, brand, image_url, view_url,
                                        category_id, category_name, condition_name, listing_type, watch_count,
                                        start_time, end_time, status, raw, seen_at, mtime)
         SELECT CAST(:kanal AS integer), r.item_id, COALESCE(r.variation_sku, ''), NULLIF(r.sku, ''), r.title, r.variation_text,
                r.price, r.currency, r.quantity, r.quantity_sold, r.lot_size, NULLIF(r.ean, ''), NULLIF(r.mpn, ''),
                NULLIF(r.brand, ''), NULLIF(r.image_url, ''), NULLIF(r.view_url, ''), r.category_id, r.category_name,
                r.condition_name, r.listing_type, r.watch_count,
                CAST(r.start_time AS timestamptz) AT TIME ZONE 'UTC', CAST(r.end_time AS timestamptz) AT TIME ZONE 'UTC',
                'active', r.raw, now(), now()
           FROM jsonb_to_recordset(CAST(:zeilen AS jsonb)) AS r(
                item_id text, variation_sku text, sku text, title text, variation_text text, price numeric,
                currency text, quantity integer, quantity_sold integer, lot_size integer, ean text, mpn text,
                brand text, image_url text, view_url text, category_id text, category_name text,
                condition_name text, listing_type text, watch_count integer, start_time text, end_time text, raw jsonb)
         ON CONFLICT (channel_id, item_id, variation_sku) DO UPDATE
            SET sku = EXCLUDED.sku, title = EXCLUDED.title, variation_text = EXCLUDED.variation_text,
                price = EXCLUDED.price, currency = EXCLUDED.currency, quantity = EXCLUDED.quantity,
                quantity_sold = EXCLUDED.quantity_sold, lot_size = EXCLUDED.lot_size, ean = EXCLUDED.ean,
                mpn = EXCLUDED.mpn, brand = EXCLUDED.brand, image_url = EXCLUDED.image_url,
                view_url = EXCLUDED.view_url, category_id = EXCLUDED.category_id,
                category_name = EXCLUDED.category_name, condition_name = EXCLUDED.condition_name,
                listing_type = EXCLUDED.listing_type, watch_count = EXCLUDED.watch_count,
                start_time = EXCLUDED.start_time, end_time = EXCLUDED.end_time,
                status = 'active', raw = EXCLUDED.raw, seen_at = now(), mtime = now()",
        [':kanal' => $kanal, ':zeilen' => json_encode($zeilen, JSON_UNESCAPED_UNICODE)]
    );
}

// ── Übernommene Angebote ändern ──

/**
 * Artikelmerkmale für eBay: {Name: [Wert]} aus der Liste der Produktseite
 *
 * @param array $liste [{name, value}]
 * @return array
 */
function shopEbayAspects(array $liste): array {
    $merkmale = [];
    foreach ($liste as $eintrag) {
        $name = mb_substr(trim((string)($eintrag['name'] ?? '')), 0, 65);
        $wert = mb_substr(trim((string)($eintrag['value'] ?? '')), 0, 65);
        if ('' !== $name && '' !== $wert) {
            $merkmale[$name] = [$wert];
        }
    }
    return $merkmale;
}

/**
 * Gleicht ein übernommenes Angebot mit dem Artikel ab
 *
 * Was geschrieben wird, sagt legacy_sync des Kanals:
 *   off          nichts an Menge und Preis
 *   stock        Menge = Lagerbestand (in Losen des Angebots); 0, wenn der
 *                Artikel im Kanal nicht verfügbar oder veraltet ist
 *   stock_price  dazu der Kanalpreis (brutto)
 * Titel, Beschreibung und Merkmale gehen nur hin, wenn sie in OSERP gestaltet
 * wurden (Kanalzeile mit Titel oder Produktseite) und sich seit dem letzten
 * Mal geändert haben (legacy_text_hash).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $partsId Artikel
 * @return string kurzer Stand
 * @throws ApiError EBAY_LISTING_FAILED
 */
function shopEbayReviseLegacy($db, int $kanal, int $partsId): string {
    $z = $db->getOne(
        "SELECT pc.sync_data ->> 'legacy_item_id' AS item_id,
                COALESCE(pc.sync_data ->> 'legacy_variation_sku', '') AS variante,
                COALESCE(pc.sync_data ->> 'legacy_text_hash', '') AS text_hash,
                NULLIF(btrim(pc.title), '') AS titel, NULLIF(btrim(pc.description), '') AS text,
                COALESCE(pc.settings -> 'page' -> 'aspects', '[]'::jsonb) AS merkmale,
                COALESCE(NULLIF(c.settings ->> 'legacy_sync', ''), 'off') AS modus,
                COALESCE(l.lot_size, 1) AS los, l.listing_type, l.quantity AS menge_ebay, l.price AS preis_ebay, l.id AS listing_id,
                CASE WHEN shop_part_available(p.id, c.id) AND NOT COALESCE(p.obsolete, false)
                     THEN GREATEST(FLOOR(COALESCE(p.onhand, 0) / GREATEST(COALESCE(l.lot_size, 1), 1)), 0)::int ELSE 0 END AS menge,
                ebay_gross_price(p.id, c.id) * GREATEST(COALESCE(l.lot_size, 1), 1) AS preis
           FROM parts_channel_shop pc
           JOIN sales_channel_shop c ON c.id = pc.channel_id
           JOIN parts p ON p.id = pc.parts_id
           LEFT JOIN ebay_listing_shop l ON l.channel_id = c.id AND l.item_id = pc.sync_data ->> 'legacy_item_id'
                                        AND l.variation_sku = COALESCE(pc.sync_data ->> 'legacy_variation_sku', '')
          WHERE pc.parts_id = :parts_id AND pc.channel_id = CAST(:kanal AS integer)",
        [':parts_id' => $partsId, ':kanal' => $kanal]
    );
    if (!$z) {
        throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden: '.$partsId);
    }

    $teile = [];
    $itemXml = '<ItemID>'.shopEbayXml((string)$z['item_id']).'</ItemID>'
        .('' !== $z['variante'] ? '<SKU>'.shopEbayXml((string)$z['variante']).'</SKU>' : '');

    // Menge und Preis — nur bei Festpreisangeboten
    $festpreis = in_array($z['listing_type'] ?? 'FixedPriceItem', ['FixedPriceItem', 'StoresFixedPrice', null, ''], true);
    if ('off' !== $z['modus'] && $festpreis) {
        $preis = 'stock_price' === $z['modus'] ? number_format((float)$z['preis'], 2, '.', '') : null;
        $xml = '<InventoryStatus>'.$itemXml.'<Quantity>'.(int)$z['menge'].'</Quantity>'
            .(null !== $preis ? '<StartPrice>'.$preis.'</StartPrice>' : '').'</InventoryStatus>';
        try {
            shopEbayTrading($db, $kanal, 'ReviseInventoryStatus', $xml);
        } catch (ApiError $e) {
            $hinweis = 0 === (int)$z['menge']
                ? ' — Menge 0 nimmt eBay nur mit der Option „Nicht vorrätige Artikel weiter anzeigen“ im eBay-Konto an'
                : '';
            shopEbayFail($db, $kanal, $partsId, 'Menge/Preis abgelehnt: '.$e->getMessage().$hinweis);
        }
        $db->execute(
            "UPDATE ebay_listing_shop
                SET quantity = CAST(:menge AS integer), price = COALESCE(CAST(:preis AS numeric), price), mtime = now()
              WHERE id = CAST(:id AS integer)",
            [':menge' => (int)$z['menge'], ':preis' => $preis, ':id' => (int)($z['listing_id'] ?? 0)]
        );
        $teile[] = sprintf('%d Stück', (int)$z['menge'] * (int)$z['los']).(null !== $preis ? ', '.$preis.' EUR' : '');
    }

    // Titel und Produktseite, wenn in OSERP gestaltet und geändert
    $hash = null !== $z['titel'] || null !== $z['text'] ? sha1($z['titel'].'|'.$z['text'].'|'.$z['merkmale']) : '';
    if ('' !== $hash && $hash !== $z['text_hash']) {
        $merkmale = '';
        foreach (shopEbayAspects(json_decode((string)$z['merkmale'], true) ?: []) as $name => $werte) {
            $merkmale .= '<NameValueList><Name>'.shopEbayXml($name).'</Name><Value>'.shopEbayXml($werte[0]).'</Value></NameValueList>';
        }
        $xml = '<Item><ItemID>'.shopEbayXml((string)$z['item_id']).'</ItemID>'
            .(null !== $z['titel'] ? '<Title>'.shopEbayXml(mb_substr((string)$z['titel'], 0, 80)).'</Title>' : '')
            .(null !== $z['text'] ? '<Description><![CDATA['.str_replace(']]>', ']]&gt;', (string)$z['text']).']]></Description>' : '')
            .('' !== $merkmale ? '<ItemSpecifics>'.$merkmale.'</ItemSpecifics>' : '')
            .'</Item>';
        try {
            shopEbayTrading($db, $kanal, 'ReviseFixedPriceItem', $xml);
        } catch (ApiError $e) {
            shopEbayFail($db, $kanal, $partsId, 'Titel/Beschreibung abgelehnt: '.$e->getMessage());
        }
        if (null !== $z['titel']) {
            $db->execute("UPDATE ebay_listing_shop SET title = :titel, mtime = now() WHERE id = CAST(:id AS integer)",
                         [':titel' => mb_substr((string)$z['titel'], 0, 80), ':id' => (int)($z['listing_id'] ?? 0)]);
        }
        $teile[] = 'Produktseite aktualisiert';
    }

    shopEbayRecord($db, $kanal, $partsId, 'active', '', '' !== $hash ? ['legacy_text_hash' => $hash] : []);
    return $teile ? 'Angebot '.$z['item_id'].': '.implode(', ', $teile)
                  : 'Angebot '.$z['item_id'].' zugeordnet, Abgleich von Menge und Preis ist aus';
}
