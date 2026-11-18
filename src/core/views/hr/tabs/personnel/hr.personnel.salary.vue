<!-- src/core/views/hr/tabs/personnel/hr.personnel.salary.vue -->
<!--
    Gehaltseinstellungen eines Mitarbeiters: Brutto, Steuerklasse und die
    Sozialversicherungssätze (Arbeitnehmer- und Arbeitgeberanteil).
-->
<template>
    <v-form @submit.prevent="save">
        <div class="hr-salary__sections">
            <section-card :title="t('HrView.personnel.salary.title')" icon="mdi-cash-multiple">
                <v-row density="compact">
                    <v-col cols="12" sm="6" md="4">
                        <v-text-field
                            v-model.number="salary.brutto"
                            :label="t('HrView.settings.brutto')"
                            type="number"
                            min="0"
                            step="0.01"
                            prefix="€"
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="4">
                        <v-select
                            v-model="salary.steuerklasse"
                            :label="t('HrView.settings.steuerklasse')"
                            :items="[1, 2, 3, 4, 5, 6]"
                        />
                    </v-col>
                    <v-col cols="12">
                        <v-text-field v-model="salary.notes" :label="t('HrView.settings.notes')" />
                    </v-col>
                </v-row>
            </section-card>

            <section-card :title="t('HrView.settings.svRates')" icon="mdi-shield-account-outline">
                <template #actions>
                    <v-btn size="small" variant="tonal" prepend-icon="mdi-refresh" @click="resetRates">
                        {{ t('HrView.settings.resetRates') }}
                    </v-btn>
                </template>
                <p class="hr-salary__group">{{ t('HrView.settings.anRates') }}</p>
                <v-row density="compact">
                    <v-col v-for="field in rateFields" :key="field.an" cols="6" sm="3">
                        <v-text-field v-model.number="salary[field.an]" :label="field.label" type="number" min="0" max="100" step="0.001" suffix="%" />
                    </v-col>
                </v-row>
                <p class="hr-salary__group">{{ t('HrView.settings.agRates') }}</p>
                <v-row density="compact">
                    <v-col v-for="field in rateFields" :key="field.ag" cols="6" sm="3">
                        <v-text-field v-model.number="salary[field.ag]" :label="field.label" type="number" min="0" max="100" step="0.001" suffix="%" />
                    </v-col>
                </v-row>
            </section-card>
        </div>

        <v-btn color="primary" type="submit" :loading="saving" prepend-icon="mdi-content-save-outline" class="hr-salary__save">
            {{ t('HrView.settings.save') }}
        </v-btn>
    </v-form>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import SectionCard from '@/core/components/layout/section-card.vue'
import * as toasts from '@/core/utils/toasts.js'

const DEFAULT_RATES = {
    kv_prozent: 8.150, pv_prozent: 1.700, rv_prozent: 9.300, av_prozent: 1.300,
    kv_prozent_ag: 7.300, pv_prozent_ag: 1.700, rv_prozent_ag: 9.300, av_prozent_ag: 1.300
}

// salary: Zeile aus getSalarySettings — wird direkt bearbeitet, die
// Mitarbeiterliste zeigt Brutto und Steuerklasse daraus an
const props = defineProps({
    salary: { type: Object, required: true },
})

const { t } = useI18n()
const hr = hrStore()
const saving = ref(false)

const rateFields = computed(() => [
    { an: 'kv_prozent', ag: 'kv_prozent_ag', label: t('HrView.settings.kv') },
    { an: 'pv_prozent', ag: 'pv_prozent_ag', label: t('HrView.settings.pv') },
    { an: 'rv_prozent', ag: 'rv_prozent_ag', label: t('HrView.settings.rv') },
    { an: 'av_prozent', ag: 'av_prozent_ag', label: t('HrView.settings.av') },
])

function resetRates() {
    Object.assign(props.salary, DEFAULT_RATES)
}

async function save() {
    saving.value = true
    try {
        await hr.saveSalarySettings(props.salary)
        toasts.success(t('HrView.settings.saved'))
    } catch {
        toasts.error(t('HrView.settings.saveError'))
    } finally {
        saving.value = false
    }
}
</script>

<style scoped>
.hr-salary__sections {
    display: flex;
    flex-direction: column;
    gap: var(--oserp-gap);
}

.hr-salary__group {
    margin: var(--oserp-gap-sm) 0;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--oserp-text-muted);
}

.hr-salary__save {
    margin-top: var(--oserp-gap);
}
</style>
