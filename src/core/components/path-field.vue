<!-- src/core/components/path-field.vue -->
<!--
    Textfeld für einen Pfad, mit Ordner-Symbol zum Durchblättern des Servers.

    Ersetzt ein `<v-text-field>` eins zu eins: Alle Attribute und Slots gehen
    an das Textfeld. Das Feld bleibt frei beschreibbar — das Symbol ist eine
    Hilfe, keine Pflicht. Was der Dialog zeigt, begrenzt das Backend
    (`backend/api/lib/directory_browser.php`).

    Beispiel — Verzeichnis aus der settings.ini:
        <PathField v-model="wert" :label="…" />

    Beispiel — Datei (Logdatei, Programm):
        <PathField v-model="wert" pick="file" :label="…" />

    Beispiel — relatives Verzeichnis unterhalb der Webseite des Mandanten:
        <PathField v-model="wert" scope="shop" base="site" :label="…" />
-->
<template>
    <v-text-field
        v-bind="$attrs"
        v-model="wert"
    >
        <!-- Ordner-Symbol; ein vorhandener #append-inner-Inhalt steht links daneben -->
        <template #append-inner>
            <slot name="append-inner" />
            <v-tooltip location="top" :text="t('directoryPicker.browse')">
                <template #activator="{ props: tipProps }">
                    <v-icon
                        v-bind="tipProps"
                        class="path-field__browse"
                        size="small"
                        color="grey"
                        tabindex="-1"
                        @mousedown.prevent
                        @click.stop="offen = true"
                    >
                        mdi-folder-open-outline
                    </v-icon>
                </template>
            </v-tooltip>
        </template>

        <!-- Alle übrigen Slots unverändert weiterreichen -->
        <template v-for="name in weitereSlots" :key="name" #[name]="slotProps">
            <slot :name="name" v-bind="slotProps || {}" />
        </template>
    </v-text-field>

    <directory-picker-dialog
        v-model="offen"
        :scope="scope"
        :base="base"
        :pick="pick"
        :start="startPfad"
        @select="uebernehmen"
    />
</template>

<script setup>
import { ref, computed, useSlots } from 'vue';
import { useI18n } from 'vue-i18n';
import DirectoryPickerDialog from './directory-picker.dialog.vue';

// Attribute gehören an das Textfeld, nicht an die Wurzel
defineOptions({ inheritAttrs: false });

const props = defineProps({
    /** 'system' = Pfade der settings.ini, 'shop' = Verzeichnisse der Shop-Einstellungen */
    scope: {
        type: String,
        default: 'system',
    },
    /** Nur bei scope 'shop': 'sites' oder 'site' */
    base: {
        type: String,
        default: 'sites',
    },
    /** 'dir' wählt ein Verzeichnis, 'file' eine Datei */
    pick: {
        type: String,
        default: 'dir',
    },
});

const wert = defineModel({ type: String, default: '' });

const { t } = useI18n();
const slots = useSlots();
const offen = ref(false);

/** Slots, die unverändert durchgereicht werden — append-inner baut die Komponente selbst */
const weitereSlots = computed(() => Object.keys(slots).filter(name => name !== 'append-inner'));

/**
 * Wo der Dialog öffnet
 *
 * Im Shop-Bereich steht im Feld ein relativer Pfad, mit dem das Backend nichts
 * anfangen kann — dort beginnt der Dialog an der Wurzel.
 */
const startPfad = computed(() => (props.scope === 'shop' ? '' : (wert.value || '')));

/**
 * Übernimmt die Auswahl des Dialogs
 */
function uebernehmen(auswahl) {
    wert.value = auswahl.value;
}
</script>

<style scoped>
.path-field__browse {
    cursor: pointer;
}
</style>
