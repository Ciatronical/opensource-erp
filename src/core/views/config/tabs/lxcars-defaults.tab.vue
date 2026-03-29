<!-- src/core/views/config/tabs/lxcars-defaults.tab.vue -->

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
                    <strong>Datei:</strong> <code>src/core/views/config/tabs/lxcarsDefaultsConfig.js</code>
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
        <template v-else v-for="field in lxcarsConfig" :key="field.name">
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
            <v-row v-else-if="field.type === 'checkbox'" class="my-1" :data-field-name="field.name">
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
            <v-row v-else-if="field.type === 'input' || field.type === 'password'" class="my-1" :data-field-name="field.name">
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
            <v-row v-else-if="field.type === 'textarea'" class="my-1" :data-field-name="field.name">
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

            <!-- Select -->
            <v-row v-else-if="field.type === 'select'" class="my-1" :data-field-name="field.name">
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

            <!-- Dynamic Select (Items aus company_config) -->
            <v-row v-else-if="field.type === 'dynamic-select'" class="my-1" :data-field-name="field.name">
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
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t } = useI18n();
const store = oserpStore();

function getDynamicItems(source) {
    return store.session?.[source] || store.session?.company_config?.[source] || [];
}

// Props: Übergebene defaults vom Parent
const props = defineProps({
    crmDefaults: {
        type: Object,
        required: true
    }
});

// State für Config und Fehler
const lxcarsConfig = ref([]);
const configError = ref(null);
const configLoaded = ref(false);

/**
 * Lädt die Config-Datei mit Error Handling
 */
async function loadConfigFile() {
    try {
        const config = await import('./lxcarsDefaultsConfig.js');
        lxcarsConfig.value = config.default || config.lxcarsDefaultsConfig || [];
        configError.value = null;
        configLoaded.value = true;
    } catch (error) {
        console.error('Error loading lxcarsDefaultsConfig.js:', error);
        configError.value = error.message;
        lxcarsConfig.value = [];
        configLoaded.value = false;
    }
}

/**
 * Normalisiert die LxCars-Konfiguration aus den Prop-Daten (API)
 *
 * Konvertiert DB-Formate in JavaScript-Typen:
 * - Checkboxen: 't'/'f'/'1'/'0' → true/false
 * - Dynamic-Selects: String-IDs → Number
 *
 * Liest aus den Props (Parent hat frische API-Daten geladen),
 * NICHT aus dem Store (könnte veraltet sein).
 */
function normalizeLxcarsDefaults() {
    lxcarsConfig.value.forEach(field => {
        if (field.type === 'checkbox') {
            const value = props.crmDefaults[field.name];
            props.crmDefaults[field.name] =
                value === true ||
                value === 'true' ||
                value === 't' ||
                value === '1' ||
                value === 1;
        } else if (field.type === 'dynamic-select') {
            const value = props.crmDefaults[field.name];
            if (value) {
                // IDs als Zahl speichern damit Vuetify den Eintrag matcht
                const num = Number(value);
                props.crmDefaults[field.name] = isNaN(num) ? value : num;
            }
        }
    });
}

// Lade Config beim Mount
onMounted(async () => {
    await loadConfigFile();
    if (!configError.value) {
        normalizeLxcarsDefaults();
    }
});

// Exportiere normalizeLxcarsDefaults für Parent
defineExpose({
    normalizeLxcarsDefaults
});
</script>
