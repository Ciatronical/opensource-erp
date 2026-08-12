<!-- src/components/settings/tabs/default.accounts.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ $t('defaultAccounts') }}</h3>

        <v-row>
            <template v-for="field in filteredMainFields" :key="field.key">
                <v-col cols="12">
                    <v-text-field
                        v-model="defaults[field.key]"
                        :label="field.label"
                        variant="outlined"
                        density="compact"
                        :class="{ 'highlight-field': isHighlighted(field) }"
                    />
                </v-col>
            </template>

            <template v-if="filteredYearEndFields.length">
                <v-col cols="12">
                    <v-divider class="my-4" />
                    <h3 class="text-h6 mb-4">{{ $t('yearEnd') }}</h3>
                </v-col>
                <v-col v-for="field in filteredYearEndFields" :key="field.key" cols="12">
                    <v-text-field
                        v-model="defaults[field.key]"
                        :label="field.label"
                        variant="outlined"
                        density="compact"
                        :class="{ 'highlight-field': isHighlighted(field) }"
                    />
                </v-col>
            </template>
        </v-row>

        <v-alert
            v-if="hasQuery && filteredMainFields.length === 0 && filteredYearEndFields.length === 0"
            type="info" variant="tonal" class="mt-4"
        >
            {{ $t('noFieldsFound') }}
        </v-alert>
    </div>
</template>

<script setup>
import { defineProps, toRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFieldSearch } from '../composables/useFieldSearch.js';

const { t } = useI18n();

const props = defineProps({
    defaults: {
        type: Object,
        required: true
    },
    searchQuery: {
        type: String,
        default: ''
    }
});

// Standardkonten
const mainFields = [
    { key: 'inventory_accno_id', label: t('inventoryAccount'), searchTerms: ['warenbestand', 'bestand', 'inventory'] },
    { key: 'income_accno_id', label: t('incomeAccount'), searchTerms: ['erlös', 'erlöskonto', 'ertrag', 'income'] },
    { key: 'expense_accno_id', label: t('expenseAccount'), searchTerms: ['aufwand', 'aufwandskonto', 'expense'] },
    { key: 'fxgain_accno_id', label: t('fxGainAccount'), searchTerms: ['währung', 'kursgewinn', 'fx'] },
    { key: 'fxloss_accno_id', label: t('fxLossAccount'), searchTerms: ['währung', 'kursverlust', 'fx'] },
    { key: 'rndgain_accno_id', label: t('rndGainAccount'), searchTerms: ['rundung', 'rundungsgewinn'] },
    { key: 'rndloss_accno_id', label: t('rndLossAccount'), searchTerms: ['rundung', 'rundungsverlust'] },
    { key: 'ar_paid_accno_id', label: t('arPaidAccount'), searchTerms: ['bezahlt', 'zahlung', 'forderung'] },
    { key: 'ap_chart_id', label: t('apAccount'), searchTerms: ['verbindlichkeit', 'kreditor', 'ap'] },
    { key: 'ar_chart_id', label: t('arAccount'), searchTerms: ['forderung', 'debitor', 'ar'] },
    { key: 'advance_payment_clearing_chart_id', label: t('advancePaymentClearingAccount'), searchTerms: ['anzahlung', 'verrechnung'] },
    { key: 'workflow_po_ap_chart_id', label: t('workflowPoApAccount'), searchTerms: ['bestellung', 'workflow', 'einkauf'] }
];

// Jahresabschluss
const yearEndFields = [
    { key: 'carry_over_account_chart_id', label: t('carryOverAccount'), searchTerms: ['saldenvortrag', 'vortrag', 'jahresabschluss'] },
    { key: 'profit_carried_forward_chart_id', label: t('profitCarriedForwardAccount'), searchTerms: ['gewinnvortrag', 'jahresabschluss'] },
    { key: 'loss_carried_forward_chart_id', label: t('lossCarriedForwardAccount'), searchTerms: ['verlustvortrag', 'jahresabschluss'] },
    { key: 'transit_items_chart_id', label: t('transitItemsAccount'), searchTerms: ['durchlaufend', 'transit'] }
];

const query = toRef(props, 'searchQuery');
const { filteredFields: filteredMainFields, isHighlighted, hasQuery } = useFieldSearch(mainFields, query);
const { filteredFields: filteredYearEndFields } = useFieldSearch(yearEndFields, query);
</script>

<style scoped>
.highlight-field :deep(.v-field) {
    border: 2px solid rgb(var(--v-theme-primary));
    background-color: rgba(var(--v-theme-primary), 0.05);
}
</style>
