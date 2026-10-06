<!-- src/core/views/recurring-invoices/recurring.invoices.view.vue -->
<!--
    Wiederkehrende Rechnungen — Abos, Mieten, Wartungsverträge.

    Drei Fragen, drei Reiter: Was ist jetzt zu berechnen (Fällig), was läuft
    (Abrechnungen), was kommt (Vorschau). Die Kennzahlen oben beantworten die
    Frage nach dem wiederkehrenden Umsatz auf einen Blick. Fällige Rechnungen
    sind vorausgewählt; ein Klick erzeugt, bucht und verschickt sie. Was die
    Mahnsperre anhält, bleibt sichtbar und wird bewusst freigegeben.
-->
<template>
    <NavbarView />

    <v-container fluid class="recurring pa-2 pa-md-4">
        <div class="d-flex align-center flex-wrap ga-3 mb-3">
            <h1 class="text-h5 d-flex align-center mb-0">
                <v-icon class="me-2" color="primary">mdi-autorenew</v-icon>
                {{ t('RecurringInvoices.title') }}
            </h1>
            <v-spacer />
            <v-btn icon variant="text" size="small" :loading="loading" :aria-label="t('RecurringInvoices.reload')" @click="reload">
                <v-icon>mdi-refresh</v-icon>
            </v-btn>
            <v-btn v-if="dueRows.length" color="primary" variant="flat" class="text-none" :loading="creating" @click="createSelected">
                <v-icon start>mdi-file-document-multiple-outline</v-icon>
                {{ t('RecurringInvoices.createSelected', { count: selectedDue.length }) }}
            </v-btn>
        </div>

        <!-- Kennzahlen -->
        <v-card variant="outlined" class="mb-4 pulse">
            <div class="pulse__grid">
                <button class="pulse__cell" @click="tab = 'due'">
                    <div class="pulse__label">{{ t('RecurringInvoices.kpi.due') }}</div>
                    <div class="pulse__value" :class="{ 'text-error': dueSummary.count > 0 }">{{ money(dueSummary.amount, locale) }}</div>
                    <div class="pulse__sub">{{ t('RecurringInvoices.kpi.dueSub', { count: dueSummary.count || 0, customers: dueSummary.customers || 0 }) }}</div>
                </button>
                <button class="pulse__cell" @click="tab = 'configs'; statusFilter = 'active'">
                    <div class="pulse__label">{{ t('RecurringInvoices.kpi.active') }}</div>
                    <div class="pulse__value">{{ kpis.active_count || 0 }}</div>
                    <div class="pulse__sub">{{ t('RecurringInvoices.kpi.activeSub', { customers: kpis.customers || 0 }) }}</div>
                </button>
                <button class="pulse__cell" @click="tab = 'upcoming'">
                    <div class="pulse__label">{{ t('RecurringInvoices.kpi.monthly') }}</div>
                    <div class="pulse__value">{{ money(kpis.monthly_amount, locale) }}</div>
                    <div class="pulse__sub">{{ t('RecurringInvoices.kpi.monthlySub', { yearly: money(kpis.yearly_amount, locale) }) }}</div>
                </button>
                <button class="pulse__cell" @click="tab = 'configs'; statusFilter = 'expiring'">
                    <div class="pulse__label">{{ t('RecurringInvoices.kpi.expiring') }}</div>
                    <div class="pulse__value" :class="{ 'text-warning': expiring.count > 0 }">{{ expiring.count || 0 }}</div>
                    <div class="pulse__sub">{{ t('RecurringInvoices.kpi.expiringSub') }}</div>
                </button>
                <button v-if="(kpis.blocked_count || 0) > 0" class="pulse__cell" @click="tab = 'due'">
                    <div class="pulse__label">{{ t('RecurringInvoices.kpi.blocked') }}</div>
                    <div class="pulse__value text-error">{{ kpis.blocked_count }}</div>
                    <div class="pulse__sub">{{ t('RecurringInvoices.kpi.blockedSub') }}</div>
                </button>
                <button v-if="(kpis.paused_count || 0) > 0" class="pulse__cell" @click="tab = 'configs'; statusFilter = 'paused'">
                    <div class="pulse__label">{{ t('RecurringInvoices.kpi.paused') }}</div>
                    <div class="pulse__value">{{ kpis.paused_count }}</div>
                    <div class="pulse__sub">{{ t('RecurringInvoices.kpi.pausedSub') }}</div>
                </button>
            </div>
        </v-card>

        <v-tabs v-model="tab" color="primary" class="mb-3">
            <v-tab value="due" prepend-icon="mdi-calendar-alert">
                {{ t('RecurringInvoices.tabs.due') }}
                <v-badge v-if="dueRows.length" :content="dueRows.length" color="error" inline class="ml-2" />
            </v-tab>
            <v-tab value="configs" prepend-icon="mdi-format-list-bulleted">{{ t('RecurringInvoices.tabs.configs') }}</v-tab>
            <v-tab value="upcoming" prepend-icon="mdi-chart-timeline-variant">{{ t('RecurringInvoices.tabs.upcoming') }}</v-tab>
        </v-tabs>

        <v-window v-model="tab">
            <!-- ── Fällig ─────────────────────────────────────────────────── -->
            <v-window-item value="due">
                <div v-if="loading && !overview" class="d-flex flex-column ga-3">
                    <v-skeleton-loader v-for="n in 3" :key="n" type="list-item-two-line" />
                </div>

                <v-alert v-else-if="!dueRows.length" type="success" variant="tonal" density="comfortable" icon="mdi-check-circle-outline"
                         :text="configs.length ? t('RecurringInvoices.due.empty') : t('RecurringInvoices.due.noConfigs')">
                    <template v-if="!configs.length" #append>
                        <v-btn color="primary" variant="flat" class="text-none" :to="{ name: 'order-list' }">{{ t('RecurringInvoices.due.openOrders') }}</v-btn>
                    </template>
                </v-alert>

                <template v-else>
                    <v-alert v-if="dueRows.some(d => d.blocked)" type="warning" variant="tonal" density="compact" class="mb-3" icon="mdi-hand-back-left-outline"
                             :text="t('RecurringInvoices.due.blockedInfo')" />

                    <v-data-table v-model="selectedDue" :items="dueRows" :headers="dueHeaders" item-value="key" show-select density="comfortable"
                                  :items-per-page="50" :sort-by="[{ key: 'billing_date', order: 'asc' }]" class="due-table"
                                  :no-data-text="t('RecurringInvoices.due.empty')">
                        <template #item.customer_name="{ item }">
                            <a href="#" class="link" @click.prevent="openCustomer(item.customer_id)">{{ item.customer_name }}</a>
                            <div class="text-caption text-medium-emphasis">{{ item.customernumber }}</div>
                        </template>
                        <template #item.ordnumber="{ item }">
                            <a href="#" class="link" @click.prevent="openOrder(item.oe_id)">{{ item.ordnumber }}</a>
                        </template>
                        <template #item.period="{ item }">
                            {{ formatDate(item.period_start, locale) }} – {{ formatDate(item.period_end, locale) }}
                            <v-chip v-if="item.is_partial" size="x-small" variant="tonal" color="info" class="ml-1">
                                {{ t('RecurringInvoices.dialog.partial', { percent: Math.round(item.factor * 100) }) }}
                            </v-chip>
                        </template>
                        <template #item.billing_date="{ item }">
                            <span :class="{ 'text-error font-weight-medium': daysLate(item.billing_date) > 7 }">{{ formatDate(item.billing_date, locale) }}</span>
                            <div v-if="daysLate(item.billing_date) > 0" class="text-caption text-medium-emphasis">
                                {{ t('RecurringInvoices.due.daysAgo', { days: daysLate(item.billing_date) }) }}
                            </div>
                        </template>
                        <template #item.amount="{ item }">
                            <span class="font-weight-medium">{{ money(item.amount, locale) }}</span>
                            <div class="text-caption text-medium-emphasis">{{ item.taxincluded ? t('RecurringInvoices.gross') : t('RecurringInvoices.net') }}</div>
                        </template>
                        <template #item.flags="{ item }">
                            <v-chip v-if="item.blocked" size="small" color="error" variant="tonal" prepend-icon="mdi-hand-back-left-outline">
                                {{ t('RecurringInvoices.due.blocked', { days: item.hold_on_overdue_days }) }}
                            </v-chip>
                            <v-icon v-if="item.send_email" size="small" class="ml-1" :title="t('RecurringInvoices.due.willEmail')">mdi-email-fast-outline</v-icon>
                        </template>
                        <template #item.actions="{ item }">
                            <v-btn size="small" variant="tonal" color="primary" class="text-none mr-1" :loading="creating" @click="createOne(item)">
                                {{ item.blocked ? t('RecurringInvoices.due.createAnyway') : t('RecurringInvoices.due.create') }}
                            </v-btn>
                            <v-btn size="small" variant="text" class="text-none" @click="skip(item)">{{ t('RecurringInvoices.due.skip') }}</v-btn>
                        </template>
                    </v-data-table>
                </template>

                <!-- Ergebnis des letzten Laufs -->
                <v-card v-if="lastRun" variant="outlined" class="mt-4">
                    <v-card-title class="text-subtitle-1 d-flex align-center">
                        <v-icon start color="success">mdi-check-decagram-outline</v-icon>
                        {{ t('RecurringInvoices.run.title', { count: lastRun.created.length }) }}
                        <v-spacer />
                        <v-btn icon variant="text" size="small" @click="lastRun = null"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text>
                        <div v-for="r in lastRun.created" :key="r.ar_id" class="d-flex align-center flex-wrap ga-2 py-1">
                            <a href="#" class="link font-weight-medium" @click.prevent="openInvoice(r.ar_id)">{{ r.invnumber }}</a>
                            <span>{{ r.customer_name }}</span>
                            <span class="text-medium-emphasis">{{ formatDate(r.period_start, locale) }} – {{ formatDate(r.period_end, locale) }}</span>
                            <v-chip v-if="r.posted" size="x-small" color="success" variant="tonal">{{ t('RecurringInvoices.run.posted') }}</v-chip>
                            <v-chip v-else-if="r.post_reason" size="x-small" color="warning" variant="tonal">{{ t('RecurringInvoices.run.notPosted', { reason: r.post_reason }) }}</v-chip>
                            <v-chip v-if="r.emailed" size="x-small" color="success" variant="tonal" prepend-icon="mdi-email-check-outline">{{ t('RecurringInvoices.run.emailed') }}</v-chip>
                            <v-chip v-else-if="r.email_error" size="x-small" color="error" variant="tonal" prepend-icon="mdi-email-alert-outline"
                                    @click="retryEmail(r)">{{ t('RecurringInvoices.run.emailFailed') }}: {{ r.email_error }}</v-chip>
                            <v-chip v-if="r.whatsapped" size="x-small" color="success" variant="tonal" prepend-icon="mdi-whatsapp">{{ t('RecurringInvoices.run.whatsapped') }}</v-chip>
                            <v-chip v-else-if="r.whatsapp_error" size="x-small" color="error" variant="tonal" prepend-icon="mdi-whatsapp"
                                    @click="retryWhatsApp(r)">{{ t('RecurringInvoices.run.whatsappFailed') }}: {{ r.whatsapp_error }}</v-chip>
                            <v-chip v-if="r.printed" size="x-small" color="success" variant="tonal" prepend-icon="mdi-printer-check">{{ t('RecurringInvoices.run.printed') }}</v-chip>
                            <v-chip v-else-if="r.print_error" size="x-small" color="error" variant="tonal">{{ r.print_error }}</v-chip>
                        </div>
                        <div v-for="b in lastRun.blocked" :key="'b' + b.config_id + b.period_start" class="text-warning py-1">
                            <v-icon size="small" start>mdi-hand-back-left-outline</v-icon>
                            {{ t('RecurringInvoices.run.blockedRow', { customer: b.customer_name, period: formatDate(b.period_start, locale) }) }}
                        </div>
                        <div v-for="e in lastRun.errors" :key="'e' + e.config_id + e.period_start" class="text-error py-1">
                            <v-icon size="small" start>mdi-alert-circle-outline</v-icon>{{ e.message }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-window-item>

            <!-- ── Abrechnungen ───────────────────────────────────────────── -->
            <v-window-item value="configs">
                <div class="d-flex align-center flex-wrap ga-3 mb-3">
                    <v-text-field v-model="search" density="compact" variant="outlined" hide-details clearable prepend-inner-icon="mdi-magnify"
                                  :label="t('RecurringInvoices.list.search')" style="max-width: 360px" />
                    <v-chip-group v-model="statusFilter" selected-class="text-primary" mandatory>
                        <v-chip value="all" filter variant="outlined" size="small">{{ t('RecurringInvoices.list.all') }} ({{ configs.length }})</v-chip>
                        <v-chip value="active" filter variant="outlined" size="small">{{ t('RecurringInvoices.status.active') }} ({{ kpis.active_count || 0 }})</v-chip>
                        <v-chip value="expiring" filter variant="outlined" size="small">{{ t('RecurringInvoices.list.expiring') }} ({{ expiring.count || 0 }})</v-chip>
                        <v-chip value="paused" filter variant="outlined" size="small">{{ t('RecurringInvoices.status.paused') }} ({{ kpis.paused_count || 0 }})</v-chip>
                        <v-chip value="terminated" filter variant="outlined" size="small">{{ t('RecurringInvoices.status.terminated') }} ({{ kpis.terminated_count || 0 }})</v-chip>
                        <v-chip value="ended" filter variant="outlined" size="small">{{ t('RecurringInvoices.status.ended') }} ({{ kpis.ended_count || 0 }})</v-chip>
                    </v-chip-group>
                </div>

                <v-alert v-if="!loading && !configs.length" type="info" variant="tonal" density="comfortable" icon="mdi-autorenew"
                         :title="t('RecurringInvoices.list.emptyTitle')" :text="t('RecurringInvoices.list.emptyText')" />

                <v-data-table v-else :items="filteredConfigs" :headers="configHeaders" item-value="id" density="comfortable" :items-per-page="25"
                              :search="search" class="config-table" :no-data-text="t('RecurringInvoices.list.emptyFiltered')" hover>
                    <template #item.status="{ item }">
                        <v-chip size="small" :color="statusColor(item.status)" variant="flat" label>{{ t('RecurringInvoices.status.' + item.status) }}</v-chip>
                        <div v-if="item.status === 'paused' && item.paused_until" class="text-caption text-medium-emphasis">
                            {{ t('RecurringInvoices.list.pausedUntil', { date: formatDate(item.paused_until, locale) }) }}
                        </div>
                        <v-chip v-if="item.blocked && item.status === 'active'" size="x-small" color="error" variant="tonal" class="mt-1">{{ t('RecurringInvoices.list.blocked') }}</v-chip>
                    </template>
                    <template #item.customer_name="{ item }">
                        <a href="#" class="link font-weight-medium" @click.prevent="openCustomer(item.customer_id)">{{ item.customer_name }}</a>
                        <div class="text-caption text-medium-emphasis">
                            <a href="#" class="link" @click.prevent="openOrder(item.oe_id)">{{ t('RecurringInvoices.list.order', { ordnumber: item.ordnumber }) }}</a>
                            <span v-if="item.transaction_description"> · {{ item.transaction_description }}</span>
                        </div>
                    </template>
                    <template #item.rhythm="{ item }">
                        {{ rhythmText(t, item.interval_unit, item.interval_count) }}
                        <div class="text-caption text-medium-emphasis">
                            {{ item.billing_timing === 'arrears' ? t('RecurringInvoices.dialog.arrears') : t('RecurringInvoices.dialog.advance') }}
                            <span v-if="item.align_to_calendar"> · {{ t('RecurringInvoices.list.aligned') }}</span>
                            <span v-if="item.send_email"> · <v-icon size="x-small">mdi-email-fast-outline</v-icon></span>
                            <span v-if="item.direct_debit"> · <v-icon size="x-small">mdi-bank-transfer</v-icon></span>
                        </div>
                    </template>
                    <template #item.next_billing_date="{ item }">
                        <template v-if="item.next_billing_date">
                            <span :class="{ 'text-error font-weight-medium': item.due_count > 0 }">{{ formatDate(item.next_billing_date, locale) }}</span>
                            <v-chip v-if="item.due_count > 0" size="x-small" color="error" variant="flat" class="ml-1">{{ t('RecurringInvoices.list.dueCount', { count: item.due_count }) }}</v-chip>
                            <div class="text-caption text-medium-emphasis">{{ formatDate(item.next_period_start, locale) }} – {{ formatDate(item.next_period_end, locale) }}</div>
                        </template>
                        <span v-else class="text-medium-emphasis">–</span>
                    </template>
                    <template #item.last_invoice_date="{ item }">
                        <template v-if="item.last_ar_id">
                            <a href="#" class="link" @click.prevent="openInvoice(item.last_ar_id)">{{ item.last_invnumber }}</a>
                            <div class="text-caption text-medium-emphasis">{{ formatDate(item.last_invoice_date, locale) }} · {{ t('RecurringInvoices.list.invoiceCount', { count: item.invoice_count }) }}</div>
                        </template>
                        <span v-else class="text-medium-emphasis">{{ t('RecurringInvoices.list.none') }}</span>
                    </template>
                    <template #item.period_amount="{ item }">
                        <span class="font-weight-medium">{{ money(item.period_amount, locale) }}</span>
                        <div class="text-caption text-medium-emphasis">{{ t('RecurringInvoices.list.perMonth', { amount: money(item.monthly_amount, locale) }) }}</div>
                    </template>
                    <template #item.term="{ item }">
                        <div>{{ formatDate(item.start_date, locale) }} – {{ item.end_date ? formatDate(item.end_date, locale) : t('RecurringInvoices.dialog.openEnded') }}</div>
                        <div v-if="item.cancel_deadline" class="text-caption" :class="daysUntil(item.cancel_deadline) <= 30 ? 'text-warning font-weight-medium' : 'text-medium-emphasis'">
                            {{ t('RecurringInvoices.list.cancelBy', { date: formatDate(item.cancel_deadline, locale), end: formatDate(item.next_possible_end, locale) }) }}
                        </div>
                        <div v-else-if="item.extend_automatically_by && item.end_date" class="text-caption text-medium-emphasis">
                            {{ t('RecurringInvoices.summary.extends', { months: item.extend_automatically_by }) }}
                        </div>
                        <div v-if="Number(item.open_total) > 0" class="text-caption text-error">{{ t('RecurringInvoices.list.open', { amount: money(item.open_total, locale) }) }}</div>
                    </template>
                    <template #item.actions="{ item }">
                        <v-btn icon size="small" variant="text" :title="t('RecurringInvoices.list.edit')" @click="edit(item)"><v-icon>mdi-pencil-outline</v-icon></v-btn>
                        <v-menu>
                            <template #activator="{ props: mp }">
                                <v-btn icon size="small" variant="text" v-bind="mp"><v-icon>mdi-dots-vertical</v-icon></v-btn>
                            </template>
                            <v-list density="compact">
                                <v-list-item v-if="item.status === 'active'" prepend-icon="mdi-pause-circle-outline" :title="t('RecurringInvoices.actions.pause')" @click="pause(item)" />
                                <v-list-item v-if="item.status === 'paused' || item.status === 'inactive'" prepend-icon="mdi-play-circle-outline" :title="t('RecurringInvoices.actions.resume')" @click="setStatus(item, 'active')" />
                                <v-list-item v-if="item.status === 'active' || item.status === 'paused'" prepend-icon="mdi-calendar-remove-outline" :title="t('RecurringInvoices.actions.terminate')" @click="terminate(item)" />
                                <v-list-item v-if="item.status === 'terminated'" prepend-icon="mdi-undo-variant" :title="t('RecurringInvoices.actions.reactivate')" @click="setStatus(item, 'reactivate')" />
                                <v-divider />
                                <v-list-item prepend-icon="mdi-file-document-outline" :title="t('RecurringInvoices.actions.openOrder')" @click="openOrder(item.oe_id)" />
                                <v-list-item prepend-icon="mdi-account-outline" :title="t('RecurringInvoices.actions.openCustomer')" @click="openCustomer(item.customer_id)" />
                            </v-list>
                        </v-menu>
                    </template>
                </v-data-table>
            </v-window-item>

            <!-- ── Vorschau ───────────────────────────────────────────────── -->
            <v-window-item value="upcoming">
                <v-card variant="outlined">
                    <v-card-title class="text-subtitle-1">{{ t('RecurringInvoices.upcoming.title') }}</v-card-title>
                    <v-card-subtitle>{{ t('RecurringInvoices.upcoming.subtitle') }}</v-card-subtitle>
                    <v-card-text>
                        <v-alert v-if="!upcoming.length" type="info" variant="tonal" density="compact" :text="t('RecurringInvoices.upcoming.empty')" />
                        <div v-else class="upcoming">
                            <div v-for="m in upcoming" :key="m.month" class="upcoming__row">
                                <div class="upcoming__month">{{ monthLabel(m.month) }}</div>
                                <div class="upcoming__bar">
                                    <div class="upcoming__fill" :style="{ width: barWidth(m.amount) }" />
                                </div>
                                <div class="upcoming__amount">{{ money(m.amount, locale) }}</div>
                                <div class="upcoming__count text-medium-emphasis">{{ t('RecurringInvoices.upcoming.invoices', { count: m.invoices }) }}</div>
                            </div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-window-item>
        </v-window>

        <RecurringConfigDialog v-model="dialogOpen" :oe-id="dialogOeId" @saved="reload" @deleted="reload" />
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import RecurringConfigDialog from './components/recurring.config.dialog.vue'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'
import { formatDate } from '@/core/utils/dateFormatter.js'
import { entityRoute } from '@/core/constants/routes.js'
import { useRecurringInvoices, rhythmText, money } from './composables/useRecurringInvoices.js'

const { t, locale } = useI18n()
const router = useRouter()
const api = useRecurringInvoices()
const loading = api.loading

const overview = ref(null)
const tab = ref('due')
const search = ref('')
const statusFilter = ref('all')
const selectedDue = ref([])
const creating = ref(false)
const lastRun = ref(null)
const dialogOpen = ref(false)
const dialogOeId = ref(null)

const kpis = computed(() => overview.value?.kpis || {})
const dueSummary = computed(() => overview.value?.due_summary || {})
const expiring = computed(() => overview.value?.expiring || { count: 0, ids: [] })
const configs = computed(() => overview.value?.configs || [])
const upcoming = computed(() => overview.value?.upcoming || [])
const dueRows = computed(() => (overview.value?.due || []).map(d => ({ ...d, key: `${d.config_id}:${d.period_start}` })))

const filteredConfigs = computed(() => {
    const f = statusFilter.value
    if (f === 'all') return configs.value
    if (f === 'expiring') return configs.value.filter(c => expiring.value.ids?.includes(c.id))
    if (f === 'ended') return configs.value.filter(c => c.status === 'ended' || c.status === 'inactive')
    return configs.value.filter(c => c.status === f)
})

const dueHeaders = computed(() => [
    { title: t('RecurringInvoices.due.customer'), key: 'customer_name' },
    { title: t('RecurringInvoices.due.order'), key: 'ordnumber', width: 110 },
    { title: t('RecurringInvoices.dialog.period'), key: 'period', sortable: false },
    { title: t('RecurringInvoices.dialog.invoiceDate'), key: 'billing_date', width: 140 },
    { title: t('RecurringInvoices.dialog.amount'), key: 'amount', align: 'end', width: 130 },
    { title: '', key: 'flags', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end', width: 220 }
])

const configHeaders = computed(() => [
    { title: t('RecurringInvoices.list.status'), key: 'status', width: 120 },
    { title: t('RecurringInvoices.due.customer'), key: 'customer_name' },
    { title: t('RecurringInvoices.list.rhythm'), key: 'rhythm', sortable: false },
    { title: t('RecurringInvoices.list.next'), key: 'next_billing_date' },
    { title: t('RecurringInvoices.list.last'), key: 'last_invoice_date' },
    { title: t('RecurringInvoices.list.amount'), key: 'period_amount', align: 'end' },
    { title: t('RecurringInvoices.list.term'), key: 'term', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end', width: 100 }
])

async function reload() {
    try {
        overview.value = await api.fetchOverview()
        // Fällige vorauswählen — gesperrte nicht
        selectedDue.value = dueRows.value.filter(d => !d.blocked).map(d => d.key)
    } catch (e) {
        alerts.error(e.message)
    }
}

async function runCreate(items) {
    if (!items.length) return
    creating.value = true
    try {
        const res = await api.createInvoices(items)
        lastRun.value = res
        if (res.created.length) toasts.success(t('RecurringInvoices.created', { count: res.created.length }))
        if (res.errors.length) toasts.error(t('RecurringInvoices.run.errors', { count: res.errors.length }))
        await reload()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        creating.value = false
    }
}

function createSelected() {
    const rows = dueRows.value.filter(d => selectedDue.value.includes(d.key))
    runCreate(rows.map(d => ({ config_id: d.config_id, period_start: d.period_start, force: d.blocked })))
}

async function createOne(item) {
    if (item.blocked) {
        const answer = await alerts.question(t('RecurringInvoices.due.createAnywayText', { customer: item.customer_name, days: item.hold_on_overdue_days }),
            t('RecurringInvoices.due.createAnyway'), t('RecurringInvoices.due.create'), t('RecurringInvoices.dialog.cancel'))
        if (!answer.isConfirmed) return
    }
    runCreate([{ config_id: item.config_id, period_start: item.period_start, force: item.blocked }])
}

async function skip(item) {
    const answer = await alerts.question(
        t('RecurringInvoices.due.skipText', { period: `${formatDate(item.period_start, locale.value)} – ${formatDate(item.period_end, locale.value)}`, customer: item.customer_name }),
        t('RecurringInvoices.due.skip'), t('RecurringInvoices.due.skip'), t('RecurringInvoices.dialog.cancel'))
    if (!answer.isConfirmed) return
    try {
        await api.skipPeriod(item.config_id, item.period_start)
        toasts.success(t('RecurringInvoices.due.skipped'))
        await reload()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function retryWhatsApp(r) {
    try {
        await api.sendWhatsApp(r.periodic_invoice_id)
        r.whatsapped = true
        r.whatsapp_error = null
        toasts.success(t('RecurringInvoices.run.whatsapped'))
    } catch (e) {
        alerts.error(e.message)
    }
}

async function retryEmail(r) {
    try {
        await api.sendEmail(r.periodic_invoice_id)
        r.emailed = true
        r.email_error = null
        toasts.success(t('RecurringInvoices.run.emailed'))
    } catch (e) {
        alerts.error(e.message)
    }
}

function edit(item) {
    dialogOeId.value = item.oe_id
    dialogOpen.value = true
}

async function setStatus(item, status, extra = {}) {
    try {
        await api.setStatus(item.id, status, extra)
        toasts.success(t('RecurringInvoices.actions.statusSaved'))
        await reload()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function pause(item) {
    const answer = await alerts.question(t('RecurringInvoices.actions.pauseText'), t('RecurringInvoices.actions.pause'),
        t('RecurringInvoices.actions.pause'), t('RecurringInvoices.dialog.cancel'))
    if (!answer.isConfirmed) return
    setStatus(item, 'paused')
}

async function terminate(item) {
    const suggested = item.next_possible_end || item.end_date || ''
    const answer = await alerts.question(
        t('RecurringInvoices.actions.terminateText', { date: suggested ? formatDate(suggested, locale.value) : '–' }),
        t('RecurringInvoices.actions.terminate'), t('RecurringInvoices.actions.terminate'), t('RecurringInvoices.dialog.cancel'))
    if (!answer.isConfirmed) return
    if (!suggested) {
        // Unbefristet ohne Kündigungsfrist: zum Ende der laufenden Periode
        const end = item.next_period_end || new Date().toISOString().slice(0, 10)
        return setStatus(item, 'terminated', { end_date: end })
    }
    setStatus(item, 'terminated', { end_date: suggested })
}

function openOrder(id) { router.push(entityRoute('order', id)) }
function openInvoice(id) { router.push(entityRoute('invoice', id)) }
function openCustomer(id) { router.push(entityRoute('customer', id)) }

function statusColor(status) {
    return { active: 'success', paused: 'warning', terminated: 'orange', ended: 'grey', inactive: 'grey' }[status] || 'grey'
}
function daysLate(date) {
    return Math.floor((Date.now() - new Date(date).getTime()) / 86400000)
}
function daysUntil(date) {
    return Math.ceil((new Date(date).getTime() - Date.now()) / 86400000)
}
function monthLabel(ym) {
    const [y, m] = ym.split('-').map(Number)
    return new Date(y, m - 1, 1).toLocaleDateString(locale.value === 'en' ? 'en-GB' : 'de-DE', { month: 'long', year: 'numeric' })
}
const maxUpcoming = computed(() => Math.max(1, ...upcoming.value.map(m => Number(m.amount) || 0)))
function barWidth(amount) {
    return Math.max(2, Math.round(100 * (Number(amount) || 0) / maxUpcoming.value)) + '%'
}

onMounted(reload)
</script>

<style scoped>
.pulse__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
}
.pulse__cell {
    text-align: left;
    padding: 14px 16px;
    border-right: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    background: transparent;
    cursor: pointer;
    transition: background 0.15s;
}
.pulse__cell:last-child { border-right: none; }
.pulse__cell:hover { background: rgba(var(--v-theme-primary), 0.05); }
.pulse__label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: rgba(var(--v-theme-on-surface), 0.6); }
.pulse__value { font-size: 1.35rem; font-weight: 600; line-height: 1.3; }
.pulse__sub { font-size: 0.75rem; color: rgba(var(--v-theme-on-surface), 0.6); }
.link { color: rgb(var(--v-theme-primary)); text-decoration: none; }
.link:hover { text-decoration: underline; }
.upcoming__row {
    display: grid;
    grid-template-columns: 160px 1fr 130px 110px;
    align-items: center;
    gap: 12px;
    padding: 6px 0;
}
.upcoming__bar { height: 14px; background: rgba(var(--v-theme-primary), 0.08); border-radius: 7px; overflow: hidden; }
.upcoming__fill { height: 100%; background: rgb(var(--v-theme-primary)); border-radius: 7px; transition: width 0.3s; }
.upcoming__amount { text-align: right; font-weight: 600; }
.upcoming__count { font-size: 0.8rem; }
@media (max-width: 700px) {
    .upcoming__row { grid-template-columns: 1fr 1fr; }
    .upcoming__bar { grid-column: 1 / -1; }
}
</style>
