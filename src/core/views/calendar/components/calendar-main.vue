<!-- src/core/views/calendar/components/calendar-main.vue -->
<template>
    <v-card flat class="calendar-wrapper">
        <FullCalendar ref="calendarRef" :options="calendarOptions" />
    </v-card>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/vue3/daygrid'
import timeGridPlugin from '@fullcalendar/vue3/timegrid'
import listPlugin from '@fullcalendar/vue3/list'
import interactionPlugin from '@fullcalendar/vue3/interaction'
import rrulePlugin from '@fullcalendar/rrule'
import { classicThemePlugin, calendarLocale, oserpCalendarHooks } from '@/core/components/calendar/oserp-fullcalendar.js'
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
    weekDuration: { type: Number, default: 7 },
    // Höhe eines Zeitraster-Slots in px (bestimmt, wie viele Stunden ohne Scrollen sichtbar sind)
    slotMinHeight: { type: Number, default: 40 }
})

const emit = defineEmits(['event-click', 'date-click', 'event-drop', 'event-resize', 'dates-set'])

const { t, locale } = useI18n()
const calendarRef = ref(null)
const oserp = oserpStore()

// ── Uhr & Tagesfortschritt ──
const currentTime = ref('')
let clockTimer = null
const workdayConfig = parseWorkdayConfig(k => oserp.getClientDefaultValue(k))
// Tagesfortschritt-SVG als State: FullCalendar rendert den Button über
// iconContent neu, sobald sich der Wert mit der Uhr ändert
const dayProgressSVG = ref('')

function updateClock() {
    currentTime.value = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + ' Uhr'
    dayProgressSVG.value = buildWorkdayProgressSVG(workdayConfig)
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

// Toolbar-Buttons. prev/next/today sind die Standard-Buttons mit eigener
// Navigation (KW-Wochenlogik) — preventDefault unterdrückt die eingebaute.
// KW-Farbe gerade/ungerade über CSS-Klassen (.ofc-kw--even/--odd).
const mergedButtons = computed(() => {
    const svg = dayProgressSVG.value
    return {
        prev:  { iconClass: 'mdi mdi-chevron-left',  click: (ev) => { ev.preventDefault(); navigatePrev() } },
        next:  { iconClass: 'mdi mdi-chevron-right', click: (ev) => { ev.preventDefault(); navigateNext() } },
        today: { text: t('CalendarMain.today'), isPrimary: true, click: (ev) => { ev.preventDefault(); navigateToday() } },
        calendarWeek: {
            text: `${currentKW.value}.KW`,
            hint: 'Kalenderwoche',
            className: currentKW.value % 2 === 0 ? 'ofc-kw ofc-kw--even' : 'ofc-kw ofc-kw--odd'
        },
        clock: {
            text: currentTime.value || '--:--',
            className: 'ofc-clock'
        },
        dayProgress: {
            display: 'icon',
            iconContent: () => ({ html: svg }),
            className: 'ofc-dayprogress'
        },
        listCustomWeek:     { text: t('CalendarMain.list') },
        timeGridCustomWeek: { text: t('CalendarMain.week') },
        timeGridDay:        { text: t('CalendarMain.day') },
        dayGridMonth:       { text: t('CalendarMain.month') },
        ...props.customButtons
    }
})

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
                color: 'rgba(229, 57, 53, 0.10)',
                extendedProps: { isHoliday: true }
            })
            result.push({
                id: event.id,
                title: event.title,
                start: event.dtstart,
                allDay: true,
                color: '#FFEBEE',
                contrastColor: '#B71C1C',
                editable: false,
                className: 'ofc-holiday',
                extendedProps: { isHoliday: true }
            })
            continue
        }
        const isRecurring = !!event.rrule
        const base = {
            id: String(event.id),
            title: event.title,
            allDay: event.allDay,
            color: event.color || '#1976D2',
            contrastColor: '#fff',
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
    ...oserpCalendarHooks,
    plugins: [classicThemePlugin, dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, rrulePlugin],
    initialView: props.initialView,
    initialDate: getStartDate(),
    locale: calendarLocale(locale.value),
    headerToolbar: props.headerToolbar || {
        left: 'prev,next today',
        center: 'calendarWeek title clock dayProgress',
        right: 'listCustomWeek,timeGridCustomWeek,timeGridDay,dayGridMonth'
    },
    buttons: mergedButtons.value,
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
    firstDay: 1,
    fixedWeekCount: false,
    showNonCurrentDates: true,
    nowIndicator: true,
    allDaySlot: true,
    allDayText: t('CalendarMain.allDay'),
    slotMinTime,
    slotMaxTime,
    slotMinHeight: props.slotMinHeight,
    businessHours: {
        daysOfWeek: [1, 2, 3, 4, 5],
        startTime: businessStart,
        endTime: businessEnd
    },
    titleFormat: { year: 'numeric', month: 'long' },
    eventContent: renderEventContent,
    eventResizableFromStart: true,
    // Uhrzeit-Termine im Monatsraster als farbige Blöcke statt Punkt-Liste
    eventDisplay: 'block',
    views: {
        listCustomWeek: {
            type: 'list',
            duration: { days: 9 },
            titleFormat: { year: 'numeric', month: 'long', day: 'numeric' },
            displayEventTime: true
        },
        timeGridDay: {
            titleFormat: { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' }
        },
        timeGridCustomWeek: {
            type: 'timeGrid',
            duration: { days: props.weekDuration },
            titleFormat: { year: 'numeric', month: 'long', day: 'numeric' }
        },
        // Ganztags-Zeile aller Zeitraster-Ansichten (Wandanzeige begrenzt sie per CSS)
        timeGrid: {
            dayRowClass: 'ofc-allday-row'
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
    currentKW.value = getISOWeek(kwDate)
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
        const capLabel = capPct !== null ? `<span class="ofc-wl-cap">${capPct}%</span>` : ''
        const ordLabel = orderCount > 0
            ? `<span class="ofc-wl-orders">${orderCount} Auftrag${orderCount !== 1 ? 'träge' : ''}</span>`
            : ''
        return { html: `
            <div class="ofc-wl-wrap">
                <div class="ofc-wl-header">
                    <span class="ofc-wl-hours">${hours}h</span>
                    ${capLabel}${ordLabel}
                </div>
                <div class="ofc-wl-track">
                    <div class="ofc-wl-fill" style="width:${pct}%;background:${color}"></div>
                </div>
            </div>` }
    }

    const desc = arg.event.extendedProps?.description
    const title = arg.event.title || ''
    const time = arg.timeText || ''
    // Ein Block-Container: das Theme legt mehrere Kinder sonst als Flex-Zeile nebeneinander
    let html = '<div class="ofc-ev">'
    if (time) html += `<div class="ofc-ev-time">${time}</div>`
    html += `<div class="ofc-ev-title">${title}</div>`
    if (desc) html += `<div class="ofc-ev-desc">${desc}</div>`
    html += '</div>'
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

/* Basis-Optik: src/core/components/calendar/oserp-fullcalendar.css */

/* Termin-Inhalt aus renderEventContent */
.oserp-cal .ofc-ev {
    min-width: 0;
    overflow-wrap: normal;
    word-break: normal;
}

.oserp-cal .ofc-ev-title {
    font-weight: 600;
}

.oserp-cal .ofc-ev-desc {
    font-weight: 500;
    font-size: 0.95em;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.oserp-cal .ofc-col-event .ofc-ev-title,
.oserp-cal .ofc-list-event .ofc-ev-title {
    white-space: normal;
    line-height: 1.3;
}

/* Feiertage */
.oserp-cal .ofc-holiday,
.oserp-cal .ofc-holiday:hover {
    cursor: default;
    font-style: italic;
    font-size: 0.75rem;
    padding: 2px 6px;
    transform: none;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

/* Uhr: Titelgröße, kein Button-Look */
.oserp-cal .ofc-clock,
.oserp-cal .ofc-clock:hover {
    font-size: 1.9rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    padding: 4px 10px;
    border-radius: 8px;
    background: transparent;
    color: #424242;
    box-shadow: none;
    transform: none;
    cursor: default;
    pointer-events: none;
}

/* Tagesfortschritt: nur das SVG */
.oserp-cal .ofc-dayprogress,
.oserp-cal .ofc-dayprogress:hover {
    padding: 4px 6px;
    line-height: 0;
    border: none;
    background: transparent;
    box-shadow: none;
    transform: none;
    cursor: default;
    pointer-events: none;
}

/* Kalenderwoche: gerade blau, ungerade grün */
.oserp-cal .ofc-kw,
.oserp-cal .ofc-kw:hover {
    font-size: 1.1rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 8px;
    box-shadow: none;
    transform: none;
    cursor: default;
    pointer-events: none;
}

.oserp-cal .ofc-kw--even {
    color: #1565c0;
    background: #e3f2fd;
}

.oserp-cal .ofc-kw--odd {
    color: #2e7d32;
    background: #e8f5e9;
}

/* ── LxCars Auslastungsbalken (Monatsansicht Wandanzeige) ── */
.oserp-cal .ofc-workload-event {
    pointer-events: none;
    cursor: default;
    margin: 1px 2px;
}

.oserp-cal .ofc-workload-event .ofc-event-inner {
    padding: 0;
}

.ofc-wl-wrap {
    padding: 3px 5px 4px;
    border-radius: 4px;
    background: rgba(0, 0, 0, 0.04);
}

.ofc-wl-header {
    display: flex;
    align-items: baseline;
    gap: 5px;
    margin-bottom: 3px;
    line-height: 1;
}

.ofc-wl-hours {
    font-size: 12px;
    font-weight: 800;
    color: #212121;
    letter-spacing: -0.03em;
}

.ofc-wl-cap {
    font-size: 10px;
    font-weight: 600;
    color: #616161;
}

.ofc-wl-orders {
    font-size: 9px;
    color: #9e9e9e;
    margin-left: auto;
}

.ofc-wl-track {
    height: 5px;
    border-radius: 3px;
    background: rgba(0, 0, 0, 0.10);
    overflow: hidden;
}

.ofc-wl-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.5s ease;
    min-width: 4px;
}
</style>
