<template>
  <v-card variant="outlined" elevation="1">
    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
      <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billing.infoStatusTitle') }}</h4>
    </v-card-title>
    <v-divider />
    <v-card-text class="py-2 px-2 px-sm-3">
      <v-row dense>
        <v-col v-if="entitySrc === 'C' && businessTypes && businessTypes.length" cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.business_type')"
            v-model="localData.business_id"
            :items="businessTypes"
            variant="outlined"
            density="compact"
            hide-details="auto"
            :disabled="businessTypes.length === 1"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.salesman')"
            v-model="localData.salesman_id"
            :items="salesmen"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-select
            :label="t('CustomerVendorEditView.fields.language')"
            v-model="localData.language_id"
            :items="languages"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1 d-flex align-center">
          <v-switch
            :label="t('CustomerVendorEditView.fields.obsolete')"
            v-model="localData.obsolete"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" class="py-1">
          <v-textarea
            :label="t('CustomerVendorEditView.fields.notes')"
            v-model="localData.notes"
            variant="outlined"
            density="compact"
            rows="3"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" class="py-1">
          <v-textarea
            :label="t('CustomerVendorEditView.fields.contact_origin')"
            v-model="localData.contact_origin"
            variant="outlined"
            density="compact"
            rows="3"
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
  name: 'InfoStatusCard',
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
