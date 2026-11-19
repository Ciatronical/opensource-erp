<!-- src/core/components/crmview/occurrence.sent.component.vue -->
<!--
    Versand-Kurzfassung eines Belegs für die Spalte „Status“ der Belegkarte:
    je Kanal (E-Mail, WhatsApp, SMS, Post, DHL, Druck) ein Symbol, Einzelheiten
    (wann zuletzt, wie oft, an wen, Zustellstatus) im Tooltip. Die Daten kommen
    mit getCV (SQL-Funktion record_sent_summary), neuester Kanal zuerst.
    Mit show-unsent steht bei einem Beleg ohne Versand ein blasses Uhr-Symbol.
-->
<template>
    <span class="occurrence-sent">
        <v-icon
            v-for="ch in channels"
            :key="ch.channel"
            size="16"
            :color="ch.failed ? 'error' : ch.meta.color"
            :title="ch.title"
        >{{ ch.failed ? 'mdi-alert-circle' : ch.meta.icon }}</v-icon>
        <v-icon
            v-if="showUnsent && channels.length === 0"
            size="16"
            class="occurrence-sent__none"
            :title="t('CrmView.notSent')"
        >mdi-send-clock-outline</v-icon>
    </span>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { channelMeta, postStatusMeta, waStatusMeta } from '@/core/views/faktura/components/sent.channels.js'

const props = defineProps({
    /** sent aus getCV: [{channel, count, sent_at, status, recipient}] */
    sent: { type: Array, default: () => [] },
    /** Symbol „noch nicht versendet“ zeigen, wenn es keinen Versand gibt */
    showUnsent: { type: Boolean, default: false },
})

const { t, locale } = useI18n()

function formatWhen(value) {
    const d = new Date(value)
    if (!value || isNaN(d.getTime())) return ''
    return d.toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// Zustellstatus gibt es bei WhatsApp/SMS und beim Postversand
function statusLabel(ch) {
    if (ch.channel === 'whatsapp' || ch.channel === 'sms') return t(waStatusMeta(ch.status).label)
    if (ch.channel === 'post') return t(postStatusMeta(ch.status).label)
    return ''
}

const channels = computed(() => (props.sent || [])
    .filter(ch => channelMeta[ch.channel])
    .map(ch => {
        const meta = channelMeta[ch.channel]
        const label = t(meta.label) + (ch.count > 1 ? ` (${ch.count}×)` : '')
        return {
            channel: ch.channel,
            meta,
            failed: ch.status === 'failed',
            title: [label, formatWhen(ch.sent_at), ch.recipient, statusLabel(ch)].filter(Boolean).join(' · '),
        }
    }))
</script>

<style scoped>
.occurrence-sent {
    display: inline-flex;
    align-items: center;
    gap: var(--oserp-gap-xs);
    vertical-align: middle;
}

.occurrence-sent__none {
    opacity: 0.35;
}
</style>
