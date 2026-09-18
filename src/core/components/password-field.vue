<!-- src/core/components/password-field.vue -->
<!--
    Passwortfeld mit Auge zum Sichtbarmachen.

    Ersetzt ein `<v-text-field type="password">` eins zu eins: Alle Attribute
    (label, hint, rules, density, variant …) und alle Slots werden an das
    Textfeld durchgereicht. Im sichtbaren Zustand steht der Wert im Klartext
    und laesst sich markieren und kopieren.

    Beispiel:
        <PasswordField v-model="daten.api_key" :label="t('...')" density="compact" />

    Das Auge sitzt rechts im Feld. Wer den Platz dort selbst braucht, kann
    #append-inner weiter benutzen — der eigene Inhalt steht dann links neben
    dem Auge.

    Der Sichtbar-Zustand laesst sich von aussen steuern, etwa um einen frisch
    erzeugten Schluessel gleich anzuzeigen:
        <PasswordField v-model="wert" v-model:visible="sichtbar" />
-->
<template>
    <v-text-field
        v-bind="$attrs"
        v-model="wert"
        :type="sichtbar ? 'text' : 'password'"
    >
        <!-- Auge; ein vorhandener #append-inner-Inhalt steht links daneben -->
        <template #append-inner>
            <slot name="append-inner" />
            <v-tooltip location="top" :text="sichtbar ? t('passwordField.hide') : t('passwordField.show')">
                <template #activator="{ props: tipProps }">
                    <v-icon
                        v-bind="tipProps"
                        class="password-field__toggle"
                        size="small"
                        color="grey"
                        tabindex="-1"
                        @mousedown.prevent
                        @click.stop="sichtbar = !sichtbar"
                    >
                        {{ sichtbar ? 'mdi-eye-off' : 'mdi-eye' }}
                    </v-icon>
                </template>
            </v-tooltip>
        </template>

        <!-- Alle uebrigen Slots unveraendert weiterreichen -->
        <template v-for="name in weitereSlots" :key="name" #[name]="slotProps">
            <slot :name="name" v-bind="slotProps || {}" />
        </template>
    </v-text-field>
</template>

<script setup>
import { computed, useSlots } from 'vue';
import { useI18n } from 'vue-i18n';

// Attribute gehoeren an das Textfeld, nicht an die Wurzel
defineOptions({ inheritAttrs: false });

/** Der Feldwert */
const wert = defineModel({ type: [String, Number], default: '' });

/** Sichtbar-Zustand; ohne v-model:visible verwaltet ihn die Komponente selbst */
const sichtbar = defineModel('visible', { type: Boolean, default: false });

const { t } = useI18n();
const slots = useSlots();

/** Slots, die unveraendert durchgereicht werden — append-inner baut die Komponente selbst */
const weitereSlots = computed(() => Object.keys(slots).filter(name => name !== 'append-inner'));
</script>

<style scoped>
.password-field__toggle {
    cursor: pointer;
}
</style>
