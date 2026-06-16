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
        fetchDashboard,
        fetchBookings,
        fetchBooking,
        approveBooking,
        approveBookingsBatch,
        rejectBooking,
        updateBooking,
        searchAccounts,
        searchVendors
    }
}
