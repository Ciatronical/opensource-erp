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
            <v-col cols="12" md="3" lg="2" class="border-e sidebar-col">
                <v-card flat class="rounded-0">
                    <!-- Suchfeld + Strg+K-Hinweis -->
                    <div class="pa-3">
                        <v-text-field
                            v-model="searchQuery"
                            :label="$t('searchSettings')"
                            prepend-inner-icon="mdi-magnify"
                            variant="outlined"
                            density="compact"
                            clearable
                            hide-details
                            autofocus
                        >
                            <template #append-inner>
                                <v-tooltip location="bottom" :text="$t('quickSearchHint')">
                                    <template #activator="{ props }">
                                        <button
                                            v-bind="props"
                                            type="button"
                                            class="kbd-hint"
                                            @click="openPalette"
                                        >Strg K</button>
                                    </template>
                                </v-tooltip>
                            </template>
                        </v-text-field>
                    </div>

                    <v-divider />

                    <!-- Mobil: kompakte Bereichsauswahl statt langer Liste -->
                    <div class="d-md-none pa-3">
                        <v-select
                            :model-value="activeTab"
                            :items="mobileTabItems"
                            :label="$t('selectSection')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            @update:model-value="goToTab"
                        />
                    </div>

                    <!-- Ohne Suche: gruppierte Tab-Liste (Desktop) -->
                    <v-list v-if="!searchQuery" density="compact" nav class="pt-0 d-none d-md-block">
                        <v-list-item
                            :value="'overview'"
                            :active="activeTab === 'overview'"
                            @click="goToTab('overview')"
                            prepend-icon="mdi-view-dashboard-outline"
                        >
                            <v-list-item-title>{{ $t('overview') }}</v-list-item-title>
                        </v-list-item>

                        <template v-for="group in tabGroups" :key="group.key">
                            <v-list-subheader class="text-uppercase text-caption font-weight-bold">
                                {{ group.title }}
                            </v-list-subheader>
                            <template v-for="tab in group.items" :key="tab.value">
                                <v-list-item
                                    :value="tab.value"
                                    :active="activeTab === tab.value"
                                    @click="activeTab = tab.value"
                                    :prepend-icon="tab.icon"
                                >
                                    <v-list-item-title>{{ tab.title }}</v-list-item-title>
                                </v-list-item>
                                <!-- Unterbereiche direkt anspringbar -->
                                <v-list-item
                                    v-for="sub in (tab.subsections || [])"
                                    :key="tab.value + '/' + sub.key"
                                    class="subsection-item"
                                    :active="activeTab === tab.value && pendingPanel === sub.panel"
                                    @click="goToResult({ type: 'sub', tab: tab.value, panel: sub.panel })"
                                >
                                    <template #prepend>
                                        <v-icon size="small" class="ms-4">mdi-circle-small</v-icon>
                                    </template>
                                    <v-list-item-title class="text-caption">{{ sub.title }}</v-list-item-title>
                                </v-list-item>
                            </template>
                        </template>
                    </v-list>

                    <!-- Mit Suche: flache, gerankte Trefferliste (inkl. Unterbereichen) -->
                    <v-list v-else density="compact" nav class="pt-0 d-none d-md-block">
                        <v-list-subheader class="text-uppercase text-caption font-weight-bold">
                            {{ $t('searchResults') }} ({{ searchResults.length }})
                        </v-list-subheader>
                        <v-list-item
                            v-for="result in searchResults"
                            :key="result.id"
                            :active="activeTab === result.tab && (result.type === 'tab' || pendingPanel === result.panel)"
                            @click="goToResult(result)"
                            :prepend-icon="result.icon"
                        >
                            <v-list-item-title>{{ result.title }}</v-list-item-title>
                            <v-list-item-subtitle v-if="result.type === 'sub'" class="text-caption">
                                {{ result.parent }}
                            </v-list-item-subtitle>
                        </v-list-item>

                        <!-- Keine Treffer -->
                        <v-list-item v-if="searchResults.length === 0" :title="$t('noSectionsFound')" disabled>
                            <template #prepend>
                                <v-icon>mdi-magnify-close</v-icon>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>

            <!-- Hauptinhalt -->
            <v-col cols="12" md="9" lg="10">
                <v-card flat class="rounded-0">
                    <v-card-title class="d-flex align-center justify-space-between pa-4 ga-2">
                        <div class="d-flex align-center">
                            <v-icon class="me-2">{{ activeTab === 'overview' ? 'mdi-view-dashboard-outline' : currentTab?.icon }}</v-icon>
                            <span>{{ activeTab === 'overview' ? $t('overview') : currentTab?.title }}</span>
                        </div>

                        <div class="d-flex align-center ga-2">
                            <!-- Speicher-Status: dauerhaft sichtbar statt nur Toast -->
                            <span class="save-status text-caption">
                                <template v-if="saving">
                                    <v-progress-circular indeterminate size="14" width="2" class="me-1" color="primary" />
                                    {{ $t('savingChanges') }}
                                </template>
                                <template v-else-if="lastSaved">
                                    <v-icon size="16" color="success" class="me-1">mdi-check-circle-outline</v-icon>
                                    {{ $t('savedAllChanges') }}
                                </template>
                            </span>

                            <!-- Aktiver Suchbegriff als entfernbarer Chip -->
                            <v-chip
                                v-if="searchQuery"
                                closable
                                color="primary"
                                variant="tonal"
                                prepend-icon="mdi-magnify"
                                @click:close="searchQuery = ''"
                            >
                                {{ searchQuery }}
                            </v-chip>
                        </div>
                    </v-card-title>

                    <v-divider />

                    <v-card-text class="pa-4" @focusin.capture="onFocusIn" @focusout.capture="onFocusOut">
                        <!-- Startseite: Gruppen-Übersicht -->
                        <overview-tab
                            v-if="activeTab === 'overview'"
                            :cards="overviewCards"
                            @select="goToTab"
                        />

                        <!-- LAZY LOADED TABS - Nur der aktive Tab wird geladen! -->
                        <component
                            v-else
                            :is="currentTabComponent"
                            :defaults="['crm','lxcars','anpr','ai_health','employees'].includes(activeTab) ? undefined : defaults"
                            :crm-defaults="['crm','lxcars','anpr','bank','features','ai_health'].includes(activeTab) ? crmDefaults : undefined"
                            :search-query="searchQuery"
                            :open-panel="activeTab === 'add' ? pendingPanel : undefined"
                            :extensions="activeTab === 'features' ? availableExtensions : undefined"
                            @toggle-extension="onToggleExtension"
                        />
                    </v-card-text>

                </v-card>
            </v-col>
        </v-row>
    </v-container>

        <!-- Strg+K Befehls-Suche (Schnellsuche über alle Bereiche) -->
        <v-dialog v-model="showPalette" max-width="560" scrollable>
            <v-card class="palette-card">
                <div class="pa-3">
                    <v-text-field
                        v-model="paletteQuery"
                        :placeholder="$t('quickSearch')"
                        prepend-inner-icon="mdi-magnify"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autofocus
                        clearable
                        @keydown.enter="paletteResults.length && selectPaletteResult(paletteResults[0])"
                        @keydown.esc="showPalette = false"
                    />
                </div>
                <v-divider />
                <v-list density="compact" nav class="palette-list">
                    <v-list-item
                        v-for="result in paletteResults"
                        :key="result.id"
                        :prepend-icon="result.icon"
                        @click="selectPaletteResult(result)"
                    >
                        <v-list-item-title>{{ result.title }}</v-list-item-title>
                        <template #append>
                            <span class="text-caption text-medium-emphasis">
                                {{ result.type === 'sub' ? `${result.parent}` : result.group }}
                            </span>
                        </template>
                    </v-list-item>
                    <v-list-item v-if="paletteQuery && paletteResults.length === 0" :title="$t('noSectionsFound')" disabled>
                        <template #prepend>
                            <v-icon>mdi-magnify-close</v-icon>
                        </template>
                    </v-list-item>
                    <v-list-item v-if="!paletteQuery" :subtitle="$t('quickSearchHint')" disabled>
                        <template #prepend>
                            <v-icon>mdi-keyboard-outline</v-icon>
                        </template>
                    </v-list-item>
                </v-list>
            </v-card>
        </v-dialog>

        <!-- Feature-Wechsel Bestätigungs-Dialog -->
        <v-dialog v-model="showExtensionChangeDialog" max-width="500" persistent>
            <v-card>
                <v-card-title class="bg-warning text-white">
                    <v-icon start>mdi-alert</v-icon>
                    {{ t('extensionChange.confirm_title') }}
                </v-card-title>
                <v-card-text class="pa-4">
                    <p>
                        {{ pendingExtensions.length
                            ? t('extensionChange.confirm_text', { extensions: pendingExtensions.join(', ') })
                            : t('extensionChange.confirm_text_none') }}
                    </p>
                    <p class="text-caption text-medium-emphasis mt-3">
                        {{ t('extensionChange.data_hint') }}
                    </p>
                </v-card-text>
                <v-card-actions>
                    <v-btn @click="cancelExtensionChange">{{ t('cancel') }}</v-btn>
                    <v-spacer />
                    <v-btn
                        color="warning"
                        variant="flat"
                        :loading="extensionUpdateLoading"
                        @click="applyExtensionChange"
                    >
                        <v-icon start>mdi-check</v-icon>
                        {{ t('extensionChange.confirm_button') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Feature-Dienst Ein/Aus-Dialog -->
        <v-dialog v-model="showFeatureServiceDialog" max-width="580" persistent>
            <v-card>
                <v-card-title class="d-flex align-center ga-2" :class="pendingFeatureService?.enabled ? 'bg-success' : 'bg-error'" style="color:white">
                    <v-icon start>{{ pendingFeatureService?.enabled ? 'mdi-power-plug' : 'mdi-power-plug-off' }}</v-icon>
                    {{ pendingFeatureService?.enabled
                        ? t('featureService.enable_title', { name: featureServiceLabel })
                        : t('featureService.disable_title', { name: featureServiceLabel }) }}
                </v-card-title>
                <v-card-text class="pa-4">
                    <!-- Normaler Bestätigungstext (kein Fehler) -->
                    <template v-if="!featureServiceErrorDetail">
                        <p v-if="pendingFeatureService?.enabled">
                            {{ t('featureService.enable_text', { name: featureServiceLabel, services: featureServiceNames }) }}
                        </p>
                        <p v-else>
                            {{ t('featureService.disable_text', { name: featureServiceLabel, services: featureServiceNames }) }}
                        </p>
                    </template>

                    <!-- Fehlerbereich -->
                    <template v-if="featureServiceErrorDetail">
                        <v-alert type="error" variant="tonal" density="compact" class="mb-3">
                            <div class="font-weight-medium mb-1">{{ t('featureService.stop_failed') }}</div>
                            <div class="text-caption">{{ featureServiceErrorDetail.message }}</div>
                        </v-alert>

                        <!-- systemctl-Output -->
                        <div v-if="featureServiceErrorDetail.output" class="mb-3">
                            <div class="text-caption text-medium-emphasis mb-1">{{ t('featureService.systemctl_output') }}</div>
                            <pre class="feature-error-pre">{{ featureServiceErrorDetail.output }}</pre>
                        </div>

                        <!-- Manuelle Befehle -->
                        <div v-if="featureServiceErrorDetail.manualCommands?.length">
                            <div class="text-caption text-medium-emphasis mb-1">{{ t('featureService.manual_fix') }}</div>
                            <div v-for="cmd in featureServiceErrorDetail.manualCommands" :key="cmd"
                                class="d-flex align-center ga-2 mb-1">
                                <code class="feature-cmd-code flex-grow-1">{{ cmd }}</code>
                                <v-btn
                                    size="x-small"
                                    variant="tonal"
                                    :color="copiedCmd === cmd ? 'success' : 'default'"
                                    :prepend-icon="copiedCmd === cmd ? 'mdi-check' : 'mdi-content-copy'"
                                    @click="copyCmd(cmd)"
                                >
                                    {{ copiedCmd === cmd ? t('copied') : t('copy') }}
                                </v-btn>
                            </div>
                        </div>
                    </template>
                </v-card-text>
                <v-card-actions>
                    <v-btn @click="cancelFeatureService">{{ t('cancel') }}</v-btn>
                    <v-spacer />
                    <v-btn
                        v-if="!featureServiceErrorDetail"
                        :color="pendingFeatureService?.enabled ? 'success' : 'error'"
                        variant="flat"
                        :loading="featureServiceLoading"
                        @click="applyFeatureService"
                    >
                        <v-icon start>mdi-check</v-icon>
                        {{ pendingFeatureService?.enabled ? t('featureService.confirm_enable') : t('featureService.confirm_disable') }}
                    </v-btn>
                    <v-btn
                        v-else
                        color="primary"
                        variant="flat"
                        @click="retryFeatureService"
                        :loading="featureServiceLoading"
                    >
                        <v-icon start>mdi-refresh</v-icon>
                        {{ t('featureService.retry') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick, defineAsyncComponent } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { oserpStore } from '@/core/stores/oserp.store.js';
import axios from 'axios';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import OverviewTab from './tabs/overview.tab.vue';
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
const EinvoiceTab = defineAsyncComponent(() => import('./tabs/einvoice.tab.vue'));
const CrmTab = defineAsyncComponent(() => import('./tabs/crm-defaults.tab.vue'));
const LxCarsTab = defineAsyncComponent(() => import('./tabs/lxcars-defaults.tab.vue'));
const AnprTab = defineAsyncComponent(() => import('./tabs/anpr-defaults.tab.vue'));
const AiHealthTab = defineAsyncComponent(() => import('./tabs/ai-health.tab.vue'));
const EmployeesTab = defineAsyncComponent(() => import('./tabs/employees.tab.vue'));
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

const activeTab = ref('overview');
const searchQuery = ref('');
const defaults = ref({});
const crmDefaults = ref({});
const saving = ref(false);
const lastSaved = ref(null); // Zeitpunkt der letzten erfolgreichen Speicherung

// Strg+K Befehls-Suche (Palette)
const showPalette = ref(false);
const paletteQuery = ref('');
// Erweiterungen (Module wie LxCars) — Katalog kommt aus getExtensions
const availableExtensions = ref([]);
const showExtensionChangeDialog = ref(false);
const extensionUpdateLoading = ref(false);
const pendingExtensions = ref([]);
let saveTimeout = null;
let initialLoaded = false;
let textInputFocused = false;
let hasPendingChanges = false;

// Feature-Dienst-Toggle (ANPR / NVR)
const showFeatureServiceDialog = ref(false);
const featureServiceLoading = ref(false);
const featureServiceErrorDetail = ref(null); // { message, output, manualCommands }
const pendingFeatureService = ref(null); // { feature: 'anpr'|'nvr', enabled: bool }
const copiedCmd = ref('');
let previousFeatureAnpr = undefined;
let previousFeatureNvr = undefined;

const featureServiceMeta = {
    anpr: { label: 'ANPR', services: 'anpr.service' },
    nvr:  { label: 'NVR',  services: 'go2rtc.service, camera-monitor.service' },
};
const featureServiceLabel = computed(() => featureServiceMeta[pendingFeatureService.value?.feature]?.label ?? '');
const featureServiceNames = computed(() => featureServiceMeta[pendingFeatureService.value?.feature]?.services ?? '');

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
function _normalizeFeatureBool(v) {
    return v === true || v === 'true' || v === '1' || v === 1;
}

function onDataChange() {
    if (!initialLoaded) return;

    // ANPR-Toggle abfangen
    const currentAnpr = crmDefaults.value.feature_anpr;
    if (previousFeatureAnpr !== undefined) {
        const wasOn = _normalizeFeatureBool(previousFeatureAnpr);
        const isOn  = _normalizeFeatureBool(currentAnpr);
        if (wasOn !== isOn) {
            pendingFeatureService.value = { feature: 'anpr', enabled: isOn };
            featureServiceErrorDetail.value = null;
            showFeatureServiceDialog.value = true;
            return;
        }
    }

    // NVR-Toggle abfangen
    const currentNvr = crmDefaults.value.feature_nvr;
    if (previousFeatureNvr !== undefined) {
        const wasOn = _normalizeFeatureBool(previousFeatureNvr);
        const isOn  = _normalizeFeatureBool(currentNvr);
        if (wasOn !== isOn) {
            pendingFeatureService.value = { feature: 'nvr', enabled: isOn };
            featureServiceErrorDetail.value = null;
            showFeatureServiceDialog.value = true;
            return;
        }
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

// WICHTIG: COMPUTED, damit Übersetzungen reaktiv sind.
// Logisch gruppiert; `keywords` speisen die Sektions-Suche (DE + EN).
const tabGroups = computed(() => [
    {
        key: 'master_data',
        title: t('configGroups.masterData'),
        items: [
            { value: 'company',   title: t('company'),   icon: 'mdi-domain',           keywords: ['firma', 'company', 'stammdaten', 'adresse', 'address', 'logo', 'ust', 'ust-idnr', 'umsatzsteuer', 'steuernummer', 'währung', 'currency', 'sprache', 'language', 'drucker', 'printer', 'druckvorlage', 'template', 'gewicht', 'weight', 'signatur', 'gläubiger', 'creditor'] },
            { value: 'employees', title: t('employeesTab.title'), icon: 'mdi-account-tie', keywords: ['mitarbeiter', 'employee', 'personal', 'obsolet', 'obsolete', 'verkäufer', 'benutzer', 'user', 'staff'] },
            { value: 'warehouse', title: t('warehouse'), icon: 'mdi-warehouse',        keywords: ['lager', 'warehouse', 'bestand', 'stock', 'lagerort', 'ort'] },
            { value: 'crm',       title: t('crm'),       icon: 'mdi-account-multiple', keywords: ['crm', 'kunde', 'customer', 'kontakt', 'contact'] },
        ]
    },
    {
        key: 'accounting',
        title: t('configGroups.accounting'),
        items: [
            { value: 'default_accounts',          title: t('defaultAccounts'),        icon: 'mdi-bank',               keywords: ['konto', 'konten', 'account', 'standardkonto', 'sachkonto', 'fibu', 'erlöskonto', 'aufwandskonto', 'warenbestand', 'forderungen', 'verbindlichkeiten', 'jahresabschluss', 'gewinnvortrag', 'verlustvortrag'] },
            { value: 'posting_configuration',     title: t('postingConfiguration'),   icon: 'mdi-file-document-edit', keywords: ['buchung', 'posting', 'buchen', 'kontierung', 'zahlungsziel', 'skonto'] },
            { value: 'datev_check_configuration', title: t('datevCheckConfiguration'),icon: 'mdi-check-circle',       keywords: ['datev', 'export', 'prüfung', 'steuerberater', 'ustva'] },
            { value: 'bank',                      title: t('bank'),                   icon: 'mdi-bank-transfer',      keywords: ['bank', 'sepa', 'iban', 'überweisung', 'fints', 'hbci', 'zahlung', 'payment', 'banking'] },
        ]
    },
    {
        key: 'documents',
        title: t('configGroups.documents'),
        items: [
            { value: 'ranges_of_numbers', title: t('rangesOfNumbers'), icon: 'mdi-numeric',              keywords: ['nummer', 'nummernkreis', 'number', 'rechnung', 'invoice', 'auftrag', 'order', 'angebot', 'kunde', 'lieferant', 'artikel', 'dienstleistung', 'erzeugnis'] },
            { value: 'record_links',      title: t('recordLinks'),     icon: 'mdi-link-variant',         keywords: ['verknüpfung', 'link', 'beleg', 'record', 'kette'] },
            { value: 'orders_deleteable', title: t('ordersDeleteable'),icon: 'mdi-delete',               keywords: ['löschen', 'delete', 'auftrag', 'order', 'beleg', 'stornieren'] },
            { value: 'einvoice',          title: t('einvoice.tab_title'), icon: 'mdi-file-document-check',keywords: ['erechnung', 'e-rechnung', 'einvoice', 'zugferd', 'xrechnung', 'factur-x', 'xml', 'leitweg'] },
            { value: 'stocktaking',       title: t('stocktaking'),     icon: 'mdi-clipboard-list',       keywords: ['inventur', 'stocktaking', 'zählung', 'bestand'] },
        ]
    },
    {
        key: 'features',
        title: t('configGroups.features'),
        items: [
            { value: 'features', title: t('features'), icon: 'mdi-star', keywords: ['feature', 'funktion', 'modul', 'branche', 'module', 'e-mail', 'email', 'dms', 'dokumente', 'webdav', 'kamera', 'überwachung', 'datev', 'ustva'] },
            ...(store.isLxCars() ? [{ value: 'lxcars', title: 'LxCars', icon: 'mdi-car', keywords: ['lxcars', 'fahrzeug', 'auto', 'werkstatt', 'kfz', 'reifen'] }] : []),
            ...(store.isAnprEnabled() ? [{ value: 'anpr', title: 'ANPR', icon: 'mdi-car-search', keywords: ['anpr', 'kennzeichen', 'kamera', 'nummernschild'] }] : []),
            { value: 'ai_health', title: t('aiHealth.tabTitle'), icon: 'mdi-robot-happy-outline', keywords: ['ki', 'ai', 'whisper', 'llm', 'ollama', 'spracheingabe', 'glossar', 'fachbegriffe', 'gesundheit', 'health', 'cloud', 'api-key', 'positionsvorschläge'] },
        ]
    },
    {
        key: 'tools',
        title: t('configGroups.tools'),
        items: [
            {
                value: 'add',
                title: t('grunddaten'),
                icon: 'mdi-cog-outline',
                keywords: ['systemeinstellungen', 'grunddaten', 'stammdaten', 'hinzufügen', 'add', 'neu', 'anlegen', 'werkzeug',
                    'buchungsgruppe', 'buchungsgruppen', 'steuerzone', 'steuerzonen', 'steuer', 'steuersatz', 'steuersätze', 'tax', 'mwst', 'ust',
                    'bank', 'bankkonto', 'bankkonten', 'iban', 'konto'],
                // Unterbereiche (Accordion-Panels) — direkt anspringbar via Suche.
                subsections: [
                    { key: 'buchungsgruppen', title: t('add_fields.buchungsgruppen.title'), panel: 'buchungsgruppen', keywords: ['buchungsgruppe', 'buchungsgruppen', 'booking group'] },
                    { key: 'taxzones',        title: t('add_fields.taxzones.title'),        panel: 'taxzones',        keywords: ['steuerzone', 'steuerzonen', 'taxzone'] },
                    { key: 'taxes',           title: t('add_fields.taxes.title'),           panel: 'taxes',           keywords: ['steuer', 'steuersatz', 'steuersätze', 'tax', 'mwst', 'ust'] },
                    { key: 'bank_accounts',   title: t('add_fields.bank_accounts.title'),   panel: 'bank_accounts',   keywords: ['bank', 'bankkonto', 'bankkonten', 'iban', 'konto'] },
                ],
            },
        ]
    }
]);

// Flache Liste aller sichtbaren Tabs (für Lookups & Query-Param-Handling)
const allTabs = computed(() => tabGroups.value.flatMap(g => g.items));

// Panel, das im Ziel-Tab geöffnet werden soll (Deep-Link aus der Suche).
const pendingPanel = ref('');

/**
 * Zentraler Such-Index als flache, gerankte Trefferliste.
 *
 * Durchsucht Titel + Keywords der Sektionen UND deren Unterbereiche
 * (z. B. "Bankkonten" im Tab "Hinzufügen"). Ein Unterbereich-Treffer
 * springt direkt in das passende Accordion-Panel.
 *
 * Ranking: Titel-Anfang (0) < Titel enthält (1) < Keyword (2).
 */
function buildResults(rawQuery) {
    const q = (rawQuery || '').trim().toLowerCase();
    if (!q) return [];

    const rank = (title, keywords) => {
        const tl = title.toLowerCase();
        if (tl.startsWith(q)) return 0;
        if (tl.includes(q)) return 1;
        if (keywords.some(k => k.includes(q))) return 2;
        return -1;
    };

    const results = [];
    for (const group of tabGroups.value) {
        for (const tab of group.items) {
            const tabScore = rank(tab.title, tab.keywords);
            if (tabScore >= 0) {
                results.push({ id: tab.value, type: 'tab', tab: tab.value, title: tab.title, icon: tab.icon, group: group.title, score: tabScore });
            }
            for (const sub of (tab.subsections || [])) {
                const subScore = rank(sub.title, sub.keywords);
                if (subScore >= 0) {
                    results.push({ id: `${tab.value}/${sub.key}`, type: 'sub', tab: tab.value, panel: sub.panel, title: sub.title, icon: tab.icon, parent: tab.title, group: group.title, score: subScore });
                }
            }
        }
    }
    return results.sort((a, b) => a.score - b.score);
}

const searchResults = computed(() => buildResults(searchQuery.value));

const currentTab = computed(() => {
    return allTabs.value.find(tab => tab.value === activeTab.value);
});

/**
 * Navigiert zu einem Suchtreffer: setzt den Tab und öffnet bei
 * Unterbereich-Treffern das passende Panel.
 */
function goToResult(result) {
    activeTab.value = result.tab;
    // pendingPanel neu setzen (auch bei gleichem Panel), damit der
    // Watcher im Ziel-Tab erneut feuert und hinscrollt.
    pendingPanel.value = '';
    if (result.type === 'sub' && result.panel) {
        nextTick(() => { pendingPanel.value = result.panel; });
    }
}

// Beim Tippen automatisch zum besten Treffer springen, damit die Suche
// direkt zur passenden Sektion führt (Panel wird erst per Klick geöffnet).
watch(searchResults, (results) => {
    if (!searchQuery.value || results.length === 0) return;
    if (!results.some(r => r.tab === activeTab.value)) {
        activeTab.value = results[0].tab;
    }
});

/** Wechselt den Tab (Übersichts-Kacheln, Mobil-Auswahl) und leert die Suche. */
function goToTab(value) {
    activeTab.value = value;
    searchQuery.value = '';
}

// ── Übersichts-Kacheln ────────────────────────────────────────────────
// Kurzbeschreibung je Gruppe für die Startseite. Nur ehrliche Kennzahlen:
// Anzahl Bereiche bzw. echte Eintragszahlen aus dem Store.
const groupDescriptions = {
    master_data: () => t('overviewDesc.masterData'),
    accounting:  () => t('overviewDesc.accounting'),
    documents:   () => t('overviewDesc.documents'),
    features:    () => t('overviewDesc.features'),
    tools:       () => {
        const cc = store.session?.company_config || {};
        const bg = (cc.buchungsgruppen || []).length;
        const tax = (cc.tax || []).length;
        const ba = (cc.bank_accounts || []).length;
        return `${bg} ${t('add_fields.buchungsgruppen.title')} · ${tax} ${t('add_fields.taxes.title')} · ${ba} ${t('add_fields.bank_accounts.title')}`;
    },
};

const overviewCards = computed(() =>
    tabGroups.value
        .filter(g => g.items.length > 0)
        .map(g => ({
            key: g.key,
            title: g.title,
            icon: g.items[0].icon,
            meta: groupDescriptions[g.key]
                ? groupDescriptions[g.key]()
                : `${g.items.length} ${t('sectionsLabel')}`,
            target: g.items[0].value,
        }))
);

// Flache Auswahl-Liste für die mobile Bereichsauswahl (inkl. Übersicht).
const mobileTabItems = computed(() => [
    { title: t('overview'), value: 'overview' },
    ...allTabs.value.map(tab => ({ title: tab.title, value: tab.value })),
]);

// ── Strg+K Befehls-Suche ──────────────────────────────────────────────
const paletteResults = computed(() => buildResults(paletteQuery.value));

function openPalette() {
    paletteQuery.value = '';
    showPalette.value = true;
}

function selectPaletteResult(result) {
    goToResult(result);
    showPalette.value = false;
}

function onGlobalKeydown(e) {
    // Strg+K / Cmd+K öffnet die Schnellsuche
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        openPalette();
    }
}

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
        'einvoice': EinvoiceTab,
        'crm': CrmTab,
        'lxcars': LxCarsTab,
        'anpr': AnprTab,
        'ai_health': AiHealthTab,
        'employees': EmployeesTab,
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

    // Feature-Booleans normalisieren (DB liefert Strings wie 'true'/'false'/'1'/'0')
    // Nicht gesetzt = Standard aktiviert
    for (const key of ['feature_anpr', 'feature_nvr']) {
        const v = crmDefaults.value[key];
        crmDefaults.value[key] = (v === null || v === undefined)
            ? true
            : (v === true || v === 'true' || v === '1' || v === 1);
    }
    previousFeatureAnpr = crmDefaults.value.feature_anpr;
    previousFeatureNvr  = crmDefaults.value.feature_nvr;
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

            lastSaved.value = new Date();
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
 * Lädt den Katalog der verfügbaren Erweiterungen samt Aktivierungszustand
 */
async function loadExtensions() {
    try {
        const response = await api.post('/oserp_config/', { action: 'getExtensions' });
        availableExtensions.value = response.data.success
            ? (response.data.payload?.results || [])
            : [];
    } catch (error) {
        console.error('getExtensions fehlgeschlagen:', error);
        availableExtensions.value = [];
    }
}

/**
 * Ein Schalter im Erweiterungs-Abschnitt wurde umgelegt: Bestätigung einholen,
 * gespeichert wird erst nach Zustimmung. Die Anzeige bleibt bis dahin unverändert.
 */
function onToggleExtension({ name, enabled }) {
    const active = availableExtensions.value.filter(e => e.active).map(e => e.name);
    pendingExtensions.value = enabled
        ? [...new Set([...active, name])]
        : active.filter(n => n !== name);
    showExtensionChangeDialog.value = true;
}

/**
 * Bricht die Änderung ab — die Anzeige stellt sich von selbst zurück,
 * da der Schalter nur den Zustand aus dem Katalog spiegelt.
 */
function cancelExtensionChange() {
    showExtensionChangeDialog.value = false;
    pendingExtensions.value = [];
    availableExtensions.value = [...availableExtensions.value];
}

/**
 * Führt die Änderung durch: Erweiterungen speichern, Schema-Update, App-Reload
 */
async function applyExtensionChange() {
    extensionUpdateLoading.value = true;

    try {
        // 1. Aktive Erweiterungen speichern (Tabelle extensions_oserp)
        const saveResponse = await api.post('/oserp_config/', {
            action: 'saveExtensions',
            extensions: pendingExtensions.value
        });

        if (!saveResponse.data.success) {
            // Häufigster Fall: Die Tabelle extensions_oserp fehlt noch, weil auf dieser
            // Installation das Schema-Update nach dem Rollout nicht gelaufen ist.
            const code = saveResponse.data.text;
            toasts.error(code === 'SCHEMA_UPDATE_REQUIRED'
                ? t('extensionChange.schema_update_required')
                : t('extensionChange.save_failed'));
            extensionUpdateLoading.value = false;
            showExtensionChangeDialog.value = false;
            pendingExtensions.value = [];
            return;
        }

        // 2. Schema-Update ausführen (nur Company-DB, Erweiterungen wirken nur dort)
        const updateResponse = await api.post('/update/', {
            action: 'updateSchema',
            dry_run: false,
            auth_db: false,
            company_db: true
        });

        if (!updateResponse.data.success) {
            console.error('Schema-Update fehlgeschlagen:', updateResponse.data);
        }

        // 3. App neu laden — Menü, Routen und Store lesen die Erweiterungen neu ein
        window.location.reload();
    } catch (error) {
        console.error('Erweiterungs-Wechsel Fehler:', error);
        toasts.error(t('extensionChange.save_failed'));
        extensionUpdateLoading.value = false;
        showExtensionChangeDialog.value = false;
        pendingExtensions.value = [];
    }
}

/**
 * Bricht den Feature-Dienst-Toggle ab und stellt den alten Wert wieder her
 */
function cancelFeatureService() {
    if (!pendingFeatureService.value) return;
    const { feature, enabled } = pendingFeatureService.value;
    if (feature === 'anpr') {
        crmDefaults.value.feature_anpr = !enabled;
        previousFeatureAnpr = !enabled;
    } else if (feature === 'nvr') {
        crmDefaults.value.feature_nvr = !enabled;
        previousFeatureNvr = !enabled;
    }
    showFeatureServiceDialog.value = false;
    pendingFeatureService.value = null;
    featureServiceErrorDetail.value = null;
    copiedCmd.value = '';
}

/**
 * Führt den Feature-Dienst-Toggle durch: Config speichern + Dienst stoppen/starten
 */
async function applyFeatureService() {
    if (!pendingFeatureService.value) return;
    const { feature, enabled } = pendingFeatureService.value;
    featureServiceLoading.value = true;
    featureServiceErrorDetail.value = null;

    try {
        await saveConfig();

        const response = await api.post('/camera/', {
            action: 'setFeatureService',
            feature,
            enabled,
        });

        if (response.data.success) {
            if (feature === 'anpr') previousFeatureAnpr = enabled;
            else if (feature === 'nvr') previousFeatureNvr = enabled;
            showFeatureServiceDialog.value = false;
            pendingFeatureService.value = null;
            toasts.success(enabled ? t('featureService.enabled_toast') : t('featureService.disabled_toast'));
        } else {
            const payload = response.data.payload || {};
            const rawOutput = Object.values(payload.results || {})
                .map(r => r.output).filter(Boolean).join('\n').trim();
            featureServiceErrorDetail.value = {
                message:        payload.message || response.data.text || t('featureService.error'),
                output:         rawOutput || null,
                manualCommands: payload.manual_commands || [],
            };
        }
    } catch (error) {
        featureServiceErrorDetail.value = {
            message:        error.message,
            output:         null,
            manualCommands: featureServiceMeta[feature]?.services
                                .split(', ')
                                .map(s => `sudo systemctl ${enabled ? 'start' : 'stop'} ${s}`) ?? [],
        };
    }

    featureServiceLoading.value = false;
}

/**
 * Erneuter Versuch nach Fehler
 */
function retryFeatureService() {
    featureServiceErrorDetail.value = null;
    copiedCmd.value = '';
    applyFeatureService();
}

/**
 * Kopiert einen Befehl in die Zwischenablage
 */
async function copyCmd(cmd) {
    try {
        await navigator.clipboard.writeText(cmd);
        copiedCmd.value = cmd;
        setTimeout(() => { copiedCmd.value = ''; }, 2000);
    } catch {
        /* Fallback: nicht unterstützt */
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
    window.addEventListener('keydown', onGlobalKeydown);
    await loadConfig();
    await loadExtensions();

    // Query-Parameter: ?tab=lxcars&focus=lxcars_yellow_label_printer
    const tabParam = route.query.tab;
    if (tabParam && allTabs.value.some(t => t.value === tabParam)) {
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

onUnmounted(() => {
    window.removeEventListener('keydown', onGlobalKeydown);
});
</script>

<style scoped>
.border-e {
    border-right: 1px solid rgba(0, 0, 0, 0.12);
}
/* Sidebar bleibt beim Scrollen sichtbar; lange Listen scrollen intern */
.sidebar-col :deep(> .v-card) {
    position: sticky;
    top: 0;
    max-height: 100vh;
    overflow-y: auto;
}
.sidebar-col :deep(.v-list-subheader) {
    min-height: 28px;
    opacity: 0.7;
}
.feature-error-pre {
    font-size: 11px;
    background: #1e1e1e;
    color: #f48771;
    padding: 8px 10px;
    border-radius: 4px;
    white-space: pre-wrap;
    word-break: break-all;
    max-height: 140px;
    overflow-y: auto;
    margin: 0;
}
.feature-cmd-code {
    font-family: monospace;
    font-size: 12px;
    background: rgba(0, 0, 0, 0.06);
    padding: 4px 8px;
    border-radius: 4px;
    word-break: break-all;
}

/* Strg+K-Hinweis im Suchfeld */
.kbd-hint {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.03em;
    color: rgba(var(--v-theme-on-surface), 0.6);
    border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
    border-radius: 5px;
    padding: 1px 6px;
    white-space: nowrap;
    cursor: pointer;
    background: transparent;
}
.kbd-hint:hover {
    border-color: rgb(var(--v-theme-primary));
    color: rgb(var(--v-theme-primary));
}

/* Unterbereiche in der Navigation etwas dezenter */
.subsection-item {
    min-height: 32px;
}
.subsection-item :deep(.v-list-item-title) {
    opacity: 0.85;
}

/* Speicher-Status im Kopf */
.save-status {
    display: inline-flex;
    align-items: center;
    color: rgba(var(--v-theme-on-surface), 0.6);
    white-space: nowrap;
}

/* Befehls-Suche (Palette) */
.palette-list {
    max-height: 60vh;
    overflow-y: auto;
}
</style>
