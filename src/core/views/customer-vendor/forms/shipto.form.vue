<template>
  <v-row dense>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.name')" v-model="localData.shiptoname" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.contact')" v-model="localData.shiptocontact" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.department_1')" v-model="localData.shiptodepartment_1" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="6" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.department_2')" v-model="localData.shiptodepartment_2" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.street')" v-model="localData.shiptostreet" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="4" sm="3" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.zipcode')" v-model="localData.shiptozipcode" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="8" sm="5" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.city')" v-model="localData.shiptocity" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.country')" v-model="localData.shiptocountry" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.phone')" v-model="localData.shiptophone" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.fax')" v-model="localData.shiptofax" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="12" sm="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.email')" v-model="localData.shiptoemail" variant="outlined" density="compact" hide-details="auto" :error-messages="emailError" />
    </v-col>
    <v-col cols="6" sm="4" class="py-1">
      <v-text-field :label="t('CustomerVendorEditView.fields.gln')" v-model="localData.shiptogln" variant="outlined" density="compact" hide-details="auto" />
    </v-col>
    <v-col cols="6" sm="4" class="py-1">
      <v-select
        :label="t('CustomerVendorEditView.fields.gender')"
        v-model="localData.shiptocp_gender"
        :items="[
          { title: t('CustomerVendorEditView.fields.gender_m'), value: 'm' },
          { title: t('CustomerVendorEditView.fields.gender_f'), value: 'f' }
        ]"
        variant="outlined" density="compact" hide-details="auto"
      />
    </v-col>
    <v-col cols="12" class="py-1">
      <v-btn color="error" size="small" variant="outlined" @click="$emit('remove')" prepend-icon="mdi-delete">
        {{ t('CustomerVendorEditView.delivery.removeButton') }}
      </v-btn>
    </v-col>
  </v-row>
</template>

<script>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

export default {
  name: 'ShiptoForm',
  props: { modelValue: { type: Object, required: true } },
  emits: ['update:modelValue', 'remove'],
  setup(props, { emit }) {
    const { t } = useI18n()
    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })
    const RE_EMAIL = /^[^\s@]+@[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/

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

    const shiptoemailDns = useDnsCheck(() => localData.value.shiptoemail)

    const emailError = computed(() => {
      const v = localData.value.shiptoemail
      if (!v) return []
      const errors = RE_EMAIL.test(v.trim()) ? [] : [t('CustomerVendorEditView.email.invalid')]
      if (shiptoemailDns.value) errors.push(shiptoemailDns.value)
      return errors
    })
    return { localData, t, emailError }
  }
}
</script>
