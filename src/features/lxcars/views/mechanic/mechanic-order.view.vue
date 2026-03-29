<!-- src/features/lxcars/views/mechanic/mechanic-order.view.vue -->

<template>
    <div class="mechanic-detail">
        <!-- Header -->
        <div class="mechanic-detail__header pa-3 d-flex align-center">
            <v-btn icon variant="text" @click="$router.push({ name: 'mechanic' })">
                <v-icon>mdi-arrow-left</v-icon>
            </v-btn>
            <div class="ml-2 flex-grow-1">
                <div class="text-subtitle-1 font-weight-bold">{{ orderData?.order?.ordnumber || '' }}</div>
                <div class="text-caption text-medium-emphasis">
                    {{ orderData?.order?.customer_name }}
                    <span v-if="orderData?.vehicle?.c_ln" class="ml-2 font-weight-bold">{{ orderData.vehicle.c_ln }}</span>
                </div>
            </div>
            <v-btn icon variant="text" @click="loadDetail">
                <v-icon>mdi-refresh</v-icon>
            </v-btn>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="d-flex justify-center pa-8">
            <v-progress-circular indeterminate color="primary" size="48" />
        </div>

        <template v-else-if="orderData">
            <!-- Tabs -->
            <v-tabs v-model="activeTab" grow density="compact" color="primary">
                <v-tab value="instructions">
                    <v-icon start size="small">mdi-clipboard-check</v-icon>
                    {{ t('MechanicView.instructions') }}
                    <v-badge v-if="instructionsDoneCount < instructionsTotal" :content="instructionsOpen" color="warning" inline class="ml-1" />
                </v-tab>
                <v-tab value="positions">
                    <v-icon start size="small">mdi-format-list-numbered</v-icon>
                    {{ t('MechanicView.positions') }}
                </v-tab>
                <v-tab value="defects">
                    <v-icon start size="small">mdi-alert-circle</v-icon>
                    {{ t('MechanicView.defects') }}
                    <v-badge v-if="defects.length" :content="defects.length" color="error" inline class="ml-1" />
                </v-tab>
                <v-tab value="parts">
                    <v-icon start size="small">mdi-cart</v-icon>
                    {{ t('MechanicView.partsCart') }}
                    <v-badge v-if="pendingPartsCount" :content="pendingPartsCount" color="orange" inline class="ml-1" />
                </v-tab>
            </v-tabs>

            <v-divider />

            <v-window v-model="activeTab" class="mechanic-detail__content">
                <!-- ===== ANWEISUNGEN ===== -->
                <v-window-item value="instructions">
                    <div class="pa-3">
                        <v-card v-for="instr in instructions" :key="instr.id" variant="outlined" class="mb-2 rounded-lg">
                            <v-card-text class="pa-3">
                                <div class="d-flex align-center">
                                    <v-checkbox
                                        :model-value="instr.done"
                                        hide-details
                                        density="compact"
                                        color="success"
                                        class="mr-2 flex-shrink-0"
                                        @update:model-value="toggleInstruction(instr, $event)"
                                    />
                                    <div class="flex-grow-1">
                                        <div class="text-body-1" :class="{ 'text-decoration-line-through text-medium-emphasis': instr.done }">
                                            {{ instr.description }}
                                        </div>
                                        <div class="text-caption text-medium-emphasis">
                                            {{ instr.instruction_number }}
                                            <span v-if="instr.employee_name" class="ml-2">{{ instr.employee_name }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex align-center mt-2 ga-3">
                                    <v-chip size="x-small" variant="tonal" color="blue">
                                        {{ t('MechanicView.plannedMinutes') }}: {{ instr.planned_minutes || 0 }} {{ t('MechanicView.minutes') }}
                                    </v-chip>
                                    <v-text-field
                                        :model-value="instr.actual_minutes"
                                        @update:model-value="instr.actual_minutes = Number($event)"
                                        @blur="saveInstructionField(instr, 'actual_minutes', instr.actual_minutes)"
                                        :label="t('MechanicView.actualMinutes')"
                                        type="number"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        style="max-width: 120px"
                                        :suffix="t('MechanicView.minutes')"
                                    />
                                </div>
                            </v-card-text>
                        </v-card>
                    </div>
                </v-window-item>

                <!-- ===== POSITIONEN ===== -->
                <v-window-item value="positions">
                    <div class="pa-3">
                        <div v-if="!positions.length" class="text-center text-medium-emphasis py-6">
                            {{ t('MechanicView.noPositions') }}
                        </div>
                        <v-card v-for="item in positions" :key="item.id" variant="outlined" class="mb-2 rounded-lg">
                            <v-card-text class="pa-3">
                                <div class="d-flex align-center mb-2">
                                    <v-chip size="x-small" variant="tonal" class="mr-2">{{ item.position }}</v-chip>
                                    <span v-if="item.partnumber" class="text-caption text-medium-emphasis">{{ item.partnumber }}</span>
                                </div>
                                <v-text-field
                                    v-model="item.description"
                                    :label="t('MechanicView.description')"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    class="mb-2"
                                    @blur="savePosition(item)"
                                />
                                <div class="d-flex ga-2">
                                    <v-text-field
                                        v-model.number="item.qty"
                                        :label="t('MechanicView.qty')"
                                        type="number"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        style="max-width: 100px"
                                        @blur="savePosition(item)"
                                    />
                                    <v-text-field
                                        v-model="item.unit"
                                        :label="t('MechanicView.unit')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        style="max-width: 80px"
                                        @blur="savePosition(item)"
                                    />
                                    <v-text-field
                                        v-model.number="item.sellprice"
                                        :label="t('MechanicView.price')"
                                        type="number"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        style="max-width: 120px"
                                        @blur="savePosition(item)"
                                    />
                                </div>
                            </v-card-text>
                        </v-card>
                    </div>
                </v-window-item>

                <!-- ===== MÄNGEL ===== -->
                <v-window-item value="defects">
                    <div class="pa-3">
                        <!-- Neuen Mangel hinzufügen -->
                        <v-autocomplete
                            v-model:search="defectSearch"
                            :items="defectSearchResults"
                            item-title="display"
                            item-value="code"
                            :label="t('MechanicView.addDefect')"
                            :placeholder="t('MechanicView.searchDefect')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            class="mb-3"
                            :no-filter="true"
                            clearable
                            return-object
                            @update:model-value="onDefectSelect"
                        />

                        <div v-if="!defects.length" class="text-center text-medium-emphasis py-6">
                            {{ t('MechanicView.noDefects') }}
                        </div>

                        <v-card v-for="defect in defects" :key="defect.id" variant="outlined" class="mb-2 rounded-lg">
                            <v-card-text class="pa-3">
                                <div class="d-flex align-center mb-1">
                                    <v-chip size="x-small" :color="getDefectColor(defect.defect_class)" variant="tonal" class="mr-2">
                                        {{ defect.defect_class }}
                                    </v-chip>
                                    <span class="text-caption text-medium-emphasis">{{ defect.defect_code }}</span>
                                </div>
                                <div class="text-body-2">{{ defect.defect_description }}</div>
                                <v-text-field
                                    v-model="defect.note"
                                    :label="t('MechanicView.defectNote')"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    class="mt-2"
                                    @blur="saveDefectNote(defect)"
                                />
                            </v-card-text>
                        </v-card>
                    </div>
                </v-window-item>

                <!-- ===== ERSATZTEILE ===== -->
                <v-window-item value="parts">
                    <div class="pa-3">
                        <!-- Neues Ersatzteil anfordern -->
                        <v-card variant="tonal" color="orange" class="mb-3 rounded-lg">
                            <v-card-text class="pa-3">
                                <v-autocomplete
                                    v-model:search="partSearch"
                                    v-model="selectedPart"
                                    :items="partSearchResults"
                                    item-title="description"
                                    item-value="id"
                                    :label="t('MechanicView.partsSearch')"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    class="mb-2"
                                    bg-color="white"
                                    :no-filter="true"
                                    clearable
                                    return-object
                                >
                                    <template #item="{ item, props: itemProps }">
                                        <v-list-item v-bind="itemProps" :subtitle="item.raw.partnumber" />
                                    </template>
                                </v-autocomplete>
                                <v-text-field
                                    v-model="partFreetext"
                                    :label="t('MechanicView.partsFreetextHint')"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    class="mb-2"
                                    bg-color="white"
                                    @keydown.enter="addPartFromFreetext"
                                />
                                <div class="d-flex ga-2 align-center">
                                    <v-text-field
                                        v-model.number="partQty"
                                        :label="t('MechanicView.qty')"
                                        type="number"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        style="max-width: 80px"
                                        bg-color="white"
                                    />
                                    <v-text-field
                                        v-model="partNote"
                                        :label="t('MechanicView.partsNote')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        class="flex-grow-1"
                                        bg-color="white"
                                    />
                                    <v-btn color="primary" variant="elevated" @click="addPart" :disabled="!canAddPart">
                                        <v-icon start>mdi-cart-plus</v-icon>
                                        {{ t('MechanicView.partsRequest') }}
                                    </v-btn>
                                </div>
                            </v-card-text>
                        </v-card>

                        <!-- Liste der Anfragen -->
                        <div v-if="!partsRequests.length" class="text-center text-medium-emphasis py-6">
                            <v-icon size="48" color="grey-lighten-1">mdi-cart-outline</v-icon>
                            <div class="mt-2">{{ t('MechanicView.partsEmpty') }}</div>
                        </div>

                        <v-card v-for="pr in partsRequests" :key="pr.id" variant="outlined" class="mb-2 rounded-lg">
                            <v-card-text class="pa-3">
                                <div class="d-flex align-center mb-1">
                                    <v-chip
                                        size="x-small"
                                        :color="pr.status === 'ordered' ? 'success' : 'orange'"
                                        variant="tonal"
                                        class="mr-2"
                                    >
                                        {{ pr.status === 'ordered' ? t('MechanicView.partsOrdered') : t('MechanicView.partsRequested') }}
                                    </v-chip>
                                    <span v-if="pr.partnumber" class="text-caption text-medium-emphasis">{{ pr.partnumber }}</span>
                                    <v-spacer />
                                    <v-btn
                                        v-if="pr.status === 'pending'"
                                        icon
                                        size="x-small"
                                        variant="text"
                                        color="error"
                                        @click="removePart(pr)"
                                    >
                                        <v-icon size="small">mdi-delete</v-icon>
                                    </v-btn>
                                </div>
                                <div class="text-body-1">{{ pr.description }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ pr.qty }} {{ pr.unit }}
                                    <span v-if="pr.note" class="ml-2">— {{ pr.note }}</span>
                                </div>
                                <div v-if="pr.vendor_name" class="text-caption mt-1">
                                    <v-icon size="x-small" class="mr-1">mdi-truck</v-icon>
                                    {{ pr.vendor_name }}
                                </div>
                                <!-- Foto -->
                                <div class="mt-2 d-flex ga-2 align-center">
                                    <v-btn
                                        v-if="pr.status === 'pending' && !pr.photoSrc"
                                        size="small"
                                        variant="tonal"
                                        color="blue"
                                        @click="takePhoto(pr)"
                                    >
                                        <v-icon start size="small">mdi-camera</v-icon>
                                        {{ t('MechanicView.partsPhoto') }}
                                    </v-btn>
                                    <img v-if="pr.photoSrc" :src="pr.photoSrc" style="max-width: 120px; max-height: 80px; border-radius: 6px; cursor: pointer;" @click="viewPhoto(pr)" />
                                </div>
                                <input
                                    :ref="el => { if (el) photoInputRefs[pr.id] = el }"
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    style="display: none"
                                    @change="onPhotoCapture($event, pr)"
                                />
                            </v-card-text>
                        </v-card>
                    </div>
                </v-window-item>
            </v-window>
        </template>

        <!-- Foto-Betrachter -->
        <v-dialog v-model="photoViewerOpen" max-width="600">
            <v-card>
                <v-card-title class="d-flex align-center py-2 px-4">
                    <span>{{ t('MechanicView.partsPhoto') }}</span>
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="photoViewerOpen = false" />
                </v-card-title>
                <v-card-text class="pa-2 text-center">
                    <img v-if="photoViewerSrc" :src="photoViewerSrc" style="max-width: 100%; border-radius: 6px;" />
                </v-card-text>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import axios from 'axios'

const props = defineProps({
    id: { type: [String, Number], required: true }
})

const { t } = useI18n()
const carsStore = lxcarsStore()

const loading = ref(false)
const orderData = ref(null)
const activeTab = ref('instructions')

const instructions = ref([])
const positions = ref([])
const defects = ref([])
const partsRequests = ref([])

const instructionsDoneCount = computed(() => instructions.value.filter(i => i.done).length)
const instructionsTotal = computed(() => instructions.value.length)
const instructionsOpen = computed(() => instructionsTotal.value - instructionsDoneCount.value)
const pendingPartsCount = computed(() => partsRequests.value.filter(p => p.status === 'pending').length)

// ===== Daten laden =====

async function loadDetail() {
    loading.value = true
    try {
        const data = await carsStore.loadMechanicOrderDetail(props.id)
        orderData.value = data
        instructions.value = data.instructions || []
        positions.value = (data.items || []).filter(i => i.parts_id)
        defects.value = data.defects || []
        partsRequests.value = data.parts_requests || []
        // Fotos laden
        for (const pr of partsRequests.value) {
            if (pr.photo) loadPhotoThumb(pr)
        }
    } catch (e) {
        console.error('Error loading mechanic order:', e)
    } finally {
        loading.value = false
    }
}

// ===== Anweisungen =====

async function toggleInstruction(instr, done) {
    instr.done = done
    try {
        await carsStore.updateInstruction({ id: instr.id, done })
    } catch (e) {
        instr.done = !done
        console.error('Error updating instruction:', e)
    }
}

async function saveInstructionField(instr, field, value) {
    try {
        await carsStore.updateInstruction({ id: instr.id, [field]: value })
    } catch (e) {
        console.error('Error saving instruction field:', e)
    }
}

// ===== Positionen =====

let positionSaveTimer = null
async function savePosition(item) {
    if (positionSaveTimer) clearTimeout(positionSaveTimer)
    positionSaveTimer = setTimeout(async () => {
        try {
            await axios.post('/api/faktura/', {
                action: 'updateFakturaItems',
                fakturaID: Number(props.id),
                fakturaType: 'order',
                items: [{
                    id: item.id,
                    position: item.position,
                    description: item.description,
                    longdescription: item.longdescription || '',
                    qty: Number(item.qty),
                    sellprice: Number(item.sellprice),
                    discount: Number(item.discount || 0),
                    unit: item.unit || ''
                }],
                netAmount: 0,
                grossAmount: 0
            })
        } catch (e) {
            console.error('Error saving position:', e)
        }
    }, 500)
}

// ===== Mängel =====

const defectSearch = ref('')
const defectSearchResults = ref([])
let defectSearchTimer = null

watch(defectSearch, (val) => {
    if (defectSearchTimer) clearTimeout(defectSearchTimer)
    if (!val || val.length < 2) { defectSearchResults.value = []; return }
    defectSearchTimer = setTimeout(async () => {
        try {
            const results = await carsStore.searchMaengelliste(val)
            defectSearchResults.value = (results || []).map(r => ({
                ...r,
                display: `${r.code} — ${r.description}`
            }))
        } catch { defectSearchResults.value = [] }
    }, 300)
})

async function onDefectSelect(item) {
    if (!item) return
    try {
        await carsStore.addMangel({
            oe_id: Number(props.id),
            defect_code: item.code,
            defect_description: item.description,
            defect_class: item.defect_class || 'GM',
            doc_type: 'order'
        })
        defectSearch.value = ''
        defectSearchResults.value = []
        loadDetail()
    } catch (e) {
        console.error('Error adding defect:', e)
    }
}

async function saveDefectNote(defect) {
    try {
        await carsStore.updateMangel({ id: defect.id, note: defect.note, doc_type: 'order' })
    } catch (e) {
        console.error('Error saving defect note:', e)
    }
}

function getDefectColor(cls) {
    const map = { OM: 'green', GM: 'amber', EM: 'orange', VM: 'red', VU: 'red-darken-3', HW: 'blue' }
    return map[cls] || 'grey'
}

// ===== Ersatzteile =====

const partSearch = ref('')
const selectedPart = ref(null)
const partFreetext = ref('')
const partQty = ref(1)
const partNote = ref('')
const partSearchResults = ref([])
let partSearchTimer = null

const canAddPart = computed(() => !!selectedPart.value || !!partFreetext.value.trim())

watch(partSearch, (val) => {
    if (partSearchTimer) clearTimeout(partSearchTimer)
    if (!val || val.length < 2) { partSearchResults.value = []; return }
    partSearchTimer = setTimeout(async () => {
        try {
            const resp = await axios.post('/api/parts/', {
                action: 'searchParts',
                where: [{ column: 'all', value: val }],
                limit: 15
            })
            partSearchResults.value = resp.data?.results || []
        } catch { partSearchResults.value = [] }
    }, 300)
})

async function addPart() {
    const data = {
        qty: partQty.value || 1,
        unit: selectedPart.value?.unit || 'Stck',
        note: partNote.value
    }
    if (selectedPart.value) {
        data.parts_id = selectedPart.value.id
        data.partnumber = selectedPart.value.partnumber
        data.description = selectedPart.value.description
    } else if (partFreetext.value.trim()) {
        data.description = partFreetext.value.trim()
    } else {
        return
    }

    try {
        await carsStore.addPartsRequest(Number(props.id), data)
        selectedPart.value = null
        partFreetext.value = ''
        partQty.value = 1
        partNote.value = ''
        partSearch.value = ''
        loadDetail()
    } catch (e) {
        console.error('Error adding parts request:', e)
    }
}

function addPartFromFreetext() {
    if (partFreetext.value.trim()) addPart()
}

async function removePart(pr) {
    try {
        await carsStore.deletePartsRequest(pr.id)
        loadDetail()
    } catch (e) {
        console.error('Error deleting parts request:', e)
    }
}

// ===== Fotos =====

const photoInputRefs = ref({})
const photoViewerOpen = ref(false)
const photoViewerSrc = ref('')

function takePhoto(pr) {
    const input = photoInputRefs.value[pr.id]
    if (input) input.click()
}

async function onPhotoCapture(event, pr) {
    const file = event.target.files?.[0]
    if (!file) return

    const reader = new FileReader()
    reader.onload = async () => {
        const base64 = reader.result
        try {
            await carsStore.savePartsRequestPhoto(pr.id, base64)
            pr.photoSrc = base64
        } catch (e) {
            console.error('Error saving photo:', e)
        }
    }
    reader.readAsDataURL(file)
}

async function loadPhotoThumb(pr) {
    try {
        const data = await carsStore.getPartsRequestPhoto(pr.id)
        if (data?.image) {
            pr.photoSrc = `data:${data.mime};base64,${data.image}`
        }
    } catch { /* kein Foto */ }
}

function viewPhoto(pr) {
    photoViewerSrc.value = pr.photoSrc
    photoViewerOpen.value = true
}

// ===== SSE =====

let sseSource = null

function connectSSE() {
    sseSource = new EventSource('/sse/events')
    sseSource.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data)
            const relevantTables = ['oe_instructions_lxcars', 'oe_defects', 'orderitems', 'oe_parts_requests_lxcars', 'oe']
            if (relevantTables.includes(data.table) && Number(data.id) === Number(props.id)) {
                loadDetail()
            }
        } catch { /* ignorieren */ }
    }
    sseSource.onerror = () => {}
}

onMounted(() => {
    loadDetail()
    connectSSE()
})

onBeforeUnmount(() => {
    if (sseSource) { sseSource.close(); sseSource = null }
})
</script>

<style scoped>
.mechanic-detail {
    min-height: 100vh;
    background: #f0f2f5;
}

.mechanic-detail__header {
    background: white;
    border-bottom: 1px solid #e0e0e0;
    position: sticky;
    top: 0;
    z-index: 5;
}

.mechanic-detail__content {
    min-height: calc(100vh - 120px);
}
</style>
