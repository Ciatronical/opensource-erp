<!-- src/features/lxcars/views/special-tools/special-tools.view.vue -->
<!--
    Spezialwerkzeug-Übersicht unter Lager. Jedes Werkzeug zeigt sofort, zu wie
    vielen Fahrzeugen es passt; Werkzeuge ohne Zuordnung fallen auf und
    lassen sich mit einem Klick von der KI bewerten. Die Kennzahlen oben sind
    Filter. Details, Regeln und Fahrzeuge stehen im Werkzeugdialog.
-->
<template>
    <NavbarView />
    <v-container fluid class="pt-3">
        <!-- ── Kopfzeile ─────────────────────────────────────────────────── -->
        <div class="d-flex align-center flex-wrap ga-2 mb-3">
            <v-icon color="primary" class="mr-1">mdi-tools</v-icon>
            <div>
                <h1 class="text-h6 mb-0">{{ t('SpecialToolsView.title') }}</h1>
                <div class="text-caption text-medium-emphasis">{{ t('SpecialToolsView.subtitle') }}</div>
            </div>
            <v-spacer />
            <ai-model-button assistant="special_tools" size="small" />
            <v-btn color="primary" variant="flat" size="small" @click="openTool(null)">
                <v-icon start size="small">mdi-plus</v-icon>
                {{ t('SpecialToolsView.newTool') }}
            </v-btn>
        </div>

        <!-- Profile veraltet (z. B. nach Datenimport) -->
        <v-alert v-if="kpi.profiles_stale" type="info" variant="tonal" density="compact" class="mb-3">
            <div class="d-flex align-center flex-wrap ga-3">
                <span class="text-body-2">{{ t('SpecialToolsView.stale.text') }}</span>
                <v-btn size="x-small" variant="flat" color="info" :loading="rebuilding" @click="rebuild">
                    {{ t('SpecialToolsView.stale.rebuild') }}
                </v-btn>
            </div>
        </v-alert>

        <!-- ── Einstieg ohne Werkzeuge ───────────────────────────────────── -->
        <v-row v-if="!loading && kpi.total === 0">
            <v-col cols="12" md="8" offset-md="2">
                <v-card variant="tonal" color="primary" rounded="lg" class="pa-2">
                    <v-card-text class="text-center py-8">
                        <v-icon size="64" color="primary" class="mb-4">mdi-tools</v-icon>
                        <h2 class="text-h5 mb-2">{{ t('SpecialToolsView.empty.title') }}</h2>
                        <p class="text-body-2 text-medium-emphasis mb-6" style="max-width: 52ch; margin: 0 auto;">
                            {{ t('SpecialToolsView.empty.text') }}
                        </p>
                        <v-btn color="primary" variant="flat" size="large" @click="openTool(null)">
                            <v-icon start>mdi-plus</v-icon>
                            {{ t('SpecialToolsView.newTool') }}
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <template v-else>
            <!-- ── Kennzahlen als Filter ─────────────────────────────────── -->
            <v-row dense class="mb-1">
                <v-col v-for="card in kpiCards" :key="card.key" cols="6" md="3">
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

            <!-- ── Suche ─────────────────────────────────────────────────── -->
            <div class="d-flex align-center flex-wrap ga-2 mb-2 pt-2">
                <v-text-field
                    v-model="search"
                    :placeholder="t('SpecialToolsView.search')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined" density="compact" hide-details clearable autofocus
                    style="max-width: 420px;"
                />
                <v-chip v-if="filter !== 'all'" closable color="primary" size="small" @click:close="applyFilter('all')">
                    {{ filterLabel }}
                </v-chip>
                <v-spacer />
                <span class="text-caption text-medium-emphasis">
                    {{ t('SpecialToolsView.count', { shown: visibleItems.length }) }}
                </span>
            </div>

            <!-- ── Werkzeugliste ─────────────────────────────────────────── -->
            <v-data-table
                :headers="headers"
                :items="visibleItems"
                :loading="loading"
                density="comfortable"
                item-value="id"
                :items-per-page="50"
                hover
                class="tools-table"
                :no-data-text="t('SpecialToolsView.empty.noResults')"
                @click:row="(e, { item }) => openTool(item.id)"
            >
                <template #item.name="{ item }">
                    <div class="font-weight-medium">{{ item.name }}</div>
                    <div class="text-caption text-medium-emphasis">
                        {{ [item.tool_number, item.manufacturer].filter(Boolean).join(' · ') }}
                    </div>
                </template>
                <template #item.category="{ item }">
                    <v-chip v-if="item.category" size="small" variant="tonal">{{ item.category }}</v-chip>
                </template>
                <template #item.location="{ item }">
                    <div v-if="item.location" class="d-flex align-center ga-1">
                        <v-icon size="small" color="grey">mdi-map-marker-outline</v-icon>
                        <span>{{ item.location }}</span>
                    </div>
                    <div v-if="item.bin" class="text-caption text-medium-emphasis">{{ item.warehouse }} / {{ item.bin }}</div>
                </template>
                <template #item.status="{ item }">
                    <v-chip size="small" variant="flat" :color="statusColor(item.status)">
                        {{ t(`SpecialToolsView.status.${item.status}`) }}
                        <template v-if="item.status === 'lent' && item.lent_to"> · {{ item.lent_to }}</template>
                    </v-chip>
                </template>
                <template #item.vehicles="{ item }">
                    <v-chip v-if="item.vehicles > 0" size="small" variant="tonal" color="primary">
                        <v-icon start size="small">mdi-car-multiple</v-icon>{{ item.vehicles }}
                    </v-chip>
                    <v-chip v-else size="small" variant="tonal" color="warning">
                        <v-icon start size="small">mdi-help-circle-outline</v-icon>{{ t('SpecialToolsView.assignment.none') }}
                    </v-chip>
                </template>
                <template #item.assignment="{ item }">
                    <span class="text-caption">{{ assignmentText(item) }}</span>
                    <div v-if="item.shop_sell || item.shop_rent" class="mt-1">
                        <v-chip v-if="item.shop_rent" size="x-small" variant="tonal" color="teal" class="mr-1">
                            <v-icon start size="x-small">mdi-storefront-outline</v-icon>{{ t('SpecialToolsView.shop.rentChip') }}
                        </v-chip>
                        <v-chip v-if="item.shop_sell" size="x-small" variant="tonal" color="teal">
                            <v-icon start size="x-small">mdi-cart-outline</v-icon>{{ t('SpecialToolsView.shop.sellChip') }}
                        </v-chip>
                    </div>
                </template>
                <template #item.actions="{ item }">
                    <div class="d-flex justify-end text-no-wrap">
                    <v-btn icon variant="text" size="x-small" color="deep-purple"
                           :title="item.ai_summary ? t('SpecialToolsView.assignment.reanalyze') : t('SpecialToolsView.assignment.analyze')"
                           :loading="analyzingId === item.id"
                           @click.stop="analyzeInline(item)">
                        <v-icon size="small">mdi-brain</v-icon>
                    </v-btn>
                    <v-btn icon variant="text" size="x-small" :title="t('SpecialToolsView.delete')" @click.stop="removeTool(item)">
                        <v-icon size="small">mdi-delete-outline</v-icon>
                    </v-btn>
                    </div>
                </template>
            </v-data-table>
        </template>

        <special-tool-dialog
            v-model="dialogOpen"
            :tool-id="dialogToolId"
            :options="options"
            @changed="reloadAll"
        />
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AiModelButton from '@/core/components/ai-model-button.vue'
import SpecialToolDialog from './components/special-tool.dialog.vue'
import { useSpecialTools } from '@/features/lxcars/composables/useSpecialTools.js'
import { aiModelStore } from '@/core/stores/ai-model.store.js'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const api = useSpecialTools()
const aiModels = aiModelStore()

const loading = computed(() => api.loading.value)
const items = ref([])
const kpi = ref({ total: 0, lent: 0, unassigned: 0, vehicles_covered: 0, vehicles_total: 0, profiles_stale: false })
const options = ref({})
const search = ref('')
const filter = ref('all')
const rebuilding = ref(false)
const analyzingId = ref(null)

const dialogOpen = ref(false)
const dialogToolId = ref(null)

const visibleItems = computed(() => {
    if (filter.value === 'lent') return items.value.filter(i => i.status === 'lent')
    if (filter.value === 'unassigned') return items.value.filter(i => Number(i.vehicles) === 0)
    return items.value
})

const filterLabel = computed(() => ({
    lent: t('SpecialToolsView.kpi.lent'),
    unassigned: t('SpecialToolsView.kpi.unassigned'),
}[filter.value] || ''))

const kpiCards = computed(() => [
    { key: 'total',      filter: 'all',        icon: 'mdi-tools',                 color: 'primary',
      label: t('SpecialToolsView.kpi.total'),      value: kpi.value.total ?? 0 },
    { key: 'lent',       filter: 'lent',       icon: 'mdi-account-arrow-right-outline', color: 'warning',
      label: t('SpecialToolsView.kpi.lent'),       value: kpi.value.lent ?? 0 },
    { key: 'unassigned', filter: 'unassigned', icon: 'mdi-help-circle-outline',   color: 'error',
      label: t('SpecialToolsView.kpi.unassigned'), value: kpi.value.unassigned ?? 0 },
    { key: 'covered',    filter: null,         icon: 'mdi-car-multiple',          color: 'success',
      label: t('SpecialToolsView.kpi.covered'),
      value: t('SpecialToolsView.kpi.coveredOf', { covered: kpi.value.vehicles_covered ?? 0, total: kpi.value.vehicles_total ?? 0 }) },
])

const headers = computed(() => [
    { title: t('SpecialToolsView.columns.tool'),       key: 'name' },
    { title: t('SpecialToolsView.columns.category'),   key: 'category',   width: '150px' },
    { title: t('SpecialToolsView.columns.location'),   key: 'location',   width: '200px' },
    { title: t('SpecialToolsView.columns.status'),     key: 'status',     width: '150px' },
    { title: t('SpecialToolsView.columns.vehicles'),   key: 'vehicles',   width: '150px', align: 'end' },
    { title: t('SpecialToolsView.columns.assignment'), key: 'assignment', width: '220px', sortable: false },
    { title: '',                                       key: 'actions',    width: '110px', sortable: false, align: 'end' },
])

function statusColor(s) { return { available: 'success', lent: 'warning', defective: 'error' }[s] || 'grey' }
function isActive(card) { return !!card.filter && card.filter !== 'all' && filter.value === card.filter }
function applyFilter(f) { filter.value = f }

function assignmentText(item) {
    const parts = []
    if (item.rules > 0) parts.push(t('SpecialToolsView.assignment.rules', { n: item.rules }, item.rules))
    if (item.pinned > 0) parts.push(t('SpecialToolsView.assignment.pinned', { n: item.pinned }))
    if (item.excluded > 0) parts.push(t('SpecialToolsView.assignment.excluded', { n: item.excluded }))
    return parts.join(' · ') || '—'
}

// ── Laden ───────────────────────────────────────────────────────────────────
async function loadTools() {
    const res = await api.fetchTools({ search: search.value || '' })
    items.value = res.items || []
    kpi.value = res.kpi || kpi.value
}
async function loadOptions() { options.value = await api.fetchOptions() }
async function reloadAll() { await Promise.all([loadTools(), loadOptions()]) }

onMounted(async () => {
    await reloadAll()
    // Direktsprung aus der Fahrzeugkarte: ?tool=ID
    const id = Number(route.query.tool)
    if (id) openTool(id)
})

let searchTimer = null
watch(search, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(loadTools, 250)
})

// ── Aktionen ────────────────────────────────────────────────────────────────
function openTool(id) {
    dialogToolId.value = id || null
    dialogOpen.value = true
}

watch(dialogOpen, (open) => {
    // Beim Schließen den Direktsprung aus der URL nehmen
    if (!open && route.query.tool) router.replace({ name: 'special-tools' })
})

async function analyzeInline(item) {
    if (item.rules > 0) {
        const ok = await alerts.question(t('SpecialToolsView.assignment.reanalyzeConfirm'))
        if (!ok.isConfirmed) return
    }
    analyzingId.value = item.id
    try {
        const res = await api.analyzeTool(item.id, aiModels.requestModel('special_tools'))
        alerts.success(t('SpecialToolsView.assignment.analyzeDone', { rules: res.rules.length, vehicles: res.vehicles_total }))
        await loadTools()
        openTool(item.id)
    } catch (e) {
        if (e.code === 'MISSING_API_KEYS') alerts.error(t('SpecialToolsView.assignment.missingKey'))
        else alerts.error(e.message)
    } finally {
        analyzingId.value = null
    }
}

async function removeTool(item) {
    const ok = await alerts.question(t('SpecialToolsView.form.deleteConfirm', { name: item.name }))
    if (!ok.isConfirmed) return
    try {
        await api.deleteTool(item.id)
        alerts.success(t('SpecialToolsView.form.deleted'))
        await loadTools()
    } catch (e) { alerts.error(e.message) }
}

async function rebuild() {
    rebuilding.value = true
    try {
        await api.rebuildMatches()
        alerts.success(t('SpecialToolsView.stale.done'))
        await loadTools()
    } catch (e) { alerts.error(e.message) }
    finally { rebuilding.value = false }
}
</script>

<style scoped>
.cursor-pointer { cursor: pointer; }
.tools-table :deep(tbody tr) { cursor: pointer; }
</style>
