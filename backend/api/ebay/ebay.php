<?php
// backend/api/ebay/ebay.php
//
// eBay-Sell-API-Anbindung: OAuth-Token (Refresh -> Access) mit Cache in
// defaults_oserp und ein schlanker GET-Helper. Muster analog aag_online.php.
// Wird sowohl vom HTTP-Modul (orders.php) als auch vom CLI-Sweep genutzt.

const EBAY_OAUTH_PROD     = 'https://api.ebay.com/identity/v1/oauth2/token';
const EBAY_OAUTH_SANDBOX  = 'https://api.sandbox.ebay.com/identity/v1/oauth2/token';
const EBAY_API_PROD       = 'https://api.ebay.com';
const EBAY_API_SANDBOX    = 'https://api.sandbox.ebay.com';
const EBAY_SCOPE_ORDERS   = 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly';
const EBAY_SCOPE_INVENTORY = 'https://api.ebay.com/oauth/api_scope/sell.inventory';
// Angeforderte Scopes: Bestellungen lesen (Inbound) + Inventar schreiben (Outbound-Listing).
const EBAY_SCOPES         = EBAY_SCOPE_ORDERS . ' ' . EBAY_SCOPE_INVENTORY;
const EBAY_TOKEN_TTL      = 7200; // Fallback (2 h), falls eBay kein expires_in liefert
const EBAY_TOKEN_SKEW     = 300;  // Sicherheitspuffer (5 Min.) vor Ablauf

/**
 * Liest alle ebay_*-Schluessel aus defaults_oserp als assoziatives Array.
 *
 * @param object $db DbhCompany-Handle
 * @return array key => value
 */
function ebayLoadConfig($db) {
    $rows = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'ebay\\_%' ESCAPE '\\'") ?: [];
    $cfg = [];
    foreach ($rows as $r) {
        $cfg[$r['key']] = $r['value'];
    }
    return $cfg;
}

/**
 * Schreibt/aktualisiert einen Laufzeit-Wert in defaults_oserp (Upsert).
 *
 * @param object $db   DbhCompany-Handle
 * @param string $key  Schluessel
 * @param string $value Wert
 */
function ebaySetConfig($db, $key, $value) {
    $db->execute(
        "INSERT INTO defaults_oserp (key, value, mtime) VALUES (:key, :value, now())
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
        [':key' => $key, ':value' => (string)$value]
    );
}

/**
 * Ist die eBay-Anbindung fuer diesen Mandanten aktiv?
 *
 * @param array $cfg Ergebnis von ebayLoadConfig
 * @return bool
 */
function ebayIsEnabled($cfg) {
    return in_array($cfg['ebay_enabled'] ?? '', ['1', 'true', 't', 'on'], true);
}

/**
 * Basis-URL der eBay-REST-API je nach Umgebung (sandbox|production).
 */
function ebayApiBase($cfg) {
    return (($cfg['ebay_environment'] ?? 'production') === 'sandbox') ? EBAY_API_SANDBOX : EBAY_API_PROD;
}

/**
 * Holt ein gueltiges Access-Token (Refresh-Token-Grant) und cacht es in
 * defaults_oserp (ebay_access_token / ebay_access_token_exp), analog aagGetToken.
 *
 * @param object $db           DbhCompany-Handle
 * @param bool   $forceRefresh Cache ignorieren
 * @return string Access-Token
 * @throws ApiError wenn Zugangsdaten fehlen oder der Refresh fehlschlaegt
 */
function ebayGetToken($db, $forceRefresh = false) {
    $cfg = ebayLoadConfig($db);

    if (!$forceRefresh
        && !empty($cfg['ebay_access_token'])
        && intval($cfg['ebay_access_token_exp'] ?? 0) > time() + EBAY_TOKEN_SKEW) {
        return $cfg['ebay_access_token'];
    }

    $clientId = trim($cfg['ebay_client_id'] ?? '');
    $secret   = trim($cfg['ebay_client_secret'] ?? '');
    $refresh  = trim($cfg['ebay_refresh_token'] ?? '');

    if ($clientId === '' || $secret === '' || $refresh === '') {
        throw new ApiError('EBAY_NO_CREDENTIALS', 'eBay-Zugangsdaten sind nicht konfiguriert (Einstellungen → eBay)');
    }

    $url = (($cfg['ebay_environment'] ?? 'production') === 'sandbox') ? EBAY_OAUTH_SANDBOX : EBAY_OAUTH_PROD;

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($clientId . ':' . $secret),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'scope'         => EBAY_SCOPES,
        ]),
    ]);

    $response = curl_exec($curl);
    $err      = curl_error($curl);
    curl_close($curl);

    if ($err) {
        throw new ApiError('EBAY_AUTH_FAILED', 'eBay-Token konnte nicht abgerufen werden: ' . $err);
    }

    $decoded = json_decode($response, true);
    if (empty($decoded['access_token'])) {
        $detail = $decoded['error_description'] ?? ($decoded['error'] ?? 'unbekannter Fehler');
        throw new ApiError('EBAY_AUTH_FAILED', 'eBay-Token-Refresh fehlgeschlagen: ' . $detail);
    }

    $token = $decoded['access_token'];
    $ttl   = intval($decoded['expires_in'] ?? 0);
    if ($ttl <= 0) $ttl = EBAY_TOKEN_TTL;
    $exp   = time() + $ttl;

    ebaySetConfig($db, 'ebay_access_token', $token);
    ebaySetConfig($db, 'ebay_access_token_exp', (string)$exp);

    return $token;
}

/**
 * Fuehrt einen GET gegen die eBay-REST-API aus. Bei 401 wird das Token einmal
 * erneuert und der Aufruf wiederholt.
 *
 * @param object $db    DbhCompany-Handle
 * @param string $path  z. B. '/sell/fulfillment/v1/order'
 * @param array  $query Query-Parameter
 * @param bool   $retry Intern: noch ein Retry erlaubt?
 * @return array Dekodierte JSON-Antwort
 * @throws ApiError bei HTTP-Fehlern
 */
function ebayApiGet($db, $path, $query = [], $retry = true) {
    $cfg   = ebayLoadConfig($db);
    $base  = ebayApiBase($cfg);
    $token = ebayGetToken($db);
    $url   = $base . $path . (empty($query) ? '' : ('?' . http_build_query($query)));

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if (!empty($cfg['ebay_marketplace_id'])) {
        $headers[] = 'X-EBAY-C-MARKETPLACE-ID: ' . $cfg['ebay_marketplace_id'];
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err      = curl_error($curl);
    curl_close($curl);

    if ($err) {
        throw new ApiError('EBAY_API_ERROR', 'eBay-API nicht erreichbar: ' . $err);
    }

    if ($httpCode === 401 && $retry) {
        ebayGetToken($db, true); // Token erneuern
        return ebayApiGet($db, $path, $query, false);
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        $detail = $decoded['errors'][0]['message'] ?? ('HTTP ' . $httpCode);
        throw new ApiError('EBAY_API_ERROR', 'eBay-API-Fehler: ' . $detail);
    }

    return is_array($decoded) ? $decoded : [];
}

/**
 * Schreibender Aufruf (POST/PUT/DELETE) gegen die eBay-REST-API. Anders als
 * ebayApiGet wirft diese Funktion bei 4xx NICHT, sondern liefert Statuscode +
 * Body zurueck — der Aufrufer wertet eBay-Validierungsfehler selbst aus
 * (z. B. fehlende Pflicht-Aspekte beim Listing). Bei 401 wird das Token einmal
 * erneuert und wiederholt.
 *
 * @param object $db     DbhCompany-Handle
 * @param string $method 'POST'|'PUT'|'DELETE'
 * @param string $path   z. B. '/sell/inventory/v1/offer'
 * @param array|null $body JSON-Body (oder null)
 * @param bool   $retry  Intern
 * @return array ['status' => int, 'body' => array]
 * @throws ApiError nur bei Transportfehlern
 */
function ebayApiSend($db, $method, $path, $body = null, $retry = true) {
    $cfg   = ebayLoadConfig($db);
    $base  = ebayApiBase($cfg);
    $token = ebayGetToken($db);
    $url   = $base . $path;

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
        'Content-Language: ' . ($cfg['ebay_content_language'] ?? 'de-DE'),
    ];
    if (!empty($cfg['ebay_marketplace_id'])) {
        $headers[] = 'X-EBAY-C-MARKETPLACE-ID: ' . $cfg['ebay_marketplace_id'];
    }

    $opts = [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, $opts);
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err      = curl_error($curl);
    curl_close($curl);

    if ($err) {
        throw new ApiError('EBAY_API_ERROR', 'eBay-API nicht erreichbar: ' . $err);
    }

    if ($httpCode === 401 && $retry) {
        ebayGetToken($db, true);
        return ebayApiSend($db, $method, $path, $body, false);
    }

    $decoded = ($response === '' || $response === false) ? [] : json_decode($response, true);
    return ['status' => $httpCode, 'body' => is_array($decoded) ? $decoded : []];
}

/**
 * Extrahiert eine lesbare Fehlermeldung aus einer eBay-Fehlerantwort.
 *
 * @param array $resp Ergebnis von ebayApiSend
 * @return string
 */
function ebayErrorMessage($resp) {
    $errors = $resp['body']['errors'] ?? [];
    $parts = [];
    foreach ($errors as $e) {
        $msg = $e['message'] ?? '';
        $long = $e['longMessage'] ?? '';
        $parts[] = trim($long !== '' ? $long : $msg);
    }
    if (empty($parts)) {
        return 'HTTP ' . ($resp['status'] ?? '?');
    }
    return implode(' | ', array_filter($parts));
}
