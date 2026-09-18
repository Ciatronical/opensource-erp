// src/core/constants/aiModels.js

/**
 * Modellwahl der KI-Assistenten
 *
 * Die gleiche Liste steht im Backend in backend/api/lib/ai_model.php.
 * Wer hier etwas ändert, ändert sie dort mit — das Backend prüft jede
 * Modellangabe gegen seine eigene Liste und verwirft Unbekanntes.
 */

/**
 * Auswählbare Modelle
 *
 * `label` ist der Produktname und wird nicht übersetzt, `hintKey` zeigt auf
 * die Kurzbeschreibung in den Sprachdateien.
 */
export const AI_MODELS = [
    { value: 'claude-fable-5-1', label: 'Claude Fable 5.1', hintKey: 'aiModels.hints.fable51' },
    { value: 'claude-opus-5', label: 'Claude Opus 5', hintKey: 'aiModels.hints.opus5' },
    { value: 'claude-sonnet-5', label: 'Claude Sonnet 5', hintKey: 'aiModels.hints.sonnet5' },
    { value: 'claude-haiku-4-5', label: 'Claude Haiku 4.5', hintKey: 'aiModels.hints.haiku45' },
];

/**
 * Die KI-Assistenten mit ihrem Schlüssel in defaults_oserp
 *
 * `prompt: true` heisst: Der Benutzer darf das Modell auch am Prompt
 * umschalten; diese Wahl gilt nur für seine Sitzung.
 * `unsupported` zählt Modelle auf, die dieser Assistent nicht verträgt.
 */
export const AI_ASSISTANTS = [
    { id: 'car_chat', key: 'car_chat_ai_model', labelKey: 'aiModels.assistants.carChat', prompt: true },
    { id: 'weroni', key: 'weroni_ai_model', labelKey: 'aiModels.assistants.weroni', prompt: true },
    { id: 'sales_text', key: 'sales_text_ai_model', labelKey: 'aiModels.assistants.salesText', prompt: true },
    { id: 'ai_positions', key: 'ai_positions_ai_model', labelKey: 'aiModels.assistants.aiPositions', prompt: true },
    { id: 'filemanager', key: 'filemanager_ai_model', labelKey: 'aiModels.assistants.filemanager', prompt: true },
    { id: 'accounting', key: 'accounting_ai_model', labelKey: 'aiModels.assistants.accounting', prompt: false },
    { id: 'business_card', key: 'business_card_ai_model', labelKey: 'aiModels.assistants.businessCard', prompt: false },
    { id: 'phone_search', key: 'phone_search_ai_model', labelKey: 'aiModels.assistants.phoneSearch', prompt: false },
    { id: 'call_transcript', key: 'call_transcript_ai_model', labelKey: 'aiModels.assistants.callTranscript', prompt: false },
    // Die Prognose erzwingt den Aufruf ihres Werkzeugs; Claude Fable 5.1
    // lehnt einen erzwungenen Werkzeugaufruf ab (siehe lib/ai_model.php).
    { id: 'banking', key: 'banking_ai_model', labelKey: 'aiModels.assistants.banking', prompt: false,
      unsupported: ['claude-fable-5-1'] },
];

/**
 * Liefert die Beschreibung eines Assistenten
 *
 * Das Feld heisst `id` und nicht `name`: `name:` in einer Objektliteralliste
 * liest npm run check:routes als Route-Namen und meldet sie als unbekannt.
 *
 * @param {string} id Kennung aus AI_ASSISTANTS
 * @returns {object|null}
 */
export function aiAssistant(id) {
    return AI_ASSISTANTS.find(assistant => assistant.id === id) || null;
}

/**
 * Prüft, ob eine Modellangabe auswählbar ist
 *
 * @param {string} value Modell-ID
 * @returns {boolean}
 */
export function isAiModel(value) {
    return AI_MODELS.some(model => model.value === value);
}

/**
 * Die Modelle, die ein bestimmter Assistent verträgt
 *
 * @param {string} id Kennung aus AI_ASSISTANTS
 * @returns {Array} Einträge aus AI_MODELS
 */
export function aiModelsFor(id) {
    const unsupported = aiAssistant(id)?.unsupported || [];
    return AI_MODELS.filter(model => !unsupported.includes(model.value));
}

/**
 * Anzeigename eines Modells, für unbekannte Werte die ID selbst
 *
 * @param {string} value Modell-ID
 * @returns {string}
 */
export function aiModelLabel(value) {
    return AI_MODELS.find(model => model.value === value)?.label || value || '';
}
