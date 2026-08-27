// src/features/accounting/composables/useCustomerMatching.js
import { ref } from 'vue'
import axios from 'axios'

/**
 * Composable fuer die Kunden-Dublettenpruefung in der Buchhaltung
 *
 * Gegenstueck zu useVendorMatching. Das Anlegen und Bearbeiten von Kunden
 * bleibt bewusst im Kundenmodul — hier geht es nur ums Finden und
 * Zusammenfuehren doppelter Stammdaten.
 */
export function useCustomerMatching() {
    const loading = ref(false)
    const error = ref(null)
    const customers = ref([])
    const duplicates = ref([])

    async function fetchCustomers(query = '', limit = 50) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getAccountingCustomers',
                query,
                limit
            })
            if (response.data.success) {
                customers.value = response.data.payload.customers || []
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function mergeCustomers(keepId, mergeId, deleteMerged = false) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'mergeCustomers',
                keep_customer_id: keepId,
                merge_customer_id: mergeId,
                delete_merged: deleteMerged
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    async function findDuplicates(threshold = 0.4) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'findCustomerDuplicates',
                threshold
            })
            if (response.data.success) {
                duplicates.value = response.data.payload.duplicates || []
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    return {
        loading,
        error,
        customers,
        duplicates,
        fetchCustomers,
        mergeCustomers,
        findDuplicates
    }
}
