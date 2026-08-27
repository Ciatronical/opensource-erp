// src/features/accounting/composables/useAccounting.js
import { ref } from 'vue'
import axios from 'axios'

/**
 * Composable fuer Buchhaltungs-Operationen
 * Buchungen laden, freigeben, bearbeiten
 */
export function useAccounting() {
    const loading = ref(false)
    const error = ref(null)
    const bookings = ref([])
    const stats = ref({})
    const total = ref(0)
    const dashboard = ref(null)
    const journal = ref([])
    const journalStats = ref({})

    async function fetchDashboard() {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getAccountingDashboard'
            })
            if (response.data.success) {
                dashboard.value = response.data.payload
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function fetchBookings(filters = {}) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getAccountingBookings',
                ...filters
            })
            if (response.data.success) {
                bookings.value = response.data.payload.bookings || []
                stats.value = response.data.payload.stats || {}
                total.value = response.data.payload.total || 0
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function fetchBooking(bookingId) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getAccountingBooking',
                booking_id: bookingId
            })
            if (response.data.success) {
                return response.data.payload.booking
            } else {
                error.value = response.data.text
                return null
            }
        } catch (e) {
            error.value = e.message
            return null
        } finally {
            loading.value = false
        }
    }

    async function approveBooking(bookingId, extra = {}) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'approveBooking',
                booking_id: bookingId,
                ...extra   // optional: vendor_id, debit_account (Freigabe-Override)
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    // Manuelle Eingangsrechnung buchen (ohne Scan/KI) → echte ap + acc_trans
    async function postIncomingInvoice(payload) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'postIncomingInvoice',
                ...payload
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    async function searchVendors(query) {
        try {
            const response = await axios.post('/api/accounting/', { action: 'searchVendors', query })
            return response.data?.payload?.vendors ?? []
        } catch (e) {
            return []
        }
    }

    async function approveBookingsBatch(bookingIds) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'approveBookingsBatch',
                booking_ids: bookingIds
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    async function rejectBooking(bookingId, reason = '') {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'rejectBooking',
                booking_id: bookingId,
                reason
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    async function updateBooking(bookingId, fields) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'updateBooking',
                booking_id: bookingId,
                ...fields
            })
            return response.data
        } catch (e) {
            return { success: false, text: e.message }
        }
    }

    // Echtes kivitendo-Buchungsjournal (ar/ap/gl), nicht die KI-Vorerfassung
    async function fetchJournal(filters = {}) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getLedgerJournal',
                ...filters
            })
            if (response.data.success) {
                journal.value = response.data.payload.journal || []
                journalStats.value = response.data.payload.stats || {}
                total.value = response.data.payload.total || 0
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function fetchOpenItems(type = 'receivables') {
        const action = type === 'payables' ? 'getOpenPayables' : 'getOpenReceivables'
        try {
            const response = await axios.post('/api/accounting/', { action })
            if (response.data.success) return response.data.payload
            return { items: [], summary: {} }
        } catch (e) {
            return { items: [], summary: {} }
        }
    }

    async function fetchChart(months = 12) {
        try {
            const response = await axios.post('/api/accounting/', { action: 'getAccountingChart', months })
            if (response.data.success) return response.data.payload.series || []
            return []
        } catch (e) {
            return []
        }
    }

    async function fetchJournalEntry(id, src) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getLedgerEntry', id, src
            })
            if (response.data.success) return response.data.payload
            return null
        } catch (e) {
            return null
        }
    }

    // Kontoblatt / Sachkontoauszug: alle Buchungen eines Kontos mit laufendem Saldo
    async function fetchAccountLedger(params) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'getAccountLedger',
                ...params
            })
            if (response.data.success) return response.data.payload
            return null
        } catch (e) {
            return null
        }
    }

    async function searchAccounts(query, link = null) {
        try {
            const response = await axios.post('/api/accounting/', {
                action: 'searchAccounts',
                query,
                link
            })
            if (response.data.success) {
                return response.data.payload.accounts || []
            }
            return []
        } catch {
            return []
        }
    }

    return {
        loading,
        error,
        bookings,
        stats,
        total,
        dashboard,
        journal,
        journalStats,
        fetchDashboard,
        fetchChart,
        fetchOpenItems,
        fetchJournal,
        fetchJournalEntry,
        fetchBookings,
        fetchBooking,
        approveBooking,
        approveBookingsBatch,
        rejectBooking,
        updateBooking,
        searchAccounts,
        searchVendors,
        postIncomingInvoice,
        fetchAccountLedger
    }
}
