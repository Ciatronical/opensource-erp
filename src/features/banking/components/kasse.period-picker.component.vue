<!-- src/features/banking/components/kasse.period-picker.component.vue -->
<!--
    Zeitraumauswahl der Kasse: Monat (Standard), Jahr oder Gesamt, dazu die
    Blätterpfeile.

    Als eigene Komponente, weil in der Kasse drei Listen denselben Zeitraum
    brauchen — Kassenbuch, offene Ausgangs- und offene Eingangsrechnungen. Drei
    handgeschriebene Kopien derselben Leiste hätten sich früher oder später
    auseinanderentwickelt.
-->
<template>
    <v-btn-toggle :model-value="mode" mandatory density="compact" variant="outlined" rounded="lg"
                  @update:model-value="$emit('update:mode', $event)">
        <v-btn value="month" size="small">{{ t('KasseView.modeMonth') }}</v-btn>
        <v-btn value="year" size="small">{{ t('KasseView.modeYear') }}</v-btn>
        <v-btn value="all" size="small">{{ t('KasseView.wholePeriod') }}</v-btn>
    </v-btn-toggle>

    <div v-if="mode !== 'all'" class="d-flex align-center">
        <v-btn icon="mdi-chevron-left" variant="text" size="small"
               :aria-label="t('KasseView.previousPeriod')" @click="shift(-1)" />
        <div class="text-subtitle-1 font-weight-medium text-center" :style="{ minWidth: mode === 'year' ? '90px' : '160px' }">
            {{ label }}
        </div>
        <v-btn icon="mdi-chevron-right" variant="text" size="small"
               :aria-label="t('KasseView.nextPeriod')" @click="shift(1)" />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    mode:  { type: String, required: true },   // 'month' | 'year' | 'all'
    year:  { type: Number, required: true },
    month: { type: Number, required: true },   // 0-basiert
})
const emit = defineEmits(['update:mode', 'update:year', 'update:month'])

const { t } = useI18n()

const monthNames = computed(() => [
    t('KasseView.months.jan'), t('KasseView.months.feb'), t('KasseView.months.mar'),
    t('KasseView.months.apr'), t('KasseView.months.may'), t('KasseView.months.jun'),
    t('KasseView.months.jul'), t('KasseView.months.aug'), t('KasseView.months.sep'),
    t('KasseView.months.oct'), t('KasseView.months.nov'), t('KasseView.months.dec'),
])

const label = computed(() =>
    props.mode === 'year' ? String(props.year) : `${monthNames.value[props.month]} ${props.year}`
)

/**
 * Einen Zeitraum vor oder zurück. Im Monatsmodus läuft der Jahreswechsel mit,
 * damit man vom Januar aus im Dezember des Vorjahres landet und nicht im
 * Dezember desselben Jahres.
 */
function shift(step) {
    if (props.mode === 'year') { emit('update:year', props.year + step); return }
    const next = new Date(props.year, props.month + step, 1)
    emit('update:year', next.getFullYear())
    emit('update:month', next.getMonth())
}
</script>
