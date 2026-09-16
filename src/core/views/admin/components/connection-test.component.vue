<!-- src/core/views/admin/components/connection-test.component.vue -->
<!-- "Verbindung prüfen" für Firmen-Zugangsdaten mit Ergebnisanzeige -->
<template>
    <div>
        <v-btn size="small" variant="tonal" prepend-icon="mdi-connection" :loading="testing" :disabled="!form.dbhost || !form.dbname || !form.dbuser" @click="test">
            {{ t('AdminView.companyDialog.testConnection') }}
        </v-btn>
        <v-alert v-if="result" :type="result.type" variant="tonal" density="compact" class="mt-2 text-body-2" closable @click:close="result = null">
            {{ result.text }}
        </v-alert>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t } = useI18n();
const store = oserpStore();

const props = defineProps({
    form: { type: Object, required: true },
    companyId: { type: Number, default: null }
});

const testing = ref(false);
const result = ref(null);

async function test() {
    testing.value = true;
    result.value = null;
    try {
        const info = await store.adminTestClientConnection({
            id: props.companyId, dbhost: props.form.dbhost, dbport: Number(props.form.dbport), dbname: props.form.dbname, dbuser: props.form.dbuser, dbpasswd: props.form.dbpasswd
        });
        if (!info.is_company) {
            result.value = { type: 'warning', text: t('AdminView.companies.errors.NOT_A_COMPANY_DATABASE') };
        } else if (!info.has_oserp) {
            result.value = { type: 'warning', text: t('AdminView.companies.testOkNoOserp') };
        } else {
            result.value = { type: 'success', text: t('AdminView.companies.testOk', { company: info.company || props.form.dbname, coa: info.coa || '?' }) };
        }
    } catch (e) {
        result.value = { type: 'error', text: t('AdminView.companies.testFail', { error: e.message || e.code }) };
    } finally {
        testing.value = false;
    }
}
</script>
