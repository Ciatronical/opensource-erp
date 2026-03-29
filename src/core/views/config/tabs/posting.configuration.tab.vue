<!-- src/components/settings/tabs/posting.configuration.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ $t('changeabilityAfterPosting') }}</h3>

        <v-row>
            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.is_changeable"
                    :label="$t('salesInvoices')"
                    :items="changeabilityOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.ir_changeable"
                    :label="$t('purchaseInvoices')"
                    :items="changeabilityOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.ar_changeable"
                    :label="$t('arTransactions')"
                    :items="changeabilityOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.ap_changeable"
                    :label="$t('apTransactions')"
                    :items="changeabilityOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.gl_changeable"
                    :label="$t('glTransactions')"
                    :items="changeabilityOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.payments_changeable"
                    :label="$t('payments')"
                    :items="changeabilityOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.sales_purchase_record_numbers_changeable"
                    :label="$t('recordNumbersChangeable')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('recordNumbersChangeableHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('showMarkAsPaid') }}</h3>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.is_show_mark_as_paid"
                    :label="$t('salesInvoices')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.ir_show_mark_as_paid"
                    :label="$t('purchaseInvoices')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.ar_show_mark_as_paid"
                    :label="$t('arTransactions')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.ap_show_mark_as_paid"
                    :label="$t('apTransactions')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('addDocumentFor') }}</h3>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.ir_add_doc"
                    :label="$t('purchaseInvoices')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.ar_add_doc"
                    :label="$t('arTransactions')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.ap_add_doc"
                    :label="$t('apTransactions')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.gl_add_doc"
                    :label="$t('glTransactions')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('methodsAndSettings') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.accounting_method"
                    :label="$t('accountingMethod')"
                    :items="accountingMethodOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.inventory_system"
                    :label="$t('inventorySystem')"
                    :items="inventorySystemOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.profit_determination"
                    :label="$t('profitDetermination')"
                    :items="profitDeterminationOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.balance_startdate_method"
                    :label="$t('balanceStartdateMethod')"
                    :items="balanceStartdateMethodOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.yearend_method"
                    :label="$t('yearendMethod')"
                    :items="yearendMethodOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.reqdate_on"
                    :label="$t('reqdateOn')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.reqdate_interval_as_number"
                    :label="$t('reqdateInterval')"
                    type="number"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.deliverydate_on"
                    :label="$t('deliverydateOn')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.delivery_date_interval_as_number"
                    :label="$t('deliveryDateInterval')"
                    type="number"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.fuzzy_skonto"
                    :label="$t('fuzzySkonto')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.fuzzy_skonto_percentage_as_number"
                    :label="$t('fuzzySkontoPercentage')"
                    type="number"
                    suffix="%"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.check_bt_duplicates_endtoend"
                    :label="$t('checkBtDuplicatesEndtoend')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
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

const changeabilityOptions = [
    { title: t('never'), value: 0 },
    { title: t('always'), value: 1 },
    { title: t('sameDay'), value: 2 }
];

const yesNoOptions = [
    { title: t('yes'), value: true },
    { title: t('no'), value: false }
];

const accountingMethodOptions = [
    { title: t('accrual'), value: 'accrual' },
    { title: t('cash'), value: 'cash' }
];

const inventorySystemOptions = [
    { title: t('perpetual'), value: 'perpetual' },
    { title: t('periodic'), value: 'periodic' }
];

const profitDeterminationOptions = [
    { title: t('balance'), value: 'balance' },
    { title: t('income'), value: 'income' }
];

const balanceStartdateMethodOptions = [
    { title: t('closedTo'), value: 'closed_to' },
    { title: t('startOfYear'), value: 'start_of_year' },
    { title: t('allTransactions'), value: 'all_transactions' },
    { title: t('lastObOrAllTransactions'), value: 'last_ob_or_all_transactions' },
    { title: t('lastObOrStartOfYear'), value: 'last_ob_or_start_of_year' }
];

const yearendMethodOptions = [
    { title: t('default'), value: 'default' },
    { title: t('simple'), value: 'simple' }
];
</script>
