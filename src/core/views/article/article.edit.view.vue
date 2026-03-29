<!-- src/core/views/article/article.edit.view.vue -->

<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Titel-Zeile -->
        <div class="d-flex align-center mb-3 flex-wrap ga-2">
            <v-icon color="primary" class="mr-1">mdi-package-variant</v-icon>
            <h1 class="text-h6 mb-0">{{ t('ArticleEditView.titleEdit') }}</h1>
            <v-chip v-if="article.partnumber" size="small" variant="tonal" color="primary" class="font-weight-bold">
                {{ article.partnumber }}
            </v-chip>
            <v-spacer />
            <v-chip v-if="saving" size="x-small" color="warning" variant="tonal">
                <v-progress-circular indeterminate size="12" width="2" class="mr-1" />
                {{ t('ArticleEditView.saving') }}
            </v-chip>
            <v-chip v-else-if="!loading && !error" size="x-small" color="success" variant="tonal">
                <v-icon start size="x-small">mdi-check</v-icon>
                {{ t('ArticleEditView.saved') }}
            </v-chip>
        </div>

        <!-- Alerts -->
        <v-alert v-if="loading" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('ArticleEditView.messages.loading') }}...
        </v-alert>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
            {{ error }}
        </v-alert>

        <div
            v-if="!loading && !error"
            @focusin.capture="onFocusIn"
            @focusout.capture="onFocusOut"
        >
            <v-row>
                <v-col cols="12" lg="8">

                    <!-- Stammdaten Card -->
                    <v-card variant="outlined" elevation="1">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <v-icon class="mr-2" size="small">mdi-card-text-outline</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">{{ t('ArticleEditView.sections.masterData') }}</span>
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="py-2 px-2 px-sm-3">
                            <v-row dense>
                                <!-- Artikelnummer (readonly) -->
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-text-field
                                        :model-value="article.partnumber"
                                        :label="t('ArticleEditView.fields.partnumber')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        readonly
                                        bg-color="grey-lighten-4"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Artikeltyp -->
                                <v-col cols="12" sm="8" class="py-1 d-flex align-center">
                                    <v-radio-group
                                        v-model="article.part_type"
                                        inline
                                        hide-details
                                        class="mt-0"
                                    >
                                        <v-radio value="service" color="primary">
                                            <template #label>
                                                <div class="d-flex align-center">
                                                    <v-icon class="mr-1" size="small">mdi-account-wrench</v-icon>
                                                    {{ t('ArticleEditView.fields.typeService') }}
                                                </div>
                                            </template>
                                        </v-radio>
                                        <v-radio value="part" color="primary">
                                            <template #label>
                                                <div class="d-flex align-center">
                                                    <v-icon class="mr-1" size="small">mdi-package-variant</v-icon>
                                                    {{ t('ArticleEditView.fields.typePart') }}
                                                </div>
                                            </template>
                                        </v-radio>
                                    </v-radio-group>
                                </v-col>

                                <!-- Beschreibung -->
                                <v-col cols="12" class="py-1">
                                    <v-text-field
                                        v-model="article.description"
                                        :label="t('ArticleEditView.fields.description')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Einheit -->
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-combobox
                                        v-model="article.unit"
                                        :items="unitOptions"
                                        :label="t('ArticleEditView.fields.unit')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Verkaufspreis -->
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-text-field
                                        v-model.number="article.sellprice"
                                        :label="t('ArticleEditView.fields.sellprice')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        prefix="€"
                                        @focus="$event.target.select()"
                                    />
                                </v-col>

                                <!-- Buchungsgruppe -->
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-autocomplete
                                        v-model="article.buchungsgruppen_id"
                                        :items="buchungsgruppen"
                                        item-title="description"
                                        item-value="id"
                                        :label="t('ArticleEditView.fields.buchungsgruppe')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Langbeschreibung -->
                                <v-col cols="12" class="py-1">
                                    <v-textarea
                                        v-model="article.notes"
                                        :label="t('ArticleEditView.fields.notes')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                        rows="3"
                                        auto-grow
                                    />
                                </v-col>

                                <!-- Veraltet -->
                                <v-col cols="12" class="py-1">
                                    <v-checkbox
                                        v-model="article.obsolete"
                                        :label="t('ArticleEditView.fields.obsolete')"
                                        density="compact"
                                        hide-details
                                        color="error"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                </v-col>
            </v-row>
        </div>

    </v-container>
</template>

<script>
import { defineComponent, ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import axios from 'axios'

export default defineComponent({
    name: 'ArticleEditView',
    components: { NavbarView },
    props: {
        id: { type: [String, Number], required: true }
    },
    setup(props) {
        const { t } = useI18n()
        const oserp = oserpStore()

        const article = ref({
            partnumber: '',
            description: '',
            part_type: 'service',
            unit: 'Stck',
            sellprice: 0,
            buchungsgruppen_id: null,
            notes: '',
            obsolete: false
        })

        const saving = ref(false)
        const loading = ref(false)
        const error = ref('')
        const initialLoaded = ref(false)

        let textInputFocused = false
        let hasPendingChanges = false
        let saveTimeout = null

        const unitOptions = ['Stck', 'Std', 'kg', 'm', 'm²', 'm³', 'l', 'Psch', 'Tag']

        const buchungsgruppen = computed(() => {
            return oserp.session?.company_config?.buchungsgruppen || []
        })

        // ── Focus-Tracking ──

        function isTextInput(el) {
            if (!el) return false
            const tag = el.tagName?.toLowerCase()
            if (tag === 'textarea') return true
            if (tag === 'input') {
                const type = (el.type || 'text').toLowerCase()
                return ['text', 'number', 'password', 'email', 'url', 'search', 'tel', 'date'].includes(type)
            }
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

        // ── Save-Logik ──

        function onDataChange() {
            if (!initialLoaded.value) return
            if (textInputFocused) {
                hasPendingChanges = true
                return
            }
            triggerSave()
        }

        function triggerSave() {
            if (saveTimeout) clearTimeout(saveTimeout)
            saveTimeout = setTimeout(() => {
                saveArticle()
            }, 500)
        }

        watch(article, onDataChange, { deep: true })

        async function saveArticle() {
            if (saving.value) return

            saving.value = true
            error.value = ''

            try {
                await axios.post('/api/parts/', {
                    action: 'updatePart',
                    parts_id: Number(props.id),
                    description: article.value.description,
                    part_type: article.value.part_type,
                    unit: article.value.unit,
                    sellprice: article.value.sellprice,
                    buchungsgruppen_id: article.value.buchungsgruppen_id,
                    notes: article.value.notes,
                    obsolete: article.value.obsolete
                })
            } catch (e) {
                console.error('Save article error:', e)
                error.value = t('ArticleEditView.messages.loadError')
            } finally {
                saving.value = false
            }
        }

        // ── Load ──

        async function fetchArticle(articleId) {
            loading.value = true
            error.value = ''

            try {
                const response = await axios.post('/api/parts/', {
                    action: 'getPart',
                    parts_id: Number(articleId)
                })

                if (response.data.success) {
                    const data = response.data.payload
                    article.value = {
                        partnumber: data.partnumber || '',
                        description: data.description || '',
                        part_type: data.part_type || 'service',
                        unit: data.unit || 'Stck',
                        sellprice: parseFloat(data.sellprice) || 0,
                        buchungsgruppen_id: data.buchungsgruppen_id ? parseInt(data.buchungsgruppen_id) : null,
                        notes: data.notes || '',
                        obsolete: data.obsolete === true || data.obsolete === 't'
                    }
                } else {
                    error.value = t('ArticleEditView.messages.notFound')
                }
            } catch (e) {
                console.error('Load article error:', e)
                error.value = t('ArticleEditView.messages.loadError')
            } finally {
                loading.value = false
            }
        }

        // ── sendBeacon bei Seitennavigation ──

        function flushPendingChanges() {
            if (!initialLoaded.value) return
            if (!hasPendingChanges && !saveTimeout) return

            if (saveTimeout) { clearTimeout(saveTimeout); saveTimeout = null }
            hasPendingChanges = false

            const payload = {
                action: 'updatePart',
                parts_id: Number(props.id),
                description: article.value.description,
                part_type: article.value.part_type,
                unit: article.value.unit,
                sellprice: article.value.sellprice,
                buchungsgruppen_id: article.value.buchungsgruppen_id,
                notes: article.value.notes,
                obsolete: article.value.obsolete
            }
            navigator.sendBeacon('/api/parts/', new Blob([JSON.stringify(payload)], { type: 'application/json' }))
        }

        // ── Lifecycle ──

        onMounted(async () => {
            await fetchArticle(props.id)
            await nextTick()
            initialLoaded.value = true
            window.addEventListener('beforeunload', flushPendingChanges)
        })

        onBeforeUnmount(() => {
            window.removeEventListener('beforeunload', flushPendingChanges)
            if (saveTimeout) clearTimeout(saveTimeout)
            flushPendingChanges()
        })

        watch(() => props.id, async (newId) => {
            initialLoaded.value = false
            if (newId) {
                await fetchArticle(newId)
            }
            await nextTick()
            initialLoaded.value = true
        })

        return {
            t,
            article,
            saving,
            loading,
            error,
            unitOptions,
            buchungsgruppen,
            onFocusIn,
            onFocusOut
        }
    }
})
</script>
