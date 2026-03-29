<!-- document-search-form.component.vue -->
<template>
    <v-row dense>
        <v-col cols="12" sm="6" md="3">
            <v-text-field
                v-model="criteria.document_number"
                :label="t('SearchView.document_fields.document_number')"
                variant="outlined"
                density="compact"
                hide-details
                clearable
            />
        </v-col>
        <v-col cols="12" sm="6" md="3">
            <v-text-field
                v-model="criteria.cv_name"
                :label="cvNameLabel"
                variant="outlined"
                density="compact"
                hide-details
                clearable
            />
        </v-col>
        <v-col cols="6" sm="3" md="1.5">
            <v-text-field
                v-model="criteria.transdate_from"
                :label="t('SearchView.document_fields.transdate_from')"
                type="date"
                variant="outlined"
                density="compact"
                hide-details
                clearable
            />
        </v-col>
        <v-col cols="6" sm="3" md="1.5">
            <v-text-field
                v-model="criteria.transdate_to"
                :label="t('SearchView.document_fields.transdate_to')"
                type="date"
                variant="outlined"
                density="compact"
                hide-details
                clearable
            />
        </v-col>
        <v-col cols="6" sm="3" md="1.5">
            <v-text-field
                v-model="criteria.amount_from"
                :label="t('SearchView.document_fields.amount_from')"
                type="number"
                variant="outlined"
                density="compact"
                hide-details
                clearable
            />
        </v-col>
        <v-col cols="6" sm="3" md="1.5">
            <v-text-field
                v-model="criteria.amount_to"
                :label="t('SearchView.document_fields.amount_to')"
                type="number"
                variant="outlined"
                density="compact"
                hide-details
                clearable
            />
        </v-col>
    </v-row>
    <v-row dense class="mt-1">
        <v-col cols="12" sm="4" md="2">
            <v-select
                v-model="criteria.status"
                :label="t('SearchView.document_fields.status')"
                :items="statusOptions"
                variant="outlined"
                density="compact"
                hide-details
                clearable
            />
        </v-col>
    </v-row>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({})
    },
    documentType: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['update:modelValue']);

/**
 * Reaktives Proxy-Objekt für v-model Bindungen der Suchkriterien
 */
const criteria = new Proxy({}, {
    get(_, key) {
        return props.modelValue[key] ?? null;
    },
    set(_, key, value) {
        emit('update:modelValue', { ...props.modelValue, [key]: value });
        return true;
    }
});

/**
 * Bestimmt das Label für das Kunden-/Lieferanten-Feld basierend auf dem Dokumenttyp
 */
const cvNameLabel = computed(() => {
    const vendorTypes = ['purchase_invoice', 'purchase_order'];
    if (vendorTypes.includes(props.documentType)) {
        return t('SearchView.document_fields.vendor_name');
    }
    return t('SearchView.document_fields.customer_name');
});

/**
 * Status-Optionen für das Dropdown
 */
const statusOptions = computed(() => [
    { title: t('SearchView.document_fields.status_open'), value: 'open' },
    { title: t('SearchView.document_fields.status_closed'), value: 'closed' },
]);
</script>
