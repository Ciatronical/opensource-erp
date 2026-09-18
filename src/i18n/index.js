// src/i18n/index.js
import { createI18n } from 'vue-i18n'
import routeMessages from 'virtual:oserp-route-messages'

/**
 * Deep merge helper function
 * Merged nested objects rekursiv
 */
function deepMerge(target, source) {
    const output = { ...target }

    for (const key in source) {
        if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
            // Rekursiv mergen für verschachtelte Objekte
            output[key] = deepMerge(target[key] || {}, source[key])
        } else {
            // Primitive Werte direkt überschreiben
            output[key] = source[key]
        }
    }

    return output
}

// ───────────────────────── Sprachdateien nach Bedarf ─────────────────────────
//
// Die 789 Sprachdateien der 21 Sprachen sind zusammen rund 7 MB JSON. Früher
// lud `import.meta.glob(..., { eager: true })` alle davon in den Initial-Load —
// jeder Benutzer bezahlte also 21 Sprachen, um eine zu lesen.
//
// Ohne `eager` liefert der Glob je Datei nur eine Ladefunktion. Nach Sprache
// gruppiert und über `manualChunks` (vite.config.js) zu einem Chunk je Sprache
// zusammengefasst, geht beim Start genau eine Sprache über die Leitung.
//
// Was der Router beim Modul-Import braucht — die `routes.*`-Pfade ALLER
// Sprachen für die URL-Aliase —, liefert das virtuelle Modul
// `virtual:oserp-route-messages` vorab: ~38 kB für alle Sprachen zusammen.
const localeModules = import.meta.glob('../**/locales/*.json')

const loadersByLocale = {}
for (const path in localeModules) {
    // Beispiel: ../core/views/customer-vendor/locales/de.json
    const matched = path.match(/locales\/([a-z-]+)\.json$/i)
    if (!matched) continue

    const locale = matched[1].toLowerCase()
    if (!loadersByLocale[locale]) loadersByLocale[locale] = []
    loadersByLocale[locale].push(localeModules[path])
}

/** Alle Sprachen, für die es Übersetzungen im Quellcode gibt */
export const AVAILABLE_LOCALES = Object.keys(loadersByLocale).sort()

const loadedLocales = new Set()
const pendingLocales = new Map()

/**
 * Lädt alle Sprachdateien einer Sprache nach und hängt sie an i18n
 *
 * Mehrfachaufrufe sind unkritisch: eine bereits geladene Sprache kehrt sofort
 * zurück, ein laufender Ladevorgang wird geteilt statt neu gestartet.
 *
 * @param {string} locale - Sprachkürzel, z. B. 'de'
 * @return {Promise<void>}
 */
export async function loadLocaleMessages(locale) {
    if (!locale || loadedLocales.has(locale)) return
    if (pendingLocales.has(locale)) return pendingLocales.get(locale)

    const loaders = loadersByLocale[locale]
    if (!loaders) {
        // Unbekannte Sprache: nicht dauernd neu versuchen, der Fallback greift
        loadedLocales.add(locale)
        return
    }

    const pending = Promise.all(loaders.map(load => load()))
        .then(modules => {
            let messages = {}
            for (const module of modules) {
                messages = deepMerge(messages, module.default || module)
            }
            // mergeLocaleMessage statt setLocaleMessage: die Routen-Pfade
            // stehen schon drin und dürfen nicht verloren gehen.
            i18n.global.mergeLocaleMessage(locale, messages)
            loadedLocales.add(locale)
        })
        .finally(() => {
            pendingLocales.delete(locale)
        })

    pendingLocales.set(locale, pending)
    return pending
}

const LOCALE_STORAGE_KEY = 'oserp-locale'

/**
 * Zuletzt verwendete Sprache aus dem Browser-Speicher
 *
 * Wird gebraucht, bevor die Session geladen ist: der Router baut seine
 * URL-Tabelle beim Modul-Import und muss die Sprache da bereits kennen.
 * Ohne das startet die App immer deutsch und die URLs würden nach dem
 * Login sichtbar umspringen.
 *
 * @return {string} Sprachkürzel, im Zweifel 'de'
 */
export function getStoredLocale() {
    try {
        return localStorage.getItem(LOCALE_STORAGE_KEY) || 'de'
    } catch {
        return 'de'
    }
}

/**
 * Merkt die Sprache für den nächsten Seitenaufruf
 *
 * @param {string} locale - Sprachkürzel
 */
export function storeLocale(locale) {
    try {
        localStorage.setItem(LOCALE_STORAGE_KEY, locale)
    } catch {
        // privater Modus o. Ä. — dann eben ohne Merken
    }
}

/** Sprache, auf die zurückgefallen wird, wenn eine Übersetzung fehlt */
export const FALLBACK_LOCALE = 'en'

// Sprachkonfiguration für i18n
const i18n = createI18n({
    legacy: false, // Wichtig für den Composition API-Modus
    globalInjection: true, // Erhält den globalen $t-Zugriff
    locale: getStoredLocale(), // Zuletzt gewählte Sprache, sonst Deutsch
    fallbackLocale: FALLBACK_LOCALE, // Fallback-Sprache setzen
    // Vorab nur die Routen-Pfade aller Sprachen (Router braucht sie sofort),
    // der Rest kommt über loadLocaleMessages() nach.
    messages: routeMessages,
})

/**
 * Stellt sicher, dass eine Sprache samt Fallback benutzbar ist
 *
 * Muss abgewartet werden, bevor die App gemountet oder die Sprache gewechselt
 * wird — sonst rendert die Oberfläche kurz mit leeren Übersetzungen.
 *
 * @param {string} locale - Sprachkürzel
 * @return {Promise<void>}
 */
export async function ensureLocale(locale) {
    await Promise.all([
        loadLocaleMessages(locale),
        loadLocaleMessages(FALLBACK_LOCALE),
    ])
}

export default i18n
