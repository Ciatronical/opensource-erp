<?php
// backend/api/ebay/import.php
//
// Kern des eBay-Bestellimports: holt neue Bestellungen, legt Kunden
// dublettenfrei an, erzeugt je Bestellung genau eine Ausgangsrechnung und
// bucht sie ins Hauptbuch. Session-unabhaengig -> von HTTP (orders.php) und
// CLI (backend/cli/ebay-orders.php) gemeinsam genutzt.

require_once __DIR__.'/ebay.php';
// Wiederverwendung der Faktura-Kernlogik (Nummernkreis, Positionen, Buchung)
require_once __DIR__.'/../faktura/faktura.php';

/**
 * Ermittelt die Mitarbeiter-ID fuer eBay-Rechnungen: konfigurierter Login
 * (ebay_employee_login) oder als Fallback der erste Mitarbeiter.
 */
function ebayEmployeeId($db, $cfg) {
    $login = trim($cfg['ebay_employee_login'] ?? '');
    if ($login !== '') {
        $row = $db->getOne("SELECT id FROM employee WHERE login = :l", [':l' => $login]);
        if ($row) return intval($row['id']);
    }
    $row = $db->getOne("SELECT id FROM employee ORDER BY id LIMIT 1");
    return $row ? intval($row['id']) : null;
}

/**
 * Ermittelt/erzeugt den Kunden zu einer eBay-Bestellung – ohne Dubletten.
 * Reihenfolge: bekannter eBay-Kaeufer -> Adress-Fuzzy (wie checkDuplicateCV)
 * -> sonst neu anlegen (Kundennummer via nextFreeNumber).
 *
 * @return int customer.id
 */
function ebayResolveCustomer($db, $order) {
    $buyer = trim($order['buyer']['username'] ?? '');

    // 1) Bekannter eBay-Kaeufer -> bereits verknuepften Kunden wiederverwenden
    if ($buyer !== '') {
        $row = $db->getOne(
            "SELECT customer_id FROM ebay_orders
             WHERE buyer_username = :u AND customer_id IS NOT NULL
             ORDER BY id DESC LIMIT 1",
            [':u' => $buyer]
        );
        if ($row && !empty($row['customer_id'])) {
            return intval($row['customer_id']);
        }
    }

    // Adressdaten aus der Versandanweisung
    $shipTo  = $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? [];
    $addr    = $shipTo['contactAddress'] ?? [];
    $name    = trim($shipTo['fullName'] ?? ($buyer !== '' ? $buyer : 'eBay-Kunde'));
    $street  = trim(($addr['addressLine1'] ?? '') . ' ' . ($addr['addressLine2'] ?? ''));
    $zipcode = trim($addr['postalCode'] ?? '');
    $city    = trim($addr['city'] ?? '');

    // 2) Adress-Fuzzy (spiegelt checkDuplicateCV: Name>0.7, Strasse>0.9, PLZ exakt)
    if ($name !== '' && $street !== '' && $zipcode !== '') {
        $match = $db->getOne(
            "SELECT id FROM customer
             WHERE LOWER(zipcode) = LOWER(:zip)
               AND similarity(LOWER(name), LOWER(:name))     > 0.7
               AND similarity(LOWER(street), LOWER(:street)) > 0.9
             ORDER BY similarity(LOWER(name), LOWER(:name2)) DESC
             LIMIT 1",
            [':zip' => $zipcode, ':name' => $name, ':street' => $street, ':name2' => $name]
        );
        if ($match) {
            return intval($match['id']);
        }
    }

    // 3) Neuen Kunden anlegen (Kundennummer aus defaults-Nummernkreis)
    $customernumber = nextFreeNumber($db, 'customernumber', 'customer', 'customernumber');
    $email = trim($shipTo['email'] ?? ''); // bei Managed Payments oft maskiert -> nur informativ
    $row = $db->getOne(
        "INSERT INTO customer (customernumber, name, street, zipcode, city, email)
         VALUES (:num, :name, :street, :zip, :city, :email)
         RETURNING id",
        [
            ':num'    => $customernumber,
            ':name'   => $name,
            ':street' => $street,
            ':zip'    => $zipcode,
            ':city'   => $city,
            ':email'  => $email,
        ]
    );
    return intval($row['id']);
}

/**
 * Ordnet eine eBay-Position einem Artikel zu: per SKU (= partnumber), sonst
 * Sammelartikel aus ebay_default_parts_id.
 *
 * @return int parts.id
 * @throws ApiError wenn weder SKU-Treffer noch Default-Artikel vorhanden
 */
function ebayResolvePart($db, $lineItem, $defaultPartsId) {
    $sku = trim($lineItem['sku'] ?? '');
    if ($sku !== '') {
        $row = $db->getOne(
            "SELECT id FROM parts WHERE partnumber = :sku AND COALESCE(obsolete, false) = false LIMIT 1",
            [':sku' => $sku]
        );
        if ($row) return intval($row['id']);
    }
    if ($defaultPartsId > 0) {
        return $defaultPartsId;
    }
    throw new ApiError('EBAY_NO_PART', 'Kein Artikel fuer SKU "' . $sku . '" und kein Sammelartikel (ebay_default_parts_id) konfiguriert');
}

/**
 * Importiert eine einzelne eBay-Bestellung idempotent: Kunde, Rechnung,
 * Positionen, Buchung. Gibt 'imported' oder 'skipped' zurueck.
 *
 * @return string 'imported'|'skipped'
 */
function ebayImportOneOrder($db, $order, $cfg) {
    $orderId = trim($order['orderId'] ?? '');
    if ($orderId === '') {
        return 'skipped';
    }

    // Idempotenz: bereits importiert?
    if ($db->getOne("SELECT 1 FROM ebay_orders WHERE ebay_order_id = :o", [':o' => $orderId])) {
        return 'skipped';
    }

    $defaultPartsId = intval($cfg['ebay_default_parts_id'] ?? 0);
    $employeeId     = ebayEmployeeId($db, $cfg);
    $buyer          = trim($order['buyer']['username'] ?? '');
    $status         = $order['orderFulfillmentStatus'] ?? null;
    $total          = (float)($order['pricingSummary']['total']['value'] ?? 0);

    // Beleg-Transaktion: Kunde + Rechnung + Positionen + Audit-Zeile atomar.
    // Die Hauptbuch-Buchung erfolgt NACH dem Commit (postArInvoiceToLedger
    // oeffnet eine eigene Transaktion; PDO kann nicht verschachteln).
    $db->beginTransaction();
    try {
        $customerId = ebayResolveCustomer($db, $order);

        // eBay-Preise sind brutto -> taxincluded = true erzwingen
        $created = createFakturaCore($db, 'invoice', $customerId, 'C', $employeeId, ['taxincluded' => true]);
        $arId    = intval($created['id']);

        foreach ($order['lineItems'] ?? [] as $li) {
            $partsId = ebayResolvePart($db, $li, $defaultPartsId);
            createFakturaItemCore($db, 'invoice', $arId, [
                'parts_id'        => $partsId,
                'description'     => $li['title'] ?? ('eBay-Artikel ' . ($li['sku'] ?? '')),
                'longdescription' => '',
                'qty'             => (float)($li['quantity'] ?? 1),
                'sellprice'       => (float)($li['lineItemCost']['value'] ?? 0),
                'discount'        => 0,
                'unit'            => 'Stck',
            ]);
        }

        // Versandkosten als eigene Position (falls vorhanden und Sammelartikel da)
        $shipping = (float)($order['pricingSummary']['deliveryCost']['value'] ?? 0);
        if ($shipping > 0 && $defaultPartsId > 0) {
            createFakturaItemCore($db, 'invoice', $arId, [
                'parts_id'        => $defaultPartsId,
                'description'     => 'Versandkosten',
                'longdescription' => '',
                'qty'             => 1,
                'sellprice'       => $shipping,
                'discount'        => 0,
                'unit'            => 'Stck',
            ]);
        }

        // Rechnungsbetrag = von eBay gezahlter Bruttobetrag
        $db->execute("UPDATE ar SET amount = :a WHERE id = :id", [':a' => $total, ':id' => $arId]);

        // Audit-/Idempotenz-Zeile (posting_reason zunaechst PENDING)
        $db->execute(
            "INSERT INTO ebay_orders (ebay_order_id, ar_id, customer_id, buyer_username, order_status, total, posting_reason, raw)
             VALUES (:oid, :ar, :cust, :buyer, :status, :total, 'PENDING', :raw)",
            [
                ':oid'    => $orderId,
                ':ar'     => $arId,
                ':cust'   => $customerId,
                ':buyer'  => $buyer,
                ':status' => $status,
                ':total'  => $total,
                ':raw'    => json_encode($order, JSON_UNESCAPED_UNICODE),
            ]
        );

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Hauptbuch-Buchung nach Commit (eigene Transaktion). Bei Betrags-/Konten-
    // Mismatch bleibt die Rechnung ungebucht – posting_reason haelt den Grund fest.
    try {
        $post   = postArInvoiceToLedger($db, $arId);
        $reason = !empty($post['posted']) ? 'posted' : ($post['reason'] ?? 'UNKNOWN');
    } catch (\Throwable $e) {
        $reason = 'POST_ERROR';
    }
    $db->execute(
        "UPDATE ebay_orders SET posting_reason = :r, mtime = now() WHERE ebay_order_id = :o",
        [':r' => $reason, ':o' => $orderId]
    );

    return 'imported';
}

/**
 * Holt neue eBay-Bestellungen seit dem letzten Lauf und importiert sie.
 * Zeitfenster ab defaults_oserp.ebay_order_last_check (Fallback: 24 h).
 *
 * @return array ['imported'=>int, 'skipped'=>int, 'errors'=>array, 'fetched'=>int]
 */
function ebayImportOrders($db, $cfg = null) {
    if ($cfg === null) {
        $cfg = ebayLoadConfig($db);
    }

    $from = !empty($cfg['ebay_order_last_check'])
        ? $cfg['ebay_order_last_check']
        : gmdate('Y-m-d\TH:i:s.000\Z', time() - 86400);
    $now  = gmdate('Y-m-d\TH:i:s.000\Z');

    $filter   = 'creationdate:[' . $from . '..' . $now . ']';
    $limit    = 50;
    $offset   = 0;
    $imported = 0;
    $skipped  = 0;
    $fetched  = 0;
    $errors   = [];

    do {
        $resp   = ebayApiGet($db, '/sell/fulfillment/v1/order', [
            'filter' => $filter,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
        $orders = $resp['orders'] ?? [];
        $total  = intval($resp['total'] ?? 0);
        $fetched += count($orders);

        foreach ($orders as $order) {
            try {
                $result = ebayImportOneOrder($db, $order, $cfg);
                if ($result === 'imported') $imported++; else $skipped++;
            } catch (\Throwable $e) {
                $errors[] = ($order['orderId'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        $offset += $limit;
    } while ($offset < $total && !empty($orders));

    // Zeitstempel des Laufs merken (nur bei fehlerfreiem Abruf)
    if (empty($errors)) {
        ebaySetConfig($db, 'ebay_order_last_check', $now);
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'fetched' => $fetched];
}
