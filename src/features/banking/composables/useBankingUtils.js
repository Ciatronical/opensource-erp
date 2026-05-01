// src/features/banking/composables/useBankingUtils.js
// Gemeinsame Hilfsfunktionen: BIC-Lookup, Datei-Download, Alerts, Liquidität, Lastschrift

import { ref } from 'vue'
import axios from 'axios'

const API_URL = '/api/banking/'

// ── BIC-Lookup ────────────────────────────────────────────

export async function lookupBicFromIban(iban) {
    if (!iban || iban.replace(/\s/g, '').length < 15) return null
    try {
        const r = await axios.post(API_URL, { action: 'lookupBicFromIban', iban })
        if (r.data.success && r.data.payload.bic) return r.data.payload
        return null
    } catch { return null }
}

// ── Datei-Download (base64 → Blob → <a>) ─────────────────

export function downloadBase64File(base64, filename, mime) {
    const bytes  = Uint8Array.from(atob(base64), c => c.charCodeAt(0))
    const blob   = new Blob([bytes], { type: mime })
    const url    = URL.createObjectURL(blob)
    const link   = document.createElement('a')
    link.href    = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
}

// ── Composable ────────────────────────────────────────────

export function useBankingUtils() {
    const alerts            = ref([])
    const forecast          = ref([])
    const forecastLoading   = ref(false)
    const aiForecast        = ref([])
    const aiForecastLoading = ref(false)
    const directDebits      = ref([])
    const ddLoading         = ref(false)

    async function fetchAlerts(bankAccountId = null) {
        try {
            const r = await axios.post(API_URL, { action: 'getBankingAlerts', bank_account_id: bankAccountId })
            if (r.data.success) alerts.value = r.data.payload.alerts || []
        } catch { alerts.value = [] }
    }

    async function fetchForecast(bankAccountId, days = 30) {
        forecastLoading.value = true
        try {
            const r = await axios.post(API_URL, { action: 'getLiquidityForecast', bank_account_id: bankAccountId, days })
            if (r.data.success) {
                forecast.value = r.data.payload.forecast || []
                const d = r.data.payload.debug
                if (d) console.log('[Liquidität Debug]', d)
            }
            return r.data.payload
        } finally { forecastLoading.value = false }
    }

    async function fetchAIForecast(bankAccountId, days = 30) {
        aiForecastLoading.value = true
        try {
            const r = await axios.post(API_URL, { action: 'getAILiquidityForecast', bank_account_id: bankAccountId, days })
            if (r.data.success) aiForecast.value = r.data.payload.forecast || []
            else throw new Error(r.data.payload || r.data.text || 'KI-Prognose fehlgeschlagen')
            return r.data.payload
        } finally { aiForecastLoading.value = false }
    }

    async function exportCsv(bankAccountId, fromDate = null, toDate = null) {
        const r = await axios.post(API_URL, { action: 'exportTransactionsCsv', bank_account_id: bankAccountId, from_date: fromDate, to_date: toDate })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        const { data, filename, mime } = r.data.payload
        downloadBase64File(data, filename, mime)
    }

    async function exportPdf(bankAccountId, fromDate = null, toDate = null) {
        const r = await axios.post(API_URL, { action: 'exportTransactionsPdf', bank_account_id: bankAccountId, from_date: fromDate, to_date: toDate })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        const { data, filename, mime } = r.data.payload
        downloadBase64File(data, filename, mime)
    }

    async function printTransferConfirmation(transferId) {
        const r = await axios.post(API_URL, { action: 'getTransferConfirmationPdf', transfer_id: transferId })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        const { data, filename, mime } = r.data.payload
        downloadBase64File(data, filename, mime)
    }

    // ── SEPA-Lastschrift ──────────────────────────────────

    async function fetchDirectDebits(bankAccountId = null) {
        ddLoading.value = true
        try {
            const r = await axios.post(API_URL, { action: 'getDirectDebitOrders', bank_account_id: bankAccountId })
            if (r.data.success) directDebits.value = r.data.payload.orders || []
        } finally { ddLoading.value = false }
    }

    async function createDirectDebit(data) {
        const r = await axios.post(API_URL, { action: 'createDirectDebitOrder', ...data })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        return r.data.payload
    }

    async function deleteDirectDebit(id) {
        const r = await axios.post(API_URL, { action: 'deleteDirectDebitOrder', id })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    async function submitDirectDebit(orderId, pin, creditorId) {
        const r = await axios.post(API_URL, { action: 'fintsSubmitDirectDebit', direct_debit_order_id: orderId, pin, creditor_id: creditorId })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        return r.data
    }

    async function submitDirectDebitTan(orderId, tan, pin) {
        const r = await axios.post(API_URL, { action: 'fintsSubmitDirectDebitTan', direct_debit_order_id: orderId, tan, pin })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        return r.data
    }

    return {
        alerts, forecast, forecastLoading, aiForecast, aiForecastLoading, directDebits, ddLoading,
        fetchAlerts, fetchForecast, fetchAIForecast, exportCsv, exportPdf, printTransferConfirmation,
        fetchDirectDebits, createDirectDebit, deleteDirectDebit, submitDirectDebit, submitDirectDebitTan
    }
}
