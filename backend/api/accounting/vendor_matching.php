<?php
// backend/api/accounting/vendor_matching.php

/**
 * Lieferanten mit Dublettenpruefer laden
 *
 * @param string $data['query']   Suchbegriff (optional)
 * @param int    $data['limit']   Anzahl (Standard: 50)
 * @param bool   $data['include_obsolete'] Auch inaktive anzeigen
 * @testdata {"limit": 50}
 */
function getAccountingVendors($data) {
    $db = DbhCompany::begin();

    $query = trim($data['query'] ?? '');
    $limit = intval($data['limit'] ?? 50);
    $includeObsolete = !empty($data['include_obsolete']);

    $where = $includeObsolete ? '1=1' : 'v.obsolete IS NOT TRUE';
    $params = [':limit' => $limit];

    if (!empty($query)) {
        $where .= " AND (v.name ILIKE :q OR v.vendornumber ILIKE :q2 OR v.iban ILIKE :q3 OR v.taxnumber ILIKE :q4)";
        $params[':q'] = '%' . $query . '%';
        $params[':q2'] = $query . '%';
        $params[':q3'] = '%' . $query . '%';
        $params[':q4'] = '%' . $query . '%';
    }

    $vendors = $db->getAll(<<<SQL
        SELECT v.id, v.name, v.vendornumber, v.street, v.zipcode, v.city,
               v.phone, v.email, v.iban, v.bic, v.taxnumber, v.ustid,
               v.obsolete,
               (SELECT COUNT(*) FROM accounting_bookings ab WHERE ab.vendor_id = v.id) AS booking_count,
               (SELECT SUM(ab.amount) FROM accounting_bookings ab WHERE ab.vendor_id = v.id AND ab.type = 'incoming' AND ab.status != 'rejected') AS total_amount,
               (SELECT ab.debit_account FROM accounting_bookings ab WHERE ab.vendor_id = v.id AND ab.type = 'incoming' ORDER BY ab.booking_date DESC LIMIT 1) AS default_account,
               (SELECT STRING_AGG(va.alias_name, ', ') FROM vendor_aliases va WHERE va.vendor_id = v.id) AS aliases
        FROM vendor v
        WHERE {$where}
        ORDER BY v.name
        LIMIT :limit
    SQL, $params);

    resultInfo(true, '', ['vendors' => $vendors ?: []]);
}

/**
 * Lieferant anlegen (mit Dublettenprüfung)
 *
 * @param string $data['name']      Firmenname
 * @param string $data['street']    Strasse
 * @param string $data['zipcode']   PLZ
 * @param string $data['city']      Ort
 * @param string $data['iban']      IBAN
 * @param string $data['taxnumber'] Steuernummer
 * @param string $data['ustid']     USt-IdNr
 * @param string $data['email']     E-Mail
 * @param string $data['phone']     Telefon
 * @param bool   $data['force']     Anlage trotz Duplikat erzwingen
 * @testdata {"name": "Test GmbH", "city": "Berlin"}
 */
function createAccountingVendor($data) {
    $db = DbhCompany::begin();

    $name = trim($data['name'] ?? '');
    if (empty($name)) throw new ApiError('VALIDATION_ERROR', 'name erforderlich');

    $iban = trim($data['iban'] ?? '');
    $taxnumber = trim($data['taxnumber'] ?? $data['ustid'] ?? '');
    $force = !empty($data['force']);

    // Dublettenprüfung
    if (!$force) {
        $matches = $db->getAll(
            "SELECT * FROM find_vendor_fuzzy(:name, :iban, :tax, 0.4)",
            [':name' => $name, ':iban' => $iban ?: null, ':tax' => $taxnumber ?: null]
        );

        if (!empty($matches)) {
            $duplicates = [];
            foreach ($matches as $m) {
                $vendor = $db->getOne("SELECT id, name, city, iban FROM vendor WHERE id = :id", [':id' => $m['vendor_id']]);
                $duplicates[] = [
                    'vendor_id'   => intval($m['vendor_id']),
                    'vendor_name' => $m['vendor_name'],
                    'city'        => $vendor['city'] ?? '',
                    'iban'        => $vendor['iban'] ?? '',
                    'match_score' => floatval($m['match_score']),
                    'match_type'  => $m['match_type']
                ];
            }

            resultInfo(false, 'POSSIBLE_DUPLICATES', [
                'message'    => 'Moegliche Dubletten gefunden. Mit force=true trotzdem anlegen.',
                'duplicates' => $duplicates
            ]);
            return;
        }
    }

    // Naechste Lieferantennummer
    $vendornumber = $db->getOne(
        "SELECT COALESCE(MAX(CAST(vendornumber AS INTEGER)), 70000) + 1 AS next_nr
         FROM vendor WHERE vendornumber ~ '^\d+$'",
        []
    );

    $db->execute(
        "INSERT INTO vendor (name, street, zipcode, city, country, taxnumber, ustid, iban, bic, phone, email, vendornumber, taxzone_id, currency_id)
         VALUES (:name, :street, :zip, :city, :country, :tax, :ustid, :iban, :bic, :phone, :email, :vnr, 1, 1)",
        [
            ':name'    => $name,
            ':street'  => $data['street'] ?? null,
            ':zip'     => $data['zipcode'] ?? null,
            ':city'    => $data['city'] ?? null,
            ':country' => $data['country'] ?? 'DE',
            ':tax'     => $data['taxnumber'] ?? null,
            ':ustid'   => $data['ustid'] ?? null,
            ':iban'    => $iban ?: null,
            ':bic'     => $data['bic'] ?? null,
            ':phone'   => $data['phone'] ?? null,
            ':email'   => $data['email'] ?? null,
            ':vnr'     => $vendornumber['next_nr']
        ]
    );

    $newVendor = $db->getOne(
        "SELECT id, name, vendornumber FROM vendor WHERE vendornumber = :vnr",
        [':vnr' => $vendornumber['next_nr']]
    );

    resultInfo(true, 'Lieferant angelegt', [
        'vendor_id'      => intval($newVendor['id']),
        'vendor_name'    => $newVendor['name'],
        'vendornumber'   => $newVendor['vendornumber']
    ]);
}

/**
 * Lieferant bearbeiten
 *
 * @param int    $data['vendor_id'] Lieferanten-ID
 * @param string $data['name']      Firmenname (optional)
 * @param string $data['iban']      IBAN (optional)
 * @testdata {"vendor_id": 1, "name": "Geaenderter Name"}
 */
function updateAccountingVendor($data) {
    $db = DbhCompany::begin();
    $vendorId = intval($data['vendor_id'] ?? 0);
    if (!$vendorId) throw new ApiError('VALIDATION_ERROR', 'vendor_id erforderlich');

    $allowedFields = ['name', 'street', 'zipcode', 'city', 'country', 'phone', 'email',
                      'iban', 'bic', 'taxnumber', 'ustid'];
    $fields = [];
    $params = [':id' => $vendorId];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $data)) {
            $fields[] = "{$field} = :{$field}";
            $params[":{$field}"] = $data[$field];
        }
    }

    if (empty($fields)) throw new ApiError('VALIDATION_ERROR', 'Keine Felder zum Aktualisieren');

    $fields[] = "mtime = NOW()";
    $db->execute(
        "UPDATE vendor SET " . implode(', ', $fields) . " WHERE id = :id",
        $params
    );

    resultInfo(true, 'Lieferant aktualisiert', ['vendor_id' => $vendorId]);
}

/**
 * Lieferanten zusammenfuehren (Deduplizierung)
 *
 * @param int $data['keep_vendor_id']   Lieferant der beibehalten wird
 * @param int $data['merge_vendor_id']  Lieferant der aufgeloest wird
 * @testdata {"keep_vendor_id": 1, "merge_vendor_id": 2}
 */
function mergeVendors($data) {
    $db = DbhCompany::begin();

    $keepId = intval($data['keep_vendor_id'] ?? 0);
    $mergeId = intval($data['merge_vendor_id'] ?? 0);

    if (!$keepId || !$mergeId || $keepId === $mergeId) {
        throw new ApiError('VALIDATION_ERROR', 'Zwei unterschiedliche vendor_ids erforderlich');
    }

    $keepVendor = $db->getOne("SELECT id, name FROM vendor WHERE id = :id", [':id' => $keepId]);
    $mergeVendor = $db->getOne("SELECT id, name FROM vendor WHERE id = :id", [':id' => $mergeId]);

    if (!$keepVendor || !$mergeVendor) {
        throw new ApiError('DATA_NOT_FOUND', 'Lieferant nicht gefunden');
    }

    $db->beginTransaction();

    try {
        // Alle Referenzen umhaengen
        $db->execute("UPDATE accounting_bookings SET vendor_id = :keep WHERE vendor_id = :merge",
            [':keep' => $keepId, ':merge' => $mergeId]);
        $db->execute("UPDATE accounting_documents SET vendor_id = :keep WHERE vendor_id = :merge",
            [':keep' => $keepId, ':merge' => $mergeId]);
        $db->execute("UPDATE ap SET vendor_id = :keep WHERE vendor_id = :merge",
            [':keep' => $keepId, ':merge' => $mergeId]);
        $db->execute("UPDATE oe SET vendor_id = :keep WHERE vendor_id = :merge",
            [':keep' => $keepId, ':merge' => $mergeId]);

        // Name des aufgeloesten Lieferanten als Alias speichern
        $db->execute(
            "INSERT INTO vendor_aliases (vendor_id, alias_name, alias_iban)
             SELECT :keep, :name, iban FROM vendor WHERE id = :merge",
            [':keep' => $keepId, ':name' => $mergeVendor['name'], ':merge' => $mergeId]
        );

        // Vorhandene Aliases umhaengen
        $db->execute("UPDATE vendor_aliases SET vendor_id = :keep WHERE vendor_id = :merge",
            [':keep' => $keepId, ':merge' => $mergeId]);

        // Aufgeloesten Lieferanten als obsolet markieren
        $db->execute("UPDATE vendor SET obsolete = TRUE, mtime = NOW() WHERE id = :id",
            [':id' => $mergeId]);

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        throw new ApiError('MERGE_ERROR', 'Zusammenfuehrung fehlgeschlagen: ' . $e->getMessage());
    }

    resultInfo(true, 'Lieferanten zusammengefuehrt', [
        'kept_vendor'  => $keepVendor['name'],
        'merged_vendor' => $mergeVendor['name']
    ]);
}

/**
 * Potenzielle Dubletten finden
 *
 * @param float $data['threshold'] Schwellwert (Standard: 0.4)
 * @testdata {"threshold": 0.4}
 */
function findVendorDuplicates($data) {
    $db = DbhCompany::begin();
    $threshold = floatval($data['threshold'] ?? 0.4);

    $duplicates = $db->getAll(<<<SQL
        SELECT v1.id AS vendor1_id, v1.name AS vendor1_name, v1.city AS vendor1_city,
               v2.id AS vendor2_id, v2.name AS vendor2_name, v2.city AS vendor2_city,
               similarity(LOWER(v1.name), LOWER(v2.name)) AS name_similarity,
               CASE WHEN v1.iban IS NOT NULL AND v1.iban != '' AND v1.iban = v2.iban THEN TRUE ELSE FALSE END AS same_iban
        FROM vendor v1
        JOIN vendor v2 ON v2.id > v1.id
        WHERE v1.obsolete IS NOT TRUE AND v2.obsolete IS NOT TRUE
        AND (
            similarity(LOWER(v1.name), LOWER(v2.name)) > :threshold
            OR (v1.iban IS NOT NULL AND v1.iban != '' AND REPLACE(v1.iban, ' ', '') = REPLACE(v2.iban, ' ', ''))
        )
        ORDER BY name_similarity DESC
        LIMIT 50
    SQL, [':threshold' => $threshold]);

    resultInfo(true, '', ['duplicates' => $duplicates ?: []]);
}
