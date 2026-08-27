<template>
    <NavbarView />
    <v-container fluid>
        <AccountingPageHeader :title="t('AccountingView.manual.title')" />

        <v-row>
            <v-col cols="12">
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline"
                         class="mt-1 mb-2" :text="t('AccountingView.manual.info')" />
            </v-col>
        </v-row>

        <v-row>
            <!-- Erfassungsformular -->
            <v-col cols="12" md="7" lg="6">
                <v-card>
                    <v-card-title class="text-subtitle-1">
                        <v-icon start>mdi-file-document-plus</v-icon>
                        {{ t('AccountingView.manual.formTitle') }}
                    </v-card-title>
                    <v-card-text>
                        <v-form ref="formRef" @submit.prevent="submit">
                            <!-- Lieferant -->
                            <v-autocomplete
                                v-model="vendorId"
                                :items="vendorOptions"
                                :item-title="v => v.vendornumber ? `${v.name} (${v.vendornumber})` : v.name"
                                item-value="id"
                                :label="t('AccountingView.manual.vendor') + ' *'"
                                :rules="[requiredRule]"
                                density="comfortable" variant="outlined"
                                :loading="vendorLoading" no-filter clearable
                                prepend-inner-icon="mdi-domain"
                                :no-data-text="t('AccountingView.manual.vendorSearchHint')"
                                @update:search="onVendorSearch"
                            />

                            <v-row dense>
                                <v-col cols="12" sm="6">
                                    <v-text-field
                                        v-model="form.invnumber"
                                        :label="t('AccountingView.manual.invnumber') + ' *'"
                                        :rules="[requiredRule]"
                                        density="comfortable" variant="outlined"
                                        prepend-inner-icon="mdi-pound" />
                                </v-col>
                                <v-col cols="12" sm="3">
                                    <v-text-field
                                        v-model="form.transdate" type="date"
                                        :label="t('AccountingView.manual.transdate')"
                                        density="comfortable" variant="outlined" />
                                </v-col>
                                <v-col cols="12" sm="3">
                                    <v-text-field
                                        v-model="form.duedate" type="date"
                                        :label="t('AccountingView.manual.duedate')"
                                        density="comfortable" variant="outlined" />
                                </v-col>
                            </v-row>

                            <!-- Aufwandskonto -->
                            <v-autocomplete
                                v-model="expenseAccount"
                                :items="accountOptions"
                                item-title="label"
                                item-value="id"
                                return-object
                                :label="t('AccountingView.manual.expenseAccount') + ' *'"
                                :rules="[requiredRule]"
                                density="comfortable" variant="outlined"
                                :loading="accountLoading" no-filter clearable
                                prepend-inner-icon="mdi-bank-outline"
                                :hint="t('AccountingView.manual.expenseAccountHint')" persistent-hint
                                :no-data-text="t('AccountingView.manual.accountSearchHint')"
                                @update:search="onAccountSearch" />

                            <v-row dense class="mt-2">
                                <v-col cols="12" sm="4">
                                    <v-text-field
                                        v-model.number="form.net" type="number" min="0" step="0.01"
                                        :label="t('AccountingView.manual.net') + ' *'"
                                        :rules="[positiveRule]"
                                        density="comfortable" variant="outlined"
                                        suffix="€" />
                                </v-col>
                                <v-col cols="12" sm="4">
                                    <v-select
                                        v-model.number="form.rate" :items="taxRateOptions"
                                        :label="t('AccountingView.manual.taxRate')"
                                        density="comfortable" variant="outlined" />
                                </v-col>
                                <v-col cols="12" sm="4">
                                    <v-text-field
                                        :model-value="formatCurrency(tax)"
                                        :label="t('AccountingView.manual.tax')"
                                        density="comfortable" variant="outlined" readonly disabled />
                                </v-col>
                            </v-row>

                            <v-text-field
                                v-model="form.notes"
                                :label="t('AccountingView.manual.notes')"
                                density="comfortable" variant="outlined"
                                prepend-inner-icon="mdi-text" />

                            <!-- Brutto-Zusammenfassung -->
                            <v-alert type="success" variant="tonal" density="compact" class="mt-1 mb-3">
                                <div class="d-flex justify-space-between align-center">
                                    <span>{{ t('AccountingView.manual.gross') }}</span>
                                    <span class="text-h6 font-weight-bold">{{ formatCurrency(gross) }}</span>
                                </div>
                            </v-alert>

                            <div class="d-flex gap-3">
                                <v-btn type="submit" color="primary" variant="elevated"
                                       :loading="saving" :disabled="!canSubmit">
                                    <v-icon start>mdi-check</v-icon>
                                    {{ t('AccountingView.manual.book') }}
                                </v-btn>
                                <v-btn variant="outlined" @click="resetForm">
                                    <v-icon start>mdi-eraser</v-icon>
                                    {{ t('AccountingView.manual.reset') }}
                                </v-btn>
                            </div>
                        </v-form>
                    </v-card-text>
                </v-card>

                <!-- Alternative: Beleg scannen -->
                <v-alert type="info" variant="tonal" density="compact" class="mt-3" icon="mdi-cloud-upload">
                    {{ t('AccountingView.manual.scanHint') }}
                    <v-btn class="ml-2" size="small" variant="tonal" :to="{ name: 'accounting-invoice-upload' }">
                        {{ t('AccountingView.manual.toScan') }}
                    </v-btn>
                </v-alert>
            </v-col>

            <!-- Zuletzt gebuchte Eingangsrechnungen -->
            <v-col cols="12" md="5" lg="6">
                <v-card>
                    <v-card-title class="text-subtitle-1">{{ t('AccountingView.manual.recentTitle') }}</v-card-title>
                    <v-data-table
                        :headers="invoiceHeaders"
                        :items="incomingInvoices"
                        :loading="loadingInvoices"
                        density="compact"
                        :items-per-page="10"
                        :no-data-text="t('AccountingView.manual.noInvoices')">
                        <template #[`item.amount`]="{ item }">{{ formatCurrency(item.amount) }}</template>
                        <template #[`item.open_amount`]="{ item }">
                            <span :class="Number(item.open_amount) <= 0 ? 'text-success' : 'text-error'">
                                {{ formatCurrency(item.open_amount) }}
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
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useAccounting } from '../composables/useAccounting.js'
import { useInvoiceUpload } from '../composables/useInvoiceUpload.js'
import * as toasts from '@/core/utils/toasts.js'

const { t } = useI18n()
const { searchVendors, searchAccounts, postIncomingInvoice } = useAccounting()
const { incomingInvoices, loadingInvoices, fetchIncomingInvoices } = useInvoiceUpload()

const formRef = ref(null)
const saving  = ref(false)

function todayISO() {
    // Ohne Zeitzone-Verschiebung das lokale Datum als YYYY-MM-DD
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = ref({
    invnumber: '',
    transdate: todayISO(),
    duedate: '',
    net: null,
    rate: 19,
    notes: ''
})

// ── Lieferantensuche ──────────────────────────────────────────────
const vendorId      = ref(null)
const vendorOptions = ref([])
const vendorLoading = ref(false)
let   vendorTimer   = null

function onVendorSearch(q) {
    clearTimeout(vendorTimer)
    if (!q || q.length < 2) return
    vendorTimer = setTimeout(async () => {
        vendorLoading.value = true
        vendorOptions.value = await searchVendors(q)
        vendorLoading.value = false
    }, 300)
}

// ── Kontensuche ───────────────────────────────────────────────────
const expenseAccount = ref(null)
const accountOptions = ref([])
const accountLoading = ref(false)
let   accountTimer   = null

function onAccountSearch(q) {
    clearTimeout(accountTimer)
    if (!q || q.length < 1) return
    accountTimer = setTimeout(async () => {
        accountLoading.value = true
        const rows = await searchAccounts(q)
        accountOptions.value = rows.map(a => ({ id: a.id, accno: a.accno, label: `${a.accno} ${a.description}` }))
        accountLoading.value = false
    }, 300)
}

// ── Betragslogik: Steuer und Brutto folgen aus Netto + Satz ───────
const taxRateOptions = [
    { title: '19 %', value: 19 },
    { title: '7 %', value: 7 },
    { title: '0 % (steuerfrei)', value: 0 }
]
const tax   = computed(() => Math.round(((Number(form.value.net) || 0) * (Number(form.value.rate) || 0) / 100) * 100) / 100)
const gross = computed(() => Math.round(((Number(form.value.net) || 0) + tax.value) * 100) / 100)

const canSubmit = computed(() =>
    vendorId.value && expenseAccount.value && form.value.invnumber && Number(form.value.net) > 0
)

const requiredRule = v => (!!v || v === 0) || t('AccountingView.manual.required')
const positiveRule = v => (Number(v) > 0) || t('AccountingView.manual.positive')

async function submit() {
    const { valid } = await formRef.value.validate()
    if (!valid || !canSubmit.value) return

    saving.value = true
    const result = await postIncomingInvoice({
        vendor_id: vendorId.value,
        expense_chart_id: expenseAccount.value.id,
        invnumber: form.value.invnumber,
        transdate: form.value.transdate,
        duedate: form.value.duedate || null,
        net: Number(form.value.net),
        rate: Number(form.value.rate),
        tax: tax.value,
        gross: gross.value,
        notes: form.value.notes || null
    })
    saving.value = false

    if (result.success) {
        toasts.success(t('AccountingView.manual.booked', { gross: formatCurrency(gross.value) }))
        resetForm()
        fetchIncomingInvoices()
    } else {
        toasts.error(result.text || t('AccountingView.manual.bookError'))
    }
}

function resetForm() {
    form.value = { invnumber: '', transdate: todayISO(), duedate: '', net: null, rate: 19, notes: '' }
    vendorId.value = null
    vendorOptions.value = []
    expenseAccount.value = null
    accountOptions.value = []
    formRef.value?.resetValidation?.()
}

const invoiceHeaders = computed(() => [
    { title: t('AccountingView.manual.colInvNumber'), key: 'invnumber', width: '120px' },
    { title: t('AccountingView.manual.colVendor'), key: 'vendor_name' },
    { title: t('AccountingView.manual.colAmount'), key: 'amount', align: 'end', width: '110px' },
    { title: t('AccountingView.manual.colOpen'), key: 'open_amount', align: 'end', width: '110px' }
])

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

onMounted(fetchIncomingInvoices)
</script>
