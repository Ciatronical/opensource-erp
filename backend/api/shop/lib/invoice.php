<?php
// backend/api/shop/lib/invoice.php
//
// Aus dem Warenkorb wird eine Rechnung. Die Bridge tat das in rund 200 Zeilen
// selbst — Nummernkreis, Positionen, Buchungssaetze. Hier bleibt ein Ablauf,
// der die vorhandenen Bausteine aneinanderreiht:
//
//   Versandkosten   cartApplyShipping()
//   Lieferadresse   shiptoCreate()
//   ar + invoice    hier, aus den Warenkorbzeilen
//   acc_trans       postArInvoiceToLedger()   (faktura.php)
//   Verknuepfung    ar_link_hugoshop
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
 * @throws ApiError CART_NOT_FOUND, CART_EMPTY, SHOP_DATABASE_ERROR, LEDGER_ERROR
 */
function createShopInvoice($db, string $uuid, array $lieferadresse = [], ?array $paypal = null): array {
    $context    = shopContextCustomer($db, $uuid);
    $customerId = (int)$context['customer_id'];

    if (empty($context['cart_uuid'])) {
        throw new ApiError("CART_NOT_FOUND", 'Zu dieser Sitzung gibt es keinen Warenkorb');
    }
    $cartUuid = $context['cart_uuid'];

    cartApplyShipping($db, $cartUuid, $customerId);

    $korb = cartRead($db, $cartUuid, $customerId, false);
    if (empty($korb['positions'])) {
        throw new ApiError("CART_EMPTY", 'Der Warenkorb ist leer');
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

        // Die Positionen stehen bereits in der Datenbank — sie muessen nicht
        // durch PHP. Ein Vorgang statt einer Schleife ueber INSERTs.
        $db->execute(
            "INSERT INTO invoice (trans_id, parts_id, description, qty, sellprice, fxsellprice,
                                  discount, unit, position, lastcost, base_qty, allocated,
                                  marge_total, marge_percent, serialnumber, active_price_source,
                                  longdescription, mtime)
             SELECT :ar_id, p.id, p.description, c.amount, p.sellprice, p.sellprice,
                    0, p.unit, ROW_NUMBER() OVER (ORDER BY c.id), COALESCE(p.lastcost, 0), 1, 0,
                    0, 0, '', :preisquelle, COALESCE(p.notes, ''), NOW()
               FROM cart_parts_hugoshop c
               JOIN parts p ON p.id = c.parts_id
              WHERE c.cart_uuid = :cart_uuid",
            [
                ':ar_id'       => $arId,
                ':cart_uuid'   => $cartUuid,
                ':preisquelle' => shopConfigValue($db, 'shop_active_price_source', 'master_data/sellprice'),
            ]
        );

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError("SHOP_DATABASE_ERROR", 'Rechnung konnte nicht angelegt werden: '.$e->getMessage());
    }

    shopInvoicePostToLedger($db, $arId);

    $arLink = shopNewContextUuid();
    $db->execute(
        "INSERT INTO ar_link_hugoshop (ar_id, uuid, paypal, paypal_order_id, paypal_capture_id,
                                       payment_status, payment_reason, payment_mtime)
         VALUES (:ar_id, :uuid, :payer, :order_id, :capture_id, :status, :reason, :mtime)",
        [
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
 * Rechnungen des Kunden
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return array
 */
function customerInvoices($db, int $customerId): array {
    $kunde = shopInvoicingData($db, $customerId);

    return [
        'name'   => $kunde['name'],
        'orders' => $db->getAll(
            "SELECT ar.id, ar.invnumber, ar.transdate AS date,
                    TRUNC(ar.amount, 2) AS amount,
                    (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency,
                    (SELECT COUNT(*) FROM invoice WHERE trans_id = ar.id) AS positions,
                    al.uuid AS ar_link, al.payment_status
               FROM ar
               LEFT JOIN ar_link_hugoshop al ON al.ar_id = ar.id
              WHERE ar.customer_id = :customer_id
              ORDER BY ar.id DESC",
            [':customer_id' => $customerId]
        ),
    ];
}

/**
 * Eine Rechnung des Kunden mit Positionen, Steuern und Lieferanschrift
 *
 * Die Kundennummer steht in der Bedingung: ohne sie liesse sich durch
 * Hochzaehlen der Kennung jede fremde Rechnung lesen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int $arId Rechnung
 * @return array
 * @throws ApiError INVOICE_NOT_FOUND
 */
function customerInvoice($db, int $customerId, int $arId): array {
    $rechnung = $db->getOne(
        "SELECT ar.id, ar.invnumber, ar.transdate AS invdate, ar.duedate,
                TRUNC(ar.amount, 2) AS invtotal, TRUNC(ar.netamount, 2) AS subtotal,
                ar.customer_id, ar.taxzone_id, ar.shipto_id,
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency,
                c.name, c.street, c.zipcode, c.city, c.country, c.customernumber,
                c.email, c.phone,
                al.uuid AS ar_link, al.payment_status, al.payment_reason
           FROM ar
           JOIN customer c ON c.id = ar.customer_id
           LEFT JOIN ar_link_hugoshop al ON al.ar_id = ar.id
          WHERE ar.id = :ar_id AND ar.customer_id = :customer_id",
        [':ar_id' => $arId, ':customer_id' => $customerId]
    );

    if (!$rechnung) {
        throw new ApiError("INVOICE_NOT_FOUND", 'Diese Rechnung gibt es nicht');
    }

    return [
        'invoice'   => $rechnung,
        'positions' => shopInvoicePositions($db, $arId),
        'taxes'     => shopInvoiceTaxes($db, $arId),
        'shipping'  => shopInvoiceShippingAddress($db, $arId),
    ];
}

/**
 * Positionen einer Rechnung
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return array
 */
function shopInvoicePositions($db, int $arId): array {
    return $db->getAll(
        "SELECT i.parts_id, i.position AS runningnumber, p.partnumber AS number,
                i.description, TRUNC(i.qty) AS qty, COALESCE(i.unit, p.unit) AS unit,
                TRUNC(i.discount * 100) AS p_discount,
                TRUNC(i.fxsellprice, 2) AS sellprice,
                TRUNC(i.qty * i.sellprice, 2) AS linetotal,
                pe.hugoshop_images ->> 0 AS thumbnail
           FROM invoice i
           JOIN parts p ON p.id = i.parts_id
           LEFT JOIN parts_ext pe ON pe.parts_id = i.parts_id
          WHERE i.trans_id = :ar_id
          ORDER BY i.position",
        [':ar_id' => $arId]
    );
}

/**
 * Steueranteile einer Rechnung
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return array
 */
function shopInvoiceTaxes($db, int $arId): array {
    return $db->getAll(
        "SELECT ch.description AS taxdescription, TRUNC(at.amount, 2) AS tax
           FROM acc_trans at
           JOIN chart ch ON ch.id = at.chart_id
          WHERE at.trans_id = :ar_id
            AND at.chart_link LIKE 'AR_tax%'
          ORDER BY ch.accno",
        [':ar_id' => $arId]
    );
}

/**
 * Lieferanschrift einer Rechnung, sonst die Rechnungsanschrift
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return array|false
 */
function shopInvoiceShippingAddress($db, int $arId) {
    return $db->getOne(
        "SELECT COALESCE(s.shiptoname, c.name)       AS name,
                COALESCE(s.shiptostreet, c.street)   AS street,
                COALESCE(s.shiptozipcode, c.zipcode) AS zipcode,
                COALESCE(s.shiptocity, c.city)       AS city,
                COALESCE(s.shiptocountry, c.country) AS country,
                COALESCE(s.shiptophone, c.phone)     AS phone,
                COALESCE(s.shiptoemail, c.email)     AS email
           FROM ar
           JOIN customer c ON c.id = ar.customer_id
           LEFT JOIN shipto s ON s.shipto_id = ar.shipto_id
          WHERE ar.id = :ar_id",
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
 * @param object $db Company-Datenbankverbindung
 * @param string $arLink Kennung aus ar_link_hugoshop
 * @return array
 * @throws ApiError INVOICE_LINK_NOT_FOUND
 */
function invoiceSummaryByLink($db, string $arLink): array {
    $zeile = $db->getOne(
        "SELECT al.ar_id, al.paypal, al.payment_status, al.payment_reason,
                ar.invnumber, TRUNC(ar.amount, 2) AS amount,
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency,
                c.email, c.phone
           FROM ar_link_hugoshop al
           JOIN ar ON ar.id = al.ar_id
           JOIN customer c ON c.id = ar.customer_id
          WHERE al.uuid = :ar_link",
        [':ar_link' => $arLink]
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
        'shipping'                   => shopInvoiceShippingAddress($db, (int)$zeile['ar_id']),
    ];
}

/**
 * Erzeugt das Rechnungs-PDF
 *
 * Nutzt die Druckaufbereitung von OpensourceERP. Die Bridge rief dafuer ein
 * Perl-Skript und pdflatex ueber die Shell und brauchte eine installierte
 * kivitendo-Instanz; davon bleibt nichts.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return array{path: string, filename: string}
 * @throws ApiError INVOICE_PDF_ERROR
 */
function shopInvoicePdf($db, int $arId): array {
    $fehler = null;
    $pdf = renderDocumentPdfFile($db, $arId, 'invoice', null, false, $fehler);

    if (false === $pdf) {
        throw new ApiError("INVOICE_PDF_ERROR", 'Rechnungs-PDF konnte nicht erzeugt werden: '.(string)$fehler);
    }

    return ['path' => $pdf['path'], 'filename' => $pdf['filename']];
}

/**
 * Rechnung zu einem Link, geprueft
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $arLink Kennung aus ar_link_hugoshop
 * @return int Rechnungs-Kennung
 * @throws ApiError INVOICE_LINK_NOT_FOUND
 */
function shopInvoiceIdByLink($db, string $arLink): int {
    $zeile = $db->getOne(
        "SELECT ar_id FROM ar_link_hugoshop WHERE uuid = :ar_link",
        [':ar_link' => $arLink]
    );

    if (!$zeile) {
        throw new ApiError("INVOICE_LINK_NOT_FOUND", 'Zu diesem Link gibt es keine Bestellung');
    }
    return (int)$zeile['ar_id'];
}
