<!-- src/core/views/config/tabs/employees.tab.vue -->
<template>
    <div>
        <v-alert
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-information-outline"
        >
            {{ $t('employeesTab.intro') }}
        </v-alert>

        <!-- Kopfzeile: Filter + Suche -->
        <div class="d-flex flex-wrap align-center ga-3 mb-4">
            <v-btn-toggle
                v-model="statusFilter"
                mandatory
                density="comfortable"
                variant="outlined"
                color="primary"
                divided
            >
                <v-btn value="active" prepend-icon="mdi-account-check">
                    {{ $t('employeesTab.filterActive') }}
                    <v-chip size="x-small" class="ms-2" variant="flat">{{ activeCount }}</v-chip>
                </v-btn>
                <v-btn value="obsolete" prepend-icon="mdi-account-off">
                    {{ $t('employeesTab.filterObsolete') }}
                    <v-chip size="x-small" class="ms-2" variant="flat">{{ obsoleteCount }}</v-chip>
                </v-btn>
                <v-btn value="all" prepend-icon="mdi-account-group">
                    {{ $t('employeesTab.filterAll') }}
                    <v-chip size="x-small" class="ms-2" variant="flat">{{ employees.length }}</v-chip>
                </v-btn>
            </v-btn-toggle>

            <v-spacer />

            <v-text-field
                v-model="localSearch"
                :label="$t('employeesTab.search')"
                prepend-inner-icon="mdi-magnify"
                variant="outlined"
                density="compact"
                clearable
                hide-details
                style="max-width: 320px;"
            />
        </div>

        <!-- Ladezustand -->
        <v-skeleton-loader
            v-if="loading"
            type="table-row@6"
            class="border rounded"
        />

        <!-- Tabelle -->
        <v-data-table
            v-else
            :headers="headers"
            :items="filteredEmployees"
            :search="effectiveSearch"
            item-value="id"
            :items-per-page="-1"
            density="comfortable"
            class="border rounded"
            hide-default-footer
            :no-data-text="$t('employeesTab.noResults')"
        >
            <!-- Name (mit Fallback auf Login) -->
            <template #item.name="{ item }">
                <div class="d-flex align-center ga-2">
                    <v-avatar :color="item.obsolete ? 'grey-lighten-1' : 'primary'" size="32">
                        <span class="text-caption text-white">{{ initials(item) }}</span>
                    </v-avatar>
                    <div>
                        <div :class="{ 'text-decoration-line-through text-medium-emphasis': item.obsolete }">
                            {{ item.name || item.login }}
                        </div>
                        <div v-if="item.name" class="text-caption text-medium-emphasis">{{ item.login }}</div>
                    </div>
                </div>
            </template>

            <!-- Verkäufer-Kennzeichen -->
            <template #item.sales="{ item }">
                <v-icon v-if="isTrue(item.sales)" color="success" size="small">mdi-check-circle</v-icon>
                <v-icon v-else color="grey-lighten-1" size="small">mdi-minus</v-icon>
            </template>

            <!-- Status -->
            <template #item.status="{ item }">
                <v-chip
                    :color="item.obsolete ? 'grey' : 'success'"
                    size="small"
                    variant="tonal"
                >
                    {{ item.obsolete ? $t('employeesTab.statusObsolete') : $t('employeesTab.statusActive') }}
                </v-chip>
            </template>

            <!-- Aktion: obsolet umschalten -->
            <template #item.action="{ item }">
                <v-switch
                    :model-value="!item.obsolete"
                    :loading="savingId === item.id"
                    :disabled="savingId === item.id"
                    color="success"
                    density="compact"
                    hide-details
                    inset
                    @update:model-value="(val) => toggleObsolete(item, !val)"
                />
            </template>
        </v-data-table>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import * as toasts from '@/core/utils/toasts.js';

const { t } = useI18n();

// searchQuery = globale Suche aus der Sidebar; wirkt hier zusätzlich zur lokalen Suche.
const props = defineProps({
    searchQuery: {
        type: String,
        default: ''
    }
});

const employees = ref([]);
const loading = ref(true);
const savingId = ref(null);
const localSearch = ref('');
const statusFilter = ref('all');
// Gerade umgeschaltete Mitarbeiter bleiben sichtbar, auch wenn sie nicht mehr
// zum aktiven Filter passen — sonst verschwindet die Zeile unter dem Cursor und
// der Schalter wirkt, als hätte er nicht funktioniert.
const recentlyToggled = ref(new Set());

const headers = computed(() => [
    { title: t('employeesTab.colName'), key: 'name', sortable: true },
    { title: t('employeesTab.colSales'), key: 'sales', sortable: true, align: 'center', width: 110 },
    { title: t('employeesTab.colStatus'), key: 'status', sortable: false, align: 'center', width: 140 },
    { title: t('employeesTab.colActive'), key: 'action', sortable: false, align: 'end', width: 120 }
]);

// PostgreSQL liefert Booleans u. U. als 't'/'f' — robust normalisieren.
function isTrue(v) {
    return v === true || v === 'true' || v === 't' || v === '1' || v === 1;
}

function initials(item) {
    const src = (item.name || item.login || '?').trim();
    const parts = src.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return src.slice(0, 2).toUpperCase();
}

const activeCount = computed(() => employees.value.filter(e => !e.obsolete).length);
const obsoleteCount = computed(() => employees.value.filter(e => e.obsolete).length);

const filteredEmployees = computed(() => {
    if (statusFilter.value === 'all') return employees.value;
    return employees.value.filter(e =>
        recentlyToggled.value.has(e.id) ||
        (statusFilter.value === 'active' ? !e.obsolete : e.obsolete)
    );
});

// Beim Filterwechsel die "Nachzügler" räumen — dann greift der Filter sauber.
watch(statusFilter, () => recentlyToggled.value.clear());

const effectiveSearch = computed(() => (localSearch.value || props.searchQuery || '').trim());

async function loadEmployees() {
    loading.value = true;
    recentlyToggled.value.clear();
    try {
        const { data } = await axios.post('/api/oserp_config/', { action: 'getEmployeesConfig' });
        if (data?.success) {
            employees.value = (data.payload?.results || []).map(e => ({
                ...e,
                obsolete: isTrue(e.obsolete)
            }));
        } else {
            toasts.error(data?.text || t('employeesTab.loadFailed'));
        }
    } catch (e) {
        toasts.error(t('employeesTab.loadFailed'));
    } finally {
        loading.value = false;
    }
}

async function toggleObsolete(item, obsolete) {
    savingId.value = item.id;
    try {
        const { data } = await axios.post('/api/oserp_config/', {
            action: 'setEmployeeObsolete',
            id: item.id,
            obsolete
        });
        if (data?.success) {
            item.obsolete = isTrue(data.payload?.results?.obsolete ?? obsolete);
            recentlyToggled.value.add(item.id);
            toasts.success(obsolete
                ? t('employeesTab.markedObsolete', { name: item.name || item.login })
                : t('employeesTab.markedActive', { name: item.name || item.login }));
        } else {
            toasts.error(data?.text || t('employeesTab.saveFailed'));
        }
    } catch (e) {
        toasts.error(t('employeesTab.saveFailed'));
    } finally {
        savingId.value = null;
    }
}

onMounted(loadEmployees);
</script>
