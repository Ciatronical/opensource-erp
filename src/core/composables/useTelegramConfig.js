// src/core/composables/useTelegramConfig.js
//
// Telegram-Einstellungen (getTelegramConfig) einmal je Firma laden und teilen –
// Reiter, Kontakt → Telegram und Kontakthistorie fragen dasselbe.

import { ref } from 'vue'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'

const config = ref({ configured: false, bot_username: '', link: '' })
let loadedFor = null
let pending = null

/**
 * @returns {{ telegramConfig: import('vue').Ref, loadTelegramConfig: (force?: boolean) => Promise<object> }}
 */
export function useTelegramConfig() {
    const oserp = oserpStore()

    async function loadTelegramConfig(force = false) {
        const client = oserp.session?.client ?? null
        if (!force && loadedFor === client) return config.value
        if (!force && pending) return pending
        pending = axios.post('/api/telegram/', { action: 'getTelegramConfig' })
            .then(resp => {
                if (resp.data?.success) config.value = { ...config.value, ...resp.data.payload }
                loadedFor = client
                return config.value
            })
            .catch(() => config.value)
            .finally(() => { pending = null })
        return pending
    }

    return { telegramConfig: config, loadTelegramConfig }
}
