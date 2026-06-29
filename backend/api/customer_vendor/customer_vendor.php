<?php
// backend/api/customer_vendor/customer_vendor.php
/**
 * Holt Kunden- oder Lieferanten-Daten mit allen zugehörigen Informationen
 *
 * @param int $data['customerId'] ID des Kunden (optional, sonst wird der neueste genommen)
 * @testdata {"customerId": 1121}
 */
function getCV($data, $withConfig = []) {
    $mandant = DbhCompany::begin();
    $cv_id = null;
    $cvSrc = $data['src'] ?? 'C';
    $cvTable = ($cvSrc === 'V') ? 'vendor' : 'customer';
    $cvSrcLiteral = ($cvSrc === 'V') ? 'V' : 'C';

    if (isset($data['customerId'])) {
        // Prüfen ob die übergebene ID in diesem Mandanten existiert
        $exists = $mandant->getOne(
            "SELECT id FROM $cvTable WHERE id = :id",
            [':id' => $data['customerId']]
        );
        if ($exists) {
            $cv_id = $data['customerId'];
        }
    }

    // Fallback: View-History des Mitarbeiters → zuletzt angelegter Kunde/Lieferant
    if (!$cv_id) {
        $cv_id = _getCvIdFromViewHistory($mandant, $cvSrc);
    }
    if (!$cv_id) {
        $row = $mandant->getOne("SELECT id FROM $cvTable WHERE itime = (SELECT MAX(itime) FROM $cvTable)");
        $cv_id = $row['id'] ?? null;
    }
    // Fallback: in der anderen Tabelle suchen (Lieferant ↔ Kunde)
    if (!$cv_id) {
        $altTable = ($cvTable === 'customer') ? 'vendor' : 'customer';
        $row = $mandant->getOne("SELECT id FROM $altTable WHERE itime = (SELECT MAX(itime) FROM $altTable)");
        if ($row) {
            $cv_id = $row['id'];
            $cvSrc = ($altTable === 'vendor') ? 'V' : 'C';
            $cvTable = $altTable;
            $cvSrcLiteral = $cvSrc;
        }
    }
    if (!$cv_id) {
        if (!empty($withConfig)) {
            // Login-Kontext: company_config trotzdem laden (Steuerzonen, Währungen etc.)
            $auth = DbhAuth::begin();
            $auth->fetchSessionData();
            $configQuery = <<<SQL
                SELECT json_build_object(
                    'logged_in_employee', (
                        SELECT row_to_json(emp)
                        FROM (
                            SELECT id, name, login
                            FROM employee WHERE login = '{$auth->getLogin()}'
                        ) AS emp
                    ),
                    'company_config', (
                        SELECT json_build_object(
                            'features', (
                                SELECT json_agg(feature) FROM (
                                    SELECT value FROM defaults_oserp WHERE key = 'features'
                                ) AS feature
                            ),
                            'defaults', (
                                SELECT row_to_json(config) FROM (SELECT * FROM defaults) AS config
                            ),
                            'defaults_oserp', (
                                SELECT json_object_agg(key, value) FROM defaults_oserp
                            ),
                            'currencies', (
                                SELECT json_agg(currency) FROM (SELECT * FROM currencies) AS currency
                            ),
                            'tax_zones', (
                                SELECT json_agg(tz) FROM (
                                    SELECT id, description, obsolete, sortkey
                                    FROM tax_zones WHERE obsolete = false ORDER BY sortkey
                                ) AS tz
                            ),
                            'payment_terms', (
                                SELECT json_agg(pt) FROM (
                                    SELECT * FROM payment_terms ORDER BY sortkey
                                ) AS pt
                            ),
                            'languages', (
                                SELECT json_agg(lang) FROM (
                                    SELECT * FROM language WHERE obsolete = false ORDER BY id ASC
                                ) AS lang
                            ),
                            'employees', (
                                SELECT json_agg(emp) FROM (
                                    SELECT id, name, login
                                    FROM employee WHERE deleted = false ORDER BY name ASC
                                ) AS emp
                            ),
                            'business_types', (
                                SELECT json_agg(b) FROM (SELECT * FROM business) AS b
                            ),
                            'delivery_terms', (
                                SELECT json_agg(dt) FROM (SELECT * FROM delivery_terms) AS dt
                            )
                        )
                    ),
                    'customer_vendor', null
                ) AS main
            SQL;
            echo $mandant->get($configQuery, $withConfig);
        } else {
            resultInfo(false, 'NO_CV', 'Kein Kunde/Lieferant vorhanden');
        }
        return;
    }

    $features = $mandant->fetchAll("SELECT value FROM defaults_oserp WHERE key = 'features'");
    $feature = $features[0]['value'] ?? null;
    $lxCars = str_contains($feature, 'lxcars');

    // Vendor vs. Customer: unterschiedliche Tabellen/FK/record_types
    $isVendor = ($cvSrc === 'V');
    $cvForeignKey      = $isVendor ? 'vendor_id'          : 'customer_id';
    $quotationType     = $isVendor ? 'request_quotation'   : 'sales_quotation';
    $orderCondition    = $isVendor ? "record_type = 'purchase_order'" : "record_type = 'sales_order' OR record_type = 'sales_order_intake'";
    $invoiceTable      = $isVendor ? 'ap'                  : 'ar';
    $invoiceForeignKey = $isVendor ? 'vendor_id'           : 'customer_id';
    $extTable = $isVendor ? 'vendor_ext' : 'customer_ext';
    $extFk    = $isVendor ? 'vendor_id'  : 'customer_id';
    $phoneNumbersSelect = "(SELECT phone_numbers FROM $extTable WHERE $extFk = $cv_id)";
    $keywordsSelect     = "(SELECT keywords FROM $extTable WHERE $extFk = $cv_id)";

    $orderFirstDescription = $lxCars
        ? "SELECT il.description FROM oe_instructions_lxcars il WHERE il.oe_id = oe.id ORDER BY il.sort_order, il.id LIMIT 1"
        : "SELECT oi.description FROM orderitems oi WHERE oi.trans_id = oe.id ORDER BY oi.position LIMIT 1";

    $deliveryOrderType  = $isVendor ? 'purchase_delivery_order' : 'sales_delivery_order';
    $reclamationType    = $isVendor ? 'purchase_reclamation'    : 'sales_reclamation';

    // LxCars: Kennzeichen des Fahrzeugs ueber record_links auf den referenzierten
    // Auftrag/Rechnung aufloesen. Ohne LxCars leeren String liefern (Spalte wird
    // dann im Frontend ausgeblendet).
    $kennzeichenForReclamation = $lxCars
        ? "COALESCE(
              (SELECT oe_ext.kennzeichen FROM oe_ext
               JOIN record_links rl ON rl.from_table = 'oe' AND rl.from_id = oe_ext.oe_id
               WHERE rl.to_table = 'reclamations' AND rl.to_id = rec.id
               LIMIT 1),
              (SELECT car.c_ln FROM cars_lxcars car
               JOIN ar_ext ON ar_ext.c_id = car.c_id
               JOIN record_links rl ON rl.from_table = 'ar' AND rl.from_id = ar_ext.ar_id
               WHERE rl.to_table = 'reclamations' AND rl.to_id = rec.id
               LIMIT 1),
              ''
          )"
        : "''";
    $kennzeichenForDeliveryOrder = $lxCars
        ? "COALESCE(
              (SELECT oe_ext.kennzeichen FROM oe_ext
               JOIN record_links rl ON rl.from_table = 'oe' AND rl.from_id = oe_ext.oe_id
               WHERE rl.to_table = 'delivery_orders' AND rl.to_id = dlo.id
               LIMIT 1),
              ''
          )"
        : "''";
    // Auftrag (oe) selbst: Kennzeichen aus oe_ext, Fallback Fahrzeug-Kennzeichen (cars_lxcars.c_ln)
    $kennzeichenForOrder = $lxCars
        ? "(SELECT COALESCE(NULLIF(car.c_ln, ''), oe_ext.kennzeichen, '')
            FROM oe_ext
            LEFT JOIN cars_lxcars car ON car.c_id = oe_ext.c_id
            WHERE oe_ext.oe_id = oe.id
            LIMIT 1)"
        : "''";

    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    $query = <<<SQL
           SELECT json_build_object(
                'logged_in_employee',
                    (
                        SELECT row_to_json(emp)
                        FROM (
                            SELECT id, name, login
                            FROM employee
                            WHERE login = '{$auth->getLogin()}'
                        ) AS emp
                    ),
                'company_config',
                    (
                        SELECT json_build_object(
                            'features', (
                                SELECT json_agg(feature)
                                FROM (
                                    SELECT value
                                    FROM defaults_oserp
                                    WHERE key = 'features'
                                ) AS feature
                            ),
                            'defaults', (
                                SELECT row_to_json(config)
                                FROM (
                                    SELECT * FROM defaults
                                ) AS config
                            ),
                            'defaults_oserp', (
                                SELECT json_object_agg(key, value)
                                FROM defaults_oserp
                            ),
                            'business_types', (
                                SELECT json_agg(business)
                                FROM (
                                    SELECT * FROM business
                                ) AS business
                            ),
                            'delivery_terms', (
                                SELECT json_agg(delivery)
                                FROM (
                                    SELECT * FROM delivery_terms
                                ) AS delivery
                            ),
                            'currencies', (
                                SELECT json_agg(currency)
                                FROM (
                                    SELECT * FROM currencies
                                ) AS currency
                            ),
                            'languages', (
                                SELECT json_agg(language)
                                FROM (
                                    SELECT * FROM language WHERE obsolete = false ORDER BY id ASC
                                ) AS language
                            ),
                            'payment_terms', (
                                SELECT json_agg(payment_terms)
                                FROM (
                                    SELECT * FROM payment_terms ORDER BY sortkey
                                ) AS payment_terms
                            ),
                            'employees', (
                                SELECT json_agg(employees)
                                FROM (
                                    SELECT id, name, login
                                    FROM employee WHERE deleted = false ORDER BY name ASC
                                ) AS employees
                            ),
                            'tax', (--Steuersätze
                                SELECT json_agg(tax)
                                FROM (
                                    SELECT
                                        t.id,
                                        t.taxkey,
                                        t.taxdescription,
                                        t.rate,
                                        t.chart_id,
                                        t.chart_categories,
                                        t.skonto_sales_chart_id,
                                        t.skonto_purchase_chart_id,
                                        t.reverse_charge_chart_id,
                                        EXISTS(
                                            SELECT 1 FROM acc_trans WHERE tax_id = t.id
                                            UNION
                                            SELECT 1 FROM invoice WHERE tax_id = t.id
                                            UNION
                                            SELECT 1 FROM taxkeys WHERE tax_id = t.id
                                            LIMIT 1
                                        ) as used
                                    FROM tax t
                                    ORDER BY t.taxkey, t.rate, t.taxdescription
                                ) AS tax
                            ),
                            'tax_zones', (--Steuerzonen, Inland, EU mit USt-Id, EU ohne USt-Id, Ausland
                                SELECT json_agg(tax_zones)
                                FROM (
                                    SELECT
                                        tz.id,
                                        tz.description,
                                        tz.obsolete,
                                        tz.sortkey,
                                        EXISTS(
                                            SELECT 1 FROM customer WHERE taxzone_id = tz.id
                                            UNION ALL
                                            SELECT 1 FROM vendor WHERE taxzone_id = tz.id
                                            UNION ALL
                                            SELECT 1 FROM ar WHERE taxzone_id = tz.id
                                            UNION ALL
                                            SELECT 1 FROM ap WHERE taxzone_id = tz.id
                                            UNION ALL
                                            SELECT 1 FROM oe WHERE taxzone_id = tz.id
                                            UNION ALL
                                            SELECT 1 FROM delivery_orders WHERE taxzone_id = tz.id
                                            LIMIT 1
                                        ) as used
                                    FROM tax_zones tz
                                    WHERE tz.obsolete = false
                                    ORDER BY tz.sortkey
                                ) AS tax_zones
                            ),
                            'taxzone_charts', (--Steuerzonen-Konten
                                SELECT json_agg(taxzone_charts)
                                FROM (
                                    SELECT * FROM taxzone_charts
                                ) AS taxzone_charts
                            ),
                            'chart', (--Kontenplan
                                SELECT json_agg(chart)
                                FROM (
                                    SELECT * FROM chart ORDER BY accno
                                ) AS chart
                            ),
                            'buchungsgruppen', (--Buchungsgruppen, in used steht ob die BG in parts verwendet wird
                                SELECT json_agg(buchungsgruppen)
                                FROM (
                                    SELECT
                                        bg.*,
                                        COUNT(p.id) > 0 as used
                                    FROM buchungsgruppen bg
                                    LEFT JOIN parts p ON p.buchungsgruppen_id = bg.id
                                    WHERE bg.obsolete = false
                                    GROUP BY bg.id, bg.description, bg.inventory_accno_id, bg.sortkey, bg.obsolete
                                    ORDER BY bg.sortkey, bg.id
                                ) AS buchungsgruppen
                            ),
                            'pricegroups', (--Preisgruppen
                                SELECT json_agg(pricegroups)
                                FROM (
                                    SELECT * FROM pricegroup WHERE obsolete = false ORDER BY sortkey
                                ) AS pricegroups
                            ),
                            'department', (--Abteilungen
                                SELECT json_agg(department)
                                FROM (
                                    SELECT * FROM department
                                ) AS department
                            ),
                            'printers', (--Drucker
                                SELECT json_agg(printers)
                                FROM (
                                    SELECT * FROM printers
                                ) AS printers
                            ),
                            'generic_translations', (--Allgemeine Übersetzungen
                                SELECT json_agg(generic_translations)
                                FROM (
                                    SELECT * FROM generic_translations
                                ) AS generic_translations
                            ),
                            'payment_acc', (-- ToDo: Diese Tabelle existiert in K. nicht!!!
                                SELECT json_agg(payment_acc ORDER BY accno)
                                FROM (
                                    SELECT
                                        id,
                                        accno,
                                        description
                                    FROM chart
                                    WHERE link LIKE '%AP_paid%'
                                ) AS payment_acc
                            ),
                            'company_employee_config', (--Mitarbeiterkonfiguration
                                SELECT json_object_agg(key, value)
                                FROM employee_config_oserp
                                WHERE employee_id = (SELECT id FROM employee WHERE login = '{$auth->getLogin()}')
                            ),
                            'part_classifications', (--Teileklassifikationen
                                SELECT json_agg(part_classifications)
                                FROM (
                                    SELECT *
                                    FROM part_classifications ORDER BY id ASC
                                ) AS part_classifications
                            ),
                            'units', (--Einheiten
                                SELECT json_agg(units)
                                FROM (
                                    SELECT *
                                    FROM units ORDER BY id ASC
                                ) AS units
                            ),
                            'warehouse', (--Lagerhäuser
                                SELECT json_agg(warehouse)
                                FROM (
                                    SELECT *
                                    FROM warehouse ORDER BY id ASC
                                ) AS warehouse
                            ),
                            'bin', (--Lagerplätze
                                SELECT json_agg(bin)
                                FROM (
                                    SELECT *
                                    FROM bin ORDER BY id ASC
                                ) AS bin
                            ),
                            'bank_accounts', (--Bankkonten + Info ob verwendet
                                SELECT json_agg(bank_accounts)
                                FROM (
                                    SELECT
                                        ba.id,
                                        ba.account_number,
                                        ba.bank_code,
                                        ba.iban,
                                        ba.bic,
                                        ba.bank,
                                        ba.name,
                                        ba.chart_id,
                                        ba.reconciliation_starting_date,
                                        ba.reconciliation_starting_balance,
                                        ba.use_with_bank_import,
                                        ba.use_for_zugferd,
                                        ba.use_for_qrbill,
                                        ba.qr_iban,
                                        ba.bank_account_id,
                                        EXISTS(
                                            SELECT 1 FROM bank_transactions WHERE CAST(bank_account_id AS text) = CAST(ba.id AS text)
                                            UNION
                                            SELECT 1 FROM reconciliation_links WHERE bank_transaction_id IN (
                                                SELECT id FROM bank_transactions WHERE CAST(bank_account_id AS text) = CAST(ba.id AS text)
                                            )
                                            LIMIT 1
                                        ) as used
                                    FROM bank_accounts ba
                                    ORDER BY ba.sortkey, ba.name
                                ) AS bank_accounts
                            ),
                            'whatsapp_templates', (
                                SELECT COALESCE(json_agg(wa_tpl), '[]'::json)
                                FROM (
                                    SELECT id, display_name
                                    FROM whatsapp_templates
                                    ORDER BY template_type, display_name
                                ) AS wa_tpl
                            )
                        )
                    ),
                'customer_vendor',
                    (
                        SELECT json_build_object(
                            'profile',
                                (
                                    SELECT row_to_json(cv)
                                    FROM (
                                        SELECT '$cvSrcLiteral' AS src, t.*,
                                            $phoneNumbersSelect AS phone_numbers,
                                            $keywordsSelect AS keywords
                                        FROM $cvTable t WHERE t.id = $cv_id
                                    ) AS cv
                                ),
                            'custom_vars',
                                (
                                    SELECT COALESCE(json_agg(custom_vars ORDER BY custom_vars.sortkey, custom_vars.config_id), '[]'::json)
                                    FROM (
                                        SELECT
                                            cfg.id            AS config_id,
                                            cfg.name,
                                            cfg.description,
                                            cfg.type,
                                            cfg.options,
                                            cfg.default_value,
                                            cfg.sortkey,
                                            var.bool_value,
                                            var.number_value,
                                            var.timestamp_value,
                                            var.text_value,
                                            COALESCE(
                                                var.bool_value::text,
                                                var.number_value::text,
                                                to_char(var.timestamp_value, 'DD.MM.YYYY'),
                                                var.text_value
                                            ) AS value
                                        FROM custom_variable_configs cfg
                                        LEFT JOIN custom_variables var
                                            ON var.config_id = cfg.id
                                            AND var.trans_id = $cv_id
                                            AND COALESCE(var.sub_module, '') = ''
                                        WHERE cfg.module = 'CT'
                                    ) AS custom_vars
                                ),
                            'offers',
                                (
                                    SELECT json_agg(obj ORDER BY itime DESC)
                                    FROM (
                                        SELECT
                                            oe.itime,
                                            json_build_object(
                                                'date', to_char(oe.transdate, 'DD.MM.YYYY'),
                                                'description', firstpos.description,
                                                'amount', oe.amount,
                                                'currency', c.name,
                                                'number', oe.quonumber,
                                                'id', oe.id
                                            ) AS obj
                                        FROM oe
                                        LEFT JOIN currencies c ON c.id = oe.currency_id
                                        LEFT JOIN LATERAL (
                                            SELECT oi.description, oi.position
                                            FROM orderitems oi
                                            WHERE oi.trans_id = oe.id
                                            ORDER BY oi.position
                                            LIMIT 1
                                        ) AS firstpos ON TRUE
                                        WHERE record_type = '{$quotationType}' AND {$cvForeignKey} = $cv_id
                                    ) AS t
                                ),
                            'orders',
                                (
                                    SELECT json_agg(obj ORDER BY itime DESC)
                                    FROM (
                                        SELECT
                                            oe.itime,
                                            json_build_object(
                                                'date', to_char(oe.transdate, 'DD.MM.YYYY'),
                                                'description', firstpos.description,
                                                'amount', oe.amount,
                                                'currency', c.name,
                                                'number', oe.ordnumber,
                                                'record_type', oe.record_type,
                                                'license_plate', {$kennzeichenForOrder},
                                                'id', oe.id
                                            ) AS obj
                                        FROM oe
                                        LEFT JOIN currencies c ON c.id = oe.currency_id
                                        LEFT JOIN LATERAL (
                                            {$orderFirstDescription}
                                        ) AS firstpos ON TRUE
                                        WHERE ({$orderCondition}) AND {$cvForeignKey} = $cv_id
                                    ) AS t
                                ),
                            'invoices',
                                (
                                    SELECT json_agg(obj ORDER BY hdr_id DESC)
                                    FROM (
                                        SELECT
                                            inv.id AS hdr_id,
                                            json_build_object(
                                                'date', to_char(inv.transdate, 'DD.MM.YYYY'),
                                                'description', COALESCE(firstpos.description, '---------'),
                                                'amount', inv.amount,
                                                'currency', c.name,
                                                'number', inv.invnumber,
                                                'id', inv.id,
                                                'type', inv.type,
                                                'storno', inv.storno
                                            ) AS obj
                                        FROM {$invoiceTable} inv
                                        LEFT JOIN currencies c ON c.id = inv.currency_id
                                        LEFT JOIN LATERAL (
                                            SELECT i.description, i.position
                                            FROM invoice i
                                            WHERE i.trans_id = inv.id
                                            ORDER BY i.position
                                            LIMIT 1
                                        ) AS firstpos ON TRUE
                                        WHERE {$invoiceForeignKey} = $cv_id
                                    ) AS t
                                ),
                            'delivery_orders',
                                (
                                    SELECT json_agg(obj ORDER BY hdr_id DESC)
                                    FROM (
                                        SELECT
                                            dlo.id AS hdr_id,
                                            json_build_object(
                                                'date', to_char(dlo.transdate, 'DD.MM.YYYY'),
                                                'description', COALESCE(firstpos.description, '---------'),
                                                'number', dlo.donumber,
                                                'ordnumber', dlo.ordnumber,
                                                'closed', dlo.closed,
                                                'delivered', dlo.delivered,
                                                'id', dlo.id,
                                                'license_plate', {$kennzeichenForDeliveryOrder}
                                            ) AS obj
                                        FROM delivery_orders dlo
                                        LEFT JOIN LATERAL (
                                            SELECT doi.description, doi.position
                                            FROM delivery_order_items doi
                                            WHERE doi.delivery_order_id = dlo.id
                                            ORDER BY doi.position
                                            LIMIT 1
                                        ) AS firstpos ON TRUE
                                        WHERE dlo.{$cvForeignKey} = $cv_id
                                          AND dlo.record_type = '{$deliveryOrderType}'
                                    ) AS t
                                ),
                            'reclamations',
                                (
                                    SELECT json_agg(obj ORDER BY hdr_id DESC)
                                    FROM (
                                        SELECT
                                            rec.id AS hdr_id,
                                            json_build_object(
                                                'date', to_char(rec.transdate, 'DD.MM.YYYY'),
                                                'description', COALESCE(firstpos.description, '---------'),
                                                'amount', rec.amount,
                                                'currency', c.name,
                                                'number', rec.record_number,
                                                'closed', rec.closed,
                                                'delivered', rec.delivered,
                                                'id', rec.id,
                                                'license_plate', {$kennzeichenForReclamation}
                                            ) AS obj
                                        FROM reclamations rec
                                        LEFT JOIN currencies c ON c.id = rec.currency_id
                                        LEFT JOIN LATERAL (
                                            SELECT ri.description, ri.position
                                            FROM reclamation_items ri
                                            WHERE ri.reclamation_id = rec.id
                                            ORDER BY ri.position
                                            LIMIT 1
                                        ) AS firstpos ON TRUE
                                        WHERE rec.{$cvForeignKey} = $cv_id
                                          AND rec.record_type = '{$reclamationType}'
                                    ) AS t
                                ),
                            'contacts',
                                (
                                    SELECT json_agg(contacts)
                                    FROM (
                                        SELECT *
                                        FROM contacts
                                        WHERE cp_cv_id = $cv_id
                                    ) AS contacts
                                ),
                            'shiptos',
                                (
                                    SELECT json_agg(shiptos)
                                    FROM (
                                        SELECT * FROM shipto WHERE trans_id = $cv_id AND module = 'CT' ORDER BY shipto_id ASC
                                    ) AS shiptos
                                ),
                            'additional_billing_addresses',
                                (
                                    SELECT json_agg(additional_billing_addresses)
                                    FROM (
                                        SELECT * FROM additional_billing_addresses WHERE customer_id = $cv_id ORDER BY id ASC
                                    ) AS additional_billing_addresses
                                ),
                            'contact_history',
                                (
                                    SELECT json_agg(contact_history)
                                    FROM (
                                        SELECT  EXTRACT( EPOCH FROM TIMESTAMPTZ( crmti_init_time ) ) * 1000 AS call_date, crmti_status, crmti_src, crmti_dst, crmti_caller_id, crmti_caller_typ, crmti_direction, crmti_number, unique_call_id FROM crmti WHERE crmti_caller_id = $cv_id AND crmti_init_time > NOW() - INTERVAL '1.5 WEEK' ORDER BY crmti_init_time DESC LIMIT 12
                                    ) AS contact_history
                                ),
                            'turnover_statistics',
                                (
                                    SELECT json_build_object(
                                        -- Umsatz nach Jahren (letzte 5 Jahre)
                                        'yearly', (
                                            SELECT json_agg(
                                                json_build_object(
                                                    'year', year,
                                                    'revenue', ROUND(COALESCE(revenue, 0)::numeric, 2),
                                                    'invoice_count', COALESCE(invoice_count, 0),
                                                    'avg_invoice', ROUND(COALESCE(avg_invoice, 0)::numeric, 2)
                                                )
                                                ORDER BY year DESC
                                            )
                                            FROM (
                                                SELECT
                                                    EXTRACT(YEAR FROM inv.transdate)::integer AS year,
                                                    SUM(inv.amount * COALESCE(inv.exchangerate, 1))::numeric AS revenue,
                                                    COUNT(inv.id) AS invoice_count,
                                                    AVG(inv.amount * COALESCE(inv.exchangerate, 1))::numeric AS avg_invoice
                                                FROM {$invoiceTable} inv
                                                WHERE inv.{$invoiceForeignKey} = $cv_id
                                                    AND inv.transdate >= (CURRENT_DATE - INTERVAL '5 years')
                                                    AND inv.amount IS NOT NULL
                                                GROUP BY EXTRACT(YEAR FROM inv.transdate)
                                            ) AS yearly_data
                                        ),
                                        -- Umsatz nach Monaten (letzte 12 Monate)
                                        'monthly', (
                                            SELECT json_agg(
                                                json_build_object(
                                                    'month', TO_CHAR(month_date, 'YYYY-MM'),
                                                    'month_name', TO_CHAR(month_date, 'Mon YYYY'),
                                                    'revenue', ROUND(COALESCE(revenue, 0)::numeric, 2),
                                                    'invoice_count', COALESCE(invoice_count, 0)
                                                )
                                                ORDER BY month_date DESC
                                            )
                                            FROM (
                                                SELECT
                                                    DATE_TRUNC('month', inv.transdate) AS month_date,
                                                    SUM(inv.amount * COALESCE(inv.exchangerate, 1))::numeric AS revenue,
                                                    COUNT(inv.id) AS invoice_count
                                                FROM {$invoiceTable} inv
                                                WHERE inv.{$invoiceForeignKey} = $cv_id
                                                    AND inv.transdate >= (CURRENT_DATE - INTERVAL '12 months')
                                                    AND inv.amount IS NOT NULL
                                                GROUP BY DATE_TRUNC('month', inv.transdate)
                                            ) AS monthly_data
                                        ),
                                        -- Umsatz nach Quartalen (letzte 8 Quartale)
                                        'quarterly', (
                                            SELECT json_agg(
                                                json_build_object(
                                                    'quarter', quarter_name,
                                                    'revenue', ROUND(COALESCE(revenue, 0)::numeric, 2),
                                                    'invoice_count', COALESCE(invoice_count, 0)
                                                )
                                                ORDER BY quarter_date DESC
                                            )
                                            FROM (
                                                SELECT
                                                    DATE_TRUNC('quarter', inv.transdate) AS quarter_date,
                                                    'Q' || EXTRACT(QUARTER FROM inv.transdate)::text || ' ' ||
                                                    EXTRACT(YEAR FROM inv.transdate)::text AS quarter_name,
                                                    SUM(inv.amount * COALESCE(inv.exchangerate, 1))::numeric AS revenue,
                                                    COUNT(inv.id) AS invoice_count
                                                FROM {$invoiceTable} inv
                                                WHERE inv.{$invoiceForeignKey} = $cv_id
                                                    AND inv.transdate >= (CURRENT_DATE - INTERVAL '24 months')
                                                    AND inv.amount IS NOT NULL
                                                GROUP BY DATE_TRUNC('quarter', inv.transdate),
                                                         EXTRACT(QUARTER FROM inv.transdate),
                                                         EXTRACT(YEAR FROM inv.transdate)
                                            ) AS quarterly_data
                                        ),
                                        -- Gesamtstatistik
                                        'totals', (
                                            SELECT json_build_object(
                                                'total_revenue_all_time', ROUND(COALESCE(SUM(inv.amount * COALESCE(inv.exchangerate, 1))::numeric, 0), 2),
                                                'total_invoices', COALESCE(COUNT(inv.id), 0),
                                                'avg_invoice_value', ROUND(COALESCE(AVG(inv.amount * COALESCE(inv.exchangerate, 1))::numeric, 0), 2),
                                                'first_invoice_date', TO_CHAR(MIN(inv.transdate), 'DD.MM.YYYY'),
                                                'last_invoice_date', TO_CHAR(MAX(inv.transdate), 'DD.MM.YYYY'),
                                                'currency', COALESCE(
                                                    (SELECT c.name FROM currencies c WHERE c.id =
                                                        (SELECT currency_id FROM {$invoiceTable} WHERE {$invoiceForeignKey} = $cv_id
                                                         ORDER BY transdate DESC LIMIT 1)
                                                    ), 'EUR'
                                                )
                                            )
                                            FROM {$invoiceTable} inv
                                            WHERE inv.{$invoiceForeignKey} = $cv_id
                                        ),
                                        -- Offene Posten
                                        'outstanding', (
                                            SELECT json_build_object(
                                                'count', COALESCE(COUNT(*), 0),
                                                'total_amount', ROUND(COALESCE(SUM(inv.amount - inv.paid)::numeric, 0), 2),
                                                'invoices', (
                                                    SELECT json_agg(
                                                        json_build_object(
                                                            'invoice_number', inv2.invnumber,
                                                            'date', TO_CHAR(inv2.transdate, 'DD.MM.YYYY'),
                                                            'due_date', TO_CHAR(inv2.duedate, 'DD.MM.YYYY'),
                                                            'amount', ROUND(inv2.amount::numeric, 2),
                                                            'paid', ROUND(inv2.paid::numeric, 2),
                                                            'outstanding', ROUND((inv2.amount - inv2.paid)::numeric, 2),
                                                            'days_overdue', CASE
                                                                WHEN inv2.duedate < CURRENT_DATE THEN (CURRENT_DATE - inv2.duedate)
                                                                ELSE 0
                                                            END
                                                        )
                                                        ORDER BY inv2.duedate ASC
                                                    )
                                                    FROM {$invoiceTable} inv2
                                                    WHERE inv2.{$invoiceForeignKey} = $cv_id
                                                        AND inv2.amount > inv2.paid
                                                        AND NOT inv2.storno
                                                )
                                            )
                                            FROM {$invoiceTable} inv
                                            WHERE inv.{$invoiceForeignKey} = $cv_id
                                                AND inv.amount > inv.paid
                                                AND NOT inv.storno
                                        ),
                                        -- Top 10 Produkte (nach Umsatz)
                                        'top_products', (
                                            SELECT json_agg(
                                                json_build_object(
                                                    'partnumber', partnumber,
                                                    'description', description,
                                                    'total_revenue', ROUND(total_revenue::numeric, 2),
                                                    'quantity_sold', quantity_sold,
                                                    'invoice_count', invoice_count
                                                )
                                            )
                                            FROM (
                                                SELECT
                                                    i.parts_id,
                                                    COALESCE(p.partnumber, 'N/A') AS partnumber,
                                                    COALESCE(i.description, 'Unbekannt') AS description,
                                                    SUM(i.qty * i.sellprice * (1 - i.discount))::numeric AS total_revenue,
                                                    SUM(i.qty) AS quantity_sold,
                                                    COUNT(DISTINCT inv.id) AS invoice_count
                                                FROM invoice i
                                                JOIN {$invoiceTable} inv ON inv.id = i.trans_id
                                                LEFT JOIN parts p ON p.id = i.parts_id
                                                WHERE inv.{$invoiceForeignKey} = $cv_id
                                                    AND inv.transdate >= (CURRENT_DATE - INTERVAL '2 years')
                                                    AND i.parts_id IS NOT NULL
                                                GROUP BY i.parts_id, p.partnumber, i.description
                                                ORDER BY total_revenue DESC
                                                LIMIT 10
                                            ) AS top_products_data
                                        ),
                                        -- Trend-Analyse (Vergleich letztes Jahr vs. dieses Jahr)
                                        'trend', (
                                            SELECT json_build_object(
                                                'current_year_revenue', ROUND(COALESCE(current_year.revenue, 0)::numeric, 2),
                                                'last_year_revenue', ROUND(COALESCE(last_year.revenue, 0)::numeric, 2),
                                                'growth_absolute', ROUND((COALESCE(current_year.revenue, 0) - COALESCE(last_year.revenue, 0))::numeric, 2),
                                                'growth_percent', CASE
                                                    WHEN COALESCE(last_year.revenue, 0) > 0
                                                    THEN ROUND((((COALESCE(current_year.revenue, 0) - COALESCE(last_year.revenue, 0)) / last_year.revenue * 100))::numeric, 2)
                                                    ELSE 0
                                                END,
                                                'current_year_invoices', COALESCE(current_year.invoice_count, 0),
                                                'last_year_invoices', COALESCE(last_year.invoice_count, 0)
                                            )
                                            FROM
                                                (SELECT
                                                    SUM(inv.amount * COALESCE(inv.exchangerate, 1))::numeric AS revenue,
                                                    COUNT(inv.id) AS invoice_count
                                                FROM {$invoiceTable} inv
                                                WHERE inv.{$invoiceForeignKey} = $cv_id
                                                    AND EXTRACT(YEAR FROM inv.transdate) = EXTRACT(YEAR FROM CURRENT_DATE)
                                                ) AS current_year,
                                                (SELECT
                                                    SUM(inv.amount * COALESCE(inv.exchangerate, 1))::numeric AS revenue,
                                                    COUNT(inv.id) AS invoice_count
                                                FROM {$invoiceTable} inv
                                                WHERE inv.{$invoiceForeignKey} = $cv_id
                                                    AND EXTRACT(YEAR FROM inv.transdate) = EXTRACT(YEAR FROM CURRENT_DATE) - 1
                                                ) AS last_year
                                        )
                                    )
                                )
SQL;
if($lxCars && !$isVendor) {
    $query .= <<<SQL
                            ,
                            'cars',
                                (
                                    SELECT json_agg(cars)
                                    FROM (
                                        SELECT
                                            cars_lxcars.c_id,
                                            cars_lxcars.c_ln,
                                            COALESCE(sk.hersteller, kba.hersteller, '---------') AS hersteller,
                                            COALESCE(sk.name, kba.name, '---------')             AS name,
                                            COALESCE(sk.fhzart, kba.fhzart, '---------')         AS mytype
                                        FROM cars_lxcars
                                        LEFT JOIN special_kba_lxcars sk ON (cars_lxcars.c_id = sk.c_id)
                                        LEFT JOIN kba_lxcars kba ON (cars_lxcars.kba_id = kba.id)
                                        WHERE cars_lxcars.c_ow = $cv_id
                                        ORDER BY c_id DESC
                                    ) AS cars
                                )
    SQL;
}
$query .= <<<SQL
                        )
                    )
            ) AS main
SQL;
    //writeLog( $query );
    //writeLog( $withConfig );
    //debugVar($mandant->get($query, $withConfig), 'getCV Query');
    //writeLog($mandant->get($query, $withConfig));
    echo $mandant->get($query, $withConfig); //Hier ist kein success, etc mehr, da es direkt das JSON-Objekt zurückgibt!!!
}
/**
 * Speichert Kunden- oder Lieferanten-Daten
 *
 * @param array $data['profile'] Profil-Daten des Kunden/Lieferanten mit 'id' und 'src' (C/V)
 * @param array $data Weitere Tabellen-Daten (contacts, shipto, etc.)
 * @testdata {"profile": {"id": 1, "src": "C", "name": "Test Kunde", "customernumber": "K-001"}}
 */
function saveCV($data) {
    include_once __DIR__ . '/../features.php';
    $apiCompanySpace = DbhCompany::begin();
    $cv_id = $data['profile']['id'] ?? null;
    $isNew = empty($cv_id);

    if (!$isNew) {
        if(!checkPermissions('customer_vendor_all_edit')) {
            permit('customer_vendor_edit');
            $login = DbhAuth::begin()->getLogin();
            $ownedCVs = $apiCompanySpace->getAll("SELECT cv.* FROM customer cv LEFT JOIN employee emp ON cv.salesman_id = emp.id WHERE emp.login = :login AND cv.id = :cv_id", [
                ':login' => $login,
                ':cv_id' => $cv_id
            ]);
            if(count($ownedCVs) === 0) {
                throw new ApiError("NO_PERMISSION", "Keine Berechtigung, diesen Kunden/Lieferanten zu bearbeiten.");
            }
        }
    } else {
        permit(['customer_vendor_edit', 'customer_vendor_all_edit'], false);
    }

    // Duplikat-Pruefung: Name + Straße + PLZ muessen uebereinstimmen
    // Nur innerhalb derselben Tabelle blockieren (Kunde=Kunde, Lieferant=Lieferant).
    // Cross-Table (Kunde existiert als Lieferant) ist erlaubt.
    $name = trim($data['profile']['name'] ?? '');
    $street = trim($data['profile']['street'] ?? '');
    $zipcode = trim($data['profile']['zipcode'] ?? '');
    $src = $data['profile']['src'] ?? 'C';
    $sameTable = ($src === 'V') ? 'vendor' : 'customer';
    if ($name !== '' && $street !== '' && $zipcode !== '') {
        $duplicateQuery = <<<SQL
            SELECT id, name, street, zipcode, city FROM $sameTable
            WHERE LOWER(name) = LOWER(:name) AND LOWER(street) = LOWER(:street) AND LOWER(zipcode) = LOWER(:zipcode)
        SQL;
        $duplicates = $apiCompanySpace->getAll($duplicateQuery, [
            ':name' => $name, ':street' => $street, ':zipcode' => $zipcode
        ]);

        // Eigenen Datensatz ausschliessen
        $duplicates = array_filter($duplicates, function($d) use ($cv_id) {
            return $d['id'] != $cv_id;
        });

        if (!empty($duplicates)) {
            $dup = array_values($duplicates)[0];
            $type = ($src === 'V') ? 'Lieferant' : 'Kunde';
            $details = "Name: \"{$dup['name']}\", Straße: \"{$dup['street']}\", " . trim($dup['zipcode'] . ' ' . $dup['city']);
            throw new ApiError("DUPLICATE_CV", "$type bereits vorhanden: $details (ID: {$dup['id']}).");
        }
    }

    // E-Mail-Validierung: Format + DNS-Check (MX-Record)
    $emailFields = ['email' => 'E-Mail', 'cc' => 'CC', 'bcc' => 'BCC',
                     'invoice_mail' => 'Rechnungs-E-Mail', 'delivery_order_mail' => 'Lieferschein-E-Mail'];
    foreach ($emailFields as $field => $label) {
        $val = trim($data['profile'][$field] ?? '');
        if ($val === '') continue;
        if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError("VALIDATION_ERROR", "Ungültige $label: $val");
        }
        $domain = substr($val, strrpos($val, '@') + 1);
        if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
            throw new ApiError("VALIDATION_ERROR", "$label-Domain existiert nicht: $domain");
        }
    }

    // CC/BCC duerfen nicht identisch mit Haupt-E-Mail oder untereinander sein
    $email = strtolower(trim($data['profile']['email'] ?? ''));
    $cc    = strtolower(trim($data['profile']['cc'] ?? ''));
    $bcc   = strtolower(trim($data['profile']['bcc'] ?? ''));
    if ($cc !== '' && $cc === $email) {
        throw new ApiError("VALIDATION_ERROR", "CC darf nicht identisch mit der Haupt-E-Mail sein.");
    }
    if ($bcc !== '' && $bcc === $email) {
        throw new ApiError("VALIDATION_ERROR", "BCC darf nicht identisch mit der Haupt-E-Mail sein.");
    }
    if ($cc !== '' && $bcc !== '' && $cc === $bcc) {
        throw new ApiError("VALIDATION_ERROR", "CC und BCC dürfen nicht identisch sein.");
    }

    // Homepage-Validierung: Punkt + gueltige TLD (kein Ping beim Speichern)
    $homepage = trim($data['profile']['homepage'] ?? '');
    if ($homepage !== '') {
        // Protokoll entfernen fuer Format-Check
        $urlBody = preg_replace('#^https?://#i', '', $homepage);
        if (!preg_match('/[^\s\/]+\.[a-zA-Z]{2,}/', $urlBody)) {
            throw new ApiError("VALIDATION_ERROR", "Ungültige Homepage-URL: $homepage");
        }
    }

    // phone_numbers vor dem Loop extrahieren und aus profile entfernen
    $phoneNumbers = null;
    $hasPhoneNumbers = false;
    if (array_key_exists('phone_numbers', $data['profile'])) {
        $hasPhoneNumbers = true;
        $phoneNumbers = $data['profile']['phone_numbers'];
        unset($data['profile']['phone_numbers']);
    }

    // keywords vor dem Loop extrahieren und aus profile entfernen
    $keywords = null;
    $hasKeywords = false;
    if (array_key_exists('keywords', $data['profile'])) {
        $hasKeywords = true;
        $keywords = $data['profile']['keywords'];
        unset($data['profile']['keywords']);
    }

    // benutzerdefinierte Variablen vor dem Loop extrahieren, damit sie nicht
    // als unbekannte Tabelle im Hauptloop landen
    $customVars = null;
    $hasCustomVars = false;
    if (array_key_exists('custom_vars', $data)) {
        $hasCustomVars = true;
        $customVars = $data['custom_vars'];
        unset($data['custom_vars']);
    }

    $apiCompanySpace->beginTransaction();
    $newId = null;
    foreach($data as $tableName => $tableData) {
        if('action' === $tableName) {
            continue;
        }
        $table = null;
        $conflictColumns = ['id'];
        if('profile' === $tableName) {
            if('C' === $tableData['src']) {
                $table = 'customer';
                // vendornumber existiert nur in der vendor-Tabelle
                unset($tableData['vendornumber']);
            } else {
                $table = 'vendor';
                // customernumber existiert nur in der customer-Tabelle
                unset($tableData['customernumber']);
            }
            // Entferne das 'src'-Feld, da es nicht in der Tabelle existiert
            unset($tableData['src']);
        }
        elseif('additional_billing_addresses' === $tableName) {
            $table = 'additional_billing_addresses';
        }
        elseif('shiptos' === $tableName) {
            $table = 'shipto';
            $conflictColumns = ['shipto_id'];
        }
        elseif('contacts' === $tableName) {
            $table = 'contacts';
            $conflictColumns = ['cp_id'];
        }
        else {
            debugLog("Unknown tableData '$tableName' in saveCV2, skipping.", DLOG_INF);
            continue;
        }
        if(!is_array($tableData)) {
            debugLog("TableData for '$tableName' is not an array, skipping.", DLOG_INF);
            continue;
        }
        if(empty($tableData)) {
            debugLog("TableData for '$tableName' is empty, skipping.", DLOG_INF);
            continue;
        }
        if(array_keys($tableData) !== range(0, count($tableData) - 1)) {
            // Einzelnes Objekt, kein Array von Objekten
            $tableData = [ $tableData ];
        }
        try {
            foreach($tableData as $row) {
               //writeLog("=== Processing row for table: $table ===");
               //writeLog("Row BEFORE cleanup: " . json_encode($row));
                // SCHRITT 1: INLINE Datenbereinigung - leere Strings → NULL, Booleans → PG-kompatibel
                foreach ($row as $key => $value) {
                    if (is_bool($value)) {
                        // PHP PDO sendet false als "" → PostgreSQL versteht 't'/'f'
                        $row[$key] = $value ? 't' : 'f';
                    } elseif (is_string($value)) {
                        $trimmed = trim($value);
                        if ($trimmed === '' || $trimmed === 'null') {
                            $row[$key] = null;
                        } elseif ($trimmed === 'false') {
                            $row[$key] = 'f';
                        } elseif ($trimmed === 'true') {
                            $row[$key] = 't';
                        } else {
                            $row[$key] = $trimmed;
                        }
                    } elseif ($value === '') {
                        $row[$key] = null;
                    }
                }
               //writeLog("Row AFTER cleanup: " . json_encode($row));
                // SCHRITT 2: Bestimme den Primary Key für diese Tabelle
                $pkField = $conflictColumns[0]; // 'id', 'shipto_id' oder 'cp_id'
               //writeLog("Primary key field: $pkField");
               //writeLog("Primary key value: " . json_encode($row[$pkField] ?? 'NOT SET'));
                // SCHRITT 3: Prüfe ob Primary Key vorhanden ist (NACH Bereinigung!)
                $hasPrimaryKey = isset($row[$pkField]) &&
                                 $row[$pkField] !== null &&
                                 $row[$pkField] !== '';
               //writeLog("Has primary key: " . ($hasPrimaryKey ? 'YES' : 'NO'));
                if ($hasPrimaryKey) {
                    // Kundentyp geändert → neue Kundennummer aus neuem Nummernkreis
                    if ($tableName === 'profile' && $table === 'customer' && isset($row['business_id'])) {
                        $oldBiz = $apiCompanySpace->getOne(
                            "SELECT business_id FROM customer WHERE id = :id",
                            [':id' => $row['id']]
                        );
                        $oldBizId = $oldBiz['business_id'] ?? null;
                        $newBizId = $row['business_id'];
                        if ($newBizId != $oldBizId && $newBizId) {
                            $seq = $apiCompanySpace->getOne(
                                "UPDATE business SET customernumberinit = (COALESCE(NULLIF(customernumberinit, ''), '0')::INT + 1)::TEXT WHERE id = :biz_id RETURNING customernumberinit",
                                [':biz_id' => $newBizId]
                            );
                            $row['customernumber'] = $seq['customernumberinit'];
                        }
                    }
                    // UPDATE: Datensatz existiert bereits → UPSERT verwenden
                    $apiCompanySpace->upsert($table, $row, $conflictColumns);
                } else {
                    // INSERT: Neuer Datensatz → Entferne NULL/leeren Primary Key
                    unset($row[$pkField]);
                    // WICHTIG: Setze Fremdschlüssel für neue Datensätze
                    if ($table === 'contacts' && $cv_id) {
                        $row['cp_cv_id'] = $cv_id;
                    }
                    if ($table === 'shipto' && $cv_id) {
                        $row['trans_id'] = $cv_id;
                        $row['module'] = 'CT';
                    }
                    if ($table === 'additional_billing_addresses' && $cv_id) {
                        $row['customer_id'] = $cv_id;
                    }

                    // Neues Profil: INSERT mit RETURNING id + Nummernkreis
                    if ($tableName === 'profile' && $isNew) {
                        // Nummernkreis: Automatisch customernumber/vendornumber vergeben
                        if ($table === 'customer') {
                            $businessId = $row['business_id'] ?? null;
                            if ($businessId) {
                                // Kundentyp gesetzt → Nummernkreis aus business.customernumberinit
                                $bRow = $apiCompanySpace->getOne(
                                    "UPDATE business SET customernumberinit = (COALESCE(NULLIF(customernumberinit, ''), '0')::INT + 1)::TEXT WHERE id = :biz_id RETURNING customernumberinit",
                                    [':biz_id' => $businessId]
                                );
                                $candidate = (int)$bRow['customernumberinit'];
                                while ($apiCompanySpace->getOne("SELECT 1 FROM customer WHERE customernumber = :num", [':num' => (string)$candidate])) {
                                    $candidate++;
                                }
                                if ($candidate !== (int)$bRow['customernumberinit']) {
                                    $apiCompanySpace->execute("UPDATE business SET customernumberinit = :num WHERE id = :biz_id", [':num' => (string)$candidate, ':biz_id' => $businessId]);
                                }
                                $row['customernumber'] = (string)$candidate;
                            } else {
                                // Kein Kundentyp → Nummernkreis aus defaults.customernumber
                                $row['customernumber'] = nextFreeNumber($apiCompanySpace, 'customernumber', 'customer', 'customernumber');
                            }
                        } elseif ($table === 'vendor') {
                            $row['vendornumber'] = nextFreeNumber($apiCompanySpace, 'vendornumber', 'vendor', 'vendornumber');
                        }

                        $cols = array_keys($row);
                        $params = [];
                        $valueParts = [];
                        foreach ($row as $k => $v) {
                            $valueParts[] = ':' . $k;
                            $params[':' . $k] = $v;
                        }

                        $sql = "INSERT INTO $table (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $valueParts) . ") RETURNING id";
                        $result = $apiCompanySpace->getOne($sql, $params);
                        $newId = intval($result['id']);
                        $cv_id = $newId;
                    } else {
                        $apiCompanySpace->insert($table, array_keys($row), array_values($row));
                    }
                }
               //writeLog("=== Row processed successfully ===\n");
            }
        }
        catch (Exception $e) {
            $apiCompanySpace->rollBack();
            writeLog("Fehler beim Speichern der Tabelle '$table': " . $e->getMessage(), DLOG_ERR);
            throw $e;
        }
    }
    // phone_numbers in customer_ext/vendor_ext speichern
    if ($hasPhoneNumbers && $cv_id) {
        $jsonValue = (!empty($phoneNumbers) && is_array($phoneNumbers)) ? json_encode($phoneNumbers) : null;
        $extTable = ($src === 'V') ? 'vendor_ext' : 'customer_ext';
        $extFk    = ($src === 'V') ? 'vendor_id'  : 'customer_id';
        $apiCompanySpace->execute(
            "INSERT INTO $extTable ($extFk, phone_numbers) VALUES (:cid, :pn)
             ON CONFLICT ($extFk) DO UPDATE SET phone_numbers = :pn, mtime = now()",
            [':cid' => $cv_id, ':pn' => $jsonValue]
        );
    }

    // keywords in customer_ext/vendor_ext speichern
    if ($hasKeywords && $cv_id) {
        $kwValue = is_string($keywords) ? trim($keywords) : '';
        $kwValue = $kwValue !== '' ? $kwValue : null;
        $extTable = ($src === 'V') ? 'vendor_ext' : 'customer_ext';
        $extFk    = ($src === 'V') ? 'vendor_id'  : 'customer_id';
        $apiCompanySpace->execute(
            "INSERT INTO $extTable ($extFk, keywords) VALUES (:cid, :kw)
             ON CONFLICT ($extFk) DO UPDATE SET keywords = :kw, mtime = now()",
            [':cid' => $cv_id, ':kw' => $kwValue]
        );
    }

    // benutzerdefinierte Variablen (custom_variables) speichern.
    // Es gibt keinen UNIQUE-Constraint auf (config_id, trans_id), daher wird der
    // vorhandene Wert geloescht und ggf. neu eingefuegt (leere Werte = nicht gesetzt).
    if ($hasCustomVars && $cv_id && is_array($customVars)) {
        foreach ($customVars as $cvar) {
            $configId = $cvar['config_id'] ?? null;
            if (!$configId) continue;
            $type = $cvar['type'] ?? 'text';
            $raw  = $cvar['value'] ?? null;

            $boolVal = null; $numVal = null; $tsVal = null; $textVal = null;
            $hasValue = !($raw === null || $raw === '');
            switch ($type) {
                case 'bool':
                case 'boolean':
                    // Checkbox: immer einen Wert setzen (true/false)
                    $boolVal = ($raw === true || $raw === 't' || $raw === 'true' || $raw === 1 || $raw === '1') ? 't' : 'f';
                    $hasValue = true;
                    break;
                case 'number':
                    $numVal = $hasValue ? $raw : null;
                    break;
                case 'date':
                case 'timestamp':
                    $tsVal = $hasValue ? $raw : null;
                    break;
                default:
                    $textVal = $hasValue ? trim((string)$raw) : null;
                    if ($textVal === '') { $textVal = null; $hasValue = false; }
                    break;
            }

            $apiCompanySpace->execute(
                "DELETE FROM custom_variables WHERE config_id = :cid AND trans_id = :tid AND COALESCE(sub_module, '') = ''",
                [':cid' => $configId, ':tid' => $cv_id]
            );
            if ($hasValue) {
                $apiCompanySpace->execute(
                    "INSERT INTO custom_variables (config_id, trans_id, sub_module, bool_value, number_value, timestamp_value, text_value)
                     VALUES (:cid, :tid, '', :bv, :nv, :tv, :txt)",
                    [':cid' => $configId, ':tid' => $cv_id, ':bv' => $boolVal, ':nv' => $numVal, ':tv' => $tsVal, ':txt' => $textVal]
                );
            }
        }
    }

    $apiCompanySpace->commit();

    // Dateiordner + Symlinks sicherstellen
    $cvName = trim($data['profile']['name'] ?? '');
    $cvSrcForFolder = $data['profile']['src'] ?? 'C';
    if ($cvName && $cv_id) {
        ensureCustomerFolder($cv_id, $cvSrcForFolder, $cvName);
    }

    $payload = ['message' => 'Gespeichert'];
    if ($newId) {
        $payload['new_id'] = $newId;
    }
    resultInfo(true, 'SAVED', $payload);
}
/**
 * Ermittelt die Anrede anhand des Vornamens über die Tabelle firstnametogender
 *
 * @param array $data Array mit 'firstname' => Vorname
 * @testdata {"action": "lookupGreeting", "firstname": "Hans"}
 */
function lookupGreeting($data) {
    $firstname = trim($data['firstname'] ?? '');
    if ($firstname === '') {
        resultInfo(true, '', ['greeting' => '']);
        return;
    }
    $mandant = DbhCompany::begin();
    $row = $mandant->getOne(
        "SELECT gender FROM firstnametogender WHERE LOWER(firstname) = LOWER(:firstname)",
        [':firstname' => $firstname]
    );
    $greeting = '';
    if ($row) {
        if ($row['gender'] === 'F') $greeting = 'Frau';
        elseif ($row['gender'] === 'M') $greeting = 'Herr';
    }
    resultInfo(true, '', ['greeting' => $greeting]);
}

/**
 * Löscht einen Ansprechpartner aus der Datenbank
 *
 * @param array $data Array mit 'cp_id' => ID des zu löschenden Ansprechpartners
 * @return void Gibt JSON mit Erfolgs-Status aus
 *
 * @testdata {"action": "deleteContact", "cp_id": 123}
 */
function deleteContact($data) {
    // Hole Datenbank-Verbindung
    $apiCompanySpace = DbhCompany::begin();
    // Prüfe, ob cp_id vorhanden ist
    if (!isset($data['cp_id']) || empty($data['cp_id'])) {
        throw new ApiError('MISSING_CP_ID', 'cp_id fehlt oder ist leer');
    }
    $cp_id = $data['cp_id'];
    // Prüfe Berechtigungen
    if (!checkPermissions('customer_vendor_all_edit')) {
        permit('customer_vendor_edit');
        // Hole den Login des aktuellen Benutzers
        $login = DbhAuth::begin()->getLogin();
        // Prüfe, ob der Benutzer Zugriff auf den Kunden/Lieferanten hat,
        // zu dem dieser Ansprechpartner gehört
        $ownedCV = $apiCompanySpace->getOne(
            "SELECT cv.id
             FROM contacts c
             LEFT JOIN customer cv ON c.cp_cv_id = cv.id
             LEFT JOIN employee emp ON cv.salesman_id = emp.id
             WHERE c.cp_id = :cp_id AND emp.login = :login",
            [':cp_id' => $cp_id, ':login' => $login]
        );
        if (!$ownedCV) {
            throw new ApiError('NO_PERMISSION', 'Keine Berechtigung, diesen Ansprechpartner zu löschen');
        }
    }
    // Lösche den Ansprechpartner
    try {
        $apiCompanySpace->beginTransaction();
        $apiCompanySpace->execute(
            "DELETE FROM contacts WHERE cp_id = :cp_id",
            [':cp_id' => $cp_id]
        );
        $apiCompanySpace->commit();
        // Erfolgreiche Antwort
        echo json_encode([
            'success' => true,
            'message' => 'Ansprechpartner erfolgreich gelöscht'
        ]);
    } catch (Exception $e) {
        $apiCompanySpace->rollBack();
        throw $e;
    }
}

/**
 * Gibt den Dateinamen einer Telefonaufnahme zurück, die mit dem CRM-Telefonie-Interface (crmti) verknüpft ist
 *
 * @param array $data Array mit 'unique_call_id' => Unique Call ID des Anrufs
 * @return void Gibt JSON mit dem Dateinamen der Aufnahme oder einem Fehler aus
 *
 * @testdata {"action": "playPhoneCall", "unique_call_id": "1771403745.750"}
 */
function playPhoneCall($data) {
    if (!isset($data['unique_call_id']) || empty($data['unique_call_id'])) {
        throw new ApiError('MISSING_UNIQUE_CALL_ID', 'unique_call_id fehlt oder ist leer');
    }

    $uniqueCallId = $data['unique_call_id'];
    $monitorDir = TELEPHONY_MONITOR_DIR;
    $files = scandir($monitorDir);

    foreach ($files as $file) {
        // Dateien die kleiner als 44 Byte sind, sind i.d.R leere WAV-Header ohne Inhalt
        if (strpos($file, $uniqueCallId) !== false && filesize($monitorDir . '/' . $file) > 44) {
            echo json_encode(['success' => true, 'payload' => ['filename' => $file]]);
            return;
        }
    }

    resultInfo(false, 'FILE_NOT_FOUND', null, 'Keine Aufnahme gefunden für die übergebene unique_call_id');
}

/**
 * Gibt die gesamte Anrufliste aller Kunden/Lieferanten zurück
 *
 * @param array $data Optional: 'limit' (Standard 100), 'offset' (Standard 0),
 *                     'search' (Freitextsuche), 'direction' (E/A), 'date_from', 'date_to' (YYYY-MM-DD)
 * @return void Gibt JSON mit der Anrufliste zurück
 *
 * @testdata {"action": "getAllCallHistory"}
 */
function getAllCallHistory($data) {
    $mandant = DbhCompany::begin();
    $pdo = $mandant->getPDO();
    $limit = isset($data['limit']) ? intval($data['limit']) : 100;
    $offset = isset($data['offset']) ? intval($data['offset']) : 0;
    $limitClause = $limit > 0 ? "LIMIT $limit OFFSET $offset" : '';

    $where = [];

    if (!empty($data['direction'])) {
        $where[] = "crmti_direction = " . $pdo->quote($data['direction']);
    }

    if (!empty($data['date_from'])) {
        $where[] = "crmti_init_time >= " . $pdo->quote($data['date_from'] . ' 00:00:00') . "::timestamptz";
    }

    if (!empty($data['date_to'])) {
        $where[] = "crmti_init_time <= " . $pdo->quote($data['date_to'] . ' 23:59:59') . "::timestamptz";
    }

    if (!empty($data['search'])) {
        $searchVal = $pdo->quote('%' . $data['search'] . '%');
        $where[] = "(crmti_src ILIKE $searchVal OR crmti_dst ILIKE $searchVal OR crmti_number ILIKE $searchVal)";
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $query = <<<SQL
        WITH filtered AS (
            SELECT * FROM crmti $whereClause
        )
        SELECT json_build_object(
            'call_history', (
                SELECT json_agg(ch)
                FROM (
                    SELECT
                        crmti_id,
                        EXTRACT(EPOCH FROM TIMESTAMPTZ(crmti_init_time)) * 1000 AS call_date,
                        crmti_status,
                        crmti_src,
                        crmti_dst,
                        crmti_caller_id,
                        crmti_caller_typ,
                        crmti_direction,
                        crmti_number,
                        unique_call_id,
                        CASE crmti_caller_typ
                            WHEN 'C' THEN (SELECT name FROM customer WHERE id = crmti_caller_id)
                            WHEN 'V' THEN (SELECT name FROM vendor WHERE id = crmti_caller_id)
                            ELSE NULL
                        END AS caller_name
                    FROM filtered
                    ORDER BY crmti_init_time DESC
                    $limitClause
                ) AS ch
            ),
            'total_count', (
                SELECT COUNT(*) FROM filtered
            )
        ) AS main
    SQL;

    echo $mandant->get($query);
}

/**
 * Ordnet einen Anruf einem Kunden oder Lieferanten zu (oder entfernt die Zuordnung)
 *
 * @param int    $data['crmti_id']       ID des Anrufdatensatzes
 * @param int    $data['caller_id']      ID des Kunden/Lieferanten (0 zum Entfernen)
 * @param string $data['caller_typ']     'C' für Kunde, 'V' für Lieferant, 'X' zum Entfernen
 * @param string $data['phone_number']   Telefonnummer zum Speichern beim Kunden (optional)
 * @param string $data['phone_label']    Label für die Nummer z.B. "Büro", "Mobil" (optional)
 * @testdata {"action": "assignCallToCv", "crmti_id": 1, "caller_id": 42, "caller_typ": "C"}
 */
function assignCallToCv($data) {
    $mandant = DbhCompany::begin();
    $pdo = $mandant->getPDO();

    $crmtiId = intval($data['crmti_id'] ?? 0);
    $callerId = intval($data['caller_id'] ?? 0);
    $callerTyp = $data['caller_typ'] ?? 'X';
    $phoneNumber = $data['phone_number'] ?? null;
    $phoneLabel = $data['phone_label'] ?? null;

    if ($crmtiId <= 0) {
        throw new ApiError('MISSING_CRMTI_ID', 'crmti_id fehlt oder ist ungültig');
    }

    if (!in_array($callerTyp, ['C', 'V', 'X'])) {
        throw new ApiError('INVALID_CALLER_TYP', 'caller_typ muss C, V oder X sein');
    }

    // 1. Anruf zuordnen
    $query = <<<SQL
        UPDATE crmti
        SET crmti_caller_id = :caller_id,
            crmti_caller_typ = :caller_typ
        WHERE crmti_id = :crmti_id
        RETURNING json_build_object(
            'crmti_id', crmti_id,
            'crmti_caller_id', crmti_caller_id,
            'crmti_caller_typ', crmti_caller_typ,
            'caller_name', CASE :caller_typ
                WHEN 'C' THEN (SELECT name FROM customer WHERE id = crmti_caller_id)
                WHEN 'V' THEN (SELECT name FROM vendor WHERE id = crmti_caller_id)
                ELSE NULL
            END
        ) AS result
    SQL;

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':crmti_id' => $crmtiId,
        ':caller_id' => $callerId,
        ':caller_typ' => $callerTyp,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new ApiError('CRMTI_NOT_FOUND', 'Anrufdatensatz nicht gefunden');
    }

    // 2. Telefonnummer beim Kunden/Lieferanten speichern (Label ist optional)
    // Format: phone_numbers = [{"label": "Büro", "number": "030-123"}, ...]
    if ($phoneNumber && in_array($callerTyp, ['C', 'V'])) {
        $extTable = $callerTyp === 'C' ? 'customer_ext' : 'vendor_ext';
        $extFk = $callerTyp === 'C' ? 'customer_id' : 'vendor_id';

        // Bestehende Einträge laden
        $extStmt = $pdo->prepare("SELECT phone_numbers FROM $extTable WHERE $extFk = :cid");
        $extStmt->execute([':cid' => $callerId]);
        $extRow = $extStmt->fetch(PDO::FETCH_ASSOC);

        $entries = $extRow ? json_decode($extRow['phone_numbers'] ?? '[]', true) : [];
        if (!is_array($entries)) $entries = [];

        // Nur hinzufügen wenn die Nummer noch nicht vorhanden ist
        $exists = false;
        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['number'] ?? '') === $phoneNumber) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $entries[] = ['label' => $phoneLabel ?? '', 'number' => $phoneNumber];
            $jsonEntries = json_encode($entries);

            $upsert = $pdo->prepare(<<<SQL
                INSERT INTO $extTable ($extFk, phone_numbers)
                VALUES (:cid, :pn)
                ON CONFLICT ($extFk) DO UPDATE SET
                    phone_numbers = :pn,
                    mtime = now()
            SQL);
            $upsert->execute([
                ':cid' => $callerId,
                ':pn' => $jsonEntries,
            ]);
        }
    }

    echo json_encode(['success' => true, 'payload' => json_decode($row['result'], true)]);
}

/**
 * Ermittelt die Orte anhand der Postleitzahl aus zipcode_location_oserp
 *
 * @param array $data['zipcode'] Postleitzahl (4-5-stellig)
 * @testdata {"action": "lookupZipcode", "zipcode": "10115"}
 */
function lookupZipcode($data) {
    $zipcode = trim($data['zipcode'] ?? '');
    if ($zipcode === '') {
        resultInfo(true, '', ['cities' => []]);
        return;
    }
    $mandant = DbhCompany::begin();
    $rows = $mandant->getAll(
        "SELECT DISTINCT ort FROM zipcode_location_oserp WHERE plz = :plz ORDER BY ort",
        [':plz' => $zipcode]
    );
    $cities = array_column($rows, 'ort');
    resultInfo(true, '', ['cities' => $cities]);
}

/**
 * Validiert eine E-Mail-Adresse: Format + DNS-Check (MX/A-Record)
 * Leichtgewichtiger Endpoint fuer Frontend-Live-Validierung.
 *
 * @param array $data['email'] Die zu pruefende E-Mail-Adresse
 * @testdata {"email": "test@example.com"}
 */
function validateEmail($data) {
    $email = trim($data['email'] ?? '');
    if ($email === '') {
        resultInfo(true, '', ['valid' => true, 'error' => null]);
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        resultInfo(true, '', ['valid' => false, 'error' => 'format', 'message' => "Ungültiges E-Mail-Format: $email"]);
        return;
    }

    $domain = substr($email, strrpos($email, '@') + 1);
    if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) {
        resultInfo(true, '', ['valid' => false, 'error' => 'dns', 'message' => "Domain existiert nicht: $domain"]);
        return;
    }

    resultInfo(true, '', ['valid' => true, 'error' => null]);
}

/**
 * Initiiert einen Click-to-Call Anruf ueber das Asterisk Manager Interface (AMI).
 * Laedt die Asterisk-Konfiguration und benutzerspezifische Telefoneinstellungen
 * in einer einzigen DB-Abfrage. Oeffnet eine TCP-Socket-Verbindung zum AMI,
 * authentifiziert sich und sendet einen Originate-Request.
 *
 * @param string $data['phone_number'] Telefonnummer des Angerufenen
 * @param string $data['contact_name'] Name des Kontakts (fuer Caller-ID Anzeige)
 * @param int $data['employee_id'] ID des Mitarbeiters (aus dem Store)
 * @testdata {"action": "clickToCall", "phone_number": "030123456", "contact_name": "Max Mustermann", "employee_id": 1}
 */
function clickToCall($data) {
    if (!isset($data['phone_number']) || trim($data['phone_number']) === '') {
        throw new ApiError('MISSING_PHONE_NUMBER', 'phone_number fehlt oder ist leer');
    }

    $phoneNumber = str_replace('+', '00', trim($data['phone_number']));
    $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    $contactName = trim($data['contact_name'] ?? '');
    $employeeId = intval($data['employee_id'] ?? 0);
    $mandant = DbhCompany::begin();

    // Globale + benutzerspezifische Asterisk-Konfiguration in einer Abfrage
    $query = <<<SQL
        SELECT json_build_object(
            'ip_asterisk',          (SELECT value FROM defaults_oserp WHERE key = 'ip_asterisk'),
            'asterisk_passwd',      (SELECT value FROM defaults_oserp WHERE key = 'asterisk_passwd'),
            'external_contexts',    (SELECT value FROM defaults_oserp WHERE key = 'external_contexts'),
            'internal_phones',      (SELECT value FROM defaults_oserp WHERE key = 'internal_phones'),
            'crmti_mobile_number',  (SELECT value FROM defaults_oserp WHERE key = 'crmti_mobile_number'),
            'user_external_context',(SELECT value FROM defaults_oserp WHERE key = :user_context_key),
            'user_internal_phone',  (SELECT value FROM defaults_oserp WHERE key = :user_phone_key)
        ) AS config
    SQL;

    $result = $mandant->getOne($query, [
        ':user_context_key' => 'employee_' . $employeeId . '_external_context',
        ':user_phone_key' => 'employee_' . $employeeId . '_internal_phone'
    ]);

    $config = json_decode($result['config'], true);

    $ip = $config['ip_asterisk'] ?? '';
    $passwd = $config['asterisk_passwd'] ?? '';
    if ($ip === '' || $passwd === '') {
        throw new ApiError('PHONE_CONFIG_MISSING', 'Asterisk IP oder Passwort nicht konfiguriert');
    }

    $externalContext = $config['user_external_context']
        ?? trim(explode(',', $config['external_contexts'] ?? '')[0] ?? '');
    $internalPhone = $config['user_internal_phone']
        ?? trim(explode(',', $config['internal_phones'] ?? '')[0] ?? '');
    $mobileNumber = $config['crmti_mobile_number'] ?? '';

    if ($externalContext === '' || $internalPhone === '') {
        throw new ApiError('PHONE_USER_CONFIG_MISSING', 'Externer Kontext oder internes Telefon nicht konfiguriert');
    }

    $externalContext = trim($externalContext);
    $internalPhone = trim($internalPhone);
    $port = 5038;
    $username = 'clickToCall';

    // Originate-Request erstellen
    if (stripos($internalPhone, 'Handy') !== false || stripos($internalPhone, 'Mobile') !== false) {
        $originateRequest  = "Action: Originate\r\n";
        $originateRequest .= "Channel: Local/" . $mobileNumber . "@from-external\r\n";
        $originateRequest .= "Exten: " . $phoneNumber . "\r\n";
        $originateRequest .= "Context: " . $externalContext . "\r\n";
        $originateRequest .= "Callerid: Click2Call <" . $phoneNumber . ">\r\n";
        $originateRequest .= "Priority: 1\r\n";
        $originateRequest .= "Async: true\r\n\r\n";
    } else {
        $originateRequest  = "Action: Originate\r\n";
        $originateRequest .= "Channel: SIP/" . $internalPhone . "@" . $internalPhone . "\r\n";
        $originateRequest .= "Callerid: # " . $contactName . "\r\n";
        $originateRequest .= "Exten: " . $phoneNumber . "\r\n";
        $originateRequest .= "Context: " . $externalContext . "\r\n";
        $originateRequest .= "Priority: 1\r\n";
        $originateRequest .= "Async: true\r\n\r\n";
    }

    // Socket-Verbindung zum AMI herstellen
    $socket = @stream_socket_client("tcp://$ip:$port", $errno, $errstr, 5);
    if (!$socket) {
        throw new ApiError('AMI_CONNECTION_FAILED', "Verbindung zu Asterisk fehlgeschlagen: $errstr ($errno)");
    }

    // Authentifizierung
    $authRequest  = "Action: Login\r\n";
    $authRequest .= "Username: $username\r\n";
    $authRequest .= "Secret: $passwd\r\n";
    $authRequest .= "Events: off\r\n\r\n";

    stream_socket_sendto($socket, $authRequest);
    usleep(200000);
    $authResponse = fread($socket, 4096);

    if (strpos($authResponse, 'Success') === false) {
        fclose($socket);
        throw new ApiError('AMI_AUTH_FAILED', 'Authentifizierung am Asterisk Manager Interface fehlgeschlagen');
    }

    // Anruf initiieren
    $sent = stream_socket_sendto($socket, $originateRequest);
    usleep(200000);
    $originateResponse = fread($socket, 4096);
    fclose($socket);

    if ($sent <= 0 || strpos($originateResponse, 'Success') === false) {
        throw new ApiError('AMI_ORIGINATE_FAILED', 'Anruf konnte nicht gestartet werden');
    }

    // debugLog("Click-to-Call: $internalPhone -> $phoneNumber ($contactName) via $externalContext");
    resultInfo(true, '', ['message' => 'Anruf wird aufgebaut']);
}

/**
 * Gibt die verfuegbaren Telefone und Kontexte fuer Click-to-Call zurueck.
 * Laedt die globalen Asterisk-Einstellungen und benutzerspezifische Standardwerte
 * in einer einzigen DB-Abfrage.
 *
 * @param int $data['employee_id'] ID des Mitarbeiters (aus dem Store)
 * @testdata {"action": "getPhoneConfig", "employee_id": 1}
 */
function getPhoneConfig($data) {
    $mandant = DbhCompany::begin();
    $employeeId = intval($data['employee_id'] ?? 0);

    $query = <<<SQL
        SELECT json_build_object(
            'external_contexts',    (SELECT value FROM defaults_oserp WHERE key = 'external_contexts'),
            'internal_phones',      (SELECT value FROM defaults_oserp WHERE key = 'internal_phones'),
            'user_external_context',(SELECT value FROM defaults_oserp WHERE key = :user_context_key),
            'user_internal_phone',  (SELECT value FROM defaults_oserp WHERE key = :user_phone_key)
        ) AS config
    SQL;

    $result = $mandant->getOne($query, [
        ':user_context_key' => 'employee_' . $employeeId . '_external_context',
        ':user_phone_key' => 'employee_' . $employeeId . '_internal_phone'
    ]);

    resultInfo(true, '', json_decode($result['config'], true));
}

/**
 * Speichert die benutzerspezifischen Click-to-Call Einstellungen
 * (externer Kontext und internes Telefon) in defaults_oserp.
 *
 * @param int $data['employee_id'] ID des Mitarbeiters (aus dem Store)
 * @param string $data['user_external_context'] Gewaehlter externer Kontext
 * @param string $data['user_internal_phone'] Gewaehltes internes Telefon
 * @testdata {"action": "savePhoneConfig", "employee_id": 1, "user_external_context": "Autoprofis", "user_internal_phone": "2000"}
 */
function savePhoneConfig($data) {
    $mandant = DbhCompany::begin();
    $employeeId = intval($data['employee_id'] ?? 0);

    if ($employeeId === 0) {
        throw new ApiError('MISSING_EMPLOYEE_ID', 'employee_id fehlt');
    }

    $query = <<<SQL
        INSERT INTO defaults_oserp (key, value)
        VALUES
            (:context_key, :context_value),
            (:phone_key, :phone_value)
        ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()
    SQL;

    $mandant->execute($query, [
        ':context_key' => 'employee_' . $employeeId . '_external_context',
        ':context_value' => trim($data['user_external_context'] ?? ''),
        ':phone_key' => 'employee_' . $employeeId . '_internal_phone',
        ':phone_value' => trim($data['user_internal_phone'] ?? '')
    ]);

    resultInfo(true);
}

/**
 * Duplikat-Pruefung fuer Kunden/Lieferanten via pg_trgm-Aehnlichkeit
 *
 * Treffer-Bedingungen (alle muessen erfuellt sein):
 *   - similarity(name)   > 0.7
 *   - zipcode            exakt gleich
 *   - similarity(street) > 0.9
 *
 * Geprueft wird in der Tabelle, die zu src passt (customer/vendor).
 * Treffer landen in `exact`; `partial` bleibt aus Kompatibilitaet leer.
 *
 * @param string $data['name']     Name
 * @param string $data['street']   Strasse
 * @param string $data['zipcode']  PLZ
 * @param int    $data['exclude_id'] Eigene ID (zum Ausschliessen)
 * @param string $data['src']      'C' oder 'V'
 * @testdata {"action": "checkDuplicateCV", "name": "Mustermann GmbH", "street": "Hauptstr. 1", "zipcode": "10115", "src": "C"}
 */
function checkDuplicateCV($data) {
    $db = DbhCompany::begin();
    $name = trim($data['name'] ?? '');
    $street = trim($data['street'] ?? '');
    $zipcode = trim($data['zipcode'] ?? '');
    $excludeId = intval($data['exclude_id'] ?? 0);
    $src = ($data['src'] ?? 'C') === 'V' ? 'V' : 'C';

    if ($name === '' || $street === '' || $zipcode === '') {
        resultInfo(true, 'OK', ['exact' => [], 'partial' => []]);
        return;
    }

    $table = ($src === 'V') ? 'vendor' : 'customer';

    $rows = $db->getAll(
        "SELECT id, name, street, zipcode, city, '$src' AS src,
                similarity(LOWER(name), LOWER(:name))     AS name_sim,
                similarity(LOWER(street), LOWER(:street)) AS street_sim
         FROM $table
         WHERE LOWER(zipcode) = LOWER(:zipcode)
           AND similarity(LOWER(name), LOWER(:name2))     > 0.7
           AND similarity(LOWER(street), LOWER(:street2)) > 0.9
           AND id != :exclude
         ORDER BY name_sim DESC, street_sim DESC
         LIMIT 5",
        [
            ':name'     => $name,
            ':street'   => $street,
            ':zipcode'  => $zipcode,
            ':name2'    => $name,
            ':street2'  => $street,
            ':exclude'  => $excludeId,
        ]
    ) ?: [];

    resultInfo(true, 'OK', ['exact' => $rows, 'partial' => []]);
}

/**
 * Ermittelt eine Kunden-/Lieferanten-ID aus der Such-History des Mitarbeiters
 *
 * @param object $db DbhCompany-Instanz
 * @param string $cvSrc 'C' für Kunde, 'V' für Lieferant
 * @return int|null CV-ID oder null
 */
function _getCvIdFromViewHistory($db, $cvSrc) {
    $auth = DbhAuth::begin();
    $login = $auth->getLogin();
    if (!$login) return null;

    $row = $db->getOne(
        "SELECT value FROM employee_config_oserp
         WHERE employee_id = (SELECT id FROM employee WHERE login = :login)
         AND key = 'view-history'",
        [':login' => $login]
    );

    if (!$row || !$row['value']) return null;

    $history = json_decode($row['value'], true);
    if (!is_array($history)) return null;

    $type = ($cvSrc === 'V') ? 'vendor' : 'customer';
    $cvTable = ($cvSrc === 'V') ? 'vendor' : 'customer';

    foreach ($history as $entry) {
        if (($entry['type'] ?? '') === $type && !empty($entry['id'])) {
            // Prüfen ob der Eintrag in diesem Mandanten noch existiert
            $exists = $db->getOne(
                "SELECT id FROM $cvTable WHERE id = :id",
                [':id' => (int)$entry['id']]
            );
            if ($exists) return (int)$entry['id'];
        }
    }

    return null;
}
