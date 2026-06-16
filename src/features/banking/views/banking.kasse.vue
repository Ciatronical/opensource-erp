<template>
    <NavbarView />
    <v-container fluid>

        <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mb-3" :text="t('KasseView.info')" />

        <!-- Header -->
        <v-row align="center" class="mb-3">
            <v-col>
                <h1 class="text-h5">
                    <v-icon start>mdi-cash-register</v-icon>
                    {{ t('KasseView.title') }}
                    <span v-if="selectedRegister" class="text-body-2 text-disabled ml-2">
                        {{ selectedRegister.chart_description }} · {{ t('KasseView.chartAccount') }} {{ selectedRegister.chart_accno }}
                    </span>
                </h1>
            </v-col>
            <v-col v-if="registers.length > 1" cols="auto">
                <v-select
                    v-model="selectedRegisterId"
                    :items="registers"
                    :item-title="r => `${r.chart_accno} – ${r.name}`"
                    item-value="id"
                    density="compact"
                    hide-details
                    variant="outlined"
                    style="min-width:240px"
                    prepend-inner-icon="mdi-cash-register"
                />
            </v-col>
            <v-col cols="auto">
                <v-btn
                    v-if="selectedRegister"
                    color="primary"
                    variant="flat"
                    size="small"
                    @click="openNewTransaction()"
                >
                    <v-icon start>mdi-cash-plus</v-icon>
                    {{ t('KasseView.newTransaction') }}
                </v-btn>
                <v-progress-circular v-else-if="loading" indeterminate size="24" />
            </v-col>
        </v-row>

        <v-alert
            v-if="!loading && registers.length === 0"
            type="info"
            variant="tonal"
            class="mb-4"
        >
            {{ t('KasseView.noKassaAccount') }}
        </v-alert>

        <!-- Statistik-Karten -->
        <v-row v-if="selectedRegister" class="mb-3">
            <v-col cols="12" sm="4">
                <v-card :color="(selectedRegister.balance ?? 0) >= 0 ? 'primary' : 'error'" variant="tonal">
                    <v-card-text class="text-center py-3">
                        <div class="text-h5 font-weight-bold">{{ formatCurrency(selectedRegister.balance) }}</div>
                        <div class="text-body-2">{{ t('KasseView.balance') }}</div>
                        <div class="text-caption text-disabled mt-1">
                            {{ t('KasseView.chartAccount') }}: {{ selectedRegister.chart_accno }}
                            <span v-if="selectedRegister.last_transaction_date"> · {{ t('KasseView.lastEntry') }}: {{ formatDateShort(selectedRegister.last_transaction_date) }}</span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="4">
                <v-card color="success" variant="tonal">
                    <v-card-text class="text-center py-3">
                        <v-icon size="24" class="mb-1">mdi-arrow-down-bold</v-icon>
                        <div class="text-h6 font-weight-bold">{{ formatCurrency(selectedRegister.income_this_month) }}</div>
                        <div class="text-body-2">{{ t('KasseView.incomeThisMonth') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="4">
                <v-card color="warning" variant="tonal">
                    <v-card-text class="text-center py-3">
                        <v-icon size="24" class="mb-1">mdi-arrow-up-bold</v-icon>
                        <div class="text-h6 font-weight-bold">{{ formatCurrency(selectedRegister.expenses_this_month) }}</div>
                        <div class="text-body-2">{{ t('KasseView.expensesThisMonth') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Tabs -->
        <v-tabs v-if="selectedRegister" v-model="activeTab" color="primary" class="mb-4">
            <v-tab value="transactions" prepend-icon="mdi-book-open-variant">
                {{ t('KasseView.tabCashbook') }}
            </v-tab>
            <v-tab value="invoices" prepend-icon="mdi-file-document-outline">
                {{ t('KasseView.tabInvoices') }}
            </v-tab>
            <v-tab value="payables" prepend-icon="mdi-file-document-arrow-right-outline">
                {{ t('KasseView.tabPayables') }}
            </v-tab>
        </v-tabs>

        <v-window v-if="selectedRegister" v-model="activeTab">

            <!-- ── KASSENBUCH ── -->
            <v-window-item value="transactions">
                <div class="tab-toolbar mb-3">
                    <!-- Zeitraum-Modus: Jahr (Standard) / Monat / Gesamt -->
                    <v-btn-toggle v-model="periodMode" mandatory density="compact" variant="outlined" rounded="lg">
                        <v-btn value="year" size="small">{{ t('KasseView.modeYear') }}</v-btn>
                        <v-btn value="month" size="small">{{ t('KasseView.modeMonth') }}</v-btn>
                        <v-btn value="all" size="small">{{ t('KasseView.wholePeriod') }}</v-btn>
                    </v-btn-toggle>

                    <!-- Jahresnavigation -->
                    <div v-if="periodMode === 'year'" class="d-flex align-center">
                        <v-btn icon="mdi-chevron-left" variant="text" size="small" @click="shiftYear(-1)" />
                        <div class="text-subtitle-1 font-weight-medium text-center" style="min-width:90px">
                            {{ periodYear }}
                        </div>
                        <v-btn icon="mdi-chevron-right" variant="text" size="small" @click="shiftYear(1)" />
                    </div>
                    <!-- Monatsnavigation (wie bei Lexware) -->
                    <div v-else-if="periodMode === 'month'" class="d-flex align-center">
                        <v-btn icon="mdi-chevron-left" variant="text" size="small" @click="shiftMonth(-1)" />
                        <div class="text-subtitle-1 font-weight-medium text-center" style="min-width:160px">
                            {{ periodLabel }}
                        </div>
                        <v-btn icon="mdi-chevron-right" variant="text" size="small" @click="shiftMonth(1)" />
                    </div>

                    <v-spacer />

                    <v-text-field
                        v-model="search"
                        :label="t('KasseView.searchBookings')"
                        density="compact"
                        hide-details
                        variant="outlined"
                        prepend-inner-icon="mdi-magnify"
                        clearable
                        style="max-width:260px"
                    />

                    <v-btn-toggle v-model="typeFilter" mandatory density="compact" variant="outlined" rounded="lg">
                        <v-btn value="all" size="small">{{ t('KasseView.filterAll') }}</v-btn>
                        <v-btn value="income" size="small" color="success">{{ t('KasseView.filterIncome') }}</v-btn>
                        <v-btn value="expense" size="small" color="error">{{ t('KasseView.filterExpense') }}</v-btn>
                    </v-btn-toggle>
                </div>

                <!-- Summenleiste (sortier-/filterunabhängig) -->
                <v-sheet class="kassenbuch-summary-bar mb-3 px-4 py-2 d-flex flex-wrap align-center" rounded="lg" border>
                    <div class="summary-item">
                        <span class="text-caption text-medium-emphasis">{{ t('KasseView.openingBalance') }}<span v-if="periodMode !== 'all'"> ({{ periodHint }})</span></span>
                        <span class="text-body-1 font-weight-medium">{{ formatCurrency(openingBalance) }}</span>
                    </div>
                    <v-divider vertical class="mx-3" />
                    <div class="summary-item">
                        <span class="text-caption text-medium-emphasis">{{ t('KasseView.sumIncome') }}</span>
                        <span class="text-body-1 font-weight-medium text-success">+{{ formatNumber(sumIncome) }}</span>
                    </div>
                    <v-divider vertical class="mx-3" />
                    <div class="summary-item">
                        <span class="text-caption text-medium-emphasis">{{ t('KasseView.sumExpense') }}</span>
                        <span class="text-body-1 font-weight-medium text-error">−{{ formatNumber(sumExpense) }}</span>
                    </div>
                    <v-spacer />
                    <div class="summary-item text-right">
                        <span class="text-caption text-medium-emphasis">{{ t('KasseView.closingBalance') }}</span>
                        <span class="text-h6 font-weight-bold" :class="closingBalance < 0 ? 'text-error' : 'text-primary'">{{ formatCurrency(closingBalance) }}</span>
                    </div>
                </v-sheet>

                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        v-model:sort-by="sortBy"
                        :headers="txHeaders"
                        :items="filteredTransactions"
                        :loading="txLoading"
                        :items-per-page="-1"
                        density="comfortable"
                        hide-default-footer
                        multi-sort
                        class="kassenbuch-table"
                        @click:row="(e, { item }) => openCashbookRow(item)"
                    >
                        <template #item.transdate="{ item }">
                            <span class="text-no-wrap text-body-2">{{ formatDateShort(item.transdate) }}</span>
                        </template>

                        <template #item.reference="{ item }">
                            <span class="text-body-2">{{ item.reference || '—' }}</span>
                        </template>

                        <template #item.description="{ item }">
                            <div>
                                <div class="text-body-2">
                                    {{ item.description || (item.amount >= 0 ? t('KasseView.income') : t('KasseView.expense')) }}
                                    <span v-if="item.partner_name" class="text-disabled"> · {{ item.partner_name }}</span>
                                </div>
                                <v-chip v-if="item.source_type !== 'gl'" size="x-small" variant="tonal" :color="item.source_type === 'ar' ? 'info' : 'warning'" class="mt-1">
                                    {{ item.source_type === 'ar' ? t('KasseView.sourceAR') : t('KasseView.sourceAP') }}
                                </v-chip>
                            </div>
                        </template>

                        <template #item.gegenkonto="{ item }">
                            <span v-if="item.gegenkonto_accno" class="text-caption text-medium-emphasis">
                                {{ item.gegenkonto_accno }}<br>{{ item.gegenkonto_description }}
                            </span>
                            <span v-else class="text-disabled">—</span>
                        </template>

                        <template #item.einnahme="{ item }">
                            <span v-if="item.amount > 0" class="text-success font-weight-medium">{{ formatNumber(item.amount) }}</span>
                        </template>

                        <template #item.ausgabe="{ item }">
                            <span v-if="item.amount < 0" class="text-error font-weight-medium">{{ formatNumber(-item.amount) }}</span>
                        </template>

                        <template #item.saldo="{ item }">
                            <span class="font-weight-medium" :class="item.saldo < 0 ? 'text-error' : ''">{{ formatNumber(item.saldo) }}</span>
                        </template>

                        <template v-if="documentsEnabled" #item.document="{ item }">
                            <v-tooltip v-if="item.document_id" :text="item.document_name || t('KasseView.viewDocument')">
                                <template #activator="{ props }">
                                    <v-btn v-bind="props" icon="mdi-paperclip" size="x-small" variant="text" color="primary" @click="previewDocument(item.document_id)" />
                                </template>
                            </v-tooltip>
                            <v-tooltip v-else-if="item.source_type === 'gl'" :text="t('KasseView.uploadDocument')">
                                <template #activator="{ props }">
                                    <v-btn v-bind="props" icon="mdi-upload" size="x-small" variant="text" @click="triggerDocumentUpload(item)" />
                                </template>
                            </v-tooltip>
                        </template>

                        <template #item.actions="{ item }">
                            <v-btn
                                v-if="item.source_type === 'gl'"
                                icon="mdi-delete"
                                size="x-small"
                                variant="text"
                                color="error"
                                :title="t('KasseView.delete')"
                                @click="confirmDelete(item)"
                            />
                        </template>

                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('KasseView.noTransactions') }}</div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-window-item>

            <!-- ── OFFENE AUSGANGSRECHNUNGEN ── -->
            <v-window-item value="invoices">
                <div class="tab-toolbar mb-2">
                    <v-text-field
                        v-model="arSearch"
                        :label="t('KasseView.searchInvoice')"
                        density="compact"
                        hide-details
                        prepend-inner-icon="mdi-magnify"
                        clearable
                        style="max-width:320px"
                        @update:model-value="debouncedLoadOpenAr"
                    />
                </div>

                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        :headers="arHeaders"
                        :items="openAr"
                        :loading="arLoading"
                        :items-per-page="50"
                        density="compact"
                        hover
                        class="cursor-pointer"
                        @click:row="(e, { item }) => openInvoice(item)"
                    >
                        <template #item.transdate="{ item }">
                            <span class="text-no-wrap text-body-2">{{ formatDateShort(item.transdate) }}</span>
                        </template>
                        <template #item.duedate="{ item }">
                            <span class="text-no-wrap text-body-2" :class="isOverdue(item.duedate) ? 'text-error' : ''">
                                {{ formatDateShort(item.duedate) }}
                            </span>
                        </template>
                        <template #item.open_amount="{ item }">
                            <span class="font-weight-semibold">{{ formatCurrency(item.open_amount) }}</span>
                        </template>
                        <template #item.actions="{ item }">
                            <v-btn size="small" variant="tonal" color="success" @click.stop="bookArAsCashDialog(item)">
                                <v-icon start>mdi-cash</v-icon>
                                {{ t('KasseView.bookAsCash') }}
                            </v-btn>
                        </template>
                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('KasseView.noOpenInvoices') }}</div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-window-item>

            <!-- ── OFFENE EINGANGSRECHNUNGEN (Lieferanten) ── -->
            <v-window-item value="payables">
                <div class="tab-toolbar mb-2">
                    <v-text-field
                        v-model="apSearch"
                        :label="t('KasseView.searchPayable')"
                        density="compact"
                        hide-details
                        prepend-inner-icon="mdi-magnify"
                        clearable
                        style="max-width:320px"
                        @update:model-value="debouncedLoadOpenAp"
                    />
                </div>

                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        :headers="apHeaders"
                        :items="openAp"
                        :loading="apLoading"
                        :items-per-page="50"
                        density="compact"
                        hover
                    >
                        <template #item.transdate="{ item }">
                            <span class="text-no-wrap text-body-2">{{ formatDateShort(item.transdate) }}</span>
                        </template>
                        <template #item.duedate="{ item }">
                            <span class="text-no-wrap text-body-2" :class="isOverdue(item.duedate) ? 'text-error' : ''">
                                {{ formatDateShort(item.duedate) }}
                            </span>
                        </template>
                        <template #item.open_amount="{ item }">
                            <span class="font-weight-semibold">{{ formatCurrency(item.open_amount) }}</span>
                        </template>
                        <template #item.actions="{ item }">
                            <v-btn size="small" variant="tonal" color="warning" @click.stop="bookApAsCashDialog(item)">
                                <v-icon start>mdi-cash-minus</v-icon>
                                {{ t('KasseView.payCash') }}
                            </v-btn>
                        </template>
                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('KasseView.noOpenPayables') }}</div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-window-item>

        </v-window>

        <!-- ── Dialog: Neue manuelle Buchung ── -->
        <v-dialog v-model="showTransactionDialog" max-width="540" persistent>
            <v-card>
                <v-card-title>{{ t('KasseView.newTransaction') }}</v-card-title>
                <v-card-text>
                    <v-btn-toggle
                        v-model="txData.type"
                        mandatory
                        density="compact"
                        variant="outlined"
                        rounded="lg"
                        color="primary"
                        class="mb-4"
                    >
                        <v-btn value="income">
                            <v-icon start>mdi-arrow-down-bold</v-icon>
                            {{ t('KasseView.income') }}
                        </v-btn>
                        <v-btn value="expense">
                            <v-icon start>mdi-arrow-up-bold</v-icon>
                            {{ t('KasseView.expense') }}
                        </v-btn>
                    </v-btn-toggle>

                    <v-text-field
                        v-model="txData.transdate"
                        :label="t('KasseView.date')"
                        type="date"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="txData.amount"
                        :label="t('KasseView.amount')"
                        type="number"
                        step="0.01"
                        min="0.01"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                        prepend-inner-icon="mdi-currency-eur"
                        @blur="suggestFromAmount"
                        @keydown.enter="suggestFromAmount"
                    />

                    <!-- Gegenkonto -->
                    <v-autocomplete
                        v-model="txData.counterChartId"
                        :items="filteredCounterCharts"
                        :item-title="c => `${c.accno} – ${c.description}`"
                        item-value="id"
                        :label="t('KasseView.counterAccount')"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                        clearable
                        :no-data-text="t('KasseView.noCharts')"
                        @update:model-value="suggestFromCounter"
                    />

                    <v-text-field
                        v-model="txData.description"
                        :label="t('KasseView.description')"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="txData.reference"
                        :label="t('KasseView.reference')"
                        :placeholder="t('KasseView.referencePlaceholder')"
                        :readonly="belegLocked"
                        :hint="belegLocked ? t('KasseView.autoBelegHint') : t('KasseView.manualBelegHint')"
                        persistent-hint
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                    >
                        <template #append-inner>
                            <v-tooltip :text="belegLocked ? t('KasseView.editBeleg') : t('KasseView.lockBeleg')" location="top">
                                <template #activator="{ props }">
                                    <v-icon
                                        v-bind="props"
                                        :icon="belegLocked ? 'mdi-lock' : 'mdi-lock-open-variant'"
                                        :color="belegLocked ? 'medium-emphasis' : 'primary'"
                                        style="cursor:pointer"
                                        @click="toggleBelegLock"
                                    />
                                </template>
                            </v-tooltip>
                        </template>
                    </v-text-field>
                    <v-file-input
                        v-if="documentsEnabled"
                        v-model="txData.documentFile"
                        :label="t('KasseView.uploadDocument')"
                        density="compact"
                        variant="outlined"
                        accept="image/*,application/pdf"
                        prepend-icon=""
                        prepend-inner-icon="mdi-paperclip"
                        clearable
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showTransactionDialog = false">{{ t('KasseView.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="txSaving" @click="saveTransaction">{{ t('KasseView.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Dialog: Ausgangsrechnung bar verbuchen ── -->
        <v-dialog v-model="showArDialog" max-width="440" persistent>
            <v-card>
                <v-card-title>{{ t('KasseView.bookAsCash') }}</v-card-title>
                <v-card-text v-if="arDialogItem">
                    <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                        <div>{{ arDialogItem.invnumber }} · {{ arDialogItem.customer_name }}</div>
                        <div class="text-caption">{{ t('KasseView.openAmount') }}: {{ formatCurrency(arDialogItem.open_amount) }}</div>
                    </v-alert>
                    <v-text-field
                        v-model="arData.transdate"
                        :label="t('KasseView.date')"
                        type="date"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="arData.amount"
                        :label="t('KasseView.amount')"
                        type="number"
                        step="0.01"
                        min="0.01"
                        density="compact"
                        variant="outlined"
                        prepend-inner-icon="mdi-currency-eur"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showArDialog = false">{{ t('KasseView.cancel') }}</v-btn>
                    <v-btn color="success" :loading="arSaving" @click="confirmBookAr">
                        <v-icon start>mdi-cash</v-icon>
                        {{ t('KasseView.bookAsCash') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Dialog: Eingangsrechnung bar bezahlen ── -->
        <v-dialog v-model="showApDialog" max-width="440" persistent>
            <v-card>
                <v-card-title>{{ t('KasseView.payCash') }}</v-card-title>
                <v-card-text v-if="apDialogItem">
                    <v-alert type="warning" variant="tonal" density="compact" class="mb-4">
                        <div>{{ apDialogItem.invnumber }} · {{ apDialogItem.vendor_name }}</div>
                        <div class="text-caption">{{ t('KasseView.openAmount') }}: {{ formatCurrency(apDialogItem.open_amount) }}</div>
                    </v-alert>
                    <v-text-field
                        v-model="apData.transdate"
                        :label="t('KasseView.date')"
                        type="date"
                        density="compact"
                        variant="outlined"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="apData.amount"
                        :label="t('KasseView.amount')"
                        type="number"
                        step="0.01"
                        min="0.01"
                        density="compact"
                        variant="outlined"
                        prepend-inner-icon="mdi-currency-eur"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="showApDialog = false">{{ t('KasseView.cancel') }}</v-btn>
                    <v-btn color="warning" :loading="apSaving" @click="confirmBookAp">
                        <v-icon start>mdi-cash-minus</v-icon>
                        {{ t('KasseView.payCash') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- ── Dialog: Beleg-Vorschau ── -->
        <v-dialog v-model="showDocPreview" max-width="860">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <span>{{ t('KasseView.documentPreview') }}</span>
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" @click="showDocPreview = false" />
                </v-card-title>
                <v-card-text class="text-center pa-2">
                    <v-progress-circular v-if="docPreviewLoading" indeterminate class="my-8" />
                    <img
                        v-else-if="docPreviewMime && docPreviewMime.startsWith('image/')"
                        :src="`data:${docPreviewMime};base64,${docPreviewData}`"
                        style="max-width:100%; max-height:640px"
                    />
                    <iframe
                        v-else-if="docPreviewMime === 'application/pdf'"
                        :src="`data:application/pdf;base64,${docPreviewData}`"
                        style="width:100%; height:640px; border:none"
                    />
                </v-card-text>
            </v-card>
        </v-dialog>

    </v-container>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as alerts from '@/core/utils/alerts.js'
import Swal from 'sweetalert2'

const { t } = useI18n()
const router = useRouter()
const API_URL = '/api/banking/'

// ── State ──────────────────────────────────────────────────────────────────

const loading            = ref(false)
const registers          = ref([])
const selectedRegisterId = ref(null)
const counterCharts      = ref([])    // Gegenkonten (Aufwand/Ertrag)
const transactions       = ref([])
const txLoading          = ref(false)
const openingBalance     = ref(0)
const closingBalance     = ref(0)
const sumIncome          = ref(0)
const sumExpense         = ref(0)
const documentsEnabled   = ref(false)
const openAr             = ref([])
const arLoading          = ref(false)
const arSearch           = ref('')
const openAp             = ref([])     // offene Eingangsrechnungen (Lieferanten)
const apLoading          = ref(false)
const apSearch           = ref('')
const typeFilter         = ref('all')
const search             = ref('')        // Freitextsuche im Kassenbuch
const sortBy             = ref([{ key: 'transdate', order: 'desc' }])
const activeTab          = ref('transactions')

// Zeitraum-Filter: Jahr (Standard), Monat oder gesamter Zeitraum
const now          = new Date()
const periodYear   = ref(now.getFullYear())
const periodMonth  = ref(now.getMonth())   // 0-basiert
const periodMode   = ref('year')           // 'year' | 'month' | 'all'

// ── Dialogs ────────────────────────────────────────────────────────────────

const showTransactionDialog = ref(false)
const showArDialog          = ref(false)
const showApDialog          = ref(false)
const showDocPreview        = ref(false)
const txSaving              = ref(false)
const arSaving              = ref(false)
const apSaving              = ref(false)
const docPreviewLoading     = ref(false)
const docPreviewData        = ref('')
const docPreviewMime        = ref('')

const txData = reactive({
    type:           'income',
    transdate:      new Date().toISOString().slice(0, 10),
    amount:         '',
    counterChartId: null,
    description:    '',
    reference:      '',
    documentFile:   null,
})

// Belegnummer: standardmäßig gesperrt (automatisch). Per Schloss freigebbar.
const belegLocked = ref(true)

const arDialogItem = ref(null)
const arData = reactive({
    transdate: new Date().toISOString().slice(0, 10),
    amount:    '',
})

const apDialogItem = ref(null)
const apData = reactive({
    transdate: new Date().toISOString().slice(0, 10),
    amount:    '',
})

// ── Computed ────────────────────────────────────────────────────────────────

const selectedRegister = computed(() =>
    registers.value.find(r => r.id === selectedRegisterId.value)
)

const filteredCounterCharts = computed(() => {
    // Transferkonten (category A: Geldtransit, Bank) immer anbieten – in beide Richtungen
    if (txData.type === 'expense') return counterCharts.value.filter(c => c.category === 'E' || c.category === 'A')
    if (txData.type === 'income')  return counterCharts.value.filter(c => c.category === 'I' || c.category === 'A')
    return counterCharts.value
})

// Freitextsuche über Beleg, Buchungstext, Partner, Gegenkonto, Beträge, Datum
const filteredTransactions = computed(() => {
    const q = (search.value || '').trim().toLowerCase()
    if (!q) return transactions.value
    return transactions.value.filter(tx => {
        const hay = [
            tx.reference, tx.description, tx.partner_name,
            tx.gegenkonto_accno, tx.gegenkonto_description,
            formatDateShort(tx.transdate),
            String(tx.amount ?? ''), String(tx.saldo ?? ''),
        ].filter(Boolean).join(' ').toLowerCase()
        return hay.includes(q)
    })
})

const monthNames = computed(() => [
    t('KasseView.months.jan'), t('KasseView.months.feb'), t('KasseView.months.mar'),
    t('KasseView.months.apr'), t('KasseView.months.may'), t('KasseView.months.jun'),
    t('KasseView.months.jul'), t('KasseView.months.aug'), t('KasseView.months.sep'),
    t('KasseView.months.oct'), t('KasseView.months.nov'), t('KasseView.months.dec'),
])

const periodLabel = computed(() => `${monthNames.value[periodMonth.value]} ${periodYear.value}`)

// Kurzhinweis auf den aktiven Zeitraum (für die Anfangsbestand-Zeile)
const periodHint = computed(() => {
    if (periodMode.value === 'year')  return String(periodYear.value)
    if (periodMode.value === 'month') return periodLabel.value
    return ''
})

const fromDate = computed(() => {
    if (periodMode.value === 'all')  return undefined
    if (periodMode.value === 'year') return `${periodYear.value}-01-01`
    const m = String(periodMonth.value + 1).padStart(2, '0')
    return `${periodYear.value}-${m}-01`
})
const toDate = computed(() => {
    if (periodMode.value === 'all')  return undefined
    if (periodMode.value === 'year') return `${periodYear.value}-12-31`
    const last = new Date(periodYear.value, periodMonth.value + 1, 0).getDate()
    const m = String(periodMonth.value + 1).padStart(2, '0')
    return `${periodYear.value}-${m}-${String(last).padStart(2, '0')}`
})

const txHeaders = computed(() => {
    const h = [
        { title: t('KasseView.date'),        key: 'transdate',   width: '105px', sortable: true },
        { title: t('KasseView.reference'),   key: 'reference',   width: '120px', sortable: true,
          value: item => isNaN(Number(item.reference)) ? (item.reference || '') : Number(item.reference) },
        { title: t('KasseView.bookingText'), key: 'description', sortable: true,
          value: item => [item.description, item.partner_name].filter(Boolean).join(' ') },
        { title: t('KasseView.counterAccount'), key: 'gegenkonto', width: '150px', sortable: true,
          value: item => [item.gegenkonto_accno, item.gegenkonto_description].filter(Boolean).join(' ') },
        { title: t('KasseView.income'),      key: 'einnahme',    width: '110px', align: 'end', sortable: true,
          value: item => Number(item.amount) > 0 ? Number(item.amount) : null },
        { title: t('KasseView.expense'),     key: 'ausgabe',     width: '110px', align: 'end', sortable: true,
          value: item => Number(item.amount) < 0 ? Math.abs(Number(item.amount)) : null },
        { title: t('KasseView.saldo'),       key: 'saldo',       width: '120px', align: 'end', sortable: true,
          value: item => Number(item.saldo) },
    ]
    if (documentsEnabled.value) {
        h.splice(4, 0, { title: '', key: 'document', width: '48px', sortable: false })
    }
    h.push({ title: '', key: 'actions', width: '48px', sortable: false })
    return h
})

const arHeaders = computed(() => [
    { title: t('KasseView.invoice'),     key: 'invnumber',   width: '130px' },
    { title: t('KasseView.customer'),    key: 'customer_name' },
    { title: t('KasseView.invoiceDate'), key: 'transdate',   width: '100px' },
    { title: t('KasseView.dueDate'),     key: 'duedate',     width: '100px' },
    { title: t('KasseView.openAmount'),  key: 'open_amount', width: '120px' },
    { title: '',                         key: 'actions',     width: '160px', sortable: false },
])

const apHeaders = computed(() => [
    { title: t('KasseView.invoice'),     key: 'invnumber',   width: '130px' },
    { title: t('KasseView.vendor'),      key: 'vendor_name' },
    { title: t('KasseView.invoiceDate'), key: 'transdate',   width: '100px' },
    { title: t('KasseView.dueDate'),     key: 'duedate',     width: '100px' },
    { title: t('KasseView.openAmount'),  key: 'open_amount', width: '120px' },
    { title: '',                         key: 'actions',     width: '160px', sortable: false },
])

// ── API ─────────────────────────────────────────────────────────────────────

async function loadRegisters() {
    loading.value = true
    try {
        const res = await axios.post(API_URL, { action: 'getCashRegisters' })
        if (res.data?.success) {
            registers.value = res.data.payload.registers
            if (registers.value.length > 0 && !selectedRegisterId.value) {
                selectedRegisterId.value = registers.value[0].id
            }
        }
    } finally {
        loading.value = false
    }
}

async function loadCharts() {
    const res = await axios.post(API_URL, { action: 'getCashCounterCharts' })
    if (res.data?.success) counterCharts.value = res.data.payload.charts
}

async function loadTransactions() {
    if (!selectedRegisterId.value) return
    txLoading.value = true
    try {
        const res = await axios.post(API_URL, {
            action:           'getCashTransactions',
            cash_register_id: selectedRegisterId.value,
            type_filter:      typeFilter.value,
            from_date:        fromDate.value,
            to_date:          toDate.value,
        })
        if (res.data?.success) {
            const p = res.data.payload
            transactions.value    = p.transactions ?? []
            openingBalance.value  = p.opening_balance ?? 0
            closingBalance.value  = p.closing_balance ?? 0
            sumIncome.value       = p.sum_income ?? 0
            sumExpense.value      = p.sum_expense ?? 0
            documentsEnabled.value = !!p.documents_enabled
        }
    } finally {
        txLoading.value = false
    }
}

async function loadOpenAr() {
    if (!selectedRegisterId.value) return
    arLoading.value = true
    try {
        const res = await axios.post(API_URL, {
            action: 'getOpenArForCash',
            search: arSearch.value || undefined,
        })
        if (res.data?.success) openAr.value = res.data.payload.invoices ?? []
    } finally {
        arLoading.value = false
    }
}

let arSearchTimer = null
function debouncedLoadOpenAr() {
    clearTimeout(arSearchTimer)
    arSearchTimer = setTimeout(loadOpenAr, 350)
}

async function loadOpenAp() {
    if (!selectedRegisterId.value) return
    apLoading.value = true
    try {
        const res = await axios.post(API_URL, {
            action: 'getOpenApForCash',
            search: apSearch.value || undefined,
        })
        if (res.data?.success) openAp.value = res.data.payload.invoices ?? []
    } finally {
        apLoading.value = false
    }
}

let apSearchTimer = null
function debouncedLoadOpenAp() {
    clearTimeout(apSearchTimer)
    apSearchTimer = setTimeout(loadOpenAp, 350)
}

// ── Zeitraum-Navigation ──────────────────────────────────────────────────────

function shiftMonth(delta) {
    let m = periodMonth.value + delta
    let y = periodYear.value
    if (m < 0)  { m = 11; y-- }
    if (m > 11) { m = 0;  y++ }
    periodMonth.value = m
    periodYear.value  = y
}

function shiftYear(delta) {
    periodYear.value += delta
}

// ── Neue Buchung ─────────────────────────────────────────────────────────────

function openNewTransaction() {
    Object.assign(txData, {
        type:           'income',
        transdate:      new Date().toISOString().slice(0, 10),
        amount:         '',
        counterChartId: null,
        description:    '',
        reference:      '',
        documentFile:   null,
    })
    belegLocked.value = true
    showTransactionDialog.value = true
    loadNextBeleg()
}

// Nächste fortlaufende Belegnummer vom Server holen und ins (gesperrte) Feld setzen
async function loadNextBeleg() {
    if (!selectedRegisterId.value) return
    const res = await axios.post(API_URL, {
        action:           'getNextCashBelegnummer',
        cash_register_id: selectedRegisterId.value,
        transdate:        txData.transdate,
    })
    if (res.data?.success) txData.reference = res.data.payload.belegnummer
}

// Schloss umschalten — reiner Editierschutz, ändert die Belegnummer NICHT.
function toggleBelegLock() {
    belegLocked.value = !belegLocked.value
}

// Buchungsvorschlag aus der Historie, sobald ein Betrag eingegeben wurde und noch
// kein Gegenkonto gewählt ist (füllt Typ, Gegenkonto und Buchungstext vor).
async function suggestFromAmount() {
    const amt = Number(txData.amount)
    if (!amt || amt <= 0 || txData.counterChartId) return
    try {
        const res = await axios.post(API_URL, {
            action:           'getCashBookingSuggestion',
            cash_register_id: selectedRegisterId.value,
            amount:           amt,
        })
        const s = res.data?.payload?.suggestion
        if (!s) return
        if (s.type) txData.type = s.type
        txData.counterChartId = s.counter_chart_id
        if (!txData.description) txData.description = s.description || ''
    } catch (e) { /* Vorschlag ist optional */ }
}

// Beim manuellen Wählen eines Gegenkontos den letzten Buchungstext dazu vorschlagen.
async function suggestFromCounter() {
    if (!txData.counterChartId || txData.description) return
    try {
        const res = await axios.post(API_URL, {
            action:           'getCashBookingSuggestion',
            cash_register_id: selectedRegisterId.value,
            counter_chart_id: txData.counterChartId,
        })
        const s = res.data?.payload?.suggestion
        if (s && s.description && !txData.description) txData.description = s.description
    } catch (e) { /* Vorschlag ist optional */ }
}

async function saveTransaction() {
    const amt = Number(txData.amount)
    if (!amt || amt <= 0) { alerts.error(t('KasseView.amountRequired')); return }
    if (!txData.counterChartId) { alerts.error(t('KasseView.counterAccountRequired')); return }

    txSaving.value = true
    try {
        let documentId = null
        const rawFile = Array.isArray(txData.documentFile) ? txData.documentFile[0] : txData.documentFile
        if (rawFile && documentsEnabled.value) {
            const base64 = await fileToBase64(rawFile)
            const upRes  = await axios.post(API_URL, {
                action: 'uploadCashDocument', filename: rawFile.name,
                mime_type: rawFile.type, file_base64: base64,
            })
            if (upRes.data?.success) documentId = upRes.data.payload.document_id
        }

        const res = await axios.post(API_URL, {
            action:           'createCashTransaction',
            cash_register_id: selectedRegisterId.value,
            transdate:        txData.transdate,
            amount:           amt,
            type:             txData.type,
            counter_chart_id: txData.counterChartId,
            description:      txData.description || undefined,
            reference:        txData.reference   || undefined,
            document_id:      documentId          || undefined,
        })

        if (res.data?.success) {
            alerts.success(t('KasseView.transactionCreated'))
            showTransactionDialog.value = false
            await loadRegisters()
            await loadTransactions()
        } else {
            alerts.error(res.data?.text || t('KasseView.saveError'))
        }
    } finally {
        txSaving.value = false
    }
}

// ── AR als Barzahlung buchen ─────────────────────────────────────────────────

function bookArAsCashDialog(invoice) {
    arDialogItem.value = invoice
    arData.transdate   = new Date().toISOString().slice(0, 10)
    arData.amount      = String(invoice.open_amount)
    showArDialog.value = true
}

function openInvoice(invoice) {
    if (!invoice?.id) return
    router.push(`${t('routes.manageInvoices')}/${invoice.id}`)
}

// Klick auf eine Kassenbuch-Zeile: Kundenrechnung (AR) öffnen.
// Manuelle Buchungen (gl) und Lieferantenzahlungen (ap) haben keine Kundenrechnung.
function openCashbookRow(item) {
    if (item?.source_type === 'ar' && item.gl_id) {
        router.push(`${t('routes.manageInvoices')}/${item.gl_id}`)
    }
}

async function confirmBookAr() {
    const amt = Number(arData.amount)
    if (!amt || amt <= 0) { alerts.error(t('KasseView.amountRequired')); return }

    arSaving.value = true
    try {
        const res = await axios.post(API_URL, {
            action:           'bookArAsCash',
            cash_register_id: selectedRegisterId.value,
            ar_id:            arDialogItem.value.id,
            amount:           amt,
            transdate:        arData.transdate,
        })
        if (res.data?.success) {
            alerts.success(t('KasseView.transactionCreated'))
            showArDialog.value = false
            await loadRegisters()
            await loadTransactions()
            await loadOpenAr()
        } else {
            alerts.error(res.data?.text || t('KasseView.saveError'))
        }
    } finally {
        arSaving.value = false
    }
}

// ── AP (Eingangsrechnung) bar bezahlen ────────────────────────────────────────

function bookApAsCashDialog(invoice) {
    apDialogItem.value = invoice
    apData.transdate   = new Date().toISOString().slice(0, 10)
    apData.amount      = String(invoice.open_amount)
    showApDialog.value = true
}

async function confirmBookAp() {
    const amt = Number(apData.amount)
    if (!amt || amt <= 0) { alerts.error(t('KasseView.amountRequired')); return }

    apSaving.value = true
    try {
        const res = await axios.post(API_URL, {
            action:           'bookApAsCash',
            cash_register_id: selectedRegisterId.value,
            ap_id:            apDialogItem.value.id,
            amount:           amt,
            transdate:        apData.transdate,
        })
        if (res.data?.success) {
            alerts.success(t('KasseView.paymentBooked'))
            showApDialog.value = false
            await loadRegisters()
            await loadTransactions()
            await loadOpenAp()
        } else {
            alerts.error(res.data?.text || t('KasseView.saveError'))
        }
    } finally {
        apSaving.value = false
    }
}

// ── Buchung löschen ────────────────────────────────────────────────────────────

async function confirmDelete(item) {
    const result = await Swal.fire({
        title: t('KasseView.deleteConfirm'), icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#d33',
        confirmButtonText: t('KasseView.delete'), cancelButtonText: t('KasseView.cancel'),
    })
    if (!result.isConfirmed) return

    const res = await axios.post(API_URL, { action: 'deleteCashTransaction', gl_id: item.gl_id })
    if (res.data?.success) {
        alerts.success(t('KasseView.transactionDeleted'))
        await loadRegisters()
        await loadTransactions()
    }
}

// ── Beleg nachträglich hochladen ─────────────────────────────────────────────

function triggerDocumentUpload(txItem) {
    const input  = document.createElement('input')
    input.type   = 'file'
    input.accept = 'image/*,application/pdf'
    input.onchange = async (e) => {
        const file = e.target.files[0]
        if (!file) return
        const base64  = await fileToBase64(file)
        const upRes   = await axios.post(API_URL, {
            action: 'uploadCashDocument', filename: file.name, mime_type: file.type, file_base64: base64,
        })
        if (!upRes.data?.success) { alerts.error(t('KasseView.uploadError')); return }
        const linkRes = await axios.post(API_URL, {
            action: 'linkDocumentToCashTransaction', gl_id: txItem.gl_id,
            document_id: upRes.data.payload.document_id,
        })
        if (linkRes.data?.success) {
            alerts.success(t('KasseView.documentUploaded'))
            await loadTransactions()
        }
    }
    input.click()
}

// ── Beleg-Vorschau ─────────────────────────────────────────────────────────

async function previewDocument(documentId) {
    showDocPreview.value    = true
    docPreviewLoading.value = true
    docPreviewData.value    = ''
    docPreviewMime.value    = ''
    try {
        const res = await axios.post(API_URL, { action: 'getCashDocumentContent', document_id: documentId })
        if (res.data?.success) {
            docPreviewData.value = res.data.payload.content_base64
            docPreviewMime.value = res.data.payload.mime_type
        }
    } finally {
        docPreviewLoading.value = false
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────

function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload  = e => resolve(e.target.result.split(',')[1])
        reader.onerror = reject
        reader.readAsDataURL(file)
    })
}

function formatCurrency(v)  { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(v) || 0) }
function formatNumber(v)    { return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v) || 0) }
function formatDateShort(d) { return d ? new Date(d).toLocaleDateString('de-DE') : '—' }
function isOverdue(d)       { return d && new Date(d) < new Date() }

// ── Watchers & Init ────────────────────────────────────────────────────────

watch(selectedRegisterId, () => {
    loadTransactions()
    if (activeTab.value === 'invoices') loadOpenAr()
    if (activeTab.value === 'payables') loadOpenAp()
})
watch(typeFilter,   loadTransactions)
watch(periodYear,   loadTransactions)
watch(periodMonth,  loadTransactions)
watch(periodMode,   loadTransactions)
watch(activeTab,    (tab) => {
    if (tab === 'invoices') loadOpenAr()
    if (tab === 'payables') loadOpenAp()
})
// Typwechsel: gewähltes Gegenkonto nur verwerfen, wenn es nicht mehr passt.
// Transferkonten (Geldtransit/Bank, category A) gelten für beide Richtungen.
watch(() => txData.type, () => {
    const c = counterCharts.value.find(x => x.id === txData.counterChartId)
    if (!c || c.category === 'A') return
    if ((txData.type === 'expense' && c.category !== 'E') ||
        (txData.type === 'income'  && c.category !== 'I')) {
        txData.counterChartId = null
    }
})
// Bei Jahreswechsel im Datum die automatische Belegnummer neu holen (solange gesperrt)
watch(() => txData.transdate, (nd, od) => {
    if (showTransactionDialog.value && belegLocked.value && (nd || '').slice(0, 4) !== (od || '').slice(0, 4)) {
        loadNextBeleg()
    }
})

onMounted(() => {
    loadRegisters()
    loadCharts()
})
</script>

<style scoped>
.tab-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.cursor-pointer :deep(tbody tr) {
    cursor: pointer;
}
.kassenbuch-summary-bar {
    background: rgba(var(--v-theme-primary), 0.04);
}
.summary-item {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}
</style>
