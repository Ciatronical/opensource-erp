<template>
    <NavbarView />
    <v-container fluid>
        <AccountingPageHeader :title="t('AccountingView.customers.title')" />

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
            <v-col cols="12" sm="6" md="8" class="d-flex gap-2">
                <v-btn variant="outlined" @click="onFindDuplicates">
                    <v-icon start>mdi-content-duplicate</v-icon>
                    {{ t('AccountingView.customers.findDuplicates') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Dubletten-Warnung -->
        <v-row v-if="duplicates.length > 0" class="mt-2">
            <v-col cols="12">
                <v-alert type="warning" variant="tonal" closable>
                    {{ t('AccountingView.customers.duplicatesFound', { count: duplicates.length }) }}
                </v-alert>
                <v-card v-for="dup in duplicates" :key="dup.customer1_id + '-' + dup.customer2_id" class="mb-2">
                    <v-card-text class="d-flex align-center flex-wrap ga-2">
                        <div class="flex-grow-1 d-flex align-center flex-wrap ga-2">
                            <div>
                                <strong>{{ dup.customer1_name }}</strong> ({{ dup.customer1_city }})
                                <div class="text-caption text-grey">
                                    {{ t('AccountingView.customers.createdOn', { date: dup.customer1_created }) }} ·
                                    {{ t('AccountingView.customers.bookings', { count: dup.customer1_bookings }) }}
                                </div>
                            </div>
                            <v-icon class="mx-2">mdi-swap-horizontal</v-icon>
                            <div>
                                <strong>{{ dup.customer2_name }}</strong> ({{ dup.customer2_city }})
                                <div class="text-caption text-grey">
                                    {{ t('AccountingView.customers.createdOn', { date: dup.customer2_created }) }} ·
                                    {{ t('AccountingView.customers.bookings', { count: dup.customer2_bookings }) }}
                                </div>
                            </div>
                            <v-chip size="small" :color="dup.same_iban ? 'error' : 'warning'">
                                {{ dup.same_iban ? t('AccountingView.customers.sameIban') : (Math.round(dup.name_similarity * 100) + '% ' + t('AccountingView.customers.similarity')) }}
                            </v-chip>
                        </div>
                        <v-btn size="small" variant="outlined" color="primary" @click="openMergeDialog(dup)">
                            {{ t('AccountingView.customers.merge') }}
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Kunden-Tabelle -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-data-table
                    :headers="headers"
                    :items="customers"
                    :loading="loading"
                    density="compact"
                    :items-per-page="50"
                    :no-data-text="t('AccountingView.customers.noCustomers')"
                    @click:row="(_, { item }) => openCustomer(item)"
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

        <!-- Zusammenfuehren Dialog -->
        <v-dialog v-model="mergeDialog" max-width="580">
            <v-card>
                <v-card-title>{{ t('AccountingView.customers.merge') }}</v-card-title>
                <v-card-text>
                    <p class="mb-2">{{ t('AccountingView.customers.mergeConfirm') }}</p>
                    <v-radio-group v-model="mergeKeepId" hide-details>
                        <v-radio v-for="opt in mergeOptions" :key="opt.id" :value="opt.id">
                            <template #label>
                                <div>
                                    <strong>{{ opt.name }}</strong>
                                    <span v-if="opt.id === suggestedKeepId" class="text-caption text-primary ml-2">
                                        {{ t('AccountingView.customers.suggestion') }}
                                    </span>
                                    <div class="text-caption text-grey">
                                        {{ t('AccountingView.customers.createdOn', { date: opt.created }) }} ·
                                        {{ t('AccountingView.customers.bookings', { count: opt.bookings }) }}
                                    </div>
                                </div>
                            </template>
                        </v-radio>
                    </v-radio-group>

                    <v-checkbox v-if="mergedOption?.deletable" v-model="deleteMerged" hide-details density="compact" class="mt-2"
                                color="primary" :label="t('AccountingView.customers.deleteMerged', { name: mergedCustomerName })" />

                    <v-alert :type="deleteMerged ? 'warning' : 'info'" variant="tonal" density="compact" class="mt-3"
                             :text="deleteMerged
                                 ? t('AccountingView.customers.mergeExplainDelete', { keep: keptCustomerName, merged: mergedCustomerName })
                                 : t('AccountingView.customers.mergeExplain', { keep: keptCustomerName, merged: mergedCustomerName })" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="mergeDialog = false">{{ t('AccountingView.customers.cancel') }}</v-btn>
                    <v-btn :color="deleteMerged ? 'error' : 'primary'" variant="elevated" @click="doMerge">
                        {{ deleteMerged ? t('AccountingView.customers.mergeAndDelete') : t('AccountingView.customers.merge') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useCustomerMatching } from '../composables/useCustomerMatching.js'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const router = useRouter()
const { loading, error, customers, duplicates, fetchCustomers, mergeCustomers, findDuplicates } = useCustomerMatching()

const searchQuery = ref('')
const mergeDialog = ref(false)
const mergeDup = ref(null)
const mergeKeepId = ref(null)

// Beide Seiten der Dublette als Auswahlliste — mit Anlegedatum und
// Rechnungszahl, damit sichtbar ist, welcher Kunde der gewachsene ist.
const mergeOptions = computed(() => {
    const d = mergeDup.value
    if (!d) return []
    return [
        { id: d.customer1_id, name: d.customer1_name, created: d.customer1_created, bookings: Number(d.customer1_bookings || 0), deletable: d.customer1_deletable === true },
        { id: d.customer2_id, name: d.customer2_name, created: d.customer2_created, bookings: Number(d.customer2_bookings || 0), deletable: d.customer2_deletable === true }
    ]
})

// Vorschlag: der stärker bebuchte Kunde bleibt, bei Gleichstand der ältere
// (kleinere ID) — so müssen die wenigsten Belege umgehängt werden.
const suggestedKeepId = computed(() => {
    const [a, b] = mergeOptions.value
    if (!a || !b) return null
    if (a.bookings !== b.bookings) return a.bookings > b.bookings ? a.id : b.id
    return a.id < b.id ? a.id : b.id
})

const keptCustomerName = computed(() => mergeOptions.value.find(o => o.id === mergeKeepId.value)?.name || '')
const mergedOption = computed(() => mergeOptions.value.find(o => o.id !== mergeKeepId.value) || null)
const mergedCustomerName = computed(() => mergedOption.value?.name || '')

// Ein nie benutzter Doppeleintrag wird gelöscht statt stillgelegt — sonst
// bleibt er in jeder Kundenauswahl als Karteileiche stehen. Sobald am
// Eintrag irgendein Beleg hängt, ist die Option gar nicht erst wählbar.
const deleteMerged = ref(false)
watch(mergedOption, (opt) => { deleteMerged.value = !!opt?.deletable }, { immediate: true })

const headers = computed(() => [
    { title: t('AccountingView.customers.number'), key: 'customernumber', width: '100px' },
    { title: t('AccountingView.customers.name'), key: 'name' },
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

// Stammdaten werden im Kundenmodul gepflegt, nicht hier nachgebaut
function openCustomer(item) {
    router.push({ name: 'customer-edit', params: { id: Number(item.id) } })
}

function openMergeDialog(dup) {
    mergeDup.value = dup
    mergeKeepId.value = suggestedKeepId.value
    mergeDialog.value = true
}

async function doMerge() {
    const result = await mergeCustomers(mergeKeepId.value, mergedOption.value?.id, deleteMerged.value)
    if (result.success) {
        mergeDialog.value = false
        alerts.success(result.payload?.merged_deleted
            ? t('AccountingView.customers.deleteDone', {
                merged: result.payload?.merged_customer || '',
                keep: result.payload?.kept_customer || ''
            })
            : t('AccountingView.customers.mergeDone', {
                keep: result.payload?.kept_customer || '',
                count: (result.payload?.moved_invoices || 0) + (result.payload?.moved_bookings || 0)
            }))
        fetchCustomers(searchQuery.value)
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
    if (duplicates.value.length === 0) alerts.info(t('AccountingView.customers.noDuplicates'))
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

onMounted(() => {
    fetchCustomers()
})
</script>
