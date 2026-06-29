<!-- src/core/views/faktura/cards/additional.info.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-information</v-icon>
            {{ t('FakturaView.faktura.additionalInfo') }}
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__body">
            <v-row dense>
                <v-col cols="12" class="py-1">
                    <v-autocomplete
                        :model-value="modelValue.currency_id"
                        @update:model-value="onFieldChange('currency_id', $event)"
                        :label="t('FakturaView.faktura.currency')"
                        :items="currencyList"
                        item-title="name"
                        item-value="id"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        prepend-inner-icon="mdi-currency-eur"
                    />
                </v-col>

                <v-col cols="12" class="py-1">
                    <v-autocomplete
                        :model-value="modelValue.taxzone_id"
                        @update:model-value="onFieldChange('taxzone_id', $event)"
                        :label="t('FakturaView.faktura.taxZone')"
                        :items="taxZoneList"
                        item-title="description"
                        item-value="id"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        prepend-inner-icon="mdi-percent"
                    />
                </v-col>

                <v-col
                    v-if="modelValue.record_type === 'sales_order' || modelValue.record_type === 'sales_order_intake'"
                    cols="12"
                    class="py-1"
                >
                    <v-switch
                        :model-value="modelValue.record_type === 'sales_order'"
                        @update:model-value="onFieldChange('record_type', $event ? 'sales_order' : 'sales_order_intake')"
                        :label="t('FakturaView.faktura.orderConfirmed')"
                        color="success"
                        density="compact"
                        hide-details
                        inset
                    />
                </v-col>

                <v-col cols="12" class="py-1">
                    <v-autocomplete
                        :model-value="modelValue.language_id"
                        @update:model-value="onFieldChange('language_id', $event)"
                        :label="t('FakturaView.faktura.language')"
                        :items="languageList"
                        item-title="description"
                        item-value="id"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details
                        prepend-inner-icon="mdi-translate"
                    />
                </v-col>

                <v-col cols="12" class="py-1">
                    <v-autocomplete
                        :model-value="modelValue.department_id"
                        @update:model-value="onFieldChange('department_id', $event)"
                        :label="t('FakturaView.faktura.department')"
                        :items="departmentList"
                        item-title="description"
                        item-value="id"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details
                        prepend-inner-icon="mdi-domain"
                    />
                </v-col>

                <v-col cols="12" class="py-1">
                    <v-autocomplete
                        :model-value="modelValue.employee_id"
                        @update:model-value="onFieldChange('employee_id', $event)"
                        :label="t('FakturaView.faktura.employee')"
                        :items="employeeList"
                        item-title="name"
                        item-value="id"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autocomplete="off"
                        prepend-inner-icon="mdi-account-tie"
                    />
                </v-col>

                <v-col cols="12" class="py-1">
                    <v-autocomplete
                        :model-value="modelValue.payment_id"
                        @update:model-value="onFieldChange('payment_id', $event)"
                        :label="t('FakturaView.faktura.paymentTerms')"
                        :items="paymentTermList"
                        item-title="description"
                        item-value="id"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details
                        prepend-inner-icon="mdi-cash-clock"
                    />
                </v-col>

                <v-col cols="12" class="py-1">
                    <v-autocomplete
                        :model-value="modelValue.delivery_term_id"
                        @update:model-value="onFieldChange('delivery_term_id', $event)"
                        :label="t('FakturaView.faktura.deliveryTerms')"
                        :items="deliveryTermList"
                        item-title="description"
                        item-value="id"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details
                        prepend-inner-icon="mdi-truck"
                    />
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>
</template>

<script>
// src/core/views/faktura/cards/additional.info.card.vue

import { defineComponent } from 'vue'
import { useI18n } from 'vue-i18n'

export default defineComponent({
    name: 'AdditionalInfoCard',
    props: {
        modelValue: {
            type: Object,
            required: true
        },
        currencyList: {
            type: Array,
            default: () => []
        },
        taxZoneList: {
            type: Array,
            default: () => []
        },
        languageList: {
            type: Array,
            default: () => []
        },
        departmentList: {
            type: Array,
            default: () => []
        },
        employeeList: {
            type: Array,
            default: () => []
        },
        paymentTermList: {
            type: Array,
            default: () => []
        },
        deliveryTermList: {
            type: Array,
            default: () => []
        }
    },
    emits: ['update:modelValue', 'field-change'],
    setup(props, { emit }) {
        const { t } = useI18n()

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
            onFieldChange
        }
    }
})
</script>

<style scoped>
/* ============================================
   ADDITIONAL INFO CARD
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
</style>