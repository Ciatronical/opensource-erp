<!-- src/core/views/hr/tabs/hr.settings.tab.vue -->
<!--
    Mitarbeiterdaten: links die Mitarbeiterliste, rechts die Personalakte des
    gewählten Mitarbeiters mit Personalfragebogen, Gehalt und Dokumenten.
    Aus der Akte heraus entstehen Personalschreiben (Abmahnung, Kündigung,
    Aufhebungsvertrag) aus Vorlagen.

    Der ganze Reiter verlangt das Recht Personalverwaltung („hr_management",
    Backend prüft dasselbe); der Hub blendet ihn ohne das Recht aus.
-->
<template>
    <div>
        <div class="hr-personnel__bar">
            <span class="hr-personnel__intro">{{ t('HrView.settings.title') }}</span>
            <v-spacer />
            <template v-if="canManage">
                <v-switch
                    v-model="showInactive"
                    :label="t('HrView.personnel.showInactive')"
                    color="primary"
                    density="compact"
                    hide-details
                    @update:model-value="loadOverview"
                />
                <v-btn size="small" variant="tonal" prepend-icon="mdi-clipboard-outline" :loading="printingBlank" @click="printBlank">
                    {{ t('HrView.personnel.blankQuestionnaire') }}
                </v-btn>
                <v-btn size="small" variant="tonal" prepend-icon="mdi-file-cog-outline" @click="templatesDialog = true">
                    {{ t('HrView.personnel.templates.title') }}
                </v-btn>
            </template>
        </div>

        <v-progress-linear v-if="loading" indeterminate color="primary" class="hr-personnel__progress" />

        <v-row density="comfortable">
            <!-- Mitarbeiterliste -->
            <v-col cols="12" md="4" lg="3">
                <section-card :title="t('HrView.settings.employee')" icon="mdi-account-group-outline" plain>
                    <div class="hr-personnel__search">
                        <v-text-field
                            v-model="search"
                            :placeholder="t('HrView.personnel.search')"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                        />
                    </div>
                    <p v-if="!loading && visibleEmployees.length === 0" class="hr-personnel__empty">
                        {{ t('HrView.settings.noEmployees') }}
                    </p>
                    <v-list density="compact" class="hr-personnel__list">
                        <v-list-item
                            v-for="emp in visibleEmployees"
                            :key="emp.employee_id"
                            :active="emp.employee_id === selected_id"
                            color="primary"
                            @click="select(emp.employee_id)"
                        >
                            <v-list-item-title :class="{ 'hr-personnel__inactive': emp.deleted }">{{ emp.name }}</v-list-item-title>
                            <v-list-item-subtitle>
                                {{ emp.job_title || (emp.deleted ? t('HrView.personnel.inactive') : emp.login) }}
                            </v-list-item-subtitle>
                            <template v-if="canManage" #append>
                                <v-icon
                                    v-if="deadline(emp)"
                                    size="small"
                                    color="warning"
                                    :title="deadline(emp)"
                                >mdi-calendar-alert</v-icon>
                                <v-icon
                                    v-if="!emp.has_data && !emp.deleted"
                                    size="small"
                                    color="warning"
                                    class="ms-1"
                                    :title="t('HrView.personnel.questionnaireMissing')"
                                >mdi-clipboard-alert-outline</v-icon>
                                <v-chip v-if="emp.document_count > 0" size="x-small" variant="tonal" class="ms-1" prepend-icon="mdi-paperclip">
                                    {{ emp.document_count }}
                                </v-chip>
                            </template>
                        </v-list-item>
                    </v-list>
                </section-card>
            </v-col>

            <!-- Personalakte -->
            <v-col cols="12" md="8" lg="9">
                <div v-if="!current" class="hr-personnel__placeholder">
                    <v-icon size="48">mdi-account-box-outline</v-icon>
                    <p>{{ t('HrView.personnel.selectEmployee') }}</p>
                </div>

                <template v-else>
                    <div class="hr-personnel__head">
                        <h2 class="hr-personnel__name">{{ current.name }}</h2>
                        <v-chip v-if="current.personnel_number" size="small" variant="tonal">
                            {{ t('HrView.personnel.personnelNumber', { number: current.personnel_number }) }}
                        </v-chip>
                        <v-chip v-if="current.entry_date" size="small" variant="tonal" prepend-icon="mdi-calendar-start">
                            {{ t('HrView.personnel.since', { date: formatDate(current.entry_date) }) }}
                        </v-chip>
                        <v-chip v-if="current.deleted" size="small" color="grey" variant="tonal">{{ t('HrView.personnel.inactive') }}</v-chip>
                        <v-chip v-if="deadline(current)" size="small" color="warning" variant="tonal" prepend-icon="mdi-calendar-alert">
                            {{ deadline(current) }}
                        </v-chip>
                    </div>

                    <v-tabs v-model="section" color="primary" density="compact" class="hr-personnel__tabs">
                        <v-tab v-if="canManage" value="questionnaire" prepend-icon="mdi-clipboard-account-outline">
                            {{ t('HrView.personnel.tabs.questionnaire') }}
                        </v-tab>
                        <v-tab value="salary" prepend-icon="mdi-cash-multiple">
                            {{ t('HrView.personnel.tabs.salary') }}
                        </v-tab>
                        <v-tab v-if="canManage" value="documents" prepend-icon="mdi-folder-account-outline">
                            {{ t('HrView.personnel.tabs.documents') }}
                            <v-chip v-if="file?.documents?.length" size="x-small" variant="tonal" class="ms-1">{{ file.documents.length }}</v-chip>
                        </v-tab>
                    </v-tabs>

                    <v-progress-linear v-if="fileLoading" indeterminate color="primary" />

                    <template v-if="section === 'questionnaire' && file">
                        <HrPersonnelQuestionnaire
                            ref="questionnaire"
                            :employee_id="current.employee_id"
                            :data="file.data"
                            @saved="onQuestionnaireSaved"
                        />
                    </template>

                    <template v-if="section === 'salary'">
                        <HrPersonnelSalary v-if="currentSalary" :salary="currentSalary" />
                        <p v-else class="hr-personnel__empty">{{ t('HrView.personnel.salary.inactive') }}</p>
                    </template>

                    <template v-if="section === 'documents' && file">
                        <HrPersonnelDocuments
                            :employee_id="current.employee_id"
                            :documents="file.documents"
                            :categories="categories"
                            @changed="reloadFile"
                            @letter="letterDialog = true"
                        />
                    </template>
                </template>
            </v-col>
        </v-row>

        <HrPersonnelLetterDialog
            v-model="letterDialog"
            :employee="current"
            :templates="templates"
            :categories="categories"
            @saved="reloadFile"
        />
        <HrPersonnelTemplatesDialog
            v-model="templatesDialog"
            :templates="templates"
            :categories="categories"
            @changed="loadOverview"
        />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import SectionCard from '@/core/components/layout/section-card.vue'
import HrPersonnelQuestionnaire from './personnel/hr.personnel.questionnaire.vue'
import HrPersonnelSalary from './personnel/hr.personnel.salary.vue'
import HrPersonnelDocuments from './personnel/hr.personnel.documents.vue'
import HrPersonnelLetterDialog from './personnel/hr.personnel.letter.dialog.vue'
import HrPersonnelTemplatesDialog from './personnel/hr.personnel.templates.dialog.vue'
import * as toasts from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'
import { openBase64Pdf } from '@/core/utils/download.js'

// Vorwarnzeit für Fristen in der Mitarbeiterliste (Tage)
const DEADLINE_WARNING_DAYS = 45

const { t, locale } = useI18n()
const hr = hrStore()
const oserp = oserpStore()

const canManage = computed(() => oserp.canManageHr())

const loading = ref(false)
const fileLoading = ref(false)
const printingBlank = ref(false)
const showInactive = ref(false)
const search = ref('')

const hr_salary_settings = ref([])   // Zeilen aus getSalarySettings
const overview = ref({ employees: [], categories: [], templates: [] })
const selected_id = ref(null)
const file = ref(null)               // Personalakte des gewählten Mitarbeiters
const section = ref('salary')
const questionnaire = ref(null)
const letterDialog = ref(false)
const templatesDialog = ref(false)

const categories = computed(() => overview.value.categories || [])
const templates = computed(() => overview.value.templates || [])

// Mit Recht: Personalübersicht (auch Ausgeschiedene). Ohne: die Gehaltsliste wie bisher.
const employees = computed(() => canManage.value
    ? overview.value.employees || []
    : hr_salary_settings.value.map(s => ({ employee_id: s.employee_id, name: s.name, login: s.login, deleted: false })))

const visibleEmployees = computed(() => {
    const term = (search.value || '').trim().toLowerCase()
    if (!term) return employees.value
    return employees.value.filter(e => [e.name, e.login, e.job_title, e.personnel_number]
        .some(v => String(v || '').toLowerCase().includes(term)))
})

const current = computed(() => employees.value.find(e => e.employee_id === selected_id.value) || null)
const currentSalary = computed(() => hr_salary_settings.value.find(s => s.employee_id === selected_id.value) || null)

function formatDate(value) {
    if (!value) return ''
    const date = new Date(value + 'T00:00:00')
    return isNaN(date) ? '' : date.toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' })
}

// Nächste Frist eines Mitarbeiters innerhalb der Vorwarnzeit: Probezeit oder Befristung
function deadline(emp) {
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const limit = new Date(today.getTime() + DEADLINE_WARNING_DAYS * 86400000)
    for (const [field, key] of [['probation_end', 'probationEnds'], ['limited_until', 'limitedEnds']]) {
        if (!emp?.[field]) continue
        const date = new Date(emp[field] + 'T00:00:00')
        if (date >= today && date <= limit) return t('HrView.personnel.' + key, { date: formatDate(emp[field]) })
    }
    return ''
}

async function loadOverview() {
    if (!canManage.value) return
    try {
        overview.value = await hr.fetchPersonnelOverview(showInactive.value)
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    }
}

async function load() {
    loading.value = true
    try {
        const [salary] = await Promise.all([hr.fetchSalarySettings(), loadOverview()])
        hr_salary_settings.value = salary
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        loading.value = false
    }
}

async function reloadFile() {
    if (!canManage.value || !selected_id.value) return
    fileLoading.value = true
    try {
        const [result] = await Promise.all([hr.fetchPersonnelFile(selected_id.value), loadOverview()])
        file.value = result
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        fileLoading.value = false
    }
}

async function select(employee_id) {
    if (employee_id === selected_id.value) return
    // Ungespeicherte Angaben im Fragebogen nicht stillschweigend verwerfen
    if (questionnaire.value?.dirty) {
        const answer = await alerts.question(
            t('HrView.personnel.discardConfirm'),
            t('HrView.personnel.questionnaire.unsaved'),
            t('HrView.personnel.discard'),
            t('HrView.cancel')
        )
        if (!answer?.isConfirmed) return
    }
    selected_id.value = employee_id
    file.value = null
    if (!canManage.value) {
        section.value = 'salary'
        return
    }
    if (section.value === 'salary' && !currentSalary.value) section.value = 'questionnaire'
    fileLoading.value = true
    try {
        file.value = await hr.fetchPersonnelFile(employee_id)
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        fileLoading.value = false
    }
}

function onQuestionnaireSaved(data) {
    if (file.value) file.value.data = data
    loadOverview()
}

async function printBlank() {
    printingBlank.value = true
    try {
        const result = await hr.fetchPersonnelQuestionnairePdf(0, true)
        openBase64Pdf(result.pdf, result.filename)
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        printingBlank.value = false
    }
}

onMounted(() => {
    if (canManage.value) section.value = 'questionnaire'
    load()
})
</script>

<style scoped>
.hr-personnel__bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
    margin-bottom: var(--oserp-gap);
}

.hr-personnel__intro,
.hr-personnel__empty {
    font-size: 0.875rem;
    color: var(--oserp-text-muted);
}

.hr-personnel__empty {
    padding: var(--oserp-gap);
}

.hr-personnel__progress {
    margin-bottom: var(--oserp-gap);
}

.hr-personnel__search {
    padding: var(--oserp-gap-sm);
}

.hr-personnel__list {
    max-height: 70vh;
    overflow-y: auto;
}

.hr-personnel__inactive {
    color: var(--oserp-text-muted);
    text-decoration: line-through;
}

.hr-personnel__placeholder {
    padding: var(--oserp-gap-xl);
    text-align: center;
    color: var(--oserp-text-muted);
    border: 1px dashed var(--oserp-border);
    border-radius: var(--oserp-radius);
}

.hr-personnel__head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
    margin-bottom: var(--oserp-gap-sm);
}

.hr-personnel__name {
    margin: 0;
    font-size: 1.25rem;
    font-weight: var(--oserp-section-title-weight);
}

.hr-personnel__tabs {
    margin-bottom: var(--oserp-gap);
}
</style>
