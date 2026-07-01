<?php
// backend/api/faktura/faktura.php

/**
 * Lädt Faktura-Daten (Rechnung, Auftrag, Angebot, Lieferschein)
 *
 * @param array $data Request-Daten mit fakturaID und fakturaType
 * @return void Gibt JSON zurück
 * @testdata {"fakturaID": 1, "fakturaType": "invoice"}
 */
function getFakturaData($data) {
    include_once __DIR__ . '/../features.php';

    $fakturaID = intval($data['fakturaID']);
    $fakturaType = $data['fakturaType'] ?? 'invoice';

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType($fakturaType));

    // Tabellennamen abhängig vom Dokumenttyp
    $tableConfig = getFakturaTableConfig($fakturaType);
    $mainTable = $tableConfig['main_table'];
    $itemsTable = $tableConfig['items_table'];
    $cvTable = $tableConfig['cv_table'];
    $cvColumn = $tableConfig['cv_column'];

    // Payment chart_link: AR_paid für Verkauf, AP_paid für Einkauf
    $paymentLink = ($mainTable === 'ap') ? '%AP_paid%' : '%AR_paid%';

    // Ext-Tabelle für phone_numbers (customer_ext/vendor_ext)
    $extTable = ($cvTable === 'vendor') ? 'vendor_ext' : 'customer_ext';
    $extFk    = ($cvTable === 'vendor') ? 'vendor_id'  : 'customer_id';

    // Billing addresses nur für Kunden verfügbar
    $billingAddressesSql = ($cvTable === 'customer')
        ? "(SELECT json_agg(billing_addr) FROM (SELECT addr.* FROM {$mainTable} JOIN additional_billing_addresses addr ON {$mainTable}.customer_id = addr.customer_id WHERE {$mainTable}.id = :fakturaID ORDER BY addr.id ASC) AS billing_addr)"
        : "NULL";

    $query = <<<SQL
        SELECT json_build_object(
            'success', true,
            'payload', json_build_object(
            'common',
                (
                    SELECT row_to_json(common)
                    FROM (
                        SELECT *
                        FROM {$mainTable}
                        WHERE {$mainTable}.id = :fakturaID
                    ) AS common
                ),
            'customer',
                (
                    SELECT row_to_json(common)
                    FROM (
                        SELECT
                            {$cvTable}.*,
                            (SELECT phone_numbers FROM {$extTable} WHERE {$extFk} = {$cvTable}.id) AS phone_numbers
                        FROM {$mainTable}
                        INNER JOIN {$cvTable} ON {$cvTable}.id = {$mainTable}.{$cvColumn}
                        WHERE {$mainTable}.id = :fakturaID
                    ) AS common
                ),
            'payment',
                (
                    SELECT json_agg(payment ORDER BY acc_trans_id DESC)
                    FROM (
                        SELECT
                            at.acc_trans_id,
                            at.chart_id,
                            at.amount,
                            at.source,
                            at.memo,
                            at.transdate,
                            -- Zugehörige, noch GEBUCHTE Bankzuordnung ermitteln
                            -- (stabil über das Mapping, für Alt-Buchungen per Memo).
                            -- bank_transaction_id != null => Zahlung ist bank-gebucht:
                            -- im Faktura-Editor gesperrt (Storno nur über das Bankmodul);
                            -- bank_account_id dient dem Sprung ins Bankmodul.
                            (btx.id IS NOT NULL) AS bank_booked,
                            btx.id AS bank_transaction_id,
                            btx.local_bank_account_id AS bank_account_id
                        FROM acc_trans at
                        LEFT JOIN LATERAL (
                            SELECT bt.id, bt.local_bank_account_id
                            FROM bank_transactions bt
                            WHERE bt.match_status = 'booked'
                                AND (
                                    EXISTS (
                                        SELECT 1 FROM bank_transaction_acc_trans bat
                                        WHERE bat.bank_transaction_id = bt.id
                                            AND bat.acc_trans_id = at.acc_trans_id
                                    )
                                    OR at.memo LIKE '%Umsatz #' || bt.id
                                )
                            LIMIT 1
                        ) btx ON true
                        WHERE at.trans_id = :fakturaID
                            AND at.chart_link LIKE '{$paymentLink}'
                    ) AS payment
                ),
            'positions',
                (
                    SELECT json_agg({$itemsTable} ORDER BY position ASC)
                    FROM (
                        SELECT
                            {$itemsTable}.*,
                            parts.partnumber,
                            parts.part_type,
                            parts.classification_id,
                            {$itemsTable}.longdescription,
                            (
                                SELECT row_to_json(buchungsziel)
                                FROM (
                                    SELECT
                                        c2.id AS income_chart_id,
                                        tk.tax_id,
                                        tx.chart_id AS tax_chart_id,
                                        tx.rate
                                    FROM parts p
                                    LEFT JOIN buchungsgruppen bg ON p.buchungsgruppen_id = bg.id
                                    LEFT JOIN taxzone_charts tc ON bg.id = tc.buchungsgruppen_id
                                    LEFT JOIN chart c1 ON bg.inventory_accno_id = c1.id
                                    LEFT JOIN chart c2 ON tc.income_accno_id = c2.id
                                    LEFT JOIN chart c3 ON tc.expense_accno_id = c3.id
                                    LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
                                    LEFT JOIN tax tx ON tx.id = tk.tax_id
                                    WHERE tc.taxzone_id = (SELECT taxzone_id FROM {$mainTable} WHERE id = :fakturaID)
                                        AND p.id = parts.id
                                    ORDER BY tk.startdate DESC
                                    LIMIT 1
                                ) AS buchungsziel
                            ) AS buchungsziel
                        FROM {$itemsTable}
                        LEFT JOIN parts ON {$itemsTable}.parts_id = parts.id
                        WHERE trans_id = :fakturaID
                    ) AS {$itemsTable}
                ),
            'shiptos',
                (
                    SELECT json_agg(shiptos)
                    FROM (
                        SELECT shipto.* FROM {$mainTable} JOIN shipto ON {$mainTable}.{$cvColumn} = shipto.trans_id WHERE {$mainTable}.id = :fakturaID AND module = 'CT' ORDER BY shipto.shipto_id ASC
                    ) AS shiptos
                ),
            'billing_addresses',
                {$billingAddressesSql},
            'customers',
                (
                    SELECT json_agg(json_build_object('id', cv.id, 'name', cv.name))
                    FROM {$cvTable} cv
                    JOIN {$mainTable} mt ON mt.{$cvColumn} = cv.id AND mt.id = :fakturaID
                )
            )
        ) AS result
SQL;

    //debugQuery($query, ['fakturaID' => $fakturaID], 'Faktura Daten laden');

     echo $company->getOne($query, ['fakturaID' => $fakturaID])['result'];
}

/**
 * Sucht Kunden oder Lieferanten per Name fuer das Faktura-Autocomplete (max 10 Treffer)
 *
 * @param string $data['search'] Suchbegriff (min 3 Zeichen)
 * @param string $data['type'] 'customer' oder 'vendor'
 * @testdata {"search": "Test", "type": "customer"}
 */
/**
 * Sucht Kunden und Lieferanten mit E-Mail für den E-Mail-Dialog.
 * Gibt primäre E-Mail (customer.email) + weitere Adressen aus customer_ext.emails zurück.
 * Mehrere Adressen können im Frontend automatisch als CC gesetzt werden.
 *
 * @param type $data['search'] Suchbegriff (mind. 3 Zeichen)
 * @testdata {"search": "Muster"}
 */
function searchCvEmails($data) {
    $search = trim($data['search'] ?? '');
    if (strlen($search) < 3) {
        echo resultInfo(true, '', ['results' => []]);
        return;
    }
    $db = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object('results', COALESCE(
            (
                SELECT json_agg(t ORDER BY t.name)
                FROM (
                    (
                        SELECT c.id, c.name, 'customer' AS cv_type,
                               NULLIF(TRIM(c.email), '') AS email,
                               COALESCE(
                                   (SELECT json_agg(e)
                                    FROM jsonb_array_elements_text(ce.emails) AS e
                                    WHERE NULLIF(TRIM(e), '') IS NOT NULL
                                      AND e IS DISTINCT FROM NULLIF(TRIM(c.email), '')),
                                   '[]'::json
                               ) AS extra_emails
                        FROM customer c
                        LEFT JOIN customer_ext ce ON ce.customer_id = c.id
                        WHERE (c.name ILIKE '%' || :s1 || '%' OR c.email ILIKE '%' || :s2 || '%')
                          AND (NULLIF(TRIM(c.email), '') IS NOT NULL
                               OR (ce.emails IS NOT NULL AND jsonb_array_length(ce.emails) > 0))
                        ORDER BY c.name LIMIT 10
                    )
                    UNION ALL
                    (
                        SELECT v.id, v.name, 'vendor' AS cv_type,
                               NULLIF(TRIM(v.email), '') AS email,
                               COALESCE(
                                   (SELECT json_agg(e)
                                    FROM jsonb_array_elements_text(ve.emails) AS e
                                    WHERE NULLIF(TRIM(e), '') IS NOT NULL
                                      AND e IS DISTINCT FROM NULLIF(TRIM(v.email), '')),
                                   '[]'::json
                               ) AS extra_emails
                        FROM vendor v
                        LEFT JOIN vendor_ext ve ON ve.vendor_id = v.id
                        WHERE (v.name ILIKE '%' || :s3 || '%' OR v.email ILIKE '%' || :s4 || '%')
                          AND (NULLIF(TRIM(v.email), '') IS NOT NULL
                               OR (ve.emails IS NOT NULL AND jsonb_array_length(ve.emails) > 0))
                        ORDER BY v.name LIMIT 10
                    )
                ) t
                LIMIT 15
            ),
            '[]'::json
        )) AS result
    SQL;

    echo $db->getOne($query, [
        's1' => $search, 's2' => $search,
        's3' => $search, 's4' => $search,
    ])['result'];
}

function searchFakturaCustomers($data) {
    $search = trim($data['search'] ?? '');
    $type = $data['type'] ?? 'customer';

    if (strlen($search) < 3) {
        echo resultInfo(true, '', ['results' => []]);
        return;
    }

    $table = $type === 'vendor' ? 'vendor' : 'customer';
    $company = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'results', COALESCE(
                (
                    SELECT json_agg(row_to_json(t))
                    FROM (
                        SELECT c.id, c.name,
                               COALESCE(NULLIF(c.phone, ''), ext.phone_numbers->>0) AS phone,
                               ext.emails->>0 AS email
                        FROM {$table} c
                        LEFT JOIN {$table}_ext ext ON ext.{$table}_id = c.id
                        WHERE c.name ILIKE '%' || :search || '%'
                        ORDER BY c.name
                        LIMIT 10
                    ) t
                ),
                '[]'::json
            )
        ) AS result
SQL;

    echo $company->getOne($query, ['search' => $search])['result'];
}

/**
 * Gibt die Tabellenkonfiguration für den jeweiligen Dokumenttyp zurück
 *
 * @param string $fakturaType Dokumenttyp (invoice, order, quotation, delivery_order)
 * @return array Konfiguration mit main_table und items_table
 */
function getFakturaTableConfig($fakturaType) {
    $configs = [
        'invoice' => [
            'main_table' => 'ar',
            'items_table' => 'invoice',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'purchase_invoice' => [
            'main_table' => 'ap',
            'items_table' => 'invoice',
            'cv_table' => 'vendor',
            'cv_column' => 'vendor_id'
        ],
        'order' => [
            'main_table' => 'oe',
            'items_table' => 'orderitems',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'purchase_order' => [
            'main_table' => 'oe',
            'items_table' => 'orderitems',
            'cv_table' => 'vendor',
            'cv_column' => 'vendor_id'
        ],
        'quotation' => [
            'main_table' => 'oe',
            'items_table' => 'orderitems',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'request_quotation' => [
            'main_table' => 'oe',
            'items_table' => 'orderitems',
            'cv_table' => 'vendor',
            'cv_column' => 'vendor_id'
        ],
        'delivery_order' => [
            'main_table' => 'delivery_orders',
            'items_table' => 'delivery_order_items',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'credit_note' => [
            'main_table' => 'ar',
            'items_table' => 'invoice',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'invoice_storno' => [
            'main_table' => 'ar',
            'items_table' => 'invoice',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ]
    ];

    return $configs[$fakturaType] ?? $configs['invoice'];
}

/**
 * Gibt die benötigte Berechtigung für einen Dokumenttyp zurück
 *
 * @param string $fakturaType Dokumenttyp
 * @return string Permission-Name
 */
function getPermissionForFakturaType($fakturaType) {
    $map = [
        'invoice' => 'invoice_edit',
        'purchase_invoice' => 'purchase_invoice_edit',
        'order' => 'sales_order_edit',
        'purchase_order' => 'purchase_order_edit',
        'quotation' => 'sales_quotation_edit',
        'request_quotation' => 'sales_quotation_edit',
        'delivery_order' => 'sales_delivery_order_edit',
        'credit_note' => 'invoice_edit',
        'invoice_storno' => 'invoice_edit',
    ];
    return $map[$fakturaType] ?? 'invoice_edit';
}

/**
 * Speichert Faktura-Daten
 *
 * @param array $data Request-Daten mit fakturaData und fakturaType
 * @return void Gibt JSON zurück
 */
function saveFakturaData($data) {
    // TODO: Implementierung für das Speichern von Faktura-Daten
    resultInfo(false, 'NOT_IMPLEMENTED', ['message' => 'Save faktura not yet implemented']);
}

/**
 * Erstellt ein neues leeres Faktura-Dokument (Auftrag, Angebot oder Rechnung)
 *
 * @param array $data Request-Daten mit fakturaType ('order', 'quotation', 'invoice')
 * @return void Gibt JSON mit der neuen Dokument-ID zurück
 */
function createFaktura($data) {
    $fakturaType = $data['fakturaType'] ?? 'order';
    $cvId = !empty($data['cvId']) ? intval($data['cvId']) : null;
    $cvSrc = $data['cvSrc'] ?? null; // 'C' = Kunde, 'V' = Lieferant
    $isVendor = ($cvSrc === 'V');
    $company = DbhCompany::begin();

    $effectiveType = resolveEffectiveFakturaType($fakturaType, $isVendor);
    permit(getPermissionForFakturaType($effectiveType));

    // Employee-ID aus Session-Login ermitteln
    $auth = DbhAuth::begin();
    $login = $auth->getLogin();
    $employee = $company->getOne(
        "SELECT id FROM employee WHERE login = :login",
        ['login' => $login]
    );
    $employeeId = $employee ? intval($employee['id']) : null;

    $res = createFakturaCore($company, $fakturaType, $cvId, $cvSrc, $employeeId);
    resultInfo(true, 'CREATED', $res);
}

/**
 * Verkaufs- → Einkaufs-Dokumenttyp (Kivitendo-Logik). Für Lieferanten ($isVendor)
 * wird der Einkaufstyp gemappt, sonst bleibt der Typ unverändert.
 */
function resolveEffectiveFakturaType($fakturaType, $isVendor) {
    if (!$isVendor) {
        return $fakturaType;
    }
    $typeMap = [
        'order' => 'purchase_order',
        'quotation' => 'request_quotation',
        'invoice' => 'purchase_invoice'
    ];
    return $typeMap[$fakturaType] ?? $fakturaType;
}

/**
 * Kern der Faktura-Erstellung – session-unabhängig (Mitarbeiter als Parameter).
 * Wird von createFaktura (HTTP) und vom eBay-Import (CLI) gemeinsam genutzt, damit
 * Nummernkreis- und Buchungslogik nur an einer Stelle existieren.
 *
 * @param object      $company     DbhCompany-Handle
 * @param string      $fakturaType  'invoice'|'order'|'quotation' (Verkaufssicht)
 * @param int|null    $cvId         Kunden-/Lieferanten-ID
 * @param string|null $cvSrc        'C' = Kunde, 'V' = Lieferant
 * @param int|null    $employeeId   Mitarbeiter-ID
 * @param array       $opts         Optionale Overrides: ['taxincluded' => bool]
 * @return array ['id'=>int, 'fakturaType'=>string, 'docNumber'=>mixed]
 */
function createFakturaCore($company, $fakturaType, $cvId, $cvSrc, $employeeId, $opts = []) {
    $isVendor = ($cvSrc === 'V');
    $effectiveType = resolveEffectiveFakturaType($fakturaType, $isVendor);

    // Defaults laden (currency_id)
    $defaults = $company->getOne("SELECT currency_id FROM defaults LIMIT 1");
    $currencyId = intval($defaults['currency_id']);

    // Steuerzone + taxincluded: aus Kunden-/Lieferantenstamm oder erste verfügbare
    $taxzoneId = null;
    $taxincluded = false;
    if ($cvId) {
        $cvTable = $isVendor ? 'vendor' : 'customer';
        $cvData = $company->getOne(
            "SELECT taxzone_id, taxincluded_checked FROM {$cvTable} WHERE id = :id",
            ['id' => $cvId]
        );
        $taxzoneId = $cvData ? intval($cvData['taxzone_id']) : null;
        $taxincluded = !empty($cvData['taxincluded_checked']);
    }
    if (!$taxzoneId) {
        $taxzone = $company->getOne("SELECT id FROM tax_zones ORDER BY id LIMIT 1");
        $taxzoneId = $taxzone ? intval($taxzone['id']) : 4;
    }

    // Optionaler Override (z. B. eBay-Import: Preise sind brutto → taxincluded = true)
    if (array_key_exists('taxincluded', $opts)) {
        $taxincluded = (bool)$opts['taxincluded'];
    }

    // customer_id / vendor_id zuweisen
    $customerId = !$isVendor ? $cvId : null;
    $vendorId = $isVendor ? $cvId : null;

    // Dokumentnummer-Zähler und INSERT in einem atomaren CTE-Statement:
    //   WITH tmp AS (UPDATE defaults SET <col> = <col>::INT + 1 RETURNING <col>)
    //   INSERT INTO <table> (..., <numberfield>, ...) SELECT ..., (SELECT <col> FROM tmp), ...
    //   RETURNING id
    //
    // Mapping effectiveType → defaults-Spalte / Nummernfeld in oe:
    //   order            → sonumber  / ordnumber
    //   purchase_order   → ponumber  / ordnumber
    //   quotation        → sqnumber  / quonumber  (ordnumber bleibt '' – NOT NULL in oe)
    //   request_quotation→ rfqnumber / quonumber
    //   invoice          → invnumber / ar.invnumber
    //   purchase_invoice → invnumber / ap.invnumber

    // Nummernkreis-Spalte je nach Dokumenttyp bestimmen
    $defaultsColMap = [
        'purchase_invoice'   => 'invnumber',
        'invoice'            => 'invnumber',
        'quotation'          => 'sqnumber',
        'request_quotation'  => 'rfqnumber',
        'purchase_order'     => 'ponumber',
        'order'              => 'sonumber',
        'sales_order'        => 'sonumber',
    ];
    $nkCol = $defaultsColMap[$effectiveType] ?? 'sonumber';
    $nkBefore = $company->getOne("SELECT {$nkCol} FROM defaults LIMIT 1");
    // writeLog("createFaktura VORHER: defaults.{$nkCol} = " . ($nkBefore[$nkCol] ?? 'NULL') . " (effectiveType={$effectiveType})");

    if ($effectiveType === 'purchase_invoice') {
        // Eingangsrechnung → ap-Tabelle
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ap (invnumber, transdate, gldate, employee_id, vendor_id, taxzone_id, currency_id, invoice, taxincluded)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :vendor_id, :taxzone_id, :currency_id, true, :taxincluded
            RETURNING id, invnumber
SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':vendor_id'    => $vendorId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId,
            ':taxincluded'  => $taxincluded ? 'true' : 'false'
        ]);

    } elseif ($effectiveType === 'invoice') {
        // Ausgangsrechnung → ar-Tabelle
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice, taxincluded)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true, :taxincluded
            RETURNING id, invnumber
SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':customer_id'  => $customerId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId,
            ':taxincluded'  => $taxincluded ? 'true' : 'false'
        ]);

    } elseif ($effectiveType === 'quotation' || $effectiveType === 'request_quotation') {
        // Angebot / Anfrage → oe-Tabelle, Nummer in quonumber; ordnumber = '' (NOT NULL)
        $defaultsCol = ($effectiveType === 'quotation') ? 'sqnumber' : 'rfqnumber';
        $recordType  = ($effectiveType === 'quotation') ? 'sales_quotation' : 'request_quotation';

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO oe (ordnumber, quonumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, record_type, taxincluded)
            SELECT '', (SELECT {$defaultsCol} FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type, :taxincluded
            RETURNING id, quonumber
SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':customer_id'  => $customerId,
            ':vendor_id'    => $vendorId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId,
            ':record_type'  => $recordType,
            ':taxincluded'  => $taxincluded ? 'true' : 'false'
        ]);

    } else {
        // Auftrag (Verkauf oder Einkauf) → oe-Tabelle, Nummer in ordnumber
        // Neue Verkaufsaufträge starten als Auftragseingang (unbestätigt) und werden
        // über den "Bestätigt"-Schalter (setOrderConfirmed) zu sales_order.
        $defaultsCol = ($effectiveType === 'purchase_order') ? 'ponumber' : 'sonumber';
        $recordType  = ($effectiveType === 'purchase_order') ? 'purchase_order' : 'sales_order_intake';

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO oe (ordnumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, record_type, taxincluded)
            SELECT (SELECT {$defaultsCol} FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type, :taxincluded
            RETURNING id, ordnumber
SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':customer_id'  => $customerId,
            ':vendor_id'    => $vendorId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId,
            ':record_type'  => $recordType,
            ':taxincluded'  => $taxincluded ? 'true' : 'false'
        ]);
    }

    $nkAfter = $company->getOne("SELECT {$nkCol} FROM defaults LIMIT 1");
    // writeLog("createFaktura NACHHER: defaults.{$nkCol} = " . ($nkAfter[$nkCol] ?? 'NULL') . " (effectiveType={$effectiveType})");

    // Dokumentnummer aus dem RETURNING-Ergebnis extrahieren
    $docNumber = $result['invnumber'] ?? $result['ordnumber'] ?? $result['quonumber'] ?? null;

    return [
        'id' => intval($result['id']),
        'fakturaType' => $effectiveType,
        'docNumber' => $docNumber
    ];
}

/**
 * Fügt eine neue Faktura-Position ein
 *
 * @param array $data Request-Daten mit item-Daten und fakturaType
 * @return void Gibt JSON mit der neuen Position zurück
 */
function createFakturaItem($data) {
    $fakturaID = intval($data['fakturaID']);
    $fakturaType = $data['fakturaType'] ?? 'invoice';
    $item = $data['item'];

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType($fakturaType));

    $newId = createFakturaItemCore($company, $fakturaType, $fakturaID, $item);

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];
    $mainTable = $tableConfig['main_table'];

    // Komplette Position mit allen Daten zurückgeben (wie in getFakturaData)
    $selectQuery = <<<SQL
        SELECT json_build_object(
            'success', true,
            'payload', (
                SELECT row_to_json(item)
                FROM (
                    SELECT
                        {$itemsTable}.*,
                        parts.partnumber,
                        parts.part_type,
                        parts.classification_id,
                        (
                            SELECT row_to_json(buchungsziel)
                            FROM (
                                SELECT
                                    c2.id AS income_chart_id,
                                    tk.tax_id,
                                    tx.chart_id AS tax_chart_id,
                                    tx.rate
                                FROM parts p
                                LEFT JOIN buchungsgruppen bg ON p.buchungsgruppen_id = bg.id
                                LEFT JOIN taxzone_charts tc ON bg.id = tc.buchungsgruppen_id
                                LEFT JOIN chart c2 ON tc.income_accno_id = c2.id
                                LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
                                LEFT JOIN tax tx ON tx.id = tk.tax_id
                                WHERE tc.taxzone_id = (SELECT taxzone_id FROM {$mainTable} WHERE id = {$itemsTable}.trans_id)
                                    AND p.id = parts.id
                                ORDER BY tk.startdate DESC
                                LIMIT 1
                            ) AS buchungsziel
                        ) AS buchungsziel
                    FROM {$itemsTable}
                    LEFT JOIN parts ON {$itemsTable}.parts_id = parts.id
                    WHERE {$itemsTable}.id = :itemId
                ) AS item
            )
        ) AS result
SQL;

    echo $company->getOne($selectQuery, ['itemId' => $newId])['result'];
}

/**
 * Kern: fügt eine Faktura-Position ein und liefert die neue Positions-ID zurück.
 * Session-unabhängig; von createFakturaItem (HTTP) und vom eBay-Import (CLI) genutzt.
 *
 * @param object $company     DbhCompany-Handle
 * @param string $fakturaType  Dokumenttyp (z. B. 'invoice')
 * @param int    $fakturaID    trans_id des Belegs
 * @param array  $item         ['parts_id','description','longdescription','qty','sellprice','discount','unit']
 * @return int   Neue Positions-ID
 */
function createFakturaItemCore($company, $fakturaType, $fakturaID, $item) {
    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];

    // Nächste Position ermitteln
    $positionQuery = "SELECT COALESCE(MAX(position), 0) + 1 AS next_position FROM {$itemsTable} WHERE trans_id = :fakturaID";
    $nextPosition = $company->getOne($positionQuery, ['fakturaID' => $fakturaID])['next_position'];

    // Basis-Spalten für alle Tabellentypen
    $columns = [
        'trans_id',
        'parts_id',
        'position',
        'description',
        'longdescription',
        'qty',
        'sellprice',
        'discount',
        'unit'
    ];

    $values = [
        ':trans_id',
        ':parts_id',
        ':position',
        ':description',
        ':longdescription',
        ':qty',
        ':sellprice',
        ':discount',
        // Leere Einheit -> Einheit des Artikels (parts.unit), sonst NULL.
        // Verhindert FK-Verletzung (orderitems_unit_fkey/invoice_unit_fkey),
        // wenn das Frontend keine Einheit mitsendet (z. B. bei Dienstleistungen).
        "COALESCE(NULLIF(:unit, ''), (SELECT unit FROM parts WHERE id = :unit_parts_id))"
    ];

    $params = [
        'trans_id' => $fakturaID,
        'parts_id' => intval($item['parts_id']),
        'position' => $nextPosition,
        'description' => $item['description'] ?? '',
        'longdescription' => $item['longdescription'] ?? '',
        'qty' => floatval($item['qty'] ?? 1),
        'sellprice' => floatval($item['sellprice'] ?? 0),
        'discount' => floatval($item['discount'] ?? 0),
        'unit' => $item['unit'] ?? '',
        'unit_parts_id' => intval($item['parts_id'])
    ];

    // Zusätzliche Spalten nur für Rechnungen (invoice Tabelle)
    if ($fakturaType === 'invoice') {
        $columns[] = 'fxsellprice';
        $values[] = ':fxsellprice';
        $params['fxsellprice'] = floatval($item['sellprice'] ?? 0);
    }

    $columnList = implode(', ', $columns);
    $valueList = implode(', ', $values);

    // Insert mit RETURNING für die generierte ID
    $query = "INSERT INTO {$itemsTable} ({$columnList}) VALUES ({$valueList}) RETURNING id";

    $result = $company->getOne($query, $params);
    return $result['id'];
}

/**
 * Löscht eine Faktura-Position
 *
 * @param array $data Request-Daten mit itemID und fakturaType
 * @return void Gibt JSON zurück
 */
function deleteFakturaItem($data) {
    $itemID = intval($data['itemID']);
    $fakturaType = $data['fakturaType'] ?? 'invoice';

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];

    // trans_id ermitteln für Positionsneuberechnung
    $transIdQuery = "SELECT trans_id FROM {$itemsTable} WHERE id = :itemID";
    $transId = $company->getOne($transIdQuery, ['itemID' => $itemID])['trans_id'];

    if (!$transId) {
        resultInfo(false, 'ITEM_NOT_FOUND', ['message' => 'Position nicht gefunden']);
        return;
    }

    // Position löschen
    $deleteQuery = "DELETE FROM {$itemsTable} WHERE id = :itemID";
    $company->execute($deleteQuery, ['itemID' => $itemID]);

    // Positionen neu nummerieren
    $renumberQuery = <<<SQL
        UPDATE {$itemsTable}
        SET position = subquery.new_position
        FROM (
            SELECT id, ROW_NUMBER() OVER (ORDER BY position) AS new_position
            FROM {$itemsTable}
            WHERE trans_id = :transId
        ) AS subquery
        WHERE {$itemsTable}.id = subquery.id
SQL;

    $company->execute($renumberQuery, ['transId' => $transId]);

    resultInfo(true, 'DELETED', ['message' => 'Position gelöscht']);
}

/**
 * Ersetzt den Artikel EINER bestehenden Position (parts_id + zugehörige Felder).
 * Bewusst isoliertes Einzel-Update per id – kann konstruktionsbedingt KEINE
 * andere Position beeinflussen (anders als das Bulk-Update, das parts_id
 * absichtlich nicht anfasst).
 *
 * @param int    $data['item_id']     ID der Position
 * @param int    $data['parts_id']    Neue Artikel-ID
 * @param string $data['description'] Beschreibung
 * @param float  $data['qty']         Menge
 * @param float  $data['sellprice']   Verkaufspreis
 * @param string $data['unit']        Einheit
 * @param string $data['fakturaType'] order|invoice|quotation...
 * @testdata {"item_id": 1, "parts_id": 1, "description": "Test", "qty": 1, "sellprice": 0, "unit": "Stk", "fakturaType": "order"}
 */
function replaceFakturaItemArticle($data) {
    $fakturaType = $data['fakturaType'] ?? 'order';
    $itemId  = intval($data['item_id'] ?? 0);
    $partsId = intval($data['parts_id'] ?? 0);
    if ($itemId <= 0 || $partsId <= 0) {
        resultInfo(false, 'INVALID_ARGS', 'item_id und parts_id erforderlich');
        return;
    }

    $company = DbhCompany::begin();
    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table']; // kontrollierter Tabellenname (kein User-Input)

    $company->execute(
        "UPDATE {$itemsTable}
            SET parts_id = :parts_id,
                description = :description,
                qty = :qty,
                sellprice = :sellprice,
                unit = COALESCE(NULLIF(:unit, ''), unit)
          WHERE id = :item_id",
        [
            ':parts_id'    => $partsId,
            ':description' => $data['description'] ?? '',
            ':qty'         => $data['qty'] ?? 1,
            ':sellprice'   => $data['sellprice'] ?? 0,
            ':unit'        => $data['unit'] ?? '',
            ':item_id'     => $itemId,
        ]
    );

    resultInfo(true, 'REPLACED');
}

/**
 * Aktualisiert mehrere Faktura-Positionen in einem Query (Bulk-Update)
 * und verarbeitet die Buchungen für acc_trans
 *
 * @param array $data Request-Daten mit items Array, fakturaType, accTransEntries, paymentEntries
 * @return void Gibt JSON zurück
 * @testdata {"fakturaID": 4, "fakturaType": "invoice", "items": [], "accTransEntries": [], "paymentEntries": [], "netAmount": 100, "grossAmount": 119}
 */
function updateFakturaItems($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $fakturaType = $data['fakturaType'] ?? 'invoice';
    $items = $data['items'] ?? [];
    $accTransEntries = $data['accTransEntries'] ?? [];
    $updatePayments = array_key_exists('paymentEntries', $data);
    $paymentEntries = $updatePayments ? ($data['paymentEntries'] ?? []) : [];
    $netAmount = floatval($data['netAmount'] ?? 0);
    $grossAmount = floatval($data['grossAmount'] ?? 0);

    if ($fakturaID <= 0) {
        resultInfo(false, 'INVALID_FAKTURA_ID', ['message' => 'Ungültige Faktura-ID']);
        return;
    }

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];
    $mainTable = $tableConfig['main_table'];
    $isInvoiceType = in_array($fakturaType, ['invoice', 'purchase_invoice']);
    $isOeType = in_array($fakturaType, ['order', 'purchase_order', 'quotation', 'request_quotation']);
    $paymentLink = ($mainTable === 'ap') ? '%AP_paid%' : '%AR_paid%';

    // 1. Items aktualisieren (Bulk-Update) falls vorhanden
    if (!empty($items)) {
        $columns = [
            'id' => 'integer',
            'position' => 'integer',
            'description' => 'text',
            'longdescription' => 'text',
            'qty' => 'numeric',
            'sellprice' => 'numeric',
            'discount' => 'numeric',
            'unit' => 'text'
        ];

        if ($isInvoiceType) {
            $columns['fxsellprice'] = 'numeric';
        }

        $company->updateAll($itemsTable, $columns, $items, 'id');
    }

    // 2a. Bei Aufträgen/Angeboten: Beträge in oe-Tabelle aktualisieren
    if ($isOeType) {
        $updateOeQuery = <<<SQL
            UPDATE oe SET
                netamount = :netAmount,
                amount = :grossAmount
            WHERE id = :fakturaID
SQL;
        $company->execute($updateOeQuery, [
            'fakturaID' => $fakturaID,
            'netAmount' => $netAmount,
            'grossAmount' => $grossAmount
        ]);
    }

    // Geschützte Bank-Buchungen ermitteln: acc_trans-Einträge, die zu einer noch
    // GEBUCHTEN Bankzuordnung gehören. Erkennung über das stabile Mapping
    // bank_transaction_acc_trans ODER (für Alt-Buchungen) über das Memo
    // '...Umsatz #<id>' — unabhängig von source (source trägt jetzt die Belegnummer).
    // Solche Einträge dürfen vom Faktura-Editor nicht gelöscht/verändert werden
    // (FK bank_transaction_acc_trans_acc_trans_id_fkey, Bankabstimmung).
    // Bereits stornierte/verwaiste Einträge sind hier NICHT enthalten und werden
    // dadurch im Editor wieder bereinigbar (löschen/editieren).
    $protectedBankIds = [];
    $protectExclusion = '';
    $protectParams = [];
    if ($isInvoiceType) {
        $protectedRows = $company->getAll(<<<SQL
            SELECT pa.acc_trans_id
            FROM acc_trans pa
            WHERE pa.trans_id = :fakturaID
                AND EXISTS (
                    SELECT 1 FROM bank_transactions bt
                    WHERE bt.match_status = 'booked'
                        AND (
                            EXISTS (
                                SELECT 1 FROM bank_transaction_acc_trans bat
                                WHERE bat.bank_transaction_id = bt.id
                                    AND bat.acc_trans_id = pa.acc_trans_id
                            )
                            OR pa.memo LIKE '%Umsatz #' || bt.id
                        )
                )
SQL, ['fakturaID' => $fakturaID]);
        $protectedBankIds = array_map(static fn($r) => intval($r['acc_trans_id']), $protectedRows);
        if (!empty($protectedBankIds)) {
            $ph = [];
            foreach ($protectedBankIds as $i => $pid) {
                $ph[] = ":pb{$i}";
                $protectParams["pb{$i}"] = $pid;
            }
            $protectExclusion = ' AND acc_trans_id NOT IN (' . implode(',', $ph) . ')';
        }
    }

    // 2b. Bei Rechnungen: Positions-Buchungen verarbeiten
    if ($isInvoiceType && !empty($accTransEntries)) {
        // Alte Buchungen löschen (NICHT die existierenden Zahlungen!).
        // Geschützte Bank-Buchungen (gebuchte Bankzuordnung) NICHT anfassen — deren
        // Forderungs-Gegenbuchung (chart_link='AR') würde sonst mitgelöscht und die
        // FK bank_transaction_acc_trans_acc_trans_id_fkey verletzen.
        $deleteQuery = "DELETE FROM acc_trans WHERE trans_id = :fakturaID AND chart_link NOT LIKE '{$paymentLink}'{$protectExclusion}";
        $company->execute($deleteQuery, array_merge(['fakturaID' => $fakturaID], $protectParams));

        // Neue Positions-Buchungen einfügen
        foreach ($accTransEntries as $entry) {
            $insertQuery = <<<SQL
                INSERT INTO acc_trans (
                    trans_id,
                    chart_id,
                    amount,
                    transdate,
                    gldate,
                    tax_id,
                    taxkey,
                    chart_link
                )
                SELECT
                    :trans_id,
                    :chart_id,
                    :amount,
                    :transdate,
                    :transdate,
                    :tax_id,
                    :taxkey,
                    chart.link
                FROM chart
                WHERE chart.id = :chart_id
SQL;
            $company->execute($insertQuery, [
                'trans_id' => $fakturaID,
                'chart_id' => intval($entry['chart_id']),
                'amount' => floatval($entry['amount']),
                'transdate' => $entry['transdate'],
                'tax_id' => intval($entry['tax_id'] ?? 0),
                'taxkey' => intval($entry['taxkey'] ?? 0)
            ]);
        }
    }

    // 2c. Zahlungsbuchungen nur aktualisieren wenn explizit vom Frontend angefordert
    if ($isInvoiceType && $updatePayments) {
        // Geschützte, noch gebuchte Bank-Zahlungen behandeln. Pro Zahlung kommen zwei
        // Beine an (Forderung +Betrag, Bank −Betrag), beide tragen die acc_trans_id des
        // Bank-Beins → dedupliziert über diese ID.
        //   - Standard (bank_edit aus): nur das editierbare Memo pflegen, Betrag/Konto/
        //     Quelle bleiben unangetastet → Bankabstimmung intakt.
        //   - bank_edit (Schloss im Editor geöffnet): den gebuchten Satz direkt
        //     bearbeiten. Beide acc_trans-Beine werden in-place aktualisiert (stabile
        //     acc_trans_id → FK bank_transaction_acc_trans bleibt gültig). Der Bankumsatz
        //     selbst wird NICHT verändert; Betrag/Konto können dadurch bewusst von der
        //     Bankabstimmung abweichen (vom Anwender so gewollt, Warnung im Frontend).
        $bankMemoUpdates = [];   // acc_trans_id (Bank-Bein) => memo
        $bankFullEdits   = [];   // acc_trans_id (Bank-Bein) => [amount, transdate, source, memo, bank_chart_id]
        foreach ($paymentEntries as $entry) {
            $accTransId = intval($entry['acc_trans_id'] ?? 0);
            if ($accTransId <= 0 || !in_array($accTransId, $protectedBankIds, true)) {
                continue;
            }
            $amount = floatval($entry['amount']);
            if (!empty($entry['bank_edit'])) {
                if (!isset($bankFullEdits[$accTransId])) {
                    $bankFullEdits[$accTransId] = [
                        'amount'        => abs($amount),
                        'transdate'     => $entry['transdate'],
                        'source'        => $entry['source'] ?? '',
                        'memo'          => $entry['memo'] ?? '',
                        'bank_chart_id' => null,
                    ];
                }
                // Das negative Bein liefert das Geldkonto (Bank) und den Betrag.
                if ($amount < 0) {
                    $bankFullEdits[$accTransId]['bank_chart_id'] = intval($entry['chart_id']);
                    $bankFullEdits[$accTransId]['amount']        = abs($amount);
                }
            } else {
                $bankMemoUpdates[$accTransId] = $entry['memo'] ?? '';
            }
        }

        foreach ($bankMemoUpdates as $accTransId => $memo) {
            $company->execute(<<<SQL
                UPDATE acc_trans
                SET memo = :memo
                WHERE acc_trans_id = :acc_trans_id
SQL, ['memo' => $memo, 'acc_trans_id' => $accTransId]);
        }

        foreach ($bankFullEdits as $bankLegId => $edit) {
            // Gegenbein (Forderung) über das stabile Mapping finden — selber Bankumsatz,
            // andere acc_trans_id.
            $counterRow = $company->getOne(<<<SQL
                SELECT acc_trans_id
                FROM bank_transaction_acc_trans
                WHERE bank_transaction_id = (
                        SELECT bank_transaction_id FROM bank_transaction_acc_trans
                        WHERE acc_trans_id = :bankLeg LIMIT 1
                    )
                  AND acc_trans_id <> :bankLeg
                LIMIT 1
SQL, ['bankLeg' => $bankLegId]);
            $counterId = $counterRow ? intval($counterRow['acc_trans_id']) : null;

            // Fallback für Alt-Buchungen ohne Mapping-Zeile: Gegenbein über das
            // (noch unveränderte) identische Memo derselben Rechnung finden.
            if (!$counterId) {
                $counterRow = $company->getOne(<<<SQL
                    SELECT acc_trans_id
                    FROM acc_trans
                    WHERE trans_id = :tid
                      AND acc_trans_id <> :bankLeg
                      AND chart_link NOT LIKE '{$paymentLink}'
                      AND memo = (SELECT memo FROM acc_trans WHERE acc_trans_id = :bankLeg2)
                    LIMIT 1
SQL, ['tid' => $fakturaID, 'bankLeg' => $bankLegId, 'bankLeg2' => $bankLegId]);
                $counterId = $counterRow ? intval($counterRow['acc_trans_id']) : null;
            }

            $amt = round((float)$edit['amount'], 2);

            // Bank-Bein (negativ). chart_link separat laden (vermeidet doppelte
            // Named-Parameter) und mit aktualisieren, falls das Geldkonto gewechselt wurde.
            if (!empty($edit['bank_chart_id'])) {
                $bankChartLink = $company->getOne(
                    "SELECT link FROM chart WHERE id = :id",
                    ['id' => $edit['bank_chart_id']]
                );
                $company->execute(<<<SQL
                    UPDATE acc_trans SET
                        amount     = :amount,
                        transdate  = :transdate,
                        gldate     = :transdate,
                        source     = :source,
                        memo       = :memo,
                        chart_id   = :chart_id,
                        chart_link = :chart_link
                    WHERE acc_trans_id = :acc_trans_id
SQL, [
                    'amount'       => -$amt,
                    'transdate'    => $edit['transdate'],
                    'source'       => (string)$edit['source'],
                    'memo'         => $edit['memo'],
                    'chart_id'     => $edit['bank_chart_id'],
                    'chart_link'   => $bankChartLink['link'] ?? 'AR_paid:AP_paid',
                    'acc_trans_id' => $bankLegId,
                ]);
            }

            // Gegenbein (Forderung, positiv) — Konto/chart_link bleiben unverändert.
            if ($counterId) {
                $company->execute(<<<SQL
                    UPDATE acc_trans SET
                        amount    = :amount,
                        transdate = :transdate,
                        gldate    = :transdate,
                        source    = :source,
                        memo      = :memo
                    WHERE acc_trans_id = :acc_trans_id
SQL, [
                    'amount'       => $amt,
                    'transdate'    => $edit['transdate'],
                    'source'       => (string)$edit['source'],
                    'memo'         => $edit['memo'],
                    'acc_trans_id' => $counterId,
                ]);
            }
        }

        // Alte Zahlungsbuchungen löschen — aber NICHT die geschützten Bank-Zahlungen
        // (noch gebuchte Bankzuordnung). Verwaiste/stornierte BANK-Einträge sind nicht
        // geschützt und werden hier mit aufgeräumt.
        $deletePaymentsQuery = "DELETE FROM acc_trans WHERE trans_id = :fakturaID AND chart_link LIKE '{$paymentLink}'{$protectExclusion}";
        $company->execute($deletePaymentsQuery, array_merge(['fakturaID' => $fakturaID], $protectParams));

        // Neue Zahlungsbuchungen einfügen
        foreach ($paymentEntries as $entry) {
            // Geschützte Bank-Zahlungen überspringen — sie bleiben in der DB erhalten
            // (oben nicht gelöscht, oben per Memo-UPDATE gepflegt). Ein erneutes
            // Einfügen würde sie verdoppeln. Erkennung über die acc_trans_id (robust
            // gegen ein vom Anwender geändertes Quellfeld). paid (Schritt 3) zählt sie
            // weiterhin korrekt aus $paymentEntries mit.
            $accTransId = intval($entry['acc_trans_id'] ?? 0);
            if ($accTransId > 0 && in_array($accTransId, $protectedBankIds, true)) {
                continue;
            }
            $insertQuery = <<<SQL
                INSERT INTO acc_trans (
                    trans_id,
                    chart_id,
                    amount,
                    transdate,
                    gldate,
                    source,
                    memo,
                    tax_id,
                    taxkey,
                    chart_link
                )
                SELECT
                    :trans_id,
                    :chart_id,
                    :amount,
                    :transdate,
                    :transdate,
                    :source,
                    :memo,
                    :tax_id,
                    :taxkey,
                    chart.link
                FROM chart
                WHERE chart.id = :chart_id
SQL;
            $company->execute($insertQuery, [
                'trans_id' => $fakturaID,
                'chart_id' => intval($entry['chart_id']),
                'amount' => floatval($entry['amount']),
                'transdate' => $entry['transdate'],
                'source' => $entry['source'] ?? '',
                'memo' => $entry['memo'] ?? '',
                'tax_id' => intval($entry['tax_id'] ?? 0),
                'taxkey' => intval($entry['taxkey'] ?? 0)
            ]);
        }
    }

    // 3. Beträge in Rechnungstabelle aktualisieren (ar oder ap)
    if ($isInvoiceType && (!empty($accTransEntries) || $updatePayments)) {
        if ($updatePayments) {
            // paid NICHT aus der gesendeten Liste summieren, sondern direkt aus dem
            // Hauptbuch ableiten — die Zahlungs-Beine (chart_link '…_paid', negativer
            // Betrag) sind die Wahrheit. So bleibt paid auch dann konsistent, wenn
            // geschützte Bank-Zahlungen in acc_trans stehen, aber nicht (mehr) in der
            // übermittelten Liste enthalten sind.
            $updateInvoiceQuery = <<<SQL
                UPDATE {$mainTable} SET
                    netamount = :netAmount,
                    amount = :grossAmount,
                    paid = COALESCE((
                        SELECT -sum(amount)
                        FROM acc_trans
                        WHERE trans_id = :fakturaID
                            AND chart_link LIKE '{$paymentLink}'
                    ), 0)
                WHERE id = :fakturaID
SQL;
            $company->execute($updateInvoiceQuery, [
                'fakturaID' => $fakturaID,
                'netAmount' => $netAmount,
                'grossAmount' => $grossAmount
            ]);
        } else {
            $updateInvoiceQuery = <<<SQL
                UPDATE {$mainTable} SET
                    netamount = :netAmount,
                    amount = :grossAmount
                WHERE id = :fakturaID
SQL;
            $company->execute($updateInvoiceQuery, [
                'fakturaID' => $fakturaID,
                'netAmount' => $netAmount,
                'grossAmount' => $grossAmount
            ]);
        }
    }

    resultInfo(true, 'UPDATED', [
        'message' => 'Positionen und Buchungen aktualisiert',
        'itemCount' => count($items),
        'accTransCount' => count($accTransEntries),
        'paymentCount' => count($paymentEntries)
    ]);
}

/**
 * Bucht eine Ausgangsrechnung (ar) serverseitig ins Hauptbuch (acc_trans).
 *
 * Spiegelt exakt die Frontend-Logik (useAccounting.calculateAccTransEntries) und die
 * buchungsziel-Ableitung (Buchungsgruppe → taxzone_charts → Erlöskonto/Steuer) aus
 * createFakturaItem. Pro Erlöskonto+Steuer eine Sammelbuchung: Erlös (+netto),
 * Steuer (+steuer), Forderungskonto (−brutto). Vorzeichen wie kivitendo.
 *
 * Sicherheitsnetz:
 *  - Idempotent: existieren bereits Sach-Buchungen (chart_link NICHT '%_paid%'),
 *    wird nichts gebucht (reason ALREADY_POSTED).
 *  - Wird das ermittelte Brutto != ar.amount (> 1 Cent), wird NICHT gebucht
 *    (reason AMOUNT_MISMATCH) — schützt vor falschen Steuer-/Kontenzuordnungen.
 *  - $dryRun = true liefert nur die geplanten Buchungssätze zurück, ohne zu schreiben.
 *
 * @param object $db     DbhCompany-Handle
 * @param int    $arId   ar.id
 * @param bool   $dryRun Nur planen, nicht schreiben
 * @return array ['posted'=>bool, 'reason'=>string, 'gross'=>float, 'ar_amount'=>float, 'entries'=>array, 'count'=>int]
 */
function postArInvoiceToLedger($db, $arId, $dryRun = false) {
    $arId = intval($arId);
    $ar = $db->getOne(
        "SELECT id, taxzone_id, taxincluded, amount, transdate FROM ar WHERE id = :id",
        ['id' => $arId]
    );
    if (!$ar) {
        return ['posted' => false, 'reason' => 'AR_NOT_FOUND'];
    }

    // Bereits gebucht? (Sach-Buchungen außerhalb der Zahlungs-Beine '%_paid%')
    $already = $db->getOne(
        "SELECT 1 AS x FROM acc_trans WHERE trans_id = :id AND COALESCE(chart_link,'') NOT LIKE '%_paid%' LIMIT 1",
        ['id' => $arId]
    );
    if ($already) {
        return ['posted' => false, 'reason' => 'ALREADY_POSTED'];
    }

    $taxIncluded = in_array($ar['taxincluded'], [true, 't', '1', 1], true);
    $transdate   = $ar['transdate'];

    // Positionen inkl. buchungsziel (Erlöskonto, Steuerkonto, tax_id, rate) — exakt
    // wie createFakturaItem: der für die Steuerzone gültige taxzone_charts-Eintrag,
    // die Steuer kommt aus dem taxkeys-Eintrag des Erlöskontos.
    $items = $db->getAll(<<<SQL
        SELECT i.qty, i.sellprice, i.discount,
               bz.income_chart_id, bz.tax_id, bz.tax_chart_id, bz.rate
        FROM invoice i
        JOIN parts p ON p.id = i.parts_id
        LEFT JOIN LATERAL (
            SELECT c2.id AS income_chart_id, tk.tax_id, tx.chart_id AS tax_chart_id, tx.rate
            FROM buchungsgruppen bg
            JOIN taxzone_charts tc ON tc.buchungsgruppen_id = bg.id AND tc.taxzone_id = :tz
            JOIN chart c2 ON c2.id = tc.income_accno_id
            LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
            LEFT JOIN tax tx ON tx.id = tk.tax_id
            WHERE bg.id = p.buchungsgruppen_id
            ORDER BY tk.startdate DESC NULLS LAST
            LIMIT 1
        ) bz ON true
        WHERE i.trans_id = :id
        ORDER BY i.position
    SQL, ['id' => $arId, 'tz' => $ar['taxzone_id']]);

    if (empty($items)) {
        return ['posted' => false, 'reason' => 'NO_ITEMS'];
    }

    // Nach Erlöskonto + tax_id gruppieren und Netto summieren.
    $groups = [];
    foreach ($items as $it) {
        if ($it['income_chart_id'] === null) {
            // Ohne Erlöskonto (Buchungsgruppe/Steuerzone nicht zugeordnet) NICHT raten.
            return ['posted' => false, 'reason' => 'NO_INCOME_ACCOUNT'];
        }
        $rate      = (float)($it['rate'] ?? 0);
        $itemTotal = round((float)$it['qty'] * (float)$it['sellprice'] * (1 - (float)$it['discount']), 2);
        $net       = ($taxIncluded && $rate) ? round($itemTotal / (1 + $rate), 2) : $itemTotal;
        $key       = $it['income_chart_id'] . '_' . ($it['tax_id'] ?? '0');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'income'    => (int)$it['income_chart_id'],
                'tax_chart' => $it['tax_chart_id'] !== null ? (int)$it['tax_chart_id'] : null,
                'tax_id'    => $it['tax_id'] !== null ? (int)$it['tax_id'] : 0,
                'rate'      => $rate,
                'net'       => 0.0,
            ];
        }
        $groups[$key]['net'] += $net;
    }

    $entries = [];
    $gross   = 0.0;
    foreach ($groups as $g) {
        $net    = round($g['net'], 2);
        $tax    = $g['rate'] ? round($net * $g['rate'], 2) : 0.0;
        $taxkey = $g['rate'] > 0.1 ? 3 : ($g['rate'] > 0 ? 2 : 0);

        $entries[] = ['chart_id' => $g['income'], 'amount' => $net, 'tax_id' => $g['tax_id'], 'taxkey' => $taxkey];
        if ($tax != 0 && $g['tax_chart']) {
            $entries[] = ['chart_id' => $g['tax_chart'], 'amount' => $tax, 'tax_id' => 0, 'taxkey' => 0];
        }
        $gross += $net + $tax;
    }
    $gross = round($gross, 2);

    // Forderungskonto (Standard: chart.link='AR', niedrigste Kontonummer).
    $arChart = $db->getOne("SELECT id FROM chart WHERE link = 'AR' ORDER BY accno ASC LIMIT 1");
    if (!$arChart) {
        return ['posted' => false, 'reason' => 'NO_AR_ACCOUNT'];
    }
    $entries[] = ['chart_id' => (int)$arChart['id'], 'amount' => round(-$gross, 2), 'tax_id' => 0, 'taxkey' => 0];

    // Sicherheitsnetz: ermitteltes Brutto muss zum gespeicherten Rechnungsbetrag passen.
    if (abs($gross - (float)$ar['amount']) > 0.01) {
        return ['posted' => false, 'reason' => 'AMOUNT_MISMATCH',
                'gross' => $gross, 'ar_amount' => (float)$ar['amount'], 'entries' => $entries];
    }

    if ($dryRun) {
        return ['posted' => false, 'reason' => 'DRY_RUN',
                'gross' => $gross, 'ar_amount' => (float)$ar['amount'], 'entries' => $entries];
    }

    $db->beginTransaction();
    try {
        foreach ($entries as $e) {
            $linkRow = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $e['chart_id']]);
            $db->execute(<<<SQL
                INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
                VALUES (:t, :c, :a, :td, :td, '', '', :taxid, :tk, :link)
            SQL, [
                't'     => $arId,
                'c'     => $e['chart_id'],
                'a'     => $e['amount'],
                'td'    => $transdate,
                'taxid' => $e['tax_id'],
                'tk'    => $e['taxkey'],
                'link'  => $linkRow['link'] ?? '',
            ]);
        }
        $db->commit();
    } catch (\Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }

    return ['posted' => true, 'gross' => $gross, 'ar_amount' => (float)$ar['amount'], 'count' => count($entries)];
}

/**
 * Bucht eine Eingangsrechnung (ap) serverseitig ins Hauptbuch (acc_trans).
 *
 * Spiegelbild zu postArInvoiceToLedger: pro Aufwandskonto+Steuer eine Sammelbuchung
 * Aufwand (−netto) + Vorsteuer (−steuer) gegen Kreditor (+brutto). Aufwandskonto und
 * Vorsteuer kommen aus der Buchungsgruppe (taxzone_charts.expense_accno → dessen
 * taxkeys → tax_id/rate/Steuerkonto). Vorzeichen wie kivitendo (siehe AP-Referenz).
 *
 * Sicherheitsnetz wie bei AR: idempotent (ALREADY_POSTED) und Brutto==ap.amount-Schutz
 * (AMOUNT_MISMATCH). $dryRun liefert nur die geplanten Buchungssätze.
 *
 * @param object $db     DbhCompany-Handle
 * @param int    $apId   ap.id
 * @param bool   $dryRun Nur planen, nicht schreiben
 * @return array ['posted'=>bool,'reason'=>string,'gross'=>float,'ap_amount'=>float,'entries'=>array,'count'=>int]
 */
function postApInvoiceToLedger($db, $apId, $dryRun = false) {
    $apId = intval($apId);
    $ap = $db->getOne(
        "SELECT id, taxzone_id, taxincluded, amount, transdate FROM ap WHERE id = :id",
        ['id' => $apId]
    );
    if (!$ap) {
        return ['posted' => false, 'reason' => 'AP_NOT_FOUND'];
    }

    $already = $db->getOne(
        "SELECT 1 AS x FROM acc_trans WHERE trans_id = :id AND COALESCE(chart_link,'') NOT LIKE '%_paid%' LIMIT 1",
        ['id' => $apId]
    );
    if ($already) {
        return ['posted' => false, 'reason' => 'ALREADY_POSTED'];
    }

    $taxIncluded = in_array($ap['taxincluded'], [true, 't', '1', 1], true);
    $transdate   = $ap['transdate'];

    // Positionen inkl. buchungsziel: Aufwandskonto (expense_accno) + Vorsteuer aus
    // dem taxkeys-Eintrag des Aufwandskontos.
    $items = $db->getAll(<<<SQL
        SELECT i.qty, i.sellprice, i.discount,
               bz.expense_chart_id, bz.tax_id, bz.tax_chart_id, bz.rate
        FROM invoice i
        JOIN parts p ON p.id = i.parts_id
        LEFT JOIN LATERAL (
            SELECT c2.id AS expense_chart_id, tk.tax_id, tx.chart_id AS tax_chart_id, tx.rate
            FROM buchungsgruppen bg
            JOIN taxzone_charts tc ON tc.buchungsgruppen_id = bg.id AND tc.taxzone_id = :tz
            JOIN chart c2 ON c2.id = tc.expense_accno_id
            LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
            LEFT JOIN tax tx ON tx.id = tk.tax_id
            WHERE bg.id = p.buchungsgruppen_id
            ORDER BY tk.startdate DESC NULLS LAST
            LIMIT 1
        ) bz ON true
        WHERE i.trans_id = :id
        ORDER BY i.position
    SQL, ['id' => $apId, 'tz' => $ap['taxzone_id']]);

    if (empty($items)) {
        return ['posted' => false, 'reason' => 'NO_ITEMS'];
    }

    $groups = [];
    foreach ($items as $it) {
        if ($it['expense_chart_id'] === null) {
            return ['posted' => false, 'reason' => 'NO_EXPENSE_ACCOUNT'];
        }
        $rate      = (float)($it['rate'] ?? 0);
        $itemTotal = round((float)$it['qty'] * (float)$it['sellprice'] * (1 - (float)$it['discount']), 2);
        $net       = ($taxIncluded && $rate) ? round($itemTotal / (1 + $rate), 2) : $itemTotal;
        $key       = $it['expense_chart_id'] . '_' . ($it['tax_id'] ?? '0');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'expense'   => (int)$it['expense_chart_id'],
                'tax_chart' => $it['tax_chart_id'] !== null ? (int)$it['tax_chart_id'] : null,
                'tax_id'    => $it['tax_id'] !== null ? (int)$it['tax_id'] : 0,
                'rate'      => $rate,
                'net'       => 0.0,
            ];
        }
        $groups[$key]['net'] += $net;
    }

    $entries = [];
    $gross   = 0.0;
    foreach ($groups as $g) {
        $net    = round($g['net'], 2);
        $tax    = $g['rate'] ? round($net * $g['rate'], 2) : 0.0;
        // Vorsteuer-Schlüssel: 19% = 9, 7% = 8, sonst 0 (Einkaufsseite)
        $taxkey = $g['rate'] > 0.1 ? 9 : ($g['rate'] > 0 ? 8 : 0);

        $entries[] = ['chart_id' => $g['expense'], 'amount' => -$net, 'tax_id' => $g['tax_id'], 'taxkey' => $taxkey];
        if ($tax != 0 && $g['tax_chart']) {
            $entries[] = ['chart_id' => $g['tax_chart'], 'amount' => -$tax, 'tax_id' => 0, 'taxkey' => 0];
        }
        $gross += $net + $tax;
    }
    $gross = round($gross, 2);

    // Kreditorenkonto (Standard: chart.link='AP', niedrigste Kontonummer).
    $apChart = $db->getOne("SELECT id FROM chart WHERE link = 'AP' ORDER BY accno ASC LIMIT 1");
    if (!$apChart) {
        return ['posted' => false, 'reason' => 'NO_AP_ACCOUNT'];
    }
    $entries[] = ['chart_id' => (int)$apChart['id'], 'amount' => round($gross, 2), 'tax_id' => 0, 'taxkey' => 0];

    if (abs($gross - (float)$ap['amount']) > 0.01) {
        return ['posted' => false, 'reason' => 'AMOUNT_MISMATCH',
                'gross' => $gross, 'ap_amount' => (float)$ap['amount'], 'entries' => $entries];
    }

    if ($dryRun) {
        return ['posted' => false, 'reason' => 'DRY_RUN',
                'gross' => $gross, 'ap_amount' => (float)$ap['amount'], 'entries' => $entries];
    }

    $db->beginTransaction();
    try {
        foreach ($entries as $e) {
            $linkRow = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $e['chart_id']]);
            $db->execute(<<<SQL
                INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
                VALUES (:t, :c, :a, :td, :td, '', '', :taxid, :tk, :link)
            SQL, [
                't'     => $apId,
                'c'     => $e['chart_id'],
                'a'     => $e['amount'],
                'td'    => $transdate,
                'taxid' => $e['tax_id'],
                'tk'    => $e['taxkey'],
                'link'  => $linkRow['link'] ?? '',
            ]);
        }
        $db->commit();
    } catch (\Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }

    return ['posted' => true, 'gross' => $gross, 'ap_amount' => (float)$ap['amount'], 'count' => count($entries)];
}

/**
 * Gibt das Mapping von Dokumenttyp auf Nummernfeld und defaults-Spalte zurück
 *
 * @param string $fakturaType Dokumenttyp
 * @return array ['doc_number_column' => ..., 'defaults_column' => ...]
 */
function getNumberConfig($fakturaType) {
    $map = [
        'invoice'            => ['doc_number_column' => 'invnumber', 'defaults_column' => 'invnumber'],
        'purchase_invoice'   => ['doc_number_column' => 'invnumber', 'defaults_column' => 'invnumber'],
        'order'              => ['doc_number_column' => 'ordnumber', 'defaults_column' => 'sonumber'],
        'purchase_order'     => ['doc_number_column' => 'ordnumber', 'defaults_column' => 'ponumber'],
        'quotation'          => ['doc_number_column' => 'quonumber', 'defaults_column' => 'sqnumber'],
        'request_quotation'  => ['doc_number_column' => 'quonumber', 'defaults_column' => 'rfqnumber'],
    ];
    return $map[$fakturaType] ?? null;
}

/**
 * Löscht eine komplette Faktura (Rechnung, Auftrag, Angebot, Lieferschein).
 * Bei Rechnungen ist Löschen nur erlaubt wenn es die letzte Nummer des Nummernkreises ist.
 * Aufträge und Angebote sind immer löschbar. Bei Löschung der letzten Nummer wird
 * der Nummernkreis um 1 zurückgesetzt.
 *
 * @param array $data Request-Daten mit fakturaID und fakturaType
 * @testdata {"fakturaID": 1, "fakturaType": "invoice"}
 */
function deleteFaktura($data) {
    $fakturaID = intval($data['fakturaID']);
    $fakturaType = $data['fakturaType'] ?? 'invoice';

    if ($fakturaID <= 0) {
        echo resultInfo(false, 'INVALID_FAKTURA_ID', 'Ungültige Faktura-ID');
        return;
    }

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $mainTable = $tableConfig['main_table'];
    $itemsTable = $tableConfig['items_table'];
    $numberConfig = getNumberConfig($fakturaType);

    // 1. Dokument laden
    $docNumberCol = $numberConfig ? $numberConfig['doc_number_column'] : null;
    $selectCols = $docNumberCol ? "id, {$docNumberCol}" : 'id';
    $doc = $company->getOne(
        "SELECT {$selectCols} FROM {$mainTable} WHERE id = :fakturaID",
        ['fakturaID' => $fakturaID]
    );

    if (!$doc) {
        echo resultInfo(false, 'NOT_FOUND', 'Dokument nicht gefunden');
        return;
    }

    // 2. Transaktion starten und Nummernkreis mit FOR UPDATE sperren,
    //    damit keine parallele Nummernvergabe dazwischenfunkt.
    $company->beginTransaction();

    $isLastNumber = false;
    if ($numberConfig) {
        $defaultsCol = $numberConfig['defaults_column'];
        $docNumber = $doc[$docNumberCol] ?? '';

        // FOR UPDATE sperrt die defaults-Zeile — konkurrierende CTEs
        // (UPDATE defaults SET ... +1) blockieren bis zum COMMIT.
        $defaults = $company->getOne(
            "SELECT {$defaultsCol} FROM defaults LIMIT 1 FOR UPDATE",
            []
        );
        $currentNumber = $defaults[$defaultsCol] ?? '';

        $isLastNumber = (string)$docNumber === (string)$currentNumber;

        // Nur bei Rechnungen die Nummernkreis-Prüfung erzwingen
        if (in_array($fakturaType, ['invoice', 'purchase_invoice']) && !$isLastNumber) {
            $company->rollBack();
            echo resultInfo(false, 'NOT_LAST_NUMBER', [
                'currentNumber' => $currentNumber,
                'docNumber' => $docNumber
            ]);
            return;
        }
    }

    // 3. Nummernkreis, Positionen, Buchungen und Dokument atomar löschen
    if ($numberConfig && $isLastNumber) {
        $defaultsCol = $numberConfig['defaults_column'];
        $company->execute(
            "UPDATE defaults SET {$defaultsCol} = GREATEST(({$defaultsCol}::INT) - 1, 0)",
            []
        );
    }

    $company->execute(
        "DELETE FROM {$itemsTable} WHERE trans_id = :fakturaID",
        ['fakturaID' => $fakturaID]
    );

    if (in_array($fakturaType, ['invoice', 'purchase_invoice'])) {
        $company->execute(
            "DELETE FROM acc_trans WHERE trans_id = :fakturaID",
            ['fakturaID' => $fakturaID]
        );
    }

    if ($mainTable === 'oe') {
        $company->execute(
            "DELETE FROM calendar_events WHERE order_id = :fakturaID",
            ['fakturaID' => $fakturaID]
        );
    }

    $company->execute(
        "DELETE FROM {$mainTable} WHERE id = :fakturaID",
        ['fakturaID' => $fakturaID]
    );

    $company->commit();

    echo resultInfo(true, 'DELETED', ['message' => 'Dokument erfolgreich gelöscht']);
}

/**
 * Aktualisiert ein einzelnes Feld einer Faktura (Belegdatum, Lieferdatum, Fälligkeitsdatum)
 *
 * @param array $data Request-Daten mit fakturaID, fakturaType, field und value
 * @return void Gibt JSON zurück
 * @testdata {"fakturaID": 1, "fakturaType": "invoice", "field": "transdate", "value": "2025-01-30"}
 */
function updateFakturaField($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $fakturaType = $data['fakturaType'] ?? 'invoice';
    $field = $data['field'] ?? '';
    $value = $data['value'] ?? null;

    if ($fakturaID <= 0) {
        resultInfo(false, 'INVALID_FAKTURA_ID', ['message' => 'Ungültige Faktura-ID']);
        return;
    }

    // Erlaubte Felder für diesen Endpunkt (Sicherheit!)
    $allowedFields = [
        // Belegnummern
        'invnumber',        // Rechnungsnummer
        'ordnumber',        // Auftragsnummer
        'quonumber',        // Angebotsnummer
        'donumber',         // Lieferscheinnummer
        'cusordnumber',     // Kundenauftragsnummer
        // Datumsfelder
        'transdate',        // Belegdatum
        'reqdate',          // Lieferdatum (oe, delivery_orders)
        'deliverydate',     // Lieferdatum (ar, ap)
        'duedate',          // Fälligkeitsdatum (nur bei invoice)
        // Kundenfelder
        'customer_id',      // Kunde
        'vendor_id',        // Lieferant
        'shipto_id',        // Lieferadresse
        'billing_address_id', // Rechnungsadresse
        // Notizen
        'notes',            // Öffentliche Notizen
        'intnotes',         // Interne Notizen
        // Zusätzliche Informationen
        'currency_id',      // Währung
        'taxzone_id',       // Steuerzone
        'language_id',      // Sprache
        'department_id',    // Abteilung
        'employee_id',      // Mitarbeiter
        'payment_id',       // Zahlungsbedingungen
        'delivery_term_id', // Lieferbedingungen
        'taxincluded',      // Steuer im Preis inbegriffen
        'closed'            // Status offen/erledigt
    ];

    // Feldname-Mapping: Frontend -> Datenbank (je nach Tabellentyp)
    $fieldMapping = [];
    if (in_array($fakturaType, ['invoice', 'purchase_invoice'])) {
        // ar/ap-Tabelle verwendet 'deliverydate' statt 'reqdate'
        $fieldMapping['reqdate'] = 'deliverydate';
    }

    // Feldnamen ggf. mappen
    $dbField = $fieldMapping[$field] ?? $field;

    if (!in_array($field, $allowedFields)) {
        resultInfo(false, 'INVALID_FIELD', ['message' => 'Feld nicht erlaubt: ' . $field]);
        return;
    }

    // duedate nur bei Rechnungen erlauben
    if ($field === 'duedate' && !in_array($fakturaType, ['invoice', 'purchase_invoice'])) {
        resultInfo(false, 'INVALID_FIELD', ['message' => 'Fälligkeitsdatum nur bei Rechnungen erlaubt']);
        return;
    }

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $mainTable = $tableConfig['main_table'];

    // Wert vorbereiten
    if ($field === 'closed') {
        $cleanValue = $value ? 't' : 'f';
    } else {
        $cleanValue = empty($value) ? null : $value;
    }

    // Update durchführen (mit gemapptem Feldnamen)
    $company->update(
        $mainTable,
        [$dbField],
        [$cleanValue],
        'id = ' . $fakturaID
    );

    resultInfo(true, 'UPDATED', [
        'message' => 'Feld aktualisiert',
        'field' => $field,
        'dbField' => $dbField,
        'value' => $cleanValue
    ]);
}

/**
 * Bestätigt einen Verkaufsauftrag oder setzt ihn auf Auftragseingang zurück.
 *
 * Schaltet record_type zwischen 'sales_order' (bestätigt) und
 * 'sales_order_intake' (Auftragseingang/unbestätigt). Nur für Verkaufsaufträge —
 * Angebote, Lieferscheine, Rechnungen und Einkaufsbelege werden abgelehnt.
 *
 * @param int  $data['fakturaID'] Auftrags-ID (oe.id)
 * @param bool $data['confirmed'] true = bestätigt, false = Auftragseingang
 * @testdata {"fakturaID": 1, "confirmed": true}
 */
function setOrderConfirmed($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    if (!$fakturaID) {
        resultInfo(false, 'VALIDATION_ERROR', 'fakturaID required');
        return;
    }

    $company = DbhCompany::begin();
    permit(getPermissionForFakturaType('order'));

    $row = $company->getOne(
        "SELECT record_type FROM oe WHERE id = :id",
        [':id' => $fakturaID]
    );
    if (!$row) {
        resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden');
        return;
    }
    if (!in_array($row['record_type'], ['sales_order', 'sales_order_intake'], true)) {
        resultInfo(false, 'INVALID_TYPE', 'Nur Verkaufsaufträge können bestätigt werden');
        return;
    }

    $newType = !empty($data['confirmed']) ? 'sales_order' : 'sales_order_intake';
    $company->update('oe', ['record_type'], [$newType], 'id = ' . $fakturaID);

    resultInfo(true, 'UPDATED', ['record_type' => $newType]);
}

/**
 * Konvertiert ein Dokument in einen anderen Dokumenttyp
 * (z.B. Angebot → Auftrag, Auftrag → Rechnung, etc.)
 *
 * Ablauf:
 * 1. Quelldokument-Header laden
 * 2. Neues Zieldokument anlegen (neue Nummer, transdate = heute)
 * 3. Header-Felder vom Quell- auf das Zieldokument kopieren
 * 4. Positionen kopieren (INSERT...SELECT)
 * 5. record_links-Eintrag erstellen
 * 6. LxCars: Fahrzeug-Zuordnung kopieren (falls vorhanden)
 *
 * @param array $data Request-Daten mit sourceId, sourceType, targetType
 * @testdata {"sourceId": 1, "sourceType": "order", "targetType": "invoice"}
 */
function convertFaktura($data) {
    $sourceId   = intval($data['sourceId'] ?? 0);
    $sourceType = $data['sourceType'] ?? '';
    $targetType = $data['targetType'] ?? '';

    if ($sourceId <= 0 || !$sourceType || !$targetType) {
        resultInfo(false, 'INVALID_PARAMS', 'sourceId, sourceType und targetType sind erforderlich');
        return;
    }

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType($sourceType));
    permit(getPermissionForFakturaType($targetType));

    $sourceConfig = getFakturaTableConfig($sourceType);
    $targetConfig = getFakturaTableConfig($targetType);
    $sourceTable  = $sourceConfig['main_table'];
    $targetTable  = $targetConfig['main_table'];

    // ── 1. Quelldokument-Header laden ──

    $source = $company->getOne(
        "SELECT * FROM {$sourceTable} WHERE id = :id",
        [':id' => $sourceId]
    );

    if (!$source) {
        resultInfo(false, 'SOURCE_NOT_FOUND', 'Quelldokument nicht gefunden');
        return;
    }

    // ── 1b. Duplikat-Check: Nur eine Rechnung pro Auftrag/Angebot ──

    $isReuse = ($sourceType === $targetType);

    if (!$isReuse && $targetType === 'invoice' && $sourceTable === 'oe') {
        $existingInvoice = $company->getOne(
            "SELECT rl.to_id, ar.invnumber
             FROM record_links rl
             JOIN ar ON ar.id = rl.to_id
             WHERE rl.from_table = 'oe' AND rl.from_id = :sourceId AND rl.to_table = 'ar'
             LIMIT 1",
            [':sourceId' => $sourceId]
        );

        if ($existingInvoice) {
            resultInfo(false,
                'Für dieses Dokument existiert bereits eine Rechnung: ' . ($existingInvoice['invnumber'] ?? ''));
            return;
        }
    }

    // Transaktion starten — invnumber-Inkrement und alle Folgeoperationen atomar
    $company->beginTransaction();

    try {

    // Kunden-/Lieferanten-ID bestimmen
    $customerId = $source['customer_id'] ?? null;
    $vendorId   = $source['vendor_id'] ?? null;

    // Falls customer_id NULL ist und das Quelldokument ein Fahrzeug hat → Halter übernehmen
    if (!$customerId && !$vendorId && $sourceTable === 'oe') {
        $owner = $company->getOne(
            "SELECT cl.c_ow FROM oe_ext e JOIN cars_lxcars cl ON cl.c_id = e.c_id WHERE e.oe_id = :id AND cl.c_ow IS NOT NULL",
            [':id' => $sourceId]
        );
        if ($owner) {
            $customerId = intval($owner['c_ow']);
        }
    }

    // Für Einkaufs-Workflows (Lieferantenanfrage/-auftrag) aus Verkaufsdokumenten:
    // customer_id nicht übernehmen, vendor_id bleibt leer (manuell zuordnen)
    $isTargetPurchase = in_array($targetType, ['purchase_order', 'request_quotation']);
    if ($isTargetPurchase) {
        $customerId = null;
        $vendorId   = null;
    }

    // ── 2. Neues Dokument anlegen (CTE mit Nummern-Inkrement) ──

    $auth = DbhAuth::begin();
    $login = $auth->getLogin();
    $employee = $company->getOne("SELECT id FROM employee WHERE login = :login", ['login' => $login]);
    $employeeId = $employee ? intval($employee['id']) : null;

    $currencyId = intval($source['currency_id'] ?? 1);
    $taxzoneId  = intval($source['taxzone_id'] ?? 4);

    // Effektiven Zieltyp bestimmen (wie in createFaktura)
    $effectiveTarget = $targetType;

    // Nummernfeld und record_type je nach Zieltyp
    $newId = null;

    /* Für Ronny (alter Code):

    function insertInvoiceFromOrder( $data ){
        $exists = $GLOBALS['dbh']->getOne( "SELECT id FROM ar WHERE ordnumber = '".$data['ordnumber']."' LIMIT 1" );

        if( is_array( $exists ) && sizeof( $exists ) > 0 ){
            getInvoice( $exists, "exists" );
            return;
        }

        $GLOBALS['dbh']->beginTransaction();
        $id = $GLOBALS['dbh']->getOne( "WITH tmp AS ( UPDATE defaults SET invnumber = invnumber::INT + 1 RETURNING invnumber) ".
                                    "INSERT INTO ar ( invnumber, customer_id, employee_id, taxzone_id, currency_id, shippingpoint, notes, ordnumber, intnotes, shipvia, amount, netamount, invoice, type ) ".
                                    "SELECT (SELECT invnumber FROM tmp), oe.customer_id, ".$_SESSION['id'].", oe.taxzone_id, oe.currency_id, oe.shippingpoint, oe.notes, oe.ordnumber, oe.intnotes, oe.shipvia, oe.amount, oe.netamount, true AS invoice, 'invoice' AS type ".
                                    "FROM oe WHERE id = ".$data['oe_id']." RETURNING id;" )['id'];

        $query = "INSERT INTO invoice (trans_id, position, parts_id, description, longdescription, qty, unit, sellprice, discount, marge_total, fxsellprice) ".
                "(SELECT ".$id.", position, parts_id, description, longdescription, qty, unit, sellprice, discount, marge_total, sellprice AS fxsellprice FROM orderitems WHERE trans_id = ".$data['oe_id'].")";

        $GLOBALS['dbh']->myquery( $query );
        $GLOBALS['dbh']->commit();

        $exists = array( "id" => $id );
        getInvoice( $exists );
    }

    function insertOfferFromOrder( $data ){//Auftrag in Angebot kopieren
        $query = "WITH tmp AS ( UPDATE defaults SET sqnumber = sqnumber::INT + 1 RETURNING sqnumber ) ".
                    "INSERT INTO oe ( quonumber, record_type, ".
                    "ordnumber, vendor_id, customer_id, amount, netamount, reqdate, taxincluded, shippingpoint, notes, employee_id, ".
                    "closed, cusordnumber, intnotes, department_id, shipvia, cp_id, language_id, payment_id, delivery_customer_id, ".
                    "delivery_vendor_id, taxzone_id, proforma, shipto_id, order_probability, expected_billing_date, globalproject_id, delivered, ".
                    "salesman_id, marge_total, marge_percent, transaction_description, delivery_term_id, currency_id, exchangerate, ".
                    "tax_point, km_stnd, c_id, status, car_status, finish_time, printed, car_manuf, car_type, internalorder, ".
                    "billing_address_id, order_status_id ".
                    ") SELECT ( SELECT sqnumber FROM tmp ), 'sales_quotation', ".
                    "ordnumber, vendor_id, customer_id, amount, netamount, reqdate, taxincluded, shippingpoint, notes, ".$_SESSION['id'].", ".
                    "closed, cusordnumber, intnotes, department_id, shipvia, cp_id, language_id, payment_id, delivery_customer_id, ".
                    "delivery_vendor_id, taxzone_id, proforma, shipto_id, order_probability, expected_billing_date, globalproject_id, delivered, ".
                    "salesman_id, marge_total, marge_percent, transaction_description, delivery_term_id, currency_id, exchangerate, ".
                    "tax_point, km_stnd, c_id, status, car_status, finish_time, printed, car_manuf, car_type, internalorder, ".
                    "billing_address_id, order_status_id ".
                    "FROM oe WHERE id = ".$data['oe_id']." RETURNING id;";

    */

    // Nummernkreis-Spalte je nach Zieltyp bestimmen
    $defaultsColMap = [
        'invoice'            => 'invnumber',
        'purchase_invoice'   => 'invnumber',
        'quotation'          => 'sqnumber',
        'request_quotation'  => 'rfqnumber',
        'order'              => 'sonumber',
        'purchase_order'     => 'ponumber',
        'delivery_order'     => 'donumber',
        'credit_note'        => 'invnumber',
        'invoice_storno'     => 'invnumber',
    ];
    $nkCol = $defaultsColMap[$effectiveTarget] ?? 'sonumber';
    $nkBefore = $company->getOne("SELECT {$nkCol} FROM defaults LIMIT 1");
    // writeLog("convertFaktura VORHER: defaults.{$nkCol} = " . ($nkBefore[$nkCol] ?? 'NULL') . " (sourceType={$sourceType}, targetType={$targetType}, effectiveTarget={$effectiveTarget})");

    if ($effectiveTarget === 'invoice') {
        /* Für Ronny (alter Code):
        $id = $GLOBALS['dbh']->getOne( "WITH tmp AS ( UPDATE defaults SET invnumber = invnumber::INT + 1 RETURNING invnumber) ".
                                    "INSERT INTO ar ( invnumber, customer_id, employee_id, taxzone_id, currency_id, shippingpoint, notes, ordnumber, intnotes, shipvia, amount, netamount, invoice, type ) ".
                                    "SELECT (SELECT invnumber FROM tmp), oe.customer_id, ".$_SESSION['id'].", oe.taxzone_id, oe.currency_id, oe.shippingpoint, oe.notes, oe.ordnumber, oe.intnotes, oe.shipvia, oe.amount, oe.netamount, true AS invoice, 'invoice' AS type ".
                                    "FROM oe WHERE id = ".$data['oe_id']." RETURNING id;" )['id'];
        */
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true
            RETURNING id
SQL;
        // Lösungsvorschlag: GREATEST() heilt defaults.invnumber falls out-of-sync mit MAX(ar.invnumber) → verhindert UNIQUE VIOLATION
        // $query = <<<SQL
        //     WITH tmp AS (
        //         UPDATE defaults SET invnumber = GREATEST(COALESCE(invnumber::INT, 0) + 1, COALESCE((SELECT MAX(invnumber::INT) FROM ar), 0) + 1)
        //         RETURNING invnumber
        //     )
        //     INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice)
        //     SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true
        //     RETURNING id
        // SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':customer_id'  => $customerId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId
        ]);
        $newId = intval($result['id']);

    } elseif ($effectiveTarget === 'purchase_invoice') {
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ap (invnumber, transdate, gldate, employee_id, vendor_id, taxzone_id, currency_id, invoice)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :vendor_id, :taxzone_id, :currency_id, true
            RETURNING id
SQL;
        // Lösungsvorschlag: GREATEST() heilt defaults.invnumber falls out-of-sync mit MAX(ap.invnumber) → verhindert UNIQUE VIOLATION
        // $query = <<<SQL
        //     WITH tmp AS (
        //         UPDATE defaults SET invnumber = GREATEST(COALESCE(invnumber::INT, 0) + 1, COALESCE((SELECT MAX(invnumber::INT) FROM ap), 0) + 1)
        //         RETURNING invnumber
        //     )
        //     INSERT INTO ap (invnumber, transdate, gldate, employee_id, vendor_id, taxzone_id, currency_id, invoice)
        //     SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :vendor_id, :taxzone_id, :currency_id, true
        //     RETURNING id
        // SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':vendor_id'   => $vendorId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId
        ]);
        $newId = intval($result['id']);

    } elseif (in_array($effectiveTarget, ['quotation', 'request_quotation'])) {
        $defaultsCol = ($effectiveTarget === 'quotation') ? 'sqnumber' : 'rfqnumber';
        $recordType  = ($effectiveTarget === 'quotation') ? 'sales_quotation' : 'request_quotation';

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO oe (ordnumber, quonumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, record_type)
            SELECT '', (SELECT {$defaultsCol} FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':customer_id' => $customerId,
            ':vendor_id'   => $vendorId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId,
            ':record_type' => $recordType
        ]);
        $newId = intval($result['id']);

    } elseif (in_array($effectiveTarget, ['order', 'purchase_order'])) {
        $defaultsCol = ($effectiveTarget === 'purchase_order') ? 'ponumber' : 'sonumber';
        $recordType  = ($effectiveTarget === 'purchase_order') ? 'purchase_order' : 'sales_order';

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO oe (ordnumber, quonumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, record_type)
            SELECT (SELECT {$defaultsCol} FROM tmp), '', CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':customer_id' => $customerId,
            ':vendor_id'   => $vendorId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId,
            ':record_type' => $recordType
        ]);
        $newId = intval($result['id']);

    } elseif ($effectiveTarget === 'delivery_order') {
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET donumber = COALESCE(donumber::INT, 0) + 1 RETURNING donumber)
            INSERT INTO delivery_orders (donumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, record_type)
            SELECT (SELECT donumber FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, 'sales_delivery_order'
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':customer_id' => $customerId,
            ':vendor_id'   => $vendorId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId
        ]);
        $newId = intval($result['id']);

    } elseif ($effectiveTarget === 'credit_note') {
        // Gutschrift: nur aus Rechnung (ar) erlaubt
        if ($sourceTable !== 'ar') {
            resultInfo(false, 'INVALID_SOURCE', 'Gutschrift kann nur aus einer Rechnung erstellt werden');
            return;
        }

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice, type, invnumber_for_credit_note)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true, 'credit_note', :source_invnumber
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id'      => $employeeId,
            ':customer_id'      => $customerId,
            ':taxzone_id'       => $taxzoneId,
            ':currency_id'      => $currencyId,
            ':source_invnumber' => $source['invnumber'] ?? ''
        ]);
        $newId = intval($result['id']);

    } elseif ($effectiveTarget === 'invoice_storno') {
        // Storno: nur aus Rechnung (ar) erlaubt
        if ($sourceTable !== 'ar') {
            resultInfo(false, 'INVALID_SOURCE', 'Storno kann nur aus einer Rechnung erstellt werden');
            return;
        }

        // Prüfen: Ist die Quellrechnung selbst ein Storno?
        if (!empty($source['storno']) && ($source['storno'] === true || $source['storno'] === 't')) {
            resultInfo(false, 'ALREADY_STORNO', 'Eine Storno-Rechnung kann nicht erneut storniert werden');
            return;
        }

        // Prüfen: Existiert bereits ein Storno für diese Rechnung?
        $existingStorno = $company->getOne(
            "SELECT id, invnumber FROM ar WHERE storno_id = :sourceId AND storno = true LIMIT 1",
            [':sourceId' => $sourceId]
        );
        if ($existingStorno) {
            resultInfo(false, 'STORNO_EXISTS', 'Für diese Rechnung existiert bereits ein Storno: ' . ($existingStorno['invnumber'] ?? ''));
            return;
        }

        // Neuen Storno-Record anlegen
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice, type, storno, storno_id)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true, 'invoice_storno', true, :storno_id
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':customer_id' => $customerId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId,
            ':storno_id'   => $sourceId
        ]);
        $newId = intval($result['id']);

        // Original-Rechnung als storniert markieren: paid = amount, storno = true
        $company->execute(
            "UPDATE ar SET paid = amount, storno = true,
             intnotes = CONCAT('Storniert am ' || CURRENT_DATE || E'\\n', COALESCE(intnotes, ''))
             WHERE id = :sourceId",
            [':sourceId' => $sourceId]
        );

    } else {
        resultInfo(false, 'UNSUPPORTED_TARGET', 'Zieltyp nicht unterstützt: ' . $effectiveTarget);
        return;
    }

    $nkAfter = $company->getOne("SELECT {$nkCol} FROM defaults LIMIT 1");
    // writeLog("convertFaktura NACHHER: defaults.{$nkCol} = " . ($nkAfter[$nkCol] ?? 'NULL') . " (effectiveTarget={$effectiveTarget}, newId={$newId})");

    // ── 3. Header-Felder vom Quell-Dokument kopieren ──

    // Felder, die auf dem Zieldokument aktualisiert werden (sofern sie in der Zieltabelle existieren)
    $headerFields = [];
    $headerParams = [':newId' => $newId];

    // Gemeinsame Felder die kopiert werden sollen
    $copyFields = ['notes', 'intnotes', 'payment_id', 'delivery_term_id', 'language_id',
                   'department_id', 'cusordnumber', 'globalproject_id', 'salesman_id',
                   'shippingpoint', 'shipvia', 'transaction_description', 'shipto_id',
                   'amount', 'netamount', 'taxincluded', 'cp_id',
                   'marge_total', 'marge_percent', 'tax_point', 'exchangerate',
                   'billing_address_id'];

    // reqdate → reqdate (oe/delivery_orders) oder deliverydate (ar/ap)
    if (isset($source['reqdate']) && $source['reqdate']) {
        $targetDateField = in_array($targetTable, ['ar', 'ap']) ? 'deliverydate' : 'reqdate';
        $headerFields[] = "{$targetDateField} = :reqdate_val";
        $headerParams[':reqdate_val'] = $source['reqdate'];
    } elseif (isset($source['deliverydate']) && $source['deliverydate']) {
        $targetDateField = in_array($targetTable, ['ar', 'ap']) ? 'deliverydate' : 'reqdate';
        $headerFields[] = "{$targetDateField} = :reqdate_val";
        $headerParams[':reqdate_val'] = $source['deliverydate'];
    }

    foreach ($copyFields as $field) {
        if (isset($source[$field]) && $source[$field] !== null && $source[$field] !== '') {
            $headerFields[] = "{$field} = :{$field}_val";
            $headerParams[":{$field}_val"] = $source[$field];
        }
    }

    // Gutschrift/Storno: Beträge negieren
    if (in_array($effectiveTarget, ['credit_note', 'invoice_storno'])) {
        if (isset($headerParams[':amount_val'])) {
            $headerParams[':amount_val'] = -1 * abs(floatval($headerParams[':amount_val']));
        }
        if (isset($headerParams[':netamount_val'])) {
            $headerParams[':netamount_val'] = -1 * abs(floatval($headerParams[':netamount_val']));
        }
        if (isset($headerParams[':marge_total_val'])) {
            $headerParams[':marge_total_val'] = -1 * abs(floatval($headerParams[':marge_total_val']));
        }
    }

    // ── 3b. Referenznummern kopieren ──
    // Angebotsnr → neuen Auftrag/Rechnung, Auftragsnr → neue Rechnung/Lieferschein

    if (!$isReuse && $sourceTable === 'oe') {
        // ordnumber → ar, ap, delivery_orders, oe (z.B. Auftrag → Rechnung, Auftrag → Angebot)
        if (!empty($source['ordnumber']) && in_array($targetTable, ['ar', 'ap', 'delivery_orders', 'oe'])) {
            $headerFields[] = "ordnumber = :ref_ordnumber";
            $headerParams[':ref_ordnumber'] = $source['ordnumber'];
        }

        // quonumber → oe, ar, ap (z.B. Angebot → Auftrag, Angebot → Rechnung)
        if (!empty($source['quonumber'])) {
            if (in_array($targetTable, ['oe', 'ar', 'ap'])) {
                $headerFields[] = "quonumber = :ref_quonumber";
                $headerParams[':ref_quonumber'] = $source['quonumber'];
            }
        }
    }

    if (!empty($headerFields)) {
        $setClause = implode(', ', $headerFields);
        $company->execute(
            "UPDATE {$targetTable} SET {$setClause} WHERE id = :newId",
            $headerParams
        );
    }

    // ── 4. Positionen kopieren ──

    $sourceItemsTable = $sourceConfig['items_table'];
    $targetItemsTable = $targetConfig['items_table'];

    // ID-Spalte in der Ziel-Items-Tabelle
    $targetIdCol = ($targetItemsTable === 'delivery_order_items') ? 'delivery_order_id' : 'trans_id';
    $sourceIdCol = ($sourceItemsTable === 'delivery_order_items') ? 'delivery_order_id' : 'trans_id';

    // Gemeinsame Spalten
    $commonCols = 'parts_id, description, longdescription, qty, sellprice, discount, unit, position';

    // Gutschrift: Mengen negieren (wie kivitendo)
    $qtyExpr = in_array($effectiveTarget, ['credit_note', 'invoice_storno']) ? '(qty * -1)' : 'qty';

    // Für Rechnungen/Gutschriften: fxsellprice zusätzlich setzen
    if ($targetItemsTable === 'invoice') {
        $company->execute(
            "INSERT INTO invoice ({$targetIdCol}, parts_id, description, longdescription, qty, sellprice, discount, unit, position, fxsellprice)
             SELECT :newId, parts_id, description, longdescription, {$qtyExpr}, sellprice, discount, unit, position, sellprice
             FROM {$sourceItemsTable}
             WHERE {$sourceIdCol} = :sourceId
             ORDER BY position",
            [':newId' => $newId, ':sourceId' => $sourceId]
        );
    } else {
        $company->execute(
            "INSERT INTO {$targetItemsTable} ({$targetIdCol}, {$commonCols})
             SELECT :newId, {$commonCols}
             FROM {$sourceItemsTable}
             WHERE {$sourceIdCol} = :sourceId
             ORDER BY position",
            [':newId' => $newId, ':sourceId' => $sourceId]
        );
    }

    // ── 5. record_links erstellen ──

    $company->execute(
        "INSERT INTO record_links (from_table, from_id, to_table, to_id)
         VALUES (:from_table, :from_id, :to_table, :to_id)",
        [
            ':from_table' => $sourceTable,
            ':from_id'    => $sourceId,
            ':to_table'   => $targetTable,
            ':to_id'      => $newId
        ]
    );

    // ── 6. LxCars: Fahrzeug-Zuordnung + Fertigstellung + Mängel kopieren ──
    // Fehler hier dürfen die Konvertierung nicht abbrechen

    try {
        $sourceExtTable = ($sourceTable === 'ar') ? 'ar_ext' : (($sourceTable === 'oe') ? 'oe_ext' : null);
        $targetExtTable = ($targetTable === 'ar') ? 'ar_ext' : (($targetTable === 'oe') ? 'oe_ext' : null);

        if ($sourceExtTable && $targetExtTable) {
            $sourceExtIdCol = ($sourceExtTable === 'ar_ext') ? 'ar_id' : 'oe_id';

            // Fahrzeug + km_stand + fertigstellung laden
            $extCols = 'c_id, km_stand';
            if ($sourceExtTable === 'oe_ext') $extCols .= ', fertigstellung';
            if ($sourceExtTable === 'ar_ext') $extCols .= ', fertigstellung';

            $sourceExt = $company->getOne(
                "SELECT {$extCols} FROM {$sourceExtTable} WHERE {$sourceExtIdCol} = :sourceId",
                [':sourceId' => $sourceId]
            );

            if ($sourceExt && $sourceExt['c_id']) {
                $cId = intval($sourceExt['c_id']);
                $kmStand = $sourceExt['km_stand'] !== null ? intval($sourceExt['km_stand']) : null;
                $fertigstellung = $sourceExt['fertigstellung'] ?? null;

                if ($targetExtTable === 'ar_ext') {
                    $company->execute(
                        "INSERT INTO ar_ext (ar_id, c_id, km_stand, fertigstellung)
                         VALUES (:newId, :c_id, :km_stand, :fertigstellung)
                         ON CONFLICT (ar_id) DO UPDATE SET c_id = EXCLUDED.c_id, km_stand = EXCLUDED.km_stand, fertigstellung = EXCLUDED.fertigstellung",
                        [':newId' => $newId, ':c_id' => $cId, ':km_stand' => $kmStand, ':fertigstellung' => $fertigstellung]
                    );
                } else {
                    $company->execute(
                        "INSERT INTO oe_ext (oe_id, c_id, km_stand) VALUES (:newId, :c_id, :km_stand)
                         ON CONFLICT (oe_id) DO UPDATE SET c_id = EXCLUDED.c_id, km_stand = EXCLUDED.km_stand",
                        [':newId' => $newId, ':c_id' => $cId, ':km_stand' => $kmStand]
                    );
                }

                // Nur bei Auftrag -> Rechnung: HU-Termin im Fahrzeug automatisch setzen,
                // wenn eine Auftragsposition einen der konfigurierten Trigger-Begriffe enthaelt.
                // Neuer HU-Termin = heute + 2 Jahre, jeweils 1. des Monats.
                if ($sourceTable === 'oe' && $targetTable === 'ar') {
                    $company->execute(
                        "UPDATE cars_lxcars
                         SET c_hu = (date_trunc('month', CURRENT_DATE + INTERVAL '2 years'))::date
                         WHERE c_id = :c_id
                           AND EXISTS (
                               SELECT 1
                               FROM orderitems oi,
                                    defaults_oserp d,
                                    unnest(string_to_array(d.value, ',')) AS trigger_raw
                               WHERE oi.trans_id = :source_id
                                 AND d.key = 'lxcars_hu_trigger_descriptions'
                                 AND trim(trigger_raw) <> ''
                                 AND oi.description ILIKE '%' || trim(trigger_raw) || '%'
                           )",
                        [':c_id' => $cId, ':source_id' => $sourceId]
                    );
                }
            }
        }

        // Defects kopieren (oe_defects → ar_defects oder umgekehrt)
        $sourceDefectsTable = ($sourceTable === 'ar') ? 'ar_defects' : (($sourceTable === 'oe') ? 'oe_defects' : null);
        $targetDefectsTable = ($targetTable === 'ar') ? 'ar_defects' : (($targetTable === 'oe') ? 'oe_defects' : null);

        if ($sourceDefectsTable && $targetDefectsTable) {
            $srcDefectsIdCol = ($sourceDefectsTable === 'ar_defects') ? 'ar_id' : 'oe_id';
            $tgtDefectsIdCol = ($targetDefectsTable === 'ar_defects') ? 'ar_id' : 'oe_id';

            $company->execute(
                "INSERT INTO {$targetDefectsTable} ({$tgtDefectsIdCol}, defect_code, defect_description, defect_class, note, sort_order)
                 SELECT :newId, defect_code, defect_description, defect_class, note, sort_order
                 FROM {$sourceDefectsTable}
                 WHERE {$srcDefectsIdCol} = :sourceId
                 ORDER BY sort_order",
                [':newId' => $newId, ':sourceId' => $sourceId]
            );
        }
    } catch (Exception $e) {
        // LxCars-Kopie fehlgeschlagen — Konvertierung trotzdem abschließen
        error_log('LxCars data copy failed: ' . $e->getMessage());
    }

    // Storno: Neuen Storno-Record auch als bezahlt markieren (paid = amount)
    if ($effectiveTarget === 'invoice_storno') {
        $company->execute("UPDATE ar SET paid = amount WHERE id = :newId", [':newId' => $newId]);
    }

    $company->commit();

    // Auftrag → Rechnung: sofort ins Hauptbuch buchen (acc_trans), damit die Rechnung
    // in GuV/UStVA/Bilanz erscheint und Zahlungen/Forderungskonto sauber greifen.
    // Best-effort NACH dem Commit: ein Buchungsfehler darf die bereits erstellte
    // Rechnung nicht zurückrollen (postArInvoiceToLedger ist idempotent + hat den
    // Mismatch-Schutz). Nur fuer echte Rechnungen, nicht Gutschrift/Storno.
    if ($effectiveTarget === 'invoice') {
        try {
            $postRes = postArInvoiceToLedger($company, $newId);
            if (empty($postRes['posted'])) {
                writeLog("convertFaktura: AR #{$newId} nicht auto-gebucht (" . ($postRes['reason'] ?? '?') . ")", true, DLOG_INF);
            }
        } catch (\Throwable $e) {
            writeLog("convertFaktura: Auto-Buchung AR #{$newId} fehlgeschlagen: " . $e->getMessage(), true, DLOG_ERR);
        }
    } elseif ($effectiveTarget === 'purchase_invoice') {
        try {
            $postRes = postApInvoiceToLedger($company, $newId);
            if (empty($postRes['posted'])) {
                writeLog("convertFaktura: AP #{$newId} nicht auto-gebucht (" . ($postRes['reason'] ?? '?') . ")", true, DLOG_INF);
            }
        } catch (\Throwable $e) {
            writeLog("convertFaktura: Auto-Buchung AP #{$newId} fehlgeschlagen: " . $e->getMessage(), true, DLOG_ERR);
        }
    }

    resultInfo(true, 'CONVERTED', ['id' => $newId, 'fakturaType' => $effectiveTarget]);

    } catch (Exception $e) {
        $company->rollBack();
        resultInfo(false, 'CONVERT_ERROR', $e->getMessage());
    }
}

/**
 * Importiert SilverDAT VXS-Positionen als Auftrags-/Rechnungspositionen (Bulk)
 *
 * Für jedes Item wird ein Artikel in der parts-Tabelle angelegt (oder ein
 * bestehender anhand der Teilenummer wiederverwendet) und eine Position
 * in der Faktura-Tabelle eingefügt.
 *
 * @param array $data Request-Daten
 * @param int    $data['fakturaID']            Faktura-ID
 * @param string $data['fakturaType']          z.B. 'order'
 * @param int    $data['buchungsgruppeTeile']  Buchungsgruppen-ID für Ersatzteile
 * @param int    $data['buchungsgruppeArbeit'] Buchungsgruppen-ID für Dienstleistungen
 * @param array  $data['items']                Array mit Items {description, partnumber, qty, sellprice, unit, part_type, longdescription}
 * @return void Gibt JSON mit allen angelegten Positionen zurück
 */
function importSilverDATItems($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $fakturaType = $data['fakturaType'] ?? 'order';
    $buchungsgruppeTeile = intval($data['buchungsgruppeTeile'] ?? 0);
    $buchungsgruppeArbeit = intval($data['buchungsgruppeArbeit'] ?? 0);
    $items = $data['items'] ?? [];

    if ($fakturaID <= 0) {
        resultInfo(false, 'INVALID_FAKTURA_ID', ['message' => 'Ungültige Faktura-ID']);
        return;
    }
    if (empty($items)) {
        resultInfo(false, 'NO_ITEMS', ['message' => 'Keine Positionen zum Importieren']);
        return;
    }
    if ($buchungsgruppeTeile <= 0 || $buchungsgruppeArbeit <= 0) {
        resultInfo(false, 'MISSING_BUCHUNGSGRUPPE', ['message' => 'Buchungsgruppen müssen angegeben werden']);
        return;
    }

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];
    $isInvoiceType = in_array($fakturaType, ['invoice', 'purchase_invoice']);

    // Nächste Position ermitteln
    $positionQuery = "SELECT COALESCE(MAX(position), 0) AS max_position FROM {$itemsTable} WHERE trans_id = :fakturaID";
    $nextPosition = intval($company->getOne($positionQuery, ['fakturaID' => $fakturaID])['max_position']);

    $createdIds = [];

    // Atomar importieren: schlägt eine einzige Position fehl, wird nichts
    // angelegt (kein Teil-Import, der den Beleg inkonsistent zuruecklaesst).
    $company->beginTransaction();
    try {

    foreach ($items as $item) {
        $nextPosition++;
        $description = trim($item['description'] ?? '');
        $partnumber = trim($item['partnumber'] ?? '');
        $partType = $item['part_type'] ?? 'service';
        $buchungsgruppenId = ($partType === 'part') ? $buchungsgruppeTeile : $buchungsgruppeArbeit;

        if (empty($description)) continue;

        // 1. Artikel finden oder anlegen
        $partsId = null;

        if (!empty($partnumber)) {
            // Bestehenden Artikel suchen
            $existing = $company->getOne(
                "SELECT id FROM parts WHERE partnumber = :pn LIMIT 1",
                ['pn' => $partnumber]
            );
            if ($existing) {
                $partsId = intval($existing['id']);
            }
        }

        if (!$partsId) {
            // Neuen Artikel anlegen
            if (!empty($partnumber)) {
                $partQuery = <<<SQL
                    INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, obsolete)
                    VALUES (:partnumber, :description, :part_type, :buchungsgruppen_id, :sellprice, :unit, FALSE)
                    RETURNING id
SQL;
                $partResult = $company->getOne($partQuery, [
                    'partnumber' => $partnumber,
                    'description' => $description,
                    'part_type' => $partType,
                    'buchungsgruppen_id' => $buchungsgruppenId,
                    'sellprice' => floatval($item['sellprice'] ?? 0),
                    'unit' => trim($item['unit'] ?? 'Stck')
                ]);
            } else {
                // Ohne Teilenummer → interne Nummer aus dem defaults-Nummernkreis.
                // Bewusst KEIN MAX(partnumber)-Scan: importierte Fremdnummern (z. B.
                // EANs) sollen den internen Zähler nicht hochreissen. nextFreeNumber
                // zählt defaults hoch und überspringt bereits vergebene Nummern.
                $numberField = ($partType === 'service') ? 'servicenumber' : 'articlenumber';
                $newNumber = nextFreeNumber($company, $numberField, 'parts', 'partnumber');

                $partQuery = <<<SQL
                    INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, obsolete)
                    VALUES (:partnumber, :description, :part_type, :buchungsgruppen_id, :sellprice, :unit, FALSE)
                    RETURNING id
SQL;
                $partResult = $company->getOne($partQuery, [
                    'partnumber' => $newNumber,
                    'description' => $description,
                    'part_type' => $partType,
                    'buchungsgruppen_id' => $buchungsgruppenId,
                    'sellprice' => floatval($item['sellprice'] ?? 0),
                    'unit' => trim($item['unit'] ?? 'Stck')
                ]);
            }
            $partsId = intval($partResult['id']);
        }

        // 2. Position einfügen
        $columns = 'trans_id, parts_id, position, description, longdescription, qty, sellprice, discount, unit';
        $values = ':trans_id, :parts_id, :position, :description, :longdescription, :qty, :sellprice, :discount, :unit';
        $params = [
            'trans_id' => $fakturaID,
            'parts_id' => $partsId,
            'position' => $nextPosition,
            'description' => $description,
            'longdescription' => trim($item['longdescription'] ?? ''),
            'qty' => floatval($item['qty'] ?? 1),
            'sellprice' => floatval($item['sellprice'] ?? 0),
            'discount' => 0,
            'unit' => trim($item['unit'] ?? '')
        ];

        if ($isInvoiceType) {
            $columns .= ', fxsellprice';
            $values .= ', :fxsellprice';
            $params['fxsellprice'] = floatval($item['sellprice'] ?? 0);
        }

        $insertResult = $company->getOne(
            "INSERT INTO {$itemsTable} ({$columns}) VALUES ({$values}) RETURNING id",
            $params
        );
        $createdIds[] = intval($insertResult['id']);
    }

        $company->commit();
    } catch (\Throwable $e) {
        $company->rollBack();
        resultInfo(false, 'IMPORT_FAILED', ['message' => 'Import fehlgeschlagen: ' . $e->getMessage()]);
        return;
    }

    if (empty($createdIds)) {
        resultInfo(false, 'NO_ITEMS_CREATED', ['message' => 'Keine Positionen angelegt']);
        return;
    }

    resultInfo(true, 'IMPORTED', ['count' => count($createdIds)]);
}
