<!-- src/features/lxcars/views/car/components/car-chat.card.vue -->

<template>
    <v-card variant="outlined" elevation="1">
        <v-card-title class="py-2 px-3 bg-deep-purple-lighten-5 d-flex align-center">
            <v-icon class="mr-2" size="small" color="deep-purple">mdi-robot-outline</v-icon>
            <span class="text-subtitle-1 font-weight-medium text-deep-purple">{{ t('CarEditView.chat.title') }}</span>
            <v-chip v-if="messages.length" size="x-small" variant="tonal" color="deep-purple" class="ml-2">{{ messages.length }}</v-chip>
            <v-spacer />
            <v-btn
                v-if="messages.length"
                size="x-small"
                variant="text"
                color="grey"
                icon="mdi-delete-outline"
                :title="t('CarEditView.chat.clear')"
                @click="confirmClear"
            />
        </v-card-title>
        <v-divider />
        <v-card-text class="pa-0">
            <!-- Chat-Verlauf -->
            <div ref="chatContainer" class="car-chat__messages">
                <template v-if="loading && !messages.length">
                    <div class="text-center py-4">
                        <v-progress-circular indeterminate size="24" width="2" color="deep-purple" />
                    </div>
                </template>
                <template v-else-if="!messages.length">
                    <div class="text-center py-6 text-medium-emphasis">
                        <v-icon size="32" color="grey-lighten-1" class="mb-2">mdi-chat-outline</v-icon>
                        <div class="text-body-2">{{ t('CarEditView.chat.empty') }}</div>
                    </div>
                </template>
                <template v-else>
                    <div
                        v-for="msg in messages"
                        :key="msg.id"
                        class="car-chat__bubble"
                        :class="msg.role === 'user' ? 'car-chat__bubble--user' : 'car-chat__bubble--assistant'"
                    >
                        <div class="car-chat__bubble-content" v-html="renderMessage(msg.content)" />
                        <div class="car-chat__bubble-time">{{ msg.created_at }}</div>
                    </div>
                </template>
            </div>

            <!-- Eingabe -->
            <div class="car-chat__input">
                <v-textarea
                    v-model="inputMessage"
                    :placeholder="t('CarEditView.chat.placeholder')"
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
</template>

<script>
import { ref, nextTick, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import Swal from 'sweetalert2'

export default {
    name: 'CarChatCard',

    props: {
        carId: {
            type: Number,
            required: true
        }
    },

    setup(props) {
        const { t } = useI18n()
        const carsStore = lxcarsStore()

        const messages = ref([])
        const inputMessage = ref('')
        const sending = ref(false)
        const loading = ref(false)
        const chatContainer = ref(null)

        async function loadChat() {
            if (!props.carId) return
            loading.value = true
            try {
                messages.value = await carsStore.loadCarChat(props.carId)
                await nextTick()
                scrollToBottom()
            } catch { /* leise scheitern beim Laden */ }
            loading.value = false
        }

        async function sendMessage() {
            const text = inputMessage.value.trim()
            if (!text || sending.value) return

            // Optimistisch Nachricht anzeigen
            const tempMsg = { id: 'temp-' + Date.now(), role: 'user', content: text, created_at: '' }
            messages.value.push(tempMsg)
            inputMessage.value = ''
            await nextTick()
            scrollToBottom()

            sending.value = true
            try {
                const newMessages = await carsStore.sendCarChatMessage(props.carId, text)
                // Temp-Nachricht durch echte ersetzen
                const tempIdx = messages.value.findIndex(m => m.id === tempMsg.id)
                if (tempIdx >= 0) messages.value.splice(tempIdx, 1)
                messages.value.push(...newMessages)
                await nextTick()
                scrollToBottom()
            } catch (err) {
                // Temp-Nachricht entfernen bei Fehler
                const tempIdx = messages.value.findIndex(m => m.id === tempMsg.id)
                if (tempIdx >= 0) messages.value.splice(tempIdx, 1)
                inputMessage.value = text
                Swal.fire({ icon: 'error', title: t('CarEditView.chat.errorTitle'), text: err.message || t('CarEditView.chat.errorText'), timer: 5000 })
            }
            sending.value = false
        }

        async function confirmClear() {
            const result = await Swal.fire({
                icon: 'warning',
                title: t('CarEditView.chat.clearConfirmTitle'),
                text: t('CarEditView.chat.clearConfirmText'),
                showCancelButton: true,
                confirmButtonText: t('CarEditView.chat.clearConfirm'),
                cancelButtonText: t('CarEditView.chat.clearCancel')
            })
            if (result.isConfirmed) {
                try {
                    await carsStore.clearCarChat(props.carId)
                    messages.value = []
                } catch { /* ignore */ }
            }
        }

        function scrollToBottom() {
            if (chatContainer.value) {
                chatContainer.value.scrollTop = chatContainer.value.scrollHeight
            }
        }

        function renderMessage(content) {
            // Einfaches Markdown: **bold**, Zeilenumbrueche
            let html = content
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\n/g, '<br>')
            return html
        }

        watch(() => props.carId, (newId) => {
            if (newId) loadChat()
        }, { immediate: true })

        return {
            t, messages, inputMessage, sending, loading, chatContainer,
            sendMessage, confirmClear, renderMessage
        }
    }
}
</script>

<style scoped>
.bg-deep-purple-lighten-5 {
    background-color: #ede7f6;
}

.car-chat__messages {
    max-height: 400px;
    overflow-y: auto;
    padding: 12px;
}

.car-chat__bubble {
    margin-bottom: 10px;
    max-width: 85%;
}

.car-chat__bubble--user {
    margin-left: auto;
}

.car-chat__bubble--assistant {
    margin-right: auto;
}

.car-chat__bubble-content {
    padding: 8px 12px;
    border-radius: 12px;
    font-size: 0.875rem;
    line-height: 1.4;
    word-wrap: break-word;
}

.car-chat__bubble--user .car-chat__bubble-content {
    background-color: #ede7f6;
    color: #311b92;
    border-bottom-right-radius: 4px;
}

.car-chat__bubble--assistant .car-chat__bubble-content {
    background-color: #f5f5f5;
    color: rgba(0, 0, 0, 0.87);
    border-bottom-left-radius: 4px;
}

.car-chat__bubble-time {
    font-size: 0.6875rem;
    color: rgba(0, 0, 0, 0.4);
    margin-top: 2px;
    padding: 0 4px;
}

.car-chat__bubble--user .car-chat__bubble-time {
    text-align: right;
}

.car-chat__input {
    padding: 8px 12px 12px;
    border-top: 1px solid rgba(0, 0, 0, 0.08);
}
</style>
