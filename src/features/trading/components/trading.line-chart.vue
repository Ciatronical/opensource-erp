<!-- src/features/trading/components/trading.line-chart.vue -->
<!--
    Liniendiagramm für Wertentwicklungen. Alle Reihen liegen auf EINER Achse:
    mit `indexed` wird jede Reihe auf ihren ersten Wert bezogen (Veränderung
    in %), damit Portfolio und Vergleichsmaßstab trotz verschiedener
    Grössenordnung vergleichbar sind — keine zweite Achse.
-->
<template>
    <div class="trading-chart" :style="{ height: height + 'px' }">
        <Line v-if="hasData" :data="chartData" :options="chartOptions" />
        <div v-else class="trading-chart__empty">{{ emptyText }}</div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import { Chart as ChartJS, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Legend, Filler } from 'chart.js'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'

ChartJS.register(LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Legend, Filler)

const props = defineProps({
    // [{ label, points: [{ x, y }] }] — höchstens zwei Reihen, feste Reihenfolge
    series: { type: Array, default: () => [] },
    indexed: { type: Boolean, default: false },
    currency: { type: String, default: 'EUR' },
    height: { type: Number, default: 280 },
    emptyText: { type: String, default: '' },
})
const theme = useTheme()
const { locale } = useI18n()

// Dieselbe geprüfte Palette wie im Buchhaltungs-Trend (blau/orange, farbfehlsichtig-sicher).
// Die Farbe hängt an der Stelle der Reihe, nicht an ihrem Wert.
const dark = computed(() => theme.global.current.value.dark)
const C = computed(() => dark.value
    ? { lines: ['#3987e5', '#d95926'], grid: 'rgba(255,255,255,0.08)', text: '#c3c2b7' }
    : { lines: ['#2a78d6', '#eb6834'], grid: 'rgba(0,0,0,0.07)', text: '#52514e' })

const labels = computed(() => {
    const all = new Set()
    props.series.forEach(s => s.points.forEach(p => all.add(p.x)))
    return [...all].sort()
})
const hasData = computed(() => labels.value.length > 1)

function values(s) {
    const byX = new Map(s.points.map(p => [p.x, Number(p.y)]))
    const base = Number(s.points.find(p => Number.isFinite(Number(p.y)) && Number(p.y) > 0)?.y)
    return labels.value.map(x => {
        const y = byX.get(x)
        if (!Number.isFinite(y)) return null
        return props.indexed ? (y / base - 1) * 100 : y
    })
}

function fmt(v) {
    if (props.indexed) {
        return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1, signDisplay: 'exceptZero' }).format(v) + ' %'
    }
    return new Intl.NumberFormat(locale.value, { style: 'currency', currency: props.currency, maximumFractionDigits: 0 }).format(v)
}
function fmtDay(x) {
    const d = new Date(x)
    return Number.isNaN(d.getTime()) ? x : new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit', year: '2-digit' }).format(d)
}

const chartData = computed(() => ({
    labels: labels.value,
    datasets: props.series.slice(0, 2).map((s, i) => ({
        label: s.label,
        data: values(s),
        borderColor: C.value.lines[i],
        backgroundColor: C.value.lines[i],
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 5,
        spanGaps: true,
        tension: 0.15,
    })),
}))

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        // Eine einzelne Reihe braucht keine Legende — der Kartentitel benennt sie
        legend: {
            display: props.series.length > 1,
            position: 'top', align: 'end',
            labels: { color: C.value.text, usePointStyle: true, pointStyle: 'line', boxWidth: 24, padding: 16 },
        },
        tooltip: {
            callbacks: {
                title: (items) => fmtDay(items[0].label),
                label: (ctx) => ` ${ctx.dataset.label}: ${fmt(ctx.parsed.y)}`,
            },
        },
    },
    scales: {
        x: { grid: { display: false }, ticks: { color: C.value.text, maxRotation: 0, autoSkipPadding: 24, callback(v) { return fmtDay(this.getLabelForValue(v)) } } },
        y: { grid: { color: C.value.grid }, border: { display: false }, ticks: { color: C.value.text, callback: (v) => fmt(v) } },
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
    text-align: center;
    padding: var(--oserp-gap-lg);
}
</style>
