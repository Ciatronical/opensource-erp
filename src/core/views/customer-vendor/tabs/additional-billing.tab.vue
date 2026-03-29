<!-- src/core/views/cv/tabs/additional-billing.tab.vue -->

<template>
  <v-row class="pa-2 pa-sm-3">
    <v-col cols="12">
      <v-card variant="outlined" elevation="1">
        <v-card-title class="py-2 px-3 d-flex flex-column flex-sm-row justify-space-between align-start align-sm-center bg-grey-lighten-4 ga-2">
          <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.billAddresses.title') }}</h4>
          <v-btn color="primary" size="small" @click="addBillingAddress" prepend-icon="mdi-plus">
            {{ t('CustomerVendorEditView.billAddresses.addButton') }}
          </v-btn>
        </v-card-title>
        <v-divider />
        <v-card-text class="py-2 px-2 px-sm-3">
          <v-alert v-if="localData.length === 0" type="info" density="compact" class="mb-0">
            {{ t('CustomerVendorEditView.billAddresses.noAddresses') }}
          </v-alert>
          <v-expansion-panels v-else variant="accordion" v-model="expandedPanels">
            <v-expansion-panel
              v-for="(billing, index) in localData"
              :key="billing.id || `new-billing-\${index}`"
              :value="index"
            >
              <v-expansion-panel-title>
                <div class="d-flex align-center w-100 flex-wrap ga-1">
                  <v-icon class="mr-2">mdi-file-document-outline</v-icon>
                  <span class="font-weight-medium">
                    {{ billing.name || t('CustomerVendorEditView.billAddresses.newAddress') }}
                  </span>
                  <v-spacer />
                  <v-chip v-if="billing.default_address" size="x-small" color="primary" class="mr-2">
                    {{ t('CustomerVendorEditView.billAddresses.defaultChip') }}
                  </v-chip>
                  <span class="text-caption text-medium-emphasis mr-2 d-none d-sm-inline">
                    {{ billing.city || '' }}
                  </span>
                </div>
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <billing-address-form v-model="localData[index]" @remove="removeBillingAddress(index)" />
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>
</template>

<script>
// src/core/views/cv/tabs/additional-billing.tab.vue

import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BillingAddressForm from '../forms/billing.address.form.vue'

export default {
    name: 'AdditionalBillingTab',
    components: { BillingAddressForm },
    props: {
        modelValue: {
            type: Array,
            required: true
        },
        expandId: {
            type: Number,
            default: null
        }
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const { t } = useI18n()
        const expandedPanels = ref([])
        const localData = computed({
            get: () => props.modelValue,
            set: (value) => emit('update:modelValue', value)
        })

        /**
         * Klappt die Adresse mit der übergebenen ID auf
         */
        function expandAddressById(billingId) {
            if (!billingId) return

            const index = localData.value.findIndex(b => b.id === billingId)
            if (index !== -1) {
                expandedPanels.value = [index]
            }
        }

        // Bei Änderung der expandId aufklappen
        watch(() => props.expandId, (newId) => {
            if (newId) {
                expandAddressById(newId)
            }
        }, { immediate: true })

        // Auch wenn Daten später geladen werden
        watch(() => localData.value, () => {
            if (props.expandId && expandedPanels.value.length === 0) {
                expandAddressById(props.expandId)
            }
        })

        const addBillingAddress = () => {
            const newAddress = {
                id: null,
                customer_id: null,
                name: '',
                department_1: '',
                department_2: '',
                contact: '',
                street: '',
                zipcode: '',
                city: '',
                country: '',
                gln: '',
                email: '',
                phone: '',
                fax: '',
                default_address: false,
                dunning_mail: '',
            }
            localData.value = [...localData.value, newAddress]
            expandedPanels.value = [localData.value.length - 1]
        }

        const removeBillingAddress = (index) => {
            if (confirm(t('CustomerVendorEditView.billAddresses.confirmRemove'))) {
                const updated = [...localData.value]
                updated.splice(index, 1)
                localData.value = updated
            }
        }

        return {
            t,
            localData,
            expandedPanels,
            addBillingAddress,
            removeBillingAddress
        }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 { background-color: #f5f5f5; }
</style>