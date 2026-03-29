<!-- src/core/views/config/tabs/warehouse.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ $t('defaultWarehouse') }}</h3>

        <v-row>
            <v-col cols="12">
                <v-select
                    v-model="defaults.transfer_default"
                    :label="$t('transferDefault')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('transferDefaultHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.transfer_default_services"
                    :label="$t('transferDefaultServices')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('transferDefaultServicesHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.warehouse_id"
                    :label="$t('defaultWarehouse')"
                    :items="warehouses"
                    item-title="description"
                    item-value="id"
                    variant="outlined"
                    density="compact"
                    @update:model-value="onWarehouseChange"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.bin_id"
                    :label="$t('defaultBin')"
                    :items="bins"
                    item-title="description"
                    item-value="id"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.transfer_default_use_master_default_bin"
                    :label="$t('transferDefaultUseMasterDefaultBin')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('withoutStockCheck') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.transfer_default_ignore_onhand"
                    :label="$t('transferDefaultIgnoreOnhand')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.warehouse_id_ignore_onhand"
                    :label="$t('warehouseIgnoreOnhand')"
                    :items="warehouses"
                    item-title="description"
                    item-value="id"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.bin_id_ignore_onhand"
                    :label="$t('binIgnoreOnhand')"
                    :items="bins"
                    item-title="description"
                    item-value="id"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('transferAndProduction') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.is_transfer_out"
                    :label="$t('isTransferOut')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('isTransferOutHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.sales_serial_eq_charge"
                    :label="$t('salesSerialEqCharge')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('salesSerialEqChargeHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.undo_transfer_interval_as_number"
                    :label="$t('undoTransferInterval')"
                    type="number"
                    variant="outlined"
                    density="compact"
                    suffix="Tage"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('undoTransferIntervalHelp') }}
                        </v-tooltip>
                    </template>
                </v-text-field>
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('bestBefore') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.show_bestbefore"
                    :label="$t('showBestbefore')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('showBestbeforeHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('assemblyProduction') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.produce_assembly_same_warehouse"
                    :label="$t('produceAssemblySameWarehouse')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.produce_assembly_transfer_service"
                    :label="$t('produceAssemblyTransferService')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('servicesInDeliveryOrders') }}</h3>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.sales_delivery_order_check_service"
                    :label="$t('salesDeliveryOrderCheckService')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.purchase_delivery_order_check_service"
                    :label="$t('purchaseDeliveryOrderCheckService')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('deliveryQuantityCalculation') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.shipped_qty_require_stock_out"
                    :label="$t('shippedQtyRequireStockOut')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('shippedQtyRequireStockOutHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { computed, defineProps } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t } = useI18n();
const store = oserpStore();

defineProps({
    defaults: {
        type: Object,
        required: true
    }
});

const yesNoOptions = [
    { title: t('yes'), value: true },
    { title: t('no'), value: false }
];

// Hole Lager und Lagerplätze direkt aus dem oserpStore (Singular wie in kivitendo)
const warehouses = computed(() => {
    return store.session?.company_config?.warehouse || [];
});

const bins = computed(() => {
    return store.session?.company_config?.bin || [];
});

/**
 * Reagiert auf Lager-Änderung
 */
function onWarehouseChange(warehouseId) {
    // Optional: Lagerplätze nach Lager filtern, wenn benötigt
}
</script>
