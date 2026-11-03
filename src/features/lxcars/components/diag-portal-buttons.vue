<!-- src/features/lxcars/components/diag-portal-buttons.vue -->
<!--
    Fahrzeug an die Werkstatt-Portale übergeben: AAG-Online, HGS-Data und die
    Gutmann-Diagnose (mega macs). Ein Baustein für Fahrzeugmaske, Auftrag und
    Mechaniker-Auftrag, damit die Knöpfe überall gleich aussehen (Wiedererkennung).
    Die Logik dahinter liefert useDiagPortals.js bzw. die Fahrzeugmaske selbst.
-->
<template>
    <div class="diag-portal-buttons">
        <v-btn
            v-if="aagAvailable"
            variant="tonal"
            size="small"
            color="indigo"
            :loading="aagLoading"
            :title="t('CarEditView.aag.tsnTooltip')"
            @click="$emit('open-aag')"
        >
            <v-icon start size="small">mdi-car-search</v-icon>
            {{ t('CarEditView.aag.button') }}
        </v-btn>
        <v-btn
            v-if="hgsAvailable"
            variant="tonal"
            size="small"
            color="blue-grey-darken-1"
            :loading="hgsLoading"
            :title="t('CarEditView.hgs.tooltip')"
            @click="$emit('open-hgs')"
        >
            <v-icon start size="small">mdi-database-search-outline</v-icon>
            {{ t('CarEditView.hgs.button') }}
        </v-btn>
        <!-- mega macs als Seite in LxCars (docs/features/gutmann.md); das Fahrzeug kommt über den offenen Auftrag (asanetwork) ins Gerät -->
        <v-btn
            v-if="gutmannAvailable"
            variant="tonal"
            size="small"
            color="blue-grey-darken-1"
            :title="t('CarEditView.gutmann.tooltip')"
            :to="{ name: 'gutmann' }"
        >
            <v-icon start size="small">mdi-car-wrench</v-icon>
            {{ t('CarEditView.gutmann.button') }}
        </v-btn>
    </div>
</template>

<script>
import { defineComponent } from 'vue'
import { useI18n } from 'vue-i18n'

export default defineComponent({
    name: 'DiagPortalButtons',
    props: {
        aagAvailable: { type: Boolean, default: false },
        aagLoading: { type: Boolean, default: false },
        hgsAvailable: { type: Boolean, default: false },
        hgsLoading: { type: Boolean, default: false },
        gutmannAvailable: { type: Boolean, default: false }
    },
    emits: ['open-aag', 'open-hgs'],
    setup() {
        const { t } = useI18n()
        return { t }
    }
})
</script>

<style scoped>
/* Leer, wenn kein Portal verfügbar ist: dann auch kein Abstand */
.diag-portal-buttons:empty {
    display: none;
}

.diag-portal-buttons {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
}
</style>
