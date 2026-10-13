<!-- src/core/components/layout/section-card.vue -->
<!--
    Abschnittskarte: Kopf mit Symbol, Titel und Aktionen, darunter der Inhalt.

    Ersetzt das in vielen Views kopierte Muster
      <v-card variant="outlined"><v-card-title class="bg-grey-lighten-4">…
    und bringt die Feld-Konventionen des Projekts (outlined, compact,
    hide-details auto) per v-defaults-provider mit — nur für ihren Inhalt,
    nicht global. Explizite Props am Feld gewinnen weiterhin.

    quiet: ruhige Darstellung ohne Kopffläche, für Lesebreite und den
    einfachen Modus. Kann auch von einer Elternansicht per
    provide('sectionQuiet', ref) für alle Karten darunter gesetzt werden.
    plain: Inhalt ohne Innenabstand (Tabellen, Listen).
-->
<template>
    <v-card
        variant="outlined"
        rounded="lg"
        class="oserp-section"
        :class="{ 'oserp-section--quiet': isQuiet }"
    >
        <div v-if="title || $slots.title || $slots.actions" class="oserp-section__head">
            <v-icon v-if="icon" size="small" class="oserp-section__icon">{{ icon }}</v-icon>
            <h3 class="oserp-section__title">
                <slot name="title">{{ title }}</slot>
            </h3>
            <span v-if="subtitle" class="oserp-section__subtitle">{{ subtitle }}</span>
            <v-spacer />
            <div v-if="$slots.actions" class="oserp-section__actions">
                <slot name="actions" />
            </div>
        </div>
        <div class="oserp-section__body" :class="{ 'oserp-section__body--plain': plain }">
            <v-defaults-provider :defaults="formDefaults">
                <slot />
            </v-defaults-provider>
        </div>
    </v-card>
</template>

<script setup>
import { computed, inject, unref } from 'vue'

const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: '' },
    quiet: { type: Boolean, default: false },
    plain: { type: Boolean, default: false },
})

const injectedQuiet = inject('sectionQuiet', false)
const isQuiet = computed(() => props.quiet || !!unref(injectedQuiet))

// Feld-Konventionen des Projekts, damit Karten sie nicht an jedem Feld wiederholen
const formDefaults = {
    VTextField: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VSelect: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VAutocomplete: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VCombobox: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VTextarea: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VNumberInput: { variant: 'outlined', density: 'compact', hideDetails: 'auto' },
    VSwitch: { density: 'compact', hideDetails: 'auto', color: 'primary' },
    VCheckbox: { density: 'compact', hideDetails: 'auto', color: 'primary' },
}
</script>

<style scoped>
.oserp-section {
    border-color: var(--oserp-border);
    background: var(--oserp-surface);
}

.oserp-section__head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
    padding: var(--oserp-gap-sm) var(--oserp-gap);
    min-height: 44px;
    background: var(--oserp-section-head-bg);
    border-bottom: 1px solid var(--oserp-border-soft);
}

.oserp-section__icon {
    color: rgb(var(--v-theme-primary));
}

.oserp-section__title {
    margin: 0;
    font-size: var(--oserp-section-title-size);
    font-weight: var(--oserp-section-title-weight);
    line-height: 1.5;
}

.oserp-section__subtitle {
    font-size: 0.8125rem;
    color: var(--oserp-text-muted);
}

.oserp-section__actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
}

.oserp-section__body {
    padding: var(--oserp-gap) var(--oserp-gap);
}

.oserp-section__body--plain {
    padding: 0;
}

/* Ruhige Darstellung: Kopf ohne Fläche, etwas mehr Luft */
.oserp-section--quiet .oserp-section__head {
    background: transparent;
    border-bottom: none;
    padding-top: var(--oserp-gap);
    padding-bottom: 0;
}

.oserp-section--quiet .oserp-section__body {
    padding: var(--oserp-gap) var(--oserp-gap) var(--oserp-gap-lg);
}
</style>
