<template>
    <NavbarView />
    <v-container fluid>
        <AccountingPageHeader :title="t('AccountingView.vendors.title')" />

        <v-row>
            <v-col cols="12">
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mt-1 mb-2" :text="t('AccountingView.vendors.info')" />
            </v-col>
        </v-row>

        <!-- Aktionsleiste -->
        <v-row>
            <v-col cols="12" sm="6" md="4">
                <v-text-field v-model="searchQuery" :label="t('AccountingView.vendors.search')"
                              prepend-inner-icon="mdi-magnify" density="compact" variant="outlined"
                              hide-details clearable @update:model-value="onSearch" />
            </v-col>
            <v-col cols="12" sm="6" md="8" class="d-flex gap-2">
                <v-btn color="primary" variant="elevated" @click="openNewVendorDialog">
                    <v-icon start>mdi-plus</v-icon>
                    {{ t('AccountingView.vendors.newVendor') }}
                </v-btn>
                <v-btn variant="outlined" @click="onFindDuplicates">
                    <v-icon start>mdi-content-duplicate</v-icon>
                    {{ t('AccountingView.vendors.findDuplicates') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Dubletten-Warnung -->
        <v-row v-if="duplicates.length > 0" class="mt-2">
            <v-col cols="12">
                <v-alert type="warning" variant="tonal" closable>
                    {{ t('AccountingView.vendors.duplicatesFound', { count: duplicates.length }) }}
                </v-alert>
                <v-card v-for="dup in duplicates" :key="dup.vendor1_id + '-' + dup.vendor2_id" class="mb-2">
                    <v-card-text class="d-flex align-center flex-wrap ga-2">
                        <div class="flex-grow-1 d-flex align-center flex-wrap ga-2">
                            <div>
                                <strong>{{ dup.vendor1_name }}</strong> ({{ dup.vendor1_city }})
                                <div class="text-caption text-grey">
                                    {{ t('AccountingView.vendors.createdOn', { date: dup.vendor1_created }) }} ·
                                    {{ t('AccountingView.vendors.bookings', { count: dup.vendor1_bookings }) }}
                                </div>
                            </div>
                            <v-icon class="mx-2">mdi-swap-horizontal</v-icon>
                            <div>
                                <strong>{{ dup.vendor2_name }}</strong> ({{ dup.vendor2_city }})
                                <div class="text-caption text-grey">
                                    {{ t('AccountingView.vendors.createdOn', { date: dup.vendor2_created }) }} ·
                                    {{ t('AccountingView.vendors.bookings', { count: dup.vendor2_bookings }) }}
                                </div>
                            </div>
                            <v-chip size="small" :color="dup.same_iban ? 'error' : 'warning'">
                                {{ dup.same_iban ? t('AccountingView.vendors.sameIban') : (Math.round(dup.name_similarity * 100) + '% ' + t('AccountingView.vendors.similarity')) }}
                            </v-chip>
                        </div>
                        <v-btn size="small" variant="outlined" color="primary" @click="openMergeDialog(dup)">
                            {{ t('AccountingView.vendors.merge') }}
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Lieferanten-Tabelle -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-data-table
                    :headers="headers"
                    :items="vendors"
                    :loading="loading"
                    density="compact"
                    :items-per-page="50"
                    :no-data-text="t('AccountingView.vendors.noVendors')"
                    @click:row="(_, { item }) => openEditDialog(item)"
                >
                    <template #item.itime="{ item }">
                        {{ item.created_fmt || '—' }}
                    </template>
                    <template #item.total_amount="{ item }">
                        {{ item.total_amount ? formatCurrency(item.total_amount) : '—' }}
                    </template>
                    <template #item.aliases="{ item }">
                        <span class="text-caption text-grey">{{ item.aliases || '' }}</span>
                    </template>
                </v-data-table>
            </v-col>
        </v-row>

        <!-- Neuer Lieferant Dialog -->
        <v-dialog v-model="newVendorDialog" max-width="600">
            <v-card>
                <v-card-title>{{ editVendorId ? t('AccountingView.vendors.editVendor') : t('AccountingView.vendors.newVendor') }}</v-card-title>
                <v-card-text>
                    <!-- Duplikat-Warnung -->
                    <v-alert v-if="duplicateWarning" type="warning" variant="tonal" class="mb-4">
                        {{ t('AccountingView.vendors.duplicateWarning') }}
                        <div v-for="d in duplicateWarning" :key="d.vendor_id" class="mt-1">
                            <strong>{{ d.vendor_name }}</strong> ({{ d.city }}) — {{ Math.round(d.match_score * 100) }}%
                        </div>
                    </v-alert>

                    <v-row dense>
                        <v-col cols="12">
                            <v-text-field v-model="vendorForm.name" :label="t('AccountingView.vendors.name')"
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="8">
                            <v-text-field v-model="vendorForm.street" label="Strasse"
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="4">
                            <v-text-field v-model="vendorForm.zipcode" label="PLZ"
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="vendorForm.city" :label="t('AccountingView.vendors.city')"
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="vendorForm.iban" :label="t('AccountingView.vendors.iban')"
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="vendorForm.taxnumber" :label="t('AccountingView.vendors.taxNumber')"
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="vendorForm.ustid" :label="t('AccountingView.vendors.ustId')"
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="vendorForm.email" :label="t('AccountingView.vendors.email')"
                                          density="compact" variant="outlined" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="vendorForm.phone" :label="t('AccountingView.vendors.phone')"
                                          density="compact" variant="outlined" />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="closeVendorDialog">{{ t('AccountingView.vendors.cancel') }}</v-btn>
                    <v-btn v-if="duplicateWarning && !editVendorId" color="warning" variant="elevated" @click="saveVendor(true)">
                        {{ t('AccountingView.vendors.forceCreate') }}
                    </v-btn>
                    <v-btn color="primary" variant="elevated" @click="saveVendor(false)">
                        {{ t('AccountingView.vendors.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Zusammenfuehren Dialog -->
        <v-dialog v-model="mergeDialog" max-width="580">
            <v-card>
                <v-card-title>{{ t('AccountingView.vendors.merge') }}</v-card-title>
                <v-card-text>
                    <p class="mb-2">{{ t('AccountingView.vendors.mergeConfirm') }}</p>
                    <v-radio-group v-model="mergeKeepId" hide-details>
                        <v-radio v-for="opt in mergeOptions" :key="opt.id" :value="opt.id">
                            <template #label>
                                <div>
                                    <strong>{{ opt.name }}</strong>
                                    <span v-if="opt.id === suggestedKeepId" class="text-caption text-primary ml-2">
                                        {{ t('AccountingView.vendors.suggestion') }}
                                    </span>
                                    <div class="text-caption text-grey">
                                        {{ t('AccountingView.vendors.createdOn', { date: opt.created }) }} ·
                                        {{ t('AccountingView.vendors.bookings', { count: opt.bookings }) }}
                                    </div>
                                </div>
                            </template>
                        </v-radio>
                    </v-radio-group>
                    <v-checkbox v-if="mergedOption?.deletable" v-model="deleteMerged" hide-details density="compact" class="mt-2"
                                color="primary" :label="t('AccountingView.vendors.deleteMerged', { name: mergedVendorName })" />

                    <v-alert :type="deleteMerged ? 'warning' : 'info'" variant="tonal" density="compact" class="mt-3"
                             :text="deleteMerged
                                 ? t('AccountingView.vendors.mergeExplainDelete', { keep: keptVendorName, merged: mergedVendorName })
                                 : t('AccountingView.vendors.mergeExplain', { keep: keptVendorName, merged: mergedVendorName })" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="mergeDialog = false">{{ t('AccountingView.vendors.cancel') }}</v-btn>
                    <v-btn :color="deleteMerged ? 'error' : 'primary'" variant="elevated" @click="doMerge">
                        {{ deleteMerged ? t('AccountingView.vendors.mergeAndDelete') : t('AccountingView.vendors.merge') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useVendorMatching } from '../composables/useVendorMatching.js'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const { loading, error, vendors, duplicates, fetchVendors, createVendor, updateVendor, mergeVendors, findDuplicates } = useVendorMatching()

const searchQuery = ref('')
const newVendorDialog = ref(false)
const editVendorId = ref(null)
const mergeDialog = ref(false)
const duplicateWarning = ref(null)

const vendorForm = ref({
    name: '', street: '', zipcode: '', city: '', iban: '',
    taxnumber: '', ustid: '', email: '', phone: ''
})

const mergeDup = ref(null)
const mergeKeepId = ref(null)

// Beide Seiten der Dublette als Auswahlliste — mit Anlegedatum und
// Buchungszahl, damit sichtbar ist, welcher Lieferant der gewachsene ist.
const mergeOptions = computed(() => {
    const d = mergeDup.value
    if (!d) return []
    return [
        { id: d.vendor1_id, name: d.vendor1_name, created: d.vendor1_created, bookings: Number(d.vendor1_bookings || 0), deletable: d.vendor1_deletable === true },
        { id: d.vendor2_id, name: d.vendor2_name, created: d.vendor2_created, bookings: Number(d.vendor2_bookings || 0), deletable: d.vendor2_deletable === true }
    ]
})

// Vorschlag: der stärker bebuchte Lieferant bleibt, bei Gleichstand der
// ältere (kleinere ID) — so müssen die wenigsten Belege umgehängt werden.
const suggestedKeepId = computed(() => {
    const [a, b] = mergeOptions.value
    if (!a || !b) return null
    if (a.bookings !== b.bookings) return a.bookings > b.bookings ? a.id : b.id
    return a.id < b.id ? a.id : b.id
})

const keptVendorName = computed(() => mergeOptions.value.find(o => o.id === mergeKeepId.value)?.name || '')
const mergedOption = computed(() => mergeOptions.value.find(o => o.id !== mergeKeepId.value) || null)
const mergedVendorName = computed(() => mergedOption.value?.name || '')

// Ein nie benutzter Doppeleintrag wird gelöscht statt stillgelegt — sonst
// bleibt er in jeder Lieferantenauswahl als Karteileiche stehen. Sobald am
// Eintrag irgendein Beleg hängt, ist die Option gar nicht erst wählbar.
const deleteMerged = ref(false)
watch(mergedOption, (opt) => { deleteMerged.value = !!opt?.deletable }, { immediate: true })

const headers = computed(() => [
    { title: t('AccountingView.vendors.number'), key: 'vendornumber', width: '100px' },
    { title: t('AccountingView.vendors.name'), key: 'name' },
    { title: t('AccountingView.vendors.city'), key: 'city', width: '120px' },
    { title: t('AccountingView.vendors.created'), key: 'itime', width: '110px' },
    { title: t('AccountingView.vendors.iban'), key: 'iban', width: '200px' },
    { title: t('AccountingView.vendors.taxNumber'), key: 'taxnumber', width: '140px' },
    { title: t('AccountingView.vendors.bookingCount'), key: 'booking_count', align: 'end', width: '100px' },
    { title: t('AccountingView.vendors.totalAmount'), key: 'total_amount', align: 'end', width: '120px' },
    { title: t('AccountingView.vendors.defaultAccount'), key: 'default_account', width: '100px' },
    { title: t('AccountingView.vendors.aliases'), key: 'aliases' }
])

let searchTimer = null
function onSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
        fetchVendors(searchQuery.value)
    }, 300)
}

async function saveVendor(force = false) {
    duplicateWarning.value = null

    // Ein geöffneter Lieferant wird geändert, nicht erneut angelegt —
    // sonst entstehen beim Bearbeiten genau die Dubletten, die diese
    // Ansicht aufräumen soll.
    const result = editVendorId.value
        ? await updateVendor(editVendorId.value, vendorForm.value)
        : await createVendor({ ...vendorForm.value, force })

    if (result.success) {
        closeVendorDialog()
        fetchVendors(searchQuery.value)
    } else if (result.text === 'POSSIBLE_DUPLICATES') {
        duplicateWarning.value = result.payload?.duplicates || []
    } else {
        alerts.error(result.text || '')
    }
}

function openEditDialog(item) {
    editVendorId.value = item.id
    vendorForm.value = { ...item }
    duplicateWarning.value = null
    newVendorDialog.value = true
}

function openNewVendorDialog() {
    editVendorId.value = null
    duplicateWarning.value = null
    vendorForm.value = { name: '', street: '', zipcode: '', city: '', iban: '', taxnumber: '', ustid: '', email: '', phone: '' }
    newVendorDialog.value = true
}

function closeVendorDialog() {
    newVendorDialog.value = false
    editVendorId.value = null
    duplicateWarning.value = null
    vendorForm.value = { name: '', street: '', zipcode: '', city: '', iban: '', taxnumber: '', ustid: '', email: '', phone: '' }
}

function openMergeDialog(dup) {
    mergeDup.value = dup
    mergeKeepId.value = suggestedKeepId.value
    mergeDialog.value = true
}

async function doMerge() {
    const result = await mergeVendors(mergeKeepId.value, mergedOption.value?.id, deleteMerged.value)
    if (result.success) {
        mergeDialog.value = false
        alerts.success(result.payload?.merged_deleted
            ? t('AccountingView.vendors.deleteDone', {
                merged: result.payload?.merged_vendor || '',
                keep: result.payload?.kept_vendor || ''
            })
            : t('AccountingView.vendors.mergeDone', {
                keep: result.payload?.kept_vendor || '',
                count: (result.payload?.moved_invoices || 0) + (result.payload?.moved_bookings || 0)
            }))
        fetchVendors(searchQuery.value)
        findDuplicates()
    } else {
        alerts.error(result.text || '')
    }
}

// Ohne die Fehlerausgabe sah ein Serverfehler wie ein leeres Ergebnis aus:
// die Suche meldete "keine Dubletten gefunden", obwohl sie gar nicht gelaufen war.
async function onFindDuplicates() {
    await findDuplicates()
    if (error.value) {
        alerts.error(error.value)
        return
    }
    if (duplicates.value.length === 0) alerts.info(t('AccountingView.vendors.noDuplicates'))
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

onMounted(() => {
    fetchVendors()
})
</script>
