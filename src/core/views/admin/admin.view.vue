<!-- src/core/views/admin/admin.view.vue -->
<!--
    Systemadministration: Benutzer, Gruppen, Firmen (Ersatz für k9o admin.pl).
    Lädt die komplette Übersicht in einem Aufruf (getAdminOverview) und reicht
    sie an die Reiter weiter. Nach jeder Änderung wird neu geladen — die Daten
    sind klein, und so stimmen Zähler und Zuordnungen überall sofort.
-->
<template>
    <div>
        <navbar-view :title="t('AdminView.title')" :show-back-button="true" />

        <v-container fluid class="admin-container">
            <div class="d-flex flex-wrap align-center ga-3 mb-4">
                <div>
                    <h1 class="text-h5 font-weight-bold mb-0">{{ t('AdminView.title') }}</h1>
                    <div class="text-body-2 text-medium-emphasis">{{ t('AdminView.subtitle') }}</div>
                </div>
                <v-spacer />
                <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="reload">
                    {{ t('AdminView.common.reload') }}
                </v-btn>
            </div>

            <v-tabs v-model="activeTab" color="primary" class="mb-4 admin-tabs" show-arrows>
                <v-tab value="overview" prepend-icon="mdi-view-dashboard-outline">{{ t('AdminView.tabs.overview') }}</v-tab>
                <v-tab value="users" prepend-icon="mdi-account-multiple-outline">
                    {{ t('AdminView.tabs.users') }}
                    <v-chip v-if="overview" size="x-small" class="ms-2" variant="tonal">{{ overview.users.length }}</v-chip>
                </v-tab>
                <v-tab value="groups" prepend-icon="mdi-shield-account-outline">
                    {{ t('AdminView.tabs.groups') }}
                    <v-chip v-if="overview" size="x-small" class="ms-2" variant="tonal">{{ overview.groups.length }}</v-chip>
                </v-tab>
                <v-tab value="companies" prepend-icon="mdi-domain">
                    {{ t('AdminView.tabs.companies') }}
                    <v-chip v-if="overview" size="x-small" class="ms-2" variant="tonal">{{ overview.clients.length }}</v-chip>
                </v-tab>
            </v-tabs>

            <v-alert v-if="error" type="error" variant="tonal" class="mb-4">
                {{ error }}
                <template #append>
                    <v-btn size="small" variant="text" @click="reload">{{ t('AdminView.common.retry') }}</v-btn>
                </template>
            </v-alert>

            <v-skeleton-loader v-if="loading && !overview" type="card, table-row@4" />

            <template v-else-if="overview">
                <admin-overview-tab
                    v-if="activeTab === 'overview'"
                    :overview="overview"
                    @go="goTo"
                    @reload="reload"
                />
                <admin-users-tab
                    v-else-if="activeTab === 'users'"
                    :overview="overview"
                    :open-new="openNewCounter.users"
                    :focus-id="focusIds.users"
                    @reload="reload"
                />
                <admin-groups-tab
                    v-else-if="activeTab === 'groups'"
                    :overview="overview"
                    :open-new="openNewCounter.groups"
                    :focus-id="focusIds.groups"
                    @reload="reload"
                />
                <admin-companies-tab
                    v-else-if="activeTab === 'companies'"
                    :overview="overview"
                    :open-new="openNewCounter.companies"
                    :focus-id="focusIds.companies"
                    @reload="reload"
                />
            </template>
        </v-container>
    </div>
</template>

<script setup>
import { ref, reactive, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { oserpStore } from '@/core/stores/oserp.store.js';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import AdminOverviewTab from './components/admin-overview.tab.vue';
import AdminUsersTab from './components/admin-users.tab.vue';
import AdminGroupsTab from './components/admin-groups.tab.vue';
import AdminCompaniesTab from './components/admin-companies.tab.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const store = oserpStore();

const TABS = ['overview', 'users', 'groups', 'companies'];

const overview = ref(null);
const loading = ref(false);
const error = ref('');
const activeTab = ref(TABS.includes(route.query.tab) ? route.query.tab : 'overview');
// Zähler statt Boolean: jede Erhöhung öffnet den "Neu"-Dialog des Reiters erneut
const openNewCounter = reactive({ users: 0, groups: 0, companies: 0 });
// Datensatz, der nach dem Wechsel in einen Reiter direkt geöffnet werden soll
const focusIds = reactive({ users: null, groups: null, companies: null });

async function reload() {
    loading.value = true;
    error.value = '';
    try {
        overview.value = await store.adminOverview();
    } catch (e) {
        error.value = e?.message || t('AdminView.common.loadFailed');
    } finally {
        loading.value = false;
    }
}

/**
 * Sprung aus der Übersicht: Reiter wechseln, optional "Neu" öffnen oder Datensatz fokussieren
 *
 * @param {Object} target - { tab, create?: boolean, id?: number }
 */
function goTo(target) {
    activeTab.value = target.tab;
    if (target.create) openNewCounter[target.tab]++;
    if (target.id) focusIds[target.tab] = target.id;
}

// Reiter in der URL halten (Lesezeichen, Zurück-Taste); "new" nur einmal auswerten
watch(activeTab, (tab) => {
    const query = { ...route.query, tab };
    delete query.new;
    router.replace({ query });
});

onMounted(async () => {
    await reload();
    if (route.query.new && TABS.includes(activeTab.value) && activeTab.value !== 'overview') {
        openNewCounter[activeTab.value]++;
    }
});
</script>

<style scoped>
.admin-container {
    max-width: 1400px;
}
</style>
