<!-- contacts-search-form.component.vue -->
<template>
    <div>
        <v-row dense>
            <v-col>
                <v-row dense>
                    <v-col cols="1">
                        <v-text-field
                            :model-value="modelValue?.cp_title || ''"
                            @update:model-value="updateField('cp_title', $event)"
                            :label="t('CustomerVendorSearchView.fields.greeting')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_givenname || ''"
                            @update:model-value="updateField('cp_givenname', $event)"
                            :label="t('CustomerVendorSearchView.fields.first_name')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_name || ''"
                            @update:model-value="updateField('cp_name', $event)"
                            :label="t('CustomerVendorSearchView.fields.last_name')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_email || ''"
                            @update:model-value="updateField('cp_email', $event)"
                            :label="t('CustomerVendorSearchView.fields.mail')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="2">
                        <v-text-field
                            :model-value="modelValue?.phone || ''"
                            @update:model-value="updateField('phone', $event)"
                            :label="t('CustomerVendorSearchView.fields.phone')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.number || ''"
                            @update:model-value="updateField('number', $event)"
                            :label="t('CustomerVendorSearchView.fields.customer_vendor_number')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_position || ''"
                            @update:model-value="updateField('cp_position', $event)"
                            :label="t('CustomerVendorSearchView.fields.position_title')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_project || ''"
                            @update:model-value="updateField('cp_project', $event)"
                            :label="t('CustomerVendorSearchView.fields.project')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_abteilung || ''"
                            @update:model-value="updateField('cp_abteilung', $event)"
                            :label="t('CustomerVendorSearchView.fields.department')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-switch
                        color="green-darken-4"
                        base-color="red-darken-4"
                        :label="t('CustomerVendorSearchView.fields.switch_show_additional_search_fields')"
                        v-model="showAdditionalCriteria"
                        density="compact"
                    ></v-switch>
                </v-row>
            </v-col>
        </v-row>

        <v-row v-show="showAdditionalCriteria" dense>
            <v-col>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cv_name || ''"
                            @update:model-value="updateField('cv_name', $event)"
                            :label="t('CustomerVendorSearchView.fields.customer_vendor_name')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="2">
                        <v-select
                            :model-value="modelValue?.cv_type"
                            @update:model-value="updateField('cv_type', $event)"
                            :items="[
                                { label: t('CustomerVendorSearchView.labels.customer'), value: 'C' },
                                { label: t('CustomerVendorSearchView.labels.vendor'), value: 'V' }
                            ]"
                            :label="t('CustomerVendorSearchView.fields.type')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                    <v-col cols="2">
                        <v-select
                            :model-value="modelValue?.cp_gender"
                            @update:model-value="updateField('cp_gender', $event)"
                            :items="options.genderOptions.value"
                            :label="t('CustomerVendorSearchView.fields.gender')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                    <v-col cols="2">
                        <v-select
                            :model-value="modelValue?.cp_main"
                            @update:model-value="updateField('cp_main', $event)"
                            :items="options.mainContactOptions.value"
                            :label="t('CustomerVendorSearchView.fields.main_contact')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_street || ''"
                            @update:model-value="updateField('cp_street', $event)"
                            :label="t('CustomerVendorSearchView.fields.street')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="2">
                        <v-text-field
                            :model-value="modelValue?.cp_zipcode || ''"
                            @update:model-value="updateField('cp_zipcode', $event)"
                            :label="t('CustomerVendorSearchView.fields.zipcode')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="4">
                        <v-text-field
                            :model-value="modelValue?.cp_city || ''"
                            @update:model-value="updateField('cp_city', $event)"
                            :label="t('CustomerVendorSearchView.fields.city')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="6">
                        <!-- Platz für weitere Felder -->
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_birthday || ''"
                            @update:model-value="updateField('cp_birthday', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.birthday')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_birthday_from || ''"
                            @update:model-value="updateField('cp_birthday_from', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.birthday_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.cp_birthday_to || ''"
                            @update:model-value="updateField('cp_birthday_to', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.birthday_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <!-- Platz für weitere Felder -->
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.itime_from || ''"
                            @update:model-value="updateField('itime_from', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.created_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.itime_to || ''"
                            @update:model-value="updateField('itime_to', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.created_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.mtime_from || ''"
                            @update:model-value="updateField('mtime_from', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.updated_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue?.mtime_to || ''"
                            @update:model-value="updateField('mtime_to', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.updated_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                </v-row>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCustomerVendorOptions } from '../composables/useCustomerVendorOptions.js';

const { t } = useI18n();
const options = useCustomerVendorOptions();

// Interner State für den Switch - NICHT als Prop!
const showAdditionalCriteria = ref(false);

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({})
    }
});

const emit = defineEmits(['update:modelValue']);

/**
 * Aktualisiert ein einzelnes Feld im modelValue Objekt
 *
 * @param {string} field - Name des Feldes
 * @param {*} value - Neuer Wert
 */
const updateField = (field, value) => {
    emit('update:modelValue', {
        ...props.modelValue,
        [field]: value
    });
};
</script>
