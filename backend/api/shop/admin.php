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

    $versandNr = shopConfigValue($db, 'shop_shipping_partnumber');

    // Eine Abfrage für alles, was in der Datenbank nachzuschlagen ist
    $stand = $db->getOne(
        "SELECT
            (SELECT COUNT(*) FROM parts WHERE partnumber = :versand_nr) > 0 AS versandartikel,
            (SELECT COUNT(*) FROM employee WHERE login = :kontakt AND NOT deleted) > 0 AS kontakt,
            (SELECT COUNT(*) FROM chart WHERE description ILIKE :forderungskonto) > 0 AS forderungskonto,
            (SELECT COUNT(*) FROM tax_zones WHERE description ILIKE :taxzone) > 0 AS taxzone,
            (SELECT COUNT(*) FROM currencies WHERE name ILIKE :currency) > 0 AS currency,
            (SELECT COUNT(*) FROM parts_channel_shop
              WHERE channel_id = shop_active_channel_id('hugoshop') AND active) AS artikel_mit_shopdaten,
            EXISTS (SELECT 1 FROM sales_channel_shop WHERE active AND round_99) AS rundung_99,
            shop_active_channel_id('hugoshop') IS NOT NULL AS hugoshop_an,
            shop_active_channel_id('ebay') IS NOT NULL AS ebay_an,
            EXISTS (SELECT 1 FROM bin
                     WHERE id::text = btrim((SELECT value FROM defaults_oserp
                                              WHERE key = 'shop_stock_bin_id'))) AS lagerplatz,
            (SELECT COUNT(*) FROM context_hugoshop) AS sitzungen,
            (SELECT COUNT(*) FROM carts_hugoshop) AS warenkoerbe",
        [
            ':versand_nr'      => $versandNr,
            ':kontakt'         => shopConfigValue($db, 'shop_contact_login'),
            ':forderungskonto' => '%'.shopConfigValue($db, 'shop_target_account').'%',
            ':taxzone'         => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
            ':currency'        => shopConfigValue($db, 'shop_standard_currency', 'EUR'),
        ]
    );

    $wahr = fn($wert) => in_array($wert, ['t', true, 1, '1'], true);

    // Ohne diese Punkte nimmt der oeffentliche Zugang keine Bestellung an
    $blockierend = [];
    if ('' === shopConfigValue($db, 'shop_public_key')) {
        $blockierend[] = 'shop_public_key';
    }
    if ('' === $versandNr || !$wahr($stand['versandartikel'])) {
        $blockierend[] = 'shop_shipping_partnumber';
    }
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
    // Geprueft wird das Paar, das gerade gilt — im Testbetrieb nuetzen die
    // Echtbetrieb-Zugangsdaten nichts und umgekehrt.
    $paypalVorsilbe = shopConfigBool($db, 'shop_paypal_sandbox', true)
        ? 'shop_paypal_sandbox_' : 'shop_paypal_live_';
    if ('' === shopConfigValue($db, $paypalVorsilbe.'client_id')
        || '' === shopConfigValue($db, $paypalVorsilbe.'secret')) {
        $hinweise[] = $paypalVorsilbe.'client_id';
    }
    if ('' === shopConfigValue($db, 'shop_payment_iban')) {
        $hinweise[] = 'shop_payment_iban';
    }
    if ('' === shopConfigValue($db, 'shop_base_url')) {
        $hinweise[] = 'shop_base_url';
    }
    if (0 == (int)$stand['artikel_mit_shopdaten']) {
        $hinweise[] = 'parts_ext';
    }
    if (shopConfigBool($db, 'shop_paypal_sandbox', true)) {
        $hinweise[] = 'shop_paypal_sandbox';
    }
    // Veröffentlichung: Ohne gültiges Programm werden Seiten geschrieben, aber
    // nicht gebaut; ohne Wurzelverzeichnis entsteht überhaupt keine Seite. Der
    // Feldname allein sagt nicht, was daran fehlt — der Grund kommt mit.
    $veroeffentlichung = [];
    if ('hugocms' === shopPublishMode($db)) {
        // Betriebsart HugoCMS: gebaut wird dort. Hier zählt nur, ob Adresse
        // und Schlüssel gesetzt sind — ob sie stimmen, prüft der Knopf in den
        // Einstellungen; ein Aufruf bei jedem Öffnen der Übersicht wäre zu teuer.
        $adresse = shopHugoCmsUrl(shopConfigValue($db, 'shop_hugocms_url'));
        if ('' !== $adresse['fehler']) {
            $hinweise[] = 'shop_hugocms_url';
            $veroeffentlichung[] = $adresse['fehler'];
        }
        if ('' === shopConfigValue($db, 'shop_hugocms_key')) {
            $hinweise[] = 'shop_hugocms_key';
        }
    } else {
        $programm = shopPublishProgram($db);
        if ('' === $programm['pfad']) {
            $hinweise[] = 'shop_publish_command_path';
            if ('' !== $programm['fehler']) {
                $veroeffentlichung[] = $programm['fehler'];
            }
        }
        try {
            shopSiteDir($db);
        } catch (Throwable $e) {
            $hinweise[] = 'shop_sites_dir';
            $veroeffentlichung[] = $e->getMessage();
        }
    }

    // V18: Die Punkte oben betreffen den HugoShop. Ist er abgeschaltet — etwa
    // bei einem Mandanten, der nur über eBay verkauft —, fehlt nichts davon.
    if (!$wahr($stand['hugoshop_an'])) {
        $blockierend = [];
        $hinweise = [];
        $veroeffentlichung = [];
    }

    // eBay-Kanal: ohne diese Angaben lehnt eBay jedes Angebot ab
    if ($wahr($stand['ebay_an'])) {
        $ebay = function_exists('shopEbayConfig') ? shopEbayConfig($db) : [];
        $leer = fn(string $key) => '' === trim((string)($ebay[$key] ?? ''));
        foreach ([
            'ebay_client_id'          => $leer('ebay_client_id') || $leer('ebay_client_secret') || $leer('ebay_refresh_token'),
            'ebay_public_host'        => $leer('ebay_public_host'),
            'ebay_category_id'        => $leer('ebay_default_category_id'),
            'ebay_location_key'       => $leer('ebay_merchant_location_key'),
            'ebay_payment_policy'     => $leer('ebay_payment_policy_id'),
            'ebay_return_policy'      => $leer('ebay_return_policy_id'),
            'ebay_fulfillment_policy' => $leer('ebay_fulfillment_policy_id'),
        ] as $punkt => $fehlt) {
            if ($fehlt) {
                $hinweise[] = $punkt;
            }
        }
    }

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
                       pe.hugoshop_category
                  FROM parts p
                  LEFT JOIN parts_ext pe ON pe.parts_id = p.id
                 WHERE p.id = :parts_id) a) AS part,
            (SELECT COALESCE(json_agg(k ORDER BY k.sortkey NULLS LAST, k.channel_id), '[]'::json) FROM (
                SELECT c.id AS channel_id, c.type, c.sortkey,
                       c.markup_type AS channel_markup_type, c.markup_value AS channel_markup_value,
                       c.round_99,
                       COALESCE(pc.active, false) AS active,
                       pc.markup_type, pc.markup_value, pc.title, pc.description,
                       COALESCE(pc.settings, '{}'::jsonb) AS settings,
                       pc.sync_status, pc.sync_error, pc.sync_mtime, pc.external_id,
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
            ':taxzone'         => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
        ]
    );

    resultInfo(true, '', [
        'part'      => json_decode((string)($zeile['part'] ?? 'null'), true),
        'channels'  => json_decode((string)($zeile['channels'] ?? '[]'), true) ?: [],
        'tax_rates' => json_decode((string)($zeile['tax_rates'] ?? '{}'), true) ?: (object)[],
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
 *              title, description, settings (kanaleigene Angaben, etwa
 *              category_id und condition bei eBay)
 * @return void
 * @testdata {"parts_id": 1, "category": "Bremsen", "hyperlink": "bremsscheibe", "channels": [{"channel_id": 1, "active": true, "markup_type": "percent", "markup_value": 10, "title": "", "description": ""}]}
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

    // vorher liest den Stand vor der Anweisung — alle Teile einer Anweisung
    // sehen denselben Ausgangsstand. Daraus ergibt sich, ob der HugoShop
    // gerade abgewählt wird.
    $seite = $db->getOne(
        "WITH vorher AS (
             SELECT active FROM parts_channel_shop
              WHERE parts_id = :parts_id AND channel_id = shop_channel_id('hugoshop')
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
             SELECT DISTINCT ON (COALESCE(e.channel_id, shop_channel_id('hugoshop')))
                    COALESCE(e.channel_id, shop_channel_id('hugoshop')) AS channel_id,
                    COALESCE(e.active, false) AS active,
                    CASE WHEN e.markup_type IN ('none', 'percent', 'amount') THEN e.markup_type END AS markup_type,
                    CASE WHEN e.markup_type IN ('percent', 'amount') THEN COALESCE(e.markup_value, 0) END AS markup_value,
                    NULLIF(btrim(e.title), '') AS title,
                    NULLIF(btrim(e.description), '') AS description,
                    -- leere Angaben fallen weg: sie hießen „Vorgabe des Kanals“
                    (SELECT jsonb_object_agg(a.key, a.value)
                       FROM jsonb_each(CASE WHEN jsonb_typeof(e.settings) = 'object' THEN e.settings END) a
                      WHERE a.value NOT IN ('null'::jsonb, to_jsonb(''::text))) AS settings
               FROM jsonb_to_recordset(:kanaele::jsonb)
                    AS e(channel_id integer, active boolean, markup_type text, markup_value numeric,
                         title text, description text, settings jsonb)
         ), geschrieben AS (
             INSERT INTO parts_channel_shop (parts_id, channel_id, active, markup_type, markup_value,
                                             title, description, settings)
             SELECT ext.parts_id, e.channel_id, e.active, e.markup_type, e.markup_value,
                    e.title, e.description, e.settings
               FROM ext
               JOIN eingabe e ON true
               JOIN sales_channel_shop c ON c.id = e.channel_id
             ON CONFLICT (parts_id, channel_id) DO UPDATE SET
                    active       = EXCLUDED.active,
                    markup_type  = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.markup_type  ELSE parts_channel_shop.markup_type END,
                    markup_value = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.markup_value ELSE parts_channel_shop.markup_value END,
                    title        = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.title        ELSE parts_channel_shop.title END,
                    description  = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.description  ELSE parts_channel_shop.description END,
                    settings     = CASE WHEN :vollstaendig = 1 THEN EXCLUDED.settings     ELSE parts_channel_shop.settings END,
                    mtime        = now()
             RETURNING channel_id, active
         )
         SELECT p.partnumber, pe.hugoshop_hyperlink
           FROM parts p
           LEFT JOIN parts_ext pe ON pe.parts_id = p.id
          WHERE p.id = :parts_id
            AND EXISTS (SELECT 1 FROM vorher WHERE vorher.active)
            AND EXISTS (SELECT 1 FROM geschrieben g
                         WHERE g.channel_id = shop_channel_id('hugoshop') AND NOT g.active)",
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
        ]
    );

    // Die abschließende Abfrage liest parts_ext im Stand vor der Anweisung,
    // also die Zielseite, unter der die Seite tatsächlich liegt — auch wenn
    // dieselbe Speicherung die Adresse ändert.
    if ($seite) {
        shopQueueRemovePage($db, (string)$seite['partnumber'], (string)($seite['hugoshop_hyperlink'] ?? ''));
    }

    resultInfo(true, 'PART_SHOP_DATA_SAVED');
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

    // Abschalten und den Dateinamen der Seite lesen in einem Vorgang. Nur
    // wenn der Artikel im HugoShop angeboten wurde, kommt eine Zeile zurück —
    // dann entsteht der Auftrag zum Entfernen der Seite.
    $seite = $db->getOne(
        "WITH aus AS (
             UPDATE parts_channel_shop SET active = false, mtime = now()
              WHERE parts_id = :parts_id AND active
             RETURNING parts_id, channel_id
         )
         SELECT p.partnumber, pe.hugoshop_hyperlink
           FROM aus
           JOIN parts p ON p.id = aus.parts_id
           LEFT JOIN parts_ext pe ON pe.parts_id = p.id
          WHERE aus.channel_id = shop_channel_id('hugoshop')",
        [':parts_id' => $partsId]
    );

    if ($seite) {
        shopQueueRemovePage($db, (string)$seite['partnumber'], (string)($seite['hugoshop_hyperlink'] ?? ''));
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
 * @param string $partnumber Artikelnummer
 * @param string $hyperlink Zielseite aus parts_ext, leer = Artikelnummer
 * @return void
 */
function shopQueueRemovePage($db, string $partnumber, string $hyperlink): void {
    $datei = shopPageFileName(['shop' => ['hyperlink' => $hyperlink], 'artikel' => ['partnumber' => $partnumber]]);
    shopQueueJob($db, 'remove_part', $partnumber, $datei);
}

/**
 * Lädt ein Bild für einen Marktplatz-Kanal hoch
 *
 * @param array $data['parts_id'] Artikel
 * @param array $data['channel'] Art des Kanals, etwa ebay
 * @param array $data['filename'] ursprünglicher Dateiname
 * @param array $data['data'] Bilddaten Base64, auch als data:-Adresse
 * @return void Bilder des Kanals nach dem Hochladen
 * @testdata {"parts_id": 1, "channel": "ebay", "filename": "foto.jpg", "data": "data:image/png;base64,..."}
 */
function uploadShopChannelImage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $partsId = (int)($data['parts_id'] ?? 0);
    $kanal = shopChannelIdOf($db, (string)($data['channel'] ?? ''));
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
 * @param array $data['channel'] Art des Kanals
 * @param array $data['ids'] Bildkennungen in der neuen Reihenfolge, das erste ist das Hauptbild
 * @return void Bilder des Kanals danach
 * @testdata {"parts_id": 1, "channel": "ebay", "ids": [2, 1]}
 */
function sortShopChannelImages($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $partsId = (int)($data['parts_id'] ?? 0);
    $kanal = shopChannelIdOf($db, (string)($data['channel'] ?? ''));
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
 * @param array $data['from'] Art des Quellkanals
 * @param array $data['to'] Art des Zielkanals
 * @return void Zahl der übernommenen Bilder
 * @testdata {"parts_id": 1, "from": "hugoshop", "to": "ebay"}
 */
function copyShopChannelImages($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $anzahl = shopChannelImageCopy($db, (int)($data['parts_id'] ?? 0),
        (string)($data['from'] ?? ''), (string)($data['to'] ?? ''));
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

    shopEbayToken($db, true);
    $antwort = shopEbayApi($db, 'GET', '/sell/fulfillment/v1/order', null, ['limit' => 1]);
    if ($antwort['status'] >= 400) {
        resultInfo(false, 'EBAY_API_ERROR', null, 'eBay-API-Fehler: '.shopEbayErrorMessage($antwort));
        return;
    }
    resultInfo(true, '', [
        'connected'   => true,
        'environment' => shopEbayConfig($db)['ebay_environment'] ?? 'production',
        'orderTotal'  => (int)($antwort['body']['total'] ?? 0),
    ]);
}

/**
 * Ruft die eBay-Bestellungen jetzt ab, statt auf den Cron zu warten
 *
 * @return void imported, skipped, fetched, errors
 * @testdata {}
 */
function syncShopEbayOrders($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', shopEbayImportOrders($db));
}

/**
 * Stand des eBay-Kanals: letzter Abruf und zuletzt importierte Bestellungen
 *
 * @return void enabled, lastCheck, counts, recent
 * @testdata {}
 */
function getShopEbayStatus($data) {
    permit(['shop_order', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', shopEbayStatus($db));
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
            "SELECT c.id AS channel_id, c.type, c.active, c.sortkey,
                    c.markup_type, c.markup_value, c.round_99,
                    (SELECT COUNT(*) FROM parts_channel_shop pc
                      WHERE pc.channel_id = c.id AND pc.active) AS parts
               FROM sales_channel_shop c
              ORDER BY c.sortkey NULLS LAST, c.id"
        ),
        'tax_included' => shopConfigBool($db, 'shop_tax_included'),
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
 * @return void
 * @testdata {"channel_id": 1, "active": true, "markup_type": "percent", "markup_value": 10, "round_99": true}
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
    if ('percent' === $art && $wert <= -100) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Ein Abschlag von 100 % oder mehr ergibt keinen Preis');
        return;
    }

    // vorher liest den Stand vor der Änderung: daraus ergibt sich, ob sich
    // der Preis der HugoShop-Seiten ändert.
    $zeile = $db->getOne(
        "WITH vorher AS (
             SELECT id, active, markup_type, markup_value, round_99
               FROM sales_channel_shop WHERE id = :channel_id
         ), geaendert AS (
             UPDATE sales_channel_shop
                SET active       = :active OR NOT EXISTS (
                                       SELECT 1 FROM sales_channel_shop andere
                                        WHERE andere.id <> :channel_id_andere AND andere.active),
                    markup_type  = :markup_type,
                    markup_value = :markup_value,
                    round_99     = :round_99,
                    mtime        = now()
              WHERE id = :channel_id
             RETURNING id, type, active, markup_type, markup_value, round_99
         )
         SELECT g.type, g.active,
                (g.active IS DISTINCT FROM v.active) AS geschaltet,
                (g.markup_type IS DISTINCT FROM v.markup_type
                 OR g.markup_value IS DISTINCT FROM v.markup_value
                 OR g.round_99 IS DISTINCT FROM v.round_99) AS preis_geaendert
           FROM geaendert g JOIN vorher v ON v.id = g.id",
        [
            ':channel_id'   => (int)($data['channel_id'] ?? 0),
            ':channel_id_andere' => (int)($data['channel_id'] ?? 0),
            ':active'       => !empty($data['active']),
            ':markup_type'  => $art,
            ':markup_value' => $wert,
            ':round_99'     => !empty($data['round_99']),
        ]
    );

    if (!$zeile) {
        resultInfo(false, 'CHANNEL_NOT_FOUND', null, 'Diesen Verkaufskanal gibt es nicht');
        return;
    }

    $wahr = fn($wert) => in_array($wert, [true, 't', 1, '1'], true);
    $an = $wahr($zeile['active']);

    // Ein- oder ausgeschaltet: das Modul des Kanals entscheidet, was folgt —
    // beim HugoShop Seiten entfernen oder alle neu schreiben (V16). Sonst bei
    // geändertem Preis die Seiten des eingeschalteten HugoShops (V9).
    if ($wahr($zeile['geschaltet'])) {
        shopChannelSwitched($db, (string)$zeile['type'], $an);
        $neuVeroeffentlichen = 'hugoshop' === $zeile['type'] && $an;
        $auftrag = 0;
    } else {
        $neuVeroeffentlichen = 'hugoshop' === $zeile['type'] && $an && $wahr($zeile['preis_geaendert'])
            && shopConfigBool($db, 'shop_auto_publish', true);
        $auftrag = $neuVeroeffentlichen ? shopQueueJob($db, 'publish_all') : 0;
    }

    resultInfo(true, 'CHANNEL_SAVED', [
        'republish' => $neuVeroeffentlichen,
        'job_id'    => $auftrag,
        'active'    => $an,
    ]);
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
 * Zeigt die Produktseite eines Artikels, ohne sie zu schreiben
 *
 * Zum Prüfen eines Vorlagensatzes, auch ohne Schreibrecht im Webseiten-Verzeichnis.
 *
 * @param array $data['parts_id'] Artikel
 * @return void
 * @testdata {"parts_id": 1}
 */
function previewShopPage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $seite = shopPageData($db, (int)($data['parts_id'] ?? 0));

    resultInfo(true, '', [
        'filename' => shopPageFileName($seite),
        'content'  => shopRenderPage($db, $seite),
    ]);
}

/**
 * Schreibt die Produktseite eines Artikels in das eingestellte Verzeichnis
 *
 * @param array $data['parts_id'] Artikel
 * @return void
 * @testdata {"parts_id": 1}
 */
function writeShopPage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, 'PAGE_WRITTEN', shopWriteProductPage($db, (int)($data['parts_id'] ?? 0)));
}

/**
 * Nimmt einen Artikel in die Veröffentlichung auf
 *
 * Schreibt nur den Auftrag; die Seite entsteht beim nächsten Lauf von
 * tools/shop-publish.php.
 *
 * @param array $data['parts_id'] Artikel
 * @return void
 * @testdata {"parts_id": 1}
 */
function publishShopPart($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $artikel = $db->getOne(
        "SELECT partnumber FROM parts WHERE id = :parts_id",
        [':parts_id' => (int)($data['parts_id'] ?? 0)]
    );
    if (!$artikel) {
        resultInfo(false, 'PART_NOT_FOUND', null, 'Artikel nicht gefunden');
        return;
    }

    $id = shopQueueJob($db, 'publish_part', (string)$artikel['partnumber']);

    resultInfo(true, 'PUBLISH_QUEUED', ['job_id' => $id, 'queued' => $id > 0]);
}

/**
 * Nimmt alle Artikel des Shops in die Veröffentlichung auf
 *
 * @return void
 * @testdata {}
 */
function publishShopAll($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $id = shopQueueJob($db, 'publish_all');

    resultInfo(true, 'PUBLISH_QUEUED', ['job_id' => $id, 'queued' => $id > 0]);
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
 * @return void
 * @testdata {}
 */
function testShopHugoCms($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();

    $antwort = shopHugoCmsCall($db, 'shopbuildstatus', 'GET', 20, [
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
 * Nur die der Veröffentlichung — die Tabelle teilt sich OSERP mit der Bridge.
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
             SELECT b.id, b.itime, b.function, b.partnumber, b.param, b.result,
                    c.type AS channel
               FROM batchjob_hugoshop b
               JOIN sales_channel_shop c ON c.id = COALESCE(b.channel_id, shop_channel_id('hugoshop'))
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
