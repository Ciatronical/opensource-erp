<!-- src/core/views/hr/tabs/personnel/hr.personnel.documents.vue -->
<!--
    Personalakte eines Mitarbeiters: Dokumente hochladen (Einkommensteuer-
    nachweise, Verträge, Krankmeldungen …), ansehen, umsortieren, löschen.
    Erzeugte Schreiben (Abmahnung, Kündigung …) landen ebenfalls hier.
    Ablagefächer kommen aus der Datenbank (hr_document_categories).
-->
<template>
    <section-card
        :title="t('HrView.personnel.documents.title')"
        icon="mdi-folder-account-outline"
        plain
        :class="{ 'hr-documents--drop': dragging }"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
    >
        <template #actions>
            <v-btn size="small" color="primary" prepend-icon="mdi-upload" @click="openUpload([])">
                {{ t('HrView.personnel.documents.upload') }}
            </v-btn>
            <v-btn size="small" variant="tonal" prepend-icon="mdi-file-document-edit-outline" @click="emit('letter')">
                {{ t('HrView.personnel.documents.createLetter') }}
            </v-btn>
        </template>

        <div class="hr-documents__filter">
            <v-chip-group v-model="filter" column>
                <v-chip
                    v-for="category in usedCategories"
                    :key="category.key"
                    :value="category.key"
                    size="small"
                    filter
                    variant="tonal"
                    :prepend-icon="category.icon"
                >
                    {{ categoryName(category.key) }} ({{ category.count }})
                </v-chip>
            </v-chip-group>
        </div>

        <div v-if="documents.length === 0" class="hr-documents__empty">
            <v-icon size="40">mdi-folder-open-outline</v-icon>
            <p>{{ t('HrView.personnel.documents.empty') }}</p>
            <p class="hr-documents__hint">{{ t('HrView.personnel.documents.dropHint') }}</p>
        </div>

        <v-table v-else density="comfortable">
            <thead>
                <tr>
                    <th>{{ t('HrView.personnel.documents.name') }}</th>
                    <th>{{ t('HrView.personnel.documents.category') }}</th>
                    <th>{{ t('HrView.personnel.documents.date') }}</th>
                    <th class="d-none d-md-table-cell">{{ t('HrView.personnel.documents.filedBy') }}</th>
                    <th class="text-right">{{ t('HrView.personnel.documents.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="doc in visibleDocuments" :key="doc.id">
                    <td>
                        <a class="hr-documents__link" :href="hr.personnelDocumentUrl(doc.id)" target="_blank" rel="noopener">
                            <v-icon size="small" class="me-1">{{ fileIcon(doc) }}</v-icon>{{ doc.title }}
                        </a>
                        <div v-if="doc.notes" class="hr-documents__notes">{{ doc.notes }}</div>
                    </td>
                    <td>{{ categoryName(doc.category) }}</td>
                    <td class="text-no-wrap">
                        {{ formatDate(doc.doc_date) }}
                        <v-chip v-if="doc.doc_year" size="x-small" variant="tonal" class="ms-1">{{ doc.doc_year }}</v-chip>
                    </td>
                    <td class="d-none d-md-table-cell hr-documents__meta">
                        {{ doc.created_by_name }}<br>{{ formatDate(doc.itime) }} · {{ formatSize(doc.filesize) }}
                    </td>
                    <td class="text-right text-no-wrap">
                        <v-btn icon="mdi-download" size="small" variant="text" :href="hr.personnelDocumentUrl(doc.id, true)" :title="t('HrView.personnel.documents.download')" />
                        <v-btn icon="mdi-pencil-outline" size="small" variant="text" :title="t('HrView.personnel.documents.edit')" @click="openEdit(doc)" />
                        <v-btn icon="mdi-delete-outline" size="small" variant="text" color="error" :title="t('HrView.personnel.documents.delete')" @click="remove(doc)" />
                    </td>
                </tr>
            </tbody>
        </v-table>
    </section-card>

    <!-- Hochladen und Bearbeiten teilen sich den Dialog: beim Hochladen mit Dateiauswahl -->
    <v-dialog v-model="dialog" max-width="560">
        <v-card>
            <v-card-title>
                {{ editing.id ? t('HrView.personnel.documents.editTitle') : t('HrView.personnel.documents.uploadTitle') }}
            </v-card-title>
            <v-card-text>
                <v-defaults-provider :defaults="fieldDefaults">
                    <v-row density="compact">
                        <v-col v-if="!editing.id" cols="12">
                            <v-file-input
                                v-model="files"
                                :label="t('HrView.personnel.documents.files')"
                                :hint="t('HrView.personnel.documents.fileHint')"
                                persistent-hint
                                multiple
                                show-size
                                accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.odt"
                                prepend-icon=""
                                prepend-inner-icon="mdi-paperclip"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-select
                                v-model="editing.category"
                                :label="t('HrView.personnel.documents.category')"
                                :items="categoryItems"
                            />
                        </v-col>
                        <v-col v-if="editing.id || files.length <= 1" cols="12">
                            <v-text-field
                                v-model="editing.title"
                                :label="t('HrView.personnel.documents.name')"
                                :hint="editing.id ? '' : t('HrView.personnel.documents.nameHint')"
                                :persistent-hint="!editing.id"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="editing.doc_date" type="date" :label="t('HrView.personnel.documents.date')" />
                        </v-col>
                        <v-col v-if="perYear" cols="12" sm="6">
                            <v-text-field v-model="editing.doc_year" type="number" :label="t('HrView.personnel.documents.year')" />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field v-model="editing.notes" :label="t('HrView.personnel.documents.notes')" />
                        </v-col>
                    </v-row>
                </v-defaults-provider>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="dialog = false">{{ t('HrView.cancel') }}</v-btn>
                <v-btn color="primary" variant="flat" :loading="busy" :disabled="!canSubmit" @click="submit">
                    {{ editing.id ? t('HrView.save') : t('HrView.personnel.documents.upload') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import SectionCard from '@/core/components/layout/section-card.vue'
import * as toasts from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    employee_id: { type: Number, required: true },
    documents: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
})
// changed: Dokumentliste hat sich geändert (Eltern laden die Akte neu)
const emit = defineEmits(['changed', 'letter'])

const { t, te, locale } = useI18n()
const hr = hrStore()

const fieldDefaults = {
    VTextField: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VSelect: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VFileInput: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
}

const filter = ref(null)
const dragging = ref(false)
const dialog = ref(false)
const busy = ref(false)
const files = ref([])
const editing = ref({})

// Name eines Ablagefachs: Übersetzung, sonst der Name aus der Datenbank
function categoryName(key) {
    const i18nKey = 'HrView.personnel.categories.' + key
    if (te(i18nKey)) return t(i18nKey)
    return props.categories.find(c => c.key === key)?.name || key
}

const categoryItems = computed(() => props.categories.map(c => ({ value: c.key, title: categoryName(c.key) })))

const usedCategories = computed(() => props.categories
    .map(c => ({ ...c, count: props.documents.filter(d => d.category === c.key).length }))
    .filter(c => c.count > 0))

const visibleDocuments = computed(() => filter.value
    ? props.documents.filter(d => d.category === filter.value)
    : props.documents)

const perYear = computed(() => !!props.categories.find(c => c.key === editing.value.category)?.per_year)

const canSubmit = computed(() => !!editing.value.category
    && (editing.value.id ? !!String(editing.value.title || '').trim() : files.value.length > 0))

function formatDate(value) {
    if (!value) return ''
    const date = new Date(String(value).length <= 10 ? value + 'T00:00:00' : String(value).replace(' ', 'T'))
    return isNaN(date) ? '' : date.toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatSize(bytes) {
    const size = Number(bytes) || 0
    return size >= 1048576
        ? (size / 1048576).toLocaleString(locale.value, { maximumFractionDigits: 1 }) + ' MB'
        : Math.max(1, Math.round(size / 1024)).toLocaleString(locale.value) + ' kB'
}

function fileIcon(doc) {
    const ext = String(doc.filename || '').split('.').pop().toLowerCase()
    if (ext === 'pdf') return 'mdi-file-pdf-box'
    if (['jpg', 'png', 'webp'].includes(ext)) return 'mdi-file-image-outline'
    return 'mdi-file-document-outline'
}

function openUpload(list) {
    files.value = list
    editing.value = {
        id: null,
        category: filter.value || props.categories[0]?.key || '',
        title: '', doc_date: '', doc_year: '', notes: '',
    }
    dialog.value = true
}

function openEdit(doc) {
    files.value = []
    editing.value = {
        id: doc.id, category: doc.category, title: doc.title,
        doc_date: doc.doc_date || '', doc_year: doc.doc_year || '', notes: doc.notes || '',
    }
    dialog.value = true
}

function onDrop(event) {
    dragging.value = false
    const list = Array.from(event.dataTransfer?.files || [])
    if (list.length) openUpload(list)
}

async function submit() {
    busy.value = true
    const fields = { ...editing.value, doc_year: perYear.value ? editing.value.doc_year : '' }
    try {
        if (fields.id) {
            await hr.updatePersonnelDocument(fields)
        } else {
            // Jede Datei einzeln: ein Fehler bei einer Datei verwirft nicht die übrigen
            const failed = []
            for (const file of files.value) {
                try {
                    await hr.uploadPersonnelDocument({
                        employee_id: props.employee_id, category: fields.category,
                        title: files.value.length === 1 ? fields.title : '',
                        doc_date: fields.doc_date, doc_year: fields.doc_year, notes: fields.notes,
                    }, file)
                } catch (e) {
                    failed.push(file.name + (e.message ? ': ' + e.message : ''))
                }
            }
            if (failed.length) toasts.error(t('HrView.personnel.documents.uploadFailed', { files: failed.join('; ') }))
            if (failed.length < files.value.length) {
                toasts.success(t('HrView.personnel.documents.uploaded', { count: files.value.length - failed.length }))
            }
        }
        dialog.value = false
        emit('changed')
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    } finally {
        busy.value = false
    }
}

async function remove(doc) {
    const answer = await alerts.question(
        t('HrView.personnel.documents.deleteConfirm', { title: doc.title }),
        t('HrView.personnel.documents.delete'),
        t('HrView.personnel.documents.delete'),
        t('HrView.cancel')
    )
    if (!answer?.isConfirmed) return
    try {
        await hr.deletePersonnelDocument(doc.id)
        emit('changed')
    } catch (e) {
        toasts.error(e.message || t('HrView.personnel.error'))
    }
}
</script>

<style scoped>
.hr-documents--drop {
    outline: 2px dashed var(--oserp-primary);
    outline-offset: 2px;
}

.hr-documents__filter {
    padding: var(--oserp-gap-sm) var(--oserp-gap) 0;
}

.hr-documents__empty {
    padding: var(--oserp-gap-xl);
    text-align: center;
    color: var(--oserp-text-muted);
}

.hr-documents__hint,
.hr-documents__notes,
.hr-documents__meta {
    font-size: 0.8125rem;
    color: var(--oserp-text-muted);
}

.hr-documents__link {
    color: inherit;
    font-weight: 500;
    text-decoration: none;
}

.hr-documents__link:hover {
    color: var(--oserp-primary);
    text-decoration: underline;
}
</style>
