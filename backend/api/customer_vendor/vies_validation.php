<?php
// backend/api/customer_vendor/vies_validation.php

/**
 * USt-IdNr.-Bestätigung: eVatR (BZSt, qualifiziert) mit VIES als Rückfallebene
 *
 * Deutsche Unternehmen bestätigen die USt-IdNr. eines EU-Geschäftspartners
 * über das Bundeszentralamt für Steuern (eVatR, REST seit 07/2025). Die
 * qualifizierte Abfrage prüft Firmenname und Ort (optional PLZ, Straße) gegen
 * die Registerdaten und ist der Nachweis nach § 18e UStG. Jede Abfrage wird
 * vollständig in ustid_check_log festgehalten, die Kurzfassung der letzten
 * Prüfung in customer_ext/vendor_ext.ustid_check — sichtbar in der Kundenmaske.
 *
 * Wann welcher Dienst:
 *   - eigene USt-IdNr. ist DE und die geprüfte ist nicht DE → eVatR
 *     (qualifiziert, sobald Name und Ort mitgeschickt werden)
 *   - geprüfte USt-IdNr. ist DE → eVatR lehnt DE→DE ab (evatr-0006), VIES
 *     bestätigt nur die Gültigkeit; Deutschland gibt Name/Anschrift nicht heraus
 *   - keine oder keine deutsche eigene USt-IdNr. → VIES; mit eigener EU-Nummer
 *     liefert VIES eine Konsultationsnummer (requestIdentifier) als Nachweis
 */

/**
 * Prüft eine EU-USt-IdNr. und speichert das Ergebnis als Nachweis
 *
 * @param string $data['vatId']   Umsatzsteuer-ID des Geschäftspartners (z. B. ATU12345678)
 * @param int    $data['cv_id']   Kunden-/Lieferanten-ID (optional; ohne ID wird nur geprüft, nicht am Stamm gespeichert)
 * @param string $data['src']     C = Kunde, V = Lieferant (optional, Standard C)
 * @param string $data['name']    Firmenname für die qualifizierte Abfrage (optional)
 * @param string $data['city']    Ort für die qualifizierte Abfrage (optional)
 * @param string $data['zipcode'] PLZ (optional)
 * @param string $data['street']  Straße (optional)
 * @testdata {"vatId": "ATU12345678", "cv_id": 0, "src": "C", "name": "Musterhaus GmbH & Co KG", "city": "Musterort", "zipcode": "12345", "street": "Musterstrasse 22"}
 */
function checkVatId($data) {
    $vatId = trim((string)($data['vatId'] ?? ''));
    if ($vatId === '') {
        resultInfo(false, 'VAT_ID_REQUIRED', ['error' => 'VAT ID is required']);
        return;
    }

    $cleanVatId  = strtoupper(preg_replace('/[\s\-\.]/', '', $vatId));
    $countryCode = substr($cleanVatId, 0, 2);
    $vatNumber   = substr($cleanVatId, 2);
    if (!preg_match('/^[A-Z]{2}$/', $countryCode) || strlen($vatNumber) < 2) {
        resultInfo(false, 'INVALID_FORMAT', ['error' => 'Invalid VAT ID format', 'vatId' => $vatId]);
        return;
    }
    // Nur EU-Nummern (EL = Griechenland, XI = Nordirland) lassen sich bestätigen —
    // eine Schweizer CHE-Nummer etwa ist keine USt-IdNr. im Sinne von § 18e UStG
    // und darf nicht als „ungültig" im Nachweis landen.
    $euCodes = ['AT','BE','BG','CY','CZ','DE','DK','EE','EL','ES','FI','FR','HR','HU','IE','IT',
                'LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK','XI'];
    if (!in_array($countryCode, $euCodes, true)) {
        resultInfo(false, 'NOT_EU', ['error' => 'Not an EU VAT ID', 'vatId' => $vatId, 'countryCode' => $countryCode]);
        return;
    }

    $db = DbhCompany::begin();

    // Eigene USt-IdNr. der Firma — entscheidet über den Dienst
    $firma    = $db->getOne("SELECT btrim(COALESCE(co_ustid, '')) AS co_ustid FROM defaults LIMIT 1") ?: [];
    $ownVatId = strtoupper(preg_replace('/[\s\-\.]/', '', (string)($firma['co_ustid'] ?? '')));

    $name    = trim((string)($data['name'] ?? ''));
    $city    = trim((string)($data['city'] ?? ''));
    $zipcode = trim((string)($data['zipcode'] ?? ''));
    $street  = trim((string)($data['street'] ?? ''));
    $cvId    = (int)($data['cv_id'] ?? 0);
    $src     = (($data['src'] ?? 'C') === 'V') ? 'V' : 'C';

    $useEvatr = str_starts_with($ownVatId, 'DE') && $countryCode !== 'DE';
    if ($useEvatr) {
        $result = ustidCheckEvatr($ownVatId, $cleanVatId, $name, $city, $zipcode, $street);
    } else {
        $result = ustidCheckVies($countryCode, $vatNumber, $ownVatId);
    }

    // Warum es keine qualifizierte Abfrage gab — die Maske erklärt es dem Benutzer
    if (!$result['qualified']) {
        if ($countryCode === 'DE') {
            $result['qualified_reason'] = 'TARGET_DE';
        } elseif (!str_starts_with($ownVatId, 'DE')) {
            $result['qualified_reason'] = $ownVatId === '' ? 'NO_OWN_USTID' : 'OWN_NOT_DE';
        } elseif ($name === '' || $city === '') {
            $result['qualified_reason'] = 'MISSING_NAME_CITY';
        }
    }

    $result['ustid']     = $cleanVatId;
    $result['own_ustid'] = $ownVatId !== '' ? $ownVatId : null;
    $result['cv_id']     = $cvId ?: null;
    $result['src']       = $src;
    $result['stored']    = false;

    // Nachweis festhalten — Protokoll immer, Kurzfassung am Stamm nur mit ID.
    // Ein Dienstausfall (valid = null) steht ebenfalls im Protokoll, überschreibt
    // aber keine frühere gültige Bestätigung am Stamm.
    $summary = ustidCheckSummary($result);
    try {
        $extTable = $src === 'V' ? 'vendor_ext' : 'customer_ext';
        $extFk    = $src === 'V' ? 'vendor_id'  : 'customer_id';
        $row = $db->getOne(
            "WITH l AS (
                INSERT INTO ustid_check_log
                    (cv_id, src, ustid, own_ustid, service, qualified, valid, status, request, response, employee_id, login)
                VALUES
                    (CAST(:cv_id AS integer), :src, :ustid, :own_ustid, :service, CAST(:qualified AS boolean), CAST(:valid AS boolean), :status,
                     CAST(:request AS jsonb), CAST(:response AS jsonb),
                     NULLIF(current_setting('oserp.employee_id', true), '')::integer, :login)
                RETURNING id
             ), e AS (
                INSERT INTO $extTable ($extFk, ustid_check)
                SELECT CAST(:cv_id2 AS integer), CAST(:summary AS jsonb) || jsonb_build_object('log_id', l.id)
                  FROM l
                 WHERE CAST(:cv_id3 AS integer) > 0 AND CAST(:valid2 AS boolean) IS NOT NULL
                ON CONFLICT ($extFk) DO UPDATE SET ustid_check = EXCLUDED.ustid_check, mtime = now()
                RETURNING $extFk
             )
             SELECT l.id AS log_id, (SELECT COUNT(*) FROM e) AS stored FROM l",
            [
                ':cv_id'     => $cvId ?: null,
                ':cv_id2'    => $cvId,
                ':cv_id3'    => $cvId,
                ':src'       => $src,
                ':ustid'     => $cleanVatId,
                ':own_ustid' => $ownVatId !== '' ? $ownVatId : null,
                ':service'   => $result['service'],
                ':qualified' => $result['qualified'] ? 't' : 'f',
                ':valid'     => $result['valid'] === null ? null : ($result['valid'] ? 't' : 'f'),
                ':valid2'    => $result['valid'] === null ? null : ($result['valid'] ? 't' : 'f'),
                ':status'    => $result['status'],
                ':request'   => json_encode($result['request'], JSON_UNESCAPED_UNICODE),
                ':response'  => json_encode($result['response'], JSON_UNESCAPED_UNICODE),
                ':summary'   => json_encode($summary, JSON_UNESCAPED_UNICODE),
                ':login'     => DbhAuth::begin()->getLogin(),
            ]
        );
        $result['log_id'] = (int)($row['log_id'] ?? 0);
        $result['stored'] = (int)($row['stored'] ?? 0) > 0;
    } catch (Exception $e) {
        writeLog('USt-IdNr.-Nachweis konnte nicht gespeichert werden: ' . $e->getMessage(), true, DLOG_ERR);
    }
    $summary['log_id'] = $result['log_id'] ?? null;
    $result['summary'] = $summary;

    if ($result['valid'] === null) {
        // Dienst nicht erreichbar / Eingabefehler — kein Urteil über die Nummer
        resultInfo(false, $result['error_code'] ?? 'SERVICE_UNAVAILABLE', $result);
        return;
    }
    resultInfo(true, 'CHECKED', $result);
}

/**
 * Kurzfassung für customer_ext/vendor_ext.ustid_check (das zeigt die Maske)
 *
 * @param array $result Ergebnis aus ustidCheckEvatr()/ustidCheckVies()
 * @return array
 */
function ustidCheckSummary($result) {
    return [
        'ustid'       => $result['ustid'],
        'service'     => $result['service'],
        'qualified'   => $result['qualified'],
        'valid'       => $result['valid'],
        'status'      => $result['status'],
        'checked_at'  => $result['checked_at'],
        'fields'      => $result['fields'],
        'name'        => $result['name'],
        'address'     => $result['address'],
        'request_id'  => $result['request_id'],
        'valid_from'  => $result['valid_from'],
        'valid_until' => $result['valid_until'],
        'request'     => $result['request'],
    ];
}

/**
 * Leeres Ergebnis — beide Dienste füllen dieselben Felder
 *
 * @param string $service evatr | vies
 * @param array  $request gesendete Felder
 * @return array
 */
function ustidCheckBlank($service, $request) {
    return [
        'service'     => $service,
        'qualified'   => false,
        'valid'       => null,      // null: kein Urteil (Dienst/Eingabe)
        'status'      => null,
        'message'     => null,
        'error_code'  => null,
        'retry'       => false,
        'checked_at'  => date('c'),
        'fields'      => null,      // qualifiziert: [firmenname => A|B|C|D, ort, plz, strasse]
        'name'        => null,
        'address'     => null,
        'request_id'  => null,      // VIES-Konsultationsnummer
        'valid_from'  => null,
        'valid_until' => null,
        'request'     => $request,
        'response'    => null,
    ];
}

/**
 * eVatR-Abfrage beim BZSt (REST, ohne Registrierung)
 *
 * Qualifiziert, sobald Firmenname und Ort besetzt sind; PLZ und Straße
 * verfeinern den Abgleich. Antwortfelder ergFirmenname/ergOrt/ergPlz/ergStrasse:
 * A stimmt überein, B stimmt nicht überein, C nicht angefragt, D vom
 * Mitgliedstaat nicht mitgeteilt. Statuscodes: https://api.evatr.vies.bzst.de/app/v1/info/statusmeldungen
 *
 * @param string $ownVatId  anfragende DE-USt-IdNr.
 * @param string $vatId     angefragte USt-IdNr.
 * @param string $name      Firmenname (qualifiziert)
 * @param string $city      Ort (qualifiziert)
 * @param string $zipcode   PLZ (optional)
 * @param string $street    Straße (optional)
 * @return array
 */
function ustidCheckEvatr($ownVatId, $vatId, $name, $city, $zipcode = '', $street = '') {
    $qualified = $name !== '' && $city !== '';
    $request = ['anfragendeUstid' => $ownVatId, 'angefragteUstid' => $vatId];
    if ($qualified) {
        $request['firmenname'] = $name;
        $request['ort']        = $city;
        if ($zipcode !== '') $request['plz']     = $zipcode;
        if ($street  !== '') $request['strasse'] = $street;
    }
    $result = ustidCheckBlank('evatr', $request);
    $result['qualified'] = $qualified;

    $ch = curl_init('https://api.evatr.vies.bzst.de/app/v1/abfrage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($request, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '' || $body === false) {
        $result['error_code'] = 'SERVICE_UNAVAILABLE';
        $result['retry']      = true;
        $result['message']    = 'eVatR nicht erreichbar: ' . $curlErr;
        return $result;
    }

    $json = json_decode((string)$body, true);
    $result['response'] = is_array($json) ? $json : ['raw' => substr((string)$body, 0, 1000), 'http' => $httpCode];
    $status = is_array($json) ? (string)($json['status'] ?? '') : '';
    $result['status']  = $status !== '' ? $status : 'http-' . $httpCode;
    $result['message'] = is_array($json) ? ($json['meldung'] ?? null) : null;
    if (is_array($json)) {
        $result['checked_at']  = $json['anfrageZeitpunkt'] ?? $result['checked_at'];
        $result['valid_from']  = $json['gueltigAb']  ?? null;
        $result['valid_until'] = $json['gueltigBis'] ?? null;
    }

    // Gültig: evatr-0000, evatr-2008 (gültig mit Besonderheit). Nicht gültig:
    // evatr-2001 (nicht vergeben), evatr-2002 (erst ab), evatr-2006 (war gültig).
    // Alles andere ist kein Urteil über die Nummer.
    if (in_array($status, ['evatr-0000', 'evatr-2008'], true)) {
        $result['valid'] = true;
        if ($qualified) {
            $result['fields'] = [
                'firmenname' => $json['ergFirmenname'] ?? 'C',
                'ort'        => $json['ergOrt']        ?? 'C',
                'plz'        => $json['ergPlz']        ?? 'C',
                'strasse'    => $json['ergStrasse']    ?? 'C',
            ];
        }
    } elseif (in_array($status, ['evatr-2001', 'evatr-2002', 'evatr-2006'], true)) {
        $result['valid'] = false;
    } elseif (in_array($status, ['evatr-0005', 'evatr-0012', 'evatr-2003'], true)) {
        $result['valid']      = false;      // syntaktisch falsch — es kann keine gültige Nummer sein
        $result['error_code'] = 'INVALID_VAT_ID';
    } elseif ($status === 'evatr-0003') {
        // gültig, aber qualifizierte Pflichtfelder fehlten — wie einfache Abfrage behandeln
        $result['valid']     = true;
        $result['qualified'] = false;
    } elseif (in_array($status, ['evatr-0004', 'evatr-2005', 'evatr-0006'], true)) {
        $result['error_code'] = 'OWN_VAT_ID_INVALID';
    } elseif ($status === 'evatr-0008') {
        $result['error_code'] = 'TOO_MANY_REQUESTS';
        $result['retry']      = true;
    } else {
        $result['error_code'] = 'SERVICE_UNAVAILABLE';
        $result['retry']      = $httpCode >= 500;
    }
    return $result;
}

/**
 * VIES-Abfrage (EU-Kommission, SOAP per cURL)
 *
 * Mit eigener EU-USt-IdNr. wird checkVatApprox benutzt — die Antwort enthält
 * dann die Konsultationsnummer (requestIdentifier) als Nachweis. Name und
 * Anschrift kommen nur, wenn der Mitgliedstaat sie herausgibt (DE: nein).
 *
 * @param string $countryCode Länderkennzeichen der angefragten Nummer
 * @param string $vatNumber   Nummer ohne Länderkennzeichen
 * @param string $ownVatId    eigene USt-IdNr. (leer: ohne Konsultationsnummer)
 * @return array
 */
function ustidCheckVies($countryCode, $vatNumber, $ownVatId = '') {
    $withRequester = $ownVatId !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{2,}$/', $ownVatId);
    $request = ['countryCode' => $countryCode, 'vatNumber' => $vatNumber];
    $esc = fn($v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    if ($withRequester) {
        $request['requesterCountryCode'] = substr($ownVatId, 0, 2);
        $request['requesterVatNumber']   = substr($ownVatId, 2);
        $op   = 'checkVatApprox';
        $extra = '<urn:traderName></urn:traderName><urn:traderCompanyType></urn:traderCompanyType>'
               . '<urn:traderStreet></urn:traderStreet><urn:traderPostcode></urn:traderPostcode><urn:traderCity></urn:traderCity>'
               . '<urn:requesterCountryCode>' . $esc($request['requesterCountryCode']) . '</urn:requesterCountryCode>'
               . '<urn:requesterVatNumber>' . $esc($request['requesterVatNumber']) . '</urn:requesterVatNumber>';
    } else {
        $op = 'checkVat';
        $extra = '';
    }
    $result = ustidCheckBlank('vies', $request);

    $soap = '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:urn="urn:ec.europa.eu:taxud:vies:services:checkVat:types">'
        . '<soap:Body><urn:' . $op . '>'
        . '<urn:countryCode>' . $esc($countryCode) . '</urn:countryCode>'
        . '<urn:vatNumber>' . $esc($vatNumber) . '</urn:vatNumber>'
        . $extra
        . '</urn:' . $op . '></soap:Body></soap:Envelope>';

    $ch = curl_init('https://ec.europa.eu/taxation_customs/vies/services/checkVatService');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $soap,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '' || $body === false || ($httpCode !== 200 && $httpCode !== 500)) {
        $result['error_code'] = 'SERVICE_UNAVAILABLE';
        $result['retry']      = true;
        $result['message']    = 'VIES nicht erreichbar: ' . ($curlErr !== '' ? $curlErr : 'HTTP ' . $httpCode);
        return $result;
    }

    $xml = @simplexml_load_string((string)$body);
    if ($xml === false) {
        $result['error_code'] = 'PARSE_ERROR';
        $result['response']   = ['raw' => substr((string)$body, 0, 1000)];
        return $result;
    }
    $xml->registerXPathNamespace('env', 'http://schemas.xmlsoap.org/soap/envelope/');
    $xml->registerXPathNamespace('ns2', 'urn:ec.europa.eu:taxud:vies:services:checkVat:types');

    $faults = $xml->xpath('//env:Fault');
    if (!empty($faults)) {
        $faultString = (string)$faults[0]->faultstring;
        $result['status']   = $faultString;
        $result['message']  = $faultString;
        $result['response'] = ['fault' => $faultString];
        if (strpos($faultString, 'INVALID_INPUT') !== false) {
            $result['valid']      = false;
            $result['error_code'] = 'INVALID_VAT_ID';
        } elseif (strpos($faultString, 'MS_MAX_CONCURRENT_REQ') !== false || strpos($faultString, 'GLOBAL_MAX_CONCURRENT_REQ') !== false) {
            $result['error_code'] = 'TOO_MANY_REQUESTS';
            $result['retry']      = true;
        } else {
            $result['error_code'] = 'SERVICE_UNAVAILABLE';
            $result['retry']      = true;
        }
        return $result;
    }

    $nodes = $xml->xpath('//ns2:' . $op . 'Response');
    if (empty($nodes)) {
        $result['error_code'] = 'NO_RESULT';
        $result['response']   = ['raw' => substr((string)$body, 0, 1000)];
        return $result;
    }
    $r = $nodes[0]->children('urn:ec.europa.eu:taxud:vies:services:checkVat:types');
    $clean = fn($v) => ($v !== '' && $v !== '---') ? $v : null;
    $name    = $clean(trim((string)($withRequester ? $r->traderName : $r->name)));
    $address = $clean(trim(preg_replace('/\s+/', ' ', (string)($withRequester ? $r->traderAddress : $r->address))));

    $result['valid']      = strtolower((string)$r->valid) === 'true';
    $result['status']     = $result['valid'] ? 'VALID' : 'INVALID';
    // requestDate ist nur ein Datum ("2026-10-29+01:00") — der eigene Zeitstempel bleibt
    $result['name']       = $name;
    $result['address']    = $address;
    $result['request_id'] = $withRequester ? ($clean(trim((string)$r->requestIdentifier))) : null;
    $result['response']   = [
        'valid'             => $result['valid'],
        'requestDate'       => (string)$r->requestDate,
        'name'              => $name,
        'address'           => $address,
        'requestIdentifier' => $result['request_id'],
    ];
    return $result;
}
