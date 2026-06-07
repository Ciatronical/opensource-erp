<template>
    <NavbarView />
    <v-container fluid class="pa-4">

        <!-- ── Header ─────────────────────────────────────────────── -->
        <v-row class="mb-4" align="center">
            <v-col>
                <div class="d-flex align-center gap-3">
                    <v-icon size="28" color="primary">mdi-cctv</v-icon>
                    <div>
                        <h1 class="text-h5 font-weight-bold">{{ t('cam.title') }}</h1>
                        <div class="text-caption text-medium-emphasis d-flex align-center gap-3">
                            <span><strong>{{ stats.camera_count || 0 }}</strong> {{ t('cam.statCameras') }}</span>
                            <span v-if="stats.events_today > 0">
                                <v-icon size="12" color="warning">mdi-circle</v-icon>
                                <strong>{{ stats.events_today }}</strong> {{ t('cam.statEventsToday') }}
                            </span>
                            <span v-if="unreadCount > 0" class="text-error">
                                <v-icon size="12" color="error">mdi-circle</v-icon>
                                <strong>{{ unreadCount }}</strong> {{ t('cam.statUnread') }}
                            </span>
                        </div>
                    </div>
                </div>
            </v-col>
            <v-col cols="auto">
                <v-btn-toggle v-model="viewMode" mandatory density="compact" variant="outlined" rounded="lg">
                    <v-btn value="live" prepend-icon="mdi-cctv">{{ t('cam.live') }}</v-btn>
                    <v-btn value="events" prepend-icon="mdi-bell-outline">
                        {{ t('cam.events') }}
                        <v-badge v-if="unreadCount > 0" :content="unreadCount" color="error" floating inline class="ml-1" />
                    </v-btn>
                    <v-btn value="rules" prepend-icon="mdi-shield-check-outline">{{ t('cam.rules') }}</v-btn>
                    <v-btn value="settings" prepend-icon="mdi-cog-outline">{{ t('cam.settings') }}</v-btn>
                </v-btn-toggle>
            </v-col>
        </v-row>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- LIVE-ANSICHT                                               -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <template v-if="viewMode === 'live'">
            <template v-if="cameras.length === 0">
                <v-row justify="center" class="mt-8">
                    <v-col cols="12" sm="8" md="5" class="text-center">
                        <v-icon size="80" color="grey-lighten-2" class="mb-4">mdi-cctv-off</v-icon>
                        <h2 class="text-h6 mb-2">{{ t('cam.noCamerasTitle') }}</h2>
                        <p class="text-body-2 text-medium-emphasis mb-6">{{ t('cam.noCamerasHint') }}</p>
                        <div class="d-flex justify-center gap-3">
                            <v-btn color="primary" @click="autoDiscover" :loading="discovering" prepend-icon="mdi-radar">
                                {{ t('cam.autoDiscover') }}
                            </v-btn>
                            <v-btn variant="tonal" prepend-icon="mdi-plus" @click="editCamera(null)">
                                {{ t('cam.addCamera') }}
                            </v-btn>
                        </div>
                    </v-col>
                </v-row>
            </template>

            <template v-else>
                <!-- Grid-Größe -->
                <div class="d-flex justify-end mb-3">
                    <v-btn-toggle v-model="gridCols" mandatory density="compact" variant="text">
                        <v-btn :value="6" icon="mdi-view-grid-outline" size="small" />
                        <v-btn :value="4" icon="mdi-view-comfy-outline" size="small" />
                        <v-btn :value="3" icon="mdi-view-module-outline" size="small" />
                    </v-btn-toggle>
                </div>

                <v-row>
                    <v-col v-for="cam in cameras" :key="cam.id" cols="12" :md="gridCols" :lg="gridCols">
                        <v-card elevation="2" rounded="lg" class="cam-card">
                            <!-- Stream -->
                            <div class="cam-video-wrap">
                                <!-- WebRTC/HTTP-Stream via iframe -->
                                <iframe
                                    v-if="cam.stream_url && isWebUrl(cam.stream_url)"
                                    :src="cam.stream_url"
                                    class="cam-video"
                                    allow="autoplay"
                                    loading="lazy"
                                />
                                <!-- RTSP → WebRTC via go2rtc WHEP -->
                                <video
                                    v-else-if="cam.stream_url && cam.cam_key"
                                    :ref="el => registerVideoEl(el, cam)"
                                    class="cam-video"
                                    autoplay muted playsinline
                                />
                                <!-- RTSP → Snapshot (Fallback ohne cam_key) -->
                                <img
                                    v-else-if="cam.stream_url"
                                    :src="snapshotUrls[cam.id] || snapshotUrl(cam)"
                                    class="cam-video"
                                    :alt="cam.name"
                                />
                                <!-- Kein Stream -->
                                <div v-else class="cam-no-stream d-flex flex-column align-center justify-center">
                                    <v-icon size="40" color="grey">mdi-video-off-outline</v-icon>
                                    <span class="text-caption text-medium-emphasis mt-2">{{ t('cam.noStream') }}</span>
                                </div>

                                <!-- Overlay: Name + Badge -->
                                <div class="cam-overlay">
                                    <div class="cam-overlay-left">
                                        <span class="cam-name">{{ cam.name }}</span>
                                        <span v-if="cam.location" class="cam-location">{{ cam.location }}</span>
                                    </div>
                                    <div class="cam-overlay-right">
                                        <v-badge
                                            v-if="cam.unread_events > 0"
                                            :content="cam.unread_events"
                                            color="error"
                                            inline
                                        />
                                        <!-- Refresh-Button für RTSP-Snapshots -->
                                        <v-btn
                                            v-if="cam.stream_url && !isWebUrl(cam.stream_url)"
                                            icon="mdi-refresh" size="x-small" variant="flat"
                                            class="ml-1 snap-refresh"
                                            @click="refreshSnapshot(cam)"
                                        />
                                    </div>
                                </div>
                            </div>

                            <!-- Zonen -->
                            <div v-if="cam.zones?.length" class="px-3 pt-2 pb-1">
                                <v-chip
                                    v-for="zone in cam.zones" :key="zone.id"
                                    size="x-small" class="mr-1 mb-1"
                                    :color="zone.color" variant="tonal"
                                >{{ zone.name }}</v-chip>
                            </div>

                            <!-- Aktionen -->
                            <v-card-actions class="pa-2">
                                <v-btn
                                    size="small" variant="text" prepend-icon="mdi-history"
                                    @click="showEventsForCamera(cam)"
                                >
                                    {{ t('cam.showEvents') }}
                                </v-btn>
                                <v-spacer />
                                <v-btn
                                    v-if="cam.unread_events > 0"
                                    size="small" variant="text" color="primary"
                                    @click="acknowledgeAllForCamera(cam)"
                                >
                                    {{ t('cam.markAllRead') }}
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-col>
                </v-row>
            </template>
        </template>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- EREIGNISSE                                                 -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <template v-if="viewMode === 'events'">
            <!-- Filter-Leiste -->
            <v-card variant="outlined" rounded="lg" class="mb-4 pa-3">
                <v-row dense align="center">
                    <v-col cols="12" sm="3">
                        <v-select
                            v-model="eventFilter.camera_id"
                            :items="cameraOptions"
                            :label="t('cam.filterCamera')"
                            clearable density="compact" variant="outlined" hide-details
                        />
                    </v-col>
                    <v-col cols="12" sm="2">
                        <v-select
                            v-model="eventFilter.label"
                            :items="labelOptions"
                            :label="t('cam.filterLabel')"
                            clearable density="compact" variant="outlined" hide-details
                        />
                    </v-col>
                    <v-col cols="12" sm="2">
                        <v-select
                            v-model="eventFilter.acknowledged"
                            :items="ackOptions"
                            :label="t('cam.filterStatus')"
                            density="compact" variant="outlined" hide-details
                        />
                    </v-col>
                    <v-col cols="12" sm="2">
                        <v-text-field
                            v-model="eventFilter.date_from"
                            :label="t('cam.dateFrom')"
                            type="date" density="compact" variant="outlined" hide-details
                        />
                    </v-col>
                    <v-col cols="12" sm="2">
                        <v-text-field
                            v-model="eventFilter.date_to"
                            :label="t('cam.dateTo')"
                            type="date" density="compact" variant="outlined" hide-details
                        />
                    </v-col>
                    <v-col cols="auto">
                        <v-btn icon="mdi-refresh" variant="text" @click="loadEvents" :loading="loadingEvents" />
                    </v-col>
                </v-row>
            </v-card>

            <v-card rounded="lg">
                <v-data-table
                    :headers="eventHeaders"
                    :items="events"
                    :loading="loadingEvents"
                    :items-per-page="50"
                    density="compact"
                    @update:options="onEventPagination"
                >
                    <template #item.snapshot_url="{ item }">
                        <v-img
                            v-if="item.snapshot_url"
                            :src="mediaUrl(item, 'snapshot')"
                            width="80" height="45" cover rounded="sm"
                            class="cursor-pointer my-1"
                            @click="previewSnapshot = mediaUrl(item, 'snapshot')"
                        />
                        <v-icon v-else size="small" color="grey">mdi-image-off-outline</v-icon>
                    </template>
                    <template #item.label="{ item }">
                        <v-chip size="small" :color="labelColor(item.label)" variant="tonal" :prepend-icon="labelIcon(item.label)">
                            {{ t('cam.labels.' + item.label, item.label) }}
                        </v-chip>
                    </template>
                    <template #item.zones="{ item }">
                        <v-chip v-for="z in item.zones" :key="z" size="x-small" class="mr-1" variant="outlined">{{ z }}</v-chip>
                    </template>
                    <template #item.started_at="{ item }">
                        {{ formatDateTime(item.started_at) }}
                    </template>
                    <template #item.score="{ item }">
                        <span v-if="item.score" :class="item.score >= 0.8 ? 'text-success' : 'text-warning'">
                            {{ Math.round(item.score * 100) }}%
                        </span>
                    </template>
                    <template #item.acknowledged="{ item }">
                        <v-icon v-if="item.acknowledged" color="success" size="small">mdi-check-circle</v-icon>
                        <v-btn v-else icon="mdi-check" size="x-small" variant="text" color="primary" @click="acknowledgeEvent(item)" />
                    </template>
                    <template #item.actions="{ item }">
                        <v-btn
                            v-if="item.clip_url || item.snapshot_url"
                            :icon="item.clip_url ? 'mdi-play-circle-outline' : 'mdi-image-search-outline'"
                            :color="item.clip_url ? 'primary' : 'grey'"
                            :title="item.clip_url ? t('cam.playClip') : t('cam.showSnapshot')"
                            size="x-small" variant="text"
                            @click="item.clip_url ? openClip(item) : (previewSnapshot = mediaUrl(item, 'snapshot'))"
                        />
                    </template>
                </v-data-table>
            </v-card>
        </template>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- REGELN                                                     -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <template v-if="viewMode === 'rules'">
            <div class="d-flex align-center mb-4">
                <h2 class="text-subtitle-1 font-weight-medium">{{ t('cam.rulesTitle') }}</h2>
                <v-spacer />
                <v-btn color="primary" prepend-icon="mdi-plus" @click="editRule(null)">
                    {{ t('cam.newRule') }}
                </v-btn>
            </div>

            <v-row v-if="rules.length === 0">
                <v-col class="text-center py-12 text-medium-emphasis">
                    <v-icon size="48" class="mb-3">mdi-shield-off-outline</v-icon>
                    <p>{{ t('cam.noRules') }}</p>
                </v-col>
            </v-row>

            <v-row v-else>
                <v-col v-for="rule in rules" :key="rule.id" cols="12" md="6" lg="4">
                    <v-card
                        rounded="lg" variant="outlined"
                        :class="rule.active ? '' : 'opacity-60'"
                        class="cursor-pointer rule-card"
                        @click="editRule(rule)"
                    >
                        <v-card-text class="pb-2">
                            <div class="d-flex align-center mb-2">
                                <v-icon :color="rule.active ? 'success' : 'grey'" size="18" class="mr-2">
                                    {{ rule.active ? 'mdi-shield-check' : 'mdi-shield-off-outline' }}
                                </v-icon>
                                <span class="font-weight-medium">{{ rule.name }}</span>
                                <v-spacer />
                                <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error" @click.stop="deleteRule(rule)" />
                            </div>
                            <div class="text-caption text-medium-emphasis">
                                <v-icon size="12" class="mr-1">mdi-cctv</v-icon>
                                {{ rule.camera_name || t('cam.allCameras') }}
                                <template v-if="rule.zone_name"> · {{ rule.zone_name }}</template>
                            </div>
                            <div class="text-caption text-medium-emphasis mt-1">
                                <v-chip v-for="lbl in rule.labels" :key="lbl" size="x-small" :color="labelColor(lbl)" variant="tonal" class="mr-1">
                                    {{ t('cam.labels.' + lbl, lbl) }}
                                </v-chip>
                            </div>
                        </v-card-text>
                        <v-divider />
                        <v-card-text class="pt-2 pb-2">
                            <div class="d-flex align-center gap-3 text-caption">
                                <span>
                                    <v-icon size="12" class="mr-1">mdi-bell-outline</v-icon>
                                    {{ t('cam.actions.' + rule.action, rule.action) }}
                                </span>
                                <span v-if="rule.time_from && rule.time_to">
                                    <v-icon size="12" class="mr-1">mdi-clock-outline</v-icon>
                                    {{ rule.time_from }}–{{ rule.time_to }}
                                </span>
                                <span>
                                    <v-icon size="12" class="mr-1">mdi-timer-pause-outline</v-icon>
                                    {{ Math.round(rule.cooldown_seconds / 60) }} Min
                                </span>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </template>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- EINSTELLUNGEN                                              -->
        <!-- ══════════════════════════════════════════════════════════ -->
        <template v-if="viewMode === 'settings'">

            <!-- Schnellstatistiken -->
            <v-row dense class="mb-4">
                <v-col v-for="s in quickStats" :key="s.label" cols="6" sm="3">
                    <v-card rounded="lg" variant="tonal" :color="s.color">
                        <v-card-text class="text-center py-3">
                            <div class="text-h4 font-weight-bold">{{ s.value }}</div>
                            <div class="text-caption">{{ s.label }}</div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-row>
                <!-- Kameras verwalten -->
                <v-col cols="12" md="7">
                    <v-card rounded="lg">
                        <v-card-title class="d-flex align-center pa-4 pb-2">
                            <v-icon size="20" class="mr-2">mdi-cctv</v-icon>
                            {{ t('cam.manageCameras') }}
                            <v-spacer />
                            <v-btn size="small" variant="tonal" prepend-icon="mdi-radar" class="mr-2"
                                @click="autoDiscover" :loading="discovering">
                                {{ t('cam.autoDiscover') }}
                            </v-btn>
                            <v-btn icon="mdi-plus" size="small" variant="text" @click="editCamera(null)" />
                        </v-card-title>

                        <v-list density="compact" v-if="cameras.length">
                            <v-list-item
                                v-for="cam in cameras" :key="cam.id"
                                @click="editCamera(cam)"
                                rounded="0"
                            >
                                <template #prepend>
                                    <v-icon size="16" color="grey" class="mr-1">mdi-cctv</v-icon>
                                </template>
                                <v-list-item-title class="text-body-2">{{ cam.name }}</v-list-item-title>
                                <v-list-item-subtitle>
                                    <span class="font-mono text-caption">{{ cam.cam_key }}</span>
                                    <span v-if="cam.location" class="ml-2">· {{ cam.location }}</span>
                                </v-list-item-subtitle>
                                <template #append>
                                    <v-btn icon="mdi-vector-polygon" size="x-small" variant="text"
                                        :color="cam.zones?.some(z => z.coordinates?.length) ? 'primary' : 'grey'"
                                        :title="t('cam.editZones')"
                                        @click.stop="openZoneEditor(cam)" />
                                    <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error"
                                        @click.stop="deleteCamera(cam)" />
                                </template>
                            </v-list-item>
                        </v-list>
                        <v-card-text v-else class="text-center text-medium-emphasis py-6">
                            {{ t('cam.noCamerasShort') }}
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- NVR-Dienst go2rtc -->
                <v-col cols="12" md="5">
                    <v-card rounded="lg">
                        <v-card-title class="d-flex align-center pa-4 pb-2">
                            <v-icon size="20" class="mr-2">mdi-video-wireless-outline</v-icon>
                            {{ t('cam.nvrTitle') }}
                            <v-spacer />
                            <v-btn icon="mdi-refresh" size="small" variant="text"
                                @click="loadGo2rtcStatus" :loading="go2rtcLoading" />
                        </v-card-title>

                        <v-card-text v-if="go2rtcLoading && !go2rtcStatus">
                            <v-skeleton-loader type="text, text, button" />
                        </v-card-text>

                        <v-card-text v-else-if="go2rtcStatus">
                            <!-- Status-Chip -->
                            <div class="d-flex align-center mb-4">
                                <v-chip
                                    :color="go2rtcStatus.running ? 'success' : (go2rtcStatus.installed ? 'warning' : 'default')"
                                    variant="tonal" size="small"
                                    :prepend-icon="go2rtcStatus.running ? 'mdi-check-circle' : 'mdi-alert-circle-outline'"
                                >
                                    {{ go2rtcStatus.running
                                        ? `${t('cam.nvrRunning')} ${go2rtcStatus.version ? 'v'+go2rtcStatus.version : ''}`
                                        : go2rtcStatus.installed ? t('cam.nvrStopped') : t('cam.nvrNotInstalled') }}
                                </v-chip>
                                <v-chip v-if="go2rtcStatus.systemd_active === 'active'" size="x-small" variant="text" class="ml-2">
                                    systemd ✓
                                </v-chip>
                            </div>

                            <!-- URL + Testen -->
                            <v-text-field
                                v-model="go2rtcUrl"
                                :label="t('cam.nvrUrl')"
                                variant="outlined" density="compact"
                                :hint="t('cam.nvrUrlHint')"
                                persistent-hint
                                class="mb-3"
                            >
                                <template #append-inner>
                                    <v-btn
                                        icon="mdi-connection" size="x-small" variant="text"
                                        :loading="savingGo2rtcUrl"
                                        :title="t('cam.nvrTest')"
                                        @click="saveGo2rtcUrl"
                                    />
                                </template>
                            </v-text-field>

                            <!-- Aktive Streams -->
                            <div v-if="go2rtcStatus.streams?.length" class="mb-3">
                                <div class="text-caption text-medium-emphasis mb-1">
                                    {{ t('cam.nvrStreams') }}
                                </div>
                                <v-chip
                                    v-for="s in go2rtcStatus.streams" :key="s"
                                    size="x-small" variant="tonal" color="primary" class="mr-1 mb-1"
                                    prepend-icon="mdi-play-circle-outline"
                                >{{ s }}</v-chip>
                            </div>

                            <!-- Install-Button wenn nicht installiert -->
                            <template v-if="!go2rtcStatus.installed">
                                <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                                    {{ t('cam.nvrInstallHint') }}
                                </v-alert>
                                <v-btn
                                    color="primary" prepend-icon="mdi-download"
                                    :loading="installingGo2rtc"
                                    @click="installGo2rtcService"
                                    block
                                >
                                    {{ t('cam.nvrInstall') }}
                                </v-btn>
                            </template>

                            <!-- Setup-Befehle (wenn systemd nicht aktiv) -->
                            <v-btn
                                v-if="go2rtcStatus.installed && go2rtcStatus.systemd_active !== 'active'"
                                variant="text" size="small" prepend-icon="mdi-console-line"
                                class="mt-2"
                                @click="showGo2rtcSetupCmds"
                            >
                                {{ t('cam.nvrSetupCmds') }}
                            </v-btn>
                        </v-card-text>

                        <v-card-text v-else class="text-center text-medium-emphasis py-6">
                            <v-btn variant="tonal" prepend-icon="mdi-refresh" @click="loadGo2rtcStatus">
                                {{ t('cam.nvrCheck') }}
                            </v-btn>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Kamera-Monitor-Dienst -->
            <v-row class="mt-4">
                <v-col cols="12">
                    <v-card rounded="lg">
                        <v-card-title class="d-flex align-center pa-4 pb-2">
                            <v-icon size="20" class="mr-2">mdi-eye-check-outline</v-icon>
                            {{ t('cam.monitorTitle') }}
                            <v-spacer />
                            <v-btn icon="mdi-refresh" size="small" variant="text"
                                @click="loadCameraMonitorStatus" :loading="cameraMonitorLoading" />
                        </v-card-title>

                        <v-card-text v-if="cameraMonitorLoading && !cameraMonitorStatus">
                            <v-skeleton-loader type="text, text, button" />
                        </v-card-text>

                        <v-card-text v-else-if="cameraMonitorStatus">
                            <v-row align="center" class="mb-2">
                                <v-col cols="auto">
                                    <v-chip
                                        :color="cameraMonitorStatus.running ? 'success' : (cameraMonitorStatus.installed ? 'warning' : 'error')"
                                        variant="tonal" size="small"
                                        :prepend-icon="cameraMonitorStatus.running ? 'mdi-check-circle' : 'mdi-alert-circle-outline'"
                                    >
                                        {{ cameraMonitorStatus.running
                                            ? t('cam.monitorRunning')
                                            : (cameraMonitorStatus.installed ? t('cam.monitorStopped') : t('cam.monitorNotInstalled')) }}
                                    </v-chip>
                                    <v-chip v-if="cameraMonitorStatus.systemd_active === 'active'" size="x-small" variant="text" class="ml-2">
                                        systemd ✓
                                    </v-chip>
                                </v-col>
                                <v-col>
                                    <v-alert v-if="!cameraMonitorStatus.running" type="warning" variant="tonal" density="compact" rounded="lg">
                                        {{ t('cam.monitorInstallHint') }}
                                    </v-alert>
                                </v-col>
                                <v-col cols="auto">
                                    <v-btn v-if="!cameraMonitorStatus.running"
                                        color="primary" prepend-icon="mdi-play-circle-outline" size="small"
                                        :loading="installingCameraMonitor"
                                        @click="installCameraMonitorService">
                                        {{ cameraMonitorStatus.installed ? t('cam.monitorStart') : t('cam.monitorInstall') }}
                                    </v-btn>
                                    <v-btn v-if="cameraMonitorStatus.running"
                                        variant="tonal" prepend-icon="mdi-restart" size="small"
                                        :loading="restartingCameraMonitor"
                                        @click="restartCameraMonitorService">
                                        {{ t('cam.monitorRestart') }}
                                    </v-btn>
                                </v-col>
                            </v-row>

                            <div v-if="cameraMonitorStatus.manual_cmd" class="mt-2">
                                <div class="text-caption text-medium-emphasis mb-1">{{ t('cam.monitorManualCmds') }}</div>
                                <v-sheet rounded="lg" color="surface-variant" class="pa-3">
                                    <pre class="text-caption" style="white-space:pre-wrap">{{ cameraMonitorStatus.manual_cmd }}</pre>
                                </v-sheet>
                            </div>

                            <div v-if="cameraMonitorStatus.logs" class="mt-3">
                                <div class="text-caption text-medium-emphasis mb-1">{{ t('cam.monitorLogs') }}</div>
                                <v-sheet rounded="lg" color="surface-variant" class="pa-3">
                                    <pre class="text-caption" style="max-height:120px;overflow-y:auto;white-space:pre-wrap">{{ cameraMonitorStatus.logs }}</pre>
                                </v-sheet>
                            </div>
                        </v-card-text>

                        <v-card-text v-else class="text-center text-medium-emphasis py-6">
                            <v-btn variant="tonal" prepend-icon="mdi-refresh" @click="loadCameraMonitorStatus">
                                {{ t('cam.monitorCheck') }}
                            </v-btn>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- KI-Objekterkennung -->
            <v-row class="mt-4">
                <v-col cols="12">
                    <v-card rounded="lg">
                        <v-card-title class="d-flex align-center pa-4 pb-2">
                            <v-icon size="20" class="mr-2">mdi-brain</v-icon>
                            {{ t('cam.hwTitle') }}
                            <v-spacer />
                            <v-btn icon="mdi-refresh" size="small" variant="text"
                                @click="loadHardwareInfo" :loading="hwLoading" />
                        </v-card-title>

                        <template v-if="hw">
                            <!-- Aktives Backend -->
                            <v-card-text class="pt-0 pb-2">
                                <v-alert
                                    :type="hwBackendSeverity"
                                    variant="tonal" density="compact" rounded="lg"
                                >
                                    <span class="font-weight-medium">{{ t('cam.hwActiveBackend') }}:</span>
                                    {{ hwActiveBackend }}
                                </v-alert>
                                <v-alert v-if="hwRecommendation" type="warning" variant="tonal" density="compact" rounded="lg" class="mt-2">
                                    {{ hwRecommendation }}
                                </v-alert>
                            </v-card-text>

                            <!-- Hardware-Tabelle -->
                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th style="width:200px">{{ t('cam.hwComponent') }}</th>
                                        <th>{{ t('cam.hwStatus') }}</th>
                                        <th style="width:120px" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- CPU -->
                                    <tr>
                                        <td class="font-weight-medium">CPU</td>
                                        <td class="text-body-2">
                                            {{ hw.cpu?.model }}
                                            <v-chip size="x-small" class="ml-2" variant="outlined">
                                                {{ hw.cpu?.cores }}C / {{ hw.cpu?.threads }}T
                                            </v-chip>
                                        </td>
                                        <td />
                                    </tr>
                                    <!-- RAM -->
                                    <tr>
                                        <td class="font-weight-medium">RAM</td>
                                        <td class="text-body-2">
                                            {{ hw.ram?.available_gb }} GB frei von {{ hw.ram?.total_gb }} GB
                                        </td>
                                        <td />
                                    </tr>
                                    <!-- Intel iGPU + OpenVINO -->
                                    <tr>
                                        <td>
                                            <div class="font-weight-medium">Intel iGPU</div>
                                            <div class="text-caption text-medium-emphasis">via OpenVINO</div>
                                        </td>
                                        <td>
                                            <template v-if="hw.openvino?.in_camera_venv">
                                                <v-icon color="success" size="16" class="mr-1">mdi-check-circle</v-icon>
                                                OpenVINO v{{ hw.openvino.version }}
                                                <span class="text-caption text-medium-emphasis ml-1">~40 Bilder/s</span>
                                            </template>
                                            <template v-else-if="hw.gpu?.intel_igpu">
                                                <v-icon color="warning" size="16" class="mr-1">mdi-alert-circle-outline</v-icon>
                                                {{ hw.gpu.device || 'Intel iGPU erkannt' }} — OpenVINO fehlt
                                            </template>
                                            <template v-else>
                                                <v-icon color="grey" size="16" class="mr-1">mdi-minus-circle-outline</v-icon>
                                                {{ t('cam.hwNotDetected') }}
                                            </template>
                                        </td>
                                        <td>
                                            <v-btn v-if="!hw.openvino?.in_camera_venv" size="x-small" variant="tonal" color="primary"
                                                @click="installHwPackage('openvino', 'camera')" :loading="installingPkg === 'openvino'">
                                                {{ t('cam.hwInstall') }}
                                            </v-btn>
                                        </td>
                                    </tr>
                                    <!-- Google Coral -->
                                    <tr>
                                        <td>
                                            <div class="font-weight-medium">Google Coral USB</div>
                                            <div class="text-caption text-medium-emphasis">TPU (~35 €)</div>
                                        </td>
                                        <td>
                                            <template v-if="hw.coral?.connected && hw.coral?.python_package">
                                                <v-icon color="success" size="16" class="mr-1">mdi-check-circle</v-icon>
                                                {{ t('cam.hwCoralReady') }}
                                                <span class="text-caption text-medium-emphasis ml-1">~100 Bilder/s</span>
                                            </template>
                                            <template v-else-if="hw.coral?.connected && !hw.coral?.driver_installed">
                                                <v-icon color="warning" size="16" class="mr-1">mdi-alert-circle-outline</v-icon>
                                                {{ t('cam.hwCoralNoDriver') }}
                                            </template>
                                            <template v-else-if="hw.coral?.connected && hw.coral?.driver_installed">
                                                <v-icon color="warning" size="16" class="mr-1">mdi-alert-circle-outline</v-icon>
                                                {{ t('cam.hwCoralNoPycoral') }}
                                            </template>
                                            <template v-else>
                                                <v-icon color="grey" size="16" class="mr-1">mdi-minus-circle-outline</v-icon>
                                                {{ t('cam.hwCoralNotConnected') }}
                                            </template>
                                        </td>
                                        <td>
                                            <v-btn v-if="hw.coral?.connected && !hw.coral?.python_package"
                                                size="x-small" variant="tonal" color="primary"
                                                @click="installHwPackage('pycoral', 'camera')" :loading="installingPkg === 'pycoral'">
                                                {{ t('cam.hwInstall') }}
                                            </v-btn>
                                        </td>
                                    </tr>
                                    <!-- YOLO -->
                                    <tr>
                                        <td>
                                            <div class="font-weight-medium">YOLO</div>
                                            <div class="text-caption text-medium-emphasis">CPU-Fallback</div>
                                        </td>
                                        <td>
                                            <template v-if="hw.python_packages?.camera?.ultralytics">
                                                <v-icon color="success" size="16" class="mr-1">mdi-check-circle</v-icon>
                                                ultralytics v{{ hw.python_packages.camera.ultralytics }}
                                                <span class="text-caption text-medium-emphasis ml-1">~10 Bilder/s</span>
                                            </template>
                                            <template v-else>
                                                <v-icon color="error" size="16" class="mr-1">mdi-close-circle</v-icon>
                                                {{ t('cam.hwNotInstalled') }}
                                                <span class="text-caption text-medium-emphasis ml-1">(Pflicht)</span>
                                            </template>
                                        </td>
                                        <td>
                                            <v-btn v-if="!hw.python_packages?.camera?.ultralytics"
                                                size="x-small" variant="tonal" color="primary"
                                                @click="installHwPackage('ultralytics', 'camera')" :loading="installingPkg === 'ultralytics'">
                                                {{ t('cam.hwInstall') }}
                                            </v-btn>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </template>

                        <v-card-text v-else class="text-center py-6 text-medium-emphasis">
                            <v-progress-circular v-if="hwLoading" indeterminate size="28" />
                            <span v-else>{{ t('cam.hwClickRefresh') }}</span>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </template>

        <!-- ══════════════════════════════════════════════════════════ -->
        <!-- DIALOGE                                                    -->
        <!-- ══════════════════════════════════════════════════════════ -->

        <!-- Kamera-Dialog -->
        <v-dialog v-model="showCameraDialog" max-width="560">
            <v-card rounded="lg">
                <v-card-title class="pa-4 pb-0">
                    {{ editingCamera?.id ? t('cam.editCamera') : t('cam.newCamera') }}
                </v-card-title>
                <v-tabs v-model="cameraDialogTab" density="compact" class="px-4">
                    <v-tab value="basic" prepend-icon="mdi-cctv">{{ t('cam.tabBasic') }}</v-tab>
                    <v-tab value="detection" prepend-icon="mdi-motion-sensor">{{ t('cam.tabDetection') }}</v-tab>
                </v-tabs>
                <v-divider />
                <v-card-text class="pa-4">
                    <v-window v-model="cameraDialogTab">
                        <!-- Tab: Basis -->
                        <v-window-item value="basic">
                            <v-text-field v-model="editingCamera.name" :label="t('cam.cameraName')"
                                variant="outlined" density="compact" class="mb-3" autofocus />
                            <v-text-field v-model="editingCamera.cam_key" :label="t('cam.camKey')"
                                :hint="t('cam.camKeyHint')" persistent-hint
                                variant="outlined" density="compact" class="mb-3" />
                            <v-text-field v-model="editingCamera.stream_url" :label="t('cam.streamUrl')"
                                :hint="t('cam.streamUrlHint')" persistent-hint
                                variant="outlined" density="compact" class="mb-3" />
                            <v-text-field v-model="editingCamera.location" :label="t('cam.cameraLocation')"
                                variant="outlined" density="compact" class="mb-3" />
                            <v-text-field v-model.number="editingCamera.sort_order" :label="t('cam.sortOrder')"
                                type="number" variant="outlined" density="compact" />
                        </v-window-item>
                        <!-- Tab: Erkennung -->
                        <v-window-item value="detection">
                            <div class="text-caption text-medium-emphasis mb-1">
                                {{ t('cam.motionThreshold') }}: {{ editingCamera.motion_threshold }}%
                            </div>
                            <v-slider v-model="editingCamera.motion_threshold"
                                :min="0.3" :max="10" :step="0.1" color="primary"
                                track-color="grey-lighten-2" hide-details class="mb-4"
                                :hint="t('cam.motionThresholdHint')" />
                            <div class="text-caption text-medium-emphasis mb-1">
                                {{ t('cam.detectionMinScore') }}: {{ Math.round(editingCamera.min_score * 100) }}%
                            </div>
                            <v-slider v-model="editingCamera.min_score"
                                :min="0.1" :max="0.95" :step="0.05" color="primary"
                                track-color="grey-lighten-2" hide-details class="mb-4" />
                            <v-select v-model="editingCamera.analysis_fps"
                                :items="[{title:'1 fps (langsam, wenig CPU)',value:1},{title:'2 fps (Standard)',value:2},{title:'3 fps',value:3},{title:'4 fps (schnell, viel CPU)',value:4}]"
                                :label="t('cam.analysisFps')"
                                variant="outlined" density="compact" class="mb-3" />
                            <v-switch v-model="editingCamera.record_clips"
                                :label="t('cam.recordClips')" color="primary" hide-details class="mb-3" />
                            <v-expand-transition>
                                <v-row v-if="editingCamera.record_clips" dense>
                                    <v-col cols="6">
                                        <v-text-field
                                            v-model.number="editingCamera.pre_record_secs"
                                            :label="t('cam.preRecordSecs')"
                                            type="number" :min="0" :max="30" suffix="s"
                                            variant="outlined" density="compact"
                                            :hint="t('cam.preRecordHint')" persistent-hint
                                        />
                                    </v-col>
                                    <v-col cols="6">
                                        <v-text-field
                                            v-model.number="editingCamera.post_record_secs"
                                            :label="t('cam.postRecordSecs')"
                                            type="number" :min="0" :max="30" suffix="s"
                                            variant="outlined" density="compact"
                                            :hint="t('cam.postRecordHint')" persistent-hint
                                        />
                                    </v-col>
                                </v-row>
                            </v-expand-transition>
                        </v-window-item>
                    </v-window>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="showCameraDialog = false">{{ t('cam.cancel') }}</v-btn>
                    <v-btn color="primary" @click="saveCamera" :loading="savingCamera">{{ t('cam.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Zonen-Editor-Dialog -->
        <v-dialog v-model="showZoneDialog" max-width="1000" :fullscreen="$vuetify.display.smAndDown">
            <v-card rounded="lg" style="overflow:hidden">
                <v-card-title class="d-flex align-center pa-4 pb-2">
                    <v-icon size="20" class="mr-2">mdi-vector-polygon</v-icon>
                    {{ t('cam.zoneEditorTitle') }}: {{ zoneCamera?.name }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="small" variant="text" @click="showZoneDialog = false" />
                </v-card-title>
                <v-divider />
                <div class="d-flex" style="height:500px">
                    <!-- Linke Seitenleiste: Zonenliste -->
                    <div style="width:220px;min-width:220px;border-right:1px solid rgba(0,0,0,.12);display:flex;flex-direction:column">
                        <div class="pa-2 text-caption text-medium-emphasis font-weight-medium px-3 pt-3">
                            {{ t('cam.zoneEditorHint') }}
                        </div>
                        <v-list density="compact" class="flex-grow-1 overflow-y-auto">
                            <v-list-item v-for="zone in zoneCamera?.zones" :key="zone.id"
                                :active="drawingZoneId === zone.id"
                                :class="drawingZoneId === zone.id ? 'bg-primary-lighten-5' : ''"
                                rounded="lg" class="mx-1 mb-1"
                                @click="selectZoneForDrawing(zone)">
                                <template #prepend>
                                    <div class="zone-color-dot mr-2" :style="{background: zone.color}" />
                                </template>
                                <v-list-item-title class="text-body-2">{{ zone.name }}</v-list-item-title>
                                <v-list-item-subtitle v-if="zonePolygons[zone.id]?.length >= 3" class="text-caption">
                                    {{ zonePolygons[zone.id].length }} {{ t('cam.zonePoints') }}
                                </v-list-item-subtitle>
                                <v-list-item-subtitle v-else class="text-caption text-medium-emphasis">
                                    {{ t('cam.zoneNoPolygon') }}
                                </v-list-item-subtitle>
                                <template #append>
                                    <v-btn v-if="zonePolygons[zone.id]?.length"
                                        icon="mdi-delete-outline" size="x-small" variant="text" color="error"
                                        @click.stop="clearZonePolygon(zone.id)" />
                                </template>
                            </v-list-item>
                        </v-list>
                        <v-divider />
                        <div class="pa-2">
                            <v-btn block size="small" prepend-icon="mdi-plus" variant="tonal"
                                @click="openAddZoneInEditor">
                                {{ t('cam.zoneAdd') }}
                            </v-btn>
                        </div>
                    </div>

                    <!-- Canvas-Bereich -->
                    <div class="flex-grow-1 d-flex flex-column">
                        <div ref="zoneCanvasWrap" class="zone-canvas-wrap flex-grow-1 position-relative"
                            style="background:#111;overflow:hidden">
                            <img v-if="zoneSnapshot"
                                ref="zoneBgImg"
                                :src="zoneSnapshot"
                                class="zone-bg-img"
                                @load="onZoneBgLoad"
                            />
                            <canvas v-if="zoneSnapshot"
                                ref="zoneCanvas"
                                class="zone-canvas"
                                :style="{cursor: drawingZoneId ? 'crosshair' : 'default'}"
                                @click="onZoneCanvasClick"
                                @dblclick="onZoneCanvasDblClick"
                                @mousemove="onZoneCanvasMouseMove"
                                @contextmenu.prevent="onZoneCanvasRightClick"
                            />
                            <div v-if="!zoneSnapshot" class="d-flex align-center justify-center h-100">
                                <v-progress-circular indeterminate color="white" />
                            </div>
                        </div>
                        <div class="pa-2 text-caption text-medium-emphasis d-flex align-center gap-3" style="height:36px">
                            <template v-if="drawingZoneId">
                                <v-icon size="14" color="primary">mdi-cursor-default-click</v-icon>
                                {{ t('cam.zoneDrawHint') }}
                            </template>
                            <template v-else>
                                <v-icon size="14">mdi-information-outline</v-icon>
                                {{ t('cam.zoneSelectHint') }}
                            </template>
                        </div>
                    </div>
                </div>
                <v-divider />
                <v-card-actions class="pa-3">
                    <v-btn variant="text" @click="showZoneDialog = false">{{ t('cam.cancel') }}</v-btn>
                    <v-spacer />
                    <v-btn color="primary" prepend-icon="mdi-content-save-outline"
                        @click="saveAllZones" :loading="savingZones">
                        {{ t('cam.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Regel-Dialog -->
        <v-dialog v-model="showRuleDialog" max-width="620">
            <v-card rounded="lg">
                <v-card-title class="pa-4 pb-2">
                    {{ editingRule?.id ? t('cam.editRule') : t('cam.newRule') }}
                </v-card-title>
                <v-card-text class="pa-4 pt-2">
                    <v-text-field v-model="editingRule.name" :label="t('cam.ruleName')" variant="outlined" density="compact" class="mb-3" />
                    <v-row dense class="mb-1">
                        <v-col cols="6">
                            <v-select v-model="editingRule.camera_id" :items="cameraOptions" :label="t('cam.filterCamera')" clearable variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="6">
                            <v-select v-model="editingRule.zone_id" :items="zoneOptionsForRule" :label="t('cam.ruleZone')" clearable variant="outlined" density="compact" />
                        </v-col>
                    </v-row>
                    <v-select v-model="editingRule.labels" :items="allLabelOptions" :label="t('cam.ruleLabels')" multiple chips closable-chips variant="outlined" density="compact" class="mb-3" />
                    <v-row dense class="mb-1">
                        <v-col cols="6">
                            <v-text-field v-model="editingRule.time_from" :label="t('cam.timeFrom')" type="time" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="editingRule.time_to" :label="t('cam.timeTo')" type="time" variant="outlined" density="compact" />
                        </v-col>
                    </v-row>
                    <v-select v-model="editingRule.days_of_week" :items="dayOptions" :label="t('cam.ruleDays')" multiple chips closable-chips variant="outlined" density="compact" class="mb-3" />
                    <div class="mb-3">
                        <div class="text-caption text-medium-emphasis mb-1">
                            {{ t('cam.minScore') }}: {{ Math.round(editingRule.min_score * 100) }}%
                        </div>
                        <v-slider v-model="editingRule.min_score" :min="0.1" :max="1" :step="0.05" color="primary" track-color="grey-lighten-2" hide-details />
                    </div>
                    <v-select v-model="editingRule.action" :items="actionOptions" :label="t('cam.ruleAction')" variant="outlined" density="compact" class="mb-3" />
                    <v-text-field v-if="editingRule.action === 'whatsapp'" v-model="editingRule.action_config.phone" :label="t('cam.whatsappPhone')" variant="outlined" density="compact" class="mb-3" />
                    <v-text-field v-if="editingRule.action === 'email'" v-model="editingRule.action_config.email" :label="t('cam.emailAddress')" variant="outlined" density="compact" class="mb-3" />
                    <v-text-field v-model="editingRule.action_config.message" :label="t('cam.customMessage')" variant="outlined" density="compact" class="mb-3" />
                    <v-row dense>
                        <v-col cols="6">
                            <v-text-field v-model.number="editingRule.cooldown_seconds" :label="t('cam.cooldown')" type="number" :suffix="t('cam.seconds')" variant="outlined" density="compact" />
                        </v-col>
                        <v-col cols="6" class="d-flex align-center">
                            <v-switch v-model="editingRule.active" :label="t('cam.ruleActive')" color="primary" hide-details />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="showRuleDialog = false">{{ t('cam.cancel') }}</v-btn>
                    <v-btn color="primary" @click="saveRule" :loading="savingRule">{{ t('cam.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snapshot-Vorschau -->
        <v-dialog v-model="showSnapshotPreview" max-width="900">
            <v-card rounded="lg">
                <v-img :src="previewSnapshot" max-height="700" contain />
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showSnapshotPreview = false">{{ t('cam.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Clip-Player -->
        <v-dialog v-model="showClipDialog" max-width="900">
            <v-card rounded="lg">
                <video v-if="clipUrl" :src="clipUrl" controls autoplay class="w-100" style="max-height:70vh" />
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showClipDialog = false">{{ t('cam.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Installations-Dialog (pip / go2rtc) -->
        <v-dialog v-model="installDialog.show" max-width="700" persistent>
            <v-card rounded="lg">
                <v-card-title class="d-flex align-center pa-4 pb-0">
                    <v-icon class="mr-2">mdi-console-line</v-icon>
                    {{ t('cam.hwInstallTitle', { pkg: installDialog.pkg }) }}
                </v-card-title>
                <v-card-text class="pa-4">
                    <!-- Läuft -->
                    <div v-if="installDialog.running" class="d-flex align-center gap-3 mb-3">
                        <v-progress-circular indeterminate size="20" width="2" color="primary" />
                        <span class="text-body-2">{{ t('cam.hwInstallRunning') }}</span>
                    </div>

                    <!-- Ergebnis-Banner -->
                    <v-alert
                        v-else-if="installDialog.success !== null"
                        :type="installDialog.success ? 'success' : 'error'"
                        variant="tonal" rounded="lg" class="mb-3"
                        :text="installDialog.success
                            ? t('cam.hwInstallSuccess', { pkg: installDialog.pkg })
                            : t('cam.hwInstallFailed', { pkg: installDialog.pkg })"
                    />

                    <!-- Log-Ausgabe -->
                    <pre v-if="installDialog.output" class="install-terminal">{{ installDialog.output }}</pre>

                    <!-- Manuelle Befehle -->
                    <template v-if="installDialog.cmd">
                        <div class="text-caption text-medium-emphasis mt-3 mb-1">
                            {{ t('cam.hwInstallManualHint') }}
                        </div>
                        <pre class="install-terminal">{{ installDialog.cmd }}</pre>
                    </template>

                    <!-- Info-Text -->
                    <div v-if="installDialog.info" class="text-caption text-medium-emphasis mt-3" style="white-space:pre-line">
                        {{ installDialog.info }}
                    </div>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn :disabled="installDialog.running" @click="installDialog.show = false">
                        {{ t('cam.hwInstallClose') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

    </v-container>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toast from '@/core/utils/toasts.js'

const { t } = useI18n()
const oserp = oserpStore()

// ── State ──────────────────────────────────────────────────────────────────

const viewMode = ref('live')
const cameras = ref([])
const events = ref([])
const rules = ref([])
const stats = reactive({})
const loadingEvents = ref(false)
const discovering = ref(false)
const gridCols = ref(4)  // md-Spalten pro Kamera (6=2, 4=3, 3=4)

// go2rtc
const go2rtcStatus = ref(null)
const go2rtcLoading = ref(false)
const go2rtcUrl = ref('http://localhost:1984')
const savingGo2rtcUrl = ref(false)
const installingGo2rtc = ref(false)

// Camera-Monitor-Dienst
const cameraMonitorStatus = ref(null)
const cameraMonitorLoading = ref(false)
const installingCameraMonitor = ref(false)
const restartingCameraMonitor = ref(false)

// Hardware
const hw = ref(null)
const hwLoading = ref(false)
const installingPkg = ref('')
const installDialog = reactive({
    show: false, pkg: '', cmd: '', output: '', info: '', running: false, success: null,
})

// Kamera-Dialog
const showCameraDialog = ref(false)
const cameraDialogTab = ref('basic')
const savingCamera = ref(false)
const editingCamera = reactive({
    id: null, name: '', cam_key: '', stream_url: '', location: '', sort_order: 0,
    motion_threshold: 1.5, min_score: 0.45, record_clips: true, analysis_fps: 2,
    pre_record_secs: 3, post_record_secs: 5,
})

// Zonen-Editor
const showZoneDialog = ref(false)
const zoneCamera = ref(null)
const zoneSnapshot = ref('')
const zoneBgImg = ref(null)
const zoneCanvas = ref(null)
const zoneCanvasWrap = ref(null)
const drawingZoneId = ref(null)
const zonePolygons = reactive({})     // zone.id → [[x,y], ...]
const currentPoints = ref([])         // Punkte der gerade gezeichneten Zone
const zoneCursorPos = ref(null)       // Cursor-Position (normalisiert)
const savingZones = ref(false)

// Regel-Dialog
const showRuleDialog = ref(false)
const savingRule = ref(false)
const editingRule = reactive({
    id: null, name: '', camera_id: null, zone_id: null,
    labels: ['person'], time_from: '', time_to: '',
    days_of_week: [0,1,2,3,4,5,6], min_score: 0.7,
    action: 'notify', action_config: {}, active: true, cooldown_seconds: 300
})

// Medien-Dialoge
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

// ── Computed ───────────────────────────────────────────────────────────────

const unreadCount = computed(() =>
    cameras.value.reduce((sum, c) => sum + (c.unread_events || 0), 0)
)

const quickStats = computed(() => [
    { label: t('cam.statCameras'), value: stats.camera_count || 0, color: 'primary' },
    { label: t('cam.statEventsToday'), value: stats.events_today || 0, color: 'secondary' },
    { label: t('cam.statUnread'), value: stats.unread_events || 0, color: unreadCount.value > 0 ? 'error' : 'default' },
    { label: t('cam.statRules'), value: stats.active_rules || 0, color: 'success' },
])

const cameraOptions = computed(() =>
    cameras.value.map(c => ({ title: c.name, value: c.id }))
)

const zoneOptionsForRule = computed(() => {
    if (!editingRule.camera_id) return []
    const cam = cameras.value.find(c => c.id === editingRule.camera_id)
    return (cam?.zones || []).map(z => ({ title: z.name, value: z.id }))
})

const labelOptions = computed(() => [
    { title: t('cam.labels.person'), value: 'person' },
    { title: t('cam.labels.car'), value: 'car' },
    { title: t('cam.labels.dog'), value: 'dog' },
    { title: t('cam.labels.cat'), value: 'cat' },
    { title: t('cam.labels.motorcycle'), value: 'motorcycle' },
    { title: t('cam.labels.bicycle'), value: 'bicycle' },
    { title: t('cam.labels.truck'), value: 'truck' },
    { title: t('cam.labels.bird'), value: 'bird' },
])

const allLabelOptions = computed(() => [
    'person', 'car', 'motorcycle', 'bicycle', 'bus', 'truck',
    'dog', 'cat', 'bird', 'horse'
])

const ackOptions = [
    { title: t('cam.filterAll'), value: 'all' },
    { title: t('cam.filterUnread'), value: 'false' },
    { title: t('cam.filterRead'), value: 'true' },
]

const dayOptions = [
    { title: t('cam.days.mon'), value: 1 },
    { title: t('cam.days.tue'), value: 2 },
    { title: t('cam.days.wed'), value: 3 },
    { title: t('cam.days.thu'), value: 4 },
    { title: t('cam.days.fri'), value: 5 },
    { title: t('cam.days.sat'), value: 6 },
    { title: t('cam.days.sun'), value: 0 },
]

const actionOptions = [
    { title: t('cam.actions.notify'), value: 'notify' },
    { title: t('cam.actions.whatsapp'), value: 'whatsapp' },
    { title: t('cam.actions.email'), value: 'email' },
    { title: t('cam.actions.log'), value: 'log' },
]

const eventHeaders = [
    { title: '', key: 'snapshot_url', sortable: false, width: 90 },
    { title: t('cam.headerCamera'), key: 'camera_display_name' },
    { title: t('cam.headerLabel'), key: 'label' },
    { title: t('cam.headerZones'), key: 'zones', sortable: false },
    { title: t('cam.headerTime'), key: 'started_at' },
    { title: t('cam.headerScore'), key: 'score', width: 80 },
    { title: '', key: 'acknowledged', sortable: false, width: 50 },
    { title: '', key: 'actions', sortable: false, width: 50 },
]

const hwActiveBackend = computed(() => {
    if (!hw.value) return ''
    if (hw.value.coral?.connected && hw.value.coral?.python_package) return t('cam.hwBackendCoral')
    if (hw.value.openvino?.in_camera_venv) return t('cam.hwBackendOpenvino')
    if (hw.value.python_packages?.camera?.ultralytics) return t('cam.hwBackendCpu')
    return t('cam.hwBackendNone')
})

const hwBackendSeverity = computed(() => {
    if (!hw.value) return 'info'
    if (hw.value.coral?.connected && hw.value.coral?.python_package) return 'success'
    if (hw.value.openvino?.in_camera_venv) return 'success'
    if (hw.value.python_packages?.camera?.ultralytics) return 'warning'
    return 'error'
})

const hwRecommendation = computed(() => {
    if (!hw.value) return ''
    if (hw.value.coral?.connected && !hw.value.coral?.python_package)
        return t('cam.hwRecommendCoralDriver')
    if ((hw.value.cpu?.has_intel_gpu || hw.value.gpu?.intel_igpu)
        && !hw.value.openvino?.in_camera_venv && !hw.value.coral?.connected)
        return t('cam.hwRecommendOpenvino')
    return ''
})

// ── API ────────────────────────────────────────────────────────────────────

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

async function loadGo2rtcStatus() {
    go2rtcLoading.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'getGo2rtcStatus' })
        if (res.data.success) {
            go2rtcStatus.value = res.data.payload
            go2rtcUrl.value = res.data.payload.go2rtc_url || 'http://localhost:1984'
        }
    } catch {}
    go2rtcLoading.value = false
}

async function loadHardwareInfo() {
    hwLoading.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'getHardwareInfo' })
        if (res.data.success) hw.value = res.data.payload.hardware
    } catch {}
    hwLoading.value = false
}

// ── go2rtc ─────────────────────────────────────────────────────────────────

async function saveGo2rtcUrl() {
    savingGo2rtcUrl.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'detectGo2rtc', url: go2rtcUrl.value })
        if (res.data.success) {
            toast.success(t('cam.nvrConnected', { n: res.data.payload.cameras_synced || 0 }))
            loadGo2rtcStatus()
        } else {
            toast.error(t('cam.nvrConnectFailed'))
        }
    } catch (e) { toast.error(e.message) }
    savingGo2rtcUrl.value = false
}

async function installGo2rtcService() {
    installingGo2rtc.value = true
    Object.assign(installDialog, {
        show: true, pkg: 'go2rtc', cmd: '', output: '', info: '', running: true, success: null,
    })
    try {
        const res = await axios.post('/api/camera/', { action: 'installGo2rtc' })
        installDialog.running = false
        const p = res.data.payload
        if (res.data.success) {
            installDialog.output = p?.log || ''
            installDialog.cmd = p?.manual_cmd || ''
            installDialog.info = p?.running
                ? `go2rtc läuft unter ${p.go2rtc_url}`
                : p?.manual_cmd ? t('cam.nvrManualCmds') : ''
            installDialog.success = true
            loadGo2rtcStatus()
        } else {
            installDialog.output = [
                p?.log,
                p?.cmd ? `$ ${p.cmd}` : '',
                p?.output,
            ].filter(Boolean).join('\n')
            installDialog.cmd = p?.setup_cmd || ''
            installDialog.success = false
        }
    } catch (e) {
        installDialog.running = false
        installDialog.success = false
        installDialog.output = e.message
    }
    installingGo2rtc.value = false
}

async function showGo2rtcSetupCmds() {
    Object.assign(installDialog, {
        show: true, pkg: 'go2rtc', cmd: '', output: '', info: '', running: true, success: null,
    })
    try {
        const res = await axios.post('/api/camera/', { action: 'getSetupCmds' })
        installDialog.running = false
        installDialog.cmd = res.data.payload?.cmd || ''
        installDialog.info = res.data.payload?.info || ''
        installDialog.success = null
    } catch (e) {
        installDialog.running = false
        installDialog.output = e.message
    }
}

// ── Camera-Monitor ─────────────────────────────────────────────────────────

async function loadCameraMonitorStatus() {
    cameraMonitorLoading.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'getCameraMonitorStatus' })
        if (res.data.success) cameraMonitorStatus.value = res.data.payload
    } catch {}
    cameraMonitorLoading.value = false
}

async function installCameraMonitorService() {
    installingCameraMonitor.value = true
    Object.assign(installDialog, {
        show: true, pkg: 'camera-monitor', cmd: '', output: '', info: '', running: true, success: null,
    })
    try {
        const res = await axios.post('/api/camera/', { action: 'installCameraMonitor' })
        installDialog.running = false
        const p = res.data.payload
        if (res.data.success) {
            installDialog.output = p?.log || ''
            installDialog.cmd = p?.manual_cmd || ''
            installDialog.info = p?.running ? t('cam.monitorRunning') : (p?.manual_cmd ? t('cam.monitorManualCmds') : '')
            installDialog.success = true
            loadCameraMonitorStatus()
        } else {
            installDialog.output = res.data.text || ''
            installDialog.success = false
        }
    } catch (e) {
        installDialog.running = false
        installDialog.success = false
        installDialog.output = e.message
    }
    installingCameraMonitor.value = false
}

async function restartCameraMonitorService() {
    restartingCameraMonitor.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'restartCameraMonitor' })
        if (res.data.success) {
            toast.success(t('cam.monitorRestartSuccess'))
            loadCameraMonitorStatus()
        } else {
            toast.error(res.data.text || t('cam.monitorInstallFailed'))
        }
    } catch (e) { toast.error(e.message) }
    restartingCameraMonitor.value = false
}

// ── Kamera CRUD ────────────────────────────────────────────────────────────

function editCamera(cam) {
    cameraDialogTab.value = 'basic'
    Object.assign(editingCamera, cam
        ? { ...cam }
        : { id: null, name: '', cam_key: '', stream_url: '', location: '', sort_order: 0,
            motion_threshold: 1.5, min_score: 0.45, record_clips: true, analysis_fps: 2,
            pre_record_secs: 3, post_record_secs: 5 }
    )
    showCameraDialog.value = true
}

async function saveCamera() {
    savingCamera.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'saveCamera', ...editingCamera })
        if (res.data.success) {
            showCameraDialog.value = false
            toast.success(t('cam.cameraSaved'))
            loadCameras()
        } else {
            toast.error(res.data.text)
        }
    } catch (e) { toast.error(e.message) }
    savingCamera.value = false
}

async function deleteCamera(cam) {
    if (!confirm(t('cam.confirmDeleteCamera', { name: cam.name }))) return
    try {
        await axios.post('/api/camera/', { action: 'deleteCamera', id: cam.id })
        toast.success(t('cam.cameraDeleted'))
        loadCameras()
    } catch (e) { toast.error(e.message) }
}

// ── Zonen-Editor ───────────────────────────────────────────────────────────

function openZoneEditor(cam) {
    zoneCamera.value = cam
    zoneSnapshot.value = ''
    drawingZoneId.value = null
    currentPoints.value = []
    zoneCursorPos.value = null

    // Lokale Kopie der gespeicherten Koordinaten
    for (const key in zonePolygons) delete zonePolygons[key]
    for (const zone of cam.zones || []) {
        zonePolygons[zone.id] = zone.coordinates ? JSON.parse(JSON.stringify(zone.coordinates)) : []
    }

    // Snapshot laden
    zoneSnapshot.value = `/api/camera/stream.php?id=${cam.id}&t=${Date.now()}`
    showZoneDialog.value = true
}

function onZoneBgLoad() {
    renderZoneCanvas()
}

function selectZoneForDrawing(zone) {
    if (currentPoints.value.length >= 3) finishCurrentPolygon()
    drawingZoneId.value = zone.id
    currentPoints.value = []
    renderZoneCanvas()
}

function clearZonePolygon(zoneId) {
    zonePolygons[zoneId] = []
    if (drawingZoneId.value === zoneId) {
        drawingZoneId.value = null
        currentPoints.value = []
    }
    renderZoneCanvas()
}

function _canvasNorm(evt) {
    const canvas = zoneCanvas.value
    if (!canvas) return null
    const rect = canvas.getBoundingClientRect()
    return [
        (evt.clientX - rect.left) / rect.width,
        (evt.clientY - rect.top) / rect.height,
    ]
}

function _distPx(p1, p2, canvasW, canvasH) {
    return Math.sqrt(
        ((p1[0] - p2[0]) * canvasW) ** 2 +
        ((p1[1] - p2[1]) * canvasH) ** 2
    )
}

function onZoneCanvasClick(evt) {
    if (!drawingZoneId.value) return
    const pos = _canvasNorm(evt)
    if (!pos) return
    const canvas = zoneCanvas.value
    const pts = currentPoints.value

    // Nahe am ersten Punkt? → Polygon schließen
    if (pts.length >= 3 && _distPx(pos, pts[0], canvas.width, canvas.height) < 18) {
        finishCurrentPolygon()
        return
    }
    currentPoints.value = [...pts, pos]
    renderZoneCanvas()
}

function onZoneCanvasDblClick(evt) {
    if (!drawingZoneId.value) return
    // Letzten Punkt (der vom click davor) entfernen dann schließen
    if (currentPoints.value.length > 3) {
        currentPoints.value = currentPoints.value.slice(0, -1)
    }
    finishCurrentPolygon()
}

function onZoneCanvasRightClick() {
    if (!drawingZoneId.value) return
    if (currentPoints.value.length > 0) {
        currentPoints.value = currentPoints.value.slice(0, -1)
        renderZoneCanvas()
    }
}

function onZoneCanvasMouseMove(evt) {
    if (!drawingZoneId.value) return
    zoneCursorPos.value = _canvasNorm(evt)
    renderZoneCanvas()
}

function finishCurrentPolygon() {
    const pts = currentPoints.value
    if (pts.length >= 3 && drawingZoneId.value) {
        zonePolygons[drawingZoneId.value] = [...pts]
    }
    currentPoints.value = []
    drawingZoneId.value = null
    zoneCursorPos.value = null
    renderZoneCanvas()
}

function _hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16)
    const g = parseInt(hex.slice(3, 5), 16)
    const b = parseInt(hex.slice(5, 7), 16)
    return `rgba(${r},${g},${b},${alpha})`
}

function renderZoneCanvas() {
    const canvas = zoneCanvas.value
    const img = zoneBgImg.value
    if (!canvas || !img) return

    canvas.width  = img.clientWidth  || img.naturalWidth
    canvas.height = img.clientHeight || img.naturalHeight
    const ctx = canvas.getContext('2d')
    const W = canvas.width, H = canvas.height
    ctx.clearRect(0, 0, W, H)

    const zones = zoneCamera.value?.zones || []

    // Gespeicherte Polygone zeichnen
    for (const zone of zones) {
        const pts = zonePolygons[zone.id]
        if (!pts || pts.length < 3) continue
        ctx.beginPath()
        ctx.moveTo(pts[0][0] * W, pts[0][1] * H)
        for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0] * W, pts[i][1] * H)
        ctx.closePath()
        ctx.fillStyle   = _hexToRgba(zone.color, 0.22)
        ctx.fill()
        ctx.strokeStyle = zone.color
        ctx.lineWidth   = 2
        ctx.stroke()
        // Label
        const cx = pts.reduce((s, p) => s + p[0], 0) / pts.length * W
        const cy = pts.reduce((s, p) => s + p[1], 0) / pts.length * H
        ctx.font      = 'bold 13px sans-serif'
        ctx.fillStyle = '#fff'
        ctx.textAlign = 'center'
        ctx.textBaseline = 'middle'
        ctx.shadowColor  = 'rgba(0,0,0,.7)'
        ctx.shadowBlur   = 4
        ctx.fillText(zone.name, cx, cy)
        ctx.shadowBlur   = 0
    }

    // Aktuell gezeichnetes Polygon
    const pts = currentPoints.value
    if (drawingZoneId.value && pts.length > 0) {
        const zone = zones.find(z => z.id === drawingZoneId.value)
        const color = zone?.color || '#FF5722'

        ctx.setLineDash([6, 4])
        ctx.strokeStyle = color
        ctx.lineWidth   = 2
        ctx.beginPath()
        ctx.moveTo(pts[0][0] * W, pts[0][1] * H)
        for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0] * W, pts[i][1] * H)
        if (zoneCursorPos.value) ctx.lineTo(zoneCursorPos.value[0] * W, zoneCursorPos.value[1] * H)
        ctx.stroke()
        ctx.setLineDash([])

        // Vertices
        for (let i = 0; i < pts.length; i++) {
            const isFirst = i === 0
            ctx.beginPath()
            ctx.arc(pts[i][0] * W, pts[i][1] * H, isFirst && pts.length >= 3 ? 9 : 5, 0, Math.PI * 2)
            ctx.fillStyle   = color
            ctx.fill()
            if (isFirst && pts.length >= 3) {
                ctx.strokeStyle = '#fff'
                ctx.lineWidth   = 2
                ctx.stroke()
            }
        }
    }
}

async function openAddZoneInEditor() {
    const name = prompt(t('cam.zoneNewName'))
    if (!name?.trim()) return
    const cam = zoneCamera.value
    const zoneKey = name.toLowerCase().replace(/[^a-z0-9]+/g, '_')
    const colors  = ['#FF5722','#2196F3','#4CAF50','#FF9800','#9C27B0','#00BCD4']
    const color   = colors[(cam.zones?.length || 0) % colors.length]
    try {
        await axios.post('/api/camera/', {
            action: 'saveZone', camera_id: cam.id, name: name.trim(), zone_key: zoneKey, color,
        })
        await loadCameras()
        const updated = cameras.value.find(c => c.id === cam.id)
        if (updated) {
            zoneCamera.value = updated
            const newZone = updated.zones?.find(z => z.zone_key === zoneKey)
            if (newZone) {
                zonePolygons[newZone.id] = []
                selectZoneForDrawing(newZone)
            }
        }
    } catch (e) { toast.error(e.message) }
}

async function saveAllZones() {
    savingZones.value = true
    try {
        const zones = zoneCamera.value?.zones || []
        for (const zone of zones) {
            await axios.post('/api/camera/', {
                action: 'saveZone',
                id: zone.id,
                camera_id: zone.camera_id ?? zoneCamera.value.id,
                name: zone.name,
                zone_key: zone.zone_key,
                color: zone.color,
                coordinates: zonePolygons[zone.id]?.length >= 3 ? zonePolygons[zone.id] : null,
            })
        }
        toast.success(t('cam.zonesSaved'))
        await loadCameras()
        showZoneDialog.value = false
    } catch (e) { toast.error(e.message) }
    savingZones.value = false
}

// ── Events ─────────────────────────────────────────────────────────────────

async function acknowledgeEvent(event) {
    try {
        await axios.post('/api/camera/', { action: 'acknowledgeEvent', id: event.id })
        event.acknowledged = true
        const cam = cameras.value.find(c => c.id === event.camera_id)
        if (cam && cam.unread_events > 0) cam.unread_events--
    } catch (e) { toast.error(e.message) }
}

async function acknowledgeAllForCamera(cam) {
    try {
        await axios.post('/api/camera/', { action: 'acknowledgeAllEvents', camera_id: cam.id })
        cam.unread_events = 0
        toast.success(t('cam.allMarkedRead'))
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

// ── Regeln CRUD ────────────────────────────────────────────────────────────

function editRule(rule) {
    Object.assign(editingRule, rule
        ? { ...rule, action_config: { ...(rule.action_config || {}) } }
        : {
            id: null, name: '', camera_id: null, zone_id: null,
            labels: ['person'], time_from: '', time_to: '',
            days_of_week: [1,2,3,4,5], min_score: 0.7,
            action: 'notify', action_config: {}, active: true, cooldown_seconds: 300
        }
    )
    showRuleDialog.value = true
}

async function saveRule() {
    savingRule.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'saveRule', ...editingRule })
        if (res.data.success) {
            showRuleDialog.value = false
            toast.success(t('cam.ruleSaved'))
            loadRules()
        } else {
            toast.error(res.data.text)
        }
    } catch (e) { toast.error(e.message) }
    savingRule.value = false
}

async function deleteRule(rule) {
    if (!confirm(t('cam.confirmDeleteRule', { name: rule.name }))) return
    try {
        await axios.post('/api/camera/', { action: 'deleteRule', id: rule.id })
        toast.success(t('cam.ruleDeleted'))
        loadRules()
    } catch (e) { toast.error(e.message) }
}

// ── Hardware-Packages ──────────────────────────────────────────────────────

async function installHwPackage(pkg, service) {
    installingPkg.value = pkg
    Object.assign(installDialog, {
        show: true, pkg, cmd: '', output: '', info: '', running: true, success: null,
    })
    try {
        const res = await axios.post('/api/camera/', { action: 'installPythonPackage', package: pkg, service })
        installDialog.running = false
        const p = res.data.payload
        installDialog.output = [
            p?.cmd ? `$ ${p.cmd}` : '',
            p?.output,
        ].filter(Boolean).join('\n')
        installDialog.cmd = p?.setup_cmd || ''
        installDialog.success = res.data.success
        if (res.data.success) loadHardwareInfo()
    } catch (e) {
        installDialog.running = false
        installDialog.success = false
        installDialog.output = e.message
    }
    installingPkg.value = ''
}

// ── Auto-Discovery ─────────────────────────────────────────────────────────

async function autoDiscover() {
    discovering.value = true
    try {
        const res = await axios.post('/api/camera/', { action: 'autoDiscoverCameras' })
        if (res.data.success) {
            const { added = 0, updated = 0, cameras: found = [] } = res.data.payload
            if (added > 0 || updated > 0) {
                toast.success(t('cam.discovered', { found: found.length, added }))
                loadCameras()
            } else if (found.length > 0) {
                toast.info(t('cam.discoveredExisting', { found: found.length }))
            } else {
                toast.warning(t('cam.discoveredNone'))
            }
        } else {
            toast.error(res.data.text || t('cam.discoveryFailed'))
        }
    } catch (e) { toast.error(e.message) }
    discovering.value = false
}

// ── Hilfsfunktionen ────────────────────────────────────────────────────────

const snapshotUrls = reactive({})

function isWebUrl(url) {
    return url && (url.startsWith('http://') || url.startsWith('https://'))
}

function snapshotUrl(cam) {
    return `/api/camera/stream.php?id=${cam.id}&t=${Date.now()}`
}

function refreshSnapshot(cam) {
    const url = `/api/camera/stream.php?id=${cam.id}&t=${Date.now()}`
    const img = new Image()
    img.onload = () => { snapshotUrls[cam.id] = url }
    img.src = url
}

let snapshotInterval = null
function startSnapshotRefresh() {
    if (snapshotInterval) return
    cameras.value.forEach(cam => {
        if (cam.stream_url && !isWebUrl(cam.stream_url))
            snapshotUrls[cam.id] = snapshotUrl(cam)
    })
    snapshotInterval = setInterval(() => {
        if (viewMode.value !== 'live') return
        cameras.value.forEach(cam => {
            if (cam.stream_url && !isWebUrl(cam.stream_url))
                refreshSnapshot(cam)
        })
    }, 500)
}

// ── WebRTC (WHEP via go2rtc) ────────────────────────────────────────────────

const peerConnections = {}

function registerVideoEl(el, cam) {
    if (!el || !cam.cam_key) return
    if (peerConnections[cam.id]) return
    startWebRTC(el, cam)
}

async function startWebRTC(videoEl, cam) {
    const pc = new RTCPeerConnection({
        iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
        iceTransportPolicy: 'all',
    })
    peerConnections[cam.id] = pc

    pc.addTransceiver('video', { direction: 'recvonly' })
    pc.addTransceiver('audio', { direction: 'recvonly' })

    pc.ontrack = ev => {
        if (ev.track.kind === 'video') {
            videoEl.srcObject = ev.streams[0] || new MediaStream([ev.track])
        }
    }

    pc.oniceconnectionstatechange = () => {
        if (pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'disconnected') {
            pc.close()
            delete peerConnections[cam.id]
            setTimeout(() => startWebRTC(videoEl, cam), 3000)
        }
    }

    const offer = await pc.createOffer()
    await pc.setLocalDescription(offer)

    // Warten bis ICE-Kandidaten gesammelt sind (max 2s)
    await new Promise(resolve => {
        if (pc.iceGatheringState === 'complete') return resolve()
        const t = setTimeout(resolve, 2000)
        pc.onicegatheringstatechange = () => {
            if (pc.iceGatheringState === 'complete') { clearTimeout(t); resolve() }
        }
    })

    try {
        const res = await fetch(`/nvr/api/webrtc?src=${encodeURIComponent(cam.cam_key)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/sdp' },
            body: pc.localDescription.sdp,
        })
        if (!res.ok) throw new Error(`WHEP ${res.status}`)
        const sdp = await res.text()
        await pc.setRemoteDescription({ type: 'answer', sdp })
    } catch {
        pc.close()
        delete peerConnections[cam.id]
        // Fallback: Snapshot weiterhin aktiv
    }
}

function stopAllWebRTC() {
    Object.values(peerConnections).forEach(pc => pc.close())
    Object.keys(peerConnections).forEach(k => delete peerConnections[k])
}

function formatDateTime(dt) {
    if (!dt) return ''
    return new Date(dt).toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit'
    })
}

function labelColor(label) {
    const colors = {
        person: 'red', car: 'blue', dog: 'orange', cat: 'purple',
        motorcycle: 'teal', bicycle: 'cyan', truck: 'indigo',
        bird: 'lime', horse: 'brown',
    }
    return colors[label] || 'grey'
}

function labelIcon(label) {
    const icons = {
        person: 'mdi-account', car: 'mdi-car', dog: 'mdi-dog',
        cat: 'mdi-cat', motorcycle: 'mdi-motorbike', bicycle: 'mdi-bicycle',
        truck: 'mdi-truck', bird: 'mdi-bird', horse: 'mdi-horse',
    }
    return icons[label] || 'mdi-eye'
}

function openClip(event) {
    clipUrl.value = mediaUrl(event, 'clip')
}

// Medien (Snapshot/Clip) werden über den authentifizierten Endpoint geladen,
// nicht mehr direkt aus public/. snapshot_url/clip_url dienen nur noch als
// Vorhandensein-Flag.
function mediaUrl(event, type) {
    if (!event?.event_id) return ''
    return `/api/camera/media.php?type=${type}&event=${encodeURIComponent(event.event_id)}`
}

// ── Tab-Wechsel ────────────────────────────────────────────────────────────

watch(viewMode, (mode) => {
    if (mode === 'events') loadEvents()
    if (mode === 'rules') loadRules()
    if (mode === 'settings') { loadStats(); loadGo2rtcStatus(); loadHardwareInfo(); loadCameraMonitorStatus() }
    if (mode !== 'live') stopAllWebRTC()
})

// ── SSE ────────────────────────────────────────────────────────────────────

function handleSSE(event) {
    try {
        const data = JSON.parse(event.data)
        if (data.type === 'camera_event' || data.type === 'camera_alert') {
            const cam = cameras.value.find(c => c.cam_key === data.camera)
            if (cam) cam.unread_events = (cam.unread_events || 0) + 1
            if (viewMode.value === 'events') loadEvents()
            if (data.type === 'camera_alert')
                toast.warning(`${data.camera}: ${data.label} — ${data.message || ''}`)
        }
    } catch {}
}

// ── Init ───────────────────────────────────────────────────────────────────

onMounted(() => {
    loadCameras()
    loadStats()
    startSnapshotRefresh()
    window.addEventListener('camera-sse', handleSSE)
})

onUnmounted(() => {
    clearInterval(snapshotInterval)
    snapshotInterval = null
    stopAllWebRTC()
    window.removeEventListener('camera-sse', handleSSE)
})
</script>

<style scoped>
/* ── Zonen-Editor ── */
.zone-canvas-wrap {
    position: relative;
    width: 100%;
    height: 100%;
}
.zone-bg-img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    user-select: none;
    -webkit-user-drag: none;
}
.zone-canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}
.zone-color-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
}

/* ── Kamera-Karte ── */
.cam-card {
    overflow: hidden;
}

.cam-video-wrap {
    position: relative;
    aspect-ratio: 16 / 9;
    background: #111;
    overflow: hidden;
}

.cam-video {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border: none;
}

.cam-no-stream {
    position: absolute;
    inset: 0;
}

.cam-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: flex-end;
    padding: 8px;
    background: linear-gradient(to top, rgba(0,0,0,.65) 0%, transparent 60%);
}

.cam-overlay-left {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
}

.cam-overlay-right {
    display: flex;
    align-items: center;
    gap: 4px;
}

.cam-name {
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
    text-shadow: 0 1px 3px rgba(0,0,0,.6);
}

.cam-location {
    color: rgba(255,255,255,.7);
    font-size: 11px;
    line-height: 1.2;
}

.snap-refresh {
    opacity: 0.7;
    background: rgba(0,0,0,.4) !important;
    color: #fff !important;
}
.snap-refresh:hover { opacity: 1; }

/* ── Regeln ── */
.rule-card { transition: box-shadow .15s; }
.rule-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.12) !important; }

/* ── Terminal-Output ── */
.install-terminal {
    background: #1a1a2e;
    color: #e2e8f0;
    font-family: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
    font-size: 12px;
    line-height: 1.6;
    padding: 14px 16px;
    border-radius: 8px;
    max-height: 380px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-all;
}

/* ── Misc ── */
.cursor-pointer { cursor: pointer; }
.font-mono { font-family: monospace; }
.gap-3 { gap: 12px; }
</style>
