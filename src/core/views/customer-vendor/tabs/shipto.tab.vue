<!-- src/core/views/cv/tabs/shipto.tab.vue -->

<template>
  <v-row class="pa-2 pa-sm-3">
    <v-col cols="12">
      <v-card variant="outlined" elevation="1">
        <v-card-title class="py-2 px-3 d-flex flex-column flex-sm-row justify-space-between align-start align-sm-center bg-grey-lighten-4 ga-2">
          <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.delivery.title') }}</h4>
          <v-btn color="primary" size="small" @click="addShipto" prepend-icon="mdi-plus">
            {{ t('CustomerVendorEditView.delivery.addButton') }}
          </v-btn>
        </v-card-title>
        <v-divider />
        <v-card-text class="py-2 px-2 px-sm-3">
          <v-alert v-if="localData.length === 0" type="info" density="compact" class="mb-0">
            {{ t('CustomerVendorEditView.delivery.noAddresses') }}
          </v-alert>
          <v-expansion-panels v-else variant="accordion" v-model="expandedPanels">
            <v-expansion-panel
              v-for="(shipto, index) in localData"
              :key="shipto.shipto_id || `new-\${index}`"
              :value="index"
            >
              <v-expansion-panel-title>
                <div class="d-flex align-center w-100 flex-wrap ga-1">
                  <v-icon class="mr-2">mdi-truck-delivery</v-icon>
                  <span class="font-weight-medium">
                    {{ shipto.shiptoname || t('CustomerVendorEditView.delivery.newAddress') }}
                  </span>
                  <v-spacer />
                  <span class="text-caption text-medium-emphasis mr-2 d-none d-sm-inline">
                    {{ shipto.shiptocity || '' }}
                  </span>
                </div>
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <shipto-form v-model="localData[index]" @remove="removeShipto(index)" />
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>
</template>

<script>
// src/core/views/cv/tabs/shipto.tab.vue

import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import ShiptoForm from '../forms/shipto.form.vue'

export default {
    name: 'ShiptoTab',
    components: { ShiptoForm },
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
        function expandAddressById(shiptoId) {
            if (!shiptoId) return

            const index = localData.value.findIndex(s => s.shipto_id === shiptoId)
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

        const addShipto = () => {
            const newShipto = {
                shipto_id: null,
                trans_id: null,
                shiptoname: '',
                shiptodepartment_1: '',
                shiptodepartment_2: '',
                shiptostreet: '',
                shiptozipcode: '',
                shiptocity: '',
                shiptocountry: '',
                shiptocontact: '',
                shiptophone: '',
                shiptofax: '',
                shiptoemail: '',
                shiptogln: '',
                shiptocp_gender: '',
            }
            localData.value = [...localData.value, newShipto]
            expandedPanels.value = [localData.value.length - 1]
        }

        const removeShipto = (index) => {
            if (confirm(t('CustomerVendorEditView.delivery.confirmRemove'))) {
                const updated = [...localData.value]
                updated.splice(index, 1)
                localData.value = updated
            }
        }

        return {
            t,
            localData,
            expandedPanels,
            addShipto,
            removeShipto
        }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 { background-color: #f5f5f5; }
</style>