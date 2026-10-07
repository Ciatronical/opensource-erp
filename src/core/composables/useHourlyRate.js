/**
 * Standard-Stundensatz der Mandantenkonfiguration.
 *
 * Satz:    defaults.customer_hourly_rate (kivitendo-Spalte)
 * Einheit: defaults_oserp.hourly_rate_unit — die Dienstleistungs-Einheit, auf die
 *          sich der Satz bezieht. Nicht gesetzt → erste Dienstleistungs-Einheit
 *          der units-Tabelle in Sortierreihenfolge, deren Basiseinheit "min" ist
 *          (also die Stunde), sonst die erste Dienstleistungs-Einheit überhaupt.
 *
 * Alles kommt aus dem Store (company_config), nichts ist fest verdrahtet.
 */
import { computed } from 'vue'
import { oserpStore } from '@/core/stores/oserp.store.js'

export function useHourlyRate() {
    const store = oserpStore()
    const config = computed(() => store.session?.company_config || {})

    /** Alle Dienstleistungs-Einheiten (units.type = 'service') in Sortierreihenfolge */
    const serviceUnits = computed(() =>
        (config.value.units || [])
            .filter(u => u.type === 'service')
            .sort((a, b) => (a.sortkey ?? 0) - (b.sortkey ?? 0))
            .map(u => u.name)
    )

    /** Einheit, auf die sich der Stundensatz bezieht */
    const hourlyRateUnit = computed(() => {
        const configured = config.value.defaults_oserp?.hourly_rate_unit
        if (configured && serviceUnits.value.includes(configured)) return configured
        const stunde = (config.value.units || []).find(u => u.type === 'service' && u.base_unit === 'min')
        return stunde?.name || serviceUnits.value[0] || ''
    })

    /** Stundensatz netto, 0 wenn nicht gesetzt */
    const hourlyRate = computed(() => Number(config.value.defaults?.customer_hourly_rate) || 0)

    /** Währungskürzel des Mandanten (defaults.currency_id → currencies.name) */
    const currencyName = computed(() =>
        (config.value.currencies || []).find(c => c.id === config.value.defaults?.currency_id)?.name || ''
    )

    return { serviceUnits, hourlyRateUnit, hourlyRate, currencyName }
}
