<template>
    <div>
        <!-- Listenansicht der Läufe -->
        <div v-if="!activeRun">
            <div class="d-flex align-center mb-4 flex-wrap ga-2">
                <span class="text-body-2 text-medium-emphasis">{{ t('HrView.payroll.title') }}</span>
                <v-spacer />
                <v-btn
                    color="primary"
                    size="small"
                    prepend-icon="mdi-plus"
                    @click="showCreateDialog = true"
                >
                    {{ t('HrView.payroll.newRun') }}
                </v-btn>
            </div>

            <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />

            <v-card v-if="!loading && runs.length === 0" variant="outlined" class="text-center pa-8">
                <v-icon size="64" color="grey-lighten-1" class="mb-4">mdi-cash-multiple</v-icon>
                <p class="text-h6 text-medium-emphasis mb-2">{{ t('HrView.payroll.noRuns') }}</p>
                <v-btn color="primary" prepend-icon="mdi-plus" @click="showCreateDialog = true">
                    {{ t('HrView.payroll.newRun') }}
                </v-btn>
            </v-card>

            <v-row v-else>
                <v-col v-for="run in runs" :key="run.id" cols="12" md="6" lg="4">
                    <v-card variant="outlined" elevation="1" class="h-100">
                        <v-card-title class="d-flex align-center pb-1">
                            <v-icon class="mr-2" :color="run.status === 'final' ? 'success' : 'warning'">
                                {{ run.status === 'final' ? 'mdi-check-circle' : 'mdi-pencil-circle' }}
                            </v-icon>
                            {{ monthName(run.month) }} {{ run.year }}
                            <v-spacer />
                            <v-chip
                                size="x-small"
                                :color="run.status === 'final' ? 'success' : 'warning'"
                                variant="tonal"
                            >
                                {{ run.status === 'final' ? t('HrView.payroll.statusFinal') : t('HrView.payroll.statusDraft') }}
                            </v-chip>
                        </v-card-title>

                        <v-card-text class="pt-0">
                            <v-table density="compact" class="text-body-2">
                                <tbody>
                                    <tr>
                                        <td class="text-medium-emphasis">{{ t('HrView.payroll.employees') }}</td>
                                        <td class="text-right font-weight-medium">{{ run.item_count }}</td>
                                    </tr>
                                    <tr>
                                        <td class="text-medium-emphasis">{{ t('HrView.payroll.totalBrutto') }}</td>
                                        <td class="text-right font-weight-medium">{{ formatCurrency(run.total_brutto) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="text-medium-emphasis">{{ t('HrView.payroll.totalNetto') }}</td>
                                        <td class="text-right font-weight-medium text-success">{{ formatCurrency(run.total_netto) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="text-medium-emphasis">{{ t('HrView.payroll.agCost') }}</td>
                                        <td class="text-right">{{ formatCurrency(run.total_ag_cost) }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card-text>

                        <v-divider />
                        <v-card-actions class="px-3 py-2">
                            <span class="text-caption text-medium-emphasis">{{ formatDate(run.itime) }}</span>
                            <v-spacer />
                            <v-btn
                                size="x-small"
                                variant="tonal"
                                color="primary"
                                prepend-icon="mdi-eye"
                                @click="openRun(run)"
                            >
                                {{ t('HrView.payroll.view') }}
                            </v-btn>
                            <v-btn
                                v-if="run.status === 'draft'"
                                size="x-small"
                                variant="tonal"
                                color="success"
                                prepend-icon="mdi-check"
                                class="ml-1"
                                @click="confirmFinalize(run)"
                            >
                                {{ t('HrView.payroll.finalize') }}
                            </v-btn>
                            <v-btn
                                v-if="run.status === 'draft'"
                                size="x-small"
                                variant="text"
                                color="red"
                                icon="mdi-delete"
                                class="ml-1"
                                @click="confirmDelete(run)"
                            />
                        </v-card-actions>
                    </v-card>
                </v-col>
            </v-row>
        </div>

        <!-- Detailansicht eines Laufs -->
        <div v-else>
            <div class="d-flex align-center mb-4 flex-wrap ga-2">
                <v-btn
                    size="small"
                    variant="text"
                    prepend-icon="mdi-arrow-left"
                    @click="activeRun = null"
                >
                    {{ t('HrView.payroll.back') }}
                </v-btn>
                <v-icon color="primary" class="mx-1">mdi-cash-multiple</v-icon>
                <span class="text-subtitle-1 font-weight-medium">
                    {{ monthName(activeRun.run.month) }} {{ activeRun.run.year }}
                </span>
                <v-chip
                    size="x-small"
                    :color="activeRun.run.status === 'final' ? 'success' : 'warning'"
                    variant="tonal"
                    class="ml-1"
                >
                    {{ activeRun.run.status === 'final' ? t('HrView.payroll.statusFinal') : t('HrView.payroll.statusDraft') }}
                </v-chip>
                <v-spacer />
                <v-btn
                    v-if="activeRun.run.status === 'draft'"
                    size="small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-calculator-variant"
                    :loading="calcingTaxes"
                    class="mr-2"
                    @click="autoCalcTaxes"
                >
                    {{ t('HrView.payroll.calcTaxes') }}
                </v-btn>
                <v-btn
                    v-if="activeRun.run.status === 'draft'"
                    size="small"
                    color="success"
                    prepend-icon="mdi-check"
                    @click="confirmFinalize(activeRun.run)"
                >
                    {{ t('HrView.payroll.finalize') }}
                </v-btn>
            </div>

            <!-- Zusammenfassung -->
            <v-row class="mb-4">
                <v-col v-for="s in runSummary" :key="s.label" cols="6" sm="4" md="2">
                    <v-card variant="tonal" :color="s.color || undefined" class="text-center pa-3">
                        <div class="text-caption text-medium-emphasis">{{ s.label }}</div>
                        <div class="text-subtitle-1 font-weight-bold">{{ s.value }}</div>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Steuer-Hinweis -->
            <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                <template #text>
                    <span class="text-body-2">{{ t('HrView.payroll.taxHint') }}</span>
                </template>
            </v-alert>

            <!-- Positionen-Tabelle -->
            <v-data-table
                :headers="itemHeaders"
                :items="activeRun.items"
                density="compact"
                :items-per-page="-1"
                hide-default-footer
                class="payroll-table"
            >
                <template #item.brutto="{ item }">
                    <span class="font-weight-medium">{{ formatCurrency(item.brutto) }}</span>
                </template>
                <template #item.sv_an_total="{ item }">
                    <v-tooltip :text="svAnTooltip(item)" location="top">
                        <template #activator="{ props }">
                            <span v-bind="props" class="text-decoration-dotted cursor-help">
                                {{ formatCurrency(item.sv_an_total) }}
                            </span>
                        </template>
                    </v-tooltip>
                </template>
                <template #item.sv_ag_total="{ item }">
                    <v-tooltip :text="svAgTooltip(item)" location="top">
                        <template #activator="{ props }">
                            <span v-bind="props" class="text-decoration-dotted cursor-help">
                                {{ formatCurrency(item.sv_ag_total) }}
                            </span>
                        </template>
                    </v-tooltip>
                </template>
                <template #item.lohnsteuer="{ item }">{{ formatCurrency(item.lohnsteuer) }}</template>
                <template #item.netto="{ item }">
                    <span class="font-weight-bold text-success">{{ formatCurrency(item.netto) }}</span>
                </template>
                <template #item.agTotal="{ item }">
                    <span class="text-medium-emphasis">{{ formatCurrency(agTotal(item)) }}</span>
                </template>
                <template #item.actions="{ item }">
                    <v-btn
                        v-if="activeRun.run.status === 'draft'"
                        size="x-small"
                        variant="text"
                        icon="mdi-pencil"
                        @click="openEditItem(item)"
                    />
                </template>
            </v-data-table>
        </div>

        <!-- Dialog: Lohnlauf erstellen -->
        <v-dialog v-model="showCreateDialog" max-width="400">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon class="mr-2" color="primary">mdi-cash-multiple</v-icon>
                    {{ t('HrView.payroll.newRun') }}
                </v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="8">
                            <v-select
                                v-model="createForm.month"
                                :label="t('HrView.payroll.month')"
                                :items="monthItems"
                                item-title="title"
                                item-value="value"
                                variant="outlined"
                                density="comfortable"
                            />
                        </v-col>
                        <v-col cols="4">
                            <v-text-field
                                v-model.number="createForm.year"
                                :label="t('HrView.payroll.year')"
                                type="number"
                                variant="outlined"
                                density="comfortable"
                            />
                        </v-col>
                    </v-row>
                    <p class="text-caption text-medium-emphasis mt-1">{{ t('HrView.payroll.createHint') }}</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showCreateDialog = false">{{ t('HrView.cancel') }}</v-btn>
                    <v-btn
                        color="primary"
                        :loading="creating"
                        @click="createRun"
                    >
                        {{ t('HrView.payroll.createRun') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog: Position bearbeiten -->
        <v-dialog v-model="showEditDialog" max-width="640" scrollable>
            <v-card v-if="editItem">
                <v-card-title class="d-flex align-center">
                    <v-icon class="mr-2" color="primary">mdi-account-cash</v-icon>
                    {{ editItem.employee_name }}
                </v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model.number="editItem.brutto"
                                :label="t('HrView.payroll.brutto')"
                                type="number" min="0" step="0.01" prefix="€"
                                variant="outlined" density="comfortable"
                                @update:modelValue="recalcSv"
                            />
                        </v-col>
                    </v-row>

                    <v-expansion-panels class="mb-3">
                        <v-expansion-panel :title="t('HrView.payroll.svDetails')">
                            <v-expansion-panel-text>
                                <p class="text-caption text-medium-emphasis mb-2">{{ t('HrView.settings.anRates') }}</p>
                                <v-row dense>
                                    <v-col v-for="f in svAnEditFields" :key="f.key" cols="6">
                                        <v-text-field
                                            v-model.number="editItem[f.key]"
                                            :label="f.label"
                                            type="number" min="0" step="0.01" prefix="€"
                                            variant="outlined" density="compact" hide-details
                                        />
                                    </v-col>
                                </v-row>
                                <p class="text-caption text-medium-emphasis mb-2 mt-3">{{ t('HrView.settings.agRates') }}</p>
                                <v-row dense>
                                    <v-col v-for="f in svAgEditFields" :key="f.key" cols="6">
                                        <v-text-field
                                            v-model.number="editItem[f.key]"
                                            :label="f.label"
                                            type="number" min="0" step="0.01" prefix="€"
                                            variant="outlined" density="compact" hide-details
                                        />
                                    </v-col>
                                </v-row>
                            </v-expansion-panel-text>
                        </v-expansion-panel>
                    </v-expansion-panels>

                    <v-row dense>
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model.number="editItem.lohnsteuer"
                                :label="t('HrView.payroll.lohnsteuer')"
                                type="number" min="0" step="0.01" prefix="€"
                                variant="outlined" density="comfortable" hide-details
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model.number="editItem.kirchensteuer"
                                :label="t('HrView.payroll.kirchensteuer')"
                                type="number" min="0" step="0.01" prefix="€"
                                variant="outlined" density="comfortable" hide-details
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model.number="editItem.solidaritaetszuschlag"
                                :label="t('HrView.payroll.soli')"
                                type="number" min="0" step="0.01" prefix="€"
                                variant="outlined" density="comfortable" hide-details
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model.number="editItem.sonstige_abzuege"
                                :label="t('HrView.payroll.sonstigeAbzuege')"
                                type="number" min="0" step="0.01" prefix="€"
                                variant="outlined" density="comfortable" hide-details
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model.number="editItem.sonstige_zulagen"
                                :label="t('HrView.payroll.sonstigeZulagen')"
                                type="number" min="0" step="0.01" prefix="€"
                                variant="outlined" density="comfortable" hide-details
                            />
                        </v-col>
                    </v-row>

                    <!-- Live-Netto-Vorschau -->
                    <v-card variant="tonal" color="success" class="mt-3 pa-3">
                        <div class="d-flex justify-space-between align-center">
                            <span class="text-body-2">{{ t('HrView.payroll.netto') }}</span>
                            <span class="text-h6 font-weight-bold">{{ formatCurrency(liveNetto) }}</span>
                        </div>
                    </v-card>

                    <v-text-field
                        v-model="editItem.notes"
                        :label="t('HrView.payroll.notes')"
                        variant="outlined" density="comfortable" hide-details class="mt-3"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showEditDialog = false">{{ t('HrView.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="savingItem" @click="saveItem">
                        {{ t('HrView.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'

const MONTH_NAMES_DE = [
    'Januar','Februar','März','April','Mai','Juni',
    'Juli','August','September','Oktober','November','Dezember'
]

export default {
    name: 'HrPayrollTab',
    setup() {
        const { t } = useI18n()
        const hr = hrStore()
        const oserp = oserpStore()

        const runs = ref([])
        const loading = ref(false)
        const activeRun = ref(null)
        const showCreateDialog = ref(false)
        const creating = ref(false)
        const createForm = ref({ month: new Date().getMonth() + 1, year: new Date().getFullYear() })

        const showEditDialog = ref(false)
        const editItem = ref(null)
        const savingItem = ref(false)
        const calcingTaxes = ref(false)

        function monthName(m) { return MONTH_NAMES_DE[m - 1] || m }

        const monthItems = computed(() =>
            MONTH_NAMES_DE.map((n, i) => ({ title: n, value: i + 1 }))
        )

        const itemHeaders = computed(() => [
            { title: t('HrView.payroll.employeeName'), key: 'employee_name', sortable: true },
            { title: t('HrView.payroll.brutto'), key: 'brutto', align: 'end' },
            { title: t('HrView.payroll.svAn'), key: 'sv_an_total', align: 'end' },
            { title: t('HrView.payroll.svAg'), key: 'sv_ag_total', align: 'end' },
            { title: t('HrView.payroll.lohnsteuer'), key: 'lohnsteuer', align: 'end' },
            { title: t('HrView.payroll.netto'), key: 'netto', align: 'end' },
            { title: t('HrView.payroll.agTotal'), key: 'agTotal', align: 'end' },
            { title: '', key: 'actions', sortable: false, width: 40 }
        ])

        const svAnEditFields = computed(() => [
            { key: 'kv_an', label: t('HrView.payroll.kvAn') },
            { key: 'pv_an', label: t('HrView.payroll.pvAn') },
            { key: 'rv_an', label: t('HrView.payroll.rvAn') },
            { key: 'av_an', label: t('HrView.payroll.avAn') }
        ])

        const svAgEditFields = computed(() => [
            { key: 'kv_ag', label: t('HrView.payroll.kvAg') },
            { key: 'pv_ag', label: t('HrView.payroll.pvAg') },
            { key: 'rv_ag', label: t('HrView.payroll.rvAg') },
            { key: 'av_ag', label: t('HrView.payroll.avAg') }
        ])

        const liveNetto = computed(() => {
            if (!editItem.value) return 0
            const e = editItem.value
            return (
                (e.brutto || 0)
                - (e.kv_an || 0) - (e.pv_an || 0) - (e.rv_an || 0) - (e.av_an || 0)
                - (e.lohnsteuer || 0) - (e.kirchensteuer || 0) - (e.solidaritaetszuschlag || 0)
                - (e.sonstige_abzuege || 0)
                + (e.sonstige_zulagen || 0)
            )
        })

        const runSummary = computed(() => {
            if (!activeRun.value) return []
            const items = activeRun.value.items
            const totalBrutto = items.reduce((s, i) => s + i.brutto, 0)
            const totalNetto = items.reduce((s, i) => s + i.netto, 0)
            const totalSvAn = items.reduce((s, i) => s + i.sv_an_total, 0)
            const totalSvAg = items.reduce((s, i) => s + i.sv_ag_total, 0)
            const totalTax = items.reduce((s, i) => s + i.lohnsteuer + i.kirchensteuer + i.solidaritaetszuschlag, 0)
            const totalAg = items.reduce((s, i) => s + agTotal(i), 0)
            return [
                { label: t('HrView.payroll.summaryBrutto'), value: formatCurrency(totalBrutto) },
                { label: t('HrView.payroll.summaryNetto'), value: formatCurrency(totalNetto), color: 'success' },
                { label: t('HrView.payroll.summarySvAn'), value: formatCurrency(totalSvAn) },
                { label: t('HrView.payroll.summarySvAg'), value: formatCurrency(totalSvAg) },
                { label: t('HrView.payroll.summaryTax'), value: formatCurrency(totalTax) },
                { label: t('HrView.payroll.summaryAgCost'), value: formatCurrency(totalAg), color: 'primary' }
            ]
        })

        function agTotal(item) {
            return item.brutto + item.sv_ag_total
        }

        function svAnTooltip(item) {
            return `KV: ${formatCurrency(item.kv_an)} | PV: ${formatCurrency(item.pv_an)} | RV: ${formatCurrency(item.rv_an)} | AV: ${formatCurrency(item.av_an)}`
        }

        function svAgTooltip(item) {
            return `KV: ${formatCurrency(item.kv_ag)} | PV: ${formatCurrency(item.pv_ag)} | RV: ${formatCurrency(item.rv_ag)} | AV: ${formatCurrency(item.av_ag)}`
        }

        function formatCurrency(v) {
            return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v || 0)
        }

        function formatDate(d) {
            if (!d) return ''
            return new Date(d).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
        }

        async function load() {
            loading.value = true
            try {
                runs.value = await hr.fetchPayrollRuns()
            } finally {
                loading.value = false
            }
        }

        async function openRun(run) {
            loading.value = true
            try {
                activeRun.value = await hr.fetchPayrollRun(run.id)
            } finally {
                loading.value = false
            }
        }

        async function createRun() {
            creating.value = true
            try {
                const employeeId = oserp.session?.logged_in_employee?.id || 0
                await hr.createPayrollRun({
                    year: createForm.value.year,
                    month: createForm.value.month,
                    employee_id: employeeId
                })
                showCreateDialog.value = false
                await load()
                toasts.success(t('HrView.payroll.statusDraft'))
            } catch (e) {
                if (e.message?.includes('RUN_EXISTS')) {
                    toasts.error(t('HrView.payroll.runExists'))
                } else {
                    toasts.error(t('HrView.payroll.saveError'))
                }
            } finally {
                creating.value = false
            }
        }

        async function confirmFinalize(run) {
            const res = await alerts.question(
                t('HrView.payroll.finalizeConfirm', { month: run.month, year: run.year })
            )
            if (!res.isConfirmed) return
            try {
                const employeeId = oserp.session?.logged_in_employee?.id || 0
                await hr.finalizePayrollRun(run.id, employeeId)
                toasts.success(t('HrView.payroll.finalized'))
                await load()
                if (activeRun.value) activeRun.value = await hr.fetchPayrollRun(run.id)
            } catch {
                toasts.error(t('HrView.payroll.saveError'))
            }
        }

        async function confirmDelete(run) {
            const res = await alerts.question(
                t('HrView.payroll.deleteConfirm', { month: run.month, year: run.year })
            )
            if (!res.isConfirmed) return
            try {
                await hr.deletePayrollRun(run.id)
                toasts.success(t('HrView.payroll.deleted'))
                await load()
            } catch {
                toasts.error(t('HrView.payroll.saveError'))
            }
        }

        function openEditItem(item) {
            editItem.value = { ...item }
            showEditDialog.value = true
        }

        function recalcSv() {
            // Neuberechnung der SV-Beträge aus den ursprünglichen Prozentsätzen ist hier
            // nicht möglich ohne die gespeicherten Prozentsätze – bewusst nicht automatisch
        }

        async function autoCalcTaxes() {
            if (!activeRun.value) return
            calcingTaxes.value = true
            try {
                const result = await hr.calculatePayrollTaxes(activeRun.value.run.id)
                toasts.success(t('HrView.payroll.taxCalculated', { count: result.updated }))
                activeRun.value = await hr.fetchPayrollRun(activeRun.value.run.id)
            } catch {
                toasts.error(t('HrView.payroll.taxCalcError'))
            } finally {
                calcingTaxes.value = false
            }
        }

        async function saveItem() {
            savingItem.value = true
            try {
                const result = await hr.updatePayrollItem(editItem.value)
                // Item in aktiver Liste aktualisieren
                const idx = activeRun.value.items.findIndex(i => i.id === editItem.value.id)
                if (idx >= 0) {
                    Object.assign(activeRun.value.items[idx], editItem.value, result.item)
                }
                showEditDialog.value = false
                toasts.success(t('HrView.payroll.saved'))
            } catch {
                toasts.error(t('HrView.payroll.saveError'))
            } finally {
                savingItem.value = false
            }
        }

        onMounted(load)

        return {
            t, runs, loading, activeRun,
            showCreateDialog, creating, createForm, monthItems, monthName,
            showEditDialog, editItem, savingItem, liveNetto,
            itemHeaders, svAnEditFields, svAgEditFields, runSummary,
            formatCurrency, formatDate, svAnTooltip, svAgTooltip, agTotal,
            openRun, createRun, confirmFinalize, confirmDelete,
            openEditItem, recalcSv, saveItem, autoCalcTaxes, calcingTaxes
        }
    }
}
</script>

<style scoped>
.cursor-help { cursor: help; }
.text-decoration-dotted { text-decoration: underline dotted; }
.payroll-table :deep(td) { padding: 4px 8px !important; }
</style>
