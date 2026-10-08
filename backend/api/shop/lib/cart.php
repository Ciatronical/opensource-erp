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
    // Kunden des Kontextes, damit er beim Abmelden nicht aufgeraeumt wird,
    // und seinen HugoShop — Preise, Angebot und Versand gelten je Kanal.
    $zeile = $db->getOne(
        "WITH neu AS (
             INSERT INTO carts_hugoshop (uuid, customer_id, active, channel_id)
             SELECT :cart_uuid, con.customer_id, NOW(), con.channel_id
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
 * Summen des Warenkorbs, mit Versand
 *
 * Rechnet mit den Steuerschluesseln des Artikels, nicht mit einem festen
 * Satz: jede Position bringt ueber ihre Buchungsgruppe ihr Erloes- und
 * Steuerkonto mit.
 *
 * Bei leerem Warenkorb kommen Nullen zurueck. Die Bridge lieferte dort NULL,
 * und NULL <= Mindestumsatz ist in PHP wahr — so entstand eine
 * PayPal-Bestellung ueber 7,90 EUR Versand fuer einen Korb ohne Ware.
 *
 * Der Versand kommt aus shop_cart_shipping() (dev/shop-versand.md, Schritt 6):
 * Versandart und Preis nach Lieferland, Gewicht, Abmessungen, Zuordnung und
 * Freigrenze. Versandartikel zaehlen nie als Ware (shop_is_shipping_part).
 * Steuer und Rundung der Versandposition wie bei einer Warenposition, mit
 * dem Versandartikel der gewaehlten Versandart.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $cartUuid Warenkorb-Kennung
 * @param float $precision Rundungsschritt aus den Mandanten-Vorgaben
 * @param int $taxzoneId Steuerzone des Kunden
 * @param string $land Land der Lieferadresse (Freitext oder Code), leer = Mandantenland
 * @return array{total_sum: float, netto_total_sum: float, shipping_costs: float,
 *               inc_shipping_costs: bool, total_sum_inc_shipping: float,
 *               netto_total_sum_inc_shipping: float, shipping_status: string,
 *               shipping_method_id: ?int, shipping_method: string, shipping_parts_id: ?int,
 *               shipping_free: bool, shipping_account: bool}
 */
function cartTotals($db, string $cartUuid, float $precision, int $taxzoneId, string $land = ''): array {
    // Gerundet wird je Position, danach summiert. Sonst weicht die Summe von
    // der Addition der angezeigten Positionsbetraege ab.
    //
    // precision ist der Rundungsschritt des Mandanten (0.01 = Cent). Die
    // Bridge schrieb ROUND(x / precision, 2) * precision — das rundet eine
    // bereits ganze Zahl auf zwei Stellen und laesst den Schritt wirkungslos.
    // Richtig ist ROUND(x / precision) * precision.
    //
    // Der Preis kommt aus shop_channel_price() im Kanal des Warenkorbs und ist
    // netto oder brutto wie parts.sellprice laut shop_tax_included. Frueher wurde hier immer die
    // Steuer aufgeschlagen, bei Bruttopreisen also doppelt — so auch im
    // Betrag, den PayPal abbuchte. Der Versandpreis folgt derselben Regel.
    $zeile = $db->getOne(
        "WITH letzte_steuerschluessel AS (
             -- je Konto der heute gueltige Steuerschluessel — ein im Voraus
             -- eingetragener Satzwechsel greift erst ab seinem Datum (V24),
             -- wie auf der Produktseite und in der Buchung
             SELECT DISTINCT ON (tk.chart_id) tk.tax_id, tk.chart_id
               FROM taxkeys tk
              WHERE tk.startdate <= current_date
              ORDER BY tk.chart_id, tk.startdate DESC
         ), positionen AS (
             SELECT ROUND(CASE WHEN :brutto = 1 THEN k.preis ELSE k.preis * (1 + COALESCE(tax.rate, 0)) END
                          * cps.amount / :precision) * :precision AS brutto,
                    ROUND(CASE WHEN :brutto = 1 THEN k.preis / (1 + COALESCE(tax.rate, 0)) ELSE k.preis END
                          * cps.amount / :precision) * :precision AS netto
               FROM cart_parts_hugoshop cps
               JOIN carts_hugoshop kb ON kb.uuid = cps.cart_uuid
               JOIN parts ps ON ps.id = cps.parts_id
               CROSS JOIN LATERAL (SELECT shop_channel_price(ps.id, kb.channel_id) AS preis) k
               JOIN taxzone_charts tc ON tc.buchungsgruppen_id = ps.buchungsgruppen_id
                                     AND tc.taxzone_id = :taxzone_id
               LEFT JOIN letzte_steuerschluessel tk ON tk.chart_id = tc.income_accno_id
               LEFT JOIN tax ON tax.id = tk.tax_id
              WHERE cps.cart_uuid = :cart_uuid
                AND NOT shop_is_shipping_part(cps.parts_id)
         ), summen AS (
             SELECT COALESCE(SUM(brutto), 0) AS total_sum,
                    COALESCE(SUM(netto), 0)  AS netto_total_sum
               FROM positionen
         ), versand AS (
             SELECT v.*
               FROM summen s
               CROSS JOIN LATERAL shop_cart_shipping(:cart_uuid, :land, s.total_sum) v
         ), satz AS (
             -- Steuer und Erloeskonto des Versandartikels in der Steuerzone
             SELECT COALESCE(t.rate, 0) AS rate, tc.income_accno_id
               FROM versand v
               JOIN parts p ON p.id = v.parts_id
               LEFT JOIN taxzone_charts tc ON tc.buchungsgruppen_id = p.buchungsgruppen_id
                                          AND tc.taxzone_id = :taxzone_id
               LEFT JOIN letzte_steuerschluessel tk ON tk.chart_id = tc.income_accno_id
               LEFT JOIN tax t ON t.id = tk.tax_id
         ), betrag AS (
             SELECT ROUND(CASE WHEN :brutto = 1 THEN v.price ELSE v.price * (1 + COALESCE(st.rate, 0)) END
                          / :precision) * :precision AS brutto,
                    ROUND(CASE WHEN :brutto = 1 THEN v.price / (1 + COALESCE(st.rate, 0)) ELSE v.price END
                          / :precision) * :precision AS netto
               FROM versand v
               LEFT JOIN satz st ON true
         )
         SELECT s.total_sum,
                s.netto_total_sum,
                v.status AS shipping_status,
                v.shipping_method_id,
                v.description AS shipping_method,
                v.parts_id AS shipping_parts_id,
                v.free AS shipping_free,
                COALESCE(v.price, 0) AS shipping_costs,
                (v.status = 'ok' AND COALESCE(v.price, 0) > 0) AS inc_shipping_costs,
                s.total_sum + COALESCE(b.brutto, 0) AS total_sum_inc_shipping,
                s.netto_total_sum + COALESCE(b.netto, 0) AS netto_total_sum_inc_shipping,
                (v.parts_id IS NULL OR (SELECT income_accno_id FROM satz) IS NOT NULL) AS shipping_account
           FROM summen s
           CROSS JOIN versand v
           CROSS JOIN betrag b",
        [
            ':cart_uuid'  => $cartUuid,
            ':land'       => $land,
            ':precision'  => $precision,
            ':taxzone_id' => $taxzoneId,
            ':brutto'     => shopConfigBool($db, 'shop_tax_included') ? 1 : 0,
        ]
    );

    $wahr = fn($wert) => in_array($wert, ['t', true, 1, '1'], true);
    return [
        'total_sum'                    => (float)($zeile['total_sum'] ?? 0),
        'netto_total_sum'              => (float)($zeile['netto_total_sum'] ?? 0),
        'shipping_costs'               => (float)($zeile['shipping_costs'] ?? 0),
        'inc_shipping_costs'           => $wahr($zeile['inc_shipping_costs'] ?? 'f'),
        'total_sum_inc_shipping'       => (float)($zeile['total_sum_inc_shipping'] ?? 0),
        'netto_total_sum_inc_shipping' => (float)($zeile['netto_total_sum_inc_shipping'] ?? 0),
        'shipping_status'              => (string)($zeile['shipping_status'] ?? 'no_goods'),
        'shipping_method_id'           => isset($zeile['shipping_method_id']) ? (int)$zeile['shipping_method_id'] : null,
        'shipping_method'              => (string)($zeile['shipping_method'] ?? ''),
        'shipping_parts_id'            => isset($zeile['shipping_parts_id']) ? (int)$zeile['shipping_parts_id'] : null,
        'shipping_free'                => $wahr($zeile['shipping_free'] ?? 'f'),
        'shipping_account'             => $wahr($zeile['shipping_account'] ?? 't'),
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
 * @param string|null $land Land der Lieferadresse (Freitext oder Code); null =
 *                          Standard-Lieferadresse des Kunden (shopShippingCountry)
 * @return array{positions: array, totalSum: float, ...}
 */
function cartRead($db, string $cartUuid, ?int $customerId, bool $simple = true, ?string $land = null): array {
    $stammdaten = shopInvoicingData($db, $customerId);
    $precision  = (float)$stammdaten['precision'];
    $taxzoneId  = (int)$stammdaten['taxzone_id'];

    if ($simple) {
        $positionen = $db->getAll(
            "SELECT cps.id AS pos_id, ps.id AS parts_id,
                    COALESCE(NULLIF(pc.title, ''), ps.description) AS description, ps.unit, cps.amount,
                    ROUND(k.preis / :precision) * :precision AS unit_price,
                    ROUND(k.preis * cps.amount / :precision) * :precision AS total_price,
                    ps.buchungsgruppen_id,
                    psh.hugoshop_images ->> 0 AS thumbnail,
                    (pc.active AND shop_active_channel_id(kb.channel_id) IS NOT NULL
                         AND shop_part_available(ps.id, kb.channel_id)) IS TRUE AS offered,
                    pss.min_qty,
                    COALESCE(NULLIF(btrim(dt.description_long), ''), dt.description) AS delivery_term
               FROM cart_parts_hugoshop cps
               JOIN carts_hugoshop kb ON kb.uuid = cps.cart_uuid
               JOIN parts ps ON ps.id = cps.parts_id
               CROSS JOIN LATERAL (SELECT shop_channel_price(ps.id, kb.channel_id) AS preis) k
               LEFT JOIN parts_channel_shop pc ON pc.parts_id = ps.id
                                              AND pc.channel_id = kb.channel_id
               LEFT JOIN parts_ext psh ON psh.parts_id = ps.id
               LEFT JOIN parts_shipping_shop pss ON pss.parts_id = ps.id
               LEFT JOIN delivery_terms dt ON dt.id = pss.delivery_term_id
              WHERE cps.cart_uuid = :cart_uuid
                -- Der Versand ist kein Artikel des Kunden: er steht in den
                -- Summen (shippingCosts), nicht in der Liste
                AND NOT shop_is_shipping_part(cps.parts_id)
              ORDER BY cps.id",
            [':cart_uuid' => $cartUuid, ':precision' => $precision]
        );
    } else {
        $positionen = $db->getAll(
            "WITH letzte_steuerschluessel AS (
                 SELECT DISTINCT ON (tk.chart_id) tk.tax_id, tk.chart_id
                   FROM taxkeys tk
                  WHERE tk.startdate <= current_date
                  ORDER BY tk.chart_id, tk.startdate DESC
             )
             SELECT cps.id AS pos_id, ps.id AS parts_id,
                    COALESCE(NULLIF(pc.title, ''), ps.description) AS description, ps.unit, cps.amount,
                    ROUND(k.preis / :precision) * :precision AS unit_price,
                    ROUND(k.preis * cps.amount / :precision) * :precision AS total_price,
                    ps.buchungsgruppen_id,
                    tc.income_accno_id, tk.tax_id,
                    ch_income.taxkey_id AS income_taxkey, ch_income.link AS income_link,
                    tax.chart_id AS taxservice_accno_id, tax.rate AS tax_rate,
                    ch_tax.link AS taxservice_link,
                    psh.hugoshop_images ->> 0 AS thumbnail,
                    (pc.active AND shop_active_channel_id(kb.channel_id) IS NOT NULL
                         AND shop_part_available(ps.id, kb.channel_id)) IS TRUE AS offered,
                    pss.min_qty,
                    COALESCE(NULLIF(btrim(dt.description_long), ''), dt.description) AS delivery_term
               FROM cart_parts_hugoshop cps
               JOIN carts_hugoshop kb ON kb.uuid = cps.cart_uuid
               JOIN parts ps ON ps.id = cps.parts_id
               CROSS JOIN LATERAL (SELECT shop_channel_price(ps.id, kb.channel_id) AS preis) k
               LEFT JOIN parts_channel_shop pc ON pc.parts_id = ps.id
                                              AND pc.channel_id = kb.channel_id
               JOIN taxzone_charts tc ON tc.buchungsgruppen_id = ps.buchungsgruppen_id
                                     AND tc.taxzone_id = :taxzone_id
               LEFT JOIN letzte_steuerschluessel tk ON tk.chart_id = tc.income_accno_id
               LEFT JOIN tax ON tax.id = tk.tax_id
               LEFT JOIN chart ch_income ON ch_income.id = tc.income_accno_id
               LEFT JOIN chart ch_tax ON ch_tax.id = tax.chart_id
               LEFT JOIN parts_ext psh ON psh.parts_id = ps.id
               LEFT JOIN parts_shipping_shop pss ON pss.parts_id = ps.id
               LEFT JOIN delivery_terms dt ON dt.id = pss.delivery_term_id
              WHERE cps.cart_uuid = :cart_uuid
                -- Die Versandposition entsteht in der Rechnung selbst
                AND NOT shop_is_shipping_part(cps.parts_id)
              ORDER BY cps.id",
            [':cart_uuid' => $cartUuid, ':precision' => $precision, ':taxzone_id' => $taxzoneId]
        );
    }

    $summen = cartTotals($db, $cartUuid, $precision, $taxzoneId,
                         $land ?? shopShippingCountry($db, $customerId, [], true));

    $daten = [
        'positions'                => array_map(fn($p) => cartPositionShape($p, $simple), $positionen),
        'totalSum'                 => $summen['total_sum'],
        'nettoTotalSum'            => $summen['netto_total_sum'],
        'shippingCosts'            => $summen['shipping_costs'],
        'incShippingCosts'         => $summen['inc_shipping_costs'],
        'totalSumIncShipping'      => $summen['total_sum_inc_shipping'],
        'nettoTotalSumIncShipping' => $summen['netto_total_sum_inc_shipping'],
        'currency'                 => $stammdaten['currency'],
    ] + cartShippingShape($summen);

    if (!$simple) {
        // Fuer die Rechnung: Versandartikel und Preis der gewaehlten Versandart
        $daten['shipping'] = [
            'status'     => $summen['shipping_status'],
            'method_id'  => $summen['shipping_method_id'],
            'method'     => $summen['shipping_method'],
            'parts_id'   => $summen['shipping_parts_id'],
            'price'      => $summen['shipping_costs'],
            'account'    => $summen['shipping_account'],
        ];
    }

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
        // false: nicht mehr im Shop angeboten (O2) — der Kauf wird abgelehnt,
        // bis die Position entfernt ist
        'offered'            => in_array($p['offered'] ?? true, [true, 't', 1, '1'], true),
        // dev/shop-versand.md: Lieferbedingung vor dem Kauf, Mindestabnahme
        'deliveryTerm'       => (string)($p['delivery_term'] ?? ''),
        'minQuantity'        => isset($p['min_qty']) ? (float)$p['min_qty'] : null,
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

    // Nur Artikel, die der HugoShop des Warenkorbs anbietet (O2): aktive
    // Kanalzeile bei eingeschaltetem Kanal. Sonst liesse sich jeder Artikel bestellen, dessen
    // Kennung jemand kennt — auch abgewählte oder nie angebotene. Dazu muss er
    // verfügbar sein (shop_part_available: nicht veraltet, nicht als nicht
    // verfügbar markiert); die Produktseite zeigt dann keinen Warenkorb-Knopf,
    // eine ältere Seite im Browser-Cache aber noch.
    $eingefuegt = $db->getOne(
        "INSERT INTO cart_parts_hugoshop (cart_uuid, parts_id, amount)
         SELECT :cart_uuid, p.id, GREATEST(:menge, COALESCE(CEIL(ps.min_qty)::integer, 0))
           FROM parts p
           JOIN carts_hugoshop kb ON kb.uuid = :korb
           JOIN parts_channel_shop pc ON pc.parts_id = p.id
                                     AND pc.channel_id = shop_active_channel_id(kb.channel_id)
                                     AND pc.active
           LEFT JOIN parts_shipping_shop ps ON ps.parts_id = p.id
          WHERE p.id = :parts_id
            AND shop_part_available(p.id, kb.channel_id)
         -- Mindestabnahme (dev/shop-versand.md, Entscheidung 5): wer weniger
         -- hineinlegt, bekommt die Mindestmenge; liegt der Artikel schon
         -- darin, kommt die gewuenschte Menge hinzu
         ON CONFLICT (cart_uuid, parts_id)
         DO UPDATE SET amount = GREATEST(cart_parts_hugoshop.amount + :menge_dazu, EXCLUDED.amount)
         RETURNING id",
        [':cart_uuid' => $cartUuid, ':korb' => $cartUuid, ':parts_id' => $partsId,
         ':menge' => $menge, ':menge_dazu' => $menge]
    );

    // Ohne Treffer bleibt das INSERT wirkungslos — den Artikel gibt es nicht
    // oder nicht im Shop. Stillschweigend nichts zu tun waere die schlechtere
    // Antwort. Der Code bleibt PART_NOT_FOUND: für den Besucher ist beides
    // dasselbe.
    if (!$eingefuegt) {
        throw new ApiError("PART_NOT_FOUND", 'Diesen Artikel gibt es im Shop nicht');
    }

    // Getrennte Abfrage, nicht im selben CTE: alle Zweige einer Anweisung
    // lesen denselben Stand, die Zaehlung saehe den Warenkorb also so, wie er
    // vor dem Einfuegen aussah.
    $anzahl = $db->getOne(
        "SELECT COUNT(*) AS anzahl
           FROM cart_parts_hugoshop c
          WHERE c.cart_uuid = :cart_uuid
            -- Positionszahl ohne Versand, wie die Liste im Warenkorb
            AND NOT shop_is_shipping_part(c.parts_id)",
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
             -- Mindestabnahme (dev/shop-versand.md, Entscheidung 5): darunter
             -- wird auf sie angehoben; die Antwort nennt die tatsaechliche Menge
             UPDATE cart_parts_hugoshop c
                SET amount = GREATEST(:menge, COALESCE((SELECT CEIL(ps.min_qty)::integer
                                                           FROM parts_shipping_shop ps
                                                          WHERE ps.parts_id = c.parts_id), 0))
              WHERE c.id = :pos_id AND c.cart_uuid = :cart_uuid
             RETURNING c.id, c.parts_id, c.amount
         )
         SELECT g.id, g.amount,
                ROUND(k.preis / :precision) * :precision AS unit_price,
                ROUND(k.preis * g.amount / :precision) * :precision AS total_price
           FROM geaendert g
           JOIN carts_hugoshop kb ON kb.uuid = :korb
           CROSS JOIN LATERAL (SELECT shop_channel_price(g.parts_id, kb.channel_id) AS preis) k",
        [':menge' => $menge, ':pos_id' => $posId, ':cart_uuid' => $cartUuid, ':korb' => $cartUuid,
         ':precision' => $precision]
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
    $summen = cartTotals($db, $cartUuid, (float)$stammdaten['precision'], (int)$stammdaten['taxzone_id'],
                         shopShippingCountry($db, $customerId, [], true));

    $anzahl = $db->getOne(
        "SELECT COUNT(*) AS anzahl
           FROM cart_parts_hugoshop c
          WHERE c.cart_uuid = :cart_uuid
            -- Positionszahl ohne Versand, wie die Liste im Warenkorb
            AND NOT shop_is_shipping_part(c.parts_id)",
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
    ] + cartShippingShape($summen);
}

/**
 * Versandangaben fuer die Oberflaeche
 *
 * shippingStatus ok heisst: es gibt eine passende Versandart. Sonst sperrt die
 * Kasse das Bezahlen und nennt den Grund (cartRequireShipping).
 *
 * @param array $summen Ergebnis von cartTotals()
 * @return array
 */
function cartShippingShape(array $summen): array {
    return [
        'shippingStatus' => $summen['shipping_status'],
        'shippingMethod' => $summen['shipping_method'],
        'shippingFree'   => $summen['shipping_free'],
    ];
}

/**
 * Lieferadresse aus der Kasse, vereinheitlicht
 *
 * Die Kasse schickt adresses.shipping in drei Formen (checkout-data.js):
 *   {default: true, id: "12"}   gespeicherte Lieferadresse
 *   {default: true, id: null}   keine abweichende — an die Rechnungsadresse
 *   {default: false, id: null, name, street, …}  neue Adresse
 * Frueher wurde bei default die ganze Angabe verworfen, und die Rechnung las
 * shipto_id statt id — eine gewaehlte gespeicherte Adresse kam nie an.
 *
 * @param mixed $adresse adresses.shipping oder schon vereinheitlicht
 * @return array ['shipto_id' => int], Adressfelder oder [] (Rechnungsadresse)
 */
function shopDeliveryAddress($adresse): array {
    if (!is_array($adresse)) {
        return [];
    }
    $id = (int)($adresse['shipto_id'] ?? $adresse['id'] ?? 0);
    if ($id > 0) {
        return ['shipto_id' => $id];
    }
    if (empty($adresse['default']) && (!empty($adresse['name']) || !empty($adresse['street']))) {
        unset($adresse['default'], $adresse['id'], $adresse['shipto_id']);
        return $adresse;
    }
    return [];
}

/**
 * Land der Lieferadresse als Freitext
 *
 * Gespeicherte Lieferadresse (nur eine des Kunden), neue Adresse oder — ohne
 * Angabe — die Rechnungsadresse des Kunden. Mit $standard gilt ohne Angabe
 * zuerst die Standard-Lieferadresse des Kontos: so rechnet der Warenkorb, bevor
 * in der Kasse gewaehlt wird. Ohne Kunden (Gast) bleibt es leer, das heisst
 * Mandantenland.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int|null $customerId Kunde
 * @param array $lieferadresse Ergebnis von shopDeliveryAddress()
 * @param bool $standard ohne Angabe die Standard-Lieferadresse verwenden
 * @return string Freitext, leer = Mandantenland
 */
function shopShippingCountry($db, ?int $customerId, array $lieferadresse = [], bool $standard = false): string {
    if (!isset($lieferadresse['shipto_id']) && (!empty($lieferadresse['name']) || !empty($lieferadresse['street']))) {
        return trim((string)($lieferadresse['country'] ?? ''));
    }
    if (!$customerId) {
        return '';
    }
    $zeile = $db->getOne(
        "SELECT COALESCE(
                    (SELECT s.shiptocountry FROM shipto s
                      WHERE s.shipto_id = :shipto_id AND s.trans_id = :kunde_a
                        AND COALESCE(s.module, 'CT') = 'CT'),
                    CASE WHEN :standard = 1 THEN
                        (SELECT s.shiptocountry FROM shipto s
                           JOIN customer_ext ce ON ce.hugoshop_shipto_id = s.shipto_id
                          WHERE ce.customer_id = :kunde_b AND s.trans_id = :kunde_c
                            AND COALESCE(s.module, 'CT') = 'CT')
                    END,
                    (SELECT c.country FROM customer c WHERE c.id = :kunde_d),
                    '') AS land",
        [
            ':shipto_id' => (int)($lieferadresse['shipto_id'] ?? 0),
            ':kunde_a'   => $customerId,
            ':kunde_b'   => $customerId,
            ':kunde_c'   => $customerId,
            ':kunde_d'   => $customerId,
            ':standard'  => $standard ? 1 : 0,
        ]
    );
    return trim((string)($zeile['land'] ?? ''));
}

/**
 * Bricht ab, wenn der Versand nicht moeglich ist
 *
 * Vor dem Bezahlen (dev/shop-versand.md, W2, W3, W8): ohne passende
 * Versandart kein Kauf. Der Code nennt den Grund, die Oberflaeche uebersetzt
 * ihn.
 *
 * @param array $korb Ergebnis von cartRead()
 * @return void
 * @throws ApiError SHIPPING_*, CART_MIN_QUANTITY
 */
function cartRequireShipping(array $korb): void {
    $gruende = [
        'country_unknown'       => ['SHIPPING_COUNTRY_UNKNOWN', 'Das Land der Lieferadresse ist unbekannt'],
        'country_not_delivered' => ['SHIPPING_COUNTRY_NOT_DELIVERED', 'In dieses Land liefern wir nicht'],
        'weight_missing'        => ['SHIPPING_ON_REQUEST', 'Versand auf Anfrage'],
        'assigned_unfit'        => ['SHIPPING_ASSIGNED_UNFIT', 'Die Versandart dieser Bestellung passt nicht'],
        'no_method'             => ['SHIPPING_NO_METHOD', 'Für diese Bestellung gibt es keine Versandart'],
    ];
    $status = (string)($korb['shippingStatus'] ?? 'ok');
    if (isset($gruende[$status])) {
        throw new ApiError($gruende[$status][0], $gruende[$status][1]);
    }

    // Mindestabnahme (Entscheidung 5): der Warenkorb hebt beim Hineinlegen an;
    // hier faellt nur auf, was vor einer geaenderten Mindestmenge darin lag
    foreach ($korb['positions'] ?? [] as $position) {
        if (null !== ($position['minQuantity'] ?? null) && $position['quantity'] < $position['minQuantity']) {
            throw new ApiError('CART_MIN_QUANTITY',
                'Mindestabnahme nicht erreicht: '.$position['label'].' ('.$position['minQuantity'].')');
        }
    }
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
 * Nur Warenkoerbe desselben HugoShops: Kundenkonten gelten fuer alle
 * HugoShops des Mandanten (M1), Warenkoerbe nicht — Preise und Angebot
 * gehoeren zum Kanal.
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
            AND channel_id = CAST(:kanal AS integer)
          ORDER BY active DESC LIMIT 1",
        [':customer_id' => $customerId, ':gast_korb' => $gastKorb, ':kanal' => (int)$context['channel_id']]
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

/**
 * Lehnt einen Warenkorb mit Artikeln ab, die der Shop nicht mehr anbietet
 *
 * O2: ein Artikel kann abgewählt werden, während er in einem Warenkorb liegt.
 * Geprüft wird vor dem Kauf — beim Kauf auf Rechnung und vor der Zahlung bei
 * PayPal, nicht nach ihr: dann ist das Geld schon unterwegs und die Rechnung
 * muss entstehen.
 *
 * @param array $korb Ergebnis von cartRead()
 * @return void
 * @throws ApiError CART_NOT_OFFERED mit den Bezeichnungen der Positionen
 */
function cartRequireOffered(array $korb): void {
    $weg = array_values(array_filter($korb['positions'] ?? [], fn($p) => empty($p['offered'])));
    if ($weg) {
        throw new ApiError('CART_NOT_OFFERED',
            'Nicht mehr im Shop: '.implode(', ', array_column($weg, 'label')).'. Bitte aus dem Warenkorb entfernen.');
    }
}
