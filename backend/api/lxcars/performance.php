<?php
// backend/api/lxcars/performance.php
//
// Leistungsdaten der Mechaniker für Personal → Leistung.
//
// Grundlage sind die Arbeitsanweisungen (oe_instructions_lxcars) mit
// Vorgabezeit und Ist-Zeit sowie die automatisch protokollierten
// Ausführungszeiten (oe_instruction_times_lxcars, je Timer-Sitzung und Tag).
// Ist-Zeit zählt am Tag des ARBEITSBEGINNS, nicht der Fertigstellung; eine
// Anweisung gehört zum Zeitraum ihres ersten Arbeitsbeginns. Für Altdaten
// ohne Protokoll wird der Beginn aus Fertigstellung minus Nettominuten
// geschätzt (Arbeitstage rückwärts, Wochenende übersprungen).
// Alles wird in EINER Abfrage für alle Mechaniker gleichzeitig berechnet,
// damit die Oberfläche zwischen den Mechanikern ohne Nachladen wechselt.

/**
 * Prüft, ob der angemeldete Benutzer die Leistungsdaten sehen darf:
 * kivitendo-Recht „Produktivität" (productivity) ODER Mitglied der in der
 * Firmenkonfiguration hinterlegten Werkstattleitungs-Gruppe.
 *
 * @param object $db Firmen-Datenbank
 * @return bool
 */
function _performanceAllowed($db) {
    if (checkPermissions('productivity')) {
        return true;
    }
    $row = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'lxcars_werkstattleitung_group'",
        []
    );
    $groupId = intval($row['value'] ?? 0);
    if (!$groupId) {
        return false;
    }
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    $login = $auth->getLogin();
    foreach ($auth->fetchClientGroups() as $group) {
        if (intval($group['id']) === $groupId && in_array($login, $group['logins'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * Leistungsdaten aller Mechaniker für einen Zeitraum samt Vorperiode.
 *
 * Die Mechaniker kommen aus dem Frontend (Mitglieder der Mechaniker-Gruppe,
 * aufgelöst über die Mitarbeiterliste im Store). Ist die Liste leer, zählt
 * jeder Mitarbeiter, der im Zeitraum Anweisungen erledigt hat.
 *
 * Verfügbare Zeit je Mechaniker = Arbeitstage (Mo–Fr) im Zeitraum abzüglich
 * genehmigter Urlaubstage × Nettominuten pro Tag (Arbeitsbeginn/-ende/Pausen
 * aus der LxCars-Konfiguration).
 *
 * Plausibilität: Jede Anweisung im Zeitraum kommt mit Vergleichswert und
 * Auffälligkeit zurück (items.flag: timer | high | low), dazu alle Tage über
 * der Netto-Arbeitszeit (long_days) und die Kopfdaten der Aufträge (orders),
 * damit die Oberfläche direkt in die Aufträge verzweigen kann.
 *
 * Arbeitslohn-Umsatz = Netto-Dienstleistungspositionen (parts.part_type =
 * 'service') des Auftrags, anteilig nach dem Ist-Minuten-Anteil des
 * Mechanikers an allen erfassten Minuten dieses Auftrags.
 *
 * @param array  $data['employee_ids']  Mitarbeiter-IDs der Mechaniker (leer = alle aktiven)
 * @param string $data['date_from']     Zeitraum von (YYYY-MM-DD)
 * @param string $data['date_to']       Zeitraum bis (YYYY-MM-DD)
 * @param string $data['prev_from']     Vorperiode von
 * @param string $data['prev_to']       Vorperiode bis
 * @param string $data['bucket']        Raster der Zeitreihe: day | week | month
 * @param string $data['arbeitsbeginn'] Konfiguration Arbeitsbeginn (HH:MM)
 * @param string $data['arbeitsende']   Konfiguration Arbeitsende (HH:MM)
 * @param string $data['pausen']        Konfiguration Pausen ("09:00-09:30, 12:00-12:30")
 * @testdata {"employee_ids": [1085, 145647], "date_from": "2026-09-01", "date_to": "2026-09-30", "prev_from": "2026-08-01", "prev_to": "2026-08-31", "bucket": "week", "arbeitsbeginn": "08:00", "arbeitsende": "17:00", "pausen": "10:00-10:30, 13:00-13:30"}
 */
function getMechanicPerformance($data) {
    $db = DbhCompany::begin();

    if (!_performanceAllowed($db)) {
        resultInfo(false, 'NO_PERMISSION', 'Keine Berechtigung für die Leistungsdaten');
        return;
    }

    $dateFrom = $data['date_from'] ?? date('Y-m-01');
    $dateTo   = $data['date_to'] ?? date('Y-m-t');
    $prevFrom = $data['prev_from'] ?? $dateFrom;
    $prevTo   = $data['prev_to'] ?? $dateTo;
    foreach ([$dateFrom, $dateTo, $prevFrom, $prevTo] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            resultInfo(false, 'VALIDATION_ERROR', 'Ungültiges Datum');
            return;
        }
    }
    $bucket = in_array($data['bucket'] ?? '', ['day', 'week', 'month'], true) ? $data['bucket'] : 'week';

    $ids = array_values(array_filter(array_map('intval', (array)($data['employee_ids'] ?? [])), fn($v) => $v > 0));
    $idsLiteral = '{' . implode(',', $ids) . '}';

    $availPerDay = _availableMinutesPerDay(
        $data['arbeitsbeginn'] ?? '08:00',
        $data['arbeitsende'] ?? '17:00',
        $data['pausen'] ?? ''
    );

    $row = $db->getOne(
        "WITH p AS (
            SELECT :ids::int[]        AS ids,
                   :d_from::date      AS d_from,
                   :d_to::date        AS d_to,
                   :p_from::date      AS p_from,
                   :p_to::date        AS p_to,
                   :bucket::text      AS bucket,
                   :avail::int        AS avail_per_day
        ),
        -- Jede erfasste Arbeitszeit: protokollierte Sitzung (je Tag) oder, für
        -- Altdaten ohne Protokoll, eine Schätzung ab Fertigstellung rückwärts.
        -- is_first markiert den ersten Arbeitsbeginn einer Anweisung: dort
        -- zählen Vorgabezeit, Erledigung und Tätigkeitsvergleich genau einmal.
        work_raw AS MATERIALIZED (
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
            CROSS JOIN p
            CROSS JOIN LATERAL (
                SELECT i.done_at
                       - make_interval(days => i.actual_minutes / GREATEST(p.avail_per_day, 60))
                       - make_interval(mins => i.actual_minutes % GREATEST(p.avail_per_day, 60)) AS ts
            ) est
            WHERE i.done = true AND i.actual_minutes > 0 AND i.done_at IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM oe_instruction_times_lxcars t WHERE t.instruction_id = i.id)
        ),
        work AS MATERIALIZED (
            SELECT i.id, i.oe_id,
                   COALESCE(w.employee_id, i.employee_id) AS employee_id,
                   i.description, i.planned_minutes, i.actual_minutes, i.done, i.done_at,
                   w.started_at, w.minutes,
                   ROW_NUMBER() OVER (PARTITION BY i.id ORDER BY w.started_at, w.sid) = 1 AS is_first
            FROM work_raw w
            JOIN oe_instructions_lxcars i ON i.id = w.id
            WHERE w.minutes > 0
        ),
        -- Sitzungen im Zeitraum und in der Vorperiode
        base AS MATERIALIZED (
            SELECT w.*, (w.started_at::date BETWEEN p.d_from AND p.d_to) AS in_cur
            FROM work w, p
            WHERE w.started_at::date BETWEEN LEAST(p.d_from, p.p_from) AND GREATEST(p.d_to, p.p_to)
              AND (w.started_at::date BETWEEN p.d_from AND p.d_to
                   OR w.started_at::date BETWEEN p.p_from AND p.p_to)
              AND (cardinality(p.ids) = 0 OR w.employee_id = ANY(p.ids))
        ),
        cur  AS MATERIALIZED (SELECT * FROM base WHERE in_cur),
        prev AS MATERIALIZED (SELECT * FROM base WHERE NOT in_cur),
        -- Eine Zeile je Anweisung, deren Arbeit im Zeitraum BEGANN (volle Ist-Zeit)
        cur_i AS MATERIALIZED (SELECT * FROM cur WHERE is_first),
        -- Mechaniker: konfigurierte Gruppe, sonst jeder mit Erledigungen
        mech AS MATERIALIZED (
            SELECT e.id, e.name, e.login
            FROM employee e, p
            WHERE e.deleted = false
              AND ((cardinality(p.ids) > 0 AND e.id = ANY(p.ids))
                   OR (cardinality(p.ids) = 0 AND EXISTS (SELECT 1 FROM cur WHERE cur.employee_id = e.id)))
        ),
        -- Arbeitstage Mo–Fr im Zeitraum
        workdays AS MATERIALIZED (
            SELECT COUNT(*) AS n
            FROM p, generate_series(p.d_from, p.d_to, '1 day') AS d
            WHERE EXTRACT(isodow FROM d) <= 5
        ),
        -- Genehmigte Urlaubstage (Mo–Fr) je Mechaniker im Zeitraum
        vacation AS MATERIALIZED (
            SELECT v.employee_id, COUNT(*) AS days
            FROM hr_vacation_requests v, p,
                 generate_series(GREATEST(v.date_from, p.d_from), LEAST(v.date_to, p.d_to), '1 day') AS d
            WHERE v.status = 'approved'
              AND v.date_from <= p.d_to AND v.date_to >= p.d_from
              AND EXTRACT(isodow FROM d) <= 5
            GROUP BY v.employee_id
        ),
        -- Dienstleistungsumsatz (netto) je Auftrag und Gesamtminuten des Auftrags
        order_labor AS MATERIALIZED (
            SELECT o.id AS oe_id,
                   COALESCE(SUM(oi.qty * oi.sellprice * (1 - COALESCE(oi.discount, 0))), 0) AS labor_net,
                   (SELECT COALESCE(SUM(actual_minutes), 0) FROM oe_instructions_lxcars x WHERE x.oe_id = o.id) AS total_minutes
            FROM oe o
            JOIN orderitems oi ON oi.trans_id = o.id
            JOIN parts pa ON pa.id = oi.parts_id AND pa.part_type = 'service'
            WHERE o.id IN (SELECT DISTINCT oe_id FROM cur)
            GROUP BY o.id
        ),
        kpi_cur AS MATERIALIZED (
            SELECT employee_id,
                   SUM(minutes)                                          AS actual,
                   COALESCE(SUM(planned_minutes) FILTER (WHERE is_first), 0) AS planned,
                   COUNT(DISTINCT id) FILTER (WHERE is_first AND done)   AS done,
                   COUNT(DISTINCT oe_id)                                 AS orders,
                   MAX(started_at)                                       AS last_done_at,
                   COUNT(DISTINCT started_at::date)                      AS active_days
            FROM cur GROUP BY employee_id
        ),
        kpi_prev AS MATERIALIZED (
            SELECT employee_id,
                   SUM(minutes)                                          AS actual,
                   COALESCE(SUM(planned_minutes) FILTER (WHERE is_first), 0) AS planned,
                   COUNT(DISTINCT id) FILTER (WHERE is_first AND done)   AS done,
                   COUNT(DISTINCT oe_id)                                 AS orders
            FROM prev GROUP BY employee_id
        ),
        labor AS MATERIALIZED (
            SELECT c.employee_id,
                   SUM(ol.labor_net * c.minutes / NULLIF(ol.total_minutes, 0)) AS labor_revenue
            FROM cur c
            JOIN order_labor ol ON ol.oe_id = c.oe_id
            GROUP BY c.employee_id
        ),
        running AS MATERIALIZED (
            SELECT i.timer_employee_id AS employee_id,
                   json_build_object(
                       'description', i.description,
                       'ordnumber', o.ordnumber,
                       'oe_id', o.id,
                       'kennzeichen', COALESCE(ext.kennzeichen, car.c_ln),
                       'started_at', i.timer_started_at
                   ) AS info
            FROM oe_instructions_lxcars i
            JOIN oe o ON o.id = i.oe_id
            LEFT JOIN oe_ext ext ON ext.oe_id = o.id
            LEFT JOIN cars_lxcars car ON car.c_id = ext.c_id
            WHERE i.timer_started_at IS NOT NULL AND i.timer_employee_id IS NOT NULL
        ),
        mechanics AS (
            SELECT m.id AS employee_id, m.name, m.login,
                   COALESCE(k.actual, 0)  AS actual,
                   COALESCE(k.planned, 0) AS planned,
                   COALESCE(k.done, 0)    AS done,
                   COALESCE(k.orders, 0)  AS orders,
                   COALESCE(k.active_days, 0) AS active_days,
                   k.last_done_at,
                   ROUND(COALESCE(l.labor_revenue, 0)::numeric, 2) AS labor_revenue,
                   COALESCE(v.days, 0) AS vacation_days,
                   GREATEST(0, (SELECT n FROM workdays) - COALESCE(v.days, 0)) * (SELECT avail_per_day FROM p) AS avail_minutes,
                   json_build_object(
                       'actual',  COALESCE(kp.actual, 0),
                       'planned', COALESCE(kp.planned, 0),
                       'done',    COALESCE(kp.done, 0),
                       'orders',  COALESCE(kp.orders, 0)
                   ) AS prev,
                   (SELECT r.info FROM running r WHERE r.employee_id = m.id LIMIT 1) AS running
            FROM mech m
            LEFT JOIN kpi_cur k   ON k.employee_id = m.id
            LEFT JOIN kpi_prev kp ON kp.employee_id = m.id
            LEFT JOIN labor l     ON l.employee_id = m.id
            LEFT JOIN vacation v  ON v.employee_id = m.id
        ),
        -- Zeitreihe je Mechaniker im gewählten Raster (nach Arbeitsbeginn)
        series AS (
            SELECT employee_id,
                   date_trunc((SELECT bucket FROM p), started_at)::date AS bucket,
                   SUM(minutes)                                          AS actual,
                   COALESCE(SUM(planned_minutes) FILTER (WHERE is_first), 0) AS planned,
                   COUNT(DISTINCT id) FILTER (WHERE is_first AND done)   AS done
            FROM cur
            GROUP BY employee_id, 2
            ORDER BY 2
        ),
        -- Team-Durchschnitt je Tätigkeit (mind. 3 Erledigungen, 2 Mechaniker)
        team_task AS MATERIALIZED (
            SELECT description,
                   AVG(actual_minutes)  AS team_avg,
                   AVG(planned_minutes) AS team_planned,
                   COUNT(*)             AS team_n,
                   COUNT(DISTINCT employee_id) AS team_mechanics
            FROM cur_i
            GROUP BY description
        ),
        tasks AS (
            SELECT c.employee_id, c.description,
                   COUNT(*)                        AS n,
                   ROUND(AVG(c.actual_minutes), 1) AS avg_actual,
                   ROUND(AVG(c.planned_minutes), 1) AS avg_planned,
                   SUM(c.actual_minutes)           AS total_actual,
                   ROUND(tt.team_avg, 1)           AS team_avg,
                   tt.team_n,
                   tt.team_mechanics
            FROM cur_i c
            JOIN team_task tt ON tt.description = c.description
            GROUP BY c.employee_id, c.description, tt.team_avg, tt.team_n, tt.team_mechanics
            ORDER BY c.employee_id, COUNT(*) DESC, SUM(c.actual_minutes) DESC
        ),
        -- Aktivitätsprofil: Arbeitsbeginne je Wochentag und Stunde
        heat AS (
            SELECT employee_id,
                   EXTRACT(isodow FROM started_at)::int AS dow,
                   EXTRACT(hour FROM started_at)::int   AS hour,
                   COUNT(*)                             AS cnt,
                   SUM(minutes)                         AS minutes
            FROM cur
            GROUP BY employee_id, 2, 3
        ),
        -- Streuung im Team je Tätigkeit: schnellster und langsamster Mechaniker
        per_mech_task AS MATERIALIZED (
            SELECT c.employee_id, m.name, c.description,
                   COUNT(*) AS n, AVG(c.actual_minutes) AS avg_actual
            FROM cur_i c JOIN mech m ON m.id = c.employee_id
            GROUP BY c.employee_id, m.name, c.description
            HAVING COUNT(*) >= 3
        ),
        spread AS (
            SELECT t.description,
                   tt.team_n AS n,
                   ROUND(tt.team_avg, 1) AS team_avg,
                   ROUND(tt.team_planned, 1) AS team_planned,
                   (SELECT json_build_object('employee_id', x.employee_id, 'name', x.name, 'avg', ROUND(x.avg_actual, 1), 'n', x.n)
                      FROM per_mech_task x WHERE x.description = t.description ORDER BY x.avg_actual ASC LIMIT 1) AS fastest,
                   (SELECT json_build_object('employee_id', x.employee_id, 'name', x.name, 'avg', ROUND(x.avg_actual, 1), 'n', x.n)
                      FROM per_mech_task x WHERE x.description = t.description ORDER BY x.avg_actual DESC LIMIT 1) AS slowest,
                   (SELECT MAX(x.avg_actual) / NULLIF(MIN(x.avg_actual), 0)
                      FROM per_mech_task x WHERE x.description = t.description) AS ratio
            FROM (SELECT DISTINCT description FROM per_mech_task) t
            JOIN team_task tt ON tt.description = t.description
            WHERE tt.team_n >= 5 AND tt.team_mechanics >= 2
              AND (SELECT COUNT(*) FROM per_mech_task x WHERE x.description = t.description) >= 2
            ORDER BY (SELECT MAX(x.avg_actual) / NULLIF(MIN(x.avg_actual), 0)
                      FROM per_mech_task x WHERE x.description = t.description) DESC NULLS LAST
            LIMIT 8
        ),
        -- ─── Plausibilität ───
        -- Vergleichswert je Tätigkeit: Median der letzten 365 Tage, ohne Einträge
        -- über einem ganzen Arbeitstag (vergessene Timer verfälschen sonst den Wert)
        task_ref AS MATERIALIZED (
            SELECT x.description,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY x.actual_minutes) AS median
            FROM oe_instructions_lxcars x, p
            WHERE x.done = true AND x.actual_minutes > 0
              AND x.actual_minutes <= GREATEST(p.avail_per_day, 60)
              AND x.done_at >= p.d_to - 365 AND x.done_at < p.d_to + 1
              AND x.description IN (SELECT description FROM team_task)
            GROUP BY x.description
            HAVING COUNT(*) >= 5
        ),
        -- Jede erledigte Anweisung im Zeitraum mit Vergleichswert und Auffälligkeit:
        --   timer = mehr Ist-Zeit als ein ganzer Arbeitstag und kein passender
        --           Vergleichswert, der das erklärt (Timer vergessen?)
        --   high  = mindestens 3× Vergleichswert und 30 min darüber
        --   low   = höchstens ein Drittel des Vergleichswerts (ab 20 min Vergleich)
        -- Vergleichswert = Vorgabezeit, ohne Vorgabe der Team-Median der Tätigkeit
        items AS MATERIALIZED (
            SELECT r.*,
                   CASE
                       WHEN r.actual > GREATEST(p.avail_per_day, 60)
                            AND (r.ref IS NULL OR r.ref = 0 OR r.actual >= 2 * r.ref) THEN 'timer'
                       WHEN r.ref > 0 AND r.actual >= 3 * r.ref AND r.actual - r.ref >= 30 THEN 'high'
                       WHEN r.ref >= 20 AND r.actual * 3 <= r.ref THEN 'low'
                   END AS flag
            FROM p, (
                SELECT c.id, c.oe_id, c.employee_id, c.description,
                       c.planned_minutes AS planned, c.actual_minutes AS actual,
                       c.done_at, c.started_at::date AS day,
                       CASE WHEN c.planned_minutes > 0 THEN c.planned_minutes ELSE ROUND(tr.median)::int END AS ref,
                       CASE WHEN c.planned_minutes > 0 THEN 'planned' WHEN tr.median IS NOT NULL THEN 'median' END AS ref_source
                FROM cur_i c
                LEFT JOIN task_ref tr ON tr.description = c.description
            ) r
        ),
        -- Tage, an denen ein Mechaniker mehr als die Netto-Arbeitszeit erfasst hat
        -- (Summe der Sitzungen je Tag). Geschätzte Altdaten plausibler
        -- Mehrtagesarbeiten (über einem Arbeitstag, aber passend zur Vorgabe)
        -- zählen nicht: sie liegen ungeteilt am geschätzten Beginn
        long_days AS (
            SELECT c.employee_id, c.started_at::date AS day,
                   SUM(c.minutes)        AS minutes,
                   COUNT(DISTINCT c.id)  AS n,
                   array_agg(DISTINCT c.oe_id) AS oe_ids
            FROM cur c
            LEFT JOIN items it ON it.id = c.id, p
            WHERE c.minutes <= GREATEST(p.avail_per_day, 60) OR it.flag IS NOT NULL
            GROUP BY c.employee_id, c.started_at::date, p.avail_per_day
            HAVING SUM(c.minutes) > GREATEST(p.avail_per_day, 60)
        ),
        -- Kopfdaten der bearbeiteten Aufträge für die Auftragsliste
        orders AS (
            SELECT o.id AS oe_id, o.ordnumber,
                   COALESCE(ext.kennzeichen, car.c_ln) AS kennzeichen,
                   cu.name AS customer
            FROM oe o
            LEFT JOIN oe_ext ext       ON ext.oe_id = o.id
            LEFT JOIN cars_lxcars car  ON car.c_id = ext.c_id
            LEFT JOIN customer cu      ON cu.id = o.customer_id
            WHERE o.id IN (SELECT oe_id FROM cur)
        )
        SELECT json_build_object(
            'range', json_build_object(
                'date_from', (SELECT d_from FROM p),
                'date_to',   (SELECT d_to FROM p),
                'prev_from', (SELECT p_from FROM p),
                'prev_to',   (SELECT p_to FROM p),
                'bucket',    (SELECT bucket FROM p),
                'work_days', (SELECT n FROM workdays),
                'avail_per_day', (SELECT avail_per_day FROM p)
            ),
            'mechanics', COALESCE((SELECT json_agg(m ORDER BY m.actual DESC, m.name) FROM mechanics m), '[]'::json),
            'series',    COALESCE((SELECT json_agg(s ORDER BY s.bucket) FROM series s), '[]'::json),
            'tasks',     COALESCE((SELECT json_agg(t) FROM tasks t), '[]'::json),
            'heatmap',   COALESCE((SELECT json_agg(h) FROM heat h), '[]'::json),
            'spread',    COALESCE((SELECT json_agg(s) FROM spread s), '[]'::json),
            'items',     COALESCE((SELECT json_agg(json_build_object(
                             'id', i.id, 'oe_id', i.oe_id, 'employee_id', i.employee_id,
                             'description', i.description, 'planned', i.planned, 'actual', i.actual,
                             'done_at', i.done_at, 'day', i.day, 'ref', i.ref,
                             'ref_source', i.ref_source, 'flag', i.flag
                         ) ORDER BY i.done_at DESC) FROM items i), '[]'::json),
            'long_days', COALESCE((SELECT json_agg(d ORDER BY d.day DESC, d.minutes DESC) FROM long_days d), '[]'::json),
            'orders',    COALESCE((SELECT json_object_agg(o.oe_id, json_build_object(
                             'ordnumber', o.ordnumber, 'kennzeichen', o.kennzeichen, 'customer', o.customer
                         )) FROM orders o), '{}'::json)
        ) AS result",
        [
            ':ids'    => $idsLiteral,
            ':d_from' => $dateFrom,
            ':d_to'   => $dateTo,
            ':p_from' => $prevFrom,
            ':p_to'   => $prevTo,
            ':bucket' => $bucket,
            ':avail'  => $availPerDay,
        ]
    );

    $result = $row ? json_decode($row['result'], true) : null;
    resultInfo(true, 'OK', ['result' => $result]);
}
