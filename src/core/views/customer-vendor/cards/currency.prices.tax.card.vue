<template>
  <v-card variant="outlined" elevation="1">
    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
      <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billing.currencyPricesTaxTitle') }}</h4>
    </v-card-title>
    <v-divider />
    <v-card-text class="py-2 px-2 px-sm-3">
      <v-row dense>
        <v-col cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.currency')"
            v-model="localData.currency_id"
            :items="currencies"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.pricegroup')"
            v-model="localData.pricegroup_id"
            :items="pricegroups"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.taxzone')"
            v-model="localData.taxzone_id"
            :items="taxzones"
            item-title="title"
            item-value="value"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.taxincluded_checked')"
            v-model="localData.taxincluded_checked"
            :items="taxIncludedOptions"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="6" sm="4" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.discount')"
            v-model="localData.discount"
            type="number"
            suffix="%"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="6" sm="4" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.hourly_rate')"
            v-model="localData.hourly_rate"
            type="number"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="4" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.create_zugferd_invoices')"
            v-model="localData.create_zugferd_invoices"
            :items="zugferdOptions"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
      </v-row>
    </v-card-text>
  </v-card>
</template>

<script>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

export default {
  name: 'CurrencyPricesTaxCard',
  props: {
    modelValue: { type: Object, required: true },
    currencies: Array,
    pricegroups: Array,
    taxzones: Array,
    taxIncludedOptions: Array,
    zugferdOptions: Array,
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { t } = useI18n()
    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })
    return { localData, t }
  }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
  background-color: #f5f5f5;
}
</style>
