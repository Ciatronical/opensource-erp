<!-- src/core/views/admin/components/admin-overview.tab.vue -->
<!--
    Startseite der Verwaltung: Kennzahlen, Schnellaktionen und eine ehrliche
    Prüfung der Einrichtung (Benutzer ohne Firma, Gruppen ohne Wirkung, ...).
    Jeder Befund führt per Klick direkt zur Stelle, an der er behoben wird.
-->
<template>
    <div>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('AdminView.overview.intro') }}</p>

        <!-- Übergangsmodus: Admins noch nicht ausdrücklich festgelegt -->
        <v-alert
            v-if="overview.admin.legacy_mode"
            type="warning"
            variant="tonal"
            class="mb-4"
            icon="mdi-shield-alert-outline"
        >
            <div class="font-weight-bold mb-1">{{ t('AdminView.overview.legacyModeTitle') }}</div>
            <div class="text-body-2">{{ t('AdminView.overview.legacyModeText') }}</div>
            <v-btn class="mt-3" color="warning" variant="flat" size="small" :loading="claiming" prepend-icon="mdi-shield-check" @click="claimAdmin">
                {{ t('AdminView.overview.legacyModeAction') }}
            </v-btn>
        </v-alert>

        <!-- Kennzahlen -->
        <v-row class="mb-2">
            <v-col v-for="card in cards" :key="card.tab" cols="12" sm="4">
                <v-card variant="outlined" hover class="h-100 stat-card" @click="$emit('go', { tab: card.tab })">
                    <v-card-text class="d-flex align-center ga-4 pa-4">
                        <v-avatar color="primary" variant="tonal" size="48">
                            <v-icon :icon="card.icon" size="26" />
                        </v-avatar>
                        <div class="flex-grow-1">
                            <div class="text-h4 font-weight-bold lh-1">{{ card.count }}</div>
                            <div class="text-subtitle-2">{{ card.title }}</div>
                            <div class="text-caption text-medium-emphasis">{{ card.meta }}</div>
                        </div>
                        <v-icon color="grey">mdi-chevron-right</v-icon>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-row>
            <!-- Prüfung -->
            <v-col cols="12" md="7">
                <v-card variant="outlined" class="h-100">
                    <v-card-title class="d-flex align-center ga-2">
                        <v-icon :color="checks.length ? 'warning' : 'success'">
                            {{ checks.length ? 'mdi-clipboard-alert-outline' : 'mdi-clipboard-check-outline' }}
                        </v-icon>
                        {{ t('AdminView.overview.healthTitle') }}
                    </v-card-title>
                    <v-divider />
                    <v-card-text v-if="checks.length === 0" class="d-flex align-center ga-2 text-success">
                        <v-icon>mdi-check-circle-outline</v-icon>
                        {{ t('AdminView.overview.healthOk') }}
                    </v-card-text>
                    <v-list v-else density="comfortable">
                        <v-list-item v-for="check in checks" :key="check.key" :prepend-icon="check.icon">
                            <v-list-item-title class="text-wrap">{{ check.text }}</v-list-item-title>
                            <template #append>
                                <v-btn size="small" variant="tonal" color="primary" @click="$emit('go', check.target)">
                                    {{ t('AdminView.overview.fix') }}
                                </v-btn>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>

            <!-- Schnellaktionen + eigener Zugang -->
            <v-col cols="12" md="5">
                <v-card variant="outlined" class="mb-4">
                    <v-card-title class="d-flex align-center ga-2">
                        <v-icon>mdi-lightning-bolt-outline</v-icon>{{ t('AdminView.overview.quickActions') }}
                    </v-card-title>
                    <v-divider />
                    <v-list density="comfortable">
                        <v-list-item prepend-icon="mdi-account-plus-outline" @click="$emit('go', { tab: 'users', create: true })">
                            <v-list-item-title>{{ t('AdminView.overview.newUser') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item prepend-icon="mdi-shield-plus-outline" @click="$emit('go', { tab: 'groups', create: true })">
                            <v-list-item-title>{{ t('AdminView.overview.newGroup') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item prepend-icon="mdi-domain-plus" @click="$emit('go', { tab: 'companies', create: true })">
                            <v-list-item-title>{{ t('AdminView.overview.newCompany') }}</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-card>

                <v-card variant="outlined">
                    <v-card-title class="d-flex align-center ga-2">
                        <v-icon>mdi-account-key-outline</v-icon>{{ t('AdminView.overview.yourAccess') }}
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="text-body-2">
                        <div class="d-flex align-center ga-2 mb-2">
                            <v-avatar color="primary" size="32"><span class="text-caption text-white">{{ initials(overview.admin.login) }}</span></v-avatar>
                            <strong>{{ overview.admin.login }}</strong>
                        </div>
                        <div>{{ accessText }}</div>
                        <div v-if="overview.admin.settings_admins.length" class="text-caption text-medium-emphasis mt-2">
                            {{ t('AdminView.overview.settingsAdmins', { logins: overview.admin.settings_admins.join(', ') }) }}
                        </div>
                        <v-divider class="my-3" />
                        <div class="text-caption text-medium-emphasis">{{ t('AdminView.overview.authDb') }}</div>
                        <div class="font-mono">{{ overview.auth_db.name }} <span class="text-medium-emphasis">@ {{ overview.auth_db.host }}:{{ overview.auth_db.port }}</span></div>
                        <div class="text-caption text-medium-emphasis mt-1">{{ t('AdminView.overview.authDbHint') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card variant="tonal" color="primary" class="mt-4">
            <v-card-text class="d-flex ga-3">
                <v-icon>mdi-lightbulb-on-outline</v-icon>
                <div>
                    <div class="font-weight-bold">{{ t('AdminView.overview.howItWorks') }}</div>
                    <div class="text-body-2">{{ t('AdminView.overview.howItWorksText') }}</div>
                </div>
            </v-card-text>
        </v-card>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { initials, isTrue } from '../adminHelpers.js';
import * as toasts from '@/core/utils/toasts.js';

const { t } = useI18n();
const store = oserpStore();

const props = defineProps({
    overview: { type: Object, required: true }
});
const emit = defineEmits(['go', 'reload']);

const claiming = ref(false);

const cards = computed(() => {
    const o = props.overview;
    const admins = o.users.filter(u => isTrue(u.is_admin)).length;
    const active = o.clients.reduce((n, c) => n + Number(c.active_sessions || 0), 0);
    return [
        { tab: 'users', icon: 'mdi-account-multiple-outline', count: o.users.length, title: t('AdminView.overview.usersCard'), meta: t('AdminView.overview.usersMeta', { admins }) },
        { tab: 'groups', icon: 'mdi-shield-account-outline', count: o.groups.length, title: t('AdminView.overview.groupsCard'), meta: t('AdminView.overview.groupsMeta', { rights: o.master_rights.filter(r => !isTrue(r.category)).length }) },
        { tab: 'companies', icon: 'mdi-domain', count: o.clients.length, title: t('AdminView.overview.companiesCard'), meta: t('AdminView.overview.companiesMeta', { active }) }
    ];
});

const accessText = computed(() => {
    const src = props.overview.admin.source;
    if (src === 'settings') return t('AdminView.overview.yourAccessSettings');
    if (src === 'legacy_right') return t('AdminView.overview.yourAccessLegacy');
    return t('AdminView.overview.yourAccessExplicit');
});

// Prüfung der Einrichtung: jeder Befund mit Sprungziel
const checks = computed(() => {
    const o = props.overview;
    const out = [];
    const usersWithoutCompany = o.users.filter(u => u.client_ids.length === 0);
    const usersWithoutGroup = o.users.filter(u => u.group_ids.length === 0);
    const usersWithoutPassword = o.users.filter(u => !isTrue(u.has_password));
    const usersWithDefaultPassword = o.users.filter(u => isTrue(u.has_default_password));
    const companiesWithoutUsers = o.clients.filter(c => c.user_ids.length === 0);
    const groupsWithoutCompany = o.groups.filter(g => g.client_ids.length === 0);
    const hasDefault = o.clients.some(c => isTrue(c.is_default));

    if (usersWithoutCompany.length) out.push({ key: 'uwc', icon: 'mdi-account-off-outline', text: t('AdminView.overview.checks.usersWithoutCompany', { count: usersWithoutCompany.length }), target: { tab: 'users', id: usersWithoutCompany[0].id } });
    if (usersWithoutGroup.length) out.push({ key: 'uwg', icon: 'mdi-shield-off-outline', text: t('AdminView.overview.checks.usersWithoutGroup', { count: usersWithoutGroup.length }), target: { tab: 'users', id: usersWithoutGroup[0].id } });
    if (usersWithoutPassword.length) out.push({ key: 'uwp', icon: 'mdi-lock-open-variant-outline', text: t('AdminView.overview.checks.usersWithoutPassword', { count: usersWithoutPassword.length }), target: { tab: 'users', id: usersWithoutPassword[0].id } });
    // Standardpasswort aus der Einrichtung: solange es gilt, kommt jeder hinein, der den Namen kennt
    if (usersWithDefaultPassword.length) out.push({ key: 'udp', icon: 'mdi-shield-key-outline', text: t('AdminView.overview.checks.usersWithDefaultPassword', { count: usersWithDefaultPassword.length, login: usersWithDefaultPassword[0].login }), target: { tab: 'users', id: usersWithDefaultPassword[0].id } });
    if (companiesWithoutUsers.length) out.push({ key: 'cwu', icon: 'mdi-domain-off', text: t('AdminView.overview.checks.companiesWithoutUsers', { count: companiesWithoutUsers.length }), target: { tab: 'companies', id: companiesWithoutUsers[0].id } });
    if (groupsWithoutCompany.length) out.push({ key: 'gwc', icon: 'mdi-shield-alert-outline', text: t('AdminView.overview.checks.groupsWithoutCompany', { count: groupsWithoutCompany.length }), target: { tab: 'groups', id: groupsWithoutCompany[0].id } });
    if (o.clients.length > 1 && !hasDefault) out.push({ key: 'nd', icon: 'mdi-star-off-outline', text: t('AdminView.overview.checks.noDefaultCompany'), target: { tab: 'companies' } });
    return out;
});

/**
 * Sich selbst ausdrücklich als Administrator kennzeichnen (beendet den Übergangsmodus)
 */
async function claimAdmin() {
    const me = props.overview.users.find(u => u.id === props.overview.admin.user_id);
    if (!me) return;
    claiming.value = true;
    try {
        await store.adminSaveUser({ id: me.id, login: me.login, is_admin: true });
        toasts.success(t('AdminView.overview.legacyModeDone'));
        emit('reload');
    } catch (e) {
        toasts.error(e?.message || e?.code);
    } finally {
        claiming.value = false;
    }
}
</script>

<style scoped>
.stat-card { cursor: pointer; transition: border-color 0.15s ease; }
.stat-card:hover { border-color: rgb(var(--v-theme-primary)); }
.lh-1 { line-height: 1.1; }
.font-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
</style>
