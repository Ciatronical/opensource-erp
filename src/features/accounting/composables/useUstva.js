// src/features/accounting/composables/useUstva.js
//
// Composable fuer die Umsatzsteuer-Voranmeldung.

import { ref } from 'vue'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'

const API_URL = '/api/accounting/'

async function post(action, payload = {}) {
    const store = oserpStore()
    const { data } = await axios.post(API_URL, {
        action,
        employee_id: store.session?.logged_in_employee?.id,
        ...payload,
    })
    if (!data.success) {
        const err = new Error(data.text || 'API_ERROR')
        err.code = data.text
        err.payload = data.payload
        throw err
    }
    return data.payload?.results ?? data.payload ?? null
}

export function useUstva() {
    const loading = ref(false)

    async function run(fn) {
        loading.value = true
        try { return await fn() } finally { loading.value = false }
    }

    const fetchYear    = (year, method)              => run(() => post('getUstvaYear', { year, method }))
    const fetchPeriod  = (year, period, method)      => run(() => post('getUstva', { year, period, method }))
    const fetchDetails = (year, period, kz, method)  => run(() => post('getUstvaDetails', { year, period, kz, method }))
    const file         = (payload)                   => run(() => post('fileUstva', payload))
    const reopen       = (year, period)              => run(() => post('reopenUstva', { year, period }))
    const fetchMapping = ()                          => run(() => post('getUstvaMapping'))
    const saveMapping  = (payload)                   => run(() => post('saveUstvaMapping', payload))
    const deleteMapping= (id)                        => run(() => post('deleteUstvaMapping', { id }))

    /**
     * CSV herunterladen. Das Backend liefert Base64, damit die Antwort JSON
     * bleibt und die Fehlerbehandlung dieselbe ist wie bei allen anderen Calls.
     */
    async function exportCsv(year, period, method) {
        const res = await run(() => post('exportUstvaCsv', { year, period, method }))
        const bytes = Uint8Array.from(atob(res.content), c => c.charCodeAt(0))
        const url = URL.createObjectURL(new Blob([bytes], { type: res.mimetype }))
        const a = document.createElement('a')
        a.href = url
        a.download = res.filename
        a.click()
        URL.revokeObjectURL(url)
        return res
    }

    return {
        loading,
        fetchYear, fetchPeriod, fetchDetails, file, reopen,
        fetchMapping, saveMapping, deleteMapping, exportCsv,
    }
}
