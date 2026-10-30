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
 * Wer hier etwas ändert, ändert sie dort mit. Hintergrund, Vertraeglichkeit
 * einzelner Modelle und die Schritte fuer ein neues Modell: dev/ki-modelle.md
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
        // Motorfamilien über Marken hinweg erkennen braucht Fachwissen — daher Opus
        'special_tools'    => ['key' => 'special_tools_ai_model',    'default' => 'claude-opus-5'],
        // Fremde Gutachten-PDFs: lange Kalkulationstabellen, oft gescannt — Opus liest am sichersten
        'gutachten_import' => ['key' => 'gutachten_import_ai_model', 'default' => 'claude-opus-5'],
        // Weroni Rechtsprüfung des Gutachtens: Abwägung mit Urteilen und Beträgen — Opus
        'gutachten_review' => ['key' => 'gutachten_review_ai_model', 'default' => 'claude-opus-5'],
        // Kleinanzeigen: Anzeigentexte und Antwortvorschläge — kurze Texte, Haiku reicht
        'kleinanzeigen'    => ['key' => 'kleinanzeigen_ai_model',    'default' => 'claude-haiku-4-5'],
        // eBay-Produktseiten: Titel mit Suchbegriffen, Aufbau, Artikelmerkmale — verkaufen soll es, Sonnet
        'ebay'             => ['key' => 'ebay_ai_model',             'default' => 'claude-sonnet-5'],

        // Hintergrundverarbeitung ohne Prompt — nur Mandanteneinstellung
        'accounting'       => ['key' => 'accounting_ai_model',       'default' => 'claude-opus-5'],
        'business_card'    => ['key' => 'business_card_ai_model',    'default' => 'claude-haiku-4-5'],
        'phone_search'     => ['key' => 'phone_search_ai_model',     'default' => 'claude-sonnet-5'],
        'call_transcript'  => ['key' => 'call_transcript_ai_model',  'default' => 'claude-haiku-4-5'],
        // Auftragsbestätigung: Zusage/Absage aus Anruf, WhatsApp, E-Mail erkennen — Zwischentöne
        // („ja, aber erst nach Rücksprache“) und Bedingungen sicher trennen, daher Sonnet
        'order_confirm'    => ['key' => 'order_confirm_ai_model',    'default' => 'claude-sonnet-5'],
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
 * Schlüssel des zentralen Schalters für externe KI (Cloud)
 *
 * Steht neben 'anthropic_api_key' in jeder Konfigurationsabfrage eines
 * Assistenten, damit resolveAiModel() den Schalter ohne zweite Abfrage sieht.
 *
 * @return string Schlüssel in defaults_oserp
 */
function aiExternalConfigKey() {
    return 'ai_external_enabled';
}

/**
 * Ist externe KI (Anthropic-Cloud) für diesen Mandanten freigegeben?
 *
 * Firmenkonfiguration → KI und Gesundheit → „Externe KI (Cloud) verwenden".
 * Der Upstall legt den Schlüssel an: bestehende Installationen mit Schlüssel
 * bleiben an, neue Installationen starten aus. Fehlt der Schlüssel in der
 * übergebenen Konfiguration (Aufrufer hat ihn nicht geladen), gilt „an" —
 * jede Konfigurationsabfrage eines Assistenten nimmt ihn deshalb mit.
 *
 * @param array $config Bereits geladene defaults_oserp-Werte (key => value)
 * @return bool
 */
function aiExternalEnabled($config) {
    if (!array_key_exists(aiExternalConfigKey(), $config) || $config[aiExternalConfigKey()] === null) {
        return true;
    }
    return in_array(strtolower(trim((string)$config[aiExternalConfigKey()])), ['1', 'true', 't'], true);
}

/**
 * Bricht ab, wenn externe KI abgeschaltet ist
 *
 * Zentraler Riegel: resolveAiModel() ruft ihn vor jedem Anthropic-Aufruf.
 * Aufrufer ohne Modellwahl (Hintergrunddienste) rufen ihn selbst.
 *
 * @param array $config Bereits geladene defaults_oserp-Werte
 * @throws ApiError AI_EXTERNAL_DISABLED
 */
function aiAssertExternalEnabled($config) {
    if (!aiExternalEnabled($config)) {
        throw new ApiError('AI_EXTERNAL_DISABLED',
            'Externe KI (Cloud) ist in der Firmenkonfiguration abgeschaltet (KI und Gesundheit).');
    }
}

/**
 * Ermittelt das Modell für einen Assistenten
 *
 * Vorher der zentrale Riegel: ist externe KI abgeschaltet, gibt es kein
 * Modell und damit keinen Aufruf (AI_EXTERNAL_DISABLED).
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
 * @throws ApiError AI_EXTERNAL_DISABLED
 */
function resolveAiModel($config, $assistant, $override = null) {
    aiAssertExternalEnabled($config);
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

/**
 * Liefert den Antworttext einer Messages-API-Antwort
 *
 * Neuere Modelle (z. B. claude-opus-5) stellen dem Text einen
 * „thinking“-Block voran. Wer nur content[0].text liest, bekommt dann
 * einen leeren String und meldet „Leere Antwort von Claude“. Hier werden
 * alle Textblöcke in Reihenfolge zusammengefügt; Blöcke anderer Typen
 * (thinking, tool_use) werden übersprungen.
 *
 * @param array|null $responseData Dekodierte JSON-Antwort der Messages-API
 * @return string Zusammengefügter Antworttext, leer wenn kein Textblock vorhanden
 */
function aiResponseText($responseData) {
    $parts = [];
    foreach (($responseData['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $parts[] = $block['text'];
        }
    }
    return trim(implode("\n", $parts));
}
