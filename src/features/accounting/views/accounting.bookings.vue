<template>
    <NavbarView />
    <v-container fluid>
        <AccountingPageHeader :title="t('AccountingView.bookings.title')" />

        <v-row>
            <v-col cols="12">
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mt-1 mb-2"
                         :text="viewMode === 'journal' ? t('AccountingView.bookings.infoJournal') : t('AccountingView.bookings.info')" />
            </v-col>
        </v-row>

        <!-- Umschalter: echtes Hauptbuch-Journal vs. KI-Belegvorschläge -->
        <v-row>
            <v-col cols="12" class="d-flex align-center flex-wrap ga-2">
                <v-btn-toggle v-model="viewMode" mandatory density="comfortable" color="primary" variant="outlined">
                    <v-btn value="journal"><v-icon start>mdi-book-open-variant</v-icon>{{ t('AccountingView.bookings.modeJournal') }}</v-btn>
                    <v-btn value="ki"><v-icon start>mdi-robot</v-icon>{{ t('AccountingView.bookings.modeKi') }}</v-btn>
                </v-btn-toggle>
                <v-spacer />
                <v-btn color="primary" variant="tonal" :to="{ name: 'accounting-invoice-manual' }">
                    <v-icon start>mdi-file-document-plus</v-icon>{{ t('AccountingView.bookings.manualEntry') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- ===================== JOURNAL (echtes kivitendo-Hauptbuch) ===================== -->
        <template v-if="viewMode === 'journal'">
            <v-row>
                <v-col cols="12" sm="6" md="2">
                    <v-select v-model="journalFilters.type" :items="journalTypeOptions" :label="t('AccountingView.bookings.type')"
                              density="compact" variant="outlined" hide-details @update:model-value="loadJournal" />
                </v-col>
                <v-col cols="12" sm="6" md="2">
                    <v-text-field v-model="journalFilters.from_date" :label="t('AccountingView.bookings.fromDate')"
                                  type="date" density="compact" variant="outlined" hide-details />
                </v-col>
                <v-col cols="12" sm="6" md="2">
                    <v-text-field v-model="journalFilters.to_date" :label="t('AccountingView.bookings.toDate')"
                                  type="date" density="compact" variant="outlined" hide-details />
                </v-col>
                <v-col cols="12" sm="6" md="2">
                    <v-btn color="primary" variant="elevated" @click="loadJournal" block>
                        <v-icon start>mdi-magnify</v-icon>{{ t('AccountingView.bookings.filterAll') }}
                    </v-btn>
                </v-col>
            </v-row>

            <v-row class="mt-1">
                <v-col cols="12">
                    <v-chip class="mr-2" color="success" variant="tonal">
                        {{ t('AccountingView.bookings.typeOutgoing') }}: {{ journalStats.count_outgoing || 0 }} · {{ formatCurrency(journalStats.sum_outgoing) }}
                    </v-chip>
                    <v-chip class="mr-2" color="error" variant="tonal">
                        {{ t('AccountingView.bookings.typeIncoming') }}: {{ journalStats.count_incoming || 0 }} · {{ formatCurrency(journalStats.sum_incoming) }}
                    </v-chip>
                    <v-chip class="mr-2" color="info" variant="tonal">
                        {{ t('AccountingView.bookings.typeManual') }}: {{ journalStats.count_manual || 0 }}
                    </v-chip>
                    <v-chip class="mr-2" variant="tonal">
                        {{ t('AccountingView.bookings.totalBookings', { count: total }) }}
                    </v-chip>
                    <span v-if="journal.length < total" class="text-caption text-warning ml-1">
                        <v-icon size="14">mdi-alert-outline</v-icon>
                        {{ t('AccountingView.bookings.journalTruncated', { shown: journal.length, total }) }}
                    </span>
                </v-col>
            </v-row>

            <v-row class="mt-2">
                <v-col cols="12">
                    <v-data-table
                        :headers="journalHeaders"
                        :items="journal"
                        :loading="loading"
                        density="compact"
                        :items-per-page="50"
                        :no-data-text="t('AccountingView.bookings.noJournal')"
                        @click:row="(_, { item }) => openJournalDetail(item)"
                    >
                        <template #item.type="{ item }">
                            <v-chip :color="journalTypeColor(item.type)" size="x-small">
                                {{ t('AccountingView.bookings.type' + capitalize(item.type)) }}
                            </v-chip>
                        </template>
                        <template #item.amount="{ item }">
                            <span :class="item.type === 'incoming' ? 'text-error' : 'text-success'" class="font-weight-medium">
                                {{ formatCurrency(item.amount) }}
                            </span>
                        </template>
                        <template #item.open_amount="{ item }">
                            <span v-if="item.open_amount !== null && Number(item.open_amount) > 0.005" class="text-warning">
                                {{ formatCurrency(item.open_amount) }}
                            </span>
                            <span v-else class="text-grey">—</span>
                        </template>
                    </v-data-table>
                </v-col>
            </v-row>
        </template>

        <!-- ===================== KI-VORSCHLÄGE (accounting_bookings) ===================== -->
        <template v-if="viewMode === 'ki'">
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
        </template>

        <!-- Journal-Detail-Dialog (Soll/Haben aus acc_trans) -->
        <v-dialog v-model="journalDialog" max-width="760">
            <v-card v-if="journalDetail">
                <v-card-title class="text-body-1">
                    {{ journalDetail.head?.reference || '—' }}
                    <span v-if="journalDetail.head?.partner"> — {{ journalDetail.head.partner }}</span>
                </v-card-title>
                <v-card-text>
                    <div class="d-flex justify-space-between align-center text-caption text-grey mb-2">
                        <span>{{ journalDetail.head?.transdate_fmt }} · {{ journalDetail.head?.description }}</span>
                        <span v-if="journalDetail.head?.amount != null" class="text-body-2 font-weight-medium">
                            {{ formatCurrency(journalDetail.head.amount) }}
                        </span>
                    </div>
                    <v-table v-if="journalDetail.lines && journalDetail.lines.length" density="compact">
                        <thead>
                            <tr>
                                <th>{{ t('AccountingView.bookings.account') }}</th>
                                <th class="text-end">{{ t('AccountingView.bookings.debit') }}</th>
                                <th class="text-end">{{ t('AccountingView.bookings.credit') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in journalDetail.lines" :key="l.acc_trans_id">
                                <td>
                                    {{ l.accno }} <span class="text-grey">{{ l.account_name }}</span>
                                    <v-chip v-if="l.is_tax" size="x-small" class="ml-1" color="grey">{{ t('AccountingView.bookings.taxLine') }}</v-chip>
                                </td>
                                <td class="text-end">{{ Number(l.soll) > 0 ? formatCurrency(l.soll) : '' }}</td>
                                <td class="text-end">{{ Number(l.haben) > 0 ? formatCurrency(l.haben) : '' }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-alert v-else type="info" variant="tonal" density="compact" icon="mdi-information-outline">
                        {{ t('AccountingView.bookings.noLines') }}
                    </v-alert>

                    <!-- Gescannter Beleg zur Buchung — bei einer Pruefung wird
                         genau von hier aus nachgesehen. -->
                    <v-alert v-if="journalDetail.document" type="success" variant="tonal"
                             density="compact" class="mt-3" icon="mdi-paperclip">
                        <div class="d-flex align-center flex-wrap" style="gap: 8px">
                            <div>
                                <div class="font-weight-medium">{{ journalDetail.document.original_name }}</div>
                                <div class="text-caption">
                                    {{ t('AccountingView.bookings.documentScanned', { date: journalDetail.document.uploaded_fmt }) }}
                                </div>
                            </div>
                            <v-spacer />
                            <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-file-pdf-box"
                                   @click="viewDocument(journalDetail.document.id)">
                                {{ t('AccountingView.bookings.viewDocument') }}
                            </v-btn>
                        </div>
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-btn v-if="journalRef.src === 'ar'" color="primary" variant="text" @click="openInvoiceFromJournal">
                        <v-icon start>mdi-file-document-outline</v-icon>
                        {{ t('AccountingView.bookings.openInvoice') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn variant="text" @click="journalDialog = false">{{ t('AccountingView.bookings.cancel') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

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

                    <!-- Korrektur vor der Freigabe. Nur solange der Beleg noch nicht
                         im Hauptbuch steht — danach ginge die Änderung an ap/acc_trans
                         vorbei und der DATEV-Export wiche vom Hauptbuch ab. -->
                    <div v-if="selectedBooking.status === 'pending'" class="mt-4">
                        <div class="d-flex align-center mb-2">
                            <h3 class="text-subtitle-1 font-weight-bold">{{ t('AccountingView.bookings.editTitle') }}</h3>
                            <v-spacer />
                            <v-btn v-if="!editMode" size="small" variant="tonal" color="primary"
                                   prepend-icon="mdi-pencil" @click="startEdit">
                                {{ t('AccountingView.bookings.edit') }}
                            </v-btn>
                        </div>

                        <v-row v-if="editMode" dense>
                            <v-col cols="12" md="6">
                                <v-autocomplete
                                    v-model="editForm.debit_account"
                                    :items="accountOptions"
                                    item-title="label" item-value="accno"
                                    :label="t('AccountingView.bookings.debitAccount')"
                                    density="comfortable" variant="outlined" hide-details
                                    :loading="accountLoading" no-filter
                                    prepend-inner-icon="mdi-magnify"
                                    @update:search="onAccountSearch" />
                            </v-col>
                            <v-col cols="12" md="6">
                                <v-text-field v-model="editForm.description"
                                              :label="t('AccountingView.bookings.description')"
                                              density="comfortable" variant="outlined" hide-details />
                            </v-col>
                            <v-col cols="6" md="3">
                                <v-text-field v-model.number="editForm.net_amount" type="number" step="0.01"
                                              :label="t('AccountingView.bookings.netAmount')"
                                              density="comfortable" variant="outlined" hide-details
                                              @update:model-value="recalcGross" />
                            </v-col>
                            <v-col cols="6" md="3">
                                <v-text-field v-model.number="editForm.tax_amount" type="number" step="0.01"
                                              :label="t('AccountingView.bookings.taxAmount')"
                                              density="comfortable" variant="outlined" hide-details
                                              @update:model-value="recalcGross" />
                            </v-col>
                            <v-col cols="6" md="3">
                                <!-- Brutto ergibt sich aus Netto + Steuer und ist deshalb gesperrt -->
                                <v-text-field :model-value="formatCurrency(editForm.amount)"
                                              :label="t('AccountingView.bookings.amount')"
                                              density="comfortable" variant="outlined" hide-details readonly />
                            </v-col>
                            <v-col cols="6" md="3">
                                <v-text-field v-model.number="editForm.tax_rate" type="number" step="0.01"
                                              :label="t('AccountingView.bookings.taxRate')"
                                              density="comfortable" variant="outlined" hide-details />
                            </v-col>
                            <v-col cols="12" class="d-flex">
                                <v-spacer />
                                <v-btn variant="text" class="mr-2" @click="editMode = false">
                                    {{ t('AccountingView.bookings.cancel') }}
                                </v-btn>
                                <v-btn color="primary" variant="elevated" :loading="saving" @click="saveEdit">
                                    {{ t('AccountingView.bookings.saveEdit') }}
                                </v-btn>
                            </v-col>
                        </v-row>
                        <p v-else class="text-caption text-medium-emphasis">
                            {{ t('AccountingView.bookings.editHint') }}
                        </p>
                    </div>

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
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useRouter, useRoute } from 'vue-router'
import { useAccounting } from '../composables/useAccounting.js'
import * as toasts from '@/core/utils/toasts.js'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const { loading, bookings, stats, total, journal, journalStats, fetchJournal, fetchJournalEntry,
        fetchBookings, fetchBooking, approveBooking, approveBookingsBatch, rejectBooking, searchVendors,
        searchAccounts, updateBooking } = useAccounting()

const selected = ref([])
const detailDialog = ref(false)
const selectedBooking = ref(null)

// Ansicht: echtes Hauptbuch-Journal (Standard) oder KI-Belegvorschläge
const viewMode = ref('journal')
const journalDialog = ref(false)
const journalDetail = ref(null)
const journalRef = ref({})   // src + id der geöffneten Buchung (für „Rechnung öffnen")
const journalFilters = ref({ type: 'all', from_date: '', to_date: '', limit: 5000 })

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

// ── Korrektur eines offenen Belegs vor der Freigabe ──────────────────────────
const editMode       = ref(false)
const saving         = ref(false)
const editForm       = ref({})
const accountOptions = ref([])
const accountLoading = ref(false)
let   accountTimer   = null

function startEdit() {
    const b = selectedBooking.value
    editForm.value = {
        debit_account: b.debit_account || '',
        description:   b.description || '',
        net_amount:    Number(b.net_amount ?? 0),
        tax_amount:    Number(b.tax_amount ?? 0),
        amount:        Number(b.amount ?? 0),
        tax_rate:      Number(b.tax_rate ?? 0)
    }
    // Aktuelles Konto vorbelegen, damit die Auswahl es anzeigt statt nur der Nummer
    accountOptions.value = b.debit_account
        ? [{ accno: b.debit_account, label: b.debit_account + ' ' + (b.debit_account_name || '') }]
        : []
    editMode.value = true
}

// Brutto ist keine Eingabe, sondern folgt aus Netto + Steuer — sonst kann die
// Buchung mit sich selbst uneins sein.
function recalcGross() {
    const net = Number(editForm.value.net_amount) || 0
    const tax = Number(editForm.value.tax_amount) || 0
    editForm.value.amount = Math.round((net + tax) * 100) / 100
}

function onAccountSearch(q) {
    clearTimeout(accountTimer)
    if (!q || q.length < 1) return
    accountTimer = setTimeout(async () => {
        accountLoading.value = true
        const rows = await searchAccounts(q)
        accountOptions.value = rows.map(a => ({ accno: a.accno, label: a.accno + ' ' + a.description }))
        accountLoading.value = false
    }, 300)
}

async function saveEdit() {
    saving.value = true
    const result = await updateBooking(selectedBooking.value.id, {
        debit_account: editForm.value.debit_account,
        description:   editForm.value.description,
        net_amount:    editForm.value.net_amount,
        tax_amount:    editForm.value.tax_amount,
        amount:        editForm.value.amount,
        tax_rate:      editForm.value.tax_rate
    })
    saving.value = false
    if (!result.success) {
        toasts.error(result.text || t('AccountingView.bookings.saveError'))
        return
    }
    toasts.success(t('AccountingView.bookings.saved'))
    editMode.value = false
    // Dialog UND Liste auf den gespeicherten Stand bringen
    selectedBooking.value = await fetchBooking(selectedBooking.value.id)
    await loadBookings()
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

const journalTypeOptions = computed(() => [
    { title: t('AccountingView.bookings.filterAll'), value: 'all' },
    { title: t('AccountingView.bookings.typeOutgoing'), value: 'outgoing' },
    { title: t('AccountingView.bookings.typeIncoming'), value: 'incoming' },
    { title: t('AccountingView.bookings.typeManual'), value: 'manual' }
])

const journalHeaders = computed(() => [
    { title: t('AccountingView.bookings.date'), key: 'transdate_fmt', width: '100px' },
    { title: t('AccountingView.bookings.reference'), key: 'reference', width: '120px' },
    { title: t('AccountingView.bookings.partner'), key: 'partner' },
    { title: t('AccountingView.bookings.description'), key: 'description' },
    { title: t('AccountingView.bookings.type'), key: 'type', width: '110px' },
    { title: t('AccountingView.bookings.amount'), key: 'amount', align: 'end', width: '120px' },
    { title: t('AccountingView.bookings.open'), key: 'open_amount', align: 'end', width: '110px' }
])

function journalTypeColor(type) {
    const colors = { outgoing: 'success', incoming: 'error', manual: 'info' }
    return colors[type] || 'default'
}

async function loadJournal() {
    await fetchJournal(journalFilters.value)
}

async function openJournalDetail(item) {
    journalRef.value = { src: item.src, id: item.id }
    journalDetail.value = await fetchJournalEntry(item.id, item.src)
    if (journalDetail.value) journalDialog.value = true
}

// Aus der Buchung zur echten Rechnung springen (nur Ausgangsrechnungen)
function openInvoiceFromJournal() {
    if (journalRef.value.src === 'ar' && journalRef.value.id) {
        router.push({ name: 'faktura-invoice-view', params: { id: journalRef.value.id } })
    }
}

async function loadBookings() {
    await fetchBookings(filters.value)
}

async function openDetail(item) {
    editMode.value = false
    assignVendorId.value = null
    vendorOptions.value = []
    selectedBooking.value = await fetchBooking(item.id)
    // Kandidaten als Auswahl vorbereiten
    vendorOptions.value = (selectedBooking.value?.extracted_data?.vendor_resolution?.candidates ?? [])
        .map(c => ({ id: c.vendor_id, name: c.vendor_name }))
    detailDialog.value = true
}

async function approveOne(id, item = null) {
    // Inline-Freigabe einer Buchung ohne Lieferant → Detail öffnen, damit zugeordnet werden kann.
    // Ohne Hinweis wirkt der Klick, als würde nichts passieren – deshalb ein Info-Toast.
    if (item && item.status === 'pending' && !item.vendor_id) {
        toasts.info(t('AccountingView.bookings.needVendorHint'))
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
        // Fehlt das Aufwandskonto, direkt in die Bearbeitung springen statt den Nutzer raten zu lassen.
        if (/Aufwandskonto|ACCOUNT_REQUIRED/i.test(result.text) && selectedBooking.value?.id === id) {
            startEdit()
        }
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

// Beim Wechsel die jeweilige Ansicht (nach)laden
watch(viewMode, (mode) => {
    if (mode === 'journal') loadJournal()
    else loadBookings()
})

onMounted(async () => {
    // Deep-Link vom Dashboard: eine bestimmte Buchung direkt öffnen (?src=..&id=..)
    if (route.query.src && route.query.id) {
        viewMode.value = 'journal'
        await loadJournal()
        journalRef.value = { src: route.query.src, id: route.query.id }
        journalDetail.value = await fetchJournalEntry(route.query.id, route.query.src)
        if (journalDetail.value) journalDialog.value = true
        return
    }
    // Kommt man über eine Status-Kachel (z. B. „offene Vorschläge"), direkt in die KI-Ansicht
    if (route.query.status) {
        filters.value.status = route.query.status
        viewMode.value = 'ki'
        loadBookings()
    } else {
        loadJournal()
    }
})
</script>
