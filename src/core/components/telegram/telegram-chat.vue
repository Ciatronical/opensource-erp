<!-- src/core/components/telegram/telegram-chat.vue -->
<!--
    Telegram-Kundenchat mit Eingabezeile – gemeinsam für Kontakt → Telegram (ein
    Chat) und den Reiter Telegram in der Kundenmaske (verknüpfter Chat des Kunden).

    - Fotos der Kunden erscheinen als Vorschau, Dateien und Sprachnachrichten
      lassen sich öffnen; Sprachnachrichten zeigen das Transkript.
    - Antworten mit Datei (Upload oder aus der Kundenablage): Bilder gehen als
      Foto, alles andere als Dokument – direkt in Telegram, kein Link.
    - Live: neue Nachrichten laden den Verlauf nach.
-->
<template>
    <div class="tg-chat">
        <div ref="scroller" class="tg-chat__messages" :style="{ height }">
            <div class="tg-chat__inner">
                <div v-if="loading && !messages.length" class="tg-chat__empty">
                    <v-progress-circular indeterminate size="28" width="3" />
                </div>
                <div v-else-if="!messages.length" class="tg-chat__empty">
                    <v-icon size="44" class="mb-2">mdi-send-circle-outline</v-icon>
                    <div>{{ t('TelegramChat.noMessages') }}</div>
                </div>
                <template v-for="(msg, idx) in messages" :key="msg.id">
                    <div v-if="showDateSeparator(idx)" class="tg-chat__date">
                        <span>{{ formatDateLabel(msg.itime) }}</span>
                    </div>
                    <div class="tg-chat__row" :class="msg.direction === 'O' ? 'tg-chat__row--out' : 'tg-chat__row--in'">
                        <div class="tg-chat__bubble" :class="[msg.direction === 'O' ? 'tg-chat__bubble--out' : 'tg-chat__bubble--in', { 'tg-chat__bubble--failed': msg.status === 'failed' }]">
                            <v-btn
                                class="tg-chat__delete"
                                icon
                                size="x-small"
                                variant="text"
                                :title="t('TelegramChat.deleteMessage')"
                                @click.stop="deleteMessage(msg)"
                            >
                                <v-icon size="14">mdi-delete-outline</v-icon>
                            </v-btn>

                            <div v-if="bubbleHead(msg)" class="tg-chat__head">{{ bubbleHead(msg) }}</div>

                            <!-- Foto: Vorschau, Klick öffnet groß -->
                            <img
                                v-if="msg.has_media && msg.message_type === 'photo' && mediaUrls[msg.id]"
                                :src="mediaUrls[msg.id]"
                                :alt="msg.media_name"
                                class="tg-chat__photo"
                                @click="openMedia(msg)"
                            >
                            <div v-else-if="msg.has_media" class="tg-chat__media" @click="openMedia(msg)">
                                <v-icon size="18">{{ MEDIA_ICON[msg.message_type] || 'mdi-paperclip' }}</v-icon>
                                <span class="text-truncate">{{ msg.media_name }}</span>
                            </div>

                            <div v-if="msg.message_text" class="tg-chat__text">
                                <v-icon v-if="msg.message_type === 'voice' || msg.message_type === 'audio'" size="14" class="me-1" :title="t('TelegramChat.transcript')">mdi-text-to-speech</v-icon>
                                <v-icon v-else-if="msg.message_type === 'contact'" size="14" class="me-1">mdi-card-account-phone-outline</v-icon>
                                <template v-for="(part, i) in linkify(msg.message_text)" :key="i">
                                    <a v-if="part.url" :href="part.url" target="_blank" rel="noopener">{{ part.text }}</a>
                                    <span v-else>{{ part.text }}</span>
                                </template>
                            </div>
                            <div v-if="msg.message_type === 'contact'" class="tg-chat__note">{{ t('TelegramChat.contactShared') }}</div>

                            <div class="tg-chat__meta">
                                <span>{{ formatTime(msg.itime) }}</span>
                                <v-icon
                                    v-if="msg.direction === 'O'"
                                    size="14"
                                    :color="msg.status === 'failed' ? 'error' : undefined"
                                    :title="t('TelegramChat.status.' + msg.status)"
                                >
                                    {{ STATUS_ICON[msg.status] || 'mdi-check' }}
                                </v-icon>
                            </div>
                            <div v-if="msg.status === 'failed'" class="tg-chat__error">
                                <v-icon size="12" class="mr-1">mdi-alert-circle</v-icon>
                                {{ errorText(msg.error_message) }}
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Eingabe -->
        <div class="tg-chat__composer">
            <v-alert v-if="!telegramConfig.configured" type="info" variant="tonal" density="compact" class="mb-2">
                {{ t('TelegramChat.notConfigured') }}
                <template #append>
                    <v-btn size="small" variant="text" @click="router.push({ name: 'client-defaults', query: { tab: 'crm', focus: 'telegram_bot_token' } })">
                        {{ t('TelegramChat.openConfig') }}
                    </v-btn>
                </template>
            </v-alert>
            <v-alert v-else-if="!loading && !activeChatId" type="info" variant="tonal" density="compact" icon="mdi-link-variant-off">
                {{ t('TelegramChat.notLinked', { link: telegramConfig.link.replace('https://', '') }) }}
                <div class="mt-2">
                    <v-btn size="small" variant="tonal" prepend-icon="mdi-content-copy" @click="copyLink">{{ t('TelegramChat.copyLink') }}</v-btn>
                </div>
            </v-alert>

            <template v-if="!telegramConfig.configured || activeChatId || loading">
            <div v-if="attachedFile" class="mb-2">
                <v-chip color="primary" variant="tonal" closable prepend-icon="mdi-paperclip" @click:close="attachedFile = null">
                    {{ attachedFile.name }}
                </v-chip>
            </div>

            <div class="d-flex align-end ga-2">
                <v-menu location="top start">
                    <template #activator="{ props: menu }">
                        <v-btn v-bind="menu" icon="mdi-paperclip" variant="text" :disabled="!canWrite" :title="t('TelegramChat.attach')" />
                    </template>
                    <v-list density="compact">
                        <v-list-item prepend-icon="mdi-upload" :title="t('TelegramChat.attachUpload')" @click="fileInput?.click()" />
                        <v-list-item v-if="customerId" prepend-icon="mdi-folder-open" :title="t('TelegramChat.attachFromDocs')" @click="openDocPicker" />
                    </v-list>
                </v-menu>
                <input ref="fileInput" type="file" class="d-none" @change="onFileSelected">
                <v-textarea
                    ref="textarea"
                    v-model="text"
                    :placeholder="t('TelegramChat.placeholder')"
                    :disabled="!canWrite"
                    variant="outlined"
                    density="compact"
                    rows="2"
                    max-rows="8"
                    auto-grow
                    hide-details
                    :counter="4096"
                    @keydown.enter.exact.prevent="send"
                />
                <v-btn
                    icon="mdi-send"
                    color="primary"
                    :loading="sending"
                    :disabled="!canSend"
                    :title="t('TelegramChat.send')"
                    @click="send"
                />
            </div>
            </template>
        </div>

        <!-- Kundenablage -->
        <v-dialog v-model="docPicker.show" max-width="520">
            <section-card :title="t('TelegramChat.attachFromDocs')" icon="mdi-folder-open" plain>
                <template #actions>
                    <v-btn icon="mdi-close" size="small" variant="text" @click="docPicker.show = false" />
                </template>
                <div v-if="docPicker.loading" class="d-flex justify-center pa-6"><v-progress-circular indeterminate /></div>
                <v-list v-else-if="docPicker.files.length" density="compact" max-height="420">
                    <v-list-item
                        v-for="file in docPicker.files"
                        :key="file.path"
                        :prepend-icon="/\.pdf$/i.test(file.basename) ? 'mdi-file-pdf-box' : 'mdi-file-outline'"
                        :title="file.basename"
                        @click="pickDoc(file)"
                    />
                </v-list>
                <div v-else class="pa-6 text-center tg-chat__note">{{ t('TelegramChat.noDocuments') }}</div>
            </section-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from 'axios'
import SectionCard from '@/core/components/layout/section-card.vue'
import { onServerEvent } from '@/core/composables/sseClient.js'
import { useTelegramConfig } from '@/core/composables/useTelegramConfig.js'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    /** Telegram-Chat (Kontakt → Telegram); sonst der verknüpfte Chat des Kunden */
    chatId: { type: String, default: '' },
    customerId: { type: [Number, String], default: null },
    src: { type: String, default: 'C' },
    height: { type: String, default: '480px' },
})
const emit = defineEmits(['sent'])

const { t, locale } = useI18n()
const router = useRouter()
const { telegramConfig, loadTelegramConfig } = useTelegramConfig()

const messages = ref([])
const resolvedChatId = ref('')
const loading = ref(false)
const sending = ref(false)
const text = ref('')
const attachedFile = ref(null)
const scroller = ref(null)
const fileInput = ref(null)
const textarea = ref(null)
const docPicker = ref({ show: false, loading: false, files: [] })
const mediaUrls = ref({})

const STATUS_ICON = { pending: 'mdi-clock-outline', sent: 'mdi-check', failed: 'mdi-alert-circle-outline' }
const MEDIA_ICON = { document: 'mdi-file-document-outline', voice: 'mdi-microphone', audio: 'mdi-music-note', video: 'mdi-video-outline', photo: 'mdi-image-outline' }

const activeChatId = computed(() => props.chatId || resolvedChatId.value)
const canWrite = computed(() => telegramConfig.value.configured && !!activeChatId.value)
const canSend = computed(() => canWrite.value && (text.value.trim() !== '' || !!attachedFile.value) && !sending.value)

async function load(silent = false) {
    if (!props.chatId && !props.customerId) {
        messages.value = []
        resolvedChatId.value = ''
        return
    }
    if (!silent) loading.value = true
    try {
        const resp = await axios.post('/api/telegram/', {
            action: 'getTelegramChat',
            chat_id: props.chatId,
            customer_id: props.chatId ? null : Number(props.customerId),
        })
        if (resp.data.success) {
            messages.value = resp.data.payload?.messages || []
            resolvedChatId.value = resp.data.payload?.chat_id || ''
            loadPhotos()
            scrollToBottom()
        }
    } catch {
        if (!silent) toast.error(t('TelegramChat.loadError'))
    } finally {
        loading.value = false
    }
}

function scrollToBottom() {
    nextTick(() => {
        if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
    })
}

async function fetchMedia(msg) {
    const resp = await axios.post('/api/telegram/', { action: 'getTelegramMedia', message_id: msg.id }, { responseType: 'blob' })
    return URL.createObjectURL(resp.data)
}

// Fotovorschau: einmal je Nachricht laden
async function loadPhotos() {
    for (const msg of messages.value) {
        if (msg.has_media && msg.message_type === 'photo' && !mediaUrls.value[msg.id]) {
            try {
                mediaUrls.value[msg.id] = await fetchMedia(msg)
                scrollToBottom()
            } catch { /* Datei fehlt – Zeile bleibt ohne Vorschau */ }
        }
    }
}

async function openMedia(msg) {
    try {
        const url = mediaUrls.value[msg.id] || (mediaUrls.value[msg.id] = await fetchMedia(msg))
        window.open(url, '_blank', 'noopener')
    } catch {
        toast.error(t('TelegramChat.downloadError'))
    }
}

async function send() {
    if (!canSend.value) return
    sending.value = true
    try {
        const payload = {
            action: 'sendTelegram',
            chat_id: activeChatId.value,
            message: text.value,
            customer_id: props.customerId ? Number(props.customerId) : null,
        }
        if (attachedFile.value) {
            payload.document_base64 = attachedFile.value.base64
            payload.filename = attachedFile.value.name
        }
        const resp = await axios.post('/api/telegram/', payload)
        if (resp.data.success) {
            text.value = ''
            attachedFile.value = null
            emit('sent', resp.data.payload)
        } else {
            toast.error(errorText(resp.data.payload?.error || ''))
        }
        await load(true)
    } catch {
        toast.error(t('TelegramChat.sendError'))
    } finally {
        sending.value = false
    }
}

async function deleteMessage(msg) {
    const answer = await alerts.question(t('TelegramChat.deleteConfirm'), t('TelegramChat.deleteMessage'), t('TelegramChat.delete'), t('TelegramChat.cancel'))
    if (!answer?.isConfirmed) return
    await axios.post('/api/telegram/', { action: 'deleteTelegramMessage', message_id: msg.id })
    messages.value = messages.value.filter(m => m.id !== msg.id)
}

// Häufige Antworten der Bot-API in Klartext
function errorText(error) {
    const e = String(error || '')
    if (/blocked by the user/i.test(e)) return t('TelegramChat.errors.blocked')
    if (/chat not found/i.test(e)) return t('TelegramChat.errors.chatNotFound')
    if (/too many requests/i.test(e)) return t('TelegramChat.errors.tooMany')
    return e || t('TelegramChat.sendError')
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(telegramConfig.value.link)
        toast.success(t('TelegramChat.linkCopied'))
    } catch {
        toast.error(telegramConfig.value.link)
    }
}

// ── Anhänge ──
function readAsBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => resolve(String(reader.result).split(',')[1] || '')
        reader.onerror = reject
        reader.readAsDataURL(file)
    })
}

async function onFileSelected(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    if (file.size > 20 * 1024 * 1024) {
        toast.error(t('TelegramChat.fileTooLarge'))
        return
    }
    attachedFile.value = { name: file.name, base64: await readAsBase64(file) }
}

async function openDocPicker() {
    docPicker.value = { show: true, loading: true, files: [] }
    try {
        const resp = await axios.post('/api/customer_vendor/', { action: 'vfIndex', cv_id: Number(props.customerId), src: props.src })
        const entries = resp.data.success ? (resp.data.payload?.files || resp.data.payload || []) : []
        docPicker.value.files = entries.filter(f => f.type === 'file')
    } catch {
        docPicker.value.files = []
    } finally {
        docPicker.value.loading = false
    }
}

async function pickDoc(file) {
    docPicker.value.show = false
    try {
        const resp = await axios.post('/api/customer_vendor/', {
            action: 'vfDownload', cv_id: Number(props.customerId), src: props.src, path: file.path,
        }, { responseType: 'blob' })
        attachedFile.value = { name: file.basename, base64: await readAsBase64(resp.data) }
    } catch {
        toast.error(t('TelegramChat.downloadError'))
    }
}

// ── Darstellung ──
function bubbleHead(msg) {
    if (msg.direction === 'I') return msg.contact_name || ''
    const parts = []
    if (msg.employee_name) parts.push(msg.employee_name)
    if (msg.source === 'mailing') parts.push(t('TelegramChat.sourceMailing'))
    return parts.join(' · ')
}

const URL_RE = /(https?:\/\/[^\s]+)/g
function linkify(value) {
    return String(value || '').split(URL_RE).filter(s => s !== '').map(s => (/^https?:\/\//.test(s) ? { text: s, url: s } : { text: s }))
}

function showDateSeparator(idx) {
    if (idx === 0) return true
    return new Date(messages.value[idx].itime).toDateString() !== new Date(messages.value[idx - 1].itime).toDateString()
}
function formatDateLabel(value) {
    const d = new Date(value)
    const today = new Date()
    const yesterday = new Date(); yesterday.setDate(today.getDate() - 1)
    if (d.toDateString() === today.toDateString()) return t('TelegramChat.today')
    if (d.toDateString() === yesterday.toDateString()) return t('TelegramChat.yesterday')
    return d.toLocaleDateString(locale.value, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
}
function formatTime(value) {
    return new Date(value).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
}

// ── Live ──
let unsubMessage = null
let unsubHistory = null
onMounted(() => {
    loadTelegramConfig()
    load()
    unsubMessage = onServerEvent('message', (event) => {
        let data
        try { data = JSON.parse(event.data) } catch { return }
        if (data.table === 'telegram_messages') load(true)
    })
    unsubHistory = onServerEvent('contact_history_change', (event) => {
        let data
        try { data = JSON.parse(event.data) } catch { return }
        if (data.kind === 'telegram' && props.customerId && (data.cv_ids || []).map(Number).includes(Number(props.customerId))) load(true)
    })
})
onUnmounted(() => {
    if (unsubMessage) unsubMessage()
    if (unsubHistory) unsubHistory()
    Object.values(mediaUrls.value).forEach(url => URL.revokeObjectURL(url))
})

watch(() => [props.chatId, props.customerId], () => load())

defineExpose({ reload: () => load(true), focus: () => textarea.value?.focus() })
</script>

<style scoped>
.tg-chat {
    display: flex;
    flex-direction: column;
}
.tg-chat__messages {
    overflow-y: auto;
    background: var(--oserp-surface-soft);
    padding: var(--oserp-gap-lg) var(--oserp-gap);
}
/* Verlauf unten verankert wie in jeder Chat-App */
.tg-chat__inner {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    min-height: 100%;
}
.tg-chat__empty {
    margin: auto;
    text-align: center;
    color: var(--oserp-text-muted);
}
.tg-chat__date {
    text-align: center;
    margin: var(--oserp-gap) 0;
}
.tg-chat__date span {
    display: inline-block;
    background: var(--oserp-surface);
    border: 1px solid var(--oserp-border-soft);
    color: var(--oserp-text-muted);
    font-size: 12px;
    padding: 2px var(--oserp-gap);
    border-radius: var(--oserp-radius-sm);
}
.tg-chat__row {
    display: flex;
    margin-bottom: var(--oserp-gap-xs);
}
.tg-chat__row--out { justify-content: flex-end; }
.tg-chat__row--in { justify-content: flex-start; }
.tg-chat__bubble {
    position: relative;
    max-width: 70%;
    min-width: 110px;
    padding: 6px 10px 4px;
    border-radius: var(--oserp-radius);
    border: 1px solid var(--oserp-border-soft);
    word-wrap: break-word;
}
.tg-chat__bubble--out {
    background: rgba(var(--v-theme-primary), 0.1);
    border-bottom-right-radius: var(--oserp-radius-sm);
}
.tg-chat__bubble--in {
    background: var(--oserp-surface);
    border-bottom-left-radius: var(--oserp-radius-sm);
}
.tg-chat__bubble--failed {
    border-color: rgba(var(--v-theme-error), 0.5);
}
.tg-chat__delete {
    position: absolute;
    top: 0;
    right: 0;
    opacity: 0;
    transition: opacity 0.15s;
}
.tg-chat__bubble:hover .tg-chat__delete { opacity: 0.7; }
.tg-chat__head {
    font-size: 12px;
    font-weight: 600;
    color: var(--oserp-text-muted);
    margin-bottom: 2px;
    padding-right: 20px;
}
.tg-chat__photo {
    display: block;
    max-width: 100%;
    max-height: 260px;
    border-radius: var(--oserp-radius-sm);
    cursor: zoom-in;
    margin-bottom: var(--oserp-gap-xs);
}
.tg-chat__media {
    display: flex;
    align-items: center;
    gap: var(--oserp-gap-xs);
    font-size: 13px;
    cursor: pointer;
    color: rgb(var(--v-theme-primary));
    margin-bottom: var(--oserp-gap-xs);
}
.tg-chat__text {
    font-size: 14px;
    line-height: 1.4;
    white-space: pre-wrap;
}
.tg-chat__text a { word-break: break-all; }
.tg-chat__note {
    font-size: 12px;
    color: var(--oserp-text-muted);
}
.tg-chat__meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--oserp-gap-xs);
    font-size: 11px;
    color: var(--oserp-text-muted);
    margin-top: 2px;
}
.tg-chat__error {
    display: flex;
    align-items: center;
    font-size: 11px;
    color: rgb(var(--v-theme-error));
    margin-top: var(--oserp-gap-xs);
}
.tg-chat__composer {
    padding: var(--oserp-gap);
    border-top: 1px solid var(--oserp-border);
}
</style>
