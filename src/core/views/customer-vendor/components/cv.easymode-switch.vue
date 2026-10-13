<!-- src/core/views/customer-vendor/components/cv.easymode-switch.vue -->
<!--
    Umschalter "Einfach | Alle Felder" der Kunden-/Lieferantenmaske.

    Segment-Umschalter statt Haken: beide Sichten stehen nebeneinander, so ist
    sichtbar, dass es eine zweite gibt. Die Wahl wird sofort pro Benutzer
    gespeichert, Standard kommt aus der Firmenkonfiguration.
-->
<template>
    <v-tooltip location="bottom" max-width="340">
        <template #activator="{ props }">
            <v-btn-toggle
                v-bind="props"
                :model-value="easymode ? 'easy' : 'pro'"
                mandatory
                density="compact"
                variant="outlined"
                divided
                :aria-label="t('CustomerVendorEditView.easymode.label')"
                @update:model-value="onChange"
            >
                <v-btn value="easy" size="small" class="text-none px-3">
                    <v-icon start size="small">mdi-white-balance-sunny</v-icon>
                    {{ t('CustomerVendorEditView.easymode.easy') }}
                </v-btn>
                <v-btn value="pro" size="small" class="text-none px-3">
                    <v-icon start size="small">mdi-tune-variant</v-icon>
                    {{ t('CustomerVendorEditView.easymode.pro') }}
                </v-btn>
            </v-btn-toggle>
        </template>
        <div class="text-body-2">
            <strong>{{ t('CustomerVendorEditView.easymode.easy') }}</strong> — {{ t('CustomerVendorEditView.easymode.easyHint') }}
            <br>
            <strong>{{ t('CustomerVendorEditView.easymode.pro') }}</strong> — {{ t('CustomerVendorEditView.easymode.proHint') }}
        </div>
    </v-tooltip>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { useCvEasymode } from '../composables/useCvEasymode.js'

const { t } = useI18n()
const { easymode } = useCvEasymode()

function onChange(value) {
    // v-btn-toggle meldet mit `mandatory` schon beim ersten Rendern einen Wert;
    // der Setter speichert nur echte Änderungen.
    easymode.value = value === 'easy'
}
</script>
