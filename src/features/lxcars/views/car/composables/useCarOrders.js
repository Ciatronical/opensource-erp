// Composable: Aufträge filtern und formatieren
// Sortierung + Pager übernimmt die v-data-table in der View (CRM-Optik).

import { ref, computed } from 'vue'
import { formatNumber } from '@/core/utils/numberFormat.js'

export function useCarOrders(locale) {
    const orders = ref([])
    const orderFilter = ref('')
    const searchIndexLoaded = ref(false)
    const searchIndexLoading = ref(false)

    // Kluger Filter: jeder Suchbegriff muss irgendwo im Auftrag vorkommen
    // (Auftrag-Nr., Datum, Beschreibung, Summe, Waren, Warenbeträge,
    //  Arbeitsanweisungen, Rechnungsnummer/-betrag – search_text wird lazy nachgeladen).
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

    /**
     * Lädt den durchsuchbaren Text-Blob (Waren, Anweisungen, Rechnung) einmalig
     * nach und merged ihn in die bereits geladenen Aufträge. Wird erst beim
     * ersten Filtern angestoßen, damit die Ladezeit des Fahrzeugs nicht leidet.
     *
     * @param {Function} loader - liefert Promise<[{ id, search_text }]>
     */
    async function ensureSearchIndex(loader) {
        if (searchIndexLoaded.value || searchIndexLoading.value) return
        searchIndexLoading.value = true
        try {
            const rows = await loader()
            const map = {}
            for (const r of rows || []) map[r.id] = r.search_text || ''
            orders.value = orders.value.map(o => ({ ...o, search_text: map[o.id] || '' }))
            searchIndexLoaded.value = true
        } catch {
            // Ohne Index filtert der Nutzer weiterhin über die Anzeigefelder
        } finally {
            searchIndexLoading.value = false
        }
    }

    function resetSearchIndex() {
        searchIndexLoaded.value = false
    }

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
        searchIndexLoading, ensureSearchIndex, resetSearchIndex,
        formatAmount, compareDate
    }
}
