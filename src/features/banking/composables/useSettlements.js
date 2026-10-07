// src/features/banking/composables/useSettlements.js
//
// Composable fuer Kartenabrechnungen (Flatpay/Rapyd und SumUp Sammelauszahlungen).
// Parst Excel/CSV im Browser (SheetJS) und spricht die settlements.php-API an.
//
// Zwei Dateiformate:
//   Flatpay/Rapyd: eine Zeile je Auszahlung (Datum, Gesamteinnahmen,
//                  Transaktionskosten, Nettoauszahlung).
//   SumUp:         eine Zeile je Kartenzahlung ("Zahlung") plus eine
//                  "Auszahlung"-Zeile je Kartenzahlung mit Auszahlungsdatum und
//                  Auszahlungs-ID ("SUMUP PID1283966 PAYOUT 010726"). Die
//                  Bank-Gutschrift ist die Summe aller Auszahlungsbetraege mit
//                  derselben Auszahlungs-ID; genau diese PID steht im
//                  Verwendungszweck des Bankumsatzes.

import { ref } from 'vue'
import axios from 'axios'

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
    // m/d/yy bzw. m/d/yyyy — SheetJS formatiert xls-Datumszellen ohne eigenes
    // Zellformat US-amerikanisch
    const us = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/)
    if (us) {
        const y = us[3].length === 2 ? `20${us[3]}` : us[3]
        return `${y}-${us[1].padStart(2, '0')}-${us[2].padStart(2, '0')}`
    }
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

/**
 * SumUp-Transaktionsexport zu Auszahlungszeilen buendeln.
 *
 * Nur Zeilen MIT Auszahlungs-ID zaehlen (die "Auszahlung"-Zeilen); die
 * "Zahlung"-Zeilen tragen dieselben Betraege ohne ID und wuerden sonst doppelt
 * zaehlen. Rueckerstattungen und Anpassungen ohne ID werden von SumUp bereits
 * in den Auszahlungsbetraegen verrechnet. Je Auszahlungs-ID: brutto = Summe
 * Betrag, Gebuehr = Summe Gebuehrenbetrag, netto = Summe Auszahlungsbetrag,
 * Zeitraum = aeltester bis juengster Zeitstempel der enthaltenen Zahlungen.
 *
 * @param {Array<Array>} matrix Tabelle inkl. Header-Zeile (sheet_to_json header:1)
 * @return {Array|null} Auszahlungszeilen oder null, wenn es kein SumUp-Export ist
 */
export function parseSumupMatrix(matrix) {
    let headerIdx = -1
    for (let i = 0; i < Math.min(matrix.length, 20); i++) {
        const norm = matrix[i].map(normHeader)
        if (norm.includes('auszahlungsid') && norm.includes('auszahlungsbetrag')) {
            headerIdx = i
            break
        }
    }
    if (headerIdx === -1) return null

    const header = matrix[headerIdx].map(normHeader)
    const cId    = header.indexOf('auszahlungsid')
    const cDate  = header.indexOf('auszahlungsdatum')
    const cNet   = header.indexOf('auszahlungsbetrag')
    const cGross = header.indexOf('betrag')
    const cFee   = header.findIndex(h => h.startsWith('geb') && h.endsWith('betrag'))
    const cTime  = header.indexOf('zeitstempel')
    const cCode  = header.indexOf('transaktionscode')
    const cDescr = header.indexOf('beschreibung')
    if (cId === -1 || cDate === -1 || cNet === -1 || cGross === -1 || cFee === -1) {
        throw new Error('PARSE_NO_COLUMNS')
    }

    // Beschreibung und Zeitstempel stehen an der "Zahlung"-Zeile, die Auszahlungs-
    // zeile desselben Codes traegt sie nicht — deshalb je Transaktionscode merken.
    const descrByCode = new Map()
    const timeByCode = new Map()
    if (cCode !== -1) {
        for (let i = headerIdx + 1; i < matrix.length; i++) {
            const r = matrix[i]
            const code = String(r[cCode] || '').trim()
            if (!code) continue
            if (cDescr !== -1 && r[cDescr] && !descrByCode.has(code)) descrByCode.set(code, String(r[cDescr]).trim())
            if (cTime !== -1 && r[cTime] && !timeByCode.has(code)) timeByCode.set(code, String(r[cTime]).trim())
        }
    }

    const byPayout = new Map()
    for (let i = headerIdx + 1; i < matrix.length; i++) {
        const r = matrix[i]
        const rawId = String(r[cId] || '').trim()
        if (!rawId) continue
        // "SUMUP PID1283966 PAYOUT 010726" → "PID1283966" (steht so im Bank-Verwendungszweck)
        const reference = (rawId.match(/PID\d+/i) || [rawId])[0].toUpperCase()
        const payout = toIsoDate(r[cDate])
        if (!payout) continue

        const day = cTime !== -1 ? toIsoDate(r[cTime]) : null
        let line = byPayout.get(reference)
        if (!line) {
            line = { reference, payout_date: payout, period_from: day || payout, period_to: day || payout, gross: 0, fee: 0, net: 0, count: 0, transactions: [] }
            byPayout.set(reference, line)
        }
        const gross = parseGermanAmount(r[cGross])
        const fee   = Math.abs(parseGermanAmount(r[cFee]))
        const net   = parseGermanAmount(r[cNet])
        line.gross += gross
        line.fee   += fee
        line.net   += net
        line.count += 1
        const code = cCode !== -1 ? String(r[cCode] || '').trim() : ''
        // Einzelzahlung fuer die Rechnungszuordnung je Zahlung (wie die API)
        line.transactions.push({
            code,
            timestamp: timeByCode.get(code) || (cTime !== -1 ? String(r[cTime] || '') : null) || null,
            gross: Math.round(gross * 100) / 100,
            fee: Math.round(fee * 100) / 100,
            net: Math.round(net * 100) / 100,
            description: descrByCode.get(code) || '',
        })
        if (day && day < line.period_from) line.period_from = day
        if (day && day > line.period_to)   line.period_to = day
    }

    const rows = []
    for (const l of byPayout.values()) {
        l.gross = Math.round(l.gross * 100) / 100
        l.fee   = Math.round(l.fee * 100) / 100
        l.net   = Math.round(l.net * 100) / 100
        if (l.gross === 0 && l.net === 0) continue
        rows.push(l)
    }
    rows.sort((a, b) => a.payout_date.localeCompare(b.payout_date))
    if (rows.length === 0) throw new Error('PARSE_NO_ROWS')
    return rows
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

        // xlsx ist rund 0,5 MB und wird nur beim Einlesen einer Abrechnung
        // gebraucht — deshalb erst hier laden statt beim Seitenaufruf.
        const XLSX = await import('xlsx')

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

        // SumUp-Transaktionsexport? Dann je Auszahlungs-ID buendeln.
        const sumup = parseSumupMatrix(matrix)
        if (sumup) return sumup

        // Flatpay/Rapyd: Header-Zeile finden (enthaelt "Datum" und "Nettoauszahlung").
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

    // Bereits hochgeladene Abrechnungen (standardmaessig nur mit offenen Zeilen)
    async function fetchSettlements({ vendorId = null, onlyOpen = true } = {}) {
        const res = await axios.post(API_URL, {
            action: 'getCardSettlements',
            vendor_id: vendorId,
            only_open: onlyOpen ? '1' : '0',
        })
        if (!res.data.success) throw new Error(res.data.payload || res.data.text)
        return res.data.payload.settlements || []
    }

    // Falsche Datei: Abrechnung samt offenen Zeilen loeschen (gebuchte sperren)
    async function deleteSettlement(settlementId) {
        const res = await axios.post(API_URL, { action: 'deleteCardSettlement', settlement_id: settlementId })
        if (!res.data.success) throw new Error(res.data.payload || res.data.text)
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

    // SumUp-Auszahlungen per API rund um den Bankumsatz holen und speichern;
    // liefert den passenden Treffer (wie suggestMatch) gleich mit
    async function syncSumupPayouts(bankTransactionId) {
        const res = await axios.post(API_URL, { action: 'syncSumupPayouts', bank_transaction_id: bankTransactionId })
        if (!res.data.success) throw new Error(res.data.payload || res.data.text)
        return res.data.payload
    }

    // Buchungsvorschau: dieselben Parameter wie bookLine, veraendert nichts
    async function previewBooking({ bankTransactionId, settlementLineId, feeChartId, clearingChartId, arIds, arDiffs }) {
        const res = await axios.post(API_URL, {
            action: 'previewCardSettlementBooking',
            bank_transaction_id: bankTransactionId,
            settlement_line_id: settlementLineId,
            fee_chart_id: feeChartId,
            clearing_chart_id: clearingChartId,
            ar_ids: arIds || [],
            ar_diffs: arDiffs || {},
        })
        if (!res.data.success) throw new Error(res.data.payload || res.data.text)
        return res.data.payload
    }

    async function bookLine({ bankTransactionId, settlementLineId, feeChartId, clearingChartId, arIds, arDiffs }) {
        loading.value = true
        try {
            const res = await axios.post(API_URL, {
                action: 'bookCardSettlementLine',
                bank_transaction_id: bankTransactionId,
                settlement_line_id: settlementLineId,
                fee_chart_id: feeChartId,
                clearing_chart_id: clearingChartId,
                ar_ids: arIds || [],
            ar_diffs: arDiffs || {},
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
        fetchSettlements,
        deleteSettlement,
        suggestMatch,
        syncSumupPayouts,
        findInvoices,
        previewBooking,
        bookLine,
    }
}
