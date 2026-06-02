// src/features/banking/composables/useBanking.js

import { ref, reactive } from 'vue'
import axios from 'axios'

const API_URL = '/api/banking/'

// ── Globaler Trust-Anker Keep-Alive (Modul-Level-Singleton) ──────────────────
// Läuft unabhängig vom Lebenszyklus einzelner Komponenten für die gesamte
// Browser-Session. Wird beim ersten Aufruf von startGlobalKeepAlive() gestartet.
let _keepAliveTimer = null

export function startGlobalKeepAlive() {
    if (_keepAliveTimer) return
    const run = () => axios.post(API_URL, { action: 'fintsKeepAliveAll' }).catch(() => {})
    run()
    _keepAliveTimer = setInterval(run, 45 * 60 * 1000)
}
// ─────────────────────────────────────────────────────────────────────────────

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
        challengeHhduc: null,
        decoupled: false,
        message: ''
    })

    async function fetchFintsUrlByBlz(bankCode) {
        if (!bankCode) return null
        const response = await axios.post(API_URL, {
            action: 'getFintsUrlByBlz',
            bank_code: bankCode
        })
        if (response.data.success) {
            return response.data.payload
        }
        return null
    }

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
                    tanChallenge.decoupled = !!payload.decoupled
                    tanChallenge.message = payload.message || ''
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

            if (response.data.success) {
                // Decoupled: Bank meldet "noch nicht bestaetigt" — Dialog offen lassen
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
                return {
                    importedCount: response.data.payload?.imported_count || 0
                }
            }
            throw new Error(response.data.payload || response.data.text)
        } finally {
            loading.value = false
        }
    }

    async function saveBankingPin(bankAccountId, pin) {
        const response = await axios.post(API_URL, {
            action: 'fintsSavePin',
            bank_account_id: bankAccountId,
            pin
        })
        if (!response.data.success) throw new Error(response.data.payload || response.data.text)
    }

    async function deleteBankingPin(bankAccountId) {
        const response = await axios.post(API_URL, {
            action: 'fintsDeletePin',
            bank_account_id: bankAccountId
        })
        if (!response.data.success) throw new Error(response.data.payload || response.data.text)
    }

    async function loadBankingPin(bankAccountId) {
        const response = await axios.post(API_URL, {
            action: 'fintsLoadPin',
            bank_account_id: bankAccountId
        })
        if (response.data.success) return response.data.payload?.pin || ''
        throw new Error(response.data.payload || response.data.text)
    }

    // Gibt 'REFRESHED' | 'EXPIRED' | 'NO_PIN_SAVED' | 'NO_STATE' zurück
    async function keepAliveTrustAnchor(bankAccountId) {
        const response = await axios.post(API_URL, {
            action: 'fintsKeepAliveTrustAnchor',
            bank_account_id: bankAccountId
        })
        if (!response.data.success) throw new Error(response.data.payload || response.data.text)
        return response.data.text
    }

    async function keepAliveAll() {
        await axios.post(API_URL, { action: 'fintsKeepAliveAll' })
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
        fetchFintsUrlByBlz,
        fetchFintsConfig,
        saveFintsConfig,
        deleteFintsConfig,
        syncTransactions,
        submitTan,
        getBalance,
        saveBankingPin,
        deleteBankingPin,
        loadBankingPin,
        keepAliveTrustAnchor
    }
}
