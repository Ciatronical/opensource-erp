<!-- src/features/warehouse/components/part-stock.panel.vue -->
<!--
    Bestand eines Artikels für die Artikelmaske: Gesamtbestand, Verteilung
    auf Lager, Platz und Charge, dazu der Buchungsdialog.

    Der Bestand ist keine Eigenschaft des Artikels, sondern die Summe der
    Lagerbewegungen (inventory). Deshalb gibt es hier kein Mengenfeld —
    eine Menge wird eingelagert, ausgelagert oder umgelagert.

    Lager, Bewegungsarten und die Vorgabe zur Mindesthaltbarkeit kommen von
    der Elternansicht (getWarehouseOptions), die sie auch für ihre eigenen
    Auswahlfelder braucht — kein zweiter Abruf.
-->
<template>
    <div>
        <!-- Ohne Lager kann nichts gebucht werden: Weg zur Einrichtung -->
        <v-alert v-if="!warehouses.length" type="info" variant="tonal" density="compact">
            <div class="d-flex align-center flex-wrap ga-2">
                <span>{{ t('WarehouseView.partStock.noWarehouse') }}</span>
                <v-btn variant="text" size="small" density="comfortable" prepend-icon="mdi-warehouse"
                       :to="{ name: 'warehouse' }">
                    {{ t('WarehouseView.onboarding.title') }}
                </v-btn>
            </div>
        </v-alert>

        <template v-else>
            <div class="d-flex align-center flex-wrap ga-2 mb-2">
                <span class="text-body-2">
                    {{ t('WarehouseView.partStock.total') }}:
                    <strong>{{ num(stock.part?.stock_qty ?? 0) }} {{ part.unit || '' }}</strong>
                </span>
                <v-chip v-if="belowRop" size="x-small" color="warning" variant="tonal">
                    {{ t('WarehouseView.stock.ropReached', { rop: num(stock.part?.rop) }) }}
                </v-chip>
                <v-spacer />
                <v-btn color="primary" variant="tonal" size="small" prepend-icon="mdi-tray-arrow-down"
                       @click="openBooking">
                    {{ t('WarehouseView.partStock.book') }}
                </v-btn>
                <v-btn variant="text" size="small" prepend-icon="mdi-warehouse" :to="{ name: 'warehouse' }">
                    {{ t('WarehouseView.title') }}
                </v-btn>
            </div>

            <v-progress-linear v-if="loading" indeterminate height="2" class="mb-2" />

            <v-table v-if="locations.length" density="compact" class="oserp-part-stock">
                <thead>
                    <tr>
                        <th>{{ t('WarehouseView.warehouse') }}</th>
                        <th>{{ t('WarehouseView.bin') }}</th>
                        <th>{{ t('WarehouseView.booking.charge') }}</th>
                        <th class="text-right">{{ t('WarehouseView.booking.qty') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(l, i) in locations" :key="i">
                        <td>{{ l.warehouse }}</td>
                        <td>{{ l.bin }}</td>
                        <td>{{ l.chargenumber || '–' }}</td>
                        <td class="text-right">{{ num(l.qty) }} {{ part.unit || '' }}</td>
                    </tr>
                </tbody>
            </v-table>
            <p v-else-if="!loading" class="text-body-2 oserp-part-stock__empty mb-0">
                {{ t('WarehouseView.stock.noLocations') }}
            </p>
        </template>

        <StockBookingDialog
            v-model="bookingOpen"
            :warehouses="warehouses"
            :transfer-types="transferTypes"
            :show-bestbefore="showBestbefore"
            :preset-part="presetPart"
            preset-direction="in"
            @booked="onBooked"
        />
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import StockBookingDialog from './stock-booking.dialog.vue'
import { useWarehouse } from '../composables/useWarehouse.js'
import { formatNumber } from '@/core/utils/numberFormat.js'

const props = defineProps({
    /** Artikel: { id, partnumber, description, unit } */
    part: { type: Object, required: true },
    warehouses: { type: Array, default: () => [] },
    transferTypes: { type: Array, default: () => [] },
    showBestbefore: { type: Boolean, default: false },
})
const emit = defineEmits(['booked'])

const { t, locale } = useI18n()
const { fetchPartStock, loading } = useWarehouse()

const stock = ref({ part: null, locations: [], moves: [] })
const bookingOpen = ref(false)

const locations = computed(() => stock.value.locations || [])
const belowRop = computed(() => {
    const p = stock.value.part
    return !!p && Number(p.rop) > 0 && Number(p.stock_qty) <= Number(p.rop)
})
const presetPart = computed(() => ({
    id: props.part.id,
    partnumber: props.part.partnumber,
    description: props.part.description,
    unit: props.part.unit,
}))

function num(v) {
    return formatNumber(v, locale.value, Number.isInteger(Number(v)) ? 0 : 2)
}

async function load() {
    if (!props.part?.id) return
    try {
        stock.value = await fetchPartStock(props.part.id)
    } catch {
        stock.value = { part: null, locations: [], moves: [] }
    }
}

function openBooking() {
    bookingOpen.value = true
}

function onBooked() {
    load()
    emit('booked')
}

onMounted(load)
watch(() => props.part?.id, load)

defineExpose({ reload: load })
</script>

<style scoped>
.oserp-part-stock {
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius-sm);
}

.oserp-part-stock__empty {
    color: var(--oserp-text-muted);
}
</style>
