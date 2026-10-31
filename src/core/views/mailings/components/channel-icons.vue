<!-- src/core/views/mailings/components/channel-icons.vue -->
<!--
    Kleine Symbole je Empfänger: Telegram, WhatsApp, E-Mail, bei eingerichtetem
    Postdienst auch Brief — kräftig,
    wenn der Weg offen steht, blass, wenn nicht. WhatsApp unterscheidet das
    kostenlose offene Fenster (grün) von „nur per Vorlage“ (orange).
-->
<template>
    <div class="channel-icons" :class="{ 'channel-icons--detailed': detailed }">
        <v-icon size="small" :color="a.telegram ? CHANNEL_META.telegram.color : undefined" :class="{ 'channel-icons__off': !a.telegram }" :title="telegramTitle">
            {{ CHANNEL_META.telegram.icon }}
        </v-icon>
        <v-icon size="small" :color="whatsappColor" :class="{ 'channel-icons__off': !recipient.whatsapp_phone }" :title="whatsappTitle">
            {{ CHANNEL_META.whatsapp.icon }}
        </v-icon>
        <v-icon size="small" :color="a.email ? CHANNEL_META.email.color : undefined" :class="{ 'channel-icons__off': !a.email }" :title="emailTitle">
            {{ CHANNEL_META.email.icon }}
        </v-icon>
        <v-icon v-if="config.letter" size="small" :color="a.letter ? CHANNEL_META.letter.color : undefined" :class="{ 'channel-icons__off': !a.letter }" :title="letterTitle">
            {{ CHANNEL_META.letter.icon }}
        </v-icon>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { CHANNEL_META, availability } from '../composables/mailingPlan.js'

const props = defineProps({
    recipient: { type: Object, required: true },
    settings: { type: Object, default: () => ({}) },
    config: { type: Object, default: () => ({}) },
    detailed: { type: Boolean, default: false },
})
const { t } = useI18n()

const a = computed(() => availability(props.recipient, props.settings, props.config))
const whatsappColor = computed(() => {
    if (!props.recipient.whatsapp_phone) return undefined
    if (props.recipient.whatsapp_window) return CHANNEL_META.whatsapp.color
    return CHANNEL_META.whatsapp_template.color
})
const telegramTitle = computed(() => props.recipient.telegram_chat_id
    ? (props.config.telegram ? t('MailingRecipients.hints.telegramOk') : t('MailingRecipients.hints.telegramOff'))
    : t('MailingRecipients.hints.telegramNone'))
const whatsappTitle = computed(() => {
    const r = props.recipient
    if (!r.whatsapp_phone) return t('MailingRecipients.hints.whatsappNone')
    if (!props.config.whatsapp) return t('MailingRecipients.hints.whatsappOff')
    if (r.whatsapp_window) return t('MailingRecipients.hints.whatsappWindow', { phone: r.whatsapp_phone })
    return r.whatsapp_known
        ? t('MailingRecipients.hints.whatsappKnown', { phone: r.whatsapp_phone })
        : t('MailingRecipients.hints.whatsappTemplateOnly', { phone: r.whatsapp_phone })
})
const letterTitle = computed(() => props.recipient.address_ok
    ? t('MailingRecipients.hints.letterOk', { address: props.recipient.address })
    : t('MailingRecipients.hints.letterNone'))
const emailTitle = computed(() => props.recipient.email
    ? (props.config.email ? props.recipient.email : t('MailingRecipients.hints.emailOff'))
    : t('MailingRecipients.hints.emailNone'))
</script>

<style scoped>
.channel-icons {
    display: inline-flex;
    gap: var(--oserp-gap-xs);
}
.channel-icons__off {
    opacity: 0.22;
}
</style>
