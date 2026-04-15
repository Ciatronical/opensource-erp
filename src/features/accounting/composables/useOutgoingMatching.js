// src/features/accounting/composables/useOutgoingMatching.js
import { ref } from 'vue'
import axios from 'axios'

/**
 * Composable fuer Ausgangsrechnungen-Abgleich mit Bankbewegungen
 */
export function useOutgoingMatching() {
    const loading = ref(false)
    const error = ref(null)
    const matches = ref([])
    const matchStats = ref({})

    async function fetchMatches(bankAccountId = null) {
        loading.value = true
        error.value = null
        try {
            const params = { action: 'matchOutgoingInvoices' }
            if (bankAccountId) params.bank_account_id = bankAccountId

            const response = await axios.post('/api/accounting/', params)
            if (response.data.success) {
                matches.value = response.data.payload.matches || []
                matchStats.value = {
                    matchCount: response.data.payload.match_count,
                    totalTransactions: response.data.payload.total_transactions,
                    totalOpenInvoices: response.data.payload.total_open_invoices
                }
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function confirmMatch(transactionId, invoiceId) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'confirmOutgoingMatch',
                transaction_id: transactionId,
                invoice_id: invoiceId
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    return {
        loading,
        error,
        matches,
        matchStats,
        fetchMatches,
        confirmMatch
    }
}
