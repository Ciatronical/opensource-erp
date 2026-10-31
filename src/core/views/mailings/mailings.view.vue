<!-- src/core/views/mailings/mailings.view.vue -->
<!--
    Rundschreiben (Kontakt → Rundschreiben): Übersicht aller Rundschreiben mit
    Fortschritt und Status sowie die Verteiler (gespeicherte Empfängerlisten).

    Solange ein Rundschreiben läuft, stößt die offene Seite fällige Etappen
    selbst an — der Versand hängt nicht am Cron, der Cron sorgt nur dafür,
    dass es auch ohne offenen Browser weitergeht.
-->
<template>
    <NavbarView />

    <v-container fluid class="pt-2 px-2 px-sm-4 mailings">
        <page-header :title="t('MailingsView.title')" :subtitle="t('MailingsView.subtitle')">
            <div class="d-flex flex-wrap ga-1">
                <v-chip v-for="c in channelChips" :key="c.key" size="small" variant="tonal" :color="c.ok ? c.color : 'grey'" :prepend-icon="c.icon" :title="c.ok ? t('MailingsView.channelReady') : t('MailingsView.channelNotConfigured')">
                    {{ t('MailingRecipients.channel.' + c.key) }}
                </v-chip>
            </div>
            <template #actions>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" :to="{ name: 'mailing-new' }">
                    {{ t('MailingsView.newMailing') }}
                </v-btn>
            </template>
        </page-header>

        <v-alert v-if="config && !config.telegram && !config.whatsapp && !config.email" type="warning" variant="tonal" density="compact" class="mb-3" icon="mdi-alert-outline">
            {{ t('MailingsView.noChannelConfigured') }}
        </v-alert>
        <v-alert v-else-if="config && config.telegram && config.telegram_bot" type="info" variant="tonal" density="compact" class="mb-3" icon="mdi-send-circle-outline" closable>
            {{ t('MailingsView.telegramHint', { bot: config.telegram_bot }) }}
        </v-alert>

        <v-tabs v-model="tab" color="primary" class="mb-3">
            <v-tab value="mailings" prepend-icon="mdi-bullhorn-outline">{{ t('MailingsView.tabMailings') }} <v-chip size="x-small" class="ms-2" variant="tonal">{{ mailings.length }}</v-chip></v-tab>
            <v-tab value="lists" prepend-icon="mdi-account-multiple-outline">{{ t('MailingsView.tabLists') }} <v-chip size="x-small" class="ms-2" variant="tonal">{{ lists.length }}</v-chip></v-tab>
        </v-tabs>

        <v-window v-model="tab">
            <!-- Rundschreiben -->
            <v-window-item value="mailings">
                <section-card plain>
                    <v-data-table
                        :headers="headers"
                        :items="mailings"
                        :items-per-page="25"
                        :loading="loading"
                        density="comfortable"
                        hover
                        class="zebra-table mailings__table"
                        :no-data-text="t('MailingsView.noMailings')"
                        @click:row="(_, { item }) => open(item)"
                    >
                        <template #item.title="{ item }">
                            <div class="font-weight-medium">{{ item.title }}</div>
                            <div v-if="item.subject" class="text-caption text-medium-emphasis text-truncate">{{ item.subject }}</div>
                        </template>
                        <template #item.status="{ item }">
                            <v-chip size="small" variant="tonal" :color="statusMeta(item.status).color" :prepend-icon="statusMeta(item.status).icon">
                                {{ t('MailingsView.status.' + item.status) }}
                            </v-chip>
                            <div v-if="item.status === 'running'" class="text-caption text-medium-emphasis mt-1">{{ nextBatchText(item) }}</div>
                            <div v-else-if="item.status === 'paused' && item.last_error" class="text-caption text-error mt-1 text-truncate mailings__error" :title="item.last_error">{{ item.last_error }}</div>
                        </template>
                        <template #item.progress="{ item }">
                            <span v-if="item.total && item.status === 'draft'" class="text-caption text-medium-emphasis">{{ t('MailingsView.recipientsCount', { n: item.total }) }}</span>
                            <div v-else-if="item.total" class="mailings__progress">
                                <v-progress-linear :model-value="progressPct(item)" :color="item.failed ? 'warning' : 'success'" height="6" rounded />
                                <div class="text-caption text-medium-emphasis mt-1">
                                    {{ t('MailingsView.progress', { sent: item.sent, total: item.total }) }}
                                    <span v-if="item.failed" class="text-error"> · {{ t('MailingsView.failed', { n: item.failed }) }}</span>
                                    <span v-if="item.skipped"> · {{ t('MailingsView.skipped', { n: item.skipped }) }}</span>
                                </div>
                            </div>
                            <span v-else class="text-caption text-medium-emphasis">{{ t('MailingsView.noRecipients') }}</span>
                        </template>
                        <template #item.channels="{ item }">
                            <div class="d-flex flex-wrap ga-1">
                                <template v-if="Object.keys(item.by_channel || {}).length">
                                    <v-chip v-for="(n, ch) in item.by_channel" :key="ch" size="x-small" variant="tonal" :color="CHANNEL_META[ch]?.color" :prepend-icon="CHANNEL_META[ch]?.icon">
                                        {{ n }}
                                    </v-chip>
                                </template>
                                <template v-else>
                                    <v-icon v-for="ch in item.channels" :key="ch" size="small" :color="CHANNEL_META[ch]?.color" class="me-1" :title="t('MailingRecipients.channel.' + ch)">{{ CHANNEL_META[ch]?.icon }}</v-icon>
                                    <v-icon v-if="item.email_mode !== 'off'" size="small" :color="CHANNEL_META.email.color" class="me-1" :title="t('MailingEditView.emailModes.' + (item.email_mode || 'always'))">{{ CHANNEL_META.email.icon }}</v-icon>
                                    <v-icon v-if="item.letter_mode && item.letter_mode !== 'off'" size="small" :color="CHANNEL_META.letter.color" class="me-1" :title="t('MailingEditView.letterModes.' + item.letter_mode)">{{ CHANNEL_META.letter.icon }}</v-icon>
                                </template>
                            </div>
                        </template>
                        <template #item.itime="{ item }">
                            <div class="text-no-wrap">{{ formatDate(item.finished_at || item.started_at || item.itime) }}</div>
                            <div v-if="item.employee_name" class="text-caption text-medium-emphasis">{{ item.employee_name }}</div>
                        </template>
                        <template #item.actions="{ item }">
                            <div class="d-inline-flex align-center">
                                <v-btn icon size="x-small" variant="text" color="primary" :title="t('MailingsView.open')" @click.stop="open(item)">
                                    <v-icon size="small">mdi-pencil</v-icon>
                                </v-btn>
                                <v-btn icon size="x-small" variant="text" :title="t('MailingsView.copy')" @click.stop="copy(item)">
                                    <v-icon size="small">mdi-content-copy</v-icon>
                                </v-btn>
                                <v-btn icon size="x-small" variant="text" color="error" :title="t('MailingsView.delete')" :disabled="item.status === 'running'" @click.stop="remove(item)">
                                    <v-icon size="small">mdi-delete-outline</v-icon>
                                </v-btn>
                            </div>
                        </template>
                    </v-data-table>
                </section-card>
            </v-window-item>

            <!-- Verteiler -->
            <v-window-item value="lists">
                <section-card plain>
                    <template #actions>
                        <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-plus" @click="editList(null)">{{ t('MailingsView.newList') }}</v-btn>
                    </template>
                    <v-data-table
                        :headers="listHeaders"
                        :items="lists"
                        :items-per-page="25"
                        :loading="loading"
                        density="comfortable"
                        hover
                        class="zebra-table mailings__table"
                        :no-data-text="t('MailingsView.noLists')"
                        @click:row="(_, { item }) => editList(item)"
                    >
                        <template #item.name="{ item }">
                            <div class="font-weight-medium">{{ item.name }}</div>
                            <div v-if="item.description" class="text-caption text-medium-emphasis">{{ item.description }}</div>
                        </template>
                        <template #item.members="{ item }">
                            <v-chip size="small" variant="tonal">{{ t('MailingRecipients.members', { n: item.members }) }}</v-chip>
                        </template>
                        <template #item.mtime="{ item }">
                            <span class="text-no-wrap">{{ formatDate(item.mtime || item.itime) }}</span>
                        </template>
                        <template #item.actions="{ item }">
                            <div class="d-inline-flex align-center">
                                <v-btn icon size="x-small" variant="text" color="primary" :title="t('MailingsView.mailingToList')" @click.stop="mailingToList(item)">
                                    <v-icon size="small">mdi-bullhorn-outline</v-icon>
                                </v-btn>
                                <v-btn icon size="x-small" variant="text" :title="t('MailingsView.editList')" @click.stop="editList(item)">
                                    <v-icon size="small">mdi-pencil</v-icon>
                                </v-btn>
                                <v-btn icon size="x-small" variant="text" color="error" :title="t('MailingsView.deleteList')" @click.stop="removeList(item)">
                                    <v-icon size="small">mdi-delete-outline</v-icon>
                                </v-btn>
                            </div>
                        </template>
                    </v-data-table>
                </section-card>
            </v-window-item>
        </v-window>

        <!-- Verteiler bearbeiten -->
        <v-dialog v-model="listDialog" max-width="1100" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon class="me-2" color="primary">mdi-account-multiple-outline</v-icon>
                    {{ listEdit.id ? t('MailingsView.editList') : t('MailingsView.newList') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" density="compact" size="small" @click="listDialog = false" />
                </v-card-title>
                <v-card-text class="pt-2">
                    <v-row density="compact" class="mb-2">
                        <v-col cols="12" sm="5">
                            <v-text-field v-model="listEdit.name" :label="t('MailingRecipients.listName')" variant="outlined" density="compact" hide-details autofocus />
                        </v-col>
                        <v-col cols="12" sm="7">
                            <v-text-field v-model="listEdit.description" :label="t('MailingRecipients.listDescription')" variant="outlined" density="compact" hide-details />
                        </v-col>
                    </v-row>
                    <mailing-recipients
                        v-model="listEdit.members"
                        :config="config || {}"
                        :settings="{ channels: ['telegram', 'whatsapp', 'email'], whatsapp_template_id: null }"
                        :lists="lists.filter(l => l.id !== listEdit.id)"
                        :businesses="businesses"
                        :max="config?.max_recipients || 300"
                        @lists-changed="load"
                    />
                </v-card-text>
                <v-divider />
                <v-card-actions class="px-4 py-3">
                    <v-spacer />
                    <v-btn variant="text" @click="listDialog = false">{{ t('MailingRecipients.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="listSaving" :disabled="!listEdit.name.trim()" @click="saveList">{{ t('MailingRecipients.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, onActivated, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { apiError } from './composables/apiError.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import MailingRecipients from './components/mailing-recipients.vue'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'
import { CHANNEL_META } from './composables/mailingPlan.js'

const { t, locale } = useI18n()
const router = useRouter()

const tab = ref('mailings')
const loading = ref(false)
const mailings = ref([])
const lists = ref([])
const businesses = ref([])
const config = ref(null)

const headers = computed(() => [
    { title: t('MailingsView.colTitle'), key: 'title', sortable: false, cellProps: { class: 'mailings__title' } },
    { title: t('MailingsView.colStatus'), key: 'status', sortable: false, width: '170px' },
    { title: t('MailingsView.colProgress'), key: 'progress', sortable: false, width: '220px' },
    { title: t('MailingsView.colChannels'), key: 'channels', sortable: false, width: '160px' },
    { title: t('MailingsView.colDate'), key: 'itime', sortable: false, width: '150px' },
    { title: '', key: 'actions', sortable: false, align: 'end', width: '120px' },
])
const listHeaders = computed(() => [
    { title: t('MailingRecipients.listName'), key: 'name', sortable: true },
    { title: t('MailingsView.colMembers'), key: 'members', sortable: true, width: '160px' },
    { title: t('MailingsView.colChanged'), key: 'mtime', sortable: true, width: '140px' },
    { title: '', key: 'actions', sortable: false, align: 'end', width: '120px' },
])
const channelChips = computed(() => ['telegram', 'whatsapp', 'email'].map(k => ({ key: k, ok: !!config.value?.[k], ...CHANNEL_META[k] })))

function statusMeta(s) {
    return {
        draft:     { icon: 'mdi-pencil-outline', color: 'grey' },
        running:   { icon: 'mdi-send-clock-outline', color: 'primary' },
        paused:    { icon: 'mdi-pause-circle-outline', color: 'warning' },
        done:      { icon: 'mdi-check-circle-outline', color: 'success' },
        cancelled: { icon: 'mdi-cancel', color: 'error' },
    }[s] || { icon: 'mdi-help-circle-outline', color: 'grey' }
}
function progressPct(m) {
    const done = Number(m.sent) + Number(m.failed) + Number(m.skipped)
    return m.total ? Math.round(100 * done / Number(m.total)) : 0
}
function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString(locale.value)
}
function nextBatchText(m) {
    if (!m.next_batch_at) return t('MailingsView.nextBatchNow')
    const at = new Date(m.next_batch_at)
    if (at <= new Date()) return t('MailingsView.nextBatchNow')
    return t('MailingsView.nextBatchAt', { time: at.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) })
}

async function load() {
    loading.value = true
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'getMailings' })
        if (!data.success) throw new Error(apiError(data, t))
        mailings.value = data.payload.mailings || []
        lists.value = data.payload.lists || []
        config.value = data.payload.config || {}
    } catch (e) {
        toast.error(t('MailingsView.loadError', { error: e.message }))
    } finally {
        loading.value = false
    }
}
async function loadBusinesses() {
    if (businesses.value.length) return
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'getMailing', id: 0 })
        if (data.success) businesses.value = data.payload.businesses || []
    } catch (e) { /* Vorschlag funktioniert auch ohne Kundentypen */ }
}

// Laufende Rundschreiben: Etappen anstoßen und Fortschritt nachladen
let timer = null
async function tick() {
    if (mailings.value.some(m => m.status === 'running')) {
        try { await axios.post('/api/mailings/', { action: 'processMailings', id: 0 }) } catch (e) { /* nächster Versuch */ }
        await load()
    }
}
function startTimer() { stopTimer(); timer = setInterval(tick, 20000) }
function stopTimer() { if (timer) { clearInterval(timer); timer = null } }

function open(item) { router.push({ name: 'mailing-edit', params: { id: item.id } }) }
function copy(item) { router.push({ name: 'mailing-new', query: { copy_of: String(item.id) } }) }
function mailingToList(list) { router.push({ name: 'mailing-new', query: { list: String(list.id) } }) }
async function remove(item) {
    const res = await alerts.warning(t('MailingsView.deleteConfirm', { title: item.title }), '', t('MailingsView.delete'), t('MailingRecipients.cancel'))
    if (!res.isConfirmed) return
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'deleteMailing', id: item.id })
        if (!data.success) throw new Error(apiError(data, t))
        toast.success(t('MailingsView.deleted'))
        load()
    } catch (e) {
        toast.error(e.message)
    }
}

// ── Verteiler ──
const listDialog = ref(false)
const listSaving = ref(false)
const listEdit = ref({ id: 0, name: '', description: '', members: [] })
async function editList(item) {
    loadBusinesses()
    if (!item) {
        listEdit.value = { id: 0, name: '', description: '', members: [] }
        listDialog.value = true
        return
    }
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'getMailingList', id: item.id })
        if (!data.success) throw new Error(apiError(data, t))
        listEdit.value = { id: item.id, name: data.payload.list?.name || item.name, description: data.payload.list?.description || '', members: data.payload.members || [] }
        listDialog.value = true
    } catch (e) {
        toast.error(e.message)
    }
}
async function saveList() {
    listSaving.value = true
    try {
        const { data } = await axios.post('/api/mailings/', {
            action: 'saveMailingList',
            id: listEdit.value.id,
            name: listEdit.value.name.trim(),
            description: listEdit.value.description.trim(),
            members: listEdit.value.members.map(r => ({ cv_src: r.cv_src, cv_id: r.cv_id })),
        })
        if (!data.success) throw new Error(apiError(data, t))
        listDialog.value = false
        toast.success(t('MailingRecipients.listSaved'))
        load()
    } catch (e) {
        toast.error(e.message)
    } finally {
        listSaving.value = false
    }
}
async function removeList(item) {
    const res = await alerts.warning(t('MailingsView.deleteListConfirm', { name: item.name }), '', t('MailingsView.deleteList'), t('MailingRecipients.cancel'))
    if (!res.isConfirmed) return
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'deleteMailingList', id: item.id })
        if (!data.success) throw new Error(apiError(data, t))
        load()
    } catch (e) {
        toast.error(e.message)
    }
}

onMounted(() => { load(); startTimer() })
onActivated(() => { load(); startTimer() })
onBeforeUnmount(stopTimer)
</script>

<style scoped>
.mailings__table :deep(.mailings__title) {
    max-width: 0;
    width: 100%;
}
.mailings__table :deep(tbody tr) {
    cursor: pointer;
}
.mailings__progress {
    min-width: 160px;
}
.mailings__error {
    max-width: 160px;
}
</style>
