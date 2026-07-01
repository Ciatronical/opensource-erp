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
        challengeHhduc: null,
        decoupled: false,
        message: '',
        vopMatchStatus: null     // RVMA | RVMC | RVNM | RVNA — Hinweis fuer den User
    })

    // Wenn die Bank VoP-Pruefung negativ war, fragt der Server explizite
    // Bestaetigung an. Felder kommen aus Python-Runner (EVPE-Datenelemente).
    const vopApproval = reactive({
        required: false,
        result: null,            // RVNM/RVMC/RVNA Code
        recipientIban: null,
        infoIban: null,
        closeMatchName: null,    // Bank-Vorschlag bei "close match"
        naReason: null,          // Begruendung wenn Service nicht verfuegbar
        notice: null             // Aufklaerungstext der Bank
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
                    tanChallenge.challenge = payload.challenge || payload.challenge_html || ''
                    tanChallenge.tanMedium = payload.tan_medium || ''
                    tanChallenge.challengeHhduc = payload.challenge_hhduc
                    tanChallenge.decoupled = !!payload.decoupled
                    tanChallenge.message = payload.message || ''
                    tanChallenge.vopMatchStatus = payload.vop_match_status || null
                    return { tanRequired: true }
                }
                if (response.data.text === 'VOP_APPROVAL_REQUIRED') {
                    const payload = response.data.payload
                    const v = payload.vop || {}
                    vopApproval.required = true
                    vopApproval.result = v.result || null
                    vopApproval.recipientIban = v.recipient_iban || null
                    vopApproval.infoIban = v.info_iban || null
                    vopApproval.closeMatchName = v.close_match_name || null
                    vopApproval.naReason = v.na_reason || null
                    vopApproval.notice = payload.notice || null
                    return { vopApprovalRequired: true }
                }
                return { tanRequired: false }
            }
            throw new Error(response.data.payload || response.data.text)
        } finally {
            loading.value = false
        }
    }

    async function approveVopTransfer(transferOrderId, pin) {
        loading.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'fintsApproveVopTransfer',
                transfer_order_id: transferOrderId,
                pin
            })
            if (!response.data.success) {
                throw new Error(response.data.payload || response.data.text)
            }
            // Bank antwortet jetzt typischerweise mit TAN-Anforderung
            if (response.data.text === 'TAN_REQUIRED') {
                tanRequired.value = true
                const payload = response.data.payload
                tanChallenge.challenge = payload.challenge || payload.challenge_html || ''
                tanChallenge.tanMedium = payload.tan_medium || ''
                tanChallenge.challengeHhduc = payload.challenge_hhduc
                tanChallenge.decoupled = !!payload.decoupled
                tanChallenge.message = payload.message || ''
                vopApproval.required = false
                return { tanRequired: true }
            }
            vopApproval.required = false
            return { tanRequired: false }
        } finally {
            loading.value = false
        }
    }

    async function reconcileTransfers(bankAccountId = null) {
        const response = await axios.post(API_URL, {
            action: 'reconcileTransferOrders',
            bank_account_id: bankAccountId
        })
        if (response.data.success) {
            return response.data.payload
        }
        throw new Error(response.data.payload || response.data.text)
    }

    async function searchRecipient(query, limit = 20) {
        const response = await axios.post(API_URL, {
            action: 'searchTransferRecipient',
            query, limit
        })
        if (response.data.success) {
            return response.data.payload.results || []
        }
        throw new Error(response.data.payload || response.data.text)
    }

    async function createRecipient(payload) {
        const response = await axios.post(API_URL, {
            action: 'createTransferRecipient',
            ...payload
        })
        if (response.data.success) {
            return response.data.payload
        }
        throw new Error(response.data.payload || response.data.text)
    }

    async function updateRecipientBank({ type, id, iban, bic }) {
        const response = await axios.post(API_URL, {
            action: 'updateRecipientBankDetails',
            type, id, iban, bic
        })
        if (!response.data.success) {
            throw new Error(response.data.payload || response.data.text)
        }
    }

    async function submitTransferBatch(transferOrderIds, pin, executionDate = null) {
        loading.value = true
        tanRequired.value = false
        try {
            const response = await axios.post(API_URL, {
                action: 'fintsSubmitTransferBatch',
                transfer_order_ids: transferOrderIds,
                pin,
                execution_date: executionDate || ''
            })
            if (response.data.success) {
                if (response.data.text === 'TAN_REQUIRED') {
                    tanRequired.value = true
                    const payload = response.data.payload
                    tanChallenge.challenge = payload.challenge
                    tanChallenge.tanMedium = payload.tan_medium
                    tanChallenge.challengeHhduc = payload.challenge_hhduc
                    tanChallenge.decoupled = !!payload.decoupled
                    tanChallenge.message = payload.message || ''
                    return { tanRequired: true, batchId: payload.batch_id }
                }
                return { tanRequired: false, count: response.data.payload?.count || 0 }
            }
            throw new Error(response.data.payload || response.data.text)
        } finally {
            loading.value = false
        }
    }

    async function submitTransferBatchTan(batchId, tan, pin) {
        loading.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'fintsSubmitTransferBatchTan',
                batch_id: batchId,
                tan, pin
            })
            if (!response.data.success) {
                throw new Error(response.data.payload || response.data.text)
            }
            // Nach Login-TAN folgt eine zweite TAN fuer die eigentliche
            // Sammelueberweisung — Composable reicht das durch.
            if (response.data.text === 'TAN_REQUIRED') {
                const payload = response.data.payload
                tanChallenge.challenge = payload.challenge
                tanChallenge.tanMedium = payload.tan_medium
                tanChallenge.challengeHhduc = payload.challenge_hhduc
                tanChallenge.decoupled = !!payload.decoupled
                tanChallenge.message = payload.message || ''
                return { tanRequired: true, batchId: payload.batch_id || batchId }
            }
            tanRequired.value = false
            return { tanRequired: false, ...(response.data.payload || {}) }
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
            if (!response.data.success) {
                throw new Error(response.data.payload || response.data.text)
            }
            // Folge-TAN moeglich: nach Login-TAN kommt die eigentliche SEPA-TAN,
            // bei pushTAN-Polling kommt der gleiche Schritt mit "noch nicht bestaetigt".
            if (response.data.text === 'TAN_REQUIRED') {
                const payload = response.data.payload
                tanChallenge.challenge = payload.challenge
                tanChallenge.tanMedium = payload.tan_medium
                tanChallenge.challengeHhduc = payload.challenge_hhduc
                tanChallenge.decoupled = !!payload.decoupled
                tanChallenge.message = payload.message || ''
                return { tanRequired: true }
            }
            tanRequired.value = false
            return { tanRequired: false }
        } finally {
            loading.value = false
        }
    }

    return {
        loading,
        transferOrders,
        tanRequired,
        tanChallenge,
        vopApproval,
        approveVopTransfer,

        fetchTransferOrders,
        createTransfer,
        updateTransfer,
        deleteTransfer,
        createTransferFromInvoice,
        submitTransfer,
        submitTransferTan,
        searchRecipient,
        createRecipient,
        updateRecipientBank,
        reconcileTransfers,
        submitTransferBatch,
        submitTransferBatchTan
    }
}
