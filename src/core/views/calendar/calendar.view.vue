<!-- src/core/views/calendar/calendar.view.vue -->
<template>
    <NavbarView />

    <v-container fluid class="pa-2 pa-md-4">
        <!-- Header -->
        <v-row class="mb-4">
            <v-col cols="12" md="6">
                <h1 class="text-h5 text-md-h4 d-flex align-center">
                    <v-icon class="me-2" color="blue-darken-2">mdi-calendar-month</v-icon>
                    {{ t('CalendarView.title') }}
                </h1>
            </v-col>
            <v-col cols="12" md="6" class="d-flex justify-end align-center gap-2 flex-wrap">
                <v-btn
                    variant="outlined"
                    size="small"
                    @click="showCategoryManager = true"
                    prepend-icon="mdi-tag-multiple"
                >
                    {{ t('CalendarView.actions.categories') }}
                </v-btn>
                <v-btn
                    variant="outlined"
                    size="small"
                    @click="showImportDialog = true"
                    prepend-icon="mdi-calendar-import"
                >
                    {{ t('CalendarView.actions.import') }}
                </v-btn>
                <v-btn
                    color="primary"
                    @click="openCreateDialog"
                    prepend-icon="mdi-plus"
                >
                    {{ t('CalendarView.actions.create') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Filter: Suche + Kategorien -->
        <v-row class="mb-2">
            <v-col cols="12" md="4" lg="3">
                <v-text-field
                    v-model="searchQuery"
                    :label="t('CalendarView.search')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    clearable
                    hide-details
                />
            </v-col>
            <v-col v-if="categories.length > 0" cols="12" md="4" lg="3">
                <v-select
                    v-model="selectedCategoryIds"
                    :items="categories"
                    item-title="label"
                    item-value="id"
                    :label="t('CalendarMain.filterCategories')"
                    multiple
                    clearable
                    density="compact"
                    variant="outlined"
                    hide-details
                >
                    <template #selection="{ item, index }">
                        <v-chip v-if="index < 3" :color="item.raw.color" size="x-small" label>
                            {{ item.title }}
                        </v-chip>
                        <span v-if="index === 3" class="text-grey text-caption ms-1">
                            +{{ selectedCategoryIds.length - 3 }}
                        </span>
                    </template>
                    <template #item="{ item, props: itemProps }">
                        <v-list-item v-bind="itemProps">
                            <template #prepend>
                                <v-icon :color="item.raw.color">mdi-circle</v-icon>
                            </template>
                        </v-list-item>
                    </template>
                </v-select>
            </v-col>
        </v-row>

        <!-- Search Results -->
        <v-card v-if="searchQuery && searchQuery.length >= 2" class="mb-4" variant="outlined" rounded="lg">
            <v-card-title class="text-subtitle-1 d-flex align-center py-2 px-4">
                <v-icon class="me-2" size="small">mdi-magnify</v-icon>
                {{ t('CalendarView.searchResults') }}
                <v-chip class="ms-2" size="x-small" label>{{ searchResults.length }}</v-chip>
                <v-spacer />
                <v-btn icon size="x-small" variant="text" @click="searchQuery = ''">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>
            <v-divider />
            <v-progress-linear v-if="searchLoading" indeterminate color="primary" />
            <v-list v-if="searchResults.length > 0" density="compact" class="py-0">
                <v-list-item
                    v-for="ev in searchResults"
                    :key="ev.id"
                    @click="openEventDetail(ev)"
                    class="py-1"
                >
                    <template #prepend>
                        <v-icon :color="ev.color || '#1976D2'" size="small">mdi-circle</v-icon>
                    </template>
                    <v-list-item-title>{{ ev.title }}</v-list-item-title>
                    <v-list-item-subtitle>
                        {{ formatSearchDate(ev.dtstart, ev.allDay) }}
                        <span v-if="ev.location" class="ms-2">
                            <v-icon size="x-small">mdi-map-marker</v-icon> {{ ev.location }}
                        </span>
                    </v-list-item-subtitle>
                </v-list-item>
            </v-list>
            <v-card-text v-else-if="!searchLoading" class="text-center text-grey py-4">
                {{ t('CalendarView.noResults') }}
            </v-card-text>
        </v-card>

        <!-- Calendar -->
        <calendar-main
            :events="filteredEvents"
            @event-click="openEventDetail"
            @date-click="openCreateDialogWithDate"
            @event-drop="handleEventDrop"
            @event-resize="handleEventResize"
            @dates-set="handleDatesSet"
        />

        <!-- Event Detail Dialog -->
        <calendar-event-detail
            v-model="eventDetailOpen"
            :event="selectedEvent"
            @edit="editFromDetail"
            @delete="deleteFromDetail"
        />

        <!-- Event Create/Edit Dialog -->
        <calendar-event-dialog
            v-model="eventDialogOpen"
            :event="editingEvent"
            :categories="categories"
            :current-customer="currentCustomer"
            @save="saveEvent"
        />

        <!-- Import Dialog -->
        <calendar-import-dialog
            v-model="showImportDialog"
            :categories="categories"
            @imported="onImported"
        />

        <!-- Category Manager Dialog -->
        <calendar-category-manager
            v-model="showCategoryManager"
            :categories="categories"
            @save="saveCategory"
            @delete="deleteCategory"
        />

        <!-- Delete Confirmation -->
        <v-dialog v-model="deleteDialogOpen" max-width="400">
            <v-card>
                <v-card-title class="text-h6">
                    <v-icon color="error" class="me-2">mdi-delete-alert</v-icon>
                    {{ t('CalendarView.confirmDelete.title') }}
                </v-card-title>
                <v-card-text>
                    {{ t('CalendarView.confirmDelete.message') }}
                    <div v-if="deletingEvent" class="mt-2 font-weight-medium">
                        "{{ deletingEvent.title }}"
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="deleteDialogOpen = false">
                        {{ t('CalendarView.actions.cancel') }}
                    </v-btn>
                    <v-btn color="error" variant="flat" @click="confirmDelete">
                        {{ t('CalendarView.actions.delete') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snackbar -->
        <v-snackbar
            v-model="snackbar.show"
            :color="snackbar.color"
            :timeout="3000"
            location="bottom right"
        >
            {{ snackbar.message }}
            <template #actions>
                <v-btn variant="text" @click="snackbar.show = false">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </template>
        </v-snackbar>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import CalendarMain from './components/calendar-main.vue'
import CalendarEventDialog from './components/calendar-event-dialog.vue'
import CalendarEventDetail from './components/calendar-event-detail.vue'
import CalendarCategoryManager from './components/calendar-category-manager.vue'
import CalendarImportDialog from './components/calendar-import-dialog.vue'

const { t } = useI18n()
const store = oserpStore()

// State
const events = ref([])
const holidays = ref([])
const loadedHolidayYears = new Set()
const categories = ref([])
const selectedCategoryIds = ref([])
const searchQuery = ref('')
const searchResults = ref([])
const searchLoading = ref(false)
const currentDateRange = ref({ start: null, end: null })

// Dialog State
const eventDialogOpen = ref(false)
const editingEvent = ref(null)
const eventDetailOpen = ref(false)
const selectedEvent = ref(null)
const showCategoryManager = ref(false)
const showImportDialog = ref(false)
const deleteDialogOpen = ref(false)
const deletingEvent = ref(null)

// Snackbar
const snackbar = ref({ show: false, message: '', color: 'success' })

// Current customer from store
const currentCustomer = computed(() => {
    const cv = store.customer_vendor
    if (cv?.profile?.id) {
        return {
            id: cv.profile.id,
            name: cv.profile.name,
            type: cv.profile.src || 'C'
        }
    }
    return null
})

// Filtered events by selected categories, merged with holidays
const filteredEvents = computed(() => {
    const base = selectedCategoryIds.value.length === 0
        ? events.value
        : events.value.filter(e => selectedCategoryIds.value.includes(e.category_id) || !e.category_id)
    return [...base, ...holidays.value]
})

// ── API Calls ──

async function loadCategories() {
    try {
        const response = await axios.post('/api/calendar/', { action: 'getEventCategories' })
        if (response.data.success) {
            categories.value = response.data.payload?.result?.categories || []
        }
    } catch (error) {
        console.error('Error loading categories:', error)
    }
}

async function loadEvents() {
    if (!currentDateRange.value.start || !currentDateRange.value.end) return
    try {
        const response = await axios.post('/api/calendar/', {
            action: 'getCalendarEvents',
            startDate: currentDateRange.value.start,
            endDate: currentDateRange.value.end
        })
        if (response.data.success) {
            events.value = response.data.payload?.events || []
        }
    } catch (error) {
        console.error('Error loading events:', error)
        showSnackbar(t('CalendarView.messages.loadError'), 'error')
    }
}

async function loadHolidays(year) {
    if (loadedHolidayYears.has(year)) return
    loadedHolidayYears.add(year)
    try {
        const response = await axios.post('/api/calendar/', {
            action: 'getPublicHolidays',
            year
        })
        if (response.data.success) {
            const fetched = (response.data.payload?.holidays || []).map(h => ({
                id: `holiday_${h.date}`,
                title: h.name,
                dtstart: h.date,
                dtend: null,
                allDay: true,
                isHoliday: true
            }))
            holidays.value = [...holidays.value, ...fetched]
        }
    } catch {
        loadedHolidayYears.delete(year)
    }
}

async function saveEvent(data) {
    try {
        let response
        if (data.id) {
            response = await axios.post('/api/calendar/', {
                action: 'updateCalendarEvent',
                ...data
            })
        } else {
            response = await axios.post('/api/calendar/', {
                action: 'createCalendarEvent',
                ...data
            })
        }

        if (response.data.success) {
            eventDialogOpen.value = false
            showSnackbar(t('CalendarView.messages.saved'), 'success')
            loadEvents()
        } else {
            showSnackbar(response.data.text || t('CalendarView.messages.saveError'), 'error')
        }
    } catch (error) {
        console.error('Error saving event:', error)
        showSnackbar(t('CalendarView.messages.saveError'), 'error')
    }
}

async function deleteEvent(id) {
    try {
        const response = await axios.post('/api/calendar/', {
            action: 'deleteCalendarEvent',
            id: id
        })
        if (response.data.success) {
            showSnackbar(t('CalendarView.messages.deleted'), 'success')
            loadEvents()
        }
    } catch (error) {
        console.error('Error deleting event:', error)
        showSnackbar(t('CalendarView.messages.error'), 'error')
    }
}

async function handleEventDrop(info) {
    try {
        await axios.post('/api/calendar/', {
            action: 'moveCalendarEvent',
            id: parseInt(info.id),
            dtstart: info.start,
            dtend: info.end,
            allDay: info.allDay
        })
        loadEvents()
    } catch (error) {
        console.error('Error moving event:', error)
        info.revert?.()
        showSnackbar(t('CalendarView.messages.error'), 'error')
    }
}

async function handleEventResize(info) {
    try {
        await axios.post('/api/calendar/', {
            action: 'moveCalendarEvent',
            id: parseInt(info.id),
            dtstart: info.start,
            dtend: info.end,
            allDay: info.allDay
        })
        loadEvents()
    } catch (error) {
        console.error('Error resizing event:', error)
        info.revert?.()
        showSnackbar(t('CalendarView.messages.error'), 'error')
    }
}

async function saveCategory(data) {
    try {
        const response = await axios.post('/api/calendar/', {
            action: 'saveEventCategory',
            ...data
        })
        if (response.data.success) {
            categories.value = response.data.payload?.result?.categories || []
            showSnackbar(t('CalendarView.messages.categorySaved'), 'success')
        }
    } catch (error) {
        console.error('Error saving category:', error)
        showSnackbar(t('CalendarView.messages.error'), 'error')
    }
}

async function deleteCategory(id) {
    try {
        const response = await axios.post('/api/calendar/', {
            action: 'deleteEventCategory',
            id: id
        })
        if (response.data.success) {
            categories.value = response.data.payload?.result?.categories || []
            showSnackbar(t('CalendarView.messages.categoryDeleted'), 'success')
            loadEvents()
        }
    } catch (error) {
        console.error('Error deleting category:', error)
        showSnackbar(t('CalendarView.messages.error'), 'error')
    }
}

function onImported(count) {
    showSnackbar(t('CalendarView.messages.imported', { n: count }), 'success')
    loadEvents()
}

// ── Search ──

let searchTimeout = null
watch(searchQuery, (q) => {
    clearTimeout(searchTimeout)
    if (!q || q.length < 2) {
        searchResults.value = []
        return
    }
    searchLoading.value = true
    searchTimeout = setTimeout(() => searchEvents(q), 400)
})

async function searchEvents(query) {
    try {
        const response = await axios.post('/api/calendar/', {
            action: 'searchCalendarEvents',
            query
        })
        if (response.data.success) {
            searchResults.value = response.data.payload?.result?.events || []
        }
    } catch (error) {
        console.error('Error searching events:', error)
    } finally {
        searchLoading.value = false
    }
}

// ── Event Handlers ──

function handleDatesSet({ start, end, view, viewStart }) {
    currentDateRange.value = { start, end }
    loadEvents()
    const startYear = parseInt(start.substring(0, 4))
    const endYear = parseInt(end.substring(0, 4))
    for (let y = startYear; y <= endYear; y++) loadHolidays(y)

    // Wandanzeige steuern — viewStart = tatsächlicher Monatserster (nicht Raster-Padding)
    axios.post('/api/calendar/', {
        action: 'setWallDisplayView',
        view:      view || 'timeGridCustomWeek',
        startDate: viewStart || start
    }).catch(() => {})
}

function openCreateDialog() {
    editingEvent.value = null
    eventDialogOpen.value = true
}

function openCreateDialogWithDate({ dateStr, allDay }) {
    editingEvent.value = { dtstart: dateStr, allDay: !!allDay }
    eventDialogOpen.value = true
}

function openEventDetail(event) {
    selectedEvent.value = event
    eventDetailOpen.value = true
}

function editFromDetail(event) {
    eventDetailOpen.value = false
    editingEvent.value = { ...event }
    eventDialogOpen.value = true
}

function deleteFromDetail(event) {
    eventDetailOpen.value = false
    deletingEvent.value = event
    deleteDialogOpen.value = true
}

function confirmDelete() {
    if (!deletingEvent.value) return
    deleteEvent(deletingEvent.value.id)
    deleteDialogOpen.value = false
    deletingEvent.value = null
}

function formatSearchDate(dateStr, allDay) {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    if (isNaN(d.getTime())) return dateStr
    const options = allDay
        ? { day: '2-digit', month: '2-digit', year: 'numeric' }
        : { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }
    return d.toLocaleDateString('de-DE', options)
}

function showSnackbar(message, color = 'success') {
    snackbar.value = { show: true, message, color }
}

// ── SSE: Echtzeit-Updates ──

let eventSource = null

function connectSSE() {
    eventSource = new EventSource('/sse/events')
    eventSource.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data)
            if (data.action !== undefined && data.uid !== undefined) {
                // calendar_change Event — Events neu laden
                loadEvents()
            }
        } catch { /* kein Kalender-Event */ }
    }
    eventSource.onerror = () => { /* SSE-Fehler still ignorieren, reconnect ist automatisch */ }
}

// ── Lifecycle ──

onMounted(() => {
    loadCategories()
    connectSSE()
})

onUnmounted(() => {
    if (eventSource) {
        eventSource.close()
        eventSource = null
    }
})
</script>
