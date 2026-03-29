<?php
// backend/api/tasks/tasks.php
// Wiedervorlage (Follow-Up) API
// Einfache SQL-Queries mit json_build_object - KEINE PG-Funktionen

/**
 * Lädt alle Wiedervorlagen für den aktuellen Mitarbeiter
 *
 * @param array $data Filter-Optionen (showDone, fromDate, toDate)
 * @testdata {"action": "getTasks"}
 * @testdata {"action": "getTasks", "showDone": true}
 */
function getTasks($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForTask($mandant, $auth->getLogin());
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
 * @testdata {"action": "getTask", "id": 1}
 */
function getTask($data) {
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
 * @testdata {"action": "getTaskDashboard"}
 */
function getTaskDashboard($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForTask($mandant, $auth->getLogin());
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
 * @param array $data Mit taskDate, subject, body, transId, transType, transInfo, assignedEmployees
 * @testdata {"action": "createTask", "taskDate": "2025-02-01", "subject": "Test Wiedervorlage"}
 */
function createTask($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForTask($mandant, $auth->getLogin());
    if ($employeeId === 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter gefunden');
        return;
    }

    // Validierung
    if (empty($data['taskDate'])) {
        resultInfo(false, 'MISSING_DATE', 'Datum erforderlich');
        return;
    }
    if (empty($data['subject'])) {
        resultInfo(false, 'MISSING_SUBJECT', 'Betreff erforderlich');
        return;
    }

    $pdo = $mandant->getPDO();
    $taskDate = $pdo->quote($data['taskDate']);
    $subject = $pdo->quote($data['subject']);
    $body = $pdo->quote($data['body'] ?? '');

    $mandant->beginTransaction();

    try {
        // 1. Note erstellen
        $noteQuery = "INSERT INTO notes (subject, body, created_by, trans_module) VALUES ($subject, $body, $employeeId, 'fu') RETURNING id";
        $noteResult = $mandant->fetch($noteQuery);
        $noteId = $noteResult['id'];

        // 2. Follow-Up erstellen
        $fuQuery = "INSERT INTO follow_ups (follow_up_date, note_id, created_by) VALUES ($taskDate, $noteId, $employeeId) RETURNING id";
        $fuResult = $mandant->fetch($fuQuery);
        $taskId = $fuResult['id'];

        // 3. Optional: Link erstellen
        if (!empty($data['transId']) && !empty($data['transType'])) {
            $transId = intval($data['transId']);
            $transType = $pdo->quote($data['transType']);
            $transInfo = $pdo->quote($data['transInfo'] ?? '');
            $mandant->query("INSERT INTO follow_up_links (follow_up_id, trans_id, trans_type, trans_info) VALUES ($taskId, $transId, $transType, $transInfo)");
        }

        // 4. Mitarbeiter zuweisen
        if (!empty($data['assignedEmployees']) && is_array($data['assignedEmployees'])) {
            foreach ($data['assignedEmployees'] as $empId) {
                $empId = intval($empId);
                $mandant->query("INSERT INTO follow_up_created_for_employees (follow_up_id, employee_id) VALUES ($taskId, $empId)");
            }
        } else {
            $mandant->query("INSERT INTO follow_up_created_for_employees (follow_up_id, employee_id) VALUES ($taskId, $employeeId)");
        }

        $mandant->commit();

        // Neu erstellte Wiedervorlage zurückgeben
        $data['id'] = $taskId;
        getTask($data);

    } catch (Exception $e) {
        $mandant->rollBack();
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Aktualisiert eine Wiedervorlage
 *
 * @param array $data Mit id, taskDate, subject, body, assignedEmployees
 * @testdata {"action": "updateTask", "id": 1, "taskDate": "2025-02-15", "subject": "Geändert"}
 */
function updateTask($data) {
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
        if (!empty($data['taskDate'])) {
            $taskDate = $pdo->quote($data['taskDate']);
            $mandant->query("UPDATE follow_ups SET follow_up_date = $taskDate, mtime = NOW() WHERE id = $id");
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

        $mandant->commit();
        getTask($data);

    } catch (Exception $e) {
        $mandant->rollBack();
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Markiert Wiedervorlage als erledigt
 *
 * @param array $data Mit 'id'
 * @testdata {"action": "markTaskDone", "id": 1}
 */
function markTaskDone($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForTask($mandant, $auth->getLogin());
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
 * @testdata {"action": "markTaskUndone", "id": 1}
 */
function markTaskUndone($data) {
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
 * @testdata {"action": "deleteTask", "id": 1}
 */
function deleteTask($data) {
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
 * @testdata {"action": "getEmployeesForTask"}
 */
function getEmployeesForTask($data) {
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
 * Hilfsfunktion: Employee-ID aus Login holen
 */
function getEmployeeIdForTask($mandant, $login) {
    $login = $mandant->getPDO()->quote($login);
    $result = $mandant->fetch("SELECT id FROM employee WHERE login = $login");
    return $result ? intval($result['id']) : 0;
}
