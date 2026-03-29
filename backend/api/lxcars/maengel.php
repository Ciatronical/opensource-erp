<?php
// backend/api/lxcars/maengel.php

/**
 * Bestimmt Tabelle und Spaltenname anhand des Dokumenttyps
 *
 * @param string $docType 'order' (default) oder 'invoice'
 * @return array ['table' => ..., 'id_col' => ..., 'seq' => ...]
 */
function getMaengelTableConfig($docType) {
    if ($docType === 'invoice') {
        return ['table' => 'ar_defects', 'id_col' => 'ar_id', 'seq' => 'ar_defects_id_seq'];
    }
    return ['table' => 'oe_defects', 'id_col' => 'oe_id', 'seq' => 'oe_defects_id_seq'];
}

/**
 * Lädt alle Mängel eines Dokuments (Auftrag oder Rechnung)
 *
 * @param int $data['oe_id'] Dokument-ID
 * @param string $data['doc_type'] 'order' oder 'invoice' (optional, default 'order')
 * @testdata {"oe_id": 1}
 */
function getMaengel($data) {
    $db = DbhCompany::begin();
    $docId = intval($data['oe_id']);
    $cfg = getMaengelTableConfig($data['doc_type'] ?? 'order');

    $rows = $db->getAll(
        "SELECT id, {$cfg['id_col']} AS doc_id, defect_code, defect_description, defect_class, note, sort_order
         FROM {$cfg['table']}
         WHERE {$cfg['id_col']} = :doc_id
         ORDER BY sort_order, id",
        [':doc_id' => $docId]
    );

    // possible_classes aus Stammdaten nachladen
    foreach ($rows as &$row) {
        $master = $db->getOne(
            "SELECT possible_classes FROM tuev_defect_catalog WHERE defect_code = :code LIMIT 1",
            [':code' => $row['defect_code']]
        );
        $row['possible_classes'] = $master ? $master['possible_classes'] : $row['defect_class'];
    }
    unset($row);

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Fügt einen Mangel zu einem Dokument hinzu
 *
 * @param int $data['oe_id'] Dokument-ID
 * @param string $data['doc_type'] 'order' oder 'invoice' (optional)
 * @param string $data['defect_code'] Mangel-Code aus tuev_defect_catalog
 * @param string $data['defect_description'] Beschreibung
 * @param string $data['defect_class'] Zugewiesene Mängelklasse
 * @param string $data['note'] Optionale Notiz
 * @testdata {"oe_id": 1, "defect_code": "1.1.1a", "defect_description": "Bremspedal: übermäßiger Verschleiß", "defect_class": "GM"}
 */
function addMangel($data) {
    $db = DbhCompany::begin();
    $docId = intval($data['oe_id']);
    $cfg = getMaengelTableConfig($data['doc_type'] ?? 'order');
    $mangelCode = trim($data['defect_code'] ?? '');
    $beschreibung = trim($data['defect_description'] ?? '');
    $klasse = trim($data['defect_class'] ?? '');
    $note = trim($data['note'] ?? '');

    if (!$docId || empty($mangelCode) || empty($beschreibung) || empty($klasse)) {
        resultInfo(false, 'VALIDATION_ERROR: oe_id, defect_code, defect_description and defect_class required');
        return;
    }

    // Nächste sort_order ermitteln
    $row = $db->getOne(
        "SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort
         FROM {$cfg['table']} WHERE {$cfg['id_col']} = :doc_id",
        [':doc_id' => $docId]
    );
    $nextSort = intval($row['next_sort']);

    $db->execute(
        "INSERT INTO {$cfg['table']} ({$cfg['id_col']}, defect_code, defect_description, defect_class, note, sort_order)
         VALUES (:doc_id, :code, :beschreibung, :klasse, :note, :sort_order)",
        [
            ':doc_id' => $docId,
            ':code' => $mangelCode,
            ':beschreibung' => $beschreibung,
            ':klasse' => $klasse,
            ':note' => $note ?: null,
            ':sort_order' => $nextSort
        ]
    );

    $result = $db->getOne("SELECT currval('{$cfg['seq']}') AS id");
    $newId = intval($result['id']);

    resultInfo(true, 'CREATED', [
        'id' => $newId,
        'sort_order' => $nextSort
    ]);
}

/**
 * Aktualisiert einen Mangel (Klasse oder Notiz)
 *
 * @param int $data['id'] Mangel-ID
 * @param string $data['doc_type'] 'order' oder 'invoice' (optional)
 * @param array $data['data'] Key-Value-Paare { defect_class?, note? }
 * @testdata {"id": 1, "data": {"defect_class": "EM"}}
 */
function updateMangel($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id']);
    $cfg = getMaengelTableConfig($data['doc_type'] ?? 'order');
    $fields = $data['data'];

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    $allowed = ['defect_class', 'note'];
    $setClauses = [];
    $params = [':id' => $id];

    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed)) continue;
        $paramName = ':' . $key;
        $setClauses[] = "$key = $paramName";
        $params[$paramName] = ($value === '' || $value === null) ? null : $value;
    }

    if (empty($setClauses)) {
        resultInfo(true, 'NO_CHANGES');
        return;
    }

    $setString = implode(', ', $setClauses);
    $db->execute(
        "UPDATE {$cfg['table']} SET $setString WHERE id = :id",
        $params
    );

    resultInfo(true, 'UPDATED');
}

/**
 * Löscht einen Mangel
 *
 * @param int $data['id'] Mangel-ID
 * @param string $data['doc_type'] 'order' oder 'invoice' (optional)
 * @testdata {"id": 1}
 */
function deleteMangel($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id']);
    $cfg = getMaengelTableConfig($data['doc_type'] ?? 'order');

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    $db->execute(
        "DELETE FROM {$cfg['table']} WHERE id = :id",
        [':id' => $id]
    );

    resultInfo(true, 'DELETED');
}

/**
 * Löscht alle Mängel eines Dokuments
 *
 * @param int $data['oe_id'] Dokument-ID
 * @param string $data['doc_type'] 'order' oder 'invoice' (optional)
 * @testdata {"oe_id": 1}
 */
function deleteAllMaengel($data) {
    $db = DbhCompany::begin();
    $docId = intval($data['oe_id']);
    $cfg = getMaengelTableConfig($data['doc_type'] ?? 'order');

    if (!$docId) {
        resultInfo(false, 'VALIDATION_ERROR: oe_id required');
        return;
    }

    $db->execute(
        "DELETE FROM {$cfg['table']} WHERE {$cfg['id_col']} = :doc_id",
        [':doc_id' => $docId]
    );

    resultInfo(true, 'DELETED');
}

/**
 * Durchsucht die Mängelliste (Stammdaten) für Autocomplete
 *
 * @param string $data['query'] Suchbegriff (sucht in defect_code + defect_description)
 * @testdata {"query": "Brems"}
 */
function searchMaengelliste($data) {
    $db = DbhCompany::begin();
    $query = trim($data['query'] ?? '');

    if (strlen($query) < 1) {
        resultInfo(true, 'OK', []);
        return;
    }

    $rows = $db->getAll(
        "SELECT id, defect_code, defect_description, pruefgruppe, pruefgruppe_nr,
                unterpunkt, unterpunkt_nr, possible_classes
         FROM tuev_defect_catalog
         WHERE LOWER(defect_code) LIKE LOWER(:code_query)
            OR LOWER(defect_description) LIKE LOWER(:desc_query)
         ORDER BY
             CASE WHEN LOWER(defect_code) = LOWER(:exact) THEN 0 ELSE 1 END,
             pruefgruppe_nr, unterpunkt_nr, defect_code
         LIMIT 20",
        [
            ':code_query' => $query . '%',
            ':desc_query' => '%' . $query . '%',
            ':exact' => $query
        ]
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Legt einen eigenen (benutzerdefinierten) Mangel an
 *
 * Erzeugt einen neuen Eintrag in tuev_defect_catalog mit automatisch generiertem Code (E-001, E-002, ...)
 * und gibt den neuen Eintrag zurück.
 *
 * @param string $data['defect_description'] Beschreibung des Mangels (Pflicht)
 * @param string $data['possible_classes'] Pipe-getrennte Mängelklassen (Pflicht, z.B. "GM|EM")
 * @testdata {"defect_description": "Eigener Testmangel", "possible_classes": "GM|EM"}
 */
function addCustomMangel($data) {
    $db = DbhCompany::begin();
    $beschreibung = trim($data['defect_description'] ?? '');
    $klassen = trim($data['possible_classes'] ?? '');

    if (empty($beschreibung) || empty($klassen)) {
        resultInfo(false, 'VALIDATION_ERROR: defect_description and possible_classes required');
        return;
    }

    // Nächsten E-xxx Code generieren
    $row = $db->getOne(
        "SELECT defect_code FROM tuev_defect_catalog
         WHERE defect_code LIKE 'E-%'
         ORDER BY defect_code DESC LIMIT 1"
    );

    $nextNumber = 1;
    if ($row && preg_match('/^E-(\d+)$/', $row['defect_code'], $matches)) {
        $nextNumber = intval($matches[1]) + 1;
    }
    $newCode = 'E-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

    $db->execute(
        "INSERT INTO tuev_defect_catalog (pruefgruppe_nr, pruefgruppe, defect_code, defect_description, possible_classes, is_custom)
         VALUES (:prg_nr, :prg, :code, :beschreibung, :klassen, true)",
        [
            ':prg_nr' => 'E',
            ':prg' => 'Eigene Mängel',
            ':code' => $newCode,
            ':beschreibung' => $beschreibung,
            ':klassen' => $klassen
        ]
    );

    $result = $db->getOne("SELECT currval('tuev_defect_catalog_id_seq') AS id");

    resultInfo(true, 'CREATED', [
        'id' => intval($result['id']),
        'defect_code' => $newCode,
        'defect_description' => $beschreibung,
        'possible_classes' => $klassen,
        'pruefgruppe' => 'Eigene Mängel'
    ]);
}
