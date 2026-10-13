<template>
    <NavbarView />
    <v-container fluid>
        <v-row align="center" class="mb-4">
            <v-col>
                <h1 class="text-h5">{{ t('BankingView.reconciliation.title') }}</h1>
            </v-col>
            <v-col cols="auto">
                <v-select
                    v-model="selectedAccountId"
                    :items="banking.accounts.value"
                    item-title="name"
                    item-value="id"
                    :label="t('BankingView.reconciliation.selectAccount')"
                    density="compact"
                    hide-details
                    style="min-width: 250px"
                />
            </v-col>
        </v-row>

        <v-row v-if="selectedAccountId">
            <!-- Linke Seite: Bankumsaetze -->
            <v-col cols="12" lg="6">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        {{ t('BankingView.reconciliation.bankTransactions') }}
                        <v-spacer />
                        <v-btn
                            color="primary"
                            variant="tonal"
                            size="small"
                            :loading="matching.loading.value"
                            @click="runAutoMatchAction"
                        >
                            <v-icon start>mdi-auto-fix</v-icon>
                            {{ t('BankingView.reconciliation.autoMatch') }}
                        </v-btn>
                    </v-card-title>
                    <v-divider />

                    <v-list density="compact" class="banking-list">
                        <template v-if="unmatchedTransactions.length === 0">
                            <v-list-item class="text-medium-emphasis text-center">
                                {{ t('BankingView.transactions.noTransactions') }}
                            </v-list-item>
                        </template>

                        <v-list-item
                            v-for="bt in unmatchedTransactions"
                            :key="bt.id"
                            :class="{ 'bg-blue-lighten-5': selectedTransaction?.id === bt.id }"
                            @click="selectTransaction(bt)"
                        >
                            <template #prepend>
                                <v-checkbox-btn
                                    v-if="bt.match_status === 'matched'"
                                    v-model="selectedForBooking"
                                    :value="bt.id"
                                    density="compact"
                                />
                                <v-icon
                                    v-else
                                    :color="bt.amount >= 0 ? 'success' : 'error'"
                                    size="small"
                                >
                                    {{ bt.amount >= 0 ? 'mdi-arrow-down' : 'mdi-arrow-up' }}
                                </v-icon>
                            </template>

                            <v-list-item-title class="d-flex align-center">
                                <span class="font-weight-medium" :class="bt.amount >= 0 ? 'text-success' : 'text-error'">
                                    {{ formatCurrency(bt.amount) }}
                                </span>
                                <v-spacer />
                                <span class="text-caption text-medium-emphasis">
                                    {{ formatDateShort(bt.transdate) }}
                                </span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                <strong>{{ bt.remote_name || '—' }}</strong>
                            </v-list-item-subtitle>
                            <v-list-item-subtitle class="text-caption">
                                {{ bt.purpose ? bt.purpose.substring(0, 80) : '—' }}
                            </v-list-item-subtitle>

                            <template #append>
                                <v-btn
                                    v-if="bt.match_status === 'unmatched' && bt.amount < 0"
                                    icon="mdi-file-document-plus"
                                    size="x-small"
                                    variant="text"
                                    color="primary"
                                    :title="t('BankingView.reconciliation.createApTitle')"
                                    @click.stop="openApDialog(bt)"
                                />
                                <v-chip
                                    :color="statusColor(bt.match_status)"
                                    size="x-small"
                                    variant="tonal"
                                >
                                    {{ bt.match_status }}
                                </v-chip>
                            </template>
                        </v-list-item>
                    </v-list>

                    <!-- Buchen-Button -->
                    <v-card-actions v-if="selectedForBooking.length > 0">
                        <v-btn
                            color="success"
                            variant="tonal"
                            block
                            @click="bookSelected"
                        >
                            <v-icon start>mdi-check-all</v-icon>
                            {{ t('BankingView.reconciliation.bookSelected') }}
                            ({{ selectedForBooking.length }})
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-col>

            <!-- Rechte Seite: Offene Posten -->
            <v-col cols="12" lg="6">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        {{ t('BankingView.reconciliation.openInvoices') }}
                        <v-spacer />
                        <v-text-field
                            v-model="invoiceSearch"
                            :placeholder="t('BankingView.reconciliation.searchInvoices')"
                            prepend-inner-icon="mdi-magnify"
                            density="compact"
                            hide-details
                            single-line
                            style="max-width: 250px"
                            clearable
                        />
                    </v-card-title>
                    <v-divider />

                    <!-- Auto-Match Ergebnisse -->
                    <v-alert
                        v-if="matching.autoMatches.value.length > 0"
                        type="info"
                        variant="tonal"
                        class="ma-2"
                        closable
                    >
                        {{ t('BankingView.reconciliation.matchCount', { count: matching.autoMatches.value.length }) }}
                    </v-alert>

                    <!-- Ausgangsrechnungen (Zahlungseingaenge) -->
                    <div v-if="arInvoices.length > 0" class="px-3 pt-2">
                        <div class="text-overline text-success">{{ t('BankingView.reconciliation.receivables') }}</div>
                    </div>
                    <v-list density="compact" class="banking-list">
                        <v-list-item
                            v-for="inv in arInvoices"
                            :key="'ar-' + inv.id"
                            @click="matchWithSelected(inv)"
                        >
                            <v-list-item-title class="d-flex align-center">
                                <span class="font-weight-medium">{{ inv.invnumber }}</span>
                                <v-spacer />
                                <span class="text-success font-weight-medium">
                                    {{ formatCurrency(inv.open_amount) }}
                                </span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                {{ inv.customer_name }}
                            </v-list-item-subtitle>
                            <v-list-item-subtitle class="text-caption">
                                {{ t('BankingView.reconciliation.dueDate') }}: {{ formatDateShort(inv.duedate) }}
                            </v-list-item-subtitle>

                            <template #append>
                                <v-btn
                                    v-if="selectedTransaction"
                                    icon="mdi-link-variant"
                                    size="x-small"
                                    variant="text"
                                    color="primary"
                                    :title="t('BankingView.reconciliation.matchManually')"
                                    @click.stop="matchManually(inv)"
                                />
                            </template>
                        </v-list-item>
                    </v-list>

                    <!-- Eingangsrechnungen (Zahlungsausgaenge) -->
                    <div v-if="apInvoices.length > 0" class="px-3 pt-2">
                        <div class="text-overline text-error">{{ t('BankingView.reconciliation.payables') }}</div>
                    </div>
                    <v-list density="compact" class="banking-list">
                        <v-list-item
                            v-for="inv in apInvoices"
                            :key="'ap-' + inv.id"
                            @click="matchWithSelected(inv)"
                        >
                            <v-list-item-title class="d-flex align-center">
                                <span class="font-weight-medium">{{ inv.invnumber }}</span>
                                <v-spacer />
                                <span class="text-error font-weight-medium">
                                    {{ formatCurrency(inv.open_amount) }}
                                </span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                {{ inv.vendor_name }}
                            </v-list-item-subtitle>

                            <template #append>
                                <v-btn
                                    v-if="selectedTransaction"
                                    icon="mdi-link-variant"
                                    size="x-small"
                                    variant="text"
                                    color="primary"
                                    @click.stop="matchManually(inv)"
                                />
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>
        </v-row>

        <!-- Eingangsrechnung direkt aus dem Bankumsatz anlegen + bezahlen -->
        <v-dialog v-model="apDialog" max-width="780">
            <v-card v-if="apSource">
                <v-card-title>{{ t('BankingView.reconciliation.createApTitle') }}</v-card-title>
                <v-card-subtitle>
                    {{ formatCurrency(Math.abs(apSource.amount)) }} · {{ apSource.remote_name || '—' }}
                    · {{ formatDateShort(apSource.transdate) }}
                </v-card-subtitle>
                <v-card-text>
                    <v-autocomplete
                        v-model="apForm.vendor_id"
                        :items="vendorOptions"
                        :item-title="v => v.vendornumber ? `${v.name} (${v.vendornumber})` : v.name"
                        item-value="id"
                        :label="t('BankingView.reconciliation.vendor') + ' *'"
                        density="comfortable" variant="outlined"
                        :loading="vendorLoading" no-filter clearable
                        prepend-inner-icon="mdi-domain"
                        @update:search="onVendorSearch" />
                    <!-- Aufwandskonto und Steuersatz je Position; „Aufteilen" für eine
                         Splitbuchung (z. B. Wareneingang + Mahnkosten auf 6436) -->
                    <split-lines
                        v-model="apForm.lines"
                        :total="Math.abs(Number(apSource.amount)) || 0"
                        :search-accounts="searchAccountsForSplit"
                        :account-title="a => a ? `${a.accno} ${a.description}` : ''"
                        :account-label="t('BankingView.reconciliation.expenseAccount') + ' *'"
                        :tax-items="taxRateOptions"
                        :default-tax="defaultRateFor"
                        class="mt-2"
                    />
                    <v-text-field
                        v-model="apForm.invnumber"
                        :label="t('BankingView.reconciliation.invnumber')"
                        density="comfortable" variant="outlined" />
                    <v-alert type="info" variant="tonal" density="compact">
                        {{ t('BankingView.reconciliation.createApHint') }}
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="apDialog = false">{{ t('BankingView.reconciliation.cancel') }}</v-btn>
                    <v-btn color="primary" variant="elevated" :loading="apSaving"
                           :disabled="!apForm.vendor_id || !!apLinesProblem" @click="submitAp">
                        <v-icon start>mdi-check</v-icon>{{ t('BankingView.reconciliation.createApBook') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBanking } from '../composables/useBanking.js'
import { useMatching } from '../composables/useMatching.js'
import { useAccounting } from '@/features/accounting/composables/useAccounting.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import SplitLines from '../components/split-lines.component.vue'
import { newLine, linesError } from '../composables/useSplitLines.js'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const banking = useBanking()
const matching = useMatching()
const { searchVendors, searchAccounts } = useAccounting()

const selectedAccountId = ref(null)
const selectedTransaction = ref(null)
const selectedForBooking = ref([])
const invoiceSearch = ref('')

const unmatchedTransactions = computed(() => {
    return banking.transactions.value.filter(
        bt => bt.match_status === 'unmatched' || bt.match_status === 'matched'
    )
})

const arInvoices = computed(() => {
    return matching.openInvoices.value.filter(inv => inv.type === 'ar')
})

const apInvoices = computed(() => {
    return matching.openInvoices.value.filter(inv => inv.type === 'ap')
})

onMounted(async () => {
    await banking.fetchAccounts()
    if (banking.accounts.value.length > 0) {
        selectedAccountId.value = banking.accounts.value[0].id
    }
})

watch(selectedAccountId, async (newId) => {
    if (newId) {
        await Promise.all([
            banking.fetchTransactions(newId, { match_status: 'all', limit: 500 }),
            matching.fetchOpenInvoices('all', '')
        ])
    }
})

watch(invoiceSearch, async (search) => {
    await matching.fetchOpenInvoices('all', search || '')
})

function selectTransaction(bt) {
    selectedTransaction.value = selectedTransaction.value?.id === bt.id ? null : bt
}

async function matchManually(invoice) {
    if (!selectedTransaction.value) {
        alerts.warning(t('BankingView.reconciliation.selectAccount'))
        return
    }

    try {
        await matching.matchTransaction(
            selectedTransaction.value.id,
            invoice.type,
            invoice.id
        )
        alerts.success(t('BankingView.alerts.matchSuccess'))
        selectedTransaction.value = null
        await reloadData()
    } catch (e) {
        alerts.error(e.message)
    }
}

function matchWithSelected(invoice) {
    if (selectedTransaction.value) {
        matchManually(invoice)
    }
}

async function runAutoMatchAction() {
    if (!selectedAccountId.value) return

    try {
        const matches = await matching.runAutoMatch(selectedAccountId.value)

        if (matches.length === 0) {
            alerts.info(t('BankingView.reconciliation.noMatches'))
            return
        }

        // Automatisch zuordnen
        for (const match of matches) {
            if (match.target_type === 'ar' || match.target_type === 'ap') {
                try {
                    await matching.matchTransaction(match.bank_transaction_id, match.target_type, match.target_id)
                } catch (e) {
                    // Einzelfehler nicht abbrechen
                }
            }
        }

        alerts.success(t('BankingView.reconciliation.matchCount', { count: matches.length }))
        await reloadData()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function bookSelected() {
    if (selectedForBooking.value.length === 0) return

    try {
        const result = await matching.bookMatchedTransactions(
            selectedForBooking.value,
            selectedAccountId.value
        )
        alerts.success(t('BankingView.reconciliation.bookSuccess', { count: result.booked_count }))
        selectedForBooking.value = []
        await reloadData()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function reloadData() {
    await Promise.all([
        banking.fetchTransactions(selectedAccountId.value, { match_status: 'all', limit: 500 }),
        matching.fetchOpenInvoices('all', invoiceSearch.value || '')
    ])
}

// ── Eingangsrechnung direkt aus Bankumsatz anlegen ──────────────────────────
const apDialog = ref(false)
const apSource = ref(null)
const apSaving = ref(false)
const apForm = ref({ vendor_id: null, lines: [newLine()], invnumber: '' })
const vendorOptions = ref([])
const vendorLoading = ref(false)
let vendorTimer = null
// Steuersatz je Position (value = Prozentsatz fürs Backend, rate = Bruchteil für die Vorschau)
const taxRateOptions = [
    { title: '19 %', value: 19, rate: 0.19 },
    { title: '7 %',  value: 7,  rate: 0.07 },
    { title: t('BankingView.split.taxNone'), value: 0, rate: 0 }
]
const apLinesProblem = computed(() =>
    apSource.value ? linesError(Math.abs(Number(apSource.value.amount)) || 0, apForm.value.lines) : 'errorNoLines')

function openApDialog(bt) {
    apSource.value = bt
    apForm.value = { vendor_id: null, lines: [newLine()], invnumber: '' }
    vendorOptions.value = []
    apDialog.value = true
}

/** Steuersatz eines Kontos (heute gültiger Steuerschlüssel aus searchAccounts), sonst 19 % */
function defaultRateFor(account) {
    if (!account || account.tax_rate == null) return 19
    return Math.round(Number(account.tax_rate) * 100)
}

async function searchAccountsForSplit(q) {
    const rows = await searchAccounts(q)
    return rows.map(a => ({ id: a.id, accno: a.accno, description: a.description, tax_rate: a.tax_rate }))
}

function onVendorSearch(q) {
    clearTimeout(vendorTimer)
    if (!q || q.length < 2) return
    vendorTimer = setTimeout(async () => {
        vendorLoading.value = true
        vendorOptions.value = await searchVendors(q)
        vendorLoading.value = false
    }, 300)
}

async function submitAp() {
    if (!apForm.value.vendor_id || apLinesProblem.value) return
    apSaving.value = true
    try {
        const res = await matching.createApFromBankTransaction({
            bank_transaction_id: apSource.value.id,
            bank_account_id: selectedAccountId.value,
            vendor_id: apForm.value.vendor_id,
            lines: apForm.value.lines.map(l => ({
                expense_chart_id: l.account.id,
                gross: Number(l.gross),
                rate:  Number(l.tax ?? 19)
            })),
            invnumber: apForm.value.invnumber || undefined
        })
        alerts.success(res.line_count > 1
            ? t('BankingView.reconciliation.createApSuccessSplit', { gross: formatCurrency(res.gross), n: res.line_count })
            : t('BankingView.reconciliation.createApSuccess', { gross: formatCurrency(res.gross) }))
        apDialog.value = false
        await reloadData()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        apSaving.value = false
    }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

function formatDateShort(dateStr) {
    if (!dateStr) return '—'
    return new Date(dateStr).toLocaleDateString('de-DE')
}

function statusColor(status) {
    const colors = { unmatched: 'warning', matched: 'info', booked: 'success', ignored: 'default' }
    return colors[status] || 'default'
}
</script>

<style scoped>
.banking-list {
    max-height: 60vh;
    overflow-y: auto;
}
</style>
