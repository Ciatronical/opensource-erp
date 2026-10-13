<!-- src/core/views/faktura/dialogs/gutachten.import.dialog.vue -->
<!--
    Vorschau eines per KI ausgelesenen Gutachtens (PDF/Foto) vor der Übernahme
    in den Auftrag: Fahrzeug, Gutachtendaten, abwählbare Positionen, Summen.
-->

<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="1040"
        persistent
        scrollable
    >
        <v-card>
            <v-card-title class="d-flex align-center bg-deep-orange-darken-2">
                <v-icon class="mr-2">mdi-file-pdf-box</v-icon>
                {{ t('FakturaView.faktura.gutachten.title') }}
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
                <!-- Analyse läuft -->
                <div v-if="analyzing" class="d-flex flex-column align-center py-10">
                    <v-progress-circular indeterminate color="deep-orange-darken-2" size="48" class="mb-4" />
                    <div class="text-body-1">{{ t('FakturaView.faktura.gutachten.analyzing') }}</div>
                    <div class="text-caption text-grey mt-1">{{ fileName }}</div>
                </div>

                <template v-else>
                    <!-- Fehler -->
                    <v-alert v-if="importError" type="error" variant="tonal" class="mb-4" closable @click:close="$emit('clear-error')">
                        {{ importError }}
                    </v-alert>

                    <template v-if="hasResult">
                        <!-- Dateiname + Aktionen -->
                        <div class="d-flex align-center flex-wrap mb-3">
                            <v-icon size="small" class="mr-1">mdi-file-pdf-box</v-icon>
                            <span class="text-body-2 text-grey-darken-1">{{ fileName }}</span>
                            <v-chip
                                size="x-small"
                                class="ml-2"
                                :color="confidence >= 0.85 ? 'green' : confidence >= 0.7 ? 'orange' : 'red'"
                                variant="tonal"
                                label
                            >
                                {{ t('FakturaView.faktura.gutachten.confidence') }} {{ Math.round(confidence * 100) }} %
                            </v-chip>
                            <v-btn variant="text" size="x-small" class="ml-2" prepend-icon="mdi-refresh" @click="$emit('reanalyze')">
                                {{ t('FakturaView.faktura.gutachten.reanalyze') }}
                            </v-btn>
                            <v-btn variant="text" size="x-small" @click="$emit('change-file')">
                                {{ t('FakturaView.faktura.silverdat.changeFile') }}
                            </v-btn>
                            <v-spacer />
                            <!-- Modellwahl für die Sitzung – wirkt beim erneuten Auslesen -->
                            <ai-model-button assistant="gutachten_import" size="x-small" />
                        </div>

                        <v-row dense class="mb-2">
                            <!-- Fahrzeug -->
                            <v-col cols="12" md="6">
                                <v-card variant="outlined" class="pa-3 h-100">
                                    <div class="d-flex align-center mb-1">
                                        <v-icon size="small" class="mr-1">mdi-car</v-icon>
                                        <span class="text-subtitle-2">{{ t('FakturaView.faktura.gutachten.vehicle') }}</span>
                                        <v-spacer />
                                        <v-chip v-if="vehicleAction === 'linked'" size="x-small" color="teal" variant="tonal" label>
                                            {{ t('FakturaView.faktura.gutachten.vehicleLinked', { plate: context?.c_ln || '' }) }}
                                        </v-chip>
                                        <v-chip v-else-if="vehicleAction === 'create'" size="x-small" color="deep-orange" variant="tonal" label>
                                            {{ t('FakturaView.faktura.gutachten.vehicleCreate', { plate: vehicle.plate_normalized }) }}
                                        </v-chip>
                                        <v-chip v-else-if="vehicleAction === 'plate_invalid'" size="x-small" color="red" variant="tonal" label>
                                            {{ t('FakturaView.faktura.gutachten.plateInvalid') }}
                                        </v-chip>
                                    </div>
                                    <template v-if="vehicle">
                                        <div class="text-body-2">
                                            <strong>{{ [vehicle.manufacturer, vehicle.model].filter(Boolean).join(' ') || '–' }}</strong>
                                        </div>
                                        <div class="text-body-2 d-flex flex-wrap ga-3 mt-1">
                                            <span v-if="vehicle.license_plate">{{ vehicle.license_plate }}</span>
                                            <span v-if="vehicle.mileage">{{ vehicle.mileage.toLocaleString('de-DE') }} km</span>
                                            <span v-if="vehicle.first_registration">EZ {{ formatDate(vehicle.first_registration) }}</span>
                                            <span v-if="vehicle.color">{{ vehicle.color }}</span>
                                        </div>
                                        <div v-if="vehicle.vin" class="text-caption text-grey">FIN: {{ vehicle.vin }}</div>
                                        <v-alert
                                            v-if="plateMismatch"
                                            type="warning"
                                            variant="tonal"
                                            density="compact"
                                            class="mt-2"
                                        >
                                            {{ t('FakturaView.faktura.gutachten.plateMismatch', { pdf: vehicle.license_plate, order: context.c_ln }) }}
                                        </v-alert>
                                    </template>
                                    <div v-else class="text-body-2 text-grey">{{ t('FakturaView.faktura.gutachten.noVehicle') }}</div>
                                </v-card>
                            </v-col>

                            <!-- Gutachten -->
                            <v-col cols="12" md="6">
                                <v-card variant="outlined" class="pa-3 h-100">
                                    <div class="d-flex align-center mb-1">
                                        <v-icon size="small" class="mr-1">mdi-file-document-outline</v-icon>
                                        <span class="text-subtitle-2">{{ t('FakturaView.faktura.gutachten.appraisal') }}</span>
                                    </div>
                                    <template v-if="appraisal">
                                        <div class="text-body-2">
                                            <strong v-if="appraisal.number">{{ t('FakturaView.faktura.gutachten.number') }} {{ appraisal.number }}</strong>
                                            <span v-if="appraisal.date" class="text-grey ml-1">{{ formatDate(appraisal.date) }}</span>
                                        </div>
                                        <div v-if="appraisal.appraiser" class="text-body-2">{{ appraisal.appraiser }}</div>
                                        <div v-if="appraisal.insurer" class="text-body-2">
                                            <span class="text-grey">{{ t('FakturaView.faktura.gutachten.insurer') }}:</span> {{ appraisal.insurer }}
                                        </div>
                                        <div v-if="appraisal.claim_number" class="text-body-2">
                                            <span class="text-grey">{{ t('FakturaView.faktura.gutachten.claimNumber') }}:</span> {{ appraisal.claim_number }}
                                        </div>
                                        <div v-if="appraisal.damage_description" class="text-caption text-grey-darken-1 mt-1">
                                            {{ appraisal.damage_description }}
                                        </div>
                                    </template>
                                </v-card>
                            </v-col>
                        </v-row>

                        <!-- Übernahme-Optionen -->
                        <div class="d-flex flex-wrap ga-4 mb-2">
                            <v-checkbox
                                v-model="options.apply_vehicle"
                                :label="vehicleAction === 'create'
                                    ? t('FakturaView.faktura.gutachten.optCreateVehicle')
                                    : t('FakturaView.faktura.gutachten.optUpdateVehicle')"
                                :disabled="vehicleAction === 'none' || vehicleAction === 'plate_invalid'"
                                density="compact"
                                hide-details
                            />
                            <v-checkbox
                                v-model="options.apply_km"
                                :label="t('FakturaView.faktura.gutachten.optKm', { km: (vehicle?.mileage || 0).toLocaleString('de-DE') })"
                                :disabled="!vehicle?.mileage"
                                density="compact"
                                hide-details
                            />
                            <v-checkbox
                                v-model="options.apply_notes"
                                :label="t('FakturaView.faktura.gutachten.optNotes')"
                                density="compact"
                                hide-details
                            />
                            <v-checkbox
                                v-model="options.store_pdf"
                                :label="t('FakturaView.faktura.gutachten.optStorePdf')"
                                density="compact"
                                hide-details
                            />
                        </div>

                        <!-- Positionen -->
                        <v-data-table
                            v-if="items.length"
                            :headers="tableHeaders"
                            :items="items"
                            :items-per-page="-1"
                            density="compact"
                            class="gutachten-table mb-3"
                            fixed-header
                            height="340"
                        >
                            <template #header.selected>
                                <v-checkbox-btn
                                    :model-value="allSelected"
                                    :indeterminate="someSelected && !allSelected"
                                    density="compact"
                                    @update:model-value="toggleAll"
                                />
                            </template>
                            <template #item.selected="{ item }">
                                <v-checkbox-btn v-model="item.selected" density="compact" />
                            </template>
                            <template #item.category="{ item }">
                                <v-chip :color="categoryColor(item.category)" size="x-small" variant="tonal" label>
                                    {{ categoryLabel(item.category) }}
                                </v-chip>
                            </template>
                            <template #item.description="{ item }">
                                <span :class="{ 'text-grey text-decoration-line-through': !item.selected }">{{ item.description }}</span>
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
                        <v-alert v-else type="info" variant="tonal" density="compact" class="mb-3">
                            {{ t('FakturaView.faktura.gutachten.noItems') }}
                        </v-alert>

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
                            <div v-if="appraisal?.repair_costs_net" class="d-flex justify-space-between align-center text-body-2">
                                <span class="text-grey">{{ t('FakturaView.faktura.gutachten.repairCostsPdf') }}</span>
                                <span :class="deltaWarning ? 'text-orange-darken-3 font-weight-bold' : 'text-grey'">
                                    {{ formatCurrency(appraisal.repair_costs_net) }}
                                    <span v-if="deltaWarning">({{ deltaSelected > 0 ? '+' : '' }}{{ formatCurrency(deltaSelected) }})</span>
                                </span>
                            </div>
                            <div v-if="aiNotes" class="text-caption text-grey-darken-1 mt-2">
                                <v-icon size="x-small" class="mr-1">mdi-information-outline</v-icon>{{ aiNotes }}
                            </div>
                        </v-card>
                    </template>
                </template>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="onClose" :disabled="importing">
                    {{ t('FakturaView.common.cancel') }}
                </v-btn>
                <v-btn
                    v-if="hasResult && !analyzing"
                    color="deep-orange-darken-2"
                    variant="elevated"
                    prepend-icon="mdi-file-import"
                    :loading="importing"
                    :disabled="!canImport"
                    @click="$emit('do-import')"
                >
                    {{ importing
                        ? t('FakturaView.faktura.silverdat.importing')
                        : t('FakturaView.faktura.gutachten.import', { count: summary.totalCount })
                    }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import { defineComponent, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AiModelButton from '@/core/components/ai-model-button.vue'

export default defineComponent({
    name: 'GutachtenImportDialog',

    components: { AiModelButton },

    props: {
        modelValue: { type: Boolean, default: false },
        items: { type: Array, default: () => [] },
        vehicle: { type: Object, default: null },
        appraisal: { type: Object, default: null },
        context: { type: Object, default: null },
        options: { type: Object, default: () => ({}) },
        vehicleAction: { type: String, default: 'none' },
        plateMismatch: { type: Boolean, default: false },
        confidence: { type: Number, default: 0 },
        aiNotes: { type: String, default: '' },
        analyzing: { type: Boolean, default: false },
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
        'reanalyze',
        'clear-error'
    ],

    setup(props, { emit }) {
        const { t } = useI18n()

        const hasResult = computed(() => props.items.length > 0 || !!props.vehicle?.license_plate || !!props.appraisal?.number)

        const allSelected = computed(() => props.items.length > 0 && props.items.every(i => i.selected))
        const someSelected = computed(() => props.items.some(i => i.selected))

        // Übernehmen lohnt sich, sobald Positionen gewählt sind oder Fahrzeug/km/Notizen übernommen werden
        const canImport = computed(() =>
            props.summary.totalCount > 0
            || (props.options.apply_vehicle && (props.vehicleAction === 'create' || props.vehicleAction === 'linked'))
            || (props.options.apply_km && props.vehicle?.mileage)
            || (props.options.apply_notes && props.appraisal?.number)
        )

        // Abweichung der gewählten Positionen zu den Reparaturkosten laut Gutachten (> 1 %)
        const deltaSelected = computed(() => (props.summary.totalNet || 0) - (props.appraisal?.repair_costs_net || 0))
        const deltaWarning = computed(() =>
            !!props.appraisal?.repair_costs_net && Math.abs(deltaSelected.value) > Math.max(1, props.appraisal.repair_costs_net * 0.01)
        )

        const tableHeaders = computed(() => [
            { title: '', key: 'selected', width: '40px', sortable: false },
            { title: t('FakturaView.faktura.silverdat.type'), key: 'category', width: '80px', sortable: false },
            { title: t('FakturaView.faktura.silverdat.partNumber'), key: 'partnumber', width: '130px', sortable: false },
            { title: t('FakturaView.faktura.silverdat.description'), key: 'description', sortable: false },
            { title: t('FakturaView.faktura.silverdat.qty'), key: 'qty', width: '70px', align: 'end', sortable: false },
            { title: t('FakturaView.faktura.silverdat.unit'), key: 'unit', width: '55px', sortable: false },
            { title: t('FakturaView.faktura.silverdat.price'), key: 'sellprice', width: '90px', align: 'end', sortable: false },
            { title: t('FakturaView.faktura.silverdat.total'), key: 'total', width: '100px', align: 'end', sortable: false }
        ])

        function toggleAll(value) {
            props.items.forEach(i => { i.selected = !!value })
        }

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

        function formatDate(iso) {
            const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '')
            return m ? `${m[3]}.${m[2]}.${m[1]}` : (iso || '')
        }

        function onClose() {
            emit('close')
            emit('update:modelValue', false)
        }

        return {
            t,
            hasResult,
            allSelected,
            someSelected,
            canImport,
            deltaSelected,
            deltaWarning,
            tableHeaders,
            toggleAll,
            categoryColor,
            categoryLabel,
            formatCurrency,
            formatNumber,
            formatDate,
            onClose
        }
    }
})
</script>

<style scoped>
.gutachten-table :deep(.v-data-table__td) {
    font-size: 12px !important;
    padding: 4px 8px !important;
}

.gutachten-table :deep(.v-data-table__th) {
    font-size: 12px !important;
    padding: 4px 8px !important;
}
</style>
