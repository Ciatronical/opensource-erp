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
    const eurOptions = ref([])
    const bwaOptions = ref([])
    const ustvaOptions = ref([])

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

    // Alle Auswahllisten des Konten-Dialogs (Steuerschlüssel, EÜR, BWA, UStVA)
    // kommen in einem Aufruf aus der Datenbank
    async function fetchOptions() {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getChartAccountOptions'
            })
            if (response.data.success) {
                const options = response.data.payload.options || {}
                taxOptions.value = options.taxes || []
                eurOptions.value = options.eur || []
                bwaOptions.value = options.bwa || []
                ustvaOptions.value = options.ustva || []
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
            // Bei Fehlern steht in text der Fehlercode und in payload die Meldung
            const message = response.data.payload || response.data.text
            error.value = message
            return { success: false, text: message }
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
        eurOptions,
        bwaOptions,
        ustvaOptions,
        fetchAccounts,
        fetchAccount,
        fetchOptions,
        saveAccount
    }
}
