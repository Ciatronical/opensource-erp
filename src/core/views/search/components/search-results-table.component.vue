<!-- search-results-table.component.vue -->
<template>
    <v-card>
        <v-card-text>
            <v-toolbar flat>
                <v-btn
                    icon="mdi-refresh"
                    variant="text"
                    @click="$emit('refresh')"
                    :loading="loading"
                ></v-btn>
                <v-toolbar-title>{{ t('CustomerVendorSearchView.table.title') }}</v-toolbar-title>
                <v-spacer></v-spacer>

                <v-menu v-if="selected.length > 0">
                    <template v-slot:activator="{ props }">
                        <v-btn
                            color="primary"
                            variant="flat"
                            prepend-icon="mdi-chevron-down"
                            v-bind="props"
                        >
                            {{ t('CustomerVendorSearchView.buttons.bulk_actions') }} ({{ selected.length }})
                        </v-btn>
                    </template>
                    <v-list>
                        <v-list-item
                            prepend-icon="mdi-email-fast"
                            title="Brevo Marketing Mail"
                            @click="$emit('open-brevo-dialog', null)"
                        ></v-list-item>
                    </v-list>
                </v-menu>
            </v-toolbar>

            <v-data-table-server
                :headers="headers"
                :items="searchResults || []"
                :items-length="totalResults"
                :items-per-page="itemsPerPage"
                :items-per-page-options="[10, 25, 50, 100, { title: $t('SearchView.all'), value: -1 }]"
                :loading="loading"
                :model-value="selected"
                @update:model-value="$emit('update:selected', $event)"
                show-select
                select-strategy="all"
                :no-data-text="noDataText"
                hover
                class="zebra-table mt-5"
                @click:row="handleRowClick"
                @update:options="opts => $emit('update:options', opts)"
            >
                <template #item.cv_type="{ item }">
                    <template v-if="item.cv_type">
                        {{ item.cv_type === 'C' ? t('CustomerVendorSearchView.labels.customer') : t('CustomerVendorSearchView.labels.vendor') }}
                    </template>
                </template>

                <template #item.itime="{ item }">
                    {{ formatDateTime(item.itime) }}
                </template>

                <template #item.mtime="{ item }">
                    {{ formatDateTime(item.mtime) }}
                </template>

                <template #item.menu="{ item }">
                    <v-menu offset-y>
                        <template #activator="{ props }">
                            <v-btn icon="mdi-dots-vertical" variant="text" v-bind="props"></v-btn>
                        </template>
                        <v-list>
                            <v-list-item
                                v-if="loadedDataType === 'customer'"
                                prepend-icon="mdi-pencil"
                                :title="t('CustomerVendorSearchView.buttons.edit')"
                                :to="{name: 'customer-edit', params: { id: item.id }}"
                            ></v-list-item>
                            <v-list-item
                                prepend-icon="mdi-email-fast"
                                :title="t('CustomerVendorSearchView.buttons.brevo_marketing_mail')"
                                @click="$emit('open-brevo-dialog', item.id)"
                            ></v-list-item>
                        </v-list>
                    </v-menu>
                </template>
            </v-data-table-server>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatDateTime } from '@/core/utils/dateFormatter.js';

const { t } = useI18n();

/**
 * Props Definition
 */
const props = defineProps({
    searchResults: {
        type: Array,
        default: () => []
    },
    totalResults: {
        type: Number,
        default: 0
    },
    itemsPerPage: {
        type: Number,
        default: 10
    },
    loading: {
        type: Boolean,
        default: false
    },
    loadedDataType: {
        type: String,
        required: true
    },
    selected: {
        type: Array,
        default: () => []
    }
});

/**
 * Emits Definition
 */
const emit = defineEmits(['update:selected', 'update:options', 'row-click', 'refresh', 'open-brevo-dialog']);

/**
 * Berechnet die Spaltenüberschriften basierend auf dem geladenen Datentyp
 */
const headers = computed(() => {
    if (props.loadedDataType === 'customer' || props.loadedDataType === 'vendor') {
        return [
            {
                title: props.loadedDataType === 'customer'
                    ? t('CustomerVendorSearchView.fields.customer_number')
                    : t('CustomerVendorSearchView.fields.vendor_number'),
                key: 'cv_number',
                sortable: true
            },
            { title: t('CustomerVendorSearchView.fields.greeting'), key: 'greeting', sortable: true },
            { title: t('CustomerVendorSearchView.fields.name'), key: 'name', sortable: true },
            { title: t('CustomerVendorSearchView.fields.mail'), key: 'email', sortable: true },
            { title: t('CustomerVendorSearchView.fields.phone'), key: 'phone', sortable: true },
            { title: t('CustomerVendorSearchView.fields.street'), key: 'street', sortable: true },
            { title: t('CustomerVendorSearchView.fields.zipcode'), key: 'zipcode', sortable: true },
            { title: t('CustomerVendorSearchView.fields.city'), key: 'city', sortable: true },
            { title: t('CustomerVendorSearchView.fields.created_at'), key: 'itime', sortable: true },
            { title: t('CustomerVendorSearchView.fields.updated_at'), key: 'mtime', sortable: true },
            { title: '', key: 'menu', align: 'end' },
        ];
    }

    if (props.loadedDataType === 'contacts') {
        return [
            { title: t('CustomerVendorSearchView.fields.type'), key: 'cv_type', sortable: true },
            { title: t('CustomerVendorSearchView.fields.number'), key: 'cv_number', sortable: true },
            { title: t('CustomerVendorSearchView.fields.name'), key: 'cv_name', sortable: true },
            { title: t('CustomerVendorSearchView.fields.greeting'), key: 'cp_title', sortable: true },
            { title: t('CustomerVendorSearchView.fields.first_name'), key: 'cp_givenname', sortable: true },
            { title: t('CustomerVendorSearchView.fields.last_name'), key: 'cp_name', sortable: true },
            { title: t('CustomerVendorSearchView.fields.mail'), key: 'cp_email', sortable: true },
            { title: t('CustomerVendorSearchView.fields.position_title'), key: 'cp_position', sortable: true },
            { title: t('CustomerVendorSearchView.fields.project'), key: 'cp_project', sortable: true },
            { title: t('CustomerVendorSearchView.fields.department'), key: 'cp_abteilung', sortable: true },
            { title: t('CustomerVendorSearchView.fields.created_at'), key: 'itime', sortable: true },
            { title: t('CustomerVendorSearchView.fields.updated_at'), key: 'mtime', sortable: true },
            { title: '', key: 'menu', align: 'end' },
        ];
    }

    return [];
});

/**
 * Berechnet den Text für leere Suchergebnisse basierend auf dem Datentyp
 */
const noDataText = computed(() => {
    switch (props.loadedDataType) {
        case 'customer': return t('CustomerVendorSearchView.table.text_no_results_customer');
        case 'vendor': return t('CustomerVendorSearchView.table.text_no_results_vendor');
        case 'contacts': return t('CustomerVendorSearchView.table.text_no_results_person');
        default: return t('CustomerVendorSearchView.table.text_no_results_default');
    }
});

/**
 * Handler für Zeilen-Klick Events
 *
 * @param {Event} event - Das Klick-Event
 * @param {Object} row - Die angeklickte Zeile
 */
const handleRowClick = (event, row) => {
    emit('row-click', event, row);
};
</script>

<style scoped>
.zebra-table :deep(tbody tr:nth-child(odd)) {
    background-color: rgba(0, 0, 0, 0.05);
}

.zebra-table :deep(tbody tr:hover) {
    background-color: rgba(0, 0, 0, 0.1) !important;
}
</style>
