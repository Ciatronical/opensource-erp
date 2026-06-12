<template>
    <v-dialog v-model="show" max-width="680" scrollable @after-enter="onOpen" @after-leave="reset">
        <v-card :loading="loading">
            <v-card-title class="d-flex align-center py-3">
                <v-icon start>mdi-credit-card-sync-outline</v-icon>
                {{ t('BankingView.settlement.title') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" density="compact" @click="show = false" />
            </v-card-title>
            <v-divider />

            <!-- Umsatz-Kopf -->
            <v-card-text class="pb-0">
                <v-sheet color="grey-lighten-4" rounded="lg" class="pa-3 mb-4">
                    <div class="d-flex align-center">
                        <span class="text-h6 font-weight-bold text-success">{{ formatCurrency(transaction?.amount) }}</span>
                        <v-spacer />
                        <span class="text-caption text-medium-emphasis">{{ formatDate(transaction?.transdate) }}</span>
                    </div>
                    <div class="font-weight-medium">{{ transaction?.remote_name || '—' }}</div>
                    <div v-if="transaction?.purpose" class="text-caption text-medium-emphasis">{{ transaction.purpose }}</div>
                </v-sheet>
            </v-card-text>

            <!-- Schritt: Upload -->
            <v-card-text v-if="step === 'upload'">
                <p class="text-body-2 mb-4">{{ t('BankingView.settlement.uploadHint') }}</p>

                <v-text-field
                    v-model="provider"
                    :label="t('BankingView.settlement.provider')"
                    density="compact"
                    class="mb-2"
                />

                <v-autocomplete
                    v-model="vendorId"
                    :items="vendors"
                    item-title="name"
                    item-value="id"
                    :label="t('BankingView.settlement.vendor')"
                    :hint="t('BankingView.settlement.vendorHint')"
                    persistent-hint
                    density="compact"
                    class="mb-3"
                    :loading="vendorLoading"
                    @update:search="searchVendors"
                />

                <v-file-input
                    :label="t('BankingView.settlement.file')"
                    accept=".xlsx,.xls,.csv,.pdf"
                    density="compact"
                    prepend-icon="mdi-file-table-outline"
                    show-size
                    :loading="parsing"
                    @update:model-value="onFileSelected"
                />

                <!-- Vorschau geparster Zeilen -->
                <template v-if="rows.length > 0">
                    <div class="text-overline mt-2">{{ t('BankingView.settlement.preview', { count: rows.length }) }}</div>
                    <v-table density="compact" class="text-caption">
                        <thead>
                            <tr>
                                <th>{{ t('BankingView.settlement.payoutDate') }}</th>
                                <th class="text-right">{{ t('BankingView.settlement.gross') }}</th>
                                <th class="text-right">{{ t('BankingView.settlement.fee') }}</th>
                                <th class="text-right">{{ t('BankingView.settlement.net') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(r, i) in rows.slice(0, 6)"
                                :key="i"
                                :class="{ 'bg-green-lighten-5': isBankRow(r) }"
                            >
                                <td>{{ formatDate(r.payout_date) }}</td>
                                <td class="text-right">{{ formatCurrency(r.gross) }}</td>
                                <td class="text-right text-error">{{ formatCurrency(-r.fee) }}</td>
                                <td class="text-right font-weight-medium">{{ formatCurrency(r.net) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <div v-if="rows.length > 6" class="text-caption text-medium-emphasis mt-1">
                        {{ t('BankingView.settlement.andMore', { count: rows.length - 6 }) }}
                    </div>
                </template>
            </v-card-text>

            <!-- Schritt: Buchen -->
            <v-card-text v-else-if="step === 'book'">
                <v-alert type="success" variant="tonal" density="compact" class="mb-4">
                    {{ t('BankingView.settlement.matchFound', { provider: match?.provider }) }}
                </v-alert>

                <v-table density="compact" class="mb-4">
                    <tbody>
                        <tr>
                            <td>{{ t('BankingView.settlement.gross') }}</td>
                            <td class="text-right">{{ formatCurrency(match?.gross) }}</td>
                        </tr>
                        <tr>
                            <td class="text-error">{{ t('BankingView.settlement.fee') }}</td>
                            <td class="text-right text-error">{{ formatCurrency(-match?.fee) }}</td>
                        </tr>
                        <tr class="font-weight-bold">
                            <td>{{ t('BankingView.settlement.net') }}</td>
                            <td class="text-right">{{ formatCurrency(match?.net) }}</td>
                        </tr>
                    </tbody>
                </v-table>

                <!-- Zugehörige Ausgangsrechnungen (automatischer Ausgleich) -->
                <div class="text-overline mb-1">{{ t('BankingView.settlement.invoicesTitle') }}</div>
                <v-progress-linear v-if="invoicesLoading" indeterminate class="mb-2" />
                <v-alert v-else-if="invoiceInfo.ambiguous" type="info" variant="tonal" density="compact" class="mb-2">
                    {{ t('BankingView.settlement.invoicesAmbiguous') }}
                </v-alert>
                <v-alert v-else-if="!invoiceInfo.found && invoiceCandidates.length > 0" type="warning" variant="tonal" density="compact" class="mb-2">
                    {{ t('BankingView.settlement.invoicesNotFound') }}
                </v-alert>
                <v-alert v-else-if="invoiceCandidates.length === 0" type="warning" variant="tonal" density="compact" class="mb-2">
                    {{ t('BankingView.settlement.invoicesNone') }}
                </v-alert>

                <v-table v-if="invoiceCandidates.length > 0" density="compact" class="text-caption mb-1">
                    <tbody>
                        <tr v-for="inv in invoiceCandidates" :key="inv.ar_id">
                            <td style="width:34px">
                                <v-checkbox-btn v-model="selectedArIds" :value="inv.ar_id" density="compact" hide-details />
                            </td>
                            <td class="font-weight-medium">{{ inv.invnumber }}</td>
                            <td>{{ inv.customer_name }}</td>
                            <td class="text-right">{{ formatCurrency(inv.open_amount) }}</td>
                        </tr>
                    </tbody>
                </v-table>

                <div v-if="invoiceCandidates.length > 0" class="d-flex text-caption mb-1">
                    <span>{{ t('BankingView.settlement.selectedSum') }}</span>
                    <v-spacer />
                    <span :class="sumMatches ? 'text-success font-weight-bold' : 'text-error font-weight-bold'">
                        {{ formatCurrency(selectedSum) }} / {{ formatCurrency(invoiceInfo.gross) }}
                    </span>
                </div>
                <div v-if="selectedArIds.length === 0" class="text-caption text-medium-emphasis mb-3">
                    {{ t('BankingView.settlement.noInvoiceHint') }}
                </div>

                <v-autocomplete
                    v-if="selectedArIds.length === 0"
                    v-model="clearingChartId"
                    :items="clearingAccountItems"
                    :label="t('BankingView.settlement.clearingAccount')"
                    :hint="t('BankingView.settlement.clearingHint')"
                    :placeholder="t('BankingView.settlement.accountSearchPlaceholder')"
                    persistent-hint
                    density="compact"
                    class="mb-3"
                    :menu-props="{ maxHeight: 400, class: 'oserp-scroll-menu' }"
                />
                <v-autocomplete
                    v-model="feeChartId"
                    :items="feeAccountItems"
                    :label="t('BankingView.settlement.feeAccount')"
                    :hint="t('BankingView.settlement.feeHint')"
                    :placeholder="t('BankingView.settlement.accountSearchPlaceholder')"
                    persistent-hint
                    density="compact"
                    :menu-props="{ maxHeight: 400, class: 'oserp-scroll-menu' }"
                />
            </v-card-text>

            <!-- Schritt: kein Match -->
            <v-card-text v-else-if="step === 'nomatch'">
                <v-alert type="warning" variant="tonal" density="compact">
                    {{ t('BankingView.settlement.noMatch') }}
                </v-alert>
            </v-card-text>

            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="show = false">{{ t('BankingView.settlement.cancel') }}</v-btn>

                <v-btn
                    v-if="step === 'upload'"
                    color="primary"
                    variant="tonal"
                    :disabled="rows.length === 0 || !vendorId || !provider"
                    :loading="loading"
                    @click="saveAndMatch"
                >
                    {{ t('BankingView.settlement.saveAndMatch') }}
                </v-btn>

                <v-btn
                    v-else-if="step === 'book'"
                    color="success"
                    variant="tonal"
                    :disabled="!canBook"
                    :loading="loading"
                    @click="book"
                >
                    <v-icon start>mdi-check</v-icon>
                    {{ t('BankingView.settlement.book') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettlements } from '../composables/useSettlements.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    transaction: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'booked'])

const { t } = useI18n()
const settlements = useSettlements()

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
})

const loading = computed(() => settlements.loading.value)

const step = ref('upload')
const provider = ref('')
const vendorId = ref(null)
const vendors = ref([])
const vendorLoading = ref(false)
const selectedFile = ref(null)
const parsing = ref(false)
const rows = ref([])
const match = ref(null)
const accounts = ref([])
const feeChartId = ref(null)
const clearingChartId = ref(null)

const invoiceCandidates = ref([])
const selectedArIds = ref([])
const invoiceInfo = ref({ found: false, ambiguous: false, gross: 0 })
const invoicesLoading = ref(false)

function mapAccounts(list) {
    return list.map(a => ({ title: `${a.accno} – ${a.description}`, value: a.id }))
}
// Sinnvolle Vorauswahl je Feld: Verrechnung/Geldtransit = Aktiva (A),
// Gebühr = Aufwand (E). Falls ein bereits gemerktes Konto nicht in die
// Kategorie fällt, wird es trotzdem ergänzt, damit es sichtbar bleibt.
const clearingAccountItems = computed(() => {
    const list = accounts.value.filter(a => a.category === 'A' || a.id === clearingChartId.value)
    return mapAccounts(list)
})
const feeAccountItems = computed(() => {
    const list = accounts.value.filter(a => a.category === 'E' || a.id === feeChartId.value)
    return mapAccounts(list)
})

const selectedSum = computed(() =>
    invoiceCandidates.value
        .filter(inv => selectedArIds.value.includes(inv.ar_id))
        .reduce((s, inv) => s + Number(inv.open_amount || 0), 0)
)

const sumMatches = computed(() =>
    Math.abs(selectedSum.value - Number(invoiceInfo.value.gross || 0)) < 0.005
)

// Buchen erlaubt: Gebuehrenkonto gewaehlt; mit Rechnungsauswahl muss die Summe
// exakt den Bruttobetrag ergeben, ohne Rechnungen braucht es ein Verrechnungskonto.
const canBook = computed(() => {
    if (!feeChartId.value) return false
    if (selectedArIds.value.length > 0) return sumMatches.value
    return !!clearingChartId.value
})

function isBankRow(r) {
    return Math.abs((r.net || 0) - (props.transaction?.amount || 0)) < 0.005
}

async function onOpen() {
    if (!props.transaction) return
    step.value = 'upload'
    provider.value = props.transaction.remote_name || 'Flatpay'
    try {
        // Bereits hochgeladene Abrechnung? Dann direkt zum Buchen.
        const [m, accs] = await Promise.all([
            settlements.suggestMatch(props.transaction.id),
            settlements.fetchAccounts(),
        ])
        accounts.value = accs
        await searchVendors('')
        if (m) {
            if (m.vendor_id) vendorId.value = m.vendor_id
            await enterBookStep(m)
        }
    } catch (e) {
        alerts.error(e.message)
    }
}

// In den Buchen-Schritt wechseln: Konten vorbelegen + zugehoerige Rechnungen laden.
async function enterBookStep(m) {
    match.value = m
    feeChartId.value = m.fee_chart_id || feeChartId.value || null
    clearingChartId.value = m.clearing_chart_id || clearingChartId.value || null
    step.value = 'book'
    invoicesLoading.value = true
    try {
        const res = await settlements.findInvoices(m.line_id)
        invoiceCandidates.value = res.all_candidates || []
        selectedArIds.value = (res.invoices || []).map(i => i.ar_id)
        invoiceInfo.value = { found: !!res.found, ambiguous: !!res.ambiguous, gross: res.gross }
    } catch (e) {
        invoiceCandidates.value = []
        selectedArIds.value = []
        invoiceInfo.value = { found: false, ambiguous: false, gross: m.gross }
    } finally {
        invoicesLoading.value = false
    }
}

let searchTimer = null
function searchVendors(query) {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(async () => {
        vendorLoading.value = true
        try {
            vendors.value = await settlements.fetchVendors(query || '')
        } catch (e) {
            // stillschweigend – Liste bleibt wie sie ist
        } finally {
            vendorLoading.value = false
        }
    }, 250)
}

async function onFileSelected(file) {
    const f = Array.isArray(file) ? file[0] : file
    selectedFile.value = f || null
    rows.value = []
    if (!f) return
    parsing.value = true
    try {
        rows.value = await settlements.parseFile(f)
    } catch (e) {
        alerts.error(t('BankingView.settlement.parseError'))
    } finally {
        parsing.value = false
    }
}

async function saveAndMatch() {
    try {
        await settlements.uploadSettlement({
            provider: provider.value,
            vendorId: vendorId.value,
            file: selectedFile.value,
            rows: rows.value,
        })
        const m = await settlements.suggestMatch(props.transaction.id)
        if (m) {
            await enterBookStep(m)
        } else {
            step.value = 'nomatch'
        }
    } catch (e) {
        alerts.error(e.message)
    }
}

async function book() {
    try {
        await settlements.bookLine({
            bankTransactionId: props.transaction.id,
            settlementLineId: match.value.line_id,
            feeChartId: feeChartId.value,
            clearingChartId: clearingChartId.value,
            arIds: selectedArIds.value,
        })
        alerts.success(t('BankingView.settlement.bookSuccess'))
        emit('booked')
        show.value = false
    } catch (e) {
        alerts.error(e.message)
    }
}

function reset() {
    step.value = 'upload'
    provider.value = ''
    vendorId.value = null
    selectedFile.value = null
    parsing.value = false
    rows.value = []
    match.value = null
    feeChartId.value = null
    clearingChartId.value = null
    invoiceCandidates.value = []
    selectedArIds.value = []
    invoiceInfo.value = { found: false, ambiguous: false, gross: 0 }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}
function formatDate(dateStr) {
    if (!dateStr) return '—'
    return new Date(dateStr).toLocaleDateString('de-DE')
}
</script>
