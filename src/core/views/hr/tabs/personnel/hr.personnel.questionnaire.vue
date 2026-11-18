<!-- src/core/views/hr/tabs/personnel/hr.personnel.questionnaire.vue -->
<!--
    Personalfragebogen eines Mitarbeiters: Stammdaten für die Lohnabrechnung.
    Die Feldliste (Abschnitte, Feldtyp, Auswahlwerte) steht einmal im Skript;
    das Formular wird daraus aufgebaut. Gespeichert wird der ganze Bogen.
-->
<template>
    <v-form ref="form" @submit.prevent="save">
        <div class="hr-questionnaire__bar">
            <v-btn color="primary" type="submit" :loading="saving" prepend-icon="mdi-content-save-outline">
                {{ t('HrView.save') }}
            </v-btn>
            <v-btn variant="tonal" :loading="printing" prepend-icon="mdi-printer-outline" @click="print">
                {{ t('HrView.personnel.questionnaire.print') }}
            </v-btn>
            <v-chip v-if="dirty" size="small" color="warning" variant="tonal" prepend-icon="mdi-pencil-outline">
                {{ t('HrView.personnel.questionnaire.unsaved') }}
            </v-chip>
        </div>

        <div class="hr-questionnaire__sections">
            <section-card
                v-for="section in sections"
                :key="section.key"
                :title="t('HrView.personnel.questionnaire.sections.' + section.key)"
                :icon="section.icon"
            >
                <v-row density="compact">
                    <v-col v-for="field in section.fields" :key="field.key" cols="12" :sm="field.wide ? 12 : 6" :md="field.wide ? 12 : 4">
                        <v-select
                            v-if="field.options"
                            v-model="record[field.key]"
                            :label="label(field)"
                            :items="options(field)"
                            clearable
                        />
                        <v-switch
                            v-else-if="field.type === 'boolean'"
                            v-model="record[field.key]"
                            :label="label(field)"
                        />
                        <v-textarea
                            v-else-if="field.type === 'textarea'"
                            v-model="record[field.key]"
                            :label="label(field)"
                            rows="2"
                            auto-grow
                        />
                        <v-text-field
                            v-else
                            v-model="record[field.key]"
                            :label="label(field)"
                            :type="field.type || 'text'"
                            :step="field.step"
                            :min="field.type === 'number' ? 0 : undefined"
                            :rules="field.rules || []"
                            :autocomplete="'off'"
                        />
                    </v-col>
                </v-row>
            </section-card>
        </div>
    </v-form>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import SectionCard from '@/core/components/layout/section-card.vue'
import * as toasts from '@/core/utils/toasts.js'
import { openBase64Pdf } from '@/core/utils/download.js'

const props = defineProps({
    employee_id: { type: Number, required: true },
    data: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['saved'])

const { t } = useI18n()
const hr = hrStore()

// Prüfungen: nur Form, keine Pflichtfelder — ein Bogen wird oft in Etappen gefüllt
const taxIdRule = v => !v || /^\d{11}$/.test(String(v).replace(/\s/g, '')) || t('HrView.personnel.questionnaire.rules.taxId')
const ibanRule = v => !v || /^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(String(v).replace(/\s/g, '').toUpperCase()) || t('HrView.personnel.questionnaire.rules.iban')
const svRule = v => !v || /^\d{8}[A-Z]\d{3}$/.test(String(v).replace(/\s/g, '').toUpperCase()) || t('HrView.personnel.questionnaire.rules.svNumber')
const emailRule = v => !v || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) || t('HrView.personnel.questionnaire.rules.email')

const sections = [
    { key: 'personal', icon: 'mdi-account-outline', fields: [
        { key: 'salutation', options: ['mr', 'mrs'] },
        { key: 'first_name' }, { key: 'last_name' },
        { key: 'birth_name' }, { key: 'birth_date', type: 'date' }, { key: 'birth_place' },
        { key: 'birth_country' }, { key: 'nationality' },
        { key: 'gender', options: ['male', 'female', 'diverse', 'unspecified'] },
        { key: 'marital_status', options: ['single', 'married', 'partnership', 'separated', 'divorced', 'widowed'] },
        { key: 'severely_disabled', type: 'boolean' },
    ] },
    { key: 'contact', icon: 'mdi-map-marker-outline', fields: [
        { key: 'street' }, { key: 'zipcode' }, { key: 'city' }, { key: 'country' },
        { key: 'phone', type: 'tel' }, { key: 'mobile', type: 'tel' },
        { key: 'email', type: 'email', rules: [emailRule] },
        { key: 'emergency_name' }, { key: 'emergency_phone', type: 'tel' },
    ] },
    { key: 'employment', icon: 'mdi-briefcase-outline', fields: [
        { key: 'personnel_number' }, { key: 'job_title' },
        { key: 'employment_type', options: ['full_time', 'part_time', 'minijob', 'trainee', 'working_student', 'temporary'] },
        { key: 'entry_date', type: 'date' }, { key: 'probation_end', type: 'date' }, { key: 'limited_until', type: 'date' },
        { key: 'exit_date', type: 'date' }, { key: 'weekly_hours', type: 'number', step: '0.25' }, { key: 'notice_period' },
        { key: 'main_employment', type: 'boolean' },
        { key: 'other_employments', type: 'textarea', wide: true },
        { key: 'school_degree' }, { key: 'vocational_training' }, { key: 'driving_license' },
    ] },
    { key: 'tax', icon: 'mdi-file-percent-outline', fields: [
        { key: 'tax_id', rules: [taxIdRule] },
        { key: 'child_allowance', type: 'number', step: '0.5' },
        { key: 'denomination' },
    ] },
    { key: 'social', icon: 'mdi-shield-account-outline', fields: [
        { key: 'sv_number', rules: [svRule] }, { key: 'health_insurance' },
        { key: 'health_insurance_type', options: ['statutory', 'voluntary', 'private'] },
        { key: 'has_children', type: 'boolean' }, { key: 'children_count', type: 'number', step: '1' },
    ] },
    { key: 'bank', icon: 'mdi-bank-outline', fields: [
        { key: 'iban', rules: [ibanRule] }, { key: 'bic' }, { key: 'bank_name' }, { key: 'account_holder' },
    ] },
    { key: 'misc', icon: 'mdi-note-text-outline', fields: [
        { key: 'notes', type: 'textarea', wide: true },
    ] },
]

const form = ref(null)
const record = ref({})
const original = ref('')
const saving = ref(false)
const printing = ref(false)

const dirty = computed(() => JSON.stringify(record.value) !== original.value)

function label(field) {
    return t('HrView.personnel.questionnaire.fields.' + field.key)
}

function options(field) {
    return field.options.map(value => ({ value, title: t('HrView.personnel.questionnaire.options.' + field.key + '.' + value) }))
}

// Bogen aus den Akten-Daten übernehmen; fehlende Felder als null, damit die
// Änderungserkennung nicht schon beim ersten Klick in ein Feld anschlägt
function adopt(data) {
    const next = {}
    sections.forEach(section => section.fields.forEach(field => {
        const value = data?.[field.key]
        next[field.key] = field.type === 'boolean' ? !!value : (value ?? null)
    }))
    record.value = next
    original.value = JSON.stringify(next)
}

watch(() => [props.employee_id, props.data], () => adopt(props.data), { immediate: true })

async function save() {
    const { valid } = await form.value.validate()
    if (!valid) {
        toasts.warning(t('HrView.personnel.questionnaire.invalid'))
        return false
    }
    saving.value = true
    try {
        // Leerzeichen aus Nummern entfernen, IBAN und SV-Nummer gross schreiben
        const payload = { ...record.value }
        for (const key of ['iban', 'sv_number', 'bic']) {
            if (payload[key]) payload[key] = String(payload[key]).replace(/\s/g, '').toUpperCase()
        }
        if (payload.tax_id) payload.tax_id = String(payload.tax_id).replace(/\s/g, '')
        const saved = await hr.savePersonnelData(props.employee_id, payload)
        adopt(saved)
        emit('saved', saved)
        toasts.success(t('HrView.personnel.questionnaire.saved'))
        return true
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
        return false
    } finally {
        saving.value = false
    }
}

// Gedruckt wird der gespeicherte Stand — offene Änderungen vorher sichern
async function print() {
    if (dirty.value && !(await save())) return
    printing.value = true
    try {
        const result = await hr.fetchPersonnelQuestionnairePdf(props.employee_id, false)
        openBase64Pdf(result.pdf, result.filename)
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        printing.value = false
    }
}

defineExpose({ dirty })
</script>

<style scoped>
.hr-questionnaire__bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
    margin-bottom: var(--oserp-gap);
}

.hr-questionnaire__sections {
    display: flex;
    flex-direction: column;
    gap: var(--oserp-gap);
}
</style>
