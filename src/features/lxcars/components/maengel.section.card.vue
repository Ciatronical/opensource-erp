<!-- src/features/lxcars/components/maengel.section.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-car-wrench</v-icon>
            <span class="text-subtitle-1 font-weight-medium">{{ t('FakturaView.faktura.maengel.title') }}</span>
            <v-chip v-if="maengel.length" size="x-small" variant="tonal" color="error" class="ml-2">
                {{ maengel.length }} {{ t('FakturaView.faktura.maengel.count') }}
            </v-chip>
            <v-chip v-if="maengel.length" size="small" variant="tonal" :color="huBestanden ? 'success' : 'error'" class="ml-2">
                <v-icon start size="small">{{ huBestanden ? 'mdi-check-circle' : 'mdi-close-circle' }}</v-icon>
                {{ huBestanden ? t('FakturaView.faktura.maengel.huBestanden') : t('FakturaView.faktura.maengel.huNichtBestanden') }}
            </v-chip>
            <v-spacer />
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__table-body">
            <!-- Loading -->
            <div v-if="loading" class="text-center py-4">
                <v-progress-circular indeterminate size="24" />
            </div>

            <template v-else>
                <v-table density="compact" class="maengel-table">
                    <thead>
                        <tr>
                            <th style="width: 45px; text-align: center">{{ t('FakturaView.faktura.position') }}</th>
                            <th style="width: 100px">{{ t('FakturaView.faktura.maengel.code') }}</th>
                            <th>{{ t('FakturaView.faktura.maengel.description') }}</th>
                            <th style="width: 100px">{{ t('FakturaView.faktura.maengel.klasse') }}</th>
                            <th style="width: 200px">{{ t('FakturaView.faktura.maengel.notiz') }}</th>
                            <th style="width: 36px; text-align: center">
                                <v-icon size="small" color="grey" style="cursor: pointer" :title="t('FakturaView.faktura.maengel.deleteAllTooltip')" @click="confirmDeleteAll">mdi-delete</v-icon>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(mangel, index) in maengel" :key="mangel.id">
                            <td class="text-center text-caption text-grey">{{ index + 1 }}</td>
                            <td class="text-caption font-weight-medium">{{ mangel.defect_code }}</td>
                            <td class="text-caption">{{ mangel.defect_description }}</td>
                            <td>
                                <v-select
                                    :model-value="mangel.defect_class"
                                    @update:model-value="onKlasseChange(mangel, $event)"
                                    :items="getKlassenOptions(mangel)"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                    class="maengel-select"
                                />
                            </td>
                            <td>
                                <v-text-field
                                    :model-value="mangel.note"
                                    @update:model-value="mangel.note = $event"
                                    @blur="onNotizBlur(mangel)"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                />
                            </td>
                            <td class="text-center">
                                <v-btn
                                    icon="mdi-close"
                                    size="x-small"
                                    variant="text"
                                    color="grey"
                                    :title="t('FakturaView.faktura.tooltipDelete')"
                                    @click="confirmDelete(mangel)"
                                />
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td :colspan="6" class="pa-2">
                                <v-autocomplete
                                    v-model="selectedMangel"
                                    v-model:search="searchQuery"
                                    :items="searchResults"
                                    :loading="false"
                                    :placeholder="t('FakturaView.faktura.maengel.addPlaceholder')"
                                    :hide-no-data="false"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                    item-value="id"
                                    :item-title="formatSearchItem"
                                    return-object
                                    no-filter
                                    clearable
                                    prepend-inner-icon="mdi-magnify"
                                    @update:search="onSearch"
                                    @update:model-value="onMangelSelect"
                                    @keydown.enter="onEnterAdd"
                                >
                                    <template #item="{ props, item }">
                                        <v-list-item v-bind="props" :title="null">
                                            <v-list-item-title class="text-body-2">
                                                <span class="font-weight-bold">{{ item.raw.defect_code }}</span>
                                                {{ item.raw.defect_description }}
                                            </v-list-item-title>
                                            <v-list-item-subtitle class="text-caption">
                                                {{ item.raw.pruefgruppe }}
                                                <v-chip
                                                    v-for="kl in (item.raw.possible_classes || '').split('|')"
                                                    :key="kl"
                                                    size="x-small"
                                                    variant="tonal"
                                                    :color="getKlasseColor(kl)"
                                                    class="ml-1"
                                                >{{ kl }}</v-chip>
                                            </v-list-item-subtitle>
                                        </v-list-item>
                                    </template>

                                    <template #no-data>
                                        <v-list-item v-if="searchQuery && searchQuery.length >= 2 && !searching">
                                            <v-list-item-title class="text-body-2 text-grey">
                                                {{ t('FakturaView.faktura.maengel.noResults') }}
                                            </v-list-item-title>
                                            <template #append>
                                                <v-btn
                                                    size="small"
                                                    variant="tonal"
                                                    color="primary"
                                                    prepend-icon="mdi-plus"
                                                    @click="openCreateCustomDialog"
                                                >
                                                    {{ t('FakturaView.faktura.maengel.createCustom') }}
                                                </v-btn>
                                            </template>
                                        </v-list-item>
                                    </template>

                                </v-autocomplete>
                            </td>
                        </tr>
                    </tfoot>
                </v-table>

                <!-- Leermeldung -->
                <div v-if="!maengel.length && !loading" class="text-center text-caption text-grey pa-3">
                    {{ t('FakturaView.faktura.maengel.empty') }}
                </div>
            </template>
        </v-card-text>
    </v-card>

    <!-- Delete Confirm Dialog -->
    <v-dialog v-model="deleteDialog.show" max-width="400" @keydown.esc="deleteDialog.show = false">
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4 bg-error text-white">
                <v-icon class="mr-2">mdi-delete-alert</v-icon>
                {{ t('FakturaView.faktura.maengel.deleteConfirm') }}
            </v-card-title>
            <v-card-text class="pt-4">
                {{ t('FakturaView.faktura.maengel.deleteConfirmText', { description: deleteDialog.mangel?.defect_description || '' }) }}
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn variant="text" @click="deleteDialog.show = false">
                    {{ t('FakturaView.faktura.maengel.cancel') }}
                </v-btn>
                <v-btn color="error" variant="elevated" @click="doDelete">
                    {{ t('FakturaView.faktura.maengel.delete') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Delete All Confirm Dialog -->
    <v-dialog v-model="deleteAllDialog.show" max-width="400">
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4">
                <v-icon class="mr-2" color="error">mdi-alert-circle-outline</v-icon>
                {{ t('FakturaView.faktura.maengel.deleteAllConfirm') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" @click="deleteAllDialog.show = false" />
            </v-card-title>
            <v-card-text>
                {{ t('FakturaView.faktura.maengel.deleteAllConfirmText', { count: maengel.length }) }}
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn variant="text" @click="deleteAllDialog.show = false">
                    {{ t('FakturaView.faktura.maengel.cancel') }}
                </v-btn>
                <v-btn
                    color="error"
                    variant="elevated"
                    prepend-icon="mdi-delete-sweep"
                    :loading="deleteAllDialog.deleting"
                    @click="executeDeleteAll"
                >
                    {{ t('FakturaView.faktura.maengel.deleteAll') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Create Custom Mangel Dialog -->
    <v-dialog v-model="createCustomDialog.show" max-width="500">
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4">
                <v-icon class="mr-2" color="primary">mdi-plus-circle-outline</v-icon>
                {{ t('FakturaView.faktura.maengel.createCustomTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" @click="createCustomDialog.show = false" />
            </v-card-title>
            <v-card-text>
                <div class="text-caption text-grey mb-3">
                    {{ t('FakturaView.faktura.maengel.createCustomInfo') }}
                </div>
                <v-text-field
                    v-model="createCustomDialog.beschreibung"
                    :label="t('FakturaView.faktura.maengel.createCustomDescription')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                    autofocus
                    class="mb-3"
                />
                <v-select
                    v-model="createCustomDialog.klassen"
                    :items="klassenOptions"
                    :label="t('FakturaView.faktura.maengel.createCustomKlassen')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                    multiple
                    chips
                    closable-chips
                >
                    <template #chip="{ item, props: chipProps }">
                        <v-chip v-bind="chipProps" :color="getKlasseColor(item.value)" size="small">{{ item.value }}</v-chip>
                    </template>
                    <template #item="{ item, props: itemProps }">
                        <v-list-item v-bind="itemProps">
                            <template #prepend>
                                <v-chip :color="getKlasseColor(item.value)" size="x-small" class="mr-2">{{ item.value }}</v-chip>
                            </template>
                        </v-list-item>
                    </template>
                </v-select>
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn variant="text" @click="createCustomDialog.show = false">
                    {{ t('FakturaView.faktura.maengel.cancel') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="elevated"
                    prepend-icon="mdi-plus"
                    :disabled="!createCustomValid"
                    :loading="createCustomDialog.saving"
                    @click="saveCustomMangel"
                >
                    {{ t('FakturaView.faktura.maengel.createCustomSave') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import { defineComponent, ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'

export default defineComponent({
    name: 'MaengelSectionCard',

    props: {
        oeId: {
            type: Number,
            default: null
        },
        ensureOeId: {
            type: Function,
            default: null
        },
        docType: {
            type: String,
            default: 'order'
        }
    },

    setup(props) {
        const { t } = useI18n()
        const carsStore = lxcarsStore()

        const maengel = ref([])
        const loading = ref(true)
        const searching = ref(false)
        const searchQuery = ref('')
        const searchResults = ref([])
        const selectedMangel = ref(null)
        let searchTimeout = null

        // HU-Ergebnis: bestanden wenn kein Mangel mit EM/VM/VU
        const huBestanden = computed(() => {
            return !maengel.value.some(m => ['EM', 'VM', 'VU'].includes(m.defect_class))
        })

        const deleteDialog = reactive({
            show: false,
            mangel: null
        })

        const deleteAllDialog = reactive({
            show: false,
            deleting: false
        })

        const createCustomDialog = reactive({
            show: false,
            saving: false,
            beschreibung: '',
            klassen: []
        })

        const klassenOptions = ['GM', 'EM', 'VM', 'VU', 'HW']

        const createCustomValid = computed(() => {
            return createCustomDialog.beschreibung.trim().length > 0 && createCustomDialog.klassen.length > 0
        })

        // ===== Laden =====

        async function loadMaengel() {
            if (!props.oeId) {
                loading.value = false
                return
            }
            try {
                loading.value = true
                maengel.value = await carsStore.loadMaengel(props.oeId, props.docType)
            } catch (e) {
                console.error('Error loading maengel:', e)
                maengel.value = []
            } finally {
                loading.value = false
            }
        }

        onMounted(loadMaengel)

        watch(() => props.oeId, loadMaengel)

        // ===== Suche =====

        function onSearch(val) {
            if (searchTimeout) clearTimeout(searchTimeout)
            if (!val || val.length < 2) {
                searchResults.value = []
                searching.value = false
                return
            }

            searching.value = true
            searchTimeout = setTimeout(async () => {
                try {
                    searchResults.value = await carsStore.searchMaengelliste(val)
                } catch (e) {
                    console.error('Error searching maengelliste:', e)
                    searchResults.value = []
                } finally {
                    searching.value = false
                }
            }, 250)
        }

        function formatSearchItem(item) {
            if (!item || typeof item === 'string') return item
            return `[${item.defect_code}] ${item.defect_description}`
        }

        // ===== Mangel hinzufügen =====

        async function addMangelFromItem(item) {
            if (!item || !item.defect_code) return

            const oeId = props.oeId || (props.ensureOeId ? await props.ensureOeId() : null)
            if (!oeId) return

            const klassen = (item.possible_classes || '').split('|').filter(Boolean)
            const defaultKlasse = klassen[0] || 'GM'

            try {
                const result = await carsStore.addMangel(oeId, {
                    defect_code: item.defect_code,
                    defect_description: item.defect_description,
                    defect_class: defaultKlasse
                }, props.docType)

                maengel.value.push({
                    id: result.id,
                    oe_id: oeId,
                    defect_code: item.defect_code,
                    defect_description: item.defect_description,
                    defect_class: defaultKlasse,
                    possible_classes: item.possible_classes,
                    note: null,
                    sort_order: result.sort_order
                })
            } catch (e) {
                console.error('Error adding mangel:', e)
            }

            // Reset Suche
            if (searchTimeout) clearTimeout(searchTimeout)
            searchResults.value = []
            selectedMangel.value = null
            searchQuery.value = ''
        }

        function onMangelSelect(item) {
            addMangelFromItem(item)
        }

        function onEnterAdd() {
            if (searchResults.value.length > 0) {
                addMangelFromItem(searchResults.value[0])
            } else if (searchQuery.value && searchQuery.value.length >= 2 && !searching.value) {
                openCreateCustomDialog()
            }
        }

        // ===== Klasse ändern =====

        async function onKlasseChange(mangel, newKlasse) {
            const oldKlasse = mangel.defect_class
            mangel.defect_class = newKlasse
            try {
                await carsStore.updateMangel(mangel.id, { defect_class: newKlasse }, props.docType)
            } catch (e) {
                mangel.defect_class = oldKlasse
                console.error('Error updating defect_class:', e)
            }
        }

        // ===== Notiz ändern =====

        const notizCache = {}

        function onNotizBlur(mangel) {
            const currentNotiz = mangel.note || ''
            const cachedNotiz = notizCache[mangel.id] || ''
            if (currentNotiz === cachedNotiz) return

            notizCache[mangel.id] = currentNotiz
            carsStore.updateMangel(mangel.id, { note: mangel.note }, props.docType).catch(e => {
                console.error('Error updating notiz:', e)
            })
        }

        // Initialer Cache bei Laden
        watch(maengel, (items) => {
            items.forEach(m => { notizCache[m.id] = m.note || '' })
        })

        // ===== Löschen =====

        function confirmDelete(mangel) {
            deleteDialog.mangel = mangel
            deleteDialog.show = true
        }

        async function doDelete() {
            const mangel = deleteDialog.mangel
            if (!mangel) return

            deleteDialog.show = false
            try {
                await carsStore.deleteMangel(mangel.id, props.docType)
                const idx = maengel.value.findIndex(m => m.id === mangel.id)
                if (idx !== -1) maengel.value.splice(idx, 1)
            } catch (e) {
                console.error('Error deleting mangel:', e)
            }
        }

        // ===== Alle löschen =====

        function confirmDeleteAll() {
            if (!maengel.value.length) return
            deleteAllDialog.deleting = false
            deleteAllDialog.show = true
        }

        async function executeDeleteAll() {
            deleteAllDialog.deleting = true
            try {
                await carsStore.deleteAllMaengel(props.oeId, props.docType)
                maengel.value = []
                deleteAllDialog.show = false
            } catch (e) {
                console.error('Error deleting all maengel:', e)
            } finally {
                deleteAllDialog.deleting = false
            }
        }

        // ===== Eigenen Mangel anlegen =====

        function openCreateCustomDialog() {
            createCustomDialog.beschreibung = searchQuery.value || ''
            createCustomDialog.klassen = []
            createCustomDialog.saving = false
            createCustomDialog.show = true
        }

        async function saveCustomMangel() {
            if (!createCustomValid.value || createCustomDialog.saving) return

            createCustomDialog.saving = true
            try {
                const newEntry = await carsStore.addCustomMangel({
                    defect_description: createCustomDialog.beschreibung.trim(),
                    possible_classes: createCustomDialog.klassen.join('|')
                })

                createCustomDialog.show = false
                await addMangelFromItem(newEntry)
            } catch (e) {
                console.error('Error creating custom mangel:', e)
            } finally {
                createCustomDialog.saving = false
            }
        }

        // ===== Helpers =====

        function getKlassenOptions(mangel) {
            const raw = mangel.possible_classes || mangel.defect_class || ''
            return raw.split('|').filter(Boolean)
        }

        function getKlasseColor(klasse) {
            switch (klasse) {
                case 'OM': return 'success'
                case 'GM': return 'info'
                case 'EM': return 'warning'
                case 'VM': return 'error'
                case 'VU': return 'error'
                case 'HW': return 'grey'
                default: return 'grey'
            }
        }

        return {
            t,
            maengel,
            huBestanden,
            loading,
            searching,
            searchQuery,
            searchResults,
            selectedMangel,
            deleteDialog,
            deleteAllDialog,
            createCustomDialog,
            klassenOptions,
            createCustomValid,
            confirmDeleteAll,
            executeDeleteAll,
            openCreateCustomDialog,
            saveCustomMangel,
            loadMaengel,
            onSearch,
            formatSearchItem,
            onMangelSelect,
            onEnterAdd,
            onKlasseChange,
            onNotizBlur,
            confirmDelete,
            doDelete,
            getKlassenOptions,
            getKlasseColor
        }
    }
})
</script>

<style scoped>
.maengel-table :deep(th) {
    font-size: 0.9rem !important;
    font-weight: 700 !important;
}

.maengel-table :deep(td) {
    padding-top: 4px !important;
    padding-bottom: 4px !important;
}

.maengel-select :deep(.v-field) {
    font-size: 0.85rem;
}
</style>
