<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-2">{{ t('AccountingView.bookings.title') }}</h1>
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mt-1 mb-2" :text="t('AccountingView.bookings.info')" />
            </v-col>
        </v-row>

        <!-- Filter -->
        <v-row>
            <v-col cols="12" sm="6" md="2">
                <v-select v-model="filters.status" :items="statusOptions" :label="t('AccountingView.bookings.status')"
                          density="compact" variant="outlined" hide-details />
            </v-col>
            <v-col cols="12" sm="6" md="2">
                <v-select v-model="filters.type" :items="typeOptions" :label="t('AccountingView.bookings.type')"
                          density="compact" variant="outlined" hide-details />
            </v-col>
            <v-col cols="12" sm="6" md="2">
                <v-text-field v-model="filters.from_date" :label="t('AccountingView.bookings.fromDate')"
                              type="date" density="compact" variant="outlined" hide-details />
            </v-col>
            <v-col cols="12" sm="6" md="2">
                <v-text-field v-model="filters.to_date" :label="t('AccountingView.bookings.toDate')"
                              type="date" density="compact" variant="outlined" hide-details />
            </v-col>
            <v-col cols="12" sm="6" md="2">
                <v-btn color="primary" variant="elevated" @click="loadBookings" block>
                    <v-icon start>mdi-magnify</v-icon>
                    {{ t('AccountingView.bookings.filterAll') }}
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6" md="2" v-if="selected.length > 0">
                <v-btn color="success" variant="elevated" @click="approveBatch" block>
                    <v-icon start>mdi-check-all</v-icon>
                    {{ t('AccountingView.bookings.approveSelected') }} ({{ selected.length }})
                </v-btn>
            </v-col>
        </v-row>

        <!-- Statistik-Chips -->
        <v-row class="mt-1">
            <v-col cols="12">
                <v-chip class="mr-2" color="warning" variant="tonal" @click="filters.status = 'pending'; loadBookings()">
                    {{ t('AccountingView.bookings.statusPending') }}: {{ stats.pending_count || 0 }}
                </v-chip>
                <v-chip class="mr-2" color="success" variant="tonal" @click="filters.status = 'approved'; loadBookings()">
                    {{ t('AccountingView.bookings.statusApproved') }}: {{ stats.approved_count || 0 }}
                </v-chip>
                <v-chip class="mr-2" color="info" variant="tonal" @click="filters.status = 'booked'; loadBookings()">
                    {{ t('AccountingView.bookings.statusBooked') }}: {{ stats.booked_count || 0 }}
                </v-chip>
            </v-col>
        </v-row>

        <!-- Buchungen-Tabelle -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-data-table
                    v-model="selected"
                    :headers="headers"
                    :items="bookings"
                    :loading="loading"
                    density="compact"
                    :items-per-page="50"
                    show-select
                    item-value="id"
                    :no-data-text="t('AccountingView.bookings.noBookings')"
                    @click:row="(_, { item }) => openDetail(item)"
                >
                    <template #item.amount="{ item }">
                        <span :class="item.type === 'incoming' ? 'text-error' : 'text-success'" class="font-weight-medium">
                            {{ formatCurrency(item.amount) }}
                        </span>
                    </template>
                    <template #item.debit_account="{ item }">
                        <span class="text-caption">{{ item.debit_account }}</span>
                        <div class="text-caption text-grey">{{ item.debit_account_name }}</div>
                    </template>
                    <template #item.credit_account="{ item }">
                        <span class="text-caption">{{ item.credit_account }}</span>
                        <div class="text-caption text-grey">{{ item.credit_account_name }}</div>
                    </template>
                    <template #item.status="{ item }">
                        <v-chip :color="statusColor(item.status)" size="x-small">
                            {{ t('AccountingView.bookings.status' + capitalize(item.status)) }}
                        </v-chip>
                    </template>
                    <template #item.ai_generated="{ item }">
                        <v-tooltip v-if="item.ai_generated" :text="t('AccountingView.bookings.aiConfidence') + ': ' + Math.round((item.ai_confidence || 0) * 100) + '%'">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" color="purple" size="small">mdi-robot</v-icon>
                            </template>
                        </v-tooltip>
                    </template>
                    <template #item.actions="{ item }">
                        <v-btn v-if="item.status === 'pending'" icon size="x-small" color="success"
                               @click.stop="approveOne(item.id, item)" :title="t('AccountingView.bookings.approve')">
                            <v-icon>mdi-check</v-icon>
                        </v-btn>
                        <v-btn v-if="item.status === 'pending'" icon size="x-small" color="error" class="ml-1"
                               @click.stop="rejectOne(item.id)" :title="t('AccountingView.bookings.reject')">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                        <v-btn v-if="item.document_id" icon size="x-small" class="ml-1"
                               @click.stop="viewDocument(item.document_id)" :title="t('AccountingView.bookings.viewDocument')">
                            <v-icon>mdi-file-pdf-box</v-icon>
                        </v-btn>
                    </template>
                </v-data-table>
            </v-col>
        </v-row>

        <!-- Detail-Dialog -->
        <v-dialog v-model="detailDialog" max-width="800">
            <v-card v-if="selectedBooking">
                <v-card-title>
                    {{ selectedBooking.reference }} — {{ selectedBooking.description }}
                    <v-chip :color="statusColor(selectedBooking.status)" size="small" class="ml-2">
                        {{ t('AccountingView.bookings.status' + capitalize(selectedBooking.status)) }}
                    </v-chip>
                </v-card-title>
                <v-card-text>
                    <v-row>
                        <v-col cols="6" md="3">
                            <div class="text-caption text-grey">{{ t('AccountingView.bookings.date') }}</div>
                            <div>{{ selectedBooking.booking_date_fmt }}</div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="text-caption text-grey">{{ t('AccountingView.bookings.amount') }}</div>
                            <div class="font-weight-bold">{{ formatCurrency(selectedBooking.amount) }}</div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="text-caption text-grey">{{ t('AccountingView.bookings.debitAccount') }}</div>
                            <div>{{ selectedBooking.debit_account }} {{ selectedBooking.debit_account_name }}</div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="text-caption text-grey">{{ t('AccountingView.bookings.creditAccount') }}</div>
                            <div>{{ selectedBooking.credit_account }} {{ selectedBooking.credit_account_name }}</div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="text-caption text-grey">{{ t('AccountingView.bookings.invoiceNumber') }}</div>
                            <div>{{ selectedBooking.invoice_number || '—' }}</div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="text-caption text-grey">{{ t('AccountingView.bookings.taxRate') }}</div>
                            <div>{{ selectedBooking.tax_rate }}% ({{ t('AccountingView.bookings.taxKey') }}: {{ selectedBooking.tax_key }})</div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="text-caption text-grey">{{ t('AccountingView.bookings.vendor') }}</div>
                            <div>{{ selectedBooking.vendor_name || '—' }}</div>
                        </v-col>
                        <v-col cols="6" md="3">
                            <div class="text-caption text-grey">{{ t('AccountingView.bookings.netAmount') }} / {{ t('AccountingView.bookings.taxAmount') }}</div>
                            <div>{{ formatCurrency(selectedBooking.net_amount) }} / {{ formatCurrency(selectedBooking.tax_amount) }}</div>
                        </v-col>
                    </v-row>

                    <!-- KI-Hinweis -->
                    <v-alert v-if="selectedBooking.ai_generated" type="info" variant="tonal" density="compact" class="mt-4">
                        <v-icon start>mdi-robot</v-icon>
                        {{ t('AccountingView.bookings.aiGenerated') }} — {{ t('AccountingView.bookings.aiConfidence') }}: {{ Math.round((selectedBooking.ai_confidence || 0) * 100) }}%
                        <div v-if="selectedBooking.ai_notes" class="mt-1 text-body-2">{{ selectedBooking.ai_notes }}</div>
                    </v-alert>

                    <!-- Lieferant zuordnen (unsichere Erkennung) -->
                    <v-alert v-if="needsVendor" type="warning" variant="tonal" class="mt-4">
                        <div class="font-weight-medium mb-2">
                            <v-icon start>mdi-account-question-outline</v-icon>
                            {{ t('AccountingView.bookings.assignVendorTitle') }}
                            <span v-if="extractedVendorName" class="text-medium-emphasis"> — „{{ extractedVendorName }}"</span>
                        </div>
                        <div v-if="vendorCandidates.length" class="mb-2">
                            <span class="text-caption">{{ t('AccountingView.bookings.candidates') }}:</span>
                            <v-chip
                                v-for="c in vendorCandidates" :key="c.vendor_id"
                                class="ma-1" size="small"
                                :color="assignVendorId === c.vendor_id ? 'primary' : undefined"
                                :variant="assignVendorId === c.vendor_id ? 'flat' : 'outlined'"
                                @click="selectVendor(c.vendor_id, c.vendor_name)"
                            >
                                {{ c.vendor_name }} ({{ Math.round((c.match_score || 0) * 100) }}%)
                            </v-chip>
                        </div>
                        <v-autocomplete
                            v-model="assignVendorId"
                            :items="vendorOptions"
                            :item-title="v => v.vendornumber ? `${v.name} (${v.vendornumber})` : v.name"
                            item-value="id"
                            :label="t('AccountingView.bookings.searchVendor')"
                            density="compact" variant="outlined" hide-details
                            :loading="vendorLoading" no-filter clearable
                            prepend-inner-icon="mdi-magnify"
                            @update:search="onVendorSearch"
                        />
                    </v-alert>

                    <!-- Positionen -->
                    <div v-if="selectedBooking.lines && selectedBooking.lines.length > 0" class="mt-4">
                        <h3 class="text-subtitle-1 mb-2">{{ t('AccountingView.bookings.lines') }}</h3>
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>Pos.</th>
                                    <th>{{ t('AccountingView.bookings.description') }}</th>
                                    <th class="text-end">{{ t('AccountingView.bookings.netAmount') }}</th>
                                    <th class="text-end">{{ t('AccountingView.bookings.taxRate') }}</th>
                                    <th class="text-end">{{ t('AccountingView.bookings.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in selectedBooking.lines" :key="line.id">
                                    <td>{{ line.position }}</td>
                                    <td>{{ line.description }}</td>
                                    <td class="text-end">{{ formatCurrency(line.net_amount) }}</td>
                                    <td class="text-end">{{ line.tax_rate }}%</td>
                                    <td class="text-end">{{ formatCurrency(line.gross_amount) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-btn v-if="selectedBooking.document_id" variant="text" @click="viewDocument(selectedBooking.document_id)">
                        <v-icon start>mdi-file-pdf-box</v-icon>
                        {{ t('AccountingView.bookings.viewDocument') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn v-if="selectedBooking.status === 'pending'" color="error" variant="text" @click="rejectOne(selectedBooking.id)">
                        {{ t('AccountingView.bookings.reject') }}
                    </v-btn>
                    <v-btn v-if="selectedBooking.status === 'pending'" color="success" variant="elevated"
                           :disabled="needsVendor && !assignVendorId" @click="approveOne(selectedBooking.id)">
                        <v-icon start>mdi-check</v-icon>
                        {{ needsVendor ? t('AccountingView.bookings.assignAndBook') : t('AccountingView.bookings.approve') }}
                    </v-btn>
                    <v-btn variant="text" @click="detailDialog = false">
                        {{ t('AccountingView.bookings.cancel') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useRouter, useRoute } from 'vue-router'
import { useAccounting } from '../composables/useAccounting.js'
import * as toasts from '@/core/utils/toasts.js'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const { loading, bookings, stats, fetchBookings, fetchBooking, approveBooking, approveBookingsBatch, rejectBooking, searchVendors } = useAccounting()

const selected = ref([])
const detailDialog = ref(false)
const selectedBooking = ref(null)

// Kandidaten-Picker (Lieferant zuordnen bei unsicherer Erkennung)
const assignVendorId = ref(null)
const vendorOptions  = ref([])
const vendorLoading  = ref(false)
let   vendorTimer    = null

const needsVendor = computed(() =>
    selectedBooking.value?.status === 'pending' && !selectedBooking.value?.vendor_id
)
const vendorCandidates = computed(() =>
    selectedBooking.value?.extracted_data?.vendor_resolution?.candidates ?? []
)
const extractedVendorName = computed(() =>
    selectedBooking.value?.extracted_data?.vendor_resolution?.original_name
    || selectedBooking.value?.extracted_data?.vendor?.name || ''
)

function selectVendor(id, name) {
    assignVendorId.value = id
    if (!vendorOptions.value.find(v => v.id === id)) {
        vendorOptions.value = [{ id, name }, ...vendorOptions.value]
    }
}
function onVendorSearch(q) {
    clearTimeout(vendorTimer)
    if (!q || q.length < 2) return
    vendorTimer = setTimeout(async () => {
        vendorLoading.value = true
        vendorOptions.value = await searchVendors(q)
        vendorLoading.value = false
    }, 300)
}

const filters = ref({
    status: route.query.status || 'all',
    type: 'all',
    from_date: '',
    to_date: '',
    limit: 100
})

const statusOptions = computed(() => [
    { title: t('AccountingView.bookings.filterAll'), value: 'all' },
    { title: t('AccountingView.bookings.statusPending'), value: 'pending' },
    { title: t('AccountingView.bookings.statusApproved'), value: 'approved' },
    { title: t('AccountingView.bookings.statusBooked'), value: 'booked' },
    { title: t('AccountingView.bookings.statusRejected'), value: 'rejected' }
])

const typeOptions = computed(() => [
    { title: t('AccountingView.bookings.filterAll'), value: 'all' },
    { title: t('AccountingView.bookings.typeIncoming'), value: 'incoming' },
    { title: t('AccountingView.bookings.typeOutgoing'), value: 'outgoing' },
    { title: t('AccountingView.bookings.typeManual'), value: 'manual' },
    { title: t('AccountingView.bookings.typeBank'), value: 'bank' }
])

const headers = computed(() => [
    { title: t('AccountingView.bookings.date'), key: 'booking_date_fmt', width: '100px' },
    { title: t('AccountingView.bookings.reference'), key: 'reference', width: '140px' },
    { title: t('AccountingView.bookings.description'), key: 'description' },
    { title: t('AccountingView.bookings.vendor'), key: 'vendor_name' },
    { title: t('AccountingView.bookings.debitAccount'), key: 'debit_account', width: '120px' },
    { title: t('AccountingView.bookings.creditAccount'), key: 'credit_account', width: '120px' },
    { title: t('AccountingView.bookings.amount'), key: 'amount', align: 'end', width: '120px' },
    { title: t('AccountingView.bookings.status'), key: 'status', width: '100px' },
    { title: 'KI', key: 'ai_generated', width: '40px', align: 'center' },
    { title: '', key: 'actions', width: '120px', sortable: false }
])

async function loadBookings() {
    await fetchBookings(filters.value)
}

async function openDetail(item) {
    assignVendorId.value = null
    vendorOptions.value = []
    selectedBooking.value = await fetchBooking(item.id)
    // Kandidaten als Auswahl vorbereiten
    vendorOptions.value = (selectedBooking.value?.extracted_data?.vendor_resolution?.candidates ?? [])
        .map(c => ({ id: c.vendor_id, name: c.vendor_name }))
    detailDialog.value = true
}

async function approveOne(id, item = null) {
    // Inline-Freigabe einer Buchung ohne Lieferant → Detail öffnen, damit zugeordnet werden kann
    if (item && item.status === 'pending' && !item.vendor_id) {
        await openDetail(item)
        return
    }
    const extra = (needsVendor.value && assignVendorId.value) ? { vendor_id: assignVendorId.value } : {}
    const result = await approveBooking(id, extra)
    if (result.success) {
        toasts.success(t('AccountingView.bookings.bookedSuccess'))
        await loadBookings()
        detailDialog.value = false
    } else if (result.text) {
        toasts.error(result.text)   // z. B. "Aufwandskonto … nicht im Kontenrahmen"
    }
}

async function rejectOne(id) {
    const result = await rejectBooking(id)
    if (result.success) {
        await loadBookings()
        detailDialog.value = false
    }
}

async function approveBatch() {
    const result = await approveBookingsBatch(selected.value)
    if (result.success) {
        selected.value = []
        await loadBookings()
    }
}

function viewDocument(docId) {
    window.open(`/api/accounting/?action=getDocumentPdf&document_id=${docId}`, '_blank')
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

function statusColor(status) {
    const colors = { pending: 'warning', approved: 'success', booked: 'info', rejected: 'error' }
    return colors[status] || 'default'
}

function capitalize(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''
}

onMounted(() => {
    loadBookings()
})
</script>
