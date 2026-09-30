<!-- src/features/accounting/views/accounting.dunning.vue -->
<!--
    Mahnwesen.

    Drei Reiter, eine Frage je Reiter: Wen mahne ich jetzt (Mahnlauf), was
    habe ich gemahnt (Verlauf), wie wird gemahnt (Mahnstufen). Der Vorschlag
    kommt fertig gebündelt aus der Datenbank — je Kunde und Stufe ein Brief,
    alles Mahnreife vorausgewählt, der Versandweg vorbelegt (E-Mail, wenn die
    Stufe es erlaubt und eine Adresse da ist, sonst Druck). Ein Klick startet
    den Lauf; die gedruckten Briefe kommen als ein PDF zurück.
-->
<template>
    <NavbarView />

    <v-container fluid class="dunning">
        <AccountingPageHeader :title="t('AccountingView.dunning.title')">
            <template #actions>
                <v-btn icon variant="text" size="small" :loading="loading" :aria-label="t('AccountingView.cockpit.reload')" @click="reload">
                    <v-icon>mdi-refresh</v-icon>
                </v-btn>
            </template>
        </AccountingPageHeader>

        <!-- Kennzahlen -->
        <v-card variant="outlined" class="mb-4 pulse">
            <div class="pulse__grid">
                <button class="pulse__cell" @click="tab = 'run'">
                    <div class="pulse__label">{{ t('AccountingView.dunning.stats.ready') }}</div>
                    <div class="pulse__value" :class="{ 'text-error': summary.ready_invoices > 0 }">{{ money(summary.ready_sum) }}</div>
                    <div class="pulse__sub">{{ t('AccountingView.dunning.stats.readySub', { count: summary.ready_invoices || 0, customers: summary.ready_customers || 0 }) }}</div>
                </button>
                <button class="pulse__cell" @click="tab = 'history'">
                    <div class="pulse__label">{{ t('AccountingView.dunning.stats.inDunning') }}</div>
                    <div class="pulse__value">{{ money(summary.in_dunning_sum) }}</div>
                    <div class="pulse__sub">{{ t('AccountingView.dunning.stats.inDunningSub', { count: summary.in_dunning || 0 }) }}</div>
                </button>
                <button class="pulse__cell" @click="onlyReady = false; tab = 'run'">
                    <div class="pulse__label">{{ t('AccountingView.dunning.stats.waiting') }}</div>
                    <div class="pulse__value">{{ summary.waiting_invoices || 0 }}</div>
                    <div class="pulse__sub">{{ t('AccountingView.dunning.stats.waitingSub') }}</div>
                </button>
                <button v-if="(summary.max_level_invoices || 0) > 0" class="pulse__cell" @click="onlyReady = false; tab = 'run'">
                    <div class="pulse__label">{{ t('AccountingView.dunning.stats.maxLevel') }}</div>
                    <div class="pulse__value text-error">{{ money(summary.max_level_sum) }}</div>
                    <div class="pulse__sub">{{ t('AccountingView.dunning.stats.maxLevelSub', { count: summary.max_level_invoices }) }}</div>
                </button>
                <button class="pulse__cell" @click="tab = 'history'">
                    <div class="pulse__label">{{ t('AccountingView.dunning.stats.letters', { year }) }}</div>
                    <div class="pulse__value">{{ historyStats.letters_year || 0 }}</div>
                    <div class="pulse__sub">
                        {{ historyStats.last_run ? t('AccountingView.dunning.stats.lettersSub', { date: historyStats.last_run }) : t('AccountingView.dunning.stats.lettersNone') }}
                    </div>
                </button>
                <button class="pulse__cell" @click="tab = 'history'">
                    <div class="pulse__label">{{ t('AccountingView.dunning.stats.fees') }}</div>
                    <div class="pulse__value">{{ money(historyStats.fees_total) }}</div>
                    <div class="pulse__sub">{{ t('AccountingView.dunning.stats.feesSub', { count: historyStats.settled_after_dunning || 0 }) }}</div>
                </button>
            </div>
        </v-card>

        <v-tabs v-model="tab" color="primary" class="mb-3">
            <v-tab value="run" prepend-icon="mdi-email-alert-outline">
                {{ t('AccountingView.dunning.tabs.run') }}
                <v-badge v-if="summary.ready_invoices > 0" :content="summary.ready_invoices" color="error" inline class="ml-2" />
            </v-tab>
            <v-tab value="history" prepend-icon="mdi-history">{{ t('AccountingView.dunning.tabs.history') }}</v-tab>
            <v-tab value="config" prepend-icon="mdi-tune-variant">{{ t('AccountingView.dunning.tabs.config') }}</v-tab>
        </v-tabs>

        <v-window v-model="tab">
            <!-- ── Mahnlauf ─────────────────────────────────────────────── -->
            <v-window-item value="run">
                <v-alert v-if="proposal && !proposal.configured" type="info" variant="tonal" icon="mdi-tune-variant" class="mb-4"
                         :title="t('AccountingView.dunning.run.notConfigured')" :text="t('AccountingView.dunning.run.notConfiguredText')">
                    <template #append>
                        <v-btn color="primary" variant="flat" class="text-none" @click="tab = 'config'">
                            {{ t('AccountingView.dunning.run.configure') }}
                        </v-btn>
                    </template>
                </v-alert>

                <template v-else>
                    <div class="d-flex align-center flex-wrap ga-3 mb-3">
                        <v-text-field v-model="search" density="compact" variant="outlined" hide-details clearable
                                      prepend-inner-icon="mdi-magnify" :label="t('AccountingView.dunning.run.search')"
                                      style="max-width: 360px" @update:model-value="onSearch" />
                        <v-switch v-model="onlyReady" color="primary" density="compact" hide-details inset
                                  :label="t('AccountingView.dunning.run.onlyReady')" />
                        <v-spacer />
                        <v-btn size="small" variant="text" class="text-none" @click="selectAll(true)">
                            <v-icon start size="small">mdi-checkbox-multiple-marked-outline</v-icon>
                            {{ t('AccountingView.dunning.run.selectAll') }}
                        </v-btn>
                        <v-btn size="small" variant="text" class="text-none" @click="selectAll(false)">
                            <v-icon start size="small">mdi-checkbox-multiple-blank-outline</v-icon>
                            {{ t('AccountingView.dunning.run.deselectAll') }}
                        </v-btn>
                    </div>

                    <div v-if="loading && !proposal" class="d-flex flex-column ga-3">
                        <v-skeleton-loader v-for="n in 3" :key="n" type="article" />
                    </div>

                    <v-alert v-else-if="!visibleGroups.length" type="success" variant="tonal" density="comfortable"
                             icon="mdi-check-circle-outline"
                             :text="proposal?.groups?.length ? t('AccountingView.dunning.run.emptyFiltered') : t('AccountingView.dunning.run.empty')" />

                    <div v-else class="d-flex flex-column ga-3 mb-16">
                        <DunningGroupCard v-for="g in visibleGroups" :key="g.key" :group="g" :draft="drafts[g.key]"
                                          :levels="proposal.levels" :email-configured="emailConfigured"
                                          @preview="preview" @toggle-lock="toggleLock"
                                          @open-customer="openCustomer" @open-invoice="openInvoice" />
                    </div>
                </template>
            </v-window-item>

            <!-- ── Verlauf ──────────────────────────────────────────────── -->
            <v-window-item value="history">
                <div class="d-flex align-center flex-wrap ga-3 mb-3">
                    <v-text-field v-model="historyFilter.search" density="compact" variant="outlined" hide-details clearable
                                  prepend-inner-icon="mdi-magnify" :label="t('AccountingView.dunning.history.search')"
                                  style="max-width: 360px" @update:model-value="onHistorySearch" />
                    <v-select v-model="historyFilter.config_id" :items="levelFilterItems" density="compact" variant="outlined"
                              hide-details style="max-width: 220px" :label="t('AccountingView.dunning.history.level')"
                              @update:model-value="loadHistory" />
                    <v-switch v-model="historyFilter.show_settled" color="primary" density="compact" hide-details inset
                              :label="t('AccountingView.dunning.history.showSettled')" @update:model-value="loadHistory" />
                </div>

                <v-data-table :headers="historyHeaders" :items="history" :loading="loading" density="compact"
                              :items-per-page="25" hover :no-data-text="t('AccountingView.dunning.history.empty')"
                              @click:row="(_, { item }) => openLetterPdf(item)">
                    <template #item.level="{ item }">
                        <v-chip size="x-small" variant="tonal" :color="item.settled ? 'success' : 'error'">
                            {{ item.level }} · {{ item.description }}
                        </v-chip>
                    </template>
                    <template #item.customer="{ item }">
                        <a class="text-primary text-decoration-none font-weight-medium" @click.stop="openCustomer(item.customer_id)">
                            {{ item.customer }}
                        </a>
                        <div class="text-caption text-medium-emphasis">{{ item.invnumbers }}</div>
                    </template>
                    <template #item.open_then="{ item }">{{ money(item.open_then) }}</template>
                    <template #item.open_now="{ item }">
                        <v-chip v-if="item.settled" size="x-small" color="success" variant="flat">
                            {{ t('AccountingView.dunning.history.settled') }}
                        </v-chip>
                        <span v-else class="font-weight-medium text-error">{{ money(item.open_now) }}</span>
                    </template>
                    <template #item.fees="{ item }">
                        <span v-if="Number(item.fee) + Number(item.interest) > 0">{{ money(Number(item.fee) + Number(item.interest)) }}</span>
                        <div v-if="item.fee_invoice" class="text-caption text-medium-emphasis">
                            <a class="text-primary" @click.stop="openFeeInvoice(item.fee_invoice)">
                                {{ t('AccountingView.dunning.history.feeInvoice', { invnumber: item.fee_invoice.invnumber }) }}
                            </a>
                        </div>
                    </template>
                    <template #item.duedate="{ item }">
                        {{ item.duedate }}
                        <div v-if="!item.settled" class="text-caption" :class="Number(item.days_open) > 0 ? 'text-error' : 'text-medium-emphasis'">
                            {{ Number(item.days_open) > 0
                                ? t('AccountingView.dunning.history.overdueAgain', { days: item.days_open })
                                : t('AccountingView.dunning.history.inTime') }}
                        </div>
                    </template>
                    <template #item.channel="{ item }">
                        <v-tooltip location="top" :text="channelText(item)">
                            <template #activator="{ props: tip }">
                                <v-icon v-bind="tip" size="small" :color="item.error ? 'error' : undefined">{{ channelIcon(item) }}</v-icon>
                            </template>
                        </v-tooltip>
                    </template>
                    <template #item.actions="{ item }">
                        <div class="d-flex ga-1 justify-end">
                            <v-tooltip location="top" :text="t('AccountingView.dunning.history.pdf')">
                                <template #activator="{ props: tip }">
                                    <v-btn v-bind="tip" icon="mdi-file-pdf-box" size="small" variant="text" @click.stop="openLetterPdf(item)" />
                                </template>
                            </v-tooltip>
                            <v-tooltip location="top" :text="t('AccountingView.dunning.history.email')">
                                <template #activator="{ props: tip }">
                                    <v-btn v-bind="tip" icon="mdi-email-send-outline" size="small" variant="text"
                                           :disabled="!emailConfigured" @click.stop="askResend(item)" />
                                </template>
                            </v-tooltip>
                            <v-tooltip location="top" :text="item.is_current ? t('AccountingView.dunning.history.delete') : t('AccountingView.dunning.history.notCurrent')">
                                <template #activator="{ props: tip }">
                                    <span v-bind="tip">
                                        <v-btn icon="mdi-undo-variant" size="small" variant="text" color="error"
                                               :disabled="!item.is_current" @click.stop="askDelete(item)" />
                                    </span>
                                </template>
                            </v-tooltip>
                        </div>
                    </template>
                </v-data-table>
            </v-window-item>

            <!-- ── Mahnstufen ───────────────────────────────────────────── -->
            <v-window-item value="config">
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mb-4"
                         :text="t('AccountingView.dunning.config.intro')" />
                <v-skeleton-loader v-if="!config" type="article" />
                <DunningLevelsEditor v-else :config="config" :saving="saving" @save="saveConfiguration" />
            </v-window-item>
        </v-window>
    </v-container>

    <!-- Leiste am unteren Rand: Summe der Auswahl und der Startknopf -->
    <v-slide-y-reverse-transition>
        <div v-if="tab === 'run' && selection.letters > 0" class="runbar">
            <div class="runbar__inner">
                <div>
                    <div class="font-weight-medium">
                        {{ t('AccountingView.dunning.run.barSummary', { letters: selection.letters, invoices: selection.invoices, amount: money(selection.total) }) }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                        {{ t('AccountingView.dunning.run.barChannels', { email: selection.email, print: selection.print, none: selection.none }) }}
                    </div>
                </div>
                <v-spacer />
                <v-btn color="error" size="large" class="text-none" :loading="running" :disabled="selection.missingEmail > 0" @click="startRun">
                    <v-icon start>mdi-email-fast-outline</v-icon>
                    {{ t('AccountingView.dunning.run.start') }}
                </v-btn>
            </div>
            <div v-if="selection.missingEmail > 0" class="runbar__warn">
                <v-icon size="small" color="warning">mdi-alert-outline</v-icon>
                {{ t('AccountingView.dunning.run.missingEmail', { count: selection.missingEmail }) }}
            </div>
        </div>
    </v-slide-y-reverse-transition>

    <!-- Ergebnis des Laufs -->
    <v-dialog v-model="resultDialog" max-width="720" scrollable>
        <v-card>
            <v-card-title class="d-flex align-center ga-2">
                <v-icon color="success">mdi-check-circle-outline</v-icon>
                {{ t('AccountingView.dunning.run.resultTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" size="small" @click="resultDialog = false" />
            </v-card-title>
            <v-card-subtitle class="pb-2">
                {{ t('AccountingView.dunning.run.resultCreated', { count: result?.created || 0 }) }}
                · {{ t('AccountingView.dunning.run.resultEmails', { count: result?.emails || 0 }) }}
                <span v-if="result?.errors" class="text-error"> · {{ t('AccountingView.dunning.run.resultErrors', { count: result.errors }) }}</span>
            </v-card-subtitle>
            <v-divider />
            <v-card-text>
                <v-list density="compact">
                    <v-list-item v-for="(l, i) in result?.letters || []" :key="i">
                        <template #prepend>
                            <v-icon :color="l.error ? 'error' : 'success'">{{ l.error ? 'mdi-alert-circle-outline' : 'mdi-check' }}</v-icon>
                        </template>
                        <v-list-item-title>
                            {{ customerName(l.customer_id) }}
                            <span v-if="l.dunning_id" class="text-medium-emphasis">· {{ t('AccountingView.dunning.run.resultLetter', { id: l.dunning_id }) }}</span>
                        </v-list-item-title>
                        <v-list-item-subtitle>
                            <span v-if="l.email_sent">{{ t('AccountingView.dunning.run.channelEmail') }}</span>
                            <span v-else-if="l.channel === 'print' && l.pdf">{{ t('AccountingView.dunning.run.channelPrint') }}</span>
                            <span v-else-if="l.pdf">{{ t('AccountingView.dunning.run.channelNone') }}</span>
                            <span v-if="l.fee_invoice"> · {{ t('AccountingView.dunning.run.resultFee', { invnumber: l.fee_invoice.invnumber, amount: money(l.fee_invoice.amount) }) }}</span>
                            <span v-if="l.error" class="text-error"> · {{ l.error }}</span>
                        </v-list-item-subtitle>
                    </v-list-item>
                </v-list>
            </v-card-text>
            <v-divider />
            <v-card-actions class="px-4">
                <v-spacer />
                <v-btn variant="text" class="text-none" @click="resultDialog = false">{{ t('AccountingView.dunning.run.close') }}</v-btn>
                <v-btn v-if="result?.pdf" color="primary" variant="flat" class="text-none" @click="openBase64Pdf(result.pdf, result.filename)">
                    <v-icon start>mdi-printer-outline</v-icon>
                    {{ t('AccountingView.dunning.run.openPdf') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Erneut per E-Mail -->
    <v-dialog v-model="resendDialog" max-width="480">
        <v-card>
            <v-card-title>{{ t('AccountingView.dunning.history.emailTitle') }}</v-card-title>
            <v-card-text>
                <v-text-field v-model="resendEmailTo" variant="outlined" density="compact" prepend-inner-icon="mdi-at"
                              :label="t('AccountingView.dunning.history.emailTo')" autofocus />
            </v-card-text>
            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn variant="text" class="text-none" @click="resendDialog = false">{{ t('AccountingView.dunning.run.cancel') }}</v-btn>
                <v-btn color="primary" variant="flat" class="text-none" :loading="loading" :disabled="!resendEmailTo" @click="doResend">
                    <v-icon start>mdi-email-send-outline</v-icon>
                    {{ t('AccountingView.dunning.history.send') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingPageHeader from '../components/accounting.page-header.vue'
import DunningGroupCard from '../components/dunning.group-card.vue'
import DunningLevelsEditor from '../components/dunning.levels-editor.vue'
import { useDunning } from '../composables/useDunning.js'
import { openBase64Pdf } from '@/core/utils/download.js'
import { entityRoute } from '@/core/constants/routes.js'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const dunning = useDunning()
const { loading } = dunning

const tab = ref(route.query.tab === 'history' || route.query.tab === 'config' ? route.query.tab : 'run')
const year = new Date().getFullYear()

// ── Vorschlag ──────────────────────────────────────────────────────────────
const proposal = ref(null)
const config = ref(null)
const search = ref('')
const onlyReady = ref(true)
const drafts = reactive({})
const running = ref(false)
const saving = ref(false)
const result = ref(null)
const resultDialog = ref(false)

const summary = computed(() => proposal.value?.summary || {})
const emailConfigured = computed(() => !!config.value?.email_configured)

const visibleGroups = computed(() => {
    const groups = proposal.value?.groups || []
    return onlyReady.value ? groups.filter(g => g.ready_count > 0 || drafts[g.key]?.selected) : groups
})

/**
 * Entwurf je Brief: was der Benutzer an der Karte verändert. Vorbelegt mit
 * dem, was die Datenbank vorschlägt — mahnreife Rechnungen ausgewählt, die
 * nächste Stufe, E-Mail wenn Stufe und Adresse es hergeben.
 */
function buildDrafts() {
    const seen = new Set()
    for (const g of proposal.value?.groups || []) {
        seen.add(g.key)
        const ready = g.invoices.filter(i => i.state === 'ready').map(i => i.id)
        const previous = drafts[g.key]
        drafts[g.key] = {
            selected:  previous ? previous.selected && ready.length > 0 : ready.length > 0 && !g.dunning_lock,
            ids:       previous ? previous.ids.filter(id => g.invoices.some(i => i.id === id)) : ready,
            // Ohne nächste Stufe (höchste erreicht) wird die höchste wiederholt —
            // wieder mit der Zahlungserinnerung anzufangen wäre das Gegenteil.
            config_id: previous?.config_id || g.next_config_id || proposal.value.levels.at(-1)?.id || null,
            channel:   previous?.channel || (g.next_email && g.email && emailConfigured.value ? 'email' : 'print'),
            email:     previous?.email ?? g.email
        }
    }
    for (const key of Object.keys(drafts)) if (!seen.has(key)) delete drafts[key]
}

const selection = computed(() => {
    const s = { letters: 0, invoices: 0, total: 0, email: 0, print: 0, none: 0, missingEmail: 0 }
    for (const g of proposal.value?.groups || []) {
        const d = drafts[g.key]
        if (!d?.selected || !d.ids.length) continue
        const level = proposal.value.levels.find(l => l.id === d.config_id)
        const chosen = g.invoices.filter(i => d.ids.includes(i.id))
        s.letters++
        s.invoices += chosen.length
        s.total += chosen.reduce((a, i) => a + Number(i.open_amount), 0) + Number(level?.fee || 0)
        s[d.channel]++
        if (d.channel === 'email' && !(d.email || '').trim()) s.missingEmail++
    }
    return s
})

function selectAll(on) {
    for (const g of visibleGroups.value) {
        const d = drafts[g.key]
        if (!d || g.dunning_lock) continue
        if (on) {
            if (!d.ids.length) d.ids = g.invoices.filter(i => i.state === 'ready').map(i => i.id)
            d.selected = d.ids.length > 0
        } else {
            d.selected = false
        }
    }
}

let searchTimer = null
function onSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(loadProposal, 300)
}

async function loadProposal() {
    const payload = await dunning.fetchProposal(search.value || '')
    if (payload) {
        proposal.value = payload.results
        buildDrafts()
    }
}

async function loadConfig() {
    const payload = await dunning.fetchConfig()
    if (payload) config.value = payload.results
}

async function preview(group) {
    const d = drafts[group.key]
    const pdf = await dunning.previewPdf(group.customer_id, d.config_id, d.ids)
    if (pdf) openBase64Pdf(pdf.pdf, pdf.filename)
    else alerts.error(dunning.error || '', t('AccountingView.dunning.run.previewFailed'))
}

async function toggleLock(group) {
    const payload = await dunning.setLock(group.customer_id, !group.dunning_lock)
    if (!payload) { alerts.error(dunning.error || ''); return }
    alerts.success(payload.results.locked ? t('AccountingView.dunning.run.lockSet') : t('AccountingView.dunning.run.lockRemoved'))
    await loadProposal()
}

async function startRun() {
    const letters = []
    for (const g of proposal.value?.groups || []) {
        const d = drafts[g.key]
        if (!d?.selected || !d.ids.length) continue
        letters.push({ customer_id: g.customer_id, config_id: d.config_id, ids: [...d.ids], channel: d.channel, email: d.email || '' })
    }
    if (!letters.length) { alerts.warning(t('AccountingView.dunning.run.noneSelected')); return }

    const answer = await alerts.question(
        t('AccountingView.dunning.run.confirmText', { letters: letters.length }),
        t('AccountingView.dunning.run.confirmTitle'),
        t('AccountingView.dunning.run.confirmOk'),
        t('AccountingView.dunning.run.cancel')
    )
    if (!answer.isConfirmed) return

    running.value = true
    const payload = await dunning.createRun(letters)
    running.value = false
    if (!payload) { alerts.error(dunning.error || ''); return }

    result.value = payload.results
    resultDialog.value = true
    await Promise.all([loadProposal(), loadHistory()])
}

function customerName(customerId) {
    return (proposal.value?.groups || []).find(g => g.customer_id === customerId)?.customer
        || history.value.find(h => h.customer_id === customerId)?.customer
        || '#' + customerId
}

// ── Verlauf ────────────────────────────────────────────────────────────────
const history = ref([])
const historyStats = ref({})
const historyFilter = reactive({ search: '', config_id: 0, show_settled: true })
const resendDialog = ref(false)
const resendEmailTo = ref('')
let resendItem = null

const levelFilterItems = computed(() => [
    { value: 0, title: t('AccountingView.dunning.history.allLevels') },
    ...(config.value?.levels || []).map(l => ({ value: l.id, title: l.dunning_level + ' · ' + l.dunning_description }))
])

const historyHeaders = computed(() => [
    { title: t('AccountingView.dunning.history.date'),     key: 'transdate', width: '100px', nowrap: true },
    { title: t('AccountingView.dunning.history.number'),   key: 'dunning_id', width: '80px' },
    { title: t('AccountingView.dunning.history.level'),    key: 'level', width: '170px' },
    { title: t('AccountingView.dunning.history.customer'), key: 'customer', minWidth: '220px' },
    { title: t('AccountingView.dunning.history.openThen'), key: 'open_then', align: 'end', width: '110px', nowrap: true },
    { title: t('AccountingView.dunning.history.openNow'),  key: 'open_now', align: 'end', width: '110px', nowrap: true },
    { title: t('AccountingView.dunning.history.fees'),     key: 'fees', align: 'end', width: '130px', nowrap: true },
    { title: t('AccountingView.dunning.history.dueDate'),  key: 'duedate', width: '150px', nowrap: true },
    { title: t('AccountingView.dunning.history.channel'),  key: 'channel', width: '56px', sortable: false, align: 'center' },
    { title: t('AccountingView.dunning.history.employee'), key: 'employee', width: '130px' },
    { title: '', key: 'actions', width: '120px', sortable: false, align: 'end' }
])

let historyTimer = null
function onHistorySearch() {
    clearTimeout(historyTimer)
    historyTimer = setTimeout(loadHistory, 300)
}

async function loadHistory() {
    const payload = await dunning.fetchHistory({
        search: historyFilter.search || '',
        config_id: historyFilter.config_id || 0,
        show_settled: historyFilter.show_settled
    })
    if (payload) {
        history.value = payload.results.items || []
        historyStats.value = payload.results.stats || {}
    }
}

function channelIcon(item) {
    if (item.error) return 'mdi-alert-circle-outline'
    return { email: 'mdi-email-check-outline', print: 'mdi-printer-outline', none: 'mdi-archive-outline' }[item.channel] || 'mdi-file-outline'
}

function channelText(item) {
    if (item.error) return t('AccountingView.dunning.history.error', { text: item.error })
    if (item.channel === 'email') return t('AccountingView.dunning.history.channelEmail', { email: item.email_to || '' })
    if (item.channel === 'print') return t('AccountingView.dunning.history.channelPrint')
    return t('AccountingView.dunning.history.channelNone')
}

async function openLetterPdf(item) {
    const pdf = await dunning.fetchPdf(item.dunning_id)
    if (pdf) openBase64Pdf(pdf.pdf, pdf.filename)
    else alerts.error(dunning.error || '')
}

function askResend(item) {
    resendItem = item
    resendEmailTo.value = item.email_to || ''
    resendDialog.value = true
}

async function doResend() {
    const payload = await dunning.resendEmail(resendItem.dunning_id, resendEmailTo.value)
    if (!payload) { alerts.error(dunning.error || ''); return }
    resendDialog.value = false
    alerts.success(t('AccountingView.dunning.history.sent'))
    loadHistory()
}

async function askDelete(item) {
    const answer = await alerts.warning(
        t('AccountingView.dunning.history.deleteText'),
        t('AccountingView.dunning.history.deleteTitle'),
        t('AccountingView.dunning.history.deleteOk'),
        t('AccountingView.dunning.run.cancel')
    )
    if (!answer.isConfirmed) return
    const payload = await dunning.deleteLetter(item.dunning_id)
    if (!payload) { alerts.error(dunning.error || ''); return }
    alerts.success(t('AccountingView.dunning.history.deleted'))
    await Promise.all([loadHistory(), loadProposal()])
}

// ── Konfiguration ──────────────────────────────────────────────────────────
async function saveConfiguration(form) {
    saving.value = true
    const payload = await dunning.saveConfig(form)
    saving.value = false
    if (!payload) { alerts.error(dunning.error || ''); return }
    alerts.success(t('AccountingView.dunning.config.saved'))
    await Promise.all([loadConfig(), loadProposal()])
    tab.value = 'run'
}

// ── Navigation ─────────────────────────────────────────────────────────────
function openCustomer(id) {
    const target = entityRoute('customer', id)
    if (target) router.push(target)
}

function openInvoice(id) {
    const target = entityRoute('invoice', id)
    if (target) router.push(target)
}

function openFeeInvoice(feeInvoice) {
    router.push({ name: 'accounting-bookings', query: { src: 'ar', id: feeInvoice.id } })
}

function money(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value) || 0)
}

async function reload() {
    await Promise.all([loadConfig(), loadProposal(), loadHistory()])
}

watch(tab, value => {
    router.replace({ query: { ...route.query, tab: value === 'run' ? undefined : value } })
})

onMounted(reload)
</script>

<style scoped>
.dunning { max-width: 1400px; }

.pulse__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1px;
    background: rgba(var(--v-border-color), var(--v-border-opacity));
}
.pulse__cell { background: rgb(var(--v-theme-surface)); padding: .7rem 1rem; text-align: left; cursor: pointer; transition: background .15s; }
.pulse__cell:hover { background: rgba(var(--v-theme-on-surface), .04); }
.pulse__label { font-size: .68rem; letter-spacing: .06em; text-transform: uppercase; opacity: .65; }
.pulse__value { font-size: 1.35rem; font-weight: 600; font-variant-numeric: tabular-nums; line-height: 1.25; }
.pulse__sub   { font-size: .72rem; opacity: .6; }

.runbar {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 1005;
    background: rgb(var(--v-theme-surface));
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    box-shadow: 0 -6px 18px rgba(0, 0, 0, .12);
}
.runbar__inner { max-width: 1400px; margin: 0 auto; display: flex; align-items: center; gap: 1rem; padding: .7rem 1.25rem; }
.runbar__warn { max-width: 1400px; margin: 0 auto; padding: 0 1.25rem .6rem; font-size: .78rem; display: flex; align-items: center; gap: .4rem; }
</style>
