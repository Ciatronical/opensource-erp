<!-- src/core/views/calendar/components/calendar-event-dialog.vue -->
<template>
    <v-dialog :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)" max-width="600" persistent scrollable>
        <v-card>

            <!-- Gradient Header -->
            <div class="ced-header pa-5" :style="{ background: headerGradient }">
                <div class="d-flex align-center" style="padding-right: 48px;">
                    <v-avatar size="46" color="white" class="mr-3 flex-shrink-0">
                        <v-icon :color="form.color || '#1976D2'" size="22">mdi-calendar-edit</v-icon>
                    </v-avatar>
                    <div style="flex: 1; min-width: 0;">
                        <div class="text-white text-h6 font-weight-medium" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            {{ form.title || (isEdit ? t('CalendarEventDialog.titleEdit') : t('CalendarEventDialog.titleCreate')) }}
                        </div>
                        <div class="text-white text-caption" style="opacity: 0.8;">
                            {{ isEdit ? t('CalendarEventDialog.titleEdit') : t('CalendarEventDialog.titleCreate') }}
                        </div>
                    </div>
                </div>
                <v-btn icon variant="text" size="small" class="ced-close-btn" @click="closeAndSave">
                    <v-icon color="white">mdi-close</v-icon>
                </v-btn>
            </div>

            <!-- Scrollable Form -->
            <v-card-text class="pa-4" style="max-height: 65vh; overflow-y: auto;">
                <v-form ref="formRef" v-model="formValid">

                    <!-- Titel -->
                    <v-text-field
                        v-model="form.title"
                        :label="t('CalendarEventDialog.title')"
                        :rules="[v => !!v || t('CalendarEventDialog.validation.titleRequired')]"
                        variant="outlined"
                        density="compact"
                        prepend-inner-icon="mdi-format-title"
                        class="mb-4"
                        autofocus
                    />

                    <!-- Datum & Zeit -->
                    <div class="ced-section rounded-lg mb-4">
                        <div class="ced-section-label mb-2">
                            <v-icon size="15" class="mr-1">mdi-clock-outline</v-icon>
                            {{ t('CalendarEventDialog.start') }} / {{ t('CalendarEventDialog.end') }}
                        </div>
                        <v-switch
                            v-model="form.allDay"
                            :label="t('CalendarEventDialog.allDay')"
                            color="primary"
                            density="compact"
                            hide-details
                            class="mb-3"
                        />
                        <v-row dense>
                            <v-col cols="6">
                                <v-text-field
                                    v-model="form.dtstart"
                                    :label="t('CalendarEventDialog.start')"
                                    :type="form.allDay ? 'date' : 'datetime-local'"
                                    :rules="[v => !!v || t('CalendarEventDialog.validation.startRequired')]"
                                    variant="outlined"
                                    density="compact"
                                    prepend-inner-icon="mdi-calendar-arrow-right"
                                    hide-details="auto"
                                />
                            </v-col>
                            <v-col cols="6">
                                <v-text-field
                                    v-model="form.dtend"
                                    :label="t('CalendarEventDialog.end')"
                                    :type="form.allDay ? 'date' : 'datetime-local'"
                                    :min="form.dtstart"
                                    variant="outlined"
                                    density="compact"
                                    prepend-inner-icon="mdi-calendar-arrow-left"
                                    hide-details
                                />
                            </v-col>
                        </v-row>
                    </div>

                    <!-- Kategorie / Priorität / Sichtbarkeit -->
                    <v-row dense class="mb-2">
                        <v-col cols="12" sm="6">
                            <v-select
                                v-model="form.category_id"
                                :items="categoryItems"
                                :label="t('CalendarEventDialog.category')"
                                variant="outlined"
                                density="compact"
                                prepend-inner-icon="mdi-tag-outline"
                                clearable
                                hide-details
                            >
                                <template #selection="{ item }">
                                    <v-chip
                                        size="x-small"
                                        :color="categories.find(c => c.id === item.value)?.color"
                                        variant="tonal"
                                        class="mr-1"
                                    >
                                        {{ item.title }}
                                    </v-chip>
                                </template>
                            </v-select>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-select
                                v-model="form.prio"
                                :items="priorityItems"
                                :label="t('CalendarEventDialog.priority')"
                                variant="outlined"
                                density="compact"
                                prepend-inner-icon="mdi-flag-outline"
                                hide-details
                            >
                                <template #selection="{ item }">
                                    <v-icon size="16" :color="priorityColor(item.value)" class="mr-1">mdi-flag</v-icon>
                                    <span class="text-caption">{{ item.title }}</span>
                                </template>
                            </v-select>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-select
                                v-model="form.visibility"
                                :items="visibilityItems"
                                :label="t('CalendarEventDialog.visibility')"
                                variant="outlined"
                                density="compact"
                                prepend-inner-icon="mdi-eye-outline"
                                hide-details
                            />
                        </v-col>
                    </v-row>

                    <!-- Ort -->
                    <v-text-field
                        v-model="form.location"
                        :label="t('CalendarEventDialog.location')"
                        variant="outlined"
                        density="compact"
                        prepend-inner-icon="mdi-map-marker-outline"
                        class="mb-3"
                        hide-details
                    />

                    <!-- Beschreibung -->
                    <v-textarea
                        v-model="form.description"
                        :label="t('CalendarEventDialog.description')"
                        variant="outlined"
                        density="compact"
                        rows="2"
                        auto-grow
                        prepend-inner-icon="mdi-text"
                        class="mb-4"
                        hide-details
                    />

                    <!-- Farbe -->
                    <div class="ced-section rounded-lg mb-4">
                        <div class="ced-section-label mb-3">
                            <v-icon size="15" class="mr-1">mdi-palette-outline</v-icon>
                            {{ t('CalendarEventDialog.color') }}
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <div
                                v-for="c in colorOptions"
                                :key="c"
                                class="ced-color-dot"
                                :class="{ 'ced-color-dot--active': form.color === c }"
                                :style="{ backgroundColor: c }"
                                @click="form.color = form.color === c ? null : c"
                            >
                                <v-icon v-if="form.color === c" size="13" color="white">mdi-check</v-icon>
                            </div>
                            <!-- Kein Farbfilter -->
                            <div
                                class="ced-color-dot ced-color-dot--none"
                                :class="{ 'ced-color-dot--active': !form.color }"
                                @click="form.color = null"
                            >
                                <v-icon v-if="!form.color" size="13" color="grey-darken-1">mdi-check</v-icon>
                                <v-icon v-else size="13" color="grey">mdi-cancel</v-icon>
                            </div>
                        </div>
                    </div>

                    <!-- Wiederholen -->
                    <div class="ced-section rounded-lg mb-4">
                        <v-switch
                            v-model="form.repeat"
                            :label="t('CalendarEventDialog.repeat')"
                            color="primary"
                            density="compact"
                            hide-details
                            :class="form.repeat ? 'mb-4' : ''"
                        />
                        <template v-if="form.repeat">
                            <div class="d-flex align-center gap-2 mb-3">
                                <span class="text-body-2 text-medium-emphasis text-no-wrap flex-shrink-0">
                                    {{ t('CalendarEventDialog.repeatEvery') }}
                                </span>
                                <v-text-field
                                    v-model.number="form.recur_interval"
                                    type="number"
                                    min="1"
                                    max="999"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    style="max-width: 80px;"
                                />
                                <v-select
                                    v-model="form.freq"
                                    :items="freqItems"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                />
                            </div>
                            <v-btn-toggle
                                v-model="form.repeatEndType"
                                mandatory
                                density="compact"
                                rounded="lg"
                                color="primary"
                                class="mb-3"
                            >
                                <v-btn value="count" size="small">{{ t('CalendarEventDialog.repeatEndAfter') }}</v-btn>
                                <v-btn value="date" size="small">{{ t('CalendarEventDialog.repeatEndOnDate') }}</v-btn>
                            </v-btn-toggle>
                            <div v-if="form.repeatEndType === 'count'" class="d-flex align-center gap-2">
                                <v-text-field
                                    v-model.number="form.recur_count"
                                    type="number"
                                    min="1"
                                    max="9999"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    style="max-width: 100px;"
                                />
                                <span class="text-body-2 text-medium-emphasis">{{ t('CalendarEventDialog.repeatEndOccurrences') }}</span>
                            </div>
                            <v-text-field
                                v-else
                                v-model="form.recur_repeat_end"
                                type="date"
                                :label="t('CalendarEventDialog.repeatEndOnDate')"
                                variant="outlined"
                                density="compact"
                                prepend-inner-icon="mdi-calendar-end"
                                hide-details
                            />
                        </template>
                    </div>

                    <!-- Kunde / Lieferant -->
                    <v-autocomplete
                        v-model="selectedCvp"
                        v-model:search="cvpSearch"
                        :items="cvpResults"
                        :loading="cvpLoading"
                        :label="t('CalendarEventDialog.customerVendor')"
                        item-title="name"
                        item-value="id"
                        return-object
                        no-filter
                        variant="outlined"
                        density="compact"
                        prepend-inner-icon="mdi-account-tie-outline"
                        clearable
                        hide-no-data
                        hide-details
                        @update:search="onCvpSearch"
                    >
                        <template #item="{ props: itemProps, item }">
                            <v-list-item v-bind="itemProps">
                                <template #append>
                                    <v-icon size="small" :color="item.raw.typ === 'C' ? 'primary' : 'warning'">
                                        {{ item.raw.typ === 'C' ? 'mdi-account' : 'mdi-domain' }}
                                    </v-icon>
                                </template>
                            </v-list-item>
                        </template>
                        <template #append-inner>
                            <v-btn
                                v-if="currentCustomer && !form.cvp_id"
                                size="x-small"
                                variant="tonal"
                                color="primary"
                                @click.stop="applyCurrent"
                            >
                                {{ currentCustomer.name }}
                            </v-btn>
                        </template>
                    </v-autocomplete>

                </v-form>
            </v-card-text>

        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    event: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    currentCustomer: { type: Object, default: null }
})

const emit = defineEmits(['update:modelValue', 'save'])

const { t } = useI18n()
const oserp = oserpStore()
const formRef = ref(null)
const formValid = ref(false)

function defaultStartTime() {
    const raw = (oserp.getClientDefaultValue('calendar_day_start') || '07:00').trim()
    return raw.substring(0, 5)
}

const isEdit = computed(() => !!props.event?.id)

const headerGradient = computed(() => {
    const c = form.value.color || '#1976D2'
    return `linear-gradient(135deg, ${c} 0%, ${c}bb 100%)`
})

const defaultForm = () => ({
    id: null,
    title: '',
    description: '',
    dtstart: '',
    dtend: '',
    allDay: false,
    location: '',
    color: null,
    prio: 1,
    category_id: null,
    visibility: -1,
    cvp_id: null,
    cvp_name: '',
    cvp_type: null,
    repeat: false,
    freq: 'yearly',
    recur_interval: 1,
    recur_count: 1,
    recur_repeat_end: '',
    repeatEndType: 'count',
})

const form = ref(defaultForm())

const selectedCvp = ref(null)
const cvpSearch = ref('')
const cvpResults = ref([])
const cvpLoading = ref(false)
let cvpDebounce = null
let cvpAbort = null

const colorOptions = [
    '#1976D2', '#4CAF50', '#FF9800', '#E53935', '#9C27B0',
    '#00BCD4', '#FF7043', '#8BC34A', '#F44336', '#3F51B5',
    '#009688', '#FFC107', '#795548'
]

const categoryItems = computed(() =>
    props.categories.map(c => ({ title: c.label, value: c.id }))
)

const priorityItems = computed(() => [
    { title: t('CalendarEventDialog.priorityLow'),    value: 0 },
    { title: t('CalendarEventDialog.priorityNormal'), value: 1 },
    { title: t('CalendarEventDialog.priorityHigh'),   value: 2 }
])

const visibilityItems = computed(() => [
    { title: t('CalendarEventDialog.visibilityAll'),     value: -1 },
    { title: t('CalendarEventDialog.visibilityPrivate'), value: 0 }
])

const freqItems = computed(() => [
    { title: t('CalendarEventDialog.repeatDays'),   value: 'daily' },
    { title: t('CalendarEventDialog.repeatWeeks'),  value: 'weekly' },
    { title: t('CalendarEventDialog.repeatMonths'), value: 'monthly' },
    { title: t('CalendarEventDialog.repeatYears'),  value: 'yearly' },
])

function priorityColor(val) {
    return val === 2 ? 'error' : val === 1 ? 'warning' : 'success'
}

watch(selectedCvp, (val) => {
    if (val) {
        form.value.cvp_id   = val.id
        form.value.cvp_name = val.name
        form.value.cvp_type = val.typ
    } else {
        form.value.cvp_id   = null
        form.value.cvp_name = ''
        form.value.cvp_type = null
    }
})

watch(() => form.value.dtstart, (newStart) => {
    if (!newStart) return
    if (!form.value.dtend || form.value.dtend < newStart) {
        form.value.dtend = newStart
    }
})

watch(() => form.value.allDay, (isAllDay) => {
    for (const field of ['dtstart', 'dtend']) {
        const val = form.value[field]
        if (!val) continue
        if (isAllDay) {
            form.value[field] = val.split('T')[0].split(' ')[0]
        } else {
            if (!val.includes('T')) {
                let time = defaultStartTime()
                if (field === 'dtend') {
                    const [h, m] = time.split(':').map(Number)
                    time = String(h + 1).padStart(2, '0') + ':' + String(m).padStart(2, '0')
                }
                form.value[field] = val + 'T' + time
            }
        }
    }
})

watch(() => props.modelValue, (open) => {
    if (open) {
        if (props.event) {
            const e = props.event
            form.value = {
                id:          e.id || null,
                title:       e.title || '',
                description: e.description || '',
                dtstart:     formatForInput(e.edit_dtstart ?? e.dtstart, e.allDay),
                dtend:       formatForInput(e.edit_dtend   ?? e.dtend,   e.allDay),
                allDay:      e.allDay || false,
                location:    e.location || '',
                color:       e.color || null,
                prio:        e.prio ?? 1,
                category_id: e.category_id || null,
                visibility:  e.visibility ?? -1,
                cvp_id:      e.cvp_id || null,
                cvp_name:    e.cvp_name || '',
                cvp_type:    e.cvp_type || null,
                repeat:          !!e.freq,
                freq:            e.freq || 'yearly',
                recur_interval:  e.recur_interval || 1,
                repeatEndType:   e.count ? 'count' : (e.repeat_end ? 'date' : 'count'),
                recur_count:     e.count ? Math.max(1, e.count - 1) : 1,  // count - 1: Gesamtanzahl → Wiederholungen
                recur_repeat_end: e.repeat_end
                    ? (e.repeat_end.split('T')[0].split(' ')[0])
                    : '',
            }
            if (e.cvp_id && e.cvp_name) {
                const item = { id: e.cvp_id, name: e.cvp_name, typ: e.cvp_type }
                selectedCvp.value = item
                cvpResults.value  = [item]
            } else {
                selectedCvp.value = null
                cvpResults.value  = []
            }
        } else {
            form.value    = defaultForm()
            selectedCvp.value = null
            cvpResults.value  = []
        }
        cvpSearch.value = ''
    }
})

function onCvpSearch(val) {
    clearTimeout(cvpDebounce)
    if (!val || val.length < 2) return
    cvpDebounce = setTimeout(() => searchCvp(val), 300)
}

async function searchCvp(query) {
    if (cvpAbort) cvpAbort.abort()
    cvpAbort = new AbortController()
    cvpLoading.value = true
    try {
        const [custRes, vendRes] = await Promise.all([
            axios.post('/api/customer_vendor/', { action: 'searchCV', type: 'customer', where: { name: query } }, { signal: cvpAbort.signal }),
            axios.post('/api/customer_vendor/', { action: 'searchCV', type: 'vendor',   where: { name: query } }, { signal: cvpAbort.signal })
        ])
        const customers = (custRes.data?.payload?.search?.results ?? []).map(c => ({ id: c.id, name: c.name, typ: 'C' }))
        const vendors   = (vendRes.data?.payload?.search?.results ?? []).map(v => ({ id: v.id, name: v.name, typ: 'V' }))
        cvpResults.value = [...customers, ...vendors].slice(0, 15)
    } catch {
        // aborted or network error
    } finally {
        cvpLoading.value = false
    }
}

function formatForInput(dateStr, allDay) {
    if (!dateStr) return ''
    if (allDay) return dateStr.split('T')[0].split(' ')[0]
    const d = new Date(dateStr)
    if (isNaN(d.getTime())) return dateStr
    const pad = n => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function formatForSave(dateStr, allDay) {
    if (!dateStr) return null
    if (allDay) return dateStr.split('T')[0].split(' ')[0]
    if (!dateStr.includes('T') && !dateStr.includes(' ')) return dateStr + ' 00:00:00'
    return dateStr.replace('T', ' ') + ':00'
}

function applyCurrent() {
    if (props.currentCustomer) {
        const item = { id: props.currentCustomer.id, name: props.currentCustomer.name, typ: props.currentCustomer.type || 'C' }
        selectedCvp.value = item
        cvpResults.value  = [item]
    }
}

function calcCountFromDate(dtstart, freq, interval, repeatEndDate) {
    if (!dtstart || !repeatEndDate) return 1
    const start = new Date((dtstart.split('T')[0].split(' ')[0]))
    const end   = new Date(repeatEndDate)
    let cur = new Date(start)
    let count = 0
    const max = 10000
    while (cur <= end && count < max) {
        count++
        switch (freq) {
            case 'daily':   cur.setDate(cur.getDate() + interval); break
            case 'weekly':  cur.setDate(cur.getDate() + interval * 7); break
            case 'monthly': cur.setMonth(cur.getMonth() + interval); break
            case 'yearly':  cur.setFullYear(cur.getFullYear() + interval); break
            default: return count
        }
    }
    return Math.max(1, count)
}

function close() {
    emit('update:modelValue', false)
}

async function closeAndSave() {
    if (!form.value.title || !form.value.dtstart) {
        close()
        return
    }
    if (formRef.value) {
        const { valid } = await formRef.value.validate()
        if (!valid) { close(); return }
    }
    save()
}

function save() {
    if (!formValid.value) return
    const f = form.value
    emit('save', {
        id:          f.id,
        title:       f.title,
        description: f.description,
        dtstart:     formatForSave(f.dtstart, f.allDay),
        dtend:       formatForSave(f.dtend, f.allDay),
        allDay:      f.allDay,
        location:    f.location,
        color:       f.color,
        prio:        f.prio,
        category_id: f.category_id,
        visibility:  f.visibility,
        cvp_id:      f.cvp_id,
        cvp_name:    f.cvp_name,
        cvp_type:    f.cvp_type,
        freq:        f.repeat ? f.freq : null,
        interval:    f.repeat ? f.recur_interval : null,
        // Bis-Datum: count aus Datumsbereich berechnen, beide Felder befüllen
        count:       f.repeat
            ? (f.repeatEndType === 'count'
                ? (parseInt(f.recur_count, 10) || 0) + 1          // +1: Wiederholungen → Gesamtanzahl
                : calcCountFromDate(f.dtstart, f.freq, f.recur_interval, f.recur_repeat_end))
            : null,
        repeat_end:  f.repeat && f.repeatEndType === 'date' ? f.recur_repeat_end : null,
    })
}
</script>

<style scoped>
.ced-header {
    position: relative;
}

.ced-close-btn {
    position: absolute;
    top: 14px;
    right: 14px;
}

.ced-section {
    background: #f8f9fa;
    padding: 12px 14px;
}

.ced-section-label {
    display: flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #757575;
}

.ced-color-dot {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    flex-shrink: 0;
}

.ced-color-dot:hover {
    transform: scale(1.18);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}

.ced-color-dot--active {
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px rgba(0, 0, 0, 0.3);
    transform: scale(1.1);
}

.ced-color-dot--none {
    background: #f0f0f0 !important;
    border: 1px solid #ddd;
}
</style>
