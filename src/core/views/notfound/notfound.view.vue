<template>
    <StartupView :message="{ title: $t('NotFoundView.title'), description: $t('NotFoundView.message'), type: 'warning' }" />
</template>

<script setup>
import StartupView from '@/core/views/startup/startup.view.vue';

defineProps({
  id: {
    type: [String, Number],
    required: false,
    default: null
  }
});
</script>
