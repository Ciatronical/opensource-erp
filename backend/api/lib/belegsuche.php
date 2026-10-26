<?php
// backend/api/lib/belegsuche.php
//
// Gemeinsame SQL-Bausteine fuer die Belegsuche (Rechnungen, Gutschriften, Angebote,
// Auftraege, Bestellungen, Lieferscheine). Wird von der Suche (customer_vendor/search.php)
// und vom Sammeldruck (print/print.php) benutzt, damit "alle Treffer drucken"
// exakt dieselbe Treffermenge liefert wie die Ergebnistabelle.

/**
 * Konfiguration je Belegart (Tab der Suche).
 *
 * print_type_expr: SQL-Ausdruck, der je Zeile die Belegart fuer den Druck
 * liefert (PRINT_TEMPLATE_MAP in print/print.php). Gutschriften stehen in ar
 * mit type = 'credit_note' (ar_type grenzt sie von den Rechnungen ab),
 * Lieferscheine tragen den Einkauf im record_type.
 *
 * status_expr: offener Betrag — ABS, weil Gutschriften negativ gebucht sind.
 * Tabellen mit status_expr (ar, ap) kennen kivitendos Storno: Stornobeleg mit
 * storno_id, stornierte Rechnung nur storno = true; beide gelten als erledigt.
 *
 * @return array
 */
function belegsucheKonfig(): array {
    return [
        'invoice' => [
            'table' => 'ar',
            'number_field' => 'invnumber',
            'cv_table' => 'customer',
            'cv_fk' => 'customer_id',
            'cv_number_field' => 'customernumber',
            'status_expr' => 'ABS(ar.amount - ar.paid)',
            'ar_type' => "COALESCE(ar.type, '') <> 'credit_note'",
            'cv_src' => 'C',
            'print_type_expr' => "'invoice'",
        ],
        'credit_note' => [
            'table' => 'ar',
            'number_field' => 'invnumber',
            'cv_table' => 'customer',
            'cv_fk' => 'customer_id',
            'cv_number_field' => 'customernumber',
            'status_expr' => 'ABS(ar.amount - ar.paid)',
            'ar_type' => "ar.type = 'credit_note'",
            // Gutschriften sind negativ gebucht, gesucht wird nach dem Betrag ohne Vorzeichen
            'amount_expr' => 'ABS(ar.amount)',
            'cv_src' => 'C',
            'print_type_expr' => "'credit_note'",
        ],
        'purchase_invoice' => [
            'table' => 'ap',
            'number_field' => 'invnumber',
            'cv_table' => 'vendor',
            'cv_fk' => 'vendor_id',
            'cv_number_field' => 'vendornumber',
            'status_expr' => 'ABS(ap.amount - ap.paid)',
            'cv_src' => 'V',
            'print_type_expr' => "NULL",
        ],
        'quotation' => [
            'table' => 'oe',
            'number_field' => 'quonumber',
            'cv_table' => 'customer',
            'cv_fk' => 'customer_id',
            'cv_number_field' => 'customernumber',
            'record_types' => "'sales_quotation'",
            'status_field' => 'closed',
            'cv_src' => 'C',
            'print_type_expr' => "'quotation'",
        ],
        'order' => [
            'table' => 'oe',
            'number_field' => 'ordnumber',
            'cv_table' => 'customer',
            'cv_fk' => 'customer_id',
            'cv_number_field' => 'customernumber',
            'record_types' => "'sales_order', 'sales_order_intake'",
            'status_field' => 'closed',
            'cv_src' => 'C',
            'print_type_expr' => "'order'",
        ],
        'purchase_order' => [
            'table' => 'oe',
            'number_field' => 'ordnumber',
            'cv_table' => 'vendor',
            'cv_fk' => 'vendor_id',
            'cv_number_field' => 'vendornumber',
            'record_types' => "'purchase_order', 'purchase_order_confirmation'",
            'status_field' => 'closed',
            'cv_src' => 'V',
            'print_type_expr' => "'purchase_order'",
        ],
        'delivery_order' => [
            'table' => 'delivery_orders',
            'number_field' => 'donumber',
            'cv_table' => 'customer',
            'cv_fk' => 'customer_id',
            'cv_number_field' => 'customernumber',
            'record_types' => "'sales_delivery_order', 'purchase_delivery_order'",
            'status_field' => 'closed',
            'cv_src' => 'C',
            'print_type_expr' => "CASE WHEN delivery_orders.record_type = 'purchase_delivery_order' THEN 'purchase_delivery_order' ELSE 'delivery_order' END",
        ],
    ];
}

/**
 * Baut FROM/WHERE-Teil und Parameter der Belegsuche.
 *
 * @param string $type  Belegart (Schluessel aus belegsucheKonfig)
 * @param array  $where Filter: document_number, cv_name, transdate_from/to,
 *                      amount_from/to, status ('open'|'closed'|'storno' — storno nur ar/ap)
 * @return array ['cfg' => array, 'tbl' => string, 'from' => string (FROM + JOIN), 'where' => string (WHERE ...), 'params' => array]
 * @throws ApiError bei unbekannter Belegart
 */
function belegsucheBedingungen(string $type, array $where): array {
    $konfig = belegsucheKonfig();
    if (!isset($konfig[$type])) {
        throw new ApiError('API_INVALID_TYPE_FILTER', 'Invalid document type specified');
    }

    $cfg = $konfig[$type];
    $tbl = $cfg['table'];

    $conditions = ["1=1"];
    $params = [];
    $paramIndex = 0;

    // Dokumentnummer
    if (!empty($where['document_number'])) {
        $paramIndex++;
        $paramName = ":p$paramIndex";
        $conditions[] = "$tbl.{$cfg['number_field']} ILIKE $paramName";
        $params[$paramName] = '%' . $where['document_number'] . '%';
    }

    // Kunden-/Lieferantenname
    if (!empty($where['cv_name'])) {
        $paramIndex++;
        $paramName = ":p$paramIndex";
        $conditions[] = "cv.name ILIKE $paramName";
        $params[$paramName] = '%' . $where['cv_name'] . '%';
    }

    // Datum von/bis
    if (!empty($where['transdate_from'])) {
        $paramIndex++;
        $paramName = ":p$paramIndex";
        $conditions[] = "$tbl.transdate >= $paramName";
        $params[$paramName] = $where['transdate_from'];
    }
    if (!empty($where['transdate_to'])) {
        $paramIndex++;
        $paramName = ":p$paramIndex";
        $conditions[] = "$tbl.transdate <= $paramName";
        $params[$paramName] = $where['transdate_to'];
    }

    // Betrag von/bis (Lieferscheine haben keinen Betrag)
    if ($tbl !== 'delivery_orders') {
        $amountExpr = $cfg['amount_expr'] ?? "$tbl.amount";
        if (isset($where['amount_from']) && $where['amount_from'] !== '') {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "$amountExpr >= $paramName";
            $params[$paramName] = floatval($where['amount_from']);
        }
        if (isset($where['amount_to']) && $where['amount_to'] !== '') {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "$amountExpr <= $paramName";
            $params[$paramName] = floatval($where['amount_to']);
        }
    }

    // Status (offen/geschlossen/storniert)
    if (!empty($where['status'])) {
        if (isset($cfg['status_expr'])) {
            // AR/AP: offen = Restbetrag > 0 und nicht storniert
            if ($where['status'] === 'open') {
                $conditions[] = "({$cfg['status_expr']}) > 0.01 AND $tbl.storno IS NOT TRUE";
            } elseif ($where['status'] === 'closed') {
                $conditions[] = "(({$cfg['status_expr']}) <= 0.01 OR $tbl.storno IS TRUE)";
            } elseif ($where['status'] === 'storno') {
                $conditions[] = "$tbl.storno IS TRUE";
            }
        } elseif (isset($cfg['status_field'])) {
            // OE/delivery_orders: closed = true/false
            if ($where['status'] === 'open') {
                $conditions[] = "$tbl.{$cfg['status_field']} IS NOT TRUE";
            } elseif ($where['status'] === 'closed') {
                $conditions[] = "$tbl.{$cfg['status_field']} IS TRUE";
            }
        }
    }

    // Rechnung oder Gutschrift (beide in ar)
    if (isset($cfg['ar_type'])) {
        $conditions[] = $cfg['ar_type'];
    }

    // Record-Type-Filter fuer oe und delivery_orders
    if (isset($cfg['record_types'])) {
        $conditions[] = "$tbl.record_type IN ({$cfg['record_types']})";
    }

    $from = "FROM $tbl LEFT JOIN {$cfg['cv_table']} AS cv ON cv.id = $tbl.{$cfg['cv_fk']}";
    $whereSql = 'WHERE ' . implode(' AND ', $conditions);

    return ['cfg' => $cfg, 'tbl' => $tbl, 'from' => $from, 'where' => $whereSql, 'params' => $params];
}
