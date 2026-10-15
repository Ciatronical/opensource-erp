// src/core/utils/partsSearch.js
//
// Gewichte der Artikelsuche in den Belegpositionen (parts.php findParts).
// Sie stehen in defaults_oserp (Firmenkonfiguration → Artikelsuche in Belegen)
// und werden bei jeder Suche mitgeschickt — der Faktura-Store liest sie aus dem
// Session-Store, die Probesuche in der Konfiguration aus den noch ungespeicherten
// Formularwerten. Leere oder ungültige Werte fallen auf die Standards zurück,
// 0 ist erlaubt (Häufigkeit ohne Einfluss).

export const PARTS_SEARCH_DEFAULTS = Object.freeze({
    parts_search_limit: 12,          // Anzahl Vorschläge
    parts_search_usage_weight: 40,   // Gewicht Verwendungen gesamt
    parts_search_recent_weight: 60,  // Gewicht Verwendungen „zuletzt“
    parts_search_recent_months: 12   // Zeitraum „zuletzt“ in Monaten
});

/**
 * Liest die vier Gewichte über einen Getter und liefert sie in der Form,
 * die das Backend erwartet.
 *
 * @param {(key: string) => any} readKey - liefert den Rohwert zu einem defaults_oserp-Schlüssel
 * @returns {{limit: number, usage_weight: number, recent_weight: number, recent_months: number}}
 */
export function partsSearchWeights(readKey) {
    const num = key => {
        const v = readKey(key);
        const fallback = PARTS_SEARCH_DEFAULTS[key];
        return (v === null || v === undefined || v === '' || isNaN(Number(v))) ? fallback : Number(v);
    };
    return {
        limit: num('parts_search_limit'),
        usage_weight: num('parts_search_usage_weight'),
        recent_weight: num('parts_search_recent_weight'),
        recent_months: num('parts_search_recent_months')
    };
}
