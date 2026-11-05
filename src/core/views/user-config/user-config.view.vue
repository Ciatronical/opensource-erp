<!-- src/core/views/user-config/user-config.view.vue -->

<template>
    <div>
        <navbar-view />

        <v-container fluid class="user-config">
            <page-header :title="t('title')" />

            <!-- Fehler beim Laden der Config -->
            <v-alert
                v-if="configError"
                type="error"
                variant="tonal"
                :title="t('configLoadError')"
            >
                <pre class="text-caption overflow-auto mt-2 user-config__error">{{ configError }}</pre>
            </v-alert>

            <!-- Loading -->
            <div v-else-if="!configLoaded" class="d-flex justify-center align-center pa-8">
                <v-progress-circular indeterminate color="primary" />
                <span class="ml-3">{{ t('loading') }}</span>
            </div>

            <!-- Je Überschrift eine Abschnittskarte mit ihren Feldern -->
            <section-card
                v-else
                v-for="section in sections"
                :key="section.name"
                :title="t(section.label)"
                :icon="section.icon"
                class="user-config__section"
            >
                <template v-for="field in section.fields" :key="field.name">
                    <!-- Unterschrift für Briefe: eigener Baustein mit Upload/Zeichnen -->
                    <signature-field v-if="field.type === 'signature'" />

                    <!-- Checkbox: speichert sofort bei Klick -->
                    <v-checkbox
                        v-else-if="field.type === 'checkbox'"
                        v-model="configValues[field.name]"
                        :label="t(field.label)"
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

                    <!-- Input / Password: speichert bei blur -->
                    <component
                        :is="field.type === 'password' ? PasswordField : VTextField"
                        v-else-if="field.type === 'input' || field.type === 'password'"
                        v-model="configValues[field.name]"
                        :label="t(field.label)"
                        :type="field.inputType || 'text'"
                        :style="field.fieldstyle"
                        class="user-config__field"
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
                    </component>

                    <!-- Select: speichert sofort bei Auswahl -->
                    <v-select
                        v-else-if="field.type === 'select'"
                        v-model="configValues[field.name]"
                        :items="getFieldItems(field)"
                        :label="t(field.label)"
                        :style="field.fieldstyle"
                        :clearable="!!field.clearable"
                        class="user-config__field"
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
                </template>
            </section-card>
        </v-container>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { VTextField } from 'vuetify/components';
import PasswordField from '@/core/components/password-field.vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import PageHeader from '@/core/components/layout/page-header.vue';
import SectionCard from '@/core/components/layout/section-card.vue';
import SignatureField from './components/signature.field.vue';
import { useRoute } from 'vue-router';

const { t } = useI18n();
const store = oserpStore();

const userConfigFields = ref([]);
const configValues = ref({});
const configError = ref(null);
const configLoaded = ref(false);
const route = useRoute();

/** Felder nach Überschrift gruppiert: eine Abschnittskarte je Überschrift */
const sections = computed(() => {
    const list = [];
    for (const field of userConfigFields.value) {
        if (field.type === 'headline') list.push({ ...field, fields: [] });
        else if (list.length) list[list.length - 1].fields.push(field);
    }
    return list;
});

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
        if (field.type !== 'headline' && field.type !== 'signature') {
            // Ohne gespeicherten Wert gilt der Standard aus der Felddefinition.
            // Sonst zeigt die Maske "aus", waehrend die Anwendung den Standard
            // bereits als "an" behandelt.
            const stored = employeeConfig[field.name];
            const value = stored === undefined || stored === null || stored === ''
                ? field.default
                : stored;

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
 * Statische Items mit `extension` erscheinen nur bei aktiver Erweiterung.
 */
function getFieldItems(field) {
    if (field.storeItems) {
        const list = store.session?.company_config?.[field.storeItems] || [];
        return list.map(item => ({
            value: String(item[field.itemValue || 'id']),
            title: item[field.itemTitle || 'name'] || String(item[field.itemValue || 'id'])
        }));
    }
    return (field.items || []).filter(item => !item.extension || store.isExtensionEnabled(item.extension));
}

onMounted(async () => {
    await loadConfigFile();
    if (!configError.value) {
        loadConfigValues();
    }
    // Sprung aus dem Briefeditor („Unterschrift jetzt hinterlegen“)
    if (route.hash) {
        await nextTick();
        document.querySelector(route.hash)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>

<style scoped>
.user-config {
    max-width: var(--oserp-content-max);
}
.user-config__section {
    margin-bottom: var(--oserp-gap-lg);
}
.user-config__field {
    max-width: 40ch;
    margin-block: var(--oserp-gap-sm);
}
.user-config__error {
    max-height: 200px;
}
</style>
