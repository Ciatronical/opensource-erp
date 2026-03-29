<!-- src/core/components/about/about.dialog.vue -->
<template>
  <v-dialog v-model="visible" max-width="400">
    <v-card>
      <v-card-title class="d-flex align-center">
        {{ t('AboutDialog.title') }}
        <v-spacer />
        <v-btn icon="mdi-close" size="x-small" variant="text" @click="visible = false" />
      </v-card-title>
      <v-card-text>
        <div class="text-h6 mb-4">{{ appTitle }}</div>
        <div class="text-subtitle-2 text-medium-emphasis mb-2">{{ t('AboutDialog.team') }}</div>
        <v-list density="compact">
          <v-list-item v-for="person in team" :key="person">
            <template #prepend><v-icon size="small" class="me-2">mdi-account</v-icon></template>
            <v-list-item-title>{{ person }}</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn variant="text" @click="visible = false">{{ t('AboutDialog.close') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

export default {
  name: 'AboutDialog',
  props: {
    modelValue: { type: Boolean, default: false },
    appTitle: { type: String, default: 'OpensourceERP' }
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const { t } = useI18n()

    const visible = computed({
      get: () => props.modelValue,
      set: (val) => emit('update:modelValue', val)
    })

    const team = [
      'Ronny Zimmermann',
      'Dirk Schwartzer',
      'Martin Willig'
    ]

    return { t, visible, team }
  }
}
</script>
