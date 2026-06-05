<template>
    <NavbarView />
    <v-container fluid>

        <!-- Header -->
        <v-row align="center" class="mb-3">
            <v-col>
                <h1 class="text-h5">
                    <v-icon start>mdi-cash-register</v-icon>
                    {{ selectedRegister ? selectedRegister.name : t('KasseView.title') }}
                    <span v-if="selectedRegister" class="text-body-2 text-disabled ml-2">
                        {{ t('KasseView.chartAccount') }} {{ selectedRegister.chart_accno }}
                    </span>
                </h1>
            </v-col>
            <v-col v-if="registers.length > 1" cols="auto">
                <v-select
                    v-model="selectedRegisterId"
                    :items="registers"
                    item-title="name"
                    item-value="id"
                    density="compact"
                    hide-details
                    variant="outlined"
                    style="min-width:220px"
                    prepend-inner-icon="mdi-cash-register"
                />
            </v-col>
            <v-col cols="auto">
                <v-btn
                    v-if="selectedRegister"
                    color="primary"
                    variant="tonal"
                    size="small"
                    @click="openNewTransaction()"
                >
                    <v-icon start>mdi-cash-plus</v-icon>
                    {{ t('KasseView.newTransaction') }}
                </v-btn>
                <v-progress-circular v-else-if="loading" indeterminate size="24" />
            </v-col>
        </v-row>

        <!-- Statistik-Karten -->
        <v-row v-if="selectedRegister" class="mb-3">
            <v-col cols="12" sm="4">
                <v-card :color="(selectedRegister.balance ?? 0) >= 0 ? 'primary' : 'error'" variant="tonal">
                    <v-card-text class="text-center py-3">
                        <div class="text-h5 font-weight-bold">{{ formatCurrency(selectedRegister.balance) }}</div>
                        <div class="text-body-2">{{ t('KasseView.balance') }}</div>
                        <div class="text-caption text-disabled mt-1">
                            {{ t('KasseView.chartAccount') }}: {{ selectedRegister.chart_accno }}
                            <span v-if="selectedRegister.last_transaction_date"> · {{ t('KasseView.lastEntry') }}: {{ formatDateShort(selectedRegister.last_transaction_date) }}</span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="4">
                <v-card color="success" variant="tonal">
                    <v-card-text class="text-center py-3">
                        <v-icon size="24" class="mb-1">mdi-arrow-down-bold</v-icon>
                        <div class="text-h6 font-weight-bold">{{ formatCurrency(selectedRegister.income_this_month) }}</div>
                        <div class="text-body-2">{{ t('KasseView.incomeThisMonth') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="4">
                <v-card color="warning" variant="tonal">
                    <v-card-text class="text-center py-3">
                        <v-icon size="24" class="mb-1">mdi-arrow-up-bold</v-icon>
                        <div class="text-h6 font-weight-bold">{{ formatCurrency(selectedRegister.expenses_this_month) }}</div>
                        <div class="text-body-2">{{ t('KasseView.expensesThisMonth') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Tabs -->
        <v-tabs v-if="selectedRegister" v-model="activeTab" color="primary" class="mb-4">
            <v-tab value="transactions" prepend-icon="mdi-format-list-bulleted">
                {{ t('KasseView.tabTransactions') }}
            </v-tab>
            <v-tab value="invoices" prepend-icon="mdi-file-document-outline">
                {{ t('KasseView.tabInvoices') }}
            </v-tab>
        </v-tabs>

        <v-window v-if="selectedRegister" v-model="activeTab">

            <!-- ── KASSENBUCHUNGEN ── -->
            <v-window-item value="transactions">
                <div class="tab-toolbar mb-2">
                    <v-btn-toggle v-model="typeFilter" mandatory density="compact" variant="outlined" rounded="lg">
                        <v-btn value="all" size="small">{{ t('KasseView.filterAll') }}</v-btn>
                        <v-btn value="income" size="small" color="success">{{ t('KasseView.filterIncome') }}</v-btn>
                        <v-btn value="expense" size="small" color="error">{{ t('KasseView.filterExpense') }}</v-btn>
                    </v-btn-toggle>
                    <v-spacer />
                    <v-text-field v-model="fromDate" :label="t('KasseView.fromDate')" type="date" density="compact" hide-details style="max-width:140px" />
                    <v-text-field v-model="toDate" :label="t('KasseView.toDate')" type="date" density="compact" hide-details style="max-width:140px" />
                </div>

                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        :headers="txHeaders"
                        :items="transactions"
                        :loading="txLoading"
                        :items-per-page="50"
                        density="compact"
                        hover
                    >
                        <template #item.transdate="{ item }">
                            <span class="text-no-wrap text-body-2">{{ formatDateShort(item.transdate) }}</span>
                        </template>
                        <template #item.amount="{ item }">
                            <span :class="item.amount >= 0 ? 'text-success font-weight-semibold' : 'text-error font-weight-semibold'">
                                {{ formatCurrency(item.amount) }}
                            </span>
                        </template>
                        <template #item.description="{ item }">
                            <div>
                                <div class="text-body-2">
                                    {{ item.description || item.reference || '—' }}
                                    <span v-if="item.partner_name" class="text-disabled text-caption"> · {{ item.partner_name }}</span>
                                </div>
                                <div v-if="item.gegenkonto_accno" class="text-caption text-disabled">
                                    {{ item.gegenkonto_accno }} {{ item.gegenkonto_description }}
                                </div>
                                <v-chip v-if="item.source_type !== 'gl'" size="x-small" variant="tonal" :color="item.source_type === 'ar' ? 'info' : 'warning'" class="mt-1">
                                    {{ item.source_type === 'ar' ? t('KasseView.sourceAR') : t('KasseView.sourceAP') }}
                                </v-chip>
                            </div>
                        </template>
                        <template #item.reference="{ item }">
                            <span class="text-body-2 text-disabled">{{ item.reference || '—' }}</span>
                        </template>
                        <template #item.document="{ item }">
                            <v-tooltip v-if="item.document_id" :text="item.document_name || t('KasseView.viewDocument')">
                                <template #activator="{ props }">
                                    <v-btn v-bind="props" icon="mdi-paperclip" size="x-small" variant="text" color="primary" @click="previewDocument(item.document_id)" />
                                </template>
                            </v-tooltip>
                            <v-tooltip v-else-if="item.source_type === 'gl'" :text="t('KasseView.uploadDocument')">
                                <template #activator="{ props }">
                                    <v-btn v-bind="props" icon="mdi-upload" size="x-small" variant="text" @click="triggerDocumentUpload(item)" />
                                </template>
                            </v-tooltip>
                        </template>
                        <template #item.actions="{ item }">
                            <v-btn
                                v-if="item.source_type === 'gl'"
                                icon="mdi-delete"
                                size="x-small"
                                variant="text"
                                color="error"
                                :title="t('KasseView.delete')"
                                @click="confirmDelete(item)"
                            />
                        </template>
                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('KasseView.noTransactions') }}</div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-window-item>

            <!-- ── OFFENE AUSGANGSRECHNUNGEN ── -->
            <v-window-item value="invoices">
                <div class="tab-toolbar mb-2">
                    <v-text-field
                        v-model="arSearch"
                        :label="t('KasseView.searchInvoice')"
                        density="compact"
                        hide-details
                        prepend-inner-icon="mdi-magnify"
                        clearable
                        style="max-width:320px"
                        @update:model-value="debouncedLoadOpenAr"
                    />
                </div>

                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        :headers="arHeaders"
                        :items="openAr"
                        :loading="arLoading"
                        :items-per-page="50"
                        density="compact"
                        hover
                    >
                        <template #item.transdate="{ item }">
                            <span class="text-no-wrap text-body-2">{{ formatDateShort(item.transdate) }}</span>
                        </template>
                        <template #item.duedate="{ item }">
                            <span class="text-no-wrap text-body-2" :class="isOverdue(item.duedate) ? 'text-error' : ''">
                                {{ formatDateShort(item.duedate) }}
                            </span>
                        </template>
                        <template #item.open_amount="{ item }">
                            <span class="font-weight-semibold">{{ formatCurrency(item.open_amount) }}</span>
                        </template>
                        <template #item.actions="{ item }">
                            <v-btn size="small" variant="tonal" color="success" @click="bookArAsCashDialog(item)">
                                <v-icon start>mdi-cash</v-icon>
                                {{ t('KasseView.bookAsCash') }}
                            </v-btn>
                        </template>
                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('KasseView.noOpenInvoices') }}</div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-window-item>

        </v-window>

        <!-- ── Dialog: Neue manuelle Buchung ── -->
        <v-dialog v-model="showTransactionDialog" max-width="540" persistent>
            <v-card>
                <v-card-title>{{ t('KasseView.newTransaction') }}</v-card-title>
                <v-card-text>
                    <v-btn-toggle
                        v-model="txData.type"
                        mandatory
                        density="compact"
                        variant="outlined"
                        rounded="lg"
                        color="primary"
                        class="mb-4"
                    >
                        <v-btn value="expense">
                            <v-icon start>mdi-arrow-up-bold</v-icon>
                            {{ t('KasseView.expense') }}
                        </v-btn>
                        <v-btn value="income">
                            <v-icon start>mdi-arrow-down-bold</v-icon>
                            {{ t('KasseView.income') }}
                        </v-btn>
                    </v-btn-toggle>

                    <v-text-field
                        v-model="txData.transdate"
                        :label="t('KasseView.date')"
                        type="date"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="txData.amount"
                        :label="t('KasseView.amount')"
                        type="number"
                        step="0.01"
                        min="0.01"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                        prepend-inner-icon="mdi-currency-eur"
                    />

                    <!-- Gegenkonto -->
                    <v-autocomplete
                        v-model="txData.counterChartId"
                        :items="counterCharts"
                        :item-title="c => `${c.accno} – ${c.description}`"
                        item-value="id"
                        :label="t('KasseView.counterAccount')"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                        clearable
                        :no-data-text="t('KasseView.noCharts')"
                    />

                    <v-text-field
                        v-model="txData.description"
                        :label="t('KasseView.description')"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="txData.reference"
                        :label="t('KasseView.reference')"
                        :placeholder="t('KasseView.referencePlaceholder')"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                    />
                    <v-file-input
                        v-model="txData.documentFile"
                        :label="t('KasseView.uploadDocument')"
                        density="compact"
                        variant="outlined"
                        accept="image/*,application/pdf"
                        prepend-icon=""
                        prepend-inner-icon="mdi-paperclip"
                        clearable
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showTransactionDialog = false">{{ t('KasseView.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="txSaving" @click="saveTransaction">{{ t('KasseView.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Dialog: Ausgangsrechnung bar verbuchen ── -->
        <v-dialog v-model="showArDialog" max-width="440" persistent>
            <v-card>
                <v-card-title>{{ t('KasseView.bookAsCash') }}</v-card-title>
                <v-card-text v-if="arDialogItem">
                    <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                        <div>{{ arDialogItem.invnumber }} · {{ arDialogItem.customer_name }}</div>
                        <div class="text-caption">{{ t('KasseView.openAmount') }}: {{ formatCurrency(arDialogItem.open_amount) }}</div>
                    </v-alert>
                    <v-text-field
                        v-model="arData.transdate"
                        :label="t('KasseView.date')"
                        type="date"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="arData.amount"
                        :label="t('KasseView.amount')"
                        type="number"
                        step="0.01"
                        min="0.01"
                        density="compact"
                        variant="outlined"
                        prepend-inner-icon="mdi-currency-eur"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showArDialog = false">{{ t('KasseView.cancel') }}</v-btn>
                    <v-btn color="success" :loading="arSaving" @click="confirmBookAr">
                        <v-icon start>mdi-cash</v-icon>
                        {{ t('KasseView.bookAsCash') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Dialog: Beleg-Vorschau ── -->
        <v-dialog v-model="showDocPreview" max-width="860">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <span>{{ t('KasseView.documentPreview') }}</span>
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" @click="showDocPreview = false" />
                </v-card-title>
                <v-card-text class="text-center pa-2">
                    <v-progress-circular v-if="docPreviewLoading" indeterminate class="my-8" />
                    <img
                        v-else-if="docPreviewMime && docPreviewMime.startsWith('image/')"
                        :src="`data:${docPreviewMime};base64,${docPreviewData}`"
                        style="max-width:100%; max-height:640px"
                    />
                    <iframe
                        v-else-if="docPreviewMime === 'application/pdf'"
                        :src="`data:application/pdf;base64,${docPreviewData}`"
                        style="width:100%; height:640px; border:none"
                    />
                </v-card-text>
            </v-card>
        </v-dialog>

    </v-container>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as alerts from '@/core/utils/alerts.js'
import Swal from 'sweetalert2'

const { t } = useI18n()
const API_URL = '/api/banking/'

// ── State ──────────────────────────────────────────────────────────────────

const loading            = ref(false)
const registers          = ref([])
const selectedRegisterId = ref(null)
const counterCharts      = ref([])    // Gegenkonten (Aufwand/Ertrag)
const transactions       = ref([])
const txLoading          = ref(false)
const openAr             = ref([])
const arLoading          = ref(false)
const arSearch           = ref('')
const typeFilter         = ref('all')
const fromDate           = ref('')
const toDate             = ref('')
const activeTab          = ref('transactions')

// ── Dialogs ────────────────────────────────────────────────────────────────

const showTransactionDialog = ref(false)
const showArDialog          = ref(false)
const showDocPreview        = ref(false)
const txSaving              = ref(false)
const arSaving              = ref(false)
const docPreviewLoading     = ref(false)
const docPreviewData        = ref('')
const docPreviewMime        = ref('')

const txData = reactive({
    type:           'expense',
    transdate:      new Date().toISOString().slice(0, 10),
    amount:         '',
    counterChartId: null,
    description:    '',
    reference:      '',
    documentFile:   null,
})

const arDialogItem = ref(null)
const arData = reactive({
    transdate: new Date().toISOString().slice(0, 10),
    amount:    '',
})

// ── Computed ────────────────────────────────────────────────────────────────

const selectedRegister = computed(() =>
    registers.value.find(r => r.id === selectedRegisterId.value)
)

const filteredCounterCharts = computed(() => {
    if (txData.type === 'expense') return counterCharts.value.filter(c => c.category === 'E')
    if (txData.type === 'income')  return counterCharts.value.filter(c => c.category === 'I')
    return counterCharts.value
})

const txHeaders = computed(() => [
    { title: t('KasseView.date'),        key: 'transdate',   width: '100px' },
    { title: t('KasseView.amount'),      key: 'amount',      width: '120px' },
    { title: t('KasseView.description'), key: 'description' },
    { title: t('KasseView.reference'),   key: 'reference',   width: '130px' },
    { title: '',                         key: 'document',    width: '48px',  sortable: false },
    { title: '',                         key: 'actions',     width: '48px',  sortable: false },
])

const arHeaders = computed(() => [
    { title: t('KasseView.invoice'),     key: 'invnumber',   width: '130px' },
    { title: t('KasseView.customer'),    key: 'customer_name' },
    { title: t('KasseView.invoiceDate'), key: 'transdate',   width: '100px' },
    { title: t('KasseView.dueDate'),     key: 'duedate',     width: '100px' },
    { title: t('KasseView.openAmount'),  key: 'open_amount', width: '120px' },
    { title: '',                         key: 'actions',     width: '160px', sortable: false },
])

// ── API ─────────────────────────────────────────────────────────────────────

async function loadRegisters() {
    loading.value = true
    try {
        const res = await axios.post(API_URL, { action: 'getCashRegisters' })
        if (res.data?.success) {
            registers.value = res.data.payload.registers
            if (registers.value.length > 0 && !selectedRegisterId.value) {
                selectedRegisterId.value = registers.value[0].id
            }
        }
    } finally {
        loading.value = false
    }
}

async function loadCharts() {
    const res = await axios.post(API_URL, { action: 'getCashCounterCharts' })
    if (res.data?.success) counterCharts.value = res.data.payload.charts
}

async function loadTransactions() {
    if (!selectedRegisterId.value) return
    txLoading.value = true
    try {
        const res = await axios.post(API_URL, {
            action:           'getCashTransactions',
            cash_register_id: selectedRegisterId.value,
            type_filter:      typeFilter.value,
            from_date:        fromDate.value || undefined,
            to_date:          toDate.value   || undefined,
        })
        if (res.data?.success) {
            transactions.value = res.data.payload.transactions ?? []
        }
    } finally {
        txLoading.value = false
    }
}

async function loadOpenAr() {
    if (!selectedRegisterId.value) return
    arLoading.value = true
    try {
        const res = await axios.post(API_URL, {
            action: 'getOpenArForCash',
            search: arSearch.value || undefined,
        })
        if (res.data?.success) openAr.value = res.data.payload.invoices ?? []
    } finally {
        arLoading.value = false
    }
}

let arSearchTimer = null
function debouncedLoadOpenAr() {
    clearTimeout(arSearchTimer)
    arSearchTimer = setTimeout(loadOpenAr, 350)
}

// ── Neue Buchung ─────────────────────────────────────────────────────────────

function openNewTransaction() {
    Object.assign(txData, {
        type:           'expense',
        transdate:      new Date().toISOString().slice(0, 10),
        amount:         '',
        counterChartId: null,
        description:    '',
        reference:      '',
        documentFile:   null,
    })
    showTransactionDialog.value = true
}

async function saveTransaction() {
    const amt = Number(txData.amount)
    if (!amt || amt <= 0) { alerts.error(t('KasseView.amountRequired')); return }
    if (!txData.counterChartId) { alerts.error(t('KasseView.counterAccountRequired')); return }

    txSaving.value = true
    try {
        let documentId = null
        const rawFile = Array.isArray(txData.documentFile) ? txData.documentFile[0] : txData.documentFile
        if (rawFile) {
            const base64 = await fileToBase64(rawFile)
            const upRes  = await axios.post(API_URL, {
                action: 'uploadCashDocument', filename: rawFile.name,
                mime_type: rawFile.type, file_base64: base64,
            })
            if (upRes.data?.success) documentId = upRes.data.payload.document_id
        }

        const res = await axios.post(API_URL, {
            action:           'createCashTransaction',
            cash_register_id: selectedRegisterId.value,
            transdate:        txData.transdate,
            amount:           amt,
            type:             txData.type,
            counter_chart_id: txData.counterChartId,
            description:      txData.description || undefined,
            reference:        txData.reference   || undefined,
            document_id:      documentId          || undefined,
        })

        if (res.data?.success) {
            alerts.success(t('KasseView.transactionCreated'))
            showTransactionDialog.value = false
            await loadRegisters()
            await loadTransactions()
        } else {
            alerts.error(res.data?.text || t('KasseView.saveError'))
        }
    } finally {
        txSaving.value = false
    }
}

// ── AR als Barzahlung buchen ─────────────────────────────────────────────────

function bookArAsCashDialog(invoice) {
    arDialogItem.value = invoice
    arData.transdate   = new Date().toISOString().slice(0, 10)
    arData.amount      = String(invoice.open_amount)
    showArDialog.value = true
}

async function confirmBookAr() {
    const amt = Number(arData.amount)
    if (!amt || amt <= 0) { alerts.error(t('KasseView.amountRequired')); return }

    arSaving.value = true
    try {
        const res = await axios.post(API_URL, {
            action:           'bookArAsCash',
            cash_register_id: selectedRegisterId.value,
            ar_id:            arDialogItem.value.id,
            amount:           amt,
            transdate:        arData.transdate,
        })
        if (res.data?.success) {
            alerts.success(t('KasseView.transactionCreated'))
            showArDialog.value = false
            await loadRegisters()
            await loadTransactions()
            await loadOpenAr()
        } else {
            alerts.error(res.data?.text || t('KasseView.saveError'))
        }
    } finally {
        arSaving.value = false
    }
}

// ── Buchung löschen ────────────────────────────────────────────────────────────

async function confirmDelete(item) {
    const result = await Swal.fire({
        title: t('KasseView.deleteConfirm'), icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#d33',
        confirmButtonText: t('KasseView.delete'), cancelButtonText: t('KasseView.cancel'),
    })
    if (!result.isConfirmed) return

    const res = await axios.post(API_URL, { action: 'deleteCashTransaction', gl_id: item.gl_id })
    if (res.data?.success) {
        alerts.success(t('KasseView.transactionDeleted'))
        await loadRegisters()
        await loadTransactions()
    }
}

// ── Beleg nachträglich hochladen ─────────────────────────────────────────────

function triggerDocumentUpload(txItem) {
    const input  = document.createElement('input')
    input.type   = 'file'
    input.accept = 'image/*,application/pdf'
    input.onchange = async (e) => {
        const file = e.target.files[0]
        if (!file) return
        const base64  = await fileToBase64(file)
        const upRes   = await axios.post(API_URL, {
            action: 'uploadCashDocument', filename: file.name, mime_type: file.type, file_base64: base64,
        })
        if (!upRes.data?.success) { alerts.error(t('KasseView.uploadError')); return }
        const linkRes = await axios.post(API_URL, {
            action: 'linkDocumentToCashTransaction', gl_id: txItem.gl_id,
            document_id: upRes.data.payload.document_id,
        })
        if (linkRes.data?.success) {
            alerts.success(t('KasseView.documentUploaded'))
            await loadTransactions()
        }
    }
    input.click()
}

// ── Beleg-Vorschau ─────────────────────────────────────────────────────────

async function previewDocument(documentId) {
    showDocPreview.value    = true
    docPreviewLoading.value = true
    docPreviewData.value    = ''
    docPreviewMime.value    = ''
    try {
        const res = await axios.post(API_URL, { action: 'getCashDocumentContent', document_id: documentId })
        if (res.data?.success) {
            docPreviewData.value = res.data.payload.content_base64
            docPreviewMime.value = res.data.payload.mime_type
        }
    } finally {
        docPreviewLoading.value = false
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────

function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload  = e => resolve(e.target.result.split(',')[1])
        reader.onerror = reject
        reader.readAsDataURL(file)
    })
}

function formatCurrency(v)  { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v || 0) }
function formatDateShort(d) { return d ? new Date(d).toLocaleDateString('de-DE') : '—' }
function isOverdue(d)       { return d && new Date(d) < new Date() }

// ── Watchers & Init ────────────────────────────────────────────────────────

watch(selectedRegisterId, () => {
    loadTransactions()
    if (activeTab.value === 'invoices') loadOpenAr()
})
watch(typeFilter, loadTransactions)
watch(fromDate,   loadTransactions)
watch(toDate,     loadTransactions)
watch(activeTab,  (tab) => { if (tab === 'invoices') loadOpenAr() })
watch(() => txData.type, () => { txData.counterChartId = null })

onMounted(() => {
    loadRegisters()
    loadCharts()
})
</script>

<style scoped>
.tab-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
</style>
