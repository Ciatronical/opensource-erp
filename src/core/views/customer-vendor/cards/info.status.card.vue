<template>
  <section-card :title="t('CustomerVendorEditView.billing.infoStatusTitle')" icon="mdi-information-outline">
      <v-row density="compact">
        <v-col v-if="entitySrc === 'C' && businessTypes && businessTypes.length && show('business_type')" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.business_type')"
            v-model="localData.business_id"
            :items="businessTypes"
            :disabled="businessTypes.length === 1"
          />
        </v-col>
        <v-col v-if="show('salesman')" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.salesman')"
            v-model="localData.salesman_id"
            :items="salesmen"
          />
        </v-col>
        <v-col v-if="show('language')" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.language')"
            v-model="localData.language_id"
            :items="languages"
          />
        </v-col>
        <v-col v-if="show('obsolete')" cols="12" sm="6" class="py-1 d-flex align-center">
          <v-switch
            :label="t('CustomerVendorEditView.fields.obsolete')"
            v-model="localData.obsolete"
          />
        </v-col>
        <v-col v-if="show('notes')" cols="12" class="py-1">
          <v-textarea
            :label="t('CustomerVendorEditView.fields.notes')"
            v-model="localData.notes"
            rows="3"
            data-field="notes"
          />
        </v-col>
        <v-col v-if="show('contact_origin')" cols="12" class="py-1">
          <v-textarea
            :label="t('CustomerVendorEditView.fields.contact_origin')"
            v-model="localData.contact_origin"
            rows="3"
          />
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
  name: 'InfoStatusCard',
  components: { SectionCard },
  props: {
    modelValue: { type: Object, required: true },
    businessTypes: Array,
    salesmen: Array,
    languages: Array,
    entitySrc: { type: String, default: 'C' },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { t } = useI18n()
    const { show } = useCvEasymode()
    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })
    return { localData, t, show }
  }
}
</script>
