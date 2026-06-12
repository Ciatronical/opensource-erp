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
        <v-dialog v-model="editDialog" max-width="900" scrollable>
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
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="12" sm="8">
                            <v-text-field v-model="form.description" :label="t('AccountingView.chartOfAccounts.description')"
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-select v-model="form.charttype" :items="charttypeItems"
                                      :label="t('AccountingView.chartOfAccounts.charttype')"
                                      density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-select v-model="form.category" :items="categoryItems"
                                      :label="t('AccountingView.chartOfAccounts.category')"
                                      density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="12" sm="4" class="d-flex align-center">
                            <v-checkbox v-model="form.invalid" :label="t('AccountingView.chartOfAccounts.invalid')"
                                        density="compact" hide-details />
                        </v-col>
                    </v-row>

                    <!-- Steuerautomatik & UStVA -->
                    <div class="d-flex align-center mt-4 mb-2">
                        <h3 class="text-subtitle-1 font-weight-bold">{{ t('AccountingView.chartOfAccounts.taxAutomatic') }}</h3>
                        <v-spacer />
                        <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-plus" @click="addTaxkeyRow">
                            {{ t('AccountingView.chartOfAccounts.addTaxkey') }}
                        </v-btn>
                    </div>
                    <v-alert type="info" variant="tonal" density="compact" class="mb-3" :text="t('AccountingView.chartOfAccounts.taxHint')" />

                    <v-table density="compact" class="taxkey-table">
                        <thead>
                            <tr>
                                <th>{{ t('AccountingView.chartOfAccounts.taxkey') }}</th>
                                <th style="width: 160px">{{ t('AccountingView.chartOfAccounts.validFrom') }}</th>
                                <th style="width: 120px">{{ t('AccountingView.chartOfAccounts.ustva') }}</th>
                                <th style="width: 60px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, idx) in form.taxkeys" :key="row._key">
                                <td>
                                    <v-select v-model="row.tax_id" :items="taxSelectItems"
                                              item-title="label" item-value="id"
                                              density="compact" variant="outlined" hide-details
                                              :placeholder="t('AccountingView.chartOfAccounts.selectTaxkey')" />
                                </td>
                                <td>
                                    <v-text-field v-model="row.startdate" type="date"
                                                  density="compact" variant="outlined" hide-details />
                                </td>
                                <td>
                                    <v-text-field v-model="row.pos_ustva" type="number"
                                                  density="compact" variant="outlined" hide-details />
                                </td>
                                <td class="text-center">
                                    <v-btn icon="mdi-delete" size="small" variant="text" color="error"
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

                    <!-- Sonstige Einstellungen -->
                    <h3 class="text-subtitle-1 font-weight-bold mt-4 mb-3">{{ t('AccountingView.chartOfAccounts.otherSettings') }}</h3>
                    <v-row dense>
                        <v-col cols="6" sm="3">
                            <v-text-field v-model="form.pos_eur" type="number"
                                          :label="t('AccountingView.chartOfAccounts.posEur')"
                                          density="compact" variant="outlined" hide-details />
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-text-field v-model="form.pos_bwa" type="number"
                                          :label="t('AccountingView.chartOfAccounts.posBwa')"
                                          density="compact" variant="outlined" hide-details />
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-text-field v-model="form.pos_bilanz" type="number"
                                          :label="t('AccountingView.chartOfAccounts.posBilanz')"
                                          density="compact" variant="outlined" hide-details />
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-text-field v-model="form.pos_er" type="number"
                                          :label="t('AccountingView.chartOfAccounts.posEr')"
                                          density="compact" variant="outlined" hide-details />
                        </v-col>
                    </v-row>
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
const { loading, saving, accounts, taxOptions, fetchAccounts, fetchAccount, fetchTaxOptions, saveAccount } = useChartOfAccounts()

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
    form.value = {
        id: Number(chart.id),
        accno: chart.accno,
        description: chart.description,
        charttype: chart.charttype || 'A',
        category: chart.category || '',
        invalid: !!chart.invalid,
        pos_eur: chart.pos_eur,
        pos_bwa: chart.pos_bwa,
        pos_bilanz: chart.pos_bilanz,
        pos_er: chart.pos_er,
        taxkeys: (chart.taxkeys || []).map(tk => ({
            _key: 'tk' + tk.id,
            id: Number(tk.id),
            tax_id: Number(tk.tax_id),
            startdate: tk.startdate,
            pos_ustva: tk.pos_ustva
        }))
    }
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
    fetchTaxOptions()
    loadAccounts()
})
</script>

<style scoped>
.row-clickable :deep(tbody tr) {
    cursor: pointer;
}
.taxkey-table :deep(td) {
    padding-top: 6px !important;
    padding-bottom: 6px !important;
    vertical-align: top;
}
</style>
