<!-- src/features/shop/components/shop-nav-layout.vue -->
<!--
    Seite mit Seitennavigation, wie in der Firmenkonfiguration — für lange
    Shop-Ansichten (Versandarten, Verkaufskanäle).

    Oben der Seitenkopf (Slot „kopf“), darunter links die Navigation und
    rechts der Inhalt (Standard-Slot). Ein Klick auf ein Ziel springt zum
    Element mit dieser id. Ab mittlerer Breite füllt die Seite genau das
    Fenster unter der Kopfleiste, Navigation und Inhalt blättern je für sich —
    die Navigation wandert so nicht mit dem Inhalt aus dem Bild. Schmaler
    bleibt es beim gewöhnlichen Seitenfluss mit einer Auswahl oben.

        <ShopNavLayout :ziele="ziele">
            <template #kopf>…</template>
            …Inhalt mit den Ankern…
        </ShopNavLayout>

    ziele: [{ id, title, icon, ebene, inaktiv, gruppe }] in der Reihenfolge
    der Seite; ebene 1 = eingerückter Eintrag unter dem Abschnitt davor,
    gruppe = Name des Abschnitts für die Auswahl auf schmalen Bildschirmen.
    Ohne Ziele entfällt die Navigation (etwa ohne Recht).
-->
<template>
    <v-container fluid class="navseite" :class="{ 'navseite--geteilt': ziele.length }">
        <slot name="kopf" />

        <v-row v-if="ziele.length" class="navseite__layout">
            <v-col cols="12" md="3" lg="2" class="navseite__nav">
                <!-- Mobil: kompakte Auswahl statt langer Liste -->
                <v-select
                    class="d-md-none"
                    :model-value="aktiv"
                    :items="auswahl"
                    :label="t('selectSection')"
                    variant="outlined"
                    density="compact"
                    hide-details
                    @update:model-value="springen"
                />
                <v-list density="compact" nav class="d-none d-md-block pa-0">
                    <v-list-item
                        v-for="ziel in ziele"
                        :key="ziel.id"
                        :prepend-icon="ziel.icon"
                        :active="aktiv === ziel.id"
                        :class="{ 'navseite__eintrag': ziel.ebene && !ziel.icon }"
                        @click="springen(ziel.id)"
                    >
                        <v-list-item-title :class="{ 'text-body-2': ziel.ebene, 'text-medium-emphasis': ziel.inaktiv }">
                            {{ ziel.title }}
                        </v-list-item-title>
                    </v-list-item>
                </v-list>
            </v-col>

            <v-col cols="12" md="9" lg="10" class="navseite__inhalt">
                <slot />
            </v-col>
        </v-row>
        <slot v-else />
    </v-container>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    /** Sprungziele in der Reihenfolge der Seite */
    ziele: { type: Array, default: () => [] },
})

const { t } = useI18n()

/** Zuletzt angesprungenes Ziel */
const aktiv = ref('')

/** Dieselben Ziele für die Auswahl auf schmalen Bildschirmen */
const auswahl = computed(() => props.ziele.map(z => ({
    value: z.id,
    title: z.ebene && z.gruppe ? `${z.gruppe} – ${z.title}` : z.title,
})))

function springen(id) {
    if (!id) return
    aktiv.value = id
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

defineExpose({ springen })
</script>

<style scoped>
@media (min-width: 960px) {
    /* Fensterhöhe abzüglich der Kopfleiste (--v-layout-top setzt Vuetify) */
    .navseite--geteilt {
        display: flex;
        flex-direction: column;
        height: calc(100dvh - var(--v-layout-top, 0px));
    }
    .navseite--geteilt > * {
        flex: 0 0 auto;
    }
    .navseite--geteilt > .navseite__layout {
        flex: 1 1 auto;
        min-height: 0;
        flex-wrap: nowrap;
    }
    .navseite__nav,
    .navseite__inhalt {
        height: 100%;
        overflow-y: auto;
        /* Eigene Spur für den Rollbalken: Browser mit überlagerndem
           Rollbalken legten ihn sonst über Felder und Schaltflächen */
        scrollbar-gutter: stable;
    }
    .navseite__inhalt {
        padding-inline-end: var(--oserp-gap-xl);
    }
    .navseite__nav {
        padding-inline-end: var(--oserp-gap);
    }
}
.navseite__eintrag {
    padding-inline-start: var(--oserp-gap-xl);
}
</style>
