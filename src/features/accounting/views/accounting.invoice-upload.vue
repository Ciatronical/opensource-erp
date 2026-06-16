<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-2">{{ t('AccountingView.upload.title') }}</h1>
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mt-1 mb-2" :text="t('AccountingView.upload.info')" />
            </v-col>
        </v-row>

        <v-row>
            <!-- Upload-Bereich -->
            <v-col cols="12" :md="uploadResult ? 5 : 8" :lg="uploadResult ? 4 : 6">
                <v-card>
                    <v-card-text>
                        <!-- Dropzone -->
                        <div
                            class="dropzone pa-8 text-center rounded-lg"
                            :class="{ 'dropzone-active': isDragging, 'dropzone-uploading': uploading }"
                            @dragover.prevent="isDragging = true"
                            @dragleave="isDragging = false"
                            @drop.prevent="onDrop"
                            @click="$refs.fileInput.click()"
                        >
                            <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png"
                                   style="display: none" @change="onFileSelect" />

                            <template v-if="uploading">
                                <v-progress-circular :model-value="uploadProgress" size="80" width="6" color="primary">
                                    <v-icon size="32">mdi-robot</v-icon>
                                </v-progress-circular>
                                <div class="text-h6 mt-4">{{ t('AccountingView.upload.analyzing') }}</div>
                                <div class="text-body-2 text-grey mt-1">Weroni</div>
                            </template>

                            <template v-else-if="error">
                                <v-icon size="64" color="error">mdi-alert-circle</v-icon>
                                <div class="text-h6 mt-4 text-error">{{ t('AccountingView.upload.error') }}</div>
                                <div class="text-body-2 mt-1">{{ error }}</div>
                                <v-btn class="mt-4" color="primary" variant="text" @click="resetUpload">
                                    {{ t('AccountingView.upload.uploadAnother') }}
                                </v-btn>
                            </template>

                            <template v-else>
                                <v-icon size="64" color="primary">mdi-cloud-upload</v-icon>
                                <div class="text-h6 mt-4">{{ t('AccountingView.upload.dropzone') }}</div>
                                <div class="text-body-2 text-grey mt-1">{{ t('AccountingView.upload.dropzoneHint') }}</div>
                                <div class="text-caption text-grey mt-2">
                                    {{ t('AccountingView.upload.maxFileSize') }}<br>
                                    {{ t('AccountingView.upload.allowedFormats') }}
                                </div>
                            </template>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>

            <!-- Ergebnis-Bereich -->
            <v-col v-if="uploadResult" cols="12" md="7" lg="8">
                <v-alert type="success" variant="tonal" class="mb-4">
                    <v-icon start>mdi-check-circle</v-icon>
                    {{ t('AccountingView.upload.success') }}
                    <span class="ml-2 font-weight-bold">{{ uploadResult.reference }}</span>
                </v-alert>

                <!-- Hybrid-Status: automatisch gebucht vs. Freigabe nötig -->
                <v-alert
                    :type="uploadResult.auto_booked ? 'success' : 'warning'"
                    variant="tonal"
                    class="mb-4"
                    :icon="uploadResult.auto_booked ? 'mdi-robot-happy-outline' : 'mdi-account-clock-outline'"
                >
                    <template v-if="uploadResult.auto_booked">
                        {{ t('AccountingView.upload.autoBooked') }}
                    </template>
                    <template v-else>
                        {{ uploadResult.vendor_status === 'ambiguous'
                            ? t('AccountingView.upload.reviewVendor')
                            : t('AccountingView.upload.reviewNeeded') }}
                        <v-btn class="ml-3" size="small" variant="tonal" :to="t('AccountingView.routes.accountingBookings')">
                            {{ t('AccountingView.upload.toReview') }}
                        </v-btn>
                    </template>
                </v-alert>

                <!-- Lieferant -->
                <v-card class="mb-4">
                    <v-card-title>
                        <v-icon start>mdi-domain</v-icon>
                        {{ t('AccountingView.upload.vendorInfo') }}
                        <v-chip v-if="uploadResult.vendor?.is_collective" color="warning" size="small" class="ml-2">
                            {{ t('AccountingView.upload.collectiveVendor') }}
                        </v-chip>
                        <v-chip v-else-if="uploadResult.vendor?.is_new" color="info" size="small" class="ml-2">
                            {{ t('AccountingView.upload.newVendor') }}
                        </v-chip>
                        <v-chip v-else-if="uploadResult.vendor_status === 'ambiguous'" color="warning" size="small" class="ml-2">
                            {{ t('AccountingView.upload.vendorAmbiguous') }}
                        </v-chip>
                        <v-chip v-else-if="uploadResult.vendor?.vendor_name" color="success" size="small" class="ml-2">
                            {{ t('AccountingView.upload.existingVendor') }}
                            ({{ Math.round((uploadResult.vendor?.match_score || 0) * 100) }}%)
                        </v-chip>
                    </v-card-title>
                    <v-card-text v-if="uploadResult.extracted?.vendor">
                        <v-row dense>
                            <v-col cols="6"><strong>{{ uploadResult.extracted.vendor.name }}</strong></v-col>
                            <v-col cols="6">{{ uploadResult.extracted.vendor.street }}</v-col>
                            <v-col cols="6">{{ uploadResult.extracted.vendor.zipcode }} {{ uploadResult.extracted.vendor.city }}</v-col>
                            <v-col cols="6" v-if="uploadResult.extracted.vendor.iban">IBAN: {{ uploadResult.extracted.vendor.iban }}</v-col>
                            <v-col cols="6" v-if="uploadResult.extracted.vendor.ustid">USt-IdNr: {{ uploadResult.extracted.vendor.ustid }}</v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <!-- Rechnungsdaten -->
                <v-card class="mb-4">
                    <v-card-title>
                        <v-icon start>mdi-file-document</v-icon>
                        {{ t('AccountingView.upload.invoiceInfo') }}
                    </v-card-title>
                    <v-card-text v-if="uploadResult.extracted">
                        <v-row dense>
                            <v-col cols="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.bookings.invoiceNumber') }}</div>
                                <div class="font-weight-bold">{{ uploadResult.extracted.invoice?.number || '—' }}</div>
                            </v-col>
                            <v-col cols="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.bookings.date') }}</div>
                                <div>{{ uploadResult.extracted.invoice?.date || '—' }}</div>
                            </v-col>
                            <v-col cols="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.bookings.dueDate') }}</div>
                                <div>{{ uploadResult.extracted.invoice?.due_date || '—' }}</div>
                            </v-col>
                        </v-row>
                        <v-divider class="my-3" />
                        <v-row dense>
                            <v-col cols="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.bookings.netAmount') }}</div>
                                <div class="text-h6">{{ formatCurrency(uploadResult.extracted.amounts?.net) }}</div>
                            </v-col>
                            <v-col cols="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.bookings.taxAmount') }} ({{ uploadResult.extracted.amounts?.tax_rate }}%)</div>
                                <div class="text-h6">{{ formatCurrency(uploadResult.extracted.amounts?.tax) }}</div>
                            </v-col>
                            <v-col cols="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.bookings.amount') }}</div>
                                <div class="text-h5 font-weight-bold">{{ formatCurrency(uploadResult.extracted.amounts?.gross) }}</div>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <!-- Buchungsvorschlag -->
                <v-card class="mb-4" v-if="uploadResult.extracted?.booking_suggestion">
                    <v-card-title>
                        <v-icon start color="purple">mdi-robot</v-icon>
                        {{ t('AccountingView.upload.bookingSuggestion') }}
                    </v-card-title>
                    <v-card-text>
                        <v-row dense>
                            <v-col cols="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.bookings.debitAccount') }}</div>
                                <div class="font-weight-bold">{{ uploadResult.extracted.booking_suggestion.debit_account }}</div>
                            </v-col>
                            <v-col cols="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.bookings.creditAccount') }}</div>
                                <div class="font-weight-bold">{{ uploadResult.extracted.booking_suggestion.credit_account }}</div>
                            </v-col>
                            <v-col cols="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.bookings.taxKey') }}</div>
                                <div class="font-weight-bold">{{ uploadResult.extracted.booking_suggestion.tax_key }}</div>
                            </v-col>
                        </v-row>
                        <div class="mt-2 text-body-2">{{ uploadResult.extracted.booking_suggestion.description }}</div>
                    </v-card-text>
                </v-card>

                <!-- Positionen -->
                <v-card class="mb-4" v-if="uploadResult.extracted?.positions?.length > 0">
                    <v-card-title>
                        <v-icon start>mdi-format-list-numbered</v-icon>
                        {{ t('AccountingView.upload.positions') }}
                    </v-card-title>
                    <v-card-text>
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>{{ t('AccountingView.bookings.description') }}</th>
                                    <th class="text-end">Menge</th>
                                    <th class="text-end">{{ t('AccountingView.bookings.netAmount') }}</th>
                                    <th class="text-end">{{ t('AccountingView.bookings.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(pos, idx) in uploadResult.extracted.positions" :key="idx">
                                    <td>{{ pos.description }}</td>
                                    <td class="text-end">{{ pos.quantity }}</td>
                                    <td class="text-end">{{ formatCurrency(pos.net_amount) }}</td>
                                    <td class="text-end">{{ formatCurrency(pos.gross_amount) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-card-text>
                </v-card>

                <!-- Aktionen -->
                <div class="d-flex gap-4">
                    <v-btn color="primary" variant="elevated"
                           :to="t('AccountingView.routes.accountingBookings') + '?status=pending'">
                        <v-icon start>mdi-check-all</v-icon>
                        {{ t('AccountingView.upload.goToBookings') }}
                    </v-btn>
                    <v-btn variant="outlined" @click="resetForNext">
                        <v-icon start>mdi-plus</v-icon>
                        {{ t('AccountingView.upload.uploadAnother') }}
                    </v-btn>
                </div>
            </v-col>
        </v-row>

        <!-- Bereits gebuchte Eingangsrechnungen -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-card>
                    <v-card-title class="text-subtitle-1">{{ t('AccountingView.upload.bookedTitle') }}</v-card-title>
                    <v-data-table
                        :headers="invoiceHeaders"
                        :items="incomingInvoices"
                        :loading="loadingInvoices"
                        density="compact"
                        :items-per-page="10"
                        :no-data-text="t('AccountingView.upload.noInvoices')">
                        <template #[`item.amount`]="{ item }">{{ formatCurrency(item.amount) }}</template>
                        <template #[`item.paid`]="{ item }">{{ formatCurrency(item.paid) }}</template>
                        <template #[`item.open_amount`]="{ item }">
                            <span :class="Number(item.open_amount) <= 0 ? 'text-success' : 'text-error'">
                                {{ formatCurrency(item.open_amount) }}
                            </span>
                        </template>
                    </v-data-table>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useInvoiceUpload } from '../composables/useInvoiceUpload.js'

const { t } = useI18n()
const { uploading, uploadProgress, uploadResult, error, incomingInvoices, loadingInvoices,
        uploadInvoice, fetchIncomingInvoices, resetUpload } = useInvoiceUpload()

const isDragging = ref(false)
const fileInput = ref(null)

const invoiceHeaders = computed(() => [
    { title: t('AccountingView.upload.colInvNumber'), key: 'invnumber', width: '130px' },
    { title: t('AccountingView.upload.colVendor'), key: 'vendor_name' },
    { title: t('AccountingView.upload.colDate'), key: 'transdate_fmt', width: '110px' },
    { title: t('AccountingView.upload.colAmount'), key: 'amount', align: 'end', width: '120px' },
    { title: t('AccountingView.upload.colPaid'), key: 'paid', align: 'end', width: '120px' },
    { title: t('AccountingView.upload.colOpen'), key: 'open_amount', align: 'end', width: '120px' }
])

// Nach erfolgreicher Analyse/Buchung die Liste aktualisieren
watch(uploadResult, (val) => { if (val) fetchIncomingInvoices() })

onMounted(() => {
    fetchIncomingInvoices()
})

function onDrop(event) {
    isDragging.value = false
    const files = event.dataTransfer?.files
    if (files?.length > 0) {
        processFile(files[0])
    }
}

function onFileSelect(event) {
    const files = event.target?.files
    if (files?.length > 0) {
        processFile(files[0])
    }
}

async function processFile(file) {
    // Dateityp pruefen
    const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png']
    if (!allowedTypes.includes(file.type)) {
        error.value = t('AccountingView.upload.allowedFormats')
        return
    }

    // Groesse pruefen (20 MB)
    if (file.size > 20 * 1024 * 1024) {
        error.value = t('AccountingView.upload.maxFileSize')
        return
    }

    await uploadInvoice(file)
}

function resetForNext() {
    resetUpload()
    if (fileInput.value) {
        fileInput.value.value = ''
    }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}
</script>

<style scoped>
.dropzone {
    border: 2px dashed rgb(var(--v-theme-primary), 0.3);
    cursor: pointer;
    transition: all 0.2s ease;
    min-height: 300px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.dropzone:hover {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.03);
}
.dropzone-active {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.08);
}
.dropzone-uploading {
    border-color: rgb(var(--v-theme-primary));
    cursor: wait;
}
</style>
