// src/core/composables/useHelpPanel.js
//
// Zustand der Hilfsanzeige (help-panel.vue): eine schmale, nicht modale
// Leiste am rechten Rand mit der Langfassung eines Hilfetexts. Es gibt nur
// eine; ein weiterer Klick tauscht den Inhalt aus, statt eine zweite zu
// öffnen. Der Zustand liegt auf Modulebene, damit Feld-Komponenten ihn ohne
// provide/inject erreichen.
//
// Verwendung:
//   import { useHelpPanel } from '@/core/composables/useHelpPanel.js'
//   const { zeigen } = useHelpPanel()
//   zeigen(t(field.label), t(field.help))
//
// Die Anzeige selbst rendert <help-panel /> (src/core/components/layout) —
// einmal je Ansicht, die sie anbietet.

import { reactive, readonly } from 'vue'

const zustand = reactive({
    offen: false,
    titel: '',
    text: '',
    /** Vorlage zum Kopieren (etwa ein Konfigurationsausschnitt), mit Kopierknopf */
    code: '',
    /** Text unter der Vorlage */
    nachtext: '',
})

/**
 * Öffnet die Hilfsanzeige mit diesem Text oder ersetzt den angezeigten
 *
 * @param {string} titel
 * @param {string} text Erklärung (Absätze mit Leerzeile)
 * @param {{code?: string, nachtext?: string}} extra Vorlage zum Kopieren und Text darunter
 */
function zeigen(titel, text, extra = {}) {
    zustand.titel = String(titel || '')
    zustand.text = String(text || '')
    zustand.code = String(extra.code || '')
    zustand.nachtext = String(extra.nachtext || '')
    zustand.offen = true
}

function schliessen() {
    zustand.offen = false
}

export function useHelpPanel() {
    return { zustand: readonly(zustand), zeigen, schliessen }
}
