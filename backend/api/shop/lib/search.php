<?php
// backend/api/shop/lib/search.php
//
// Die Artikelsuche des Shops. Sie durchsucht Bezeichnung, Artikelnummer,
// Kanaltitel, Kategorie, Navigationspfad und die zusätzlichen Suchwörter des
// Artikels (z. B. OE-Nummern) und gewichtet das Ergebnis auf Wunsch mit dem
// Preis — teurere Artikel stehen dann weiter oben.
//
// Der Suchindex steht je Artikel in parts_ext.hugoshop_search (GIN-Index,
// von Triggern gepflegt, shop_part_search_vector im Schema). Nur für Artikel
// ohne Zeile in parts_ext entsteht er noch zur Laufzeit.

/**
 * Artikelsuche im Shop
 *
 * Sucht mit Praefix-Treffern: "brem" findet "Bremsscheibe". Die Suchbegriffe
 * werden dafuer auf Buchstaben und Ziffern reduziert, bevor sie in den
 * tsquery gehen — sonst laesst sich die Abfrage mit einem Anfuehrungszeichen
 * oder einem Operatorzeichen zum Scheitern bringen.
 *
 * Laesst sich ersetzen: ist eine Funktion shopSearchImplementation()
 * definiert, wird sie anstelle der eingebauten Suche gerufen, mit dem Kanal
 * als fuenftem Argument. Die Bridge loeste das ueber eine Schnittstelle und
 * eine Konstante in der Instanz-Konfiguration.
 *
 * Gesucht wird im Sortiment des HugoShops der Anfrage, mit seinen Preisen
 * und Adressmustern.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop der Anfrage
 * @param string $begriffe Sucheingabe des Besuchers
 * @param int $limit Hoechstzahl der Treffer
 * @param int $offset Zu ueberspringende Treffer
 * @return array Liste aus partnumber, description, image, hyperlink, breadcrumbs
 */
function shopSearch($db, int $kanal, string $begriffe, int $limit = 5, int $offset = 0): array {
    if (function_exists('shopSearchImplementation')) {
        return shopSearchImplementation($db, $begriffe, $limit, $offset, $kanal);
    }

    $tsquery = shopSearchQuery($begriffe);
    if ('' === $tsquery) {
        return [];
    }
    // Alle Begriffe zugleich: solche Treffer stehen vor den Teiltreffern —
    // eine OE-Nummer in Gruppen („82 00 264 952“) fände sonst jeden Artikel,
    // in dem eine der Gruppen vorkommt, und der gesuchte ginge darin unter.
    $alle = shopSearchQuery($begriffe, '&');

    $gewicht      = shopConfigFloat($db, 'shop_search_weighting', 0.5);

    // Gesucht wird unter den Artikeln mit aktiver Zeile im HugoShop. Der
    // erste Arm nutzt den gespeicherten Index; der zweite fängt Artikel ohne
    // Zeile in parts_ext. Gewichtet wird mit dem Kanalpreis — nur unter den
    // besten Treffern (:vorauswahl): der Preis ist ein Funktionsaufruf je
    // Artikel, und ein kurzes Präfix trifft in einem großen Sortiment
    // Zehntausende.
    $treffer = $db->getAll(
        "WITH anfrage AS (
             SELECT to_tsquery('simple', :begriffe) AS q,
                    to_tsquery('simple', :alle) AS q_alle,
                    shop_active_channel_id(CAST(:kanal AS integer)) AS kanal
         ), ohne_zeile AS MATERIALIZED (
             -- erst die wenigen Artikel ohne parts_ext bestimmen, dann deren
             -- Index rechnen — sonst rechnete ihn der Planer für alle
             SELECT pc.parts_id, pc.title
               FROM anfrage a
               JOIN parts_channel_shop pc ON pc.channel_id = a.kanal AND pc.active
              WHERE NOT EXISTS (SELECT 1 FROM parts_ext x WHERE x.parts_id = pc.parts_id)
         ), gefunden AS (
             SELECT p.id, p.partnumber,
                    COALESCE(NULLIF(pc.title, ''), p.description) AS description,
                    psh.hugoshop_breadcrumbs AS breadcrumbs,
                    btrim(cs.name)           AS category,
                    psh.hugoshop_hyperlink   AS hyperlink,
                    psh.hugoshop_images ->> 0 AS image,
                    ts_rank(psh.hugoshop_search, a.q) AS rang,
                    psh.hugoshop_search @@ a.q_alle AS alle
               FROM anfrage a
               JOIN parts_ext psh ON psh.hugoshop_search @@ a.q
               JOIN parts p ON p.id = psh.parts_id
               JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.channel_id = a.kanal AND pc.active
               -- Kategorie aus dem Sortiment (dev/shop-sortiment.md)
               LEFT JOIN category_shop cs ON cs.id = psh.hugoshop_category_id
             UNION ALL
             SELECT p.id, p.partnumber,
                    COALESCE(NULLIF(pc.title, ''), p.description),
                    NULL, NULL, NULL, NULL,
                    ts_rank(v.suche, a.q),
                    v.suche @@ a.q_alle
               FROM anfrage a
               CROSS JOIN ohne_zeile pc
               JOIN parts p ON p.id = pc.parts_id
               -- derselbe Index wie der gespeicherte, nur ohne die Angaben aus parts_ext
               CROSS JOIN LATERAL (SELECT shop_part_search_vector(p.id, NULL, NULL, NULL) AS suche) v
              WHERE v.suche @@ a.q
         ), beste AS (
             SELECT * FROM gefunden ORDER BY alle DESC, rang DESC, description LIMIT :vorauswahl
         )
         SELECT g.id, g.partnumber, g.description, g.breadcrumbs, g.category, g.hyperlink, g.image
           FROM beste g
           CROSS JOIN LATERAL (SELECT shop_channel_price(g.id, CAST(:kanal_preis AS integer)) AS preis) k
          ORDER BY g.alle DESC, g.rang * POWER(LOG(GREATEST(k.preis, 0) + 1), :gewicht) DESC, g.description
          LIMIT :limit OFFSET :offset",
        [
            ':begriffe' => $tsquery,
            ':alle'     => $alle,
            ':kanal'       => $kanal,
            ':kanal_preis' => $kanal,
            ':gewicht'  => $gewicht,
            ':vorauswahl' => max(2000, $limit + $offset),
            ':limit'    => $limit,
            ':offset'   => $offset,
        ]
    );

    return array_map(function ($zeile) use ($db, $kanal) {
        return [
            'id'          => (int)$zeile['id'],
            'partnumber'  => (string)$zeile['partnumber'],
            'description' => (string)$zeile['description'],
            'category'    => $zeile['category'],
            'breadcrumbs' => json_decode((string)$zeile['breadcrumbs'], true) ?: [],
            // #focus hinter die fertige Adresse, nicht ins Muster — sonst
            // stünde es vor dem Schrägstrich am Ende von /produkt/%s/
            'hyperlink'   => shopChannelLink($db, $kanal, 'products_link',
                                             shopPageSlug((string)$zeile['hyperlink'], (string)$zeile['partnumber']), '#focus'),
            'image'       => shopChannelLink($db, $kanal, 'thumbnails_link', (string)$zeile['image']),
        ];
    }, $treffer);
}

/**
 * Baut aus der Sucheingabe einen tsquery mit Praefix-Treffern
 *
 * Jedes Wort wird auf Buchstaben, Ziffern und Bindestrich reduziert und mit
 * ":*" versehen; die Woerter werden mit "|" verknuepft, damit auch ein
 * Teiltreffer zaehlt — oder mit "&", wenn alle vorkommen sollen.
 *
 * @param string $begriffe Sucheingabe
 * @param string $verknuepfung "|" (eines der Wörter) oder "&" (alle)
 * @return string tsquery-Ausdruck, leer wenn nichts Verwertbares uebrig bleibt
 */
function shopSearchQuery(string $begriffe, string $verknuepfung = '|'): string {
    $woerter = preg_split('/\s+/u', trim($begriffe), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $teile = [];
    foreach ($woerter as $wort) {
        $sauber = preg_replace('/[^\p{L}\p{N}\-]/u', '', $wort);
        if ('' !== $sauber) {
            $teile[] = $sauber.':*';
        }
    }

    return implode('&' === $verknuepfung ? ' & ' : ' | ', $teile);
}
