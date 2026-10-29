<?php
// backend/api/portal/lib/documents.php
//
// Belege des Kunden im Kundenkonto (Seite „Belege“): Rechnungen, Aufträge,
// Angebote, Lieferscheine, Reklamationen. Welche davon eine Webseite zeigt,
// sagen ihre Einstellungen documents_* (Firmenkonfiguration → Kundenportal;
// Vorgabe: nur Rechnungen).
//
// Rechnungen laufen weiter über invoices.php — dort hängen Rechnungslink und
// die Haken des Shops (Zahlstand, Lieferstatus, Produktlinks). Die übrigen
// Belege liest diese Datei aus oe, delivery_orders und reclamations, in
// derselben Form, damit das Widget <portal-account-documents> alle gleich
// darstellt: id, number, date, amount, currency, positions, status.
//
// Alte und neue kivitendo-Schemata: oe trägt die Belegart neu in record_type,
// alt in quotation. Die Abfragen lesen beide über eine Rückfallzeile
// (schema_fallback), wie backend/api/faktura/faktura.php.

/**
 * Die Belegarten des Kerns, in der Reihenfolge der Reiter im Widget
 *
 * setting: Schalter der Webseite; print: Belegart der Druckaufbereitung
 * (print.php, PRINT_TEMPLATE_MAP) — leer, wenn es keine Vorlage gibt;
 * default: Vorgabe des Schalters. Erweiterungen hängen eigene Arten über den
 * Haken document_types an (der Shop seine Bestellungen, shop_orders) und
 * liefern deren Zeilen und Ansicht über document_rows und document_detail.
 */
const PORTAL_DOCUMENT_TYPES = [
    'invoices'        => ['setting' => 'documents_invoices',        'print' => 'invoice', 'default' => true],
    'orders'          => ['setting' => 'documents_orders',          'print' => 'order'],
    'quotations'      => ['setting' => 'documents_quotations',      'print' => 'quotation'],
    'delivery_orders' => ['setting' => 'documents_delivery_orders', 'print' => 'delivery_order'],
    // Reklamationen druckt OpensourceERP noch nicht (print.php): Liste und
    // Ansicht ja, kein PDF
    'reclamations'    => ['setting' => 'documents_reclamations',    'print' => ''],
];

/**
 * Alle Belegarten samt Erweiterungen (Haken document_types)
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return array Belegart => [setting, print, default]
 */
function portalDocumentTypes($db, int $website): array {
    return portalHookFilter('document_types', PORTAL_DOCUMENT_TYPES, $db, $website);
}

/**
 * Belegarten, die die Webseite im Kundenkonto zeigt
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return string[] Schlüssel aus portalDocumentTypes()
 */
function websiteDocumentTypes($db, int $website): array {
    $typen = [];
    foreach (portalDocumentTypes($db, $website) as $typ => $info) {
        if (websiteBool($db, $website, $info['setting'], (bool)($info['default'] ?? false))) {
            $typen[] = $typ;
        }
    }
    return $typen;
}

/**
 * Gibt es zur Belegart ein PDF?
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param string $typ Belegart
 * @return bool
 */
function documentTypeHasPdf($db, int $website, string $typ): bool {
    return '' !== (string)(portalDocumentTypes($db, $website)[$typ]['print'] ?? '');
}

/**
 * Prüft die Belegart einer Anfrage gegen die Einstellungen der Webseite
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param string $typ Belegart aus der Anfrage; leer = die erste eingeschaltete
 * @return string Belegart
 * @throws ApiError DOCUMENTS_DISABLED
 */
function documentTypeRequire($db, int $website, string $typ): string {
    $erlaubt = websiteDocumentTypes($db, $website);
    if ('' === $typ) {
        $typ = $erlaubt[0] ?? '';
    }
    if ('' === $typ || !in_array($typ, $erlaubt, true)) {
        throw new ApiError('DOCUMENTS_DISABLED', 'Diese Belege zeigt das Kundenkonto nicht an');
    }
    return $typ;
}

/**
 * Belege einer Art für den Kunden
 *
 * Der Haken document_rows läuft über jede Art: eine Erweiterung liefert so
 * die Zeilen ihrer eigenen Arten (der Shop seine Bestellungen) und darf die
 * des Kerns anpassen (der Shop nimmt seine Bestellungen aus den Rechnungen,
 * wenn sie einen eigenen Reiter haben).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $typ Belegart
 * @param int $website Webseite der Anfrage
 * @return array Zeilen: id, number, date, reqdate, amount (null ohne Beträge), currency, positions, status
 */
function customerDocuments($db, int $customerId, string $typ, int $website = 0): array {
    switch ($typ) {
        case 'invoices':
            $zeilen = documentInvoiceRows(customerInvoices($db, $customerId)['orders']);
            break;

        case 'orders':
        case 'quotations':
            $zeilen = $db->getAll(
                "SELECT x.* FROM (SELECT NULL::text AS record_type, NULL::boolean AS quotation) AS schema_fallback
                 CROSS JOIN LATERAL (
                     SELECT o.id, ".('quotations' === $typ ? 'o.quonumber' : 'o.ordnumber')." AS number,
                            o.transdate AS date, o.reqdate,
                            TRUNC(o.amount, 2) AS amount,
                            (SELECT name FROM currencies WHERE id = o.currency_id) AS currency,
                            (SELECT COUNT(*) FROM orderitems i WHERE i.trans_id = o.id) AS positions,
                            o.closed, o.delivered,
                            COALESCE(record_type::text,
                                     CASE WHEN quotation THEN 'sales_quotation' ELSE 'sales_order' END) AS rt
                       FROM oe o
                      WHERE o.customer_id = :customer_id AND o.vendor_id IS NULL
                 ) AS x
                 WHERE x.rt = ANY(CAST(:arten AS text[]))
                 ORDER BY x.id DESC",
                [':customer_id' => $customerId, ':arten' => documentOeTypesSql($typ)]
            ) ?: [];
            break;

        case 'delivery_orders':
            $zeilen = $db->getAll(
                "SELECT d.id, d.donumber AS number, d.transdate AS date, d.reqdate,
                        NULL::numeric AS amount,
                        (SELECT name FROM currencies WHERE id = d.currency_id) AS currency,
                        (SELECT COUNT(*) FROM delivery_order_items i WHERE i.delivery_order_id = d.id) AS positions,
                        d.closed, d.delivered
                   FROM delivery_orders d
                  WHERE d.customer_id = :customer_id AND d.vendor_id IS NULL
                  ORDER BY d.id DESC",
                [':customer_id' => $customerId]
            ) ?: [];
            break;

        case 'reclamations':
            if (!documentReclamationsAvailable($db)) {
                return [];
            }
            $zeilen = $db->getAll(
                "SELECT r.id, r.record_number AS number, r.transdate AS date, r.reqdate,
                        TRUNC(r.amount, 2) AS amount,
                        (SELECT name FROM currencies WHERE id = r.currency_id) AS currency,
                        (SELECT COUNT(*) FROM reclamation_items i WHERE i.reclamation_id = r.id) AS positions,
                        r.closed, r.delivered
                   FROM reclamations r
                  WHERE r.customer_id = :customer_id AND r.vendor_id IS NULL
                  ORDER BY r.id DESC",
                [':customer_id' => $customerId]
            ) ?: [];
            break;

        default:
            // Art einer Erweiterung: der Haken liefert die fertigen Zeilen
            $zeilen = portalHookFilter('document_rows', null, $db, $customerId, $typ, $website);
            if (!is_array($zeilen)) {
                throw new ApiError('DOCUMENTS_DISABLED', 'Unbekannte Belegart');
            }
            return $zeilen;
    }

    if ('invoices' !== $typ) {
        foreach ($zeilen as &$z) {
            $z['status'] = documentStatus($z);
            unset($z['rt']);
        }
        unset($z);
    }
    $zeilen = portalHookFilter('document_rows', $zeilen, $db, $customerId, $typ, $website);
    return is_array($zeilen) ? array_values($zeilen) : [];
}

/**
 * Rechnungszeilen (customerInvoices) in die Form der Belegliste
 *
 * Auch für den Shop, dessen Bestellungen Rechnungen sind.
 *
 * @param array $rechnungen Zeilen aus customerInvoices()['orders']
 * @return array
 */
function documentInvoiceRows(array $rechnungen): array {
    return array_map(function (array $r): array {
        $r['number']  = $r['invnumber'];
        $r['reqdate'] = null;
        $r['status']  = documentInvoiceStatus($r);
        return $r;
    }, $rechnungen);
}

/**
 * Ein Beleg des Kunden mit Positionen und Lieferanschrift
 *
 * Rechnungen kommen aus customerInvoice() (Positionen mit Produktlinks des
 * Shops, Steueranteile); die anderen Belege in derselben Form: document,
 * positions, shipping — taxes nur bei Rechnungen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $typ Belegart
 * @param int $id Beleg
 * @param int $website Webseite der Anfrage (Links der Rechnungspositionen)
 * @return array{type: string, document: array, positions: array, shipping: array|false, taxes: array}
 * @throws ApiError DOCUMENT_NOT_FOUND
 */
function customerDocument($db, int $customerId, string $typ, int $id, int $website = 0): array {
    if ('invoices' === $typ) {
        return documentInvoiceDetail($db, $customerId, $typ, $id, $website);
    }
    if (!isset(PORTAL_DOCUMENT_TYPES[$typ])) {
        // Art einer Erweiterung: der Haken liefert die fertige Ansicht
        $detail = portalHookFilter('document_detail', null, $db, $customerId, $typ, $id, $website);
        if (!is_array($detail)) {
            throw new ApiError('DOCUMENT_NOT_FOUND', 'Diesen Beleg gibt es nicht');
        }
        return $detail;
    }

    $kopf = documentHead($db, $customerId, $typ, $id);
    if (!$kopf) {
        throw new ApiError('DOCUMENT_NOT_FOUND', 'Diesen Beleg gibt es nicht');
    }
    $kopf['status'] = documentStatus($kopf);
    unset($kopf['rt']);

    return [
        'type'      => $typ,
        'document'  => $kopf,
        'positions' => documentPositions($db, $typ, $id),
        'shipping'  => documentShippingAddress($db, $typ, $id),
        'taxes'     => [],
    ];
}

/**
 * Ansicht einer Rechnung (customerInvoice) in der Form der Belegansicht
 *
 * Auch für den Shop, dessen Bestellungen Rechnungen sind.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $typ Belegart, unter der die Ansicht läuft (invoices, shop_orders)
 * @param int $arId Rechnung
 * @param int $website Webseite der Anfrage
 * @return array{type: string, document: array, positions: array, shipping: array|false, taxes: array}
 * @throws ApiError INVOICE_NOT_FOUND
 */
function documentInvoiceDetail($db, int $customerId, string $typ, int $arId, int $website): array {
    $r = customerInvoice($db, $customerId, $arId, $website);
    $kopf = $r['invoice'];
    $kopf['number']  = $kopf['invnumber'];
    $kopf['date']    = $kopf['invdate'];
    $kopf['amount']  = $kopf['invtotal'];
    $kopf['reqdate'] = null;
    $kopf['status']  = documentInvoiceStatus($kopf);
    return ['type' => $typ, 'document' => $kopf, 'positions' => $r['positions'], 'shipping' => $r['shipping'], 'taxes' => $r['taxes']];
}

/**
 * Kopf eines Belegs (ohne Rechnungen), geprüft auf den Kunden
 *
 * @return array|null
 */
function documentHead($db, int $customerId, string $typ, int $id): ?array {
    switch ($typ) {
        case 'orders':
        case 'quotations':
            return $db->getOne(
                "SELECT x.* FROM (SELECT NULL::text AS record_type, NULL::boolean AS quotation) AS schema_fallback
                 CROSS JOIN LATERAL (
                     SELECT o.id, ".('quotations' === $typ ? 'o.quonumber' : 'o.ordnumber')." AS number,
                            o.cusordnumber, o.transdate AS date, o.reqdate,
                            TRUNC(o.amount, 2) AS amount, TRUNC(o.netamount, 2) AS netamount,
                            COALESCE(o.taxincluded, false) AS taxincluded,
                            (SELECT name FROM currencies WHERE id = o.currency_id) AS currency,
                            COALESCE(o.notes, '') AS notes, o.closed, o.delivered,
                            COALESCE(record_type::text,
                                     CASE WHEN quotation THEN 'sales_quotation' ELSE 'sales_order' END) AS rt
                       FROM oe o
                      WHERE o.id = :id AND o.customer_id = :customer_id AND o.vendor_id IS NULL
                 ) AS x
                 WHERE x.rt = ANY(CAST(:arten AS text[]))",
                [':id' => $id, ':customer_id' => $customerId, ':arten' => documentOeTypesSql($typ)]
            ) ?: null;

        case 'delivery_orders':
            return $db->getOne(
                "SELECT d.id, d.donumber AS number, d.cusordnumber, d.transdate AS date, d.reqdate,
                        NULL::numeric AS amount, NULL::numeric AS netamount, false AS taxincluded,
                        (SELECT name FROM currencies WHERE id = d.currency_id) AS currency,
                        COALESCE(d.notes, '') AS notes, d.closed, d.delivered
                   FROM delivery_orders d
                  WHERE d.id = :id AND d.customer_id = :customer_id AND d.vendor_id IS NULL",
                [':id' => $id, ':customer_id' => $customerId]
            ) ?: null;

        case 'reclamations':
            if (!documentReclamationsAvailable($db)) {
                return null;
            }
            return $db->getOne(
                "SELECT r.id, r.record_number AS number, r.cv_record_number AS cusordnumber,
                        r.transdate AS date, r.reqdate,
                        TRUNC(r.amount, 2) AS amount, TRUNC(r.netamount, 2) AS netamount,
                        COALESCE(r.taxincluded, false) AS taxincluded,
                        (SELECT name FROM currencies WHERE id = r.currency_id) AS currency,
                        COALESCE(r.notes, '') AS notes, r.closed, r.delivered
                   FROM reclamations r
                  WHERE r.id = :id AND r.customer_id = :customer_id AND r.vendor_id IS NULL",
                [':id' => $id, ':customer_id' => $customerId]
            ) ?: null;
    }
    return null;
}

/**
 * Positionen eines Belegs (ohne Rechnungen)
 *
 * Lieferscheine ohne Preise — der Lieferschein nennt keine; sellprice und
 * linetotal bleiben dort NULL, das Widget lässt die Preiszeile weg.
 *
 * @return array
 */
function documentPositions($db, string $typ, int $id): array {
    $preise = 'delivery_orders' !== $typ;
    // discount ist real, qty in reclamation_items auch: Produkte werden double
    // precision, TRUNC(x, 2) will numeric
    $preisSpalten = $preise
        ? "TRUNC(i.discount * 100) AS p_discount,
           TRUNC(i.sellprice, 2) AS sellprice,
           TRUNC(CAST(i.qty * i.sellprice * (1 - COALESCE(i.discount, 0)) AS numeric), 2) AS linetotal"
        : "NULL::numeric AS p_discount, NULL::numeric AS sellprice, NULL::numeric AS linetotal";
    switch ($typ) {
        case 'orders':
        case 'quotations':
            $tabelle = 'orderitems';
            $fk = 'trans_id';
            $extra = '';
            break;
        case 'delivery_orders':
            $tabelle = 'delivery_order_items';
            $fk = 'delivery_order_id';
            $extra = '';
            break;
        case 'reclamations':
            $tabelle = 'reclamation_items';
            $fk = 'reclamation_id';
            // Grund der Reklamation, wie er dem Kunden gegenüber genannt wird
            $extra = ", COALESCE(NULLIF(i.reason_description_ext, ''),
                               (SELECT rr.description FROM reclamation_reasons rr WHERE rr.id = i.reason_id)) AS reason";
            break;
        default:
            return [];
    }
    return $db->getAll(
        "SELECT i.id, i.parts_id, i.position AS runningnumber, p.partnumber AS number,
                i.description, TRUNC(CAST(i.qty AS numeric), 2) AS qty, COALESCE(i.unit, p.unit) AS unit,
                $preisSpalten,
                '' AS product_url, '' AS thumbnail_url, NULL::text AS delivery_term
                $extra
           FROM $tabelle i
           LEFT JOIN parts p ON p.id = i.parts_id
          WHERE i.$fk = :id
          ORDER BY i.position",
        [':id' => $id]
    ) ?: [];
}

/**
 * Lieferanschrift eines Belegs (ohne Rechnungen), sonst die Rechnungsanschrift
 *
 * @return array|false
 */
function documentShippingAddress($db, string $typ, int $id) {
    $tabelle = ['orders' => 'oe', 'quotations' => 'oe', 'delivery_orders' => 'delivery_orders', 'reclamations' => 'reclamations'][$typ] ?? '';
    if ('' === $tabelle) {
        return false;
    }
    return $db->getOne(
        "SELECT COALESCE(s.shiptoname, c.name)       AS name,
                COALESCE(s.shiptostreet, c.street)   AS street,
                COALESCE(s.shiptozipcode, c.zipcode) AS zipcode,
                COALESCE(s.shiptocity, c.city)       AS city,
                COALESCE(s.shiptocountry, c.country) AS country,
                COALESCE(s.shiptophone, c.phone)     AS phone,
                COALESCE(s.shiptoemail, c.email)     AS email
           FROM $tabelle b
           JOIN customer c ON c.id = b.customer_id
           LEFT JOIN shipto s ON s.shipto_id = b.shipto_id
          WHERE b.id = :id",
        [':id' => $id]
    );
}

/**
 * Das PDF eines Belegs über die Druckaufbereitung
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $typ Belegart
 * @param int $id Beleg
 * @param int $website Webseite der Anfrage (Belegarten der Erweiterungen)
 * @return array{path: string, filename: string}
 * @throws ApiError DOCUMENT_PDF_UNAVAILABLE, INVOICE_PDF_ERROR
 */
function documentPdf($db, string $typ, int $id, int $website = 0): array {
    $art = (string)(portalDocumentTypes($db, $website)[$typ]['print'] ?? '');
    if ('' === $art) {
        throw new ApiError('DOCUMENT_PDF_UNAVAILABLE', 'Für diesen Beleg gibt es kein PDF');
    }
    if ('invoice' === $art) {
        return invoicePdf($db, $id);
    }
    $fehler = null;
    $pdf = renderDocumentPdfFile($db, $id, $art, null, false, $fehler);
    if (false === $pdf) {
        throw new ApiError('INVOICE_PDF_ERROR', 'Das PDF konnte nicht erzeugt werden: '.(string)$fehler);
    }
    return ['path' => $pdf['path'], 'filename' => $pdf['filename']];
}

/**
 * Prüft, ob der Beleg dem Kunden gehört
 *
 * Ohne diese Prüfung ließe sich durch Hochzählen der Kennung jeder fremde
 * Beleg herunterladen.
 *
 * @param int $website Webseite der Anfrage (Belegarten der Erweiterungen)
 * @return int Beleg-Kennung
 * @throws ApiError DOCUMENT_NOT_FOUND
 */
function documentOfCustomer($db, int $customerId, string $typ, int $id, int $website = 0): int {
    if ('invoices' === $typ) {
        return invoiceOfCustomer($db, $customerId, $id);
    }
    $da = isset(PORTAL_DOCUMENT_TYPES[$typ])
        ? documentHead($db, $customerId, $typ, $id)
        : portalHookFilter('document_detail', null, $db, $customerId, $typ, $id, $website);
    if (!$da) {
        throw new ApiError('DOCUMENT_NOT_FOUND', 'Diesen Beleg gibt es nicht');
    }
    return $id;
}

// ── Hilfen ──

/**
 * Belegarten in oe.record_type je Reiter: Aufträge auch der Auftragseingang
 *
 * @return string PostgreSQL-Array-Literal
 */
function documentOeTypesSql(string $typ): string {
    return 'quotations' === $typ ? '{sales_quotation}' : '{sales_order,sales_order_intake}';
}

/**
 * Gibt es die Tabelle der Reklamationen? Ältere kivitendo-Schemata haben sie nicht
 */
function documentReclamationsAvailable($db): bool {
    static $da = null;
    if (null === $da) {
        $zeile = $db->getOne("SELECT to_regclass('public.reclamations') IS NOT NULL AS da");
        $da = in_array($zeile['da'] ?? false, [true, 't', 1, '1'], true);
    }
    return $da;
}

/**
 * Status eines Belegs aus closed/delivered: delivered, closed oder open
 */
function documentStatus(array $z): string {
    $wahr = fn($v) => in_array($v, [true, 't', 1, '1'], true);
    if ($wahr($z['delivered'] ?? false)) {
        return 'delivered';
    }
    return $wahr($z['closed'] ?? false) ? 'closed' : 'open';
}

/**
 * Status einer Rechnung: storno, paid, partly_paid oder unpaid
 */
function documentInvoiceStatus(array $r): string {
    if (in_array($r['storno'] ?? false, [true, 't', 1, '1'], true)) {
        return 'storno';
    }
    $betrag = (float)($r['amount'] ?? 0);
    $bezahlt = (float)($r['paid'] ?? 0);
    if ($betrag > 0 && $bezahlt >= $betrag) {
        return 'paid';
    }
    return $bezahlt > 0 ? 'partly_paid' : 'unpaid';
}
