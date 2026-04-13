<template>
    <NavbarView />
    <v-container fluid class="pa-4">
        <!-- Header -->
        <v-row class="mb-4" align="center">
            <v-col>
                <h1 class="text-h5">{{ t('CameraView.title') }}</h1>
            </v-col>
            <v-col cols="auto">
                <v-btn-toggle v-model="viewMode" mandatory density="compact" variant="outlined">
                    <v-btn value="live" :prepend-icon="'mdi-cctv'">{{ t('CameraView.live') }}</v-btn>
                    <v-btn value="events" :prepend-icon="'mdi-bell-outline'">
                        {{ t('CameraView.events') }}
                        <v-badge v-if="unreadCount > 0" :content="unreadCount" color="error" floating />
                    </v-btn>
                    <v-btn value="rules" :prepend-icon="'mdi-shield-check-outline'">{{ t('CameraView.rules') }}</v-btn>
                    <v-btn value="settings" :prepend-icon="'mdi-cog-outline'">{{ t('CameraView.settings') }}</v-btn>
                </v-btn-toggle>
            </v-col>
        </v-row>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- LIVE-ANSICHT -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <template v-if="viewMode === 'live'">
            <v-row v-if="cameras.length === 0">
                <v-col>
                    <v-alert type="info" variant="tonal" class="mb-4">
                        {{ t('CameraView.noCameras') }}
                    </v-alert>
                    <v-btn color="primary" @click="autoDiscover" :loading="discovering" prepend-icon="mdi-radar">
                        {{ t('CameraView.autoDiscover') }}
                    </v-btn>
                </v-col>
            </v-row>
            <v-row v-else>
                <v-col
                    v-for="cam in cameras" :key="cam.id"
                    cols="12" md="6" lg="4"
                >
                    <v-card elevation="2">
                        <v-card-title class="d-flex align-center">
                            <v-icon class="mr-2" color="success" size="small">mdi-circle</v-icon>
                            {{ cam.name }}
                            <v-spacer />
                            <v-badge
                                v-if="cam.unread_events > 0"
                                :content="cam.unread_events"
                                color="error"
                                inline
                            />
                        </v-card-title>
                        <v-card-subtitle v-if="cam.location">{{ cam.location }}</v-card-subtitle>

                        <!-- Stream: iframe fuer WebRTC/MJPEG, Snapshot-Refresh als Fallback -->
                        <div class="stream-container" v-if="cam.stream_url && isWebUrl(cam.stream_url)">
                            <iframe
                                :src="cam.stream_url"
                                class="stream-iframe"
                                allow="autoplay"
                                loading="lazy"
                            />
                        </div>
                        <div class="stream-container" v-else-if="cam.stream_url">
                            <!-- RTSP-URL: Snapshot-Proxy mit Auto-Refresh -->
                            <img
                                :src="snapshotUrl(cam)"
                                class="stream-iframe"
                                :alt="cam.name"
                                @error="$event.target.style.opacity = 0.3"
                            />
                            <v-btn
                                icon="mdi-refresh" size="x-small"
                                variant="flat" color="black"
                                class="stream-refresh-btn"
                                @click="refreshSnapshot(cam)"
                            />
                        </div>
                        <v-card-text v-else class="text-center py-8 text-medium-emphasis">
                            <v-icon size="48" class="mb-2">mdi-video-off-outline</v-icon>
                            <div>{{ t('CameraView.noStream') }}</div>
                            <div class="text-caption">{{ cam.frigate_name }}</div>
                        </v-card-text>

                        <!-- Zonen-Chips -->
                        <v-card-text v-if="cam.zones?.length" class="pt-0">
                            <v-chip
                                v-for="zone in cam.zones" :key="zone.id"
                                size="small" class="mr-1 mb-1"
                                :color="zone.color"
                                variant="tonal"
                            >
                                {{ zone.name }}
                            </v-chip>
                        </v-card-text>

                        <v-card-actions>
                            <v-btn
                                size="small" variant="text"
                                @click="showEventsForCamera(cam)"
                            >
                                {{ t('CameraView.showEvents') }}
                            </v-btn>
                            <v-spacer />
                            <v-btn
                                size="small" variant="text" color="primary"
                                @click="acknowledgeAllForCamera(cam)"
                                :disabled="cam.unread_events === 0"
                            >
                                {{ t('CameraView.markAllRead') }}
                            </v-btn>
                        </v-card-actions>
                    </v-card>
                </v-col>
            </v-row>
        </template>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- EVENT-TIMELINE -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <template v-if="viewMode === 'events'">
            <!-- Filter -->
            <v-row class="mb-2" dense>
                <v-col cols="12" sm="3">
                    <v-select
                        v-model="eventFilter.camera_id"
                        :items="cameraOptions"
                        :label="t('CameraView.filterCamera')"
                        clearable density="compact" variant="outlined"
                    />
                </v-col>
                <v-col cols="12" sm="2">
                    <v-select
                        v-model="eventFilter.label"
                        :items="labelOptions"
                        :label="t('CameraView.filterLabel')"
                        clearable density="compact" variant="outlined"
                    />
                </v-col>
                <v-col cols="12" sm="2">
                    <v-select
                        v-model="eventFilter.acknowledged"
                        :items="ackOptions"
                        :label="t('CameraView.filterStatus')"
                        density="compact" variant="outlined"
                    />
                </v-col>
                <v-col cols="12" sm="2">
                    <v-text-field
                        v-model="eventFilter.date_from"
                        :label="t('CameraView.dateFrom')"
                        type="date" density="compact" variant="outlined"
                    />
                </v-col>
                <v-col cols="12" sm="2">
                    <v-text-field
                        v-model="eventFilter.date_to"
                        :label="t('CameraView.dateTo')"
                        type="date" density="compact" variant="outlined"
                    />
                </v-col>
                <v-col cols="auto" class="d-flex align-center">
                    <v-btn icon="mdi-refresh" variant="text" @click="loadEvents" />
                </v-col>
            </v-row>

            <!-- Event-Liste -->
            <v-card>
                <v-data-table
                    :headers="eventHeaders"
                    :items="events"
                    :loading="loadingEvents"
                    :items-per-page="50"
                    density="compact"
                    class="elevation-0"
                    @update:options="onEventPagination"
                >
                    <template #item.snapshot_url="{ item }">
                        <v-img
                            v-if="item.snapshot_url"
                            :src="proxySnapshotUrl(item.snapshot_url)"
                            width="80" height="45"
                            cover class="rounded cursor-pointer"
                            @click="previewSnapshot = item.snapshot_url"
                        />
                        <v-icon v-else size="small" color="grey">mdi-image-off-outline</v-icon>
                    </template>
                    <template #item.label="{ item }">
                        <v-chip size="small" :color="labelColor(item.label)" variant="tonal">
                            {{ t('CameraView.labels.' + item.label, item.label) }}
                        </v-chip>
                    </template>
                    <template #item.zones="{ item }">
                        <v-chip
                            v-for="z in item.zones" :key="z"
                            size="x-small" class="mr-1"
                            variant="outlined"
                        >{{ z }}</v-chip>
                    </template>
                    <template #item.started_at="{ item }">
                        {{ formatDateTime(item.started_at) }}
                    </template>
                    <template #item.score="{ item }">
                        {{ item.score ? Math.round(item.score * 100) + '%' : '' }}
                    </template>
                    <template #item.acknowledged="{ item }">
                        <v-icon v-if="item.acknowledged" color="success" size="small">mdi-check-circle</v-icon>
                        <v-btn
                            v-else icon="mdi-check" size="x-small" variant="text"
                            color="primary" @click="acknowledgeEvent(item)"
                        />
                    </template>
                    <template #item.actions="{ item }">
                        <v-btn
                            v-if="item.clip_url"
                            icon="mdi-play-circle-outline" size="x-small"
                            variant="text" @click="openClip(item)"
                        />
                    </template>
                </v-data-table>
            </v-card>
        </template>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- REGELN -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <template v-if="viewMode === 'rules'">
            <v-row class="mb-2">
                <v-col>
                    <v-btn color="primary" @click="editRule(null)">
                        <v-icon start>mdi-plus</v-icon>
                        {{ t('CameraView.newRule') }}
                    </v-btn>
                </v-col>
            </v-row>

            <v-card>
                <v-list>
                    <v-list-item
                        v-for="rule in rules" :key="rule.id"
                        @click="editRule(rule)"
                    >
                        <template #prepend>
                            <v-icon :color="rule.active ? 'success' : 'grey'">
                                {{ rule.active ? 'mdi-shield-check' : 'mdi-shield-off-outline' }}
                            </v-icon>
                        </template>
                        <v-list-item-title>{{ rule.name }}</v-list-item-title>
                        <v-list-item-subtitle>
                            {{ rule.camera_name || t('CameraView.allCameras') }}
                            <template v-if="rule.zone_name"> / {{ rule.zone_name }}</template>
                            &mdash;
                            {{ rule.labels?.join(', ') }}
                            <template v-if="rule.time_from && rule.time_to">
                                ({{ rule.time_from }}–{{ rule.time_to }})
                            </template>
                            &rarr; {{ t('CameraView.actions.' + rule.action, rule.action) }}
                        </v-list-item-subtitle>
                        <template #append>
                            <v-btn
                                icon="mdi-delete-outline" size="small" variant="text"
                                color="error" @click.stop="deleteRule(rule)"
                            />
                        </template>
                    </v-list-item>
                    <v-list-item v-if="rules.length === 0">
                        <v-list-item-title class="text-medium-emphasis">
                            {{ t('CameraView.noRules') }}
                        </v-list-item-title>
                    </v-list-item>
                </v-list>
            </v-card>
        </template>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- EINSTELLUNGEN -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <template v-if="viewMode === 'settings'">
            <v-row>
                <!-- Kameras verwalten -->
                <v-col cols="12" md="6">
                    <v-card>
                        <v-card-title class="d-flex align-center">
                            {{ t('CameraView.manageCameras') }}
                            <v-spacer />
                            <v-btn size="small" variant="tonal" color="primary" class="mr-1" @click="autoDiscover" :loading="discovering" prepend-icon="mdi-radar">
                                {{ t('CameraView.autoDiscover') }}
                            </v-btn>
                            <v-btn icon="mdi-plus" size="small" variant="text" @click="editCamera(null)" />
                        </v-card-title>
                        <v-list density="compact">
                            <v-list-item v-for="cam in cameras" :key="cam.id" @click="editCamera(cam)">
                                <v-list-item-title>{{ cam.name }}</v-list-item-title>
                                <v-list-item-subtitle>{{ cam.frigate_name }} — {{ cam.location }}</v-list-item-subtitle>
                                <template #append>
                                    <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error" @click.stop="deleteCamera(cam)" />
                                </template>
                            </v-list-item>
                        </v-list>
                    </v-card>
                </v-col>

                <!-- Frigate-Konfiguration -->
                <v-col cols="12" md="6">
                    <v-card>
                        <v-card-title>{{ t('CameraView.frigateConfig') }}</v-card-title>
                        <v-card-text>
                            <v-text-field
                                v-model="frigateUrl"
                                :label="t('CameraView.frigateUrl')"
                                :hint="t('CameraView.frigateUrlHint')"
                                persistent-hint variant="outlined" density="compact"
                                class="mb-3"
                            />
                            <v-text-field
                                v-model="webhookToken"
                                :label="t('CameraView.webhookToken')"
                                :hint="webhookUrlExample"
                                persistent-hint variant="outlined" density="compact"
                                class="mb-3"
                            >
                                <template #append-inner>
                                    <v-btn icon="mdi-refresh" size="x-small" variant="text" @click="generateToken" :title="t('CameraView.generateToken')" />
                                </template>
                            </v-text-field>
                            <v-btn color="primary" @click="saveFrigateConfig" :loading="savingConfig">
                                {{ t('CameraView.save') }}
                            </v-btn>
                        </v-card-text>
                    </v-card>

                    <!-- Statistiken -->
                    <v-card class="mt-4">
                        <v-card-title>{{ t('CameraView.statistics') }}</v-card-title>
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="6">
                                    <div class="text-h4">{{ stats.camera_count || 0 }}</div>
                                    <div class="text-caption">{{ t('CameraView.statCameras') }}</div>
                                </v-col>
                                <v-col cols="6">
                                    <div class="text-h4">{{ stats.events_today || 0 }}</div>
                                    <div class="text-caption">{{ t('CameraView.statEventsToday') }}</div>
                                </v-col>
                                <v-col cols="6">
                                    <div class="text-h4">{{ stats.unread_events || 0 }}</div>
                                    <div class="text-caption">{{ t('CameraView.statUnread') }}</div>
                                </v-col>
                                <v-col cols="6">
                                    <div class="text-h4">{{ stats.active_rules || 0 }}</div>
                                    <div class="text-caption">{{ t('CameraView.statRules') }}</div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Hardware-Beschleunigung -->
            <v-row class="mt-4">
                <v-col cols="12">
                    <v-card>
                        <v-card-title class="d-flex align-center">
                            <v-icon class="mr-2" size="20">mdi-chip</v-icon>
                            {{ t('CameraView.hwTitle') }}
                            <v-spacer />
                            <v-btn variant="text" size="small" icon="mdi-refresh"
                                @click="loadHardwareInfo" :loading="hwLoading" />
                        </v-card-title>
                        <v-card-text v-if="hw">
                            <v-table density="compact">
                                <tbody>
                                    <!-- CPU -->
                                    <tr>
                                        <td class="font-weight-medium" style="width:180px">
                                            <v-tooltip location="top" max-width="400">
                                                <template #activator="{ props }">
                                                    <span v-bind="props" class="text-decoration-underline-dotted cursor-help">CPU</span>
                                                </template>
                                                {{ t('CameraView.hwCpuTooltip') }}
                                            </v-tooltip>
                                        </td>
                                        <td>{{ hw.cpu?.model }} ({{ hw.cpu?.cores }}C/{{ hw.cpu?.threads }}T)</td>
                                        <td style="width:80px" />
                                    </tr>
                                    <!-- RAM -->
                                    <tr>
                                        <td class="font-weight-medium">RAM</td>
                                        <td>{{ hw.ram?.total_gb }} GB ({{ hw.ram?.available_gb }} GB frei)</td>
                                        <td />
                                    </tr>
                                    <!-- GPU -->
                                    <tr>
                                        <td class="font-weight-medium">
                                            <v-tooltip location="top" max-width="400">
                                                <template #activator="{ props }">
                                                    <span v-bind="props" class="text-decoration-underline-dotted cursor-help">Intel iGPU</span>
                                                </template>
                                                {{ t('CameraView.hwIgpuTooltip') }}
                                            </v-tooltip>
                                        </td>
                                        <td>
                                            <v-icon :color="hw.gpu?.intel_igpu ? 'success' : 'grey'" size="16" class="mr-1">
                                                {{ hw.gpu?.intel_igpu ? 'mdi-check-circle' : 'mdi-minus-circle-outline' }}
                                            </v-icon>
                                            {{ hw.gpu?.intel_igpu ? hw.gpu.device : t('CameraView.hwNotDetected') }}
                                        </td>
                                        <td />
                                    </tr>
                                    <!-- OpenVINO -->
                                    <tr>
                                        <td class="font-weight-medium">
                                            <v-tooltip location="top" max-width="400">
                                                <template #activator="{ props }">
                                                    <span v-bind="props" class="text-decoration-underline-dotted cursor-help">OpenVINO</span>
                                                </template>
                                                {{ t('CameraView.hwOpenvinoTooltip') }}
                                            </v-tooltip>
                                        </td>
                                        <td>
                                            <template v-if="hw.openvino?.installed">
                                                <v-icon color="success" size="16" class="mr-1">mdi-check-circle</v-icon>
                                                v{{ hw.openvino.version }}
                                                <v-chip v-if="hw.openvino.in_camera_venv" size="x-small" color="success" variant="tonal" class="ml-1">NVR</v-chip>
                                            </template>
                                            <template v-else>
                                                <v-icon color="warning" size="16" class="mr-1">mdi-alert-circle-outline</v-icon>
                                                {{ t('CameraView.hwNotInstalled') }}
                                            </template>
                                        </td>
                                        <td>
                                            <v-btn v-if="!hw.openvino?.in_camera_venv" size="x-small" variant="tonal" color="primary"
                                                @click="installHwPackage('openvino', 'camera')" :loading="installingPkg === 'openvino'">
                                                {{ t('CameraView.hwInstall') }}
                                            </v-btn>
                                        </td>
                                    </tr>
                                    <!-- Coral USB -->
                                    <tr>
                                        <td class="font-weight-medium">
                                            <v-tooltip location="top" max-width="400">
                                                <template #activator="{ props }">
                                                    <span v-bind="props" class="text-decoration-underline-dotted cursor-help">Google Coral USB</span>
                                                </template>
                                                {{ t('CameraView.hwCoralTooltip') }}
                                            </v-tooltip>
                                        </td>
                                        <td>
                                            <template v-if="hw.coral?.connected && hw.coral?.python_package">
                                                <v-icon color="success" size="16" class="mr-1">mdi-check-circle</v-icon>
                                                {{ t('CameraView.hwCoralReady') }}
                                            </template>
                                            <template v-else-if="hw.coral?.connected && !hw.coral?.python_package">
                                                <v-icon color="warning" size="16" class="mr-1">mdi-alert-circle-outline</v-icon>
                                                {{ t('CameraView.hwCoralNoDriver') }}
                                            </template>
                                            <template v-else>
                                                <v-icon color="grey" size="16" class="mr-1">mdi-minus-circle-outline</v-icon>
                                                {{ t('CameraView.hwCoralNotConnected') }}
                                            </template>
                                        </td>
                                        <td>
                                            <v-btn v-if="hw.coral?.connected && !hw.coral?.python_package"
                                                size="x-small" variant="tonal" color="primary"
                                                @click="installHwPackage('pycoral', 'camera')" :loading="installingPkg === 'pycoral'">
                                                {{ t('CameraView.hwInstall') }}
                                            </v-btn>
                                        </td>
                                    </tr>
                                    <!-- YOLO -->
                                    <tr>
                                        <td class="font-weight-medium">
                                            <v-tooltip location="top" max-width="400">
                                                <template #activator="{ props }">
                                                    <span v-bind="props" class="text-decoration-underline-dotted cursor-help">YOLO (Objekterkennung)</span>
                                                </template>
                                                {{ t('CameraView.hwYoloTooltip') }}
                                            </v-tooltip>
                                        </td>
                                        <td>
                                            <template v-if="hw.python_packages?.camera?.ultralytics">
                                                <v-icon color="success" size="16" class="mr-1">mdi-check-circle</v-icon>
                                                v{{ hw.python_packages.camera.ultralytics }}
                                            </template>
                                            <template v-else>
                                                <v-icon color="error" size="16" class="mr-1">mdi-close-circle</v-icon>
                                                {{ t('CameraView.hwNotInstalled') }}
                                            </template>
                                        </td>
                                        <td>
                                            <v-btn v-if="!hw.python_packages?.camera?.ultralytics"
                                                size="x-small" variant="tonal" color="primary"
                                                @click="installHwPackage('ultralytics', 'camera')" :loading="installingPkg === 'ultralytics'">
                                                {{ t('CameraView.hwInstall') }}
                                            </v-btn>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>

                            <!-- Erkennungskaskade -->
                            <v-alert type="info" variant="tonal" density="compact" class="mt-3">
                                <div class="font-weight-medium mb-1">{{ t('CameraView.hwCascadeTitle') }}</div>
                                <div>{{ hwActiveBackend }}</div>
                            </v-alert>

                            <!-- Empfehlung -->
                            <v-alert v-if="hwRecommendation" type="warning" variant="tonal" density="compact" class="mt-2">
                                {{ hwRecommendation }}
                            </v-alert>
                        </v-card-text>
                        <v-card-text v-else class="text-center py-4">
                            <v-progress-circular v-if="hwLoading" indeterminate size="24" />
                            <span v-else class="text-medium-emphasis">{{ t('CameraView.hwClickRefresh') }}</span>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </template>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- DIALOGE -->
        <!-- ══════════════════════════════════════════════════════════ -->

        <!-- Kamera-Dialog -->
        <v-dialog v-model="showCameraDialog" max-width="500">
            <v-card>
                <v-card-title>{{ editingCamera?.id ? t('CameraView.editCamera') : t('CameraView.newCamera') }}</v-card-title>
                <v-card-text>
                    <v-text-field v-model="editingCamera.name" :label="t('CameraView.cameraName')" variant="outlined" density="compact" class="mb-2" />
                    <v-text-field v-model="editingCamera.frigate_name" :label="t('CameraView.frigateName')" :hint="t('CameraView.frigateNameHint')" persistent-hint variant="outlined" density="compact" class="mb-2" />
                    <v-text-field v-model="editingCamera.stream_url" :label="t('CameraView.streamUrl')" :hint="t('CameraView.streamUrlHint')" persistent-hint variant="outlined" density="compact" class="mb-2" />
                    <v-text-field v-model="editingCamera.location" :label="t('CameraView.cameraLocation')" variant="outlined" density="compact" class="mb-2" />
                    <v-text-field v-model.number="editingCamera.sort_order" :label="t('CameraView.sortOrder')" type="number" variant="outlined" density="compact" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showCameraDialog = false">{{ t('CameraView.cancel') }}</v-btn>
                    <v-btn color="primary" @click="saveCamera" :loading="savingCamera">{{ t('CameraView.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Regel-Dialog -->
        <v-dialog v-model="showRuleDialog" max-width="600">
            <v-card>
                <v-card-title>{{ editingRule?.id ? t('CameraView.editRule') : t('CameraView.newRule') }}</v-card-title>
                <v-card-text>
                    <v-text-field v-model="editingRule.name" :label="t('CameraView.ruleName')" variant="outlined" density="compact" class="mb-2" />
                    <v-select v-model="editingRule.camera_id" :items="cameraOptions" :label="t('CameraView.filterCamera')" clearable variant="outlined" density="compact" class="mb-2" />
                    <v-select v-model="editingRule.zone_id" :items="zoneOptionsForRule" :label="t('CameraView.ruleZone')" clearable variant="outlined" density="compact" class="mb-2" />
                    <v-select v-model="editingRule.labels" :items="allLabelOptions" :label="t('CameraView.ruleLabels')" multiple chips variant="outlined" density="compact" class="mb-2" />
                    <v-row dense>
                        <v-col cols="6">
                            <v-text-field v-model="editingRule.time_from" :label="t('CameraView.timeFrom')" type="time" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="editingRule.time_to" :label="t('CameraView.timeTo')" type="time" variant="outlined" density="compact" />
                        </v-col>
                    </v-row>
                    <v-select v-model="editingRule.days_of_week" :items="dayOptions" :label="t('CameraView.ruleDays')" multiple chips variant="outlined" density="compact" class="mb-2" />
                    <v-slider v-model="editingRule.min_score" :label="t('CameraView.minScore')" :min="0.1" :max="1" :step="0.05" thumb-label class="mb-2" />
                    <v-select v-model="editingRule.action" :items="actionOptions" :label="t('CameraView.ruleAction')" variant="outlined" density="compact" class="mb-2" />
                    <v-text-field
                        v-if="editingRule.action === 'whatsapp'"
                        v-model="editingRule.action_config.phone"
                        :label="t('CameraView.whatsappPhone')"
                        variant="outlined" density="compact" class="mb-2"
                    />
                    <v-text-field
                        v-if="editingRule.action === 'email'"
                        v-model="editingRule.action_config.email"
                        :label="t('CameraView.emailAddress')"
                        variant="outlined" density="compact" class="mb-2"
                    />
                    <v-text-field v-model="editingRule.action_config.message" :label="t('CameraView.customMessage')" variant="outlined" density="compact" class="mb-2" />
                    <v-text-field v-model.number="editingRule.cooldown_seconds" :label="t('CameraView.cooldown')" type="number" :suffix="t('CameraView.seconds')" variant="outlined" density="compact" class="mb-2" />
                    <v-switch v-model="editingRule.active" :label="t('CameraView.ruleActive')" color="primary" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showRuleDialog = false">{{ t('CameraView.cancel') }}</v-btn>
                    <v-btn color="primary" @click="saveRule" :loading="savingRule">{{ t('CameraView.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snapshot-Vorschau -->
        <v-dialog v-model="showSnapshotPreview" max-width="800">
            <v-card>
                <v-img :src="proxySnapshotUrl(previewSnapshot)" max-height="600" contain />
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showSnapshotPreview = false">{{ t('CameraView.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Clip-Player -->
        <v-dialog v-model="showClipDialog" max-width="800">
            <v-card>
                <video v-if="clipUrl" :src="clipUrl" controls autoplay class="w-100" />
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showClipDialog = false">{{ t('CameraView.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toast from '@/core/utils/toasts.js'

const { t } = useI18n()
const oserp = oserpStore()

// ── State ──
const viewMode = ref('live')
const cameras = ref([])
const events = ref([])
const rules = ref([])
const stats = reactive({})
const loadingEvents = ref(false)
const frigateUrl = ref('')
const webhookToken = ref('')
const savingConfig = ref(false)
const discovering = ref(false)

// Hardware
const hw = ref(null)
const hwLoading = ref(false)
const installingPkg = ref('')

// Kamera-Dialog
const showCameraDialog = ref(false)
const savingCamera = ref(false)
const editingCamera = reactive({
    id: null, name: '', frigate_name: '', stream_url: '', location: '', sort_order: 0
})

// Regel-Dialog
const showRuleDialog = ref(false)
const savingRule = ref(false)
const editingRule = reactive({
    id: null, name: '', camera_id: null, zone_id: null,
    labels: ['person'], time_from: '', time_to: '',
    days_of_week: [0,1,2,3,4,5,6], min_score: 0.7,
    action: 'notify', action_config: {}, active: true, cooldown_seconds: 300
})

// Snapshot/Clip Dialoge
const previewSnapshot = ref('')
const showSnapshotPreview = computed({
    get: () => !!previewSnapshot.value,
    set: (v) => { if (!v) previewSnapshot.value = '' }
})
const clipUrl = ref('')
const showClipDialog = computed({
    get: () => !!clipUrl.value,
    set: (v) => { if (!v) clipUrl.value = '' }
})

// Event-Filter
const eventFilter = reactive({
    camera_id: null, label: null, acknowledged: 'all',
    date_from: '', date_to: '', limit: 50, offset: 0
})

// ── Computed ──
const unreadCount = computed(() =>
    cameras.value.reduce((sum, c) => sum + (c.unread_events || 0), 0)
)

const cameraOptions = computed(() =>
    cameras.value.map(c => ({ title: c.name, value: c.id }))
)

const zoneOptionsForRule = computed(() => {
    if (!editingRule.camera_id) return []
    const cam = cameras.value.find(c => c.id === editingRule.camera_id)
    return (cam?.zones || []).map(z => ({ title: z.name, value: z.id }))
})

const labelOptions = computed(() => [
    { title: t('CameraView.labels.person'), value: 'person' },
    { title: t('CameraView.labels.car'), value: 'car' },
    { title: t('CameraView.labels.dog'), value: 'dog' },
    { title: t('CameraView.labels.cat'), value: 'cat' },
])

const allLabelOptions = computed(() => [
    'person', 'car', 'motorcycle', 'bicycle', 'bus', 'truck',
    'dog', 'cat', 'bird', 'horse'
])

const ackOptions = [
    { title: 'Alle', value: 'all' },
    { title: 'Ungelesen', value: 'false' },
    { title: 'Gelesen', value: 'true' },
]

const dayOptions = [
    { title: t('CameraView.days.sun'), value: 0 },
    { title: t('CameraView.days.mon'), value: 1 },
    { title: t('CameraView.days.tue'), value: 2 },
    { title: t('CameraView.days.wed'), value: 3 },
    { title: t('CameraView.days.thu'), value: 4 },
    { title: t('CameraView.days.fri'), value: 5 },
    { title: t('CameraView.days.sat'), value: 6 },
]

const actionOptions = [
    { title: t('CameraView.actions.notify'), value: 'notify' },
    { title: t('CameraView.actions.whatsapp'), value: 'whatsapp' },
    { title: t('CameraView.actions.email'), value: 'email' },
    { title: t('CameraView.actions.log'), value: 'log' },
]

const eventHeaders = [
    { title: '', key: 'snapshot_url', sortable: false, width: 90 },
    { title: t('CameraView.headerCamera'), key: 'camera_display_name' },
    { title: t('CameraView.headerLabel'), key: 'label' },
    { title: t('CameraView.headerZones'), key: 'zones', sortable: false },
    { title: t('CameraView.headerTime'), key: 'started_at' },
    { title: t('CameraView.headerScore'), key: 'score' },
    { title: '', key: 'acknowledged', sortable: false, width: 50 },
    { title: '', key: 'actions', sortable: false, width: 50 },
]

// ── API-Aufrufe ──

async function loadCameras() {
    try {
        const res = await axios.post('/api/camera/', { action: 'getCameras' })
        if (res.data.success) cameras.value = res.data.payload.cameras
    } catch (e) { console.error('getCameras:', e) }
}

async function loadEvents() {
    loadingEvents.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'getEvents', ...eventFilter })
        if (res.data.success) events.value = res.data.payload.events
    } catch (e) { console.error('getEvents:', e) }
    loadingEvents.value = false
}

async function loadRules() {
    try {
        const res = await axios.post('/api/camera/', { action: 'getRules' })
        if (res.data.success) rules.value = res.data.payload.rules
    } catch (e) { console.error('getRules:', e) }
}

async function loadStats() {
    try {
        const res = await axios.post('/api/camera/', { action: 'getCameraStats' })
        if (res.data.success) Object.assign(stats, res.data.payload.stats)
    } catch (e) { console.error('getCameraStats:', e) }
}

async function loadConfig() {
    try {
        const res = await axios.post('/api/camera/', { action: 'getCameraConfig' })
        if (res.data.success) {
            frigateUrl.value = res.data.payload.config?.camera_frigate_url || ''
            webhookToken.value = res.data.payload.config?.camera_webhook_token || ''
        }
    } catch (e) { console.error('getCameraConfig:', e) }
}

const webhookUrlExample = computed(() => {
    const base = window.location.origin
    const client = oserp.session.client
    const token = webhookToken.value || 'DEIN_TOKEN'
    return `Webhook: ${base}/api/camera/webhook.php?token=${token}&client=${client}`
})

// ── Kamera CRUD ──

function editCamera(cam) {
    if (cam) {
        Object.assign(editingCamera, cam)
    } else {
        Object.assign(editingCamera, { id: null, name: '', frigate_name: '', stream_url: '', location: '', sort_order: 0 })
    }
    showCameraDialog.value = true
}

async function saveCamera() {
    savingCamera.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'saveCamera', ...editingCamera })
        if (res.data.success) {
            showCameraDialog.value = false
            toast.success(t('CameraView.cameraSaved'))
            loadCameras()
        } else {
            toast.error(res.data.text)
        }
    } catch (e) { toast.error(e.message) }
    savingCamera.value = false
}

async function deleteCamera(cam) {
    if (!confirm(t('CameraView.confirmDeleteCamera', { name: cam.name }))) return
    try {
        await axios.post('/api/camera/', { action: 'deleteCamera', id: cam.id })
        toast.success(t('CameraView.cameraDeleted'))
        loadCameras()
    } catch (e) { toast.error(e.message) }
}

// ── Events ──

async function acknowledgeEvent(event) {
    try {
        await axios.post('/api/camera/', { action: 'acknowledgeEvent', id: event.id })
        event.acknowledged = true
        // Kamera-Badge aktualisieren
        const cam = cameras.value.find(c => c.id === event.camera_id)
        if (cam && cam.unread_events > 0) cam.unread_events--
    } catch (e) { toast.error(e.message) }
}

async function acknowledgeAllForCamera(cam) {
    try {
        await axios.post('/api/camera/', { action: 'acknowledgeAllEvents', camera_id: cam.id })
        cam.unread_events = 0
        toast.success(t('CameraView.allMarkedRead'))
        if (viewMode.value === 'events') loadEvents()
    } catch (e) { toast.error(e.message) }
}

function showEventsForCamera(cam) {
    eventFilter.camera_id = cam.id
    viewMode.value = 'events'
    loadEvents()
}

function onEventPagination({ page, itemsPerPage }) {
    eventFilter.offset = (page - 1) * itemsPerPage
    eventFilter.limit = itemsPerPage
    loadEvents()
}

// ── Regeln CRUD ──

function editRule(rule) {
    if (rule) {
        Object.assign(editingRule, {
            ...rule,
            action_config: { ...(rule.action_config || {}) }
        })
    } else {
        Object.assign(editingRule, {
            id: null, name: '', camera_id: null, zone_id: null,
            labels: ['person'], time_from: '', time_to: '',
            days_of_week: [0,1,2,3,4,5,6], min_score: 0.7,
            action: 'notify', action_config: {}, active: true, cooldown_seconds: 300
        })
    }
    showRuleDialog.value = true
}

async function saveRule() {
    savingRule.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'saveRule', ...editingRule })
        if (res.data.success) {
            showRuleDialog.value = false
            toast.success(t('CameraView.ruleSaved'))
            loadRules()
        } else {
            toast.error(res.data.text)
        }
    } catch (e) { toast.error(e.message) }
    savingRule.value = false
}

async function deleteRule(rule) {
    if (!confirm(t('CameraView.confirmDeleteRule', { name: rule.name }))) return
    try {
        await axios.post('/api/camera/', { action: 'deleteRule', id: rule.id })
        toast.success(t('CameraView.ruleDeleted'))
        loadRules()
    } catch (e) { toast.error(e.message) }
}

// ── Frigate-Config ──

async function saveFrigateConfig() {
    savingConfig.value = true
    try {
        await axios.post('/api/oserp_config/', {
            action: 'saveDefaultOserp',
            key: 'camera_frigate_url',
            value: frigateUrl.value
        })
        if (webhookToken.value) {
            await axios.post('/api/oserp_config/', {
                action: 'saveDefaultOserp',
                key: 'camera_webhook_token',
                value: webhookToken.value
            })
        }
        toast.success(t('CameraView.configSaved'))
    } catch (e) { toast.error(e.message) }
    savingConfig.value = false
}

// ── Hardware-Info ──

async function loadHardwareInfo() {
    hwLoading.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'getHardwareInfo' })
        if (res.data.success) hw.value = res.data.payload.hardware
    } catch { /* ignore */ }
    hwLoading.value = false
}

async function installHwPackage(pkg, service) {
    installingPkg.value = pkg
    try {
        const res = await axios.post('/api/camera/', { action: 'installPythonPackage', package: pkg, service })
        if (res.data.success) {
            toast.success(t('CameraView.hwInstallSuccess', { pkg }))
            loadHardwareInfo()
        } else {
            toast.error(t('CameraView.hwInstallFailed', { pkg }))
        }
    } catch (e) { toast.error(e.message) }
    installingPkg.value = ''
}

const hwActiveBackend = computed(() => {
    if (!hw.value) return ''
    const coral = hw.value.coral?.connected && hw.value.coral?.python_package
    const openvino = hw.value.openvino?.in_camera_venv
    const yolo = hw.value.python_packages?.camera?.ultralytics

    if (coral) return t('CameraView.hwBackendCoral')
    if (openvino) return t('CameraView.hwBackendOpenvino')
    if (yolo) return t('CameraView.hwBackendCpu')
    return t('CameraView.hwBackendNone')
})

const hwRecommendation = computed(() => {
    if (!hw.value) return ''
    const hasIntel = hw.value.cpu?.has_intel_gpu || hw.value.gpu?.intel_igpu
    const hasOpenvino = hw.value.openvino?.in_camera_venv
    const hasCoral = hw.value.coral?.connected

    if (hasCoral && !hw.value.coral?.python_package) {
        return t('CameraView.hwRecommendCoralDriver')
    }
    if (hasIntel && !hasOpenvino && !hasCoral) {
        return t('CameraView.hwRecommendOpenvino')
    }
    return ''
})

// ── Auto-Discovery ──

async function autoDiscover() {
    discovering.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'autoDiscoverCameras' })
        if (res.data.success) {
            const count = res.data.payload.added || 0
            const found = res.data.payload.cameras?.length || 0
            if (count > 0) {
                toast.success(t('CameraView.discovered', { found, added: count }))
                loadCameras()
            } else if (found > 0) {
                toast.info(t('CameraView.discoveredExisting', { found }))
            } else {
                toast.warning(t('CameraView.discoveredNone'))
            }
        } else {
            toast.error(res.data.text || t('CameraView.discoveryFailed'))
        }
    } catch (e) { toast.error(e.message) }
    discovering.value = false
}

function generateToken() {
    const array = new Uint8Array(32)
    crypto.getRandomValues(array)
    webhookToken.value = Array.from(array, b => b.toString(16).padStart(2, '0')).join('')
}

// ── Hilfsfunktionen ──

function proxySnapshotUrl(url) {
    // Snapshots liegen lokal unter /camera-snapshots/ oder auf Frigate
    return url || ''
}

const snapshotTimestamps = reactive({})

function isWebUrl(url) {
    return url && (url.startsWith('http://') || url.startsWith('https://'))
}

function snapshotUrl(cam) {
    const ts = snapshotTimestamps[cam.id] || Date.now()
    return `/api/camera/stream.php?id=${cam.id}&t=${ts}`
}

function refreshSnapshot(cam) {
    snapshotTimestamps[cam.id] = Date.now()
}

// Auto-Refresh Snapshots alle 5 Sekunden (nur fuer RTSP-Kameras)
let snapshotInterval = null
function startSnapshotRefresh() {
    if (snapshotInterval) return
    snapshotInterval = setInterval(() => {
        if (viewMode.value !== 'live') return
        cameras.value.forEach(cam => {
            if (cam.stream_url && !isWebUrl(cam.stream_url)) {
                snapshotTimestamps[cam.id] = Date.now()
            }
        })
    }, 5000)
}

function formatDateTime(dt) {
    if (!dt) return ''
    const d = new Date(dt)
    return d.toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
    })
}

function labelColor(label) {
    const colors = { person: 'red', car: 'blue', dog: 'orange', cat: 'purple', motorcycle: 'teal' }
    return colors[label] || 'grey'
}

function openClip(event) {
    clipUrl.value = event.clip_url
}

// ── Tab-Wechsel: Daten laden ──
watch(viewMode, (mode) => {
    if (mode === 'events') loadEvents()
    if (mode === 'rules') loadRules()
    if (mode === 'settings') { loadStats(); loadConfig(); loadHardwareInfo() }
})

// ── SSE: Echtzeit-Updates ──
function handleSSE(event) {
    try {
        const data = JSON.parse(event.data)
        if (data.type === 'camera_event' || data.type === 'camera_alert') {
            loadCameras()
            if (viewMode.value === 'events') loadEvents()
            if (data.type === 'camera_alert') {
                toast.warning(`${data.camera}: ${data.label} — ${data.message || ''}`)
            }
        }
    } catch { /* kein Camera-Event */ }
}

// ── Init ──
onMounted(async () => {
    loadCameras()
    loadStats()
    startSnapshotRefresh()

    // SSE-Events lauschen (globale EventSource aus useInfoBar)
    window.addEventListener('camera-sse', handleSSE)
})
</script>

<style scoped>
.stream-container {
    position: relative;
    padding-top: 56.25%; /* 16:9 */
    background: #000;
}
.stream-iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: none;
}
.cursor-pointer {
    cursor: pointer;
}
.stream-refresh-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    opacity: 0.6;
}
.stream-refresh-btn:hover {
    opacity: 1;
}
</style>
