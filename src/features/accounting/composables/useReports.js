// src/features/accounting/composables/useReports.js

import { ref } from 'vue'
import axios from 'axios'
import { openBase64Pdf } from '@/core/utils/download.js'

const API_URL = '/api/accounting/'

/**
 * Berichte der Buchhaltung.
 *
 * Der eine Bericht, der wirklich alles zeigt, ist die Summen- und Saldenliste:
 * jedes Konto mit Anfangssaldo, Bewegungen und Endsaldo. Von dort geht es ins
 * Kontoblatt, das die einzelnen Buchungen eines Kontos auflistet.
 */
export function useReports() {
    const loading  = ref(false)
    const printing = ref(false)
    const balance  = ref(null)

    async function fetchTrialBalance({ from_date, to_date, all = false }) {
        loading.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'getTrialBalance', from_date, to_date, all
            })
            balance.value = response.data.success ? response.data.payload : null
        } finally {
            loading.value = false
        }
        return balance.value
    }

    async function printTrialBalance({ from_date, to_date, all = false }) {
        printing.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'getTrialBalancePdf', from_date, to_date, all
            })
            if (!response.data.success) throw new Error(response.data.text || response.data.payload)
            openBase64Pdf(response.data.payload.data, response.data.payload.filename)
        } finally {
            printing.value = false
        }
    }

    async function printAccountLedger({ accno, from_date, to_date }) {
        printing.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'getAccountLedgerPdf', accno, from_date, to_date
            })
            if (!response.data.success) throw new Error(response.data.text || response.data.payload)
            openBase64Pdf(response.data.payload.data, response.data.payload.filename)
        } finally {
            printing.value = false
        }
    }

    return { loading, printing, balance, fetchTrialBalance, printTrialBalance, printAccountLedger }
}
