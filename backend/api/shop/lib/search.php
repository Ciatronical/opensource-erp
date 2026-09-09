<?php
// backend/api/shop/lib/search.php
//
// Die Artikelsuche des Shops. Sie durchsucht Bezeichnung, Artikelnummer,
// Kategorie und Navigationspfad und gewichtet das Ergebnis auf Wunsch mit dem
// Preis — teurere Artikel stehen dann weiter oben.
//
// Der Suchindex entsteht zur Laufzeit (to_tsvector in der Abfrage). Das
// Schema traegt dafuer eine vorbereitete, aber nicht in Betrieb genommene
// Alternative mit gespeicherter tsvector-Spalte; solange der Bestand klein
// bleibt, ist der Laufzeit-Index einfacher zu pflegen.

/**
 * Artikelsuche im Shop
 *
 * Sucht mit Praefix-Treffern: "brem" findet "Bremsscheibe". Die Suchbegriffe
 * werden dafuer auf Buchstaben und Ziffern reduziert, bevor sie in den
 * tsquery gehen — sonst laesst sich die Abfrage mit einem Anfuehrungszeichen
 * oder einem Operatorzeichen zum Scheitern bringen.
 *
 * Laesst sich ersetzen: ist eine Funktion shopSearchImplementation()
 * definiert, wird sie anstelle der eingebauten Suche gerufen. Die Bridge
 * loeste das ueber eine Schnittstelle und eine Konstante in der
 * Instanz-Konfiguration.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $begriffe Sucheingabe des Besuchers
 * @param int $limit Hoechstzahl der Treffer
 * @param int $offset Zu ueberspringende Treffer
 * @return array Liste aus partnumber, description, image, hyperlink, breadcrumbs
 */
function shopSearch($db, string $begriffe, int $limit = 5, int $offset = 0): array {
    if (function_exists('shopSearchImplementation')) {
        return shopSearchImplementation($db, $begriffe, $limit, $offset);
    }

    $tsquery = shopSearchQuery($begriffe);
    if ('' === $tsquery) {
        return [];
    }

    $gewicht      = shopConfigFloat($db, 'shop_search_weighting', 0.5);
    $artikelLink  = shopConfigValue($db, 'shop_products_link');
    $bildLink     = shopConfigValue($db, 'shop_thumbnails_link');

    $treffer = $db->getAll(
        "WITH gefunden AS (
             SELECT p.id, p.partnumber, p.description, p.sellprice,
                    psh.hugoshop_breadcrumbs AS breadcrumbs,
                    psh.hugoshop_category    AS category,
                    psh.hugoshop_hyperlink   AS hyperlink,
                    psh.hugoshop_images ->> 0 AS image,
                    ts_rank(
                        to_tsvector('simple',
                            p.description || ' ' || p.partnumber || ' ' ||
                            COALESCE(psh.hugoshop_breadcrumbs::text, '') || ' ' ||
                            COALESCE(psh.hugoshop_category, '')),
                        to_tsquery('simple', :begriffe)
                    ) AS rang
               FROM parts p
               JOIN parts_ext psh ON psh.parts_id = p.id
              WHERE to_tsvector('simple',
                        p.description || ' ' || p.partnumber || ' ' ||
                        COALESCE(psh.hugoshop_breadcrumbs::text, '') || ' ' ||
                        COALESCE(psh.hugoshop_category, ''))
                    @@ to_tsquery('simple', :begriffe)
         )
         SELECT id, partnumber, description, sellprice, breadcrumbs, category, hyperlink, image
           FROM gefunden
          ORDER BY rang * POWER(LOG(sellprice + 1), :gewicht) DESC, description
          LIMIT :limit OFFSET :offset",
        [
            ':begriffe' => $tsquery,
            ':gewicht'  => $gewicht,
            ':limit'    => $limit,
            ':offset'   => $offset,
        ]
    );

    return array_map(function ($zeile) use ($artikelLink, $bildLink) {
        return [
            'id'          => (int)$zeile['id'],
            'partnumber'  => mb_strtolower((string)$zeile['partnumber']),
            'description' => mb_strtolower((string)$zeile['description']),
            'category'    => $zeile['category'],
            'breadcrumbs' => json_decode((string)$zeile['breadcrumbs'], true) ?: [],
            'hyperlink'   => shopSearchFormatLink($artikelLink, mb_strtolower((string)$zeile['hyperlink']).'#focus'),
            'image'       => $zeile['image'] ? shopSearchFormatLink($bildLink, (string)$zeile['image']) : '',
        ];
    }, $treffer);
}

/**
 * Baut aus der Sucheingabe einen tsquery mit Praefix-Treffern
 *
 * Jedes Wort wird auf Buchstaben, Ziffern und Bindestrich reduziert und mit
 * ":*" versehen; die Woerter werden mit "|" verknuepft, damit auch ein
 * Teiltreffer zaehlt.
 *
 * @param string $begriffe Sucheingabe
 * @return string tsquery-Ausdruck, leer wenn nichts Verwertbares uebrig bleibt
 */
function shopSearchQuery(string $begriffe): string {
    $woerter = preg_split('/\s+/u', trim($begriffe), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $teile = [];
    foreach ($woerter as $wort) {
        $sauber = preg_replace('/[^\p{L}\p{N}\-]/u', '', $wort);
        if ('' !== $sauber) {
            $teile[] = $sauber.':*';
        }
    }

    return implode(' | ', $teile);
}

/**
 * Setzt einen Wert in ein Adressmuster ein
 *
 * Ohne Muster bleibt der Wert stehen — dann baut die Oberflaeche die Adresse
 * selbst zusammen.
 *
 * @param string $muster Adressmuster mit %s, oder leer
 * @param string $wert Einzusetzender Wert
 * @return string
 */
function shopSearchFormatLink(string $muster, string $wert): string {
    if ('' === $muster || false === strpos($muster, '%s')) {
        return $wert;
    }
    return sprintf($muster, $wert);
}
