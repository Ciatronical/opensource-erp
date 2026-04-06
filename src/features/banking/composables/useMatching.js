// src/features/banking/composables/useMatching.js

import { ref } from 'vue'
import axios from 'axios'

const API_URL = '/api/banking/'

/**
 * Composable fuer Zuordnungs-Operationen
 */
export function useMatching() {

    const loading = ref(false)
    const openInvoices = ref([])
    const autoMatches = ref([])
    const matchingRules = ref([])

    // ═══════════════════════════════════════════════
    // OFFENE POSTEN
    // ═══════════════════════════════════════════════

    async function fetchOpenInvoices(type = 'all', search = '') {
        loading.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'getOpenInvoicesForMatching',
                type: type,
                search: search
            })
            if (response.data.success) {
                openInvoices.value = response.data.payload.invoices || []
            }
        } finally {
            loading.value = false
        }
    }

    // ═══════════════════════════════════════════════
    // AUTO-MATCHING
    // ═══════════════════════════════════════════════

    async function runAutoMatch(bankAccountId) {
        loading.value = true
        try {
            const response = await axios.post(API_URL, {
                action: 'runAutoMatch',
                bank_account_id: bankAccountId
            })
            if (response.data.success) {
                autoMatches.value = response.data.payload.matches || []
                return autoMatches.value
            }
            throw new Error(response.data.text)
        } finally {
            loading.value = false
        }
    }

    // ═══════════════════════════════════════════════
    // ZUORDNUNG
    // ═══════════════════════════════════════════════

    async function matchTransaction(bankTransactionId, targetType, targetId) {
        const response = await axios.post(API_URL, {
            action: 'matchTransaction',
            bank_transaction_id: bankTransactionId,
            target_type: targetType,
            target_id: targetId
        })
        if (!response.data.success) {
            throw new Error(response.data.payload || response.data.text)
        }
    }

    async function unmatchTransaction(bankTransactionId) {
        const response = await axios.post(API_URL, {
            action: 'unmatchTransaction',
            bank_transaction_id: bankTransactionId
        })
        if (!response.data.success) {
            throw new Error(response.data.payload || response.data.text)
        }
    }

    async function bookMatchedTransactions(transactionIds, bankAccountId) {
        const response = await axios.post(API_URL, {
            action: 'bookMatchedTransactions',
            transaction_ids: transactionIds,
            bank_account_id: bankAccountId
        })
        if (response.data.success) {
            return response.data.payload
        }
        throw new Error(response.data.payload || response.data.text)
    }

    // ═══════════════════════════════════════════════
    // REGELN
    // ═══════════════════════════════════════════════

    async function fetchMatchingRules(bankAccountId = null) {
        const response = await axios.post(API_URL, {
            action: 'getMatchingRules',
            bank_account_id: bankAccountId
        })
        if (response.data.success) {
            matchingRules.value = response.data.payload.rules || []
        }
    }

    async function saveMatchingRule(rule) {
        const response = await axios.post(API_URL, {
            action: 'saveMatchingRule',
            rule: rule
        })
        if (!response.data.success) {
            throw new Error(response.data.payload || response.data.text)
        }
    }

    async function deleteMatchingRule(ruleId) {
        const response = await axios.post(API_URL, {
            action: 'deleteMatchingRule',
            rule_id: ruleId
        })
        if (!response.data.success) {
            throw new Error(response.data.text)
        }
    }

    return {
        loading,
        openInvoices,
        autoMatches,
        matchingRules,

        fetchOpenInvoices,
        runAutoMatch,
        matchTransaction,
        unmatchTransaction,
        bookMatchedTransactions,

        fetchMatchingRules,
        saveMatchingRule,
        deleteMatchingRule
    }
}
