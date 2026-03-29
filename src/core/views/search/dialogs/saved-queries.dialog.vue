<!-- src/core/views/search/dialogs/saved-queries.dialog.vue -->
<template>
    <v-dialog v-model="dialogVisible" max-width="700px">
        <v-card>
            <v-card-title class="py-3 px-4 bg-primary text-white d-flex align-center">
                <span class="text-h6">{{ t('CustomerVendorSearchView.dialogs.saved_queries.title') }}</span>
                <v-spacer />
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    color="white"
                    @click="close"
                />
            </v-card-title>

            <v-card-text class="pt-4">
                <v-alert v-if="loading" type="info" variant="tonal">
                    {{ t('CustomerVendorSearchView.dialogs.saved_queries.loading') }}
                </v-alert>

                <div v-else-if="queries.length > 0">
                    <v-list>
                        <v-list-item
                            v-for="query in queries"
                            :key="query.id"
                            @click="selectQuery(query)"
                            class="mb-2"
                            border
                            rounded
                        >
                            <template #prepend>
                                <v-icon color="primary">mdi-database-search</v-icon>
                            </template>

                            <v-list-item-title class="font-weight-medium">
                                {{ query.name }}
                            </v-list-item-title>

                            <v-list-item-subtitle class="text-caption mt-1">
                                <code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">{{ query.query }}</code>
                            </v-list-item-subtitle>

                            <v-list-item-subtitle class="text-caption mt-1">
                                {{ formatDate(query.created_at) }}
                            </v-list-item-subtitle>

                            <template #append>
                                <v-btn
                                    icon="mdi-delete"
                                    size="small"
                                    variant="text"
                                    color="error"
                                    @click.stop="openDeleteConfirm(query.id)"
                                />
                            </template>
                        </v-list-item>
                    </v-list>
                </div>

                <v-alert v-else type="info" variant="tonal">
                    {{ t('CustomerVendorSearchView.dialogs.saved_queries.no_queries') }}
                </v-alert>
            </v-card-text>

            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn
                    color="primary"
                    variant="elevated"
                    @click="close"
                >
                    {{ t('CustomerVendorSearchView.dialogs.saved_queries.close') }}
                </v-btn>
            </v-card-actions>
        </v-card>

        <!-- Delete Confirmation Dialog -->
        <v-dialog v-model="showDeleteConfirm" max-width="400px">
            <v-card>
                <v-card-title class="py-3 px-4 bg-error text-white">
                    {{ t('CustomerVendorSearchView.dialogs.saved_queries.delete_confirm_title') }}
                </v-card-title>
                <v-card-text class="pt-4">
                    {{ t('CustomerVendorSearchView.dialogs.saved_queries.delete_confirm_text') }}
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showDeleteConfirm = false">
                        {{ t('CustomerVendorSearchView.dialogs.save_query.cancel') }}
                    </v-btn>
                    <v-btn color="error" variant="elevated" @click="confirmDelete">
                        Löschen
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import * as toasts from '@/core/utils/toasts.js';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t } = useI18n();
const store = oserpStore();

// Props
const props = defineProps({
    modelValue: {
        type: Boolean,
        required: true
    },
    typeFilter: {
        type: String,
        required: true
    }
});

// Emits
const emit = defineEmits(['update:modelValue', 'select-query']);

// State
const loading = ref(false);
const queries = ref([]);
const showDeleteConfirm = ref(false);
const queryToDelete = ref(null);

// Computed
const dialogVisible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

// Methods
/**
 * Lädt gespeicherte Queries
 */
const loadQueries = async () => {
    loading.value = true;

    try {
        const response = await axios.post('/api/customer_vendor/', {
            action: 'getSavedSqlQueries',
            type: props.typeFilter,
            employee_id: store.session?.logged_in_employee?.id
        });

        // Probiere verschiedene Pfade
        let loadedQueries = null;

        if (response.data?.payload?.result?.queries) {
            loadedQueries = response.data.payload.result.queries;
        } else if (response.data?.queries) {
            loadedQueries = response.data.queries;
        } else if (response.data?.payload?.queries) {
            loadedQueries = response.data.payload.queries;
        }

        queries.value = loadedQueries || [];

    } catch (error) {
        console.error('Load error:', error);
        toasts.error(t('CustomerVendorSearchView.dialogs.saved_queries.error_loading'));
        queries.value = [];
    } finally {
        loading.value = false;
    }
};

/**
 * Wählt eine Query aus
 */
const selectQuery = (query) => {
    emit('select-query', query.query);
    close();
};

/**
 * Öffnet Delete-Bestätigung
 */
const openDeleteConfirm = (queryId) => {
    queryToDelete.value = queryId;
    showDeleteConfirm.value = true;
};

/**
 * Bestätigt das Löschen
 */
const confirmDelete = async () => {
    showDeleteConfirm.value = false;

    if (!queryToDelete.value) {
        return;
    }

    try {
        // Hole alle Queries
        const getResponse = await axios.post('/api/customer_vendor/', {
            action: 'getSavedSqlQueries',
            type: null, // Alle Typen
            employee_id: store.session?.logged_in_employee?.id
        });

        let allQueries = [];
        if (getResponse.data?.payload?.result?.queries) {
            allQueries = getResponse.data.payload.result.queries;
        } else if (getResponse.data?.queries) {
            allQueries = getResponse.data.queries;
        }

        // Entferne die Query
        allQueries = allQueries.filter(q => q.id !== queryToDelete.value);

        // Speichere zurück
        const saveResponse = await axios.post('/api/customer_vendor/', {
            action: 'updateSavedSqlQueries',
            queries: allQueries,
            employee_id: store.session?.logged_in_employee?.id
        });

        if (saveResponse.data?.success || saveResponse.data?.payload?.result?.success) {
            toasts.success(t('CustomerVendorSearchView.dialogs.saved_queries.deleted'));
            await loadQueries();
        } else {
            toasts.error(t('CustomerVendorSearchView.dialogs.saved_queries.error_deleting'));
        }
    } catch (error) {
        console.error('Delete error:', error);
        toasts.error(t('CustomerVendorSearchView.dialogs.saved_queries.error_deleting'));
    } finally {
        queryToDelete.value = null;
    }
};

/**
 * Formatiert Datum
 */
const formatDate = (dateString) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleString('de-DE');
};

/**
 * Schließt den Dialog
 */
const close = () => {
    dialogVisible.value = false;
};

// Lade Queries wenn Dialog geöffnet wird
watch(dialogVisible, (newValue) => {
    if (newValue) {
        loadQueries();
    }
});
</script>