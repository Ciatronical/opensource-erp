// src/features/accounting/composables/useChartOfAccounts.js
import { ref } from 'vue'
import axios from 'axios'

/**
 * Composable für den Kontenrahmen (chart of accounts)
 * Konten laden, einzelnes Konto laden/speichern, Steuerschlüssel-Optionen laden
 */
export function useChartOfAccounts() {
    const loading = ref(false)
    const saving = ref(false)
    const error = ref(null)
    const accounts = ref([])
    const taxOptions = ref([])

    async function fetchAccounts(filters = {}) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getChartAccounts',
                ...filters
            })
            if (response.data.success) {
                accounts.value = response.data.payload.accounts || []
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function fetchAccount(id) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getChartAccount',
                id
            })
            if (response.data.success) {
                return response.data.payload.chart
            }
            error.value = response.data.text
            return null
        } catch (e) {
            error.value = e.message
            return null
        } finally {
            loading.value = false
        }
    }

    async function fetchTaxOptions() {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getChartAccountTaxOptions'
            })
            if (response.data.success) {
                taxOptions.value = response.data.payload.taxes || []
            }
        } catch (e) {
            error.value = e.message
        }
    }

    async function saveAccount(chart) {
        saving.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'saveChartAccount',
                ...chart
            })
            if (response.data.success) {
                return { success: true }
            }
            error.value = response.data.text
            return { success: false, text: response.data.text }
        } catch (e) {
            error.value = e.message
            return { success: false, text: e.message }
        } finally {
            saving.value = false
        }
    }

    return {
        loading,
        saving,
        error,
        accounts,
        taxOptions,
        fetchAccounts,
        fetchAccount,
        fetchTaxOptions,
        saveAccount
    }
}
