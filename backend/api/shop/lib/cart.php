<?php
// backend/api/shop/lib/cart.php
//
// Der Warenkorb haengt an einer UUID, nicht am Kunden: er entsteht, bevor
// jemand angemeldet ist. Meldet sich der Besucher spaeter an, fuehrt
// cartMergeIntoCustomerCart() den Gastkorb mit einem vorhandenen Kundenkorb
// zusammen.
//
// Preise kommen als Zahlen, nicht als formatierte Zeichenketten. Die Bridge
// formatierte in PHP (formatPrice); in OpensourceERP formatiert die
// Oberflaeche. Fuer das shop-ui heisst das: die Anzeige uebernimmt die
// Formatierung, siehe dev/shop-migration.md.

/**
 * Warenkorb-Kennung des Kontextes, notfalls neu angelegt
 *
 * Ein Besucher bekommt seinen Warenkorb erst, wenn er etwas hineinlegt —
 * deshalb legt diese Funktion an, statt einen leeren Korb vorzuhalten.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return string Warenkorb-Kennung
 * @throws ApiError SHOP_CONTEXT_ERROR wenn der Kontext nicht existiert
 */
function cartOfContext($db, string $uuid): string {
    $context = shopContextRequire($db, $uuid);
    if (!empty($context['cart_uuid'])) {
        return $context['cart_uuid'];
    }

    // Anlegen und anhaengen in einem Vorgang: der neue Korb uebernimmt den
    // Kunden des Kontextes, damit er beim Abmelden nicht aufgeraeumt wird.
    $zeile = $db->getOne(
        "WITH neu AS (
             INSERT INTO carts_hugoshop (uuid, customer_id, active)
             SELECT :cart_uuid, con.customer_id, NOW()
               FROM context_hugoshop con WHERE con.uuid = :uuid
             RETURNING uuid
         )
         UPDATE context_hugoshop SET cart_uuid = (SELECT uuid FROM neu)
          WHERE uuid = :uuid
         RETURNING cart_uuid",
        [':cart_uuid' => shopNewContextUuid(), ':uuid' => $uuid]
    );

    if (!$zeile || empty($zeile['cart_uuid'])) {
        throw new ApiError("SHOP_CONTEXT_ERROR", 'Warenkorb konnte nicht angelegt werden');
    }
    return $zeile['cart_uuid'];
}

/**
 * Summen des Warenkorbs
 *
 * Rechnet mit den Steuerschluesseln des Artikels, nicht mit einem festen
 * Satz: jede Position bringt ueber ihre Buchungsgruppe ihr Erloes- und
 * Steuerkonto mit.
 *
 * Bei leerem Warenkorb kommen Nullen zurueck. Die Bridge lieferte dort NULL,
 * und NULL <= Mindestumsatz ist in PHP wahr — so entstand eine
 * PayPal-Bestellung ueber 7,90 EUR Versand fuer einen Korb ohne Ware.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $cartUuid Warenkorb-Kennung
 * @param float $precision Rundungsschritt aus den Mandanten-Vorgaben
 * @param int $taxzoneId Steuerzone des Kunden
 * @return array{total_sum: float, netto_total_sum: float, shipping_costs: float,
 *               inc_shipping_costs: bool, total_sum_inc_shipping: float,
 *               netto_total_sum_inc_shipping: float}
 */
function cartTotals($db, string $cartUuid, float $precision, int $taxzoneId): array {
    $versandNr  = shopConfigValue($db, 'shop_shipping_partnumber');
    $freiAb     = shopConfigFloat($db, 'shop_free_shipping_from');

    // Gerundet wird je Position, danach summiert. Sonst weicht die Summe von
    // der Addition der angezeigten Positionsbetraege ab.
    //
    // precision ist der Rundungsschritt des Mandanten (0.01 = Cent). Die
    // Bridge schrieb ROUND(x / precision, 2) * precision — das rundet eine
    // bereits ganze Zahl auf zwei Stellen und laesst den Schritt wirkungslos.
    // Richtig ist ROUND(x / precision) * precision.
    $zeile = $db->getOne(
        "WITH letzte_steuerschluessel AS (
             -- je Konto der zuletzt gueltige Steuerschluessel
             SELECT DISTINCT ON (tk.chart_id) tk.tax_id, tk.chart_id
               FROM taxkeys tk
              ORDER BY tk.chart_id, tk.startdate DESC
         ), versand AS (
             -- der Versandartikel mit seinem eigenen Steuersatz, nicht mit
             -- dem hoechsten des Warenkorbs
             SELECT p.sellprice, COALESCE(t.rate, 0) AS rate
               FROM parts p
               LEFT JOIN taxzone_charts tc ON tc.buchungsgruppen_id = p.buchungsgruppen_id
                                          AND tc.taxzone_id = :taxzone_id
               LEFT JOIN letzte_steuerschluessel tk ON tk.chart_id = tc.income_accno_id
               LEFT JOIN tax t ON t.id = tk.tax_id
              WHERE p.partnumber = :versand_nr
         ), positionen AS (
             SELECT ROUND(ps.sellprice * cps.amount * (1 + COALESCE(tax.rate, 0)) / :precision) * :precision AS brutto,
                    ROUND(ps.sellprice * cps.amount / :precision) * :precision AS netto
               FROM cart_parts_hugoshop cps
               JOIN parts ps ON ps.id = cps.parts_id
               JOIN taxzone_charts tc ON tc.buchungsgruppen_id = ps.buchungsgruppen_id
                                     AND tc.taxzone_id = :taxzone_id
               LEFT JOIN letzte_steuerschluessel tk ON tk.chart_id = tc.income_accno_id
               LEFT JOIN tax ON tax.id = tk.tax_id
              WHERE cps.cart_uuid = :cart_uuid
         ), summen AS (
             SELECT COALESCE(SUM(brutto), 0) AS total_sum,
                    COALESCE(SUM(netto), 0)  AS netto_total_sum,
                    COUNT(*) AS anzahl
               FROM positionen
         )
         SELECT s.total_sum,
                s.netto_total_sum,
                COALESCE((SELECT sellprice FROM versand), 0) AS shipping_costs,
                (s.anzahl > 0 AND s.total_sum <= :frei_ab) AS inc_shipping_costs,
                s.total_sum + COALESCE(
                    (SELECT ROUND(sellprice * (1 + rate) / :precision) * :precision FROM versand), 0
                ) AS total_sum_inc_shipping,
                s.netto_total_sum + COALESCE((SELECT sellprice FROM versand), 0) AS netto_total_sum_inc_shipping
           FROM summen s",
        [
            ':cart_uuid'  => $cartUuid,
            ':versand_nr' => $versandNr,
            ':frei_ab'    => $freiAb,
            ':precision'  => $precision,
            ':taxzone_id' => $taxzoneId,
        ]
    );

    return [
        'total_sum'                    => (float)($zeile['total_sum'] ?? 0),
        'netto_total_sum'              => (float)($zeile['netto_total_sum'] ?? 0),
        'shipping_costs'               => (float)($zeile['shipping_costs'] ?? 0),
        'inc_shipping_costs'           => in_array($zeile['inc_shipping_costs'] ?? 'f', ['t', true, 1, '1'], true),
        'total_sum_inc_shipping'       => (float)($zeile['total_sum_inc_shipping'] ?? 0),
        'netto_total_sum_inc_shipping' => (float)($zeile['netto_total_sum_inc_shipping'] ?? 0),
    ];
}

/**
 * Inhalt des Warenkorbs
 *
 * Zwei Auspraegungen. Die schlanke reicht der Oberflaeche: Bezeichnung,
 * Menge, Preise, Vorschaubild. Die ausfuehrliche traegt zusaetzlich die
 * Buchungsangaben je Position und wird von der Rechnungsstellung gebraucht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $cartUuid Warenkorb-Kennung
 * @param int|null $customerId Kunde, oder null fuer die Mandanten-Vorgaben
 * @param bool $simple true => schlanke Auspraegung
 * @return array{positions: array, totalSum: float, ...}
 */
function cartRead($db, string $cartUuid, ?int $customerId, bool $simple = true): array {
    $stammdaten = shopInvoicingData($db, $customerId);
    $precision  = (float)$stammdaten['precision'];
    $taxzoneId  = (int)$stammdaten['taxzone_id'];

    if ($simple) {
        $positionen = $db->getAll(
            "SELECT cps.id AS pos_id, ps.id AS parts_id, ps.description, ps.unit, cps.amount,
                    ROUND(ps.sellprice / :precision) * :precision AS unit_price,
                    ROUND(ps.sellprice * cps.amount / :precision) * :precision AS total_price,
                    ps.buchungsgruppen_id,
                    psh.hugoshop_images ->> 0 AS thumbnail
               FROM cart_parts_hugoshop cps
               JOIN parts ps ON ps.id = cps.parts_id
               LEFT JOIN parts_ext psh ON psh.parts_id = ps.id
              WHERE cps.cart_uuid = :cart_uuid
              ORDER BY cps.id",
            [':cart_uuid' => $cartUuid, ':precision' => $precision]
        );
    } else {
        $positionen = $db->getAll(
            "WITH letzte_steuerschluessel AS (
                 SELECT DISTINCT ON (tk.chart_id) tk.tax_id, tk.chart_id
                   FROM taxkeys tk
                  ORDER BY tk.chart_id, tk.startdate DESC
             )
             SELECT cps.id AS pos_id, ps.id AS parts_id, ps.description, ps.unit, cps.amount,
                    ROUND(ps.sellprice / :precision) * :precision AS unit_price,
                    ROUND(ps.sellprice * cps.amount / :precision) * :precision AS total_price,
                    ps.buchungsgruppen_id,
                    tc.income_accno_id, tk.tax_id,
                    ch_income.taxkey_id AS income_taxkey, ch_income.link AS income_link,
                    tax.chart_id AS taxservice_accno_id, tax.rate AS tax_rate,
                    ch_tax.link AS taxservice_link,
                    psh.hugoshop_images ->> 0 AS thumbnail
               FROM cart_parts_hugoshop cps
               JOIN parts ps ON ps.id = cps.parts_id
               JOIN taxzone_charts tc ON tc.buchungsgruppen_id = ps.buchungsgruppen_id
                                     AND tc.taxzone_id = :taxzone_id
               LEFT JOIN letzte_steuerschluessel tk ON tk.chart_id = tc.income_accno_id
               LEFT JOIN tax ON tax.id = tk.tax_id
               LEFT JOIN chart ch_income ON ch_income.id = tc.income_accno_id
               LEFT JOIN chart ch_tax ON ch_tax.id = tax.chart_id
               LEFT JOIN parts_ext psh ON psh.parts_id = ps.id
              WHERE cps.cart_uuid = :cart_uuid
              ORDER BY cps.id",
            [':cart_uuid' => $cartUuid, ':precision' => $precision, ':taxzone_id' => $taxzoneId]
        );
    }

    $summen = cartTotals($db, $cartUuid, $precision, $taxzoneId);

    $daten = [
        'positions'                => array_map(fn($p) => cartPositionShape($p, $simple), $positionen),
        'totalSum'                 => $summen['total_sum'],
        'nettoTotalSum'            => $summen['netto_total_sum'],
        'shippingCosts'            => $summen['shipping_costs'],
        'incShippingCosts'         => $summen['inc_shipping_costs'],
        'totalSumIncShipping'      => $summen['total_sum_inc_shipping'],
        'nettoTotalSumIncShipping' => $summen['netto_total_sum_inc_shipping'],
        'currency'                 => $stammdaten['currency'],
    ];

    if (!$simple) {
        $daten['customer'] = [
            'customer_id' => $customerId,
            'taxzone_id'  => $taxzoneId,
            'currency_id' => $stammdaten['currency_id'],
            'name'        => $stammdaten['name']  ?? null,
            'email'       => $stammdaten['email'] ?? null,
        ];
    }

    return $daten;
}

/**
 * Bringt eine Warenkorbzeile in die Form, die der Aufrufer erwartet
 *
 * @param array $p Zeile aus cartRead
 * @param bool $simple Auspraegung
 * @return array
 */
function cartPositionShape(array $p, bool $simple): array {
    $position = [
        'id'                 => (int)$p['pos_id'],
        'referencedId'       => (int)$p['parts_id'],
        'label'              => $p['description'],
        'unit'               => $p['unit'],
        'quantity'           => (int)$p['amount'],
        'unitPrice'          => (float)$p['unit_price'],
        'totalPrice'         => (float)$p['total_price'],
        'buchungsgruppen_id' => $p['buchungsgruppen_id'],
        'thumbnail'          => $p['thumbnail'],
    ];

    if (!$simple) {
        $position += [
            'tax_id'               => $p['tax_id'],
            'tax_rate'             => (float)$p['tax_rate'],
            'income_accno_id'      => $p['income_accno_id'],
            'income_taxkey'        => $p['income_taxkey'],
            'income_link'          => $p['income_link'],
            'taxservice_accno_id'  => $p['taxservice_accno_id'],
            'taxservice_link'      => $p['taxservice_link'],
        ];
    }

    return $position;
}

/**
 * Legt einen Artikel in den Warenkorb
 *
 * Liegt der Artikel schon darin, wird die Menge erhoeht. Das entscheidet die
 * Datenbank ueber den eindeutigen Index auf (cart_uuid, parts_id) — die
 * Bridge suchte erst und entschied dann, wobei zwei gleichzeitige Anfragen
 * zwei Zeilen anlegen konnten.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param int $partsId Artikel
 * @param int $menge Anzahl, mindestens 1
 * @return array{cartPosCount: int}
 * @throws ApiError SHOP_CONTEXT_ERROR, INVALID_QUANTITY, PART_NOT_FOUND
 */
function cartAdd($db, string $uuid, int $partsId, int $menge): array {
    if (1 > $menge) {
        throw new ApiError("INVALID_QUANTITY", 'Die Menge muss mindestens 1 sein');
    }

    $cartUuid = cartOfContext($db, $uuid);

    $eingefuegt = $db->getOne(
        "INSERT INTO cart_parts_hugoshop (cart_uuid, parts_id, amount)
         SELECT :cart_uuid, p.id, :menge FROM parts p WHERE p.id = :parts_id
         ON CONFLICT (cart_uuid, parts_id)
         DO UPDATE SET amount = cart_parts_hugoshop.amount + EXCLUDED.amount
         RETURNING id",
        [':cart_uuid' => $cartUuid, ':parts_id' => $partsId, ':menge' => $menge]
    );

    // Ohne Treffer in parts bleibt das INSERT wirkungslos — den Artikel gibt
    // es nicht. Stillschweigend nichts zu tun waere die schlechtere Antwort.
    if (!$eingefuegt) {
        throw new ApiError("PART_NOT_FOUND", 'Diesen Artikel gibt es nicht');
    }

    // Getrennte Abfrage, nicht im selben CTE: alle Zweige einer Anweisung
    // lesen denselben Stand, die Zaehlung saehe den Warenkorb also so, wie er
    // vor dem Einfuegen aussah.
    $anzahl = $db->getOne(
        "SELECT COUNT(*) AS anzahl FROM cart_parts_hugoshop WHERE cart_uuid = :cart_uuid",
        [':cart_uuid' => $cartUuid]
    );

    return ['cartPosCount' => (int)$anzahl['anzahl']];
}

/**
 * Entfernt eine Position aus dem Warenkorb
 *
 * Die Warenkorb-Kennung gehoert in die Bedingung: ohne sie loescht eine
 * fremde Positionsnummer die Position eines anderen Warenkorbs.
 *
 * Zweimal loeschen (Doppelklick) ist kein Fehler.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $cartUuid Warenkorb-Kennung
 * @param int|null $customerId Kunde, oder null fuer die Mandanten-Vorgaben
 * @param int $posId Positionsnummer
 * @return array Warenkorb-Zusammenfassung nach dem Loeschen
 */
function cartRemovePos($db, string $cartUuid, ?int $customerId, int $posId): array {
    $db->execute(
        "DELETE FROM cart_parts_hugoshop WHERE id = :pos_id AND cart_uuid = :cart_uuid",
        [':pos_id' => $posId, ':cart_uuid' => $cartUuid]
    );

    return ['pos' => $posId] + cartSummary($db, $cartUuid, $customerId);
}

/**
 * Setzt die Menge einer Position
 *
 * Menge 0 entfernt die Position — sonst bliebe eine Zeile stehen, die in
 * jeder Summe mit 0 mitrechnet.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $cartUuid Warenkorb-Kennung
 * @param int|null $customerId Kunde, oder null fuer die Mandanten-Vorgaben
 * @param int $posId Positionsnummer
 * @param int $menge Neue Anzahl
 * @return array Position und Warenkorb-Zusammenfassung
 * @throws ApiError CART_POS_NOT_FOUND, INVALID_QUANTITY
 */
function cartSetQuantity($db, string $cartUuid, ?int $customerId, int $posId, int $menge): array {
    if (0 > $menge) {
        throw new ApiError("INVALID_QUANTITY", 'Die Menge darf nicht negativ sein');
    }
    if (0 === $menge) {
        return cartRemovePos($db, $cartUuid, $customerId, $posId);
    }

    $stammdaten = shopInvoicingData($db, $customerId);
    $precision  = (float)$stammdaten['precision'];

    $zeile = $db->getOne(
        "WITH geaendert AS (
             UPDATE cart_parts_hugoshop SET amount = :menge
              WHERE id = :pos_id AND cart_uuid = :cart_uuid
             RETURNING id, parts_id, amount
         )
         SELECT g.id, g.amount,
                ROUND(ps.sellprice / :precision) * :precision AS unit_price,
                ROUND(ps.sellprice * g.amount / :precision) * :precision AS total_price
           FROM geaendert g JOIN parts ps ON ps.id = g.parts_id",
        [':menge' => $menge, ':pos_id' => $posId, ':cart_uuid' => $cartUuid, ':precision' => $precision]
    );

    // Gehoert die Position nicht zu diesem Warenkorb, ist die Antwort sonst
    // halb leer — Kennung und Menge aus einem Datensatz, den es nicht gibt.
    if (!$zeile) {
        throw new ApiError("CART_POS_NOT_FOUND", 'Diese Position gehoert nicht zu diesem Warenkorb');
    }

    return [
        'id'         => (int)$zeile['id'],
        'quantity'   => (int)$zeile['amount'],
        'unitPrice'  => (float)$zeile['unit_price'],
        'totalPrice' => (float)$zeile['total_price'],
    ] + cartSummary($db, $cartUuid, $customerId);
}

/**
 * Summen und Positionszahl eines Warenkorbs
 *
 * Gemeinsamer Teil der Antworten nach jeder Aenderung.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $cartUuid Warenkorb-Kennung
 * @param int|null $customerId Kunde, oder null fuer die Mandanten-Vorgaben
 * @return array
 */
function cartSummary($db, string $cartUuid, ?int $customerId): array {
    $stammdaten = shopInvoicingData($db, $customerId);
    $summen = cartTotals($db, $cartUuid, (float)$stammdaten['precision'], (int)$stammdaten['taxzone_id']);

    $anzahl = $db->getOne(
        "SELECT COUNT(*) AS anzahl FROM cart_parts_hugoshop WHERE cart_uuid = :cart_uuid",
        [':cart_uuid' => $cartUuid]
    );

    return [
        'cartPosCount'             => (int)($anzahl['anzahl'] ?? 0),
        'totalSum'                 => $summen['total_sum'],
        'nettoTotalSum'            => $summen['netto_total_sum'],
        'shippingCosts'            => $summen['shipping_costs'],
        'incShippingCosts'         => $summen['inc_shipping_costs'],
        'totalSumIncShipping'      => $summen['total_sum_inc_shipping'],
        'nettoTotalSumIncShipping' => $summen['netto_total_sum_inc_shipping'],
    ];
}

/**
 * Ergaenzt die Versandkosten, wenn der Warenkorbwert darunter liegt
 *
 * Drei Bedingungen, alle in der Datenbank geprueft: der Warenkorbwert liegt
 * unter der Grenze, es ist ausser dem Versand ueberhaupt Ware im Korb, und
 * der Versand liegt noch nicht darin.
 *
 * Der zweite und dritte Punkt fehlten in der Bridge. Ohne sie entstand eine
 * PayPal-Bestellung ueber reine Versandkosten fuer einen leeren Warenkorb,
 * und ein zweiter Aufruf machte aus dem Versand zwei.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $cartUuid Warenkorb-Kennung
 * @param int|null $customerId Kunde, oder null fuer die Mandanten-Vorgaben
 * @return bool true wenn die Versandkosten ergaenzt wurden
 */
function cartApplyShipping($db, string $cartUuid, ?int $customerId): bool {
    $versandNr = shopConfigValue($db, 'shop_shipping_partnumber');
    if ('' === $versandNr) {
        return false;
    }

    $stammdaten = shopInvoicingData($db, $customerId);
    $summen = cartTotals($db, $cartUuid, (float)$stammdaten['precision'], (int)$stammdaten['taxzone_id']);

    if (!$summen['inc_shipping_costs']) {
        return false;
    }

    $zeile = $db->getOne(
        "INSERT INTO cart_parts_hugoshop (cart_uuid, parts_id, amount)
         SELECT :cart_uuid, p.id, 1
           FROM parts p
          WHERE p.partnumber = :versand_nr
            AND EXISTS (SELECT 1 FROM cart_parts_hugoshop c
                         WHERE c.cart_uuid = :cart_uuid AND c.parts_id <> p.id)
         ON CONFLICT (cart_uuid, parts_id) DO NOTHING
         RETURNING id",
        [':cart_uuid' => $cartUuid, ':versand_nr' => $versandNr]
    );

    return (bool)$zeile;
}

/**
 * Nimmt die Versandkosten wieder heraus
 *
 * Gebraucht, wenn der Kunde die Bezahlung abbricht und zurueck in den
 * Warenkorb geht: der Versand wird beim naechsten Anlauf neu bestimmt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $cartUuid Warenkorb-Kennung
 * @return void
 */
function cartRemoveShipping($db, string $cartUuid): void {
    $versandNr = shopConfigValue($db, 'shop_shipping_partnumber');
    if ('' === $versandNr) {
        return;
    }

    $db->execute(
        "DELETE FROM cart_parts_hugoshop
          WHERE cart_uuid = :cart_uuid
            AND parts_id IN (SELECT id FROM parts WHERE partnumber = :versand_nr)",
        [':cart_uuid' => $cartUuid, ':versand_nr' => $versandNr]
    );
}

/**
 * Leert den Warenkorb
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $cartUuid Warenkorb-Kennung
 * @return void
 */
function cartClear($db, string $cartUuid): void {
    $db->execute(
        "DELETE FROM cart_parts_hugoshop WHERE cart_uuid = :cart_uuid",
        [':cart_uuid' => $cartUuid]
    );
}

/**
 * Fuehrt den Gastkorb mit dem Warenkorb des Kunden zusammen
 *
 * Wird beim Anmelden gerufen. Drei Faelle, alle in einem Vorgang:
 *
 *   kein Kundenkorb        der Gastkorb wird zum Kundenkorb
 *   kein Gastkorb          der Kundenkorb wird an den Kontext gehaengt
 *   beide vorhanden        Mengen addieren, Gastkorb loeschen
 *
 * Beim Zusammenfuehren entscheidet der eindeutige Index ueber
 * (cart_uuid, parts_id), ob eine Position addiert oder angelegt wird.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param int $customerId Angemeldeter Kunde
 * @return void
 */
function cartMergeIntoCustomerCart($db, string $uuid, int $customerId): void {
    $context = shopContextRequire($db, $uuid);
    $gastKorb = $context['cart_uuid'] ?? null;

    $kundenKorb = $db->getOne(
        "SELECT uuid FROM carts_hugoshop
          WHERE customer_id = :customer_id AND (:gast_korb::text IS NULL OR uuid <> :gast_korb)
          ORDER BY active DESC LIMIT 1",
        [':customer_id' => $customerId, ':gast_korb' => $gastKorb]
    );
    $kundenKorb = $kundenKorb['uuid'] ?? null;

    // Kein Kundenkorb: der mitgebrachte Korb gehoert jetzt dem Kunden.
    if (null === $kundenKorb) {
        if (null !== $gastKorb) {
            $db->execute(
                "UPDATE carts_hugoshop SET customer_id = :customer_id WHERE uuid = :cart_uuid",
                [':customer_id' => $customerId, ':cart_uuid' => $gastKorb]
            );
        }
        return;
    }

    // Kein mitgebrachter Korb: den vorhandenen anhaengen.
    if (null === $gastKorb) {
        $db->execute(
            "UPDATE context_hugoshop SET cart_uuid = :cart_uuid WHERE uuid = :uuid",
            [':cart_uuid' => $kundenKorb, ':uuid' => $uuid]
        );
        return;
    }

    // Beide: Positionen uebertragen, Kontext umhaengen, Gastkorb loeschen.
    $db->execute(
        "INSERT INTO cart_parts_hugoshop (cart_uuid, parts_id, amount)
         SELECT :kunden_korb, parts_id, amount
           FROM cart_parts_hugoshop WHERE cart_uuid = :gast_korb
         ON CONFLICT (cart_uuid, parts_id)
         DO UPDATE SET amount = cart_parts_hugoshop.amount + EXCLUDED.amount",
        [':kunden_korb' => $kundenKorb, ':gast_korb' => $gastKorb]
    );

    $db->execute(
        "UPDATE context_hugoshop SET cart_uuid = :kunden_korb WHERE uuid = :uuid",
        [':kunden_korb' => $kundenKorb, ':uuid' => $uuid]
    );

    // Der Gastkorb haengt an keinem Kontext mehr; seine Positionen sind
    // uebertragen. ON DELETE CASCADE raeumt sie mit weg.
    $db->execute(
        "DELETE FROM carts_hugoshop WHERE uuid = :gast_korb",
        [':gast_korb' => $gastKorb]
    );
}
