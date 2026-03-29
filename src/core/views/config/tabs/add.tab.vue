<!-- src/core/views/config/tabs/add.tab.vue -->

<template>
    <v-container fluid class="pa-6">
        <!-- Einleitungstext -->
        <div class="mb-6">
            <h2 class="text-h5 font-weight-bold mb-2">
                {{ t('add_fields.title') }}
            </h2>
            <p class="text-body-1 text-grey-darken-1">
                {{ t('add_fields.description') }}
            </p>
        </div>

        <!-- Accordion für Systemeinstellungen -->
        <v-expansion-panels
            v-model="openPanels"
            multiple
            variant="accordion"
        >
            <!-- 1. Buchungsgruppen -->
            <v-expansion-panel value="buchungsgruppen">
                <v-expansion-panel-title>
                    <div class="d-flex align-center">
                        <v-icon class="me-3" color="primary" size="large">mdi-file-document-multiple</v-icon>
                        <div>
                            <div class="text-h6">{{ t('add_fields.buchungsgruppen.title') }}</div>
                            <div class="text-caption text-grey-darken-1">
                                {{ buchungsgruppenList.length }} {{ t('add_fields.entries') }}
                            </div>
                        </div>
                    </div>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <!-- Beschreibung -->
                    <v-alert
                        type="info"
                        variant="tonal"
                        class="mb-4"
                        density="compact"
                    >
                        {{ t('add_fields.buchungsgruppen.description') }}
                    </v-alert>

                    <!-- Bestehende Buchungsgruppen -->
                    <div v-if="buchungsgruppenList.length > 0" class="mb-4">
                        <div class="text-subtitle-2 mb-2">{{ t('add_fields.existing') }}:</div>
                        <v-list density="compact" class="bg-grey-lighten-5 rounded">
                            <draggable
                                v-model="sortableBuchungsgruppen"
                                item-key="id"
                                handle=".drag-handle"
                                @end="saveBuchungsgruppeOrder"
                            >
                                <template #item="{ element: item }">
                                    <v-list-item
                                        :key="item.id"
                                        @click="editBuchungsgruppe(item)"
                                        class="item-hover"
                                    >
                                        <template v-slot:prepend>
                                            <v-icon class="drag-handle me-2" size="small" style="cursor: move">mdi-drag</v-icon>
                                            <v-icon size="small">mdi-file-document</v-icon>
                                        </template>
                                        <v-list-item-title>{{ item.description }}</v-list-item-title>
                                        <template v-slot:append>
                                            <v-btn
                                                icon
                                                variant="text"
                                                size="small"
                                                @click.stop="editBuchungsgruppe(item)"
                                            >
                                                <v-icon size="small">mdi-pencil</v-icon>
                                            </v-btn>
                                        </template>
                                    </v-list-item>
                                </template>
                            </draggable>
                        </v-list>
                    </div>

                    <!-- Neu erstellen Button -->
                    <v-btn
                        color="primary"
                        variant="elevated"
                        prepend-icon="mdi-plus"
                        @click="createBuchungsgruppe"
                    >
                        {{ t('add_fields.buchungsgruppen.create_new') }}
                    </v-btn>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- 2. Steuerzonen -->
            <v-expansion-panel value="taxzones">
                <v-expansion-panel-title>
                    <div class="d-flex align-center">
                        <v-icon class="me-3" color="primary" size="large">mdi-earth</v-icon>
                        <div>
                            <div class="text-h6">{{ t('add_fields.taxzones.title') }}</div>
                            <div class="text-caption text-grey-darken-1">
                                {{ taxzonesList.length }} {{ t('add_fields.entries') }}
                            </div>
                        </div>
                    </div>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <!-- Beschreibung -->
                    <v-alert
                        type="info"
                        variant="tonal"
                        class="mb-4"
                        density="compact"
                    >
                        {{ t('add_fields.taxzones.description') }}
                    </v-alert>

                    <!-- Bestehende Steuerzonen -->
                    <div v-if="taxzonesList.length > 0" class="mb-4">
                        <div class="text-subtitle-2 mb-2">{{ t('add_fields.existing') }}:</div>
                        <v-list density="compact" class="bg-grey-lighten-5 rounded">
                            <draggable
                                v-model="sortableTaxzones"
                                item-key="id"
                                handle=".drag-handle"
                                @end="saveTaxzoneOrder"
                            >
                                <template #item="{ element: item }">
                                    <v-list-item
                                        :key="item.id"
                                        @click="editTaxzone(item)"
                                        class="item-hover"
                                    >
                                        <template v-slot:prepend>
                                            <v-icon class="drag-handle me-2" size="small" style="cursor: move">mdi-drag</v-icon>
                                            <v-icon size="small">mdi-earth</v-icon>
                                        </template>
                                        <v-list-item-title>{{ item.description }}</v-list-item-title>
                                        <v-list-item-subtitle v-if="item.obsolete" class="text-red">
                                            {{ t('add_fields.obsolete') }}
                                        </v-list-item-subtitle>
                                        <template v-slot:append>
                                            <v-btn
                                                icon
                                                variant="text"
                                                size="small"
                                                @click.stop="editTaxzone(item)"
                                            >
                                                <v-icon size="small">mdi-pencil</v-icon>
                                            </v-btn>
                                        </template>
                                    </v-list-item>
                                </template>
                            </draggable>
                        </v-list>
                    </div>

                    <!-- Neu erstellen Button -->
                    <v-btn
                        color="primary"
                        variant="elevated"
                        prepend-icon="mdi-plus"
                        @click="createTaxzone"
                    >
                        {{ t('add_fields.taxzones.create_new') }}
                    </v-btn>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- 3. Steuern -->
            <v-expansion-panel value="taxes">
                <v-expansion-panel-title>
                    <div class="d-flex align-center">
                        <v-icon class="me-3" color="primary" size="large">mdi-percent</v-icon>
                        <div>
                            <div class="text-h6">{{ t('add_fields.taxes.title') }}</div>
                            <div class="text-caption text-grey-darken-1">
                                {{ taxesList.length }} {{ t('add_fields.entries') }}
                            </div>
                        </div>
                    </div>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <!-- Beschreibung -->
                    <v-alert
                        type="info"
                        variant="tonal"
                        class="mb-4"
                        density="compact"
                    >
                        {{ t('add_fields.taxes.description') }}
                    </v-alert>

                    <!-- Bestehende Steuern -->
                    <div v-if="taxesList.length > 0" class="mb-4">
                        <v-table density="compact" hover>
                            <thead>
                                <tr>
                                    <th class="text-no-wrap font-weight-bold">{{ t('tax.taxkey') }}</th>
                                    <th class="text-no-wrap font-weight-bold">{{ t('tax.taxdescription') }}</th>
                                    <th class="text-no-wrap font-weight-bold">{{ t('tax.rate') }}</th>
                                    <th class="text-no-wrap font-weight-bold">{{ t('tax.chart_auto') }}</th>
                                    <th class="text-no-wrap font-weight-bold">{{ t('tax.used') }}</th>
                                    <!-- <th class="text-no-wrap font-weight-bold">{{ t('tax.chart_description') }}</th>
                                    <th class="text-no-wrap font-weight-bold">{{ t('tax.skonto_sales_chart') }}</th>
                                    <th class="text-no-wrap font-weight-bold">{{ t('tax.skonto_purchase_chart') }}</th> -->
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="item in taxesList"
                                    :key="item.id"
                                    @click="editTax(item)"
                                    style="cursor: pointer"
                                >
                                    <td class="text-no-wrap"><strong>{{ item.taxkey }}</strong></td>
                                    <td class="text-no-wrap">{{ item.taxdescription }}</td>
                                    <td class="text-no-wrap">{{ (item.rate * 100).toFixed(2) }}%</td>
                                    <td class="text-no-wrap">{{ getChartByIdFromStore(item.chart_id)?.accno || '' }}</td>
                                    <td class="text-center">
                                        <v-icon v-if="item.used" size="small" color="success">mdi-check</v-icon>
                                    </td>
                                    <!-- <td>{{ getChartByIdFromStore(item.chart_id)?.description || '' }}</td>
                                    <td>{{ getSkontoChartDisplay(item.skonto_sales_chart_id) }}</td>
                                    <td>{{ getSkontoChartDisplay(item.skonto_purchase_chart_id) }}</td> -->
                                    <td class="text-no-wrap">
                                        <v-btn
                                            icon
                                            variant="text"
                                            size="small"
                                            @click.stop="editTax(item)"
                                        >
                                            <v-icon size="small">mdi-pencil</v-icon>
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </div>

                    <!-- Neu erstellen Button -->
                    <v-btn
                        color="primary"
                        variant="elevated"
                        prepend-icon="mdi-plus"
                        @click="createTax"
                    >
                        {{ t('add_fields.taxes.create_new') }}
                    </v-btn>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- 4. Bankkonten -->
            <v-expansion-panel value="bank_accounts">
                <v-expansion-panel-title>
                    <div class="d-flex align-center">
                        <v-icon class="me-3" color="primary" size="large">mdi-bank</v-icon>
                        <div>
                            <div class="text-h6">{{ t('add_fields.bank_accounts.title') }}</div>
                            <div class="text-caption text-grey-darken-1">
                                {{ bankAccountsList.length }} {{ t('add_fields.entries') }}
                            </div>
                        </div>
                    </div>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <!-- Beschreibung -->
                    <v-alert
                        type="info"
                        variant="tonal"
                        class="mb-4"
                        density="compact"
                    >
                        {{ t('add_fields.bank_accounts.description') }}
                    </v-alert>

                    <!-- Bestehende Bankkonten -->
                    <div v-if="sortableBankAccounts.length > 0" class="mb-4">
                        <div class="text-subtitle-2 mb-2">{{ t('add_fields.existing') }}:</div>
                        <draggable
                            v-model="sortableBankAccounts"
                            @end="saveBankAccountsOrder"
                            item-key="id"
                            handle=".drag-handle"
                        >
                            <template #item="{ element: item }">
                                <v-list-item
                                    @click="editBankAccount(item)"
                                    class="item-hover bg-grey-lighten-5 mb-1 rounded"
                                >
                                    <template v-slot:prepend>
                                        <v-icon class="drag-handle" style="cursor: grab">mdi-drag</v-icon>
                                        <v-icon size="small" class="ms-2">mdi-bank</v-icon>
                                    </template>
                                    <v-list-item-title>{{ item.name }}</v-list-item-title>
                                    <v-list-item-subtitle v-if="item.iban">
                                        {{ item.iban }}
                                    </v-list-item-subtitle>
                                    <template v-slot:append>
                                        <v-icon v-if="item.used" size="small" color="success" class="me-2">mdi-check</v-icon>
                                        <v-btn
                                            icon
                                            variant="text"
                                            size="small"
                                            @click.stop="editBankAccount(item)"
                                        >
                                            <v-icon size="small">mdi-pencil</v-icon>
                                        </v-btn>
                                    </template>
                                </v-list-item>
                            </template>
                        </draggable>
                    </div>

                    <!-- Neu erstellen Button -->
                    <v-btn
                        color="primary"
                        variant="elevated"
                        prepend-icon="mdi-plus"
                        @click="createBankAccount"
                    >
                        {{ t('add_fields.bank_accounts.create_new') }}
                    </v-btn>
                </v-expansion-panel-text>
            </v-expansion-panel>
        </v-expansion-panels>

        <!-- Edit Dialog (wird für alle Typen verwendet) -->
        <v-dialog
            v-model="editDialog"
            max-width="800px"
            persistent
        >
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon class="me-2">{{ currentEditIcon }}</v-icon>
                    {{ currentEditTitle }}
                </v-card-title>

                <v-card-text>
                    <!-- Der Inhalt wird dynamisch geladen basierend auf editType -->
                    <component
                        :is="currentEditComponent"
                        v-if="currentEditComponent"
                        :item="editingItem"
                        @save="saveItem"
                        @cancel="cancelEdit"
                        @delete="deleteItem"
                    />
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Snackbar für Feedback -->
        <v-snackbar
            v-model="snackbar.show"
            :color="snackbar.color"
            :timeout="3000"
            location="top"
        >
            {{ snackbar.message }}
            <template v-slot:actions>
                <v-btn
                    variant="text"
                    @click="snackbar.show = false"
                >
                    Schließen
                </v-btn>
            </template>
        </v-snackbar>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';
import BuchungsgruppeEdit from './edit/buchungsgruppe-edit.vue';
import TaxzoneEdit from './edit/taxzone-edit.vue';
import TaxEdit from './edit/tax-edit.vue';
import BankAccountEdit from './edit/bank-account-edit.vue';
import draggable from 'vuedraggable';

const { t } = useI18n();
const store = oserpStore();

// Welche Panels sind geöffnet
const openPanels = ref([]);

// Edit Dialog State
const editDialog = ref(false);
const editType = ref(null);
const editingItem = ref(null);

// Snackbar für Feedback
const snackbar = ref({
    show: false,
    message: '',
    color: 'success'
});

// Listen aus Store
const buchungsgruppenList = computed(() => store.session?.company_config?.buchungsgruppen || []);
const taxzonesList = computed(() => store.session?.company_config?.tax_zones || []);
const taxesList = computed(() => {
    const taxes = store.session?.company_config?.tax || [];
    return [...taxes].sort((a, b) => (a.taxkey || 0) - (b.taxkey || 0));
});
const bankAccountsList = computed(() => store.session?.company_config?.bank_accounts || []);

// Sortierbare Buchungsgruppen (nach sortkey sortiert)
const sortableBuchungsgruppen = ref([]);

// Sortierbare Steuerzonen (nach sortkey sortiert)
const sortableTaxzones = ref([]);

// Sortierbare Bankkonten (nach sortkey sortiert)
const sortableBankAccounts = ref([]);

// Initialisiere sortableBuchungsgruppen wenn buchungsgruppenList sich ändert
watch(buchungsgruppenList, (newList) => {
    sortableBuchungsgruppen.value = [...newList].sort((a, b) => (a.sortkey || 0) - (b.sortkey || 0));
}, { immediate: true });

// Initialisiere sortableTaxzones wenn taxzonesList sich ändert
watch(taxzonesList, (newList) => {
    sortableTaxzones.value = [...newList].sort((a, b) => (a.sortkey || 0) - (b.sortkey || 0));
}, { immediate: true });

// Initialisiere sortableBankAccounts wenn bankAccountsList sich ändert
watch(bankAccountsList, (newList) => {
    sortableBankAccounts.value = [...newList].sort((a, b) => (a.sortkey || 0) - (b.sortkey || 0));
}, { immediate: true });

// Dialog Properties
const currentEditIcon = computed(() => {
    const icons = {
        'buchungsgruppe': 'mdi-file-document-multiple',
        'taxzone': 'mdi-earth',
        'tax': 'mdi-percent',
        'bank_account': 'mdi-bank'
    };
    return icons[editType.value] || 'mdi-pencil';
});

const currentEditTitle = computed(() => {
    if (!editType.value) return '';
    const key = editingItem.value?.id
        ? `${editType.value}.edit_title`
        : `${editType.value}.create_title`;
    return t(key);
});

const currentEditComponent = computed(() => {
    const components = {
        'buchungsgruppe': BuchungsgruppeEdit,
        'taxzone': TaxzoneEdit,
        'tax': TaxEdit,
        'bank_account': BankAccountEdit
    };
    return components[editType.value] || null;
});

/**
 * Lädt alle Daten beim Mounting
 */
onMounted(async () => {
    await loadAllData();
});

/**
 * Lädt alle Listen-Daten aus dem Store
 */
async function loadAllData() {
    // Die Daten sollten bereits im Store sein
    // Falls nicht, hier API-Calls machen
    console.log('Buchungsgruppen:', buchungsgruppenList.value);
    console.log('Steuerzonen:', taxzonesList.value);
    console.log('Steuern:', taxesList.value);
    console.log('Bankkonten:', bankAccountsList.value);
}

// === Buchungsgruppen Actions ===
function createBuchungsgruppe() {
    editType.value = 'buchungsgruppe';
    editingItem.value = {
        description: '',
        inventory_accno_id: null,
        obsolete: false
    };

    console.log('➕ Neue Buchungsgruppe erstellen');
    console.log('Initial Item:', editingItem.value);

    editDialog.value = true;
}

function editBuchungsgruppe(item) {
    editType.value = 'buchungsgruppe';
    editingItem.value = { ...item };

    console.log('✏️ Buchungsgruppe bearbeiten');
    console.log('Item aus Liste:', item);
    console.log('Kopiert zu editingItem:', editingItem.value);

    editDialog.value = true;
}

// === Steuerzonen Actions ===
function createTaxzone() {
    editType.value = 'taxzone';
    editingItem.value = {
        description: '',
        obsolete: false
    };
    editDialog.value = true;
}

function editTaxzone(item) {
    editType.value = 'taxzone';
    editingItem.value = { ...item };
    editDialog.value = true;
}

// === Steuern Actions ===
function createTax() {
    editType.value = 'tax';
    editingItem.value = {
        taxdescription: '',
        rate: 0,
        taxkey: null,
        chart_id: null
    };
    editDialog.value = true;
}

function editTax(item) {
    editType.value = 'tax';
    editingItem.value = { ...item };
    editDialog.value = true;
}

// === Bankkonten Actions ===
function createBankAccount() {
    editType.value = 'bank_account';
    editingItem.value = {
        description: '',
        iban: '',
        bic: '',
        bank: '',
        chart_id: null,
        obsolete: false
    };
    editDialog.value = true;
}

function editBankAccount(item) {
    editType.value = 'bank_account';
    editingItem.value = { ...item };
    editDialog.value = true;
}

// === Dialog Actions ===
async function saveItem(updatedItem) {
    console.log('💾 Speichere:', editType.value, updatedItem);

    try {
        let response;

        if (editType.value === 'buchungsgruppe') {
            response = await store.saveBuchungsgruppe(updatedItem);
        } else if (editType.value === 'taxzone') {
            response = await store.saveTaxzone(updatedItem);
        } else if (editType.value === 'tax') {
            response = await store.saveTax(updatedItem);
        } else if (editType.value === 'bank_account') {
            response = await store.saveBankAccount(updatedItem);
        }

        if (response && response.success) {
            editDialog.value = false;
            snackbar.value = {
                show: true,
                message: editType.value === 'buchungsgruppe'
                    ? 'Buchungsgruppe gespeichert'
                    : editType.value === 'taxzone'
                    ? 'Steuerzone gespeichert'
                    : editType.value === 'tax'
                    ? 'Steuer gespeichert'
                    : 'Bankkonto gespeichert',
                color: 'success'
            };
        }
    } catch (error) {
        console.error('❌ Fehler:', error);
        snackbar.value = {
            show: true,
            message: error.message || 'Fehler beim Speichern',
            color: 'error'
        };
    }
}

function cancelEdit() {
    editDialog.value = false;
    editingItem.value = null;
}

async function deleteItem(item) {
    console.log('🗑️ Lösche:', editType.value, item);

    try {
        let response;

        if (editType.value === 'buchungsgruppe') {
            response = await store.deleteBuchungsgruppe(item.id);
        } else if (editType.value === 'taxzone') {
            response = await store.deleteTaxzone(item.id);
        } else if (editType.value === 'tax') {
            response = await store.deleteTax(item.id);
        } else if (editType.value === 'bank_account') {
            response = await store.deleteBankAccount(item.id);
        }

        if (response && response.success) {
            editDialog.value = false;
            snackbar.value = {
                show: true,
                message: editType.value === 'buchungsgruppe'
                    ? 'Buchungsgruppe gelöscht'
                    : editType.value === 'taxzone'
                    ? 'Steuerzone gelöscht'
                    : editType.value === 'tax'
                    ? 'Steuer gelöscht'
                    : 'Bankkonto gelöscht',
                color: 'success'
            };
        }
    } catch (error) {
        console.error('❌ Fehler:', error);
        snackbar.value = {
            show: true,
            message: error.message || 'Fehler beim Löschen',
            color: 'error'
        };
    }
}

/**
 * Speichert die neue Sortierung der Steuerzonen
 */
async function saveTaxzoneOrder() {
    try {
        // Erstelle Array mit IDs in neuer Reihenfolge
        const taxzoneIds = sortableTaxzones.value.map(tz => tz.id);

        const response = await store.reorderTaxzones(taxzoneIds);

        if (response && response.success) {
            snackbar.value = {
                show: true,
                message: 'Reihenfolge gespeichert',
                color: 'success'
            };
        }
    } catch (error) {
        console.error('❌ Fehler beim Sortieren:', error);
        snackbar.value = {
            show: true,
            message: 'Fehler beim Speichern der Reihenfolge',
            color: 'error'
        };
    }
}

/**
 * Speichert die neue Sortierung der Bankkonten
 */
async function saveBankAccountsOrder() {
    const bankAccountIds = sortableBankAccounts.value.map(ba => ba.id);
    console.log('💾 Speichere Bankkonten-Reihenfolge:', bankAccountIds);

    try {
        await store.reorderBankAccounts(bankAccountIds);
    } catch (error) {
        console.error('❌ Fehler beim Speichern der Reihenfolge:', error);
    }
}

/**
 * Holt ein Konto aus dem Store anhand der ID
 */
function getChartByIdFromStore(chartId) {
    if (!chartId) return null;
    const chart = store.session?.company_config?.chart || [];
    return chart.find(c => c.id === chartId);
}

/**
 * Zeigt Skonto-Konto als "accno description" an
 */
function getSkontoChartDisplay(chartId) {
    const chart = getChartByIdFromStore(chartId);
    if (!chart) return '';
    return `${chart.accno} ${chart.description}`;
}

/**
 * Speichert die neue Sortierung der Buchungsgruppen
 */
async function saveBuchungsgruppeOrder() {
    try {
        const buchungsgruppeIds = sortableBuchungsgruppen.value.map(bg => bg.id);

        const response = await store.reorderBuchungsgruppen(buchungsgruppeIds);

        if (response && response.success) {
            snackbar.value = {
                show: true,
                message: 'Reihenfolge gespeichert',
                color: 'success'
            };
        }
    } catch (error) {
        console.error('❌ Fehler beim Sortieren:', error);
        snackbar.value = {
            show: true,
            message: 'Fehler beim Speichern der Reihenfolge',
            color: 'error'
        };
    }
}
</script>

<style>
:deep(.v-expansion-panel-title) {
    padding: 20px 24px;
}

:deep(.v-expansion-panel-text__wrapper) {
    padding: 16px 24px 24px;
}

.item-hover {
    cursor: pointer;
    transition: background-color 0.2s;
}

.item-hover:hover {
    background-color: rgba(0, 0, 0, 0.04) !important;
}
</style>
