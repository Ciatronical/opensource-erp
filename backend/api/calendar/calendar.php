<?php
// backend/api/calendar/calendar.php
// Kalender API – Events und Kategorien

/**
 * Hilfsfunktion: Employee-ID aus Login holen
 */
function getEmployeeIdForCalendar($mandant, $login) {
    $login = $mandant->getPDO()->quote($login);
    $result = $mandant->fetch("SELECT id FROM employee WHERE login = $login");
    return $result ? intval($result['id']) : 0;
}

// ============================================================================
// EVENT CATEGORIES
// ============================================================================

/**
 * Lädt alle Kalender-Kategorien
 *
 * @testdata {"action": "getEventCategories"}
 */
function getEventCategories($data) {
    $mandant = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'categories', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', id,
                    'label', label,
                    'color', color,
                    'cat_order', cat_order
                ) ORDER BY cat_order, id)
                FROM event_category
            ), '[]'::json)
        ) AS result
    SQL;

    echo $mandant->get($query);
}

/**
 * Erstellt oder aktualisiert eine Kategorie
 *
 * @param array $data Mit label, color, cat_order, optional id (für Update)
 * @testdata {"action": "saveEventCategory", "label": "Test", "color": "#FF0000"}
 */
function saveEventCategory($data) {
    $mandant = DbhCompany::begin();
    $pdo = $mandant->getPDO();

    $label = $pdo->quote($data['label'] ?? '');
    $color = $pdo->quote($data['color'] ?? '#1976D2');
    $catOrder = intval($data['cat_order'] ?? 1);

    if (empty($data['label'])) {
        resultInfo(false, 'MISSING_LABEL', 'Bezeichnung erforderlich');
        return;
    }

    if (!empty($data['id'])) {
        $id = intval($data['id']);
        $mandant->query("UPDATE event_category SET label = $label, color = $color, cat_order = $catOrder, mtime = NOW() WHERE id = $id");
    } else {
        if ($catOrder <= 1) {
            $maxOrder = $mandant->fetch("SELECT COALESCE(MAX(cat_order), 0) + 1 AS next_order FROM event_category");
            $catOrder = intval($maxOrder['next_order']);
        }
        $mandant->query("INSERT INTO event_category (label, color, cat_order) VALUES ($label, $color, $catOrder)");
    }

    getEventCategories($data);
}

/**
 * Löscht eine Kategorie
 *
 * @param array $data Mit id
 * @testdata {"action": "deleteEventCategory", "id": 1}
 */
function deleteEventCategory($data) {
    $mandant = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    $mandant->beginTransaction();
    try {
        $mandant->query("UPDATE calendar_events SET category_id = NULL WHERE category_id = $id");
        $mandant->query("DELETE FROM event_category WHERE id = $id");
        $mandant->commit();
        getEventCategories($data);
    } catch (Exception $e) {
        $mandant->rollBack();
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

// ============================================================================
// CALENDAR EVENTS
// ============================================================================

/**
 * Lädt Kalendertermine für einen Datumsbereich
 *
 * @param array $data Mit startDate, endDate
 * @testdata {"action": "getCalendarEvents", "startDate": "2026-01-01", "endDate": "2026-02-01"}
 */
function getCalendarEvents($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForCalendar($mandant, $auth->getLogin());
    if ($employeeId === 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter gefunden');
        return;
    }

    $pdo = $mandant->getPDO();
    $startDate = $pdo->quote($data['startDate'] ?? date('Y-m-01'));
    $endDate = $pdo->quote($data['endDate'] ?? date('Y-m-t'));

    $query = <<<SQL
        SELECT json_build_object(
            'events', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', ce.id,
                    'title', ce.title,
                    'description', ce.description,
                    'dtstart', ce.dtstart,
                    'dtend', ce.dtend,
                    'allDay', ce."allDay",
                    'location', ce.location,
                    'color', COALESCE(ce.color, ec.color, '#1976D2'),
                    'prio', ce.prio,
                    'category_id', ce.category_id,
                    'category_label', ec.label,
                    'category_color', ec.color,
                    'visibility', ce.visibility,
                    'uid', ce.uid,
                    'owner_name', e.name,
                    'cvp_id', ce.cvp_id,
                    'cvp_name', ce.cvp_name,
                    'cvp_type', ce.cvp_type,
                    'order_id', ce.order_id
                ) ORDER BY ce.dtstart)
                FROM calendar_events ce
                LEFT JOIN event_category ec ON ec.id = ce.category_id
                LEFT JOIN employee e ON e.id = ce.uid
                WHERE (
                    (ce.dtstart >= $startDate::timestamp AND ce.dtstart < $endDate::timestamp)
                    OR (ce.dtend > $startDate::timestamp AND ce.dtend <= $endDate::timestamp)
                    OR (ce.dtstart <= $startDate::timestamp AND COALESCE(ce.dtend, ce.dtstart) >= $endDate::timestamp)
                )
                AND (ce.uid = $employeeId OR ce.visibility = -1)
            ), '[]'::json)
        ) AS result
    SQL;

    echo $mandant->get($query);
}

/**
 * Sucht Kalender-Events über alle Zeiträume
 *
 * @param string $data['query'] Suchbegriff (min. 2 Zeichen)
 * @testdata {"action": "searchCalendarEvents", "query": "Geburtstag"}
 */
function searchCalendarEvents($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForCalendar($mandant, $auth->getLogin());
    if ($employeeId === 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter gefunden');
        return;
    }

    $query = trim($data['query'] ?? '');
    if (mb_strlen($query) < 2) {
        resultInfo(true, '', ['results' => json_encode(['events' => []])]);
        return;
    }

    $pdo = $mandant->getPDO();
    $like = $pdo->quote('%' . mb_strtolower($query) . '%');

    $sql = <<<SQL
        SELECT json_build_object(
            'events', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', ce.id,
                    'title', ce.title,
                    'description', ce.description,
                    'dtstart', ce.dtstart,
                    'dtend', ce.dtend,
                    'allDay', ce."allDay",
                    'location', ce.location,
                    'color', COALESCE(ce.color, ec.color, '#1976D2'),
                    'prio', ce.prio,
                    'category_id', ce.category_id,
                    'category_label', ec.label,
                    'category_color', ec.color,
                    'visibility', ce.visibility,
                    'uid', ce.uid,
                    'owner_name', e.name,
                    'cvp_id', ce.cvp_id,
                    'cvp_name', ce.cvp_name,
                    'cvp_type', ce.cvp_type,
                    'order_id', ce.order_id
                ) ORDER BY ce.dtstart DESC)
                FROM calendar_events ce
                LEFT JOIN event_category ec ON ec.id = ce.category_id
                LEFT JOIN employee e ON e.id = ce.uid
                WHERE (ce.uid = $employeeId OR ce.visibility = -1)
                AND (
                    LOWER(ce.title) LIKE $like
                    OR LOWER(ce.description) LIKE $like
                    OR LOWER(ce.location) LIKE $like
                    OR LOWER(ce.cvp_name) LIKE $like
                )
            ), '[]'::json)
        ) AS result
    SQL;

    echo $mandant->get($sql);
}

/**
 * Lädt ein einzelnes Kalender-Event
 *
 * @param array $data Mit id
 * @testdata {"action": "getCalendarEvent", "id": 1}
 */
function getCalendarEvent($data) {
    $mandant = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    $query = <<<SQL
        SELECT json_build_object(
            'event', (
                SELECT json_build_object(
                    'id', ce.id,
                    'title', ce.title,
                    'description', ce.description,
                    'dtstart', ce.dtstart,
                    'dtend', ce.dtend,
                    'allDay', ce."allDay",
                    'location', ce.location,
                    'color', COALESCE(ce.color, ec.color, '#1976D2'),
                    'prio', ce.prio,
                    'category_id', ce.category_id,
                    'category_label', ec.label,
                    'category_color', ec.color,
                    'visibility', ce.visibility,
                    'uid', ce.uid,
                    'owner_name', e.name,
                    'cvp_id', ce.cvp_id,
                    'cvp_name', ce.cvp_name,
                    'cvp_type', ce.cvp_type,
                    'order_id', ce.order_id
                )
                FROM calendar_events ce
                LEFT JOIN event_category ec ON ec.id = ce.category_id
                LEFT JOIN employee e ON e.id = ce.uid
                WHERE ce.id = $id
            )
        ) AS result
    SQL;

    echo $mandant->get($query);
}

/**
 * Erstellt einen neuen Kalendertermin
 *
 * @param array $data Mit title, dtstart, dtend, allDay, description, location, etc.
 * @testdata {"action": "createCalendarEvent", "title": "Test", "dtstart": "2026-02-20 10:00:00", "dtend": "2026-02-20 11:00:00"}
 */
function createCalendarEvent($data) {
    $mandant = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForCalendar($mandant, $auth->getLogin());
    if ($employeeId === 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter gefunden');
        return;
    }

    if (empty($data['title'])) {
        resultInfo(false, 'MISSING_TITLE', 'Titel erforderlich');
        return;
    }
    if (empty($data['dtstart'])) {
        resultInfo(false, 'MISSING_START', 'Startdatum erforderlich');
        return;
    }

    $pdo = $mandant->getPDO();
    $title = $pdo->quote($data['title']);
    $description = $pdo->quote($data['description'] ?? '');
    $dtstart = $pdo->quote($data['dtstart']);
    $dtend = !empty($data['dtend']) ? $pdo->quote($data['dtend']) : 'NULL';
    $allDay = !empty($data['allDay']) ? 'TRUE' : 'FALSE';
    $location = $pdo->quote($data['location'] ?? '');
    $color = !empty($data['color']) ? $pdo->quote($data['color']) : 'NULL';
    $prio = intval($data['prio'] ?? 1);
    $categoryId = !empty($data['category_id']) ? intval($data['category_id']) : 'NULL';
    $visibility = intval($data['visibility'] ?? -1);
    $cvpId = !empty($data['cvp_id']) ? intval($data['cvp_id']) : 'NULL';
    $cvpName = !empty($data['cvp_name']) ? $pdo->quote($data['cvp_name']) : 'NULL';
    $cvpType = !empty($data['cvp_type']) ? $pdo->quote($data['cvp_type']) : 'NULL';
    $orderId = !empty($data['order_id']) ? intval($data['order_id']) : 'NULL';

    $query = <<<SQL
        INSERT INTO calendar_events
            (title, description, dtstart, dtend, "allDay", location, color, prio,
             category_id, visibility, uid, cvp_id, cvp_name, cvp_type, order_id)
        VALUES
            ($title, $description, $dtstart, $dtend, $allDay, $location, $color, $prio,
             $categoryId, $visibility, $employeeId, $cvpId, $cvpName, $cvpType, $orderId)
        RETURNING id
    SQL;

    try {
        $result = $mandant->fetch($query);
        $data['id'] = $result['id'];
        getCalendarEvent($data);
    } catch (Exception $e) {
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Aktualisiert einen Kalendertermin
 *
 * @param array $data Mit id und beliebigen Feldern
 * @testdata {"action": "updateCalendarEvent", "id": 1, "title": "Geändert"}
 */
function updateCalendarEvent($data) {
    $mandant = DbhCompany::begin();
    $pdo = $mandant->getPDO();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    $updates = [];

    if (isset($data['title'])) $updates[] = "title = " . $pdo->quote($data['title']);
    if (isset($data['description'])) $updates[] = "description = " . $pdo->quote($data['description']);
    if (isset($data['dtstart'])) $updates[] = "dtstart = " . $pdo->quote($data['dtstart']);
    if (isset($data['dtend'])) $updates[] = "dtend = " . ($data['dtend'] ? $pdo->quote($data['dtend']) : 'NULL');
    if (isset($data['allDay'])) $updates[] = '"allDay" = ' . ($data['allDay'] ? 'TRUE' : 'FALSE');
    if (isset($data['location'])) $updates[] = "location = " . $pdo->quote($data['location']);
    if (isset($data['color'])) $updates[] = "color = " . ($data['color'] ? $pdo->quote($data['color']) : 'NULL');
    if (isset($data['prio'])) $updates[] = "prio = " . intval($data['prio']);
    if (isset($data['category_id'])) $updates[] = "category_id = " . ($data['category_id'] ? intval($data['category_id']) : 'NULL');
    if (isset($data['visibility'])) $updates[] = "visibility = " . intval($data['visibility']);
    if (isset($data['cvp_id'])) $updates[] = "cvp_id = " . ($data['cvp_id'] ? intval($data['cvp_id']) : 'NULL');
    if (isset($data['cvp_name'])) $updates[] = "cvp_name = " . ($data['cvp_name'] ? $pdo->quote($data['cvp_name']) : 'NULL');
    if (isset($data['cvp_type'])) $updates[] = "cvp_type = " . ($data['cvp_type'] ? $pdo->quote($data['cvp_type']) : 'NULL');
    if (isset($data['order_id'])) $updates[] = "order_id = " . ($data['order_id'] ? intval($data['order_id']) : 'NULL');

    if (empty($updates)) {
        getCalendarEvent($data);
        return;
    }

    $updates[] = "mtime = NOW()";
    $updateSql = implode(', ', $updates);

    try {
        $mandant->query("UPDATE calendar_events SET $updateSql WHERE id = $id");
        getCalendarEvent($data);
    } catch (Exception $e) {
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Löscht einen Kalendertermin
 *
 * @param array $data Mit id
 * @testdata {"action": "deleteCalendarEvent", "id": 1}
 */
function deleteCalendarEvent($data) {
    $mandant = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    $mandant->query("DELETE FROM calendar_events WHERE id = $id");
    resultInfo(true, 'DELETED', 'Termin gelöscht');
}

/**
 * Verschiebt/Resized einen Kalendertermin (Drag&Drop / Resize)
 *
 * @param array $data Mit id, dtstart, dtend, allDay
 * @testdata {"action": "moveCalendarEvent", "id": 1, "dtstart": "2026-02-21 10:00:00", "dtend": "2026-02-21 11:00:00"}
 */
function moveCalendarEvent($data) {
    $mandant = DbhCompany::begin();
    $pdo = $mandant->getPDO();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungültige ID');
        return;
    }

    $updates = [];

    if (isset($data['dtstart'])) $updates[] = "dtstart = " . $pdo->quote($data['dtstart']);
    if (isset($data['dtend'])) $updates[] = "dtend = " . ($data['dtend'] ? $pdo->quote($data['dtend']) : 'NULL');
    if (isset($data['allDay'])) $updates[] = '"allDay" = ' . ($data['allDay'] ? 'TRUE' : 'FALSE');
    $updates[] = "mtime = NOW()";

    $updateSql = implode(', ', $updates);

    try {
        $mandant->query("UPDATE calendar_events SET $updateSql WHERE id = $id");

        // Rücksync: Wenn der Kalendereintrag zu einem Auftrag gehört, oe_ext aktualisieren
        if (isset($data['dtstart'])) {
            $event = $mandant->getOne(
                "SELECT order_id, color FROM calendar_events WHERE id = :id",
                [':id' => $id]
            );
            if ($event && !empty($event['order_id'])) {
                $field = null;
                if ($event['color'] === '#FF9800') {
                    $field = 'bringetermin';
                } elseif ($event['color'] === '#4CAF50') {
                    $field = 'fertigstellung';
                }
                if ($field) {
                    $mandant->execute(
                        "UPDATE oe_ext SET $field = :ts WHERE oe_id = :oe_id",
                        [':ts' => $data['dtstart'], ':oe_id' => intval($event['order_id'])]
                    );
                }
            }
        }

        resultInfo(true, 'MOVED', 'Termin verschoben');
    } catch (Exception $e) {
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}
