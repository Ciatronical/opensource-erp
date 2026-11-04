<!-- src/core/views/order-search/order-search.view.vue -->
<template>
    <NavbarView :message="message" :messages="messages" />

    <v-container class="pt-2 px-2 px-sm-4" fluid>
        <!-- Schnellzugriff: zuletzt erstellte, noch offene Rechnungen. Jeder
             Knopf öffnet die Rechnung; bezahlte (bar, Bank oder Karte) fallen
             beim nächsten Laden automatisch heraus. Das Kreuz blendet eine
             Rechnung nur für den angemeldeten Mitarbeiter aus (Liste in seiner
             Mitarbeiter-Konfiguration), andere sehen sie weiterhin. -->
        <section
            v-if="recentInvoices.length"
            class="recent-invoices"
            :aria-label="t('OrderSearchView.recent_invoices.title')"
        >
            <div class="recent-invoices__label">
                <v-icon size="small" class="me-1">mdi-receipt-text-clock-outline</v-icon>
                {{ t('OrderSearchView.recent_invoices.title') }}
            </div>
            <div class="recent-invoices__grid">
                <div v-for="inv in recentInvoices" :key="inv.id" class="recent-invoice-wrap">
                    <v-btn
                        class="recent-invoice"
                        variant="tonal"
                        color="primary"
                        height="auto"
                        :title="t('OrderSearchView.recent_invoices.open', { invnumber: inv.invnumber })"
                        @click="openInvoice(inv)"
                    >
                        <span class="recent-invoice__line">
                            <span class="recent-invoice__name">{{ inv.customer_name || inv.invnumber }}</span>
                            <span class="recent-invoice__amount">{{ formatAmount(inv.amount) }}</span>
                        </span>
                        <span class="recent-invoice__meta">
                            {{ inv.invnumber }}<template v-if="inv.kennzeichen"> · {{ inv.kennzeichen }}</template>
                        </span>
                    </v-btn>
                    <button
                        type="button"
                        class="recent-invoice__hide"
                        :title="t('OrderSearchView.recent_invoices.hide')"
                        :aria-label="t('OrderSearchView.recent_invoices.hide')"
                        @click.stop="hideInvoice(inv)"
                    >
                        <v-icon size="16">mdi-close</v-icon>
                    </button>
                </div>
            </div>
        </section>

        <page-header>
            <template #title>
                <v-icon class="me-2" color="primary">mdi-clipboard-text-search-outline</v-icon>
                {{ t('OrderSearchView.title') }}
            </template>
            <v-chip v-if="hasSearched" size="small" variant="tonal" color="primary">
                {{ searchResults.length }} {{ t('OrderSearchView.table.results_count') }}
            </v-chip>
            <template #actions>
                <v-btn
                    v-if="hasSearchCriteria"
                    variant="text"
                    prepend-icon="mdi-filter-remove"
                    size="small"
                    @click="reset"
                >
                    {{ t('OrderSearchView.buttons.reset') }}
                </v-btn>
            </template>
        </page-header>

        <!-- Filter: ein Raster, das umbricht; Zeilenabstand kommt aus row-gap -->
        <v-row dense class="filter-row" @keydown.enter.capture="handleEnterKey">
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
        <v-card class="results-card" :loading="loading">
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
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import * as toasts from '@/core/utils/toasts.js';
import { formatDate } from '@/core/utils/dateFormatter.js';
import { getValues, getColorMap } from '@/core/utils/configColors.js';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { useViewHistory } from '@/core/composables/useViewHistory.js';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import PageHeader from '@/core/components/layout/page-header.vue';
import { entityRoute } from '@/core/constants/routes.js';
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
const recentInvoices = ref([]);
const hasSearched = ref(false);

// Je Mitarbeiter ausgeblendete Rechnungen: [{ id, t }] als JSON-Text in der
// Mitarbeiter-Konfiguration (employee_config_oserp über den Store). Einträge
// älter als 30 Tage fallen beim nächsten Schreiben weg — die Rechnung ist dann
// ohnehin aus dem 14-Tage-Fenster des Schnellzugriffs heraus.
const HIDDEN_KEY = 'order_search_hidden_invoices';
const HIDDEN_MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;

const readHiddenInvoices = () => {
    try {
        const list = JSON.parse(store.getConfigValue(HIDDEN_KEY, '[]') || '[]');
        return Array.isArray(list) ? list.filter(e => e && Number(e.id) > 0) : [];
    } catch {
        return [];
    }
};

const writeHiddenInvoices = (list) => {
    const cutoff = Date.now() - HIDDEN_MAX_AGE_MS;
    const kept = list.filter(e => !e.t || new Date(e.t).getTime() >= cutoff);
    store.setConfigValue(HIDDEN_KEY, JSON.stringify(kept));
};

const hiddenInvoiceIds = () => readHiddenInvoices().map(e => Number(e.id));

// Terminal-Checkouts ohne geklärtes Ergebnis (Rechnung verlassen, bevor das
// Terminal antwortete) mit SumUp abgleichen — sonst bliebe eine am Terminal
// kassierte Rechnung im Schnellzugriff stehen, bis jemand sie öffnet. Je
// Rechnung nur einmal pro Seitenaufruf, im Hintergrund, Fehler still.
const syncedCardInvoices = new Set();
const syncUnresolvedCardPayments = async (invoices) => {
    const todo = invoices.filter(i => Number(i.card_unresolved) > 0 && !syncedCardInvoices.has(i.id));
    if (!todo.length) return;
    todo.forEach(i => syncedCardInvoices.add(i.id));
    const results = await Promise.allSettled(todo.map(i =>
        axios.post('/api/payment/', { action: 'sumupSyncCheckouts', fakturaID: i.id })
    ));
    const paidNow = results.some(r => r.status === 'fulfilled' && r.value?.data?.payload?.new_payments?.length);
    if (paidNow) loadData();
};

// Kommt der Anwender von der Kasse oder aus einem anderen Fenster zurück,
// soll eine inzwischen bezahlte Rechnung sofort aus dem Schnellzugriff
// verschwinden — deshalb beim Sichtbarwerden neu laden.
const reloadWhenVisible = () => {
    if (document.visibilityState === 'visible' && !loading.value) loadData();
};

onMounted(() => {
    loadData();
    document.addEventListener('visibilitychange', reloadWhenVisible);
    window.addEventListener('focus', reloadWhenVisible);
});

onUnmounted(() => {
    document.removeEventListener('visibilitychange', reloadWhenVisible);
    window.removeEventListener('focus', reloadWhenVisible);
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
            where: where,
            hidden_invoice_ids: hiddenInvoiceIds()
        });

        if (response.data.sql_error) {
            toasts.error(response.data.error_message || t('OrderSearchView.toasts.error_loading_search_results'));
            searchResults.value = [];
        } else if (response.data.success) {
            searchResults.value = response.data.payload.results ?? [];
            recentInvoices.value = response.data.payload.recent_invoices ?? [];
            syncUnresolvedCardPayments(recentInvoices.value);
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

const openInvoice = (inv) => {
    saveToHistory({
        type: 'invoice',
        id: inv.id,
        title: inv.invnumber,
        subtitle: inv.customer_name || '',
        route: entityRoute('invoice', inv.id)
    });
    router.push(entityRoute('invoice', inv.id));
};

// Nur für den angemeldeten Mitarbeiter ausblenden; Rückgängig per Klick auf
// die Einblendung
const hideInvoice = (inv) => {
    const list = readHiddenInvoices().filter(e => Number(e.id) !== Number(inv.id));
    writeHiddenInvoices([...list, { id: Number(inv.id), t: new Date().toISOString() }]);
    recentInvoices.value = recentInvoices.value.filter(i => i.id !== inv.id);
    toasts.info(t('OrderSearchView.recent_invoices.hidden_toast', { invnumber: inv.invnumber }), {
        onClick: () => {
            writeHiddenInvoices(readHiddenInvoices().filter(e => Number(e.id) !== Number(inv.id)));
            loadData();
        }
    });
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
.recent-invoices {
    margin-bottom: var(--oserp-gap);
}
.recent-invoices__label {
    display: flex;
    align-items: center;
    font-size: 0.8125rem;
    color: var(--oserp-text-muted);
    margin-bottom: var(--oserp-gap-xs);
}
/* Gleich breite Knöpfe, so viele je Zeile wie Platz ist */
.recent-invoices__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: var(--oserp-gap-sm);
}
.recent-invoice-wrap {
    position: relative;
    min-width: 0;
}
.recent-invoice {
    width: 100%;
    /* rechts Platz für das Kreuz, mit Luft zum Betrag */
    padding: var(--oserp-gap-sm) calc(var(--oserp-gap-xl) + var(--oserp-gap-lg)) var(--oserp-gap-sm) var(--oserp-gap);
    min-width: 0;
}
/* Kreuz zum Ausblenden: schlichtes Element über der Ecke — keine runde
   Fläche, kein Rahmen, kein Knopf im Knopf */
.recent-invoice__hide {
    position: absolute;
    top: var(--oserp-gap-sm);
    right: var(--oserp-gap-sm);
    display: inline-flex;
    padding: 0;
    border: 0;
    background: none;
    color: rgb(var(--v-theme-primary));
    line-height: 1;
    cursor: pointer;
    opacity: 0.55;
}
.recent-invoice-wrap:hover .recent-invoice__hide,
.recent-invoice__hide:hover,
.recent-invoice__hide:focus-visible {
    opacity: 1;
    outline: none;
}
.recent-invoice :deep(.v-btn__content) {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
    min-width: 0;
    text-align: left;
}
/* Name links (gekürzt), Betrag rechts – der Betrag bleibt immer lesbar */
.recent-invoice__line {
    display: flex;
    align-items: baseline;
    gap: var(--oserp-gap-sm);
    width: 100%;
    min-width: 0;
}
.recent-invoice__name {
    flex: 1 1 auto;
    min-width: 0;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.recent-invoice__amount {
    flex: 0 0 auto;
    font-weight: 500;
    white-space: nowrap;
}
.recent-invoice__meta {
    width: 100%;
    font-size: 0.75rem;
    font-weight: 400;
    opacity: 0.8;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.filter-row {
    row-gap: var(--oserp-gap-sm);
}
.results-card {
    margin-top: var(--oserp-gap-lg);
}
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
