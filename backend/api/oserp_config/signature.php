<?php
// backend/api/oserp_config/signature.php
//
// Unterschrift des angemeldeten Mitarbeiters (Benutzermenü → Konfiguration).
// Das Frontend bereitet das Bild auf (weißen Hintergrund entfernen, auf die
// Schrift zuschneiden, verkleinern) und schickt ein PNG als Data-URL. Es
// steht in employee_config_oserp unter dem Schlüssel signature — die Lader
// der Mitarbeiterkonfiguration für den Store lassen diesen Schlüssel aus.
// Der Brief druckt es über der Unterschriftslinie (letters.php).

require_once __DIR__.'/../lib/mitarbeiter.php';

/**
 * Unterschrift des angemeldeten Mitarbeiters als Data-URL (oder null).
 *
 * @param int $data['employee_id'] Mitarbeiter (kommt aus dem Store)
 * @testdata {"employee_id": 1}
 */
function getEmployeeSignature($data) {
    $row = DbhCompany::begin()->getOne(
        "SELECT value AS image, coalesce(mtime, itime) AS mtime
         FROM employee_config_oserp WHERE employee_id = :id AND key = 'signature'",
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
        "INSERT INTO employee_config_oserp (employee_id, key, value, itime)
         VALUES (:id, 'signature', :image, now())
         ON CONFLICT (employee_id, key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
        [':id' => $employee_id, ':image' => 'data:image/png;base64,' . base64_encode($bytes)]
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
        "DELETE FROM employee_config_oserp WHERE employee_id = :id AND key = 'signature'",
        [':id' => intval(mitarbeiterId($data) ?? 0)]
    );
    resultInfo(true, 'DELETED');
}
