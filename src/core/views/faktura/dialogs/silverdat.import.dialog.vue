<!-- src/core/views/faktura/dialogs/silverdat.import.dialog.vue -->

<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="960"
        persistent
        scrollable
    >
        <v-card>
            <v-card-title class="d-flex align-center bg-orange-darken-2">
                <v-icon class="mr-2">mdi-file-import</v-icon>
                {{ t('FakturaView.faktura.silverdat.title') }}
                <v-spacer />
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    density="compact"
                    size="small"
                    @click="onClose"
                />
            </v-card-title>

            <v-card-text class="pt-4">
                <!-- Fehler -->
                <v-alert v-if="importError" type="error" variant="tonal" class="mb-4" closable @click:close="$emit('clear-error')">
                    {{ importError }}
                </v-alert>

                <!-- Vorschau (nach erfolgreichem Parsen) -->
                <template v-if="importItems.length > 0">
                    <!-- Fahrzeug-Info -->
                    <v-alert v-if="vehicleInfo" type="info" variant="tonal" density="compact" class="mb-4">
                        <div class="d-flex align-center flex-wrap ga-3">
                            <v-icon size="small">mdi-car</v-icon>
                            <strong>{{ vehicleInfo.manufacturer }} {{ vehicleInfo.model }}</strong>
                            <span v-if="vehicleInfo.licensePlate">{{ vehicleInfo.licensePlate }}</span>
                            <span v-if="vehicleInfo.mileage">{{ vehicleInfo.mileage.toLocaleString('de-DE') }} km</span>
                            <span v-if="vehicleInfo.vin" class="text-grey text-caption">VIN: {{ vehicleInfo.vin }}</span>
                        </div>
                    </v-alert>

                    <!-- Dateiname + Ändern -->
                    <div class="d-flex align-center mb-3">
                        <v-icon size="small" class="mr-1">mdi-file-xml-box</v-icon>
                        <span class="text-body-2 text-grey-darken-1">{{ fileName }}</span>
                        <v-btn variant="text" size="x-small" class="ml-2" @click="onChangeFile">
                            {{ t('FakturaView.faktura.silverdat.changeFile') }}
                        </v-btn>
                    </div>

                    <!-- Positionen-Tabelle -->
                    <v-data-table
                        :headers="tableHeaders"
                        :items="importItems"
                        :items-per-page="-1"
                        density="compact"
                        class="silverdat-table mb-3"
                        fixed-header
                        height="360"
                    >
                        <template #item.category="{ item }">
                            <v-chip
                                :color="categoryColor(item.category)"
                                size="x-small"
                                variant="tonal"
                                label
                            >
                                {{ categoryLabel(item.category) }}
                            </v-chip>
                        </template>
                        <template #item.qty="{ item }">
                            {{ formatNumber(item.qty) }}
                        </template>
                        <template #item.sellprice="{ item }">
                            {{ formatCurrency(item.sellprice) }}
                        </template>
                        <template #item.total="{ item }">
                            {{ formatCurrency(item.qty * item.sellprice) }}
                        </template>
                        <template #bottom />
                    </v-data-table>

                    <!-- Zusammenfassung -->
                    <v-card variant="outlined" class="pa-3">
                        <div class="d-flex flex-wrap ga-4 text-body-2">
                            <div v-if="summary.partsCount">
                                <span class="text-grey">{{ t('FakturaView.faktura.silverdat.summaryParts') }}:</span>
                                <strong class="ml-1">{{ formatCurrency(summary.partsSum) }}</strong>
                                <span class="text-grey ml-1">({{ summary.partsCount }})</span>
                            </div>
                            <div v-if="summary.labourCount">
                                <span class="text-grey">{{ t('FakturaView.faktura.silverdat.summaryLabour') }}:</span>
                                <strong class="ml-1">{{ formatCurrency(summary.labourSum) }}</strong>
                                <span class="text-grey ml-1">({{ summary.labourCount }})</span>
                            </div>
                            <div v-if="summary.lacquerCount">
                                <span class="text-grey">{{ t('FakturaView.faktura.silverdat.summaryLacquer') }}:</span>
                                <strong class="ml-1">{{ formatCurrency(summary.lacquerSum) }}</strong>
                                <span class="text-grey ml-1">({{ summary.lacquerCount }})</span>
                            </div>
                            <div v-if="summary.additionalCount">
                                <span class="text-grey">{{ t('FakturaView.faktura.silverdat.summaryAdditional') }}:</span>
                                <strong class="ml-1">{{ formatCurrency(summary.additionalSum) }}</strong>
                                <span class="text-grey ml-1">({{ summary.additionalCount }})</span>
                            </div>
                        </div>
                        <v-divider class="my-2" />
                        <div class="d-flex justify-space-between align-center">
                            <span class="text-body-1 font-weight-bold">
                                {{ t('FakturaView.faktura.silverdat.summaryNet') }}
                            </span>
                            <span class="text-h6 font-weight-bold">
                                {{ formatCurrency(summary.totalNet) }}
                            </span>
                        </div>
                    </v-card>
                </template>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn
                    variant="text"
                    @click="onClose"
                    :disabled="importing"
                >
                    {{ t('FakturaView.common.cancel') }}
                </v-btn>
                <v-btn
                    v-if="importItems.length > 0"
                    color="orange-darken-2"
                    variant="elevated"
                    prepend-icon="mdi-file-import"
                    :loading="importing"
                    @click="$emit('do-import')"
                >
                    {{ importing
                        ? t('FakturaView.faktura.silverdat.importing')
                        : t('FakturaView.faktura.silverdat.import')
                    }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import { defineComponent, computed } from 'vue'
import { useI18n } from 'vue-i18n'

export default defineComponent({
    name: 'SilverdatImportDialog',

    props: {
        modelValue: { type: Boolean, default: false },
        importItems: { type: Array, default: () => [] },
        vehicleInfo: { type: Object, default: null },
        importing: { type: Boolean, default: false },
        importError: { type: String, default: '' },
        fileName: { type: String, default: '' },
        summary: { type: Object, default: () => ({}) }
    },

    emits: [
        'update:modelValue',
        'do-import',
        'close',
        'change-file',
        'clear-error'
    ],

    setup(props, { emit }) {
        const { t } = useI18n()

        const tableHeaders = computed(() => [
            { title: t('FakturaView.faktura.silverdat.type'), key: 'category', width: '80px', sortable: false },
            { title: t('FakturaView.faktura.silverdat.partNumber'), key: 'partnumber', width: '140px', sortable: false },
            { title: t('FakturaView.faktura.silverdat.description'), key: 'description', sortable: false },
            { title: t('FakturaView.faktura.silverdat.qty'), key: 'qty', width: '70px', align: 'end', sortable: false },
            { title: t('FakturaView.faktura.silverdat.unit'), key: 'unit', width: '50px', sortable: false },
            { title: t('FakturaView.faktura.silverdat.price'), key: 'sellprice', width: '90px', align: 'end', sortable: false },
            { title: t('FakturaView.faktura.silverdat.total'), key: 'total', width: '100px', align: 'end', sortable: false }
        ])

        function categoryColor(cat) {
            const colors = { part: 'blue', labour: 'green', lacquer: 'purple', additional: 'grey' }
            return colors[cat] || 'grey'
        }

        function categoryLabel(cat) {
            const labels = {
                part: t('FakturaView.faktura.silverdat.typePart'),
                labour: t('FakturaView.faktura.silverdat.typeLabour'),
                lacquer: t('FakturaView.faktura.silverdat.typeLacquer'),
                additional: t('FakturaView.faktura.silverdat.typeAdditional')
            }
            return labels[cat] || cat
        }

        function formatCurrency(value) {
            return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
        }

        function formatNumber(value) {
            if (Number.isInteger(value)) return value.toString()
            return new Intl.NumberFormat('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 2 }).format(value)
        }

        function onClose() {
            emit('close')
            emit('update:modelValue', false)
        }

        function onChangeFile() {
            emit('change-file')
        }

        return {
            t,
            tableHeaders,
            categoryColor,
            categoryLabel,
            formatCurrency,
            formatNumber,
            onClose,
            onChangeFile
        }
    }
})
</script>

<style scoped>
.silverdat-table :deep(.v-data-table__td) {
    font-size: 12px !important;
    padding: 4px 8px !important;
}

.silverdat-table :deep(.v-data-table__th) {
    font-size: 12px !important;
    padding: 4px 8px !important;
}
</style>
