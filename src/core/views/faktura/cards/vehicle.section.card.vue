
<!-- src/core/views/faktura/cards/vehicle.section.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-car</v-icon>
            {{ t('FakturaView.faktura.vehicleSection') }}
            <v-spacer />
            <!-- Sprung in die Fahrzeugmaske (zusätzlich zum Auto-Symbol in der Aktionsleiste) -->
            <v-btn
                v-if="selectedCarId"
                variant="tonal"
                color="teal"
                size="small"
                prepend-icon="mdi-car"
                class="mr-2"
                @click="$emit('open-vehicle')"
            >
                {{ t('FakturaView.faktura.openVehicle') }}
            </v-btn>
            <!-- Werkstatt-Portale (AAG-Online, HGS-Data, Gutmann): dieselben Knöpfe wie in der Fahrzeugmaske -->
            <diag-portal-buttons
                class="mr-2"
                :aag-available="aagAvailable"
                :aag-loading="aagLoading"
                :hgs-available="hgsAvailable"
                :hgs-loading="hgsLoading"
                :gutmann-available="gutmannAvailable"
                @open-aag="$emit('open-aag')"
                @open-hgs="$emit('open-hgs')"
            />
            <v-chip
                v-if="!isInvoice"
                :color="oeExtData.intern ? 'warning' : 'default'"
                :variant="oeExtData.intern ? 'tonal' : 'outlined'"
                size="small"
                :prepend-icon="oeExtData.intern ? 'mdi-account-hard-hat' : 'mdi-account-hard-hat'"
                style="cursor: pointer"
                class="mr-2"
                @click="$emit('toggle-intern')"
            >
                {{ t('FakturaView.faktura.intern') }}
            </v-chip>
            <v-chip
                v-if="!isInvoice"
                :color="oeExtData.gedruckt ? 'success' : 'default'"
                :variant="oeExtData.gedruckt ? 'tonal' : 'outlined'"
                size="small"
                :prepend-icon="oeExtData.gedruckt ? 'mdi-printer-check' : 'mdi-printer-off'"
            >
                {{ t('FakturaView.faktura.gedruckt') }}
            </v-chip>
            <v-tooltip location="bottom">
                <template #activator="{ props: tooltipProps }">
                    <v-chip
                        v-if="!isInvoice"
                        v-bind="tooltipProps"
                        :color="oeExtData.no_whatsapp ? 'error' : 'success'"
                        :variant="oeExtData.no_whatsapp ? 'tonal' : 'outlined'"
                        size="small"
                        :prepend-icon="oeExtData.no_whatsapp ? 'mdi-message-off' : 'mdi-whatsapp'"
                        style="cursor: pointer"
                        class="ml-2"
                        @click="$emit('oe-ext-field-change', 'no_whatsapp', !oeExtData.no_whatsapp)"
                    >
                        WhatsApp
                    </v-chip>
                </template>
                {{ t('FakturaView.faktura.noWhatsapp') }}
            </v-tooltip>
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__body">
            <v-row dense>
                <v-col cols="12" :sm="isInvoice ? 5 : 6" :md="isInvoice ? 4 : 3" :lg="isInvoice ? 4 : 3" class="py-1">
                    <v-autocomplete
                        ref="carAutocomplete"
                        :model-value="selectedCarId"
                        v-model:search="carSearch"
                        :items="customerCars"
                        item-value="c_id"
                        item-title="c_ln"
                        :label="t('FakturaView.faktura.licensePlate')"
                        :no-data-text="carNoDataText"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        clearable
                        prepend-inner-icon="mdi-car"
                        @update:model-value="$emit('car-change', $event)"
                        @keydown.enter="onCarEnter"
                    />
                </v-col>
                <v-col v-if="!isTrailer" :cols="isInvoice ? 6 : 6" :sm="isInvoice ? 3 : 4" :md="isInvoice ? 4 : 2" :lg="isInvoice ? 4 : 2" class="py-1">
                    <v-text-field
                        :model-value="displayKmStand"
                        :label="t('FakturaView.faktura.kmStand')"
                        inputmode="numeric"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        prepend-inner-icon="mdi-speedometer"
                        @update:model-value="$emit('update:display-km-stand', $event)"
                        @blur="$emit('blur-km-stand')"
                    />
                </v-col>
                <v-col v-if="!isInvoice && statusOptions.length" cols="6" sm="4" md="2" lg="2" class="py-1">
                    <v-select
                        :model-value="oeExtData.status"
                        :items="statusOptions"
                        :label="t('FakturaView.faktura.status')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        clearable
                        prepend-inner-icon="mdi-list-status"
                        @update:model-value="$emit('oe-ext-field-change', 'status', $event)"
                    />
                </v-col>
                <v-col v-if="!isInvoice" cols="6" sm="4" md="2" lg="2" class="py-1">
                    <v-select
                        :model-value="oeExtData.kfz_ort"
                        :items="kfzOrtOptions"
                        :label="t('FakturaView.faktura.kfzOrt')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        clearable
                        prepend-inner-icon="mdi-map-marker"
                        @update:model-value="$emit('oe-ext-field-change', 'kfz_ort', $event)"
                    />
                </v-col>
                <v-col v-if="!isInvoice" cols="6" sm="4" md="3" lg="3" class="py-1">
                    <v-text-field
                        :model-value="displayBringetermin"
                        :label="t('FakturaView.faktura.bringetermin')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        readonly
                        clearable
                        @click:clear="$emit('clear-datetime', 'bringetermin')"
                    >
                        <template #append-inner>
                            <v-menu
                                v-model="localShowBringetermin"
                                :close-on-content-click="false"
                                location="bottom end"
                            >
                                <template #activator="{ props: menuProps }">
                                    <v-icon
                                        v-bind="menuProps"
                                        class="date-picker-icon"
                                    >mdi-calendar-clock</v-icon>
                                </template>
                        <v-card class="d-flex datetime-picker-card">
                            <v-date-picker
                                :model-value="pickerDateBringetermin"
                                @update:model-value="$emit('picker-date-select', 'bringetermin', $event)"
                                show-adjacent-months
                                color="primary"
                                :header="t('FakturaView.faktura.bringetermin')"
                            />
                            <div class="datetime-picker-times">
                                <div class="datetime-picker-times__header">
                                    {{ t('FakturaView.faktura.uhrzeit') }}
                                    <v-btn icon variant="text" size="x-small" class="datetime-picker-close" @click="localShowBringetermin = false">
                                        <v-icon size="16">mdi-close</v-icon>
                                    </v-btn>
                                </div>
                                <div class="datetime-picker-times__list">
                                    <div
                                        v-for="slot in timeSlots"
                                        :key="'b-' + slot"
                                        class="datetime-picker-times__slot"
                                        :class="{ 'datetime-picker-times__slot--active': pickerTimeBringetermin === slot }"
                                        @click="$emit('picker-time-select', 'bringetermin', slot); localShowBringetermin = false"
                                    >{{ slot }}</div>
                                </div>
                            </div>
                        </v-card>
                            </v-menu>
                        </template>
                    </v-text-field>
                </v-col>
                <v-col cols="6" :sm="isInvoice ? 4 : 4" :md="isInvoice ? 4 : 3" :lg="isInvoice ? 4 : 3" class="py-1">
                    <v-text-field
                        :model-value="displayFertigstellung"
                        :label="t('FakturaView.faktura.fertigstellung')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        readonly
                        clearable
                        @click:clear="$emit('clear-datetime', 'fertigstellung')"
                    >
                        <template #append-inner>
                            <v-menu
                                v-model="localShowFertigstellung"
                                :close-on-content-click="false"
                                location="bottom end"
                            >
                                <template #activator="{ props: menuProps }">
                                    <v-icon
                                        v-bind="menuProps"
                                        class="date-picker-icon"
                                    >mdi-calendar-clock</v-icon>
                                </template>
                        <v-card class="d-flex datetime-picker-card">
                            <v-date-picker
                                :model-value="pickerDateFertigstellung"
                                @update:model-value="$emit('picker-date-select', 'fertigstellung', $event)"
                                show-adjacent-months
                                color="primary"
                                :header="t('FakturaView.faktura.fertigstellung')"
                            />
                            <div class="datetime-picker-times">
                                <div class="datetime-picker-times__header">
                                    {{ t('FakturaView.faktura.uhrzeit') }}
                                    <v-btn icon variant="text" size="x-small" class="datetime-picker-close" @click="localShowFertigstellung = false">
                                        <v-icon size="16">mdi-close</v-icon>
                                    </v-btn>
                                </div>
                                <div class="datetime-picker-times__list">
                                    <div
                                        v-for="slot in timeSlots"
                                        :key="'f-' + slot"
                                        class="datetime-picker-times__slot"
                                        :class="{ 'datetime-picker-times__slot--active': pickerTimeFertigstellung === slot }"
                                        @click="$emit('picker-time-select', 'fertigstellung', slot); localShowFertigstellung = false"
                                    >{{ slot }}</div>
                                </div>
                            </div>
                        </v-card>
                            </v-menu>
                        </template>
                    </v-text-field>
                </v-col>
                <!-- Reparaturzeit (docs/features/lxcars.md → Reparaturzeit): gemessen, von Hand oder aus dem Gutachten;
                     Schalter „Drucken“ bringt sie auf Auftrag und Rechnung -->
                <v-col cols="12" sm="8" :md="isInvoice ? 4 : 5" :lg="isInvoice ? 4 : 4" class="py-1">
                    <div class="repair-time">
                        <v-text-field
                            :model-value="repairTimeInput"
                            :label="t('FakturaView.faktura.repairTime.label')"
                            :placeholder="repairTimePlaceholder"
                            persistent-placeholder
                            variant="outlined"
                            density="compact"
                            hide-details
                            autocomplete="off"
                            clearable
                            class="repair-time__field"
                            :class="{ 'repair-time__field--running': repairTimeRunning }"
                            @update:model-value="repairTimeInput = $event ?? ''"
                            @blur="commitRepairTime"
                            @keydown.enter.prevent="commitRepairTime"
                            @click:clear="clearRepairTime"
                        >
                            <template #prepend-inner>
                                <v-tooltip location="bottom">
                                    <template #activator="{ props: tipProps }">
                                        <v-icon v-bind="tipProps" size="small" class="repair-time__icon">{{ repairTimeIcon }}</v-icon>
                                    </template>
                                    {{ repairTimeHint }}
                                </v-tooltip>
                            </template>
                        </v-text-field>
                        <v-tooltip location="bottom">
                            <template #activator="{ props: tipProps }">
                                <v-checkbox
                                    v-bind="tipProps"
                                    :model-value="!!oeExtData.reparaturzeit_drucken"
                                    :label="t('FakturaView.faktura.repairTime.print')"
                                    density="compact"
                                    hide-details
                                    class="repair-time__print"
                                    @update:model-value="$emit('oe-ext-field-change', 'reparaturzeit_drucken', !!$event)"
                                />
                            </template>
                            {{ t('FakturaView.faktura.repairTime.printHint') }}
                        </v-tooltip>
                    </div>
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>
</template>

<script>
import { defineComponent, ref, computed, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toast from '@/core/utils/toasts.js'
import { formatRepairTime, parseRepairTime } from '@/features/lxcars/utils/repairTime.js'
import DiagPortalButtons from '@/features/lxcars/components/diag-portal-buttons.vue'

export default defineComponent({
    name: 'VehicleSectionCard',
    components: { DiagPortalButtons },
    props: {
        oeExtData: { type: Object, required: true },
        selectedCarId: { type: [Number, null], default: null },
        customerCars: { type: Array, default: () => [] },
        kfzOrtOptions: { type: Array, default: () => [] },
        statusOptions: { type: Array, default: () => [] },
        displayKmStand: { type: String, default: '' },
        displayBringetermin: { type: String, default: '' },
        displayFertigstellung: { type: String, default: '' },
        pickerDateBringetermin: { default: undefined },
        pickerTimeBringetermin: { type: String, default: '' },
        pickerDateFertigstellung: { default: undefined },
        pickerTimeFertigstellung: { type: String, default: '' },
        timeSlots: { type: Array, default: () => [] },
        showPickerBringetermin: { type: Boolean, default: false },
        showPickerFertigstellung: { type: Boolean, default: false },
        isInvoice: { type: Boolean, default: false },
        isTrailer: { type: Boolean, default: false },
        /** Werkstatt-Portale (useDiagPortals.js): AAG-Online und HGS-Data */
        aagAvailable: { type: Boolean, default: false },
        aagLoading: { type: Boolean, default: false },
        hgsAvailable: { type: Boolean, default: false },
        hgsLoading: { type: Boolean, default: false }
    },
    emits: [
        'toggle-intern', 'blur-km-stand', 'car-change', 'car-create',
        'oe-ext-field-change', 'picker-date-select', 'picker-time-select', 'clear-datetime',
        'update:display-km-stand', 'repair-time-change', 'open-aag', 'open-hgs', 'open-vehicle'
    ],
    setup(props, { expose, emit }) {
        const { t } = useI18n()
        const oserp = oserpStore()
        // Gutmann-Diagnose (docs/features/gutmann.md): Schalter feature_gutmann und gewähltes Fahrzeug
        const gutmannAvailable = computed(() => oserp.isGutmannEnabled() && !!props.selectedCarId)

        // Kennzeichen-Schnellanlage: Enter auf einen unbekannten Suchtext legt das Fahrzeug an
        const carSearch = ref('')
        const carNoDataText = computed(() => {
            const typed = (carSearch.value || '').trim()
            return typed
                ? t('FakturaView.faktura.quickCar.enterToCreate', { plate: typed.toUpperCase() })
                : t('FakturaView.faktura.noVehicle')
        })
        function onCarEnter() {
            const typed = (carSearch.value || '').trim()
            if (!typed) return
            // Exakter Treffer in der Liste wird von Vuetify nicht automatisch gewählt – übernimmt das Composable
            emit('car-create', typed)
            carSearch.value = ''
        }

        // Lokale Kopien für v-model der Date-Time-Picker-Menus
        const localShowBringetermin = ref(props.showPickerBringetermin)
        const localShowFertigstellung = ref(props.showPickerFertigstellung)

        watch(() => props.showPickerBringetermin, v => { localShowBringetermin.value = v })
        watch(() => props.showPickerFertigstellung, v => { localShowFertigstellung.value = v })

        const carAutocomplete = ref(null)
        function focusCarSelect() {
            carAutocomplete.value?.focus()
        }
        expose({ focusCarSelect })

        // ── Reparaturzeit ──
        // Anzeige ist der formatierte Wert; beim Verlassen/Enter wird die Eingabe
        // geparst (3 Tage, 4,5 Std, 1:30 …) und als Minuten gemeldet. Ohne Wert zeigt
        // der Platzhalter die laufende Messung ab Abgabetermin bzw. Auftragsanlage.
        const repairTimeInput = ref('')
        watch(() => props.oeExtData.reparaturzeit_minuten, v => { repairTimeInput.value = formatRepairTime(v, t) }, { immediate: true })

        const nowTick = ref(Date.now())
        const tickTimer = setInterval(() => { nowTick.value = Date.now() }, 60000)
        onBeforeUnmount(() => clearInterval(tickTimer))

        const repairTimeRunningMinutes = computed(() => {
            if (props.isInvoice || props.oeExtData.reparaturzeit_minuten || !props.oeExtData.reparaturzeit_start) return 0
            const start = Date.parse(String(props.oeExtData.reparaturzeit_start).replace(' ', 'T'))
            if (Number.isNaN(start)) return 0
            return Math.floor((nowTick.value - start) / 60000)
        })
        const repairTimeRunning = computed(() => repairTimeRunningMinutes.value > 0)
        const repairTimePlaceholder = computed(() => repairTimeRunning.value
            ? t('FakturaView.faktura.repairTime.running', { time: formatRepairTime(repairTimeRunningMinutes.value, t) })
            : t('FakturaView.faktura.repairTime.placeholder'))

        const repairTimeIcon = computed(() => ({
            auto: 'mdi-timer-check-outline',
            manuell: 'mdi-pencil-outline',
            gutachten: 'mdi-file-document-check-outline'
        })[props.oeExtData.reparaturzeit_quelle] || (repairTimeRunning.value ? 'mdi-timer-sand' : 'mdi-timer-outline'))
        const repairTimeHint = computed(() => {
            const q = props.oeExtData.reparaturzeit_quelle
            if (q) return t('FakturaView.faktura.repairTime.source.' + q)
            return repairTimeRunning.value
                ? t('FakturaView.faktura.repairTime.source.running')
                : t('FakturaView.faktura.repairTime.source.none')
        })

        function commitRepairTime() {
            const minutes = parseRepairTime(repairTimeInput.value)
            if (Number.isNaN(minutes)) {
                toast.warning(t('FakturaView.faktura.repairTime.invalid'))
                repairTimeInput.value = formatRepairTime(props.oeExtData.reparaturzeit_minuten, t)
                return
            }
            const current = props.oeExtData.reparaturzeit_minuten ? Number(props.oeExtData.reparaturzeit_minuten) : null
            repairTimeInput.value = formatRepairTime(minutes, t)
            if (minutes === current) return
            emit('repair-time-change', minutes)
        }
        function clearRepairTime() {
            repairTimeInput.value = ''
            if (props.oeExtData.reparaturzeit_minuten) emit('repair-time-change', null)
        }

        return { t, localShowBringetermin, localShowFertigstellung, carAutocomplete, carSearch, carNoDataText, onCarEnter, gutmannAvailable,
                 repairTimeInput, repairTimeRunning, repairTimePlaceholder, repairTimeIcon, repairTimeHint, commitRepairTime, clearRepairTime }
    }
})
</script>

<style scoped>
.faktura-card {
    height: 100%;
    border-radius: 8px;
}

.faktura-card__header {
    padding: 14px 16px !important;
    background-color: #f5f5f5;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
}

.faktura-card__body {
    padding: 16px !important;
}

.faktura-card__body :deep(.v-input) {
    flex: unset;
    grid-template-rows: auto !important;
}

.faktura-card__body :deep(.v-input__details) {
    display: none !important;
}

.faktura-card__body :deep(.v-field--variant-outlined) {
    --v-field-padding-start: 12px;
    --v-field-padding-end: 12px;
}

/* Raster statt Flex: die Kartenregel „.v-input { flex: unset }“ würde das Feld sonst auf Inhaltsbreite schrumpfen */
.repair-time {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: var(--oserp-space-2, 8px);
}

.repair-time__field {
    min-width: 0;
    width: 100%;
}

.repair-time__field--running :deep(input::placeholder) {
    opacity: 0.85;
}

.repair-time__icon {
    opacity: 0.6;
    cursor: help;
}

.repair-time__print {
    flex: 0 0 auto;
}

.repair-time__print :deep(.v-label) {
    font-size: 13px;
}

.date-picker-icon {
    cursor: pointer;
    opacity: 0.5;
    transition: opacity 0.15s ease;
    font-size: 18px !important;
}

.date-picker-icon:hover {
    opacity: 1;
}

/* DateTime-Picker: Kalender links, Zeitliste rechts */
.datetime-picker-card {
    overflow: visible;
}

.datetime-picker-times {
    width: 80px;
    border-left: 1px solid #ddd;
    display: flex;
    flex-direction: column;
}

.datetime-picker-times__header {
    padding: 6px 4px;
    text-align: center;
    font-size: 12px;
    font-weight: 600;
    color: #666;
    border-bottom: 1px solid #ddd;
    flex-shrink: 0;
    position: relative;
    overflow: visible;
}

.datetime-picker-close {
    position: absolute;
    right: -4px;
    top: -4px;
    opacity: 0.5;
    z-index: 1;
}

.datetime-picker-close:hover {
    opacity: 1;
}

.datetime-picker-times__list {
    overflow-y: auto;
    flex: 1;
}

.datetime-picker-times__slot {
    padding: 4px 8px;
    text-align: center;
    font-size: 13px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    color: #555;
    transition: background 0.1s;
}

.datetime-picker-times__slot:hover {
    background: #ff8000;
    color: #fff;
}

.datetime-picker-times__slot--active {
    background: #1976d2;
    color: #fff;
    font-weight: 600;
}

.datetime-picker-times__slot--active:hover {
    background: #1565c0;
}
</style>
