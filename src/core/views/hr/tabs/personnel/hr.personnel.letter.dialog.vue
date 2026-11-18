<!-- src/core/views/hr/tabs/personnel/hr.personnel.letter.dialog.vue -->
<!--
    Personalschreiben erstellen: Vorlage wählen (Abmahnung, Kündigung,
    Aufhebungsvertrag …), der Text kommt mit den Angaben des Mitarbeiters
    gefüllt in den Editor. Stellen in eckigen Klammern ergänzt der Benutzer.
    „Ablegen und drucken" erzeugt das PDF und legt es in die Personalakte.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="980" scrollable persistent @update:model-value="emit('update:modelValue', $event)">
        <v-card>
            <v-card-title class="hr-letter__title">
                <v-icon class="me-2">mdi-file-document-edit-outline</v-icon>
                {{ t('HrView.personnel.letter.title', { name: employee?.name || '' }) }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" size="small" @click="close" />
            </v-card-title>
            <v-divider />
            <v-card-text>
                <v-defaults-provider :defaults="fieldDefaults">
                    <v-row density="compact">
                        <v-col cols="12" md="6">
                            <v-select
                                v-model="template"
                                :label="t('HrView.personnel.letter.template')"
                                :items="templateItems"
                                return-object
                                :loading="loading"
                                @update:model-value="loadDraft"
                            />
                        </v-col>
                        <v-col cols="12" md="6" class="d-flex align-center">
                            <span class="hr-letter__hint">{{ t('HrView.personnel.letter.templateHint') }}</span>
                        </v-col>
                    </v-row>

                    <template v-if="letter">
                        <v-alert v-if="letter.address_missing" type="warning" variant="tonal" density="compact" class="hr-letter__alert">
                            {{ t('HrView.personnel.letter.addressMissing') }}
                        </v-alert>
                        <v-alert type="info" variant="tonal" density="compact" class="hr-letter__alert">
                            {{ t('HrView.personnel.letter.legalHint') }}
                        </v-alert>

                        <v-row density="compact">
                            <v-col cols="12" md="5">
                                <v-text-field v-model="title" :label="t('HrView.personnel.letter.fileTitle')" />
                            </v-col>
                            <v-col cols="12" sm="6" md="4">
                                <v-select v-model="category" :label="t('HrView.personnel.documents.category')" :items="categoryItems" />
                            </v-col>
                            <v-col cols="12" sm="6" md="3">
                                <v-text-field v-model="letter.date" type="date" :label="t('HrView.personnel.letter.date')" />
                            </v-col>
                            <v-col cols="12">
                                <v-text-field v-model="letter.subject" :label="t('HrView.personnel.letter.subject')" prepend-inner-icon="mdi-format-title" />
                            </v-col>
                        </v-row>

                        <HtmlEditorComponent v-model="letter.body" :min-height="320" :max-height="0" class="hr-letter__editor" />
                        <p class="hr-letter__hint">
                            <v-icon size="14" class="me-1">mdi-code-brackets</v-icon>{{ t('HrView.personnel.letter.bracketHint') }}
                        </p>

                        <v-row density="compact">
                            <v-col cols="12" sm="6">
                                <v-select v-model="letter.signature_mode" :label="t('HrView.personnel.templates.signatureMode')" :items="signatureItems" />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-switch v-model="letter.receipt" :label="t('HrView.personnel.templates.receipt')" color="primary" density="compact" hide-details />
                            </v-col>
                        </v-row>
                    </template>
                </v-defaults-provider>
            </v-card-text>
            <v-divider />
            <v-card-actions class="hr-letter__actions">
                <v-chip v-if="openBrackets" size="small" color="warning" variant="tonal" prepend-icon="mdi-alert-outline">
                    {{ t('HrView.personnel.letter.openBrackets', { count: openBrackets }) }}
                </v-chip>
                <v-spacer />
                <v-btn variant="text" @click="close">{{ t('HrView.cancel') }}</v-btn>
                <v-btn variant="tonal" :disabled="!letter" :loading="previewing" prepend-icon="mdi-eye-outline" @click="preview">
                    {{ t('HrView.personnel.letter.preview') }}
                </v-btn>
                <v-btn color="primary" variant="flat" :disabled="!canSave" :loading="saving" prepend-icon="mdi-folder-download-outline" @click="save">
                    {{ t('HrView.personnel.letter.fileAndPrint') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import HtmlEditorComponent from '@/core/components/html.editor.component.vue'
import * as toasts from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'
import { openBase64Pdf } from '@/core/utils/download.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    employee: { type: Object, default: null },
    templates: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue', 'saved'])

const { t, te } = useI18n()
const hr = hrStore()

const fieldDefaults = {
    VTextField: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VSelect: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
}

const template = ref(null)
const letter = ref(null)
const title = ref('')
const category = ref('')
const loading = ref(false)
const previewing = ref(false)
const saving = ref(false)

const templateItems = computed(() => props.templates.map(tpl => ({ ...tpl, title: tpl.name, value: tpl.id ? 'id:' + tpl.id : 'key:' + tpl.default_key })))

const categoryItems = computed(() => props.categories.map(c => {
    const key = 'HrView.personnel.categories.' + c.key
    return { value: c.key, title: te(key) ? t(key) : c.name }
}))

const signatureItems = computed(() => ['letter', 'contract'].map(value => ({ value, title: t('HrView.personnel.templates.signatureModes.' + value) })))

// Noch nicht ausgefüllte Stellen: [Beendigungsdatum], [Betrag] …
const openBrackets = computed(() => (String(letter.value?.body || '').match(/\[[^\]]{2,}\]/g) || []).length)

const canSave = computed(() => !!letter.value && !!title.value.trim() && !!category.value)

// Jedes Öffnen beginnt leer — ein halb geschriebenes Schreiben für einen
// anderen Mitarbeiter darf nicht stehen bleiben
watch(() => props.modelValue, open => {
    if (open) {
        template.value = null
        letter.value = null
        title.value = ''
        category.value = ''
    }
})

async function loadDraft(tpl) {
    if (!tpl || !props.employee) return
    loading.value = true
    try {
        const draft = await hr.fetchPersonnelLetterDraft(props.employee.employee_id, tpl)
        letter.value = draft
        title.value = draft.title
        category.value = draft.category
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        loading.value = false
    }
}

async function preview() {
    previewing.value = true
    try {
        const result = await hr.fetchPersonnelLetterPdf(props.employee.employee_id, letter.value)
        openBase64Pdf(result.pdf, result.filename)
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        previewing.value = false
    }
}

async function save() {
    if (openBrackets.value) {
        const answer = await alerts.question(
            t('HrView.personnel.letter.openBracketsConfirm', { count: openBrackets.value }),
            t('HrView.personnel.letter.fileAndPrint'),
            t('HrView.personnel.letter.fileAnyway'),
            t('HrView.cancel')
        )
        if (!answer?.isConfirmed) return
    }
    saving.value = true
    try {
        const result = await hr.savePersonnelLetter(props.employee.employee_id, category.value, title.value.trim(), letter.value)
        openBase64Pdf(result.pdf, result.filename)
        toasts.success(t('HrView.personnel.letter.filed'))
        emit('saved', result.document)
        emit('update:modelValue', false)
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        saving.value = false
    }
}

function close() {
    emit('update:modelValue', false)
}
</script>

<style scoped>
.hr-letter__title {
    display: flex;
    align-items: center;
}

.hr-letter__alert {
    margin: var(--oserp-gap-sm) 0;
}

.hr-letter__editor {
    margin-top: var(--oserp-gap);
}

.hr-letter__hint {
    margin: var(--oserp-gap-xs) 0 var(--oserp-gap);
    font-size: 0.8125rem;
    color: var(--oserp-text-muted);
}

.hr-letter__actions {
    padding: var(--oserp-gap-sm) var(--oserp-gap-lg);
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
}
</style>
