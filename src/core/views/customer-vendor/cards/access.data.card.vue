<template>
  <section-card :title="t('CustomerVendorEditView.billing.accessDataTitle')" icon="mdi-key-outline">
      <v-row density="compact">
        <v-col v-if="show('username')" cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.username')"
            v-model="localData.username"
          />
        </v-col>
        <v-col v-if="show('password')" cols="12" sm="6" class="py-1">
          <password-field
            :label="t('CustomerVendorEditView.fields.password')"
            v-model="localData.user_password"
            variant="outlined"
            density="compact"
            hide-details="auto"
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
import PasswordField from '@/core/components/password-field.vue'

export default {
  name: 'AccessDataCard',
  components: { SectionCard,  PasswordField },
  props: {
    modelValue: { type: Object, required: true },
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
