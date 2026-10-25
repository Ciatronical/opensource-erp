<?php
// backend/api/faktura/history.php
//
// Belegchronik: wer hat wann was an einem Faktura-Beleg geändert.
//
// Quelle sind die Diffs aus record_history_oserp (Trigger record_history_log,
// Upstall crm/lxcars), dazu als Vorgeschichte die kivitendo-Ereignisse aus
// history_erp, die Belegkette aus record_links und die Wiedervorlagen. Der
// Versand (E-Mail, WhatsApp, Post, DHL) liegt schon mit getFakturaData im
// Store (sent_log) und wird im Frontend eingemischt.

/**
 * Chronik eines Belegs in einer Abfrage
 *
 * Liefert neueste zuerst:
 *  - results:  Diff-Zeilen mit Mitarbeiter und Quelle (api, cron:…, leer = außerhalb OSERP)
 *  - legacy:   kivitendo-history_erp (gespeichert, gedruckt, gebucht, gemailt …)
 *  - links:    Belege, die aus diesem entstanden sind bzw. aus denen er entstand
 *  - followups: Wiedervorlagen am Beleg (angelegt, erledigt)
 *  - lookups:  Klartext zu allen IDs, die in den Diffs vorkommen — auch gelöschte
 *              Mitarbeiter, Kunden, Artikel, die im Store nicht stehen
 *
 * @param int    $data['fakturaID']   Beleg-ID
 * @param string $data['fakturaType'] Belegart (Hinweis auf die ID-Familie, siehe resolveFakturaType)
 * @param int    $data['limit']       Höchstens so viele Diff-Zeilen (Standard 2000)
 * @testdata {"action": "getFakturaHistory", "fakturaID": 1, "fakturaType": "order"}
 */
function getFakturaHistory($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $company = DbhCompany::begin();

    $resolved = resolveFakturaType($company, $fakturaID, $data['fakturaType'] ?? 'invoice');
    if (!$resolved) {
        resultInfo(false, 'FAKTURA_NOT_FOUND', "Dokument {$fakturaID} nicht gefunden");
        return;
    }
    permit(getPermissionForFakturaType($resolved['type']));
    $table = $resolved['table'];   // oe | ar | ap | delivery_orders (kontrolliert, kein User-Input)
    $limit = max(1, min(5000, intval($data['limit'] ?? 2000)));

    // kivitendo-Vorgeschichte: history_erp hängt an trans_id + Belegnummer (snumbers);
    // trans_id allein kollidiert zwischen den ID-Familien (oe ∩ gl, oe ∩ ar)
    $legacyNumbers = [
        'oe'              => "ARRAY['ordnumber_' || d.ordnumber, 'quonumber_' || d.quonumber]",
        'ar'              => "ARRAY['invnumber_' || d.invnumber]",
        'ap'              => "ARRAY['invnumber_' || d.invnumber]",
        'delivery_orders' => "ARRAY['donumber_' || d.donumber]",
    ][$table];
    $legacyKinds = [
        'oe'              => "'sales_order', 'sales_quotation', 'sales_order_intake', 'purchase_order', 'request_quotation', 'purchase_quotation_intake', 'purchase_order_confirmation'",
        'ar'              => "'invoice', 'credit_note'",
        'ap'              => "'invoice', 'purchase_invoice', 'vendor_invoice'",
        'delivery_orders' => "'sales_delivery_order', 'purchase_delivery_order', 'supplier_delivery_order', 'rma_delivery_order'",
    ][$table];
    // Wiedervorlagen: dieselbe Typfamilie wie in getFakturaData
    $followUpTypes = [
        'oe'              => ['sales_quotation', 'sales_order', 'sales_order_intake', 'request_quotation', 'purchase_order', 'purchase_quotation_intake', 'purchase_order_confirmation'],
        'ar'              => ['sales_invoice', 'credit_note', 'ar_transaction'],
        'ap'              => ['purchase_invoice', 'vendor_invoice', 'ap_transaction'],
        'delivery_orders' => ['sales_delivery_order', 'purchase_delivery_order', 'supplier_delivery_order', 'rma_delivery_order'],
    ][$table];

    // Fahrzeuge gibt es nur mit der LxCars-Erweiterung
    $carLookup = existingTables($company, ['cars_lxcars'])
        ? "(SELECT json_object_agg(c.c_id, c.c_ln) FROM cars_lxcars c WHERE c.c_id IN (SELECT val::int FROM ids WHERE key = 'c_id'))"
        : "NULL::json";

    $sql = <<<SQL
        WITH h AS (
            SELECT h.*
              FROM record_history_oserp h
             WHERE h.record_table = :record_table AND h.record_id = :id
             ORDER BY h.itime DESC, h.id DESC
             LIMIT :limit
        ),
        -- alle IDs, die in den Diffs vorkommen (alt und neu), für den Klartext
        ids AS (
            SELECT DISTINCT c.key, v.val
              FROM h
              CROSS JOIN LATERAL jsonb_each(COALESCE(h.changes, '{}'::jsonb)) c
              CROSS JOIN LATERAL (VALUES (c.value->>'old'), (c.value->>'new')) AS v(val)
             WHERE v.val ~ '^[0-9]{1,9}$'   -- passt in integer; der Cast in den Lookups nutzt den Index
        ),
        -- Belegkette: Zielbelege dieses Belegs und der Beleg, aus dem er entstand
        rl AS (
            SELECT l.itime, 'to' AS direction, l.to_table AS tbl, l.to_id AS rid
              FROM record_links l
             WHERE l.from_table = :record_table AND l.from_id = :id
               AND l.to_table IN ('oe', 'ar', 'ap', 'delivery_orders')
            UNION
            SELECT l.itime, 'from', l.from_table, l.from_id
              FROM record_links l
             WHERE l.to_table = :record_table AND l.to_id = :id
               AND l.from_table IN ('oe', 'ar', 'ap', 'delivery_orders')
        )
        SELECT json_build_object(
            'results', COALESCE((
                SELECT json_agg(json_build_object(
                           'id', h.id, 'itime', h.itime, 'table_name', h.table_name,
                           'row_id', h.row_id, 'row_label', h.row_label, 'action', h.action,
                           'changes', h.changes, 'employee_id', h.employee_id,
                           'employee_name', e.name, 'source', h.source)
                       ORDER BY h.itime DESC, h.id DESC)
                  FROM h LEFT JOIN employee e ON e.id = h.employee_id
            ), '[]'::json),
            'legacy', COALESCE((
                SELECT json_agg(json_build_object(
                           'id', k.id, 'itime', k.itime, 'addition', k.addition,
                           'what_done', k.what_done, 'employee_name', e.name)
                       ORDER BY k.itime DESC, k.id DESC)
                  FROM {$table} d
                  JOIN history_erp k ON k.trans_id = d.id
                  LEFT JOIN employee e ON e.id = k.employee_id
                 WHERE d.id = :id
                   AND k.what_done IN ({$legacyKinds})
                   AND (k.snumbers = ANY({$legacyNumbers}) OR k.snumbers LIKE 'emailjournal\\_%')
            ), '[]'::json),
            'links', COALESCE((
                SELECT json_agg(x ORDER BY x.itime DESC)
                  FROM (
                      SELECT rl.itime, rl.direction, rl.tbl AS record_table, rl.rid AS record_id,
                             COALESCE(o.number, a.invnumber, p.invnumber, dl.donumber) AS number,
                             COALESCE(o.kind, a.kind, CASE WHEN p.id IS NOT NULL THEN 'purchase_invoice' END, dl.kind) AS kind,
                             COALESCE(o.employee_name, a.employee_name, p.employee_name, dl.employee_name) AS employee_name
                        FROM rl
                        LEFT JOIN LATERAL (
                            -- Auftrag/Angebot: neues Schema record_type, altes quotation (schema_fallback)
                            SELECT COALESCE(CASE WHEN oe_art.rt LIKE '%quotation%' THEN NULLIF(oe_art.quonumber, '') END,
                                            NULLIF(oe_art.ordnumber, ''), oe_art.quonumber) AS number,
                                   CASE WHEN oe_art.rt = 'sales_quotation' THEN 'quotation'
                                        WHEN oe_art.rt IN ('request_quotation', 'purchase_quotation_intake') THEN 'request_quotation'
                                        WHEN oe_art.rt IN ('purchase_order', 'purchase_order_confirmation') THEN 'purchase_order'
                                        ELSE 'order' END AS kind,
                                   oe_art.employee_name
                              FROM (SELECT NULL::text AS record_type, NULL::boolean AS quotation) AS schema_fallback
                              CROSS JOIN LATERAL (
                                  SELECT oo.ordnumber, oo.quonumber, em.name AS employee_name,
                                         COALESCE(record_type::text,
                                             CASE WHEN oo.vendor_id IS NOT NULL
                                                  THEN CASE WHEN quotation THEN 'request_quotation' ELSE 'purchase_order' END
                                                  ELSE CASE WHEN quotation THEN 'sales_quotation' ELSE 'sales_order' END END) AS rt
                                    FROM oe oo LEFT JOIN employee em ON em.id = oo.employee_id
                                   WHERE oo.id = rl.rid AND rl.tbl = 'oe'
                              ) AS oe_art
                        ) o ON true
                        LEFT JOIN LATERAL (
                            SELECT aa.invnumber, CASE WHEN aa.type = 'credit_note' THEN 'credit_note' ELSE 'invoice' END AS kind,
                                   em.name AS employee_name
                              FROM ar aa LEFT JOIN employee em ON em.id = aa.employee_id
                             WHERE aa.id = rl.rid AND rl.tbl = 'ar'
                        ) a ON true
                        LEFT JOIN LATERAL (
                            SELECT pp.id, pp.invnumber, em.name AS employee_name
                              FROM ap pp LEFT JOIN employee em ON em.id = pp.employee_id
                             WHERE pp.id = rl.rid AND rl.tbl = 'ap'
                        ) p ON true
                        LEFT JOIN LATERAL (
                            SELECT dd.donumber, CASE WHEN dd.vendor_id IS NOT NULL THEN 'purchase_delivery_order' ELSE 'delivery_order' END AS kind,
                                   em.name AS employee_name
                              FROM delivery_orders dd LEFT JOIN employee em ON em.id = dd.employee_id
                             WHERE dd.id = rl.rid AND rl.tbl = 'delivery_orders'
                        ) dl ON true
                  ) x
            ), '[]'::json),
            'followups', COALESCE((
                SELECT json_agg(json_build_object(
                           'id', fu.id, 'itime', fu.itime, 'follow_up_date', fu.follow_up_date,
                           'subject', n.subject, 'employee_name', ec.name,
                           'done_at', fd.done_at, 'done_by_name', ed.name)
                       ORDER BY fu.itime DESC)
                  FROM follow_ups fu
                  LEFT JOIN notes n ON n.id = fu.note_id
                  LEFT JOIN employee ec ON ec.id = fu.created_by
                  LEFT JOIN follow_up_done fd ON fd.follow_up_id = fu.id
                  LEFT JOIN employee ed ON ed.id = fd.employee_id
                 WHERE EXISTS (SELECT 1 FROM follow_up_links ful
                                WHERE ful.follow_up_id = fu.id AND ful.trans_id = :id
                                  AND ful.trans_type = ANY(CAST(:followUpTypes AS text[])))
            ), '[]'::json),
            'lookups', json_build_object(
                'employee', (SELECT json_object_agg(x.id, x.name) FROM employee x
                              WHERE x.id IN (SELECT val::int FROM ids WHERE key IN ('employee_id', 'salesman_id', 'orderer_id'))),
                'customer', (SELECT json_object_agg(x.id, x.name) FROM customer x
                              WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'customer_id')),
                'vendor', (SELECT json_object_agg(x.id, x.name) FROM vendor x
                            WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'vendor_id')),
                'shipto', (SELECT json_object_agg(x.shipto_id, concat_ws(', ', NULLIF(x.shiptoname, ''), NULLIF(x.shiptocity, ''))) FROM shipto x
                            WHERE x.shipto_id IN (SELECT val::int FROM ids WHERE key = 'shipto_id')),
                'billing_address', (SELECT json_object_agg(x.id, concat_ws(', ', NULLIF(x.name, ''), NULLIF(x.city, ''))) FROM additional_billing_addresses x
                            WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'billing_address_id')),
                'contact', (SELECT json_object_agg(x.cp_id, trim(concat_ws(' ', x.cp_givenname, x.cp_name))) FROM contacts x
                             WHERE x.cp_id IN (SELECT val::int FROM ids WHERE key = 'cp_id')),
                'part', (SELECT json_object_agg(x.id, concat_ws(' ', x.partnumber, x.description)) FROM parts x
                          WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'parts_id')),
                'project', (SELECT json_object_agg(x.id, concat_ws(' ', x.projectnumber, x.description)) FROM project x
                             WHERE x.id IN (SELECT val::int FROM ids WHERE key IN ('project_id', 'globalproject_id'))),
                'payment', (SELECT json_object_agg(x.id, x.description) FROM payment_terms x
                             WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'payment_id')),
                'delivery_term', (SELECT json_object_agg(x.id, x.description) FROM delivery_terms x
                                   WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'delivery_term_id')),
                'taxzone', (SELECT json_object_agg(x.id, x.description) FROM tax_zones x
                             WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'taxzone_id')),
                'currency', (SELECT json_object_agg(x.id, x.name) FROM currencies x
                              WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'currency_id')),
                'language', (SELECT json_object_agg(x.id, x.description) FROM language x
                              WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'language_id')),
                'department', (SELECT json_object_agg(x.id, x.description) FROM department x
                                WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'department_id')),
                'dunning', (SELECT json_object_agg(x.id, x.dunning_description) FROM dunning_config x
                             WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'dunning_config_id')),
                'price_factor', (SELECT json_object_agg(x.id, x.description) FROM price_factors x
                                  WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'price_factor_id')),
                'pricegroup', (SELECT json_object_agg(x.id, x.pricegroup) FROM pricegroup x
                                WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'pricegroup_id')),
                'storno', (SELECT json_object_agg(x.id, x.invnumber) FROM ar x
                            WHERE x.id IN (SELECT val::int FROM ids WHERE key = 'storno_id')),
                'car', {$carLookup}
            )
        ) AS result
SQL;

    $row = $company->getOne($sql, [
        'record_table'  => $table,
        'id'            => $fakturaID,
        'limit'         => $limit,
        'followUpTypes' => '{' . implode(',', $followUpTypes) . '}',
    ]);
    $result = json_decode($row['result'] ?? '{}', true) ?: [];
    $result['record_table'] = $table;
    $result['fakturaType'] = $resolved['type'];
    resultInfo(true, '', $result);
}
