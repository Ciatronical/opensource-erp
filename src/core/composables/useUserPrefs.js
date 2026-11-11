// src/core/composables/useUserPrefs.js

import { watch } from 'vue'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { ensureLocale } from '@/i18n'
import { applyPalette, paletteKey, DEFAULT_PALETTE, THEME_MODES } from '@/core/theme/palettes.js'

/**
 * Wendet benutzerspezifische Einstellungen an (Farbschema, Modus, Sprache).
 * Wird in App.vue aufgerufen und reagiert auf Session-Änderungen.
 */

/**
 * Gewählte Palette: Benutzer (theme_palette) vor Firma (defaults_oserp) vor Blau.
 */
export function resolvePalette(oserp) {
    return paletteKey(oserp.getConfigValue('theme_palette', ''))
        || paletteKey(oserp.getClientDefaultValue('theme_palette', ''))
        || DEFAULT_PALETTE
}

/**
 * Gewählter Modus light/dark/system. Ohne theme_mode gilt der frühere
 * Schalter dark_mode, damit niemand nach dem Update plötzlich hell sieht.
 */
export function resolveThemeMode(oserp) {
    const mode = oserp.getConfigValue('theme_mode', '')
    if (THEME_MODES.includes(mode)) return mode
    const dark = oserp.getConfigValue('dark_mode', false)
    return (dark === true || dark === 'true' || dark === 't' || dark === '1') ? 'dark' : 'light'
}

export function useUserPrefs() {
    const theme = useTheme()
    const { locale } = useI18n()
    const oserp = oserpStore()

    async function apply() {
        // Farbschema und Modus ('system' folgt dem Betriebssystem)
        applyPalette(theme, resolvePalette(oserp))
        theme.global.name.value = resolveThemeMode(oserp)

        // Locale
        const userLocale = oserp.getConfigValue('locale', '')
        const supportedLocales = ['de', 'en', 'pl', 'uk', 'ru', 'fr', 'nl', 'da', 'nb', 'sv', 'et', 'lv', 'lt', 'es', 'it', 'pt', 'cs', 'ro', 'tr', 'fi', 'zh']
        if (userLocale && supportedLocales.includes(userLocale)) {
            // Die Sprachdateien liegen in einem eigenen Chunk je Sprache und
            // sind beim Wechsel noch nicht da — erst laden, dann umschalten,
            // sonst steht die Oberfläche kurz ohne Übersetzungen da.
            await ensureLocale(userLocale)
            locale.value = userLocale
        }
    }

    // Auf Session-Änderungen reagieren (Login, Firmenwechsel, neuer
    // Firmenstandard nach dem Speichern der Firmenkonfiguration)
    watch(() => [oserp.session?.company_config?.company_employee_config, oserp.session?.company_config?.defaults_oserp?.theme_palette], () => {
        apply()
    }, { deep: true })

    // Sofort anwenden falls Session schon geladen
    apply()
}
