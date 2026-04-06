// src/features/banking/composables/useTransfers.js

import { ref, reactive } from 'vue'
import axios from 'axios'

const API_URL = '/api/banking/'

/**
 * Composable fuer Ueberweisungs-Operationen
 */
export function useTransfers() {

    const loading = ref(false)
    const transferOrders = ref([])
    const tanRequired = ref(false)
    const tanChallenge = reactive({
        challenge: '',
        tanMedium: '',
        challengeHhduc: null
    })

    async function fetchTransferOrders(bankAccountId = null, status = 'all') {
        loading.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'getTransferOrders',
                bank_account_id: bankAccountId,
                status: status
            })
            if (response.data.success) {
                transferOrders.value = response.data.payload.orders || []
            }
        } finally {
            loading.value = false
        }
    }

    async function createTransfer(transferData) {
        const response = await axios.post(API_URL, {
            action: 'createTransferOrder',
            ...transferData
        })
        if (response.data.success) {
            return response.data.payload
        }
        throw new Error(response.data.payload || response.data.text)
    }

    async function updateTransfer(transferData) {
        const response = await axios.post(API_URL, {
            action: 'updateTransferOrder',
            ...transferData
        })
        if (!response.data.success) {
            throw new Error(response.data.payload || response.data.text)
        }
    }

    async function deleteTransfer(id) {
        const response = await axios.post(API_URL, {
            action: 'deleteTransferOrder',
            id: id
        })
        if (!response.data.success) {
            throw new Error(response.data.payload || response.data.text)
        }
    }

    async function createTransferFromInvoice(apId, bankAccountId) {
        const response = await axios.post(API_URL, {
            action: 'createTransferFromInvoice',
            ap_id: apId,
            bank_account_id: bankAccountId
        })
        if (response.data.success) {
            return response.data.payload
        }
        throw new Error(response.data.payload || response.data.text)
    }

    async function submitTransfer(transferOrderId, pin) {
        loading.value = true
        tanRequired.value = false
        try {
            const response = await axios.post(API_URL, {
                action: 'fintsSubmitTransfer',
                transfer_order_id: transferOrderId,
                pin: pin
            })

            if (response.data.success) {
                if (response.data.text === 'TAN_REQUIRED') {
                    tanRequired.value = true
                    const payload = response.data.payload
                    tanChallenge.challenge = payload.challenge
                    tanChallenge.tanMedium = payload.tan_medium
                    tanChallenge.challengeHhduc = payload.challenge_hhduc
                    return { tanRequired: true }
                }
                return { tanRequired: false }
            }
            throw new Error(response.data.payload || response.data.text)
        } finally {
            loading.value = false
        }
    }

    async function submitTransferTan(transferOrderId, tan, pin) {
        loading.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'fintsSubmitTransferTan',
                transfer_order_id: transferOrderId,
                tan: tan,
                pin: pin
            })
            tanRequired.value = false
            if (!response.data.success) {
                throw new Error(response.data.payload || response.data.text)
            }
        } finally {
            loading.value = false
        }
    }

    return {
        loading,
        transferOrders,
        tanRequired,
        tanChallenge,

        fetchTransferOrders,
        createTransfer,
        updateTransfer,
        deleteTransfer,
        createTransferFromInvoice,
        submitTransfer,
        submitTransferTan
    }
}
