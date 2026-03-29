<?php
// backend/api/faktura/order_search.php

/**
 * Sucht nach Auftraegen mit optionalen Filtern
 *
 * @param array $data['where'] Filter-Objekt mit optionalen Feldern:
 *   - ordnumber: ILIKE auf oe.ordnumber
 *   - customer_name: ILIKE auf customer.name
 *   - transaction_description: ILIKE auf erste Instruction (oe_instructions_lxcars)
 *   - status: exakter Match auf oe_ext.status
 *   - kfz_ort: exakter Match auf oe_ext.kfz_ort
 *   - transdate_from / transdate_to: Datumsbereich
 *   - bringetermin_from / bringetermin_to: Bringetermin-Bereich (oe_ext)
 *   - amount_from / amount_to: Betragsbereich
 *   - employee_id: Mitarbeiter-Filter
 * @testdata {"where": {"ordnumber": "1"}}
 */
function searchOrders($data) {
    permit('sales_order_edit');

    $mandant = DbhCompany::begin();

    $where = $data['where'] ?? [];
    $conditions = ["1=1"];
    $params = [];
    $paramIndex = 0;

    if (!empty($where) && is_array($where)) {
        // Auftragsnummer
        if (!empty($where['ordnumber'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe.ordnumber ILIKE $paramName";
            $params[$paramName] = '%' . $where['ordnumber'] . '%';
        }

        // Kundenname
        if (!empty($where['customer_name'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "customer.name ILIKE $paramName";
            $params[$paramName] = '%' . $where['customer_name'] . '%';
        }

        // Beschreibung (sucht in erster Instruction)
        if (!empty($where['transaction_description'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "EXISTS (SELECT 1 FROM oe_instructions_lxcars instr WHERE instr.oe_id = oe.id AND instr.description ILIKE $paramName)";
            $params[$paramName] = '%' . $where['transaction_description'] . '%';
        }

        // Status aus oe_ext (exakter Match)
        if (!empty($where['status'])) {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "oe_ext.status = $paramName";
            $params[$paramName] = $where['status'];
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

    // Config-Filter: Status ausblenden (Subquery statt Extra-Query)
    $conditions[] = "(oe_ext.status IS NULL OR oe_ext.status != COALESCE((SELECT value FROM defaults_oserp WHERE key = 'lxcars_order_hide_status'), ''))";

    // Config-Filter: Bringetermin-Zukunftsfenster (Subquery statt Extra-Query)
    $conditions[] = "(oe_ext.bringetermin IS NULL OR oe_ext.bringetermin <= CURRENT_DATE + COALESCE((SELECT value::integer FROM defaults_oserp WHERE key = 'lxcars_order_future_days'), 7) * INTERVAL '1 day')";

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
            employee.name AS employee_name,
            oe_ext.status AS oe_ext_status,
            oe_ext.kfz_ort,
            oe_ext.bringetermin,
            kba.hersteller,
            (SELECT description FROM oe_instructions_lxcars
             WHERE oe_id = oe.id ORDER BY sort_order, id LIMIT 1) AS first_instruction
        FROM oe
        LEFT JOIN customer ON customer.id = oe.customer_id
        LEFT JOIN employee ON employee.id = oe.employee_id
        LEFT JOIN oe_ext ON oe_ext.oe_id = oe.id
        LEFT JOIN cars_lxcars car ON car.c_id = oe_ext.c_id
        LEFT JOIN kba_lxcars kba ON kba.id = car.kba_id
        WHERE oe.record_type IN ('sales_order', 'purchase_order')
        AND $search
        ORDER BY
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
