<!-- src/core/views/system/components/api-tester.component.vue -->
<template>
    <v-card variant="outlined">
        <v-card-title class="bg-grey-lighten-4">
            <v-icon start color="info">mdi-api</v-icon>
            {{ $t('DeveloperTools.apiTester.title') }}
        </v-card-title>
        <v-card-text class="pa-4">
            <p class="mb-4 text-caption text-grey">
                {{ $t('DeveloperTools.apiTester.description') }}
            </p>

            <!-- API Ordner auswählen -->
            <v-select
                v-model="selectedFolder"
                :items="folders"
                :label="$t('DeveloperTools.apiTester.folder_label')"
                variant="outlined"
                density="comfortable"
                class="mb-4"
                :loading="foldersLoading"
                @update:model-value="loadFunctions"
            />

            <!-- Funktion auswählen -->
            <v-select
                v-model="selectedFunction"
                :items="functions"
                :label="$t('DeveloperTools.apiTester.function_label')"
                variant="outlined"
                density="comfortable"
                class="mb-4"
                :loading="functionsLoading"
                :disabled="!selectedFolder"
            />

            <!-- Daten eingeben -->
            <v-row class="mb-4">
                <v-col cols="12" md="10">
                    <v-textarea
                        v-model="requestData"
                        :label="$t('DeveloperTools.apiTester.data_label')"
                        :hint="$t('DeveloperTools.apiTester.data_hint')"
                        variant="outlined"
                        rows="6"
                        class="monospace-textarea"
                        :disabled="!selectedFunction"
                    />
                </v-col>
                <v-col cols="12" md="2" class="d-flex align-start">
                    <v-btn
                        color="primary"
                        size="large"
                        prepend-icon="mdi-play"
                        :loading="callLoading"
                        :disabled="!selectedFunction"
                        @click="executeApiCall"
                        block
                    >
                        {{ $t('DeveloperTools.apiTester.call_button') }}
                    </v-btn>
                </v-col>
            </v-row>

            <!-- Ergebnis anzeigen -->
            <v-card v-if="apiResult" variant="outlined" class="mt-4">
                <v-card-title class="bg-grey-lighten-5 text-subtitle-2">
                    <v-icon start size="small">mdi-code-json</v-icon>
                    {{ $t('DeveloperTools.apiTester.result_title') }}
                    <v-spacer />
                    <v-chip
                        :color="apiResult.success ? 'success' : 'error'"
                        size="small"
                        variant="flat"
                        class="mr-2"
                    >
                        {{ apiResult.success ? $t('success') : $t('error') }}
                    </v-chip>
                    <v-btn
                        icon
                        size="small"
                        variant="text"
                        color="primary"
                        @click="downloadResult"
                    >
                        <v-icon>mdi-download</v-icon>
                        <v-tooltip activator="parent" location="bottom">
                            {{ $t('DeveloperTools.apiTester.download_result') }}
                        </v-tooltip>
                    </v-btn>
                </v-card-title>
                <v-card-text>
                    <pre class="result-pre">{{ formatResult(apiResult) }}</pre>
                </v-card-text>
            </v-card>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import Swal from 'sweetalert2';

const { t: $t } = useI18n();

// localStorage Keys fuer HMR-Persistenz
const DEVTOOLS_API_FOLDER_KEY = 'devtools_apiFolder';
const DEVTOOLS_API_FUNCTION_KEY = 'devtools_apiFunction';
const DEVTOOLS_API_DATA_KEY = 'devtools_apiData';

const folders = ref([]);
const functions = ref([]);
const selectedFolder = ref(localStorage.getItem(DEVTOOLS_API_FOLDER_KEY) || null);
const selectedFunction = ref(localStorage.getItem(DEVTOOLS_API_FUNCTION_KEY) || null);
const requestData = ref(localStorage.getItem(DEVTOOLS_API_DATA_KEY) || '{}');
const apiResult = ref(null);
const foldersLoading = ref(false);
const functionsLoading = ref(false);
const callLoading = ref(false);

/**
 * Lädt alle verfügbaren API-Ordner
 */
async function loadFolders() {
    foldersLoading.value = true;
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getApiFolders'
        });

        if (response.data.success && response.data.payload) {
            folders.value = response.data.payload.folders || [];
        }
    } catch (error) {
        console.error('Fehler beim Laden der API-Ordner:', error);
        Swal.fire($t('error'), $t('DeveloperTools.apiTester.error_folders'), 'error');
    } finally {
        foldersLoading.value = false;
    }
}

/**
 * Lädt alle Funktionen aus dem gewählten Ordner
 */
async function loadFunctions() {
    if (!selectedFolder.value) {
        functions.value = [];
        return;
    }

    functionsLoading.value = true;
    selectedFunction.value = null;
    apiResult.value = null;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getApiFunctions',
            folder: selectedFolder.value
        });

        if (response.data.success && response.data.payload) {
            functions.value = response.data.payload.functions || [];
        }
    } catch (error) {
        console.error('Fehler beim Laden der Funktionen:', error);
        Swal.fire($t('error'), $t('DeveloperTools.apiTester.error_functions'), 'error');
    } finally {
        functionsLoading.value = false;
    }
}

/**
 * Führt den API-Call aus
 */
async function executeApiCall() {
    if (!selectedFolder.value || !selectedFunction.value) {
        return;
    }

    // Validiere JSON
    let parsedData;
    try {
        parsedData = JSON.parse(requestData.value);
    } catch (error) {
        Swal.fire($t('error'), $t('DeveloperTools.apiTester.invalid_json'), 'error');
        return;
    }

    callLoading.value = true;
    apiResult.value = null;

    try {
        const response = await axios.post(`/api/${selectedFolder.value}/`, {
            action: selectedFunction.value,
            ...parsedData
        });

        apiResult.value = response.data;
    } catch (error) {
        console.error('Fehler beim API-Call:', error);
        apiResult.value = {
            success: false,
            error: 'Network Error',
            message: error.message
        };
    } finally {
        callLoading.value = false;
    }
}

/**
 * Formatiert das Ergebnis als lesbares JSON
 */
function formatResult(result) {
    return JSON.stringify(result, null, 2);
}

/**
 * Lädt das API-Ergebnis als JSON-Datei herunter
 */
function downloadResult() {
    if (!apiResult.value) return;

    const json = JSON.stringify(apiResult.value, null, 2);
    const blob = new Blob([json], { type: 'application/json;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);

    const now = new Date();
    const timestamp = now.toISOString().replace(/[:.]/g, '-').substring(0, 19);
    const filename = `api_result_${selectedFolder.value}_${selectedFunction.value}_${timestamp}.json`;

    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

/**
 * Lädt Beispieldaten für die gewählte Funktion vom Backend
 */
async function loadExampleData() {
    if (!selectedFolder.value || !selectedFunction.value) {
        return;
    }

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getFunctionParameters',
            folder: selectedFolder.value,
            function: selectedFunction.value
        });

        if (response.data.success && response.data.payload) {
            const exampleData = response.data.payload.example_data || {};

            // Prüfe ob Beispieldaten leer sind
            if (Object.keys(exampleData).length === 0) {
                Swal.fire({
                    title: '⚠️ Fehlende Dokumentation',
                    html: `Die Funktion <strong>${selectedFunction.value}</strong> hat keine Testdaten!<br><br>
                           Bitte füge einen <code>@testdata</code> Tag zum Docblock hinzu:<br>
                           <code style="display:block; margin-top:10px; padding:10px; background:#f5f5f5; text-align:left;">
                           @testdata {"key": "value"}
                           </code>`,
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
            }

            requestData.value = JSON.stringify(exampleData, null, 2);
        } else {
            // Zeige Fehlermeldung wenn Docblock fehlt
            if (response.data.text && response.data.text.includes('keine @param')) {
                Swal.fire({
                    title: $t('error'),
                    text: response.data.text,
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
            }
            // Fallback: leeres Objekt
            requestData.value = '{}';
        }
    } catch (error) {
        console.error('Fehler beim Laden der Beispieldaten:', error);
        // Fallback: leeres Objekt
        requestData.value = '{}';
    }
}

// Watch: Wenn Funktion gewählt wird, lade Beispiel-Daten vom Backend
watch(selectedFunction, (newVal, oldVal) => {
    localStorage.setItem(DEVTOOLS_API_FUNCTION_KEY, newVal || '');
    // Nur Beispieldaten laden wenn manuell gewechselt (nicht beim Restore)
    if (newVal && oldVal !== undefined) {
        loadExampleData();
    }
});

// Watch: Folder-Auswahl persistieren
watch(selectedFolder, (val) => {
    localStorage.setItem(DEVTOOLS_API_FOLDER_KEY, val || '');
});

// Watch: Request-Daten persistieren
watch(requestData, (val) => {
    localStorage.setItem(DEVTOOLS_API_DATA_KEY, val);
});

onMounted(async () => {
    await loadFolders();
    // Wenn Folder aus localStorage wiederhergestellt, Funktionen nachladen
    const savedFolder = localStorage.getItem(DEVTOOLS_API_FOLDER_KEY);
    if (savedFolder && folders.value.includes(savedFolder)) {
        selectedFolder.value = savedFolder;
        functionsLoading.value = true;
        try {
            const response = await axios.post('/api/developer-tools/', {
                action: 'getApiFunctions',
                folder: savedFolder
            });
            if (response.data.success && response.data.payload) {
                functions.value = response.data.payload.functions || [];
            }
        } catch (error) {
            console.error('Fehler beim Laden der Funktionen:', error);
        } finally {
            functionsLoading.value = false;
        }
        // Funktion wiederherstellen wenn sie noch existiert
        const savedFunction = localStorage.getItem(DEVTOOLS_API_FUNCTION_KEY);
        if (savedFunction && functions.value.includes(savedFunction)) {
            selectedFunction.value = savedFunction;
        }
    }
});
</script>

<style scoped>
.monospace-textarea :deep(textarea) {
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
}

.result-pre {
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
    background-color: #f5f5f5;
    padding: 16px;
    border-radius: 4px;
    overflow-x: auto;
    max-height: 500px;
    overflow-y: auto;
}
</style>