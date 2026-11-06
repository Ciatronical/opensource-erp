<!-- src/core/views/customer-vendor/tabs/sms.tab.vue -->
<!--
    Reiter SMS in der Kontaktkarte: Verlauf über alle Nummern des Kunden bzw.
    Lieferanten (und seine Zuordnung), neue SMS an die gewählte Nummer.
    Mobilnummern stehen vorn – Festnetz empfängt keine SMS.
-->
<template>
    <div class="sms-tab">
        <v-alert v-if="!phoneNumbers.length" type="info" variant="tonal" density="compact" class="ma-3" icon="mdi-phone-off">
            {{ t('SmsChat.noPhone') }}
        </v-alert>
        <div v-else class="sms-tab__bar">
            <v-select
                v-model="target"
                :items="phoneItems"
                :label="t('SmsChat.sendTo')"
                prepend-inner-icon="mdi-cellphone"
                variant="outlined"
                density="compact"
                hide-details
                class="sms-tab__select"
            />
        </div>
        <sms-chat
            :phone="target ? normalizedTarget : ''"
            :phones="normalizedPhones"
            :customer-id="cvId"
            :src="src"
            height="420px"
        />
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SmsChat from '@/core/components/sms/sms-chat.vue'
import { useSmsConfig } from '@/core/composables/useSmsConfig.js'
import { formatPhone } from '@/core/utils/phoneFormat.js'

const props = defineProps({
    cvId: { type: [Number, String], required: true },
    src: { type: String, default: 'C' },
    phoneNumbers: { type: Array, default: () => [] },
    /** Vorgewählte Nummer (Telefonknopf „SMS“ an einer Nummer) */
    preselect: { type: String, default: '' },
})

const { t } = useI18n()
const { smsConfig } = useSmsConfig()

const MOBILE_RE = /^(\+491[5-7]|00491[5-7]|01[5-7]|\+436|06[^4]|\+417|07[^2])/
const isMobile = (n) => MOBILE_RE.test(String(n).replace(/[\s\-/().]/g, ''))

const phoneItems = computed(() => [...props.phoneNumbers]
    .sort((a, b) => Number(isMobile(b)) - Number(isMobile(a)))
    .map(n => ({ title: formatPhone(n) + (isMobile(n) ? '' : ' · ' + t('SmsChat.landline')), value: n })))

const target = ref('')
watch([phoneItems, () => props.preselect], () => {
    if (props.preselect && props.phoneNumbers.includes(props.preselect)) target.value = props.preselect
    else if (!props.phoneNumbers.includes(target.value)) target.value = phoneItems.value[0]?.value || ''
}, { immediate: true })

/** wie smsNormalizePhone im Backend */
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
const normalizedTarget = computed(() => normalize(target.value))
const normalizedPhones = computed(() => props.phoneNumbers.map(normalize).filter(Boolean))
</script>

<style scoped>
.sms-tab__bar {
    padding: var(--oserp-gap-sm) var(--oserp-gap);
    border-bottom: 1px solid var(--oserp-border-soft);
}
.sms-tab__select {
    max-width: 360px;
}
</style>
