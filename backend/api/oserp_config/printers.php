<?php
// backend/api/oserp_config/printers.php

/**
 * Lädt alle Drucker aus der printers-Tabelle
 *
 * @param array $data (keine Parameter benötigt)
 * @testdata {}
 */
function getPrinters($data) {
    $db = DbhCompany::begin();
    $printers = $db->getAll(
        "SELECT id, printer_description, printer_command, template_code
         FROM printers
         ORDER BY printer_description"
    );
    resultInfo(true, '', ['results' => $printers ?: []]);
}

/**
 * Speichert einen Drucker (INSERT oder UPDATE)
 *
 * @param string $data['id'] Drucker-ID (leer = neuer Drucker)
 * @param string $data['printer_description'] Beschreibung
 * @param string $data['printer_command'] Druckbefehl
 * @param string $data['template_code'] Template-Code
 * @testdata {"printer_description": "Testdrucker", "printer_command": "lp -d test", "template_code": ""}
 */
function savePrinter($data) {
    $db = DbhCompany::begin();

    $description = trim($data['printer_description'] ?? '');
    $command = trim($data['printer_command'] ?? '');
    $template_code = trim($data['template_code'] ?? '');

    if ($description === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Beschreibung ist erforderlich');
        return;
    }

    if (!empty($data['id'])) {
        // UPDATE
        $result = $db->getOne(
            "UPDATE printers
             SET printer_description = :printer_description,
                 printer_command = :printer_command,
                 template_code = :template_code
             WHERE id = :id
             RETURNING id",
            [
                ':id' => $data['id'],
                ':printer_description' => $description,
                ':printer_command' => $command,
                ':template_code' => $template_code
            ]
        );
    } else {
        // INSERT
        $result = $db->getOne(
            "INSERT INTO printers (printer_description, printer_command, template_code)
             VALUES (:printer_description, :printer_command, :template_code)
             RETURNING id",
            [
                ':printer_description' => $description,
                ':printer_command' => $command,
                ':template_code' => $template_code
            ]
        );
    }

    resultInfo(true, '', ['results' => $result]);
}

/**
 * Löscht einen Drucker
 *
 * @param int $data['id'] Drucker-ID
 * @testdata {"id": 1}
 */
function deletePrinter($data) {
    if (empty($data['id'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'ID ist erforderlich');
        return;
    }

    $db = DbhCompany::begin();
    $db->execute(
        "DELETE FROM printers WHERE id = :id",
        [':id' => $data['id']]
    );

    resultInfo(true, '');
}
