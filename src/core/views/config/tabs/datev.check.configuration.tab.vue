<!-- src/components/settings/tabs/datev.check.configuration.tab.vue -->
<template>
    <div>
        <v-alert type="info" variant="tonal" class="mb-4">
            {{ $t('datevCheckInfo') }}
        </v-alert>

        <v-row>
            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.datev_check_on_sales_invoice"
                    :label="$t('checkOnSalesInvoice')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('checkOnSalesInvoiceHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.datev_check_on_purchase_invoice"
                    :label="$t('checkOnPurchaseInvoice')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('checkOnPurchaseInvoiceHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.datev_check_on_ar_transaction"
                    :label="$t('checkOnArTransaction')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('checkOnArTransactionHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.datev_check_on_ap_transaction"
                    :label="$t('checkOnApTransaction')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('checkOnApTransactionHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.datev_check_on_gl_transaction"
                    :label="$t('checkOnGlTransaction')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('checkOnGlTransactionHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.datev_export_format"
                    :label="$t('datevExportFormat')"
                    :items="datevExportFormatOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('datevExportFormatHelp') }}
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

const datevExportFormatOptions = [
    { title: t('strictWithAbort'), value: 'cp1252' },
    { title: t('strictWithReplacements'), value: 'cp1252-translit' },
    { title: t('laxUtf8'), value: 'utf-8' }
];
</script>
