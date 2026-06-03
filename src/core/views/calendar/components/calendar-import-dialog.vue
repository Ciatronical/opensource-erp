<!-- src/core/views/calendar/components/calendar-import-dialog.vue -->
<template>
    <v-dialog v-model="dialogOpen" max-width="680" scrollable>
        <v-card rounded="lg">
            <v-card-title class="d-flex align-center pa-4 pb-3">
                <v-icon class="me-2" color="primary">mdi-calendar-import</v-icon>
                {{ t('CalendarImport.title') }}
                <v-spacer />
                <v-btn icon size="small" variant="text" @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <v-card-text class="pa-4" style="overflow-y: auto; max-height: calc(100vh - 200px);">

                <!-- Drop Zone -->
                <div
                    class="drop-zone rounded-lg d-flex flex-column align-center justify-center pa-6"
                    :class="{
                        'drop-zone--dragging': isDragging,
                        'drop-zone--loaded':   !!fileName
                    }"
                    @dragover.prevent="isDragging = true"
                    @dragleave.prevent="isDragging = false"
                    @drop.prevent="onDrop"
                    @click="fileInput?.click()"
                >
                    <input
                        ref="fileInput"
                        type="file"
                        accept=".ics,.csv,.txt"
                        class="d-none"
                        @change="onFileSelected"
                    />

                    <v-icon
                        :size="fileName ? 40 : 56"
                        class="mb-3 drop-icon"
                        :color="fileName ? 'success' : (isDragging ? 'primary' : 'grey-lighten-1')"
                    >
                        {{ fileName ? 'mdi-check-circle-outline' : 'mdi-tray-arrow-up' }}
                    </v-icon>

                    <template v-if="!fileName">
                        <div class="text-subtitle-1 font-weight-medium mb-1">
                            {{ t('CalendarImport.drop.title') }}
                        </div>
                        <div class="text-caption text-medium-emphasis mb-3">
                            {{ t('CalendarImport.drop.subtitle') }}
                        </div>
                        <div class="d-flex gap-2 flex-wrap justify-center">
                            <v-chip size="x-small" color="blue-darken-1" label>.ics (iCal)</v-chip>
                            <v-chip size="x-small" color="green-darken-1" label>.csv</v-chip>
                            <v-chip size="x-small" color="orange-darken-1" label>.txt</v-chip>
                        </div>
                    </template>

                    <template v-else>
                        <div class="text-subtitle-1 font-weight-medium mb-1">{{ fileName }}</div>
                        <div class="d-flex align-center gap-2 mt-1">
                            <v-chip size="small" :color="formatChipColor" label>
                                {{ detectedFormat.toUpperCase() }}
                            </v-chip>
                            <span class="text-caption text-medium-emphasis">
                                {{ t('CalendarImport.drop.change') }}
                            </span>
                        </div>
                    </template>
                </div>

                <!-- Parse-Fehler -->
                <v-alert
                    v-if="parseError"
                    type="error"
                    variant="tonal"
                    density="compact"
                    closable
                    class="mt-3"
                    @click:close="parseError = ''"
                >
                    {{ parseError }}
                </v-alert>

                <!-- Lade-Indikator -->
                <v-progress-linear
                    v-if="parsing"
                    indeterminate
                    color="primary"
                    rounded
                    class="mt-3"
                />

                <!-- Optionen + Vorschau -->
                <v-expand-transition>
                    <div v-if="parsedEvents.length > 0">
                        <v-divider class="my-4" />

                        <!-- Optionen -->
                        <v-row dense align="start">

                            <!-- Datumsversatz -->
                            <v-col cols="12" sm="6">
                                <div class="text-caption font-weight-medium text-medium-emphasis mb-2 text-uppercase tracking-wide">
                                    {{ t('CalendarImport.options.offsetTitle') }}
                                </div>
                                <div class="d-flex align-center gap-2">
                                    <v-btn
                                        icon="mdi-minus"
                                        size="small"
                                        variant="tonal"
                                        rounded
                                        @click="offsetDays = Math.max(-31, offsetDays - 1)"
                                    />
                                    <v-text-field
                                        v-model.number="offsetDays"
                                        type="number"
                                        density="compact"
                                        variant="outlined"
                                        hide-details
                                        min="-31"
                                        max="31"
                                        style="width: 72px"
                                        class="offset-input"
                                    />
                                    <v-btn
                                        icon="mdi-plus"
                                        size="small"
                                        variant="tonal"
                                        rounded
                                        @click="offsetDays = Math.min(31, offsetDays + 1)"
                                    />
                                    <span class="text-body-2 text-medium-emphasis">
                                        {{ t('CalendarImport.options.days') }}
                                    </span>
                                </div>
                                <div class="text-caption mt-2" style="min-height: 20px;">
                                    <span v-if="offsetDays < 0" class="text-deep-orange-darken-2">
                                        <v-icon size="x-small">mdi-arrow-left-thin</v-icon>
                                        {{ t('CalendarImport.options.shiftedBefore', { n: Math.abs(offsetDays) }) }}
                                    </span>
                                    <span v-else-if="offsetDays > 0" class="text-blue-darken-2">
                                        <v-icon size="x-small">mdi-arrow-right-thin</v-icon>
                                        {{ t('CalendarImport.options.shiftedAfter', { n: offsetDays }) }}
                                    </span>
                                    <span v-else class="text-medium-emphasis">
                                        {{ t('CalendarImport.options.noShift') }}
                                    </span>
                                </div>
                            </v-col>

                            <!-- Kategorie -->
                            <v-col cols="12" sm="6">
                                <div class="text-caption font-weight-medium text-medium-emphasis mb-2 text-uppercase tracking-wide">
                                    {{ t('CalendarImport.options.categoryTitle') }}
                                </div>
                                <v-select
                                    v-model="selectedCategoryId"
                                    :items="categoryItems"
                                    item-title="label"
                                    item-value="id"
                                    density="compact"
                                    variant="outlined"
                                    hide-details
                                    clearable
                                    :placeholder="t('CalendarImport.options.noCategory')"
                                >
                                    <template #item="{ item, props: p }">
                                        <v-list-item v-bind="p">
                                            <template #prepend>
                                                <v-icon
                                                    size="small"
                                                    :color="item.raw.color || 'grey-lighten-2'"
                                                    class="me-1"
                                                >
                                                    mdi-circle
                                                </v-icon>
                                            </template>
                                        </v-list-item>
                                    </template>
                                    <template #selection="{ item }">
                                        <v-icon
                                            v-if="item.raw.color"
                                            size="small"
                                            :color="item.raw.color"
                                            class="me-1"
                                        >
                                            mdi-circle
                                        </v-icon>
                                        {{ item.title }}
                                    </template>
                                </v-select>
                            </v-col>
                        </v-row>

                        <!-- Hinweis (nur wenn kein Versatz gesetzt) -->
                        <v-alert
                            v-if="offsetDays === 0"
                            type="info"
                            variant="tonal"
                            density="compact"
                            icon="mdi-lightbulb-on-outline"
                            class="mt-3"
                        >
                            {{ t('CalendarImport.options.hint') }}
                        </v-alert>

                        <!-- Vorschau-Tabelle -->
                        <v-divider class="my-4" />

                        <div class="d-flex align-center mb-2">
                            <span class="text-subtitle-2 font-weight-medium">
                                {{ t('CalendarImport.preview.title') }}
                            </span>
                            <v-chip class="ms-2" size="x-small" color="primary" label>
                                {{ shiftedEvents.length }}
                            </v-chip>
                        </div>

                        <v-table density="compact" fixed-header height="240" class="preview-table rounded-lg">
                            <thead>
                                <tr>
                                    <th class="text-left" style="width: 120px;">
                                        {{ t('CalendarImport.preview.date') }}
                                    </th>
                                    <th class="text-left">{{ t('CalendarImport.preview.eventTitle') }}</th>
                                    <th class="text-center" style="width: 60px;">
                                        {{ t('CalendarImport.preview.allDay') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(ev, i) in shiftedEvents" :key="i">
                                    <td class="text-no-wrap">
                                        <span class="text-caption font-weight-medium text-mono">
                                            {{ formatPreviewDate(ev.dtstart) }}
                                        </span>
                                    </td>
                                    <td class="text-body-2">{{ ev.title }}</td>
                                    <td class="text-center">
                                        <v-icon
                                            size="small"
                                            :color="ev.allDay ? 'success' : 'grey-lighten-1'"
                                        >
                                            {{ ev.allDay ? 'mdi-check' : 'mdi-clock-outline' }}
                                        </v-icon>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </div>
                </v-expand-transition>

            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" :disabled="importing" @click="close">
                    {{ t('CalendarImport.cancel') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    :disabled="shiftedEvents.length === 0"
                    :loading="importing"
                    prepend-icon="mdi-calendar-import"
                    @click="doImport"
                >
                    {{ t('CalendarImport.importBtn', { n: shiftedEvents.length }) }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

const props = defineProps({
    modelValue: Boolean,
    categories: { type: Array, default: () => [] }
})

const emit = defineEmits(['update:modelValue', 'imported'])
const { t } = useI18n()

const dialogOpen = computed({
    get: () => props.modelValue,
    set: v => emit('update:modelValue', v)
})

const fileInput   = ref(null)
const fileName    = ref('')
const fileContent = ref('')
const detectedFormat = ref('auto')
const parsedEvents   = ref([])
const parseError     = ref('')
const isDragging     = ref(false)
const parsing        = ref(false)
const importing      = ref(false)

const offsetDays         = ref(0)
const selectedCategoryId = ref(null)

const formatChipColor = computed(() => {
    if (detectedFormat.value === 'ical') return 'blue-darken-1'
    if (detectedFormat.value === 'csv')  return 'green-darken-1'
    return 'orange-darken-1'
})

const categoryItems = computed(() => props.categories)

const shiftedEvents = computed(() => {
    if (offsetDays.value === 0) return parsedEvents.value
    return parsedEvents.value.map(ev => ({
        ...ev,
        dtstart: shiftDateClient(ev.dtstart, offsetDays.value),
        dtend:   ev.dtend ? shiftDateClient(ev.dtend, offsetDays.value) : ev.dtend
    }))
})

function shiftDateClient(dateStr, days) {
    const d = new Date(dateStr.replace(' ', 'T'))
    d.setDate(d.getDate() + days)
    const yyyy = d.getFullYear()
    const mm   = String(d.getMonth() + 1).padStart(2, '0')
    const dd   = String(d.getDate()).padStart(2, '0')
    return `${yyyy}-${mm}-${dd} 00:00:00`
}

function formatPreviewDate(dateStr) {
    const d = new Date(dateStr.replace(' ', 'T'))
    if (isNaN(d.getTime())) return dateStr
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function onFileSelected(event) {
    const file = event.target.files?.[0]
    if (file) processFile(file)
    event.target.value = ''
}

function onDrop(event) {
    isDragging.value = false
    const file = event.dataTransfer?.files?.[0]
    if (file) processFile(file)
}

function processFile(file) {
    fileName.value = file.name
    const ext = file.name.split('.').pop().toLowerCase()
    detectedFormat.value = ext === 'ics' ? 'ical' : (ext === 'csv' ? 'csv' : 'txt')

    const reader = new FileReader()
    reader.onload = e => {
        fileContent.value = e.target.result
        parseFile()
    }
    reader.readAsText(file, 'UTF-8')
}

async function parseFile() {
    parseError.value  = ''
    parsedEvents.value = []
    parsing.value = true
    try {
        const res = await axios.post('/api/calendar/', {
            action:      'importCalendarEvents',
            content:     fileContent.value,
            format:      detectedFormat.value,
            offset_days: 0,
            preview:     true
        })
        if (res.data.success) {
            parsedEvents.value = res.data.payload?.events || []
            if (parsedEvents.value.length === 0) {
                parseError.value = t('CalendarImport.errors.noEvents')
            }
        } else {
            parseError.value = res.data.text || t('CalendarImport.errors.parseFailed')
        }
    } catch {
        parseError.value = t('CalendarImport.errors.parseFailed')
    } finally {
        parsing.value = false
    }
}

async function doImport() {
    importing.value = true
    parseError.value = ''
    try {
        const res = await axios.post('/api/calendar/', {
            action:      'importCalendarEvents',
            content:     fileContent.value,
            format:      detectedFormat.value,
            offset_days: offsetDays.value,
            category_id: selectedCategoryId.value,
            visibility:  -1,
            preview:     false
        })
        if (res.data.success) {
            emit('imported', res.data.payload?.inserted ?? 0)
            close()
        } else {
            parseError.value = res.data.text || t('CalendarImport.errors.importFailed')
        }
    } catch {
        parseError.value = t('CalendarImport.errors.importFailed')
    } finally {
        importing.value = false
    }
}

function close() {
    emit('update:modelValue', false)
}

watch(dialogOpen, open => {
    if (!open) {
        fileName.value       = ''
        fileContent.value    = ''
        detectedFormat.value = 'auto'
        parsedEvents.value   = []
        parseError.value     = ''
        offsetDays.value     = 0
        selectedCategoryId.value = null
    }
})
</script>

<style scoped>
.drop-zone {
    border: 2px dashed rgba(0, 0, 0, 0.15);
    cursor: pointer;
    transition: border-color 0.2s ease, background 0.2s ease;
    min-height: 130px;
    background: rgba(0, 0, 0, 0.015);
}

.drop-zone:hover,
.drop-zone--dragging {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.04);
}

.drop-zone--loaded {
    border-style: solid;
    border-color: rgb(var(--v-theme-success));
    background: rgba(var(--v-theme-success), 0.04);
}

.drop-zone--loaded:hover {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.04);
}

.drop-icon {
    transition: color 0.2s ease;
}

.offset-input :deep(input) {
    text-align: center;
}

.preview-table {
    border: 1px solid rgba(0, 0, 0, 0.08);
}

.text-mono {
    font-family: 'Roboto Mono', monospace;
}
</style>
