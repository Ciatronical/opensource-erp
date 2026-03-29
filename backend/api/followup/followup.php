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

        // Links aktualisieren
        $mandant->query("DELETE FROM follow_up_links WHERE follow_up_id = $id");
        if (!empty($data['transId']) && !empty($data['transType'])) {
            $transId = intval($data['transId']);
            $transType = $pdo->quote($data['transType']);
            $transInfo = $pdo->quote($data['transInfo'] ?? '');
            $mandant->query("INSERT INTO follow_up_links (follow_up_id, trans_id, trans_type, trans_info) VALUES ($id, $transId, $transType, $transInfo)");
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
 * Sucht nach Verknüpfungen (Kunden, Aufträge, Rechnungen, etc.)
 *
 * @param array $data Mit type (customer/vendor/salesOrder/etc.) und query (Suchbegriff)
 * @testdata {"action": "searchForLink", "type": "customer", "query": "Ronny"}
 */
function searchForLink($data) {
    $mandant = DbhCompany::begin();

    $type = $data['type'] ?? '';
    $query = $data['query'] ?? '';
    $limit = intval($data['limit'] ?? 10);

    if (empty($query) || strlen($query) < 2) {
        resultInfo(false, 'QUERY_TOO_SHORT', 'Mindestens 2 Zeichen erforderlich');
        return;
    }

    // Map: Type -> SQL Query
    $queryMap = [
        'customer' => <<<SQL
            SELECT COALESCE(
                (SELECT json_agg(row_to_json(sub))
                 FROM (
                     SELECT
                         c.id,
                         c.name,
                         c.customernumber AS number,
                         c.city
                     FROM customer c
                     WHERE c.name ILIKE '%$query%'
                     OR c.customernumber ILIKE '%$query%'
                     ORDER BY c.name
                     LIMIT $limit
                 ) sub
                ), '[]'::json
            ) AS results
        SQL,

        'vendor' => <<<SQL
            SELECT COALESCE(
                (SELECT json_agg(row_to_json(sub))
                 FROM (
                     SELECT
                         v.id,
                         v.name,
                         v.vendornumber AS number,
                         v.city
                     FROM vendor v
                     WHERE v.name ILIKE '%$query%'
                     OR v.vendornumber ILIKE '%$query%'
                     ORDER BY v.name
                     LIMIT $limit
                 ) sub
                ), '[]'::json
            ) AS results
        SQL,

        'sales_quotation' => <<<SQL
            SELECT COALESCE(
                (SELECT json_agg(row_to_json(sub))
                 FROM (
                     SELECT
                         o.id,
                         o.quonumber AS number,
                         c.name AS customer_name,
                         o.amount::numeric(15,2) AS amount,
                         o.transdate
                     FROM oe o
                     LEFT JOIN customer c ON c.id = o.customer_id
                     WHERE o.quotation = true
                     AND o.customer_id IS NOT NULL
                     AND (o.quonumber ILIKE '%$query%' OR c.name ILIKE '%$query%')
                     ORDER BY o.transdate DESC
                     LIMIT $limit
                 ) sub
                ), '[]'::json
            ) AS results
        SQL,

        'sales_order' => <<<SQL
            SELECT COALESCE(
                (SELECT json_agg(row_to_json(sub))
                 FROM (
                    SELECT
                        o.id,
                        o.ordnumber AS number,
                        c.name AS customer_name,
                        o.amount::numeric(15,2) AS amount,
                        o.transdate
                    FROM oe o
                    LEFT JOIN customer c ON c.id = o.customer_id
                    WHERE o.quotation = false
                    AND o.customer_id IS NOT NULL
                    AND (o.ordnumber ILIKE '%$query%' OR c.name ILIKE '%$query%')
                    ORDER BY o.transdate DESC
                    LIMIT $limit
                 ) sub
                ), '[]'::json
            ) AS results
        SQL,

        'sales_invoice' => <<<SQL
            SELECT COALESCE(
                (SELECT json_agg(row_to_json(sub))
                 FROM (
                    SELECT
                        ar.id,
                        ar.invnumber AS number,
                        COALESCE(c.name, 'Kein Kunde') AS customer_name,
                        COALESCE(ar.amount, 0)::numeric(15,2) AS amount,
                        ar.transdate
                    FROM ar ar
                    LEFT JOIN customer c ON c.id = ar.customer_id
                    WHERE (ar.invnumber::text ILIKE '%$query%' OR c.name ILIKE '%$query%')
                    ORDER BY ar.transdate DESC
                    LIMIT $limit
                 ) sub
                ), '[]'::json
            ) AS results
        SQL,

        'purchase_order' => <<<SQL
            SELECT COALESCE(
                (SELECT json_agg(row_to_json(sub))
                 FROM (
                    SELECT
                        o.id,
                        o.ordnumber AS number,
                        v.name AS vendor_name,
                        o.amount::numeric(15,2) AS amount,
                        o.transdate
                    FROM oe o
                    LEFT JOIN vendor v ON v.id = o.vendor_id
                    WHERE o.quotation = false
                    AND o.vendor_id IS NOT NULL
                    AND (o.ordnumber ILIKE '%$query%' OR v.name ILIKE '%$query%')
                    ORDER BY o.transdate DESC
                    LIMIT $limit
                 ) sub
                ), '[]'::json
            ) AS results
        SQL,

        'purchase_invoice' => <<<SQL
            SELECT COALESCE(
                (SELECT json_agg(row_to_json(sub))
                 FROM (
                    SELECT
                        ap.id,
                        ap.invnumber AS number,
                        COALESCE(v.name, 'Kein Lieferant') AS vendor_name,
                        COALESCE(ap.amount, 0)::numeric(15,2) AS amount,
                        ap.transdate
                    FROM ap ap
                    LEFT JOIN vendor v ON v.id = ap.vendor_id
                    WHERE (ap.invnumber::text ILIKE '%$query%' OR v.name ILIKE '%$query%')
                    ORDER BY ap.transdate DESC
                    LIMIT $limit
                 ) sub
                ), '[]'::json
            ) AS results
        SQL,

        'sales_delivery_order' => <<<SQL
            SELECT '[]'::json AS results
        SQL,

        'purchase_delivery_order' => <<<SQL
            SELECT '[]'::json AS results
        SQL,

        'request_quotation' => <<<SQL
            SELECT COALESCE(
                (SELECT json_agg(row_to_json(sub))
                 FROM (
                    SELECT
                        o.id,
                        o.quonumber AS number,
                        COALESCE(v.name, 'Kein Lieferant') AS vendor_name,
                        COALESCE(o.amount, 0)::numeric(15,2) AS amount,
                        o.transdate
                    FROM oe o
                    LEFT JOIN vendor v ON v.id = o.vendor_id
                    WHERE o.quotation = true
                    AND o.vendor_id IS NOT NULL
                    AND (o.quonumber::text ILIKE '%$query%' OR v.name ILIKE '%$query%')
                    ORDER BY o.transdate DESC
                    LIMIT $limit
                 ) sub
                ), '[]'::json
            ) AS results
        SQL
    ];

    // Query aus Map holen
    if (!isset($queryMap[$type])) {
        resultInfo(false, 'INVALID_TYPE', 'Ungültiger Verknüpfungstyp: ' . $type);
        return;
    }

    $sql = $queryMap[$type];

    // Einfach get() verwenden - gibt automatisch JSON zurück!
    echo $mandant->get($sql);
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