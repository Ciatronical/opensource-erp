<?php
// backend/api/lxcars/cars.php

/**
 * Speichert ein neues Fahrzeug (INSERT)
 *
 * Erwartet: { action: 'saveCar', data: { c_ow, c_ln, ... } }
 * Gibt zurück: { success: true, payload: { c_id: <neue ID> } }
 */
function saveCar($data) {
    $db = DbhCompany::begin();
    $car = $data['data'];
    $kbaData = $data['kba'] ?? null;

    // Pflichtfelder prüfen
    if (empty($car['c_ow'])) {
        resultInfo(false, 'VALIDATION_ERROR: c_ow (Eigentümer) ist erforderlich');
        return;
    }
    if (empty($car['c_ln'])) {
        resultInfo(false, 'VALIDATION_ERROR: c_ln (Kennzeichen) ist erforderlich');
        return;
    }

    // D2 aus Car-Daten extrahieren (wird nicht in cars_lxcars gespeichert)
    $d2 = trim($car['c_d2'] ?? '');
    unset($car['c_d2']);

    // Felder die nicht vom Frontend gesetzt werden
    unset($car['c_id']);  // Auto-generated
    unset($car['c_it']);  // DEFAULT now()

    // Leere Strings bei Date-Feldern entfernen (PostgreSQL akzeptiert '' nicht als date)
    $dateFields = ['c_d', 'c_hu', 'c_zrd', 'c_bf', 'c_wd'];
    foreach ($dateFields as $df) {
        if (isset($car[$df]) && ($car[$df] === '' || $car[$df] === null)) {
            unset($car[$df]);
        }
    }

    $db->beginTransaction();

    try {
        // KBA-Daten verarbeiten (INSERT/UPDATE in kba_lxcars, kba_id zurück)
        $useSpecialKbaFallback = false;
        if (!empty($kbaData)) {
            $kbaId = prepareKba($kbaData);
            if ($kbaId) {
                $car['kba_id'] = $kbaId;
            } else {
                // prepareKba abgelehnt (ungültige/unbekannte HSN) → nach INSERT in special_kba_lxcars speichern
                $useSpecialKbaFallback = true;
            }
        } else {
            // KBA-Verknüpfung über HSN+TSN+D2 auflösen (manuelle Eingabe)
            $hsn = $car['c_2'] ?? '';
            $tsn = $car['c_3'] ?? '';
            $kbaId = resolveKbaWithD2($db, $hsn, $tsn, $d2);
            if ($kbaId) {
                $car['kba_id'] = $kbaId;
            }
        }

        // Felder und Werte aufteilen
        $fields = array_keys($car);
        $values = array_values($car);

        $db->insert('cars_lxcars', $fields, $values);
        $result = $db->getOne("SELECT currval('cars_lxcars_c_id_seq') AS c_id");
        $newCarId = intval($result['c_id']);

        // Fallback: KBA-Daten in special_kba_lxcars wenn prepareKba abgelehnt hat
        // (muss VOR commit stehen — gleiche Transaktion wie cars_lxcars-INSERT)
        if ($useSpecialKbaFallback) {
            upsertSpecialKba($db, $newCarId, $kbaData);
        }

        $db->commit();

        $car['c_id'] = $newCarId;

        // Pfade & Symlinks fuer das neue Fahrzeug sicherstellen:
        //   - fahrzeuge/{c_id}/ inkl. fahrzeugschein/ + Auto-Folder
        //   - fahrzeuge/0_by-plate/{c_ln} → ../{c_id}/fahrzeugschein
        //   - customers/{c_ow}/fahrzeuge/{c_ln} → ../../../fahrzeuge/{c_id}
        ensureVehiclePaths($car);

        // Kunden-Ordner + Name-Symlink sicherstellen (legt zusaetzlich
        // customers/0_by-name/{Name}_{cv_id} an und resynct alle Fahrzeug-Symlinks).
        $ownerId = intval($car['c_ow']);
        $ownerRow = $db->getOne("SELECT name FROM customer WHERE id = :id", [':id' => $ownerId]);
        if ($ownerRow) {
            ensureCustomerFolder($ownerId, 'C', trim($ownerRow['name']));
        }

        // KBA-Daten für Frontend-Anzeige laden
        $responseKba = null;
        if (!empty($car['kba_id'])) {
            $responseKba = $db->getOne(
                "SELECT * FROM kba_lxcars WHERE id = :id",
                [':id' => intval($car['kba_id'])]
            );
        }

        resultInfo(true, 'SAVED', ['c_id' => $newCarId, 'kba_id' => $car['kba_id'] ?? null, 'kba' => $responseKba]);
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('API_DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Aktualisiert ein bestehendes Fahrzeug (UPDATE)
 *
 * Erwartet: { action: 'updateCar', data: { c_id, c_ow, c_ln, ... } }
 * Gibt zurück: { success: true }
 */
function updateCar($data) {
    $db = DbhCompany::begin();
    $car = $data['data'];
    $kbaData = $data['kba'] ?? null;

    if (empty($car['c_id'])) {
        resultInfo(false, 'VALIDATION_ERROR: c_id ist erforderlich');
        return;
    }

    $carId = intval($car['c_id']);

    // D2 aus Car-Daten extrahieren (wird nicht in cars_lxcars gespeichert)
    $d2 = trim($car['c_d2'] ?? '');
    unset($car['c_d2']);

    $db->beginTransaction();
    try {
        // KBA-Daten verarbeiten: Special-KBA hat Vorrang, sonst normale kba_lxcars
        if (!empty($kbaData)) {
            $hasSpecialKba = $db->getOne(
                "SELECT id FROM special_kba_lxcars WHERE c_id = :c_id",
                [':c_id' => $carId]
            );

            if ($hasSpecialKba) {
                upsertSpecialKba($db, $carId, $kbaData);
            } else {
                $kbaId = prepareKba($kbaData);
                if ($kbaId) {
                    $car['kba_id'] = $kbaId;
                } else {
                    // prepareKba abgelehnt (ungültige/unbekannte HSN) → special_kba_lxcars
                    upsertSpecialKba($db, $carId, $kbaData);
                }
            }
        }

        // KBA-Verknüpfung über HSN+TSN+D2 auflösen (wenn nicht durch Scan gesetzt)
        if (empty($kbaData)) {
            $hsn = $car['c_2'] ?? '';
            $tsn = $car['c_3'] ?? '';
            $kbaId = resolveKbaWithD2($db, $hsn, $tsn, $d2);
            if ($kbaId) {
                $car['kba_id'] = $kbaId;
            }
        }

        // Schutz: Textfelder nur ueberschreiben wenn neuer Wert laenger oder bestehender leer
        $textFields = ['c_fin', 'c_st', 'c_wt', 'c_st_l', 'c_wt_l', 'c_mt', 'c_e_id', 'c_text', 'c_color', 'c_gart', 'c_st_z', 'c_wt_z'];
        $currentCar = $db->getOne("SELECT * FROM cars_lxcars WHERE c_id = :id", [':id' => $carId]);
        if ($currentCar) {
            foreach ($textFields as $tf) {
                if (!isset($car[$tf])) continue;
                $oldVal = trim($currentCar[$tf] ?? '');
                $newVal = trim($car[$tf] ?? '');
                // Kuerzeren oder leeren neuen Wert nicht uebernehmen (ausser Feld war vorher leer)
                if (!empty($oldVal) && mb_strlen($newVal) < mb_strlen($oldVal)) {
                    unset($car[$tf]);
                }
            }
        }

        // Felder die nicht aktualisiert werden sollen
        $db->updateRow(
            'cars_lxcars',
            $car,
            ['c_id', 'c_it'],           // Exclude: PK und Insert-Timestamp
            'c_id = :where_id',
            [':where_id' => $carId]
        );

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('API_DATABASE_ERROR', $e->getMessage());
    }

    // Kunden-Symlinks aktualisieren (Kennzeichen oder Eigentümer kann sich geaendert haben)
    // (nach commit — Dateisystem-Op, nicht transaktionsfähig)
    $ownerId = intval($car['c_ow'] ?? 0);
    if (!$ownerId) {
        $ownerRow = $db->getOne("SELECT c_ow FROM cars_lxcars WHERE c_id = :id", [':id' => $carId]);
        $ownerId = intval($ownerRow['c_ow'] ?? 0);
    }
    if ($ownerId) {
        $nameRow = $db->getOne("SELECT name FROM customer WHERE id = :id", [':id' => $ownerId]);
        if ($nameRow) {
            ensureCustomerFolder($ownerId, 'C', trim($nameRow['name']));
        }
    }

    // KBA-Daten zurückgeben wenn sich die Verknüpfung geändert hat
    $responseKba = null;
    if (!empty($car['kba_id'])) {
        $responseKba = $db->getOne(
            "SELECT * FROM kba_lxcars WHERE id = :id",
            [':id' => intval($car['kba_id'])]
        );
    }

    resultInfo(true, 'UPDATED', ['kba_id' => $car['kba_id'] ?? null, 'kba' => $responseKba]);
}

/**
 * Löscht ein Fahrzeug anhand der ID
 *
 * Löscht den Datensatz aus cars_lxcars. Verknüpfte Aufträge (oe_ext, ar_ext)
 * behalten ihren Eintrag, c_id wird per ON DELETE SET NULL genullt.
 * special_kba_lxcars wird per ON DELETE CASCADE mitgelöscht.
 * Der Fahrzeugschein-Ordner wird ebenfalls entfernt.
 *
 * @param int $data['id'] Fahrzeug-ID (c_id)
 * @testdata {"id": 9999}
 */
function deleteCar($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    // Kennzeichen + Eigentuemer vor dem DELETE holen — wird unten zum Aufraeumen
    // der Symlinks (0_by-plate und customers/{c_ow}/fahrzeuge/{plate}) gebraucht.
    $row = $db->getOne(
        "SELECT c_ln, c_ow FROM cars_lxcars WHERE c_id = :id",
        [':id' => $id]
    );

    if (!$row) {
        resultInfo(false, 'NOT_FOUND');
        return;
    }

    $db->execute(
        "DELETE FROM cars_lxcars WHERE c_id = :id",
        [':id' => $id]
    );

    $baseDir = fmDataDir() . '/fahrzeuge';

    // Fahrzeug-Ordner (inkl. Fahrzeugschein und ggf. Anbauteile-Fotos) aufräumen
    $carDir = $baseDir . '/' . $id;
    if (is_dir($carDir)) {
        deleteDirectoryRecursive($carDir);
    }

    // By-plate-Symlink entfernen
    $cLn = trim($row['c_ln'] ?? '');
    if ($cLn !== '') {
        $safePlate = preg_replace('/[^a-zA-Z0-9\-]/', '_', $cLn);
        $plateLink = $baseDir . '/0_by-plate/' . $safePlate;
        if (is_link($plateLink)) {
            unlink($plateLink);
        }

        // Reverse-Symlink im Kunden-Ordner entfernen
        $ownerId = intval($row['c_ow'] ?? 0);
        if ($ownerId > 0) {
            $customerLink = fmDataDir() . '/customers/' . $ownerId . '/fahrzeuge/' . $safePlate;
            if (is_link($customerLink)) {
                unlink($customerLink);
            }
        }
    }

    resultInfo(true, 'DELETED');
}

/**
 * Löscht ein Verzeichnis rekursiv
 *
 * @param string $dir Absoluter Pfad
 */
function deleteDirectoryRecursive($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            deleteDirectoryRecursive($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/**
 * Prüft ob ein Kennzeichen bereits vergeben ist
 *
 * Erwartet: { action: 'checkCarLicense', data: { c_ln: '...', c_id?: <aktuelle ID> } }
 * Gibt zurück: { success: true, payload: { exists: bool, owner_name: string } }
 * @testdata {"data": {"c_ln": "B-AB1234"}}
 */
function checkCarLicense($data) {
    $db = DbhCompany::begin();
    $car = $data['data'];

    if (empty($car['c_ln'])) {
        resultInfo(true, 'OK', ['exists' => false, 'owner_name' => '']);
        return;
    }

    $excludeId = isset($car['c_id']) ? intval($car['c_id']) : 0;

    $row = $db->getOne(
        "SELECT c.c_id, COALESCE(cu.name, '') AS owner_name
         FROM cars_lxcars c
         LEFT JOIN customer cu ON c.c_ow = cu.id
         WHERE UPPER(c.c_ln) = UPPER(:c_ln) AND c.c_id != :exclude_id
         LIMIT 1",
        [':c_ln' => $car['c_ln'], ':exclude_id' => $excludeId]
    );

    resultInfo(true, 'OK', [
        'exists'     => !empty($row),
        'owner_name' => $row ? $row['owner_name'] : '',
        'c_id'       => $row ? intval($row['c_id']) : null
    ]);
}

/**
 * Prüft mehrere Kennzeichen auf einmal ob sie bereits vergeben sind
 *
 * @param array $data['plates'] Array von Kennzeichen-Strings
 * @testdata {"plates": ["B-AB1234", "M-XY5678"]}
 */
function checkCarLicenseBatch($data) {
    $plates = $data['plates'] ?? [];
    if (!is_array($plates) || empty($plates)) {
        resultInfo(true, '', ['results' => new \stdClass()]);
        return;
    }

    $db = DbhCompany::begin();

    $rows = $db->getAll(
        "SELECT UPPER(c.c_ln) AS plate, c.c_id, c.c_ow AS customer_id, COALESCE(cu.name, '') AS owner_name
         FROM cars_lxcars c
         LEFT JOIN customer cu ON c.c_ow = cu.id
         WHERE UPPER(c.c_ln) = ANY(:plates)",
        [':plates' => '{' . implode(',', array_map(fn($p) => strtoupper(trim($p)), $plates)) . '}']
    );

    $results = [];
    foreach ($rows as $row) {
        $results[$row['plate']] = [
            'exists' => true,
            'c_id' => intval($row['c_id']),
            'customer_id' => intval($row['customer_id']),
            'owner_name' => $row['owner_name'],
        ];
    }

    resultInfo(true, '', ['results' => empty($results) ? new \stdClass() : $results]);
}

/**
 * Prüft ob eine FIN bereits vergeben ist
 *
 * Erwartet: { action: 'checkCarFin', data: { c_fin: '...', c_id?: <aktuelle ID> } }
 * Gibt zurück: { success: true, payload: { exists: bool, owner_name: string, c_id: int|null } }
 * @testdata {"data": {"c_fin": "WBA00000000000001"}}
 */
function checkCarFin($data) {
    $db = DbhCompany::begin();
    $car = $data['data'];

    if (empty($car['c_fin'])) {
        resultInfo(true, 'OK', ['exists' => false, 'owner_name' => '', 'c_id' => null]);
        return;
    }

    $excludeId = isset($car['c_id']) ? intval($car['c_id']) : 0;

    $row = $db->getOne(
        "SELECT c.c_id, COALESCE(cu.name, '') AS owner_name
         FROM cars_lxcars c
         LEFT JOIN customer cu ON c.c_ow = cu.id
         WHERE UPPER(c.c_fin) = UPPER(:c_fin) AND c.c_id != :exclude_id
         LIMIT 1",
        [':c_fin' => $car['c_fin'], ':exclude_id' => $excludeId]
    );

    resultInfo(true, 'OK', [
        'exists'     => !empty($row),
        'owner_name' => $row ? $row['owner_name'] : '',
        'c_id'       => $row ? intval($row['c_id']) : null
    ]);
}

/**
 * Lädt ein Fahrzeug anhand der ID
 *
 * Erwartet: { action: 'getCar', id: <c_id> }
 * Gibt zurück: { success: true, payload: { c_id, c_ow, c_ln, ... } }
 * @testdata {"id": 1}
 */
function getCar($data) {
    $db = DbhCompany::begin();
    $carId = intval($data['id']);

    $car = $db->getOne(
        "SELECT * FROM cars_lxcars WHERE c_id = :c_id",
        [':c_id' => $carId]
    );

    if (!$car) {
        resultInfo(false, 'DATA_NOT_FOUND');
        return;
    }

    // KBA-Stammdaten laden: zuerst special_kba_lxcars, dann Fallback auf kba_lxcars
    $kba = $db->getOne(
        "SELECT * FROM special_kba_lxcars WHERE c_id = :c_id",
        [':c_id' => $carId]
    );

    if ($kba) {
        $kba['is_special_kba'] = true;
    } elseif (!empty($car['kba_id'])) {
        $kba = $db->getOne(
            "SELECT * FROM kba_lxcars WHERE id = :id",
            [':id' => intval($car['kba_id'])]
        );
    }

    // Fehlende Stammdaten aus kba_lxcars nachfüllen (z.B. fhzart fehlt bei Scan-erstellten Datensätzen)
    if ($kba && empty($kba['fhzart'])) {
        $hsn = substr($car['c_2'] ?? '', 0, 4);
        $tsn = substr($car['c_3'] ?? '', 0, 3);
        if (strlen($hsn) === 4 && strlen($tsn) === 3) {
            $masterFields = ['fhzart', 'klasse', 'aufbau', 'antrieb', 'sitze', 'datum', 'achsen', 'masse', 'j'];
            $masterKba = $db->getOne(
                "SELECT " . implode(', ', $masterFields) . "
                 FROM kba_lxcars
                 WHERE hsn = :hsn AND tsn = :tsn AND fhzart IS NOT NULL AND fhzart != ''
                 ORDER BY id LIMIT 1",
                [':hsn' => $hsn, ':tsn' => $tsn]
            );
            if ($masterKba) {
                foreach ($masterFields as $field) {
                    if (empty($kba[$field]) && !empty($masterKba[$field])) {
                        $kba[$field] = $masterKba[$field];
                    }
                }
            }
        }
    }

    $car['kba'] = $kba;

    // Pfade & Symlinks fuer das Fahrzeug sicherstellen (idempotent):
    //   - fahrzeuge/{c_id}/ inkl. fahrzeugschein/ + Auto-Folder
    //   - fahrzeuge/0_by-plate/{c_ln} → ../{c_id}/fahrzeugschein
    //   - customers/{c_ow}/fahrzeuge/{c_ln} → ../../../fahrzeuge/{c_id}
    ensureVehiclePaths($car);

    resultInfo(true, 'OK', $car);
}

/**
 * Prüft den km-Stand eines Auftrags auf Plausibilität gegen alle früheren Aufträge/Rechnungen desselben Fahrzeugs.
 * Gibt den höchsten bisher erfassten km-Stand zurück (0 wenn kein Vorgänger vorhanden).
 *
 * @param int $data['oe_id'] Aktuelle Auftrags-ID (wird ausgeschlossen)
 * @param int $data['c_id']  Fahrzeug-ID
 * @testdata {"oe_id": 1, "c_id": 1}
 */
function checkKmStandPlausibility($data) {
    $db = DbhCompany::begin();
    $oeId  = intval($data['oe_id']);
    $carId = intval($data['c_id']);

    $row = $db->getOne(
        "SELECT GREATEST(
             COALESCE((SELECT MAX(e.km_stand) FROM oe_ext e
                       WHERE e.c_id = :c_id AND e.oe_id != :oe_id AND e.km_stand IS NOT NULL), 0),
             COALESCE((SELECT MAX(e.km_stand) FROM ar_ext e
                       WHERE e.c_id = :c_id2 AND e.km_stand IS NOT NULL), 0)
         ) AS last_km",
        [':c_id' => $carId, ':oe_id' => $oeId, ':c_id2' => $carId]
    );

    resultInfo(true, 'OK', ['last_km' => $row ? intval($row['last_km']) : 0]);
}

/**
 * Lädt die Aufträge eines Fahrzeugs via oe_ext
 *
 * @param int $data['id'] c_id des Fahrzeugs
 * @testdata {"id": 1}
 */
function getCarOrders($data) {
    $db = DbhCompany::begin();
    $carId = intval($data['id']);

    $orders = $db->getAll(
        "SELECT o.id, o.ordnumber, TO_CHAR(o.transdate, 'DD.MM.YYYY') AS transdate,
                o.amount,
                COALESCE(
                    (SELECT il.description FROM oe_instructions_lxcars il
                     WHERE il.oe_id = o.id ORDER BY il.sort_order, il.id LIMIT 1),
                    (SELECT oi.description FROM orderitems oi
                     WHERE oi.trans_id = o.id ORDER BY oi.position LIMIT 1)
                ) AS description
         FROM oe_ext e
         JOIN oe o ON o.id = e.oe_id
         WHERE e.c_id = :c_id
           AND o.record_type IN ('sales_order', 'sales_order_intake')
         ORDER BY o.transdate DESC
         LIMIT 20",
        [':c_id' => $carId]
    );

    resultInfo(true, 'OK', $orders ?: []);
}

/**
 * Lädt alle Fahrzeuge eines Kunden (für Kennzeichen-Auswahl im Auftrag)
 *
 * @param int $data['customer_id'] Kunden-ID
 * @testdata {"customer_id": 1}
 */
function getCustomerCars($data) {
    $db = DbhCompany::begin();
    $customerId = intval($data['customer_id']);

    $cars = $db->getAll(
        "SELECT c.c_id, c.c_ln, c.c_m, c.c_mt, c.c_2, c.c_3, c.c_fin, c.c_ktype, COALESCE(c.c_text, '') AS c_text,
                COALESCE(k.fhzart, '') AS fhzart
         FROM cars_lxcars c
         LEFT JOIN kba_lxcars k ON k.id = c.kba_id
         WHERE c.c_ow = :customer_id
         ORDER BY c.c_ln",
        [':customer_id' => $customerId]
    );

    resultInfo(true, 'OK', $cars ?: []);
}

/**
 * Lädt alle LxCars-Initialdaten für eine Faktura in einem einzigen Call.
 * Ersetzt getCustomerCars + getCarForOrder/getCarForInvoice + getPartsRequestsByOrder + getRecentVendors.
 *
 * @param int $data['faktura_id'] Dokument-ID
 * @param int $data['customer_id'] Kunden-ID
 * @param string $data['faktura_type'] 'order', 'quotation' oder 'invoice'
 * @testdata {"faktura_id": 1, "customer_id": 1, "faktura_type": "order"}
 */
function getLxCarsFakturaInit($data) {
    $db = DbhCompany::begin();
    $fakturaId   = intval($data['faktura_id']  ?? 0);
    $customerId  = intval($data['customer_id'] ?? 0);
    $fakturaType = $data['faktura_type'] ?? 'order';
    $isInvoice   = ($fakturaType === 'invoice');
    $isOrder     = ($fakturaType === 'order');

    // customer_id aus Dokument ableiten wenn nicht übergeben
    if (!$customerId && $fakturaId) {
        $mainTable = $isInvoice ? 'ar' : 'oe';
        $row = $db->getOne("SELECT customer_id FROM $mainTable WHERE id = :id", [':id' => $fakturaId]);
        $customerId = intval($row['customer_id'] ?? 0);
    }

    $customerCars = $customerId
        ? $db->getAll(
            "SELECT c.c_id, c.c_ln, c.c_m, c.c_mt, c.c_2, c.c_3, c.c_fin, c.c_ktype, COALESCE(c.c_text, '') AS c_text,
                    COALESCE(k.fhzart, '') AS fhzart
             FROM cars_lxcars c
             LEFT JOIN kba_lxcars k ON k.id = c.kba_id
             WHERE c.c_ow = :customer_id ORDER BY c.c_ln",
            [':customer_id' => $customerId]
        ) ?: [] : [];

    $carData = null;
    if ($fakturaId) {
        if ($isInvoice) {
            $carData = $db->getOne(
                "SELECT c.c_id, c.c_ln, COALESCE(c.c_text, '') AS c_text,
                        e.km_stand, e.fertigstellung, COALESCE(k.fhzart, '') AS fhzart
                 FROM ar_ext e
                 LEFT JOIN cars_lxcars c ON c.c_id = e.c_id
                 LEFT JOIN kba_lxcars k ON k.id = c.kba_id
                 WHERE e.ar_id = :id",
                [':id' => $fakturaId]
            );
        } else {
            $carData = $db->getOne(
                "SELECT c.c_id, c.c_ln, COALESCE(c.c_text, '') AS c_text,
                        e.km_stand, e.kfz_ort, e.status, e.gedruckt, e.intern,
                        e.bringetermin, e.fertigstellung, e.no_whatsapp,
                        c.c_sk, to_char(c.c_zrd, 'YYYY-MM-DD') AS c_zrd, c.c_zrk,
                        to_char(c.c_bf, 'YYYY-MM-DD') AS c_bf, to_char(c.c_wd, 'YYYY-MM-DD') AS c_wd,
                        COALESCE(k.fhzart, '') AS fhzart,
                        COALESCE((SELECT value FROM defaults_oserp WHERE key = 'lxcars_default_abgabezeit'), '08:00') AS _def_bring,
                        COALESCE((SELECT value FROM defaults_oserp WHERE key = 'lxcars_default_fertigstellungszeit'), '17:00') AS _def_fertig
                 FROM oe_ext e
                 LEFT JOIN cars_lxcars c ON c.c_id = e.c_id
                 LEFT JOIN kba_lxcars k ON k.id = c.kba_id
                 WHERE e.oe_id = :id",
                [':id' => $fakturaId]
            );
            // Fehlende Uhrzeiten ergänzen (Datum ohne Uhrzeit)
            if ($carData) {
                foreach (['bringetermin' => '_def_bring', 'fertigstellung' => '_def_fertig'] as $field => $defKey) {
                    if (!empty($carData[$field]) && !preg_match('/\d{2}:\d{2}/', $carData[$field])) {
                        $carData[$field] = $carData[$field] . ' ' . trim($carData[$defKey]) . ':00';
                    }
                }
                unset($carData['_def_bring'], $carData['_def_fertig']);
            }
        }
    }

    $partsRequests = [];
    $recentVendors = [];
    if ($isOrder && $fakturaId) {
        $partsRequests = $db->getAll(
            "SELECT pr.id, pr.orderitem_id, pr.status, pr.note, pr.photo,
                    pr.requested_by, pr.vendor_id, pr.requested_at, pr.ordered_at,
                    e.name AS requested_by_name, v.name AS vendor_name
             FROM oe_parts_requests_lxcars pr
             LEFT JOIN employee e ON e.id = pr.requested_by
             LEFT JOIN vendor v ON v.id = pr.vendor_id
             WHERE pr.oe_id = :oe_id",
            [':oe_id' => $fakturaId]
        ) ?: [];

        $recentVendors = $db->getAll(
            "SELECT v.id, v.name, COUNT(pr.id) AS order_count
             FROM oe_parts_requests_lxcars pr
             JOIN vendor v ON v.id = pr.vendor_id
             WHERE pr.status = 'ordered' AND NOT v.obsolete
             GROUP BY v.id, v.name
             ORDER BY COUNT(pr.id) DESC, v.name ASC
             LIMIT 10",
            []
        ) ?: [];
    }

    resultInfo(true, 'OK', [
        'customer_cars'  => $customerCars,
        'car_data'       => $carData,
        'parts_requests' => $partsRequests,
        'recent_vendors' => $recentVendors,
    ]);
}

/**
 * Lädt das verknüpfte Fahrzeug-Kennzeichen eines Auftrags
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @testdata {"oe_id": 1}
 */
function getCarForOrder($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id']);

    $row = $db->getOne(
        "SELECT c.c_id, c.c_ln, COALESCE(c.c_text, '') AS c_text,
                e.km_stand, e.kfz_ort, e.status, e.gedruckt, e.intern,
                e.bringetermin, e.fertigstellung, e.no_whatsapp,
                c.c_sk, to_char(c.c_zrd, 'YYYY-MM-DD') AS c_zrd, c.c_zrk,
                to_char(c.c_bf, 'YYYY-MM-DD') AS c_bf, to_char(c.c_wd, 'YYYY-MM-DD') AS c_wd,
                COALESCE(k.fhzart, '') AS fhzart
         FROM oe_ext e
         LEFT JOIN cars_lxcars c ON c.c_id = e.c_id
         LEFT JOIN kba_lxcars k ON k.id = c.kba_id
         WHERE e.oe_id = :oe_id",
        [':oe_id' => $oeId]
    );

    // Fehlende Uhrzeiten ergänzen: Wenn nur Datum (ohne Zeit) oder 00:00:00
    if ($row) {
        $timeDefaults = ['bringetermin' => 'lxcars_default_abgabezeit', 'fertigstellung' => 'lxcars_default_fertigstellungszeit'];
        $fallbacks = ['bringetermin' => '08:00', 'fertigstellung' => '17:00'];
        foreach ($timeDefaults as $field => $configKey) {
            if (!empty($row[$field]) && !preg_match('/\d{2}:\d{2}(:\d{2})?$/', $row[$field])) {
                $defRow = $db->getOne("SELECT value FROM defaults_oserp WHERE key = :key", [':key' => $configKey]);
                $defTime = $defRow ? trim($defRow['value']) : $fallbacks[$field];
                $row[$field] = $row[$field] . ' ' . $defTime . ':00';
            }
        }
    }

    resultInfo(true, 'OK', $row ?: ['c_id' => null, 'c_ln' => '', 'km_stand' => null, 'kfz_ort' => null, 'status' => null, 'gedruckt' => false, 'intern' => false, 'bringetermin' => null, 'fertigstellung' => null, 'no_whatsapp' => false, 'c_sk' => false, 'c_zrd' => null, 'c_zrk' => null, 'c_bf' => null, 'c_wd' => null, 'fhzart' => '']);
}

/**
 * Verknüpft einen Auftrag mit einem Fahrzeug via oe_ext (INSERT/UPDATE/DELETE)
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @param int|null $data['c_id'] Fahrzeug-ID (null = Verknüpfung lösen)
 * @testdata {"oe_id": 1, "c_id": 1}
 */
function linkCarToFaktura($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id']);
    $cId = !empty($data['c_id']) ? intval($data['c_id']) : null;

    if (!$cId) {
        // Verknüpfung lösen (oe_ext-Zeile bleibt erhalten, nur c_id wird NULL)
        $db->execute(
            "UPDATE oe_ext SET c_id = NULL WHERE oe_id = :oe_id",
            [':oe_id' => $oeId]
        );
        resultInfo(true, 'UNLINKED');
        return;
    }

    // UPSERT: INSERT oder UPDATE
    $db->execute(
        "INSERT INTO oe_ext (oe_id, c_id) VALUES (:oe_id, :c_id)
         ON CONFLICT (oe_id) DO UPDATE SET c_id = :c_id",
        [':oe_id' => $oeId, ':c_id' => $cId]
    );

    // Falls customer_id in oe noch NULL ist, den Fahrzeughalter (c_ow) übernehmen
    $db->execute(
        "UPDATE oe SET customer_id = (SELECT c_ow FROM cars_lxcars WHERE c_id = :c_id)
         WHERE id = :oe_id AND customer_id IS NULL
           AND (SELECT c_ow FROM cars_lxcars WHERE c_id = :c_id) IS NOT NULL",
        [':oe_id' => $oeId, ':c_id' => $cId]
    );

    resultInfo(true, 'LINKED');
}

/**
 * Aktualisiert oe_ext-Felder (km_stand, kfz_ort, gedruckt, intern, bringetermin, fertigstellung)
 * c_id wird hier NICHT angefasst — dafür ist linkCarToFaktura zuständig.
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @param array $data['data'] Key-Value-Paare der zu ändernden Felder
 */
function updateOeExt($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id']);
    $fields = $data['data'];

    // Whitelist: nur erlaubte oe_ext-Felder (Wartungsfelder gehen direkt ins Fahrzeug)
    $allowedOeExt = ['km_stand', 'kfz_ort', 'status', 'gedruckt', 'intern', 'bringetermin', 'fertigstellung', 'no_whatsapp'];

    $setClauses = [];
    $params = [':oe_id' => $oeId];

    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowedOeExt)) {
            continue;
        }

        $paramName = ':' . $key;

        if ($key === 'km_stand') {
            $value = $value !== null && $value !== '' ? intval($value) : null;
        } elseif ($key === 'gedruckt' || $key === 'intern' || $key === 'no_whatsapp') {
            $value = $value ? 't' : 'f';
        } elseif ($key === 'bringetermin' || $key === 'fertigstellung') {
            if (!empty($value)) {
                if (preg_match('/00:00:00$/', $value) || !preg_match('/\d{2}:\d{2}:\d{2}$/', $value)) {
                    $defKey = $key === 'bringetermin' ? 'lxcars_default_abgabezeit' : 'lxcars_default_fertigstellungszeit';
                    $defRow = $db->getOne("SELECT value FROM defaults_oserp WHERE key = :key", [':key' => $defKey]);
                    $defTime = $defRow ? trim($defRow['value']) : ($key === 'bringetermin' ? '08:00' : '17:00');
                    $value = substr($value, 0, 10) . ' ' . $defTime . ':00';
                }
            } else {
                $value = null;
            }
        }

        $setClauses[] = "$key = $paramName";
        $params[$paramName] = $value;
    }

    if (!empty($setClauses)) {
        $setString = implode(', ', $setClauses);
        $db->execute(
            "INSERT INTO oe_ext (oe_id) VALUES (:oe_id)
             ON CONFLICT (oe_id) DO NOTHING",
            [':oe_id' => $oeId]
        );
        $db->execute(
            "UPDATE oe_ext SET $setString WHERE oe_id = :oe_id",
            $params
        );
    }

    // Wartungsfelder direkt ins Fahrzeug schreiben (immer, unabhängig vom Auftragsdatum)
    $maintenanceMap = ['c_sk' => 'boolean', 'c_zrd' => 'date', 'c_zrk' => 'int', 'c_bf' => 'date', 'c_wd' => 'date'];
    $carSetClauses = [];
    $carParams = [':oe_id' => $oeId];

    foreach ($maintenanceMap as $mf => $type) {
        if (!array_key_exists($mf, $fields)) continue;
        $value = $fields[$mf];
        if ($type === 'int') {
            $value = $value !== null && $value !== '' ? intval($value) : null;
        } elseif ($type === 'boolean') {
            $value = $value ? 't' : 'f';
        } else {
            $value = !empty($value) ? $value : null;
        }
        $carSetClauses[] = "$mf = :$mf";
        $carParams[":$mf"] = $value;
    }

    if (!empty($carSetClauses)) {
        $carSetString = implode(', ', $carSetClauses);
        $db->execute(
            "UPDATE cars_lxcars SET $carSetString
             FROM oe_ext e
             WHERE cars_lxcars.c_id = e.c_id AND e.oe_id = :oe_id AND e.c_id IS NOT NULL",
            $carParams
        );
    }

    // km_stand in c_km spiegeln — nur wenn dies der neueste Auftrag zum Fahrzeug ist
    if (array_key_exists('km_stand', $fields)) {
        $db->execute(
            "UPDATE cars_lxcars c
             SET c_km = COALESCE(e.km_stand, c.c_km)
             FROM oe_ext e
             JOIN oe o ON o.id = e.oe_id
             WHERE e.oe_id = :oe_id
               AND e.c_id IS NOT NULL
               AND c.c_id = e.c_id
               AND NOT EXISTS (
                   SELECT 1 FROM oe_ext e2
                   JOIN oe o2 ON o2.id = e2.oe_id
                   WHERE e2.c_id = e.c_id AND o2.itime > o.itime
               )",
            [':oe_id' => $oeId]
        );
    }

    if (empty($setClauses) && empty($carSetClauses) && !array_key_exists('km_stand', $fields)) {
        resultInfo(true, 'NO_CHANGES');
        return;
    }

    // Kalender-Sync: Wenn bringetermin oder fertigstellung eine Uhrzeit enthalten,
    // automatisch Kalendereintrag erstellen/aktualisieren
    if (isset($fields['bringetermin']) || isset($fields['fertigstellung'])) {
        syncOrderToCalendar($db, $oeId);
    }

    resultInfo(true, 'UPDATED');
}

/**
 * Sendet WhatsApp-Terminbestaetigung fuer Bringetermin (debounced vom Frontend aufgerufen)
 *
 * Liest den aktuellen Bringetermin aus der DB und prueft no_whatsapp.
 * Wird vom Frontend erst nach 360s Inaktivitaet aufgerufen, damit bei
 * Datum+Uhrzeit-Eingabe nur EINE Nachricht rausgeht.
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @testdata {"oe_id": 1}
 */
function sendBringeterminWa($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id']);

    // writeLog("[sendBringeterminWa] oe_id=$oeId START");

    $ext = $db->getOne(
        "SELECT bringetermin, no_whatsapp FROM oe_ext WHERE oe_id = :oe_id",
        [':oe_id' => $oeId]
    );

    // writeLog("[sendBringeterminWa] oe_id=$oeId bringetermin='" . ($ext['bringetermin'] ?? 'NULL') . "' no_whatsapp='" . ($ext['no_whatsapp'] ?? 'NULL') . "'");

    if (!$ext || empty($ext['bringetermin'])) {
        // writeLog("[sendBringeterminWa] oe_id=$oeId ABORT: no bringetermin");
        resultInfo(true, 'NO_BRINGETERMIN');
        return;
    }

    if (!empty($ext['no_whatsapp']) && $ext['no_whatsapp'] !== 'f') {
        // writeLog("[sendBringeterminWa] oe_id=$oeId ABORT: whatsapp disabled");
        resultInfo(true, 'WA_DISABLED');
        return;
    }

    // writeLog("[sendBringeterminWa] oe_id=$oeId -> calling _sendAppointmentConfirmation");
    _sendAppointmentConfirmation($db, $oeId, $ext['bringetermin']);
    resultInfo(true, 'WA_SENT');
}

/**
 * Sendet automatisch eine WhatsApp-Terminbestaetigung wenn Bringetermin gesetzt wird
 */
function _sendAppointmentConfirmation($db, $oeId, $bringetermin) {
    try {
        require_once dirname(__DIR__).'/whatsapp/whatsapp.php';

        $config = _getWhatsAppConfig();
        $tplId = (int)($config['whatsapp_tpl_appointment_confirm'] ?? 0);
        if ($tplId <= 0) return;

        $template = $db->getOne(
            "SELECT id, name, body_text FROM whatsapp_templates WHERE id = :id AND status = 'approved'",
            [':id' => $tplId]
        );
        if (!$template) return;

        $countryCode = $config['whatsapp_country_code'] ?? '49';

        // Auftrags- und Kundendaten laden
        $order = $db->getOne(
            "SELECT o.ordnumber, c.id AS customer_id, c.name AS customer_name, c.greeting, c.phone
             FROM oe o
             JOIN customer c ON c.id = o.customer_id
             WHERE o.id = :oe_id",
            [':oe_id' => $oeId]
        );
        if (!$order || empty($order['phone'])) return;

        $phone = _normalizePhone($order['phone'], $countryCode);
        if (empty($phone)) return;

        $dt = new DateTime($bringetermin);
        $dateStr = $dt->format('d.m.Y');
        $timeStr = $dt->format('H:i');
        // Fallback: wenn keine Uhrzeit gesetzt (00:00), Default-Abgabezeit verwenden
        if ($timeStr === '00:00') {
            $defTime = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'lxcars_default_abgabezeit'", []);
            $timeStr = $defTime ? trim($defTime['value']) : '08:00';
        }

        // Anrede
        $salutation = trim(($order['greeting'] ?? '') . ' ' . ($order['customer_name'] ?? ''));

        // Template-Parameter: {{1}}=Anrede, {{2}}=Datum, {{3}}=Uhrzeit
        $parameters = [$salutation, $dateStr, $timeStr];

        _sendTemplateMessageInternal($phone, $template, $parameters, (int)$order['customer_id']);
    } catch (Exception $e) {
        error_log("WhatsApp Terminbestaetigung Fehler: " . $e->getMessage());
    }
}

/**
 * Synchronisiert Auftrags-Termine (bringetermin, fertigstellung) in den Kalender.
 * Erstellt/aktualisiert Kalendereinträge wenn eine Uhrzeit gesetzt ist,
 * löscht sie wenn das Datum/die Zeit entfernt wird.
 *
 * Kalendereinträge werden über order_id + title-Prefix identifiziert.
 */
function syncOrderToCalendar($db, $oeId) {
    // writeLog("[syncOrderToCalendar] oe_id=$oeId START");
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    $login = $db->getPDO()->quote($auth->getLogin());
    $emp = $db->getOne("SELECT id FROM employee WHERE login = $login", []);
    $employeeId = $emp ? intval($emp['id']) : 0;
    if ($employeeId === 0) { writeLog("[syncOrderToCalendar] oe_id=$oeId ABORT: employeeId=0"); return; }

    // Auftrags- und Kundendaten laden
    $order = $db->getOne(
        "SELECT o.id, o.customer_id,
                COALESCE(c.name, '') AS customer_name,
                e.bringetermin, e.fertigstellung,
                (SELECT il.description FROM oe_instructions_lxcars il
                 WHERE il.oe_id = o.id ORDER BY il.sort_order, il.id LIMIT 1) AS first_instruction
         FROM oe o
         LEFT JOIN customer c ON c.id = o.customer_id
         LEFT JOIN oe_ext e ON e.oe_id = o.id
         WHERE o.id = :oe_id",
        [':oe_id' => $oeId]
    );
    if (!$order) { writeLog("[syncOrderToCalendar] oe_id=$oeId ABORT: order not found"); return; }

    // writeLog("[syncOrderToCalendar] oe_id=$oeId DB bringetermin='" . ($order['bringetermin'] ?? 'NULL') . "' fertigstellung='" . ($order['fertigstellung'] ?? 'NULL') . "'");

    $pdo = $db->getPDO();

    // Für beide Terminarten synchronisieren
    $termTypes = [
        'bringetermin'   => ['color' => '#FF9800'],
        'fertigstellung' => ['color' => '#4CAF50']
    ];

    foreach ($termTypes as $field => $config) {
        $timestamp = $order[$field];

        // Bestehenden Kalendereintrag für diesen Auftrag + Typ suchen (via Farbe)
        $existing = $db->getOne(
            "SELECT id FROM calendar_events
             WHERE order_id = :oe_id AND color = :color",
            [':oe_id' => $oeId, ':color' => $config['color']]
        );

        // writeLog("[syncOrderToCalendar] oe_id=$oeId $field timestamp='$timestamp' existing=" . ($existing ? $existing['id'] : 'NONE'));

        if ($timestamp) {
            // Fehlende Uhrzeit ergänzen (nur Datum oder 00:00:00)
            if (!preg_match('/\d{2}:\d{2}(:\d{2})?$/', $timestamp)) {
                $defKey = $field === 'bringetermin' ? 'lxcars_default_abgabezeit' : 'lxcars_default_fertigstellungszeit';
                $defRow = $db->getOne("SELECT value FROM defaults_oserp WHERE key = :key", [':key' => $defKey]);
                $defTime = $defRow ? trim($defRow['value']) : ($field === 'bringetermin' ? '08:00' : '17:00');
                $timestamp = substr($timestamp, 0, 10) . ' ' . $defTime . ':00';
            }

            $title = $order['customer_name'];
            $qTitle = $pdo->quote($title);
            $qTs = $pdo->quote($timestamp);
            $qDesc = $order['first_instruction'] ? $pdo->quote($order['first_instruction']) : 'NULL';

            // Ende = Start + 1 Stunde
            $dtend = date('Y-m-d H:i:s', strtotime($timestamp) + 3600);
            $qDtend = $pdo->quote($dtend);
            $allDay = 'FALSE';

            if ($existing) {
                // Aktualisieren
                $db->execute(
                    "UPDATE calendar_events SET title = $qTitle, description = $qDesc,
                            dtstart = $qTs, dtend = $qDtend, \"allDay\" = $allDay,
                            color = :color, mtime = NOW()
                     WHERE id = :id",
                    [':color' => $config['color'], ':id' => intval($existing['id'])]
                );
            } else {
                // Neuen Eintrag erstellen (öffentlich sichtbar)
                $cvpId = $order['customer_id'] ? intval($order['customer_id']) : 'NULL';
                $cvpName = $order['customer_name'] ? $pdo->quote($order['customer_name']) : 'NULL';

                $db->execute(
                    "INSERT INTO calendar_events
                        (title, description, dtstart, dtend, \"allDay\", color, prio, visibility,
                         uid, cvp_id, cvp_name, cvp_type, order_id)
                     VALUES ($qTitle, $qDesc, $qTs, $qDtend, $allDay, :color, 1, -1,
                             :uid, $cvpId, $cvpName, 'C', :oe_id)",
                    [':color' => $config['color'], ':uid' => $employeeId, ':oe_id' => $oeId]
                );
            }
        } elseif ($existing) {
            // Datum wurde gelöscht → Kalendereintrag entfernen
            $db->execute(
                "DELETE FROM calendar_events WHERE id = :id",
                [':id' => intval($existing['id'])]
            );
        }
    }
}

/**
 * Lädt das verknüpfte Fahrzeug einer Rechnung via ar_ext
 *
 * @param int $data['ar_id'] Rechnungs-ID
 * @testdata {"ar_id": 1}
 */
function getCarForInvoice($data) {
    $db = DbhCompany::begin();
    $arId = intval($data['ar_id']);

    $row = $db->getOne(
        "SELECT c.c_id, c.c_ln, COALESCE(c.c_text, '') AS c_text,
                e.km_stand, e.fertigstellung,
                COALESCE(k.fhzart, '') AS fhzart
         FROM ar_ext e
         LEFT JOIN cars_lxcars c ON c.c_id = e.c_id
         LEFT JOIN kba_lxcars k ON k.id = c.kba_id
         WHERE e.ar_id = :ar_id",
        [':ar_id' => $arId]
    );

    resultInfo(true, 'OK', $row ?: ['c_id' => null, 'c_ln' => '', 'km_stand' => null, 'fertigstellung' => null, 'fhzart' => '']);
}

/**
 * Verknüpft eine Rechnung mit einem Fahrzeug via ar_ext (INSERT/UPDATE/DELETE)
 *
 * @param int $data['ar_id'] Rechnungs-ID
 * @param int|null $data['c_id'] Fahrzeug-ID (null = Verknüpfung lösen)
 * @testdata {"ar_id": 1, "c_id": 1}
 */
function linkCarToInvoice($data) {
    $db = DbhCompany::begin();
    $arId = intval($data['ar_id']);
    $cId = !empty($data['c_id']) ? intval($data['c_id']) : null;

    if (!$cId) {
        $db->execute(
            "UPDATE ar_ext SET c_id = NULL WHERE ar_id = :ar_id",
            [':ar_id' => $arId]
        );
        resultInfo(true, 'UNLINKED');
        return;
    }

    $db->execute(
        "INSERT INTO ar_ext (ar_id, c_id) VALUES (:ar_id, :c_id)
         ON CONFLICT (ar_id) DO UPDATE SET c_id = :c_id",
        [':ar_id' => $arId, ':c_id' => $cId]
    );

    resultInfo(true, 'LINKED');
}

/**
 * Aktualisiert ar_ext-Felder (km_stand)
 * c_id wird hier NICHT angefasst — dafür ist linkCarToInvoice zuständig.
 *
 * @param int $data['ar_id'] Rechnungs-ID
 * @param array $data['data'] Key-Value-Paare der zu ändernden Felder
 * @testdata {"ar_id": 1, "data": {"km_stand": 12345}}
 */
function updateArExt($data) {
    $db = DbhCompany::begin();
    $arId = intval($data['ar_id']);
    $fields = $data['data'];

    $allowed = ['km_stand', 'fertigstellung'];

    $setClauses = [];
    $params = [':ar_id' => $arId];

    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed)) {
            continue;
        }

        $paramName = ':' . $key;

        if ($key === 'km_stand') {
            $value = $value !== null && $value !== '' ? intval($value) : null;
        }
        if ($key === 'fertigstellung') {
            $value = !empty($value) ? $value : null;
        }

        $setClauses[] = "$key = $paramName";
        $params[$paramName] = $value;
    }

    if (empty($setClauses)) {
        resultInfo(true, 'NO_CHANGES');
        return;
    }

    $setString = implode(', ', $setClauses);

    $db->execute(
        "INSERT INTO ar_ext (ar_id) VALUES (:ar_id)
         ON CONFLICT (ar_id) DO NOTHING",
        [':ar_id' => $arId]
    );

    $db->execute(
        "UPDATE ar_ext SET $setString WHERE ar_id = :ar_id",
        $params
    );

    resultInfo(true, 'UPDATED');
}

/**
 * Lädt die letzten Fahrzeugschein-Scans von fahrzeugschein-scanner.de,
 * speichert neue Scans in fs_scans_lxcars und gibt die Daten paginiert aus der DB zurück.
 *
 * @param int $data['per_page'] Einträge pro Seite (1-50, default 20)
 * @param int $data['page'] Aktuelle Seite (1-basiert, default 1)
 * @testdata {"per_page": 5, "page": 1}
 */
function getScans($data) {
    $db = DbhCompany::begin();

    if (defined('DEMO_MODE') && DEMO_MODE) {
        // Demo: Scan aus CSV in DB seeden (idempotent)
        if (($data['page'] ?? 1) == 1) {
            _seedDemoScan($db);
        }
    } else {
        $row = $db->getOne(
            "SELECT value FROM defaults_oserp WHERE key = 'lxcarsapi'",
            []
        );

        if (!$row || empty($row['value'])) {
            resultInfo(false, 'NO_API_KEY', 'Kein API-Key konfiguriert (lxcarsapi)');
            return;
        }

        $apiKey = $row['value'];

        // Neue Scans von der API holen und in DB cachen (nur auf Seite 1)
        if (($data['page'] ?? 1) == 1) {
            syncScansFromApi($db, $apiKey, intval($data['per_page'] ?? 20));
        }
    }

    $perPage = intval($data['per_page'] ?? 20);
    if ($perPage < 1 || $perPage > 50) $perPage = 20;
    $page = max(1, intval($data['page'] ?? 1));

    // Scans paginiert aus der DB lesen (mit optionaler Volltextsuche)
    $offset = ($page - 1) * $perPage;
    $search = trim($data['search'] ?? '');

    $whereClause = '';
    $params = [':per_page' => $perPage, ':offset' => $offset];

    if ($search !== '') {
        $likeVal = '%' . mb_strtolower($search) . '%';
        $whereClause = "WHERE lower(coalesce(itime::text,'')) LIKE :q
            OR lower(coalesce(firstname,'')) LIKE :q
            OR lower(coalesce(name1,'')) LIKE :q
            OR lower(coalesce(registrationnumber,'')) LIKE :q
            OR lower(coalesce(d1,'')) LIKE :q
            OR lower(coalesce(d3,'')) LIKE :q
            OR lower(coalesce(maker,'')) LIKE :q
            OR lower(coalesce(model,'')) LIKE :q";
        $params[':q'] = $likeVal;
    }

    $countRow = $db->getOne("SELECT count(*) AS total FROM fs_scans_lxcars $whereClause", $search !== '' ? [':q' => $params[':q']] : []);
    $total = intval($countRow['total']);

    $rows = $db->getAll(
        "SELECT *
         FROM fs_scans_lxcars
         $whereClause
         ORDER BY itime DESC
         LIMIT :per_page OFFSET :offset",
        $params
    );

    resultInfo(true, '', [
        'results' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max(1, ceil($total / $perPage)),
    ]);
}

/**
 * Holt neue Scans von der externen API und speichert sie in fs_scans_lxcars
 *
 * @param object $db DB-Verbindung
 * @param string $apiKey API-Key für fahrzeugschein-scanner.de
 * @param int $take Anzahl der Scans die von der API abgerufen werden
 */
function syncScansFromApi($db, $apiKey, $take) {
    $listUrl = 'https://fahrzeugschein-scanner.de/api/Scans/GetScans/' . $apiKey . '/?take=' . $take;
    $listJson = file_get_contents($listUrl);

    if ($listJson === false) return;

    $scanList = json_decode($listJson, true);
    if (!is_array($scanList) || empty($scanList)) return;

    // Bereits vorhandene scan_ids aus der DB holen → nur neue abrufen
    $existingIds = [];
    $checkRows = $db->getAll(
        "SELECT scan_id FROM fs_scans_lxcars WHERE scan_id = ANY(:ids)",
        [':ids' => '{' . implode(',', array_map(fn($s) => $s['id'], $scanList)) . '}']
    );
    foreach ($checkRows as $r) {
        $existingIds[$r['scan_id']] = true;
    }

    // Nur neue Scans von der API abrufen
    $newScans = array_filter($scanList, fn($s) => !isset($existingIds[$s['id']]));
    if (empty($newScans)) return;

    // Details parallel mit curl_multi holen
    $multiHandle = curl_multi_init();
    $curlHandles = [];

    foreach ($newScans as $i => $scan) {
        $detailUrl = 'https://fahrzeugschein-scanner.de/api/Scans/ScanDetails/' . $apiKey . '/' . $scan['id'] . '/false';
        $ch = curl_init($detailUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$i] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);

    // Spalten-Mapping: API-Key (camelCase) → DB-Spalte (lowercase)
    $colMap = [
        'scan_detail_id' => 'scan_detail_id', 'scan_id' => 'scan_id',
        'ez' => 'ez', 'ez_string' => 'ez_string', 'hsn' => 'hsn', 'tsn' => 'tsn',
        'vsn' => 'vsn', 'field_2_2' => 'field_2_2', 'vin' => 'vin', 'd3' => 'd3',
        'registrationNumber' => 'registrationnumber',
        'name1' => 'name1', 'name2' => 'name2', 'firstname' => 'firstname',
        'address1' => 'address1', 'address2' => 'address2',
        'j' => 'j', 'field_4' => 'field_4', 'field_3' => 'field_3',
        'd1' => 'd1', 'd2_1' => 'd2_1', 'd2_2' => 'd2_2', 'd2_3' => 'd2_3', 'd2_4' => 'd2_4',
        'field_2' => 'field_2', 'field_5_1' => 'field_5_1', 'field_5_2' => 'field_5_2',
        'v9' => 'v9', 'field_14' => 'field_14', 'p3' => 'p3', 'field_10' => 'field_10',
        'field_14_1' => 'field_14_1', 'p1' => 'p1', 'l' => 'l', 'field_9' => 'field_9',
        'p2_p4' => 'p2_p4', 't' => 't',
        'field_18' => 'field_18', 'field_19' => 'field_19', 'field_20' => 'field_20',
        'g' => 'g', 'field_12' => 'field_12', 'field_13' => 'field_13',
        'q' => 'q', 'v7' => 'v7', 'f1' => 'f1', 'f2' => 'f2',
        'field_7_1' => 'field_7_1', 'field_7_2' => 'field_7_2', 'field_7_3' => 'field_7_3',
        'field_8_1' => 'field_8_1', 'field_8_2' => 'field_8_2', 'field_8_3' => 'field_8_3',
        'u1' => 'u1', 'u2' => 'u2', 'u3' => 'u3', 'o1' => 'o1', 'o2' => 'o2',
        's1' => 's1', 's2' => 's2',
        'field_15_1' => 'field_15_1', 'field_15_2' => 'field_15_2', 'field_15_3' => 'field_15_3',
        'r' => 'r', 'field_11' => 'field_11', 'k' => 'k',
        'field_6' => 'field_6', 'field_17' => 'field_17', 'field_16' => 'field_16',
        'field_21' => 'field_21', 'field_22' => 'field_22', 'hu' => 'hu',
        'creation_date' => 'creation_date', 'creation_city' => 'creation_city',
        'document_id' => 'document_id',
        'Maker' => 'maker', 'Model' => 'model',
        'PowerKw' => 'powerkw', 'PowerHpKw' => 'powerhpkw',
        'Ccm' => 'ccm', 'Fuel' => 'fuel', 'FuelCode' => 'fuelcode',
        'Filename' => 'filename',
    ];

    $dbCols = array_values($colMap);
    $dbCols[] = 'itime';
    $dbPlaceholders = array_map(fn($c) => ':' . $c, $dbCols);
    $insertSql = "INSERT INTO fs_scans_lxcars (" . implode(', ', $dbCols) . ") "
               . "VALUES (" . implode(', ', $dbPlaceholders) . ") "
               . "ON CONFLICT (scan_id) DO NOTHING";

    $apiKeys = array_keys($colMap);

    foreach ($newScans as $i => $scan) {
        $response = curl_multi_getcontent($curlHandles[$i]);
        curl_multi_remove_handle($multiHandle, $curlHandles[$i]);
        curl_close($curlHandles[$i]);

        $detail = json_decode($response, true);
        if (!$detail) continue;

        // Bild-Daten entfernen
        foreach (array_keys($detail) as $key) {
            if (strpos($key, 'img') !== false || strpos($key, 'Img') !== false) {
                unset($detail[$key]);
            }
        }

        $detail['scan_id'] = $scan['id'];

        $params = [];
        foreach ($apiKeys as $apiKey_) {
            $dbCol = $colMap[$apiKey_];
            $params[':' . $dbCol] = $detail[$apiKey_] ?? null;
        }
        $params[':itime'] = $scan['timestamp'];
        $db->execute($insertSql, $params);
    }

    curl_multi_close($multiHandle);
}

/**
 * Mappt Scan-Rohdaten auf Car-Felder (wird im Frontend und für getScanDetails verwendet)
 *
 * Erwartet: { action: 'mapScanData', data: { ... Scan-Rohdaten ... } }
 * Gibt zurück: { success: true, payload: { car: {...}, owner: {...} } }
 */
function mapScanData($data) {
    $scanData = $data['data'] ?? [];

    $mapped = mapScanToCarFields($scanData);
    resultInfo(true, 'OK', $mapped);
}

/**
 * Hilfsfunktion: Parst Scan-Datumsangaben in PostgreSQL-kompatibles Format
 * Formate: "03.26" → "2026-03-01", "03.2026" → "2026-03-01",
 *          "15.03.2020" → "2020-03-15", "03/26" → "2026-03-01"
 * Gibt null zurück wenn das Datum nicht geparst werden kann
 */
/**
 * Erkennt anhand der Scan-Rohdaten, ob das Fahrzeug der Personenbeförderung
 * dient (Taxi/Mietwagen/Krankenkraftwagen) — diese haben jährliche HU (12/12).
 * Quelle sind die Bemerkungen (Feld 22) sowie die Aufbau-/Klassenfelder.
 *
 * @param array $scanData Scan-Rohdaten
 * @return bool
 */
function detectPassengerTransport($scanData) {
    $haystack = mb_strtolower(trim(
        ($scanData['field_22'] ?? '') . ' ' .
        ($scanData['field_5_1'] ?? '') . ' ' .
        ($scanData['field_5_2'] ?? '') . ' ' .
        ($scanData['field_4'] ?? '')
    ));
    if ($haystack === '') return false;

    foreach (['taxi', 'mietwagen', 'personenbeförderung', 'personenbefoerderung',
              'krankenkraftwagen', 'krankenwagen'] as $needle) {
        if (mb_strpos($haystack, $needle) !== false) return true;
    }
    return false;
}

function parseScanDate($dateStr) {
    if (empty($dateStr)) return null;
    $dateStr = trim($dateStr);

    // Trennzeichen normalisieren: / → .
    $dateStr = str_replace('/', '.', $dateStr);

    $parts = explode('.', $dateStr);

    // MM.JJ (z.B. "03.26")
    if (count($parts) === 2 && strlen($parts[0]) <= 2 && strlen($parts[1]) === 2) {
        $month = intval($parts[0]);
        $year = 2000 + intval($parts[1]);
        if ($month >= 1 && $month <= 12) {
            return sprintf('%04d-%02d-01', $year, $month);
        }
    }

    // MM.JJJJ (z.B. "03.2026")
    if (count($parts) === 2 && strlen($parts[0]) <= 2 && strlen($parts[1]) === 4) {
        $month = intval($parts[0]);
        $year = intval($parts[1]);
        if ($month >= 1 && $month <= 12 && $year >= 1886) {
            return sprintf('%04d-%02d-01', $year, $month);
        }
    }

    // TT.MM.JJJJ (z.B. "15.03.2020")
    if (count($parts) === 3 && strlen($parts[2]) === 4) {
        $day = intval($parts[0]);
        $month = intval($parts[1]);
        $year = intval($parts[2]);
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // TT.MM.JJ (z.B. "15.03.20")
    if (count($parts) === 3 && strlen($parts[2]) === 2) {
        $day = intval($parts[0]);
        $month = intval($parts[1]);
        $year = 2000 + intval($parts[2]);
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // MMJJ (z.B. "0326" → 03/26)
    if (strlen($dateStr) === 4 && ctype_digit($dateStr)) {
        $month = intval(substr($dateStr, 0, 2));
        $year = 2000 + intval(substr($dateStr, 2, 2));
        if ($month >= 1 && $month <= 12) {
            return sprintf('%04d-%02d-01', $year, $month);
        }
    }

    return null;
}

/**
 * Hilfsfunktion: Mappt Scan-Rohdaten auf Car-Felder + KBA-Daten
 *
 * Gibt zurück: { car: {...}, kba: {...}, owner: {...} }
 * - car: Instanz-Felder für cars_lxcars (Kennzeichen, FIN, Datum, etc.)
 * - kba: Stammdaten für kba_lxcars (Hersteller, Modell, Hubraum, Leistung, etc.)
 * - owner: Halter-Information aus dem Scan
 */
function mapScanToCarFields($scanData) {
    // Kennzeichen formatieren: "MOL ID 100" → "MOL-ID100"
    $regNum = $scanData['registrationNumber'] ?? ($scanData['registration_number'] ?? '');
    $regParts = preg_split('/\s+/', trim($regNum));
    if (count($regParts) > 1) {
        $formatted = $regParts[0] . '-';
        for ($i = 1; $i < count($regParts); $i++) {
            $formatted .= $regParts[$i];
        }
        $regNum = str_replace('*', '', $formatted);
    }
    $regNum = strtoupper(trim($regNum));

    // TSN bereinigen (vollständig für car.c_3)
    $tsnFull = $scanData['field_2_2'] ?? ($scanData['tsn'] ?? '');
    $tsnFull = preg_replace('/[^a-zA-Z0-9]/', '', $tsnFull);

    // TSN erste 3 Zeichen für KBA-Lookup
    $tsnShort = mb_substr(strtoupper($tsnFull), 0, 3);

    // D2 zusammensetzen (Typschlüssel für KBA)
    $d2 = trim(
        ($scanData['d2_1'] ?? '') .
        ($scanData['d2_2'] ?? '') .
        ($scanData['d2_3'] ?? '') .
        ($scanData['d2_4'] ?? '')
    );

    // --- Car-Instanzdaten (cars_lxcars) ---
    $car = [
        'c_ln'     => mb_substr($regNum, 0, 10),
        'c_2'      => mb_substr($scanData['hsn'] ?? '', 0, 4),
        'c_3'      => mb_substr(strtoupper($tsnFull), 0, 10),
        'c_fin'    => mb_substr(strtoupper($scanData['vin'] ?? ''), 0, 30),
        'c_finchk' => mb_substr($scanData['field_3'] ?? '', 0, 1),
        'c_d'      => parseScanDate($scanData['ez'] ?? ''),
        'c_hu'     => parseScanDate($scanData['hu'] ?? ''),
        'c_em'     => mb_substr($scanData['field_14_1'] ?? ($scanData['field_14'] ?? ''), 0, 6),
        'c_pb'     => detectPassengerTransport($scanData),
    ];

    // --- KBA-Stammdaten (kba_lxcars) ---
    $kba = [
        'hsn'        => $scanData['hsn'] ?? '',
        'tsn'        => $tsnShort,
        'd2'         => $d2,
        'd1'         => $scanData['d1'] ?? '',
        'hersteller' => $scanData['d1'] ?? ($scanData['Maker'] ?? ($scanData['maker'] ?? '')),
        'marke'      => $scanData['Maker'] ?? ($scanData['maker'] ?? ($scanData['d1'] ?? '')),
        'name'       => $scanData['Model'] ?? ($scanData['model'] ?? ''),
        'd3'         => $scanData['d3'] ?? '',
        'hubraum'    => $scanData['ccm'] ?? '',
        'leistung'   => $scanData['powerkw'] ?? '',
        'kraftstoff' => $scanData['fuel'] ?? '',
        'fhzart'     => '',
        'field_14'   => $scanData['field_14'] ?? '',
        'field_14_1' => $scanData['field_14_1'] ?? '',
        'j'          => $scanData['j'] ?? '',
        'field_4'    => $scanData['field_4'] ?? '',
        'field_2'    => $scanData['field_2'] ?? '',
        'field_5'    => $scanData['field_5_1'] ?? ($scanData['field_5'] ?? ''),
        'v9'         => $scanData['v9'] ?? '',
        'p3'         => $scanData['p3'] ?? '',
        'field_10'   => $scanData['field_10'] ?? '',
        'p1'         => $scanData['p1'] ?? '',
        'l'          => $scanData['l'] ?? '',
        'field_9'    => $scanData['field_9'] ?? '',
        'p2_p4'      => $scanData['p2_p4'] ?? '',
        't'          => $scanData['t'] ?? '',
        'field_18'   => $scanData['field_18'] ?? '',
        'field_19'   => $scanData['field_19'] ?? '',
        'field_20'   => $scanData['field_20'] ?? '',
        'g'          => $scanData['g'] ?? '',
        'field_12'   => $scanData['field_12'] ?? '',
        'field_13'   => $scanData['field_13'] ?? '',
        'q'          => $scanData['q'] ?? '',
        'v7'         => $scanData['v7'] ?? '',
        'f1'         => $scanData['f1'] ?? '',
        'f2'         => $scanData['f2'] ?? '',
        'field_7_1'  => $scanData['field_7_1'] ?? '',
        'field_7_2'  => $scanData['field_7_2'] ?? '',
        'field_7_3'  => $scanData['field_7_3'] ?? '',
        'field_8_1'  => $scanData['field_8_1'] ?? '',
        'field_8_2'  => $scanData['field_8_2'] ?? '',
        'field_8_3'  => $scanData['field_8_3'] ?? '',
        'u1'         => $scanData['u1'] ?? '',
        'u2'         => $scanData['u2'] ?? '',
        'u3'         => $scanData['u3'] ?? '',
        'o1'         => $scanData['o1'] ?? '',
        'o2'         => $scanData['o2'] ?? '',
        's1'         => $scanData['s1'] ?? '',
        's2'         => $scanData['s2'] ?? '',
        'field_15_1' => $scanData['field_15_1'] ?? '',
        'field_15_2' => $scanData['field_15_2'] ?? '',
        'field_15_3' => $scanData['field_15_3'] ?? '',
        'k'          => $scanData['k'] ?? '',
        'field_6'    => $scanData['field_6'] ?? '',
        'field_17'   => $scanData['field_17'] ?? '',
        'field_21'   => $scanData['field_21'] ?? '',
    ];

    // Leere Strings entfernen
    $kba = array_filter($kba, function($v) { return $v !== '' && $v !== null; });
    // hsn und tsn immer behalten (auch wenn leer)
    if (!isset($kba['hsn'])) $kba['hsn'] = '';
    if (!isset($kba['tsn'])) $kba['tsn'] = '';

    // --- Halter-Information ---
    $owner = [
        'firstname' => $scanData['firstname'] ?? '',
        'name'      => $scanData['name1'] ?? ($scanData['name2'] ?? ''),
        'address1'  => $scanData['address1'] ?? '',
        'address2'  => $scanData['address2'] ?? '',
    ];

    return ['car' => $car, 'kba' => $kba, 'owner' => $owner];
}

/**
 * Fuzzy-KBA-Lookup: prüft ob HSN+TSN exakt in kba_lxcars existiert.
 * Falls nicht: Suche mit OCR-typischen Zeichensubstitutionen (B↔3, O↔0, etc.)
 *
 * @param string $data['hsn'] HSN (4 Zeichen)
 * @param string $data['tsn'] TSN (mind. 3 Zeichen)
 * @return array {exact: bool, suggestions: [{id, hsn, tsn, d2, hersteller, marke, name, fhzart, hubraum, leistung, kraftstoff}]}
 * @testdata {"hsn": "B333", "tsn": "BAU"}
 */
function lookupKbaFuzzy($data) {
    $hsn = strtoupper(trim($data['hsn'] ?? ''));
    $tsn = strtoupper(mb_substr(trim($data['tsn'] ?? ''), 0, 3));

    if (empty($hsn) || strlen($tsn) < 3) {
        resultInfo(true, 'OK', ['exact' => false, 'suggestions' => []]);
        return;
    }

    $db = DbhCompany::begin();

    // Felder die für KBA-Panel und Rotes Heft benötigt werden
    $kbaSelectFields = "id, hsn, tsn, d2, d1, hersteller, marke, name, fhzart,
                        klasse, aufbau, antrieb, sitze, datum, achsen, masse,
                        hubraum, leistung, kraftstoff, t, f2, field_7_1, field_7_2, field_7_3";

    // 1. Exakter Match — NUR wenn HSN gültig ist (genau 4 Ziffern).
    //    Ungültige HSN (Buchstaben wie "B333") überspringen Phase 1:
    //    Auch wenn ein Falscheintrag in der DB existiert, soll der Korrektur-Dialog erscheinen.

    if (preg_match('/^\d{4}$/', $hsn)) {
        $exact = $db->getAll(
            "SELECT $kbaSelectFields
             FROM kba_lxcars WHERE hsn = :hsn AND tsn = :tsn ORDER BY d2 NULLS FIRST LIMIT 5",
            [':hsn' => $hsn, ':tsn' => $tsn]
        );
        if ($exact) {
            resultInfo(true, 'OK', ['exact' => true, 'suggestions' => $exact]);
            return;
        }
    }

    // 2. Fuzzy via OCR-typische Zeichensubstitutionen:
    //    B→3, O→0, I→1, S→5, Z→2, D→0, G→6, Q→0, L→1
    //    translate() normalisiert beide Seiten — kein Injektionsrisiko da hardcoded
    $suggestions = $db->getAll(
        "SELECT DISTINCT ON (k.hsn, k.tsn) $kbaSelectFields
         FROM kba_lxcars k
         WHERE translate(upper(k.hsn), :ocr_f1, :ocr_t1) = translate(upper(:hsn_val), :ocr_f2, :ocr_t2)
           AND translate(upper(k.tsn), :ocr_f3, :ocr_t3) = translate(upper(:tsn_val), :ocr_f4, :ocr_t4)
         ORDER BY k.hsn, k.tsn, k.id
         LIMIT 8",
        [
            ':ocr_f1' => 'BOISZDGQL', ':ocr_t1' => '301520601',
            ':hsn_val' => $hsn,
            ':ocr_f2' => 'BOISZDGQL', ':ocr_t2' => '301520601',
            ':ocr_f3' => 'BOISZDGQL', ':ocr_t3' => '301520601',
            ':tsn_val' => $tsn,
            ':ocr_f4' => 'BOISZDGQL', ':ocr_t4' => '301520601',
        ]
    );

    resultInfo(true, 'OK', ['exact' => false, 'suggestions' => $suggestions ?: []]);
}

/**
 * Sucht KBA-Datensätze anhand von HSN + TSN (+ optional D2)
 *
 * Wenn D2 angegeben ist, wird zuerst ein exakter Treffer (HSN+TSN+D2) versucht.
 * Gibt es keinen D2-Treffer, wird ohne D2 gesucht (Fallback).
 *
 * Erwartet: { action: 'lookupKba', hsn: '0600', tsn: 'ABL', d2?: 'ABCD' }
 * Gibt zurück: { success: true, payload: [ { id, hsn, tsn, d2, hersteller, marke, name, ... } ] }
 * @testdata {"hsn": "0600", "tsn": "ABL"}
 */
function lookupKba($data) {
    $hsn = trim($data['hsn'] ?? '');
    $tsn = mb_substr(trim($data['tsn'] ?? ''), 0, 3);
    $d2  = trim($data['d2'] ?? '');

    if (strlen($hsn) !== 4 || strlen($tsn) < 3) {
        resultInfo(true, 'OK', []);
        return;
    }

    $db = DbhCompany::begin();

    // Mit D2: zuerst exakten Treffer versuchen
    if ($d2 !== '') {
        $rows = $db->getAll(
            "SELECT * FROM kba_lxcars WHERE hsn = :hsn AND tsn = :tsn AND d2 = :d2 ORDER BY name",
            [':hsn' => $hsn, ':tsn' => $tsn, ':d2' => $d2]
        );
        if ($rows) {
            resultInfo(true, 'OK', $rows);
            return;
        }
    }

    // Ohne D2 oder kein D2-Treffer: alle HSN+TSN-Treffer zurückgeben
    $rows = $db->getAll(
        "SELECT * FROM kba_lxcars WHERE hsn = :hsn AND tsn = :tsn ORDER BY name",
        [':hsn' => $hsn, ':tsn' => $tsn]
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Sucht KBA-Datensätze nur anhand der HSN (alle TSN-Varianten)
 *
 * Wird verwendet wenn die TSN unvollständig ist (z.B. "000").
 * Gibt eine kompakte Liste mit den wichtigsten Feldern zurück.
 *
 * @param string $data['hsn'] 4-stellige HSN
 * @testdata {"hsn": "0600"}
 */
function lookupKbaByHsn($data) {
    $hsn = trim($data['hsn'] ?? '');

    if (strlen($hsn) !== 4) {
        resultInfo(true, 'OK', []);
        return;
    }

    $db = DbhCompany::begin();
    $rows = $db->getAll(
        "SELECT id, hsn, tsn, d2, d1, hersteller, marke, name, fhzart,
                klasse, aufbau, antrieb, sitze, datum, achsen, masse,
                hubraum, leistung, kraftstoff, t, f2, field_7_1, field_7_2, field_7_3
         FROM kba_lxcars
         WHERE hsn = :hsn
         ORDER BY marke, name, tsn",
        [':hsn' => $hsn]
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Speichert Scan-KBA-Daten in special_kba_lxcars (fahrzeugspezifisch)
 *
 * Wird verwendet wenn keine Standard-KBA-Zuordnung möglich ist
 * (z.B. TSN mit Nullen). Die Daten werden über c_id mit dem Fahrzeug verknüpft.
 *
 * @param int    $data['c_id'] Fahrzeug-ID
 * @param array  $data['data'] KBA-Felder aus dem Scan
 * @testdata {"c_id": 1, "data": {"hsn": "0600", "tsn": "000", "hersteller": "VW", "marke": "VW"}}
 */
/**
 * Schreibt KBA-Daten in special_kba_lxcars (DELETE + INSERT)
 *
 * @param object $db DB-Verbindung
 * @param int $carId Fahrzeug-ID
 * @param array $kbaData KBA-Felder
 */
function upsertSpecialKba($db, $carId, $kbaData) {
    // Bestehende Daten laden
    $existing = $db->getOne(
        "SELECT * FROM special_kba_lxcars WHERE c_id = :c_id",
        [':c_id' => $carId]
    );

    // Leere/null-Werte aus neuen Daten entfernen
    $kbaData = array_filter($kbaData, function($v) {
        return $v !== '' && $v !== null;
    });

    if ($existing) {
        // UPDATE: Nur Felder ueberschreiben, deren neuer Wert laenger ist oder bestehender leer
        $updateData = [];
        foreach ($kbaData as $key => $value) {
            if ($key === 'c_id' || $key === 'id') continue;
            $currentValue = $existing[$key] ?? '';
            if (empty($currentValue) || mb_strlen((string)$value) > mb_strlen((string)$currentValue)) {
                $updateData[$key] = $value;
            }
        }
        if (!empty($updateData)) {
            $setClauses = [];
            $params = [':where_id' => $carId];
            foreach ($updateData as $key => $value) {
                $setClauses[] = "$key = :$key";
                $params[":$key"] = $value;
            }
            $db->execute(
                "UPDATE special_kba_lxcars SET " . implode(', ', $setClauses) . " WHERE c_id = :where_id",
                $params
            );
        }
    } else {
        // INSERT: Neuer Datensatz
        $kbaData['c_id'] = $carId;
        if (!isset($kbaData['hsn'])) $kbaData['hsn'] = '';
        if (!isset($kbaData['tsn'])) $kbaData['tsn'] = '';
        if (!isset($kbaData['hersteller'])) $kbaData['hersteller'] = '';
        if (!isset($kbaData['marke'])) $kbaData['marke'] = '';

        $fields = array_keys($kbaData);
        $values = array_values($kbaData);
        $db->insert('special_kba_lxcars', $fields, $values);
    }
}

function saveSpecialKba($data) {
    $carId = intval($data['c_id'] ?? 0);
    $kbaData = $data['data'] ?? [];

    if (!$carId) {
        resultInfo(false, 'VALIDATION_ERROR', 'c_id fehlt');
        return;
    }
    if (empty($kbaData)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Keine KBA-Daten');
        return;
    }

    $db = DbhCompany::begin();
    upsertSpecialKba($db, $carId, $kbaData);

    resultInfo(true, 'SAVED');
}

/**
 * KBA-Verknüpfung über HSN+TSN+D2 auflösen (manuelle Eingabe, kein Scan).
 *
 * Fall 1: Genau ein Eintrag mit HSN+TSN, d2 ist NULL → d2 setzen, verknüpfen
 * Fall 2: Exakter Treffer HSN+TSN+D2 vorhanden → verknüpfen (kein neuer Eintrag)
 * Fall 3: Einträge mit HSN+TSN existieren, aber d2 stimmt nicht überein
 *         → neue Variante anlegen (Klon eines bestehenden Eintrags mit neuem d2)
 *
 * @param object $db  Datenbank-Handle
 * @param string $hsn HSN (4-stellig)
 * @param string $tsn TSN (mind. 3 Zeichen)
 * @param string $d2  Typschlüssel D.2 aus dem Eingabefeld
 * @return int|null kba_id oder null
 */
function resolveKbaWithD2($db, $hsn, $tsn, $d2) {
    $hsn = trim($hsn);
    $tsn = mb_substr(trim($tsn), 0, 3);
    $d2  = trim($d2);

    if (!preg_match('/^\d{4}$/', $hsn) || strlen($tsn) < 3) return null;

    // Fall 2: Exakter Treffer (HSN+TSN+D2) — auch wenn D2 leer ist
    if ($d2 === '') {
        $row = $db->getOne(
            "SELECT id FROM kba_lxcars WHERE hsn = :hsn AND tsn = :tsn AND (d2 IS NULL OR d2 = '')",
            [':hsn' => $hsn, ':tsn' => $tsn]
        );
    } else {
        $row = $db->getOne(
            "SELECT id FROM kba_lxcars WHERE hsn = :hsn AND tsn = :tsn AND d2 = :d2",
            [':hsn' => $hsn, ':tsn' => $tsn, ':d2' => $d2]
        );
    }
    if ($row) return intval($row['id']);

    // D2 leer → keine weiteren Fälle (kein Update, kein Klon)
    if ($d2 === '') return null;

    // Fall 1: Genau ein Eintrag mit HSN+TSN, dessen d2 NULL ist → d2 setzen
    $entries = $db->getAll(
        "SELECT id, d2 FROM kba_lxcars WHERE hsn = :hsn AND tsn = :tsn",
        [':hsn' => $hsn, ':tsn' => $tsn]
    );

    if (count($entries) === 1 && ($entries[0]['d2'] === null || $entries[0]['d2'] === '')) {
        $kbaId = intval($entries[0]['id']);
        $db->execute(
            "UPDATE kba_lxcars SET d2 = :d2 WHERE id = :id",
            [':d2' => $d2, ':id' => $kbaId]
        );
        return $kbaId;
    }

    // Fall 3: Einträge existieren aber kein D2-Treffer → neue Variante klonen
    if (!empty($entries)) {
        $template = $db->getOne(
            "SELECT * FROM kba_lxcars WHERE hsn = :hsn AND tsn = :tsn ORDER BY id LIMIT 1",
            [':hsn' => $hsn, ':tsn' => $tsn]
        );

        unset($template['id']);
        $template['d2'] = $d2;

        $fields = array_keys($template);
        $values = array_values($template);
        $db->insert('kba_lxcars', $fields, $values);
        $result = $db->getOne("SELECT currval('kba_lxcars_id_seq') AS id");
        return intval($result['id']);
    }

    return null;
}

/**
 * KBA-Datensatz anlegen oder aktualisieren (Scan-Daten).
 * Lookup über HSN + TSN (erste 3 Zeichen) + D2.
 *
 * Logik (wie im alten Projekt):
 * 1. Exakter Match (hsn + tsn + d2) → UPDATE
 * 2. Match ohne d2 (hsn + tsn, d2 IS NULL) → UPDATE (d2 setzen)
 * 3. Kein Match → INSERT
 *
 * @param array $kbaData KBA-Felder aus dem Scan
 * @return int|null kba_id oder null wenn keine Daten
 */
function prepareKba($kbaData) {
    if (empty($kbaData) || (empty($kbaData['hsn']) && empty($kbaData['tsn']))) {
        return null;
    }

    $db = DbhCompany::begin();

    $hsn = $kbaData['hsn'] ?? '';
    $tsn = mb_substr($kbaData['tsn'] ?? '', 0, 3);
    $d2  = $kbaData['d2'] ?? '';

    // HSN muss genau 4 Ziffern haben — kba_lxcars ist Stammdaten, keine Buchstaben erlaubt
    if (!preg_match('/^\d{4}$/', $hsn)) {
        return null;
    }

    // Sicherstellen dass tsn im KBA-Datensatz nur 3 Zeichen hat
    $kbaData['tsn'] = $tsn;

    // Leere Strings und NULL-Werte entfernen (unleserliche Scan-Felder sollen
    // vorhandene Daten nicht ueberschreiben)
    $kbaData = array_filter($kbaData, function($v) { return $v !== '' && $v !== null; });
    // hsn und tsn immer behalten (werden fuer Lookup gebraucht)
    if (!isset($kbaData['hsn'])) $kbaData['hsn'] = $hsn;
    if (!isset($kbaData['tsn'])) $kbaData['tsn'] = $tsn;

    // 1. Exakter Match (hsn + tsn + d2)
    $existing = $db->getOne(
        "SELECT id FROM kba_lxcars WHERE hsn = :hsn AND tsn = :tsn AND d2 = :d2",
        [':hsn' => $hsn, ':tsn' => $tsn, ':d2' => $d2]
    );

    // Stammdaten-Felder (hsn bis fhzart) duerfen bei UPDATE nicht ueberschrieben werden.
    // Nur die variablen Felder ab d3 werden aktualisiert.
    $protectedFields = ['hsn', 'tsn', 'd2', 'd1', 'hersteller', 'marke', 'name', 'hubraum', 'leistung', 'kraftstoff', 'fhzart'];

    if ($existing) {
        // UPDATE bestehenden Datensatz — nur variable Felder (ab d3)
        $kbaId = intval($existing['id']);
        // Aktuellen Datensatz laden fuer Laengenvergleich
        $currentRecord = $db->getOne(
            "SELECT * FROM kba_lxcars WHERE id = :id",
            [':id' => $kbaId]
        );
        $updateData = array_diff_key($kbaData, array_flip($protectedFields));
        // Nur Felder uebernehmen, deren neuer Wert LAENGER ist als der vorhandene
        $filteredUpdate = [];
        foreach ($updateData as $key => $value) {
            $currentValue = $currentRecord[$key] ?? '';
            if (mb_strlen((string)$value) > mb_strlen((string)$currentValue)) {
                $filteredUpdate[$key] = $value;
            }
        }
        if (!empty($filteredUpdate)) {
            $setClauses = [];
            $params = [':where_id' => $kbaId];
            foreach ($filteredUpdate as $key => $value) {
                $setClauses[] = "$key = :$key";
                $params[":$key"] = $value;
            }
            $db->execute(
                "UPDATE kba_lxcars SET " . implode(', ', $setClauses) . " WHERE id = :where_id",
                $params
            );
        }
        return $kbaId;
    }

    // 2. Match ohne d2 (d2 IS NULL oder leer)
    if (!empty($d2)) {
        $existingNull = $db->getOne(
            "SELECT id FROM kba_lxcars WHERE hsn = :hsn AND tsn = :tsn AND (d2 IS NULL OR d2 = '')",
            [':hsn' => $hsn, ':tsn' => $tsn]
        );

        if ($existingNull) {
            // Nur variable Felder (ab d3) + d2 setzen — Stammdaten bleiben
            $kbaId = intval($existingNull['id']);
            // Aktuellen Datensatz laden fuer Laengenvergleich
            $currentRecord = $db->getOne(
                "SELECT * FROM kba_lxcars WHERE id = :id",
                [':id' => $kbaId]
            );
            $updateData = array_diff_key($kbaData, array_flip($protectedFields));
            // Nur Felder uebernehmen, deren neuer Wert LAENGER ist als der vorhandene
            $filteredUpdate = [];
            foreach ($updateData as $key => $value) {
                $currentValue = $currentRecord[$key] ?? '';
                if (mb_strlen((string)$value) > mb_strlen((string)$currentValue)) {
                    $filteredUpdate[$key] = $value;
                }
            }
            $filteredUpdate['d2'] = $d2; // d2 wird hier erstmalig gesetzt
            if (!empty($filteredUpdate)) {
                $setClauses = [];
                $params = [':where_id' => $kbaId];
                foreach ($filteredUpdate as $key => $value) {
                    $setClauses[] = "$key = :$key";
                    $params[":$key"] = $value;
                }
                $db->execute(
                    "UPDATE kba_lxcars SET " . implode(', ', $setClauses) . " WHERE id = :where_id",
                    $params
                );
            }
            return $kbaId;
        }
    }

    // 3. kba_lxcars ist Stammdaten — neue HSN dürfen nicht per Scan angelegt werden.
    // Nur bekannte HSN mit einem neuen D2 bekommen eine Klonvariante.
    $template = $db->getOne(
        "SELECT * FROM kba_lxcars WHERE hsn = :hsn ORDER BY id LIMIT 1",
        [':hsn' => $hsn]
    );
    if (!$template) {
        // Unbekannte HSN → kein Eintrag anlegen, Aufrufer fällt auf special_kba_lxcars zurück
        return null;
    }

    // Bekannte HSN aber neue TSN+D2-Kombination: Klon aus Template (Stammdaten übernehmen)
    $masterFields = ['fhzart', 'klasse', 'aufbau', 'antrieb', 'sitze', 'datum', 'achsen', 'masse', 'j'];
    foreach ($masterFields as $field) {
        if (!isset($kbaData[$field]) && !empty($template[$field])) {
            $kbaData[$field] = $template[$field];
        }
    }
    $fields = array_keys($kbaData);
    $values = array_values($kbaData);
    $db->insert('kba_lxcars', $fields, $values);
    $result = $db->getOne("SELECT currval('kba_lxcars_id_seq') AS id");
    return intval($result['id']);
}

/**
 * Scannt einen Fahrzeugschein über die fahrzeugschein-scanner.de API (Upload)
 *
 * Erwartet: { action: 'scanFahrzeugschein', image: '<base64>', is_pdf: false }
 * Gibt zurück: { success: true, payload: { car: {...}, owner: {...}, raw: {...} } }
 */
function scanFahrzeugschein($data) {
    $db = DbhCompany::begin();

    // Demo-Modus: Scan aus CSV simulieren
    if (defined('DEMO_MODE') && DEMO_MODE) {
        _scanFahrzeugscheinDemo($db, $data);
        return;
    }

    // API-Key aus defaults_oserp lesen
    $row = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'lxcarsapi'",
        []
    );

    if (!$row || empty($row['value'])) {
        resultInfo(false, 'NO_API_KEY', 'Kein API-Key für den Fahrzeugschein-Scanner konfiguriert (lxcarsapi)');
        return;
    }

    $apiKey = $row['value'];
    $image = $data['image'] ?? '';
    $isPdf = !empty($data['is_pdf']);

    if (empty($image)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Kein Bild übergeben');
        return;
    }

    // API-Request an fahrzeugschein-scanner.de
    $requestBody = json_encode([
        'image' => $image,
        'is_pdf' => $isPdf
    ]);

    $ch = curl_init('https://api.fahrzeugschein-scanner.de/generic-json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'access_key: ' . $apiKey
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        resultInfo(false, 'SCAN_ERROR', 'cURL-Fehler: ' . $curlError);
        return;
    }

    if ($httpCode !== 200) {
        resultInfo(false, 'SCAN_API_ERROR', 'API-Fehler (HTTP ' . $httpCode . '): ' . $response);
        return;
    }

    $result = json_decode($response, true);
    if (!$result || !isset($result['data'])) {
        resultInfo(false, 'SCAN_PARSE_ERROR', 'Ungültige API-Antwort');
        return;
    }

    $scanData = $result['data'];

    // Falls document_img auf Top-Level liegt (nicht unter data), in scanData übernehmen
    if (!isset($scanData['document_img']) && isset($result['document_img'])) {
        $scanData['document_img'] = $result['document_img'];
    }

    // Bild-Felder extrahieren (Ausschnitte der einzelnen Felder)
    $imageFields = [];
    foreach ($scanData as $key => $val) {
        if ((strpos($key, 'img') !== false || strpos($key, 'Img') !== false) && !empty($val)) {
            $imageFields[$key] = $val;
            unset($scanData[$key]);
        }
    }

    // Original-Bild als Temp-Datei speichern (für späteres Verknüpfen mit c_id)
    $tempId = null;
    $tempDir = fmDataDir() . '/fahrzeuge/0_temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    $decoded = base64_decode($image);
    if ($decoded !== false) {
        $tempId = bin2hex(random_bytes(16));
        $ext = $isPdf ? 'pdf' : 'jpg';
        file_put_contents($tempDir . '/' . $tempId . '.' . $ext, $decoded);
    }

    // Gemeinsame Mapping-Funktion nutzen
    $mapped = mapScanToCarFields($scanData);

    // Scan-Rohdaten in fs_scans_lxcars persistieren (gleiche Daten wie bei Listensync)
    _persistScanData($db, $scanData, $result);

    resultInfo(true, 'OK', [
        'car'   => $mapped['car'],
        'kba'   => $mapped['kba'],
        'owner' => $mapped['owner'],
        'raw'   => $scanData,
        'images' => $imageFields,
        'country_code' => $result['country_code'] ?? 'de',
        'temp_image_id' => $tempId
    ]);
}

/**
 * Speichert Scan-Rohdaten in fs_scans_lxcars (wird von scanFahrzeugschein aufgerufen).
 * Verwendet das gleiche Spalten-Mapping wie _syncNewScansFromApi.
 */
function _persistScanData($db, $scanData, $fullResult) {
    // Spalten-Mapping: API-Key → DB-Spalte (identisch mit _syncNewScansFromApi)
    $colMap = [
        'scan_detail_id' => 'scan_detail_id', 'scan_id' => 'scan_id',
        'ez' => 'ez', 'ez_string' => 'ez_string', 'hsn' => 'hsn', 'tsn' => 'tsn',
        'vsn' => 'vsn', 'field_2_2' => 'field_2_2', 'vin' => 'vin', 'd3' => 'd3',
        'registrationNumber' => 'registrationnumber',
        'name1' => 'name1', 'name2' => 'name2', 'firstname' => 'firstname',
        'address1' => 'address1', 'address2' => 'address2',
        'j' => 'j', 'field_4' => 'field_4', 'field_3' => 'field_3',
        'd1' => 'd1', 'd2_1' => 'd2_1', 'd2_2' => 'd2_2', 'd2_3' => 'd2_3', 'd2_4' => 'd2_4',
        'field_2' => 'field_2', 'field_5_1' => 'field_5_1', 'field_5_2' => 'field_5_2',
        'v9' => 'v9', 'field_14' => 'field_14', 'p3' => 'p3', 'field_10' => 'field_10',
        'field_14_1' => 'field_14_1', 'p1' => 'p1', 'l' => 'l', 'field_9' => 'field_9',
        'p2_p4' => 'p2_p4', 't' => 't',
        'field_18' => 'field_18', 'field_19' => 'field_19', 'field_20' => 'field_20',
        'g' => 'g', 'field_12' => 'field_12', 'field_13' => 'field_13',
        'q' => 'q', 'v7' => 'v7', 'f1' => 'f1', 'f2' => 'f2',
        'field_7_1' => 'field_7_1', 'field_7_2' => 'field_7_2', 'field_7_3' => 'field_7_3',
        'field_8_1' => 'field_8_1', 'field_8_2' => 'field_8_2', 'field_8_3' => 'field_8_3',
        'u1' => 'u1', 'u2' => 'u2', 'u3' => 'u3', 'o1' => 'o1', 'o2' => 'o2',
        's1' => 's1', 's2' => 's2',
        'field_15_1' => 'field_15_1', 'field_15_2' => 'field_15_2', 'field_15_3' => 'field_15_3',
        'r' => 'r', 'field_11' => 'field_11', 'k' => 'k',
        'field_6' => 'field_6', 'field_17' => 'field_17', 'field_16' => 'field_16',
        'field_21' => 'field_21', 'field_22' => 'field_22', 'hu' => 'hu',
        'creation_date' => 'creation_date', 'creation_city' => 'creation_city',
        'document_id' => 'document_id',
        'Maker' => 'maker', 'Model' => 'model',
        'PowerKw' => 'powerkw', 'PowerHpKw' => 'powerhpkw',
        'Ccm' => 'ccm', 'Fuel' => 'fuel', 'FuelCode' => 'fuelcode',
        'Filename' => 'filename',
    ];

    // Datenquelle: scanData (bereits bereinigt um Bilder) + fullResult (fuer Top-Level-Felder)
    $merged = array_merge($scanData, $fullResult['data'] ?? []);

    // scan_id generieren falls nicht vorhanden (generic-json-API liefert keine scan_id)
    if (empty($merged['scan_id'])) {
        $merged['scan_id'] = 'upload_' . bin2hex(random_bytes(8));
    }
    if (empty($merged['scan_detail_id'])) {
        $merged['scan_detail_id'] = 'upload_' . bin2hex(random_bytes(8));
    }

    $dbCols = array_values($colMap);
    $dbCols[] = 'itime';
    $dbPlaceholders = array_map(fn($c) => ':' . $c, $dbCols);
    $insertSql = "INSERT INTO fs_scans_lxcars (" . implode(', ', $dbCols) . ") "
               . "VALUES (" . implode(', ', $dbPlaceholders) . ") "
               . "ON CONFLICT (scan_id) DO NOTHING";

    $params = [':itime' => date('Y-m-d H:i:s')];
    foreach ($colMap as $apiKey => $dbCol) {
        $params[':' . $dbCol] = isset($merged[$apiKey]) ? (string)$merged[$apiKey] : null;
    }

    try {
        $db->execute($insertSql, $params);
    } catch (\Exception $e) {
        // Nicht-kritisch: Scan-Daten sind nice-to-have, kein Abbruch
        error_log('_persistScanData: ' . $e->getMessage());
    }
}

/**
 * Lädt den Demo-Scan aus der CSV-Datei.
 * Gibt ein assoziatives Array zurück (CSV-Spaltenname => Wert) oder null bei Fehler.
 */
function _loadDemoScanFromCsv() {
    $csvPath = __DIR__ . '/../demo/data/demo_fs_scan.csv';
    if (!is_file($csvPath)) return null;

    $handle = fopen($csvPath, 'r');
    if (!$handle) return null;

    $headers = fgetcsv($handle);
    $row = fgetcsv($handle);
    fclose($handle);

    if (!$headers || !$row) return null;

    return array_combine($headers, $row);
}

/**
 * Seeded den Demo-Scan aus der CSV in fs_scans_lxcars (idempotent via ON CONFLICT).
 */
function _seedDemoScan($db) {
    $csvData = _loadDemoScanFromCsv();
    if (!$csvData) return;

    $dbCols = [
        'itime', 'scan_detail_id', 'scan_id', 'ez', 'ez_string', 'hsn', 'tsn',
        'vsn', 'field_2_2', 'vin', 'd3', 'registrationnumber', 'name1', 'name2',
        'firstname', 'address1', 'address2', 'j', 'field_4', 'field_3',
        'd1', 'd2_1', 'd2_2', 'd2_3', 'd2_4', 'field_2', 'field_5_1', 'field_5_2',
        'v9', 'field_14', 'p3', 'field_10', 'field_14_1', 'p1', 'l', 'field_9',
        'p2_p4', 't', 'field_18', 'field_19', 'field_20', 'g', 'field_12', 'field_13',
        'q', 'v7', 'f1', 'f2', 'field_7_1', 'field_7_2', 'field_7_3',
        'field_8_1', 'field_8_2', 'field_8_3', 'u1', 'u2', 'u3', 'o1', 'o2',
        's1', 's2', 'field_15_1', 'field_15_2', 'field_15_3', 'r', 'field_11',
        'k', 'field_6', 'field_17', 'field_16', 'field_21', 'field_22', 'hu',
        'creation_date', 'creation_city', 'document_id',
        'maker', 'model', 'powerkw', 'powerhpkw', 'ccm', 'fuel', 'fuelcode', 'filename',
    ];

    $placeholders = array_map(fn($c) => ':' . $c, $dbCols);
    $insertSql = "INSERT INTO fs_scans_lxcars (" . implode(', ', $dbCols) . ") "
               . "VALUES (" . implode(', ', $placeholders) . ") "
               . "ON CONFLICT (scan_id) DO NOTHING";

    $params = [];
    foreach ($dbCols as $col) {
        $val = $csvData[$col] ?? null;
        $params[':' . $col] = ($val !== '' ? $val : null);
    }

    try {
        $db->execute($insertSql, $params);
    } catch (\Exception $e) {
        error_log('_seedDemoScan: ' . $e->getMessage());
    }
}

/**
 * Demo-Modus: Simuliert einen Fahrzeugschein-Scan mit den Demo-Daten.
 * Gibt das gleiche Ergebnis-Format wie der echte Scan zurück.
 */
function _scanFahrzeugscheinDemo($db, $data) {
    $csvData = _loadDemoScanFromCsv();
    if (!$csvData) {
        resultInfo(false, 'DEMO_ERROR', 'Demo-Daten nicht gefunden');
        return;
    }

    $image = $data['image'] ?? '';
    $isPdf = !empty($data['is_pdf']);

    // Original-Bild als Temp-Datei speichern (normaler Flow)
    $tempId = null;
    if (!empty($image)) {
        $tempDir = fmDataDir() . '/fahrzeuge/0_temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $decoded = base64_decode($image);
        if ($decoded !== false) {
            $tempId = bin2hex(random_bytes(16));
            $ext = $isPdf ? 'pdf' : 'jpg';
            file_put_contents($tempDir . '/' . $tempId . '.' . $ext, $decoded);
        }
    }

    // CSV-Daten als scanData aufbereiten (Spalten-Mapping für mapScanToCarFields)
    $scanData = $csvData;
    $scanData['registrationNumber'] = $csvData['registrationnumber'] ?? '';
    $scanData['Maker'] = $csvData['maker'] ?? '';
    $scanData['Model'] = $csvData['model'] ?? '';
    $scanData['PowerKw'] = $csvData['powerkw'] ?? '';
    $scanData['PowerHpKw'] = $csvData['powerhpkw'] ?? '';
    $scanData['Ccm'] = $csvData['ccm'] ?? '';
    $scanData['Fuel'] = $csvData['fuel'] ?? '';
    $scanData['FuelCode'] = $csvData['fuelcode'] ?? '';
    $scanData['Filename'] = $csvData['filename'] ?? '';

    // Crop-Bilder aus Demo-Daten laden
    $demoScanId = $csvData['scan_id'];
    $demoDir = __DIR__ . '/../demo/data/' . $demoScanId;
    $imageFields = [];

    $cropDir = $demoDir . '/.crops';
    if (is_dir($cropDir)) {
        foreach (glob($cropDir . '/crop_*.jpg') as $cropFile) {
            // crop_hsn.jpg → hsn_img
            $fieldName = preg_replace('/^crop_(.+)\.jpg$/', '$1', basename($cropFile));
            $imageFields[$fieldName . '_img'] = base64_encode(file_get_contents($cropFile));
        }
    }

    // Original-Dokument-Bild
    $originalPath = $demoDir . '/original.jpg';
    if (is_file($originalPath)) {
        $imageFields['document_img'] = base64_encode(file_get_contents($originalPath));
    }

    // Mapping auf Car-Felder
    $mapped = mapScanToCarFields($scanData);

    // In DB persistieren (neue scan_id für jeden Upload, damit mehrfach scannen möglich ist)
    $scanData['scan_id'] = 'demo_' . bin2hex(random_bytes(8));
    $scanData['scan_detail_id'] = 'demo_' . bin2hex(random_bytes(8));
    _persistScanData($db, $scanData, ['data' => $scanData]);

    // Bilder in tmp cachen (für späteres Lazy-Loading via getScanTempCrop)
    cacheScanToTmp($scanData['scan_id'], $imageFields);

    resultInfo(true, 'OK', [
        'car'   => $mapped['car'],
        'kba'   => $mapped['kba'],
        'owner' => $mapped['owner'],
        'raw'   => $scanData,
        'images' => $imageFields,
        'country_code' => 'de',
        'temp_image_id' => $tempId
    ]);
}

/**
 * Demo-Modus: Kopiert Demo-Bilder in den tmp-Cache für eine Scan-ID.
 * Sucht immer im originalen Demo-Scan-Ordner.
 */
function _cacheDemoScanImages($scanId) {
    $safeScanId = preg_replace('/[^a-zA-Z0-9\-_]/', '', $scanId);
    if (empty($safeScanId)) return;

    $tmpDir = __DIR__ . '/../../tmp/' . $safeScanId;
    $tmpCropDir = $tmpDir . '/.crops';

    // Bereits gecacht?
    if (is_dir($tmpCropDir) && count(glob($tmpCropDir . '/crop_*.jpg')) > 0) return;

    // Demo-Quelldaten: immer aus dem originalen Demo-Scan-Ordner
    $csvData = _loadDemoScanFromCsv();
    $demoScanId = $csvData['scan_id'] ?? '';
    $demoDir = __DIR__ . '/../demo/data/' . $demoScanId;
    if (!is_dir($demoDir)) return;

    if (!is_dir($tmpDir)) mkdir($tmpDir, 0775, true);
    if (!is_dir($tmpCropDir)) mkdir($tmpCropDir, 0775, true);

    // Original kopieren
    $srcOriginal = $demoDir . '/original.jpg';
    if (is_file($srcOriginal)) {
        copy($srcOriginal, $tmpDir . '/original.jpg');
    }

    // Crops kopieren
    $srcCropDir = $demoDir . '/.crops';
    if (is_dir($srcCropDir)) {
        foreach (glob($srcCropDir . '/crop_*.jpg') as $cropFile) {
            copy($cropFile, $tmpCropDir . '/' . basename($cropFile));
        }
    }
}

/**
 * Holt Detail-Daten eines Scans (nur Textdaten).
 * Bilder werden in backend/tmp/{scan_id}/ gecacht und über getScanTempCrop geladen.
 *
 * Erwartet: { action: 'getScanDetail', scan_id: '...' }
 * Gibt zurück: { success: true, payload: { car, owner, kba, raw } }
 * @testdata {"scan_id": "1"}
 */
function getScanDetail($data) {
    $db = DbhCompany::begin();
    $scanId = trim($data['scan_id'] ?? '');

    if (empty($scanId)) {
        resultInfo(false, 'VALIDATION_ERROR', 'scan_id ist erforderlich');
        return;
    }

    // Textdaten aus DB (nach Sync immer vorhanden)
    $row = $db->getOne(
        "SELECT * FROM fs_scans_lxcars WHERE scan_id = :scan_id",
        [':scan_id' => $scanId]
    );

    // Prüfen ob Bilder schon im tmp-Cache liegen
    $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
    $tmpCropDir = __DIR__ . '/../../tmp/' . $safeScanId . '/.crops';
    $hasCachedImages = is_dir($tmpCropDir) && count(glob($tmpCropDir . '/crop_*.jpg')) > 0;

    // DB-Zeile vorhanden + Bilder gecacht → schneller Pfad
    if ($row && $hasCachedImages) {
        $mapped = mapScanToCarFields($row);
        resultInfo(true, 'OK', [
            'car'   => $mapped['car'],
            'kba'   => $mapped['kba'],
            'owner' => $mapped['owner'],
            'raw'   => $row
        ]);
        return;
    }

    // Demo-Modus: Bilder aus Demo-Daten cachen statt API
    if (defined('DEMO_MODE') && DEMO_MODE) {
        _cacheDemoScanImages($scanId);

        $source = $row ?: _loadDemoScanFromCsv();
        if ($source) {
            $mapped = mapScanToCarFields($source);
            resultInfo(true, 'OK', [
                'car'   => $mapped['car'],
                'kba'   => $mapped['kba'],
                'owner' => $mapped['owner'],
                'raw'   => $source
            ]);
            return;
        }
        resultInfo(false, 'NOT_FOUND', 'Demo-Scan nicht gefunden');
        return;
    }

    // API aufrufen (mit Bildern zum Cachen)
    $apiRow = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'lxcarsapi'",
        []
    );

    if (!$apiRow || empty($apiRow['value'])) {
        if ($row) {
            $mapped = mapScanToCarFields($row);
            resultInfo(true, 'OK', [
                'car'   => $mapped['car'],
                'kba'   => $mapped['kba'],
                'owner' => $mapped['owner'],
                'raw'   => $row
            ]);
            return;
        }
        resultInfo(false, 'NO_API_KEY', 'Kein API-Key konfiguriert (lxcarsapi)');
        return;
    }

    $apiKey = $apiRow['value'];
    $detailUrl = 'https://fahrzeugschein-scanner.de/api/Scans/ScanDetails/' . $apiKey . '/' . $scanId . '/true';
    $detailJson = file_get_contents($detailUrl);

    if ($detailJson === false) {
        resultInfo(false, 'SCAN_API_ERROR', 'Fehler beim Abrufen der Scan-Details');
        return;
    }

    $detail = json_decode($detailJson, true);
    if (!$detail) {
        resultInfo(false, 'SCAN_PARSE_ERROR', 'Ungültige API-Antwort');
        return;
    }

    // Bild-Felder extrahieren und in tmp cachen
    $imageFields = [];
    foreach ($detail as $key => $val) {
        if ((strpos($key, 'img') !== false || strpos($key, 'Img') !== false) && !empty($val)) {
            $imageFields[$key] = $val;
            unset($detail[$key]);
        }
    }

    // Original-Dokument holen
    if (empty($imageFields['document_img'])) {
        $documentUrl = 'https://fahrzeugschein-scanner.de/api/Scans/Document/' . $apiKey . '/' . $scanId;
        $imgData = @file_get_contents($documentUrl);
        if ($imgData !== false && strlen($imgData) > 100) {
            $imageFields['document_img'] = base64_encode($imgData);
        }
    }

    // In tmp cachen
    cacheScanToTmp($scanId, $imageFields);

    $source = $row ?: $detail;
    $mapped = mapScanToCarFields($source);

    resultInfo(true, 'OK', [
        'car'   => $mapped['car'],
        'kba'   => $mapped['kba'],
        'owner' => $mapped['owner'],
        'raw'   => $source
    ]);
}

/**
 * Findet automatisch einen Kunden anhand Name (similarity) + exakter Adresse
 *
 * @param string $data['name'] Haltername aus dem Scan
 * @param string $data['street'] Straße
 * @param string $data['zipcode'] PLZ
 * @param string $data['city'] Ort
 * @testdata {"name": "Max Mustermann", "street": "Musterstr. 1", "zipcode": "12345", "city": "Berlin"}
 */
function autoMatchCustomerForScan($data) {
    $db = DbhCompany::begin();
    $name    = trim($data['name'] ?? '');
    $street  = trim($data['street'] ?? '');
    $zipcode = trim($data['zipcode'] ?? '');
    $city    = trim($data['city'] ?? '');

    if ($name === '' || $zipcode === '') {
        resultInfo(true, 'OK', null);
        return;
    }

    $customer = $db->getOne(
        "SELECT id, name, street, zipcode, city, greeting, phone, email, business_id
         FROM customer
         WHERE similarity(LOWER(name), LOWER(:name)) > 0.35
           AND LOWER(zipcode) = LOWER(:zipcode)
           AND LOWER(street) = LOWER(:street)
           AND LOWER(city) = LOWER(:city)
         ORDER BY similarity(LOWER(name), LOWER(:name)) DESC
         LIMIT 1",
        [':name' => $name, ':street' => $street, ':zipcode' => $zipcode, ':city' => $city]
    );

    resultInfo(true, 'OK', $customer ?: null);
}

/**
 * Sucht Kunden anhand des Namens (für Fahrzeugschein-Scan Zuordnung)
 *
 * Erwartet: { action: 'searchCustomerForScan', name: '...' }
 * Gibt zurück: { success: true, payload: [ { id, name, street, zipcode, city } ] }
 * @testdata {"name": "Test"}
 */
function searchCustomerForScan($data) {
    $db = DbhCompany::begin();
    $name = trim($data['name'] ?? '');

    if (empty($name)) {
        resultInfo(true, 'OK', []);
        return;
    }

    $customers = $db->getAll(
        "SELECT id, name, street, zipcode, city, greeting, phone, email, business_id
         FROM customer
         WHERE name ILIKE :name
         ORDER BY name
         LIMIT 10",
        [':name' => '%' . $name . '%']
    );

    resultInfo(true, 'OK', $customers ?: []);
}

/**
 * Sucht Fahrzeuge anhand des Kennzeichens (fuer Fahrzeugschein-Scan Zuordnung)
 *
 * Erwartet: { action: 'searchCarForScan', c_ln: '...' }
 * Gibt zurueck: { success: true, payload: [ { c_id, c_ln, owner_name, hersteller, modell } ] }
 * @testdata {"c_ln": "B-AB"}
 */
function searchCarForScan($data) {
    $db = DbhCompany::begin();
    $cln = trim($data['c_ln'] ?? '');

    if (empty($cln)) {
        resultInfo(true, 'OK', []);
        return;
    }

    $cars = $db->getAll(
        "SELECT c.c_id, c.c_ln, COALESCE(cu.name, '') AS owner_name,
                COALESCE(k.hersteller, '') AS hersteller, COALESCE(k.name, '') AS modell
         FROM cars_lxcars c
         LEFT JOIN customer cu ON c.c_ow = cu.id
         LEFT JOIN kba_lxcars k ON c.kba_id = k.id
         WHERE UPPER(c.c_ln) LIKE UPPER(:cln) || '%'
         ORDER BY c.c_ln
         LIMIT 10",
        [':cln' => $cln]
    );

    resultInfo(true, 'OK', $cars ?: []);
}

/**
 * Prüft ob ein Kunde mit gleichem oder ähnlichem Namen + Adresse bereits existiert.
 *
 * Erwartet: { action: 'checkDuplicateCustomer', name: '...', street: '...', zipcode: '...' }
 * Gibt zurück: {
 *   exact: [ { id, name, street, zipcode, city } ],   // Exakt gleicher Name + Adresse
 *   partial: [ { id, name, street, zipcode, city } ]   // Gleicher Nachname + Adresse (anderer Vorname)
 * }
 * @testdata {"action": "checkDuplicateCustomer", "name": "Max Mustermann", "street": "Musterstr. 1", "zipcode": "12345"}
 */
function checkDuplicateCustomer($data) {
    $db = DbhCompany::begin();
    $name = trim($data['name'] ?? '');
    $street = trim($data['street'] ?? '');
    $zipcode = trim($data['zipcode'] ?? '');

    $exact = [];
    $partial = [];

    if ($name === '') {
        resultInfo(true, 'OK', ['exact' => [], 'partial' => []]);
        return;
    }

    // 1) Exakt-Treffer: Name + Straße + PLZ identisch
    if ($street !== '' && $zipcode !== '') {
        $exact = $db->getAll(
            "SELECT id, name, street, zipcode, city FROM customer
             WHERE LOWER(name) = LOWER(:name)
               AND LOWER(street) = LOWER(:street)
               AND LOWER(zipcode) = LOWER(:zipcode)
             LIMIT 5",
            [':name' => $name, ':street' => $street, ':zipcode' => $zipcode]
        ) ?: [];
    }

    // 2) Teilname-Treffer: Letztes Wort (Nachname) + Adresse identisch, anderer Vorname
    $nameParts = preg_split('/\s+/', $name);
    if (count($nameParts) >= 2 && $street !== '' && $zipcode !== '') {
        $lastName = end($nameParts);
        $partial = $db->getAll(
            "SELECT id, name, street, zipcode, city FROM customer
             WHERE LOWER(name) LIKE '%' || LOWER(:lastname)
               AND LOWER(name) != LOWER(:fullname)
               AND LOWER(street) = LOWER(:street)
               AND LOWER(zipcode) = LOWER(:zipcode)
             LIMIT 10",
            [':lastname' => $lastName, ':fullname' => $name, ':street' => $street, ':zipcode' => $zipcode]
        ) ?: [];
    }

    resultInfo(true, 'OK', ['exact' => $exact, 'partial' => $partial]);
}
