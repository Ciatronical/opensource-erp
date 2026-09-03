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
import { transTypeRoute } from '@/core/constants/routes.js'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/vue3/daygrid'
import interactionPlugin from '@fullcalendar/vue3/interaction'
import { classicThemePlugin, calendarLocale, oserpCalendarHooks } from '@/core/components/calendar/oserp-fullcalendar.js'

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
        color: getPriorityColor(item.priority),
        contrastColor: item.priority === 'today' ? '#000' : '#fff',
        className: 'ofc-prio-' + item.priority,
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
    ...oserpCalendarHooks,
    plugins: [classicThemePlugin, dayGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    locale: calendarLocale(locale.value),
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,dayGridWeek'
    },
    buttons: {
        today:        { text: t('FollowUpCalendar.today'), isPrimary: true },
        dayGridMonth: { text: t('FollowUpCalendar.month') },
        dayGridWeek:  { text: t('FollowUpCalendar.week') }
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
    fixedWeekCount: false,
    showNonCurrentDates: true,
    nowIndicator: true,
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
    // Navigation basierend auf trans_type - EXAKT wie in follow-up-card.vue
    const target = transTypeRoute(link.trans_type, link.trans_id);

    if (target) {
        showEventDialog.value = false;
        router.push(target);
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

/* Basis-Optik: src/core/components/calendar/oserp-fullcalendar.css */

/* Prioritäts-Farben — !important übersteuert die Inline-Farbe von FullCalendar */
.oserp-cal .ofc-prio-overdue {
    background: linear-gradient(135deg, #E53935 0%, #C62828 100%) !important;
    box-shadow: 0 2px 8px rgba(229, 57, 53, 0.3);
}

.oserp-cal .ofc-prio-today {
    background: linear-gradient(135deg, #FFC107 0%, #FFB300 100%) !important;
    box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);
}

.oserp-cal .ofc-prio-soon {
    background: linear-gradient(135deg, #FF9800 0%, #F57C00 100%) !important;
    box-shadow: 0 2px 8px rgba(255, 152, 0, 0.3);
}

.oserp-cal .ofc-prio-normal {
    background: linear-gradient(135deg, #4CAF50 0%, #43A047 100%) !important;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
}

.oserp-cal .ofc-prio-done {
    background: linear-gradient(135deg, #9E9E9E 0%, #757575 100%) !important;
    text-decoration: line-through;
    opacity: 0.7;
}
</style>
