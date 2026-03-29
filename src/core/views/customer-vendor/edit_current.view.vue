<template>
    <CustomerVendorEditView :id="customer_id" />
</template>

<script>
import { computed } from 'vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import CustomerVendorEditView from '@/core/views/customer-vendor/cv.edit.view.vue'

export default {
  name: 'CurrentCustomerVendorEditView',
  components: { CustomerVendorEditView },

  setup() {
    const oserp = oserpStore();
    const customer_id = computed(() => String(oserp.customer_vendor?.profile?.id));

    return {
      customer_id,
      CustomerVendorEditView,
    }
  },
}
</script>
