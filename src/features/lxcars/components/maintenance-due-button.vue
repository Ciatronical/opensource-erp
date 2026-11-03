<!-- src/features/lxcars/components/maintenance-due-button.vue
     Chip „fällig“ am Wartungsfeld (Auftrag, Werkstatt-Modus, Fahrzeug).
     Rot „fällig“ = Termin/km erreicht (nicht klickbar) oder von Hand markiert (Haken),
     Gelb „bald fällig“, umrandet mit leerem Kästchen = nichts anliegend.
     Klick setzt/entfernt die Hand-Markierung; ein automatisch erreichter Termin
     erlischt erst mit dem neuen Termin bzw. km-Stand (Wartung erledigt). -->

<template>
    <v-tooltip location="top" max-width="320" :text="tooltip">
        <template #activator="{ props: tp }">
            <v-chip
                v-bind="tp"
                size="small"
                label
                :color="look.color"
                :variant="look.variant"
                :prepend-icon="look.icon"
                :disabled="disabled && !state.auto"
                class="maintenance-due"
                :class="'maintenance-due--' + look.kind"
                tabindex="-1"
                v-on="state.auto ? {} : { click: onClick }"
            >{{ look.text }}</v-chip>
        </template>
    </v-tooltip>
</template>

<script>
import { computed, defineComponent } from 'vue'
import { useI18n } from 'vue-i18n'
import { maintenanceDueReasons } from '@/features/lxcars/utils/maintenanceDue.js'

export default defineComponent({
    name: 'MaintenanceDueButton',
    props: {
        // Eintrag aus maintenanceDueState().fields
        state: { type: Object, required: true },
        disabled: { type: Boolean, default: false }
    },
    emits: ['toggle'],
    setup(props, { emit }) {
        const { t, locale } = useI18n()

        const look = computed(() => {
            const s = props.state
            if (s.auto) return { kind: 'auto', color: 'error', variant: 'flat', icon: 'mdi-alert-circle', text: t('MaintenanceDue.chip.due') }
            if (s.manual) return { kind: 'manual', color: 'error', variant: 'flat', icon: 'mdi-checkbox-marked', text: t('MaintenanceDue.chip.due') }
            if (s.state === 'soon') return { kind: 'soon', color: 'warning', variant: 'tonal', icon: 'mdi-clock-alert-outline', text: t('MaintenanceDue.chip.soon') }
            return { kind: 'idle', color: undefined, variant: 'outlined', icon: 'mdi-checkbox-blank-outline', text: t('MaintenanceDue.chip.due') }
        })

        const tooltip = computed(() => {
            const parts = maintenanceDueReasons(props.state, t, locale.value)
            if (props.state.auto) parts.push(t('MaintenanceDue.tooltip.autoHint'))
            else parts.push(t(props.state.manual ? 'MaintenanceDue.tooltip.unmark' : 'MaintenanceDue.tooltip.mark'))
            return parts.join(' · ')
        })

        function onClick(e) {
            e.stopPropagation()
            e.preventDefault()
            emit('toggle', !props.state.manual)
        }

        return { look, tooltip, onClick }
    }
})
</script>

<style scoped>
.maintenance-due { flex-shrink: 0; }
.maintenance-due--idle { color: rgba(var(--v-theme-on-surface), 0.6); }
.maintenance-due--idle:hover { color: rgb(var(--v-theme-on-surface)); }
</style>
