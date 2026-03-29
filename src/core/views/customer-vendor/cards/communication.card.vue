<template>
  <v-card variant="outlined" elevation="1">
    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
      <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billing.communicationTitle') }}</h4>
    </v-card-title>
    <v-divider />
    <v-card-text class="py-2 px-2 px-sm-3">
      <v-row dense>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.contact')"
            v-model="localData.contact"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.phone')"
            v-model="localData.phone"
            variant="outlined"
            density="compact"
            hide-details="auto"
            data-field="phone"
          />
        </v-col>
        <template v-for="(entry, idx) in phoneNumbers" :key="'phone-' + idx">
          <v-col cols="12" sm="5" class="py-1">
            <v-text-field
              :label="t('CustomerVendorEditView.fields.phoneLabel')"
              v-model="entry.label"
              variant="outlined"
              density="compact"
              hide-details="auto"
            />
          </v-col>
          <v-col cols="10" sm="5" class="py-1">
            <v-text-field
              :label="t('CustomerVendorEditView.fields.phoneNumber')"
              v-model="entry.number"
              variant="outlined"
              density="compact"
              hide-details="auto"
            />
          </v-col>
          <v-col cols="2" sm="2" class="py-1 d-flex align-center">
            <v-btn icon size="small" variant="text" color="error" @click="removePhoneNumber(idx)">
              <v-icon>mdi-close</v-icon>
            </v-btn>
          </v-col>
        </template>
        <v-col cols="12" class="py-1">
          <v-btn variant="text" size="small" prepend-icon="mdi-plus" @click="addPhoneNumber">
            {{ t('CustomerVendorEditView.fields.addPhone') }}
          </v-btn>
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.email')"
            v-model="localData.email"
            variant="outlined"
            density="compact"
            hide-details="auto"
            data-field="email"
            :error-messages="emailError"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.cc')"
            v-model="localData.cc"
            variant="outlined"
            density="compact"
            hide-details="auto"
            :error-messages="ccErrors"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.bcc')"
            v-model="localData.bcc"
            variant="outlined"
            density="compact"
            hide-details="auto"
            :error-messages="bccErrors"
          />
        </v-col>
        <v-col cols="12" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.homepage')"
            v-model="localData.homepage"
            variant="outlined"
            density="compact"
            hide-details="auto"
            :error-messages="homepageError"
          />
        </v-col>
        <template v-if="localData.src !== 'V'">
          <v-col cols="12" sm="6" class="py-1">
            <v-text-field
              :label="t('CustomerVendorEditView.fields.invoice_mail')"
              v-model="localData.invoice_mail"
              variant="outlined"
              density="compact"
              hide-details="auto"
              :error-messages="invoiceMailError"
            />
          </v-col>
          <v-col cols="12" sm="6" class="py-1">
            <v-text-field
              :label="t('CustomerVendorEditView.fields.delivery_order_mail')"
              v-model="localData.delivery_order_mail"
              variant="outlined"
              density="compact"
              hide-details="auto"
              :error-messages="deliveryMailError"
            />
          </v-col>
        </template>
      </v-row>
    </v-card-text>
  </v-card>
</template>

<script>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

export default {
  name: 'CommunicationCard',
  props: {
    modelValue: { type: Object, required: true },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { t } = useI18n()
    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })

    const phoneNumbers = computed(() => {
      if (!localData.value.phone_numbers) {
        localData.value.phone_numbers = []
      }
      return localData.value.phone_numbers
    })

    function addPhoneNumber() {
      if (!localData.value.phone_numbers) {
        localData.value.phone_numbers = []
      }
      localData.value.phone_numbers.push({ label: '', number: '' })
    }

    function removePhoneNumber(index) {
      localData.value.phone_numbers.splice(index, 1)
    }

    const RE_EMAIL = /^[^\s@]+@[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/
    const RE_URL = /^(https?:\/\/)?[^\s/$.?#][^\s]*\.[a-zA-Z]{2,}$/

    function checkEmail(v) {
      if (!v) return []
      return RE_EMAIL.test(v.trim()) ? [] : [t('CustomerVendorEditView.email.invalid')]
    }

    // Debounced DNS-Check: prueft Domain per Backend nach 800ms Tipp-Pause
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

    // Debounced Homepage-Ping: prueft Erreichbarkeit per fetch (no-cors) nach 800ms Tipp-Pause
    function useHomepageCheck(urlGetter) {
      const pingError = ref('')
      let timer = null
      let lastChecked = ''
      watch(urlGetter, (val) => {
        pingError.value = ''
        if (timer) clearTimeout(timer)
        if (!val || !RE_URL.test(val.trim())) return
        timer = setTimeout(async () => {
          const raw = val.trim()
          lastChecked = raw
          const url = /^https?:\/\//i.test(raw) ? raw : 'https://' + raw
          const controller = new AbortController()
          const timeout = setTimeout(() => controller.abort(), 5000)
          try {
            await fetch(url, { mode: 'no-cors', signal: controller.signal })
          } catch (e) {
            if (lastChecked === raw) {
              pingError.value = t('CustomerVendorEditView.email.homepageUnreachable')
            }
          } finally {
            clearTimeout(timeout)
          }
        }, 800)
      })
      return pingError
    }

    const homepagePing = useHomepageCheck(() => localData.value.homepage)
    const emailDns = useDnsCheck(() => localData.value.email)
    const ccDns = useDnsCheck(() => localData.value.cc)
    const bccDns = useDnsCheck(() => localData.value.bcc)
    const invoiceMailDns = useDnsCheck(() => localData.value.invoice_mail)
    const deliveryMailDns = useDnsCheck(() => localData.value.delivery_order_mail)

    const emailError = computed(() => {
      const errors = checkEmail(localData.value.email)
      if (emailDns.value) errors.push(emailDns.value)
      return errors
    })
    const invoiceMailError = computed(() => {
      const errors = checkEmail(localData.value.invoice_mail)
      if (invoiceMailDns.value) errors.push(invoiceMailDns.value)
      return errors
    })
    const deliveryMailError = computed(() => {
      const errors = checkEmail(localData.value.delivery_order_mail)
      if (deliveryMailDns.value) errors.push(deliveryMailDns.value)
      return errors
    })

    const ccErrors = computed(() => {
      const v = localData.value.cc
      if (!v) return []
      const errors = []
      if (!RE_EMAIL.test(v.trim())) errors.push(t('CustomerVendorEditView.email.invalid'))
      if (localData.value.email && v.trim().toLowerCase() === localData.value.email.trim().toLowerCase()) errors.push(t('CustomerVendorEditView.email.ccSameAsEmail'))
      if (localData.value.bcc && v.trim().toLowerCase() === localData.value.bcc.trim().toLowerCase()) errors.push(t('CustomerVendorEditView.email.ccSameAsBcc'))
      if (ccDns.value) errors.push(ccDns.value)
      return errors
    })

    const bccErrors = computed(() => {
      const v = localData.value.bcc
      if (!v) return []
      const errors = []
      if (!RE_EMAIL.test(v.trim())) errors.push(t('CustomerVendorEditView.email.invalid'))
      if (localData.value.email && v.trim().toLowerCase() === localData.value.email.trim().toLowerCase()) errors.push(t('CustomerVendorEditView.email.bccSameAsEmail'))
      if (localData.value.cc && v.trim().toLowerCase() === localData.value.cc.trim().toLowerCase()) errors.push(t('CustomerVendorEditView.email.bccSameAsCc'))
      if (bccDns.value) errors.push(bccDns.value)
      return errors
    })

    const homepageError = computed(() => {
      const v = localData.value.homepage
      if (!v) return []
      const errors = RE_URL.test(v.trim()) ? [] : [t('CustomerVendorEditView.email.homepageInvalid')]
      if (homepagePing.value) errors.push(homepagePing.value)
      return errors
    })

    return { localData, t, phoneNumbers, addPhoneNumber, removePhoneNumber, emailError, ccErrors, bccErrors, homepageError, invoiceMailError, deliveryMailError }
  }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
  background-color: #f5f5f5;
}
</style>
