<!-- src/core/views/faktura/components/sent.status.component.vue -->
<!--
    Versandstatus eines Belegs: auf einen Blick, ob und wie der Beleg den Kunden
    erreicht hat (E-Mail, WhatsApp, DHL). Die Einträge kommen aus getFakturaData
    (sent_log: record_links → email_journal / whatsapp_messages, dhl_shipments).
-->
<template>
    <v-card
        variant="outlined"
        class="sent-status"
        :class="sent ? 'sent-status--sent' : 'sent-status--pending'"
    >
        <div
            class="sent-status__main"
            :class="{ 'sent-status__main--clickable': sent }"
            :role="sent ? 'button' : undefined"
            :tabindex="sent ? 0 : undefined"
            @click="sent && (expanded = !expanded)"
            @keydown.enter.prevent="sent && (expanded = !expanded)"
            @keydown.space.prevent="sent && (expanded = !expanded)"
        >
            <div class="sent-status__icon">
                <v-icon :color="sent ? 'success' : 'grey'" size="22">
                    {{ sent ? 'mdi-check-decagram' : 'mdi-send-clock-outline' }}
                </v-icon>
            </div>

            <div class="sent-status__text">
                <div class="sent-status__title">
                    {{ sent ? t('FakturaView.faktura.sent.title') : t('FakturaView.faktura.sent.notSent') }}
                    <span v-if="sent" class="sent-status__count">{{ entries.length }}</span>
                </div>

                <!-- Versendet: ein Chip je Kanal mit letztem Versand -->
                <div v-if="sent" class="sent-status__chips">
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
                        <span v-if="ch.count > 1" class="sent-status__chip-more">+{{ ch.count - 1 }}</span>
                    </v-chip>
                </div>

                <!-- Noch nicht versendet: Hinweis, ggf. auf den automatischen Versand beim Drucken -->
                <div v-else class="sent-status__hint">
                    {{ autoSendHint || t('FakturaView.faktura.sent.notSentHint') }}
                </div>
            </div>

            <v-btn
                v-if="sent"
                variant="text"
                size="small"
                color="success"
                class="sent-status__toggle"
                :append-icon="expanded ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                @click.stop="expanded = !expanded"
            >
                {{ t('FakturaView.faktura.sent.history') }}
            </v-btn>
        </div>

        <!-- Verlauf: alle Versandereignisse, neueste zuerst -->
        <v-expand-transition>
            <div v-show="expanded && sent">
                <v-divider />
                <v-timeline side="end" density="compact" align="start" truncate-line="both" class="sent-status__timeline">
                    <v-timeline-item
                        v-for="(e, i) in entries"
                        :key="i"
                        :dot-color="channelMeta[e.channel]?.color || 'grey'"
                        :icon="channelMeta[e.channel]?.icon"
                        icon-color="white"
                        size="small"
                    >
                        <div class="sent-status__event">
                            <div class="sent-status__event-head">
                                <span class="font-weight-medium">{{ t(channelMeta[e.channel]?.label || 'FakturaView.faktura.sent.channelEmail') }}</span>
                                <span class="text-medium-emphasis">{{ formatWhen(e.sent_at) }}</span>
                                <v-chip
                                    v-if="e.channel === 'whatsapp'"
                                    size="x-small"
                                    variant="tonal"
                                    :color="waStatusMeta(e.status).color"
                                    :prepend-icon="waStatusMeta(e.status).icon"
                                >{{ t(waStatusMeta(e.status).label) }}</v-chip>
                            </div>
                            <div v-if="e.recipient" class="sent-status__event-line">
                                <v-icon size="14" class="mr-1">mdi-account-arrow-right-outline</v-icon>{{ e.recipient }}
                            </div>
                            <div v-if="e.subject" class="sent-status__event-line text-medium-emphasis">
                                <v-icon size="14" class="mr-1">{{ e.channel === 'dhl' ? 'mdi-package-variant' : 'mdi-text-short' }}</v-icon>{{ e.subject }}
                            </div>
                            <div v-if="e.employee_name" class="sent-status__event-line text-medium-emphasis">
                                <v-icon size="14" class="mr-1">mdi-account-tie</v-icon>{{ e.employee_name }}
                            </div>
                        </div>
                    </v-timeline-item>
                </v-timeline>
            </div>
        </v-expand-transition>
    </v-card>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    /** sent_log aus getFakturaData: [{channel, sent_at, recipient, subject, status, employee_name}] */
    entries: { type: Array, default: () => [] },
    /** Hinweistext, wenn der Beleg beim Drucken automatisch versendet wird (leer = kein Auto-Versand) */
    autoSendHint: { type: String, default: '' },
})

const { t, locale } = useI18n()
const expanded = ref(false)

const sent = computed(() => props.entries.length > 0)

const channelMeta = {
    email:    { icon: 'mdi-email',          color: 'info',           label: 'FakturaView.faktura.sent.channelEmail' },
    whatsapp: { icon: 'mdi-whatsapp',       color: 'green-darken-1', label: 'FakturaView.faktura.sent.channelWhatsapp' },
    dhl:      { icon: 'mdi-truck-delivery', color: 'amber-darken-2', label: 'FakturaView.faktura.sent.channelDhl' },
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
.sent-status {
    border-radius: 8px;
    overflow: hidden;
}

/* Versendet: grüner Akzent links, zarter Verlauf */
.sent-status--sent {
    border-color: rgba(76, 175, 80, 0.45) !important;
    background: linear-gradient(90deg, rgba(76, 175, 80, 0.10) 0%, rgba(76, 175, 80, 0.03) 35%, #fff 100%);
    box-shadow: inset 4px 0 0 #4caf50;
}

/* Noch nicht versendet: gestrichelt und zurückhaltend */
.sent-status--pending {
    border-style: dashed !important;
    border-color: rgba(0, 0, 0, 0.18) !important;
    background: #fafafa;
}

.sent-status__main {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 16px;
    min-height: 56px;
}

.sent-status__main--clickable {
    cursor: pointer;
}

.sent-status__icon {
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.04);
}

.sent-status--sent .sent-status__icon {
    background: rgba(76, 175, 80, 0.15);
}

.sent-status__text {
    flex: 1 1 auto;
    min-width: 0;
}

.sent-status__title {
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
    line-height: 1.3;
}

.sent-status__count {
    font-size: 11px;
    font-weight: 600;
    line-height: 18px;
    min-width: 18px;
    padding: 0 6px;
    border-radius: 9px;
    text-align: center;
    color: #2e7d32;
    background: rgba(76, 175, 80, 0.18);
}

.sent-status__hint {
    font-size: 12.5px;
    color: rgba(0, 0, 0, 0.55);
    margin-top: 2px;
}

.sent-status__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
}

.sent-status__chip {
    max-width: 100%;
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

.sent-status__toggle {
    flex: 0 0 auto;
}

.sent-status__timeline {
    padding: 12px 16px 4px;
    background: #fff;
}

.sent-status__event {
    font-size: 13px;
    padding-bottom: 6px;
}

.sent-status__event-head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 2px;
}

.sent-status__event-line {
    display: flex;
    align-items: center;
    line-height: 1.6;
    word-break: break-word;
}

/* Chip-Inhalt darf kürzen statt über die Karte hinauszulaufen */
.sent-status__chip :deep(.v-chip__content) {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}

@media (max-width: 600px) {
    /* Symbol und Text bleiben in einer Zeile, nur der Verlauf-Button rutscht darunter */
    .sent-status__main {
        flex-wrap: wrap;
    }
    .sent-status__text {
        flex: 1 1 0;
        min-width: 60%;
    }
    .sent-status__toggle {
        flex-basis: 100%;
        margin-left: 50px;
        justify-content: flex-start;
    }
    .sent-status__chip-recipient {
        max-width: 120px;
    }
}
</style>
