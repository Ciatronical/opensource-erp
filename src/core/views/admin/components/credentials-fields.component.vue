<!-- src/core/views/admin/components/credentials-fields.component.vue -->
<!-- Zugangsdaten einer Firmen-Datenbank (Host, Port, DB, Benutzer, Passwort) -->
<template>
    <div>
        <v-row dense>
            <v-col cols="8">
                <v-text-field :model-value="modelValue.dbhost" :label="t('AdminView.companyDialog.host')" :rules="[rules.required]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-server" @update:model-value="set('dbhost', $event)" />
            </v-col>
            <v-col cols="4">
                <v-text-field :model-value="modelValue.dbport" :label="t('AdminView.companyDialog.port')" :rules="[rules.port]" type="number" variant="outlined" density="comfortable" @update:model-value="set('dbport', $event)" />
            </v-col>
        </v-row>
        <v-text-field :model-value="modelValue.dbname" :label="t('AdminView.companyDialog.database')" :rules="[rules.required]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-database-outline" class="font-mono" @update:model-value="set('dbname', $event)" />
        <v-row dense>
            <v-col cols="12" sm="6">
                <v-text-field :model-value="modelValue.dbuser" :label="t('AdminView.companyDialog.dbUser')" :rules="[rules.required]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-account-key-outline" autocomplete="off" @update:model-value="set('dbuser', $event)" />
            </v-col>
            <v-col cols="12" sm="6">
                <password-field
                    :model-value="modelValue.dbpasswd"
                    :label="t('AdminView.companyDialog.dbPassword')"
                    :hint="isNew ? '' : t('AdminView.companyDialog.dbPasswordKeep')"
                    :generator="false"
                    :strength-meter="false"
                    autocomplete="off"
                    @update:model-value="set('dbpasswd', $event)"
                />
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import PasswordField from './password-field.component.vue';

const { t } = useI18n();
const props = defineProps({
    modelValue: { type: Object, required: true },
    rules: { type: Object, required: true },
    isNew: { type: Boolean, default: true }
});
const emit = defineEmits(['update:modelValue']);

function set(key, value) {
    // Das Formular ist ein reactive-Objekt des Elterndialogs — direkt setzen, damit
    // Validierung und Snapshot-Vergleich dort greifen.
    props.modelValue[key] = value;
    emit('update:modelValue', props.modelValue);
}
</script>

<style scoped>
.font-mono :deep(input) { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
</style>
