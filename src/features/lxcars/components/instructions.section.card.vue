<!-- src/features/lxcars/components/instructions.section.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card" :style="timeBudgetStyle">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-clipboard-check-outline</v-icon>
            <span class="text-subtitle-1 font-weight-medium">{{ t('FakturaView.faktura.instructions.title') }}</span>
            <v-chip v-if="instructions.length" size="x-small" variant="tonal" :color="allDone ? 'success' : 'primary'" class="ml-2">
                {{ doneCount }}/{{ instructions.length }} {{ t('FakturaView.faktura.instructions.done') }}
            </v-chip>
            <v-chip v-if="totalPlanned > 0" size="x-small" variant="tonal" color="info" class="ml-2">
                {{ formatDuration(totalPlanned) }} {{ t('FakturaView.faktura.instructions.plannedTotal') }}
            </v-chip>
            <v-chip v-if="totalActual > 0" size="x-small" variant="tonal" color="warning" class="ml-2">
                {{ formatDuration(totalActual) }} {{ t('FakturaView.faktura.instructions.actualTotal') }}
            </v-chip>
            <v-chip
                v-if="totalPlanned > 0 && totalActual > 0"
                size="x-small"
                variant="elevated"
                :color="timeDiffColor"
                class="ml-2 font-weight-bold"
            >
                {{ timeDiffLabel }}
            </v-chip>
            <v-spacer />
            <v-tooltip v-if="instructions.length" location="bottom" :text="t('FakturaView.faktura.instructions.aiSuggestTimes')">
                <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" icon="mdi-creation" size="small" variant="text" color="purple"
                           :loading="aiTimesLoading" @click="onAiSuggestTimes" />
                </template>
            </v-tooltip>
            <v-btn icon="mdi-cog-outline" size="small" variant="text" :title="t('FakturaView.faktura.instructions.masterTitle')" @click="showMasterDialog = true" />
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__table-body">
            <!-- Loading -->
            <div v-if="loading" class="text-center py-4">
                <v-progress-circular indeterminate size="24" />
            </div>

            <template v-else>
                <v-table density="compact" class="instructions-table">
                    <thead>
                        <tr>
                            <th style="width: 45px; text-align: center">{{ t('FakturaView.faktura.position') }}</th>
                            <th style="width: 36px; text-align: center">
                                <v-icon size="small" color="grey" :title="t('FakturaView.faktura.tooltipDrag')">mdi-drag-vertical</v-icon>
                            </th>
                            <th style="width: 36px; text-align: center">
                                <v-icon size="small" color="grey" :title="t('FakturaView.faktura.tooltipEdit')">mdi-pencil</v-icon>
                            </th>
                            <th style="width: 36px; text-align: center">
                                <v-icon size="small" color="grey" :title="t('FakturaView.faktura.tooltipDone')">mdi-check</v-icon>
                            </th>
                            <th style="width: 70px; text-align: center">{{ t('FakturaView.faktura.instructions.number') }}</th>
                            <th>{{ t('FakturaView.faktura.instructions.description') }}</th>
                            <th style="width: 90px; text-align: center">{{ t('FakturaView.faktura.instructions.plannedTime') }}</th>
                            <th style="width: 90px; text-align: center">{{ t('FakturaView.faktura.instructions.actualTime') }}</th>
                            <th style="width: 52px; text-align: center">
                                <v-icon size="small" color="grey" :title="t('FakturaView.faktura.instructions.timerTooltip')">mdi-timer-outline</v-icon>
                            </th>
                            <th style="width: 200px">
                                <v-autocomplete
                                    v-model="globalEmployeeId"
                                    :items="employeeList"
                                    item-title="name"
                                    item-value="id"
                                    :placeholder="t('FakturaView.faktura.instructions.completedBy')"
                                    variant="underlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                    clearable
                                    single-line
                                    @update:model-value="onGlobalEmployeeChange"
                                />
                            </th>
                            <th style="width: 36px; text-align: center">
                                <v-icon size="small" color="grey" style="cursor: pointer" :title="t('FakturaView.faktura.instructions.deleteAllTooltip')" @click="confirmDeleteAll">mdi-delete</v-icon>
                            </th>
                        </tr>
                    </thead>
                    <draggable
                        :list="instructions"
                        tag="tbody"
                        item-key="id"
                        handle=".drag-handle"
                        @change="onDragChange"
                    >
                        <template #item="{ element: instruction, index }">
                            <tr :class="{ 'row-done': instruction.done }">
                                <!-- Position (1,2,3,...) -->
                                <td class="text-center field-disabled">
                                    {{ index + 1 }}
                                </td>
                                <!-- Drag Handle -->
                                <td class="text-center">
                                    <v-icon class="drag-handle" size="small" style="cursor: move">
                                        mdi-drag-vertical
                                    </v-icon>
                                </td>
                                <!-- Edit -->
                                <td class="text-center">
                                    <v-btn
                                        icon="mdi-pencil"
                                        size="x-small"
                                        variant="text"
                                        color="primary"
                                        @click="openEditDialog(instruction, index)"
                                    />
                                </td>
                                <!-- Done -->
                                <td class="text-center instruction-done-cell" @click="onToggleDone(instruction, index)">
                                    <v-checkbox
                                        :model-value="instruction.done"
                                        hide-details
                                        density="compact"
                                        class="instruction-checkbox"
                                        readonly
                                    />
                                </td>
                                <!-- Instruction Number -->
                                <td class="text-center" :class="{ 'field-disabled': instruction.done }">
                                    {{ instruction.instruction_number }}
                                </td>
                                <!-- Description (inline editierbar wie bei Positionen) -->
                                <td>
                                    <v-text-field
                                        :ref="el => { if (el) descriptionRefs[index] = el }"
                                        v-model="instruction.description"
                                        :class="{ 'field-disabled': instruction.done }"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        autocomplete="off"
                                        @blur="onDescriptionBlur(instruction)"
                                    />
                                </td>
                                <!-- Geplante Zeit -->
                                <td style="width: 90px">
                                    <v-text-field
                                        :ref="el => { if (el) plannedTimeRefs[index] = el }"
                                        v-model="instruction._plannedDisplay"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        autocomplete="off"
                                        placeholder="0:00"
                                        class="time-input"
                                        @blur="onPlannedBlur(instruction)"
                                        @keydown.enter.prevent="onPlannedNext(instruction, index)"
                                        @keydown.tab.prevent="onPlannedNext(instruction, index)"
                                    />
                                </td>
                                <!-- Benötigte Zeit -->
                                <td style="width: 90px">
                                    <v-text-field
                                        :ref="el => { if (el) actualTimeRefs[index] = el }"
                                        v-model="instruction._actualDisplay"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        autocomplete="off"
                                        placeholder="0:00"
                                        class="time-input"
                                        @blur="onActualBlur(instruction)"
                                        @keydown.enter.prevent="onActualNext(instruction, index)"
                                        @keydown.tab.prevent="onActualNext(instruction, index)"
                                    />
                                </td>
                                <!-- Timer -->
                                <td class="text-center timer-cell" style="width: 52px">
                                    <div v-if="timerRunning(instruction.id)" class="d-flex flex-column align-center">
                                        <v-btn
                                            icon="mdi-stop"
                                            size="x-small"
                                            variant="flat"
                                            color="error"
                                            :title="t('FakturaView.faktura.instructions.timerStop')"
                                            @click="onTimerStop(instruction, index)"
                                        />
                                        <span class="timer-display" :class="{ 'timer-paused': timerPaused(instruction.id) }">
                                            {{ timerElapsed(instruction.id) }}
                                        </span>
                                    </div>
                                    <v-btn
                                        v-else
                                        icon="mdi-play"
                                        size="x-small"
                                        variant="tonal"
                                        color="success"
                                        :disabled="instruction.done"
                                        :title="t('FakturaView.faktura.instructions.timerStart')"
                                        @click="onTimerStart(instruction, index)"
                                    />
                                </td>
                                <!-- Erledigt von -->
                                <td style="width: 160px">
                                    <v-autocomplete
                                        :ref="el => { if (el) employeeRefs[index] = el }"
                                        v-model="instruction.employee_id"
                                        :items="employeeList"
                                        item-title="name"
                                        item-value="id"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        autocomplete="off"
                                        clearable
                                        @update:model-value="onFieldUpdate(instruction, 'employee_id', $event)"
                                    />
                                </td>
                                <!-- Delete -->
                                <td class="text-center">
                                    <v-btn
                                        icon="mdi-close"
                                        size="x-small"
                                        variant="text"
                                        color="grey"
                                        @click="onDelete(instruction, index)"
                                    />
                                </td>
                            </tr>
                        </template>
                    </draggable>
                    <!-- Add-Row -->
                    <tbody>
                        <tr class="new-item-row">
                            <td class="text-center field-disabled"></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td>
                                <v-autocomplete
                                    ref="addInputRef"
                                    v-model="newInstructionSelected"
                                    v-model:search="newInstructionSearch"
                                    :items="suggestions"
                                    :loading="false"
                                    item-title="description"
                                    item-value="description"
                                    :placeholder="t('FakturaView.faktura.instructions.addPlaceholder')"
                                    :hide-no-data="true"
                                    no-filter
                                    autocomplete="off"
                                    class="new-item-input"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    clearable
                                    return-object
                                    @update:search="onSearchInput"
                                    @update:model-value="onSuggestionSelect"
                                    @keydown.enter="onEnterAdd"
                                >
                                    <template #item="{ props: itemProps, item }">
                                        <v-list-item v-bind="itemProps" :title="undefined">
                                            <template #prepend>
                                                <span v-if="item.raw.instruction_number" class="text-grey font-weight-medium mr-3" style="font-size: 12px; min-width: 40px">{{ item.raw.instruction_number }}</span>
                                            </template>
                                            <template #title>{{ item.raw.description }}</template>
                                        </v-list-item>
                                    </template>
                                </v-autocomplete>
                            </td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </v-table>
            </template>
        </v-card-text>
    </v-card>

    <!-- Edit-Dialog (wie edit.part.dialog) -->
    <v-dialog v-model="editDialog.show" max-width="600" @keydown.esc="closeEditDialog">
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4 bg-primary text-white">
                <v-icon class="mr-2">mdi-pencil</v-icon>
                {{ t('FakturaView.faktura.instructions.editTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" color="white" @click="closeEditDialog" />
            </v-card-title>
            <v-card-text class="pt-4">
                <v-row v-if="editDialog.item" dense>
                    <!-- Instruction Number (editierbar mit Duplikat-Check) -->
                    <v-col cols="12" sm="4">
                        <v-text-field
                            v-model="editDialog.item.instruction_number"
                            :label="t('FakturaView.faktura.instructions.number')"
                            :error-messages="editDialog.numberError || []"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            @update:model-value="onEditNumberChange"
                        />
                    </v-col>
                    <!-- Done Status -->
                    <v-col cols="12" sm="8" class="d-flex align-center">
                        <v-checkbox
                            v-model="editDialog.item.done"
                            :label="t('FakturaView.faktura.instructions.done')"
                            hide-details
                            density="compact"
                            color="primary"
                        />
                    </v-col>
                    <!-- Description -->
                    <v-col cols="12">
                        <v-text-field
                            v-model="editDialog.item.description"
                            :label="t('FakturaView.faktura.instructions.description')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            autofocus
                        />
                    </v-col>
                    <!-- Update Master -->
                    <v-col cols="12">
                        <v-checkbox
                            v-model="editDialog.updateMaster"
                            :label="t('FakturaView.faktura.instructions.updateMaster')"
                            :hint="t('FakturaView.faktura.instructions.updateMasterHint')"
                            persistent-hint
                            density="compact"
                            color="primary"
                        />
                    </v-col>
                </v-row>
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-btn
                    color="error"
                    variant="text"
                    prepend-icon="mdi-delete"
                    @click="confirmDeleteFromEdit"
                >
                    {{ t('FakturaView.faktura.delete') }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" @click="closeEditDialog">
                    {{ t('FakturaView.faktura.instructions.cancel') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="elevated"
                    prepend-icon="mdi-content-save"
                    :loading="editDialog.saving"
                    :disabled="!!editDialog.numberError"
                    @click="saveEditDialog"
                >
                    {{ t('FakturaView.faktura.instructions.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Master-Verwaltungs-Dialog -->
    <v-dialog v-model="showMasterDialog" max-width="700" @keydown.esc="showMasterDialog = false">
        <v-card style="height: 70vh; overflow: hidden">
            <!-- Header: 52px -->
            <v-card-title class="d-flex align-center py-3 px-4 bg-primary text-white" style="height: 52px">
                <v-icon class="mr-2">mdi-cog-outline</v-icon>
                {{ t('FakturaView.faktura.instructions.masterTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" color="white" @click="showMasterDialog = false" />
            </v-card-title>
            <!-- Filter: 56px -->
            <div class="px-4 pt-3 pb-2" style="height: 56px">
                <v-text-field
                    v-model="masterSearch"
                    :label="t('FakturaView.faktura.instructions.filter')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                    autocomplete="off"
                />
            </div>
            <!-- Scrollbereich: feste Höhe = 70vh - Header - Filter - Footer -->
            <div class="master-scroll-area px-4" style="height: calc(70vh - 52px - 56px - 52px); overflow-y: auto">
                <div v-if="masterLoading" class="text-center py-4">
                    <v-progress-circular indeterminate size="24" />
                </div>
                <v-table v-else density="compact" class="instructions-table master-table">
                    <thead>
                        <tr>
                            <th style="width: 70px; text-align: center">{{ t('FakturaView.faktura.instructions.number') }}</th>
                            <th>{{ t('FakturaView.faktura.instructions.description') }}</th>
                            <th style="width: 60px; text-align: center">{{ t('FakturaView.faktura.instructions.usageCount') }}</th>
                            <th style="width: 80px; text-align: center">{{ t('FakturaView.faktura.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!masterInstructions.length">
                            <td colspan="4" class="text-center text-medium-emphasis py-4">
                                {{ t('FakturaView.faktura.instructions.masterEmpty') }}
                            </td>
                        </tr>
                        <tr v-for="master in masterInstructions" :key="master.id">
                            <td class="text-center">{{ master.instruction_number }}</td>
                            <td>{{ master.description }}</td>
                            <td class="text-center">{{ master.usage_count }}</td>
                            <td class="text-center" style="white-space: nowrap">
                                <v-btn icon="mdi-pencil" size="x-small" variant="text" color="primary" class="mr-1" @click="openMasterEdit(master)" />
                                <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteMaster(master)" />
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </div>
            <!-- Footer: 52px -->
            <v-card-actions class="pa-4" style="height: 52px">
                <span class="text-caption text-medium-emphasis">{{ masterInstructions.length }} {{ t('FakturaView.faktura.instructions.masterCount') }}</span>
                <v-spacer />
                <v-btn variant="text" @click="showMasterDialog = false">
                    {{ t('FakturaView.common.close') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Delete-Bestätigung (Vuetify Dialog statt confirm()) -->
    <v-dialog v-model="deleteConfirmDialog.show" max-width="400">
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4">
                <v-icon class="mr-2" color="error">mdi-alert-circle-outline</v-icon>
                {{ t('FakturaView.faktura.instructions.deleteConfirm') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" @click="deleteConfirmDialog.show = false" />
            </v-card-title>
            <v-card-text>
                {{ t('FakturaView.faktura.instructions.deleteConfirmText', { description: deleteConfirmDialog.item?.description || '' }) }}
                <v-alert
                    v-if="deleteConfirmDialog.source === 'edit' && deleteConfirmDialog.updateMaster"
                    type="warning"
                    variant="tonal"
                    density="compact"
                    class="mt-3"
                >
                    {{ t('FakturaView.faktura.instructions.deleteConfirmMasterWarning') }}
                </v-alert>
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn variant="text" @click="deleteConfirmDialog.show = false">
                    {{ t('FakturaView.faktura.instructions.cancel') }}
                </v-btn>
                <v-btn
                    color="error"
                    variant="elevated"
                    prepend-icon="mdi-delete"
                    :loading="deleteConfirmDialog.deleting"
                    @click="executeDelete"
                >
                    {{ t('FakturaView.faktura.delete') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Alle löschen Bestätigung -->
    <v-dialog v-model="deleteAllConfirmDialog.show" max-width="400">
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4">
                <v-icon class="mr-2" color="error">mdi-alert-circle-outline</v-icon>
                {{ t('FakturaView.faktura.instructions.deleteAllConfirm') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" @click="deleteAllConfirmDialog.show = false" />
            </v-card-title>
            <v-card-text>
                {{ t('FakturaView.faktura.instructions.deleteAllConfirmText', { count: instructions.length }) }}
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn variant="text" @click="deleteAllConfirmDialog.show = false">
                    {{ t('FakturaView.faktura.instructions.cancel') }}
                </v-btn>
                <v-btn
                    color="error"
                    variant="elevated"
                    prepend-icon="mdi-delete-sweep"
                    :loading="deleteAllConfirmDialog.deleting"
                    @click="executeDeleteAll"
                >
                    {{ t('FakturaView.faktura.instructions.deleteAll') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Master-Edit-Dialog (inline im Master-Dialog) -->
    <v-dialog v-model="masterEditDialog.show" max-width="500" @keydown.esc="masterEditDialog.show = false">
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4 bg-primary text-white">
                <v-icon class="mr-2">mdi-pencil</v-icon>
                {{ t('FakturaView.faktura.instructions.editTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" color="white" @click="masterEditDialog.show = false" />
            </v-card-title>
            <v-card-text class="pt-4">
                <v-row v-if="masterEditDialog.item" dense>
                    <v-col cols="12" sm="4">
                        <v-text-field
                            v-model="masterEditDialog.item.instruction_number"
                            :label="t('FakturaView.faktura.instructions.number')"
                            :error-messages="masterEditDialog.numberError || []"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            @update:model-value="onMasterEditNumberChange"
                        />
                    </v-col>
                    <v-col cols="12" sm="8">
                        <v-text-field
                            v-model="masterEditDialog.item.description"
                            :label="t('FakturaView.faktura.instructions.description')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            autofocus
                        />
                    </v-col>
                </v-row>
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn variant="text" @click="masterEditDialog.show = false">
                    {{ t('FakturaView.faktura.instructions.cancel') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="elevated"
                    prepend-icon="mdi-content-save"
                    :loading="masterEditDialog.saving"
                    :disabled="!!masterEditDialog.numberError"
                    @click="saveMasterEdit"
                >
                    {{ t('FakturaView.faktura.instructions.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Hinweis: Angaben fehlen -->
    <v-dialog v-model="actualRequiredDialog.show" max-width="420">
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4 bg-warning">
                <v-icon class="mr-2">mdi-clock-alert-outline</v-icon>
                {{ t('FakturaView.faktura.instructions.actualRequiredTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" @click="closeActualRequiredDialog" />
            </v-card-title>
            <v-card-text class="pt-4">
                <p v-if="actualRequiredDialog.missingActual && actualRequiredDialog.missingEmployee">
                    {{ t('FakturaView.faktura.instructions.missingBoth') }}
                </p>
                <p v-else-if="actualRequiredDialog.missingActual">
                    {{ t('FakturaView.faktura.instructions.missingActual') }}
                </p>
                <p v-else>
                    {{ t('FakturaView.faktura.instructions.missingEmployee') }}
                </p>
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn
                    color="primary"
                    variant="elevated"
                    @click="closeActualRequiredDialog"
                >
                    {{ t('FakturaView.common.ok') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- KI-Zeitvorschläge Dialog -->
    <v-dialog v-model="aiTimesDialog.show" max-width="700" persistent>
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4 bg-purple-lighten-4">
                <v-icon class="mr-2" color="purple">mdi-creation</v-icon>
                {{ t('FakturaView.faktura.instructions.aiTimesTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="x-small" variant="text" @click="aiTimesDialog.show = false" />
            </v-card-title>
            <v-card-text class="pa-4">
                <p class="text-body-2 text-grey-darken-1 mb-3">
                    {{ t('FakturaView.faktura.instructions.aiTimesSubtitle') }}
                </p>
                <v-table density="compact">
                    <thead>
                        <tr>
                            <th style="width: 36px; text-align: center">
                                <v-checkbox-btn
                                    :model-value="aiTimesAllSelected"
                                    density="compact"
                                    hide-details
                                    @update:model-value="onAiTimesSelectAll"
                                />
                            </th>
                            <th>{{ t('FakturaView.faktura.instructions.description') }}</th>
                            <th style="width: 90px; text-align: center">{{ t('FakturaView.faktura.instructions.aiTimesCurrentTime') }}</th>
                            <th style="width: 90px; text-align: center">{{ t('FakturaView.faktura.instructions.aiTimesSuggestedTime') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(s, idx) in aiTimesDialog.suggestions" :key="idx">
                            <td class="text-center">
                                <v-checkbox-btn
                                    v-model="s.selected"
                                    density="compact"
                                    hide-details
                                />
                            </td>
                            <td>
                                <div>{{ s.description }}</div>
                                <div v-if="s.reason" class="text-caption text-grey">{{ s.reason }}</div>
                            </td>
                            <td class="text-center text-grey">
                                {{ s.current_planned ? formatDuration(s.current_planned) : '–' }}
                            </td>
                            <td class="text-center">
                                <v-text-field
                                    v-model="s.suggestedDisplay"
                                    variant="underlined"
                                    density="compact"
                                    hide-details
                                    single-line
                                    class="centered-input"
                                    style="max-width: 70px; margin: 0 auto"
                                />
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn variant="text" @click="aiTimesDialog.show = false">
                    {{ t('FakturaView.faktura.instructions.aiTimesSkip') }}
                </v-btn>
                <v-btn
                    color="purple"
                    variant="elevated"
                    prepend-icon="mdi-check"
                    :loading="aiTimesDialog.applying"
                    :disabled="!aiTimesAnySelected"
                    @click="onAiTimesConfirmed"
                >
                    {{ t('FakturaView.faktura.instructions.aiTimesApply') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
// src/features/lxcars/components/instructions.section.card.vue

import { defineComponent, ref, reactive, computed, watch, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { useInstructionTimer } from '@/core/composables/useInstructionTimer.js'
import draggable from 'vuedraggable'
import axios from 'axios'
import * as toast from '@/core/utils/toasts.js'

export default defineComponent({
    name: 'InstructionsSectionCard',
    components: { draggable },

    props: {
        oeId: {
            type: Number,
            default: null
        },
        ensureOeId: {
            type: Function,
            default: null
        }
    },

    emits: ['jump-to-positions'],

    setup(props, { emit }) {
        const { t } = useI18n()
        const carsStore = lxcarsStore()
        const oserp = oserpStore()
        const { isRunning, isPaused, formatElapsed, checkWorkTime, getTimerConfig, setActive, clearActive, activeTimer, detectFromInstructions } = useInstructionTimer()
        const allEmployees = computed(() => oserp.session?.company_config?.employees || [])
        const lxcarsGroupId = computed(() => {
            const val = oserp.session?.company_config?.defaults_oserp?.lxcars_employee_group
            return val ? parseInt(val) : null
        })
        const lxcarsGroupLogins = computed(() => {
            if (!lxcarsGroupId.value) return null
            const group = (oserp.session?.auth_groups || []).find(g => g.id === lxcarsGroupId.value)
            return group ? group.logins : null
        })
        const employeeList = computed(() => {
            if (!lxcarsGroupLogins.value) return allEmployees.value
            return allEmployees.value.filter(e => lxcarsGroupLogins.value.includes(e.login))
        })

        // Duration helpers
        function formatDuration(minutes) {
            if (!minutes || minutes <= 0) return ''
            const h = Math.floor(minutes / 60)
            const m = minutes % 60
            return h + ':' + String(m).padStart(2, '0')
        }

        function parseDuration(input) {
            if (!input || typeof input !== 'string') return 0
            const val = input.trim()
            if (!val) return 0
            // "1:30" format
            if (val.includes(':')) {
                const [h, m] = val.split(':')
                return (parseInt(h) || 0) * 60 + (parseInt(m) || 0)
            }
            // "1.5" format (hours)
            if (val.includes('.') || val.includes(',')) {
                const hours = parseFloat(val.replace(',', '.'))
                return Math.round((hours || 0) * 60)
            }
            // Plain number = hours
            return (parseInt(val) || 0) * 60
        }

        const instructions = ref([])
        const loading = ref(false)
        const globalEmployeeId = ref(null)
        const suggestions = ref([])
        const suggestionsLoading = ref(false)
        const newInstructionSearch = ref('')
        const newInstructionSelected = ref(null)
        const addInputRef = ref(null)
        const descriptionRefs = {}
        const plannedTimeRefs = {}
        const actualTimeRefs = {}
        const employeeRefs = {}

        // SSE-Reload unterdrücken wenn eigener Save gerade läuft
        let suppressSSEReloadUntil = 0

        // Master-Dialog
        const showMasterDialog = ref(false)
        const allMasterInstructions = ref([])
        const masterLoading = ref(false)
        const masterSearch = ref('')

        const filteredMasterInstructions = computed(() => {
            const q = (masterSearch.value || '').trim().toLowerCase()
            if (!q) return allMasterInstructions.value
            return allMasterInstructions.value.filter(m =>
                (m.description || '').toLowerCase().includes(q) ||
                (m.instruction_number || '').startsWith(q)
            )
        })
        const masterEditDialog = reactive({
            show: false,
            item: null,
            originalNumber: '',
            numberError: '',
            saving: false
        })
        const actualRequiredDialog = reactive({
            show: false,
            missingActual: false,
            missingEmployee: false,
            index: -1
        })
        const deleteConfirmDialog = reactive({
            show: false,
            item: null,
            deleting: false,
            source: null, // 'master' or 'edit'
            index: -1,
            updateMaster: false
        })
        const deleteAllConfirmDialog = reactive({
            show: false,
            deleting: false
        })

        // Edit-Dialog
        const editDialog = reactive({
            show: false,
            item: null,
            index: -1,
            updateMaster: false,
            saving: false,
            numberError: '',
            originalNumber: ''
        })

        let searchTimeout = null

        const doneCount = computed(() => instructions.value.filter(i => i.done).length)
        const allDone = computed(() => instructions.value.length > 0 && doneCount.value === instructions.value.length)
        const totalPlanned = computed(() => instructions.value.reduce((sum, i) => sum + (i.planned_minutes || 0), 0))
        const totalActual = computed(() => instructions.value.reduce((sum, i) => sum + (i.actual_minutes || 0), 0))
        const timeDiffColor = computed(() => {
            const diff = totalActual.value - totalPlanned.value
            if (diff > 0) return 'error'    // Länger als geplant → rot
            if (diff < 0) return 'success'  // Schneller als geplant → grün
            return 'grey'                   // Genau im Plan
        })
        const timeDiffSign = computed(() => {
            const diff = totalActual.value - totalPlanned.value
            if (diff > 0) return '+'
            if (diff < 0) return '-'
            return '±'
        })
        const timeDiffLabel = computed(() => {
            const diff = totalActual.value - totalPlanned.value
            const time = formatDuration(Math.abs(diff))
            if (diff > 0) return t('FakturaView.faktura.instructions.timeOverBudget', { time })
            if (diff < 0) return t('FakturaView.faktura.instructions.timeUnderBudget', { time })
            return t('FakturaView.faktura.instructions.timeOnBudget')
        })
        const timeBudgetStyle = computed(() => {
            if (totalPlanned.value <= 0 || totalActual.value <= 0) return {}
            const diff = totalActual.value - totalPlanned.value
            if (diff > 0) return {
                backgroundColor: 'rgba(244, 67, 54, 0.15)',
                borderColor: 'rgba(244, 67, 54, 0.5)',
                transition: 'background-color 0.6s ease, border-color 0.6s ease'
            }
            if (diff < 0) return {
                backgroundColor: 'rgba(76, 175, 80, 0.12)',
                borderColor: 'rgba(76, 175, 80, 0.5)',
                transition: 'background-color 0.6s ease, border-color 0.6s ease'
            }
            return {
                backgroundColor: 'rgba(33, 150, 243, 0.08)',
                borderColor: 'rgba(33, 150, 243, 0.4)',
                transition: 'background-color 0.6s ease, border-color 0.6s ease'
            }
        })

        async function loadInstructions() {
            if (!props.oeId) return
            if (Date.now() < suppressSSEReloadUntil) return
            loading.value = true
            try {
                const rows = await carsStore.loadInstructions(props.oeId)
                instructions.value = (rows || []).map(r => ({
                    ...r,
                    done: r.done === true || r.done === 't',
                    _prevDescription: r.description,
                    planned_minutes: parseInt(r.planned_minutes) || 0,
                    actual_minutes: parseInt(r.actual_minutes) || 0,
                    employee_id: r.employee_id ? parseInt(r.employee_id) : null,
                    _plannedDisplay: formatDuration(parseInt(r.planned_minutes) || 0),
                    _actualDisplay: formatDuration(parseInt(r.actual_minutes) || 0),
                    _prevPlanned: parseInt(r.planned_minutes) || 0,
                    _prevActual: parseInt(r.actual_minutes) || 0
                }))
                // Timer-State aus geladenen Instructions erkennen
                detectFromInstructions(rows || [], props.oeId)
            } catch (e) {
                console.error('Error loading instructions:', e)
            } finally {
                loading.value = false
                if (instructions.value.length === 0) {
                    nextTick(() => {
                        addInputRef.value?.focus()
                    })
                }
            }
        }

        async function loadActiveTimerGlobal() {
            // Globalen Timer-State laden (falls Timer in anderem Auftrag läuft)
            const employee = oserp.session?.logged_in_employee
            if (!employee?.id) return
            try {
                const timerData = await carsStore.getActiveInstructionTimer(employee.id)
                if (timerData && timerData.timer_started_at) {
                    setActive(
                        parseInt(timerData.id),
                        parseInt(timerData.oe_id),
                        timerData.timer_started_at,
                        timerData.description,
                        timerData.ordnumber
                    )
                }
            } catch (e) {
                // Silently ignore
            }
        }

        onMounted(async () => {
            await loadInstructions()
            // Wenn kein Timer in diesem Auftrag erkannt → global prüfen
            if (!activeTimer.instructionId) {
                await loadActiveTimerGlobal()
            }
        })
        watch(() => props.oeId, (newId) => { if (newId) loadInstructions() })

        // Autocomplete-Suche (150ms debounce, ab 2 Zeichen)
        function onSearchInput(val) {
            if (searchTimeout) clearTimeout(searchTimeout)
            if (!val || val.length < 2) {
                suggestions.value = []
                suggestionsLoading.value = false
                return
            }
            suggestionsLoading.value = true
            searchTimeout = setTimeout(async () => {
                try {
                    suggestions.value = await carsStore.searchInstructions(val)
                } catch (e) {
                    suggestions.value = []
                } finally {
                    suggestionsLoading.value = false
                }
            }, 150)
        }

        // Neue Anweisung hinzufügen
        async function addNewInstruction(description) {
            const trimmed = description.trim()
            if (!trimmed) return

            const oeId = props.oeId || (props.ensureOeId ? await props.ensureOeId() : null)
            if (!oeId) return

            try {
                const result = await carsStore.addInstruction(oeId, trimmed)
                const plannedMin = parseInt(result.planned_minutes) || 0
                instructions.value.push({
                    id: result.id,
                    oe_id: oeId,
                    description: trimmed,
                    done: false,
                    sort_order: result.sort_order,
                    instruction_number: result.instruction_number,
                    planned_minutes: plannedMin,
                    actual_minutes: 0,
                    employee_id: null,
                    _prevDescription: trimmed,
                    _plannedDisplay: formatDuration(plannedMin),
                    _actualDisplay: '',
                    _prevPlanned: plannedMin,
                    _prevActual: 0
                })
                // Pending Search abbrechen, dann alles leeren
                if (searchTimeout) clearTimeout(searchTimeout)
                suggestions.value = []
                newInstructionSelected.value = null
                newInstructionSearch.value = ''
                // Focus auf geplante Zeit der neuen Anweisung
                nextTick(() => {
                    const newIndex = instructions.value.length - 1
                    const plannedRef = plannedTimeRefs[newIndex]
                    if (plannedRef) {
                        const input = plannedRef.$el?.querySelector('input')
                        if (input) {
                            input.focus()
                            return
                        }
                    }
                    addInputRef.value?.focus()
                })
            } catch (e) {
                console.error('Error adding instruction:', e)
            }
        }

        function onSuggestionSelect(item) {
            if (item && item.description) {
                addNewInstruction(item.description)
            }
        }

        async function onEnterAdd() {
            const text = (newInstructionSearch.value || '').trim()
            if (!text) return

            // Wenn Nummer eingegeben → Anweisung per instruction_number finden
            if (/^\d+$/.test(text)) {
                let match = suggestions.value.find(s => s.instruction_number === text)
                if (!match) {
                    try {
                        const results = await carsStore.searchInstructions(text)
                        match = results.find(s => s.instruction_number === text)
                    } catch (e) { /* ignore */ }
                }
                if (match) {
                    addNewInstruction(match.description)
                    return
                }
            }

            // Wenn Vorschläge geladen sind, besten Match verwenden statt Rohtext
            if (suggestions.value.length > 0) {
                const lowerText = text.toLowerCase()
                // Exakter Match hat Vorrang
                const exactMatch = suggestions.value.find(s => s.description.toLowerCase() === lowerText)
                if (exactMatch) {
                    addNewInstruction(exactMatch.description)
                    return
                }
                // Ersten Vorschlag nehmen (sortiert nach usage_count, also relevantester)
                addNewInstruction(suggestions.value[0].description)
                return
            }

            addNewInstruction(text)
        }

        async function onToggleDone(instruction, index) {
            const newValue = !instruction.done
            const missingActual = !instruction.actual_minutes
            const missingEmployee = !instruction.employee_id
            // Erledigt nur möglich wenn benötigte Zeit und Mitarbeiter eingetragen
            if (newValue && (missingActual || missingEmployee)) {
                actualRequiredDialog.show = true
                actualRequiredDialog.missingActual = missingActual
                actualRequiredDialog.missingEmployee = missingEmployee
                actualRequiredDialog.index = index
                return
            }
            instruction.done = newValue
            try {
                await carsStore.updateInstruction(instruction.id, { done: newValue })
            } catch (e) {
                instruction.done = !newValue
                console.error('Error toggling instruction:', e)
            }
        }

        function closeActualRequiredDialog() {
            const idx = actualRequiredDialog.index
            const focusActual = actualRequiredDialog.missingActual
            actualRequiredDialog.show = false
            nextTick(() => {
                if (focusActual) {
                    actualTimeRefs[idx]?.focus()
                } else {
                    employeeRefs[idx]?.focus()
                }
            })
        }

        async function onDescriptionBlur(instruction) {
            const trimmed = (instruction.description || '').trim()
            if (!trimmed || trimmed === instruction._prevDescription) return
            try {
                await carsStore.updateInstruction(instruction.id, { description: trimmed })
                instruction.description = trimmed
                instruction._prevDescription = trimmed
            } catch (e) {
                console.error('Error updating instruction description:', e)
            }
        }

        async function onPlannedBlur(instruction) {
            const minutes = parseDuration(instruction._plannedDisplay)
            instruction._plannedDisplay = formatDuration(minutes)
            if (minutes === instruction._prevPlanned) return
            instruction.planned_minutes = minutes
            instruction._prevPlanned = minutes
            suppressSSEReloadUntil = Date.now() + 2000
            try {
                await carsStore.updateInstruction(instruction.id, { planned_minutes: minutes })
            } catch (e) {
                console.error('Error updating planned time:', e)
            }
        }

        async function onActualBlur(instruction) {
            const minutes = parseDuration(instruction._actualDisplay)
            instruction._actualDisplay = formatDuration(minutes)
            if (minutes === instruction._prevActual) return
            instruction.actual_minutes = minutes
            instruction._prevActual = minutes
            suppressSSEReloadUntil = Date.now() + 2000
            try {
                await carsStore.updateInstruction(instruction.id, { actual_minutes: minutes })
            } catch (e) {
                console.error('Error updating actual time:', e)
            }
        }

        function focusPlannedTime(index) {
            const plannedRef = plannedTimeRefs[index]
            if (plannedRef) {
                const input = plannedRef.$el?.querySelector('input')
                if (input) {
                    input.focus()
                    input.select()
                    return true
                }
            }
            return false
        }

        function focusActualTime(index) {
            const actualRef = actualTimeRefs[index]
            if (actualRef) {
                const input = actualRef.$el?.querySelector('input')
                if (input) {
                    input.focus()
                    input.select()
                    return true
                }
            }
            return false
        }

        function onPlannedNext(instruction, index) {
            // Focus direkt setzen — blur-Handler feuert automatisch und speichert
            focusActualTime(index)
        }

        function onActualNext(instruction, index) {
            // Nächste Zeile: Geplante Zeit
            for (let i = index + 1; i < instructions.value.length; i++) {
                if (focusPlannedTime(i)) return
            }
            // Keine weitere Anweisung → zur neuen Position springen
            emit('jump-to-positions')
        }

        async function onFieldUpdate(instruction, field, value) {
            try {
                await carsStore.updateInstruction(instruction.id, { [field]: value })
            } catch (e) {
                console.error('Error updating field:', e)
            }
        }

        async function onGlobalEmployeeChange(employeeId) {
            if (!props.oeId || !instructions.value.length) return
            try {
                await carsStore.setAllInstructionsEmployee(props.oeId, employeeId)
                instructions.value.forEach(i => {
                    i.employee_id = employeeId
                })
            } catch (e) {
                console.error('Error setting global employee:', e)
            }
        }

        async function onDelete(instruction, index) {
            try {
                await carsStore.deleteInstruction(instruction.id)
                instructions.value.splice(index, 1)
            } catch (e) {
                console.error('Error deleting instruction:', e)
            }
        }

        async function onDragChange(evt) {
            if (evt.moved) {
                const order = instructions.value.map(i => i.id)
                try {
                    await carsStore.reorderInstructions(props.oeId, order)
                } catch (e) {
                    console.error('Error reordering instructions:', e)
                }
            }
        }

        // ===== Edit-Dialog =====

        let numberCheckTimeout = null

        function openEditDialog(instruction, index) {
            editDialog.item = { ...instruction }
            editDialog.index = index
            editDialog.updateMaster = true
            editDialog.saving = false
            editDialog.numberError = ''
            editDialog.originalNumber = instruction.instruction_number || ''
            editDialog.show = true
        }

        function onEditNumberChange(val) {
            if (numberCheckTimeout) clearTimeout(numberCheckTimeout)
            editDialog.numberError = ''

            const num = (val || '').trim()
            if (!num || num === editDialog.originalNumber) return

            numberCheckTimeout = setTimeout(async () => {
                try {
                    const result = await carsStore.checkInstructionNumber(num, editDialog.originalNumber)
                    if (result.taken) {
                        editDialog.numberError = t('FakturaView.faktura.instructions.numberTaken', { description: result.description })
                    }
                } catch (e) { /* ignore */ }
            }, 400)
        }

        function closeEditDialog() {
            editDialog.show = false
            editDialog.item = null
            editDialog.index = -1
        }

        async function saveEditDialog() {
            if (!editDialog.item || editDialog.numberError) return
            editDialog.saving = true

            try {
                const original = instructions.value[editDialog.index]
                const newDescription = editDialog.item.description.trim()
                const newDone = editDialog.item.done
                const newNumber = (editDialog.item.instruction_number || '').trim()
                const numberChanged = newNumber !== editDialog.originalNumber

                // Per-Order Anweisung aktualisieren
                const updates = {}
                if (newDescription !== original.description) updates.description = newDescription
                if (newDone !== original.done) updates.done = newDone
                if (numberChanged) updates.instruction_number = newNumber

                if (Object.keys(updates).length > 0) {
                    await carsStore.updateInstruction(original.id, updates)
                    original.description = newDescription
                    original._prevDescription = newDescription
                    original.done = newDone
                    if (numberChanged) original.instruction_number = newNumber
                }

                // Optional: Master-Stammdaten aktualisieren
                if (editDialog.updateMaster && (updates.description || numberChanged)) {
                    try {
                        const results = await carsStore.searchInstructions(editDialog.originalNumber)
                        const master = results.find(m => m.instruction_number === editDialog.originalNumber)
                        if (master) {
                            await carsStore.updateMasterInstruction(
                                master.id,
                                newDescription,
                                numberChanged ? newNumber : undefined
                            )
                        }
                    } catch (e) {
                        console.error('Error updating master instruction:', e)
                    }
                }

                closeEditDialog()
                nextTick(() => {
                    addInputRef.value?.focus()
                })
            } catch (e) {
                console.error('Error saving instruction:', e)
            } finally {
                editDialog.saving = false
            }
        }

        function confirmDeleteFromEdit() {
            if (!editDialog.item || editDialog.index < 0) return
            deleteConfirmDialog.item = { ...editDialog.item }
            deleteConfirmDialog.source = 'edit'
            deleteConfirmDialog.index = editDialog.index
            deleteConfirmDialog.updateMaster = editDialog.updateMaster
            deleteConfirmDialog.deleting = false
            deleteConfirmDialog.show = true
        }

        // ===== Master-Verwaltungs-Dialog =====

        let masterNumberCheckTimeout = null

        async function loadMasterData() {
            masterLoading.value = true
            try {
                allMasterInstructions.value = await carsStore.loadMasterInstructions()
            } catch (e) {
                allMasterInstructions.value = []
            } finally {
                masterLoading.value = false
            }
        }

        function openMasterEdit(master) {
            masterEditDialog.item = { ...master }
            masterEditDialog.originalNumber = master.instruction_number || ''
            masterEditDialog.numberError = ''
            masterEditDialog.saving = false
            masterEditDialog.show = true
        }

        function onMasterEditNumberChange(val) {
            if (masterNumberCheckTimeout) clearTimeout(masterNumberCheckTimeout)
            masterEditDialog.numberError = ''

            const num = (val || '').trim()
            if (!num || num === masterEditDialog.originalNumber) return

            masterNumberCheckTimeout = setTimeout(async () => {
                try {
                    const result = await carsStore.checkInstructionNumber(num, masterEditDialog.originalNumber)
                    if (result.taken) {
                        masterEditDialog.numberError = t('FakturaView.faktura.instructions.numberTaken', { description: result.description })
                    }
                } catch (e) { /* ignore */ }
            }, 400)
        }

        async function saveMasterEdit() {
            if (!masterEditDialog.item || masterEditDialog.numberError) return
            masterEditDialog.saving = true
            try {
                const newDescription = masterEditDialog.item.description.trim()
                const newNumber = (masterEditDialog.item.instruction_number || '').trim()
                const numberChanged = newNumber !== masterEditDialog.originalNumber
                await carsStore.updateMasterInstruction(
                    masterEditDialog.item.id,
                    newDescription,
                    numberChanged ? newNumber : undefined
                )
                // Lokales Update statt Server-Reload
                const idx = allMasterInstructions.value.findIndex(m => m.id === masterEditDialog.item.id)
                if (idx >= 0) {
                    allMasterInstructions.value[idx].description = newDescription
                    if (numberChanged) allMasterInstructions.value[idx].instruction_number = newNumber
                }
                masterEditDialog.show = false
                showMasterDialog.value = false
                nextTick(() => {
                    addInputRef.value?.focus()
                })
            } catch (e) {
                console.error('Error saving master instruction:', e)
            } finally {
                masterEditDialog.saving = false
            }
        }

        function confirmDeleteMaster(master) {
            deleteConfirmDialog.item = master
            deleteConfirmDialog.source = 'master'
            deleteConfirmDialog.deleting = false
            deleteConfirmDialog.show = true
        }

        async function executeDelete() {
            if (!deleteConfirmDialog.item) return
            deleteConfirmDialog.deleting = true
            try {
                if (deleteConfirmDialog.source === 'master') {
                    await carsStore.deleteMasterInstruction(deleteConfirmDialog.item.id)
                    allMasterInstructions.value = allMasterInstructions.value.filter(m => m.id !== deleteConfirmDialog.item.id)
                    showMasterDialog.value = false
                    nextTick(() => {
                        addInputRef.value?.focus()
                    })
                } else if (deleteConfirmDialog.source === 'edit') {
                    await carsStore.deleteInstruction(deleteConfirmDialog.item.id)
                    instructions.value.splice(deleteConfirmDialog.index, 1)

                    if (deleteConfirmDialog.updateMaster) {
                        try {
                            const results = await carsStore.searchInstructions(deleteConfirmDialog.item.instruction_number)
                            const master = results.find(m => m.instruction_number === deleteConfirmDialog.item.instruction_number)
                            if (master) {
                                await carsStore.deleteMasterInstruction(master.id)
                            }
                        } catch (e) {
                            console.error('Error deleting master instruction:', e)
                        }
                    }

                    closeEditDialog()
                }
                deleteConfirmDialog.show = false
                nextTick(() => {
                    addInputRef.value?.focus()
                })
            } catch (e) {
                console.error('Error deleting instruction:', e)
            } finally {
                deleteConfirmDialog.deleting = false
            }
        }

        // ===== Alle Anweisungen löschen =====

        function confirmDeleteAll() {
            if (!instructions.value.length) return
            deleteAllConfirmDialog.deleting = false
            deleteAllConfirmDialog.show = true
        }

        async function executeDeleteAll() {
            deleteAllConfirmDialog.deleting = true
            try {
                await carsStore.deleteAllInstructions(props.oeId)
                instructions.value = []
                deleteAllConfirmDialog.show = false
                nextTick(() => {
                    addInputRef.value?.focus()
                })
            } catch (e) {
                console.error('Error deleting all instructions:', e)
            } finally {
                deleteAllConfirmDialog.deleting = false
            }
        }

        // ===== KI-Zeitvorschläge =====

        const aiTimesLoading = ref(false)
        const aiTimesDialog = reactive({
            show: false,
            suggestions: [],
            applying: false
        })

        const aiTimesAllSelected = computed(() =>
            aiTimesDialog.suggestions.length > 0 && aiTimesDialog.suggestions.every(s => s.selected)
        )
        const aiTimesAnySelected = computed(() =>
            aiTimesDialog.suggestions.some(s => s.selected)
        )

        function onAiTimesSelectAll(val) {
            aiTimesDialog.suggestions.forEach(s => { s.selected = val })
        }

        async function onAiSuggestTimes() {
            if (!props.oeId) return
            aiTimesLoading.value = true
            try {
                const response = await axios.post('/api/lxcars/', {
                    action: 'suggestPlannedTimes',
                    oe_id: props.oeId
                }, { timeout: 60000 })

                if (!response.data.success) {
                    toast.error(t('FakturaView.faktura.instructions.aiTimesError') + '\n' + (response.data.payload || response.data.text || ''))
                    return
                }

                const suggestions = response.data.payload.suggestions || []
                if (!suggestions.length) {
                    toast.info(t('FakturaView.faktura.instructions.aiTimesNoSuggestions'))
                    return
                }

                // Vorschläge mit den aktuellen Instructions matchen
                aiTimesDialog.suggestions = suggestions.map(s => {
                    const instr = instructions.value.find(i => i.id === s.id)
                    return {
                        id: s.id,
                        description: instr ? instr.description : '?',
                        current_planned: instr ? instr.planned_minutes : 0,
                        suggested_minutes: s.suggested_minutes,
                        suggestedDisplay: formatDuration(s.suggested_minutes),
                        reason: s.reason || '',
                        selected: true
                    }
                })
                aiTimesDialog.applying = false
                aiTimesDialog.show = true
            } catch (e) {
                toast.error(t('FakturaView.faktura.instructions.aiTimesError'))
                console.error('AI time suggestions error:', e)
            } finally {
                aiTimesLoading.value = false
            }
        }

        async function onAiTimesConfirmed() {
            aiTimesDialog.applying = true
            try {
                const selected = aiTimesDialog.suggestions.filter(s => s.selected)
                for (const s of selected) {
                    const minutes = parseDuration(s.suggestedDisplay)
                    if (minutes <= 0) continue

                    await carsStore.updateInstruction(s.id, { planned_minutes: minutes })

                    // Lokales Update
                    const instr = instructions.value.find(i => i.id === s.id)
                    if (instr) {
                        instr.planned_minutes = minutes
                        instr._plannedDisplay = formatDuration(minutes)
                        instr._prevPlanned = minutes
                    }
                }
                toast.success(t('FakturaView.faktura.instructions.aiTimesApplied', { count: selected.length }))
                aiTimesDialog.show = false
            } catch (e) {
                toast.error(t('FakturaView.faktura.instructions.aiTimesError'))
                console.error('AI time apply error:', e)
            } finally {
                aiTimesDialog.applying = false
            }
        }

        // ===== Timer (DB-backed) =====

        function timerRunning(instructionId) {
            return isRunning(instructionId)
        }

        function timerPaused(instructionId) {
            return isPaused(instructionId)
        }

        function timerElapsed(instructionId) {
            return formatElapsed(instructionId)
        }

        async function onTimerStart(instruction, index) {
            if (instruction.done) return

            // Außerhalb der Arbeitszeit → Meldung
            const wt = checkWorkTime()
            if (wt.outsideWorkHours) {
                toast.warning(t('FakturaView.faktura.instructions.timerOutsideWorkHours'))
                return
            }

            // Wenn dieser Timer schon läuft → nichts tun
            if (activeTimer.instructionId === instruction.id) return

            const employee = oserp.session?.logged_in_employee
            if (!employee?.id) return

            suppressSSEReloadUntil = Date.now() + 2000
            try {
                // Backend: startet Timer, stoppt automatisch den vorherigen
                const result = await carsStore.startInstructionTimer(
                    instruction.id,
                    employee.id,
                    getTimerConfig()
                )

                // Lokalen Display-State setzen
                setActive(instruction.id, props.oeId, new Date().toISOString(), instruction.description)

                // Vorherigen Timer lokal aktualisieren (actual_minutes wurde im Backend addiert)
                if (result.stopped_id && result.stopped_minutes > 0) {
                    const stoppedInstr = instructions.value.find(i => i.id === result.stopped_id)
                    if (stoppedInstr) {
                        const newMinutes = (stoppedInstr.actual_minutes || 0) + result.stopped_minutes
                        stoppedInstr.actual_minutes = newMinutes
                        stoppedInstr._actualDisplay = formatDuration(newMinutes)
                        stoppedInstr._prevActual = newMinutes
                        stoppedInstr.timer_started_at = null
                    }
                }

                // Mitarbeiter lokal eintragen (Backend setzt es auch)
                if (!instruction.employee_id) {
                    instruction.employee_id = employee.id
                }
                instruction.timer_started_at = new Date().toISOString()
                instruction.timer_employee_id = employee.id
            } catch (e) {
                console.error('Error starting timer:', e)
                toast.error(t('FakturaView.faktura.instructions.timerError'))
            }
        }

        async function onTimerStop(instruction, index) {
            suppressSSEReloadUntil = Date.now() + 2000
            try {
                const result = await carsStore.stopInstructionTimer(
                    instruction.id,
                    getTimerConfig()
                )

                // Lokalen State aktualisieren
                clearActive()
                instruction.timer_started_at = null
                instruction.timer_employee_id = null
                instruction.actual_minutes = result.actual_minutes
                instruction._actualDisplay = formatDuration(result.actual_minutes)
                instruction._prevActual = result.actual_minutes
            } catch (e) {
                console.error('Error stopping timer:', e)
                toast.error(t('FakturaView.faktura.instructions.timerError'))
            }
        }

        // Master-Dialog öffnen → Daten laden
        watch(showMasterDialog, (val) => {
            if (val) {
                masterSearch.value = ''
                loadMasterData()
            }
        })

        return {
            t,
            instructions,
            loading,
            suggestions,
            suggestionsLoading,
            newInstructionSearch,
            newInstructionSelected,
            addInputRef,
            doneCount,
            allDone,
            totalPlanned,
            totalActual,
            timeDiffColor,
            timeDiffSign,
            timeDiffLabel,
            timeBudgetStyle,
            employeeList,
            formatDuration,
            onSearchInput,
            onSuggestionSelect,
            onEnterAdd,
            onToggleDone,
            closeActualRequiredDialog,
            onDescriptionBlur,
            onPlannedBlur,
            onPlannedNext,
            onActualBlur,
            onActualNext,
            onFieldUpdate,
            globalEmployeeId,
            onGlobalEmployeeChange,
            descriptionRefs,
            plannedTimeRefs,
            actualTimeRefs,
            employeeRefs,
            onDelete,
            onDragChange,
            editDialog,
            openEditDialog,
            onEditNumberChange,
            closeEditDialog,
            saveEditDialog,
            confirmDeleteFromEdit,
            showMasterDialog,
            masterInstructions: filteredMasterInstructions,
            masterLoading,
            masterSearch,
            masterEditDialog,
            openMasterEdit,
            onMasterEditNumberChange,
            saveMasterEdit,
            deleteConfirmDialog,
            confirmDeleteMaster,
            executeDelete,
            deleteAllConfirmDialog,
            confirmDeleteAll,
            executeDeleteAll,
            actualRequiredDialog,
            aiTimesLoading,
            aiTimesDialog,
            aiTimesAllSelected,
            aiTimesAnySelected,
            onAiTimesSelectAll,
            onAiSuggestTimes,
            onAiTimesConfirmed,
            timerRunning,
            timerPaused,
            timerElapsed,
            onTimerStart,
            onTimerStop,
            activeTimer,
            loadInstructions,
            focusInput() {
                nextTick(() => {
                    if (addInputRef.value) {
                        addInputRef.value.focus()
                    }
                })
            }
        }
    }
})
</script>

<style scoped>
/* ============================================
   INSTRUCTIONS TABLE (matches items table)
   ============================================ */

.faktura-card {
    border-radius: 8px;
    transition: background-color 0.6s ease, border-color 0.6s ease;
}


.faktura-card__header {
    padding: 14px 16px !important;
    background-color: #eeeeee;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
}

.faktura-card__table-body {
    padding: 0 !important;
}

/* Tabellen Styling */
.instructions-table {
    width: 100%;
}

.instructions-table :deep(thead th) {
    padding: 12px 8px !important;
    vertical-align: middle;
    white-space: nowrap;
    font-weight: 700 !important;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: rgba(0, 0, 0, 0.7) !important;
    border-bottom: 2px solid rgba(0, 0, 0, 0.12);
}

.instructions-table :deep(tbody td) {
    padding: 8px 6px !important;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

/* Zebra-Streifen */
.instructions-table :deep(tbody tr:nth-child(even):not(.new-item-row)) {
    background-color: #fafafa;
}

.instructions-table :deep(tbody tr:hover:not(.new-item-row)) {
    background-color: #f0f7ff;
}

/* Done-Zeile */
.row-done {
    opacity: 0.6;
}

/* Vuetify Input-Wrapper */
.instructions-table :deep(.v-input) {
    flex: unset;
    grid-template-rows: auto !important;
}

.instructions-table :deep(.v-input__control) {
    display: flex;
}

.instructions-table :deep(.v-input__details) {
    display: none !important;
}

.instructions-table :deep(.v-field--variant-outlined) {
    --v-field-padding-start: 10px;
    --v-field-padding-end: 10px;
}

/* Autocomplete */
.instructions-table :deep(.v-autocomplete) {
    flex: unset !important;
}

.instructions-table :deep(.v-autocomplete .v-input__control) {
    min-height: unset;
}

/* Drag Handle */
.drag-handle {
    cursor: move;
    color: #9e9e9e;
    transition: color 0.2s;
}

.drag-handle:hover {
    color: #616161;
}

/* Disabled fields */
.field-disabled {
    color: #9e9e9e;
}

/* Button hover */
.instructions-table :deep(.v-btn--icon) {
    transition: transform 0.15s, color 0.15s;
}

.instructions-table :deep(.v-btn--icon:hover:not(:disabled)) {
    transform: scale(1.1);
}

/* Checkbox kompakt */
.instruction-done-cell {
    cursor: pointer;
}

.instruction-checkbox {
    flex: none !important;
    display: flex;
    justify-content: center;
    pointer-events: none;
}

.instruction-checkbox :deep(.v-selection-control) {
    min-height: unset !important;
}

/* New item row */
.new-item-row {
    background-color: #fff !important;
}

.new-item-row:hover {
    background-color: #fff !important;
}

.new-item-input :deep(.v-field) {
    background-color: #fff;
}

/* Hide dropdown arrow in autocomplete */
.new-item-input :deep(.v-input__append) {
    display: none !important;
}

/* Time input fields */
.time-input :deep(.v-field__input) {
    text-align: center;
    min-width: 60px;
}

/* Timer */
.timer-cell {
    min-width: 52px;
}

.timer-display {
    font-size: 10px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: #d32f2f;
    line-height: 1.2;
    margin-top: 2px;
    animation: timer-pulse 2s ease-in-out infinite;
}

.timer-paused {
    color: #ff9800;
    animation: timer-blink 1s ease-in-out infinite;
}

@keyframes timer-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

@keyframes timer-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}

/* Master-Dialog: v-table__wrapper darf nicht selbst scrollen */
.master-scroll-area :deep(.v-table__wrapper) {
    overflow: visible !important;
    height: auto !important;
}

/* Master-Dialog: Sticky header in scrollable table */
.master-table :deep(thead th) {
    position: sticky;
    top: 0;
    z-index: 1;
    background-color: #fff;
    font-weight: 700;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: rgba(0, 0, 0, 0.7);
    border-bottom: 2px solid rgba(0, 0, 0, 0.12);
}
</style>
