<template>
  <v-row dense>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.name')" v-model="localData.name" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.contact')" v-model="localData.contact" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.department_1')" v-model="localData.department_1" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.department_2')" v-model="localData.department_2" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.street')" v-model="localData.street" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="4" sm="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.zipcode')" v-model="localData.zipcode" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="8" sm="5" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.city')" v-model="localData.city" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.country')" v-model="localData.country" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.email')" v-model="localData.email" variant="outlined" density="compact" hide-details="auto" :error-messages="emailError" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.dunning_mail')" v-model="localData.dunning_mail" variant="outlined" density="compact" hide-details="auto" :error-messages="dunningMailError" />
    </v-col>
    <v-col cols="6" sm="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.phone')" v-model="localData.phone" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.fax')" v-model="localData.fax" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.gln')" v-model="localData.gln" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" class="py-1">
      <v-switch :label="t('CustomerVendorEditView.fields.default_address')" v-model="localData.default_address" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" class="py-1">
      <v-btn color="error" size="small" variant="outlined" @click="$emit('remove')" prepend-icon="mdi-delete">
        {{ t('CustomerVendorEditView.billAddresses.removeButton') }}
      </v-btn>
    </v-col>
  </v-row>
</template>

<script>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

export default {
  name: 'BillingAddressForm',
  props: { modelValue: { type: Object, required: true } },
  emits: ['update:modelValue', 'remove'],
  setup(props, { emit }) {
    const { t } = useI18n()
    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })
    const RE_EMAIL = /^[^\s@]+@[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/
    function checkEmail(v) {
      if (!v) return []
      return RE_EMAIL.test(v.trim()) ? [] : [t('CustomerVendorEditView.email.invalid')]
    }

    function useDnsCheck(emailGetter) {
      const dnsError = ref('')
      let timer = null
      let lastChecked = ''
      watch(emailGetter, (val) => {
        dnsError.value = ''
        if (timer) clearTimeout(timer)
        if (!val || !RE_EMAIL.test(val.trim())) return
        timer = setTimeout(async () => {
          const email = val.trim()
          lastChecked = email
          try {
            const resp = await axios.post('/api/customer_vendor/', { action: 'validateEmail', email })
            if (lastChecked === email && resp.data?.payload?.error === 'dns') {
              dnsError.value = resp.data.payload.message
            }
          } catch (e) { /* Netzwerkfehler ignorieren */ }
        }, 800)
      })
      return dnsError
    }

    const emailDns = useDnsCheck(() => localData.value.email)
    const dunningMailDns = useDnsCheck(() => localData.value.dunning_mail)

    const emailError = computed(() => {
      const errors = checkEmail(localData.value.email)
      if (emailDns.value) errors.push(emailDns.value)
      return errors
    })
    const dunningMailError = computed(() => {
      const errors = checkEmail(localData.value.dunning_mail)
      if (dunningMailDns.value) errors.push(dunningMailDns.value)
      return errors
    })
    return { localData, t, emailError, dunningMailError }
  }
}
</script>
