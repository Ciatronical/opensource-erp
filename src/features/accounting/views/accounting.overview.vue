<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-2">{{ t('AccountingView.overview.title') }}</h1>
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline"
                         class="mb-2" :text="t('AccountingView.overview.info')" />
            </v-col>
        </v-row>

        <!-- Statistik-Karten -->
        <v-row>
            <v-col cols="12" sm="6" md="3">
                <v-card color="warning" variant="tonal" @click="goToBookings('pending')">
                    <v-card-text class="text-center">
                        <v-icon size="32" class="mb-2">mdi-clock-outline</v-icon>
                        <div class="text-h4 font-weight-bold">{{ dashboard?.stats?.pending_count || 0 }}</div>
                        <div class="text-body-2">{{ t('AccountingView.overview.pendingBookings') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card color="success" variant="tonal" :to="{ name: 'accounting-bookings' }">
                    <v-card-text class="text-center">
                        <v-icon size="32" class="mb-2">mdi-book-open-variant</v-icon>
                        <div class="text-h4 font-weight-bold">{{ dashboard?.stats?.bookings_year || 0 }}</div>
                        <div class="text-body-2">{{ t('AccountingView.overview.bookingsYear', { year: dashboard?.stats?.current_year || '' }) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card color="primary" variant="tonal">
                    <v-card-text class="text-center">
                        <v-icon size="32" class="mb-2">mdi-arrow-up-bold</v-icon>
                        <div class="text-h5 font-weight-bold">{{ formatCurrency(dashboard?.stats?.income_year) }}</div>
                        <div class="text-body-2">{{ t('AccountingView.overview.incomeYear', { year: dashboard?.stats?.current_year || '' }) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ t('AccountingView.overview.monthBreakdown', { cur: formatCurrency(dashboard?.stats?.income_this_month), prev: formatCurrency(dashboard?.stats?.income_last_month) }) }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card color="info" variant="tonal">
                    <v-card-text class="text-center">
                        <v-icon size="32" class="mb-2">mdi-arrow-down-bold</v-icon>
                        <div class="text-h5 font-weight-bold">{{ formatCurrency(dashboard?.stats?.expense_year) }}</div>
                        <div class="text-body-2">{{ t('AccountingView.overview.expensesYear', { year: dashboard?.stats?.current_year || '' }) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ t('AccountingView.overview.monthBreakdown', { cur: formatCurrency(dashboard?.stats?.expense_this_month), prev: formatCurrency(dashboard?.stats?.expense_last_month) }) }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Verlauf Einnahmen & Ausgaben -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-card variant="outlined">
                    <v-card-title class="text-subtitle-1 d-flex align-center">
                        {{ t('AccountingView.overview.trendTitle') }}
                    </v-card-title>
                    <v-card-text>
                        <AccountingTrendChart :series="chartSeries" />
                        <div class="text-caption text-medium-emphasis mt-2">
                            <v-icon size="14" class="mr-1">mdi-information-outline</v-icon>
                            {{ t('AccountingView.overview.trendHint') }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Offene Posten (echte Zahlen aus ar/ap) -->
        <v-row class="mt-2">
            <v-col cols="12" sm="6">
                <v-card variant="tonal" color="error" hover style="cursor: pointer"
                        @click="goToOpenItems('receivables')">
                    <v-card-text>
                        <div class="d-flex justify-space-between align-center">
                            <div>
                                <div class="text-body-2">{{ t('AccountingView.overview.openReceivables') }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ t('AccountingView.overview.openReceivablesHint', { count: dashboard?.open_items?.receivables_count || 0 }) }}
                                </div>
                            </div>
                            <div class="text-h5 font-weight-bold">{{ formatCurrency(dashboard?.open_items?.receivables_sum) }}</div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6">
                <v-card variant="tonal" color="deep-orange" hover style="cursor: pointer"
                        @click="goToOpenItems('payables')">
                    <v-card-text>
                        <div class="d-flex justify-space-between align-center">
                            <div>
                                <div class="text-body-2">{{ t('AccountingView.overview.openPayables') }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ t('AccountingView.overview.openPayablesHint', { count: dashboard?.open_items?.payables_count || 0 }) }}
                                </div>
                            </div>
                            <div class="text-h5 font-weight-bold">{{ formatCurrency(dashboard?.open_items?.payables_sum) }}</div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Umsatzsteuer-Voranmeldung: laufender Monat und Vormonat nebeneinander.
             Die Voranmeldung wird für den Vormonat abgegeben — am Monatsanfang
             wären reine „diesen Monat"-Zahlen sonst durchweg null. -->
        <v-row class="mt-4">
            <v-col cols="12">
                <h2 class="text-h6 mb-2">{{ t('AccountingView.overview.taxTitle') }}</h2>
            </v-col>
            <v-col cols="12" md="6">
                <v-card variant="outlined">
                    <v-card-title class="text-subtitle-2 text-medium-emphasis">
                        {{ t('AccountingView.overview.periodCurrent', { period: dashboard?.stats?.current_period || '' }) }}
                    </v-card-title>
                    <v-card-text class="pt-0">
                        <div class="d-flex justify-space-between align-center py-1">
                            <span class="text-body-2">{{ t('AccountingView.overview.ust') }}</span>
                            <span class="text-h6 text-error">{{ formatCurrency(dashboard?.stats?.ust_this_month) }}</span>
                        </div>
                        <div class="d-flex justify-space-between align-center py-1">
                            <span class="text-body-2">{{ t('AccountingView.overview.vst') }}</span>
                            <span class="text-h6 text-success">{{ formatCurrency(dashboard?.stats?.vst_this_month) }}</span>
                        </div>
                        <v-divider class="my-2" />
                        <div class="d-flex justify-space-between align-center">
                            <span class="text-body-2 font-weight-medium">{{ payableLabel(dashboard?.stats?.payable_this_month) }}</span>
                            <span class="text-h6 font-weight-bold">{{ formatCurrency(Math.abs(dashboard?.stats?.payable_this_month || 0)) }}</span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" md="6">
                <v-card variant="outlined">
                    <v-card-title class="text-subtitle-2 text-medium-emphasis">
                        {{ t('AccountingView.overview.periodPrevious', { period: dashboard?.stats?.previous_period || '' }) }}
                    </v-card-title>
                    <v-card-text class="pt-0">
                        <div class="d-flex justify-space-between align-center py-1">
                            <span class="text-body-2">{{ t('AccountingView.overview.ust') }}</span>
                            <span class="text-h6 text-error">{{ formatCurrency(dashboard?.stats?.ust_last_month) }}</span>
                        </div>
                        <div class="d-flex justify-space-between align-center py-1">
                            <span class="text-body-2">{{ t('AccountingView.overview.vst') }}</span>
                            <span class="text-h6 text-success">{{ formatCurrency(dashboard?.stats?.vst_last_month) }}</span>
                        </div>
                        <v-divider class="my-2" />
                        <div class="d-flex justify-space-between align-center">
                            <span class="text-body-2 font-weight-medium">{{ payableLabel(dashboard?.stats?.payable_last_month) }}</span>
                            <span class="text-h6 font-weight-bold">{{ formatCurrency(Math.abs(dashboard?.stats?.payable_last_month || 0)) }}</span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12">
                <v-card variant="outlined" @click="router.push({ name: 'banking-reconciliation' })">
                    <v-card-text>
                        <div class="d-flex justify-space-between align-center">
                            <span class="text-body-2">{{ t('AccountingView.overview.unmatchedBank') }}</span>
                            <v-chip :color="(dashboard?.unmatched_bank || 0) > 0 ? 'warning' : 'success'" size="small">
                                {{ dashboard?.unmatched_bank || 0 }}
                            </v-chip>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Schnellaktionen -->
        <v-row class="mt-4">
            <v-col cols="12">
                <h2 class="text-h6 mb-2">{{ t('AccountingView.overview.quickActions') }}</h2>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-btn block color="primary" size="large" variant="elevated"
                       :to="{ name: 'accounting-invoice-upload' }">
                    <v-icon start>mdi-upload</v-icon>
                    {{ t('AccountingView.overview.uploadInvoice') }}
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-btn block color="secondary" size="large" variant="elevated"
                       @click="goToBookings('pending')">
                    <v-icon start>mdi-check-all</v-icon>
                    {{ t('AccountingView.overview.pendingBookings') }}
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-btn block size="large" variant="elevated"
                       :to="{ name: 'accounting-outgoing' }">
                    <v-icon start>mdi-cash-check</v-icon>
                    {{ t('AccountingView.menu.outgoingMatching') }}
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-btn block size="large" variant="elevated"
                       :to="{ name: 'accounting-datev-export' }">
                    <v-icon start>mdi-file-export</v-icon>
                    {{ t('AccountingView.menu.datevExport') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Letzte Buchungen -->
        <v-row class="mt-4">
            <v-col cols="12">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        {{ t('AccountingView.overview.recentBookings') }}
                        <v-spacer />
                        <v-btn variant="text" size="small" :to="{ name: 'accounting-bookings' }">
                            {{ t('AccountingView.bookings.filterAll') }}
                            <v-icon end>mdi-arrow-right</v-icon>
                        </v-btn>
                    </v-card-title>
                    <v-data-table
                        :headers="recentHeaders"
                        :items="dashboard?.recent_bookings || []"
                        :loading="loading"
                        density="compact"
                        :items-per-page="10"
                        :no-data-text="t('AccountingView.bookings.noJournal')"
                        hover
                        @click:row="(_, { item }) => openBooking(item)"
                    >
                        <template #item.reference="{ item }">
                            <a class="text-primary text-decoration-none font-weight-medium" @click.stop="openBooking(item)">
                                {{ item.reference || '—' }}
                            </a>
                        </template>
                        <template #item.type="{ item }">
                            <v-chip :color="journalTypeColor(item.type)" size="x-small">
                                {{ t('AccountingView.bookings.type' + capitalize(item.type)) }}
                            </v-chip>
                        </template>
                        <template #item.amount="{ item }">
                            <span :class="item.type === 'incoming' ? 'text-error' : 'text-success'">
                                {{ item.type === 'incoming' ? '-' : '+' }}{{ formatCurrency(item.amount) }}
                            </span>
                        </template>
                    </v-data-table>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingTrendChart from '../components/accounting.trend-chart.vue'
import { useRouter } from 'vue-router'
import { useAccounting } from '../composables/useAccounting.js'

const { t } = useI18n()
const router = useRouter()
const { loading, dashboard, fetchDashboard, fetchChart } = useAccounting()

const chartSeries = ref([])

const recentHeaders = computed(() => [
    { title: t('AccountingView.bookings.date'), key: 'transdate_fmt', width: '100px' },
    { title: t('AccountingView.bookings.reference'), key: 'reference', width: '120px' },
    { title: t('AccountingView.bookings.partner'), key: 'partner' },
    { title: t('AccountingView.bookings.description'), key: 'description' },
    { title: t('AccountingView.bookings.type'), key: 'type', width: '110px' },
    { title: t('AccountingView.bookings.amount'), key: 'amount', align: 'end' }
])

function journalTypeColor(type) {
    const colors = { outgoing: 'success', incoming: 'error', manual: 'info' }
    return colors[type] || 'default'
}

function goToBookings(status) {
    router.push({ name: 'accounting-bookings', query: { status } })
}

// Klick auf eine Buchung/Belegnummer → Journal öffnen und die Buchung direkt anzeigen
function openBooking(item) {
    router.push({ name: 'accounting-bookings', query: { src: item.src, id: item.id } })
}

// Klick auf „Offene Forderungen/Verbindlichkeiten" → Offene-Posten-Liste
function goToOpenItems(type) {
    router.push({ name: 'accounting-open-items', query: { type } })
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

// Zahllast = Umsatzsteuer minus Vorsteuer. Negativ bedeutet Vorsteuer-Überhang,
// also eine Erstattung — dann darf dort nicht „Zahllast" stehen.
function payableLabel(value) {
    return Number(value || 0) < 0
        ? t('AccountingView.overview.vatRefund')
        : t('AccountingView.overview.vatPayable')
}

function statusColor(status) {
    const colors = { pending: 'warning', approved: 'success', booked: 'info', rejected: 'error' }
    return colors[status] || 'default'
}

function capitalize(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''
}

onMounted(async () => {
    fetchDashboard()
    chartSeries.value = await fetchChart(12)
})
</script>
