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

    // ── Produktive Minuten pro Tag (ueber done_at verknuepft) ──
    $daily = $db->getAll(
        "SELECT d.day::date AS work_date,
                COALESCE(SUM(i.actual_minutes), 0) AS actual_minutes,
                COALESCE(SUM(i.planned_minutes), 0) AS planned_minutes,
                COUNT(DISTINCT i.id) FILTER (WHERE i.done = true) AS tasks_done,
                COUNT(DISTINCT i.oe_id) AS orders_touched
         FROM generate_series(:from::date, :to::date, '1 day'::interval) AS d(day)
         LEFT JOIN oe_instructions_lxcars i
             ON i.employee_id = :emp
             AND i.actual_minutes > 0
             AND i.done_at::date = d.day::date
         GROUP BY d.day::date
         ORDER BY d.day::date",
        [':from' => $dateFrom, ':to' => $dateTo, ':emp' => $employeeId]
    );

    // ── Zusammenfassung im Zeitraum ──
    $summary = $db->getOne(
        "SELECT COALESCE(SUM(actual_minutes), 0) AS total_actual,
                COALESCE(SUM(planned_minutes), 0) AS total_planned,
                COUNT(DISTINCT id) FILTER (WHERE done = true) AS tasks_done,
                COUNT(DISTINCT id) AS tasks_total,
                COUNT(DISTINCT oe_id) AS orders_count
         FROM oe_instructions_lxcars
         WHERE employee_id = :emp
           AND actual_minutes > 0
           AND done_at::date BETWEEN :from::date AND :to::date",
        [':emp' => $employeeId, ':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Bearbeitete Fahrzeuge im Zeitraum ──
    $vehicles = $db->getAll(
        "SELECT o.ordnumber,
                COALESCE(ext.kennzeichen, c.c_ln) AS kennzeichen,
                c.c_m AS fahrzeugtyp,
                COALESCE(SUM(i.actual_minutes), 0) AS actual_minutes,
                COALESCE(SUM(i.planned_minutes), 0) AS planned_minutes,
                COUNT(DISTINCT i.id) FILTER (WHERE i.done = true) AS tasks_done
         FROM oe_instructions_lxcars i
         JOIN oe o ON o.id = i.oe_id
         LEFT JOIN oe_ext ext ON ext.oe_id = i.oe_id
         LEFT JOIN cars_lxcars c ON c.c_id = ext.c_id
         WHERE i.employee_id = :emp
           AND i.actual_minutes > 0
           AND i.done_at::date BETWEEN :from::date AND :to::date
         GROUP BY o.ordnumber, ext.kennzeichen, c.c_ln, c.c_m
         ORDER BY SUM(i.actual_minutes) DESC
         LIMIT 25",
        [':emp' => $employeeId, ':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Top-Taetigkeiten im Zeitraum ──
    $topTasks = $db->getAll(
        "SELECT description,
                COUNT(*) AS times_done,
                ROUND(AVG(actual_minutes)) AS avg_actual,
                ROUND(AVG(planned_minutes)) AS avg_planned
         FROM oe_instructions_lxcars
         WHERE employee_id = :emp AND done = true AND actual_minutes > 0
           AND done_at::date BETWEEN :from::date AND :to::date
         GROUP BY description
         ORDER BY COUNT(*) DESC
         LIMIT 10",
        [':emp' => $employeeId, ':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Persoenlicher Rekord (bester Tag aller Zeiten) ──
    $bestDay = $db->getOne(
        "SELECT MAX(day_total) AS best_day_minutes
         FROM (
             SELECT done_at::date AS d, SUM(actual_minutes) AS day_total
             FROM oe_instructions_lxcars
             WHERE employee_id = :emp AND actual_minutes > 0 AND done_at IS NOT NULL
             GROUP BY done_at::date
         ) sub",
        [':emp' => $employeeId]
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
    $teamStats = $db->getAll(
        "SELECT e.id AS employee_id,
                e.name AS employee_name,
                COALESCE(SUM(i.actual_minutes), 0) AS actual_minutes,
                COALESCE(SUM(i.planned_minutes), 0) AS planned_minutes,
                COUNT(DISTINCT i.id) FILTER (WHERE i.done = true) AS done_count,
                COUNT(DISTINCT i.id) AS total_count,
                COUNT(DISTINCT i.oe_id) AS order_count
         FROM employee e
         JOIN oe_instructions_lxcars i ON i.employee_id = e.id
         WHERE i.actual_minutes > 0
           AND i.done_at::date BETWEEN :from::date AND :to::date
         GROUP BY e.id, e.name
         ORDER BY SUM(i.actual_minutes) DESC",
        [':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Pro Mechaniker pro Tag ──
    $dailyByEmployee = $db->getAll(
        "SELECT i.employee_id,
                e.name AS employee_name,
                d.day::date AS work_date,
                COALESCE(SUM(i.actual_minutes), 0) AS actual_minutes
         FROM generate_series(:from::date, :to::date, '1 day'::interval) AS d(day)
         CROSS JOIN (
             SELECT DISTINCT employee_id FROM oe_instructions_lxcars
             WHERE actual_minutes > 0 AND done_at::date BETWEEN :from2::date AND :to2::date
         ) AS emps
         JOIN employee e ON e.id = emps.employee_id
         LEFT JOIN oe_instructions_lxcars i
             ON i.employee_id = emps.employee_id
             AND i.actual_minutes > 0
             AND i.done_at::date = d.day::date
         GROUP BY i.employee_id, e.name, d.day::date
         ORDER BY e.name, d.day::date",
        [':from' => $dateFrom, ':to' => $dateTo, ':from2' => $dateFrom, ':to2' => $dateTo]
    );

    // ── Fahrzeug-Zeiten im Zeitraum ──
    $vehicleTimes = $db->getAll(
        "SELECT o.ordnumber,
                COALESCE(ext.kennzeichen, c.c_ln) AS kennzeichen,
                c.c_m AS fahrzeugtyp,
                COALESCE(SUM(i.actual_minutes), 0) AS actual_minutes,
                COALESCE(SUM(i.planned_minutes), 0) AS planned_minutes,
                COUNT(DISTINCT i.employee_id) AS mechanic_count,
                string_agg(DISTINCT e.name, ', ') AS mechanics
         FROM oe_instructions_lxcars i
         JOIN oe o ON o.id = i.oe_id
         LEFT JOIN oe_ext ext ON ext.oe_id = i.oe_id
         LEFT JOIN cars_lxcars c ON c.c_id = ext.c_id
         LEFT JOIN employee e ON e.id = i.employee_id
         WHERE i.done = true AND i.actual_minutes > 0
           AND i.done_at::date BETWEEN :from::date AND :to::date
         GROUP BY o.ordnumber, ext.kennzeichen, c.c_ln, c.c_m
         ORDER BY SUM(i.actual_minutes) DESC
         LIMIT 50",
        [':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Team-Gesamtstatistik im Zeitraum ──
    $teamTotal = $db->getOne(
        "SELECT COALESCE(SUM(actual_minutes), 0) AS total_actual,
                COALESCE(SUM(planned_minutes), 0) AS total_planned,
                COUNT(DISTINCT id) FILTER (WHERE done = true) AS total_done,
                COUNT(DISTINCT id) AS total_count,
                COUNT(DISTINCT oe_id) AS total_orders,
                COUNT(DISTINCT employee_id) AS mechanic_count
         FROM oe_instructions_lxcars
         WHERE actual_minutes > 0
           AND done_at::date BETWEEN :from::date AND :to::date",
        [':from' => $dateFrom, ':to' => $dateTo]
    );

    // ── Top-Taetigkeiten teamweit im Zeitraum ──
    $topTasks = $db->getAll(
        "SELECT description,
                COUNT(*) AS times_done,
                ROUND(AVG(actual_minutes)) AS avg_actual,
                ROUND(AVG(planned_minutes)) AS avg_planned,
                COUNT(DISTINCT employee_id) AS mechanic_count
         FROM oe_instructions_lxcars
         WHERE done = true AND actual_minutes > 0
           AND done_at::date BETWEEN :from::date AND :to::date
         GROUP BY description
         ORDER BY COUNT(*) DESC
         LIMIT 10",
        [':from' => $dateFrom, ':to' => $dateTo]
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
