<?php
// backend/api/customer_vendor/scan_business_card.php

/**
 * Prüft ob eine URL erreichbar ist (HEAD-Request, kurzer Timeout)
 *
 * @param string $url Die zu prüfende URL
 * @return bool true wenn HTTP 200-399
 */
function checkUrlReachable($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => ['User-Agent: OpenSourceERP/1.0'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode >= 200 && $httpCode < 400;
}

/**
 * Erzeugt Domain-Varianten für häufige OCR-/KI-Lesefehler
 * z.B. "efs-schmidt" → ["ef-schmidt", "es-schmidt", "efs-schmid", ...]
 *
 * @param string $domain Domain-Teil ohne TLD (z.B. "efs-schmidt")
 * @return array Varianten
 */
function generateDomainVariants($domain) {
    $variants = [];

    // Jeden einzelnen Buchstaben weglassen
    $len = strlen($domain);
    for ($i = 0; $i < $len; $i++) {
        $char = $domain[$i];
        // Nur Buchstaben/Ziffern entfernen, keine Bindestriche/Punkte
        if (ctype_alnum($char)) {
            $variant = substr($domain, 0, $i) . substr($domain, $i + 1);
            if (strlen($variant) >= 2 && !in_array($variant, $variants, true)) {
                $variants[] = $variant;
            }
        }
    }

    // Häufige Buchstaben-Verwechslungen (OCR)
    $swaps = ['l' => ['1', 'i'], 'i' => ['l', '1'], '1' => ['l', 'i'],
              'o' => ['0'], '0' => ['o'], 'rn' => ['m'], 'nn' => ['m'],
              'cl' => ['d'], 'vv' => ['w']];

    foreach ($swaps as $from => $tos) {
        if (str_contains($domain, $from)) {
            foreach ($tos as $to) {
                $variant = str_replace($from, $to, $domain);
                if (!in_array($variant, $variants, true)) {
                    $variants[] = $variant;
                }
            }
        }
    }

    return $variants;
}

/**
 * Validiert und korrigiert eine deutsche Adresse über Nominatim (OpenStreetMap)
 * Mit kurzer Timeout — bei Fehler wird null zurückgegeben
 *
 * @param string $street Straße und Hausnummer
 * @param string $zip PLZ
 * @param string $city Ort
 * @param string $name Firmenname (optional, für bessere Suche)
 * @return array|null ['street', 'zipcode', 'city', 'corrected' => bool, 'notes' => string] oder null bei Fehler
 */
function validateAddressWithNominatim($street, $zip, $city, $name = '') {
    try {
        $result = [
            'street' => $street,
            'zipcode' => $zip,
            'city' => $city,
            'corrected' => false,
            'notes' => ''
        ];

        // Suchquery zusammenbauen (kurz halten)
        $query = trim("$zip $city");
        if (!empty($street)) {
            $query = trim("$street, $zip $city");
        }

        // Nominatim API aufrufen mit sehr kurzem Timeout
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'addressdetails' => 1,
            'limit' => 1
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,  // Nur 3 Sekunden — bei Fehler einfach skip
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                'User-Agent: OpenSourceERP/1.0 (Address Validation)'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200 || empty($response)) {
            return null; // Fehler — lokale Validierung verwenden
        }

        $data = json_decode($response, true);
        if (empty($data) || !is_array($data)) {
            return null;
        }

        $match = $data[0];
        $addr = $match['address'] ?? [];

        // Extrahiere Komponenten aus Nominatim-Antwort
        $nomZip = $addr['postcode'] ?? '';
        $nomCity = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? '';
        $nomStreet = isset($addr['road']) ? trim($addr['road'] . ' ' . ($addr['house_number'] ?? '')) : '';

        // Vergleiche und korrigiere wenn nötig
        if ($nomZip && $nomZip !== $zip) {
            $result['zipcode'] = $nomZip;
            $result['corrected'] = true;
            $result['notes'] .= "PLZ korrigiert: $zip → $nomZip; ";
        }

        if ($nomCity && strtolower($nomCity) !== strtolower($city)) {
            $result['city'] = $nomCity;
            $result['corrected'] = true;
            $result['notes'] .= "Ort korrigiert: $city → $nomCity; ";
        }

        if ($nomStreet && strtolower(trim($nomStreet)) !== strtolower(trim($street))) {
            // Nur korrigieren wenn Nominatim eine bessere Version hat
            if (strpos(strtolower($nomStreet), strtolower($street)) === 0 || strpos(strtolower($street), strtolower($nomStreet)) === 0) {
                $result['street'] = trim($nomStreet);
                $result['corrected'] = true;
                $result['notes'] .= "Straße präzisiert; ";
            }
        }

        return $result;
    } catch (Exception $e) {
        return null; // Bei Exception: lokale Validierung verwenden
    }
}

/**
 * Bestimmt die Anrede basierend auf Kundendaten
 * - Vertraue auf KI: natural_person=false → "Firma"
 * - Sonst: Vornamen in firstnametogender suchen → "Herr"/"Frau"
 *
 * @param array $extracted Aus der KI extrahierte Daten
 * @param PDOConnection $db Datenbankverbindung
 * @return string "Firma", "Herr", "Frau" oder ""
 */
function determineGreeting($extracted, $db) {
    // Vertraue auf KI: natural_person=false bedeutet Firma
    if (isset($extracted['natural_person']) && $extracted['natural_person'] === false) {
        return 'Firma';
    }

    // Person: Vornamen in firstnametogender suchen
    $firstname = trim($extracted['contact_firstname'] ?? '');
    if ($firstname !== '') {
        try {
            $genderRow = $db->getOne(
                "SELECT gender FROM firstnametogender WHERE LOWER(firstname) = LOWER(:firstname)",
                [':firstname' => $firstname]
            );
            if ($genderRow) {
                if ($genderRow['gender'] === 'F') return 'Frau';
                if ($genderRow['gender'] === 'M') return 'Herr';
            }
        } catch (\Throwable $e) {
            // writeLog('Gender-Lookup Fehler: ' . $e->getMessage());
        }
    }

    return '';
}

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
    // writeLog('=== scanBusinessCard START ===');

    $db = DbhCompany::begin();
    // writeLog('DB-Verbindung OK');

    $fileBase64 = $data['file_base64'] ?? '';
    $mimeType   = $data['mime_type']   ?? '';
    $cvSrc      = ($data['src'] ?? 'C') === 'V' ? 'Lieferanten' : 'Kunden';
    // writeLog("mimeType=$mimeType, src=$cvSrc, base64-len=" . strlen($fileBase64));

    if (empty($fileBase64)) {
        throw new ApiError('VALIDATION_ERROR', 'file_base64 erforderlich');
    }

    $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new ApiError('VALIDATION_ERROR', 'Ungültiger MIME-Typ. Erlaubt: ' . implode(', ', $allowedMimes));
    }

    $decoded = base64_decode($fileBase64, true);
    if ($decoded === false) {
        throw new ApiError('VALIDATION_ERROR', 'Ungültige Base64-Daten');
    }
    if (strlen($decoded) > 10 * 1024 * 1024) {
        throw new ApiError('VALIDATION_ERROR', 'Datei zu groß (max. 10 MB)');
    }
    // writeLog('Validierung OK, Dateigröße=' . strlen($decoded));

    // API-Key und Modell aus DB laden
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', 'business_card_ai_model')"
    );
    // writeLog('Config geladen: ' . json_encode(array_keys($config)));
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key nicht konfiguriert');
    }
    $aiModel = $config['business_card_ai_model'] ?? 'claude-haiku-4-5-20251001';
    // writeLog("AI-Modell=$aiModel");

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
- natural_person = true NUR, wenn es sich um eine REINE PRIVATVISITENKARTE handelt (z.B. "Max Mustermann, Freiberufler" ohne Firma).
  * IMMER natural_person = false, wenn im Namen oder anderen Feldern ein Organisations-/Firmen-Indikator steht (z.B. "Firma XYZ", "EFS-Schmidt", "Musterkanzlei", Unternehmensname erkennbar, etc.)
- Bei mehrsprachigen Karten: deutsche/europaeische Daten bevorzugen.
- PLAUSIBILITAETSPRUEFUNG: Bei deutschen Adressen (zipcode 4-5 Ziffern, country='DE'):
  * Geprueft werden muss, ob PLZ und Ortsname zueinander passen (z.B. PLZ 15370 passt zu Petershagen, PLZ 75370 NICHT)
  * Wenn unplausibel: confidence reduzieren und in notes ein Warnhinweis setzen
  * Beispiel: "Warnung: PLZ 75370 passt nicht zu Petershagen" oder aehnlich
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
    // writeLog("Claude API httpCode=$httpCode, curlErr=$curlErr, response-len=" . strlen($response));

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
        // writeLog('FEHLER: KI-Antwort kein JSON: ' . substr($aiText, 0, 500));
        throw new ApiError('EXTRACTION_ERROR', 'KI-Antwort konnte nicht als JSON gelesen werden');
    }
    // writeLog('KI-Extraktion OK: ' . json_encode(array_keys($extracted)));

    // writeLog('Nachbearbeitung START');

    // Nachbearbeitung: Homepage normalisieren
    if (!empty($extracted['homepage']) && !preg_match('~^https?://~i', $extracted['homepage'])) {
        $extracted['homepage'] = 'https://' . $extracted['homepage'];
    }

    // Nachbearbeitung Adresse: PLZ aus city/street entfernen falls KI sie nicht sauber getrennt hat
    $zip = trim($extracted['zipcode'] ?? '');
    $city = trim($extracted['city'] ?? '');
    $street = trim($extracted['street'] ?? '');
    $country = trim($extracted['country'] ?? 'DE');
    // writeLog("Adresse roh: street=$street, zip=$zip, city=$city, country=$country");

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
    // writeLog("Adresse bereinigt: street=$street, zip=$zip, city=$city");

    // Einfache Adress-Validierung gegen Datenbank (nur für DE-Adressen)
    if ($country === 'DE' && !empty($zip)) {
        // writeLog('PLZ-Validierung gestartet');
        try {
            // Alle Orte zu dieser PLZ laden
            $plzOrte = $db->getAll(
                'SELECT ort FROM zipcode_location_oserp WHERE plz = :plz',
                [':plz' => $zip]
            );
            // writeLog('PLZ-Orte gefunden: ' . json_encode($plzOrte));

            if (!empty($plzOrte)) {
                $ortsNamen = array_column($plzOrte, 'ort');

                if (!empty($city)) {
                    // Prüfen ob der KI-Ort zum PLZ passt (Fuzzy-Match)
                    $cityLower = mb_strtolower($city);
                    $bestMatch = null;
                    $bestScore = 0;

                    foreach ($ortsNamen as $ort) {
                        $ortLower = mb_strtolower($ort);
                        // Exakter Match
                        if ($ortLower === $cityLower) {
                            $bestMatch = $ort;
                            $bestScore = 100;
                            break;
                        }
                        // Teilstring-Match (z.B. "Petershagen" in "Petershagen/Eggersdorf")
                        if (str_contains($ortLower, $cityLower) || str_contains($cityLower, $ortLower)) {
                            $score = 80;
                            if ($score > $bestScore) {
                                $bestMatch = $ort;
                                $bestScore = $score;
                            }
                        }
                        // Ähnlichkeit
                        similar_text($ortLower, $cityLower, $pct);
                        if ($pct > $bestScore && $pct >= 60) {
                            $bestMatch = $ort;
                            $bestScore = $pct;
                        }
                    }

                    if ($bestMatch && $bestMatch !== $city) {
                        // writeLog("Ort korrigiert: $city -> $bestMatch (Score: $bestScore)");
                        $existing = trim($extracted['notes'] ?? '');
                        $extracted['notes'] = $existing
                            ? "$existing\nOrt korrigiert: $city → $bestMatch"
                            : "Ort korrigiert: $city → $bestMatch";
                        $city = $bestMatch;
                        $extracted['city'] = $city;
                    }
                } else {
                    // Kein Ort von der KI — aus DB nehmen
                    $city = count($ortsNamen) === 1 ? $ortsNamen[0] : $ortsNamen[0];
                    $extracted['city'] = $city;
                    // writeLog("Ort aus PLZ-DB gesetzt: $city");
                }
            } else if (!empty($city)) {
                // writeLog('PLZ nicht in DB, Nominatim wird versucht');
                // PLZ nicht in DB — Nominatim versuchen
                $nomResult = validateAddressWithNominatim($street, $zip, $city, $extracted['name'] ?? '');
                // writeLog('Nominatim Ergebnis: ' . json_encode($nomResult));

                if ($nomResult !== null && $nomResult['corrected']) {
                    $extracted['zipcode'] = $nomResult['zipcode'];
                    $extracted['city'] = $nomResult['city'];
                    $extracted['street'] = $nomResult['street'];

                    if (!empty($nomResult['notes'])) {
                        $existing = trim($extracted['notes'] ?? '');
                        $extracted['notes'] = $existing ? "$existing\n" . $nomResult['notes'] : $nomResult['notes'];
                    }
                    $extracted['confidence'] = max(0, min(1, floatval($extracted['confidence'] ?? 0.8) * 0.9));
                }
            }
        } catch (\Throwable $e) {
            // writeLog('PLZ-Validierung Fehler: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    // Homepage-URL validieren und E-Mail-Domain abgleichen
    $homepage = trim($extracted['homepage'] ?? '');
    $email = trim($extracted['email'] ?? '');

    if (!empty($homepage)) {
        try {
            // writeLog("Homepage-Validierung: $homepage");
            $reachable = checkUrlReachable($homepage);

            if (!$reachable) {
                // Domain-Varianten versuchen (häufige KI-Lesefehler)
                $parsedUrl = parse_url($homepage);
                $host = $parsedUrl['host'] ?? '';
                $scheme = $parsedUrl['scheme'] ?? 'https';

                if (!empty($host)) {
                    // Varianten: Buchstaben entfernen/hinzufügen die oft falsch gelesen werden
                    $hostParts = explode('.', $host);
                    $domainPart = $hostParts[0] === 'www' && count($hostParts) > 1 ? $hostParts[1] : $hostParts[0];
                    $alternatives = generateDomainVariants($domainPart);

                    foreach ($alternatives as $altDomain) {
                        $altHost = str_replace($domainPart, $altDomain, $host);
                        $altUrl = $scheme . '://' . $altHost;
                        if (isset($parsedUrl['path'])) $altUrl .= $parsedUrl['path'];

                        // writeLog("Homepage-Alternative testen: $altUrl");
                        if (checkUrlReachable($altUrl)) {
                            // writeLog("Homepage korrigiert: $homepage -> $altUrl");
                            $oldDomain = $host;
                            $newDomain = $altHost;
                            $extracted['homepage'] = $altUrl;

                            $existing = trim($extracted['notes'] ?? '');
                            $extracted['notes'] = $existing
                                ? "$existing\nHomepage korrigiert: $homepage → $altUrl"
                                : "Homepage korrigiert: $homepage → $altUrl";

                            // E-Mail-Domain mitkorrigieren wenn sie zur alten Homepage passt
                            if (!empty($email)) {
                                $emailDomain = substr($email, strpos($email, '@') + 1);
                                $wwwlessDomain = preg_replace('/^www\./', '', $oldDomain);
                                if ($emailDomain === $wwwlessDomain) {
                                    $newEmailDomain = preg_replace('/^www\./', '', $newDomain);
                                    $newEmail = substr($email, 0, strpos($email, '@') + 1) . $newEmailDomain;
                                    // writeLog("E-Mail korrigiert: $email -> $newEmail");
                                    $extracted['email'] = $newEmail;
                                    $extracted['notes'] .= "\nE-Mail korrigiert: $email → $newEmail";
                                }
                            }
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // writeLog('Homepage-Validierung Fehler: ' . $e->getMessage());
        }
    }
    // writeLog('Validierung abgeschlossen');

    // Anrede bestimmen: Firma oder Person (mit Geschlechts-Lookup)
    $greeting = determineGreeting($extracted, $db);
    $extracted['greeting'] = $greeting;
    // writeLog('Anrede bestimmt: ' . ($greeting ?: '(nicht bestimmbar)'));
    // writeLog('DEBUG: extracted keys = ' . json_encode(array_keys($extracted)));
    // writeLog('DEBUG: greeting value = ' . json_encode($greeting));
    // writeLog('DEBUG: extracted[greeting] = ' . json_encode($extracted['greeting'] ?? 'NOT SET'));

    // phone_numbers als saubere Struktur zurueckgeben
    if (!isset($extracted['phone_numbers']) || !is_array($extracted['phone_numbers'])) {
        $extracted['phone_numbers'] = [];
    }

    // writeLog('=== scanBusinessCard ENDE — resultInfo wird gesendet ===');
    // writeLog('DEBUG: final extracted[greeting] = ' . json_encode($extracted['greeting'] ?? 'NOT SET'));
    resultInfo(true, 'OK', ['extracted' => $extracted]);
}
