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
                <v-col cols="12" md="2">
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
                <v-col cols="6" md="2">
                    <v-select
                        v-model="filterStatus"
                        :items="statusItems"
                        item-title="text"
                        item-value="value"
                        :label="t('DeveloperTools.tickets.status')"
                        variant="outlined"
                        density="compact"
                        multiple
                        clearable
                        hide-details
                    >
                        <template #selection="{ index }">
                            <span v-if="index === 0" class="text-no-wrap">
                                {{ getStatusLabel(filterStatus[0]) }}<span v-if="filterStatus.length > 1" class="text-medium-emphasis"> (+{{ filterStatus.length - 1 }})</span>
                            </span>
                        </template>
                    </v-select>
                </v-col>
                <v-col cols="6" md="2">
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
                <v-col cols="6" md="2">
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
                <v-col cols="6" md="2">
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
                <v-col cols="6" md="2">
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

        <!-- Statistik-Chips, zugleich Schnellfilter nach Status -->
        <div class="d-flex flex-wrap ga-2 mb-4" v-if="tickets.length > 0">
            <v-chip size="small" :variant="filterStatus.length === 0 ? 'flat' : 'tonal'" color="grey" @click="filterStatus = []">
                {{ t('DeveloperTools.tickets.total') }}: {{ tickets.length }}
            </v-chip>
            <template v-for="s in statusItems" :key="s.value">
                <v-chip
                    v-if="s.value !== 'closed' || ticketsByStatus('closed').length > 0"
                    size="small"
                    :variant="filterStatus.includes(s.value) ? 'flat' : 'tonal'"
                    :color="getStatusColor(s.value)"
                    :prepend-icon="filterStatus.includes(s.value) ? 'mdi-filter' : undefined"
                    @click="toggleStatusFilter(s.value)"
                >
                    {{ s.text }}: {{ ticketsByStatus(s.value).length }}
                </v-chip>
            </template>
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
                                    <span v-if="ticket.attachment_count > 0" class="d-flex align-center">
                                        <v-icon size="12" class="mr-1">mdi-paperclip</v-icon>
                                        {{ ticket.attachment_count }}
                                    </span>
                                </div>
                            </div>
                        </v-card-text>
                    </v-card>
                </div>
            </div>
        </div>

        <!-- Auswahl-Leiste der Listenansicht -->
        <v-alert
            v-if="viewMode === 'list' && selectedIds.length > 0"
            type="info"
            variant="tonal"
            density="compact"
            icon="mdi-checkbox-multiple-marked"
            class="mb-2"
        >
            <div class="d-flex align-center flex-wrap ga-2">
                <span class="font-weight-medium">{{ t('DeveloperTools.tickets.selected', { count: selectedIds.length }) }}</span>
                <v-spacer />
                <v-btn size="small" variant="text" @click="selectedIds = []">
                    {{ t('DeveloperTools.tickets.clearSelection') }}
                </v-btn>
                <v-btn size="small" variant="flat" color="error" prepend-icon="mdi-delete" :loading="deleting" @click="deleteSelectedTickets">
                    {{ t('delete') }}
                </v-btn>
            </div>
        </v-alert>

        <!-- LISTEN-ANSICHT -->
        <v-card v-if="viewMode === 'list' && filteredTickets.length > 0" variant="outlined">
            <v-table density="compact" hover>
                <thead>
                    <tr>
                        <th style="width: 40px" class="px-1">
                            <v-checkbox-btn
                                :model-value="allSelected"
                                :indeterminate="selectedIds.length > 0 && !allSelected"
                                density="compact"
                                @update:model-value="toggleSelectAll"
                            />
                        </th>
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
                        :class="{ 'ticket-row-selected': selectedIds.includes(ticket.id) }"
                        @click="openDetailDialog(ticket)"
                    >
                        <td class="px-1" @click.stop>
                            <v-checkbox-btn v-model="selectedIds" :value="ticket.id" density="compact" />
                        </td>
                        <td>
                            <span class="text-caption font-weight-bold">{{ ticket.ticket_number }}</span>
                            <v-icon v-if="ticket.attachment_count > 0" size="12" class="ml-1 text-medium-emphasis">mdi-paperclip</v-icon>
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
                        <td class="text-no-wrap">
                            <v-btn size="x-small" icon="mdi-pencil" variant="text" @click.stop="openTicketDialog(ticket)" />
                            <v-btn size="x-small" icon="mdi-delete" variant="text" color="error" @click.stop="confirmDeleteTicket(ticket)" />
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>

        <!-- TICKET-DETAIL DIALOG -->
        <v-dialog v-model="showDetailDialog" max-width="900" scrollable>
            <v-card
                v-if="detailTicket"
                class="ticket-drop-target"
                @paste="onPaste($event, commentFiles)"
                @dragenter="onDragEnter"
                @dragover.prevent
                @dragleave="onDragLeave"
                @drop.prevent="onDropFiles($event, commentFiles)"
            >
                <div v-if="dropActive" class="ticket-drop-overlay">
                    <v-icon size="48" color="primary">mdi-tray-arrow-down</v-icon>
                    <div class="text-subtitle-1 font-weight-medium">{{ t('DeveloperTools.tickets.dropHere') }}</div>
                </div>
                <v-card-title class="d-flex align-center bg-primary text-white">
                    <v-chip size="small" variant="flat" color="white" text-color="primary" class="mr-2">
                        {{ detailTicket.ticket_number }}
                    </v-chip>
                    {{ detailTicket.title }}
                    <v-spacer />
                    <v-btn icon="mdi-pencil" size="x-small" variant="text" color="white" class="mr-1" @click="openTicketDialog(detailTicket); showDetailDialog = false" />
                    <v-btn icon="mdi-delete" size="x-small" variant="text" color="white" class="mr-1" :title="t('DeveloperTools.tickets.deleteTicket')" @click="confirmDeleteTicket(detailTicket)" />
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

                    <!-- Anhänge am Ticket -->
                    <div v-if="ticketAttachments.length > 0" class="mb-4">
                        <div class="text-subtitle-2 font-weight-bold mb-2">
                            <v-icon start size="small">mdi-paperclip</v-icon>
                            {{ t('DeveloperTools.tickets.attachments') }}
                            <v-chip size="x-small" class="ml-1">{{ ticketAttachments.length }}</v-chip>
                        </div>
                        <div class="ticket-attachments">
                            <template v-for="a in ticketAttachments" :key="a.id">
                                <div v-if="isImage(a)" class="ticket-thumb" :title="a.filename" @click="openPreview(a)">
                                    <img :src="attachmentUrl(a)" :alt="a.filename" loading="lazy">
                                    <v-btn class="ticket-thumb-delete" icon="mdi-close" size="x-small" variant="flat" density="comfortable" @click.stop="deleteAttachment(a)" />
                                </div>
                                <v-chip v-else :href="attachmentUrl(a)" target="_blank" variant="outlined" size="small" :prepend-icon="getFileIcon(a.mime_type)" closable @click:close.prevent="deleteAttachment(a)">
                                    {{ a.filename }} <span class="text-medium-emphasis ml-1">{{ formatSize(a.file_size) }}</span>
                                </v-chip>
                            </template>
                        </div>
                    </div>

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
                                <div v-if="comment.comment" style="white-space: pre-wrap">{{ comment.comment }}</div>
                                <div v-if="commentAttachments(comment.id).length > 0" class="ticket-attachments mt-2">
                                    <template v-for="a in commentAttachments(comment.id)" :key="a.id">
                                        <div v-if="isImage(a)" class="ticket-thumb" :title="a.filename" @click="openPreview(a)">
                                            <img :src="attachmentUrl(a)" :alt="a.filename" loading="lazy">
                                        </div>
                                        <v-chip v-else :href="attachmentUrl(a)" target="_blank" variant="outlined" size="small" :prepend-icon="getFileIcon(a.mime_type)">
                                            {{ a.filename }} <span class="text-medium-emphasis ml-1">{{ formatSize(a.file_size) }}</span>
                                        </v-chip>
                                    </template>
                                </div>
                            </v-card-text>
                        </v-card>
                    </div>

                    <!-- Neuer Kommentar -->
                    <v-textarea
                        ref="commentField"
                        v-model="newComment"
                        :label="t('DeveloperTools.tickets.newComment')"
                        :hint="t('DeveloperTools.tickets.attachHint')"
                        persistent-hint
                        variant="outlined"
                        density="compact"
                        rows="2"
                        auto-grow
                        class="mt-3"
                    />
                    <div v-if="commentFiles.length > 0" class="ticket-attachments mt-2">
                        <div v-for="(f, i) in commentFiles" :key="f.key" class="ticket-thumb" :title="f.file.name">
                            <img v-if="f.preview" :src="f.preview" :alt="f.file.name">
                            <div v-else class="ticket-thumb-file">
                                <v-icon>{{ getFileIcon(f.file.type) }}</v-icon>
                                <span>{{ f.file.name }}</span>
                            </div>
                            <v-btn class="ticket-thumb-delete" icon="mdi-close" size="x-small" variant="flat" density="comfortable" @click.stop="removeStaged(commentFiles, i)" />
                        </div>
                    </div>
                    <div class="d-flex align-center justify-end ga-2 mt-2">
                        <v-btn size="small" variant="text" prepend-icon="mdi-paperclip" @click="pickFiles(commentFiles)">
                            {{ t('DeveloperTools.tickets.attachFile') }}
                        </v-btn>
                        <v-btn
                            size="small"
                            color="primary"
                            variant="tonal"
                            prepend-icon="mdi-send"
                            :disabled="!newComment.trim() && commentFiles.length === 0"
                            :loading="sendingComment"
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
            <v-card
                class="ticket-drop-target"
                @paste="onPaste($event, ticketFiles)"
                @dragenter="onDragEnter"
                @dragover.prevent
                @dragleave="onDragLeave"
                @drop.prevent="onDropFiles($event, ticketFiles)"
            >
                <div v-if="dropActive" class="ticket-drop-overlay">
                    <v-icon size="48" color="primary">mdi-tray-arrow-down</v-icon>
                    <div class="text-subtitle-1 font-weight-medium">{{ t('DeveloperTools.tickets.dropHere') }}</div>
                </div>
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
                                :hint="t('DeveloperTools.tickets.attachHint')"
                                persistent-hint
                                variant="outlined"
                                density="comfortable"
                                rows="3"
                                auto-grow
                            />
                            <div class="d-flex align-center flex-wrap ga-2 mt-2">
                                <div v-for="(f, i) in ticketFiles" :key="f.key" class="ticket-thumb" :title="f.file.name">
                                    <img v-if="f.preview" :src="f.preview" :alt="f.file.name">
                                    <div v-else class="ticket-thumb-file">
                                        <v-icon>{{ getFileIcon(f.file.type) }}</v-icon>
                                        <span>{{ f.file.name }}</span>
                                    </div>
                                    <v-btn class="ticket-thumb-delete" icon="mdi-close" size="x-small" variant="flat" density="comfortable" @click.stop="removeStaged(ticketFiles, i)" />
                                </div>
                                <v-btn size="small" variant="text" prepend-icon="mdi-paperclip" @click="pickFiles(ticketFiles)">
                                    {{ t('DeveloperTools.tickets.attachFile') }}
                                </v-btn>
                            </div>
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
                                    <v-chip size="small" :color="item.color" variant="flat">{{ item.name }}</v-chip>
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

        <!-- BILD-VORSCHAU -->
        <v-dialog v-model="showPreview" max-width="1400">
            <v-card v-if="previewAttachment" @keydown.left="stepPreview(-1)" @keydown.right="stepPreview(1)">
                <v-card-title class="d-flex align-center">
                    <v-icon start size="small">mdi-image</v-icon>
                    <span class="text-body-1 text-truncate">{{ previewAttachment.filename }}</span>
                    <span class="text-caption text-medium-emphasis ml-2">{{ formatSize(previewAttachment.file_size) }}</span>
                    <v-spacer />
                    <v-btn v-if="previewImages.length > 1" icon="mdi-chevron-left" size="small" variant="text" @click="stepPreview(-1)" />
                    <v-btn v-if="previewImages.length > 1" icon="mdi-chevron-right" size="small" variant="text" @click="stepPreview(1)" />
                    <v-btn icon="mdi-download" size="small" variant="text" :href="attachmentUrl(previewAttachment, true)" :title="t('DeveloperTools.tickets.download')" />
                    <v-btn icon="mdi-close" size="small" variant="text" @click="showPreview = false" />
                </v-card-title>
                <v-card-text class="pa-2 text-center">
                    <img :src="attachmentUrl(previewAttachment)" :alt="previewAttachment.filename" class="ticket-preview-image">
                </v-card-text>
            </v-card>
        </v-dialog>

        <input ref="fileInput" type="file" multiple hidden @change="onFilesPicked">

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
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { oserpStore } from '@/core/stores/oserp.store.js';
import * as alerts from '@/core/utils/alerts.js';
import * as toasts from '@/core/utils/toasts.js';

const { t } = useI18n();
const oserp = oserpStore();

const MAX_FILE_SIZE = 20 * 1024 * 1024; // wie FM_MAX_FILESIZE im Backend

// State
const loading = ref(false);
const saving = ref(false);
const deleting = ref(false);
const selectedIds = ref([]);
const tickets = ref([]);
const masterData = ref({ projects: [], specs: [], labels: [] });
const viewMode = ref(localStorage.getItem('tickets_viewMode') || 'kanban');
const searchText = ref('');
const filterStatus = ref([]);
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
const detailAttachments = ref([]);
const newComment = ref('');
const sendingComment = ref(false);

// Anhänge: vorgemerkte Dateien je Dialog, hochgeladen beim Speichern bzw. Senden
const commentFiles = ref([]);
const ticketFiles = ref([]);
const dropActive = ref(false);
const fileInput = ref(null);
const commentField = ref(null);
const showPreview = ref(false);
const previewAttachment = ref(null);
let pickTarget = null;
let dragDepth = 0;
let stagedKey = 0;

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
    if (filterStatus.value?.length) result = result.filter(t => filterStatus.value.includes(t.status));
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

function toggleStatusFilter(status) {
    filterStatus.value = filterStatus.value.includes(status)
        ? filterStatus.value.filter(s => s !== status)
        : [...filterStatus.value, status];
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
            await uploadStaged(res.data.payload.id, ticketFiles.value);
            showTicketDialog.value = false;
            await loadTickets();
        }
    } catch (e) { console.error(e); }
    saving.value = false;
}

async function deleteTickets(ids) {
    deleting.value = true;
    try {
        const res = await axios.post('/api/project_management/', { action: 'deleteTickets', ids });
        if (res.data.success) {
            const geloescht = res.data.payload.ids.map(String);
            selectedIds.value = selectedIds.value.filter(id => !geloescht.includes(String(id)));
            if (detailTicket.value && geloescht.includes(String(detailTicket.value.id))) showDetailDialog.value = false;
            toasts.success(t('DeveloperTools.tickets.deleted', geloescht.length));
            await loadTickets();
        }
    } catch (e) { console.error(e); }
    deleting.value = false;
}

async function confirmDeleteTicket(ticket) {
    const antwort = await alerts.warning(t('DeveloperTools.tickets.confirmDelete', { title: ticket.title }), '', t('delete'), t('cancel'));
    if (antwort?.isConfirmed) await deleteTickets([ticket.id]);
}

async function deleteSelectedTickets() {
    const antwort = await alerts.warning(t('DeveloperTools.tickets.confirmDeleteMany', { count: selectedIds.value.length }), '', t('delete'), t('cancel'));
    if (antwort?.isConfirmed) await deleteTickets([...selectedIds.value]);
}

async function loadDetail(id) {
    try {
        const res = await axios.post('/api/project_management/', { action: 'getTicket', id });
        if (res.data.success) {
            detailTicket.value = res.data.payload.ticket;
            detailComments.value = res.data.payload.comments;
            detailAttachments.value = res.data.payload.attachments;
            // Zähler auf Karte und Liste mitziehen
            const t2 = tickets.value.find(t => String(t.id) === String(id));
            if (t2) {
                t2.comment_count = detailComments.value.length;
                t2.attachment_count = detailAttachments.value.length;
            }
        }
    } catch (e) { console.error(e); }
}

async function openDetailDialog(ticket) {
    detailTicket.value = ticket;
    detailComments.value = [];
    detailAttachments.value = [];
    clearStaged(commentFiles.value);
    showDetailDialog.value = true;
    newComment.value = '';
    await loadDetail(ticket.id);
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
    if (!newComment.value.trim() && commentFiles.value.length === 0) return;
    sendingComment.value = true;
    try {
        // Nur Dateien ohne Text: hängen direkt am Ticket
        let commentId = null;
        if (newComment.value.trim()) {
            const res = await axios.post('/api/project_management/', {
                action: 'addTicketComment',
                ticket_id: detailTicket.value.id,
                comment: newComment.value,
            });
            if (!res.data.success) return;
            commentId = res.data.payload.id;
        }
        await uploadStaged(detailTicket.value.id, commentFiles.value, commentId);
        newComment.value = '';
        await loadDetail(detailTicket.value.id);
    } catch (e) {
        console.error(e);
    } finally {
        sendingComment.value = false;
    }
}

async function deleteComment(commentId) {
    try {
        await axios.post('/api/project_management/', { action: 'deleteTicketComment', id: commentId });
        await loadDetail(detailTicket.value.id);
    } catch (e) { console.error(e); }
}

// Anhänge
const ticketAttachments = computed(() => detailAttachments.value.filter(a => !a.comment_id));
const previewImages = computed(() => detailAttachments.value.filter(isImage));

function commentAttachments(commentId) {
    return detailAttachments.value.filter(a => String(a.comment_id) === String(commentId));
}
function isImage(a) {
    return /^image\/(png|jpe?g|gif|webp|bmp)$/.test(a.mime_type);
}
function attachmentUrl(a, download = false) {
    return `/api/project_management/?action=getTicketAttachment&id=${a.id}${download ? '&download=1' : ''}`;
}
function getFileIcon(mime) {
    if (!mime) return 'mdi-file-outline';
    if (mime.startsWith('image/')) return 'mdi-file-image-outline';
    if (mime === 'application/pdf') return 'mdi-file-pdf-box';
    if (mime.startsWith('video/')) return 'mdi-file-video-outline';
    if (mime.startsWith('text/')) return 'mdi-file-document-outline';
    if (/zip|compressed|tar|rar|7z/.test(mime)) return 'mdi-folder-zip-outline';
    if (/sheet|excel|csv/.test(mime)) return 'mdi-file-excel-outline';
    if (/word|opendocument\.text/.test(mime)) return 'mdi-file-word-outline';
    return 'mdi-file-outline';
}
function formatSize(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toLocaleString('de-DE', { maximumFractionDigits: 0 })} KB`;
    return `${(n / 1024 / 1024).toLocaleString('de-DE', { maximumFractionDigits: 1 })} MB`;
}

function stageFiles(target, files) {
    for (const file of files) {
        if (file.size > MAX_FILE_SIZE) {
            toasts.error(t('DeveloperTools.tickets.fileTooLarge', { name: file.name, size: formatSize(MAX_FILE_SIZE) }));
            continue;
        }
        const preview = isImage({ mime_type: file.type }) ? URL.createObjectURL(file) : null;
        target.push({ key: ++stagedKey, file, preview });
    }
}
function removeStaged(target, index) {
    const [f] = target.splice(index, 1);
    if (f?.preview) URL.revokeObjectURL(f.preview);
}
function clearStaged(target) {
    target.forEach(f => f.preview && URL.revokeObjectURL(f.preview));
    target.splice(0);
}

// Strg+V: Screenshots aus der Zwischenablage. Mit Text dabei (Excel, Word)
// bleibt es ein normales Einfügen.
function onPaste(event, target) {
    const files = [...(event.clipboardData?.files || [])];
    if (files.length === 0 || event.clipboardData.getData('text/plain')) return;
    event.preventDefault();
    const jetzt = new Date();
    const stamp = new Date(jetzt - jetzt.getTimezoneOffset() * 60000).toISOString().slice(0, 19).replace('T', '_').replace(/:/g, '-');
    stageFiles(target, files.map((f, i) => /^image\.\w+$/i.test(f.name)
        ? new File([f], `screenshot_${stamp}${files.length > 1 ? '_' + (i + 1) : ''}.${f.name.split('.').pop()}`, { type: f.type })
        : f));
    if (target === commentFiles.value) commentField.value?.focus();
}

function onDragEnter(event) {
    if (!event.dataTransfer?.types?.includes('Files')) return;
    dragDepth++;
    dropActive.value = true;
}
function onDragLeave() {
    if (dragDepth > 0 && --dragDepth === 0) dropActive.value = false;
}
function onDropFiles(event, target) {
    dragDepth = 0;
    dropActive.value = false;
    stageFiles(target, [...(event.dataTransfer?.files || [])]);
    if (target === commentFiles.value) commentField.value?.focus();
}

function pickFiles(target) {
    pickTarget = target;
    fileInput.value.value = '';
    fileInput.value.click();
}
function onFilesPicked(event) {
    if (pickTarget) stageFiles(pickTarget, [...event.target.files]);
}

function readBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(file);
    });
}

async function uploadStaged(ticketId, target, commentId = null) {
    for (const f of target) {
        try {
            const res = await axios.post('/api/project_management/', {
                action: 'uploadTicketAttachment',
                ticket_id: ticketId,
                comment_id: commentId,
                filename: f.file.name,
                file_base64: await readBase64(f.file),
            });
            if (!res.data.success) {
                throw new Error(typeof res.data.payload === 'string' ? res.data.payload : res.data.text);
            }
        } catch (e) {
            console.error(e);
            // Grund vom Server mit anzeigen, sonst bleibt die Ursache unsichtbar
            toasts.error(t('DeveloperTools.tickets.uploadFailed', { name: f.file.name }), { text: e.message, timer: 10000 });
        }
    }
    clearStaged(target);
}

async function deleteAttachment(a) {
    const antwort = await alerts.warning(t('DeveloperTools.tickets.confirmDeleteAttachment', { name: a.filename }), '', t('delete'), t('cancel'));
    if (!antwort?.isConfirmed) return;
    try {
        await axios.post('/api/project_management/', { action: 'deleteTicketAttachment', id: a.id });
        await loadDetail(detailTicket.value.id);
    } catch (e) { console.error(e); }
}

function openPreview(a) {
    previewAttachment.value = a;
    showPreview.value = true;
}
function stepPreview(richtung) {
    const bilder = previewImages.value;
    const i = bilder.findIndex(b => b.id === previewAttachment.value?.id);
    previewAttachment.value = bilder[(i + richtung + bilder.length) % bilder.length];
}

// Mehrfachauswahl der Listenansicht
const allSelected = computed(() => filteredTickets.value.length > 0
    && filteredTickets.value.every(ticket => selectedIds.value.includes(ticket.id)));

function toggleSelectAll(alle) {
    selectedIds.value = alle ? filteredTickets.value.map(ticket => ticket.id) : [];
}

// Ausgefilterte Tickets fallen aus der Auswahl — gelöscht wird nur, was man sieht
watch(filteredTickets, list => {
    const sichtbar = new Set(list.map(ticket => ticket.id));
    selectedIds.value = selectedIds.value.filter(id => sichtbar.has(id));
});
watch(showTicketDialog, offen => { if (!offen) clearStaged(ticketFiles.value); });
watch(showDetailDialog, offen => { if (!offen) clearStaged(commentFiles.value); });

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

.ticket-row-selected {
    background: rgba(var(--v-theme-primary), 0.06);
}

/* Anhänge */
.ticket-drop-target {
    position: relative;
}
.ticket-drop-overlay {
    position: absolute;
    inset: 0;
    z-index: 10;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--oserp-gap-sm);
    background: rgba(var(--v-theme-surface), 0.92);
    border: 2px dashed rgb(var(--v-theme-primary));
    border-radius: var(--oserp-radius);
    pointer-events: none;
}
.ticket-attachments {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--oserp-gap-sm);
}
.ticket-thumb {
    position: relative;
    width: 96px;
    height: 72px;
    border: 1px solid var(--oserp-border);
    border-radius: var(--oserp-radius-sm);
    background: var(--oserp-surface-soft);
    overflow: hidden;
    cursor: pointer;
}
.ticket-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.ticket-thumb-file {
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: var(--oserp-gap-xs);
    font-size: 0.7rem;
    color: var(--oserp-text-muted);
    text-align: center;
}
.ticket-thumb-file span {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ticket-thumb-delete {
    position: absolute;
    top: 2px;
    right: 2px;
    opacity: 0;
    transition: opacity 0.15s ease;
}
.ticket-thumb:hover .ticket-thumb-delete {
    opacity: 1;
}
.ticket-preview-image {
    max-width: 100%;
    max-height: 80vh;
    object-fit: contain;
}
</style>
