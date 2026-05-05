<!-- src/core/views/calendar/components/calendar-event-dialog.vue -->
<template>
    <v-dialog :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)" max-width="600" persistent>
        <v-card rounded="xl">
            <v-card-title class="d-flex align-center pa-4">
                <v-icon class="me-2" color="primary">mdi-calendar-edit</v-icon>
                {{ isEdit ? t('CalendarEventDialog.titleEdit') : t('CalendarEventDialog.titleCreate') }}
                <v-spacer />
                <v-btn icon variant="text" size="small" @click="closeAndSave">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <v-card-text class="pa-4">
                <v-form ref="formRef" v-model="formValid">
                    <!-- Title -->
                    <v-text-field
                        v-model="form.title"
                        :label="t('CalendarEventDialog.title')"
                        :rules="[v => !!v || t('CalendarEventDialog.validation.titleRequired')]"
                        variant="outlined"
                        density="compact"
                        class="mb-3"
                        autofocus
                    />

                    <!-- All Day Toggle -->
                    <v-switch
                        v-model="form.allDay"
                        :label="t('CalendarEventDialog.allDay')"
                        color="primary"
                        density="compact"
                        hide-details
                        class="mb-3"
                    />

                    <!-- Start / End -->
                    <v-row>
                        <v-col cols="6">
                            <v-text-field
                                v-model="form.dtstart"
                                :label="t('CalendarEventDialog.start')"
                                :type="form.allDay ? 'date' : 'datetime-local'"
                                :rules="[v => !!v || t('CalendarEventDialog.validation.startRequired')]"
                                variant="outlined"
                                density="compact"
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
                            />
                        </v-col>
                    </v-row>

                    <!-- Category & Priority -->
                    <v-row>
                        <v-col cols="6">
                            <v-select
                                v-model="form.category_id"
                                :items="categoryItems"
                                :label="t('CalendarEventDialog.category')"
                                variant="outlined"
                                density="compact"
                                clearable
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-select
                                v-model="form.prio"
                                :items="priorityItems"
                                :label="t('CalendarEventDialog.priority')"
                                variant="outlined"
                                density="compact"
                            />
                        </v-col>
                    </v-row>

                    <!-- Location -->
                    <v-text-field
                        v-model="form.location"
                        :label="t('CalendarEventDialog.location')"
                        variant="outlined"
                        density="compact"
                        prepend-inner-icon="mdi-map-marker"
                        class="mb-3"
                    />

                    <!-- Description -->
                    <v-textarea
                        v-model="form.description"
                        :label="t('CalendarEventDialog.description')"
                        variant="outlined"
                        density="compact"
                        rows="3"
                        auto-grow
                        class="mb-3"
                    />

                    <!-- Color -->
                    <div class="mb-3">
                        <div class="text-caption text-grey mb-1">{{ t('CalendarEventDialog.color') }}</div>
                        <div class="d-flex flex-wrap gap-1">
                            <v-btn
                                v-for="c in colorOptions"
                                :key="c"
                                icon
                                size="x-small"
                                :color="c"
                                :variant="form.color === c ? 'flat' : 'tonal'"
                                @click="form.color = form.color === c ? null : c"
                            >
                                <v-icon v-if="form.color === c" size="14" color="white">mdi-check</v-icon>
                            </v-btn>
                        </div>
                    </div>

                    <!-- Visibility -->
                    <v-select
                        v-model="form.visibility"
                        :items="visibilityItems"
                        :label="t('CalendarEventDialog.visibility')"
                        variant="outlined"
                        density="compact"
                        class="mb-3"
                    />

                    <!-- Wiederholen -->
                    <v-switch
                        v-model="form.repeat"
                        :label="t('CalendarEventDialog.repeat')"
                        color="primary"
                        density="compact"
                        hide-details
                        class="mb-3"
                    />

                    <template v-if="form.repeat">
                        <!-- Alle N [Einheit] -->
                        <div class="d-flex align-center gap-2 mb-3">
                            <span class="text-body-2 text-no-wrap flex-shrink-0">{{ t('CalendarEventDialog.repeatEvery') }}</span>
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

                        <!-- Ende: nach N oder bis Datum -->
                        <v-btn-toggle
                            v-model="form.repeatEndType"
                            mandatory
                            density="compact"
                            rounded="lg"
                            class="mb-2"
                        >
                            <v-btn value="count" size="small">{{ t('CalendarEventDialog.repeatEndAfter') }}</v-btn>
                            <v-btn value="date" size="small">{{ t('CalendarEventDialog.repeatEndOnDate') }}</v-btn>
                        </v-btn-toggle>

                        <div v-if="form.repeatEndType === 'count'" class="d-flex align-center gap-2 mb-3">
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
                            <span class="text-body-2">{{ t('CalendarEventDialog.repeatEndOccurrences') }}</span>
                        </div>
                        <v-text-field
                            v-else
                            v-model="form.recur_repeat_end"
                            type="date"
                            variant="outlined"
                            density="compact"
                            class="mb-3"
                        />
                    </template>

                    <!-- Customer/Vendor -->
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
                        prepend-inner-icon="mdi-account-tie"
                        clearable
                        hide-no-data
                        @update:search="onCvpSearch"
                    >
                        <template #item="{ props: itemProps, item }">
                            <v-list-item v-bind="itemProps">
                                <template #append>
                                    <v-icon
                                        size="small"
                                        :color="item.raw.typ === 'C' ? 'primary' : 'warning'"
                                    >
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
    // HH:MM:SS → HH:MM
    return raw.substring(0, 5)
}

const isEdit = computed(() => !!props.event?.id)

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
    // Wiederholung (UI-Felder)
    repeat: false,
    freq: 'yearly',
    recur_interval: 1,
    recur_count: 10,
    recur_repeat_end: '',
    repeatEndType: 'count',
})

const form = ref(defaultForm())

// CVP-Suche
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
    { title: t('CalendarEventDialog.priorityLow'), value: 0 },
    { title: t('CalendarEventDialog.priorityNormal'), value: 1 },
    { title: t('CalendarEventDialog.priorityHigh'), value: 2 }
])

const visibilityItems = computed(() => [
    { title: t('CalendarEventDialog.visibilityAll'), value: -1 },
    { title: t('CalendarEventDialog.visibilityPrivate'), value: 0 }
])

const freqItems = computed(() => [
    { title: t('CalendarEventDialog.repeatDays'),   value: 'daily' },
    { title: t('CalendarEventDialog.repeatWeeks'),  value: 'weekly' },
    { title: t('CalendarEventDialog.repeatMonths'), value: 'monthly' },
    { title: t('CalendarEventDialog.repeatYears'),  value: 'yearly' },
])

watch(selectedCvp, (val) => {
    if (val) {
        form.value.cvp_id = val.id
        form.value.cvp_name = val.name
        form.value.cvp_type = val.typ
    } else {
        form.value.cvp_id = null
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
            // datetime-local → date: Zeitanteil entfernen
            form.value[field] = val.split('T')[0].split(' ')[0]
        } else {
            // date → datetime-local: Kalender-Startzeit aus Config ergänzen
            if (!val.includes('T')) {
                form.value[field] = val + 'T' + defaultStartTime()
            }
        }
    }
})

watch(() => props.modelValue, (open) => {
    if (open) {
        if (props.event) {
            const e = props.event
            form.value = {
                id: e.id || null,
                title: e.title || '',
                description: e.description || '',
                dtstart: formatForInput(e.dtstart, e.allDay),
                dtend: formatForInput(e.dtend, e.allDay),
                allDay: e.allDay || false,
                location: e.location || '',
                color: e.color || null,
                prio: e.prio ?? 1,
                category_id: e.category_id || null,
                visibility: e.visibility ?? -1,
                cvp_id: e.cvp_id || null,
                cvp_name: e.cvp_name || '',
                cvp_type: e.cvp_type || null,
                // Wiederholung
                repeat:         !!e.freq,
                freq:           e.freq || 'yearly',
                recur_interval: e.recur_interval || 1,
                repeatEndType:  e.count ? 'count' : (e.repeat_end ? 'date' : 'count'),
                recur_count:    e.count || 10,
                recur_repeat_end: e.repeat_end
                    ? (e.repeat_end.split('T')[0].split(' ')[0])
                    : '',
            }
            if (e.cvp_id && e.cvp_name) {
                const item = { id: e.cvp_id, name: e.cvp_name, typ: e.cvp_type }
                selectedCvp.value = item
                cvpResults.value = [item]
            } else {
                selectedCvp.value = null
                cvpResults.value = []
            }
        } else {
            form.value = defaultForm()
            selectedCvp.value = null
            cvpResults.value = []
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
            axios.post('/api/customer_vendor/', {
                action: 'searchCV',
                type: 'customer',
                where: { name: query }
            }, { signal: cvpAbort.signal }),
            axios.post('/api/customer_vendor/', {
                action: 'searchCV',
                type: 'vendor',
                where: { name: query }
            }, { signal: cvpAbort.signal })
        ])
        const customers = (custRes.data?.payload?.search?.results ?? []).map(c => ({
            id: c.id, name: c.name, typ: 'C'
        }))
        const vendors = (vendRes.data?.payload?.search?.results ?? []).map(v => ({
            id: v.id, name: v.name, typ: 'V'
        }))
        cvpResults.value = [...customers, ...vendors].slice(0, 15)
    } catch {
        // aborted or network error
    } finally {
        cvpLoading.value = false
    }
}

function formatForInput(dateStr, allDay) {
    if (!dateStr) return ''
    if (allDay) {
        return dateStr.split('T')[0].split(' ')[0]
    }
    const d = new Date(dateStr)
    if (isNaN(d.getTime())) return dateStr
    const pad = n => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function formatForSave(dateStr, allDay) {
    if (!dateStr) return null
    if (allDay) {
        return dateStr.split('T')[0].split(' ')[0]
    }
    // Nur Datum ohne Uhrzeit (z.B. nach allDay-Toggle) → Mitternacht anhängen
    if (!dateStr.includes('T') && !dateStr.includes(' ')) {
        return dateStr + ' 00:00:00'
    }
    return dateStr.replace('T', ' ') + ':00'
}

function applyCurrent() {
    if (props.currentCustomer) {
        const item = {
            id: props.currentCustomer.id,
            name: props.currentCustomer.name,
            typ: props.currentCustomer.type || 'C'
        }
        selectedCvp.value = item
        cvpResults.value = [item]
    }
}

function close() {
    emit('update:modelValue', false)
}

function closeAndSave() {
    if (formValid.value) {
        save()
    } else {
        close()
    }
}

function save() {
    if (!formValid.value) return
    const f = form.value
    const payload = {
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
        // Wiederholung: immer mitschicken damit Backend alte Werte überschreibt
        freq:        f.repeat ? f.freq : null,
        interval:    f.repeat ? f.recur_interval : null,
        count:       f.repeat && f.repeatEndType === 'count' ? f.recur_count : null,
        repeat_end:  f.repeat && f.repeatEndType === 'date'  ? f.recur_repeat_end : null,
    }
    emit('save', payload)
}
</script>
