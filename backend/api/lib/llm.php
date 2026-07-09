<?php
// backend/api/lib/llm.php

/**
 * Wiederverwendbarer Client für den lokal gehosteten LLM (Ollama, OpenAI-kompatibel).
 *
 * Endpunkt und Modell kommen aus defaults_oserp (Schlüssel llm_url, llm_model),
 * mit Fallback auf den lokalen Ollama-Dienst. Der Dienst läuft als systemd-User-Unit
 * oserp-ollama und nutzt automatisch die Intel-iGPU (IPEX-LLM) oder CPU.
 * Setup und Betrieb: dev/lokale-ki-ollama.md
 *
 * Bewusst OpenAI-kompatibel (/v1/chat/completions), damit einzelne Features per
 * Konfiguration zwischen lokalem Modell und Cloud-Anbietern umgeschaltet werden können.
 */

/**
 * Sendet eine Chat-Anfrage an den lokalen LLM und liefert die Antwort als Text.
 *
 * @param array $messages Liste von ['role' => 'system|user|assistant', 'content' => '...']
 * @param array $opts     ['model' => string, 'temperature' => float, 'max_tokens' => int, 'json' => bool, 'timeout' => int]
 * @return string         Antworttext des Assistenten
 * @throws ApiError       LLM_UNAVAILABLE (Dienst nicht erreichbar) / LLM_ERROR (ungültige Antwort)
 * @testdata {"messages": [{"role": "user", "content": "Antworte mit genau einem Wort: Testerfolg?"}]}
 */
function oserpLlmChat(array $messages, array $opts = []): string {
    $db = DbhCompany::begin();

    // Endpunkt und Modell aus der Konfiguration (mit Fallback auf lokalen Dienst)
    $config  = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('llm_url', 'llm_model')"
    );
    $baseUrl = rtrim((trim($config['llm_url'] ?? '') ?: 'http://127.0.0.1:11434'), '/');
    $model   = ($opts['model'] ?? '') ?: (trim($config['llm_model'] ?? '') ?: 'qwen3:30b-a3b-instruct-2507-q4_K_M');

    $payload = [
        'model'       => $model,
        'messages'    => array_values($messages),
        'stream'      => false,
        'temperature' => $opts['temperature'] ?? 0.2,
    ];
    if (!empty($opts['max_tokens'])) $payload['max_tokens']      = (int)$opts['max_tokens'];
    if (!empty($opts['json']))       $payload['response_format'] = ['type' => 'json_object'];

    $ch = curl_init($baseUrl . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)($opts['timeout'] ?? 120),
    ]);
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $code !== 200) {
        throw new ApiError('LLM_UNAVAILABLE',
            'Lokaler LLM nicht erreichbar (HTTP ' . $code . '). Läuft der Dienst oserp-ollama? Siehe dev/lokale-ki-ollama.md');
    }

    $data    = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || $content === '') {
        throw new ApiError('LLM_ERROR', 'Leere oder ungültige Antwort vom lokalen LLM');
    }

    return trim($content);
}
