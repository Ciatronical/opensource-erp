<!-- src/core/views/config/tabs/shop-defaults.tab.vue -->

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
                    <strong>Datei:</strong> <code>src/core/views/config/tabs/shopDefaultsConfig.js</code>
                </div>

                <v-divider class="my-3"></v-divider>

                <div class="text-body-2 font-weight-bold mb-2">{{ t('errorDetails') || 'Fehlerdetails' }}:</div>
                <pre class="pa-3 bg-grey-lighten-4 rounded text-caption overflow-auto" style="max-height: 200px;">{{ configError }}</pre>
            </div>
        </v-alert>

        <!-- Ladeanzeige -->
        <div v-else-if="!configLoaded" class="d-flex justify-center align-center pa-8">
            <v-progress-circular indeterminate color="primary" />
            <span class="ml-3">{{ t('loadingConfiguration') }}</span>
        </div>

        <template v-else>
            <!-- Geheimnisse werden aus Sicherheitsgründen nicht geladen -->
            <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                <v-icon start size="small">mdi-shield-key-outline</v-icon>
                {{ t('crm_fields.shopSecretsNotice') }}
            </v-alert>

            <template v-for="field in shopConfig" :key="field.name">
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

                <!-- Eingabefeld / Passwort -->
                <v-row v-else-if="field.type === 'input' || field.type === 'password'" class="my-1" :data-field-name="field.name">
                    <v-col cols="12" md="6">
                        <v-text-field
                            v-model="crmDefaults[field.name]"
                            :label="t(field.label)"
                            :type="field.type === 'password' ? 'password' : (field.inputType || 'text')"
                            :placeholder="field.type === 'password' ? t('crm_fields.shopSecretKeep') : undefined"
                            :persistent-placeholder="field.type === 'password'"
                            :style="field.fieldstyle"
                            hide-details="auto"
                            density="compact"
                            variant="outlined"
                            autocomplete="new-password"
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

                <!-- Auswahl mit festen Werten -->
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

                <!-- Auswahl aus company_config -->
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

const props = defineProps({
    crmDefaults: {
        type: Object,
        required: true
    }
});

const shopConfig = ref([]);
const configError = ref(null);
const configLoaded = ref(false);

/**
 * Lädt die Felddefinition
 */
async function loadConfigFile() {
    try {
        const config = await import('./shopDefaultsConfig.js');
        shopConfig.value = config.default || [];
        configError.value = null;
        configLoaded.value = true;
    } catch (error) {
        console.error('Error loading shopDefaultsConfig.js:', error);
        configError.value = error.message;
        shopConfig.value = [];
        configLoaded.value = false;
    }
}

/**
 * Wandelt die Werte aus der Datenbank in JavaScript-Typen
 *
 * Wahrheitswerte kommen als 't'/'f'/'1'/'0'. Anders als bei LxCars werden
 * dynamic-selects nicht in Zahlen gewandelt: shop_contact_login ist ein
 * Login-Name, keine ID.
 *
 * Passwortfelder bleiben leer — sie werden von getCompanyConfig bewusst nicht
 * ausgeliefert, und cleanData() übergeht leere Felder beim Speichern.
 */
function normalizeShopDefaults() {
    shopConfig.value.forEach(field => {
        if (field.type === 'checkbox') {
            const value = props.crmDefaults[field.name];
            props.crmDefaults[field.name] =
                value === true ||
                value === 'true' ||
                value === 't' ||
                value === '1' ||
                value === 1;
        } else if (field.type === 'password') {
            props.crmDefaults[field.name] = '';
        }
    });
}

onMounted(async () => {
    await loadConfigFile();
    if (!configError.value) {
        normalizeShopDefaults();
    }
});

defineExpose({
    normalizeShopDefaults
});
</script>
