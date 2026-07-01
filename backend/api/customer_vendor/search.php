<?php
// search.php

/**
 * Sucht nach Kunden, Lieferanten oder Kontakten mit optionalen WHERE-Bedingungen
 *
 * @param string $data['type'] Typ der Suche: customer, vendor oder contacts
 * @param array $data['where'] Optionale WHERE-Bedingungen als Array (optional)
 * @testdata {"type": "customer", "where": {"name": "Test"}}
 */
function searchCV($data) {
    $mandant = DbhCompany::begin();

    // Tabellenkonfiguration basierend auf kivitendo Struktur
    $tableConfig = [
        'customer' => [
            'table' => 'customer',
            'select' => "customer.*, 'C' AS src, customernumber AS cv_number",
            'numberField' => 'customernumber',
            'hasJoin' => false,
            'documents' => [
                'invoice' => [
                    'table' => 'ar',
                    'foreignKey' => 'customer_id',
                ],
                'quotation' => [
                    'table' => 'oe',
                    'foreignKey' => 'customer_id',
                    'condition' => "record_type = 'sales_quotation'",
                ],
                'order' => [
                    'table' => 'oe',
                    'foreignKey' => 'customer_id',
                    'condition' => "record_type IN ('sales_order', 'sales_order_intake')",
                ],
                'delivery_order' => [
                    'table' => 'delivery_orders',
                    'foreignKey' => 'customer_id',
                ],
            ],
        ],
        'vendor' => [
            'table' => 'vendor',
            'select' => "vendor.*, 'V' AS src, vendornumber AS cv_number",
            'numberField' => 'vendornumber',
            'hasJoin' => false,
            'documents' => [
                'invoice' => [
                    'table' => 'ap',
                    'foreignKey' => 'vendor_id',
                ],
                'quotation' => [
                    'table' => 'oe',
                    'foreignKey' => 'vendor_id',
                    'condition' => "record_type = 'request_quotation'",
                ],
                'order' => [
                    'table' => 'oe',
                    'foreignKey' => 'vendor_id',
                    'condition' => "record_type = 'purchase_order'",
                ],
                'delivery_order' => [
                    'table' => 'delivery_orders',
                    'foreignKey' => 'vendor_id',
                ],
            ],
        ],
        'contacts' => [
            'table' => 'contacts',
            'select' => "contacts.*, cp_id AS id, 'P' AS src",
            'numberField' => 'cv_number',
            'hasJoin' => true,
            'documents' => [],
        ],
    ];

    $type = $data['type'] ?? null;
    if (!isset($tableConfig[$type])) {
        throw new ApiError('API_INVALID_TYPE_FILTER', 'Invalid type specified');
    }

    $config = $tableConfig[$type];
    $table = $config['table'];
    $where = $data['where'] ?? [];

    // WHERE-Bedingungen zusammenbauen und benötigte JOINs erkennen
    $conditions = ['1=1'];
    $requiredJoins = [];
    $sqlQueryString = null; // Speichere SQL-Query für Fehlerausgabe

    if (!empty($where)) {
        if (is_array($where)) {
            foreach ($where as $key => $value) {
                // Prüfen welche JOINs benötigt werden
                foreach (['invoice', 'quotation', 'order', 'delivery_order'] as $docType) {
                    if (str_starts_with($key, $docType . '_')) {
                        $requiredJoins[$docType] = true;
                    }
                }

                $conditions[] = buildWhereCondition($key, $value, $table, $config);
            }
        } elseif (is_string($where)) {
            validateSqlString($where);
            // Falls der Benutzer ein Semikolon am Ende angefügt hat wird es entfernt
            $where = rtrim($where, ';');
            $sqlQueryString = $where; // Für Fehlerausgabe speichern
            $conditions[] = $where;
        }
    }

    $search = implode(' AND ', $conditions);

    // Pagination (-1 = alle Ergebnisse)
    $rawLimit = isset($data['limit']) ? intval($data['limit']) : 10;
    $limit = ($rawLimit === -1) ? 'ALL' : max(1, $rawLimit);
    $offset = isset($data['offset']) ? max(0, intval($data['offset'])) : 0;
    $sortBy = $data['sortBy'] ?? 'id';
    $sortOrder = (isset($data['sortOrder']) && strtolower($data['sortOrder']) === 'asc') ? 'ASC' : 'DESC';

    // Query je nach Tabellentyp und benötigten JOINs erstellen
    if ($config['hasJoin']) {
        $query = buildContactsQuery($config['select'], $search, $limit, $offset, $sortBy, $sortOrder);
    } else {
        $query = buildCustomerVendorQuery(
            $config['select'],
            $table,
            $search,
            $requiredJoins,
            $config,
            $limit,
            $offset,
            $sortBy,
            $sortOrder
        );
    }

    try {
        echo $mandant->get($query);
    } catch (Exception $e) {
        // SQL-Fehler abfangen und strukturiert zurückgeben
        $errorMessage = $e->getMessage();

        // Extrahiere PostgreSQL-Fehlercode falls vorhanden
        preg_match('/SQLSTATE\[(\w+)\]:\s*(.+)/', $errorMessage, $matches);
        $sqlState = $matches[1] ?? 'UNKNOWN';
        $sqlError = $matches[2] ?? $errorMessage;

        $errorResponse = [
            'sql_error' => true,
            'sql_state' => $sqlState,
            'error_message' => $sqlError,
            'query' => $sqlQueryString ?? 'Erstellt aus Suchkriterien',
            'full_error' => $errorMessage
        ];

        echo json_encode($errorResponse);
    }
}

/**
 * Erstellt WHERE-Bedingung basierend auf Feldtyp und Tabelle
 *
 * ⚠️ KRITISCHER HINWEIS ZU TABELLENPRÄFIXEN ⚠️
 *
 * CONTACTS vs CUSTOMER/VENDOR haben unterschiedliche SQL-Strukturen:
 *
 * CONTACTS-Query:
 *   SELECT * FROM (
 *       SELECT ... FROM contacts JOIN customer ...
 *       UNION ALL
 *       SELECT ... FROM contacts JOIN vendor ...
 *   ) AS combined
 *   WHERE <hier kommen die Bedingungen hin>
 *
 *   → WHERE-Bedingung steht NACH dem UNION
 *   → Es gibt KEINE Tabelle mehr namens "contacts" oder "customer" oder "vendor"
 *   → Es gibt nur noch die Spalten aus dem UNION-Result
 *   → DAHER: KEINE Tabellenpräfixe für contacts! Nur "itime", "mtime", "cp_name" etc.
 *
 * CUSTOMER/VENDOR-Query:
 *   SELECT ...
 *   FROM customer
 *   LEFT JOIN ar ON ...
 *   LEFT JOIN oe AS oe_quotation ON ...
 *   WHERE <hier kommen die Bedingungen hin>
 *
 *   → WHERE-Bedingung steht INNERHALB der Query
 *   → Mehrere Tabellen können die gleichen Spaltennamen haben (z.B. customer.itime, ar.itime)
 *   → DAHER: Tabellenpräfixe sind NOTWENDIG! "customer.itime", "customer.mtime" etc.
 *
 * REGEL:
 *   if ($table === 'contacts') → KEIN Präfix
 *   if ($table === 'customer' || $table === 'vendor') → MIT Präfix
 *
 * FEHLER DEN WIR IMMER WIEDER MACHEN:
 *   - Wir vergessen den Tabellenpräfix bei customer/vendor
 *   - PostgreSQL gibt dann "ERROR: column reference 'itime' is ambiguous"
 *   - Das passiert wenn mehrere Tabellen die gleiche Spalte haben
 *
 * @param string $key Feldname
 * @param mixed $value Feldwert
 * @param string $table Tabellenname (customer, vendor, contacts)
 * @param array $config Tabellenkonfiguration
 * @return string SQL WHERE-Bedingung
 */
function buildWhereCondition($key, $value, $table, $config) {
    // Fremdschlüssel-Felder
    if (str_ends_with($key, '_id')) {
        return "$key = " . intval($value);
    }

    // Datumsbereich - itime (Erstellungsdatum) für Haupt-Tabelle
    // WICHTIG: Contacts braucht KEINEN Tabellenpräfix weil die WHERE-Bedingung NACH dem UNION kommt!
    // Customer/Vendor brauchen Tabellenpräfix weil mehrere Tabellen im FROM stehen können!
    if ($key === 'itime_from') {
        return $table === 'contacts'
            ? "itime::date >= '$value'"
            : "$table.itime::date >= '$value'";
    }
    if ($key === 'itime_to') {
        return $table === 'contacts'
            ? "itime::date <= '$value'"
            : "$table.itime::date <= '$value'";
    }

    // Datumsbereich - mtime (Änderungsdatum) für Haupt-Tabelle
    // WICHTIG: Contacts braucht KEINEN Tabellenpräfix weil die WHERE-Bedingung NACH dem UNION kommt!
    // Customer/Vendor brauchen Tabellenpräfix weil mehrere Tabellen im FROM stehen können!
    if ($key === 'mtime_from') {
        return $table === 'contacts'
            ? "(mtime IS NULL OR mtime::date >= '$value')"
            : "($table.mtime IS NULL OR $table.mtime::date >= '$value')";
    }
    if ($key === 'mtime_to') {
        return $table === 'contacts'
            ? "(mtime IS NULL OR mtime::date <= '$value')"
            : "($table.mtime IS NULL OR $table.mtime::date <= '$value')";
    }

    // Rechnungs-Datumsfelder (ar für customer, ap für vendor)
    if ($key === 'invoice_itime_from') {
        $invoiceTable = $table === 'customer' ? 'ar' : 'ap';
        return "$invoiceTable.itime::date >= '$value'";
    }
    if ($key === 'invoice_itime_to') {
        $invoiceTable = $table === 'customer' ? 'ar' : 'ap';
        return "$invoiceTable.itime::date <= '$value'";
    }
    if ($key === 'invoice_mtime_from') {
        $invoiceTable = $table === 'customer' ? 'ar' : 'ap';
        return "($invoiceTable.mtime IS NULL OR $invoiceTable.mtime::date >= '$value')";
    }
    if ($key === 'invoice_mtime_to') {
        $invoiceTable = $table === 'customer' ? 'ar' : 'ap';
        return "($invoiceTable.mtime IS NULL OR $invoiceTable.mtime::date <= '$value')";
    }

    // Angebots-Datumsfelder (oe mit record_type sales_quotation/request_quotation)
    if ($key === 'quotation_itime_from') {
        return "oe_quotation.itime::date >= '$value'";
    }
    if ($key === 'quotation_itime_to') {
        return "oe_quotation.itime::date <= '$value'";
    }
    if ($key === 'quotation_mtime_from') {
        return "(oe_quotation.mtime IS NULL OR oe_quotation.mtime::date >= '$value')";
    }
    if ($key === 'quotation_mtime_to') {
        return "(oe_quotation.mtime IS NULL OR oe_quotation.mtime::date <= '$value')";
    }

    // Auftrags-Datumsfelder (oe mit record_type sales_order/purchase_order)
    if ($key === 'order_itime_from') {
        return "oe_order.itime::date >= '$value'";
    }
    if ($key === 'order_itime_to') {
        return "oe_order.itime::date <= '$value'";
    }
    if ($key === 'order_mtime_from') {
        return "(oe_order.mtime IS NULL OR oe_order.mtime::date >= '$value')";
    }
    if ($key === 'order_mtime_to') {
        return "(oe_order.mtime IS NULL OR oe_order.mtime::date <= '$value')";
    }

    // Lieferschein-Datumsfelder
    if ($key === 'delivery_order_itime_from') {
        return "delivery_orders.itime::date >= '$value'";
    }
    if ($key === 'delivery_order_itime_to') {
        return "delivery_orders.itime::date <= '$value'";
    }
    if ($key === 'delivery_order_mtime_from') {
        return "(delivery_orders.mtime IS NULL OR delivery_orders.mtime::date >= '$value')";
    }
    if ($key === 'delivery_order_mtime_to') {
        return "(delivery_orders.mtime IS NULL OR delivery_orders.mtime::date <= '$value')";
    }

    // Datumsbereich - cp_birthday (Geburtsdatum)
    if ($key === 'cp_birthday') {
        return "cp_birthday = '$value'";
    }
    if ($key === 'cp_birthday_from') {
        return "(cp_birthday IS NULL OR cp_birthday >= '$value')";
    }
    if ($key === 'cp_birthday_to') {
        return "(cp_birthday IS NULL OR cp_birthday <= '$value')";
    }

    // Tabellenspezifische Bedingungen (müssen vor generischen Typ-Prüfungen kommen)
    if ($table === 'customer' || $table === 'vendor') {
        $condition = match($key) {
            'number' => "$table.{$table}number ILIKE '%$value%'",
            'phone' => "($table.phone ILIKE '%$value%' OR $table.fax ILIKE '%$value%')",
            'department' => "($table.department_1 ILIKE '%$value%' OR $table.department_2 ILIKE '%$value%')",
            'dunning_lock' => "$table.dunning_lock IS " . ($value === 'yes' ? "TRUE" : "FALSE"),
            'zugferd' => "$table.create_zugferd_invoices = " . intval($value),
            'obsolete' => "$table.obsolete IS " . ($value ? "TRUE" : "FALSE"),
            default => null,
        };
        if ($condition !== null) {
            return $condition;
        }
    }

    if ($table === 'contacts') {
        $condition = match($key) {
            'phone' => "(cp_phone1 ILIKE '%$value%' OR cp_phone2 ILIKE '%$value%' OR cp_fax ILIKE '%$value%' OR cp_mobile1 ILIKE '%$value%' OR cp_mobile2 ILIKE '%$value%' OR cp_privatphone ILIKE '%$value%' OR cp_satphone ILIKE '%$value%' OR cp_satfax ILIKE '%$value%')",
            'number' => "cv_number ILIKE '%$value%'",
            'email' => "(cp_email ILIKE '%$value%' OR cp_privatemail ILIKE '%$value%')",
            default => null,
        };
        if ($condition !== null) {
            return $condition;
        }
    }

    // Boolean-Felder
    if (is_bool($value)) {
        return "$key IS " . ($value ? "TRUE" : "FALSE");
    }

    // Numerische Felder - NUR für echte Integer-Felder, NICHT für Text-Felder wie zipcode
    // Bekannte Integer-Felder in kivitendo: id, salesman_id, business_id, language_id, etc.
    $integerFields = ['id', 'salesman_id', 'business_id', 'language_id', 'currency_id',
                      'delivery_term_id', 'taxzone_id', 'payment_id', 'pricegroup_id',
                      'creditlimit', 'discount', 'hourly_rate'];

    if (is_numeric($value) && in_array($key, $integerFields)) {
        if ($table === 'contacts') {
            return "$key = " . $value;
        }
        return "$table.$key = " . $value;
    }

    // Standard: String ILIKE - für Contacts OHNE Tabellenpräfix
    if ($table === 'contacts') {
        return "$key ILIKE '%$value%'";
    }

    return "$table.$key ILIKE '%$value%'";
}

/**
 * Validiert SQL-String auf gefährliche Verben
 *
 * @param string $sql Zu validierender SQL-String
 * @throws ApiError wenn gefährliche SQL-Verben gefunden werden
 */
function validateSqlString($sql) {
    $dangerousVerbs = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'GRANT', 'REVOKE', 'EXECUTE', 'EXEC', 'CALL',
        'MERGE', 'RENAME', 'COMMIT', 'ROLLBACK', 'SAVEPOINT'
    ];

    $pattern = '/\b(' . implode('|', array_map('preg_quote', $dangerousVerbs)) . ')\b/i';

    if (preg_match($pattern, $sql) === 1) {
        throw new ApiError('API_INVALID_SQL_VERBS', 'Invalid sql verbs in where clause');
    }
}

/**
 * Erstellt Query für customer/vendor Tabellen mit optionalen Document-JOINs
 *
 * Verwendet kivitendo Tabellen:
 * - ar: Debitorenrechnungen (customer invoices)
 * - ap: Kreditorenrechnungen (vendor invoices)
 * - oe: Order Entry (Aufträge und Angebote, unterschieden durch record_type)
 *   - sales_quotation: Kundenangebote
 *   - sales_order: Kundenaufträge
 *   - request_quotation: Lieferantenanfragen
 *   - purchase_order: Lieferantenbestellungen
 * - delivery_orders: Lieferscheine
 *
 * @param string $selectFields Komma-getrennte Liste der zu selektierenden Felder
 * @param string $table Tabellenname (customer oder vendor)
 * @param string $search WHERE-Bedingungen
 * @param array $requiredJoins Assoziatives Array mit benötigten JOINs
 * @param array $config Tabellenkonfiguration
 * @return string Vollständige SQL-Query
 */
function buildCustomerVendorQuery($selectFields, $table, $search, $requiredJoins, $config, $limit = 10, $offset = 0, $sortCol = 'id', $sortOrder = 'DESC') {
    $joins = "";

    // Invoice JOIN (ar für customer, ap für vendor)
    if (isset($requiredJoins['invoice'])) {
        $docConfig = $config['documents']['invoice'];
        $joins .= "\n    LEFT JOIN {$docConfig['table']} ON $table.id = {$docConfig['table']}.{$docConfig['foreignKey']}";
    }

    // Quotation JOIN (oe mit record_type)
    if (isset($requiredJoins['quotation'])) {
        $docConfig = $config['documents']['quotation'];
        $foreignKey = $table === 'customer' ? 'customer_id' : 'vendor_id';
        $recordType = $table === 'customer' ? 'sales_quotation' : 'request_quotation';
        $joins .= "\n    LEFT JOIN oe AS oe_quotation ON $table.id = oe_quotation.$foreignKey AND oe_quotation.record_type = '$recordType'";
    }

    // Order JOIN (oe mit record_type)
    if (isset($requiredJoins['order'])) {
        $docConfig = $config['documents']['order'];
        $foreignKey = $table === 'customer' ? 'customer_id' : 'vendor_id';
        $recordType = $table === 'customer' ? 'sales_order' : 'purchase_order';
        $joins .= "\n    LEFT JOIN oe AS oe_order ON $table.id = oe_order.$foreignKey AND oe_order.record_type = '$recordType'";
    }

    // Delivery Order JOIN (delivery_orders)
    if (isset($requiredJoins['delivery_order'])) {
        $docConfig = $config['documents']['delivery_order'];
        $joins .= "\n    LEFT JOIN {$docConfig['table']} ON $table.id = {$docConfig['table']}.{$docConfig['foreignKey']}";
    }

    // DISTINCT bei JOINs verwenden um Duplikate zu vermeiden
    $distinct = !empty($requiredJoins) ? 'DISTINCT ' : '';

    return <<<SQL
        SELECT json_build_object(
            'results',
            (
                SELECT json_agg(r)
                FROM (
                    SELECT *
                    FROM (
                        SELECT $distinct$selectFields
                        FROM $table$joins
                        WHERE $search
                    ) AS search_data
                    ORDER BY $sortCol $sortOrder
                    LIMIT $limit OFFSET $offset
                ) AS r
            ),
            'total',
            (
                SELECT COUNT(*)
                FROM (
                    SELECT $distinct$table.id
                    FROM $table$joins
                    WHERE $search
                ) AS cnt
            )
        ) AS search
    SQL;
}

/**
 * Erstellt Contacts-Query mit Customer/Vendor-Joins
 * Erstellt zuerst die UNION mit cv_number, wendet dann WHERE-Bedingungen außerhalb an
 *
 * @param string $selectFields Komma-getrennte Liste der zu selektierenden Felder
 * @param string $search WHERE-Bedingungen
 * @return string Vollständige SQL-Query
 */
function buildContactsQuery($selectFields, $search, $limit = 10, $offset = 0, $sortCol = 'id', $sortOrder = 'DESC') {
    return <<<SQL
        SELECT json_build_object(
            'results',
            (
                SELECT json_agg(r)
                FROM (
                    SELECT * FROM (
                        SELECT 'C' AS cv_type, cu.name AS cv_name, cu.customernumber AS cv_number, $selectFields
                        FROM contacts
                        JOIN customer AS cu ON contacts.cp_cv_id = cu.id

                        UNION ALL

                        SELECT 'V' AS cv_type, ve.name AS cv_name, ve.vendornumber AS cv_number, $selectFields
                        FROM contacts
                        JOIN vendor AS ve ON contacts.cp_cv_id = ve.id
                    ) AS combined
                    WHERE $search
                    ORDER BY $sortCol $sortOrder
                    LIMIT $limit OFFSET $offset
                ) AS r
            ),
            'total',
            (
                SELECT COUNT(*) FROM (
                    SELECT 'C' AS cv_type, cu.name AS cv_name, cu.customernumber AS cv_number, $selectFields
                    FROM contacts
                    JOIN customer AS cu ON contacts.cp_cv_id = cu.id

                    UNION ALL

                    SELECT 'V' AS cv_type, ve.name AS cv_name, ve.vendornumber AS cv_number, $selectFields
                    FROM contacts
                    JOIN vendor AS ve ON contacts.cp_cv_id = ve.id
                ) AS combined
                WHERE $search
            )
        ) AS search
    SQL;
}

/**
 * Gibt alle verfügbaren Spalten für eine Tabelle zurück
 *
 * @param array $data Array mit 'type' (customer|vendor|contacts)
 * @return string JSON-String mit Spaltenliste
 * @throws ApiError wenn ungültiger Type angegeben wurde
 */
/**
 * Gibt alle Spaltennamen einer Tabelle zurück
 *
 * @param string $data['type'] Tabellentyp: customer, vendor oder contacts
 * @testdata {"type": "customer"}
 */
function getTableColumns($data) {
    $mandant = DbhCompany::begin();

    $table_map = [
        'customer' => 'customer',
        'vendor' => 'vendor',
        'contacts' => 'contacts'
    ];

    $type = $data['type'] ?? null;
    if (!isset($table_map[$type])) {
        throw new ApiError('API_INVALID_TYPE_FILTER', 'Invalid type specified');
    }

    $table = $table_map[$type];

    $query = <<<SQL
        SELECT json_build_object(
            'columns',
            (
                SELECT json_agg(column_name ORDER BY ordinal_position)
                FROM information_schema.columns
                WHERE table_schema = 'public'
                AND table_name = '$table'
            ),
            'table', '$table'
        ) AS columns
    SQL;

    echo $mandant->get($query);
}

/**
 * Speichert ALLE SQL-Queries für den eingeloggten Benutzer (kompletter Replace)
 *
 * @param array $data['queries'] Komplettes Array mit allen SQL-Queries
 * @param int $data['employee_id'] ID des Mitarbeiters
 * @testdata {"queries": [{"id": "1", "name": "Test Query", "query": "SELECT * FROM customer", "type": "customer"}], "employee_id": 1}
 */
function updateSavedSqlQueries($data) {
    $mandant = DbhCompany::begin();

    $employee_id = $data['employee_id'] ?? null;

    if (!$employee_id) {
        throw new ApiError('API_NOT_AUTHENTICATED', 'User not authenticated');
    }

    $queries = $data['queries'] ?? [];

    if (!is_array($queries)) {
        throw new ApiError('API_INVALID_PARAMETERS', 'Queries must be an array');
    }

    // JSON encode im PHP (nur hier, weil vom Frontend kommt)
    $jsonQueries = json_encode($queries);
    $escapedJson = pg_escape_string($jsonQueries);

    //writeLog("DEBUG updateSavedSqlQueries - employee_id: $employee_id, count: " . count($queries));

    $query = <<<SQL
        INSERT INTO employee_config_oserp (employee_id, key, value, itime)
        VALUES ($employee_id, 'saved_sql_queries', '$escapedJson', NOW())
        ON CONFLICT (employee_id, key)
        DO UPDATE SET value = '$escapedJson', mtime = NOW()
        RETURNING json_build_object(
            'success', true,
            'message', 'Queries updated successfully'
        ) AS result
    SQL;

    try {
        echo $mandant->get($query);
    } catch (Exception $e) {
        //writeLog("DEBUG updateSavedSqlQueries - ERROR: " . $e->getMessage());
        throw new ApiError('API_DATABASE_ERROR', 'Failed to update queries: ' . $e->getMessage());
    }
}

/**
 * Lädt alle gespeicherten SQL-Queries des eingeloggten Benutzers
 *
 * @param int $data['employee_id'] ID des Mitarbeiters
 * @param string $data['type'] Optionaler Filter nach Typ (optional)
 * @testdata {"employee_id": 1, "type": "customer"}
 */
function getSavedSqlQueries($data) {
    $mandant = DbhCompany::begin();

    $employee_id = $data['employee_id'] ?? null;

    if (!$employee_id) {
        throw new ApiError('API_NOT_AUTHENTICATED', 'User not authenticated');
    }

    $type = $data['type'] ?? null;

    //writeLog("DEBUG getSavedSqlQueries - employee_id: $employee_id, type: " . ($type ?? 'null'));

    // Optional Type-Filter
    $typeFilter = '';
    if ($type) {
        $escapedType = pg_escape_string($type);
        $typeFilter = " AND elem->>'type' = '$escapedType'";
    }

    $query = <<<SQL
        SELECT json_build_object(
            'success', true,
            'queries', COALESCE(
                (
                    SELECT json_agg(elem ORDER BY (elem->>'created_at')::timestamp DESC)
                    FROM employee_config_oserp,
                    LATERAL json_array_elements(
                        CASE
                            WHEN value IS NULL OR value = '' THEN '[]'::json
                            ELSE value::json
                        END
                    ) AS elem
                    WHERE employee_id = $employee_id
                    AND key = 'saved_sql_queries'
                    $typeFilter
                ),
                '[]'::json
            )
        ) AS result
    SQL;

    try {
        $result = $mandant->get($query);
        //writeLog("DEBUG getSavedSqlQueries - Result: " . $result);
        echo $result;
    } catch (Exception $e) {
        //writeLog("DEBUG getSavedSqlQueries - ERROR: " . $e->getMessage());
        throw new ApiError('API_DATABASE_ERROR', 'Failed to load queries: ' . $e->getMessage());
    }
}

/**
 * Löscht eine gespeicherte SQL-Query
 *
 * @param string $data['id'] ID der zu löschenden Query
 * @param int $data['employee_id'] ID des Mitarbeiters
 * @testdata {"id": "query-123", "employee_id": 1}
 */
function deleteSqlQuery($data) {
    $mandant = DbhCompany::begin();

    $employee_id = $data['employee_id'] ?? null;

    if (!$employee_id) {
        throw new ApiError('API_NOT_AUTHENTICATED', 'User not authenticated');
    }

    $queryId = $data['id'] ?? null;
    if (!$queryId) {
        throw new ApiError('API_MISSING_PARAMETERS', 'Query ID is required');
    }

    // Hole bestehende Queries
    $existingQuery = <<<SQL
        SELECT value
        FROM employee_config_oserp
        WHERE employee_id = $employee_id
        AND key = 'saved_sql_queries'
    SQL;

    $result = $mandant->get($existingQuery);
    $decoded = json_decode($result, true);

    if (empty($decoded) || !isset($decoded[0]['value'])) {
        throw new ApiError('API_NOT_FOUND', 'No saved queries found');
    }

    $savedQueries = json_decode($decoded[0]['value'], true) ?? [];

    // Query entfernen
    $savedQueries = array_filter($savedQueries, function($q) use ($queryId) {
        return $q['id'] !== $queryId;
    });
    $savedQueries = array_values($savedQueries); // Re-index

    // Zurück speichern
    $jsonQueries = json_encode($savedQueries);
    $escapedJson = pg_escape_string($jsonQueries);

    $updateQuery = <<<SQL
        UPDATE employee_config_oserp
        SET value = '$escapedJson', mtime = NOW()
        WHERE employee_id = $employee_id
        AND key = 'saved_sql_queries'
    SQL;

    try {
        $mandant->query($updateQuery);

        echo json_encode([
            'success' => true,
            'message' => 'Query deleted successfully'
        ]);
    } catch (Exception $e) {
        throw new ApiError('API_DATABASE_ERROR', 'Failed to delete query: ' . $e->getMessage());
    }
}

/**
 * Sucht nach Faktura-Dokumenten (Rechnungen, Angebote, Aufträge, Bestellungen, Lieferscheine)
 *
 * @param string $data['type'] Dokumenttyp: invoice, purchase_invoice, quotation, order, purchase_order, delivery_order
 * @param array $data['where'] Optionale Filter als Array:
 *   - document_number: ILIKE auf Dokumentnummer
 *   - cv_name: ILIKE auf Kunden-/Lieferantenname
 *   - transdate_from / transdate_to: Datumsbereich
 *   - amount_from / amount_to: Betragsbereich
 *   - status: 'open' oder 'closed'
 * @testdata {"type": "invoice", "where": {"document_number": "1"}}
 */
function searchDocuments($data) {
    $mandant = DbhCompany::begin();

    $typeConfig = [
        'invoice' => [
            'table' => 'ar',
            'number_field' => 'invnumber',
            'cv_table' => 'customer',
            'cv_fk' => 'customer_id',
            'cv_number_field' => 'customernumber',
            'status_expr' => 'ar.amount - ar.paid',
            'faktura_route' => 'invoice',
            'cv_src' => 'C',
        ],
        'purchase_invoice' => [
            'table' => 'ap',
            'number_field' => 'invnumber',
            'cv_table' => 'vendor',
            'cv_fk' => 'vendor_id',
            'cv_number_field' => 'vendornumber',
            'status_expr' => 'ap.amount - ap.paid',
            'faktura_route' => 'purchase_invoice',
            'cv_src' => 'V',
        ],
        'quotation' => [
            'table' => 'oe',
            'number_field' => 'quonumber',
            'cv_table' => 'customer',
            'cv_fk' => 'customer_id',
            'cv_number_field' => 'customernumber',
            'record_types' => "'sales_quotation'",
            'status_field' => 'closed',
            'faktura_route' => 'quotation',
            'cv_src' => 'C',
        ],
        'order' => [
            'table' => 'oe',
            'number_field' => 'ordnumber',
            'cv_table' => 'customer',
            'cv_fk' => 'customer_id',
            'cv_number_field' => 'customernumber',
            'record_types' => "'sales_order', 'sales_order_intake'",
            'status_field' => 'closed',
            'faktura_route' => 'order',
            'cv_src' => 'C',
        ],
        'purchase_order' => [
            'table' => 'oe',
            'number_field' => 'ordnumber',
            'cv_table' => 'vendor',
            'cv_fk' => 'vendor_id',
            'cv_number_field' => 'vendornumber',
            'record_types' => "'purchase_order', 'purchase_order_confirmation'",
            'status_field' => 'closed',
            'faktura_route' => 'purchase_order',
            'cv_src' => 'V',
        ],
        'delivery_order' => [
            'table' => 'delivery_orders',
            'number_field' => 'donumber',
            'cv_table' => 'customer',
            'cv_fk' => 'customer_id',
            'cv_number_field' => 'customernumber',
            'record_types' => "'sales_delivery_order', 'purchase_delivery_order'",
            'status_field' => 'closed',
            'faktura_route' => 'delivery_order',
            'cv_src' => 'C',
        ],
    ];

    $type = $data['type'] ?? null;
    if (!isset($typeConfig[$type])) {
        throw new ApiError('API_INVALID_TYPE_FILTER', 'Invalid document type specified');
    }

    $cfg = $typeConfig[$type];
    $tbl = $cfg['table'];
    $where = $data['where'] ?? [];

    $conditions = ["1=1"];
    $params = [];
    $paramIndex = 0;

    if (!empty($where) && is_array($where)) {
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

        // Betrag von/bis
        if (isset($where['amount_from']) && $where['amount_from'] !== '') {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "$tbl.amount >= $paramName";
            $params[$paramName] = floatval($where['amount_from']);
        }
        if (isset($where['amount_to']) && $where['amount_to'] !== '') {
            $paramIndex++;
            $paramName = ":p$paramIndex";
            $conditions[] = "$tbl.amount <= $paramName";
            $params[$paramName] = floatval($where['amount_to']);
        }

        // Status (offen/geschlossen)
        if (!empty($where['status'])) {
            if (isset($cfg['status_expr'])) {
                // AR/AP: offen = amount - paid > 0
                if ($where['status'] === 'open') {
                    $conditions[] = "({$cfg['status_expr']}) > 0.01";
                } elseif ($where['status'] === 'closed') {
                    $conditions[] = "({$cfg['status_expr']}) <= 0.01";
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
    }

    // Record-Type-Filter für oe und delivery_orders
    $recordTypeFilter = '';
    if (isset($cfg['record_types'])) {
        $recordTypeFilter = "AND $tbl.record_type IN ({$cfg['record_types']})";
    }

    $search = implode(' AND ', $conditions);

    // Betrag-Select: delivery_orders hat kein amount-Feld
    $amountSelect = $tbl === 'delivery_orders'
        ? "NULL::numeric AS amount"
        : "$tbl.amount";

    // Status-Select
    if (isset($cfg['status_expr'])) {
        $statusSelect = "CASE WHEN ({$cfg['status_expr']}) > 0.01 THEN 'open' ELSE 'closed' END AS doc_status";
    } elseif (isset($cfg['status_field'])) {
        $statusSelect = "CASE WHEN $tbl.{$cfg['status_field']} IS TRUE THEN 'closed' ELSE 'open' END AS doc_status";
    } else {
        $statusSelect = "'unknown' AS doc_status";
    }

    // Pagination (-1 = alle Ergebnisse)
    $rawLimit = isset($data['limit']) ? intval($data['limit']) : 10;
    $limit = ($rawLimit === -1) ? 'ALL' : max(1, $rawLimit);
    $offset = isset($data['offset']) ? max(0, intval($data['offset'])) : 0;

    // Sortierung
    $sortKey = $data['sortBy'] ?? 'id';
    $sortOrder = (isset($data['sortOrder']) && strtolower($data['sortOrder']) === 'asc') ? 'ASC' : 'DESC';
    $allowedSortKeys = ['id', 'document_number', 'cv_name', 'cv_number', 'transdate', 'amount', 'doc_status', 'itime'];
    if (!in_array($sortKey, $allowedSortKeys)) $sortKey = 'id';
    // document_number ist ein Alias — auf die echte Spalte mappen
    $sortCol = ($sortKey === 'document_number') ? "$tbl.{$cfg['number_field']}" : (in_array($sortKey, ['cv_name', 'cv_number']) ? "cv.$sortKey" : "$tbl.$sortKey");
    if ($sortKey === 'doc_status') $sortCol = "doc_status";

    // Gesamtanzahl
    $countQuery = <<<SQL
        SELECT COUNT(*) AS total
        FROM $tbl
        LEFT JOIN {$cfg['cv_table']} AS cv ON cv.id = $tbl.{$cfg['cv_fk']}
        WHERE $search
        $recordTypeFilter
    SQL;

    $query = <<<SQL
        SELECT
            $tbl.id,
            $tbl.{$cfg['number_field']} AS document_number,
            cv.name AS cv_name,
            cv.{$cfg['cv_number_field']} AS cv_number,
            cv.id AS cv_id,
            '{$cfg['cv_src']}' AS cv_src,
            $tbl.transdate,
            $amountSelect,
            $statusSelect,
            $tbl.itime
        FROM $tbl
        LEFT JOIN {$cfg['cv_table']} AS cv ON cv.id = $tbl.{$cfg['cv_fk']}
        WHERE $search
        $recordTypeFilter
        ORDER BY $sortCol $sortOrder
        LIMIT $limit OFFSET $offset
    SQL;

    try {
        $countResult = $mandant->getOne($countQuery, $params);
        $total = intval($countResult['total']);
        $results = $mandant->getAll($query, $params);
        resultInfo(true, '', ['results' => $results, 'total' => $total]);
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

/**
 * Sucht Fahrzeuge (nur wenn lxcars Feature aktiv)
 *
 * @param array $data['where'] Suchkriterien (optional)
 * @param int $data['limit'] Einträge pro Seite (optional, default 10, -1 = alle)
 * @param int $data['offset'] Offset (optional, default 0)
 * @param string $data['sortBy'] Sortierspalte (optional, default 'c_id')
 * @param string $data['sortOrder'] Sortierrichtung (optional, default 'desc')
 * @testdata {"where": {"c_ln": "B"}}
 */
function searchVehicles($data) {
    $db = DbhCompany::begin();

    $where = $data['where'] ?? [];
    $conditions = ["1=1"];
    $params = [];
    $i = 0;

    if (!empty($where) && is_array($where)) {
        if (!empty($where['c_ln'])) {
            $i++; $conditions[] = "cl.c_ln ILIKE :p$i"; $params[":p$i"] = '%' . $where['c_ln'] . '%';
        }
        if (!empty($where['c_fin'])) {
            $i++; $conditions[] = "cl.c_fin ILIKE :p$i"; $params[":p$i"] = '%' . $where['c_fin'] . '%';
        }
        if (!empty($where['owner_name'])) {
            $i++; $conditions[] = "cu.name ILIKE :p$i"; $params[":p$i"] = '%' . $where['owner_name'] . '%';
        }
        if (!empty($where['hersteller'])) {
            $i++; $conditions[] = "kba.hersteller ILIKE :p$i"; $params[":p$i"] = '%' . $where['hersteller'] . '%';
        }
        if (!empty($where['modell'])) {
            $i++; $conditions[] = "kba.name ILIKE :p$i"; $params[":p$i"] = '%' . $where['modell'] . '%';
        }
    }

    $search = implode(' AND ', $conditions);

    // Pagination
    $rawLimit = isset($data['limit']) ? intval($data['limit']) : 10;
    $limit = ($rawLimit === -1) ? 'ALL' : max(1, $rawLimit);
    $offset = isset($data['offset']) ? max(0, intval($data['offset'])) : 0;

    $sortKey = $data['sortBy'] ?? 'c_id';
    $sortOrder = (isset($data['sortOrder']) && strtolower($data['sortOrder']) === 'asc') ? 'ASC' : 'DESC';
    $allowedSortKeys = ['c_id', 'c_ln', 'c_fin', 'owner_name', 'hersteller', 'modell', 'c_hu', 'c_it'];
    if (!in_array($sortKey, $allowedSortKeys)) $sortKey = 'c_id';

    $countQuery = <<<SQL
        SELECT COUNT(*) AS total
        FROM cars_lxcars cl
        LEFT JOIN customer cu ON cu.id = cl.c_ow
        LEFT JOIN kba_lxcars kba ON kba.id = cl.kba_id
        WHERE $search
    SQL;

    $query = <<<SQL
        SELECT
            cl.c_id,
            cl.c_ln,
            cl.c_fin,
            COALESCE(kba.hersteller, '') AS hersteller,
            COALESCE(kba.name, '') AS modell,
            cu.name AS owner_name,
            cu.id AS owner_id,
            cl.c_hu,
            cl.c_it
        FROM cars_lxcars cl
        LEFT JOIN customer cu ON cu.id = cl.c_ow
        LEFT JOIN kba_lxcars kba ON kba.id = cl.kba_id
        WHERE $search
        ORDER BY $sortKey $sortOrder
        LIMIT $limit OFFSET $offset
    SQL;

    try {
        $countResult = $db->getOne($countQuery, $params);
        $total = intval($countResult['total']);
        $results = $db->getAll($query, $params);
        resultInfo(true, '', ['results' => $results, 'total' => $total]);
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        preg_match('/SQLSTATE\[(\w+)\]:\s*(.+)/', $errorMessage, $matches);
        echo json_encode([
            'sql_error' => true,
            'sql_state' => $matches[1] ?? 'UNKNOWN',
            'error_message' => $matches[2] ?? $errorMessage,
            'full_error' => $errorMessage
        ]);
    }
}