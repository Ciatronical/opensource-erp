<!-- src/core/views/config/tabs/ebay-status.config.vue -->
<template>
    <v-card variant="outlined" class="pa-4" style="max-width: 720px">
        <div class="d-flex align-center mb-3">
            <v-icon size="32" color="primary" class="mr-3">mdi-shopping</v-icon>
            <div>
                <div class="text-subtitle-1 font-weight-medium">
                    {{ t('crm_fields.ebayPanelUi.title') }}
                </div>
                <div class="text-caption text-grey">
                    {{ t('crm_fields.ebayPanelUi.description') }}
                </div>
            </div>
        </div>

        <v-alert
            v-if="!status.enabled"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-3"
        >
            {{ t('crm_fields.ebayPanelUi.disabled') }}
        </v-alert>

        <v-alert
            v-else
            type="success"
            variant="tonal"
            density="compact"
            class="mb-3"
        >
            {{ t('crm_fields.ebayPanelUi.lastCheck', { date: status.lastCheck || '—' }) }}
            <span class="text-caption d-block">
                {{ t('crm_fields.ebayPanelUi.counts', {
                    total: status.counts?.total ?? 0,
                    posted: status.counts?.posted ?? 0,
                    unposted: status.counts?.unposted ?? 0
                }) }}
            </span>
        </v-alert>

        <div class="d-flex gap-2 mb-3">
            <v-btn
                color="primary"
                variant="flat"
                prepend-icon="mdi-cloud-sync"
                :loading="syncing"
                @click="syncOrders"
            >
                {{ t('crm_fields.ebayPanelUi.syncBtn') }}
            </v-btn>
            <v-btn
                variant="outlined"
                prepend-icon="mdi-connection"
                :loading="testing"
                @click="testConnection"
            >
                {{ t('crm_fields.ebayPanelUi.testBtn') }}
            </v-btn>
            <v-btn
                variant="text"
                prepend-icon="mdi-refresh"
                :loading="loading"
                @click="loadStatus"
            >
                {{ t('crm_fields.ebayPanelUi.refreshBtn') }}
            </v-btn>
        </div>

        <v-alert
            v-if="message.text"
            :type="message.type"
            variant="tonal"
            density="compact"
            class="mb-3"
        >
            {{ message.text }}
        </v-alert>

        <!-- Zuletzt importierte Bestellungen -->
        <v-table v-if="status.recent && status.recent.length" density="compact">
            <thead>
                <tr>
                    <th>{{ t('crm_fields.ebayPanelUi.colOrder') }}</th>
                    <th>{{ t('crm_fields.ebayPanelUi.colCustomer') }}</th>
                    <th>{{ t('crm_fields.ebayPanelUi.colInvoice') }}</th>
                    <th class="text-right">{{ t('crm_fields.ebayPanelUi.colTotal') }}</th>
                    <th>{{ t('crm_fields.ebayPanelUi.colStatus') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in status.recent" :key="row.ebay_order_id">
                    <td class="text-caption">{{ row.ebay_order_id }}</td>
                    <td>{{ row.customer_name || row.buyer_username }}</td>
                    <td>{{ row.invnumber || '—' }}</td>
                    <td class="text-right">{{ formatMoney(row.total) }}</td>
                    <td>
                        <v-chip
                            size="x-small"
                            :color="row.posting_reason === 'posted' ? 'success' : 'warning'"
                            variant="tonal"
                        >
                            {{ row.posting_reason }}
                        </v-chip>
                    </td>
                </tr>
            </tbody>
        </v-table>
    </v-card>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const status = reactive({ enabled: false, lastCheck: null, counts: {}, recent: [] });
const syncing = ref(false);
const testing = ref(false);
const loading = ref(false);
const message = reactive({ type: 'success', text: '' });

function formatMoney(v) {
    const n = Number(v || 0);
    return n.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
}

async function loadStatus() {
    loading.value = true;
    try {
        const resp = await axios.post('/api/ebay/', { action: 'ebayGetStatus' });
        if (resp.data.success) {
            Object.assign(status, resp.data.payload || {});
        }
    } catch {
        // Konfiguration (noch) nicht verfügbar
    } finally {
        loading.value = false;
    }
}

async function testConnection() {
    testing.value = true;
    message.text = '';
    try {
        const resp = await axios.post('/api/ebay/', { action: 'ebayTestConnection' });
        if (resp.data.success) {
            message.type = 'success';
            message.text = t('crm_fields.ebayPanelUi.testOk', { count: resp.data.payload?.orderTotal ?? 0 });
        } else {
            message.type = 'error';
            message.text = resp.data.text || t('crm_fields.ebayPanelUi.testFail');
        }
    } catch (err) {
        message.type = 'error';
        message.text = err.response?.data?.text || t('crm_fields.ebayPanelUi.testFail');
    } finally {
        testing.value = false;
    }
}

async function syncOrders() {
    syncing.value = true;
    message.text = '';
    try {
        const resp = await axios.post('/api/ebay/', { action: 'ebaySyncOrders' });
        if (resp.data.success) {
            const p = resp.data.payload || {};
            message.type = (p.errors && p.errors.length) ? 'warning' : 'success';
            message.text = t('crm_fields.ebayPanelUi.syncOk', {
                imported: p.imported ?? 0,
                skipped: p.skipped ?? 0
            }) + ((p.errors && p.errors.length) ? (' — ' + p.errors.join('; ')) : '');
            await loadStatus();
        } else {
            message.type = 'error';
            message.text = resp.data.text || t('crm_fields.ebayPanelUi.syncFail');
        }
    } catch (err) {
        message.type = 'error';
        message.text = err.response?.data?.text || t('crm_fields.ebayPanelUi.syncFail');
    } finally {
        syncing.value = false;
    }
}

onMounted(loadStatus);
</script>
