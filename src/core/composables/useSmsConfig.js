// src/core/composables/useSmsConfig.js
//
// SMS-Einstellungen (getSmsConfig) einmal je Firma laden und teilen – die
// Telefonknöpfe (phone-action-bar) stehen dutzendfach auf einer Seite.

import { ref } from 'vue'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'

const config = ref({ configured: false, cost: 0, sender: '', fallback: true, link_days: 90, public_url: '' })
let loadedFor = null
let pending = null

/**
 * @returns {{ smsConfig: import('vue').Ref, loadSmsConfig: (force?: boolean) => Promise<object> }}
 */
export function useSmsConfig() {
    const oserp = oserpStore()

    async function loadSmsConfig(force = false) {
        const client = oserp.session?.client ?? null
        if (!force && loadedFor === client) return config.value
        if (!force && pending) return pending
        pending = axios.post('/api/sms/', { action: 'getSmsConfig' })
            .then(resp => {
                if (resp.data?.success) config.value = { ...config.value, ...resp.data.payload }
                loadedFor = client
                return config.value
            })
            .catch(() => config.value)
            .finally(() => { pending = null })
        return pending
    }

    return { smsConfig: config, loadSmsConfig }
}
