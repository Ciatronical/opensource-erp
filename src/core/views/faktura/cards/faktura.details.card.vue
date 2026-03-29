<!-- src/core/views/faktura/cards/faktura.details.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-file-document</v-icon>
            {{ t('FakturaView.faktura.fakturaDetails') }}
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__body">
            <v-row dense>
                <!-- Belegnummer - abhängig vom Dokumenttyp -->
                <v-col cols="12" class="py-1">
                    <v-text-field
                        :model-value="getDocumentNumber()"
                        :label="getDocumentNumberLabel()"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        prepend-inner-icon="mdi-pound"
                        readonly
                    />
                </v-col>

                <!-- Auftragsnummer (wenn nicht Auftrag) -->
                <v-col v-if="fakturaType !== 'order'" cols="12" class="py-1">
                    <v-text-field
                        :model-value="modelValue.ordnumber"
                        @update:model-value="onFieldChange('ordnumber', $event)"
                        :label="t('FakturaView.faktura.orderNumber')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        prepend-inner-icon="mdi-pound"
                    />
                </v-col>

                <!-- Kundenauftragsnummer -->
                <v-col cols="12" class="py-1">
                    <v-text-field
                        :model-value="modelValue.cusordnumber"
                        @update:model-value="onFieldChange('cusordnumber', $event)"
                        :label="t('FakturaView.faktura.customerOrderNumber')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        prepend-inner-icon="mdi-pound"
                    />
                </v-col>

                <!-- Angebotsnummer (wenn nicht Angebot) -->
                <v-col v-if="fakturaType !== 'quotation'" cols="12" class="py-1">
                    <v-text-field
                        :model-value="modelValue.quonumber"
                        @update:model-value="onFieldChange('quonumber', $event)"
                        :label="t('FakturaView.faktura.quoteNumber')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        prepend-inner-icon="mdi-pound"
                    />
                </v-col>

                <!-- Belegdatum -->
                <v-col cols="12" class="py-1">
                    <v-menu v-model="showPicker.transdate" :close-on-content-click="false" location="bottom start">
                        <template #activator="{ props: menuProps }">
                            <v-text-field
                                v-model="displayDates.transdate"
                                :label="getDocumentDateLabel()"
                                :placeholder="t('FakturaView.faktura.datePlaceholder')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                @blur="onBlurDate('transdate')"
                            >
                                <template #append-inner>
                                    <v-icon v-bind="menuProps" class="date-picker-icon">mdi-calendar</v-icon>
                                </template>
                            </v-text-field>
                        </template>
                        <v-date-picker
                            :model-value="toDateObj(modelValue.transdate)"
                            @update:model-value="onPickerSelect('transdate', $event)"
                            show-adjacent-months
                            color="primary"
                        />
                    </v-menu>
                </v-col>

                <!-- Lieferdatum -->
                <v-col cols="12" class="py-1">
                    <v-menu v-model="showPicker.deliverydate" :close-on-content-click="false" location="bottom start">
                        <template #activator="{ props: menuProps }">
                            <v-text-field
                                v-model="displayDates.deliverydate"
                                :label="t('FakturaView.faktura.deliveryDate')"
                                :placeholder="t('FakturaView.faktura.datePlaceholder')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                @blur="onBlurDate('deliverydate')"
                            >
                                <template #append-inner>
                                    <v-icon v-bind="menuProps" class="date-picker-icon">mdi-calendar</v-icon>
                                </template>
                            </v-text-field>
                        </template>
                        <v-date-picker
                            :model-value="toDateObj(getDeliveryDate())"
                            @update:model-value="onPickerSelect('deliverydate', $event)"
                            show-adjacent-months
                            color="primary"
                        />
                    </v-menu>
                </v-col>

                <!-- Fälligkeitsdatum (nur bei Rechnung) -->
                <v-col v-if="fakturaType === 'invoice'" cols="12" class="py-1">
                    <v-menu v-model="showPicker.duedate" :close-on-content-click="false" location="bottom start">
                        <template #activator="{ props: menuProps }">
                            <v-text-field
                                v-model="displayDates.duedate"
                                :label="t('FakturaView.faktura.dueDate')"
                                :placeholder="t('FakturaView.faktura.datePlaceholder')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                @blur="onBlurDate('duedate')"
                            >
                                <template #append-inner>
                                    <v-icon v-bind="menuProps" class="date-picker-icon">mdi-calendar</v-icon>
                                </template>
                            </v-text-field>
                        </template>
                        <v-date-picker
                            :model-value="toDateObj(modelValue.duedate)"
                            @update:model-value="onPickerSelect('duedate', $event)"
                            show-adjacent-months
                            color="primary"
                        />
                    </v-menu>
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>
</template>

<script>
// src/core/views/faktura/cards/faktura.details.card.vue

import { defineComponent, ref, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { parseShortDate, formatDateDE } from '@/features/lxcars/utils/validation.js'

export default defineComponent({
    name: 'FakturaDetailsCard',
    props: {
        modelValue: {
            type: Object,
            required: true
        },
        fakturaType: {
            type: String,
            default: 'invoice',
            validator: (value) => ['invoice', 'order', 'quotation', 'delivery_order'].includes(value)
        }
    },
    emits: ['update:modelValue', 'field-change'],
    setup(props, { emit }) {
        const { t } = useI18n()

        // Date picker state
        const showPicker = reactive({ transdate: false, deliverydate: false, duedate: false })
        const displayDates = reactive({ transdate: '', deliverydate: '', duedate: '' })

        // ISO-String → Date-Objekt für v-date-picker
        function toDateObj(isoStr) {
            if (!isoStr) return undefined
            return new Date(isoStr + 'T00:00:00')
        }

        // Props → Display-Werte synchronisieren
        function syncDisplayDates() {
            displayDates.transdate = formatDateDE(props.modelValue.transdate)
            displayDates.deliverydate = formatDateDE(
                props.fakturaType === 'invoice' ? props.modelValue.deliverydate : props.modelValue.reqdate
            )
            displayDates.duedate = formatDateDE(props.modelValue.duedate)
        }

        watch(() => props.modelValue, syncDisplayDates, { deep: true, immediate: true })

        // Manuelle Eingabe verarbeiten (deutsches Kurzformat)
        function onBlurDate(displayField) {
            const raw = displayDates[displayField].trim()
            const realField = displayField === 'deliverydate' ? getDeliveryDateField() : displayField

            if (!raw) {
                onFieldChange(realField, null)
                return
            }
            const parsed = parseShortDate(raw)
            if (!parsed) {
                // Ungültig → zurücksetzen auf vorherigen Wert
                syncDisplayDates()
                return
            }
            displayDates[displayField] = formatDateDE(parsed)
            onFieldChange(realField, parsed)
        }

        // Auswahl aus v-date-picker
        function onPickerSelect(displayField, date) {
            if (!date) return
            const d = new Date(date)
            const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
            displayDates[displayField] = formatDateDE(iso)
            const realField = displayField === 'deliverydate' ? getDeliveryDateField() : displayField
            onFieldChange(realField, iso)
            showPicker[displayField] = false
        }

        /**
         * Gibt die Belegnummer abhängig vom Dokumenttyp zurück
         */
        function getDocumentNumber() {
            switch (props.fakturaType) {
                case 'invoice':
                    return props.modelValue.invnumber
                case 'order':
                    return props.modelValue.ordnumber
                case 'quotation':
                    return props.modelValue.quonumber
                case 'delivery_order':
                    return props.modelValue.donumber
                default:
                    return props.modelValue.invnumber
            }
        }

        /**
         * Aktualisiert die Belegnummer abhängig vom Dokumenttyp
         */
        function updateDocumentNumber(value) {
            const updates = { ...props.modelValue }
            let fieldName = ''

            switch (props.fakturaType) {
                case 'invoice':
                    updates.invnumber = value
                    fieldName = 'invnumber'
                    break
                case 'order':
                    updates.ordnumber = value
                    fieldName = 'ordnumber'
                    break
                case 'quotation':
                    updates.quonumber = value
                    fieldName = 'quonumber'
                    break
                case 'delivery_order':
                    updates.donumber = value
                    fieldName = 'donumber'
                    break
            }

            emit('update:modelValue', updates)
            emit('field-change', fieldName, value)
        }

        /**
         * Gibt das Label für die Belegnummer abhängig vom Dokumenttyp zurück
         */
        function getDocumentNumberLabel() {
            switch (props.fakturaType) {
                case 'invoice':
                    return t('FakturaView.faktura.invoiceNumber')
                case 'order':
                    return t('FakturaView.faktura.orderNumber')
                case 'quotation':
                    return t('FakturaView.faktura.quoteNumber')
                case 'delivery_order':
                    return t('FakturaView.faktura.deliveryOrderNumber')
                default:
                    return t('FakturaView.faktura.fakturaNumber')
            }
        }

        /**
         * Gibt das Label für das Belegdatum abhängig vom Dokumenttyp zurück
         */
        function getDocumentDateLabel() {
            switch (props.fakturaType) {
                case 'invoice':
                    return t('FakturaView.faktura.invoiceDate')
                case 'order':
                    return t('FakturaView.faktura.orderDate')
                case 'quotation':
                    return t('FakturaView.faktura.quotationDate')
                case 'delivery_order':
                    return t('FakturaView.faktura.deliveryDate')
                default:
                    return t('FakturaView.faktura.fakturaDate')
            }
        }

        /**
         * Gibt den Feldnamen für das Lieferdatum zurück
         * ar/ap verwenden 'deliverydate', oe verwendet 'reqdate'
         */
        function getDeliveryDateField() {
            return props.fakturaType === 'invoice' ? 'deliverydate' : 'reqdate'
        }

        /**
         * Gibt das Lieferdatum zurück
         */
        function getDeliveryDate() {
            return props.fakturaType === 'invoice'
                ? props.modelValue.deliverydate
                : props.modelValue.reqdate
        }

        /**
         * Aktualisiert das Model und emittiert das field-change Event für Backend-Speicherung
         *
         * @param {string} field - Feldname
         * @param {any} value - Neuer Wert
         */
        function onFieldChange(field, value) {
            emit('update:modelValue', { ...props.modelValue, [field]: value })
            emit('field-change', field, value)
        }

        return {
            t,
            showPicker,
            displayDates,
            toDateObj,
            onBlurDate,
            onPickerSelect,
            getDocumentNumber,
            updateDocumentNumber,
            getDocumentNumberLabel,
            getDocumentDateLabel,
            getDeliveryDateField,
            getDeliveryDate,
            onFieldChange
        }
    }
})
</script>

<style scoped>
/* ============================================
   FAKTURA DETAILS CARD
   ============================================ */

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

/* Input-Felder */
.faktura-card__body :deep(.v-input) {
    flex: unset;
    grid-template-rows: auto !important;
    margin-bottom: 4px;
}

.faktura-card__body :deep(.v-input__details) {
    display: none !important;
}

.faktura-card__body :deep(.v-field--variant-outlined) {
    --v-field-padding-start: 12px;
    --v-field-padding-end: 12px;
}

/* Date Picker Icon im Text-Field */
.date-picker-icon {
    cursor: pointer;
    opacity: 0.5;
    transition: opacity 0.15s ease;
    font-size: 18px !important;
}

.date-picker-icon:hover {
    opacity: 1;
}
</style>