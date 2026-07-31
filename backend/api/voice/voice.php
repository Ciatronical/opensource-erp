<?php
// backend/api/voice/voice.php
// Spracheingabe: Proxy zum lokalen Whisper-Dienst + Fachbegriffe-Glossar.

/**
 * Einen einzelnen defaults_oserp-Wert lesen (mit Fallback).
 */
function _voiceCfg(string $key, string $default = ''): string {
    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = :key",
        [':key' => $key]
    );
    $val = $row['value'] ?? '';
    return ($val === '' || $val === null) ? $default : (string)$val;
}

/**
 * Audio (aus dem Browser aufgenommen) per lokalem Whisper-Dienst transkribieren.
 *
 * Das Audio kommt Base64-kodiert im JSON-Body (api.call.php parst JSON), damit der
 * bestehende Action-Dispatcher unverändert genutzt werden kann. Der Whisper-Dienst
 * bekommt das gepflegte Fachbegriffe-Glossar als initial_prompt mit — das hebt die
 * Trefferquote bei Domänen-Vokabular (Kfz-Teile, Arbeitsschritte, Namen).
 *
 * @param string $data['audio'] Base64-kodierte Audio-Bytes (webm/opus vom MediaRecorder)
 * @param string $data['lang'] Sprache: "de" (Standard) oder "auto"
 * @testdata {"action": "transcribeAudio", "audio": "", "lang": "de"}
 */
function transcribeAudio($data) {
    $b64 = $data['audio'] ?? '';
    if (!is_string($b64) || $b64 === '') {
        resultInfo(false, 'EMPTY_AUDIO', 'Keine Audiodaten übergeben');
        return;
    }

    // "data:audio/webm;base64,...." Präfix tolerieren
    if (($pos = strpos($b64, 'base64,')) !== false) {
        $b64 = substr($b64, $pos + 7);
    }
    $audio = base64_decode($b64, true);
    if ($audio === false || $audio === '') {
        resultInfo(false, 'INVALID_AUDIO', 'Audiodaten konnten nicht dekodiert werden');
        return;
    }

    $url   = rtrim(_voiceCfg('whisper_url', 'http://127.0.0.1:3002'), '/') . '/transcribe';
    $token = _voiceCfg('whisper_token', '');
    $lang  = ($data['lang'] ?? 'de') === 'auto' ? 'auto' : 'de';

    $headers = [
        'Content-Type: application/octet-stream',
        'X-Whisper-Lang: ' . $lang,
    ];
    if ($token !== '') {
        $headers[] = 'X-Whisper-Token: ' . $token;
    }

    // Fachbegriffe-Glossar als initial_prompt (base64, damit Umlaute/Kommas den
    // HTTP-Header nicht sprengen). Leeres Glossar -> kein Header, unveraendertes
    // Verhalten.
    $glossary = trim(_voiceCfg('whisper_glossary', ''));
    if ($glossary !== '') {
        $headers[] = 'X-Whisper-Prompt: ' . base64_encode($glossary);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $audio,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code === 0) {
        resultInfo(false, 'WHISPER_UNREACHABLE',
            'Whisper-Dienst nicht erreichbar (' . $err . '). Läuft oserp-whisper?');
        return;
    }
    if ($code !== 200) {
        resultInfo(false, 'WHISPER_HTTP_' . $code, substr((string)$resp, 0, 300));
        return;
    }

    $result = json_decode($resp, true);
    if (!is_array($result) || empty($result['ok'])) {
        resultInfo(false, 'WHISPER_FAILED', 'Transkription fehlgeschlagen');
        return;
    }

    resultInfo(true, '', [
        'text' => trim($result['text'] ?? ''),
        'language' => $result['language'] ?? null,
        'duration' => $result['duration'] ?? null,
    ]);
}

/**
 * Das aktuell gespeicherte Fachbegriffe-Glossar lesen.
 *
 * @testdata {"action": "getVoiceGlossary"}
 */
function getVoiceGlossary($data) {
    resultInfo(true, '', ['glossary' => _voiceCfg('whisper_glossary', '')]);
}

/**
 * Fachbegriffe lernen: baut aus den Stammdaten (Artikelbeschreibungen und —
 * falls vorhanden — den LxCars-Arbeitsanweisungen) ein kompaktes Domänen-Glossar
 * und speichert es unter defaults_oserp.whisper_glossary.
 *
 * Whisper nutzt vom initial_prompt nur die letzten ~224 Tokens — deshalb wird das
 * Glossar bewusst kurz gehalten (die häufigsten/wichtigsten Begriffe zuerst).
 *
 * @testdata {"action": "learnWhisperTerms"}
 */
function learnWhisperTerms($data) {
    $db = DbhCompany::begin();

    // Deutsche Allerweltswörter, die als "Fachbegriff" nichts bringen.
    $stop = array_flip([
        'oder','und','für','mit','ohne','pro','der','die','das','den','dem','des',
        'ein','eine','einer','eines','einem','einen','auf','aus','bei','vom','zum',
        'zur','nach','über','unter','inkl','inklusive','stück','stk','satz','set',
        'neu','gebraucht','original','komplett','passend','sonstige','sonstiges',
        'diverse','verschiedene','artikel','ware','stunde','stunden','arbeit',
    ]);

    // 1) Häufigste bedeutungstragende Wörter aus den Artikelbeschreibungen.
    //    Die Originalschreibweise (z. B. "Bremsscheibe" statt "bremsscheibe")
    //    wird direkt in der Aggregation als Beispiel mitgenommen — kein Lookbehind
    //    (das unterstützt PostgreSQL-Regex nicht) und keine Extra-Queries.
    $words = $db->getAll(
        "SELECT (array_agg(word ORDER BY word))[1] AS sample,
                lower(word) AS w,
                COUNT(*) AS c
         FROM (
             SELECT regexp_split_to_table(
                        regexp_replace(description, '[^[:alnum:]äöüÄÖÜß ]', ' ', 'g'),
                        '\\s+') AS word
             FROM parts
             WHERE description IS NOT NULL AND description <> ''
         ) t
         WHERE char_length(word) >= 4
         GROUP BY lower(word)
         ORDER BY c DESC
         LIMIT 400"
    );

    $terms = [];
    foreach ($words as $row) {
        $w = trim($row['w'] ?? '');
        if ($w === '' || isset($stop[$w]) || ctype_digit($w)) continue;
        $terms[] = trim($row['sample'] ?? '') !== '' ? $row['sample'] : $w;
        if (count($terms) >= 80) break;
    }

    // 2) Falls LxCars aktiv: die meistgenutzten Arbeitsanweisungen dazunehmen.
    $instructionCount = 0;
    if ($db->getOne("SELECT to_regclass('public.instructions_lxcars') AS t")['t'] ?? null) {
        $instr = $db->getAll(
            "SELECT description
             FROM instructions_lxcars
             WHERE description IS NOT NULL AND description <> ''
             ORDER BY COALESCE(usage_count, 0) DESC
             LIMIT 40"
        );
        foreach ($instr as $row) {
            $d = trim($row['description'] ?? '');
            if ($d !== '') { $terms[] = $d; $instructionCount++; }
        }
    }

    // Dedupe (case-insensitive), Reihenfolge erhalten.
    $seen = [];
    $unique = [];
    foreach ($terms as $trm) {
        $k = mb_strtolower($trm);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $unique[] = $trm;
    }

    // Auf ~700 Zeichen begrenzen (Whisper-Prompt-Fenster). Domäne voranstellen.
    $prefix = 'Kfz-Werkstatt. Fachbegriffe: ';
    $glossary = $prefix;
    $used = 0;
    foreach ($unique as $trm) {
        $add = ($used === 0 ? '' : ', ') . $trm;
        if (mb_strlen($glossary . $add) > 700) break;
        $glossary .= $add;
        $used++;
    }
    $glossary .= '.';

    $db->execute(
        "INSERT INTO defaults_oserp (key, value) VALUES ('whisper_glossary', :v)
         ON CONFLICT (key) DO UPDATE SET value = :v2, mtime = NOW()",
        [':v' => $glossary, ':v2' => $glossary]
    );

    resultInfo(true, '', [
        'glossary' => $glossary,
        'term_count' => $used,
        'from_articles' => $used - $instructionCount > 0 ? $used - $instructionCount : 0,
        'from_instructions' => $instructionCount,
    ]);
}
