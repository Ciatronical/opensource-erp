<?php
// backend/api/lib/whisper.php

/**
 * Transkription über den lokalen Whisper-Dienst (oserp-whisper, faster-whisper)
 *
 * Gemeinsam genutzt von der Spracheingabe im Browser (voice.php) und der
 * Erkennung von Auftragsbestätigungen in Anrufen und WhatsApp-Sprachnachrichten
 * (backend/cli/auftragsbestaetigung.php). Endpunkt, Token und Fachbegriffe
 * kommen aus defaults_oserp (whisper_url, whisper_token, whisper_glossary); der
 * Aufrufer lädt sie mit seiner ohnehin laufenden Konfigurationsabfrage.
 */

/**
 * Schlüssel in defaults_oserp, die whisperTranscribe() auswertet
 *
 * @return array
 */
function whisperConfigKeys(): array {
    return ['whisper_url', 'whisper_token', 'whisper_glossary'];
}

/**
 * Audio transkribieren
 *
 * Der Dienst bekommt das gepflegte Fachbegriffe-Glossar als initial_prompt mit —
 * das hebt die Trefferquote bei Kfz-Teilen, Arbeitsschritten und Namen. Er
 * nimmt jedes Format, das ffmpeg lesen kann (wav, ogg/opus, webm).
 *
 * @param string $audio   Audio-Bytes
 * @param array  $config  defaults_oserp-Werte (whisperConfigKeys())
 * @param string $lang    "de" oder "auto"
 * @param int    $timeout Sekunden — lange Anrufe brauchen auf der CPU entsprechend
 * @return array          ['text' => string, 'language' => ?string, 'duration' => ?float]
 * @throws ApiError       WHISPER_UNREACHABLE / WHISPER_HTTP_<code> / WHISPER_FAILED
 */
function whisperTranscribe(string $audio, array $config, string $lang = 'de', int $timeout = 120): array {
    $url   = rtrim(trim($config['whisper_url'] ?? '') ?: 'http://127.0.0.1:3002', '/') . '/transcribe';
    $token = trim($config['whisper_token'] ?? '');

    $headers = [
        'Content-Type: application/octet-stream',
        'X-Whisper-Lang: ' . ($lang === 'auto' ? 'auto' : 'de'),
    ];
    if ($token !== '') {
        $headers[] = 'X-Whisper-Token: ' . $token;
    }
    // Glossar base64, damit Umlaute/Kommas den HTTP-Header nicht sprengen
    $glossary = trim($config['whisper_glossary'] ?? '');
    if ($glossary !== '') {
        $headers[] = 'X-Whisper-Prompt: ' . base64_encode($glossary);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $audio,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code === 0) {
        throw new ApiError('WHISPER_UNREACHABLE', 'Whisper-Dienst nicht erreichbar (' . $err . '). Läuft oserp-whisper?');
    }
    if ($code !== 200) {
        throw new ApiError('WHISPER_HTTP_' . $code, substr((string)$resp, 0, 300));
    }

    $result = json_decode($resp, true);
    if (!is_array($result) || empty($result['ok'])) {
        throw new ApiError('WHISPER_FAILED', 'Transkription fehlgeschlagen');
    }

    return [
        'text'     => trim($result['text'] ?? ''),
        'language' => $result['language'] ?? null,
        'duration' => $result['duration'] ?? null,
    ];
}
