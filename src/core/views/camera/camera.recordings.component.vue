<!-- src/core/views/camera/camera.recordings.component.vue -->
<!--
    Aufzeichnungen: gespeicherte Clips eines Zeitraums ansehen.

    Der camera-monitor zeichnet keine Dauervideos auf, sondern einen Clip je
    erkannter Szene. Diese Ansicht reiht die Clips eines Zeitraums auf einer
    Zeitleiste (eine Spur je Kamera) auf und spielt sie nacheinander ab — so
    lässt sich z. B. ein Vormittag am Stück durchsehen.
-->
<template>
    <div class="rec">
        <section-card :title="t('cam.archive')" icon="mdi-filmstrip-box-multiple" :subtitle="summary">
            <v-row density="compact" align="center">
                <v-col cols="12" sm="4" md="3">
                    <v-text-field
                        v-model="filter.date" type="date" :label="t('cam.recDate')"
                        prepend-inner-icon="mdi-chevron-left" append-inner-icon="mdi-chevron-right"
                        @click:prepend-inner="shiftDay(-1)" @click:append-inner="shiftDay(1)"
                        @keyup.enter="loadRecordings"
                    />
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-text-field v-model="filter.time_from" type="time" :label="t('cam.dateFrom')" @keyup.enter="loadRecordings" />
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-text-field v-model="filter.time_to" type="time" :label="t('cam.dateTo')" @keyup.enter="loadRecordings" />
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-select v-model="filter.camera_id" :items="cameraOptions" :label="t('cam.filterCamera')" clearable />
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-select v-model="filter.label" :items="labelOptions" :label="t('cam.filterLabel')" clearable />
                </v-col>
                <v-col cols="12" sm="4" md="1">
                    <v-btn color="primary" block :loading="loading" @click="loadRecordings">{{ t('cam.recShow') }}</v-btn>
                </v-col>
            </v-row>
        </section-card>

        <div v-if="loaded && recordings.length === 0" class="rec-empty">
            <v-icon size="64">mdi-filmstrip-off</v-icon>
            <div class="rec-empty__title">{{ t('cam.recNoneTitle') }}</div>
            <div>{{ t('cam.recNoneHint') }}</div>
        </div>

        <template v-if="recordings.length > 0">
            <!-- Zeitleiste: eine Spur je Kamera -->
            <section-card :title="t('cam.recTimeline')" icon="mdi-timeline-clock-outline" :subtitle="t('cam.recTimelineHint')">
                <div class="rec-tl">
                    <div
                        v-for="lane in lanes" :key="lane.camera_id" class="rec-lane"
                        :class="{
                            'rec-lane--active': current && current.camera_id === lane.camera_id,
                            'rec-lane--muted': solo_camera_id !== null && solo_camera_id !== lane.camera_id,
                        }"
                    >
                        <button type="button" class="rec-lane__name" :title="t('cam.recSoloHint')" @click="toggleSolo(lane.camera_id)">
                            <v-icon size="x-small">{{ solo_camera_id === lane.camera_id ? 'mdi-radiobox-marked' : 'mdi-radiobox-blank' }}</v-icon>
                            <span class="rec-lane__label">{{ lane.camera_name }}</span>
                            <span class="rec-lane__count">{{ lane.count }}</span>
                        </button>
                        <div class="rec-lane__track" @click="onTrackClick($event, lane.camera_id)" @mousemove="onTrackHover" @mouseleave="hover = null">
                            <span v-for="(block, i) in lane.blocks" :key="i" class="rec-block" :style="{ left: block.left + '%', width: block.width + '%' }" />
                            <span
                                v-if="current && current.camera_id === lane.camera_id && playhead_percent !== null"
                                class="rec-playhead" :style="{ left: playhead_percent + '%' }"
                            />
                        </div>
                    </div>
                    <div class="rec-lane rec-lane--axis">
                        <span class="rec-lane__name" />
                        <div class="rec-axis">
                            <span v-for="tick in ticks" :key="tick.ms" class="rec-axis__tick" :class="{ 'rec-axis__tick--end': tick.percent > 97 }" :style="{ left: tick.percent + '%' }">{{ tick.text }}</span>
                            <span v-if="hover" class="rec-axis__hover" :style="{ left: hover.percent + '%' }">{{ hover.text }}</span>
                        </div>
                    </div>
                </div>
            </section-card>

            <v-row density="compact">
                <!-- Player -->
                <v-col cols="12" md="8">
                    <section-card plain>
                        <div class="rec-player">
                            <video
                                v-if="current" ref="video_el" class="rec-video" :src="mediaUrl(current, 'clip')"
                                controls autoplay playsinline
                                @loadedmetadata="applyRate" @timeupdate="onTimeUpdate"
                                @ended="onEnded" @error="onVideoError" @playing="error_streak = 0"
                            />
                            <div v-else class="rec-video rec-video--idle">
                                <v-icon size="48">mdi-play-circle-outline</v-icon>
                                <div>{{ t('cam.recPickClip') }}</div>
                            </div>
                        </div>
                        <div class="rec-bar">
                            <v-btn icon="mdi-skip-previous" variant="text" size="small" :disabled="current_index <= 0" :title="t('cam.recPrev')" @click="step(-1)" />
                            <v-btn icon="mdi-skip-next" variant="text" size="small" :disabled="current_index < 0 || current_index >= playlist.length - 1" :title="t('cam.recNext')" @click="step(1)" />
                            <div v-if="current" class="rec-bar__info">
                                <strong>{{ current.camera_name }}</strong>
                                <span class="rec-bar__time">{{ formatClock(playhead_ms ?? current.start_ms) }}</span>
                                <span class="rec-muted">{{ t('cam.recPosition', { index: current_index + 1, total: playlist.length }) }}</span>
                            </div>
                            <v-spacer />
                            <v-btn-toggle v-model="playback_rate" mandatory density="compact" variant="outlined" divided :title="t('cam.recSpeed')">
                                <v-btn v-for="rate in rates" :key="rate" :value="rate" size="small">{{ rate }}×</v-btn>
                            </v-btn-toggle>
                            <v-btn
                                :icon="auto_next ? 'mdi-playlist-play' : 'mdi-playlist-remove'" variant="text" size="small"
                                :color="auto_next ? 'primary' : undefined" :title="t('cam.recAutoNext')" @click="auto_next = !auto_next"
                            />
                            <v-btn
                                v-if="current" icon="mdi-download" variant="text" size="small" :title="t('cam.recDownload')"
                                :href="mediaUrl(current, 'clip')" :download="downloadName(current)"
                            />
                        </div>
                    </section-card>
                </v-col>

                <!-- Clip-Liste -->
                <v-col cols="12" md="4">
                    <section-card plain>
                        <v-virtual-scroll ref="list_el" :items="playlist" :item-height="64" class="rec-list">
                            <template #default="{ item }">
                                <div class="rec-item" :class="{ 'rec-item--active': item === current }" @click="play(item)">
                                    <img
                                        v-if="item.has_snapshot && snapshots_available && !missing_snapshots.has(item.event_id)" class="rec-item__thumb"
                                        :src="mediaUrl(item, 'snapshot')" loading="lazy" alt=""
                                        @load="snapshots_loaded++" @error="missing_snapshots.add(item.event_id)"
                                    >
                                    <span v-else class="rec-item__thumb rec-item__thumb--none"><v-icon size="small">mdi-image-off-outline</v-icon></span>
                                    <div class="rec-item__text">
                                        <div class="rec-item__time">
                                            {{ formatClock(item.start_ms) }}
                                            <span class="rec-muted">· {{ formatSeconds(item.duration) }}</span>
                                        </div>
                                        <div class="rec-item__cam">{{ item.camera_name }}</div>
                                    </div>
                                    <div class="rec-item__labels">
                                        <v-icon
                                            v-for="label in item.label_list" :key="label" size="small"
                                            :color="labelColor(label)" :title="t('cam.labels.' + label, label)"
                                        >{{ labelIcon(label) }}</v-icon>
                                    </div>
                                </div>
                            </template>
                        </v-virtual-scroll>
                    </section-card>
                </v-col>
            </v-row>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, shallowRef, nextTick, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import SectionCard from '@/core/components/layout/section-card.vue'
import * as toast from '@/core/utils/toasts.js'
import { labelColor, labelIcon } from './camera.labels.js'

const props = defineProps({
    // Kameras aus der Elternansicht (dort bereits geladen)
    cameras: { type: Array, default: () => [] },
})

const { t, locale } = useI18n()

const rates = [1, 2, 4, 8]
// Schrittweiten der Zeitachse in Minuten — gewählt wird die kleinste, die höchstens 12 Marken ergibt
const tick_steps = [1, 2, 5, 10, 15, 30, 60, 120, 180, 240, 360]

const filter = reactive({
    date: toDateInput(new Date()),
    time_from: '00:00',
    time_to: '23:59',
    camera_id: null,
    label: null,
})

const recordings = shallowRef([])
const range = reactive({ from_ms: 0, to_ms: 0 })   // Zeitraum der geladenen Daten
const loading = ref(false)
const loaded = ref(false)

const current = shallowRef(null)
const solo_camera_id = ref(null)
const playback_rate = ref(1)
const auto_next = ref(true)
const playhead_ms = ref(null)
const hover = ref(null)
const error_streak = ref(0)
const missing_snapshots = reactive(new Set())   // Vorschaubilder, deren Datei fehlt
const snapshots_loaded = ref(0)
// Fehlen die Vorschaubilder des Zeitraums offenbar ganz (aufgeräumt, während
// die Clips noch da sind), nicht für jede Zeile der Liste erneut anfragen —
// jeder Abruf belegt einen PHP-Arbeitsprozess.
const snapshots_available = computed(() => snapshots_loaded.value > 0 || missing_snapshots.size < 3)
const video_el = ref(null)
const list_el = ref(null)

// ── Abgeleitete Daten ──────────────────────────────────────────────────────

const cameraOptions = computed(() => props.cameras.map(c => ({ title: c.name, value: c.id })))

const labelOptions = computed(() =>
    ['person', 'car', 'truck', 'bus', 'motorcycle', 'bicycle', 'dog', 'cat', 'bird', 'horse']
        .map(label => ({ title: t('cam.labels.' + label), value: label }))
)

const playlist = computed(() =>
    solo_camera_id.value === null
        ? recordings.value
        : recordings.value.filter(r => r.camera_id === solo_camera_id.value)
)

const current_index = computed(() => current.value ? playlist.value.indexOf(current.value) : -1)

const summary = computed(() => {
    if (!loaded.value || recordings.value.length === 0) return ''
    const seconds = recordings.value.reduce((sum, r) => sum + r.duration, 0)
    return t('cam.recSummary', { count: recordings.value.length, duration: formatSeconds(seconds) })
})

// Spuren: je Kamera die Clips als Blöcke in Prozent des Zeitraums. Dicht
// aufeinander folgende Clips verschmelzen zu einem Block, damit bei mehreren
// tausend Clips nicht ebenso viele Elemente entstehen.
const lanes = computed(() => {
    const span = range.to_ms - range.from_ms
    if (span <= 0) return []
    const merge_gap = span / 1200
    const map = new Map()
    for (const r of recordings.value) {
        let lane = map.get(r.camera_id)
        if (!lane) {
            lane = { camera_id: r.camera_id, camera_name: r.camera_name, count: 0, spans: [] }
            map.set(r.camera_id, lane)
        }
        lane.count++
        const start = Math.max(r.start_ms, range.from_ms)
        const end = Math.min(r.end_ms, range.to_ms)
        const last = lane.spans[lane.spans.length - 1]
        if (last && start - last.end <= merge_gap) last.end = Math.max(last.end, end)
        else lane.spans.push({ start, end })
    }
    // Reihenfolge wie in der Kameraliste; Kameras, die es nicht mehr gibt, ans Ende
    const position = id => {
        const index = props.cameras.findIndex(c => c.id === id)
        return index < 0 ? props.cameras.length : index
    }
    return [...map.values()].sort((a, b) => position(a.camera_id) - position(b.camera_id)).map(lane => ({
        ...lane,
        blocks: lane.spans.map(s => ({
            left: (s.start - range.from_ms) / span * 100,
            width: Math.max(s.end - s.start, 0) / span * 100,
        })),
    }))
})

const ticks = computed(() => {
    const span = range.to_ms - range.from_ms
    if (span <= 0) return []
    const step = (tick_steps.find(s => span / (s * 60000) <= 12) || 720) * 60000
    const midnight = new Date(range.from_ms).setHours(0, 0, 0, 0)
    const list = []
    for (let ms = midnight + Math.ceil((range.from_ms - midnight) / step) * step; ms <= range.to_ms; ms += step) {
        list.push({ ms, percent: (ms - range.from_ms) / span * 100, text: formatClock(ms, false) })
    }
    return list
})

const playhead_percent = computed(() => {
    const span = range.to_ms - range.from_ms
    if (playhead_ms.value === null || span <= 0) return null
    return Math.min(100, Math.max(0, (playhead_ms.value - range.from_ms) / span * 100))
})

// ── Laden ──────────────────────────────────────────────────────────────────

async function loadRecordings() {
    if (!filter.date || !filter.time_from || !filter.time_to || filter.time_to <= filter.time_from) {
        toast.error(t('cam.recInvalidRange'))
        return
    }
    const date_from = `${filter.date} ${filter.time_from}`
    const date_to = `${filter.date} ${filter.time_to}`
    loading.value = true
    try {
        const res = await axios.post('/api/camera/', {
            action: 'getRecordings', date_from, date_to,
            camera_id: filter.camera_id, label: filter.label,
        })
        if (!res.data.success) throw new Error(res.data.text || '')
        // Zeitstempel kommen als Serverzeit ohne Zone und werden als Ortszeit gelesen
        recordings.value = res.data.payload.recordings.map(r => {
            const start_ms = toMs(r.started_at)
            const end_ms = toMs(r.ended_at)
            return {
                ...r,
                start_ms, end_ms,
                duration: Math.max(0, (end_ms - start_ms) / 1000),
                clip_start_ms: start_ms - Number(r.pre_record_secs) * 1000,
                label_list: (r.labels || '').split(',').filter(Boolean),
            }
        })
        range.from_ms = toMs(date_from)
        range.to_ms = toMs(date_to)
        solo_camera_id.value = null
        missing_snapshots.clear()
        snapshots_loaded.value = 0
        loaded.value = true
        play(recordings.value[0] || null)
    } catch (e) {
        console.error('getRecordings:', e)
        toast.error(t('cam.recLoadError'))
    }
    loading.value = false
}

function shiftDay(days) {
    const d = new Date(filter.date + 'T12:00:00')
    if (isNaN(d)) return
    d.setDate(d.getDate() + days)
    filter.date = toDateInput(d)
    loadRecordings()
}

// ── Wiedergabe ─────────────────────────────────────────────────────────────

function play(recording) {
    current.value = recording
    playhead_ms.value = recording ? recording.start_ms : null
}

function step(direction) {
    const next = playlist.value[current_index.value + direction]
    if (next) play(next)
}

function applyRate() {
    if (video_el.value) video_el.value.playbackRate = playback_rate.value
}

function onTimeUpdate() {
    if (current.value && video_el.value)
        playhead_ms.value = current.value.clip_start_ms + video_el.value.currentTime * 1000
}

function onEnded() {
    if (auto_next.value) step(1)
}

// Fehlende oder defekte Datei überspringen — aber nicht endlos, falls gar
// kein Clip mehr lesbar ist (z. B. Speicher nicht eingehängt).
function onVideoError() {
    error_streak.value++
    if (error_streak.value >= 5) {
        toast.error(t('cam.recPlayError'))
        error_streak.value = 0
        return
    }
    if (auto_next.value) step(1)
}

function toggleSolo(camera_id) {
    solo_camera_id.value = solo_camera_id.value === camera_id ? null : camera_id
    if (solo_camera_id.value !== null && current.value?.camera_id !== camera_id) {
        // An der bisherigen Stelle in der gewählten Kamera weitermachen
        playFrom(camera_id, playhead_ms.value ?? range.from_ms)
    }
}

function trackPosition(evt) {
    const rect = evt.currentTarget.getBoundingClientRect()
    const ratio = Math.min(1, Math.max(0, (evt.clientX - rect.left) / rect.width))
    return { ratio, ms: range.from_ms + ratio * (range.to_ms - range.from_ms) }
}

function onTrackClick(evt, camera_id) {
    if (solo_camera_id.value !== null && solo_camera_id.value !== camera_id) solo_camera_id.value = camera_id
    playFrom(camera_id, trackPosition(evt).ms)
}

function onTrackHover(evt) {
    const pos = trackPosition(evt)
    hover.value = { percent: pos.ratio * 100, text: formatClock(pos.ms) }
}

// Ersten Clip der Kamera abspielen, der zum Zeitpunkt läuft oder danach beginnt
function playFrom(camera_id, ms) {
    const of_camera = recordings.value.filter(r => r.camera_id === camera_id)
    const hit = of_camera.find(r => r.end_ms >= ms) || of_camera[of_camera.length - 1]
    if (hit) play(hit)
}

watch(playback_rate, applyRate)

watch(current, async () => {
    await nextTick()
    if (current_index.value >= 0) list_el.value?.scrollToIndex(Math.max(0, current_index.value - 2))
})

// ── Helfer ─────────────────────────────────────────────────────────────────

function mediaUrl(recording, type) {
    return `/api/camera/media.php?type=${type}&event=${encodeURIComponent(recording.event_id)}`
}

function downloadName(recording) {
    const stamp = recording.started_at.slice(0, 19).replace(/[: ]/g, '-')
    return `${recording.camera_name}_${stamp}.mp4`.replace(/[^\w.\-]+/g, '_')
}

function toMs(text) {
    return new Date(String(text).replace(' ', 'T').slice(0, 23)).getTime()
}

function toDateInput(d) {
    const pad = n => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

function formatClock(ms, with_seconds = true) {
    return new Date(ms).toLocaleTimeString(locale.value, {
        hour: '2-digit', minute: '2-digit', ...(with_seconds ? { second: '2-digit' } : {}),
    })
}

function formatSeconds(seconds) {
    const s = Math.round(seconds)
    const pad = n => String(n).padStart(2, '0')
    const h = Math.floor(s / 3600)
    return (h > 0 ? h + ':' + pad(Math.floor(s % 3600 / 60)) : Math.floor(s / 60)) + ':' + pad(s % 60)
}

onMounted(loadRecordings)
</script>

<style scoped>
.rec {
    display: flex;
    flex-direction: column;
    gap: var(--oserp-gap-lg);
}
.rec-muted { color: var(--oserp-text-muted); }

.rec-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--oserp-gap-sm);
    padding: var(--oserp-gap-xl);
    color: var(--oserp-text-muted);
    text-align: center;
}
.rec-empty__title {
    font-size: var(--oserp-section-title-size);
    font-weight: var(--oserp-section-title-weight);
}

/* ── Zeitleiste ── */
.rec-tl {
    display: flex;
    flex-direction: column;
    gap: var(--oserp-gap-xs);
}
.rec-lane {
    display: flex;
    align-items: center;
    gap: var(--oserp-gap-sm);
}
.rec-lane--muted { opacity: 0.4; }
.rec-lane__name {
    display: flex;
    align-items: center;
    gap: var(--oserp-gap-xs);
    flex: 0 0 11rem;
    min-width: 0;
    font-size: 0.8125rem;
    text-align: left;
}
button.rec-lane__name { cursor: pointer; }
.rec-lane--active .rec-lane__name { color: var(--oserp-primary); font-weight: 600; }
.rec-lane__label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.rec-lane__count { color: var(--oserp-text-muted); font-weight: 400; }
.rec-lane__track {
    position: relative;
    flex: 1;
    height: 1.5rem;
    overflow: hidden;
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius-sm);
    background: var(--oserp-surface-soft);
    cursor: pointer;
}
.rec-block {
    position: absolute;
    top: 0;
    bottom: 0;
    min-width: 2px;
    background: var(--oserp-primary);
    opacity: 0.75;
    pointer-events: none;
}
.rec-playhead {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    margin-left: -1px;
    background: rgb(var(--v-theme-error));
    pointer-events: none;
}
.rec-axis {
    position: relative;
    flex: 1;
    height: 1.25rem;
    font-size: 0.75rem;
    color: var(--oserp-text-muted);
}
.rec-axis__tick,
.rec-axis__hover {
    position: absolute;
    top: 0;
    transform: translateX(-50%);
    white-space: nowrap;
}
.rec-axis__tick--end { transform: translateX(-100%); }
.rec-axis__hover {
    padding: 0 var(--oserp-gap-xs);
    border-radius: var(--oserp-radius-sm);
    background: var(--oserp-primary);
    color: var(--oserp-on-primary);
    z-index: 1;
}

/* ── Player ── */
.rec-player { background: #000; }
.rec-video {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 9;
    max-height: 70vh;
    background: #000;
}
.rec-video--idle {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--oserp-gap-sm);
    color: rgba(255, 255, 255, 0.7);
}
.rec-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
    padding: var(--oserp-gap-sm) var(--oserp-gap);
}
.rec-bar__info {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: var(--oserp-gap);
    font-size: 0.875rem;
}
.rec-bar__time { font-variant-numeric: tabular-nums; }

/* ── Liste ── */
.rec-list { height: min(70vh, 34rem); }
.rec-item {
    display: flex;
    align-items: center;
    gap: var(--oserp-gap);
    height: 64px;
    padding: 0 var(--oserp-gap);
    border-bottom: 1px solid var(--oserp-border-soft);
    cursor: pointer;
}
.rec-item:hover { background: var(--oserp-primary-faint); }
.rec-item--active { background: var(--oserp-primary-soft); }
.rec-item__thumb {
    flex: 0 0 auto;
    width: 80px;
    height: 45px;
    object-fit: cover;
    border-radius: var(--oserp-radius-sm);
    background: var(--oserp-surface-soft);
}
.rec-item__thumb--none {
    display: flex;
    align-items: center;
    justify-content: center;
}
.rec-item__text { flex: 1; min-width: 0; }
.rec-item__time { font-size: 0.875rem; font-variant-numeric: tabular-nums; }
.rec-item__cam {
    overflow: hidden;
    font-size: 0.75rem;
    color: var(--oserp-text-muted);
    text-overflow: ellipsis;
    white-space: nowrap;
}
.rec-item__labels { display: flex; gap: 2px; }
</style>
