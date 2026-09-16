<!-- src/core/views/admin/components/admin-users.tab.vue -->
<template>
    <div>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('AdminView.users.intro') }}</p>

        <div class="d-flex flex-wrap align-center ga-3 mb-4">
            <v-btn-toggle v-model="filter" mandatory density="comfortable" variant="outlined" color="primary" divided>
                <v-btn value="all">{{ t('AdminView.users.filterAll') }} <v-chip size="x-small" class="ms-2" variant="flat">{{ users.length }}</v-chip></v-btn>
                <v-btn value="admins">{{ t('AdminView.users.filterAdmins') }} <v-chip size="x-small" class="ms-2" variant="flat">{{ users.filter(u => u.isAdmin).length }}</v-chip></v-btn>
                <v-btn value="problems">{{ t('AdminView.users.filterProblems') }} <v-chip size="x-small" class="ms-2" variant="flat">{{ users.filter(u => u.problems.length).length }}</v-chip></v-btn>
            </v-btn-toggle>
            <v-spacer />
            <v-text-field
                v-model="search"
                :label="t('AdminView.users.search')"
                prepend-inner-icon="mdi-magnify"
                variant="outlined"
                density="compact"
                clearable
                hide-details
                style="max-width: 300px"
            />
            <v-btn color="primary" variant="flat" prepend-icon="mdi-account-plus-outline" @click="openNewUser">
                {{ t('AdminView.users.new') }}
            </v-btn>
        </div>

        <v-card v-if="users.length === 0" variant="outlined" class="pa-8 text-center">
            <v-icon size="48" color="grey">mdi-account-multiple-outline</v-icon>
            <div class="text-h6 mt-2">{{ t('AdminView.users.empty') }}</div>
            <div class="text-body-2 text-medium-emphasis mb-4">{{ t('AdminView.users.emptyHint') }}</div>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-account-plus-outline" @click="openNewUser">{{ t('AdminView.users.new') }}</v-btn>
        </v-card>

        <v-data-table
            v-else
            :headers="headers"
            :items="filteredUsers"
            :search="search"
            item-value="id"
            :items-per-page="-1"
            density="comfortable"
            class="border rounded users-table"
            hide-default-footer
            :no-data-text="t('AdminView.common.noResults')"
            @click:row="(_, { item }) => openEditUser(item)"
        >
            <template #item.display="{ item }">
                <div class="d-flex align-center ga-3 py-1">
                    <v-avatar :color="item.isAdmin ? 'primary' : 'grey-lighten-1'" size="36">
                        <span class="text-caption text-white">{{ initials(item.display) }}</span>
                    </v-avatar>
                    <div>
                        <div class="font-weight-medium">
                            {{ item.display }}
                            <v-chip v-if="item.id === overview.admin.user_id" size="x-small" variant="tonal" color="primary" class="ms-1">{{ t('AdminView.common.you') }}</v-chip>
                        </div>
                        <div class="text-caption text-medium-emphasis">{{ item.login }}</div>
                    </div>
                </div>
            </template>
            <template #item.email="{ item }">
                <span class="text-body-2">{{ item.config.email || '' }}</span>
            </template>
            <template #item.companies="{ item }">
                <chip-list :items="item.companyNames" :empty-text="t('AdminView.users.noCompanies')" empty-color="warning" />
            </template>
            <template #item.groups="{ item }">
                <chip-list :items="item.groupNames" :empty-text="t('AdminView.users.noGroups')" empty-color="warning" />
            </template>
            <template #item.role="{ item }">
                <v-chip v-if="item.isAdmin" size="small" color="primary" variant="tonal" prepend-icon="mdi-shield-crown-outline">{{ t('AdminView.users.admin') }}</v-chip>
                <span v-else class="text-body-2 text-medium-emphasis">{{ t('AdminView.users.user') }}</span>
                <v-tooltip v-if="!item.hasPassword" :text="t('AdminView.users.noPassword')" location="top">
                    <template #activator="{ props: tip }">
                        <v-icon v-bind="tip" size="small" color="warning" class="ms-1">mdi-lock-open-variant-outline</v-icon>
                    </template>
                </v-tooltip>
                <v-tooltip v-else-if="item.hasDefaultPassword" :text="t('AdminView.users.defaultPassword')" location="top">
                    <template #activator="{ props: tip }">
                        <v-icon v-bind="tip" size="small" color="warning" class="ms-1">mdi-shield-key-outline</v-icon>
                    </template>
                </v-tooltip>
            </template>
            <template #item.lastActive="{ item }">
                <span class="text-body-2 text-medium-emphasis">{{ item.lastActive || t('AdminView.users.never') }}</span>
            </template>
            <template #item.actions="{ item }">
                <v-menu location="bottom end">
                    <template #activator="{ props: menu }">
                        <v-btn v-bind="menu" icon="mdi-dots-vertical" variant="text" size="small" @click.stop />
                    </template>
                    <v-list density="compact">
                        <v-list-item prepend-icon="mdi-pencil-outline" @click="openEditUser(item)">
                            <v-list-item-title>{{ t('AdminView.users.edit') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item prepend-icon="mdi-lock-reset" @click="openEditUser(item, 'password')">
                            <v-list-item-title>{{ t('AdminView.users.setPassword') }}</v-list-item-title>
                        </v-list-item>
                        <v-divider />
                        <v-list-item prepend-icon="mdi-delete-outline" class="text-error" :disabled="item.id === overview.admin.user_id" @click="confirmDelete(item)">
                            <v-list-item-title>{{ t('AdminView.users.delete') }}</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-menu>
            </template>
        </v-data-table>

        <user-edit-dialog
            v-model="dialogOpen"
            :overview="overview"
            :user="editingUser"
            :focus-password="focusPassword"
            @saved="onSaved"
        />
    </div>
</template>

<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { initials, isTrue, relativeTime, userDisplayName } from '../adminHelpers.js';
import * as toasts from '@/core/utils/toasts.js';
import * as alerts from '@/core/utils/alerts.js';
import UserEditDialog from './user-edit.dialog.vue';
import ChipList from './chip-list.component.vue';

const { t, locale } = useI18n();
const store = oserpStore();

const props = defineProps({
    overview: { type: Object, required: true },
    openNew: { type: Number, default: 0 },
    focusId: { type: Number, default: null }
});
const emit = defineEmits(['reload']);

const search = ref('');
const filter = ref('all');
const dialogOpen = ref(false);
const editingUser = ref(null);
const focusPassword = ref(false);

const headers = computed(() => [
    { title: t('AdminView.users.colUser'), key: 'display', sortable: true },
    { title: t('AdminView.users.colEmail'), key: 'email', sortable: true, value: u => u.config.email || '' },
    { title: t('AdminView.users.colCompanies'), key: 'companies', sortable: false },
    { title: t('AdminView.users.colGroups'), key: 'groups', sortable: false },
    { title: t('AdminView.users.colRole'), key: 'role', sortable: true, value: u => (u.isAdmin ? 0 : 1) },
    { title: t('AdminView.users.colLastActive'), key: 'lastActive', sortable: true, value: u => u.last_active || '' },
    { title: '', key: 'actions', sortable: false, align: 'end', width: 56 }
]);

// Anzeige-Modell mit aufgelösten Namen und Befunden
const users = computed(() => {
    const groupsById = Object.fromEntries(props.overview.groups.map(g => [g.id, g]));
    const clientsById = Object.fromEntries(props.overview.clients.map(c => [c.id, c]));
    return props.overview.users.map(u => {
        const problems = [];
        if (u.client_ids.length === 0) problems.push('company');
        if (u.group_ids.length === 0) problems.push('group');
        if (!isTrue(u.has_password)) problems.push('password');
        if (isTrue(u.has_default_password)) problems.push('default_password');
        return {
            ...u,
            display: userDisplayName(u),
            isAdmin: isTrue(u.is_admin),
            hasPassword: isTrue(u.has_password),
            hasDefaultPassword: isTrue(u.has_default_password),
            companyNames: u.client_ids.map(id => clientsById[id]?.name).filter(Boolean),
            groupNames: u.group_ids.map(id => groupsById[id]?.name).filter(Boolean),
            lastActive: relativeTime(u.last_active, locale.value),
            problems
        };
    });
});

const filteredUsers = computed(() => {
    if (filter.value === 'admins') return users.value.filter(u => u.isAdmin);
    if (filter.value === 'problems') return users.value.filter(u => u.problems.length);
    return users.value;
});

function openNewUser() {
    editingUser.value = null;
    focusPassword.value = false;
    dialogOpen.value = true;
}
function openEditUser(user, section = null) {
    editingUser.value = props.overview.users.find(u => u.id === user.id) || null;
    focusPassword.value = section === 'password';
    dialogOpen.value = true;
}
function onSaved() {
    emit('reload');
}

async function confirmDelete(user) {
    const result = await alerts.question(
        t('AdminView.users.deleteText'),
        t('AdminView.users.deleteTitle', { login: user.login }),
        t('AdminView.common.delete'),
        t('AdminView.common.cancel')
    );
    if (!result.isConfirmed) return;
    try {
        await store.adminDeleteUser(user.id);
        toasts.success(t('AdminView.users.deleted', { login: user.login }));
        emit('reload');
    } catch (e) {
        const key = 'AdminView.users.errors.' + e.code;
        toasts.error(t(key) !== key ? t(key) : (e.message || e.code));
        if (e.code === 'NOT_FOUND') emit('reload');
    }
}

watch(() => props.openNew, (n) => { if (n > 0) openNewUser(); });
watch(() => props.focusId, (id) => { if (id) openEditUser({ id }); });
onMounted(() => {
    if (props.openNew > 0) openNewUser();
    else if (props.focusId) openEditUser({ id: props.focusId });
});
</script>

<style scoped>
.users-table :deep(tbody tr) { cursor: pointer; }
</style>
