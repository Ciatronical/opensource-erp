<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Header mit Save-Status -->
        <div class="d-flex align-center mb-3 flex-wrap ga-2">
            <v-btn icon="mdi-arrow-left" size="small" variant="text" @click="goBack" />
            <v-icon color="primary" class="mr-1">mdi-book-edit</v-icon>
            <h1 class="text-h6 mb-0">{{ isEdit ? t('WikiEditView.titleEdit') : t('WikiEditView.titleNew') }}</h1>
            <v-spacer />

            <!-- Save-Status Chip -->
            <v-chip v-if="saveStatus === 'saving'" size="small" color="warning" variant="tonal">
                <v-progress-circular indeterminate size="14" width="2" class="mr-1" />
                {{ t('WikiEditView.statusSaving') }}
            </v-chip>
            <v-chip v-else-if="saveStatus === 'saved'" size="small" color="success" variant="tonal">
                <v-icon start size="x-small">mdi-check</v-icon>
                {{ t('WikiEditView.statusSaved') }}
            </v-chip>
            <v-chip v-else-if="saveStatus === 'unsaved'" size="small" color="info" variant="tonal">
                <v-icon start size="x-small">mdi-circle-medium</v-icon>
                {{ t('WikiEditView.statusUnsaved') }}
            </v-chip>
            <v-chip v-else-if="saveStatus === 'error'" size="small" color="error" variant="tonal">
                <v-icon start size="x-small">mdi-alert</v-icon>
                {{ t('WikiEditView.statusError') }}
            </v-chip>
        </div>

        <!-- Skeleton beim Laden -->
        <template v-if="loading">
            <v-skeleton-loader type="heading" class="mb-3" />
            <v-skeleton-loader type="article" class="mb-3" />
        </template>

        <!-- Formular mit Focus-Tracking -->
        <div v-else @focusin.capture="onFocusIn" @focusout.capture="onFocusOut">
            <v-card variant="outlined" elevation="1">
                <v-card-text class="pa-4">

                    <!-- Titel -->
                    <v-text-field
                        v-model="form.title"
                        :label="t('WikiEditView.fieldTitle')"
                        variant="outlined"
                        density="compact"
                        class="mb-3"
                        :error-messages="titleError"
                        @input="titleError = ''"
                    />

                    <!-- Kategorie + Inline-Erstellung -->
                    <v-row dense class="mb-3">
                        <v-col cols="12" sm="6">
                            <div class="d-flex align-center ga-2">
                                <v-select
                                    v-model="form.category_id"
                                    :items="categories"
                                    item-title="name"
                                    item-value="id"
                                    :label="t('WikiEditView.fieldCategory')"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    clearable
                                    class="flex-grow-1"
                                />
                                <v-btn
                                    icon="mdi-plus"
                                    size="small"
                                    variant="tonal"
                                    color="primary"
                                    :title="t('WikiEditView.newCategory')"
                                    @click="showCategoryDialog = true"
                                />
                            </div>
                        </v-col>
                    </v-row>

                    <!-- KBA-Zuordnung -->
                    <v-expansion-panels variant="accordion" class="mb-3">
                        <v-expansion-panel>
                            <v-expansion-panel-title class="text-body-2">
                                <v-icon size="small" class="mr-2">mdi-car</v-icon>
                                {{ t('WikiEditView.kbaSection') }}
                                <v-chip v-if="form.kba_hsn" size="x-small" variant="tonal" color="amber" class="ml-2">
                                    {{ form.kba_hsn }}/{{ form.kba_tsn }}
                                </v-chip>
                            </v-expansion-panel-title>
                            <v-expansion-panel-text>
                                <v-row dense>
                                    <v-col cols="6" sm="3">
                                        <v-text-field
                                            v-model="form.kba_hsn"
                                            :label="t('WikiEditView.fieldKbaHsn')"
                                            variant="outlined"
                                            density="compact"
                                            hide-details
                                            maxlength="4"
                                        />
                                    </v-col>
                                    <v-col cols="6" sm="3">
                                        <v-text-field
                                            v-model="form.kba_tsn"
                                            :label="t('WikiEditView.fieldKbaTsn')"
                                            variant="outlined"
                                            density="compact"
                                            hide-details
                                            maxlength="3"
                                        />
                                    </v-col>
                                </v-row>
                            </v-expansion-panel-text>
                        </v-expansion-panel>
                    </v-expansion-panels>

                    <!-- Editor volle Breite -->
                    <HtmlEditorComponent
                        v-model="form.content"
                        :label="t('WikiEditView.fieldContent')"
                    />
                </v-card-text>
            </v-card>

            <!-- Revisionen als aufklappbares Panel -->
            <v-expansion-panels v-if="isEdit && revisions.length > 0" variant="accordion" class="mt-3">
                <v-expansion-panel>
                    <v-expansion-panel-title class="bg-grey-lighten-4">
                        <v-icon size="small" class="mr-2">mdi-history</v-icon>
                        <span class="text-subtitle-2 font-weight-medium">{{ t('WikiEditView.revisions') }}</span>
                        <v-chip size="x-small" variant="tonal" class="ml-2">{{ revisions.length }}</v-chip>
                    </v-expansion-panel-title>
                    <v-expansion-panel-text>
                        <v-list density="compact">
                            <v-list-item v-for="rev in revisions" :key="rev.id">
                                <v-list-item-title class="text-body-2">{{ rev.title }}</v-list-item-title>
                                <v-list-item-subtitle class="text-caption">
                                    {{ rev.edited_by_name }} &mdash; {{ formatDate(rev.itime) }}
                                </v-list-item-subtitle>
                                <template #append>
                                    <v-btn size="x-small" variant="text" color="primary" @click="confirmRestore(rev)">
                                        {{ t('WikiEditView.restore') }}
                                    </v-btn>
                                </template>
                            </v-list-item>
                        </v-list>
                    </v-expansion-panel-text>
                </v-expansion-panel>
            </v-expansion-panels>

            <!-- Zurueck-Button -->
            <div class="d-flex mt-4">
                <v-btn variant="text" prepend-icon="mdi-arrow-left" @click="goBack">
                    {{ t('WikiEditView.back') }}
                </v-btn>
            </div>
        </div>

        <!-- Inline Kategorie-Dialog -->
        <v-dialog v-model="showCategoryDialog" max-width="400">
            <v-card>
                <v-card-title class="py-3 px-4 bg-grey-lighten-4 d-flex align-center">
                    <v-icon class="mr-2" size="small">mdi-tag-plus</v-icon>
                    {{ t('WikiEditView.newCategory') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="showCategoryDialog = false" />
                </v-card-title>
                <v-divider />
                <v-card-text class="pt-4">
                    <v-text-field
                        v-model="newCategoryName"
                        :label="t('WikiCategoriesView.name')"
                        variant="outlined"
                        density="compact"
                        autofocus
                        hide-details
                        @keydown.enter="createCategory"
                    />
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="showCategoryDialog = false">{{ t('WikiEditView.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="creatingCategory" @click="createCategory">
                        {{ t('WikiEditView.createCategory') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

    </v-container>
</template>

<script>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { wikiStore } from '@/core/stores/wiki.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import HtmlEditorComponent from '@/core/components/html.editor.component.vue'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'

export default {
    name: 'WikiEditView',
    components: { NavbarView, HtmlEditorComponent },
    props: {
        id: { type: [Number, String], default: null }
    },
    setup(props) {
        const { t } = useI18n()
        const router = useRouter()
        const route = useRoute()
        const wiki = wikiStore()
        const oserp = oserpStore()

        const pageId = computed(() => props.id ? Number(props.id) : null)
        const isEdit = computed(() => !!(pageId.value || savedPageId.value))

        const form = reactive({
            title: '',
            content: '',
            category_id: null,
            kba_hsn: '',
            kba_tsn: ''
        })

        const categories = ref([])
        const revisions = ref([])
        const titleError = ref('')

        // Inline Kategorie-Dialog
        const showCategoryDialog = ref(false)
        const newCategoryName = ref('')
        const creatingCategory = ref(false)

        // Autosave State
        const saving = ref(false)
        const loading = ref(false)
        const saveStatus = ref('idle')
        const initialLoaded = ref(false)
        const savedPageId = ref(null)

        // Focus-Tracking
        let textInputFocused = false
        let hasPendingChanges = false
        let saveTimeout = null

        function isTextInput(el) {
            if (!el) return false
            const tag = el.tagName?.toLowerCase()
            if (tag === 'textarea') return true
            if (tag === 'input') {
                const type = (el.type || 'text').toLowerCase()
                return ['text', 'number', 'password', 'email', 'url', 'search', 'tel', 'date'].includes(type)
            }
            if (el.getAttribute?.('contenteditable') === 'true') return true
            return false
        }

        function onFocusIn(event) {
            if (isTextInput(event.target)) {
                textInputFocused = true
            }
        }

        function onFocusOut(event) {
            if (isTextInput(event.target)) {
                textInputFocused = false
                if (hasPendingChanges) {
                    hasPendingChanges = false
                    triggerSave()
                }
            }
        }

        // Autosave-Logik
        function onDataChange() {
            if (!initialLoaded.value) return
            saveStatus.value = 'unsaved'
            if (textInputFocused) {
                hasPendingChanges = true
                return
            }
            triggerSave()
        }

        function triggerSave() {
            if (saveTimeout) clearTimeout(saveTimeout)
            saveTimeout = setTimeout(() => {
                saveWikiPage()
            }, 500)
        }

        watch(form, onDataChange, { deep: true })

        async function saveWikiPage() {
            if (saving.value) return
            if (!form.title.trim()) return

            saving.value = true
            saveStatus.value = 'saving'

            try {
                const payload = {
                    title: form.title,
                    content: form.content,
                    category_id: form.category_id || null,
                    kba_hsn: form.kba_hsn || null,
                    kba_tsn: form.kba_tsn || null,
                    employee_id: oserp.session.logged_in_employee?.id
                }

                const currentId = pageId.value || savedPageId.value
                if (currentId) payload.id = currentId

                const result = await wiki.savePage(payload)
                saveStatus.value = 'saved'

                // Erster INSERT: URL aktualisieren ohne Navigation
                if (!currentId && result.id) {
                    savedPageId.value = result.id
                    const resolved = router.resolve({ name: 'wiki-edit', params: { id: result.id } })
                    window.history.replaceState(history.state, '', resolved.href)
                }

                // Revisionen aktualisieren nach Update
                if (currentId) {
                    revisions.value = await wiki.fetchRevisions(currentId)
                }
            } catch (e) {
                console.error('Wiki autosave error:', e)
                saveStatus.value = 'error'
            } finally {
                saving.value = false
            }
        }

        // sendBeacon bei Navigation
        function flushPendingChanges() {
            const id = pageId.value || savedPageId.value
            if (!id || !initialLoaded.value) return
            if (!hasPendingChanges && !saveTimeout) return
            if (!form.title.trim()) return

            if (saveTimeout) { clearTimeout(saveTimeout); saveTimeout = null }
            hasPendingChanges = false

            const payload = {
                action: 'saveWikiPage',
                id: Number(id),
                title: form.title,
                content: form.content,
                category_id: form.category_id || null,
                kba_hsn: form.kba_hsn || null,
                kba_tsn: form.kba_tsn || null,
                employee_id: oserp.session.logged_in_employee?.id
            }
            navigator.sendBeacon('/api/wiki/', new Blob([JSON.stringify(payload)], { type: 'application/json' }))
        }

        // Ctrl+S
        function onKeyDown(event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                event.preventDefault()
                if (saveTimeout) clearTimeout(saveTimeout)
                saveWikiPage()
            }
        }

        // Hilfsfunktionen
        function formatDate(dateStr) {
            if (!dateStr) return ''
            const d = new Date(dateStr)
            return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        }

        async function loadData() {
            loading.value = true
            try {
                categories.value = await wiki.fetchCategories()

                if (pageId.value) {
                    const page = await wiki.fetchPage(pageId.value)
                    if (page) {
                        form.title = page.title
                        form.content = page.content || ''
                        form.category_id = page.category_id
                        form.kba_hsn = page.kba_hsn || ''
                        form.kba_tsn = page.kba_tsn || ''
                    }
                    revisions.value = await wiki.fetchRevisions(pageId.value)
                } else {
                    if (route.query.hsn) form.kba_hsn = route.query.hsn
                    if (route.query.tsn) form.kba_tsn = route.query.tsn
                }
            } catch (e) {
                console.error('Wiki loadData error:', e)
            } finally {
                loading.value = false
            }
        }

        // Inline Kategorie-Erstellung
        async function createCategory() {
            if (!newCategoryName.value.trim()) return
            creatingCategory.value = true
            try {
                const result = await wiki.saveCategory({
                    name: newCategoryName.value.trim(),
                    sortkey: 0
                })
                categories.value = await wiki.fetchCategories()
                if (result.id) {
                    form.category_id = result.id
                }
                showCategoryDialog.value = false
                newCategoryName.value = ''
                toasts.success(t('WikiCategoriesView.saved'))
            } catch (e) {
                console.error('Create category error:', e)
                toasts.error(t('WikiCategoriesView.saveError'))
            } finally {
                creatingCategory.value = false
            }
        }

        // Revision wiederherstellen
        async function confirmRestore(rev) {
            const result = await alerts.question(
                t('WikiEditView.confirmRestore', { date: formatDate(rev.itime) })
            )
            if (result.isConfirmed) {
                try {
                    await wiki.restoreRevision(rev.id, oserp.session.logged_in_employee?.id)
                    const page = await wiki.fetchPage(pageId.value || savedPageId.value)
                    if (page) {
                        form.title = page.title
                        form.content = page.content || ''
                        form.category_id = page.category_id
                        form.kba_hsn = page.kba_hsn || ''
                        form.kba_tsn = page.kba_tsn || ''
                    }
                    revisions.value = await wiki.fetchRevisions(pageId.value || savedPageId.value)
                    toasts.success(t('WikiEditView.restored'))
                } catch (e) {
                    console.error('Wiki restore error:', e)
                    toasts.error(t('WikiEditView.restoreError'))
                }
            }
        }

        function goBack() {
            const id = pageId.value || savedPageId.value
            if (id) {
                router.push({ name: 'wiki-read', params: { id } })
            } else {
                router.push({ name: 'wiki-list' })
            }
        }

        // Lifecycle
        onMounted(async () => {
            await loadData()
            await nextTick()
            initialLoaded.value = true
            window.addEventListener('beforeunload', flushPendingChanges)
            document.addEventListener('keydown', onKeyDown)
        })

        onBeforeUnmount(() => {
            window.removeEventListener('beforeunload', flushPendingChanges)
            document.removeEventListener('keydown', onKeyDown)
            if (saveTimeout) clearTimeout(saveTimeout)
            flushPendingChanges()
        })

        // Route-Wechsel (INSERT -> EDIT)
        watch(() => props.id, async (newId) => {
            if (newId) {
                initialLoaded.value = false
                await loadData()
                await nextTick()
                initialLoaded.value = true
            }
        })

        return {
            t, form, categories, revisions, saving, loading,
            saveStatus, titleError, isEdit, initialLoaded,
            showCategoryDialog, newCategoryName, creatingCategory,
            onFocusIn, onFocusOut, createCategory, goBack,
            formatDate, confirmRestore
        }
    }
}
</script>
