<!-- src/core/components/chat/chat.panel.vue -->
<!-- Mitarbeiter-Chat als rechtes Drawer. Oeffnet sich automatisch, sobald eine
     Nachricht eintrifft (siehe useChat.js) — der Kollege sieht sie sofort. -->
<template>
    <v-navigation-drawer
        v-model="panelOpen"
        location="right"
        temporary
        width="440"
        class="chat-panel"
    >
        <!-- Kopfzeile -->
        <div class="chat-panel__header">
            <v-btn
                v-if="inConversation"
                icon
                size="small"
                variant="text"
                :title="t('Chat.back')"
                @click="closeConversation"
            >
                <v-icon>mdi-arrow-left</v-icon>
            </v-btn>
            <v-icon v-else color="primary" class="me-2">mdi-forum</v-icon>

            <div class="chat-panel__title">
                <div class="text-subtitle-1 font-weight-bold">
                    {{ inConversation ? activeTitle : t('Chat.title') }}
                </div>
                <div v-if="!inConversation" class="text-caption text-medium-emphasis">
                    {{ t('Chat.subtitle') }}
                </div>
            </div>

            <v-spacer />
            <v-btn icon size="small" variant="text" :title="t('Chat.close')" @click="panelOpen = false">
                <v-icon>mdi-close</v-icon>
            </v-btn>
        </div>

        <!-- Uebersicht: Unterhaltungen + Kollegen -->
        <div v-if="!inConversation" class="chat-panel__body">
            <v-text-field
                v-model="search"
                :placeholder="t('Chat.searchPlaceholder')"
                prepend-inner-icon="mdi-magnify"
                variant="outlined"
                density="compact"
                hide-details
                clearable
                class="ma-3"
            />

            <div class="chat-panel__scroll">
                <v-progress-linear v-if="loadingOverview && !conversations.length" indeterminate color="primary" />

                <!-- Laufende Unterhaltungen -->
                <template v-if="filteredConversations.length">
                    <div class="chat-panel__section">{{ t('Chat.conversations') }}</div>
                    <v-list density="compact" class="py-0">
                        <v-list-item
                            v-for="conv in filteredConversations"
                            :key="conv.id"
                            @click="openConversation(conv.id)"
                        >
                            <template #prepend>
                                <v-avatar size="36" color="primary" class="me-3">
                                    <span class="text-caption font-weight-bold">{{ initials(conv.title || conv.partner_names) }}</span>
                                </v-avatar>
                            </template>
                            <v-list-item-title class="font-weight-medium">
                                {{ conv.title || conv.partner_names || t('Chat.unknown') }}
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-truncate">
                                <span v-if="conv.last_employee_id === myEmployeeId" class="text-medium-emphasis">{{ t('Chat.youPrefix') }} </span>
                                {{ conv.last_message || t('Chat.noMessages') }}
                            </v-list-item-subtitle>
                            <template #append>
                                <div class="d-flex flex-column align-end">
                                    <span class="text-caption text-medium-emphasis">{{ shortTime(conv.last_itime) }}</span>
                                    <v-badge
                                        v-if="Number(conv.unread) > 0"
                                        :content="conv.unread"
                                        color="error"
                                        inline
                                        class="mt-1"
                                    />
                                </div>
                            </template>
                        </v-list-item>
                    </v-list>
                </template>

                <!-- Kollegen fuer einen neuen Chat -->
                <div class="chat-panel__section">{{ t('Chat.newChat') }}</div>
                <div v-if="!filteredEmployees.length" class="text-caption text-medium-emphasis px-4 py-2">
                    {{ t('Chat.noEmployees') }}
                </div>
                <v-list density="compact" class="py-0">
                    <v-list-item
                        v-for="emp in filteredEmployees"
                        :key="emp.id"
                        @click="openChatWith(emp)"
                    >
                        <template #prepend>
                            <v-avatar size="32" color="grey-lighten-1" class="me-3">
                                <span class="text-caption font-weight-bold">{{ initials(emp.name) }}</span>
                            </v-avatar>
                        </template>
                        <v-list-item-title>{{ emp.name }}</v-list-item-title>
                        <template #append>
                            <v-icon size="small" color="medium-emphasis">mdi-message-plus-outline</v-icon>
                        </template>
                    </v-list-item>
                </v-list>
            </div>
        </div>

        <!-- Unterhaltung -->
        <div v-else class="chat-panel__body">
            <div ref="messageBox" class="chat-panel__messages">
                <v-progress-linear v-if="loadingMessages" indeterminate color="primary" />

                <div v-if="!messages.length && !loadingMessages" class="text-center py-8 text-medium-emphasis">
                    <v-icon size="40" class="mb-2">mdi-message-text-outline</v-icon>
                    <div class="text-body-2">{{ t('Chat.startHint', { name: activeTitle }) }}</div>
                </div>

                <div
                    v-for="msg in messages"
                    :key="msg.id"
                    class="chat-panel__bubble"
                    :class="msg.employee_id === myEmployeeId ? 'chat-panel__bubble--own' : 'chat-panel__bubble--other'"
                >
                    <div v-if="msg.employee_id !== myEmployeeId" class="chat-panel__sender">{{ msg.employee_name }}</div>
                    <div class="chat-panel__text">{{ msg.message }}</div>
                    <div class="chat-panel__meta">
                        <span>{{ shortTime(msg.itime) }}</span>
                        <v-icon
                            v-if="msg.employee_id === myEmployeeId"
                            size="14"
                            class="ms-1"
                            :color="msg.id <= partnerReadId ? 'primary' : undefined"
                            :title="msg.id <= partnerReadId ? t('Chat.read') : t('Chat.sent')"
                        >{{ msg.id <= partnerReadId ? 'mdi-check-all' : 'mdi-check' }}</v-icon>
                        <v-btn
                            v-if="msg.employee_id === myEmployeeId"
                            icon
                            size="x-small"
                            variant="text"
                            class="chat-panel__delete"
                            :title="t('Chat.delete')"
                            @click="removeMessage(msg)"
                        >
                            <v-icon size="14">mdi-delete-outline</v-icon>
                        </v-btn>
                    </div>
                </div>
            </div>

            <div class="chat-panel__input">
                <v-textarea
                    v-model="draft"
                    :placeholder="t('Chat.messagePlaceholder')"
                    variant="outlined"
                    density="compact"
                    hide-details
                    rows="1"
                    max-rows="5"
                    auto-grow
                    :disabled="sending"
                    @keydown.enter.exact.prevent="submit"
                />
                <v-btn
                    icon
                    color="primary"
                    class="ms-2"
                    :loading="sending"
                    :disabled="!draft.trim()"
                    :title="t('Chat.send')"
                    @click="submit"
                >
                    <v-icon>mdi-send</v-icon>
                </v-btn>
            </div>
        </div>
    </v-navigation-drawer>
</template>

<script>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as alerts from '@/core/utils/alerts.js'
import {
    panelOpen, conversations, employees, messages, activeConversationId, activeTitle,
    pendingEmployee, partnerReadId, loadingOverview, loadingMessages, sending,
    openConversation, openChatWith, closeConversation, sendMessage, deleteMessage,
} from '@/core/composables/useChat.js'

export default {
    name: 'ChatPanel',
    setup() {
        const { t, locale } = useI18n()
        const oserp = oserpStore()

        const search = ref('')
        const draft = ref('')
        const messageBox = ref(null)

        const myEmployeeId = computed(() => oserp.session?.logged_in_employee?.id || 0)
        const inConversation = computed(() => !!activeConversationId.value || !!pendingEmployee.value)

        const filteredConversations = computed(() => {
            const q = (search.value || '').toLowerCase().trim()
            if (!q) return conversations.value
            return conversations.value.filter(c =>
                `${c.title || ''} ${c.partner_names || ''} ${c.last_message || ''}`.toLowerCase().includes(q)
            )
        })

        const filteredEmployees = computed(() => {
            const q = (search.value || '').toLowerCase().trim()
            if (!q) return employees.value
            return employees.value.filter(e => (e.name || '').toLowerCase().includes(q))
        })

        function initials(name) {
            if (!name) return '?'
            return name.trim().split(/\s+/).slice(0, 2).map(p => p[0]).join('').toUpperCase()
        }

        // Heute nur die Uhrzeit, aelteres mit Datum — im Chat zaehlt der schnelle Blick
        function shortTime(value) {
            if (!value) return ''
            const d = new Date(value)
            if (Number.isNaN(d.getTime())) return ''
            const today = new Date()
            const sameDay = d.toDateString() === today.toDateString()
            return sameDay
                ? d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
                : d.toLocaleString(locale.value, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
        }

        function scrollToBottom() {
            nextTick(() => {
                if (messageBox.value) messageBox.value.scrollTop = messageBox.value.scrollHeight
            })
        }

        watch(messages, scrollToBottom, { deep: true })
        watch(inConversation, (open) => { if (open) scrollToBottom() })

        async function submit() {
            const text = draft.value.trim()
            if (!text || sending.value) return
            const ok = await sendMessage(text)
            if (ok) {
                draft.value = ''
                scrollToBottom()
            } else {
                alerts.error(t('Chat.sendError'))
            }
        }

        async function removeMessage(msg) {
            const res = await alerts.question(
                t('Chat.confirmDeleteText'),
                t('Chat.confirmDeleteTitle'),
                t('Chat.delete'),
                t('Chat.cancel'),
            )
            if (!res.isConfirmed) return
            await deleteMessage(msg.id)
        }

        return {
            t, panelOpen, conversations, employees, messages, activeTitle, partnerReadId,
            loadingOverview, loadingMessages, sending,
            search, draft, messageBox, myEmployeeId, inConversation,
            filteredConversations, filteredEmployees,
            initials, shortTime, submit, removeMessage,
            openConversation, openChatWith, closeConversation,
        }
    },
}
</script>

<style scoped>
.chat-panel__header {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.chat-panel__title {
    min-width: 0;
}

.chat-panel__title .text-subtitle-1 {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat-panel__body {
    display: flex;
    flex-direction: column;
    height: calc(100% - 57px);
}

.chat-panel__scroll {
    flex: 1;
    overflow-y: auto;
}

.chat-panel__section {
    padding: 8px 16px 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.6;
}

.chat-panel__messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.chat-panel__bubble {
    max-width: 80%;
    padding: 6px 10px;
    border-radius: 12px;
    word-break: break-word;
    white-space: pre-wrap;
}

.chat-panel__bubble--own {
    align-self: flex-end;
    background: rgba(var(--v-theme-primary), 0.14);
    border-bottom-right-radius: 4px;
}

.chat-panel__bubble--other {
    align-self: flex-start;
    background: rgba(var(--v-theme-on-surface), 0.07);
    border-bottom-left-radius: 4px;
}

.chat-panel__sender {
    font-size: 0.7rem;
    font-weight: 700;
    opacity: 0.7;
    margin-bottom: 2px;
}

.chat-panel__text {
    font-size: 0.9rem;
    line-height: 1.35;
}

.chat-panel__meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    font-size: 0.65rem;
    opacity: 0.65;
    margin-top: 2px;
}

.chat-panel__delete {
    opacity: 0;
    transition: opacity 0.15s;
}

.chat-panel__bubble:hover .chat-panel__delete {
    opacity: 1;
}

.chat-panel__input {
    display: flex;
    align-items: flex-end;
    padding: 8px;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
</style>
