<!-- src/core/views/admin/components/user-edit.dialog.vue -->
<!--
    Benutzer anlegen/bearbeiten: Zugang, Passwort, Rolle, Firmen + Gruppen — alles
    in einem Dialog, damit ein neuer Kollege nach dem Speichern sofort arbeiten kann.
    Validierung passiert hier (Vue), das Backend prüft nur nochmal Eindeutigkeit.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="860" :fullscreen="mobile" scrollable persistent @update:model-value="tryClose">
        <v-card>
            <v-card-title class="d-flex align-center ga-2 bg-primary text-white">
                <v-icon>{{ isNew ? 'mdi-account-plus-outline' : 'mdi-account-edit-outline' }}</v-icon>
                {{ isNew ? t('AdminView.userDialog.titleNew') : t('AdminView.userDialog.titleEdit') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" @click="tryClose(false)" />
            </v-card-title>

            <v-card-text class="pa-4">
                <v-form ref="formRef" v-model="formValid" @submit.prevent="save">
                    <v-row>
                        <!-- Linke Spalte: Zugang, Passwort, Rolle -->
                        <v-col cols="12" md="6">
                            <div class="section-title">{{ t('AdminView.userDialog.sectionAccount') }}</div>
                            <v-text-field
                                ref="loginRef"
                                v-model="form.login"
                                :label="t('AdminView.userDialog.login')"
                                :hint="t('AdminView.userDialog.loginHint')"
                                :rules="[rules.required, rules.login, rules.loginUnique]"
                                variant="outlined"
                                density="comfortable"
                                prepend-inner-icon="mdi-account-outline"
                                autocomplete="off"
                                autofocus
                            />
                            <v-text-field
                                v-model="form.name"
                                :label="t('AdminView.userDialog.name')"
                                :hint="t('AdminView.userDialog.nameHint')"
                                variant="outlined"
                                density="comfortable"
                                prepend-inner-icon="mdi-card-account-details-outline"
                            />
                            <v-text-field
                                v-model="form.email"
                                :label="t('AdminView.userDialog.email')"
                                :rules="[rules.email]"
                                type="email"
                                variant="outlined"
                                density="comfortable"
                                prepend-inner-icon="mdi-email-outline"
                            />
                            <v-row dense>
                                <v-col cols="6">
                                    <v-text-field v-model="form.tel" :label="t('AdminView.userDialog.tel')" variant="outlined" density="comfortable" prepend-inner-icon="mdi-phone-outline" />
                                </v-col>
                                <v-col cols="6">
                                    <v-text-field v-model="form.fax" :label="t('AdminView.userDialog.fax')" variant="outlined" density="comfortable" prepend-inner-icon="mdi-fax" />
                                </v-col>
                            </v-row>

                            <div class="section-title" ref="passwordSectionRef">{{ t('AdminView.userDialog.sectionPassword') }}</div>
                            <v-radio-group v-if="!isNew" v-model="changePassword" inline hide-details density="compact" class="mb-2">
                                <v-radio :label="t('AdminView.userDialog.passwordKeep')" :value="false" />
                                <v-radio :label="t('AdminView.userDialog.passwordChange')" :value="true" />
                            </v-radio-group>
                            <template v-if="isNew || changePassword">
                                <password-field
                                    v-model="form.password"
                                    :label="t('AdminView.userDialog.password')"
                                    :hint="t('AdminView.userDialog.passwordHintNew')"
                                    :rules="[rules.passwordRequired, rules.passwordLength]"
                                    @generated="form.passwordConfirm = $event"
                                />
                                <password-field
                                    v-model="form.passwordConfirm"
                                    :label="t('AdminView.userDialog.passwordConfirm')"
                                    :rules="[rules.passwordMatch]"
                                    :generator="false"
                                    :strength-meter="false"
                                />
                            </template>

                            <div class="section-title">{{ t('AdminView.userDialog.sectionRole') }}</div>
                            <v-switch
                                v-model="form.is_admin"
                                :label="t('AdminView.userDialog.adminSwitch')"
                                :disabled="isSelf"
                                color="primary"
                                inset
                                hide-details
                            />
                            <div class="text-caption text-medium-emphasis mt-1">
                                {{ isSelf ? t('AdminView.userDialog.adminSelfHint') : t('AdminView.userDialog.adminHint') }}
                            </div>

                            <div class="section-title">{{ t('AdminView.userDialog.sectionFormats') }}</div>
                            <v-row dense>
                                <v-col cols="12" sm="4">
                                    <v-select v-model="form.countrycode" :items="languages" :label="t('AdminView.userDialog.language')" variant="outlined" density="compact" hide-details />
                                </v-col>
                                <v-col cols="6" sm="4">
                                    <v-select v-model="form.dateformat" :items="dateformats" :label="t('AdminView.userDialog.dateformat')" variant="outlined" density="compact" hide-details />
                                </v-col>
                                <v-col cols="6" sm="4">
                                    <v-select v-model="form.numberformat" :items="numberformats" :label="t('AdminView.userDialog.numberformat')" variant="outlined" density="compact" hide-details />
                                </v-col>
                            </v-row>
                        </v-col>

                        <!-- Rechte Spalte: Firmen + Gruppen -->
                        <v-col cols="12" md="6">
                            <div class="section-title">{{ t('AdminView.userDialog.sectionAccess') }}</div>
                            <entity-picker
                                v-model="form.client_ids"
                                :items="companyItems"
                                :label="t('AdminView.userDialog.companies')"
                                :hint="t('AdminView.userDialog.companiesHint')"
                                :warning="t('AdminView.userDialog.noCompaniesWarning')"
                                :empty-text="t('AdminView.companies.empty')"
                                class="mb-4"
                            />
                            <entity-picker
                                v-model="form.group_ids"
                                :items="groupItems"
                                :label="t('AdminView.userDialog.groups')"
                                :hint="t('AdminView.userDialog.groupsHint')"
                                :warning="t('AdminView.userDialog.noGroupsWarning')"
                                :empty-text="t('AdminView.groups.empty')"
                            />
                        </v-col>
                    </v-row>
                </v-form>

                <v-alert v-if="errorMessage" type="error" variant="tonal" class="mt-2" closable @click:close="errorMessage = ''">{{ errorMessage }}</v-alert>
            </v-card-text>

            <v-divider />
            <v-card-actions class="pa-3">
                <v-btn variant="text" @click="tryClose(false)">{{ t('AdminView.common.cancel') }}</v-btn>
                <v-spacer />
                <v-btn color="primary" variant="flat" :loading="saving" :disabled="!formValid" prepend-icon="mdi-check" @click="save">
                    {{ isNew ? t('AdminView.common.create') : t('AdminView.common.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useDisplay } from 'vuetify';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { isTrue, isValidEmail, isValidLogin } from '../adminHelpers.js';
import * as toasts from '@/core/utils/toasts.js';
import * as alerts from '@/core/utils/alerts.js';
import PasswordField from './password-field.component.vue';
import EntityPicker from './entity-picker.component.vue';

const { t } = useI18n();
const { mobile } = useDisplay();
const store = oserpStore();

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    overview: { type: Object, required: true },
    user: { type: Object, default: null },
    focusPassword: { type: Boolean, default: false }
});
const emit = defineEmits(['update:modelValue', 'saved']);

const formRef = ref(null);
const loginRef = ref(null);
const passwordSectionRef = ref(null);
const formValid = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const changePassword = ref(false);
let snapshot = '';

const emptyForm = () => ({
    id: null, login: '', name: '', email: '', tel: '', fax: '',
    password: '', passwordConfirm: '', is_admin: false,
    countrycode: 'de', dateformat: 'dd.mm.yy', numberformat: '1.000,00',
    client_ids: [], group_ids: []
});
const form = reactive(emptyForm());

const isNew = computed(() => !form.id);
const isSelf = computed(() => form.id === props.overview.admin.user_id);

const languages = [
    { value: 'de', title: 'Deutsch' }, { value: 'en', title: 'English' }, { value: 'fr', title: 'Français' },
    { value: 'es', title: 'Español' }, { value: 'it', title: 'Italiano' }, { value: 'nl', title: 'Nederlands' },
    { value: 'pl', title: 'Polski' }, { value: 'cs', title: 'Čeština' }, { value: 'tr', title: 'Türkçe' }
];
const dateformats = ['dd.mm.yy', 'dd/mm/yy', 'mm/dd/yy', 'yyyy-mm-dd'];
const numberformats = ['1.000,00', '1000,00', '1,000.00', '1000.00', "1'000.00"];

const companyItems = computed(() => props.overview.clients.map(c => ({
    id: c.id, title: c.name, subtitle: c.dbname, badge: isTrue(c.is_default) ? t('AdminView.companies.defaultBadge') : null, badgeColor: 'primary'
})));
// Warnung, wenn eine gewählte Gruppe in keiner der gewählten Firmen wirkt
const groupItems = computed(() => props.overview.groups.map(g => ({
    id: g.id,
    title: g.name,
    subtitle: g.description || t('AdminView.groups.rightsCount', { granted: g.rights.length, total: totalRights.value }),
    warning: form.client_ids.length && !g.client_ids.some(id => form.client_ids.includes(id)) ? t('AdminView.userDialog.groupNotInCompany') : null
})));
const totalRights = computed(() => props.overview.master_rights.filter(r => !isTrue(r.category)).length);

const rules = {
    required: v => !!(v && String(v).trim()) || t('AdminView.common.required'),
    login: v => isValidLogin(v) || t('AdminView.userDialog.loginInvalid'),
    loginUnique: v => !props.overview.users.some(u => u.id !== form.id && u.login.toLowerCase() === String(v || '').trim().toLowerCase()) || t('AdminView.users.errors.LOGIN_EXISTS'),
    email: v => !v || isValidEmail(v) || t('AdminView.userDialog.emailInvalid'),
    passwordRequired: v => (!isNew.value && !changePassword.value) || !!v || t('AdminView.common.required'),
    passwordLength: v => (!isNew.value && !changePassword.value) || !v || v.length >= 8 || t('AdminView.userDialog.passwordTooShort'),
    passwordMatch: v => (!isNew.value && !changePassword.value) || v === form.password || t('AdminView.userDialog.passwordMismatch')
};

function load() {
    Object.assign(form, emptyForm());
    const u = props.user;
    if (u) {
        form.id = u.id;
        form.login = u.login;
        form.name = u.config?.name || '';
        form.email = u.config?.email || '';
        form.tel = u.config?.tel || '';
        form.fax = u.config?.fax || '';
        form.is_admin = isTrue(u.is_admin);
        form.countrycode = u.config?.countrycode || 'de';
        form.dateformat = u.config?.dateformat || 'dd.mm.yy';
        form.numberformat = u.config?.numberformat || '1.000,00';
        form.client_ids = [...u.client_ids];
        form.group_ids = [...u.group_ids];
    } else {
        // Sinnvolle Vorgabe: Zugang zur aktuellen Firma, und dort die Gruppe mit den meisten Rechten
        form.client_ids = props.overview.clients.length === 1 ? [props.overview.clients[0].id] : [props.overview.admin.client_id].filter(Boolean);
    }
    changePassword.value = props.focusPassword;
    errorMessage.value = '';
    snapshot = JSON.stringify(form);
    nextTick(() => {
        formRef.value?.resetValidation();
        if (props.focusPassword && passwordSectionRef.value) {
            passwordSectionRef.value.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
        }
    });
}

watch(() => props.modelValue, (open) => { if (open) load(); });
watch(changePassword, () => nextTick(() => formRef.value?.validate()));

async function tryClose(value) {
    if (value === true) return;
    if (JSON.stringify(form) !== snapshot) {
        const result = await alerts.question(t('AdminView.common.unsavedChangesText'), t('AdminView.common.unsavedChanges'), t('AdminView.common.discard'), t('AdminView.common.keepEditing'));
        if (!result.isConfirmed) return;
    }
    emit('update:modelValue', false);
}

async function save() {
    const { valid } = await formRef.value.validate();
    if (!valid) return;
    saving.value = true;
    errorMessage.value = '';
    try {
        const payload = {
            id: form.id, login: form.login.trim(), name: form.name.trim(), email: form.email.trim(),
            tel: form.tel.trim(), fax: form.fax.trim(), is_admin: form.is_admin,
            countrycode: form.countrycode, dateformat: form.dateformat, numberformat: form.numberformat,
            client_ids: form.client_ids, group_ids: form.group_ids
        };
        if (isNew.value || changePassword.value) payload.password = form.password;
        const result = await store.adminSaveUser(payload);
        toasts.success(t(isNew.value ? 'AdminView.users.created' : 'AdminView.users.saved', { login: payload.login }));
        if (result?.warnings?.length) {
            alerts.warning(result.warnings.join('\n'), t('AdminView.userDialog.warningsTitle'));
        }
        snapshot = JSON.stringify(form);
        emit('update:modelValue', false);
        emit('saved', result);
    } catch (e) {
        const key = 'AdminView.users.errors.' + e.code;
        errorMessage.value = t(key) !== key ? t(key) : (e.message || e.code);
    } finally {
        saving.value = false;
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
.section-title:first-child { margin-top: 0; }
</style>
