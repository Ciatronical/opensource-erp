<template>
  <v-card variant="outlined" elevation="1">
    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
      <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billing.communicationTitle') }}</h4>
    </v-card-title>
    <v-divider />
    <v-card-text class="py-2 px-2 px-sm-3">
      <v-row density="compact">
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
        <v-col v-for="(entry, idx) in phoneNumbers" :key="'phone-' + idx" cols="12" class="py-0">
          <v-row density="compact">
            <v-col cols="12" sm="4" class="py-1">
              <v-text-field
                :label="t('CustomerVendorEditView.fields.phoneLabel')"
                v-model="entry.label"
                variant="outlined"
                density="compact"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="10" sm="7" class="py-1">
              <v-text-field
                :label="t('CustomerVendorEditView.fields.phoneNumber')"
                v-model="entry.number"
                variant="outlined"
                density="compact"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="2" sm="1" class="py-1 d-flex align-center">
              <v-btn icon size="small" variant="text" color="error" @click="removePhoneNumber(idx)">
                <v-icon>mdi-close</v-icon>
              </v-btn>
            </v-col>
          </v-row>
        </v-col>
        <v-col cols="12" class="py-1">
          <v-btn variant="text" size="small" prepend-icon="mdi-plus" @click="addPhoneNumber">
            {{ t('CustomerVendorEditView.fields.addPhone') }}
          </v-btn>
        </v-col>

        <!-- Haupt-E-Mail, CC, BCC -->
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.email')"
            v-model="localData.email"
            variant="outlined"
            density="compact"
            hide-details="auto"
            data-field="email"
            :error-messages="emailError"
          >
            <template #append-inner>
              <v-btn
                v-if="isValidEmail(localData.email)"
                icon
                size="x-small"
                variant="text"
                color="primary"
                :title="t('CustomerVendorEditView.fields.openEmail')"
                @mousedown.prevent
                @click.stop="openEmail(localData.email)"
              >
                <v-icon size="small">mdi-email-fast-outline</v-icon>
              </v-btn>
            </template>
          </v-text-field>
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

        <!-- Weitere E-Mail-Adressen (customer_ext/vendor_ext.emails) -->
        <v-col v-for="(entry, idx) in emails" :key="'email-' + idx" cols="12" class="py-0">
          <v-row density="compact">
            <v-col cols="12" sm="4" class="py-1">
              <v-text-field
                :label="t('CustomerVendorEditView.fields.emailLabel')"
                v-model="entry.label"
                variant="outlined"
                density="compact"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="10" sm="7" class="py-1">
              <v-text-field
                :label="t('CustomerVendorEditView.fields.emailAddress')"
                v-model="entry.email"
                variant="outlined"
                density="compact"
                hide-details="auto"
                type="email"
                :error-messages="emailEntryErrors(entry, idx)"
                @blur="checkEmailEntryDns(entry, idx)"
              >
                <template #append-inner>
                  <v-btn
                    v-if="isValidEmail(entry.email)"
                    icon
                    size="x-small"
                    variant="text"
                    color="primary"
                    :title="t('CustomerVendorEditView.fields.openEmail')"
                    @mousedown.prevent
                    @click.stop="openEmail(entry.email)"
                  >
                    <v-icon size="small">mdi-email-fast-outline</v-icon>
                  </v-btn>
                </template>
              </v-text-field>
            </v-col>
            <v-col cols="2" sm="1" class="py-1 d-flex align-center">
              <v-btn icon size="small" variant="text" color="error" @click="removeEmail(idx)">
                <v-icon>mdi-close</v-icon>
              </v-btn>
            </v-col>
          </v-row>
        </v-col>
        <v-col cols="12" class="py-1">
          <v-btn variant="text" size="small" prepend-icon="mdi-plus" @click="addEmail">
            {{ t('CustomerVendorEditView.fields.addEmail') }}
          </v-btn>
        </v-col>

        <!-- Homepage + weitere URLs (customer_ext/vendor_ext.urls) -->
        <v-col cols="12" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.homepage')"
            v-model="localData.homepage"
            variant="outlined"
            density="compact"
            hide-details="auto"
            :error-messages="homepageError"
          >
            <template #append-inner>
              <v-btn
                v-if="isValidUrl(localData.homepage)"
                icon
                size="x-small"
                variant="text"
                color="primary"
                :title="t('CustomerVendorEditView.fields.openUrl')"
                @mousedown.prevent
                @click.stop="openUrl(localData.homepage)"
              >
                <v-icon size="small">mdi-open-in-new</v-icon>
              </v-btn>
            </template>
          </v-text-field>
        </v-col>
        <v-col v-for="(entry, idx) in urls" :key="'url-' + idx" cols="12" class="py-0">
          <v-row density="compact">
            <v-col cols="12" sm="4" class="py-1">
              <v-text-field
                :label="t('CustomerVendorEditView.fields.urlLabel')"
                v-model="entry.label"
                variant="outlined"
                density="compact"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="10" sm="7" class="py-1">
              <v-text-field
                :label="t('CustomerVendorEditView.fields.urlAddress')"
                v-model="entry.url"
                variant="outlined"
                density="compact"
                hide-details="auto"
                type="url"
                :error-messages="urlEntryErrors(entry)"
              >
                <template #append-inner>
                  <v-btn
                    v-if="isValidUrl(entry.url)"
                    icon
                    size="x-small"
                    variant="text"
                    color="primary"
                    :title="t('CustomerVendorEditView.fields.openUrl')"
                    @mousedown.prevent
                    @click.stop="openUrl(entry.url)"
                  >
                    <v-icon size="small">mdi-open-in-new</v-icon>
                  </v-btn>
                </template>
              </v-text-field>
            </v-col>
            <v-col cols="2" sm="1" class="py-1 d-flex align-center">
              <v-btn icon size="small" variant="text" color="error" @click="removeUrl(idx)">
                <v-icon>mdi-close</v-icon>
              </v-btn>
            </v-col>
          </v-row>
        </v-col>
        <v-col cols="12" class="py-1">
          <v-btn variant="text" size="small" prepend-icon="mdi-plus" @click="addUrl">
            {{ t('CustomerVendorEditView.fields.addUrl') }}
          </v-btn>
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
import { useEmailActions } from '@/core/composables/useEmailActions.js'

export default {
  name: 'CommunicationCard',
  props: {
    modelValue: { type: Object, required: true },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { t } = useI18n()
    const { openEmail, openUrl } = useEmailActions()
    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })

    // Listen in customer_ext/vendor_ext: beim ersten Zugriff als leeres Array anlegen,
    // damit v-for und push auf demselben reaktiven Array arbeiten.
    function ensureList(key) {
      if (!Array.isArray(localData.value[key])) localData.value[key] = []
      return localData.value[key]
    }

    const phoneNumbers = computed(() => ensureList('phone_numbers'))
    function addPhoneNumber() { ensureList('phone_numbers').push({ label: '', number: '' }) }
    function removePhoneNumber(index) { localData.value.phone_numbers.splice(index, 1) }

    const emails = computed(() => ensureList('emails'))
    function addEmail() { ensureList('emails').push({ label: '', email: '' }) }
    function removeEmail(index) {
      localData.value.emails.splice(index, 1)
      emailEntryDns.value.splice(index, 1)
    }

    const urls = computed(() => ensureList('urls'))
    function addUrl() { ensureList('urls').push({ label: '', url: '' }) }
    function removeUrl(index) { localData.value.urls.splice(index, 1) }

    const RE_EMAIL = /^[^\s@]+@[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/
    // Domain mit TLD, optional Schema, Port, Pfad/Query — "portal.beispiel.de/login" ist gültig
    const RE_URL = /^(https?:\/\/)?([a-z0-9äöüß-]+\.)+[a-z]{2,}(:\d+)?([/?#][^\s]*)?$/i

    function isValidEmail(v) { return !!v && RE_EMAIL.test(v.trim()) }
    function isValidUrl(v) { return !!v && RE_URL.test(v.trim()) }

    function checkEmail(v) {
      if (!v) return []
      return RE_EMAIL.test(v.trim()) ? [] : [t('CustomerVendorEditView.email.invalid')]
    }

    async function dnsCheck(email) {
      try {
        const resp = await axios.post('/api/customer_vendor/', { action: 'validateEmail', email })
        return resp.data?.payload?.error === 'dns' ? resp.data.payload.message : ''
      } catch (e) {
        return '' // Netzwerkfehler ignorieren
      }
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
          const msg = await dnsCheck(email)
          if (lastChecked === email) dnsError.value = msg
        }, 800)
      })
      return dnsError
    }

    // DNS-Check der weiteren E-Mail-Adressen beim Verlassen des Feldes
    const emailEntryDns = ref([])
    async function checkEmailEntryDns(entry, idx) {
      emailEntryDns.value[idx] = ''
      const email = (entry.email || '').trim()
      if (!RE_EMAIL.test(email)) return
      const msg = await dnsCheck(email)
      if ((localData.value.emails?.[idx]?.email || '').trim() === email) emailEntryDns.value[idx] = msg
    }

    function emailEntryErrors(entry, idx) {
      const errors = checkEmail(entry.email)
      const v = (entry.email || '').trim().toLowerCase()
      if (v && localData.value.email && v === localData.value.email.trim().toLowerCase()) {
        errors.push(t('CustomerVendorEditView.email.sameAsEmail'))
      }
      if (v && (localData.value.emails || []).some((e, i) => i !== idx && (e.email || '').trim().toLowerCase() === v)) {
        errors.push(t('CustomerVendorEditView.email.duplicate'))
      }
      if (emailEntryDns.value[idx]) errors.push(emailEntryDns.value[idx])
      return errors
    }

    function urlEntryErrors(entry) {
      const v = entry.url
      if (!v) return []
      return RE_URL.test(v.trim()) ? [] : [t('CustomerVendorEditView.email.homepageInvalid')]
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

    return {
      localData, t,
      phoneNumbers, addPhoneNumber, removePhoneNumber,
      emails, addEmail, removeEmail, emailEntryErrors, checkEmailEntryDns,
      urls, addUrl, removeUrl, urlEntryErrors,
      isValidEmail, isValidUrl, openEmail, openUrl,
      emailError, ccErrors, bccErrors, homepageError, invoiceMailError, deliveryMailError,
    }
  }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
  background-color: #f5f5f5;
}
</style>
