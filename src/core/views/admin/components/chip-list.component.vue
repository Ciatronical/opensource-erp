<!-- src/core/views/admin/components/chip-list.component.vue -->
<!-- Kompakte Chip-Liste: die ersten Einträge, der Rest als "+n" mit Tooltip -->
<template>
    <div class="d-flex flex-wrap ga-1 align-center">
        <template v-if="items.length">
            <v-chip v-for="name in items.slice(0, max)" :key="name" size="small" variant="tonal">{{ name }}</v-chip>
            <v-tooltip v-if="items.length > max" :text="items.slice(max).join(', ')" location="top">
                <template #activator="{ props: tip }">
                    <v-chip v-bind="tip" size="small" variant="outlined">+{{ items.length - max }}</v-chip>
                </template>
            </v-tooltip>
        </template>
        <v-chip v-else size="small" variant="tonal" :color="emptyColor" prepend-icon="mdi-alert-outline">{{ emptyText }}</v-chip>
    </div>
</template>

<script setup>
defineProps({
    items: { type: Array, default: () => [] },
    max: { type: Number, default: 3 },
    emptyText: { type: String, default: '' },
    emptyColor: { type: String, default: 'grey' }
});
</script>
