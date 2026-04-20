<!-- src/core/views/customer-vendor/cards/name.address.card.vue -->

<template>
  <v-card variant="outlined" elevation="1">
    <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
      <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billing.nameAddressTitle') }}</h4>
      <v-spacer />
      <v-btn
        size="small"
        color="primary"
        variant="tonal"
        :loading="scanning"
        :disabled="scanning"
        prepend-icon="mdi-creation"
        @click="openBusinessCardPicker"
      >
        {{ t('CustomerVendorEditView.businessCard.button') }}
      </v-btn>
      <input
        ref="fileInput"
        type="file"
        accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
        style="display:none"
        @change="onBusinessCardSelected"
      />
    </v-card-title>
    <v-divider />
    <v-card-text class="py-2 px-2 px-sm-3">
      <v-row dense>
        <v-col cols="12" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.name')"
            v-model="localData.name"
            variant="outlined"
            density="compact"
            hide-details="auto"
            @blur="onNameBlur"
          />
        </v-col>

        <v-col cols="12" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.greeting')"
            v-model="localData.greeting"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>

        <v-col cols="4" sm="3" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.zipcode')"
            v-model="localData.zipcode"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="8" sm="9" class="py-1">
          <v-menu v-model="showCityMenu" :close-on-content-click="true">
            <template #activator="{ props: menuProps }">
              <v-text-field
                :label="t('CustomerVendorEditView.fields.city')"
                v-model="localData.city"
                variant="outlined"
                density="compact"
                hide-details="auto"
                v-bind="cityChoices.length > 1 ? menuProps : {}"
                :append-inner-icon="cityChoices.length > 1 ? 'mdi-menu-down' : undefined"
              />
            </template>
            <v-list density="compact">
              <v-list-item
                v-for="city in cityChoices"
                :key="city"
                :title="city"
                @click="localData.city = city"
              />
            </v-list>
          </v-menu>
        </v-col>

        <v-col cols="12" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.country')"
            v-model="localData.country"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>

        <v-col cols="12" class="py-1">
          <v-switch
            :label="t('CustomerVendorEditView.fields.natural_person')"
            v-model="localData.natural_person"
            density="compact"
            hide-details="auto"
          />
        </v-col>

        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.department_1')"
            v-model="localData.department_1"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.department_2')"
            v-model="localData.department_2"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>

        <v-col cols="12" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.street')"
            v-model="localData.street"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>

        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.gln')"
            v-model="localData.gln"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>

        <v-col cols="12" sm="6" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.commercial_court')"
            v-model="localData.commercial_court"
            variant="outlined"
            density="compact"
            hide-details="auto"
          />
        </v-col>
      </v-row>
    </v-card-text>
  </v-card>

  <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top">
    {{ snackbar.text }}
    <template #actions>
      <v-btn icon="mdi-close" variant="text" @click="snackbar.show = false" />
    </template>
  </v-snackbar>
</template>

<script>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

export default {
  name: 'NameAddressCard',
  props: {
    modelValue: {
      type: Object,
      required: true,
    },
    phoneNumbers: {
      type: Array,
      default: () => [],
    },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { t } = useI18n()

    const localData = computed({
      get: () => props.modelValue,
      set: (value) => emit('update:modelValue', value)
    })

    async function onNameBlur() {
      const name = (localData.value.name || '').trim()
      if (!name) return
      // Erstes Wort als Vorname extrahieren
      const firstname = name.split(/\s+/)[0]
      try {
        const response = await axios.post('/api/customer_vendor/', {
          action: 'lookupGreeting',
          firstname
        })
        const greeting = response.data?.payload?.greeting
        if (greeting) {
          localData.value.greeting = greeting
        }
      } catch (e) {
        // Stille Fehlerbehandlung — Anrede bleibt leer
      }
    }

    const cityChoices = ref([])
    const showCityMenu = ref(false)

    // ── Visitenkarten-Scan per KI ──
    const fileInput = ref(null)
    const scanning = ref(false)
    const snackbar = ref({ show: false, color: 'success', text: '' })

    function openBusinessCardPicker() {
      if (scanning.value) return
      fileInput.value?.click()
    }

    function readFileAsBase64(file) {
      return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => {
          const result = reader.result || ''
          const idx = String(result).indexOf(',')
          resolve(idx >= 0 ? String(result).slice(idx + 1) : String(result))
        }
        reader.onerror = () => reject(reader.error)
        reader.readAsDataURL(file)
      })
    }

    function applyIfEmpty(field, value) {
      const v = (value ?? '').toString().trim()
      if (!v) return
      const current = (localData.value[field] ?? '').toString().trim()
      if (!current) localData.value[field] = v
    }

    async function onBusinessCardSelected(event) {
      const file = event.target.files?.[0]
      if (!file) return
      // Reset input damit dieselbe Datei erneut waehlbar ist
      event.target.value = ''

      if (file.size > 10 * 1024 * 1024) {
        snackbar.value = { show: true, color: 'error', text: t('CustomerVendorEditView.businessCard.tooLarge') }
        return
      }

      scanning.value = true
      try {
        const fileBase64 = await readFileAsBase64(file)
        const response = await axios.post('/api/customer_vendor/', {
          action: 'scanBusinessCard',
          file_base64: fileBase64,
          mime_type: file.type || 'image/jpeg',
          src: localData.value.src || 'C'
        })
        if (!response.data?.success) {
          throw new Error(response.data?.text || 'Scan fehlgeschlagen')
        }
        const extracted = response.data.payload?.extracted || {}
        const confidence = Number(extracted.confidence ?? 0)
        if (confidence <= 0) {
          snackbar.value = {
            show: true, color: 'warning',
            text: t('CustomerVendorEditView.businessCard.lowConfidence')
          }
          return
        }

        // Stammdaten nur in leere Felder uebernehmen — bestehende Eingaben nicht ueberschreiben
        // greeting wird bewusst nicht uebernommen — lookupGreeting beim Namens-Blur erzeugt die Anrede
        applyIfEmpty('name', extracted.name)
        applyIfEmpty('contact', extracted.contact)
        // Abteilungen werden bewusst NICHT uebernommen — zu haeufige Falsch-Erkennung
        applyIfEmpty('street', extracted.street)
        applyIfEmpty('phone', extracted.phone)
        applyIfEmpty('email', extracted.email)
        applyIfEmpty('homepage', extracted.homepage)
        applyIfEmpty('commercial_court', extracted.commercial_court)
        applyIfEmpty('taxnumber', extracted.taxnumber)
        applyIfEmpty('ustid', extracted.ustid)

        // Land IMMER 'D' (interner Code, nicht ISO 'DE')
        localData.value.country = 'D'

        // PLZ ZULETZT setzen — der bestehende lookupZipcode-Watcher fuellt den Ort
        // aus der DB (bzw. zeigt Auswahl), das ist zuverlaessiger als die OCR der KI.
        // Stadt aus der Extraktion wird bewusst verworfen.
        applyIfEmpty('zipcode', extracted.zipcode)

        if (typeof extracted.natural_person === 'boolean' && localData.value.natural_person == null) {
          localData.value.natural_person = extracted.natural_person
        }

        // Weitere Telefonnummern anhaengen (ohne Duplikate)
        if (Array.isArray(extracted.phone_numbers) && extracted.phone_numbers.length) {
          const existing = Array.isArray(localData.value.phone_numbers)
            ? [...localData.value.phone_numbers]
            : []
          const existingNumbers = new Set(
            existing.map(p => (typeof p === 'string' ? p : p.number || '').trim())
          )
          existingNumbers.add((localData.value.phone || '').trim())
          for (const pn of extracted.phone_numbers) {
            const number = (pn?.number || '').trim()
            if (!number || existingNumbers.has(number)) continue
            existing.push({ label: (pn.label || '').trim(), number })
            existingNumbers.add(number)
          }
          localData.value.phone_numbers = existing
        }

        snackbar.value = {
          show: true, color: 'success',
          text: t('CustomerVendorEditView.businessCard.success', { confidence: Math.round(confidence * 100) })
        }
      } catch (e) {
        snackbar.value = {
          show: true, color: 'error',
          text: (e?.response?.data?.text || e?.message || t('CustomerVendorEditView.businessCard.error'))
        }
      } finally {
        scanning.value = false
      }
    }

    watch(() => localData.value.zipcode, async (zipcode, oldZipcode) => {
      // Beim initialen Laden eines vorhandenen Kunden den Ort nicht überschreiben
      if (oldZipcode == null && localData.value.city) return
      cityChoices.value = []
      const plz = (zipcode || '').trim()
      if (plz.length < 4) return
      try {
        const response = await axios.post('/api/customer_vendor/', {
          action: 'lookupZipcode',
          zipcode: plz
        })
        const cities = response.data?.payload?.cities || []
        if (cities.length === 1) {
          localData.value.city = cities[0]
        } else if (cities.length > 1) {
          cityChoices.value = cities
          showCityMenu.value = true
        }
      } catch (e) {
        // Stille Fehlerbehandlung — Ort bleibt unverändert
      }
    })

    return {
      localData, onNameBlur, cityChoices, showCityMenu,
      fileInput, scanning, snackbar,
      openBusinessCardPicker, onBusinessCardSelected,
      t,
    }
  }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
  background-color: #f5f5f5;
}
</style>
