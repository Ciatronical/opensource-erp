<!-- src/core/views/follow-up/components/follow-up-calendar.vue -->
<template>
    <v-card flat class="follow-up-calendar-wrapper">
        <FullCalendar
            ref="calendarRef"
            :options="calendarOptions"
        />

        <!-- Event-Detail-Dialog -->
        <v-dialog v-model="showEventDialog" max-width="420">
            <v-card v-if="selectedEvent" rounded="xl" elevation="8">
                <div
                    class="event-dialog-header pa-4"
                    :style="{ background: getGradient(selectedEvent.extendedProps.priority) }"
                >
                    <div class="d-flex align-center" style="padding-right: 48px;">
                        <v-avatar size="40" color="white" class="mr-3">
                            <v-icon :color="getPriorityColor(selectedEvent.extendedProps.priority)">
                                mdi-bell-ring
                            </v-icon>
                        </v-avatar>
                        <div style="flex: 1; min-width: 0;">
                            <div class="text-white text-h6 font-weight-medium" style="word-break: break-word;">
                                {{ selectedEvent.title }}
                            </div>
                            <div class="text-white-darken-1 text-caption">
                                {{ formatDate(selectedEvent.start) }}
                            </div>
                        </div>
                    </div>
                    <v-btn
                        icon
                        variant="text"
                        size="small"
                        class="close-btn"
                        @click="showEventDialog = false"
                    >
                        <v-icon color="white">mdi-close</v-icon>
                    </v-btn>
                </div>

                <v-card-text class="pa-4">
                    <div v-if="selectedEvent.extendedProps.body" class="mb-4">
                        <div class="d-flex align-start pa-3 bg-grey-lighten-4 rounded-lg">
                            <v-icon size="20" color="grey-darken-1" class="mr-3 mt-1">mdi-text</v-icon>
                            <p class="text-body-2 text-grey-darken-2 mb-0">
                                {{ selectedEvent.extendedProps.body }}
                            </p>
                        </div>
                    </div>

                    <div v-if="selectedEvent.extendedProps.links?.length" class="mb-4">
                        <div class="text-caption text-grey mb-2">{{ t('FollowUpCalendar.links') }}</div>
                        <v-chip
                            v-for="link in selectedEvent.extendedProps.links"
                            :key="link.id"
                            size="small"
                            color="primary"
                            variant="tonal"
                            class="mr-2 mb-1"
                            prepend-icon="mdi-link"
                            @click="navigateToLink(link)"
                            style="cursor: pointer;"
                        >
                            {{ link.trans_info || link.trans_type }}
                        </v-chip>
                    </div>
                </v-card-text>

                <v-divider />

                <v-card-actions class="pa-4">
                    <v-btn
                        v-if="!selectedEvent.extendedProps.is_done"
                        color="success"
                        variant="flat"
                        rounded="lg"
                        @click="markDone(selectedEvent.id)"
                    >
                        <v-icon class="mr-2">mdi-check-circle</v-icon>
                        {{ t('FollowUpCalendar.done') }}
                    </v-btn>
                    <v-btn
                        v-else
                        color="warning"
                        variant="flat"
                        rounded="lg"
                        @click="markUndone(selectedEvent.id)"
                    >
                        <v-icon class="mr-2">mdi-undo</v-icon>
                        {{ t('FollowUpCalendar.reopen') }}
                    </v-btn>

                    <v-spacer />

                    <v-btn
                        color="primary"
                        variant="flat"
                        rounded="lg"
                        @click="editEvent"
                    >
                        <v-icon class="mr-2">mdi-pencil</v-icon>
                        {{ t('FollowUpCalendar.edit') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

    </v-card>
</template>

<script setup>
// src/core/views/follow-up/components/follow-up-calendar.vue
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction'
import deLocale from '@fullcalendar/core/locales/de'

const props = defineProps({
    items: { type: Array, default: () => [] }
})

const emit = defineEmits(['edit', 'mark-done', 'mark-undone', 'create', 'date-change', 'create-with-date'])

const { t, locale } = useI18n()
const router = useRouter()
const calendarRef = ref(null)
const showEventDialog = ref(false)
const selectedEvent = ref(null)

const calendarEvents = computed(() => {
    return props.items.map(item => ({
        id: String(item.id),
        title: item.subject || item.note?.subject || t('FollowUpCalendar.noSubject'),
        start: item.follow_up_date,
        allDay: true,
        backgroundColor: getPriorityColor(item.priority),
        borderColor: 'transparent',
        textColor: item.priority === 'today' ? '#000' : '#fff',
        classNames: ['fc-event-' + item.priority],
        extendedProps: {
            priority: item.priority,
            body: item.body || item.note?.body,
            is_done: item.is_done,
            links: item.links,
            original: item
        }
    }))
})

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    locale: locale.value === 'de' ? deLocale : undefined,
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,dayGridWeek'
    },
    events: calendarEvents.value,
    eventClick: handleEventClick,
    dateClick: handleDateClick,
    eventDrop: handleEventDrop,
    editable: true,
    selectable: true,
    dayMaxEvents: 3,
    moreLinkClick: 'popover',
    height: 'auto',
    contentHeight: 700,
    handleWindowResize: true,
    fixedWeekCount: false,
    showNonCurrentDates: true,
    nowIndicator: true,
    buttonText: {
        today: t('FollowUpCalendar.today'),
        month: t('FollowUpCalendar.month'),
        week: t('FollowUpCalendar.week')
    },
    titleFormat: { year: 'numeric', month: 'long' },
    eventDisplay: 'block'
}))

function getPriorityColor(priority) {
    const colors = {
        overdue: '#E53935',
        today: '#FFC107',
        soon: '#FF9800',
        normal: '#4CAF50',
        done: '#9E9E9E'
    }
    return colors[priority] || colors.normal
}

function getGradient(priority) {
    const gradients = {
        overdue: 'linear-gradient(135deg, #E53935 0%, #B71C1C 100%)',
        today: 'linear-gradient(135deg, #FFC107 0%, #FF8F00 100%)',
        soon: 'linear-gradient(135deg, #FF9800 0%, #E65100 100%)',
        normal: 'linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%)',
        done: 'linear-gradient(135deg, #9E9E9E 0%, #616161 100%)'
    }
    return gradients[priority] || gradients.normal
}

function formatDate(date) {
    if (!date) return ''
    const d = new Date(date)
    return d.toLocaleDateString(locale.value === 'de' ? 'de-DE' : 'en-US', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric'
    })
}

function handleEventClick(info) {
    info.jsEvent.preventDefault()
    selectedEvent.value = info.event
    showEventDialog.value = true
}

function handleDateClick(info) {
    emit('create-with-date', info.dateStr.split('T')[0])
}

function handleEventDrop(info) {
    emit('date-change', {
        id: parseInt(info.event.id),
        newDate: info.event.startStr.split('T')[0]
    })
}

function markDone(id) {
    emit('mark-done', parseInt(id))
    showEventDialog.value = false
}

function markUndone(id) {
    emit('mark-undone', parseInt(id))
    showEventDialog.value = false
}

function editEvent() {
    emit('edit', selectedEvent.value.extendedProps.original)
    showEventDialog.value = false
}

function navigateToLink(link) {
    // Navigation basierend auf trans_type mit named routes - EXAKT wie in follow-up-card.vue
    const routeMap = {
        customer: { name: 'customer-edit', params: { id: link.trans_id } },
        vendor: { name: 'vendor-edit', params: { id: link.trans_id } },
        sales_quotation: { name: 'quotation-edit', params: { id: link.trans_id } },
        sales_order: { name: 'order-edit', params: { id: link.trans_id } },
        sales_invoice: { name: 'invoice-view', params: { invoiceID: link.trans_id } }
    };

    if (routeMap[link.trans_type]) {
        showEventDialog.value = false;
        router.push(routeMap[link.trans_type]);
    } else {
        console.warn('Unknown link type:', link.trans_type);
    }
}


watch(() => props.items, () => {
    calendarRef.value?.getApi()?.refetchEvents()
}, { deep: true })

defineExpose({
    goToDate: (date) => calendarRef.value?.getApi()?.gotoDate(date),
    next: () => calendarRef.value?.getApi()?.next(),
    prev: () => calendarRef.value?.getApi()?.prev(),
    today: () => calendarRef.value?.getApi()?.today()
})
</script>

<style>
.follow-up-calendar-wrapper {
    padding: 20px;
    background: linear-gradient(180deg, #fafafa 0%, #fff 100%);
}

/* Event Dialog Header */
.event-dialog-header {
    position: relative;
    border-radius: 24px 24px 0 0;
}

.event-dialog-header .close-btn {
    position: absolute;
    top: 12px;
    right: 12px;
}

.create-dialog-header {
    background: linear-gradient(180deg, #f5f5f5 0%, #fff 100%);
    border-radius: 24px 24px 0 0;
}

/* FullCalendar Basis */
.fc {
    font-family: inherit;
    --fc-border-color: #e0e0e0;
    --fc-today-bg-color: rgba(25, 118, 210, 0.06);
    --fc-neutral-bg-color: #fafafa;
    --fc-page-bg-color: #fff;
    --fc-now-indicator-color: #E53935;
}

/* Toolbar */
.fc .fc-toolbar {
    margin-bottom: 24px;
    padding: 16px 20px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    flex-wrap: wrap;
    gap: 12px;
}

.fc .fc-toolbar-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1a1a1a;
    letter-spacing: -0.02em;
}

/* Toolbar Buttons */
.fc .fc-button {
    padding: 10px 16px;
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: none;
    border-radius: 10px;
    border: none;
    box-shadow: none !important;
    transition: all 0.2s ease;
}

.fc .fc-button-primary {
    background-color: #f0f0f0;
    color: #424242;
}

.fc .fc-button-primary:hover {
    background-color: #e0e0e0;
    transform: translateY(-1px);
}

.fc .fc-button-primary:not(:disabled).fc-button-active,
.fc .fc-button-primary:not(:disabled):active {
    background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
    color: white;
}

.fc .fc-today-button {
    background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%) !important;
    color: white !important;
    box-shadow: 0 4px 12px rgba(25, 118, 210, 0.3) !important;
}

.fc .fc-today-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(25, 118, 210, 0.4) !important;
}

.fc .fc-today-button:disabled {
    opacity: 0.5;
    transform: none;
}

/* Kalender Container */
.fc .fc-view-harness {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}

/* Header (Wochentage) */
.fc .fc-col-header {
    background: linear-gradient(180deg, #f8f9fa 0%, #f0f2f5 100%);
}

.fc .fc-col-header-cell {
    padding: 14px 4px;
    font-weight: 600;
    font-size: 0.8rem;
    color: #616161;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #e0e0e0;
}

/* Tages-Zellen Monatsansicht */
.fc .fc-daygrid-day {
    min-height: 100px;
    transition: background-color 0.2s;
}

.fc .fc-daygrid-day:hover {
    background-color: rgba(25, 118, 210, 0.03);
}

.fc .fc-daygrid-day-number {
    font-size: 0.9rem;
    font-weight: 600;
    color: #424242;
    padding: 8px;
}

.fc .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
    background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
    color: white;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(25, 118, 210, 0.3);
}

.fc .fc-daygrid-day.fc-day-other .fc-daygrid-day-number {
    color: #bdbdbd;
}

/* Events allgemein */
.fc .fc-daygrid-event {
    border-radius: 8px;
    padding: 6px 10px;
    margin: 2px 4px;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}

.fc .fc-daygrid-event:hover {
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.fc .fc-event-title {
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Priority Event Styles */
.fc .fc-event-overdue {
    background: linear-gradient(135deg, #E53935 0%, #C62828 100%) !important;
    box-shadow: 0 2px 8px rgba(229, 57, 53, 0.3);
}

.fc .fc-event-today {
    background: linear-gradient(135deg, #FFC107 0%, #FFB300 100%) !important;
    box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);
}

.fc .fc-event-soon {
    background: linear-gradient(135deg, #FF9800 0%, #F57C00 100%) !important;
    box-shadow: 0 2px 8px rgba(255, 152, 0, 0.3);
}

.fc .fc-event-normal {
    background: linear-gradient(135deg, #4CAF50 0%, #43A047 100%) !important;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
}

.fc .fc-event-done {
    background: linear-gradient(135deg, #9E9E9E 0%, #757575 100%) !important;
    text-decoration: line-through;
    opacity: 0.7;
}

/* More Link */
.fc .fc-daygrid-more-link {
    color: #1976d2;
    font-weight: 600;
    font-size: 0.75rem;
    padding: 4px 10px;
    margin: 2px 4px;
    border-radius: 8px;
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
}

/* Popover */
.fc .fc-popover {
    border-radius: 16px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.15);
    border: none;
    overflow: hidden;
}

.fc .fc-popover-header {
    background: linear-gradient(135deg, #f5f5f5 0%, #eeeeee 100%);
    padding: 14px 18px;
    font-weight: 600;
}

/* Wochenende */
.fc .fc-day-sat,
.fc .fc-day-sun {
    background-color: rgba(0,0,0,0.02);
}

/* Responsive */
@media (max-width: 768px) {
    .follow-up-calendar-wrapper {
        padding: 12px;
    }

    .fc .fc-toolbar {
        flex-direction: column;
        gap: 12px;
        padding: 12px;
    }

    .fc .fc-toolbar-chunk {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 4px;
    }

    .fc .fc-toolbar-title {
        font-size: 1.1rem;
    }

    .fc .fc-button {
        padding: 8px 12px;
        font-size: 0.75rem;
    }
}
</style>