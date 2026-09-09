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
        "SELECT p.id, p.printer_description, p.printer_command, p.template_code, COALESCE(pe.hide_factura, false) AS hide_factura
         FROM printers p
         LEFT JOIN printers_ext pe ON pe.printer_id = p.id
         ORDER BY p.printer_description"
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
 * @param bool $data['hide_factura'] In Faktura ausblenden
 * @testdata {"printer_description": "Testdrucker", "printer_command": "lp -d test", "template_code": "", "hide_factura": false}
 */
function savePrinter($data) {
    $db = DbhCompany::begin();

    $description = trim($data['printer_description'] ?? '');
    $command = trim($data['printer_command'] ?? '');
    $template_code = trim($data['template_code'] ?? '');
    $hide_factura = !empty($data['hide_factura']);

    if ($description === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Beschreibung ist erforderlich');
        return;
    }

    if (!empty($data['id'])) {
        // UPDATE
        $result = $db->getOne(
            "WITH p AS (
                 UPDATE printers
                 SET printer_description = :printer_description,
                     printer_command = :printer_command,
                     template_code = :template_code
                 WHERE id = :id
                 RETURNING id
             )
             INSERT INTO printers_ext (printer_id, hide_factura)
             SELECT id, CAST(:hide_factura AS boolean) FROM p
             ON CONFLICT (printer_id) DO UPDATE SET hide_factura = EXCLUDED.hide_factura
             RETURNING printer_id AS id",
            [
                ':id' => $data['id'],
                ':printer_description' => $description,
                ':printer_command' => $command,
                ':template_code' => $template_code,
                ':hide_factura' => $hide_factura
            ]
        );
    } else {
        // INSERT
        $result = $db->getOne(
            "WITH p AS (
                 INSERT INTO printers (printer_description, printer_command, template_code)
                 VALUES (:printer_description, :printer_command, :template_code)
                 RETURNING id
             )
             INSERT INTO printers_ext (printer_id, hide_factura)
             SELECT id, CAST(:hide_factura AS boolean) FROM p
             RETURNING printer_id AS id",
            [
                ':printer_description' => $description,
                ':printer_command' => $command,
                ':template_code' => $template_code,
                ':hide_factura' => $hide_factura
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
