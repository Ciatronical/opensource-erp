<!-- src/core/views/hr/tabs/hr.performance.worktime.vue -->
<!--
    Personal → Leistung: Arbeitszeit-Auswertung in drei Zahlen.

    Regelarbeitszeit (Soll) · vorgegebene Arbeitszeit · benötigte Arbeitszeit,
    wahlweise pro Tag, Woche, Monat oder Fahrzeug — in der Teamansicht
    zusätzlich pro Mitarbeiter. Die Tageswerte kommen fertig aus der Abfrage
    der Leistungsdaten, hier wird nur gruppiert und formatiert.
    Pro Fahrzeug gibt es keine Regelarbeitszeit (nur Vorgabe und benötigt).
-->
<template>
    <section-card :title="t('HrView.performance.worktime.title')" :subtitle="t('HrView.performance.worktime.subtitle')" icon="mdi-clock-check-outline" plain>
        <template #actions>
            <v-btn-toggle v-model="mode" mandatory density="compact" color="primary" variant="outlined" divided>
                <v-btn v-for="m in modes" :key="m" :value="m" size="x-small">
                    {{ t('HrView.performance.worktime.by.' + m) }}
                </v-btn>
            </v-btn-toggle>
        </template>

        <div v-if="rows.length" class="worktime__scroll">
            <v-table density="compact" hover fixed-header class="worktime__table">
                <thead>
                    <tr>
                        <th>{{ t('HrView.performance.worktime.by.' + mode) }}</th>
                        <th class="text-right">{{ t('HrView.performance.worktime.regular') }}</th>
                        <th class="text-right">{{ t('HrView.performance.worktime.planned') }}</th>
                        <th class="text-right">{{ t('HrView.performance.worktime.needed') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in rows"
                        :key="r.key"
                        :class="{ 'worktime__row--link': r.employee_id || r.oe_id }"
                        @click="onRow(r)"
                    >
                        <td class="worktime__label">
                            {{ r.label }}
                            <span v-if="r.sub" class="text-caption text-medium-emphasis"> · {{ r.sub }}</span>
                        </td>
                        <td class="text-right worktime__num">{{ r.soll === null ? '–' : fmt(r.soll) }}</td>
                        <td class="text-right worktime__num">{{ fmt(r.planned) }}</td>
                        <td class="text-right worktime__num">{{ fmt(r.actual) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="worktime__total">
                        <td>{{ t('HrView.performance.worktime.total') }}</td>
                        <td class="text-right worktime__num">{{ total.soll === null ? '–' : fmt(total.soll) }}</td>
                        <td class="text-right worktime__num">{{ fmt(total.planned) }}</td>
                        <td class="text-right worktime__num">{{ fmt(total.actual) }}</td>
                    </tr>
                </tfoot>
            </v-table>
        </div>
        <div v-else class="worktime__empty">{{ t('HrView.performance.noData') }}</div>
    </section-card>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import SectionCard from '@/core/components/layout/section-card.vue'
import { entityRoute } from '@/core/constants/routes.js'

const props = defineProps({
    // Zeilen je Mitarbeiter und Tag: { employee_id, day, soll, planned, actual } (Minuten)
    days: { type: Array, default: () => [] },
    // Zeilen je Mitarbeiter und Auftrag: { employee_id, oe_id, day, planned, actual }
    vehicles: { type: Array, default: () => [] },
    // Kopfdaten der Aufträge: oe_id → { ordnumber, kennzeichen, customer }
    orders: { type: Object, default: () => ({}) },
    // Teamansicht: employee_id → Name; schaltet die Gruppierung „Mitarbeiter" frei
    names: { type: Object, default: null },
    // Gewählter Zeitraum der Seite (week | month | quarter | year) für die Vorauswahl
    period: { type: String, default: 'month' },
})
const emit = defineEmits(['select'])

const { t, locale, n } = useI18n()
const router = useRouter()

const modes = computed(() => (props.names ? ['employee'] : []).concat(['day', 'week', 'month', 'vehicle']))

function defaultMode() {
    if (props.names) return 'employee'
    if (props.period === 'week') return 'day'
    return props.period === 'month' ? 'week' : 'month'
}
const mode = ref(defaultMode())
watch(() => props.period, () => { mode.value = defaultMode() })

function fromIso(s) { return new Date(s + 'T12:00:00') }
function pad(v) { return String(v).padStart(2, '0') }
function iso(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` }
function weekStart(s) {
    const d = fromIso(s)
    d.setDate(d.getDate() - (d.getDay() || 7) + 1)
    return d
}
function isoWeek(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()))
    d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7))
    return Math.ceil((((d - new Date(Date.UTC(d.getUTCFullYear(), 0, 1))) / 86400000) + 1) / 7)
}
function fmt(min) {
    return n((Number(min) || 0) / 60, { maximumFractionDigits: 1 }) + ' h'
}
function shortDate(d) { return d.toLocaleDateString(locale.value, { day: 'numeric', month: 'numeric' }) }

// Gruppiert Zeilen über einen Schlüssel und summiert die drei Zeiten
function group(list, keyOf, describe, withSoll = true) {
    const map = new Map()
    for (const x of list) {
        const key = keyOf(x)
        let row = map.get(key)
        if (!row) {
            row = { key, soll: withSoll ? 0 : null, planned: 0, actual: 0, ...describe(x, key) }
            map.set(key, row)
        }
        if (withSoll) row.soll += Number(x.soll) || 0
        row.planned += Number(x.planned) || 0
        row.actual += Number(x.actual) || 0
    }
    return [...map.values()]
}

const rows = computed(() => {
    if (mode.value === 'employee') {
        return group(props.days, x => x.employee_id, x => ({ employee_id: x.employee_id, label: props.names?.[x.employee_id] || '' }))
            .sort((a, b) => a.label.localeCompare(b.label))
    }
    if (mode.value === 'day') {
        return group(props.days, x => x.day, x => ({
            label: fromIso(x.day).toLocaleDateString(locale.value, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
        })).sort((a, b) => b.key.localeCompare(a.key))
    }
    if (mode.value === 'week') {
        return group(props.days, x => iso(weekStart(x.day)), (x, key) => {
            const from = fromIso(key)
            const to = new Date(from)
            to.setDate(to.getDate() + 6)
            return { label: t('HrView.performance.weekShort', { n: isoWeek(from) }), sub: shortDate(from) + ' – ' + shortDate(to) }
        }).sort((a, b) => b.key.localeCompare(a.key))
    }
    if (mode.value === 'month') {
        return group(props.days, x => x.day.slice(0, 7), x => ({
            label: fromIso(x.day).toLocaleDateString(locale.value, { month: 'long', year: 'numeric' })
        })).sort((a, b) => b.key.localeCompare(a.key))
    }
    // Fahrzeug: Aufträge desselben Kennzeichens zusammengefasst
    return group(props.vehicles, x => props.orders[x.oe_id]?.kennzeichen || 'oe' + x.oe_id, x => {
        const o = props.orders[x.oe_id] || {}
        return { oe_id: x.oe_id, label: o.kennzeichen || o.ordnumber || String(x.oe_id), sub: o.customer || '' }
    }, false).sort((a, b) => b.actual - a.actual)
})

const total = computed(() => rows.value.reduce((s, r) => ({
    soll: r.soll === null ? null : s.soll + r.soll,
    planned: s.planned + r.planned,
    actual: s.actual + r.actual
}), { soll: 0, planned: 0, actual: 0 }))

function onRow(r) {
    if (r.employee_id) emit('select', r.employee_id)
    else if (r.oe_id) {
        const target = entityRoute('order', r.oe_id)
        if (target) router.push(target)
    }
}
</script>

<style scoped>
.worktime__scroll { max-height: 420px; overflow: auto; }
.worktime__table th { white-space: nowrap; }
.worktime__label { white-space: nowrap; }
.worktime__num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.worktime__row--link { cursor: pointer; }
.worktime__total td {
    font-weight: 600;
    border-top: 1px solid var(--oserp-border);
    background: var(--oserp-surface-soft);
    position: sticky;
    bottom: 0;
}
.worktime__empty {
    padding: var(--oserp-gap-lg);
    text-align: center;
    color: var(--oserp-text-muted);
    font-size: 0.875rem;
}
</style>
