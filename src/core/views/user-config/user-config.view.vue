<!-- src/core/views/user-config/user-config.view.vue -->

<template>
    <div>
        <navbar-view />

        <v-container fluid style="max-width: 800px">
            <v-card flat>
                <v-card-title class="d-flex align-center pa-4">
                    <v-icon class="me-2">mdi-cog</v-icon>
                    <span>{{ t('title') }}</span>
                </v-card-title>

                <v-divider />

                <v-card-text class="pa-4">
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
                            {{ t('configLoadError') }}
                        </v-alert-title>
                        <pre class="pa-3 bg-grey-lighten-4 rounded text-caption overflow-auto mt-2" style="max-height: 200px;">{{ configError }}</pre>
                    </v-alert>

                    <!-- Loading -->
                    <div v-else-if="!configLoaded" class="d-flex justify-center align-center pa-8">
                        <v-progress-circular indeterminate color="primary" />
                        <span class="ml-3">{{ t('loading') }}</span>
                    </div>

                    <!-- Dynamische Felder -->
                    <template v-else v-for="field in userConfigFields" :key="field.name">
                        <!-- Ueberschrift -->
                        <v-row v-if="field.type === 'headline'" class="mt-6 mb-2">
                            <v-col cols="12">
                                <h3 class="text-h6 text-primary">{{ t(field.label) }}</h3>
                                <v-divider class="mt-2" />
                            </v-col>
                        </v-row>

                        <!-- Checkbox: speichert sofort bei Klick -->
                        <v-row v-else-if="field.type === 'checkbox'" class="my-1">
                            <v-col cols="12" md="6">
                                <v-checkbox
                                    v-model="configValues[field.name]"
                                    :label="t(field.label)"
                                    hide-details="auto"
                                    density="compact"
                                    @update:model-value="saveField(field.name, $event)"
                                >
                                    <template v-if="field.tooltip" #append>
                                        <v-tooltip location="top">
                                            <template #activator="{ props }">
                                                <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                            </template>
                                            {{ t(field.tooltip) }}
                                        </v-tooltip>
                                    </template>
                                </v-checkbox>
                            </v-col>
                        </v-row>

                        <!-- Input / Password: speichert bei blur -->
                        <v-row v-else-if="field.type === 'input' || field.type === 'password'" class="my-1">
                            <v-col cols="12" md="6">
                                <v-text-field
                                    v-model="configValues[field.name]"
                                    :label="t(field.label)"
                                    :type="field.type === 'password' ? 'password' : (field.inputType || 'text')"
                                    :style="field.fieldstyle"
                                    hide-details="auto"
                                    density="compact"
                                    variant="outlined"
                                    @blur="saveField(field.name, configValues[field.name])"
                                >
                                    <template v-if="field.tooltip" #append-inner>
                                        <v-tooltip location="top">
                                            <template #activator="{ props }">
                                                <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                            </template>
                                            {{ t(field.tooltip) }}
                                        </v-tooltip>
                                    </template>
                                </v-text-field>
                            </v-col>
                        </v-row>

                        <!-- Select: speichert sofort bei Auswahl -->
                        <v-row v-else-if="field.type === 'select'" class="my-1">
                            <v-col cols="12" md="6">
                                <v-select
                                    v-model="configValues[field.name]"
                                    :items="getFieldItems(field)"
                                    :label="t(field.label)"
                                    :style="field.fieldstyle"
                                    hide-details="auto"
                                    density="compact"
                                    variant="outlined"
                                    :clearable="!!field.clearable"
                                    @update:model-value="saveField(field.name, $event)"
                                >
                                    <template v-if="field.tooltip" #append-inner>
                                        <v-tooltip location="top">
                                            <template #activator="{ props }">
                                                <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                            </template>
                                            {{ t(field.tooltip) }}
                                        </v-tooltip>
                                    </template>
                                </v-select>
                            </v-col>
                        </v-row>
                    </template>
                </v-card-text>
            </v-card>
        </v-container>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';
import NavbarView from '@/core/components/navbar/navbar.view.vue';

const { t } = useI18n();
const store = oserpStore();

const userConfigFields = ref([]);
const configValues = ref({});
const configError = ref(null);
const configLoaded = ref(false);

/**
 * Laedt die Config-Felddefinitionen
 */
async function loadConfigFile() {
    try {
        const config = await import('./userConfig.js');
        userConfigFields.value = config.default || [];
        configError.value = null;
        configLoaded.value = true;
    } catch (error) {
        console.error('Error loading userConfig.js:', error);
        configError.value = error.message;
        configLoaded.value = false;
    }
}

/**
 * Laedt die gespeicherten Werte aus dem Store
 */
function loadConfigValues() {
    const employeeConfig = store.session?.company_config?.company_employee_config || {};

    userConfigFields.value.forEach(field => {
        if (field.type !== 'headline') {
            const value = employeeConfig[field.name];

            if (field.type === 'checkbox') {
                configValues.value[field.name] =
                    value === true || value === 'true' || value === 't' || value === '1' || value === 1;
            } else {
                configValues.value[field.name] = value || '';
            }
        }
    });
}

/**
 * Speichert einen einzelnen Config-Wert ueber den Store
 */
function saveField(key, value) {
    store.setConfigValue(key, value);
}

/**
 * Liefert Items fuer Select-Felder.
 * Unterstuetzt statische (field.items) und dynamische (field.storeItems) Quellen.
 */
function getFieldItems(field) {
    if (field.storeItems) {
        const list = store.session?.company_config?.[field.storeItems] || [];
        return list.map(item => ({
            value: String(item[field.itemValue || 'id']),
            title: item[field.itemTitle || 'name'] || String(item[field.itemValue || 'id'])
        }));
    }
    return field.items || [];
}

onMounted(async () => {
    await loadConfigFile();
    if (!configError.value) {
        loadConfigValues();
    }
});
</script>
