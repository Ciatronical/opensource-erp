<?php
// backend/api/shop/admin.php
//
// Aktionen des Admin-Panels der Shop-Erweiterung — fuer die Mitarbeiter des
// Shop-Betreibers, mit normaler OpensourceERP-Sitzung und Rechtepruefung.
//
// Bestelluebersicht, Zahlungsstaende, Artikel-Shopdaten, Weiterleitungen und
// Widerrufe kommen mit den Stufen 5 und 6 dazu.

/**
 * Prüft, ob die Shop-Erweiterung einsatzbereit eingerichtet ist
 *
 * Die Einstellungen lassen sich einzeln speichern, ohne dass jemand merkt,
 * dass eine fehlt — der Shop meldet sich dann erst im Betrieb. Diese Auskunft
 * sammelt die Punkte, die den Betrieb verhindern oder einschraenken, damit das
 * Admin-Panel sie an einer Stelle zeigen kann.
 *
 * Zugangsdaten werden nur auf Vorhandensein geprüft und nie zurückgegeben.
 *
 * @param array $data Eingabedaten (wird nicht verwendet)
 * @return void Gibt JSON mit der Einrichtungsprüfung aus
 * @testdata {}
 */
function getShopStatus($data) {
    // kivitendo bringt die passenden Rechte bereits mit: shop_order (Bestellungen),
    // shop_part_edit (Artikel-Shopdaten) und edit_shop_config (Einstellungen).
    // Die Erweiterung braucht deshalb keine eigenen. Fuer die Auskunft genuegt
    // eines von beiden — sie sagt nur, was noch fehlt.
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    // Eine Abfrage für alles, was in der Datenbank nachzuschlagen ist
    $stand = $db->getOne(
        "SELECT
            -- Versand (dev/shop-versand.md): ohne aktive Versandart mit
            -- Preisstufe läuft jede Bestellung als „Standard“ ohne Kosten
            shop_shipping_configured() AS versandart,
            (SELECT COUNT(*) FROM employee WHERE login = :kontakt AND NOT deleted) > 0 AS kontakt,
            (SELECT COUNT(*) FROM chart WHERE description ILIKE :forderungskonto) > 0 AS forderungskonto,
            (SELECT COUNT(*) FROM tax_zones WHERE description ILIKE :taxzone) > 0 AS taxzone,
            (SELECT COUNT(*) FROM currencies WHERE name ILIKE :currency) > 0 AS currency,
            (SELECT COUNT(DISTINCT pc.parts_id) FROM parts_channel_shop pc
               JOIN sales_channel_shop hc ON hc.id = pc.channel_id AND hc.active AND hc.type = 'hugoshop'
              WHERE pc.active) AS artikel_mit_shopdaten,
            EXISTS (SELECT 1 FROM sales_channel_shop WHERE active AND round_99) AS rundung_99,
            EXISTS (SELECT 1 FROM sales_channel_shop WHERE type = 'hugoshop' AND active) AS hugoshop_an,
            EXISTS (SELECT 1 FROM sales_channel_shop WHERE type = 'ebay' AND active) AS ebay_an,
            EXISTS (SELECT 1 FROM bin
                     WHERE id::text = btrim((SELECT value FROM defaults_oserp
                                              WHERE key = 'shop_stock_bin_id'))) AS lagerplatz,
            (SELECT COUNT(*) FROM context_hugoshop) AS sitzungen,
            (SELECT COUNT(*) FROM carts_hugoshop) AS warenkoerbe",
        [
            ':kontakt'         => shopConfigValue($db, 'shop_contact_login'),
            ':forderungskonto' => '%'.shopConfigValue($db, 'shop_target_account').'%',
            ':taxzone'         => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
            ':currency'        => shopConfigValue($db, 'shop_standard_currency', 'EUR'),
        ]
    );

    $wahr = fn($wert) => in_array($wert, ['t', true, 1, '1'], true);

    // Ohne diese Punkte nimmt der oeffentliche Zugang keine Bestellung an
    $blockierend = [];
    if (!$wahr($stand['forderungskonto'])) {
        $blockierend[] = 'shop_target_account';
    }
    if (!$wahr($stand['taxzone'])) {
        $blockierend[] = 'shop_standard_taxzone';
    }
    if (!$wahr($stand['currency'])) {
        $blockierend[] = 'shop_standard_currency';
    }

    // Ohne diese laeuft der Shop, aber eingeschraenkt
    $hinweise = [];
    if ('' === shopConfigValue($db, 'shop_contact_login') || !$wahr($stand['kontakt'])) {
        $hinweise[] = 'shop_contact_login';
    }
    if ('' === shopConfigValue($db, 'shop_payment_iban')) {
        $hinweise[] = 'shop_payment_iban';
    }
    if (0 == (int)$stand['artikel_mit_shopdaten']) {
        $hinweise[] = 'parts_ext';
    }

    // Je eingeschaltetem Kanal seine Einstellungen (dev/shop-mehrere-kanaele.md).
    // Die Schlüssel bleiben die bisherigen Feldnamen — die Übersicht übersetzt
    // sie; welcher Kanal betroffen ist, steht bei mehreren in den Details.
    $kanaele = $db->getAll(
        "SELECT id, type, name FROM sales_channel_shop WHERE active ORDER BY sortkey NULLS LAST, id"
    ) ?: [];
    $hugoshops = array_values(array_filter($kanaele, fn($k) => 'hugoshop' === $k['type']));
    $mehrere = count($hugoshops) > 1;
    $veroeffentlichung = [];
    $programm = null;

    foreach ($hugoshops as $kanal) {
        $id = (int)$kanal['id'];
        $vorsilbe = $mehrere ? $kanal['name'].': ' : '';
        $leer = fn(string $key) => '' === shopChannelValue($db, $id, $key);

        if ($leer('public_key')) {
            $blockierend[] = 'shop_public_key';
            if ($mehrere) {
                $veroeffentlichung[] = $vorsilbe.shopConfigLabel('shop_public_key');
            }
        }
        // Geprueft wird das Paar, das gerade gilt — im Testbetrieb nuetzen die
        // Echtbetrieb-Zugangsdaten nichts und umgekehrt.
        $paypal = shopChannelBool($db, $id, 'paypal_sandbox', true) ? 'paypal_sandbox_' : 'paypal_live_';
        if ($leer($paypal.'client_id') || $leer($paypal.'secret')) {
            $hinweise[] = 'shop_'.$paypal.'client_id';
        }
        if ($leer('base_url')) {
            $hinweise[] = 'shop_base_url';
        }
        if (shopChannelBool($db, $id, 'paypal_sandbox', true)) {
            $hinweise[] = 'shop_paypal_sandbox';
        }

        // Veröffentlichung: Ohne gültiges Programm werden Seiten geschrieben,
        // aber nicht gebaut; ohne Verzeichnis entsteht überhaupt keine Seite.
        // Der Feldname allein sagt nicht, was daran fehlt — der Grund kommt mit.
        if ('hugocms' === shopPublishMode($db, $id)) {
            // Gebaut wird dort. Hier zählt nur, ob Adresse und Schlüssel
            // gesetzt sind — ob sie stimmen, prüft der Knopf in der Kanalkarte;
            // ein Aufruf bei jedem Öffnen der Übersicht wäre zu teuer.
            $adresse = shopHugoCmsUrl(shopChannelValue($db, $id, 'hugocms_url'));
            if ('' !== $adresse['fehler']) {
                $hinweise[] = 'shop_hugocms_url';
                $veroeffentlichung[] = $vorsilbe.$adresse['fehler'];
            }
            if ($leer('hugocms_key')) {
                $hinweise[] = 'shop_hugocms_key';
            }
        } else {
            $programm ??= shopPublishProgram($db);
            if ('' === $programm['pfad']) {
                $hinweise[] = 'shop_publish_command_path';
                if ('' !== $programm['fehler']) {
                    $veroeffentlichung[] = $programm['fehler'];
                }
            }
            try {
                shopSiteDir($db, $id);
            } catch (Throwable $e) {
                $hinweise[] = 'shop_sites_dir';
                $veroeffentlichung[] = $vorsilbe.$e->getMessage();
            }
        }
    }

    // V18: Die HugoShop-Punkte gelten nur mit eingeschaltetem HugoShop — etwa
    // bei einem Mandanten, der nur über eBay verkauft, fehlt nichts davon.
    if (!$hugoshops) {
        $blockierend = [];
        $hinweise = [];
        $veroeffentlichung = [];
    }

    // Ohne Versandart nimmt der HugoShop Bestellungen an, berechnet aber
    // keinen Versand — kein fehlender Punkt, aber eine eigene Warnung
    $versandFehlt = $hugoshops && !$wahr($stand['versandart']);

    // eBay-Kanäle: ohne diese Angaben lehnt eBay jedes Angebot ab
    foreach (array_filter($kanaele, fn($k) => 'ebay' === $k['type']) as $kanal) {
        $ebay = function_exists('shopEbayConfig') ? shopEbayConfig($db, (int)$kanal['id']) : [];
        $leer = fn(string $key) => '' === trim((string)($ebay[$key] ?? ''));
        foreach ([
            'ebay_client_id'          => $leer('client_id') || $leer('client_secret') || $leer('refresh_token'),
            'ebay_public_host'        => $leer('public_host'),
            'ebay_category_id'        => $leer('default_category_id'),
            'ebay_location_key'       => $leer('merchant_location_key'),
            'ebay_payment_policy'     => $leer('payment_policy_id'),
            'ebay_return_policy'      => $leer('return_policy_id'),
            'ebay_fulfillment_policy' => $leer('fulfillment_policy_id'),
        ] as $punkt => $fehlt) {
            if ($fehlt) {
                $hinweise[] = $punkt;
            }
        }
    }
    $blockierend = array_values(array_unique($blockierend));
    $hinweise = array_values(array_unique($hinweise));
    $veroeffentlichung = array_values(array_unique($veroeffentlichung));

    // O14: Ohne Lagerplatz buchen Verkäufe kein Lager aus — der gemeinsame
    // Bestand stimmt dann nicht, und eBay meldet den alten
    if (($wahr($stand['hugoshop_an']) || $wahr($stand['ebay_an'])) && !$wahr($stand['lagerplatz'])) {
        $hinweise[] = 'shop_stock_bin_id';
    }

    // Empfehlungen: nichts fehlt, aber eine andere Einstellung wäre besser.
    // Rundung auf ,99 bei Nettopreisen in den Stammdaten (V2d): der Nettopreis
    // wird aus dem gerundeten Bruttopreis zurückgerechnet, und die Rechnung
    // kann um einen Cent vom Warenkorb abweichen.
    $empfehlungen = [];
    if ($wahr($stand['rundung_99']) && !shopConfigBool($db, 'shop_tax_included')) {
        $empfehlungen[] = 'channel_round_99_net';
    }

    resultInfo(true, '', [
        'ready'     => empty($blockierend),
        'blocking'  => $blockierend,
        'shipping_missing' => $versandFehlt,
        'hints'     => $hinweise,
        'recommendations' => $empfehlungen,
        'publish_problems' => $veroeffentlichung,
        'counts'    => [
            'parts_with_shop_data' => (int)$stand['artikel_mit_shopdaten'],
            'sessions'             => (int)$stand['sitzungen'],
            'carts'                => (int)$stand['warenkoerbe'],
        ],
    ]);
}

/**
 * Bestellungen des Shops
 *
 * Nur Rechnungen, die über den Shop entstanden sind — erkennbar an der
 * Verknüpfung in ar_link_hugoshop. Rechnungen aus der Faktura bleiben aussen
 * vor; für die gibt es die Belegübersicht.
 *
 * @param array $data['open'] Optional true: nur unbezahlte
 * @param array $data['limit'] Optional Höchstzahl (Vorgabe 100)
 * @return void
 * @testdata {"limit": 20}
 */
function getShopOrders($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $nurOffene = !empty($data['open']);
    $limit = (int)($data['limit'] ?? 100);

    resultInfo(true, '', ['results' => $db->getAll(
        "SELECT ar.id AS ar_id, ar.invnumber, ar.transdate, ar.duedate,
                TRUNC(ar.amount, 2) AS amount, TRUNC(ar.paid, 2) AS paid,
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency,
                c.id AS customer_id, c.name AS customer, c.customernumber,
                ce.hugoshop_guest AS guest,
                al.uuid AS ar_link, al.paypal, al.paypal_order_id,
                al.payment_status, al.payment_reason, al.payment_mtime,
                (SELECT COUNT(*) FROM invoice WHERE trans_id = ar.id) AS positions
           FROM ar_link_hugoshop al
           JOIN ar ON ar.id = al.ar_id
           JOIN customer c ON c.id = ar.customer_id
           LEFT JOIN customer_ext ce ON ce.customer_id = c.id
          WHERE NOT :nur_offene OR COALESCE(al.payment_status, '') <> 'COMPLETED'
          ORDER BY ar.id DESC
          LIMIT :limit",
        [':nur_offene' => $nurOffene, ':limit' => $limit]
    )]);
}

/**
 * Rechnungen, deren Zahlung bei PayPal noch schwebt
 *
 * Solange niemand hinsieht, bleibt eine schwebende Zahlung offen stehen. Diese
 * Liste ist die Grundlage dafür, dass jemand hinsieht.
 *
 * @return void
 * @testdata {}
 */
function getPendingPayments($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', ['results' => paymentsPending($db, (int)($data['limit'] ?? 100))]);
}

/**
 * Fragt die schwebenden Zahlungen bei PayPal nach und trägt das Ergebnis ein
 *
 * Gebucht wird nichts: bestätigte Zahlungen sind danach als bezahlt vermerkt
 * und im ERP von Hand zu buchen — wie Überweisungen auch.
 *
 * @return void
 * @testdata {}
 */
function reconcileShopPayments($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', ['results' => paymentsReconcile($db, (int)($data['limit'] ?? 100))]);
}

/**
 * Eingegangene Widerrufe
 *
 * @param array $data['open'] Optional true: nur unbearbeitete
 * @return void
 * @testdata {}
 */
function getShopWithdrawals($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', ['results' => withdrawalsList(
        $db, !empty($data['open']), (int)($data['limit'] ?? 100)
    )]);
}

/**
 * Merkt einen Widerruf als bearbeitet vor
 *
 * @param array $data['id'] Widerruf
 * @param array $data['processed'] true = bearbeitet, false = wieder offen
 * @return void
 * @testdata {"id": 1, "processed": true}
 */
function setShopWithdrawalProcessed($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    withdrawalSetProcessed($db, (int)($data['id'] ?? 0), !empty($data['processed']));
    resultInfo(true, 'WITHDRAWAL_UPDATED');
}

/**
 * Shop-Angaben eines Artikels mit seinen Verkaufskanälen
 *
 * Eine Abfrage für die ganze Artikelkarte:
 *
 *   part       Artikel und Shop-Angaben aus parts_ext; null bei der Neuanlage
 *              oder wenn es den Artikel nicht gibt. listed = in mindestens
 *              einem Kanal aktiv
 *   channels   alle eingeschalteten Kanäle mit den Werten des Artikels
 *              (leer, wenn er dort keine Zeile hat) und den Vorgaben des Kanals
 *   tax_rates  Steuersatz der Standard-Steuerzone je Buchungsgruppe — für die
 *              Preisvorschau, die in der Oberfläche gerechnet wird, auch wenn
 *              in der Maske eine andere Buchungsgruppe gewählt wird
 *   shipping   Versandangaben des Artikels (parts_shipping_shop) und die
 *              Auswahllisten dafür: Versandarten, Lieferbedingungen,
 *              Gewichtseinheit (dev/shop-versand.md, Schritt 5)
 *
 * Je HugoShop steht in shipping_message, warum keine Versandart passt (leer =
 * passt) — ohne passende wird die Seite nicht veröffentlicht.
 *
 * Die Shop-Angaben aus parts_ext bleiben auch nach dem Abwählen erhalten (V5).
 *
 * @param array $data['parts_id'] Artikel; leer oder 0 bei der Neuanlage
 * @return void
 * @testdata {"parts_id": 1}
 */
function getPartShopData($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $zeile = $db->getOne(
        "SELECT
            (SELECT row_to_json(a) FROM (
                SELECT p.id AS parts_id, p.partnumber, p.description, TRUNC(p.sellprice, 2) AS sellprice,
                       EXISTS (SELECT 1 FROM parts_channel_shop x
                                 JOIN sales_channel_shop xc ON xc.id = x.channel_id AND xc.active
                                WHERE x.parts_id = p.id AND x.active) AS listed,
                       pe.hugoshop_breadcrumbs, pe.hugoshop_technical_data, pe.hugoshop_properties,
                       pe.hugoshop_downloads, pe.hugoshop_images, pe.hugoshop_hyperlink,
                       pe.hugoshop_category, p.weight,
                       -- Versandartikel: gehört dieser Versandart, wird nie angeboten
                       (SELECT m.description FROM shipping_method_shop m WHERE m.parts_id = p.id) AS shipping_method_of
                  FROM parts p
                  LEFT JOIN parts_ext pe ON pe.parts_id = p.id
                 WHERE p.id = :parts_id) a) AS part,
            (SELECT json_build_object(
                 'part', (SELECT row_to_json(v) FROM (
                             SELECT ps.shipping_method_id, ps.length, ps.width, ps.height,
                                    ps.min_qty, ps.delivery_term_id
                               FROM parts_shipping_shop ps WHERE ps.parts_id = :parts_id_versand) v),
                 'methods', (SELECT COALESCE(json_agg(json_build_object(
                                        'id', m.id, 'description', m.description, 'active', m.active)
                                        ORDER BY m.rank DESC, m.description), '[]')
                               FROM shipping_method_shop m),
                 'delivery_terms', (SELECT COALESCE(json_agg(json_build_object(
                                               'id', d.id, 'description', d.description,
                                               'description_long', d.description_long, 'obsolete', d.obsolete)
                                               ORDER BY d.sortkey, d.id), '[]')
                                      FROM delivery_terms d),
                 'weightunit', (SELECT weightunit FROM defaults LIMIT 1)
             )) AS shipping,
            (SELECT COALESCE(json_agg(k ORDER BY k.sortkey NULLS LAST, k.channel_id), '[]'::json) FROM (
                SELECT c.id AS channel_id, c.type, c.name, c.sortkey,
                       -- Betriebsart der Webseite (HugoShop): Bilder aus einem
                       -- Marktplatz lassen sich nur lokal übernehmen (V17)
                       COALESCE(c.settings ->> 'publish_mode', 'local') AS publish_mode,
                       -- Adressmuster des HugoShops: Link zur Produktseite, Vorschaubilder
                       COALESCE(c.settings ->> 'products_link', '') AS products_link,
                       COALESCE(c.settings ->> 'thumbnails_link', '') AS thumbnails_link,
                       c.markup_type AS channel_markup_type, c.markup_value AS channel_markup_value,
                       c.round_99,
                       COALESCE(pc.active, false) AS active,
                       pc.markup_type, pc.markup_value, pc.title, pc.description,
                       COALESCE(pc.unavailable, false) AS unavailable,
                       COALESCE(pc.settings, '{}'::jsonb) AS settings,
                       pc.sync_status, pc.sync_error, pc.sync_mtime, pc.external_id,
                       -- HugoShop: passt eine Versandart? Sonst wird die Seite
                       -- nicht veröffentlicht (shop_part_shipping_check)
                       CASE WHEN c.type = 'hugoshop'
                            THEN (SELECT row_to_json(v)
                                    FROM shop_part_shipping_check(CAST(:parts_id_pruefung AS integer), c.id) v) END AS shipping_check,
                       -- Bilder der Marktplätze; der HugoShop führt seine in parts_ext
                       (SELECT COALESCE(json_agg(json_build_object(
                                   'id', i.id, 'filename', i.filename,
                                   'url', '/webhook/part-image.php?db=' || current_database()
                                          || '&id=' || i.parts_id || '&f=' || i.filename)
                                ORDER BY i.sort, i.id), '[]'::json)
                          FROM parts_channel_image_shop i
                         WHERE i.parts_id = :parts_id_bilder AND i.channel_id = c.id) AS images
                  FROM sales_channel_shop c
                  LEFT JOIN parts_channel_shop pc ON pc.channel_id = c.id AND pc.parts_id = :parts_id
                 WHERE c.active) k) AS channels,
            (SELECT COALESCE(json_object_agg(bg.id, shop_tax_rate(bg.id, :taxzone)), '{}'::json)
               FROM buchungsgruppen bg) AS tax_rates",
        [
            ':parts_id'        => (int)($data['parts_id'] ?? 0),
            ':parts_id_bilder' => (int)($data['parts_id'] ?? 0),
            ':parts_id_versand' => (int)($data['parts_id'] ?? 0),
            ':parts_id_pruefung' => (int)($data['parts_id'] ?? 0),
            ':taxzone'         => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
        ]
    );

    // Die Meldung zur Versandart je HugoShop, leer = passt
    $kanaele = json_decode((string)($zeile['channels'] ?? '[]'), true) ?: [];
    foreach ($kanaele as &$kanal) {
        $kanal['shipping_message'] = shopShippingCheckText((array)($kanal['shipping_check'] ?? []));
        unset($kanal['shipping_check']);
    }
    unset($kanal);

    resultInfo(true, '', [
        'part'      => json_decode((string)($zeile['part'] ?? 'null'), true),
        'channels'  => $kanaele,
        'tax_rates' => json_decode((string)($zeile['tax_rates'] ?? '{}'), true) ?: (object)[],
        'shipping'  => json_decode((string)($zeile['shipping'] ?? '{}'), true) ?: (object)[],
    ]);
}

/**
 * Speichert die Shop-Angaben eines Artikels und seine Verkaufskanäle
 *
 * Ein Vorgang: Shop-Angaben in parts_ext anlegen oder ändern und die
 * übergebenen Kanalzeilen schreiben. Kanäle, die nicht übergeben werden,
 * bleiben unberührt.
 *
 * Ohne channels wird nur der HugoShop eingeschaltet, Aufschlag und Texte
 * bleiben — so wie vor den Verkaufskanälen.
 *
 * Wird der HugoShop dabei abgewählt, entsteht der Auftrag zum Entfernen der
 * Produktseite, wie beim Herausnehmen aus dem Shop.
 *
 * @param array $data['parts_id'] Artikel
 * @param array $data['category'] Kategorie
 * @param array $data['hyperlink'] Zielseite im Shop
 * @param array $data['breadcrumbs'] JSON-Array
 * @param array $data['images'] JSON-Array
 * @param array $data['technical_data'] JSON-Objekt: Bezeichnung => Wert
 * @param array $data['properties'] JSON-Objekt: Bezeichnung => Wert
 * @param array $data['downloads'] JSON-Objekt: Anzeigename => Dateiname
 * @param array $data['channels'] Liste aus channel_id, active, markup_type
 *              (null = Vorgabe des Kanals, none, percent, amount), markup_value,
 *              title, description, unavailable (vorübergehend nicht
 *              verfügbar), settings (kanaleigene Angaben, etwa
 *              category_id und condition bei eBay)
 * @param array $data['shipping'] Versandangaben (dev/shop-versand.md, Schritt 5):
 *              shipping_method_id (leer = die günstigste passende), length,
 *              width, height (cm), min_qty (Mindestabnahme, bei eBay die
 *              Losgröße), delivery_term_id (Lieferbedingung). Fehlt das Feld,
 *              bleiben die Angaben unverändert
 * @return void
 * @testdata {"parts_id": 1, "category": "Bremsen", "hyperlink": "bremsscheibe", "channels": [{"channel_id": 1, "active": true, "markup_type": "percent", "markup_value": 10, "title": "", "description": "", "unavailable": false}], "shipping": {"shipping_method_id": null, "length": 40, "width": 30, "height": 20, "min_qty": null, "delivery_term_id": null}}
 */
function savePartShopData($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $partsId = (int)($data['parts_id'] ?? 0);
    if (0 === $partsId) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'parts_id fehlt');
        return;
    }

    // Die drei Felder sind Objekte. Ohne JSON_FORCE_OBJECT würde ein leeres zu []
    // und eines mit Schlüsseln "0", "1", … zu einer Liste.
    $objekt = fn($wert) => json_encode(is_array($wert) ? $wert : [], JSON_FORCE_OBJECT);

    // Ohne Kanalliste: nur den HugoShop einschalten (channel_id null steht
    // in der Abfrage für ihn), gepflegte Werte nicht anfassen.
    $vollstaendig = isset($data['channels']) && is_array($data['channels']);
    $kanaele = $vollstaendig ? array_values($data['channels']) : [['channel_id' => null, 'active' => true]];

    // Ein Versandartikel wird nie angeboten (dev/shop-versand.md, Nachtrag
    // 2026-10-02): er gehört seiner Versandart, die ihn verwaltet
    if (array_filter($kanaele, fn($k) => !empty($k['active']))) {
        $versandart = $db->getOne(
            "SELECT description FROM shipping_method_shop WHERE parts_id = :parts_id",
            [':parts_id' => $partsId]
        );
        if ($versandart) {
            resultInfo(false, 'SHIPPING_PART_NOT_OFFERED', null,
                'Versandartikel der Versandart „'.$versandart['description'].'“ — wird nicht im Shop angeboten');
            return;
        }
    }

    // Versandangaben: Zahlen größer 0 oder leer. Fehlt das Feld, bleiben sie.
    $versandSetzen = isset($data['shipping']) && is_array($data['shipping']);
    $versand = $versandSetzen ? $data['shipping'] : [];
    $mass = [];
    foreach (['length', 'width', 'height', 'min_qty'] as $feld) {
        $wert = $versand[$feld] ?? null;
        if (null === $wert || '' === trim((string)$wert)) {
            $mass[$feld] = null;
        } elseif (is_numeric($wert) && (float)$wert > 0) {
            $mass[$feld] = (string)(float)$wert;
        } else {
            resultInfo(false, 'VALIDATION_ERROR', null, 'Abmessungen und Mindestabnahme müssen leer oder größer als 0 sein');
            return;
        }
    }

    // vorher liest den Stand vor der Anweisung — alle Teile einer Anweisung
    // sehen denselben Ausgangsstand. Daraus ergibt sich, in welchen HugoShops
    // der Artikel gerade abgewählt wird.
    try {
        $seiten = $db->getAll(
            "WITH vorher AS (
                 SELECT pc.channel_id, pc.active
                   FROM parts_channel_shop pc
                   JOIN sales_channel_shop c ON c.id = pc.channel_id AND c.type = 'hugoshop'
                  WHERE pc.parts_id = :parts_id
             ), ext AS (
                 INSERT INTO parts_ext (parts_id, hugoshop_category, hugoshop_hyperlink,
                                        hugoshop_breadcrumbs, hugoshop_images,
                                        hugoshop_technical_data, hugoshop_properties, hugoshop_downloads)
                 SELECT p.id, :category, :hyperlink, :breadcrumbs::jsonb, :images::jsonb,
                        :technical::jsonb, :properties::jsonb, :downloads::jsonb
                   FROM parts p WHERE p.id = :parts_id
                 ON CONFLICT (parts_id) DO UPDATE SET
                        hugoshop_category       = EXCLUDED.hugoshop_category,
                        hugoshop_hyperlink      = EXCLUDED.hugoshop_hyperlink,
                        hugoshop_breadcrumbs    = EXCLUDED.hugoshop_breadcrumbs,
                        hugoshop_images         = EXCLUDED.hugoshop_images,
                        hugoshop_technical_data = EXCLUDED.hugoshop_technical_data,
                        hugoshop_properties     = EXCLUDED.hugoshop_properties,
                        hugoshop_downloads      = EXCLUDED.hugoshop_downloads
                 RETURNING parts_id
             ), eingabe AS (
                 -- je Kanal eine Zeile: ein doppelter Eintrag ließe ON CONFLICT
                 -- dieselbe Zeile zweimal ändern, und die Anweisung bräche ab
                 SELECT DISTINCT ON (COALESCE(e.channel_id, shop_first_channel_id('hugoshop')))
                        COALESCE(e.channel_id, shop_first_channel_id('hugoshop')) AS channel_id,
                        COALESCE(e.active, false) AS active,
                        CASE WHEN e.markup_type IN ('none', 'percent', 'amount') THEN e.markup_type END AS markup_type,
                        CASE WHEN e.markup_type IN ('percent', 'amount') THEN COALESCE(e.markup_value, 0) END AS markup_value,
                        NULLIF(btrim(e.title), '') AS title,
                        NULLIF(btrim(e.description), '') AS description,
                        COALESCE(e.unavailable, false) AS unavailable,
                        -- leere Angaben fallen weg: sie hießen „Vorgabe des Kanals“
                        (SELECT jsonb_object_agg(a.key, a.value)
                           FROM jsonb_each(CASE WHEN jsonb_typeof(e.settings) = 'object' THEN e.settings END) a
                          WHERE a.value NOT IN ('null'::jsonb, to_jsonb(''::text))) AS settings
                   FROM jsonb_to_recordset(:kanaele::jsonb)
                        AS e(channel_id integer, active boolean, markup_type text, markup_value numeric,
                             title text, description text, unavailable boolean, settings jsonb)
             ), geschrieben AS (
                 INSERT INTO parts_channel_shop (parts_id, channel_id, active, markup_type, markup_value,
                                                 title, description, unavailable, settings)
                 SELECT ext.parts_id, e.channel_id, e.active, e.markup_type, e.markup_value,
                        e.title, e.description, e.unavailable, e.settings
                   FROM ext
                   JOIN eingabe e ON true
                   JOIN sales_channel_shop c ON c.id = e.channel_id
                 ON CONFLICT (parts_id, channel_id) DO UPDATE SET
                        active       = EXCLUDED.active,
                        markup_type  = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.markup_type  ELSE parts_channel_shop.markup_type END,
                        markup_value = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.markup_value ELSE parts_channel_shop.markup_value END,
                        title        = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.title        ELSE parts_channel_shop.title END,
                        description  = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.description  ELSE parts_channel_shop.description END,
                        unavailable  = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.unavailable  ELSE parts_channel_shop.unavailable END,
                        settings     = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.settings     ELSE parts_channel_shop.settings END,
                        mtime        = now()
                 RETURNING channel_id, active
             ), versand AS (
                 -- Versandangaben (Schritt 5); unbekannte Versandart oder
                 -- Lieferbedingung scheitern am Fremdschlüssel
                 INSERT INTO parts_shipping_shop (parts_id, shipping_method_id, length, width, height,
                                                  min_qty, delivery_term_id)
                 SELECT ext.parts_id, CAST(NULLIF(:versandart, 0) AS integer),
                        CAST(:laenge AS numeric), CAST(:breite AS numeric), CAST(:hoehe AS numeric),
                        CAST(:mindestmenge AS numeric), CAST(NULLIF(:lieferbedingung, 0) AS integer)
                   FROM ext
                  WHERE :versand_setzen = 1
                 ON CONFLICT (parts_id) DO UPDATE SET
                        shipping_method_id = EXCLUDED.shipping_method_id,
                        length             = EXCLUDED.length,
                        width              = EXCLUDED.width,
                        height             = EXCLUDED.height,
                        min_qty            = EXCLUDED.min_qty,
                        delivery_term_id   = EXCLUDED.delivery_term_id
             )
             SELECT v.channel_id, p.partnumber, pe.hugoshop_hyperlink
               FROM vorher v
               JOIN geschrieben g ON g.channel_id = v.channel_id AND NOT g.active
               JOIN parts p ON p.id = :parts_id
               LEFT JOIN parts_ext pe ON pe.parts_id = p.id
              WHERE v.active",
            [
                ':parts_id'     => $partsId,
                ':category'     => $data['category']  ?? null,
                ':hyperlink'    => $data['hyperlink'] ?? null,
                ':breadcrumbs'  => json_encode($data['breadcrumbs']    ?? []),
                ':images'       => json_encode($data['images']         ?? []),
                ':technical'    => $objekt($data['technical_data'] ?? null),
                ':properties'   => $objekt($data['properties']     ?? null),
                ':downloads'    => $objekt($data['downloads']      ?? null),
                ':kanaele'      => json_encode($kanaele),
                ':vollstaendig' => $vollstaendig ? 1 : 0,
                ':versand_setzen'  => $versandSetzen ? 1 : 0,
                ':versandart'      => (int)($versand['shipping_method_id'] ?? 0),
                ':laenge'          => $mass['length'],
                ':breite'          => $mass['width'],
                ':hoehe'           => $mass['height'],
                ':mindestmenge'    => $mass['min_qty'],
                ':lieferbedingung' => (int)($versand['delivery_term_id'] ?? 0),
            ]
        );
    } catch (PDOException $e) {
        // Fremdschlüssel: Versandart oder Lieferbedingung gibt es nicht (mehr)
        if ('23503' === $e->getCode()) {
            resultInfo(false, 'VALIDATION_ERROR', null, 'Versandart oder Lieferbedingung gibt es nicht (mehr)');
            return;
        }
        throw $e;
    }

    // Die abschließende Abfrage liest parts_ext im Stand vor der Anweisung,
    // also die Zielseite, unter der die Seite tatsächlich liegt — auch wenn
    // dieselbe Speicherung die Adresse ändert. Je HugoShop, in dem der Artikel
    // abgewählt wurde, ein Auftrag.
    foreach ($seiten ?: [] as $seite) {
        shopQueueRemovePage($db, (int)$seite['channel_id'], (string)$seite['partnumber'],
                            (string)($seite['hugoshop_hyperlink'] ?? ''));
    }

    // Passt nach dem Speichern eine Versandart? Eine eigene Abfrage: die
    // Anweisung oben sieht ihre eigenen Änderungen nicht (Momentaufnahme).
    $pruefungen = $db->getAll(
        "SELECT c.id AS channel_id, row_to_json(v) AS pruefung
           FROM sales_channel_shop c
           CROSS JOIN LATERAL shop_part_shipping_check(CAST(:parts_id AS integer), c.id) v
          WHERE c.active AND c.type = 'hugoshop'",
        [':parts_id' => $partsId]
    ) ?: [];

    resultInfo(true, 'PART_SHOP_DATA_SAVED', [
        'shipping_messages' => array_map(fn($z) => [
            'channel_id' => (int)$z['channel_id'],
            'message'    => shopShippingCheckText(json_decode((string)$z['pruefung'], true) ?: []),
        ], $pruefungen),
    ]);
}

/**
 * Nimmt einen Artikel aus dem Shop
 *
 * Schaltet alle seine Kanalzeilen ab; danach findet ihn die Shop-Suche nicht
 * mehr. Die Shop-Angaben in parts_ext, Titel, Beschreibung und Aufschläge
 * bleiben für ein späteres Wiedereinschalten erhalten (V5). Der Artikel
 * selbst, Warenkörbe und Rechnungen bleiben unberührt.
 *
 * @param array $data['parts_id'] Artikel
 * @return void
 * @testdata {"parts_id": 1}
 */
function deletePartShopData($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $partsId = (int)($data['parts_id'] ?? 0);

    // Abschalten und den Dateinamen der Seite lesen in einem Vorgang. Je
    // HugoShop, in dem der Artikel angeboten wurde, kommt eine Zeile zurück —
    // dort entsteht der Auftrag zum Entfernen der Seite.
    $seiten = $db->getAll(
        "WITH aus AS (
             UPDATE parts_channel_shop SET active = false, mtime = now()
              WHERE parts_id = :parts_id AND active
             RETURNING parts_id, channel_id
         )
         SELECT aus.channel_id, p.partnumber, pe.hugoshop_hyperlink
           FROM aus
           JOIN sales_channel_shop c ON c.id = aus.channel_id AND c.type = 'hugoshop'
           JOIN parts p ON p.id = aus.parts_id
           LEFT JOIN parts_ext pe ON pe.parts_id = p.id",
        [':parts_id' => $partsId]
    );

    foreach ($seiten ?: [] as $seite) {
        shopQueueRemovePage($db, (int)$seite['channel_id'], (string)$seite['partnumber'],
                            (string)($seite['hugoshop_hyperlink'] ?? ''));
    }

    resultInfo(true, 'PART_SHOP_DATA_REMOVED');
}

/**
 * Legt den Auftrag an, die Produktseite eines Artikels zu entfernen
 *
 * Der Dateiname folgt der Zielseite, ohne Zielseite der Artikelnummer — wie
 * beim Schreiben der Seite.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop, aus dessen Webseite die Seite verschwindet
 * @param string $partnumber Artikelnummer
 * @param string $hyperlink Zielseite aus parts_ext, leer = Artikelnummer
 * @return void
 */
function shopQueueRemovePage($db, int $kanal, string $partnumber, string $hyperlink): void {
    $datei = shopPageFileName(['shop' => ['hyperlink' => $hyperlink], 'artikel' => ['partnumber' => $partnumber]]);
    shopQueueJob($db, 'remove_part', $partnumber, $datei, $kanal);
}

/**
 * Lädt ein Bild für einen Marktplatz-Kanal hoch
 *
 * @param array $data['parts_id'] Artikel
 * @param array $data['channel'] Kennung des Kanals; die Art (etwa ebay) meint den Standardkanal der Art
 * @param array $data['filename'] ursprünglicher Dateiname
 * @param array $data['data'] Bilddaten Base64, auch als data:-Adresse
 * @return void Bilder des Kanals nach dem Hochladen
 * @testdata {"parts_id": 1, "channel": "ebay", "filename": "foto.jpg", "data": "data:image/png;base64,..."}
 */
function uploadShopChannelImage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $partsId = (int)($data['parts_id'] ?? 0);
    $kanal = shopChannelParam($db, $data['channel'] ?? '')['id'];
    $roh = (string)($data['data'] ?? '');
    if (false !== ($stelle = strpos($roh, 'base64,'))) {
        $roh = substr($roh, $stelle + 7);
    }
    $inhalt = base64_decode($roh, true);
    if (0 === $partsId || false === $inhalt) {
        resultInfo(false, 'SHOP_IMAGE_INVALID', null, 'Artikel oder Bilddaten fehlen');
        return;
    }

    shopChannelImageStore($db, $partsId, $kanal, $inhalt, (string)($data['filename'] ?? ''));
    resultInfo(true, '', ['images' => shopChannelImages($db, $partsId, $kanal)]);
}

/**
 * Entfernt ein Bild aus einem Marktplatz-Kanal
 *
 * @param array $data['image_id'] Bild
 * @return void Bilder des Kanals danach
 * @testdata {"image_id": 1}
 */
function deleteShopChannelImage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $bild = shopChannelImageDelete($db, (int)($data['image_id'] ?? 0));
    resultInfo(true, '', ['images' => $bild ? shopChannelImages($db, $bild['parts_id'], $bild['channel_id']) : []]);
}

/**
 * Setzt die Reihenfolge der Bilder eines Marktplatz-Kanals
 *
 * @param array $data['parts_id'] Artikel
 * @param array $data['channel'] Kennung des Kanals oder seine Art
 * @param array $data['ids'] Bildkennungen in der neuen Reihenfolge, das erste ist das Hauptbild
 * @return void Bilder des Kanals danach
 * @testdata {"parts_id": 1, "channel": "ebay", "ids": [2, 1]}
 */
function sortShopChannelImages($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $partsId = (int)($data['parts_id'] ?? 0);
    $kanal = shopChannelParam($db, $data['channel'] ?? '')['id'];
    shopChannelImageSort($db, $partsId, $kanal, (array)($data['ids'] ?? []));
    resultInfo(true, '', ['images' => shopChannelImages($db, $partsId, $kanal)]);
}

/**
 * Übernimmt die Bilder eines Artikels aus einem anderen Kanal (V12, V17)
 *
 * Vom HugoShop in einen Marktplatz immer; aus einem Marktplatz in den
 * HugoShop nur in der Betriebsart lokal.
 *
 * @param array $data['parts_id'] Artikel
 * @param array $data['from'] Quellkanal: Kennung oder Art
 * @param array $data['to'] Zielkanal: Kennung oder Art
 * @return void Zahl der übernommenen Bilder
 * @testdata {"parts_id": 1, "from": "hugoshop", "to": "ebay"}
 */
function copyShopChannelImages($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $anzahl = shopChannelImageCopy($db, (int)($data['parts_id'] ?? 0), $data['from'] ?? '', $data['to'] ?? '');
    resultInfo(true, 'CHANNEL_IMAGES_COPIED', ['copied' => $anzahl]);
}

/**
 * Prüft die Verbindung zu eBay: Token holen und eine Bestellung abfragen
 *
 * Erzwingt ein frisches Token — so zeigt der Test auch ein abgelaufenes
 * Refresh-Token.
 *
 * @return void Umgebung und Zahl der Bestellungen bei eBay
 * @testdata {}
 */
function testShopEbay($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $kanal = shopChannelOfRequest($db, $data, 'ebay');
    // Frisches Token: prüft dabei das Konto (M4, shopEbayAccountCheck)
    shopEbayToken($db, $kanal, true);
    $antwort = shopEbayApi($db, $kanal, 'GET', '/sell/fulfillment/v1/order', null, ['limit' => 1]);
    if ($antwort['status'] >= 400) {
        resultInfo(false, 'EBAY_API_ERROR', null, 'eBay-API-Fehler: '.shopEbayErrorMessage($antwort));
        return;
    }
    $cfg = shopEbayConfig($db, $kanal);
    resultInfo(true, '', [
        'connected'   => true,
        'environment' => $cfg['environment'] ?? 'production',
        // leer: Konto nicht feststellbar (kein Identity-Scope, keine Bestellung)
        'account'     => $cfg['account_id'] ?? '',
        'orderTotal'  => (int)($antwort['body']['total'] ?? 0),
    ]);
}

/**
 * Ruft die eBay-Bestellungen jetzt ab, statt auf den Cron zu warten
 *
 * @param array $data['channel_id'] eBay-Kanal, leer = Standard-eBay-Kanal
 * @return void imported, skipped, fetched, errors
 * @testdata {}
 */
function syncShopEbayOrders($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', shopEbayImportOrders($db, shopChannelOfRequest($db, $data, 'ebay')));
}

/**
 * Stand eines eBay-Kanals: letzter Abruf, Konto und zuletzt importierte Bestellungen
 *
 * @param array $data['channel_id'] eBay-Kanal, leer = Standard-eBay-Kanal
 * @return void channel_id, account, enabled, lastCheck, counts, recent
 * @testdata {}
 */
function getShopEbayStatus($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', shopEbayStatus($db, shopChannelOfRequest($db, $data, 'ebay')));
}

/**
 * Länder der Adressen und ihre Zuordnung (dev/shop-versand.md, Schritt 3)
 *
 * Jeder Freitext, der als Land in Kundenadressen, Lieferadressen oder
 * weiteren Rechnungsadressen vorkommt — bereinigt zusammengefasst, mit der
 * Zahl der Adressen und dem zugeordneten Land (leer = nicht zugeordnet).
 * Leere Freitexte stehen als eigene Zeile: sie meinen das Land des
 * Mandanten. Dazu die Länderliste für die Auswahl; die Namen übersetzt die
 * Oberfläche aus dem Code.
 *
 * @return void
 * @testdata {}
 */
function getShopCountryMapping($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $zeile = $db->getOne(
        "WITH texte AS (
             SELECT country AS text FROM customer
             UNION ALL SELECT shiptocountry FROM shipto
             UNION ALL SELECT country FROM additional_billing_addresses
         ), gruppen AS (
             SELECT COALESCE(shop_country_key(text), '') AS alias,
                    min(btrim(text)) AS beispiel,
                    count(*) AS anzahl
               FROM texte
              GROUP BY 1
         )
         SELECT
             (SELECT COALESCE(json_agg(json_build_object(
                         'alias',    g.alias,
                         'beispiel', COALESCE(g.beispiel, ''),
                         'anzahl',   g.anzahl,
                         'iso_code', CASE WHEN g.alias = '' THEN shop_country_code(NULL) ELSE a.iso_code END,
                         'manual',   COALESCE(a.manual, false)
                     ) ORDER BY (a.iso_code IS NULL AND g.alias <> '') DESC, g.anzahl DESC, g.alias), '[]')
                FROM gruppen g
                LEFT JOIN country_alias_shop a ON a.alias = g.alias) AS texte,
             (SELECT COALESCE(json_agg(json_build_object('iso_code', c.iso_code, 'eu', c.eu)
                                       ORDER BY c.iso_code), '[]')
                FROM country_shop c) AS laender,
             (SELECT address_country FROM defaults LIMIT 1) AS mandantenland,
             shop_country_code(NULL) AS mandantenland_code"
    );

    resultInfo(true, '', [
        'texts'               => json_decode((string)$zeile['texte'], true),
        'countries'           => json_decode((string)$zeile['laender'], true),
        'company_country'     => (string)($zeile['mandantenland'] ?? ''),
        'company_country_code' => $zeile['mandantenland_code'],
    ]);
}

/**
 * Ordnet einen Freitext einem Land zu oder hebt die Zuordnung auf
 *
 * Die Zuordnung gilt für jede Schreibweise mit demselben bereinigten Text
 * (shop_country_key). Sie wird als „von Hand" vermerkt; ohne Land wird sie
 * entfernt — auch eine aus der Länderliste, dann ist der Text nicht mehr
 * zugeordnet.
 *
 * @param string $data['alias'] Freitext (wird bereinigt)
 * @param string $data['iso_code'] Land, leer = Zuordnung entfernen
 * @return void
 * @testdata {"alias": "Brandenburg", "iso_code": "DE"}
 */
function saveShopCountryAlias($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $alias = trim((string)($data['alias'] ?? ''));
    $land  = strtoupper(trim((string)($data['iso_code'] ?? '')));
    if ('' === $alias) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Ein leerer Text meint das Land des Mandanten und lässt sich nicht zuordnen');
        return;
    }

    // Ein Vorgang: ohne Land löschen, sonst setzen. Ein unbekannter Code
    // scheitert am Fremdschlüssel spätestens beim Abschluss der Anweisung —
    // vorher geprüft, damit die Meldung verständlich bleibt.
    $zeile = $db->getOne(
        "WITH geloescht AS (
             DELETE FROM country_alias_shop
              WHERE alias = shop_country_key(:alias_weg) AND :land_weg = ''
             RETURNING alias
         ), gesetzt AS (
             INSERT INTO country_alias_shop (alias, iso_code, manual)
             SELECT shop_country_key(:alias), c.iso_code, true
               FROM country_shop c
              WHERE c.iso_code = :land
             ON CONFLICT (alias) DO UPDATE SET iso_code = EXCLUDED.iso_code, manual = true
             RETURNING alias, iso_code
         )
         SELECT (SELECT count(*) FROM geloescht) AS geloescht,
                (SELECT iso_code FROM gesetzt) AS iso_code",
        [':alias' => $alias, ':alias_weg' => $alias, ':land' => $land, ':land_weg' => $land]
    );

    if ('' !== $land && empty($zeile['iso_code'])) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Unbekannter Ländercode: '.$land);
        return;
    }

    resultInfo(true, '', ['alias' => $alias, 'iso_code' => $zeile['iso_code'] ?: null]);
}

/**
 * Versandarten, Zonen und Preise für die Ansicht „Versandarten“
 *
 * dev/shop-versand.md, Schritt 4. Alles, was die Karte „Versandarten" braucht,
 * in einer Abfrage: Versandarten mit ihren Preisstufen und ihrem
 * Versandartikel, Zonen mit ihren Ländern, die Kanäle, die Lieferanten als
 * mögliche Anbieter, die Länderliste, die Gewichtseinheit der Artikel, ob die
 * Preise brutto gelten, die Buchungsgruppen und die nächste freie
 * Dienstleistungsnummer als Vorschlag für einen neuen Versandartikel.
 *
 * part_used: der Versandartikel steht auf einer Rechnung — seine Nummer
 * lässt sich dann nicht mehr ändern.
 *
 * @return void
 * @testdata {}
 */
function getShopShipping($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $zeile = $db->getOne(
        "SELECT
             (SELECT COALESCE(json_agg(json_build_object(
                         'id', m.id, 'description', m.description, 'vendor_id', m.vendor_id,
                         'parts_id', m.parts_id, 'partnumber', p.partnumber, 'part_description', p.description,
                         'part_buchungsgruppen_id', p.buchungsgruppen_id, 'part_type', p.part_type,
                         'part_used', EXISTS (SELECT 1 FROM invoice i WHERE i.parts_id = p.id),
                         'rank', m.rank, 'max_weight', m.max_weight, 'max_length', m.max_length,
                         'max_girth', m.max_girth, 'free_shipping_applies', m.free_shipping_applies,
                         'ebay_fulfillment_policy_id', m.ebay_fulfillment_policy_id, 'active', m.active,
                         'parts', (SELECT count(*) FROM parts_shipping_shop ps WHERE ps.shipping_method_id = m.id),
                         'rates', (SELECT COALESCE(json_agg(json_build_object(
                                          'channel_id', r.channel_id, 'zone_id', r.zone_id,
                                          'weight_from', r.weight_from, 'qty_from', r.qty_from, 'price', r.price)
                                          ORDER BY r.channel_id NULLS FIRST, r.zone_id NULLS FIRST,
                                                   r.weight_from, r.qty_from), '[]')
                                     FROM shipping_rate_shop r WHERE r.shipping_method_id = m.id)
                     ) ORDER BY m.rank DESC, m.description), '[]')
                FROM shipping_method_shop m
                JOIN parts p ON p.id = m.parts_id) AS versandarten,
             (SELECT COALESCE(json_agg(json_build_object(
                         'id', z.id, 'description', z.description, 'sortkey', z.sortkey,
                         'countries', (SELECT COALESCE(json_agg(zc.iso_code ORDER BY zc.iso_code), '[]')
                                         FROM shipping_zone_country_shop zc WHERE zc.zone_id = z.id)
                     ) ORDER BY z.sortkey, z.description), '[]')
                FROM shipping_zone_shop z) AS zonen,
             (SELECT COALESCE(json_agg(json_build_object('channel_id', c.id, 'type', c.type, 'name', c.name, 'active', c.active)
                                       ORDER BY c.sortkey NULLS LAST, c.id), '[]')
                FROM sales_channel_shop c) AS kanaele,
             (SELECT COALESCE(json_agg(json_build_object('id', v.id, 'name', v.name, 'vendornumber', v.vendornumber)
                                       ORDER BY v.name), '[]')
                FROM vendor v
               WHERE NOT COALESCE(v.obsolete, false)
                  OR v.id IN (SELECT vendor_id FROM shipping_method_shop)) AS anbieter,
             (SELECT COALESCE(json_agg(c.iso_code ORDER BY c.iso_code), '[]') FROM country_shop c) AS laender,
             (SELECT weightunit FROM defaults LIMIT 1) AS gewichtseinheit,
             COALESCE((SELECT lower(trim(value)) IN ('t', 'true', '1', 'y', 'yes')
                         FROM defaults_oserp WHERE key = 'shop_tax_included'), false) AS brutto,
             (SELECT COALESCE(json_agg(json_build_object('id', b.id, 'description', b.description)
                                       ORDER BY b.sortkey, b.id), '[]')
                FROM buchungsgruppen b) AS buchungsgruppen,
             -- Nächste freie Dienstleistungsnummer, ohne den Zähler zu
             -- verbrauchen (wie peekNextPartnumber). Ein Nummernkreis mit
             -- Buchstaben lässt keinen Vorschlag zu — dann leer statt Fehler
             (WITH RECURSIVE c(n) AS (
                  SELECT COALESCE(NULLIF(btrim(servicenumber), ''), '0')::BIGINT + 1
                    FROM defaults
                   WHERE COALESCE(NULLIF(btrim(servicenumber), ''), '0') ~ '^[0-9]+$'
                  UNION ALL
                  SELECT n + 1 FROM c WHERE EXISTS (SELECT 1 FROM parts WHERE partnumber = c.n::TEXT)
              )
              SELECT MAX(n)::TEXT FROM c) AS naechste_nummer"
    );

    resultInfo(true, '', [
        'methods'    => json_decode((string)$zeile['versandarten'], true),
        'buchungsgruppen' => json_decode((string)$zeile['buchungsgruppen'], true),
        'next_partnumber' => (string)($zeile['naechste_nummer'] ?? ''),
        'zones'      => json_decode((string)$zeile['zonen'], true),
        'channels'   => json_decode((string)$zeile['kanaele'], true),
        'vendors'    => json_decode((string)$zeile['anbieter'], true),
        'countries'  => json_decode((string)$zeile['laender'], true),
        'weightunit' => (string)($zeile['gewichtseinheit'] ?? ''),
        // Preise netto oder brutto wie parts.sellprice — für die eigene
        // Ansicht „Versandarten“, die kein Formular mit shop_tax_included hat
        'tax_included' => in_array($zeile['brutto'] ?? false, [true, 't', 'true', 1, '1'], true),
    ]);
}

/**
 * Legt eine Versandart an oder ändert sie, samt ihren Preisstufen
 *
 * Die Versandart verwaltet ihren Versandartikel (2026-10-02): eine
 * Dienstleistung — nie im Lager, nie im Shop angeboten — mit Nummer,
 * Bezeichnung und Buchungsgruppe aus der Maske. Die Bezeichnung steht auf der
 * Rechnung, die Buchungsgruppe bestimmt Erlöskonto und Steuer. Je Versandart
 * ein eigener Artikel (Index shipping_method_shop_parts_id_key).
 *
 *   neu        Nummer vorgeschlagen (part.suggested) oder leer: die nächste
 *              freie aus dem Nummernkreis servicenumber — ist der Vorschlag
 *              inzwischen vergeben, die danach. Sonst die eingegebene, wenn
 *              sie frei ist. Einheit Stck, Verkaufspreis 0 (der Preis kommt
 *              aus den Preisstufen).
 *   vorhanden  Bezeichnung und Buchungsgruppe ändern sich; die Nummer nur,
 *              solange der Artikel auf keiner Rechnung steht. Ist er noch
 *              keine Dienstleistung, wird er umgestellt — nicht bei
 *              Lagerbuchungen oder in einer Stückliste.
 *
 * Danach wird der Artikel in keinem Kanal mehr angeboten: Kanalzeilen aus,
 * Produktseite im HugoShop entfernt, Marktplätze beenden ihr Angebot
 * (Trigger).
 *
 * Eine Transaktion: Artikel, Versandart mit Preisstufen, Kanalzeilen. Die
 * Preisstufen werden ersetzt: gleiche Stufen (Kanal, Zone, ab Gewicht, ab
 * Stückzahl) bekommen den neuen Preis, neue kommen hinzu, nicht mehr genannte
 * entfallen. Löschen und Neuanlegen derselben Stufe in einer Anweisung lehnt
 * PostgreSQL ab (eindeutiger Schlüssel), deshalb dieser Weg.
 *
 * @param int $data['id'] Versandart, 0 = neu
 * @param string $data['description'] Bezeichnung
 * @param int|null $data['vendor_id'] Anbieter (Lieferant), leer = allgemein
 * @param array $data['part'] Versandartikel: partnumber, description,
 *              buchungsgruppen_id, suggested (Nummer ist der Vorschlag)
 * @param int $data['rank'] Rang (höher gewinnt)
 * @param float|null $data['max_weight'] Höchstgewicht, leer = ohne
 * @param float|null $data['max_length'] längste Kante in cm, leer = ohne
 * @param float|null $data['max_girth'] Gurtmaß in cm, leer = ohne
 * @param bool $data['free_shipping_applies'] Freigrenze gilt
 * @param string $data['ebay_fulfillment_policy_id'] eBay-Versandrichtlinie, leer = die allgemeine
 * @param bool $data['active'] aktiv
 * @param array $data['rates'] Liste aus channel_id, zone_id (leer = alle), weight_from, qty_from, price
 * @testdata {"id": 0, "description": "DHL Paket", "vendor_id": null, "part": {"partnumber": "", "description": "Versand", "buchungsgruppen_id": 1, "suggested": true}, "rank": 10, "max_weight": 31.5, "max_length": 120, "max_girth": 300, "free_shipping_applies": true, "ebay_fulfillment_policy_id": "", "active": true, "rates": [{"channel_id": null, "zone_id": null, "weight_from": 0, "qty_from": 0, "price": 5.99}]}
 */
function saveShopShippingMethod($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $zahl = function ($wert) {
        if (null === $wert || '' === trim((string)$wert)) {
            return null;
        }
        return is_numeric($wert) ? (float)$wert : false;
    };

    $bezeichnung = trim((string)($data['description'] ?? ''));
    $artikel = [
        'partnumber'         => trim((string)($data['part']['partnumber'] ?? '')),
        'description'        => trim((string)($data['part']['description'] ?? '')),
        'buchungsgruppen_id' => (int)($data['part']['buchungsgruppen_id'] ?? 0),
        'suggested'          => !empty($data['part']['suggested']),
    ];
    if ('' === $bezeichnung) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Die Versandart braucht eine Bezeichnung');
        return;
    }
    if ('' === $artikel['description'] || $artikel['buchungsgruppen_id'] <= 0
        || ('' === $artikel['partnumber'] && !$artikel['suggested'])) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Der Versandartikel braucht Artikelnummer, Bezeichnung und Buchungsgruppe');
        return;
    }
    $grenzen = [];
    foreach (['max_weight', 'max_length', 'max_girth'] as $feld) {
        $grenzen[$feld] = $zahl($data[$feld] ?? null);
        if (false === $grenzen[$feld] || (null !== $grenzen[$feld] && $grenzen[$feld] <= 0)) {
            resultInfo(false, 'VALIDATION_ERROR', null, 'Grenzen müssen leer oder größer als 0 sein');
            return;
        }
    }

    // Preisstufen prüfen und vereinheitlichen; doppelte Stufen meldet die
    // Datenbank (eindeutiger Schlüssel), hier vorab mit verständlicher Meldung
    $stufen = [];
    $gesehen = [];
    foreach ((array)($data['rates'] ?? []) as $stufe) {
        $gewicht = $zahl($stufe['weight_from'] ?? 0) ?? 0.0;
        $menge   = $zahl($stufe['qty_from'] ?? 0) ?? 0.0;
        $preis   = $zahl($stufe['price'] ?? null);
        if (false === $gewicht || false === $menge || $gewicht < 0 || $menge < 0
            || null === $preis || false === $preis || $preis < 0) {
            resultInfo(false, 'VALIDATION_ERROR', null, 'Jede Preisstufe braucht einen Preis ab 0; Gewicht und Stückzahl ab 0');
            return;
        }
        $kanal = (int)($stufe['channel_id'] ?? 0) ?: null;
        $zone  = (int)($stufe['zone_id'] ?? 0) ?: null;
        $schluessel = implode('|', [$kanal, $zone, $gewicht, $menge]);
        if (isset($gesehen[$schluessel])) {
            resultInfo(false, 'VALIDATION_ERROR', null, 'Eine Preisstufe steht doppelt (gleicher Kanal, gleiche Zone, gleiche Stufe)');
            return;
        }
        $gesehen[$schluessel] = true;
        $stufen[] = ['channel_id' => $kanal, 'zone_id' => $zone, 'weight_from' => $gewicht,
                     'qty_from' => $menge, 'price' => $preis];
    }

    $db->beginTransaction();
    try {
        $teil = shopShippingPartSave($db, (int)($data['id'] ?? 0), $artikel);
        $partsId = $teil['id'];

        $zeile = $db->getOne(
            "WITH methode AS (
                 INSERT INTO shipping_method_shop AS m
                        (id, description, vendor_id, parts_id, rank, max_weight, max_length, max_girth,
                         free_shipping_applies, ebay_fulfillment_policy_id, active)
                 OVERRIDING SYSTEM VALUE
                 SELECT COALESCE(NULLIF(:id, 0), nextval(pg_get_serial_sequence('shipping_method_shop', 'id'))),
                        :description, CAST(NULLIF(:vendor_id, 0) AS integer), :parts_id, :rank,
                        CAST(:max_weight AS numeric), CAST(:max_length AS numeric), CAST(:max_girth AS numeric),
                        :free_shipping_applies, NULLIF(btrim(:ebay_policy), ''), :active
                  WHERE NULLIF(:id_neu, 0) IS NULL
                     OR EXISTS (SELECT 1 FROM shipping_method_shop WHERE id = :id_vorhanden)
                 ON CONFLICT (id) DO UPDATE SET
                        description = EXCLUDED.description, vendor_id = EXCLUDED.vendor_id,
                        parts_id = EXCLUDED.parts_id, rank = EXCLUDED.rank,
                        max_weight = EXCLUDED.max_weight, max_length = EXCLUDED.max_length,
                        max_girth = EXCLUDED.max_girth, free_shipping_applies = EXCLUDED.free_shipping_applies,
                        ebay_fulfillment_policy_id = EXCLUDED.ebay_fulfillment_policy_id,
                        active = EXCLUDED.active, mtime = now()
                 RETURNING m.id
             ), neu AS (
                 SELECT s.*
                   FROM jsonb_to_recordset(CAST(:stufen AS jsonb))
                        AS s(channel_id integer, zone_id integer, weight_from numeric, qty_from numeric, price numeric)
             ), weg AS (
                 DELETE FROM shipping_rate_shop r
                  USING methode
                  WHERE r.shipping_method_id = methode.id
                    AND NOT EXISTS (SELECT 1 FROM neu
                                     WHERE COALESCE(neu.channel_id, 0) = COALESCE(r.channel_id, 0)
                                       AND COALESCE(neu.zone_id, 0)    = COALESCE(r.zone_id, 0)
                                       AND neu.weight_from = r.weight_from
                                       AND neu.qty_from    = r.qty_from)
             ), gesetzt AS (
                 INSERT INTO shipping_rate_shop (shipping_method_id, channel_id, zone_id, weight_from, qty_from, price)
                 SELECT methode.id, neu.channel_id, neu.zone_id, neu.weight_from, neu.qty_from, neu.price
                   FROM methode CROSS JOIN neu
                 ON CONFLICT (shipping_method_id, (COALESCE(channel_id, 0)), (COALESCE(zone_id, 0)), weight_from, qty_from)
                 DO UPDATE SET price = EXCLUDED.price
                 RETURNING 1
             )
             SELECT (SELECT id FROM methode) AS id, (SELECT count(*) FROM gesetzt) AS stufen",
            [
                ':id'                    => (int)($data['id'] ?? 0),
                ':id_neu'                => (int)($data['id'] ?? 0),
                ':id_vorhanden'          => (int)($data['id'] ?? 0),
                ':description'           => $bezeichnung,
                ':vendor_id'             => (int)($data['vendor_id'] ?? 0),
                ':parts_id'              => $partsId,
                ':rank'                  => (int)($data['rank'] ?? 0),
                ':max_weight'            => null === $grenzen['max_weight'] ? null : (string)$grenzen['max_weight'],
                ':max_length'            => null === $grenzen['max_length'] ? null : (string)$grenzen['max_length'],
                ':max_girth'             => null === $grenzen['max_girth'] ? null : (string)$grenzen['max_girth'],
                ':free_shipping_applies' => !empty($data['free_shipping_applies']),
                ':ebay_policy'           => (string)($data['ebay_fulfillment_policy_id'] ?? ''),
                ':active'                => !empty($data['active']),
                ':stufen'                => json_encode($stufen),
            ]
        );
        if (empty($zeile['id'])) {
            throw new ApiError('SHIPPING_METHOD_NOT_FOUND', 'Diese Versandart gibt es nicht');
        }

        $seiten = $teil['seiten'];
        $db->commit();
    } catch (ApiError $e) {
        $db->rollBack();
        resultInfo(false, $e->getId(), null, $e->getMessage());
        return;
    } catch (PDOException $e) {
        $db->rollBack();
        // Eindeutig: der Artikel gehört schon einer anderen Versandart;
        // Fremdschlüssel: Buchungsgruppe, Anbieter, Kanal oder Zone fehlt
        $meldung = [
            '23505' => 'Der Versandartikel gehört schon zu einer anderen Versandart',
            '23503' => 'Buchungsgruppe, Anbieter, Kanal oder Zone gibt es nicht (mehr)',
        ][$e->getCode()] ?? $e->getMessage();
        resultInfo(false, 'VALIDATION_ERROR', null, $meldung);
        return;
    }

    // Produktseiten des Artikels entfernen — er wird nicht mehr angeboten
    foreach ($seiten as $seite) {
        shopQueueRemovePage($db, (int)$seite['channel_id'], (string)$seite['partnumber'],
                            (string)($seite['hugoshop_hyperlink'] ?? ''));
    }

    resultInfo(true, '', ['id' => (int)$zeile['id'], 'parts_id' => $partsId, 'partnumber' => $teil['partnumber']]);
}

/**
 * Legt den Versandartikel einer Versandart an oder ändert ihn
 *
 * Teil von saveShopShippingMethod, in dessen Transaktion. Regeln dort.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $methodeId Versandart, 0 = neu
 * @param array $artikel partnumber, description, buchungsgruppen_id, suggested
 * @return array id, partnumber, seiten (HugoShop-Seiten zum Entfernen, aus
 *               shopShippingPartWithdraw)
 * @throws ApiError SHIPPING_METHOD_NOT_FOUND, VALIDATION_ERROR
 */
function shopShippingPartSave($db, int $methodeId, array $artikel): array {
    $werte = [
        ':description'        => $artikel['description'],
        ':buchungsgruppen_id' => $artikel['buchungsgruppen_id'],
    ];

    if ($methodeId > 0) {
        $vorhanden = $db->getOne(
            "SELECT p.id, p.partnumber, p.part_type,
                    EXISTS (SELECT 1 FROM invoice i WHERE i.parts_id = p.id) AS benutzt,
                    EXISTS (SELECT 1 FROM inventory iv WHERE iv.parts_id = p.id)
                        OR EXISTS (SELECT 1 FROM assembly a WHERE a.parts_id = p.id OR a.id = p.id) AS gebunden
               FROM shipping_method_shop m
               JOIN parts p ON p.id = m.parts_id
              WHERE m.id = :id
                FOR UPDATE OF m",
            [':id' => $methodeId]
        );
        if (!$vorhanden) {
            throw new ApiError('SHIPPING_METHOD_NOT_FOUND', 'Diese Versandart gibt es nicht');
        }
        $wahr = fn($wert) => in_array($wert, [true, 't', 1, '1'], true);

        $nummer = '' === $artikel['partnumber'] ? (string)$vorhanden['partnumber'] : $artikel['partnumber'];
        if ($nummer !== (string)$vorhanden['partnumber'] && $wahr($vorhanden['benutzt'])) {
            throw new ApiError('VALIDATION_ERROR', 'Die Artikelnummer lässt sich nicht mehr ändern: der Versandartikel steht auf Rechnungen');
        }
        if ('service' !== $vorhanden['part_type'] && $wahr($vorhanden['gebunden'])) {
            throw new ApiError('VALIDATION_ERROR', 'Der Versandartikel hat Lagerbuchungen oder gehört zu einer Stückliste — als Dienstleistung nicht umstellbar');
        }

        // Erst aus den Kanälen nehmen, dann ändern: sonst stieße eine neue
        // Bezeichnung noch eine Veröffentlichung an, und die Seite trüge den
        // alten Namen
        $seiten = shopShippingPartWithdraw($db, (int)$vorhanden['id']);

        // Ausgemustert war er nur als Kniff (Bridge): er wird gebraucht
        $zeile = $db->getOne(
            "UPDATE parts
                SET partnumber = :partnumber, description = :description,
                    buchungsgruppen_id = :buchungsgruppen_id, part_type = 'service',
                    obsolete = false, mtime = now()
              WHERE id = :id
                AND NOT EXISTS (SELECT 1 FROM parts x WHERE x.partnumber = :partnumber_pruefung AND x.id <> :id_pruefung)
             RETURNING id, partnumber",
            $werte + [
                ':partnumber' => $nummer, ':partnumber_pruefung' => $nummer,
                ':id' => (int)$vorhanden['id'], ':id_pruefung' => (int)$vorhanden['id'],
            ]
        );
    } else {
        $seiten = [];
        // Vorgeschlagen: die nächste freie Nummer — zählt den Nummernkreis
        // hoch und überspringt Vergebenes, auch wenn der Vorschlag inzwischen
        // weg ist
        $nummer = $artikel['suggested'] || '' === $artikel['partnumber']
            ? nextFreeNumber($db, 'servicenumber', 'parts', 'partnumber')
            : $artikel['partnumber'];

        $zeile = $db->getOne(
            "INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, notes, obsolete)
             SELECT :partnumber, :description, 'service', :buchungsgruppen_id, 0, 'Stck', '', false
              WHERE NOT EXISTS (SELECT 1 FROM parts WHERE partnumber = :partnumber_pruefung)
             RETURNING id, partnumber",
            $werte + [':partnumber' => $nummer, ':partnumber_pruefung' => $nummer]
        );
    }

    if (!$zeile) {
        throw new ApiError('VALIDATION_ERROR', 'Die Artikelnummer '.$nummer.' ist bereits vergeben');
    }
    return ['id' => (int)$zeile['id'], 'partnumber' => (string)$zeile['partnumber'], 'seiten' => $seiten];
}

/**
 * Nimmt einen Versandartikel aus allen Kanälen
 *
 * Kanalzeilen aus; bei Marktplätzen beendet der Trigger das Angebot. Für den
 * HugoShop kommen die Seiten zurück, die der Aufrufer entfernen lässt
 * (shopQueueRemovePage) — nach der Transaktion.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Versandartikel
 * @return array channel_id, partnumber, hugoshop_hyperlink je HugoShop
 */
function shopShippingPartWithdraw($db, int $partsId): array {
    return $db->getAll(
        "WITH aus AS (
             UPDATE parts_channel_shop pc
                SET active = false, mtime = now()
              WHERE pc.parts_id = :parts_id AND pc.active
             RETURNING pc.channel_id, pc.parts_id
         )
         SELECT aus.channel_id, p.partnumber, pe.hugoshop_hyperlink
           FROM aus
           JOIN sales_channel_shop c ON c.id = aus.channel_id AND c.type = 'hugoshop'
           JOIN parts p ON p.id = aus.parts_id
           LEFT JOIN parts_ext pe ON pe.parts_id = p.id",
        [':parts_id' => $partsId]
    ) ?: [];
}

/**
 * Löscht eine Versandart
 *
 * Ihre Preisstufen gehen mit; Artikel, denen sie zugeordnet war, bekommen
 * wieder die günstigste passende (parts_shipping_shop, ON DELETE SET NULL).
 * Der Versandartikel bleibt — Rechnungen verweisen auf ihn —, wird aber
 * ausgemustert und ist damit kein Versandartikel mehr.
 *
 * @param int $data['id'] Versandart
 * @testdata {"id": 1}
 */
function deleteShopShippingMethod($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $zeile = $db->getOne(
        "WITH weg AS (
             DELETE FROM shipping_method_shop WHERE id = :id RETURNING id, parts_id
         ), ausgemustert AS (
             UPDATE parts p SET obsolete = true, mtime = now()
               FROM weg
              WHERE p.id = weg.parts_id
             RETURNING p.id
         )
         SELECT id FROM weg",
        [':id' => (int)($data['id'] ?? 0)]
    );
    if (!$zeile) {
        resultInfo(false, 'SHIPPING_METHOD_NOT_FOUND', null, 'Diese Versandart gibt es nicht');
        return;
    }
    resultInfo(true, '', ['id' => (int)$zeile['id']]);
}

/**
 * Legt eine Versandzone an oder ändert sie, samt ihren Ländern
 *
 * Ein Land gehört höchstens zu einer Zone: steht es schon in einer anderen,
 * wechselt es hierher. Länder, die nicht mehr genannt sind, verlassen die
 * Zone.
 *
 * @param int $data['id'] Zone, 0 = neu
 * @param string $data['description'] Bezeichnung
 * @param int $data['sortkey'] Reihenfolge
 * @param array $data['countries'] ISO-Codes
 * @testdata {"id": 0, "description": "EU", "sortkey": 1, "countries": ["AT", "FR", "NL"]}
 */
function saveShopShippingZone($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $bezeichnung = trim((string)($data['description'] ?? ''));
    if ('' === $bezeichnung) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Die Zone braucht eine Bezeichnung');
        return;
    }
    $laender = array_values(array_unique(array_filter(
        array_map(fn($code) => strtoupper(trim((string)$code)), (array)($data['countries'] ?? [])),
        fn($code) => 1 === preg_match('/^[A-Z]{2}$/', $code)
    )));

    $zeile = $db->getOne(
        "WITH zone AS (
             INSERT INTO shipping_zone_shop AS z (id, description, sortkey)
             OVERRIDING SYSTEM VALUE
             SELECT COALESCE(NULLIF(:id, 0), nextval(pg_get_serial_sequence('shipping_zone_shop', 'id'))),
                    :description, :sortkey
              WHERE NULLIF(:id_neu, 0) IS NULL
                 OR EXISTS (SELECT 1 FROM shipping_zone_shop WHERE id = :id_vorhanden)
             ON CONFLICT (id) DO UPDATE SET description = EXCLUDED.description, sortkey = EXCLUDED.sortkey
             RETURNING z.id
         ), neu AS (
             SELECT code FROM unnest(CAST(:laender AS text[])) AS code
              WHERE code IN (SELECT iso_code FROM country_shop)
         ), weg AS (
             DELETE FROM shipping_zone_country_shop zc
              USING zone
              WHERE zc.zone_id = zone.id AND zc.iso_code NOT IN (SELECT code FROM neu)
         ), gesetzt AS (
             INSERT INTO shipping_zone_country_shop (iso_code, zone_id)
             SELECT neu.code, zone.id FROM neu CROSS JOIN zone
             ON CONFLICT (iso_code) DO UPDATE SET zone_id = EXCLUDED.zone_id
             RETURNING 1
         )
         SELECT (SELECT id FROM zone) AS id, (SELECT count(*) FROM gesetzt) AS laender",
        [
            ':id'           => (int)($data['id'] ?? 0),
            ':id_neu'       => (int)($data['id'] ?? 0),
            ':id_vorhanden' => (int)($data['id'] ?? 0),
            ':description'  => $bezeichnung,
            ':sortkey'      => (int)($data['sortkey'] ?? 0),
            ':laender'      => '{'.implode(',', $laender).'}',
        ]
    );

    if (empty($zeile['id'])) {
        resultInfo(false, 'SHIPPING_ZONE_NOT_FOUND', null, 'Diese Zone gibt es nicht');
        return;
    }
    resultInfo(true, '', ['id' => (int)$zeile['id'], 'countries' => (int)$zeile['laender']]);
}

/**
 * Löscht eine Versandzone
 *
 * Ihre Länder und die Preisstufen dieser Zone gehen mit.
 *
 * @param int $data['id'] Zone
 * @testdata {"id": 1}
 */
function deleteShopShippingZone($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $zeile = $db->getOne(
        "DELETE FROM shipping_zone_shop WHERE id = :id RETURNING id",
        [':id' => (int)($data['id'] ?? 0)]
    );
    if (!$zeile) {
        resultInfo(false, 'SHIPPING_ZONE_NOT_FOUND', null, 'Diese Zone gibt es nicht');
        return;
    }
    resultInfo(true, '', ['id' => (int)$zeile['id']]);
}

/**
 * Verkaufskanäle mit ihren Vorgaben für die Firmenkonfiguration
 *
 * Alle eingerichteten Kanäle, auch abgeschaltete, mit der Zahl der Artikel,
 * die dort angeboten werden. tax_included sagt der Oberfläche, ob ein fester
 * Aufschlag netto oder brutto gemeint ist.
 *
 * @return void
 * @testdata {}
 */
function getShopChannels($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', [
        'channels' => $db->getAll(
            "SELECT c.id AS channel_id, c.type, c.name, c.active, c.sortkey, c.auto_add_parts,
                    c.markup_type, c.markup_value, c.round_99, c.free_shipping_from,
                    COALESCE(c.settings, '{}'::jsonb) AS settings,
                    -- Geheimnisse: nur ob hinterlegt, nie der Wert
                    (SELECT COALESCE(json_object_agg(s.key, true), '{}')
                       FROM sales_channel_secret_shop s
                      WHERE s.channel_id = c.id AND s.value <> '') AS secrets_set,
                    -- Löschen (M5): nur abgeschaltet, ohne offene Aufträge und
                    -- ohne Belege (Rechnungslinks, Widerrufe, eBay-Bestellungen)
                    (NOT c.active
                     AND NOT EXISTS (SELECT 1 FROM batchjob_hugoshop b WHERE b.channel_id = c.id AND b.result IS NULL)
                     AND NOT EXISTS (SELECT 1 FROM ar_link_hugoshop a WHERE a.channel_id = c.id)
                     AND NOT EXISTS (SELECT 1 FROM withdrawals_hugoshop w WHERE w.channel_id = c.id)
                     AND NOT EXISTS (SELECT 1 FROM ebay_orders e WHERE e.channel_id = c.id)) AS deletable,
                    -- Lieferländer (dev/shop-versand.md, Schritt 7); leer = alle
                    (SELECT COALESCE(json_agg(cc.iso_code ORDER BY cc.iso_code), '[]')
                       FROM sales_channel_country_shop cc WHERE cc.channel_id = c.id) AS countries,
                    (SELECT COUNT(*) FROM parts_channel_shop pc
                      WHERE pc.channel_id = c.id AND pc.active) AS parts
               FROM sales_channel_shop c
              ORDER BY c.sortkey NULLS LAST, c.id"
        ),
        'tax_included' => shopConfigBool($db, 'shop_tax_included'),
        // Arten, von denen sich Kanäle anlegen lassen (channels/channels.php)
        'types'        => SHOP_CHANNEL_TYPES,
        // Auswahllisten: Länder für die Lieferländer, Lieferbedingungen für
        // den Ausschluss langer Lieferzeiten bei eBay (W11)
        'all_countries'  => array_column($db->getAll("SELECT iso_code FROM country_shop ORDER BY iso_code"), 'iso_code'),
        'delivery_terms' => $db->getAll(
            "SELECT id, description, description_long, obsolete FROM delivery_terms ORDER BY sortkey, id"
        ),
    ]);
}

/**
 * Speichert die Vorgaben eines Verkaufskanals
 *
 * Aufschlag und Rundung gelten für alle Artikel des Kanals ohne eigenen
 * Aufschlag. Mindestens ein Kanal bleibt eingeschaltet (V8): der letzte
 * eingeschaltete lässt sich nicht abschalten — solange es nur den HugoShop
 * gibt, ist das er. Die Antwort nennt den Stand, der tatsächlich gilt.
 *
 * Ändert sich beim HugoShop, was den Preis bestimmt, entsteht der Auftrag,
 * alle Produktseiten neu zu schreiben (V9) — sonst zeigten die Seiten den
 * alten Preis, während Warenkorb und Rechnung schon den neuen rechnen. Das
 * lässt sich mit shop_auto_publish abschalten (V22).
 *
 * @param array $data['channel_id'] Kanal
 * @param array $data['active'] Kanal eingeschaltet (beim letzten eingeschalteten ohne Wirkung)
 * @param array $data['markup_type'] none, percent oder amount
 * @param array $data['markup_value'] Prozent oder Betrag (netto/brutto wie parts.sellprice)
 * @param array $data['round_99'] Bruttopreis auf ,99 aufrunden
 * @param float|null $data['free_shipping_from'] Versandfrei ab diesem Bruttowarenwert, leer = keine Freigrenze, fehlt = unverändert
 * @param array $data['countries'] Lieferländer (ISO-Codes), leer = alle; fehlt = unverändert (Schritt 7)
 * @param array $data['excluded_delivery_terms'] eBay: Lieferbedingungen, bei denen nicht angeboten
 *              wird (W11); fehlt = unverändert
 * @param string $data['name'] Name des Kanals, eindeutig; fehlt = unverändert
 * @param bool $data['auto_add_parts'] neue Shop-Artikel automatisch aufnehmen (M3); fehlt = unverändert
 * @return void
 * @testdata {"channel_id": 1, "active": true, "markup_type": "percent", "markup_value": 10, "round_99": true, "free_shipping_from": 150, "name": "HugoShop"}
 */
function saveShopChannel($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $art = (string)($data['markup_type'] ?? 'none');
    if (!in_array($art, ['none', 'percent', 'amount'], true)) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Unbekannte Aufschlagsart: '.$art);
        return;
    }
    $wert = 'none' === $art ? 0.0 : (float)($data['markup_value'] ?? 0);

    // Freigrenze: leer = keine (dev/shop-versand.md, Entscheidung 4). Fehlt
    // das Feld ganz, bleibt sie, wie sie ist — sonst löschte ein Aufruf ohne
    // das Feld die Freigrenze.
    $freigrenzeSetzen = array_key_exists('free_shipping_from', $data);
    $freigrenze = $data['free_shipping_from'] ?? null;
    $freigrenze = (null === $freigrenze || '' === trim((string)$freigrenze)) ? null : (float)$freigrenze;
    if (null !== $freigrenze && $freigrenze < 0) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Die Freigrenze kann nicht negativ sein');
        return;
    }
    if ('percent' === $art && $wert <= -100) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Ein Abschlag von 100 % oder mehr ergibt keinen Preis');
        return;
    }

    // Lieferländer und ausgeschlossene Lieferbedingungen: nur, wenn mitgeschickt
    $laenderSetzen = isset($data['countries']) && is_array($data['countries']);
    $laender = $laenderSetzen ? array_values(array_unique(array_filter(
        array_map(fn($code) => strtoupper(trim((string)$code)), $data['countries']),
        fn($code) => 1 === preg_match('/^[A-Z]{2}$/', $code)))) : [];
    $ausschlussSetzen = isset($data['excluded_delivery_terms']) && is_array($data['excluded_delivery_terms']);
    $ausschluss = $ausschlussSetzen
        ? array_values(array_unique(array_filter(array_map('intval', $data['excluded_delivery_terms']), fn($id) => $id > 0)))
        : [];
    sort($ausschluss);

    // Name und automatische Aufnahme: nur, wenn mitgeschickt
    $nameSetzen = array_key_exists('name', $data);
    $name = trim((string)($data['name'] ?? ''));
    if ($nameSetzen && '' === $name) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Der Kanal braucht einen Namen');
        return;
    }
    $aufnahmeSetzen = array_key_exists('auto_add_parts', $data);

    // vorher liest den Stand vor der Änderung: daraus ergibt sich, ob sich
    // der Preis der HugoShop-Seiten ändert.
    try {
        $zeile = $db->getOne(
            "WITH vorher AS (
                 SELECT id, active, markup_type, markup_value, round_99,
                        COALESCE(settings -> 'excluded_delivery_terms', '[]'::jsonb) AS ausschluss
                   FROM sales_channel_shop WHERE id = :channel_id
             ), laender_weg AS (
                 DELETE FROM sales_channel_country_shop
                  WHERE channel_id = :channel_id_laender_weg AND :laender_setzen_weg = 1
                    AND iso_code <> ALL(CAST(:laender_weg AS text[]))
             ), laender_neu AS (
                 INSERT INTO sales_channel_country_shop (channel_id, iso_code)
                 SELECT :channel_id_laender_neu, c.iso_code
                   FROM country_shop c
                  WHERE :laender_setzen_neu = 1 AND c.iso_code = ANY(CAST(:laender_neu AS text[]))
                    AND EXISTS (SELECT 1 FROM sales_channel_shop WHERE id = :channel_id_laender_pruefen)
                 ON CONFLICT DO NOTHING
             ), geaendert AS (
                 UPDATE sales_channel_shop
                    SET active       = :active OR NOT EXISTS (
                                           SELECT 1 FROM sales_channel_shop andere
                                            WHERE andere.id <> :channel_id_andere AND andere.active),
                        markup_type  = :markup_type,
                        markup_value = :markup_value,
                        round_99     = :round_99,
                        free_shipping_from = CASE WHEN :freigrenze_setzen = 1
                                                  THEN CAST(:free_shipping_from AS numeric)
                                                  ELSE free_shipping_from END,
                        settings     = CASE WHEN :ausschluss_setzen = 1
                                            THEN COALESCE(settings, '{}'::jsonb)
                                                 || jsonb_build_object('excluded_delivery_terms', CAST(:ausschluss AS jsonb))
                                            ELSE settings END,
                        name         = CASE WHEN :name_setzen = 1 THEN CAST(:name AS text) ELSE name END,
                        auto_add_parts = CASE WHEN :aufnahme_setzen = 1 THEN CAST(:auto_add_parts AS boolean)
                                              ELSE auto_add_parts END,
                        mtime        = now()
                  WHERE id = :channel_id
                 RETURNING id, type, active, markup_type, markup_value, round_99,
                           COALESCE(settings -> 'excluded_delivery_terms', '[]'::jsonb) AS ausschluss
             )
             SELECT g.type, g.active,
                    (g.active IS DISTINCT FROM v.active) AS geschaltet,
                    (g.markup_type IS DISTINCT FROM v.markup_type
                     OR g.markup_value IS DISTINCT FROM v.markup_value
                     OR g.round_99 IS DISTINCT FROM v.round_99) AS preis_geaendert,
                    (g.ausschluss IS DISTINCT FROM v.ausschluss) AS ausschluss_geaendert
               FROM geaendert g JOIN vorher v ON v.id = g.id",
            [
                ':channel_id'   => (int)($data['channel_id'] ?? 0),
                ':channel_id_andere' => (int)($data['channel_id'] ?? 0),
                ':active'       => !empty($data['active']),
                ':markup_type'  => $art,
                ':markup_value' => $wert,
                ':round_99'     => !empty($data['round_99']),
                ':free_shipping_from' => null === $freigrenze ? null : (string)$freigrenze,
                ':freigrenze_setzen'  => $freigrenzeSetzen ? 1 : 0,
                ':channel_id_laender_weg'    => (int)($data['channel_id'] ?? 0),
                ':channel_id_laender_neu'    => (int)($data['channel_id'] ?? 0),
                ':channel_id_laender_pruefen' => (int)($data['channel_id'] ?? 0),
                ':laender_setzen_weg' => $laenderSetzen ? 1 : 0,
                ':laender_setzen_neu' => $laenderSetzen ? 1 : 0,
                ':laender_weg'        => '{'.implode(',', $laender).'}',
                ':laender_neu'        => '{'.implode(',', $laender).'}',
                ':ausschluss_setzen'  => $ausschlussSetzen ? 1 : 0,
                ':ausschluss'         => json_encode($ausschluss),
                ':name_setzen'        => $nameSetzen ? 1 : 0,
                ':name'               => $name,
                ':aufnahme_setzen'    => $aufnahmeSetzen ? 1 : 0,
                ':auto_add_parts'     => !empty($data['auto_add_parts']) ? 'true' : 'false',
            ]
        );
    } catch (PDOException $e) {
        // Der Name ist eindeutig (sales_channel_shop_name_key)
        if ('23505' === $e->getCode()) {
            // Text in der Oberfläche (ShopView.errors.CHANNEL_NAME_TAKEN)
            resultInfo(false, 'CHANNEL_NAME_TAKEN', null, null);
            return;
        }
        throw $e;
    }

    if (!$zeile) {
        resultInfo(false, 'CHANNEL_NOT_FOUND', null, 'Diesen Verkaufskanal gibt es nicht');
        return;
    }

    $wahr = fn($wert) => in_array($wert, [true, 't', 1, '1'], true);
    $an = $wahr($zeile['active']);

    // Ein- oder ausgeschaltet: das Modul des Kanals entscheidet, was folgt —
    // beim HugoShop Seiten entfernen oder alle neu schreiben (V16). Sonst bei
    // geändertem Preis die Seiten des eingeschalteten HugoShops (V9).
    $kanal = (int)($data['channel_id'] ?? 0);
    if ($wahr($zeile['geschaltet'])) {
        shopChannelSwitched($db, (string)$zeile['type'], $kanal, $an);
        $neuVeroeffentlichen = 'hugoshop' === $zeile['type'] && $an;
        $auftrag = 0;
    } else {
        $neuVeroeffentlichen = 'hugoshop' === $zeile['type'] && $an && $wahr($zeile['preis_geaendert'])
            && shopChannelBool($db, $kanal, 'auto_publish', true);
        $auftrag = $neuVeroeffentlichen ? shopQueueJob($db, 'publish_all', '', null, $kanal) : 0;

        // eBay: andere Lieferbedingungen ausgeschlossen (W11) — alle Angebote
        // neu abgleichen; betroffene werden beendet oder wieder eingestellt
        if ('ebay' === $zeile['type'] && $an && $wahr($zeile['ausschluss_geaendert'])) {
            shopQueueJob($db, 'publish_all', '', null, $kanal);
        }
    }

    resultInfo(true, 'CHANNEL_SAVED', [
        'republish' => $neuVeroeffentlichen,
        'job_id'    => $auftrag,
        'active'    => $an,
    ]);
}

/**
 * Speichert die Einstellungen einer Instanz (Kanalkarte, dev/shop-mehrere-kanaele.md)
 *
 * Angenommen werden nur die Schlüssel, die shop_channel_setting_keys() für die
 * Art des Kanals nennt — nicht geheime nach settings, geheime nach
 * sales_channel_secret_shop. Ein leer geschicktes Geheimnis bleibt, wie es
 * ist: die Oberfläche bekommt Geheimnisse nie zu sehen und schickt nur, was
 * neu eingegeben wurde. Ein Vorgang; die Antwort nennt, welche Geheimnisse
 * danach hinterlegt sind.
 *
 * @param int $data['channel_id'] Kanal
 * @param array $data['settings'] Schlüssel ohne Präfix => Wert, etwa base_url
 * @param array $data['secrets'] Geheimnisse => neuer Wert, etwa public_key
 * @return void secrets_set (Schlüssel => true)
 * @testdata {"channel_id": 1, "settings": {"base_url": "https://shop.example"}, "secrets": {}}
 */
function saveShopChannelSettings($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $objekt = fn($wert) => json_encode(
        array_map(fn($v) => is_bool($v) ? ($v ? '1' : '0') : (string)($v ?? ''), is_array($wert) ? $wert : []),
        JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE
    );

    try {
        $zeile = $db->getOne(
            "WITH kanal AS (
                 SELECT id, type FROM sales_channel_shop WHERE id = CAST(:kanal AS integer)
             ), erlaubt AS (
                 SELECT k.key, k.secret FROM shop_channel_setting_keys() k JOIN kanal ON kanal.type = k.type
             ), werte AS (
                 SELECT e.key, e.value
                   FROM jsonb_each_text(CAST(:settings AS jsonb)) e
                   JOIN erlaubt a ON a.key = e.key AND NOT a.secret
             ), geheim AS (
                 INSERT INTO sales_channel_secret_shop (channel_id, key, value)
                 SELECT kanal.id, e.key, e.value
                   FROM kanal
                   CROSS JOIN jsonb_each_text(CAST(:secrets AS jsonb)) e
                   JOIN erlaubt a ON a.key = e.key AND a.secret
                  WHERE btrim(e.value) <> ''
                 ON CONFLICT (channel_id, key) DO UPDATE SET value = EXCLUDED.value, mtime = now()
                 RETURNING key
             ), geaendert AS (
                 UPDATE sales_channel_shop c
                    SET settings = COALESCE(c.settings, '{}'::jsonb)
                                   || COALESCE((SELECT jsonb_object_agg(key, value) FROM werte), '{}'::jsonb),
                        mtime = now()
                   FROM kanal
                  WHERE c.id = kanal.id
                 RETURNING c.id
             )
             SELECT (SELECT id FROM geaendert) AS id,
                    (SELECT COALESCE(json_object_agg(x.key, true), '{}')
                       FROM (SELECT s.key FROM sales_channel_secret_shop s, kanal
                              WHERE s.channel_id = kanal.id AND s.value <> ''
                             UNION
                             SELECT key FROM geheim) x) AS secrets_set",
            [
                ':kanal'    => (int)($data['channel_id'] ?? 0),
                ':settings' => $objekt($data['settings'] ?? []),
                ':secrets'  => $objekt($data['secrets'] ?? []),
            ]
        );
    } catch (PDOException $e) {
        // Der Shop-Schlüssel bestimmt Mandant und HugoShop — eindeutig
        // (sales_channel_secret_shop_public_key)
        if ('23505' === $e->getCode()) {
            // Text in der Oberfläche (ShopView.errors.CHANNEL_KEY_TAKEN)
            resultInfo(false, 'CHANNEL_KEY_TAKEN', null, null);
            return;
        }
        throw $e;
    }

    if (empty($zeile['id'])) {
        resultInfo(false, 'CHANNEL_NOT_FOUND', null, 'Diesen Verkaufskanal gibt es nicht');
        return;
    }
    resultInfo(true, 'CHANNEL_SETTINGS_SAVED', [
        'secrets_set' => json_decode((string)($zeile['secrets_set'] ?? '{}'), true) ?: (object)[],
    ]);
}

/**
 * Legt einen Verkaufskanal an (dev/shop-mehrere-kanaele.md)
 *
 * Eine weitere Instanz einer Art — ein HugoShop mit eigener Webseite, ein
 * eBay-Kanal mit eigenem Konto. Neue Kanäle sind abgeschaltet, nehmen keine
 * Artikel automatisch auf und bekommen die Vorgaben ihrer Art
 * (shop_channel_default_settings): eingeschaltet wird, wenn eingerichtet ist.
 *
 * @param string $data['type'] Art: hugoshop oder ebay
 * @param string $data['name'] Name, eindeutig
 * @return void channel_id
 * @testdata {"type": "hugoshop", "name": "Zweitshop"}
 */
function createShopChannel($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $art = (string)($data['type'] ?? '');
    $name = trim((string)($data['name'] ?? ''));
    if (!in_array($art, SHOP_CHANNEL_TYPES, true)) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Unbekannte Kanalart: '.$art);
        return;
    }
    if ('' === $name) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Der Kanal braucht einen Namen');
        return;
    }

    try {
        $zeile = $db->getOne(
            "INSERT INTO sales_channel_shop (type, name, active, auto_add_parts, sortkey, settings)
             SELECT :art, :name, false, false,
                    COALESCE((SELECT max(sortkey) FROM sales_channel_shop), 0) + 1,
                    shop_channel_default_settings(:art_vorgabe)
             RETURNING id",
            [':art' => $art, ':name' => $name, ':art_vorgabe' => $art]
        );
    } catch (PDOException $e) {
        if ('23505' === $e->getCode()) {
            // Text in der Oberfläche (ShopView.errors.CHANNEL_NAME_TAKEN)
            resultInfo(false, 'CHANNEL_NAME_TAKEN', null, null);
            return;
        }
        throw $e;
    }

    resultInfo(true, 'CHANNEL_CREATED', ['channel_id' => (int)$zeile['id']]);
}

/**
 * Löscht einen Verkaufskanal (M5)
 *
 * Nur abgeschaltet und ohne offene Aufträge — beim Abschalten entstehen die
 * Aufträge, die Seiten oder Angebote zurücknehmen; sie müssen gelaufen sein,
 * sonst blieben Seiten stehen und eBay-Angebote aktiv. Mit Belegen
 * (Rechnungslinks, Widerrufe, eBay-Bestellungen) verhindert die Datenbank das
 * Löschen; dann bleibt der Kanal abgeschaltet stehen. Mit dem Kanal gehen
 * seine Artikelzeilen, Bilder, Lieferländer, Preisstufen, Warenkörbe und
 * erledigten Aufträge.
 *
 * @param int $data['channel_id'] Kanal
 * @return void
 * @testdata {"channel_id": 0}
 */
function deleteShopChannel($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    try {
        $zeile = $db->getOne(
            "DELETE FROM sales_channel_shop c
              WHERE c.id = CAST(:kanal AS integer)
                AND NOT c.active
                AND NOT EXISTS (SELECT 1 FROM batchjob_hugoshop b WHERE b.channel_id = c.id AND b.result IS NULL)
             RETURNING c.id",
            [':kanal' => (int)($data['channel_id'] ?? 0)]
        );
    } catch (PDOException $e) {
        if ('23503' === $e->getCode()) {
            // Text in der Oberfläche (ShopView.errors.CHANNEL_IN_USE): Rechnungen,
            // Widerrufe oder eBay-Bestellungen hängen daran — nur abschalten
            resultInfo(false, 'CHANNEL_IN_USE', null, null);
            return;
        }
        throw $e;
    }

    if (!$zeile) {
        // Text in der Oberfläche (ShopView.errors.CHANNEL_NOT_DELETABLE): nur
        // abgeschaltet und ohne offene Aufträge
        resultInfo(false, 'CHANNEL_NOT_DELETABLE', null, null);
        return;
    }
    resultInfo(true, 'CHANNEL_DELETED');
}

/**
 * Verfügbare Vorlagensätze für die Produktseiten
 *
 * Für die Auswahl im Einstellungen-Tab. Kundenkopien unter <templates_dir>/shop/
 * verdecken gleichnamige mitgelieferte Sätze.
 *
 * @return void
 * @testdata {}
 */
function getShopTemplateSets($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);

    resultInfo(true, '', ['sets' => shopTemplateSets()]);
}

/**
 * Kanal einer Anfrage aus der Verwaltung
 *
 * channel_id nennt ihn; ohne Angabe gilt der Standardkanal der Art — so
 * arbeitet die Oberfläche bis zur Kanalauswahl (Schritt 5,
 * dev/shop-mehrere-kanaele.md) unverändert weiter.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $data Eingabedaten
 * @param string $art verlangte Art: hugoshop oder ebay
 * @return int Kennung des Kanals
 * @throws ApiError SHOP_CHANNEL_UNKNOWN
 */
function shopChannelOfRequest($db, array $data, string $art): int {
    $angabe = $data['channel_id'] ?? '';
    $kanal = shopChannelParam($db, '' === (string)$angabe || null === $angabe ? $art : $angabe);
    if ($art !== $kanal['type']) {
        throw new ApiError('SHOP_CHANNEL_UNKNOWN', ('hugoshop' === $art ? 'Kein HugoShop: ' : 'Kein eBay-Kanal: ').$angabe);
    }
    return $kanal['id'];
}

/** HugoShop einer Anfrage, siehe shopChannelOfRequest() */
function shopHugoshopOfRequest($db, array $data): int {
    return shopChannelOfRequest($db, $data, 'hugoshop');
}

/**
 * HugoShops einer Anfrage, die alle Webseiten betreffen kann
 *
 * Mit channel_id dieser eine, sonst alle eingeschalteten — für „Alle
 * veröffentlichen" und „Shop-Benutzerschnittstelle installieren" in der
 * Shop-Übersicht, die keinen einzelnen Shop auswählt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $data Eingabedaten
 * @return int[] Kennungen
 * @throws ApiError SHOP_CHANNEL_UNKNOWN
 */
function shopHugoshopsOfRequest($db, array $data): array {
    if ('' !== (string)($data['channel_id'] ?? '')) {
        return [shopHugoshopOfRequest($db, $data)];
    }
    return array_map('intval', array_column($db->getAll(
        "SELECT id FROM sales_channel_shop WHERE type = 'hugoshop' AND active ORDER BY sortkey NULLS LAST, id"
    ) ?: [], 'id'));
}

/**
 * Zeigt die Produktseite eines Artikels, ohne sie zu schreiben
 *
 * Zum Prüfen eines Vorlagensatzes, auch ohne Schreibrecht im Webseiten-Verzeichnis.
 *
 * @param array $data['parts_id'] Artikel
 * @param array $data['channel_id'] HugoShop, leer = Standard-HugoShop
 * @return void
 * @testdata {"parts_id": 1}
 */
function previewShopPage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $kanal = shopHugoshopOfRequest($db, $data);
    $seite = shopPageData($db, $kanal, (int)($data['parts_id'] ?? 0));

    resultInfo(true, '', [
        'filename' => shopPageFileName($seite),
        'content'  => shopRenderPage($db, $kanal, $seite),
    ]);
}

/**
 * Schreibt die Produktseite eines Artikels in das eingestellte Verzeichnis
 *
 * @param array $data['parts_id'] Artikel
 * @param array $data['channel_id'] HugoShop, leer = Standard-HugoShop
 * @return void
 * @testdata {"parts_id": 1}
 */
function writeShopPage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, 'PAGE_WRITTEN', shopWriteProductPage($db, shopHugoshopOfRequest($db, $data), (int)($data['parts_id'] ?? 0)));
}

/**
 * Nimmt einen Artikel in die Veröffentlichung auf
 *
 * Schreibt nur den Auftrag; die Seite entsteht beim nächsten Lauf von
 * tools/shop-publish.php. Passt keine Versandart, entsteht kein Auftrag:
 * SHIPPING_UNFIT mit dem Grund.
 *
 * @param array $data['parts_id'] Artikel
 * @param array $data['channel_id'] HugoShop, leer = Standard-HugoShop
 * @return void
 * @testdata {"parts_id": 1}
 */
function publishShopPart($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopHugoshopOfRequest($db, $data);

    $artikel = $db->getOne(
        "SELECT p.partnumber,
                (SELECT row_to_json(v) FROM shop_part_shipping_check(p.id, CAST(:kanal AS integer)) v) AS versand
           FROM parts p WHERE p.id = :parts_id",
        [':parts_id' => (int)($data['parts_id'] ?? 0), ':kanal' => $kanal]
    );
    if (!$artikel) {
        resultInfo(false, 'PART_NOT_FOUND', null, 'Artikel nicht gefunden');
        return;
    }

    // Ohne passende Versandart wird nicht veröffentlicht (dev/shop-versand.md)
    $versandFehler = shopShippingCheckText(json_decode((string)$artikel['versand'], true) ?: []);
    if ('' !== $versandFehler) {
        resultInfo(false, 'SHIPPING_UNFIT', null, $versandFehler);
        return;
    }

    $id = shopQueueJob($db, 'publish_part', (string)$artikel['partnumber'], null, $kanal);

    resultInfo(true, 'PUBLISH_QUEUED', ['job_id' => $id, 'queued' => $id > 0]);
}

/**
 * Nimmt alle Artikel in die Veröffentlichung auf — eines HugoShops oder aller
 *
 * job_ids nennt die neu angelegten Aufträge, job_id den ersten davon (für
 * Aufrufer, die nur einen erwarten).
 *
 * @param array $data['channel_id'] HugoShop, leer = alle eingeschalteten
 * @return void job_id, job_ids, queued
 * @testdata {}
 */
function publishShopAll($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $ids = [];
    foreach (shopHugoshopsOfRequest($db, $data) as $kanal) {
        $id = shopQueueJob($db, 'publish_all', '', null, $kanal);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    resultInfo(true, 'PUBLISH_QUEUED', ['job_id' => $ids[0] ?? 0, 'job_ids' => $ids, 'queued' => (bool)$ids]);
}

/**
 * Führt Aufträge sofort aus, statt auf den Cron zu warten
 *
 * Startet den Läufer (tools/shop-publish.php) als eigenen Prozess und
 * antwortet sofort — die Oberfläche fragt den Stand danach über
 * getShopPublishStatus ab. Früher lief der ganze Lauf in dieser Anfrage; ein
 * Vollbau hielt sie dann minutenlang fest, und ein Proxy brach sie ab.
 *
 * Arbeitet gerade ein Lauf — der Cron oder ein zweiter Mitarbeiter —, wird
 * nichts gestartet: der laufende nimmt die offenen Aufträge ohnehin mit.
 *
 * Voraussetzung ist, dass der Webserver-Benutzer im Verzeichnis der Webseite
 * schreiben und den Bau-Befehl ausführen darf.
 *
 * @param array $data['ids'] Auftragsnummern; leer bedeutet alle offenen
 * @return void
 * @testdata {"ids": []}
 */
function runShopPublishJobs($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $ids = array_values(array_filter(array_map('intval', (array)($data['ids'] ?? [])), fn($id) => $id > 0));

    if (shopPublishStatus($db)['running']) {
        resultInfo(true, '', ['started' => false, 'running' => true]);
        return;
    }

    $start = shopPublishStartBackground($db, $ids);
    if ('' !== $start['fehler']) {
        resultInfo(false, 'SHOP_PUBLISH_START_FAILED', null, $start['fehler']);
        return;
    }

    resultInfo(true, '', ['started' => true, 'running' => true]);
}

/**
 * Installiert die Shop-Benutzerschnittstelle in der Webseite
 *
 * Übernimmt aus dem Panel, was sonst tools/shop-publish.php im Cron erledigt:
 * das Paket des Vorlagensatzes (Widget-Bündel, Shortcodes, Einstiegspunkte,
 * config.json) nach <webseite>/oserp-shop/ spiegeln — in der Betriebsart
 * HugoCMS dorthin übertragen — und die Webseite bauen. Gebaut wird auch, wenn
 * das Paket schon aktuell war (SHOP_KIT_INSTALL). Fehlende Mounts oder
 * params.shopui meldet der Lauf als Hinweis.
 *
 * Dazu wird ein Auftrag sync_kit angelegt und allein ausgeführt. Ist schon
 * einer offen, wird er dafür übernommen: shop_queue_job legt keinen zweiten
 * an. Arbeitet gerade ein Lauf, wird nichts gestartet — der Auftrag bleibt
 * offen und wird beim nächsten Lauf erledigt.
 *
 * @param array $data['channel_id'] HugoShop, leer = alle eingeschalteten
 * @return void job_id, job_ids, started, running
 * @testdata {}
 */
function installShopUi($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $ids = [];
    foreach (shopHugoshopsOfRequest($db, $data) as $kanal) {
        $id = shopQueueJob($db, 'sync_kit', '', SHOP_KIT_INSTALL, $kanal);
        if (0 === $id) {
            $offen = $db->getOne(
                "UPDATE batchjob_hugoshop b SET param = :param
                  WHERE b.channel_id = CAST(:kanal AS integer)
                    AND b.function = 'sync_kit'
                    AND b.partnumber = ''
                    AND b.result IS NULL
              RETURNING b.id",
                [':param' => SHOP_KIT_INSTALL, ':kanal' => $kanal]
            );
            $id = (int)($offen['id'] ?? 0);
        }
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    if (!$ids) {
        resultInfo(false, 'SHOP_PUBLISH_START_FAILED', null, 'Der Auftrag ließ sich nicht anlegen.');
        return;
    }

    if (shopPublishStatus($db)['running']) {
        resultInfo(true, '', ['job_id' => $ids[0], 'job_ids' => $ids, 'started' => false, 'running' => true]);
        return;
    }

    $start = shopPublishStartBackground($db, $ids);
    if ('' !== $start['fehler']) {
        resultInfo(false, 'SHOP_PUBLISH_START_FAILED', null, $start['fehler']);
        return;
    }

    resultInfo(true, '', ['job_id' => $ids[0], 'job_ids' => $ids, 'started' => true, 'running' => true]);
}

/**
 * Prüft die Verbindung zu HugoCMS
 *
 * Fragt dort den Baustand der Webseite ab. Gelingt das, stimmen Adresse,
 * Schlüssel und Zuordnung zur Webseite; die Antwort zeigt zugleich, ob HugoCMS
 * bauen kann und wie der letzte Lauf ausging.
 *
 * Adresse und Schlüssel dürfen aus dem Formular kommen: Die Firmenkonfiguration
 * speichert verzögert, und ein Test direkt nach der Eingabe prüfte sonst noch
 * die alten Werte. Leere Angaben fallen auf das Gespeicherte zurück — das
 * Schlüsselfeld ist nach dem Laden immer leer.
 *
 * @param string $data['url'] Adresse aus dem Formular (optional)
 * @param string $data['key'] Schlüssel aus dem Formular (optional)
 * @param array $data['channel_id'] HugoShop, leer = Standard-HugoShop
 * @return void
 * @testdata {}
 */
function testShopHugoCms($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $antwort = shopHugoCmsCall($db, shopHugoshopOfRequest($db, $data), 'shopbuildstatus', 'GET', 20, [
        'url' => (string)($data['url'] ?? ''),
        'key' => (string)($data['key'] ?? ''),
    ]);
    if (!$antwort['ok']) {
        resultInfo(false, 'SHOP_HUGOCMS_FAILED', null, $antwort['fehler']);
        return;
    }

    resultInfo(true, '', $antwort['data']);
}

/**
 * Stand der Veröffentlichung
 *
 * Ob ein Lauf arbeitet, die Meldungen des laufenden oder letzten Laufs, seine
 * Bilanz — gleich, ob ihn der Cron oder das Panel gestartet hat. Die
 * Oberfläche fragt das während eines Laufs regelmäßig ab.
 *
 * @return void
 * @testdata {}
 */
function getShopPublishStatus($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', shopPublishStatus($db));
}

/**
 * Löscht ausgewählte Aufträge
 *
 * Gedacht für fehlgeschlagene Aufträge: der Benutzer liest den Fehler und
 * nimmt die Zeile dann aus der Liste. Offene lassen sich ebenso löschen, wenn
 * sie niemand mehr ausgeführt haben will.
 *
 * @param array $data['ids'] Auftragsnummern
 * @return void
 * @testdata {"ids": []}
 */
function deleteShopPublishJobs($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', ['removed' => shopDeleteJobs($db, (array)($data['ids'] ?? []))]);
}

/**
 * Räumt erledigte Aufträge aus der Warteschlange
 *
 * Löscht die erfolgreich ausgeführten, damit die Tabelle nicht vollläuft.
 * Fehlgeschlagene und offene bleiben stehen. Regelmäßig tut das auch der
 * Läufer, dort nach der eingestellten Aufbewahrungsfrist
 * (shop_job_retention_days); dieser Knopf räumt sofort und ohne Frist.
 *
 * @return void
 * @testdata {}
 */
function cleanupShopPublishJobs($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', ['removed' => shopCleanupJobs($db)]);
}

/**
 * Offene und zuletzt erledigte Aufträge
 *
 * Nur Auftragsarten der Kanalmodule (shopChannelJobPairs), mit Kanal.
 *
 * @return void
 * @testdata {}
 */
function getShopPublishJobs($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    // Die Sortierung der Teilabfragen gilt nur für deren Auswahl — die
    // Reihenfolge der Vereinigung muss aussen stehen.
    resultInfo(true, '', $db->getAll(
        "WITH eigene AS (
             SELECT b.id, b.itime, b.function, b.partnumber, b.param, b.result, b.run_id,
                    c.type AS channel, b.channel_id, c.name AS channel_name,
                    -- Für den Verweis auf den Artikel. Die Artikelnummer ist in
                    -- parts nicht eindeutig erzwungen: der aktive vor dem
                    -- veralteten, sonst der älteste.
                    (SELECT p.id FROM parts p
                      WHERE b.partnumber <> '' AND p.partnumber = b.partnumber
                      ORDER BY COALESCE(p.obsolete, false), p.id
                      LIMIT 1) AS parts_id
               FROM batchjob_hugoshop b
               JOIN sales_channel_shop c ON c.id = b.channel_id
              WHERE (c.type || ':' || b.function) = ANY(string_to_array(:paare, ','))
         )
         SELECT * FROM (
             (SELECT *, true AS open FROM eigene WHERE result IS NULL
               ORDER BY id LIMIT 50)
             UNION ALL
             (SELECT *, false AS open FROM eigene WHERE result IS NOT NULL
               ORDER BY id DESC LIMIT 20)
         ) auftraege
         ORDER BY open DESC, id DESC",
        [
            ':paare' => shopChannelJobPairs(),
        ]
    ));
}

/**
 * Ausgabe des Laufs, in dem ein Auftrag erledigt wurde
 *
 * Solange der Auftrag besteht; mit dem letzten Auftrag eines Laufs geht auch
 * seine Ausgabe (Trigger auf batchjob_hugoshop).
 *
 * @param int $data['id'] Auftragsnummer
 * @return void
 * @testdata {"id": 1}
 */
function getShopPublishRunOutput($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $lauf = $db->getOne(
        "SELECT r.id, r.itime, r.finished, r.output
           FROM batchjob_hugoshop b
           JOIN batchjob_run_hugoshop r ON r.id = b.run_id
          WHERE b.id = :id",
        [':id' => (int)($data['id'] ?? 0)]
    );
    if (!$lauf) {
        resultInfo(false, 'SHOP_RUN_OUTPUT_MISSING', null, 'Zu diesem Auftrag ist keine Ausgabe gespeichert.');
        return;
    }

    resultInfo(true, '', $lauf);
}
