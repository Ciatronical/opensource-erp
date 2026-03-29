<!-- src/core/views/tasks/tasks.view.vue -->
<template>
    <v-container fluid class="pa-2 pa-md-4">
        <!-- Header -->
        <v-row class="mb-4">
            <v-col cols="12" md="6">
                <h1 class="text-h5 text-md-h4 d-flex align-center">
                    <v-icon class="me-2" color="primary">mdi-checkbox-marked-circle-outline</v-icon>
                    {{ $t('TasksView.title') }}
                </h1>
            </v-col>
            <v-col cols="12" md="6" class="d-flex justify-end align-center gap-2 flex-wrap">
                <v-btn-toggle v-model="viewMode" mandatory density="compact" color="primary">
                    <v-btn value="board" size="small">
                        <v-icon>mdi-view-column</v-icon>
                        <span class="d-none d-sm-inline ms-1">{{ $t('TasksView.tabs.board') }}</span>
                    </v-btn>
                    <v-btn value="list" size="small">
                        <v-icon>mdi-format-list-bulleted</v-icon>
                        <span class="d-none d-sm-inline ms-1">{{ $t('TasksView.tabs.list') }}</span>
                    </v-btn>
                    <v-btn value="calendar" size="small">
                        <v-icon>mdi-calendar</v-icon>
                        <span class="d-none d-sm-inline ms-1">{{ $t('TasksView.tabs.calendar') }}</span>
                    </v-btn>
                </v-btn-toggle>

                <v-btn color="primary" @click="openCreateDialog" prepend-icon="mdi-plus">
                    {{ $t('TasksView.actions.create') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Filter -->
        <v-row class="mb-4">
            <v-col cols="12" sm="6" md="3">
                <v-text-field
                    v-model="searchQuery"
                    :label="$t('TasksView.search')"
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
                    :label="$t('TasksView.fromDate')"
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
                    :label="$t('TasksView.toDate')"
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
                    :label="$t('TasksView.showDone')"
                    density="compact"
                    hide-details
                />
            </v-col>
            <v-col cols="12" sm="6" md="2" class="d-flex align-center">
                <v-btn variant="outlined" size="small" @click="loadTasks" :loading="loading">
                    <v-icon>mdi-refresh</v-icon>
                </v-btn>
            </v-col>
        </v-row>

        <!-- Loading -->
        <v-row v-if="loading">
            <v-col v-for="n in 3" :key="n" cols="12" md="4">
                <v-skeleton-loader type="card" />
            </v-col>
        </v-row>

        <!-- Board View -->
        <v-row v-else-if="viewMode === 'board'">
            <v-col cols="12" md="4">
                <div class="board-column board-column-overdue">
                    <div class="board-column-header bg-red-lighten-4 pa-3 rounded-t">
                        <v-icon color="red-darken-2" class="me-2">mdi-alert-circle</v-icon>
                        <span class="font-weight-bold text-red-darken-2">{{ $t('TasksView.columns.overdue') }}</span>
                        <v-chip size="x-small" color="red" class="ms-2">{{ overdueItems.length }}</v-chip>
                    </div>
                    <div class="board-column-content pa-2">
                        <draggable v-model="overdueItems" group="tasks" item-key="id" class="min-height-200" @end="onDragEnd">
                            <template #item="{ element }">
                                <tasks-card
                                    :task="element"
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

            <v-col cols="12" md="4">
                <div class="board-column board-column-today">
                    <div class="board-column-header bg-amber-lighten-4 pa-3 rounded-t">
                        <v-icon color="amber-darken-3" class="me-2">mdi-calendar-today</v-icon>
                        <span class="font-weight-bold text-amber-darken-3">{{ $t('TasksView.columns.today') }}</span>
                        <v-chip size="x-small" color="amber" class="ms-2">{{ todayItems.length }}</v-chip>
                    </div>
                    <div class="board-column-content pa-2">
                        <draggable v-model="todayItems" group="tasks" item-key="id" class="min-height-200" @end="onDragEnd">
                            <template #item="{ element }">
                                <tasks-card
                                    :task="element"
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

            <v-col cols="12" md="4">
                <div class="board-column board-column-upcoming">
                    <div class="board-column-header bg-green-lighten-4 pa-3 rounded-t">
                        <v-icon color="green-darken-2" class="me-2">mdi-calendar-arrow-right</v-icon>
                        <span class="font-weight-bold text-green-darken-2">{{ $t('TasksView.columns.upcoming') }}</span>
                        <v-chip size="x-small" color="green" class="ms-2">{{ upcomingItems.length }}</v-chip>
                    </div>
                    <div class="board-column-content pa-2">
                        <draggable v-model="upcomingItems" group="tasks" item-key="id" class="min-height-200" @end="onDragEnd">
                            <template #item="{ element }">
                                <tasks-card
                                    :task="element"
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

            <v-col v-if="showDone && doneItems.length > 0" cols="12">
                <v-expansion-panels>
                    <v-expansion-panel>
                        <v-expansion-panel-title>
                            <v-icon color="grey" class="me-2">mdi-check-circle</v-icon>
                            {{ $t('TasksView.columns.done') }}
                            <v-chip size="x-small" color="grey" class="ms-2">{{ doneItems.length }}</v-chip>
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <v-row>
                                <v-col v-for="item in doneItems" :key="item.id" cols="12" sm="6" md="4" lg="3">
                                    <tasks-card
                                        :task="item"
                                        color="grey-lighten-4"
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
        <tasks-list
            v-else-if="viewMode === 'list'"
            :items="filteredItems"
            @edit="openEditDialog"
            @done="markDone"
            @undone="markUndone"
            @delete="confirmDelete"
        />

        <!-- Calendar View -->
        <tasks-calendar
            v-else-if="viewMode === 'calendar'"
            :items="filteredItems"
            @edit="openEditDialog"
            @mark-done="markDoneById"
            @mark-undone="markUndoneById"
            @create="createFromCalendar"
            @date-change="updateDate"
        />

        <!-- Create/Edit Dialog -->
        <tasks-dialog
            v-model="dialogOpen"
            :task="editingTask"
            :employees="employees"
            @save="saveTask"
        />

        <!-- Delete Confirmation -->
        <v-dialog v-model="deleteDialogOpen" max-width="400">
            <v-card>
                <v-card-title>{{ $t('TasksView.confirmDelete.title') }}</v-card-title>
                <v-card-text>{{ $t('TasksView.confirmDelete.message') }}</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="deleteDialogOpen = false">
                        {{ $t('TasksView.actions.cancel') }}
                    </v-btn>
                    <v-btn color="red" variant="flat" @click="deleteTask">
                        {{ $t('TasksView.actions.delete') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snackbar -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000">
            {{ snackbar.message }}
        </v-snackbar>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'
import axios from 'axios'
import TasksCard from './components/tasks-card.vue'
import TasksList from './components/tasks-list.vue'
import TasksCalendar from './components/tasks-calendar.vue'
import TasksDialog from './components/tasks-dialog.vue'

const { t } = useI18n()

const loading = ref(false)
const viewMode = ref('board')
const searchQuery = ref('')
const filterFromDate = ref('')
const filterToDate = ref('')
const showDone = ref(false)

const allItems = ref([])
const employees = ref([])
const dialogOpen = ref(false)
const editingTask = ref(null)
const deleteDialogOpen = ref(false)
const deletingTask = ref(null)
const snackbar = ref({ show: false, message: '', color: 'success' })

const filteredItems = computed(() => {
    let items = [...allItems.value]
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase()
        items = items.filter(item =>
            item.subject?.toLowerCase().includes(query) ||
            item.body?.toLowerCase().includes(query)
        )
    }
    return items
})

const overdueItems = computed({
    get: () => filteredItems.value.filter(item => !item.is_done && item.priority === 'overdue'),
    set: () => {}
})

const todayItems = computed({
    get: () => filteredItems.value.filter(item => !item.is_done && item.priority === 'today'),
    set: () => {}
})

const upcomingItems = computed({
    get: () => filteredItems.value.filter(item => !item.is_done && ['soon', 'normal'].includes(item.priority)),
    set: () => {}
})

const doneItems = computed(() => filteredItems.value.filter(item => item.is_done))

async function loadTasks() {
    loading.value = true
    try {
        const { data } = await axios.post('/api/tasks/', {
            action: 'getTasks',
            showDone: showDone.value,
            fromDate: filterFromDate.value || null,
            toDate: filterToDate.value || null
        })
        if (data.follow_ups) {
            allItems.value = data.follow_ups
        }
    } catch (error) {
        console.error('Error loading tasks:', error)
        showSnackbar(t('TasksView.messages.loadError'), 'error')
    } finally {
        loading.value = false
    }
}

async function loadEmployees() {
    try {
        const { data } = await axios.post('/api/tasks/', { action: 'getEmployeesForTask' })
        if (data.employees) {
            employees.value = data.employees
        }
    } catch (error) {
        console.error('Error loading employees:', error)
    }
}

async function saveTask(taskData) {
    try {
        const action = taskData.id ? 'updateTask' : 'createTask'
        const { data } = await axios.post('/api/tasks/', { action, ...taskData })
        if (data.follow_up || data.success) {
            dialogOpen.value = false
            showSnackbar(t('TasksView.messages.saved'), 'success')
            loadTasks()
        } else {
            showSnackbar(data.message || t('TasksView.messages.saveError'), 'error')
        }
    } catch (error) {
        console.error('Error saving task:', error)
        showSnackbar(t('TasksView.messages.saveError'), 'error')
    }
}

async function markDone(item) {
    await markDoneById(item.id)
}

async function markDoneById(id) {
    try {
        await axios.post('/api/tasks/', { action: 'markTaskDone', id })
        showSnackbar(t('TasksView.messages.markedDone'), 'success')
        loadTasks()
    } catch (error) {
        console.error('Error marking done:', error)
    }
}

async function markUndone(item) {
    await markUndoneById(item.id)
}

async function markUndoneById(id) {
    try {
        await axios.post('/api/tasks/', { action: 'markTaskUndone', id })
        showSnackbar(t('TasksView.messages.markedUndone'), 'success')
        loadTasks()
    } catch (error) {
        console.error('Error marking undone:', error)
    }
}

async function deleteTask() {
    if (!deletingTask.value) return
    try {
        await axios.post('/api/tasks/', { action: 'deleteTask', id: deletingTask.value.id })
        deleteDialogOpen.value = false
        deletingTask.value = null
        showSnackbar(t('TasksView.messages.deleted'), 'success')
        loadTasks()
    } catch (error) {
        console.error('Error deleting task:', error)
    }
}

async function updateDate({ id, newDate }) {
    try {
        await axios.post('/api/tasks/', { action: 'updateTask', id, followUpDate: newDate })
        loadTasks()
    } catch (error) {
        console.error('Error updating date:', error)
    }
}

function createFromCalendar(data) {
    editingTask.value = { follow_up_date: data.followUpDate, subject: data.subject, body: data.body }
    saveTask({
        followUpDate: data.followUpDate,
        subject: data.subject,
        body: data.body
    })
}

function openCreateDialog() {
    editingTask.value = null
    dialogOpen.value = true
}

function openEditDialog(item) {
    editingTask.value = { ...item }
    dialogOpen.value = true
}

function confirmDelete(item) {
    deletingTask.value = item
    deleteDialogOpen.value = true
}

function onDragEnd(event) {
    const item = event.item.__draggable_context?.element
    if (!item) return
    const targetClass = event.to.closest('.board-column')?.classList
    let newDate = item.follow_up_date
    if (targetClass?.contains('board-column-today')) {
        newDate = new Date().toISOString().split('T')[0]
    } else if (targetClass?.contains('board-column-upcoming')) {
        const tomorrow = new Date()
        tomorrow.setDate(tomorrow.getDate() + 1)
        newDate = tomorrow.toISOString().split('T')[0]
    }
    if (newDate !== item.follow_up_date) {
        updateDate({ id: item.id, newDate })
    }
}

function showSnackbar(message, color = 'success') {
    snackbar.value = { show: true, message, color }
}

watch([showDone, filterFromDate, filterToDate], loadTasks)

onMounted(() => {
    loadTasks()
    loadEmployees()
})
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

.sortable-ghost {
    opacity: 0.5;
    background: #c8ebfb;
}

.sortable-chosen {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transform: rotate(2deg);
}
</style>
