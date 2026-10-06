<!-- src/core/views/config/tabs/beleg-quellen.config.vue -->
<!--
    Belegsuche: Quellen verwalten (Postfach per IMAP, WhatsApp-Eingang, Ordner,
    Lieferanten-Portal mit Login). Passwoerter gehen nur beim Speichern zum
    Server und kommen nie zurueck (has_secret).
-->
<template>
    <v-card variant="outlined" rounded="lg">
        <v-card-title class="text-body-1 font-weight-semibold d-flex align-center">
            <v-icon start size="small">{{ vendorId ? 'mdi-cloud-download-outline' : 'mdi-magnify-scan' }}</v-icon>
            {{ vendorId ? t('crm_fields.belegQuellen.vendorTitle') : t('crm_fields.belegQuellen.title') }}
            <v-spacer />
            <v-btn v-if="!vendorId" size="small" variant="tonal" color="primary" prepend-icon="mdi-play" :loading="running" class="mr-2" @click="runAll">
                {{ t('crm_fields.belegQuellen.runAll') }}
            </v-btn>
            <v-btn size="small" variant="tonal" :color="vendorId ? 'primary' : undefined" prepend-icon="mdi-plus" @click="openEditor()">
                {{ vendorId ? t('crm_fields.belegQuellen.addPortal') : t('crm_fields.belegQuellen.add') }}
            </v-btn>
        </v-card-title>
        <v-card-text>
            <p class="text-body-2 text-medium-emphasis mb-3">{{ vendorId ? t('crm_fields.belegQuellen.vendorIntro', { name: vendorName }) : t('crm_fields.belegQuellen.intro') }}</p>

            <!-- Lieferant ohne Portal: Hinweis statt leerer Tabelle -->
            <v-alert v-if="vendorId && sources.length === 0" type="info" variant="tonal" density="compact" class="mb-3">
                {{ t('crm_fields.belegQuellen.vendorNone') }}
            </v-alert>

            <v-table v-if="sources.length > 0" density="compact" class="mb-3">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th>{{ t('crm_fields.belegQuellen.name') }}</th>
                        <th>{{ t('crm_fields.belegQuellen.type') }}</th>
                        <th>{{ t('crm_fields.belegQuellen.lastRun') }}</th>
                        <th style="width:150px"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in sources" :key="s.id" :class="{ 'text-medium-emphasis': !s.active }">
                        <td><v-icon size="small">{{ icon(s.type) }}</v-icon></td>
                        <td>
                            <div class="font-weight-medium">{{ s.name }}</div>
                            <div class="text-caption text-medium-emphasis">{{ detail(s) }}</div>
                        </td>
                        <td class="text-no-wrap">{{ t('crm_fields.belegQuellen.type_' + s.type) }}</td>
                        <td class="text-no-wrap">
                            <template v-if="s.last_run_at">
                                {{ formatDateTime(s.last_run_at) }}
                                <v-chip size="x-small" variant="tonal" :color="s.last_status === 'ok' ? 'success' : (s.last_status === 'error' ? 'error' : 'warning')" class="ml-1" :title="s.last_message || ''">
                                    {{ t('crm_fields.belegQuellen.status_' + (s.last_status || 'ok')) }}
                                </v-chip>
                                <span class="text-caption text-medium-emphasis ml-1">{{ t('crm_fields.belegQuellen.found', { n: s.imported_total }) }}</span>
                            </template>
                            <span v-else class="text-caption text-medium-emphasis">{{ t('crm_fields.belegQuellen.never') }}</span>
                        </td>
                        <td class="text-no-wrap">
                            <v-btn icon="mdi-connection" size="x-small" variant="text" :title="t('crm_fields.belegQuellen.test')" :loading="testing === s.id" @click="testSource(s)" />
                            <v-btn icon="mdi-play" size="x-small" variant="text" :title="t('crm_fields.belegQuellen.run')" :loading="runningId === s.id" @click="runOne(s)" />
                            <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" @click="openEditor(s)" />
                            <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error" @click="remove(s)" />
                        </td>
                    </tr>
                </tbody>
            </v-table>

            <div v-if="settings.cron_line && !vendorId" class="text-caption text-medium-emphasis">
                <div class="font-weight-medium">{{ t('crm_fields.belegQuellen.cronTitle') }}</div>
                <div>{{ t('crm_fields.belegQuellen.cronHint') }}</div>
                <code class="d-block mt-1 pa-2 rounded bg-grey-lighten-4" style="white-space:pre-wrap">{{ settings.cron_line }}</code>
            </div>
        </v-card-text>

        <!-- Editor -->
        <v-dialog v-model="editorOpen" max-width="620">
            <v-card>
                <v-card-title>{{ form.id ? t('crm_fields.belegQuellen.edit') : t('crm_fields.belegQuellen.add') }}</v-card-title>
                <v-card-text>
                    <v-select v-model="form.type" :items="typeItems" :label="t('crm_fields.belegQuellen.type')" density="compact" :disabled="!!form.id" class="mb-2" />
                    <v-text-field v-model="form.name" :label="t('crm_fields.belegQuellen.name')" density="compact" class="mb-2" />
                    <v-switch v-model="form.active" :label="t('crm_fields.belegQuellen.active')" color="primary" density="compact" hide-details class="mb-2" />

                    <template v-if="form.type === 'imap'">
                        <v-switch v-model="form.config.use_company_mailbox" :label="t('crm_fields.belegQuellen.useCompanyMailbox')" color="primary" density="compact" hide-details class="mb-2" />
                        <template v-if="!form.config.use_company_mailbox">
                            <v-row dense>
                                <v-col cols="8"><v-text-field v-model="form.config.host" :label="t('crm_fields.belegQuellen.host')" density="compact" /></v-col>
                                <v-col cols="4"><v-text-field v-model="form.config.port" :label="t('crm_fields.belegQuellen.port')" density="compact" type="number" /></v-col>
                            </v-row>
                            <v-select v-model="form.config.encryption" :items="['ssl', 'starttls', 'none']" :label="t('crm_fields.belegQuellen.encryption')" density="compact" />
                            <v-text-field v-model="form.config.username" :label="t('crm_fields.belegQuellen.username')" density="compact" />
                            <v-text-field v-model="form.secret" :label="form.id && form.has_secret ? t('crm_fields.belegQuellen.passwordKeep') : t('crm_fields.belegQuellen.password')" type="password" density="compact" autocomplete="new-password" />
                        </template>
                        <v-text-field v-model="form.config.folder" :label="t('crm_fields.belegQuellen.folder')" density="compact" placeholder="INBOX" />
                    </template>

                    <template v-else-if="form.type === 'folder'">
                        <v-text-field v-model="form.config.path" :label="t('crm_fields.belegQuellen.path')" :hint="t('crm_fields.belegQuellen.pathHint')" persistent-hint density="compact" class="mb-2" />
                        <v-switch v-model="form.config.move_processed" :label="t('crm_fields.belegQuellen.moveProcessed')" color="primary" density="compact" hide-details />
                        <v-switch v-model="form.config.recursive" :label="t('crm_fields.belegQuellen.recursive')" color="primary" density="compact" hide-details />
                    </template>

                    <template v-else-if="form.type === 'portal'">
                        <v-autocomplete v-model="form.vendor_id" :items="vendors" item-title="name" item-value="id" :label="t('crm_fields.belegQuellen.vendor')" :hint="t('crm_fields.belegQuellen.vendorHint')" persistent-hint density="compact" class="mb-2" :loading="vendorLoading" @update:search="searchVendors" />
                        <v-text-field v-model="form.config.url" :label="t('crm_fields.belegQuellen.url')" density="compact" placeholder="https://" />
                        <v-text-field v-model="form.config.username" :label="t('crm_fields.belegQuellen.username')" density="compact" />
                        <v-text-field v-model="form.secret" :label="form.id && form.has_secret ? t('crm_fields.belegQuellen.passwordKeep') : t('crm_fields.belegQuellen.password')" type="password" density="compact" autocomplete="new-password" />
                        <v-switch v-model="form.config.download_all" :label="t('crm_fields.belegQuellen.downloadAll')" color="primary" density="compact" hide-details class="mb-2" />

                        <!-- Aufnahme: einmal vormachen (Chrome-Recorder), danach automatisch -->
                        <v-card variant="tonal" color="primary" rounded="lg" class="mt-2">
                            <v-card-text class="text-caption">
                                <div class="font-weight-medium text-body-2 mb-1">
                                    <v-icon size="small" start>mdi-record-rec</v-icon>{{ t('crm_fields.belegQuellen.recording.title') }}
                                </div>
                                <div v-if="form.has_recording" class="mb-2">
                                    <v-chip size="small" color="success" variant="flat" prepend-icon="mdi-check">{{ t('crm_fields.belegQuellen.recording.present', { steps: form.config.recording_steps, at: form.config.recording_at }) }}</v-chip>
                                </div>
                                <div v-else class="mb-2 text-warning">{{ t('crm_fields.belegQuellen.recording.missing') }}</div>
                                <ol class="pl-4 mb-2">
                                    <li v-for="n in 6" :key="n">{{ t('crm_fields.belegQuellen.recording.step' + n) }}</li>
                                </ol>
                                <v-file-input
                                    v-model="recordingFile"
                                    :label="t('crm_fields.belegQuellen.recording.file')"
                                    accept=".json,application/json"
                                    density="compact"
                                    prepend-icon="mdi-file-code-outline"
                                    :disabled="!form.id"
                                    :hint="form.id ? '' : t('crm_fields.belegQuellen.recording.saveFirst')"
                                    persistent-hint
                                    @update:model-value="uploadRecording"
                                />
                                <div class="text-caption text-medium-emphasis mt-1">{{ t('crm_fields.belegQuellen.recording.privacy') }}</div>
                            </v-card-text>
                        </v-card>
                    </template>

                    <v-alert v-if="testResult" :type="testResult.ok ? 'success' : 'warning'" variant="tonal" density="compact" class="mt-3 text-caption">{{ testResult.message }}</v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-btn variant="text" :loading="testing === 'form'" @click="testForm">{{ t('crm_fields.belegQuellen.test') }}</v-btn>
                    <v-spacer />
                    <v-btn variant="text" @click="editorOpen = false">{{ t('crm_fields.belegQuellen.cancel') }}</v-btn>
                    <v-btn color="primary" variant="tonal" :loading="saving" @click="save">{{ t('crm_fields.belegQuellen.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-card>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { useMagicBooking } from '@/features/accounting/composables/useMagicBooking.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    // Am Lieferanten (Reiter "Belegabruf"): nur dessen Portale, neue Quelle = Portal fuer ihn
    vendorId:   { type: Number, default: null },
    vendorName: { type: String, default: '' },
})

const { t } = useI18n()
const magic = useMagicBooking()

const sources = ref([])
const settings = ref({})
const editorOpen = ref(false)
const form = ref(blank())
const saving = ref(false)
const testing = ref(null)
const testResult = ref(null)
const running = ref(false)
const runningId = ref(null)
const vendors = ref([])
const vendorLoading = ref(false)
const recordingFile = ref(null)

// Chrome-Recorder-Export hochladen; das Passwort geht mit, damit der Server
// es in der Aufnahme erkennen und durch den Platzhalter ersetzen kann.
async function uploadRecording(file) {
    const f = Array.isArray(file) ? file[0] : file
    if (!f || !form.value.id) return
    try {
        const text = await f.text()
        const r = await magic.saveRecording({ id: form.value.id, recording: text, secret: form.value.secret || '' })
        form.value.has_recording = true
        form.value.config.recording_steps = r.steps
        form.value.config.recording_at = new Date().toISOString().slice(0, 16).replace('T', ' ')
        alerts.success(t('crm_fields.belegQuellen.recording.saved', { steps: r.steps }))
        testResult.value = null
        await load()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        recordingFile.value = null
    }
}

const typeItems = computed(() => ['imap', 'folder', 'whatsapp', 'portal'].map(v => ({ value: v, title: t('crm_fields.belegQuellen.type_' + v) })))

function blank() {
    return { id: null, type: 'imap', name: '', active: true, has_secret: false, secret: '', vendor_id: null,
             config: { use_company_mailbox: false, host: '', port: 993, encryption: 'ssl', username: '', folder: 'INBOX', path: '', move_processed: true, recursive: false, url: '', download_all: true } }
}
function icon(type) {
    return { imap: 'mdi-email-outline', whatsapp: 'mdi-whatsapp', folder: 'mdi-folder-outline', portal: 'mdi-web' }[type] || 'mdi-help'
}
function detail(s) {
    const c = s.config || {}
    if (s.type === 'imap') return c.use_company_mailbox ? t('crm_fields.belegQuellen.useCompanyMailbox') : `${c.username || ''}@${c.host || ''} · ${c.folder || 'INBOX'}`
    if (s.type === 'folder') return c.path || ''
    if (s.type === 'portal') return [s.vendor_name, c.url, s.has_recording ? t('crm_fields.belegQuellen.recording.short', { steps: c.recording_steps }) : t('crm_fields.belegQuellen.recording.none')].filter(Boolean).join(' · ')
    return ''
}
function formatDateTime(d) {
    const x = new Date(String(d).replace(' ', 'T'))
    return isNaN(x.getTime()) ? String(d) : x.toLocaleDateString('de-DE') + ' ' + x.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
}

async function load() {
    try {
        const p = await magic.fetchSources(props.vendorId ? { vendor_id: props.vendorId } : {})
        sources.value = p.sources || []
        settings.value = p.settings || {}
    } catch (e) {
        alerts.error(e.message)
    }
}

function openEditor(s = null) {
    testResult.value = null
    form.value = s ? { ...blank(), ...JSON.parse(JSON.stringify(s)), secret: '', config: { ...blank().config, ...(s.config || {}) } } : blank()
    if (!s && settings.value.default_folder) form.value.config.path = settings.value.default_folder
    if (!s && props.vendorId) {
        form.value.type = 'portal'
        form.value.vendor_id = props.vendorId
        form.value.name = t('crm_fields.belegQuellen.portalNameDefault', { name: props.vendorName })
        if (!vendors.value.some(v => v.id === props.vendorId)) vendors.value.push({ id: props.vendorId, name: props.vendorName })
    }
    editorOpen.value = true
}

async function save() {
    saving.value = true
    try {
        await magic.saveSource({ id: form.value.id, type: form.value.type, name: form.value.name, active: form.value.active, config: form.value.config, secret: form.value.secret, vendor_id: form.value.vendor_id })
        alerts.success(t('crm_fields.belegQuellen.saved'))
        editorOpen.value = false
        await load()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        saving.value = false
    }
}

async function remove(s) {
    const res = await alerts.warning(t('crm_fields.belegQuellen.deleteConfirm', { name: s.name }), t('crm_fields.belegQuellen.delete'), t('crm_fields.belegQuellen.delete'), t('crm_fields.belegQuellen.cancel'))
    if (!res.isConfirmed) return
    try {
        await magic.deleteSource(s.id)
        alerts.success(t('crm_fields.belegQuellen.deleted'))
        await load()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function testSource(s) {
    testing.value = s.id
    try {
        const r = await magic.testSource({ id: s.id })
        ;(r.ok ? alerts.success : alerts.warning)(r.message)
    } catch (e) {
        alerts.error(e.message)
    } finally {
        testing.value = null
    }
}
async function testForm() {
    testing.value = 'form'
    try {
        testResult.value = form.value.id && !form.value.secret
            ? await magic.testSource({ id: form.value.id })
            : await magic.testSource({ type: form.value.type, config: form.value.config, secret: form.value.secret })
    } catch (e) {
        testResult.value = { ok: false, message: e.message }
    } finally {
        testing.value = null
    }
}

async function runOne(s) {
    runningId.value = s.id
    try {
        const r = await magic.runSearch({ source_id: s.id })
        alerts.success(t('crm_fields.belegQuellen.runDone', { imported: r.imported }))
        await load()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        runningId.value = null
    }
}
async function runAll() {
    running.value = true
    try {
        const r = await magic.runSearch()
        alerts.success(t('crm_fields.belegQuellen.runDone', { imported: r.imported }))
        await load()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        running.value = false
    }
}

let vendorTimer = null
function searchVendors(q) {
    clearTimeout(vendorTimer)
    vendorTimer = setTimeout(async () => {
        vendorLoading.value = true
        try {
            const res = await axios.post('/api/accounting/', { action: 'getAccountingVendors', query: q || '', limit: 50 })
            vendors.value = res.data.success ? (res.data.payload.vendors || res.data.payload || []) : []
        } finally {
            vendorLoading.value = false
        }
    }, 250)
}

onMounted(() => { load(); searchVendors('') })
</script>
