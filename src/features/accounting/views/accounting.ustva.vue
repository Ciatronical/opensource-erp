<!-- src/features/accounting/views/accounting.ustva.vue -->
<!--
    UStVA-Cockpit.

    Kein Formularnachbau: Ausgangspunkt ist der Zeitstrahl des Jahres, in dem
    jeder Zeitraum seinen Status und seine Zahllast zeigt. Darunter steht die
    eine Zahl, die zaehlt, danach die Kennzahlen — und jede Kennzahl laesst sich
    bis auf die einzelne Buchung aufklappen. Was sich nicht zuordnen laesst,
    wird sichtbar gemacht statt weggelassen.
-->
<template>
    <NavbarView />
    <v-container fluid class="pt-3">

        <AccountingPageHeader :title="t('AccountingView.ustva.title')" />

        <!-- Kopf -->
        <div class="d-flex align-center flex-wrap ga-2 mb-3">
            <v-spacer />
            <v-btn-toggle v-model="granularity" mandatory density="compact" variant="outlined" divided>
                <v-btn value="month" size="small">{{ t('AccountingView.ustva.monthly') }}</v-btn>
                <v-btn value="quarter" size="small">{{ t('AccountingView.ustva.quarterly') }}</v-btn>
            </v-btn-toggle>
            <v-btn icon variant="text" size="small" @click="changeYear(-1)"><v-icon>mdi-chevron-left</v-icon></v-btn>
            <span class="text-subtitle-1 font-weight-bold">{{ year }}</span>
            <v-btn icon variant="text" size="small" @click="changeYear(1)"><v-icon>mdi-chevron-right</v-icon></v-btn>
        </div>

        <!-- ── Zeitstrahl ────────────────────────────────────────────────── -->
        <div class="timeline mb-4">
            <button
                v-for="p in timeline" :key="p.key"
                class="timeline__item"
                :class="[`timeline__item--${p.status}`, { 'timeline__item--active': p.key === period }]"
                @click="period = p.key"
            >
                <span class="timeline__label">{{ p.short }}</span>
                <span class="timeline__value">{{ p.status === 'future' && !p.payable ? '—' : shortMoney(p.payable) }}</span>
                <v-icon v-if="p.status === 'filed'" size="12" class="timeline__flag">mdi-check-circle</v-icon>
                <v-icon v-else-if="p.status === 'overdue'" size="12" class="timeline__flag">mdi-alert-circle</v-icon>
                <v-icon v-else-if="p.status === 'current'" size="12" class="timeline__flag">mdi-progress-clock</v-icon>
            </button>
        </div>

        <v-row>
            <!-- ── Ergebnis + Prüfungen ──────────────────────────────────── -->
            <v-col cols="12" md="4">
                <v-card :color="payable >= 0 ? 'primary' : 'success'" variant="flat" rounded="lg" class="mb-3">
                    <v-card-text>
                        <div class="text-caption">
                            {{ payable >= 0 ? t('AccountingView.ustva.payable') : t('AccountingView.ustva.refund') }}
                            · {{ data.period?.label }}
                        </div>
                        <div class="text-h4 font-weight-bold my-1">{{ money(Math.abs(payable)) }}</div>
                        <div class="text-caption d-flex align-center flex-wrap ga-2">
                            <span>{{ t('AccountingView.ustva.dueOn', { date: d(data.period?.due_date) }) }}</span>
                            <v-chip v-if="dueInfo" size="x-small" variant="flat" :color="dueInfo.color">
                                {{ dueInfo.text }}
                            </v-chip>
                            <!-- Ein laufender Zeitraum aendert sich noch — das gehoert
                                 sichtbar an die Zahl, nicht in eine Fussnote. -->
                            <v-chip v-if="periodStatus === 'current'" size="x-small" variant="flat" color="info">
                                {{ t('AccountingView.ustva.running') }}
                            </v-chip>
                        </div>
                        <v-divider class="my-3" style="opacity:.3" />
                        <div class="d-flex justify-space-between text-body-2">
                            <span>{{ t('AccountingView.ustva.vatOut') }}</span>
                            <span class="font-weight-medium">{{ money(data.totals?.vat_out) }}</span>
                        </div>
                        <div class="d-flex justify-space-between text-body-2">
                            <span>{{ t('AccountingView.ustva.vatIn') }}</span>
                            <span class="font-weight-medium">− {{ money(data.totals?.vat_in) }}</span>
                        </div>
                    </v-card-text>
                </v-card>

                <!-- Besteuerungsart -->
                <v-card variant="outlined" rounded="lg" class="mb-3">
                    <v-card-text class="py-3">
                        <div class="text-caption text-medium-emphasis mb-1">{{ t('AccountingView.ustva.method') }}</div>
                        <v-btn-toggle v-model="method" mandatory density="compact" variant="outlined" divided class="w-100">
                            <v-btn value="accrual" size="small" class="flex-grow-1">{{ t('AccountingView.ustva.accrual') }}</v-btn>
                            <v-btn value="cash" size="small" class="flex-grow-1">{{ t('AccountingView.ustva.cash') }}</v-btn>
                        </v-btn-toggle>
                        <div class="text-caption text-medium-emphasis mt-2">
                            {{ method === 'cash' ? t('AccountingView.ustva.cashHint') : t('AccountingView.ustva.accrualHint') }}
                        </div>
                        <div v-if="method !== defaultMethod" class="text-caption text-warning mt-1">
                            {{ t('AccountingView.ustva.methodOverride', { method: t(`AccountingView.ustva.${defaultMethod}`) }) }}
                        </div>
                    </v-card-text>
                </v-card>

                <!-- Prüfungen -->
                <v-card variant="outlined" rounded="lg" class="mb-3">
                    <v-card-title class="text-subtitle-2 font-weight-bold pb-1">
                        <v-icon size="small" class="mr-1">mdi-shield-check-outline</v-icon>
                        {{ t('AccountingView.ustva.checks.title') }}
                    </v-card-title>
                    <v-list density="compact">
                        <v-list-item v-for="c in checks" :key="c.key" :class="c.ok ? '' : 'cursor-pointer'"
                                     @click="c.action && c.action()">
                            <template #prepend>
                                <v-icon size="small" :color="c.ok ? 'success' : c.color">
                                    {{ c.ok ? 'mdi-check-circle' : 'mdi-alert-circle' }}
                                </v-icon>
                            </template>
                            <v-list-item-title class="text-body-2">{{ c.title }}</v-list-item-title>
                            <v-list-item-subtitle v-if="c.subtitle" class="text-caption">{{ c.subtitle }}</v-list-item-subtitle>
                        </v-list-item>
                    </v-list>
                </v-card>

                <!-- Aktionen -->
                <div class="d-flex flex-column ga-2">
                    <v-btn v-if="!data.filing" color="primary" variant="flat" @click="fileIt">
                        <v-icon start>mdi-send-check</v-icon>{{ t('AccountingView.ustva.markFiled') }}
                    </v-btn>
                    <v-alert v-else type="success" variant="tonal" density="compact">
                        <div class="text-body-2">{{ t('AccountingView.ustva.filedOn', { date: dt(data.filing.filed_at) }) }}</div>
                        <v-btn size="x-small" variant="text" class="mt-1 px-0" @click="reopenIt">
                            {{ t('AccountingView.ustva.reopen') }}
                        </v-btn>
                    </v-alert>
                    <v-btn variant="tonal" @click="exportIt">
                        <v-icon start>mdi-file-delimited-outline</v-icon>{{ t('AccountingView.ustva.exportCsv') }}
                    </v-btn>
                    <v-btn variant="text" size="small" @click="mappingOpen = true">
                        <v-icon start size="small">mdi-tune</v-icon>{{ t('AccountingView.ustva.mapping.open') }}
                    </v-btn>
                </div>

                <v-alert type="info" variant="tonal" density="compact" class="mt-3"
                         :text="t('AccountingView.ustva.noElsterHint')" />
            </v-col>

            <!-- ── Kennzahlen ────────────────────────────────────────────── -->
            <v-col cols="12" md="8">
                <div class="d-flex align-center mb-2">
                    <v-switch v-model="showEmpty" color="primary" density="compact" hide-details inset
                              :label="t('AccountingView.ustva.showEmpty')" />
                    <v-spacer />
                    <span class="text-caption text-medium-emphasis">{{ t('AccountingView.ustva.clickHint') }}</span>
                </div>

                <!-- Nicht zugeordnet -->
                <v-card v-if="data.unmapped?.length" variant="tonal" color="warning" rounded="lg" class="mb-3">
                    <v-card-title class="text-subtitle-2 font-weight-bold pb-1">
                        <v-icon size="small" class="mr-1">mdi-help-circle-outline</v-icon>
                        {{ t('AccountingView.ustva.unmapped.title') }}
                    </v-card-title>
                    <v-card-text class="pt-0">
                        <div class="text-caption mb-2">{{ t('AccountingView.ustva.unmapped.text') }}</div>
                        <v-table density="compact" class="bg-transparent">
                            <tbody>
                                <tr v-for="(u, i) in data.unmapped" :key="i">
                                    <td class="text-caption">{{ u.accno }} · {{ u.chart_name }}</td>
                                    <td class="text-caption">
                                        {{ t('AccountingView.ustva.unmapped.taxkey', { taxkey: u.taxkey ?? '—', rate: pct(u.rate) }) }}
                                    </td>
                                    <td class="text-caption text-right font-weight-bold">{{ money(u.amount) }}</td>
                                    <td class="text-right" style="width: 130px;">
                                        <v-btn size="x-small" variant="flat" color="warning" @click="assign(u)">
                                            {{ t('AccountingView.ustva.unmapped.assign') }}
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-card-text>
                </v-card>

                <!-- Bewusst nicht gemeldet (durchlaufende Posten) -->
                <v-card v-if="data.excluded?.length" variant="outlined" rounded="lg" class="mb-3">
                    <v-card-title class="text-subtitle-2 font-weight-bold py-2 d-flex align-center">
                        <v-icon size="small" class="mr-1">mdi-eye-off-outline</v-icon>
                        {{ t('AccountingView.ustva.excluded.title') }}
                        <v-spacer />
                        <span class="text-body-2 font-weight-bold">{{ money(excludedTotal) }}</span>
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="py-2">
                        <div class="text-caption text-medium-emphasis mb-2">{{ t('AccountingView.ustva.excluded.text') }}</div>
                        <div v-for="r in data.excluded" :key="r.kz" class="kz-row d-flex align-center py-1"
                             @click="openDetails(r)">
                            <span class="text-body-2 flex-grow-1">{{ r.label }}</span>
                            <span class="text-caption text-medium-emphasis mr-3">
                                {{ t('AccountingView.ustva.excluded.entries', { count: r.entries }) }}
                            </span>
                            <span class="text-body-2 font-weight-medium">{{ money(r.amount) }}</span>
                        </div>
                    </v-card-text>
                </v-card>

                <!-- Kennzahlen-Gruppen -->
                <v-card v-for="g in groups" :key="g.section" variant="outlined" rounded="lg" class="mb-3">
                    <v-card-title class="text-subtitle-2 font-weight-bold py-2">
                        {{ t(`AccountingView.ustva.sections.${g.section}`) }}
                    </v-card-title>
                    <v-divider />
                    <v-table density="compact">
                        <tbody>
                            <tr v-for="r in g.rows" :key="r.kz"
                                class="kz-row" :class="{ 'kz-row--zero': !Number(r.reported) }"
                                @click="openDetails(r)">
                                <td style="width: 62px;">
                                    <v-chip size="x-small" variant="flat"
                                            :color="Number(r.reported) ? 'primary' : 'grey-lighten-1'">{{ r.kz }}</v-chip>
                                </td>
                                <td>
                                    <div class="text-body-2">{{ r.label }}</div>
                                    <div v-if="taxMismatch(r)" class="text-caption text-error">
                                        {{ t('AccountingView.ustva.taxMismatch', {
                                            computed: money(r.computed_tax), booked: money(r.booked_tax) }) }}
                                    </div>
                                </td>
                                <td class="text-right kz-amount">
                                    <span class="font-weight-bold">{{ money(r.reported) }}</span>
                                    <!-- Steuer nur bei Kennzahlen mit echtem Steuersatz —
                                         "Steuer 0,00 €" unter einem steuerfreien Umsatz ist Rauschen. -->
                                    <div v-if="Number(r.rate)" class="text-caption text-medium-emphasis">
                                        {{ t('AccountingView.ustva.taxOf', { tax: money(r.computed_tax) }) }}
                                    </div>
                                </td>
                                <td style="width: 40px;">
                                    <v-btn icon variant="text" size="x-small" :title="t('AccountingView.ustva.copy')"
                                           @click.stop="copy(r)">
                                        <v-icon size="small">mdi-content-copy</v-icon>
                                    </v-btn>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </v-col>
        </v-row>

        <!-- ── Drilldown ─────────────────────────────────────────────────── -->
        <v-dialog v-model="detailsOpen" max-width="960" scrollable>
            <v-card rounded="lg">
                <v-card-title class="d-flex align-center">
                    <v-chip size="small" color="primary" variant="flat" class="mr-2">{{ detailsKz?.kz ?? '—' }}</v-chip>
                    <span class="text-subtitle-2 font-weight-bold">{{ detailsKz?.label }}</span>
                    <v-spacer />
                    <div class="text-right">
                        <div v-if="Number(details.base_total)" class="text-subtitle-1 font-weight-bold">
                            {{ money(details.base_total) }}
                            <span class="text-caption text-medium-emphasis">{{ t('AccountingView.ustva.details.base') }}</span>
                        </div>
                        <div v-if="Number(details.tax_total)" class="text-body-2">
                            {{ money(details.tax_total) }}
                            <span class="text-caption text-medium-emphasis">{{ t('AccountingView.ustva.details.tax') }}</span>
                        </div>
                    </div>
                </v-card-title>
                <v-divider />
                <v-card-text>
                    <v-data-table
                        :headers="detailHeaders"
                        :items="details.items || []"
                        :loading="loading"
                        density="compact"
                        :items-per-page="25"
                        :no-data-text="t('AccountingView.ustva.details.empty')"
                    >
                        <template #item.transdate="{ item }">{{ d(item.transdate) }}</template>
                        <template #item.chart="{ item }">
                            <v-chip size="x-small" variant="tonal" class="mr-2"
                                    :color="item.role === 'tax' ? 'secondary' : 'primary'">
                                {{ t(`AccountingView.ustva.mapping.role_${item.role}`) }}
                            </v-chip>
                            {{ item.accno }} · {{ item.chart_name }}
                        </template>
                        <template #item.taxkey="{ item }">{{ item.taxkey ?? '—' }} / {{ pct(item.rate) }}</template>
                        <template #item.signed="{ item }">
                            <span class="font-weight-medium">{{ money(item.signed) }}</span>
                        </template>
                        <template #item.paid="{ item }">
                            <span v-if="item.paid !== null" class="text-caption">
                                {{ money(item.paid) }} / {{ money(item.gross) }}
                            </span>
                            <span v-else class="text-disabled">—</span>
                        </template>
                    </v-data-table>
                </v-card-text>
                <v-divider />
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="detailsOpen = false">{{ t('AccountingView.ustva.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Zuordnung ─────────────────────────────────────────────────── -->
        <v-dialog v-model="mappingOpen" max-width="1000" scrollable>
            <v-card rounded="lg">
                <v-card-title class="text-subtitle-1 font-weight-bold">
                    {{ t('AccountingView.ustva.mapping.title') }}
                </v-card-title>
                <v-card-subtitle class="pb-2">{{ t('AccountingView.ustva.mapping.text') }}</v-card-subtitle>
                <v-divider />
                <v-card-text>
                    <v-data-table
                        :headers="mappingHeaders"
                        :items="mapping.mapping || []"
                        density="compact"
                        :items-per-page="25"
                    >
                        <template #item.direction="{ item }">
                            {{ t(`AccountingView.ustva.mapping.${item.direction}`) }}
                        </template>
                        <template #item.role="{ item }">
                            {{ t(`AccountingView.ustva.mapping.role_${item.role}`) }}
                        </template>
                        <template #item.rate="{ item }">{{ pct(item.rate) }}</template>
                        <template #item.kz="{ item }">
                            <v-chip size="x-small" color="primary" variant="flat">{{ item.kz }}</v-chip>
                            <span class="text-caption ml-2">{{ item.kz_label }}</span>
                        </template>
                        <template #item.actions="{ item }">
                            <v-btn icon variant="text" size="x-small" @click="removeMapping(item)">
                                <v-icon size="small">mdi-delete-outline</v-icon>
                            </v-btn>
                        </template>
                    </v-data-table>
                </v-card-text>
                <v-divider />
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="mappingOpen = false">{{ t('AccountingView.ustva.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useUstva } from '../composables/useUstva.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import { formatDate, formatDateTime } from '@/core/utils/dateFormatter.js'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'
import Swal from 'sweetalert2'

const { t, locale } = useI18n()
const api = useUstva()
const loading = computed(() => api.loading.value)

const year = ref(new Date().getFullYear())
const period = ref(String(new Date().getMonth() + 1))
const granularity = ref('month')
const method = ref(null)
const defaultMethod = ref('accrual')
const showEmpty = ref(false)

const yearData = ref({ months: [], quarters: [] })
const data = ref({ rows: [], unmapped: [], totals: {}, period: {} })
const details = ref({ items: [], total: 0 })
const detailsKz = ref(null)
const detailsOpen = ref(false)
const mapping = ref({ mapping: [], kennzahlen: [] })
const mappingOpen = ref(false)

const payable = computed(() => Number(data.value.totals?.payable ?? 0))

const timeline = computed(() => {
    const src = granularity.value === 'month' ? (yearData.value.months || []) : (yearData.value.quarters || [])
    return src.map(p => ({
        key: granularity.value === 'month' ? String(p.month) : `q${p.quarter}`,
        short: granularity.value === 'month'
            ? new Date(year.value, p.month - 1, 1).toLocaleDateString(locale.value, { month: 'short' })
            : `Q${p.quarter}`,
        payable: p.payable,
        status: p.status,
    }))
})

const SECTION_ORDER = ['umsatz', 'umsatz_frei', 'erwerb', 'reverse', 'steuer', 'vorsteuer', 'ergebnis']

const groups = computed(() => {
    const rows = data.value.rows || []
    return SECTION_ORDER
        .map(section => ({
            section,
            rows: rows.filter(r => r.section === section && (showEmpty.value || Number(r.reported) !== 0 || Number(r.booked_tax) !== 0)),
        }))
        .filter(g => g.rows.length)
})

const periodStatus = computed(() => timeline.value.find(p => p.key === period.value)?.status ?? null)

const excludedTotal = computed(() =>
    (data.value.excluded || []).reduce((s, r) => s + Number(r.amount || 0), 0))

const dueInfo = computed(() => {
    const due = data.value.period?.due_date
    if (!due) return null
    if (data.value.filing) return null
    const days = Math.ceil((new Date(due) - new Date()) / 86400000)
    if (days < 0)  return { color: 'error',   text: t('AccountingView.ustva.overdueBy', { days: Math.abs(days) }) }
    if (days <= 7) return { color: 'warning', text: t('AccountingView.ustva.dueIn', { days }) }
    return null
})

const checks = computed(() => {
    const out = []
    const unmappedSum = (data.value.unmapped || []).reduce((s, u) => s + Math.abs(Number(u.amount)), 0)

    out.push({
        key: 'unmapped',
        ok: !unmappedSum,
        color: 'warning',
        title: unmappedSum
            ? t('AccountingView.ustva.checks.unmapped', { amount: money(unmappedSum) })
            : t('AccountingView.ustva.checks.unmappedOk'),
    })

    const mismatch = (data.value.rows || []).filter(taxMismatch)
    out.push({
        key: 'tax',
        ok: !mismatch.length,
        color: 'error',
        title: mismatch.length
            ? t('AccountingView.ustva.checks.taxMismatch', { count: mismatch.length })
            : t('AccountingView.ustva.checks.taxOk'),
        subtitle: mismatch.length ? mismatch.map(r => `Kz ${r.kz}`).join(', ') : '',
    })

    const noKey = data.value.no_taxkey || {}
    out.push({
        key: 'nokey',
        ok: !Number(noKey.entries),
        color: 'warning',
        title: Number(noKey.entries)
            ? t('AccountingView.ustva.checks.noTaxkey', { count: noKey.entries, amount: money(noKey.amount) })
            : t('AccountingView.ustva.checks.noTaxkeyOk'),
    })

    if (data.value.filing) {
        out.push({
            key: 'late',
            ok: !data.value.late_bookings,
            color: 'error',
            title: data.value.late_bookings
                ? t('AccountingView.ustva.checks.lateBookings', { count: data.value.late_bookings })
                : t('AccountingView.ustva.checks.lateOk'),
        })
    }
    return out
})

const detailHeaders = computed(() => [
    { title: t('AccountingView.ustva.details.date'),    key: 'transdate', width: '110px' },
    { title: t('AccountingView.ustva.details.doc'),     key: 'reference', width: '140px' },
    { title: t('AccountingView.ustva.details.account'), key: 'chart' },
    { title: t('AccountingView.ustva.details.taxkey'),  key: 'taxkey',    width: '110px' },
    { title: t('AccountingView.ustva.details.amount'),  key: 'signed',    width: '130px', align: 'end' },
    { title: t('AccountingView.ustva.details.paid'),    key: 'paid',      width: '170px', align: 'end' },
])

const mappingHeaders = computed(() => [
    { title: t('AccountingView.ustva.mapping.dir'),     key: 'direction', width: '110px' },
    { title: t('AccountingView.ustva.mapping.roleCol'), key: 'role',      width: '150px' },
    { title: t('AccountingView.ustva.mapping.taxkey'),  key: 'taxkey',    width: '90px' },
    { title: t('AccountingView.ustva.mapping.rate'),    key: 'rate',      width: '90px' },
    { title: t('AccountingView.ustva.mapping.account'), key: 'accno',     width: '90px' },
    { title: t('AccountingView.ustva.mapping.kz'),      key: 'kz' },
    { title: '',                                         key: 'actions',   width: '50px', sortable: false },
])

function money(v) { return formatNumber(Number(v ?? 0), locale.value, 2) + ' €' }
function shortMoney(v) {
    const n = Number(v ?? 0)
    if (!n) return '0'
    if (Math.abs(n) >= 1000) return formatNumber(n / 1000, locale.value, 1) + 'k'
    return formatNumber(n, locale.value, 0)
}
function pct(v) { return v === null || v === undefined ? '—' : formatNumber(Number(v) * 100, locale.value, 0) + ' %' }
function d(v)   { return v ? formatDate(v, locale.value) : '' }
function dt(v)  { return v ? formatDateTime(v, locale.value) : '' }

// Toleranz für den Abgleich gerechnete vs. gebuchte Steuer.
// Die Rundung pro Beleg (positionsweise Steuerberechnung, Euro-Kürzung der
// Bemessungsgrundlage) hebt sich weitgehend auf und wächst NICHT linear mit der
// Belegzahl, sondern nur mit deren Wurzel — empirisch bleibt der Rest selbst bei
// >140 Belegen unter ~0,25 €. 0,50 € schluckt diese normale Rundung auf jeder
// Betriebsgröße, schlägt aber bei echten Fehlbuchungen (€-groß) sofort an.
const TAX_MISMATCH_TOLERANCE = 0.50

/** Weicht die aus der Bemessungsgrundlage gerechnete Steuer von der gebuchten ab? */
function taxMismatch(r) {
    if (r.computed_tax === null || r.computed_tax === undefined) return false
    if (!Number(r.booked_tax)) return false
    return Math.abs(Number(r.computed_tax) - Number(r.booked_tax)) > TAX_MISMATCH_TOLERANCE
}

async function loadYear() {
    yearData.value = await api.fetchYear(year.value, method.value)
    if (!method.value) {
        method.value = yearData.value.method
        defaultMethod.value = yearData.value.method
    }
}

async function loadPeriod() {
    data.value = await api.fetchPeriod(year.value, period.value, method.value)
}

onMounted(async () => {
    await loadYear()
    await loadPeriod()
})

watch([year, method], async () => { await loadYear(); await loadPeriod() })
watch(period, loadPeriod)
watch(granularity, () => {
    period.value = granularity.value === 'month' ? String(new Date().getMonth() + 1) : 'q1'
})

function changeYear(delta) { year.value += delta }

async function openDetails(r) {
    detailsKz.value = r
    detailsOpen.value = true
    details.value = await api.fetchDetails(year.value, period.value, r.kz, method.value)
}

function copy(r) {
    navigator.clipboard?.writeText(String(Number(r.reported).toFixed(2)).replace('.', ','))
    toasts.success(t('AccountingView.ustva.copied', { kz: r.kz }))
}

async function fileIt() {
    const ok = await alerts.question(t('AccountingView.ustva.fileConfirm', { period: data.value.period?.label }))
    if (!ok.isConfirmed) return
    await api.file({
        year: year.value,
        period: period.value,
        method: method.value,
        vat_payable: payable.value,
        payload: { rows: data.value.rows, totals: data.value.totals },
    })
    await Promise.all([loadYear(), loadPeriod()])
    alerts.success(t('AccountingView.ustva.filed'))
}

async function reopenIt() {
    const ok = await alerts.warning(t('AccountingView.ustva.reopenConfirm'))
    if (!ok.isConfirmed) return
    await api.reopen(year.value, period.value)
    await Promise.all([loadYear(), loadPeriod()])
}

async function exportIt() {
    await api.exportCsv(year.value, period.value, method.value)
}

/** Nicht zugeordneten Posten einer Kennzahl zuweisen — ein Klick, ein Dialog. */
async function assign(u) {
    if (!mapping.value.kennzahlen?.length) mapping.value = await api.fetchMapping()
    const opts = {}
    // "Nicht melden" zuerst: bei nicht zugeordneten Betraegen ist das genauso oft
    // die richtige Antwort wie eine Formularzeile (z. B. durchlaufende Posten).
    const kzList = [...mapping.value.kennzahlen].sort((a, b) => (a.kz === 0 ? -1 : b.kz === 0 ? 1 : 0))
    for (const k of kzList) opts[k.kz] = k.kz === 0 ? k.label : `${k.kz} — ${k.label}`

    const { value: kz } = await Swal.fire({
        title: t('AccountingView.ustva.unmapped.assignTitle'),
        text: `${u.accno} · ${u.chart_name}`,
        input: 'select',
        inputOptions: opts,
        inputPlaceholder: t('AccountingView.ustva.unmapped.choose'),
        showCancelButton: true,
        confirmButtonText: t('AccountingView.ustva.unmapped.assign'),
        cancelButtonText: t('AccountingView.ustva.close'),
    })
    if (!kz) return

    await api.saveMapping({
        taxkey: u.taxkey,
        rate: u.rate,
        // Ohne Steuerschluessel gibt es nichts, woran man die Zuordnung sonst
        // festmachen koennte — dann gilt sie fuer genau dieses Konto.
        chart_id: Number(u.taxkey) ? null : u.chart_id,
        role: u.role,
        direction: u.direction,
        kz: Number(kz),
        description: `${u.accno} ${u.chart_name}`,
    })
    await Promise.all([loadYear(), loadPeriod()])
    toasts.success(t('AccountingView.ustva.unmapped.assigned'))
}

watch(mappingOpen, async (v) => { if (v) mapping.value = await api.fetchMapping() })

async function removeMapping(item) {
    await api.deleteMapping(item.id)
    mapping.value = await api.fetchMapping()
    await loadPeriod()
}
</script>

<style scoped>
.timeline {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding-bottom: 4px;
}
.timeline__item {
    flex: 1 1 0;
    min-width: 72px;
    border-radius: 10px;
    padding: 8px 6px;
    border: 1px solid rgba(var(--v-border-color), .3);
    background: rgb(var(--v-theme-surface));
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    position: relative;
    cursor: pointer;
    transition: transform .12s ease, box-shadow .12s ease;
}
.timeline__item:hover { transform: translateY(-1px); }
.timeline__label { font-size: .75rem; text-transform: uppercase; opacity: .7; }
.timeline__value { font-size: .95rem; font-weight: 700; }
.timeline__flag  { position: absolute; top: 4px; right: 4px; }
.timeline__item--filed   { border-color: rgb(var(--v-theme-success)); }
.timeline__item--overdue { border-color: rgb(var(--v-theme-error)); }
.timeline__item--current { border-color: rgb(var(--v-theme-info)); border-style: dashed; }
.timeline__item--future  { opacity: .45; }
.timeline__item--active  {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: 1px;
}
/* Betragsspalte: breit genug fuer sechsstellige Summen, damit das
   Waehrungszeichen nicht in die naechste Zeile rutscht. */
.kz-amount {
    width: 180px;
    white-space: nowrap;
}
.kz-row { cursor: pointer; }
.kz-row:hover { background: rgba(var(--v-theme-primary), .06); }
.kz-row--zero { opacity: .55; }
.cursor-pointer { cursor: pointer; }
</style>
