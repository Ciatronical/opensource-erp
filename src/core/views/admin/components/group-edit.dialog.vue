<!-- src/core/views/admin/components/group-edit.dialog.vue -->
<!--
    Gruppe anlegen/bearbeiten. Die Rechte-Matrix ist nach Kategorien gegliedert,
    jede Kategorie lässt sich mit einem Klick komplett erteilen/entziehen, ein
    Suchfeld findet einzelne Rechte. Beschreibungen kommen übersetzt aus den
    Locale-Dateien (rights.*), sonst aus dem Rechtekatalog der Datenbank.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="1000" :fullscreen="mobile" scrollable persistent @update:model-value="tryClose">
        <v-card>
            <v-card-title class="d-flex align-center ga-2 bg-primary text-white">
                <v-icon>{{ isNew ? 'mdi-shield-plus-outline' : 'mdi-shield-edit-outline' }}</v-icon>
                {{ isNew ? t('AdminView.groupDialog.titleNew') : t('AdminView.groupDialog.titleEdit') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" @click="tryClose(false)" />
            </v-card-title>

            <v-card-text class="pa-4">
                <v-form ref="formRef" v-model="formValid" @submit.prevent="save">
                    <v-row>
                        <v-col cols="12" md="7">
                            <v-text-field
                                v-model="form.name"
                                :label="t('AdminView.groupDialog.name')"
                                :rules="[rules.required, rules.unique]"
                                variant="outlined"
                                density="comfortable"
                                prepend-inner-icon="mdi-shield-account-outline"
                                autofocus
                            />
                            <v-text-field
                                v-model="form.description"
                                :label="t('AdminView.groupDialog.description')"
                                :hint="t('AdminView.groupDialog.descriptionHint')"
                                variant="outlined"
                                density="comfortable"
                                prepend-inner-icon="mdi-text"
                            />

                            <!-- Rechte -->
                            <div class="d-flex flex-wrap align-center ga-2 mt-2 mb-2">
                                <span class="section-title mb-0">{{ t('AdminView.groupDialog.sectionRights') }}</span>
                                <v-chip size="small" variant="tonal" color="primary">{{ t('AdminView.groupDialog.grantedOf', { granted: form.rights.length, total: allRightNames.length }) }}</v-chip>
                                <v-spacer />
                                <v-btn size="small" variant="text" @click="form.rights = [...allRightNames]">{{ t('AdminView.groupDialog.grantAll') }}</v-btn>
                                <v-btn size="small" variant="text" @click="form.rights = []">{{ t('AdminView.groupDialog.revokeAll') }}</v-btn>
                            </div>
                            <div class="d-flex flex-wrap align-center ga-3 mb-2">
                                <v-text-field
                                    v-model="rightsSearch"
                                    :placeholder="t('AdminView.groupDialog.rightsSearch')"
                                    prepend-inner-icon="mdi-magnify"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    clearable
                                    class="flex-grow-1"
                                />
                                <v-switch v-model="onlyGranted" :label="t('AdminView.groupDialog.showOnlyGranted')" density="compact" hide-details color="primary" />
                            </div>

                            <v-expansion-panels v-model="openPanels" multiple variant="accordion" class="rights-panels">
                                <v-expansion-panel v-for="cat in visibleCategories" :key="cat.name" :value="cat.name">
                                    <v-expansion-panel-title class="py-2">
                                        <div class="d-flex align-center ga-2 w-100">
                                            <v-checkbox-btn
                                                :model-value="categoryState(cat).all"
                                                :indeterminate="categoryState(cat).some && !categoryState(cat).all"
                                                color="primary"
                                                density="compact"
                                                class="flex-grow-0"
                                                @click.stop="toggleCategory(cat)"
                                            />
                                            <span class="font-weight-medium">{{ categoryLabel(cat) }}</span>
                                            <v-chip size="x-small" variant="tonal" class="ms-auto me-2">{{ categoryState(cat).granted }} / {{ cat.rights.length }}</v-chip>
                                        </div>
                                    </v-expansion-panel-title>
                                    <v-expansion-panel-text>
                                        <v-list density="compact" class="py-0">
                                            <v-list-item v-for="r in cat.rights" :key="r.name" class="px-1" @click="toggleRight(r.name)">
                                                <template #prepend>
                                                    <v-checkbox-btn :model-value="form.rights.includes(r.name)" color="primary" density="compact" @click.stop="toggleRight(r.name)" />
                                                </template>
                                                <v-list-item-title class="text-wrap text-body-2">{{ rightLabel(r) }}</v-list-item-title>
                                                <v-list-item-subtitle class="text-caption">{{ r.name }}</v-list-item-subtitle>
                                            </v-list-item>
                                        </v-list>
                                    </v-expansion-panel-text>
                                </v-expansion-panel>
                            </v-expansion-panels>
                            <div v-if="visibleCategories.length === 0" class="text-caption text-medium-emphasis pa-2">{{ t('AdminView.common.noResults') }}</div>
                        </v-col>

                        <v-col cols="12" md="5">
                            <entity-picker
                                v-model="form.client_ids"
                                :items="companyItems"
                                :label="t('AdminView.groupDialog.sectionCompanies')"
                                :hint="t('AdminView.groupDialog.companiesHint')"
                                :warning="t('AdminView.groupDialog.noCompaniesWarning')"
                                :empty-text="t('AdminView.companies.empty')"
                                class="mb-4"
                            />
                            <entity-picker
                                v-model="form.user_ids"
                                :items="userItems"
                                :label="t('AdminView.groupDialog.sectionMembers')"
                                :hint="t('AdminView.groupDialog.membersHint')"
                                :empty-text="t('AdminView.users.empty')"
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
import { groupRightsByCategory, isTrue, userDisplayName } from '../adminHelpers.js';
import * as toasts from '@/core/utils/toasts.js';
import * as alerts from '@/core/utils/alerts.js';
import EntityPicker from './entity-picker.component.vue';

const { t, te } = useI18n();
const { mobile } = useDisplay();
const store = oserpStore();

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    overview: { type: Object, required: true },
    group: { type: Object, default: null },
    templateGroup: { type: Object, default: null }
});
const emit = defineEmits(['update:modelValue', 'saved']);

const formRef = ref(null);
const formValid = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const rightsSearch = ref('');
const onlyGranted = ref(false);
const openPanels = ref([]);
let snapshot = '';

const form = reactive({ id: null, name: '', description: '', rights: [], user_ids: [], client_ids: [] });
const isNew = computed(() => !form.id);

const categories = computed(() => groupRightsByCategory(props.overview.master_rights));
const allRightNames = computed(() => categories.value.flatMap(c => c.rights.map(r => r.name)));

const rules = {
    required: v => !!(v && String(v).trim()) || t('AdminView.common.required'),
    unique: v => !props.overview.groups.some(g => g.id !== form.id && g.name.toLowerCase() === String(v || '').trim().toLowerCase()) || t('AdminView.groups.errors.GROUP_NAME_EXISTS')
};

function rightLabel(r) {
    const key = 'AdminView.rights.' + r.name;
    return te(key) ? t(key) : r.description;
}
function categoryLabel(cat) {
    const key = 'AdminView.rightCategories.' + cat.name;
    return te(key) ? t(key) : cat.description;
}

const visibleCategories = computed(() => {
    const q = (rightsSearch.value || '').trim().toLowerCase();
    return categories.value
        .map(cat => ({
            ...cat,
            rights: cat.rights.filter(r =>
                (!onlyGranted.value || form.rights.includes(r.name)) &&
                (!q || (r.name + ' ' + rightLabel(r) + ' ' + r.description).toLowerCase().includes(q)))
        }))
        .filter(cat => cat.rights.length > 0);
});

// Bei Suche alle Treffer-Kategorien aufklappen
watch([rightsSearch, onlyGranted], () => {
    if (rightsSearch.value || onlyGranted.value) openPanels.value = visibleCategories.value.map(c => c.name);
});

function categoryState(cat) {
    const granted = cat.rights.filter(r => form.rights.includes(r.name)).length;
    return { granted, all: granted === cat.rights.length && cat.rights.length > 0, some: granted > 0 };
}
function toggleCategory(cat) {
    const names = cat.rights.map(r => r.name);
    if (categoryState(cat).all) {
        form.rights = form.rights.filter(n => !names.includes(n));
    } else {
        form.rights = [...new Set([...form.rights, ...names])];
    }
}
function toggleRight(name) {
    form.rights = form.rights.includes(name) ? form.rights.filter(n => n !== name) : [...form.rights, name];
}

const companyItems = computed(() => props.overview.clients.map(c => ({ id: c.id, title: c.name, subtitle: c.dbname, badge: isTrue(c.is_default) ? t('AdminView.companies.defaultBadge') : null, badgeColor: 'primary' })));
const userItems = computed(() => props.overview.users.map(u => ({ id: u.id, title: userDisplayName(u), subtitle: u.login, badge: isTrue(u.is_admin) ? t('AdminView.users.admin') : null, badgeColor: 'primary' })));

function load() {
    const src = props.group || props.templateGroup;
    form.id = props.group ? props.group.id : null;
    form.name = props.group ? src.name : (src ? `${src.name} ${t('AdminView.groups.copySuffix')}` : '');
    form.description = src?.description || '';
    form.rights = src ? [...src.rights] : [];
    form.user_ids = props.group ? [...src.user_ids] : [];
    // Neue Gruppe: gilt in allen Firmen, damit die Rechte auch wirken
    form.client_ids = src ? [...src.client_ids] : props.overview.clients.map(c => c.id);
    rightsSearch.value = '';
    onlyGranted.value = false;
    openPanels.value = [];
    errorMessage.value = '';
    snapshot = JSON.stringify(form);
    nextTick(() => formRef.value?.resetValidation());
}
watch(() => props.modelValue, (open) => { if (open) load(); });

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
        const payload = { id: form.id, name: form.name.trim(), description: form.description.trim(), rights: form.rights, user_ids: form.user_ids, client_ids: form.client_ids };
        const result = await store.adminSaveGroup(payload);
        toasts.success(t(isNew.value ? 'AdminView.groups.created' : 'AdminView.groups.saved', { name: payload.name }));
        snapshot = JSON.stringify(form);
        emit('update:modelValue', false);
        emit('saved', result);
    } catch (e) {
        const key = 'AdminView.groups.errors.' + e.code;
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
}
.rights-panels { max-height: 480px; overflow-y: auto; }
/* v-selection-control wächst standardmäßig (flex: 1 0) — im Kategoriekopf soll die Überschrift direkt daneben stehen */
.rights-panels :deep(.v-expansion-panel-title .v-selection-control) { flex: 0 0 auto; }
</style>
