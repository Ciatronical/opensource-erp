<!-- src/core/views/developer-tools/components/auto-test.component.vue -->
<template>
    <v-card variant="outlined">
        <v-card-title class="bg-grey-lighten-4">
            <v-icon start color="info">mdi-test-tube</v-icon>
            {{ t('DeveloperTools.autoTest.title') }}
            <v-spacer />
            <v-btn-toggle v-model="testMode" mandatory density="compact" class="mr-4">
                <v-btn value="function" size="small">
                    <v-icon start size="small">mdi-function</v-icon>
                    {{ t('DeveloperTools.autoTest.mode_function') }}
                </v-btn>
                <v-btn value="route" size="small">
                    <v-icon start size="small">mdi-routes</v-icon>
                    {{ t('DeveloperTools.autoTest.mode_route') }}
                </v-btn>
                <v-btn value="workflow" size="small">
                    <v-icon start size="small">mdi-sitemap</v-icon>
                    {{ t('DeveloperTools.autoTest.mode_workflow') }}
                </v-btn>
            </v-btn-toggle>
            <v-btn
                v-if="!testsRunning"
                color="success"
                variant="flat"
                prepend-icon="mdi-play"
                class="mr-2"
                @click="startTests"
            >
                {{ t('DeveloperTools.autoTest.run_all') }}
            </v-btn>
            <v-btn
                v-else
                color="error"
                variant="flat"
                prepend-icon="mdi-stop"
                class="mr-2"
                @click="cancelTests"
            >
                {{ t('DeveloperTools.autoTest.cancel') }}
            </v-btn>
        </v-card-title>

        <v-card-text class="pa-4">
            <p class="mb-4 text-caption text-grey">
                {{ modeDescriptions[testMode] }}
            </p>

            <!-- Zusammenfassung -->
            <v-row v-if="currentHasResults" class="mb-4">
                <v-col cols="6" sm="3">
                    <v-card variant="tonal" color="info" class="text-center pa-3">
                        <div class="text-h5">{{ summary.total }}</div>
                        <div class="text-caption">{{ t('DeveloperTools.autoTest.summary.total') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="3">
                    <v-card variant="tonal" color="success" class="text-center pa-3">
                        <div class="text-h5">{{ summary.passed }}</div>
                        <div class="text-caption">{{ t('DeveloperTools.autoTest.summary.passed') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="3">
                    <v-card variant="tonal" color="error" class="text-center pa-3">
                        <div class="text-h5">{{ summary.failed }}</div>
                        <div class="text-caption">{{ t('DeveloperTools.autoTest.summary.failed') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="3">
                    <v-card variant="tonal" color="warning" class="text-center pa-3">
                        <div class="text-h5">{{ summary.skipped + summary.missing }}</div>
                        <div class="text-caption">{{ t('DeveloperTools.autoTest.summary.skipped') }}</div>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Fortschrittsanzeige -->
            <div v-if="testsRunning" class="mb-4">
                <div v-if="progressText" class="text-caption text-grey mb-1">{{ progressText }}</div>
                <v-progress-linear
                    v-if="progressTotal > 0"
                    :model-value="(progressCurrent / progressTotal) * 100"
                    color="primary"
                    height="8"
                    rounded
                />
                <v-progress-linear v-else indeterminate color="primary" />
            </div>

            <!-- Fehler-Alert -->
            <v-alert v-if="errorMessage" type="error" variant="tonal" class="mb-4" closable @click:close="errorMessage = ''">
                {{ errorMessage }}
            </v-alert>

            <!-- Keine Ergebnisse -->
            <v-alert
                v-if="!currentHasResults && !testsRunning && !errorMessage"
                type="info"
                variant="tonal"
                class="mb-4"
            >
                {{ t('DeveloperTools.autoTest.no_results') }}
            </v-alert>

            <!-- ===== Funktions-Tests ===== -->
            <v-expansion-panels v-if="hasResults && testMode === 'function'" variant="accordion">
                <v-expansion-panel
                    v-for="(functions, folder) in testResults"
                    :key="folder"
                >
                    <v-expansion-panel-title>
                        <div class="d-flex align-center ga-2 flex-grow-1">
                            <v-icon size="small">mdi-folder</v-icon>
                            <span class="font-weight-bold">{{ folder }}</span>
                            <v-spacer />
                            <v-chip v-if="folderSummary(functions).passed > 0" color="success" size="x-small" variant="flat" class="mr-1">
                                {{ folderSummary(functions).passed }}
                            </v-chip>
                            <v-chip v-if="folderSummary(functions).failed > 0" color="error" size="x-small" variant="flat" class="mr-1">
                                {{ folderSummary(functions).failed }}
                            </v-chip>
                            <v-chip v-if="folderSummary(functions).skipped > 0" color="warning" size="x-small" variant="flat" class="mr-1">
                                {{ folderSummary(functions).skipped }}
                            </v-chip>
                            <v-btn icon size="x-small" variant="text" color="primary"
                                :loading="folderLoading === folder" @click.stop="runFolderTests(folder)">
                                <v-icon size="small">mdi-play</v-icon>
                                <v-tooltip activator="parent" location="top">{{ t('DeveloperTools.autoTest.run_folder') }}</v-tooltip>
                            </v-btn>
                        </div>
                    </v-expansion-panel-title>
                    <v-expansion-panel-text>
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>{{ t('DeveloperTools.autoTest.table.function') }}</th>
                                    <th>{{ t('DeveloperTools.autoTest.table.status') }}</th>
                                    <th>{{ t('DeveloperTools.autoTest.table.time') }}</th>
                                    <th>{{ t('DeveloperTools.autoTest.table.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(result, funcName) in functions" :key="funcName">
                                    <td class="font-weight-medium">{{ funcName }}</td>
                                    <td>
                                        <v-chip :color="getStatusColor(result)" size="small" variant="flat">
                                            {{ getStatusText(result) }}
                                        </v-chip>
                                    </td>
                                    <td class="text-caption">
                                        {{ result.time_ms > 0 ? result.time_ms + ' ms' : '-' }}
                                    </td>
                                    <td>
                                        <v-btn icon size="x-small" variant="text" color="primary"
                                            :loading="singleLoading === folder + '.' + funcName"
                                            @click="runSingleTest(folder, funcName)">
                                            <v-icon size="small">mdi-play</v-icon>
                                        </v-btn>
                                        <v-btn v-if="result.error && !result.skipped" icon size="x-small" variant="text" color="error"
                                            @click="showError(funcName, result)">
                                            <v-icon size="small">mdi-alert-circle</v-icon>
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-expansion-panel-text>
                </v-expansion-panel>
            </v-expansion-panels>

            <!-- ===== Routen-Tests ===== -->
            <v-expansion-panels v-if="hasRouteResults && testMode === 'route'" variant="accordion">
                <v-expansion-panel
                    v-for="(routes, folder) in routeResults"
                    :key="folder"
                >
                    <v-expansion-panel-title>
                        <div class="d-flex align-center ga-2 flex-grow-1">
                            <v-icon size="small">mdi-routes</v-icon>
                            <span class="font-weight-bold">/api/{{ folder }}/</span>
                            <v-spacer />
                            <v-chip v-if="routeFolderSummary(routes).passed > 0" color="success" size="x-small" variant="flat" class="mr-1">
                                {{ routeFolderSummary(routes).passed }}
                            </v-chip>
                            <v-chip v-if="routeFolderSummary(routes).failed > 0" color="error" size="x-small" variant="flat" class="mr-1">
                                {{ routeFolderSummary(routes).failed }}
                            </v-chip>
                            <v-chip v-if="routeFolderSummary(routes).skipped > 0" color="warning" size="x-small" variant="flat" class="mr-1">
                                {{ routeFolderSummary(routes).skipped }}
                            </v-chip>
                        </div>
                    </v-expansion-panel-title>
                    <v-expansion-panel-text>
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>{{ t('DeveloperTools.autoTest.table.route') }}</th>
                                    <th>{{ t('DeveloperTools.autoTest.table.status') }}</th>
                                    <th>HTTP</th>
                                    <th>{{ t('DeveloperTools.autoTest.table.time') }}</th>
                                    <th>{{ t('DeveloperTools.autoTest.table.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="route in routes" :key="route.action">
                                    <td>
                                        <span class="font-weight-medium">{{ route.action }}</span>
                                        <span class="text-caption text-grey ml-2">{{ route.file }}</span>
                                    </td>
                                    <td>
                                        <v-chip :color="getRouteStatusColor(route)" size="small" variant="flat">
                                            {{ getRouteStatusText(route) }}
                                        </v-chip>
                                    </td>
                                    <td class="text-caption">
                                        <v-chip v-if="route.http_status" :color="route.http_status < 400 ? 'success' : 'error'" size="x-small" variant="tonal">
                                            {{ route.http_status }}
                                        </v-chip>
                                        <span v-else>-</span>
                                    </td>
                                    <td class="text-caption">
                                        {{ route.time_ms > 0 ? route.time_ms + ' ms' : '-' }}
                                    </td>
                                    <td>
                                        <v-btn v-if="route.error" icon size="x-small" variant="text" color="error"
                                            @click="showError(route.action, { error: route.error, response: route.response })">
                                            <v-icon size="small">mdi-alert-circle</v-icon>
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-expansion-panel-text>
                </v-expansion-panel>
            </v-expansion-panels>

            <!-- ===== Workflow-Tests ===== -->
            <div v-if="hasWorkflowResults && testMode === 'workflow'">
                <v-card v-for="(wf, key) in workflowResults" :key="key" variant="outlined" class="mb-4">
                    <v-card-title class="d-flex align-center ga-2 py-2">
                        <v-icon :color="wf.success ? 'success' : 'error'" size="small">
                            {{ wf.success ? 'mdi-check-circle' : 'mdi-close-circle' }}
                        </v-icon>
                        <span>{{ wf.name }}</span>
                        <v-spacer />
                        <v-chip size="x-small" variant="tonal" color="grey">{{ wf.time_ms }} ms</v-chip>
                    </v-card-title>
                    <v-card-text class="pa-0">
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">#</th>
                                    <th>Schritt</th>
                                    <th>{{ t('DeveloperTools.autoTest.table.status') }}</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(step, idx) in wf.steps" :key="idx">
                                    <td class="text-caption text-grey">{{ idx + 1 }}</td>
                                    <td class="font-weight-medium">{{ step.name }}</td>
                                    <td>
                                        <v-icon :color="step.success ? 'success' : 'error'" size="small">
                                            {{ step.success ? 'mdi-check' : 'mdi-close' }}
                                        </v-icon>
                                    </td>
                                    <td class="text-caption" style="max-width: 400px; white-space: normal;">
                                        {{ step.detail || step.error || '' }}
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-card-text>
                </v-card>
            </div>

            <!-- Fehlerdetail-Dialog -->
            <v-dialog v-model="errorDialog" max-width="700">
                <v-card>
                    <v-card-title class="bg-error text-white">
                        <v-icon start>mdi-alert-circle</v-icon>
                        {{ t('DeveloperTools.autoTest.error_detail') }}: {{ errorFunction }}
                    </v-card-title>
                    <v-card-text class="pa-4">
                        <pre class="error-pre">{{ errorContent }}</pre>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn @click="errorDialog = false">{{ t('cancel') }}</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const testMode = ref('function');
const testResults = ref({});
const routeResults = ref({});
const workflowResults = ref({});
const summary = ref({ total: 0, passed: 0, failed: 0, skipped: 0, missing: 0 });
const testsRunning = ref(false);
const folderLoading = ref(null);
const singleLoading = ref(null);
const errorDialog = ref(false);
const errorFunction = ref('');
const errorContent = ref('');
const errorMessage = ref('');
const progressText = ref('');
const progressCurrent = ref(0);
const progressTotal = ref(0);
let activeAbort = null;

const modeDescriptions = {
    function: t('DeveloperTools.autoTest.description'),
    route: t('DeveloperTools.autoTest.description_route'),
    workflow: t('DeveloperTools.autoTest.description_workflow'),
};

function cancelTests() {
    if (activeAbort) { activeAbort.abort(); activeAbort = null; }
    testsRunning.value = false;
}

const hasResults = computed(() => Object.keys(testResults.value).length > 0);
const hasRouteResults = computed(() => Object.keys(routeResults.value).length > 0);
const hasWorkflowResults = computed(() => Object.keys(workflowResults.value).length > 0);
const currentHasResults = computed(() => {
    if (testMode.value === 'function') return hasResults.value;
    if (testMode.value === 'route') return hasRouteResults.value;
    return hasWorkflowResults.value;
});

function startTests() {
    errorMessage.value = '';
    if (testMode.value === 'function') runAllTests();
    else if (testMode.value === 'route') runRouteTests();
    else runWorkflowTests();
}

// ===== Funktions-Tests =====

function folderSummary(functions) {
    const result = { passed: 0, failed: 0, skipped: 0 };
    for (const fn of Object.values(functions)) {
        if (fn.skipped || !fn.has_testdata) result.skipped++;
        else if (fn.success) result.passed++;
        else result.failed++;
    }
    return result;
}

function getStatusColor(result) {
    if (result.skipped) return 'grey';
    if (!result.has_testdata) return 'warning';
    return result.success ? 'success' : 'error';
}

function getStatusText(result) {
    if (result.skipped) return t('DeveloperTools.autoTest.status.skipped');
    if (!result.has_testdata) return t('DeveloperTools.autoTest.status.missing');
    return result.success ? t('DeveloperTools.autoTest.status.pass') : t('DeveloperTools.autoTest.status.fail');
}

function showError(funcName, result) {
    errorFunction.value = funcName;
    errorContent.value = JSON.stringify({ error: result.error, response: result.response }, null, 2);
    errorDialog.value = true;
}

async function runAllTests() {
    cancelTests();
    testsRunning.value = true;
    progressText.value = t('DeveloperTools.autoTest.running');
    progressTotal.value = 0;
    activeAbort = new AbortController();
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'runAllTests'
        }, { signal: activeAbort.signal });
        if (response.data.success && response.data.payload) {
            testResults.value = response.data.payload.results;
            summary.value = response.data.payload.summary;
        } else {
            errorMessage.value = response.data.text || 'Unbekannter Fehler';
        }
    } catch (error) {
        if (!axios.isCancel(error)) {
            errorMessage.value = error.message;
        }
    } finally {
        activeAbort = null;
        testsRunning.value = false;
        progressText.value = '';
    }
}

async function runFolderTests(folder) {
    cancelTests();
    folderLoading.value = folder;
    activeAbort = new AbortController();
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'runAllTests', folder
        }, { signal: activeAbort.signal });
        if (response.data.success && response.data.payload) {
            const folderResults = response.data.payload.results[folder];
            if (folderResults) testResults.value[folder] = folderResults;
            recalcSummary();
        }
    } catch (error) {
        if (!axios.isCancel(error)) errorMessage.value = error.message;
    } finally {
        activeAbort = null;
        folderLoading.value = null;
    }
}

async function runSingleTest(folder, funcName) {
    cancelTests();
    singleLoading.value = folder + '.' + funcName;
    activeAbort = new AbortController();
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'runAllTests', folder, function: funcName
        }, { signal: activeAbort.signal });
        if (response.data.success && response.data.payload) {
            const funcResult = response.data.payload.results[folder]?.[funcName];
            if (funcResult) {
                if (!testResults.value[folder]) testResults.value[folder] = {};
                testResults.value[folder][funcName] = funcResult;
            }
            recalcSummary();
        }
    } catch (error) {
        if (!axios.isCancel(error)) errorMessage.value = error.message;
    } finally {
        activeAbort = null;
        singleLoading.value = null;
    }
}

function recalcSummary() {
    const s = { total: 0, passed: 0, failed: 0, skipped: 0, missing: 0 };
    for (const functions of Object.values(testResults.value)) {
        for (const fn of Object.values(functions)) {
            s.total++;
            if (fn.skipped) s.skipped++;
            else if (!fn.has_testdata) s.missing++;
            else if (fn.success) s.passed++;
            else s.failed++;
        }
    }
    summary.value = s;
}

// ===== Routen-Tests =====

function routeFolderSummary(routes) {
    const result = { passed: 0, failed: 0, skipped: 0 };
    for (const r of routes) {
        if (r.skipped) result.skipped++;
        else if (r.success) result.passed++;
        else result.failed++;
    }
    return result;
}

function getRouteStatusColor(route) {
    if (route.skipped) return 'grey';
    if (!route.tested) return 'warning';
    return route.success ? 'success' : 'error';
}

function getRouteStatusText(route) {
    if (route.skipped) return t('DeveloperTools.autoTest.status.skipped');
    if (!route.tested) return t('DeveloperTools.autoTest.status.missing');
    return route.success ? t('DeveloperTools.autoTest.status.pass') : t('DeveloperTools.autoTest.status.fail');
}

async function runRouteTests() {
    cancelTests();
    testsRunning.value = true;
    activeAbort = new AbortController();
    routeResults.value = {};
    progressText.value = t('DeveloperTools.autoTest.discovering');
    progressTotal.value = 0;
    progressCurrent.value = 0;

    try {
        // 1. Routen ermitteln
        const disc = await axios.post('/api/developer-tools/', {
            action: 'discoverRoutes'
        }, { signal: activeAbort.signal });

        if (!disc.data.success) {
            errorMessage.value = disc.data.text || 'discoverRoutes fehlgeschlagen';
            return;
        }

        const allRoutes = disc.data.payload?.routes;
        if (!allRoutes || Object.keys(allRoutes).length === 0) {
            errorMessage.value = 'Keine API-Routen gefunden';
            return;
        }

        // Gesamtzahl fuer Fortschritt berechnen
        let totalRoutes = 0;
        for (const routes of Object.values(allRoutes)) {
            totalRoutes += routes.length;
        }
        progressTotal.value = totalRoutes;

        const results = {};
        const s = { total: 0, passed: 0, failed: 0, skipped: 0, missing: 0 };

        // 2. Jede Route per HTTP testen
        for (const [folder, routes] of Object.entries(allRoutes)) {
            results[folder] = [];

            for (const route of routes) {
                if (activeAbort?.signal.aborted) break;

                s.total++;
                progressCurrent.value++;
                progressText.value = `Route ${progressCurrent.value}/${totalRoutes}: ${route.route} → ${route.action}`;

                // Gefaehrliche Funktionen ueberspringen
                if (route.dangerous) {
                    results[folder].push({
                        ...route,
                        tested: false,
                        skipped: true,
                        success: false,
                        time_ms: 0,
                        http_status: null,
                        error: null,
                    });
                    s.skipped++;
                    routeResults.value = JSON.parse(JSON.stringify(results));
                    summary.value = { ...s };
                    continue;
                }

                // Ohne Testdaten: als missing markieren
                if (!route.has_testdata) {
                    results[folder].push({
                        ...route,
                        tested: false,
                        skipped: false,
                        success: false,
                        time_ms: 0,
                        http_status: null,
                        error: t('DeveloperTools.autoTest.status.missing'),
                    });
                    s.missing++;
                    routeResults.value = JSON.parse(JSON.stringify(results));
                    summary.value = { ...s };
                    continue;
                }

                // HTTP-Request an die Route
                const startTime = performance.now();
                try {
                    const resp = await axios.post(route.route, {
                        action: route.action,
                        ...route.testdata
                    }, {
                        signal: activeAbort.signal,
                        validateStatus: () => true,
                        timeout: 15000,
                    });

                    const timeMs = Math.round((performance.now() - startTime) * 10) / 10;
                    const ok = resp.status < 400 && resp.data?.success === true;

                    results[folder].push({
                        ...route,
                        tested: true,
                        skipped: false,
                        success: ok,
                        time_ms: timeMs,
                        http_status: resp.status,
                        error: ok ? null : (resp.data?.text || `HTTP ${resp.status}`),
                        response: resp.data,
                    });

                    if (ok) s.passed++;
                    else s.failed++;
                } catch (err) {
                    if (axios.isCancel(err)) break;
                    const timeMs = Math.round((performance.now() - startTime) * 10) / 10;
                    results[folder].push({
                        ...route,
                        tested: true,
                        skipped: false,
                        success: false,
                        time_ms: timeMs,
                        http_status: err.response?.status || null,
                        error: err.message,
                    });
                    s.failed++;
                }

                // Zwischenergebnisse sofort anzeigen (deep copy)
                routeResults.value = JSON.parse(JSON.stringify(results));
                summary.value = { ...s };
            }
        }
    } catch (error) {
        if (!axios.isCancel(error)) {
            errorMessage.value = 'Routen-Test Fehler: ' + error.message;
            console.error('Route test error:', error);
        }
    } finally {
        activeAbort = null;
        testsRunning.value = false;
        progressText.value = '';
        progressTotal.value = 0;
    }
}

// ===== Workflow-Tests =====

async function runWorkflowTests() {
    cancelTests();
    testsRunning.value = true;
    activeAbort = new AbortController();
    workflowResults.value = {};
    progressText.value = 'Workflow-Tests werden ausgefuehrt...';
    progressTotal.value = 0;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'runWorkflowTests'
        }, { signal: activeAbort.signal });

        if (response.data.success && response.data.payload?.workflows) {
            workflowResults.value = response.data.payload.workflows;

            // Summary berechnen
            const s = { total: 0, passed: 0, failed: 0, skipped: 0, missing: 0 };
            for (const wf of Object.values(workflowResults.value)) {
                s.total++;
                if (wf.success) s.passed++;
                else s.failed++;
            }
            summary.value = s;
        } else {
            errorMessage.value = response.data.text || 'Workflow-Tests fehlgeschlagen';
        }
    } catch (error) {
        if (!axios.isCancel(error)) {
            errorMessage.value = 'Workflow-Test Fehler: ' + error.message;
        }
    } finally {
        activeAbort = null;
        testsRunning.value = false;
        progressText.value = '';
    }
}
</script>

<style scoped>
.error-pre {
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
    background-color: #f5f5f5;
    padding: 16px;
    border-radius: 4px;
    overflow-x: auto;
    max-height: 400px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
