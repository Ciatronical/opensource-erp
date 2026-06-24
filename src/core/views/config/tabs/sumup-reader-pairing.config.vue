<!-- src/core/views/config/tabs/sumup-reader-pairing.config.vue -->
<template>
    <v-card variant="outlined" class="pa-4" style="max-width: 560px">
        <div class="d-flex align-center mb-3">
            <v-icon size="32" color="primary" class="mr-3">mdi-credit-card-wireless</v-icon>
            <div>
                <div class="text-subtitle-1 font-weight-medium">
                    {{ t('crm_fields.sumupPairing.title') }}
                </div>
                <div class="text-caption text-grey">
                    {{ t('crm_fields.sumupPairing.description') }}
                </div>
            </div>
        </div>

        <!-- Aktuell gekoppeltes Terminal -->
        <v-alert
            v-if="info.reader_id"
            type="success"
            variant="tonal"
            density="compact"
            class="mb-3"
        >
            {{ t('crm_fields.sumupPairing.current', { name: info.reader_name || info.reader_id }) }}
            <span class="text-caption d-block">{{ info.reader_id }}</span>
        </v-alert>
        <v-alert
            v-else
            type="info"
            variant="tonal"
            density="compact"
            class="mb-3"
        >
            {{ t('crm_fields.sumupPairing.none') }}
        </v-alert>

        <!-- Hinweis: zuerst Zugangsdaten speichern -->
        <v-alert
            v-if="!info.has_api_key || !info.merchant_code"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-3"
        >
            {{ t('crm_fields.sumupPairing.saveFirst') }}
        </v-alert>

        <div class="text-caption text-grey mb-2">
            {{ t('crm_fields.sumupPairing.hint') }}
        </div>

        <v-text-field
            v-model="pairingCode"
            :label="t('crm_fields.sumupPairing.codeLabel')"
            variant="outlined"
            density="compact"
            hide-details="auto"
            class="mb-3"
            style="max-width: 30ch"
            @keyup.enter="pairReader"
        />

        <div class="d-flex gap-2">
            <v-btn
                color="primary"
                variant="flat"
                prepend-icon="mdi-link-variant"
                :loading="pairing"
                :disabled="!pairingCode || !info.has_api_key || !info.merchant_code"
                @click="pairReader"
            >
                {{ t('crm_fields.sumupPairing.pairBtn') }}
            </v-btn>
            <v-btn
                v-if="info.reader_id"
                variant="outlined"
                color="error"
                prepend-icon="mdi-link-variant-off"
                :loading="unpairing"
                @click="unpairReader"
            >
                {{ t('crm_fields.sumupPairing.unpairBtn') }}
            </v-btn>
        </div>

        <v-alert
            v-if="message.text"
            :type="message.type"
            variant="tonal"
            density="compact"
            class="mt-3"
        >
            {{ message.text }}
        </v-alert>
    </v-card>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const info = reactive({
    enabled: false,
    has_api_key: false,
    merchant_code: '',
    reader_id: '',
    reader_name: ''
});
const pairingCode = ref('');
const pairing = ref(false);
const unpairing = ref(false);
const message = reactive({ type: 'success', text: '' });

async function loadInfo() {
    try {
        const resp = await axios.post('/api/payment/', { action: 'getSumupReaderInfo' });
        if (resp.data.success) {
            Object.assign(info, resp.data.payload || {});
        }
    } catch {
        // Konfiguration (noch) nicht verfügbar
    }
}

async function pairReader() {
    if (!pairingCode.value) return;
    pairing.value = true;
    message.text = '';
    try {
        const resp = await axios.post('/api/payment/', {
            action: 'pairSumupReader',
            pairing_code: pairingCode.value,
            name: 'Terminal'
        });
        if (resp.data.success) {
            message.type = 'success';
            message.text = t('crm_fields.sumupPairing.success');
            pairingCode.value = '';
            await loadInfo();
        } else {
            message.type = 'error';
            message.text = resp.data.text || t('crm_fields.sumupPairing.error');
        }
    } catch (err) {
        message.type = 'error';
        message.text = err.response?.data?.text || t('crm_fields.sumupPairing.error');
    } finally {
        pairing.value = false;
    }
}

async function unpairReader() {
    unpairing.value = true;
    message.text = '';
    try {
        const resp = await axios.post('/api/payment/', { action: 'unpairSumupReader' });
        if (resp.data.success) {
            message.type = 'success';
            message.text = t('crm_fields.sumupPairing.unpaired');
            await loadInfo();
        }
    } catch (err) {
        message.type = 'error';
        message.text = err.response?.data?.text || t('crm_fields.sumupPairing.error');
    } finally {
        unpairing.value = false;
    }
}

onMounted(loadInfo);
</script>
