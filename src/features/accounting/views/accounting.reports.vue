<!-- src/features/accounting/views/accounting.reports.vue -->
<!--
    Berichte der Buchhaltung.

    Die Summen- und Saldenliste ist der Bericht, nach dem tatsächlich gefragt
    wird: alle Konten und das, was darauf gebucht wurde — in einer Zeile je
    Konto, mit Anfangssaldo, Soll, Haben und Endsaldo. Ein Klick auf eine Zeile
    führt ins Kontoblatt mit den einzelnen Buchungen.

    Soll und Haben der Bewegungen müssen sich decken. Tun sie es nicht, steht
    die Abweichung ganz oben — eine unausgeglichene Buchhaltung ist kein
    Schönheitsfehler, den man unter einer Tabelle verstecken darf.
-->
<template>
    <NavbarView />

    <v-container fluid>
        <AccountingPageHeader :title="t('AccountingView.reports.title')">
            <template #actions>
                <v-btn variant="tonal" size="small" class="text-none" :loading="printing" @click="print">
                    <v-icon start size="small">mdi-printer-outline</v-icon>
                    {{ t('AccountingView.reports.print') }}
                </v-btn>
            </template>
        </AccountingPageHeader>

        <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline"
                 class="mb-3" :text="t('AccountingView.reports.info')" />

        <!-- Zeitraum -->
        <v-row dense class="mb-1">
            <v-col cols="12" md="auto">
                <v-btn-toggle v-model="period" mandatory density="compact" variant="outlined" rounded="lg">
                    <v-btn value="year" size="small">{{ t('AccountingView.reports.periodYear') }}</v-btn>
                    <v-btn value="quarter" size="small">{{ t('AccountingView.reports.periodQuarter') }}</v-btn>
                    <v-btn value="month" size="small">{{ t('AccountingView.reports.periodMonth') }}</v-btn>
                    <v-btn value="custom" size="small">{{ t('AccountingView.reports.periodCustom') }}</v-btn>
                </v-btn-toggle>
            </v-col>
            <v-col v-if="period !== 'custom'" cols="12" md="auto" class="d-flex align-center">
                <v-btn icon="mdi-chevron-left" variant="text" size="small"
                       :aria-label="t('AccountingView.reports.previous')" @click="shift(-1)" />
                <div class="text-subtitle-1 font-weight-medium text-center" style="min-width:180px">
                    {{ periodLabel }}
                </div>
                <v-btn icon="mdi-chevron-right" variant="text" size="small"
                       :aria-label="t('AccountingView.reports.next')" @click="shift(1)" />
            </v-col>
            <template v-else>
                <v-col cols="6" md="2">
                    <v-text-field v-model="fromDate" type="date" :label="t('AccountingView.reports.fromDate')"
                                  density="compact" variant="outlined" hide-details />
                </v-col>
                <v-col cols="6" md="2">
                    <v-text-field v-model="toDate" type="date" :label="t('AccountingView.reports.toDate')"
                                  density="compact" variant="outlined" hide-details />
                </v-col>
            </template>
            <v-spacer />
            <v-col cols="12" md="3">
                <v-text-field v-model="search" :placeholder="t('AccountingView.reports.search')"
                              prepend-inner-icon="mdi-magnify" density="compact" variant="outlined"
                              hide-details clearable />
            </v-col>
        </v-row>

        <v-row dense class="mb-2">
            <v-col cols="12" class="d-flex align-center flex-wrap ga-4">
                <v-switch v-model="showAll" :label="t('AccountingView.reports.showAll')"
                          density="compact" color="primary" hide-details class="flex-grow-0" />
                <v-chip v-for="group in groupFilters" :key="group.key" size="small"
                        :variant="categories.includes(group.key) ? 'flat' : 'outlined'"
                        :color="categories.includes(group.key) ? 'primary' : undefined"
                        @click="toggleCategory(group.key)">
                    {{ group.label }}
                </v-chip>
            </v-col>
        </v-row>

        <!-- Kopfzahlen: die Probe, ob die Buchhaltung ausgeglichen ist -->
        <v-card variant="outlined" class="mb-3">
            <div class="figures">
                <div class="figures__cell">
                    <div class="figures__label">{{ t('AccountingView.reports.accounts') }}</div>
                    <div class="figures__value">{{ rows.length }}</div>
                </div>
                <div class="figures__cell">
                    <div class="figures__label">{{ t('AccountingView.reports.sumSoll') }}</div>
                    <div class="figures__value">{{ money(balance?.sum_soll) }}</div>
                </div>
                <div class="figures__cell">
                    <div class="figures__label">{{ t('AccountingView.reports.sumHaben') }}</div>
                    <div class="figures__value">{{ money(balance?.sum_haben) }}</div>
                </div>
                <div class="figures__cell" :class="{ 'figures__cell--bad': !balanced }">
                    <div class="figures__label">{{ t('AccountingView.reports.difference') }}</div>
                    <div class="figures__value" :class="balanced ? 'text-success' : 'text-error'">
                        {{ money(difference) }}
                    </div>
                    <div class="figures__sub">
                        {{ balanced ? t('AccountingView.reports.balanced') : t('AccountingView.reports.unbalanced') }}
                    </div>
                </div>
            </div>
        </v-card>

        <v-data-table
            :headers="headers"
            :items="rows"
            :loading="loading"
            :items-per-page="100"
            density="compact"
            hover
            class="reports-table"
            :no-data-text="t('AccountingView.reports.noRows')"
            @click:row="(_, { item }) => openLedger(item)">
            <template #[`item.accno`]="{ item }">
                <span class="font-weight-medium">{{ item.accno }}</span>
            </template>
            <template #[`item.category`]="{ item }">
                <span class="text-caption text-medium-emphasis">{{ categoryLabel(item.category) }}</span>
            </template>
            <template #[`item.opening`]="{ item }">{{ money(item.opening, true) }}</template>
            <template #[`item.soll`]="{ item }">{{ money(item.soll, true) }}</template>
            <template #[`item.haben`]="{ item }">{{ money(item.haben, true) }}</template>
            <template #[`item.closing`]="{ item }">
                <span class="font-weight-medium" :class="Number(item.closing) < 0 ? 'text-error' : ''">
                    {{ money(item.closing) }}
                </span>
            </template>
        </v-data-table>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useReports } from '../composables/useReports.js'

const { t, locale } = useI18n()
const router = useRouter()
const { loading, printing, balance, fetchTrialBalance, printTrialBalance } = useReports()

const today = new Date()
const period     = ref('year')
const year       = ref(today.getFullYear())
const month      = ref(today.getMonth())      // 0-basiert
const quarter    = ref(Math.floor(today.getMonth() / 3))
const fromDate   = ref(`${today.getFullYear()}-01-01`)
const toDate     = ref(iso(today))
const search     = ref('')
const showAll    = ref(false)
const categories = ref([])

// Die Gruppen des Kontenrahmens — als Filter, damit „nur Aufwand" ein Klick ist.
const groupFilters = computed(() => ['A', 'L', 'Q', 'E', 'I', 'C'].map(key => ({
    key, label: t('AccountingView.chartOfAccounts.categories.' + key)
})))

const headers = computed(() => [
    { title: t('AccountingView.reports.account'),  key: 'accno',       width: '90px' },
    { title: t('AccountingView.reports.name'),     key: 'description' },
    { title: t('AccountingView.reports.category'), key: 'category',    width: '150px' },
    { title: t('AccountingView.reports.opening'),  key: 'opening',  align: 'end', width: '130px' },
    { title: t('AccountingView.reports.soll'),     key: 'soll',     align: 'end', width: '130px' },
    { title: t('AccountingView.reports.haben'),    key: 'haben',    align: 'end', width: '130px' },
    { title: t('AccountingView.reports.closing'),  key: 'closing',  align: 'end', width: '140px' }
])

const rows = computed(() => {
    const query = (search.value || '').trim().toLowerCase()
    return (balance.value?.accounts || []).filter(row => {
        if (categories.value.length && !categories.value.includes(row.category)) return false
        if (!query) return true
        return `${row.accno} ${row.description}`.toLowerCase().includes(query)
    })
})

const difference = computed(() =>
    Math.round(((balance.value?.sum_soll || 0) - (balance.value?.sum_haben || 0)) * 100) / 100
)
const balanced = computed(() => Math.abs(difference.value) < 0.005)

const periodLabel = computed(() => {
    if (period.value === 'year')    return String(year.value)
    if (period.value === 'quarter') return `${quarter.value + 1}. ${t('AccountingView.reports.quarter')} ${year.value}`
    return new Date(year.value, month.value, 1)
        .toLocaleDateString(locale.value, { month: 'long', year: 'numeric' })
})

// Zeitraum → Datumsgrenzen. Bei „frei" bleiben die Felder unangetastet.
const range = computed(() => {
    if (period.value === 'custom')  return { from: fromDate.value, to: toDate.value }
    if (period.value === 'year')    return { from: `${year.value}-01-01`, to: `${year.value}-12-31` }
    if (period.value === 'quarter') {
        const first = quarter.value * 3
        return { from: iso(new Date(year.value, first, 1)), to: iso(new Date(year.value, first + 3, 0)) }
    }
    return { from: iso(new Date(year.value, month.value, 1)), to: iso(new Date(year.value, month.value + 1, 0)) }
})

function iso(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function money(value, blankZero = false) {
    if (blankZero && Math.abs(Number(value) || 0) < 0.005) return ''
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value) || 0)
}

function categoryLabel(category) {
    const key = 'AccountingView.chartOfAccounts.categories.' + category
    return category ? t(key) : ''
}

function toggleCategory(key) {
    categories.value = categories.value.includes(key)
        ? categories.value.filter(entry => entry !== key)
        : [...categories.value, key]
}

function shift(step) {
    if (period.value === 'year') { year.value += step; return }
    if (period.value === 'quarter') {
        const next = quarter.value + step
        year.value += Math.floor(next / 4)
        quarter.value = ((next % 4) + 4) % 4
        return
    }
    const next = new Date(year.value, month.value + step, 1)
    year.value = next.getFullYear()
    month.value = next.getMonth()
}

function openLedger(row) {
    router.push({
        name: 'accounting-account-ledger',
        query: { accno: row.accno, from: range.value.from, to: range.value.to }
    })
}

async function load() {
    await fetchTrialBalance({ from_date: range.value.from, to_date: range.value.to, all: showAll.value })
}

function print() {
    printTrialBalance({ from_date: range.value.from, to_date: range.value.to, all: showAll.value })
}

watch([range, showAll], load)
onMounted(load)
</script>

<style scoped>
.figures {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 1px;
    background: rgba(var(--v-border-color), var(--v-border-opacity));
}
.figures__cell { background: rgb(var(--v-theme-surface)); padding: .6rem 1rem; }
.figures__cell--bad { background: rgba(var(--v-theme-error), .06); }
.figures__label { font-size: .68rem; letter-spacing: .06em; text-transform: uppercase; opacity: .65; }
.figures__value { font-size: 1.2rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.figures__sub   { font-size: .72rem; opacity: .6; }

.reports-table :deep(td) { font-variant-numeric: tabular-nums; }
.reports-table :deep(tbody tr) { cursor: pointer; }
</style>
