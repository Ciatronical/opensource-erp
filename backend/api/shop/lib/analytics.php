<?php
// backend/api/shop/lib/analytics.php
//
// Angaben für die Reichweitenmessung der Shop-Webseite (Google Tag Manager
// und Ähnliches). Der Shop meldet damit, welcher Artikel angesehen und was
// gekauft wurde.
//
// Hier steht nur die Auskunft; ob und wohin sie gemeldet wird, entscheidet
// die Shop-Webseite. Personenbezogene Angaben sind nicht dabei — Name,
// Anschrift und E-Mail des Kunden bleiben draußen.

/**
 * Angaben zu einem Artikel
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @return array|null
 */
function analyticsProduct($db, int $partsId): ?array {
    $zeile = $db->getOne(
        "SELECT p.partnumber AS id, p.description AS name,
                TRUNC(p.sellprice, 2) AS price,
                pe.hugoshop_category AS category,
                (SELECT c.name FROM currencies c CROSS JOIN defaults d WHERE c.id = d.currency_id) AS currency
           FROM parts p
           LEFT JOIN parts_ext pe ON pe.parts_id = p.id
          WHERE p.id = :parts_id",
        [':parts_id' => $partsId]
    );

    return $zeile ?: null;
}

/**
 * Angaben zu einem Kauf
 *
 * Über den Rechnungslink, nicht über die Sitzung: die Bestätigungsseite wird
 * auch von einem Gast geöffnet, der nicht angemeldet ist.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $arLink Kennung aus ar_link_hugoshop
 * @return array|null
 */
function analyticsPurchase($db, string $arLink): ?array {
    $zeile = $db->getOne(
        "SELECT ar.invnumber AS transaction_id, TRUNC(ar.amount, 2) AS value,
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency
           FROM ar_link_hugoshop al JOIN ar ON ar.id = al.ar_id
          WHERE al.uuid = :ar_link",
        [':ar_link' => $arLink]
    );

    return $zeile ?: null;
}

/**
 * Gekaufte Artikel und Kaufangaben
 *
 * Die Versandkosten stehen als eigener Betrag daneben, nicht als Artikel —
 * so erwarten es die Auswertungswerkzeuge. Die Bridge las dafür zweimal und
 * filterte in PHP; hier erledigt das eine Abfrage.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $arLink Kennung aus ar_link_hugoshop
 * @return array{purchased: array|null, products: array}
 */
function analyticsPurchaseItems($db, string $arLink): array {
    $versandNr = shopConfigValue($db, 'shop_shipping_partnumber');

    $zeilen = $db->getAll(
        "SELECT p.partnumber AS id, i.description AS name,
                pe.hugoshop_category AS category,
                TRUNC(i.qty) AS quantity, TRUNC(i.sellprice, 2) AS price,
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency,
                (p.partnumber = :versand_nr) AS ist_versand
           FROM ar_link_hugoshop al
           JOIN ar ON ar.id = al.ar_id
           JOIN invoice i ON i.trans_id = ar.id
           JOIN parts p ON p.id = i.parts_id
           LEFT JOIN parts_ext pe ON pe.parts_id = i.parts_id
          WHERE al.uuid = :ar_link
          ORDER BY i.position",
        [':ar_link' => $arLink, ':versand_nr' => $versandNr]
    );

    $artikel = [];
    $versand = 0.0;
    foreach ($zeilen as $zeile) {
        if (in_array($zeile['ist_versand'], ['t', true, 1, '1'], true)) {
            $versand = (float)$zeile['price'];
            continue;
        }
        unset($zeile['ist_versand']);
        $artikel[] = $zeile;
    }

    $kauf = analyticsPurchase($db, $arLink);
    if (null !== $kauf) {
        $kauf['shipping'] = $versand;
    }

    return ['purchased' => $kauf, 'products' => $artikel];
}
