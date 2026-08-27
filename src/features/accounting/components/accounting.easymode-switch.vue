<!-- src/features/accounting/components/accounting.easymode-switch.vue -->
<!--
    Umschalter zwischen einfachem und fachlichem Modus.

    Bewusst ein Segment-Umschalter und kein Haken: er zeigt beide Moeglichkeiten
    gleichzeitig und macht damit sichtbar, dass es eine zweite Sicht gibt. Die
    Auswahl wird sofort gespeichert (Benutzereinstellung), es gibt kein Speichern.
-->
<template>
    <v-tooltip location="bottom" max-width="320">
        <template #activator="{ props }">
            <v-btn-toggle
                v-bind="props"
                :model-value="easymode ? 'easy' : 'pro'"
                mandatory
                density="compact"
                variant="outlined"
                divided
                :aria-label="t('AccountingView.easymode.label')"
                @update:model-value="onChange"
            >
                <v-btn value="easy" size="small" class="text-none px-3">
                    <v-icon start size="small">mdi-white-balance-sunny</v-icon>
                    {{ t('AccountingView.easymode.easy') }}
                </v-btn>
                <v-btn value="pro" size="small" class="text-none px-3">
                    <v-icon start size="small">mdi-tune-variant</v-icon>
                    {{ t('AccountingView.easymode.pro') }}
                </v-btn>
            </v-btn-toggle>
        </template>
        <div class="text-body-2">
            <strong>{{ t('AccountingView.easymode.easy') }}</strong> — {{ t('AccountingView.easymode.easyHint') }}
            <br>
            <strong>{{ t('AccountingView.easymode.pro') }}</strong> — {{ t('AccountingView.easymode.proHint') }}
        </div>
    </v-tooltip>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { useEasymode } from '../composables/useEasymode.js'

const { t } = useI18n()
const { easymode } = useEasymode()

function onChange(value) {
    const next = value === 'easy'
    // v-btn-toggle meldet mit `mandatory` auch beim ersten Rendern einen Wert.
    // Ohne diese Pruefung schriebe schon das blosse Oeffnen der Seite in die
    // Benutzerkonfiguration — und konnte den Modus ungewollt umstellen.
    if (next === easymode.value) return
    easymode.value = next
}
</script>
