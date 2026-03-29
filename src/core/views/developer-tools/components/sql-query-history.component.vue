<!-- src/core/views/system/components/sql-query-history.component.vue -->
<template>
    <v-expansion-panels variant="accordion" class="mt-4">
        <v-expansion-panel>
            <v-expansion-panel-title>
                <template #default="{ expanded }">
                    <v-icon start color="primary">mdi-history</v-icon>
                    {{ $t('DeveloperTools.sql.history.title') }}
                    <v-spacer />
                    <v-icon :class="{ 'rotate-icon': expanded }">mdi-chevron-down</v-icon>
                </template>
                <template #actions>
                    <v-tooltip v-if="history.length > 0" location="bottom">
                        <template #activator="{ props }">
                            <v-btn
                                v-bind="props"
                                icon
                                size="small"
                                variant="text"
                                @click.stop="confirmClearHistory"
                            >
                                <v-icon>mdi-delete-sweep</v-icon>
                            </v-btn>
                        </template>
                        <span>{{ $t('delete_all') }}</span>
                    </v-tooltip>
                </template>
            </v-expansion-panel-title>

            <v-expansion-panel-text>
                <div v-if="loading" class="pa-4 text-center">
                    <v-progress-circular indeterminate color="primary" />
                </div>

                <div v-else-if="history.length === 0" class="pa-4 text-center text-grey">
                    {{ $t('DeveloperTools.sql.history.empty') }}
                </div>

                <v-data-table
                    v-else
                    :headers="historyHeaders"
                    :items="history"
                    :items-per-page="10"
                    density="compact"
                    class="query-history-table"
                    @click:row="handleRowClick"
                >
                    <template #item.query="{ item }">
                        <div class="query-text-cell">
                            {{ truncateQuery(item.query) }}
                        </div>
                    </template>

                    <template #item.formatted_time="{ item }">
                        <div class="text-caption">
                            <v-icon size="x-small">mdi-clock-outline</v-icon>
                            {{ item.formatted_time }}
                        </div>
                    </template>

                    <template #item.execution_time="{ item }">
                        <div class="text-caption">
                            {{ item.execution_time }}ms
                        </div>
                    </template>

                    <template #item.row_count="{ item }">
                        <div class="text-caption">
                            {{ item.row_count }}
                        </div>
                    </template>

                    <template #item.actions="{ item }">
                        <v-btn
                            icon
                            size="small"
                            variant="text"
                            @click.stop="deleteQuery(item.id)"
                        >
                            <v-icon size="small">mdi-delete</v-icon>
                        </v-btn>
                    </template>
                </v-data-table>
            </v-expansion-panel-text>
        </v-expansion-panel>
    </v-expansion-panels>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import Swal from 'sweetalert2';

// Konstanten
const TOAST_DURATION = 800; // Dauer für Toast-Benachrichtigungen in ms, Wird später in der Config stehen

const { t: $t } = useI18n();
const props = defineProps({
    database: {
        type: String,
        required: true
    }
});
const emit = defineEmits(['select-query']);

const history = ref([]);
const loading = ref(false);

// Table Headers
const historyHeaders = computed(() => [
    { title: $t('DeveloperTools.sql.history.columns.query'), key: 'query', align: 'start', sortable: false },
    { title: $t('DeveloperTools.sql.history.columns.time'), key: 'formatted_time', align: 'start', sortable: true, width: '180px' },
    { title: $t('DeveloperTools.sql.history.columns.duration'), key: 'execution_time', align: 'end', sortable: true, width: '100px' },
    { title: $t('DeveloperTools.sql.history.columns.rows'), key: 'row_count', align: 'end', sortable: true, width: '100px' },
    { title: $t('DeveloperTools.sql.history.columns.actions'), key: 'actions', align: 'center', sortable: false, width: '80px' }
]);

/**
 * Behandelt Klick auf eine Zeile
 */
function handleRowClick(event, row) {
    emit('select-query', row.item.query);
}

/**
 * Lädt die Query-History (alle Queries)
 */
async function loadHistory() {
    loading.value = true;
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getQueryHistory',
            limit: 500,
            database: props.database
        });

        if (response.data.success && response.data.payload) {
            history.value = response.data.payload.history || [];
        }
    } catch (error) {
        console.error('Fehler beim Laden der Query-History:', error);
    } finally {
        loading.value = false;
    }
}

/**
 * Löscht einen einzelnen Query
 */
async function deleteQuery(queryId) {
    const result = await Swal.fire({
        title: $t('DeveloperTools.sql.history.delete_confirm'),
        text: $t('DeveloperTools.sql.history.delete_text'),
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: $t('delete'),
        cancelButtonText: $t('cancel'),
        confirmButtonColor: '#f44336'
    });

    if (!result.isConfirmed) {
        return;
    }

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'deleteQueryFromHistory',
            id: queryId
        });

        if (response.data.success) {
            await Swal.fire({
                title: $t('DeveloperTools.sql.history.deleted'),
                text: $t('DeveloperTools.sql.history.query_deleted'),
                icon: 'success',
                timer: TOAST_DURATION,
                showConfirmButton: false
            });
            loadHistory();
        } else {
            Swal.fire($t('error'), response.data.text || 'Query konnte nicht gelöscht werden', 'error');
        }
    } catch (error) {
        console.error('Fehler beim Löschen:', error);
        Swal.fire($t('error'), 'Netzwerkfehler beim Löschen', 'error');
    }
}

/**
 * Löscht die gesamte History
 */
async function confirmClearHistory() {
    const result = await Swal.fire({
        title: $t('DeveloperTools.sql.history.delete_all_confirm'),
        text: $t('DeveloperTools.sql.history.delete_all_text'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: $t('delete_all'),
        cancelButtonText: $t('cancel'),
        confirmButtonColor: '#f44336'
    });

    if (!result.isConfirmed) {
        return;
    }

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'clearQueryHistory',
            database: props.database
        });

        if (response.data.success) {
            await Swal.fire({
                title: $t('DeveloperTools.sql.history.deleted'),
                text: $t('DeveloperTools.sql.history.all_deleted'),
                icon: 'success',
                timer: TOAST_DURATION,
                showConfirmButton: false
            });
            loadHistory();
        } else {
            Swal.fire($t('error'), response.data.text || 'History konnte nicht gelöscht werden', 'error');
        }
    } catch (error) {
        console.error('Fehler beim Löschen:', error);
        Swal.fire($t('error'), 'Netzwerkfehler beim Löschen', 'error');
    }
}

/**
 * Kürzt lange Queries für die Anzeige
 */
function truncateQuery(query) {
    if (query.length <= 100) {
        return query;
    }
    return query.substring(0, 100) + '...';
}

// Watcher: History neu laden wenn Datenbank gewechselt wird
watch(() => props.database, () => {
    loadHistory();
});

onMounted(() => {
    loadHistory();
});

defineExpose({
    loadHistory
});
</script>

<style scoped>
.query-history-table {
    cursor: pointer;
}

.query-history-table :deep(tbody tr:hover) {
    background-color: #f5f5f5 !important;
}

.query-text-cell {
    font-family: 'Courier New', Courier, monospace;
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 500px;
}

.rotate-icon {
    transform: rotate(180deg);
    transition: transform 0.3s ease;
}
</style>