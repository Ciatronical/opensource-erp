<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-2">{{ t('AccountingView.datev.title') }}</h1>
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mt-1 mb-2" :text="t('AccountingView.datev.info')" />
            </v-col>
        </v-row>

        <!-- DATEV-Konfiguration -->
        <v-row>
            <v-col cols="12" md="6">
                <v-card>
                    <v-card-title>{{ t('AccountingView.datev.config') }}</v-card-title>
                    <v-card-text>
                        <v-row dense>
                            <v-col cols="6">
                                <v-text-field v-model="config.beraternr" :label="t('AccountingView.datev.consultantNumber')"
                                              density="compact" variant="outlined" />
                            </v-col>
                            <v-col cols="6">
                                <v-text-field v-model="config.beratername" :label="t('AccountingView.datev.consultantName')"
                                              density="compact" variant="outlined" />
                            </v-col>
                            <v-col cols="6">
                                <v-text-field v-model="config.mandantennr" :label="t('AccountingView.datev.clientNumber')"
                                              density="compact" variant="outlined" />
                            </v-col>
                            <v-col cols="6">
                                <v-text-field v-model="config.datentraegernr" :label="t('AccountingView.datev.dataCarrierNumber')"
                                              density="compact" variant="outlined" />
                            </v-col>
                        </v-row>
                        <v-btn color="primary" variant="elevated" @click="onSaveConfig" class="mt-2">
                            {{ t('AccountingView.datev.saveConfig') }}
                        </v-btn>

                        <!-- Firmeninfo -->
                        <v-divider class="my-4" />
                        <v-row dense v-if="datevConfig.defaults">
                            <v-col cols="6">
                                <div class="text-caption text-grey">{{ t('AccountingView.datev.company') }}</div>
                                <div>{{ datevConfig.defaults.company || '—' }}</div>
                            </v-col>
                            <v-col cols="6">
                                <div class="text-caption text-grey">{{ t('AccountingView.datev.chartOfAccounts') }}</div>
                                <div>{{ datevConfig.defaults.coa || '—' }}</div>
                            </v-col>
                            <v-col cols="6">
                                <div class="text-caption text-grey">{{ t('AccountingView.datev.taxNumber') }}</div>
                                <div>{{ datevConfig.defaults.taxnumber || '—' }}</div>
                            </v-col>
                            <v-col cols="6">
                                <div class="text-caption text-grey">{{ t('AccountingView.datev.vatId') }}</div>
                                <div>{{ datevConfig.defaults.co_ustid || '—' }}</div>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </v-col>

            <!-- Export -->
            <v-col cols="12" md="6">
                <v-card>
                    <v-card-title>{{ t('AccountingView.datev.period') }}</v-card-title>
                    <v-card-text>
                        <v-row dense>
                            <v-col cols="6">
                                <v-text-field v-model="fromDate" :label="t('AccountingView.datev.fromDate')"
                                              type="date" density="compact" variant="outlined" />
                            </v-col>
                            <v-col cols="6">
                                <v-text-field v-model="toDate" :label="t('AccountingView.datev.toDate')"
                                              type="date" density="compact" variant="outlined" />
                            </v-col>
                        </v-row>
                        <div class="d-flex flex-wrap mt-2" style="gap: 8px">
                            <v-btn variant="outlined" @click="onPreview">
                                <v-icon start>mdi-eye</v-icon>
                                {{ t('AccountingView.datev.preview') }}
                            </v-btn>
                            <v-btn variant="outlined" @click="onExport"
                                   :disabled="!preview || preview.count === 0">
                                <v-icon start>mdi-file-delimited-outline</v-icon>
                                {{ t('AccountingView.datev.export') }}
                                <span v-if="preview" class="ml-1">({{ preview.count }})</span>
                            </v-btn>
                            <!-- Fuer Steuerberater und Betriebspruefung: mit Belegbildern -->
                            <v-btn color="primary" variant="elevated" :loading="packing" @click="onExportPackage"
                                   :disabled="!preview || preview.count === 0">
                                <v-icon start>mdi-folder-zip-outline</v-icon>
                                {{ t('AccountingView.datev.exportPackage') }}
                            </v-btn>
                        </div>
                        <p v-if="preview" class="text-caption text-medium-emphasis mt-2 mb-0">
                            {{ t('AccountingView.datev.packageHint', {
                                    withDoc: preview.with_document || 0, total: preview.count }) }}
                        </p>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Vorschau -->
        <v-row v-if="preview" class="mt-4">
            <v-col cols="12">
                <v-card>
                    <v-card-title>
                        {{ t('AccountingView.datev.bookingsToExport') }}
                        <v-chip class="ml-2" size="small">{{ preview.count }}</v-chip>
                    </v-card-title>
                    <v-card-text v-if="preview.count === 0">
                        <v-alert type="info" variant="tonal">
                            {{ t('AccountingView.datev.noBookings') }}
                        </v-alert>
                    </v-card-text>
                    <v-data-table v-else
                        :headers="previewHeaders"
                        :items="preview.bookings"
                        density="compact"
                        :items-per-page="50"
                    >
                        <template #item.amount="{ item }">
                            {{ formatCurrency(item.amount) }}
                        </template>
                    </v-data-table>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useDatevExport } from '../composables/useDatevExport.js'
import * as toasts from '@/core/utils/toasts.js'

const { t } = useI18n()
const { loading, preview, datevConfig, fetchPreview, exportCsv, exportPackage, fetchConfig, saveConfig } = useDatevExport()

// Das Paket enthaelt alle Scans und kann dauern — Knopf so lange sperren
const packing = ref(false)

const now = new Date()
const fromDate = ref(now.getFullYear() + '-01-01')
const toDate = ref(now.toISOString().slice(0, 10))

const config = ref({
    beraternr: '',
    beratername: '',
    mandantennr: '',
    datentraegernr: '',
    abrechnungsnr: ''
})

const previewHeaders = computed(() => [
    { title: t('AccountingView.bookings.date'), key: 'booking_date', width: '100px' },
    { title: t('AccountingView.bookings.reference'), key: 'reference', width: '140px' },
    { title: t('AccountingView.bookings.description'), key: 'description' },
    { title: t('AccountingView.bookings.vendor'), key: 'vendor_name' },
    { title: t('AccountingView.bookings.debitAccount'), key: 'debit_account', width: '100px' },
    { title: t('AccountingView.bookings.creditAccount'), key: 'credit_account', width: '100px' },
    { title: t('AccountingView.bookings.taxKey'), key: 'tax_key', width: '80px' },
    { title: t('AccountingView.bookings.amount'), key: 'amount', align: 'end', width: '120px' }
])

async function onPreview() {
    await fetchPreview(fromDate.value, toDate.value)
}

async function onExport() {
    await exportCsv(fromDate.value, toDate.value)
}

async function onExportPackage() {
    packing.value = true
    const result = await exportPackage(fromDate.value, toDate.value)
    packing.value = false
    if (!result.success) toasts.error(result.text || t('AccountingView.datev.exportError'))
}

async function onSaveConfig() {
    await saveConfig(config.value)
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

onMounted(async () => {
    await fetchConfig()
    if (datevConfig.value?.datev) {
        config.value = { ...datevConfig.value.datev }
    }
})
</script>
