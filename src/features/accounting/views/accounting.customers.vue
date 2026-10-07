<template>
    <NavbarView />
    <v-container fluid>
        <AccountingPageHeader :title="t('AccountingView.customers.title')" :back-to="backTo" :back-label="backLabel" />

        <v-row>
            <v-col cols="12">
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mt-1 mb-2" :text="t('AccountingView.customers.info')" />
            </v-col>
        </v-row>

        <!-- Aktionsleiste -->
        <v-row>
            <v-col cols="12" sm="6" md="4">
                <v-text-field v-model="searchQuery" :label="t('AccountingView.customers.search')"
                              prepend-inner-icon="mdi-magnify" density="compact" variant="outlined"
                              hide-details clearable @update:model-value="onSearch" />
            </v-col>
            <v-col cols="12" sm="6" md="8" class="d-flex flex-wrap align-center ga-2">
                <v-btn variant="outlined" :loading="loading" @click="onFindDuplicates">
                    <v-icon start>mdi-content-duplicate</v-icon>
                    {{ t('AccountingView.customers.findDuplicates') }}
                </v-btn>
                <!-- Von Hand angehakte Zeilen: auch Kunden, die der Dublettenprüfer
                     nicht findet (abweichende Schreibweise), lassen sich so vereinen -->
                <v-btn color="primary" variant="elevated" :disabled="selectedRows.length < 2" @click="openMergeDialog(selectedRows)">
                    <v-icon start>mdi-merge</v-icon>
                    {{ t('AccountingView.customers.mergeSelected', { count: selectedRows.length }) }}
                </v-btn>
                <v-btn v-if="selectedRows.length" variant="text" size="small" @click="clearSelection">
                    {{ t('AccountingView.customers.clearSelection') }}
                </v-btn>
                <span v-else class="text-caption text-grey">{{ t('AccountingView.customers.selectHint') }}</span>
            </v-col>
        </v-row>

        <!-- Dubletten-Gruppen: ein Kunde, der dreimal angelegt wurde, ist eine
             Gruppe mit drei Mitgliedern — und wird in einem Schritt zusammengeführt -->
        <v-row v-if="duplicateGroups.length > 0" class="mt-2">
            <v-col cols="12">
                <v-alert type="warning" variant="tonal" closable>
                    {{ t('AccountingView.customers.duplicatesFound', { count: duplicateGroups.length }) }}
                </v-alert>
                <v-card v-for="group in duplicateGroups" :key="groupKey(group)" class="mb-2">
                    <v-card-text class="d-flex align-center flex-wrap ga-3">
                        <div class="flex-grow-1 d-flex align-center flex-wrap ga-3">
                            <template v-for="(member, index) in group.members" :key="member.id">
                                <v-icon v-if="index > 0" size="small" class="text-grey">mdi-swap-horizontal</v-icon>
                                <div>
                                    <strong>{{ member.name }}</strong>
                                    <span v-if="member.customernumber" class="text-grey"> · {{ member.customernumber }}</span>
                                    <div class="text-caption text-grey">{{ memberDetails(member) }}</div>
                                </div>
                            </template>
                            <v-chip size="small" :color="group.same_iban ? 'error' : 'warning'">
                                {{ group.same_iban ? t('AccountingView.customers.sameIban') : (Math.round(group.similarity * 100) + '% ' + t('AccountingView.customers.similarity')) }}
                            </v-chip>
                        </div>
                        <v-btn size="small" variant="outlined" color="primary" @click="openMergeDialog(group.members)">
                            {{ t('AccountingView.customers.mergeGroup', { count: group.members.length }) }}
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Kunden-Tabelle -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-data-table
                    v-model="selectedIds"
                    :headers="headers"
                    :items="customers"
                    :loading="loading"
                    show-select
                    item-value="id"
                    density="compact"
                    :items-per-page="50"
                    :no-data-text="t('AccountingView.customers.noCustomers')"
                    @click:row="openCustomer"
                >
                    <template #item.itime="{ item }">
                        {{ item.created_fmt || '—' }}
                    </template>
                    <template #item.total_amount="{ item }">
                        {{ item.total_amount ? formatCurrency(item.total_amount) : '—' }}
                    </template>
                </v-data-table>
            </v-col>
        </v-row>

        <!-- Zusammenführen-Dialog: einer bleibt, alle anderen gehen in ihm auf -->
        <v-dialog v-model="mergeDialog" max-width="640">
            <v-card>
                <v-card-title>{{ t('AccountingView.customers.merge') }}</v-card-title>
                <v-card-text>
                    <p class="mb-2">{{ t('AccountingView.customers.mergeConfirm') }}</p>
                    <v-radio-group v-model="mergeKeepId" hide-details>
                        <div v-for="opt in mergeOptions" :key="opt.id" class="d-flex align-center">
                            <v-radio :value="opt.id" class="flex-grow-1 merge-option">
                                <template #label>
                                    <div>
                                        <strong>{{ opt.name }}</strong>
                                        <span v-if="opt.customernumber" class="text-grey"> · {{ opt.customernumber }}</span>
                                        <span v-if="opt.id === suggestedKeepId" class="text-caption text-primary ml-2">
                                            {{ t('AccountingView.customers.suggestion') }}
                                        </span>
                                        <div class="text-caption text-grey">
                                            {{ memberDetails(opt) }}<template v-if="opt.deletable"> · {{ t('AccountingView.customers.neverUsed') }}</template>
                                        </div>
                                    </div>
                                </template>
                            </v-radio>
                            <!-- Ein Mitglied, das doch nicht dazugehört, fliegt aus der Gruppe -->
                            <v-btn v-if="mergeOptions.length > 2" icon="mdi-close" size="x-small" variant="text"
                                   :title="t('AccountingView.customers.removeFromMerge')" @click="removeFromMerge(opt.id)" />
                        </div>
                    </v-radio-group>

                    <v-checkbox v-if="deletableMerged.length" v-model="deleteMerged" hide-details density="compact" class="mt-2"
                                color="primary" :label="t('AccountingView.customers.deleteMerged', { name: joinNames(deletableMerged) })" />

                    <v-alert :type="willDelete ? 'warning' : 'info'" variant="tonal" density="compact" class="mt-3" :text="explainText" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="mergeDialog = false">{{ t('AccountingView.customers.cancel') }}</v-btn>
                    <v-btn :color="willDelete ? 'error' : 'primary'" variant="elevated" :loading="merging" @click="doMerge">
                        {{ willDelete ? t('AccountingView.customers.mergeAndDelete') : t('AccountingView.customers.merge') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useCustomerMatching } from '../composables/useCustomerMatching.js'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { loading, error, customers, duplicateGroups, fetchCustomers, mergeCustomers, findDuplicates } = useCustomerMatching()

// Aus der Kundensuche (Stammdaten) kommend führt "Zurück" dorthin, nicht ins
// Buchhaltungs-Cockpit — und die Dublettensuche läuft gleich los, denn genau
// dafür wurde der Knopf dort gedrückt.
const fromSearch = computed(() => route.query.from === 'search')
const backTo = computed(() => fromSearch.value ? { name: 'search', query: { type: 'customer' } } : null)
const backLabel = computed(() => fromSearch.value ? t('AccountingView.customers.backToSearch') : '')

const searchQuery = ref('')
const mergeDialog = ref(false)
const merging = ref(false)
const mergeOptions = ref([])
const mergeKeepId = ref(null)
const hasSearchedDuplicates = ref(false)

// ── Auswahl in der Tabelle ──
// v-data-table hält nur IDs; die Zeilen dazu werden gemerkt, damit die Auswahl
// auch über eine neue Suche hinweg bestehen bleibt (erst "Strauch" anhaken,
// dann "Autohaus S." suchen und anhaken).
const selectedIds = ref([])
const selectedRowsById = ref({})
watch(selectedIds, (ids) => {
    const next = {}
    for (const id of ids) {
        next[id] = selectedRowsById.value[id] || customers.value.find(c => Number(c.id) === Number(id))
    }
    selectedRowsById.value = next
})
const selectedRows = computed(() => selectedIds.value.map(id => selectedRowsById.value[id]).filter(Boolean))

function clearSelection() {
    selectedIds.value = []
}

// Vorschlag: der stärker bebuchte Kunde bleibt, bei Gleichstand der ältere
// (kleinere ID) — so müssen die wenigsten Belege umgehängt werden.
const suggestedKeepId = computed(() => {
    const best = [...mergeOptions.value].sort((a, b) => (b.bookings - a.bookings) || (a.id - b.id))[0]
    return best ? best.id : null
})

const keptOption = computed(() => mergeOptions.value.find(o => o.id === mergeKeepId.value) || null)
const mergedOptions = computed(() => mergeOptions.value.filter(o => o.id !== mergeKeepId.value))
const deletableMerged = computed(() => mergedOptions.value.filter(o => o.deletable))
const obsoletedMerged = computed(() => mergedOptions.value.filter(o => !o.deletable))

// Nie benutzte Doppeleinträge werden gelöscht statt stillgelegt — sonst
// bleiben sie in jeder Kundenauswahl als Karteileiche stehen. Sobald am
// Eintrag irgendein Beleg hängt, ist die Option gar nicht erst wählbar.
const deleteMerged = ref(true)
const willDelete = computed(() => deleteMerged.value && deletableMerged.value.length > 0)

const explainText = computed(() => {
    const keep = joinNames(keptOption.value ? [keptOption.value] : [])
    if (!willDelete.value) {
        return t('AccountingView.customers.mergeExplain', { keep, merged: joinNames(mergedOptions.value) })
    }
    if (obsoletedMerged.value.length === 0) {
        return t('AccountingView.customers.mergeExplainDelete', { keep, merged: joinNames(deletableMerged.value) })
    }
    return t('AccountingView.customers.mergeExplainMixed', {
        keep, deleted: joinNames(deletableMerged.value), merged: joinNames(obsoletedMerged.value)
    })
})

const headers = computed(() => [
    { title: t('AccountingView.customers.number'), key: 'customernumber', width: '100px' },
    { title: t('AccountingView.customers.name'), key: 'name' },
    { title: t('AccountingView.customers.street'), key: 'street', width: '160px' },
    { title: t('AccountingView.customers.city'), key: 'city', width: '120px' },
    { title: t('AccountingView.customers.created'), key: 'itime', width: '110px' },
    { title: t('AccountingView.customers.iban'), key: 'iban', width: '200px' },
    { title: t('AccountingView.customers.taxNumber'), key: 'taxnumber', width: '140px' },
    { title: t('AccountingView.customers.bookingCount'), key: 'booking_count', align: 'end', width: '100px' },
    { title: t('AccountingView.customers.totalAmount'), key: 'total_amount', align: 'end', width: '120px' },
    { title: t('AccountingView.customers.defaultAccount'), key: 'default_account', width: '100px' }
])

let searchTimer = null
function onSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
        fetchCustomers(searchQuery.value)
    }, 300)
}

// Stammdaten werden im Kundenmodul gepflegt, nicht hier nachgebaut. Ein Klick
// auf das Auswahlkästchen soll nur anhaken, nicht die Seite verlassen.
function openCustomer(event, { item }) {
    if (event?.target?.closest?.('.v-selection-control')) return
    router.push({ name: 'customer-edit', params: { id: Number(item.id) } })
}

function joinNames(options) {
    return options.map(o => '„' + o.name + '“').join(', ')
}

function memberDetails(member) {
    const address = [member.street, member.city].filter(Boolean).join(', ')
    return [
        address,
        t('AccountingView.customers.createdOn', { date: member.created }),
        t('AccountingView.customers.bookings', { count: Number(member.bookings || 0) })
    ].filter(Boolean).join(' · ')
}

function groupKey(group) {
    return group.members.map(m => m.id).join('-')
}

// Tabellenzeilen und Gruppenmitglieder haben unterschiedliche Spaltennamen —
// der Dialog arbeitet mit einer Form.
function toOption(row) {
    return {
        id: Number(row.id),
        name: row.name,
        customernumber: row.customernumber,
        street: row.street,
        city: row.city,
        created: row.created || row.created_fmt,
        bookings: Number(row.bookings ?? row.booking_count ?? 0),
        deletable: row.deletable === true
    }
}

function openMergeDialog(members) {
    mergeOptions.value = members.map(toOption)
    mergeKeepId.value = suggestedKeepId.value
    deleteMerged.value = true
    mergeDialog.value = true
}

function removeFromMerge(id) {
    mergeOptions.value = mergeOptions.value.filter(o => o.id !== id)
    if (mergeKeepId.value === id) mergeKeepId.value = suggestedKeepId.value
}

async function doMerge() {
    if (!keptOption.value || mergedOptions.value.length === 0) return
    merging.value = true
    const result = await mergeCustomers(mergeKeepId.value, mergedOptions.value.map(o => o.id), deleteMerged.value)
    merging.value = false
    if (!result.success) {
        alerts.error(result.text || '')
        return
    }
    mergeDialog.value = false
    const payload = result.payload || {}
    const keep = '„' + (payload.kept_customer || '') + '“'
    const mergedNames = (payload.merged || []).map(m => '„' + m.name + '“').join(', ')
    alerts.success(payload.merged_deleted
        ? t('AccountingView.customers.deleteDone', { merged: mergedNames, keep })
        : t('AccountingView.customers.mergeDone', {
            keep,
            count: (payload.moved_invoices || 0) + (payload.moved_orders || 0)
                 + (payload.moved_delivery_orders || 0) + (payload.moved_bookings || 0)
        }))
    clearSelection()
    fetchCustomers(searchQuery.value)
    if (hasSearchedDuplicates.value) findDuplicates()
}

// Ohne die Fehlerausgabe sah ein Serverfehler wie ein leeres Ergebnis aus:
// die Suche meldete "keine Dubletten gefunden", obwohl sie gar nicht gelaufen war.
async function onFindDuplicates() {
    await findDuplicates()
    if (error.value) {
        alerts.error(error.value)
        return
    }
    hasSearchedDuplicates.value = true
    if (duplicateGroups.value.length === 0) alerts.info(t('AccountingView.customers.noDuplicates'))
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

onMounted(() => {
    fetchCustomers()
    if (fromSearch.value) onFindDuplicates()
})
</script>

<style scoped>
.merge-option :deep(.v-label) {
    opacity: 1;
}
</style>
