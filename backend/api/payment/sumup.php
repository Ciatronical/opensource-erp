<?php
// backend/api/payment/sumup.php
//
// SumUp Cloud API: Betrag an einen gekoppelten SumUp-Reader (Solo-Klasse)
// "pushen" und den Reader einmalig mit dem Account koppeln.
// Zugangsdaten (API-Key, Merchant-Code, gekoppelter Reader) liegen in
// defaults_oserp (Einstellungen -> CRM-Defaults -> SumUp).
//
// Doku: https://developer.sumup.com/terminal-payments/cloud-api

const SUMUP_API_BASE = 'https://api.sumup.com/v0.1';

/**
 * Lädt die SumUp-Konfiguration aus defaults_oserp.
 *
 * @param object $db DbhCompany-Handle
 * @return array mit enabled, api_key, merchant_code, reader_id, reader_name
 */
function _sumupConfig($db) {
    $cfg = $db->getOne(
        "SELECT
            MAX(value) FILTER (WHERE key = 'sumup_enabled')       AS enabled,
            MAX(value) FILTER (WHERE key = 'sumup_api_key')       AS api_key,
            MAX(value) FILTER (WHERE key = 'sumup_merchant_code') AS merchant_code,
            MAX(value) FILTER (WHERE key = 'sumup_reader_id')     AS reader_id,
            MAX(value) FILTER (WHERE key = 'sumup_reader_name')   AS reader_name
         FROM defaults_oserp
         WHERE key IN ('sumup_enabled', 'sumup_api_key', 'sumup_merchant_code', 'sumup_reader_id', 'sumup_reader_name')"
    );
    return $cfg ?: [];
}

/**
 * Prüft, ob ein Checkbox-/Boolean-Wert aus defaults_oserp "wahr" ist.
 */
function _sumupTruthy($value): bool {
    return in_array((string)($value ?? ''), ['t', 'true', '1'], true);
}

/**
 * Führt einen HTTPS-Request gegen die SumUp Cloud API aus.
 *
 * @param string     $method HTTP-Methode (POST, DELETE, ...)
 * @param string     $path   Pfad ab SUMUP_API_BASE (z.B. "/merchants/MC/readers")
 * @param string     $apiKey Bearer-Token
 * @param array|null $body   JSON-Body (optional)
 * @return array mit status (HTTP-Code) und body (dekodiertes JSON)
 * @throws ApiError bei Verbindungsfehlern
 */
function _sumupRequest($method, $path, $apiKey, $body = null) {
    $curl = curl_init(SUMUP_API_BASE . $path);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $apiKey];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($curl, $opts);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        throw new ApiError('SUMUP_CONNECTION_FAILED', 'Verbindung zu SumUp fehlgeschlagen: ' . $err);
    }

    return ['status' => $httpCode, 'body' => json_decode($response, true)];
}

/**
 * Liest eine Fehlermeldung aus einer SumUp-Antwort.
 */
function _sumupErrorMessage($res, $fallback) {
    $body = $res['body'] ?? [];
    return $body['message'] ?? ($body['error_message'] ?? $fallback);
}

/**
 * Gibt den aktuellen Kopplungs-Status zurück (für die Konfigurations-UI).
 *
 * @param array $data unbenutzt
 * @return void JSON
 * @testdata {}
 */
function getSumupReaderInfo($data) {
    $db = DbhCompany::begin();
    $cfg = _sumupConfig($db);

    resultInfo(true, '', [
        'enabled'       => _sumupTruthy($cfg['enabled'] ?? ''),
        'has_api_key'   => !empty($cfg['api_key']),
        'merchant_code' => $cfg['merchant_code'] ?? '',
        'reader_id'     => $cfg['reader_id'] ?? '',
        'reader_name'   => $cfg['reader_name'] ?? '',
    ]);
}

/**
 * Koppelt einen SumUp-Reader mit dem Account.
 *
 * Am Reader (Solo) unter Einstellungen -> Verbindungen -> API einen
 * Kopplungscode erzeugen und hier eingeben. Die zurückgelieferte Reader-ID
 * wird in defaults_oserp gespeichert.
 *
 * @param array $data ['pairing_code' => 'ABCDEFGH', 'name' => 'Terminal Kasse']
 * @return void JSON
 * @testdata {"pairing_code": "ABCDEFGH", "name": "Terminal"}
 */
function pairSumupReader($data) {
    $db = DbhCompany::begin();
    $cfg = _sumupConfig($db);

    $apiKey   = trim($cfg['api_key'] ?? '');
    $merchant = trim($cfg['merchant_code'] ?? '');
    if ($apiKey === '' || $merchant === '') {
        resultInfo(false, 'SUMUP_NOT_CONFIGURED', 'Bitte zuerst API-Schlüssel und Merchant-Code speichern.');
        return;
    }

    $pairingCode = strtoupper(trim($data['pairing_code'] ?? ''));
    if ($pairingCode === '') {
        resultInfo(false, 'SUMUP_NO_PAIRING_CODE', 'Bitte den Kopplungscode vom Terminal eingeben.');
        return;
    }
    $name = trim($data['name'] ?? '') ?: 'Terminal';

    $res = _sumupRequest('POST', "/merchants/{$merchant}/readers", $apiKey, [
        'pairing_code' => $pairingCode,
        'name'         => $name,
    ]);

    if ($res['status'] < 200 || $res['status'] > 299) {
        resultInfo(false, 'SUMUP_PAIR_FAILED', 'SumUp (HTTP ' . $res['status'] . '): ' . _sumupErrorMessage($res, 'Kopplung fehlgeschlagen'));
        return;
    }

    $reader = $res['body'] ?? [];
    $readerId = $reader['id'] ?? '';
    $readerName = $reader['name'] ?? $name;
    if ($readerId === '') {
        resultInfo(false, 'SUMUP_PAIR_FAILED', 'SumUp lieferte keine Reader-ID zurück.');
        return;
    }

    $db->execute(
        "INSERT INTO defaults_oserp (key, value, mtime) VALUES
            ('sumup_reader_id', :id, now()),
            ('sumup_reader_name', :name, now())
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
        ['id' => $readerId, 'name' => $readerName]
    );

    resultInfo(true, '', ['reader_id' => $readerId, 'reader_name' => $readerName]);
}

/**
 * Entkoppelt den gespeicherten SumUp-Reader (lokal und bei SumUp).
 *
 * @param array $data unbenutzt
 * @return void JSON
 * @testdata {}
 */
function unpairSumupReader($data) {
    $db = DbhCompany::begin();
    $cfg = _sumupConfig($db);

    $apiKey   = trim($cfg['api_key'] ?? '');
    $merchant = trim($cfg['merchant_code'] ?? '');
    $readerId = trim($cfg['reader_id'] ?? '');

    if ($readerId !== '' && $apiKey !== '' && $merchant !== '') {
        // Best effort: bei SumUp löschen, lokale Bereinigung darf nicht daran scheitern
        try { _sumupRequest('DELETE', "/merchants/{$merchant}/readers/{$readerId}", $apiKey); }
        catch (\Throwable $e) { /* ignorieren */ }
    }

    $db->execute("DELETE FROM defaults_oserp WHERE key IN ('sumup_reader_id', 'sumup_reader_name')");
    resultInfo(true, '', ['unpaired' => true]);
}

/**
 * Sendet einen Betrag an den gekoppelten SumUp-Reader (Karten-Checkout).
 *
 * Der Reader zeigt den Betrag an und wartet auf die Karte. Das endgültige
 * Zahlungsergebnis liefert SumUp asynchron per Webhook — synchron kommt nur
 * die Bestätigung, dass der Checkout am Terminal gestartet wurde.
 *
 * @param array $data ['amount' => 99.90, 'currency' => 'EUR', 'description' => 'RG 2026-001', 'fakturaID' => 123]
 * @return void JSON
 * @testdata {"amount": 9.90, "currency": "EUR", "description": "Testrechnung"}
 */
function sumupCheckout($data) {
    $db = DbhCompany::begin();
    $cfg = _sumupConfig($db);

    if (!_sumupTruthy($cfg['enabled'] ?? '')) {
        resultInfo(false, 'SUMUP_DISABLED', 'SumUp ist nicht aktiviert.');
        return;
    }

    $apiKey   = trim($cfg['api_key'] ?? '');
    $merchant = trim($cfg['merchant_code'] ?? '');
    $readerId = trim($cfg['reader_id'] ?? '');
    if ($apiKey === '' || $merchant === '') {
        resultInfo(false, 'SUMUP_NOT_CONFIGURED', 'SumUp API-Schlüssel oder Merchant-Code fehlt.');
        return;
    }
    if ($readerId === '') {
        resultInfo(false, 'SUMUP_NO_READER', 'Es ist kein SumUp-Terminal gekoppelt.');
        return;
    }

    $amount = round((float)($data['amount'] ?? 0), 2);
    if ($amount <= 0) {
        resultInfo(false, 'SUMUP_INVALID_AMOUNT', 'Der Betrag muss größer als 0 sein.');
        return;
    }
    $currency = strtoupper(trim($data['currency'] ?? '')) ?: 'EUR';
    $description = trim($data['description'] ?? '');

    $body = [
        'total_amount' => [
            'currency'   => $currency,
            'minor_unit' => 2,
            'value'      => (int) round($amount * 100),
        ],
    ];
    if ($description !== '') {
        $body['description'] = $description;
    }

    $res = _sumupRequest('POST', "/merchants/{$merchant}/readers/{$readerId}/checkout", $apiKey, $body);

    if ($res['status'] >= 200 && $res['status'] <= 299) {
        resultInfo(true, '', [
            'sent'        => true,
            'amount'      => $amount,
            'currency'    => $currency,
            'reader_name' => $cfg['reader_name'] ?? $readerId,
            'response'    => $res['body'],
        ]);
        return;
    }

    resultInfo(false, 'SUMUP_CHECKOUT_FAILED', 'SumUp (HTTP ' . $res['status'] . '): ' . _sumupErrorMessage($res, 'Checkout fehlgeschlagen'));
}
