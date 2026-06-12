// src/features/accounting/composables/useInvoiceUpload.js
import { ref } from 'vue'
import axios from 'axios'

/**
 * Composable fuer Rechnungs-Upload und KI-Extraktion
 */
export function useInvoiceUpload() {
    const uploading = ref(false)
    const uploadProgress = ref(0)
    const uploadResult = ref(null)
    const error = ref(null)
    const incomingInvoices = ref([])
    const loadingInvoices = ref(false)

    async function uploadInvoice(file) {
        uploading.value = true
        uploadProgress.value = 0
        uploadResult.value = null
        error.value = null

        try {
            // Datei in Base64 konvertieren
            const base64 = await _fileToBase64(file)

            uploadProgress.value = 30

            const response = await axios.post('/api/accounting/', {
                action: 'uploadInvoiceDocument',
                file_base64: base64,
                filename: file.name,
                mime_type: file.type || 'application/pdf'
            })

            uploadProgress.value = 100

            if (response.data.success) {
                uploadResult.value = response.data.payload
                return response.data.payload
            } else {
                error.value = response.data.text
                // Duplikat-Erkennung
                if (response.data.text === 'DUPLICATE_DOCUMENT') {
                    error.value = response.data.payload?.message || 'Dokument bereits hochgeladen'
                }
                return null
            }
        } catch (e) {
            error.value = e.message
            return null
        } finally {
            uploading.value = false
        }
    }

    async function getDocumentPdfUrl(documentId) {
        return `/api/accounting/?action=getDocumentPdf&document_id=${documentId}`
    }

    async function fetchIncomingInvoices() {
        loadingInvoices.value = true
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getIncomingInvoices'
            })
            if (response.data.success) {
                incomingInvoices.value = response.data.payload.invoices || []
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loadingInvoices.value = false
        }
    }

    function _fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader()
            reader.onload = () => {
                const base64 = reader.result.split(',')[1]
                resolve(base64)
            }
            reader.onerror = reject
            reader.readAsDataURL(file)
        })
    }

    function resetUpload() {
        uploading.value = false
        uploadProgress.value = 0
        uploadResult.value = null
        error.value = null
    }

    return {
        uploading,
        uploadProgress,
        uploadResult,
        error,
        incomingInvoices,
        loadingInvoices,
        uploadInvoice,
        getDocumentPdfUrl,
        fetchIncomingInvoices,
        resetUpload
    }
}
