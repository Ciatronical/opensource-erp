<?php
// backend/api/oserp_config/employees.php

/**
 * Lädt alle Mitarbeiter für die Mandantenkonfiguration
 *
 * Aktive Mitarbeiter zuerst (alphabetisch), obsolete danach.
 * `deleted` ist das kivitendo-Kennzeichen für "obsolet": der Mitarbeiter
 * verschwindet aus Auswahllisten, historische Belege bleiben aber erhalten.
 *
 * @param array $data (keine Parameter benötigt)
 * @testdata {"action": "getEmployeesConfig"}
 */
function getEmployeesConfig($data) {
    $db = DbhCompany::begin();
    $employees = $db->getAll(
        "SELECT id,
                login,
                name,
                deleted AS obsolete,
                sales,
                startdate,
                enddate
         FROM employee
         ORDER BY deleted ASC, lower(coalesce(nullif(name, ''), login)) ASC"
    );
    resultInfo(true, '', ['results' => $employees ?: []]);
}

/**
 * Setzt einen Mitarbeiter auf obsolet oder wieder aktiv
 *
 * @param int  $data['id']       Mitarbeiter-ID
 * @param bool $data['obsolete'] true = obsolet, false = aktiv
 * @testdata {"action": "setEmployeeObsolete", "id": 1, "obsolete": true}
 */
function setEmployeeObsolete($data) {
    if (empty($data['id'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'ID ist erforderlich');
        return;
    }

    $db = DbhCompany::begin();
    $result = $db->getOne(
        "UPDATE employee
         SET deleted = :obsolete,
             mtime = now()
         WHERE id = :id
         RETURNING id, deleted AS obsolete",
        [
            ':id' => intval($data['id']),
            ':obsolete' => !empty($data['obsolete'])
        ]
    );

    if (!$result) {
        resultInfo(false, 'NOT_FOUND', 'Mitarbeiter nicht gefunden');
        return;
    }

    resultInfo(true, '', ['results' => $result]);
}
