<!-- src/components/settings/tabs/orders.deleteable.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ $t('ordersDeliveryOrdersDeletable') }}</h3>

        <v-row>
            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.sales_order_show_delete"
                    :label="$t('salesOrders')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('showDeleteButtonForSalesOrders') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.purchase_order_show_delete"
                    :label="$t('purchaseOrders')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('showDeleteButtonForPurchaseOrders') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.sales_delivery_order_show_delete"
                    :label="$t('salesDeliveryOrders')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('showDeleteButtonForSalesDeliveryOrders') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.purchase_delivery_order_show_delete"
                    :label="$t('purchaseDeliveryOrders')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('showDeleteButtonForPurchaseDeliveryOrders') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { defineProps } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
</script>
