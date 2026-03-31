<!-- src/features/lxcars/views/mechanic/mechanic.view.vue -->

<template>
    <div class="mechanic-view">
        <!-- Header -->
        <div class="mechanic-header pa-4 d-flex align-center">
            <v-icon size="large" color="primary" class="mr-3">mdi-wrench</v-icon>
            <h1 class="text-h5 font-weight-bold flex-grow-1">{{ t('MechanicView.title') }}</h1>
            <v-btn-toggle v-model="viewMode" mandatory density="compact" color="primary" class="mr-2">
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

        <!-- Loading -->
        <div v-if="loading" class="d-flex justify-center pa-8">
            <v-progress-circular indeterminate color="primary" size="48" />
        </div>

        <!-- Keine Aufträge -->
        <div v-else-if="!orders.length" class="text-center pa-8">
            <v-icon size="80" color="grey-lighten-1" class="mb-4">mdi-clipboard-text-off</v-icon>
            <div class="text-h6 text-medium-emphasis">{{ t('MechanicView.noOrders') }}</div>
            <div class="text-body-2 text-medium-emphasis mt-1">{{ t('MechanicView.noOrdersHint') }}</div>
        </div>

        <!-- Auftragsliste -->
        <div v-else class="mechanic-orders pa-4">
            <v-card
                v-for="order in orders"
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
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'

const { t } = useI18n()
const router = useRouter()
const carsStore = lxcarsStore()

const orders = ref([])
const loading = ref(false)
const viewMode = ref('mine')
const sseConnected = ref(false)

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

.mechanic-order-card {
    border-radius: 12px;
    cursor: pointer;
    transition: transform 0.15s, box-shadow 0.15s;
}

.mechanic-order-card:active {
    transform: scale(0.98);
}
</style>
