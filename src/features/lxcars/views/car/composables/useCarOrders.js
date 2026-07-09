// Composable: Aufträge filtern und formatieren
// Sortierung + Pager übernimmt die v-data-table in der View (CRM-Optik).

import { ref, computed } from 'vue'
import { formatNumber } from '@/core/utils/numberFormat.js'

export function useCarOrders(locale) {
    const orders = ref([])
    const orderFilter = ref('')

    // Kluger Filter: jeder Suchbegriff muss irgendwo im Auftrag vorkommen
    // (Auftrag-Nr., Datum, Beschreibung, Summe, Waren, Warenbeträge,
    //  Arbeitsanweisungen, Rechnungsnummer/-betrag – alles im search_text vom Backend).
    const filteredOrders = computed(() => {
        const list = orders.value
        const q = (orderFilter.value || '').trim().toLowerCase()
        if (!q) return list
        const terms = q.split(/\s+/).filter(Boolean)
        return list.filter(o => {
            const haystack = [
                o.ordnumber, o.transdate, o.description,
                String(o.amount ?? ''), o.search_text
            ].join(' ').toLowerCase()
            return terms.every(term => haystack.includes(term))
        })
    })

    function formatAmount(value) {
        return formatNumber(value, locale.value) + ' €'
    }

    // Datumsvergleich für die Spaltensortierung (Anzeige = DD.MM.YYYY)
    function compareDate(a, b) {
        const ka = (a || '').split('.').reverse().join('')
        const kb = (b || '').split('.').reverse().join('')
        return ka < kb ? -1 : ka > kb ? 1 : 0
    }

    return {
        orders, orderFilter, filteredOrders,
        formatAmount, compareDate
    }
}
