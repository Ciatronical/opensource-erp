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
 * Baut ein einheitliches Event-Objekt für die API-Antwort.
 * $dtstart/$dtend können von der Originaleintragung abweichen (Wiederholungs-Vorkommen).
 */
function buildCalendarEventObject($row, $dtstart, $dtend) {
    $isRecurring = !empty($row['freq']);

    // Alte Feiertag-Einträge: allDay=false aber Dauer >= 8h → ausblenden
    // (der Feiertag erscheint bereits über getPublicHolidays oben in der Ganztags-Zeile)
    $isAllDayRow = (bool)($row['allDay'] ?? false);
    if (!$isAllDayRow && !empty($dtstart) && !empty($dtend)) {
        $s = new DateTime($dtstart);
        $e = new DateTime($dtend);
        if (($e->getTimestamp() - $s->getTimestamp()) >= 28800) {  // >= 8 Stunden
            return null;
        }
    }

    // Dauer berechnen (für FullCalendar RRule-Plugin, nur bei Uhrzeitterminen)
    $duration = null;
    if ($isRecurring && !$isAllDayRow && !empty($dtstart) && !empty($dtend)) {
        $s = new DateTime($dtstart);
        $e = new DateTime($dtend);
        $diff = $s->diff($e);
        $h = $diff->h + $diff->days * 24;
        $m = $diff->i;
        $duration = sprintf('%02d:%02d', $h, $m);
    }

    $obj = [
        'id'             => intval($row['id']),
        'title'          => $row['title'],
        'description'    => $row['description'],
        'dtstart'        => $dtstart,
        'dtend'          => $isRecurring ? null : $dtend,  // bei Wiederholung steuert 'duration'
        'duration'       => $duration,
        'allDay'         => (bool)($row['allDay'] ?? false),
        'location'       => $row['location'],
        'color'          => $row['color'] ?? $row['category_color'] ?? '#1976D2',
        'prio'           => intval($row['prio'] ?? 1),
        'category_id'    => isset($row['category_id']) ? intval($row['category_id']) : null,
        'category_label' => $row['category_label'] ?? null,
        'category_color' => $row['category_color'] ?? null,
        'visibility'     => intval($row['visibility'] ?? -1),
        'uid'            => intval($row['uid']),
        'owner_name'     => $row['owner_name'] ?? null,
        'cvp_id'         => !empty($row['cvp_id']) ? intval($row['cvp_id']) : null,
        'cvp_name'       => $row['cvp_name'] ?? null,
        'cvp_type'       => $row['cvp_type'] ?? null,
        'order_id'       => !empty($row['order_id']) ? intval($row['order_id']) : null,
        'freq'           => $row['freq'] ?? null,
        'recur_interval' => !empty($row['interval']) ? intval($row['interval']) : null,
        'count'          => !empty($row['count']) ? intval($row['count']) : null,
        'repeat_end'     => $row['repeat_end'] ?? null,
    ];

    // rrule-Objekt für FullCalendar RRule-Plugin (nur bei Wiederholungen)
    if ($isRecurring) {
        // Ganztägig: dtstart ohne Uhrzeit, sonst erkennt FullCalendar es nicht als allDay
        $rruleStart = (bool)($row['allDay'] ?? false) ? substr($dtstart, 0, 10) : $dtstart;
        $rrule = [
            'freq'     => $row['freq'],
            'interval' => !empty($row['interval']) ? intval($row['interval']) : 1,
            'dtstart'  => $rruleStart,
        ];
        if (!empty($row['count'])) {
            $rrule['count'] = intval($row['count']);
        } elseif (!empty($row['repeat_end'])) {
            $rrule['until'] = substr($row['repeat_end'], 0, 10); // nur Datum
        }
        $obj['rrule'] = $rrule;
    }

    return $obj;
}

/**
 * Expandiert ein wiederkehrendes Ereignis für den angegebenen Datumsbereich.
 * Gibt ein Array von ['start' => ..., 'end' => ...] zurück.
 */
function expandCalendarRecurring($event, DateTime $rangeStart, DateTime $rangeEnd) {
    $freq      = $event['freq'];
    $interval  = max(1, intval($event['interval'] ?? 1));
    $maxCount  = !empty($event['count']) ? intval($event['count']) : null;
    $repeatEnd = !empty($event['repeat_end']) ? new DateTime($event['repeat_end']) : null;

    $duration = null;
    if (!empty($event['dtend']) && !empty($event['dtstart'])) {
        $duration = (new DateTime($event['dtstart']))->diff(new DateTime($event['dtend']));
    }

    $current    = new DateTime($event['dtstart']);
    $occurrences = [];
    $totalCount  = 0;

    while ($totalCount < 3000) {
        if ($maxCount !== null && $totalCount >= $maxCount) break;
        if ($repeatEnd !== null && $current > $repeatEnd) break;
        if ($current > $rangeEnd) break;

        if ($current >= $rangeStart) {
            $occEnd = null;
            if ($duration !== null) {
                $endDt = clone $current;
                $endDt->add($duration);
                $occEnd = $endDt->format('Y-m-d H:i:s');
            }
            $occurrences[] = [
                'start' => $current->format('Y-m-d H:i:s'),
                'end'   => $occEnd,
            ];
        }

        switch ($freq) {
            case 'daily':   $current->modify("+{$interval} days");   break;
            case 'weekly':  $current->modify("+{$interval} weeks");  break;
            case 'monthly': $current->modify("+{$interval} months"); break;
            case 'yearly':  $current->modify("+{$interval} years");  break;
            default: break 2;
        }
        $totalCount++;
    }

    return $occurrences;
}

/**
 * Berechnet repeat_end aus dtstart + count * interval.
 * Format: 'YYYY-MM-DD 23:59:00' (kompatibel mit altem System).
 */
function calcRepeatEnd($dtstart, $freq, $interval, $count) {
    $dt = new DateTime($dtstart);
    switch ($freq) {
        case 'daily':   $dt->modify("+{$count} days");   break;
        case 'weekly':  $dt->modify("+{$count} weeks");  break;
        case 'monthly': $dt->modify("+{$count} months"); break;
        case 'yearly':  $dt->modify("+{$count} years");  break;
    }
    return $dt->format('Y-m-d') . ' 23:59:00';
}

/**
 * Lädt Kalendertermine für einen Datumsbereich, expandiert Wiederholungen.
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

    $startDate = $data['startDate'] ?? date('Y-m-01');
    $endDate   = $data['endDate']   ?? date('Y-m-t');

    $rows = $mandant->getAll(
        "WITH p AS (SELECT :start::timestamp AS s, :end::timestamp AS e)
         SELECT ce.id, ce.title, ce.description,
                ce.dtstart, ce.dtend, ce.\"allDay\",
                ce.location, ce.color, ce.prio,
                ce.category_id, ec.label AS category_label, ec.color AS category_color,
                ce.visibility, ce.uid, e.name AS owner_name,
                ce.cvp_id, ce.cvp_name, ce.cvp_type, ce.order_id,
                ce.freq, ce.interval, ce.count, ce.repeat_end
         FROM calendar_events ce
         CROSS JOIN p
         LEFT JOIN event_category ec ON ec.id = ce.category_id
         LEFT JOIN employee e ON e.id = ce.uid
         WHERE (ce.uid = :uid OR ce.visibility = -1)
         AND (
             (ce.freq IS NULL AND (
                 (ce.dtstart >= p.s AND ce.dtstart < p.e)
                 OR (ce.dtend > p.s AND ce.dtend <= p.e)
                 OR (ce.dtstart <= p.s AND COALESCE(ce.dtend, ce.dtstart) >= p.e)
             ))
             OR (ce.freq IS NOT NULL
                 AND ce.dtstart <= p.e
                 AND (ce.repeat_end >= p.s OR ce.repeat_end IS NULL))
         )
         ORDER BY ce.dtstart",
        [':start' => $startDate, ':end' => $endDate, ':uid' => $employeeId]
    );

    $rangeStart = new DateTime($startDate);
    $rangeEnd   = new DateTime($endDate);
    $events     = [];

    $events = [];
    foreach ($rows as $row) {
        $obj = buildCalendarEventObject($row, $row['dtstart'], $row['dtend']);
        if ($obj !== null) $events[] = $obj;
    }

    usort($events, fn($a, $b) => strcmp($a['dtstart'] ?? '', $b['dtstart'] ?? ''));
    resultInfo(true, '', ['events' => $events]);
}

/**
 * Sucht Kalender-Events über alle Zeiträume (gibt Basis-Events zurück, keine Expansion)
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
                    'order_id', ce.order_id,
                    'freq', ce.freq
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
 * Lädt ein einzelnes Kalender-Event inkl. Wiederholungsfelder
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

    $row = $mandant->getOne(
        "SELECT ce.id, ce.title, ce.description,
                ce.dtstart, ce.dtend, ce.\"allDay\",
                ce.location, ce.color, ce.prio,
                ce.category_id, ec.label AS category_label, ec.color AS category_color,
                ce.visibility, ce.uid, e.name AS owner_name,
                ce.cvp_id, ce.cvp_name, ce.cvp_type, ce.order_id,
                ce.freq, ce.interval, ce.count, ce.repeat_end
         FROM calendar_events ce
         LEFT JOIN event_category ec ON ec.id = ce.category_id
         LEFT JOIN employee e ON e.id = ce.uid
         WHERE ce.id = :id",
        [':id' => $id]
    );

    if (!$row) {
        resultInfo(false, 'NOT_FOUND', 'Termin nicht gefunden');
        return;
    }

    resultInfo(true, '', ['event' => buildCalendarEventObject($row, $row['dtstart'], $row['dtend'])]);
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
    $title       = $pdo->quote($data['title']);
    $description = $pdo->quote($data['description'] ?? '');
    $dtstart     = $pdo->quote($data['dtstart']);
    $dtend       = !empty($data['dtend']) ? $pdo->quote($data['dtend']) : 'NULL';
    $allDay      = !empty($data['allDay']) ? 'TRUE' : 'FALSE';
    $location    = $pdo->quote($data['location'] ?? '');
    $color       = !empty($data['color']) ? $pdo->quote($data['color']) : 'NULL';
    $prio        = intval($data['prio'] ?? 1);
    $categoryId  = !empty($data['category_id']) ? intval($data['category_id']) : 'NULL';
    $visibility  = intval($data['visibility'] ?? -1);
    $cvpId       = !empty($data['cvp_id']) ? intval($data['cvp_id']) : 'NULL';
    $cvpName     = !empty($data['cvp_name']) ? $pdo->quote($data['cvp_name']) : 'NULL';
    $cvpType     = !empty($data['cvp_type']) ? $pdo->quote($data['cvp_type']) : 'NULL';
    $orderId     = !empty($data['order_id']) ? intval($data['order_id']) : 'NULL';

    // Wiederholung
    [$freq, $interval, $count, $repeatEnd] = resolveRecurrence($data, $pdo);

    $query = <<<SQL
        INSERT INTO calendar_events
            (title, description, dtstart, dtend, "allDay", location, color, prio,
             category_id, visibility, uid, cvp_id, cvp_name, cvp_type, order_id,
             freq, interval, count, repeat_end)
        VALUES
            ($title, $description, $dtstart, $dtend, $allDay, $location, $color, $prio,
             $categoryId, $visibility, $employeeId, $cvpId, $cvpName, $cvpType, $orderId,
             $freq, $interval, $count, $repeatEnd)
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

    if (isset($data['title']))       $updates[] = "title = "       . $pdo->quote($data['title']);
    if (isset($data['description'])) $updates[] = "description = " . $pdo->quote($data['description']);
    if (isset($data['dtstart']))     $updates[] = "dtstart = "     . $pdo->quote($data['dtstart']);
    if (isset($data['dtend']))       $updates[] = "dtend = "       . ($data['dtend'] ? $pdo->quote($data['dtend']) : 'NULL');
    if (isset($data['allDay']))      $updates[] = '"allDay" = '    . ($data['allDay'] ? 'TRUE' : 'FALSE');
    if (isset($data['location']))    $updates[] = "location = "    . $pdo->quote($data['location']);
    if (isset($data['color']))       $updates[] = "color = "       . ($data['color'] ? $pdo->quote($data['color']) : 'NULL');
    if (isset($data['prio']))        $updates[] = "prio = "        . intval($data['prio']);
    if (isset($data['category_id'])) $updates[] = "category_id = " . ($data['category_id'] ? intval($data['category_id']) : 'NULL');
    if (isset($data['visibility']))  $updates[] = "visibility = "  . intval($data['visibility']);
    if (isset($data['cvp_id']))      $updates[] = "cvp_id = "      . ($data['cvp_id'] ? intval($data['cvp_id']) : 'NULL');
    if (isset($data['cvp_name']))    $updates[] = "cvp_name = "    . ($data['cvp_name'] ? $pdo->quote($data['cvp_name']) : 'NULL');
    if (isset($data['cvp_type']))    $updates[] = "cvp_type = "    . ($data['cvp_type'] ? $pdo->quote($data['cvp_type']) : 'NULL');
    if (isset($data['order_id']))    $updates[] = "order_id = "    . ($data['order_id'] ? intval($data['order_id']) : 'NULL');

    // Wiederholung immer mitschreiben wenn freq-Schlüssel vorhanden
    if (array_key_exists('freq', $data)) {
        [$freq, $interval, $count, $repeatEnd] = resolveRecurrence($data, $pdo);
        $updates[] = "freq = $freq";
        $updates[] = "interval = $interval";
        $updates[] = "count = $count";
        $updates[] = "repeat_end = $repeatEnd";
    }

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
 * Löst Wiederholungsfelder aus $data auf und gibt SQL-Fragmente zurück.
 * Kompatibel mit dem alten Kalender: repeat_end = dtstart + count * interval (23:59:00).
 *
 * @return array [$freq, $interval, $count, $repeatEnd] — jeweils SQL-Fragments (NULL oder quoted)
 */
function resolveRecurrence($data, $pdo) {
    $freqVal = $data['freq'] ?? null;
    if (empty($freqVal) || !in_array($freqVal, ['daily', 'weekly', 'monthly', 'yearly'])) {
        return ['NULL', 'NULL', 'NULL', 'NULL'];
    }

    $freq     = $pdo->quote($freqVal);
    $interval = max(1, intval($data['interval'] ?? $data['recur_interval'] ?? 1));
    $count    = !empty($data['count']) ? intval($data['count']) : null;

    // repeat_end: entweder direkt übergeben oder aus count berechnen
    $repeatEndVal = null;
    if (!empty($data['repeat_end'])) {
        // Nur Datum übergeben → 23:59:00 anhängen
        $d = $data['repeat_end'];
        if (strlen($d) <= 10) {
            $repeatEndVal = $d . ' 23:59:00';
        } else {
            $repeatEndVal = $d;
        }
    } elseif ($count !== null && !empty($data['dtstart'])) {
        $repeatEndVal = calcRepeatEnd($data['dtstart'], $freqVal, $interval, $count);
    }

    return [
        $freq,
        intval($interval),
        $count !== null ? intval($count) : 'NULL',
        $repeatEndVal ? $pdo->quote($repeatEndVal) : 'NULL',
    ];
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
    if (isset($data['dtend']))   $updates[] = "dtend = "   . ($data['dtend'] ? $pdo->quote($data['dtend']) : 'NULL');
    if (isset($data['allDay']))  $updates[] = '"allDay" = ' . ($data['allDay'] ? 'TRUE' : 'FALSE');
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

// ============================================================================
// PUBLIC HOLIDAYS
// ============================================================================

function mapCountryToISO($raw) {
    $map = [
        'de' => 'DE', 'd' => 'DE', 'deutschland' => 'DE', 'germany' => 'DE',
        'at' => 'AT', 'a' => 'AT', 'österreich' => 'AT', 'oesterreich' => 'AT', 'austria' => 'AT',
        'ch' => 'CH', 'schweiz' => 'CH', 'switzerland' => 'CH',
        'fr' => 'FR', 'frankreich' => 'FR', 'france' => 'FR',
        'nl' => 'NL', 'niederlande' => 'NL', 'netherlands' => 'NL',
        'be' => 'BE', 'belgien' => 'BE', 'belgium' => 'BE',
        'pl' => 'PL', 'polen' => 'PL', 'poland' => 'PL',
        'it' => 'IT', 'italien' => 'IT', 'italy' => 'IT',
        'es' => 'ES', 'spanien' => 'ES', 'spain' => 'ES',
        'gb' => 'GB', 'uk' => 'GB', 'grossbritannien' => 'GB', 'united kingdom' => 'GB',
        'lu' => 'LU', 'luxemburg' => 'LU', 'luxembourg' => 'LU',
        'dk' => 'DK', 'daenemark' => 'DK', 'dänemark' => 'DK', 'denmark' => 'DK',
        'se' => 'SE', 'schweden' => 'SE', 'sweden' => 'SE',
        'no' => 'NO', 'norwegen' => 'NO', 'norway' => 'NO',
        'fi' => 'FI', 'finnland' => 'FI', 'finland' => 'FI',
        'cz' => 'CZ', 'tschechien' => 'CZ', 'czech republic' => 'CZ',
        'hu' => 'HU', 'ungarn' => 'HU', 'hungary' => 'HU',
    ];
    return $map[strtolower(trim($raw))] ?? null;
}

function mapBundeslandToCode($bundesland) {
    $map = [
        'Baden-Württemberg'      => 'DE-BW',
        'Bayern'                 => 'DE-BY',
        'Berlin'                 => 'DE-BE',
        'Brandenburg'            => 'DE-BB',
        'Bremen'                 => 'DE-HB',
        'Hamburg'                => 'DE-HH',
        'Hessen'                 => 'DE-HE',
        'Mecklenburg-Vorpommern' => 'DE-MV',
        'Niedersachsen'          => 'DE-NI',
        'Nordrhein-Westfalen'    => 'DE-NW',
        'Rheinland-Pfalz'        => 'DE-RP',
        'Saarland'               => 'DE-SL',
        'Sachsen'                => 'DE-SN',
        'Sachsen-Anhalt'         => 'DE-ST',
        'Schleswig-Holstein'     => 'DE-SH',
        'Thüringen'              => 'DE-TH',
    ];
    return $map[trim($bundesland)] ?? '';
}

/**
 * Öffentliche Feiertage für den Firmenstandort laden.
 * Nutzt nager.date API, gecacht in defaults_oserp pro Jahr und Region.
 * Für Deutschland wird das Bundesland anhand der Firmen-PLZ ermittelt.
 *
 * @param int $data['year'] Kalenderjahr (optional, default: aktuelles Jahr)
 * @testdata {"year": 2026}
 */
function getPublicHolidays($data) {
    $db = DbhCompany::begin();
    $year = max(2000, min(2100, intval($data['year'] ?? date('Y'))));

    $loc = $db->getOne(<<<SQL
        SELECT
            d.address_country,
            TRIM(d.address_zipcode) AS plz,
            TRIM(z.bundesland)      AS bundesland
        FROM defaults d
        LEFT JOIN zipcode_location_oserp z ON TRIM(z.plz) = TRIM(d.address_zipcode)
        LIMIT 1
    SQL);

    $countryCode = mapCountryToISO($loc['address_country'] ?? '');
    if (!$countryCode) {
        resultInfo(true, '', ['holidays' => []]);
        return;
    }

    $stateCode = ($countryCode === 'DE' && !empty($loc['bundesland']))
        ? mapBundeslandToCode($loc['bundesland'])
        : '';

    $cacheKey = 'holidays_' . $year . '_' . $countryCode . ($stateCode ? '_' . str_replace('-', '', $stateCode) : '');

    $cached = $db->getOne(
        "SELECT value, mtime FROM defaults_oserp WHERE key = :key",
        [':key' => $cacheKey]
    );

    if ($cached) {
        $maxAge = ($year < (int)date('Y')) ? PHP_INT_MAX : 86400 * 7;
        if (time() - strtotime($cached['mtime']) < $maxAge) {
            resultInfo(true, '', ['holidays' => json_decode($cached['value'], true)]);
            return;
        }
    }

    $url = "https://date.nager.at/api/v3/PublicHolidays/{$year}/{$countryCode}";
    $ctx = stream_context_create(['http' => [
        'timeout' => 5,
        'header'  => "User-Agent: oserp/1.0\r\n",
    ]]);
    $json = @file_get_contents($url, false, $ctx);

    if ($json === false) {
        $holidays = $cached ? json_decode($cached['value'], true) : [];
        resultInfo(true, '', ['holidays' => $holidays]);
        return;
    }

    $all = json_decode($json, true) ?? [];

    if ($stateCode) {
        $all = array_values(array_filter($all, fn($h) =>
            !isset($h['counties']) || $h['counties'] === null || in_array($stateCode, $h['counties'])
        ));
    }

    $result = array_map(fn($h) => [
        'date' => $h['date'],
        'name' => $h['localName'],
    ], $all);

    $db->execute(
        "INSERT INTO defaults_oserp (key, value)
         VALUES (:key, :value)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
        [':key' => $cacheKey, ':value' => json_encode($result)]
    );

    resultInfo(true, '', ['holidays' => $result]);
}

/**
 * Sendet einen Steuerbefehl an die Wandanzeige via pg_notify.
 * Nur für User erlaubt die in der Config "wall_display_controllers" stehen.
 *
 * @param string $data['view']      FullCalendar-View-Name (z.B. dayGridMonth)
 * @param string $data['startDate'] ISO-Datum des Bereichsanfangs
 * @testdata {"action": "setWallDisplayView", "view": "dayGridMonth", "startDate": "2026-05-01"}
 */
function setWallDisplayView($data) {
    $mandant = DbhCompany::begin();
    $auth    = DbhAuth::begin();
    $auth->fetchSessionData();

    $login = $auth->getLogin();

    // Berechtigungsprüfung: ist der User in wall_display_controllers?
    $cfg     = $mandant->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'wall_display_controllers'",
        []
    );
    $allowed = array_filter(array_map('trim', explode(',', $cfg['value'] ?? '')));

    if (empty($allowed) || !in_array($login, $allowed, true)) {
        resultInfo(false, 'NOT_AUTHORIZED', 'Nicht berechtigt');
        return;
    }

    $view      = $data['view']      ?? 'timeGridCustomWeek';
    $startDate = $data['startDate'] ?? date('Y-m-d');

    $pdo     = $mandant->getPDO();
    $payload = $pdo->quote(json_encode(['view' => $view, 'startDate' => $startDate]));
    $mandant->execute("SELECT pg_notify('wall_display_command', $payload)", []);

    resultInfo(true, '', []);
}

// ============================================================================
// IMPORT
// ============================================================================

/**
 * Importiert Kalendertermine aus einer Datei (iCal, CSV oder TXT).
 * preview=true liefert nur die geparsten Termine zurück (kein Speichern).
 *
 * @param string $data['content']     Dateiinhalt (UTF-8 Text)
 * @param string $data['format']      ical | csv | txt | auto
 * @param int    $data['offset_days'] Tage verschieben (negativ = früher)
 * @param int    $data['category_id'] Zielkategorie (optional)
 * @param string $data['color']       Farbe für alle importierten Termine (optional)
 * @param int    $data['visibility']  -1=alle, 0=privat
 * @param bool   $data['preview']     true = nur Vorschau, kein Speichern
 * @testdata {"action": "importCalendarEvents", "content": "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Restmüll\r\nDTSTART;VALUE=DATE:20260615\r\nEND:VEVENT\r\nEND:VCALENDAR", "format": "ical", "offset_days": -1, "preview": true}
 */
function importCalendarEvents($data) {
    $mandant = DbhCompany::begin();
    $auth    = DbhAuth::begin();
    $auth->fetchSessionData();

    $employeeId = getEmployeeIdForCalendar($mandant, $auth->getLogin());
    if ($employeeId === 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter gefunden');
        return;
    }

    $content    = $data['content']   ?? '';
    $format     = strtolower($data['format']      ?? 'auto');
    $offsetDays = intval($data['offset_days']      ?? 0);
    $categoryId = !empty($data['category_id'])     ? intval($data['category_id']) : null;
    $color      = !empty($data['color'])           ? $data['color']               : null;
    $visibility = intval($data['visibility']       ?? -1);
    $preview    = !empty($data['preview']);

    if (empty(trim($content))) {
        resultInfo(false, 'EMPTY_CONTENT', 'Kein Dateiinhalt übergeben');
        return;
    }

    if ($format === 'auto') {
        if (strpos($content, 'BEGIN:VCALENDAR') !== false || strpos($content, 'BEGIN:VEVENT') !== false) {
            $format = 'ical';
        } elseif (preg_match('/^[^\r\n;,]*[;,][^\r\n;,]/m', $content)) {
            $format = 'csv';
        } else {
            $format = 'txt';
        }
    }

    switch ($format) {
        case 'ical': $events = parseICalEvents($content); break;
        case 'csv':  $events = parseCsvEvents($content);  break;
        default:     $events = parseTxtEvents($content);  break;
    }

    if (empty($events)) {
        resultInfo(false, 'NO_EVENTS', 'Keine Termine in der Datei gefunden');
        return;
    }

    if ($offsetDays !== 0) {
        foreach ($events as &$ev) {
            $ev['dtstart'] = shiftDateByDays($ev['dtstart'], $offsetDays);
            if (!empty($ev['dtend'])) $ev['dtend'] = shiftDateByDays($ev['dtend'], $offsetDays);
        }
        unset($ev);
    }

    if ($preview) {
        resultInfo(true, '', ['events' => $events, 'count' => count($events)]);
        return;
    }

    $pdo = $mandant->getPDO();
    $inserted = 0;
    $errors   = 0;

    foreach ($events as $ev) {
        $title       = $pdo->quote($ev['title']       ?? 'Import');
        $description = $pdo->quote($ev['description'] ?? '');
        $location    = $pdo->quote($ev['location']    ?? '');
        $dtstart     = $pdo->quote($ev['dtstart']);
        $dtend       = !empty($ev['dtend']) ? $pdo->quote($ev['dtend']) : 'NULL';
        $allDay      = !empty($ev['allDay']) ? 'TRUE' : 'FALSE';
        $colorSql    = $color      ? $pdo->quote($color)  : 'NULL';
        $catSql      = $categoryId ? intval($categoryId)  : 'NULL';

        try {
            $mandant->query(
                "INSERT INTO calendar_events
                    (title, description, dtstart, dtend, \"allDay\", location, color,
                     category_id, visibility, uid)
                 VALUES
                    ($title, $description, $dtstart, $dtend, $allDay, $location, $colorSql,
                     $catSql, $visibility, $employeeId)"
            );
            $inserted++;
        } catch (Exception $e) {
            $errors++;
        }
    }

    resultInfo(true, '', ['inserted' => $inserted, 'errors' => $errors, 'total' => count($events)]);
}

/**
 * Verschiebt ein Datum um $days Tage.
 */
function shiftDateByDays($dateStr, $days) {
    try {
        $dt = new DateTime($dateStr);
        $dt->modify("{$days} days");
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $dateStr;
    }
}

/**
 * Parst einen iCalendar-Inhalt und extrahiert alle VEVENT-Blöcke.
 */
function parseICalEvents($content) {
    $events = [];

    // RFC 5545 Line-Unfolding: CRLF + SPACE/TAB = Fortsetzungszeile
    $content = preg_replace('/\r\n[ \t]/', '', $content);
    $content = str_replace("\r\n", "\n", $content);
    $content = preg_replace('/\n[ \t]/', '', $content);

    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $content, $matches);

    foreach ($matches[1] as $block) {
        $props = [];
        foreach (explode("\n", trim($block)) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $rawKey  = trim(substr($line, 0, $pos));
            $value   = trim(substr($line, $pos + 1));
            $baseKey = explode(';', $rawKey)[0];
            $props[$rawKey]  = $value;
            if (!isset($props[$baseKey])) $props[$baseKey] = $value;
        }

        $rawDs   = $props['DTSTART'] ?? '';
        $dtstart = parseICalDate($rawDs);
        if (empty($dtstart)) continue;

        $dtend  = parseICalDate($props['DTEND'] ?? '');
        $digits = preg_replace('/[^0-9]/', '', $rawDs);
        $allDay = strlen($digits) === 8 || isset($props['DTSTART;VALUE=DATE']);

        $events[] = [
            'title'       => unescapeICalText($props['SUMMARY']     ?? 'Termin'),
            'description' => unescapeICalText($props['DESCRIPTION'] ?? ''),
            'location'    => unescapeICalText($props['LOCATION']    ?? ''),
            'dtstart'     => $dtstart,
            'dtend'       => $dtend ?: null,
            'allDay'      => $allDay,
        ];
    }

    return $events;
}

function parseICalDate($value) {
    if (empty($value)) return '';
    $v = preg_replace('/[^0-9T]/', '', $value);

    if (strlen($v) === 8) {
        return substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2) . ' 00:00:00';
    }
    if (strlen($v) >= 15 && isset($v[8]) && $v[8] === 'T') {
        $d = substr($v, 0, 8);
        $t = substr($v, 9, 6);
        return substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2)
             . ' ' . substr($t, 0, 2) . ':' . substr($t, 2, 2) . ':' . substr($t, 4, 2);
    }
    return '';
}

function unescapeICalText($text) {
    return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $text);
}

/**
 * Parst CSV-Inhalt. Erkennt automatisch Trennzeichen, Header-Zeile und Spalten.
 */
function parseCsvEvents($content) {
    $events = [];
    $lines  = preg_split('/\r?\n/', $content);
    if (empty($lines)) return $events;

    $sep = (substr_count($lines[0], ';') >= substr_count($lines[0], ',')) ? ';' : ',';

    $firstRow  = str_getcsv($lines[0], $sep);
    $lcHeaders = array_map('mb_strtolower', array_map('trim', $firstRow));

    $dateCol  = 0; $titleCol = 1; $descCol = -1; $locCol = -1;
    $hasHeader = false;

    foreach ($lcHeaders as $i => $h) {
        if (in_array($h, ['datum', 'date', 'start', 'startdatum', 'dtstart', 'von', 'beginn', 'anfang']))
            { $dateCol = $i; $hasHeader = true; }
        if (in_array($h, ['titel', 'title', 'betreff', 'subject', 'summary', 'name', 'bezeichnung', 'event', 'termin', 'fraktion', 'abfallart', 'art']))
            { $titleCol = $i; $hasHeader = true; }
        if (in_array($h, ['beschreibung', 'description', 'notiz', 'note', 'text', 'info']))
            { $descCol = $i; $hasHeader = true; }
        if (in_array($h, ['ort', 'location', 'adresse', 'address', 'place']))
            { $locCol = $i; $hasHeader = true; }
    }

    foreach (array_slice($lines, $hasHeader ? 1 : 0) as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $cols    = str_getcsv($line, $sep);
        $rawDate = trim($cols[$dateCol] ?? '');
        $title   = trim($cols[$titleCol] ?? '');
        if (empty($rawDate) || empty($title)) continue;
        $dtstart = parseDateFlexible($rawDate);
        if (empty($dtstart)) continue;
        $events[] = [
            'title'       => $title,
            'description' => $descCol >= 0 ? trim($cols[$descCol] ?? '') : '',
            'location'    => $locCol  >= 0 ? trim($cols[$locCol]  ?? '') : '',
            'dtstart'     => $dtstart,
            'dtend'       => null,
            'allDay'      => true,
        ];
    }

    return $events;
}

/**
 * Parst TXT-Inhalt: jede Zeile "DATUM[Trennzeichen]Titel" → ein Termin.
 */
function parseTxtEvents($content) {
    $events = [];
    foreach (preg_split('/\r?\n/', $content) as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        if (preg_match(
            '/^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\.\d{1,2}\.\d{4}|\d{1,2}\/\d{1,2}\/\d{4})[\s,;:]+(.+)$/',
            $line, $m
        )) {
            $dtstart = parseDateFlexible($m[1]);
            if (empty($dtstart)) continue;
            $events[] = [
                'title'       => trim($m[2]),
                'description' => '',
                'location'    => '',
                'dtstart'     => $dtstart,
                'dtend'       => null,
                'allDay'      => true,
            ];
        }
    }
    return $events;
}

/**
 * Erkennt und normalisiert gängige Datumsformate → Y-m-d 00:00:00.
 */
function parseDateFlexible($raw) {
    $raw = trim($raw);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m))
        return sprintf('%04d-%02d-%02d 00:00:00', $m[1], $m[2], $m[3]);
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $raw, $m))
        return sprintf('%04d-%02d-%02d 00:00:00', $m[3], $m[2], $m[1]);
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $raw, $m))
        return sprintf('%04d-%02d-%02d 00:00:00', $m[3], $m[1], $m[2]);
    $ts = @strtotime($raw);
    return $ts !== false ? date('Y-m-d 00:00:00', $ts) : '';
}

// ============================================================================

/**
 * Liefert die LxCars-Auslastung (geplante Minuten je Bringetermin-Tag) für einen Monat.
 * Nur sinnvoll wenn LxCars aktiviert ist.
 *
 * @param string $data['startDate'] Beginn des Bereichs (YYYY-MM-DD)
 * @param string $data['endDate']   Ende des Bereichs (YYYY-MM-DD)
 * @testdata {"action": "getMonthWorkload", "startDate": "2026-05-01", "endDate": "2026-05-31"}
 */
function getMonthWorkload($data) {
    $mandant = DbhCompany::begin();

    $startDate = $data['startDate'] ?? date('Y-m-01');
    $endDate   = $data['endDate']   ?? date('Y-m-t');

    try {
        $rows = $mandant->getAll(
            "SELECT
                 oe_ext.bringetermin::date                    AS work_date,
                 COALESCE(SUM(i.planned_minutes), 0)::int    AS total_minutes,
                 COUNT(DISTINCT oe.id)::int                  AS order_count,
                 COUNT(i.id)::int                            AS instruction_count
             FROM oe_ext
             JOIN oe ON oe.id = oe_ext.oe_id AND oe.closed = false
             LEFT JOIN oe_instructions_lxcars i ON i.oe_id = oe.id
             WHERE oe_ext.bringetermin IS NOT NULL
               AND oe_ext.bringetermin::date BETWEEN :start AND :end
             GROUP BY oe_ext.bringetermin::date
             ORDER BY work_date",
            [':start' => $startDate, ':end' => $endDate]
        );
    } catch (Exception $e) {
        // Tabelle existiert nicht (LxCars nicht installiert)
        resultInfo(true, '', ['workload' => [], 'capacity_minutes' => 0]);
        return;
    }

    // Tageskapazität: Arbeitszeit minus Pausen
    $getVal = fn($key, $default) =>
        $mandant->getOne("SELECT value FROM defaults_oserp WHERE key = :k", [':k' => $key])['value'] ?? $default;

    $toMin = fn($t) => (int)explode(':', $t)[0] * 60 + (int)(explode(':', $t)[1] ?? 0);

    $startMin = $toMin($getVal('lxcars_arbeitsbeginn', '08:00'));
    $endMin   = $toMin($getVal('lxcars_arbeitsende',   '17:00'));
    $pausenStr = $getVal('lxcars_pausen', '');
    $pauseMin  = 0;
    foreach (explode(',', $pausenStr) as $p) {
        if (preg_match('/(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})/', trim($p), $m)) {
            $pauseMin += ($m[3] * 60 + $m[4]) - ($m[1] * 60 + $m[2]);
        }
    }
    $capacityMinutes = max(0, ($endMin - $startMin) - $pauseMin);

    resultInfo(true, '', ['workload' => $rows, 'capacity_minutes' => $capacityMinutes]);
}
