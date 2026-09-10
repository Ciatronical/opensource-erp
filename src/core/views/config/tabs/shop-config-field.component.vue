<!-- src/core/views/config/tabs/shop-config-field.component.vue -->
<!--
    Ein Einstellungsfeld der Shop-Erweiterung.

    Herausgelöst aus shop-defaults.tab.vue, damit dieselbe Darstellung sowohl
    auf oberster Ebene als auch innerhalb einer Gruppe gilt — die PayPal-Felder
    stehen in zwei Gruppen, je eine für Test- und Echtbetrieb.
-->
<template>
    <!-- Checkbox -->
    <v-row v-if="field.type === 'checkbox'" class="my-4" :data-field-name="field.name">
        <v-col cols="12" md="6">
            <v-checkbox
                v-model="werte[field.name]"
                :label="t(field.label)"
                hide-details="auto"
                density="compact"
            >
                <template v-if="field.tooltip" #append>
                    <FeldHilfe :text="t(field.tooltip)" />
                </template>
            </v-checkbox>
        </v-col>
    </v-row>

    <!-- Eingabefeld / Passwort -->
    <v-row v-else-if="field.type === 'input' || field.type === 'password'" class="my-4" :data-field-name="field.name">
        <v-col cols="12" md="6">
            <v-text-field
                v-model="werte[field.name]"
                :label="t(field.label)"
                :type="field.type === 'password' ? 'password' : (field.inputType || 'text')"
                :placeholder="field.type === 'password' ? t('crm_fields.shopSecretKeep') : undefined"
                :persistent-placeholder="field.type === 'password'"
                :style="field.fieldstyle"
                hide-details="auto"
                density="compact"
                variant="outlined"
                autocomplete="new-password"
            >
                <template v-if="field.tooltip" #append-inner>
                    <FeldHilfe :text="t(field.tooltip)" />
                </template>
            </v-text-field>
        </v-col>
    </v-row>

    <!-- Auswahl mit festen Werten -->
    <v-row v-else-if="field.type === 'select'" class="my-4" :data-field-name="field.name">
        <v-col cols="12" md="6">
            <v-select
                v-model="werte[field.name]"
                :items="field.items"
                :label="t(field.label)"
                :style="field.fieldstyle"
                hide-details="auto"
                density="compact"
                variant="outlined"
            >
                <template v-if="field.tooltip" #append-inner>
                    <FeldHilfe :text="t(field.tooltip)" />
                </template>
            </v-select>
        </v-col>
    </v-row>

    <!-- Auswahl aus company_config -->
    <v-row v-else-if="field.type === 'dynamic-select'" class="my-4" :data-field-name="field.name">
        <v-col cols="12" md="6">
            <v-select
                v-model="werte[field.name]"
                :items="auswahl(field.source)"
                :item-title="field.itemTitle || 'title'"
                :item-value="field.itemValue || 'value'"
                :label="t(field.label)"
                :style="field.fieldstyle"
                hide-details="auto"
                density="compact"
                variant="outlined"
                clearable
            >
                <template v-if="field.tooltip" #append-inner>
                    <FeldHilfe :text="t(field.tooltip)" />
                </template>
            </v-select>
        </v-col>
    </v-row>
</template>

<script setup>
import { h } from 'vue'
import { useI18n } from 'vue-i18n'
import { VIcon, VTooltip } from 'vuetify/components'
import { oserpStore } from '@/core/stores/oserp.store.js'

const { t } = useI18n()
const store = oserpStore()

defineProps({
    /** Felddefinition aus shopDefaultsConfig.js */
    field: { type: Object, required: true },
    /** Die Einstellungen des Mandanten (defaults_oserp) */
    werte: { type: Object, required: true },
})

/** Auswahlwerte aus der Firmenkonfiguration, etwa die Mitarbeiterliste */
function auswahl(source) {
    return store.session?.[source] || store.session?.company_config?.[source] || []
}

/**
 * Das Fragezeichen mit dem Hilfetext
 *
 * Als kleine Funktionskomponente statt viermal derselbe Block — das Muster
 * wiederholt sich für jeden Feldtyp.
 */
const FeldHilfe = (props) => h(VTooltip, { location: 'top' }, {
    activator: ({ props: aktivator }) =>
        h(VIcon, { ...aktivator, size: 'small', color: 'grey' }, () => 'mdi-information-outline'),
    default: () => props.text,
})
FeldHilfe.props = { text: String }
</script>
