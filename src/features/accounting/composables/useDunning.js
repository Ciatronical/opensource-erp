// src/features/accounting/composables/useDunning.js

import { ref } from 'vue'
import axios from 'axios'

/**
 * Datenzugriff des Mahnwesens.
 *
 * Jede Funktion ist ein Aufruf und eine Abfrage: Vorschlag, Verlauf und
 * Konfiguration kommen jeweils als ein JSON aus der Datenbank. PDFs kommen
 * base64-kodiert und werden im Browser geöffnet.
 */
export function useDunning() {
    const loading = ref(false)
    const error = ref(null)

    async function call(action, params = {}) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', { action, ...params })
            if (response.data.success) return response.data.payload
            error.value = response.data.text || response.data.payload || action
            return null
        } catch (e) {
            error.value = e.message
            return null
        } finally {
            loading.value = false
        }
    }

    const fetchConfig   = () => call('getDunningConfig')
    const saveConfig    = (config) => call('saveDunningConfig', config)
    const fetchProposal = (search = '') => call('getDunningProposal', { search })
    const createRun     = (letters) => call('createDunningRun', { letters })
    const fetchHistory  = (filters = {}) => call('getDunningHistory', filters)
    const resendEmail   = (dunning_id, email = '') => call('sendDunningEmail', { dunning_id, email })
    const deleteLetter  = (dunning_id) => call('deleteDunning', { dunning_id })
    const setLock       = (customer_id, locked) => call('setCustomerDunningLock', { customer_id, locked })

    /** Vorschau eines Briefs, ohne ihn zu speichern — base64-PDF */
    async function previewPdf(customer_id, config_id, ids) {
        const payload = await call('previewDunningPdf', { customer_id, config_id, ids })
        return payload ? { pdf: payload.pdf, filename: payload.filename } : null
    }

    /** Versandexemplar eines gespeicherten Briefs — base64-PDF */
    async function fetchPdf(dunning_id) {
        const payload = await call('getDunningPdf', { dunning_id })
        return payload ? { pdf: payload.pdf, filename: payload.filename } : null
    }

    return {
        loading, error,
        fetchConfig, saveConfig, fetchProposal, createRun, fetchHistory,
        previewPdf, fetchPdf, resendEmail, deleteLetter, setLock
    }
}
