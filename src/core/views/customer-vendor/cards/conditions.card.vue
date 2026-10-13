<template>
  <section-card :title="t('CustomerVendorEditView.billing.conditionsTitle')" icon="mdi-handshake-outline">
      <v-row density="compact">
        <v-col v-if="show('creditlimit')" cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.creditlimit')"
            v-model="localData.creditlimit"
            type="number"
            data-field="creditlimit"
          />
        </v-col>
        <v-col v-if="show('dunning_lock')" cols="6" sm="6" class="py-1 d-flex align-center">
          <v-switch
            :label="t('CustomerVendorEditView.fields.dunning_lock')"
            v-model="localData.dunning_lock"
          />
        </v-col>
        <v-col v-if="show('order_lock')" cols="6" sm="6" class="py-1 d-flex align-center">
          <v-switch
            :label="t('CustomerVendorEditView.fields.order_lock')"
            v-model="localData.order_lock"
          />
        </v-col>
        <v-col v-if="show('direct_debit')" cols="12" sm="6" class="py-1 d-flex align-center">
          <v-switch
            :label="t('CustomerVendorEditView.fields.direct_debit')"
            v-model="localData.direct_debit"
          />
        </v-col>
        <v-col v-if="show('payment_terms')" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.payment_terms')"
            v-model="localData.payment_id"
            :items="paymentTerms"
          />
        </v-col>
        <v-col v-if="show('delivery_terms')" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.delivery_terms')"
            v-model="localData.delivery_term_id"
            :items="deliveryTerms"
          />
        </v-col>
        <!-- LxCars: HU-Benachrichtigung fuer alle Fahrzeuge des Kunden (Kunde zaehlt mehr als Fahrzeug) -->
        <v-col v-if="showHuSwitch && show('hu_notify')" cols="12" class="py-1 d-flex align-center">
          <v-switch
            :model-value="!localData.hu_serienbrief_excluded"
            :label="t('CustomerVendorEditView.fields.hu_notify')"
            :color="!localData.hu_serienbrief_excluded ? 'success' : undefined"
            @update:model-value="val => localData.hu_serienbrief_excluded = !val"
          />
          <v-tooltip location="top" max-width="320" :text="t('CustomerVendorEditView.fields.hu_notify_hint')">
            <template #activator="{ props: tp }">
              <v-icon v-bind="tp" size="small" color="grey" class="ms-2">mdi-information-outline</v-icon>
            </template>
          </v-tooltip>
        </v-col>
      </v-row>
  </section-card>
</template>

<script>
import { computed } from 'vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import { useI18n } from 'vue-i18n'
import { useCvEasymode } from '../composables/useCvEasymode.js'
import { oserpStore } from '@/core/stores/oserp.store.js'

export default {
  name: 'ConditionsCard',
  components: { SectionCard },
  props: {
    modelValue: { type: Object, required: true },
    paymentTerms: Array,
    deliveryTerms: Array,
    entitySrc: { type: String, default: 'C' },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { t } = useI18n()
    const { show } = useCvEasymode()
    const oserp = oserpStore()
    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })
    // Nur bei Kunden (nicht Lieferanten) und aktiviertem LxCars
    const showHuSwitch = computed(() => props.entitySrc !== 'V' && oserp.isLxCars && oserp.isLxCars())
    return { localData, t, show, showHuSwitch }
  }
}
</script>
