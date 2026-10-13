<!-- src/core/components/layout/page-header.vue -->
<!--
    Seitenkopf: optionaler Rückweg, Titel, Untertitel, Status neben dem Titel
    und rechts die Aktionen (Umschalter, Knöpfe).

    Eine Zeile, die auf jeder Seite gleich aufgebaut ist, damit sich der
    Anwender nicht an jeder Maske neu orientieren muss. Bricht auf schmalen
    Geräten um: Aktionen rutschen unter den Titel.
-->
<template>
    <header class="oserp-page-header">
        <div class="oserp-page-header__main">
            <v-btn
                v-if="backTo"
                variant="text"
                size="small"
                icon="mdi-arrow-left"
                class="oserp-page-header__back"
                :aria-label="backLabel"
                :title="backLabel"
                @click="router.push(backTo)"
            />
            <div class="oserp-page-header__text">
                <h1 class="oserp-page-header__title">
                    <slot name="title">{{ title }}</slot>
                </h1>
                <div v-if="subtitle || $slots.subtitle" class="oserp-page-header__subtitle">
                    <slot name="subtitle">{{ subtitle }}</slot>
                </div>
            </div>
            <!-- Status neben dem Titel: Chips wie "Speichert …" -->
            <div v-if="$slots.default" class="oserp-page-header__status">
                <slot />
            </div>
        </div>
        <div v-if="$slots.actions" class="oserp-page-header__actions">
            <slot name="actions" />
        </div>
    </header>
</template>

<script setup>
import { useRouter } from 'vue-router'

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    backTo: { type: Object, default: null },
    backLabel: { type: String, default: '' },
})

const router = useRouter()
</script>

<style scoped>
.oserp-page-header {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap);
    padding: var(--oserp-gap-sm) 0 var(--oserp-gap);
}

.oserp-page-header__main {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap);
    flex: 1 1 auto;
    min-width: 0;
}

.oserp-page-header__text {
    min-width: 0;
}

.oserp-page-header__title {
    margin: 0;
    font-size: var(--oserp-title-size);
    font-weight: var(--oserp-title-weight);
    line-height: 1.25;
    overflow-wrap: anywhere;
}

.oserp-page-header__subtitle {
    font-size: 0.9375rem;
    color: var(--oserp-text-muted);
    overflow-wrap: anywhere;
}

.oserp-page-header__status,
.oserp-page-header__actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
}

.oserp-page-header__actions {
    margin-left: auto;
}
</style>
