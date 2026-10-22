<?php
// backend/api/portal/lib/invoices.php
//
// Rechnungen des Kunden im Portal: Liste, Einzelansicht, PDF und der
// Rechnungslink. Der Link (invoice_link_oserp.uuid) ist das Geheimnis: wer ihn
// hat, sieht die Rechnung, auch ohne Anmeldung — so kommt ein Gast nach einer
// Bestellung an seine Rechnung, und so führen die Links in Mails.
//
// Der Kern zeigt, was in ar und invoice steht. Was eine Erweiterung dazu
// weiß — Zahlstand bei PayPal, Lieferstatus, Produktseite und Vorschaubild
// einer Position —, hängt sie über die Haken invoice_list, invoice und
// invoice_positions an.

/**
 * Legt den Rechnungslink an oder liefert den vorhandenen
 *
 * Je Rechnung genau einer: eine zweite Anfrage gibt denselben zurück, ohne
 * die Webseite zu ändern.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @param int|null $website Webseite, auf der die Rechnung entstand
 * @return string uuid
 */
function invoiceLinkCreate($db, int $arId, ?int $website): string {
    $zeile = $db->getOne(
        "INSERT INTO invoice_link_oserp (uuid, ar_id, website_id)
         VALUES (:uuid, :ar_id, CAST(:website AS integer))
         ON CONFLICT (ar_id) DO UPDATE SET active = invoice_link_oserp.active
         RETURNING uuid",
        [':uuid' => portalNewUuid(), ':ar_id' => $arId, ':website' => $website]
    );
    return (string)$zeile['uuid'];
}

/**
 * Rechnung zu einem Link, geprüft
 *
 * Der Link gilt nur auf der Webseite, von der er stammt — eine zweite
 * Webseite desselben Mandanten zeigt fremde Rechnungen nicht an.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $arLink Kennung aus invoice_link_oserp
 * @param int $website Webseite der Anfrage
 * @return int Rechnungs-Kennung
 * @throws ApiError INVOICE_LINK_NOT_FOUND
 */
function invoiceIdByLink($db, string $arLink, int $website): int {
    $zeile = $db->getOne(
        "SELECT ar_id FROM invoice_link_oserp
          WHERE uuid = :ar_link AND website_id = CAST(:website AS integer)",
        [':ar_link' => $arLink, ':website' => $website]
    );

    if (!$zeile) {
        throw new ApiError("INVOICE_LINK_NOT_FOUND", 'Zu diesem Link gibt es keine Bestellung');
    }
    return (int)$zeile['ar_id'];
}

/**
 * Rechnungen des Kunden
 *
 * Alle Rechnungen des Kundenstamms, nicht nur die der Webseite: der Kunde
 * der Werkstatt sieht hier auch die Werkstattrechnung. Den Zahlstand und den
 * Lieferstatus einer Shop-Bestellung hängt der Shop an (Haken invoice_list).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return array{name: string, orders: array}
 */
function customerInvoices($db, int $customerId): array {
    $kunde = customerInvoicingData($db, $customerId);

    $rechnungen = $db->getAll(
        "SELECT ar.id, ar.invnumber, ar.transdate AS date, ar.duedate,
                TRUNC(ar.amount, 2) AS amount, TRUNC(ar.paid, 2) AS paid,
                COALESCE(ar.storno, false) AS storno,
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency,
                (SELECT COUNT(*) FROM invoice WHERE trans_id = ar.id) AS positions,
                il.uuid AS ar_link,
                NULL::text AS payment_status, NULL::text AS delivery_status
           FROM ar
           LEFT JOIN invoice_link_oserp il ON il.ar_id = ar.id
          WHERE ar.customer_id = :customer_id AND ar.invoice
          ORDER BY ar.id DESC",
        [':customer_id' => $customerId]
    ) ?: [];

    return [
        'name'   => $kunde['name'],
        'orders' => portalHookFilter('invoice_list', $rechnungen, $db, $customerId),
    ];
}

/**
 * Eine Rechnung des Kunden mit Positionen, Steuern und Lieferanschrift
 *
 * Die Kundennummer steht in der Bedingung: ohne sie ließe sich durch
 * Hochzählen der Kennung jede fremde Rechnung lesen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int $arId Rechnung
 * @param int $website Webseite der Anfrage — für die Links der Positionen; 0 = ohne
 * @return array
 * @throws ApiError INVOICE_NOT_FOUND
 */
function customerInvoice($db, int $customerId, int $arId, int $website = 0): array {
    $rechnung = $db->getOne(
        "SELECT ar.id, ar.invnumber, ar.transdate AS invdate, ar.duedate,
                TRUNC(ar.amount, 2) AS invtotal, TRUNC(ar.netamount, 2) AS subtotal,
                TRUNC(ar.paid, 2) AS paid, COALESCE(ar.storno, false) AS storno,
                ar.customer_id, ar.taxzone_id, ar.shipto_id,
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency,
                c.name, c.street, c.zipcode, c.city, c.country, c.customernumber,
                c.email, c.phone,
                il.uuid AS ar_link,
                NULL::text AS payment_status, NULL::text AS payment_reason, NULL::text AS delivery_status
           FROM ar
           JOIN customer c ON c.id = ar.customer_id
           LEFT JOIN invoice_link_oserp il ON il.ar_id = ar.id
          WHERE ar.id = :ar_id AND ar.customer_id = :customer_id",
        [':ar_id' => $arId, ':customer_id' => $customerId]
    );

    if (!$rechnung) {
        throw new ApiError("INVOICE_NOT_FOUND", 'Diese Rechnung gibt es nicht');
    }

    return [
        'invoice'   => portalHookFilter('invoice', $rechnung, $db, $arId),
        'positions' => invoicePositions($db, $arId, $website),
        'taxes'     => invoiceTaxes($db, $arId),
        'shipping'  => invoiceShippingAddress($db, $arId),
    ];
}

/**
 * Positionen einer Rechnung
 *
 * Produktseite und Vorschaubild hängt der Shop an (Haken invoice_positions),
 * wenn die Webseite einen HugoShop hat; sonst bleiben die Adressen leer und
 * die Widgets zeigen keinen Link.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @param int $website Webseite der Anfrage, 0 = keine
 * @return array
 */
function invoicePositions($db, int $arId, int $website = 0): array {
    $positionen = $db->getAll(
        "SELECT i.id AS invoice_id, i.parts_id, i.position AS runningnumber, p.partnumber AS number,
                i.description, TRUNC(i.qty) AS qty, COALESCE(i.unit, p.unit) AS unit,
                TRUNC(i.discount * 100) AS p_discount,
                TRUNC(i.fxsellprice, 2) AS sellprice,
                TRUNC(i.qty * i.sellprice, 2) AS linetotal,
                '' AS product_url, '' AS thumbnail_url, NULL::text AS delivery_term
           FROM invoice i
           LEFT JOIN parts p ON p.id = i.parts_id
          WHERE i.trans_id = :ar_id
          ORDER BY i.position",
        [':ar_id' => $arId]
    ) ?: [];

    return portalHookFilter('invoice_positions', $positionen, $db, $arId, $website);
}

/**
 * Steueranteile einer Rechnung
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return array
 */
function invoiceTaxes($db, int $arId): array {
    return $db->getAll(
        "SELECT ch.description AS taxdescription, TRUNC(at.amount, 2) AS tax
           FROM acc_trans at
           JOIN chart ch ON ch.id = at.chart_id
          WHERE at.trans_id = :ar_id
            AND at.chart_link LIKE 'AR_tax%'
          ORDER BY ch.accno",
        [':ar_id' => $arId]
    ) ?: [];
}

/**
 * Lieferanschrift einer Rechnung, sonst die Rechnungsanschrift
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return array|false
 */
function invoiceShippingAddress($db, int $arId) {
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
 * Erzeugt das Rechnungs-PDF über die Druckaufbereitung von OpensourceERP
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return array{path: string, filename: string}
 * @throws ApiError INVOICE_PDF_ERROR
 */
function invoicePdf($db, int $arId): array {
    $fehler = null;
    $pdf = renderDocumentPdfFile($db, $arId, 'invoice', null, false, $fehler);

    if (false === $pdf) {
        throw new ApiError("INVOICE_PDF_ERROR", 'Rechnungs-PDF konnte nicht erzeugt werden: '.(string)$fehler);
    }

    return ['path' => $pdf['path'], 'filename' => $pdf['filename']];
}

/**
 * Prüft, ob die Rechnung dem Kunden gehört
 *
 * Ohne diese Prüfung ließe sich durch Hochzählen der Kennung jede fremde
 * Rechnung herunterladen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int $arId Rechnung
 * @return int Rechnungs-Kennung
 * @throws ApiError INVOICE_NOT_FOUND
 */
function invoiceOfCustomer($db, int $customerId, int $arId): int {
    $zeile = $db->getOne(
        "SELECT id FROM ar WHERE id = :ar_id AND customer_id = :customer_id",
        [':ar_id' => $arId, ':customer_id' => $customerId]
    );
    if (!$zeile) {
        throw new ApiError("INVOICE_NOT_FOUND", 'Diese Rechnung gibt es nicht');
    }
    return $arId;
}
