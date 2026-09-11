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
            (SELECT COUNT(*) FROM parts_ext) AS artikel_mit_shopdaten,
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

    resultInfo(true, '', [
        'ready'     => empty($blockierend),
        'blocking'  => $blockierend,
        'hints'     => $hinweise,
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
 * Shop-Angaben eines Artikels
 *
 * listed sagt, ob es die Zeile in parts_ext gibt — nur dann findet die
 * Shop-Suche den Artikel. Die übrigen Felder sind ohne Zeile leer.
 *
 * @param array $data['parts_id'] Artikel
 * @return void
 * @testdata {"parts_id": 1}
 */
function getPartShopData($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    resultInfo(true, '', $db->getOne(
        "SELECT p.id AS parts_id, p.partnumber, p.description, TRUNC(p.sellprice, 2) AS sellprice,
                (pe.id IS NOT NULL) AS listed,
                pe.hugoshop_breadcrumbs, pe.hugoshop_technical_data, pe.hugoshop_properties,
                pe.hugoshop_downloads, pe.hugoshop_images, pe.hugoshop_hyperlink,
                pe.hugoshop_category
           FROM parts p
           LEFT JOIN parts_ext pe ON pe.parts_id = p.id
          WHERE p.id = :parts_id",
        [':parts_id' => (int)($data['parts_id'] ?? 0)]
    ) ?: null);
}

/**
 * Speichert die Shop-Angaben eines Artikels
 *
 * @param array $data['parts_id'] Artikel
 * @param array $data['category'] Kategorie
 * @param array $data['hyperlink'] Zielseite im Shop
 * @param array $data['breadcrumbs'] JSON-Array
 * @param array $data['images'] JSON-Array
 * @param array $data['technical_data'] JSON-Objekt: Bezeichnung => Wert
 * @param array $data['properties'] JSON-Objekt: Bezeichnung => Wert
 * @param array $data['downloads'] JSON-Objekt: Anzeigename => Dateiname
 * @return void
 * @testdata {"parts_id": 1, "category": "Bremsen", "hyperlink": "bremsscheibe"}
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

    // Ein Vorgang: anlegen oder ändern, entschieden über den eindeutigen
    // Index auf parts_id.
    $db->execute(
        "INSERT INTO parts_ext (parts_id, hugoshop_category, hugoshop_hyperlink,
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
                hugoshop_downloads      = EXCLUDED.hugoshop_downloads",
        [
            ':parts_id'    => $partsId,
            ':category'    => $data['category']  ?? null,
            ':hyperlink'   => $data['hyperlink'] ?? null,
            ':breadcrumbs' => json_encode($data['breadcrumbs']    ?? []),
            ':images'      => json_encode($data['images']         ?? []),
            ':technical'   => $objekt($data['technical_data'] ?? null),
            ':properties'  => $objekt($data['properties']     ?? null),
            ':downloads'   => $objekt($data['downloads']      ?? null),
        ]
    );

    resultInfo(true, 'PART_SHOP_DATA_SAVED');
}

/**
 * Nimmt einen Artikel aus dem Shop
 *
 * Löscht seine Shop-Angaben; ohne sie findet die Shop-Suche ihn nicht mehr.
 * Der Artikel selbst, Warenkörbe und Rechnungen bleiben unberührt.
 *
 * @param array $data['parts_id'] Artikel
 * @return void
 * @testdata {"parts_id": 1}
 */
function deletePartShopData($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $partsId = (int)($data['parts_id'] ?? 0);

    // Der Dateiname steht in der Zeile, die gleich verschwindet — deshalb
    // vorher lesen und den Auftrag zum Entfernen der Seite anlegen.
    $seite = $db->getOne(
        "SELECT p.partnumber, pe.hugoshop_hyperlink
           FROM parts p
           JOIN parts_ext pe ON pe.parts_id = p.id
          WHERE p.id = :parts_id",
        [':parts_id' => $partsId]
    );

    $db->execute("DELETE FROM parts_ext WHERE parts_id = :parts_id", [':parts_id' => $partsId]);

    if ($seite) {
        $name = '' !== (string)$seite['hugoshop_hyperlink']
            ? (string)$seite['hugoshop_hyperlink']
            : (string)$seite['partnumber'];
        $datei = mb_strtolower(basename($name));
        shopQueueJob($db, 'remove_part', (string)$seite['partnumber'],
                     str_ends_with($datei, '.md') ? $datei : $datei.'.md');
    }

    resultInfo(true, 'PART_SHOP_DATA_REMOVED');
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
        "SELECT * FROM (
             (SELECT id, itime, function, partnumber, param, result, true AS open
                FROM batchjob_hugoshop
               WHERE result IS NULL
                 AND function = ANY(string_to_array(:funktionen_offen, ','))
               ORDER BY id LIMIT 50)
             UNION ALL
             (SELECT id, itime, function, partnumber, param, result, false AS open
                FROM batchjob_hugoshop
               WHERE result IS NOT NULL
                 AND function = ANY(string_to_array(:funktionen_erledigt, ','))
               ORDER BY id DESC LIMIT 20)
         ) auftraege
         ORDER BY open DESC, id DESC",
        [
            ':funktionen_offen'    => implode(',', shopJobFunctions()),
            ':funktionen_erledigt' => implode(',', shopJobFunctions()),
        ]
    ));
}
