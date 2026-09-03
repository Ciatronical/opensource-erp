// src/features/accounting/composables/useDocumentCheck.js

import { ref } from 'vue'
import axios from 'axios'

const API_URL = '/api/accounting/'

/**
 * Belegprüfung: liest jede abgelegte Datei neu ein und vergleicht ihren
 * SHA-256-Hash mit dem, der bei der Ablage gespeichert wurde.
 */
export function useDocumentCheck() {
    const loading = ref(false)
    const report  = ref(null)
    const logs    = ref({})

    async function runCheck({ limit = 500, nur_fehler = false } = {}) {
        loading.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'checkAccountingDocuments', limit, nur_fehler
            })
            report.value = response.data.success ? response.data.payload : null
        } finally {
            loading.value = false
        }
        return report.value
    }

    async function fetchLog(documentId) {
        const response = await axios.post(API_URL, {
            action: 'getAccountingDocumentLog', document_id: documentId
        })
        logs.value = { ...logs.value, [documentId]: response.data.payload?.eintraege || [] }
        return logs.value[documentId]
    }

    return { loading, report, logs, runCheck, fetchLog }
}
