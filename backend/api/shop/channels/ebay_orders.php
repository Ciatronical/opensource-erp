<?php
// backend/api/shop/channels/ebay_orders.php
//
// Bestellimport des eBay-Kanals (dev/shop-verkaufskanaele.md, Schritt 5,
// Teil 2; Verkäufe mit Prüfung: dev/shop-ebay.md):
//
//   geänderte Bestellungen seit dem letzten Abruf (order_last_check)
//   → ebay_order_upsert(): festhalten und einordnen — nicht bezahlt,
//     storniert, vor dem Rechnungsbeginn (import_since), Käufer unklar
//   → Käufer eindeutig (ebay_customer_match: sicher erkannt oder sicher neu)
//     → eine Ausgangsrechnung, brutto wie bei eBay, Buchung, Lager
//   → sonst Prüfung im eBay-Board; nach der Entscheidung dieselbe Rechnung
//   → ebay_orders als Sperre gegen doppelte Rechnungen und als Nachweis
//   → Sendungsnummern abgerechneter Bestellungen an eBay melden
//
// Geändert: eingeschaltet ist der Import über den Kanalschalter
// (sales_channel_shop.active) statt ebay_enabled. Jeder eBay-Kanal ruft seine
// eigenen Bestellungen ab und merkt sich seinen Abruf; ebay_orders trägt den
// Kanal (dev/shop-mehrere-kanaele.md, Schritt 4).
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
 * @param int $kanal eBay-Kanal
 * @return bool
 */
function shopEbayActive($db, int $kanal): bool {
    $zeile = $db->getOne(
        "SELECT shop_active_channel_id(CAST(:kanal AS integer)) IS NOT NULL AS an",
        [':kanal' => $kanal]
    );
    return in_array($zeile['an'] ?? false, [true, 't', 1, '1'], true);
}

/**
 * Eingeschaltete eBay-Kanäle — für den Bestellabruf im Cron
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Liste aus id und name
 */
function shopEbayActiveChannels($db): array {
    return $db->getAll(
        "SELECT id, name FROM sales_channel_shop WHERE type = 'ebay' AND active ORDER BY sortkey NULLS LAST, id"
    ) ?: [];
}

/**
 * Mitarbeiter für eBay-Rechnungen: employee_login des Kanals, sonst der erste
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
        [':login' => trim((string)($cfg['employee_login'] ?? '')), ':login_gesetzt' => '' !== trim((string)($cfg['employee_login'] ?? '')) ? 1 : 0]
    );
    return isset($zeile['id']) ? (int)$zeile['id'] : null;
}

/**
 * Legt den Kunden aus den Käuferdaten einer Bestellung an
 *
 * Rechnungsanschrift wie ebay_buyer(): Registrierungsadresse, sonst
 * Lieferanschrift. Mit Firmennamen wird die Firma der Name und die Person
 * Ansprechpartner. Steuerzone und Währung wie bei der Kontoanlage im Shop.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $roh Bestellung von eBay als JSON
 * @return int customer.id
 */
function shopEbayCreateCustomer($db, string $roh): int {
    $zeile = $db->getOne(
        "WITH k AS (SELECT ebay_buyer(CAST(:roh AS jsonb)) AS b)
         INSERT INTO customer (customernumber, name, contact, street, zipcode, city, country, email, phone, notes,
                               taxzone_id, currency_id)
         SELECT :nummer,
                COALESCE(NULLIF(b ->> 'company', ''), NULLIF(b ->> 'name', ''), NULLIF(b ->> 'username', ''), 'eBay-Kunde'),
                CASE WHEN b ->> 'company' <> '' THEN NULLIF(b ->> 'name', '') END,
                b ->> 'street', b ->> 'zipcode', b ->> 'city', b ->> 'country',
                NULLIF(b ->> 'email', ''), NULLIF(b ->> 'phone', ''),
                'eBay-Käufer ' || COALESCE(NULLIF(b ->> 'username', ''), '?'),
                COALESCE((SELECT id FROM tax_zones WHERE description ILIKE :taxzone ORDER BY sortkey LIMIT 1),
                         (SELECT id FROM tax_zones ORDER BY sortkey LIMIT 1)),
                COALESCE((SELECT id FROM currencies WHERE name ILIKE :currency LIMIT 1),
                         (SELECT currency_id FROM defaults))
           FROM k
         RETURNING id",
        [
            ':roh'      => $roh,
            ':nummer'   => nextFreeNumber($db, 'customernumber', 'customer', 'customernumber'),
            ':taxzone'  => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
            ':currency' => shopConfigValue($db, 'shop_standard_currency', 'EUR'),
        ]
    );
    return (int)$zeile['id'];
}

/**
 * Rechnet eine geklärte Bestellung ab
 *
 * Kunde (vorhanden, neu oder mit neuer Anschrift), Rechnung brutto wie bei
 * eBay, Positionen nach ebay_order_items() (eine Regel mit der Prüfung),
 * Versandkosten, Lieferadresse und Vermerk in ebay_orders — in einer
 * Transaktion. Danach Buchung und Lager wie bisher. Fehlt einer Position der
 * Artikel, bricht es ab, bevor etwas entsteht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $bestellung ebay_orders.id
 * @param int|null $kunde Kunde, null = aus den Käuferdaten neu anlegen
 * @param bool $anschrift Anschrift des Kunden auf die von eBay setzen
 * @param string $wer Login oder auto
 * @return array ar_id, invnumber, customer_id, posted
 * @throws ApiError EBAY_ORDER_NOT_FOUND, EBAY_ORDER_INVOICED, EBAY_NO_PART, EBAY_NO_SHIPPING_PART
 */
function shopEbayInvoiceOrder($db, int $bestellung, ?int $kunde, bool $anschrift, string $wer): array {
    $zeile = $db->getOne(
        "SELECT e.id, e.ebay_order_id, e.channel_id, e.ar_id, e.total, e.raw::text AS roh,
                ebay_order_items(e.channel_id, e.raw) AS positionen,
                COALESCE((e.raw -> 'pricingSummary' -> 'deliveryCost' ->> 'value')::numeric, 0) AS versand,
                COALESCE(NULLIF(btrim(c.settings ->> 'default_parts_id'), '')::integer,
                         (SELECT m.parts_id FROM shipping_method_shop m WHERE m.active ORDER BY m.rank DESC, m.id LIMIT 1)) AS versandartikel
           FROM ebay_orders e
           JOIN sales_channel_shop c ON c.id = e.channel_id
          WHERE e.id = :id",
        [':id' => $bestellung]
    );
    if (!$zeile) {
        throw new ApiError('EBAY_ORDER_NOT_FOUND', 'eBay-Bestellung nicht gefunden');
    }
    if (!empty($zeile['ar_id'])) {
        throw new ApiError('EBAY_ORDER_INVOICED', 'Für diese Bestellung gibt es schon eine Rechnung');
    }
    $kanal = (int)$zeile['channel_id'];
    $positionen = json_decode((string)$zeile['positionen'], true) ?: [];
    $ohne = array_filter($positionen, fn($p) => empty($p['parts_id']));
    if ($ohne) {
        throw new ApiError('EBAY_NO_PART', 'Kein Artikel für: '.implode(', ', array_map(fn($p) => '„'.$p['title'].'“', $ohne))
            .' — Angebot unter „Angebote“ einem Artikel zuordnen oder einen Sammelartikel im Kanal eintragen');
    }
    $versand = (float)$zeile['versand'];
    if ($versand > 0 && empty($zeile['versandartikel'])) {
        throw new ApiError('EBAY_NO_SHIPPING_PART', 'Versandkosten ohne Artikel — eine Versandart oder einen Sammelartikel im Kanal einrichten');
    }
    $cfg = shopEbayConfig($db, $kanal);

    $db->beginTransaction();
    try {
        if (null === $kunde) {
            $kunde = shopEbayCreateCustomer($db, (string)$zeile['roh']);
        } elseif ($anschrift) {
            // Umgezogen: die Rechnungsanschrift von eBay wird die des Kunden
            $db->execute(
                "UPDATE customer c
                    SET street = NULLIF(b ->> 'street', ''), zipcode = NULLIF(b ->> 'zipcode', ''),
                        city = NULLIF(b ->> 'city', ''), country = COALESCE(NULLIF(b ->> 'country', ''), c.country),
                        phone = COALESCE(NULLIF(c.phone, ''), NULLIF(b ->> 'phone', '')),
                        email = COALESCE(NULLIF(c.email, ''), NULLIF(b ->> 'email', '')),
                        mtime = now()
                   FROM (SELECT ebay_buyer(CAST(:roh AS jsonb)) AS b) k
                  WHERE c.id = :id",
                [':roh' => (string)$zeile['roh'], ':id' => $kunde]
            );
        }

        // eBay-Preise sind brutto
        $rechnung = createFakturaCore($db, 'invoice', $kunde, 'C', shopEbayEmployeeId($db, $cfg), ['taxincluded' => true]);
        $arId = (int)$rechnung['id'];

        foreach ($positionen as $position) {
            // lineItemCost ist der Betrag der ganzen Zeile, Stück = Menge × Los
            createFakturaItemCore($db, 'invoice', $arId, [
                'parts_id'        => (int)$position['parts_id'],
                'description'     => (string)($position['title'] ?: $position['description']),
                'longdescription' => 'eBay '.$position['item_id'],
                'qty'             => (int)$position['pieces'],
                'sellprice'       => round((float)$position['total'] / max(1, (int)$position['pieces']), 5),
                'discount'        => 0,
                'unit'            => trim((string)($position['unit'] ?? '')) ?: 'Stck',
            ]);
        }
        if ($versand > 0) {
            createFakturaItemCore($db, 'invoice', $arId, [
                'parts_id'        => (int)$zeile['versandartikel'],
                'description'     => 'Versandkosten',
                'longdescription' => '',
                'qty'             => 1,
                'sellprice'       => $versand,
                'discount'        => 0,
                'unit'            => 'Stck',
            ]);
        }

        // Lieferadresse der Bestellung an die Rechnung (dev/shop-adressen.md) —
        // eigene Anweisung: shop_invoice_shipto ändert die Rechnungszeile selbst
        $db->getOne(
            "SELECT shop_invoice_shipto(CAST(:ar_id AS integer), NULL, shop_ebay_ship_to(CAST(:roh AS jsonb)), false)",
            [':ar_id' => $arId, ':roh' => (string)$zeile['roh']]
        );
        // Betrag = gezahlter Bruttobetrag, eBay-Bestellnummer als Bestellnummer
        // des Kunden, Bestellung als abgerechnet vermerken
        $db->getOne(
            "WITH betrag AS (
                 UPDATE ar SET amount = CAST(:betrag AS numeric),
                               cusordnumber = COALESCE(NULLIF(cusordnumber, ''), :bestellnummer)
                  WHERE id = CAST(:ar_betrag AS integer)
             )
             UPDATE ebay_orders
                SET ar_id = CAST(:ar_vermerk AS integer), customer_id = CAST(:kunde AS integer), state = 'invoiced',
                    posting_reason = 'PENDING', error = NULL,
                    decided_by = COALESCE(decided_by, :wer), decided_at = COALESCE(decided_at, now()), mtime = now()
              WHERE id = CAST(:id AS integer)
             RETURNING id",
            [
                ':betrag' => (float)$zeile['total'], ':bestellnummer' => (string)$zeile['ebay_order_id'], ':ar_betrag' => $arId,
                ':ar_vermerk' => $arId, ':kunde' => $kunde, ':wer' => $wer, ':id' => $bestellung,
            ]
        );
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Buchen (eigene Transaktion) und Lager — verkauft ist die Ware in jedem Fall
    $gebucht = false;
    try {
        $probe = postArInvoiceToLedger($db, $arId, true);
        // Rundungscent zwischen eBay-Summe und Positionen: das Hauptbuch rechnet
        if (isset($probe['gross']) && abs((float)$probe['gross'] - (float)$zeile['total']) < 0.05) {
            $db->execute("UPDATE ar SET amount = :brutto WHERE id = :ar_id", [':brutto' => $probe['gross'], ':ar_id' => $arId]);
        }
        $buchung = postArInvoiceToLedger($db, $arId);
        $gebucht = !empty($buchung['posted']);
        $grund = $gebucht ? 'posted' : ($buchung['reason'] ?? 'UNKNOWN');
    } catch (\Throwable $e) {
        $grund = 'POST_ERROR';
    }
    // Nettobetrag aus den gebuchten Erlöszeilen — Listen und Auswertungen lesen ar.netamount
    $db->getOne(
        "WITH netto AS (
             UPDATE ar SET netamount = COALESCE((SELECT sum(t.amount) FROM acc_trans t JOIN chart c ON c.id = t.chart_id
                                                  WHERE t.trans_id = ar.id AND c.link LIKE '%AR_amount%'), netamount)
              WHERE id = CAST(:ar_id AS integer)
         )
         UPDATE ebay_orders SET posting_reason = :grund, mtime = now() WHERE id = CAST(:id AS integer) RETURNING id",
        [':ar_id' => $arId, ':grund' => $grund, ':id' => $bestellung]
    );
    shopBookStock($db, $arId);

    return ['ar_id' => $arId, 'invnumber' => (string)($rechnung['docNumber'] ?? ''), 'customer_id' => $kunde, 'posted' => $gebucht];
}

/**
 * Schreibt eine Bestellung von eBay fest und rechnet sie ab, wenn klar
 *
 * Die Regel steht in ebay_order_upsert(); scheitert die Rechnung, bleibt die
 * Bestellung in der Prüfung mit dem Fehler — der nächste Abruf versucht es
 * erneut, der Benutzer sieht, woran es hängt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param array $bestellung Bestellung von eBay
 * @return string invoiced, review, waiting_payment, cancelled, history, ignored oder error
 */
function shopEbayImportOrder($db, int $kanal, array $bestellung): string {
    if ('' === trim($bestellung['orderId'] ?? '')) {
        return 'skipped';
    }
    $zeile = $db->getOne(
        "SELECT ebay_order_upsert(CAST(:kanal AS integer), CAST(:roh AS jsonb)) AS r",
        [':kanal' => $kanal, ':roh' => json_encode($bestellung, JSON_UNESCAPED_UNICODE)]
    );
    $ergebnis = json_decode((string)($zeile['r'] ?? '{}'), true) ?: [];
    if ('invoice' !== ($ergebnis['action'] ?? '')) {
        return (string)($ergebnis['state'] ?? 'skipped');
    }
    try {
        shopEbayInvoiceOrder($db, (int)$ergebnis['id'],
                             isset($ergebnis['customer_id']) ? (int)$ergebnis['customer_id'] : null, false, 'auto');
        return 'invoiced';
    } catch (\Throwable $e) {
        $db->execute("UPDATE ebay_orders SET state = 'review', error = :fehler, mtime = now() WHERE id = :id",
                     [':fehler' => $e->getMessage(), ':id' => (int)$ergebnis['id']]);
        return 'error';
    }
}

/**
 * Holt die geänderten Bestellungen seit dem letzten Abruf
 *
 * Nach dem Änderungsdatum (lastmodifieddate), nicht nach dem Kaufdatum: so
 * kommt eine Bestellung wieder, wenn sie bezahlt, storniert oder erstattet
 * wird. Zehn Minuten Überlappung fangen späte Änderungen bei eBay ab;
 * ebay_order_upsert() verträgt Wiederholungen. Danach die Versandmeldungen.
 * Der Zeitpunkt wird nur gemerkt, wenn alles durchlief.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @return array fetched, invoiced, review, waiting_payment, cancelled, history, errors, shipped
 * @throws ApiError EBAY_DISABLED, EBAY_API_ERROR, EBAY_ACCOUNT_IN_USE
 */
function shopEbayImportOrders($db, int $kanal): array {
    if (!shopEbayActive($db, $kanal)) {
        throw new ApiError('EBAY_DISABLED', 'Der eBay-Kanal ist abgeschaltet (Shop → Verkaufskanäle)');
    }
    $cfg = shopEbayConfig($db, $kanal);
    // Erster Abruf: Rechnungen ab jetzt, ältere Verkäufe sind schon abgerechnet
    if ('' === trim((string)($cfg['import_since'] ?? ''))) {
        shopEbaySetConfig($db, $kanal, 'import_since', date('Y-m-d\TH:i'));
    }

    $von = !empty($cfg['order_last_check'])
        ? gmdate('Y-m-d\TH:i:s.000\Z', strtotime($cfg['order_last_check']) - 600)
        : gmdate('Y-m-d\TH:i:s.000\Z', time() - 86400);
    $bis = gmdate('Y-m-d\TH:i:s.000\Z');

    $bilanz = ['fetched' => 0, 'invoiced' => 0, 'review' => 0, 'waiting_payment' => 0, 'cancelled' => 0,
               'history' => 0, 'errors' => [], 'shipped' => 0];
    $grenze = 50;
    $versatz = 0;
    do {
        $antwort = shopEbayApi($db, $kanal, 'GET', '/sell/fulfillment/v1/order', null, [
            'filter' => 'lastmodifieddate:['.$von.'..'.$bis.']',
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
                $stand = shopEbayImportOrder($db, $kanal, $bestellung);
                if ('error' === $stand) {
                    $bilanz['errors'][] = ($bestellung['orderId'] ?? '?').': siehe Prüfung';
                } elseif (isset($bilanz[$stand]) && is_int($bilanz[$stand])) {
                    $bilanz[$stand]++;
                }
            } catch (\Throwable $e) {
                $bilanz['errors'][] = ($bestellung['orderId'] ?? '?').': '.$e->getMessage();
            }
        }
        $versatz += $grenze;
    } while ($versatz < $gesamt && $bestellungen);

    if (!array_filter($bilanz['errors'], fn($f) => !str_ends_with($f, ': siehe Prüfung'))) {
        shopEbaySetConfig($db, $kanal, 'order_last_check', $bis);
    }

    // Sendungsnummern der abgerechneten Bestellungen an eBay melden
    $bilanz['shipped'] = shopEbayPushTracking($db, $kanal);
    return $bilanz;
}

/**
 * Meldet versandte Bestellungen an eBay: Sendungsnummer und Versanddienst
 *
 * Nimmt die eingetragene Nummer oder die des DHL-Etiketts der Rechnung.
 * eBay setzt die Bestellung dann auf „Versendet“, der Käufer bekommt die
 * Sendungsverfolgung. Braucht das Recht sell.fulfillment („Mit eBay
 * verbinden“); ohne bleibt der Grund an der Bestellung stehen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @param int $nur nur diese Bestellung (ebay_orders.id), 0 = alle offenen
 * @return int gemeldete Bestellungen
 */
function shopEbayPushTracking($db, int $kanal, int $nur = 0): int {
    $offen = $db->getAll(
        "SELECT e.id, e.ebay_order_id,
                COALESCE(NULLIF(e.tracking_number, ''), d.shipment_no) AS nummer,
                COALESCE(NULLIF(e.carrier, ''), 'DHL') AS dienst,
                (SELECT jsonb_agg(jsonb_build_object('lineItemId', li ->> 'lineItemId', 'quantity', COALESCE((li ->> 'quantity')::int, 1)))
                   FROM jsonb_array_elements(COALESCE(e.raw -> 'lineItems', '[]'::jsonb)) li)::text AS positionen
           FROM ebay_orders e
           LEFT JOIN LATERAL (SELECT x.shipment_no FROM dhl_shipments x
                               WHERE x.record_type = 'invoice' AND x.record_id = e.ar_id
                               ORDER BY x.id DESC LIMIT 1) d ON true
          WHERE e.channel_id = CAST(:kanal AS integer)
            AND e.ar_id IS NOT NULL AND e.shipped_at IS NULL
            AND COALESCE(e.order_status, '') <> 'FULFILLED'
            AND COALESCE(e.cancel_state, '') NOT IN ('CANCELED', 'CANCELLED')
            AND COALESCE(NULLIF(e.tracking_number, ''), d.shipment_no) IS NOT NULL
            AND (CAST(:nur AS integer) = 0 OR e.id = CAST(:nur_id AS integer))
            -- abgelehnte Meldungen nicht bei jedem Abruf wiederholen
            AND (e.ship_error IS NULL OR CAST(:nur_wieder AS integer) > 0)",
        [':kanal' => $kanal, ':nur' => $nur, ':nur_id' => $nur, ':nur_wieder' => $nur]
    ) ?: [];

    $gemeldet = 0;
    foreach ($offen as $zeile) {
        $antwort = shopEbayApi($db, $kanal, 'POST',
            '/sell/fulfillment/v1/order/'.rawurlencode((string)$zeile['ebay_order_id']).'/shipping_fulfillment', [
                'lineItems'           => json_decode((string)$zeile['positionen'], true) ?: [],
                'shippedDate'         => gmdate('Y-m-d\TH:i:s.000\Z'),
                'shippingCarrierCode' => strtoupper((string)$zeile['dienst']),
                'trackingNumber'      => preg_replace('/\s+/', '', (string)$zeile['nummer']),
            ]);
        $ok = $antwort['status'] < 400;
        $db->execute(
            "UPDATE ebay_orders
                SET shipped_at = CASE WHEN :ok = 1 THEN now() END,
                    tracking_number = :nummer, carrier = :dienst,
                    ship_error = CASE WHEN :ok_fehler = 1 THEN NULL ELSE :fehler END,
                    order_status = CASE WHEN :ok_stand = 1 THEN 'FULFILLED' ELSE order_status END, mtime = now()
              WHERE id = :id",
            [':ok' => $ok ? 1 : 0, ':ok_fehler' => $ok ? 1 : 0, ':ok_stand' => $ok ? 1 : 0,
             ':nummer' => (string)$zeile['nummer'], ':dienst' => strtoupper((string)$zeile['dienst']),
             ':fehler' => 403 === $antwort['status']
                 ? 'eBay verweigert die Versandmeldung — Kanal neu „Mit eBay verbinden“, damit OSERP Versand melden darf'
                 : shopEbayErrorMessage($antwort),
             ':id' => (int)$zeile['id']]
        );
        $gemeldet += $ok ? 1 : 0;
    }
    return $gemeldet;
}

/**
 * Stand eines eBay-Kanals für die Einstellungen: letzter Abruf, Konto, Zahlen,
 * die zuletzt importierten Bestellungen
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal eBay-Kanal
 * @return array
 */
function shopEbayStatus($db, int $kanal): array {
    $zeile = $db->getOne(
        "SELECT shop_active_channel_id(CAST(:kanal AS integer)) IS NOT NULL AS enabled,
                c.settings ->> 'order_last_check' AS last_check,
                c.settings ->> 'account_id' AS account,
                (SELECT row_to_json(z) FROM (
                    SELECT COUNT(*) AS total,
                           COUNT(*) FILTER (WHERE posting_reason = 'posted') AS posted,
                           COUNT(*) FILTER (WHERE posting_reason <> 'posted') AS unposted
                      FROM ebay_orders WHERE channel_id = c.id AND ar_id IS NOT NULL) z) AS counts,
                (SELECT COALESCE(json_agg(r ORDER BY r.id DESC), '[]'::json) FROM (
                    SELECT e.id, e.ebay_order_id, e.buyer_username, e.total, e.posting_reason, e.itime,
                           a.invnumber, cu.name AS customer_name
                      FROM ebay_orders e
                      LEFT JOIN ar a ON a.id = e.ar_id
                      LEFT JOIN customer cu ON cu.id = e.customer_id
                     WHERE e.channel_id = c.id AND e.ar_id IS NOT NULL
                     ORDER BY e.id DESC LIMIT 20) r) AS recent
           FROM sales_channel_shop c
          WHERE c.id = CAST(:kanal_zeile AS integer)",
        [':kanal' => $kanal, ':kanal_zeile' => $kanal]
    );

    return [
        'channel_id' => $kanal,
        'account'   => $zeile['account'] ?? null,
        'enabled'   => in_array($zeile['enabled'] ?? false, [true, 't', 1, '1'], true),
        'lastCheck' => $zeile['last_check'] ?? null,
        'counts'    => json_decode((string)($zeile['counts'] ?? '{}'), true) ?: [],
        'recent'    => json_decode((string)($zeile['recent'] ?? '[]'), true) ?: [],
    ];
}
