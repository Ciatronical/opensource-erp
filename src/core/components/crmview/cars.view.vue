<!-- src/components/carsview/cars.view.vue -->

<template>
  <div>
    <v-table v-if="cars.length > 0" hover>
      <thead>
        <tr>
          <th class="text-left">{{ t('CrmView.licensePlate') }}</th>
          <th class="text-left">{{ t('CrmView.manufacturer') }}</th>
          <th class="text-left">{{ t('CrmView.model') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="car in cars"
          :key="car.c_id"
          @click="$router.push({ name: 'car', params: { id: car.c_id } })"
          class="cursor-pointer"
        >
          <td>{{ car.c_ln }}</td>
          <td>{{ car.hersteller }}</td>
          <td>{{ car.name }}</td>
        </tr>
      </tbody>
    </v-table>
    <v-alert v-else type="info" variant="tonal" class="mt-2">
      {{ t('CrmView.noVehicles') }}
    </v-alert>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'

const { t } = useI18n();
const oserpData = oserpStore();
const cars = computed(() => oserpData.customer_vendor?.cars || []);
</script>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}

tbody tr:nth-child(odd) {
  background-color: rgba(0, 0, 0, 0.05);
}

tbody tr:hover {
  background-color: rgba(0, 0, 0, 0.1) !important;
}
</style>
