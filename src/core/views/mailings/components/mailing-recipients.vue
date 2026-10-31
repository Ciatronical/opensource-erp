<!-- src/core/views/mailings/components/mailing-recipients.vue -->
<!--
    Empfängerliste eines Rundschreibens (oder eines Verteilers).

    Oben die Wege, Empfänger hereinzuholen: Suche (Kunde/Lieferant), Vorschlag
    nach Kundentyp/Umsatz/Ort/Kanal, Verteiler laden, die Liste als Verteiler
    sichern. Darunter die Tabelle mit den erreichbaren Kanälen je Empfänger und
    dem geplanten Kanal (vor dem Start) bzw. dem Versandstatus (danach).
    Die Zahl der Empfänger ist auf `max` begrenzt.
-->
<template>
    <section-card :title="t('MailingRecipients.title')" icon="mdi-account-multiple-outline" plain>
        <template #actions>
            <v-chip size="small" variant="tonal" :color="recipients.length >= max ? 'error' : 'primary'">
                {{ t('MailingRecipients.count', { n: recipients.length, max }) }}
            </v-chip>
        </template>

        <!-- Empfänger hereinholen -->
        <div v-if="!readonly" class="recipients__toolbar">
            <v-btn-toggle v-model="searchSrc" mandatory density="compact" variant="outlined" color="primary" divided>
                <v-btn value="" size="small">{{ t('MailingRecipients.all') }}</v-btn>
                <v-btn value="C" size="small"><v-icon start size="small">mdi-account-outline</v-icon>{{ t('MailingRecipients.customers') }}</v-btn>
                <v-btn value="V" size="small"><v-icon start size="small">mdi-truck-outline</v-icon>{{ t('MailingRecipients.vendors') }}</v-btn>
            </v-btn-toggle>
            <v-autocomplete
                v-model="searchSelected"
                v-model:search="searchText"
                :items="searchItems"
                :loading="searchLoading"
                item-title="name"
                :item-value="recipientKey"
                return-object
                :no-filter="true"
                :label="t('MailingRecipients.search')"
                :placeholder="t('MailingRecipients.searchHint')"
                :no-data-text="searchText && searchText.length >= 2 ? t('MailingRecipients.noHits') : t('MailingRecipients.typeToSearch')"
                prepend-inner-icon="mdi-magnify"
                autocomplete="off"
                class="recipients__search"
                :disabled="recipients.length >= max"
                :menu-props="{ maxHeight: 340 }"
                :list-props="{ density: 'compact', class: 'recipients__search-list' }"
                @update:model-value="onSearchPicked"
            >
                <!-- Eine Zeile je Treffer: Name, dann Nummer · Ort klein, rechts die Kanäle -->
                <template #item="{ props: itemProps, item }">
                    <v-list-item v-bind="itemProps" :title="undefined" density="compact" class="recipients__hit">
                        <template #prepend>
                            <v-icon size="small" :color="item.cv_src === 'V' ? 'orange-darken-2' : 'primary'">
                                {{ item.cv_src === 'V' ? 'mdi-truck-outline' : 'mdi-account-outline' }}
                            </v-icon>
                        </template>
                        <div class="d-flex align-center ga-2 text-truncate">
                            <span class="text-body-2 text-truncate">{{ item.name }}</span>
                            <span class="text-caption text-medium-emphasis text-no-wrap">{{ [item.number, item.city].filter(Boolean).join(' · ') }}</span>
                        </div>
                        <template #append>
                            <channel-icons :recipient="item" :settings="settings" :config="config" />
                        </template>
                    </v-list-item>
                </template>
                <template v-if="searchItems.length >= SEARCH_LIMIT" #append-item>
                    <div class="px-4 py-2 text-caption text-medium-emphasis">{{ t('MailingRecipients.moreHits') }}</div>
                </template>
            </v-autocomplete>
            <v-btn variant="tonal" color="primary" prepend-icon="mdi-auto-fix" :disabled="recipients.length >= max" @click="openSuggest">
                {{ t('MailingRecipients.suggest') }}
            </v-btn>
            <v-select
                v-if="lists.length"
                :model-value="null"
                :items="lists"
                item-title="name"
                item-value="id"
                :label="t('MailingRecipients.loadList')"
                prepend-inner-icon="mdi-playlist-plus"
                class="recipients__lists"
                :disabled="recipients.length >= max"
                @update:model-value="loadList"
            >
                <template #item="{ props: itemProps, item }">
                    <v-list-item v-bind="itemProps" :title="item.name" :subtitle="t('MailingRecipients.members', { n: item.members })" />
                </template>
            </v-select>
            <v-btn variant="text" prepend-icon="mdi-content-save-outline" :disabled="!recipients.length" @click="openSaveList">
                {{ t('MailingRecipients.saveAsList') }}
            </v-btn>
            <v-menu v-if="recipients.length">
                <template #activator="{ props: menuProps }">
                    <v-btn v-bind="menuProps" variant="text" icon="mdi-dots-vertical" :title="t('MailingRecipients.more')" />
                </template>
                <v-list density="compact">
                    <v-list-item prepend-icon="mdi-account-off-outline" :title="t('MailingRecipients.removeWithoutChannel', { n: plan.none + plan.duplicates })" :disabled="!(plan.none + plan.duplicates)" @click="removeWithoutChannel" />
                    <v-list-item prepend-icon="mdi-delete-sweep-outline" base-color="error" :title="t('MailingRecipients.removeAll')" @click="removeAll" />
                </v-list>
            </v-menu>
        </div>

        <!-- Filter nach geplantem Kanal / Status -->
        <div v-if="recipients.length" class="recipients__filters">
            <v-chip-group v-model="filter" selected-class="text-primary" mandatory>
                <v-chip value="all" size="small" variant="tonal" filter>{{ t('MailingRecipients.all') }} {{ recipients.length }}</v-chip>
                <template v-if="showStatus">
                    <v-chip v-for="s in statusFilters" :key="s.value" :value="s.value" size="small" variant="tonal" filter :prepend-icon="s.icon" :color="s.color">
                        {{ t('MailingRecipients.status.' + s.value) }} {{ s.count }}
                    </v-chip>
                </template>
                <template v-else>
                    <v-chip v-for="c in channelFilters" :key="c.value" :value="c.value" size="small" variant="tonal" filter :prepend-icon="c.icon" :color="c.color">
                        {{ t('MailingRecipients.channel.' + c.value) }} {{ c.count }}
                    </v-chip>
                </template>
            </v-chip-group>
        </div>

        <v-data-table
            :headers="headers"
            :items="filteredRecipients"
            :items-per-page="50"
            :items-per-page-options="[25, 50, 100, 300]"
            density="compact"
            hover
            class="zebra-table recipients__table"
            :no-data-text="t('MailingRecipients.empty')"
            item-value="key"
        >
            <template #item.name="{ item }">
                <div class="d-flex align-center">
                    <v-icon size="small" class="me-1" :color="item.cv_src === 'V' ? 'orange-darken-2' : 'primary'">
                        {{ item.cv_src === 'V' ? 'mdi-truck-outline' : 'mdi-account-outline' }}
                    </v-icon>
                    <a href="#" class="text-decoration-none" :class="{ 'text-decoration-line-through text-medium-emphasis': item.obsolete }" @click.prevent="openCv(item)">
                        {{ item.name || t('MailingRecipients.deleted') }}
                    </a>
                </div>
                <div class="text-caption text-medium-emphasis recipients__detail">{{ [item.number, item.city, item.whatsapp_phone, item.email].filter(Boolean).join(' · ') }}</div>
            </template>
            <template #item.channels="{ item }">
                <channel-icons :recipient="item" :settings="settings" :config="config" detailed />
            </template>
            <template #item.plan="{ item }">
                <template v-if="showStatus && item.status && item.status !== 'pending'">
                    <v-chip size="x-small" variant="tonal" :color="statusMeta(item.status).color" :prepend-icon="statusMeta(item.status).icon"
                            :title="item.error ? errorText(item.error) : (item.sent_at ? formatWhen(item.sent_at) : '')">
                        {{ t('MailingRecipients.status.' + item.status) }}
                        <template v-if="item.channel"> · {{ t('MailingRecipients.channel.' + item.channel) }}</template>
                    </v-chip>
                    <v-chip v-if="item.email_status" size="x-small" variant="tonal" class="ms-1" :color="statusMeta(item.email_status).color" :prepend-icon="CHANNEL_META.email_extra.icon"
                            :title="item.email_error || item.email_target || ''">
                        {{ t('MailingRecipients.status.' + item.email_status) }} · {{ t('MailingRecipients.channel.email') }}
                    </v-chip>
                    <div v-if="item.error" class="text-caption text-error text-truncate recipients__error" :title="errorText(item.error)">{{ errorText(item.error) }}</div>
                    <v-chip v-if="item.letter_status" size="x-small" variant="tonal" class="ms-1" :color="statusMeta(item.letter_status).color" :prepend-icon="CHANNEL_META.letter_extra.icon"
                            :title="item.letter_error || item.letter_target || ''">
                        {{ t('MailingRecipients.status.' + item.letter_status) }} · {{ t('MailingRecipients.channel.letter') }}
                    </v-chip>
                    <div v-if="item.email_error" class="text-caption text-error text-truncate recipients__error" :title="item.email_error">{{ t('MailingRecipients.channel.email') }}: {{ item.email_error }}</div>
                    <div v-if="item.letter_error" class="text-caption text-error text-truncate recipients__error" :title="item.letter_error">{{ t('MailingRecipients.channel.letter') }}: {{ item.letter_error }}</div>
                </template>
                <template v-else-if="showStatus && item.channel">
                    <v-chip size="x-small" variant="tonal" :color="CHANNEL_META[item.channel].color" :prepend-icon="CHANNEL_META[item.channel].icon">
                        {{ t('MailingRecipients.status.pending') }} · {{ t('MailingRecipients.channel.' + item.channel) }}
                    </v-chip>
                    <v-chip v-if="item.email_target" size="x-small" variant="tonal" class="ms-1" :color="CHANNEL_META.email_extra.color" :prepend-icon="CHANNEL_META.email_extra.icon" :title="item.email_target">
                        {{ t('MailingRecipients.emailExtraShort') }}
                    </v-chip>
                    <v-chip v-if="item.letter_target" size="x-small" variant="tonal" class="ms-1" :color="CHANNEL_META.letter_extra.color" :prepend-icon="CHANNEL_META.letter_extra.icon" :title="item.letter_target">
                        {{ t('MailingRecipients.letterExtraShort') }}
                    </v-chip>
                </template>
                <template v-else>
                    <v-chip v-if="planFor(item).duplicate" size="x-small" variant="tonal" color="grey" prepend-icon="mdi-content-duplicate" :title="t('MailingRecipients.duplicateHint')">
                        {{ t('MailingRecipients.duplicate') }}
                    </v-chip>
                    <template v-else-if="planFor(item).channel">
                        <v-chip size="x-small" variant="tonal" :color="CHANNEL_META[planFor(item).channel].color" :prepend-icon="CHANNEL_META[planFor(item).channel].icon" :title="planFor(item).target">
                            {{ t('MailingRecipients.channel.' + planFor(item).channel) }}
                        </v-chip>
                        <v-chip v-if="planFor(item).email" size="x-small" variant="tonal" class="ms-1" :color="CHANNEL_META.email_extra.color" :prepend-icon="CHANNEL_META.email_extra.icon" :title="planFor(item).email">
                            {{ t('MailingRecipients.emailExtraShort') }}
                        </v-chip>
                        <v-chip v-if="planFor(item).letter" size="x-small" variant="tonal" class="ms-1" :color="CHANNEL_META.letter_extra.color" :prepend-icon="CHANNEL_META.letter_extra.icon" :title="planFor(item).letter">
                            {{ t('MailingRecipients.letterExtraShort') }}
                        </v-chip>
                    </template>
                    <v-chip v-else size="x-small" variant="tonal" color="grey" prepend-icon="mdi-account-off-outline" :title="t('MailingRecipients.noChannelHint')">
                        {{ t('MailingRecipients.channel.none') }}
                    </v-chip>
                </template>
            </template>
            <template #item.actions="{ item }">
                <v-btn v-if="!readonly" icon size="x-small" variant="text" color="error" :title="t('MailingRecipients.remove')" @click="remove(item)">
                    <v-icon size="small">mdi-close</v-icon>
                </v-btn>
            </template>
        </v-data-table>

        <!-- Vorschlag -->
        <v-dialog v-model="suggestDialog" max-width="720" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon class="me-2" color="primary">mdi-auto-fix</v-icon>
                    {{ t('MailingRecipients.suggestTitle') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" density="compact" size="small" @click="suggestDialog = false" />
                </v-card-title>
                <v-card-text>
                    <v-row density="compact">
                        <v-col cols="12" sm="6">
                            <v-btn-toggle v-model="suggest.src" mandatory density="compact" variant="outlined" color="primary" divided class="mb-2">
                                <v-btn value="" size="small">{{ t('MailingRecipients.all') }}</v-btn>
                                <v-btn value="C" size="small">{{ t('MailingRecipients.customers') }}</v-btn>
                                <v-btn value="V" size="small">{{ t('MailingRecipients.vendors') }}</v-btn>
                            </v-btn-toggle>
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-select v-model="suggest.business_id" :items="businessItems" item-title="description" item-value="id" :label="t('MailingRecipients.suggestBusiness')" :disabled="suggest.src === 'V'" variant="outlined" density="compact" hide-details />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-select v-model="suggest.months" :items="monthItems" :label="t('MailingRecipients.suggestMonths')" variant="outlined" density="compact" hide-details />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="suggest.city" :label="t('MailingRecipients.suggestCity')" variant="outlined" density="compact" hide-details clearable />
                        </v-col>
                        <v-col cols="12">
                            <v-select v-model="suggest.channel" :items="channelItems" :label="t('MailingRecipients.suggestChannel')" variant="outlined" density="compact" hide-details />
                        </v-col>
                    </v-row>

                    <v-alert v-if="suggestResult" :type="suggestResult.total ? 'info' : 'warning'" variant="tonal" density="compact" class="mt-4">
                        <div class="d-flex align-center flex-wrap ga-2">
                            <span>{{ t('MailingRecipients.suggestHits', { n: suggestResult.total }) }}</span>
                            <span v-if="suggestNew.length !== suggestResult.results.length" class="text-medium-emphasis">
                                {{ t('MailingRecipients.suggestAlreadyIn', { n: suggestResult.results.length - suggestNew.length }) }}
                            </span>
                            <span v-if="suggestNew.length > capacity" class="text-medium-emphasis">
                                {{ t('MailingRecipients.suggestCapped', { n: capacity }) }}
                            </span>
                        </div>
                        <div v-if="suggestNew.length" class="text-caption mt-1 text-medium-emphasis">
                            {{ suggestNew.slice(0, 8).map(r => r.name).join(', ') }}<span v-if="suggestNew.length > 8"> …</span>
                        </div>
                    </v-alert>
                </v-card-text>
                <v-divider />
                <v-card-actions class="px-4 py-3">
                    <v-btn variant="text" :loading="suggestLoading" prepend-icon="mdi-magnify" @click="runSuggest">{{ t('MailingRecipients.suggestRun') }}</v-btn>
                    <v-spacer />
                    <v-btn variant="text" @click="suggestDialog = false">{{ t('MailingRecipients.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" prepend-icon="mdi-account-multiple-plus-outline" :disabled="!suggestNew.length" @click="applySuggest">
                        {{ t('MailingRecipients.suggestApply', { n: Math.min(suggestNew.length, capacity) }) }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Als Verteiler speichern -->
        <v-dialog v-model="saveListDialog" max-width="460">
            <v-card>
                <v-card-title class="text-subtitle-1">{{ t('MailingRecipients.saveListTitle') }}</v-card-title>
                <v-card-text>
                    <v-radio-group v-if="currentList" v-model="saveListMode" density="compact" hide-details class="mb-3">
                        <v-radio value="update" :label="t('MailingRecipients.saveListUpdate', { name: currentList.name })" />
                        <v-radio value="new" :label="t('MailingRecipients.saveListNew')" />
                    </v-radio-group>
                    <v-text-field v-if="saveListMode === 'new' || !currentList" v-model="saveListName" :label="t('MailingRecipients.listName')" variant="outlined" density="compact" hide-details autofocus class="mb-3" @keyup.enter="saveList" />
                    <v-text-field v-if="saveListMode === 'new' || !currentList" v-model="saveListDescription" :label="t('MailingRecipients.listDescription')" variant="outlined" density="compact" hide-details />
                    <div class="text-caption text-medium-emphasis mt-3">{{ t('MailingRecipients.saveListHint', { n: recipients.length }) }}</div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="saveListDialog = false">{{ t('MailingRecipients.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="saveListLoading" :disabled="saveListMode === 'new' && !saveListName.trim()" @click="saveList">{{ t('MailingRecipients.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </section-card>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import SectionCard from '@/core/components/layout/section-card.vue'
import ChannelIcons from './channel-icons.vue'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'
import { CHANNEL_META, planAll, recipientKey } from '../composables/mailingPlan.js'

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    showStatus: { type: Boolean, default: false },
    settings: { type: Object, default: () => ({ channels: [], whatsapp_template_id: null }) },
    config: { type: Object, default: () => ({}) },
    lists: { type: Array, default: () => [] },
    businesses: { type: Array, default: () => [] },
    max: { type: Number, default: 300 },
    /** Verteiler, aus dem die Liste stammt (für „Verteiler aktualisieren“) */
    listId: { type: [Number, String], default: null },
})
const emit = defineEmits(['update:modelValue', 'lists-changed', 'list-loaded'])

const { t, locale } = useI18n()
const router = useRouter()

const recipients = computed(() => props.modelValue.map(r => ({ ...r, key: recipientKey(r) })))
const capacity = computed(() => Math.max(0, props.max - recipients.value.length))
const plan = computed(() => planAll(recipients.value, props.settings, props.config))
function planFor(r) { return plan.value.byKey.get(recipientKey(r)) || { channel: null } }
const currentList = computed(() => props.lists.find(l => Number(l.id) === Number(props.listId)) || null)

const headers = computed(() => [
    { title: t('MailingRecipients.name'), key: 'name', sortable: true },
    { title: t('MailingRecipients.reachable'), key: 'channels', sortable: false, width: '150px' },
    { title: props.showStatus ? t('MailingRecipients.statusCol') : t('MailingRecipients.planned'), key: 'plan', sortable: false, width: '260px' },
    { title: '', key: 'actions', sortable: false, align: 'end', width: '48px' },
])

// ── Filter ──
const filter = ref('all')
const channelFilters = computed(() => ['whatsapp', 'whatsapp_template', 'telegram', 'email', 'email_extra', 'letter', 'letter_extra', 'none'].map(v => ({
    value: v, icon: CHANNEL_META[v].icon, color: CHANNEL_META[v].color,
    count: v === 'none' ? plan.value.none + plan.value.duplicates : plan.value.counts[v],
})).filter(f => f.count > 0 || f.value === 'none'))
const statusFilters = computed(() => ['pending', 'sent', 'failed', 'skipped'].map(v => ({
    value: v, ...statusMeta(v), count: recipients.value.filter(r => (r.status || 'pending') === v).length,
})).filter(f => f.count > 0))
const filteredRecipients = computed(() => {
    if (filter.value === 'all') return recipients.value
    if (props.showStatus) return recipients.value.filter(r => (r.status || 'pending') === filter.value)
    return recipients.value.filter(r => {
        const p = planFor(r)
        if (filter.value === 'none') return !p.channel || p.duplicate
        if (filter.value === 'email_extra') return !!p.email
        if (filter.value === 'letter_extra') return !!p.letter
        return p.channel === filter.value && !p.duplicate
    })
})
watch(() => props.showStatus, () => { filter.value = 'all' })

function statusMeta(s) {
    return {
        pending: { icon: 'mdi-clock-outline', color: 'grey' },
        sent:    { icon: 'mdi-check-circle-outline', color: 'success' },
        failed:  { icon: 'mdi-alert-circle-outline', color: 'error' },
        skipped: { icon: 'mdi-debug-step-over', color: 'warning' },
    }[s] || { icon: 'mdi-help-circle-outline', color: 'grey' }
}
function errorText(e) {
    const known = ['no_channel', 'duplicate_target', 'not_found']
    return known.includes(e) ? t('MailingRecipients.errors.' + e) : e
}
function formatWhen(v) {
    if (!v) return ''
    return new Date(v).toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// ── Liste ändern ──
function setRecipients(list) {
    emit('update:modelValue', list.map(({ key, ...r }) => r))
}
function addMany(items) {
    const have = new Set(recipients.value.map(r => r.key))
    const fresh = items.filter(r => !have.has(recipientKey(r)))
    const room = capacity.value
    const take = fresh.slice(0, room)
    if (take.length) setRecipients([...recipients.value, ...take])
    if (fresh.length > room) toast.warning(t('MailingRecipients.maxReached', { max: props.max }))
    return take.length
}
function remove(item) {
    setRecipients(recipients.value.filter(r => r.key !== item.key))
}
async function removeAll() {
    const res = await alerts.warning(t('MailingRecipients.removeAllConfirm', { n: recipients.value.length }), '', t('MailingRecipients.removeAll'), t('MailingRecipients.cancel'))
    if (res.isConfirmed) setRecipients([])
}
function removeWithoutChannel() {
    setRecipients(recipients.value.filter(r => { const p = planFor(r); return p.channel && !p.duplicate }))
}
function openCv(item) {
    router.push({ name: item.cv_src === 'V' ? 'change-vendor' : 'change-customer', params: { id: item.cv_id } })
}

// ── Suche ──
const searchSrc = ref('')
const searchText = ref('')
const searchSelected = ref(null)
const SEARCH_LIMIT = 8   // mehr Treffer zeigt die Liste nicht, Suche verfeinern statt scrollen
const searchItems = ref([])
const searchLoading = ref(false)
let searchTimer = null
watch(searchText, (s) => {
    clearTimeout(searchTimer)
    if (!s || s.length < 2) { searchItems.value = []; return }
    searchTimer = setTimeout(() => runSearch(s), 300)
})
watch(searchSrc, () => { if (searchText.value?.length >= 2) runSearch(searchText.value) })
async function runSearch(s) {
    searchLoading.value = true
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'searchMailingRecipients', search: s, src: searchSrc.value })
        if (!data.success) throw new Error(data.text)
        const have = new Set(recipients.value.map(r => r.key))
        searchItems.value = (data.payload.results || []).filter(r => !have.has(recipientKey(r))).slice(0, SEARCH_LIMIT)
    } catch (e) {
        toast.error(e.message)
    } finally {
        searchLoading.value = false
    }
}
function onSearchPicked(item) {
    if (!item) return
    addMany([item])
    searchSelected.value = null
    searchItems.value = []
    // Vuetify setzt den Suchtext nach der Auswahl noch einmal auf den Titel – erst danach leeren
    nextTick(() => { searchText.value = '' })
}

// ── Vorschlag ──
const suggestDialog = ref(false)
const suggestLoading = ref(false)
const suggestResult = ref(null)
const suggest = ref({ src: 'C', business_id: 0, months: 0, city: '', channel: 'any' })
const businessItems = computed(() => [{ id: 0, description: t('MailingRecipients.anyBusiness') }, ...props.businesses])
const monthItems = computed(() => [0, 3, 6, 12, 24, 36].map(m => ({ value: m, title: m ? t('MailingRecipients.monthsOption', { n: m }) : t('MailingRecipients.monthsAny') })))
const channelItems = computed(() => ['any', 'telegram', 'whatsapp_window', 'whatsapp', 'email', 'none'].map(v => ({ value: v, title: t('MailingRecipients.suggestChannels.' + v) })))
const suggestNew = computed(() => {
    if (!suggestResult.value) return []
    const have = new Set(recipients.value.map(r => r.key))
    return suggestResult.value.results.filter(r => !have.has(recipientKey(r)))
})
function openSuggest() { suggestResult.value = null; suggestDialog.value = true }
async function runSuggest() {
    suggestLoading.value = true
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'suggestMailingRecipients', ...suggest.value, limit: props.max })
        if (!data.success) throw new Error(data.text)
        suggestResult.value = data.payload
    } catch (e) {
        toast.error(e.message)
    } finally {
        suggestLoading.value = false
    }
}
function applySuggest() {
    const n = addMany(suggestNew.value)
    suggestDialog.value = false
    if (n) toast.success(t('MailingRecipients.added', { n }))
}

// ── Verteiler ──
async function loadList(id) {
    if (!id) return
    try {
        const { data } = await axios.post('/api/mailings/', { action: 'getMailingList', id })
        if (!data.success) throw new Error(data.text)
        const n = addMany(data.payload.members || [])
        emit('list-loaded', id)
        toast.success(t('MailingRecipients.added', { n }))
    } catch (e) {
        toast.error(e.message)
    }
}
const saveListDialog = ref(false)
const saveListLoading = ref(false)
const saveListMode = ref('new')
const saveListName = ref('')
const saveListDescription = ref('')
function openSaveList() {
    saveListMode.value = currentList.value ? 'update' : 'new'
    saveListName.value = ''
    saveListDescription.value = ''
    saveListDialog.value = true
}
async function saveList() {
    saveListLoading.value = true
    try {
        const update = saveListMode.value === 'update' && currentList.value
        const { data } = await axios.post('/api/mailings/', {
            action: 'saveMailingList',
            id: update ? currentList.value.id : 0,
            name: update ? currentList.value.name : saveListName.value.trim(),
            description: update ? (currentList.value.description || '') : saveListDescription.value.trim(),
            members: recipients.value.map(r => ({ cv_src: r.cv_src, cv_id: r.cv_id })),
        })
        if (!data.success) throw new Error(data.text)
        saveListDialog.value = false
        emit('lists-changed', data.payload.id)
        toast.success(t('MailingRecipients.listSaved'))
    } catch (e) {
        toast.error(e.message)
    } finally {
        saveListLoading.value = false
    }
}
</script>

<style scoped>
.recipients__toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
    padding: var(--oserp-gap) var(--oserp-gap-lg);
    border-bottom: 1px solid var(--oserp-border-soft);
}
.recipients__search {
    flex: 1 1 280px;
    min-width: 240px;
}
.recipients__lists {
    flex: 0 1 240px;
    min-width: 200px;
}
.recipients__filters {
    padding: var(--oserp-gap-xs) var(--oserp-gap-lg);
    border-bottom: 1px solid var(--oserp-border-soft);
}
.recipients__error {
    max-width: 240px;
}
.recipients__table :deep(tbody tr) {
    cursor: default;
}
.recipients__hit {
    min-height: 36px;
}
.recipients__hit :deep(.v-list-item__prepend) {
    margin-inline-end: var(--oserp-space-2, 8px);
}
</style>
