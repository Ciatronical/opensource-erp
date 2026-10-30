<!-- src/core/views/customer-vendor/cards/numbers.ids.card.vue -->

<template>
  <section-card :title="t('CustomerVendorEditView.billing.numbersIdsTitle')" icon="mdi-identifier">
      <v-row density="compact">
        <v-col v-if="show('customernumber')" cols="12" sm="6" class="py-1">
          <v-text-field
            :label="entityNumberLabel"
            v-model="entityNumber"
          />
        </v-col>
        <v-col v-if="show('taxnumber')" cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.taxnumber')"
            v-model="localData.taxnumber"
            :error="taxnumberError"
            :error-messages="taxnumberErrorMessage"
            @blur="validateTaxNumberField"
          />
        </v-col>
        <v-col v-if="show('ustid')" cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.ustid')"
            v-model="localData.ustid"
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
                :title="t('CustomerVendorEditView.ustid.check_qualified')"
              />
            </template>
          </v-text-field>

          <!-- Ergebnis der Bestätigungsabfrage: frisch aus diesem Aufruf oder der am
               Stamm hinterlegte Nachweis (customer_ext.ustid_check) -->
          <div v-if="viesResult && viesResult.serviceError" class="text-caption mt-1">
            <v-chip color="warning" size="small" variant="flat">
              <v-icon start>mdi-alert-circle</v-icon>
              {{ viesResult.error }}
            </v-chip>
          </div>
          <div v-else-if="shownCheck" class="text-caption mt-1">
            <div class="d-flex align-center flex-wrap ga-1">
              <v-chip :color="shownCheck.valid ? 'success' : 'error'" size="small" variant="flat">
                <v-icon start>{{ shownCheck.valid ? 'mdi-check-decagram' : 'mdi-close-circle' }}</v-icon>
                {{ checkLabel(shownCheck) }}
              </v-chip>
              <span class="text-medium-emphasis">{{ t('CustomerVendorEditView.ustid.checked_at', { date: formatCheckDate(shownCheck.checked_at) }) }}</span>
            </div>
            <!-- Qualifiziert: Vergleichsergebnis je Feld (A stimmt, B stimmt nicht, C nicht angefragt, D nicht mitgeteilt) -->
            <div v-if="shownCheck.valid && shownCheck.fields" class="d-flex align-center flex-wrap ga-1 mt-1">
              <v-chip
                v-for="field in checkFields(shownCheck.fields)"
                :key="field.key"
                size="x-small"
                :color="field.color"
                variant="tonal"
                :title="t('CustomerVendorEditView.ustid.match_' + field.code.toLowerCase())"
              >
                <v-icon start size="x-small">{{ field.icon }}</v-icon>
                {{ t('CustomerVendorEditView.ustid.field_' + field.key) }}
              </v-chip>
            </div>
            <div v-if="shownCheck.name || shownCheck.address" class="mt-1">
              {{ [shownCheck.name, shownCheck.address].filter(Boolean).join(', ') }}
            </div>
            <div v-if="shownCheck.request_id" class="text-medium-emphasis">
              {{ t('CustomerVendorEditView.ustid.consultation_number', { id: shownCheck.request_id }) }}
            </div>
            <div v-if="shownCheck.valid && !shownCheck.qualified && qualifiedReasonText" class="text-medium-emphasis">
              {{ qualifiedReasonText }}
            </div>
            <div v-if="viesResult && viesResult.stored === false && !viesResult.serviceError" class="text-medium-emphasis">
              {{ t('CustomerVendorEditView.ustid.not_saved_hint') }}
            </div>
          </div>
          <div v-else-if="staleCheck" class="text-caption text-medium-emphasis mt-1">
            {{ t('CustomerVendorEditView.ustid.stale_check', { ustid: staleCheck.ustid }) }}
          </div>
        </v-col>
        <v-col v-if="show('c_vendor_id')" cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.c_vendor_id')"
            v-model="localData.c_vendor_id"
          />
        </v-col>
        <v-col v-if="show('c_vendor_routing_id')" cols="12" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.c_vendor_routing_id')"
            v-model="localData.c_vendor_routing_id"
          />
        </v-col>
      </v-row>
  </section-card>
</template>

<script>
import { computed, ref } from 'vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import { useI18n } from 'vue-i18n'
import { useCvEasymode } from '../composables/useCvEasymode.js'
import axios from 'axios'
import { validateUstId } from '@/core/utils/ustid-validation.js'
import { validateTaxNumber } from '@/core/utils/taxnumber-validation.js'

export default {
  name: 'NumbersIdsCard',
  components: { SectionCard },
  props: {
    modelValue: { type: Object, required: true },
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

    // Bestätigungsabfrage der USt-IdNr. — eVatR (BZSt, qualifiziert mit Name und
    // Ort) oder VIES; das Backend entscheidet anhand der eigenen USt-IdNr. und
    // hinterlegt den Nachweis am Stamm (ustid_check) und im Protokoll.
    const viesLoading = ref(false)
    const viesResult = ref(null)

    const normalizeUstId = (value) => String(value || '').replace(/[\s\-.]/g, '').toUpperCase()

    /** Frisches Ergebnis oder gespeicherter Nachweis zur aktuell eingetragenen Nummer */
    const shownCheck = computed(() => {
      if (viesResult.value && !viesResult.value.serviceError) return viesResult.value
      const stored = localData.value.ustid_check
      if (!stored || !stored.ustid) return null
      return normalizeUstId(stored.ustid) === normalizeUstId(localData.value.ustid) ? stored : null
    })

    /** Gespeicherter Nachweis gilt einer anderen Nummer als der eingetragenen */
    const staleCheck = computed(() => {
      const stored = localData.value.ustid_check
      if (!stored || !stored.ustid || !localData.value.ustid) return null
      return normalizeUstId(stored.ustid) === normalizeUstId(localData.value.ustid) ? null : stored
    })

    const checkLabel = (check) => {
      const service = check.service === 'evatr' ? 'eVatR' : 'VIES'
      if (!check.valid) return t('CustomerVendorEditView.ustid.result_invalid', { service })
      return check.qualified
        ? t('CustomerVendorEditView.ustid.result_qualified', { service })
        : t('CustomerVendorEditView.ustid.result_valid', { service })
    }

    const formatCheckDate = (value) => {
      if (!value) return ''
      const d = new Date(value)
      return isNaN(d.getTime()) ? String(value) : d.toLocaleString()
    }

    /** A/B/C/D je Feld in Chip-Darstellung */
    const checkFields = (fields) => ['firmenname', 'ort', 'plz', 'strasse']
      .filter(key => fields[key] && fields[key] !== 'C')
      .map(key => {
        const code = fields[key]
        return {
          key,
          code,
          color: code === 'A' ? 'success' : code === 'B' ? 'error' : 'grey',
          icon: code === 'A' ? 'mdi-check' : code === 'B' ? 'mdi-close' : 'mdi-help',
        }
      })

    const qualifiedReasonText = computed(() => {
      const reason = shownCheck.value?.qualified_reason
      if (!reason) return ''
      const key = {
        TARGET_DE: 'target_de',
        NO_OWN_USTID: 'no_own_ustid',
        OWN_NOT_DE: 'own_not_de',
        MISSING_NAME_CITY: 'missing_name_city',
      }[reason]
      return key ? t('CustomerVendorEditView.ustid.reason_' + key) : ''
    })

    const checkViesVatId = async () => {
      if (!localData.value.ustid) return

      viesLoading.value = true
      viesResult.value = null

      try {
        const response = await axios.post('/api/customer_vendor/', {
          action: 'checkVatId',
          vatId: localData.value.ustid,
          cv_id: localData.value.id || 0,
          src: localData.value.src || props.entitySrc || 'C',
          name: localData.value.name || '',
          city: localData.value.city || '',
          zipcode: localData.value.zipcode || '',
          street: localData.value.street || '',
        })

        const payload = response.data.payload || {}
        if (response.data.success) {
          viesResult.value = payload
          // Nachweis wie vom Backend gespeichert in den Datensatz übernehmen, damit
          // Karte und Zeitleiste ohne Neuladen den aktuellen Stand zeigen
          if (payload.stored && payload.summary) {
            localData.value.ustid_check = payload.summary
          }
          return
        }

        const errorCode = response.data.text
        if (errorCode === 'INVALID_VAT_ID' || (payload.valid === false)) {
          viesResult.value = { ...payload, valid: false }
          return
        }
        const messages = {
          SERVICE_UNAVAILABLE: 'vies_service_unavailable',
          TOO_MANY_REQUESTS: 'vies_too_many_requests',
          OWN_VAT_ID_INVALID: 'own_ustid_invalid',
          INVALID_FORMAT: 'invalid_format_generic',
          NOT_EU: 'not_eu',
        }
        viesResult.value = {
          serviceError: true,
          error: t('CustomerVendorEditView.ustid.' + (messages[errorCode] || 'vies_error'))
            + (payload.message ? ' – ' + payload.message : ''),
        }
      } catch (error) {
        console.error('USt-IdNr.-Prüfung:', error)
        viesResult.value = {
          serviceError: true,
          error: t('CustomerVendorEditView.ustid.vies_service_unavailable'),
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
      show,
      localData,
      entityNumberLabel,
      entityNumber,
      t,
      ustidError,
      ustidErrorMessage,
      validateUstIdField,
      viesLoading,
      viesResult,
      shownCheck,
      staleCheck,
      checkLabel,
      formatCheckDate,
      checkFields,
      qualifiedReasonText,
      checkViesVatId,
      taxnumberError,
      taxnumberErrorMessage,
      validateTaxNumberField
    }
  }
}
</script>
