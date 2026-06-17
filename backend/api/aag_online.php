<?php
// backend/api/aag_online.php
//
// Gemeinsame AAG-Online-Anbindung (DVSE TM.Next): Token holen, Belege/Fahrzeuge
// an das Portal übertragen und die Portal-URL zurückliefern.
// Wird von der Faktura (Auftrag) und vom Fahrzeug (LxCars) genutzt.

const AAG_LOGIN_URL  = 'https://tm-next.dvse.de/data/TM.Next.Authority/external/login/GetAuthToken';
const AAG_IMPORT_URL = 'https://tm-next.dvse.de/data/TM.Next.Dms/api/portal/service/v1/Gsi/ImportVoucher';
const AAG_AUTH_ID    = 'ti6x'; // Authentifizierungs-ID der DVSE: ti6x = AAG-Online
const AAG_TOKEN_TTL  = 1800;   // Token-Cache-Dauer in Sekunden (30 Min.)

/**
 * Holt ein Bearer-Token von AAG-Online.
 *
 * Das Token wird in defaults_oserp zwischengespeichert (aag_online_token /
 * aag_online_token_exp), um pro Klick einen HTTP-Roundtrip zu sparen. Bei
 * abgelaufenem Token oder $forceRefresh wird neu angemeldet.
 *
 * @param object $db           DbhCompany-Handle
 * @param bool   $forceRefresh Cache ignorieren und neu anmelden
 * @return string Bearer-Token
 * @throws ApiError wenn Zugangsdaten fehlen oder der Login fehlschlägt
 */
function aagGetToken($db, $forceRefresh = false) {
    $cfg = $db->getOne(
        "SELECT
            MAX(value) FILTER (WHERE key = 'aag_online_user')      AS aag_user,
            MAX(value) FILTER (WHERE key = 'aag_online_passwd')    AS aag_passwd,
            MAX(value) FILTER (WHERE key = 'aag_online_token')     AS aag_token,
            MAX(value) FILTER (WHERE key = 'aag_online_token_exp') AS aag_token_exp
         FROM defaults_oserp
         WHERE key IN ('aag_online_user', 'aag_online_passwd', 'aag_online_token', 'aag_online_token_exp')"
    );

    // Gültiges Token aus dem Cache verwenden
    if (!$forceRefresh
        && !empty($cfg['aag_token'])
        && intval($cfg['aag_token_exp'] ?? 0) > time()) {
        return $cfg['aag_token'];
    }

    $user = trim($cfg['aag_user'] ?? '');
    $passwd = trim($cfg['aag_passwd'] ?? '');

    if ($user === '' || $passwd === '') {
        throw new ApiError('AAG_NO_CREDENTIALS', 'AAG-Online Zugangsdaten sind nicht konfiguriert (Einstellungen → AAG-Online)');
    }

    $curl = curl_init(AAG_LOGIN_URL);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => json_encode([
            'authId'   => AAG_AUTH_ID,
            'username' => $user,
            'password' => $passwd
        ])
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        throw new ApiError('AAG_LOGIN_FAILED', 'AAG-Online Login fehlgeschlagen: ' . $err);
    }

    $decoded = json_decode($response, true);
    if (empty($decoded['token'])) {
        throw new ApiError('AAG_LOGIN_FAILED', 'AAG-Online Token konnte nicht abgerufen werden');
    }

    $token = $decoded['token'];

    // Token cachen
    $db->execute(
        "INSERT INTO defaults_oserp (key, value, mtime) VALUES
            ('aag_online_token', :token, now()),
            ('aag_online_token_exp', :exp, now())
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
        ['token' => $token, 'exp' => (string)(time() + AAG_TOKEN_TTL)]
    );

    return $token;
}

/**
 * Überträgt einen Beleg/ein Fahrzeug an AAG-Online (ImportVoucher) und liefert
 * die Portal-URL zurück. Bei abgelaufenem Token wird einmal neu angemeldet.
 *
 * @param object $db      DbhCompany-Handle
 * @param array  $payload ImportVoucher-Payload
 * @return array ['portalUrl' => string|null, 'error' => string]
 */
function aagImportVoucher($db, $payload) {
    $token = aagGetToken($db);
    $portalUrl = null;
    $lastError = '';

    for ($try = 0; $try < 2; $try++) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => AAG_IMPORT_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Accept-Language: de',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $lastError = curl_error($curl);
        curl_close($curl);

        if ($lastError) {
            $token = aagGetToken($db, true); // Verbindungsfehler → Token erneuern
            continue;
        }

        if ($httpCode === 401) {
            $token = aagGetToken($db, true); // Token abgelaufen → erneuern
            continue;
        }

        $decoded = json_decode($response, true);
        $portalUrl = $decoded['portalUrl'] ?? null;
        $lastError = $portalUrl ? '' : ('Unerwartete Antwort von AAG-Online: ' . $response);
        break;
    }

    return ['portalUrl' => $portalUrl, 'error' => $lastError];
}

/**
 * Überträgt die Fahrzeug-/Kundendaten eines Auftrags an AAG-Online und liefert
 * die Portal-URL zurück, unter der der Beleg im Teilekatalog geöffnet wird.
 *
 * @param mixed $data['fakturaID'] ID des Auftrags (oe.id)
 * @testdata {"fakturaID": 29116}
 */
function getAagUrl($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);

    if ($fakturaID <= 0) {
        resultInfo(false, 'INVALID_FAKTURA_ID', ['message' => 'Ungültige Auftrags-ID']);
        return;
    }

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType('order'));

    // Auftrag + Kunde + Fahrzeug in einer Abfrage holen
    $row = $company->getOne(
        "SELECT
            oe.id AS oe_id,
            oe.ordnumber,
            ext.km_stand,
            customer.id AS customer_id,
            customer.customernumber,
            CASE
                WHEN customer.greeting ILIKE '%Herr/Frau%' THEN 0
                WHEN customer.greeting ILIKE '%Herr%'      THEN 1
                WHEN customer.greeting ILIKE '%Frau%'      THEN 2
                ELSE 3
            END AS title,
            customer.name,
            CASE
                WHEN customer.greeting IN ('Frau', 'Herr', 'Herr/Frau') THEN
                    split_part(customer.name, ' ', array_length(string_to_array(customer.name, ' '), 1))
                ELSE ''
            END AS last_name,
            CASE
                WHEN customer.greeting IN ('Frau', 'Herr') THEN
                    array_to_string(array_remove(string_to_array(customer.name, ' '),
                        split_part(customer.name, ' ', array_length(string_to_array(customer.name, ' '), 1))), ' ')
                ELSE ''
            END AS first_name,
            CASE
                WHEN customer.greeting NOT IN ('Frau', 'Herr') THEN customer.name
                ELSE ''
            END AS company_name,
            customer.street,
            customer.zipcode,
            customer.city,
            customer.country,
            customer.phone,
            customer.fax,
            customer.phone3,
            customer.email,
            customer.notes,
            to_char(customer.itime, 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS customer_itime,
            to_char(customer.mtime, 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS customer_mtime,
            car.c_id,
            car.c_ln,
            car.c_fin,
            car.c_mkb,
            car.c_text,
            to_char(car.c_d, 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS registration_date,
            to_char(car.c_it, 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS car_itime,
            CONCAT(car.c_2, car.c_3) AS kba
         FROM oe
         JOIN customer ON customer.id = oe.customer_id
         JOIN oe_ext ext ON ext.oe_id = oe.id
         JOIN cars_lxcars car ON car.c_id = ext.c_id
         WHERE oe.id = :oe_id",
        ['oe_id' => $fakturaID]
    );

    if (!$row) {
        resultInfo(false, 'NO_VEHICLE', ['message' => 'Kein Fahrzeug am Auftrag verknüpft']);
        return;
    }

    // Belegdaten für AAG-Online (DVSE ImportVoucher) zusammenstellen
    $payload = [
        'referenceId' => $row['ordnumber'],
        'voucherId'   => (string) $row['oe_id'],
        'voucherType' => [
            'referenceId' => '2',
            'description' => 'Auftrag',
            'countryCode' => 'DE'
        ],
        'customer' => [
            'referenceId' => (string) $row['customer_id'],
            'customerId'  => $row['customernumber'],
            'title'       => intval($row['title']),
            'firstName'   => $row['first_name'],
            'lastName'    => $row['last_name'],
            'companyName' => $row['company_name'],
            'generalAddress' => [
                'description' => 'Anschrift',
                'street'      => $row['street'],
                'city'        => $row['city'],
                'zip'         => $row['zipcode'],
                'country'     => $row['country']
            ],
            'phone'  => $row['phone'],
            'mobile' => $row['fax'],
            'fax'    => $row['phone3'],
            'email'  => $row['email'],
            'memos'  => [[
                'description' => 'Bemerkungen',
                'value'       => $row['notes'] ?? '',
                'type'        => '0',
                'isVisible'   => true
            ]],
            'creationDate' => $row['customer_itime'],
            'modifiedDate' => $row['customer_mtime']
        ],
        'vehicle' => [
            'referenceId' => (string) $row['c_id'],
            'vehicleType' => [
                'type' => 1 // PKW
            ],
            'registrationInformation' => [
                'plateId'          => $row['c_ln'],
                'countryCode'      => 'DE',
                'registrationNo'   => $row['kba'], // KBA-Nummer (wird nur genutzt wenn keine KTYPNR übergeben wird)
                'registrationDate' => $row['registration_date'],
                'registrationTypeId' => 0 // KBA
            ],
            'vin'         => $row['c_fin'],
            'mileage'     => intval($row['km_stand']),
            'mileageType' => 1, // Kilometer
            'engineCode'  => $row['c_mkb'],
            'memos'       => [[
                'description' => 'Bemerkungen zum Fahrzeug',
                'value'       => $row['c_text'] ?? '',
                'type'        => '0',
                'isVisible'   => true
            ]],
            'creationDate' => $row['car_itime']
        ]
    ];

    $result = aagImportVoucher($company, $payload);

    if (!$result['portalUrl']) {
        resultInfo(false, 'AAG_IMPORT_FAILED', ['message' => $result['error'] ?: 'AAG-Online Übertragung fehlgeschlagen']);
        return;
    }

    resultInfo(true, '', ['portalUrl' => $result['portalUrl']]);
}

/**
 * Öffnet ein Fahrzeug in AAG-Online anhand der FIN (ohne Auftragskontext).
 * Wird auf der Fahrzeugseite genutzt, wenn die TSN ein Platzhalter ist.
 *
 * @param mixed $data['c_id'] Fahrzeug-ID (cars_lxcars.c_id), für die Referenz
 * @param mixed $data['vin']  FIN/Fahrgestellnummer (Pflicht)
 * @testdata {"c_id": 6471, "vin": "WAUZZZ4G3DN045044"}
 */
function getAagVehicleUrl($data) {
    $cId = intval($data['c_id'] ?? 0);
    $vin = strtoupper(trim($data['vin'] ?? ''));

    if ($vin === '') {
        resultInfo(false, 'NO_VIN', ['message' => 'Keine FIN angegeben']);
        return;
    }

    $company = DbhCompany::begin();

    // Kennzeichen/Erstzulassung ergänzen, falls das Fahrzeug bereits gespeichert ist
    $plate = '';
    $registrationDate = null;
    if ($cId > 0) {
        $car = $company->getOne(
            "SELECT c_ln, to_char(c_d, 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS reg_date
             FROM cars_lxcars WHERE c_id = :c_id",
            ['c_id' => $cId]
        );
        if ($car) {
            $plate = $car['c_ln'] ?? '';
            $registrationDate = $car['reg_date'];
        }
    }

    $registration = [
        'countryCode'        => 'DE',
        'registrationTypeId' => 0
    ];
    if ($plate !== '') $registration['plateId'] = $plate;
    if ($registrationDate) $registration['registrationDate'] = $registrationDate;

    // Minimaler Beleg: Fahrzeug wird allein über die FIN identifiziert
    $payload = [
        'referenceId' => 'FZG_' . ($cId > 0 ? $cId : $vin),
        'voucherType' => [
            'referenceId' => '2',
            'description' => 'Fahrzeug',
            'countryCode' => 'DE'
        ],
        'vehicle' => [
            'referenceId'             => $vin,
            'vehicleType'             => ['type' => 1], // PKW
            'registrationInformation' => $registration,
            'vin'                     => $vin
        ]
    ];

    $result = aagImportVoucher($company, $payload);

    if (!$result['portalUrl']) {
        resultInfo(false, 'AAG_IMPORT_FAILED', ['message' => $result['error'] ?: 'AAG-Online Übertragung fehlgeschlagen']);
        return;
    }

    resultInfo(true, '', ['portalUrl' => $result['portalUrl']]);
}
