<!-- src/features/accounting/components/accounting.page-header.vue -->
<!--
    Kopfzeile jeder Buchhaltungsseite.

    Seit die Buchhaltung nur noch einen Menuepunkt hat, ist das Menue nicht mehr
    der Rueckweg — den muss die Seite selbst anbieten. Die Zeile traegt deshalb
    den Weg zurueck ins Cockpit, den Titel, die Befehlspalette (Strg+K, damit sie
    auf jeder Seite erreichbar ist) und den Umschalter zwischen einfachem und
    fachlichem Modus.
-->
<template>
    <div class="d-flex align-center flex-wrap ga-3 mb-3">
        <v-btn variant="text" size="small" class="text-none px-2"
               :aria-label="t('AccountingView.header.back')"
               @click="router.push({ name: 'accounting-overview' })">
            <v-icon start>mdi-arrow-left</v-icon>
            {{ t('AccountingView.header.back') }}
        </v-btn>
        <v-divider vertical class="my-1" />
        <h1 class="text-h6 mb-0">{{ title }}</h1>
        <slot name="after-title" />
        <v-spacer />
        <slot name="actions" />
        <v-btn variant="tonal" size="small" class="text-none" @click="paletteRef?.openPalette()">
            <v-icon start size="small">mdi-magnify</v-icon>
            {{ t('AccountingView.palette.button') }}
            <kbd class="ml-2">{{ shortcutLabel }}</kbd>
        </v-btn>
        <EasymodeSwitch v-if="!hideEasymode" />
        <ConceptButton />
    </div>

    <CommandPalette ref="paletteRef" />
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import CommandPalette from './accounting.command-palette.vue'
import EasymodeSwitch from './accounting.easymode-switch.vue'
import ConceptButton from './accounting.concept-button.vue'

defineProps({
    title: { type: String, required: true },
    hideEasymode: { type: Boolean, default: false }
})

const { t } = useI18n()
const router = useRouter()
const paletteRef = ref(null)

const shortcutLabel = computed(() =>
    navigator.platform?.toLowerCase().includes('mac') ? '⌘K' : 'Strg+K'
)
</script>

<style scoped>
kbd {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 3px;
    padding: 0 .3em;
    font-size: .78em;
}
</style>
