<?php
// backend/api/aag_online.php
//
// Gemeinsame AAG-Online-Anbindung (DVSE TM.Next): Token holen, Belege/Fahrzeuge
// an das Portal übertragen und die Portal-URL zurückliefern.
// Wird von der Faktura (Auftrag) und vom Fahrzeug (LxCars) genutzt.

// AAG-Online Basis-URL. Produktiv: https://tm2.carparts-cat.com
// (Testsystem war https://tm-next.dvse.de). Host hier zentral umstellen.
const AAG_BASE = 'https://tm2.carparts-cat.com';
const AAG_LOGIN_URL  = AAG_BASE . '/data/TM.Next.Authority/external/login/GetAuthToken';
const AAG_IMPORT_URL = AAG_BASE . '/data/TM.Next.Dms/api/portal/service/v1/Gsi/ImportVoucher';
// GSI-Voucher-Endpunkte (dokumentierte API) für den Ktype-Roundtrip Import→Export
const AAG_GSI_IMPORT_URL = AAG_BASE . '/data/TM.Next.Dms/gsi/vouchers/ImportVoucher';
const AAG_GSI_EXPORT_URL = AAG_BASE . '/data/TM.Next.Dms/gsi/vouchers/ExportVoucher';
const AAG_AUTH_ID    = 'ti6x'; // Authentifizierungs-ID der DVSE: ti6x = AAG-Online
const AAG_TOKEN_TTL  = 43200;  // Fallback-Gueltigkeit (12 h), falls AAG kein 'expiration' liefert
const AAG_TOKEN_SKEW = 300;    // Sicherheitspuffer (5 Min.): Token vor echtem Ablauf erneuern
// Cloudflare vor docs/API blockt Default-Clients ohne Browser-User-Agent
const AAG_USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0 Safari/537.36';

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

    // Gültiges Token aus dem Cache verwenden (mit Sicherheitspuffer, damit ein
    // kurz vor Ablauf stehendes Token nicht mitten in einer Operation verfällt)
    if (!$forceRefresh
        && !empty($cfg['aag_token'])
        && intval($cfg['aag_token_exp'] ?? 0) > time() + AAG_TOKEN_SKEW) {
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

    // Echte Gueltigkeit aus der AAG-Antwort uebernehmen ('expiration' in Sekunden,
    // i.d.R. 12 h). Fehlt das Feld, greift der Fallback AAG_TOKEN_TTL.
    $ttl = intval($decoded['expiration'] ?? 0);
    if ($ttl <= 0) $ttl = AAG_TOKEN_TTL;
    $exp = time() + $ttl;

    // Token cachen
    $db->execute(
        "INSERT INTO defaults_oserp (key, value, mtime) VALUES
            ('aag_online_token', :token, now()),
            ('aag_online_token_exp', :exp, now())
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
        ['token' => $token, 'exp' => (string)$exp]
    );

    return $token;
}

/**
 * Stellt im Hintergrund sicher, dass ein gültiges AAG-Online-Token vorliegt.
 *
 * Wird beim Öffnen eines Auftrags nicht-blockierend aufgerufen, damit der
 * langsame Login (~3 s) nicht erst beim Klick auf den AAG-Button anfällt.
 * Ist das gecachte Token noch gültig (i.d.R. 12 h), ist das ein reiner
 * DB-Lesezugriff ohne HTTP-Roundtrip. Fehlende Zugangsdaten werden still
 * ignoriert (kein Fehler-Toast für ein reines Vorladen).
 *
 * @testdata {}
 */
function warmAagToken($data) {
    $company = DbhCompany::begin();
    try {
        aagGetToken($company);
        resultInfo(true, '', ['warmed' => true]);
    } catch (ApiError $e) {
        // Vorladen ist optional – kein harter Fehler nach außen
        resultInfo(true, '', ['warmed' => false]);
    }
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
            car.c_ktype,
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
            // Ktype (TecDoc-Typnummer) hat höchste Such-Priorität: wenn bekannt,
            // identifiziert der Katalog das Fahrzeug direkt – auch bei
            // ausgenullter TSN, wo die KBA-Suche scheitert.
            'vehicleType' => intval($row['c_ktype']) > 0
                ? ['id' => intval($row['c_ktype']), 'type' => 1]
                : ['type' => 1], // PKW
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

    // Kennzeichen/Erstzulassung/Ktype ergänzen, falls das Fahrzeug bereits gespeichert ist
    $plate = '';
    $registrationDate = null;
    $ktype = 0;
    if ($cId > 0) {
        $car = $company->getOne(
            "SELECT c_ln, c_ktype, to_char(c_d, 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS reg_date
             FROM cars_lxcars WHERE c_id = :c_id",
            ['c_id' => $cId]
        );
        if ($car) {
            $plate = $car['c_ln'] ?? '';
            $registrationDate = $car['reg_date'];
            $ktype = intval($car['c_ktype'] ?? 0);
        }
    }

    $registration = [
        'countryCode'        => 'DE',
        'registrationTypeId' => 0
    ];
    if ($plate !== '') $registration['plateId'] = $plate;
    if ($registrationDate) $registration['registrationDate'] = $registrationDate;

    // Ktype (TecDoc-Typnummer) hat höchste Such-Priorität: wenn bekannt,
    // identifiziert der Katalog das Fahrzeug direkt – wichtig bei ausgenullter
    // TSN, wo die reine VIN-/KBA-Suche das Modell sonst nicht findet.
    $vehicleType = $ktype > 0 ? ['id' => $ktype, 'type' => 1] : ['type' => 1]; // PKW

    // Minimaler Beleg: Fahrzeug wird über Ktype (falls vorhanden) bzw. FIN identifiziert
    $payload = [
        'referenceId' => 'FZG_' . ($cId > 0 ? $cId : $vin),
        'voucherType' => [
            'referenceId' => '2',
            'description' => 'Fahrzeug',
            'countryCode' => 'DE'
        ],
        'vehicle' => [
            'referenceId'             => $vin,
            'vehicleType'             => $vehicleType,
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

/**
 * Sendet einen JSON-POST an einen AAG-Online-Endpunkt (mit Token + 401-Retry).
 *
 * @param object $db   DbhCompany-Handle (für Token)
 * @param string $url  Endpunkt-URL
 * @param array  $body Request-Body
 * @return array ['status' => int, 'data' => array|null, 'error' => string]
 */
function aagPostJson($db, $url, $body) {
    $token = aagGetToken($db);

    for ($try = 0; $try < 2; $try++) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_USERAGENT      => AAG_USER_AGENT,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Accept-Language: de',
                'Content-Type: application/json',
                'Accept: */*',
                'Authorization: Bearer ' . $token
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            $token = aagGetToken($db, true);
            continue;
        }
        if ($httpCode === 401) {
            $token = aagGetToken($db, true);
            continue;
        }

        return ['status' => $httpCode, 'data' => json_decode($response, true), 'error' => ''];
    }

    return ['status' => 0, 'data' => null, 'error' => 'AAG-Online nicht erreichbar'];
}

/**
 * Ermittelt die TecDoc-Ktype-Nummer eines Fahrzeugs über AAG-Online und
 * speichert sie (mit Klartext-Beschreibung) in cars_lxcars.
 *
 * Identifikation über die KBA-Nummer (HSN+TSN), falls gültig vorhanden,
 * sonst über die FIN. Der Katalog identifiziert headless: ImportVoucher legt
 * den Vorgang an, ExportVoucher liefert das aufgelöste Fahrzeug zurück.
 * Gespeichert wird nur bei eindeutigem Treffer (vehicleType.id > 0).
 *
 * @param mixed $data['c_id'] Fahrzeug-ID (cars_lxcars.c_id)
 * @testdata {"c_id": 6471}
 */
function resolveKtype($data) {
    $cId = intval($data['c_id'] ?? 0);
    if ($cId <= 0) {
        resultInfo(false, 'INVALID_CAR_ID', ['message' => 'Ungültige Fahrzeug-ID']);
        return;
    }

    $company = DbhCompany::begin();

    $car = $company->getOne(
        "SELECT c_2, c_3, c_fin, c_ktype FROM cars_lxcars WHERE c_id = :c_id",
        ['c_id' => $cId]
    );
    if (!$car) {
        resultInfo(false, 'CAR_NOT_FOUND', ['message' => 'Fahrzeug nicht gefunden']);
        return;
    }

    $hsn = trim($car['c_2'] ?? '');
    $tsn = trim($car['c_3'] ?? '');
    $vin = strtoupper(trim($car['c_fin'] ?? ''));

    // Fahrzeug-Identifikation: bevorzugt KBA (zuverlässiger), sonst FIN
    $hasKba = preg_match('/^\d{4}$/', $hsn) && $tsn !== '' && substr($tsn, 0, 3) !== '000';

    $vehicle = ['referenceId' => 'v_' . $cId, 'vehicleType' => ['id' => 0, 'type' => 1]];
    if ($hasKba) {
        $vehicle['registrationInformation'] = [
            'countryCode'        => 'DE',
            'registrationNo'     => $hsn . $tsn, // KBA-Nummer = HSN+TSN
            'registrationTypeId' => 0
        ];
    } elseif ($vin !== '') {
        $vehicle['vin'] = $vin;
        $vehicle['registrationInformation'] = ['countryCode' => 'DE'];
    } else {
        resultInfo(false, 'NO_IDENTIFIER', ['message' => 'Weder gültige HSN/TSN noch FIN vorhanden']);
        return;
    }

    $referenceId = 'OSERP_FZG_' . $cId;

    // 1. ImportVoucher: Vorgang anlegen / Fahrzeugsuche auslösen
    $imp = aagPostJson($company, AAG_GSI_IMPORT_URL, [
        'referenceId' => $referenceId,
        'voucherType' => ['referenceId' => '1', 'description' => 'Fahrzeugidentifikation'],
        'vehicle'     => $vehicle
    ]);
    if ($imp['status'] !== 200) {
        resultInfo(false, 'AAG_IMPORT_FAILED', ['message' => $imp['error'] ?: ('HTTP ' . $imp['status'])]);
        return;
    }

    // 2. ExportVoucher: aufgelöstes Fahrzeug zurücklesen
    $exp = aagPostJson($company, AAG_GSI_EXPORT_URL, ['referenceId' => $referenceId]);
    if ($exp['status'] !== 200) {
        resultInfo(false, 'AAG_EXPORT_FAILED', ['message' => $exp['error'] ?: ('HTTP ' . $exp['status'])]);
        return;
    }

    $vt = $exp['data']['vehicle']['vehicleType'] ?? null;
    $ktype = intval($vt['id'] ?? 0);
    $desc = trim($vt['description'] ?? '');

    if ($ktype <= 0) {
        // Kein eindeutiger Treffer (mehrere Fahrzeuge oder nicht gefunden) → nichts speichern
        resultInfo(false, 'NO_UNIQUE_MATCH', ['message' => 'Kein eindeutiges Fahrzeug ermittelt']);
        return;
    }

    $company->execute(
        "UPDATE cars_lxcars SET c_ktype = :k, c_ktype_desc = :d WHERE c_id = :c_id",
        ['k' => $ktype, 'd' => $desc, 'c_id' => $cId]
    );

    resultInfo(true, '', ['c_ktype' => $ktype, 'c_ktype_desc' => $desc, 'source' => $hasKba ? 'kba' : 'vin']);
}
