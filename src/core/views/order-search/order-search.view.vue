<!-- src/core/views/order-search/order-search.view.vue -->
<template>
    <NavbarView :message="message" :messages="messages" />

    <v-container class="pt-2 px-2 px-sm-4" fluid>
        <v-row class="mb-2 align-center">
            <v-col>
                <h1 class="text-h5 text-sm-h4 d-flex align-center">
                    <v-icon class="me-2" color="primary">mdi-clipboard-text-search-outline</v-icon>
                    {{ t('OrderSearchView.title') }}
                    <v-chip
                        v-if="hasSearched"
                        class="ms-3"
                        size="small"
                        variant="tonal"
                        color="primary"
                    >
                        {{ searchResults.length }} {{ t('OrderSearchView.table.results_count') }}
                    </v-chip>
                </h1>
            </v-col>
            <v-col cols="auto">
                <v-btn
                    v-if="hasSearchCriteria"
                    variant="text"
                    prepend-icon="mdi-filter-remove"
                    @click="reset"
                    size="small"
                >
                    {{ t('OrderSearchView.buttons.reset') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Filter -->
        <v-row dense @keydown.enter.capture="handleEnterKey">
            <v-col cols="12" sm="6" md="2">
                <v-text-field
                    v-model="searchCriteria.ordnumber"
                    :label="t('OrderSearchView.fields.ordnumber')"
                    prepend-inner-icon="mdi-pound"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-text-field
                    v-model="searchCriteria.customer_name"
                    :label="t('OrderSearchView.fields.customer_name')"
                    prepend-inner-icon="mdi-account"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-text-field
                    v-model="searchCriteria.transaction_description"
                    :label="t('OrderSearchView.fields.first_instruction')"
                    prepend-inner-icon="mdi-text-short"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="6" sm="3" md="2">
                <v-select
                    v-model="searchCriteria.status"
                    :label="t('OrderSearchView.fields.status')"
                    :items="statusFilterOptions"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="6" sm="3" md="2">
                <v-select
                    v-model="searchCriteria.kfz_ort"
                    :label="t('OrderSearchView.fields.location')"
                    :items="kfzOrtFilterOptions"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
        </v-row>

        <v-row dense class="mt-1">
            <v-col cols="6" sm="3">
                <v-text-field
                    v-model="searchCriteria.transdate_from"
                    :label="t('OrderSearchView.fields.transdate_from')"
                    type="date"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="6" sm="3">
                <v-text-field
                    v-model="searchCriteria.transdate_to"
                    :label="t('OrderSearchView.fields.transdate_to')"
                    type="date"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="6" sm="3">
                <v-text-field
                    v-model="searchCriteria.bringetermin_from"
                    :label="t('OrderSearchView.fields.bringetermin_from')"
                    type="date"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="6" sm="3">
                <v-text-field
                    v-model="searchCriteria.bringetermin_to"
                    :label="t('OrderSearchView.fields.bringetermin_to')"
                    type="date"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
        </v-row>

        <!-- Ergebnistabelle -->
        <v-card class="mt-4" :loading="loading">
            <v-data-table-server
                :headers="headers"
                :items="displayResults"
                :items-length="sortedResults.length"
                v-model:sort-by="tableSortBy"
                v-model:page="page"
                v-model:items-per-page="itemsPerPage"
                :items-per-page-options="[10, 25, 50, 100, 200, -1]"
                :loading="loading"
                :no-data-text="t('OrderSearchView.table.text_no_results')"
                :row-props="getRowProps"
                hover
                class="zebra-table"
                @click:row="onRowClick"
            >
                <template #item.ordnumber="{ item }">
                    <span class="font-weight-medium">{{ item.ordnumber }}</span>
                </template>

                <template #item.transdate="{ item }">
                    {{ formatDate(item.transdate) }}
                </template>

                <template #item.bringetermin="{ item }">
                    {{ formatDate(item.bringetermin) }}
                </template>

                <template #item.amount="{ item }">
                    <span class="text-no-wrap">{{ formatAmount(item.amount) }}</span>
                </template>

                <template #item.oe_ext_status="{ item }">
                    <v-chip
                        v-if="item.oe_ext_status"
                        :color="statusColorMap[item.oe_ext_status] || 'default'"
                        variant="tonal"
                        size="small"
                    >
                        {{ item.oe_ext_status }}
                    </v-chip>
                </template>

                <template #item.kfz_ort="{ item }">
                    <v-chip
                        v-if="item.kfz_ort"
                        :color="kfzOrtColorMap[item.kfz_ort] || 'default'"
                        variant="tonal"
                        size="small"
                    >
                        {{ item.kfz_ort }}
                    </v-chip>
                </template>
            </v-data-table-server>
        </v-card>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import * as toasts from '@/core/utils/toasts.js';
import { formatDate } from '@/core/utils/dateFormatter.js';
import { getValues, getColorMap } from '@/core/utils/configColors.js';
import { oserpStore } from '@/core/stores/oserp.store.js';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import router from '@/core/router/index.js';

const { t } = useI18n();
const store = oserpStore();

const props = defineProps({
    message: {
        type: Object,
        default: () => ({ title: '', description: '', type: 'info' })
    },
    messages: {
        type: Array,
        default: () => []
    }
});

// Reactive state
const loading = ref(false);
const searchCriteria = ref({});
const searchResults = ref([]);
const hasSearched = ref(false);

onMounted(() => {
    loadData();
});

watch(searchCriteria, () => {
    loadData();
}, { deep: true });

// Config-basierte Filter-Optionen und Farben
const statusFilterOptions = computed(() => {
    const raw = store.session?.company_config?.defaults_oserp?.lxcars_order_statuses || ''
    return getValues(raw)
});

const kfzOrtFilterOptions = computed(() => {
    const raw = store.session?.company_config?.defaults_oserp?.lxcars_kfz_ort_options || ''
    return getValues(raw)
});

const statusColorMap = computed(() => {
    const raw = store.session?.company_config?.defaults_oserp?.lxcars_order_statuses || ''
    return getColorMap(raw)
});

const kfzOrtColorMap = computed(() => {
    const raw = store.session?.company_config?.defaults_oserp?.lxcars_kfz_ort_options || ''
    return getColorMap(raw)
});

const hasSearchCriteria = computed(() => {
    return Object.values(searchCriteria.value).some(value => {
        if (value === null || value === undefined) return false;
        if (value === false) return true;
        if (typeof value === 'string') return value.trim() !== '';
        return true;
    });
});

const headers = computed(() => [
    { title: t('OrderSearchView.fields.ordnumber'), key: 'ordnumber', sortable: true },
    { title: t('OrderSearchView.fields.customer_name'), key: 'customer_name', sortable: true },
    { title: t('OrderSearchView.fields.hersteller'), key: 'hersteller', sortable: true },
    { title: t('OrderSearchView.fields.first_instruction'), key: 'first_instruction', sortable: true },
    { title: t('OrderSearchView.fields.status'), key: 'oe_ext_status', sortable: true },
    { title: t('OrderSearchView.fields.location'), key: 'kfz_ort', sortable: true },
    { title: t('OrderSearchView.fields.transdate'), key: 'transdate', sortable: true },
    { title: t('OrderSearchView.fields.bringetermin'), key: 'bringetermin', sortable: true },
    { title: t('OrderSearchView.fields.amount'), key: 'amount', sortable: true, align: 'end' },
    { title: t('OrderSearchView.fields.employee'), key: 'employee_name', sortable: true },
]);

function formatAmount(value) {
    if (value === null || value === undefined) return '';
    return Number(value).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' \u20AC';
}

const handleEnterKey = (event) => {
    const target = event.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
        event.preventDefault();
        loadData();
    }
};

const loadData = async () => {
    loading.value = true;

    const where = {};
    for (const [key, value] of Object.entries(searchCriteria.value)) {
        if (value === null || value === undefined) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        where[key] = value;
    }

    try {
        const response = await axios.post('/api/faktura/', {
            action: 'searchOrders',
            where: where
        });

        if (response.data.sql_error) {
            toasts.error(response.data.error_message || t('OrderSearchView.toasts.error_loading_search_results'));
            searchResults.value = [];
        } else if (response.data.success) {
            searchResults.value = response.data.payload.results ?? [];
        } else {
            toasts.error(t('OrderSearchView.toasts.error_loading_search_results'));
            searchResults.value = [];
        }
    } catch (error) {
        toasts.error(t('OrderSearchView.toasts.error_loading_search_results'));
        searchResults.value = [];
    }

    hasSearched.value = true;
    loading.value = false;
};

const reset = () => {
    searchCriteria.value = {};
    loadData();
};

const today = new Date().toISOString().slice(0, 10);
const tableSortBy = ref([{ key: 'bringetermin', order: 'desc' }]);
const page = ref(1);
const itemsPerPage = ref(100);

// Zukunfts-Aufträge immer zuerst, innerhalb jeder Gruppe nach User-Sortierung
const sortedResults = computed(() => {
    const future = []
    const rest = []
    for (const item of searchResults.value) {
        if (item.bringetermin && item.bringetermin > today) {
            future.push(item)
        } else {
            rest.push(item)
        }
    }

    const key = tableSortBy.value[0]?.key || 'transdate'
    const asc = tableSortBy.value[0]?.order === 'asc'

    const compare = (a, b) => {
        let va = a[key] ?? (key === 'bringetermin' ? a['transdate'] : '') ?? ''
        let vb = b[key] ?? (key === 'bringetermin' ? b['transdate'] : '') ?? ''
        if (typeof va === 'string') { va = va.toLowerCase(); vb = String(vb).toLowerCase() }
        if (va < vb) return asc ? -1 : 1
        if (va > vb) return asc ? 1 : -1
        return 0
    }

    return [...future.sort(compare), ...rest.sort(compare)]
});

const displayResults = computed(() => {
    if (itemsPerPage.value === -1) return sortedResults.value
    const start = (page.value - 1) * itemsPerPage.value
    return sortedResults.value.slice(start, start + itemsPerPage.value)
});

watch(tableSortBy, () => { page.value = 1 });

const getRowProps = ({ item }) => {
    if (item.bringetermin && item.bringetermin > today) {
        return { class: 'future-order' }
    }
    return {}
};

const onRowClick = (event, row) => {
    router.push({
        name: 'faktura-order-view',
        params: { id: row.item.id }
    });
};
</script>

<style scoped>
.zebra-table :deep(tbody tr:nth-child(odd)) {
    background-color: rgba(0, 0, 0, 0.03);
}
.zebra-table :deep(tbody tr:hover) {
    background-color: rgba(var(--v-theme-primary), 0.08) !important;
}
.zebra-table :deep(tbody tr) {
    cursor: pointer;
}
.zebra-table :deep(tbody tr.future-order) {
    opacity: 0.45;
}
</style>
