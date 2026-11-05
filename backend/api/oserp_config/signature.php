<?php
// backend/api/oserp_config/signature.php
//
// Unterschrift des angemeldeten Mitarbeiters (Benutzermenü → Konfiguration).
// Das Frontend bereitet das Bild auf (weißen Hintergrund entfernen, auf die
// Schrift zuschneiden, verkleinern) und schickt ein PNG als Data-URL. Der
// Brief druckt es über der Unterschriftslinie (letters.php).

require_once __DIR__.'/../lib/mitarbeiter.php';

/**
 * Unterschrift des angemeldeten Mitarbeiters als Data-URL (oder null).
 *
 * @param int $data['employee_id'] Mitarbeiter (kommt aus dem Store)
 * @testdata {"employee_id": 1}
 */
function getEmployeeSignature($data) {
    $row = DbhCompany::begin()->getOne(
        "SELECT 'data:' || mime || ';base64,' || translate(encode(image, 'base64'), E'\\n', '') AS image, mtime
         FROM employee_signature_oserp WHERE employee_id = :id",
        [':id' => intval(mitarbeiterId($data) ?? 0)]
    );
    resultInfo(true, '', ['results' => ['image' => $row['image'] ?? null, 'mtime' => $row['mtime'] ?? null]]);
}

/**
 * Unterschrift speichern (ersetzt eine vorhandene).
 *
 * @param int    $data['employee_id'] Mitarbeiter (kommt aus dem Store)
 * @param string $data['image']       PNG als Data-URL, höchstens 1 MB (die Vorlage bindet es als .png ein)
 * @testdata {"employee_id": 1, "image": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=="}
 */
function saveEmployeeSignature($data) {
    $employee_id = intval(mitarbeiterId($data) ?? 0);
    if (!$employee_id) throw new ApiError('EMPLOYEE_NOT_FOUND', 'Mitarbeiter fehlt');
    if (!preg_match('#^data:image/png;base64,(.+)$#s', (string)($data['image'] ?? ''), $m)) {
        throw new ApiError('VALIDATION_ERROR', 'Nur PNG');
    }
    $bytes = base64_decode($m[1], true);
    $info  = $bytes !== false ? @getimagesizefromstring($bytes) : false;
    if (!$info || $info[2] !== IMAGETYPE_PNG || strlen($bytes) > 1024 * 1024) {
        throw new ApiError('VALIDATION_ERROR', 'Bild ungültig oder größer als 1 MB');
    }
    DbhCompany::begin()->execute(
        "INSERT INTO employee_signature_oserp (employee_id, image, mime)
         VALUES (:id, decode(:image, 'base64'), :mime)
         ON CONFLICT (employee_id) DO UPDATE SET image = EXCLUDED.image, mime = EXCLUDED.mime, mtime = now()",
        [':id' => $employee_id, ':image' => base64_encode($bytes), ':mime' => 'image/png']
    );
    resultInfo(true, 'SAVED');
}

/**
 * Unterschrift entfernen.
 *
 * @param int $data['employee_id'] Mitarbeiter (kommt aus dem Store)
 * @testdata {"employee_id": 1}
 */
function deleteEmployeeSignature($data) {
    DbhCompany::begin()->execute(
        "DELETE FROM employee_signature_oserp WHERE employee_id = :id",
        [':id' => intval(mitarbeiterId($data) ?? 0)]
    );
    resultInfo(true, 'DELETED');
}
