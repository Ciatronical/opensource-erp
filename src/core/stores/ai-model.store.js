// src/core/stores/ai-model.store.js

/**
 * Modellwahl am Prompt — nur für die Sitzung des Benutzers
 *
 * Die dauerhafte Einstellung je Mandant steht in defaults_oserp und kommt
 * über den oserp-Store aus der Sitzungsantwort. Was der Benutzer am Prompt
 * wählt, überschreibt sie nur vorübergehend: Es liegt im sessionStorage und
 * ist mit dem Schliessen des Browsertabs wieder fort. In der Datenbank
 * landet davon nichts.
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';
import { oserpStore } from './oserp.store.js';
import { AI_ASSISTANTS, aiAssistant, isAiModel } from '@/core/constants/aiModels.js';

const STORAGE_KEY = 'oserp_ai_model_overrides';

/**
 * Liest die Auswahl der laufenden Sitzung
 *
 * Ein privates Fenster oder gesperrter Speicher darf die Assistenten nicht
 * lahmlegen — im Zweifel gilt einfach die Mandanteneinstellung.
 *
 * @returns {object} Zuordnung Assistent → Modell
 */
function readStored() {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (!raw) return {};

        const parsed = JSON.parse(raw);
        const valid = {};
        for (const assistant of AI_ASSISTANTS) {
            const value = parsed?.[assistant.id];
            if (isAiModel(value)) valid[assistant.id] = value;
        }
        return valid;
    } catch (error) {
        return {};
    }
}

export const aiModelStore = defineStore('aiModelStore', () => {
    const overrides = ref(readStored());

    /**
     * Schreibt die Auswahl in den sessionStorage
     */
    function persist() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(overrides.value));
        } catch (error) {
            // Kein Speicher verfügbar: die Wahl gilt dann nur bis zum Neuladen
        }
    }

    /**
     * Modell, das der Mandant für diesen Assistenten eingestellt hat
     *
     * @param {string} name Kennung aus AI_ASSISTANTS
     * @returns {string} Modell-ID oder '' wenn nichts eingestellt ist
     */
    function clientModel(name) {
        const assistant = aiAssistant(name);
        if (!assistant) return '';

        const oserp = oserpStore();
        const value = oserp.getClientDefaultValue(assistant.key, '');
        return isAiModel(value) ? value : '';
    }

    /**
     * Aktuell gültiges Modell: Wahl der Sitzung, sonst Mandanteneinstellung
     *
     * Ist beides leer, entscheidet das Backend anhand seiner Vorgabe.
     *
     * @param {string} name Kennung aus AI_ASSISTANTS
     * @returns {string} Modell-ID oder ''
     */
    function model(name) {
        return overrides.value[name] || clientModel(name);
    }

    /**
     * Wert für den API-Aufruf
     *
     * Ohne eigene Wahl wird nichts mitgeschickt — dann gilt im Backend die
     * Einstellung des Mandanten.
     *
     * @param {string} name Kennung aus AI_ASSISTANTS
     * @returns {string} Modell-ID oder ''
     */
    function requestModel(name) {
        return overrides.value[name] || '';
    }

    /**
     * Prüft, ob für diesen Assistenten eine eigene Wahl gilt
     *
     * @param {string} name Kennung aus AI_ASSISTANTS
     * @returns {boolean}
     */
    function isOverridden(name) {
        return !!overrides.value[name];
    }

    /**
     * Setzt das Modell für die Sitzung
     *
     * Die Mandanteneinstellung erneut zu wählen hebt die Abweichung auf.
     *
     * @param {string} name Kennung aus AI_ASSISTANTS
     * @param {string} value Modell-ID, leer setzt zurück
     */
    function setModel(name, value) {
        if (!aiAssistant(name) || !isAiModel(value) || value === clientModel(name)) {
            resetModel(name);
            return;
        }

        overrides.value = { ...overrides.value, [name]: value };
        persist();
    }

    /**
     * Verwirft die Wahl der Sitzung, es gilt wieder die Mandanteneinstellung
     *
     * @param {string} name Kennung aus AI_ASSISTANTS
     */
    function resetModel(name) {
        if (!(name in overrides.value)) return;

        const rest = { ...overrides.value };
        delete rest[name];
        overrides.value = rest;
        persist();
    }

    return {
        overrides,
        clientModel,
        model,
        requestModel,
        isOverridden,
        setModel,
        resetModel,
    };
});
