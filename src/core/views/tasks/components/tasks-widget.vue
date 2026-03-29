<!-- src/core/views/tasks/components/tasks-widget.vue -->
<!-- Kompaktes Dashboard-Widget für Wiedervorlagen -->
<template>
    <v-card class="tasks-widget" variant="outlined">
        <!-- Header -->
        <v-card-title class="d-flex align-center pa-3 bg-amber-lighten-5">
            <v-icon color="amber-darken-2" class="me-2">
                mdi-clipboard-text-clock
            </v-icon>
            <span class="text-subtitle-1 font-weight-bold">
                {{ t('TasksWidget.title') }}
            </span>
            <v-spacer />
            <v-btn
                icon
                size="small"
                variant="text"
                :to="{ name: 'tasks' }"
                :title="t('TasksWidget.openAll')"
            >
                <v-icon>mdi-arrow-expand</v-icon>
            </v-btn>
        </v-card-title>

        <v-divider />

        <!-- Loading -->
        <div v-if="loading" class="pa-4 text-center">
            <v-progress-circular indeterminate color="amber" size="32" />
        </div>

        <!-- Content -->
        <template v-else>
            <!-- Übersicht-Badges -->
            <v-card-text class="py-2">
                <div class="d-flex justify-space-around">
                    <div class="text-center">
                        <v-badge
                            :content="counts.overdue"
                            :color="counts.overdue > 0 ? 'red' : 'grey'"
                            inline
                        >
                            <v-icon
                                :color="counts.overdue > 0 ? 'red' : 'grey'"
                                size="small"
                            >
                                mdi-alert-circle
                            </v-icon>
                        </v-badge>
                        <div class="text-caption text-grey mt-1">
                            {{ t('TasksWidget.overdue') }}
                        </div>
                    </div>
                    <div class="text-center">
                        <v-badge
                            :content="counts.today"
                            :color="counts.today > 0 ? 'amber' : 'grey'"
                            inline
                        >
                            <v-icon
                                :color="counts.today > 0 ? 'amber' : 'grey'"
                                size="small"
                            >
                                mdi-calendar-today
                            </v-icon>
                        </v-badge>
                        <div class="text-caption text-grey mt-1">
                            {{ t('TasksWidget.today') }}
                        </div>
                    </div>
                    <div class="text-center">
                        <v-badge
                            :content="counts.upcoming"
                            :color="counts.upcoming > 0 ? 'green' : 'grey'"
                            inline
                        >
                            <v-icon
                                :color="counts.upcoming > 0 ? 'green' : 'grey'"
                                size="small"
                            >
                                mdi-calendar-arrow-right
                            </v-icon>
                        </v-badge>
                        <div class="text-caption text-grey mt-1">
                            {{ t('TasksWidget.upcoming') }}
                        </div>
                    </div>
                </div>
            </v-card-text>

            <v-divider />

            <!-- Aktuelle Items -->
            <v-list density="compact" class="widget-list">
                <!-- Keine Items -->
                <v-list-item v-if="displayItems.length === 0">
                    <v-list-item-title class="text-center text-grey py-4">
                        <v-icon class="mb-1">mdi-check-all</v-icon>
                        <div>{{ t('TasksWidget.noItems') }}</div>
                    </v-list-item-title>
                </v-list-item>

                <!-- Items -->
                <v-list-item
                    v-for="item in displayItems"
                    :key="item.id"
                    :class="`widget-item widget-item-${item.priority}`"
                    @click="openItem(item)"
                >
                    <template #prepend>
                        <v-icon
                            :color="getPriorityColor(item.priority)"
                            size="small"
                        >
                            {{ getPriorityIcon(item.priority) }}
                        </v-icon>
                    </template>

                    <v-list-item-title class="text-body-2">
                        {{ item.note?.subject || '-' }}
                    </v-list-item-title>

                    <v-list-item-subtitle class="text-caption">
                        <span :class="`text-${getPriorityColor(item.priority)}`">
                            {{ formatDate(item.task_date) }}
                        </span>
                        <span v-if="item.links?.[0]?.trans_info" class="ms-2">
                            · {{ item.links[0].trans_info }}
                        </span>
                    </v-list-item-subtitle>

                    <template #append>
                        <v-btn
                            icon
                            size="x-small"
                            variant="text"
                            color="success"
                            @click.stop="markDone(item)"
                            :title="t('TasksWidget.markDone')"
                        >
                            <v-icon size="small">mdi-check</v-icon>
                        </v-btn>
                    </template>
                </v-list-item>
            </v-list>

            <!-- Mehr anzeigen -->
            <v-card-actions v-if="totalItems > maxDisplay" class="pa-2">
                <v-btn
                    block
                    variant="text"
                    size="small"
                    color="primary"
                    :to="{ name: 'tasks' }"
                >
                    {{ t('TasksWidget.showAll', { count: totalItems }) }}
                    <v-icon end>mdi-arrow-right</v-icon>
                </v-btn>
            </v-card-actions>
        </template>

        <!-- Quick Add Button -->
        <v-fab
            color="amber"
            icon="mdi-plus"
            size="small"
            location="bottom end"
            absolute
            class="me-2 mb-2"
            @click="openCreateDialog"
            :title="t('TasksWidget.quickAdd')"
        />

        <!-- Quick Create Dialog -->
        <tasks-dialog
            v-model="dialogOpen"
            :employees="employees"
            @save="onSave"
        />
    </v-card>
</template>

<script setup>
// src/core/views/tasks/components/tasks-widget.vue
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { oserpStore } from '@/core/stores/oserp.store.js';
import TasksDialog from './tasks-dialog.vue';

const { t, d } = useI18n();
const router = useRouter();
const store = oserpStore();

const props = defineProps({
    maxDisplay: {
        type: Number,
        default: 5
    }
});

const emit = defineEmits(['refresh']);

// State
const loading = ref(false);
const dialogOpen = ref(false);
const dashboardData = ref({
    overdue: [],
    today: [],
    upcoming: [],
    counts: { overdue: 0, today: 0, upcoming: 0 }
});

// Computed
const counts = computed(() => dashboardData.value.counts || {});

const displayItems = computed(() => {
    // Priorisiert: Überfällig > Heute > Kommend
    const all = [
        ...(dashboardData.value.overdue || []).map(i => ({ ...i, priority: 'overdue' })),
        ...(dashboardData.value.today || []).map(i => ({ ...i, priority: 'today' })),
        ...(dashboardData.value.upcoming || []).map(i => ({ ...i, priority: 'upcoming' }))
    ];
    return all.slice(0, props.maxDisplay);
});

const totalItems = computed(() => {
    return (counts.value.overdue || 0) +
           (counts.value.today || 0) +
           (counts.value.upcoming || 0);
});

const employees = computed(() => {
    return store.session?.company_config?.employees || [];
});

// Methods
async function loadDashboard() {
    loading.value = true;
    try {
        const result = await store.apiCall('task', 'getDashboard', {});
        if (result.success) {
            dashboardData.value = result;
        }
    } catch (error) {
        console.error('Error loading tasks dashboard:', error);
    } finally {
        loading.value = false;
    }
}

async function markDone(item) {
    try {
        await store.apiCall('task', 'markDone', { id: item.id });
        loadDashboard();
        emit('refresh');
    } catch (error) {
        console.error('Error marking done:', error);
    }
}

function openItem(item) {
    router.push({ name: 'tasks', query: { edit: item.id } });
}

function openCreateDialog() {
    dialogOpen.value = true;
}

async function onSave(data) {
    try {
        await store.apiCall('task', 'create', data);
        dialogOpen.value = false;
        loadDashboard();
        emit('refresh');
    } catch (error) {
        console.error('Error creating tasks:', error);
    }
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    if (date.toDateString() === today.toDateString()) {
        return t('TasksWidget.today');
    }

    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    if (date.toDateString() === tomorrow.toDateString()) {
        return t('TasksWidget.tomorrow');
    }

    const diffDays = Math.ceil((date - today) / (1000 * 60 * 60 * 24));
    if (diffDays < 0) {
        return t('TasksWidget.daysAgo', { days: Math.abs(diffDays) });
    }

    return d(date, 'short');
}

function getPriorityColor(priority) {
    const colors = {
        overdue: 'red',
        today: 'amber-darken-2',
        upcoming: 'green'
    };
    return colors[priority] || 'grey';
}

function getPriorityIcon(priority) {
    const icons = {
        overdue: 'mdi-alert-circle',
        today: 'mdi-calendar-today',
        upcoming: 'mdi-calendar-clock'
    };
    return icons[priority] || 'mdi-calendar';
}

// Lifecycle
onMounted(() => {
    loadDashboard();
});

// Expose für Parent-Komponente
defineExpose({ refresh: loadDashboard });
</script>

<style scoped>
.tasks-widget {
    position: relative;
    overflow: visible;
}

.widget-list {
    max-height: 280px;
    overflow-y: auto;
}

.widget-item {
    border-left: 3px solid transparent;
    transition: all 0.2s;
}

.widget-item:hover {
    background-color: #f5f5f5;
}

.widget-item-overdue {
    border-left-color: #f44336;
    background-color: #ffebee;
}

.widget-item-today {
    border-left-color: #ffc107;
    background-color: #fff8e1;
}

.widget-item-upcoming {
    border-left-color: #4caf50;
}
</style>
