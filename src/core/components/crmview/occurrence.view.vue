<!-- src/core/components/crmview/occurrence.view.vue -->

<template>
    <v-sheet elevation="0">
        <v-tabs v-model="occurrenceTab" color="primary" density="compact" show-arrows>
            <v-tab value="offers">
                <span class="d-none d-sm-inline">{{ labelOffers }}</span>
            </v-tab>
            <v-tab value="orders">
                <span class="d-none d-sm-inline">{{ labelOrders }}</span>
            </v-tab>
            <v-tab value="invoices">
                <span class="d-none d-sm-inline">{{ labelInvoices }}</span>
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
            <v-tabs-window-item value="invoices">
                <v-data-table
                    :headers="baseHeaders"
                    :items="invoices"
                    density="compact"
                    :items-per-page="10"
                    :no-data-text="noInvoicesText"
                    hover
                    @click:row="(event, { item }) => navigateTo('routes.manageInvoices', item.id)"
                    class="cursor-pointer zebra-table"
                >
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

// Reactive computed properties that update when customer_vendor changes
const offers = computed(() => oserpData.customer_vendor?.offers || []);
const orders = computed(() => oserpData.customer_vendor?.orders || []);
const invoices = computed(() => oserpData.customer_vendor?.invoices || []);

// Labels je nach Kontext (Kunde / Lieferant)
const labelOffers = computed(() => t(isVendor.value ? 'CrmView.vendorOffers' : 'CrmView.offers'));
const labelOrders = computed(() => t(isVendor.value ? 'CrmView.vendorOrders' : 'CrmView.orders'));
const labelInvoices = computed(() => t(isVendor.value ? 'CrmView.vendorInvoices' : 'CrmView.invoices'));
const noOffersText = computed(() => t(isVendor.value ? 'CrmView.noVendorOffers' : 'CrmView.noOffers'));
const noOrdersText = computed(() => t(isVendor.value ? 'CrmView.noVendorOrders' : 'CrmView.noOrders'));
const noInvoicesText = computed(() => t(isVendor.value ? 'CrmView.noVendorInvoices' : 'CrmView.noInvoices'));

const occurrenceTab = ref(oserpData.getConfigValue('crm_occurrence_tab', 'offers'));

watch(occurrenceTab, (val) => {
    oserpData.setConfigValue('crm_occurrence_tab', val);
});

// Basis-Headers für Angebote und Rechnungen
const baseHeaders = [
    { title: t('CrmView.number'), key: 'number', sortable: true },
    { title: t('CrmView.date'), key: 'date', sortable: true },
    { title: t('CrmView.description'), key: 'description', sortable: false },
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

const navigateTo = (routeKey, id) => {
    router.push(`${t(routeKey)}/${id}`);
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
</style>
