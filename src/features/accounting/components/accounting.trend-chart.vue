<template>
    <div style="position: relative; height: 300px;">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
    Chart as ChartJS, LineElement, PointElement, LinearScale, CategoryScale,
    Tooltip, Legend, Filler
} from 'chart.js'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'

ChartJS.register(LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Legend, Filler)

const props = defineProps({ series: { type: Array, default: () => [] } })
const theme = useTheme()
const { t } = useI18n()

// Den laufenden (am Monatsanfang quasi leeren) Monat weglassen — er steht in den
// Kacheln und würde die Linie sonst auf 0 stürzen lassen. Außerdem führende
// Null-Monate (noch keine Buchungen) für eine ruhigere Optik entfernen.
const rows = computed(() => {
    let s = props.series.filter(r => Number(r.is_current) !== 1)
    let i = 0
    while (i < s.length - 1 && Number(s[i].income) === 0 && Number(s[i].expense) === 0) i++
    return s.slice(i)
})

// Der jüngste gezeigte Monat wird i. d. R. noch gebucht → gestrichelt markieren.
const lastIdx = computed(() => rows.value.length - 1)

// Farben aus der validierten Palette (blau/orange, farbfehlsichtig-sicher), je nach Theme.
const dark = computed(() => theme.global.current.value.dark)
const C = computed(() => dark.value
    ? { income: '#3987e5', expense: '#d95926', grid: 'rgba(255,255,255,0.08)', text: '#c3c2b7' }
    : { income: '#2a78d6', expense: '#eb6834', grid: 'rgba(0,0,0,0.07)',      text: '#52514e' })

function rgba(hex, a) {
    const n = parseInt(hex.slice(1), 16)
    return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`
}
function fmt(v) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v || 0)
}

// Segment in den jüngsten (noch nicht fertig gebuchten) Monat gestrichelt und blasser.
function segmentStyle(color) {
    return {
        borderColor: (ctx) => (lastIdx.value > 0 && ctx.p1DataIndex === lastIdx.value) ? rgba(color, 0.45) : color,
        borderDash:  (ctx) => (lastIdx.value > 0 && ctx.p1DataIndex === lastIdx.value) ? [5, 4] : undefined,
    }
}
function pointRadius(ctx) {
    return ctx.dataIndex === lastIdx.value ? 4 : 3
}

function dataset(key, color) {
    return {
        label: key === 'income' ? t('AccountingView.overview.income') : t('AccountingView.overview.expenses'),
        data: rows.value.map(r => Number(r[key])),
        borderColor: color,
        backgroundColor: rgba(color, 0.08),
        pointBackgroundColor: color,
        pointBorderColor: color,
        fill: true,
        tension: 0.35,
        borderWidth: 2,
        pointRadius: pointRadius,
        pointHoverRadius: 6,
        segment: segmentStyle(color),
    }
}

const chartData = computed(() => ({
    labels: rows.value.map(r => r.label),
    datasets: [dataset('income', C.value.income), dataset('expense', C.value.expense)],
}))

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: {
            position: 'top', align: 'end',
            labels: { color: C.value.text, usePointStyle: true, pointStyle: 'line', boxWidth: 24, boxHeight: 2, padding: 16 },
        },
        tooltip: {
            callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${fmt(ctx.parsed.y)}` },
        },
    },
    scales: {
        x: { grid: { display: false }, ticks: { color: C.value.text, maxRotation: 0, autoSkipPadding: 12 } },
        y: {
            grid: { color: C.value.grid }, border: { display: false }, beginAtZero: true,
            ticks: { color: C.value.text, callback: (v) => Math.round(v / 1000) + 'k' },
        },
    },
}))
</script>
