<!-- src/core/views/admin/components/company-delete.dialog.vue -->
<!--
    Firma entfernen. Standard: nur die Registrierung löschen (Datenbank bleibt).
    Die Datenbank wird nur gelöscht, wenn der Benutzer das ausdrücklich einschaltet
    UND den Datenbanknamen abtippt — vorher wird ein Backup gezogen.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="560" persistent @update:model-value="v => !v && close()">
        <v-card v-if="company">
            <v-card-title class="d-flex align-center ga-2 bg-error text-white">
                <v-icon>mdi-alert-outline</v-icon>
                {{ t('AdminView.companies.deleteTitle', { name: company.name }) }}
            </v-card-title>
            <v-card-text class="pa-4">
                <p class="text-body-2">{{ t('AdminView.companies.deleteText') }}</p>

                <v-switch
                    v-model="dropDatabase"
                    :label="t('AdminView.companies.deleteDropSwitch', { dbname: company.dbname })"
                    color="error"
                    inset
                    hide-details
                    class="mt-2"
                />
                <v-expand-transition>
                    <div v-if="dropDatabase" class="mt-2">
                        <v-alert type="error" variant="tonal" density="compact" class="mb-3">{{ t('AdminView.companies.deleteDropHint') }}</v-alert>
                        <v-text-field
                            v-model="confirmName"
                            :label="t('AdminView.companies.deleteConfirmLabel')"
                            :hint="t('AdminView.companies.deleteConfirmHint', { dbname: company.dbname })"
                            persistent-hint
                            variant="outlined"
                            density="comfortable"
                            autocomplete="off"
                            :error="confirmName !== '' && confirmName !== company.dbname"
                        />
                        <v-checkbox v-model="backup" :label="t('AdminView.companies.deleteBackup')" :hint="t('AdminView.companies.deleteBackupHint')" persistent-hint color="primary" density="compact" />
                    </div>
                </v-expand-transition>

                <v-alert v-if="errorMessage" type="error" variant="tonal" class="mt-3" closable @click:close="errorMessage = ''">{{ errorMessage }}</v-alert>
            </v-card-text>
            <v-divider />
            <v-card-actions class="pa-3">
                <v-btn variant="text" @click="close">{{ t('AdminView.common.cancel') }}</v-btn>
                <v-spacer />
                <v-btn color="error" variant="flat" :loading="deleting" :disabled="dropDatabase && confirmName !== company.dbname" prepend-icon="mdi-delete-outline" @click="doDelete">
                    {{ dropDatabase ? t('AdminView.companies.deleteButtonDrop') : t('AdminView.companies.deleteButton') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';
import * as toasts from '@/core/utils/toasts.js';

const { t } = useI18n();
const store = oserpStore();

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    company: { type: Object, default: null }
});
const emit = defineEmits(['update:modelValue', 'deleted']);

const dropDatabase = ref(false);
const confirmName = ref('');
const backup = ref(true);
const deleting = ref(false);
const errorMessage = ref('');

watch(() => props.modelValue, (open) => {
    if (open) {
        dropDatabase.value = false;
        confirmName.value = '';
        backup.value = true;
        errorMessage.value = '';
    }
});

function close() {
    emit('update:modelValue', false);
}

async function doDelete() {
    deleting.value = true;
    errorMessage.value = '';
    try {
        const result = await store.adminDeleteClient({
            id: props.company.id,
            drop_database: dropDatabase.value,
            confirm_dbname: confirmName.value,
            backup: backup.value
        });
        if (dropDatabase.value && result.drop_error) {
            toasts.warning(t('AdminView.companies.dropFailed', { error: result.drop_error }));
        } else {
            toasts.success(t(result.dropped ? 'AdminView.companies.deletedWithDrop' : 'AdminView.companies.deleted', { name: props.company.name }));
        }
        if (result.backup) toasts.info(t('AdminView.companies.backupCreated', { file: result.backup }));
        emit('update:modelValue', false);
        emit('deleted', result);
    } catch (e) {
        const key = 'AdminView.companies.errors.' + e.code;
        errorMessage.value = t(key) !== key ? t(key) : (e.message || e.code);
    } finally {
        deleting.value = false;
    }
}
</script>
