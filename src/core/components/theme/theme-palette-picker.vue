<!-- src/core/components/theme/theme-palette-picker.vue -->

<!--
    Farbschema wählen: ein Farbfeld je Palette (src/core/theme/palettes.js).
    Benutzt in der Benutzerkonfiguration (mit „Firmenstandard“) und in der
    Firmenkonfiguration (ohne). v-model ist der Palettenschlüssel, '' steht
    für „Firmenstandard“.
-->
<template>
    <div class="theme-picker" role="radiogroup" :lang="locale" :aria-label="t('ThemePicker.palette')">
        <button
            v-for="option in options"
            :key="option.key"
            type="button"
            role="radio"
            :aria-checked="option.key === selected"
            :class="['theme-picker__option', { 'theme-picker__option--active': option.key === selected }]"
            @click="emit('update:modelValue', option.key)"
        >
            <span class="theme-picker__swatch" :style="{ background: option.color }">
                <v-icon v-if="option.key === selected" size="20" :color="iconColor">mdi-check</v-icon>
                <v-icon v-else-if="option.key === ''" size="18" :color="iconColor">mdi-domain</v-icon>
            </span>
            <span class="theme-picker__label">{{ option.label }}</span>
            <span v-if="option.sub" class="theme-picker__sub">{{ option.sub }}</span>
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useTheme } from 'vuetify';
import { THEME_PALETTES, paletteKey } from '@/core/theme/palettes.js';

const props = defineProps({
    modelValue: { type: String, default: '' },
    // Palette der Firma: wenn gesetzt, steht „Firmenstandard“ als erste Wahl da
    companyPalette: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const { t, locale } = useI18n();
const theme = useTheme();

const modus = computed(() => (theme.global.current.value.dark ? 'dark' : 'light'));
// Im Dunkelmodus sind die Farbfelder helle Pastelltöne: Symbol dann dunkel
const iconColor = computed(() => (modus.value === 'dark' ? '#121212' : 'white'));

const selected = computed(() => {
    if (props.companyPalette !== null && !paletteKey(props.modelValue)) return '';
    return paletteKey(props.modelValue) || 'blau';
});

const options = computed(() => {
    const list = Object.entries(THEME_PALETTES).map(([key, farben]) => ({
        key,
        color: farben[modus.value],
        label: t(`ThemePicker.palettes.${key}`),
    }));
    if (props.companyPalette === null) return list;

    const firma = paletteKey(props.companyPalette) || 'blau';
    return [{
        key: '',
        color: THEME_PALETTES[firma][modus.value],
        label: t('ThemePicker.companyDefault'),
        sub: t(`ThemePicker.palettes.${firma}`),
    }, ...list];
});
</script>

<style scoped>
.theme-picker {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(70px, 1fr));
    gap: var(--oserp-gap-sm);
    max-width: 880px;
}

.theme-picker__option {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--oserp-gap-xs);
    min-width: 0;
    padding: var(--oserp-gap-sm) var(--oserp-gap-xs);
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius-sm);
    background: var(--oserp-surface);
    color: inherit;
    cursor: pointer;
    transition: border-color 0.15s, background-color 0.15s;
}

.theme-picker__option:hover {
    border-color: var(--oserp-border);
    background: var(--oserp-surface-soft);
}

.theme-picker__option:focus-visible {
    outline: 2px solid var(--oserp-primary);
    outline-offset: 2px;
}

.theme-picker__option--active {
    border-color: var(--oserp-primary);
    background: var(--oserp-primary-faint);
}

.theme-picker__swatch {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.12);
}

.theme-picker__label {
    max-width: 100%;
    font-size: 0.8125rem;
    line-height: 1.2;
    text-align: center;
    hyphens: auto;
    overflow-wrap: break-word;
}

.theme-picker__sub {
    font-size: 0.75rem;
    line-height: 1.1;
    color: var(--oserp-text-muted);
}
</style>
