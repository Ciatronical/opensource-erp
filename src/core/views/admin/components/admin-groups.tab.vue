<!-- src/core/views/admin/components/admin-groups.tab.vue -->
<template>
    <div>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('AdminView.groups.intro') }}</p>

        <div class="d-flex flex-wrap align-center ga-3 mb-4">
            <v-text-field
                v-model="search"
                :label="t('AdminView.groups.search')"
                prepend-inner-icon="mdi-magnify"
                variant="outlined"
                density="compact"
                clearable
                hide-details
                style="max-width: 300px"
            />
            <v-spacer />
            <v-btn color="primary" variant="flat" prepend-icon="mdi-shield-plus-outline" @click="openNewGroup()">
                {{ t('AdminView.groups.new') }}
            </v-btn>
        </div>

        <v-card v-if="groups.length === 0" variant="outlined" class="pa-8 text-center">
            <v-icon size="48" color="grey">mdi-shield-account-outline</v-icon>
            <div class="text-h6 mt-2">{{ t('AdminView.groups.empty') }}</div>
            <div class="text-body-2 text-medium-emphasis mb-4">{{ t('AdminView.groups.emptyHint') }}</div>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-shield-plus-outline" @click="openNewGroup()">{{ t('AdminView.groups.new') }}</v-btn>
        </v-card>

        <v-data-table
            v-else
            :headers="headers"
            :items="groups"
            :search="search"
            item-value="id"
            :items-per-page="-1"
            density="comfortable"
            class="border rounded groups-table"
            hide-default-footer
            :no-data-text="t('AdminView.common.noResults')"
            @click:row="(_, { item }) => openEditGroup(item)"
        >
            <template #item.name="{ item }">
                <div class="py-1">
                    <div class="font-weight-medium d-flex align-center ga-2">
                        <v-icon size="small" color="primary">mdi-shield-account-outline</v-icon>{{ item.name }}
                    </div>
                    <div v-if="item.description" class="text-caption text-medium-emphasis">{{ item.description }}</div>
                </div>
            </template>
            <template #item.rights="{ item }">
                <div style="min-width: 160px">
                    <div class="text-caption mb-1">{{ t('AdminView.groups.rightsCount', { granted: item.rights.length, total: totalRights }) }}</div>
                    <v-progress-linear :model-value="totalRights ? item.rights.length / totalRights * 100 : 0" color="primary" height="6" rounded />
                </div>
            </template>
            <template #item.members="{ item }">
                <chip-list :items="item.memberNames" :empty-text="t('AdminView.groups.noMembers')" />
            </template>
            <template #item.companies="{ item }">
                <chip-list :items="item.companyNames" :empty-text="t('AdminView.groups.noCompanies')" empty-color="warning" />
            </template>
            <template #item.actions="{ item }">
                <v-menu location="bottom end">
                    <template #activator="{ props: menu }">
                        <v-btn v-bind="menu" icon="mdi-dots-vertical" variant="text" size="small" @click.stop />
                    </template>
                    <v-list density="compact">
                        <v-list-item prepend-icon="mdi-pencil-outline" @click="openEditGroup(item)">
                            <v-list-item-title>{{ t('AdminView.common.edit') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item prepend-icon="mdi-content-duplicate" @click="openNewGroup(item)">
                            <v-list-item-title>{{ t('AdminView.groups.duplicate') }}</v-list-item-title>
                        </v-list-item>
                        <v-divider />
                        <v-list-item prepend-icon="mdi-delete-outline" class="text-error" @click="confirmDelete(item)">
                            <v-list-item-title>{{ t('AdminView.groups.delete') }}</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-menu>
            </template>
        </v-data-table>

        <group-edit-dialog v-model="dialogOpen" :overview="overview" :group="editingGroup" :template-group="templateGroup" @saved="$emit('reload')" />
    </div>
</template>

<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { isTrue, userDisplayName } from '../adminHelpers.js';
import * as toasts from '@/core/utils/toasts.js';
import * as alerts from '@/core/utils/alerts.js';
import GroupEditDialog from './group-edit.dialog.vue';
import ChipList from './chip-list.component.vue';

const { t } = useI18n();
const store = oserpStore();

const props = defineProps({
    overview: { type: Object, required: true },
    openNew: { type: Number, default: 0 },
    focusId: { type: Number, default: null }
});
const emit = defineEmits(['reload']);

const search = ref('');
const dialogOpen = ref(false);
const editingGroup = ref(null);
const templateGroup = ref(null);

const totalRights = computed(() => props.overview.master_rights.filter(r => !isTrue(r.category)).length);

const headers = computed(() => [
    { title: t('AdminView.groups.colGroup'), key: 'name', sortable: true },
    { title: t('AdminView.groups.colRights'), key: 'rights', sortable: true, value: g => g.rights.length },
    { title: t('AdminView.groups.colMembers'), key: 'members', sortable: false },
    { title: t('AdminView.groups.colCompanies'), key: 'companies', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end', width: 56 }
]);

const groups = computed(() => {
    const usersById = Object.fromEntries(props.overview.users.map(u => [u.id, u]));
    const clientsById = Object.fromEntries(props.overview.clients.map(c => [c.id, c]));
    return props.overview.groups.map(g => ({
        ...g,
        memberNames: g.user_ids.map(id => usersById[id] ? userDisplayName(usersById[id]) : null).filter(Boolean),
        companyNames: g.client_ids.map(id => clientsById[id]?.name).filter(Boolean)
    }));
});

function openNewGroup(template = null) {
    editingGroup.value = null;
    templateGroup.value = template ? props.overview.groups.find(g => g.id === template.id) : null;
    dialogOpen.value = true;
}
function openEditGroup(group) {
    editingGroup.value = props.overview.groups.find(g => g.id === group.id) || null;
    templateGroup.value = null;
    dialogOpen.value = true;
}

async function confirmDelete(group) {
    const result = await alerts.question(t('AdminView.groups.deleteText'), t('AdminView.groups.deleteTitle', { name: group.name }), t('AdminView.common.delete'), t('AdminView.common.cancel'));
    if (!result.isConfirmed) return;
    try {
        await store.adminDeleteGroup(group.id);
        toasts.success(t('AdminView.groups.deleted', { name: group.name }));
        emit('reload');
    } catch (e) {
        const key = 'AdminView.groups.errors.' + e.code;
        toasts.error(t(key) !== key ? t(key) : (e.message || e.code));
        if (e.code === 'NOT_FOUND') emit('reload');
    }
}

watch(() => props.openNew, (n) => { if (n > 0) openNewGroup(); });
watch(() => props.focusId, (id) => { if (id) openEditGroup({ id }); });
onMounted(() => {
    if (props.openNew > 0) openNewGroup();
    else if (props.focusId) openEditGroup({ id: props.focusId });
});
</script>

<style scoped>
.groups-table :deep(tbody tr) { cursor: pointer; }
</style>
