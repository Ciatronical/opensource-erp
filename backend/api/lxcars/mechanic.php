<?php
// backend/api/lxcars/mechanic.php

/**
 * Ermittelt die Employee-ID des eingeloggten Benutzers
 *
 * @return int|null Employee-ID
 */
function _getMechanicEmployeeId() {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    $login = $auth->getLogin();

    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT id FROM employee WHERE login = :login",
        [':login' => $login]
    );
    return $row ? intval($row['id']) : null;
}

/**
 * Lädt alle Aufträge bei denen der eingeloggte Mitarbeiter
 * mindestens einer Arbeitsanweisung zugeordnet ist
 *
 * @testdata {}
 */
function getMechanicOrders() {
    $db = DbhCompany::begin();
    $employeeId = _getMechanicEmployeeId();

    if (!$employeeId) {
        resultInfo(true, 'OK', []);
        return;
    }

    $rows = $db->getAll(
        "SELECT
            oe.id,
            oe.ordnumber,
            oe.transdate,
            oe.closed,
            c.name AS customer_name,
            car.c_ln AS vehicle_plate,
            car.c_id AS vehicle_id,
            kba.hersteller AS vehicle_manufacturer,
            kba.d2 AS vehicle_model,
            (SELECT COUNT(*) FROM oe_instructions_lxcars WHERE oe_id = oe.id) AS instruction_count,
            (SELECT COUNT(*) FROM oe_instructions_lxcars WHERE oe_id = oe.id AND done = true) AS instruction_done,
            (SELECT COUNT(*) FROM oe_parts_requests_lxcars WHERE oe_id = oe.id AND status = 'pending') AS pending_parts
         FROM oe
         JOIN oe_instructions_lxcars i ON i.oe_id = oe.id AND i.employee_id = :emp_id
         LEFT JOIN customer c ON oe.customer_id = c.id
         LEFT JOIN oe_ext ext ON ext.oe_id = oe.id
         LEFT JOIN cars_lxcars car ON ext.c_id = car.c_id
         LEFT JOIN kba_lxcars kba ON car.kba_id = kba.id
         WHERE oe.closed IS NOT TRUE
         GROUP BY oe.id, oe.ordnumber, oe.transdate, oe.closed,
                  c.name, car.c_ln, car.c_id, kba.hersteller, kba.d2
         HAVING COUNT(*) FILTER (WHERE i.done = false) > 0
         ORDER BY oe.transdate ASC, oe.id ASC",
        [':emp_id' => $employeeId]
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Lädt alle offenen Aufträge (unabhängig vom zugewiesenen Mechaniker)
 *
 * @testdata {}
 */
function getAllMechanicOrders() {
    $db = DbhCompany::begin();

    $rows = $db->getAll(
        "SELECT
            oe.id,
            oe.ordnumber,
            oe.transdate,
            oe.closed,
            c.name AS customer_name,
            car.c_ln AS vehicle_plate,
            car.c_id AS vehicle_id,
            kba.hersteller AS vehicle_manufacturer,
            kba.d2 AS vehicle_model,
            (SELECT COUNT(*) FROM oe_instructions_lxcars WHERE oe_id = oe.id) AS instruction_count,
            (SELECT COUNT(*) FROM oe_instructions_lxcars WHERE oe_id = oe.id AND done = true) AS instruction_done,
            (SELECT COUNT(*) FROM oe_parts_requests_lxcars WHERE oe_id = oe.id AND status = 'pending') AS pending_parts
         FROM oe
         LEFT JOIN customer c ON oe.customer_id = c.id
         LEFT JOIN oe_ext ext ON ext.oe_id = oe.id
         LEFT JOIN cars_lxcars car ON ext.c_id = car.c_id
         LEFT JOIN kba_lxcars kba ON car.kba_id = kba.id
         WHERE oe.closed IS NOT TRUE
           AND oe.record_type NOT IN ('sales_quotation', 'request_quotation')
           AND oe.customer_id IS NOT NULL
           AND (ext.status IS NULL OR ext.status != COALESCE((SELECT value FROM defaults_oserp WHERE key = 'lxcars_order_hide_status'), ''))
           AND EXISTS (
               SELECT 1 FROM oe_instructions_lxcars i
               WHERE i.oe_id = oe.id AND i.done = false
           )
         ORDER BY oe.transdate ASC, oe.id ASC",
        []
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Lädt die Detaildaten eines Auftrags für den Mechaniker-Modus
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @testdata {"oe_id": 1}
 */
function getMechanicOrderDetail($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id'] ?? 0);

    if (!$oeId) {
        resultInfo(false, 'VALIDATION_ERROR: oe_id required');
        return;
    }

    // Auftrags-Header
    $order = $db->getOne(
        "SELECT oe.id, oe.ordnumber, oe.transdate, oe.closed, oe.amount, oe.netamount,
                c.name AS customer_name, c.id AS customer_id
         FROM oe
         LEFT JOIN customer c ON oe.customer_id = c.id
         WHERE oe.id = :oe_id",
        [':oe_id' => $oeId]
    );

    if (!$order) {
        resultInfo(false, 'NOT_FOUND');
        return;
    }

    // Fahrzeug
    $vehicle = $db->getOne(
        "SELECT car.c_id, car.c_ln, car.c_fin,
                kba.hersteller, kba.d2, kba.marke,
                ext.km_stand
         FROM oe_ext ext
         JOIN cars_lxcars car ON ext.c_id = car.c_id
         LEFT JOIN kba_lxcars kba ON car.kba_id = kba.id
         WHERE ext.oe_id = :oe_id",
        [':oe_id' => $oeId]
    );

    // Anweisungen
    $instructions = $db->getAll(
        "SELECT i.id, i.oe_id, i.description, i.done, i.sort_order,
                i.instruction_number, i.planned_minutes, i.actual_minutes,
                i.employee_id, e.name AS employee_name
         FROM oe_instructions_lxcars i
         LEFT JOIN employee e ON e.id = i.employee_id
         WHERE i.oe_id = :oe_id
         ORDER BY i.sort_order, i.id",
        [':oe_id' => $oeId]
    );

    // Positionen
    $items = $db->getAll(
        "SELECT oi.id, oi.position, oi.parts_id, oi.description, oi.longdescription,
                oi.qty, oi.sellprice, oi.discount, oi.unit,
                p.partnumber, p.part_type
         FROM orderitems oi
         LEFT JOIN parts p ON oi.parts_id = p.id
         WHERE oi.trans_id = :oe_id
         ORDER BY oi.position",
        [':oe_id' => $oeId]
    );

    // Mängel
    $defects = $db->getAll(
        "SELECT id, oe_id, defect_code, defect_description, defect_class, note, sort_order
         FROM oe_defects
         WHERE oe_id = :oe_id
         ORDER BY sort_order, id",
        [':oe_id' => $oeId]
    );

    // Ersatzteil-Anfragen
    $partsRequests = $db->getAll(
        "SELECT pr.id, pr.oe_id, pr.parts_id, pr.partnumber, pr.description,
                pr.qty, pr.unit, pr.note, pr.photo, pr.status,
                pr.requested_by, pr.ordered_by, pr.vendor_id,
                pr.requested_at, pr.ordered_at,
                e_req.name AS requested_by_name,
                e_ord.name AS ordered_by_name,
                v.name AS vendor_name
         FROM oe_parts_requests_lxcars pr
         LEFT JOIN employee e_req ON e_req.id = pr.requested_by
         LEFT JOIN employee e_ord ON e_ord.id = pr.ordered_by
         LEFT JOIN vendor v ON v.id = pr.vendor_id
         WHERE pr.oe_id = :oe_id
         ORDER BY pr.requested_at DESC",
        [':oe_id' => $oeId]
    );

    resultInfo(true, 'OK', [
        'order' => $order,
        'vehicle' => $vehicle ?: null,
        'instructions' => $instructions ?: [],
        'items' => $items ?: [],
        'defects' => $defects ?: [],
        'parts_requests' => $partsRequests ?: []
    ]);
}

/**
 * Erstellt eine Ersatzteil-Anfrage und legt gleichzeitig eine Auftragsposition an.
 * Die Position wird rot markiert (pending) bis der Werkstattleiter bestellt.
 *
 * @param int    $data['oe_id']       Auftrags-ID
 * @param int    $data['parts_id']    Artikel-ID (optional, bei Artikelsuche)
 * @param string $data['partnumber']  Artikelnummer (optional)
 * @param string $data['description'] Beschreibung (Pflicht)
 * @param float  $data['qty']         Menge
 * @param string $data['unit']        Einheit
 * @param string $data['note']        Notiz (optional)
 * @testdata {"oe_id": 1, "description": "Bremsbelag vorne", "qty": 2, "unit": "Stck"}
 */
function addPartsRequest($data) {
    $db = DbhCompany::begin();
    $employeeId = _getMechanicEmployeeId();
    $oeId = intval($data['oe_id'] ?? 0);
    $partsId = !empty($data['parts_id']) ? intval($data['parts_id']) : null;
    $description = trim($data['description'] ?? '');
    $qty = floatval($data['qty'] ?? 1);
    $unit = trim($data['unit'] ?? 'Stck');

    if (!$oeId) {
        resultInfo(false, 'VALIDATION_ERROR: oe_id required');
        return;
    }
    if (empty($description)) {
        resultInfo(false, 'VALIDATION_ERROR: description required');
        return;
    }

    // 1. Position im Auftrag anlegen
    $nextPos = $db->getOne(
        "SELECT COALESCE(MAX(position), 0) + 1 AS next_pos FROM orderitems WHERE trans_id = :oe_id",
        [':oe_id' => $oeId]
    );

    $sellprice = 0;
    if ($partsId) {
        $part = $db->getOne("SELECT sellprice FROM parts WHERE id = :id", [':id' => $partsId]);
        if ($part) $sellprice = floatval($part['sellprice']);
    }

    $itemResult = $db->getOne(
        "INSERT INTO orderitems (trans_id, parts_id, position, description, qty, sellprice, discount, unit)
         VALUES (:oe_id, :parts_id, :pos, :desc, :qty, :price, 0, :unit)
         RETURNING id",
        [
            ':oe_id' => $oeId,
            ':parts_id' => $partsId,
            ':pos' => intval($nextPos['next_pos']),
            ':desc' => $description,
            ':qty' => $qty,
            ':price' => $sellprice,
            ':unit' => $unit
        ]
    );
    $orderitemId = intval($itemResult['id']);

    // 2. Bestellstatus anlegen (verknuepft mit Position)
    $reqResult = $db->getOne(
        "INSERT INTO oe_parts_requests_lxcars
            (oe_id, orderitem_id, parts_id, partnumber, description, qty, unit, note, requested_by)
         VALUES (:oe_id, :item_id, :parts_id, :partnumber, :desc, :qty, :unit, :note, :emp)
         RETURNING id",
        [
            ':oe_id' => $oeId,
            ':item_id' => $orderitemId,
            ':parts_id' => $partsId,
            ':partnumber' => trim($data['partnumber'] ?? ''),
            ':desc' => $description,
            ':qty' => $qty,
            ':unit' => $unit,
            ':note' => trim($data['note'] ?? ''),
            ':emp' => $employeeId
        ]
    );

    resultInfo(true, 'CREATED', [
        'id' => intval($reqResult['id']),
        'orderitem_id' => $orderitemId
    ]);
}

/**
 * Markiert eine bestehende Auftragsposition als "muss bestellt werden"
 *
 * @param int    $data['oe_id']        Auftrags-ID
 * @param int    $data['orderitem_id'] Position-ID
 * @param string $data['note']         Notiz (optional)
 * @testdata {"oe_id": 1, "orderitem_id": 100}
 */
function requestPartsForItem($data) {
    $db = DbhCompany::begin();
    $employeeId = _getMechanicEmployeeId();
    $oeId = intval($data['oe_id'] ?? 0);
    $orderitemId = intval($data['orderitem_id'] ?? 0);

    if (!$oeId || !$orderitemId) {
        resultInfo(false, 'VALIDATION_ERROR: oe_id and orderitem_id required');
        return;
    }

    // Pruefen ob bereits ein Request existiert
    $existing = $db->getOne(
        "SELECT id FROM oe_parts_requests_lxcars WHERE orderitem_id = :item_id",
        [':item_id' => $orderitemId]
    );

    if ($existing) {
        resultInfo(false, 'ALREADY_EXISTS');
        return;
    }

    // Positionsdaten lesen
    $item = $db->getOne(
        "SELECT oi.parts_id, oi.description, oi.qty, oi.unit, p.partnumber
         FROM orderitems oi
         LEFT JOIN parts p ON oi.parts_id = p.id
         WHERE oi.id = :id",
        [':id' => $orderitemId]
    );

    if (!$item) {
        resultInfo(false, 'NOT_FOUND');
        return;
    }

    $db->execute(
        "INSERT INTO oe_parts_requests_lxcars
            (oe_id, orderitem_id, parts_id, partnumber, description, qty, unit, note, requested_by)
         VALUES (:oe_id, :item_id, :parts_id, :partnumber, :desc, :qty, :unit, :note, :emp)",
        [
            ':oe_id' => $oeId,
            ':item_id' => $orderitemId,
            ':parts_id' => $item['parts_id'] ?: null,
            ':partnumber' => $item['partnumber'] ?? '',
            ':desc' => $item['description'],
            ':qty' => floatval($item['qty']),
            ':unit' => $item['unit'] ?? 'Stck',
            ':note' => trim($data['note'] ?? ''),
            ':emp' => $employeeId
        ]
    );

    resultInfo(true, 'CREATED');
}

/**
 * Entfernt den Bestellstatus einer Position (z.B. versehentlich angefordert)
 *
 * @param int $data['orderitem_id'] Position-ID
 * @testdata {"orderitem_id": 100}
 */
function cancelPartsRequest($data) {
    $db = DbhCompany::begin();
    $orderitemId = intval($data['orderitem_id'] ?? 0);

    if (!$orderitemId) {
        resultInfo(false, 'VALIDATION_ERROR: orderitem_id required');
        return;
    }

    $db->execute(
        "DELETE FROM oe_parts_requests_lxcars WHERE orderitem_id = :item_id AND status = 'pending'",
        [':item_id' => $orderitemId]
    );

    resultInfo(true, 'DELETED');
}

/**
 * Setzt den Status einer Anfrage auf "received" (Ware eingetroffen)
 *
 * @param int $data['id'] Request-ID
 * @testdata {"id": 1}
 */
function markPartsRequestReceived($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    $db->execute(
        "UPDATE oe_parts_requests_lxcars SET status = 'received' WHERE id = :id AND status = 'ordered'",
        [':id' => $id]
    );

    resultInfo(true, 'RECEIVED');
}

/**
 * Lädt den Bestellstatus aller Positionen eines Auftrags
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @testdata {"oe_id": 1}
 */
function getPartsRequestsByOrder($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id'] ?? 0);

    $rows = $db->getAll(
        "SELECT pr.id, pr.orderitem_id, pr.status, pr.note, pr.photo,
                pr.requested_by, pr.vendor_id, pr.requested_at, pr.ordered_at,
                e.name AS requested_by_name, v.name AS vendor_name
         FROM oe_parts_requests_lxcars pr
         LEFT JOIN employee e ON e.id = pr.requested_by
         LEFT JOIN vendor v ON v.id = pr.vendor_id
         WHERE pr.oe_id = :oe_id",
        [':oe_id' => $oeId]
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Aktualisiert eine Ersatzteil-Anfrage
 *
 * @param int $data['id'] Request-ID
 * @testdata {"id": 1, "qty": 4, "note": "dringend"}
 */
function updatePartsRequest($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    $fields = [];
    $params = [':id' => $id];

    $allowed = ['description', 'qty', 'unit', 'note', 'partnumber', 'parts_id'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "$f = :$f";
            $params[":$f"] = $data[$f];
        }
    }

    if (empty($fields)) {
        resultInfo(false, 'VALIDATION_ERROR: no fields to update');
        return;
    }

    $db->execute(
        "UPDATE oe_parts_requests_lxcars SET " . implode(', ', $fields) . " WHERE id = :id AND status = 'pending'",
        $params
    );

    resultInfo(true, 'UPDATED');
}

/**
 * Löscht eine Ersatzteil-Anfrage (nur im Status pending)
 *
 * @param int $data['id'] Request-ID
 * @testdata {"id": 1}
 */
function deletePartsRequest($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    // Auch bestellte Teile dürfen gelöscht werden (z.B. nicht mehr benötigt)
    $db->execute(
        "DELETE FROM oe_parts_requests_lxcars WHERE id = :id AND status IN ('pending', 'ordered')",
        [':id' => $id]
    );

    resultInfo(true, 'DELETED');
}

/**
 * Ermittelt den Speicherpfad für Ersatzteil-Fotos (unter Fahrzeug/Kunde)
 * Ordnername = erste Instruction des Auftrags, Fallback = Positionsbeschreibung
 *
 * @param object $db DB-Handle
 * @param int $oeId Auftrags-ID
 * @param int $requestId Request-ID (für Fallback-Beschreibung)
 * @return string Absoluter Verzeichnispfad
 */
function _getPartsPhotoDir($db, $oeId, $requestId) {
    // Fahrzeug und Kunde ermitteln
    $order = $db->getOne(
        "SELECT oe.customer_id, ext.c_id
         FROM oe
         LEFT JOIN oe_ext ext ON ext.oe_id = oe.id
         WHERE oe.id = :oe_id",
        [':oe_id' => $oeId]
    );

    // Ordnername: erste Instruction oder Positionsbeschreibung
    $folderName = '';
    $instr = $db->getOne(
        "SELECT description FROM oe_instructions_lxcars WHERE oe_id = :oe_id ORDER BY sort_order, id LIMIT 1",
        [':oe_id' => $oeId]
    );
    if ($instr && trim($instr['description'])) {
        $folderName = trim($instr['description']);
    } else {
        $item = $db->getOne(
            "SELECT pr.description FROM oe_parts_requests_lxcars pr WHERE pr.id = :id",
            [':id' => $requestId]
        );
        $folderName = trim($item['description'] ?? 'Ersatzteile');
    }

    // Ordnername sanitizen (Sonderzeichen entfernen, Umlaute erhalten)
    $folderName = preg_replace('/[\/\\\\:*?"<>|\x00]/', '_', $folderName);
    $folderName = mb_substr($folderName, 0, 80);

    $cId = intval($order['c_id'] ?? 0);
    $customerId = intval($order['customer_id'] ?? 0);

    if ($cId > 0) {
        $dir = fmDataDir() . '/fahrzeuge/' . $cId . '/' . $folderName;
    } elseif ($customerId > 0) {
        $dir = fmDataDir() . '/customers/' . $customerId . '/' . $folderName;
    } else {
        $dir = fmDataDir() . '/parts_requests/' . intval($oeId);
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

/**
 * Speichert ein Foto zu einer Ersatzteil-Anfrage (mehrere Fotos möglich)
 * Speicherort: Fahrzeug-Ordner/{Instruction} oder Kunden-Ordner/{Instruction}
 * Dateinamen: {requestId}_1.jpg, {requestId}_2.jpg, ...
 *
 * @param int    $data['id']    Request-ID
 * @param string $data['image'] Base64-kodiertes Bild
 * @testdata {"id": 1, "image": "data:image/jpeg;base64,..."}
 */
function savePartsRequestPhoto($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $imageData = $data['image'] ?? '';

    if (!$id || !$imageData) {
        resultInfo(false, 'VALIDATION_ERROR: id and image required');
        return;
    }

    $row = $db->getOne(
        "SELECT oe_id, photo FROM oe_parts_requests_lxcars WHERE id = :id",
        [':id' => $id]
    );

    if (!$row) {
        resultInfo(false, 'NOT_FOUND');
        return;
    }

    $dir = _getPartsPhotoDir($db, intval($row['oe_id']), $id);

    // Nächste freie Nummer ermitteln
    $existing = $row['photo'] ? array_filter(explode(',', $row['photo'])) : [];
    $nextNum = count($existing) + 1;

    // Base64 dekodieren
    $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
    $binary = base64_decode($base64);
    $filename = $id . '_' . $nextNum . '.jpg';
    file_put_contents($dir . '/' . $filename, $binary);

    // Relativen Pfad ab fmDataDir() speichern (kommaseparierte Liste)
    $relPath = str_replace(fmDataDir() . '/', '', $dir . '/' . $filename);
    $existing[] = $relPath;
    $photoList = implode(',', $existing);

    $db->execute(
        "UPDATE oe_parts_requests_lxcars SET photo = :photo WHERE id = :id",
        [':photo' => $photoList, ':id' => $id]
    );

    resultInfo(true, 'SAVED', ['photos' => $existing]);
}

/**
 * Lädt alle Fotos einer Ersatzteil-Anfrage
 *
 * @param int $data['id'] Request-ID
 * @testdata {"id": 1}
 */
function getPartsRequestPhoto($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    $row = $db->getOne(
        "SELECT photo FROM oe_parts_requests_lxcars WHERE id = :id",
        [':id' => $id]
    );

    if (!$row || !$row['photo']) {
        resultInfo(true, 'OK', ['photos' => []]);
        return;
    }

    $paths = array_filter(explode(',', $row['photo']));
    $photos = [];

    foreach ($paths as $relPath) {
        $fullPath = fmDataDir() . '/' . $relPath;
        if (file_exists($fullPath)) {
            $photos[] = [
                'path' => $relPath,
                'image' => base64_encode(file_get_contents($fullPath))
            ];
        }
    }

    resultInfo(true, 'OK', ['photos' => $photos]);
}

/**
 * Löscht ein einzelnes Foto einer Ersatzteil-Anfrage
 *
 * @param int    $data['id']   Request-ID
 * @param string $data['path'] Relativer Pfad des zu löschenden Fotos
 * @testdata {"id": 1, "path": "fahrzeuge/123/Bremsen/1_1.jpg"}
 */
function deletePartsRequestPhoto($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $pathToDelete = $data['path'] ?? '';

    if (!$id || !$pathToDelete) {
        resultInfo(false, 'VALIDATION_ERROR: id and path required');
        return;
    }

    $row = $db->getOne(
        "SELECT photo FROM oe_parts_requests_lxcars WHERE id = :id",
        [':id' => $id]
    );

    if (!$row || !$row['photo']) {
        resultInfo(false, 'NOT_FOUND');
        return;
    }

    $paths = array_filter(explode(',', $row['photo']));
    $remaining = array_filter($paths, function($p) use ($pathToDelete) {
        return trim($p) !== trim($pathToDelete);
    });

    // Datei vom Dateisystem löschen
    $fullPath = fmDataDir() . '/' . $pathToDelete;
    if (file_exists($fullPath)) {
        unlink($fullPath);
    }

    // DB aktualisieren
    $photoList = implode(',', array_values($remaining));
    $db->execute(
        "UPDATE oe_parts_requests_lxcars SET photo = :photo WHERE id = :id",
        [':photo' => $photoList ?: null, ':id' => $id]
    );

    resultInfo(true, 'DELETED', ['photos' => array_values($remaining)]);
}

/**
 * Markiert eine Ersatzteil-Anfrage als bestellt
 *
 * @param int $data['id']        Request-ID
 * @param int $data['vendor_id'] Lieferanten-ID
 * @testdata {"id": 1, "vendor_id": 42}
 */
function markPartsRequestOrdered($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $vendorId = intval($data['vendor_id'] ?? 0);
    $employeeId = _getMechanicEmployeeId();

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    $db->execute(
        "UPDATE oe_parts_requests_lxcars
         SET status = 'ordered', ordered_by = :ordered_by, vendor_id = :vendor_id, ordered_at = now()
         WHERE id = :id AND status IN ('pending', 'ordered')",
        [':id' => $id, ':ordered_by' => $employeeId, ':vendor_id' => $vendorId ?: null]
    );

    resultInfo(true, 'ORDERED');
}

/**
 * Setzt eine bestellte Ersatzteil-Anfrage zurück auf "zu bestellen"
 * (z.B. wenn Lieferant nicht liefern kann)
 *
 * @param int $data['id'] Request-ID
 * @testdata {"id": 1}
 */
function revertPartsRequestToPending($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    $db->execute(
        "UPDATE oe_parts_requests_lxcars
         SET status = 'pending', ordered_by = NULL, vendor_id = NULL, ordered_at = NULL
         WHERE id = :id AND status = 'ordered'",
        [':id' => $id]
    );

    resultInfo(true, 'REVERTED');
}

/**
 * Lädt alle offenen Ersatzteil-Anfragen gruppiert nach Auftrag (für InfoBar)
 * Gibt pro Auftrag: ordnumber, Kunde, Fahrzeug, Anzahl offener Teile
 *
 * @testdata {}
 */
function getPendingPartsRequests() {
    $db = DbhCompany::begin();

    $rows = $db->getAll(
        "SELECT pr.oe_id,
                oe.ordnumber,
                c.name AS customer_name,
                car.c_ln AS vehicle_plate,
                COUNT(*) AS pending_count,
                MAX(pr.requested_at) AS requested_at,
                json_agg(json_build_object(
                    'id', pr.id,
                    'orderitem_id', pr.orderitem_id,
                    'description', pr.description,
                    'partnumber', pr.partnumber,
                    'qty', pr.qty,
                    'unit', pr.unit,
                    'note', pr.note,
                    'requested_by_name', e.name,
                    'requested_at', pr.requested_at
                ) ORDER BY pr.requested_at DESC) AS items
         FROM oe_parts_requests_lxcars pr
         JOIN oe ON oe.id = pr.oe_id
         LEFT JOIN customer c ON oe.customer_id = c.id
         LEFT JOIN employee e ON e.id = pr.requested_by
         LEFT JOIN oe_ext ext ON ext.oe_id = oe.id
         LEFT JOIN cars_lxcars car ON ext.c_id = car.c_id
         WHERE pr.status = 'pending'
         GROUP BY pr.oe_id, oe.ordnumber, c.name, car.c_ln
         ORDER BY MIN(pr.requested_at) ASC",
        []
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Aufträge bei denen alle Instructions erledigt sind (letzte 24h)
 *
 * @testdata {}
 */
function getCompletedOrders() {
    $db = DbhCompany::begin();

    $rows = $db->getAll(
        "SELECT o.id AS oe_id, o.ordnumber,
                COALESCE(c.name, '') AS customer_name,
                MAX(i.done_at) AS completed_at
         FROM oe o
         JOIN oe_instructions_lxcars i ON i.oe_id = o.id
         LEFT JOIN customer c ON c.id = o.customer_id
         WHERE NOT EXISTS (
             SELECT 1 FROM oe_instructions_lxcars i2
             WHERE i2.oe_id = o.id AND i2.done = false
         )
         AND i.done_at >= NOW() - INTERVAL '24 hours'
         GROUP BY o.id, o.ordnumber, c.name
         ORDER BY MAX(i.done_at) DESC",
        []
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Lädt alle aktiven Lieferanten sortiert nach Bestellhäufigkeit (meistverwendete zuerst)
 * Lieferanten ohne Bestellhistorie werden alphabetisch danach aufgefüllt
 *
 * @testdata {}
 */
/**
 * Lädt die Top-5 meistverwendeten Lieferanten für Ersatzteil-Bestellungen
 *
 * @testdata {}
 */
function getRecentVendors() {
    $db = DbhCompany::begin();

    $rows = $db->getAll(
        "SELECT v.id, v.name, COUNT(pr.id) AS order_count
         FROM oe_parts_requests_lxcars pr
         JOIN vendor v ON v.id = pr.vendor_id
         WHERE pr.status = 'ordered' AND NOT v.obsolete
         GROUP BY v.id, v.name
         ORDER BY COUNT(pr.id) DESC, v.name ASC
         LIMIT 10",
        []
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Sucht Lieferanten nach Name (für Lieferant-Picker im Warenkorb)
 *
 * @param string $data['q'] Suchbegriff (min. 2 Zeichen)
 * @testdata {"q": "Auto"}
 */
function searchVendors($data) {
    $db = DbhCompany::begin();
    $q = trim($data['q'] ?? '');

    if (strlen($q) < 2) {
        resultInfo(true, 'OK', []);
        return;
    }

    $rows = $db->getAll(
        "SELECT id, name
         FROM vendor
         WHERE NOT obsolete AND name ILIKE :q
         ORDER BY name ASC
         LIMIT 15",
        [':q' => '%' . $q . '%']
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Sucht Fahrzeuge für die "Auftrag fehlt"-Meldung im Mechanikermodus
 * anhand Halter-Name, Kennzeichen oder FIN.
 *
 * @param string $data['search'] Suchbegriff (Name, Kennzeichen oder FIN)
 * @testdata {"search": "MOL"}
 */
function searchCarsForMechanic($data) {
    $db = DbhCompany::begin();
    $search = trim($data['search'] ?? '');
    if (mb_strlen($search) < 2) {
        resultInfo(true, 'OK', []);
        return;
    }
    $like = '%' . $search . '%';
    $prefix = $search . '%';
    // Relevanz: Kennzeichen-Treffer vor Halter/FIN, Präfix vor Teiltreffer.
    $rows = $db->getAll(
        "SELECT c.c_id, c.c_ln, COALESCE(c.c_fin, '') AS c_fin,
                COALESCE(cu.name, '') AS owner_name,
                COALESCE(NULLIF(c.c_m, ''), k.hersteller, '') AS manufacturer,
                COALESCE(c.c_mt, '') AS model
         FROM cars_lxcars c
         LEFT JOIN customer cu ON cu.id = c.c_ow
         LEFT JOIN kba_lxcars k ON k.id = c.kba_id
         WHERE c.c_ln ILIKE :q OR c.c_fin ILIKE :q OR cu.name ILIKE :q
         ORDER BY
             CASE WHEN c.c_ln ILIKE :prefix THEN 0
                  WHEN c.c_ln ILIKE :q THEN 1
                  WHEN cu.name ILIKE :prefix THEN 2
                  ELSE 3 END,
             c.c_ln
         LIMIT 10",
        [':q' => $like, ':prefix' => $prefix]
    );
    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Meldet ein fehlendes Auftrags-Fahrzeug (erscheint in der Info-Bar).
 * Entweder ein erkanntes Fahrzeug (c_id + Kennzeichen) oder Freitext.
 *
 * @param int    $data['c_id']  Optional: Fahrzeug-ID
 * @param string $data['label'] Anzeigetext (Kennzeichen oder Freitext)
 * @testdata {"label": "B-XX 1234"}
 */
function addMissingOrder($data) {
    $db = DbhCompany::begin();
    $cId = isset($data['c_id']) && $data['c_id'] !== '' ? intval($data['c_id']) : null;
    $label = trim($data['label'] ?? '');
    if ($label === '') {
        resultInfo(false, 'MISSING_LABEL', 'Bezeichnung fehlt');
        return;
    }
    $empId = _getMechanicEmployeeId();
    $row = $db->getOne(
        "INSERT INTO missing_orders_lxcars (c_id, label, created_by)
         VALUES (:c_id, :label, :emp)
         RETURNING id, c_id, label, itime",
        [':c_id' => $cId, ':label' => $label, ':emp' => $empId]
    );
    resultInfo(true, 'OK', $row);
}

/**
 * Liefert die offenen "Auftrag fehlt"-Meldungen für die Info-Bar.
 *
 * @testdata {}
 */
function getMissingOrders() {
    $db = DbhCompany::begin();
    $rows = $db->getAll(
        "SELECT m.id, m.c_id, m.label, m.itime,
                car.c_ln AS vehicle_plate,
                COALESCE(cu.name, '') AS owner_name
         FROM missing_orders_lxcars m
         LEFT JOIN cars_lxcars car ON car.c_id = m.c_id
         LEFT JOIN customer cu ON cu.id = car.c_ow
         WHERE m.dismissed IS NOT TRUE
         ORDER BY m.itime DESC",
        []
    );
    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Markiert eine "Auftrag fehlt"-Meldung als erledigt (dismiss).
 *
 * @param int $data['id'] Meldungs-ID
 * @testdata {"id": 1}
 */
function dismissMissingOrder($data) {
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'MISSING_ID');
        return;
    }
    $db = DbhCompany::begin();
    $empId = _getMechanicEmployeeId();
    $db->execute(
        "UPDATE missing_orders_lxcars SET dismissed = true, dismissed_by = :emp WHERE id = :id",
        [':id' => $id, ':emp' => $empId]
    );
    resultInfo(true);
}
