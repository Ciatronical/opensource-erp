<!-- src/core/views/calendar/components/calendar-main.vue -->
<template>
    <v-card flat class="calendar-wrapper">
        <FullCalendar ref="calendarRef" :options="calendarOptions" />
    </v-card>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import listPlugin from '@fullcalendar/list'
import interactionPlugin from '@fullcalendar/interaction'
import rrulePlugin from '@fullcalendar/rrule'
import deLocale from '@fullcalendar/core/locales/de'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { buildWorkdayProgressSVG, parseWorkdayConfig } from '@/core/utils/workdayProgress.js'

const props = defineProps({
    events: { type: Array, default: () => [] },
    initialView: { type: String, default: 'timeGridCustomWeek' },
    customButtons: { type: Object, default: () => ({}) },
    headerToolbar: { type: Object, default: null },
    height: { type: [String, Number], default: 'auto' },
    // null = nicht an FullCalendar durchreichen (contentHeight wird dann aus height berechnet)
    contentHeight: { type: [String, Number], default: 700 },
    expandRows: { type: Boolean, default: false },
    dayMaxEventRows: { type: [Number, Boolean], default: undefined },
    hiddenDays: { type: Array, default: () => [] },
    weekDuration: { type: Number, default: 7 }
})

const emit = defineEmits(['event-click', 'date-click', 'event-drop', 'event-resize', 'dates-set'])

const { t, locale } = useI18n()
const calendarRef = ref(null)
const oserp = oserpStore()

// ── Uhr & Tagesfortschritt ──
const currentTime = ref('')
let clockTimer = null
const workdayConfig = parseWorkdayConfig(k => oserp.getClientDefaultValue(k))

function updateClock() {
    currentTime.value = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + ' Uhr'
    nextTick(applyDayProgress)
}

function applyDayProgress() {
    const btn = calendarRef.value?.getApi()?.el?.querySelector('.fc-dayProgress-button')
    if (!btn) return
    btn.innerHTML = buildWorkdayProgressSVG(workdayConfig)
}

// ── Kalenderwoche ──
const currentKW = ref(getISOWeek(new Date()))

function getISOWeek(date) {
    const d = new Date(date)
    d.setHours(0, 0, 0, 0)
    d.setDate(d.getDate() + 4 - (d.getDay() || 7))
    const yearStart = new Date(d.getFullYear(), 0, 1)
    return Math.ceil((((d - yearStart) / 86400000) + 1) / 7)
}

function applyKWColor(kw) {
    if (!kw) return
    const btn = calendarRef.value?.getApi()?.el?.querySelector('.fc-calendarWeek-button')
    if (!btn) return
    const isEven = kw % 2 === 0
    btn.style.color = isEven ? '#1565c0' : '#2e7d32'
    btn.style.backgroundColor = isEven ? '#e3f2fd' : '#e8f5e9'
}

const mergedCustomButtons = computed(() => ({
    calendarWeek: {
        text: `${currentKW.value}.KW`,
        hint: 'Kalenderwoche'
    },
    clock: {
        text: currentTime.value || '--:--'
    },
    customPrev: {
        icon: 'chevron-left',
        click: navigatePrev
    },
    customNext: {
        icon: 'chevron-right',
        click: navigateNext
    },
    customToday: {
        text: t('CalendarMain.today'),
        click: navigateToday
    },
    dayProgress: {
        text: ' '
    },
    ...props.customButtons
}))

// Arbeitszeiten aus Company-Config — sichtbarer Tagesbereich im TimeGrid.
// Werte aus HH:MM in HH:MM:SS normalisieren (FullCalendar-Format).
function normalizeTime(value, fallback) {
    const v = (value || '').trim()
    if (/^\d{1,2}:\d{2}$/.test(v)) return v + ':00'
    if (/^\d{1,2}:\d{2}:\d{2}$/.test(v)) return v
    return fallback
}
const slotMinTime = normalizeTime(oserp.getClientDefaultValue('calendar_day_start'), '07:00:00')
const slotMaxTime = normalizeTime(oserp.getClientDefaultValue('calendar_day_end'),   '19:00:00')
// Business-Hours-Grenzen (ohne Sekunden)
const businessStart = slotMinTime.substring(0, 5)
const businessEnd   = slotMaxTime.substring(0, 5)

const calendarEvents = computed(() => {
    const result = []
    for (const event of props.events) {
        if (event.isHoliday) {
            result.push({
                id: `${event.id}_bg`,
                start: event.dtstart,
                allDay: true,
                display: 'background',
                backgroundColor: 'rgba(229, 57, 53, 0.10)',
                extendedProps: { isHoliday: true }
            })
            result.push({
                id: event.id,
                title: event.title,
                start: event.dtstart,
                allDay: true,
                backgroundColor: '#FFEBEE',
                borderColor: '#E53935',
                textColor: '#B71C1C',
                editable: false,
                classNames: ['fc-event-holiday'],
                extendedProps: { isHoliday: true }
            })
            continue
        }
        const isRecurring = !!event.rrule
        const base = {
            id: String(event.id),
            title: event.title,
            allDay: event.allDay,
            backgroundColor: event.color || '#1976D2',
            borderColor: 'transparent',
            textColor: '#fff',
            extendedProps: {
                description: event.description,
                location: event.location,
                prio: event.prio,
                category_id: event.category_id,
                category_label: event.category_label,
                category_color: event.category_color,
                cvp_id: event.cvp_id,
                cvp_name: event.cvp_name,
                cvp_type: event.cvp_type,
                order_id: event.order_id,
                owner_name: event.owner_name,
                visibility: event.visibility,
                uid: event.uid,
                original: event
            }
        }
        if (isRecurring) {
            // FullCalendar RRule-Plugin: rrule steuert alle Vorkommen
            base.rrule = event.rrule
            // duration nur bei Uhrzeitterminen – ganztägig braucht keine duration
            if (!event.allDay && event.duration) {
                base.duration = event.duration
            }
        } else {
            base.start = event.dtstart
            base.end   = event.dtend
        }
        result.push(base)
    }
    return result
})

// Startdatum: gestern — damit heute an Stelle 2 steht
function getStartDate() {
    const d = new Date()
    d.setDate(d.getDate() - 0)
    const pad = n => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

// Liefert den Montag der ISO-Woche, in der `date` liegt
function getMondayOfWeek(date) {
    const d = new Date(date)
    d.setHours(0, 0, 0, 0)
    const day = d.getDay()
    d.setDate(d.getDate() + (day === 0 ? -6 : 1 - day))
    return d
}

function navigatePrev() {
    const api = calendarRef.value?.getApi()
    if (!api) return
    if (api.view.type === 'timeGridCustomWeek') {
        const monday = getMondayOfWeek(api.view.currentStart)
        monday.setDate(monday.getDate() - 7)
        api.gotoDate(monday)
    } else {
        api.prev()
    }
}

function navigateNext() {
    const api = calendarRef.value?.getApi()
    if (!api) return
    if (api.view.type === 'timeGridCustomWeek') {
        const monday = getMondayOfWeek(api.view.currentStart)
        monday.setDate(monday.getDate() + 7)
        api.gotoDate(monday)
    } else {
        api.next()
    }
}

function navigateToday() {
    const api = calendarRef.value?.getApi()
    if (!api) return
    if (api.view.type === 'timeGridCustomWeek') {
        api.gotoDate(getStartDate())
    } else {
        api.today()
    }
}

const calendarOptions = computed(() => {
    const opts = {
    plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, rrulePlugin],
    initialView: props.initialView,
    initialDate: getStartDate(),
    locale: locale.value === 'de' ? deLocale : undefined,
    headerToolbar: props.headerToolbar || {
        left: 'customPrev,customNext customToday',
        center: 'calendarWeek title clock dayProgress',
        right: 'listCustomWeek,timeGridCustomWeek,timeGridDay,dayGridMonth'
    },
    customButtons: mergedCustomButtons.value,
    events: calendarEvents.value,
    eventClick: handleEventClick,
    dateClick: handleDateClick,
    eventDrop: handleEventDrop,
    eventResize: handleEventResize,
    datesSet: handleDatesSet,
    editable: true,
    selectable: true,
    dayMaxEvents:    props.dayMaxEventRows !== undefined ? props.dayMaxEventRows : 3,
    dayMaxEventRows: props.dayMaxEventRows !== undefined ? props.dayMaxEventRows : undefined,
    moreLinkClick: 'popover',
    hiddenDays: props.hiddenDays,
    height: props.height,
    expandRows: props.expandRows,
    handleWindowResize: true,
    firstDay: 1,
    fixedWeekCount: false,
    showNonCurrentDates: true,
    nowIndicator: true,
    allDaySlot: true,
    allDayText: t('CalendarMain.allDay'),
    slotMinTime,
    slotMaxTime,
    businessHours: {
        daysOfWeek: [1, 2, 3, 4, 5],
        startTime: businessStart,
        endTime: businessEnd
    },
    buttonText: {
        today: t('CalendarMain.today'),
        month: t('CalendarMain.month'),
        week: t('CalendarMain.week'),
        day: t('CalendarMain.day'),
        list: t('CalendarMain.list')
    },
    titleFormat: { year: 'numeric', month: 'long' },
    eventContent: renderEventContent,
    eventResizableFromStart: true,
    views: {
        listCustomWeek: {
            type: 'list',
            duration: { days: 9 },
            buttonText: t('CalendarMain.list'),
            titleFormat: { year: 'numeric', month: 'long', day: 'numeric' },
            displayEventTime: true,
            eventDisplay: 'auto'
        },
        dayGridMonth: {
            eventDisplay: 'block'
        },
        timeGridDay: {
            titleFormat: { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' }
        },
        timeGridCustomWeek: {
            type: 'timeGrid',
            duration: { days: props.weekDuration },
            buttonText: t('CalendarMain.week'),
            titleFormat: { year: 'numeric', month: 'long', day: 'numeric' }
        }
    }
    }
    // contentHeight nur setzen wenn explizit ein Wert uebergeben wurde. null
    // bedeutet: FullCalendar soll ihn aus height ableiten — sonst verhindert ein
    // gesetzter Wert dass expandRows die Slots auf den verfuegbaren Platz dehnt.
    if (props.contentHeight !== null && props.contentHeight !== undefined) {
        opts.contentHeight = props.contentHeight
    }
    return opts
})

function handleEventClick(info) {
    info.jsEvent.preventDefault()
    if (info.event.extendedProps.isHoliday) return
    emit('event-click', info.event.extendedProps.original)
}

function handleDateClick(info) {
    const isAllDay = info.allDay || info.view.type === 'dayGridMonth'
    emit('date-click', {
        dateStr: isAllDay ? info.dateStr.split('T')[0] : info.dateStr,
        allDay:  isAllDay
    })
}

function handleEventDrop(info) {
    emit('event-drop', {
        id: info.event.id,
        start: formatDateTime(info.event.start, info.event.allDay),
        end: info.event.end ? formatDateTime(info.event.end, info.event.allDay) : null,
        allDay: info.event.allDay,
        revert: info.revert
    })
}

function handleEventResize(info) {
    emit('event-resize', {
        id: info.event.id,
        start: formatDateTime(info.event.start, info.event.allDay),
        end: info.event.end ? formatDateTime(info.event.end, info.event.allDay) : null,
        allDay: info.event.allDay,
        revert: info.revert
    })
}

function handleDatesSet(info) {
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const viewStart = new Date(info.start)
    const viewEnd = new Date(info.end)
    // KW immer nach heutigem Tag — wechselt Montag/Sonntag-Grenze (ISO)
    const kwDate = (today >= viewStart && today < viewEnd) ? today : viewStart
    const kw = getISOWeek(kwDate)
    currentKW.value = kw
    nextTick(() => { applyKWColor(kw); applyDayProgress() })
    emit('dates-set', {
        start:      info.startStr.split('T')[0],
        end:        info.endStr.split('T')[0],
        view:       info.view.type,
        viewStart:  formatLocalDate(info.view.currentStart)
    })
}

function renderEventContent(arg) {
    // LxCars-Auslastungsbalken
    if (arg.event.extendedProps?.isWorkload) {
        const { hours, pct, capPct, orderCount, color } = arg.event.extendedProps
        const capLabel = capPct !== null ? `<span class="fc-wl-cap">${capPct}%</span>` : ''
        const ordLabel = orderCount > 0
            ? `<span class="fc-wl-orders">${orderCount} Auftrag${orderCount !== 1 ? 'träge' : ''}</span>`
            : ''
        return { html: `
            <div class="fc-wl-wrap">
                <div class="fc-wl-header">
                    <span class="fc-wl-hours">${hours}h</span>
                    ${capLabel}${ordLabel}
                </div>
                <div class="fc-wl-track">
                    <div class="fc-wl-fill" style="width:${pct}%;background:${color}"></div>
                </div>
            </div>` }
    }

    const desc = arg.event.extendedProps?.description
    const title = arg.event.title || ''
    const time = arg.timeText || ''
    let html = ''
    if (time) html += `<div class="fc-event-time">${time}</div>`
    html += `<div class="fc-event-title">${title}</div>`
    if (desc) html += `<div class="fc-event-desc">${desc}</div>`
    return { html }
}

function formatLocalDate(date) {
    const pad = n => String(n).padStart(2, '0')
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function formatDateTime(date, allDay) {
    if (!date) return null
    const pad = n => String(n).padStart(2, '0')
    // Lokale Datumswerte verwenden — NICHT toISOString() (UTC-Verschiebung!)
    const dateStr = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
    if (allDay) return dateStr
    return `${dateStr} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
}

watch(() => props.events, () => {
    calendarRef.value?.getApi()?.refetchEvents()
}, { deep: true })

onMounted(() => {
    updateClock()
    nextTick(applyDayProgress)
    const msToNextMinute = (60 - new Date().getSeconds()) * 1000
    setTimeout(() => {
        updateClock()
        clockTimer = setInterval(updateClock, 60000)
    }, msToNextMinute)
})

onBeforeUnmount(() => {
    if (clockTimer) { clearInterval(clockTimer); clockTimer = null }
})

defineExpose({
    goToDate: (date) => calendarRef.value?.getApi()?.gotoDate(date),
    setView: (view, date) => {
        const api = calendarRef.value?.getApi()
        if (!api) return
        // changeView(view, date) ist atomar — kein doppeltes datesSet
        api.changeView(view || 'timeGridCustomWeek', date || undefined)
    },
    next: navigateNext,
    prev: navigatePrev,
    today: navigateToday
})
</script>

<style>
.calendar-wrapper {
    padding: 20px;
    background: linear-gradient(180deg, #fafafa 0%, #fff 100%);
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

/* Toolbar-Chunks: Items horizontal nebeneinander. Ohne dieses Flex
   bricht der H2-Titel (display:block) um und stapelt KW/Title/Clock. */
.fc .fc-toolbar-chunk {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
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

/* Calendar Container */
.fc .fc-view-harness {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}

/* Header (Weekdays) */
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

/* Day Cells (Month view) */
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

/* Events */
.fc .fc-daygrid-event {
    border-radius: 3px;
    padding: 4px 8px;
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
}

.fc .fc-event-desc {
    font-weight: 500;
    font-size: 0.95em;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* TimeGrid Events */
.fc .fc-timegrid-event {
    border-radius: 4px;
    border: none;
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
    cursor: pointer;
    transition: all 0.2s ease;
    overflow: visible;
}

.fc .fc-timegrid-event:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.fc .fc-timegrid-event .fc-event-main {
    padding: 2px 6px;
    overflow: visible;
}

.fc .fc-timegrid-event .fc-event-title {
    overflow: visible;
    text-overflow: unset;
    white-space: normal;
    line-height: 1.3;
}

/* TimeGrid Slots */
.fc .fc-timegrid-slot {
    height: 40px;
}

.fc .fc-timegrid-slot-label {
    font-size: 0.75rem;
    color: #757575;
    font-weight: 500;
}

/* Now Indicator */
.fc .fc-timegrid-now-indicator-line {
    border-color: #E53935;
    border-width: 2px;
}

.fc .fc-timegrid-now-indicator-arrow {
    border-color: #E53935;
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

/* Sonntag */
.fc .fc-day-sun {
    background-color: rgba(0,0,0,0.02);
}

/* Non-business hours */
.fc .fc-non-business {
    background-color: rgba(0,0,0,0.02);
}

/* List View */
.fc .fc-list {
    border-radius: 16px;
    overflow: hidden;
}

.fc .fc-list-day-cushion {
    background: linear-gradient(180deg, #f8f9fa 0%, #f0f2f5 100%);
    padding: 10px 16px;
    font-weight: 600;
}

.fc .fc-list-event {
    cursor: pointer;
}

.fc .fc-list-event:hover td {
    background-color: rgba(25, 118, 210, 0.04);
}

.fc .fc-list-event-dot {
    border-radius: 50%;
}

.fc .fc-list-event-time {
    font-size: 0.85rem;
    color: #616161;
    white-space: nowrap;
}

.fc .fc-list-event-title {
    font-weight: 600;
}

/* Liste: Event-Blöcke zurücksetzen — kein Abschneiden, keine Rundung */
.fc .fc-list-event .fc-event {
    border-radius: 0;
    padding: 0;
    margin: 0;
    box-shadow: none;
    background: transparent !important;
    border: none;
}

.fc .fc-list-event .fc-event-title {
    overflow: visible;
    text-overflow: unset;
    white-space: normal;
    line-height: 1.4;
}

.fc .fc-list-event .fc-event-main {
    padding: 0;
}

/* Feiertage */
.fc .fc-event-holiday {
    cursor: default !important;
    font-style: italic;
    font-size: 0.75rem !important;
    padding: 2px 6px !important;
}

.fc .fc-event-holiday:hover {
    transform: none !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
}

/* Responsive */
@media (max-width: 768px) {
    .calendar-wrapper {
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

/* Uhr-Button: gleiche Schriftgröße wie Titel, kein Button-Look */
.fc .fc-clock-button {
    font-size: 1.9rem !important;
    font-weight: 600 !important;
    font-variant-numeric: tabular-nums;
    background: transparent !important;
    color: #424242 !important;
    cursor: default !important;
    pointer-events: none;
    box-shadow: none !important;
    border-radius: 8px;
    padding: 4px 10px !important;
}

.fc .fc-clock-button:hover {
    background: transparent !important;
    transform: none !important;
}

.fc .fc-dayProgress-button,
.fc .fc-dayProgress-button:hover,
.fc .fc-dayProgress-button:focus,
.fc .fc-dayProgress-button:active {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 4px 6px !important;
    cursor: default !important;
    pointer-events: none;
    line-height: 0;
}

/* Kalenderwoche-Button: kein Button-Look, Farbe per JS gesetzt */
.fc .fc-calendarWeek-button {
    font-size: 1.1rem !important;
    font-weight: 700 !important;
    cursor: default !important;
    pointer-events: none;
    border-radius: 8px;
    padding: 4px 10px !important;
    box-shadow: none !important;
}

.fc .fc-calendarWeek-button:hover {
    transform: none !important;
}

/* ── LxCars Auslastungsbalken (Monatsansicht Wandanzeige) ── */
.fc-workload-event {
    pointer-events: none !important;
    cursor: default !important;
    margin: 1px 2px !important;
}
.fc-workload-event .fc-event-main {
    padding: 0 !important;
}
.fc-wl-wrap {
    padding: 3px 5px 4px;
    border-radius: 4px;
    background: rgba(0,0,0,0.04);
}
.fc-wl-header {
    display: flex;
    align-items: baseline;
    gap: 5px;
    margin-bottom: 3px;
    line-height: 1;
}
.fc-wl-hours {
    font-size: 12px;
    font-weight: 800;
    color: #212121;
    letter-spacing: -0.03em;
}
.fc-wl-cap {
    font-size: 10px;
    font-weight: 600;
    color: #616161;
}
.fc-wl-orders {
    font-size: 9px;
    color: #9e9e9e;
    margin-left: auto;
}
.fc-wl-track {
    height: 5px;
    border-radius: 3px;
    background: rgba(0,0,0,0.10);
    overflow: hidden;
}
.fc-wl-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.5s ease;
    min-width: 4px;
}

</style>
