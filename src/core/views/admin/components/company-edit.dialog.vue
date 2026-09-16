<!-- src/core/views/admin/components/company-edit.dialog.vue -->
<!--
    Firma anlegen oder bearbeiten.
      Neu:   "Neue Datenbank anlegen" (Name, Kontenrahmen, Zugriff) oder
             "Vorhandene Datenbank verbinden" (Liste vom Server oder Zugang von Hand)
      Edit:  Name, Zugangsdaten (Passwort leer = unverändert), Standard, Zugriff
    Das Anlegen dauert ~1 Minute — der Dialog zeigt das ehrlich an und blockt
    das Schließen solange.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="860" :fullscreen="mobile" scrollable persistent @update:model-value="tryClose">
        <v-card>
            <v-card-title class="d-flex align-center ga-2 bg-primary text-white">
                <v-icon>{{ isNew ? 'mdi-domain-plus' : 'mdi-domain' }}</v-icon>
                {{ isNew ? t('AdminView.companyDialog.titleNew') : t('AdminView.companyDialog.titleEdit') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" :disabled="creating" @click="tryClose(false)" />
            </v-card-title>

            <!-- Fortschritt beim Anlegen -->
            <v-card-text v-if="creating" class="pa-8 text-center">
                <v-progress-circular indeterminate color="primary" size="56" width="5" class="mb-4" />
                <div class="text-h6">{{ t('AdminView.companyDialog.creating') }}</div>
                <div class="text-body-2 text-medium-emphasis mt-2">{{ t('AdminView.companyDialog.creatingHint') }}</div>
            </v-card-text>

            <!-- Erfolg: direkt wechseln oder hier bleiben -->
            <v-card-text v-else-if="createdResult" class="pa-8 text-center">
                <v-icon color="success" size="64">mdi-check-circle-outline</v-icon>
                <div class="text-h6 mt-2">{{ t('AdminView.companyDialog.createdTitle', { name: createdResult.name }) }}</div>
                <v-alert v-if="createdResult.warnings?.length" type="warning" variant="tonal" class="mt-4 text-start">
                    <div v-for="(w, i) in createdResult.warnings" :key="i" class="text-body-2">{{ w }}</div>
                </v-alert>
                <div class="d-flex justify-center ga-3 mt-6 flex-wrap">
                    <v-btn variant="outlined" @click="finish(false)">{{ t('AdminView.companyDialog.stayHere') }}</v-btn>
                    <v-btn color="primary" variant="flat" prepend-icon="mdi-swap-horizontal" @click="finish(true)">{{ t('AdminView.companyDialog.switchNow') }}</v-btn>
                </div>
            </v-card-text>

            <v-card-text v-else class="pa-4">
                <!-- Modus-Wahl bei Neuanlage -->
                <v-row v-if="isNew" class="mb-2">
                    <v-col v-for="m in modes" :key="m.value" cols="12" sm="6">
                        <v-card :variant="mode === m.value ? 'tonal' : 'outlined'" :color="mode === m.value ? 'primary' : undefined" hover class="h-100" @click="mode = m.value">
                            <v-card-text class="d-flex ga-3 align-start">
                                <v-icon :icon="m.icon" size="28" />
                                <div>
                                    <div class="font-weight-bold">{{ m.title }}</div>
                                    <div class="text-caption">{{ m.hint }}</div>
                                </div>
                                <v-spacer />
                                <v-icon v-if="mode === m.value" color="primary">mdi-check-circle</v-icon>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>

                <v-form ref="formRef" v-model="formValid" @submit.prevent="save">
                    <v-row>
                        <v-col cols="12" md="7">
                            <!-- ── Neue Datenbank ── -->
                            <template v-if="isNew && mode === 'new'">
                                <v-text-field
                                    v-model="form.name"
                                    :label="t('AdminView.companyDialog.name')"
                                    :hint="t('AdminView.companyDialog.nameHint')"
                                    :rules="[rules.required, rules.nameUnique]"
                                    variant="outlined"
                                    density="comfortable"
                                    prepend-inner-icon="mdi-domain"
                                    autofocus
                                    @update:model-value="onNameInput"
                                />
                                <v-text-field
                                    v-model="form.dbname"
                                    :label="t('AdminView.companyDialog.dbName')"
                                    :hint="t('AdminView.companyDialog.dbNameHint')"
                                    :rules="[rules.required, rules.dbname]"
                                    variant="outlined"
                                    density="comfortable"
                                    prepend-inner-icon="mdi-database-outline"
                                    class="font-mono"
                                    @update:model-value="dbnameTouched = true"
                                />
                                <div class="section-title">{{ t('AdminView.companyDialog.chart') }}</div>
                                <v-row dense class="mb-1">
                                    <v-col v-for="chart in overview.charts" :key="chart.id" cols="12" sm="6">
                                        <v-card :variant="form.skr === chart.id ? 'tonal' : 'outlined'" :color="form.skr === chart.id ? 'primary' : undefined" hover class="h-100" @click="form.skr = chart.id">
                                            <v-card-text class="pa-3">
                                                <div class="d-flex align-center ga-2 font-weight-bold">
                                                    <v-icon size="small">{{ form.skr === chart.id ? 'mdi-radiobox-marked' : 'mdi-radiobox-blank' }}</v-icon>
                                                    {{ chartTitle(chart) }}
                                                </div>
                                                <div class="text-caption mt-1">{{ chartHint(chart) }}</div>
                                            </v-card-text>
                                        </v-card>
                                    </v-col>
                                </v-row>
                                <div class="text-caption text-medium-emphasis mb-3">{{ t('AdminView.companyDialog.chartHint') }}</div>
                            </template>

                            <!-- ── Vorhandene Datenbank verbinden ── -->
                            <template v-else-if="isNew && mode === 'existing'">
                                <v-text-field
                                    v-model="form.name"
                                    :label="t('AdminView.companyDialog.name')"
                                    :hint="t('AdminView.companyDialog.nameHint')"
                                    :rules="[rules.required, rules.nameUnique]"
                                    variant="outlined"
                                    density="comfortable"
                                    prepend-inner-icon="mdi-domain"
                                    autofocus
                                />
                                <v-switch v-model="manualCredentials" :label="t('AdminView.companyDialog.existingManual')" color="primary" density="compact" hide-details class="mb-2" />

                                <template v-if="!manualCredentials">
                                    <div class="d-flex align-center ga-2 mb-1">
                                        <span class="text-subtitle-2">{{ t('AdminView.companyDialog.existingSelect') }}</span>
                                        <v-spacer />
                                        <v-btn size="x-small" variant="text" prepend-icon="mdi-refresh" :loading="loadingDatabases" @click="loadDatabases">{{ t('AdminView.companyDialog.refreshList') }}</v-btn>
                                    </div>
                                    <div class="text-caption text-medium-emphasis mb-2">{{ t('AdminView.companyDialog.existingSelectHint') }}</div>
                                    <div v-if="serverInfo" class="text-caption text-medium-emphasis mb-2">{{ t('AdminView.companyDialog.serverInfo', serverInfo) }}</div>
                                    <v-skeleton-loader v-if="loadingDatabases" type="list-item-two-line@3" />
                                    <v-sheet v-else border rounded class="db-list">
                                        <v-list density="compact" class="py-0">
                                            <v-list-item v-for="db in unregisteredDatabases" :key="db.name" :active="form.dbname === db.name" active-color="primary" @click="pickDatabase(db)">
                                                <template #prepend>
                                                    <v-icon :color="form.dbname === db.name ? 'primary' : 'grey'">{{ form.dbname === db.name ? 'mdi-radiobox-marked' : 'mdi-radiobox-blank' }}</v-icon>
                                                </template>
                                                <v-list-item-title class="font-mono">{{ db.name }}</v-list-item-title>
                                                <v-list-item-subtitle>
                                                    {{ db.company || t('AdminView.companyDialog.unknownCompany') }}
                                                    <span v-if="db.coa"> · {{ db.coa }}</span>
                                                    <span v-if="db.size_bytes"> · {{ t('AdminView.companyDialog.sizeMb', { size: Math.round(db.size_bytes / 1048576) }) }}</span>
                                                </v-list-item-subtitle>
                                                <template #append>
                                                    <v-chip size="x-small" variant="tonal" :color="db.has_oserp ? 'success' : 'warning'">
                                                        {{ db.has_oserp ? t('AdminView.companyDialog.hasOserp') : t('AdminView.companyDialog.noOserp') }}
                                                    </v-chip>
                                                </template>
                                            </v-list-item>
                                            <v-list-item v-if="unregisteredDatabases.length === 0" disabled>
                                                <v-list-item-title class="text-caption text-wrap">{{ t('AdminView.companyDialog.noUnregistered') }}</v-list-item-title>
                                            </v-list-item>
                                        </v-list>
                                    </v-sheet>
                                </template>

                                <credentials-fields v-else :model-value="form" :rules="rules" :is-new="true" class="mt-2" />
                                <connection-test :form="form" :company-id="null" class="mt-2" />
                            </template>

                            <!-- ── Bearbeiten ── -->
                            <template v-else>
                                <v-text-field
                                    v-model="form.name"
                                    :label="t('AdminView.companyDialog.name')"
                                    :rules="[rules.required, rules.nameUnique]"
                                    variant="outlined"
                                    density="comfortable"
                                    prepend-inner-icon="mdi-domain"
                                    autofocus
                                />
                                <div class="section-title">{{ t('AdminView.companies.database') }}</div>
                                <credentials-fields :model-value="form" :rules="rules" :is-new="false" />
                                <connection-test :form="form" :company-id="form.id" class="mt-2" />
                            </template>

                            <v-switch v-model="form.is_default" :label="t('AdminView.companyDialog.isDefault')" color="primary" inset hide-details class="mt-3" />
                        </v-col>

                        <v-col cols="12" md="5">
                            <entity-picker
                                v-model="form.user_ids"
                                :items="userItems"
                                :locked-ids="[overview.admin.user_id]"
                                :label="t('AdminView.companyDialog.users')"
                                :hint="t('AdminView.companyDialog.usersHint')"
                                :empty-text="t('AdminView.users.empty')"
                                class="mb-4"
                            />
                            <entity-picker
                                v-model="form.group_ids"
                                :items="groupItems"
                                :label="t('AdminView.companyDialog.groups')"
                                :hint="t('AdminView.companyDialog.groupsHint')"
                                :empty-text="t('AdminView.groups.empty')"
                            />
                        </v-col>
                    </v-row>
                </v-form>
                <v-alert v-if="errorMessage" type="error" variant="tonal" class="mt-2" closable @click:close="errorMessage = ''">{{ errorMessage }}</v-alert>
            </v-card-text>

            <template v-if="!creating && !createdResult">
                <v-divider />
                <v-card-actions class="pa-3">
                    <v-btn variant="text" @click="tryClose(false)">{{ t('AdminView.common.cancel') }}</v-btn>
                    <v-spacer />
                    <v-btn color="primary" variant="flat" :loading="saving" :disabled="!canSave" :prepend-icon="isNew && mode === 'new' ? 'mdi-database-plus-outline' : 'mdi-check'" @click="save">
                        {{ isNew ? (mode === 'new' ? t('AdminView.common.create') : t('AdminView.companyDialog.modeExisting')) : t('AdminView.common.save') }}
                    </v-btn>
                </v-card-actions>
            </template>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useDisplay } from 'vuetify';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { isTrue, isValidDbName, suggestDbName, userDisplayName } from '../adminHelpers.js';
import * as toasts from '@/core/utils/toasts.js';
import * as alerts from '@/core/utils/alerts.js';
import EntityPicker from './entity-picker.component.vue';
import CredentialsFields from './credentials-fields.component.vue';
import ConnectionTest from './connection-test.component.vue';

const { t, te } = useI18n();
const { mobile } = useDisplay();
const store = oserpStore();

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    overview: { type: Object, required: true },
    company: { type: Object, default: null }
});
const emit = defineEmits(['update:modelValue', 'saved']);

const formRef = ref(null);
const formValid = ref(false);
const saving = ref(false);
const creating = ref(false);
const createdResult = ref(null);
const errorMessage = ref('');
const mode = ref('new');
const manualCredentials = ref(false);
const dbnameTouched = ref(false);
const loadingDatabases = ref(false);
const databases = ref([]);
const serverInfo = ref(null);
let snapshot = '';

const form = reactive({
    id: null, name: '', dbname: '', skr: 'skr03', dbhost: '', dbport: 5432, dbuser: '', dbpasswd: '',
    is_default: false, user_ids: [], group_ids: []
});
const isNew = computed(() => !form.id);

const modes = computed(() => [
    { value: 'new', icon: 'mdi-database-plus-outline', title: t('AdminView.companyDialog.modeNew'), hint: t('AdminView.companyDialog.modeNewHint') },
    { value: 'existing', icon: 'mdi-database-import-outline', title: t('AdminView.companyDialog.modeExisting'), hint: t('AdminView.companyDialog.modeExistingHint') }
]);

const rules = {
    required: v => !!(v !== null && v !== undefined && String(v).trim()) || t('AdminView.common.required'),
    nameUnique: v => !props.overview.clients.some(c => c.id !== form.id && c.name.toLowerCase() === String(v || '').trim().toLowerCase()) || t('AdminView.companies.errors.COMPANY_NAME_EXISTS'),
    dbname: v => isValidDbName(v) || t('AdminView.companyDialog.dbNameInvalid'),
    port: v => (Number(v) >= 1 && Number(v) <= 65535) || t('AdminView.common.required')
};

function chartTitle(chart) {
    const key = 'AdminView.companyDialog.charts.' + chart.id;
    return te(key) ? t(key) : chart.name;
}
function chartHint(chart) {
    const key = 'AdminView.companyDialog.charts.' + chart.id + 'Hint';
    return te(key) ? t(key) : '';
}

// Datenbankname aus dem Firmennamen vorschlagen, solange er nicht von Hand geändert wurde
function onNameInput(value) {
    if (!dbnameTouched.value) form.dbname = suggestDbName(value);
}

const userItems = computed(() => props.overview.users.map(u => ({ id: u.id, title: userDisplayName(u), subtitle: u.login, badge: isTrue(u.is_admin) ? t('AdminView.users.admin') : null, badgeColor: 'primary' })));
const groupItems = computed(() => props.overview.groups.map(g => ({ id: g.id, title: g.name, subtitle: g.description || '' })));

const unregisteredDatabases = computed(() => databases.value.filter(db => db.is_company && !db.client && !db.is_auth_db));

const canSave = computed(() => {
    if (!formValid.value) return false;
    if (isNew.value && mode.value === 'existing' && !manualCredentials.value && !form.dbname) return false;
    return true;
});

async function loadDatabases() {
    loadingDatabases.value = true;
    try {
        const result = await store.adminListServerDatabases(true);
        databases.value = result.databases || [];
        serverInfo.value = result.server;
        // Zugangsdaten der Auth-DB übernehmen — dieselbe Rolle nutzt OSERP für alle Firmen
        if (!manualCredentials.value) {
            form.dbhost = result.server.host;
            form.dbport = result.server.port;
            form.dbuser = result.server.user;
        }
    } catch (e) {
        toasts.error(e.message || e.code);
    } finally {
        loadingDatabases.value = false;
    }
}
function pickDatabase(db) {
    form.dbname = db.name;
    if (!form.name && db.company) form.name = db.company;
}
watch(mode, (m) => { if (m === 'existing' && databases.value.length === 0) loadDatabases(); });

function load() {
    const c = props.company;
    form.id = c ? c.id : null;
    form.name = c ? c.name : '';
    form.dbname = c ? c.dbname : '';
    form.skr = props.overview.charts[0]?.id || 'skr03';
    form.dbhost = c ? c.dbhost : props.overview.auth_db.host;
    form.dbport = c ? Number(c.dbport) : props.overview.auth_db.port;
    form.dbuser = c ? c.dbuser : props.overview.auth_db.user;
    form.dbpasswd = '';
    form.is_default = c ? isTrue(c.is_default) : props.overview.clients.length === 0;
    // Neu: der Anleger selbst + alle Administratoren; Gruppen der aktuellen Firma
    form.user_ids = c ? [...c.user_ids] : [...new Set([props.overview.admin.user_id, ...props.overview.users.filter(u => isTrue(u.is_admin)).map(u => u.id)])];
    const current = props.overview.clients.find(x => x.id === props.overview.admin.client_id);
    form.group_ids = c ? [...c.group_ids] : (current ? [...current.group_ids] : props.overview.groups.map(g => g.id));
    mode.value = 'new';
    manualCredentials.value = false;
    dbnameTouched.value = false;
    createdResult.value = null;
    errorMessage.value = '';
    snapshot = JSON.stringify(form);
    nextTick(() => formRef.value?.resetValidation());
}
watch(() => props.modelValue, (open) => { if (open) load(); });

async function tryClose(value) {
    if (value === true || creating.value) return;
    if (!createdResult.value && JSON.stringify(form) !== snapshot) {
        const result = await alerts.question(t('AdminView.common.unsavedChangesText'), t('AdminView.common.unsavedChanges'), t('AdminView.common.discard'), t('AdminView.common.keepEditing'));
        if (!result.isConfirmed) return;
    }
    emit('update:modelValue', false);
}

function finish(switchTo) {
    const result = createdResult.value;
    emit('update:modelValue', false);
    emit('saved', { ...result, switchTo: switchTo ? result.clientId : null });
}

async function save() {
    const { valid } = await formRef.value.validate();
    if (!valid) return;
    saving.value = true;
    errorMessage.value = '';
    try {
        if (isNew.value && mode.value === 'new') {
            creating.value = true;
            const result = await store.createCompany({
                companyName: form.name.trim(), dbName: form.dbname.trim(), skr: form.skr,
                isDefault: form.is_default, userIds: form.user_ids, groupIds: form.group_ids
            });
            createdResult.value = { name: result.companyName, clientId: result.clientId, warnings: result.warnings || [] };
            toasts.success(t('AdminView.companies.created', { name: result.companyName }));
            snapshot = JSON.stringify(form);
            return;
        }
        const payload = {
            id: form.id, name: form.name.trim(), dbhost: form.dbhost.trim(), dbport: Number(form.dbport), dbname: form.dbname.trim(),
            dbuser: form.dbuser.trim(), dbpasswd: form.dbpasswd, is_default: form.is_default, user_ids: form.user_ids, group_ids: form.group_ids
        };
        const result = await store.adminSaveClient(payload);
        toasts.success(t(isNew.value ? 'AdminView.companies.registered' : 'AdminView.companies.saved', { name: payload.name }));
        if (result?.warnings?.length) alerts.warning(result.warnings.join('\n'), t('AdminView.common.warnings'));
        snapshot = JSON.stringify(form);
        emit('update:modelValue', false);
        emit('saved', result);
    } catch (e) {
        const key = 'AdminView.companies.errors.' + e.code;
        errorMessage.value = (t(key) !== key ? t(key) : '') + (e.message && e.message !== e.code ? ' ' + e.message : (t(key) !== key ? '' : e.code));
    } finally {
        saving.value = false;
        creating.value = false;
    }
}
</script>

<style scoped>
.section-title {
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: rgb(var(--v-theme-primary));
    margin: 12px 0 8px;
}
.font-mono :deep(input), .font-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.db-list { max-height: 260px; overflow-y: auto; }
</style>
