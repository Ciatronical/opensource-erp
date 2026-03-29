<!-- src/core/views/developer-tools/components/tickets.component.vue -->
<template>
    <div>
        <!-- Header -->
        <div class="d-flex align-center flex-wrap ga-2 mb-4">
            <div>
                <div class="text-h6 font-weight-bold">{{ t('DeveloperTools.tickets.title') }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.tickets.description') }}</div>
            </div>
            <v-spacer />

            <!-- Ansichts-Toggle -->
            <v-btn-toggle v-model="viewMode" mandatory density="compact" color="primary" variant="outlined">
                <v-btn value="kanban" size="small">
                    <v-icon start size="small">mdi-view-column</v-icon>
                    Kanban
                </v-btn>
                <v-btn value="list" size="small">
                    <v-icon start size="small">mdi-view-list</v-icon>
                    {{ t('DeveloperTools.tickets.listView') }}
                </v-btn>
            </v-btn-toggle>

            <v-btn color="primary" prepend-icon="mdi-plus" @click="openTicketDialog()">
                {{ t('DeveloperTools.tickets.newTicket') }}
            </v-btn>
        </div>

        <!-- Filter-Leiste -->
        <v-card variant="tonal" class="mb-4 pa-3">
            <v-row dense align="center">
                <v-col cols="12" sm="3">
                    <v-text-field
                        v-model="searchText"
                        :label="t('DeveloperTools.tickets.search')"
                        variant="outlined"
                        density="compact"
                        prepend-inner-icon="mdi-magnify"
                        clearable
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="2">
                    <v-select
                        v-model="filterPriority"
                        :items="priorityItems"
                        item-title="text"
                        item-value="value"
                        :label="t('DeveloperTools.tickets.priority')"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="2">
                    <v-select
                        v-model="filterType"
                        :items="typeItems"
                        item-title="text"
                        item-value="value"
                        :label="t('DeveloperTools.tickets.type')"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="2">
                    <v-select
                        v-model="filterProject"
                        :items="masterData.projects"
                        :item-title="p => p.projectnumber + ' - ' + p.description"
                        item-value="id"
                        :label="t('DeveloperTools.tickets.project')"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="3">
                    <v-select
                        v-model="filterAssignee"
                        :items="activeEmployees"
                        item-title="name"
                        item-value="id"
                        :label="t('DeveloperTools.tickets.assignedTo')"
                        variant="outlined"
                        density="compact"
                        clearable
                        hide-details
                    />
                </v-col>
            </v-row>
        </v-card>

        <!-- Loading -->
        <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />

        <!-- Statistik-Chips -->
        <div class="d-flex flex-wrap ga-2 mb-4" v-if="tickets.length > 0">
            <v-chip size="small" variant="tonal" color="grey">
                {{ t('DeveloperTools.tickets.total') }}: {{ tickets.length }}
            </v-chip>
            <v-chip size="small" variant="tonal" color="info">
                {{ t('DeveloperTools.tickets.statusOpen') }}: {{ ticketsByStatus('open').length }}
            </v-chip>
            <v-chip size="small" variant="tonal" color="warning">
                {{ t('DeveloperTools.tickets.statusInProgress') }}: {{ ticketsByStatus('in_progress').length }}
            </v-chip>
            <v-chip size="small" variant="tonal" color="purple">
                {{ t('DeveloperTools.tickets.statusReview') }}: {{ ticketsByStatus('review').length }}
            </v-chip>
            <v-chip size="small" variant="tonal" color="success">
                {{ t('DeveloperTools.tickets.statusDone') }}: {{ ticketsByStatus('done').length }}
            </v-chip>
        </div>

        <!-- Leere Ansicht -->
        <v-card v-if="!loading && tickets.length === 0" variant="tonal" class="pa-8 text-center">
            <v-icon size="64" color="grey-lighten-1" class="mb-4">mdi-ticket-outline</v-icon>
            <div class="text-h6 text-grey">{{ t('DeveloperTools.tickets.empty') }}</div>
            <div class="text-caption text-grey mt-1">{{ t('DeveloperTools.tickets.emptyHint') }}</div>
        </v-card>

        <!-- KANBAN-ANSICHT -->
        <div v-if="viewMode === 'kanban' && filteredTickets.length > 0" class="kanban-board">
            <div
                v-for="col in kanbanColumns"
                :key="col.status"
                class="kanban-column"
                @dragover.prevent
                @drop="onDrop($event, col.status)"
            >
                <div class="kanban-column-header" :style="{ borderTopColor: col.color }">
                    <v-icon start size="small" :color="col.color">{{ col.icon }}</v-icon>
                    <span class="font-weight-bold">{{ col.label }}</span>
                    <v-chip size="x-small" class="ml-2" :color="col.color" variant="flat">
                        {{ ticketsByStatusFiltered(col.status).length }}
                    </v-chip>
                </div>

                <div class="kanban-column-body">
                    <v-card
                        v-for="ticket in ticketsByStatusFiltered(col.status)"
                        :key="ticket.id"
                        class="kanban-card mb-2"
                        :draggable="true"
                        @dragstart="onDragStart($event, ticket)"
                        @click="openDetailDialog(ticket)"
                        hover
                    >
                        <v-card-text class="pa-3">
                            <!-- Ticket-Header -->
                            <div class="d-flex align-center mb-1">
                                <v-chip size="x-small" variant="flat" :color="getPriorityColor(ticket.priority)" class="mr-1">
                                    <v-icon start size="10">{{ getPriorityIcon(ticket.priority) }}</v-icon>
                                    {{ ticket.priority }}
                                </v-chip>
                                <v-chip size="x-small" variant="outlined" class="mr-1">
                                    {{ ticket.ticket_number }}
                                </v-chip>
                                <v-chip size="x-small" variant="tonal" :color="getTypeColor(ticket.ticket_type)">
                                    <v-icon start size="10">{{ getTypeIcon(ticket.ticket_type) }}</v-icon>
                                    {{ ticket.ticket_type }}
                                </v-chip>
                            </div>

                            <!-- Titel -->
                            <div class="text-body-2 font-weight-medium mt-1 mb-2 ticket-title">
                                {{ ticket.title }}
                            </div>

                            <!-- Labels -->
                            <div v-if="parseLabels(ticket.labels).length > 0" class="d-flex flex-wrap ga-1 mb-2">
                                <v-chip
                                    v-for="label in parseLabels(ticket.labels)"
                                    :key="label.id"
                                    size="x-small"
                                    :color="label.color"
                                    variant="flat"
                                    text-color="white"
                                >
                                    {{ label.name }}
                                </v-chip>
                            </div>

                            <!-- Footer -->
                            <div class="d-flex align-center justify-space-between text-caption text-medium-emphasis">
                                <div class="d-flex align-center ga-2">
                                    <span v-if="ticket.assigned_to_name" class="d-flex align-center">
                                        <v-icon size="12" class="mr-1">mdi-account</v-icon>
                                        {{ ticket.assigned_to_name }}
                                    </span>
                                    <span v-if="ticket.due_date" class="d-flex align-center" :class="{ 'text-error': isOverdue(ticket) }">
                                        <v-icon size="12" class="mr-1">mdi-calendar</v-icon>
                                        {{ formatDate(ticket.due_date) }}
                                    </span>
                                </div>
                                <div class="d-flex align-center ga-2">
                                    <span v-if="ticket.estimated_hours" class="d-flex align-center">
                                        <v-icon size="12" class="mr-1">mdi-clock-outline</v-icon>
                                        {{ ticket.estimated_hours }}h
                                    </span>
                                    <span v-if="ticket.comment_count > 0" class="d-flex align-center">
                                        <v-icon size="12" class="mr-1">mdi-comment-outline</v-icon>
                                        {{ ticket.comment_count }}
                                    </span>
                                </div>
                            </div>
                        </v-card-text>
                    </v-card>
                </div>
            </div>
        </div>

        <!-- LISTEN-ANSICHT -->
        <v-card v-if="viewMode === 'list' && filteredTickets.length > 0" variant="outlined">
            <v-table density="compact" hover>
                <thead>
                    <tr>
                        <th style="width: 100px">#</th>
                        <th style="width: 80px">{{ t('DeveloperTools.tickets.priority') }}</th>
                        <th style="width: 80px">{{ t('DeveloperTools.tickets.type') }}</th>
                        <th>{{ t('DeveloperTools.tickets.ticketTitle') }}</th>
                        <th style="width: 100px">{{ t('DeveloperTools.tickets.status') }}</th>
                        <th style="width: 130px">{{ t('DeveloperTools.tickets.assignedTo') }}</th>
                        <th style="width: 130px">{{ t('DeveloperTools.tickets.project') }}</th>
                        <th style="width: 90px">{{ t('DeveloperTools.tickets.dueDate') }}</th>
                        <th style="width: 80px">{{ t('DeveloperTools.tickets.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="ticket in filteredTickets"
                        :key="ticket.id"
                        class="cursor-pointer"
                        @click="openDetailDialog(ticket)"
                    >
                        <td>
                            <span class="text-caption font-weight-bold">{{ ticket.ticket_number }}</span>
                        </td>
                        <td>
                            <v-chip size="x-small" variant="flat" :color="getPriorityColor(ticket.priority)">
                                <v-icon start size="10">{{ getPriorityIcon(ticket.priority) }}</v-icon>
                                {{ ticket.priority }}
                            </v-chip>
                        </td>
                        <td>
                            <v-chip size="x-small" variant="tonal" :color="getTypeColor(ticket.ticket_type)">
                                {{ ticket.ticket_type }}
                            </v-chip>
                        </td>
                        <td>
                            <div class="text-body-2">{{ ticket.title }}</div>
                            <div v-if="parseLabels(ticket.labels).length > 0" class="d-flex ga-1 mt-1">
                                <v-chip
                                    v-for="label in parseLabels(ticket.labels)"
                                    :key="label.id"
                                    size="x-small"
                                    :color="label.color"
                                    variant="flat"
                                >{{ label.name }}</v-chip>
                            </div>
                        </td>
                        <td>
                            <v-chip size="x-small" :color="getStatusColor(ticket.status)" variant="tonal">
                                {{ getStatusLabel(ticket.status) }}
                            </v-chip>
                        </td>
                        <td class="text-caption">{{ ticket.assigned_to_name || '–' }}</td>
                        <td class="text-caption">{{ ticket.project_name || '–' }}</td>
                        <td class="text-caption" :class="{ 'text-error': isOverdue(ticket) }">
                            {{ ticket.due_date ? formatDate(ticket.due_date) : '–' }}
                        </td>
                        <td>
                            <v-btn size="x-small" icon="mdi-pencil" variant="text" @click.stop="openTicketDialog(ticket)" />
                            <v-btn size="x-small" icon="mdi-delete" variant="text" color="error" @click.stop="confirmDeleteTicket(ticket)" />
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>

        <!-- TICKET-DETAIL DIALOG -->
        <v-dialog v-model="showDetailDialog" max-width="900" scrollable>
            <v-card v-if="detailTicket">
                <v-card-title class="d-flex align-center bg-primary text-white">
                    <v-chip size="small" variant="flat" color="white" text-color="primary" class="mr-2">
                        {{ detailTicket.ticket_number }}
                    </v-chip>
                    {{ detailTicket.title }}
                    <v-spacer />
                    <v-btn icon="mdi-pencil" size="x-small" variant="text" color="white" class="mr-1" @click="openTicketDialog(detailTicket); showDetailDialog = false" />
                    <v-btn icon="mdi-close" size="x-small" variant="text" color="white" @click="showDetailDialog = false" />
                </v-card-title>

                <v-card-text class="pa-4">
                    <!-- Metadaten -->
                    <v-row class="mb-3">
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.tickets.status') }}</div>
                            <v-chip :color="getStatusColor(detailTicket.status)" variant="tonal" size="small">
                                {{ getStatusLabel(detailTicket.status) }}
                            </v-chip>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.tickets.priority') }}</div>
                            <v-chip :color="getPriorityColor(detailTicket.priority)" variant="flat" size="small">
                                <v-icon start size="12">{{ getPriorityIcon(detailTicket.priority) }}</v-icon>
                                {{ detailTicket.priority }}
                            </v-chip>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.tickets.type') }}</div>
                            <v-chip :color="getTypeColor(detailTicket.ticket_type)" variant="tonal" size="small">
                                <v-icon start size="12">{{ getTypeIcon(detailTicket.ticket_type) }}</v-icon>
                                {{ detailTicket.ticket_type }}
                            </v-chip>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.tickets.assignedTo') }}</div>
                            <div class="font-weight-medium">{{ detailTicket.assigned_to_name || '–' }}</div>
                        </v-col>
                    </v-row>
                    <v-row class="mb-3">
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.tickets.project') }}</div>
                            <div class="font-weight-medium">{{ detailTicket.project_name || '–' }}</div>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.tickets.dueDate') }}</div>
                            <div class="font-weight-medium" :class="{ 'text-error': isOverdue(detailTicket) }">
                                {{ detailTicket.due_date ? formatDate(detailTicket.due_date) : '–' }}
                            </div>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.tickets.estimatedHours') }}</div>
                            <div class="font-weight-medium">{{ detailTicket.estimated_hours || '–' }}h</div>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.tickets.actualHours') }}</div>
                            <div class="font-weight-medium">{{ detailTicket.actual_hours || 0 }}h</div>
                        </v-col>
                    </v-row>

                    <!-- Pflichtenheft-Verknüpfung -->
                    <v-alert v-if="detailTicket.requirement_spec_title" type="info" variant="tonal" density="compact" class="mb-3">
                        <v-icon start size="small">mdi-file-document-check</v-icon>
                        {{ t('DeveloperTools.tickets.linkedSpec') }}: <strong>{{ detailTicket.requirement_spec_title }}</strong>
                    </v-alert>

                    <!-- Beschreibung -->
                    <div v-if="detailTicket.description" class="mb-4">
                        <div class="text-subtitle-2 font-weight-bold mb-1">{{ t('DeveloperTools.tickets.ticketDescription') }}</div>
                        <v-card variant="tonal" class="pa-3">
                            <div style="white-space: pre-wrap">{{ detailTicket.description }}</div>
                        </v-card>
                    </div>

                    <v-divider class="mb-4" />

                    <!-- Quick-Status -->
                    <div class="d-flex align-center ga-2 mb-4">
                        <span class="text-caption font-weight-bold">{{ t('DeveloperTools.tickets.quickStatus') }}:</span>
                        <v-btn
                            v-for="col in kanbanColumns"
                            :key="col.status"
                            size="x-small"
                            :color="detailTicket.status === col.status ? col.color : undefined"
                            :variant="detailTicket.status === col.status ? 'flat' : 'outlined'"
                            @click="quickStatusChange(detailTicket, col.status)"
                        >
                            {{ col.label }}
                        </v-btn>
                    </div>

                    <v-divider class="mb-4" />

                    <!-- Kommentare -->
                    <div class="text-subtitle-2 font-weight-bold mb-2">
                        <v-icon start size="small">mdi-comment-multiple</v-icon>
                        {{ t('DeveloperTools.tickets.comments') }}
                        <v-chip size="x-small" class="ml-1">{{ detailComments.length }}</v-chip>
                    </div>

                    <div v-for="comment in detailComments" :key="comment.id" class="mb-2">
                        <v-card variant="outlined">
                            <v-card-text class="pa-3">
                                <div class="d-flex align-center mb-1">
                                    <v-icon size="small" class="mr-1">mdi-account-circle</v-icon>
                                    <span class="text-caption font-weight-bold">{{ comment.employee_name || 'System' }}</span>
                                    <v-spacer />
                                    <span class="text-caption text-medium-emphasis">{{ formatDateTime(comment.itime) }}</span>
                                    <v-btn size="x-small" icon="mdi-delete" variant="text" color="error" class="ml-1" @click="deleteComment(comment.id)" />
                                </div>
                                <div style="white-space: pre-wrap">{{ comment.comment }}</div>
                            </v-card-text>
                        </v-card>
                    </div>

                    <!-- Neuer Kommentar -->
                    <v-textarea
                        v-model="newComment"
                        :label="t('DeveloperTools.tickets.newComment')"
                        variant="outlined"
                        density="compact"
                        rows="2"
                        auto-grow
                        hide-details
                        class="mt-3"
                    />
                    <div class="d-flex justify-end mt-2">
                        <v-btn
                            size="small"
                            color="primary"
                            variant="tonal"
                            prepend-icon="mdi-send"
                            :disabled="!newComment.trim()"
                            @click="addComment"
                        >
                            {{ t('DeveloperTools.tickets.addComment') }}
                        </v-btn>
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- TICKET ERSTELLEN/BEARBEITEN DIALOG -->
        <v-dialog v-model="showTicketDialog" max-width="800" persistent scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon start>mdi-ticket-confirmation</v-icon>
                    {{ editingTicket?.id ? t('DeveloperTools.tickets.editTicket') : t('DeveloperTools.tickets.newTicket') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="showTicketDialog = false" />
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <v-row>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editingTicket.title"
                                :label="t('DeveloperTools.tickets.ticketTitle')"
                                variant="outlined"
                                density="comfortable"
                                autofocus
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-textarea
                                v-model="editingTicket.description"
                                :label="t('DeveloperTools.tickets.ticketDescription')"
                                variant="outlined"
                                density="comfortable"
                                rows="3"
                                auto-grow
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="editingTicket.ticket_type"
                                :items="typeItems"
                                item-title="text"
                                item-value="value"
                                :label="t('DeveloperTools.tickets.type')"
                                variant="outlined"
                                density="comfortable"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="editingTicket.priority"
                                :items="priorityItems"
                                item-title="text"
                                item-value="value"
                                :label="t('DeveloperTools.tickets.priority')"
                                variant="outlined"
                                density="comfortable"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="editingTicket.status"
                                :items="statusItems"
                                item-title="text"
                                item-value="value"
                                :label="t('DeveloperTools.tickets.status')"
                                variant="outlined"
                                density="comfortable"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="editingTicket.reported_by"
                                :items="activeEmployees"
                                item-title="name"
                                item-value="id"
                                :label="t('DeveloperTools.tickets.author')"
                                variant="outlined"
                                density="comfortable"
                                clearable
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="editingTicket.assigned_to"
                                :items="activeEmployees"
                                item-title="name"
                                item-value="id"
                                :label="t('DeveloperTools.tickets.assignedTo')"
                                variant="outlined"
                                density="comfortable"
                                clearable
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="editingTicket.project_id"
                                :items="masterData.projects"
                                :item-title="p => p.projectnumber + ' - ' + p.description"
                                item-value="id"
                                :label="t('DeveloperTools.tickets.project')"
                                variant="outlined"
                                density="comfortable"
                                clearable
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-select
                                v-model="editingTicket.requirement_spec_id"
                                :items="masterData.specs"
                                item-title="title"
                                item-value="id"
                                :label="t('DeveloperTools.tickets.linkedSpec')"
                                variant="outlined"
                                density="comfortable"
                                clearable
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-select
                                v-model="editingTicket.label_ids"
                                :items="masterData.labels"
                                item-title="name"
                                item-value="id"
                                :label="t('DeveloperTools.tickets.labels')"
                                variant="outlined"
                                density="comfortable"
                                clearable
                                multiple
                                chips
                                hide-details="auto"
                            >
                                <template #chip="{ item }">
                                    <v-chip size="small" :color="item.raw.color" variant="flat">{{ item.raw.name }}</v-chip>
                                </template>
                            </v-select>
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field
                                v-model="editingTicket.estimated_hours"
                                :label="t('DeveloperTools.tickets.estimatedHours')"
                                variant="outlined"
                                density="comfortable"
                                type="number"
                                suffix="h"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field
                                v-model="editingTicket.actual_hours"
                                :label="t('DeveloperTools.tickets.actualHours')"
                                variant="outlined"
                                density="comfortable"
                                type="number"
                                suffix="h"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field
                                v-model="editingTicket.due_date"
                                :label="t('DeveloperTools.tickets.dueDate')"
                                variant="outlined"
                                density="comfortable"
                                type="date"
                                hide-details="auto"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-divider />
                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn variant="text" @click="showTicketDialog = false">{{ t('cancel') }}</v-btn>
                    <v-btn variant="flat" color="primary" :loading="saving" @click="saveTicket">
                        {{ t('DeveloperTools.tickets.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- LABEL-VERWALTUNG DIALOG -->
        <v-dialog v-model="showLabelsDialog" max-width="500">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon start>mdi-label-multiple</v-icon>
                    {{ t('DeveloperTools.tickets.manageLabels') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="showLabelsDialog = false" />
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <div class="d-flex ga-2 mb-3">
                        <v-text-field
                            v-model="newLabelName"
                            :label="t('DeveloperTools.tickets.labelName')"
                            variant="outlined"
                            density="compact"
                            hide-details
                        />
                        <v-menu :close-on-content-click="false">
                            <template #activator="{ props }">
                                <v-btn v-bind="props" :color="newLabelColor" icon size="40" variant="flat" />
                            </template>
                            <v-color-picker v-model="newLabelColor" hide-inputs />
                        </v-menu>
                        <v-btn icon="mdi-plus" color="primary" variant="tonal" size="40" @click="saveLabel" />
                    </div>
                    <v-list density="compact">
                        <v-list-item v-for="label in masterData.labels" :key="label.id">
                            <template #prepend>
                                <v-chip size="small" :color="label.color" variant="flat">{{ label.name }}</v-chip>
                            </template>
                            <template #append>
                                <v-btn size="x-small" icon="mdi-delete" variant="text" color="error" @click="deleteLabel(label.id)" />
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card-text>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t } = useI18n();
const oserp = oserpStore();

// State
const loading = ref(false);
const saving = ref(false);
const tickets = ref([]);
const masterData = ref({ projects: [], specs: [], labels: [] });
const viewMode = ref(localStorage.getItem('tickets_viewMode') || 'kanban');
const searchText = ref('');
const filterPriority = ref(null);
const filterType = ref(null);
const filterProject = ref(null);
const filterAssignee = ref(null);

// Dialoge
const showTicketDialog = ref(false);
const showDetailDialog = ref(false);
const showLabelsDialog = ref(false);
const editingTicket = ref({});
const detailTicket = ref(null);
const detailComments = ref([]);
const newComment = ref('');

// Mitarbeiter aus dem Store (nur aktive, nicht-obsolete)
const activeEmployees = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return (oserp.session?.company_config?.employees || [])
        .filter(e => !e.deleted && (!e.enddate || e.enddate >= today))
        .map(e => ({ id: e.id, name: e.name }));
});

// Kanban Spalten
const kanbanColumns = [
    { status: 'open', label: t('DeveloperTools.tickets.statusOpen'), color: '#2196F3', icon: 'mdi-circle-outline' },
    { status: 'in_progress', label: t('DeveloperTools.tickets.statusInProgress'), color: '#FF9800', icon: 'mdi-progress-clock' },
    { status: 'review', label: t('DeveloperTools.tickets.statusReview'), color: '#9C27B0', icon: 'mdi-eye-check' },
    { status: 'done', label: t('DeveloperTools.tickets.statusDone'), color: '#4CAF50', icon: 'mdi-check-circle' },
];

const priorityItems = [
    { value: 'low', text: t('DeveloperTools.tickets.priorityLow') },
    { value: 'medium', text: t('DeveloperTools.tickets.priorityMedium') },
    { value: 'high', text: t('DeveloperTools.tickets.priorityHigh') },
    { value: 'critical', text: t('DeveloperTools.tickets.priorityCritical') },
];

const typeItems = [
    { value: 'bug', text: t('DeveloperTools.tickets.typeBug') },
    { value: 'feature', text: t('DeveloperTools.tickets.typeFeature') },
    { value: 'task', text: t('DeveloperTools.tickets.typeTask') },
    { value: 'improvement', text: t('DeveloperTools.tickets.typeImprovement') },
];

const statusItems = [
    { value: 'open', text: t('DeveloperTools.tickets.statusOpen') },
    { value: 'in_progress', text: t('DeveloperTools.tickets.statusInProgress') },
    { value: 'review', text: t('DeveloperTools.tickets.statusReview') },
    { value: 'done', text: t('DeveloperTools.tickets.statusDone') },
    { value: 'closed', text: t('DeveloperTools.tickets.statusClosed') },
];

// Labels
const newLabelName = ref('');
const newLabelColor = ref('#1976D2');

// Computed
const filteredTickets = computed(() => {
    let result = tickets.value;
    if (searchText.value) {
        const s = searchText.value.toLowerCase();
        result = result.filter(t => t.title.toLowerCase().includes(s) || t.ticket_number.toLowerCase().includes(s) || (t.description || '').toLowerCase().includes(s));
    }
    if (filterPriority.value) result = result.filter(t => t.priority === filterPriority.value);
    if (filterType.value) result = result.filter(t => t.ticket_type === filterType.value);
    if (filterProject.value) result = result.filter(t => String(t.project_id) === String(filterProject.value));
    if (filterAssignee.value) result = result.filter(t => String(t.assigned_to) === String(filterAssignee.value));
    return result;
});

function ticketsByStatus(status) {
    return tickets.value.filter(t => t.status === status);
}

function ticketsByStatusFiltered(status) {
    return filteredTickets.value.filter(t => t.status === status);
}

// Hilfsfunktionen
function getPriorityColor(p) {
    return { low: 'grey', medium: 'blue', high: 'orange', critical: 'red' }[p] || 'grey';
}
function getPriorityIcon(p) {
    return { low: 'mdi-arrow-down', medium: 'mdi-minus', high: 'mdi-arrow-up', critical: 'mdi-alert' }[p] || 'mdi-minus';
}
function getTypeColor(t) {
    return { bug: 'red', feature: 'green', task: 'blue', improvement: 'purple' }[t] || 'grey';
}
function getTypeIcon(t) {
    return { bug: 'mdi-bug', feature: 'mdi-star', task: 'mdi-checkbox-marked-circle', improvement: 'mdi-trending-up' }[t] || 'mdi-ticket';
}
function getStatusColor(s) {
    return { open: 'info', in_progress: 'warning', review: 'purple', done: 'success', closed: 'grey' }[s] || 'grey';
}
function getStatusLabel(s) {
    const found = statusItems.find(i => i.value === s);
    return found ? found.text : s;
}
function parseLabels(labels) {
    if (!labels) return [];
    if (typeof labels === 'string') {
        try { return JSON.parse(labels); } catch { return []; }
    }
    return labels;
}
function isOverdue(ticket) {
    if (!ticket.due_date || ['done', 'closed'].includes(ticket.status)) return false;
    return new Date(ticket.due_date) < new Date();
}
function formatDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('de-DE');
}
function formatDateTime(d) {
    if (!d) return '';
    return new Date(d).toLocaleString('de-DE');
}

// Drag & Drop
let draggedTicket = null;
function onDragStart(event, ticket) {
    draggedTicket = ticket;
    event.dataTransfer.effectAllowed = 'move';
}
async function onDrop(event, newStatus) {
    if (!draggedTicket || draggedTicket.status === newStatus) return;
    try {
        await axios.post('/api/project_management/', {
            action: 'updateTicketStatus',
            id: draggedTicket.id,
            status: newStatus,
        });
        draggedTicket.status = newStatus;
        if (['done', 'closed'].includes(newStatus)) draggedTicket.closed_at = new Date().toISOString();
    } catch (e) { console.error(e); }
    draggedTicket = null;
}

// API-Aufrufe
async function loadTickets() {
    loading.value = true;
    try {
        const res = await axios.post('/api/project_management/', { action: 'getTickets' });
        if (res.data.success) tickets.value = res.data.payload.tickets;
    } catch (e) { console.error(e); }
    loading.value = false;
}

async function loadMasterData() {
    try {
        const res = await axios.post('/api/project_management/', { action: 'getTicketMasterData' });
        if (res.data.success) masterData.value = res.data.payload;
    } catch (e) { console.error(e); }
}

function openTicketDialog(ticket = null) {
    if (ticket) {
        const labels = parseLabels(ticket.labels);
        editingTicket.value = { ...ticket, label_ids: labels.map(l => l.id) };
    } else {
        editingTicket.value = {
            title: '', description: '', ticket_type: 'task', priority: 'medium',
            status: 'open', assigned_to: null, reported_by: oserp.session.logged_in_employee?.id || null,
            project_id: null, requirement_spec_id: null, label_ids: [],
            estimated_hours: null, actual_hours: 0, due_date: null,
        };
    }
    showTicketDialog.value = true;
}

async function saveTicket() {
    saving.value = true;
    try {
        const res = await axios.post('/api/project_management/', { action: 'saveTicket', ticket: editingTicket.value });
        if (res.data.success) {
            showTicketDialog.value = false;
            await loadTickets();
        }
    } catch (e) { console.error(e); }
    saving.value = false;
}

async function confirmDeleteTicket(ticket) {
    if (!confirm(t('DeveloperTools.tickets.confirmDelete', { title: ticket.title }))) return;
    try {
        await axios.post('/api/project_management/', { action: 'deleteTicket', id: ticket.id });
        await loadTickets();
    } catch (e) { console.error(e); }
}

async function openDetailDialog(ticket) {
    detailTicket.value = ticket;
    showDetailDialog.value = true;
    newComment.value = '';
    try {
        const res = await axios.post('/api/project_management/', { action: 'getTicket', id: ticket.id });
        if (res.data.success) {
            detailTicket.value = res.data.payload.ticket;
            detailComments.value = res.data.payload.comments;
        }
    } catch (e) { console.error(e); }
}

async function quickStatusChange(ticket, newStatus) {
    try {
        await axios.post('/api/project_management/', { action: 'updateTicketStatus', id: ticket.id, status: newStatus });
        detailTicket.value.status = newStatus;
        const t2 = tickets.value.find(t => String(t.id) === String(ticket.id));
        if (t2) t2.status = newStatus;
    } catch (e) { console.error(e); }
}

async function addComment() {
    if (!newComment.value.trim()) return;
    try {
        await axios.post('/api/project_management/', {
            action: 'addTicketComment',
            ticket_id: detailTicket.value.id,
            comment: newComment.value,
        });
        newComment.value = '';
        // Kommentare neu laden
        const res = await axios.post('/api/project_management/', { action: 'getTicket', id: detailTicket.value.id });
        if (res.data.success) detailComments.value = res.data.payload.comments;
        // Kommentarzahl aktualisieren
        const t2 = tickets.value.find(t => String(t.id) === String(detailTicket.value.id));
        if (t2) t2.comment_count = detailComments.value.length;
    } catch (e) { console.error(e); }
}

async function deleteComment(commentId) {
    try {
        await axios.post('/api/project_management/', { action: 'deleteTicketComment', id: commentId });
        detailComments.value = detailComments.value.filter(c => String(c.id) !== String(commentId));
    } catch (e) { console.error(e); }
}

// Label-Verwaltung
async function saveLabel() {
    if (!newLabelName.value.trim()) return;
    try {
        await axios.post('/api/project_management/', { action: 'saveTicketLabel', label: { name: newLabelName.value, color: newLabelColor.value } });
        newLabelName.value = '';
        await loadMasterData();
    } catch (e) { console.error(e); }
}

async function deleteLabel(id) {
    try {
        await axios.post('/api/project_management/', { action: 'deleteTicketLabel', id });
        await loadMasterData();
    } catch (e) { console.error(e); }
}

// View-Mode Persistenz
import { watch } from 'vue';
watch(viewMode, v => localStorage.setItem('tickets_viewMode', v));

onMounted(() => {
    loadTickets();
    loadMasterData();
});
</script>

<style scoped>
.kanban-board {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    padding-bottom: 8px;
    min-height: 400px;
}

.kanban-column {
    flex: 1;
    min-width: 260px;
    max-width: 350px;
    background: rgb(var(--v-theme-surface));
    border-radius: 8px;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    display: flex;
    flex-direction: column;
}

.kanban-column-header {
    padding: 10px 12px;
    border-top: 3px solid;
    border-radius: 8px 8px 0 0;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    background: rgba(var(--v-theme-on-surface), 0.03);
}

.kanban-column-body {
    padding: 8px;
    flex: 1;
    overflow-y: auto;
    max-height: 600px;
}

.kanban-card {
    cursor: grab;
    transition: all 0.15s ease;
}
.kanban-card:active {
    cursor: grabbing;
    opacity: 0.7;
    transform: rotate(2deg);
}
.kanban-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.ticket-title {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.4;
}

.cursor-pointer {
    cursor: pointer;
}
</style>
