<?php
// backend/api/customer_vendor/search_by_phone.php

/**
 * Sucht anhand einer Telefonnummer per Claude Web-Search nach dem Inhaber
 * und extrahiert Stammdaten fuer Kunden-/Lieferantenanlage.
 *
 * @param string $data['phone'] Telefonnummer (frei formatiert)
 * @param string $data['src']   'C' fuer Kunde, 'V' fuer Lieferant (nur fuer Kontext)
 * @testdata {"phone": "030 12345678", "src": "C"}
 */
function searchByPhone($data) {
    set_time_limit(90);

    $db = DbhCompany::begin();

    $phone = trim($data['phone'] ?? '');
    $cvSrc = ($data['src'] ?? 'C') === 'V' ? 'Lieferanten' : 'Kunden';

    if ($phone === '') {
        throw new ApiError('VALIDATION_ERROR', 'Telefonnummer erforderlich');
    }
    if (strlen($phone) < 5) {
        throw new ApiError('VALIDATION_ERROR', 'Telefonnummer zu kurz');
    }

    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', 'phone_search_ai_model')"
    );
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key nicht konfiguriert');
    }
    // Sonnet liefert zuverlässigere Web-Recherche als Haiku
    $aiModel = $config['phone_search_ai_model'] ?? 'claude-sonnet-4-5-20250929';

    $prompt = <<<PROMPT
Finde per Websuche den Inhaber der folgenden deutschen Telefonnummer und extrahiere die Stammdaten fuer die Anlage eines $cvSrc.

Telefonnummer: $phone

Nutze das Web-Search-Tool. Suche nach der Nummer in verschiedenen Varianten (mit/ohne Vorwahl, mit/ohne Leerzeichen). Priorisiere seriöse Quellen:
- Firmen-Homepages (Impressum)
- Branchenverzeichnisse (Das Örtliche, Gelbe Seiten, 11880)
- Handelsregister-Einträge

Antworte AUSSCHLIESSLICH mit einem validen JSON-Objekt — kein Markdown, kein Fließtext:

{
  "name":             "Firmenname (oder 'Vorname Nachname' bei Privatperson)",
  "contact":          "Ansprechpartner falls auf der Quelle genannt, sonst leer",
  "street":           "Straße und Hausnummer",
  "zipcode":          "Postleitzahl",
  "city":             "Ort",
  "country":          "ISO-Code 'DE'/'AT'/'CH'",
  "natural_person":   true nur wenn Privatperson ohne Firma,
  "phone":            "Die gefundene Nummer im internationalen Format (+49 ...)",
  "email":            "E-Mail falls auf Quelle",
  "homepage":         "Website-URL (mit https://) falls auf Quelle",
  "taxnumber":        "Steuernummer falls aus Impressum",
  "ustid":            "USt-IdNr. falls aus Impressum",
  "commercial_court": "Handelsregister-Eintrag falls vorhanden",
  "confidence":       0.0 bis 1.0 (wie sicher der Treffer ist),
  "sources":          ["URL 1", "URL 2"],
  "notes":            "Besonderheiten, z.B. 'Mehrere Treffer — bester gewählt' oder 'Nur Branchenverzeichnis'"
}

REGELN:
- Nur Felder die tatsaechlich aus den Web-Quellen stammen — nichts erfinden.
- Felder die nicht gefunden wurden: leerer String "" (Arrays als []).
- Wenn die Nummer nicht gefunden wird: confidence = 0 und notes entsprechend setzen. Trotzdem das JSON zurueckgeben.
- Keine kostenpflichtigen "Rückwärtssuche"-Dienste nutzen (die liefern ohnehin nichts).
- Telefonnummer im internationalen Format zurueckgeben (+49 statt 0 am Anfang).
- Land 'DE' wenn nicht anders erkennbar.
PROMPT;

    $requestBody = json_encode([
        'model'      => $aiModel,
        'max_tokens' => 2048,
        'tools'      => [[
            'type'        => 'web_search_20250305',
            'name'        => 'web_search',
            'max_uses'    => 5
        ]],
        'messages'   => [[
            'role'    => 'user',
            'content' => $prompt
        ]]
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 75,
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
        throw new ApiError('CLAUDE_API_ERROR', 'Web-Suche fehlgeschlagen (HTTP ' . $httpCode . '): ' . $detail);
    }

    $responseData = json_decode($response, true);

    // Claude kann mehrere content-Bloecke liefern (tool_use + text). Letzten Text-Block nehmen.
    $aiText = '';
    foreach (($responseData['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && !empty($block['text'])) {
            $aiText = $block['text'];
        }
    }

    // Falls JSON in Markdown-Codeblock verpackt ist, extrahieren
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $aiText, $m)) {
        $aiText = $m[1];
    }

    $extracted = json_decode($aiText, true);
    if (!is_array($extracted)) {
        throw new ApiError('EXTRACTION_ERROR', 'KI-Antwort konnte nicht als JSON gelesen werden: ' . substr($aiText, 0, 300));
    }

    // Homepage normalisieren
    if (!empty($extracted['homepage']) && !preg_match('~^https?://~i', $extracted['homepage'])) {
        $extracted['homepage'] = 'https://' . $extracted['homepage'];
    }

    // sources defensiv als Array
    if (!isset($extracted['sources']) || !is_array($extracted['sources'])) {
        $extracted['sources'] = [];
    }

    resultInfo(true, 'OK', ['extracted' => $extracted]);
}
