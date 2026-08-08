<!-- src/features/warehouse/views/warehouse.hub.vue -->
<!--
    Lager-Cockpit. Bewusst kein Nachbau der klassischen Lagermasken:
    - Es gibt keine getrennten Masken fuer Ein-, Aus- und Umlagern, sondern
      einen Buchungsdialog und einen Scanner-Modus.
    - Der Bestand ist eine Suchtrefferliste statt eines Filterformulars; jede
      Zeile laesst sich zur Platz- und Chargenverteilung aufklappen.
    - Die Kennzahlen oben sind Filter: ein Klick zeigt genau die Artikel, die
      dahinterstehen.
-->
<template>
    <NavbarView />
    <v-container fluid class="pt-3">

        <!-- ── Einstieg, solange kein Lager existiert ────────────────────── -->
        <v-row v-if="!loading && warehouses.length === 0">
            <v-col cols="12" md="8" offset-md="2">
                <v-card variant="tonal" color="primary" rounded="lg" class="pa-2">
                    <v-card-text class="text-center py-8">
                        <v-icon size="64" color="primary" class="mb-4">mdi-warehouse</v-icon>
                        <h2 class="text-h5 mb-2">{{ t('WarehouseView.onboarding.title') }}</h2>
                        <p class="text-body-2 text-medium-emphasis mb-6" style="max-width: 46ch; margin: 0 auto;">
                            {{ t('WarehouseView.onboarding.text') }}
                        </p>
                        <v-row justify="center" dense class="mb-4">
                            <v-col cols="12" sm="5">
                                <v-text-field v-model="onboarding.warehouse" :label="t('WarehouseView.warehouse')"
                                              variant="outlined" density="compact" hide-details />
                            </v-col>
                            <v-col cols="12" sm="5">
                                <v-text-field v-model="onboarding.bin" :label="t('WarehouseView.bin')"
                                              variant="outlined" density="compact" hide-details />
                            </v-col>
                        </v-row>
                        <v-btn color="primary" variant="flat" size="large" :loading="loading" @click="setupWarehouse">
                            <v-icon start>mdi-check</v-icon>
                            {{ t('WarehouseView.onboarding.create') }}
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <template v-else>
            <!-- ── Kopfzeile ─────────────────────────────────────────────── -->
            <div class="d-flex align-center flex-wrap ga-2 mb-3">
                <v-icon color="primary" class="mr-1">mdi-warehouse</v-icon>
                <h1 class="text-h6 mb-0">{{ t('WarehouseView.title') }}</h1>
                <v-spacer />
                <v-btn color="primary" variant="flat" size="small" @click="openBooking('in')">
                    <v-icon start size="small">mdi-plus</v-icon>
                    {{ t('WarehouseView.booking.title') }}
                </v-btn>
                <v-btn color="primary" variant="tonal" size="small" :to="{ name: 'warehouse-scanner' }">
                    <v-icon start size="small">mdi-barcode-scan</v-icon>
                    {{ t('WarehouseView.scanner.title') }}
                </v-btn>
            </div>

            <!-- ── Kennzahlen als Filter ─────────────────────────────────── -->
            <v-row dense class="mb-1">
                <v-col v-for="card in kpiCards" :key="card.key" cols="6" md="3">
                    <!-- Hervorgehoben nur, wenn wirklich gefiltert wird — sonst
                         saehe die Standardansicht dauerhaft nach aktivem Filter aus. -->
                    <v-card
                        :variant="isActive(card) ? 'flat' : 'tonal'"
                        :color="isActive(card) ? card.color : undefined"
                        rounded="lg"
                        :class="card.filter ? 'cursor-pointer' : ''"
                        @click="card.filter && applyFilter(card.filter)"
                    >
                        <v-card-text class="py-3">
                            <div class="d-flex align-center ga-2">
                                <v-icon :color="isActive(card) ? undefined : card.color" size="20">{{ card.icon }}</v-icon>
                                <span class="text-caption">{{ card.label }}</span>
                            </div>
                            <div class="text-h6 font-weight-bold mt-1">{{ card.value }}</div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Hinweis auf abweichende Schnellbestaende -->
            <v-alert
                v-if="drift > 0"
                type="warning" variant="tonal" density="compact" class="mb-2"
                :title="t('WarehouseView.drift.title')"
            >
                <div class="d-flex align-center flex-wrap ga-3">
                    <span class="text-body-2">{{ t('WarehouseView.drift.text', { count: drift }) }}</span>
                    <v-btn size="x-small" variant="flat" color="warning" @click="fixDrift">
                        {{ t('WarehouseView.drift.fix') }}
                    </v-btn>
                </div>
            </v-alert>

            <!-- ── Reiter ────────────────────────────────────────────────── -->
            <v-tabs v-model="tab" density="compact" color="primary" class="mb-2">
                <v-tab value="stock"><v-icon start size="small">mdi-package-variant</v-icon>{{ t('WarehouseView.tabs.stock') }}</v-tab>
                <v-tab value="journal"><v-icon start size="small">mdi-history</v-icon>{{ t('WarehouseView.tabs.journal') }}</v-tab>
                <v-tab value="stocktaking"><v-icon start size="small">mdi-clipboard-list</v-icon>{{ t('WarehouseView.tabs.stocktaking') }}</v-tab>
                <v-tab value="master"><v-icon start size="small">mdi-cog</v-icon>{{ t('WarehouseView.tabs.master') }}</v-tab>
            </v-tabs>

            <v-window v-model="tab">
                <!-- ── Bestand ───────────────────────────────────────────── -->
                <v-window-item value="stock">
                    <div class="d-flex align-center flex-wrap ga-2 mb-2 pt-2">
                        <v-text-field
                            v-model="search"
                            :placeholder="t('WarehouseView.stock.searchHint')"
                            prepend-inner-icon="mdi-magnify"
                            variant="outlined" density="compact" hide-details clearable autofocus
                            style="max-width: 420px;"
                        />
                        <v-select
                            v-model="warehouseFilter"
                            :items="[{ id: 0, description: t('WarehouseView.stock.allWarehouses') }, ...warehouses]"
                            item-title="description" item-value="id"
                            variant="outlined" density="compact" hide-details
                            style="max-width: 240px;"
                        />
                        <v-chip v-if="filter !== 'all'" closable color="primary" size="small" @click:close="applyFilter('all')">
                            {{ t(`WarehouseView.stock.filters.${filter}`) }}
                        </v-chip>
                        <v-spacer />
                        <span class="text-caption text-medium-emphasis">
                            {{ t('WarehouseView.stock.count', { shown: stock.items?.length || 0, total: stock.total || 0 }) }}
                        </span>
                    </div>

                    <v-data-table
                        :headers="stockHeaders"
                        :items="stock.items || []"
                        :loading="loading"
                        density="compact"
                        item-value="id"
                        show-expand
                        :items-per-page="50"
                        hover
                        :no-data-text="t('WarehouseView.stock.empty')"
                    >
                        <template #item.partnumber="{ item }">
                            <span class="font-weight-medium">{{ item.partnumber }}</span>
                        </template>
                        <template #item.qty="{ item }">
                            <span :class="item.below_rop ? 'text-error font-weight-bold' : 'font-weight-medium'">
                                {{ num(item.qty) }} {{ item.unit }}
                            </span>
                            <v-tooltip v-if="item.below_rop" location="top"
                                       :text="t('WarehouseView.stock.ropReached', { rop: num(item.rop) })">
                                <template #activator="{ props: p }">
                                    <v-icon v-bind="p" size="x-small" color="error" class="ml-1">mdi-alert-circle</v-icon>
                                </template>
                            </v-tooltip>
                        </template>
                        <template #item.value="{ item }">{{ money(item.value) }}</template>
                        <template #item.last_move="{ item }">
                            <span class="text-caption">{{ item.last_move ? dt(item.last_move) : '—' }}</span>
                        </template>
                        <template #item.actions="{ item }">
                            <v-btn icon variant="text" size="x-small" :title="t('WarehouseView.booking.title')"
                                   @click.stop="openBooking('in', item)">
                                <v-icon size="small">mdi-swap-vertical</v-icon>
                            </v-btn>
                            <v-btn icon variant="text" size="x-small" :title="t('WarehouseView.stock.setRop')"
                                   @click.stop="askRop(item)">
                                <v-icon size="small">mdi-bell-outline</v-icon>
                            </v-btn>
                        </template>

                        <!-- Aufgeklappt: Verteilung auf Lagerplaetze und Chargen -->
                        <template #expanded-row="{ columns, item }">
                            <tr>
                                <td :colspan="columns.length" class="pa-0">
                                    <v-table density="compact" class="sub-table">
                                        <thead>
                                            <tr>
                                                <th class="text-caption">{{ t('WarehouseView.warehouse') }}</th>
                                                <th class="text-caption">{{ t('WarehouseView.bin') }}</th>
                                                <th class="text-caption">{{ t('WarehouseView.booking.charge') }}</th>
                                                <th class="text-caption text-right">{{ t('WarehouseView.booking.qty') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(loc, i) in item.locations" :key="i">
                                                <td>{{ loc.warehouse }}</td>
                                                <td>{{ loc.bin }}</td>
                                                <td>{{ loc.chargenumber || '—' }}</td>
                                                <td class="text-right">{{ num(loc.qty) }} {{ item.unit }}</td>
                                            </tr>
                                            <tr v-if="!item.locations?.length">
                                                <td colspan="4" class="text-caption text-medium-emphasis">
                                                    {{ t('WarehouseView.stock.noLocations') }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </td>
                            </tr>
                        </template>
                    </v-data-table>
                </v-window-item>

                <!-- ── Bewegungen ────────────────────────────────────────── -->
                <v-window-item value="journal">
                    <div class="d-flex align-center flex-wrap ga-2 mb-2 pt-2">
                        <v-text-field v-model="journalFilter.date_from" type="date" :label="t('WarehouseView.journal.from')"
                                      variant="outlined" density="compact" hide-details style="max-width: 170px;" />
                        <v-text-field v-model="journalFilter.date_to" type="date" :label="t('WarehouseView.journal.to')"
                                      variant="outlined" density="compact" hide-details style="max-width: 170px;" />
                        <v-select v-model="journalFilter.direction"
                                  :items="directionItems" item-title="label" item-value="value"
                                  :label="t('WarehouseView.journal.direction')"
                                  variant="outlined" density="compact" hide-details style="max-width: 190px;" />
                        <v-text-field v-model="journalFilter.search" :placeholder="t('WarehouseView.journal.searchHint')"
                                      prepend-inner-icon="mdi-magnify"
                                      variant="outlined" density="compact" hide-details clearable style="max-width: 280px;" />
                        <v-spacer />
                        <v-chip size="small" variant="tonal" color="success">+{{ num(journal.qty_in) }}</v-chip>
                        <v-chip size="small" variant="tonal" color="warning">{{ num(journal.qty_out) }}</v-chip>
                    </div>

                    <v-data-table
                        :headers="journalHeaders"
                        :items="journal.items || []"
                        :loading="loading"
                        density="compact"
                        :items-per-page="50"
                        :no-data-text="t('WarehouseView.journal.empty')"
                    >
                        <template #item.shippingdate="{ item }">{{ d(item.shippingdate) }}</template>
                        <template #item.direction="{ item }">
                            <v-chip size="x-small" variant="tonal" :color="dirColor(item.direction)">
                                {{ t(`WarehouseView.transferTypes.${item.transfer_type}`) }}
                            </v-chip>
                        </template>
                        <template #item.qty="{ item }">
                            <span :class="Number(item.qty) < 0 ? 'text-warning' : 'text-success'">
                                {{ Number(item.qty) > 0 ? '+' : '' }}{{ num(item.qty) }} {{ item.unit }}
                            </span>
                        </template>
                        <template #item.actions="{ item }">
                            <v-btn v-if="!item.from_record" icon variant="text" size="x-small"
                                   :title="t('WarehouseView.journal.undo')" @click="undo(item)">
                                <v-icon size="small">mdi-undo</v-icon>
                            </v-btn>
                            <v-tooltip v-else location="top" :text="t('WarehouseView.journal.fromRecord')">
                                <template #activator="{ props: p }">
                                    <v-icon v-bind="p" size="small" color="grey">mdi-file-document-outline</v-icon>
                                </template>
                            </v-tooltip>
                        </template>
                    </v-data-table>
                </v-window-item>

                <!-- ── Inventur ──────────────────────────────────────────── -->
                <v-window-item value="stocktaking">
                    <div class="d-flex align-center flex-wrap ga-2 mb-3">
                        <v-btn color="primary" variant="flat" size="small" @click="newSessionDialog = true">
                            <v-icon start size="small">mdi-plus</v-icon>
                            {{ t('WarehouseView.stocktaking.new') }}
                        </v-btn>
                    </div>

                    <v-row dense>
                        <v-col v-for="s in sessions" :key="s.id" cols="12" md="6" lg="4">
                            <v-card variant="outlined" rounded="lg">
                                <v-card-text>
                                    <div class="d-flex align-center ga-2 mb-1">
                                        <v-chip size="x-small" :color="sessionColor(s.status)" variant="flat">
                                            {{ t(`WarehouseView.stocktaking.status.${s.status}`) }}
                                        </v-chip>
                                        <span class="text-caption text-medium-emphasis">{{ d(s.cutoff_date) }}</span>
                                    </div>
                                    <div class="text-subtitle-2 font-weight-bold">{{ s.name }}</div>
                                    <div class="text-caption text-medium-emphasis mb-2">{{ s.warehouse }}</div>
                                    <v-progress-linear
                                        :model-value="s.expected > 0 ? (s.counted / s.expected) * 100 : 0"
                                        color="primary" height="6" rounded class="mb-1"
                                    />
                                    <div class="text-caption">
                                        {{ t('WarehouseView.stocktaking.progress', { counted: s.counted, expected: s.expected }) }}
                                    </div>
                                </v-card-text>
                                <v-card-actions>
                                    <v-btn size="small" variant="text" color="primary"
                                           :to="{ name: 'warehouse-stocktaking', params: { id: s.id } }">
                                        {{ s.status === 'open' ? t('WarehouseView.stocktaking.count') : t('WarehouseView.stocktaking.show') }}
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-col>
                        <v-col v-if="!sessions.length" cols="12">
                            <p class="text-body-2 text-medium-emphasis">{{ t('WarehouseView.stocktaking.empty') }}</p>
                        </v-col>
                    </v-row>
                </v-window-item>

                <!-- ── Lager & Plaetze ───────────────────────────────────── -->
                <v-window-item value="master">
                    <v-row dense>
                        <v-col v-for="w in overviewWarehouses" :key="w.id" cols="12" md="6">
                            <v-card variant="outlined" rounded="lg">
                                <v-card-title class="d-flex align-center pa-3 pb-1">
                                    <v-icon size="small" class="mr-2" :color="w.invalid ? 'grey' : 'primary'">mdi-warehouse</v-icon>
                                    <span class="text-subtitle-2 font-weight-bold">{{ w.description }}</span>
                                    <v-chip v-if="w.invalid" size="x-small" class="ml-2" variant="tonal">
                                        {{ t('WarehouseView.master.inactive') }}
                                    </v-chip>
                                    <v-spacer />
                                    <span class="text-caption text-medium-emphasis">
                                        {{ t('WarehouseView.master.summary', { parts: w.parts_count, qty: num(w.qty) }) }}
                                    </span>
                                </v-card-title>
                                <v-divider />
                                <v-list density="compact">
                                    <v-list-item v-for="b in w.bins" :key="b.id">
                                        <template #prepend>
                                            <v-icon size="small">mdi-cube-outline</v-icon>
                                        </template>
                                        <v-list-item-title class="text-body-2">{{ b.description }}</v-list-item-title>
                                        <template #append>
                                            <span class="text-caption text-medium-emphasis mr-2">{{ num(b.qty) }}</span>
                                            <v-btn v-if="Number(b.qty) === 0 && b.parts_count === 0"
                                                   icon variant="text" size="x-small" @click="removeBin(b)">
                                                <v-icon size="small">mdi-delete-outline</v-icon>
                                            </v-btn>
                                        </template>
                                    </v-list-item>
                                    <v-list-item>
                                        <v-text-field
                                            :model-value="newBin[w.id] || ''"
                                            :placeholder="t('WarehouseView.master.newBin')"
                                            variant="plain" density="compact" hide-details
                                            append-inner-icon="mdi-plus"
                                            @update:model-value="v => newBin[w.id] = v"
                                            @click:append-inner="addBin(w)"
                                            @keyup.enter="addBin(w)"
                                        />
                                    </v-list-item>
                                </v-list>
                            </v-card>
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-card variant="tonal" rounded="lg">
                                <v-card-text class="d-flex align-center ga-2">
                                    <v-text-field
                                        v-model="newWarehouse"
                                        :placeholder="t('WarehouseView.master.newWarehouse')"
                                        variant="outlined" density="compact" hide-details
                                        @keyup.enter="addWarehouse"
                                    />
                                    <v-btn color="primary" variant="flat" @click="addWarehouse">
                                        <v-icon>mdi-plus</v-icon>
                                    </v-btn>
                                </v-card-text>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-window-item>
            </v-window>
        </template>

        <!-- Buchungsdialog -->
        <StockBookingDialog
            v-model="bookingOpen"
            :warehouses="warehouses"
            :transfer-types="transferTypes"
            :show-bestbefore="showBestbefore"
            :preset-part="presetPart"
            :preset-direction="presetDirection"
            @booked="reloadAll"
        />

        <!-- Neue Inventur -->
        <v-dialog v-model="newSessionDialog" max-width="480">
            <v-card rounded="lg">
                <v-card-title class="text-subtitle-1 font-weight-bold">
                    {{ t('WarehouseView.stocktaking.new') }}
                </v-card-title>
                <v-card-text>
                    <v-text-field v-model="newSession.name" :label="t('WarehouseView.stocktaking.name')"
                                  variant="outlined" density="compact" class="mb-2" />
                    <v-select v-model="newSession.warehouse_id" :items="warehouses"
                              item-title="description" item-value="id"
                              :label="t('WarehouseView.warehouse')" variant="outlined" density="compact" class="mb-2" />
                    <v-text-field v-model="newSession.cutoff_date" type="date"
                                  :label="t('WarehouseView.stocktaking.cutoff')"
                                  :hint="t('WarehouseView.stocktaking.cutoffHint')" persistent-hint
                                  variant="outlined" density="compact" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="newSessionDialog = false">{{ t('WarehouseView.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" @click="createSessionAndGo">{{ t('WarehouseView.stocktaking.start') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import StockBookingDialog from '../components/stock-booking.dialog.vue'
import { useWarehouse, useStocktaking } from '../composables/useWarehouse.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import { formatDate, formatDateTime } from '@/core/utils/dateFormatter.js'
import * as alerts from '@/core/utils/alerts.js'
import Swal from 'sweetalert2'

const { t, locale } = useI18n()
const router = useRouter()
const wh = useWarehouse()
const st = useStocktaking()

const loading = computed(() => wh.loading.value || st.loading.value)

const tab = ref('stock')
const filter = ref('all')
const search = ref('')
const warehouseFilter = ref(0)

const overview = ref({ kpi: {}, warehouses: [], moves: [] })
const drift = ref(0)
const options = ref({ warehouses: [], transfer_types: [], defaults: {} })
const stock = ref({ items: [], total: 0, sum_value: 0 })
const journal = ref({ items: [], qty_in: 0, qty_out: 0 })
const sessions = ref([])

const bookingOpen = ref(false)
const presetPart = ref(null)
const presetDirection = ref('in')

const newWarehouse = ref('')
const newBin = reactive({})
const onboarding = reactive({ warehouse: 'Hauptlager', bin: 'Standard' })

const newSessionDialog = ref(false)
const newSession = reactive({
    name: '',
    warehouse_id: null,
    cutoff_date: new Date().toISOString().slice(0, 10),
})

const journalFilter = reactive({
    date_from: firstOfMonth(),
    date_to: new Date().toISOString().slice(0, 10),
    direction: '',
    search: '',
})

const warehouses = computed(() => options.value.warehouses || [])
const overviewWarehouses = computed(() => overview.value.warehouses || [])
const transferTypes = computed(() => options.value.transfer_types || [])
const showBestbefore = computed(() => !!options.value.defaults?.show_bestbefore)

const directionItems = computed(() => [
    { value: '',         label: t('WarehouseView.journal.allDirections') },
    { value: 'in',       label: t('WarehouseView.booking.in') },
    { value: 'out',      label: t('WarehouseView.booking.out') },
    { value: 'transfer', label: t('WarehouseView.booking.transfer') },
])

const kpiCards = computed(() => {
    const k = overview.value.kpi || {}
    return [
        { key: 'parts', filter: 'all',       icon: 'mdi-package-variant-closed', color: 'primary',
          label: t('WarehouseView.kpi.partsInStock'), value: k.parts_in_stock ?? 0 },
        { key: 'value', filter: null,        icon: 'mdi-cash-multiple',          color: 'success',
          label: t('WarehouseView.kpi.stockValue'),   value: money(k.stock_value) },
        { key: 'rop',   filter: 'below_rop', icon: 'mdi-bell-alert-outline',     color: 'error',
          label: t('WarehouseView.kpi.belowRop'),     value: k.below_rop ?? 0 },
        { key: 'dead',  filter: 'dead',      icon: 'mdi-snowflake',              color: 'info',
          label: t('WarehouseView.kpi.deadStock'),    value: k.dead_stock ?? 0 },
    ]
})

const stockHeaders = computed(() => [
    { title: t('WarehouseView.stock.partnumber'),  key: 'partnumber',  width: '140px' },
    { title: t('WarehouseView.stock.description'), key: 'description' },
    { title: t('WarehouseView.stock.qty'),         key: 'qty',         align: 'end', width: '160px' },
    { title: t('WarehouseView.stock.value'),       key: 'value',       align: 'end', width: '120px' },
    { title: t('WarehouseView.stock.lastMove'),    key: 'last_move',   width: '140px' },
    { title: '',                                    key: 'actions',     width: '90px', sortable: false },
])

const journalHeaders = computed(() => [
    { title: t('WarehouseView.journal.date'),      key: 'shippingdate', width: '110px' },
    { title: t('WarehouseView.stock.partnumber'),  key: 'partnumber',   width: '130px' },
    { title: t('WarehouseView.stock.description'), key: 'part_description' },
    { title: t('WarehouseView.journal.type'),      key: 'direction',    width: '150px' },
    { title: t('WarehouseView.warehouse'),         key: 'warehouse',    width: '130px' },
    { title: t('WarehouseView.bin'),               key: 'bin',          width: '120px' },
    { title: t('WarehouseView.booking.qty'),       key: 'qty',          align: 'end', width: '120px' },
    { title: t('WarehouseView.journal.comment'),   key: 'comment' },
    { title: '',                                    key: 'actions',      width: '50px', sortable: false },
])

// ── Formatierung ────────────────────────────────────────────────────────────
function num(v)   { return formatNumber(Number(v ?? 0), locale.value, Number(v) % 1 === 0 ? 0 : 2) }
function money(v) { return formatNumber(Number(v ?? 0), locale.value, 2) + ' €' }
function d(v)     { return v ? formatDate(v, locale.value) : '' }
function dt(v)    { return v ? formatDateTime(v, locale.value) : '' }
function firstOfMonth() {
    const n = new Date()
    return new Date(n.getFullYear(), n.getMonth(), 1).toISOString().slice(0, 10)
}
function dirColor(dir) { return { in: 'success', out: 'warning', transfer: 'info' }[dir] || 'grey' }
function sessionColor(s) { return { open: 'primary', posted: 'success', cancelled: 'grey' }[s] || 'grey' }

// ── Laden ───────────────────────────────────────────────────────────────────
async function loadOverview() {
    const res = await wh.fetchOverview()
    overview.value = res
    drift.value = res.drift || 0
}
async function loadOptions() {
    options.value = await wh.fetchOptions()
    if (!newSession.warehouse_id) newSession.warehouse_id = options.value.warehouses?.[0]?.id ?? null
}
async function loadStock() {
    stock.value = await wh.fetchStock({
        search: search.value || '',
        warehouse_id: warehouseFilter.value || 0,
        filter: filter.value,
        limit: 200,
    })
}
async function loadJournal() { journal.value = await wh.fetchJournal({ ...journalFilter, limit: 200 }) }
async function loadSessions() { sessions.value = await st.fetchSessions() }

async function reloadAll() {
    await Promise.all([loadOverview(), loadStock()])
    if (tab.value === 'journal') await loadJournal()
}

onMounted(async () => {
    await loadOptions()
    await Promise.all([loadOverview(), loadStock(), loadSessions()])
})

// Sofortsuche mit kurzer Verzoegerung
let searchTimer = null
watch([search, warehouseFilter, filter], () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(loadStock, 250)
})

let journalTimer = null
watch(journalFilter, () => {
    if (journalTimer) clearTimeout(journalTimer)
    journalTimer = setTimeout(loadJournal, 250)
}, { deep: true })

watch(tab, (v) => {
    if (v === 'journal') loadJournal()
    if (v === 'stocktaking') loadSessions()
})

// ── Aktionen ────────────────────────────────────────────────────────────────
function applyFilter(f) { filter.value = f; tab.value = 'stock' }

/** "Alle" ist der Ausgangszustand und wird nicht als aktiver Filter markiert. */
function isActive(card) { return !!card.filter && card.filter !== 'all' && filter.value === card.filter }

function openBooking(direction, item = null) {
    presetDirection.value = direction
    presetPart.value = item
    bookingOpen.value = true
}

async function setupWarehouse() {
    try {
        await wh.createDefault(onboarding.warehouse, onboarding.bin)
        await loadOptions()
        await loadOverview()
        alerts.success(t('WarehouseView.onboarding.done'))
    } catch (e) { alerts.error(e.message) }
}

async function addWarehouse() {
    if (!newWarehouse.value.trim()) return
    await wh.saveWarehouse({ description: newWarehouse.value.trim() })
    newWarehouse.value = ''
    await Promise.all([loadOptions(), loadOverview()])
}

async function addBin(w) {
    const name = (newBin[w.id] || '').trim()
    if (!name) return
    await wh.saveBin({ warehouse_id: w.id, description: name })
    newBin[w.id] = ''
    await Promise.all([loadOptions(), loadOverview()])
}

async function removeBin(b) {
    try {
        await wh.deleteBin(b.id)
        await Promise.all([loadOptions(), loadOverview()])
    } catch (e) { alerts.error(e.message) }
}

async function fixDrift() {
    const res = await wh.recalcOnhand(0)
    alerts.success(t('WarehouseView.drift.done', { count: res.fixed }))
    await loadOverview()
}

async function askRop(item) {
    const { value } = await Swal.fire({
        title: t('WarehouseView.stock.setRop'),
        text: `${item.partnumber} — ${item.description}`,
        input: 'number',
        inputValue: item.rop || 0,
        showCancelButton: true,
        confirmButtonText: t('WarehouseView.save'),
        cancelButtonText: t('WarehouseView.cancel'),
    })
    if (value === undefined) return
    await wh.setRop(item.id, Number(value))
    await Promise.all([loadStock(), loadOverview()])
}

async function undo(item) {
    const ok = await alerts.question(t('WarehouseView.journal.undoConfirm'))
    if (!ok.isConfirmed) return
    try {
        await wh.undoTransfer(item.trans_id)
        alerts.success(t('WarehouseView.journal.undone'))
        await Promise.all([loadJournal(), loadStock(), loadOverview()])
    } catch (e) {
        if (e.code === 'UNDO_EXPIRED') {
            alerts.error(t('WarehouseView.journal.undoExpired', { days: e.payload?.undo_days ?? 0 }))
        } else {
            alerts.error(e.message)
        }
    }
}

async function createSessionAndGo() {
    const res = await st.createSession({ ...newSession })
    newSessionDialog.value = false
    router.push({ name: 'warehouse-stocktaking', params: { id: res.id } })
}
</script>

<style scoped>
.cursor-pointer { cursor: pointer; }

/* Aufgeklappte Lagerplatzverteilung: leicht getoent statt eigener Flaechenfarbe,
   damit sie als Teil der Zeile lesbar bleibt — in hellem wie dunklem Design. */
.sub-table {
    background: rgba(var(--v-theme-primary), .05);
}
.sub-table :deep(th) {
    font-weight: 600;
    opacity: .75;
}
</style>
