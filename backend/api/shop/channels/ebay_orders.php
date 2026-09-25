<?php
// backend/api/shop/channels/ebay_orders.php
//
// Bestellimport des eBay-Kanals (dev/shop-verkaufskanaele.md, Schritt 5,
// Teil 2). Übernommen aus backend/api/ebay/import.php, Verhalten gleich:
//
//   neue Bestellungen seit dem letzten Abruf (ebay_order_last_check)
//   → Kunde ohne Dubletten (bekannter eBay-Käufer, dann Adressvergleich,
//     sonst neu)
//   → eine Ausgangsrechnung je Bestellung, brutto wie bei eBay
//   → Buchung ins Hauptbuch
//   → ebay_orders als Sperre gegen doppelte Rechnungen und als Nachweis
//
// Geändert: eingeschaltet ist der Import über den Kanalschalter
// (sales_channel_shop.active) statt ebay_enabled.
//
// Lagerbestand: Nach dem Import bucht shopBookStock() die Waren vom
// eingestellten Lagerplatz aus (O14, V28) — wie bei den Rechnungen des
// HugoShops. Die Faktura im Kern bucht weiterhin kein Lager.
//
// Braucht faktura/faktura.php (createFakturaCore, createFakturaItemCore,
// postArInvoiceToLedger) und database.php (nextFreeNumber).

/**
 * Ist der eBay-Kanal eingeschaltet?
 *
 * @param object $db Company-Datenbankverbindung
 * @return bool
 */
function shopEbayActive($db): bool {
    $zeile = $db->getOne("SELECT shop_active_channel_id('ebay') IS NOT NULL AS an");
    return in_array($zeile['an'] ?? false, [true, 't', 1, '1'], true);
}

/**
 * Mitarbeiter für eBay-Rechnungen: ebay_employee_login, sonst der erste
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $cfg Ergebnis von shopEbayConfig()
 * @return int|null
 */
function shopEbayEmployeeId($db, array $cfg): ?int {
    $zeile = $db->getOne(
        "SELECT COALESCE(
                    (SELECT id FROM employee WHERE login = :login AND :login_gesetzt = 1 LIMIT 1),
                    (SELECT id FROM employee ORDER BY id LIMIT 1)) AS id",
        [':login' => trim($cfg['ebay_employee_login'] ?? ''), ':login_gesetzt' => '' !== trim($cfg['ebay_employee_login'] ?? '') ? 1 : 0]
    );
    return isset($zeile['id']) ? (int)$zeile['id'] : null;
}

/**
 * Kunde zu einer eBay-Bestellung, ohne Dubletten
 *
 * Reihenfolge: bekannter eBay-Käufer → Adressvergleich wie checkDuplicateCV
 * (Name > 0,7, Straße > 0,9, PLZ gleich) → neu anlegen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $bestellung Bestellung von eBay
 * @return int customer.id
 */
function shopEbayResolveCustomer($db, array $bestellung): int {
    $kaeufer = trim($bestellung['buyer']['username'] ?? '');

    if ('' !== $kaeufer) {
        $zeile = $db->getOne(
            "SELECT customer_id FROM ebay_orders
              WHERE buyer_username = :kaeufer AND customer_id IS NOT NULL
              ORDER BY id DESC LIMIT 1",
            [':kaeufer' => $kaeufer]
        );
        if (!empty($zeile['customer_id'])) {
            return (int)$zeile['customer_id'];
        }
    }

    $empfaenger = $bestellung['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? [];
    $adresse    = $empfaenger['contactAddress'] ?? [];
    $name       = trim($empfaenger['fullName'] ?? ('' !== $kaeufer ? $kaeufer : 'eBay-Kunde'));
    $strasse    = trim(($adresse['addressLine1'] ?? '').' '.($adresse['addressLine2'] ?? ''));
    $plz        = trim($adresse['postalCode'] ?? '');
    $ort        = trim($adresse['city'] ?? '');

    if ('' !== $name && '' !== $strasse && '' !== $plz) {
        $treffer = $db->getOne(
            "SELECT id FROM customer
              WHERE LOWER(zipcode) = LOWER(:plz)
                AND similarity(LOWER(name), LOWER(:name)) > 0.7
                AND similarity(LOWER(street), LOWER(:strasse)) > 0.9
              ORDER BY similarity(LOWER(name), LOWER(:name_sort)) DESC
              LIMIT 1",
            [':plz' => $plz, ':name' => $name, ':strasse' => $strasse, ':name_sort' => $name]
        );
        if ($treffer) {
            return (int)$treffer['id'];
        }
    }

    // Die E-Mail ist bei eBay oft maskiert — nur zur Information
    $zeile = $db->getOne(
        "INSERT INTO customer (customernumber, name, street, zipcode, city, email)
         VALUES (:nummer, :name, :strasse, :plz, :ort, :email)
         RETURNING id",
        [
            ':nummer'  => nextFreeNumber($db, 'customernumber', 'customer', 'customernumber'),
            ':name'    => $name,
            ':strasse' => $strasse,
            ':plz'     => $plz,
            ':ort'     => $ort,
            ':email'   => trim($empfaenger['email'] ?? ''),
        ]
    );
    return (int)$zeile['id'];
}

/**
 * Artikel zu einer eBay-Position: über die SKU (= Artikelnummer), sonst der
 * Sammelartikel aus ebay_default_parts_id
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $position Position der Bestellung
 * @param int $sammelartikel ebay_default_parts_id, 0 = keiner
 * @return int parts.id
 * @throws ApiError EBAY_NO_PART
 */
function shopEbayResolvePart($db, array $position, int $sammelartikel): int {
    $sku = trim($position['sku'] ?? '');
    if ('' !== $sku) {
        $zeile = $db->getOne(
            "SELECT id FROM parts WHERE partnumber = :sku AND COALESCE(obsolete, false) = false LIMIT 1",
            [':sku' => $sku]
        );
        if ($zeile) {
            return (int)$zeile['id'];
        }
    }
    if ($sammelartikel > 0) {
        return $sammelartikel;
    }
    throw new ApiError('EBAY_NO_PART', 'Kein Artikel für SKU "'.$sku.'" und kein Sammelartikel (ebay_default_parts_id) eingestellt');
}

/**
 * Importiert eine Bestellung, höchstens einmal
 *
 * Kunde, Rechnung, Positionen und Nachweis in einer Transaktion; gebucht wird
 * danach (postArInvoiceToLedger führt eine eigene Transaktion). Scheitert die
 * Buchung, bleibt die Rechnung ungebucht und posting_reason nennt den Grund.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $bestellung Bestellung von eBay
 * @param array $cfg Ergebnis von shopEbayConfig()
 * @return string imported oder skipped
 */
function shopEbayImportOrder($db, array $bestellung, array $cfg): string {
    $bestellId = trim($bestellung['orderId'] ?? '');
    if ('' === $bestellId
        || $db->getOne("SELECT 1 FROM ebay_orders WHERE ebay_order_id = :id", [':id' => $bestellId])) {
        return 'skipped';
    }

    $sammelartikel = (int)($cfg['ebay_default_parts_id'] ?? 0);
    $gesamt = (float)($bestellung['pricingSummary']['total']['value'] ?? 0);

    $db->beginTransaction();
    try {
        $kunde = shopEbayResolveCustomer($db, $bestellung);

        // eBay-Preise sind brutto
        $rechnung = createFakturaCore($db, 'invoice', $kunde, 'C', shopEbayEmployeeId($db, $cfg), ['taxincluded' => true]);
        $arId = (int)$rechnung['id'];

        foreach ($bestellung['lineItems'] ?? [] as $position) {
            createFakturaItemCore($db, 'invoice', $arId, [
                'parts_id'        => shopEbayResolvePart($db, $position, $sammelartikel),
                'description'     => $position['title'] ?? ('eBay-Artikel '.($position['sku'] ?? '')),
                'longdescription' => '',
                'qty'             => (float)($position['quantity'] ?? 1),
                'sellprice'       => (float)($position['lineItemCost']['value'] ?? 0),
                'discount'        => 0,
                'unit'            => 'Stck',
            ]);
        }

        // Versandkosten als eigene Position, sofern ein Sammelartikel da ist
        $versand = (float)($bestellung['pricingSummary']['deliveryCost']['value'] ?? 0);
        if ($versand > 0 && $sammelartikel > 0) {
            createFakturaItemCore($db, 'invoice', $arId, [
                'parts_id'        => $sammelartikel,
                'description'     => 'Versandkosten',
                'longdescription' => '',
                'qty'             => 1,
                'sellprice'       => $versand,
                'discount'        => 0,
                'unit'            => 'Stck',
            ]);
        }

        // Rechnungsbetrag = von eBay gezahlter Bruttobetrag
        $db->execute("UPDATE ar SET amount = :betrag WHERE id = :id", [':betrag' => $gesamt, ':id' => $arId]);

        $db->execute(
            "INSERT INTO ebay_orders (ebay_order_id, ar_id, customer_id, buyer_username, order_status,
                                      total, posting_reason, raw)
             VALUES (:id, :ar, :kunde, :kaeufer, :stand, :gesamt, 'PENDING', :roh)",
            [
                ':id'      => $bestellId,
                ':ar'      => $arId,
                ':kunde'   => $kunde,
                ':kaeufer' => trim($bestellung['buyer']['username'] ?? ''),
                ':stand'   => $bestellung['orderFulfillmentStatus'] ?? null,
                ':gesamt'  => $gesamt,
                ':roh'     => json_encode($bestellung, JSON_UNESCAPED_UNICODE),
            ]
        );

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    try {
        $buchung = postArInvoiceToLedger($db, $arId);
        $grund = !empty($buchung['posted']) ? 'posted' : ($buchung['reason'] ?? 'UNKNOWN');
    } catch (\Throwable $e) {
        $grund = 'POST_ERROR';
    }
    $db->execute(
        "UPDATE ebay_orders SET posting_reason = :grund, mtime = now() WHERE ebay_order_id = :id",
        [':grund' => $grund, ':id' => $bestellId]
    );

    // Waren aus dem Lager ausbuchen (O14) — auch bei ungebuchter Rechnung:
    // verkauft ist die Ware in jedem Fall
    shopBookStock($db, $arId);

    return 'imported';
}

/**
 * Holt die neuen Bestellungen seit dem letzten Abruf und importiert sie
 *
 * Der Zeitpunkt des Abrufs wird nur gemerkt, wenn alles durchlief — sonst
 * kommt die gescheiterte Bestellung beim nächsten Mal wieder dran; die
 * übrigen überspringt ebay_orders.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array imported, skipped, fetched, errors
 * @throws ApiError EBAY_DISABLED, EBAY_API_ERROR
 */
function shopEbayImportOrders($db): array {
    if (!shopEbayActive($db)) {
        throw new ApiError('EBAY_DISABLED', 'Der eBay-Kanal ist abgeschaltet (Einstellungen → Shop → Verkaufskanäle)');
    }
    $cfg = shopEbayConfig($db);

    $von = !empty($cfg['ebay_order_last_check'])
        ? $cfg['ebay_order_last_check']
        : gmdate('Y-m-d\TH:i:s.000\Z', time() - 86400);
    $bis = gmdate('Y-m-d\TH:i:s.000\Z');

    $bilanz = ['imported' => 0, 'skipped' => 0, 'fetched' => 0, 'errors' => []];
    $grenze = 50;
    $versatz = 0;

    do {
        $antwort = shopEbayApi($db, 'GET', '/sell/fulfillment/v1/order', null, [
            'filter' => 'creationdate:['.$von.'..'.$bis.']',
            'limit'  => $grenze,
            'offset' => $versatz,
        ]);
        if ($antwort['status'] >= 400) {
            throw new ApiError('EBAY_API_ERROR', 'eBay-API-Fehler: '.shopEbayErrorMessage($antwort));
        }
        $bestellungen = $antwort['body']['orders'] ?? [];
        $gesamt = (int)($antwort['body']['total'] ?? 0);
        $bilanz['fetched'] += count($bestellungen);

        foreach ($bestellungen as $bestellung) {
            try {
                'imported' === shopEbayImportOrder($db, $bestellung, $cfg) ? $bilanz['imported']++ : $bilanz['skipped']++;
            } catch (\Throwable $e) {
                $bilanz['errors'][] = ($bestellung['orderId'] ?? '?').': '.$e->getMessage();
            }
        }
        $versatz += $grenze;
    } while ($versatz < $gesamt && $bestellungen);

    if (!$bilanz['errors']) {
        shopEbaySetConfig($db, 'ebay_order_last_check', $bis);
    }
    return $bilanz;
}

/**
 * Stand des eBay-Kanals für die Einstellungen: letzter Abruf, Zahlen, die
 * zuletzt importierten Bestellungen
 *
 * @param object $db Company-Datenbankverbindung
 * @return array
 */
function shopEbayStatus($db): array {
    $zeile = $db->getOne(
        "SELECT shop_active_channel_id('ebay') IS NOT NULL AS enabled,
                (SELECT value FROM defaults_oserp WHERE key = 'ebay_order_last_check') AS last_check,
                (SELECT row_to_json(z) FROM (
                    SELECT COUNT(*) AS total,
                           COUNT(*) FILTER (WHERE posting_reason = 'posted') AS posted,
                           COUNT(*) FILTER (WHERE posting_reason <> 'posted') AS unposted
                      FROM ebay_orders) z) AS counts,
                (SELECT COALESCE(json_agg(r ORDER BY r.id DESC), '[]'::json) FROM (
                    SELECT e.id, e.ebay_order_id, e.buyer_username, e.total, e.posting_reason, e.itime,
                           a.invnumber, c.name AS customer_name
                      FROM ebay_orders e
                      LEFT JOIN ar a ON a.id = e.ar_id
                      LEFT JOIN customer c ON c.id = e.customer_id
                     ORDER BY e.id DESC LIMIT 20) r) AS recent"
    );

    return [
        'enabled'   => in_array($zeile['enabled'] ?? false, [true, 't', 1, '1'], true),
        'lastCheck' => $zeile['last_check'] ?? null,
        'counts'    => json_decode((string)($zeile['counts'] ?? '{}'), true) ?: [],
        'recent'    => json_decode((string)($zeile['recent'] ?? '[]'), true) ?: [],
    ];
}
