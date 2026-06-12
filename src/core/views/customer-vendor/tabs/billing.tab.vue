<!-- src/core/views/customer-vendor/tabs/billing.tab.vue -->
<template>
    <v-row class="pa-2 pa-sm-3">
        <!-- Linke Spalte: Stammdaten -->
        <v-col cols="12" lg="6">
            <name-address-card v-model="localData" :phone-numbers="phoneNumbers" />
            <communication-card v-model="localData" class="mt-3" />
            <info-status-card
                v-model="localData"
                :business-types="businessTypes"
                :salesmen="salesmen"
                :languages="languages"
                :entity-src="entitySrc"
                class="mt-3"
            />
        </v-col>
        <!-- Rechte Spalte: IDs, Finanzen, Bedingungen -->
        <v-col cols="12" lg="6">
            <numbers-ids-card v-model="localData" :entity-src="entitySrc" />
            <access-data-card v-model="localData" class="mt-3" />
            <currency-prices-tax-card
                v-model="localData"
                :currencies="currencies"
                :pricegroups="pricegroups"
                :taxzones="taxzones"
                :tax-included-options="taxIncludedOptions"
                :zugferd-options="zugferdOptions"
                class="mt-3"
            />
            <conditions-card
                v-model="localData"
                :payment-terms="paymentTerms"
                :delivery-terms="deliveryTerms"
                class="mt-3"
            />
        </v-col>
    </v-row>
</template>

<script>
import { computed } from 'vue'
import NameAddressCard from '../cards/name.address.card.vue'
import CommunicationCard from '../cards/communication.card.vue'
import InfoStatusCard from '../cards/info.status.card.vue'
import NumbersIdsCard from '../cards/numbers.ids.card.vue'
import AccessDataCard from '../cards/access.data.card.vue'
import CurrencyPricesTaxCard from '../cards/currency.prices.tax.card.vue'
import ConditionsCard from '../cards/conditions.card.vue'

export default {
    name: 'BillingTab',
    components: {
        NameAddressCard,
        CommunicationCard,
        InfoStatusCard,
        NumbersIdsCard,
        AccessDataCard,
        CurrencyPricesTaxCard,
        ConditionsCard,
    },
    props: {
        modelValue: { type: Object, required: true },
        entitySrc: { type: String, default: 'C' },
        businessTypes: Array,
        salesmen: Array,
        languages: Array,
        currencies: Array,
        pricegroups: Array,
        taxzones: Array,
        taxIncludedOptions: Array,
        zugferdOptions: Array,
        paymentTerms: Array,
        deliveryTerms: Array,
        phoneNumbers: { type: Array, default: () => [] },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const localData = computed({
            get: () => props.modelValue,
            set: (value) => emit('update:modelValue', value)
        })
        return { localData, phoneNumbers: computed(() => props.phoneNumbers) }
    }
}
</script>