<!-- src/core/views/system/components/store-viewer.component.vue -->
<template>
    <v-card class="mb-4">
        <v-card-title class="bg-grey-lighten-4">
            <v-icon start>mdi-database-eye</v-icon>
            {{ t('DeveloperTools.store_viewer.title') }}
        </v-card-title>

        <v-card-text class="pa-4">
            <!-- Info-Text -->
            <v-alert
                type="info"
                variant="tonal"
                density="compact"
                class="mb-4"
            >
                {{ t('DeveloperTools.store_viewer.info') }}
            </v-alert>

            <!-- Store-Auswahl -->
            <v-row dense class="mb-4">
                <v-col cols="12" md="6">
                    <v-select
                        v-model="selectedStore"
                        :items="storeOptions"
                        :label="t('DeveloperTools.store_viewer.select_store')"
                        variant="outlined"
                        density="compact"
                    ></v-select>
                </v-col>
                <v-col cols="12" md="6" class="d-flex align-center">
                    <v-btn
                        color="primary"
                        variant="outlined"
                        prepend-icon="mdi-content-copy"
                        @click="copyFullStore"
                        size="small"
                        class="mr-2"
                    >
                        {{ t('DeveloperTools.store_viewer.copy_all') }}
                    </v-btn>
                    <v-btn
                        color="secondary"
                        variant="outlined"
                        :prepend-icon="allExpanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                        @click="toggleExpandAll"
                        size="small"
                        class="mr-2"
                    >
                        {{ allExpanded ? t('DeveloperTools.store_viewer.collapse_all') : t('DeveloperTools.store_viewer.expand_all') }}
                    </v-btn>
                    <v-btn
                        color="secondary"
                        variant="outlined"
                        prepend-icon="mdi-refresh"
                        @click="refreshStore"
                        size="small"
                    >
                        {{ t('DeveloperTools.store_viewer.refresh') }}
                    </v-btn>
                </v-col>
            </v-row>

            <!-- Store-Daten Tree-View -->
            <v-expansion-panels
                v-model="expandedPanels"
                multiple
                variant="accordion"
            >
                <v-expansion-panel
                    v-for="(value, key) in currentStoreData"
                    :key="key"
                    :value="key"
                >
                    <v-expansion-panel-title>
                        <div class="d-flex align-center justify-space-between" style="width: 100%;">
                            <div class="store-header-content">
                                <strong>{{ key }}</strong>
                                <span class="store-type-label">{{ getTypeLabel(value) }}</span>
                            </div>
                            <div @click.stop class="ml-auto">
                                <v-btn
                                    icon="mdi-content-copy"
                                    size="x-small"
                                    variant="text"
                                    @click="copyPath(key)"
                                    :title="t('DeveloperTools.store_viewer.copy_path')"
                                ></v-btn>
                                <v-btn
                                    icon="mdi-code-json"
                                    size="x-small"
                                    variant="text"
                                    @click="copyValue(key, value)"
                                    :title="t('DeveloperTools.store_viewer.copy_value')"
                                ></v-btn>
                            </div>
                        </div>
                    </v-expansion-panel-title>

                    <v-expansion-panel-text>
                        <!-- Primitive Values -->
                        <div v-if="isPrimitive(value)" class="pa-2">
                            <div class="store-primitive-value">{{ formatValue(value) }}</div>
                        </div>

                        <!-- Objects/Arrays -->
                        <div v-else>
                            <store-tree-item
                                :data="value"
                                :path="key"
                                :expand-all="allExpanded"
                                @copy-path="copyPath"
                                @copy-value="copyValue"
                            />
                        </div>
                    </v-expansion-panel-text>
                </v-expansion-panel>
            </v-expansion-panels>

            <!-- Keine Daten -->
            <v-alert
                v-if="Object.keys(currentStoreData).length === 0"
                type="warning"
                variant="tonal"
                density="compact"
            >
                {{ t('DeveloperTools.store_viewer.no_data') }}
            </v-alert>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { getActivePinia } from 'pinia';
import * as toasts from '@/core/utils/toasts.js';
import StoreTreeItem from './store-tree-item.component.vue';

const { t } = useI18n();

// State
const selectedStore = ref('oserpStore');
const expandedPanels = ref([]);
const allExpanded = ref(false);
const availableStores = ref([]);

/**
 * Findet alle verfügbaren Pinia Stores
 */
function findAllStores() {
    const pinia = getActivePinia();
    const stores = [];

    if (pinia && pinia._s) {
        // Iteriere über alle registrierten Stores
        pinia._s.forEach((store, key) => {
            stores.push({
                title: key,
                value: key
            });
        });
    }

    return stores;
}

/**
 * Store-Optionen für die Auswahl
 */
const storeOptions = computed(() => {
    return availableStores.value;
});

/**
 * Holt die Daten eines Stores anhand des Store-Namens
 *
 * @param {string} storeName - Name des Stores
 * @returns {Object} Store-Daten
 */
function getStoreData(storeName) {
    const pinia = getActivePinia();

    if (!pinia || !pinia._s) {
        return {};
    }

    const store = pinia._s.get(storeName);

    if (!store) {
        return {};
    }

    // Hole alle State-Properties des Stores
    const storeData = {};

    // Iteriere über alle Keys im Store und extrahiere nur die State-Properties
    for (const key in store) {
        // Überspringe interne Pinia Properties
        if (key.startsWith('_') || key.startsWith('$')) {
            continue;
        }

        // Überspringe Funktionen (Actions/Getters werden nicht angezeigt)
        if (typeof store[key] === 'function') {
            continue;
        }

        storeData[key] = store[key];
    }

    return storeData;
}

/**
 * Aktuelle Store-Daten basierend auf Auswahl
 */
const currentStoreData = computed(() => {
    return getStoreData(selectedStore.value);
});

/**
 * Prüft ob ein Wert primitiv ist
 *
 * @param {*} value - Zu prüfender Wert
 * @returns {boolean}
 */
function isPrimitive(value) {
    return value === null ||
           value === undefined ||
           typeof value === 'string' ||
           typeof value === 'number' ||
           typeof value === 'boolean';
}

/**
 * Formatiert einen Wert für die Anzeige
 *
 * @param {*} value - Zu formatierender Wert
 * @returns {string}
 */
function formatValue(value) {
    if (value === null) return 'null';
    if (value === undefined) return 'undefined';
    if (typeof value === 'string') return `"${value}"`;
    return String(value);
}

/**
 * Gibt das Type-Label zurück
 *
 * @param {*} value - Wert
 * @returns {string}
 */
function getTypeLabel(value) {
    if (value === null) return 'null';
    if (value === undefined) return 'undefined';
    if (Array.isArray(value)) return `Array[${value.length}]`;
    if (typeof value === 'object') return `Object{${Object.keys(value).length}}`;
    return typeof value;
}

/**
 * Kopiert einen Pfad in die Zwischenablage
 *
 * @param {string} path - Zu kopierender Pfad
 */
function copyPath(path) {
    const fullPath = `store.${path}`;
    navigator.clipboard.writeText(fullPath);
    toasts.success(t('DeveloperTools.store_viewer.path_copied', { path: fullPath }));
}

/**
 * Kopiert einen Wert als JSON in die Zwischenablage
 *
 * @param {string} path - Pfad des Werts
 * @param {*} value - Zu kopierender Wert
 */
function copyValue(path, value) {
    let copyText;

    // Bei primitiven Werten: Kopiere den Raw-Wert ohne Anführungszeichen
    if (typeof value === 'string') {
        copyText = value;
    } else if (typeof value === 'number' || typeof value === 'boolean') {
        copyText = String(value);
    } else if (value === null) {
        copyText = 'null';
    } else if (value === undefined) {
        copyText = 'undefined';
    } else {
        // Bei Objekten/Arrays: Kopiere als JSON
        copyText = JSON.stringify(value, null, 4);
    }

    navigator.clipboard.writeText(copyText);
    toasts.success(t('DeveloperTools.store_viewer.value_copied', { path }));
}

/**
 * Kopiert den kompletten Store als JSON
 */
function copyFullStore() {
    const jsonStore = JSON.stringify(currentStoreData.value, null, 4);
    navigator.clipboard.writeText(jsonStore);
    toasts.success(t('DeveloperTools.store_viewer.store_copied'));
}

/**
 * Aktualisiert die Store-Anzeige
 */
function refreshStore() {
    // Lade verfügbare Stores neu
    availableStores.value = findAllStores();

    // Reset expanded panels
    expandedPanels.value = [];
    allExpanded.value = false;

    console.log('Refreshed stores:', availableStores.value);
    toasts.success(t('DeveloperTools.store_viewer.refreshed'));
}

/**
 * Toggle: Alle Ebenen auf- oder zuklappen
 */
function toggleExpandAll() {
    allExpanded.value = !allExpanded.value;
    if (allExpanded.value) {
        expandedPanels.value = Object.keys(currentStoreData.value);
        toasts.success(t('DeveloperTools.store_viewer.expanded_all'));
    } else {
        expandedPanels.value = [];
        toasts.success(t('DeveloperTools.store_viewer.collapsed_all'));
    }
}

/**
 * Lifecycle: Component Mounted
 */
onMounted(() => {
    // Finde alle verfügbaren Stores
    availableStores.value = findAllStores();

    // Expandiere Session standardmäßig wenn oserpStore ausgewählt
    if (selectedStore.value === 'oserpStore') {
        expandedPanels.value = ['session'];
    }

    console.log('Available Pinia Stores:', availableStores.value);
});

/**
 * Watch: Store-Wechsel
 */
watch(selectedStore, (newStore, oldStore) => {
    console.log(`Store changed from ${oldStore} to ${newStore}`);

    // Reset expanded panels beim Store-Wechsel
    expandedPanels.value = [];
    allExpanded.value = false;

    // Expandiere wichtige Panels je nach Store
    if (newStore === 'oserpStore') {
        expandedPanels.value = ['session'];
    }
});
</script>

<style scoped>
.store-header-content {
    display: flex;
    align-items: center;
    gap: 16px;
}

.store-header-content strong {
    color: #0d47a1;
    font-size: 15px;
}

.store-type-label {
    color: #666;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    font-weight: 600;
    background-color: #fafafa;
    padding: 3px 10px;
    border-radius: 4px;
    border: 1px solid #e0e0e0;
}

.store-primitive-value {
    font-family: 'Courier New', monospace;
    font-size: 13px;
    font-weight: 600;
    color: #1b5e20;
    padding: 10px 14px;
    background: linear-gradient(135deg, #f1f8f4 0%, #e8f5e9 100%);
    border: 1px solid #c8e6c9;
    border-left: 3px solid #4caf50;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(76, 175, 80, 0.1);
}
</style>