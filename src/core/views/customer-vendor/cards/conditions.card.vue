<template>
  <v-card variant="outlined" elevation="1">
    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
      <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billing.conditionsTitle') }}</h4>
    </v-card-title>
    <v-divider />
    <v-card-text class="py-2 px-2 px-sm-3">
      <v-row dense>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.creditlimit')"
            v-model="localData.creditlimit"
            type="number"
            variant="outlined"
            density="compact"
            hide-details="auto"
            data-field="creditlimit"
          />
        </v-col>
        <v-col cols="6" sm="6" class="py-1 d-flex align-center">
          <v-switch
            :label="t('CustomerVendorEditView.fields.dunning_lock')"
            v-model="localData.dunning_lock"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="6" sm="6" class="py-1 d-flex align-center">
          <v-switch
            :label="t('CustomerVendorEditView.fields.order_lock')"
            v-model="localData.order_lock"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1 d-flex align-center">
          <v-switch
            :label="t('CustomerVendorEditView.fields.direct_debit')"
            v-model="localData.direct_debit"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.payment_terms')"
            v-model="localData.payment_id"
            :items="paymentTerms"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.delivery_terms')"
            v-model="localData.delivery_term_id"
            :items="deliveryTerms"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <!-- LxCars: HU-Benachrichtigung fuer alle Fahrzeuge des Kunden (Kunde zaehlt mehr als Fahrzeug) -->
        <v-col v-if="showHuSwitch" cols="12" class="py-1 d-flex align-center">
          <v-switch
            :model-value="!localData.hu_serienbrief_excluded"
            :label="t('CustomerVendorEditView.fields.hu_notify')"
            :color="!localData.hu_serienbrief_excluded ? 'success' : undefined"
            density="compact"
            hide-details="auto"
            @update:model-value="val => localData.hu_serienbrief_excluded = !val"
          />
          <v-tooltip location="top" max-width="320" :text="t('CustomerVendorEditView.fields.hu_notify_hint')">
            <template #activator="{ props: tp }">
              <v-icon v-bind="tp" size="small" color="grey" class="ms-2">mdi-information-outline</v-icon>
            </template>
          </v-tooltip>
        </v-col>
      </v-row>
    </v-card-text>
  </v-card>
</template>

<script>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'

export default {
  name: 'ConditionsCard',
  props: {
    modelValue: { type: Object, required: true },
    paymentTerms: Array,
    deliveryTerms: Array,
    entitySrc: { type: String, default: 'C' },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { t } = useI18n()
    const oserp = oserpStore()
    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })
    // Nur bei Kunden (nicht Lieferanten) und aktiviertem LxCars
    const showHuSwitch = computed(() => props.entitySrc !== 'V' && oserp.isLxCars && oserp.isLxCars())
    return { localData, t, showHuSwitch }
  }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
  background-color: #f5f5f5;
}
</style>
