<template>
  <v-dialog v-model="dialogVisible" max-width="480" @after-leave="reset">
    <v-card>
      <v-card-title class="d-flex align-center">
        {{ t('CallHistoryView.assignDialogTitle') }}
        <v-spacer />
        <v-btn icon="mdi-close" size="x-small" variant="text" @click="dialogVisible = false" />
      </v-card-title>

      <v-card-text>
        <!-- Anrufinfo -->
        <div class="text-body-2 text-medium-emphasis mb-3">
          {{ t('CallHistoryView.callerNumber') }}:
          <strong>{{ formatPhone(call?.crmti_number ?? '') }}</strong>
        </div>

        <!-- Kunde/Lieferant Suche -->
        <v-autocomplete
          ref="searchRef"
          v-model="selectedCv"
          v-model:search="searchQuery"
          :items="searchResults"
          :loading="searching"
          :label="t('CallHistoryView.searchCustomerVendor')"
          :no-data-text="searchQuery?.length >= 2 ? t('CallHistoryView.noResults') : t('CallHistoryView.minChars')"
          item-title="name"
          return-object
          no-filter
          variant="outlined"
          density="compact"
          hide-details
          autofocus
          clearable
          class="mb-3"
        >
          <template #item="{ item, props: itemProps }">
            <v-list-item v-bind="itemProps">
              <template #prepend>
                <v-icon
                  :color="item.raw.typ === 'C' ? 'blue' : 'orange'"
                  size="small"
                  class="me-2"
                >
                  {{ item.raw.typ === 'C' ? 'mdi-account' : 'mdi-truck' }}
                </v-icon>
              </template>
              <template #subtitle>
                <span class="text-caption">
                  {{ item.raw.typ === 'C' ? t('CallHistoryView.typeCustomer') : t('CallHistoryView.typeVendor') }}
                  · {{ item.raw.number }}
                </span>
              </template>
            </v-list-item>
          </template>
        </v-autocomplete>

        <!-- Label für die Telefonnummer -->
        <v-combobox
          v-if="selectedCv"
          v-model="phoneLabel"
          :items="labelSuggestions"
          :label="t('CallHistoryView.phoneLabel')"
          variant="outlined"
          density="compact"
          hide-details
        />
      </v-card-text>

      <v-card-actions>
        <v-btn
          v-if="call?.crmti_caller_id && call?.crmti_caller_typ !== 'X'"
          color="error"
          variant="text"
          size="small"
          @click="removeAssignment"
        >
          {{ t('CallHistoryView.removeAssignment') }}
        </v-btn>
        <v-spacer />
        <v-btn
          color="primary"
          variant="flat"
          :disabled="!selectedCv"
          :loading="saving"
          @click="assign"
        >
          {{ t('CallHistoryView.assign') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { formatPhone } from '@/core/utils/phoneFormat.js'
import * as toast from '@/core/utils/toasts.js'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  call: { type: Object, default: null },
})

const emit = defineEmits(['update:modelValue', 'assigned'])

const { t } = useI18n()

const dialogVisible = ref(false)
const searchQuery = ref('')
const searchResults = ref([])
const selectedCv = ref(null)
const searching = ref(false)
const saving = ref(false)
const searchRef = ref(null)
const phoneLabel = ref('')
const labelSuggestions = ['Büro', 'Mobil', 'Privat', 'Fax', 'Zentrale', 'Werkstatt']
let abortController = null
let debounceTimer = null

watch(() => props.modelValue, (val) => {
  dialogVisible.value = val
})
watch(dialogVisible, (val) => {
  emit('update:modelValue', val)
  if (val) {
    nextTick(() => searchRef.value?.focus())
  }
})

watch(searchQuery, (val) => {
  clearTimeout(debounceTimer)
  if (!val || val.length < 2) {
    searchResults.value = []
    return
  }
  debounceTimer = setTimeout(() => searchCv(val), 300)
})

async function searchCv(query) {
  if (abortController) abortController.abort()
  abortController = new AbortController()
  searching.value = true

  try {
    // Kunden und Lieferanten parallel suchen
    const [custRes, vendRes] = await Promise.all([
      axios.post('/api/customer_vendor/', {
        action: 'searchCV',
        type: 'customer',
        where: { name: query },
      }, { signal: abortController.signal }),
      axios.post('/api/customer_vendor/', {
        action: 'searchCV',
        type: 'vendor',
        where: { name: query },
      }, { signal: abortController.signal }),
    ])

    const customers = (custRes.data?.payload?.search?.results ?? []).map(c => ({
      id: c.id,
      name: c.name,
      number: c.cv_number,
      typ: 'C',
    }))
    const vendors = (vendRes.data?.payload?.search?.results ?? []).map(v => ({
      id: v.id,
      name: v.name,
      number: v.cv_number,
      typ: 'V',
    }))

    searchResults.value = [...customers, ...vendors].slice(0, 15)
  } catch (e) {
    if (e.name !== 'CanceledError') searchResults.value = []
  } finally {
    searching.value = false
  }
}

async function assign() {
  if (!selectedCv.value || !props.call) return
  saving.value = true
  try {
    const { data } = await axios.post('/api/customer_vendor/', {
      action: 'assignCallToCv',
      crmti_id: props.call.crmti_id,
      caller_id: selectedCv.value.id,
      caller_typ: selectedCv.value.typ,
      phone_number: props.call.crmti_number,
      phone_label: phoneLabel.value?.trim() || t('CallHistoryView.defaultPhoneLabel'),
    })
    if (!data?.success) throw new Error(data?.text || 'assign failed')
    toast.success(t('CallHistoryView.assignSuccess'))
    emit('assigned')
    dialogVisible.value = false
  } catch {
    toast.error(t('CallHistoryView.assignError'))
  } finally {
    saving.value = false
  }
}

async function removeAssignment() {
  if (!props.call) return
  saving.value = true
  try {
    const { data } = await axios.post('/api/customer_vendor/', {
      action: 'assignCallToCv',
      crmti_id: props.call.crmti_id,
      caller_id: 0,
      caller_typ: 'X',
    })
    if (!data?.success) throw new Error(data?.text || 'remove failed')
    toast.success(t('CallHistoryView.assignRemoved'))
    emit('assigned')
    dialogVisible.value = false
  } catch {
    toast.error(t('CallHistoryView.assignError'))
  } finally {
    saving.value = false
  }
}

function reset() {
  searchQuery.value = ''
  searchResults.value = []
  selectedCv.value = null
  phoneLabel.value = ''
}
</script>
