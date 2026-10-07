<!-- src/core/components/directory-picker.dialog.vue -->
<!--
    Auswahl eines Verzeichnisses oder einer Datei auf dem Server.

    Der Browser kann kein Serververzeichnis auswählen — seine Dateiauswahl
    meint immer den Rechner des Benutzers. Deshalb listet das Backend auf
    (`browseDirectories`), und dieser Dialog navigiert darin. Sichtbar ist nur,
    was unterhalb einer freigegebenen Wurzel liegt.

    Wird über `path-field.vue` benutzt; direkt eingebunden geht auch:
        <DirectoryPickerDialog v-model="offen" pick="dir" :start="wert" @select="…" />
-->
<template>
    <v-dialog v-model="offen" max-width="760" scrollable>
        <v-card>
            <v-card-title class="d-flex align-center bg-primary text-white py-2 px-3">
                <v-icon start>{{ pick === 'file' ? 'mdi-file-search-outline' : 'mdi-folder-search-outline' }}</v-icon>
                {{ pick === 'file' ? t('directoryPicker.titleFile') : t('directoryPicker.titleDir') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="x-small" variant="text" color="white" @click="offen = false" />
            </v-card-title>

            <v-divider />

            <v-card-text class="pa-3">
                <!-- Wurzelauswahl, wenn es mehr als einen Einstiegspunkt gibt -->
                <v-select
                    v-if="wurzeln.length > 1"
                    :model-value="aktuelleWurzel"
                    :items="wurzeln"
                    :label="t('directoryPicker.root')"
                    density="compact"
                    variant="outlined"
                    hide-details
                    class="mb-3"
                    @update:model-value="laden"
                />

                <!-- Aktueller Pfad -->
                <div class="d-flex align-center ga-2 mb-2">
                    <v-btn
                        size="small"
                        variant="text"
                        icon="mdi-arrow-up"
                        :disabled="!stand.parent || laedt"
                        :title="t('directoryPicker.up')"
                        @click="laden(stand.parent)"
                    />
                    <!-- Schriftgröße wie in den Eingabefeldern; lange Pfade brechen um,
                         auch mitten im Namen (text-break), statt abgeschnitten zu werden -->
                    <div class="text-body-1 text-high-emphasis flex-grow-1 text-break" style="min-width: 0">{{ stand.path }}</div>
                    <v-btn
                        size="small"
                        variant="text"
                        icon="mdi-refresh"
                        :loading="laedt"
                        :title="t('directoryPicker.refresh')"
                        @click="laden(stand.path)"
                    />
                </div>

                <v-alert
                    v-if="fehler"
                    type="error"
                    variant="tonal"
                    density="compact"
                    class="mb-2"
                    :text="fehler"
                />

                <!-- Angefragter Pfad nicht anzeigbar: was stattdessen zu sehen ist -->
                <v-alert
                    v-if="!fehler && stand.notice"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-2"
                    :text="t(`directoryPicker.notice.${stand.notice.type}`, { pfad: stand.notice.path })"
                />

                <v-alert
                    v-else-if="stand.truncated"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-2"
                    :text="t('directoryPicker.truncated')"
                />

                <v-card variant="outlined" class="eintraege">
                    <v-list density="compact" class="py-0">
                        <v-list-item v-if="!laedt && !stand.entries.length" class="text-medium-emphasis">
                            {{ leerMeldung }}
                        </v-list-item>

                        <v-list-item
                            v-for="eintrag in stand.entries"
                            :key="eintrag.path"
                            :active="eintrag.path === auswahl"
                            @click="anklicken(eintrag)"
                        >
                            <template #prepend>
                                <v-icon :color="eintrag.type === 'dir' ? 'amber-darken-2' : 'grey'">
                                    {{ eintrag.type === 'dir' ? 'mdi-folder' : 'mdi-file-outline' }}
                                </v-icon>
                            </template>
                            <v-list-item-title>{{ eintrag.name }}</v-list-item-title>
                            <template #append>
                                <v-icon
                                    v-if="eintrag.type === 'dir' && !eintrag.writable"
                                    size="x-small"
                                    color="grey"
                                    :title="t('directoryPicker.readOnly')"
                                >
                                    mdi-lock-outline
                                </v-icon>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>

                <div v-if="pick === 'dir'" class="text-caption text-medium-emphasis mt-2">
                    {{ stand.writable ? t('directoryPicker.writable') : t('directoryPicker.notWritable') }}
                </div>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-3">
                <!-- Lange Pfade brechen um (text-break), statt abgeschnitten zu werden;
                     min-width: 0 lässt die Zeile neben den Knöpfen schrumpfen -->
                <div class="text-caption text-break flex-grow-1 mr-2" style="min-width: 0">
                    {{ t('directoryPicker.selection') }}: {{ anzeige || '—' }}
                </div>
                <v-btn variant="text" @click="offen = false">{{ t('cancel') }}</v-btn>
                <v-btn color="primary" variant="tonal" :disabled="!anzeige" @click="uebernehmen">
                    {{ t('directoryPicker.apply') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t, te } = useI18n();

const props = defineProps({
    /** 'dir' wählt ein Verzeichnis, 'file' eine Datei darin */
    pick: {
        type: String,
        default: 'dir',
    },
    /** Pfad, bei dem der Dialog öffnet */
    start: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['select']);

/** Sichtbarkeit des Dialogs */
const offen = defineModel({ type: Boolean, default: false });

const laedt = ref(false);
const fehler = ref('');
const wurzeln = ref([]);
const auswahl = ref('');
const auswahlRelativ = ref('');

const stand = ref({
    path: '',
    relative: '',
    parent: null,
    entries: [],
    file_count: 0,
    writable: false,
    truncated: false,
    notice: null,
});

/**
 * Lesbare Fehlermeldung aus der Antwort des Backends
 *
 * text ist der Code (BROWSE_NOT_READABLE …), payload die Meldung mit Pfad und
 * Grund. Ist der Code übersetzt, steht die Übersetzung vorn und die Meldung
 * dahinter; sonst die Meldung allein, im Notfall ein allgemeiner Satz.
 */
function fehlerText(antwort) {
    const code = String(antwort?.text || '');
    const detail = typeof antwort?.payload === 'string' ? antwort.payload : '';
    const schluessel = `directoryPicker.errors.${code}`;
    if (code && te(schluessel)) return detail ? `${t(schluessel)} (${detail})` : t(schluessel);
    return detail || t('directoryPicker.failed');
}

/**
 * Meldung für ein Verzeichnis ohne anzeigbare Einträge
 *
 * Im Verzeichnis-Modus bleiben Dateien ausgeblendet — dann wäre "leer"
 * irreführend, solange welche darin liegen.
 */
const leerMeldung = computed(() => (stand.value.file_count > 0
    ? t('directoryPicker.onlyFiles', { count: stand.value.file_count })
    : t('directoryPicker.empty')));

/** Die Wurzel, unter der der aktuelle Pfad liegt */
const aktuelleWurzel = ref('');

/** Was übernommen würde */
const anzeige = computed(() => auswahl.value);

/**
 * Holt den Inhalt eines Verzeichnisses
 *
 * @param {string} pfad Verzeichnis; leer öffnet die erste Wurzel
 */
async function laden(pfad = '') {
    laedt.value = true;
    fehler.value = '';
    try {
        const { data } = await axios.post('/api/admin/', {
            action: 'browseDirectories',
            path: pfad || '',
            files: props.pick === 'file',
        });

        if (!data.success) {
            fehler.value = fehlerText(data);
            return;
        }

        stand.value = data.payload;
        wurzeln.value = data.payload.roots || [];
        aktuelleWurzel.value = data.payload.root || '';

        // Ein Verzeichnis ist mit dem Betreten ausgewählt; eine Datei erst
        // mit dem Klick darauf
        if (props.pick === 'dir') {
            auswahl.value = data.payload.path;
            auswahlRelativ.value = data.payload.relative;
        } else {
            auswahl.value = '';
            auswahlRelativ.value = '';
        }
    } catch (error) {
        fehler.value = fehlerText(error?.response?.data);
    } finally {
        laedt.value = false;
    }
}

/**
 * Verzeichnis betreten oder Datei auswählen
 */
function anklicken(eintrag) {
    if (eintrag.type === 'dir') {
        laden(eintrag.path);
        return;
    }

    auswahl.value = eintrag.path;
    auswahlRelativ.value = eintrag.relative;
}

/**
 * Übergibt die Auswahl an das Feld
 */
function uebernehmen() {
    emit('select', {
        path: auswahl.value,
        relative: auswahlRelativ.value,
        value: anzeige.value,
    });
    offen.value = false;
}

// Beim Öffnen dort beginnen, wo das Feld gerade steht
watch(offen, (istOffen) => {
    if (istOffen) {
        laden(props.start || '');
    }
});
</script>

<style scoped>
.eintraege {
    max-height: 46vh;
    overflow-y: auto;
}
</style>
