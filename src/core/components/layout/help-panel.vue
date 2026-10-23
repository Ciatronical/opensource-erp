<!-- src/core/components/layout/help-panel.vue -->
<!--
    Hilfsanzeige: schmale, hohe Leiste am rechten Rand mit der Langfassung
    eines Hilfetexts. Nicht modal — sie schiebt den Inhalt zur Seite, statt
    ihn zu verdecken, und lässt sich weiter bedienen. Geöffnet wird sie über
    useHelpPanel().zeigen(titel, text); ein weiterer Aufruf tauscht den
    Inhalt aus. Einmal je Ansicht einbinden, die lange Hilfen anbietet.
-->
<template>
    <v-navigation-drawer
        :model-value="zustand.offen"
        location="right"
        :width="breite"
        :scrim="false"
        :temporary="false"
        disable-resize-watcher
        disable-route-watcher
        class="help-panel"
        @update:model-value="wert => { if (!wert) schliessen() }"
    >
        <div class="help-panel__kopf">
            <v-icon size="small" class="me-2">mdi-help-circle-outline</v-icon>
            <div class="help-panel__titel text-subtitle-2">{{ zustand.titel }}</div>
            <v-btn
                icon="mdi-close"
                variant="text"
                size="small"
                :title="t('HelpPanel.close')"
                :aria-label="t('HelpPanel.close')"
                @click="schliessen"
            />
        </div>
        <v-divider />
        <div class="help-panel__text text-body-2">{{ zustand.text }}</div>
    </v-navigation-drawer>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { useHelpPanel } from '@/core/composables/useHelpPanel.js'

defineProps({
    /** Breite in Pixeln — schmal, damit die Leiste möglichst wenig verdrängt */
    breite: { type: Number, default: 340 },
})

const { t } = useI18n()
const { zustand, schliessen } = useHelpPanel()
</script>

<style scoped>
.help-panel__kopf {
    display: flex;
    align-items: center;
    gap: var(--oserp-gap-xs, 4px);
    padding: var(--oserp-gap-sm, 8px) var(--oserp-gap-sm, 8px) var(--oserp-gap-sm, 8px) var(--oserp-gap-md, 16px);
    background: var(--oserp-section-head-bg);
}
.help-panel__titel {
    flex: 1 1 auto;
    min-width: 0;
}
.help-panel__text {
    padding: var(--oserp-gap-md, 16px);
    white-space: pre-line;   /* Absätze aus dem Hilfetext bleiben erhalten */
    line-height: 1.5;
    color: var(--oserp-text-muted);
}
</style>
