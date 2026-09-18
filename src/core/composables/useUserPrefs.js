// src/core/composables/useUserPrefs.js

import { watch } from 'vue'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { ensureLocale } from '@/i18n'

/**
 * Wendet benutzerspezifische Einstellungen an (Dark Mode, Sprache).
 * Wird in App.vue aufgerufen und reagiert auf Session-Änderungen.
 */
export function useUserPrefs() {
    const theme = useTheme()
    const { locale } = useI18n()
    const oserp = oserpStore()

    async function apply() {
        // Dark Mode
        const dark = oserp.getConfigValue('dark_mode', false)
        const isDark = dark === true || dark === 'true' || dark === 't' || dark === '1'
        theme.global.name.value = isDark ? 'dark' : 'light'

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

    // Auf Session-Änderungen reagieren (Login, Firmenwechsel)
    watch(() => oserp.session?.company_config?.company_employee_config, () => {
        apply()
    }, { deep: true })

    // Sofort anwenden falls Session schon geladen
    apply()
}
