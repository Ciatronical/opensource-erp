<?php

/**
 * DHL-Versandetikett erstellen
 *
 * Laedt die Empfaengeradresse aus dem Beleg (shipto oder Kundenadresse)
 * und erstellt ueber die DHL API ein Versandlabel.
 *
 * @param array $data['record_id'] ID des Belegs
 * @param array $data['record_type'] Belegtyp (invoice, order, delivery_order)
 * @param array $data['weight'] Gewicht in kg
 * @param array $data['product'] DHL-Produkt (V01PAK, V53WPAK, V62WP)
 * @param array $data['length'] Laenge in cm (optional)
 * @param array $data['width'] Breite in cm (optional)
 * @param array $data['height'] Hoehe in cm (optional)
 * @testdata {"record_id": 1, "record_type": "invoice", "weight": 2.5, "product": "V01PAK"}
 */
function createDhlLabel($data) {
    if (empty($data['record_id']) || empty($data['record_type'])) {
        throw new ApiError('DHL_MISSING_PARAMS', 'Beleg-ID und Belegtyp sind erforderlich');
    }

    $weight = (float) ($data['weight'] ?? 0);
    if ($weight <= 0) {
        throw new ApiError('DHL_INVALID_WEIGHT', 'Gewicht muss größer als 0 sein');
    }

    try {
        $api = new DhlApi();
        $db = DbhCompany::begin();

        // Empfaengeradresse ermitteln
        $address = _getDhlRecipientAddress($db, $data['record_type'], $data['record_id']);
        if (!$address) {
            throw new ApiError('DHL_NO_ADDRESS', 'Keine Empfängeradresse gefunden');
        }

        // Belegnummer als Referenz
        $reference = $address['doc_number'] ?? '';

        // Sendung bei DHL erstellen
        $result = $api->createShipment([
            'product' => $data['product'] ?? 'V01PAK',
            'weight' => $weight,
            'length' => $data['length'] ?? null,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'reference' => $reference,
            'recipient_name' => $address['name'],
            'recipient_street' => $address['street'],
            'recipient_house' => $address['house'],
            'recipient_zip' => $address['zipcode'],
            'recipient_city' => $address['city'],
            'recipient_country' => $address['country'] ?: 'DEU'
        ]);

        // In DB speichern
        $auth = DbhAuth::begin();
        $auth->fetchSessionData();
        $employeeId = $db->getOne(
            "SELECT id FROM employee WHERE login = :login",
            [':login' => $auth->getLogin()]
        );

        $db->execute(
            "INSERT INTO dhl_shipments (record_type, record_id, shipment_no, product, weight, label_pdf, created_by)
             VALUES (:record_type, :record_id, :shipment_no, :product, :weight, decode(:label_b64, 'base64'), :created_by)",
            [
                ':record_type' => $data['record_type'],
                ':record_id' => $data['record_id'],
                ':shipment_no' => $result['shipment_no'],
                ':product' => $data['product'] ?? 'V01PAK',
                ':weight' => $weight,
                ':label_b64' => $result['label_b64'],
                ':created_by' => $employeeId['id'] ?? 0
            ]
        );

        // writeLog('DHL: Label erstellt - Sendungsnr. ' . $result['shipment_no'] . ' für ' . $data['record_type'] . ' #' . $data['record_id']);

        resultInfo(true, '', [
            'shipment_no' => $result['shipment_no'],
            'label_b64' => $result['label_b64']
        ]);

    } catch (ApiError $e) {
        resultInfo(false, $e->getId(), $e->getMessage());
        // writeLog('DHL Fehler: ' . $e->getMessage());
    } catch (Exception $e) {
        resultInfo(false, 'DHL_ERROR', $e->getMessage());
        // writeLog('DHL Fehler: ' . $e->getMessage());
    }
}

/**
 * DHL-Sendung stornieren
 *
 * @param array $data['shipment_no'] DHL Sendungsnummer
 * @testdata {"shipment_no": "00340434161094042557"}
 */
function deleteDhlLabel($data) {
    if (empty($data['shipment_no'])) {
        throw new ApiError('DHL_MISSING_SHIPMENT_NO', 'Sendungsnummer ist erforderlich');
    }

    try {
        $api = new DhlApi();
        $api->deleteShipment($data['shipment_no']);

        $db = DbhCompany::begin();
        $db->execute(
            "DELETE FROM dhl_shipments WHERE shipment_no = :shipment_no",
            [':shipment_no' => $data['shipment_no']]
        );

        // writeLog('DHL: Sendung storniert - ' . $data['shipment_no']);
        resultInfo(true);

    } catch (ApiError $e) {
        resultInfo(false, $e->getId(), $e->getMessage());
        // writeLog('DHL Fehler: ' . $e->getMessage());
    } catch (Exception $e) {
        resultInfo(false, 'DHL_ERROR', $e->getMessage());
        // writeLog('DHL Fehler: ' . $e->getMessage());
    }
}

/**
 * DHL-Sendungen zu einem Beleg abrufen
 *
 * @param array $data['record_id'] ID des Belegs
 * @param array $data['record_type'] Belegtyp
 * @testdata {"record_id": 1, "record_type": "invoice"}
 */
function getDhlShipments($data) {
    if (empty($data['record_id']) || empty($data['record_type'])) {
        throw new ApiError('DHL_MISSING_PARAMS', 'Beleg-ID und Belegtyp sind erforderlich');
    }

    $db = DbhCompany::begin();
    $shipments = $db->getAll(
        "SELECT id, shipment_no, product, weight, created_at, created_by
         FROM dhl_shipments
         WHERE record_type = :record_type AND record_id = :record_id
         ORDER BY created_at DESC",
        [':record_type' => $data['record_type'], ':record_id' => $data['record_id']]
    );

    resultInfo(true, '', ['shipments' => $shipments ?: []]);
}

/**
 * Label-PDF einer bestehenden Sendung abrufen
 *
 * @param array $data['shipment_no'] DHL Sendungsnummer
 * @testdata {"shipment_no": "00340434161094042557"}
 */
function getDhlLabelPdf($data) {
    if (empty($data['shipment_no'])) {
        throw new ApiError('DHL_MISSING_SHIPMENT_NO', 'Sendungsnummer ist erforderlich');
    }

    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT encode(label_pdf, 'base64') AS label_b64 FROM dhl_shipments WHERE shipment_no = :shipment_no",
        [':shipment_no' => $data['shipment_no']]
    );

    if (!$row) {
        throw new ApiError('DHL_NOT_FOUND', 'Sendung nicht gefunden');
    }

    resultInfo(true, '', ['label_b64' => $row['label_b64']]);
}

/**
 * Empfaengeradresse aus dem Beleg ermitteln
 * Prioritaet: shipto-Adresse > Kundenadresse
 */
function _getDhlRecipientAddress($db, string $recordType, int $recordId): ?array {
    // Tabellen-Mapping
    $tableMap = [
        'invoice' => ['table' => 'ar', 'number_col' => 'invnumber'],
        'order' => ['table' => 'oe', 'number_col' => 'ordnumber'],
        'delivery_order' => ['table' => 'delivery_orders', 'number_col' => 'donumber'],
        'quotation' => ['table' => 'oe', 'number_col' => 'quonumber'],
        'credit_note' => ['table' => 'ar', 'number_col' => 'invnumber']
    ];

    $map = $tableMap[$recordType] ?? null;
    if (!$map) return null;

    // Beleg laden mit shipto_id und customer_id
    $doc = $db->getOne(
        "SELECT id, customer_id, vendor_id, shipto_id, {$map['number_col']} AS doc_number FROM {$map['table']} WHERE id = :id",
        [':id' => $recordId]
    );
    if (!$doc) return null;

    // Prioritaet 1: shipto-Adresse
    if (!empty($doc['shipto_id'])) {
        $shipto = $db->getOne(
            "SELECT shiptoname AS name, shiptostreet AS street, shiptocity AS city,
                    shiptocountry AS country, shiptodepartment_1, shiptodepartment_2,
                    shiptophone AS phone, shiptoemail AS email,
                    shiptozipcode AS zipcode
             FROM shipto WHERE shipto_id = :id",
            [':id' => $doc['shipto_id']]
        );
        if ($shipto) {
            $parsed = _parseStreetHouse($shipto['street'] ?? '');
            return [
                'name' => $shipto['name'] ?? '',
                'street' => $parsed['street'],
                'house' => $parsed['house'],
                'zipcode' => $shipto['zipcode'] ?? '',
                'city' => $shipto['city'] ?? '',
                'country' => _mapCountryToISO3($shipto['country'] ?? ''),
                'doc_number' => $doc['doc_number'] ?? ''
            ];
        }
    }

    // Prioritaet 2: Kundenadresse
    $customerId = $doc['customer_id'] ?? $doc['vendor_id'] ?? 0;
    if ($customerId > 0) {
        $customer = $db->getOne(
            "SELECT name, street, zipcode, city, country FROM customer WHERE id = :id
             UNION ALL
             SELECT name, street, zipcode, city, country FROM vendor WHERE id = :id
             LIMIT 1",
            [':id' => $customerId]
        );
        if ($customer) {
            $parsed = _parseStreetHouse($customer['street'] ?? '');
            return [
                'name' => $customer['name'] ?? '',
                'street' => $parsed['street'],
                'house' => $parsed['house'],
                'zipcode' => $customer['zipcode'] ?? '',
                'city' => $customer['city'] ?? '',
                'country' => _mapCountryToISO3($customer['country'] ?? ''),
                'doc_number' => $doc['doc_number'] ?? ''
            ];
        }
    }

    return null;
}

/**
 * Strasse und Hausnummer trennen
 * "Musterstraße 42a" => ['street' => 'Musterstraße', 'house' => '42a']
 */
function _parseStreetHouse(string $fullStreet): array {
    // Hausnummer am Ende: Zahl ggf. mit Buchstabe
    if (preg_match('/^(.+?)\s+(\d+\s*[a-zA-Z]?\s*(?:[-\/]\s*\d+\s*[a-zA-Z]?)?)$/', trim($fullStreet), $m)) {
        return ['street' => trim($m[1]), 'house' => trim($m[2])];
    }
    return ['street' => trim($fullStreet), 'house' => ''];
}

/**
 * Laendername oder ISO-2-Code in ISO-3166-1 Alpha-3 umwandeln
 */
function _mapCountryToISO3(string $country): string {
    $country = trim($country);
    if (strlen($country) === 3) return strtoupper($country);

    $map = [
        'DE' => 'DEU', 'AT' => 'AUT', 'CH' => 'CHE', 'NL' => 'NLD', 'BE' => 'BEL',
        'FR' => 'FRA', 'IT' => 'ITA', 'ES' => 'ESP', 'PL' => 'POL', 'CZ' => 'CZE',
        'DK' => 'DNK', 'SE' => 'SWE', 'NO' => 'NOR', 'FI' => 'FIN', 'GB' => 'GBR',
        'LU' => 'LUX', 'PT' => 'PRT', 'IE' => 'IRL', 'HU' => 'HUN', 'RO' => 'ROU',
        'BG' => 'BGR', 'HR' => 'HRV', 'SI' => 'SVN', 'SK' => 'SVK', 'LT' => 'LTU',
        'LV' => 'LVA', 'EE' => 'EST', 'GR' => 'GRC', 'US' => 'USA',
        'Deutschland' => 'DEU', 'Österreich' => 'AUT', 'Schweiz' => 'CHE',
        'Frankreich' => 'FRA', 'Italien' => 'ITA', 'Spanien' => 'ESP',
        'Niederlande' => 'NLD', 'Belgien' => 'BEL', 'Polen' => 'POL',
        'Tschechien' => 'CZE', 'Dänemark' => 'DNK', 'Schweden' => 'SWE',
        'Norwegen' => 'NOR', 'Finnland' => 'FIN'
    ];

    return $map[$country] ?? $map[strtoupper($country)] ?? 'DEU';
}
