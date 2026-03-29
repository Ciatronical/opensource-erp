<!-- src/components/settings/tabs/default.accounts.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ $t('defaultAccounts') }}</h3>

        <v-row>
            <v-col cols="12">
                <v-text-field
                    v-model="defaults.inventory_accno_id"
                    :label="$t('inventoryAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.income_accno_id"
                    :label="$t('incomeAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.expense_accno_id"
                    :label="$t('expenseAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.fxgain_accno_id"
                    :label="$t('fxGainAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.fxloss_accno_id"
                    :label="$t('fxLossAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.rndgain_accno_id"
                    :label="$t('rndGainAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.rndloss_accno_id"
                    :label="$t('rndLossAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.ar_paid_accno_id"
                    :label="$t('arPaidAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.ap_chart_id"
                    :label="$t('apAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.ar_chart_id"
                    :label="$t('arAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.advance_payment_clearing_chart_id"
                    :label="$t('advancePaymentClearingAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.workflow_po_ap_chart_id"
                    :label="$t('workflowPoApAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('yearEnd') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.carry_over_account_chart_id"
                    :label="$t('carryOverAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.profit_carried_forward_chart_id"
                    :label="$t('profitCarriedForwardAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.loss_carried_forward_chart_id"
                    :label="$t('lossCarriedForwardAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.transit_items_chart_id"
                    :label="$t('transitItemsAccount')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { defineProps } from 'vue';

defineProps({
    defaults: {
        type: Object,
        required: true
    }
});
</script>
