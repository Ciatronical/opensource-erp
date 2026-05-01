<template>
    <div>
        <div class="d-flex align-center mb-4 flex-wrap ga-2">
            <div>
                <div class="text-subtitle-2">{{ t('HrView.tax.title') }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('HrView.tax.subtitle') }}</div>
            </div>
            <v-spacer />
            <v-select
                v-model="selectedYear"
                :items="yearItems"
                density="compact"
                variant="outlined"
                hide-details
                style="max-width: 90px"
            />
            <v-btn
                color="primary"
                size="small"
                prepend-icon="mdi-calculator"
                :loading="building"
                @click="buildTable"
            >
                {{ t('HrView.tax.buildTable') }}
            </v-btn>
        </div>

        <v-progress-linear v-if="loadingStatus" indeterminate color="primary" class="mb-4" />

        <!-- Status-Karten -->
        <v-row class="mb-4">
            <v-col v-for="meta in status.available_years || []" :key="meta.year" cols="12" sm="6" md="4">
                <v-card variant="outlined" :color="meta.year === currentYear ? 'primary' : undefined">
                    <v-card-title class="d-flex align-center text-body-1 pb-1">
                        <v-icon class="mr-2" :color="meta.year === currentYear ? 'primary' : 'grey'">
                            mdi-table-check
                        </v-icon>
                        {{ t('HrView.tax.tableYear', { year: meta.year }) }}
                        <v-chip
                            v-if="meta.year === currentYear"
                            size="x-small"
                            color="primary"
                            variant="tonal"
                            class="ml-2"
                        >
                            Aktuell
                        </v-chip>
                    </v-card-title>
                    <v-card-text class="pt-0 pb-2">
                        <div class="text-caption text-medium-emphasis">
                            {{ meta.row_count.toLocaleString('de-DE') }} {{ t('HrView.tax.rows') }}
                        </div>
                        <div class="text-caption text-medium-emphasis">
                            {{ t('HrView.tax.builtAt') }}: {{ formatDate(meta.built_at) }}
                            <span v-if="meta.built_by_name"> · {{ meta.built_by_name }}</span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col v-if="!status.available_years?.length" cols="12">
                <v-card variant="tonal" color="warning" class="pa-4 text-center">
                    <v-icon size="36" class="mb-2">mdi-alert-circle</v-icon>
                    <div class="text-body-2">{{ t('HrView.tax.noTable') }}</div>
                    <v-btn color="primary" size="small" class="mt-2" @click="buildTable">
                        {{ t('HrView.tax.buildNow', { year: currentYear }) }}
                    </v-btn>
                </v-card>
            </v-col>
        </v-row>

        <!-- Info-Box: PAP-Erklärung -->
        <v-alert type="info" variant="tonal" density="compact" class="mb-4">
            <template #text>
                <span class="text-body-2">{{ t('HrView.tax.papInfo') }}</span>
            </template>
        </v-alert>

        <!-- Vorschau-Tabelle -->
        <div v-if="status.preview?.length">
            <div class="text-subtitle-2 mb-2">{{ t('HrView.tax.preview', { year: currentYear }) }}</div>
            <v-table density="compact">
                <thead>
                    <tr>
                        <th>{{ t('HrView.tax.brutto') }}</th>
                        <th v-for="sk in [1,2,3,4,5,6]" :key="sk" class="text-center">SK {{ sk }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in previewRows" :key="row.brutto">
                        <td class="text-medium-emphasis">{{ formatCurrency(row.brutto) }}</td>
                        <td v-for="sk in [1,2,3,4,5,6]" :key="sk" class="text-right">
                            <span v-if="row[sk]">{{ formatCurrency(row[sk].lohnsteuer) }}</span>
                            <span v-else class="text-medium-emphasis">–</span>
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <p class="text-caption text-medium-emphasis mt-1">{{ t('HrView.tax.previewHint') }}</p>
        </div>

        <!-- Live-Vorschau-Rechner -->
        <v-divider class="my-4" />
        <div class="text-subtitle-2 mb-3">{{ t('HrView.tax.liveCalc') }}</div>
        <v-row dense>
            <v-col cols="12" sm="4" md="3">
                <v-text-field
                    v-model.number="calcForm.brutto"
                    :label="t('HrView.settings.brutto')"
                    type="number" min="0" step="100" prefix="€"
                    variant="outlined" density="comfortable" hide-details
                    @update:modelValue="calcLive"
                />
            </v-col>
            <v-col cols="12" sm="3" md="2">
                <v-select
                    v-model="calcForm.steuerklasse"
                    :label="t('HrView.settings.steuerklasse')"
                    :items="[1,2,3,4,5,6]"
                    variant="outlined" density="comfortable" hide-details
                    @update:modelValue="calcLive"
                />
            </v-col>
            <v-col cols="12" sm="3" md="2">
                <v-checkbox
                    v-model="calcForm.kirchensteuer"
                    :label="t('HrView.tax.kirchensteuer')"
                    hide-details density="comfortable"
                    @update:modelValue="calcLive"
                />
            </v-col>
        </v-row>

        <v-row v-if="calcResult" dense class="mt-2">
            <v-col v-for="item in calcResultItems" :key="item.label" cols="6" sm="4" md="2">
                <v-card variant="tonal" :color="item.color || undefined" class="text-center pa-2">
                    <div class="text-caption text-medium-emphasis">{{ item.label }}</div>
                    <div class="text-body-1 font-weight-bold">{{ item.value }}</div>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>

<script>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toasts from '@/core/utils/toasts.js'

export default {
    name: 'HrTaxTab',
    setup() {
        const { t } = useI18n()
        const hr = hrStore()
        const oserp = oserpStore()

        const currentYear = new Date().getFullYear()
        const selectedYear = ref(currentYear)
        const yearItems = [currentYear - 1, currentYear, currentYear + 1]

        const status = ref({ available_years: [], preview: [] })
        const loadingStatus = ref(false)
        const building = ref(false)

        const calcForm = ref({ brutto: 3000, steuerklasse: 1, kirchensteuer: false })
        const calcResult = ref(null)
        const calcLoading = ref(false)

        const previewRows = computed(() => {
            if (!status.value.preview?.length) return []
            const bruttoValues = [...new Set(status.value.preview.map(r => r.brutto_monat))].sort((a, b) => a - b)
            return bruttoValues.map(brutto => {
                const row = { brutto }
                for (let sk = 1; sk <= 6; sk++) {
                    const entry = status.value.preview.find(r => r.brutto_monat == brutto && r.steuerklasse == sk)
                    row[sk] = entry || null
                }
                return row
            })
        })

        const calcResultItems = computed(() => {
            if (!calcResult.value) return []
            const r = calcResult.value
            const fmt = v => formatCurrency(v || 0)
            return [
                { label: t('HrView.payroll.brutto'),      value: fmt(calcForm.value.brutto),    color: undefined },
                { label: t('HrView.payroll.lohnsteuer'),  value: fmt(r.lohnsteuer_monat),       color: undefined },
                { label: t('HrView.payroll.soli'),        value: fmt(r.soli_monat),             color: undefined },
                { label: t('HrView.payroll.kirchensteuer'), value: fmt(r.kist_monat),           color: undefined },
                { label: t('HrView.payroll.netto'),       value: fmt(
                    calcForm.value.brutto - r.lohnsteuer_monat - r.soli_monat - r.kist_monat
                ), color: 'success' },
                { label: 'zvE/Jahr',                      value: fmt(r.zve_jahr),               color: undefined },
            ]
        })

        function formatCurrency(v) {
            return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v || 0)
        }

        function formatDate(d) {
            if (!d) return ''
            return new Date(d).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        }

        async function loadStatus() {
            loadingStatus.value = true
            try {
                status.value = await hr.fetchLohnsteuerTableStatus()
            } finally {
                loadingStatus.value = false
            }
        }

        async function buildTable() {
            building.value = true
            try {
                const employeeId = oserp.session?.logged_in_employee?.id || 0
                const result = await hr.buildLohnsteuerTable({ year: selectedYear.value, employee_id: employeeId })
                toasts.success(t('HrView.tax.built', { year: selectedYear.value, count: result.row_count }))
                await loadStatus()
            } catch {
                toasts.error(t('HrView.tax.buildError'))
            } finally {
                building.value = false
            }
        }

        let calcTimer = null
        async function calcLive() {
            clearTimeout(calcTimer)
            calcTimer = setTimeout(async () => {
                if (!calcForm.value.brutto) { calcResult.value = null; return }
                calcLoading.value = true
                try {
                    calcResult.value = await hr.previewLohnsteuer({
                        brutto_monat: calcForm.value.brutto,
                        steuerklasse: calcForm.value.steuerklasse,
                        kirchensteuer: calcForm.value.kirchensteuer,
                        year: currentYear
                    })
                } finally {
                    calcLoading.value = false
                }
            }, 400)
        }

        onMounted(() => {
            loadStatus()
            calcLive()
        })

        return {
            t, currentYear, selectedYear, yearItems, status, loadingStatus, building,
            calcForm, calcResult, calcLoading, calcResultItems, previewRows,
            formatCurrency, formatDate, buildTable, calcLive
        }
    }
}
</script>
