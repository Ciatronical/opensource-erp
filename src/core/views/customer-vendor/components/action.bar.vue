<template>
  <v-card variant="outlined" class="action-bar mb-4">
    <v-card-text class="action-bar__content">
      <div class="action-bar__left">
        <!-- Workflow Menu -->
        <v-menu>
          <template #activator="{ props }">
            <v-btn
              v-bind="props"
              color="primary"
              variant="elevated"
              prepend-icon="mdi-swap-horizontal"
            >
              {{ t('CustomerVendorEditView.actions.workflow') }}
            </v-btn>
          </template>
          <v-list density="compact">
            <v-list-item
              prepend-icon="mdi-cash-register"
              @click="$emit('save-and-ar-transaction')"
            >
              <v-list-item-title>{{ t('CustomerVendorEditView.actions.saveAndArTransaction') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              prepend-icon="mdi-file-document-edit"
              @click="$emit('save-and-invoice')"
            >
              <v-list-item-title>{{ t('CustomerVendorEditView.actions.saveAndInvoice') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              prepend-icon="mdi-file-document-plus"
              @click="$emit('save-and-order')"
            >
              <v-list-item-title>{{ t('CustomerVendorEditView.actions.saveAndOrder') }}</v-list-item-title>
            </v-list-item>
            <v-list-item
              prepend-icon="mdi-file-document-outline"
              @click="$emit('save-and-quotation')"
            >
              <v-list-item-title>{{ t('CustomerVendorEditView.actions.saveAndQuotation') }}</v-list-item-title>
            </v-list-item>
            <v-divider class="my-1" />
            <v-list-item
              prepend-icon="mdi-delete-outline"
              class="text-error"
              @click="$emit('delete')"
            >
              <v-list-item-title>{{ t('CustomerVendorEditView.actions.delete') }}</v-list-item-title>
            </v-list-item>
          </v-list>
        </v-menu>
      </div>

      <div class="action-bar__right">
        <v-btn
          variant="tonal"
          prepend-icon="mdi-close"
          @click="$emit('close')"
        >
          {{ t('CustomerVendorEditView.actions.close') }}
        </v-btn>
      </div>
    </v-card-text>
  </v-card>
</template>

<script>
import { useI18n } from 'vue-i18n'

export default {
  name: 'ActionBar',
  emits: ['save-and-ar-transaction', 'save-and-invoice', 'save-and-order', 'save-and-quotation', 'delete', 'close'],
  setup() {
    const { t } = useI18n()
    return { t }
  }
}
</script>

<style scoped>
.action-bar {
    background-color: var(--oserp-surface-soft);
    border-color: var(--oserp-border);
    border-radius: var(--oserp-radius);
}

.action-bar__content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: var(--oserp-gap);
    padding: var(--oserp-gap) var(--oserp-gap-lg) !important;
}

.action-bar__left {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.action-bar__right {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Unterhalb des Vuetify-Breakpoints md (seit Vuetify 4: 840px) */
@media (max-width: 839.98px) {
    .action-bar__content {
        flex-direction: column;
        align-items: stretch;
    }

    .action-bar__left,
    .action-bar__right {
        justify-content: center;
    }
}
</style>
