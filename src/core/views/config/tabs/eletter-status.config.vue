<!-- src/core/views/config/tabs/eletter-status.config.vue -->
<!--
    Briefversand: Verbindung zum eingerichteten Briefdienstleister prüfen —
    LetterXpress meldet Anmeldung und Guthaben, PIN AG (SFTP/FTPS) Anmeldung
    und Erreichbarkeit des Einlieferungsordners.
-->
<template>
    <v-card variant="outlined" class="pa-4" style="max-width: 560px">
        <div class="d-flex align-center mb-3">
            <v-icon size="32" color="deep-purple" class="mr-3">mdi-mailbox-up-outline</v-icon>
            <div>
                <div class="text-subtitle-1 font-weight-medium">{{ t('crm_fields.eletterStatus.title') }}</div>
                <div class="text-caption text-grey">{{ t('crm_fields.eletterStatus.description') }}</div>
            </div>
        </div>

        <div class="text-caption text-grey mb-3">{{ t('crm_fields.eletterStatus.saveFirst') }}</div>

        <v-btn color="deep-purple" variant="flat" prepend-icon="mdi-lan-connect" :loading="testing" @click="test">
            {{ t('crm_fields.eletterStatus.testBtn') }}
        </v-btn>

        <v-alert v-if="result" :type="result.ok ? 'success' : 'error'" variant="tonal" density="compact" class="mt-3">
            <div class="font-weight-medium">{{ result.ok ? t('crm_fields.eletterStatus.ok') : t('crm_fields.eletterStatus.failed') }}</div>
            <div class="text-body-2">{{ result.message }}</div>
            <div v-if="result.balance !== null && result.balance !== undefined" class="text-body-2">
                {{ t('crm_fields.eletterStatus.balance', { amount: formatMoney(result.balance, result.currency) }) }}
            </div>
            <div v-if="result.mode" class="text-body-2">{{ t('crm_fields.eletterStatus.mode', { mode: result.mode }) }}</div>
            <div v-if="result.files !== null && result.files !== undefined" class="text-body-2">
                {{ t('crm_fields.eletterStatus.files', { n: result.files }, result.files) }}
            </div>
        </v-alert>

        <div class="text-caption text-grey mt-3">
            {{ provider === 'letterxpress' ? t('crm_fields.eletterStatus.hintLetterxpress') : t('crm_fields.eletterStatus.hintPinag') }}
        </div>
    </v-card>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t, locale } = useI18n();
const oserp = oserpStore();
const testing = ref(false);
const result = ref(null);
const provider = computed(() => oserp.getClientDefaultValue('eletter_provider', ''));

function formatMoney(v, currency) {
    return Number(v || 0).toLocaleString(locale.value, { style: 'currency', currency: currency || 'EUR' });
}

async function test() {
    testing.value = true;
    result.value = null;
    try {
        const resp = await axios.post('/api/letters/', { action: 'testPostalConnection' });
        result.value = resp.data.success ? resp.data.payload.results : { ok: false, message: resp.data.text };
    } catch (e) {
        result.value = { ok: false, message: e.message };
    } finally {
        testing.value = false;
    }
}
</script>
