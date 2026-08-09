<!-- src/core/components/chat/chat.panel.vue -->
<!-- Mitarbeiter-Chat: links die Unterhaltungen, rechts der Verlauf.
     Oeffnet sich nur auf Klick — eine eingehende Nachricht wird stattdessen
     oben eingeblendet (siehe notifyIncoming in useChat.js).
     Der Bubble-Stil ist bewusst der vertraute Messenger-Look, die Farben
     sind aber die des Projekts (Blau wie im Favicon) — der interne Chat
     darf nicht mit dem WhatsApp-Modul (whatsapp.view.vue, gruen) und nicht
     mit Weroni (violett) verwechselt werden. -->
<template>
    <v-navigation-drawer
        v-model="panelOpen"
        location="right"
        temporary
        :width="drawerWidth"
        class="chat"
    >
        <div class="chat__root">
            <!-- ── Linke Spalte: Unterhaltungen ── -->
            <aside v-show="showList" class="chat__pane chat__pane--list" :class="{ 'chat__pane--full': !twoPane }">
                <header class="chat__header">
                    <v-avatar size="34" class="chat__avatar chat__avatar--me">
                        <span>{{ initials(meName) }}</span>
                    </v-avatar>
                    <div class="chat__headline">
                        <div class="chat__headline-title">{{ t('Chat.title') }}</div>
                        <div class="chat__headline-sub">{{ meName }}</div>
                    </div>
                    <v-spacer />
                    <v-btn icon size="small" variant="text" color="white" :title="t('Chat.close')" @click="panelOpen = false">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </header>

                <div class="chat__search">
                    <v-text-field
                        v-model="search"
                        :placeholder="t('Chat.searchPlaceholder')"
                        prepend-inner-icon="mdi-magnify"
                        variant="solo"
                        flat
                        density="compact"
                        hide-details
                        rounded="lg"
                        bg-color="#ECEFF1"
                        clearable
                    />
                </div>

                <div class="chat__scroll">
                    <v-progress-linear v-if="loadingOverview && !conversations.length" indeterminate color="#0277BD" />

                    <!-- Laufende Unterhaltungen -->
                    <div
                        v-for="conv in filteredConversations"
                        :key="conv.id"
                        class="chat__row"
                        :class="{ 'chat__row--active': conv.id === activeConversationId }"
                        @click="openConversation(conv.id)"
                    >
                        <v-avatar size="46" class="chat__avatar">
                            <span>{{ initials(conv.title || conv.partner_names) }}</span>
                        </v-avatar>
                        <div class="chat__row-body">
                            <div class="chat__row-top">
                                <span class="chat__row-name">{{ conv.title || conv.partner_names || t('Chat.unknown') }}</span>
                                <span class="chat__row-time" :class="{ 'chat__row-time--unread': Number(conv.unread) > 0 }">
                                    {{ shortTime(conv.last_itime) }}
                                </span>
                            </div>
                            <div class="chat__row-bottom">
                                <span class="chat__row-preview">
                                    <v-icon
                                        v-if="conv.last_employee_id === myEmployeeId"
                                        size="14"
                                        class="me-1"
                                        :color="conv.last_message_id <= conv.partner_read_id ? '#29B6F6' : '#90A4AE'"
                                    >{{ conv.last_message_id <= conv.partner_read_id ? 'mdi-check-all' : 'mdi-check' }}</v-icon>
                                    {{ conv.last_message || t('Chat.noMessages') }}
                                </span>
                                <span v-if="Number(conv.unread) > 0" class="chat__badge">{{ conv.unread }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Kollegen fuer einen neuen Chat -->
                    <div class="chat__section">{{ t('Chat.newChat') }}</div>
                    <div v-if="!filteredEmployees.length" class="chat__empty-hint">{{ t('Chat.noEmployees') }}</div>
                    <div
                        v-for="emp in filteredEmployees"
                        :key="emp.id"
                        class="chat__row"
                        @click="openChatWith(emp)"
                    >
                        <v-avatar size="40" class="chat__avatar chat__avatar--muted">
                            <span>{{ initials(emp.name) }}</span>
                        </v-avatar>
                        <div class="chat__row-body">
                            <div class="chat__row-name">{{ emp.name }}</div>
                            <div class="chat__row-preview text-truncate">{{ emp.login }}</div>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- ── Rechte Spalte: Verlauf ── -->
            <section v-if="inConversation" class="chat__pane chat__pane--thread">
                <header class="chat__header">
                    <v-btn
                        v-if="!twoPane"
                        icon
                        size="small"
                        variant="text"
                        color="white"
                        :title="t('Chat.back')"
                        @click="closeConversation"
                    >
                        <v-icon>mdi-arrow-left</v-icon>
                    </v-btn>
                    <v-avatar size="38" class="chat__avatar">
                        <span>{{ initials(activeTitle) }}</span>
                    </v-avatar>
                    <div class="chat__headline">
                        <div class="chat__headline-title">{{ activeTitle }}</div>
                        <div class="chat__headline-sub">{{ t('Chat.subtitle') }}</div>
                    </div>
                    <v-spacer />
                    <v-btn icon size="small" variant="text" color="white" :title="t('Chat.close')" @click="panelOpen = false">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </header>

                <div ref="messageBox" class="chat__messages">
                  <!-- Innerer Wrapper: schiebt wenige Nachrichten nach unten an die
                       Eingabezeile (wie WhatsApp), scrollt bei vielen normal -->
                  <div class="chat__messages-inner">
                    <v-progress-linear v-if="loadingMessages" indeterminate color="#0277BD" />

                    <div v-if="!messages.length && !loadingMessages" class="chat__placeholder">
                        <v-icon size="44" color="#90A4AE">mdi-message-text-outline</v-icon>
                        <div class="mt-2">{{ t('Chat.startHint', { name: activeTitle }) }}</div>
                    </div>

                    <template v-for="(msg, idx) in messages" :key="msg.id">
                        <div v-if="showDateSeparator(idx)" class="chat__date">
                            <span class="chat__date-chip">{{ dateLabel(msg.itime) }}</span>
                        </div>

                        <div class="chat__msgrow" :class="msg.employee_id === myEmployeeId ? 'chat__msgrow--out' : 'chat__msgrow--in'">
                            <div class="chat__bubble" :class="msg.employee_id === myEmployeeId ? 'chat__bubble--out' : 'chat__bubble--in'">
                                <v-btn
                                    v-if="msg.employee_id === myEmployeeId"
                                    class="chat__bubble-delete"
                                    icon
                                    size="x-small"
                                    variant="text"
                                    :title="t('Chat.delete')"
                                    @click.stop="removeMessage(msg)"
                                >
                                    <v-icon size="14" color="#90A4AE">mdi-delete-outline</v-icon>
                                </v-btn>

                                <div v-if="msg.employee_id !== myEmployeeId" class="chat__bubble-sender">
                                    {{ msg.employee_name }}
                                </div>
                                <div class="chat__bubble-text">{{ msg.message }}</div>
                                <div class="chat__bubble-meta">
                                    <span>{{ clockTime(msg.itime) }}</span>
                                    <v-icon
                                        v-if="msg.employee_id === myEmployeeId"
                                        size="15"
                                        class="ms-1"
                                        :color="msg.id <= partnerReadId ? '#29B6F6' : '#90A4AE'"
                                        :title="msg.id <= partnerReadId ? t('Chat.read') : t('Chat.sent')"
                                    >{{ msg.id <= partnerReadId ? 'mdi-check-all' : 'mdi-check' }}</v-icon>
                                </div>
                            </div>
                        </div>
                    </template>
                  </div>
                </div>

                <footer class="chat__composer">
                    <v-textarea
                        ref="composer"
                        v-model="draft"
                        :placeholder="t('Chat.messagePlaceholder')"
                        variant="solo"
                        flat
                        density="compact"
                        hide-details
                        rounded="lg"
                        bg-color="white"
                        rows="1"
                        max-rows="6"
                        auto-grow
                        no-resize
                        :disabled="sending"
                        @keydown.enter.exact.prevent="submit"
                    />
                    <v-btn
                        icon
                        class="chat__send"
                        :loading="sending"
                        :disabled="!draft.trim()"
                        :title="t('Chat.send')"
                        @click="submit"
                    >
                        <v-icon color="white">mdi-send</v-icon>
                    </v-btn>
                </footer>
            </section>

            <!-- Breiter Modus ohne gewaehlten Chat -->
            <section v-else-if="twoPane" class="chat__pane chat__pane--thread chat__intro">
                <v-icon size="72" color="#90A4AE">mdi-forum-outline</v-icon>
                <div class="text-h6 mt-3">{{ t('Chat.title') }}</div>
                <div class="text-body-2 mt-1">{{ t('Chat.pickHint') }}</div>
            </section>
        </div>
    </v-navigation-drawer>
</template>

<script>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as alerts from '@/core/utils/alerts.js'
import {
    panelOpen, conversations, employees, messages, activeConversationId, activeTitle,
    pendingEmployee, partnerReadId, loadingOverview, loadingMessages, sending, focusRequest,
    openConversation, openChatWith, closeConversation, sendMessage, deleteMessage,
} from '@/core/composables/useChat.js'

export default {
    name: 'ChatPanel',
    setup() {
        const { t, locale } = useI18n()
        const { lgAndUp, width: screenWidth } = useDisplay()
        const oserp = oserpStore()

        const search = ref('')
        const draft = ref('')
        const messageBox = ref(null)
        const composer = ref(null)

        const myEmployeeId = computed(() => oserp.session?.logged_in_employee?.id || 0)
        const meName = computed(() => oserp.session?.logged_in_employee?.name || oserp.session?.user || '')
        const inConversation = computed(() => !!activeConversationId.value || !!pendingEmployee.value)

        // Ab grossen Bildschirmen beide Spalten nebeneinander (wie WhatsApp Web),
        // darunter immer nur eine — sonst wird es zusammengequetscht.
        const twoPane = computed(() => lgAndUp.value)
        // Nie breiter als der Bildschirm — auf dem Handy sonst abgeschnitten
        const drawerWidth = computed(() =>
            Math.min(twoPane.value ? 820 : 440, screenWidth.value || 440)
        )
        const showList = computed(() => twoPane.value || !inConversation.value)

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

        function clockTime(value) {
            if (!value) return ''
            const d = new Date(value)
            if (Number.isNaN(d.getTime())) return ''
            return d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
        }

        // In der Liste zaehlt der schnelle Blick: heute die Uhrzeit, gestern das
        // Wort, alles aeltere das Datum.
        function shortTime(value) {
            if (!value) return ''
            const d = new Date(value)
            if (Number.isNaN(d.getTime())) return ''
            const now = new Date()
            const yesterday = new Date(now)
            yesterday.setDate(yesterday.getDate() - 1)
            if (d.toDateString() === now.toDateString()) return clockTime(value)
            if (d.toDateString() === yesterday.toDateString()) return t('Chat.yesterday')
            return d.toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit', year: '2-digit' })
        }

        function dateLabel(value) {
            const d = new Date(value)
            const now = new Date()
            const yesterday = new Date(now)
            yesterday.setDate(yesterday.getDate() - 1)
            if (d.toDateString() === now.toDateString()) return t('Chat.today')
            if (d.toDateString() === yesterday.toDateString()) return t('Chat.yesterday')
            return d.toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' })
        }

        function showDateSeparator(idx) {
            if (idx === 0) return true
            const prev = new Date(messages.value[idx - 1].itime)
            const curr = new Date(messages.value[idx].itime)
            return prev.toDateString() !== curr.toDateString()
        }

        function scrollToBottom() {
            nextTick(() => {
                if (messageBox.value) messageBox.value.scrollTop = messageBox.value.scrollHeight
            })
        }

        // Wer einen Chatpartner anklickt, will sofort tippen — ohne erst ins
        // Eingabefeld zu klicken.
        function focusComposer() {
            nextTick(() => {
                composer.value?.$el?.querySelector('textarea')?.focus()
            })
        }

        function openAndFocus(id) {
            openConversation(id)
            focusComposer()
        }

        function startAndFocus(employee) {
            openChatWith(employee)
            focusComposer()
        }

        watch(messages, scrollToBottom, { deep: true })
        watch(inConversation, (open) => { if (open) scrollToBottom() })
        watch(panelOpen, (open) => { if (open) scrollToBottom() })
        // Fokus nur auf bewusste Aktion: Klick auf den Chat-Button in der Navi
        watch(focusRequest, () => { if (inConversation.value) focusComposer() })

        async function submit() {
            const text = draft.value.trim()
            if (!text || sending.value) return
            const ok = await sendMessage(text)
            if (ok) {
                draft.value = ''
                scrollToBottom()
                focusComposer()   // Fokus bleibt zum Weiterschreiben
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
            t, panelOpen, conversations, employees, messages, activeTitle, activeConversationId,
            partnerReadId, loadingOverview, loadingMessages, sending,
            search, draft, messageBox, composer, myEmployeeId, meName, inConversation,
            twoPane, drawerWidth, showList,
            filteredConversations, filteredEmployees,
            initials, clockTime, shortTime, dateLabel, showDateSeparator,
            submit, removeMessage, closeConversation,
            openConversation: openAndFocus, openChatWith: startAndFocus,
        }
    },
}
</script>

<style scoped>
/* Der Drawer selbst darf nicht scrollen — das uebernehmen Liste und Verlauf
   jeweils fuer sich. Sonst rutscht die Eingabezeile aus dem Bild. */
.chat :deep(.v-navigation-drawer__content) {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.chat__root {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    background: #ffffff;
}

.chat__pane {
    display: flex;
    flex-direction: column;
    min-height: 0;
    min-width: 0;
}

.chat__pane--list {
    flex: 0 0 340px;
    max-width: 340px;
    border-right: 1px solid #DDE5EA;
}

/* Einspaltig: die Liste fuellt den Drawer allein aus */
.chat__pane--full {
    flex: 1 1 auto;
    max-width: none;
    border-right: none;
}

.chat__pane--thread {
    flex: 1 1 auto;
}

/* ── Kopfzeilen (Projektblau, wie das Favicon) ── */
.chat__header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    background: linear-gradient(135deg, #0277BD 0%, #01579B 100%);
    color: #ffffff;
    flex: 0 0 auto;
}

.chat__headline {
    min-width: 0;
}

.chat__headline-title {
    font-size: 0.95rem;
    font-weight: 600;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat__headline-sub {
    font-size: 0.72rem;
    opacity: 0.85;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat__avatar {
    background: #E1F5FE;
    color: #01579B;
    font-weight: 700;
    font-size: 0.8rem;
    flex: 0 0 auto;
}

.chat__avatar--me {
    background: #ffffff;
    color: #0277BD;
}

.chat__avatar--muted {
    background: #DDE5EA;
    color: #546E7A;
}

/* ── Suchleiste ── */
.chat__search {
    padding: 8px;
    background: #ffffff;
    border-bottom: 1px solid #DDE5EA;
    flex: 0 0 auto;
}

/* ── Unterhaltungsliste ── */
.chat__scroll {
    flex: 1 1 auto;
    overflow-y: auto;
    background: #ffffff;
}

.chat__row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #ECEFF1;
}

.chat__row:hover {
    background: #F2F6F8;
}

.chat__row--active {
    background: #ECEFF1;
}

.chat__row-body {
    flex: 1 1 auto;
    min-width: 0;
}

.chat__row-top {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}

.chat__row-name {
    font-size: 0.92rem;
    color: #263238;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat__row-time {
    font-size: 0.7rem;
    color: #607D8B;
    flex: 0 0 auto;
}

.chat__row-time--unread {
    color: #0277BD;
    font-weight: 600;
}

.chat__row-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.chat__row-preview {
    font-size: 0.8rem;
    color: #607D8B;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.chat__badge {
    flex: 0 0 auto;
    background: #0288D1;
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 700;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.chat__section {
    padding: 10px 12px 4px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #0277BD;
    background: #ffffff;
}

.chat__empty-hint {
    padding: 4px 12px 10px;
    font-size: 0.78rem;
    color: #607D8B;
}

/* ── Verlauf ── */
.chat__messages {
    flex: 1 1 auto;
    overflow-y: auto;
    background-color: #E3EDF4;
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23b0c9dc' fill-opacity='0.25'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}

.chat__messages-inner {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    min-height: 100%;
    padding: 14px 12px;
}

.chat__placeholder {
    text-align: center;
    color: #607D8B;
    font-size: 0.85rem;
    padding: 40px 12px;
}

.chat__date {
    text-align: center;
    margin: 12px 0;
}

.chat__date-chip {
    display: inline-block;
    background: rgba(255, 255, 255, 0.92);
    color: #546E7A;
    font-size: 12px;
    font-weight: 500;
    padding: 4px 12px;
    border-radius: 8px;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.08);
}

.chat__msgrow {
    display: flex;
    margin-bottom: 3px;
}

.chat__msgrow--out {
    justify-content: flex-end;
}

.chat__msgrow--in {
    justify-content: flex-start;
}

.chat__bubble {
    position: relative;
    max-width: 72%;
    min-width: 96px;
    padding: 6px 8px 4px;
    border-radius: 8px;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.1);
    word-wrap: break-word;
}

.chat__bubble--out {
    background: #D5EBFA;
    border-top-right-radius: 0;
}

.chat__bubble--in {
    background: #ffffff;
    border-top-left-radius: 0;
}

.chat__bubble-sender {
    font-size: 12px;
    font-weight: 600;
    color: #0277BD;
    margin-bottom: 2px;
}

.chat__bubble-text {
    font-size: 14px;
    line-height: 1.4;
    color: #263238;
    white-space: pre-wrap;
}

.chat__bubble-meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
    margin-top: 2px;
    font-size: 11px;
    color: #607D8B;
    line-height: 1;
}

.chat__bubble-delete {
    position: absolute;
    top: 2px;
    right: 2px;
    opacity: 0;
    transition: opacity 0.15s;
}

.chat__bubble:hover .chat__bubble-delete {
    opacity: 1;
}

/* ── Eingabezeile ── */
.chat__composer {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding: 8px 10px;
    background: #ECEFF1;
    flex: 0 0 auto;
}

.chat__send {
    background: #0277BD !important;
    flex: 0 0 auto;
}

.chat__send:disabled {
    opacity: 0.5;
}

/* ── Platzhalter im Zweispalten-Modus ── */
.chat__intro {
    align-items: center;
    justify-content: center;
    text-align: center;
    color: #455A64;
    background: #ECEFF1;
    border-left: 1px solid #DDE5EA;
}
</style>
