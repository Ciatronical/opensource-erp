<!-- src/core/components/whatsapp-forward.dialog.vue -->
<!--
    WhatsApp-Nachricht weiterleiten: Empfänger suchen (bestehende Chats, Kunden,
    Lieferanten oder freie Rufnummer), auswählen, senden.

    Gemeinsam für die WhatsApp-Ansicht und den WhatsApp-Tab bei Kunde/Lieferant.
    Das Senden selbst macht das Backend (forwardWhatsAppMessage), hier wird keine
    Datei durch den Browser geschleust.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="460" @update:model-value="$emit('update:modelValue', $event)">
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon start>mdi-share</v-icon>
                {{ t('WhatsAppView.forwardTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="x-small" variant="text" @click="close" />
            </v-card-title>
            <v-divider />

            <!-- Vorschau der Nachricht -->
            <div v-if="message" class="wa-forward__preview">
                <div class="text-caption wa-forward__muted">{{ t('WhatsAppView.forwardPreview') }}</div>
                <div class="text-body-2 wa-forward__text">
                    <v-icon v-if="previewIcon" size="16" class="mr-1">{{ previewIcon }}</v-icon>{{ previewText }}
                </div>
            </div>
            <v-divider />

            <v-text-field
                v-model="search"
                :placeholder="t('WhatsAppView.forwardSearchRecipient')"
                prepend-inner-icon="mdi-magnify"
                variant="outlined"
                density="compact"
                hide-details
                clearable
                autofocus
                class="wa-forward__search"
            />

            <v-progress-linear :active="loading" indeterminate height="2" />
            <v-card-text class="pa-0 wa-forward__list">
                <v-list density="compact">
                    <!-- Freie Rufnummer -->
                    <v-list-item
                        v-if="freeNumber"
                        :active="isSelected(freeNumber)"
                        color="primary"
                        :disabled="sending"
                        @click="selected = freeNumber"
                    >
                        <template #prepend>
                            <v-avatar size="36" class="wa-forward__avatar">
                                <v-icon size="18">mdi-dialpad</v-icon>
                            </v-avatar>
                        </template>
                        <v-list-item-title class="text-body-2 font-weight-medium">
                            {{ t('WhatsAppView.forwardToNumber', { number: freeNumber.phone_number }) }}
                        </v-list-item-title>
                    </v-list-item>

                    <v-list-item
                        v-for="recipient in recipients"
                        :key="recipientKey(recipient)"
                        :active="isSelected(recipient)"
                        color="primary"
                        :disabled="sending"
                        @click="selected = recipient"
                    >
                        <template #prepend>
                            <v-avatar size="36" class="wa-forward__avatar">
                                <v-icon size="18">{{ recipient.has_chat ? 'mdi-whatsapp' : (recipient.src === 'V' ? 'mdi-truck-outline' : 'mdi-account-outline') }}</v-icon>
                            </v-avatar>
                        </template>
                        <v-list-item-title class="text-body-2 font-weight-medium">{{ recipient.name }}</v-list-item-title>
                        <v-list-item-subtitle class="text-caption">
                            {{ recipient.phone_number }}
                            <template v-if="!recipient.has_chat"> · {{ recipient.src === 'V' ? t('WhatsAppView.forwardVendor') : t('WhatsAppView.forwardCustomer') }}</template>
                        </v-list-item-subtitle>
                    </v-list-item>
                </v-list>
                <div v-if="!loading && !recipients.length && !freeNumber" class="text-center text-body-2 wa-forward__muted wa-forward__empty">
                    {{ t('WhatsAppView.forwardNoResults') }}
                </div>
            </v-card-text>

            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" :disabled="sending" @click="close">{{ t('WhatsAppView.cancel') }}</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-share" :loading="sending" :disabled="!selected" @click="doForward">
                    {{ t('WhatsAppView.forward') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import * as toast from '@/core/utils/toasts.js'

/** Nachrichtentypen, die sich nicht weiterleiten lassen (kein Inhalt vorhanden) */
const NOT_FORWARDABLE = ['reaction', 'unsupported', 'system']

/**
 * Lässt sich die Nachricht weiterleiten? (für den Knopf an der Nachricht)
 *
 * @param {object} msg - Zeile aus whatsapp_messages
 * @return {boolean}
 */
export function canForwardWhatsApp(msg) {
    if (!msg || NOT_FORWARDABLE.includes(msg.message_type)) return false
    if (msg.message_type === 'sticker') return !!msg.media_url
    return !!(msg.media_url || (msg.message_text || '').trim())
}

const PREVIEW_ICONS = {
    image: 'mdi-image',
    video: 'mdi-video',
    audio: 'mdi-microphone',
    voice: 'mdi-microphone',
    document: 'mdi-file-document-outline',
    location: 'mdi-map-marker',
    contacts: 'mdi-account-circle',
    sticker: 'mdi-sticker-emoji'
}

export default {
    name: 'WhatsappForwardDialog',
    props: {
        modelValue: { type: Boolean, default: false },
        message: { type: Object, default: null }
    },
    emits: ['update:modelValue', 'forwarded'],
    setup(props, { emit }) {
        const { t } = useI18n()

        const search = ref('')
        const recipients = ref([])
        const selected = ref(null)
        const loading = ref(false)
        const sending = ref(false)
        let searchTimeout = null
        let searchRun = 0

        const previewIcon = computed(() => PREVIEW_ICONS[props.message?.message_type] || '')

        const previewText = computed(() => {
            const msg = props.message
            if (!msg) return ''
            if (msg.message_type === 'contacts') {
                try {
                    return JSON.parse(msg.message_text).map(contact => contact.name).filter(Boolean).join(', ')
                } catch {
                    return msg.message_text || ''
                }
            }
            if (msg.message_type === 'audio' || msg.message_type === 'voice') return t('WhatsAppView.voiceMessage')
            return msg.media_caption || msg.message_text || ''
        })

        // Sieht der Suchtext wie eine Rufnummer aus, lässt sich direkt dorthin senden
        const freeNumber = computed(() => {
            const text = (search.value || '').trim()
            if (!/^\+?[\d\s/()-]+$/.test(text) || text.replace(/\D/g, '').length < 6) return null
            return { phone_number: text, name: '', customer_id: null, src: null, has_chat: false, free: true }
        })

        function recipientKey(recipient) {
            return (recipient.free ? 'free' : (recipient.customer_id || '')) + '|' + recipient.phone_number
        }

        function isSelected(recipient) {
            return !!selected.value && recipientKey(selected.value) === recipientKey(recipient)
        }

        async function loadRecipients() {
            const run = ++searchRun
            loading.value = true
            try {
                const resp = await axios.post('/api/whatsapp/', {
                    action: 'searchWhatsAppRecipients',
                    query: (search.value || '').trim()
                })
                if (run !== searchRun) return
                recipients.value = resp.data.success ? (resp.data.payload?.recipients || []) : []
            } catch {
                if (run === searchRun) recipients.value = []
            } finally {
                if (run === searchRun) loading.value = false
            }
        }

        watch(search, () => {
            selected.value = null
            clearTimeout(searchTimeout)
            searchTimeout = setTimeout(loadRecipients, 300)
        })

        // Beim Öffnen: leer anfangen, zuletzt genutzte Chats anbieten
        watch(() => props.modelValue, (open) => {
            if (!open) return
            clearTimeout(searchTimeout)
            search.value = ''
            selected.value = null
            recipients.value = []
            loadRecipients()
        })

        function close() {
            emit('update:modelValue', false)
        }

        async function doForward() {
            if (!props.message || !selected.value || sending.value) return
            const recipient = selected.value
            sending.value = true
            try {
                const resp = await axios.post('/api/whatsapp/', {
                    action: 'forwardWhatsAppMessage',
                    message_id: props.message.id,
                    to: recipient.phone_number,
                    customer_id: recipient.customer_id || 0,
                    customer_name: recipient.name || ''
                })
                if (resp.data.success) {
                    toast.success(t('WhatsAppView.forwardSuccess'))
                    emit('forwarded', recipient)
                    close()
                } else {
                    const reason = typeof resp.data.payload === 'string' ? resp.data.payload : ''
                    toast.error(t('WhatsAppView.forwardError'), { text: reason })
                }
            } catch {
                toast.error(t('WhatsAppView.forwardError'))
            } finally {
                sending.value = false
            }
        }

        return {
            t, search, recipients, selected, loading, sending,
            previewIcon, previewText, freeNumber,
            recipientKey, isSelected, close, doForward
        }
    }
}
</script>

<style scoped>
.wa-forward__preview {
    padding: var(--oserp-gap-sm) var(--oserp-gap);
    background: var(--oserp-surface-soft);
}
.wa-forward__muted {
    color: var(--oserp-text-muted);
}
.wa-forward__text {
    white-space: pre-wrap;
    max-height: 80px;
    overflow: hidden;
}
.wa-forward__search {
    margin: var(--oserp-gap-sm) var(--oserp-gap);
}
.wa-forward__list {
    height: 340px;
    overflow-y: auto;
}
.wa-forward__avatar {
    background: var(--oserp-primary-soft);
    color: var(--oserp-primary-strong);
}
.wa-forward__empty {
    padding: var(--oserp-gap-lg);
}
</style>
