<?php
// backend/api/hgs_data.php
//
// HGS-Data-Anbindung (Hella Gutmann technische Fahrzeugdaten).
// HGS-Data adressiert Fahrzeuge über eine interne vehicleId, die per
// HSN/TSN-Suche aufgelöst werden muss. Die Such-API ist login-geschützt und
// liefert JSON. Das Login ist ein AJAX-POST (X-Requested-With) mit JSON-Antwort;
// die Session steckt im Cookie "HGSData". Das Backend meldet sich an, cached die
// Session (HGS limitiert gleichzeitige Sitzungen!), löst die vehicleId auf und
// liefert die car-data-URL zurück. Der Browser des Benutzers öffnet diese Seite.

const HGS_BASE        = 'https://www.hgs-data.com';
const HGS_LOGIN_URL   = HGS_BASE . '/index.php/auth/login';
const HGS_AUTH_URL    = HGS_BASE . '/index.php/auth';
const HGS_SEARCH_URL  = HGS_BASE . '/index.php/vehicle/'; // ?hsn=..&tsn=.. (3-stellig)
const HGS_COOKIE_NAME = 'HGSData';
const HGS_SESSION_TTL = 1036800; // 12 Tage Cache (HGS-Cookie hält 14 Tage)
const HGS_USER_AGENT  = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0 Safari/537.36';

/**
 * Liest Zugangsdaten + gecachte Session aus den Firmen-Defaults.
 */
function hgsCfg($db) {
    return $db->getOne(
        "SELECT
            MAX(value) FILTER (WHERE key = 'hgs_data_user')       AS hgs_user,
            MAX(value) FILTER (WHERE key = 'hgs_data_passwd')     AS hgs_passwd,
            MAX(value) FILTER (WHERE key = 'hgs_data_cookie')     AS hgs_cookie,
            MAX(value) FILTER (WHERE key = 'hgs_data_cookie_exp') AS hgs_cookie_exp
         FROM defaults_oserp
         WHERE key IN ('hgs_data_user', 'hgs_data_passwd', 'hgs_data_cookie', 'hgs_data_cookie_exp')"
    );
}

/**
 * Sendet einen Login-POST (AJAX → JSON-Antwort). Cookies landen in $jar.
 * @return array [http_code, decoded_json|null, raw_body]
 */
function hgsPostLogin($user, $passwd, $jar, $deleteSession = '') {
    $fields = ['username' => $user, 'password' => $passwd, 'remember_me' => 'on'];
    if ($deleteSession !== '') $fields['deleteSession'] = $deleteSession;

    $curl = curl_init(HGS_LOGIN_URL);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => HGS_USER_AGENT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json, text/javascript, */*',
            'Referer: ' . HGS_AUTH_URL,
        ],
    ]);
    $body = curl_exec($curl);
    $code = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
    curl_close($curl);
    return [$code, json_decode($body, true), (string)$body];
}

/**
 * Liest den Wert des HGSData-Cookies aus einer Netscape-Cookie-Datei.
 */
function hgsCookieFromJar($jar) {
    foreach (@file($jar) ?: [] as $line) {
        if ($line[0] === '#' && strpos($line, '#HttpOnly_') !== 0) continue;
        $f = explode("\t", trim($line));
        if (count($f) >= 7 && $f[5] === HGS_COOKIE_NAME && $f[6] !== '') {
            return HGS_COOKIE_NAME . '=' . $f[6];
        }
    }
    return '';
}

/**
 * Liefert ein gültiges HGS-Session-Cookie ("HGSData=...") — aus dem Cache oder
 * per Neuanmeldung. Caching ist wichtig, weil HGS-Data die Zahl gleichzeitiger
 * Sitzungen pro Account begrenzt; bei „too many sessions" werden die vom Portal
 * als löschbar angebotenen Sitzungen freigegeben.
 *
 * @throws ApiError bei fehlenden Zugangsdaten / Login-Fehler / Sitzungslimit
 */
function hgsGetCookie($db, $forceRefresh = false) {
    $cfg = hgsCfg($db);

    if (!$forceRefresh
        && !empty($cfg['hgs_cookie'])
        && intval($cfg['hgs_cookie_exp'] ?? 0) > time() + 300) {
        return $cfg['hgs_cookie'];
    }

    $user = trim($cfg['hgs_user'] ?? '');
    $passwd = trim($cfg['hgs_passwd'] ?? '');
    if ($user === '' || $passwd === '') {
        throw new ApiError('HGS_NO_CREDENTIALS', 'HGS-Data Zugangsdaten sind nicht konfiguriert (Einstellungen → LxCars → HGS-Data)');
    }

    $jar = tempnam(sys_get_temp_dir(), 'hgsj');
    [$code, $json, $body] = hgsPostLogin($user, $passwd, $jar);

    // „Too many sessions" → angebotene Sitzungen freigeben und erneut anmelden.
    $guard = 0;
    while ($code !== 200 && is_array($json)
        && stripos($json['message'] ?? '', 'session') !== false && $guard++ < 5) {
        if (!preg_match('/value="([0-9a-f]{16,})"/', $json['deletableSessions'] ?? '', $sid)) break;
        [$code, $json, $body] = hgsPostLogin($user, $passwd, $jar, $sid[1]);
    }

    if ($code !== 200) {
        @unlink($jar);
        $msg = (is_array($json) && !empty($json['message'])) ? $json['message'] : ('HTTP ' . $code);
        if (stripos($msg, 'session') !== false) {
            throw new ApiError('HGS_TOO_MANY_SESSIONS', 'HGS-Data: zu viele offene Sitzungen für diesen Account. Bitte an anderen Geräten/Browsern abmelden und erneut versuchen.');
        }
        throw new ApiError('HGS_LOGIN_FAILED', 'HGS-Data Login fehlgeschlagen: ' . $msg);
    }

    $cookie = hgsCookieFromJar($jar);
    @unlink($jar);
    if ($cookie === '') {
        throw new ApiError('HGS_LOGIN_FAILED', 'HGS-Data Login: keine Session erhalten');
    }

    $exp = time() + HGS_SESSION_TTL;
    $db->execute(
        "INSERT INTO defaults_oserp (key, value, mtime) VALUES
            ('hgs_data_cookie', :c, now()),
            ('hgs_data_cookie_exp', :e, now())
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
        ['c' => $cookie, 'e' => (string)$exp]
    );

    return $cookie;
}

/**
 * Ruft die HGS-Such-API auf und gibt den decodierten JSON-Body zurück (oder null).
 */
function hgsSearch($cookie, $hsn, $tsn3) {
    $url = HGS_SEARCH_URL . '?' . http_build_query(['hsn' => $hsn, 'tsn' => $tsn3]);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => HGS_USER_AGENT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIE         => $cookie,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'],
    ]);
    $resp = curl_exec($curl);
    curl_close($curl);
    return json_decode($resp, true);
}

/**
 * Öffnet ein Fahrzeug in HGS-Data: löst per HSN/TSN-Suche die interne vehicleId
 * auf und liefert die car-data-URL zurück (vom Browser zu öffnen).
 *
 * @param mixed $data['c_id'] Fahrzeug-ID (cars_lxcars.c_id)
 * @testdata {"c_id": 6471}
 */
function getHgsVehicleUrl($data) {
    $cId = intval($data['c_id'] ?? 0);
    if ($cId <= 0) {
        resultInfo(false, 'INVALID_CAR_ID', ['message' => 'Ungültige Fahrzeug-ID']);
        return;
    }

    $company = DbhCompany::begin();
    $car = $company->getOne("SELECT c_2, c_3 FROM cars_lxcars WHERE c_id = :c_id", ['c_id' => $cId]);
    if (!$car) {
        resultInfo(false, 'CAR_NOT_FOUND', ['message' => 'Fahrzeug nicht gefunden']);
        return;
    }

    $hsn = trim($car['c_2'] ?? '');
    $tsn = trim($car['c_3'] ?? '');
    // HGS-Data erwartet die 3-stellige TSN (unser c_3 enthält oft den längeren KBA-Schlüssel)
    $tsn3 = substr($tsn, 0, 3);
    if (!preg_match('/^\d{4}$/', $hsn) || strlen($tsn3) < 3 || substr($tsn3, 0, 3) === '000') {
        resultInfo(false, 'NO_KBA', ['message' => 'Keine gültigen Schlüsselnummern (HSN/TSN) vorhanden']);
        return;
    }

    // Session holen (Cache → sonst Login), Suche mit einmaligem Re-Login bei Ablauf.
    try {
        $cookie = hgsGetCookie($company);
        $json = hgsSearch($cookie, $hsn, $tsn3);
        if (!is_array($json)) {
            $cookie = hgsGetCookie($company, true); // Session abgelaufen → neu anmelden
            $json = hgsSearch($cookie, $hsn, $tsn3);
        }
    } catch (ApiError $e) {
        resultInfo(false, $e->getCode(), ['message' => $e->getMessage()]);
        return;
    }

    if (!is_array($json)) {
        resultInfo(false, 'HGS_NOT_AUTHENTICATED', ['message' => 'HGS-Data Suche lieferte keine Daten (Session/Login prüfen)']);
        return;
    }

    // Antwort ist ein nach Pfad verschachteltes Objekt:
    //   { "/index.php/vehicle/2454": { "vehicleId": 2454, "carData": "...", ... } }
    // → erstes Element nehmen. Fallbacks: Einzelobjekt oder Liste.
    $first = is_array($json) ? reset($json) : null;
    $row = (is_array($first) && isset($first['vehicleId'])) ? $first
         : (isset($json['vehicleId']) ? $json
         : (isset($json[0]['vehicleId']) ? $json[0] : null));
    if (!$row || empty($row['vehicleId'])) {
        resultInfo(false, 'HGS_NO_VEHICLE', ['message' => 'HGS-Data hat kein Fahrzeug zu HSN/TSN ' . $hsn . '/' . $tsn3 . ' gefunden']);
        return;
    }

    $carData = trim($row['carData'] ?? '');
    $url = $carData !== ''
        ? (preg_match('#^https?://#', $carData) ? $carData : HGS_BASE . $carData)
        : HGS_BASE . '/index.php/index/car-data/vehicleId/' . intval($row['vehicleId']);

    resultInfo(true, '', ['portalUrl' => $url, 'vehicleId' => intval($row['vehicleId'])]);
}
