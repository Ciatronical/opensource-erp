<template>
    <div>
        <div class="d-flex align-center mb-4 flex-wrap ga-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('HrView.settings.title') }}</span>
            <v-spacer />
            <v-btn size="small" variant="tonal" prepend-icon="mdi-unfold-more-horizontal" @click="expandAll">
                {{ t('HrView.settings.expandAll') }}
            </v-btn>
            <v-btn size="small" variant="tonal" prepend-icon="mdi-unfold-less-horizontal" @click="collapseAll">
                {{ t('HrView.settings.collapseAll') }}
            </v-btn>
        </div>

        <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />

        <v-card v-if="!loading && employees.length === 0" variant="outlined" class="text-center pa-8">
            <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-account-off</v-icon>
            <p class="text-body-2 text-medium-emphasis">{{ t('HrView.settings.noEmployees') }}</p>
        </v-card>

        <v-expansion-panels v-model="openPanels" multiple>
            <v-expansion-panel
                v-for="emp in employees"
                :key="emp.employee_id"
                :value="emp.employee_id"
            >
                <v-expansion-panel-title>
                    <div class="d-flex align-center ga-3 w-100">
                        <v-icon color="primary">mdi-account</v-icon>
                        <span class="font-weight-medium">{{ emp.name }}</span>
                        <v-chip
                            v-if="emp.brutto > 0"
                            size="x-small"
                            color="success"
                            variant="tonal"
                            class="ml-2"
                        >
                            {{ formatCurrency(emp.brutto) }} Brutto
                        </v-chip>
                        <v-chip
                            v-else
                            size="x-small"
                            color="warning"
                            variant="tonal"
                            class="ml-2"
                        >
                            Kein Gehalt hinterlegt
                        </v-chip>
                        <v-spacer />
                        <span class="text-caption text-medium-emphasis mr-2">SK {{ emp.steuerklasse }}</span>
                    </div>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <v-form @submit.prevent="save(emp)">
                        <v-row dense>
                            <v-col cols="12" sm="6" md="4">
                                <v-text-field
                                    v-model.number="emp.brutto"
                                    :label="t('HrView.settings.brutto')"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    prefix="€"
                                    variant="outlined"
                                    density="comfortable"
                                    hide-details
                                />
                            </v-col>
                            <v-col cols="12" sm="6" md="4">
                                <v-select
                                    v-model="emp.steuerklasse"
                                    :label="t('HrView.settings.steuerklasse')"
                                    :items="[1,2,3,4,5,6]"
                                    variant="outlined"
                                    density="comfortable"
                                    hide-details
                                />
                            </v-col>
                        </v-row>

                        <v-divider class="my-3" />
                        <p class="text-caption font-weight-medium mb-2">{{ t('HrView.settings.anRates') }}</p>
                        <v-row dense>
                            <v-col v-for="field in svAnFields" :key="field.key" cols="6" sm="3">
                                <v-text-field
                                    v-model.number="emp[field.key]"
                                    :label="field.label"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.001"
                                    suffix="%"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                />
                            </v-col>
                        </v-row>

                        <p class="text-caption font-weight-medium mb-2 mt-3">{{ t('HrView.settings.agRates') }}</p>
                        <v-row dense>
                            <v-col v-for="field in svAgFields" :key="field.key" cols="6" sm="3">
                                <v-text-field
                                    v-model.number="emp[field.key]"
                                    :label="field.label"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.001"
                                    suffix="%"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                />
                            </v-col>
                        </v-row>

                        <v-text-field
                            v-model="emp.notes"
                            :label="t('HrView.settings.notes')"
                            variant="outlined"
                            density="comfortable"
                            hide-details
                            class="mt-3"
                        />

                        <div class="d-flex ga-2 mt-3">
                            <v-btn
                                color="primary"
                                size="small"
                                type="submit"
                                :loading="saving[emp.employee_id]"
                                prepend-icon="mdi-content-save"
                            >
                                {{ t('HrView.settings.save') }}
                            </v-btn>
                            <v-btn
                                size="small"
                                variant="tonal"
                                prepend-icon="mdi-refresh"
                                @click="resetRates(emp)"
                            >
                                {{ t('HrView.settings.resetRates') }}
                            </v-btn>
                        </div>
                    </v-form>
                </v-expansion-panel-text>
            </v-expansion-panel>
        </v-expansion-panels>
    </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import * as toasts from '@/core/utils/toasts.js'

const DEFAULT_RATES = {
    kv_prozent: 8.150, pv_prozent: 1.700, rv_prozent: 9.300, av_prozent: 1.300,
    kv_prozent_ag: 7.300, pv_prozent_ag: 1.700, rv_prozent_ag: 9.300, av_prozent_ag: 1.300
}

export default {
    name: 'HrSettingsTab',
    setup() {
        const { t } = useI18n()
        const hr = hrStore()

        const employees = ref([])
        const loading = ref(false)
        const saving = ref({})
        const openPanels = ref([])

        const svAnFields = computed(() => [
            { key: 'kv_prozent', label: t('HrView.settings.kv') },
            { key: 'pv_prozent', label: t('HrView.settings.pv') },
            { key: 'rv_prozent', label: t('HrView.settings.rv') },
            { key: 'av_prozent', label: t('HrView.settings.av') }
        ])

        const svAgFields = computed(() => [
            { key: 'kv_prozent_ag', label: t('HrView.settings.kv') },
            { key: 'pv_prozent_ag', label: t('HrView.settings.pv') },
            { key: 'rv_prozent_ag', label: t('HrView.settings.rv') },
            { key: 'av_prozent_ag', label: t('HrView.settings.av') }
        ])

        function formatCurrency(v) {
            return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v)
        }

        function resetRates(emp) {
            Object.assign(emp, DEFAULT_RATES)
        }

        function expandAll() {
            openPanels.value = employees.value.map(e => e.employee_id)
        }

        function collapseAll() {
            openPanels.value = []
        }

        async function load() {
            loading.value = true
            try {
                employees.value = await hr.fetchSalarySettings()
            } finally {
                loading.value = false
            }
        }

        async function save(emp) {
            saving.value[emp.employee_id] = true
            try {
                await hr.saveSalarySettings(emp)
                toasts.success(t('HrView.settings.saved'))
            } catch {
                toasts.error(t('HrView.settings.saveError'))
            } finally {
                saving.value[emp.employee_id] = false
            }
        }

        onMounted(load)

        return {
            t, employees, loading, saving, openPanels,
            svAnFields, svAgFields, formatCurrency,
            resetRates, expandAll, collapseAll, save
        }
    }
}
</script>
