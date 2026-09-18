<?php
// backend/api/lib/ai_model.php

/**
 * Modellwahl der KI-Assistenten
 *
 * Jeder Assistent hat einen eigenen Schlüssel in defaults_oserp, also eine
 * eigene Einstellung je Mandant. Zusätzlich darf der Benutzer am Prompt ein
 * anderes Modell wählen; diese Wahl reist als Parameter mit dem Aufruf und
 * wird nirgends gespeichert — sie gilt nur für die Sitzung im Browser.
 *
 * Die gleiche Liste steht im Frontend in src/core/constants/aiModels.js.
 * Wer hier etwas ändert, ändert sie dort mit.
 */

/**
 * Auswählbare Modelle
 *
 * Gleichzeitig die Positivliste: Was hier nicht steht, geht nicht an die
 * Anthropic-API — weder aus der Mandanteneinstellung noch aus dem Request.
 *
 * @return array Liste der Modell-IDs
 */
function aiModelChoices() {
    return [
        'claude-fable-5-1',
        'claude-opus-5',
        'claude-sonnet-5',
        'claude-haiku-4-5',
    ];
}

/**
 * Registry der KI-Assistenten: Konfigurationsschlüssel und Vorgabemodell
 *
 * 'unsupported' zählt Modelle auf, die dieser Assistent nicht verträgt.
 *
 * @return array [assistent => ['key' => string, 'default' => string, 'unsupported' => array]]
 */
function aiAssistants() {
    return [
        // Assistenten mit Prompt — hier darf der Benutzer am Prompt umschalten
        'car_chat'         => ['key' => 'car_chat_ai_model',         'default' => 'claude-haiku-4-5'],
        'weroni'           => ['key' => 'weroni_ai_model',           'default' => 'claude-haiku-4-5'],
        'sales_text'       => ['key' => 'sales_text_ai_model',       'default' => 'claude-haiku-4-5'],
        'ai_positions'     => ['key' => 'ai_positions_ai_model',     'default' => 'claude-haiku-4-5'],
        'filemanager'      => ['key' => 'filemanager_ai_model',      'default' => 'claude-haiku-4-5'],

        // Hintergrundverarbeitung ohne Prompt — nur Mandanteneinstellung
        'accounting'       => ['key' => 'accounting_ai_model',       'default' => 'claude-opus-5'],
        'business_card'    => ['key' => 'business_card_ai_model',    'default' => 'claude-haiku-4-5'],
        'phone_search'     => ['key' => 'phone_search_ai_model',     'default' => 'claude-sonnet-5'],
        'call_transcript'  => ['key' => 'call_transcript_ai_model',  'default' => 'claude-haiku-4-5'],
        // Die Prognose erzwingt den Aufruf ihres Werkzeugs (tool_choice 'any').
        // Claude Fable 5.1 lehnt einen erzwungenen Werkzeugaufruf mit HTTP 400 ab.
        'banking'          => ['key' => 'banking_ai_model',          'default' => 'claude-opus-5',
                               'unsupported' => ['claude-fable-5-1']],
    ];
}

/**
 * Liefert den defaults_oserp-Schlüssel eines Assistenten
 *
 * Damit steht der Schlüsselname nur an einer Stelle und die Abfrage der
 * jeweiligen API-Funktion kann ihn in ihr vorhandenes SELECT aufnehmen.
 *
 * @param string $assistant Name aus aiAssistants()
 * @return string Schlüssel in defaults_oserp
 */
function aiModelConfigKey($assistant) {
    $assistants = aiAssistants();
    if (!isset($assistants[$assistant])) {
        throw new ApiError('AI_ASSISTANT_UNKNOWN', 'Unbekannter KI-Assistent: ' . $assistant);
    }
    return $assistants[$assistant]['key'];
}

/**
 * Ermittelt das Modell für einen Assistenten
 *
 * Reihenfolge: Wahl am Prompt, dann Mandanteneinstellung, dann Vorgabe.
 * Werte ausserhalb von aiModelChoices() werden verworfen — eine veraltete
 * oder verschriebene Modell-ID soll keinen API-Fehler auslösen, sondern auf
 * die Vorgabe zurückfallen.
 *
 * @param array $config Bereits geladene defaults_oserp-Werte (key => value)
 * @param string $assistant Name aus aiAssistants()
 * @param string|null $override Modellwahl aus dem Request (nur für die Sitzung)
 * @return string Modell-ID für die Anthropic-API
 */
function resolveAiModel($config, $assistant, $override = null) {
    $assistants = aiAssistants();
    if (!isset($assistants[$assistant])) {
        throw new ApiError('AI_ASSISTANT_UNKNOWN', 'Unbekannter KI-Assistent: ' . $assistant);
    }

    $choices = array_diff(aiModelChoices(), $assistants[$assistant]['unsupported'] ?? []);

    $requested = is_string($override) ? trim($override) : '';
    if ($requested !== '') {
        if (in_array($requested, $choices, true)) {
            return $requested;
        }
        writeLog("KI-Modell aus dem Request verworfen ($assistant): $requested", true, DLOG_WRN);
    }

    $configured = trim($config[$assistants[$assistant]['key']] ?? '');
    if ($configured !== '') {
        if (in_array($configured, $choices, true)) {
            return $configured;
        }
        writeLog("KI-Modell aus defaults_oserp verworfen ($assistant): $configured", true, DLOG_WRN);
    }

    return $assistants[$assistant]['default'];
}
