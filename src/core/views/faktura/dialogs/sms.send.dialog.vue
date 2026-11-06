<!-- src/core/views/faktura/dialogs/sms.send.dialog.vue -->
<!--
    Beleg per SMS: Text frei änderbar, das PDF geht als Link mit ({link} markiert
    die Stelle). Zähler mit SMS-Teilen und Kosten; typografische Zeichen, die eine
    SMS verteuern, lassen sich mit einem Klick ersetzen. Der Empfänger öffnet den
    Beleg im Browser; der Chat zeigt später, ob der Link geöffnet wurde.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="640" persistent @update:model-value="emit('update:modelValue', $event)">
        <section-card :title="t('FakturaView.dialogs.sendSms.title')" icon="mdi-message-text-outline">
            <template #actions>
                <v-btn icon="mdi-close" size="small" variant="text" @click="emit('update:modelValue', false)" />
            </template>

            <!-- Noch nicht eingerichtet: Knopf trotzdem anbieten und den Weg zur Einrichtung zeigen -->
            <v-alert v-if="configLoaded && !smsConfig.configured" type="info" variant="tonal" class="mb-3">
                {{ t('SmsView.notConfigured') }}
                <template #append>
                    <v-btn variant="tonal" size="small" @click="openConfig">{{ t('SmsChat.openConfig') }}</v-btn>
                </template>
            </v-alert>

            <v-row density="compact">
                <v-col cols="12">
                    <v-combobox
                        v-model="phone"
                        :items="phoneOptions"
                        item-title="title"
                        item-value="value"
                        :return-object="false"
                        :label="t('FakturaView.dialogs.sendSms.phone')"
                        prepend-inner-icon="mdi-cellphone"
                        :hint="selectedIsLandline ? t('FakturaView.dialogs.sendSms.landlineHint') : ''"
                        persistent-hint
                    />
                </v-col>
                <v-col cols="12">
                    <v-textarea
                        v-model="text"
                        :label="t('FakturaView.dialogs.sendSms.text')"
                        rows="5"
                        auto-grow
                        autofocus
                    />
                    <div class="sms-dialog__counter">
                        <span :class="{ 'text-warning': info.encoding === 'ucs2' }">
                            {{ t('SmsChat.counter', { length: info.length, parts: info.parts, left: info.left }) }}
                        </span>
                        <span v-if="smsConfig.cost > 0 && info.parts"> · {{ t('SmsChat.cost', { cost: formatMoney(info.parts * smsConfig.cost) }) }}</span>
                        <v-btn v-if="canReplaceSpecials" size="x-small" variant="tonal" color="warning" class="ms-2" @click="text = replaceSmsSpecials(text)">
                            {{ t('SmsChat.replaceSpecials') }}
                        </v-btn>
                        <v-btn v-if="!text.includes('{link}')" size="x-small" variant="tonal" class="ms-2" prepend-icon="mdi-link-variant" @click="text = text.trimEnd() + ' {link}'">
                            {{ t('FakturaView.dialogs.sendSms.insertLink') }}
                        </v-btn>
                        <div v-if="smsConfig.reply_hint" :title="t('SmsChat.replyHintTip')">
                            <v-icon size="12" class="me-1">mdi-reply-outline</v-icon>{{ t('SmsChat.replyHint', { hint: smsConfig.reply_hint }) }}
                        </div>
                    </div>
                </v-col>
                <v-col cols="12">
                    <v-chip color="primary" variant="tonal" prepend-icon="mdi-paperclip">{{ attachmentName }}</v-chip>
                    <span class="sms-dialog__hint">{{ t('FakturaView.dialogs.sendSms.linkHint', { days: smsConfig.link_days || 90 }) }}</span>
                </v-col>
            </v-row>

            <div class="d-flex justify-end ga-2 mt-4">
                <v-btn variant="text" @click="emit('update:modelValue', false)">{{ t('FakturaView.dialogs.sendSms.cancel') }}</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-send"
                    :loading="loading"
                    :disabled="!smsConfig.configured || !phone || !text.trim()"
                    @click="emit('send', { phone, text })"
                >
                    {{ t('FakturaView.dialogs.sendSms.send') }}
                </v-btn>
            </div>
        </section-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import SectionCard from '@/core/components/layout/section-card.vue'
import { useSmsConfig } from '@/core/composables/useSmsConfig.js'
import { smsInfo, replaceSmsSpecials, withSampleLink, withReplyHint } from '@/core/utils/smsText.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** [{ title, value, mobile }] – Mobilnummern zuerst */
    phoneOptions: { type: Array, default: () => [] },
    defaultPhone: { type: String, default: '' },
    defaultText: { type: String, default: '' },
    attachmentName: { type: String, default: '' },
    loading: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'send'])

const { t, locale } = useI18n()
const { smsConfig, loadSmsConfig } = useSmsConfig()

const router = useRouter()
const configLoaded = ref(false)
const phone = ref('')
const text = ref('')

// Beim Öffnen frisch vorbelegen
watch(() => props.modelValue, (open) => {
    if (!open) return
    loadSmsConfig(true).finally(() => { configLoaded.value = true })
    phone.value = props.defaultPhone
    text.value = props.defaultText
}, { immediate: true })

const selectedIsLandline = computed(() => {
    const opt = props.phoneOptions.find(o => o.value === phone.value)
    return opt ? !opt.mobile : false
})

const sampleLink = computed(() => (smsConfig.value.public_url || window.location.origin) + '/webhook/beleg.php?k=xxxxxxxxxx.xxxxxxxxxxxxxxxx')
const info = computed(() => smsInfo(withReplyHint(withSampleLink(text.value.includes('{link}') ? text.value : text.value + '\n{link}', sampleLink.value), smsConfig.value.reply_hint)))
const canReplaceSpecials = computed(() => replaceSmsSpecials(text.value) !== text.value)

function openConfig() {
    emit('update:modelValue', false)
    router.push({ name: 'client-defaults', query: { tab: 'crm', focus: 'sms_api_key' } })
}

function formatMoney(value) {
    return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR', maximumFractionDigits: 3 }).format(Number(value || 0))
}
</script>

<style scoped>
.sms-dialog__counter {
    margin-top: var(--oserp-gap-xs);
    font-size: 12px;
    color: var(--oserp-text-muted);
}
.sms-dialog__hint {
    font-size: 12px;
    color: var(--oserp-text-muted);
    margin-left: var(--oserp-gap-sm);
}
</style>
