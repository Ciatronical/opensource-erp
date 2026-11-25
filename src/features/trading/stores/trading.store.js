// src/features/trading/stores/trading.store.js

import { defineStore } from 'pinia'
import { ref } from 'vue'
import axios from 'axios'

/**
 * Zustand der Erweiterung „KI-Trading“
 *
 * Dashboard und Startseiten-Kachel lesen dieselben Kennzahlen (summary) —
 * einmal geladen, von beiden benutzt.
 */
export const tradingStore = defineStore('tradingStore', () => {
    const summary = ref(null)
    const dashboard = ref(null)
    const loading = ref(false)

    async function call(action, data = {}) {
        const response = await axios.post('/api/trading/', { action, ...data })
        if (!response.data.success) {
            throw new Error(response.data.payload || response.data.text || action)
        }
        return response.data.payload?.results ?? null
    }

    async function loadSummary() {
        summary.value = await call('getTradingSummary')
        return summary.value
    }

    async function loadDashboard(days = 180) {
        loading.value = true
        try {
            dashboard.value = await call('getTradingDashboard', { days })
            summary.value = dashboard.value?.summary ?? summary.value
            return dashboard.value
        } finally {
            loading.value = false
        }
    }

    return { summary, dashboard, loading, call, loadSummary, loadDashboard }
})
