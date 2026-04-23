<?php
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
                (SELECT COALESCE(json_object_agg(key, value), '{}') FROM defaults_oserp) AS defaults_oserp"
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
        $pdo = $db->getPDO(); //Warum kann das DbhCompany nicht direkt PDO zurückgeben? ToDo

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
        // EINFACH: Lösche alles und füge neu ein!
        if (!empty($crmData)) {
            //writeLog("Saving CRM defaults in defaults.php");
            // Lösche alle bestehenden Einträge
            $pdo->exec("DELETE FROM defaults_oserp");

            // Füge alle neuen Einträge ein
            $stmt = $pdo->prepare("INSERT INTO defaults_oserp (key, value) VALUES (:key, :value)");

            foreach ($crmData as $key => $value) {
                $stmt->bindValue(':key', $key, PDO::PARAM_STR);

                // Type-safe Value Binding
                if ($value === null) {
                    $stmt->bindValue(':value', null, PDO::PARAM_NULL);
                } elseif (is_bool($value)) {
                    $stmt->bindValue(':value', $value, PDO::PARAM_BOOL);
                } elseif (is_int($value)) {
                    $stmt->bindValue(':value', $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(':value', $value, PDO::PARAM_STR);
                }

                $stmt->execute();
            }
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
