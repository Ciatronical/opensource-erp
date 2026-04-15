// src/features/accounting/composables/useVendorMatching.js
import { ref } from 'vue'
import axios from 'axios'

/**
 * Composable fuer Lieferantenverwaltung mit Dublettenprüfung
 */
export function useVendorMatching() {
    const loading = ref(false)
    const error = ref(null)
    const vendors = ref([])
    const duplicates = ref([])

    async function fetchVendors(query = '', limit = 50) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getAccountingVendors',
                query,
                limit
            })
            if (response.data.success) {
                vendors.value = response.data.payload.vendors || []
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function createVendor(vendorData) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'createAccountingVendor',
                ...vendorData
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    async function updateVendor(vendorId, vendorData) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'updateAccountingVendor',
                vendor_id: vendorId,
                ...vendorData
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    async function mergeVendors(keepId, mergeId) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'mergeVendors',
                keep_vendor_id: keepId,
                merge_vendor_id: mergeId
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
                action: 'findVendorDuplicates',
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
        vendors,
        duplicates,
        fetchVendors,
        createVendor,
        updateVendor,
        mergeVendors,
        findDuplicates
    }
}
