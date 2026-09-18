<!-- src/core/views/developer-tools/components/log-viewer.component.vue -->
<template>
    <div>
        <!-- Kopfbereich -->
        <div class="d-flex align-center mb-4">
            <div>
                <div class="text-h6 font-weight-bold">{{ $t('DeveloperTools.logViewer.title') }}</div>
                <div class="text-caption text-medium-emphasis">{{ $t('DeveloperTools.logViewer.description') }}</div>
            </div>
            <v-spacer />
            <v-btn
                color="primary"
                variant="tonal"
                prepend-icon="mdi-refresh"
                :loading="loading"
                @click="reload"
            >
                {{ $t('DeveloperTools.logViewer.refresh') }}
            </v-btn>
        </div>

        <!-- Steuerung -->
        <v-row dense class="mb-2">
            <v-col cols="12" md="4">
                <v-select
                    v-model="selectedFile"
                    :items="fileOptions"
                    item-title="label"
                    item-value="value"
                    :label="$t('DeveloperTools.logViewer.file')"
                    density="compact"
                    variant="outlined"
                    hide-details
                    prepend-inner-icon="mdi-file-document-outline"
                />
            </v-col>

            <v-col cols="12" md="3">
                <v-select
                    v-model="selectedLevel"
                    :items="levelOptions"
                    item-title="label"
                    item-value="value"
                    :label="$t('DeveloperTools.logViewer.level')"
                    density="compact"
                    variant="outlined"
                    hide-details
                    clearable
                    prepend-inner-icon="mdi-filter-variant"
                />
            </v-col>

            <v-col cols="12" md="5">
                <v-text-field
                    v-model="search"
                    :label="$t('DeveloperTools.logViewer.search')"
                    :placeholder="$t('DeveloperTools.logViewer.searchPlaceholder')"
                    density="compact"
                    variant="outlined"
                    hide-details
                    clearable
                    prepend-inner-icon="mdi-magnify"
                    @keyup.enter="reload"
                    @click:clear="reload"
                />
            </v-col>
        </v-row>

        <!-- Seitengröße: schrittweise 20, 50, 100 -->
        <div class="d-flex align-center flex-wrap ga-4 mb-4">
            <div class="d-flex align-center ga-2">
                <span class="text-caption text-medium-emphasis">{{ $t('DeveloperTools.logViewer.entriesPerPage') }}</span>
                <v-btn-toggle
                    v-model="pageSize"
                    color="primary"
                    density="compact"
                    variant="outlined"
                    mandatory
                >
                    <v-btn v-for="size in PAGE_SIZES" :key="size" :value="size" size="small">
                        {{ size }}
                    </v-btn>
                </v-btn-toggle>
            </div>

            <v-spacer />

            <div v-if="fileInfo.modified" class="text-caption text-medium-emphasis">
                <v-icon size="14">mdi-clock-outline</v-icon>
                {{ fileInfo.modified }}
                <span class="mx-1">·</span>
                <v-icon size="14">mdi-harddisk</v-icon>
                {{ formatSize(fileInfo.size) }}
            </div>
        </div>

        <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />

        <!-- Hinweis: Suche wurde vorzeitig abgebrochen -->
        <v-alert
            v-if="truncated"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
            :text="$t('DeveloperTools.logViewer.truncated')"
        />

        <!-- Leere Ansicht -->
        <v-card v-if="!loading && entries.length === 0" variant="tonal" class="pa-8 text-center">
            <v-icon size="64" color="grey-lighten-1" class="mb-4">mdi-text-search</v-icon>
            <div class="text-h6 text-grey">{{ $t('DeveloperTools.logViewer.empty') }}</div>
            <div class="text-caption text-grey mt-1">{{ $t('DeveloperTools.logViewer.emptyHint') }}</div>
        </v-card>

        <!-- Einträge -->
        <v-card v-else variant="outlined">
            <v-list density="compact" class="py-0">
                <template v-for="(entry, index) in entries" :key="index">
                    <v-divider v-if="index > 0" />
                    <v-list-item
                        class="py-2"
                        :class="{ 'log-entry': overflowing[index] }"
                        @click="toggleEntry(index)"
                    >
                        <div class="d-flex align-start ga-3">
                            <v-chip
                                :color="levelColor(entry.level)"
                                size="x-small"
                                variant="flat"
                                class="log-level flex-shrink-0"
                            >
                                {{ entry.level }}
                            </v-chip>

                            <div class="flex-grow-1 min-width-0">
                                <div class="text-caption text-medium-emphasis mb-1">
                                    {{ entry.timestamp }}
                                    <span v-if="entry.pid" class="ml-2">pid {{ entry.pid }}</span>
                                </div>
                                <pre
                                    :ref="el => setMessageRef(el, index)"
                                    class="log-message"
                                    :class="{ collapsed: !expanded[index] }"
                                >{{ entry.message }}</pre>
                                <div v-if="overflowing[index]" class="text-caption text-primary mt-1">
                                    {{ expanded[index] ? $t('DeveloperTools.logViewer.collapse') : $t('DeveloperTools.logViewer.expand') }}
                                </div>
                            </div>
                        </div>
                    </v-list-item>
                </template>
            </v-list>
        </v-card>

        <!-- Blättern -->
        <div v-if="entries.length > 0" class="d-flex align-center mt-4">
            <v-btn
                variant="text"
                prepend-icon="mdi-chevron-left"
                :disabled="offset === 0 || loading"
                @click="previousPage"
            >
                {{ $t('DeveloperTools.logViewer.previous') }}
            </v-btn>

            <v-spacer />

            <span class="text-caption text-medium-emphasis">
                {{ $t('DeveloperTools.logViewer.showing', { from: offset + 1, to: offset + entries.length }) }}
            </span>

            <v-spacer />

            <v-btn
                variant="text"
                append-icon="mdi-chevron-right"
                :disabled="!hasMore || loading"
                @click="nextPage"
            >
                {{ $t('DeveloperTools.logViewer.next') }}
            </v-btn>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t: $t } = useI18n();

// Wählbare Seitengrößen — Vorgabe sind die ersten 20 Einträge
const PAGE_SIZES = [20, 50, 100];
const PAGE_SIZE_KEY = 'devtools_logViewer_pageSize';

const files = ref([]);
const selectedFile = ref(null);
const selectedLevel = ref(null);
const search = ref('');
const pageSize = ref(readStoredPageSize());
const offset = ref(0);

const entries = ref([]);
const expanded = ref({});
const overflowing = ref({});
const hasMore = ref(false);
const truncated = ref(false);
const loading = ref(false);
const fileInfo = ref({ size: 0, modified: '' });

const fileOptions = computed(() => files.value.map(file => ({
    value: file.name,
    label: `${file.name} (${formatSize(file.size)})`,
})));

const levelOptions = computed(() => [
    { value: 'ERROR', label: $t('DeveloperTools.logViewer.levels.error') },
    { value: 'WARNING', label: $t('DeveloperTools.logViewer.levels.warning') },
    { value: 'INFO', label: $t('DeveloperTools.logViewer.levels.info') },
    { value: 'DEBUG', label: $t('DeveloperTools.logViewer.levels.debug') },
]);

/**
 * Liest die zuletzt gewählte Seitengröße, sonst die Vorgabe 20
 */
function readStoredPageSize() {
    const stored = parseInt(localStorage.getItem(PAGE_SIZE_KEY), 10);
    return PAGE_SIZES.includes(stored) ? stored : PAGE_SIZES[0];
}

/**
 * Farbe des Chips je Log-Level
 */
function levelColor(level) {
    switch (level) {
        case 'ERROR': return 'error';
        case 'WARNING': return 'warning';
        case 'INFO': return 'info';
        default: return 'grey';
    }
}

/**
 * Formatiert eine Dateigröße in Bytes lesbar
 */
function formatSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

// DOM-Knoten der Nachrichten, absichtlich ausserhalb von ref():
// Elemente muessen nicht reaktiv sein
const messageEls = {};

/**
 * Merkt sich den DOM-Knoten einer Nachricht für die Überlaufmessung
 */
function setMessageRef(el, index) {
    if (el) {
        messageEls[index] = el;
    } else {
        delete messageEls[index];
    }
}

/**
 * Misst, welche Nachrichten eingeklappt tatsächlich abgeschnitten sind
 *
 * Die Zeichenzahl taugt dafür nicht: ob ein Eintrag überläuft, hängt an
 * der Fensterbreite. Nur wo wirklich etwas verborgen ist, erscheint
 * "Mehr anzeigen" — sonst bliebe der Klick ohne sichtbare Wirkung.
 * Aufgeklappte Einträge überlaufen nie und behalten ihren Messwert.
 */
function measureOverflow() {
    const result = { ...overflowing.value };

    for (const [index, el] of Object.entries(messageEls)) {
        // Aufgeklappt oder gerade nicht sichtbar (anderer Tab): nicht messbar,
        // der bisherige Wert bleibt stehen
        if (expanded.value[index] || el.clientHeight === 0) {
            continue;
        }
        result[index] = el.scrollHeight > el.clientHeight + 1;
    }

    overflowing.value = result;
}

/**
 * Klappt einen Eintrag auf oder zu
 */
function toggleEntry(index) {
    if (!overflowing.value[index]) {
        return;
    }
    expanded.value = { ...expanded.value, [index]: !expanded.value[index] };
}

/**
 * Lädt die Liste der verfügbaren Logdateien
 */
async function loadFiles() {
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getLogFiles',
        });

        if (response.data.success && response.data.payload) {
            files.value = response.data.payload.files || [];

            if (!selectedFile.value || !files.value.some(file => file.name === selectedFile.value)) {
                const current = files.value.find(file => file.is_current);
                selectedFile.value = current ? current.name : (files.value[0]?.name || null);
            }
        }
    } catch (error) {
        console.error('Fehler beim Laden der Logdateien:', error);
        files.value = [];
    }
}

/**
 * Lädt die Einträge der gewählten Logdatei
 */
async function loadEntries() {
    if (!selectedFile.value) {
        entries.value = [];
        return;
    }

    loading.value = true;
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getLogEntries',
            file: selectedFile.value,
            limit: pageSize.value,
            offset: offset.value,
            level: selectedLevel.value || '',
            search: search.value || '',
        });

        if (response.data.success && response.data.payload) {
            const payload = response.data.payload;
            entries.value = payload.entries || [];
            hasMore.value = !!payload.has_more;
            truncated.value = !!payload.truncated;
            fileInfo.value = { size: payload.size, modified: payload.modified };
            expanded.value = {};
            overflowing.value = {};
        } else {
            entries.value = [];
            hasMore.value = false;
        }
    } catch (error) {
        console.error('Fehler beim Laden der Logeinträge:', error);
        entries.value = [];
        hasMore.value = false;
    } finally {
        loading.value = false;
    }

    // Erst nach dem Zeichnen lässt sich messen, was abgeschnitten ist
    await nextTick();
    measureOverflow();
}

/**
 * Lädt die Ansicht neu und beginnt wieder beim neuesten Eintrag
 */
async function reload() {
    offset.value = 0;
    await loadFiles();
    await loadEntries();
}

/**
 * Blättert eine Seite zurück (Richtung neuere Einträge)
 */
function previousPage() {
    offset.value = Math.max(0, offset.value - pageSize.value);
    loadEntries();
}

/**
 * Blättert eine Seite weiter (Richtung ältere Einträge)
 */
function nextPage() {
    offset.value += pageSize.value;
    loadEntries();
}

// Suche verzögert auslösen, damit nicht jeder Tastendruck eine Abfrage startet
let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        offset.value = 0;
        loadEntries();
    }, 400);
});

// Seitengröße merken und Ansicht von vorn laden
watch(pageSize, (newSize) => {
    localStorage.setItem(PAGE_SIZE_KEY, String(newSize));
    offset.value = 0;
    loadEntries();
});

watch([selectedFile, selectedLevel], () => {
    offset.value = 0;
    loadEntries();
});

// Bei geänderter Fensterbreite neu messen: aus einem abgeschnittenen
// Eintrag kann ein vollständig sichtbarer werden und umgekehrt
let resizeTimer = null;
function handleResize() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(measureOverflow, 200);
}

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    clearTimeout(resizeTimer);
    window.removeEventListener('resize', handleResize);
});

onMounted(async () => {
    window.addEventListener('resize', handleResize);
    await loadFiles();
    await loadEntries();
});
</script>

<style scoped>
.log-entry {
    cursor: pointer;
}

.log-message {
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
}

/* Genau drei Zeilen — ein Vielfaches der Zeilenhöhe, damit unten
   keine halb angeschnittene Zeile stehen bleibt */
.log-message.collapsed {
    max-height: 4.5em;
    overflow: hidden;
}

.log-level {
    min-width: 66px;
    justify-content: center;
    margin-top: 2px;
}

.min-width-0 {
    min-width: 0;
}
</style>
