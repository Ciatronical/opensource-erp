<!-- src/features/trading/components/trading.bar-chart.vue -->
<!--
    Ergebnis je Strategie als liegende Balken ab der Nulllinie. Eine Reihe,
    eine Farbe: die Länge trägt den Wert, die Beschriftung den Namen.
-->
<template>
    <div class="trading-chart" :style="{ height: Math.max(120, items.length * 34 + 40) + 'px' }">
        <Bar v-if="items.length" :data="chartData" :options="chartOptions" />
        <div v-else class="trading-chart__empty">{{ emptyText }}</div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, BarElement, LinearScale, CategoryScale, Tooltip } from 'chart.js'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'

ChartJS.register(BarElement, LinearScale, CategoryScale, Tooltip)

const props = defineProps({
    // [{ label, value }] — Wert in Prozent
    items: { type: Array, default: () => [] },
    seriesLabel: { type: String, default: '' },
    emptyText: { type: String, default: '' },
})
const theme = useTheme()
const { locale } = useI18n()
const dark = computed(() => theme.global.current.value.dark)
const C = computed(() => dark.value
    ? { bar: '#3987e5', grid: 'rgba(255,255,255,0.08)', zero: 'rgba(255,255,255,0.35)', text: '#c3c2b7' }
    : { bar: '#2a78d6', grid: 'rgba(0,0,0,0.07)', zero: 'rgba(0,0,0,0.35)', text: '#52514e' })

const fmt = (v) => new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1, signDisplay: 'exceptZero' }).format(v) + ' %'

const chartData = computed(() => ({
    labels: props.items.map(i => i.label),
    datasets: [{
        label: props.seriesLabel,
        data: props.items.map(i => Number(i.value)),
        backgroundColor: C.value.bar,
        borderRadius: 4,
        barThickness: 16,
    }],
}))
const chartOptions = computed(() => ({
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${fmt(ctx.parsed.x)}` } },
    },
    scales: {
        x: {
            grid: { color: (ctx) => ctx.tick.value === 0 ? C.value.zero : C.value.grid },
            border: { display: false },
            ticks: { color: C.value.text, callback: (v) => fmt(v) },
        },
        y: { grid: { display: false }, ticks: { color: C.value.text } },
    },
}))
</script>

<style scoped>
.trading-chart { position: relative; }
.trading-chart__empty {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--oserp-text-muted);
}
</style>
