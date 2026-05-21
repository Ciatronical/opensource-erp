<!-- src/core/views/wall-display/wall-display.view.vue -->

<template>
    <div class="wall-display" :class="[
        { 'wall-display--faktura': mode === 'faktura' },
        `wall-display--size-${effectiveSize}`
    ]">

        <div v-if="!sseConnected" class="wall-display__no-sse" />

        <!-- Topbar nur in Faktura-Modus — im Kalender-Modus sitzen Exit/Uhr in der FullCalendar-Toolbar -->
        <div v-if="mode !== 'calendar'" class="wall-display__topbar">
            <v-btn class="wall-display__exit" size="small" variant="tonal" color="error" @click="exitWallDisplay">
                <v-icon start size="small">mdi-exit-to-app</v-icon>
                {{ t('WallDisplay.exit') }}
            </v-btn>
            <div class="wall-display__clock-area">
                <span class="wall-display__clock">{{ currentTime }}</span>
                <span class="wall-display__progress" v-html="dayProgressSVG"></span>
            </div>
        </div>


        <!-- Kalender-Modus -->
        <div v-if="mode === 'calendar'" class="wall-display__calendar">
            <calendar-main
                ref="calendarMainRef"
                :events="events"
                :initial-view="calendarInitialView"
                :custom-buttons="calendarCustomButtons"
                :header-toolbar="calendarHeaderToolbar"
                :day-max-event-rows="false"
                :hidden-days="[0]"
                :week-duration="8"
                @dates-set="onDatesSet"
                @event-click="onEventClick"
            />
        </div>

        <!-- Faktura-Modus: Auftrag/Rechnung als Kunden-Präsentation -->
        <div v-else-if="mode === 'faktura'" class="wall-display__faktura">
            <div v-if="!fakturaData" class="d-flex align-center justify-center fill-height">
                <div class="text-center text-medium-emphasis">
                    <v-icon size="80" color="grey-lighten-1" class="mb-4">mdi-file-document-outline</v-icon>
                    <div class="text-h6">{{ t('WallDisplay.noFaktura') }}</div>
                    <div class="text-body-2 mt-1">{{ t('WallDisplay.noFakturaHint') }}</div>
                </div>
            </div>
            <template v-else>
                <!-- Kopfbereich -->
                <v-card flat class="wall-faktura__header mb-4">
                    <v-card-text class="pa-6">
                        <div class="d-flex align-center justify-space-between">
                            <div>
                                <div class="text-h4 font-weight-bold">{{ fakturaData.customer?.name || '–' }}</div>
                                <div class="text-body-1 text-medium-emphasis mt-1">
                                    {{ fakturaDocLabel }} {{ fakturaDocNumber }}
                                    <span v-if="fakturaData.common?.transdate" class="ml-4">{{ formatDate(fakturaData.common.transdate) }}</span>
                                </div>
                            </div>
                            <v-chip :color="fakturaData.common?.closed ? 'grey' : 'success'" size="large" variant="elevated">
                                {{ fakturaData.common?.closed ? t('WallDisplay.closed') : t('WallDisplay.open') }}
                            </v-chip>
                        </div>
                    </v-card-text>
                </v-card>

                <!-- Fahrzeug (wenn vorhanden) -->
                <v-card v-if="fakturaVehicle" flat class="mb-4">
                    <v-card-text class="pa-4 d-flex align-center ga-4">
                        <v-icon size="large" color="primary">mdi-car</v-icon>
                        <div>
                            <span class="text-h6 font-weight-bold">{{ fakturaVehicle.c_ln }}</span>
                            <span v-if="fakturaVehicle.kba_hersteller || fakturaVehicle.kba_d2" class="text-body-1 ml-3 text-medium-emphasis">
                                {{ fakturaVehicle.kba_hersteller }} {{ fakturaVehicle.kba_d2 }}
                            </span>
                        </div>
                    </v-card-text>
                </v-card>

                <!-- Positionen -->
                <v-card flat>
                    <v-table class="wall-faktura__table">
                        <thead>
                            <tr>
                                <th class="text-left">{{ t('WallDisplay.position') }}</th>
                                <th class="text-left" style="width: 55%">{{ t('WallDisplay.description') }}</th>
                                <th class="text-right">{{ t('WallDisplay.qty') }}</th>
                                <th class="text-right">{{ t('WallDisplay.price') }}</th>
                                <th class="text-right">{{ t('WallDisplay.total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in fakturaPositions" :key="item.id">
                                <td>{{ item.position }}</td>
                                <td>{{ item.description }}</td>
                                <td class="text-right">{{ formatQty(item.qty) }} {{ item.unit }}</td>
                                <td class="text-right">{{ formatCurrency(item.sellprice) }}</td>
                                <td class="text-right font-weight-bold">{{ formatCurrency(item.qty * item.sellprice * (1 - (item.discount || 0))) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="wall-faktura__total">
                                <td colspan="4" class="text-right font-weight-bold text-h6">{{ t('WallDisplay.grossTotal') }}</td>
                                <td class="text-right font-weight-bold text-h6">{{ formatCurrency(fakturaData.common?.amount || 0) }}</td>
                            </tr>
                        </tfoot>
                    </v-table>
                </v-card>
            </template>
        </div>

        <!-- Event-Detail Overlay (Kalender) -->
        <v-dialog v-model="eventDetailOpen" max-width="500">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4">
                    <v-icon class="mr-2" :color="selectedEvent?.color || 'primary'">mdi-calendar</v-icon>
                    {{ selectedEvent?.title }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="eventDetailOpen = false" />
                </v-card-title>
                <v-card-text v-if="selectedEvent">
                    <div v-if="selectedEvent.description" class="mb-2">{{ selectedEvent.description }}</div>
                    <div v-if="selectedEvent.location" class="d-flex align-center mb-1">
                        <v-icon size="small" class="mr-2">mdi-map-marker</v-icon>
                        {{ selectedEvent.location }}
                    </div>
                    <div class="d-flex align-center mb-1">
                        <v-icon size="small" class="mr-2">mdi-clock</v-icon>
                        {{ formatEventTime(selectedEvent) }}
                    </div>
                    <div v-if="selectedEvent.owner_name" class="d-flex align-center">
                        <v-icon size="small" class="mr-2">mdi-account</v-icon>
                        {{ selectedEvent.owner_name }}
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import { defineComponent, ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import CalendarMain from '@/core/views/calendar/components/calendar-main.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { fakturaStore } from '@/core/stores/faktura.store.js'
import { buildWorkdayProgressSVG, parseWorkdayConfig } from '@/core/utils/workdayProgress.js'

export default defineComponent({
    name: 'WallDisplayView',
    components: { CalendarMain },
    setup() {
        const { t } = useI18n()
        const route = useRoute()
        const router = useRouter()
        const oserp = oserpStore()
        const faktura = fakturaStore()

        const mode = ref(route.query.mode || 'calendar')
        const events = ref([])
        const currentDateRange = ref({ start: '', end: '' })
        const savedCalendarView = oserp.getConfigValue('wall_display_calendar_view', 'timeGridDay')
        const calendarInitialView = ref(savedCalendarView || 'timeGridDay')
        const calendarMainRef = ref(null)
        const wallCalendarView = ref(calendarInitialView.value)  // aktuell angezeigte Ansicht

        // Kalender: Event-Detail
        const eventDetailOpen = ref(false)
        const selectedEvent = ref(null)

        // Faktura
        const fakturaData = ref(null)
        const fakturaVehicle = ref(null)

        // Uhr & Tagesfortschritt
        const currentTime = ref('')
        const dayProgressSVG = ref('')
        let clockTimer = null
        const workdayConfig = parseWorkdayConfig(k => oserp.getClientDefaultValue(k))
        function updateClock() {
            currentTime.value = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) + ' Uhr'
            dayProgressSVG.value = buildWorkdayProgressSVG(workdayConfig, 200, 10)
        }

        // Display-Groesse: aus Employee-Config (Override) oder Client-Default,
        // 'auto' = anhand Viewport bestimmen (Landscape -> compact, Portrait -> large).
        const viewportWidth = ref(window.innerWidth)
        const viewportHeight = ref(window.innerHeight)
        function onViewportResize() {
            viewportWidth.value = window.innerWidth
            viewportHeight.value = window.innerHeight
        }

        const configuredSize = computed(() => {
            return oserp.getConfigValue('wall_display_size', null)
                || oserp.getClientDefaultValue('wall_display_size', 'auto')
        })

        const effectiveSize = computed(() => {
            const cfg = configuredSize.value
            if (cfg !== 'auto') return cfg
            return viewportWidth.value >= viewportHeight.value ? 'compact' : 'large'
        })

        // FullCalendar-Toolbar fuer den Wand-Display-Kalender:
        // Exit-Button rechts vom Heute-Button, Uhr direkt hinter dem Titel.
        function exitWallDisplay() {
            router.push({ name: 'customer-vendor' })
        }

        const calendarCustomButtons = computed(() => ({
            exit: {
                text: '\u2715',
                click: exitWallDisplay
            }
            // clock wird von calendar-main intern verwaltet
        }))

        const calendarHeaderToolbar = {
            left: 'customPrev,customNext customToday exit',
            center: 'calendarWeek title clock dayProgress',
            right: 'listCustomWeek,timeGridCustomWeek,timeGridDay,dayGridMonth'
        }

        const fakturaDocNumber = computed(() => {
            const c = fakturaData.value?.common
            if (!c) return ''
            return c.invnumber || c.ordnumber || c.quonumber || c.donumber || ''
        })

        const fakturaDocLabel = computed(() => {
            const c = fakturaData.value?.common
            if (!c) return ''
            if (c.invnumber) return t('WallDisplay.invoice')
            if (c.ordnumber) return t('WallDisplay.order')
            if (c.quonumber) return t('WallDisplay.quotation')
            return ''
        })

        const fakturaPositions = computed(() => {
            return (fakturaData.value?.positions || []).filter(p => p.parts_id)
        })

        // ── Kalender ──

        // Mehrtägige Ganztags-Events in Einzeltage aufteilen (für Flex-Layout in der Wandanzeige)
        function expandMultiDayAllDay(rawEvents) {
            const result = []
            for (const event of rawEvents) {
                if (event.extendedProps?.isWorkload || !event.allDay || !event.dtend || event.rrule) {
                    result.push(event)
                    continue
                }
                const start = event.dtstart.slice(0, 10)
                const end   = event.dtend.slice(0, 10)
                if (end <= start) {
                    result.push(event)
                    continue
                }
                // Expand: ein Event-Objekt pro Tag
                const cur = new Date(start + 'T00:00:00')
                const endDt = new Date(end   + 'T00:00:00')
                let i = 0
                while (cur < endDt) {
                    const dateStr = cur.toISOString().slice(0, 10)
                    result.push({ ...event, id: `${event.id}_${i}`, dtstart: dateStr, dtend: null })
                    cur.setDate(cur.getDate() + 1)
                    i++
                }
            }
            return result
        }

        // Feiertage für die Wandanzeige laden (Monatsansicht)
        const wallHolidays = ref([])
        const loadedWallHolidayYears = new Set()
        async function loadWallHolidays(start, end) {
            const startYear = parseInt(start.substring(0, 4))
            const endYear   = parseInt(end.substring(0, 4))
            for (let y = startYear; y <= endYear; y++) {
                if (loadedWallHolidayYears.has(y)) continue
                loadedWallHolidayYears.add(y)
                try {
                    const res = await axios.post('/api/calendar/', { action: 'getPublicHolidays', year: y })
                    if (res.data.success) {
                        const fetched = (res.data.payload?.holidays || []).map(h => ({
                            id: `wh_${h.date}`, title: h.name, dtstart: h.date,
                            dtend: null, allDay: true, isHoliday: true
                        }))
                        wallHolidays.value = [...wallHolidays.value, ...fetched]
                    }
                } catch { loadedWallHolidayYears.delete(y) }
            }
        }

        // LxCars-Auslastung als FullCalendar-Events aufbereiten
        function buildWorkloadEvents(workload, capacityMinutes) {
            if (!workload.length) return []
            const maxMin = Math.max(...workload.map(d => d.total_minutes), 1)
            return workload.map(day => {
                const min  = day.total_minutes
                const hrs  = (min / 60).toFixed(1)
                const pct  = Math.round((min / maxMin) * 100)
                const cap  = capacityMinutes > 0 ? Math.round((min / capacityMinutes) * 100) : null
                // Farbe nach absoluter Stundenzahl
                const color = min > 480 ? '#e53935'
                            : min > 300 ? '#ff9800'
                            : min > 120 ? '#66bb6a'
                            :             '#90a4ae'
                return {
                    id:    `wl_${day.work_date}`,
                    start: day.work_date,
                    allDay: true,
                    display: 'block',
                    classNames: ['fc-workload-event'],
                    backgroundColor: 'transparent',
                    borderColor:     'transparent',
                    textColor:       '#333',
                    editable: false,
                    extendedProps: {
                        isWorkload:   true,
                        minutes:      min,
                        hours:        hrs,
                        pct,
                        capPct:       cap,
                        orderCount:   day.order_count,
                        color
                    }
                }
            })
        }

        async function loadMonthWorkload() {
            if (!oserp.isFeatureEnabled('lxcars')) return []
            try {
                const res = await axios.post('/api/calendar/', {
                    action:    'getMonthWorkload',
                    startDate: currentDateRange.value.start,
                    endDate:   currentDateRange.value.end
                })
                if (res.data.success) {
                    return buildWorkloadEvents(
                        res.data.payload?.workload || [],
                        res.data.payload?.capacity_minutes || 0
                    )
                }
            } catch { /* LxCars nicht verfügbar */ }
            return []
        }

        async function loadEvents() {
            if (!currentDateRange.value.start) return

            // Feiertage immer laden (alle Ansichten)
            await loadWallHolidays(currentDateRange.value.start, currentDateRange.value.end)

            // Monatsansicht: nur Feiertage + LxCars-Auslastung, keine persönlichen Termine
            if (wallCalendarView.value === 'dayGridMonth') {
                const workloadEvents = await loadMonthWorkload()
                events.value = [...wallHolidays.value, ...workloadEvents]
                return
            }

            // Alle anderen Ansichten: persönliche Termine + Feiertage
            try {
                const response = await axios.post('/api/calendar/', {
                    action: 'getCalendarEvents',
                    startDate: currentDateRange.value.start,
                    endDate: currentDateRange.value.end
                })
                if (response.data.success) {
                    const calEvents = expandMultiDayAllDay(response.data.payload?.events || [])
                    events.value = [...wallHolidays.value, ...calEvents]
                }
            } catch (e) {
                console.error('Wall display: calendar load error', e)
            }
        }

        function onDatesSet(range) {
            currentDateRange.value = range
            if (range.view) wallCalendarView.value = range.view   // immer aktuell halten
            loadEvents()
            if (range.view && range.view !== calendarInitialView.value) {
                calendarInitialView.value = range.view
                oserp.setConfigValue('wall_display_calendar_view', range.view)
            }
        }

        function onEventClick(event) {
            selectedEvent.value = event
            eventDetailOpen.value = true
        }

        // ── Faktura ──

        async function loadFaktura(id, type) {
            try {
                await faktura.fetchFakturaData(id, type)
                if (faktura.data) {
                    fakturaData.value = faktura.data

                    // Fahrzeug-Info aus oe_ext extrahieren (wenn vorhanden)
                    const vehicle = faktura.data.vehicle || faktura.data.oe_ext
                    if (vehicle?.c_ln) {
                        fakturaVehicle.value = vehicle
                    }
                }
            } catch (e) {
                console.error('Wall display: faktura load error', e)
            }
        }

        // Faktura aus Query-Parameter laden (?faktura=order:12345)
        watch(() => route.query.faktura, (val) => {
            if (!val) { fakturaData.value = null; return }
            const [type, id] = val.split(':')
            if (type && id) {
                mode.value = 'faktura'
                loadFaktura(Number(id), type)
            }
        }, { immediate: true })

        // ── SSE mit Polling-Fallback ──

        let sseSource = null
        let pollInterval = null
        const sseConnected = ref(false)

        // Build-ID beim Start merken — bei SSE-Reconnect und im Polling prüfen,
        // damit ein verpasstes build_changed-Event (SSE war kurz weg) trotzdem
        // einen Reload auslöst.
        let knownBuildId = ''

        async function fetchBuildId() {
            try {
                const res = await fetch('/build-id.txt?_=' + Date.now())
                if (res.ok) return (await res.text()).trim()
            } catch { /* ignorieren */ }
            return ''
        }

        async function checkBuildAndReload() {
            if (!knownBuildId) return
            const current = await fetchBuildId()
            if (current && current !== knownBuildId) window.location.reload()
        }

        function connectSSE() {
            if (typeof EventSource === 'undefined') {
                startPolling()
                return
            }
            sseSource = new EventSource('/sse/events')
            sseSource.onopen = () => {
                sseConnected.value = true
                stopPolling()
                checkBuildAndReload()
            }
            sseSource.onmessage = (event) => {
                sseConnected.value = true
                try {
                    const data = JSON.parse(event.data)
                    // Kalender-Events
                    if (data.action !== undefined && data.uid !== undefined) {
                        loadEvents()
                    }
                    // Faktura-Events
                    if (mode.value === 'faktura' && fakturaData.value) {
                        const fakturaTables = ['oe', 'ar', 'orderitems', 'invoice']
                        if (fakturaTables.includes(data.table) && Number(data.id) === Number(fakturaData.value.common?.id)) {
                            const qParts = (route.query.faktura || '').split(':')
                            if (qParts[1]) loadFaktura(Number(qParts[1]), qParts[0])
                        }
                    }
                } catch { /* ignorieren */ }
            }
            sseSource.addEventListener('build_changed', () => {
                window.location.reload()
            })
            sseSource.addEventListener('wall_display_command', (e) => {
                try {
                    const { view, startDate } = JSON.parse(e.data)
                    wallCalendarView.value = view
                    nextTick(() => calendarMainRef.value?.setView(view, startDate))
                    loadEvents()
                } catch { /* ignorieren */ }
            })
            sseSource.onerror = () => {
                sseConnected.value = false
                startPolling()
            }
        }

        function startPolling() {
            if (pollInterval) return
            pollInterval = setInterval(() => {
                checkBuildAndReload()
                loadEvents()
            }, 60000)
        }

        function stopPolling() {
            if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
        }

        // ── Formatierung ──

        function formatDate(d) {
            if (!d) return ''
            const parts = d.split('-')
            if (parts.length === 3) return `${parts[2]}.${parts[1]}.${parts[0]}`
            return d
        }

        function formatCurrency(val) {
            return Number(val || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'
        }

        function formatQty(val) {
            const n = Number(val || 0)
            return n % 1 === 0 ? String(n) : n.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        }

        function formatEventTime(event) {
            if (!event?.dtstart) return ''
            if (event.allDay) return t('WallDisplay.allDay')
            const start = new Date(event.dtstart)
            const end = event.dtend ? new Date(event.dtend) : null
            const fmt = d => d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
            return end ? `${fmt(start)} – ${fmt(end)}` : fmt(start)
        }

        // ── Lifecycle ──

        onMounted(async () => {
            knownBuildId = await fetchBuildId()
            connectSSE()
            updateClock()
            // An volle Minute synchronisieren, danach minuetlich
            const msToNextMinute = (60 - new Date().getSeconds()) * 1000
            setTimeout(() => {
                updateClock()
                clockTimer = setInterval(updateClock, 60000)
            }, msToNextMinute)

            window.addEventListener('resize', onViewportResize)

            // FullCalendar misst beim Initial-Render gelegentlich falsch, wenn
            // der Container noch nicht seine endgueltige Groesse hat (Topbar
            // schiebt nach, Fonts laden nach). Resize ausloesen, sobald Fonts
            // bereit sind, sowie als Fallback nach kurzer Verzoegerung.
            const triggerResize = () => window.dispatchEvent(new Event('resize'))
            if (document.fonts?.ready) document.fonts.ready.then(triggerResize)
            setTimeout(triggerResize, 150)
        })

        onBeforeUnmount(() => {
            if (sseSource) { sseSource.close(); sseSource = null }
            stopPolling()
            if (clockTimer) { clearInterval(clockTimer); clockTimer = null }
            window.removeEventListener('resize', onViewportResize)
        })

        return {
            t, mode, events, calendarInitialView, calendarMainRef,
            eventDetailOpen, selectedEvent,
            fakturaData, fakturaVehicle, fakturaDocNumber, fakturaDocLabel, fakturaPositions,
            currentTime, calendarCustomButtons, calendarHeaderToolbar, effectiveSize,
            sseConnected,
            onDatesSet, onEventClick, exitWallDisplay,
            formatDate, formatCurrency, formatQty, formatEventTime
        }
    }
})
</script>

<style scoped>
.wall-display {
    position: fixed;
    inset: 0;
    overflow: hidden;
    background: #f5f5f5;
    display: flex;
    flex-direction: column;
    z-index: 1000;
}

.wall-display__topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 16px;
    background: white;
    border-bottom: 1px solid #e0e0e0;
    flex-shrink: 0;
}

.wall-display__clock-area {
    display: flex;
    align-items: center;
    gap: 14px;
}

.wall-display__clock {
    font-size: 1.6rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: #424242;
}

.wall-display__progress {
    display: flex;
    align-items: center;
}


.wall-display__calendar {
    flex: 1;
    min-height: 0;
    height: 100%;
    overflow: hidden;
}

.wall-display__calendar :deep(.calendar-wrapper) {
    padding: 4px;
}

/* Toolbar-Chunks als Flex layouten, damit Title und Uhr nebeneinander stehen */
.wall-display__calendar :deep(.fc .fc-toolbar-chunk) {
    display: flex;
    align-items: center;
}

.wall-display__calendar :deep(.fc .fc-toolbar-chunk > :not(:first-child)) {
    margin-left: 0 !important;
}

/* Uhr in der FullCalendar-Toolbar — als Text statt Button darstellen */
.wall-display__calendar :deep(.fc-clock-button),
.wall-display__calendar :deep(.fc-clock-button:hover),
.wall-display__calendar :deep(.fc-clock-button:focus),
.wall-display__calendar :deep(.fc-clock-button:active) {
    background: transparent !important;
    color: #424242 !important;
    box-shadow: none !important;
    transform: none !important;
    cursor: default;
    pointer-events: none;
    font-size: 1.8rem !important;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    padding: 0 0 0 16px !important;
    border: none !important;
}

/* Exit-Button rechts vom Heute-Button — kompakt, rot, nur Icon */
.wall-display__calendar :deep(.fc-exit-button),
.wall-display__calendar :deep(.fc-exit-button:hover),
.wall-display__calendar :deep(.fc-exit-button:focus),
.wall-display__calendar :deep(.fc-exit-button:active) {
    background: rgba(240,240,240,0.18) !important;
    color: rgba(66,66,66,0.35) !important;
    margin-left: 8px !important;
    padding: 8px 14px !important;
    font-size: 1.1rem !important;
    line-height: 1 !important;
    box-shadow: none !important;
    transform: none !important;
}

/* Groessen-Modi sind im zweiten, globalen <style>-Block am Dateiende — siehe unten. */

/* Roter Punkt: kein SSE */
.wall-display__no-sse {
    position: fixed;
    top: 6px;
    right: 6px;
    z-index: 2000;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #f44336;
    pointer-events: none;
}

/* Faktura-Modus */
.wall-display__faktura {
    flex: 1;
    overflow: auto;
    padding: 24px;
}

.wall-faktura__header {
    background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
    color: white;
    border-radius: 12px;
}

.wall-faktura__header .v-card-text {
    color: white;
}

.wall-faktura__header .text-medium-emphasis {
    color: rgba(255, 255, 255, 0.8) !important;
}

.wall-faktura__table {
    font-size: 1.1rem;
}

.wall-faktura__table th {
    font-size: 0.95rem !important;
    font-weight: 700 !important;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 16px !important;
}

.wall-faktura__table td {
    padding: 14px 16px !important;
}

.wall-faktura__total td {
    border-top: 2px solid #1976d2;
    padding-top: 20px !important;
}
</style>

<!-- Globale Groessen-Regeln: NICHT scoped, damit sie ueber Vue-Component-Grenzen
     hinweg auf die FullCalendar-DOM zugreifen koennen, die im calendar-main
     gerendert wird. !important schlaegt die Default-Regeln aus calendar-main.vue. -->
<style>
/* Ganztags-Zeile: maximal 4em hoch */
.wall-display .fc-timegrid .fc-daygrid-body {
    max-height: 4em;
    overflow: hidden;
}

/* 1–2 Termine: normales vertikales Stacking, kompakter */
.wall-display .fc-timegrid .fc-daygrid-event {
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    margin-bottom: 1px !important;
    font-size: 0.82em;
    line-height: 1.4;
}

/* Alle Ganztagstermine nebeneinander mit gleicher Breite */
.wall-display .fc-timegrid .fc-daygrid-day-events {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    align-items: stretch !important;
    gap: 2px !important;
    padding: 1px !important;
}
.wall-display .fc-timegrid .fc-daygrid-event-harness {
    position: relative !important;
    top: auto !important;
    left: auto !important;
    right: auto !important;
    flex: 1 1 0 !important;
    min-width: 0 !important;
    margin: 0 !important;
}
.wall-display .fc-timegrid .fc-h-event {
    height: 100% !important;
}

/* Schmaler Separator nach Samstag (Wochenende-/Wochenanfang-Trenner) */
.wall-display .fc .fc-col-header-cell.fc-day-sat,
.wall-display .fc .fc-timegrid-col.fc-day-sat,
.wall-display .fc .fc-daygrid-day.fc-day-sat {
    border-right: 3px solid #90a4ae !important;
}

/* compact: TV / Querformat — minimaler Platzbedarf */
.wall-display--size-compact .calendar-wrapper {
    padding: 4px !important;
}
.wall-display--size-compact .fc .fc-toolbar {
    margin-bottom: 4px !important;
    padding: 4px 10px !important;
    border-radius: 6px !important;
    gap: 4px !important;
}
.wall-display--size-compact .fc .fc-toolbar-title {
    font-size: 0.95rem !important;
}
.wall-display--size-compact .fc .fc-button {
    padding: 3px 8px !important;
    font-size: 0.7rem !important;
    border-radius: 5px !important;
}
.wall-display--size-compact .fc .fc-col-header-cell {
    padding: 3px 4px !important;
    font-size: 0.7rem !important;
}
.wall-display--size-compact .fc .fc-timegrid-slot-label {
    font-size: 0.65rem !important;
}
.wall-display--size-compact .fc .fc-timegrid-slot {
    height: 16px !important;
}
.wall-display--size-compact .fc .fc-event-title,
.wall-display--size-compact .fc .fc-event-time {
    font-size: 0.7rem !important;
}
.wall-display--size-compact .fc .fc-clock-button,
.wall-display--size-compact .fc .fc-clock-button:hover,
.wall-display--size-compact .fc .fc-clock-button:focus,
.wall-display--size-compact .fc .fc-clock-button:active {
    font-size: 1.1rem !important;
    padding: 0 0 0 12px !important;
}
.wall-display--size-compact .fc .fc-calendarWeek-button {
    font-size: 0.95rem !important;
    padding: 4px 8px !important;
}
.wall-display--size-compact .fc .fc-exit-button,
.wall-display--size-compact .fc .fc-exit-button:hover {
    padding: 3px 8px !important;
    font-size: 0.85rem !important;
    margin-left: 6px !important;
}

/* normal: nahe an FullCalendar-Defaults */
.wall-display--size-normal .fc .fc-toolbar {
    padding: 10px 14px !important;
    margin-bottom: 12px !important;
}
.wall-display--size-normal .fc .fc-toolbar-title {
    font-size: 1.25rem !important;
}
.wall-display--size-normal .fc .fc-button {
    padding: 8px 14px !important;
    font-size: 0.85rem !important;
}
.wall-display--size-normal .fc .fc-calendarWeek-button {
    font-size: 1.25rem !important;
    padding: 4px 10px !important;
}

/* Slot-Höhe bestimmt wie viele Stunden sichtbar sind (ohne Scrollen) */
.wall-display--size-normal .fc .fc-timegrid-slot {
    height: 36px !important;
}

/* qm50c: Samsung QM50C — 50 Zoll 4K, Querformat, Abstand ~3–5 m */
.wall-display--size-qm50c .fc .fc-toolbar {
    padding: 12px 18px !important;
    margin-bottom: 14px !important;
}
.wall-display--size-qm50c .fc .fc-toolbar-title {
    font-size: 1.6rem !important;
}
.wall-display--size-qm50c .fc .fc-button {
    padding: 10px 18px !important;
    font-size: 1rem !important;
}
.wall-display--size-qm50c .fc .fc-col-header-cell {
    padding: 6px 4px !important;
    font-size: 0.95rem !important;
}
.wall-display--size-qm50c .fc .fc-timegrid-slot-label {
    font-size: 0.9rem !important;
}
/* Slot-Höhe: Legt die sichtbare Höhe des Kalenders fest (ohne Scrollen) */
.wall-display--size-qm50c .fc .fc-timegrid-slot {
    height: 35.8px !important;
}
.wall-display--size-qm50c .fc .fc-event-title,
.wall-display--size-qm50c .fc .fc-event-time {
    font-size: 0.95rem !important;
}
.wall-display--size-qm50c .fc .fc-clock-button,
.wall-display--size-qm50c .fc .fc-clock-button:hover,
.wall-display--size-qm50c .fc .fc-clock-button:focus,
.wall-display--size-qm50c .fc .fc-clock-button:active {
    font-size: 1.8rem !important;
    padding: 0 0 0 20px !important;
}
.wall-display--size-qm50c .fc .fc-calendarWeek-button {
    font-size: 1.6rem !important;
    padding: 5px 12px !important;
}
.wall-display--size-qm50c .fc .fc-exit-button,
.wall-display--size-qm50c .fc .fc-exit-button:hover {
    padding: 10px 18px !important;
    font-size: 1.1rem !important;
    margin-left: 8px !important;
}

/* large: Hochformat-Wandtablet — bewusst gross */
.wall-display--size-large .fc {
    font-size: 1.15rem !important;
}
.wall-display--size-large .fc .fc-toolbar {
    padding: 18px 22px !important;
    margin-bottom: 20px !important;
}
.wall-display--size-large .fc .fc-toolbar-title {
    font-size: 2rem !important;
}
.wall-display--size-large .fc .fc-button {
    padding: 14px 22px !important;
    font-size: 1.1rem !important;
}
.wall-display--size-large .fc .fc-col-header-cell {
    padding: 16px 4px !important;
    font-size: 1.05rem !important;
}
.wall-display--size-large .fc .fc-timegrid-slot-label {
    font-size: 1rem !important;
}
.wall-display--size-large .fc .fc-event-title,
.wall-display--size-large .fc .fc-event-time {
    font-size: 1.05rem !important;
}
.wall-display--size-large .fc .fc-clock-button,
.wall-display--size-large .fc .fc-clock-button:hover,
.wall-display--size-large .fc .fc-clock-button:focus,
.wall-display--size-large .fc .fc-clock-button:active {
    font-size: 2.9rem !important;
    padding: 0 0 0 24px !important;
}
.wall-display--size-large .fc .fc-calendarWeek-button {
    font-size: 2.5rem !important;
    padding: 6px 14px !important;
}
.wall-display--size-large .fc .fc-exit-button,
.wall-display--size-large .fc .fc-exit-button:hover {
    padding: 12px 20px !important;
    font-size: 1.1rem !important;
}
</style>
