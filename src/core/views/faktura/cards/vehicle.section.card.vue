
<!-- src/core/views/faktura/cards/vehicle.section.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-car</v-icon>
            {{ t('FakturaView.faktura.vehicleSection') }}
            <v-spacer />
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
                        :items="customerCars"
                        item-value="c_id"
                        item-title="c_ln"
                        :label="t('FakturaView.faktura.licensePlate')"
                        :no-data-text="t('FakturaView.faktura.noVehicle')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        clearable
                        prepend-inner-icon="mdi-car"
                        @update:model-value="$emit('car-change', $event)"
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
            </v-row>
        </v-card-text>
    </v-card>
</template>

<script>
import { defineComponent, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

export default defineComponent({
    name: 'VehicleSectionCard',
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
        isTrailer: { type: Boolean, default: false }
    },
    emits: [
        'toggle-intern', 'blur-km-stand', 'car-change',
        'oe-ext-field-change', 'picker-date-select', 'picker-time-select', 'clear-datetime',
        'update:display-km-stand'
    ],
    setup(props, { expose }) {
        const { t } = useI18n()

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

        return { t, localShowBringetermin, localShowFertigstellung, carAutocomplete }
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
