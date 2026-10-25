<?php
// backend/api/lxcars/reports.php

/**
 * Berechnet Zeitraum-Grenzen aus Periode und Referenzdatum
 */
function _calcDateRange($period, $refDate) {
    switch ($period) {
        case 'day':
            return [$refDate, $refDate];
        case 'month':
            return [date('Y-m-01', strtotime($refDate)), date('Y-m-t', strtotime($refDate))];
        case 'week':
        default:
            $dt = new DateTime($refDate);
            $dow = intval($dt->format('N'));
            $dt->modify('-' . ($dow - 1) . ' days');
            $from = $dt->format('Y-m-d');
            $dt->modify('+6 days');
            return [$from, $dt->format('Y-m-d')];
    }
}

function _availableMinutesPerDay($arbeitsbeginn, $arbeitsende, $pausen) {
    $start = _reportTimeToMinutes($arbeitsbeginn ?: '08:00');
    $end = _reportTimeToMinutes($arbeitsende ?: '17:00');
    $total = max(0, $end - $start);
    if (trim($pausen)) {
        foreach (explode(',', $pausen) as $b) {
            $parts = explode('-', trim($b));
            if (count($parts) === 2) {
                $total -= max(0, _reportTimeToMinutes(trim($parts[1])) - _reportTimeToMinutes(trim($parts[0])));
            }
        }
    }
    return max(0, $total);
}

function _reportTimeToMinutes($t) {
    $p = explode(':', $t);
    return intval($p[0]) * 60 + intval($p[1] ?? 0);
}

/**
 * CTE „work": jede erfasste Arbeitszeit mit ihrem BEGINN.
 *
 * Protokollierte Timer-Sitzungen (oe_instruction_times_lxcars, je Tag) oder,
 * für Altdaten ohne Protokoll, eine Schätzung: Fertigstellung minus
 * Nettominuten in Arbeitstagen rückwärts, Wochenende übersprungen.
 * is_first = erster Arbeitsbeginn einer Anweisung – dort zählen Vorgabezeit
 * und Erledigung genau einmal. Gleiche Logik wie performance.php.
 * Die aufrufende Abfrage liefert den Platzhalter :avail (Nettominuten je Tag).
 *
 * @return string SQL-Text für "WITH <hier> SELECT ..."
 */
function _workTimesCte() {
    return "work_raw AS MATERIALIZED (
            SELECT t.instruction_id AS id, t.id AS sid, t.employee_id, t.started_at, t.minutes
            FROM oe_instruction_times_lxcars t
            UNION ALL
            SELECT i.id, 0, i.employee_id,
                   CASE EXTRACT(isodow FROM est.ts)::int
                       WHEN 7 THEN est.ts - interval '2 days'
                       WHEN 6 THEN est.ts - interval '1 day'
                       ELSE est.ts
                   END,
                   i.actual_minutes
            FROM oe_instructions_lxcars i
            CROSS JOIN LATERAL (
                SELECT i.done_at
                       - make_interval(days => i.actual_minutes / GREATEST(:avail::int, 60))
                       - make_interval(mins => i.actual_minutes % GREATEST(:avail2::int, 60)) AS ts
            ) est
            WHERE i.done = true AND i.actual_minutes > 0 AND i.done_at IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM oe_instruction_times_lxcars t WHERE t.instruction_id = i.id)
        ),
        work AS MATERIALIZED (
            SELECT i.id, i.oe_id,
                   COALESCE(w.employee_id, i.employee_id) AS employee_id,
                   i.description, i.planned_minutes, i.actual_minutes, i.done, i.done_at,
                   w.started_at, w.started_at::date AS work_date, w.minutes,
                   ROW_NUMBER() OVER (PARTITION BY i.id ORDER BY w.started_at, w.sid) = 1 AS is_first
            FROM work_raw w
            JOIN oe_instructions_lxcars i ON i.id = w.id
            WHERE w.minutes > 0
        )";
}

function _workDaysInRange($from, $to) {
    $start = new DateTime($from);
    $end = new DateTime($to);
    $end->modify('+1 day');
    $count = 0;
    $current = clone $start;
    while ($current < $end) {
        if (intval($current->format('N')) <= 5) $count++;
        $current->modify('+1 day');
    }
    return $count;
}

/**
 * Persoenliche Auswertung eines Mechanikers.
 *
 * @param int $data['employee_id'] Mitarbeiter-ID
 * @param string $data['period'] 'day' | 'week' | 'month'
 * @param string $data['date'] Referenzdatum (YYYY-MM-DD)
 * @param string $data['arbeitsbeginn'] Config
 * @param string $data['arbeitsende'] Config
 * @param string $data['pausen'] Config
 * @testdata {"employee_id": 5, "period": "week", "date": "2026-03-25", "arbeitsbeginn": "08:00", "arbeitsende": "17:00", "pausen": "09:00-09:30, 12:00-12:30"}
 */
function getMyReport($data) {
    $db = DbhCompany::begin();
    $employeeId = intval($data['employee_id']);
    $period = $data['period'] ?? 'week';
    $refDate = $data['date'] ?? date('Y-m-d');

    if (!$employeeId) {
        resultInfo(false, 'VALIDATION_ERROR: employee_id required');
        return;
    }

    [$dateFrom, $dateTo] = _calcDateRange($period, $refDate);

    $arbeitsbeginn = $data['arbeitsbeginn'] ?? '08:00';
    $arbeitsende = $data['arbeitsende'] ?? '17:00';
    $pausen = $data['pausen'] ?? '';
    $availPerDay = _availableMinutesPerDay($arbeitsbeginn, $arbeitsende, $pausen);
    $workDays = _workDaysInRange($dateFrom, $dateTo);

    // ── Produktive Minuten pro Tag (nach Arbeitsbeginn der Sitzungen) ──
    $wp = [':avail' => $availPerDay, ':avail2' => $availPerDay];
    $daily = $db->getAll(
        "WITH " . _workTimesCte() . "
         SELECT d.day::date AS work_date,
                COALESCE(SUM(w.minutes), 0) AS actual_minutes,
                COALESCE(SUM(w.planned_minutes) FILTER (WHERE w.is_first), 0) AS planned_minutes,
                COUNT(DISTINCT w.id) FILTER (WHERE w.is_first AND w.done) AS tasks_done,
                COUNT(DISTINCT w.oe_id) AS orders_touched
         FROM generate_series(:from::date, :to::date, '1 day'::interval) AS d(day)
         LEFT JOIN work w
             ON w.employee_id = :emp
             AND w.work_date = d.day::date
         GROUP BY d.day::date
         ORDER BY d.day::date",
        $wp + [':from' => $dateFrom, ':to' => $dateTo, ':emp' => $employeeId]
    );

    // ── Zusammenfassung im Zeitraum ──
    $summary = $db->getOne(
        "WITH " . _workTimesCte() . "
         SELECT COALESCE(SUM(minutes), 0) AS total_actual,
                COALESCE(SUM(planned_minutes) FILTER (WHERE is_first), 0) AS total_planned,
                COUNT(DISTINCT id) FILTER (WHERE is_first AND done) AS tasks_done,
                COUNT(DISTINCT id) AS tasks_total,
                COUNT(DISTINCT oe_id) AS orders_count
         FROM work
         WHERE employee_id = :emp
           AND work_date BETWEEN :from::date AND :to::date",
        $wp + [':emp' => $employeeId, ':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Bearbeitete Fahrzeuge im Zeitraum ──
    $vehicles = $db->getAll(
        "WITH " . _workTimesCte() . "
         SELECT o.ordnumber,
                COALESCE(ext.kennzeichen, c.c_ln) AS kennzeichen,
                c.c_m AS fahrzeugtyp,
                COALESCE(SUM(i.minutes), 0) AS actual_minutes,
                COALESCE(SUM(i.planned_minutes) FILTER (WHERE i.is_first), 0) AS planned_minutes,
                COUNT(DISTINCT i.id) FILTER (WHERE i.is_first AND i.done) AS tasks_done
         FROM work i
         JOIN oe o ON o.id = i.oe_id
         LEFT JOIN oe_ext ext ON ext.oe_id = i.oe_id
         LEFT JOIN cars_lxcars c ON c.c_id = ext.c_id
         WHERE i.employee_id = :emp
           AND i.work_date BETWEEN :from::date AND :to::date
         GROUP BY o.ordnumber, ext.kennzeichen, c.c_ln, c.c_m
         ORDER BY SUM(i.minutes) DESC
         LIMIT 25",
        $wp + [':emp' => $employeeId, ':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Top-Taetigkeiten im Zeitraum ──
    $topTasks = $db->getAll(
        "WITH " . _workTimesCte() . "
         SELECT description,
                COUNT(*) AS times_done,
                ROUND(AVG(actual_minutes)) AS avg_actual,
                ROUND(AVG(planned_minutes)) AS avg_planned
         FROM work
         WHERE employee_id = :emp AND done AND is_first
           AND work_date BETWEEN :from::date AND :to::date
         GROUP BY description
         ORDER BY COUNT(*) DESC
         LIMIT 10",
        $wp + [':emp' => $employeeId, ':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Persoenlicher Rekord (bester Tag aller Zeiten) ──
    $bestDay = $db->getOne(
        "WITH " . _workTimesCte() . "
         SELECT MAX(day_total) AS best_day_minutes
         FROM (
             SELECT work_date, SUM(minutes) AS day_total
             FROM work
             WHERE employee_id = :emp
             GROUP BY work_date
         ) sub",
        $wp + [':emp' => $employeeId]
    );

    resultInfo(true, 'OK', [
        'period' => $period,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'daily' => $daily ?: [],
        'summary' => $summary ?: [],
        'vehicles' => $vehicles ?: [],
        'top_tasks' => $topTasks ?: [],
        'best_day_minutes' => intval($bestDay['best_day_minutes'] ?? 0),
        'available_minutes_per_day' => $availPerDay,
        'work_days' => $workDays,
        'available_total' => $availPerDay * $workDays
    ]);
}

/**
 * Team-Auswertung fuer Werkstattleitung.
 *
 * @param string $data['period'] 'day' | 'week' | 'month'
 * @param string $data['date'] Referenzdatum
 * @param string $data['arbeitsbeginn'] Config
 * @param string $data['arbeitsende'] Config
 * @param string $data['pausen'] Config
 * @testdata {"period": "week", "date": "2026-03-25", "arbeitsbeginn": "08:00", "arbeitsende": "17:00", "pausen": "09:00-09:30, 12:00-12:30"}
 */
function getTeamReport($data) {
    $db = DbhCompany::begin();
    $period = $data['period'] ?? 'week';
    $refDate = $data['date'] ?? date('Y-m-d');

    [$dateFrom, $dateTo] = _calcDateRange($period, $refDate);

    $arbeitsbeginn = $data['arbeitsbeginn'] ?? '08:00';
    $arbeitsende = $data['arbeitsende'] ?? '17:00';
    $pausen = $data['pausen'] ?? '';
    $availPerDay = _availableMinutesPerDay($arbeitsbeginn, $arbeitsende, $pausen);
    $workDays = _workDaysInRange($dateFrom, $dateTo);

    // ── Pro Mechaniker im Zeitraum ──
    $wp = [':avail' => $availPerDay, ':avail2' => $availPerDay];
    $teamStats = $db->getAll(
        "WITH " . _workTimesCte() . "
         SELECT e.id AS employee_id,
                e.name AS employee_name,
                COALESCE(SUM(i.minutes), 0) AS actual_minutes,
                COALESCE(SUM(i.planned_minutes) FILTER (WHERE i.is_first), 0) AS planned_minutes,
                COUNT(DISTINCT i.id) FILTER (WHERE i.is_first AND i.done) AS done_count,
                COUNT(DISTINCT i.id) AS total_count,
                COUNT(DISTINCT i.oe_id) AS order_count
         FROM employee e
         JOIN work i ON i.employee_id = e.id
         WHERE i.work_date BETWEEN :from::date AND :to::date
         GROUP BY e.id, e.name
         ORDER BY SUM(i.minutes) DESC",
        $wp + [':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Pro Mechaniker pro Tag ──
    $dailyByEmployee = $db->getAll(
        "WITH " . _workTimesCte() . "
         SELECT emps.employee_id,
                e.name AS employee_name,
                d.day::date AS work_date,
                COALESCE(SUM(i.minutes), 0) AS actual_minutes
         FROM generate_series(:from::date, :to::date, '1 day'::interval) AS d(day)
         CROSS JOIN (
             SELECT DISTINCT employee_id FROM work
             WHERE work_date BETWEEN :from2::date AND :to2::date
         ) AS emps
         JOIN employee e ON e.id = emps.employee_id
         LEFT JOIN work i
             ON i.employee_id = emps.employee_id
             AND i.work_date = d.day::date
         GROUP BY emps.employee_id, e.name, d.day::date
         ORDER BY e.name, d.day::date",
        $wp + [':from' => $dateFrom, ':to' => $dateTo, ':from2' => $dateFrom, ':to2' => $dateTo]
    );

    // ── Fahrzeug-Zeiten im Zeitraum ──
    $vehicleTimes = $db->getAll(
        "WITH " . _workTimesCte() . "
         SELECT o.ordnumber,
                COALESCE(ext.kennzeichen, c.c_ln) AS kennzeichen,
                c.c_m AS fahrzeugtyp,
                COALESCE(SUM(i.minutes), 0) AS actual_minutes,
                COALESCE(SUM(i.planned_minutes) FILTER (WHERE i.is_first), 0) AS planned_minutes,
                COUNT(DISTINCT i.employee_id) AS mechanic_count,
                string_agg(DISTINCT e.name, ', ') AS mechanics
         FROM work i
         JOIN oe o ON o.id = i.oe_id
         LEFT JOIN oe_ext ext ON ext.oe_id = i.oe_id
         LEFT JOIN cars_lxcars c ON c.c_id = ext.c_id
         LEFT JOIN employee e ON e.id = i.employee_id
         WHERE i.work_date BETWEEN :from::date AND :to::date
         GROUP BY o.ordnumber, ext.kennzeichen, c.c_ln, c.c_m
         ORDER BY SUM(i.minutes) DESC
         LIMIT 50",
        $wp + [':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Team-Gesamtstatistik im Zeitraum ──
    $teamTotal = $db->getOne(
        "WITH " . _workTimesCte() . "
         SELECT COALESCE(SUM(minutes), 0) AS total_actual,
                COALESCE(SUM(planned_minutes) FILTER (WHERE is_first), 0) AS total_planned,
                COUNT(DISTINCT id) FILTER (WHERE is_first AND done) AS total_done,
                COUNT(DISTINCT id) AS total_count,
                COUNT(DISTINCT oe_id) AS total_orders,
                COUNT(DISTINCT employee_id) AS mechanic_count
         FROM work
         WHERE work_date BETWEEN :from::date AND :to::date",
        $wp + [':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Top-Taetigkeiten teamweit im Zeitraum ──
    $topTasks = $db->getAll(
        "WITH " . _workTimesCte() . "
         SELECT description,
                COUNT(*) AS times_done,
                ROUND(AVG(actual_minutes)) AS avg_actual,
                ROUND(AVG(planned_minutes)) AS avg_planned,
                COUNT(DISTINCT employee_id) AS mechanic_count
         FROM work
         WHERE done AND is_first
           AND work_date BETWEEN :from::date AND :to::date
         GROUP BY description
         ORDER BY COUNT(*) DESC
         LIMIT 10",
        $wp + [':from' => $dateFrom, ':to' => $dateTo]
    );

    resultInfo(true, 'OK', [
        'period' => $period,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'team' => $teamStats ?: [],
        'daily_by_employee' => $dailyByEmployee ?: [],
        'vehicles' => $vehicleTimes ?: [],
        'top_tasks' => $topTasks ?: [],
        'totals' => $teamTotal ?: [],
        'available_minutes_per_day' => $availPerDay,
        'work_days' => $workDays,
        'available_total' => $availPerDay * $workDays,
        'mechanic_count' => intval($teamTotal['mechanic_count'] ?? 0)
    ]);
}
