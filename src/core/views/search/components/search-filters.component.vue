<!-- src/modules/customer_vendor/views/components/search-filters.component.vue -->
<template>
    <div>
        <v-row dense>
            <v-col>
                <v-radio-group
                    inline
                    :model-value="typeFilter"
                    @update:model-value="handleTypeChange"
                >
                    <v-radio :label="t('CustomerVendorSearchView.fields.radio_customer')" value="customer"></v-radio>
                    <v-radio :label="t('CustomerVendorSearchView.fields.radio_vendor')" value="vendor"></v-radio>
                    <v-radio :label="t('CustomerVendorSearchView.fields.radio_person')" value="contacts"></v-radio>
                </v-radio-group>
            </v-col>
        </v-row>
        <v-row dense>
            <v-col>
                <v-switch
                    color="green-darken-4"
                    base-color="red-darken-4"
                    :label="t('CustomerVendorSearchView.fields.switch_sql_query')"
                    :model-value="useSqlQuery"
                    @update:model-value="handleSqlQueryChange"
                    density="compact"
                ></v-switch>
            </v-col>
        </v-row>
        <v-row v-show="useSqlQuery" dense class="mb-4">
            <v-col cols="1"></v-col>
            <v-col cols="10">
                <v-text-field
                    ref="sqlQueryInput"
                    :model-value="sqlQuery"
                    @update:model-value="$emit('update:sqlQuery', $event)"
                    @keydown.enter="$emit('search')"
                    rounded
                    variant="solo-filled"
                    :label="t('CustomerVendorSearchView.fields.sql_query')"
                    :prefix="'SELECT * FROM '+typeFilter+' WHERE '"
                ></v-text-field>
            </v-col>
            <v-col cols="1"></v-col>
        </v-row>
    </div>
</template>

<script setup>
import { ref, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

// Props
const props = defineProps({
    typeFilter: {
        type: String,
        required: true
    },
    useSqlQuery: {
        type: Boolean,
        required: true
    },
    sqlQuery: {
        type: String,
        required: true
    }
});

// Emits
const emit = defineEmits(['update:typeFilter', 'update:useSqlQuery', 'update:sqlQuery', 'search']);

// Ref für das Input-Feld
const sqlQueryInput = ref(null);

/**
 * Fokus-Funktion
 *
 * OPTIMIERT: Nur noch 1x nextTick statt 2x
 */
const setFocus = async () => {
    await nextTick();

    if (sqlQueryInput.value) {
        const nativeInput = sqlQueryInput.value.$el?.querySelector('input');
        if (nativeInput) {
            nativeInput.focus();
        }
    }
};

/**
 * Handler für Type-Änderung
 *
 * OPTIMIERT: Reduzierte Verzögerung von 100ms auf 50ms
 */
const handleTypeChange = (value) => {
    emit('update:typeFilter', value);

    // Wenn SQL-Query aktiv ist, fokussiere nach Type-Änderung
    if (props.useSqlQuery) {
        setTimeout(setFocus, 50);
    }
};

/**
 * Handler für SQL-Query Switch
 *
 * OPTIMIERT: Reduzierte Verzögerung von 100ms auf 50ms
 */
const handleSqlQueryChange = (value) => {
    emit('update:useSqlQuery', value);

    // Wenn SQL-Query aktiviert wird, fokussiere
    if (value) {
        setTimeout(setFocus, 50);
    }
};
</script>
