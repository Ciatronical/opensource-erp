<?php
// backend/api/faktura/order_search.php

/**
 * Sucht nach Auftraegen mit optionalen Filtern
 *
 * @param array $data['where'] Filter-Objekt mit optionalen Feldern:
 *   - q: intelligente Volltextsuche (Tokens per Leerzeichen getrennt, je Token
 *        AND-verknüpft, je Token OR über Auftragsnr., Kundenauftragsnr., Kunde,
 *        Beschreibung, Anweisungen, Kennzeichen, FIN, HSN/TSN, Hersteller/Marke)
 *   - status: '__not_hidden__' (Default) blendet Hide-Status aus, '__all__' zeigt alle,
 *             sonst exakter Match auf oe_ext.status
 *   - kfz_ort: exakter Match auf oe_ext.kfz_ort
 *   - transdate_from / transdate_to: Datumsbereich
 *   - bringetermin_from / bringetermin_to: Bringetermin-Bereich (oe_ext)
 *   - amount_from / amount_to: Betragsbereich
 *   - employee_id: Mitarbeiter-Filter
 * @testdata {"where": {"q": "0603 469"}}
 */
function searchOrders($data) {
    permit('sales_order_edit');

    $mandant = DbhCompany::begin();

    $where = $data['where'] ?? [];
    $conditions = ["1=1"];
    $params = [];
    $paramIndex = 0;

    if (!empty($where) && is_array($where)) {
        // Intelligente Volltextsuche: ein Feld findet alles.
        // Eingabe wird per Leerzeichen in Tokens zerlegt. Jedes Token muss in
        // mindestens einer Spalte vorkommen (OR), alle Tokens zusammen per AND.
        // So trennt z. B. "Müller Golf" oder HSN/TSN "0603 469" sauber.
        if (!empty($where['q'])) {
            $tokens = preg_split('/\s+/', trim($where['q']), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($tokens as $token) {
                $paramIndex++;
                $paramName = ":p$paramIndex";
                $params[$paramName] = $token;
                $conditions[] = <<<SQL
                    (
                        oe.ordnumber ILIKE '%' || $paramName || '%'
                        OR oe.cusordnumber ILIKE '%' || $paramName || '%'
                        OR customer.name ILIKE '%' || $paramName || '%'
                        OR oe.transaction_description ILIKE '%' || $paramName || '%'
                        OR car.c_fin ILIKE '%' || $paramName || '%'
                        OR kba.hsn ILIKE '%' || $paramName || '%'
                        OR kba.tsn ILIKE '%' || $paramName || '%'
                        OR kba.hersteller ILIKE '%' || $paramName || '%'
                        OR kba.marke ILIKE '%' || $paramName || '%'
                        OR replace(COALESCE(NULLIF(car.c_ln, ''), oe_ext.kennzeichen, ''), '-', '')
                           ILIKE '%' || replace($paramName, '-', '') || '%'
                        OR EXISTS (
                            SELECT 1 FROM oe_instructions_lxcars instr
                            WHERE instr.oe_id = oe.id AND instr.description ILIKE '%' || $paramName || '%'
                        )
                    )
                SQL;
            }
        }

        // Lokation / kfz_ort aus oe_ext (exakter Match)
        if (!empty($where['kfz_ort'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe_ext.kfz_ort = $paramName";
            $params[$paramName] = $where['kfz_ort'];
        }

        // Datum von/bis
        if (!empty($where['transdate_from'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe.transdate >= $paramName";
            $params[$paramName] = $where['transdate_from'];
        }
        if (!empty($where['transdate_to'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe.transdate <= $paramName";
            $params[$paramName] = $where['transdate_to'];
        }

        // Bringetermin von/bis
        if (!empty($where['bringetermin_from'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe_ext.bringetermin >= $paramName";
            $params[$paramName] = $where['bringetermin_from'];
        }
        if (!empty($where['bringetermin_to'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe_ext.bringetermin <= $paramName";
            $params[$paramName] = $where['bringetermin_to'];
        }

        // Betragsbereich
        if (isset($where['amount_from']) && $where['amount_from'] !== '') {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe.amount >= $paramName";
            $params[$paramName] = floatval($where['amount_from']);
        }
        if (isset($where['amount_to']) && $where['amount_to'] !== '') {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe.amount <= $paramName";
            $params[$paramName] = floatval($where['amount_to']);
        }

        // Mitarbeiter-Filter
        if (!empty($where['employee_id'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe.employee_id = $paramName";
            $params[$paramName] = intval($where['employee_id']);
        }
    }

    // Status-Filter aus oe_ext.
    //   Sentinel '__not_hidden__' (Default) -> Hide-Status ausblenden ("Nicht abgerechnet").
    //   Sentinel '__all__'                  -> keine Status-Einschränkung.
    //   konkreter Status                    -> exakter Match; so wird auch der sonst
    //                                          versteckte Hide-Status (z. B. Abgerechnet) gefunden.
    $status = $where['status'] ?? '__not_hidden__';
    if ($status === '__all__') {
        // keine Status-Einschränkung
    } elseif ($status !== '' && $status !== '__not_hidden__') {
        $paramIndex++;
        $paramName = ":p$paramIndex";
        $conditions[] = "oe_ext.status = $paramName";
        $params[$paramName] = $status;
    } else {
        $conditions[] = "(oe_ext.status IS NULL OR oe_ext.status != COALESCE((SELECT value FROM defaults_oserp WHERE key = 'lxcars_order_hide_status'), ''))";
    }

    // Config-Filter: Bringetermin-Zukunftsfenster nur im Standard-Dashboard anwenden.
    // Bei aktiver Volltextsuche (q) oder explizitem Bringetermin-Bereich aufheben,
    // damit die Suche wirklich alles findet.
    $hasBringeRange = !empty($where['bringetermin_from']) || !empty($where['bringetermin_to']);
    if (empty($where['q']) && !$hasBringeRange) {
        $conditions[] = "(oe_ext.bringetermin IS NULL OR oe_ext.bringetermin <= CURRENT_DATE + COALESCE((SELECT value::integer FROM defaults_oserp WHERE key = 'lxcars_order_future_days'), 7) * INTERVAL '1 day')";
    }

    $search = implode(' AND ', $conditions);

    $query = <<<SQL
        SELECT
            oe.id,
            oe.ordnumber,
            oe.transdate,
            oe.reqdate,
            oe.amount,
            oe.netamount,
            oe.record_type,
            oe.closed,
            oe.transaction_description,
            oe.cusordnumber,
            customer.name AS customer_name,
            customer.street AS customer_street,
            customer.zipcode AS customer_zipcode,
            customer.city AS customer_city,
            customer.country AS customer_country,
            customer.phone AS customer_phone,
            employee.name AS employee_name,
            oe_ext.status AS oe_ext_status,
            oe_ext.kfz_ort,
            oe_ext.bringetermin,
            oe_ext.intern,
            COALESCE(NULLIF(car.c_ln, ''), oe_ext.kennzeichen) AS kennzeichen,
            kba.hersteller,
            (SELECT description FROM oe_instructions_lxcars
             WHERE oe_id = oe.id ORDER BY sort_order, id LIMIT 1) AS first_instruction,
            (SELECT emp.name FROM oe_instructions_lxcars instr
             LEFT JOIN employee emp ON emp.id = instr.employee_id
             WHERE instr.oe_id = oe.id AND instr.employee_id IS NOT NULL
             ORDER BY instr.id DESC LIMIT 1) AS mechanic_name
        FROM oe
        LEFT JOIN customer ON customer.id = oe.customer_id
        LEFT JOIN employee ON employee.id = oe.employee_id
        LEFT JOIN oe_ext ON oe_ext.oe_id = oe.id
        LEFT JOIN cars_lxcars car ON car.c_id = oe_ext.c_id
        LEFT JOIN kba_lxcars kba ON kba.id = car.kba_id
        WHERE oe.record_type IN ('sales_order', 'sales_order_intake', 'purchase_order')
        AND $search
        ORDER BY
            CASE WHEN oe_ext.intern THEN 1 ELSE 0 END,
            CASE WHEN oe_ext.bringetermin > CURRENT_DATE THEN 0 ELSE 1 END,
            COALESCE(oe_ext.bringetermin, oe.itime::date) DESC
        LIMIT 200
    SQL;

    try {
        $results = $mandant->getAll($query, $params);
        resultInfo(true, '', ['results' => $results]);
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        preg_match('/SQLSTATE\[(\w+)\]:\s*(.+)/', $errorMessage, $matches);
        $sqlState = $matches[1] ?? 'UNKNOWN';
        $sqlError = $matches[2] ?? $errorMessage;

        echo json_encode([
            'sql_error' => true,
            'sql_state' => $sqlState,
            'error_message' => $sqlError,
            'full_error' => $errorMessage
        ]);
    }
}
