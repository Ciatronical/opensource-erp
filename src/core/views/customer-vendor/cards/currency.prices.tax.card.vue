<template>
  <section-card :title="t('CustomerVendorEditView.billing.currencyPricesTaxTitle')" icon="mdi-cash-multiple">
      <v-row density="compact">
        <v-col v-if="show('currency')" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.currency')"
            v-model="localData.currency_id"
            :items="currencies"
          />
        </v-col>
        <v-col v-if="show('pricegroup')" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.pricegroup')"
            v-model="localData.pricegroup_id"
            :items="pricegroups"
          />
        </v-col>
        <v-col v-if="show('taxzone')" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.taxzone')"
            v-model="localData.taxzone_id"
            :items="taxzones"
            item-title="title"
            item-value="value"
          />
        </v-col>
        <v-col v-if="show('taxincluded_checked')" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.taxincluded_checked')"
            v-model="localData.taxincluded_checked"
            :items="taxIncludedOptions"
          />
        </v-col>
        <v-col v-if="show('discount')" cols="6" sm="4" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.discount')"
            v-model="localData.discount"
            type="number"
            suffix="%"
          />
        </v-col>
        <v-col v-if="show('hourly_rate')" cols="6" sm="4" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.hourly_rate')"
            v-model="localData.hourly_rate"
            type="number"
          />
        </v-col>
        <v-col v-if="show('create_zugferd_invoices')" cols="12" sm="4" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.create_zugferd_invoices')"
            v-model="localData.create_zugferd_invoices"
            :items="zugferdOptions"
          >
            <template #append-inner>
              <v-tooltip location="top" max-width="400">
                <template #activator="{ props }">
                  <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                </template>
                <span style="white-space: pre-line;">{{ t('CustomerVendorEditView.zugferd.help_current', { option: currentZugferdHelp }) }}</span>
              </v-tooltip>
            </template>
          </v-select>
        </v-col>
        <v-col
          v-if="isXRechnungSelected && show('create_zugferd_invoices')"
          cols="12"
          class="py-1"
        >
          <v-alert
            type="info"
            variant="tonal"
            density="compact"
            icon="mdi-identifier"
          >
            {{ t('CustomerVendorEditView.zugferd.leitweg_hint') }}
          </v-alert>
        </v-col>
      </v-row>
  </section-card>
</template>

<script>
import { computed } from 'vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import { useI18n } from 'vue-i18n'
import { useCvEasymode } from '../composables/useCvEasymode.js'

export default {
  name: 'CurrencyPricesTaxCard',
  components: { SectionCard },
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
    const { show } = useCvEasymode()
    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })
    const currentZugferdHelp = computed(() => {
      const value = Number(localData.value.create_zugferd_invoices ?? -1)
      const map = {
        '-1': t('CustomerVendorEditView.zugferd.help_default'),
        '0':  t('CustomerVendorEditView.zugferd.help_off'),
        '1':  t('CustomerVendorEditView.zugferd.help_zugferd'),
        '2':  t('CustomerVendorEditView.zugferd.help_zugferd_test'),
        '3':  t('CustomerVendorEditView.zugferd.help_xrechnung'),
        '4':  t('CustomerVendorEditView.zugferd.help_xrechnung_test'),
      }
      return map[String(value)] || map['-1']
    })
    const isXRechnungSelected = computed(() => [3, 4].includes(Number(localData.value.create_zugferd_invoices)))
    return { localData, t, show, currentZugferdHelp, isXRechnungSelected }
  }
}
</script>
