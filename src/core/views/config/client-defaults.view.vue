<!-- src/core/views/config/client-defaults.view.vue -->
<template>
    <div>
        <!-- Navbar -->
        <navbar-view
            :title="$t('clientConfiguration')"
            :show-back-button="true"
        />

        <v-container fluid class="pa-0">
            <v-row no-gutters>
            <!-- Linke Sidebar mit Tabs -->
            <v-col cols="12" md="3" lg="2" class="border-e">
                <v-card flat class="rounded-0">
                    <!-- Tab-Liste -->
                    <v-list density="compact" nav>
                        <v-list-item
                            v-for="tab in tabs"
                            :key="tab.value"
                            :value="tab.value"
                            :active="activeTab === tab.value"
                            @click="activeTab = tab.value"
                            :prepend-icon="tab.icon"
                        >
                            <v-list-item-title>{{ tab.title }}</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>

            <!-- Hauptinhalt -->
            <v-col cols="12" md="9" lg="10">
                <v-card flat class="rounded-0">
                    <v-card-title class="d-flex align-center justify-space-between pa-4">
                        <div class="d-flex align-center">
                            <v-icon class="me-2">{{ currentTab?.icon }}</v-icon>
                            <span>{{ currentTab?.title }}</span>
                        </div>

                        <!-- Suchfeld für Felder -->
                        <v-text-field
                            v-model="searchQuery"
                            :label="$t('searchFields')"
                            prepend-inner-icon="mdi-magnify"
                            variant="outlined"
                            density="compact"
                            clearable
                            hide-details
                            style="max-width: 300px;"
                        />
                    </v-card-title>

                    <v-divider />

                    <v-card-text class="pa-4" @focusin.capture="onFocusIn" @focusout.capture="onFocusOut">
                        <!-- LAZY LOADED TABS - Nur der aktive Tab wird geladen! -->
                        <component
                            :is="currentTabComponent"
                            :defaults="activeTab === 'crm' || activeTab === 'lxcars' ? undefined : defaults"
                            :crm-defaults="activeTab === 'crm' || activeTab === 'lxcars' ? crmDefaults : undefined"
                            :search-query="searchQuery"
                        />
                    </v-card-text>

                </v-card>
            </v-col>
        </v-row>
    </v-container>

        <!-- Feature-Wechsel Bestätigungs-Dialog -->
        <v-dialog v-model="showFeatureChangeDialog" max-width="500" persistent>
            <v-card>
                <v-card-title class="bg-warning text-white">
                    <v-icon start>mdi-alert</v-icon>
                    {{ t('featureChange.confirm_title') }}
                </v-card-title>
                <v-card-text class="pa-4">
                    <p>{{ t('featureChange.confirm_text', { feature: pendingFeature }) }}</p>
                </v-card-text>
                <v-card-actions>
                    <v-btn @click="cancelFeatureChange">{{ t('cancel') }}</v-btn>
                    <v-spacer />
                    <v-btn
                        color="warning"
                        variant="flat"
                        :loading="featureUpdateLoading"
                        @click="applyFeatureChange"
                    >
                        <v-icon start>mdi-check</v-icon>
                        {{ t('featureChange.confirm_button') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick, defineAsyncComponent } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { oserpStore } from '@/core/stores/oserp.store.js';
import axios from 'axios';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import * as toasts from '@/core/utils/toasts.js';

// LAZY LOADING: Tabs werden nur bei Bedarf geladen - Performance-Optimierung!
const CompanyTab = defineAsyncComponent(() => import('./tabs/company.tab.vue'));
const RangesOfNumbersTab = defineAsyncComponent(() => import('./tabs/ranges.of.numbers.tab.vue'));
const DefaultAccountsTab = defineAsyncComponent(() => import('./tabs/default.accounts.tab.vue'));
const PostingConfigurationTab = defineAsyncComponent(() => import('./tabs/posting.configuration.tab.vue'));
const DatevCheckConfigurationTab = defineAsyncComponent(() => import('./tabs/datev.check.configuration.tab.vue'));
const OrdersDeleteableTab = defineAsyncComponent(() => import('./tabs/orders.deleteable.tab.vue'));
const WarehouseTab = defineAsyncComponent(() => import('./tabs/warehouse.tab.vue'));
const FeaturesTab = defineAsyncComponent(() => import('./tabs/features.tab.vue'));
const StocktakingTab = defineAsyncComponent(() => import('./tabs/stocktaking.tab.vue'));
const RecordLinksTab = defineAsyncComponent(() => import('./tabs/record.links.tab.vue'));
const BankTab = defineAsyncComponent(() => import('./tabs/bank.tab.vue'));
const CrmTab = defineAsyncComponent(() => import('./tabs/crm-defaults.tab.vue'));
const LxCarsTab = defineAsyncComponent(() => import('./tabs/lxcars-defaults.tab.vue'));
const AddTab = defineAsyncComponent(() => import('./tabs/add.tab.vue'));

const { t } = useI18n();
const route = useRoute();

// Store-Zugriff
const store = oserpStore();

// Axios Instanz mit Session-Handling
const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: {
        'Content-Type': 'application/json'
    }
});

const activeTab = ref('company');
const searchQuery = ref('');
const defaults = ref({});
const crmDefaults = ref({});
const saving = ref(false);
const showFeatureChangeDialog = ref(false);
const featureUpdateLoading = ref(false);
const pendingFeature = ref('');
let previousFeatures = '';
let saveTimeout = null;
let initialLoaded = false;
let textInputFocused = false;
let hasPendingChanges = false;

/**
 * Prüft ob ein DOM-Element ein Texteingabefeld ist
 */
function isTextInput(el) {
    if (!el) return false;
    const tag = el.tagName?.toLowerCase();
    if (tag === 'textarea') return true;
    if (tag === 'input') {
        const type = (el.type || 'text').toLowerCase();
        return ['text', 'number', 'password', 'email', 'url', 'search', 'tel', 'date'].includes(type);
    }
    return false;
}

function onFocusIn(event) {
    if (isTextInput(event.target)) {
        textInputFocused = true;
    }
}

function onFocusOut(event) {
    if (isTextInput(event.target)) {
        textInputFocused = false;
        if (hasPendingChanges) {
            hasPendingChanges = false;
            triggerSave();
        }
    }
}

/**
 * Wird vom Deep Watcher aufgerufen.
 * Speichert sofort bei Select/Checkbox-Änderungen,
 * wartet bei Textfeldern bis der Fokus verloren geht.
 */
function onDataChange() {
    if (!initialLoaded) return;

    // Feature-Wechsel abfangen: Dialog zeigen statt direkt speichern
    const currentFeature = crmDefaults.value.features || '';
    if (currentFeature !== previousFeatures) {
        pendingFeature.value = currentFeature;
        showFeatureChangeDialog.value = true;
        return;
    }

    if (textInputFocused) {
        hasPendingChanges = true;
        return;
    }
    triggerSave();
}

function triggerSave() {
    if (saveTimeout) clearTimeout(saveTimeout);
    saveTimeout = setTimeout(() => {
        saveConfig();
    }, 500);
}

watch(defaults, onDataChange, { deep: true });
watch(crmDefaults, onDataChange, { deep: true });

// WICHTIG: tabs muss COMPUTED sein damit Übersetzungen reaktiv sind!
const tabs = computed(() => [
    {
        value: 'company',
        title: t('company'),
        icon: 'mdi-domain'
    },
    {
        value: 'ranges_of_numbers',
        title: t('rangesOfNumbers'),
        icon: 'mdi-numeric'
    },
    {
        value: 'default_accounts',
        title: t('defaultAccounts'),
        icon: 'mdi-bank'
    },
    {
        value: 'posting_configuration',
        title: t('postingConfiguration'),
        icon: 'mdi-file-document-edit'
    },
    {
        value: 'datev_check_configuration',
        title: t('datevCheckConfiguration'),
        icon: 'mdi-check-circle'
    },
    {
        value: 'orders_deleteable',
        title: t('ordersDeleteable'),
        icon: 'mdi-delete'
    },
    {
        value: 'warehouse',
        title: t('warehouse'),
        icon: 'mdi-warehouse'
    },
    {
        value: 'features',
        title: t('features'),
        icon: 'mdi-star'
    },
    {
        value: 'stocktaking',
        title: t('stocktaking'),
        icon: 'mdi-clipboard-list'
    },
    {
        value: 'record_links',
        title: t('recordLinks'),
        icon: 'mdi-link-variant'
    },
    {
        value: 'bank',
        title: t('bank'),
        icon: 'mdi-bank-transfer'
    },
    {
        value: 'crm',
        title: t('crm'),
        icon: 'mdi-account-multiple'
    },
    ...(store.isLxCars() ? [{
        value: 'lxcars',
        title: 'LxCars',
        icon: 'mdi-car'
    }] : []),
    {
        value: 'add',
        title: t('add'),
        icon: 'mdi-plus-circle'
    }
]);

const currentTab = computed(() => {
    return tabs.value.find(tab => tab.value === activeTab.value);
});

// Computed: Gibt die aktuelle Tab-Component zurück (Lazy Loading!)
const currentTabComponent = computed(() => {
    const componentMap = {
        'company': CompanyTab,
        'ranges_of_numbers': RangesOfNumbersTab,
        'default_accounts': DefaultAccountsTab,
        'posting_configuration': PostingConfigurationTab,
        'datev_check_configuration': DatevCheckConfigurationTab,
        'orders_deleteable': OrdersDeleteableTab,
        'warehouse': WarehouseTab,
        'features': FeaturesTab,
        'stocktaking': StocktakingTab,
        'record_links': RecordLinksTab,
        'bank': BankTab,
        'crm': CrmTab,
        'lxcars': LxCarsTab,
        'add': AddTab
    };

    return componentMap[activeTab.value] || CompanyTab;
});

/**
 * Lädt die Firmenkonfiguration frisch aus der Datenbank
 *
 * NICHT aus dem Store! Nummernkreise (Fakturanummern etc.) werden im
 * Backend hochgezählt und wären im Store veraltet.
 */
async function loadConfig() {
    try {
        const response = await api.post('/oserp_config/', { action: 'getDefaults' });

        if (response.data.success) {
            const result = response.data.payload?.results || {};
            defaults.value = result.defaults || {};
            crmDefaults.value = result.defaults_oserp || {};
        } else {
            console.error('getDefaults fehlgeschlagen:', response.data);
            defaults.value = {};
            crmDefaults.value = {};
        }
    } catch (error) {
        console.error('getDefaults Fehler:', error);
        defaults.value = {};
        crmDefaults.value = {};
    }

    // Merke aktuellen Feature-Wert für Änderungserkennung
    previousFeatures = crmDefaults.value.features || '';
}

/**
 * Speichert die Firmenkonfiguration
 * Sendet BEIDE Datensets (defaults + crmDefaults) in EINEM Ajax-Call
 */
async function saveConfig() {
    saving.value = true;

    try {
        // Bereinige defaults (horizontale Speicherung)
        const cleanedDefaults = cleanData(defaults.value);

        // Bereinige crmDefaults (vertikale Speicherung)
        const cleanedCrmDefaults = cleanData(crmDefaults.value);

        // EIN Payload mit BEIDEN Datensets!
        const payload = {
            action: 'saveDefaults',
            data: cleanedDefaults,
            crmData: cleanedCrmDefaults
        };

        const response = await api.post('/oserp_config/', payload);

        if (response.data.success) {
            // Aktualisiere BEIDE im Store
            if (store.session?.company_config) {
                store.session.company_config.defaults = { ...defaults.value };
                store.session.company_config.defaults_oserp = { ...crmDefaults.value };
            }

            toasts.success(t('saveSuccess'));
        } else {
            console.error('API Error:', response.data);
        }
    } catch (error) {
        console.error('Network/Exception Error:', error);
    } finally {
        saving.value = false;
    }
}

/**
 * Bricht den Feature-Wechsel ab und stellt den alten Wert wieder her
 */
function cancelFeatureChange() {
    crmDefaults.value.features = previousFeatures;
    showFeatureChangeDialog.value = false;
    pendingFeature.value = '';
}

/**
 * Führt den Feature-Wechsel durch: Config speichern, Schema-Update, App-Reload
 */
async function applyFeatureChange() {
    featureUpdateLoading.value = true;

    try {
        // 1. Config speichern (neues Feature wird in defaults_oserp geschrieben)
        await saveConfig();

        // 2. Schema-Update ausführen (nur Company-DB, da Features nur dort relevant)
        const updateResponse = await api.post('/update/', {
            action: 'updateSchema',
            dry_run: false,
            auth_db: false,
            company_db: true
        });

        if (!updateResponse.data.success) {
            console.error('Schema-Update fehlgeschlagen:', updateResponse.data);
        }

        // 3. App neu laden
        window.location.reload();
    } catch (error) {
        console.error('Feature-Wechsel Fehler:', error);
        // Bei Fehler: Feature zurücksetzen
        crmDefaults.value.features = previousFeatures;
        featureUpdateLoading.value = false;
        showFeatureChangeDialog.value = false;
    }
}

/**
 * Hilfsfunktion: Bereinigt Daten für die API
 */
function cleanData(data) {
    const cleaned = {};

    for (const [key, value] of Object.entries(data)) {
        if (value === false || value === 0) {
            cleaned[key] = value;
            continue;
        }
        if (value === true) {
            cleaned[key] = true;
            continue;
        }
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed === '' || trimmed === 'null') {
                continue;
            } else if (trimmed === 'false') {
                cleaned[key] = false;
            } else if (trimmed === 'true') {
                cleaned[key] = true;
            } else {
                cleaned[key] = value;
            }
        } else if (value === null || value === undefined) {
            continue;
        } else {
            cleaned[key] = value;
        }
    }

    return cleaned;
}

// Lade Konfiguration beim Mounten
onMounted(async () => {
    await loadConfig();

    // Query-Parameter: ?tab=lxcars&focus=lxcars_yellow_label_printer
    const tabParam = route.query.tab;
    if (tabParam && tabs.value.some(t => t.value === tabParam)) {
        activeTab.value = tabParam;
    }
    const focusParam = route.query.focus;
    if (focusParam) {
        let retries = 0;
        const tryFocus = () => {
            const el = document.querySelector(`[data-field-name="${focusParam}"]`);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Visuelles Highlight
                el.style.transition = 'background 0.3s';
                el.style.background = '#fff3cd';
                setTimeout(() => { el.style.background = ''; }, 2000);
                // Vuetify v-select/v-text-field: inneres Input fokussieren
                nextTick(() => {
                    const input = el.querySelector('.v-field__input input, .v-field__input textarea');
                    if (input) input.focus();
                });
            } else if (retries++ < 20) {
                setTimeout(tryFocus, 200);
            }
        };
        setTimeout(tryFocus, 100);
    }

    // Watcher erst nach initialem Laden aktivieren
    nextTick(() => {
        initialLoaded = true;
    });
});
</script>

<style scoped>
.border-e {
    border-right: 1px solid rgba(0, 0, 0, 0.12);
}
</style>
