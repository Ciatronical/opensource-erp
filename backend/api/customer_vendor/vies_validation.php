<?php
// backend/api/customer_vendor/vies_validation.php

/**
 * VIES USt-ID Validierung über EU-API (mit cURL statt SOAP)
 *
 * Prüft eine EU-USt-ID auf Existenz über die offizielle VIES-API
 *
 * @param string $data['vatId'] EU-Umsatzsteuer-ID (z.B. DE123456789)
 * @testdata {"vatId": "DE123456789"}
 */
function checkVatId($data) {
    $vatId = $data['vatId'] ?? '';

    if (empty($vatId)) {
        resultInfo(false, 'VAT_ID_REQUIRED', ['error' => 'VAT ID is required']);
        return;
    }

    // Entferne Leerzeichen und Bindestriche
    $cleanVatId = str_replace([' ', '-'], '', strtoupper($vatId));

    // Länderkürzel und Nummer trennen
    $countryCode = substr($cleanVatId, 0, 2);
    $vatNumber = substr($cleanVatId, 2);

    if (strlen($countryCode) !== 2 || strlen($vatNumber) < 2) {
        resultInfo(false, 'INVALID_FORMAT', [
            'error' => 'Invalid VAT ID format',
            'vatId' => $vatId
        ]);
        return;
    }

    // SOAP XML Request (exakt wie im funktionierenden curl)
    $soapRequest = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:urn="urn:ec.europa.eu:taxud:vies:services:checkVat:types">
  <soap:Body>
    <urn:checkVat>
      <urn:countryCode>' . $countryCode . '</urn:countryCode>
      <urn:vatNumber>' . $vatNumber . '</urn:vatNumber>
    </urn:checkVat>
  </soap:Body>
</soap:Envelope>';

    // cURL Request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $soapRequest);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: ""'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // DEBUG: Zeige die Response
    //writeLog('VIES Response HTTP Code: ' . $httpCode);
    //writeLog('VIES Response Body: ' . substr($response, 0, 500)); // Erste 500 Zeichen

    if ($curlError || $httpCode !== 200) {
        resultInfo(false, 'SERVICE_UNAVAILABLE', [
            'error' => 'VIES service temporarily unavailable',
            'retry' => true,
            'debug' => $curlError,
            'httpCode' => $httpCode
        ]);
        return;
    }

    // Parse XML Response
    $xml = @simplexml_load_string($response);

    if ($xml === false) {
        resultInfo(false, 'PARSE_ERROR', [
            'error' => 'Could not parse VIES response',
            'responsePreview' => substr($response, 0, 200)
        ]);
        return;
    }

    // Register Namespaces
    $xml->registerXPathNamespace('env', 'http://schemas.xmlsoap.org/soap/envelope/');
    $xml->registerXPathNamespace('ns2', 'urn:ec.europa.eu:taxud:vies:services:checkVat:types');

    // Prüfe auf SOAP Fault
    $faults = $xml->xpath('//env:Fault');
    if (!empty($faults)) {
        $fault = $faults[0];
        $faultCode = (string)$fault->faultcode;
        $faultString = (string)$fault->faultstring;

        //writeLog('VIES Fault: ' . $faultCode . ' - ' . $faultString);

        if (strpos($faultString, 'INVALID_INPUT') !== false) {
            resultInfo(false, 'INVALID_VAT_ID', [
                'error' => 'Invalid VAT ID format',
                'valid' => false,
                'vatId' => $vatId
            ]);
        } else if (strpos($faultString, 'MS_UNAVAILABLE') !== false || strpos($faultString, 'SERVICE_UNAVAILABLE') !== false) {
            resultInfo(false, 'SERVICE_UNAVAILABLE', [
                'error' => 'VIES service for ' . $countryCode . ' temporarily unavailable',
                'retry' => true,
                'faultString' => $faultString
            ]);
        } else {
            resultInfo(false, 'VIES_ERROR', [
                'error' => 'VIES API error: ' . $faultString,
                'faultCode' => $faultCode
            ]);
        }
        return;
    }

    // Erfolgreiche Antwort
    $results = $xml->xpath('//ns2:checkVatResponse');
    if (empty($results)) {
        resultInfo(false, 'NO_RESULT', [
            'error' => 'VIES returned success but no data'
        ]);
        return;
    }

    $result = $results[0];

    // Extrahiere Werte aus ns2 Namespace
    $ns2Children = $result->children('urn:ec.europa.eu:taxud:vies:services:checkVat:types');

    $valid = strtolower((string)$ns2Children->valid) === 'true';

    resultInfo(true, 'CHECKED', [
        'valid' => $valid,
        'countryCode' => (string)$ns2Children->countryCode,
        'vatNumber' => (string)$ns2Children->vatNumber,
        'requestDate' => (string)$ns2Children->requestDate,
        'name' => !empty((string)$ns2Children->name) && (string)$ns2Children->name !== '---' ? (string)$ns2Children->name : null,
        'address' => !empty((string)$ns2Children->address) && (string)$ns2Children->address !== '---' ? (string)$ns2Children->address : null
    ]);
}
