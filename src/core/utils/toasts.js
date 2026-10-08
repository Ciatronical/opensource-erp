// core/utils/toasts.js
//
// Einblendungen oben rechts. Bewusst nicht über SweetAlert2: das kann nur ein
// Fenster zur selben Zeit zeigen, jede Einblendung ersetzte also einen offenen
// Dialog — etwa den Hinweis auf ein fälliges Datenbank-Update, sobald eine
// Chatnachricht eintraf. Der Dialog galt dann als abgebrochen, ohne dass der
// Benutzer ihn je bestätigt hätte.
//
// Hier nur der Zustand; angezeigt wird er von toast-stack.vue in App.vue.

import { shallowReactive } from 'vue'

// config
const duration = 5000
const maxVisible = 5

let nextId = 1

/** Die sichtbaren Einblendungen, neueste zuletzt */
export const toastItems = shallowReactive([])

/**
 * Blendet eine Meldung ein
 *
 * @param {Object} item - { icon, title, text, onClick, timer, timerProgressBar }
 * @returns {Promise<void>} erfüllt, sobald die Einblendung verschwunden ist
 */
function show({ icon = 'info', title = '', text = '', onClick = null, timer = duration, timerProgressBar = true }) {
    return new Promise((resolve) => {
        toastItems.push({ id: nextId++, icon, title, text, onClick, timer, timerProgressBar, resolve })
        // Bei einer Flut von Meldungen die ältesten verwerfen, statt den Bildschirm zu füllen
        while (toastItems.length > maxVisible) {
            closeToast(toastItems[0].id)
        }
    })
}

/**
 * Entfernt eine Einblendung
 *
 * @param {number} id
 */
export function closeToast(id) {
    const index = toastItems.findIndex(item => item.id === id)
    if (index === -1) return
    const [item] = toastItems.splice(index, 1)
    item.resolve()
}

// opts: { timer, timerProgressBar } — abweichende Anzeigedauer in ms bzw. ohne Fortschrittsbalken
export const info = (text, opts = {}) => show({ ...opts, icon: 'info', title: text })
export const success = (text, opts = {}) => show({ ...opts, icon: 'success', title: text })
export const warning = (text, opts = {}) => show({ ...opts, icon: 'warning', title: text })
export const error = (text, opts = {}) => show({ ...opts, icon: 'error', title: text })

/**
 * Einblendung mit Inhalt und Klickziel — der Text steht direkt drin (z.B. eine
 * eingehende Chatnachricht), ein Klick fuehrt an die passende Stelle.
 * Solange die Maus darauf steht, laeuft der Timer nicht weiter: laengere Texte
 * waeren sonst weg, bevor man sie zu Ende gelesen hat.
 */
export const clickable = (title, text, onClick, opts = {}) => show({ ...opts, icon: 'info', title, text, onClick })
