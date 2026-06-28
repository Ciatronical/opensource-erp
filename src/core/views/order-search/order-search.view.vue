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
            <v-col cols="12" md="8">
                <v-text-field
                    v-model="searchCriteria.q"
                    :label="t('OrderSearchView.fields.q')"
                    :placeholder="t('OrderSearchView.fields.q_hint')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                    autofocus
                />
            </v-col>
            <v-col cols="6" sm="3" md="2">
                <v-select
                    v-model="searchCriteria.status"
                    :label="t('OrderSearchView.fields.status')"
                    :items="statusFilterOptions"
                    item-title="title"
                    item-value="value"
                    variant="outlined"
                    density="compact"
                    hide-details
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
                    <div class="d-flex align-center ga-2">
                        <span class="font-weight-medium">{{ item.ordnumber }}</span>
                        <v-chip
                            v-if="item.intern"
                            color="warning"
                            variant="tonal"
                            size="x-small"
                            prepend-icon="mdi-hammer-wrench"
                        >
                            {{ t('OrderSearchView.table.intern') }}
                        </v-chip>
                    </div>
                </template>

                <template #item.customer_name="{ item }">
                    <span v-if="item.customer_name" class="customer-name-hover">
                        {{ item.customer_name }}
                        <v-tooltip
                            v-if="hasAddress(item)"
                            activator="parent"
                            location="top"
                            open-delay="200"
                            content-class="address-bubble"
                        >
                            <div class="font-weight-medium mb-1">{{ item.customer_name }}</div>
                            <div v-if="item.customer_street">{{ item.customer_street }}</div>
                            <div v-if="item.customer_zipcode || item.customer_city">
                                {{ [item.customer_zipcode, item.customer_city].filter(Boolean).join(' ') }}
                            </div>
                            <div v-if="item.customer_country">{{ item.customer_country }}</div>
                            <div v-if="item.customer_phone" class="mt-1">
                                <v-icon size="x-small" class="me-1">mdi-phone</v-icon>{{ item.customer_phone }}
                            </div>
                        </v-tooltip>
                    </span>
                    <span v-else>-</span>
                </template>

                <template #item.kennzeichen="{ item }">
                    <span v-if="item.kennzeichen" class="font-weight-medium text-no-wrap">{{ item.kennzeichen }}</span>
                    <span v-else>-</span>
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
import { useViewHistory } from '@/core/composables/useViewHistory.js';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import router from '@/core/router/index.js';

const { t } = useI18n();
const store = oserpStore();
const { saveToHistory } = useViewHistory();

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

// Status-Sentinels (siehe backend/api/faktura/order_search.php)
const NOT_HIDDEN = '__not_hidden__';  // Default: Hide-Status ausblenden ("Nicht abgerechnet")
const ALL_STATUS = '__all__';         // alle Status anzeigen

// Reactive state
const loading = ref(false);
const searchCriteria = ref({ status: NOT_HIDDEN });
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
    const cfg = store.session?.company_config?.defaults_oserp || {}
    const hideStatus = cfg.lxcars_order_hide_status || ''
    const notHiddenLabel = hideStatus
        ? t('OrderSearchView.fields.status_not_hidden', { status: hideStatus.toLowerCase() })
        : t('OrderSearchView.fields.status_open')
    return [
        { title: notHiddenLabel, value: NOT_HIDDEN },
        { title: t('OrderSearchView.fields.status_all'), value: ALL_STATUS },
        ...getValues(cfg.lxcars_order_statuses || '').map(s => ({ title: s, value: s })),
    ]
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
    return Object.entries(searchCriteria.value).some(([key, value]) => {
        if (key === 'status' && value === NOT_HIDDEN) return false; // Default zählt nicht
        if (value === null || value === undefined) return false;
        if (value === false) return true;
        if (typeof value === 'string') return value.trim() !== '';
        return true;
    });
});

const headers = computed(() => [
    { title: t('OrderSearchView.fields.ordnumber'), key: 'ordnumber', sortable: true },
    { title: t('OrderSearchView.fields.customer_name'), key: 'customer_name', sortable: true },
    { title: t('OrderSearchView.fields.kennzeichen'), key: 'kennzeichen', sortable: true },
    { title: t('OrderSearchView.fields.hersteller'), key: 'hersteller', sortable: true },
    { title: t('OrderSearchView.fields.first_instruction'), key: 'first_instruction', sortable: true },
    { title: t('OrderSearchView.fields.status'), key: 'oe_ext_status', sortable: true },
    { title: t('OrderSearchView.fields.location'), key: 'kfz_ort', sortable: true },
    { title: t('OrderSearchView.fields.transdate'), key: 'transdate', sortable: true },
    { title: t('OrderSearchView.fields.bringetermin'), key: 'bringetermin', sortable: true },
    { title: t('OrderSearchView.fields.amount'), key: 'amount', sortable: true, align: 'end' },
    { title: t('OrderSearchView.fields.mechanic'), key: 'mechanic_name', sortable: true },
    { title: t('OrderSearchView.fields.employee'), key: 'employee_name', sortable: true },
]);

function formatAmount(value) {
    if (value === null || value === undefined) return '';
    return Number(value).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' \u20AC';
}

// Adressbubble nur zeigen, wenn \u00FCberhaupt Adressdaten vorhanden sind
function hasAddress(item) {
    return Boolean(
        item.customer_street ||
        item.customer_zipcode ||
        item.customer_city ||
        item.customer_country ||
        item.customer_phone
    );
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
    searchCriteria.value = { status: NOT_HIDDEN };
    loadData();
};

const today = new Date().toISOString().slice(0, 10);
const tableSortBy = ref([{ key: 'bringetermin', order: 'desc' }]);
const page = ref(1);
const itemsPerPage = ref(100);

// Interne Aufträge immer ganz unten, davor Zukunfts-Aufträge zuerst,
// innerhalb jeder Gruppe nach User-Sortierung
const sortedResults = computed(() => {
    const future = []
    const rest = []
    const intern = []
    for (const item of searchResults.value) {
        if (item.intern) {
            intern.push(item)
        } else if (item.bringetermin && item.bringetermin.slice(0, 10) > today) {
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

    return [...future.sort(compare), ...rest.sort(compare), ...intern.sort(compare)]
});

const displayResults = computed(() => {
    if (itemsPerPage.value === -1) return sortedResults.value
    const start = (page.value - 1) * itemsPerPage.value
    return sortedResults.value.slice(start, start + itemsPerPage.value)
});

watch(tableSortBy, () => { page.value = 1 });

const getRowProps = ({ item }) => {
    if (item.intern) {
        return { class: 'intern-order' }
    }
    if (item.bringetermin && item.bringetermin.slice(0, 10) > today) {
        return { class: 'future-order' }
    }
    return {}
};

const onRowClick = (event, row) => {
    const item = row.item;
    saveToHistory({
        type: 'order',
        id: item.id,
        title: item.ordnumber,
        subtitle: item.customer_name || '',
        route: { name: 'faktura-order-view', params: { id: item.id } }
    });
    router.push({
        name: 'faktura-order-view',
        params: { id: item.id }
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
.zebra-table :deep(tbody tr.intern-order) {
    background-color: rgba(var(--v-theme-warning), 0.08);
}
.zebra-table :deep(tbody tr.intern-order:hover) {
    background-color: rgba(var(--v-theme-warning), 0.16) !important;
}
.customer-name-hover {
    cursor: help;
    text-decoration: underline dotted rgba(var(--v-theme-on-surface), 0.4);
    text-underline-offset: 2px;
}
:deep(.address-bubble) {
    opacity: 1 !important;
}
</style>
