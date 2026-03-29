<!-- src/core/views/follow-up/follow-up.view.vue -->
<template>
    <NavbarView />

    <!-- DEBUG INFO -->
    <v-alert v-if="debugInfo" color="warning" variant="tonal" closable @click:close="debugInfo = ''" class="ma-2">
        <strong>🔍 DRAG DEBUG:</strong> {{ debugInfo }}
    </v-alert>

    <v-container fluid class="pa-2 pa-md-4">
        <!-- Header mit Aktionen -->
        <v-row class="mb-4">
            <v-col cols="12" md="6">
                <h1 class="text-h5 text-md-h4 d-flex align-center">
                    <v-icon class="me-2" color="amber-darken-2">mdi-clipboard-text-clock</v-icon>
                    {{ t('FollowUpView.title') }}
                </h1>
            </v-col>
            <v-col cols="12" md="6" class="d-flex justify-end align-center gap-4 flex-wrap">
                <!-- Ansichten -->
                <v-btn-toggle v-model="viewMode" mandatory density="compact" color="primary" class="me-3">
                    <v-btn value="board" size="small">
                        <v-icon>mdi-view-column</v-icon>
                        <span class="d-none d-sm-inline ms-1">{{ t('FollowUpView.tabs.board') }}</span>
                    </v-btn>
                    <v-btn value="list" size="small">
                        <v-icon>mdi-format-list-bulleted</v-icon>
                        <span class="d-none d-sm-inline ms-1">{{ t('FollowUpView.tabs.list') }}</span>
                    </v-btn>
                    <v-btn value="calendar" size="small">
                        <v-icon>mdi-calendar</v-icon>
                        <span class="d-none d-sm-inline ms-1">{{ t('FollowUpView.tabs.calendar') }}</span>
                    </v-btn>
                </v-btn-toggle>

                <v-btn
                    color="primary"
                    @click="openCreateDialog"
                    prepend-icon="mdi-plus"
                >
                    {{ t('FollowUpView.actions.create') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Filter-Leiste -->
        <v-row class="mb-4">
            <v-col cols="12" sm="6" md="3">
                <v-text-field
                    v-model="searchQuery"
                    :label="t('FollowUpView.search')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="6" sm="3" md="2">
                <v-text-field
                    v-model="filterFromDate"
                    :label="t('FollowUpView.fromDate')"
                    type="date"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="6" sm="3" md="2">
                <v-text-field
                    v-model="filterToDate"
                    :label="t('FollowUpView.toDate')"
                    type="date"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-checkbox
                    v-model="showDone"
                    :label="t('FollowUpView.showDone')"
                    density="compact"
                    hide-details
                />
            </v-col>
            <v-col cols="12" sm="6" md="2" class="d-flex align-center">
                <v-btn
                    variant="outlined"
                    size="small"
                    @click="loadFollowUps"
                    :loading="loading"
                >
                    <v-icon>mdi-refresh</v-icon>
                </v-btn>
            </v-col>
        </v-row>

        <!-- Loading Skeleton -->
        <v-row v-if="loading">
            <v-col v-for="n in 3" :key="n" cols="12" md="4">
                <v-skeleton-loader type="card" />
            </v-col>
        </v-row>

        <!-- Board View (Kanban-Style) -->
        <v-row v-else-if="viewMode === 'board'">
            <!-- Überfällig -->
            <v-col cols="12" md="4">
                <div class="board-column board-column-overdue">
                    <div class="board-column-header bg-red-lighten-4 pa-3 rounded-t">
                        <v-icon color="red-darken-2" class="me-2">mdi-alert-circle</v-icon>
                        <span class="font-weight-bold text-red-darken-2">
                            {{ t('FollowUpView.columns.overdue') }}
                        </span>
                        <v-chip size="x-small" color="red" class="ms-2">
                            {{ overdueItems.length }}
                        </v-chip>
                    </div>
                    <div class="board-column-content pa-2">
                        <draggable
                            v-model="overdueItems"
                            group="followups"
                            item-key="id"
                            class="min-height-200"
                            @start="onDragStart"
                            @move="onDragMove"
                            @end="onDragEnd"
                        >
                            <template #item="{ element }">
                                <follow-up-card
                                    :follow-up="element"
                                    color="red-lighten-5"
                                    @click="openEditDialog(element)"
                                    @done="markDone(element)"
                                    @delete="confirmDelete(element)"
                                />
                            </template>
                        </draggable>
                    </div>
                </div>
            </v-col>

            <!-- Heute -->
            <v-col cols="12" md="4">
                <div class="board-column board-column-today">
                    <div class="board-column-header bg-amber-lighten-4 pa-3 rounded-t">
                        <v-icon color="amber-darken-3" class="me-2">mdi-calendar-today</v-icon>
                        <span class="font-weight-bold text-amber-darken-3">
                            {{ t('FollowUpView.columns.today') }}
                        </span>
                        <v-chip size="x-small" color="amber" class="ms-2">
                            {{ todayItems.length }}
                        </v-chip>
                    </div>
                    <div class="board-column-content pa-2">
                        <draggable
                            v-model="todayItems"
                            group="followups"
                            item-key="id"
                            class="min-height-200"
                            @start="onDragStart"
                            @move="onDragMove"
                            @end="onDragEnd"
                        >
                            <template #item="{ element }">
                                <follow-up-card
                                    :follow-up="element"
                                    color="amber-lighten-5"
                                    @click="openEditDialog(element)"
                                    @done="markDone(element)"
                                    @delete="confirmDelete(element)"
                                />
                            </template>
                        </draggable>
                    </div>
                </div>
            </v-col>

            <!-- Kommend -->
            <v-col cols="12" md="4">
                <div class="board-column board-column-upcoming">
                    <div class="board-column-header bg-green-lighten-4 pa-3 rounded-t">
                        <v-icon color="green-darken-2" class="me-2">mdi-calendar-arrow-right</v-icon>
                        <span class="font-weight-bold text-green-darken-2">
                            {{ t('FollowUpView.columns.upcoming') }}
                        </span>
                        <v-chip size="x-small" color="green" class="ms-2">
                            {{ upcomingItems.length }}
                        </v-chip>
                    </div>
                    <div class="board-column-content pa-2">
                        <draggable
                            v-model="upcomingItems"
                            group="followups"
                            item-key="id"
                            class="min-height-200"
                            @start="onDragStart"
                            @move="onDragMove"
                            @end="onDragEnd"
                        >
                            <template #item="{ element }">
                                <follow-up-card
                                    :follow-up="element"
                                    color="green-lighten-5"
                                    @click="openEditDialog(element)"
                                    @done="markDone(element)"
                                    @delete="confirmDelete(element)"
                                />
                            </template>
                        </draggable>
                    </div>
                </div>
            </v-col>

            <!-- Erledigt (nur wenn Filter aktiv) -->
            <v-col v-if="showDone && doneItems.length > 0" cols="12">
                <v-expansion-panels>
                    <v-expansion-panel>
                        <v-expansion-panel-title>
                            <v-icon color="grey" class="me-2">mdi-check-circle</v-icon>
                            {{ t('FollowUpView.columns.done') }}
                            <v-chip size="x-small" color="grey" class="ms-2">
                                {{ doneItems.length }}
                            </v-chip>
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <v-row>
                                <v-col
                                    v-for="item in doneItems"
                                    :key="item.id"
                                    cols="12"
                                    sm="6"
                                    md="4"
                                    lg="3"
                                >
                                    <follow-up-card
                                        :follow-up="item"
                                        color="grey-lighten-3"
                                        done
                                        @click="openEditDialog(item)"
                                        @undone="markUndone(item)"
                                        @delete="confirmDelete(item)"
                                    />
                                </v-col>
                            </v-row>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </v-col>
        </v-row>

        <!-- List View -->
        <follow-up-list
            v-else-if="viewMode === 'list'"
            :items="filteredItems"
            @edit="openEditDialog"
            @done="markDone"
            @undone="markUndone"
            @delete="confirmDelete"
            @delete-all="deleteMultiple"
        />

        <!-- Calendar View - KORRIGIERT! -->
        <follow-up-calendar
            v-else-if="viewMode === 'calendar'"
            :items="allItems"
            @edit="openEditDialog"
            @mark-done="markDoneById"
            @mark-undone="markUndoneById"
            @create="handleCalendarCreate"
            @create-with-date="openCreateDialogWithDate"
            @date-change="handleDateChange"
        />

        <!-- Create/Edit Dialog -->
        <follow-up-dialog
            v-model="dialogOpen"
            :follow-up="editingFollowUp"
            :employees="employees"
            @save="saveFollowUp"
        />

        <!-- Delete Confirmation -->
        <v-dialog v-model="deleteDialogOpen" max-width="400">
            <v-card>
                <v-card-title class="text-h6">
                    <v-icon color="error" class="me-2">mdi-delete-alert</v-icon>
                    {{ t('FollowUpView.confirmDelete.title') }}
                </v-card-title>
                <v-card-text>
                    {{ t('FollowUpView.confirmDelete.message') }}
                    <div v-if="deletingFollowUp" class="mt-2 font-weight-medium">
                        "{{ deletingFollowUp.note?.subject || deletingFollowUp.subject }}"
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="deleteDialogOpen = false">
                        {{ t('FollowUpView.actions.cancel') }}
                    </v-btn>
                    <v-btn color="error" variant="flat" @click="deleteFollowUp">
                        {{ t('FollowUpView.actions.delete') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snackbar für Feedback -->
        <v-snackbar
            v-model="snackbar.show"
            :color="snackbar.color"
            :timeout="3000"
            location="bottom right"
        >
            {{ snackbar.message }}
            <template #actions>
                <v-btn variant="text" @click="snackbar.show = false">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </template>
        </v-snackbar>
    </v-container>
</template>

<script setup>
// src/core/views/follow-up/follow-up.view.vue
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { oserpStore } from '@/core/stores/oserp.store.js';
import draggable from 'vuedraggable';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import FollowUpCard from './components/follow-up-card.vue';
import FollowUpDialog from './components/follow-up-dialog.vue';
import FollowUpList from './components/follow-up-list.vue';
import FollowUpCalendar from './components/follow-up-calendar.vue';

const { t } = useI18n();
const store = oserpStore();

// DEBUG
const debugInfo = ref('');

// State
const loading = ref(false);
const viewMode = ref('board');
const searchQuery = ref('');
const filterFromDate = ref('');
const filterToDate = ref('');
const showDone = ref(false);
const allItems = ref([]);

// Dialog State
const dialogOpen = ref(false);
const editingFollowUp = ref(null);
const deleteDialogOpen = ref(false);
const deletingFollowUp = ref(null);

// Snackbar
const snackbar = ref({
    show: false,
    message: '',
    color: 'success'
});

// Employees für Zuweisung
const employees = computed(() => {
    return store.session?.company_config?.employees || [];
});

// Gefilterte Items
const filteredItems = computed(() => {
    let items = [...allItems.value];

    // Suchfilter
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        items = items.filter(item =>
            item.subject?.toLowerCase().includes(query) ||
            item.body?.toLowerCase().includes(query) ||
            item.note?.subject?.toLowerCase().includes(query) ||
            item.note?.body?.toLowerCase().includes(query) ||
            item.links?.some(link => link.trans_info?.toLowerCase().includes(query))
        );
    }

    return items;
});

// Board-Spalten (computed)
const overdueItems = computed({
    get: () => filteredItems.value.filter(item =>
        !item.is_done && item.priority === 'overdue'
    ),
    set: () => {}
});

const todayItems = computed({
    get: () => filteredItems.value.filter(item =>
        !item.is_done && item.priority === 'today'
    ),
    set: () => {}
});

const upcomingItems = computed({
    get: () => filteredItems.value.filter(item =>
        !item.is_done && (item.priority === 'soon' || item.priority === 'normal')
    ),
    set: () => {}
});

const doneItems = computed(() =>
    filteredItems.value.filter(item => item.is_done)
);

// API-Calls
async function loadFollowUps() {
    loading.value = true;
    try {
        const response = await axios.post('/api/followup/', {
            action: 'getFollowUps',
            showDone: showDone.value,
            fromDate: filterFromDate.value || null,
            toDate: filterToDate.value || null
        });

        if (response.data.success) {
            allItems.value = response.data.payload?.result?.follow_ups || [];
        } else {
            showSnackbar(response.data.error || t('FollowUpView.messages.loadError'), 'error');
        }
    } catch (error) {
        console.error('Error loading follow-ups:', error);
        showSnackbar(t('FollowUpView.messages.loadError'), 'error');
    } finally {
        loading.value = false;
    }
}

async function saveFollowUp(data) {
    try {
        let response;
        if (data.id) {
            response = await axios.post('/api/followup/', {
                action: 'updateFollowUp',
                ...data
            });
        } else {
            response = await axios.post('/api/followup/', {
                action: 'createFollowUp',
                ...data
            });
        }

        if (response.data.success) {
            dialogOpen.value = false;
            showSnackbar(t('FollowUpView.messages.saved'), 'success');
            loadFollowUps();
        } else {
            showSnackbar(response.data.error || t('FollowUpView.messages.saveError'), 'error');
        }
    } catch (error) {
        console.error('Error saving follow-up:', error);
        showSnackbar(t('FollowUpView.messages.saveError'), 'error');
    }
}

// Mark Done/Undone mit Objekt (für Board/List)
async function markDone(item) {
    await markDoneById(item.id);
}

async function markUndone(item) {
    await markUndoneById(item.id);
}

// Mark Done/Undone mit ID (für Calendar)
async function markDoneById(id) {
    try {
        const response = await axios.post('/api/followup/', {
            action: 'markFollowUpDone',
            id: id
        });

        if (response.data.success) {
            showSnackbar(t('FollowUpView.messages.markedDone'), 'success');
            loadFollowUps();
        } else {
            showSnackbar(response.data.error, 'error');
        }
    } catch (error) {
        console.error('Error marking done:', error);
        showSnackbar(t('FollowUpView.messages.error'), 'error');
    }
}

async function markUndoneById(id) {
    try {
        const response = await axios.post('/api/followup/', {
            action: 'markFollowUpUndone',
            id: id
        });

        if (response.data.success) {
            showSnackbar(t('FollowUpView.messages.markedUndone'), 'success');
            loadFollowUps();
        } else {
            showSnackbar(response.data.error, 'error');
        }
    } catch (error) {
        console.error('Error marking undone:', error);
        showSnackbar(t('FollowUpView.messages.error'), 'error');
    }
}

async function deleteFollowUp() {
    if (!deletingFollowUp.value) return;

    try {
        const response = await axios.post('/api/followup/', {
            action: 'deleteFollowUp',
            id: deletingFollowUp.value.id
        });

        if (response.data.success) {
            deleteDialogOpen.value = false;
            deletingFollowUp.value = null;
            showSnackbar(t('FollowUpView.messages.deleted'), 'success');
            loadFollowUps();
        } else {
            showSnackbar(response.data.error, 'error');
        }
    } catch (error) {
        console.error('Error deleting follow-up:', error);
        showSnackbar(t('FollowUpView.messages.error'), 'error');
    }
}

async function deleteMultiple(ids) {
    if (!ids || ids.length === 0) return;

    try {
        // Sequentiell löschen (kann später optimiert werden mit Bulk-Delete)
        let deletedCount = 0;
        for (const id of ids) {
            const response = await axios.post('/api/followup/', {
                action: 'deleteFollowUp',
                id: id
            });
            if (response.data.success) {
                deletedCount++;
            }
        }

        if (deletedCount > 0) {
            showSnackbar(t('FollowUpView.messages.deletedMultiple', { count: deletedCount }), 'success');
            loadFollowUps();
        }
    } catch (error) {
        console.error('Error deleting multiple follow-ups:', error);
        showSnackbar(t('FollowUpView.messages.error'), 'error');
    }
}

// Calendar-spezifische Handler
async function handleCalendarCreate(data) {
    try {
        const response = await axios.post('/api/followup/', {
            action: 'createFollowUp',
            followUpDate: data.followUpDate,
            subject: data.subject,
            body: data.body
        });

        if (response.data.success) {
            showSnackbar(t('FollowUpView.messages.saved'), 'success');
            loadFollowUps();
        } else {
            showSnackbar(response.data.error || t('FollowUpView.messages.saveError'), 'error');
        }
    } catch (error) {
        console.error('Error creating follow-up from calendar:', error);
        showSnackbar(t('FollowUpView.messages.saveError'), 'error');
    }
}

async function handleDateChange({ id, newDate }) {
    try {
        const response = await axios.post('/api/followup/', {
            action: 'updateFollowUp',
            id: id,
            followUpDate: newDate
        });

        if (response.data.success) {
            loadFollowUps();
        } else {
            showSnackbar(response.data.error, 'error');
        }
    } catch (error) {
        console.error('Error updating date:', error);
        showSnackbar(t('FollowUpView.messages.error'), 'error');
    }
}

// Drag & Drop Handler
function onDragStart(event) {
    debugInfo.value = '🎯 DRAG START - Beginne Drag';
    console.log('═══════════════════════════════════════');
    console.log('[VIEW] DRAG START');
    console.log('[VIEW] event:', event);
    console.log('[VIEW] event.item:', event.item);
    console.log('[VIEW] event.from:', event.from);
    console.log('═══════════════════════════════════════');
}

function onDragMove(event) {
    debugInfo.value = '🔄 DRAG MOVE - Ziehe Element...';
    console.log('[VIEW] DRAG MOVE - element being moved');
    return true; // Allow move
}

function onDragEnd(event) {
    debugInfo.value = '✅ DRAG END - Verarbeite Drop...';
    console.log('[View] onDragEnd triggered', event);
    console.log('[View] event.item', event.item);
    console.log('[View] event.to', event.to);
    console.log('[View] event.from', event.from);

    const item = event.item.__draggable_context?.element;
    console.log('[View] Draggable item:', item);

    if (!item) {
        console.warn('[View] No item found in draggable context!');
        debugInfo.value = '❌ FEHLER: Kein Item gefunden!';
        return;
    }

    const targetClass = event.to.closest('.board-column')?.classList;
    console.log('[View] Target column classes:', targetClass);

    let newDate = item.follow_up_date;

    if (targetClass?.contains('board-column-overdue')) {
        console.log('[View] Dropped in OVERDUE column');
        const yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);
        newDate = yesterday.toISOString().split('T')[0];
    } else if (targetClass?.contains('board-column-today')) {
        console.log('[View] Dropped in TODAY column');
        newDate = new Date().toISOString().split('T')[0];
    } else if (targetClass?.contains('board-column-upcoming')) {
        console.log('[View] Dropped in UPCOMING column');
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        newDate = tomorrow.toISOString().split('T')[0];
    } else {
        console.log('[View] Dropped in unknown column');
    }

    if (newDate !== item.follow_up_date) {
        console.log('[View] Date changed from', item.follow_up_date, 'to', newDate);
        debugInfo.value = `📅 Datum geändert: ${item.follow_up_date} → ${newDate}`;
        handleDateChange({ id: item.id, newDate });
    } else {
        console.log('[View] Date unchanged, no update needed');
        debugInfo.value = '✓ Datum unverändert';
    }

    // Clear debug info after 3 seconds
    setTimeout(() => { debugInfo.value = ''; }, 3000);
}

// Dialog-Handler
function openCreateDialog() {
    editingFollowUp.value = null;
    dialogOpen.value = true;
}

function openCreateDialogWithDate(date) {
    editingFollowUp.value = { follow_up_date: date };
    dialogOpen.value = true;
}

function openEditDialog(item) {
    editingFollowUp.value = { ...item };
    dialogOpen.value = true;
}

function confirmDelete(item) {
    deletingFollowUp.value = item;
    deleteDialogOpen.value = true;
}

// Helper
function showSnackbar(message, color = 'success') {
    snackbar.value = { show: true, message, color };
}

// Watchers
watch([showDone, filterFromDate, filterToDate], () => {
    loadFollowUps();
});

// Lifecycle
onMounted(() => {
    loadFollowUps();
});
</script>

<style scoped>
.board-column {
    background: #fafafa;
    border-radius: 8px;
    min-height: 400px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.board-column-header {
    display: flex;
    align-items: center;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

.board-column-content {
    min-height: 300px;
}

.min-height-200 {
    min-height: 200px;
}

/* Drag & Drop Styling */
.sortable-ghost {
    opacity: 0.5;
    background: #c8ebfb;
}

.sortable-chosen {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transform: rotate(2deg);
}
</style>