<!-- src/core/views/system/components/schema-update.component.vue -->
<template>
    <v-card flat>
        <v-card-title class="text-h6 bg-blue-grey-lighten-5">
            <v-icon start>mdi-update</v-icon>
            {{ t('DeveloperTools.schemaUpdate.title') }}
        </v-card-title>

        <v-card-text class="pa-4">
            <p class="text-body-2 text-grey-darken-1 mb-4">
                {{ t('DeveloperTools.schemaUpdate.description') }}
            </p>

            <!-- Datenbank-Namen -->
            <template v-if="dbNames">
                <v-chip size="large" variant="outlined" color="primary" class="mr-2 my-4">
                    <v-icon start size="x-small">mdi-database</v-icon>
                    {{ t('DeveloperTools.schemaUpdate.auth_label', { name: dbNames.auth_db }) }}
                </v-chip>
                <v-chip size="large" variant="outlined" color="primary" class="my-4">
                    <v-icon start size="x-small">mdi-database</v-icon>
                    {{ t('DeveloperTools.schemaUpdate.company_label', { name: dbNames.company_db }) }}
                </v-chip>
            </template>

            <!-- Optionen -->
            <v-checkbox
                v-model="updateAuthDb"
                :label="t('DeveloperTools.schemaUpdate.update_auth_db') + (dbNames ? ` (${dbNames.auth_db})` : '')"
                density="compact"
                hide-details
            />
            <v-checkbox
                class="mb-4"
                v-model="updateCompanyDb"
                :label="t('DeveloperTools.schemaUpdate.update_company_db') + (dbNames ? ` (${dbNames.company_db})` : '')"
                density="compact"
                hide-details
            />

            <!-- Buttons -->
            <v-row>
                <v-col cols="12" md="6">
                    <v-btn
                        color="info"
                        variant="outlined"
                        block
                        :loading="dryRunLoading"
                        :disabled="updateLoading"
                        @click="runDryRun"
                    >
                        <v-icon start>mdi-eye</v-icon>
                        {{ t('DeveloperTools.schemaUpdate.dry_run_button') }}
                    </v-btn>
                </v-col>
                <v-col cols="12" md="6">
                    <v-btn
                        color="warning"
                        variant="flat"
                        block
                        :loading="updateLoading"
                        :disabled="dryRunLoading"
                        @click="confirmUpdate"
                    >
                        <v-icon start>mdi-database-sync</v-icon>
                        {{ t('DeveloperTools.schemaUpdate.update_button') }}
                    </v-btn>
                </v-col>
            </v-row>

            <!-- Status Message -->
            <v-alert
                v-if="statusMessage"
                :type="statusType"
                variant="tonal"
                closable
                class="mt-4"
                @click:close="statusMessage = ''"
            >
                {{ statusMessage }}
            </v-alert>

            <!-- Ergebnisse -->
            <div v-if="updateResult" class="mt-4">
                <v-expansion-panels v-model="expandedPanel">
                    <!-- Meldungen -->
                    <v-expansion-panel v-if="updateResult.messages && updateResult.messages.length > 0">
                        <v-expansion-panel-title>
                            <v-icon class="mr-2" color="info">mdi-information</v-icon>
                            {{ t('DeveloperTools.schemaUpdate.results.messages', { count: updateResult.messages.length }) }}
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <v-list density="compact">
                                <v-list-item
                                    v-for="(msg, index) in updateResult.messages"
                                    :key="index"
                                >
                                    <template v-slot:prepend>
                                        <v-icon
                                            :color="isChangeMessage(msg) ? 'warning' : 'success'"
                                            size="small"
                                            class="mr-2"
                                        >
                                            {{ isChangeMessage(msg) ? 'mdi-alert-circle' : 'mdi-check-circle' }}
                                        </v-icon>
                                    </template>
                                    <v-list-item-title>
                                        {{ formatMessage(msg) }}
                                    </v-list-item-title>
                                </v-list-item>
                            </v-list>
                        </v-expansion-panel-text>
                    </v-expansion-panel>

                    <!-- SQL Statements (Dry-Run) -->
                    <v-expansion-panel v-if="updateResult.sql_statements && updateResult.sql_statements.length > 0">
                        <v-expansion-panel-title>
                            <v-icon class="mr-2" color="primary">mdi-database-edit</v-icon>
                            {{ t('DeveloperTools.schemaUpdate.results.sql_statements', { count: updateResult.sql_statements.length }) }}
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <v-list density="compact">
                                <v-list-item
                                    v-for="(stmt, index) in updateResult.sql_statements"
                                    :key="index"
                                >
                                    <v-list-item-title class="font-weight-bold">
                                        {{ stmt.type }}: {{ stmt.table || stmt.target }}
                                    </v-list-item-title>
                                    <v-list-item-subtitle class="text-caption font-monospace">
                                        {{ stmt.sql }}
                                    </v-list-item-subtitle>
                                </v-list-item>
                            </v-list>
                        </v-expansion-panel-text>
                    </v-expansion-panel>

                    <!-- Fehler -->
                    <v-expansion-panel v-if="updateResult.errors && updateResult.errors.length > 0">
                        <v-expansion-panel-title>
                            <v-icon class="mr-2" color="error">mdi-alert-circle</v-icon>
                            {{ t('DeveloperTools.schemaUpdate.results.errors', { count: updateResult.errors.length }) }}
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <v-list density="compact">
                                <v-list-item
                                    v-for="(err, index) in updateResult.errors"
                                    :key="index"
                                    class="text-error"
                                >
                                    <v-list-item-title class="error-message">
                                        {{ err }}
                                    </v-list-item-title>
                                </v-list-item>
                            </v-list>
                        </v-expansion-panel-text>
                    </v-expansion-panel>

                    <!-- Verarbeitete Features -->
                    <v-expansion-panel v-if="updateResult.processed_features">
                        <v-expansion-panel-title>
                            <v-icon class="mr-2" color="secondary">mdi-puzzle</v-icon>
                            {{ t('DeveloperTools.schemaUpdate.results.processed_features') }}
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <v-chip
                                v-for="feature in updateResult.processed_features"
                                :key="feature"
                                class="ma-1"
                                size="small"
                            >
                                {{ feature }}
                            </v-chip>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </div>
        </v-card-text>

        <!-- Bestätigungs-Dialog -->
        <v-dialog v-model="showConfirmDialog" max-width="500">
            <v-card>
                <v-card-title class="bg-warning text-white">
                    <v-icon start>mdi-alert</v-icon>
                    {{ t('DeveloperTools.schemaUpdate.confirm_dialog.title') }}
                </v-card-title>
                <v-card-text class="pa-4">
                    <p>{{ t('DeveloperTools.schemaUpdate.confirm_dialog.text') }}</p>
                </v-card-text>
                <v-card-actions>
                    <v-btn @click="showConfirmDialog = false">{{ t('cancel') }}</v-btn>
                    <v-spacer />
                    <v-btn
                        color="warning"
                        variant="flat"
                        :loading="updateLoading"
                        @click="runUpdate"
                    >
                        <v-icon start>mdi-check</v-icon>
                        {{ t('DeveloperTools.schemaUpdate.confirm_dialog.confirm_button') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-card>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const updateAuthDb = ref(true);
const updateCompanyDb = ref(true);
const dryRunLoading = ref(false);
const updateLoading = ref(false);
const updateResult = ref(null);
const statusMessage = ref('');
const statusType = ref('success');
const expandedPanel = ref(null);
const showConfirmDialog = ref(false);
const dbNames = ref(null);

onMounted(() => {
    loadDatabaseNames();
});

function buildRequestBody(dryRun) {
    return {
        action: 'updateSchema',
        dry_run: dryRun,
        auth_db: updateAuthDb.value,
        company_db: updateCompanyDb.value
    };
}

async function runDryRun() {
    dryRunLoading.value = true;
    statusMessage.value = '';
    updateResult.value = null;

    try {
        const response = await fetch('/api/update/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(buildRequestBody(true))
        });

        const data = await response.json();

        if (data.success) {
            updateResult.value = data.payload;
            statusMessage.value = t('DeveloperTools.schemaUpdate.dry_run_success');
            statusType.value = 'success';
            expandedPanel.value = 0;
        } else {
            updateResult.value = data.payload;
            statusMessage.value = data.text || t('DeveloperTools.schemaUpdate.dry_run_failed');
            statusType.value = 'error';
        }
    } catch (error) {
        console.error('Dry-Run Fehler:', error);
        statusMessage.value = t('DeveloperTools.schemaUpdate.network_error', { message: error.message });
        statusType.value = 'error';
    } finally {
        dryRunLoading.value = false;
    }
}

function confirmUpdate() {
    showConfirmDialog.value = true;
}

async function runUpdate() {
    showConfirmDialog.value = false;
    updateLoading.value = true;
    statusMessage.value = '';
    updateResult.value = null;

    try {
        const response = await fetch('/api/update/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(buildRequestBody(false))
        });

        const data = await response.json();

        if (data.success) {
            updateResult.value = data.payload;
            statusMessage.value = t('DeveloperTools.schemaUpdate.update_success');
            statusType.value = 'success';
            expandedPanel.value = 0;
        } else {
            updateResult.value = data.payload;
            statusMessage.value = data.text || t('DeveloperTools.schemaUpdate.update_failed');
            statusType.value = 'error';
        }
    } catch (error) {
        console.error('Update Fehler:', error);
        statusMessage.value = t('DeveloperTools.schemaUpdate.network_error', { message: error.message });
        statusType.value = 'error';
    } finally {
        updateLoading.value = false;
    }
}

async function loadDatabaseNames() {
    try {
        const response = await fetch('/api/update/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'getDatabaseNames' })
        });
        const data = await response.json();
        if (data.success) {
            dbNames.value = data.payload;
        }
    } catch (error) {
        console.error('DB-Namen laden fehlgeschlagen:', error);
    }
}

// --- Update-Hilfsfunktionen ---

function isChangeMessage(msg) {
    if (typeof msg === 'object' && msg.type) {
        const changeTypes = ['columns_added', 'column_added', 'table_created', 'csv_imported', 'statement_executed'];
        return changeTypes.includes(msg.type);
    }
    return false;
}

function formatMessage(msg) {
    if (typeof msg !== 'object' || !msg.type) return String(msg);

    const p = 'DeveloperTools.schemaUpdate.msg.';
    switch (msg.type) {
        case 'dry_run_mode': return t(p + 'dry_run_mode');
        case 'table_up_to_date': return t(p + 'table_up_to_date', { table: msg.table });
        case 'table_created': return t(p + (msg.dry_run ? 'table_created_dry' : 'table_created'), { table: msg.table });
        case 'columns_added': return t(p + (msg.dry_run ? 'columns_added_dry' : 'columns_added'), { count: msg.count, table: msg.table });
        case 'column_added': return t(p + (msg.dry_run ? 'column_added_dry' : 'column_added'), { column: msg.column, table: msg.table });
        case 'object_exists': return t(p + 'object_exists', { type: msg.object_type, name: msg.name });
        case 'statement_executed': return t(p + 'statement_executed', { type: msg.statement_type, target: msg.target });
        case 'csv_skipped': return t(p + 'csv_skipped', { file: msg.file || msg.table, reason: msg.reason });
        case 'csv_imported': return t(p + 'csv_imported', { rows: msg.rows_imported, table: msg.table });
        default: return JSON.stringify(msg);
    }
}
</script>

<style scoped>
.font-monospace {
    font-family: monospace;
    white-space: pre-wrap;
    word-break: break-all;
}

.error-message {
    white-space: pre-wrap !important;
    overflow: visible !important;
    text-overflow: unset !important;
}
</style>
