<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-2">{{ t('AccountingView.chartOfAccounts.title') }}</h1>
                <p class="text-medium-emphasis">{{ t('AccountingView.chartOfAccounts.subtitle') }}</p>
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mt-1 mb-2" :text="t('AccountingView.chartOfAccounts.info')" />
            </v-col>
        </v-row>

        <!-- Filterleiste -->
        <v-row>
            <v-col cols="12" sm="6" md="4">
                <v-text-field v-model="searchQuery" :label="t('AccountingView.chartOfAccounts.search')"
                              prepend-inner-icon="mdi-magnify" density="compact" variant="outlined"
                              hide-details clearable @update:model-value="onSearch" />
            </v-col>
            <v-col cols="12" sm="6" md="4">
                <v-select v-model="categoryFilter" :items="categoryFilterItems" :label="t('AccountingView.chartOfAccounts.category')"
                          density="compact" variant="outlined" hide-details clearable
                          @update:model-value="loadAccounts" />
            </v-col>
        </v-row>

        <!-- Konten-Tabelle -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-data-table
                    :headers="headers"
                    :items="accounts"
                    :loading="loading"
                    density="compact"
                    :items-per-page="25"
                    :no-data-text="t('AccountingView.chartOfAccounts.noData')"
                    @click:row="(_, { item }) => openEdit(item)"
                    class="row-clickable">
                    <template #[`item.category`]="{ item }">
                        <v-chip size="x-small" variant="tonal">{{ categoryLabel(item.category) }}</v-chip>
                    </template>
                    <template #[`item.taxkey`]="{ item }">
                        <v-chip v-if="item.taxkey_missing" size="x-small" color="error" variant="tonal">
                            <v-icon start size="x-small">mdi-alert</v-icon>
                            {{ t('AccountingView.chartOfAccounts.taxkeyMissing') }}
                        </v-chip>
                        <v-chip v-else-if="Number(item.taxkey_count) > 0" size="x-small" color="success" variant="tonal">
                            {{ t('AccountingView.chartOfAccounts.taxkeyCount', { n: item.taxkey_count }) }}
                        </v-chip>
                        <span v-else class="text-disabled">–</span>
                    </template>
                    <template #[`item.invalid`]="{ item }">
                        <v-icon v-if="item.invalid" color="warning" size="small">mdi-eye-off</v-icon>
                    </template>
                    <template #[`item.actions`]="{ item }">
                        <v-btn icon="mdi-pencil" size="small" variant="text" @click.stop="openEdit(item)" />
                    </template>
                </v-data-table>
            </v-col>
        </v-row>

        <!-- Bearbeiten-Dialog -->
        <v-dialog v-model="editDialog" max-width="1000" scrollable>
            <v-card v-if="form">
                <v-card-title class="d-flex align-center">
                    <v-icon start>mdi-bank</v-icon>
                    {{ t('AccountingView.chartOfAccounts.editTitle') }}
                    <span class="ml-2 text-medium-emphasis text-body-1">{{ form.accno }} – {{ form.description }}</span>
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" @click="editDialog = false" />
                </v-card-title>
                <v-divider />

                <v-card-text>
                    <!-- Grundeinstellungen -->
                    <h3 class="text-subtitle-1 font-weight-bold mb-3">{{ t('AccountingView.chartOfAccounts.basics') }}</h3>
                    <v-row dense>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model="form.accno" :label="t('AccountingView.chartOfAccounts.accno')"
                                          density="comfortable" variant="outlined" hide-details />
                        </v-col>
                        <v-col cols="12" sm="8">
                            <v-text-field v-model="form.description" :label="t('AccountingView.chartOfAccounts.description')"
                                          density="comfortable" variant="outlined" hide-details />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <!-- Bebuchte Konten duerfen nicht zur Ueberschrift werden -->
                            <v-select v-model="form.charttype" :items="charttypeItems"
                                      :label="t('AccountingView.chartOfAccounts.charttype')"
                                      :disabled="linkLocked"
                                      :append-inner-icon="linkLocked ? 'mdi-lock-outline' : undefined"
                                      density="comfortable" variant="outlined" hide-details />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-select v-model="form.category" :items="categoryItems"
                                      :label="t('AccountingView.chartOfAccounts.category')"
                                      density="comfortable" variant="outlined" hide-details />
                        </v-col>
                        <v-col cols="12" sm="4" class="d-flex align-center">
                            <v-checkbox v-model="form.invalid" :label="t('AccountingView.chartOfAccounts.invalid')"
                                        density="comfortable" hide-details />
                        </v-col>
                    </v-row>

                    <!-- Ueberschriften gliedern den Kontenrahmen nur, auf sie wird nicht
                         gebucht: weder Steuerschluessel noch Verknuepfung noch Auswertung -->
                    <v-alert v-if="!isAccount" type="info" variant="tonal" density="comfortable"
                             class="mt-4" icon="mdi-format-title"
                             :text="t('AccountingView.chartOfAccounts.headingHint')" />

                    <template v-if="isAccount">
                    <!-- Steuerautomatik & UStVA -->
                    <div class="d-flex align-center mt-4 mb-2">
                        <h3 class="text-subtitle-1 font-weight-bold">{{ t('AccountingView.chartOfAccounts.taxAutomatic') }}</h3>
                        <v-spacer />
                        <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-plus" @click="addTaxkeyRow">
                            {{ t('AccountingView.chartOfAccounts.addTaxkey') }}
                        </v-btn>
                    </div>
                    <v-alert type="info" variant="tonal" density="compact" class="mb-3" :text="t('AccountingView.chartOfAccounts.taxHint')" />

                    <v-table density="comfortable" class="taxkey-table">
                        <thead>
                            <tr>
                                <th>{{ t('AccountingView.chartOfAccounts.taxkey') }}</th>
                                <th style="width: 210px">{{ t('AccountingView.chartOfAccounts.validFrom') }}</th>
                                <th style="width: 170px">{{ t('AccountingView.chartOfAccounts.ustva') }}</th>
                                <th style="width: 56px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in form.taxkeys" :key="row._key">
                                <td>
                                    <v-select v-model="row.tax_id" :items="taxSelectItems"
                                              item-title="label" item-value="id"
                                              density="comfortable" variant="outlined" hide-details
                                              :placeholder="t('AccountingView.chartOfAccounts.selectTaxkey')" />
                                </td>
                                <td>
                                    <!-- Das native Datumsfeld braucht Platz fuer den Kalender-Button,
                                         sonst wird die Jahreszahl abgeschnitten. -->
                                    <v-text-field v-model="row.startdate" type="date"
                                                  density="comfortable" variant="outlined" hide-details
                                                  class="date-field" />
                                </td>
                                <td>
                                    <!-- Kennzahlen kommen aus tax.report_variables, freie Eingabe
                                         waere in der UStVA nicht auswertbar -->
                                    <v-select v-model="row.pos_ustva" :items="ustvaItems"
                                              item-title="label" item-value="id"
                                              :item-props="ustvaItemProps" clearable
                                              density="comfortable" variant="outlined" hide-details
                                              :placeholder="t('AccountingView.chartOfAccounts.ustvaPlaceholder')" />
                                </td>
                                <td class="text-center">
                                    <v-btn icon="mdi-delete" size="small" variant="text" color="error"
                                           :title="t('AccountingView.chartOfAccounts.removeTaxkey')"
                                           @click="removeTaxkeyRow(idx)" />
                                </td>
                            </tr>
                            <tr v-if="form.taxkeys.length === 0">
                                <td colspan="4" class="text-center text-disabled py-4">
                                    {{ t('AccountingView.chartOfAccounts.noTaxkeys') }}
                                </td>
                            </tr>
                        </tbody>
                    </v-table>

                    <!-- Kontenverknüpfung: bestimmt, in welchen Buchungsmasken das Konto auftaucht -->
                    <h3 class="text-subtitle-1 font-weight-bold mt-6 mb-1">{{ t('AccountingView.chartOfAccounts.usage') }}</h3>
                    <p class="text-caption text-medium-emphasis mb-2">{{ t('AccountingView.chartOfAccounts.usageHint') }}</p>

                    <v-alert v-if="!form.orphaned" type="info" variant="tonal" density="compact" class="mb-3"
                             icon="mdi-lock-outline" :text="t('AccountingView.chartOfAccounts.lockedPosted')" />
                    <v-alert v-if="form.link_unmapped" type="warning" variant="tonal" density="compact" class="mb-3"
                             icon="mdi-alert-outline"
                             :text="t('AccountingView.chartOfAccounts.linkUnmapped', { link: form.link_original })" />

                    <v-row dense>
                        <!-- Sammelkonto: schliesst jede Einzelauswahl aus -->
                        <v-col cols="12" md="5">
                            <v-card variant="outlined" class="pa-3 h-100">
                                <div class="text-body-2 font-weight-bold mb-1">
                                    {{ t('AccountingView.chartOfAccounts.summaryAccount') }}
                                </div>
                                <v-radio-group v-model="form.summary_account" density="compact" hide-details
                                               :disabled="linkLocked" @update:model-value="onSummaryChange">
                                    <v-radio v-for="opt in summaryItems" :key="opt.value"
                                             :label="opt.title" :value="opt.value" />
                                </v-radio-group>
                            </v-card>
                        </v-col>

                        <!-- Einzelauswahl je Buchungsmaske -->
                        <v-col cols="12" md="7">
                            <v-card variant="outlined" class="pa-3 h-100"
                                    :class="{ 'opacity-60': dropdownsDisabled }">
                                <div class="text-body-2 font-weight-bold mb-1">
                                    {{ t('AccountingView.chartOfAccounts.dropdowns') }}
                                </div>
                                <v-row dense>
                                    <v-col cols="12" sm="6">
                                        <div class="text-caption font-weight-bold">
                                            {{ t('AccountingView.chartOfAccounts.receivables') }}
                                        </div>
                                        <v-radio-group v-model="form.ar_dropdown" density="compact" hide-details
                                                       :disabled="dropdownsDisabled">
                                            <v-radio v-for="opt in arItems" :key="opt.value"
                                                     :label="opt.title" :value="opt.value" />
                                        </v-radio-group>
                                    </v-col>
                                    <v-col cols="12" sm="6">
                                        <div class="text-caption font-weight-bold">
                                            {{ t('AccountingView.chartOfAccounts.payables') }}
                                        </div>
                                        <v-radio-group v-model="form.ap_dropdown" density="compact" hide-details
                                                       :disabled="dropdownsDisabled">
                                            <v-radio v-for="opt in apItems" :key="opt.value"
                                                     :label="opt.title" :value="opt.value" />
                                        </v-radio-group>
                                    </v-col>
                                    <v-col cols="12" sm="6">
                                        <div class="text-caption font-weight-bold mt-2">
                                            {{ t('AccountingView.chartOfAccounts.partsInventory') }}
                                        </div>
                                        <v-checkbox v-for="opt in partsItems" :key="opt.value"
                                                    v-model="form.ic_flags" :value="opt.value" :label="opt.title"
                                                    density="compact" hide-details :disabled="dropdownsDisabled" />
                                    </v-col>
                                    <v-col cols="12" sm="6">
                                        <div class="text-caption font-weight-bold mt-2">
                                            {{ t('AccountingView.chartOfAccounts.serviceItems') }}
                                        </div>
                                        <v-checkbox v-for="opt in serviceItems" :key="opt.value"
                                                    v-model="form.ic_flags" :value="opt.value" :label="opt.title"
                                                    density="compact" hide-details :disabled="dropdownsDisabled" />
                                    </v-col>
                                </v-row>
                            </v-card>
                        </v-col>
                    </v-row>

                    <v-row dense class="mt-1">
                        <v-col cols="12">
                            <v-checkbox v-model="form.datevautomatik"
                                        :label="t('AccountingView.chartOfAccounts.datevautomatik')"
                                        :hint="t('AccountingView.chartOfAccounts.datevautomatikHint')" persistent-hint
                                        density="comfortable" />
                        </v-col>
                    </v-row>

                    <!-- Auswertungs-Positionen -->
                    <h3 class="text-subtitle-1 font-weight-bold mt-4 mb-1">{{ t('AccountingView.chartOfAccounts.otherSettings') }}</h3>
                    <p class="text-caption text-medium-emphasis mb-3">{{ t('AccountingView.chartOfAccounts.otherSettingsHint') }}</p>
                    <v-row dense>
                        <v-col cols="12" md="6">
                            <v-select v-model="form.pos_eur" :items="eurItems"
                                      item-title="label" item-value="id" clearable
                                      :label="t('AccountingView.chartOfAccounts.posEur')"
                                      :placeholder="t('AccountingView.chartOfAccounts.notAssigned')"
                                      persistent-placeholder
                                      density="comfortable" variant="outlined" hide-details />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-select v-model="form.pos_bwa" :items="bwaItems"
                                      item-title="label" item-value="id" clearable
                                      :label="t('AccountingView.chartOfAccounts.posBwa')"
                                      :placeholder="t('AccountingView.chartOfAccounts.notAssigned')"
                                      persistent-placeholder
                                      density="comfortable" variant="outlined" hide-details />
                        </v-col>
                    </v-row>

                    <!-- Folgekonto: ab dem Stichtag wird auf das Nachfolgekonto gebucht -->
                    <h3 class="text-subtitle-1 font-weight-bold mt-4 mb-1">{{ t('AccountingView.chartOfAccounts.successor') }}</h3>
                    <p class="text-caption text-medium-emphasis mb-3">{{ t('AccountingView.chartOfAccounts.successorHint') }}</p>
                    <v-alert v-if="form.new_chart_valid" type="warning" variant="tonal" density="compact" class="mb-3"
                             icon="mdi-lock-outline"
                             :text="t('AccountingView.chartOfAccounts.successorActive', { date: formatDate(form.valid_from) })" />
                    <v-row dense>
                        <v-col cols="12" md="8">
                            <v-select v-model="form.new_chart_id" :items="successorItems"
                                      item-title="label" item-value="id" clearable
                                      :label="t('AccountingView.chartOfAccounts.successor')"
                                      :placeholder="t('AccountingView.chartOfAccounts.successorNone')"
                                      persistent-placeholder :disabled="form.new_chart_valid"
                                      :no-data-text="t('AccountingView.chartOfAccounts.successorNoCandidates')"
                                      density="comfortable" variant="outlined" hide-details />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field v-model="form.valid_from" type="date"
                                          :label="t('AccountingView.chartOfAccounts.successorValidFrom')"
                                          :disabled="form.new_chart_valid || !form.new_chart_id"
                                          density="comfortable" variant="outlined" hide-details
                                          class="date-field" />
                        </v-col>
                    </v-row>
                    </template>
                </v-card-text>

                <v-divider />
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="editDialog = false">{{ t('AccountingView.chartOfAccounts.cancel') }}</v-btn>
                    <v-btn color="primary" variant="elevated" :loading="saving" @click="save">
                        {{ t('AccountingView.chartOfAccounts.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useChartOfAccounts } from '../composables/useChartOfAccounts.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const { loading, saving, accounts, taxOptions, eurOptions, bwaOptions, ustvaOptions,
        fetchAccounts, fetchAccount, fetchOptions, saveAccount } = useChartOfAccounts()

const searchQuery = ref('')
const categoryFilter = ref(null)
const editDialog = ref(false)
const form = ref(null)
const deletedTaxkeys = ref([])

// Eindeutiger Schlüssel für neue Zeilen (kein Date.now nötig)
let rowCounter = 0

const categories = ['C', 'A', 'L', 'Q', 'E', 'I']

const headers = computed(() => [
    { title: t('AccountingView.chartOfAccounts.accno'), key: 'accno', width: '110px' },
    { title: t('AccountingView.chartOfAccounts.description'), key: 'description' },
    { title: t('AccountingView.chartOfAccounts.category'), key: 'category', width: '120px' },
    { title: t('AccountingView.chartOfAccounts.taxkey'), key: 'taxkey', width: '170px', sortable: false },
    { title: t('AccountingView.chartOfAccounts.invalid'), key: 'invalid', width: '80px', align: 'center' },
    { title: '', key: 'actions', width: '60px', sortable: false, align: 'end' }
])

const charttypeItems = computed(() => [
    { title: t('AccountingView.chartOfAccounts.charttypeAccount'), value: 'A' },
    { title: t('AccountingView.chartOfAccounts.charttypeHeading'), value: 'H' }
])

const categoryItems = computed(() => [
    { title: t('AccountingView.chartOfAccounts.categoryNone'), value: '' },
    ...categories.map(c => ({ title: categoryLabel(c), value: c }))
])

const categoryFilterItems = computed(() => categories.map(c => ({ title: categoryLabel(c), value: c })))

// Kontenverknüpfung: steuert, in welchen Buchungsmasken das Konto angeboten wird.
// Die Marker sind kivitendo-Vorgaben und werden im Backend gegen dieselbe Liste geprüft.
// Ein Sammelkonto (AR/AP/IC) sammelt die Gegenbuchungen und schliesst jede
// Einzelauswahl aus — deshalb hier getrennt modelliert.
const IC_FLAGS = ['IC_sale', 'IC_cogs', 'IC_taxpart', 'IC_income', 'IC_expense', 'IC_taxservice']

function linkOption(value, key) {
    return { value, title: t('AccountingView.chartOfAccounts.linkOptions.' + key) }
}

const summaryItems = computed(() => [
    linkOption('AR', 'summaryAR'),
    linkOption('AP', 'summaryAP'),
    linkOption('IC', 'summaryIC'),
    linkOption('', 'summaryNone')
])

const arItems = computed(() => [
    linkOption('AR_amount', 'revenue'),
    linkOption('AR_tax', 'tax'),
    linkOption('AR_paid', 'receipt'),
    linkOption('', 'notIncluded')
])

const apItems = computed(() => [
    linkOption('AP_amount', 'expenseAsset'),
    linkOption('AP_tax', 'tax'),
    linkOption('AP_paid', 'payment'),
    linkOption('', 'notIncluded')
])

const partsItems = computed(() => [
    linkOption('IC_sale', 'revenue'),
    linkOption('IC_cogs', 'expense'),
    linkOption('IC_taxpart', 'tax')
])

const serviceItems = computed(() => [
    linkOption('IC_income', 'revenue'),
    linkOption('IC_expense', 'expense'),
    linkOption('IC_taxservice', 'tax')
])

// Reihenfolge wie im Backend (_chartLink), damit sich zwei Verknüpfungen
// unabhängig von der Eingabereihenfolge vergleichen lassen
const LINK_ORDER = [
    'AR', 'AR_amount', 'AR_tax', 'AR_paid',
    'AP', 'AP_amount', 'AP_tax', 'AP_paid',
    'IC', 'IC_sale', 'IC_cogs', 'IC_taxpart',
    'IC_income', 'IC_expense', 'IC_taxservice'
]

function normalizeLink(markers) {
    return LINK_ORDER.filter(m => markers.includes(m)).join(':')
}

// Kontentyp und Verknüpfung sind gesperrt, sobald auf das Konto gebucht wurde.
// Ebenso bei einer Verknüpfung, die diese Oberfläche nicht abbilden kann —
// sonst ginge beim Speichern still ein Marker verloren.
const linkLocked = computed(() => !!form.value && (!form.value.orphaned || form.value.link_unmapped))
const isAccount = computed(() => !!form.value && form.value.charttype === 'A')
const dropdownsDisabled = computed(() => linkLocked.value || !!form.value?.summary_account)

// Folgekonto-Kandidaten: kommen vom Backend (gleiche Verknüpfung)
const successorItems = computed(() => (form.value?.new_accounts || []).map(a => ({
    id: Number(a.id),
    label: a.accno + ' – ' + a.description
})))

// Sammelkonto und Einzelauswahl schliessen sich aus — beim Wechsel aufräumen,
// damit der Benutzer nicht erst beim Speichern in den Fehler läuft
function onSummaryChange(value) {
    if (!value) return
    form.value.ar_dropdown = ''
    form.value.ap_dropdown = ''
    form.value.ic_flags = []
}

// Verknüpfungs-String aus der Bedienoberfläche zusammensetzen
function buildLink() {
    if (form.value.summary_account) return [form.value.summary_account]
    return [
        form.value.ar_dropdown,
        form.value.ap_dropdown,
        ...IC_FLAGS.filter(f => form.value.ic_flags.includes(f))
    ].filter(Boolean)
}

function formatDate(value) {
    if (!value) return ''
    const [year, month, day] = String(value).split('-')
    return day && month && year ? `${day}.${month}.${year}` : String(value)
}

function categoryLabel(c) {
    if (!c) return t('AccountingView.chartOfAccounts.categoryNone')
    return t('AccountingView.chartOfAccounts.categories.' + c) + ' (' + c + ')'
}

// Steuerschlüssel-Auswahl: Label wie in kivitendo aufbereiten
const taxSelectItems = computed(() => taxOptions.value.map(tx => ({
    id: Number(tx.id),
    label: formatTaxLabel(tx)
})))

function formatTaxLabel(tx) {
    const key = String(tx.taxkey).padStart(2, '0')
    const pct = formatPercent(tx.rate_percent)
    const acc = tx.chart_accno ? ' → ' + tx.chart_accno : ''
    return `${key}. (${pct}%) ${tx.taxdescription}${acc}`
}

function formatPercent(value) {
    const n = Number(value)
    return Number.isInteger(n) ? String(n) : String(parseFloat(n.toFixed(2)))
}

// EÜR-/BWA-Positionen wie im Kontenrahmen üblich zweistellig nummeriert:
// "01. Umsatzerlöse" statt einer nackten Zahl
const eurItems = computed(() => eurOptions.value.map(posItem))
const bwaItems = computed(() => bwaOptions.value.map(posItem))

function posItem(pos) {
    return {
        id: Number(pos.id),
        label: String(pos.id).padStart(2, '0') + '. ' + pos.description
    }
}

// UStVA-Kennzahlen: Nummer als Auswahl, amtliche Erläuterung als Untertitel
const ustvaItems = computed(() => ustvaOptions.value.map(pos => ({
    id: Number(pos.id),
    label: String(pos.id),
    description: pos.description || ''
})))

function ustvaItemProps(item) {
    return { title: item.label, subtitle: item.description }
}

function toPos(value) {
    return value === null || value === undefined || value === '' ? null : Number(value)
}

let searchTimer = null
function onSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(loadAccounts, 300)
}

function loadAccounts() {
    fetchAccounts({ search: searchQuery.value || '', category: categoryFilter.value || '' })
}

async function openEdit(item) {
    const chart = await fetchAccount(Number(item.id))
    if (!chart) {
        alerts.error(t('AccountingView.chartOfAccounts.loadError'))
        return
    }
    deletedTaxkeys.value = []
    // chart.link ist ein ':'-getrennter Marker-String, den die Oberfläche in
    // Sammelkonto, Forderungen/Verbindlichkeiten und Waren/Dienstleistungen zerlegt
    const markers = (chart.link || '').split(':').filter(Boolean)
    form.value = {
        id: Number(chart.id),
        accno: chart.accno,
        description: chart.description,
        charttype: chart.charttype || 'A',
        category: chart.category || '',
        invalid: !!chart.invalid,
        orphaned: !!chart.orphaned,
        summary_account: ['AR', 'AP', 'IC'].find(m => markers.includes(m)) || '',
        ar_dropdown: ['AR_amount', 'AR_tax', 'AR_paid'].find(m => markers.includes(m)) || '',
        ap_dropdown: ['AP_amount', 'AP_tax', 'AP_paid'].find(m => markers.includes(m)) || '',
        ic_flags: IC_FLAGS.filter(m => markers.includes(m)),
        link_original: normalizeLink(markers),
        datevautomatik: !!chart.datevautomatik,
        // Die Auswahllisten arbeiten mit Zahlen, die API liefert Strings
        pos_eur: toPos(chart.pos_eur),
        pos_bwa: toPos(chart.pos_bwa),
        new_chart_id: toPos(chart.new_chart_id),
        valid_from: chart.valid_from || '',
        new_chart_valid: !!chart.new_chart_valid,
        new_accounts: chart.new_accounts || [],
        taxkeys: (chart.taxkeys || []).map(tk => ({
            _key: 'tk' + tk.id,
            id: Number(tk.id),
            tax_id: Number(tk.tax_id),
            startdate: tk.startdate,
            pos_ustva: toPos(tk.pos_ustva)
        }))
    }
    // Lässt sich die gespeicherte Verknüpfung aus den Bedienelementen wieder
    // exakt herstellen? Wenn nicht, bleibt sie unangetastet.
    form.value.link_unmapped = normalizeLink(buildLink()) !== form.value.link_original
    editDialog.value = true
}

function addTaxkeyRow() {
    form.value.taxkeys.push({
        _key: 'new' + (++rowCounter),
        id: 'NEW',
        tax_id: null,
        startdate: '',
        pos_ustva: null
    })
}

function removeTaxkeyRow(idx) {
    const row = form.value.taxkeys[idx]
    if (row.id !== 'NEW') {
        deletedTaxkeys.value.push({ id: row.id, delete: true })
    }
    form.value.taxkeys.splice(idx, 1)
}

async function save() {
    const payload = {
        ...form.value,
        // Nicht abbildbare Verknüpfungen unverändert zurückschreiben
        link: form.value.link_unmapped ? form.value.link_original.split(':') : buildLink(),
        // Bei Überschriften wird ohne Datum kein Folgekonto gesetzt
        valid_from: form.value.new_chart_id ? form.value.valid_from : '',
        taxkeys: [...form.value.taxkeys, ...deletedTaxkeys.value]
    }
    const result = await saveAccount(payload)
    if (result.success) {
        alerts.success(t('AccountingView.chartOfAccounts.saved'))
        editDialog.value = false
        loadAccounts()
    } else {
        alerts.error(t('AccountingView.chartOfAccounts.saveError') + (result.text ? ': ' + result.text : ''))
    }
}

onMounted(() => {
    fetchOptions()
    loadAccounts()
})
</script>

<style scoped>
.row-clickable :deep(tbody tr) {
    cursor: pointer;
}
.taxkey-table :deep(td) {
    padding-top: 8px !important;
    padding-bottom: 8px !important;
    vertical-align: top;
}

/* Native Datumsfelder brauchen Platz fuer Datum + Kalender-Button; in der
   engen Tabellenspalte wurde sonst die Jahreszahl abgeschnitten. */
.date-field :deep(input) {
    min-width: 140px;
}
</style>
