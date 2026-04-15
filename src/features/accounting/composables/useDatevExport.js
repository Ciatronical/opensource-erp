// src/features/accounting/composables/useDatevExport.js
import { ref } from 'vue'
import axios from 'axios'

/**
 * Composable fuer DATEV-Export
 */
export function useDatevExport() {
    const loading = ref(false)
    const error = ref(null)
    const preview = ref(null)
    const datevConfig = ref({})

    async function fetchPreview(fromDate, toDate) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'exportDatev',
                from_date: fromDate,
                to_date: toDate,
                format: 'preview'
            })
            if (response.data.success) {
                preview.value = response.data.payload
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function exportCsv(fromDate, toDate) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'exportDatev',
                from_date: fromDate,
                to_date: toDate,
                format: 'datev_csv'
            }, {
                responseType: 'blob'
            })

            // Download ausloesen
            const url = window.URL.createObjectURL(new Blob([response.data]))
            const link = document.createElement('a')
            link.href = url
            link.setAttribute('download', `DATEV_${fromDate}_${toDate}.csv`)
            document.body.appendChild(link)
            link.click()
            link.remove()
            window.URL.revokeObjectURL(url)

            return { success: true }
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    async function fetchConfig() {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getDatevConfig'
            })
            if (response.data.success) {
                datevConfig.value = response.data.payload
            }
        } catch (e) {
            error.value = e.message
        }
    }

    async function saveConfig(config) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'saveDatevConfig',
                ...config
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    return {
        loading,
        error,
        preview,
        datevConfig,
        fetchPreview,
        exportCsv,
        fetchConfig,
        saveConfig
    }
}
