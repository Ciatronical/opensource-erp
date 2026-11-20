<!-- src/core/views/customer-vendor/tabs/vendor-part-sync.panel.vue -->
<!--
    Artikelabgleich am Lieferanten (Reiter „Belegabruf", unter dem Belegabruf):
    ein Knopf startet im Backend den Läufer, der Preise, Verfügbarkeit und
    Artikelangaben beim Lieferanten abholt und in parts / parts_ext übernimmt.
    Der Läufer arbeitet als eigener Prozess; diese Karte fragt den Stand ab,
    solange er läuft.

    Probelauf: Änderungen nur ermitteln, nichts schreiben.
    Debug: ausführliches Protokoll, es klappt dann von selbst auf.
    Begrenzung: nur die ersten N Artikel abrufen (leer = alle).
    Aufschlag: Verkaufspreis = gelieferter Einkaufspreis + Prozent; mit
    „Zufällig" je Artikel um ± die angegebene Spanne gestreut (je Artikel fest).
    Psychologische Preise: Bruttopreis auf eine Endung mit 8 (11,98 statt 12,01).
    Preisdatei: Excel/CSV des Lieferanten, geht mit dem Start an das Backend;
    das Abrufprogramm entscheidet anhand von Lieferant und Datei, was zu tun ist.
-->
<template>
    <section-card :title="t('VendorPartSync.title')" icon="mdi-database-sync-outline">
        <template #actions>
            <v-btn v-if="running" size="small" variant="tonal" color="error" prepend-icon="mdi-stop" :loading="cancelling" :disabled="sync?.cancel_requested" @click="cancelRun">
                {{ t('VendorPartSync.cancel') }}
            </v-btn>
            <v-btn v-else size="small" variant="tonal" color="primary" prepend-icon="mdi-cloud-sync-outline" :loading="starting" :disabled="!configured" @click="startRun">
                {{ t('VendorPartSync.start') }}
            </v-btn>
        </template>

        <v-alert v-if="loaded && !configured" type="info" variant="tonal" density="compact" class="vps-block">
            {{ t('VendorPartSync.notConfigured') }}
        </v-alert>
        <v-alert v-else-if="loaded && partsCount === 0" type="warning" variant="tonal" density="compact" class="vps-block">
            {{ t('VendorPartSync.noParts') }}
        </v-alert>

        <!-- Vorgaben für den nächsten Lauf -->
        <div class="vps-options">
            <v-switch v-model="options.dry_run" :label="t('VendorPartSync.dryRun')" color="primary" density="compact" hide-details :disabled="running" />
            <v-switch v-model="options.debug" :label="t('VendorPartSync.debug')" color="primary" density="compact" hide-details :disabled="running" />
            <v-text-field
                v-model="options.limit"
                type="number"
                min="0"
                :label="t('VendorPartSync.limit')"
                :placeholder="t('VendorPartSync.limitAll')"
                persistent-placeholder
                clearable
                class="vps-limit"
                :disabled="running"
                :rules="[limitRule]"
            />
        </div>
        <div class="vps-hint">{{ t('VendorPartSync.optionsHint') }}</div>

        <!-- Preisbildung -->
        <div class="vps-options vps-block">
            <v-text-field
                v-model="options.markup_percent"
                type="number"
                step="0.1"
                suffix="%"
                :label="t('VendorPartSync.markup')"
                :placeholder="t('VendorPartSync.markupNone')"
                persistent-placeholder
                clearable
                class="vps-limit"
                :disabled="running"
                :rules="[percentRule]"
            />
            <v-switch v-model="options.random" :label="t('VendorPartSync.random')" color="primary" density="compact" hide-details :disabled="running || !hasMarkup" />
            <v-text-field
                v-if="options.random"
                v-model="options.markup_random_percent"
                type="number"
                min="0"
                step="0.1"
                prefix="±"
                suffix="%"
                :label="t('VendorPartSync.randomRange')"
                class="vps-limit"
                :disabled="running || !hasMarkup"
                :rules="[rangeRule]"
            />
            <v-switch v-model="options.psychological_prices" :label="t('VendorPartSync.psychological')" color="primary" density="compact" hide-details :disabled="running" />
        </div>
        <div class="vps-hint">{{ t('VendorPartSync.pricingHint') }}</div>

        <!-- Preisdatei des Lieferanten -->
        <v-file-input
            v-model="priceFile"
            :label="t('VendorPartSync.file')"
            :hint="t('VendorPartSync.fileHint')"
            persistent-hint
            accept=".xlsx,.xls,.ods,.csv,.txt"
            prepend-icon="mdi-file-table-outline"
            variant="outlined"
            density="compact"
            clearable
            class="vps-block vps-file"
            :disabled="running"
        />

        <!-- Stand des laufenden bzw. letzten Laufs -->
        <div v-if="sync" class="vps-status">
            <div class="vps-status__head">
                <v-chip size="small" variant="tonal" :color="statusColor(sync.status)" :prepend-icon="statusIcon(sync.status)">
                    {{ t('VendorPartSync.status_' + sync.status) }}
                </v-chip>
                <v-chip v-if="sync.dry_run" size="small" variant="outlined">{{ t('VendorPartSync.dryRun') }}</v-chip>
                <v-chip v-if="sync.debug" size="small" variant="outlined">{{ t('VendorPartSync.debug') }}</v-chip>
                <v-chip v-if="sync.part_limit" size="small" variant="outlined">{{ t('VendorPartSync.limited', { n: sync.part_limit }) }}</v-chip>
                <v-chip v-if="sync.markup_percent !== null && sync.markup_percent !== undefined" size="small" variant="outlined">
                    {{ t('VendorPartSync.markupUsed', { p: percent(sync.markup_percent) }) }}<template v-if="sync.markup_random_percent"> ± {{ percent(sync.markup_random_percent) }}</template>
                </v-chip>
                <v-chip v-if="sync.psychological_prices" size="small" variant="outlined">{{ t('VendorPartSync.psychological') }}</v-chip>
                <v-chip v-if="sync.input_name" size="small" variant="outlined" prepend-icon="mdi-file-table-outline">{{ sync.input_name }}</v-chip>
                <span class="vps-muted">{{ runTimes }}</span>
            </div>

            <v-progress-linear
                v-if="running"
                :model-value="progress"
                :indeterminate="!sync.parts_total"
                color="primary"
                height="8"
                rounded
                class="vps-block"
            />
            <div v-if="running || sync.parts_done" class="vps-muted">
                {{ t('VendorPartSync.progress', { done: sync.parts_done, total: sync.parts_total || '?' }) }}
            </div>

            <div v-if="messageText" class="vps-message">{{ messageText }}</div>

            <div class="vps-counts">
                <span><strong>{{ sync.parts_updated }}</strong> {{ sync.dry_run ? t('VendorPartSync.wouldChange') : t('VendorPartSync.changed') }}</span>
                <span><strong>{{ sync.parts_unchanged }}</strong> {{ t('VendorPartSync.unchanged') }}</span>
                <span :class="{ 'text-warning': sync.parts_unknown > 0 }"><strong>{{ sync.parts_unknown }}</strong> {{ t('VendorPartSync.unknown') }}</span>
                <span :class="{ 'text-error': sync.parts_error > 0 }"><strong>{{ sync.parts_error }}</strong> {{ t('VendorPartSync.errors') }}</span>
            </div>

            <v-alert v-if="sync.dry_run && sync.status === 'done'" type="info" variant="tonal" density="compact" class="vps-block">
                {{ t('VendorPartSync.dryRunDone') }}
            </v-alert>

            <!-- Hängengeblieben: letzte Ausgabe des Läufers -->
            <pre v-if="output.length" class="vps-log">{{ output.join('\n') }}</pre>

            <!-- Geänderte, unbekannte und fehlerhafte Artikel -->
            <v-table v-if="items.length" density="compact" class="vps-block">
                <thead>
                    <tr>
                        <th>{{ t('VendorPartSync.partnumber') }}</th>
                        <th>{{ t('VendorPartSync.description') }}</th>
                        <th>{{ t('VendorPartSync.change') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.id">
                        <td class="text-no-wrap">
                            <router-link v-if="item.parts_id" :to="{ name: 'article-edit', params: { id: item.parts_id } }">{{ item.partnumber }}</router-link>
                            <template v-else>{{ item.partnumber }}</template>
                        </td>
                        <td>{{ item.description }}</td>
                        <td>
                            <span v-if="item.result === 'unknown'" class="text-warning">{{ t('VendorPartSync.itemUnknown') }}</span>
                            <span v-else-if="item.result === 'error'" class="text-error">{{ item.message }}</span>
                            <template v-else>
                                <div v-for="line in changeLines(item.changes)" :key="line">{{ line }}</div>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <div v-if="items.length >= 200" class="vps-muted">{{ t('VendorPartSync.itemsTruncated') }}</div>

            <!-- Protokoll -->
            <div v-if="sync.log" class="vps-block">
                <v-btn size="small" variant="text" :prepend-icon="logOpen ? 'mdi-chevron-up' : 'mdi-chevron-down'" @click="logOpen = !logOpen">
                    {{ t('VendorPartSync.log') }}
                </v-btn>
                <pre v-if="logOpen" ref="logBox" class="vps-log">{{ sync.log }}</pre>
            </div>
        </div>
        <div v-else-if="loaded" class="vps-muted">{{ t('VendorPartSync.never') }}</div>

        <!-- Frühere Läufe -->
        <div v-if="history.length > 1" class="vps-block">
            <div class="vps-subtitle">{{ t('VendorPartSync.history') }}</div>
            <v-table density="compact">
                <tbody>
                    <tr v-for="run in history.slice(1)" :key="run.id">
                        <td class="text-no-wrap">{{ formatDateTime(run.started_at) }}</td>
                        <td>
                            <v-chip size="x-small" variant="tonal" :color="statusColor(run.status)">{{ t('VendorPartSync.status_' + run.status) }}</v-chip>
                            <v-chip v-if="run.dry_run" size="x-small" variant="outlined" class="ml-1">{{ t('VendorPartSync.dryRun') }}</v-chip>
                        </td>
                        <td>{{ t('VendorPartSync.historyCounts', { done: run.parts_done, updated: run.parts_updated, unknown: run.parts_unknown, errors: run.parts_error }) }}</td>
                        <td>{{ run.employee_name }}</td>
                    </tr>
                </tbody>
            </v-table>
        </div>
    </section-card>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import SectionCard from '@/core/components/layout/section-card.vue'
import { useVendorPartSync } from '@/core/composables/useVendorPartSync.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    vendorId:   { type: Number, required: true },
    vendorName: { type: String, default: '' },
})

const { t, te, n } = useI18n()
const api = useVendorPartSync()

// Abfrageabstand, solange ein Lauf arbeitet
const POLL_MS = 2000

const loaded = ref(false)
const configured = ref(false)
const partsCount = ref(0)
const sync = ref(null)
const items = ref([])
const history = ref([])
const output = ref([])
const starting = ref(false)
const cancelling = ref(false)
const logOpen = ref(false)
const logBox = ref(null)
const options = ref({ dry_run: true, debug: false, limit: null, markup_percent: null, random: false, markup_random_percent: 1, psychological_prices: false })
const priceFile = ref(null)
// Vorgaben nur einmal aus dem letzten Lauf übernehmen, danach gilt die Eingabe
let optionsPrefilled = false

let timer = null

const running = computed(() => ['queued', 'running'].includes(sync.value?.status))
const progress = computed(() => sync.value?.parts_total ? Math.min(100, 100 * sync.value.parts_done / sync.value.parts_total) : 0)

// Bekannte Kennungen des Backends (STALLED) übersetzt, alles andere wörtlich
const messageText = computed(() => {
    const message = sync.value?.message || ''
    if (!message) return ''
    return te('VendorPartSync.msg_' + message) ? t('VendorPartSync.msg_' + message) : message
})

const runTimes = computed(() => {
    if (!sync.value) return ''
    const teile = [formatDateTime(sync.value.started_at || sync.value.itime)]
    if (sync.value.finished_at) teile.push(t('VendorPartSync.duration', { d: duration(sync.value.started_at || sync.value.itime, sync.value.finished_at) }))
    if (sync.value.employee_name) teile.push(sync.value.employee_name)
    return teile.join(' · ')
})

const hasMarkup = computed(() => options.value.markup_percent !== null && options.value.markup_percent !== '' && isFinite(Number(options.value.markup_percent)))

function percentRule(value) {
    return value === null || value === '' || isFinite(Number(value)) || t('VendorPartSync.percentInvalid')
}
function rangeRule(value) {
    return (value !== null && value !== '' && isFinite(Number(value)) && Number(value) >= 0) || t('VendorPartSync.rangeInvalid')
}
function percent(value) {
    return n(Number(value), { maximumFractionDigits: 3 }) + ' %'
}

function limitRule(value) {
    return value === null || value === '' || (Number.isInteger(Number(value)) && Number(value) >= 0) || t('VendorPartSync.limitInvalid')
}

function parseTime(d) {
    const x = new Date(String(d || '').replace(' ', 'T'))
    return isNaN(x.getTime()) ? null : x
}
function formatDateTime(d) {
    const x = parseTime(d)
    return x ? x.toLocaleDateString() + ' ' + x.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''
}
function duration(from, to) {
    const a = parseTime(from), b = parseTime(to)
    if (!a || !b) return ''
    const s = Math.max(0, Math.round((b - a) / 1000))
    return s >= 3600 ? `${Math.floor(s / 3600)}:${String(Math.floor(s % 3600 / 60)).padStart(2, '0')} h`
         : s >= 60 ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} min`
         : `${s} s`
}

function statusColor(status) {
    return { queued: 'info', running: 'info', done: 'success', error: 'error', stalled: 'error', cancelled: 'warning' }[status] || undefined
}
function statusIcon(status) {
    return { queued: 'mdi-timer-sand', running: 'mdi-sync', done: 'mdi-check', error: 'mdi-alert-circle-outline', stalled: 'mdi-alert-circle-outline', cancelled: 'mdi-stop' }[status] || undefined
}

function money(value) {
    return value === null || value === undefined ? '–' : n(Number(value), { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// Aus {"sellprice": {"old": 10, "new": 12}, "available": {...}} lesbare Zeilen
function changeLines(changes) {
    const zeilen = []
    for (const [feld, wert] of Object.entries(changes || {})) {
        if (['sellprice', 'listprice', 'lastcost'].includes(feld)) {
            const alt = Number(wert.old), neu = Number(wert.new)
            const prozent = alt ? ` (${neu >= alt ? '+' : ''}${n(100 * (neu - alt) / alt, { maximumFractionDigits: 1 })} %)` : ''
            zeilen.push(`${t('VendorPartSync.field_' + feld)}: ${money(wert.old)} → ${money(wert.new)}${prozent}`)
        } else if (feld === 'available') {
            zeilen.push(wert.new ? t('VendorPartSync.nowAvailable') : t('VendorPartSync.nowUnavailable'))
        } else if (feld.startsWith('ext_')) {
            zeilen.push(t('VendorPartSync.extChanged', { field: te('VendorPartSync.field_' + feld) ? t('VendorPartSync.field_' + feld) : feld.slice(4) }))
        } else {
            zeilen.push(`${te('VendorPartSync.field_' + feld) ? t('VendorPartSync.field_' + feld) : feld}: ${wert.old ?? '–'} → ${wert.new ?? '–'}`)
        }
    }
    return zeilen
}

async function load() {
    try {
        const stand = await api.fetchStatus(props.vendorId)
        configured.value = !!stand.configured
        partsCount.value = stand.parts_count || 0
        sync.value = stand.sync || null
        items.value = stand.items || []
        history.value = stand.history || []
        output.value = stand.output || []
        // Preisbildung des letzten Laufs als Vorgabe für den nächsten
        if (!optionsPrefilled && stand.sync) {
            optionsPrefilled = true
            if (stand.sync.markup_percent !== null && stand.sync.markup_percent !== undefined) {
                options.value.markup_percent = Number(stand.sync.markup_percent)
            }
            if (stand.sync.markup_random_percent) {
                options.value.random = true
                options.value.markup_random_percent = Number(stand.sync.markup_random_percent)
            }
            options.value.psychological_prices = !!stand.sync.psychological_prices
        }
        loaded.value = true
    } catch (e) {
        stopPolling()
        alerts.error(e.message)
    }
}

function stopPolling() {
    clearTimeout(timer)
    timer = null
}
// Solange ein Lauf arbeitet, regelmäßig nachfragen; danach hört es von selbst auf
function poll() {
    stopPolling()
    timer = setTimeout(async () => {
        await load()
        if (running.value) poll()
    }, POLL_MS)
}

async function startRun() {
    if (limitRule(options.value.limit) !== true || percentRule(options.value.markup_percent) !== true) return
    const streuen = options.value.random && hasMarkup.value
    if (streuen && rangeRule(options.value.markup_random_percent) !== true) return
    const datei = Array.isArray(priceFile.value) ? (priceFile.value[0] || null) : (priceFile.value || null)
    // Ein echter Lauf überschreibt Preise — vorher nachfragen
    if (!options.value.dry_run) {
        const antwort = await alerts.warning(t('VendorPartSync.confirmText', { name: props.vendorName }), t('VendorPartSync.confirmTitle'), t('VendorPartSync.start'), t('VendorPartSync.abort'))
        if (!antwort.isConfirmed) return
    }
    starting.value = true
    try {
        await api.start({
            vendor_id: props.vendorId,
            dry_run: options.value.dry_run,
            debug: options.value.debug,
            limit: Number(options.value.limit) || 0,
            markup_percent: hasMarkup.value ? Number(options.value.markup_percent) : null,
            markup_random_percent: streuen ? Number(options.value.markup_random_percent) : null,
            psychological_prices: options.value.psychological_prices,
        }, datei)
        // Die Datei gehört zu diesem einen Lauf
        priceFile.value = null
        // Im Debug-Lauf soll das Protokoll gleich zu sehen sein
        logOpen.value = options.value.debug
        await load()
        poll()
    } catch (e) {
        alerts.error(te('VendorPartSync.err_' + e.code) ? t('VendorPartSync.err_' + e.code) : e.message)
        await load()
    } finally {
        starting.value = false
    }
}

async function cancelRun() {
    if (!sync.value) return
    cancelling.value = true
    try {
        await api.cancel(sync.value.id)
        await load()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        cancelling.value = false
    }
}

// Protokoll am Ende halten, solange der Lauf schreibt
watch(() => sync.value?.log, async () => {
    if (!logOpen.value || !running.value) return
    await nextTick()
    if (logBox.value) logBox.value.scrollTop = logBox.value.scrollHeight
})

// Anderer Lieferant in derselben Ansicht: Stand neu holen
watch(() => props.vendorId, async () => {
    stopPolling()
    loaded.value = false
    sync.value = null
    optionsPrefilled = false
    priceFile.value = null
    await load()
    if (running.value) poll()
})

onMounted(async () => {
    await load()
    if (running.value) poll()
})
onBeforeUnmount(stopPolling)
</script>

<style scoped>
.vps-block {
    margin-top: var(--oserp-gap);
}
.vps-options {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--oserp-gap-sm) var(--oserp-gap-xl);
}
.vps-limit {
    flex: 0 0 14rem;
}
.vps-file {
    max-width: 32rem;
}
.vps-hint,
.vps-muted {
    color: var(--oserp-text-muted);
    font-size: 0.8125rem;
}
.vps-hint {
    margin-top: var(--oserp-gap-xs);
}
.vps-status {
    margin-top: var(--oserp-gap-lg);
    padding-top: var(--oserp-gap);
    border-top: 1px solid var(--oserp-border-soft);
}
.vps-status__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--oserp-gap-sm);
}
.vps-message {
    margin-top: var(--oserp-gap-sm);
}
.vps-counts {
    display: flex;
    flex-wrap: wrap;
    gap: var(--oserp-gap-xs) var(--oserp-gap-lg);
    margin-top: var(--oserp-gap-sm);
}
.vps-subtitle {
    font-weight: var(--oserp-section-title-weight);
    margin-bottom: var(--oserp-gap-xs);
}
.vps-log {
    max-height: 22rem;
    overflow: auto;
    margin-top: var(--oserp-gap-xs);
    padding: var(--oserp-gap-sm);
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius-sm);
    background: var(--oserp-surface-soft);
    font-size: 0.75rem;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
