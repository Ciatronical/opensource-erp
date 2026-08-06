// src/i18n/index.js
import { createI18n } from 'vue-i18n'

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

/**
 * Funktion zum Laden aller Sprachdateien
 * Lädt alle JSON-Dateien aus locales/ Ordnern rekursiv
 */
function loadLocaleMessages() {
    const messages = {}

    // Vite importiert alle passenden Dateien rekursiv
    const locales = import.meta.glob('../**/locales/*.json', { eager: true })

    //console.log('🌍 i18n: Loading locale files...')

    for (const path in locales) {
        // Beispiel: ../core/views/customer-vendor/locales/de.json
        const matched = path.match(/locales\/([a-z-]+)\.json$/i)

        if (matched && matched[1]) {
            const locale = matched[1]
            const content = locales[path].default || locales[path]

            //console.log(`  📄 Loading: ${path} → locale: ${locale}`)

            // Initialisiere locale falls noch nicht vorhanden
            if (!messages[locale]) {
                messages[locale] = {}
            }

            // Deep merge statt shallow Object.assign
            messages[locale] = deepMerge(messages[locale], content)
        }
    }

    //console.log('✅ i18n: Loaded locales:', Object.keys(messages))

    // Debug: Zeige Top-Level-Keys für jede Sprache
    /*
    for (const locale in messages) {
        console.log(`  🔑 ${locale}:`, Object.keys(messages[locale]))
    }
    */
    return messages
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

// Sprachkonfiguration für i18n
const i18n = createI18n({
    legacy: false, // Wichtig für den Composition API-Modus
    globalInjection: true, // Erhält den globalen $t-Zugriff
    locale: getStoredLocale(), // Zuletzt gewählte Sprache, sonst Deutsch
    fallbackLocale: 'en', // Fallback-Sprache setzen
    messages: loadLocaleMessages(), // Alle Sprachdateien laden
})

export default i18n
