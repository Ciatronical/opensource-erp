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

        <!-- Vorlage zum Kopieren, etwa ein Konfigurationsausschnitt -->
        <template v-if="zustand.code">
            <div class="help-panel__code-kopf">
                <v-btn
                    size="small"
                    variant="tonal"
                    :prepend-icon="kopiert ? 'mdi-check' : 'mdi-content-copy'"
                    @click="kopieren"
                >
                    {{ t(kopiert ? 'HelpPanel.copied' : 'HelpPanel.copy') }}
                </v-btn>
            </div>
            <pre class="help-panel__code text-caption">{{ zustand.code }}</pre>
            <div v-if="zustand.nachtext" class="help-panel__text text-body-2">{{ zustand.nachtext }}</div>
        </template>
    </v-navigation-drawer>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useHelpPanel } from '@/core/composables/useHelpPanel.js'

defineProps({
    /** Breite in Pixeln — schmal, damit die Leiste möglichst wenig verdrängt */
    breite: { type: Number, default: 340 },
})

const { t } = useI18n()
const { zustand, schliessen } = useHelpPanel()

const kopiert = ref(false)
watch(() => zustand.code, () => { kopiert.value = false })

async function kopieren() {
    try {
        await navigator.clipboard.writeText(zustand.code)
        kopiert.value = true
    } catch {
        kopiert.value = false
    }
}
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
.help-panel__code-kopf {
    display: flex;
    justify-content: flex-end;
    padding: 0 var(--oserp-gap-md, 16px);
}
.help-panel__code {
    margin: var(--oserp-gap-sm, 8px) var(--oserp-gap-md, 16px);
    padding: var(--oserp-gap-sm, 8px);
    border: 1px solid var(--oserp-border);
    border-radius: var(--oserp-radius-sm, 4px);
    background: var(--oserp-surface-soft);
    white-space: pre;
    overflow: auto;
    font-family: monospace;
    line-height: 1.4;
}
.help-panel__text {
    padding: var(--oserp-gap-md, 16px);
    white-space: pre-wrap;   /* Absätze und Einrückungen (etwa JSON-Beispiele) bleiben erhalten */
    overflow-wrap: anywhere; /* lange Adressen brechen um, statt die Leiste zu sprengen */
    line-height: 1.5;
    color: var(--oserp-text-muted);
}
</style>
