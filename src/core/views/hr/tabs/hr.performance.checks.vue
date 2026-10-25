<!-- src/core/views/hr/tabs/hr.performance.checks.vue -->
<!--
    Personal → Leistung: Plausibilitätskontrolle der erfassten Zeiten.

    Zeigt die vom Backend markierten Auffälligkeiten (performance.php):
    - day   : Tag mit mehr Ist-Zeit als die Netto-Arbeitszeit aus der Konfiguration
    - timer : einzelne Anweisung länger als ein ganzer Arbeitstag (Timer vergessen?)
    - high  : mindestens das Dreifache der Vorgabe bzw. des üblichen Werts
    - low   : höchstens ein Drittel der Vorgabe bzw. des üblichen Werts

    Jeder Auftrag ist ein echter Link auf die Faktura-Ansicht (auch Strg-Klick
    bzw. „In neuem Tab öffnen“).
-->
<template>
    <section-card
        :title="t('HrView.performance.checks.title')"
        :subtitle="t('HrView.performance.checks.subtitle', { h: fmtHours(threshold) })"
        icon="mdi-shield-check-outline"
        plain
    >
        <v-alert v-if="!rows.length" type="success" variant="tonal" density="compact" class="ma-3">
            {{ t('HrView.performance.checks.none') }}
        </v-alert>

        <template v-else>
            <div class="checks__filter">
                <v-chip-group v-model="filter" mandatory selected-class="text-primary" column>
                    <v-chip value="all" size="small" variant="outlined" filter>
                        {{ t('HrView.performance.checks.filterAll', { n: rows.length }) }}
                    </v-chip>
                    <v-chip
                        v-for="k in kindsPresent"
                        :key="k"
                        :value="k"
                        size="small"
                        variant="outlined"
                        filter
                    >
                        {{ t('HrView.performance.checks.filter.' + k, { n: counts[k] }) }}
                    </v-chip>
                </v-chip-group>
            </div>

            <v-table density="compact" class="checks__table">
                <thead>
                    <tr>
                        <th>{{ t('HrView.performance.checks.day') }}</th>
                        <th v-if="showMechanic">{{ t('HrView.performance.ranking.mechanic') }}</th>
                        <th>{{ t('HrView.performance.checks.order') }}</th>
                        <th>{{ t('HrView.performance.checks.what') }}</th>
                        <th class="text-right">{{ t('HrView.performance.checks.recorded') }}</th>
                        <th class="text-right">{{ t('HrView.performance.checks.expected') }}</th>
                        <th>{{ t('HrView.performance.checks.hint') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in visibleRows" :key="r.key" :class="{ 'checks__row--day': r.kind === 'day' }">
                        <td class="checks__nowrap">{{ fmtDay(r.day) }}</td>
                        <td v-if="showMechanic" class="checks__nowrap">
                            <button type="button" class="checks__link" @click="$emit('select', r.employee_id)">{{ r.name }}</button>
                        </td>
                        <td class="checks__nowrap">
                            <template v-if="r.kind === 'day'">
                                <v-chip
                                    v-for="o in r.orders"
                                    :key="o.oe_id"
                                    size="x-small"
                                    variant="tonal"
                                    color="primary"
                                    class="mr-1"
                                    :to="orderRoute(o.oe_id)"
                                >
                                    {{ o.ordnumber || o.oe_id }}
                                </v-chip>
                            </template>
                            <template v-else>
                                <router-link :to="orderRoute(r.oe_id)" class="checks__link">{{ r.order.ordnumber || r.oe_id }}</router-link>
                                <span v-if="r.order.kennzeichen" class="text-medium-emphasis"> · {{ r.order.kennzeichen }}</span>
                            </template>
                        </td>
                        <td class="checks__what" :title="r.kind === 'day' ? '' : r.description">
                            <template v-if="r.kind === 'day'">
                                {{ t('HrView.performance.checks.dayDetail', { n: r.n, orders: r.orders.length }) }}
                            </template>
                            <template v-else>{{ r.description }}</template>
                        </td>
                        <td class="text-right checks__num font-weight-medium">{{ fmtDuration(r.actual) }}</td>
                        <td class="text-right checks__num text-medium-emphasis">
                            <template v-if="r.kind === 'day'">{{ t('HrView.performance.checks.workTime', { v: fmtHours(threshold) }) }}</template>
                            <template v-else-if="r.ref_source === 'planned'">{{ t('HrView.performance.checks.planned', { v: fmtDuration(r.ref) }) }}</template>
                            <template v-else-if="r.ref_source === 'median'">{{ t('HrView.performance.checks.usual', { v: fmtDuration(r.ref) }) }}</template>
                            <template v-else>–</template>
                        </td>
                        <td>
                            <v-chip size="x-small" variant="tonal" :color="KIND_COLOR[r.kind]">
                                {{ t('HrView.performance.checks.kind.' + r.kind) }}
                            </v-chip>
                        </td>
                    </tr>
                </tbody>
            </v-table>

            <div v-if="filtered.length > LIMIT" class="pa-2 text-center">
                <v-btn variant="text" size="small" @click="expanded = !expanded">
                    {{ expanded ? t('HrView.performance.tasks.showLess') : t('HrView.performance.checks.showAll', { n: filtered.length }) }}
                </v-btn>
            </div>
        </template>
    </section-card>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SectionCard from '@/core/components/layout/section-card.vue'
import { entityRoute } from '@/core/constants/routes.js'

const props = defineProps({
    // Aufbereitete Zeilen aus hr.performance.tab.vue (checkRows)
    rows: { type: Array, default: () => [] },
    // Netto-Arbeitszeit je Tag in Minuten
    threshold: { type: Number, default: 480 },
    showMechanic: { type: Boolean, default: false }
})
defineEmits(['select'])

const { t, n, locale } = useI18n()

const LIMIT = 12
const KINDS = ['day', 'timer', 'high', 'low']
const KIND_COLOR = { day: 'error', timer: 'error', high: 'warning', low: 'info' }

const orderRoute = id => entityRoute('order', id)

const filter = ref('all')
const expanded = ref(false)

const counts = computed(() => {
    const c = { day: 0, timer: 0, high: 0, low: 0 }
    props.rows.forEach(r => { c[r.kind]++ })
    return c
})
const kindsPresent = computed(() => KINDS.filter(k => counts.value[k] > 0))
const filtered = computed(() => filter.value === 'all' ? props.rows : props.rows.filter(r => r.kind === filter.value))
const visibleRows = computed(() => expanded.value ? filtered.value : filtered.value.slice(0, LIMIT))

// Neuer Datensatz (Zeitraum/Mechaniker gewechselt) → Filter zurücksetzen
watch(() => props.rows, () => {
    if (filter.value !== 'all' && !counts.value[filter.value]) filter.value = 'all'
    expanded.value = false
})

function fmtHours(min) {
    return n((Number(min) || 0) / 60, { maximumFractionDigits: 1 }) + ' h'
}
function fmtDuration(min) {
    const v = Number(min) || 0
    return v >= 90 ? fmtHours(v) : n(v, { maximumFractionDigits: 0 }) + ' min'
}
function fmtDay(s) {
    return new Date(s + 'T12:00:00').toLocaleDateString(locale.value, { weekday: 'short', day: '2-digit', month: '2-digit' })
}
</script>

<style scoped>
.checks__filter { padding: var(--oserp-gap-sm) var(--oserp-gap); }
.checks__table { overflow-x: auto; }
.checks__table th { white-space: nowrap; }
.checks__row--day td { background: rgba(var(--v-theme-error), 0.04); }
.checks__nowrap { white-space: nowrap; }
.checks__num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.checks__what { max-width: 0; width: 100%; min-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.checks__link { color: rgb(var(--v-theme-primary)); text-decoration: none; background: none; border: 0; padding: 0; font: inherit; cursor: pointer; }
.checks__link:hover { text-decoration: underline; }
</style>
