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
                <v-btn icon size="x-small" variant="text" class="ms-1" @click.stop="createNew('routes.newDeliveryOrder')">
                    <v-icon size="small">mdi-plus</v-icon>
                </v-btn>
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

        <!-- Aktionsleiste fuer ausgewaehlte Belege -->
        <v-expand-transition>
            <div v-if="activeSelection.length > 0" class="d-flex flex-wrap align-center ga-2 px-3 py-2 selection-bar">
                <v-icon size="small" color="primary">mdi-checkbox-multiple-marked-outline</v-icon>
                <span class="text-body-2 font-weight-medium">
                    {{ t('CrmView.nSelected', { n: activeSelection.length }) }}
                </span>
                <v-spacer />
                <v-btn
                    v-if="canPrintActiveTab"
                    size="small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-printer"
                    @click="openPrintDialog"
                >
                    {{ t('CrmView.print') }}
                </v-btn>
                <v-btn
                    size="small"
                    variant="tonal"
                    prepend-icon="mdi-microsoft-excel"
                    @click="exportSelected"
                >
                    {{ t('CrmView.export') }}
                </v-btn>
                <v-btn
                    size="small"
                    variant="text"
                    icon="mdi-close"
                    :title="t('CrmView.deselectAll')"
                    @click="clearActiveSelection"
                />
            </div>
        </v-expand-transition>

        <v-tabs-window v-model="occurrenceTab">
            <v-tabs-window-item value="offers">
                <v-data-table
                    v-model="selection.offers"
                    :headers="baseHeaders"
                    :items="offers"
                    :search="searchText"
                    show-select
                    return-object
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
                    v-model="selection.orders"
                    :headers="ordersHeaders"
                    :items="orders"
                    :search="searchText"
                    show-select
                    return-object
                    density="compact"
                    :items-per-page="10"
                    :no-data-text="noOrdersText"
                    hover
                    @click:row="(event, { item }) => navigateTo('routes.manageOrders', item.id)"
                    class="cursor-pointer zebra-table"
                >
                    <template #item.record_type="{ item }">
                        <v-icon v-if="item.record_type === 'sales_order'" color="success" size="small" :title="t('CrmView.confirmed')">
                            mdi-check-circle
                        </v-icon>
                        <v-icon v-else-if="item.record_type === 'sales_order_intake'" color="grey" size="small" :title="t('CrmView.notConfirmed')">
                            mdi-clock-outline
                        </v-icon>
                    </template>
                    <template #item.amount="{ item }">
                        {{ formatNumber(item.amount, locale, 2) }} {{ item.currency }}
                    </template>
                </v-data-table>
            </v-tabs-window-item>
            <v-tabs-window-item value="delivery_orders">
                <v-data-table
                    v-model="selection.delivery_orders"
                    :headers="deliveryOrdersHeaders"
                    :items="deliveryOrders"
                    :search="searchText"
                    show-select
                    return-object
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
                    v-model="selection.invoices"
                    :headers="invoicesHeaders"
                    :items="invoices"
                    :search="searchText"
                    show-select
                    return-object
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
                    v-model="selection.reclamations"
                    :headers="reclamationsHeaders"
                    :items="reclamations"
                    :search="searchText"
                    show-select
                    return-object
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

        <!-- Sammeldruck-Dialog -->
        <v-dialog v-model="printDialog.show" max-width="520">
            <v-card>
                <v-card-title class="d-flex align-center py-3">
                    <v-icon class="me-2" color="primary">mdi-printer</v-icon>
                    {{ t('CrmView.printDialogTitle', { n: printDialog.documents.length }) }}
                </v-card-title>
                <v-divider />
                <v-card-text class="py-4">
                    <div class="d-flex flex-wrap ga-1 mb-4">
                        <v-chip
                            v-for="d in printDialog.documents"
                            :key="d.id"
                            size="x-small"
                            variant="tonal"
                        >
                            {{ d.number }}
                        </v-chip>
                    </div>
                    <v-select
                        v-if="printerList.length > 0"
                        v-model="printDialog.printerId"
                        :items="printerList"
                        item-title="printer_description"
                        item-value="id"
                        :label="t('CrmView.printer')"
                        prepend-inner-icon="mdi-printer-settings"
                        variant="outlined"
                        density="compact"
                        hide-details
                        clearable
                    />
                    <p class="text-caption text-medium-emphasis mt-3 mb-0">
                        {{ t('CrmView.printDialogHint') }}
                    </p>
                </v-card-text>
                <v-divider />
                <v-card-actions class="px-4 py-3">
                    <v-btn variant="text" :disabled="!!printDialog.busy" @click="printDialog.show = false">
                        {{ t('CrmView.cancel') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn
                        variant="tonal"
                        color="error"
                        prepend-icon="mdi-file-pdf-box"
                        :loading="printDialog.busy === 'pdf'"
                        :disabled="!!printDialog.busy"
                        @click="batchOpenPdf"
                    >
                        {{ t('CrmView.showPdf') }}
                    </v-btn>
                    <v-btn
                        variant="flat"
                        color="primary"
                        prepend-icon="mdi-printer"
                        :loading="printDialog.busy === 'printer'"
                        :disabled="!printDialog.printerId || !!printDialog.busy"
                        @click="batchPrintToPrinter"
                    >
                        {{ t('CrmView.sendToPrinter') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-sheet>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as XLSX from 'xlsx'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    // Volltextfilter aus der uebergeordneten "Vorgaenge"-Kopfzeile
    searchText: { type: String, default: '' }
});

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

// Headers für Aufträge: bei LxCars zusätzliche Kennzeichen-Spalte, plus Spalte "Bestätigt"
const ordersHeaders = computed(() => {
    const cols = [
        { title: t('CrmView.number'), key: 'number', sortable: true },
        { title: t('CrmView.date'), key: 'date', sortable: true },
        { title: t('CrmView.description'), key: 'description', sortable: false },
    ];
    if (lxCarsEnabled.value) {
        cols.push({ title: t('CrmView.licensePlate'), key: 'license_plate', sortable: true });
    }
    cols.push(
        { title: t('CrmView.confirmed'), key: 'record_type', sortable: true, align: 'center' },
        { title: t('CrmView.amount'), key: 'amount', sortable: true, align: 'end' }
    );
    return cols;
});

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

// ===== Mehrfachauswahl, Druck & Export =====

// Auswahl je Tab (haelt vollstaendige Belegobjekte dank return-object)
const selection = ref({
    offers: [], orders: [], delivery_orders: [], invoices: [], reclamations: []
});

const activeSelection = computed(() => selection.value[occurrenceTab.value] || []);

function clearActiveSelection() {
    selection.value[occurrenceTab.value] = [];
}

function resetSelection() {
    selection.value = { offers: [], orders: [], delivery_orders: [], invoices: [], reclamations: [] };
}

// Bei Kunden-/Lieferantenwechsel Auswahl verwerfen
watch(() => oserpData.customer_vendor?.profile?.id, resetSelection);

// Drucken nur fuer Verkaufsbelege (Print-Backend kennt nur ar/oe + customer)
const PRINTABLE_TABS = ['offers', 'orders', 'delivery_orders', 'invoices'];
const canPrintActiveTab = computed(() => !isVendor.value && PRINTABLE_TABS.includes(occurrenceTab.value));

const printerList = computed(() =>
    (oserpData.session?.company_config?.printers || []).filter(p => !p.hide_factura)
);

// Tab + Beleg → fakturaType fuer das Print-Backend
function printTypeForItem(tab, item) {
    if (tab === 'offers') return 'quotation';
    if (tab === 'orders') return 'order';
    if (tab === 'delivery_orders') return 'delivery_order';
    if (tab === 'invoices') return isCreditNote(item) ? 'credit_note' : 'invoice';
    return 'invoice';
}

const printDialog = ref({ show: false, documents: [], printerId: null, busy: null });

function openPrintDialog() {
    const tab = occurrenceTab.value;
    printDialog.value = {
        show: true,
        documents: activeSelection.value.map(item => ({
            id: item.id,
            number: item.number,
            fakturaType: printTypeForItem(tab, item)
        })),
        printerId: printerList.value.length === 1 ? printerList.value[0].id : null,
        busy: null
    };
}

// Sammel-PDF erzeugen und im Browser-Tab oeffnen (zum Drucken via Strg+P)
async function batchOpenPdf() {
    printDialog.value.busy = 'pdf';
    try {
        const resp = await axios.post('/api/print/', {
            action: 'generateBatchPdf',
            documents: printDialog.value.documents.map(d => ({ id: d.id, fakturaType: d.fakturaType })),
            filename: `belege_${occurrenceTab.value}.pdf`,
            'content-type': 'application/pdf'
        }, { responseType: 'blob' });

        // Bei Fehlern liefert das Backend JSON statt PDF
        if (resp.data.type === 'application/json') {
            const errData = JSON.parse(await resp.data.text());
            throw new Error(errData.text || 'PDF error');
        }

        const url = URL.createObjectURL(new Blob([resp.data], { type: 'application/pdf' }));
        window.open(url, '_blank');
        printDialog.value.show = false;
    } catch {
        toasts.error(t('CrmView.printError'));
    } finally {
        printDialog.value.busy = null;
    }
}

// Jeden ausgewaehlten Beleg einzeln an den Drucker senden (bestehendes printToPrinter)
async function batchPrintToPrinter() {
    const printerId = printDialog.value.printerId;
    if (!printerId) return;
    printDialog.value.busy = 'printer';
    let ok = 0, fail = 0;
    for (const doc of printDialog.value.documents) {
        try {
            const resp = await axios.post('/api/print/', {
                action: 'printToPrinter',
                fakturaID: doc.id,
                fakturaType: doc.fakturaType,
                printerId
            });
            resp.data.success ? ok++ : fail++;
        } catch {
            fail++;
        }
    }
    printDialog.value.busy = null;
    printDialog.value.show = false;
    if (fail === 0) {
        toasts.success(t('CrmView.printSent', { n: ok }));
        clearActiveSelection();
    } else {
        toasts.error(t('CrmView.printPartial', { ok, fail }));
    }
}

// Excel-Export der ausgewaehlten Belege (Verkauf + Einkauf).
// Betrag als echte Zahl \u2192 Excel rechnet/formatiert korrekt, kein CSV-Trennzeichen-Problem.
function exportSelected() {
    const rows = activeSelection.value;
    if (!rows.length) return;

    const header = [
        t('CrmView.number'), t('CrmView.date'),
        t('CrmView.description'), t('CrmView.amount'), t('CrmView.currency')
    ];
    const data = rows.map(r => {
        const amount = Number(r.amount);
        return [
            r.number,
            r.date,
            r.description,
            Number.isFinite(amount) ? amount : null,
            r.currency || ''
        ];
    });

    const ws = XLSX.utils.aoa_to_sheet([header, ...data]);
    ws['!cols'] = [{ wch: 12 }, { wch: 12 }, { wch: 42 }, { wch: 12 }, { wch: 10 }];

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, t('CrmView.occurrences').slice(0, 31));

    const cvName = oserpData.customer_vendor?.profile?.name || 'export';
    const filename = `vorgaenge_${occurrenceTab.value}_${cvName}.xlsx`.replace(/[^\w.\-]+/g, '_');
    XLSX.writeFile(wb, filename);
}
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

.selection-bar {
    background-color: rgba(var(--v-theme-primary), 0.08);
    border-bottom: 1px solid rgba(var(--v-theme-primary), 0.2);
}
</style>
