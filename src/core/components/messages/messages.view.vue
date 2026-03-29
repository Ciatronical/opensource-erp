<template>
  <v-container class="pt-3" v-if="normalizedMessages.length">
    <v-row justify="center">
      <v-col cols="12" md="5">
        <v-alert
          v-for="(msg, idx) in normalizedMessages"
          :key="idx + '-' + msg.title"
          :type="mapType(msg.type)"
          variant="tonal"
          border="start"
          density="comfortable"
          class="mb-3"
        >
          <div class="pb-2 font-weight-bold">{{ msg.title }}</div>
          <p class="mb-0">{{ msg.description }}</p>
        </v-alert>
      </v-col>
    </v-row>
  </v-container>
</template>

<script>
import { computed } from 'vue'

export default {
  name: 'MessagesView',

  props: {
    /**
     * Erwartet eine Liste von Nachrichten:
     * [{ title: String, description?: String, type?: 'info'|'warning'|'error'|'success' }]
     * Optional rückwärtskompatibel: einzelne Nachricht als Object.
     */
    messages: {
      type: [Array, Object],
      default: () => []
    }
  },

  setup(props) {
    const mapType = (t) => (['info', 'warning', 'error', 'success'].includes(t) ? t : 'info')

    const normalizedMessages = computed(() => {
      const list = Array.isArray(props.messages) ? props.messages : [props.messages]
      return list
        .filter(m => m && m.title)
        .map(m => ({
          title: m.title,
          description: m.description || '',
          type: mapType(m.type || 'info')
        }))
    })

    return { mapType, normalizedMessages }
  }
}
</script>
