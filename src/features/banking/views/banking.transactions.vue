<template>
    <NavbarView />
    <v-container fluid>
        <!-- Header -->
        <v-row align="center" class="mb-4">
            <v-col cols="auto">
                <v-btn icon variant="text" @click="router.push({ name: 'banking-overview' })">
                    <v-icon>mdi-arrow-left</v-icon>
                </v-btn>
            </v-col>
            <v-col>
                <h1 class="text-h5">{{ t('BankingView.transactions.title') }}</h1>
                <div v-if="accountStats" class="text-body-2 text-medium-emphasis">
                    {{ accountStats.name }} — {{ formatIban(accountStats.iban) }}
                </div>
            </v-col>
            <v-col cols="auto">
                <div v-if="accountStats" class="text-h5 font-weight-bold" :class="balanceColor(accountStats.balance)">
                    {{ formatCurrency(accountStats.balance) }}
                </div>
            </v-col>
        </v-row>

        <!-- Filter -->
        <v-row dense class="mb-2">
            <v-col cols="12" md="auto">
                <v-btn-toggle v-model="matchFilter" mandatory density="compact" variant="outlined">
                    <v-btn value="all" size="small">{{ t('BankingView.transactions.filterAll') }}</v-btn>
                    <v-btn value="unmatched" size="small" color="warning">{{ t('BankingView.transactions.filterUnmatched') }}</v-btn>
                    <v-btn value="matched" size="small" color="info">{{ t('BankingView.transactions.filterMatched') }}</v-btn>
                    <v-btn value="booked" size="small" color="success">{{ t('BankingView.transactions.filterBooked') }}</v-btn>
                    <v-btn value="ignored" size="small">{{ t('BankingView.transactions.filterIgnored') }}</v-btn>
                </v-btn-toggle>
            </v-col>
            <v-spacer />
            <v-col cols="6" md="2">
                <v-text-field
                    v-model="fromDate"
                    :label="t('BankingView.transactions.fromDate')"
                    type="date"
                    density="compact"
                    hide-details
                />
            </v-col>
            <v-col cols="6" md="2">
                <v-text-field
                    v-model="toDate"
                    :label="t('BankingView.transactions.toDate')"
                    type="date"
                    density="compact"
                    hide-details
                />
            </v-col>
        </v-row>

        <!-- Umsatzliste -->
        <v-card>
            <v-data-table
                :headers="headers"
                :items="banking.transactions.value"
                :loading="banking.loading.value"
                :items-per-page="50"
                density="compact"
                class="banking-transactions-table"
                hover
                :row-props="getRowProps"
                @click:row="onRowClick"
            >
                <!-- Datum -->
                <template #item.transdate="{ item }">
                    <span class="text-no-wrap">{{ formatDateShort(item.transdate) }}</span>
                </template>

                <!-- Betrag -->
                <template #item.amount="{ item }">
                    <span :class="item.amount >= 0 ? 'text-success' : 'text-error'" class="font-weight-medium">
                        {{ formatCurrency(item.amount) }}
                    </span>
                </template>

                <!-- Auftraggeber/Empfaenger -->
                <template #item.remote_name="{ item }">
                    <div>
                        <div class="font-weight-medium">{{ item.remote_name || '—' }}</div>
                        <div v-if="item.remote_iban" class="text-caption text-medium-emphasis">
                            {{ formatIban(item.remote_iban) }}
                        </div>
                    </div>
                </template>

                <!-- Verwendungszweck -->
                <template #item.purpose="{ item }">
                    <div class="text-body-2" style="max-width: 400px; white-space: normal;">
                        {{ item.purpose || '—' }}
                    </div>
                </template>

                <!-- Status -->
                <template #item.match_status="{ item }">
                    <v-chip :color="statusColor(item.match_status)" size="small" variant="tonal">
                        {{ t('BankingView.transactions.filter' + capitalize(item.match_status)) }}
                    </v-chip>
                </template>

                <!-- Zuordnung -->
                <template #item.assignments="{ item }">
                    <div v-if="item.assignments && item.assignments.length">
                        <div v-for="(a, i) in item.assignments" :key="i" class="text-caption">
                            {{ a.invnumber }} — {{ a.customer_name || a.vendor_name }}
                        </div>
                    </div>
                </template>

                <!-- Aktionen — @click.stop verhindert den Zeilen-Klick -->
                <template #item.actions="{ item }">
                    <div class="d-flex ga-1" @click.stop>
                        <v-btn
                            v-if="item.match_status === 'unmatched' && item.amount > 0"
                            icon="mdi-bank-transfer-in"
                            size="x-small"
                            variant="text"
                            color="success"
                            :title="t('BankingView.booking.titleShort')"
                            @click="openBookingDialog(item)"
                        />
                        <v-btn
                            v-else-if="item.match_status === 'matched'"
                            icon="mdi-check-circle-outline"
                            size="x-small"
                            variant="text"
                            color="info"
                            :title="t('BankingView.booking.titleMatched')"
                            @click="openBookingDialog(item)"
                        />
                        <v-btn
                            v-if="item.match_status === 'unmatched'"
                            icon="mdi-eye-off"
                            size="x-small"
                            variant="text"
                            :title="t('BankingView.transactions.ignore')"
                            @click="ignoreTransaction(item)"
                        />
                        <v-btn
                            v-if="item.match_status === 'ignored'"
                            icon="mdi-undo"
                            size="x-small"
                            variant="text"
                            :title="t('BankingView.transactions.resetStatus')"
                            @click="resetTransaction(item)"
                        />
                    </div>
                </template>
            </v-data-table>
        </v-card>

        <!-- Buchungsdialog -->
        <BookingDialog
            v-if="dialogTransaction"
            v-model="showBookingDialog"
            :transaction="dialogTransaction"
            :account-id="Number(props.id)"
            @done="onBookingDone"
            @ignore="onIgnoreFromDialog"
        />
    </v-container>
</template>

<script setup>
import { ref, watch, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useBanking } from '../composables/useBanking.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import BookingDialog from '../components/booking-dialog.component.vue'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    id: { type: [Number, String], required: true }
})

const { t } = useI18n()
const router = useRouter()
const banking = useBanking()

const accountStats = ref(null)
const matchFilter = ref('all')
const fromDate = ref('')
const toDate = ref('')

const showBookingDialog = ref(false)
const dialogTransaction = ref(null)

const headers = computed(() => [
    { title: t('BankingView.transactions.date'), key: 'transdate', width: '100px' },
    { title: t('BankingView.transactions.amount'), key: 'amount', width: '120px', align: 'end' },
    { title: t('BankingView.transactions.remoteName'), key: 'remote_name', width: '200px' },
    { title: t('BankingView.transactions.purpose'), key: 'purpose' },
    { title: t('BankingView.transactions.status'), key: 'match_status', width: '120px' },
    { title: '', key: 'assignments', width: '180px', sortable: false },
    { title: '', key: 'actions', width: '80px', sortable: false }
])

onMounted(async () => {
    await loadData()
})

watch([matchFilter, fromDate, toDate], () => {
    loadTransactions()
})

async function loadData() {
    const bankAccountId = Number(props.id)
    try {
        accountStats.value = await banking.fetchAccountStats(bankAccountId)
    } catch (e) {
        // Stats-Fehler nicht blockierend
    }
    await loadTransactions()
}

async function loadTransactions() {
    await banking.fetchTransactions(Number(props.id), {
        match_status: matchFilter.value,
        from_date: fromDate.value || undefined,
        to_date: toDate.value || undefined
    })
}

function isBookable(item) {
    return (item.match_status === 'unmatched' || item.match_status === 'matched') && item.amount > 0
}

function getRowProps({ item }) {
    const classes = []
    if (isBookable(item)) classes.push('row-bookable')
    if (item.match_status === 'booked') classes.push('row-booked')
    return { class: classes.join(' ') || undefined }
}

function onRowClick(_event, { item }) {
    if (isBookable(item)) {
        openBookingDialog(item)
    }
}

function openBookingDialog(item) {
    dialogTransaction.value = item
    showBookingDialog.value = true
}

async function onBookingDone() {
    await loadTransactions()
}

async function onIgnoreFromDialog(transactionId) {
    try {
        await banking.setTransactionStatus(transactionId, 'ignored')
        await loadTransactions()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function ignoreTransaction(item) {
    try {
        await banking.setTransactionStatus(item.id, 'ignored')
        await loadTransactions()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function resetTransaction(item) {
    try {
        await banking.setTransactionStatus(item.id, 'unmatched')
        await loadTransactions()
    } catch (e) {
        alerts.error(e.message)
    }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

function formatIban(iban) {
    if (!iban) return '—'
    return iban.replace(/(.{4})/g, '$1 ').trim()
}

function formatDateShort(dateStr) {
    if (!dateStr) return '—'
    return new Date(dateStr).toLocaleDateString('de-DE')
}

function balanceColor(balance) {
    return parseFloat(balance) >= 0 ? 'text-success' : 'text-error'
}

function statusColor(status) {
    const colors = { unmatched: 'warning', matched: 'info', booked: 'success', ignored: 'default' }
    return colors[status] || 'default'
}

function capitalize(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''
}
</script>

<style scoped>
:deep(.row-bookable) {
    cursor: pointer;
}
:deep(.row-booked) {
    opacity: 0.65;
}
</style>
