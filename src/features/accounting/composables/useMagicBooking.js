// src/features/accounting/composables/useMagicBooking.js
//
// "Magisch Buchen": Belegquellen (Postfach, WhatsApp, Ordner, Portal) pflegen,
// die Belegsuche anstossen, Vorschlaege laden und gesammelt buchen.

import { ref } from 'vue'
import axios from 'axios'

const API_URL = '/api/accounting/'

async function call(action, params = {}) {
    const res = await axios.post(API_URL, { action, ...params })
    if (!res.data.success) throw new Error(res.data.payload || res.data.text || action)
    return res.data.payload
}

export function useMagicBooking() {
    const loading = ref(false)

    // ── Quellen & Einstellungen ──
    const fetchSources       = (params = {}) => call('getBelegQuellen', params)
    const saveSource         = (source) => call('saveBelegQuelle', source)
    const deleteSource       = (id) => call('deleteBelegQuelle', { id })
    const testSource         = (params) => call('testBelegQuelle', params)
    const saveSettings       = (settings) => call('saveBelegsucheSettings', settings)
    const saveRecording      = (params) => call('savePortalRecording', params)

    // ── Suche & Vorschlaege ──
    async function runSearch(params = {}) {
        loading.value = true
        try { return await call('runBelegSuche', params) }
        finally { loading.value = false }
    }
    const fetchProposals = () => call('getMagicProposals')
    async function bookProposals(bookingIds) {
        loading.value = true
        try { return await call('magicBook', { booking_ids: bookingIds }) }
        finally { loading.value = false }
    }

    const documentPdfUrl = (documentId) => `${API_URL}?action=getDocumentPdf&document_id=${documentId}`

    return { loading, fetchSources, saveSource, deleteSource, testSource, saveSettings, saveRecording, runSearch, fetchProposals, bookProposals, documentPdfUrl }
}
