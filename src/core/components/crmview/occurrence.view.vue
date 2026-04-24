<!-- src/core/components/crmview/occurrence.view.vue -->

<template>
    <v-sheet elevation="0">
        <v-tabs v-model="occurrenceTab" color="primary" density="compact" show-arrows>
            <v-tab value="offers">
                <span class="d-none d-sm-inline">{{ labelOffers }}</span>
                <v-btn icon size="x-small" variant="text" class="ms-1" @click.stop="createNew('routes.newQuotation')">
                    <v-icon size="small">mdi-plus</v-icon>
                </v-btn>
            </v-tab>
            <v-tab value="orders">
                <span class="d-none d-sm-inline">{{ labelOrders }}</span>
                <v-btn icon size="x-small" variant="text" class="ms-1" @click.stop="createNew('routes.newOrder')">
                    <v-icon size="small">mdi-plus</v-icon>
                </v-btn>
            </v-tab>
            <v-tab value="delivery_orders">
                <span class="d-none d-sm-inline">{{ labelDeliveryOrders }}</span>
            </v-tab>
            <v-tab value="invoices">
                <span class="d-none d-sm-inline">{{ labelInvoices }}</span>
                <v-btn icon size="x-small" variant="text" class="ms-1" @click.stop="createNew('routes.newInvoice')">
                    <v-icon size="small">mdi-plus</v-icon>
                </v-btn>
            </v-tab>
            <v-tab value="reclamations">
                <span class="d-none d-sm-inline">{{ labelReclamations }}</span>
            </v-tab>
        </v-tabs>
        <v-divider></v-divider>
        <v-tabs-window v-model="occurrenceTab">
            <v-tabs-window-item value="offers">
                <v-data-table
                    :headers="baseHeaders"
                    :items="offers"
                    density="compact"
                    :items-per-page="10"
                    :no-data-text="noOffersText"
                    hover
                    @click:row="(event, { item }) => navigateTo('routes.manageQuotations', item.id)"
                    class="cursor-pointer zebra-table"
                >
                    <template #item.amount="{ item }">
                        {{ formatNumber(item.amount, locale, 2) }} {{ item.currency }}
                    </template>
                </v-data-table>
            </v-tabs-window-item>
            <v-tabs-window-item value="orders">
                <v-data-table
                    :headers="ordersHeaders"
                    :items="orders"
                    density="compact"
                    :items-per-page="10"
                    :no-data-text="noOrdersText"
                    hover
                    @click:row="(event, { item }) => navigateTo('routes.manageOrders', item.id)"
                    class="cursor-pointer zebra-table"
                >
                    <template #item.record_type="{ item }">
                        <v-icon v-if="item.record_type === 'sales_order_intake'" color="success" size="small">
                            mdi-check
                        </v-icon>
                    </template>
                    <template #item.amount="{ item }">
                        {{ formatNumber(item.amount, locale, 2) }} {{ item.currency }}
                    </template>
                </v-data-table>
            </v-tabs-window-item>
            <v-tabs-window-item value="delivery_orders">
                <v-data-table
                    :headers="deliveryOrdersHeaders"
                    :items="deliveryOrders"
                    density="compact"
                    :items-per-page="10"
                    :no-data-text="noDeliveryOrdersText"
                    hover
                    :row-props="statusRowProps"
                    @click:row="(event, { item }) => navigateTo('routes.manageDeliveryOrders', item.id)"
                    class="cursor-pointer zebra-table"
                >
                    <template #item.status="{ item }">
                        <v-chip v-if="item.closed" color="grey" size="x-small" variant="flat">{{ t('CrmView.statusClosed') }}</v-chip>
                        <v-chip v-else-if="item.delivered" color="success" size="x-small" variant="flat">{{ t('CrmView.statusDelivered') }}</v-chip>
                        <v-chip v-else color="warning" size="x-small" variant="flat">{{ t('CrmView.statusOpen') }}</v-chip>
                    </template>
                </v-data-table>
            </v-tabs-window-item>
            <v-tabs-window-item value="invoices">
                <v-data-table
                    :headers="invoicesHeaders"
                    :items="invoices"
                    density="compact"
                    :items-per-page="10"
                    :no-data-text="noInvoicesText"
                    hover
                    :row-props="invoiceRowProps"
                    @click:row="(event, { item }) => navigateToInvoice(item)"
                    class="cursor-pointer zebra-table"
                >
                    <template #item.kind="{ item }">
                        <v-chip v-if="isStorno(item)" color="error" size="x-small" variant="flat">{{ t('CrmView.storno') }}</v-chip>
                        <v-chip v-else-if="isCreditNote(item)" color="warning" size="x-small" variant="flat">{{ t('CrmView.creditNote') }}</v-chip>
                    </template>
                    <template #item.amount="{ item }">
                        {{ formatNumber(item.amount, locale, 2) }} {{ item.currency }}
                    </template>
                </v-data-table>
            </v-tabs-window-item>
            <v-tabs-window-item value="reclamations">
                <v-data-table
                    :headers="reclamationsHeaders"
                    :items="reclamations"
                    density="compact"
                    :items-per-page="10"
                    :no-data-text="noReclamationsText"
                    hover
                    :row-props="statusRowProps"
                    class="zebra-table"
                >
                    <template #item.status="{ item }">
                        <v-chip v-if="item.closed" color="grey" size="x-small" variant="flat">{{ t('CrmView.statusClosed') }}</v-chip>
                        <v-chip v-else color="warning" size="x-small" variant="flat">{{ t('CrmView.statusOpen') }}</v-chip>
                    </template>
                    <template #item.amount="{ item }">
                        {{ formatNumber(item.amount, locale, 2) }} {{ item.currency }}
                    </template>
                </v-data-table>
            </v-tabs-window-item>
        </v-tabs-window>
    </v-sheet>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { formatNumber } from '@/core/utils/numberFormat.js'

const router = useRouter();
const { t, locale } = useI18n();
const oserpData = oserpStore();

const isVendor = computed(() => oserpData.customer_vendor?.profile?.src === 'V');
const lxCarsEnabled = computed(() => oserpData.isLxCars && oserpData.isLxCars());

// Reactive computed properties that update when customer_vendor changes
const offers = computed(() => oserpData.customer_vendor?.offers || []);
const orders = computed(() => oserpData.customer_vendor?.orders || []);
const invoices = computed(() => oserpData.customer_vendor?.invoices || []);
const deliveryOrders = computed(() => oserpData.customer_vendor?.delivery_orders || []);
const reclamations = computed(() => oserpData.customer_vendor?.reclamations || []);

// Labels je nach Kontext (Kunde / Lieferant)
const labelOffers = computed(() => t(isVendor.value ? 'CrmView.vendorOffers' : 'CrmView.offers'));
const labelOrders = computed(() => t(isVendor.value ? 'CrmView.vendorOrders' : 'CrmView.orders'));
const labelInvoices = computed(() => t(isVendor.value ? 'CrmView.vendorInvoices' : 'CrmView.invoices'));
const labelDeliveryOrders = computed(() => t(isVendor.value ? 'CrmView.vendorDeliveryOrders' : 'CrmView.deliveryOrders'));
const labelReclamations = computed(() => t(isVendor.value ? 'CrmView.vendorReclamations' : 'CrmView.reclamations'));
const noOffersText = computed(() => t(isVendor.value ? 'CrmView.noVendorOffers' : 'CrmView.noOffers'));
const noOrdersText = computed(() => t(isVendor.value ? 'CrmView.noVendorOrders' : 'CrmView.noOrders'));
const noInvoicesText = computed(() => t(isVendor.value ? 'CrmView.noVendorInvoices' : 'CrmView.noInvoices'));
const noDeliveryOrdersText = computed(() => t(isVendor.value ? 'CrmView.noVendorDeliveryOrders' : 'CrmView.noDeliveryOrders'));
const noReclamationsText = computed(() => t(isVendor.value ? 'CrmView.noVendorReclamations' : 'CrmView.noReclamations'));

const occurrenceTab = ref(oserpData.getConfigValue('crm_occurrence_tab', 'offers'));

watch(occurrenceTab, (val) => {
    oserpData.setConfigValue('crm_occurrence_tab', val);
});

// Basis-Headers für Angebote
const baseHeaders = [
    { title: t('CrmView.number'), key: 'number', sortable: true },
    { title: t('CrmView.date'), key: 'date', sortable: true },
    { title: t('CrmView.description'), key: 'description', sortable: false },
    { title: t('CrmView.amount'), key: 'amount', sortable: true, align: 'end' }
];

// Rechnungen mit zusätzlicher Spalte "Art" für Gutschrift/Storno-Kennzeichnung
const invoicesHeaders = [
    { title: t('CrmView.number'), key: 'number', sortable: true },
    { title: t('CrmView.date'), key: 'date', sortable: true },
    { title: t('CrmView.description'), key: 'description', sortable: false },
    { title: '', key: 'kind', sortable: false, align: 'center', width: '90px' },
    { title: t('CrmView.amount'), key: 'amount', sortable: true, align: 'end' }
];

// Headers für Aufträge mit zusätzlicher Spalte "Bestätigt"
const ordersHeaders = [
    { title: t('CrmView.number'), key: 'number', sortable: true },
    { title: t('CrmView.date'), key: 'date', sortable: true },
    { title: t('CrmView.description'), key: 'description', sortable: false },
    { title: t('CrmView.confirmed'), key: 'record_type', sortable: true, align: 'center' },
    { title: t('CrmView.amount'), key: 'amount', sortable: true, align: 'end' }
];

// Headers für Lieferscheine: bei LxCars zusätzliche Kennzeichen-Spalte
const deliveryOrdersHeaders = computed(() => {
    const cols = [
        { title: t('CrmView.number'), key: 'number', sortable: true },
        { title: t('CrmView.date'), key: 'date', sortable: true },
        { title: t('CrmView.description'), key: 'description', sortable: false },
    ];
    if (lxCarsEnabled.value) {
        cols.push({ title: t('CrmView.licensePlate'), key: 'license_plate', sortable: true });
    }
    cols.push({ title: t('CrmView.status'), key: 'status', sortable: false, align: 'center', width: '100px' });
    return cols;
});

// Headers für Reklamationen: bei LxCars zusätzliche Kennzeichen-Spalte
const reclamationsHeaders = computed(() => {
    const cols = [
        { title: t('CrmView.number'), key: 'number', sortable: true },
        { title: t('CrmView.date'), key: 'date', sortable: true },
        { title: t('CrmView.description'), key: 'description', sortable: false },
    ];
    if (lxCarsEnabled.value) {
        cols.push({ title: t('CrmView.licensePlate'), key: 'license_plate', sortable: true });
    }
    cols.push(
        { title: t('CrmView.status'), key: 'status', sortable: false, align: 'center', width: '100px' },
        { title: t('CrmView.amount'), key: 'amount', sortable: true, align: 'end' }
    );
    return cols;
});

const isStorno = (item) => item.type === 'invoice_storno' || item.storno === true || item.storno === 't';
const isCreditNote = (item) => item.type === 'credit_note';

const invoiceRowProps = ({ item }) => {
    if (isStorno(item)) return { class: 'row-storno' };
    if (isCreditNote(item)) return { class: 'row-credit-note' };
    return {};
};

const statusRowProps = ({ item }) => {
    if (item.closed) return { class: 'row-closed' };
    return {};
};

const navigateToInvoice = (item) => {
    const routeKey = isCreditNote(item) ? 'routes.manageCreditNotes' : 'routes.manageInvoices';
    router.push(`${t(routeKey)}/${item.id}`);
};

const navigateTo = (routeKey, id) => {
    router.push(`${t(routeKey)}/${id}`);
};

const createNew = (routeKey) => {
    router.push(t(routeKey));
};
</script>

<style scoped>
.cursor-pointer :deep(tbody tr) {
    cursor: pointer;
}

.zebra-table :deep(tbody tr:nth-child(odd)) {
    background-color: rgba(0, 0, 0, 0.05);
}

.zebra-table :deep(tbody tr:hover) {
    background-color: rgba(0, 0, 0, 0.1) !important;
}

.zebra-table :deep(tbody tr.row-credit-note) {
    background-color: rgba(255, 152, 0, 0.12);
}

.zebra-table :deep(tbody tr.row-credit-note:hover) {
    background-color: rgba(255, 152, 0, 0.22) !important;
}

.zebra-table :deep(tbody tr.row-storno) {
    background-color: rgba(244, 67, 54, 0.12);
    text-decoration: line-through;
}

.zebra-table :deep(tbody tr.row-storno:hover) {
    background-color: rgba(244, 67, 54, 0.22) !important;
}

.zebra-table :deep(tbody tr.row-closed) {
    color: rgba(0, 0, 0, 0.55);
}
</style>
