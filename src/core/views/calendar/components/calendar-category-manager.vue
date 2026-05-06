<!-- src/core/views/calendar/components/calendar-category-manager.vue -->
<template>
    <v-dialog :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)" max-width="500">
        <v-card>
            <v-card-title class="d-flex align-center pa-4">
                <v-icon class="me-2" color="primary">mdi-tag-multiple</v-icon>
                {{ t('CalendarCategoryManager.title') }}
                <v-spacer />
                <v-btn icon variant="text" size="small" @click="$emit('update:modelValue', false)">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <v-card-text class="pa-4">
                <!-- Existing Categories -->
                <v-list v-if="categories.length > 0" density="compact">
                    <v-list-item
                        v-for="cat in categories"
                        :key="cat.id"
                        class="mb-2 rounded-lg"
                    >
                        <template #prepend>
                            <v-avatar size="24" :color="cat.color" class="mr-3">
                                <span></span>
                            </v-avatar>
                        </template>

                        <v-list-item-title>
                            <template v-if="editingId === cat.id">
                                <v-row dense>
                                    <v-col cols="7">
                                        <v-text-field
                                            v-model="editLabel"
                                            variant="outlined"
                                            density="compact"
                                            hide-details
                                            autofocus
                                            @keyup.enter="saveEdit(cat)"
                                        />
                                    </v-col>
                                    <v-col cols="5">
                                        <v-text-field
                                            v-model="editColor"
                                            variant="outlined"
                                            density="compact"
                                            hide-details
                                            type="color"
                                        />
                                    </v-col>
                                </v-row>
                            </template>
                            <template v-else>
                                {{ cat.label }}
                            </template>
                        </v-list-item-title>

                        <template #append>
                            <template v-if="editingId === cat.id">
                                <v-btn icon size="x-small" color="success" variant="tonal" @click="saveEdit(cat)" class="mr-1">
                                    <v-icon size="16">mdi-check</v-icon>
                                </v-btn>
                                <v-btn icon size="x-small" variant="tonal" @click="cancelEdit">
                                    <v-icon size="16">mdi-close</v-icon>
                                </v-btn>
                            </template>
                            <template v-else>
                                <v-btn icon size="x-small" variant="tonal" @click="startEdit(cat)" class="mr-1">
                                    <v-icon size="16">mdi-pencil</v-icon>
                                </v-btn>
                                <v-btn icon size="x-small" color="error" variant="tonal" @click="confirmDelete(cat)">
                                    <v-icon size="16">mdi-delete</v-icon>
                                </v-btn>
                            </template>
                        </template>
                    </v-list-item>
                </v-list>

                <div v-else class="text-center text-grey pa-4">
                    {{ t('CalendarCategoryManager.noCategories') }}
                </div>

                <v-divider class="my-3" />

                <!-- New Category Form -->
                <div class="text-subtitle-2 mb-2">{{ t('CalendarCategoryManager.addCategory') }}</div>
                <v-row dense>
                    <v-col cols="7">
                        <v-text-field
                            v-model="newLabel"
                            :label="t('CalendarCategoryManager.label')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            @keyup.enter="addCategory"
                        />
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            v-model="newColor"
                            :label="t('CalendarCategoryManager.color')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            type="color"
                        />
                    </v-col>
                    <v-col cols="2" class="d-flex align-center">
                        <v-btn
                            icon
                            size="small"
                            color="primary"
                            variant="flat"
                            :disabled="!newLabel.trim()"
                            @click="addCategory"
                        >
                            <v-icon>mdi-plus</v-icon>
                        </v-btn>
                    </v-col>
                </v-row>
            </v-card-text>

            <!-- Delete Confirmation -->
            <v-dialog v-model="deleteConfirmOpen" max-width="360">
                <v-card>
                    <v-card-text class="pa-4">
                        {{ t('CalendarCategoryManager.confirmDelete') }}
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="deleteConfirmOpen = false">
                            {{ t('CalendarView.actions.cancel') }}
                        </v-btn>
                        <v-btn color="error" variant="flat" @click="doDelete">
                            {{ t('CalendarCategoryManager.delete') }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps({
    modelValue: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] }
})

const emit = defineEmits(['update:modelValue', 'save', 'delete'])

const { t } = useI18n()

// New category
const newLabel = ref('')
const newColor = ref('#1976D2')

// Edit state
const editingId = ref(null)
const editLabel = ref('')
const editColor = ref('')

// Delete state
const deleteConfirmOpen = ref(false)
const deletingCategory = ref(null)

function addCategory() {
    if (!newLabel.value.trim()) return
    emit('save', { label: newLabel.value.trim(), color: newColor.value })
    newLabel.value = ''
    newColor.value = '#1976D2'
}

function startEdit(cat) {
    editingId.value = cat.id
    editLabel.value = cat.label
    editColor.value = cat.color
}

function cancelEdit() {
    editingId.value = null
}

function saveEdit(cat) {
    if (!editLabel.value.trim()) return
    emit('save', { id: cat.id, label: editLabel.value.trim(), color: editColor.value, cat_order: cat.cat_order })
    editingId.value = null
}

function confirmDelete(cat) {
    deletingCategory.value = cat
    deleteConfirmOpen.value = true
}

function doDelete() {
    if (deletingCategory.value) {
        emit('delete', deletingCategory.value.id)
    }
    deleteConfirmOpen.value = false
    deletingCategory.value = null
}
</script>
