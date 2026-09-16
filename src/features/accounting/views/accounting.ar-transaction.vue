<template>
    <NavbarView />
    <v-container fluid>
        <AccountingPageHeader :title="t('AccountingView.arTransaction.title')" />

        <v-row>
            <v-col cols="12">
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline"
                         class="mt-1 mb-2" :text="t('AccountingView.arTransaction.info')" />
            </v-col>
        </v-row>

        <v-row>
            <!-- Erfassungsformular -->
            <v-col cols="12" md="7" lg="6">
                <v-card>
                    <v-card-title class="text-subtitle-1">
                        <v-icon start>mdi-account-cash-outline</v-icon>
                        {{ t('AccountingView.arTransaction.formTitle') }}
                    </v-card-title>
                    <v-card-text>
                        <v-form ref="formRef" @submit.prevent="submit">
                            <!-- Kunde -->
                            <v-autocomplete
                                v-model="customerId"
                                :items="customerOptions"
                                :item-title="c => c.customernumber ? `${c.name} (${c.customernumber})` : c.name"
                                item-value="id"
                                :label="t('AccountingView.arTransaction.customer') + ' *'"
                                :rules="[requiredRule]"
                                density="comfortable" variant="outlined"
                                :loading="customerLoading" no-filter clearable
                                prepend-inner-icon="mdi-account"
                                :no-data-text="t('AccountingView.arTransaction.customerSearchHint')"
                                @update:search="onCustomerSearch"
                            />

                            <v-row dense>
                                <v-col cols="12" sm="6">
                                    <v-text-field
                                        v-model="form.invnumber"
                                        :label="t('AccountingView.arTransaction.invnumber') + ' *'"
                                        :rules="[requiredRule]"
                                        density="comfortable" variant="outlined"
                                        prepend-inner-icon="mdi-pound" />
                                </v-col>
                                <v-col cols="12" sm="3">
                                    <v-text-field
                                        v-model="form.transdate" type="date"
                                        :label="t('AccountingView.arTransaction.transdate')"
                                        density="comfortable" variant="outlined" />
                                </v-col>
                                <v-col cols="12" sm="3">
                                    <v-text-field
                                        v-model="form.duedate" type="date"
                                        :label="t('AccountingView.arTransaction.duedate')"
                                        density="comfortable" variant="outlined" />
                                </v-col>
                            </v-row>

                            <!-- Erlöskonto -->
                            <v-autocomplete
                                v-model="incomeAccount"
                                :items="accountOptions"
                                item-title="label"
                                item-value="id"
                                return-object
                                :label="t('AccountingView.arTransaction.incomeAccount') + ' *'"
                                :rules="[requiredRule]"
                                density="comfortable" variant="outlined"
                                :loading="accountLoading" no-filter clearable
                                prepend-inner-icon="mdi-bank-outline"
                                :hint="t('AccountingView.arTransaction.incomeAccountHint')" persistent-hint
                                :no-data-text="t('AccountingView.arTransaction.accountSearchHint')"
                                @update:search="onAccountSearch" />

                            <v-row dense class="mt-2">
                                <v-col cols="12" sm="4">
                                    <v-text-field
                                        v-model.number="form.net" type="number" min="0" step="0.01"
                                        :label="t('AccountingView.arTransaction.net') + ' *'"
                                        :rules="[positiveRule]"
                                        density="comfortable" variant="outlined"
                                        suffix="€" />
                                </v-col>
                                <v-col cols="12" sm="4">
                                    <v-select
                                        v-model.number="form.rate" :items="taxRateOptions"
                                        :label="t('AccountingView.arTransaction.taxRate')"
                                        density="comfortable" variant="outlined" />
                                </v-col>
                                <v-col cols="12" sm="4">
                                    <v-text-field
                                        :model-value="formatCurrency(tax)"
                                        :label="t('AccountingView.arTransaction.tax')"
                                        density="comfortable" variant="outlined" readonly disabled />
                                </v-col>
                            </v-row>

                            <v-text-field
                                v-model="form.notes"
                                :label="t('AccountingView.arTransaction.notes')"
                                density="comfortable" variant="outlined"
                                prepend-inner-icon="mdi-text" />

                            <!-- Brutto-Zusammenfassung -->
                            <v-alert type="success" variant="tonal" density="compact" class="mt-1 mb-3">
                                <div class="d-flex justify-space-between align-center">
                                    <span>{{ t('AccountingView.arTransaction.gross') }}</span>
                                    <span class="text-h6 font-weight-bold">{{ formatCurrency(gross) }}</span>
                                </div>
                            </v-alert>

                            <div class="d-flex gap-3">
                                <v-btn type="submit" color="primary" variant="elevated"
                                       :loading="saving" :disabled="!canSubmit">
                                    <v-icon start>mdi-check</v-icon>
                                    {{ t('AccountingView.arTransaction.book') }}
                                </v-btn>
                                <v-btn variant="outlined" @click="resetForm">
                                    <v-icon start>mdi-eraser</v-icon>
                                    {{ t('AccountingView.arTransaction.reset') }}
                                </v-btn>
                            </div>
                        </v-form>
                    </v-card-text>
                </v-card>

                <!-- Alternative: Rechnung mit Positionen -->
                <v-alert type="info" variant="tonal" density="compact" class="mt-3" icon="mdi-file-document-edit">
                    {{ t('AccountingView.arTransaction.invoiceHint') }}
                    <v-btn class="ml-2" size="small" variant="tonal" :to="{ name: 'invoice-new' }">
                        {{ t('AccountingView.arTransaction.toInvoice') }}
                    </v-btn>
                </v-alert>
            </v-col>

            <!-- Zuletzt gebuchte Debitorenbuchungen -->
            <v-col cols="12" md="5" lg="6">
                <v-card>
                    <v-card-title class="text-subtitle-1">{{ t('AccountingView.arTransaction.recentTitle') }}</v-card-title>
                    <v-data-table
                        :headers="entryHeaders"
                        :items="entries"
                        :loading="loadingEntries"
                        density="compact"
                        :items-per-page="10"
                        :no-data-text="t('AccountingView.arTransaction.noEntries')">
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
import { useRoute } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useAccounting } from '../composables/useAccounting.js'
import * as toasts from '@/core/utils/toasts.js'

const { t } = useI18n()
const route = useRoute()
const { searchArCustomers, searchAccounts, postArTransaction, fetchArTransactions } = useAccounting()

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

// ── Kundensuche ───────────────────────────────────────────────────
const customerId      = ref(null)
const customerOptions = ref([])
const customerLoading = ref(false)
let   customerTimer   = null

function onCustomerSearch(q) {
    clearTimeout(customerTimer)
    if (!q || q.length < 2) return
    customerTimer = setTimeout(async () => {
        customerLoading.value = true
        customerOptions.value = await searchArCustomers(q)
        customerLoading.value = false
    }, 300)
}

// Vorauswahl aus dem Kunden-Workflow ("Debitorenbuchung erfassen")
async function preselectCustomer() {
    const qid = parseInt(route.query.customer_id)
    if (!qid) return
    customerLoading.value = true
    const rows = await searchArCustomers('', qid)
    customerLoading.value = false
    if (rows.length) {
        customerOptions.value = rows
        customerId.value = rows[0].id
    }
}

// ── Kontensuche (nur Erlöskonten) ─────────────────────────────────
const incomeAccount  = ref(null)
const accountOptions = ref([])
const accountLoading = ref(false)
let   accountTimer   = null

function onAccountSearch(q) {
    clearTimeout(accountTimer)
    if (!q || q.length < 1) return
    accountTimer = setTimeout(async () => {
        accountLoading.value = true
        const rows = await searchAccounts(q, 'AR_amount')
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
    customerId.value && incomeAccount.value && form.value.invnumber && Number(form.value.net) > 0
)

const requiredRule = v => (!!v || v === 0) || t('AccountingView.arTransaction.required')
const positiveRule = v => (Number(v) > 0) || t('AccountingView.arTransaction.positive')

async function submit() {
    const { valid } = await formRef.value.validate()
    if (!valid || !canSubmit.value) return

    saving.value = true
    const result = await postArTransaction({
        customer_id: customerId.value,
        income_chart_id: incomeAccount.value.id,
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
        toasts.success(t('AccountingView.arTransaction.booked', { gross: formatCurrency(gross.value) }))
        resetForm()
        loadEntries()
    } else {
        toasts.error(result.text || t('AccountingView.arTransaction.bookError'))
    }
}

function resetForm() {
    form.value = { invnumber: '', transdate: todayISO(), duedate: '', net: null, rate: 19, notes: '' }
    // Der vorausgewählte Kunde bleibt stehen (Workflow), Suchtreffer werden geleert
    if (!route.query.customer_id) {
        customerId.value = null
        customerOptions.value = []
    }
    incomeAccount.value = null
    accountOptions.value = []
    formRef.value?.resetValidation?.()
}

// ── Historie ──────────────────────────────────────────────────────
const entries        = ref([])
const loadingEntries = ref(false)

async function loadEntries() {
    loadingEntries.value = true
    entries.value = await fetchArTransactions()
    loadingEntries.value = false
}

const entryHeaders = computed(() => [
    { title: t('AccountingView.arTransaction.colInvNumber'), key: 'invnumber', width: '120px' },
    { title: t('AccountingView.arTransaction.colCustomer'), key: 'customer_name' },
    { title: t('AccountingView.arTransaction.colDate'), key: 'transdate_fmt', width: '110px' },
    { title: t('AccountingView.arTransaction.colAmount'), key: 'amount', align: 'end', width: '110px' },
    { title: t('AccountingView.arTransaction.colOpen'), key: 'open_amount', align: 'end', width: '110px' }
])

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

onMounted(() => {
    preselectCustomer()
    loadEntries()
})
</script>
