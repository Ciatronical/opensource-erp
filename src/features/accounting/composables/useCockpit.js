// src/features/accounting/composables/useCockpit.js

import { ref } from 'vue'
import axios from 'axios'

/**
 * Datenzugriff fuer Cockpit und Durchlauf.
 *
 * Das Cockpit holt seine Kennzahlen in einem Aufruf (getAccountingCockpit);
 * die Umsatzsteuer kommt weiterhin aus getUstvaYear, wo die massgebliche
 * Kennzahlen-Logik liegt.
 */
export function useCockpit() {
    const loading = ref(false)
    const error = ref(null)
    const cockpit = ref(null)
    const ustvaYear = ref(null)

    async function fetchCockpit() {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', { action: 'getAccountingCockpit' })
            if (response.data.success) {
                cockpit.value = response.data.payload.results
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
        return cockpit.value
    }

    async function fetchUstvaYear(year) {
        try {
            const response = await axios.post('/api/accounting/', { action: 'getUstvaYear', year })
            if (response.data.success) {
                ustvaYear.value = response.data.payload.results
                return ustvaYear.value
            }
        } catch {
            // Die Umsatzsteuer ist nur ein Teil des Cockpits — faellt sie aus,
            // bleibt der Rest der Seite benutzbar.
        }
        return null
    }

    async function fetchStack(kind, limit = 200) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getAccountingStack', kind, limit
            })
            if (response.data.success) return response.data.payload.results
            error.value = response.data.text
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
        return { kind, items: [], total: 0 }
    }

    /**
     * Unstimmigkeiten zwischen Rechnung und Kontrollkonto.
     *
     * Eigener Aufruf, nicht Teil von fetchCockpit: das Cockpit zeigt nur die
     * Anzahl, die Liste holt sich erst, wer sie aufklappt.
     */
    async function fetchConsistency(limit = 200) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getLedgerConsistency', limit
            })
            if (response.data.success) return response.data.payload.results
            error.value = response.data.text
        } catch (e) {
            error.value = e.message
        }
        return { count: 0, sum_difference: 0, items: [] }
    }

    async function searchTargets(query) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'searchAccountingTargets', query
            })
            if (response.data.success) return response.data.payload.results.items || []
        } catch {
            return []
        }
        return []
    }

    async function matchBankTransaction(bankTransactionId, targetType, targetId) {
        try {
            const response = await axios.post('/api/banking/', {
                action: 'matchTransaction',
                bank_transaction_id: bankTransactionId,
                target_type: targetType,
                target_id: targetId
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    async function unmatchBankTransaction(bankTransactionId) {
        try {
            const response = await axios.post('/api/banking/', {
                action: 'unmatchTransaction',
                bank_transaction_id: bankTransactionId
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    async function searchOpenInvoices(search, type = 'all') {
        try {
            const response = await axios.post('/api/banking/', {
                action: 'getOpenInvoicesForMatching', type, search
            })
            if (response.data.success) return response.data.payload.invoices || []
        } catch {
            return []
        }
        return []
    }

    return {
        loading, error, cockpit, ustvaYear,
        fetchCockpit, fetchUstvaYear, fetchStack, fetchConsistency, searchTargets,
        matchBankTransaction, unmatchBankTransaction, searchOpenInvoices
    }
}
