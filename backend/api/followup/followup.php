<?php
// backend/api/followup/followup.php
// Wiedervorlage (Follow-Up) API
// Einfache SQL-Queries mit json_build_object - KEINE PG-Funktionen

/**
 * Lädt alle Wiedervorlagen für den aktuellen Mitarbeiter
 *
 * @param array $data Filter-Optionen (showDone, fromDate, toDate)
 * @testdata {"action": "getFollowUps"}
 * @testdata {"action": "getFollowUps", "showDone": true}
 */
function getFollowUps($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForFollowUp($mandant, $auth->getLogin());
    if ($employeeId === 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter gefunden');
        return;
    }

    $showDone = isset($data['showDone']) && $data['showDone'] ? 'TRUE' : 'FALSE';

    // Datum-Filter sicher bauen
    $dateFilter = '';
    if (!empty($data['fromDate'])) {
        $from = $mandant->getPDO()->quote($data['fromDate']);
        $dateFilter .= " AND fu.follow_up_date >= $from::date";
    }
    if (!empty($data['toDate'])) {
        $to = $mandant->getPDO()->quote($data['toDate']);
        $dateFilter .= " AND fu.follow_up_date <= $to::date";
    }

    $query = <<<SQL
        SELECT json_build_object(
            'follow_ups', COALESCE((
                SELECT json_agg(fu_data ORDER BY fu_data->>'follow_up_date')
                FROM (
                    SELECT DISTINCT ON (fu.id) json_build_object(
                        'id', fu.id,
                        'follow_up_date', fu.follow_up_date,
                        'created_by', fu.created_by,
                        'itime', fu.itime,
                        'is_done', (fud.id IS NOT NULL),
                        'done_at', fud.done_at,
                        'subject', n.subject,
                        'body', n.body,
                        'priority', CASE
                            WHEN fud.id IS NOT NULL THEN 'done'
                            WHEN fu.follow_up_date < CURRENT_DATE THEN 'overdue'
                            WHEN fu.follow_up_date = CURRENT_DATE THEN 'today'
                            WHEN fu.follow_up_date <= CURRENT_DATE + 3 THEN 'soon'
                            ELSE 'normal'
                        END,
                        'links', COALESCE((
                            SELECT json_agg(json_build_object(
                                'id', ful.id,
                                'trans_id', ful.trans_id,
                                'trans_type', ful.trans_type,
                                'trans_info', ful.trans_info
                            ))
                            FROM follow_up_links ful
                            WHERE ful.follow_up_id = fu.id
                        ), '[]'::json),
                        'assigned_employees', COALESCE((
                            SELECT json_agg(json_build_object(
                                'employee_id', fufe.employee_id,
                                'employee_name', e.name
                            ))
                            FROM follow_up_created_for_employees fufe
                            LEFT JOIN employee e ON e.id = fufe.employee_id
                            WHERE fufe.follow_up_id = fu.id
                        ), '[]'::json)
                    ) AS fu_data
                    FROM follow_ups fu
                    LEFT JOIN notes n ON n.id = fu.note_id
                    LEFT JOIN follow_up_done fud ON fud.follow_up_id = fu.id
                    LEFT JOIN follow_up_created_for_employees fufe ON fufe.follow_up_id = fu.id
                    WHERE (fufe.employee_id = $employeeId OR fu.created_by = $employeeId)
                        AND ($showDone OR fud.id IS NULL)
                        $dateFilter
                ) sub
            ), '[]'::json)
        ) AS result
    SQL;

    echo $mandant->get($query);
}

/**
 * Lädt eine einzelne Wiedervorlage
 *
 * @param array $data Mit 'id'
 * @testdata {"action": "getFollowUp", "id": 1}
 */
function getFollowUp($data) {
    $mandant = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    $query = <<<SQL
        SELECT json_build_object(
            'follow_up', (
                SELECT json_build_object(
                    'id', fu.id,
                    'follow_up_date', fu.follow_up_date,
                    'created_by', fu.created_by,
                    'created_by_name', e_creator.name,
                    'itime', fu.itime,
                    'is_done', (fud.id IS NOT NULL),
                    'done_at', fud.done_at,
                    'subject', n.subject,
                    'body', n.body,
                    'links', COALESCE((
                        SELECT json_agg(json_build_object(
                            'id', ful.id,
                            'trans_id', ful.trans_id,
                            'trans_type', ful.trans_type,
                            'trans_info', ful.trans_info
                        ))
                        FROM follow_up_links ful
                        WHERE ful.follow_up_id = fu.id
                    ), '[]'::json),
                    'assigned_employees', COALESCE((
                        SELECT json_agg(json_build_object(
                            'employee_id', fufe.employee_id,
                            'employee_name', e.name
                        ))
                        FROM follow_up_created_for_employees fufe
                        LEFT JOIN employee e ON e.id = fufe.employee_id
                        WHERE fufe.follow_up_id = fu.id
                    ), '[]'::json)
                )
                FROM follow_ups fu
                LEFT JOIN notes n ON n.id = fu.note_id
                LEFT JOIN employee e_creator ON e_creator.id = fu.created_by
                LEFT JOIN follow_up_done fud ON fud.follow_up_id = fu.id
                WHERE fu.id = $id
            )
        ) AS result
    SQL;

    echo $mandant->get($query);
}

/**
 * Lädt Dashboard-Daten (gruppiert nach Priorität)
 *
 * @param array $data Keine Parameter erforderlich
 * @testdata {"action": "getFollowUpDashboard"}
 */
function getFollowUpDashboard($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForFollowUp($mandant, $auth->getLogin());
    if ($employeeId === 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter gefunden');
        return;
    }

    $query = <<<SQL
        WITH fu_base AS (
            SELECT DISTINCT ON (fu.id)
                fu.id,
                fu.follow_up_date,
                n.subject,
                n.body,
                CASE
                    WHEN fu.follow_up_date < CURRENT_DATE THEN 'overdue'
                    WHEN fu.follow_up_date = CURRENT_DATE THEN 'today'
                    ELSE 'upcoming'
                END AS priority,
                (
                    SELECT json_build_object('trans_type', ful.trans_type, 'trans_info', ful.trans_info)
                    FROM follow_up_links ful
                    WHERE ful.follow_up_id = fu.id
                    LIMIT 1
                ) AS link
            FROM follow_ups fu
            LEFT JOIN notes n ON n.id = fu.note_id
            LEFT JOIN follow_up_done fud ON fud.follow_up_id = fu.id
            LEFT JOIN follow_up_created_for_employees fufe ON fufe.follow_up_id = fu.id
            WHERE (fufe.employee_id = $employeeId OR fu.created_by = $employeeId)
                AND fud.id IS NULL
                AND fu.follow_up_date <= CURRENT_DATE + INTERVAL '7 days'
        )
        SELECT json_build_object(
            'overdue', COALESCE((
                SELECT json_agg(row_to_json(f) ORDER BY f.follow_up_date)
                FROM fu_base f WHERE priority = 'overdue'
            ), '[]'::json),
            'today', COALESCE((
                SELECT json_agg(row_to_json(f) ORDER BY f.follow_up_date)
                FROM fu_base f WHERE priority = 'today'
            ), '[]'::json),
            'upcoming', COALESCE((
                SELECT json_agg(row_to_json(f) ORDER BY f.follow_up_date)
                FROM fu_base f WHERE priority = 'upcoming'
            ), '[]'::json),
            'counts', json_build_object(
                'overdue', (SELECT COUNT(*) FROM fu_base WHERE priority = 'overdue'),
                'today', (SELECT COUNT(*) FROM fu_base WHERE priority = 'today'),
                'upcoming', (SELECT COUNT(*) FROM fu_base WHERE priority = 'upcoming')
            )
        ) AS result
    SQL;

    echo $mandant->get($query);
}

/**
 * Erstellt eine neue Wiedervorlage
 *
 * @param array $data Mit followUpDate, subject, body, transId, transType, transInfo, assignedEmployees
 * @testdata {"action": "createFollowUp", "followUpDate": "2025-02-01", "subject": "Test Wiedervorlage"}
 */
function createFollowUp($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForFollowUp($mandant, $auth->getLogin());
    if ($employeeId === 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter gefunden');
        return;
    }

    // Validierung
    if (empty($data['followUpDate'])) {
        resultInfo(false, 'MISSING_DATE', 'Datum erforderlich');
        return;
    }
    if (empty($data['subject'])) {
        resultInfo(false, 'MISSING_SUBJECT', 'Betreff erforderlich');
        return;
    }

    $pdo = $mandant->getPDO();
    $followUpDate = $pdo->quote($data['followUpDate']);
    $subject = $pdo->quote($data['subject']);
    $body = $pdo->quote($data['body'] ?? '');

    $mandant->beginTransaction();

    try {
        // 1. Note erstellen
        $noteQuery = "INSERT INTO notes (subject, body, created_by, trans_module) VALUES ($subject, $body, $employeeId, 'fu') RETURNING id";
        $noteResult = $mandant->fetch($noteQuery);
        $noteId = $noteResult['id'];

        // 2. Follow-Up erstellen
        $fuQuery = "INSERT INTO follow_ups (follow_up_date, note_id, created_by) VALUES ($followUpDate, $noteId, $employeeId) RETURNING id";
        $fuResult = $mandant->fetch($fuQuery);
        $followUpId = $fuResult['id'];

        // Wie kivitendo (SL/FU.pm): Notiz zeigt auf die Wiedervorlage — darauf bauen der
        // Lösch-Trigger follow_up_delete_notes und die Volltextsuche der Auftragsliste
        $mandant->execute("UPDATE notes SET trans_id = :fu_id WHERE id = :note_id", ['fu_id' => $followUpId, 'note_id' => $noteId]);

        // 3. Optional: Link erstellen
        if (!empty($data['transId']) && !empty($data['transType'])) {
            $transId = intval($data['transId']);
            $transType = $pdo->quote($data['transType']);
            $transInfo = $pdo->quote($data['transInfo'] ?? '');
            $mandant->query("INSERT INTO follow_up_links (follow_up_id, trans_id, trans_type, trans_info) VALUES ($followUpId, $transId, $transType, $transInfo)");
        }

        // 4. Mitarbeiter zuweisen
        if (!empty($data['assignedEmployees']) && is_array($data['assignedEmployees'])) {
            foreach ($data['assignedEmployees'] as $empId) {
                $empId = intval($empId);
                $mandant->query("INSERT INTO follow_up_created_for_employees (follow_up_id, employee_id) VALUES ($followUpId, $empId)");
            }
        } else {
            $mandant->query("INSERT INTO follow_up_created_for_employees (follow_up_id, employee_id) VALUES ($followUpId, $employeeId)");
        }

        $mandant->commit();

        // Neu erstellte Wiedervorlage zurückgeben
        $data['id'] = $followUpId;
        getFollowUp($data);

    } catch (Exception $e) {
        $mandant->rollBack();
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Aktualisiert eine Wiedervorlage
 *
 * @param array $data Mit id, followUpDate, subject, body, assignedEmployees
 * @testdata {"action": "updateFollowUp", "id": 1, "followUpDate": "2025-02-15", "subject": "Geändert"}
 */
function updateFollowUp($data) {
    $mandant = DbhCompany::begin();
    $pdo = $mandant->getPDO();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    // Note-ID holen
    $fuData = $mandant->fetch("SELECT note_id FROM follow_ups WHERE id = $id");
    if (!$fuData) {
        resultInfo(false, 'NOT_FOUND', 'Wiedervorlage nicht gefunden');
        return;
    }

    $noteId = $fuData['note_id'];
    $mandant->beginTransaction();

    try {
        // Follow-Up aktualisieren
        if (!empty($data['followUpDate'])) {
            $followUpDate = $pdo->quote($data['followUpDate']);
            $mandant->query("UPDATE follow_ups SET follow_up_date = $followUpDate, mtime = NOW() WHERE id = $id");
        }

        // Note aktualisieren
        $updates = [];
        if (isset($data['subject'])) {
            $updates[] = "subject = " . $pdo->quote($data['subject']);
        }
        if (isset($data['body'])) {
            $updates[] = "body = " . $pdo->quote($data['body']);
        }
        if (!empty($updates)) {
            $updates[] = "mtime = NOW()";
            $mandant->query("UPDATE notes SET " . implode(', ', $updates) . " WHERE id = $noteId");
        }

        // Mitarbeiter neu zuweisen
        if (isset($data['assignedEmployees']) && is_array($data['assignedEmployees'])) {
            $mandant->query("DELETE FROM follow_up_created_for_employees WHERE follow_up_id = $id");
            foreach ($data['assignedEmployees'] as $empId) {
                $empId = intval($empId);
                $mandant->query("INSERT INTO follow_up_created_for_employees (follow_up_id, employee_id) VALUES ($id, $empId)");
            }
        }

        // Links nur anfassen, wenn der Aufruf sie mitschickt: Der Dialog sendet immer
        // transType/transId (null = Verknüpfung entfernen), das Verschieben im Board
        // oder Kalender nur das Datum — die Verknüpfung zum Beleg muss dann bleiben.
        if (array_key_exists('transType', $data) || array_key_exists('transId', $data)) {
            $mandant->execute("DELETE FROM follow_up_links WHERE follow_up_id = :id", ['id' => $id]);
            if (!empty($data['transId']) && !empty($data['transType'])) {
                $mandant->execute(
                    "INSERT INTO follow_up_links (follow_up_id, trans_id, trans_type, trans_info) VALUES (:id, :trans_id, :trans_type, :trans_info)",
                    ['id' => $id, 'trans_id' => intval($data['transId']), 'trans_type' => (string)$data['transType'], 'trans_info' => (string)($data['transInfo'] ?? '')]
                );
            }
        }

        $mandant->commit();
        getFollowUp($data);

    } catch (Exception $e) {
        $mandant->rollBack();
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Markiert Wiedervorlage als erledigt
 *
 * @param array $data Mit 'id'
 * @testdata {"action": "markFollowUpDone", "id": 1}
 */
function markFollowUpDone($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForFollowUp($mandant, $auth->getLogin());
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    // Prüfen ob schon erledigt
    $check = $mandant->fetch("SELECT id FROM follow_up_done WHERE follow_up_id = $id");
    if ($check) {
        resultInfo(false, 'ALREADY_DONE', 'Bereits erledigt');
        return;
    }

    $mandant->query("INSERT INTO follow_up_done (follow_up_id, employee_id) VALUES ($id, $employeeId)");
    resultInfo(true, 'MARKED_DONE', 'Als erledigt markiert');
}

/**
 * Öffnet Wiedervorlage wieder
 *
 * @param array $data Mit 'id'
 * @testdata {"action": "markFollowUpUndone", "id": 1}
 */
function markFollowUpUndone($data) {
    $mandant = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    $mandant->query("DELETE FROM follow_up_done WHERE follow_up_id = $id");
    resultInfo(true, 'MARKED_UNDONE', 'Wieder geöffnet');
}

/**
 * Löscht eine Wiedervorlage
 *
 * @param array $data Mit 'id'
 * @testdata {"action": "deleteFollowUp", "id": 1}
 */
function deleteFollowUp($data) {
    $mandant = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    $fuData = $mandant->fetch("SELECT note_id FROM follow_ups WHERE id = $id");
    if (!$fuData) {
        resultInfo(false, 'NOT_FOUND', 'Wiedervorlage nicht gefunden');
        return;
    }

    $noteId = $fuData['note_id'];

    $mandant->beginTransaction();

    try {
        $mandant->query("DELETE FROM follow_up_done WHERE follow_up_id = $id");
        $mandant->query("DELETE FROM follow_up_links WHERE follow_up_id = $id");
        $mandant->query("DELETE FROM follow_up_created_for_employees WHERE follow_up_id = $id");
        $mandant->query("DELETE FROM follow_ups WHERE id = $id");
        $mandant->query("DELETE FROM notes WHERE id = $noteId");

        $mandant->commit();
        resultInfo(true, 'DELETED', 'Gelöscht');

    } catch (Exception $e) {
        $mandant->rollBack();
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Lädt alle Mitarbeiter für Dropdown
 *
 * @param array $data Keine Parameter
 * @testdata {"action": "getEmployeesForFollowUp"}
 */
function getEmployeesForFollowUp($data) {
    $mandant = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'employees', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', id,
                    'name', name,
                    'login', login
                ) ORDER BY name)
                FROM employee
                WHERE deleted IS NOT TRUE
            ), '[]'::json)
        ) AS result
    SQL;

    echo $mandant->get($query);
}

/**
 * Sucht nach Verknüpfungen (Kunden, Lieferanten, Belege) für den Wiedervorlage-Dialog
 *
 * Der Suchbegriff geht nur als Parameter in die Abfrage. Die Belegart in oe kennt das
 * neue kivitendo-Schema als record_type, das alte (z. B. Mandant vermietung) als
 * quotation. Beide Spalten stehen deshalb unqualifiziert in der Abfrage: Fehlt eine in
 * oe, löst PostgreSQL den Namen über die Rückfall-Zeile schema_fallback (NULL) auf.
 *
 * @param array $data Mit type (customer, vendor, sales_order, credit_note …), query (Suchbegriff), limit
 * @testdata {"action": "searchForLink", "type": "customer", "query": "Ronny"}
 * @testdata {"action": "searchForLink", "type": "sales_order", "query": "25"}
 */
function searchForLink($data) {
    $mandant = DbhCompany::begin();

    $type = $data['type'] ?? '';
    $query = trim((string)($data['query'] ?? ''));
    $limit = max(1, min(50, intval($data['limit'] ?? 10)));

    if (mb_strlen($query) < 2) {
        resultInfo(false, 'QUERY_TOO_SHORT', 'Mindestens 2 Zeichen erforderlich');
        return;
    }

    // Belege in oe: Bedingung für die Belegart (neues Schema | altes Schema), Nummernfeld, Partner
    $oeTypes = [
        'sales_quotation'   => ["COALESCE(record_type::text = 'sales_quotation', quotation)", 'quonumber', 'customer'],
        'sales_order'       => ["COALESCE(record_type::text IN ('sales_order', 'sales_order_intake'), NOT quotation)", 'ordnumber', 'customer'],
        'request_quotation' => ["COALESCE(record_type::text IN ('request_quotation', 'purchase_quotation_intake'), quotation)", 'quonumber', 'vendor'],
        'purchase_order'    => ["COALESCE(record_type::text IN ('purchase_order', 'purchase_order_confirmation'), NOT quotation)", 'ordnumber', 'vendor'],
    ];
    // Rechnungen und Lieferscheine: Tabelle, Bedingung, Partner
    $docTypes = [
        'sales_invoice'           => ['ar', "d.type IS DISTINCT FROM 'credit_note'", 'customer'],
        'credit_note'             => ['ar', "d.type = 'credit_note'", 'customer'],
        'purchase_invoice'        => ['ap', 'TRUE', 'vendor'],
        'sales_delivery_order'    => ['delivery_orders', 'd.customer_id IS NOT NULL', 'customer'],
        'purchase_delivery_order' => ['delivery_orders', 'd.vendor_id IS NOT NULL', 'vendor'],
    ];

    if ($type === 'customer' || $type === 'vendor') {
        $sql = <<<SQL
            SELECT COALESCE(json_agg(row_to_json(sub) ORDER BY sub.name), '[]'::json) AS results
            FROM (
                SELECT cv.id, cv.name, cv.{$type}number AS number, cv.city
                FROM {$type} cv
                WHERE cv.name ILIKE '%' || :q || '%' OR cv.{$type}number ILIKE '%' || :q || '%'
                ORDER BY cv.name
                LIMIT :limit
            ) sub
        SQL;
    } elseif (isset($oeTypes[$type])) {
        [$typeCondition, $numberColumn, $cv] = $oeTypes[$type];
        $sql = <<<SQL
            SELECT COALESCE(json_agg(row_to_json(sub) ORDER BY sub.transdate DESC), '[]'::json) AS results
            FROM (SELECT NULL::text AS record_type, NULL::boolean AS quotation) AS schema_fallback
            CROSS JOIN LATERAL (
                SELECT o.id, o.{$numberColumn} AS number, cv.name AS {$cv}_name,
                       COALESCE(o.amount, 0)::numeric(15,2) AS amount, o.transdate
                FROM oe o
                LEFT JOIN {$cv} cv ON cv.id = o.{$cv}_id
                WHERE {$typeCondition}
                  AND o.{$cv}_id IS NOT NULL
                  AND (o.{$numberColumn} ILIKE '%' || :q || '%' OR cv.name ILIKE '%' || :q || '%')
                ORDER BY o.transdate DESC
                LIMIT :limit
            ) AS sub
        SQL;
    } elseif (isset($docTypes[$type])) {
        [$table, $typeCondition, $cv] = $docTypes[$type];
        $numberColumn = ($table === 'delivery_orders') ? 'donumber' : 'invnumber';
        $amountSql = ($table === 'delivery_orders') ? 'NULL::numeric' : 'COALESCE(d.amount, 0)::numeric(15,2)';
        $sql = <<<SQL
            SELECT COALESCE(json_agg(row_to_json(sub) ORDER BY sub.transdate DESC), '[]'::json) AS results
            FROM (
                SELECT d.id, d.{$numberColumn} AS number, cv.name AS {$cv}_name,
                       {$amountSql} AS amount, d.transdate
                FROM {$table} d
                LEFT JOIN {$cv} cv ON cv.id = d.{$cv}_id
                WHERE {$typeCondition}
                  AND (d.{$numberColumn} ILIKE '%' || :q || '%' OR cv.name ILIKE '%' || :q || '%')
                ORDER BY d.transdate DESC
                LIMIT :limit
            ) sub
        SQL;
    } else {
        resultInfo(false, 'INVALID_TYPE', 'Ungültiger Verknüpfungstyp: ' . $type);
        return;
    }

    $row = $mandant->getOne($sql, ['q' => $query, 'limit' => $limit]);
    resultInfo(true, '', ['results' => json_decode($row['results'] ?? '[]', true) ?: []]);
}

/**
 * Debug: Testet die AR-Tabelle
 *
 * @testdata {"action": "debugAR"}
 */
function debugAR($data) {
    $mandant = DbhCompany::begin();

    $queries = [
        'total_ar_count' => "SELECT COUNT(*) as count FROM ar",
        'sample_ar' => "SELECT id, invnumber, customer_id, amount, transdate FROM ar LIMIT 3",
        'ar_with_customer' => "SELECT ar.id, ar.invnumber, c.name as customer_name FROM ar LEFT JOIN customer c ON c.id = ar.customer_id WHERE c.name IS NOT NULL LIMIT 3",
        'ar_columns' => "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'ar' ORDER BY ordinal_position"
    ];

    $results = [];
    foreach ($queries as $key => $sql) {
        try {
            $results[$key] = $mandant->getAll($sql);
        } catch (Exception $e) {
            $results[$key] = ['error' => $e->getMessage()];
        }
    }

    resultInfo(true, 'DEBUG_RESULTS', $results);
}

/**
 * Hilfsfunktion: Employee-ID aus Login holen
 */
function getEmployeeIdForFollowUp($mandant, $login) {
    $login = $mandant->getPDO()->quote($login);
    $result = $mandant->fetch("SELECT id FROM employee WHERE login = $login");
    return $result ? intval($result['id']) : 0;
}