<!-- src/core/views/developer-tools/components/sql-tool-log.component.vue -->
<!--
    Audit-Protokoll des SQL-Werkzeugs (Tabelle sql_tool_log)

    Jede Ausführung, Änderung, Löschung, Ablehnung und jeder Fehler mit
    Benutzer, IP und Statement — neueste zuerst. Nur lesbar, es gibt keinen
    Löschpfad. Steht Systemadministratoren auch offen, wenn das Werkzeug
    selbst abgeschaltet ist.
-->
<template>
    <section-card :title="t('DeveloperTools.sqlLog.title')" icon="mdi-shield-search">
        <template #actions>
            <v-btn
                size="small"
                variant="tonal"
                color="primary"
                prepend-icon="mdi-refresh"
                :loading="loading"
                @click="load"
            >
                {{ t('DeveloperTools.sqlLog.refresh') }}
            </v-btn>
        </template>

        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('DeveloperTools.sqlLog.intro') }}</p>

        <v-text-field
            v-model="search"
            :label="t('DeveloperTools.sqlLog.search')"
            prepend-inner-icon="mdi-magnify"
            density="compact"
            variant="outlined"
            hide-details
            clearable
            class="mb-3"
            style="max-width: 420px"
        />

        <v-data-table
            :headers="headers"
            :items="rows"
            :search="search"
            :items-per-page="25"
            density="compact"
            :no-data-text="t('DeveloperTools.sqlLog.empty')"
        >
            <template #item.success="{ item }">
                <v-icon :color="item.success ? 'success' : 'error'" size="small">
                    {{ item.success ? 'mdi-check-circle' : 'mdi-close-circle' }}
                </v-icon>
            </template>
            <template #item.action="{ item }">
                <v-chip size="x-small" :color="actionColor(item.action)" variant="tonal">
                    {{ t('DeveloperTools.sqlLog.actions.' + item.action, item.action) }}
                </v-chip>
            </template>
            <template #item.statement="{ item }">
                <div class="sql-log-statement" :title="item.statement">{{ item.statement }}</div>
                <div v-if="item.error" class="text-caption text-error">{{ item.error }}</div>
            </template>
        </v-data-table>
    </section-card>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import SectionCard from '@/core/components/layout/section-card.vue';

const { t } = useI18n();

const rows = ref([]);
const loading = ref(false);
const search = ref('');

const headers = computed(() => [
    { title: t('DeveloperTools.sqlLog.columns.time'), key: 'itime', width: 160 },
    { title: t('DeveloperTools.sqlLog.columns.user'), key: 'login', width: 120 },
    { title: t('DeveloperTools.sqlLog.columns.action'), key: 'action', width: 120 },
    { title: t('DeveloperTools.sqlLog.columns.database'), key: 'database_type', width: 100 },
    { title: t('DeveloperTools.sqlLog.columns.ok'), key: 'success', width: 60, align: 'center' },
    { title: t('DeveloperTools.sqlLog.columns.statement'), key: 'statement' },
    { title: t('DeveloperTools.sqlLog.columns.rows'), key: 'row_count', width: 80, align: 'end' },
    { title: t('DeveloperTools.sqlLog.columns.ms'), key: 'execution_ms', width: 90, align: 'end' },
    { title: t('DeveloperTools.sqlLog.columns.ip'), key: 'ip', width: 130 },
]);

/**
 * Farbe je Aktion — Ablehnungen und Löschungen fallen auf
 */
function actionColor(action) {
    if (action === 'denied') return 'error';
    if (action === 'deleteTableRow') return 'warning';
    if (action === 'updateTableRow') return 'info';
    return 'primary';
}

/**
 * Lädt die letzten Einträge (Backend: nur Systemadministratoren)
 */
async function load() {
    loading.value = true;
    try {
        const { data } = await axios.post('/api/developer-tools/', { action: 'getSqlToolLog', limit: 500 });
        rows.value = data?.success ? (data.payload?.log || []) : [];
    } catch (error) {
        console.error('getSqlToolLog:', error);
        rows.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.sql-log-statement {
    max-width: 0;
    width: 100%;
    min-width: 260px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: monospace;
    font-size: 0.8rem;
}
</style>
