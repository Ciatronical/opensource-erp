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

                <v-btn
                    v-if="isSumupTransaction"
                    color="primary"
                    variant="tonal"
                    class="mb-4"
                    prepend-icon="mdi-cloud-download-outline"
                    :loading="sumupSyncing"
                    @click="syncFromSumup"
                >
                    {{ t('BankingView.settlement.sumupFetch') }}
                </v-btn>

                <!-- Bereits hochgeladene, noch offene Abrechnungen: eine falsche
                     Datei lässt sich hier wieder löschen, sonst würde sie bei
                     jedem Öffnen erneut als Treffer vorgeschlagen. -->
                <v-card v-if="uploaded.length > 0" variant="outlined" rounded="lg" class="mb-4">
                    <div class="text-overline px-3 pt-2">{{ t('BankingView.settlement.uploadedTitle') }}</div>
                    <v-list density="compact" class="py-0">
                        <v-list-item v-for="u in uploaded" :key="u.id">
                            <v-list-item-title class="text-body-2">
                                <v-icon v-if="u.document_id" size="x-small" class="mr-1" :title="t('BankingView.settlement.hasFile')">mdi-paperclip</v-icon>
                                <span class="font-weight-medium">{{ u.provider }}</span>
                                <span v-if="u.vendor_name" class="text-medium-emphasis"> · {{ u.vendor_name }}</span>
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                {{ formatDate(u.period_from) }} – {{ formatDate(u.period_to) }}
                                · {{ t('BankingView.settlement.uploadedLines', { open: openLineCount(u), total: (u.lines || []).length }) }}
                                · {{ formatCurrency(u.total_net) }}
                            </v-list-item-subtitle>
                            <template #append>
                                <v-btn
                                    icon="mdi-delete-outline"
                                    size="small"
                                    variant="text"
                                    color="error"
                                    :disabled="openLineCount(u) !== (u.lines || []).length"
                                    :title="t('BankingView.settlement.deleteSettlement')"
                                    @click="removeUploaded(u)"
                                />
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>

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
                                <td>
                                    {{ formatDate(r.payout_date) }}
                                    <span v-if="r.reference" class="text-medium-emphasis ml-1">{{ r.reference }}</span>
                                </td>
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
                    <span v-if="match?.reference" class="text-medium-emphasis">· {{ match.reference }}</span>
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

                <!-- Je Kartenzahlung: Betrag → zugeordnete Rechnung (vorbelegt) -->
                <template v-else-if="paymentRows.length > 0">
                    <v-alert v-if="invoiceInfo.unmatched > 0" type="warning" variant="tonal" density="compact" class="mb-2">
                        {{ t('BankingView.settlement.paymentsUnmatched', { count: invoiceInfo.unmatched }) }}
                    </v-alert>
                    <v-table density="compact" class="text-caption mb-1">
                        <tbody>
                            <tr v-for="(p, i) in paymentRows" :key="p.code || i" :class="{ 'bg-orange-lighten-5': !p.invoice }">
                                <td style="width:34px">
                                    <v-checkbox-btn
                                        v-if="p.invoice"
                                        v-model="selectedArIds"
                                        :value="p.invoice.ar_id"
                                        density="compact"
                                        hide-details
                                    />
                                    <v-icon v-else size="small" color="warning">mdi-help-circle-outline</v-icon>
                                </td>
                                <td class="text-no-wrap text-medium-emphasis">
                                    {{ t('BankingView.settlement.cardPayment') }} {{ formatDateTime(p.timestamp) }}
                                </td>
                                <td class="text-right text-no-wrap font-weight-medium">{{ formatCurrency(p.gross) }}</td>
                                <td class="text-medium-emphasis px-1">→</td>
                                <td v-if="p.invoice">
                                    <span class="font-weight-medium">{{ p.invoice.invnumber }}</span>
                                    <span class="text-medium-emphasis"> · {{ p.invoice.customer_name }}</span>
                                    <span v-if="p.invoice.transdate" class="text-medium-emphasis"> · {{ formatDate(p.invoice.transdate) }}</span>
                                </td>
                                <td v-else class="text-warning">{{ t('BankingView.settlement.paymentNoInvoice') }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <!-- Weitere offene Rechnungen nur, wenn eine Zahlung ohne Treffer ist -->
                    <template v-if="otherCandidates.length > 0 && (invoiceInfo.unmatched > 0 || showOthers)">
                        <div class="text-caption text-medium-emphasis mt-2 mb-1">{{ t('BankingView.settlement.otherInvoices') }}</div>
                    </template>
                    <v-btn v-else-if="otherCandidates.length > 0" variant="text" size="x-small" class="px-1" @click="showOthers = true">
                        {{ t('BankingView.settlement.showOtherInvoices', { count: otherCandidates.length }) }}
                    </v-btn>
                </template>

                <v-alert v-else-if="invoiceInfo.ambiguous" type="info" variant="tonal" density="compact" class="mb-2">
                    {{ t('BankingView.settlement.invoicesAmbiguous') }}
                </v-alert>
                <v-alert v-else-if="!invoiceInfo.found && invoiceCandidates.length > 0" type="warning" variant="tonal" density="compact" class="mb-2">
                    {{ t('BankingView.settlement.invoicesNotFound') }}
                </v-alert>
                <v-alert v-else-if="invoiceCandidates.length === 0" type="warning" variant="tonal" density="compact" class="mb-2">
                    {{ t('BankingView.settlement.invoicesNone') }}
                </v-alert>

                <v-table v-if="manualCandidates.length > 0" density="compact" class="text-caption mb-1">
                    <tbody>
                        <tr v-for="inv in manualCandidates" :key="inv.ar_id">
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

                <!-- Buchungsvorschau: exakt die Hauptbuch-Zeilen, die „Buchen" schreibt
                     (kommt aus derselben Backend-Logik wie die Buchung selbst). -->
                <div class="text-overline mt-4 mb-1">{{ t('BankingView.settlement.previewTitle') }}</div>
                <v-progress-linear v-if="previewLoading" indeterminate class="mb-2" />
                <template v-else-if="preview">
                    <div class="text-caption text-medium-emphasis mb-1">
                        {{ formatDate(preview.transdate) }} · {{ preview.description }}
                    </div>
                    <v-table density="compact" class="text-caption mb-1">
                        <thead>
                            <tr>
                                <th>{{ t('BankingView.settlement.previewAccount') }}</th>
                                <th>{{ t('BankingView.settlement.previewText') }}</th>
                                <th class="text-right">{{ t('BankingView.settlement.previewDebit') }}</th>
                                <th class="text-right">{{ t('BankingView.settlement.previewCredit') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(l, i) in preview.legs" :key="i">
                                <td class="text-truncate preview-account" :title="l.accno + ' ' + l.description">
                                    <span class="font-weight-medium">{{ l.accno }}</span> {{ l.description }}
                                </td>
                                <td class="text-medium-emphasis text-truncate preview-memo" :title="l.memo">{{ l.memo }}</td>
                                <td class="text-right text-no-wrap">{{ l.debit ? formatCurrency(l.debit) : '' }}</td>
                                <td class="text-right text-no-wrap">{{ l.credit ? formatCurrency(l.credit) : '' }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td colspan="2">
                                    <v-icon size="x-small" :color="preview.balanced ? 'success' : 'error'" class="mr-1">
                                        {{ preview.balanced ? 'mdi-check-circle' : 'mdi-alert-circle' }}
                                    </v-icon>{{ t('BankingView.settlement.previewTotal') }}
                                </td>
                                <td class="text-right text-no-wrap">{{ formatCurrency(previewDebitSum) }}</td>
                                <td class="text-right text-no-wrap">{{ formatCurrency(previewCreditSum) }}</td>
                            </tr>
                        </tfoot>
                    </v-table>
                </template>
                <v-alert v-else-if="previewError" type="warning" variant="tonal" density="compact" class="text-caption">
                    {{ previewError }}
                </v-alert>
                <div v-else class="text-caption text-medium-emphasis">{{ t('BankingView.settlement.previewPending') }}</div>
            </v-card-text>

            <!-- Schritt: kein Match -->
            <v-card-text v-else-if="step === 'nomatch'">
                <v-alert type="warning" variant="tonal" density="compact" :class="{ 'mb-3': isSumupTransaction }">
                    {{ t('BankingView.settlement.noMatch') }}
                </v-alert>
                <v-btn
                    v-if="isSumupTransaction"
                    color="primary"
                    variant="tonal"
                    size="small"
                    prepend-icon="mdi-cloud-download-outline"
                    :loading="sumupSyncing"
                    @click="syncFromSumup"
                >
                    {{ t('BankingView.settlement.sumupFetch') }}
                </v-btn>
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
                    v-if="step === 'book' || step === 'nomatch'"
                    variant="text"
                    prepend-icon="mdi-file-upload-outline"
                    @click="backToUpload"
                >
                    {{ t('BankingView.settlement.otherFile') }}
                </v-btn>

                <v-btn
                    v-if="step === 'book'"
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
import { ref, computed, watch } from 'vue'
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
const invoiceInfo = ref({ found: false, ambiguous: false, gross: 0, unmatched: 0 })
const invoicesLoading = ref(false)
// Je Kartenzahlung die zugeordnete Rechnung (API / Transaktionsbericht)
const paymentRows = ref([])
const showOthers = ref(false)
// Offene Rechnungen ohne zugeordnete Zahlung (manuelle Wahl)
const otherCandidates = computed(() => {
    const matched = new Set(paymentRows.value.map(p => p.invoice?.ar_id).filter(Boolean))
    return invoiceCandidates.value.filter(inv => !matched.has(inv.ar_id))
})
// Liste mit Checkboxen: ohne Einzelzahlungen alle Kandidaten, sonst nur die
// uebrigen (und die auch nur, wenn eine Zahlung keinen Treffer hat oder auf Wunsch)
const manualCandidates = computed(() => {
    if (paymentRows.value.length === 0) return invoiceCandidates.value
    return (invoiceInfo.value.unmatched > 0 || showOthers.value) ? otherCandidates.value : []
})
const preview = ref(null)          // Buchungsvorschau (Hauptbuch-Zeilen)
const previewLoading = ref(false)
const previewError = ref('')
const previewDebitSum = computed(() => (preview.value?.legs || []).reduce((s, l) => s + Number(l.debit || 0), 0))
const previewCreditSum = computed(() => (preview.value?.legs || []).reduce((s, l) => s + Number(l.credit || 0), 0))

// Vorschau neu laden, sobald sich im Buchen-Schritt Konten oder Rechnungs-
// auswahl aendern (kurz entprellt, Checkboxen loesen schnell hintereinander aus).
let previewTimer = null
watch([step, feeChartId, clearingChartId, selectedArIds, match], () => {
    clearTimeout(previewTimer)
    if (step.value !== 'book' || !match.value) { preview.value = null; previewError.value = ''; return }
    previewTimer = setTimeout(loadPreview, 250)
}, { deep: true })

async function loadPreview() {
    if (!feeChartId.value) { preview.value = null; previewError.value = ''; return }
    previewLoading.value = true
    try {
        preview.value = await settlements.previewBooking({
            bankTransactionId: props.transaction.id,
            settlementLineId: match.value.line_id,
            feeChartId: feeChartId.value,
            clearingChartId: clearingChartId.value,
            arIds: selectedArIds.value,
        })
        previewError.value = ''
    } catch (e) {
        preview.value = null
        previewError.value = e.message
    } finally {
        previewLoading.value = false
    }
}
const uploaded = ref([])   // bereits hochgeladene Abrechnungen mit offenen Zeilen

function openLineCount(u) {
    return (u.lines || []).filter(l => l.status === 'open').length
}

async function loadUploaded() {
    try {
        uploaded.value = await settlements.fetchSettlements({ onlyOpen: true })
    } catch (e) {
        uploaded.value = []
    }
}

// Falsche Datei entfernen — gebuchte Abrechnungen lehnt das Backend ab. Die
// hochgeladene Datei wird mitgelöscht, wenn sie nur zu dieser Abrechnung gehört.
async function removeUploaded(u) {
    const res = await alerts.warning(
        t(u.document_id ? 'BankingView.settlement.deleteConfirmFile' : 'BankingView.settlement.deleteConfirm', { provider: u.provider }),
        t('BankingView.settlement.deleteSettlement'),
        t('BankingView.settlement.deleteSettlement'),
        t('BankingView.settlement.cancel')
    )
    if (!res.isConfirmed) return
    try {
        await settlements.deleteSettlement(u.id)
        alerts.success(t('BankingView.settlement.deleteSuccess'))
        await loadUploaded()
    } catch (e) {
        alerts.error(e.message)
    }
}

// Aus Buchen/Kein-Treffer zurück zum Upload (z. B. falsche Datei erwischt).
async function backToUpload() {
    match.value = null
    rows.value = []
    selectedFile.value = null
    step.value = 'upload'
    await loadUploaded()
}

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
        await Promise.all([searchVendors(''), loadUploaded()])
        if (m) {
            if (m.vendor_id) vendorId.value = m.vendor_id
            await enterBookStep(m)
        } else if (isSumupTransaction.value) {
            // SumUp-Gutschrift ohne gespeicherte Auszahlung: direkt per API holen
            await syncFromSumup()
        }
    } catch (e) {
        alerts.error(e.message)
    }
}

// Verwendungszweck "SUMUP PID1314750 PAYOUT 310726" → Auszahlung per API abrufbar
const isSumupTransaction = computed(() =>
    /SUMUP/i.test(String(props.transaction?.purpose || '') + ' ' + String(props.transaction?.remote_name || ''))
)
const sumupSyncing = ref(false)

async function syncFromSumup() {
    sumupSyncing.value = true
    try {
        const r = await settlements.syncSumupPayouts(props.transaction.id)
        if (r.match) {
            if (r.match.vendor_id) vendorId.value = r.match.vendor_id
            alerts.success(t('BankingView.settlement.sumupSynced', { inserted: r.inserted, skipped: r.skipped }))
            await enterBookStep(r.match)
        } else {
            alerts.warning(t('BankingView.settlement.sumupNoPayout', { from: formatDate(r.window?.from), to: formatDate(r.window?.to) }))
            await loadUploaded()
        }
    } catch (e) {
        alerts.error(e.message)
    } finally {
        sumupSyncing.value = false
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
        paymentRows.value = res.transactions || []
        showOthers.value = false
        // Vorbelegung: alle gefundenen Rechnungen sind angehakt — bestätigen reicht
        selectedArIds.value = (res.invoices || []).map(i => i.ar_id)
        invoiceInfo.value = { found: !!res.found, ambiguous: !!res.ambiguous, gross: res.gross, unmatched: res.unmatched_count || 0 }
    } catch (e) {
        invoiceCandidates.value = []
        paymentRows.value = []
        selectedArIds.value = []
        invoiceInfo.value = { found: false, ambiguous: false, gross: m.gross, unmatched: 0 }
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
        const up = await settlements.uploadSettlement({
            provider: provider.value,
            vendorId: vendorId.value,
            file: selectedFile.value,
            rows: rows.value,
        })
        // Überlappende Berichte: bereits bekannte Auszahlungen wurden übersprungen
        if (up?.skipped > 0) {
            alerts.info(t('BankingView.settlement.uploadSkipped', { inserted: up.inserted || 0, skipped: up.skipped }))
        }
        const m = await settlements.suggestMatch(props.transaction.id)
        if (m) {
            await enterBookStep(m)
        } else {
            await loadUploaded()
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
    invoiceInfo.value = { found: false, ambiguous: false, gross: 0, unmatched: 0 }
    paymentRows.value = []
    showOthers.value = false
    uploaded.value = []
    preview.value = null
    previewError.value = ''
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}
function formatDate(dateStr) {
    if (!dateStr) return '—'
    return new Date(dateStr).toLocaleDateString('de-DE')
}
function formatDateTime(ts) {
    if (!ts) return ''
    const d = new Date(String(ts).replace(' ', 'T'))
    if (isNaN(d.getTime())) return String(ts)
    return d.toLocaleDateString('de-DE') + ' ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
}
</script>

<style scoped>
/* Buchungsvorschau: Konto und Text kürzen, damit Soll/Haben im 680px-Dialog sichtbar bleiben */
.preview-account { max-width: 230px; }
.preview-memo    { max-width: 190px; }
</style>
