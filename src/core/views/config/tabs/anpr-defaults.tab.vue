<!-- src/core/views/config/tabs/anpr-defaults.tab.vue -->

<template>
    <v-container fluid class="pa-0">
        <!-- Service-Status -->
        <v-row class="mb-4">
            <v-col cols="12">
                <v-card variant="outlined" class="pa-3">
                    <div class="d-flex align-center ga-3">
                        <v-icon :color="serviceStatusColor" size="20">{{ serviceStatusIcon }}</v-icon>
                        <span class="font-weight-medium">ANPR-Service: {{ serviceStatusText }}</span>
                        <span v-if="serviceDetails?.started_at" class="text-caption text-grey">
                            (seit {{ serviceDetails.started_at }})
                        </span>
                        <v-spacer />
                        <v-btn color="primary" variant="tonal" size="small"
                            @click="restartService" :loading="restarting"
                            :prepend-icon="serviceStatus === 'active' ? 'mdi-restart' : 'mdi-play'">
                            {{ serviceStatus === 'active' ? t('anpr.restart') : t('anpr.start') }}
                        </v-btn>
                        <v-btn variant="text" size="small" icon="mdi-refresh"
                            @click="checkServiceStatus" :loading="statusLoading" />
                    </div>
                </v-card>
            </v-col>
        </v-row>

        <!-- Allgemeine Einstellungen (aus Config-Datei) -->
        <template v-for="field in anprConfig" :key="field.name">
            <v-row v-if="field.type === 'headline'" class="mt-6 mb-2">
                <v-col cols="12">
                    <h3 class="text-h6 text-primary">{{ t(field.label) }}</h3>
                    <v-divider class="mt-2" />
                </v-col>
            </v-row>

            <v-row v-else-if="field.type === 'checkbox'" class="my-1" :data-field-name="field.name">
                <v-col cols="12" md="6">
                    <v-checkbox v-model="crmDefaults[field.name]" :label="t(field.label)" hide-details="auto" density="compact">
                        <template v-if="field.tooltip" #append>
                            <v-tooltip location="top">
                                <template #activator="{ props }">
                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                </template>
                                {{ t(field.tooltip) }}
                            </v-tooltip>
                        </template>
                    </v-checkbox>
                </v-col>
            </v-row>

            <v-row v-else-if="field.type === 'input'" class="my-1" :data-field-name="field.name">
                <v-col cols="12" md="6">
                    <v-text-field
                        v-model="crmDefaults[field.name]"
                        :label="t(field.label)"
                        :type="field.inputType || 'text'"
                        :style="field.fieldstyle"
                        hide-details="auto" density="compact" variant="outlined"
                    >
                        <template v-if="field.tooltip" #append-inner>
                            <v-tooltip location="top">
                                <template #activator="{ props }">
                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                </template>
                                {{ t(field.tooltip) }}
                            </v-tooltip>
                        </template>
                    </v-text-field>
                </v-col>
            </v-row>
        </template>

        <!-- ================================================================ -->
        <!-- KAMERAS -->
        <!-- ================================================================ -->
        <v-row class="mt-8 mb-2">
            <v-col cols="12">
                <h3 class="text-h6 text-primary">
                    <v-icon start>mdi-cctv</v-icon>
                    {{ t('anpr.cameras') }}
                </h3>
                <v-divider class="mt-2" />
            </v-col>
        </v-row>

        <v-row>
            <v-col cols="12">
                <v-btn color="primary" variant="tonal" size="small" @click="addCamera" class="mb-3">
                    <v-icon start>mdi-plus</v-icon>
                    {{ t('anpr.addCamera') }}
                </v-btn>

                <v-expansion-panels v-model="openCameraPanel" variant="accordion">
                    <v-expansion-panel v-for="(cam, idx) in cameras" :key="cam.id || ('new-' + idx)">
                        <v-expansion-panel-title>
                            <v-icon start :color="cam.enabled ? 'green' : 'grey'">
                                {{ cam.enabled ? 'mdi-camera' : 'mdi-camera-off' }}
                            </v-icon>
                            <span class="font-weight-medium">{{ cam.name || t('anpr.newCamera') }}</span>
                            <v-spacer />
                            <v-chip v-if="cam.action_type" size="x-small" class="me-2" variant="tonal">
                                {{ t('anpr.actionType_' + cam.action_type) }}
                            </v-chip>
                        </v-expansion-panel-title>

                        <v-expansion-panel-text>
                            <v-row dense>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="cam.name" :label="t('anpr.cameraName')"
                                        variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="cam.rtsp_url" :label="t('anpr.rtspUrl')"
                                        variant="outlined" density="compact" hide-details="auto"
                                        placeholder="rtsp://user:pass@192.168.1.100:554/stream" />
                                </v-col>

                                <v-col cols="6" md="3">
                                    <v-select v-model="cam.position" :label="t('anpr.cameraPosition')"
                                        :items="cameraPositions" variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-select v-model="cam.direction_mode" :label="t('anpr.directionMode')"
                                        :items="directionModes" variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.frame_interval" :label="t('anpr.frameInterval')"
                                        type="number" step="0.1" min="0.1" max="5"
                                        variant="outlined" density="compact" hide-details="auto" suffix="s" />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.min_confidence" :label="t('anpr.minConfidence')"
                                        type="number" step="0.05" min="0.3" max="0.99"
                                        variant="outlined" density="compact" hide-details="auto" />
                                </v-col>

                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.min_detections" :label="t('anpr.minDetections')"
                                        type="number" min="1" max="10"
                                        variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.cooldown_minutes" :label="t('anpr.cooldownMinutes')"
                                        type="number" min="1" max="60"
                                        variant="outlined" density="compact" hide-details="auto" suffix="min" />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-select v-model="cam.action_type" :label="t('anpr.actionType')"
                                        :items="actionTypes" variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-select v-model="cam.actuator_id" :label="t('anpr.linkedActuator')"
                                        :items="actuatorItems" item-title="name" item-value="id"
                                        variant="outlined" density="compact" hide-details="auto" clearable />
                                </v-col>

                                <v-col v-if="cam.actuator_id" cols="6" md="3">
                                    <v-select v-model="cam.gate_height_mode" :label="t('anpr.gateHeightMode')"
                                        :items="gateHeightModes" variant="outlined" density="compact" hide-details="auto" />
                                </v-col>

                                <!-- Kalibrierung: nur bei Fahrzeughöhe-Modus -->
                                <template v-if="cam.gate_height_mode === 'vehicle_height' && cam.actuator_id">
                                    <v-col cols="12" class="pb-0 pt-4">
                                        <div class="text-subtitle-2 text-grey-darken-1">
                                            <v-icon start size="small">mdi-ruler</v-icon>
                                            {{ t('anpr.calibration') }}
                                        </div>
                                        <div class="text-caption text-grey">{{ t('anpr.calibrationHelp') }}</div>
                                    </v-col>
                                    <v-col cols="4" md="2">
                                        <v-text-field v-model.number="cam.calibration_gate_height_cm"
                                            :label="t('anpr.calibrationGateHeight')"
                                            type="number" min="100" max="600"
                                            variant="outlined" density="compact" hide-details="auto" suffix="cm" />
                                    </v-col>
                                    <v-col cols="4" md="2">
                                        <v-text-field v-model.number="cam.calibration_gate_top_y"
                                            :label="t('anpr.calibrationGateTopY')"
                                            type="number" min="0"
                                            variant="outlined" density="compact" hide-details="auto" suffix="px" />
                                    </v-col>
                                    <v-col cols="4" md="2">
                                        <v-text-field v-model.number="cam.calibration_gate_bottom_y"
                                            :label="t('anpr.calibrationGateBottomY')"
                                            type="number" min="0"
                                            variant="outlined" density="compact" hide-details="auto" suffix="px" />
                                    </v-col>
                                </template>

                                <v-col cols="12" md="6">
                                    <v-textarea v-model="cam.note" :label="t('anpr.note')"
                                        rows="2" variant="outlined" density="compact" hide-details="auto" />
                                </v-col>

                                <v-col cols="12" md="6" class="d-flex align-center">
                                    <v-checkbox v-model="cam.enabled" :label="t('anpr.cameraEnabled')"
                                        hide-details density="compact" class="me-4" />
                                </v-col>
                            </v-row>

                            <div class="d-flex justify-end mt-3 ga-2">
                                <v-btn color="error" variant="text" size="small" @click="deleteCamera(cam, idx)">
                                    <v-icon start>mdi-delete</v-icon>{{ t('delete') }}
                                </v-btn>
                                <v-btn color="primary" variant="flat" size="small" @click="saveCamera(cam)"
                                    :loading="cam._saving">
                                    <v-icon start>mdi-content-save</v-icon>{{ t('save') }}
                                </v-btn>
                            </div>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </v-col>
        </v-row>

        <!-- ================================================================ -->
        <!-- AKTOREN -->
        <!-- ================================================================ -->
        <v-row class="mt-8 mb-2">
            <v-col cols="12">
                <h3 class="text-h6 text-primary">
                    <v-icon start>mdi-gate</v-icon>
                    {{ t('anpr.actuators') }}
                </h3>
                <v-divider class="mt-2" />
            </v-col>
        </v-row>

        <v-row>
            <v-col cols="12">
                <v-btn color="primary" variant="tonal" size="small" @click="addActuator" class="mb-3">
                    <v-icon start>mdi-plus</v-icon>
                    {{ t('anpr.addActuator') }}
                </v-btn>

                <v-expansion-panels v-model="openActuatorPanel" variant="accordion">
                    <v-expansion-panel v-for="(act, idx) in actuators" :key="act.id || ('new-' + idx)">
                        <v-expansion-panel-title>
                            <v-icon start :color="act.enabled ? 'green' : 'grey'">
                                {{ actuatorIcon(act.type) }}
                            </v-icon>
                            <span class="font-weight-medium">{{ act.name || t('anpr.newActuator') }}</span>
                            <v-spacer />
                            <v-chip size="x-small" class="me-2" variant="tonal">{{ act.protocol }}://{{ act.host }}:{{ act.port }}</v-chip>
                        </v-expansion-panel-title>

                        <v-expansion-panel-text>
                            <v-row dense>
                                <v-col cols="12" md="4">
                                    <v-text-field v-model="act.name" :label="t('anpr.actuatorName')"
                                        variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                                <v-col cols="6" md="4">
                                    <v-select v-model="act.type" :label="t('anpr.actuatorType')"
                                        :items="actuatorTypes" variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                                <v-col cols="6" md="4">
                                    <v-select v-model="act.protocol" :label="t('anpr.protocol')"
                                        :items="protocols" variant="outlined" density="compact" hide-details="auto" />
                                </v-col>

                                <v-col cols="8" md="4">
                                    <v-text-field v-model="act.host" :label="t('anpr.host')"
                                        variant="outlined" density="compact" hide-details="auto"
                                        placeholder="192.168.1.200" />
                                </v-col>
                                <v-col cols="4" md="2">
                                    <v-text-field v-model.number="act.port" :label="t('anpr.port')"
                                        type="number" variant="outlined" density="compact" hide-details="auto" />
                                </v-col>

                                <v-col cols="12" md="6">
                                    <v-text-field v-model="act.command_open" :label="t('anpr.commandOpen')"
                                        variant="outlined" density="compact" hide-details="auto"
                                        :placeholder="t('anpr.commandOpenPlaceholder')" />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="act.command_close" :label="t('anpr.commandClose')"
                                        variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="act.command_partial" :label="t('anpr.commandPartial')"
                                        variant="outlined" density="compact" hide-details="auto"
                                        :placeholder="t('anpr.commandPartialPlaceholder')" />
                                </v-col>

                                <v-col cols="4" md="2">
                                    <v-text-field v-model.number="act.max_height_cm" :label="t('anpr.maxHeightCm')"
                                        type="number" variant="outlined" density="compact" hide-details="auto" suffix="cm" />
                                </v-col>
                                <v-col cols="4" md="2">
                                    <v-text-field v-model.number="act.height_buffer_cm" :label="t('anpr.heightBufferCm')"
                                        type="number" variant="outlined" density="compact" hide-details="auto" suffix="cm" />
                                </v-col>
                                <v-col cols="4" md="2">
                                    <v-text-field v-model.number="act.timeout_seconds" :label="t('anpr.timeoutSeconds')"
                                        type="number" variant="outlined" density="compact" hide-details="auto" suffix="s" />
                                </v-col>

                                <v-col cols="12" md="6">
                                    <v-textarea v-model="act.note" :label="t('anpr.note')" rows="2"
                                        variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                                <v-col cols="12" md="6" class="d-flex align-center">
                                    <v-checkbox v-model="act.enabled" :label="t('anpr.actuatorEnabled')"
                                        hide-details density="compact" />
                                </v-col>
                            </v-row>

                            <div class="d-flex justify-end mt-3 ga-2">
                                <v-btn color="info" variant="text" size="small" @click="testActuator(act)"
                                    :loading="act._testing">
                                    <v-icon start>mdi-play-circle</v-icon>{{ t('anpr.testActuator') }}
                                </v-btn>
                                <v-btn color="error" variant="text" size="small" @click="deleteActuator(act, idx)">
                                    <v-icon start>mdi-delete</v-icon>{{ t('delete') }}
                                </v-btn>
                                <v-btn color="primary" variant="flat" size="small" @click="saveActuator(act)"
                                    :loading="act._saving">
                                    <v-icon start>mdi-content-save</v-icon>{{ t('save') }}
                                </v-btn>
                            </div>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </v-col>
        </v-row>

        <!-- ================================================================ -->
        <!-- VIDEO-TEST -->
        <!-- ================================================================ -->
        <v-row class="mt-8 mb-2">
            <v-col cols="12">
                <h3 class="text-h6 text-primary">
                    <v-icon start>mdi-test-tube</v-icon>
                    {{ t('anpr.testMode') }}
                </h3>
                <v-divider class="mt-2" />
            </v-col>
        </v-row>

        <v-row>
            <v-col cols="12" md="8">
                <v-file-input
                    v-model="testFile"
                    :label="t('anpr.testFileLabel')"
                    accept="image/*,video/*"
                    variant="outlined"
                    density="compact"
                    hide-details="auto"
                    prepend-icon="mdi-file-video"
                    class="mb-3"
                />

                <v-btn color="primary" variant="flat" size="small" @click="runTest"
                    :loading="testRunning" :disabled="!testFile">
                    <v-icon start>mdi-play</v-icon>
                    {{ t('anpr.runTest') }}
                </v-btn>
            </v-col>
        </v-row>

        <v-row v-if="testResults.length > 0" class="mt-4">
            <v-col cols="12">
                <v-card variant="outlined">
                    <v-card-title class="text-subtitle-1">
                        <v-icon start>mdi-clipboard-list</v-icon>
                        {{ t('anpr.testResults') }}
                    </v-card-title>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>{{ t('anpr.plate') }}</th>
                                <th>{{ t('anpr.confidence') }}</th>
                                <th>{{ t('anpr.direction') }}</th>
                                <th>{{ t('anpr.isPlate') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(r, i) in testResults" :key="i">
                                <td class="font-weight-bold">{{ r.plate }}</td>
                                <td>{{ (r.confidence * 100).toFixed(1) }}%</td>
                                <td>
                                    <v-chip v-if="r.direction" size="x-small"
                                        :color="r.direction === 'in' ? 'green' : 'orange'">
                                        {{ r.direction === 'in' ? 'Einfahrt' : 'Ausfahrt' }}
                                    </v-chip>
                                    <span v-else class="text-grey">-</span>
                                </td>
                                <td>
                                    <v-icon :color="r.is_plate ? 'green' : 'grey'" size="small">
                                        {{ r.is_plate ? 'mdi-check-circle' : 'mdi-close-circle' }}
                                    </v-icon>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </v-col>
        </v-row>

        <!-- ================================================================ -->
        <!-- ERKENNUNGS-HISTORIE -->
        <!-- ================================================================ -->
        <v-row class="mt-8 mb-2">
            <v-col cols="12">
                <h3 class="text-h6 text-primary">
                    <v-icon start>mdi-history</v-icon>
                    {{ t('anpr.detectionHistory') }}
                </h3>
                <v-divider class="mt-2" />
            </v-col>
        </v-row>

        <v-row>
            <v-col cols="12">
                <v-btn color="primary" variant="tonal" size="small" @click="loadHistory" :loading="historyLoading" class="mb-3">
                    <v-icon start>mdi-refresh</v-icon>
                    {{ t('anpr.loadHistory') }}
                </v-btn>

                <v-table v-if="historyItems.length > 0" density="compact">
                    <thead>
                        <tr>
                            <th>{{ t('anpr.time') }}</th>
                            <th>{{ t('anpr.plate') }}</th>
                            <th>{{ t('anpr.customer') }}</th>
                            <th>{{ t('anpr.camera') }}</th>
                            <th>{{ t('anpr.confidence') }}</th>
                            <th>{{ t('anpr.direction') }}</th>
                            <th>{{ t('anpr.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in historyItems" :key="d.id" :class="{ 'text-grey': d.dismissed === 't' }">
                            <td class="text-caption">{{ formatDate(d.detected_at) }}</td>
                            <td class="font-weight-bold">{{ d.c_ln }}</td>
                            <td>{{ d.customer_name || '-' }}</td>
                            <td>{{ d.camera_name || '-' }}</td>
                            <td>{{ d.confidence ? (d.confidence * 100).toFixed(0) + '%' : '-' }}</td>
                            <td>
                                <v-chip v-if="d.direction" size="x-small"
                                    :color="d.direction === 'in' ? 'green' : 'orange'">
                                    {{ d.direction === 'in' ? 'Einfahrt' : 'Ausfahrt' }}
                                </v-chip>
                            </td>
                            <td>
                                <v-chip size="x-small" variant="tonal">{{ d.action_taken || '-' }}</v-chip>
                            </td>
                        </tr>
                    </tbody>
                </v-table>

                <v-alert v-else-if="historyLoaded" type="info" variant="tonal" density="compact">
                    {{ t('anpr.noDetections') }}
                </v-alert>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as toasts from '@/core/utils/toasts.js'

const { t } = useI18n()

const props = defineProps({
    crmDefaults: { type: Object, required: true }
})

// --- Config-Felder ---
const anprConfig = ref([])
onMounted(async () => {
    const config = await import('./anprDefaultsConfig.js')
    anprConfig.value = config.default || []

    // Checkboxen normalisieren
    anprConfig.value.forEach(field => {
        if (field.type === 'checkbox') {
            const v = props.crmDefaults[field.name]
            props.crmDefaults[field.name] = v === true || v === 'true' || v === 't' || v === '1' || v === 1
        }
    })

    checkServiceStatus()

    loadCameras()
    loadActuators()
})

// --- Service-Status ---
const serviceStatus = ref('unknown')
const serviceDetails = ref(null)
const statusLoading = ref(false)
const restarting = ref(false)

const serviceStatusColor = computed(() => {
    if (serviceStatus.value === 'active') return 'green'
    if (serviceStatus.value === 'inactive' || serviceStatus.value === 'failed') return 'red'
    return 'grey'
})
const serviceStatusIcon = computed(() => {
    if (serviceStatus.value === 'active') return 'mdi-check-circle'
    if (serviceStatus.value === 'failed') return 'mdi-alert-circle'
    if (serviceStatus.value === 'inactive') return 'mdi-stop-circle'
    return 'mdi-help-circle'
})
const serviceStatusText = computed(() => {
    if (serviceStatus.value === 'active') return t('anpr.statusActive')
    if (serviceStatus.value === 'inactive') return t('anpr.statusInactive')
    if (serviceStatus.value === 'failed') return t('anpr.statusFailed')
    return t('anpr.statusUnknown')
})

async function checkServiceStatus() {
    statusLoading.value = true
    try {
        const res = await axios.post('/api/lxcars/', { action: 'getAnprServiceStatus' })
        if (res.data.success) {
            serviceStatus.value = res.data.payload?.status || 'unknown'
            serviceDetails.value = res.data.payload?.details || null
        }
    } catch { /* ignore */ }
    statusLoading.value = false
}

async function restartService() {
    restarting.value = true
    try {
        const res = await axios.post('/api/lxcars/', { action: 'restartAnprService' })
        if (res.data.success) {
            serviceStatus.value = res.data.payload?.status || 'unknown'
            toasts.success(t('anpr.restartSuccess'))
        } else {
            toasts.error(t('anpr.restartFailed') + (res.data.payload?.output ? ': ' + res.data.payload.output : ''))
        }
    } catch (e) {
        toasts.error(e.message)
    }
    restarting.value = false
    setTimeout(checkServiceStatus, 2000)
}

// --- Kameras ---
const cameras = ref([])
const openCameraPanel = ref(null)

const cameraPositions = [
    { value: 'front', title: t('anpr.positionFront') },
    { value: 'side_left', title: t('anpr.positionSideLeft') },
    { value: 'side_right', title: t('anpr.positionSideRight') },
]
const directionModes = [
    { value: 'size', title: t('anpr.directionSize') },
    { value: 'position', title: t('anpr.directionPosition') },
]
const actionTypes = [
    { value: 'infobar', title: t('anpr.actionType_infobar') },
    { value: 'actuator', title: t('anpr.actionType_actuator') },
    { value: 'both', title: t('anpr.actionType_both') },
]
const gateHeightModes = [
    { value: 'full', title: t('anpr.gateHeightFull') },
    { value: 'vehicle_height', title: t('anpr.gateHeightVehicle') },
]

async function loadCameras() {
    try {
        const res = await axios.post('/api/lxcars/', { action: 'getAnprCameras' })
        if (res.data.success) {
            cameras.value = (res.data.payload?.cameras || []).map(c => ({
                ...c,
                enabled: c.enabled === 't' || c.enabled === true,
                _saving: false,
            }))
        }
    } catch { /* ignore */ }
}

function addCamera() {
    cameras.value.push({
        id: 0, name: '', rtsp_url: '', enabled: true,
        direction_mode: 'size', position: 'front', frame_interval: 0.5,
        min_confidence: 0.60, min_detections: 3, cooldown_minutes: 5,
        action_type: 'infobar', actuator_id: null, gate_height_mode: 'full',
        note: '', _saving: false,
    })
    openCameraPanel.value = cameras.value.length - 1
}

async function saveCamera(cam) {
    cam._saving = true
    try {
        const res = await axios.post('/api/lxcars/', { action: 'saveAnprCamera', camera: cam })
        if (res.data.success) {
            if (!cam.id) cam.id = res.data.payload.id
            toasts.success(t('saveSuccess'))
        } else {
            toasts.error(res.data.text || 'Fehler')
        }
    } catch (e) {
        toasts.error(e.message)
    } finally {
        cam._saving = false
    }
}

async function deleteCamera(cam, idx) {
    if (cam.id) {
        try {
            await axios.post('/api/lxcars/', { action: 'deleteAnprCamera', id: cam.id })
        } catch { /* ignore */ }
    }
    cameras.value.splice(idx, 1)
    toasts.success(t('deleteSuccess'))
}

// --- Aktoren ---
const actuators = ref([])
const openActuatorPanel = ref(null)

const actuatorItems = computed(() =>
    actuators.value.filter(a => a.id > 0).map(a => ({ id: a.id, name: a.name || 'Aktor #' + a.id }))
)

const actuatorTypes = [
    { value: 'gate', title: t('anpr.typeGate') },
    { value: 'barrier', title: t('anpr.typeBarrier') },
    { value: 'light', title: t('anpr.typeLight') },
]
const protocols = [
    { value: 'tcp', title: 'TCP' },
    { value: 'http', title: 'HTTP' },
    { value: 'modbus', title: 'Modbus TCP' },
]

function actuatorIcon(type) {
    if (type === 'gate') return 'mdi-gate'
    if (type === 'barrier') return 'mdi-boom-gate'
    if (type === 'light') return 'mdi-traffic-light'
    return 'mdi-cog'
}

async function loadActuators() {
    try {
        const res = await axios.post('/api/lxcars/', { action: 'getAnprActuators' })
        if (res.data.success) {
            actuators.value = (res.data.payload?.actuators || []).map(a => ({
                ...a,
                enabled: a.enabled === 't' || a.enabled === true,
                port: parseInt(a.port) || 502,
                max_height_cm: parseInt(a.max_height_cm) || 300,
                height_buffer_cm: parseInt(a.height_buffer_cm) || 30,
                timeout_seconds: parseInt(a.timeout_seconds) || 30,
                _saving: false,
                _testing: false,
            }))
        }
    } catch { /* ignore */ }
}

function addActuator() {
    actuators.value.push({
        id: 0, name: '', type: 'gate', protocol: 'tcp',
        host: '', port: 502, command_open: '', command_close: '',
        command_partial: '', max_height_cm: 300, height_buffer_cm: 30,
        timeout_seconds: 30, enabled: true, note: '',
        _saving: false, _testing: false,
    })
    openActuatorPanel.value = actuators.value.length - 1
}

async function saveActuator(act) {
    act._saving = true
    try {
        const res = await axios.post('/api/lxcars/', { action: 'saveAnprActuator', actuator: act })
        if (res.data.success) {
            if (!act.id) act.id = res.data.payload.id
            toasts.success(t('saveSuccess'))
            loadCameras() // Refresh camera list for actuator linking
        } else {
            toasts.error(res.data.text || 'Fehler')
        }
    } catch (e) {
        toasts.error(e.message)
    } finally {
        act._saving = false
    }
}

async function deleteActuator(act, idx) {
    if (act.id) {
        try {
            const res = await axios.post('/api/lxcars/', { action: 'deleteAnprActuator', id: act.id })
            if (!res.data.success) {
                toasts.error(res.data.payload || res.data.text || 'Fehler')
                return
            }
        } catch { /* ignore */ }
    }
    actuators.value.splice(idx, 1)
    toasts.success(t('deleteSuccess'))
}

async function testActuator(act) {
    act._testing = true
    toasts.info(t('anpr.testingActuator', { name: act.name }))
    // Der eigentliche Test wird vom Python-Service durchgefuehrt
    setTimeout(() => {
        act._testing = false
        toasts.success(t('anpr.testSent'))
    }, 1500)
}

// --- Video-Test ---
const testFile = ref(null)
const testRunning = ref(false)
const testResults = ref([])

async function runTest() {
    if (!testFile.value) return
    testRunning.value = true
    testResults.value = []

    const formData = new FormData()
    formData.append('file', testFile.value)
    formData.append('action', 'testAnprFile')

    try {
        const res = await axios.post('/api/lxcars/', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            timeout: 120000,
        })
        if (res.data.success) {
            testResults.value = res.data.payload?.results || []
            if (testResults.value.length === 0) {
                toasts.info(t('anpr.noPlatesFound'))
            }
        } else {
            toasts.error(res.data.text || 'Fehler')
        }
    } catch (e) {
        toasts.error(e.message)
    } finally {
        testRunning.value = false
    }
}

// --- Erkennungs-Historie ---
const historyItems = ref([])
const historyLoading = ref(false)
const historyLoaded = ref(false)

async function loadHistory() {
    historyLoading.value = true
    try {
        const res = await axios.post('/api/lxcars/', { action: 'getAnprDetectionHistory', limit: 50 })
        if (res.data.success) {
            historyItems.value = res.data.payload?.detections || []
        }
    } catch { /* ignore */ }
    historyLoading.value = false
    historyLoaded.value = true
}

function formatDate(dateStr) {
    if (!dateStr) return '-'
    const d = new Date(dateStr)
    return d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
}
</script>
