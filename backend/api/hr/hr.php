<?php
// backend/api/hr/hr.php
// HR-Modul: Lohnabrechnung & Urlaubsplanung

// ============================================================================
// DASHBOARD
// ============================================================================

/**
 * Laedt HR-Dashboard: aktive Mitarbeiter, laufende Abrechnungen, offene Urlaubsantraege
 *
 * @param int $data['employee_id'] Eingeloggter Mitarbeiter
 * @testdata {"action": "getHrDashboard", "employee_id": 1}
 */
function getHrDashboard($data) {
    $db = DbhCompany::begin();
    $employeeId = intval($data['employee_id'] ?? 0);
    $year = intval(date('Y'));

    $query = <<<SQL
        SELECT json_build_object(
            'active_employees', (
                SELECT COUNT(*) FROM employee
                WHERE deleted IS NOT TRUE AND login IS NOT NULL AND login != ''
            ),
            'last_payroll_run', (
                SELECT json_build_object(
                    'id', r.id, 'year', r.year, 'month', r.month,
                    'status', r.status, 'item_count',
                    (SELECT COUNT(*) FROM hr_payroll_items WHERE run_id = r.id)
                )
                FROM hr_payroll_runs r
                ORDER BY r.year DESC, r.month DESC
                LIMIT 1
            ),
            'pending_vacation_requests', (
                SELECT COUNT(*) FROM hr_vacation_requests WHERE status = 'pending'
            ),
            'my_vacation', (
                SELECT json_build_object(
                    'days_total', COALESCE(e.days_total, 30),
                    'days_used', COALESCE(
                        (SELECT SUM(r.days) FROM hr_vacation_requests r
                         WHERE r.employee_id = $employeeId AND r.status = 'approved'
                         AND EXTRACT(YEAR FROM r.date_from) = $year), 0),
                    'requests', COALESCE((
                        SELECT json_agg(json_build_object(
                            'id', r.id, 'date_from', r.date_from, 'date_to', r.date_to,
                            'days', r.days, 'status', r.status
                        ) ORDER BY r.date_from)
                        FROM hr_vacation_requests r
                        WHERE r.employee_id = $employeeId
                        AND EXTRACT(YEAR FROM r.date_from) = $year
                    ), '[]'::json)
                )
                FROM (
                    SELECT COALESCE(
                        (SELECT days_total FROM hr_vacation_entitlements
                         WHERE employee_id = $employeeId AND year = $year), 30
                    ) AS days_total
                ) e
            )
        ) AS result
    SQL;

    echo $db->get($query);
}

// ============================================================================
// GEHALTSEINSTELLUNGEN
// ============================================================================

/**
 * Laedt Gehaltseinstellungen aller aktiven Mitarbeiter
 *
 * @testdata {"action": "getSalarySettings"}
 */
function getSalarySettings($data) {
    $db = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'employees', COALESCE((
                SELECT json_agg(json_build_object(
                    'employee_id', e.id,
                    'name', e.name,
                    'login', e.login,
                    'settings_id', s.id,
                    'brutto', COALESCE(s.brutto, 0),
                    'steuerklasse', COALESCE(s.steuerklasse, 1),
                    'kv_prozent', COALESCE(s.kv_prozent, 8.150),
                    'pv_prozent', COALESCE(s.pv_prozent, 1.700),
                    'rv_prozent', COALESCE(s.rv_prozent, 9.300),
                    'av_prozent', COALESCE(s.av_prozent, 1.300),
                    'kv_prozent_ag', COALESCE(s.kv_prozent_ag, 7.300),
                    'pv_prozent_ag', COALESCE(s.pv_prozent_ag, 1.700),
                    'rv_prozent_ag', COALESCE(s.rv_prozent_ag, 9.300),
                    'av_prozent_ag', COALESCE(s.av_prozent_ag, 1.300),
                    'notes', s.notes
                ) ORDER BY e.name)
                FROM employee e
                LEFT JOIN hr_salary_settings s ON s.employee_id = e.id
                WHERE e.deleted IS NOT TRUE AND e.login IS NOT NULL AND e.login != ''
            ), '[]'::json)
        ) AS result
    SQL;

    echo $db->get($query);
}

/**
 * Speichert Gehaltseinstellungen eines Mitarbeiters (INSERT oder UPDATE)
 *
 * @param int    $data['employee_id']   Mitarbeiter-ID
 * @param float  $data['brutto']        Bruttogehalt
 * @param int    $data['steuerklasse']  Steuerklasse 1-6
 * @param float  $data['kv_prozent']    KV AN-Anteil %
 * @param float  $data['pv_prozent']    PV AN-Anteil %
 * @param float  $data['rv_prozent']    RV AN-Anteil %
 * @param float  $data['av_prozent']    AV AN-Anteil %
 * @param float  $data['kv_prozent_ag'] KV AG-Anteil %
 * @param float  $data['pv_prozent_ag'] PV AG-Anteil %
 * @param float  $data['rv_prozent_ag'] RV AG-Anteil %
 * @param float  $data['av_prozent_ag'] AV AG-Anteil %
 * @param string $data['notes']         Notizen (optional)
 * @testdata {"action": "saveSalarySettings", "employee_id": 1, "brutto": 3500.00, "steuerklasse": 1}
 */
function saveSalarySettings($data) {
    $db = DbhCompany::begin();

    $employeeId = intval($data['employee_id'] ?? 0);
    if ($employeeId <= 0) {
        resultInfo(false, 'INVALID_EMPLOYEE', 'Ungueltige Mitarbeiter-ID');
        return;
    }

    $fields = [
        'employee_id', 'brutto', 'steuerklasse',
        'kv_prozent', 'pv_prozent', 'rv_prozent', 'av_prozent',
        'kv_prozent_ag', 'pv_prozent_ag', 'rv_prozent_ag', 'av_prozent_ag',
        'notes', 'mtime'
    ];
    $values = [
        $employeeId,
        floatval($data['brutto'] ?? 0),
        intval($data['steuerklasse'] ?? 1),
        floatval($data['kv_prozent'] ?? 8.150),
        floatval($data['pv_prozent'] ?? 1.700),
        floatval($data['rv_prozent'] ?? 9.300),
        floatval($data['av_prozent'] ?? 1.300),
        floatval($data['kv_prozent_ag'] ?? 7.300),
        floatval($data['pv_prozent_ag'] ?? 1.700),
        floatval($data['rv_prozent_ag'] ?? 9.300),
        floatval($data['av_prozent_ag'] ?? 1.300),
        !empty($data['notes']) ? $data['notes'] : null,
        'NOW()'
    ];

    $existing = $db->getOne(
        'SELECT id FROM hr_salary_settings WHERE employee_id = :eid',
        [':eid' => $employeeId]
    );

    if ($existing) {
        $db->update('hr_salary_settings', $fields, $values, 'employee_id = ' . $employeeId);
        resultInfo(true, '', ['id' => $existing['id']]);
    } else {
        $id = $db->insert('hr_salary_settings', $fields, $values, true);
        resultInfo(true, '', ['id' => $id]);
    }
}

// ============================================================================
// LOHNABRECHNUNGSLAEUFE
// ============================================================================

/**
 * Laedt alle Lohnabrechnungslaeufe mit Zusammenfassung
 *
 * @testdata {"action": "getPayrollRuns"}
 */
function getPayrollRuns($data) {
    $db = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'runs', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', r.id,
                    'year', r.year,
                    'month', r.month,
                    'status', r.status,
                    'notes', r.notes,
                    'created_by_name', ec.name,
                    'finalized_by_name', ef.name,
                    'finalized_at', r.finalized_at,
                    'itime', r.itime,
                    'item_count', (SELECT COUNT(*) FROM hr_payroll_items WHERE run_id = r.id),
                    'total_brutto', (SELECT COALESCE(SUM(brutto), 0) FROM hr_payroll_items WHERE run_id = r.id),
                    'total_netto', (SELECT COALESCE(SUM(netto), 0) FROM hr_payroll_items WHERE run_id = r.id),
                    'total_ag_cost', (
                        SELECT COALESCE(SUM(brutto + kv_ag + pv_ag + rv_ag + av_ag), 0)
                        FROM hr_payroll_items WHERE run_id = r.id
                    )
                ) ORDER BY r.year DESC, r.month DESC)
                FROM hr_payroll_runs r
                LEFT JOIN employee ec ON ec.id = r.created_by
                LEFT JOIN employee ef ON ef.id = r.finalized_by
            ), '[]'::json)
        ) AS result
    SQL;

    echo $db->get($query);
}

/**
 * Laedt einen einzelnen Lohnabrechnungslauf mit allen Positionen
 *
 * @param int $data['id'] Run-ID
 * @testdata {"action": "getPayrollRun", "id": 1}
 */
function getPayrollRun($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $query = <<<SQL
        SELECT json_build_object(
            'run', (
                SELECT json_build_object(
                    'id', r.id, 'year', r.year, 'month', r.month,
                    'status', r.status, 'notes', r.notes,
                    'created_by_name', ec.name, 'finalized_by_name', ef.name,
                    'finalized_at', r.finalized_at, 'itime', r.itime
                )
                FROM hr_payroll_runs r
                LEFT JOIN employee ec ON ec.id = r.created_by
                LEFT JOIN employee ef ON ef.id = r.finalized_by
                WHERE r.id = $id
            ),
            'items', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', i.id,
                    'employee_id', i.employee_id,
                    'employee_name', e.name,
                    'brutto', i.brutto,
                    'kv_an', i.kv_an, 'pv_an', i.pv_an,
                    'rv_an', i.rv_an, 'av_an', i.av_an,
                    'kv_ag', i.kv_ag, 'pv_ag', i.pv_ag,
                    'rv_ag', i.rv_ag, 'av_ag', i.av_ag,
                    'sv_an_total', (i.kv_an + i.pv_an + i.rv_an + i.av_an),
                    'sv_ag_total', (i.kv_ag + i.pv_ag + i.rv_ag + i.av_ag),
                    'lohnsteuer', i.lohnsteuer,
                    'kirchensteuer', i.kirchensteuer,
                    'solidaritaetszuschlag', i.solidaritaetszuschlag,
                    'sonstige_abzuege', i.sonstige_abzuege,
                    'sonstige_zulagen', i.sonstige_zulagen,
                    'netto', i.netto,
                    'notes', i.notes
                ) ORDER BY e.name)
                FROM hr_payroll_items i
                JOIN employee e ON e.id = i.employee_id
                WHERE i.run_id = $id
            ), '[]'::json)
        ) AS result
    SQL;

    echo $db->get($query);
}

/**
 * Erstellt einen neuen Lohnabrechnungslauf und befuellt ihn mit Mitarbeiterdaten
 *
 * @param int    $data['year']        Jahr
 * @param int    $data['month']       Monat (1-12)
 * @param int    $data['employee_id'] Ersteller
 * @param string $data['notes']       Notizen (optional)
 * @testdata {"action": "createPayrollRun", "year": 2024, "month": 1, "employee_id": 1}
 */
function createPayrollRun($data) {
    $db = DbhCompany::begin();

    $year = intval($data['year'] ?? date('Y'));
    $month = intval($data['month'] ?? date('n'));
    $createdBy = intval($data['employee_id'] ?? 0);
    $notes = !empty($data['notes']) ? $data['notes'] : null;

    if ($month < 1 || $month > 12) {
        resultInfo(false, 'INVALID_MONTH', 'Ungultiger Monat');
        return;
    }

    $existing = $db->getOne(
        'SELECT id FROM hr_payroll_runs WHERE year = :y AND month = :m',
        [':y' => $year, ':m' => $month]
    );
    if ($existing) {
        resultInfo(false, 'RUN_EXISTS', 'Fuer diesen Monat existiert bereits ein Lohnlauf');
        return;
    }

    $db->execute(
        'INSERT INTO hr_payroll_runs (year, month, status, notes, created_by)
         VALUES (:y, :m, \'draft\', :notes, :cb)',
        [':y' => $year, ':m' => $month, ':notes' => $notes, ':cb' => $createdBy]
    );
    $run = $db->getOne(
        'SELECT id FROM hr_payroll_runs WHERE year = :y AND month = :m',
        [':y' => $year, ':m' => $month]
    );
    $runId = intval($run['id']);

    // Mitarbeiter mit Gehaltseinstellungen laden und Items vorbelegen
    $employees = $db->getAll(
        <<<SQL
        SELECT e.id AS employee_id,
               COALESCE(s.brutto, 0) AS brutto,
               COALESCE(s.kv_prozent, 8.150) AS kv_prozent,
               COALESCE(s.pv_prozent, 1.700) AS pv_prozent,
               COALESCE(s.rv_prozent, 9.300) AS rv_prozent,
               COALESCE(s.av_prozent, 1.300) AS av_prozent,
               COALESCE(s.kv_prozent_ag, 7.300) AS kv_prozent_ag,
               COALESCE(s.pv_prozent_ag, 1.700) AS pv_prozent_ag,
               COALESCE(s.rv_prozent_ag, 9.300) AS rv_prozent_ag,
               COALESCE(s.av_prozent_ag, 1.300) AS av_prozent_ag
        FROM employee e
        LEFT JOIN hr_salary_settings s ON s.employee_id = e.id
        WHERE e.deleted IS NOT TRUE AND e.login IS NOT NULL AND e.login != ''
        SQL,
        []
    );

    foreach ($employees as $emp) {
        $brutto = floatval($emp['brutto']);
        $kvAn = round($brutto * floatval($emp['kv_prozent']) / 100, 2);
        $pvAn = round($brutto * floatval($emp['pv_prozent']) / 100, 2);
        $rvAn = round($brutto * floatval($emp['rv_prozent']) / 100, 2);
        $avAn = round($brutto * floatval($emp['av_prozent']) / 100, 2);
        $kvAg = round($brutto * floatval($emp['kv_prozent_ag']) / 100, 2);
        $pvAg = round($brutto * floatval($emp['pv_prozent_ag']) / 100, 2);
        $rvAg = round($brutto * floatval($emp['rv_prozent_ag']) / 100, 2);
        $avAg = round($brutto * floatval($emp['av_prozent_ag']) / 100, 2);

        $db->execute(
            <<<SQL
            INSERT INTO hr_payroll_items
                (run_id, employee_id, brutto,
                 kv_an, pv_an, rv_an, av_an,
                 kv_ag, pv_ag, rv_ag, av_ag)
            VALUES (:rid, :eid, :br, :kva, :pva, :rva, :ava, :kvg, :pvg, :rvg, :avg)
            SQL,
            [
                ':rid' => $runId, ':eid' => intval($emp['employee_id']),
                ':br' => $brutto,
                ':kva' => $kvAn, ':pva' => $pvAn, ':rva' => $rvAn, ':ava' => $avAn,
                ':kvg' => $kvAg, ':pvg' => $pvAg, ':rvg' => $rvAg, ':avg' => $avAg,
            ]
        );
    }

    resultInfo(true, '', ['id' => $runId]);
}

/**
 * Aktualisiert eine einzelne Lohnposition (editierbare Felder)
 *
 * @param int   $data['id']                    Item-ID
 * @param float $data['brutto']                Bruttogehalt
 * @param float $data['kv_an']                 KV AN-Betrag
 * @param float $data['pv_an']                 PV AN-Betrag
 * @param float $data['rv_an']                 RV AN-Betrag
 * @param float $data['av_an']                 AV AN-Betrag
 * @param float $data['kv_ag']                 KV AG-Betrag
 * @param float $data['pv_ag']                 PV AG-Betrag
 * @param float $data['rv_ag']                 RV AG-Betrag
 * @param float $data['av_ag']                 AV AG-Betrag
 * @param float $data['lohnsteuer']            Lohnsteuer
 * @param float $data['kirchensteuer']         Kirchensteuer
 * @param float $data['solidaritaetszuschlag'] Solidaritaetszuschlag
 * @param float $data['sonstige_abzuege']      Sonstige Abzuege
 * @param float $data['sonstige_zulagen']      Sonstige Zulagen
 * @param string $data['notes']               Notizen
 * @testdata {"action": "updatePayrollItem", "id": 1, "brutto": 3500, "lohnsteuer": 450}
 */
function updatePayrollItem($data) {
    $db = DbhCompany::begin();

    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $fields = [
        'brutto', 'kv_an', 'pv_an', 'rv_an', 'av_an',
        'kv_ag', 'pv_ag', 'rv_ag', 'av_ag',
        'lohnsteuer', 'kirchensteuer', 'solidaritaetszuschlag',
        'sonstige_abzuege', 'sonstige_zulagen', 'notes', 'mtime'
    ];
    $values = [
        floatval($data['brutto'] ?? 0),
        floatval($data['kv_an'] ?? 0), floatval($data['pv_an'] ?? 0),
        floatval($data['rv_an'] ?? 0), floatval($data['av_an'] ?? 0),
        floatval($data['kv_ag'] ?? 0), floatval($data['pv_ag'] ?? 0),
        floatval($data['rv_ag'] ?? 0), floatval($data['av_ag'] ?? 0),
        floatval($data['lohnsteuer'] ?? 0),
        floatval($data['kirchensteuer'] ?? 0),
        floatval($data['solidaritaetszuschlag'] ?? 0),
        floatval($data['sonstige_abzuege'] ?? 0),
        floatval($data['sonstige_zulagen'] ?? 0),
        !empty($data['notes']) ? $data['notes'] : null,
        'NOW()'
    ];

    $db->update('hr_payroll_items', $fields, $values, 'id = ' . $id);

    // Aktualisierten Datensatz zurueckgeben
    $item = $db->getOne(
        'SELECT brutto, kv_an+pv_an+rv_an+av_an AS sv_an_total,
                kv_ag+pv_ag+rv_ag+av_ag AS sv_ag_total,
                lohnsteuer, kirchensteuer, solidaritaetszuschlag,
                sonstige_abzuege, sonstige_zulagen, netto
         FROM hr_payroll_items WHERE id = :id',
        [':id' => $id]
    );
    resultInfo(true, '', ['item' => $item]);
}

/**
 * Finalisiert einen Lohnabrechnungslauf (Status: draft -> final)
 *
 * @param int $data['id']          Run-ID
 * @param int $data['employee_id'] Finalisierender Mitarbeiter
 * @testdata {"action": "finalizePayrollRun", "id": 1, "employee_id": 1}
 */
function finalizePayrollRun($data) {
    $db = DbhCompany::begin();

    $id = intval($data['id'] ?? 0);
    $employeeId = intval($data['employee_id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $db->execute(
        "UPDATE hr_payroll_runs SET status = 'final', finalized_by = :eid,
         finalized_at = NOW(), mtime = NOW() WHERE id = :id AND status = 'draft'",
        [':eid' => $employeeId, ':id' => $id]
    );
    resultInfo(true, 'FINALIZED');
}

/**
 * Loescht einen Lohnabrechnungslauf (nur im Status draft)
 *
 * @param int $data['id'] Run-ID
 * @testdata {"action": "deletePayrollRun", "id": 1}
 */
function deletePayrollRun($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $run = $db->getOne('SELECT status FROM hr_payroll_runs WHERE id = :id', [':id' => $id]);
    if (!$run) {
        resultInfo(false, 'NOT_FOUND', 'Lohnlauf nicht gefunden');
        return;
    }
    if ($run['status'] === 'final') {
        resultInfo(false, 'ALREADY_FINAL', 'Abgeschlossene Lohnlaeufe koennen nicht geloescht werden');
        return;
    }

    $db->execute('DELETE FROM hr_payroll_runs WHERE id = :id', [':id' => $id]);
    resultInfo(true, 'DELETED');
}

// ============================================================================
// URLAUBSPLANUNG
// ============================================================================

/**
 * Laedt die Urlaubsübersicht fuer ein Jahr (alle Mitarbeiter)
 *
 * @param int $data['year'] Jahr (optional, Standard: aktuelles Jahr)
 * @testdata {"action": "getVacationOverview", "year": 2024}
 */
function getVacationOverview($data) {
    $db = DbhCompany::begin();
    $year = intval($data['year'] ?? date('Y'));

    $query = <<<SQL
        SELECT json_build_object(
            'year', $year,
            'employees', COALESCE((
                SELECT json_agg(json_build_object(
                    'employee_id', e.id,
                    'name', e.name,
                    'days_total', COALESCE(ent.days_total, 30),
                    'days_used', COALESCE((
                        SELECT SUM(r.days) FROM hr_vacation_requests r
                        WHERE r.employee_id = e.id AND r.status = 'approved'
                        AND EXTRACT(YEAR FROM r.date_from) = $year
                    ), 0),
                    'requests', COALESCE((
                        SELECT json_agg(json_build_object(
                            'id', r.id,
                            'date_from', r.date_from,
                            'date_to', r.date_to,
                            'days', r.days,
                            'status', r.status,
                            'notes', r.notes,
                            'approved_by_name', ea.name,
                            'approved_at', r.approved_at,
                            'rejection_reason', r.rejection_reason,
                            'itime', r.itime
                        ) ORDER BY r.date_from)
                        FROM hr_vacation_requests r
                        LEFT JOIN employee ea ON ea.id = r.approved_by
                        WHERE r.employee_id = e.id
                        AND EXTRACT(YEAR FROM r.date_from) = $year
                    ), '[]'::json)
                ) ORDER BY e.name)
                FROM employee e
                LEFT JOIN hr_vacation_entitlements ent
                    ON ent.employee_id = e.id AND ent.year = $year
                WHERE e.deleted IS NOT TRUE AND e.login IS NOT NULL AND e.login != ''
            ), '[]'::json),
            'pending_requests', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', r.id,
                    'employee_id', r.employee_id,
                    'employee_name', e.name,
                    'date_from', r.date_from,
                    'date_to', r.date_to,
                    'days', r.days,
                    'notes', r.notes,
                    'itime', r.itime
                ) ORDER BY r.itime)
                FROM hr_vacation_requests r
                JOIN employee e ON e.id = r.employee_id
                WHERE r.status = 'pending'
                AND EXTRACT(YEAR FROM r.date_from) = $year
            ), '[]'::json)
        ) AS result
    SQL;

    echo $db->get($query);
}

/**
 * Speichert einen Urlaubsantrag (neu oder Aktualisierung)
 *
 * @param int    $data['employee_id'] Mitarbeiter-ID
 * @param string $data['date_from']   Von-Datum (YYYY-MM-DD)
 * @param string $data['date_to']     Bis-Datum (YYYY-MM-DD)
 * @param float  $data['days']        Anzahl Urlaubstage
 * @param string $data['notes']       Notizen (optional)
 * @param int    $data['id']          Antrag-ID (nur bei Update)
 * @testdata {"action": "saveVacationRequest", "employee_id": 1, "date_from": "2024-07-01", "date_to": "2024-07-05", "days": 5}
 */
function saveVacationRequest($data) {
    $db = DbhCompany::begin();

    $id = intval($data['id'] ?? 0);
    $employeeId = intval($data['employee_id'] ?? 0);
    $dateFrom = $data['date_from'] ?? '';
    $dateTo = $data['date_to'] ?? '';
    $days = floatval($data['days'] ?? 1);
    $notes = !empty($data['notes']) ? $data['notes'] : null;

    if ($employeeId <= 0 || empty($dateFrom) || empty($dateTo) || $days <= 0) {
        resultInfo(false, 'MISSING_DATA', 'Pflichtfelder fehlen');
        return;
    }

    if ($id > 0) {
        $db->execute(
            "UPDATE hr_vacation_requests
             SET date_from = :df, date_to = :dt, days = :d, notes = :n,
                 status = 'pending', approved_by = NULL, approved_at = NULL,
                 rejection_reason = NULL, mtime = NOW()
             WHERE id = :id AND employee_id = :eid",
            [':df' => $dateFrom, ':dt' => $dateTo, ':d' => $days,
             ':n' => $notes, ':id' => $id, ':eid' => $employeeId]
        );
        resultInfo(true, '', ['id' => $id]);
    } else {
        $db->execute(
            "INSERT INTO hr_vacation_requests (employee_id, date_from, date_to, days, notes)
             VALUES (:eid, :df, :dt, :d, :n)",
            [':eid' => $employeeId, ':df' => $dateFrom, ':dt' => $dateTo,
             ':d' => $days, ':n' => $notes]
        );
        $row = $db->getOne(
            'SELECT id FROM hr_vacation_requests WHERE employee_id = :eid
             ORDER BY itime DESC LIMIT 1',
            [':eid' => $employeeId]
        );
        resultInfo(true, '', ['id' => $row['id']]);
    }
}

/**
 * Genehmigt einen Urlaubsantrag
 *
 * @param int $data['id']          Antrag-ID
 * @param int $data['employee_id'] Genehmigender Mitarbeiter
 * @testdata {"action": "approveVacationRequest", "id": 1, "employee_id": 1}
 */
function approveVacationRequest($data) {
    $db = DbhCompany::begin();

    $id = intval($data['id'] ?? 0);
    $approvedBy = intval($data['employee_id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $db->execute(
        "UPDATE hr_vacation_requests
         SET status = 'approved', approved_by = :ab, approved_at = NOW(), mtime = NOW()
         WHERE id = :id",
        [':ab' => $approvedBy, ':id' => $id]
    );
    resultInfo(true, 'APPROVED');
}

/**
 * Lehnt einen Urlaubsantrag ab
 *
 * @param int    $data['id']               Antrag-ID
 * @param int    $data['employee_id']      Ablehnender Mitarbeiter
 * @param string $data['rejection_reason'] Ablehnungsgrund (optional)
 * @testdata {"action": "rejectVacationRequest", "id": 1, "employee_id": 1, "rejection_reason": "Urlaubssperre"}
 */
function rejectVacationRequest($data) {
    $db = DbhCompany::begin();

    $id = intval($data['id'] ?? 0);
    $rejectedBy = intval($data['employee_id'] ?? 0);
    $reason = !empty($data['rejection_reason']) ? $data['rejection_reason'] : null;

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $db->execute(
        "UPDATE hr_vacation_requests
         SET status = 'rejected', approved_by = :rb, approved_at = NOW(),
             rejection_reason = :rr, mtime = NOW()
         WHERE id = :id",
        [':rb' => $rejectedBy, ':rr' => $reason, ':id' => $id]
    );
    resultInfo(true, 'REJECTED');
}

/**
 * Loescht einen Urlaubsantrag (nur wenn pending oder als eigener Antrag)
 *
 * @param int $data['id']          Antrag-ID
 * @param int $data['employee_id'] Anfragender Mitarbeiter (fuer Berechtigungspruefung)
 * @testdata {"action": "deleteVacationRequest", "id": 1, "employee_id": 1}
 */
function deleteVacationRequest($data) {
    $db = DbhCompany::begin();

    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $db->execute('DELETE FROM hr_vacation_requests WHERE id = :id', [':id' => $id]);
    resultInfo(true, 'DELETED');
}

/**
 * Speichert oder aktualisiert den Jahresurlaubsanspruch eines Mitarbeiters
 *
 * @param int   $data['employee_id'] Mitarbeiter-ID
 * @param int   $data['year']        Jahr
 * @param float $data['days_total']  Urlaubsanspruch in Tagen
 * @testdata {"action": "saveVacationEntitlement", "employee_id": 1, "year": 2024, "days_total": 30}
 */
function saveVacationEntitlement($data) {
    $db = DbhCompany::begin();

    $employeeId = intval($data['employee_id'] ?? 0);
    $year = intval($data['year'] ?? date('Y'));
    $daysTotal = floatval($data['days_total'] ?? 30);

    if ($employeeId <= 0) {
        resultInfo(false, 'INVALID_EMPLOYEE', 'Ungueltige Mitarbeiter-ID');
        return;
    }

    $existing = $db->getOne(
        'SELECT id FROM hr_vacation_entitlements WHERE employee_id = :eid AND year = :y',
        [':eid' => $employeeId, ':y' => $year]
    );

    if ($existing) {
        $db->execute(
            'UPDATE hr_vacation_entitlements SET days_total = :d, mtime = NOW()
             WHERE employee_id = :eid AND year = :y',
            [':d' => $daysTotal, ':eid' => $employeeId, ':y' => $year]
        );
    } else {
        $db->execute(
            'INSERT INTO hr_vacation_entitlements (employee_id, year, days_total)
             VALUES (:eid, :y, :d)',
            [':eid' => $employeeId, ':y' => $year, ':d' => $daysTotal]
        );
    }

    resultInfo(true, '', ['employee_id' => $employeeId, 'year' => $year]);
}
