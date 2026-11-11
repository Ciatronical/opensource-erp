<!-- src/core/views/user-config/components/theme.field.vue -->

<!--
    Farbschema und Modus des Benutzers. Speichert sofort über den Store
    (employee_config_oserp: theme_palette, theme_mode); useUserPrefs
    wendet die Änderung live an.
-->
<template>
    <div class="theme-field">
        <div class="theme-field__label">{{ t('ThemePicker.palette') }}</div>
        <theme-palette-picker
            :model-value="palette"
            :company-palette="companyPalette"
            @update:model-value="savePalette"
        />
        <div class="theme-field__hint">{{ t('ThemePicker.userHint') }}</div>

        <div class="theme-field__label mt-4">{{ t('ThemePicker.mode') }}</div>
        <v-btn-toggle
            :model-value="mode"
            mandatory
            divided
            variant="outlined"
            density="comfortable"
            color="primary"
            @update:model-value="saveMode"
        >
            <v-btn v-for="m in modes" :key="m.value" :value="m.value" :prepend-icon="m.icon">
                {{ t(`ThemePicker.modes.${m.value}`) }}
            </v-btn>
        </v-btn-toggle>
        <div class="theme-field__hint">{{ t('ThemePicker.modeHint') }}</div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { resolveThemeMode } from '@/core/composables/useUserPrefs.js';
import { paletteKey } from '@/core/theme/palettes.js';
import ThemePalettePicker from '@/core/components/theme/theme-palette-picker.vue';

const { t } = useI18n();
const store = oserpStore();

const modes = [
    { value: 'light', icon: 'mdi-white-balance-sunny' },
    { value: 'dark', icon: 'mdi-weather-night' },
    { value: 'system', icon: 'mdi-monitor' },
];

const palette = computed(() => paletteKey(store.getConfigValue('theme_palette', '')) || '');
const companyPalette = computed(() => store.getClientDefaultValue('theme_palette', '') || '');
const mode = computed(() => resolveThemeMode(store));

/** '' = Firmenstandard: eigener Wert wird gelöscht, dann greift die Firma */
function savePalette(key) {
    if (key) store.setConfigValue('theme_palette', key);
    else store.deleteConfigValue('theme_palette');
}

function saveMode(value) {
    if (!value) return;
    store.setConfigValue('theme_mode', value);
    // Früherer Schalter: wird durch theme_mode ersetzt
    if (store.getConfigValue('dark_mode', null) !== null) store.deleteConfigValue('dark_mode');
}
</script>

<style scoped>
.theme-field {
    padding: var(--oserp-gap-xs) 0 var(--oserp-gap-sm);
}

.theme-field__label {
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: var(--oserp-gap-sm);
}

.theme-field__hint {
    font-size: 0.75rem;
    color: var(--oserp-text-muted);
    margin-top: var(--oserp-gap-xs);
}
</style>
