<!-- src/core/views/config/tabs/ranges.of.numbers.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ $t('lastNumbersPrefixes') }}</h3>

        <v-row>
            <v-col 
                v-for="field in filteredFields" 
                :key="field.key"
                cols="12" 
                md="6"
            >
                <v-text-field
                    v-model="defaults[field.key]"
                    :label="field.label"
                    variant="outlined"
                    density="compact"
                    :class="{ 'highlight-field': isHighlighted(field) }"
                />
            </v-col>
        </v-row>

        <!-- Keine Ergebnisse -->
        <v-alert v-if="searchQuery && filteredFields.length === 0" type="info" variant="tonal" class="mt-4">
            {{ $t('noFieldsFound') }}
        </v-alert>
    </div>
</template>

<script setup>
import { computed, defineProps } from 'vue';
import { useI18n } from 'vue-i18n';

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

// Alle Felder mit ihren Bezeichnungen und Suchbegriffen
const fields = [
    { key: 'customernumber', label: t('customer'), searchTerms: ['kunde', 'customer', 'kundennummer'] },
    { key: 'vendornumber', label: t('vendor'), searchTerms: ['lieferant', 'vendor', 'lieferantennummer'] },
    { key: 'invnumber', label: t('invoice'), searchTerms: ['rechnung', 'invoice', 'rechnungsnummer'] },
    { key: 'cnnumber', label: t('creditNote'), searchTerms: ['gutschrift', 'credit', 'cn'] },
    { key: 'soinumber', label: t('salesOrderIntake'), searchTerms: ['auftragseingang', 'intake', 'soi'] },
    { key: 'pqinumber', label: t('purchaseQuotationIntake'), searchTerms: ['angebotseingang', 'quotation', 'pqi'] },
    { key: 'sonumber', label: t('salesOrder'), searchTerms: ['auftrag', 'order', 'verkauf', 'so'] },
    { key: 'ponumber', label: t('purchaseOrder'), searchTerms: ['lieferantenauftrag', 'purchase', 'po'] },
    { key: 'pocnumber', label: t('purchaseOrderConfirmation'), searchTerms: ['auftragsbestätigung', 'confirmation', 'poc'] },
    { key: 'sqnumber', label: t('salesQuotation'), searchTerms: ['angebot', 'quotation', 'verkauf', 'sq'] },
    { key: 'rfqnumber', label: t('requestQuotation'), searchTerms: ['anfrage', 'request', 'rfq'] },
    { key: 'sdonumber', label: t('salesDeliveryOrder'), searchTerms: ['lieferschein', 'delivery', 'verkauf', 'sdo'] },
    { key: 'pdonumber', label: t('purchaseDeliveryOrder'), searchTerms: ['lieferschein', 'delivery', 'einkauf', 'pdo'] },
    { key: 'sudonumber', label: t('supplierDeliveryOrder'), searchTerms: ['beistell', 'supplier', 'sudo'] },
    { key: 'rdonumber', label: t('rmaDeliveryOrder'), searchTerms: ['retouren', 'rma', 'rdo'] },
    { key: 's_reclamation_record_number', label: t('salesReclamation'), searchTerms: ['reklamation', 'verkauf', 'sales'] },
    { key: 'p_reclamation_record_number', label: t('purchaseReclamation'), searchTerms: ['reklamation', 'einkauf', 'purchase'] },
    { key: 'articlenumber', label: t('article'), searchTerms: ['artikel', 'article', 'ware'] },
    { key: 'servicenumber', label: t('service'), searchTerms: ['dienstleistung', 'service'] },
    { key: 'assemblynumber', label: t('assembly'), searchTerms: ['erzeugnis', 'assembly'] },
    { key: 'assortmentnumber', label: t('assortment'), searchTerms: ['sortiment', 'assortment'] }
];

// Gefilterte Felder basierend auf Suchbegriff
const filteredFields = computed(() => {
    if (!props.searchQuery || props.searchQuery.trim() === '') {
        return fields;
    }
    
    const query = props.searchQuery.toLowerCase();
    return fields.filter(field => {
        // Suche in Label
        if (field.label.toLowerCase().includes(query)) {
            return true;
        }
        // Suche in Suchbegriffen
        if (field.searchTerms.some(term => term.toLowerCase().includes(query))) {
            return true;
        }
        // Suche im Key
        if (field.key.toLowerCase().includes(query)) {
            return true;
        }
        return false;
    });
});

// Prüfe ob Feld hervorgehoben werden soll
function isHighlighted(field) {
    if (!props.searchQuery || props.searchQuery.trim() === '') {
        return false;
    }
    const query = props.searchQuery.toLowerCase();
    return field.label.toLowerCase().includes(query) || 
           field.searchTerms.some(term => term.toLowerCase().includes(query));
}
</script>

<style scoped>
.highlight-field :deep(.v-field) {
    border: 2px solid rgb(var(--v-theme-primary));
    background-color: rgba(var(--v-theme-primary), 0.05);
}
</style>
