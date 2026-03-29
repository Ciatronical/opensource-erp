<template>
  <v-row class="pa-2 pa-sm-3">
    <v-col cols="12">
      <v-card variant="outlined" elevation="1">
        <v-card-title class="py-2 px-3 bg-grey-lighten-4">
          <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.price-rules.title') }}</h4>
        </v-card-title>
        <v-divider />
        <v-card-text class="py-2 px-2 px-sm-3">
          <v-alert type="info" density="compact">
            {{ t('CustomerVendorEditView.price-rules.placeholder') }}
          </v-alert>
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>
</template>

<script>
import { useI18n } from 'vue-i18n'

export default {
  name: 'Price-rulesTab',
  setup() {
    const { t } = useI18n()
    return { t }
  }
}
</script>

<style scoped>
.bg-grey-lighten-4 { background-color: #f5f5f5; }
</style>
