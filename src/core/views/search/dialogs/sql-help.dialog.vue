<!-- src/core/views/search/dialogs/sql-help.dialog.vue -->
<template>
    <v-dialog v-model="dialogVisible" max-width="800px">
        <v-card>
            <v-card-title class="py-3 px-4 bg-primary text-white d-flex align-center">
                <span class="text-h6">{{ t('CustomerVendorSearchView.dialogs.sql_help.title') }}</span>
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
                    {{ t('CustomerVendorSearchView.dialogs.sql_help.loading') }}
                </v-alert>

                <div v-else-if="columns.length > 0">
                    <!-- Tabellenname -->
                    <p class="mb-3">
                        <strong>{{ tableText }}</strong>
                    </p>

                    <!-- Beispielabfrage -->
                    <v-card variant="outlined" class="mb-4">
                        <v-card-title class="text-subtitle-2 py-2 px-3 bg-grey-lighten-4">
                            {{ t('CustomerVendorSearchView.dialogs.sql_help.example_query') }}
                        </v-card-title>
                        <v-card-text class="pa-3">
                            <p class="text-body-2 mb-2">
                                <em>{{ exampleDescription }}</em>
                            </p>
                            <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.4; margin: 0;">{{ exampleQuery }}</pre>
                            <v-btn
                                size="small"
                                variant="outlined"
                                prepend-icon="mdi-content-copy"
                                class="mt-2"
                                @click="copyExample"
                            >
                                {{ t('CustomerVendorSearchView.dialogs.sql_help.copy_example') }}
                            </v-btn>
                        </v-card-text>
                    </v-card>

                    <!-- Verfügbare Spalten -->
                    <p class="mb-2">
                        {{ t('CustomerVendorSearchView.dialogs.sql_help.available_columns') }}
                    </p>

                    <v-chip
                        v-for="column in columns"
                        :key="column"
                        class="ma-1"
                        size="small"
                        variant="outlined"
                        @click="copyColumn(column)"
                    >
                        {{ column }}
                        <v-icon end size="x-small">mdi-content-copy</v-icon>
                    </v-chip>

                    <v-alert type="info" variant="tonal" class="mt-4">
                        {{ t('CustomerVendorSearchView.dialogs.sql_help.click_to_copy') }}
                    </v-alert>
                </div>

                <v-alert v-else type="warning" variant="tonal">
                    {{ t('CustomerVendorSearchView.dialogs.sql_help.no_columns') }}
                </v-alert>
            </v-card-text>

            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn
                    color="primary"
                    variant="elevated"
                    @click="close"
                >
                    {{ t('CustomerVendorSearchView.dialogs.sql_help.close') }}
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

const { t } = useI18n();

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
const emit = defineEmits(['update:modelValue']);

// State
const loading = ref(false);
const columns = ref([]);

// Computed
const dialogVisible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});

const tableText = computed(() => {
    const tableMap = {
        'customer': t('CustomerVendorSearchView.dialogs.sql_help.table_customer'),
        'vendor': t('CustomerVendorSearchView.dialogs.sql_help.table_vendor'),
        'contacts': t('CustomerVendorSearchView.dialogs.sql_help.table_contacts')
    };
    return tableMap[props.typeFilter] || props.typeFilter;
});

const exampleDescription = computed(() => {
    const descriptions = {
        'customer': t('CustomerVendorSearchView.dialogs.sql_help.example_customer_description'),
        'vendor': t('CustomerVendorSearchView.dialogs.sql_help.example_vendor_description'),
        'contacts': t('CustomerVendorSearchView.dialogs.sql_help.example_contacts_description')
    };
    return descriptions[props.typeFilter] || '';
});

const exampleQuery = computed(() => {
    const examples = {
        'customer': `name ILIKE '%GmbH%' AND city = 'Berlin' AND creditlimit > 10000`,
        'vendor': `name ILIKE '%Tech%' AND country = 'DE' AND obsolete IS FALSE`,
        'contacts': `cp_gender = 'f' AND cv_name ILIKE '%Berlin%' AND (cp_birthday IS NULL OR EXTRACT(YEAR FROM AGE(cp_birthday)) BETWEEN 20 AND 40)`
    };
    return examples[props.typeFilter] || '';
});

// Methods
/**
 * Lädt die verfügbaren Spalten für die aktuelle Tabelle
 */
const loadColumns = async () => {
    loading.value = true;

    try {
        const response = await axios.post('/api/customer_vendor/', {
            action: 'getTableColumns',
            type: props.typeFilter
        });

        if (response.data.success) {
            const columnsData = response.data.payload?.columns?.columns || [];
            columns.value = columnsData;
        } else {
            toasts.error(t('CustomerVendorSearchView.dialogs.sql_help.error_loading'));
            columns.value = [];
        }
    } catch (error) {
        toasts.error(t('CustomerVendorSearchView.dialogs.sql_help.error_loading'));
        columns.value = [];
    } finally {
        loading.value = false;
    }
};

/**
 * Kopiert einen Spaltennamen in die Zwischenablage
 *
 * @param {string} column - Der Spaltenname
 */
const copyColumn = (column) => {
    navigator.clipboard.writeText(column);
    toasts.success(t('CustomerVendorSearchView.dialogs.sql_help.copied', { column }));
};

/**
 * Kopiert die Beispielabfrage in die Zwischenablage
 */
const copyExample = () => {
    navigator.clipboard.writeText(exampleQuery.value);
    toasts.success(t('CustomerVendorSearchView.dialogs.sql_help.example_copied'));
};

/**
 * Schließt den Dialog
 */
const close = () => {
    dialogVisible.value = false;
};

// Lade Spalten wenn Dialog geöffnet wird
watch(dialogVisible, (newValue) => {
    if (newValue) {
        loadColumns();
    }
});
</script>