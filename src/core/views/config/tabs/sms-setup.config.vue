<!-- src/core/views/config/tabs/sms-setup.config.vue -->
<!--
    Firmenkonfiguration → CRM → SMS: Stand des SMS-Versands bei seven.io
    (Guthaben, Verbrauch dieses Monats) und Einrichtung des Eingangs – eingehende
    SMS und Zustellberichte kommen über einen Webhook, den dieser Knopf bei
    seven.io anmeldet (registerSmsWebhook).
-->
<template>
    <section-card :title="t('crm_fields.smsSetup.title')" icon="mdi-message-text-outline" style="max-width: 720px">
        <template #actions>
            <v-btn icon="mdi-refresh" size="small" variant="text" :loading="loading" :title="t('crm_fields.smsSetup.refresh')" @click="load" />
        </template>

        <v-alert v-if="!setup.configured" type="info" variant="tonal" density="compact">
            {{ t('crm_fields.smsSetup.saveKeyFirst') }}
        </v-alert>

        <template v-else>
            <v-alert v-if="setup.error" type="error" variant="tonal" density="compact" class="mb-3">
                {{ setup.error }}
            </v-alert>

            <div class="d-flex flex-wrap ga-2 mb-3">
                <v-chip v-if="setup.balance" color="success" variant="tonal" prepend-icon="mdi-wallet-outline">
                    {{ t('crm_fields.smsSetup.balance', { amount: formatMoney(setup.balance.amount) }) }}
                </v-chip>
                <v-chip variant="tonal" prepend-icon="mdi-send-outline">
                    {{ t('crm_fields.smsSetup.sentMonth', { n: setup.stats?.sent_month || 0, cost: formatMoney(setup.stats?.cost_month || 0) }) }}
                </v-chip>
                <v-chip variant="tonal" prepend-icon="mdi-message-alert-outline" color="warning">
                    {{ t('crm_fields.smsSetup.fallbackMonth', { n: setup.stats?.fallback_month || 0 }) }}
                </v-chip>
                <v-chip variant="tonal" prepend-icon="mdi-message-arrow-left-outline">
                    {{ t('crm_fields.smsSetup.receivedMonth', { n: setup.stats?.received_month || 0 }) }}
                </v-chip>
            </div>

            <v-alert :type="setup.webhook ? 'success' : 'warning'" variant="tonal" density="compact" class="mb-3">
                {{ setup.webhook ? t('crm_fields.smsSetup.webhookActive') : t('crm_fields.smsSetup.webhookMissing') }}
            </v-alert>

            <div class="d-flex align-center flex-wrap ga-2">
                <v-text-field
                    v-model="publicUrl"
                    :label="t('crm_fields.smsSetup.publicUrl')"
                    :placeholder="'https://' + host"
                    prepend-inner-icon="mdi-web"
                    style="max-width: 46ch"
                    hide-details
                />
                <v-btn color="primary" variant="tonal" prepend-icon="mdi-webhook" :loading="registering" @click="register">
                    {{ setup.webhook ? t('crm_fields.smsSetup.reRegister') : t('crm_fields.smsSetup.register') }}
                </v-btn>
            </div>
            <div class="sms-setup__hint">{{ t('crm_fields.smsSetup.inboundHint') }}</div>
        </template>
    </section-card>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import SectionCard from '@/core/components/layout/section-card.vue'
import { useSmsConfig } from '@/core/composables/useSmsConfig.js'
import * as toast from '@/core/utils/toasts.js'

const { t, locale } = useI18n()
const { loadSmsConfig } = useSmsConfig()

const setup = ref({ configured: false })
const loading = ref(false)
const registering = ref(false)
const publicUrl = ref('')
const host = window.location.host

async function load() {
    loading.value = true
    try {
        const resp = await axios.post('/api/sms/', { action: 'getSmsSetup' })
        if (resp.data.success) {
            setup.value = resp.data.payload
            // Schlüssel eben gespeichert? Telefonknöpfe und Faktura sollen SMS sofort anbieten
            if (setup.value.configured) loadSmsConfig(true)
            if (!publicUrl.value) publicUrl.value = setup.value.public_url || ''
        }
    } finally {
        loading.value = false
    }
}

async function register() {
    registering.value = true
    try {
        const resp = await axios.post('/api/sms/', { action: 'registerSmsWebhook', public_url: publicUrl.value })
        if (resp.data.success) {
            toast.success(t('crm_fields.smsSetup.registered'))
            publicUrl.value = resp.data.payload.public_url
            await load()
            loadSmsConfig(true)
        } else {
            toast.error(typeof resp.data.payload === 'string' ? resp.data.payload : t('crm_fields.smsSetup.registerFailed'))
        }
    } catch {
        toast.error(t('crm_fields.smsSetup.registerFailed'))
    } finally {
        registering.value = false
    }
}

function formatMoney(value) {
    return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(Number(value || 0))
}

onMounted(load)
</script>

<style scoped>
.sms-setup__hint {
    margin-top: var(--oserp-gap-sm);
    font-size: 12px;
    color: var(--oserp-text-muted);
}
</style>
