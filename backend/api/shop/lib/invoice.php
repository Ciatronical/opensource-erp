<?php
// backend/api/shop/lib/invoice.php
//
// Aus dem Warenkorb wird eine Rechnung. Die Bridge tat das in rund 200 Zeilen
// selbst — Nummernkreis, Positionen, Buchungssaetze. Hier bleibt ein Ablauf,
// der die vorhandenen Bausteine aneinanderreiht:
//
//   Versandkosten   cartRead() mit Lieferland -> shop_cart_shipping()
//   Lieferadresse   shiptoCreate(), dann shop_invoice_shipto(): eigene Kopie
//                   der Rechnung (dev/shop-adressen.md)
//   ar + invoice    hier, aus den Warenkorbzeilen
//   acc_trans       postArInvoiceToLedger()   (faktura.php)
//   Verknuepfung    invoice_link_oserp (Kern: Rechnungslink) und
//                   ar_link_hugoshop (Shop: Kanal und PayPal-Felder, dieselbe uuid)
//
// Liste und Ansicht der Rechnungen liefert der Kern (backend/api/portal/lib/
// invoices.php); der Shop hängt Zahlstand, Lieferstatus und Produktlinks über
// die Haken in lib/portal_hooks.php an.
//
// WAS NICHT ENTSTEHT
// Kein Zahlungseingang. Eine Payer-Id von PayPal ist kein Beleg fuer
// eingegangenes Geld — PayPal vergibt sie auch fuer eine schwebende Buchung.
// Und der Shop kennt nur den PayPal-Weg: Zahlungen auf Rechnung kommen ueber
// den Kontoauszug und laufen ohnehin an ihm vorbei. Ein Teil der Eingaenge
// automatisch, der andere von Hand — das ergaebe keine Buchhaltung, der man
// ansieht, was offen ist. Gebucht wird deshalb im ERP, fuer beide Wege gleich.

/**
 * Wandelt den Warenkorb der Sitzung in eine Rechnung
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param array $lieferadresse ['shipto_id' => int] oder die Felder einer neuen Adresse,
 *                             leer = Lieferung an die Rechnungsadresse
 * @param array|null $paypal Zahlungsstand aus paypalPaymentState(), oder null
 * @return array{ar_id: int, ar_link: string, invnumber: string}
 * @throws ApiError CART_NOT_FOUND, CART_EMPTY, CART_NOT_OFFERED, SHIPPING_*, PORTAL_DATABASE_ERROR, LEDGER_ERROR
 */
function createShopInvoice($db, string $uuid, array $lieferadresse = [], ?array $paypal = null): array {
    // adresses.shipping der Kasse oder schon vereinheitlicht (PayPal)
    $lieferadresse = shopDeliveryAddress($lieferadresse);
    $context    = portalContextCustomer($db, $uuid);
    $customerId = (int)$context['customer_id'];

    if (empty($context['cart_uuid'])) {
        throw new ApiError("CART_NOT_FOUND", 'Zu dieser Sitzung gibt es keinen Warenkorb');
    }
    $cartUuid = $context['cart_uuid'];
    // HugoShop der Webseite: Preise und Bezeichnungen des Kanals, und der
    // Rechnungslink merkt ihn sich für Mail, Rechnungsseite und PayPal
    $website = (int)$context['website_id'];
    $kanal   = shopChannelOfWebsite($db, $website);

    // Versand nach dem Land der Lieferadresse (dev/shop-versand.md, Schritt 6)
    $korb = cartRead($db, $cartUuid, $customerId, false,
                     shopShippingCountry($db, $customerId, $lieferadresse));
    if (empty($korb['positions'])) {
        throw new ApiError("CART_EMPTY", 'Der Warenkorb ist leer');
    }

    // Kauf auf Rechnung: nur Angebotenes (O2). Nach einer PayPal-Zahlung wird
    // nicht geprüft — das Geld ist da, die Rechnung muss entstehen; geprüft
    // wurde vor der Zahlung (paymentBegin).
    if (null === $paypal) {
        cartRequireOffered($korb);
        cartRequireShipping($korb);
    }

    // Jede Position braucht ein Erloeskonto, sonst kann die Rechnung nicht
    // gebucht werden. Lieber vorher pruefen als eine ungebuchte Rechnung
    // stehen lassen.
    foreach ($korb['positions'] as $position) {
        if (empty($position['income_accno_id'])) {
            throw new ApiError(
                "NO_INCOME_ACCOUNT",
                'Artikel "'.$position['label'].'" hat in dieser Steuerzone kein Erloeskonto'
            );
        }
    }
    // Die Versandposition entsteht nur bei Status ok und einem Preis ueber 0
    $versand = $korb['shipping'];
    $mitVersand = 'ok' === $versand['status'] && $versand['price'] > 0 && $versand['parts_id'];
    if ($mitVersand && !$versand['account']) {
        throw new ApiError(
            "NO_INCOME_ACCOUNT",
            'Der Versandartikel von "'.$versand['method'].'" hat in dieser Steuerzone kein Erloeskonto'
        );
    }

    $shiptoId = shopInvoiceShiptoId($db, $customerId, $lieferadresse);

    $db->beginTransaction();
    try {
        // Rechnungsnummer und Kopf in einem Vorgang — dasselbe Muster wie
        // createFakturaCore() in faktura.php, ergaenzt um Lieferadresse und
        // Faelligkeit.
        $ar = $db->getOne(
            "WITH nummer AS (
                 UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1
                 RETURNING invnumber
             )
             INSERT INTO ar (invnumber, transdate, gldate, duedate, employee_id, customer_id,
                             taxzone_id, currency_id, invoice, type, taxincluded, shipto_id,
                             amount, netamount)
             SELECT (SELECT invnumber FROM nummer), CURRENT_DATE, CURRENT_DATE, CURRENT_DATE,
                    (SELECT id FROM employee WHERE login = :kontakt AND NOT deleted LIMIT 1),
                    :customer_id, :taxzone_id, :currency_id, true, 'invoice', :taxincluded,
                    :shipto_id, 0, 0
             RETURNING id, invnumber",
            [
                ':kontakt'     => shopConfigValue($db, 'shop_contact_login'),
                ':customer_id' => $customerId,
                ':taxzone_id'  => (int)$korb['customer']['taxzone_id'],
                ':currency_id' => (int)$korb['customer']['currency_id'],
                ':taxincluded' => shopConfigBool($db, 'shop_tax_included'),
                ':shipto_id'   => $shiptoId,
            ]
        );

        $arId = (int)$ar['id'];

        // Eigene Lieferadresse der Rechnung (dev/shop-adressen.md): Kopie der
        // gewählten Adresse, ohne Lieferadresse des Kundenstamms. Ändert oder
        // löscht der Kunde später eine Adresse in seinem Konto, bleibt die
        // Rechnung, wie sie ist. Bei Gästen verschwindet die Quelle danach —
        // sie war nur der Träger der Adresse durch Kasse und PayPal.
        $db->getOne(
            "SELECT shop_invoice_shipto(CAST(:ar_id AS integer), CAST(NULLIF(:quelle, 0) AS integer), NULL, true)",
            [':ar_id' => $arId, ':quelle' => (int)($shiptoId ?? 0)]
        );

        // Die Positionen stehen bereits in der Datenbank — sie muessen nicht
        // durch PHP. Ein Vorgang statt einer Schleife ueber INSERTs.
        //
        // Preis, Bezeichnung und Beschreibung des Kanals — wie im Warenkorb.
        // Weicht der Kanalpreis vom Stammdatenpreis ab, bleibt die Preisquelle
        // leer (in kivitendo "manuell", V2c): master_data/sellprice nennte
        // sonst eine Quelle, deren Preis nicht auf der Position steht.
        $db->execute(
            "INSERT INTO invoice (trans_id, parts_id, description, qty, sellprice, fxsellprice,
                                  discount, unit, position, lastcost, base_qty, allocated,
                                  marge_total, marge_percent, serialnumber, active_price_source,
                                  longdescription, mtime)
             WITH ware AS (
                 SELECT CAST(:ar_id AS integer) AS trans_id, p.id AS parts_id, COALESCE(NULLIF(pc.title, ''), p.description) AS description,
                        c.amount AS qty, k.preis, p.unit, ROW_NUMBER() OVER (ORDER BY c.id) AS position,
                        COALESCE(p.lastcost, 0) AS lastcost,
                        CASE WHEN k.preis = p.sellprice THEN :preisquelle ELSE '' END AS preisquelle,
                        -- Lieferbedingung je Artikel in den Langtext (Punkt 7, Weg a):
                        -- jede Druckvorlage zeigt ihn, und er bleibt, wie verschickt
                        concat_ws(E'\n\n', NULLIF(COALESCE(NULLIF(pc.description, ''), p.notes, ''), ''),
                                  NULLIF(COALESCE(NULLIF(btrim(dt.description_long), ''), dt.description), ''))
                            AS longdescription
                   FROM cart_parts_hugoshop c
                   JOIN parts p ON p.id = c.parts_id
                   CROSS JOIN LATERAL (SELECT shop_channel_price(p.id, CAST(:kanal AS integer)) AS preis) k
                   LEFT JOIN parts_channel_shop pc ON pc.parts_id = p.id
                                                  AND pc.channel_id = CAST(:kanal_zeile AS integer)
                   LEFT JOIN parts_shipping_shop pss ON pss.parts_id = p.id
                   LEFT JOIN delivery_terms dt ON dt.id = pss.delivery_term_id
                  WHERE c.cart_uuid = :cart_uuid
                    AND NOT shop_is_shipping_part(c.parts_id)
             )
             SELECT trans_id, parts_id, description, qty, preis, preis,
                    0, unit, position, lastcost, 1, 0,
                    0, 0, '', preisquelle, longdescription, NOW()
               FROM ware
             UNION ALL
             -- Versand: Versandartikel der Versandart mit dem berechneten
             -- Preis (dev/shop-versand.md, Schritt 6). Bezeichnung und
             -- Langtext die des Artikels, wie bei jeder Position im Kern
             -- (Nachtrag 2026-10-02) — gepflegt in der Maske der Versandart
             SELECT CAST(:ar_id_versand AS integer), p.id, p.description, 1, CAST(:versand_preis AS numeric),
                    CAST(:versand_preis_fx AS numeric), 0, p.unit, (SELECT count(*) FROM ware) + 1,
                    COALESCE(p.lastcost, 0), 1, 0, 0, 0, '', '', COALESCE(p.notes, ''), NOW()
               FROM parts p
              WHERE p.id = :versand_teil AND :mit_versand = 1",
            [
                ':ar_id'       => $arId,
                ':ar_id_versand' => $arId,
                ':versand_preis'    => (string)$versand['price'],
                ':versand_preis_fx' => (string)$versand['price'],
                ':versand_teil'     => (int)($versand['parts_id'] ?? 0),
                ':mit_versand'      => $mitVersand ? 1 : 0,
                ':cart_uuid'   => $cartUuid,
                ':kanal'       => $kanal,
                ':kanal_zeile' => $kanal,
                ':preisquelle' => shopConfigValue($db, 'shop_active_price_source', 'master_data/sellprice'),
            ]
        );

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError("PORTAL_DATABASE_ERROR", 'Rechnung konnte nicht angelegt werden: '.$e->getMessage());
    }

    shopInvoicePostToLedger($db, $arId);
    // Waren aus dem Lager ausbuchen (O14) — der Bestand gilt für alle Kanäle
    shopBookStock($db, $arId);

    // Der Rechnungslink gehört dem Kern; die Shop-Zeile trägt dieselbe uuid
    // mit Kanal und Zahlungsstand
    $arLink = invoiceLinkCreate($db, $arId, $website);
    $db->execute(
        "INSERT INTO ar_link_hugoshop (ar_id, uuid, paypal, paypal_order_id, paypal_capture_id,
                                       payment_status, payment_reason, payment_mtime, channel_id)
         VALUES (:ar_id, :uuid, :payer, :order_id, :capture_id, :status, :reason, :mtime,
                 CAST(:kanal AS integer))",
        [
            ':kanal'      => $kanal,
            ':ar_id'      => $arId,
            ':uuid'       => $arLink,
            ':payer'      => $paypal['payer_id']   ?? null,
            ':order_id'   => $paypal['order_id']   ?? null,
            ':capture_id' => $paypal['capture_id'] ?? null,
            // Ohne Zahlungsstand bleibt der Status leer statt geraten
            ':status'     => $paypal['status']     ?? null,
            ':reason'     => $paypal['reason']     ?? null,
            ':mtime'      => null === $paypal ? null : date('Y-m-d H:i:s'),
        ]
    );

    cartClear($db, $cartUuid);

    return ['ar_id' => $arId, 'ar_link' => $arLink, 'invnumber' => $ar['invnumber']];
}

/**
 * Bestimmt die Lieferadresse der Rechnung
 *
 * Drei Faelle: eine vorhandene Adresse des Kunden, eine neue aus den
 * uebergebenen Feldern, oder keine — dann geht die Lieferung an die
 * Rechnungsadresse.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param array $adresse ['shipto_id' => int] oder Adressfelder
 * @return int|null shipto_id oder null
 * @throws ApiError ADDRESS_NOT_FOUND
 */
function shopInvoiceShiptoId($db, int $customerId, array $adresse): ?int {
    if (!empty($adresse['shipto_id'])) {
        // Zugehoerigkeit pruefen: sonst ginge die Ware an eine fremde Anschrift
        shiptoRead($db, $customerId, (int)$adresse['shipto_id']);
        return (int)$adresse['shipto_id'];
    }

    if (!empty($adresse['name']) || !empty($adresse['street'])) {
        return (int)shiptoCreate($db, $customerId, $adresse)['shipto_id'];
    }

    return null;
}

/**
 * Setzt Betraege und schreibt die Buchungssaetze
 *
 * postArInvoiceToLedger() rechnet die Betraege aus den Positionen und prueft
 * sie gegen ar.amount. Deshalb erst im Trockenlauf fragen, was herauskommt,
 * den Betrag setzen und dann buchen — so kann die Pruefung nicht an einem
 * Rundungscent scheitern.
 *
 * Die Funktion fuehrt ihre eigene Transaktion und darf deshalb nicht
 * innerhalb einer anderen laufen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return void
 * @throws ApiError LEDGER_ERROR wenn die Buchung nicht moeglich ist
 */
function shopInvoicePostToLedger($db, int $arId): void {
    $probe = postArInvoiceToLedger($db, $arId, true);

    if (!isset($probe['gross'])) {
        throw new ApiError(
            "LEDGER_ERROR",
            'Rechnung '.$arId.' kann nicht gebucht werden: '.($probe['reason'] ?? 'unbekannt')
        );
    }

    // netto = Summe der Positionsbetraege, gerundet wie im Hauptbuch.
    // invoice.discount ist real, der Ausdruck damit double precision — und
    // ROUND(double precision, integer) gibt es in PostgreSQL nicht.
    $netto = $db->getOne(
        "SELECT COALESCE(SUM(ROUND((qty * sellprice * (1 - COALESCE(discount, 0)))::numeric, 2)), 0) AS netto
           FROM invoice WHERE trans_id = :ar_id",
        [':ar_id' => $arId]
    );

    $db->execute(
        "UPDATE ar SET amount = :brutto, netamount = :netto WHERE id = :ar_id",
        [':brutto' => $probe['gross'], ':netto' => $netto['netto'], ':ar_id' => $arId]
    );

    $ergebnis = postArInvoiceToLedger($db, $arId, false);
    if (empty($ergebnis['posted'])) {
        throw new ApiError(
            "LEDGER_ERROR",
            'Rechnung '.$arId.' wurde angelegt, aber nicht gebucht: '.($ergebnis['reason'] ?? 'unbekannt')
        );
    }
}

/**
 * Was der Shop zu den Rechnungen eines Kunden weiß (Haken invoice_list)
 *
 * Zahlstand (PayPal) und — wenn der Betreiber ihn zeigen will
 * (shop_delivery_status_show, dev/shop-bestellstatus.md) — der Lieferstatus,
 * nur für HugoShop-Bestellungen.
 *
 * @param array $rechnungen Liste des Kerns (customerInvoices)
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return array
 */
function shopInvoiceListDecorate(array $rechnungen, $db, int $customerId): array {
    if (empty($rechnungen)) {
        return $rechnungen;
    }
    $zusatz = [];
    foreach ($db->getAll(
        "SELECT al.ar_id, al.payment_status,
                CASE WHEN :zeigen THEN (SELECT st.delivery_status FROM shop_order_state(al.ar_id) st) END AS delivery_status
           FROM ar_link_hugoshop al
           JOIN ar ON ar.id = al.ar_id
          WHERE ar.customer_id = :customer_id",
        [':customer_id' => $customerId, ':zeigen' => shopConfigBool($db, 'shop_delivery_status_show')]
    ) ?: [] as $zeile) {
        $zusatz[(int)$zeile['ar_id']] = $zeile;
    }
    foreach ($rechnungen as &$r) {
        $z = $zusatz[(int)$r['id']] ?? null;
        if ($z) {
            $r['payment_status']  = $z['payment_status'];
            $r['delivery_status'] = $z['delivery_status'];
        }
    }
    return $rechnungen;
}

/**
 * Was der Shop zum Kopf einer Rechnung weiß (Haken invoice)
 *
 * @param array $rechnung Kopf des Kerns (customerInvoice)
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return array
 */
function shopInvoiceDecorate(array $rechnung, $db, int $arId): array {
    $zeile = $db->getOne(
        "SELECT al.payment_status, al.payment_reason,
                CASE WHEN :zeigen THEN (SELECT st.delivery_status FROM shop_order_state(al.ar_id) st) END AS delivery_status
           FROM ar_link_hugoshop al WHERE al.ar_id = :ar_id",
        [':ar_id' => $arId, ':zeigen' => shopConfigBool($db, 'shop_delivery_status_show')]
    );
    if ($zeile) {
        $rechnung['payment_status']  = $zeile['payment_status'];
        $rechnung['payment_reason']  = $zeile['payment_reason'];
        $rechnung['delivery_status'] = $zeile['delivery_status'];
    }
    return $rechnung;
}

/**
 * Produktseite, Vorschaubild und Lieferbedingung der Positionen (Haken invoice_positions)
 *
 * Mit Adresse der Produktseite und des Vorschaubilds aus den Mustern des
 * HugoShops der Webseite, über die der Kunde gerade fragt (product_url,
 * thumbnail_url). Ohne HugoShop bleiben die Adressen leer.
 *
 * @param array $positionen Positionen des Kerns (invoicePositions)
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @param int $website Webseite der Anfrage, 0 = keine
 * @return array
 */
function shopInvoicePositionsDecorate(array $positionen, $db, int $arId, int $website): array {
    if (empty($positionen)) {
        return $positionen;
    }
    $kanal = 0;
    if ($website > 0) {
        try {
            $kanal = shopChannelOfWebsite($db, $website);
        } catch (ApiError $e) {
            $kanal = 0;
        }
    }

    $zusatz = [];
    foreach ($db->getAll(
        "SELECT i.id, pe.hugoshop_hyperlink, pe.hugoshop_images ->> 0 AS thumbnail,
                shop_invoice_delivery_term(i.id) AS delivery_term
           FROM invoice i
           LEFT JOIN parts_ext pe ON pe.parts_id = i.parts_id
          WHERE i.trans_id = :ar_id",
        [':ar_id' => $arId]
    ) ?: [] as $zeile) {
        $zusatz[(int)$zeile['id']] = $zeile;
    }

    return array_map(function (array $p) use ($db, $kanal, $zusatz): array {
        $z = $zusatz[(int)($p['invoice_id'] ?? 0)] ?? [];
        $p['delivery_term'] = $z['delivery_term'] ?? null;
        $p['product_url'] = $kanal > 0 && null !== ($p['parts_id'] ?? null)
            ? shopChannelLink($db, $kanal, 'products_link',
                              shopPageSlug((string)($z['hugoshop_hyperlink'] ?? ''), (string)$p['number']), '#focus')
            : '';
        $p['thumbnail_url'] = $kanal > 0 ? shopChannelLink($db, $kanal, 'thumbnails_link', (string)($z['thumbnail'] ?? '')) : '';
        return $p;
    }, $positionen);
}

/**
 * Positionen einer Rechnung mit ihrer Lieferbedingung
 *
 * Fuer Rechnungsmail und Rechnungsseite (dev/shop-versand.md, Punkt 7):
 * Bezeichnung, Menge, Einheit und Lieferbedingung je Position — so wie sie in
 * der Rechnung steht (shop_invoice_delivery_term).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return array Liste aus description, qty, unit, delivery_term (leer = keine)
 */
function shopInvoiceDeliveryTerms($db, int $arId): array {
    return $db->getAll(
        "SELECT i.description, TRUNC(i.qty) AS qty, COALESCE(i.unit, '') AS unit,
                COALESCE(shop_invoice_delivery_term(i.id), '') AS delivery_term
           FROM invoice i
          WHERE i.trans_id = :ar_id
          ORDER BY i.position",
        [':ar_id' => $arId]
    );
}

/**
 * Zusammenfassung zum Rechnungslink
 *
 * Der Link ist das Geheimnis: wer ihn hat, sieht die Bestellung, auch ohne
 * Anmeldung. So kommt ein Gast nach der Bestellung an seine Rechnung.
 *
 * Drei Zahlungszustaende, nicht zwei:
 *   paid    Geld ist da, nichts weiter zu tun
 *   pending Zahlung laeuft bei PayPal noch — der Kunde hat bezahlt und darf
 *           keine Aufforderung zur Ueberweisung sehen
 *   keines  offene Rechnung, Bankverbindung anzeigen
 *
 * Der Link gilt nur auf der Webseite, von der die Rechnung stammt
 * (invoice_link_oserp.website_id); die Shop-Zeile trägt dieselbe uuid.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $arLink Kennung aus invoice_link_oserp
 * @param int $website Webseite der Anfrage
 * @return array
 * @throws ApiError INVOICE_LINK_NOT_FOUND
 */
function invoiceSummaryByLink($db, string $arLink, int $website): array {
    $zeile = $db->getOne(
        "SELECT il.ar_id, al.paypal, al.payment_status, al.payment_reason,
                ar.invnumber, TRUNC(ar.amount, 2) AS amount,
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency,
                c.email, c.phone
           FROM invoice_link_oserp il
           JOIN ar ON ar.id = il.ar_id
           JOIN customer c ON c.id = ar.customer_id
           LEFT JOIN ar_link_hugoshop al ON al.uuid = il.uuid
          WHERE il.uuid = :ar_link AND il.website_id = CAST(:website AS integer)",
        [':ar_link' => $arLink, ':website' => $website]
    );

    if (!$zeile) {
        throw new ApiError("INVOICE_LINK_NOT_FOUND", 'Zu diesem Link gibt es keine Bestellung');
    }

    // Zeilen aus der Zeit vor der Zahlungsstatus-Spalte haben keinen Status.
    // Fuer sie gilt wie frueher die Payer-Id.
    if (null === $zeile['payment_status']) {
        $bezahlt   = null !== $zeile['paypal'];
        $schwebend = false;
    } else {
        $bezahlt   = 'COMPLETED' === $zeile['payment_status'];
        $schwebend = 'PENDING'   === $zeile['payment_status'];
    }

    return [
        'ar_link'                    => $arLink,
        'invnumber'                  => $zeile['invnumber'],
        'amount'                     => $zeile['amount'],
        'currency'                   => $zeile['currency'],
        'email'                      => $zeile['email'],
        'phone'                      => $zeile['phone'],
        'paid'                       => $bezahlt,
        'pending'                    => $schwebend,
        'payment_status'             => $zeile['payment_status'],
        'payment_reason'             => $zeile['payment_reason'],
        'payment_term_account_owner' => shopConfigValue($db, 'shop_payment_account_owner'),
        'payment_term_bank'          => shopConfigValue($db, 'shop_payment_bank'),
        'payment_term_iban'          => shopConfigValue($db, 'shop_payment_iban'),
        'payment_term_bic'           => shopConfigValue($db, 'shop_payment_bic'),
        'payment_term_purpose'       => $zeile['invnumber'],
        'payment_term_amount'        => $zeile['amount'],
        'payment_term_currency'      => $zeile['currency'],
        'shipping'                   => invoiceShippingAddress($db, (int)$zeile['ar_id']),
        // Lieferzeiten je Position (dev/shop-versand.md, Punkt 7)
        'delivery_terms'             => array_values(array_filter(
            shopInvoiceDeliveryTerms($db, (int)$zeile['ar_id']),
            fn($position) => '' !== $position['delivery_term']
        )),
        // Lieferstatus nur, wenn der Betreiber ihn zeigen will
        // (shop_delivery_status_show, dev/shop-bestellstatus.md)
        'delivery_status'            => shopConfigBool($db, 'shop_delivery_status_show')
            ? ($db->getOne("SELECT delivery_status FROM shop_order_state(:ar_id)",
                           [':ar_id' => (int)$zeile['ar_id']])['delivery_status'] ?? null)
            : null,
    ];
}
