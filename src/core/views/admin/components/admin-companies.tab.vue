<!-- src/core/views/admin/components/admin-companies.tab.vue -->
<template>
    <div>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('AdminView.companies.intro') }}</p>

        <div class="d-flex flex-wrap align-center ga-3 mb-4">
            <v-text-field
                v-model="search"
                :label="t('AdminView.companies.search')"
                prepend-inner-icon="mdi-magnify"
                variant="outlined"
                density="compact"
                clearable
                hide-details
                style="max-width: 300px"
            />
            <v-spacer />
            <v-btn color="primary" variant="flat" prepend-icon="mdi-domain-plus" @click="openNewCompany">
                {{ t('AdminView.companies.new') }}
            </v-btn>
        </div>

        <v-card v-if="companies.length === 0" variant="outlined" class="pa-8 text-center">
            <v-icon size="48" color="grey">mdi-domain</v-icon>
            <div class="text-h6 mt-2">{{ t('AdminView.companies.empty') }}</div>
            <div class="text-body-2 text-medium-emphasis mb-4">{{ t('AdminView.companies.emptyHint') }}</div>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-domain-plus" @click="openNewCompany">{{ t('AdminView.companies.new') }}</v-btn>
        </v-card>

        <v-row v-else>
            <v-col v-for="c in filteredCompanies" :key="c.id" cols="12" md="6" xl="4">
                <v-card variant="outlined" class="h-100 company-card" :class="{ 'company-card--current': c.isCurrent }">
                    <v-card-text class="pa-4">
                        <div class="d-flex align-start ga-3">
                            <v-avatar :color="c.isDefault ? 'primary' : 'grey-lighten-2'" size="44">
                                <v-icon :color="c.isDefault ? 'white' : 'grey-darken-2'">mdi-domain</v-icon>
                            </v-avatar>
                            <div class="flex-grow-1 min-w-0">
                                <div class="d-flex align-center flex-wrap ga-2">
                                    <span class="text-subtitle-1 font-weight-bold">{{ c.name }}</span>
                                    <v-tooltip v-if="c.isDefault" :text="t('AdminView.companies.defaultHint')" location="top">
                                        <template #activator="{ props: tip }">
                                            <v-chip v-bind="tip" size="x-small" color="primary" variant="flat" prepend-icon="mdi-star">{{ t('AdminView.companies.defaultBadge') }}</v-chip>
                                        </template>
                                    </v-tooltip>
                                    <v-chip v-if="c.isCurrent" size="x-small" variant="tonal" color="success" prepend-icon="mdi-account-check-outline">{{ t('AdminView.companies.current') }}</v-chip>
                                </div>
                                <div class="text-caption text-medium-emphasis font-mono text-truncate">
                                    {{ c.dbname }} @ {{ c.dbhost }}:{{ c.dbport }} ({{ c.dbuser }})
                                </div>
                            </div>
                            <v-menu location="bottom end">
                                <template #activator="{ props: menu }">
                                    <v-btn v-bind="menu" icon="mdi-dots-vertical" variant="text" size="small" />
                                </template>
                                <v-list density="compact">
                                    <v-list-item prepend-icon="mdi-pencil-outline" @click="openEditCompany(c)"><v-list-item-title>{{ t('AdminView.companies.edit') }}</v-list-item-title></v-list-item>
                                    <v-list-item prepend-icon="mdi-connection" @click="testConnection(c)"><v-list-item-title>{{ t('AdminView.companies.test') }}</v-list-item-title></v-list-item>
                                    <v-list-item prepend-icon="mdi-star-outline" :disabled="c.isDefault" @click="setDefault(c)"><v-list-item-title>{{ t('AdminView.companies.setDefault') }}</v-list-item-title></v-list-item>
                                    <v-list-item prepend-icon="mdi-database-sync-outline" @click="upgrade(c)"><v-list-item-title>{{ t('AdminView.companies.upgrade') }}</v-list-item-title></v-list-item>
                                    <v-divider />
                                    <v-list-item prepend-icon="mdi-delete-outline" class="text-error" :disabled="c.isCurrent" @click="openDelete(c)"><v-list-item-title>{{ t('AdminView.companies.delete') }}</v-list-item-title></v-list-item>
                                </v-list>
                            </v-menu>
                        </div>

                        <div class="d-flex flex-wrap ga-2 mt-3">
                            <v-chip size="small" variant="tonal" prepend-icon="mdi-account-multiple-outline" :color="c.user_ids.length ? undefined : 'warning'">{{ t('AdminView.companies.usersCount', { count: c.user_ids.length }) }}</v-chip>
                            <v-chip size="small" variant="tonal" prepend-icon="mdi-shield-account-outline" :color="c.group_ids.length ? undefined : 'warning'">{{ t('AdminView.companies.groupsCount', { count: c.group_ids.length }) }}</v-chip>
                            <v-chip v-if="Number(c.active_sessions) > 0" size="small" variant="tonal" color="success" prepend-icon="mdi-pulse">{{ t('AdminView.companies.activeSessions', { count: c.active_sessions }) }}</v-chip>
                        </div>

                        <div v-if="c.userNames.length" class="text-caption text-medium-emphasis mt-2 text-truncate">{{ c.userNames.join(', ') }}</div>

                        <v-alert v-if="testResults[c.id]" :type="testResults[c.id].type" variant="tonal" density="compact" class="mt-3 text-caption" closable @click:close="delete testResults[c.id]">
                            {{ testResults[c.id].text }}
                        </v-alert>
                    </v-card-text>
                    <v-progress-linear v-if="busyId === c.id" indeterminate color="primary" />
                </v-card>
            </v-col>
        </v-row>

        <company-edit-dialog v-model="dialogOpen" :overview="overview" :company="editingCompany" @saved="onSaved" />
        <company-delete-dialog v-model="deleteOpen" :company="deletingCompany" @deleted="$emit('reload')" />
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { isTrue, userDisplayName } from '../adminHelpers.js';
import * as toasts from '@/core/utils/toasts.js';
import * as alerts from '@/core/utils/alerts.js';
import CompanyEditDialog from './company-edit.dialog.vue';
import CompanyDeleteDialog from './company-delete.dialog.vue';

const { t } = useI18n();
const router = useRouter();
const store = oserpStore();

const props = defineProps({
    overview: { type: Object, required: true },
    openNew: { type: Number, default: 0 },
    focusId: { type: Number, default: null }
});
const emit = defineEmits(['reload']);

const search = ref('');
const dialogOpen = ref(false);
const editingCompany = ref(null);
const deleteOpen = ref(false);
const deletingCompany = ref(null);
const busyId = ref(null);
const testResults = reactive({});

const companies = computed(() => {
    const usersById = Object.fromEntries(props.overview.users.map(u => [u.id, u]));
    return props.overview.clients.map(c => ({
        ...c,
        isDefault: isTrue(c.is_default),
        isCurrent: c.id === props.overview.admin.client_id,
        userNames: c.user_ids.map(id => usersById[id] ? userDisplayName(usersById[id]) : null).filter(Boolean)
    }));
});
const filteredCompanies = computed(() => {
    const q = (search.value || '').trim().toLowerCase();
    if (!q) return companies.value;
    return companies.value.filter(c => (c.name + ' ' + c.dbname + ' ' + c.dbhost).toLowerCase().includes(q));
});

function openNewCompany() {
    editingCompany.value = null;
    dialogOpen.value = true;
}
function openEditCompany(c) {
    editingCompany.value = props.overview.clients.find(x => x.id === c.id) || null;
    dialogOpen.value = true;
}
function openDelete(c) {
    deletingCompany.value = c;
    deleteOpen.value = true;
}

/**
 * Nach dem Anlegen einer neuen Firma: Liste neu laden, optional direkt dorthin wechseln
 */
async function onSaved(result) {
    emit('reload');
    if (result?.switchTo) {
        try {
            await store.switchClient(result.switchTo);
            router.push({ name: 'startup' });
        } catch (e) {
            toasts.error(e?.message || e?.code);
        }
    }
}

async function testConnection(c) {
    busyId.value = c.id;
    try {
        const info = await store.adminTestClientConnection({ id: c.id });
        testResults[c.id] = info.has_oserp
            ? { type: 'success', text: t('AdminView.companies.testOk', { company: info.company || c.name, coa: info.coa || '?' }) }
            : { type: 'warning', text: t('AdminView.companies.testOkNoOserp') };
    } catch (e) {
        testResults[c.id] = { type: 'error', text: t('AdminView.companies.testFail', { error: e.message || e.code }) };
    } finally {
        busyId.value = null;
    }
}

async function setDefault(c) {
    busyId.value = c.id;
    try {
        await store.adminSaveClient({ id: c.id, name: c.name, dbhost: c.dbhost, dbport: c.dbport, dbname: c.dbname, dbuser: c.dbuser, dbpasswd: '', is_default: true });
        toasts.success(t('AdminView.companies.defaultSet', { name: c.name }));
        emit('reload');
    } catch (e) {
        toasts.error(e.message || e.code);
    } finally {
        busyId.value = null;
    }
}

async function upgrade(c) {
    busyId.value = c.id;
    try {
        const result = await store.adminUpgradeClientSchema(c.id);
        if (result?.warnings?.length) {
            alerts.warning(result.warnings.join('\n'), t('AdminView.companies.upgradeWarnings'));
        } else {
            toasts.success(t('AdminView.companies.upgraded', { name: c.name }));
        }
    } catch (e) {
        toasts.error(e.message || e.code);
    } finally {
        busyId.value = null;
    }
}

watch(() => props.openNew, (n) => { if (n > 0) openNewCompany(); });
watch(() => props.focusId, (id) => { if (id) openEditCompany({ id }); });
onMounted(() => {
    if (props.openNew > 0) openNewCompany();
    else if (props.focusId) openEditCompany({ id: props.focusId });
});
</script>

<style scoped>
.company-card { transition: border-color 0.15s ease; }
.company-card--current { border-color: rgb(var(--v-theme-success)); }
.font-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.min-w-0 { min-width: 0; }
</style>
