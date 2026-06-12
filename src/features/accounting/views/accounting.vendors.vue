<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-2">{{ t('AccountingView.vendors.title') }}</h1>
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
                <v-btn color="primary" variant="elevated" @click="newVendorDialog = true">
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
                    <v-card-text class="d-flex align-center">
                        <div class="flex-grow-1">
                            <strong>{{ dup.vendor1_name }}</strong> ({{ dup.vendor1_city }})
                            <v-icon class="mx-2">mdi-swap-horizontal</v-icon>
                            <strong>{{ dup.vendor2_name }}</strong> ({{ dup.vendor2_city }})
                            <v-chip class="ml-2" size="small" :color="dup.same_iban ? 'error' : 'warning'">
                                {{ dup.same_iban ? 'Gleiche IBAN' : (Math.round(dup.name_similarity * 100) + '% ' + t('AccountingView.vendors.similarity')) }}
                            </v-chip>
                        </div>
                        <v-btn size="small" variant="outlined" color="primary"
                               @click="openMergeDialog(dup.vendor1_id, dup.vendor1_name, dup.vendor2_id, dup.vendor2_name)">
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
                <v-card-title>{{ t('AccountingView.vendors.newVendor') }}</v-card-title>
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
                    <v-btn variant="text" @click="newVendorDialog = false">{{ t('AccountingView.vendors.cancel') }}</v-btn>
                    <v-btn v-if="duplicateWarning" color="warning" variant="elevated" @click="saveVendor(true)">
                        {{ t('AccountingView.vendors.forceCreate') }}
                    </v-btn>
                    <v-btn color="primary" variant="elevated" @click="saveVendor(false)">
                        {{ t('AccountingView.vendors.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Zusammenfuehren Dialog -->
        <v-dialog v-model="mergeDialog" max-width="500">
            <v-card>
                <v-card-title>{{ t('AccountingView.vendors.merge') }}</v-card-title>
                <v-card-text>
                    <p>{{ t('AccountingView.vendors.mergeConfirm') }}</p>
                    <v-radio-group v-model="mergeKeepId">
                        <v-radio :label="mergeVendor1Name + ' (' + t('AccountingView.vendors.keep') + ')'" :value="mergeVendor1Id" />
                        <v-radio :label="mergeVendor2Name + ' (' + t('AccountingView.vendors.keep') + ')'" :value="mergeVendor2Id" />
                    </v-radio-group>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="mergeDialog = false">{{ t('AccountingView.vendors.cancel') }}</v-btn>
                    <v-btn color="primary" variant="elevated" @click="doMerge">{{ t('AccountingView.vendors.merge') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useVendorMatching } from '../composables/useVendorMatching.js'

const { t } = useI18n()
const { loading, vendors, duplicates, fetchVendors, createVendor, mergeVendors, findDuplicates } = useVendorMatching()

const searchQuery = ref('')
const newVendorDialog = ref(false)
const mergeDialog = ref(false)
const duplicateWarning = ref(null)

const vendorForm = ref({
    name: '', street: '', zipcode: '', city: '', iban: '',
    taxnumber: '', ustid: '', email: '', phone: ''
})

const mergeVendor1Id = ref(null)
const mergeVendor1Name = ref('')
const mergeVendor2Id = ref(null)
const mergeVendor2Name = ref('')
const mergeKeepId = ref(null)

const headers = computed(() => [
    { title: t('AccountingView.vendors.number'), key: 'vendornumber', width: '100px' },
    { title: t('AccountingView.vendors.name'), key: 'name' },
    { title: t('AccountingView.vendors.city'), key: 'city', width: '120px' },
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
    const result = await createVendor({ ...vendorForm.value, force })

    if (result.success) {
        newVendorDialog.value = false
        vendorForm.value = { name: '', street: '', zipcode: '', city: '', iban: '', taxnumber: '', ustid: '', email: '', phone: '' }
        fetchVendors(searchQuery.value)
    } else if (result.text === 'POSSIBLE_DUPLICATES') {
        duplicateWarning.value = result.payload?.duplicates || []
    }
}

function openEditDialog(item) {
    // Lieferant zur Bearbeitung oeffnen
    vendorForm.value = { ...item }
    newVendorDialog.value = true
}

function openMergeDialog(id1, name1, id2, name2) {
    mergeVendor1Id.value = id1
    mergeVendor1Name.value = name1
    mergeVendor2Id.value = id2
    mergeVendor2Name.value = name2
    mergeKeepId.value = id1
    mergeDialog.value = true
}

async function doMerge() {
    const mergeId = mergeKeepId.value === mergeVendor1Id.value ? mergeVendor2Id.value : mergeVendor1Id.value
    const result = await mergeVendors(mergeKeepId.value, mergeId)
    if (result.success) {
        mergeDialog.value = false
        fetchVendors(searchQuery.value)
        findDuplicates()
    }
}

async function onFindDuplicates() {
    await findDuplicates()
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

onMounted(() => {
    fetchVendors()
})
</script>
