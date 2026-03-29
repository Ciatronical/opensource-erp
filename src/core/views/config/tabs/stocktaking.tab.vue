<!-- src/components/settings/tabs/stocktaking.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ $t('stocktaking') }}</h3>

        <v-row>
            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.stocktaking_warehouse_id"
                    :label="$t('preselectedWarehouse')"
                    :items="warehouses"
                    variant="outlined"
                    density="compact"
                    @update:model-value="onWarehouseChange"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('preselectedWarehouseHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.stocktaking_bin_id"
                    :label="$t('preselectedBin')"
                    :items="bins"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('preselectedBinHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.stocktaking_cutoff_date"
                    :label="$t('preselectedCutoffDate')"
                    type="date"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('preselectedCutoffDateHelp') }}
                        </v-tooltip>
                    </template>
                </v-text-field>
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.stocktaking_qty_threshold_as_number"
                    :label="$t('qtyThreshold')"
                    type="number"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('qtyThresholdHelp') }}
                        </v-tooltip>
                    </template>
                </v-text-field>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { ref, defineProps } from 'vue';

defineProps({
    defaults: {
        type: Object,
        required: true
    }
});

const warehouses = ref([]);
const bins = ref([]);

function onWarehouseChange(warehouseId) {
    // Hier würden die Lagerplätze für das ausgewählte Lager geladen
    console.log('Warehouse changed:', warehouseId);
}
</script>
