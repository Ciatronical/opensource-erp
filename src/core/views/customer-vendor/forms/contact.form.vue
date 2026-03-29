<template>
  <v-row dense>
    <v-col cols="6" sm="4" md="2" class="py-1">
      <v-select :label="t('CustomerVendorEditView.fields.gender')" v-model="localData.cp_gender" :items="[{title: t('CustomerVendorEditView.fields.gender_m'), value: 'm'}, {title: t('CustomerVendorEditView.fields.gender_f'), value: 'f'}]" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="4" md="2" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.title')" v-model="localData.cp_title" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="4" md="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.givenname')" v-model="localData.cp_givenname" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" md="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.lastname')" v-model="localData.cp_name" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" md="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.department')" v-model="localData.cp_abteilung" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" md="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.position')" v-model="localData.cp_position" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" md="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.birthday')" v-model="localData.cp_birthday" type="date" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.email_business')" v-model="localData.cp_email" variant="outlined" density="compact" hide-details="auto" :error-messages="cpEmailError" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.email_private')" v-model="localData.cp_privatemail" variant="outlined" density="compact" hide-details="auto" :error-messages="cpPrivatemailError" />
    </v-col>
    <v-col cols="6" sm="6" md="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.phone1')" v-model="localData.cp_phone1" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="6" md="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.phone2')" v-model="localData.cp_phone2" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="6" md="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.mobile1')" v-model="localData.cp_mobile1" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="6" md="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.mobile2')" v-model="localData.cp_mobile2" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="4" md="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.fax')" v-model="localData.cp_fax" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="4" md="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.phone_private')" v-model="localData.cp_privatphone" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="4" md="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.street')" v-model="localData.cp_street" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="4" sm="4" md="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.zipcode')" v-model="localData.cp_zipcode" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="8" sm="8" md="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.city')" v-model="localData.cp_city" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" class="py-1">
      <v-btn color="error" size="small" variant="outlined" @click="$emit('remove')" prepend-icon="mdi-delete">
        {{ t('CustomerVendorEditView.contacts.removeButton') }}
      </v-btn>
    </v-col>
  </v-row>
</template>

<script>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

export default {
  name: 'ContactForm',
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

    const cpEmailDns = useDnsCheck(() => localData.value.cp_email)
    const cpPrivatemailDns = useDnsCheck(() => localData.value.cp_privatemail)

    const cpEmailError = computed(() => {
      const errors = checkEmail(localData.value.cp_email)
      if (cpEmailDns.value) errors.push(cpEmailDns.value)
      return errors
    })
    const cpPrivatemailError = computed(() => {
      const errors = checkEmail(localData.value.cp_privatemail)
      if (cpPrivatemailDns.value) errors.push(cpPrivatemailDns.value)
      return errors
    })
    return { localData, t, cpEmailError, cpPrivatemailError }
  }
}
</script>
