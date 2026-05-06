<!-- src/core/views/customer-vendor/cards/name.address.card.vue -->

<template>
  <v-card variant="outlined" elevation="1">
    <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center flex-wrap ga-2">
      <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billing.nameAddressTitle') }}</h4>
      <v-spacer />
      <v-btn
        size="small"
        color="primary"
        variant="tonal"
        :loading="phoneSearching"
        :disabled="scanning || phoneSearching"
        prepend-icon="mdi-phone-search-outline"
        @click="openPhoneSearchDialog"
      >
        {{ t('CustomerVendorEditView.phoneSearch.button') }}
      </v-btn>
      <v-btn
        size="small"
        color="primary"
        variant="tonal"
        :loading="scanning"
        :disabled="scanning || phoneSearching"
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
        <v-col cols="12" sm="8" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.name')"
            v-model="localData.name"
            variant="outlined"
            density="compact"
            hide-details="auto"
            @blur="onNameBlur"
          />
        </v-col>
        <v-col cols="12" sm="4" class="py-1">
          <v-text-field
            :label="t('CustomerVendorEditView.fields.keywords')"
            v-model="localData.keywords"
            variant="outlined"
            density="compact"
            hide-details="auto"
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

        <v-col v-if="localData.src !== 'V'" cols="12" sm="6" class="py-1">
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

  <v-dialog v-model="phoneDialog.show" max-width="460" persistent>
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon color="primary" class="mr-2">mdi-phone-search-outline</v-icon>
        {{ t('CustomerVendorEditView.phoneSearch.title') }}
      </v-card-title>
      <v-card-text>
        <p class="text-caption mb-3">{{ t('CustomerVendorEditView.phoneSearch.hint') }}</p>
        <v-text-field
          v-model="phoneDialog.phone"
          :label="t('CustomerVendorEditView.fields.phone')"
          variant="outlined"
          density="compact"
          autofocus
          :disabled="phoneSearching"
          @keyup.enter="runPhoneSearch"
        />
      </v-card-text>
      <v-card-actions>
        <v-btn variant="outlined" :disabled="phoneSearching" @click="phoneDialog.show = false">
          {{ t('CustomerVendorEditView.phoneSearch.cancel') }}
        </v-btn>
        <v-spacer />
        <v-btn
          color="primary"
          variant="flat"
          :loading="phoneSearching"
          :disabled="!phoneDialog.phone || phoneDialog.phone.length < 5"
          prepend-icon="mdi-magnify"
          @click="runPhoneSearch"
        >
          {{ t('CustomerVendorEditView.phoneSearch.search') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

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

      // Wenn bereits "Firma" gesetzt, nicht überschreiben
      const currentGreeting = (localData.value.greeting || '').trim()
      if (currentGreeting === 'Firma') return

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
    const extractedCity = ref('')  // Speichert den Ort aus der Visitenkarte für Vergleich

    // ── Visitenkarten-Scan per KI ──
    const fileInput = ref(null)
    const scanning = ref(false)
    const snackbar = ref({ show: false, color: 'success', text: '' })

    // ── Telefonnummer-Websuche per KI ──
    const phoneSearching = ref(false)
    const phoneDialog = ref({ show: false, phone: '' })

    function openPhoneSearchDialog() {
      if (scanning.value || phoneSearching.value) return
      phoneDialog.value = {
        show: true,
        phone: (localData.value.phone || '').trim()
      }
    }

    async function runPhoneSearch() {
      const phone = (phoneDialog.value.phone || '').trim()
      if (phone.length < 5) return

      phoneSearching.value = true
      try {
        const response = await axios.post('/api/customer_vendor/', {
          action: 'searchByPhone',
          phone,
          src: localData.value.src || 'C'
        })
        if (!response.data?.success) {
          throw new Error(response.data?.text || 'Suche fehlgeschlagen')
        }
        const extracted = response.data.payload?.extracted || {}
        const confidence = Number(extracted.confidence ?? 0)

        if (confidence <= 0) {
          snackbar.value = {
            show: true, color: 'warning',
            text: t('CustomerVendorEditView.phoneSearch.notFound')
          }
          return
        }

        applyIfEmpty('name', extracted.name)
        applyIfEmpty('contact', extracted.contact)
        applyIfEmpty('street', extracted.street)
        applyIfEmpty('phone', extracted.phone || phone)
        applyIfEmpty('email', extracted.email)
        applyIfEmpty('homepage', extracted.homepage)
        if (localData.value.src !== 'V') {
          applyIfEmpty('commercial_court', extracted.commercial_court)
        }
        applyIfEmpty('taxnumber', extracted.taxnumber)
        applyIfEmpty('ustid', extracted.ustid)
        localData.value.country = 'D'
        if (typeof extracted.natural_person === 'boolean' && localData.value.natural_person == null) {
          localData.value.natural_person = extracted.natural_person
        }

        // Anrede bestimmen (wie bei Visitenkarte KI)
        if (!localData.value.greeting) {
          if (extracted.natural_person === false) {
            localData.value.greeting = 'Firma'
          } else {
            const nameForGreeting = (extracted.contact || extracted.name || '').trim()
            const firstname = nameForGreeting.split(/\s+/)[0]
            if (firstname) {
              try {
                const grResp = await axios.post('/api/customer_vendor/', {
                  action: 'lookupGreeting',
                  firstname
                })
                const g = grResp.data?.payload?.greeting
                if (g) localData.value.greeting = g
              } catch (e) { /* silent */ }
            }
          }
        }

        // extracted.city für Ähnlichkeits-Vergleich bei mehreren PLZ-Orten speichern
        // PLZ zuletzt — löst lookupZipcode-Watcher aus der den Ort aus der DB füllt
        extractedCity.value = (extracted.city || '').trim()
        applyIfEmpty('zipcode', extracted.zipcode)

        phoneDialog.value.show = false
        snackbar.value = {
          show: true, color: 'success',
          text: t('CustomerVendorEditView.phoneSearch.success', { confidence: Math.round(confidence * 100) })
        }
      } catch (e) {
        snackbar.value = {
          show: true, color: 'error',
          text: (e?.response?.data?.text || e?.message || t('CustomerVendorEditView.phoneSearch.error'))
        }
      } finally {
        phoneSearching.value = false
      }
    }

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
      // Reset input damit dieselbe Datei erneut wählbar ist
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
        console.log('[DEBUG] API Response:', response.data)
        if (!response.data?.success) {
          throw new Error(response.data?.text || 'Scan fehlgeschlagen')
        }
        const extracted = response.data.payload?.extracted || {}
        console.log('[DEBUG] extracted:', extracted)
        console.log('[DEBUG] extracted.greeting:', extracted.greeting)
        const confidence = Number(extracted.confidence ?? 0)
        if (confidence <= 0) {
          snackbar.value = {
            show: true, color: 'warning',
            text: t('CustomerVendorEditView.businessCard.lowConfidence')
          }
          return
        }

        // Stammdaten nur in leere Felder übernehmen — bestehende Eingaben nicht überschreiben
        // greeting kommt direkt von der Visitenkarten-KI (Firma/Herr/Frau)
        console.log('[DEBUG] BEFORE applyIfEmpty greeting:', localData.value.greeting)
        applyIfEmpty('name', extracted.name)
        applyIfEmpty('contact', extracted.contact)
        applyIfEmpty('greeting', extracted.greeting)
        console.log('[DEBUG] AFTER applyIfEmpty greeting:', localData.value.greeting)
        // Abteilungen werden bewusst NICHT übernommen — zu häufige Falsch-Erkennung
        applyIfEmpty('street', extracted.street)
        applyIfEmpty('phone', extracted.phone)
        applyIfEmpty('email', extracted.email)
        applyIfEmpty('homepage', extracted.homepage)
        if (localData.value.src !== 'V') {
          applyIfEmpty('commercial_court', extracted.commercial_court)
        }
        applyIfEmpty('taxnumber', extracted.taxnumber)
        applyIfEmpty('ustid', extracted.ustid)

        // Land IMMER 'D' (interner Code, nicht ISO 'DE')
        localData.value.country = 'D'

        // PLZ ZULETZT setzen — der bestehende lookupZipcode-Watcher füllt den Ort
        // aus der DB (bzw. zeigt Auswahl), das ist zuverlässiger als die OCR der KI.
        // Stadt aus der Extraktion wird verworfen, aber für Ähnlichkeits-Vergleich gespeichert.
        extractedCity.value = (extracted.city || '').trim()
        console.log('[DEBUG] extractedCity stored:', extractedCity.value)
        applyIfEmpty('zipcode', extracted.zipcode)

        if (typeof extracted.natural_person === 'boolean' && localData.value.natural_person == null) {
          localData.value.natural_person = extracted.natural_person
        }

        // Weitere Telefonnummern anhängen (ohne Duplikate)
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
        // PLZ ist in zipcode_location_oserp -> deutsche PLZ -> Land 'D'
        if (cities.length > 0 && !(localData.value.country || '').trim()) {
          localData.value.country = 'D'
        }
        if (cities.length === 1) {
          localData.value.city = cities[0]
        } else if (cities.length > 1) {
          // Mehrere Orte: Automatisch den ähnlichsten zu extractedCity auswählen
          const refCity = (extractedCity.value || '').trim().toLowerCase()
          console.log('[DEBUG] Multiple cities found:', cities, 'comparing to:', refCity)

          if (refCity) {
            // Similarity-Score berechnen für jeden Ort
            let bestCity = cities[0]
            let bestScore = 0
            for (const city of cities) {
              const cityLower = city.toLowerCase()
              // Exakter Match
              if (cityLower === refCity) {
                bestCity = city
                bestScore = 100
                break
              }
              // Teilstring-Match
              if (cityLower.includes(refCity) || refCity.includes(cityLower)) {
                bestScore = Math.max(bestScore, 80)
                bestCity = city
              } else {
                // Ähnlichkeit mit PHP-ähnlicher Logik (einfach: gleiche Zeichen am Anfang)
                let matchLen = 0
                for (let i = 0; i < Math.min(cityLower.length, refCity.length); i++) {
                  if (cityLower[i] === refCity[i]) matchLen++
                  else break
                }
                const score = (matchLen / Math.max(cityLower.length, refCity.length)) * 100
                if (score > bestScore) {
                  bestScore = score
                  bestCity = city
                }
              }
            }
            localData.value.city = bestCity
            console.log('[DEBUG] Auto-selected city:', bestCity, 'score:', bestScore)
          } else {
            // Kein Ort vorhanden: Menu zeigen
            cityChoices.value = cities
            showCityMenu.value = true
            console.log('[DEBUG] No reference city, showing menu')
          }
        }
      } catch (e) {
        // Stille Fehlerbehandlung — Ort bleibt unverändert
        console.log('[DEBUG] lookupZipcode error:', e.message)
      }
    })

    return {
      localData, onNameBlur, cityChoices, showCityMenu,
      fileInput, scanning, snackbar,
      openBusinessCardPicker, onBusinessCardSelected,
      phoneSearching, phoneDialog,
      openPhoneSearchDialog, runPhoneSearch,
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
