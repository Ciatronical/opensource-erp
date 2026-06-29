<!-- opensource-erp/frontend/src/views/config/tabs/crm-defaults.tab.vue -->

<template>
    <v-container fluid class="pa-0">
        <!-- Fehler beim Laden der Config -->
        <v-alert
            v-if="configError"
            type="error"
            variant="tonal"
            prominent
            border="start"
        >
            <v-alert-title class="text-h6">
                <v-icon start>mdi-alert-circle</v-icon>
                {{ t('configLoadError') || 'Konfigurationsfehler' }}
            </v-alert-title>

            <div class="mt-4">
                <div class="text-body-1 mb-2">{{ t('syntaxErrorInConfigFile') || 'Syntaxfehler in der Konfigurationsdatei' }}</div>
                <div class="text-caption text-grey-darken-1 mb-4">
                    <strong>Datei:</strong> <code>src/core/views/config/tabs/crmDefaultsConfig.js</code>
                </div>

                <v-divider class="my-3"></v-divider>

                <div class="text-body-2 font-weight-bold mb-2">{{ t('errorDetails') || 'Fehlerdetails' }}:</div>
                <pre class="pa-3 bg-grey-lighten-4 rounded text-caption overflow-auto" style="max-height: 200px;">{{ configError }}</pre>

                <div class="mt-4 text-body-2">
                    <v-icon size="small" class="me-1">mdi-lightbulb-outline</v-icon>
                    {{ t('checkForMissingComma') || 'Häufige Fehler: Fehlende Kommas zwischen Objekten, falsche Anführungszeichen' }}
                </div>
            </div>
        </v-alert>

        <!-- Loading Spinner während Config geladen wird -->
        <div v-else-if="!configLoaded" class="d-flex justify-center align-center pa-8">
            <v-progress-circular indeterminate color="primary" />
            <span class="ml-3">Lade Konfiguration...</span>
        </div>

        <!-- Dynamische Felder - NUR wenn Config geladen ist! -->
        <template v-else v-for="field in crmDefaultsConfig" :key="field.name">
            <!-- Überschrift -->
            <v-row v-if="field.type === 'headline'" class="mt-6 mb-2">
                <v-col cols="12">
                    <h3 class="text-h6 text-primary">
                        {{ t(field.label) }}
                    </h3>
                    <v-divider class="mt-2"></v-divider>
                </v-col>
            </v-row>

            <!-- Checkbox -->
            <v-row v-else-if="field.type === 'checkbox'" class="my-1">
                <v-col cols="12" md="6">
                    <v-checkbox
                        v-model="crmDefaults[field.name]"
                        :label="t(field.label)"
                        hide-details="auto"
                        density="compact"
                    >
                        <template v-if="field.tooltip" #append>
                            <v-tooltip location="top">
                                <template #activator="{ props }">
                                    <v-icon v-bind="props" size="small" color="grey">
                                        mdi-information-outline
                                    </v-icon>
                                </template>
                                {{ t(field.tooltip) }}
                            </v-tooltip>
                        </template>
                    </v-checkbox>
                </v-col>
            </v-row>

            <!-- Input / Password -->
            <v-row v-else-if="field.type === 'input' || field.type === 'password'" class="my-1">
                <v-col cols="12" md="6">
                    <v-text-field
                        v-model="crmDefaults[field.name]"
                        :label="t(field.label)"
                        :type="field.type === 'password' ? 'password' : (field.inputType || 'text')"
                        :style="field.fieldstyle"
                        hide-details="auto"
                        density="compact"
                        variant="outlined"
                    >
                        <template v-if="field.tooltip" #append-inner>
                            <v-tooltip location="top">
                                <template #activator="{ props }">
                                    <v-icon v-bind="props" size="small" color="grey">
                                        mdi-information-outline
                                    </v-icon>
                                </template>
                                {{ t(field.tooltip) }}
                            </v-tooltip>
                        </template>
                    </v-text-field>
                </v-col>
            </v-row>

            <!-- Textarea -->
            <v-row v-else-if="field.type === 'textarea'" class="my-1">
                <v-col cols="12" md="8">
                    <v-textarea
                        v-model="crmDefaults[field.name]"
                        :label="t(field.label)"
                        :rows="field.rows || 6"
                        :style="field.fieldstyle"
                        hide-details="auto"
                        density="compact"
                        variant="outlined"
                    >
                        <template v-if="field.tooltip" #append-inner>
                            <v-tooltip location="top">
                                <template #activator="{ props }">
                                    <v-icon v-bind="props" size="small" color="grey">
                                        mdi-information-outline
                                    </v-icon>
                                </template>
                                {{ t(field.tooltip) }}
                            </v-tooltip>
                        </template>
                    </v-textarea>
                </v-col>
            </v-row>

            <!-- Select features -->
            <v-row v-else-if="field.type === 'select'" class="my-1">
                <v-col cols="12" md="6">
                    <v-select
                        v-model="crmDefaults[field.name]"
                        :items="field.items"
                        :label="t(field.label)"
                        :style="field.fieldstyle"
                        hide-details="auto"
                        density="compact"
                        variant="outlined"
                    >
                        <template v-if="field.tooltip" #append-inner>
                            <v-tooltip location="top">
                                <template #activator="{ props }">
                                    <v-icon v-bind="props" size="small" color="grey">
                                        mdi-information-outline
                                    </v-icon>
                                </template>
                                {{ t(field.tooltip) }}
                            </v-tooltip>
                        </template>
                    </v-select>
                </v-col>
            </v-row>

            <!-- Hinweis mit klickbarem Link -->
            <v-row v-else-if="field.type === 'info'" class="my-1">
                <v-col cols="12" md="8">
                    <v-alert
                        type="info"
                        variant="tonal"
                        density="compact"
                        :icon="field.icon || 'mdi-information-outline'"
                    >
                        {{ t(field.label) }}
                        <a v-if="field.url" :href="field.url" target="_blank" rel="noopener noreferrer" class="ms-1">
                            {{ field.url }}
                        </a>
                    </v-alert>
                </v-col>
            </v-row>

            <!-- Custom Component (z.B. WhatsApp Templates) -->
            <v-row v-else-if="field.type === 'component' && field.component === 'whatsapp-templates'" class="my-1">
                <v-col cols="12">
                    <WhatsAppTemplatesConfig />
                </v-col>
            </v-row>

            <!-- WhatsApp Profilbild -->
            <v-row v-else-if="field.type === 'component' && field.component === 'whatsapp-profile-picture'" class="my-1">
                <v-col cols="12">
                    <WhatsAppProfilePictureConfig />
                </v-col>
            </v-row>

            <!-- SumUp Reader koppeln -->
            <v-row v-else-if="field.type === 'component' && field.component === 'sumup-reader-pairing'" class="my-1">
                <v-col cols="12">
                    <SumupReaderPairingConfig />
                </v-col>
            </v-row>

            <!-- eBay: Verbindung testen / Bestellungen abrufen / Status -->
            <v-row v-else-if="field.type === 'component' && field.component === 'ebay-status'" class="my-1">
                <v-col cols="12">
                    <EbayStatusConfig />
                </v-col>
            </v-row>

            <!-- Dynamic Select (Items aus company_config) -->
            <v-row v-else-if="field.type === 'dynamic-select'" class="my-1">
                <v-col cols="12" md="6">
                    <v-select
                        v-model="crmDefaults[field.name]"
                        :items="getDynamicItems(field.source)"
                        :item-title="field.itemTitle || 'title'"
                        :item-value="field.itemValue || 'value'"
                        :label="t(field.label)"
                        :style="field.fieldstyle"
                        hide-details="auto"
                        density="compact"
                        variant="outlined"
                        clearable
                    >
                        <template v-if="field.tooltip" #append-inner>
                            <v-tooltip location="top">
                                <template #activator="{ props }">
                                    <v-icon v-bind="props" size="small" color="grey">
                                        mdi-information-outline
                                    </v-icon>
                                </template>
                                {{ t(field.tooltip) }}
                            </v-tooltip>
                        </template>
                    </v-select>
                </v-col>
            </v-row>
        </template>
    </v-container>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import WhatsAppTemplatesConfig from './whatsapp-templates.config.vue';
import WhatsAppProfilePictureConfig from './whatsapp-profile-picture.config.vue';
import SumupReaderPairingConfig from './sumup-reader-pairing.config.vue';
import EbayStatusConfig from './ebay-status.config.vue';

const { t } = useI18n();

// Cache für dynamisch geladene Select-Items (z.B. WhatsApp-Templates)
const dynamicItemsCache = reactive({ whatsapp_templates: [] });

function getDynamicItems(source) {
    if (dynamicItemsCache[source]?.length) return dynamicItemsCache[source];
    return [];
}

/**
 * Lädt WhatsApp-Templates für die dynamic-select Felder
 * Alle Templates laden (nicht nur approved), damit die Zuordnung auch vor Meta-Freigabe moeglich ist
 */
async function loadDynamicSelectSources() {
    try {
        const resp = await axios.post('/api/whatsapp/', { action: 'getWhatsAppTemplates' });
        if (resp.data.success) {
            dynamicItemsCache.whatsapp_templates = resp.data.payload?.templates || resp.data.payload || [];
        }
    } catch {
        // Templates nicht verfügbar
    }
}

// Props: Übergebene defaults vom Parent
const props = defineProps({
    crmDefaults: {
        type: Object,
        required: true
    }
});

// State für Config und Fehler
const crmDefaultsConfig = ref([]);
const configError = ref(null);
const configLoaded = ref(false);

/**
 * Lädt die Config-Datei mit Error Handling
 */
async function loadConfigFile() {
    try {
        const config = await import('./crmDefaultsConfig.js');
        crmDefaultsConfig.value = config.default || config.crmDefaultsConfig || [];
        configError.value = null;
        configLoaded.value = true;
    } catch (error) {
        console.error('Error loading crmDefaultsConfig.js:', error);
        configError.value = error.message;
        crmDefaultsConfig.value = [];
        configLoaded.value = false;
    }
}

/**
 * Normalisiert die CRM-Konfiguration aus den Prop-Daten (API)
 *
 * Konvertiert DB-Formate in JavaScript-Typen:
 * - Checkboxen: 't'/'f'/'1'/'0' → true/false
 * - Dynamic-Selects: String-IDs → Number
 *
 * Liest aus den Props (Parent hat frische API-Daten geladen),
 * NICHT aus dem Store (könnte veraltet sein).
 */
function normalizeCrmDefaults() {
    crmDefaultsConfig.value.forEach(field => {
        if (field.type === 'checkbox') {
            const value = props.crmDefaults[field.name];
            // Robuste Boolean-Konvertierung für verschiedene DB-Formate
            props.crmDefaults[field.name] =
                value === true ||
                value === 'true' ||
                value === 't' ||
                value === '1' ||
                value === 1;
        } else if (field.type === 'dynamic-select') {
            const value = props.crmDefaults[field.name];
            // Numerische IDs als Number speichern, damit v-select matchen kann
            const num = Number(value);
            props.crmDefaults[field.name] = (!isNaN(num) && value !== '' && value !== null) ? num : (value || '');
        }
    });
}

// Lade Config beim Mount
onMounted(async () => {
    await loadConfigFile();
    if (!configError.value) {
        normalizeCrmDefaults();
        loadDynamicSelectSources();
    }
});

// Exportiere normalizeCrmDefaults für Parent
defineExpose({
    normalizeCrmDefaults
});
</script>
