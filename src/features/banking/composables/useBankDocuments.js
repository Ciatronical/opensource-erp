// src/features/banking/composables/useBankDocuments.js
//
// Belege direkt am Bankumsatz: auflisten, hochladen, lösen, anzeigen.

import axios from 'axios'

const API_URL = '/api/banking/'

async function call(payload) {
    const response = await axios.post(API_URL, payload)
    if (response.data.success) return response.data.payload
    throw new Error(response.data.text)
}

function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => resolve(String(reader.result).split(',')[1] || '')
        reader.onerror = reject
        reader.readAsDataURL(file)
    })
}

export function useBankDocuments() {

    async function fetchDocuments(transactionId) {
        const p = await call({ action: 'getBankTransactionDocuments', bank_transaction_id: transactionId })
        return p.documents || []
    }

    /** Mehrere Dateien in einem Aufruf hochladen; liefert die neue Belegliste. */
    async function uploadDocuments(transactionId, files) {
        const documents = await Promise.all(Array.from(files).map(async f => ({
            filename:    f.name,
            mime_type:   f.type || 'application/octet-stream',
            file_base64: await fileToBase64(f)
        })))
        return call({ action: 'uploadBankTransactionDocuments', bank_transaction_id: transactionId, documents })
    }

    async function unlinkDocument(transactionId, documentId) {
        const p = await call({ action: 'unlinkBankTransactionDocument', bank_transaction_id: transactionId, document_id: documentId })
        return p.documents || []
    }

    async function fetchContent(documentId) {
        return call({ action: 'getBankDocumentContent', document_id: documentId })
    }

    return { fetchDocuments, uploadDocuments, unlinkDocument, fetchContent, fileToBase64 }
}
