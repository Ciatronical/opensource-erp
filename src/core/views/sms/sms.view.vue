<!-- src/core/views/sms/sms.view.vue -->
<!--
    Kontakt → SMS: alle SMS-Gespräche (ein- und ausgehend, auch Ausweich-SMS statt
    WhatsApp und Belege aus der Faktura) und neue SMS an Kunden oder freie Nummern.
    ?phone=<nummer>&cv=<id> öffnet direkt das Gespräch (Telefonknöpfe).
-->
<template>
    <NavbarView />
    <v-container class="sms-page" fluid>
        <page-header :title="t('SmsView.title')" :subtitle="t('SmsView.subtitle')">
            <template #actions>
                <v-chip v-if="setup.balance" variant="tonal" prepend-icon="mdi-wallet-outline" class="me-2" :title="t('SmsView.balanceHint')">
                    {{ formatMoney(setup.balance.amount) }}
                </v-chip>
                <v-btn color="primary" prepend-icon="mdi-message-plus-outline" :disabled="!smsConfig.configured" @click="openCompose">
                    {{ t('SmsView.compose') }}
                </v-btn>
            </template>
        </page-header>

        <v-alert v-if="configChecked && !smsConfig.configured" type="info" variant="tonal" class="mb-3">
            {{ t('SmsView.notConfigured') }}
            <template #append>
                <v-btn variant="tonal" size="small" @click="router.push({ name: 'client-defaults', query: { tab: 'crm', focus: 'sms_api_key' } })">
                    {{ t('SmsView.openConfig') }}
                </v-btn>
            </template>
        </v-alert>

        <v-row>
            <v-col cols="12" md="4">
                <section-card :title="t('SmsView.conversations')" icon="mdi-forum-outline" plain>
                    <template #actions>
                        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" :title="t('SmsView.refresh')" @click="loadConversations" />
                    </template>
                    <div class="sms-page__search">
                        <v-text-field
                            v-model="search"
                            :placeholder="t('SmsView.search')"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                        />
                    </div>
                    <div v-if="loading && !conversations.length" class="sms-page__empty"><v-progress-circular indeterminate /></div>
                    <div v-else-if="!conversations.length" class="sms-page__empty">
                        <v-icon size="44" class="mb-2">mdi-message-text-outline</v-icon>
                        <div>{{ search ? t('SmsView.noHits') : t('SmsView.noConversations') }}</div>
                    </div>
                    <v-list v-else density="compact" class="pa-0 sms-page__list">
                        <v-list-item
                            v-for="conv in conversations"
                            :key="conv.phone_number"
                            :active="selected?.phone_number === conv.phone_number"
                            color="primary"
                            class="sms-page__conv"
                            @click="select(conv)"
                        >
                            <template #prepend>
                                <v-avatar size="36" color="primary" variant="tonal" class="text-body-2 font-weight-bold">
                                    <template v-if="conv.customer_name || conv.contact_name">{{ initials(conv.customer_name || conv.contact_name) }}</template>
                                    <v-icon v-else size="20">mdi-cellphone</v-icon>
                                </v-avatar>
                            </template>
                            <v-list-item-title class="text-body-2">
                                {{ conv.customer_name || conv.contact_name || formatPhone(conv.phone_number) }}
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                <v-icon v-if="conv.last_status === 'failed'" size="12" color="error">mdi-alert-circle</v-icon>
                                <span v-if="conv.last_direction === 'O'">{{ t('SmsView.you') }}: </span>{{ conv.last_message }}
                            </v-list-item-subtitle>
                            <template #append>
                                <div class="text-right">
                                    <div class="text-caption sms-page__muted">{{ formatShort(conv.last_message_time) }}</div>
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
                    :title="selected.customer_name || selected.contact_name || formatPhone(selected.phone_number)"
                    :subtitle="formatPhone(selected.phone_number)"
                    icon="mdi-message-text-outline"
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
                            {{ selected.src === 'V' ? t('SmsView.openVendor') : t('SmsView.openCustomer') }}
                        </v-btn>
                        <v-btn icon="mdi-close" size="small" variant="text" :title="t('SmsView.close')" @click="selected = null" />
                    </template>
                    <sms-chat
                        ref="chat"
                        :phone="selected.phone_number"
                        :customer-id="selected.customer_id"
                        :src="selected.src || 'C'"
                        :history-by-customer="false"
                        height="max(320px, calc(100vh - 400px))"
                        @sent="onSent"
                    />
                </section-card>
                <section-card v-else plain>
                    <div class="sms-page__placeholder">
                        <v-icon size="72" class="mb-3">mdi-message-text-outline</v-icon>
                        <div>{{ t('SmsView.pickConversation') }}</div>
                    </div>
                </section-card>
            </v-col>
        </v-row>

        <!-- Neue SMS: Empfänger wählen, dann schreibt man im Chat -->
        <v-dialog v-model="compose.show" max-width="520">
            <section-card :title="t('SmsView.compose')" icon="mdi-message-plus-outline">
                <template #actions>
                    <v-btn icon="mdi-close" size="small" variant="text" @click="compose.show = false" />
                </template>
                <v-autocomplete
                    v-model="compose.customer"
                    v-model:search="compose.query"
                    :items="compose.results"
                    :loading="compose.loading"
                    :label="t('SmsView.customer')"
                    :placeholder="t('SmsView.searchCustomer')"
                    item-title="name"
                    item-value="id"
                    return-object
                    no-filter
                    clearable
                    hide-no-data
                    prepend-inner-icon="mdi-account-search"
                    class="mb-3"
                    autofocus
                    @update:search="searchCustomers"
                    @update:model-value="onCustomerPicked"
                >
                    <template #item="{ item, props: itemProps }">
                        <v-list-item v-bind="itemProps" :subtitle="[item.raw?.number ?? item.number, (item.raw?.phones ?? item.phones ?? []).join(', ')].filter(Boolean).join(' · ')" />
                    </template>
                </v-autocomplete>
                <v-combobox
                    v-model="compose.phone"
                    :items="compose.phones"
                    :label="t('SmsView.number')"
                    prepend-inner-icon="mdi-cellphone"
                    :hint="t('SmsView.numberHint')"
                    persistent-hint
                    @keydown.enter="startCompose"
                />
                <div class="d-flex justify-end ga-2 mt-4">
                    <v-btn variant="text" @click="compose.show = false">{{ t('SmsView.cancel') }}</v-btn>
                    <v-btn color="primary" :disabled="!composePhone" @click="startCompose">{{ t('SmsView.write') }}</v-btn>
                </div>
            </section-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted, onActivated, onUnmounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import SmsChat from '@/core/components/sms/sms-chat.vue'
import { useSmsConfig } from '@/core/composables/useSmsConfig.js'
import { onServerEvent } from '@/core/composables/sseClient.js'
import { formatPhone } from '@/core/utils/phoneFormat.js'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { smsConfig, loadSmsConfig } = useSmsConfig()

const conversations = ref([])
const loading = ref(false)
const search = ref('')
const selected = ref(null)
const chat = ref(null)
const configChecked = ref(false)
const setup = ref({ balance: null })
const compose = ref({ show: false, customer: null, query: '', results: [], loading: false, phones: [], phone: '' })

const MOBILE_RE = /^(\+491[5-7]|01[5-7]|\+43 ?6|06[^4]|\+41 ?7|07[^2])/

async function loadConversations() {
    loading.value = true
    try {
        const resp = await axios.post('/api/sms/', { action: 'getSmsConversations', search: search.value || '' })
        if (resp.data.success) {
            conversations.value = resp.data.payload?.conversations || []
            // Gewähltes Gespräch mit den frischen Daten (Name, ungelesen) abgleichen
            if (selected.value) {
                const fresh = conversations.value.find(c => c.phone_number === selected.value.phone_number)
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

function onSent() {
    loadConversations()
}

function openCustomer(conv) {
    router.push({ name: conv.src === 'V' ? 'change-vendor' : 'change-customer', params: { id: conv.customer_id }, query: { tab: 'sms' } })
}

// ── Neue SMS ──
function openCompose() {
    compose.value = { show: true, customer: null, query: '', results: [], loading: false, phones: [], phone: '' }
}

let searchTimer = null
function searchCustomers(query) {
    clearTimeout(searchTimer)
    if (!query || query.length < 2 || query === compose.value.customer?.name) return
    searchTimer = setTimeout(async () => {
        compose.value.loading = true
        try {
            const resp = await axios.post('/api/whatsapp/', { action: 'searchCvPhones', query })
            compose.value.results = resp.data.success ? (resp.data.payload || []) : []
        } finally {
            compose.value.loading = false
        }
    }, 250)
}

function onCustomerPicked(cv) {
    const phones = cv?.phones || []
    // Mobilnummern zuerst – Festnetz empfängt keine SMS
    compose.value.phones = [...phones].sort((a, b) => Number(MOBILE_RE.test(b.replace(/[\s\-/()]/g, ''))) - Number(MOBILE_RE.test(a.replace(/[\s\-/()]/g, ''))))
    compose.value.phone = compose.value.phones[0] || ''
}

const composePhone = computed(() => String(compose.value.phone || '').trim())

/** Rufnummer wie im Backend (smsNormalizePhone): +49… */
function normalize(phone) {
    const raw = String(phone || '').trim()
    let digits = raw.replace(/\D/g, '')
    if (!digits) return ''
    if (!raw.startsWith('+')) {
        const cc = smsConfig.value.country_code || '49'
        if (digits.startsWith('00')) digits = digits.slice(2)
        else if (digits.startsWith('0')) digits = cc + digits.slice(1)
        else digits = cc + digits
    }
    return '+' + digits
}

async function startCompose() {
    if (!composePhone.value) return
    const phone = normalize(composePhone.value)
    const cv = compose.value.customer
    compose.value.show = false
    openConversation(phone, cv ? { customer_id: cv.id, customer_name: cv.name, src: cv.type === 'vendor' ? 'V' : 'C' } : {})
}

function openConversation(phone, extra = {}) {
    const existing = conversations.value.find(c => c.phone_number === phone)
    if (existing) existing.unread_count = 0
    selected.value = existing ? { ...existing, ...Object.fromEntries(Object.entries(extra).filter(([, v]) => v)) } : { phone_number: phone, ...extra }
    nextTick(() => chat.value?.focus())
}

// ?phone= aus den Telefonknöpfen
function applyQuery() {
    if (!route.query.phone) return
    openConversation(normalize(String(route.query.phone)), route.query.cv ? { customer_id: Number(route.query.cv), customer_name: String(route.query.name || ''), src: String(route.query.src || 'C') } : {})
}

function initials(name) {
    return String(name || '?').replace(/^\+/, '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase()
}
function formatShort(value) {
    if (!value) return ''
    const d = new Date(value)
    return d.toDateString() === new Date().toDateString()
        ? d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit' })
}
function formatMoney(value) {
    return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(Number(value || 0))
}

let searchReload = null
watch(search, () => {
    clearTimeout(searchReload)
    searchReload = setTimeout(loadConversations, 300)
})
watch(() => route.query.phone, applyQuery)

let unsub = null
onMounted(async () => {
    await loadSmsConfig()
    configChecked.value = true
    await loadConversations()
    applyQuery()
    if (smsConfig.value.configured) {
        axios.post('/api/sms/', { action: 'getSmsSetup' }).then(r => { if (r.data.success) setup.value = r.data.payload }).catch(() => {})
    }
    unsub = onServerEvent('message', (event) => {
        let data
        try { data = JSON.parse(event.data) } catch { return }
        if (data.table === 'sms_messages') loadConversations()
    })
})
onActivated(loadConversations)
onUnmounted(() => { if (unsub) unsub() })
</script>

<style scoped>
.sms-page {
    padding: var(--oserp-page-pad);
}
.sms-page__search {
    padding: var(--oserp-gap-sm) var(--oserp-gap);
    border-bottom: 1px solid var(--oserp-border-soft);
}
.sms-page__list {
    max-height: calc(100vh - 330px);
    overflow-y: auto;
}
.sms-page__conv {
    border-bottom: 1px solid var(--oserp-border-soft);
}
.sms-page__empty,
.sms-page__placeholder {
    text-align: center;
    padding: var(--oserp-gap-xl);
    color: var(--oserp-text-muted);
}
.sms-page__placeholder {
    min-height: 420px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.sms-page__muted {
    color: var(--oserp-text-muted);
}
</style>
