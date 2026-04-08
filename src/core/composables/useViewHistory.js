// src/core/composables/useViewHistory.js

import { oserpStore } from '@/core/stores/oserp.store.js'

const HISTORY_CONFIG_KEY = 'view-history'
const MAX_HISTORY = 10

/**
 * Composable für den "Zuletzt besucht"-Verlauf.
 *
 * Speichert besuchte Datensätze (Kunden, Aufträge, Artikel etc.)
 * in der employee_config_oserp und stellt sie für die Schnellsuche bereit.
 */
export function useViewHistory() {
    const oserp = oserpStore()

    /**
     * Liest den gespeicherten Verlauf aus der Employee-Config
     *
     * @return {Array} Array von History-Einträgen
     */
    function loadHistory() {
        try {
            const raw = oserp.getConfigValue(HISTORY_CONFIG_KEY, '[]')
            return JSON.parse(raw) || []
        } catch {
            return []
        }
    }

    /**
     * Speichert einen Eintrag im Verlauf (vorne eingefügt, Duplikate entfernt)
     *
     * @param {Object} item - Der zu speichernde Eintrag
     * @param {string} item.type - Entitätstyp (customer, order, invoice, ...)
     * @param {number|string} item.id - ID des Datensatzes
     * @param {string} item.title - Anzeigename
     * @param {string} [item.subtitle] - Zusatzinfo
     * @param {string|Object} item.route - Vue-Router-Pfad oder Route-Objekt
     */
    function saveToHistory(item) {
        const entry = {
            type: item.type,
            id: item.id,
            title: item.title,
            subtitle: item.subtitle || '',
            route: item.route
        }
        let history = loadHistory()
        history = history.filter(h => !(h.type === entry.type && String(h.id) === String(entry.id)))
        history.unshift(entry)
        if (history.length > MAX_HISTORY) history.length = MAX_HISTORY
        oserp.setConfigValue(HISTORY_CONFIG_KEY, JSON.stringify(history))
    }

    /**
     * Löscht den gesamten Verlauf
     */
    function clearHistory() {
        oserp.deleteConfigValue(HISTORY_CONFIG_KEY)
    }

    /**
     * Baut die Dropdown-Items für die Schnellsuche auf (mit Header und Löschen-Button)
     *
     * @return {Array} Array von Items für v-autocomplete
     */
    /**
     * @param {Function} t - vue-i18n Übersetzungsfunktion
     */
    function getHistoryItems(t) {
        let history = loadHistory()
        if (oserp.isLxCars && !oserp.isLxCars()) {
            history = history.filter(h => h.type !== 'vehicle')
        }
        if (!history.length) return []

        return [
            {
                _groupHeader: true,
                _groupLabel: t('GlobalSearch.recentlyVisited'),
                _key: '_header_history',
                id: '_header_history',
                type: 'history'
            },
            ...history.map(h => ({ ...h, _fromHistory: true, _key: h.type + '_' + h.id })),
            {
                _clearHistory: true,
                _key: '_clear_history',
                id: '_clear_history',
                type: 'action'
            }
        ]
    }

    return {
        loadHistory,
        saveToHistory,
        clearHistory,
        getHistoryItems
    }
}
