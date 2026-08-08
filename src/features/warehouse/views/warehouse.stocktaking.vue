<!-- src/features/warehouse/views/warehouse.stocktaking.vue -->
<!--
    Inventur als gefuehrter Zaehlvorgang.

    Kernidee: BLIND zaehlen. Der Buchbestand wird erst eingeblendet, nachdem
    eine Menge eingetragen wurde — vorher ist er nicht einmal im Datenstrom
    enthalten. Wer die Sollmenge sieht, schreibt sie erfahrungsgemaess ab; genau
    das macht eine Inventur wertlos. Direkt nach der Eingabe zeigt die Zeile die
    Differenz, sodass ein Zaehlfehler sofort auffaellt und nachgezaehlt werden
    kann.
-->
<template>
    <NavbarView />
    <v-container fluid class="pt-3">

        <!-- Kopf -->
        <div class="d-flex align-center flex-wrap ga-2 mb-3">
            <v-btn icon variant="text" size="small" :to="{ name: 'warehouse' }">
                <v-icon>mdi-arrow-left</v-icon>
            </v-btn>
            <v-icon color="primary">mdi-clipboard-list</v-icon>
            <h1 class="text-h6 mb-0">{{ session?.name || t('WarehouseView.stocktaking.title') }}</h1>
            <v-chip size="small" variant="tonal">{{ session?.warehouse }}</v-chip>
            <v-chip size="small" variant="tonal">{{ d(session?.cutoff_date) }}</v-chip>
            <v-chip v-if="session" size="small" :color="statusColor" variant="flat">
                {{ t(`WarehouseView.stocktaking.status.${session.status}`) }}
            </v-chip>
            <v-spacer />
            <v-btn v-if="isOpen" variant="tonal" size="small" color="error" @click="cancelSessionAsk">
                {{ t('WarehouseView.stocktaking.cancel') }}
            </v-btn>
            <v-btn v-if="isOpen" color="primary" variant="flat" size="small" @click="showSummary = true">
                <v-icon start size="small">mdi-check-all</v-icon>
                {{ t('WarehouseView.stocktaking.finish') }}
            </v-btn>
        </div>

        <!-- Fortschritt -->
        <v-card variant="tonal" rounded="lg" class="mb-3">
            <v-card-text class="py-3">
                <div class="d-flex align-center flex-wrap ga-4 mb-2">
                    <div>
                        <div class="text-caption text-medium-emphasis">{{ t('WarehouseView.stocktaking.counted') }}</div>
                        <div class="text-h6 font-weight-bold">{{ list.counted }} / {{ list.total }}</div>
                    </div>
                    <div v-if="diffCount > 0">
                        <div class="text-caption text-medium-emphasis">{{ t('WarehouseView.stocktaking.diffs') }}</div>
                        <div class="text-h6 font-weight-bold text-warning">{{ diffCount }}</div>
                    </div>
                    <v-spacer />
                    <v-switch
                        v-model="blind"
                        color="primary" density="compact" hide-details inset
                        :label="t('WarehouseView.stocktaking.blind')"
                        :disabled="!isOpen"
                        @update:model-value="load"
                    />
                </div>
                <v-progress-linear
                    :model-value="list.total > 0 ? (list.counted / list.total) * 100 : 0"
                    color="primary" height="8" rounded
                />
            </v-card-text>
        </v-card>

        <v-alert v-if="blind && isOpen" type="info" variant="tonal" density="compact" class="mb-3"
                 :text="t('WarehouseView.stocktaking.blindHint')" />

        <!-- Suche / Scan -->
        <v-text-field
            v-model="search"
            :placeholder="t('WarehouseView.stocktaking.searchHint')"
            prepend-inner-icon="mdi-magnify"
            variant="outlined" density="compact" hide-details clearable autofocus
            class="mb-2" style="max-width: 460px;"
        />

        <!-- Zaehlliste -->
        <v-data-table
            :headers="headers"
            :items="list.items || []"
            :loading="loading"
            density="compact"
            :items-per-page="100"
            :no-data-text="t('WarehouseView.stocktaking.emptyList')"
        >
            <template #item.partnumber="{ item }">
                <span class="font-weight-medium">{{ item.partnumber }}</span>
            </template>

            <template #item.counted_qty="{ item }">
                <v-text-field
                    :model-value="item.counted_qty"
                    type="number" min="0" step="any"
                    variant="outlined" density="compact" hide-details
                    style="max-width: 130px;"
                    :disabled="!isOpen || item.posted"
                    :suffix="item.unit"
                    @update:model-value="v => stageCount(item, v)"
                    @blur="commitCount(item)"
                    @keyup.enter="commitCount(item)"
                />
            </template>

            <template #item.book_qty="{ item }">
                <span v-if="item.book_qty !== null" class="text-medium-emphasis">
                    {{ num(item.book_qty) }} {{ item.unit }}
                </span>
                <span v-else class="text-disabled">···</span>
            </template>

            <template #item.diff="{ item }">
                <v-chip v-if="item.diff !== null && Number(item.diff) !== 0"
                        size="small" variant="flat"
                        :color="Number(item.diff) > 0 ? 'success' : 'warning'">
                    {{ Number(item.diff) > 0 ? '+' : '' }}{{ num(item.diff) }}
                </v-chip>
                <v-icon v-else-if="item.diff !== null" size="small" color="success">mdi-check</v-icon>
                <span v-else class="text-disabled">—</span>
            </template>

            <template #item.posted="{ item }">
                <v-icon v-if="item.posted" size="small" color="success" :title="t('WarehouseView.stocktaking.postedRow')">
                    mdi-lock-check
                </v-icon>
            </template>
        </v-data-table>

        <!-- Abschluss-Dialog -->
        <v-dialog v-model="showSummary" max-width="820" scrollable>
            <v-card rounded="lg">
                <v-card-title class="text-subtitle-1 font-weight-bold">
                    {{ t('WarehouseView.stocktaking.summaryTitle') }}
                </v-card-title>
                <v-divider />
                <v-card-text>
                    <v-row dense class="mb-2">
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('WarehouseView.stocktaking.counted') }}</div>
                            <div class="text-h6">{{ summary.counted ?? 0 }}</div>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('WarehouseView.stocktaking.diffs') }}</div>
                            <div class="text-h6 text-warning">{{ summary.with_diff ?? 0 }}</div>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('WarehouseView.stocktaking.surplus') }}</div>
                            <div class="text-h6">
                                <span class="text-success">+{{ num(summary.surplus_qty) }}</span>
                                <span class="text-medium-emphasis mx-1">/</span>
                                <span class="text-warning">{{ num(summary.shortage_qty) }}</span>
                            </div>
                        </v-col>
                        <v-col cols="6" sm="3">
                            <div class="text-caption text-medium-emphasis">{{ t('WarehouseView.stocktaking.diffValue') }}</div>
                            <div class="text-h6">{{ money(summary.diff_value) }}</div>
                        </v-col>
                    </v-row>

                    <v-alert type="info" variant="tonal" density="compact" class="mb-3"
                             :text="t('WarehouseView.stocktaking.postHint')" />

                    <v-data-table
                        :headers="summaryHeaders"
                        :items="(summary.items || []).filter(i => Number(i.diff) !== 0)"
                        density="compact"
                        :items-per-page="20"
                        :no-data-text="t('WarehouseView.stocktaking.noDiffs')"
                    >
                        <template #item.diff="{ item }">
                            <span :class="Number(item.diff) > 0 ? 'text-success' : 'text-warning'">
                                {{ Number(item.diff) > 0 ? '+' : '' }}{{ num(item.diff) }} {{ item.unit }}
                            </span>
                        </template>
                        <template #item.diff_value="{ item }">{{ money(item.diff_value) }}</template>
                    </v-data-table>
                </v-card-text>
                <v-divider />
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showSummary = false">{{ t('WarehouseView.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="loading" @click="postAll">
                        {{ t('WarehouseView.stocktaking.postDiffs') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useStocktaking } from '../composables/useWarehouse.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import { formatDate } from '@/core/utils/dateFormatter.js'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const st = useStocktaking()

const sessionId = Number(route.params.id)
const loading = computed(() => st.loading.value)

const list = ref({ items: [], total: 0, counted: 0 })
const session = ref(null)
const summary = ref({})
const blind = ref(true)
const search = ref('')
const showSummary = ref(false)

// Zwischengespeicherte Eingaben, bis der Anwender das Feld verlaesst
const staged = ref({})

const isOpen = computed(() => session.value?.status === 'open')
const statusColor = computed(() => ({ open: 'primary', posted: 'success', cancelled: 'grey' }[session.value?.status] || 'grey'))
const diffCount = computed(() => (list.value.items || []).filter(i => i.diff !== null && Number(i.diff) !== 0).length)

const headers = computed(() => [
    { title: t('WarehouseView.stock.partnumber'),  key: 'partnumber',  width: '130px' },
    { title: t('WarehouseView.stock.description'), key: 'description' },
    { title: t('WarehouseView.bin'),               key: 'bin',         width: '140px' },
    { title: t('WarehouseView.booking.charge'),    key: 'chargenumber', width: '110px' },
    { title: t('WarehouseView.stocktaking.count'), key: 'counted_qty', width: '150px', sortable: false },
    { title: t('WarehouseView.stocktaking.book'),  key: 'book_qty',    width: '120px', align: 'end' },
    { title: t('WarehouseView.stocktaking.diff'),  key: 'diff',        width: '110px', align: 'center' },
    { title: '',                                    key: 'posted',      width: '40px',  sortable: false },
])

const summaryHeaders = computed(() => [
    { title: t('WarehouseView.stock.partnumber'),  key: 'partnumber',  width: '130px' },
    { title: t('WarehouseView.stock.description'), key: 'description' },
    { title: t('WarehouseView.bin'),               key: 'bin',         width: '140px' },
    { title: t('WarehouseView.stocktaking.book'),  key: 'book_qty',    width: '110px', align: 'end' },
    { title: t('WarehouseView.stocktaking.count'), key: 'counted_qty', width: '110px', align: 'end' },
    { title: t('WarehouseView.stocktaking.diff'),  key: 'diff',        width: '120px', align: 'end' },
    { title: t('WarehouseView.stocktaking.diffValue'), key: 'diff_value', width: '120px', align: 'end' },
])

function num(v)   { return formatNumber(Number(v ?? 0), locale.value, Number(v) % 1 === 0 ? 0 : 2) }
function money(v) { return formatNumber(Number(v ?? 0), locale.value, 2) + ' €' }
function d(v)     { return v ? formatDate(v, locale.value) : '' }

async function load() {
    const res = await st.fetchList({ session_id: sessionId, blind: blind.value, search: search.value || '' })
    list.value = res
    session.value = res.session
    if (session.value?.status !== 'open') blind.value = false
}

onMounted(load)

let searchTimer = null
watch(search, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(load, 250)
})

function rowKey(item) {
    return `${item.parts_id}_${item.bin_id}_${item.chargenumber || ''}`
}

function stageCount(item, value) {
    staged.value[rowKey(item)] = value
    item.counted_qty = value
}

/**
 * Eingabe speichern und die Differenz zurueckspielen. Der Buchbestand kommt
 * erst mit der Antwort — bis dahin war er nirgends sichtbar.
 */
async function commitCount(item) {
    const key = rowKey(item)
    const value = staged.value[key]
    if (value === undefined || value === '' || value === null) return
    delete staged.value[key]

    try {
        const res = await st.saveCount({
            session_id: sessionId,
            parts_id: item.parts_id,
            bin_id: item.bin_id,
            chargenumber: item.chargenumber || '',
            qty: Number(value),
        })
        item.count_id    = res.id
        item.book_qty    = res.book_qty
        item.diff        = res.diff
        item.counted_qty = Number(value)
        list.value.counted = (list.value.items || []).filter(i => i.counted_qty !== null && i.counted_qty !== '').length
        if (Number(res.diff) !== 0) {
            toasts.warning(t('WarehouseView.stocktaking.diffFound', {
                part: item.partnumber, diff: num(res.diff),
            }))
        }
    } catch (e) {
        toasts.error(e.message)
    }
}

async function openSummary() {
    summary.value = await st.fetchSummary(sessionId)
}
watch(showSummary, (v) => { if (v) openSummary() })

/**
 * Differenzen buchen.
 *
 * Bewusst OHNE zusaetzliche Sicherheitsabfrage: der Dialog zeigt bereits genau,
 * was gebucht wird, und der Knopf ist eindeutig beschriftet. Eine Abfrage
 * darueber waere nicht nur ueberfluessig — sie laege im Stapel UNTER dem Dialog
 * und liesse sich gar nicht bestaetigen.
 */
async function postAll() {
    const res = await st.postSession(sessionId)
    showSummary.value = false
    alerts.success(t('WarehouseView.stocktaking.posted', { booked: res.booked, unchanged: res.unchanged }))
    await load()
}

async function cancelSessionAsk() {
    const ok = await alerts.warning(t('WarehouseView.stocktaking.cancelConfirm'))
    if (!ok.isConfirmed) return
    await st.cancelSession(sessionId)
    router.push({ name: 'warehouse' })
}
</script>
