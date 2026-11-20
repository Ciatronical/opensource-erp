// src/core/composables/useVendorPartSync.js
//
// Artikelabgleich je Lieferant: Lauf starten, abbrechen und den Stand abfragen
// (backend/api/parts/vendor_part_sync.php).

import axios from 'axios'

const API_URL = '/api/parts/'

async function call(action, params = {}, file = null) {
    // Mit Preisdatei als Formular, sonst als JSON
    let body = { action, ...params }
    if (file) {
        body = new FormData()
        body.append('action', action)
        for (const [key, value] of Object.entries(params)) {
            if (value !== null && value !== undefined) body.append(key, String(value))
        }
        body.append('file', file, file.name)
    }
    const res = await axios.post(API_URL, body)
    if (!res.data.success) {
        // text trägt den Fehlercode, payload die Meldung
        const fehler = new Error(res.data.payload || res.data.text || action)
        fehler.code = res.data.text || ''
        throw fehler
    }
    return res.data.payload
}

export function useVendorPartSync() {
    const fetchStatus = (vendor_id) => call('getVendorPartSync', { vendor_id })
    const start = (params, file = null) => call('startVendorPartSync', params, file)
    const cancel = (sync_id) => call('cancelVendorPartSync', { sync_id })

    return { fetchStatus, start, cancel }
}
