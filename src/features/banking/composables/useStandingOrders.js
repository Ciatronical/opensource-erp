// src/features/banking/composables/useStandingOrders.js

import { ref } from 'vue'
import axios from 'axios'

const API_URL = '/api/banking/'

export function useStandingOrders() {
    const loading        = ref(false)
    const standingOrders = ref([])
    const mandates       = ref([])

    async function fetchStandingOrders(bankAccountId = null) {
        loading.value = true
        try {
            const r = await axios.post(API_URL, {
                action: 'getStandingOrders',
                bank_account_id: bankAccountId
            })
            if (r.data.success) standingOrders.value = r.data.payload.orders || []
        } finally { loading.value = false }
    }

    async function createStandingOrder(data) {
        const r = await axios.post(API_URL, { action: 'createStandingOrder', ...data })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        return r.data.payload
    }

    async function updateStandingOrder(data) {
        const r = await axios.post(API_URL, { action: 'updateStandingOrder', ...data })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    async function setStandingOrderStatus(id, status) {
        const r = await axios.post(API_URL, { action: 'setStandingOrderStatus', id, status })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    async function deleteStandingOrder(id) {
        const r = await axios.post(API_URL, { action: 'deleteStandingOrder', id })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    async function executeStandingOrder(id) {
        loading.value = true
        try {
            const r = await axios.post(API_URL, { action: 'executeStandingOrder', id })
            if (!r.data.success) throw new Error(r.data.payload || r.data.text)
            return r.data.payload // { transfer_id }
        } finally { loading.value = false }
    }

    // ── SEPA-Mandate ───────────────────────────────────────

    async function fetchMandates(bankAccountId = null) {
        loading.value = true
        try {
            const r = await axios.post(API_URL, {
                action: 'getSepaMandates',
                bank_account_id: bankAccountId
            })
            if (r.data.success) mandates.value = r.data.payload.mandates || []
        } finally { loading.value = false }
    }

    async function createMandate(data) {
        const r = await axios.post(API_URL, { action: 'createSepaMandate', ...data })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        return r.data.payload
    }

    async function updateMandate(data) {
        const r = await axios.post(API_URL, { action: 'updateSepaMandate', ...data })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    async function revokeMandate(id, revokedDate) {
        const r = await axios.post(API_URL, { action: 'revokeSepaMandate', id, revoked_date: revokedDate })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    async function deleteMandate(id) {
        const r = await axios.post(API_URL, { action: 'deleteSepaMandate', id })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    return {
        loading, standingOrders, mandates,
        fetchStandingOrders, createStandingOrder, updateStandingOrder,
        setStandingOrderStatus, deleteStandingOrder, executeStandingOrder,
        fetchMandates, createMandate, updateMandate, revokeMandate, deleteMandate
    }
}
