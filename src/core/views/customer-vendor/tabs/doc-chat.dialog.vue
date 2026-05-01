<!-- src/core/views/customer-vendor/tabs/doc-chat.dialog.vue -->
<template>
    <v-dialog v-model="open" max-width="700" scrollable>
        <v-card>
            <v-card-title class="py-2 px-3 bg-deep-purple-lighten-5 d-flex align-center">
                <v-icon class="mr-2" size="small" color="deep-purple">mdi-robot-outline</v-icon>
                <div class="d-flex flex-column">
                    <span class="text-subtitle-1 font-weight-medium text-deep-purple">{{ t('CustomerVendorEditView.files.docChat.title') }}</span>
                    <span class="text-caption text-medium-emphasis">{{ fileName }}</span>
                </div>
                <v-spacer />
                <v-btn icon size="small" variant="text" @click="close">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <v-card-text class="pa-0">
                <!-- Chat-Verlauf -->
                <div ref="chatContainer" class="doc-chat__messages">
                    <template v-if="!messages.length">
                        <div class="text-center py-6 text-medium-emphasis">
                            <v-icon size="32" color="grey-lighten-1" class="mb-2">mdi-file-question-outline</v-icon>
                            <div class="text-body-2">{{ t('CustomerVendorEditView.files.docChat.empty') }}</div>
                        </div>
                    </template>
                    <template v-else>
                        <div
                            v-for="(msg, i) in messages"
                            :key="i"
                            class="doc-chat__bubble"
                            :class="msg.role === 'user' ? 'doc-chat__bubble--user' : 'doc-chat__bubble--assistant'"
                        >
                            <div class="doc-chat__bubble-content" v-html="renderMessage(msg.content)" />
                        </div>
                    </template>
                    <div v-if="sending" class="doc-chat__bubble doc-chat__bubble--assistant">
                        <div class="doc-chat__bubble-content">
                            <v-progress-circular indeterminate size="16" width="2" color="deep-purple" />
                        </div>
                    </div>
                </div>

                <!-- Eingabe -->
                <div class="doc-chat__input">
                    <v-textarea
                        v-model="inputMessage"
                        :placeholder="t('CustomerVendorEditView.files.docChat.placeholder')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        rows="1"
                        max-rows="4"
                        auto-grow
                        :disabled="sending"
                        @keydown.enter.exact.prevent="sendMessage"
                    >
                        <template #append-inner>
                            <v-btn
                                icon
                                size="small"
                                variant="text"
                                color="deep-purple"
                                :loading="sending"
                                :disabled="!inputMessage.trim() || sending"
                                @click="sendMessage"
                            >
                                <v-icon>mdi-send</v-icon>
                            </v-btn>
                        </template>
                    </v-textarea>
                </div>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<script>
import { ref, watch, nextTick, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Swal from 'sweetalert2'

const API_URL = '/api/customer_vendor/'

export default {
    name: 'DocChatDialog',

    props: {
        modelValue: { type: Boolean, default: false },
        filePath: { type: String, default: '' },
        cvId: { type: [String, Number], required: true },
        src: { type: String, default: 'C' },
    },

    emits: ['update:modelValue'],

    setup(props, { emit }) {
        const { t } = useI18n()

        const open = computed({
            get: () => props.modelValue,
            set: (val) => emit('update:modelValue', val),
        })

        const fileName = computed(() => props.filePath.split('/').pop() || '')

        const messages = ref([])
        const inputMessage = ref('')
        const sending = ref(false)
        const chatContainer = ref(null)

        watch(() => props.modelValue, (val) => {
            if (val) {
                messages.value = []
                inputMessage.value = ''
            }
        })

        function close() {
            open.value = false
        }

        async function sendMessage() {
            const text = inputMessage.value.trim()
            if (!text || sending.value) return

            messages.value.push({ role: 'user', content: text })
            inputMessage.value = ''
            await nextTick()
            scrollToBottom()

            sending.value = true
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'docChatMessage',
                        cv_id: props.cvId,
                        src: props.src,
                        path: props.filePath,
                        message: text,
                    }),
                })
                if (!response.ok) throw new Error(`HTTP ${response.status}`)
                const result = await response.json()
                if (!result.success) throw new Error(result.text || 'Fehler')
                messages.value.push({ role: 'assistant', content: result.payload.answer })
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: t('CustomerVendorEditView.files.docChat.errorTitle'),
                    text: err.message || t('CustomerVendorEditView.files.docChat.errorText'),
                    timer: 5000,
                })
            }
            sending.value = false
            await nextTick()
            scrollToBottom()
        }

        function scrollToBottom() {
            if (chatContainer.value) {
                chatContainer.value.scrollTop = chatContainer.value.scrollHeight
            }
        }

        function renderMessage(content) {
            return content
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\n/g, '<br>')
        }

        return { t, open, fileName, messages, inputMessage, sending, chatContainer, sendMessage, close, renderMessage }
    },
}
</script>

<style scoped>
.bg-deep-purple-lighten-5 {
    background-color: #ede7f6;
}

.doc-chat__messages {
    max-height: 420px;
    overflow-y: auto;
    padding: 12px;
}

.doc-chat__bubble {
    margin-bottom: 10px;
    max-width: 85%;
}

.doc-chat__bubble--user {
    margin-left: auto;
}

.doc-chat__bubble--assistant {
    margin-right: auto;
}

.doc-chat__bubble-content {
    padding: 8px 12px;
    border-radius: 12px;
    font-size: 0.875rem;
    line-height: 1.4;
    word-wrap: break-word;
}

.doc-chat__bubble--user .doc-chat__bubble-content {
    background-color: #ede7f6;
    color: #311b92;
    border-bottom-right-radius: 4px;
}

.doc-chat__bubble--assistant .doc-chat__bubble-content {
    background-color: #f5f5f5;
    color: rgba(0, 0, 0, 0.87);
    border-bottom-left-radius: 4px;
}

.doc-chat__input {
    padding: 8px 12px 12px;
    border-top: 1px solid rgba(0, 0, 0, 0.08);
}
</style>
