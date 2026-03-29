<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Header -->
        <div class="d-flex align-center mb-3 flex-wrap ga-2">
            <v-btn icon="mdi-arrow-left" size="small" variant="text" :to="{ name: 'wiki-list' }" />
            <v-icon color="primary" class="mr-1">mdi-tag-multiple</v-icon>
            <h1 class="text-h6 mb-0">{{ t('WikiCategoriesView.title') }}</h1>
            <v-spacer />
            <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openDialog()">
                {{ t('WikiCategoriesView.newCategory') }}
            </v-btn>
        </div>

        <!-- Skeleton beim Laden -->
        <v-row v-if="loading">
            <v-col v-for="n in 4" :key="n" cols="12" sm="6" md="3">
                <v-skeleton-loader type="list-item-two-line" />
            </v-col>
        </v-row>

        <!-- Leerer Zustand -->
        <v-card v-else-if="categories.length === 0" variant="outlined" class="text-center pa-8">
            <v-icon size="64" color="grey-lighten-1" class="mb-4">mdi-tag-off-outline</v-icon>
            <h3 class="text-h6 text-medium-emphasis mb-2">{{ t('WikiCategoriesView.noCategories') }}</h3>
            <v-btn color="primary" prepend-icon="mdi-plus" class="mt-2" @click="openDialog()">
                {{ t('WikiCategoriesView.newCategory') }}
            </v-btn>
        </v-card>

        <!-- Kategorien als sortierbare Karten -->
        <draggable
            v-else
            v-model="categories"
            tag="div"
            class="d-flex flex-wrap ga-3"
            :item-key="item => item.id"
            handle=".drag-handle"
            @end="onDragEnd"
        >
            <template #item="{ element: cat }">
                <v-card
                    variant="outlined"
                    elevation="1"
                    style="min-width: 220px; max-width: 320px; flex: 1 1 220px;"
                >
                    <v-card-text class="d-flex align-center pa-3">
                        <v-icon class="drag-handle mr-3 cursor-grab" color="grey" size="20">mdi-drag</v-icon>
                        <div class="flex-grow-1">
                            <div class="text-subtitle-2 font-weight-medium">{{ cat.name }}</div>
                            <div class="text-caption text-medium-emphasis">
                                {{ cat.page_count }} {{ t('WikiCategoriesView.pageCount') }}
                            </div>
                        </div>
                        <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="openDialog(cat)" />
                        <v-btn icon="mdi-delete" size="x-small" variant="text" color="red" @click="confirmDelete(cat)" />
                    </v-card-text>
                </v-card>
            </template>
        </draggable>

        <!-- Erstellen/Bearbeiten Dialog -->
        <v-dialog v-model="dialog" max-width="450">
            <v-card>
                <v-card-title class="py-3 px-4 bg-grey-lighten-4 d-flex align-center">
                    {{ editingCategory ? t('WikiCategoriesView.edit') : t('WikiCategoriesView.newCategory') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="dialog = false" />
                </v-card-title>
                <v-card-text class="pt-4">
                    <v-text-field
                        v-model="dialogForm.name"
                        :label="t('WikiCategoriesView.name')"
                        variant="outlined"
                        density="compact"
                        autofocus
                        class="mb-3"
                        @keyup.enter="saveCategory"
                    />
                    <v-text-field
                        v-model.number="dialogForm.sortkey"
                        :label="t('WikiCategoriesView.sortkey')"
                        variant="outlined"
                        density="compact"
                        type="number"
                        @keyup.enter="saveCategory"
                    />
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="dialog = false">{{ t('WikiCategoriesView.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="saving" @click="saveCategory">{{ t('WikiCategoriesView.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script>
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { wikiStore } from '@/core/stores/wiki.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'
import draggable from 'vuedraggable'

export default {
    name: 'WikiCategoriesView',
    components: { NavbarView, draggable },
    setup() {
        const { t } = useI18n()
        const wiki = wikiStore()

        const categories = ref([])
        const loading = ref(false)
        const dialog = ref(false)
        const saving = ref(false)
        const editingCategory = ref(null)
        const dialogForm = reactive({ name: '', sortkey: 0 })

        async function loadCategories() {
            loading.value = true
            try {
                categories.value = await wiki.fetchCategories()
            } catch (e) {
                console.error('Wiki loadCategories error:', e)
            } finally {
                loading.value = false
            }
        }

        function openDialog(cat = null) {
            editingCategory.value = cat
            dialogForm.name = cat ? cat.name : ''
            dialogForm.sortkey = cat ? cat.sortkey : 0
            dialog.value = true
        }

        async function saveCategory() {
            if (!dialogForm.name.trim()) return

            saving.value = true
            try {
                const payload = {
                    name: dialogForm.name,
                    sortkey: dialogForm.sortkey
                }
                if (editingCategory.value) payload.id = editingCategory.value.id

                await wiki.saveCategory(payload)
                dialog.value = false
                toasts.success(t('WikiCategoriesView.saved'))
                await loadCategories()
            } catch (e) {
                console.error('Wiki saveCategory error:', e)
                toasts.error(t('WikiCategoriesView.saveError'))
            } finally {
                saving.value = false
            }
        }

        async function onDragEnd() {
            // Sortkeys als 10er-Schritte vergeben
            const updated = categories.value.map((cat, index) => ({
                id: cat.id,
                sortkey: (index + 1) * 10
            }))

            try {
                await wiki.updateCategorySortOrder(updated)
                // Lokale sortkeys aktualisieren
                categories.value.forEach((cat, index) => {
                    cat.sortkey = (index + 1) * 10
                })
                toasts.success(t('WikiCategoriesView.sortUpdated'))
            } catch (e) {
                console.error('Wiki updateCategorySortOrder error:', e)
                toasts.error(t('WikiCategoriesView.saveError'))
                await loadCategories()
            }
        }

        async function confirmDelete(cat) {
            const result = await alerts.question(
                t('WikiCategoriesView.confirmDelete', { name: cat.name })
            )
            if (result.isConfirmed) {
                try {
                    await wiki.deleteCategory(cat.id)
                    toasts.success(t('WikiCategoriesView.deleted'))
                    await loadCategories()
                } catch (e) {
                    console.error('Wiki deleteCategory error:', e)
                    toasts.error(t('WikiCategoriesView.deleteError'))
                }
            }
        }

        onMounted(loadCategories)

        return {
            t, categories, loading, dialog, saving, editingCategory, dialogForm,
            openDialog, saveCategory, onDragEnd, confirmDelete
        }
    }
}
</script>

<style scoped>
.cursor-grab {
    cursor: grab;
}
.cursor-grab:active {
    cursor: grabbing;
}
</style>
