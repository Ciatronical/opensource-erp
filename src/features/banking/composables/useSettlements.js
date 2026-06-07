// src/features/banking/composables/useSettlements.js
//
// Composable fuer Kartenabrechnungen (Flatpay/Rapyd Sammelauszahlungen).
// Parst Excel/CSV im Browser (SheetJS) und spricht die settlements.php-API an.

import { ref } from 'vue'
import axios from 'axios'
import * as XLSX from 'xlsx'

const API_URL = '/api/banking/'
const ACC_URL = '/api/accounting/'

/**
 * Geldbetrag in eine Zahl wandeln — robust fuer deutsches UND englisches Format.
 *
 * SheetJS liefert je nach Zellformat "1.498,26 EUR" (de) oder "1498.26" (en),
 * oder direkt eine Zahl. Heuristik: der ZULETZT vorkommende Trenner (',' oder
 * '.') ist der Dezimaltrenner, der andere ist Tausendertrenner.
 */
function parseGermanAmount(value) {
    if (typeof value === 'number') return value
    if (value == null) return 0
    let s = String(value).replace(/[^0-9.,-]/g, '')
    if (s === '' || s === '-') return 0

    const lastComma = s.lastIndexOf(',')
    const lastDot = s.lastIndexOf('.')
    if (lastComma > lastDot) {
        // ',' ist Dezimaltrenner (deutsch): '.' = Tausender entfernen
        s = s.split('.').join('').replace(',', '.')
    } else if (lastDot > lastComma) {
        // '.' ist Dezimaltrenner (englisch): ',' = Tausender entfernen
        s = s.split(',').join('')
    }
    const n = parseFloat(s)
    return isNaN(n) ? 0 : n
}

/**
 * Beliebigen Datumswert in ISO (YYYY-MM-DD) wandeln; null wenn nicht moeglich.
 */
function toIsoDate(value) {
    if (!value) return null
    const s = String(value)
    const m = s.match(/(\d{4})-(\d{2})-(\d{2})/)
    if (m) return `${m[1]}-${m[2]}-${m[3]}`
    // dd.mm.yyyy
    const d = s.match(/(\d{1,2})\.(\d{1,2})\.(\d{4})/)
    if (d) return `${d[3]}-${d[2].padStart(2, '0')}-${d[1].padStart(2, '0')}`
    const parsed = new Date(s)
    if (!isNaN(parsed.getTime())) return parsed.toISOString().slice(0, 10)
    return null
}

/**
 * Header-Text normalisieren fuer Spaltenzuordnung (Umlaute/Leerzeichen weg).
 */
function normHeader(h) {
    return String(h || '').toLowerCase().replace(/[^a-z0-9]/g, '')
}

export function useSettlements() {

    const loading = ref(false)

    // ── Datei parsen (Excel/CSV/PDF) → normalisierte Auszahlungszeilen ────────
    async function parseFile(file) {
        const ext = (file.name.split('.').pop() || '').toLowerCase()

        // PDF wird serverseitig geparst (smalot/pdfparser).
        if (ext === 'pdf') {
            const fileB64 = await fileToBase64(file)
            const res = await axios.post(API_URL, { action: 'parseSettlementPdf', file_base64: fileB64 })
            if (!res.data.success) throw new Error(res.data.payload || res.data.text)
            const rows = res.data.payload.rows || []
            if (rows.length === 0) throw new Error('PARSE_NO_ROWS')
            return rows
        }

        let workbook
        if (ext === 'csv') {
            const text = await file.text()
            workbook = XLSX.read(text, { type: 'string', raw: false })
        } else {
            const buf = await file.arrayBuffer()
            workbook = XLSX.read(buf, { type: 'array', raw: false })
        }
        const sheet = workbook.Sheets[workbook.SheetNames[0]]
        const matrix = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false, defval: '' })

        // Header-Zeile finden (enthaelt "Datum" und "Nettoauszahlung").
        let headerIdx = -1
        for (let i = 0; i < matrix.length; i++) {
            const norm = matrix[i].map(normHeader)
            if (norm.some(h => h.includes('datum')) && norm.some(h => h.includes('nettoauszahlung'))) {
                headerIdx = i
                break
            }
        }
        if (headerIdx === -1) {
            throw new Error('PARSE_NO_HEADER')
        }

        const header = matrix[headerIdx].map(normHeader)
        const col = (needle) => header.findIndex(h => h.includes(needle))
        const cDate  = col('datum')
        const cPer   = header.findIndex(h => h.includes('zeitraum') || h.includes('deckt'))
        const cGross = header.findIndex(h => h.includes('gesamteinnahmen'))
        const cFee   = header.findIndex(h => h.includes('transaktionskosten'))
        const cNet   = header.findIndex(h => h.includes('nettoauszahlung'))

        if (cDate === -1 || cGross === -1 || cFee === -1 || cNet === -1) {
            throw new Error('PARSE_NO_COLUMNS')
        }

        const rows = []
        for (let i = headerIdx + 1; i < matrix.length; i++) {
            const r = matrix[i]
            const payout = toIsoDate(r[cDate])
            if (!payout) continue // Total-/Leerzeilen ueberspringen

            let periodFrom = payout, periodTo = payout
            if (cPer !== -1) {
                const dates = String(r[cPer]).match(/\d{4}-\d{2}-\d{2}|\d{1,2}\.\d{1,2}\.\d{4}/g) || []
                if (dates[0]) periodFrom = toIsoDate(dates[0])
                periodTo = dates[1] ? toIsoDate(dates[1]) : periodFrom
            }

            const gross = parseGermanAmount(r[cGross])
            const fee   = Math.abs(parseGermanAmount(r[cFee]))
            const net   = parseGermanAmount(r[cNet])
            if (gross === 0 && net === 0) continue

            rows.push({ payout_date: payout, period_from: periodFrom, period_to: periodTo, gross, fee, net })
        }
        if (rows.length === 0) throw new Error('PARSE_NO_ROWS')
        return rows
    }

    function fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader()
            reader.onload = () => resolve(String(reader.result).split(',')[1] || '')
            reader.onerror = reject
            reader.readAsDataURL(file)
        })
    }

    // ── API ───────────────────────────────────────────────────────────────────
    async function fetchAccounts() {
        const res = await axios.post(API_URL, { action: 'getSettlementAccounts' })
        if (!res.data.success) throw new Error(res.data.payload || res.data.text)
        return res.data.payload.accounts || []
    }

    async function fetchVendors(query = '') {
        const res = await axios.post(ACC_URL, { action: 'getAccountingVendors', query, limit: 50 })
        if (!res.data.success) throw new Error(res.data.payload || res.data.text)
        return res.data.payload.vendors || res.data.payload || []
    }

    async function uploadSettlement({ provider, vendorId, file, rows }) {
        loading.value = true
        try {
            const fileB64 = file ? await fileToBase64(file) : ''
            const res = await axios.post(API_URL, {
                action: 'uploadCardSettlement',
                provider,
                vendor_id: vendorId,
                filename: file ? file.name : '',
                mime_type: file ? (file.type || 'application/octet-stream') : '',
                file_base64: fileB64,
                lines: rows,
            })
            if (!res.data.success) throw new Error(res.data.payload || res.data.text)
            return res.data.payload
        } finally {
            loading.value = false
        }
    }

    async function suggestMatch(bankTransactionId) {
        const res = await axios.post(API_URL, {
            action: 'suggestSettlementMatch',
            bank_transaction_id: bankTransactionId,
        })
        if (!res.data.success) throw new Error(res.data.payload || res.data.text)
        return res.data.payload.match
    }

    async function findInvoices(settlementLineId) {
        const res = await axios.post(API_URL, {
            action: 'findInvoicesForSettlementLine',
            settlement_line_id: settlementLineId,
        })
        if (!res.data.success) throw new Error(res.data.payload || res.data.text)
        return res.data.payload
    }

    async function bookLine({ bankTransactionId, settlementLineId, feeChartId, clearingChartId, arIds }) {
        loading.value = true
        try {
            const res = await axios.post(API_URL, {
                action: 'bookCardSettlementLine',
                bank_transaction_id: bankTransactionId,
                settlement_line_id: settlementLineId,
                fee_chart_id: feeChartId,
                clearing_chart_id: clearingChartId,
                ar_ids: arIds || [],
            })
            if (!res.data.success) throw new Error(res.data.payload || res.data.text)
            return res.data.payload
        } finally {
            loading.value = false
        }
    }

    return {
        loading,
        parseFile,
        fetchAccounts,
        fetchVendors,
        uploadSettlement,
        suggestMatch,
        findInvoices,
        bookLine,
    }
}
