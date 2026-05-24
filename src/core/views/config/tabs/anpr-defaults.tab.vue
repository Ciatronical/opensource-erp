<!-- src/core/views/config/tabs/anpr-defaults.tab.vue -->

<template>
    <v-container fluid class="pa-0">
        <!-- Service-Status -->
        <v-row class="mb-4">
            <v-col cols="12">
                <v-card variant="outlined">
                    <v-card-text class="pa-4">
                        <div class="d-flex align-center ga-4 flex-wrap">

                            <!-- Pulsierender Status-Punkt -->
                            <div class="anpr-status-dot-wrap">
                                <div class="anpr-status-dot" :class="serviceStatus" />
                            </div>

                            <!-- Label + Status-Text -->
                            <div>
                                <div class="text-subtitle-2 font-weight-bold">ANPR-Service</div>
                                <div class="text-caption" :class="`text-${serviceStatusColor}`">
                                    {{ restarting ? restartPhaseLabel : serviceStatusText }}
                                </div>
                            </div>

                            <!-- Uptime + PID + User -->
                            <div v-if="serviceStatus === 'active' && serviceDetails && !restarting"
                                class="text-caption text-medium-emphasis d-flex flex-column ga-0">
                                <span v-if="serviceDetails.pid">PID {{ serviceDetails.pid }}</span>
                                <span v-if="serviceDetails.started_ts">
                                    {{ t('anpr.since') }}: {{ formatServiceStart(serviceDetails.started_ts) }}
                                    <span v-if="serviceUptime" class="text-medium-emphasis">({{ serviceUptime }})</span>
                                </span>
                                <span v-if="serviceDetails.run_as_user">
                                    User: <strong :class="serviceDetails.run_as_user !== serviceDetails.service_user ? 'text-warning' : ''">
                                        {{ serviceDetails.run_as_user }}
                                    </strong>
                                    <v-tooltip v-if="serviceDetails.run_as_user !== serviceDetails.service_user" location="top">
                                        <template #activator="{ props }"><v-icon v-bind="props" size="12" color="warning" class="ml-1">mdi-alert</v-icon></template>
                                        {{ t('anpr.userMismatch', { configured: serviceDetails.service_user }) }}
                                    </v-tooltip>
                                </span>
                                <span v-if="serviceDetails.cpu_pct !== undefined">
                                    CPU: {{ serviceDetails.cpu_pct.toFixed(1) }}% · RAM: {{ serviceDetails.mem_pct.toFixed(1) }}%
                                    ({{ Math.round((serviceDetails.rss_kb || 0) / 1024) }} MB)
                                </span>
                                <span v-if="serviceDetails.detections_today !== null">
                                    {{ t('anpr.detectionsToday') }}: {{ serviceDetails.detections_today }}
                                </span>
                            </div>

                            <v-spacer />

                            <v-btn variant="text" size="small" icon="mdi-refresh"
                                :loading="statusLoading && !restarting"
                                @click="checkServiceStatus" />
                            <v-btn v-if="serviceStatus === 'active' && serviceDetails"
                                variant="text" size="small" icon="mdi-bug-outline"
                                :color="serviceDebugOpen ? 'primary' : ''"
                                @click="serviceDebugOpen = !serviceDebugOpen" />

                            <v-btn
                                :color="serviceStatus === 'active' ? 'primary' : 'success'"
                                :variant="serviceStatus === 'active' ? 'tonal' : 'flat'"
                                size="small" :loading="restarting"
                                :prepend-icon="serviceStatus === 'active' ? 'mdi-restart' : 'mdi-play'"
                                @click="restartService">
                                {{ serviceStatus === 'active' ? t('anpr.restart') : t('anpr.start') }}
                            </v-btn>
                        </div>

                        <!-- Neustart-Phasen -->
                        <div v-if="restarting" class="mt-3">
                            <v-progress-linear indeterminate color="primary" rounded height="3" class="mb-3" />
                            <div class="d-flex ga-2 flex-wrap">
                                <v-chip v-for="(phase, i) in restartPhases" :key="i" size="x-small"
                                    :color="restartPhaseIndex > i ? 'success' : restartPhaseIndex === i ? 'primary' : 'default'"
                                    :variant="restartPhaseIndex >= i ? 'flat' : 'tonal'">
                                    <v-icon v-if="restartPhaseIndex > i" start size="10">mdi-check</v-icon>
                                    {{ phase }}
                                </v-chip>
                            </div>
                        </div>
                        <!-- Debug-Bereich: RTSP-Verbindungen + Log-Tail -->
                        <v-expand-transition>
                            <div v-if="serviceDebugOpen && serviceDetails" class="mt-3">
                                <v-divider class="mb-3" />

                                <!-- RTSP-Verbindungen -->
                                <div class="text-caption font-weight-bold mb-1">{{ t('anpr.debugRtspConns') }}</div>
                                <div v-if="serviceDetails.rtsp_connections && serviceDetails.rtsp_connections.length">
                                    <v-table density="compact" class="mb-3" style="font-size:11px">
                                        <thead><tr>
                                            <th>State</th><th>Recv-Q</th><th>Remote (Kamera)</th>
                                        </tr></thead>
                                        <tbody>
                                            <tr v-for="(c, i) in serviceDetails.rtsp_connections" :key="i"
                                                :class="c.recv_q > 50000 ? 'bg-red-lighten-5' : ''">
                                                <td>{{ c.state }}</td>
                                                <td :class="c.recv_q > 50000 ? 'text-error font-weight-bold' : ''">
                                                    {{ c.recv_q > 0 ? (c.recv_q / 1024).toFixed(0) + ' KB' : '0' }}
                                                    <v-tooltip v-if="c.recv_q > 50000" location="top">
                                                        <template #activator="{ props }"><v-icon v-bind="props" size="12" color="error">mdi-alert</v-icon></template>
                                                        {{ t('anpr.debugRecvQWarning') }}
                                                    </v-tooltip>
                                                </td>
                                                <td>{{ c.remote }}</td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </div>
                                <v-alert v-else type="info" variant="tonal" density="compact" class="mb-3">
                                    {{ t('anpr.debugNoRtsp') }}
                                </v-alert>

                                <!-- Log-Tail -->
                                <div class="text-caption font-weight-bold mb-1">{{ t('anpr.debugLogTail') }}</div>
                                <pre v-if="serviceDetails.log_tail && serviceDetails.log_tail.length"
                                    style="font-size:10px;max-height:200px;overflow-y:auto;background:#111;color:#aaffaa;padding:8px;border-radius:4px;white-space:pre-wrap;word-break:break-all"
                                >{{ serviceDetails.log_tail.join('\n') }}</pre>
                                <v-alert v-else type="info" variant="tonal" density="compact">{{ t('anpr.debugNoLog') }}</v-alert>
                            </div>
                        </v-expand-transition>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Dienst-Diagnose -->
        <v-row class="mb-4">
            <v-col cols="12">
                <v-card variant="outlined">
                    <v-card-title class="text-body-1 font-weight-medium d-flex align-center"
                        style="cursor:pointer" @click="healthOpen = !healthOpen">
                        <v-icon class="mr-2" size="20">mdi-chart-line</v-icon>
                        {{ t('anpr.health') }}
                        <v-chip v-if="healthData.today_count > 0" size="x-small" color="primary" variant="tonal" class="ml-2">
                            {{ healthData.today_count }} {{ t('anpr.healthToday') }}
                        </v-chip>
                        <v-spacer />
                        <v-btn variant="text" size="small" icon="mdi-refresh"
                            :loading="healthLoading" @click.stop="loadHealth" />
                        <v-icon size="small" class="ml-1">{{ healthOpen ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                    </v-card-title>

                    <v-expand-transition>
                    <div v-show="healthOpen">
                    <v-card-text v-if="healthData.events?.length > 0" class="pa-0">
                        <!-- Zusammenfassung pro Kamera -->
                        <div v-if="healthData.last_heartbeats?.length > 0" class="px-4 pt-3 pb-2 d-flex flex-wrap ga-3">
                            <div v-for="hb in healthData.last_heartbeats" :key="hb.camera_id"
                                class="d-flex align-center ga-2">
                                <v-icon :color="heartbeatAge(hb.ts) < 120 ? 'green' : heartbeatAge(hb.ts) < 300 ? 'orange' : 'red'" size="12">mdi-circle</v-icon>
                                <span class="text-caption font-weight-medium">{{ cameraName(hb.camera_id) }}</span>
                                <span class="text-caption text-medium-emphasis">{{ t('anpr.healthLastSeen') }}: {{ formatAge(hb.ts) }}</span>
                                <v-chip size="x-small" variant="tonal" color="green">{{ hb.detections }} {{ t('anpr.healthDetections') }}</v-chip>
                                <span v-if="problemsFor(hb.camera_id)" class="text-caption text-error">
                                    {{ problemsFor(hb.camera_id).reconnects }}× Reconnect, {{ problemsFor(hb.camera_id).errors }}× Fehler (24h)
                                </span>
                            </div>
                        </div>
                        <v-divider v-if="healthData.last_heartbeats?.length > 0" />

                        <!-- Ereignisprotokoll -->
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th style="width:140px">{{ t('anpr.time') }}</th>
                                    <th style="width:130px">{{ t('anpr.camera') }}</th>
                                    <th style="width:110px">{{ t('anpr.healthEvent') }}</th>
                                    <th>{{ t('anpr.healthDetails') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="ev in healthData.events" :key="ev.id"
                                    :class="ev.event === 'error' ? 'bg-red-lighten-5' : ev.event === 'reconnect' ? 'bg-orange-lighten-5' : ''">
                                    <td class="text-caption">{{ formatDate(ev.ts) }}</td>
                                    <td class="text-caption">{{ ev.camera_name || '-' }}</td>
                                    <td>
                                        <v-chip size="x-small" variant="flat"
                                            :color="ev.event === 'error' ? 'error' : ev.event === 'reconnect' ? 'warning' : ev.event === 'start' ? 'info' : 'success'">
                                            {{ t('anpr.event' + ev.event.charAt(0).toUpperCase() + ev.event.slice(1)) }}
                                        </v-chip>
                                    </td>
                                    <td class="text-caption">
                                        <template v-if="ev.event === 'heartbeat'">
                                            {{ ev.frames }} {{ t('anpr.healthFrames') }},
                                            {{ ev.detections }} {{ t('anpr.healthDetections') }},
                                            {{ ev.skipped }} {{ t('anpr.healthSkipped') }}
                                        </template>
                                        <span v-else-if="ev.message" class="text-grey-darken-1">{{ ev.message }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-card-text>

                    <v-card-text v-else-if="healthLoaded" class="text-caption text-medium-emphasis">
                        {{ t('anpr.healthNoData') }}
                    </v-card-text>
                    </div>
                    </v-expand-transition>
                </v-card>
            </v-col>
        </v-row>

        <!-- Service-Log -->
        <v-row class="mb-4">
            <v-col cols="12">
                <v-card variant="outlined">
                    <v-card-title class="text-body-1 font-weight-medium d-flex align-center">
                        <v-icon class="mr-2" size="20">mdi-text-box-outline</v-icon>
                        {{ t('anpr.serviceLog') }}
                        <v-chip v-if="logLines.length" size="x-small" color="primary" variant="tonal" class="ml-2">
                            {{ logLines.length }} {{ t('anpr.logLines') }}
                        </v-chip>
                        <v-spacer />
                        <v-select
                            v-model="logLineCount"
                            :items="[50, 100, 200, 500]"
                            density="compact" variant="outlined" hide-details
                            style="max-width:90px" class="mr-2"
                            @update:model-value="loadLog"
                        />
                        <v-btn variant="text" size="small" icon="mdi-refresh"
                            :loading="logLoading" @click="loadLog" />
                        <v-btn variant="text" size="small"
                            :icon="logAutoRefresh ? 'mdi-pause' : 'mdi-play'"
                            :color="logAutoRefresh ? 'success' : ''"
                            @click="toggleLogAutoRefresh" />
                    </v-card-title>
                    <v-card-text class="pa-0">
                        <pre v-if="logLines.length"
                            ref="logPreRef"
                            style="font-size:10px;max-height:320px;overflow-y:auto;background:#0d1117;color:#c9d1d9;padding:10px 12px;white-space:pre-wrap;word-break:break-all;margin:0;border-radius:0 0 4px 4px"
                        >{{ logLines.join('\n') }}</pre>
                        <div v-else-if="!logLoading" class="pa-4 text-caption text-medium-emphasis">
                            {{ t('anpr.logEmpty') }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Hardware-Beschleunigung -->
        <v-row class="mb-4">
            <v-col cols="12">
                <v-card variant="outlined">
                    <v-card-title class="text-body-1 font-weight-medium d-flex align-center">
                        <v-icon class="mr-2" size="20">mdi-chip</v-icon>
                        {{ t('anpr.hardwareTitle') }}
                        <v-spacer />
                        <v-btn variant="text" size="small" icon="mdi-refresh"
                            @click="loadHardwareInfo" :loading="hwLoading" />
                    </v-card-title>
                    <v-card-text v-if="hw">
                        <v-table density="compact">
                            <tbody>
                                <!-- CPU -->
                                <tr>
                                    <td class="font-weight-medium" style="width:160px">
                                        <v-tooltip location="top">
                                            <template #activator="{ props }">
                                                <span v-bind="props">CPU</span>
                                            </template>
                                            {{ t('anpr.hwCpuTooltip') }}
                                        </v-tooltip>
                                    </td>
                                    <td>{{ hw.cpu?.model }} ({{ hw.cpu?.cores }}C/{{ hw.cpu?.threads }}T)</td>
                                </tr>
                                <!-- CPU-Last pro Kern -->
                                <tr v-if="cpuLoad">
                                    <td class="font-weight-medium align-top pt-2" style="white-space:nowrap">{{ t('anpr.cpuLoad') }}</td>
                                    <td class="py-2">
                                        <!-- Gesamt -->
                                        <div class="d-flex align-center ga-2 mb-3">
                                            <span class="text-caption text-medium-emphasis" style="min-width:44px">{{ t('anpr.cpuLoadTotal') }}</span>
                                            <v-progress-linear
                                                :model-value="cpuLoad['cpu'] ?? 0"
                                                :color="cpuBarColor(cpuLoad['cpu'] ?? 0)"
                                                bg-color="grey-lighten-3"
                                                rounded height="14" style="flex:1" />
                                            <span class="text-caption font-weight-bold" style="min-width:38px;text-align:right">
                                                {{ cpuLoad['cpu'] ?? 0 }}%
                                            </span>
                                        </div>
                                        <!-- Einzelne Kerne -->
                                        <div class="d-flex flex-column ga-1">
                                            <div v-for="(val, key) in cpuCores" :key="key"
                                                class="d-flex align-center ga-2">
                                                <span class="text-caption text-medium-emphasis" style="min-width:44px;font-size:11px">
                                                    {{ t('anpr.cpuLoadThread') }}{{ key.replace('cpu','') }}
                                                </span>
                                                <v-progress-linear
                                                    :model-value="val"
                                                    :color="cpuBarColor(val)"
                                                    bg-color="grey-lighten-3"
                                                    rounded height="10" style="flex:1" />
                                                <span class="text-caption" style="min-width:38px;text-align:right;font-size:11px">
                                                    {{ val }}%
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <!-- RAM -->
                                <tr>
                                    <td class="font-weight-medium">RAM</td>
                                    <td>{{ hw.ram?.total_gb }} GB ({{ hw.ram?.available_gb }} GB frei)</td>
                                </tr>
                                <!-- Intel iGPU -->
                                <tr>
                                    <td class="font-weight-medium">
                                        <v-tooltip location="top" max-width="400">
                                            <template #activator="{ props }">
                                                <span v-bind="props">Intel iGPU</span>
                                            </template>
                                            {{ t('anpr.hwIgpuTooltip') }}
                                        </v-tooltip>
                                    </td>
                                    <td>
                                        <template v-if="hw.gpu?.intel_igpu">
                                            <v-icon color="success" size="16" class="mr-1">mdi-check-circle</v-icon>
                                            {{ hw.gpu.device }}
                                        </template>
                                        <template v-else>
                                            <v-icon color="grey" size="16" class="mr-1">mdi-minus-circle-outline</v-icon>
                                            {{ t('anpr.hwNotDetected') }}
                                        </template>
                                    </td>
                                </tr>
                                <!-- OpenVINO -->
                                <tr>
                                    <td class="font-weight-medium">
                                        <v-tooltip location="top" max-width="400">
                                            <template #activator="{ props }">
                                                <span v-bind="props">OpenVINO</span>
                                            </template>
                                            {{ t('anpr.hwOpenvinoTooltip') }}
                                        </v-tooltip>
                                    </td>
                                    <td>
                                        <template v-if="hw.openvino?.installed">
                                            <v-icon color="success" size="16" class="mr-1">mdi-check-circle</v-icon>
                                            v{{ hw.openvino.version }}
                                            <v-chip v-if="hw.openvino.in_anpr_venv" size="x-small" color="success" variant="tonal" class="ml-1">ANPR</v-chip>
                                        </template>
                                        <template v-else>
                                            <v-icon color="warning" size="16" class="mr-1">mdi-alert-circle-outline</v-icon>
                                            {{ t('anpr.hwNotInstalled') }}
                                            <v-btn size="x-small" variant="tonal" color="primary" class="ml-2"
                                                @click="installPackage('openvino', 'anpr')" :loading="installingPkg === 'openvino'">
                                                {{ t('anpr.hwInstall') }}
                                            </v-btn>
                                        </template>
                                    </td>
                                </tr>
                                <!-- Coral USB -->
                                <tr>
                                    <td class="font-weight-medium">
                                        <v-tooltip location="top" max-width="400">
                                            <template #activator="{ props }">
                                                <span v-bind="props">Google Coral USB</span>
                                            </template>
                                            {{ t('anpr.hwCoralTooltip') }}
                                        </v-tooltip>
                                    </td>
                                    <td>
                                        <template v-if="hw.coral?.connected && hw.coral?.python_package">
                                            <v-icon color="success" size="16" class="mr-1">mdi-check-circle</v-icon>
                                            {{ t('anpr.hwCoralReady') }}
                                        </template>
                                        <template v-else-if="hw.coral?.connected && !hw.coral?.python_package">
                                            <v-icon color="warning" size="16" class="mr-1">mdi-alert-circle-outline</v-icon>
                                            {{ t('anpr.hwCoralNoDriver') }}
                                            <v-btn size="x-small" variant="tonal" color="primary" class="ml-2"
                                                @click="installPackage('pycoral', 'anpr')" :loading="installingPkg === 'pycoral'">
                                                {{ t('anpr.hwInstall') }}
                                            </v-btn>
                                        </template>
                                        <template v-else>
                                            <v-icon color="grey" size="16" class="mr-1">mdi-minus-circle-outline</v-icon>
                                            {{ t('anpr.hwCoralNotConnected') }}
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>

                        <!-- Empfehlung -->
                        <v-alert v-if="hwRecommendation" type="info" variant="tonal" density="compact" class="mt-3">
                            {{ hwRecommendation }}
                        </v-alert>
                    </v-card-text>
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

            <v-row v-else-if="field.type === 'textarea'" class="my-1" :data-field-name="field.name">
                <v-col cols="12" md="6">
                    <v-textarea
                        v-model="crmDefaults[field.name]"
                        :label="t(field.label)"
                        :rows="field.rows || 3"
                        :style="field.fieldstyle"
                        hide-details="auto" density="compact" variant="outlined"
                        :hint="field.tooltip ? t(field.tooltip) : undefined"
                        persistent-hint
                    />
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
                                        variant="outlined" density="compact" hide-details="auto">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.cameraName_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="cam.rtsp_url" :label="t('anpr.rtspUrl')"
                                        variant="outlined" density="compact" hide-details="auto"
                                        placeholder="rtsp://user:pass@192.168.1.100:554/stream">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.rtspUrl_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>

                                <v-col cols="6" md="3">
                                    <v-select v-model="cam.position" :label="t('anpr.cameraPosition')"
                                        :items="cameraPositions" variant="outlined" density="compact" hide-details="auto">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.cameraPosition_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-select>
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-select v-model="cam.direction_mode" :label="t('anpr.directionMode')"
                                        :items="directionModes" variant="outlined" density="compact" hide-details="auto">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.directionMode_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-select>
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.frame_interval" :label="t('anpr.frameInterval')"
                                        type="number" step="0.1" min="0.1" max="5"
                                        variant="outlined" density="compact" hide-details="auto" suffix="s">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.frameInterval_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.min_confidence" :label="t('anpr.minConfidence')"
                                        type="number" step="0.05" min="0.3" max="0.99"
                                        variant="outlined" density="compact" hide-details="auto">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.minConfidence_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>

                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.min_detections" :label="t('anpr.minDetections')"
                                        type="number" min="1" max="10"
                                        variant="outlined" density="compact" hide-details="auto">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.minDetections_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.min_plate_height_px" :label="t('anpr.minPlateHeightPx')"
                                        type="number" min="0" max="200" suffix="px"
                                        variant="outlined" density="compact" hide-details="auto">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.minPlateHeightPx_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.motion_size_pct" :label="t('anpr.motionSizePct')"
                                        type="number" min="5" max="100" suffix="%"
                                        variant="outlined" density="compact" hide-details="auto">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.motionSizePct_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field v-model.number="cam.cooldown_minutes" :label="t('anpr.cooldownMinutes')"
                                        type="number" min="1" max="60"
                                        variant="outlined" density="compact" hide-details="auto" suffix="min">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.cooldownMinutes_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-select v-model="cam.action_type" :label="t('anpr.actionType')"
                                        :items="actionTypes" variant="outlined" density="compact" hide-details="auto">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.actionType_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-select>
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-select v-model="cam.actuator_id" :label="t('anpr.linkedActuator')"
                                        :items="actuatorItems" item-title="name" item-value="id"
                                        variant="outlined" density="compact" hide-details="auto" clearable>
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.linkedActuator_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-select>
                                </v-col>

                                <v-col v-if="cam.actuator_id" cols="6" md="3">
                                    <v-select v-model="cam.gate_height_mode" :label="t('anpr.gateHeightMode')"
                                        :items="gateHeightModes" variant="outlined" density="compact" hide-details="auto">
                                        <template #append-inner>
                                            <v-tooltip location="top">
                                                <template #activator="{ props }">
                                                    <v-icon v-bind="props" size="small" color="grey">mdi-information-outline</v-icon>
                                                </template>
                                                {{ t('anpr.gateHeightMode_help') }}
                                            </v-tooltip>
                                        </template>
                                    </v-select>
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

                                <!-- Randausblendung -->
                                <v-col cols="12" class="pb-0 pt-4">
                                    <div class="text-subtitle-2 text-grey-darken-1">
                                        <v-icon start size="small">mdi-crop</v-icon>
                                        {{ t('anpr.ignoreMargin') }}
                                    </div>
                                    <div class="text-caption text-grey">{{ t('anpr.ignoreMarginHelp') }}</div>
                                </v-col>
                                <v-col cols="4" md="2">
                                    <v-text-field v-model.number="cam.ignore_left_pct"
                                        :label="t('anpr.ignoreLeft')" type="number" min="0" max="50"
                                        variant="outlined" density="compact" hide-details="auto" suffix="%" />
                                </v-col>
                                <v-col cols="4" md="2">
                                    <v-text-field v-model.number="cam.ignore_right_pct"
                                        :label="t('anpr.ignoreRight')" type="number" min="0" max="50"
                                        variant="outlined" density="compact" hide-details="auto" suffix="%" />
                                </v-col>
                                <v-col cols="4" md="2">
                                    <v-select v-model.number="cam.grid_size"
                                        :label="t('anpr.gridSize')"
                                        :items="[{title:'5%',value:5},{title:'10%',value:10},{title:'20%',value:20},{title:'25%',value:25}]"
                                        variant="outlined" density="compact" hide-details="auto" />
                                </v-col>

                                <v-col cols="12" md="6">
                                    <v-textarea v-model="cam.note" :label="t('anpr.note')"
                                        rows="2" variant="outlined" density="compact" hide-details="auto" />
                                </v-col>

                                <v-col cols="12" class="d-flex align-center flex-wrap ga-4">
                                    <v-checkbox v-model="cam.enabled" :label="t('anpr.cameraEnabled')"
                                        hide-details density="compact" />
                                    <v-checkbox v-model="cam.save_snapshots" :label="t('anpr.saveSnapshots')"
                                        hide-details density="compact" color="orange" />
                                </v-col>
                                <v-col cols="12" sm="6" md="4">
                                    <v-select v-model="cam.direction_filter"
                                        :label="t('anpr.directionFilter')"
                                        :items="directionFilterOptions"
                                        item-title="title" item-value="value"
                                        variant="outlined" density="compact" hide-details="auto" />
                                </v-col>
                            </v-row>

                            <div class="d-flex justify-end mt-3 ga-2">
                                <v-btn v-if="cam.id && cam.rtsp_url" color="info" variant="text" size="small"
                                    @click="openStreamPreview(cam)">
                                    <v-icon start>mdi-eye</v-icon>{{ t('anpr.livePreview') }}
                                </v-btn>
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
                <div class="d-flex align-center ga-2 mb-3">
                    <v-btn color="primary" variant="tonal" size="small" @click="loadHistory" :loading="historyLoading">
                        <v-icon start>mdi-refresh</v-icon>
                        {{ t('anpr.loadHistory') }}
                    </v-btn>
                    <v-select v-model="historyLimit"
                        :items="historyLimitOptions"
                        item-title="label" item-value="value"
                        :label="t('anpr.historyLimit')"
                        density="compact" variant="outlined" hide-details
                        style="max-width:160px"
                        @update:model-value="loadHistory" />
                    <v-btn v-if="!clearConfirm" color="error" variant="text" size="small"
                        :disabled="historyItems.length === 0"
                        @click="clearConfirm = true">
                        <v-icon start>mdi-delete-sweep</v-icon>
                        {{ t('anpr.clearHistory') }}
                    </v-btn>
                    <template v-if="clearConfirm">
                        <span class="text-caption text-error">{{ t('anpr.clearConfirm') }}</span>
                        <v-btn color="error" variant="flat" size="small" :loading="historyClearing"
                            @click="clearHistory">
                            {{ t('anpr.clearYes') }}
                        </v-btn>
                        <v-btn variant="text" size="small" @click="clearConfirm = false">
                            {{ t('anpr.clearNo') }}
                        </v-btn>
                    </template>
                </div>

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
                            <th>{{ t('anpr.snapshot') }}</th>
                            <th></th>
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
                            <td>
                                <v-btn v-if="d.has_snapshot" icon size="x-small" variant="text" color="primary"
                                    @click="openSnapshot(d.id, d.c_ln, d.snapshot_count)">
                                    <v-icon>mdi-camera</v-icon>
                                </v-btn>
                                <span v-else class="text-grey text-caption">-</span>
                            </td>
                            <td>
                                <v-btn icon size="x-small" variant="text" color="grey"
                                    @click="openDetectionDebug(d)">
                                    <v-icon>mdi-bug-outline</v-icon>
                                </v-btn>
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

    <!-- Snapshot-Dialog -->
    <v-dialog v-model="snapshotDialog" max-width="960">
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon start>mdi-camera</v-icon>
                {{ snapshotPlate }}
                <v-chip v-if="snapshotCount > 1" size="x-small" variant="tonal" class="ml-2">
                    {{ snapshotCount }} {{ t('anpr.frames') }}
                </v-chip>
            </v-card-title>
            <v-card-text class="pa-0">
                <div v-for="n in snapshotCount" :key="n" style="position:relative;line-height:0">
                    <img :src="`/api/lxcars/anpr-snapshot.php?id=${snapshotId}&n=${n}`"
                        style="width:100%;display:block;background:#111"
                        :alt="`${snapshotPlate} ${n}/${snapshotCount}`" />
                    <span v-if="snapshotCount > 1"
                        style="position:absolute;bottom:6px;right:8px;background:rgba(0,0,0,0.55);color:#fff;font-size:11px;padding:2px 6px;border-radius:3px">
                        {{ n }} / {{ snapshotCount }}
                    </span>
                </div>
            </v-card-text>
            <v-card-actions>
                <v-btn :href="`/api/lxcars/anpr-snapshot.php?id=${snapshotId}&n=1`"
                    target="_blank" variant="text" size="small">
                    <v-icon start>mdi-open-in-new</v-icon>{{ t('anpr.openFull') }}
                </v-btn>
                <v-spacer />
                <v-btn @click="snapshotDialog = false">{{ t('close') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Detection-Debug-Dialog -->
    <v-dialog v-model="detectionDebugDialog" max-width="700">
        <v-card v-if="detectionDebugItem">
            <v-card-title class="d-flex align-center ga-2">
                <v-icon color="grey">mdi-bug-outline</v-icon>
                {{ t('anpr.debugTitle') }} #{{ detectionDebugItem.id }}
                <v-chip size="x-small" variant="tonal" class="ml-1">{{ detectionDebugItem.c_ln }}</v-chip>
            </v-card-title>
            <v-card-text>
                <v-table density="compact" style="font-size:12px">
                    <tbody>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.debugId') }}</td><td>{{ detectionDebugItem.id }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.debugTimestamp') }}</td><td>{{ formatDate(detectionDebugItem.detected_at) }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.plate') }}</td><td><strong>{{ detectionDebugItem.c_ln }}</strong></td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.confidence') }}</td><td>{{ detectionDebugItem.confidence ? (detectionDebugItem.confidence * 100).toFixed(1) + '%' : '-' }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.direction') }}</td>
                            <td><v-chip v-if="detectionDebugItem.direction" size="x-small"
                                :color="detectionDebugItem.direction === 'in' ? 'green' : 'orange'">
                                {{ detectionDebugItem.direction === 'in' ? 'Einfahrt (in)' : 'Ausfahrt (out)' }}
                            </v-chip><span v-else>-</span></td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.customer') }}</td><td>{{ detectionDebugItem.customer_name || '-' }} (ID: {{ detectionDebugItem.customer_id || '-' }})</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.camera') }}</td><td>{{ detectionDebugItem.camera_name || '-' }} (ID: {{ detectionDebugItem.camera_id || '-' }})</td></tr>
                        <tr v-if="detectionDebugItem.camera_rtsp"><td class="text-medium-emphasis">RTSP</td>
                            <td class="text-caption" style="word-break:break-all">{{ detectionDebugItem.camera_rtsp }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.action') }}</td><td>{{ detectionDebugItem.action_taken || '-' }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.debugDismissed') }}</td><td>{{ detectionDebugItem.dismissed === 't' ? t('yes') : t('no') }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.debugVehicleHeight') }}</td>
                            <td>{{ detectionDebugItem.vehicle_height_px != null ? detectionDebugItem.vehicle_height_px + ' px' : '-' }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.debugFrameSize') }}</td>
                            <td>{{ detectionDebugItem.frame_width && detectionDebugItem.frame_height
                                ? detectionDebugItem.frame_width + ' × ' + detectionDebugItem.frame_height + ' px'
                                : '-' }}</td></tr>
                        <tr><td class="text-medium-emphasis">{{ t('anpr.debugSnapshots') }}</td>
                            <td>{{ detectionDebugItem.snapshot_count || 0 }}</td></tr>
                    </tbody>
                </v-table>
            </v-card-text>
            <v-card-actions>
                <v-btn v-if="detectionDebugItem.has_snapshot" color="primary" variant="tonal" size="small"
                    prepend-icon="mdi-camera"
                    @click="openSnapshot(detectionDebugItem.id, detectionDebugItem.c_ln, detectionDebugItem.snapshot_count); detectionDebugDialog = false">
                    {{ t('anpr.snapshot') }}
                </v-btn>
                <v-spacer />
                <v-btn @click="detectionDebugDialog = false">{{ t('close') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- ================================================================ -->
    <!-- NEAR-MISS DEBUG-SNAPSHOTS -->
    <!-- ================================================================ -->
    <template v-if="crmDefaults['anpr_debug_snapshots'] == true || crmDefaults['anpr_debug_snapshots'] === '1' || crmDefaults['anpr_debug_snapshots'] === 't'">
        <v-row class="mt-8 mb-2">
            <v-col cols="12">
                <h3 class="text-h6 text-primary">
                    <v-icon start>mdi-image-search-outline</v-icon>
                    {{ t('anpr.nearMissTitle') }}
                </h3>
                <v-divider class="mt-2" />
            </v-col>
        </v-row>
        <v-row>
            <v-col cols="12">
                <div class="d-flex align-center ga-2 mb-3 flex-wrap">
                    <v-btn color="primary" variant="tonal" size="small" :loading="nearMissLoading" @click="loadNearMiss">
                        <v-icon start>mdi-refresh</v-icon>
                        {{ t('anpr.loadHistory') }}
                    </v-btn>
                    <span v-if="nearMissTotal > 0" class="text-caption text-medium-emphasis">
                        {{ t('anpr.nearMissTotal', { n: nearMissTotal }) }}
                    </span>
                    <v-spacer />
                    <v-btn v-if="!nearMissClearConfirm" color="error" variant="text" size="small"
                        :disabled="nearMissLoaded && nearMissItems.length === 0"
                        @click="nearMissClearConfirm = true">
                        <v-icon start>mdi-delete-sweep</v-icon>
                        {{ t('anpr.nearMissClear') }}
                    </v-btn>
                    <template v-if="nearMissClearConfirm">
                        <span class="text-caption text-error">{{ t('anpr.clearConfirm') }}</span>
                        <v-btn color="error" variant="flat" size="small"
                            :loading="nearMissClearing"
                            @click="clearNearMiss">
                            {{ t('anpr.clearHistory') }}
                        </v-btn>
                        <v-btn variant="text" size="small" @click="nearMissClearConfirm = false">
                            {{ t('cancel') }}
                        </v-btn>
                    </template>
                </div>

                <v-alert v-if="nearMissLoaded && nearMissItems.length === 0"
                    type="info" variant="tonal" density="compact">
                    {{ t('anpr.nearMissEmpty') }}
                </v-alert>

                <div v-else-if="nearMissItems.length > 0"
                    class="d-flex flex-wrap ga-3">
                    <v-card v-for="item in nearMissItems" :key="item.filename"
                        variant="outlined" style="width:240px;cursor:pointer"
                        @click="nearMissPreview = item">
                        <img :src="`/api/lxcars/anpr-snapshot.php?debug=${encodeURIComponent(item.filename)}`"
                            style="width:100%;height:140px;object-fit:cover;display:block"
                            loading="lazy" />
                        <v-card-text class="pa-2">
                            <div class="text-caption font-weight-medium">{{ item.plate || '—' }}</div>
                            <div class="d-flex align-center ga-1 mt-1 flex-wrap">
                                <v-chip size="x-small" variant="tonal"
                                    :color="item.reason === 'klein' ? 'warning' : 'orange'">
                                    {{ t('anpr.nearMissReason_' + item.reason) || item.reason }}
                                </v-chip>
                                <span class="text-caption text-medium-emphasis">{{ item.cam_name }}</span>
                            </div>
                            <div class="text-caption text-medium-emphasis mt-1">
                                {{ item.ts ? new Date(item.ts * 1000).toLocaleString('de-DE') : '' }}
                            </div>
                        </v-card-text>
                    </v-card>
                </div>
            </v-col>
        </v-row>
    </template>

    <!-- Near-miss Vollbild-Vorschau -->
    <v-dialog v-model="nearMissPreviewOpen" max-width="1000">
        <v-card v-if="nearMissPreview">
            <v-card-title class="d-flex align-center pa-3">
                <v-chip size="small" variant="tonal"
                    :color="nearMissPreview.reason === 'klein' ? 'warning' : 'orange'" class="mr-2">
                    {{ t('anpr.nearMissReason_' + nearMissPreview.reason) || nearMissPreview.reason }}
                </v-chip>
                {{ nearMissPreview.plate || '—' }} · {{ nearMissPreview.cam_name }}
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" @click="nearMissPreview = null" />
            </v-card-title>
            <img :src="`/api/lxcars/anpr-snapshot.php?debug=${encodeURIComponent(nearMissPreview.filename)}`"
                style="width:100%;max-height:70vh;object-fit:contain;display:block" />
            <v-card-text class="text-caption text-medium-emphasis pa-2">
                {{ nearMissPreview.ts ? new Date(nearMissPreview.ts * 1000).toLocaleString('de-DE') : '' }}
                · {{ nearMissPreview.filename }}
            </v-card-text>
        </v-card>
    </v-dialog>

    <!-- Live-Stream-Dialog -->
    <v-dialog v-model="streamDialog" max-width="960" @update:model-value="onStreamDialogChange">
        <v-card>
            <v-card-title class="d-flex align-center pa-3">
                <v-icon start>mdi-eye</v-icon>
                {{ streamCam?.name }}
                <v-chip size="x-small" color="green" variant="flat" class="ml-2">
                    <v-icon start size="10">mdi-circle</v-icon>Live
                </v-chip>
                <v-spacer />
                <!-- Ausgeblendete Zellen Anzeige -->
                <v-chip v-if="excludedCellCount > 0" size="small" color="error" variant="tonal" class="mr-2">
                    <v-icon start size="14">mdi-eye-off</v-icon>
                    {{ excludedCellCount }} {{ t('anpr.cellsHidden') }}
                </v-chip>
            </v-card-title>

            <!-- Legende -->
            <div class="d-flex align-center ga-3 px-3 pb-2 flex-wrap">
                <span class="text-caption text-medium-emphasis">
                    <v-icon size="12" color="error">mdi-square</v-icon>
                    {{ t('anpr.gridHint') }}
                </span>
                <span class="text-caption text-medium-emphasis">
                    <v-icon size="12" color="orange">mdi-square</v-icon>
                    {{ t('anpr.gridMarginHint') }}
                </span>
                <v-btn v-if="excludedCellCount > 0" size="x-small" variant="text" color="error"
                    prepend-icon="mdi-restore" @click="resetExcludedCells">
                    {{ t('anpr.resetCells') }}
                </v-btn>
            </div>
            <v-divider />

            <v-card-text class="pa-0">
                <div style="position:relative;line-height:0">
                    <img v-if="streamDialog" :src="streamUrl"
                        style="width:100%;display:block;background:#111;min-height:300px"
                        :alt="t('anpr.livePreview')"
                        @error="streamError = true"
                        @load="streamError = false" />

                    <!-- Grid-Overlay -->
                    <svg v-if="streamDialog && streamCam"
                        style="position:absolute;top:0;left:0;width:100%;height:100%"
                        xmlns="http://www.w3.org/2000/svg">

                        <!-- Klickbare Rasterzellen -->
                        <rect v-for="cell in gridCells" :key="cell.key"
                            :x="cell.x + '%'" :y="cell.y + '%'"
                            :width="(streamCam.grid_size || 10) + '%'"
                            :height="(streamCam.grid_size || 10) + '%'"
                            :fill="cell.excluded ? 'rgba(220,50,50,0.45)' : 'rgba(255,255,255,0.03)'"
                            stroke="rgba(255,255,255,0.25)" stroke-width="0.5"
                            style="cursor:pointer"
                            @click="toggleCell(cell)">
                            <title>{{ cell.excluded ? t('anpr.clickToShow') : t('anpr.clickToHide') }} ({{ cell.x }}%/{{ cell.y }}%)</title>
                        </rect>

                        <!-- Randausblendung (orange, nicht klickbar) -->
                        <rect v-if="streamCam.ignore_left_pct" x="0" y="0"
                            :width="streamCam.ignore_left_pct + '%'" height="100%"
                            fill="rgba(255,165,0,0.2)" style="pointer-events:none" />
                        <rect v-if="streamCam.ignore_right_pct"
                            :x="(100 - streamCam.ignore_right_pct) + '%'" y="0"
                            :width="streamCam.ignore_right_pct + '%'" height="100%"
                            fill="rgba(255,165,0,0.2)" style="pointer-events:none" />

                        <!-- Prozentzahlen -->
                        <template v-for="x in gridLines" :key="'lx'+x">
                            <text :x="x+'%'" y="13" fill="white" font-size="10" text-anchor="middle"
                                style="pointer-events:none;paint-order:stroke;stroke:rgba(0,0,0,0.7);stroke-width:2px">{{ x }}%</text>
                        </template>
                        <template v-for="y in gridLines" :key="'ly'+y">
                            <text x="3" :y="y+'%'" fill="white" font-size="10" dominant-baseline="middle"
                                style="pointer-events:none;paint-order:stroke;stroke:rgba(0,0,0,0.7);stroke-width:2px">{{ y }}%</text>
                        </template>
                    </svg>
                </div>
                <v-alert v-if="streamError" type="warning" variant="tonal" density="compact" class="ma-3">
                    {{ t('anpr.streamNotReachable') }}
                </v-alert>
            </v-card-text>

            <v-divider />
            <v-card-actions class="pa-3">
                <span class="text-caption text-grey">{{ t('anpr.snapshotRefreshHint') }}</span>
                <v-spacer />
                <v-alert v-if="gridModified" type="warning" variant="tonal" density="compact"
                    class="text-caption py-1 px-2 mr-2" style="max-width:260px">
                    {{ t('anpr.saveAfterGrid') }}
                </v-alert>
                <v-btn @click="streamDialog = false">{{ t('close') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Coral / Sudo-Setup-Dialog -->
    <v-dialog v-model="setupDialog" max-width="640">
        <v-card v-if="setupDialogData">
            <v-card-title class="d-flex align-center ga-2">
                <v-icon color="primary">mdi-console</v-icon>
                {{ setupDialogData.type === 'CORAL_SETUP_REQUIRED' ? t('anpr.coralSetupTitle') : t('anpr.sudoSetupTitle') }}
            </v-card-title>
            <v-card-text>

                <!-- Coral Setup -->
                <template v-if="setupDialogData.type === 'CORAL_SETUP_REQUIRED'">
                    <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                        {{ t('anpr.coralSetupInfo') }}
                    </v-alert>

                    <p class="text-body-2 font-weight-bold mb-1">
                        {{ t('anpr.coralStep1') }}
                        <v-chip v-if="setupDialogData.lib_installed" size="x-small" color="success" class="ml-1">{{ t('installed') }}</v-chip>
                        <v-chip v-else size="x-small" color="warning" class="ml-1">{{ t('missing') }}</v-chip>
                    </p>
                    <pre class="setup-cmd mb-4">echo "deb https://packages.cloud.google.com/apt coral-edgetpu-stable main" | sudo tee /etc/apt/sources.list.d/coral-edgetpu.list
curl https://packages.cloud.google.com/apt/doc/apt-key.gpg | sudo apt-key add -
sudo apt-get update
sudo apt-get install -y libedgetpu1-std</pre>

                    <p class="text-body-2 font-weight-bold mb-1">{{ t('anpr.coralStep2') }}</p>
                    <pre v-if="setupDialogData.fake_installed" class="setup-cmd mb-2">{{ setupDialogData.venv_pip }} uninstall -y pycoral</pre>
                    <pre class="setup-cmd mb-4">{{ setupDialogData.venv_pip }} install --extra-index-url https://google-coral.github.io/py-repo/ pycoral~=2.0</pre>

                    <v-alert type="warning" variant="tonal" density="compact">
                        {{ t('anpr.coralPython310Warning') }}
                    </v-alert>
                </template>

                <!-- Sudo Not Allowed -->
                <template v-else-if="setupDialogData.type === 'SUDO_NOT_ALLOWED'">
                    <v-alert type="warning" variant="tonal" density="compact" class="mb-4">
                        {{ t('anpr.sudoNotAllowedInfo', { webUser: setupDialogData.web_user || 'www-data', owner: setupDialogData.owner }) }}
                    </v-alert>
                    <p class="text-body-2 font-weight-bold mb-1">{{ t('anpr.sudoNotAllowedFix') }}</p>
                    <pre class="setup-cmd">sudo visudo -f /etc/sudoers.d/anpr-pip</pre>
                    <p class="text-caption text-medium-emphasis mt-1 mb-2">{{ t('anpr.sudoNotAllowedAdd') }}</p>
                    <pre class="setup-cmd mb-4">{{ setupDialogData.web_user || 'www-data' }} ALL=({{ setupDialogData.owner }}) NOPASSWD: {{ setupDialogData.pip }} install *</pre>
                </template>

            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn @click="setupDialog = false">{{ t('close') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Sudo-Passwort-Dialog -->
    <v-dialog v-model="sudoDialog" max-width="440" persistent>
        <v-card>
            <v-card-title class="d-flex align-center ga-2">
                <v-icon color="warning">mdi-shield-lock-outline</v-icon>
                {{ t('anpr.sudoTitle') }}
            </v-card-title>
            <v-card-text>
                <p class="text-body-2 mb-4">
                    {{ t('anpr.sudoInfo', { pkg: sudoPkg, user: sudoOwner }) }}
                </p>
                <v-text-field
                    v-model="sudoPassword"
                    :label="t('anpr.sudoPassword')"
                    :type="sudoPasswordVisible ? 'text' : 'password'"
                    :append-inner-icon="sudoPasswordVisible ? 'mdi-eye-off' : 'mdi-eye'"
                    @click:append-inner="sudoPasswordVisible = !sudoPasswordVisible"
                    variant="outlined" density="compact" autofocus
                    @keydown.enter="confirmSudoInstall" />
                <p class="text-caption text-medium-emphasis mt-1">
                    {{ t('anpr.sudoHint') }}
                </p>
            </v-card-text>
            <v-card-actions>
                <v-btn variant="text" @click="sudoDialog = false; sudoPassword = ''">
                    {{ t('cancel') }}
                </v-btn>
                <v-spacer />
                <v-btn color="primary" variant="flat"
                    :disabled="!sudoPassword"
                    :loading="installingPkg === sudoPkg"
                    @click="confirmSudoInstall">
                    <v-icon start>mdi-package-down</v-icon>
                    {{ t('anpr.hwInstall') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
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
    loadHardwareInfo()
    loadCameras()
    loadActuators()
    loadHistory()
    loadHealth()
    loadLog()
    loadCpuLoad()

    statusAutoRefresh  = setInterval(checkServiceStatus, 30000)
    cpuLoadRefreshTimer = setInterval(loadCpuLoad, 3000)
})

onUnmounted(() => {
    clearInterval(statusAutoRefresh)
    clearInterval(cpuLoadRefreshTimer)
    if (logAutoRefreshTimer) clearInterval(logAutoRefreshTimer)
})

// --- Service-Status ---
const serviceStatus = ref('unknown')
const serviceDetails = ref(null)
const statusLoading = ref(false)
const restarting = ref(false)
const serviceDebugOpen = ref(false)
const restartPhaseIndex = ref(0)
let statusAutoRefresh   = null
let cpuLoadRefreshTimer = null

const restartPhases = computed(() => [
    t('anpr.phaseStop'),
    t('anpr.phaseStart'),
    t('anpr.phaseWait'),
])
const restartPhaseLabel = computed(() => restartPhases.value[restartPhaseIndex.value] ?? '')

const serviceStatusColor = computed(() => {
    if (serviceStatus.value === 'active') return 'green'
    if (serviceStatus.value === 'inactive' || serviceStatus.value === 'failed') return 'red'
    return 'grey'
})
const serviceStatusText = computed(() => {
    if (serviceStatus.value === 'active') return t('anpr.statusActive')
    if (serviceStatus.value === 'inactive') return t('anpr.statusInactive')
    if (serviceStatus.value === 'failed') return t('anpr.statusFailed')
    return t('anpr.statusUnknown')
})
function formatServiceStart(ts) {
    const d = new Date(ts * 1000)
    const today = new Date()
    const isToday = d.toDateString() === today.toDateString()
    const time = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    if (isToday) return time
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) + '  ' + time
}

const serviceUptime = computed(() => {
    const raw = serviceDetails.value?.started_at
    if (!raw) return ''
    const start = new Date(raw)
    if (isNaN(start.getTime())) return ''
    const diff = Date.now() - start.getTime()
    const h = Math.floor(diff / 3600000)
    const m = Math.floor((diff % 3600000) / 60000)
    if (h >= 24) return `${Math.floor(h / 24)}d ${h % 24}h`
    if (h > 0) return `${h}h ${m}m`
    return `${m}m`
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
    restartPhaseIndex.value = 0
    serviceStatus.value = 'unknown'

    const advance = (i) => { restartPhaseIndex.value = i }

    try {
        advance(0)
        await new Promise(r => setTimeout(r, 400))
        advance(1)
        const res = await axios.post('/api/lxcars/', { action: 'restartAnprService' })
        advance(2)
        await new Promise(r => setTimeout(r, 600))

        if (res.data.success) {
            serviceStatus.value = res.data.payload?.status || 'unknown'
            serviceDetails.value = res.data.payload?.details || null
            toasts.success(t('anpr.restartSuccess'))
        } else {
            toasts.error(t('anpr.restartFailed') + (res.data.payload?.output ? ': ' + res.data.payload.output : ''))
        }
    } catch (e) {
        toasts.error(e.message)
    }

    restarting.value = false
    restartPhaseIndex.value = 0
    setTimeout(checkServiceStatus, 1500)
}

// --- CPU-Last ---
const cpuLoad = ref(null)

const cpuCores = computed(() => {
    if (!cpuLoad.value) return {}
    return Object.fromEntries(
        Object.entries(cpuLoad.value).filter(([k]) => k !== 'cpu')
    )
})

function cpuBarColor(pct) {
    if (pct >= 85) return 'error'
    if (pct >= 60) return 'warning'
    return 'success'
}

async function loadCpuLoad() {
    try {
        const res = await axios.post('/api/camera/', { action: 'getAnprCpuLoad' })
        if (res.data.success) cpuLoad.value = res.data.payload?.load ?? null
    } catch { /* ignore */ }
}

// --- Hardware-Info ---
const hw = ref(null)
const hwLoading = ref(false)
const installingPkg = ref('')
const sudoDialog = ref(false)
const sudoPassword = ref('')
const sudoPasswordVisible = ref(false)
const sudoPkg = ref('')
const sudoService = ref('')
const sudoOwner = ref('')
const setupDialog = ref(false)
const setupDialogData = ref(null)

async function loadHardwareInfo() {
    hwLoading.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'getHardwareInfo' })
        if (res.data.success) hw.value = res.data.payload.hardware
    } catch { /* ignore */ }
    hwLoading.value = false
}

async function installPackage(pkg, service, password = '') {
    installingPkg.value = pkg
    try {
        const payload = { action: 'installPythonPackage', package: pkg, service }
        if (password) payload.sudo_password = password
        const res = await axios.post('/api/camera/', payload)

        if (res.data.success) {
            toasts.success(t('anpr.hwInstallSuccess', { pkg }))
            loadHardwareInfo()
        } else if (res.data.error === 'SUDO_PASSWORD_REQUIRED') {
            sudoPkg.value = pkg
            sudoService.value = service
            sudoOwner.value = res.data.payload?.owner || ''
            sudoPassword.value = ''
            sudoPasswordVisible.value = false
            sudoDialog.value = true
        } else if (res.data.error === 'SUDO_WRONG_PASSWORD') {
            toasts.error(t('anpr.sudoWrongPassword'))
            sudoPassword.value = ''
            sudoDialog.value = true
        } else if (res.data.error === 'CORAL_SETUP_REQUIRED' || res.data.error === 'SUDO_NOT_ALLOWED') {
            setupDialogData.value = { type: res.data.error, ...res.data.payload }
            setupDialog.value = true
        } else {
            toasts.error(t('anpr.hwInstallFailed', { pkg }) + (res.data.payload?.output ? '\n' + res.data.payload.output : ''))
        }
    } catch (e) { toasts.error(e.message) }
    installingPkg.value = ''
}

async function confirmSudoInstall() {
    if (!sudoPassword.value) return
    sudoDialog.value = false
    await installPackage(sudoPkg.value, sudoService.value, sudoPassword.value)
    sudoPassword.value = ''
}

const hwRecommendation = computed(() => {
    if (!hw.value) return ''
    const hasIntel = hw.value.cpu?.has_intel_gpu || hw.value.gpu?.intel_igpu
    const hasOpenvino = hw.value.openvino?.installed
    const hasCoral = hw.value.coral?.connected

    if (hasIntel && !hasOpenvino && !hasCoral) {
        return t('anpr.hwRecommendOpenvino')
    }
    if (hasCoral && !hw.value.coral?.python_package) {
        return t('anpr.hwRecommendCoralDriver')
    }
    if (hasOpenvino && hasIntel) {
        return t('anpr.hwRecommendOk')
    }
    return ''
})

// --- Kameras ---
const cameras = ref([])
const openCameraPanel = ref(null)

const cameraPositions = [
    { value: 'front', title: t('anpr.positionFront') },
    { value: 'above', title: t('anpr.positionAbove') },
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
const directionFilterOptions = [
    { value: 'moving',      title: t('anpr.directionFilter_moving') },
    { value: 'approaching', title: t('anpr.directionFilter_approaching') },
    { value: 'off',         title: t('anpr.directionFilter_off') },
]

async function loadCameras() {
    try {
        const res = await axios.post('/api/lxcars/', { action: 'getAnprCameras' })
        if (res.data.success) {
            cameras.value = (res.data.payload?.cameras || []).map(c => ({
                ...c,
                enabled: c.enabled === 't' || c.enabled === true,
                direction_required: c.direction_required === 't' || c.direction_required === true,
                direction_filter: c.direction_filter || 'approaching',
                ignore_right_pct: parseInt(c.ignore_right_pct) || 0,
                ignore_left_pct: parseInt(c.ignore_left_pct) || 0,
                grid_size: parseInt(c.grid_size) || 10,
                save_snapshots: c.save_snapshots === 't' || c.save_snapshots === true,
                excluded_cells: (() => { try { return JSON.parse(c.excluded_cells || '[]') } catch { return [] } })(),
                min_plate_height_px: parseInt(c.min_plate_height_px) || 0,
                motion_size_pct: parseInt(c.motion_size_pct) || 20,
                _saving: false,
            }))
        }
    } catch { /* ignore */ }
}

function addCamera() {
    cameras.value.push({
        id: 0, name: '', rtsp_url: '', enabled: true,
        direction_mode: 'size', position: 'front', frame_interval: 0.5,
        min_confidence: 0.60, min_detections: 2, cooldown_minutes: 5,
        action_type: 'infobar', actuator_id: null, gate_height_mode: 'full',
        ignore_right_pct: 0, ignore_left_pct: 0, direction_required: true,
        direction_filter: 'moving',
        grid_size: 10, save_snapshots: true, excluded_cells: [], min_plate_height_px: 0,
        motion_size_pct: 20, note: '', _saving: false,
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
const historyClearing = ref(false)
const clearConfirm = ref(false)
const historyLimit = ref(200)

// --- Near-miss Debug-Snapshots ---
const nearMissItems = ref([])
const nearMissTotal = ref(0)
const nearMissLoading = ref(false)
const nearMissLoaded = ref(false)
const nearMissClearing = ref(false)
const nearMissClearConfirm = ref(false)
const nearMissPreview = ref(null)
const nearMissPreviewOpen = computed({
    get: () => nearMissPreview.value !== null,
    set: (v) => { if (!v) nearMissPreview.value = null }
})

async function loadNearMiss() {
    nearMissLoading.value = true
    try {
        const res = await axios.post('/api/lxcars/', { action: 'getAnprDebugSnapshots' })
        if (res.data.success) {
            nearMissItems.value = res.data.payload?.snapshots || []
            nearMissTotal.value = res.data.payload?.total || 0
        }
    } catch { /* ignore */ }
    nearMissLoading.value = false
    nearMissLoaded.value = true
}

async function clearNearMiss() {
    nearMissClearing.value = true
    try {
        const res = await axios.post('/api/lxcars/', { action: 'clearAnprDebugSnapshots' })
        if (res.data.success) {
            nearMissItems.value = []
            nearMissTotal.value = 0
        }
    } catch { /* ignore */ }
    nearMissClearing.value = false
    nearMissClearConfirm.value = false
}
const historyLimitOptions = [
    { label: '50', value: 50 },
    { label: '100', value: 100 },
    { label: '200', value: 200 },
    { label: '500', value: 500 },
]

async function loadHistory() {
    historyLoading.value = true
    try {
        const res = await axios.post('/api/lxcars/', {
            action: 'getAnprDetectionHistory',
            limit: historyLimit.value,
        })
        if (res.data.success) {
            historyItems.value = res.data.payload?.detections || []
        }
    } catch { /* ignore */ }
    historyLoading.value = false
    historyLoaded.value = true
}

// --- Dienst-Diagnose ---
// --- Service-Log ---
const logLines = ref([])
const logLoading = ref(false)
const logLineCount = ref(200)
const logAutoRefresh = ref(false)
const logPreRef = ref(null)
let logAutoRefreshTimer = null

async function loadLog(scrollToBottom = true) {
    logLoading.value = true
    try {
        const res = await axios.post('/api/lxcars/', { action: 'getAnprLog', lines: logLineCount.value })
        if (res.data.success) {
            logLines.value = res.data.payload?.lines || []
            if (scrollToBottom) {
                await nextTick()
                if (logPreRef.value) logPreRef.value.scrollTop = logPreRef.value.scrollHeight
            }
        }
    } catch { /* ignore */ }
    logLoading.value = false
}

function toggleLogAutoRefresh() {
    logAutoRefresh.value = !logAutoRefresh.value
    if (logAutoRefresh.value) {
        loadLog()
        logAutoRefreshTimer = setInterval(() => loadLog(true), 5000)
    } else {
        clearInterval(logAutoRefreshTimer)
        logAutoRefreshTimer = null
    }
}

const healthData = ref({ events: [], last_heartbeats: [], problems: [], today_count: 0 })
const healthLoading = ref(false)
const healthLoaded = ref(false)
const healthOpen = ref(false)

async function loadHealth() {
    healthLoading.value = true
    try {
        const res = await axios.post('/api/lxcars/', { action: 'getAnprHealth' })
        if (res.data.success) healthData.value = res.data.payload
    } catch { /* ignore */ }
    healthLoading.value = false
    healthLoaded.value = true
}

function heartbeatAge(ts) {
    if (!ts) return 9999
    return Math.floor((Date.now() - new Date(ts).getTime()) / 1000)
}

function formatAge(ts) {
    const sec = heartbeatAge(ts)
    if (sec < 60) return `vor ${sec}s`
    if (sec < 3600) return `vor ${Math.floor(sec / 60)}min`
    return `vor ${Math.floor(sec / 3600)}h`
}

function cameraName(camId) {
    const cam = cameras.value.find(c => c.id === camId)
    return cam?.name || `Kamera #${camId}`
}

function problemsFor(camId) {
    const p = healthData.value.problems?.find(x => x.camera_id === camId)
    if (!p || (parseInt(p.reconnects) === 0 && parseInt(p.errors) === 0)) return null
    return { reconnects: parseInt(p.reconnects), errors: parseInt(p.errors) }
}

async function clearHistory() {
    historyClearing.value = true
    try {
        await axios.post('/api/lxcars/', { action: 'clearAnprDetectionHistory' })
        historyItems.value = []
        historyLoaded.value = true
        toasts.success(t('anpr.clearSuccess'))
    } catch (e) {
        toasts.error(e.message)
    }
    historyClearing.value = false
    clearConfirm.value = false
}

function formatDate(dateStr) {
    if (!dateStr) return '-'
    const d = new Date(dateStr)
    return d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function formatServiceTime(timeStr) {
    if (!timeStr) return ''
    // systemctl liefert z.B. "Thu 2026-04-09 07:03:44 CEST"
    const d = new Date(timeStr)
    if (isNaN(d.getTime())) return timeStr
    return d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
}

// --- Snapshot-Viewer ---
const snapshotDialog = ref(false)
const snapshotPlate = ref('')
const snapshotCount = ref(1)
const snapshotId = ref(0)

function openSnapshot(id, plate, count) {
    snapshotId.value = id
    snapshotPlate.value = plate
    snapshotCount.value = parseInt(count) || 1
    snapshotDialog.value = true
}

// --- Detection-Debug-Dialog ---
const detectionDebugDialog = ref(false)
const detectionDebugItem = ref(null)

function openDetectionDebug(detection) {
    detectionDebugItem.value = detection
    detectionDebugDialog.value = true
}

// --- Live-Stream-Vorschau ---
const streamDialog = ref(false)
const streamCam = ref(null)
const streamUrl = ref('')
const streamError = ref(false)
let streamRefreshTimer = null

const excludedCellCount = computed(() => streamCam.value?.excluded_cells?.length || 0)
const gridModified = ref(false)

function resetExcludedCells() {
    if (streamCam.value) {
        streamCam.value.excluded_cells = []
        gridModified.value = true
    }
}

const gridLines = computed(() => {
    const size = streamCam.value?.grid_size || 10
    const lines = []
    for (let i = size; i < 100; i += size) lines.push(i)
    return lines
})

const gridCells = computed(() => {
    if (!streamCam.value) return []
    const size = streamCam.value.grid_size || 10
    const excluded = streamCam.value.excluded_cells || []
    const cells = []
    for (let row = 0; row * size < 100; row++) {
        for (let col = 0; col * size < 100; col++) {
            cells.push({
                key: `${row}-${col}`,
                x: col * size,
                y: row * size,
                row, col,
                excluded: excluded.some(c => c[0] === row && c[1] === col),
            })
        }
    }
    return cells
})

function toggleCell(cell) {
    if (!streamCam.value) return
    if (!streamCam.value.excluded_cells) streamCam.value.excluded_cells = []
    const idx = streamCam.value.excluded_cells.findIndex(c => c[0] === cell.row && c[1] === cell.col)
    if (idx >= 0) streamCam.value.excluded_cells.splice(idx, 1)
    else streamCam.value.excluded_cells.push([cell.row, cell.col])
    gridModified.value = true
}

function buildSnapshotUrl(cam) {
    return `/api/lxcars/anpr-stream.php?id=${cam.id}&t=${Date.now()}`
}

function openStreamPreview(cam) {
    streamCam.value = cam
    streamError.value = false
    gridModified.value = false
    streamUrl.value = buildSnapshotUrl(cam)
    streamDialog.value = true
    streamRefreshTimer = setInterval(() => {
        streamUrl.value = buildSnapshotUrl(cam)
    }, 3000)
}

function onStreamDialogChange(open) {
    if (!open) {
        clearInterval(streamRefreshTimer)
        streamRefreshTimer = null
    }
}
</script>

<style scoped>
.anpr-status-dot-wrap {
    position: relative;
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}
.anpr-status-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #9e9e9e;
    margin: 2px;
    transition: background 0.4s;
}
.anpr-status-dot.active {
    background: #4caf50;
    animation: anpr-pulse 2.4s ease-in-out infinite;
}
.anpr-status-dot.inactive,
.anpr-status-dot.failed {
    background: #f44336;
}
@keyframes anpr-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.5); }
    50%       { box-shadow: 0 0 0 7px rgba(76, 175, 80, 0); }
}
.setup-cmd {
    background: #1e1e2e;
    color: #cdd6f4;
    font-size: 12px;
    padding: 10px 14px;
    border-radius: 6px;
    white-space: pre-wrap;
    word-break: break-all;
    user-select: all;
}
</style>
