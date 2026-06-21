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
 * Öffnet ein Fahrzeug in AAG-Online anhand der FIN (ohne Auftragskontext).
 * Wird auf der Fahrzeugseite genutzt, wenn die TSN ein Platzhalter ist.
 *
 * @param mixed $data['c_id']  Fahrzeug-ID (cars_lxcars.c_id), für die Referenz
 * @param mixed $data['vin']   FIN/Fahrgestellnummer (Pflicht)
 * @param mixed $data['oe_id'] Optional: Auftrags-ID für den km-Stand-Kontext
 * @testdata {"c_id": 6471, "vin": "WAUZZZ4G3DN045044", "oe_id": 29116}
 */
function getAagVehicleUrl($data) {
    $cId = intval($data['c_id'] ?? 0);
    $vin = strtoupper(trim($data['vin'] ?? ''));
    $oeId = intval($data['oe_id'] ?? 0);

    $company = DbhCompany::begin();

    // Fahrzeugdaten laden (Identifikation per Ktype > KBA > FIN).
    // km_stand: bevorzugt der des aktuellen Auftrags; ist dort keiner erfasst,
    // der zuletzt erfasste km-Stand aus einem vorherigen Auftrag des Fahrzeugs.
    $plate = '';
    $registrationDate = null;
    $ktype = 0;
    $hsn = '';
    $tsn = '';
    $mileage = 0;
    $nextHu = null;   // c_hu = nächste HU-Fälligkeit
    $lastHu = null;   // letzte HU = nächste HU minus HU-Intervall (fahrzeugartabhängig)
    $nextService = null; // c_wd = nächster Wartungsdienst (Inspektion)
    $mkb = '';        // c_mkb = gewählter Motorkennbuchstabe (an AAG als engineCode)
    if ($cId > 0) {
        // HU-Intervalle nach Anlage VIII StVZO, aus der Fahrzeugart (KBA) abgeleitet:
        //   - Personenbeförderung (Taxi/Mietwagen/Kranken, Flag c_pb): 12 / 12 (jährlich)
        //   - PKW / leichte Nfz (≤3,5t):      Erst-HU 36 Mon., danach alle 24 Mon.
        //   - Kraftrad:                       24 / 24
        //   - schwere Nfz/Anhänger (>3,5t):   12 / 12 (jährlich)
        //   - mehr als 8 Sitzplätze (Bus):    12 / 12
        // Die letzte HU = nächste HU minus Folge-Intervall; liegt dieser Termin vor
        // der rechnerischen Erst-HU (Erstzulassung + Erst-Intervall), gab es noch
        // keine HU → letzte HU bleibt leer (kein erfundenes Datum).
        $car = $company->getOne(
            "WITH base AS (
                SELECT c.c_id, c.c_ln, c.c_2, c.c_3, c.c_fin, c.c_ktype, c.c_mkb, c.c_d, c.c_hu, c.c_wd, c.c_pb,
                       k.fhzart,
                       NULLIF(regexp_replace(COALESCE(k.masse,''), '\\D', '', 'g'), '')::int AS masse_n,
                       NULLIF(regexp_replace(COALESCE(k.sitze,''), '\\D', '', 'g'), '')::int AS sitze_n
                FROM cars_lxcars c
                LEFT JOIN kba_lxcars k ON k.id = c.kba_id
                WHERE c.c_id = :c_id
            ), iv AS (
                SELECT *,
                    CASE WHEN c_pb THEN 12
                         WHEN COALESCE(sitze_n, 0) > 8 THEN 12
                         WHEN fhzart = 'bike' THEN 24
                         WHEN fhzart IN ('truck','tractor','trailer') AND COALESCE(masse_n, 0) > 3500 THEN 12
                         ELSE 24 END AS periodic_m,
                    CASE WHEN c_pb THEN 12
                         WHEN COALESCE(sitze_n, 0) > 8 THEN 12
                         WHEN fhzart = 'bike' THEN 24
                         WHEN fhzart IN ('truck','tractor','trailer') AND COALESCE(masse_n, 0) > 3500 THEN 12
                         ELSE 36 END AS first_m
                FROM base
            )
            SELECT c_ln, c_2, c_3, c_fin, c_ktype, c_mkb,
                   to_char(c_d,  'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS reg_date,
                   to_char(c_hu, 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS next_hu,
                   to_char(c_wd, 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') AS next_service,
                   CASE
                       WHEN c_hu IS NULL THEN NULL
                       WHEN c_d IS NOT NULL
                            AND (c_hu - make_interval(months => periodic_m))
                                < (c_d + make_interval(months => first_m) - interval '3 mon')
                         THEN NULL
                       ELSE to_char(c_hu - make_interval(months => periodic_m), 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"')
                   END AS last_hu,
                   COALESCE(
                       NULLIF((SELECT km_stand FROM oe_ext WHERE oe_id = :oe_id_cur), 0),
                       (SELECT oe2.km_stand
                          FROM oe_ext oe2
                          JOIN oe ON oe.id = oe2.oe_id
                         WHERE oe2.c_id = iv.c_id
                           AND oe2.oe_id <> :oe_id_prev
                           AND COALESCE(oe2.km_stand, 0) > 0
                         ORDER BY oe.transdate DESC, oe.id DESC
                         LIMIT 1),
                       0
                   ) AS km_stand
            FROM iv",
            ['c_id' => $cId, 'oe_id_cur' => $oeId, 'oe_id_prev' => $oeId]
        );
        if ($car) {
            $plate = $car['c_ln'] ?? '';
            $registrationDate = $car['reg_date'];
            $ktype = intval($car['c_ktype'] ?? 0);
            $hsn = trim($car['c_2'] ?? '');
            $tsn = trim($car['c_3'] ?? '');
            $mileage = intval($car['km_stand'] ?? 0);
            $nextHu = $car['next_hu'];
            $lastHu = $car['last_hu'];
            $nextService = $car['next_service'];
            $mkb = trim($car['c_mkb'] ?? '');
            if ($vin === '') $vin = strtoupper(trim($car['c_fin'] ?? ''));
        }
    }

    $hasKba = preg_match('/^\d{4}$/', $hsn) && $tsn !== '' && substr($tsn, 0, 3) !== '000';

    // Mindestens ein Identifikationsmerkmal nötig
    if ($ktype <= 0 && !$hasKba && $vin === '') {
        resultInfo(false, 'NO_IDENTIFIER', ['message' => 'Fahrzeug nicht identifizierbar (weder Ktype, HSN/TSN noch FIN)']);
        return;
    }

    $registration = [
        'countryCode'        => 'DE',
        'registrationTypeId' => 0
    ];
    if ($plate !== '') $registration['plateId'] = $plate;
    if ($registrationDate) $registration['registrationDate'] = $registrationDate;
    if ($hasKba) $registration['registrationNo'] = $hsn . $tsn; // KBA-Nummer

    // Ktype (TecDoc-Typnummer) hat höchste Such-Priorität: wenn bekannt,
    // identifiziert der Katalog das Fahrzeug direkt – wichtig bei ausgenullter
    // TSN, wo die reine VIN-/KBA-Suche das Modell sonst nicht findet.
    $vehicleType = $ktype > 0 ? ['id' => $ktype, 'type' => 1] : ['type' => 1]; // PKW

    $vehicle = [
        'referenceId'             => $vin !== '' ? $vin : 'FZG_' . $cId,
        'vehicleType'             => $vehicleType,
        'registrationInformation' => $registration
    ];
    if ($vin !== '') $vehicle['vin'] = $vin;
    if ($mkb !== '') $vehicle['engineCode'] = $mkb; // gewählten Motor vorselektieren
    if ($mileage > 0) {
        $vehicle['mileage']     = $mileage;
        $vehicle['mileageType'] = 1; // Kilometer
    }
    // HU- und Inspektionstermine (AAG-Feldnamen via ImportVoucher/ExportVoucher verifiziert)
    if ($nextHu)      $vehicle['nextGeneralInspection'] = $nextHu;   // nächste HU
    if ($lastHu)      $vehicle['lastGeneralInspection'] = $lastHu;   // letzte HU (c_hu - 24 Mon.)
    if ($nextService) $vehicle['nextServiceDate']       = $nextService; // nächste Inspektion (c_wd)

    $payload = [
        'referenceId' => 'FZG_' . ($cId > 0 ? $cId : $vin),
        'voucherType' => [
            'referenceId' => '2',
            'description' => 'Fahrzeug',
            'countryCode' => 'DE'
        ],
        'vehicle' => $vehicle
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
 * Leichtgewichtiger Motor-Sync: liest NUR den aktuell im AAG-Beleg (FZG_<c_id>)
 * hinterlegten Motorkennbuchstaben aus (= im Portal gewähltes Fahrzeug) und merged
 * ihn in installed_engines. Kein erneuter Import/Ktype-Vorgang — gedacht für den
 * häufigen Aufruf beim Zurückwechseln aus dem Portal.
 *
 * @param mixed $data['c_id'] Fahrzeug-ID
 * @param mixed $data['seed'] Optional: der beim Button gesendete Motorcode (= c_mkb).
 *                            AAG echo't gesendete engineCodes zurück; ist der Beleg-Code
 *                            gleich dem Seed, ist es nur das Echo (KEINE Portal-Auswahl)
 *                            und wird ignoriert. Nur ein abweichender Code zählt als
 *                            echte Auswahl und wird übernommen/gemerged.
 * @testdata {"c_id": 7543}
 */
function getAagEngine($data) {
    $cId = intval($data['c_id'] ?? 0);
    if ($cId <= 0) {
        resultInfo(false, 'INVALID_CAR_ID', ['message' => 'Ungültige Fahrzeug-ID']);
        return;
    }
    $seed = trim($data['seed'] ?? '');
    $company = DbhCompany::begin();
    $exp = aagPostJson($company, AAG_GSI_EXPORT_URL, ['referenceId' => 'FZG_' . $cId]);
    $raw = ($exp['status'] === 200) ? trim($exp['data']['vehicle']['engineCode'] ?? '') : '';
    // Echte Auswahl = Beleg-Code, der nicht dem gesendeten Seed (Echo) entspricht.
    $selected = ($raw !== '' && ($seed === '' || strcasecmp($raw, $seed) !== 0)) ? $raw : '';
    $engines = $selected !== ''
        ? mergeInstalledEngines($company, $cId, [$selected])
        : currentInstalledEngines($company, $cId);
    resultInfo(true, '', [
        'engine_code'       => $selected,  // echte Portal-Auswahl (oder '' = nur Echo/keine)
        'installed_engines' => $engines,
    ]);
}

/**
 * Speichert den Ktype aus einem ExportVoucher-vehicleType in cars_lxcars,
 * sofern eindeutig (id > 0), und gibt die Erfolgsantwort aus.
 *
 * @return bool true wenn gespeichert (Antwort wurde bereits gesendet)
 */
function aagPersistKtype($company, $cId, $vehicle, $source, $vin = '') {
    $vehicleType = $vehicle['vehicleType'] ?? [];
    $ktype = intval($vehicleType['id'] ?? 0);
    if ($ktype <= 0) return false;
    $desc = trim($vehicleType['description'] ?? '');
    $company->execute(
        "UPDATE cars_lxcars SET c_ktype = :k, c_ktype_desc = :d WHERE c_id = :c_id",
        ['k' => $ktype, 'd' => $desc, 'c_id' => $cId]
    );

    // Motorkennbuchstaben sammeln und in installed_engines mergen:
    //  1. engineCode aus DIESEM Export — das ist das im Portal gewählte/identifizierte
    //     Fahrzeug (erfasst eine vom Benutzer in AAG-Online getroffene Motorauswahl),
    //  2. zusätzlich der per VIN-Decodierung ermittelte Motorcode.
    // Mehr als diese liefert die GSI-API nicht (Vehicle-Objekt hat genau ein engineCode;
    // eine Mehrfachauswahl trifft der Benutzer im Portal — siehe GSI-Doku).
    $codes = [];
    $exportCode = trim($vehicle['engineCode'] ?? '');
    if ($exportCode !== '') $codes[] = $exportCode;
    if ($vin !== '') {
        $vinCode = aagEngineCodeByVin($company, $cId, $vin);
        if ($vinCode !== '') $codes[] = $vinCode;
    }
    $engines = mergeInstalledEngines($company, $cId, $codes);

    resultInfo(true, '', [
        'c_ktype'           => $ktype,
        'c_ktype_desc'      => $desc,
        'source'            => $source,
        'installed_engines' => $engines,
        // Der im Export hinterlegte Motor = im Portal gewähltes/identifiziertes
        // Fahrzeug. Das Frontend übernimmt ihn bei Rückkehr aus dem Portal als c_mkb.
        'engine_code'       => $exportCode,
    ]);
    return true;
}

/**
 * Liest die gespeicherten Motorkennbuchstaben eines Fahrzeugs (installed_engines).
 */
function currentInstalledEngines($company, $cId) {
    $row = $company->getOne("SELECT installed_engines FROM cars_lxcars WHERE c_id = :c_id", ['c_id' => $cId]);
    return trim($row['installed_engines'] ?? '');
}

/**
 * Fügt neue Motorkennbuchstaben (ohne Dubletten) zu installed_engines hinzu und
 * gibt die aktualisierte, komma-separierte Liste zurück.
 *
 * @param array $newCodes Neue Codes (werden getrimmt/dedupliziert)
 */
function mergeInstalledEngines($company, $cId, array $newCodes) {
    $current = currentInstalledEngines($company, $cId);
    $list = array_values(array_filter(array_map('trim', explode(',', $current)), fn($e) => $e !== ''));
    foreach ($newCodes as $c) {
        $c = trim($c);
        if ($c !== '' && !in_array($c, $list, true)) $list[] = $c;
    }
    $joined = implode(', ', $list);
    if ($joined !== $current) {
        $company->execute(
            "UPDATE cars_lxcars SET installed_engines = :e WHERE c_id = :c_id",
            ['e' => $joined, 'c_id' => $cId]
        );
    }
    return $joined;
}

/**
 * Ermittelt den verbauten Motorkennbuchstaben per VIN-Decodierung aus AAG-Online.
 *
 * Wichtig: Die VIN muss OHNE KBA-Nummer übertragen werden — sobald registrationNo
 * mitgesendet wird, identifiziert AAG über die KBA und liefert keinen engineCode.
 * Eigene referenceId (ENG_FZG_<c_id>), damit der Vorgang den FZG_<c_id>-Beleg des
 * AAG-Buttons (= Portal-Auswahl) nicht überschreibt.
 *
 * @return string Motorcode oder '' wenn AAG keinen liefert
 */
function aagEngineCodeByVin($company, $cId, $vin) {
    $ref = 'ENG_FZG_' . $cId;
    $imp = aagPostJson($company, AAG_GSI_IMPORT_URL, [
        'referenceId' => $ref,
        'voucherType' => ['referenceId' => '2', 'description' => 'Fahrzeug'],
        'vehicle'     => [
            'referenceId'             => $vin,
            'vehicleType'             => ['type' => 1],
            'vin'                     => $vin,
            'registrationInformation' => ['countryCode' => 'DE'],
        ],
    ]);
    if ($imp['status'] !== 200) return '';
    $exp = aagPostJson($company, AAG_GSI_EXPORT_URL, ['referenceId' => $ref]);
    if ($exp['status'] !== 200) return '';
    return trim($exp['data']['vehicle']['engineCode'] ?? '');
}

/**
 * Ermittelt die TecDoc-Ktype-Nummer eines Fahrzeugs über AAG-Online und
 * speichert sie (mit Klartext-Beschreibung) in cars_lxcars.
 *
 * Nutzt denselben Vorgang wie der AAG-Button (referenceId FZG_<c_id>):
 *  1. ExportVoucher zuerst – hat der Benutzer im Portal bereits ein Fahrzeug
 *     gewählt (z.B. bei mehrdeutiger FIN: 118 vs. 125 kW), wird genau diese
 *     Auswahl übernommen.
 *  2. Sonst headless versuchen: KBA (HSN+TSN) falls gültig, sonst FIN.
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
        "SELECT c_2, c_3, c_fin FROM cars_lxcars WHERE c_id = :c_id",
        ['c_id' => $cId]
    );
    if (!$car) {
        resultInfo(false, 'CAR_NOT_FOUND', ['message' => 'Fahrzeug nicht gefunden']);
        return;
    }

    $vin = strtoupper(trim($car['c_fin'] ?? ''));

    // Gemeinsamer Vorgang mit dem AAG-Button, damit eine im Portal getroffene
    // manuelle Fahrzeugauswahl hier übernommen werden kann.
    $referenceId = 'FZG_' . $cId;

    // 1. Bereits im Katalog ausgewähltes Fahrzeug übernehmen (manuelle Auswahl)
    $exp = aagPostJson($company, AAG_GSI_EXPORT_URL, ['referenceId' => $referenceId]);
    if ($exp['status'] === 200
        && aagPersistKtype($company, $cId, $exp['data']['vehicle'] ?? [], 'selection', $vin)) {
        return;
    }

    // 2. Sonst headless identifizieren: bevorzugt KBA (zuverlässiger), sonst FIN
    $hsn = trim($car['c_2'] ?? '');
    $tsn = trim($car['c_3'] ?? '');
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

    $imp = aagPostJson($company, AAG_GSI_IMPORT_URL, [
        'referenceId' => $referenceId,
        'voucherType' => ['referenceId' => '1', 'description' => 'Fahrzeugidentifikation'],
        'vehicle'     => $vehicle
    ]);
    if ($imp['status'] !== 200) {
        resultInfo(false, 'AAG_IMPORT_FAILED', ['message' => $imp['error'] ?: ('HTTP ' . $imp['status'])]);
        return;
    }

    $exp2 = aagPostJson($company, AAG_GSI_EXPORT_URL, ['referenceId' => $referenceId]);
    if ($exp2['status'] !== 200) {
        resultInfo(false, 'AAG_EXPORT_FAILED', ['message' => $exp2['error'] ?: ('HTTP ' . $exp2['status'])]);
        return;
    }

    if (aagPersistKtype($company, $cId, $exp2['data']['vehicle'] ?? [], $hasKba ? 'kba' : 'vin', $vin)) {
        return;
    }

    // Kein eindeutiger Treffer (mehrere Fahrzeuge / nicht gefunden) → nichts speichern.
    // Wählt der Benutzer das Fahrzeug im Portal, wird es beim nächsten Aufruf (Schritt 1) übernommen.
    resultInfo(false, 'NO_UNIQUE_MATCH', ['message' => 'Kein eindeutiges Fahrzeug ermittelt']);
}
