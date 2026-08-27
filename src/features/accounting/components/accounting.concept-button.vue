<!-- src/features/accounting/components/accounting.concept-button.vue -->
<!--
    Weg ins Buchhaltungskonzept.

    Steht bewusst direkt neben dem Umschalter „Einfach/Fachlich": wer sich fragt,
    was der Unterschied ist, fragt sich meist auch, wie das Ganze gedacht ist.
    Das Ziel ist die Doku-Seite, nicht ein Hilfetext im Fenster — sie ist lang,
    bebildert und soll sich lesen lassen wie ein Handbuch.
-->
<template>
    <v-tooltip location="bottom" max-width="320" :text="t('AccountingView.concept.tooltip')">
        <template #activator="{ props }">
            <v-btn
                v-bind="props"
                variant="outlined"
                size="small"
                density="compact"
                class="text-none px-3"
                :to="{ name: 'docs', params: { slug: 'buchhaltung' } }"
            >
                <v-icon start size="small">mdi-book-open-page-variant-outline</v-icon>
                {{ t('AccountingView.concept.button') }}
            </v-btn>
        </template>
    </v-tooltip>
</template>

<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
