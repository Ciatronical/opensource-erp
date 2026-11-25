<!-- src/features/trading/components/trading.dashboard-card.vue -->
<!--
    Kachel für die Startseite: wie sich das Kapital entwickelt, auf einen Blick.
    Zeigt ausschliesslich gespeicherte Werte; ohne Daten steht dort „noch keine
    Daten“, keine Beispielzahlen.
-->
<template>
    <section-card :title="t('TradingView.title')" icon="mdi-chart-timeline-variant-shimmer" class="trading-card">
        <template #actions>
            <v-chip size="x-small" variant="tonal" :color="modeColor">{{ t('TradingView.mode.' + (summary?.mode || 'simulation')) }}</v-chip>
            <v-chip size="x-small" variant="tonal" :color="alive ? 'success' : 'warning'">{{ alive ? t('TradingView.ai.active') : t('TradingView.ai.inactive') }}</v-chip>
            <v-btn size="small" variant="text" append-icon="mdi-arrow-right" :to="{ name: 'trading' }">{{ t('TradingView.card.open') }}</v-btn>
        </template>
        <div v-if="summary?.has_data" class="trading-card__grid">
            <div v-for="k in kpis" :key="k.label" class="trading-card__kpi">
                <div class="trading-card__label">{{ k.label }}</div>
                <div class="trading-card__value">{{ k.value }}</div>
            </div>
        </div>
        <div v-else class="trading-card__label">{{ failed ? t('TradingView.card.unavailable') : t('TradingView.overview.noData') }}</div>
        <v-alert v-for="lock in summary?.locks || []" :key="lock.id" type="error" variant="tonal" density="compact" class="mt-2">
            {{ t('TradingView.lock.active') }}: {{ t('TradingView.lock.reason.' + lock.reason_code, lock.reason_code) }}
        </v-alert>
    </section-card>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import SectionCard from '@/core/components/layout/section-card.vue'
import { tradingStore } from '@/features/trading/stores/trading.store.js'
import { useTradingFormat } from '@/features/trading/composables/useTradingFormat.js'

const { t } = useI18n()
const store = tradingStore()
const failed = ref(false)
const summary = computed(() => store.summary)
const { fmtMoney, fmtPct, engineAlive } = useTradingFormat(() => summary.value?.currency || 'EUR')
const alive = computed(() => engineAlive(summary.value))
const modeColor = computed(() => ({ live: 'error', halted: 'error', simulation: 'info' }[summary.value?.mode] || 'info'))

const kpis = computed(() => {
    const s = summary.value
    return [
        { label: s.account_kind === 'live' ? t('TradingView.kpi.startCapitalLive') : t('TradingView.kpi.startCapital'), value: fmtMoney(s.start_capital) },
        { label: t('TradingView.kpi.portfolioValue'), value: fmtMoney(s.portfolio_value) },
        { label: t('TradingView.kpi.dayPnl'), value: fmtMoney(s.day_pnl, true) },
        { label: t('TradingView.kpi.totalPnl'), value: fmtMoney(s.total_pnl, true) },
        { label: t('TradingView.kpi.totalReturn'), value: fmtPct(s.total_return_pct) },
        { label: t('TradingView.kpi.activeStrategies'), value: s.active_strategies },
    ]
})

onMounted(async () => {
    try {
        await store.loadSummary()
    } catch (e) {
        failed.value = true
    }
})
</script>

<style scoped>
.trading-card { text-align: left; }
.trading-card__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: var(--oserp-gap);
}
.trading-card__label { color: var(--oserp-text-muted); font-size: 0.8rem; }
.trading-card__value { font-size: 1.2rem; font-weight: 600; font-variant-numeric: tabular-nums; }
</style>
