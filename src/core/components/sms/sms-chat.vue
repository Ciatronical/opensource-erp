<!-- src/core/components/sms/sms-chat.vue -->
<!--
    SMS-Verlauf mit Eingabezeile – gemeinsam für Kontakt → SMS (eine Rufnummer)
    und den Reiter SMS in der Kundenmaske (alle Nummern des Kunden).

    - Text frei änderbar; Zähler zeigt Zeichen, SMS-Teile und Kosten. Typografische
      Zeichen („“ – …) machen eine SMS dreimal so teuer – ein Klick ersetzt sie.
    - Datei anhängen (Upload oder aus der Kundenablage): geht als Link mit; {link}
      im Text bestimmt die Stelle, sonst steht er am Ende.
    - Ausweich-SMS (statt einer nicht zustellbaren WhatsApp) sind markiert,
      der Grund steht im Tooltip. Abgerufene Links zeigen „geöffnet“.
    - Live: neue SMS, Zustellberichte und Ausweich-SMS laden den Verlauf nach.
-->
<template>
    <div class="sms-chat">
        <div ref="scroller" class="sms-chat__messages" :style="{ height }">
            <div class="sms-chat__inner">
                <div v-if="loading && !messages.length" class="sms-chat__empty">
                    <v-progress-circular indeterminate size="28" width="3" />
                </div>
                <div v-else-if="!messages.length" class="sms-chat__empty">
                    <v-icon size="44" class="mb-2">mdi-message-text-outline</v-icon>
                    <div>{{ t('SmsChat.noMessages') }}</div>
                </div>
                <template v-for="(msg, idx) in messages" :key="msg.id">
                    <div v-if="showDateSeparator(idx)" class="sms-chat__date">
                        <span>{{ formatDateLabel(msg.itime) }}</span>
                    </div>
                    <div class="sms-chat__row" :class="msg.direction === 'O' ? 'sms-chat__row--out' : 'sms-chat__row--in'">
                        <div class="sms-chat__bubble" :class="[msg.direction === 'O' ? 'sms-chat__bubble--out' : 'sms-chat__bubble--in', { 'sms-chat__bubble--failed': msg.status === 'failed' }]">
                            <v-btn
                                class="sms-chat__delete"
                                icon
                                size="x-small"
                                variant="text"
                                :title="t('SmsChat.deleteMessage')"
                                @click.stop="deleteMessage(msg)"
                            >
                                <v-icon size="14">mdi-delete-outline</v-icon>
                            </v-btn>

                            <!-- Kopfzeile: Absender bzw. Herkunft -->
                            <div v-if="bubbleHead(msg)" class="sms-chat__head">
                                <v-tooltip v-if="msg.source === 'fallback'" location="top" max-width="360">
                                    <template #activator="{ props: tip }">
                                        <span v-bind="tip" class="sms-chat__fallback">
                                            <v-icon size="13">mdi-whatsapp</v-icon>
                                            <v-icon size="13">mdi-arrow-right</v-icon>
                                            {{ t('SmsChat.fallback') }}
                                        </span>
                                    </template>
                                    {{ t('SmsChat.fallbackReason', { reason: msg.whatsapp_error || '–' }) }}
                                </v-tooltip>
                                <span v-else>{{ bubbleHead(msg) }}</span>
                            </div>

                            <div class="sms-chat__text">
                                <template v-for="(part, i) in linkify(msg.message_text)" :key="i">
                                    <a v-if="part.url" :href="part.url" target="_blank" rel="noopener">{{ part.text }}</a>
                                    <span v-else>{{ part.text }}</span>
                                </template>
                            </div>

                            <div v-if="msg.has_media" class="sms-chat__media">
                                <v-icon size="16">mdi-paperclip</v-icon>
                                <span class="text-truncate">{{ msg.media_name }}</span>
                                <v-chip
                                    v-if="msg.link_opened_at"
                                    size="x-small"
                                    color="success"
                                    variant="tonal"
                                    prepend-icon="mdi-eye-check-outline"
                                    :title="t('SmsChat.linkOpenedAt', { when: formatDateTime(msg.link_opened_at) })"
                                >
                                    {{ t('SmsChat.linkOpened') }}
                                </v-chip>
                            </div>

                            <div class="sms-chat__meta">
                                <span v-if="msg.parts > 1" :title="t('SmsChat.parts', { n: msg.parts })">{{ msg.parts }}×</span>
                                <span :title="msg.price ? formatMoney(msg.price) : ''">{{ formatTime(msg.itime) }}</span>
                                <v-icon
                                    v-if="msg.direction === 'O'"
                                    size="14"
                                    :color="msg.status === 'failed' ? 'error' : (msg.status === 'delivered' ? 'primary' : undefined)"
                                    :title="t('SmsChat.status.' + msg.status)"
                                >
                                    {{ STATUS_ICON[msg.status] || 'mdi-check' }}
                                </v-icon>
                            </div>
                            <div v-if="msg.status === 'failed'" class="sms-chat__error">
                                <v-icon size="12" class="mr-1">mdi-alert-circle</v-icon>
                                {{ errorText(msg.error_code, msg.error_message) }}
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Eingabe -->
        <div class="sms-chat__composer">
            <v-alert v-if="!smsConfig.configured" type="info" variant="tonal" density="compact" class="mb-2">
                {{ t('SmsChat.notConfigured') }}
                <template #append>
                    <v-btn size="small" variant="text" @click="router.push({ name: 'client-defaults', query: { tab: 'crm', focus: 'sms_api_key' } })">
                        {{ t('SmsChat.openConfig') }}
                    </v-btn>
                </template>
            </v-alert>

            <div v-if="attachedFile" class="mb-2">
                <v-chip color="primary" variant="tonal" closable prepend-icon="mdi-paperclip" @click:close="attachedFile = null">
                    {{ attachedFile.name }}
                </v-chip>
                <span class="sms-chat__hint">{{ t('SmsChat.attachmentAsLink') }}</span>
            </div>

            <div class="d-flex align-end ga-2">
                <v-menu location="top start">
                    <template #activator="{ props: menu }">
                        <v-btn v-bind="menu" icon="mdi-paperclip" variant="text" :disabled="!smsConfig.configured" :title="t('SmsChat.attach')" />
                    </template>
                    <v-list density="compact">
                        <v-list-item prepend-icon="mdi-upload" :title="t('SmsChat.attachUpload')" @click="fileInput?.click()" />
                        <v-list-item v-if="customerId" prepend-icon="mdi-folder-open" :title="t('SmsChat.attachFromDocs')" @click="openDocPicker" />
                    </v-list>
                </v-menu>
                <input ref="fileInput" type="file" class="d-none" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.mp4,.mp3" @change="onFileSelected">
                <v-textarea
                    ref="textarea"
                    v-model="text"
                    :placeholder="phone ? t('SmsChat.placeholder') : t('SmsChat.noNumber')"
                    :disabled="!smsConfig.configured || !phone"
                    variant="outlined"
                    density="compact"
                    rows="2"
                    max-rows="8"
                    auto-grow
                    hide-details
                    @keydown.enter.exact.prevent="send"
                />
                <v-btn
                    icon="mdi-send"
                    color="primary"
                    :loading="sending"
                    :disabled="!canSend"
                    :title="t('SmsChat.send')"
                    @click="send"
                />
            </div>

            <div class="sms-chat__counter">
                <span :class="{ 'text-warning': info.encoding === 'ucs2' }">
                    {{ t('SmsChat.counter', { length: info.length, parts: info.parts, left: info.left }) }}
                </span>
                <span v-if="smsConfig.cost > 0 && info.parts"> · {{ t('SmsChat.cost', { cost: formatMoney(info.parts * smsConfig.cost) }) }}</span>
                <template v-if="info.encoding === 'ucs2'">
                    <span> · {{ t('SmsChat.unicodeHint', { chars: info.nonGsm.slice(0, 6).join(' ') }) }}</span>
                    <v-btn v-if="canReplaceSpecials" size="x-small" variant="tonal" color="warning" class="ms-2" @click="text = replaceSmsSpecials(text)">
                        {{ t('SmsChat.replaceSpecials') }}
                    </v-btn>
                </template>
                <div v-if="smsConfig.reply_hint" :title="t('SmsChat.replyHintTip')">
                    <v-icon size="12" class="me-1">mdi-reply-outline</v-icon>{{ t('SmsChat.replyHint', { hint: smsConfig.reply_hint }) }}
                </div>
            </div>
        </div>

        <!-- Kundenablage -->
        <v-dialog v-model="docPicker.show" max-width="520">
            <section-card :title="t('SmsChat.attachFromDocs')" icon="mdi-folder-open" plain>
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
                <div v-else class="pa-6 text-center sms-chat__hint">{{ t('SmsChat.noDocuments') }}</div>
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
import { useSmsConfig } from '@/core/composables/useSmsConfig.js'
import { smsInfo, replaceSmsSpecials, withSampleLink, withReplyHint } from '@/core/utils/smsText.js'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    /** Zielnummer für neue SMS */
    phone: { type: String, default: '' },
    /** Weitere Nummern, deren Verlauf mit angezeigt wird (Kundenmaske) */
    phones: { type: Array, default: () => [] },
    customerId: { type: [Number, String], default: null },
    src: { type: String, default: 'C' },
    height: { type: String, default: '480px' },
    /** Vorbelegter Text (z. B. aus „Neue SMS“) */
    initialText: { type: String, default: '' },
    /** Verlauf über alle SMS des Kunden (Reiter) statt nur dieser Nummer (Seite Kontakt → SMS) */
    historyByCustomer: { type: Boolean, default: true },
})
const emit = defineEmits(['sent'])

const { t, locale } = useI18n()
const router = useRouter()
const { smsConfig, loadSmsConfig } = useSmsConfig()

const messages = ref([])
const loading = ref(false)
const sending = ref(false)
const text = ref(props.initialText)
const attachedFile = ref(null)
const scroller = ref(null)
const fileInput = ref(null)
const textarea = ref(null)
const docPicker = ref({ show: false, loading: false, files: [] })

const STATUS_ICON = {
    pending: 'mdi-clock-outline',
    sent: 'mdi-check',
    delivered: 'mdi-check-all',
    failed: 'mdi-alert-circle-outline',
}

// Länge so, wie sie beim Empfänger ankommt: {link} bzw. der angehängte Link
// wird durch eine Adresse der späteren Länge ersetzt
const sampleLink = computed(() => (smsConfig.value.public_url || window.location.origin) + '/webhook/beleg.php?k=xxxxxxxxxx.xxxxxxxxxxxxxxxx')
const outgoingText = computed(() => {
    let s = text.value
    if (attachedFile.value && !s.includes('{link}')) s = s.trimEnd() + '\n{link}'
    return withReplyHint(withSampleLink(s, attachedFile.value ? sampleLink.value : ''), smsConfig.value.reply_hint)
})
const info = computed(() => smsInfo(outgoingText.value))
const canReplaceSpecials = computed(() => replaceSmsSpecials(text.value) !== text.value)
const canSend = computed(() => smsConfig.value.configured && !!props.phone && (text.value.trim() !== '' || !!attachedFile.value) && !sending.value)

async function load(silent = false) {
    const cvFilter = props.historyByCustomer && props.customerId ? Number(props.customerId) : null
    if (!props.phone && !props.phones.length && !cvFilter) {
        messages.value = []
        return
    }
    if (!silent) loading.value = true
    try {
        const resp = await axios.post('/api/sms/', {
            action: 'getSmsChat',
            phone_number: props.phone,
            phone_numbers: props.phones,
            customer_id: cvFilter,
        })
        if (resp.data.success) {
            messages.value = resp.data.payload?.messages || []
            scrollToBottom()
        }
    } catch {
        if (!silent) toast.error(t('SmsChat.loadError'))
    } finally {
        loading.value = false
    }
}

function scrollToBottom() {
    nextTick(() => {
        if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
    })
}

async function send() {
    if (!canSend.value) return
    sending.value = true
    try {
        const payload = {
            action: 'sendSms',
            to: props.phone,
            message: text.value,
            customer_id: props.customerId ? Number(props.customerId) : null,
        }
        if (attachedFile.value) {
            payload.document_base64 = attachedFile.value.base64
            payload.filename = attachedFile.value.name
        }
        const resp = await axios.post('/api/sms/', payload)
        if (resp.data.success) {
            text.value = ''
            attachedFile.value = null
            emit('sent', resp.data.payload)
        } else {
            toast.error(errorText(resp.data.payload?.seven_code || resp.data.text, resp.data.payload?.error || ''))
        }
        await load(true)
    } catch {
        toast.error(t('SmsChat.sendError'))
    } finally {
        sending.value = false
    }
}

async function deleteMessage(msg) {
    const answer = await alerts.question(t('SmsChat.deleteConfirm'), t('SmsChat.deleteMessage'), t('SmsChat.delete'), t('SmsChat.cancel'))
    if (!answer?.isConfirmed) return
    await axios.post('/api/sms/', { action: 'deleteSmsMessage', message_id: msg.id })
    messages.value = messages.value.filter(m => m.id !== msg.id)
}

function errorText(code, fallback = '') {
    const key = 'SmsChat.errors.' + code
    const translated = t(key)
    return translated !== key ? translated : (fallback || t('SmsChat.sendError'))
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
        toast.error(t('SmsChat.fileTooLarge'))
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
        toast.error(t('SmsChat.downloadError'))
    }
}

// ── Darstellung ──
function bubbleHead(msg) {
    if (msg.source === 'fallback') return 'fallback'
    if (msg.direction === 'I') return msg.contact_name || ''
    const parts = []
    if (msg.employee_name) parts.push(msg.employee_name)
    if (msg.source && msg.source !== 'chat') parts.push(t('SmsChat.source.' + msg.source))
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
    if (d.toDateString() === today.toDateString()) return t('SmsChat.today')
    if (d.toDateString() === yesterday.toDateString()) return t('SmsChat.yesterday')
    return d.toLocaleDateString(locale.value, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
}
function formatTime(value) {
    return new Date(value).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
}
function formatDateTime(value) {
    return new Date(value).toLocaleString(locale.value, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}
function formatMoney(value) {
    return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR', maximumFractionDigits: 3 }).format(Number(value || 0))
}

// ── Live ──
let unsubMessage = null
let unsubHistory = null
onMounted(() => {
    loadSmsConfig()
    load()
    unsubMessage = onServerEvent('message', (event) => {
        let data
        try { data = JSON.parse(event.data) } catch { return }
        if (data.table === 'sms_messages') load(true)
    })
    unsubHistory = onServerEvent('contact_history_change', (event) => {
        let data
        try { data = JSON.parse(event.data) } catch { return }
        if (data.kind === 'sms' && props.customerId && (data.cv_ids || []).map(Number).includes(Number(props.customerId))) load(true)
    })
})
onUnmounted(() => {
    if (unsubMessage) unsubMessage()
    if (unsubHistory) unsubHistory()
})

watch(() => [props.phone, props.customerId, props.phones.join(',')], () => load())
watch(() => props.initialText, (v) => { if (v) text.value = v })

defineExpose({ reload: () => load(true), focus: () => textarea.value?.focus() })
</script>

<style scoped>
.sms-chat {
    display: flex;
    flex-direction: column;
}
.sms-chat__messages {
    overflow-y: auto;
    background: var(--oserp-surface-soft);
    padding: var(--oserp-gap-lg) var(--oserp-gap);
}
/* Verlauf unten verankert wie in jeder Chat-App */
.sms-chat__inner {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    min-height: 100%;
}
.sms-chat__empty {
    margin: auto;
    text-align: center;
    color: var(--oserp-text-muted);
}
.sms-chat__date {
    text-align: center;
    margin: var(--oserp-gap) 0;
}
.sms-chat__date span {
    display: inline-block;
    background: var(--oserp-surface);
    border: 1px solid var(--oserp-border-soft);
    color: var(--oserp-text-muted);
    font-size: 12px;
    padding: 2px var(--oserp-gap);
    border-radius: var(--oserp-radius-sm);
}
.sms-chat__row {
    display: flex;
    margin-bottom: var(--oserp-gap-xs);
}
.sms-chat__row--out { justify-content: flex-end; }
.sms-chat__row--in { justify-content: flex-start; }
.sms-chat__bubble {
    position: relative;
    max-width: 70%;
    min-width: 110px;
    padding: 6px 10px 4px;
    border-radius: var(--oserp-radius);
    border: 1px solid var(--oserp-border-soft);
    word-wrap: break-word;
}
.sms-chat__bubble--out {
    background: rgba(var(--v-theme-primary), 0.1);
    border-bottom-right-radius: var(--oserp-radius-sm);
}
.sms-chat__bubble--in {
    background: var(--oserp-surface);
    border-bottom-left-radius: var(--oserp-radius-sm);
}
.sms-chat__bubble--failed {
    border-color: rgba(var(--v-theme-error), 0.5);
}
.sms-chat__delete {
    position: absolute;
    top: 0;
    right: 0;
    opacity: 0;
    transition: opacity 0.15s;
}
.sms-chat__bubble:hover .sms-chat__delete { opacity: 0.7; }
.sms-chat__head {
    font-size: 12px;
    font-weight: 600;
    color: var(--oserp-text-muted);
    margin-bottom: 2px;
    padding-right: 20px;
}
.sms-chat__fallback {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    color: rgb(var(--v-theme-warning));
    cursor: help;
}
.sms-chat__text {
    font-size: 14px;
    line-height: 1.4;
    white-space: pre-wrap;
}
.sms-chat__text a { word-break: break-all; }
.sms-chat__media {
    display: flex;
    align-items: center;
    gap: var(--oserp-gap-xs);
    margin-top: var(--oserp-gap-xs);
    font-size: 13px;
    color: var(--oserp-text-muted);
}
.sms-chat__meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--oserp-gap-xs);
    font-size: 11px;
    color: var(--oserp-text-muted);
    margin-top: 2px;
}
.sms-chat__error {
    display: flex;
    align-items: center;
    font-size: 11px;
    color: rgb(var(--v-theme-error));
    margin-top: var(--oserp-gap-xs);
}
.sms-chat__composer {
    padding: var(--oserp-gap);
    border-top: 1px solid var(--oserp-border);
}
.sms-chat__counter {
    margin-top: var(--oserp-gap-xs);
    font-size: 12px;
    color: var(--oserp-text-muted);
    padding-left: 52px;
}
.sms-chat__hint {
    font-size: 12px;
    color: var(--oserp-text-muted);
    margin-left: var(--oserp-gap-sm);
}
</style>
