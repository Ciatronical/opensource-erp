<!-- src/core/views/telegram/telegram.view.vue -->
<!--
    Kontakt → Telegram: alle Chats mit dem Firmen-Bot (Kunden, Lieferanten und
    noch unbekannte Absender) und die Antworten. Unbekannte Chats lassen sich
    einem Kunden zuordnen – danach stehen sie in seiner Kontakthistorie.
    ?chat=<id> öffnet direkt einen Chat (Info-Leiste).
-->
<template>
    <NavbarView />
    <v-container class="tg-page" fluid>
        <page-header :title="t('TelegramView.title')" :subtitle="t('TelegramView.subtitle')">
            <template #actions>
                <v-chip v-if="telegramConfig.link" variant="tonal" prepend-icon="mdi-robot-outline" :href="telegramConfig.link" target="_blank" :title="t('TelegramView.botHint')">
                    {{ telegramConfig.link.replace('https://', '') }}
                </v-chip>
            </template>
        </page-header>

        <v-alert v-if="configChecked && !telegramConfig.configured" type="info" variant="tonal" class="mb-3">
            {{ t('TelegramView.notConfigured') }}
            <template #append>
                <v-btn variant="tonal" size="small" @click="router.push({ name: 'client-defaults', query: { tab: 'crm', focus: 'telegram_bot_token' } })">
                    {{ t('TelegramView.openConfig') }}
                </v-btn>
            </template>
        </v-alert>

        <v-row>
            <v-col cols="12" md="4">
                <section-card :title="t('TelegramView.conversations')" icon="mdi-forum-outline" plain>
                    <template #actions>
                        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" :title="t('TelegramView.refresh')" @click="loadConversations" />
                    </template>
                    <div class="tg-page__search">
                        <v-text-field
                            v-model="search"
                            :placeholder="t('TelegramView.search')"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                        />
                    </div>
                    <div v-if="loading && !conversations.length" class="tg-page__empty"><v-progress-circular indeterminate /></div>
                    <div v-else-if="!conversations.length" class="tg-page__empty">
                        <v-icon size="44" class="mb-2">mdi-send-circle-outline</v-icon>
                        <div>{{ search ? t('TelegramView.noHits') : t('TelegramView.noConversations') }}</div>
                    </div>
                    <v-list v-else density="compact" class="pa-0 tg-page__list">
                        <v-list-item
                            v-for="conv in conversations"
                            :key="conv.chat_id"
                            :active="selected?.chat_id === conv.chat_id"
                            color="primary"
                            class="tg-page__conv"
                            @click="select(conv)"
                        >
                            <template #prepend>
                                <v-avatar size="36" :color="conv.customer_id ? 'primary' : 'warning'" variant="tonal" class="text-body-2 font-weight-bold">
                                    <template v-if="conv.customer_name || conv.contact_name">{{ initials(conv.customer_name || conv.contact_name) }}</template>
                                    <v-icon v-else size="20">mdi-account-question-outline</v-icon>
                                </v-avatar>
                            </template>
                            <v-list-item-title class="text-body-2">
                                {{ conv.customer_name || conv.contact_name || t('TelegramView.unknown') }}
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                <v-icon v-if="conv.last_status === 'failed'" size="12" color="error">mdi-alert-circle</v-icon>
                                <span v-if="!conv.customer_id" class="text-warning">{{ t('TelegramView.notAssigned') }} · </span>
                                <span v-if="conv.last_direction === 'O'">{{ t('TelegramView.you') }}: </span>{{ conv.last_message }}
                            </v-list-item-subtitle>
                            <template #append>
                                <div class="text-right">
                                    <div class="text-caption tg-page__muted">{{ formatShort(conv.last_message_time) }}</div>
                                    <v-badge v-if="conv.unread_count > 0" :content="conv.unread_count" color="primary" inline />
                                </div>
                            </template>
                        </v-list-item>
                    </v-list>
                </section-card>
            </v-col>

            <v-col cols="12" md="8">
                <section-card
                    v-if="selected"
                    :title="selected.customer_name || selected.contact_name || t('TelegramView.unknown')"
                    :subtitle="selected.customer_id ? (selected.contact_name || '') : t('TelegramView.notAssigned')"
                    icon="mdi-send-circle-outline"
                    plain
                >
                    <template #actions>
                        <v-btn
                            v-if="selected.customer_id"
                            size="small"
                            variant="text"
                            prepend-icon="mdi-account-arrow-right-outline"
                            @click="openCustomer(selected)"
                        >
                            {{ selected.src === 'V' ? t('TelegramView.openVendor') : t('TelegramView.openCustomer') }}
                        </v-btn>
                        <v-btn
                            size="small"
                            :variant="selected.customer_id ? 'text' : 'tonal'"
                            :color="selected.customer_id ? undefined : 'warning'"
                            prepend-icon="mdi-account-link-outline"
                            @click="openAssign"
                        >
                            {{ selected.customer_id ? t('TelegramView.reassign') : t('TelegramView.assign') }}
                        </v-btn>
                        <v-btn icon="mdi-close" size="small" variant="text" :title="t('TelegramView.close')" @click="selected = null" />
                    </template>
                    <telegram-chat
                        ref="chat"
                        :chat-id="selected.chat_id"
                        :customer-id="selected.customer_id"
                        :src="selected.src || 'C'"
                        height="max(320px, calc(100vh - 400px))"
                        @sent="loadConversations"
                    />
                </section-card>
                <section-card v-else plain>
                    <div class="tg-page__placeholder">
                        <v-icon size="72" class="mb-3">mdi-send-circle-outline</v-icon>
                        <div>{{ t('TelegramView.pickConversation') }}</div>
                        <div class="text-caption mt-2">{{ t('TelegramView.howCustomersCome', { link: telegramConfig.link.replace('https://', '') || 't.me/…' }) }}</div>
                    </div>
                </section-card>
            </v-col>
        </v-row>

        <!-- Chat einem Kunden/Lieferanten zuordnen -->
        <v-dialog v-model="assign.show" max-width="520">
            <section-card :title="t('TelegramView.assignTitle')" icon="mdi-account-link-outline">
                <template #actions>
                    <v-btn icon="mdi-close" size="small" variant="text" @click="assign.show = false" />
                </template>
                <p class="text-body-2 mb-3">{{ t('TelegramView.assignHint', { name: selected?.contact_name || t('TelegramView.unknown') }) }}</p>
                <v-autocomplete
                    v-model="assign.customer"
                    v-model:search="assign.query"
                    :items="assign.results"
                    :loading="assign.loading"
                    :label="t('TelegramView.customer')"
                    :placeholder="t('TelegramView.searchCustomer')"
                    item-title="name"
                    item-value="id"
                    return-object
                    no-filter
                    clearable
                    hide-no-data
                    prepend-inner-icon="mdi-account-search"
                    autofocus
                    @update:search="searchCustomers"
                >
                    <template #item="{ item, props: itemProps }">
                        <v-list-item v-bind="itemProps" :subtitle="[item.raw?.number ?? item.number, (item.raw?.phones ?? item.phones ?? []).join(', ')].filter(Boolean).join(' · ')" />
                    </template>
                </v-autocomplete>
                <div class="d-flex justify-end ga-2 mt-4">
                    <v-btn variant="text" @click="assign.show = false">{{ t('TelegramView.cancel') }}</v-btn>
                    <v-btn color="primary" :disabled="!assign.customer" :loading="assign.saving" @click="saveAssign">{{ t('TelegramView.assign') }}</v-btn>
                </div>
            </section-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, watch, onMounted, onActivated, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import TelegramChat from '@/core/components/telegram/telegram-chat.vue'
import { useTelegramConfig } from '@/core/composables/useTelegramConfig.js'
import { onServerEvent } from '@/core/composables/sseClient.js'
import * as toast from '@/core/utils/toasts.js'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { telegramConfig, loadTelegramConfig } = useTelegramConfig()

const conversations = ref([])
const loading = ref(false)
const search = ref('')
const selected = ref(null)
const chat = ref(null)
const configChecked = ref(false)
const assign = ref({ show: false, customer: null, query: '', results: [], loading: false, saving: false })

async function loadConversations() {
    loading.value = true
    try {
        const resp = await axios.post('/api/telegram/', { action: 'getTelegramConversations', search: search.value || '' })
        if (resp.data.success) {
            conversations.value = resp.data.payload?.conversations || []
            if (selected.value) {
                const fresh = conversations.value.find(c => c.chat_id === selected.value.chat_id)
                if (fresh) selected.value = { ...selected.value, ...fresh, unread_count: 0 }
            }
        }
    } finally {
        loading.value = false
    }
}

function select(conv) {
    selected.value = conv
    conv.unread_count = 0
}

function openCustomer(conv) {
    router.push({ name: conv.src === 'V' ? 'change-vendor' : 'change-customer', params: { id: conv.customer_id }, query: { tab: 'telegram' } })
}

// ── Zuordnen ──
function openAssign() {
    assign.value = { show: true, customer: null, query: '', results: [], loading: false, saving: false }
}

let searchTimer = null
function searchCustomers(query) {
    clearTimeout(searchTimer)
    if (!query || query.length < 2 || query === assign.value.customer?.name) return
    searchTimer = setTimeout(async () => {
        assign.value.loading = true
        try {
            const resp = await axios.post('/api/whatsapp/', { action: 'searchCvPhones', query })
            assign.value.results = resp.data.success ? (resp.data.payload || []) : []
        } finally {
            assign.value.loading = false
        }
    }, 250)
}

async function saveAssign() {
    const cv = assign.value.customer
    if (!cv || !selected.value) return
    assign.value.saving = true
    try {
        const src = cv.type === 'vendor' ? 'V' : 'C'
        const resp = await axios.post('/api/telegram/', { action: 'linkTelegramChat', chat_id: selected.value.chat_id, customer_id: cv.id, src })
        if (!resp.data.success) throw new Error()
        selected.value = { ...selected.value, customer_id: cv.id, customer_name: cv.name, src }
        assign.value.show = false
        toast.success(t('TelegramView.assigned', { name: cv.name }))
        loadConversations()
    } catch {
        toast.error(t('TelegramView.assignError'))
    } finally {
        assign.value.saving = false
    }
}

// ?chat= aus der Info-Leiste
function applyQuery() {
    const id = String(route.query.chat || '')
    if (!id) return
    const existing = conversations.value.find(c => c.chat_id === id)
    selected.value = existing || { chat_id: id }
}

function initials(name) {
    return String(name || '?').replace(/^@/, '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase()
}
function formatShort(value) {
    if (!value) return ''
    const d = new Date(value)
    return d.toDateString() === new Date().toDateString()
        ? d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit' })
}

let searchReload = null
watch(search, () => {
    clearTimeout(searchReload)
    searchReload = setTimeout(loadConversations, 300)
})
watch(() => route.query.chat, applyQuery)

let unsub = null
onMounted(async () => {
    await loadTelegramConfig()
    configChecked.value = true
    await loadConversations()
    applyQuery()
    unsub = onServerEvent('message', (event) => {
        let data
        try { data = JSON.parse(event.data) } catch { return }
        if (data.table === 'telegram_messages') loadConversations()
    })
})
onActivated(loadConversations)
onUnmounted(() => { if (unsub) unsub() })
</script>

<style scoped>
.tg-page {
    padding: var(--oserp-page-pad);
}
.tg-page__search {
    padding: var(--oserp-gap-sm) var(--oserp-gap);
    border-bottom: 1px solid var(--oserp-border-soft);
}
.tg-page__list {
    max-height: calc(100vh - 330px);
    overflow-y: auto;
}
.tg-page__conv {
    border-bottom: 1px solid var(--oserp-border-soft);
}
.tg-page__empty,
.tg-page__placeholder {
    text-align: center;
    padding: var(--oserp-gap-xl);
    color: var(--oserp-text-muted);
}
.tg-page__placeholder {
    min-height: 420px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.tg-page__muted {
    color: var(--oserp-text-muted);
}
</style>
