<!-- src/features/lxcars/views/mechanic/mechanic.view.vue -->

<template>
    <div class="mechanic-view">
        <!-- Header -->
        <div class="mechanic-header pa-4">
            <div class="d-flex align-center flex-wrap ga-2">
                <v-icon size="large" color="primary">mdi-wrench</v-icon>
                <h1 class="text-h5 font-weight-bold flex-grow-1">{{ t('MechanicView.title') }}</h1>

                <v-text-field
                    v-model="filterText"
                    :placeholder="t('MechanicView.filter')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                    class="mechanic-field flex-grow-0"
                />

                <!-- "Auftrag fehlt": öffnet Such-Dialog -->
                <v-btn
                    color="warning"
                    variant="tonal"
                    prepend-icon="mdi-clipboard-alert-outline"
                    class="flex-grow-0"
                    @click="openMissingOrderDialog"
                >
                    {{ t('MechanicView.missingOrder.label') }}
                </v-btn>

                <v-btn-toggle v-model="viewMode" mandatory density="compact" color="primary">
                    <v-btn value="mine" size="small">
                        <v-icon start size="small">mdi-account</v-icon>
                        {{ t('MechanicView.myOrders') }}
                    </v-btn>
                    <v-btn value="all" size="small">
                        <v-icon start size="small">mdi-account-group</v-icon>
                        {{ t('MechanicView.allOrders') }}
                    </v-btn>
                </v-btn-toggle>
                <v-btn v-if="!sseConnected" icon variant="text" color="primary" @click="loadOrders" :title="t('MechanicView.refresh')">
                    <v-icon>mdi-refresh</v-icon>
                </v-btn>
                <v-btn icon variant="text" color="primary" @click="router.push(t('routes.mainmenu'))" :title="t('MechanicView.exitMechanic')">
                    <v-icon>mdi-exit-to-app</v-icon>
                </v-btn>
            </div>
        </div>

        <!-- "Auftrag fehlt": Such-Dialog (Fahrzeug per Name/Kennzeichen/FIN oder Freitext) -->
        <v-dialog v-model="missingOrderDialog" max-width="520">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon class="mr-2" color="warning">mdi-clipboard-alert-outline</v-icon>
                    {{ t('MechanicView.missingOrder.label') }}
                </v-card-title>
                <v-card-text>
                    <v-autocomplete
                        v-model="missingOrderSelected"
                        v-model:search="missingOrderSearch"
                        :items="missingOrderResults"
                        :loading="missingOrderLoading"
                        :label="t('MechanicView.missingOrder.searchLabel')"
                        item-title="display"
                        item-value="c_id"
                        return-object
                        no-filter
                        clearable
                        autofocus
                        hide-details
                        variant="outlined"
                        prepend-inner-icon="mdi-magnify"
                        :menu-props="{ maxHeight: 320 }"
                        @update:model-value="onMissingOrderSelect"
                    >
                        <template #item="{ props, item }">
                            <v-list-item v-bind="props" :title="null" lines="two">
                                <template #prepend>
                                    <v-icon size="small" color="grey-darken-1">mdi-car</v-icon>
                                </template>
                                <v-list-item-title class="font-weight-medium">{{ item.raw.c_ln }}</v-list-item-title>
                                <v-list-item-subtitle v-if="item.raw.subtitle" class="text-caption">
                                    {{ item.raw.subtitle }}
                                </v-list-item-subtitle>
                            </v-list-item>
                        </template>

                        <template #no-data>
                            <div class="px-4 py-3 text-body-2 text-medium-emphasis">
                                {{ missingOrderSearch && missingOrderSearch.trim().length >= 2
                                    ? t('MechanicView.missingOrder.noVehicle')
                                    : t('MechanicView.missingOrder.hint') }}
                            </div>
                        </template>

                        <!-- Freitext immer am Listenende anbieten, sobald getippt wurde -->
                        <template #append-item>
                            <template v-if="missingOrderSearch && missingOrderSearch.trim().length >= 2">
                                <v-divider />
                                <v-list-item @click="reportFreeText">
                                    <template #prepend>
                                        <v-icon color="warning">mdi-pencil-plus</v-icon>
                                    </template>
                                    <v-list-item-title>
                                        {{ t('MechanicView.missingOrder.addFreeTextNamed', { label: missingOrderSearch.trim() }) }}
                                    </v-list-item-title>
                                </v-list-item>
                            </template>
                        </template>
                    </v-autocomplete>

                    <div class="text-caption text-medium-emphasis mt-2">
                        {{ t('MechanicView.missingOrder.hint') }}
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="missingOrderDialog = false">
                        {{ t('MechanicView.missingOrder.cancel') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Loading -->
        <div v-if="loading" class="d-flex justify-center pa-8">
            <v-progress-circular indeterminate color="primary" size="48" />
        </div>

        <!-- Keine Aufträge -->
        <div v-else-if="!filteredOrders.length" class="text-center pa-8">
            <v-icon size="80" color="grey-lighten-1" class="mb-4">mdi-clipboard-text-off</v-icon>
            <div class="text-h6 text-medium-emphasis">{{ t('MechanicView.noOrders') }}</div>
            <div class="text-body-2 text-medium-emphasis mt-1">{{ t('MechanicView.noOrdersHint') }}</div>
        </div>

        <!-- Auftragsliste -->
        <div v-else class="mechanic-orders pa-4">
            <v-card
                v-for="order in filteredOrders"
                :key="order.id"
                class="mechanic-order-card mb-3"
                variant="elevated"
                @click="openOrder(order)"
            >
                <v-card-text class="pa-4">
                    <div class="d-flex align-center mb-2">
                        <v-chip size="small" color="primary" variant="tonal" class="font-weight-bold mr-2">
                            {{ order.ordnumber || '#' + order.id }}
                        </v-chip>
                        <span class="text-body-2 text-medium-emphasis">{{ formatDate(order.transdate) }}</span>
                        <v-spacer />
                        <v-chip
                            size="small"
                            :color="order.instruction_done == order.instruction_count ? 'success' : 'warning'"
                            variant="tonal"
                        >
                            {{ order.instruction_done }}/{{ order.instruction_count }}
                        </v-chip>
                        <v-badge v-if="order.pending_parts > 0" :content="order.pending_parts" color="orange" inline class="ml-2">
                            <v-icon size="small">mdi-cart</v-icon>
                        </v-badge>
                    </div>
                    <div class="text-body-1 font-weight-medium">{{ order.customer_name }}</div>
                    <div v-if="order.vehicle_plate" class="d-flex align-center mt-1">
                        <v-icon size="small" color="grey" class="mr-1">mdi-car</v-icon>
                        <span class="text-body-2 font-weight-bold">{{ order.vehicle_plate }}</span>
                        <span v-if="order.vehicle_manufacturer" class="text-body-2 text-medium-emphasis ml-2">
                            {{ order.vehicle_manufacturer }} {{ order.vehicle_model }}
                        </span>
                    </div>
                </v-card-text>
            </v-card>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import * as toast from '@/core/utils/toasts.js'

const { t } = useI18n()
const router = useRouter()
const carsStore = lxcarsStore()

const orders = ref([])
const loading = ref(false)
const viewMode = ref('mine')
const sseConnected = ref(false)
const filterText = ref('')

const filteredOrders = computed(() => {
    const q = (filterText.value || '').trim().toLowerCase()
    if (!q) return orders.value
    return orders.value.filter(o => {
        const haystack = [
            o.ordnumber,
            o.customer_name,
            o.vehicle_plate,
            o.vehicle_manufacturer,
            o.vehicle_model,
            o.transdate
        ].filter(Boolean).join(' ').toLowerCase()
        return haystack.includes(q)
    })
})

async function loadOrders() {
    loading.value = true
    try {
        orders.value = viewMode.value === 'all'
            ? await carsStore.loadAllMechanicOrders()
            : await carsStore.loadMechanicOrders()
    } catch (e) {
        console.error('Error loading mechanic orders:', e)
        orders.value = []
    } finally {
        loading.value = false
    }
}

watch(viewMode, () => loadOrders())

function openOrder(order) {
    router.push({ name: 'mechanic-order', params: { id: order.id } })
}

// ===== "Auftrag fehlt": Fahrzeug suchen und melden =====
const missingOrderDialog = ref(false)
const missingOrderSearch = ref('')
const missingOrderResults = ref([])
const missingOrderLoading = ref(false)
const missingOrderSelected = ref(null)
let missingSearchTimer = null

watch(missingOrderSearch, (q) => {
    const term = (q || '').trim()
    clearTimeout(missingSearchTimer)
    if (term.length < 2) { missingOrderResults.value = []; return }
    missingSearchTimer = setTimeout(async () => {
        missingOrderLoading.value = true
        try {
            const rows = await carsStore.searchCarsForMechanic(term)
            missingOrderResults.value = rows.map(c => ({
                ...c,
                // Kompakt: Kennzeichen als Titel, Halter/Fahrzeug als zweite Zeile
                display: c.c_ln,
                subtitle: [c.owner_name, [c.manufacturer, c.model].filter(Boolean).join(' ')]
                    .filter(Boolean).join(' · ')
            }))
        } catch {
            missingOrderResults.value = []
        } finally {
            missingOrderLoading.value = false
        }
    }, 300)
})

async function reportMissingOrder(label, cId = null) {
    try {
        await carsStore.addMissingOrder(label, cId)
        toast.success(t('MechanicView.missingOrder.added', { label }))
    } catch {
        toast.error(t('MechanicView.missingOrder.error'))
    }
}

function resetMissingOrder() {
    missingOrderSelected.value = null
    missingOrderSearch.value = ''
    missingOrderResults.value = []
}

function openMissingOrderDialog() {
    resetMissingOrder()
    missingOrderDialog.value = true
}

async function onMissingOrderSelect(val) {
    if (!val) return
    missingOrderDialog.value = false
    await reportMissingOrder(val.c_ln || val.display, val.c_id ?? null)
    resetMissingOrder()
}

// Freitext melden: der eingetippte Suchbegriff wird zur Bezeichnung
async function reportFreeText() {
    const label = (missingOrderSearch.value || '').trim()
    if (!label) return
    missingOrderDialog.value = false
    await reportMissingOrder(label, null)
    resetMissingOrder()
}

function formatDate(d) {
    if (!d) return ''
    const parts = d.split('-')
    if (parts.length === 3) return `${parts[2]}.${parts[1]}.${parts[0]}`
    return d
}

// SSE
let sseSource = null

function connectSSE() {
    sseSource = new EventSource('/sse/events')
    sseSource.onopen = () => { sseConnected.value = true }
    sseSource.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data)
            if (['oe_instructions_lxcars', 'oe_parts_requests_lxcars', 'oe'].includes(data.table)) {
                loadOrders()
            }
        } catch { /* ignorieren */ }
    }
    sseSource.onerror = () => { sseConnected.value = false }
}

onMounted(() => {
    loadOrders()
    connectSSE()
})

onBeforeUnmount(() => {
    if (sseSource) { sseSource.close(); sseSource = null }
})
</script>

<style scoped>
.mechanic-view {
    min-height: 100vh;
    background: #f0f2f5;
}

.mechanic-header {
    background: white;
    border-bottom: 1px solid #e0e0e0;
    position: sticky;
    top: 0;
    z-index: 5;
}

/* Such- und "Auftrag fehlt"-Feld: feste Breite, rechts in der Titelzeile.
   Auf schmalen Bildschirmen volle Breite, damit nichts gequetscht wird. */
.mechanic-field {
    width: 240px;
    flex: 0 0 auto;
}
@media (max-width: 600px) {
    .mechanic-field {
        width: 100%;
    }
}

.mechanic-order-card {
    border-radius: 12px;
    cursor: pointer;
    transition: transform 0.15s, box-shadow 0.15s;
}

.mechanic-order-card:active {
    transform: scale(0.98);
}
</style>
