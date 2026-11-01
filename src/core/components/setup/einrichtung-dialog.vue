<!-- src/core/components/setup/einrichtung-dialog.vue -->
<!--
    Rahmen für die Einrichtungsdialoge (Kundenportal, Shop): ein Dialog mit
    Schrittleiste, je Schritt ein Slot mit Erklärung und Feldern, unten
    „Zurück“ und „Weiter“ bzw. „Fertig“. Der Inhalt entscheidet, ob es
    weitergeht (next-disabled) und erledigt beim Weitergehen das Speichern
    (Ereignis next). Zurück geht immer, vorwärts nur Schritt für Schritt —
    so wird kein Schritt übersprungen, der etwas prüft.

    Texte des Rahmens: setupWizard.* in src/core/views/config/locales.
-->
<template>
    <v-dialog
        :model-value="modelValue"
        :fullscreen="mobile"
        :max-width="mobile ? undefined : 1040"
        scrollable
        persistent
        @update:model-value="wert => emit('update:modelValue', wert)"
    >
        <v-card class="einrichtung">
            <v-toolbar flat density="comfortable" color="transparent" class="px-2 border-b">
                <v-icon v-if="icon" :icon="icon" class="ms-2 me-3" color="primary" />
                <v-toolbar-title class="text-subtitle-1 font-weight-bold">{{ title }}</v-toolbar-title>
                <span class="text-caption text-medium-emphasis me-3">
                    {{ t('setupWizard.stepOf', { step, total: steps.length }) }}
                </span>
                <v-btn icon="mdi-close" variant="text" :title="t('setupWizard.close')" @click="schliessen" />
            </v-toolbar>

            <v-card-text class="pa-0">
                <v-stepper
                    :model-value="step"
                    :items="titel"
                    flat
                    hide-actions
                    :mobile="mobile"
                    class="einrichtung-stepper"
                    @update:model-value="wert => emit('update:step', wert)"
                >
                    <template v-for="(schritt, index) in steps" :key="schritt.key" #[`item.${index+1}`]>
                        <div class="pa-4 pa-sm-6 einrichtung-inhalt">
                            <slot :name="`step-${schritt.key}`" :index="index + 1" />
                        </div>
                    </template>
                </v-stepper>
            </v-card-text>

            <v-divider />
            <v-card-actions class="px-4 py-3 flex-wrap ga-2">
                <v-btn
                    v-if="step > 1 && !hideBack"
                    variant="text"
                    prepend-icon="mdi-chevron-left"
                    :disabled="busy"
                    @click="emit('back')"
                >
                    {{ t('setupWizard.back') }}
                </v-btn>
                <v-spacer />
                <slot name="footer-extra" />
                <v-btn
                    v-if="!hideNext"
                    color="primary"
                    variant="flat"
                    :append-icon="step < steps.length ? 'mdi-chevron-right' : 'mdi-check'"
                    :disabled="nextDisabled || busy"
                    :loading="nextLoading"
                    @click="emit(step < steps.length ? 'next' : 'finish')"
                >
                    {{ nextLabel || t(step < steps.length ? 'setupWizard.next' : 'setupWizard.finish') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: '' },
    icon: { type: String, default: '' },
    /** [{ key, title }] — title schon übersetzt */
    steps: { type: Array, required: true },
    /** Aktueller Schritt, 1-basiert (v-model:step) */
    step: { type: Number, default: 1 },
    nextDisabled: { type: Boolean, default: false },
    nextLoading: { type: Boolean, default: false },
    /** Eigene Beschriftung für „Weiter“/„Fertig“, leer = Vorgabe */
    nextLabel: { type: String, default: '' },
    hideNext: { type: Boolean, default: false },
    hideBack: { type: Boolean, default: false },
    /** Etwas läuft (Speichern, Prüfen): Zurück und Weiter gesperrt */
    busy: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'update:step', 'next', 'back', 'finish', 'close'])

const { t } = useI18n()
const { mobile } = useDisplay()

const titel = computed(() => props.steps.map(s => s.title))

function schliessen() {
    emit('close')
    emit('update:modelValue', false)
}
</script>

<style scoped>
.einrichtung-inhalt {
    max-width: 90ch;
}
.einrichtung-stepper :deep(.v-stepper-window) {
    margin: 0;
}
/* Viele Schritte passen nur umbrechend in eine Zeile; die Verbindungslinien
   erzwingen sonst je Schritt eine eigene Zeile (wie setup.view.vue) */
.einrichtung-stepper :deep(.v-stepper-header) {
    flex-wrap: wrap;
    justify-content: flex-start;
    row-gap: 2px;
    box-shadow: none;
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.einrichtung-stepper :deep(.v-stepper-header .v-divider) {
    display: none;
}
.einrichtung-stepper :deep(.v-stepper-item) {
    flex: 0 1 auto;
    padding: 10px 10px;
    font-size: 0.9rem;
}
</style>
