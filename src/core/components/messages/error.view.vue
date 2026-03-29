<template>
  <v-alert
    v-if="message"
    :type="type"
    density="comfortable"
    class="mt-3"
    closable
    @click:close="handleClose"
  >
    {{ message }}
  </v-alert>
</template>

<script setup>
const props = defineProps({
  message: { type: String, default: '' },
  type:    { type: String, default: 'warning' }, // 'warning' | 'error' | 'info' | 'success'
})

const emit = defineEmits(['clear'])
const handleClose = () => emit('clear')
</script>
