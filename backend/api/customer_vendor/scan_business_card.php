<?php
// backend/api/customer_vendor/scan_business_card.php

/**
 * Visitenkarte per KI analysieren und Stammdaten extrahieren.
 * Nutzt Claude Vision (Anthropic) um aus einem Foto/Scan einer Visitenkarte
 * strukturierte Daten fuer Kunden-/Lieferantenanlage zu gewinnen.
 *
 * @param string $data['file_base64'] Base64-kodierter Bildinhalt (ohne data: Prefix)
 * @param string $data['mime_type']   MIME-Typ (image/jpeg, image/png, image/webp, image/gif, application/pdf)
 * @param string $data['src']         'C' fuer Kunde, 'V' fuer Lieferant (nur fuer Kontext im Prompt)
 * @testdata {"mime_type": "image/jpeg", "src": "C", "file_base64": ""}
 */
function scanBusinessCard($data) {
    set_time_limit(120);

    $db = DbhCompany::begin();

    $fileBase64 = $data['file_base64'] ?? '';
    $mimeType   = $data['mime_type']   ?? '';
    $cvSrc      = ($data['src'] ?? 'C') === 'V' ? 'Lieferanten' : 'Kunden';

    if (empty($fileBase64)) {
        throw new ApiError('VALIDATION_ERROR', 'file_base64 erforderlich');
    }

    $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new ApiError('VALIDATION_ERROR', 'Ungueltiger MIME-Typ. Erlaubt: ' . implode(', ', $allowedMimes));
    }

    $decoded = base64_decode($fileBase64, true);
    if ($decoded === false) {
        throw new ApiError('VALIDATION_ERROR', 'Ungueltige Base64-Daten');
    }
    if (strlen($decoded) > 10 * 1024 * 1024) {
        throw new ApiError('VALIDATION_ERROR', 'Datei zu gross (max. 10 MB)');
    }

    // API-Key und Modell aus DB laden
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', 'business_card_ai_model')"
    );
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key nicht konfiguriert');
    }
    $aiModel = $config['business_card_ai_model'] ?? 'claude-haiku-4-5-20251001';

    $prompt = <<<PROMPT
Du analysierst das Foto/den Scan einer Visitenkarte, um daraus Stammdaten fuer die Anlage eines $cvSrc in einem ERP-System zu extrahieren.

Das Bild kann rotiert sein (Hoch- oder Querformat, evtl. um 90/180 Grad gedreht). Lies den Inhalt unabhaengig von der Bildorientierung.

Antworte AUSSCHLIESSLICH mit einem validen JSON-Objekt — kein Markdown, kein Flies-Text, kein Code-Block.

Feldstruktur:
{
  "name":             "Firmenname (bei Einzelunternehmer/Freiberufler ohne Firma: Vor- und Nachname der Person)",
  "contact":          "Ansprechpartner — Vor- und Nachname der abgedruckten Person inkl. Titel (Dr., Prof.)",
  "contact_gender":   "'m' fuer maennlich, 'f' fuer weiblich, '' wenn nicht eindeutig bestimmbar",
  "contact_title":    "Akademischer Titel OHNE Anrede (z.B. 'Dr.', 'Prof.', 'Prof. Dr.') — leer wenn nicht vorhanden",
  "contact_firstname":"Vorname der Person (ohne Titel)",
  "contact_lastname": "Nachname der Person (ohne Titel)",
  "contact_position": "Position/Jobtitel der Person (z.B. 'Geschaeftsfuehrer', 'Vertriebsleiter') NUR wenn wortwoertlich auf der Karte",
  "department_1":     "Abteilung/Position NUR wenn wortwoertlich auf der Visitenkarte abgedruckt",
  "department_2":     "Zweite Abteilungs-/Positionszeile NUR wenn wortwoertlich auf der Visitenkarte abgedruckt",
  "street":           "Strasse und Hausnummer — exakt wie abgedruckt",
  "zipcode":          "Postleitzahl — nur die Ziffern/Zeichen der PLZ, OHNE Ort",
  "city":             "Ortsname — OHNE PLZ, OHNE Strasse, OHNE Land",
  "country":          "ISO-Laendercode (2 Zeichen, z.B. 'DE', 'AT', 'CH'). Wenn kein Land erkennbar: 'DE'",
  "natural_person":   true nur wenn KEINE Firma/Organisation abgedruckt ist, sonst false,
  "phone":            "Haupt-Festnetznummer im internationalen Format (+49 ...)",
  "phone_numbers":    [ { "label": "Mobil|Fax|Zentrale|Privat|Durchwahl", "number": "+49 ..." }, ... ],
  "email":            "E-Mail-Adresse",
  "homepage":         "Website-URL (mit https:// falls nicht angegeben)",
  "taxnumber":        "Steuernummer, falls erkennbar",
  "ustid":            "USt-IdNr., falls erkennbar",
  "commercial_court": "Amtsgericht/Handelsregister-Eintrag, falls erkennbar",
  "confidence":       0.0 bis 1.0 (Gesamt-Konfidenz der Extraktion),
  "notes":            "Hinweise bei Unsicherheiten, sonst leer"
}

STRIKTE REGELN:
- Felder, die nicht auf der Visitenkarte stehen: als leerer String "" zurueckgeben (Arrays als []). Niemals Werte erfinden, erraten oder aus anderen Feldern ableiten.
- KEINE Anrede generieren (kein Feld "greeting").
- department_1 und department_2 bleiben LEER, ausser die Visitenkarte enthaelt eine wortwoertliche Abteilungs- oder Positionsangabe (z.B. 'Vertrieb', 'Geschaeftsfuehrung', 'IT-Leitung'). Nicht aus dem Firmennamen oder der Adresse ableiten.
- Adresse: Die Adresszeile einer Visitenkarte hat typisch das Format 'Strasse Hausnr' in einer Zeile und 'PLZ Ort' in der Folgezeile. Trenne strikt:
  * street = nur Strassenname + Hausnummer (OHNE PLZ, OHNE Ort)
  * zipcode = nur die PLZ (4-5 Zeichen fuer DE/AT/CH)
  * city = nur der Ortsname (OHNE PLZ davor, OHNE Strasse, OHNE Land)
  Wenn Ort und PLZ in derselben Zeile stehen ('12345 Musterstadt'): PLZ extrahieren und separat ablegen, der Ort enthaelt KEINE Ziffern der PLZ.
- Telefonnummern IMMER ins internationale Format umwandeln (+49 statt 0049 oder fuehrender 0). Die Haupt-Festnetznummer in "phone"; Mobil, Fax, Durchwahl, Zentrale etc. gehoeren in "phone_numbers" mit sprechendem deutschen Label.
- natural_person = true nur, wenn KEINE Firma/Organisation abgedruckt ist (reine Privatvisitenkarte).
- Bei mehrsprachigen Karten: deutsche/europaeische Daten bevorzugen.
- Wenn das Bild unlesbar oder keine Visitenkarte ist: confidence = 0 und notes entsprechend setzen.
PROMPT;

    if ($mimeType === 'application/pdf') {
        $contentBlock = [
            'type'   => 'document',
            'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $fileBase64]
        ];
    } else {
        $mediaType = $mimeType === 'image/jpg' ? 'image/jpeg' : $mimeType;
        $contentBlock = [
            'type'   => 'image',
            'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $fileBase64]
        ];
    }

    $requestBody = json_encode([
        'model'      => $aiModel,
        'max_tokens' => 1500,
        'messages'   => [[
            'role'    => 'user',
            'content' => [$contentBlock, ['type' => 'text', 'text' => $prompt]]
        ]]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $anthropicKey,
            'anthropic-version: 2023-06-01'
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        $detail = '';
        if ($curlErr) {
            $detail = $curlErr;
        } else {
            $errData = json_decode($response, true);
            $detail = $errData['error']['message'] ?? substr((string)$response, 0, 500);
        }
        throw new ApiError('CLAUDE_API_ERROR', 'KI-Analyse fehlgeschlagen (HTTP ' . $httpCode . '): ' . $detail);
    }

    $responseData = json_decode($response, true);
    $aiText = $responseData['content'][0]['text'] ?? '';

    // Falls die KI einen Markdown-Codeblock zurueckgibt, JSON extrahieren
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $aiText, $m)) {
        $aiText = $m[1];
    }

    $extracted = json_decode($aiText, true);
    if (!is_array($extracted)) {
        throw new ApiError('EXTRACTION_ERROR', 'KI-Antwort konnte nicht als JSON gelesen werden');
    }

    // Nachbearbeitung: Homepage normalisieren
    if (!empty($extracted['homepage']) && !preg_match('~^https?://~i', $extracted['homepage'])) {
        $extracted['homepage'] = 'https://' . $extracted['homepage'];
    }

    // Nachbearbeitung Adresse: PLZ aus city/street entfernen falls KI sie nicht sauber getrennt hat
    $zip = trim($extracted['zipcode'] ?? '');
    $city = trim($extracted['city'] ?? '');
    $street = trim($extracted['street'] ?? '');

    // Wenn city mit PLZ beginnt (z.B. '12345 Musterstadt'), PLZ abtrennen
    if (preg_match('/^(\d{4,5})\s+(.+)$/u', $city, $m)) {
        if (!$zip) $zip = $m[1];
        $city = trim($m[2]);
    }
    // Wenn city PLZ enthaelt (z.B. 'Musterstadt 12345'), PLZ entfernen
    if ($zip && str_contains($city, $zip)) {
        $city = trim(str_replace($zip, '', $city));
    }
    // Wenn street noch PLZ/Ort am Ende enthaelt, abschneiden
    if (preg_match('/^(.+?)[,\s]+(\d{4,5})\s+\S+.*$/u', $street, $m)) {
        $street = trim($m[1]);
        if (!$zip) $zip = $m[2];
    }

    $extracted['zipcode'] = $zip;
    $extracted['city']    = $city;
    $extracted['street']  = $street;

    // Greeting entfernen — wird vom bestehenden lookupGreeting-Flow erzeugt
    unset($extracted['greeting']);

    // phone_numbers als saubere Struktur zurueckgeben
    if (!isset($extracted['phone_numbers']) || !is_array($extracted['phone_numbers'])) {
        $extracted['phone_numbers'] = [];
    }

    resultInfo(true, 'OK', ['extracted' => $extracted]);
}
