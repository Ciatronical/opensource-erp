<!-- src/features/lxcars/views/special-tools/components/criteria-chips.vue -->
<!--
    Zeigt die Kriterien einer Zuordnungsregel als Chips: Listen (Hersteller,
    Motorcodes, …) gruppiert mit Beschriftung, Zahlenbereiche als ein Chip.
    Ohne Kriterien steht "Alle Fahrzeuge" — das ist bewusst sichtbar, denn so
    eine Regel trifft wirklich alles.
-->
<template>
    <div class="d-flex flex-wrap align-center ga-1">
        <template v-for="group in groups" :key="group.key">
            <span class="text-caption text-medium-emphasis mr-1">{{ group.label }}:</span>
            <v-chip v-for="v in group.values" :key="v" size="x-small" variant="tonal" :color="color" class="mr-0">
                {{ v }}
            </v-chip>
            <v-chip v-if="group.more > 0" size="x-small" variant="text" class="mr-2">+{{ group.more }}</v-chip>
            <span v-else class="mr-2"></span>
        </template>
        <v-chip v-for="r in ranges" :key="r" size="x-small" variant="tonal" :color="color">{{ r }}</v-chip>
        <v-chip v-if="!groups.length && !ranges.length" size="x-small" variant="outlined" color="warning">
            <v-icon start size="x-small">mdi-asterisk</v-icon>
            {{ t('SpecialToolsView.rules.criteria.any') }}
        </v-chip>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { CRITERIA_LIST_KEYS } from '@/features/lxcars/composables/useSpecialTools.js'

const props = defineProps({
    criteria: { type: Object, default: () => ({}) },
    color: { type: String, default: 'primary' },
    /** Mehr Chips je Gruppe werden hinter "+n" zusammengefasst */
    max: { type: Number, default: 10 },
})

const { t } = useI18n()

const groups = computed(() => {
    const out = []
    for (const key of CRITERIA_LIST_KEYS) {
        const list = (props.criteria?.[key] || []).filter(v => v !== null && v !== '')
        if (!list.length) continue
        const values = key === 'vehicle_types'
            ? list.map(v => t(`SpecialToolsView.vehicleTypes.${v}`, v))
            : list
        out.push({
            key,
            label: t(`SpecialToolsView.rules.criteria.${key}`),
            values: values.slice(0, props.max),
            more: Math.max(0, values.length - props.max),
        })
    }
    return out
})

const ranges = computed(() => {
    const c = props.criteria || {}
    const out = []
    const range = (from, to, label, unit) => {
        if (!c[from] && !c[to]) return
        const a = c[from] ? `${c[from]}` : ''
        const b = c[to] ? `${c[to]}` : ''
        const text = a && b ? `${a}–${b}` : a ? `${t('SpecialToolsView.rules.criteria.from')} ${a}` : `${t('SpecialToolsView.rules.criteria.to')} ${b}`
        out.push(`${label} ${text}${unit}`)
    }
    range('ccm_from', 'ccm_to', t('SpecialToolsView.rules.criteria.ccm'), ' ccm')
    range('kw_from', 'kw_to', t('SpecialToolsView.rules.criteria.kw'), ' kW')
    range('year_from', 'year_to', t('SpecialToolsView.rules.criteria.year'), '')
    return out
})
</script>
