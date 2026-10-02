<!-- src/core/views/faktura/cards/recurring.section.card.vue -->
<!--
    Wiederkehrende Abrechnung im Auftrag.

    Ohne Einrichtung eine Zeile mit dem Angebot, den Auftrag wiederkehrend
    abzurechnen. Mit Einrichtung der Zustand auf einen Blick: Rhythmus,
    nächste Rechnung, bisher erzeugt, offen — und was jetzt fällig ist, lässt
    sich direkt hier erzeugen. Alles Weitere im Dialog.
-->
<template>
    <v-card variant="outlined" class="faktura-card mb-3 recurring-card" :class="{ 'recurring-card--active': info?.exists }">
        <v-card-text class="d-flex align-center flex-wrap ga-3 py-3">
            <v-icon :color="info?.exists ? 'primary' : 'grey'">mdi-autorenew</v-icon>

            <template v-if="!info">
                <span class="text-body-2 text-medium-emphasis">{{ t('RecurringInvoices.card.loading') }}</span>
            </template>

            <template v-else-if="!info.exists">
                <div class="flex-grow-1">
                    <div class="text-subtitle-2">{{ t('RecurringInvoices.card.title') }}</div>
                    <div class="text-body-2 text-medium-emphasis">{{ t('RecurringInvoices.card.notConfigured') }}</div>
                </div>
                <v-btn color="primary" variant="tonal" class="text-none" prepend-icon="mdi-plus" :disabled="!hasCustomer" @click="open">
                    {{ t('RecurringInvoices.card.setup') }}
                </v-btn>
            </template>

            <template v-else>
                <div class="flex-grow-1">
                    <div class="d-flex align-center flex-wrap ga-2">
                        <span class="text-subtitle-2">{{ t('RecurringInvoices.card.title') }}</span>
                        <v-chip size="x-small" :color="statusColor(cfg.status)" variant="flat" label>{{ t('RecurringInvoices.status.' + cfg.status) }}</v-chip>
                        <v-chip v-if="dueCount" size="x-small" color="error" variant="flat">{{ t('RecurringInvoices.list.dueCount', { count: dueCount }) }}</v-chip>
                        <v-chip v-if="cfg.blocked" size="x-small" color="error" variant="tonal">{{ t('RecurringInvoices.list.blocked') }}</v-chip>
                    </div>
                    <div class="text-body-2 text-medium-emphasis mt-1">
                        {{ rhythmText(t, cfg.interval_unit, cfg.interval_count) }}
                        · {{ money(cfg.period_amount, locale) }} {{ t('RecurringInvoices.card.perPeriod') }}
                        <template v-if="nextPeriod"> · {{ t('RecurringInvoices.card.next', { date: formatDate(nextPeriod.billing_date, locale) }) }}</template>
                        <template v-if="stats.invoice_count"> · {{ t('RecurringInvoices.card.invoiced', { count: stats.invoice_count, amount: money(stats.invoiced_total, locale) }) }}</template>
                        <span v-if="Number(stats.open_total) > 0" class="text-error"> · {{ t('RecurringInvoices.list.open', { amount: money(stats.open_total, locale) }) }}</span>
                    </div>
                </div>
                <v-btn v-if="dueCount" color="primary" variant="flat" size="small" class="text-none" :loading="creating" @click="createDue">
                    <v-icon start size="small">mdi-file-document-plus-outline</v-icon>{{ t('RecurringInvoices.card.createDue', { count: dueCount }) }}
                </v-btn>
                <v-btn variant="tonal" size="small" class="text-none" prepend-icon="mdi-pencil-outline" @click="open">{{ t('RecurringInvoices.card.edit') }}</v-btn>
                <v-btn icon variant="text" size="small" :title="t('RecurringInvoices.card.overview')" :to="{ name: 'recurring-invoices' }">
                    <v-icon>mdi-view-list-outline</v-icon>
                </v-btn>
            </template>
        </v-card-text>

        <RecurringConfigDialog v-model="dialogOpen" :oe-id="oeId" @saved="onSaved" @deleted="load" />
    </v-card>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import RecurringConfigDialog from '@/core/views/recurring-invoices/components/recurring.config.dialog.vue'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'
import { formatDate } from '@/core/utils/dateFormatter.js'
import { useRecurringInvoices, rhythmText, money } from '@/core/views/recurring-invoices/composables/useRecurringInvoices.js'

const props = defineProps({
    oeId: { type: Number, default: null },
    hasCustomer: { type: Boolean, default: false }
})
const emit = defineEmits(['invoices-created'])

const { t, locale } = useI18n()
const api = useRecurringInvoices()

const info = ref(null)
const dialogOpen = ref(false)
const creating = ref(false)

const cfg = computed(() => info.value?.config || {})
const stats = computed(() => info.value?.stats || {})
const duePeriods = computed(() => (info.value?.periods || []).filter(p => p.state === 'due'))
const dueCount = computed(() => duePeriods.value.length)
const nextPeriod = computed(() => (info.value?.periods || []).find(p => p.state === 'due' || p.state === 'planned'))

async function load() {
    if (!props.oeId) { info.value = { exists: false }; return }
    try {
        info.value = await api.fetchConfig(props.oeId)
    } catch (e) {
        info.value = { exists: false }
    }
}

function open() {
    dialogOpen.value = true
}

function onSaved(e) {
    load()
    if (e?.created?.length) emit('invoices-created', e.created)
}

async function createDue() {
    creating.value = true
    try {
        const res = await api.createInvoices(duePeriods.value.map(p => ({ config_id: cfg.value.id, period_start: p.period_start })))
        if (res.created.length) toasts.success(t('RecurringInvoices.created', { count: res.created.length }))
        if (res.blocked.length) toasts.warning(t('RecurringInvoices.card.blockedHint'))
        if (res.errors.length) alerts.error(res.errors.map(e => e.message).join('\n'))
        emit('invoices-created', res.created)
        await load()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        creating.value = false
    }
}

function statusColor(status) {
    return { active: 'success', paused: 'warning', terminated: 'orange', ended: 'grey', inactive: 'grey' }[status] || 'grey'
}

watch(() => props.oeId, load)
onMounted(load)
</script>

<style scoped>
.recurring-card--active { border-color: rgba(var(--v-theme-primary), 0.4); }
</style>
