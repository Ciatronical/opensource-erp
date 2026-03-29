<!-- src/core/views/customer-vendor/cards/name.address.card.vue -->

<template>
  <v-card variant="outlined" elevation="1">
    <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
      <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billing.nameAddressTitle') }}</h4>
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
