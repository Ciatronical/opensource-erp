<!-- src/features/weroni/components/weroni-panel.vue -->

<template>
    <v-navigation-drawer
        v-model="store.panelOpen"
        location="right"
        temporary
        width="480"
        class="weroni-panel"
    >
        <!-- Header -->
        <div class="weroni-panel__header">
            <div class="d-flex align-center">
                <img :src="weroniIcon36" width="36" height="36" alt="Weroni" class="weroni-panel__avatar" />
                <div class="ml-2">
                    <div class="text-subtitle-1 font-weight-bold text-deep-purple-darken-1">Weroni</div>
                    <div class="text-caption text-medium-emphasis">{{ t('weroni.subtitle') }}</div>
                </div>
                <v-spacer />
                <v-btn icon size="small" variant="text" @click="store.panelOpen = false">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </div>
            <v-tabs v-model="store.activeTab" density="compact" color="deep-purple" class="mt-1">
                <v-tab value="chat" size="small">
                    <v-icon start size="small">mdi-chat-outline</v-icon>
                    {{ t('weroni.tabs.chat') }}
                </v-tab>
                <v-tab value="tasks" size="small">
                    <v-icon start size="small">mdi-checkbox-marked-outline</v-icon>
                    {{ t('weroni.tabs.tasks') }}
                    <v-badge v-if="taskCount" :content="taskCount" color="deep-purple" inline class="ml-1" />
                </v-tab>
                <v-tab value="questions" size="small">
                    <v-icon start size="small">mdi-help-circle-outline</v-icon>
                    {{ t('weroni.tabs.questions') }}
                    <v-badge v-if="store.pendingQuestionCount" :content="store.pendingQuestionCount" color="error" inline class="ml-1" />
                </v-tab>
            </v-tabs>
        </div>

        <!-- Chat Tab -->
        <div
            v-show="store.activeTab === 'chat'"
            class="weroni-panel__body"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onFileDrop"
        >
            <!-- Drop-Zone Overlay -->
            <div v-if="dragOver" class="weroni-panel__dropzone">
                <v-icon size="48" color="deep-purple">mdi-file-upload-outline</v-icon>
                <div class="text-body-2 mt-2">{{ t('weroni.chat.dropHere') }}</div>
            </div>
            <div ref="chatContainer" class="weroni-panel__messages">
                <div v-if="!messages.length && !loading" class="text-center py-8 text-medium-emphasis">
                    <img :src="weroniIcon56" width="56" height="56" alt="Weroni" class="mx-auto mb-3 d-block" style="border-radius: 50%;" />
                    <div class="text-body-2">{{ t('weroni.chat.welcome') }}</div>
                    <div class="text-caption mt-2">{{ t('weroni.chat.examples') }}</div>
                </div>
                <div
                    v-for="msg in messages"
                    :key="msg.id"
                    class="weroni-panel__bubble"
                    :class="msg.role === 'user' ? 'weroni-panel__bubble--user' : 'weroni-panel__bubble--weroni'"
                >
                    <div class="weroni-panel__bubble-content" v-html="renderMessage(msg.content)" />
                    <div class="weroni-panel__bubble-time">{{ msg.created_at }}</div>
                </div>
                <div v-if="sending" class="weroni-panel__bubble weroni-panel__bubble--weroni">
                    <div class="weroni-panel__bubble-content">
                        <v-progress-circular indeterminate size="16" width="2" color="deep-purple" class="mr-1" />
                        <span class="text-caption text-medium-emphasis">{{ t('weroni.chat.thinking') }}</span>
                    </div>
                </div>
            </div>

            <div class="weroni-panel__input">
                <v-textarea
                    v-model="inputMessage"
                    :placeholder="t('weroni.chat.placeholder')"
                    variant="outlined"
                    density="compact"
                    hide-details
                    rows="1"
                    max-rows="4"
                    auto-grow
                    :disabled="sending"
                    @keydown.enter.exact.prevent="sendMessage"
                >
                    <template #prepend-inner>
                        <v-btn icon size="x-small" variant="text" color="grey" :title="t('weroni.chat.newSession')" @click="newSession">
                            <v-icon size="small">mdi-refresh</v-icon>
                        </v-btn>
                        <v-btn icon size="x-small" variant="text" color="grey" :title="t('weroni.chat.uploadDoc')" :disabled="sending" @click="fileInput.click()">
                            <v-icon size="small">mdi-paperclip</v-icon>
                        </v-btn>
                        <input ref="fileInput" type="file" accept="image/*,application/pdf" hidden @change="onFileSelected" />
                    </template>
                    <template #append-inner>
                        <v-btn
                            icon size="small" variant="text" color="deep-purple"
                            :loading="sending" :disabled="!inputMessage.trim() || sending"
                            @click="sendMessage"
                        >
                            <v-icon>mdi-send</v-icon>
                        </v-btn>
                    </template>
                </v-textarea>
            </div>
        </div>

        <!-- Tasks Tab -->
        <div v-show="store.activeTab === 'tasks'" class="weroni-panel__body">
            <div class="weroni-panel__scroll pa-3">
                <div v-if="!tasks.length" class="text-center py-8 text-medium-emphasis">
                    <v-icon size="32" color="grey-lighten-1">mdi-checkbox-marked-circle-outline</v-icon>
                    <div class="text-body-2 mt-2">{{ t('weroni.tasks.empty') }}</div>
                </div>
                <template v-for="task in rootTasks" :key="task.id">
                    <v-card
                        variant="outlined"
                        class="mb-2"
                        :class="{
                            'border-deep-purple': task.priority >= 8,
                            'border-warning': task.status === 'pending_confirm'
                        }"
                    >
                        <v-card-text class="pa-2">
                            <div class="d-flex align-center">
                                <v-icon
                                    size="small"
                                    :color="task.status === 'done' ? 'success' : task.status === 'pending_confirm' ? 'warning' : task.priority >= 8 ? 'deep-purple' : 'grey'"
                                    class="mr-2"
                                >
                                    {{ task.status === 'done' ? 'mdi-checkbox-marked' : task.status === 'pending_confirm' ? 'mdi-help-circle' : 'mdi-checkbox-blank-outline' }}
                                </v-icon>
                                <div class="flex-grow-1">
                                    <div class="d-flex align-center">
                                        <span class="text-body-2 font-weight-medium">{{ task.title }}</span>
                                        <v-chip v-if="task.status === 'pending_confirm'" size="x-small" variant="tonal" color="warning" class="ml-2">{{ t('weroni.tasks.needsConfirm') }}</v-chip>
                                        <v-chip v-if="task.source" size="x-small" variant="text" color="grey" class="ml-1">{{ task.source }}</v-chip>
                                    </div>
                                    <div v-if="task.description" class="text-caption text-medium-emphasis">{{ task.description }}</div>
                                    <div v-if="task.due_date || task.assigned_to" class="text-caption text-medium-emphasis mt-1">
                                        <span v-if="task.due_date"><v-icon size="x-small" class="mr-1">mdi-clock-outline</v-icon>{{ task.due_date }}</span>
                                        <span v-if="task.assigned_to" class="ml-2"><v-icon size="x-small" class="mr-1">mdi-account-outline</v-icon>{{ task.assigned_to }}</span>
                                    </div>
                                </div>
                                <v-chip v-if="task.subtask_count > 0" size="x-small" variant="tonal" color="deep-purple">
                                    {{ task.subtask_done }}/{{ task.subtask_count }}
                                </v-chip>
                            </div>
                            <!-- Bestätigen/Ablehnen für pending_confirm -->
                            <div v-if="task.status === 'pending_confirm'" class="d-flex ga-2 mt-2 ml-6">
                                <v-btn size="small" variant="tonal" color="success" prepend-icon="mdi-check" @click="onConfirmTask(task.id)">
                                    {{ t('weroni.tasks.confirm') }}
                                </v-btn>
                                <v-btn size="small" variant="text" color="error" prepend-icon="mdi-close" @click="onRejectTask(task.id)">
                                    {{ t('weroni.tasks.reject') }}
                                </v-btn>
                            </div>
                            <!-- Teilaufgaben -->
                            <div v-if="getSubtasks(task.id).length" class="ml-6 mt-1">
                                <div v-for="sub in getSubtasks(task.id)" :key="sub.id" class="d-flex align-center py-1">
                                    <v-icon size="x-small" :color="sub.status === 'done' ? 'success' : 'grey'" class="mr-2">
                                        {{ sub.status === 'done' ? 'mdi-checkbox-marked' : 'mdi-checkbox-blank-outline' }}
                                    </v-icon>
                                    <span class="text-caption" :class="{ 'text-decoration-line-through': sub.status === 'done' }">{{ sub.title }}</span>
                                </div>
                            </div>
                        </v-card-text>
                    </v-card>
                </template>
            </div>
        </div>

        <!-- Questions Tab -->
        <div v-show="store.activeTab === 'questions'" class="weroni-panel__body">
            <div class="weroni-panel__scroll pa-3">
                <div v-if="!questions.length" class="text-center py-8 text-medium-emphasis">
                    <v-icon size="32" color="grey-lighten-1">mdi-check-circle-outline</v-icon>
                    <div class="text-body-2 mt-2">{{ t('weroni.questions.empty') }}</div>
                </div>
                <v-card v-for="q in questions" :key="q.id" variant="outlined" class="mb-2" :class="{ 'border-error': q.urgency >= 8 }">
                    <v-card-text class="pa-3">
                        <div class="text-body-2 font-weight-medium">{{ q.question }}</div>
                        <div v-if="q.context" class="text-caption text-medium-emphasis mt-1">{{ q.context }}</div>
                        <div class="text-caption text-medium-emphasis mt-1">{{ q.created_at }}</div>
                        <v-text-field
                            v-model="answerInputs[q.id]"
                            :placeholder="t('weroni.questions.answerPlaceholder')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            class="mt-2"
                            @keydown.enter.prevent="submitAnswer(q.id)"
                        >
                            <template #append-inner>
                                <v-btn icon size="small" variant="text" color="deep-purple" :disabled="!answerInputs[q.id]?.trim()" @click="submitAnswer(q.id)">
                                    <v-icon>mdi-send</v-icon>
                                </v-btn>
                            </template>
                        </v-text-field>
                    </v-card-text>
                </v-card>
            </div>
        </div>
    </v-navigation-drawer>
</template>

<script>
import { ref, computed, watch, nextTick, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { weroniStore } from '@/features/weroni/stores/weroni.store.js'
import weroniIcon36 from '@/assets/weroni/weroni-36.png'
import weroniIcon56 from '@/assets/weroni/weroni-56.png'

export default {
    name: 'WeroniPanel',

    setup() {
        const { t } = useI18n()
        const store = weroniStore()

        const messages = ref([])
        const inputMessage = ref('')
        const sending = ref(false)
        const loading = ref(false)
        const chatContainer = ref(null)
        const fileInput = ref(null)
        const dragOver = ref(false)

        const tasks = ref([])
        const taskCount = computed(() => tasks.value.filter(t => t.status !== 'done').length)
        const rootTasks = computed(() => tasks.value.filter(t => !t.parent_id))
        const getSubtasks = (parentId) => tasks.value.filter(t => t.parent_id === parentId)

        const questions = ref([])
        const answerInputs = reactive({})

        async function sendMessage() {
            const text = inputMessage.value.trim()
            if (!text || sending.value) return

            messages.value.push({ id: 'temp-' + Date.now(), role: 'user', content: text, created_at: '' })
            inputMessage.value = ''
            await nextTick()
            scrollToBottom()

            sending.value = true
            try {
                const result = await store.sendMessage(text)
                // Temp entfernen, echte Antwort hinzufügen
                messages.value = messages.value.filter(m => !String(m.id).startsWith('temp-'))
                messages.value.push(
                    { id: 'u-' + Date.now(), role: 'user', content: text, created_at: '' },
                    { id: 'a-' + Date.now(), role: 'assistant', content: result.message, created_at: '' }
                )
                await nextTick()
                scrollToBottom()
                // Tasks und Fragen aktualisieren
                refreshTasks()
                refreshQuestions()
            } catch (err) {
                messages.value = messages.value.filter(m => !String(m.id).startsWith('temp-'))
                inputMessage.value = text
                messages.value.push({
                    id: 'err-' + Date.now(), role: 'assistant',
                    content: '**Fehler:** ' + (err.message || 'Anfrage fehlgeschlagen'), created_at: ''
                })
            }
            sending.value = false
        }

        async function newSession() {
            await store.clearConversation()
            messages.value = []
        }

        function onFileSelected(event) {
            const file = event.target.files?.[0]
            if (file) uploadFile(file)
            event.target.value = ''
        }

        function onFileDrop(event) {
            dragOver.value = false
            const file = event.dataTransfer?.files?.[0]
            if (file) uploadFile(file)
        }

        async function uploadFile(file) {
            if (sending.value) return
            const maxSize = 20 * 1024 * 1024
            if (file.size > maxSize) {
                messages.value.push({ id: 'err-' + Date.now(), role: 'assistant', content: '**Fehler:** Datei zu gross (max. 20 MB)', created_at: '' })
                return
            }

            // Base64 konvertieren
            const base64 = await new Promise((resolve) => {
                const reader = new FileReader()
                reader.onload = () => resolve(reader.result.split(',')[1])
                reader.readAsDataURL(file)
            })

            messages.value.push({ id: 'temp-' + Date.now(), role: 'user', content: '📎 **' + file.name + '** wird analysiert...', created_at: '' })
            await nextTick()
            scrollToBottom()

            sending.value = true
            try {
                const result = await store.analyzeDocument(base64, file.name, file.type, inputMessage.value.trim())
                messages.value = messages.value.filter(m => !String(m.id).startsWith('temp-'))
                messages.value.push(
                    { id: 'u-' + Date.now(), role: 'user', content: '📎 ' + file.name, created_at: '' },
                    { id: 'a-' + Date.now(), role: 'assistant', content: result.message, created_at: '' }
                )
                inputMessage.value = ''
                await nextTick()
                scrollToBottom()
                refreshTasks()
            } catch (err) {
                messages.value = messages.value.filter(m => !String(m.id).startsWith('temp-'))
                messages.value.push({
                    id: 'err-' + Date.now(), role: 'assistant',
                    content: '**Fehler bei Dokumentenanalyse:** ' + (err.message || 'Unbekannter Fehler'), created_at: ''
                })
            }
            sending.value = false
        }

        function scrollToBottom() {
            if (chatContainer.value) {
                chatContainer.value.scrollTop = chatContainer.value.scrollHeight
            }
        }

        function renderMessage(content) {
            if (!content) return ''
            return content
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                // Markdown-Links [Text](https://…) klickbar machen
                .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
                .replace(/\n/g, '<br>')
        }

        async function refreshTasks() {
            try { tasks.value = await store.loadTasks() } catch { /* leise */ }
        }

        async function refreshQuestions() {
            try {
                questions.value = await store.loadQuestions()
                store.pendingQuestionCount = questions.value.length
            } catch { /* leise */ }
        }

        async function submitAnswer(questionId) {
            const answer = (answerInputs[questionId] || '').trim()
            if (!answer) return
            try {
                await store.answerQuestion(questionId, answer)
                delete answerInputs[questionId]
                refreshQuestions()
            } catch { /* leise */ }
        }

        async function onConfirmTask(taskId) {
            try {
                await store.confirmTask(taskId)
                refreshTasks()
                refreshQuestions()
            } catch { /* leise */ }
        }

        async function onRejectTask(taskId) {
            try {
                await store.rejectTask(taskId)
                refreshTasks()
                refreshQuestions()
            } catch { /* leise */ }
        }

        // Panel geöffnet → Daten laden
        watch(() => store.panelOpen, async (open) => {
            if (open) {
                loading.value = true
                try { messages.value = await store.loadConversation() } catch { /* leise */ }
                loading.value = false
                refreshTasks()
                refreshQuestions()
                await nextTick()
                scrollToBottom()
            }
        })

        return {
            t, store, weroniIcon36, weroniIcon56,
            messages, inputMessage, sending, loading, chatContainer,
            fileInput, dragOver,
            sendMessage, newSession, onFileSelected, onFileDrop, renderMessage,
            onConfirmTask, onRejectTask,
            tasks, taskCount, rootTasks, getSubtasks,
            questions, answerInputs, submitAnswer
        }
    }
}
</script>

<style scoped>
.weroni-panel__header {
    padding: 12px 16px 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.08);
    background: linear-gradient(135deg, #ede7f6 0%, #f3e5f5 100%);
}

.weroni-panel__avatar {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.weroni-panel__dropzone {
    position: absolute;
    inset: 0;
    z-index: 10;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(237, 231, 246, 0.92);
    border: 3px dashed #7E57C2;
    border-radius: 8px;
    margin: 8px;
}

.weroni-panel__body {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 130px);
}

.weroni-panel__messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
}

.weroni-panel__scroll {
    flex: 1;
    overflow-y: auto;
}

.weroni-panel__bubble {
    margin-bottom: 10px;
    max-width: 88%;
}

.weroni-panel__bubble--user {
    margin-left: auto;
}

.weroni-panel__bubble--weroni {
    margin-right: auto;
}

.weroni-panel__bubble-content {
    padding: 8px 12px;
    border-radius: 12px;
    font-size: 0.8125rem;
    line-height: 1.45;
    word-wrap: break-word;
}

.weroni-panel__bubble--user .weroni-panel__bubble-content {
    background-color: #ede7f6;
    color: #311b92;
    border-bottom-right-radius: 4px;
}

.weroni-panel__bubble--weroni .weroni-panel__bubble-content {
    background-color: #f5f5f5;
    color: rgba(0, 0, 0, 0.87);
    border-bottom-left-radius: 4px;
}

.weroni-panel__bubble-time {
    font-size: 0.6875rem;
    color: rgba(0, 0, 0, 0.35);
    margin-top: 2px;
    padding: 0 4px;
}

.weroni-panel__bubble--user .weroni-panel__bubble-time {
    text-align: right;
}

.weroni-panel__input {
    padding: 8px 12px 12px;
    border-top: 1px solid rgba(0, 0, 0, 0.08);
}

.border-deep-purple {
    border-color: #7E57C2 !important;
}

.border-error {
    border-color: #F44336 !important;
}
</style>
