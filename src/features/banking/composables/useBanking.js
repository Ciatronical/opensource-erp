// src/features/banking/composables/useBanking.js

import { ref, reactive } from 'vue'
import axios from 'axios'

const API_URL = '/api/banking/'

/**
 * Composable fuer Banking-Operationen
 * Zentrale Logik fuer Konten, Umsaetze, FinTS-Sync
 */
export function useBanking() {

    const loading = ref(false)
    const error = ref(null)

    // ═══════════════════════════════════════════════
    // KONTEN
    // ═══════════════════════════════════════════════

    const accounts = ref([])

    async function fetchAccounts() {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post(API_URL, { action: 'getBankingOverview' })
            if (response.data.success) {
                accounts.value = response.data.payload.accounts || []
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function fetchAccountStats(bankAccountId) {
        const response = await axios.post(API_URL, {
            action: 'getBankAccountStats',
            bank_account_id: bankAccountId
        })
        if (response.data.success) {
            return response.data.payload.stats
        }
        throw new Error(response.data.text || 'Fehler beim Laden der Kontostatistik')
    }

    // ═══════════════════════════════════════════════
    // UMSAETZE
    // ═══════════════════════════════════════════════

    const transactions = ref([])
    const transactionTotal = ref(0)

    async function fetchTransactions(bankAccountId, params = {}) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post(API_URL, {
                action: 'getBankTransactions',
                bank_account_id: bankAccountId,
                ...params
            })
            if (response.data.success) {
                transactions.value = response.data.payload.transactions || []
                transactionTotal.value = response.data.payload.total || 0
            } else {
                error.value = response.data.text
            }
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function setTransactionStatus(transactionId, status) {
        const response = await axios.post(API_URL, {
            action: 'setTransactionStatus',
            transaction_id: transactionId,
            status: status
        })
        if (!response.data.success) {
            throw new Error(response.data.text || 'Fehler')
        }
    }

    // ═══════════════════════════════════════════════
    // FINTS
    // ═══════════════════════════════════════════════

    const fintsConfig = ref(null)
    const tanRequired = ref(false)
    const tanChallenge = reactive({
        challenge: '',
        tanMedium: '',
        challengeHhduc: null
    })

    async function fetchFintsConfig(bankAccountId) {
        const response = await axios.post(API_URL, {
            action: 'getFintsConfig',
            bank_account_id: bankAccountId
        })
        if (response.data.success) {
            fintsConfig.value = response.data.payload.fints_config
            return fintsConfig.value
        }
        return null
    }

    async function saveFintsConfig(configData) {
        const response = await axios.post(API_URL, {
            action: 'saveFintsConfig',
            ...configData
        })
        if (!response.data.success) {
            throw new Error(response.data.payload || response.data.text)
        }
    }

    async function deleteFintsConfig(bankAccountId) {
        const response = await axios.post(API_URL, {
            action: 'deleteFintsConfig',
            bank_account_id: bankAccountId
        })
        if (!response.data.success) {
            throw new Error(response.data.text)
        }
        fintsConfig.value = null
    }

    async function syncTransactions(bankAccountId, pin, fromDate = null, toDate = null) {
        loading.value = true
        tanRequired.value = false
        error.value = null

        try {
            const response = await axios.post(API_URL, {
                action: 'fintsSyncTransactions',
                bank_account_id: bankAccountId,
                pin: pin,
                from_date: fromDate,
                to_date: toDate
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
                return {
                    tanRequired: false,
                    importedCount: response.data.payload.imported_count
                }
            }
            throw new Error(response.data.payload || response.data.text)
        } catch (e) {
            error.value = e.message
            throw e
        } finally {
            loading.value = false
        }
    }

    async function submitTan(bankAccountId, tan, pin) {
        loading.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'fintsSubmitTan',
                bank_account_id: bankAccountId,
                tan: tan,
                pin: pin
            })

            tanRequired.value = false

            if (response.data.success) {
                return {
                    importedCount: response.data.payload?.imported_count || 0
                }
            }
            throw new Error(response.data.payload || response.data.text)
        } finally {
            loading.value = false
        }
    }

    async function getBalance(bankAccountId, pin) {
        const response = await axios.post(API_URL, {
            action: 'fintsGetBalance',
            bank_account_id: bankAccountId,
            pin: pin
        })
        if (response.data.success && response.data.text !== 'TAN_REQUIRED') {
            return response.data.payload
        }
        if (response.data.text === 'TAN_REQUIRED') {
            return { tanRequired: true }
        }
        throw new Error(response.data.payload || response.data.text)
    }

    return {
        // State
        loading,
        error,
        accounts,
        transactions,
        transactionTotal,
        fintsConfig,
        tanRequired,
        tanChallenge,

        // Konten
        fetchAccounts,
        fetchAccountStats,

        // Umsaetze
        fetchTransactions,
        setTransactionStatus,

        // FinTS
        fetchFintsConfig,
        saveFintsConfig,
        deleteFintsConfig,
        syncTransactions,
        submitTan,
        getBalance
    }
}
