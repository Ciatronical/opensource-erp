<!-- src/core/views/wall-display/wall-display.view.vue -->

<template>
    <div class="wall-display" :class="{ 'wall-display--faktura': mode === 'faktura' }">

        <!-- Beenden-Button (immer sichtbar, oben links) -->
        <v-btn class="wall-display__exit" size="small" variant="tonal" color="error" @click="exitWallDisplay">
            <v-icon start size="small">mdi-exit-to-app</v-icon>
            {{ t('WallDisplay.exit') }}
        </v-btn>


        <!-- Kalender-Modus -->
        <div v-if="mode === 'calendar'" class="wall-display__calendar">
            <calendar-main
                :events="events"
                :initial-view="calendarInitialView"
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
import { defineComponent, ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import CalendarMain from '@/core/views/calendar/components/calendar-main.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { fakturaStore } from '@/core/stores/faktura.store.js'

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

        // Kalender: Event-Detail
        const eventDetailOpen = ref(false)
        const selectedEvent = ref(null)

        // Faktura
        const fakturaData = ref(null)
        const fakturaVehicle = ref(null)

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

        async function loadEvents() {
            if (!currentDateRange.value.start) return
            try {
                const response = await axios.post('/api/calendar/', {
                    action: 'getCalendarEvents',
                    startDate: currentDateRange.value.start,
                    endDate: currentDateRange.value.end
                })
                if (response.data.success) {
                    events.value = response.data.payload?.result?.events || []
                }
            } catch (e) {
                console.error('Wall display: calendar load error', e)
            }
        }

        function onDatesSet(range) {
            currentDateRange.value = range
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

        // ── SSE ──

        let sseSource = null

        function connectSSE() {
            sseSource = new EventSource('/sse/events')
            sseSource.onmessage = (event) => {
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
            sseSource.onerror = () => { /* reconnect ist automatisch */ }
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

        onMounted(() => {
            connectSSE()
        })

        onBeforeUnmount(() => {
            if (sseSource) { sseSource.close(); sseSource = null }
        })

        function exitWallDisplay() {
            router.push({ name: 'customer-vendor' })
        }

        return {
            t, mode, events, calendarInitialView,
            eventDetailOpen, selectedEvent,
            fakturaData, fakturaVehicle, fakturaDocNumber, fakturaDocLabel, fakturaPositions,
            onDatesSet, onEventClick, exitWallDisplay,
            formatDate, formatCurrency, formatQty, formatEventTime
        }
    }
})
</script>

<style scoped>
.wall-display {
    width: 100vw;
    height: 100vh;
    overflow: hidden;
    background: #f5f5f5;
    display: flex;
    flex-direction: column;
}

.wall-display__exit {
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 10;
}


.wall-display__calendar {
    flex: 1;
    overflow: auto;
}

.wall-display__calendar :deep(.calendar-wrapper) {
    padding: 8px;
    min-height: 100vh;
}

/* Kalender fuer Hochformat-Display optimieren */
.wall-display__calendar :deep(.fc) {
    font-size: 1.1rem;
}

.wall-display__calendar :deep(.fc .fc-toolbar-title) {
    font-size: 2rem;
}

.wall-display__calendar :deep(.fc .fc-timegrid-slot) {
    height: 50px;
}

.wall-display__calendar :deep(.fc .fc-col-header-cell) {
    font-size: 1rem;
    padding: 16px 4px;
}

.wall-display__calendar :deep(.fc .fc-event-title) {
    font-size: 1rem;
}

.wall-display__calendar :deep(.fc .fc-timegrid-slot-label) {
    font-size: 0.9rem;
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
