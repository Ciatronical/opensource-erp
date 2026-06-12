<!-- src/core/views/customer-vendor/cards/numbers.ids.card.vue -->

<template>
  <v-card variant="outlined" elevation="1">
    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
      <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billing.numbersIdsTitle') }}</h4>
    </v-card-title>
    <v-divider />
    <v-card-text class="py-2 px-2 px-sm-3">
      <v-row dense>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="entityNumberLabel"
            v-model="entityNumber"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.taxnumber')"
            v-model="localData.taxnumber"
            variant="outlined"
            density="compact"
            :error="taxnumberError"
            :error-messages="taxnumberErrorMessage"
            @blur="validateTaxNumberField"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.ustid')"
            v-model="localData.ustid"
            variant="outlined"
            density="compact"
            :error="ustidError"
            :error-messages="ustidErrorMessage"
            @blur="validateUstIdField"
          >
            <template #append-inner>
              <v-btn
                icon="mdi-shield-search"
                size="x-small"
                variant="text"
                :loading="viesLoading"
                :disabled="!localData.ustid || ustidError"
                @click="checkViesVatId"
                :title="t('CustomerVendorEditView.ustid.check_vies')"
              />
            </template>
          </v-text-field>
          <div v-if="viesResult" class="text-caption mt-1">
            <!-- Service-Fehler (orange) -->
            <v-chip
              v-if="viesResult.serviceError"
              color="warning"
              size="small"
              variant="flat"
            >
              <v-icon start>mdi-alert-circle</v-icon>
              {{ viesResult.error }}
            </v-chip>
            <!-- Ungültig (rot) oder Gültig (grün) -->
            <v-chip
              v-else
              :color="viesResult.valid ? 'success' : 'error'"
              size="small"
              variant="flat"
            >
              <v-icon start>{{ viesResult.valid ? 'mdi-check-circle' : 'mdi-close-circle' }}</v-icon>
              {{ viesResult.valid ? t('CustomerVendorEditView.ustid.vies_valid') : t('CustomerVendorEditView.ustid.vies_invalid') }}
            </v-chip>
            <span v-if="viesResult.name" class="ml-2">{{ viesResult.name }}</span>
          </div>
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.c_vendor_id')"
            v-model="localData.c_vendor_id"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.c_vendor_routing_id')"
            v-model="localData.c_vendor_routing_id"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
      </v-row>
    </v-card-text>
  </v-card>
</template>

<script>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { validateUstId } from '@/core/utils/ustid-validation.js'
import { validateTaxNumber } from '@/core/utils/taxnumber-validation.js'

export default {
  name: 'NumbersIdsCard',
  props: {
    modelValue: { type: Object, required: true },
    entitySrc: { type: String, default: 'C' },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { t } = useI18n()

    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })

    // Lieferanten haben keine Spalte customernumber, sondern vendornumber
    const isVendor = computed(() => (props.entitySrc || props.modelValue?.src) === 'V')

    const entityNumberLabel = computed(() => isVendor.value
      ? t('CustomerVendorEditView.fields.vendornumber')
      : t('CustomerVendorEditView.fields.customernumber'))

    const entityNumber = computed({
      get: () => isVendor.value ? localData.value.vendornumber : localData.value.customernumber,
      set: (value) => {
        if (isVendor.value) {
          localData.value.vendornumber = value
        } else {
          localData.value.customernumber = value
        }
      }
    })

    // USt-ID Validierung
    const ustidError = ref(false)
    const ustidErrorMessage = ref('')

    const validateUstIdField = () => {
      if (!localData.value.ustid) {
        ustidError.value = false
        ustidErrorMessage.value = ''
        return
      }

      const result = validateUstId(localData.value.ustid)
      ustidError.value = !result.valid

      // Übersetzte Fehlermeldung
      if (!result.valid) {
        if (result.message.includes('Unbekanntes')) {
          ustidErrorMessage.value = t('CustomerVendorEditView.ustid.unknown_country', { country: result.country })
        } else {
          ustidErrorMessage.value = t('CustomerVendorEditView.ustid.invalid_format', { country: result.country })
        }
      } else {
        ustidErrorMessage.value = ''
      }

      console.log('🔍 USt-ID Validierung:', {
        input: localData.value.ustid,
        result: result,
        message: ustidErrorMessage.value
      })
    }

    // VIES API Prüfung
    const viesLoading = ref(false)
    const viesResult = ref(null)

    const checkViesVatId = async () => {
      if (!localData.value.ustid) return

      viesLoading.value = true
      viesResult.value = null

      try {
        const response = await axios.post('/api/customer_vendor/', {
          action: 'checkVatId',
          vatId: localData.value.ustid
        })

        console.log('🔍 VIES Response:', response.data)

        if (response.data.success) {
          viesResult.value = {
            valid: response.data.payload.valid,
            name: response.data.payload.name,
            address: response.data.payload.address,
            countryCode: response.data.payload.countryCode
          }
        } else {
          // Unterscheide zwischen ungültiger USt-ID und Service-Fehlern
          const errorCode = response.data.text

          if (errorCode === 'INVALID_VAT_ID') {
            // Wirklich ungültige USt-ID
            viesResult.value = {
              valid: false,
              error: null
            }
          } else {
            // Service-Fehler (MS_UNAVAILABLE, MS_MAX_CONCURRENT_REQ, etc.)
            let errorMessage = 'Service temporarily unavailable'

            if (errorCode === 'SERVICE_UNAVAILABLE') {
              errorMessage = t('CustomerVendorEditView.ustid.vies_service_unavailable')
            } else if (errorCode === 'VIES_ERROR') {
              const payload = response.data.payload || {}
              if (payload.error && payload.error.includes('MS_MAX_CONCURRENT_REQ')) {
                errorMessage = t('CustomerVendorEditView.ustid.vies_too_many_requests')
              } else if (payload.error && payload.error.includes('MS_UNAVAILABLE')) {
                errorMessage = t('CustomerVendorEditView.ustid.vies_service_unavailable')
              } else {
                errorMessage = t('CustomerVendorEditView.ustid.vies_error')
              }
            }

            viesResult.value = {
              serviceError: true,
              error: errorMessage
            }
          }
        }

      } catch (error) {
        console.error('VIES API Error:', error)
        viesResult.value = {
          valid: false,
          error: 'Service temporarily unavailable'
        }
      } finally {
        viesLoading.value = false
      }
    }

    // Steuernummer Validierung
    const taxnumberError = ref(false)
    const taxnumberErrorMessage = ref('')

    const validateTaxNumberField = () => {
      if (!localData.value.taxnumber) {
        taxnumberError.value = false
        taxnumberErrorMessage.value = ''
        return
      }

      const result = validateTaxNumber(localData.value.taxnumber)
      taxnumberError.value = !result.valid

      // Übersetzte Fehlermeldung
      if (!result.valid) {
        if (result.message === 'CONTAINS_NON_DIGITS') {
          taxnumberErrorMessage.value = t('CustomerVendorEditView.taxnumber.contains_non_digits')
        } else if (result.message === 'INVALID_LENGTH') {
          taxnumberErrorMessage.value = t('CustomerVendorEditView.taxnumber.invalid_length', { length: result.length })
        }
      } else {
        taxnumberErrorMessage.value = ''
      }

      console.log('🔍 Steuernummer Validierung:', {
        input: localData.value.taxnumber,
        result: result,
        message: taxnumberErrorMessage.value
      })
    }

    return {
      localData,
      entityNumberLabel,
      entityNumber,
      t,
      ustidError,
      ustidErrorMessage,
      validateUstIdField,
      viesLoading,
      viesResult,
      checkViesVatId,
      taxnumberError,
      taxnumberErrorMessage,
      validateTaxNumberField
    }
  }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
  background-color: #f5f5f5;
}
</style>
