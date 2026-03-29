<!-- src/core/views/search/dialogs/save-query.dialog.vue -->
<template>
    <v-dialog v-model="dialogVisible" max-width="500px">
        <v-card>
            <v-card-title class="py-3 px-4 bg-primary text-white">
                <span class="text-h6">{{ t('CustomerVendorSearchView.dialogs.save_query.title') }}</span>
            </v-card-title>

            <v-card-text class="pt-4">
                <v-text-field
                    v-model="queryName"
                    :label="t('CustomerVendorSearchView.dialogs.save_query.name_label')"
                    :placeholder="t('CustomerVendorSearchView.dialogs.save_query.name_placeholder')"
                    variant="outlined"
                    density="comfortable"
                    autofocus
                    @keydown.enter="save"
                />

                <v-alert type="info" variant="tonal" class="mt-2">
                    <strong>{{ t('CustomerVendorSearchView.dialogs.save_query.query') }}:</strong><br>
                    <code style="font-size: 12px;">{{ query }}</code>
                </v-alert>
            </v-card-text>

            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn
                    variant="text"
                    @click="close"
                >
                    {{ t('CustomerVendorSearchView.dialogs.save_query.cancel') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="elevated"
                    :disabled="!queryName.trim()"
                    @click="save"
                >
                    {{ t('CustomerVendorSearchView.dialogs.save_query.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
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
    query: {
        type: String,
        required: true
    },
    typeFilter: {
        type: String,
        required: true
    }
});

// Emits
const emit = defineEmits(['update:modelValue', 'saved']);

// State
const queryName = ref('');

// Computed
const dialogVisible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

// Methods
/**
 * Speichert die Query
 */
const save = async () => {
    if (!queryName.value.trim()) {
        return;
    }

    try {
        // Hole aktuelle gespeicherte Queries
        const getResponse = await axios.post('/api/customer_vendor/', {
            action: 'getSavedSqlQueries',
            type: null, // Alle Typen holen
            employee_id: store.session?.logged_in_employee?.id
        });

        // Bestehende Queries oder leeres Array
        let existingQueries = [];

        if (getResponse.data?.success && getResponse.data?.payload?.result?.queries) {
            existingQueries = getResponse.data.payload.result.queries;
        }

        // Neue Query hinzufügen
        const newQuery = {
            id: Date.now().toString(36) + Math.random().toString(36).substr(2),
            name: queryName.value,
            query: props.query,
            type: props.typeFilter,
            created_at: new Date().toISOString().slice(0, 19).replace('T', ' ')
        };

        existingQueries.push(newQuery);

        // Komplett zurückspeichern
        const saveResponse = await axios.post('/api/customer_vendor/', {
            action: 'updateSavedSqlQueries',
            queries: existingQueries,
            employee_id: store.session?.logged_in_employee?.id
        });

        if (saveResponse.data?.success || saveResponse.data?.payload?.result?.success) {
            toasts.success(t('CustomerVendorSearchView.dialogs.save_query.saved'));
            emit('saved');
            close();
        } else {
            toasts.error(t('CustomerVendorSearchView.dialogs.save_query.error_saving'));
        }
    } catch (error) {
        console.error('Save error:', error);
        toasts.error(t('CustomerVendorSearchView.dialogs.save_query.error_saving'));
    }
};

/**
 * Schließt den Dialog
 */
const close = () => {
    queryName.value = '';
    dialogVisible.value = false;
};

// Reset queryName wenn Dialog geschlossen wird
watch(dialogVisible, (newValue) => {
    if (!newValue) {
        queryName.value = '';
    }
});
</script>