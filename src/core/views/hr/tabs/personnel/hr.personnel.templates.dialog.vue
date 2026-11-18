<!-- src/core/views/hr/tabs/personnel/hr.personnel.templates.dialog.vue -->
<!--
    Vorlagen für Personalschreiben pflegen. Mitgelieferte Vorlagen (Abmahnung,
    Kündigung, Aufhebungsvertrag) lassen sich anpassen — das Original bleibt
    erhalten und kommt mit „Original wiederherstellen" zurück. Eigene Vorlagen
    kommen dazu. Bausteine (Anrede, Name, Eintrittsdatum …) werden beim
    Erstellen eines Schreibens mit den Angaben des Mitarbeiters gefüllt.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="1100" scrollable @update:model-value="emit('update:modelValue', $event)">
        <v-card>
            <v-card-title class="hr-templates__title">
                <v-icon class="me-2">mdi-file-cog-outline</v-icon>
                {{ t('HrView.personnel.templates.title') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" size="small" @click="emit('update:modelValue', false)" />
            </v-card-title>
            <v-divider />
            <v-card-text class="hr-templates__body">
                <div class="hr-templates__list">
                    <v-list density="compact" nav>
                        <v-list-item
                            v-for="tpl in templates"
                            :key="itemKey(tpl)"
                            :active="itemKey(tpl) === itemKey(current)"
                            color="primary"
                            :title="tpl.name"
                            :subtitle="origin(tpl)"
                            @click="select(tpl)"
                        />
                    </v-list>
                    <v-btn variant="tonal" prepend-icon="mdi-plus" class="hr-templates__new" @click="create">
                        {{ t('HrView.personnel.templates.new') }}
                    </v-btn>
                </div>

                <div v-if="current" class="hr-templates__editor">
                    <v-defaults-provider :defaults="fieldDefaults">
                        <v-row density="compact">
                            <v-col cols="12" sm="6">
                                <v-text-field v-model="current.name" :label="t('HrView.personnel.templates.name')" />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-select v-model="current.category" :label="t('HrView.personnel.templates.category')" :items="categoryItems" />
                            </v-col>
                            <v-col cols="12">
                                <v-text-field v-model="current.subject" :label="t('HrView.personnel.letter.subject')" />
                            </v-col>
                        </v-row>

                        <HtmlEditorComponent ref="bodyEditor" v-model="current.body" :min-height="300" :max-height="0" class="hr-templates__html" />

                        <!-- Bausteine: Klick fügt den Platzhalter an der Cursorstelle ein -->
                        <div class="hr-templates__placeholders">
                            <span class="hr-templates__hint">
                                <v-icon size="14" class="me-1">mdi-puzzle-outline</v-icon>{{ t('HrView.personnel.templates.placeholders') }}
                            </span>
                            <v-chip
                                v-for="key in placeholderKeys"
                                :key="key"
                                size="small"
                                variant="tonal"
                                color="primary"
                                @mousedown.prevent
                                @click="bodyEditor?.insertPlaceholder(key, t('HrView.personnel.placeholders.' + key))"
                            >
                                {{ t('HrView.personnel.placeholders.' + key) }}
                            </v-chip>
                        </div>

                        <v-row density="compact">
                            <v-col cols="12" sm="6">
                                <v-select v-model="current.signature_mode" :label="t('HrView.personnel.templates.signatureMode')" :items="signatureItems" />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-switch v-model="current.receipt" :label="t('HrView.personnel.templates.receipt')" color="primary" density="compact" hide-details />
                            </v-col>
                        </v-row>
                    </v-defaults-provider>
                </div>
                <div v-else class="hr-templates__editor hr-templates__hint">
                    {{ t('HrView.personnel.templates.selectHint') }}
                </div>
            </v-card-text>
            <v-divider />
            <v-card-actions class="hr-templates__actions">
                <v-btn
                    v-if="current?.id"
                    color="error"
                    variant="text"
                    :prepend-icon="current.default_key ? 'mdi-restore' : 'mdi-delete-outline'"
                    @click="remove"
                >
                    {{ current.default_key ? t('HrView.personnel.templates.restore') : t('HrView.personnel.templates.delete') }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" @click="emit('update:modelValue', false)">{{ t('HrView.personnel.templates.close') }}</v-btn>
                <v-btn color="primary" variant="flat" :disabled="!canSave" :loading="saving" prepend-icon="mdi-content-save-outline" @click="save">
                    {{ t('HrView.save') }}
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

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    templates: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
})
// changed: Vorlagen wurden gespeichert oder gelöscht (Eltern laden neu)
const emit = defineEmits(['update:modelValue', 'changed'])

const { t, te } = useI18n()
const hr = hrStore()

const fieldDefaults = {
    VTextField: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VSelect: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
}

// Bausteine, die das Backend beim Erstellen eines Schreibens füllt (hrLetterValues)
const placeholderKeys = [
    'greeting', 'salutation', 'full_name', 'first_name', 'last_name', 'street', 'zipcode', 'city',
    'birth_date', 'personnel_number', 'job_title', 'entry_date', 'date',
    'company', 'company_street', 'company_zipcode', 'company_city', 'signer_name',
]

const current = ref(null)
const saving = ref(false)
const bodyEditor = ref(null)

const categoryItems = computed(() => props.categories.map(c => {
    const key = 'HrView.personnel.categories.' + c.key
    return { value: c.key, title: te(key) ? t(key) : c.name }
}))

const signatureItems = computed(() => ['letter', 'contract'].map(value => ({ value, title: t('HrView.personnel.templates.signatureModes.' + value) })))

const canSave = computed(() => !!current.value && !!String(current.value.name || '').trim() && !!current.value.category)

function itemKey(tpl) {
    if (!tpl) return ''
    return tpl.id ? 'id:' + tpl.id : 'key:' + (tpl.default_key || 'new')
}

// Herkunft: mitgeliefert, angepasst (Firmenfassung einer mitgelieferten) oder eigene
function origin(tpl) {
    if (!tpl.id) return t('HrView.personnel.templates.originDefault')
    return tpl.default_key ? t('HrView.personnel.templates.originCustomized') : t('HrView.personnel.templates.originOwn')
}

function select(tpl) {
    current.value = { ...tpl, receipt: !!tpl.receipt }
}

function create() {
    current.value = {
        id: null, default_key: null, name: '', category: props.categories[0]?.key || '',
        subject: '', body: '', signature_mode: 'letter', receipt: false,
    }
}

watch(() => props.modelValue, open => {
    if (open) current.value = null
})

async function save() {
    saving.value = true
    try {
        const saved = await hr.savePersonnelTemplate(current.value)
        current.value.id = saved.id
        toasts.success(t('HrView.personnel.templates.saved'))
        emit('changed')
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        saving.value = false
    }
}

async function remove() {
    const restore = !!current.value.default_key
    const answer = await alerts.question(
        restore ? t('HrView.personnel.templates.restoreConfirm') : t('HrView.personnel.templates.deleteConfirm', { name: current.value.name }),
        restore ? t('HrView.personnel.templates.restore') : t('HrView.personnel.templates.delete'),
        restore ? t('HrView.personnel.templates.restore') : t('HrView.personnel.templates.delete'),
        t('HrView.cancel')
    )
    if (!answer?.isConfirmed) return
    try {
        await hr.deletePersonnelTemplate(current.value.id)
        current.value = null
        emit('changed')
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    }
}
</script>

<style scoped>
.hr-templates__title {
    display: flex;
    align-items: center;
}

.hr-templates__body {
    display: flex;
    flex-wrap: wrap;
    gap: var(--oserp-gap-lg);
}

.hr-templates__list {
    flex: 0 0 260px;
    display: flex;
    flex-direction: column;
    gap: var(--oserp-gap-sm);
}

.hr-templates__new {
    flex: 0 0 auto;
}

.hr-templates__editor {
    flex: 1 1 480px;
    min-width: 0;
}

.hr-templates__html {
    margin-top: var(--oserp-gap);
}

.hr-templates__placeholders {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-xs);
    margin: var(--oserp-gap-sm) 0 var(--oserp-gap);
}

.hr-templates__hint {
    font-size: 0.8125rem;
    color: var(--oserp-text-muted);
}

.hr-templates__actions {
    padding: var(--oserp-gap-sm) var(--oserp-gap-lg);
}
</style>
