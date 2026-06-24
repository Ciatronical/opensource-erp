<?php
/**
 * Lädt die komplette Company-Config ohne Session-Reload und CV-Daten.
 * Wird nach Config-Mutationen aufgerufen statt restoreSession().
 *
 * @testdata {}
 */
function getCompanyConfig($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    $login = pg_escape_string($auth->getLogin());
    $db = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'company_config', (
                SELECT json_build_object(
                    'features', (
                        SELECT json_agg(feature)
                        FROM (SELECT value FROM defaults_oserp WHERE key = 'features') AS feature
                    ),
                    'defaults', (
                        SELECT row_to_json(config)
                        FROM (SELECT * FROM defaults) AS config
                    ),
                    'defaults_oserp', (
                        -- aag_online_token* sind serverseitiger Token-Cache, nie an Clients ausliefern
                        SELECT json_object_agg(key, value) FROM defaults_oserp
                        WHERE key NOT IN ('aag_online_token', 'aag_online_token_exp')
                    ),
                    'business_types', (
                        SELECT json_agg(business) FROM (SELECT * FROM business) AS business
                    ),
                    'delivery_terms', (
                        SELECT json_agg(delivery) FROM (SELECT * FROM delivery_terms) AS delivery
                    ),
                    'currencies', (
                        SELECT json_agg(currency) FROM (SELECT * FROM currencies) AS currency
                    ),
                    'languages', (
                        SELECT json_agg(language)
                        FROM (SELECT * FROM language WHERE obsolete = false ORDER BY id ASC) AS language
                    ),
                    'payment_terms', (
                        SELECT json_agg(payment_terms)
                        FROM (SELECT * FROM payment_terms ORDER BY sortkey) AS payment_terms
                    ),
                    'employees', (
                        SELECT json_agg(employees)
                        FROM (
                            SELECT id, name, login
                            FROM employee WHERE deleted = false ORDER BY name ASC
                        ) AS employees
                    ),
                    'tax', (
                        SELECT json_agg(tax)
                        FROM (
                            SELECT
                                t.id, t.taxkey, t.taxdescription, t.rate, t.chart_id,
                                t.chart_categories, t.skonto_sales_chart_id,
                                t.skonto_purchase_chart_id, t.reverse_charge_chart_id,
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
                    'tax_zones', (
                        SELECT json_agg(tax_zones)
                        FROM (
                            SELECT
                                tz.id, tz.description, tz.obsolete, tz.sortkey,
                                EXISTS(
                                    SELECT 1 FROM customer WHERE taxzone_id = tz.id
                                    UNION ALL SELECT 1 FROM vendor WHERE taxzone_id = tz.id
                                    UNION ALL SELECT 1 FROM ar WHERE taxzone_id = tz.id
                                    UNION ALL SELECT 1 FROM ap WHERE taxzone_id = tz.id
                                    UNION ALL SELECT 1 FROM oe WHERE taxzone_id = tz.id
                                    UNION ALL SELECT 1 FROM delivery_orders WHERE taxzone_id = tz.id
                                    LIMIT 1
                                ) as used
                            FROM tax_zones tz
                            WHERE tz.obsolete = false
                            ORDER BY tz.sortkey
                        ) AS tax_zones
                    ),
                    'taxzone_charts', (
                        SELECT json_agg(taxzone_charts)
                        FROM (SELECT * FROM taxzone_charts) AS taxzone_charts
                    ),
                    'chart', (
                        SELECT json_agg(chart)
                        FROM (SELECT * FROM chart ORDER BY accno) AS chart
                    ),
                    'buchungsgruppen', (
                        SELECT json_agg(buchungsgruppen)
                        FROM (
                            SELECT bg.*, COUNT(p.id) > 0 as used
                            FROM buchungsgruppen bg
                            LEFT JOIN parts p ON p.buchungsgruppen_id = bg.id
                            WHERE bg.obsolete = false
                            GROUP BY bg.id, bg.description, bg.inventory_accno_id, bg.sortkey, bg.obsolete
                            ORDER BY bg.sortkey, bg.id
                        ) AS buchungsgruppen
                    ),
                    'pricegroups', (
                        SELECT json_agg(pricegroups)
                        FROM (SELECT * FROM pricegroup WHERE obsolete = false ORDER BY sortkey) AS pricegroups
                    ),
                    'department', (
                        SELECT json_agg(department) FROM (SELECT * FROM department) AS department
                    ),
                    'printers', (
                        SELECT json_agg(printers) FROM (SELECT * FROM printers) AS printers
                    ),
                    'generic_translations', (
                        SELECT json_agg(generic_translations)
                        FROM (SELECT * FROM generic_translations) AS generic_translations
                    ),
                    'payment_acc', (
                        SELECT json_agg(payment_acc ORDER BY accno)
                        FROM (
                            SELECT id, accno, description FROM chart WHERE link LIKE '%AP_paid%'
                        ) AS payment_acc
                    ),
                    'company_employee_config', (
                        SELECT json_object_agg(key, value)
                        FROM employee_config_oserp
                        WHERE employee_id = (SELECT id FROM employee WHERE login = '$login')
                    ),
                    'part_classifications', (
                        SELECT json_agg(part_classifications)
                        FROM (SELECT * FROM part_classifications ORDER BY id ASC) AS part_classifications
                    ),
                    'units', (
                        SELECT json_agg(units) FROM (SELECT * FROM units ORDER BY id ASC) AS units
                    ),
                    'warehouse', (
                        SELECT json_agg(warehouse) FROM (SELECT * FROM warehouse ORDER BY id ASC) AS warehouse
                    ),
                    'bin', (
                        SELECT json_agg(bin) FROM (SELECT * FROM bin ORDER BY id ASC) AS bin
                    ),
                    'bank_accounts', (
                        SELECT json_agg(bank_accounts)
                        FROM (
                            SELECT
                                ba.id, ba.account_number, ba.bank_code, ba.iban, ba.bic,
                                ba.bank, ba.name, ba.chart_id, ba.reconciliation_starting_date,
                                ba.reconciliation_starting_balance, ba.use_with_bank_import,
                                ba.use_for_zugferd, ba.use_for_qrbill, ba.qr_iban, ba.bank_account_id,
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
            )
        ) AS result
    SQL;

    $row = $db->get($query);
    $decoded = json_decode($row, true);
    // get() liefert {"success": true, "payload": {"result": {...}}}
    // payload.result enthält unser json_build_object mit 'company_config'
    resultInfo(true, '', $decoded['payload']['result'] ?? []);
}

/**
 * Lädt die Firmenkonfiguration (defaults + defaults_oserp) frisch aus der DB
 *
 * @param array $data (keine Parameter benötigt)
 * @testdata {}
 */
function getDefaults($data) {
    try {
        $db = DbhCompany::begin();

        $result = $db->getOne(
            "SELECT
                (SELECT row_to_json(d) FROM defaults d LIMIT 1) AS defaults,
                (SELECT COALESCE(json_object_agg(key, value), '{}') FROM defaults_oserp
                    WHERE key NOT IN ('aag_online_token', 'aag_online_token_exp')) AS defaults_oserp"
        );

        $defaults = json_decode($result['defaults'] ?? '{}', true) ?: [];
        $defaults_oserp = json_decode($result['defaults_oserp'] ?? '{}', true) ?: [];

        resultInfo(true, '', ['results' => [
            'defaults' => $defaults,
            'defaults_oserp' => $defaults_oserp
        ]]);
    } catch (Exception $e) {
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
        // writeLog("getDefaults Error: " . $e->getMessage());
    }
}

/**
 * Speichert die Firmenkonfiguration (defaults)
 *
 * Diese Funktion wird automatisch von api.call.php aufgerufen
 * wenn action: 'saveDefaults' im Request ist
 *
 * @param array $data Request-Daten mit 'data' Key
 * @return void
 */
function saveDefaults($data) {
    try {
        // Hole die defaults-Daten aus dem 'data' Key
        $defaults = $data['data'] ?? [];

        // Hole die CRM-Daten aus dem 'crmData' Key
        $crmData = $data['crmData'] ?? [];

        // Verbindung zur Firmendatenbank
        $db = DbhCompany::begin();

        // Faktura-Nummernkreise: Dürfen nie auf einen kleineren Wert zurückgesetzt werden,
        // da sie gesetzlich fortlaufend sein müssen und nicht manuell änderbar sind.
        $protectedNumberRangeFields = [
            'invnumber', 'cnnumber',
            'soinumber', 'pqinumber', 'sonumber', 'ponumber', 'pocnumber',
            'sqnumber', 'rfqnumber', 'sdonumber', 'pdonumber', 'sudonumber',
            'rdonumber', 's_reclamation_record_number', 'p_reclamation_record_number',
            'donumber'
        ];

        // Stammdaten-Nummernkreise: Frei setzbar, da nextFreeNumber() beim Vergeben
        // Kollisionen automatisch verhindert.
        $freeNumberRangeFields = [
            'customernumber', 'vendornumber',
            'articlenumber', 'servicenumber', 'assemblynumber', 'assortmentnumber'
        ];

        // 1. Speichere DEFAULTS (horizontale Speicherung in 'defaults' Tabelle)
        if (!empty($defaults)) {
            // Nummernkreise aus den normalen Defaults herausnehmen
            $protectedData = [];
            $freeData = [];
            foreach ($protectedNumberRangeFields as $field) {
                if (array_key_exists($field, $defaults)) {
                    $protectedData[$field] = $defaults[$field];
                    unset($defaults[$field]);
                }
            }
            foreach ($freeNumberRangeFields as $field) {
                if (array_key_exists($field, $defaults)) {
                    $freeData[$field] = $defaults[$field];
                    unset($defaults[$field]);
                }
            }

            // Normale Felder speichern
            $excludeFields = ['id', 'itime', 'mtime'];
            if (!empty($defaults)) {
                $db->updateRow('defaults', $defaults, $excludeFields);
            }

            // Geschützte Nummernkreise mit GREATEST() speichern — nie runterzählen
            if (!empty($protectedData)) {
                $setClauses = [];
                $params = [];
                foreach ($protectedData as $field => $value) {
                    $setClauses[] = "$field = GREATEST(COALESCE($field::bigint, 0), :$field::bigint)::text";
                    $params[":$field"] = (string)$value;
                }
                $sql = "UPDATE defaults SET " . implode(', ', $setClauses);
                $db->execute($sql, $params);
            }

            // Freie Nummernkreise direkt speichern — Wert frei wählbar
            if (!empty($freeData)) {
                $setClauses = [];
                $params = [];
                foreach ($freeData as $field => $value) {
                    $setClauses[] = "$field = :$field";
                    $params[":$field"] = (string)$value;
                }
                $sql = "UPDATE defaults SET " . implode(', ', $setClauses);
                $db->execute($sql, $params);
            }
        }

        // 2. Speichere CRM-DEFAULTS (vertikale Speicherung in 'defaults_oserp' Tabelle)
        // UPSERT: Vorhandene Einträge aktualisieren, neue einfügen — nie löschen!
        //
        // ACHTUNG: Kein Batch-UPSERT mit ARRAY[?,?,...] und array_merge($keys, $values)!
        // bindTypedParams() iteriert den $params-Array per Key (0-basiert), PDO-?-Platzhalter
        // sind aber 1-basiert — Index 0 wird ignoriert, der letzte ? bleibt ungebunden
        // → PDOException → Config wird nie gespeichert. (Regressions-Commit: 91a5cab)
        foreach ($crmData as $key => $value) {
            $v = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
            $db->execute(
                "INSERT INTO defaults_oserp (key, value) VALUES (:key, :value)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
                [':key' => $key, ':value' => $v]
            );
        }

        // Erfolgsantwort
        resultInfo(true, 'Firmenkonfiguration erfolgreich gespeichert');

    } catch (PDOException $e) {
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
        // writeLog("saveDefaults PDO Error: " . $e->getMessage());
    } catch (Exception $e) {
        resultInfo(false, 'INTERNAL_ERROR', $e->getMessage());
        // writeLog("saveDefaults Error: " . $e->getMessage());
    }
}

/**
 * Speichert einen einzelnen defaults_oserp-Wert (Upsert)
 *
 * @param string $data['key'] Config-Schlüssel
 * @param string $data['value'] Config-Wert (leer = löschen)
 * @testdata {"key": "company_logo", "value": "data:image/png;base64,..."}
 */
function saveClientDefault($data) {
    $key = $data['key'] ?? null;
    $value = $data['value'] ?? null;

    if (!$key) {
        resultInfo(false, 'MISSING_KEY', 'Config-Schlüssel fehlt');
        return;
    }

    $db = DbhCompany::begin();

    if ($value === null || $value === '') {
        $db->execute("DELETE FROM defaults_oserp WHERE key = :key", [':key' => $key]);
    } else {
        $db->execute(
            "INSERT INTO defaults_oserp (key, value) VALUES (:key, :value)
             ON CONFLICT (key) DO UPDATE SET value = :value_upd, mtime = NOW()",
            [':key' => $key, ':value' => $value, ':value_upd' => $value]
        );
    }

    resultInfo(true, '', ['key' => $key]);
}

/**
 * Speichert einen Employee-Config-Wert (Key/Value)
 *
 * @param string $data['key'] Config-Schlüssel
 * @param string $data['value'] Config-Wert
 * @testdata {"key": "default_printer_id", "value": "1172"}
 */
function saveEmployeeConfig($data) {
    $key = $data['key'] ?? null;
    $value = $data['value'] ?? null;

    if (!$key) {
        resultInfo(false, 'MISSING_KEY', 'Config-Schlüssel fehlt');
        return;
    }

    // Employee-ID über Session ermitteln
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    $login = $auth->getLogin();

    $db = DbhCompany::begin();
    $employee = $db->getOne(
        "SELECT id FROM employee WHERE login = :login",
        [':login' => $login]
    );

    if (!$employee) {
        resultInfo(false, 'EMPLOYEE_NOT_FOUND', 'Mitarbeiter nicht gefunden');
        return;
    }

    $employee_id = $employee['id'];

    $db->execute(
        "INSERT INTO employee_config_oserp (employee_id, key, value, itime)
         VALUES (:employee_id, :key, :value, NOW())
         ON CONFLICT (employee_id, key)
         DO UPDATE SET value = :value_upd, mtime = NOW()",
        [
            ':employee_id' => $employee_id,
            ':key' => $key,
            ':value' => $value,
            ':value_upd' => $value
        ]
    );

    resultInfo(true, '', ['key' => $key, 'value' => $value]);
}

/**
 * Löscht einen Employee-Config-Wert
 *
 * @param string $data['key'] Config-Schlüssel
 * @testdata {"key": "view-history"}
 */
function deleteEmployeeConfig($data) {
    $key = $data['key'] ?? null;

    if (!$key) {
        resultInfo(false, 'MISSING_KEY', 'Config-Schlüssel fehlt');
        return;
    }

    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    $login = $auth->getLogin();

    $db = DbhCompany::begin();
    $employee = $db->getOne(
        "SELECT id FROM employee WHERE login = :login",
        [':login' => $login]
    );

    if (!$employee) {
        resultInfo(false, 'EMPLOYEE_NOT_FOUND', 'Mitarbeiter nicht gefunden');
        return;
    }

    $db->execute(
        "DELETE FROM employee_config_oserp WHERE employee_id = :employee_id AND key = :key",
        [':employee_id' => $employee['id'], ':key' => $key]
    );

    resultInfo(true, '', ['key' => $key]);
}
