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
    if ('' === shopConfigValue($db, 'shop_paypal_client_id')) {
        $hinweise[] = 'shop_paypal_client_id';
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
