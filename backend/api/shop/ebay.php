<?php
// backend/api/shop/ebay.php
//
// Arbeitsplatz des Verkaufskanals eBay (dev/shop-ebay.md): Board mit Prüfung
// der Käufer, Verkäufen und Versand, die übernommenen Angebote mit ihrer
// Zuordnung, „Mit eBay verbinden“ und die Produktseiten mit KI. Das
// Kanalmodul (Einstellen, Bestellimport, Trading-API) steht unter
// channels/ebay*.php, der Seitenaufbau in lib/ebay_page.php.
//
// Rechte wie die übrige Shop-Verwaltung: lesen mit shop_order, shop_part_edit
// oder edit_shop_config; Verkäufe abrechnen mit shop_order oder
// edit_shop_config; Angebote und Seiten mit shop_part_edit oder
// edit_shop_config; verbinden nur mit edit_shop_config.

require_once __DIR__.'/lib/ebay_page.php';

/** Zustände, die der Benutzer einer Bestellung von Hand geben kann */
const SHOP_EBAY_ORDER_MANUAL_STATES = ['ignored', 'review'];
/** Versanddienste, die eBay als shippingCarrierCode kennt — Auswahl in der Oberfläche */
const SHOP_EBAY_CARRIERS = ['DHL', 'DEUTSCHE_POST', 'HERMES', 'DPD', 'GLS', 'UPS', 'FEDEX', 'OTHER'];

/**
 * Login des angemeldeten Benutzers, für decided_by
 *
 * @return string
 */
function shopEbayLogin(): string {
    try {
        $auth = DbhAuth::begin();
        $auth->fetchSessionData();
        return (string)$auth->getLogin();
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * eBay-Kanal einer Anfrage, null wenn der Mandant keinen hat
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $data Eingabedaten
 * @return int|null
 */
function shopEbayChannelOrNull($db, array $data): ?int {
    if ('' === (string)($data['channel_id'] ?? '') && !$db->getOne("SELECT 1 FROM sales_channel_shop WHERE type = 'ebay' LIMIT 1")) {
        return null;
    }
    return shopChannelOfRequest($db, $data, 'ebay');
}

/**
 * Board des eBay-Kanals: Kanal, Kennzahlen, Prüfung, Verkäufe, Artikel im
 * Kanal und Vorschläge zum Einstellen — eine Abfrage
 *
 * Ohne eBay-Kanal kommt board = null: die Ansicht bietet dann an, einen
 * anzulegen. accept_url ist die Adresse, die bei eBay als Rücksprung der
 * Anmeldung einzutragen ist.
 *
 * @param int $data['channel_id'] Kanal; fehlt = der erste eBay-Kanal
 * @return void board, channels, suggestions, ai_ready, accept_url, carriers
 * @testdata {"channel_id": 2}
 */
function getEbayBoard($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopEbayChannelOrNull($db, $data);

    $zeile = $db->getOne(
        "SELECT CASE WHEN CAST(:kanal AS integer) > 0 THEN ebay_board(CAST(:kanal_board AS integer)) END AS board,
                (SELECT COALESCE(json_agg(row_to_json(v)), '[]'::json)
                   FROM kleinanzeigen_suggestions(CAST(:kanal_vorschlaege AS integer), 30) v
                  WHERE CAST(:kanal_da AS integer) > 0) AS suggestions,
                (SELECT COALESCE(json_agg(json_build_object('channel_id', c.id, 'name', c.name, 'active', c.active)
                                          ORDER BY c.sortkey NULLS LAST, c.id), '[]'::json)
                   FROM sales_channel_shop c WHERE c.type = 'ebay') AS channels,
                COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'anthropic_api_key'), false) AS ai_ready,
                (SELECT btrim(value) FROM defaults_oserp WHERE key = 'ebay_public_host') AS public_host",
        [':kanal' => $kanal ?? 0, ':kanal_board' => $kanal ?? 0, ':kanal_vorschlaege' => $kanal ?? 0, ':kanal_da' => $kanal ?? 0]
    );

    $host = preg_replace('#^https?://#i', '', rtrim((string)($zeile['public_host'] ?? ''), '/'));
    resultInfo(true, '', [
        'board'       => json_decode((string)($zeile['board'] ?? 'null'), true),
        'suggestions' => json_decode((string)($zeile['suggestions'] ?? '[]'), true) ?: [],
        'channels'    => json_decode((string)($zeile['channels'] ?? '[]'), true) ?: [],
        'ai_ready'    => in_array($zeile['ai_ready'] ?? false, [true, 't', 1, '1'], true),
        'accept_url'  => '' !== $host ? 'https://'.$host.'/webhook/ebay-oauth.php' : '',
        'carriers'    => SHOP_EBAY_CARRIERS,
    ]);
}

/**
 * Übernommene Angebote des Kanals mit Zuordnung, Lager, Kanalpreis und Abweichungen
 *
 * @param int $data['channel_id'] Kanal
 * @return void listings
 * @testdata {"channel_id": 2}
 */
function getEbayListings($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $zeile = $db->getOne("SELECT ebay_listings(CAST(:kanal AS integer)) AS l", [':kanal' => $kanal]);
    resultInfo(true, '', ['listings' => json_decode((string)($zeile['l'] ?? '[]'), true) ?: []]);
}

/**
 * Jetzt mit eBay abgleichen, statt auf den Cron zu warten
 *
 * orders: geänderte Bestellungen holen, eindeutige abrechnen, Versand
 * melden. listings: alle Angebote des Kontos lesen und zuordnen.
 *
 * @param int $data['channel_id'] Kanal
 * @param string $data['what'] orders, listings oder all (Vorgabe all)
 * @return void orders, listings — Bilanz je Teil
 * @testdata {"channel_id": 2, "what": "orders"}
 */
function syncEbay($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(900);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $was = in_array($data['what'] ?? 'all', ['orders', 'listings', 'all'], true) ? ($data['what'] ?? 'all') : 'all';

    $ergebnis = [];
    if ('orders' !== $was) {
        $ergebnis['listings'] = shopEbaySyncListings($db, $kanal);
    }
    if ('listings' !== $was) {
        $ergebnis['orders'] = shopEbayImportOrders($db, $kanal);
    }
    resultInfo(true, '', $ergebnis);
}

/**
 * Adresse für „Mit eBay verbinden“ — öffnet die Ansicht im Popup
 *
 * @param int $data['channel_id'] Kanal
 * @return void url
 * @testdata {"channel_id": 2}
 */
function getEbayConnectUrl($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();
    resultInfo(true, '', ['url' => shopEbayConsentUrl($db, shopChannelOfRequest($db, $data, 'ebay'))]);
}

/**
 * Schließt „Mit eBay verbinden“ ab: Code gegen Token tauschen
 *
 * @param int $data['channel_id'] Kanal
 * @param string $data['code'] Code aus dem Rücksprung von eBay
 * @param string $data['state'] Zustand aus dem Rücksprung
 * @return void account
 * @testdata {"channel_id": 2, "code": "v^1.1#i^1#…", "state": "eyJrIjoyfQ.abc"}
 */
function finishEbayConnect($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $konto = shopEbayExchangeCode($db, $kanal, trim((string)($data['code'] ?? '')), trim((string)($data['state'] ?? '')));
    resultInfo(true, 'EBAY_CONNECTED', ['account' => $konto]);
}

/**
 * Entscheidung in der Prüfung: dieser Kunde oder ein neuer — dann sofort die Rechnung
 *
 * @param int $data['order_id'] ebay_orders.id
 * @param int $data['customer_id'] Kunde; 0 = aus den Käuferdaten neu anlegen
 * @param bool $data['update_address'] Anschrift des Kunden auf die von eBay setzen (umgezogen)
 * @return void ar_id, invnumber, customer_id, posted
 * @testdata {"order_id": 1, "customer_id": 0, "update_address": false}
 */
function decideEbayOrder($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $id = (int)($data['order_id'] ?? 0);
    $kunde = (int)($data['customer_id'] ?? 0);

    // Festhalten, wer entschieden hat — auch wenn die Rechnung gleich scheitert,
    // bleibt die Entscheidung stehen und der nächste Abruf versucht es erneut
    $zeile = $db->getOne(
        "UPDATE ebay_orders
            SET decided_by = :wer, decided_at = now(), customer_id = NULLIF(CAST(:kunde AS integer), 0), mtime = now()
          WHERE id = :id AND ar_id IS NULL AND state IN ('review', 'ignored')
         RETURNING id",
        [':wer' => shopEbayLogin() ?: 'user', ':kunde' => $kunde, ':id' => $id]
    );
    if (!$zeile) {
        resultInfo(false, 'EBAY_ORDER_NOT_OPEN', null, 'Die Bestellung wartet nicht auf eine Entscheidung');
        return;
    }
    try {
        $ergebnis = shopEbayInvoiceOrder($db, $id, $kunde > 0 ? $kunde : null, !empty($data['update_address']), shopEbayLogin() ?: 'user');
    } catch (ApiError $e) {
        $db->execute("UPDATE ebay_orders SET state = 'review', error = :fehler WHERE id = :id",
                     [':fehler' => $e->getMessage(), ':id' => $id]);
        throw $e;
    }
    resultInfo(true, 'EBAY_ORDER_INVOICED', $ergebnis);
}

/**
 * Alle Vorschläge der Prüfung übernehmen: bester Kandidat, sonst neuer Kunde
 *
 * Eine Rechnung je Bestellung. Wo der Abgleich „umgezogen“ meint, bekommt
 * der Kunde die neue Anschrift. Einzelne Fehler halten die übrigen nicht auf.
 *
 * @param int $data['channel_id'] Kanal
 * @param array $data['order_ids'] Bestellungen (ebay_orders.id); fehlt = alle in der Prüfung
 * @return void invoiced, errors
 * @testdata {"channel_id": 2, "order_ids": [1, 2]}
 */
function acceptEbayOrderSuggestions($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    set_time_limit(300);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $ids = array_values(array_filter(array_map('intval', (array)($data['order_ids'] ?? [])), fn($id) => $id > 0));
    $wer = shopEbayLogin() ?: 'user';

    $offen = $db->getAll(
        "UPDATE ebay_orders
            SET decided_by = :wer, decided_at = now(),
                customer_id = NULLIF(COALESCE(match ->> 'customer_id', match -> 'candidates' -> 0 ->> 'customer_id'), '')::integer,
                mtime = now()
          WHERE channel_id = CAST(:kanal AS integer) AND state = 'review' AND ar_id IS NULL
            AND (CAST(:alle AS integer) = 1 OR id = ANY(CAST(:ids AS integer[])))
         RETURNING id, customer_id, match ->> 'reason' = 'address_changed' AS umgezogen",
        [':wer' => $wer, ':kanal' => $kanal, ':alle' => $ids ? 0 : 1, ':ids' => '{'.implode(',', $ids).'}']
    ) ?: [];

    $gut = 0;
    $fehler = [];
    foreach ($offen as $zeile) {
        try {
            shopEbayInvoiceOrder($db, (int)$zeile['id'], $zeile['customer_id'] ? (int)$zeile['customer_id'] : null,
                                 in_array($zeile['umgezogen'], [true, 't', 1, '1'], true), $wer);
            $gut++;
        } catch (\Throwable $e) {
            $db->execute("UPDATE ebay_orders SET error = :fehler WHERE id = :id", [':fehler' => $e->getMessage(), ':id' => (int)$zeile['id']]);
            $fehler[] = $e->getMessage();
        }
    }
    resultInfo(true, '', ['invoiced' => $gut, 'errors' => $fehler]);
}

/**
 * Bestellung zurückstellen oder wieder prüfen
 *
 * ignored: kein Beleg (etwa schon von Hand abgerechnet); review: zurück in die Prüfung.
 *
 * @param int $data['order_id'] ebay_orders.id
 * @param string $data['state'] ignored oder review
 * @return void
 * @testdata {"order_id": 1, "state": "ignored"}
 */
function setEbayOrderState($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $stand = (string)($data['state'] ?? '');
    if (!in_array($stand, SHOP_EBAY_ORDER_MANUAL_STATES, true)) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Unbekannter Zustand: '.$stand);
        return;
    }
    $zeile = $db->getOne(
        "UPDATE ebay_orders
            SET state = :stand, error = NULL, decided_at = NULL, decided_by = NULL,
                match = CASE WHEN :neu_bewerten = 1 THEN ebay_customer_match(raw) ELSE match END, mtime = now()
          WHERE id = :id AND ar_id IS NULL
         RETURNING id",
        [':stand' => $stand, ':neu_bewerten' => 'review' === $stand ? 1 : 0, ':id' => (int)($data['order_id'] ?? 0)]
    );
    resultInfo((bool)$zeile, $zeile ? '' : 'EBAY_ORDER_INVOICED');
}

/**
 * Versand melden: Sendungsnummer und Versanddienst an eBay
 *
 * @param int $data['order_id'] ebay_orders.id
 * @param string $data['tracking_number'] Sendungsnummer
 * @param string $data['carrier'] Versanddienst (SHOP_EBAY_CARRIERS)
 * @return void shipped, error
 * @testdata {"order_id": 1, "tracking_number": "00340434161094042557", "carrier": "DHL"}
 */
function shipEbayOrder($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $nummer = preg_replace('/\s+/', '', (string)($data['tracking_number'] ?? ''));
    $dienst = strtoupper((string)($data['carrier'] ?? 'DHL'));
    if ('' === $nummer || !in_array($dienst, SHOP_EBAY_CARRIERS, true)) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Sendungsnummer und Versanddienst angeben');
        return;
    }
    $zeile = $db->getOne(
        "UPDATE ebay_orders SET tracking_number = :nummer, carrier = :dienst, ship_error = NULL, mtime = now()
          WHERE id = :id AND ar_id IS NOT NULL AND shipped_at IS NULL
         RETURNING id, channel_id",
        [':nummer' => $nummer, ':dienst' => $dienst, ':id' => (int)($data['order_id'] ?? 0)]
    );
    if (!$zeile) {
        resultInfo(false, 'EBAY_ORDER_NOT_OPEN', null, 'Die Bestellung ist nicht abgerechnet oder schon versendet');
        return;
    }
    $gemeldet = shopEbayPushTracking($db, (int)$zeile['channel_id'], (int)$zeile['id']);
    $stand = $db->getOne("SELECT ship_error FROM ebay_orders WHERE id = :id", [':id' => (int)$zeile['id']]);
    resultInfo($gemeldet > 0, $gemeldet > 0 ? 'EBAY_ORDER_SHIPPED' : 'EBAY_SHIP_FAILED',
               ['shipped' => $gemeldet > 0], $gemeldet > 0 ? '' : (string)($stand['ship_error'] ?? ''));
}

/**
 * Angebot einem Artikel zuordnen
 *
 * @param int $data['listing_id'] ebay_listing_shop.id
 * @param int $data['parts_id'] Artikel
 * @return void linked
 * @testdata {"listing_id": 1, "parts_id": 1}
 */
function linkEbayListing($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $zeile = $db->getOne(
        "SELECT ebay_listing_link(CAST(:listing AS integer), CAST(:parts_id AS integer), 'manual') AS r",
        [':listing' => (int)($data['listing_id'] ?? 0), ':parts_id' => (int)($data['parts_id'] ?? 0)]
    );
    $r = json_decode((string)($zeile['r'] ?? '{}'), true) ?: [];
    if (empty($r['linked'])) {
        resultInfo(false, (string)($r['reason'] ?? 'EBAY_LINK_FAILED'), $r);
        return;
    }
    resultInfo(true, 'EBAY_LISTING_LINKED', $r);
}

/**
 * Zuordnung lösen oder Vorschlag ablehnen
 *
 * @param int $data['listing_id'] ebay_listing_shop.id
 * @param bool $data['dismiss'] den Artikel für dieses Angebot nicht wieder vorschlagen
 * @return void
 * @testdata {"listing_id": 1, "dismiss": true}
 */
function unlinkEbayListing($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $db->getOne("SELECT ebay_listing_unlink(CAST(:listing AS integer), CAST(:dismiss AS boolean))",
                [':listing' => (int)($data['listing_id'] ?? 0), ':dismiss' => !empty($data['dismiss']) ? 'true' : 'false']);
    resultInfo(true, '');
}

/**
 * Vorschläge der Angebotszuordnung übernehmen
 *
 * @param int $data['channel_id'] Kanal
 * @param array $data['listing_ids'] Angebote (ebay_listing_shop.id); fehlt = alle mit Vorschlag
 * @return void linked
 * @testdata {"channel_id": 2, "listing_ids": [1, 2]}
 */
function acceptEbayListingSuggestions($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $ids = array_values(array_filter(array_map('intval', (array)($data['listing_ids'] ?? [])), fn($id) => $id > 0));
    $zeile = $db->getOne(
        "SELECT count(*) FILTER (WHERE (ebay_listing_link(l.id, l.suggestion_parts_id, 'manual') ->> 'linked')::boolean) AS n
           FROM (SELECT id, suggestion_parts_id FROM ebay_listing_shop
                  WHERE channel_id = CAST(:kanal AS integer) AND parts_id IS NULL AND suggestion_parts_id IS NOT NULL
                    AND (CAST(:alle AS integer) = 1 OR id = ANY(CAST(:ids AS integer[])))
                  ORDER BY suggestion_score DESC) l",
        [':kanal' => $kanal, ':alle' => $ids ? 0 : 1, ':ids' => '{'.implode(',', $ids).'}']
    );
    resultInfo(true, 'EBAY_LISTINGS_LINKED', ['linked' => (int)($zeile['n'] ?? 0)]);
}

/**
 * Artikel aus eBay-Angeboten anlegen — für Ware, die bisher nur bei eBay stand
 *
 * Je Angebot ein Artikel: Artikelnummer = SKU (wenn frei), sonst die nächste;
 * Bezeichnung = Titel, Preis aus dem eBay-Preis (netto, wenn die Stammdaten
 * netto rechnen), EAN, Buchungsgruppe wie die meisten Artikel; Hauptbild
 * als eBay-Bild. Danach zugeordnet. Mit take_stock wird die Menge bei eBay
 * als Bestand auf den Shop-Lagerplatz gebucht.
 *
 * @param int $data['channel_id'] Kanal
 * @param array $data['listing_ids'] Angebote (ebay_listing_shop.id)
 * @param bool $data['take_stock'] Menge bei eBay als Lagerbestand übernehmen
 * @return void created, stocked, errors
 * @testdata {"channel_id": 2, "listing_ids": [1], "take_stock": true}
 */
function createEbayListingParts($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(300);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $ids = array_values(array_filter(array_map('intval', (array)($data['listing_ids'] ?? [])), fn($id) => $id > 0));
    if (!$ids) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Keine Angebote angegeben');
        return;
    }

    $angebote = $db->getAll(
        "SELECT l.id, l.title, l.price, l.ean, l.image_url, l.quantity * GREATEST(l.lot_size, 1) AS menge,
                NULLIF(btrim(COALESCE(NULLIF(l.variation_sku, ''), l.sku)), '') AS sku,
                EXISTS (SELECT 1 FROM parts p WHERE p.partnumber = btrim(COALESCE(NULLIF(l.variation_sku, ''), l.sku))) AS sku_vergeben
           FROM ebay_listing_shop l
          WHERE l.channel_id = CAST(:kanal AS integer) AND l.parts_id IS NULL AND l.id = ANY(CAST(:ids AS integer[]))",
        [':kanal' => $kanal, ':ids' => '{'.implode(',', $ids).'}']
    ) ?: [];

    $angelegt = 0;
    $gebucht = 0;
    $fehler = [];
    foreach ($angebote as $angebot) {
        $nummer = !empty($angebot['sku']) && !in_array($angebot['sku_vergeben'], [true, 't', 1, '1'], true)
            ? (string)$angebot['sku']
            : nextFreeNumber($db, 'articlenumber', 'parts', 'partnumber');
        try {
            $db->beginTransaction();
            $zeile = $db->getOne(
                "WITH einstellung AS (
                     SELECT COALESCE((SELECT lower(btrim(value)) FROM defaults_oserp WHERE key = 'shop_tax_included'), '')
                                IN ('t', 'true', '1', 'y', 'yes') AS brutto,
                            COALESCE((SELECT value FROM defaults_oserp WHERE key = 'shop_standard_taxzone'), 'Inland') AS zone,
                            COALESCE(NULLIF(btrim((SELECT settings ->> 'default_buchungsgruppen_id' FROM sales_channel_shop
                                                     WHERE id = CAST(:kanal AS integer))), '')::integer,
                                     (SELECT buchungsgruppen_id FROM parts WHERE part_type = 'part' AND buchungsgruppen_id IS NOT NULL
                                       GROUP BY 1 ORDER BY count(*) DESC LIMIT 1)) AS gruppe
                 ), neu AS (
                     INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, notes, obsolete, ean)
                     SELECT :nummer, :titel, 'part', e.gruppe,
                            CASE WHEN e.brutto THEN CAST(:preis AS numeric)
                                 ELSE ROUND(CAST(:preis_netto AS numeric) / (1 + shop_tax_rate(e.gruppe, e.zone)), 5) END,
                            'Stck', '', false, NULLIF(:ean, '')
                       FROM einstellung e
                      WHERE NOT EXISTS (SELECT 1 FROM parts WHERE partnumber = :nummer_frei)
                     RETURNING id
                 )
                 SELECT id FROM neu",
                [':kanal' => $kanal, ':nummer' => $nummer, ':titel' => mb_substr((string)$angebot['title'], 0, 250),
                 ':preis' => (float)$angebot['price'], ':preis_netto' => (float)$angebot['price'],
                 ':ean' => (string)($angebot['ean'] ?? ''), ':nummer_frei' => $nummer]
            );
            if (!$zeile) {
                throw new ApiError('PARTNUMBER_EXISTS', 'Artikelnummer '.$nummer.' ist vergeben');
            }
            $partsId = (int)$zeile['id'];
            $db->getOne("SELECT ebay_listing_link(CAST(:listing AS integer), CAST(:parts_id AS integer), 'created')",
                        [':listing' => (int)$angebot['id'], ':parts_id' => $partsId]);

            if (!empty($data['take_stock']) && (int)$angebot['menge'] > 0) {
                $buchung = $db->getOne(
                    "WITH platz AS (
                         SELECT b.id AS bin_id, b.warehouse_id FROM bin b
                          WHERE b.id = NULLIF(btrim((SELECT value FROM defaults_oserp WHERE key = 'shop_stock_bin_id')), '')::integer
                     ), zugang AS (
                         INSERT INTO inventory (parts_id, warehouse_id, bin_id, qty, chargenumber, comment, shippingdate,
                                                employee_id, trans_id, trans_type_id)
                         SELECT CAST(:parts_id AS integer), p.warehouse_id, p.bin_id, CAST(:menge AS numeric), '',
                                'Bestand aus eBay-Angebot übernommen', current_date,
                                (SELECT id FROM employee WHERE login = :login AND NOT COALESCE(deleted, false)
                                 UNION ALL SELECT id FROM employee WHERE NOT COALESCE(deleted, false) LIMIT 1),
                                nextval('id'),
                                (SELECT id FROM transfer_type WHERE direction = 'in' ORDER BY (description = 'stock') DESC, sortkey LIMIT 1)
                           FROM platz p
                         RETURNING parts_id
                     )
                     UPDATE parts SET stockable = true WHERE id IN (SELECT parts_id FROM zugang) RETURNING id",
                    [':parts_id' => $partsId, ':menge' => (int)$angebot['menge'], ':login' => shopEbayLogin()]
                );
                $gebucht += $buchung ? 1 : 0;
            }
            $db->commit();
            $angelegt++;
        } catch (\Throwable $e) {
            $db->rollBack();
            $fehler[] = '„'.$angebot['title'].'“: '.$e->getMessage();
            continue;
        }

        // Hauptbild von eBay als eBay-Bild des Artikels — scheitert es, bleibt der Artikel
        if (!empty($angebot['image_url'])) {
            try {
                $inhalt = shopEbayFetchImage((string)$angebot['image_url']);
                if ('' !== $inhalt) {
                    shopChannelImageStore($db, $partsId, $kanal, $inhalt, basename(parse_url((string)$angebot['image_url'], PHP_URL_PATH) ?: 'ebay.jpg'));
                }
            } catch (\Throwable $e) {
                writeLog('createEbayListingParts: Bild für Artikel '.$partsId.' nicht übernommen: '.$e->getMessage(), true, DLOG_WRN);
            }
        }
    }
    // Ohne Shop-Lagerplatz kein Bestand — die Oberfläche sagt, wo er einzurichten ist
    $platz = $db->getOne("SELECT EXISTS (SELECT 1 FROM bin WHERE id = NULLIF(btrim((SELECT value FROM defaults_oserp WHERE key = 'shop_stock_bin_id')), '')::integer) AS da");
    resultInfo(true, 'EBAY_PARTS_CREATED', ['created' => $angelegt, 'stocked' => $gebucht, 'errors' => $fehler,
                                            'stock_bin' => in_array($platz['da'] ?? false, [true, 't', 1, '1'], true)]);
}

/**
 * Bild von eBay laden — nur von eBays Bildservern, in voller Größe
 *
 * @param string $adresse Bildadresse aus dem Angebot
 * @return string Bilddaten, leer wenn nicht ladbar
 */
function shopEbayFetchImage(string $adresse): string {
    if (!preg_match('#^https://i\.ebayimg\.(com|sandbox\.ebay\.com)/#i', $adresse)) {
        return '';
    }
    // Vorschaubild (s-l140, s-l225 …) gegen die große Fassung tauschen
    $adresse = preg_replace('#/s-l\d+\.(jpg|jpeg|png|webp)#i', '/s-l1600.$1', $adresse);
    $curl = curl_init($adresse);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                              CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30]);
    $inhalt = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return 200 === $status && is_string($inhalt) ? $inhalt : '';
}

/**
 * Artikel in den eBay-Kanal aufnehmen — der Läufer stellt sie über die Inventory-API ein
 *
 * Fehlen dem Artikel eBay-Bilder, kommen die eines anderen Kanals dazu.
 *
 * @param int $data['channel_id'] Kanal
 * @param array $data['parts_ids'] Artikel
 * @return void added, images_copied
 * @testdata {"channel_id": 2, "parts_ids": [1, 2]}
 */
function addEbayParts($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['parts_ids'] ?? [])), fn($id) => $id > 0)));
    if (!$ids) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Keine Artikel angegeben');
        return;
    }
    $zeilen = $db->getAll(
        "WITH aufgenommen AS (
             INSERT INTO parts_channel_shop (parts_id, channel_id, active)
             SELECT p.id, CAST(:kanal AS integer), true
               FROM parts p
              WHERE p.id = ANY(CAST(:ids AS integer[])) AND p.part_type <> 'service'
             ON CONFLICT (parts_id, channel_id) DO UPDATE SET active = true, mtime = now()
             RETURNING parts_id
         )
         SELECT a.parts_id,
                CASE WHEN NOT EXISTS (SELECT 1 FROM parts_channel_image_shop i
                                       WHERE i.parts_id = a.parts_id AND i.channel_id = CAST(:kanal_bilder AS integer))
                     THEN COALESCE(
                         (SELECT i.channel_id FROM parts_channel_image_shop i
                           WHERE i.parts_id = a.parts_id AND i.channel_id <> CAST(:kanal_fremd AS integer)
                           ORDER BY i.channel_id LIMIT 1),
                         (SELECT c.id FROM sales_channel_shop c
                            JOIN parts_ext pe ON pe.parts_id = a.parts_id
                           WHERE c.type = 'hugoshop' AND c.active
                             AND jsonb_typeof(pe.hugoshop_images) = 'array' AND jsonb_array_length(pe.hugoshop_images) > 0
                           ORDER BY c.sortkey NULLS LAST, c.id LIMIT 1))
                END AS bildquelle
           FROM aufgenommen a",
        [':kanal' => $kanal, ':ids' => '{'.implode(',', $ids).'}', ':kanal_bilder' => $kanal, ':kanal_fremd' => $kanal]
    ) ?: [];

    $kopiert = 0;
    foreach ($zeilen as $zeile) {
        if (empty($zeile['bildquelle'])) {
            continue;
        }
        try {
            $kopiert += shopChannelImageCopy($db, (int)$zeile['parts_id'], (int)$zeile['bildquelle'], $kanal);
        } catch (\Throwable $e) {
            writeLog('addEbayParts: Bilder für Artikel '.$zeile['parts_id'].' nicht übernommen: '.$e->getMessage(), true, DLOG_WRN);
        }
    }
    resultInfo(true, 'EBAY_PARTS_ADDED', ['added' => count($zeilen), 'images_copied' => $kopiert]);
}

// ── Produktseiten ──

/**
 * Alles für Seite und Vorschau eines Artikels, eine Abfrage: gespeicherter
 * Aufbau oder ein Entwurf aus den Stammdaten, Artikel, Angebot bei eBay,
 * Texte des Kanals für den Fuß
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $partsId Artikel
 * @return array|null page, part, listing, context
 */
function shopEbayPageData($db, int $kanal, int $partsId): ?array {
    $zeile = $db->getOne(
        "WITH kanal AS (
             SELECT c.id, COALESCE(c.settings, '{}'::jsonb) AS s FROM sales_channel_shop c WHERE c.id = CAST(:kanal AS integer)
         ), artikel AS (
             SELECT p.id, p.partnumber, p.description, p.notes, p.ean, p.unit, p.onhand, p.weight,
                    (SELECT pg.partsgroup FROM partsgroup pg WHERE pg.id = p.partsgroup_id) AS partsgroup,
                    pc.title, pc.description AS kanal_text, pc.settings -> 'page' AS seite, COALESCE(pc.active, false) AS im_kanal,
                    pe.hugoshop_technical_data AS technik, pe.hugoshop_properties AS eigenschaften
               FROM parts p
               LEFT JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.channel_id = CAST(:kanal_zeile AS integer)
               LEFT JOIN parts_ext pe ON pe.parts_id = p.id
              WHERE p.id = CAST(:parts_id AS integer)
         ), angebot AS (
             SELECT l.* FROM ebay_listing_shop l, kanal k
              WHERE l.channel_id = k.id AND l.parts_id = CAST(:parts_id_angebot AS integer)
              ORDER BY l.status, l.id LIMIT 1
         ), paare AS (
             -- technische Daten und Eigenschaften: Objekt {Name: Wert} oder Liste [{name|label, value}]
             SELECT COALESCE(jsonb_agg(jsonb_build_object('name', n, 'value', w)) FILTER (WHERE btrim(n) <> '' AND btrim(w) <> ''), '[]'::jsonb) AS specs
               FROM artikel a,
                    LATERAL (SELECT e.key AS n, e.value AS w FROM jsonb_each_text(CASE WHEN jsonb_typeof(a.technik) = 'object' THEN a.technik ELSE '{}' END) e
                             UNION ALL
                             SELECT e.key, e.value FROM jsonb_each_text(CASE WHEN jsonb_typeof(a.eigenschaften) = 'object' THEN a.eigenschaften ELSE '{}' END) e
                             UNION ALL
                             SELECT COALESCE(x ->> 'name', x ->> 'label', x ->> 'key'), x ->> 'value'
                               FROM jsonb_array_elements(CASE WHEN jsonb_typeof(a.technik) = 'array' THEN a.technik ELSE '[]' END) x
                             UNION ALL
                             SELECT COALESCE(x ->> 'name', x ->> 'label', x ->> 'key'), x ->> 'value'
                               FROM jsonb_array_elements(CASE WHEN jsonb_typeof(a.eigenschaften) = 'array' THEN a.eigenschaften ELSE '[]' END) x) t
         ), merkmale AS (
             SELECT COALESCE(jsonb_agg(jsonb_build_object('name', e.key, 'value', e.value)), '[]'::jsonb) AS aspects
               FROM angebot g, jsonb_each_text(COALESCE(g.raw -> 'specifics', '{}'::jsonb)) e
         )
         SELECT jsonb_build_object(
             'page', COALESCE(a.seite, jsonb_build_object(
                 'template', COALESCE(NULLIF(k.s ->> 'page_template', ''), 'klar'),
                 'color', COALESCE(NULLIF(k.s ->> 'page_color', ''), '#1f6feb'),
                 'title', left(COALESCE(NULLIF(a.title, ''), (SELECT title FROM angebot), a.description), 80),
                 'headline', '', 'subtitle', '', 'highlights', '[]'::jsonb,
                 'description', kleinanzeigen_plain_text(COALESCE(NULLIF(a.notes, ''), a.kanal_text, '')),
                 'specs', (SELECT specs FROM paare),
                 'aspects', (SELECT aspects FROM merkmale)
                            || CASE WHEN COALESCE(a.ean, '') <> '' AND NOT EXISTS (SELECT 1 FROM angebot g WHERE jsonb_exists(g.raw -> 'specifics', 'EAN'))
                                    THEN jsonb_build_array(jsonb_build_object('name', 'EAN', 'value', a.ean)) ELSE '[]'::jsonb END,
                 'compatibility', '', 'scope', '', 'notes', '', 'show_images', false)),
             'saved', a.seite IS NOT NULL,
             'in_channel', a.im_kanal,
             'part', jsonb_build_object('parts_id', a.id, 'partnumber', a.partnumber, 'description', a.description,
                                        'notes', kleinanzeigen_plain_text(a.notes), 'ean', a.ean, 'unit', a.unit,
                                        'onhand', a.onhand, 'weight', a.weight, 'partsgroup', a.partsgroup,
                                        'price', ebay_gross_price(a.id, k.id)),
             'listing', (SELECT jsonb_build_object('item_id', g.item_id, 'title', g.title, 'view_url', g.view_url,
                                                   'image_url', g.image_url, 'price', g.price, 'quantity', g.quantity,
                                                   'category_name', g.category_name, 'condition_name', g.condition_name,
                                                   'specifics', g.raw -> 'specifics')
                           FROM angebot g),
             'context', jsonb_build_object(
                 'color', COALESCE(NULLIF(k.s ->> 'page_color', ''), '#1f6feb'),
                 'language', COALESCE(NULLIF(k.s ->> 'content_language', ''), 'de-DE'),
                 'shipping', COALESCE(k.s ->> 'page_shipping', ''),
                 'returns', COALESCE(k.s ->> 'page_returns', ''),
                 'about', COALESCE(k.s ->> 'page_about', ''),
                 'company', COALESCE((SELECT company FROM defaults LIMIT 1), ''))) AS d
           FROM artikel a CROSS JOIN kanal k",
        [':kanal' => $kanal, ':kanal_zeile' => $kanal, ':parts_id' => $partsId, ':parts_id_angebot' => $partsId]
    );
    $daten = json_decode((string)($zeile['d'] ?? 'null'), true);
    if (!$daten) {
        return null;
    }
    try {
        $daten['context']['images'] = shopEbayImageUrls($db, $kanal, $partsId, shopEbayConfig($db, $kanal));
    } catch (ApiError $e) {
        $daten['context']['images'] = [];
    }
    return $daten;
}

/**
 * Produktseite eines Artikels für den Editor: Aufbau, Artikel, Angebot, Vorschau
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @return void page, saved, part, listing, context, html
 * @testdata {"parts_id": 1, "channel_id": 2}
 */
function getEbayPage($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $daten = shopEbayPageData($db, $kanal, (int)($data['parts_id'] ?? 0));
    if (!$daten) {
        resultInfo(false, 'PART_NOT_FOUND', null, 'Artikel nicht gefunden');
        return;
    }
    $seite = shopEbayPageClean($daten['page']);
    $daten['page'] = $seite;
    $daten['html'] = shopEbayRenderPage($seite, $daten['context'] + ['title' => $seite['title']]);
    $daten['templates'] = SHOP_EBAY_PAGE_TEMPLATES;
    resultInfo(true, '', $daten);
}

/**
 * Vorschau einer Seite, ohne zu speichern
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @param array $data['page'] Aufbau
 * @return void html
 * @testdata {"parts_id": 1, "channel_id": 2, "page": {"template": "modern", "title": "Bremsscheiben VW Golf 7 vorne", "highlights": ["Neu", "312 mm"], "description": "Original verpackt."}}
 */
function previewEbayPage($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $daten = shopEbayPageData($db, $kanal, (int)($data['parts_id'] ?? 0));
    if (!$daten) {
        resultInfo(false, 'PART_NOT_FOUND', null, 'Artikel nicht gefunden');
        return;
    }
    $seite = shopEbayPageClean(is_array($data['page'] ?? null) ? $data['page'] : []);
    resultInfo(true, '', ['html' => shopEbayRenderPage($seite, $daten['context'] + ['title' => $seite['title']])]);
}

/**
 * Seite speichern: Aufbau, eBay-Titel und gerenderte Beschreibung an die Kanalzeile
 *
 * Ist der Artikel im Kanal angeboten, stellt der Trigger einen Auftrag ein,
 * der das Angebot bei eBay ändert (eingestellt: Inventory-API, übernommen:
 * ReviseFixedPriceItem). Ist er es noch nicht, legt publish = true die
 * Kanalzeile an — der Artikel wird eingestellt.
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @param array $data['page'] Aufbau
 * @param bool $data['publish'] in den Kanal aufnehmen, wenn er es noch nicht ist
 * @return void saved, queued
 * @testdata {"parts_id": 1, "channel_id": 2, "page": {"template": "klar", "title": "Bremsscheiben VW Golf 7 vorne", "description": "Original verpackt."}, "publish": false}
 */
function saveEbayPage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $partsId = (int)($data['parts_id'] ?? 0);
    $daten = shopEbayPageData($db, $kanal, $partsId);
    if (!$daten) {
        resultInfo(false, 'PART_NOT_FOUND', null, 'Artikel nicht gefunden');
        return;
    }
    $seite = shopEbayPageClean(is_array($data['page'] ?? null) ? $data['page'] : []);
    if ('' === $seite['title']) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Der eBay-Titel fehlt');
        return;
    }
    $html = shopEbayRenderPage($seite, $daten['context'] + ['title' => $seite['title']]);

    $zeile = $db->getOne(
        "WITH gespeichert AS (
             INSERT INTO parts_channel_shop (parts_id, channel_id, active, title, description, settings)
             SELECT CAST(:parts_id AS integer), CAST(:kanal AS integer), true, :titel, :html,
                    jsonb_build_object('page', CAST(:seite AS jsonb))
              WHERE CAST(:aufnehmen AS integer) = 1
                 OR EXISTS (SELECT 1 FROM parts_channel_shop WHERE parts_id = CAST(:parts_id_da AS integer) AND channel_id = CAST(:kanal_da AS integer))
             ON CONFLICT (parts_id, channel_id) DO UPDATE
                SET title = EXCLUDED.title, description = EXCLUDED.description,
                    settings = COALESCE(parts_channel_shop.settings, '{}'::jsonb) || EXCLUDED.settings,
                    active = parts_channel_shop.active OR CAST(:aufnehmen_neu AS integer) = 1,
                    mtime = now()
             RETURNING active
         )
         SELECT (SELECT count(*) FROM gespeichert) AS gespeichert, (SELECT bool_or(active) FROM gespeichert) AS angeboten",
        [':parts_id' => $partsId, ':kanal' => $kanal, ':titel' => $seite['title'], ':html' => $html,
         ':seite' => json_encode($seite, JSON_UNESCAPED_UNICODE), ':aufnehmen' => !empty($data['publish']) ? 1 : 0,
         ':parts_id_da' => $partsId, ':kanal_da' => $kanal, ':aufnehmen_neu' => !empty($data['publish']) ? 1 : 0]
    );
    if (!(int)($zeile['gespeichert'] ?? 0)) {
        resultInfo(false, 'EBAY_PART_NOT_IN_CHANNEL', null, 'Der Artikel ist nicht im eBay-Kanal — mit „Speichern und einstellen“ aufnehmen');
        return;
    }
    resultInfo(true, 'EBAY_PAGE_SAVED', ['saved' => true, 'offered' => in_array($zeile['angeboten'] ?? false, [true, 't', 1, '1'], true)]);
}

/**
 * Lässt Claude die Produktseite schreiben: Titel mit Suchbegriffen, Kopf,
 * Stichpunkte, Text, technische Daten, Artikelmerkmale, Lieferumfang
 *
 * Aus Artikelstamm, Shop-Angaben, dem bestehenden Angebot bei eBay (Titel,
 * Merkmale) und dem bisherigen Entwurf. Nichts wird gespeichert — der
 * Benutzer prüft im Editor. Erfundenes ist verboten: was nicht in den Daten
 * steht, bleibt weg.
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @param array $data['page'] bisheriger Entwurf (Gestaltung und Angaben bleiben die Grundlage)
 * @param string $data['hint'] Hinweis an die KI (Zustand, Zielgruppe, Besonderheiten)
 * @param string $data['tone'] sachlich, werblich oder technisch
 * @param string $data['ai_model'] Modellwahl für diese Sitzung
 * @return void page, titles (Alternativen)
 * @testdata {"parts_id": 1, "channel_id": 2, "hint": "Neuteil, Originalverpackung", "tone": "sachlich"}
 */
function generateEbayPage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(150);
    $db = DbhCompany::begin();
    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    $daten = shopEbayPageData($db, $kanal, (int)($data['parts_id'] ?? 0));
    if (!$daten) {
        resultInfo(false, 'PART_NOT_FOUND', null, 'Artikel nicht gefunden');
        return;
    }
    $entwurf = shopEbayPageClean(is_array($data['page'] ?? null) ? $data['page'] : $daten['page']);
    $ton = [
        'werblich'  => 'werblich und lebendig, aber ehrlich — Nutzen vor Merkmalen',
        'technisch' => 'technisch präzise für Fachleute und Werkstätten — Daten, Nummern, Passform zuerst',
    ][$data['tone'] ?? ''] ?? 'sachlich und vertrauenswürdig';
    $sprache = (string)($daten['context']['language'] ?? 'de-DE');

    $eingabe = json_encode([
        'artikel'          => $daten['part'],
        'bestehendes_ebay_angebot' => $daten['listing'],
        'bisheriger_entwurf' => array_intersect_key($entwurf, array_flip(['title', 'headline', 'subtitle', 'highlights', 'description',
                                                                          'specs', 'aspects', 'compatibility', 'scope', 'notes'])),
        'verkaeufer'       => $daten['context']['company'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $hinweis = trim((string)($data['hint'] ?? ''));

    $prompt = <<<PROMPT
Du gestaltest eine eBay-Produktseite für einen gewerblichen Verkäufer. Sprache der Seite: {$sprache}. Tonfall: {$ton}.

Ziel: Käufer finden das Angebot über die eBay-Suche und kaufen, weil die Seite alle Fragen beantwortet.

Regeln:
- "title": eBay-Titel, höchstens 80 Zeichen. Suchbegriffe zuerst: Marke, Produktart, Modell/Typ, wichtigste Nummer (Herstellernummer/OE-Nummer), Passform, Zustand nur wenn nicht neu. Keine Füllwörter („TOP“, „!!!“, „L@@K“), keine Großschreibung ganzer Wörter außer Abkürzungen.
- "title_alternatives": zwei weitere Titelvarianten mit anderem Schwerpunkt, je höchstens 80 Zeichen.
- "headline": Überschrift der Seite, gern etwas länger und lesbarer als der Titel.
- "subtitle": ein Satz, der den Nutzen sagt.
- "highlights": 3 bis 6 kurze Stichpunkte, das Wichtigste auf einen Blick.
- "description": 2 bis 4 kurze Absätze reiner Text (Leerzeile zwischen Absätzen), Aufzählungen mit "- " am Zeilenanfang. Kein HTML, kein Markdown.
- "specs": technische Daten als Liste von {"name","value"} — nur aus den Daten.
- "aspects": eBay-Artikelmerkmale als Liste von {"name","value"} mit den bei eBay üblichen Namen (etwa Marke, Herstellernummer, Produktart, Einbauposition, OE/OEM Referenznummer(n), EAN). Übernimm vorhandene Merkmale des bestehenden Angebots, ergänze nur, was die Daten belegen.
- "compatibility": passend für (Fahrzeuge, Geräte) — nur wenn die Daten es hergeben, sonst leer.
- "scope": Lieferumfang, sonst leer. "notes": wichtige Hinweise (Einbau, Pfand, Hinweis auf Prüfung der Passform), sonst leer.
- Erfinde nichts: keine Maße, Nummern, Fahrzeuge oder Eigenschaften, die nicht in den Daten stehen. Lieber ein Feld leer lassen.
- "partnumber" ist die interne Artikelnummer des Verkäufers, keine Herstellernummer — nenne sie nur als Herstellernummer/MPN, wenn die Daten (Merkmale des Angebots, Hinweis) es so sagen.
- Jede Angabe nur einmal: was in "highlights" oder "specs" steht, nicht als Liste in "description" wiederholen. Keine Banalitäten wie Einheit oder Stückzahl 1.
- Keine Preise, keine Kontaktdaten, keine Links, keine Hinweise auf andere Plattformen.

Antworte ausschließlich mit einem JSON-Objekt mit den Schlüsseln title, title_alternatives, headline, subtitle, highlights, description, specs, aspects, compatibility, scope, notes.

DATEN:
{$eingabe}
PROMPT;
    if ('' !== $hinweis) {
        $prompt .= "\n\nHINWEIS DES VERKÄUFERS:\n".$hinweis."\n";
    }

    $antwort = shopAiAsk($db, 'ebay', $prompt, $data['ai_model'] ?? null, 3000);
    $antwort = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($antwort));
    $start = strpos($antwort, '{');
    $ende = strrpos($antwort, '}');
    $ergebnis = false !== $start && false !== $ende ? json_decode(substr($antwort, $start, $ende - $start + 1), true) : null;
    if (!is_array($ergebnis) || empty($ergebnis['title'])) {
        resultInfo(false, 'CLAUDE_API_ERROR', null, 'Die KI hat keine verwertbare Seite geliefert — bitte erneut versuchen');
        return;
    }

    // Gestaltung des Entwurfs bleibt, die Inhalte kommen von der KI
    $seite = shopEbayPageClean(array_merge($entwurf, array_intersect_key($ergebnis, array_flip([
        'title', 'headline', 'subtitle', 'highlights', 'description', 'specs', 'aspects', 'compatibility', 'scope', 'notes',
    ]))));
    $titel = array_values(array_unique(array_filter(array_map(fn($t) => mb_substr(trim((string)$t), 0, 80),
        array_merge([$seite['title']], (array)($ergebnis['title_alternatives'] ?? []))))));

    resultInfo(true, '', [
        'page'   => $seite,
        'titles' => $titel,
        'html'   => shopEbayRenderPage($seite, $daten['context'] + ['title' => $seite['title']]),
    ]);
}

// ── Auswahlfelder ──

/**
 * Artikel für die Zuordnung eines Angebots: Nummer, EAN oder Bezeichnung
 *
 * Gleiche Nummer zuerst, dann Nummer beginnt mit, dann ähnliche Bezeichnung.
 *
 * @param string $data['q'] Suchbegriff
 * @return void results
 * @testdata {"q": "Xenon"}
 */
function searchEbayParts($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $q = trim((string)($data['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        resultInfo(true, '', ['results' => []]);
        return;
    }
    resultInfo(true, '', ['results' => $db->getAll(
        "SELECT p.id AS parts_id, p.partnumber, p.description, p.onhand, p.unit, p.ean
           FROM parts p
          WHERE p.part_type = 'part' AND NOT COALESCE(p.obsolete, false)
            AND (p.partnumber ILIKE :anfang OR p.ean = :ean OR p.description ILIKE :teil OR p.description % :aehnlich)
          ORDER BY (p.partnumber = :gleich) DESC, (p.partnumber ILIKE :anfang_sort) DESC,
                   similarity(p.description, :sort) DESC, p.description
          LIMIT 20",
        [':anfang' => $q.'%', ':ean' => $q, ':teil' => '%'.$q.'%', ':aehnlich' => $q,
         ':gleich' => $q, ':anfang_sort' => $q.'%', ':sort' => $q]
    ) ?: []]);
}

/**
 * Kunden für „anderer Kunde“ in der Prüfung: Name, Nummer, Ort, E-Mail
 *
 * @param string $data['q'] Suchbegriff
 * @return void results
 * @testdata {"q": "Speichert"}
 */
function searchEbayCustomers($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $q = trim((string)($data['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        resultInfo(true, '', ['results' => []]);
        return;
    }
    resultInfo(true, '', ['results' => $db->getAll(
        "SELECT c.id AS customer_id, c.customernumber, c.name, c.street, c.zipcode, c.city, c.email
           FROM customer c
          WHERE NOT COALESCE(c.obsolete, false)
            AND (c.customernumber = :nummer OR c.name ILIKE :teil OR c.city ILIKE :ort OR c.email ILIKE :mail
                 OR c.zipcode = :plz OR similarity(ebay_norm_name(c.name), ebay_norm_name(:name)) >= 0.4)
          ORDER BY (c.customernumber = :nummer_sort) DESC,
                   similarity(ebay_norm_name(c.name), ebay_norm_name(:name_sort)) DESC, c.name
          LIMIT 20",
        [':nummer' => $q, ':teil' => '%'.$q.'%', ':ort' => $q.'%', ':mail' => '%'.$q.'%', ':plz' => $q,
         ':name' => $q, ':nummer_sort' => $q, ':name_sort' => $q]
    ) ?: []]);
}
