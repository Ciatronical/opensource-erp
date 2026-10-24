<!-- src/core/views/faktura/components/sent.status.component.vue -->
<!--
    Versandstatus eines Belegs: auf einen Blick, ob und wie der Beleg den Kunden
    erreicht hat (E-Mail, WhatsApp, Post, DHL). Die Einträge kommen aus getFakturaData
    (sent_log: record_links → email_journal / whatsapp_messages, dhl_shipments,
    postal_dispatches).

    Bei Aufträgen steht rechts daneben der Rechnungsstatus (invoice_status aus
    getFakturaData, SQL-Funktion oe_invoice_status): welche Rechnung ist aus dem
    Auftrag entstanden — anklickbar — oder „Noch keine Rechnung“ mit dem Knopf,
    sie gleich zu erstellen.

    Beides sind schlichte Vuetify-Alerts (tonal): grün = erledigt, rot = fehlt noch.
-->
<template>
    <v-row dense class="sent-status">
        <!-- Versand -->
        <v-col cols="12" :md="invoiceStatus ? 7 : 12">
            <v-alert
                :type="sent ? 'success' : 'error'"
                variant="tonal"
                density="compact"
                :icon="sent ? 'mdi-check-decagram' : 'mdi-send-clock-outline'"
                class="h-100"
            >
                <template #title>
                    <span class="d-flex align-center ga-2">
                        {{ sent ? t('FakturaView.faktura.sent.title') : t('FakturaView.faktura.sent.notSent') }}
                        <v-chip v-if="sent" size="x-small" variant="flat" color="success">{{ entries.length }}</v-chip>
                    </span>
                </template>

                <!-- Versendet: ein Chip je Kanal mit letztem Versand -->
                <div v-if="sent" class="d-flex flex-wrap ga-1 mt-1">
                    <v-chip
                        v-for="ch in latestPerChannel"
                        :key="ch.channel"
                        size="small"
                        variant="tonal"
                        :color="channelMeta[ch.channel].color"
                        class="sent-status__chip"
                    >
                        <v-icon start size="16">{{ channelMeta[ch.channel].icon }}</v-icon>
                        <span class="font-weight-medium">{{ t(channelMeta[ch.channel].label) }}</span>
                        <span class="sent-status__chip-sep">·</span>
                        <span>{{ formatWhen(ch.sent_at) }}</span>
                        <template v-if="ch.recipient">
                            <span class="sent-status__chip-sep">·</span>
                            <span class="sent-status__chip-recipient">{{ ch.recipient }}</span>
                        </template>
                        <v-icon
                            v-if="ch.channel === 'whatsapp'"
                            end
                            size="16"
                            :color="waStatusMeta(ch.status).color"
                            :title="t(waStatusMeta(ch.status).label)"
                        >{{ waStatusMeta(ch.status).icon }}</v-icon>
                        <v-icon
                            v-if="ch.channel === 'post'"
                            end
                            size="16"
                            :color="postStatusMeta(ch.status).color"
                            :title="t(postStatusMeta(ch.status).label)"
                        >{{ postStatusMeta(ch.status).icon }}</v-icon>
                        <span v-if="ch.count > 1" class="sent-status__chip-more">+{{ ch.count - 1 }}</span>
                    </v-chip>
                </div>

                <!-- Noch nicht versendet: Hinweis, ggf. auf den automatischen Versand beim Drucken -->
                <div v-else class="text-body-2">
                    {{ autoSendHint || t('FakturaView.faktura.sent.notSentHint') }}
                </div>

                <!-- Verlauf: alle Versandereignisse, neueste zuerst -->
                <v-expand-transition>
                    <div v-show="expanded && sent">
                        <v-timeline side="end" density="compact" align="start" truncate-line="both" class="mt-2">
                            <v-timeline-item
                                v-for="(e, i) in entries"
                                :key="i"
                                :dot-color="channelMeta[e.channel]?.color || 'grey'"
                                :icon="channelMeta[e.channel]?.icon"
                                icon-color="white"
                                size="small"
                            >
                                <div class="text-body-2">
                                    <div class="d-flex align-center flex-wrap ga-2">
                                        <span class="font-weight-medium">{{ t(channelMeta[e.channel]?.label || 'FakturaView.faktura.sent.channelEmail') }}</span>
                                        <span class="text-medium-emphasis">{{ formatWhen(e.sent_at) }}</span>
                                        <v-chip
                                            v-if="e.channel === 'whatsapp'"
                                            size="x-small"
                                            variant="tonal"
                                            :color="waStatusMeta(e.status).color"
                                            :prepend-icon="waStatusMeta(e.status).icon"
                                        >{{ t(waStatusMeta(e.status).label) }}</v-chip>
                                        <v-chip
                                            v-if="e.channel === 'post'"
                                            size="x-small"
                                            variant="tonal"
                                            :color="postStatusMeta(e.status).color"
                                            :prepend-icon="postStatusMeta(e.status).icon"
                                        >{{ t(postStatusMeta(e.status).label) }}</v-chip>
                                    </div>
                                    <div v-if="e.recipient">
                                        <v-icon size="14" class="me-1">mdi-account-arrow-right-outline</v-icon>{{ e.recipient }}
                                    </div>
                                    <div v-if="e.subject" class="text-medium-emphasis">
                                        <v-icon size="14" class="me-1">{{ e.channel === 'dhl' ? 'mdi-package-variant' : (e.channel === 'post' ? 'mdi-file-pdf-box' : 'mdi-text-short') }}</v-icon>{{ e.subject }}
                                    </div>
                                    <div v-if="e.employee_name" class="text-medium-emphasis">
                                        <v-icon size="14" class="me-1">mdi-account-tie</v-icon>{{ e.employee_name }}
                                    </div>
                                </div>
                            </v-timeline-item>
                        </v-timeline>
                    </div>
                </v-expand-transition>

                <template v-if="sent" #append>
                    <v-btn
                        variant="text"
                        size="small"
                        color="success"
                        :append-icon="expanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                        @click="expanded = !expanded"
                    >
                        {{ t('FakturaView.faktura.sent.history') }}
                    </v-btn>
                </template>
            </v-alert>
        </v-col>

        <!-- Rechnungsstatus (nur Aufträge) -->
        <v-col v-if="invoiceStatus" cols="12" md="5">
            <v-alert
                :type="invoiceState === 'invoiced' ? 'success' : 'error'"
                variant="tonal"
                density="compact"
                :icon="invoiceMeta.icon"
                class="h-100"
            >
                <template #title>
                    <span class="d-flex align-center ga-2">
                        {{ t(invoiceMeta.label) }}
                        <v-chip v-if="invoiceCount > 1" size="x-small" variant="flat" color="success">{{ invoiceCount }}</v-chip>
                    </span>
                </template>

                <div v-if="invoiceList.length" class="d-flex flex-wrap align-center ga-1 mt-1">
                    <v-chip
                        v-for="inv in invoiceList"
                        :key="inv.id"
                        size="small"
                        variant="tonal"
                        :color="inv.storno ? 'error' : 'success'"
                        class="sent-status__chip"
                        :title="t('FakturaView.faktura.sent.invoiceOpen', { number: inv.number })"
                        @click="emit('open-invoice', inv)"
                    >
                        <v-icon start size="16">{{ inv.storno ? 'mdi-file-document-remove-outline' : 'mdi-file-document-outline' }}</v-icon>
                        <span class="font-weight-medium">{{ inv.number }}</span>
                        <span class="sent-status__chip-sep">·</span>
                        <span>{{ formatDay(inv.transdate) }}</span>
                        <span class="sent-status__chip-sep">·</span>
                        <span v-if="inv.storno">{{ t('FakturaView.faktura.sent.invoiceStornoShort') }}</span>
                        <span v-else-if="isPaid(inv)" class="d-inline-flex align-center">
                            <v-icon size="14" class="me-1">mdi-check</v-icon>{{ t('FakturaView.faktura.sent.invoicePaid') }}
                        </span>
                        <span v-else>{{ t('FakturaView.faktura.sent.invoiceOpenAmount', { amount: formatAmount(openAmount(inv)) }) }}</span>
                    </v-chip>
                    <span v-if="invoiceMore > 0" class="sent-status__chip-more">+{{ invoiceMore }}</span>
                </div>
                <div v-else class="text-body-2">
                    {{ t('FakturaView.faktura.sent.invoiceNoneHint') }}
                </div>

                <template v-if="invoiceState === 'none' && canCreateInvoice" #append>
                    <v-btn
                        variant="flat"
                        size="small"
                        color="error"
                        prepend-icon="mdi-file-document-edit"
                        @click="emit('create-invoice')"
                    >
                        {{ t('FakturaView.faktura.sent.invoiceCreate') }}
                    </v-btn>
                </template>
            </v-alert>
        </v-col>
    </v-row>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    /** sent_log aus getFakturaData: [{channel, sent_at, recipient, subject, status, employee_name}] */
    entries: { type: Array, default: () => [] },
    /** Hinweistext, wenn der Beleg beim Drucken automatisch versendet wird (leer = kein Auto-Versand) */
    autoSendHint: { type: String, default: '' },
    /** invoice_status aus getFakturaData (nur Aufträge): {state, count, invoices: [{id, number, transdate, amount, paid, storno}]} — null blendet den Block aus */
    invoiceStatus: { type: Object, default: null },
    /** Darf aus diesem Beleg eine Rechnung erstellt werden? (Knopf „Rechnung erstellen“) */
    canCreateInvoice: { type: Boolean, default: true },
})

const emit = defineEmits(['open-invoice', 'create-invoice'])

const { t, locale } = useI18n()
const expanded = ref(false)

const sent = computed(() => props.entries.length > 0)

// ── Rechnungsstatus (Aufträge) ───────────────────────────────────────────────
const INVOICE_CHIPS = 3
const invoiceState = computed(() => props.invoiceStatus?.state || 'none')
const invoiceCount = computed(() => Number(props.invoiceStatus?.count || 0))
const invoiceAll   = computed(() => props.invoiceStatus?.invoices || [])
const invoiceList  = computed(() => invoiceAll.value.slice(0, INVOICE_CHIPS))
const invoiceMore  = computed(() => Math.max(0, invoiceAll.value.length - INVOICE_CHIPS))
const invoiceMeta  = computed(() => {
    switch (invoiceState.value) {
        case 'invoiced': return { icon: 'mdi-file-document-check',  color: 'success', label: 'FakturaView.faktura.sent.invoiceTitle' }
        case 'storno':   return { icon: 'mdi-file-document-remove', color: 'error',   label: 'FakturaView.faktura.sent.invoiceStorno' }
        default:         return { icon: 'mdi-file-document-alert-outline', color: 'error', label: 'FakturaView.faktura.sent.invoiceNone' }
    }
})
const openAmount = (inv) => Number(inv.amount || 0) - Number(inv.paid || 0)
const isPaid = (inv) => Math.abs(openAmount(inv)) < 0.005
function formatAmount(value) {
    return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(Number(value || 0))
}
function formatDay(value) {
    if (!value) return ''
    const d = new Date(value)
    if (isNaN(d.getTime())) return String(value)
    return d.toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' })
}

const channelMeta = {
    email:    { icon: 'mdi-email',          color: 'info',           label: 'FakturaView.faktura.sent.channelEmail' },
    whatsapp: { icon: 'mdi-whatsapp',       color: 'green-darken-1', label: 'FakturaView.faktura.sent.channelWhatsapp' },
    dhl:      { icon: 'mdi-truck-delivery', color: 'amber-darken-2', label: 'FakturaView.faktura.sent.channelDhl' },
    post:     { icon: 'mdi-mailbox-up-outline', color: 'deep-purple', label: 'FakturaView.faktura.sent.channelPost' },
}

/** Status einer Posteinlieferung (postal_dispatches.status) */
function postStatusMeta(status) {
    switch (status) {
        case 'done':     return { icon: 'mdi-check-all',      color: 'success', label: 'FakturaView.faktura.sent.postDone' }
        case 'manual':   return { icon: 'mdi-printer-check',  color: 'grey',    label: 'FakturaView.faktura.sent.postManual' }
        case 'canceled': return { icon: 'mdi-cancel',         color: 'warning', label: 'FakturaView.faktura.sent.postCanceled' }
        default:         return { icon: 'mdi-mailbox-up-outline', color: 'grey', label: 'FakturaView.faktura.sent.postSent' }
    }
}

/** Letzter Versand je Kanal plus Anzahl — die Einträge kommen neueste zuerst */
const latestPerChannel = computed(() => {
    const map = new Map()
    for (const e of props.entries) {
        if (!channelMeta[e.channel]) continue
        const cur = map.get(e.channel)
        if (cur) cur.count++
        else map.set(e.channel, { ...e, count: 1 })
    }
    return [...map.values()]
})

function waStatusMeta(status) {
    switch (status) {
        case 'read':      return { icon: 'mdi-check-all',    color: 'blue',   label: 'FakturaView.faktura.sent.statusRead' }
        case 'delivered': return { icon: 'mdi-check-all',    color: 'grey',   label: 'FakturaView.faktura.sent.statusDelivered' }
        case 'failed':    return { icon: 'mdi-alert-circle', color: 'error',  label: 'FakturaView.faktura.sent.statusFailed' }
        default:          return { icon: 'mdi-check',        color: 'grey',   label: 'FakturaView.faktura.sent.statusSent' }
    }
}

function formatWhen(value) {
    if (!value) return ''
    const d = new Date(value)
    if (isNaN(d.getTime())) return String(value)
    return d.toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<style scoped>
/* Titel wie ein Abschnittstitel, Inhalt oben ausgerichtet — beide Alerts gleich hoch */
.sent-status :deep(.v-alert-title) {
    font-size: 1rem;
    font-weight: 600;
}

.sent-status :deep(.v-alert__content) {
    align-self: flex-start;
}

.sent-status__chip {
    max-width: 100%;
}

/* Chip-Inhalt darf kürzen statt über die Karte hinauszulaufen */
.sent-status__chip :deep(.v-chip__content) {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sent-status__chip-sep {
    margin: 0 6px;
    opacity: 0.5;
}

.sent-status__chip-recipient {
    max-width: 260px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sent-status__chip-more {
    margin-left: 6px;
    font-size: 11px;
    opacity: 0.75;
}

@media (max-width: 600px) {
    .sent-status__chip-recipient {
        max-width: 120px;
    }
}
</style>
