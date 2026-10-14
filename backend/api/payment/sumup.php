<?php
// backend/api/payment/sumup.php
//
// SumUp Cloud API: Betrag an einen gekoppelten SumUp-Reader (Solo-Klasse)
// "pushen" und den Reader einmalig mit dem Account koppeln.
// Zugangsdaten (API-Key, Merchant-Code, gekoppelter Reader) liegen in
// defaults_oserp (Einstellungen -> CRM-Defaults -> SumUp).
//
// Doku: https://developer.sumup.com/terminal-payments/cloud-api
// Checkout-Protokoll: Tabelle sumup_checkouts (backend/upstall/crm/company_schema.sql)

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
            MAX(value) FILTER (WHERE key = 'sumup_reader_name')   AS reader_name,
            -- Zuletzt gestarteter, noch offener Checkout (max. 3 Minuten alt):
            -- wird vor einem neuen Checkout am Terminal abgebrochen.
            (SELECT client_transaction_id FROM sumup_checkouts
              WHERE status = 'pending' AND itime > now() - interval '3 minutes'
              ORDER BY id DESC LIMIT 1) AS pending_client_transaction_id
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
    // Absolute URL erlaubt (z. B. v1.0-Auszahlungen), sonst relativ zur v0.1-Basis.
    $url  = str_starts_with($path, 'https://') ? $path : SUMUP_API_BASE . $path;
    $curl = curl_init($url);
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
    if (!is_array($body)) return $fallback;
    // Cloud API (Reader-Checkout): {"errors":{"detail":"The device is offline.","type":"READER_OFFLINE"}}
    if (!empty($body['errors']['detail'])) {
        $type = $body['errors']['type'] ?? '';
        return $body['errors']['detail'] . ($type ? ' [' . $type . ']' : '');
    }
    // Uebrige Endpunkte: {"message": "...", "error_code": "..."}
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
 * Der Reader zeigt den Betrag an und wartet auf die Karte. Synchron kommt nur
 * die Bestätigung, dass der Checkout am Terminal gestartet wurde, samt
 * client_transaction_id. Das Ergebnis fragt das Frontend anschließend per
 * sumupCheckoutStatus() ab (Polling gegen die Transaktions-API — braucht
 * keine öffentlich erreichbare Webhook-URL).
 *
 * Läuft am Terminal noch ein älterer Checkout (z. B. der automatisch beim
 * Öffnen gesendete Rechnungsbetrag), wird er zuerst abgebrochen, damit ein
 * abweichender Betrag (Anzahlung, Selbstbeteiligung) nicht abgewiesen wird.
 *
 * @param array $data ['amount' => 99.90, 'currency' => 'EUR', 'description' => 'RG 2026-001', 'fakturaID' => 123]
 * @return void JSON
 * @testdata {"amount": 9.90, "currency": "EUR", "description": "Testrechnung", "fakturaID": 1}
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

    // Noch offenen Checkout am Terminal verwerfen (best effort, asynchron bei SumUp)
    $pending = trim($cfg['pending_client_transaction_id'] ?? '');
    if ($pending !== '') {
        try { _sumupRequest('POST', "/merchants/{$merchant}/readers/{$readerId}/terminate", $apiKey); }
        catch (\Throwable $e) { /* ignorieren */ }
        $db->execute(
            "UPDATE sumup_checkouts SET status = 'cancelled', mtime = now() WHERE client_transaction_id = :ctid AND status = 'pending'",
            ['ctid' => $pending]
        );
    }

    $res = _sumupRequest('POST', "/merchants/{$merchant}/readers/{$readerId}/checkout", $apiKey, $body);

    if ($res['status'] >= 200 && $res['status'] <= 299) {
        $resData = $res['body']['data'] ?? $res['body'] ?? [];
        $clientTransactionId = (string)($resData['client_transaction_id'] ?? '');
        $fakturaId = intval($data['fakturaID'] ?? 0) ?: null;

        if ($clientTransactionId !== '') {
            $db->execute(
                "INSERT INTO sumup_checkouts (trans_id, amount, currency, description, client_transaction_id, checkout_id, status, employee_id)
                 VALUES (:trans_id, :amount, :currency, :description, :ctid, :checkout_id, 'pending', :employee_id)",
                [
                    'trans_id'    => $fakturaId,
                    'amount'      => $amount,
                    'currency'    => $currency,
                    'description' => $description,
                    'ctid'        => $clientTransactionId,
                    'checkout_id' => (string)($resData['checkout_id'] ?? '') ?: null,
                    'employee_id' => mitarbeiterId($data),
                ]
            );
        }

        resultInfo(true, '', [
            'sent'                  => true,
            'amount'                => $amount,
            'currency'              => $currency,
            'reader_name'           => $cfg['reader_name'] ?? $readerId,
            'client_transaction_id' => $clientTransactionId,
        ]);
        return;
    }

    // Haeufigste Ursache: Terminal offline (WLAN weg, Akku leer). Den Reader-
    // Status an die Meldung haengen, damit der Benutzer am Geraet nachsehen kann.
    $hint = '';
    try {
        $st = _sumupRequest('GET', "/merchants/{$merchant}/readers/{$readerId}/status", $apiKey);
        $d = $st['body']['data'] ?? null;
        if ($st['status'] === 200 && is_array($d)) {
            $hint = ' – Terminal ' . (($d['status'] ?? '') === 'ONLINE' ? 'online' : 'OFFLINE')
                  . (isset($d['battery_level']) ? ', Akku ' . round((float)$d['battery_level']) . ' %' : '')
                  . (!empty($d['connection_type']) ? ', ' . $d['connection_type'] : '');
        }
    } catch (\Throwable $e) { /* Hinweis ist optional */ }

    resultInfo(false, 'SUMUP_CHECKOUT_FAILED', 'SumUp (HTTP ' . $res['status'] . '): ' . _sumupErrorMessage($res, 'Checkout fehlgeschlagen') . $hint);
}

/**
 * Fragt das Ergebnis eines laufenden Checkouts ab (Polling vom Faktura-Dialog).
 *
 * Solange die Zahlung läuft, kennt die Transaktions-API die
 * client_transaction_id noch nicht (404) — dann wird zusätzlich der
 * Reader-Status geholt, damit die Oberfläche "Karte vorlegen" / "PIN-Eingabe"
 * anzeigen kann. Ein abgeschlossenes Ergebnis wird in sumup_checkouts
 * festgehalten (Transaktionscode für den späteren Abgleich der Auszahlung).
 *
 * @param string $data['client_transaction_id'] Kennung aus sumupCheckout()
 * @return void JSON {status: pending|successful|failed|cancelled, reader_state, transaction_code, card_type, amount}
 * @testdata {"client_transaction_id": "00000000-0000-0000-0000-000000000000"}
 */
function sumupCheckoutStatus($data) {
    $db = DbhCompany::begin();
    $cfg = _sumupConfig($db);

    $apiKey   = trim($cfg['api_key'] ?? '');
    $merchant = trim($cfg['merchant_code'] ?? '');
    $readerId = trim($cfg['reader_id'] ?? '');
    $ctid     = trim($data['client_transaction_id'] ?? '');
    if ($apiKey === '' || $merchant === '' || $ctid === '') {
        resultInfo(false, 'SUMUP_NOT_CONFIGURED', 'SumUp ist nicht vollständig konfiguriert.');
        return;
    }

    $res = _sumupRequest('GET', 'https://api.sumup.com/v2.1/merchants/' . rawurlencode($merchant)
        . '/transactions?client_transaction_id=' . rawurlencode($ctid), $apiKey);

    if ($res['status'] === 200 && is_array($res['body'])) {
        $tx = $res['body'];
        $sumupStatus = strtoupper((string)($tx['status'] ?? 'PENDING'));
        $map = ['SUCCESSFUL' => 'successful', 'FAILED' => 'failed', 'CANCELLED' => 'cancelled', 'REFUNDED' => 'successful'];
        $status = $map[$sumupStatus] ?? 'pending';
        $transactionCode = (string)($tx['transaction_code'] ?? '');
        $cardType = (string)($tx['card']['type'] ?? ($tx['card_type'] ?? ''));

        if ($status !== 'pending') {
            // Kundenbeleg (Receipts API) mitspeichern: der Solo hat keinen
            // Bondrucker, der Beleg wird als Seite hinter die Rechnung gedruckt.
            $receipt = ($status === 'successful') ? _sumupFetchReceipt($apiKey, $merchant, (string)($tx['id'] ?? $transactionCode)) : null;
            $db->execute(
                "UPDATE sumup_checkouts
                    SET status = :status, transaction_code = :code, card_type = :card_type,
                        receipt = COALESCE(:receipt::jsonb, receipt), mtime = now()
                  WHERE client_transaction_id = :ctid",
                [
                    'status'    => $status,
                    'code'      => $transactionCode ?: null,
                    'card_type' => $cardType ?: null,
                    'receipt'   => $receipt ? json_encode($receipt, JSON_UNESCAPED_UNICODE) : null,
                    'ctid'      => $ctid,
                ]
            );
        }

        resultInfo(true, '', [
            'status'           => $status,
            'transaction_code' => $transactionCode,
            'card_type'        => $cardType,
            'amount'           => round((float)($tx['amount'] ?? 0), 2),
            'currency'         => (string)($tx['currency'] ?? ''),
            'timestamp'        => (string)($tx['timestamp'] ?? ''),
            'reader_state'     => '',
        ]);
        return;
    }

    if ($res['status'] !== 404) {
        resultInfo(false, 'SUMUP_STATUS_FAILED', 'SumUp (HTTP ' . $res['status'] . '): ' . _sumupErrorMessage($res, 'Statusabfrage fehlgeschlagen'));
        return;
    }

    // Noch keine Transaktion: Reader-Status für die Anzeige holen (best effort)
    $readerState = '';
    if ($readerId !== '') {
        try {
            $st = _sumupRequest('GET', "/merchants/{$merchant}/readers/{$readerId}/status", $apiKey);
            if ($st['status'] === 200) {
                $readerState = (string)($st['body']['data']['state'] ?? '');
                if (($st['body']['data']['status'] ?? '') === 'OFFLINE') $readerState = 'OFFLINE';
            }
        } catch (\Throwable $e) { /* Anzeige ist optional */ }
    }

    resultInfo(true, '', ['status' => 'pending', 'reader_state' => $readerState]);
}

/**
 * Holt den Kundenbeleg einer Transaktion (GET /v1.1/receipts/{id}?mid=...)
 * und reduziert ihn auf die Felder, die auf der Belegseite gedruckt werden.
 * Scheitert der Abruf, wird null geliefert — die Zahlung ist davon unberührt.
 *
 * @param string $apiKey        Bearer-Token
 * @param string $merchant      Merchant-Code
 * @param string $transactionId SumUp-Transaktions-ID oder Transaktionscode
 * @return array|null
 */
function _sumupFetchReceipt($apiKey, $merchant, $transactionId) {
    if ($transactionId === '') return null;
    try {
        $res = _sumupRequest('GET', 'https://api.sumup.com/v1.1/receipts/' . rawurlencode($transactionId)
            . '?mid=' . rawurlencode($merchant), $apiKey);
    } catch (\Throwable $e) {
        return null;
    }
    if ($res['status'] !== 200 || !is_array($res['body'])) return null;

    $td = $res['body']['transaction_data'] ?? [];
    $mp = $res['body']['merchant_data']['merchant_profile'] ?? [];
    $ad = $res['body']['acquirer_data'] ?? [];
    $ap = $res['body']['card_application_data'] ?? [];
    $addr = $mp['address'] ?? [];

    return [
        'transaction_code'    => (string)($td['transaction_code'] ?? ''),
        'receipt_no'          => (string)($td['receipt_no'] ?? ''),
        'amount'              => (string)($td['amount'] ?? ''),
        'tip_amount'          => (string)($td['tip_amount'] ?? ''),
        'currency'            => (string)($td['currency'] ?? 'EUR'),
        'timestamp'           => (string)($td['timestamp'] ?? ''),
        'status'              => (string)($td['status'] ?? ''),
        'entry_mode'          => (string)($td['entry_mode'] ?? ''),
        'verification_method' => (string)($td['verification_method'] ?? ''),
        'card_type'           => (string)($td['card']['type'] ?? ''),
        'card_last4'          => (string)($td['card']['last_4_digits'] ?? ''),
        'card_application'    => (string)($ap['name'] ?? ''),
        'aid'                 => (string)($ap['aid'] ?? ($res['body']['emv_data']['aid'] ?? '')),
        'reader_serial'       => (string)($td['card_reader']['code'] ?? ''),
        'tid'                 => (string)($ad['tid'] ?? ''),
        'authorization_code'  => (string)($ad['authorization_code'] ?? ''),
        'return_code'         => (string)($ad['return_code'] ?? ''),
        'merchant_name'       => (string)($mp['business_name'] ?? ''),
        'merchant_code'       => (string)($mp['merchant_code'] ?? $merchant),
        'merchant_address'    => trim(implode(', ', array_filter([
            trim((string)($addr['address_line1'] ?? '') . ' ' . (string)($addr['address_line2'] ?? '')),
            trim((string)($addr['post_code'] ?? '') . ' ' . (string)($addr['city'] ?? '')),
        ]))),
    ];
}

/**
 * Bricht den laufenden Checkout am Terminal ab (nur möglich, solange der
 * Reader auf Karte/PIN wartet). SumUp bestätigt den Abbruch nicht synchron.
 *
 * @param string $data['client_transaction_id'] Kennung aus sumupCheckout()
 * @return void JSON
 * @testdata {"client_transaction_id": "00000000-0000-0000-0000-000000000000"}
 */
function sumupTerminate($data) {
    $db = DbhCompany::begin();
    $cfg = _sumupConfig($db);

    $apiKey   = trim($cfg['api_key'] ?? '');
    $merchant = trim($cfg['merchant_code'] ?? '');
    $readerId = trim($cfg['reader_id'] ?? '');
    if ($apiKey === '' || $merchant === '' || $readerId === '') {
        resultInfo(false, 'SUMUP_NOT_CONFIGURED', 'SumUp ist nicht vollständig konfiguriert.');
        return;
    }

    $res = _sumupRequest('POST', "/merchants/{$merchant}/readers/{$readerId}/terminate", $apiKey);
    if ($res['status'] < 200 || $res['status'] > 299) {
        resultInfo(false, 'SUMUP_TERMINATE_FAILED', 'SumUp (HTTP ' . $res['status'] . '): ' . _sumupErrorMessage($res, 'Abbruch fehlgeschlagen'));
        return;
    }

    $ctid = trim($data['client_transaction_id'] ?? '');
    if ($ctid !== '') {
        $db->execute(
            "UPDATE sumup_checkouts SET status = 'cancelled', mtime = now() WHERE client_transaction_id = :ctid AND status = 'pending'",
            ['ctid' => $ctid]
        );
    }
    resultInfo(true, '', ['terminated' => true]);
}
