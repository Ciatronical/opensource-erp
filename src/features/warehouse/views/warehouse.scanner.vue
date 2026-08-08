<!-- src/features/warehouse/views/warehouse.scanner.vue -->
<!--
    Scanner-Modus fuer Lager und Werkstatt.

    Gedacht fuer Tablet oder Handscanner: grosse Ziele, keine Maus noetig, das
    Eingabefeld hat immer den Fokus. Ein Handscanner tippt den Code und sendet
    Enter — dadurch laeuft der ganze Vorgang ohne Beruehrung des Bildschirms:
    scannen → Menge → Enter → naechster Artikel.
-->
<template>
    <div class="scanner">
        <!-- Kopf -->
        <div class="scanner__bar">
            <v-btn icon variant="text" color="white" size="small" :to="{ name: 'warehouse' }">
                <v-icon>mdi-arrow-left</v-icon>
            </v-btn>
            <v-icon color="white" class="mr-2">mdi-barcode-scan</v-icon>
            <span class="text-subtitle-1 font-weight-bold text-white">{{ t('WarehouseView.scanner.title') }}</span>
            <v-spacer />
            <v-select
                v-model="binId"
                :items="binOptions"
                item-title="label"
                item-value="id"
                :label="t('WarehouseView.bin')"
                variant="solo-filled"
                density="compact"
                hide-details
                style="max-width: 260px;"
                bg-color="rgba(255,255,255,.12)"
                class="scanner__select"
            />
        </div>

        <!-- Richtung -->
        <v-btn-toggle v-model="direction" mandatory divided variant="outlined"
                      class="scanner__dir" density="comfortable">
            <v-btn value="in" size="large" class="flex-grow-1">
                <v-icon start>mdi-tray-arrow-down</v-icon>{{ t('WarehouseView.booking.in') }}
            </v-btn>
            <v-btn value="out" size="large" class="flex-grow-1">
                <v-icon start>mdi-tray-arrow-up</v-icon>{{ t('WarehouseView.booking.out') }}
            </v-btn>
            <v-btn value="transfer" size="large" class="flex-grow-1">
                <v-icon start>mdi-swap-horizontal</v-icon>{{ t('WarehouseView.booking.transfer') }}
            </v-btn>
        </v-btn-toggle>

        <!-- Ziel beim Umlagern -->
        <v-select
            v-if="direction === 'transfer'"
            v-model="targetBinId"
            :items="binOptions.filter(b => b.id !== binId)"
            item-title="label"
            item-value="id"
            :label="t('WarehouseView.scanner.targetBin')"
            variant="solo-filled"
            density="comfortable"
            hide-details
            class="scanner__target"
            bg-color="rgba(255,255,255,.12)"
        />

        <!-- Schritt 1: Code -->
        <div v-if="!selected" class="scanner__stage">
            <v-text-field
                ref="codeField"
                v-model="code"
                :placeholder="t('WarehouseView.scanner.codeHint')"
                prepend-inner-icon="mdi-magnify"
                variant="solo"
                density="comfortable"
                hide-details
                autofocus
                class="scanner__input"
                @keyup.enter="resolve"
            />
            <div class="scanner__help mt-2">{{ t('WarehouseView.scanner.codeHelp') }}</div>

            <!-- Trefferliste, wenn der Code nicht eindeutig war -->
            <v-list v-if="candidates.length" class="scanner__list mt-4" bg-color="transparent">
                <v-list-item
                    v-for="p in candidates" :key="p.id"
                    class="scanner__item mb-2" rounded="lg"
                    @click="choose(p)"
                >
                    <v-list-item-title class="font-weight-bold">{{ p.partnumber }}</v-list-item-title>
                    <v-list-item-subtitle>{{ p.description }}</v-list-item-subtitle>
                    <template #append>
                        <v-chip size="small" variant="tonal">{{ num(p.qty) }} {{ p.unit }}</v-chip>
                    </template>
                </v-list-item>
            </v-list>
        </div>

        <!-- Schritt 2: Menge -->
        <div v-else class="scanner__stage">
            <v-card rounded="lg" class="mb-4">
                <v-card-text class="d-flex align-center">
                    <div class="flex-grow-1">
                        <div class="text-h6 font-weight-bold">{{ selected.partnumber }}</div>
                        <div class="text-body-2 text-medium-emphasis">{{ selected.description }}</div>
                        <div class="text-caption mt-1">
                            {{ t('WarehouseView.scanner.currentStock', { qty: num(selected.qty), unit: selected.unit }) }}
                        </div>
                    </div>
                    <v-btn icon variant="text" @click="reset">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-text>
            </v-card>

            <v-text-field
                ref="qtyField"
                v-model="qty"
                type="number"
                min="0"
                step="any"
                :suffix="selected.unit"
                variant="solo"
                density="comfortable"
                hide-details
                class="scanner__input scanner__qty"
                autofocus
                @keyup.enter="book"
            />

            <div class="d-flex ga-2 mt-3 flex-wrap justify-center">
                <v-btn v-for="q in [1, 2, 5, 10, 20]" :key="q" variant="tonal" size="large" @click="qty = q">
                    {{ q }}
                </v-btn>
            </div>

            <v-btn color="primary" size="x-large" block class="mt-4" :loading="busy"
                   :disabled="!(Number(qty) > 0)" @click="book">
                <v-icon start>mdi-check</v-icon>
                {{ t('WarehouseView.booking.book') }}
            </v-btn>
        </div>

        <!-- Letzte Buchungen -->
        <div v-if="recent.length" class="scanner__recent">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('WarehouseView.scanner.recent') }}</div>
            <div v-for="r in recent" :key="r.trans_id" class="scanner__recent-item">
                <v-icon size="small" :color="r.direction === 'out' ? 'warning' : 'success'" class="mr-2">
                    {{ r.direction === 'out' ? 'mdi-tray-arrow-up' : 'mdi-tray-arrow-down' }}
                </v-icon>
                <span class="flex-grow-1 text-truncate">{{ r.partnumber }} · {{ r.description }}</span>
                <span class="font-weight-bold mr-3">{{ r.direction === 'out' ? '−' : '+' }}{{ num(r.qty) }}</span>
                <v-btn icon variant="text" size="x-small" @click="undoRecent(r)">
                    <v-icon size="small">mdi-undo</v-icon>
                </v-btn>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWarehouse } from '../composables/useWarehouse.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import * as toasts from '@/core/utils/toasts.js'

const { t, locale } = useI18n()
const { fetchOptions, lookupCode, bookStock, undoTransfer } = useWarehouse()

const options = ref({ warehouses: [] })
const binId = ref(null)
const targetBinId = ref(null)
const direction = ref('in')

const code = ref('')
const candidates = ref([])
const selected = ref(null)
const qty = ref(null)
const busy = ref(false)
const recent = ref([])

const codeField = ref(null)
const qtyField = ref(null)

// Lagerplaetze als flache Liste "Lager · Platz" — im Scanner-Modus zaehlt
// Geschwindigkeit, nicht die Hierarchie.
const binOptions = computed(() =>
    (options.value.warehouses || []).flatMap(w =>
        (w.bins || []).map(b => ({
            id: b.id,
            warehouse_id: w.id,
            label: `${w.description} · ${b.description}`,
        }))
    )
)

function num(v) { return formatNumber(Number(v ?? 0), locale.value, Number(v) % 1 === 0 ? 0 : 2) }

onMounted(async () => {
    options.value = await fetchOptions()
    const def = options.value.defaults?.bin_id
    binId.value = binOptions.value.find(b => b.id === def)?.id ?? binOptions.value[0]?.id ?? null
    targetBinId.value = binOptions.value.find(b => b.id !== binId.value)?.id ?? null
})

// Nach jedem Schritt zurueck in das passende Feld — der Scanner soll nie
// ins Leere tippen.
watch(selected, async (v) => {
    await nextTick()
    if (v) qtyField.value?.focus?.()
    else codeField.value?.focus?.()
})

async function resolve() {
    const value = code.value.trim()
    if (!value) return
    const res = await lookupCode(value)
    const items = res.items || []
    if (!items.length) {
        toasts.error(t('WarehouseView.scanner.notFound', { code: value }))
        code.value = ''
        return
    }
    if (res.exact && items.length >= 1) { choose(items[0]); return }
    if (items.length === 1) { choose(items[0]); return }
    candidates.value = items
}

function choose(part) {
    selected.value = part
    candidates.value = []
    qty.value = 1
}

function reset() {
    selected.value = null
    candidates.value = []
    code.value = ''
    qty.value = null
}

async function book() {
    if (!(Number(qty.value) > 0) || !binId.value) return
    const src = binOptions.value.find(b => b.id === binId.value)
    const tgt = binOptions.value.find(b => b.id === targetBinId.value)
    if (direction.value === 'transfer' && !tgt) {
        toasts.error(t('WarehouseView.scanner.noTarget'))
        return
    }

    busy.value = true
    try {
        const res = await bookStock({
            direction: direction.value,
            parts_id: selected.value.id,
            qty: Number(qty.value),
            warehouse_id: src.warehouse_id,
            bin_id: src.id,
            target_warehouse_id: tgt?.warehouse_id,
            target_bin_id: tgt?.id,
            comment: t('WarehouseView.scanner.comment'),
        })
        recent.value.unshift({
            trans_id: res.trans_id,
            partnumber: selected.value.partnumber,
            description: selected.value.description,
            qty: Number(qty.value),
            direction: direction.value,
        })
        recent.value = recent.value.slice(0, 8)
        toasts.success(t('WarehouseView.scanner.booked', {
            part: selected.value.partnumber, qty: num(qty.value), onhand: num(res.onhand),
        }))
        reset()
    } catch (e) {
        if (e.code === 'NOT_ENOUGH_STOCK') {
            toasts.error(t('WarehouseView.booking.notEnough', {
                available: e.payload?.available ?? 0, requested: e.payload?.requested ?? 0,
            }))
        } else {
            toasts.error(e.message)
        }
    } finally {
        busy.value = false
    }
}

async function undoRecent(r) {
    try {
        await undoTransfer(r.trans_id)
        recent.value = recent.value.filter(x => x.trans_id !== r.trans_id)
        toasts.success(t('WarehouseView.journal.undone'))
    } catch (e) {
        toasts.error(e.message)
    }
}
</script>

<style scoped>
.scanner {
    min-height: 100vh;
    background: linear-gradient(160deg, #1a237e 0%, #0d1333 100%);
    color: #fff;
    padding: 12px 16px 32px;
    display: flex;
    flex-direction: column;
}
.scanner__bar {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 16px;
}
.scanner__dir { width: 100%; margin-bottom: 12px; height: 52px; }
/* Der Umschalter steht auf dunklem Grund — Vuetify wuerde ihn hell auf hell
   zeichnen. Deshalb Rahmen und Text explizit weiss, der aktive Knopf invertiert. */
.scanner__dir :deep(.v-btn) {
    color: #fff;
    border-color: rgba(255, 255, 255, .45);
    background: rgba(255, 255, 255, .06);
    font-weight: 600;
}
.scanner__dir :deep(.v-btn--active) {
    background: #fff;
    color: #12194a;
}
.scanner__dir :deep(.v-btn--active .v-btn__overlay) { opacity: 0; }
.scanner__help { color: rgba(255, 255, 255, .75); font-size: .85rem; }
.scanner__target { margin-bottom: 12px; }
.scanner__stage {
    max-width: 620px;
    width: 100%;
    margin: 24px auto 0;
    text-align: center;
}
.scanner__input :deep(input) { font-size: 1.6rem; }
.scanner__qty :deep(input) { text-align: center; font-size: 2.4rem; font-weight: 700; }
.scanner__list { max-height: 46vh; overflow-y: auto; }
.scanner__item { background: rgba(255, 255, 255, .08); }
.scanner__recent {
    max-width: 620px;
    width: 100%;
    margin: 32px auto 0;
}
.scanner__recent-item {
    display: flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 8px;
    background: rgba(255, 255, 255, .06);
    margin-bottom: 4px;
    font-size: .9rem;
}
.scanner__select :deep(.v-field__input) { color: #fff; }
</style>
