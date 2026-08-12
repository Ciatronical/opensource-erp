<!-- src/core/views/config/tabs/overview.tab.vue -->
<!--
    Startseite der Firmenkonfiguration: eine Kachel je Gruppe.
    Zeigt ehrliche Kennzahlen (Anzahl Bereiche bzw. echte Eintragszahlen
    aus dem Store) und navigiert per Klick in die Gruppe.
-->
<template>
    <div>
        <div class="mb-6">
            <h2 class="text-h5 font-weight-bold mb-1">{{ t('clientConfiguration') }}</h2>
            <p class="text-body-2 text-medium-emphasis mb-0">{{ t('overviewIntro') }}</p>
        </div>

        <v-row>
            <v-col
                v-for="card in cards"
                :key="card.key"
                cols="12" sm="6" lg="4"
            >
                <v-card
                    variant="outlined"
                    class="h-100 overview-card"
                    hover
                    @click="$emit('select', card.target)"
                >
                    <v-card-text class="d-flex flex-column ga-2 pa-4">
                        <v-icon :icon="card.icon" color="primary" size="28" />
                        <div class="text-subtitle-1 font-weight-bold">{{ card.title }}</div>
                        <div class="text-caption text-medium-emphasis">{{ card.meta }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    cards: {
        type: Array,
        required: true
    }
});

defineEmits(['select']);
</script>

<style scoped>
.overview-card {
    cursor: pointer;
    transition: border-color 0.15s ease;
}
.overview-card:hover {
    border-color: rgb(var(--v-theme-primary));
}
</style>
