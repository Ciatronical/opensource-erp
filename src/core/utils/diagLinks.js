// src/core/utils/diagLinks.js
//
// Gemeinsame Helfer für die Fahrzeug-Übergabe an Diagnose-Anwendungen
// (Bosch ESI[tronic] per Custom-Protokoll, Hella Gutmann mega macs per Web-URL).
// Genutzt von der Fahrzeug-View (car.edit.view) und der Auftrags-View (faktura).

// TSN-Platzhalter: ausgenullte Datensätze beginnen mit "000".
export function hasTsnPlaceholder(tsn) {
    return String(tsn || '').trim().substring(0, 3) === '000'
}

// Gültige KBA-Schlüsselnummern? HSN vierstellig, TSN >= 3 Zeichen, kein Platzhalter.
export function isKbaValid(hsn, tsn) {
    const h = String(hsn || '').trim()
    const t = String(tsn || '').trim()
    return /^\d{4}$/.test(h) && t.length >= 3 && !hasTsnPlaceholder(t)
}

// Fahrzeug identifizierbar? Gültige HSN/TSN ODER eine FIN/VIN — gleiche
// Bedingung wie der AAG-Button (der bei ausgenullter TSN über die FIN sucht).
export function hasVehicleId(hsn, tsn, vin) {
    return isKbaValid(hsn, tsn) || !!String(vin || '').trim()
}

/**
 * Custom-Protokoll-URL für Bosch ESI[tronic] (wird vom lokalen Launcher
 * abgefangen, siehe dev/esitronic-protokoll-setup.md). HSN/TSN nur bei
 * gültiger KBA; zusätzlich/ersatzweise die VIN (für ausgenullte Fahrzeuge).
 */
export function buildEsiUrl(hsn, tsn, vin = '') {
    const params = new URLSearchParams()
    if (isKbaValid(hsn, tsn)) {
        params.set('hsn', String(hsn).trim())
        params.set('tsn', String(tsn).trim())
    }
    const v = String(vin || '').trim()
    if (v) params.set('vin', v)
    return 'esitronic://vehicle?' + params.toString()
}

// HGS-Data-Standardvorlage: Suche per HSN/TSN. HGS erwartet die 3-stellige TSN
// ({tsn3}); unser c_3 enthält oft den längeren KBA-Schlüssel (z. B. AST000416).
// HGS löst serverseitig auf seine interne vehicleId/viid auf (Login vorausgesetzt).
export const HGS_DATA_DEFAULT_URL = 'https://www.hgs-data.com/index.php/vehicle/?hsn={hsn}&tsn={tsn3}'

/**
 * Baut eine fahrzeugbezogene Web-URL aus einer Basis-Adresse bzw. URL-Vorlage.
 * Genutzt für Hella Gutmann mega macs und HGS-Data.
 *
 * vals = { hsn, tsn, vin, ktype }
 *
 * Zwei Modi:
 *  - Vorlage: enthält base Platzhalter {hsn} {tsn} {tsn3} {vin} {kba} {ktype},
 *    werden diese (URL-codiert) ersetzt. {tsn3} = 3-stellige TSN (für Dienste
 *    wie HGS-Data, die nur die kurze TSN erwarten).
 *  - Anhängen (Fallback ohne Platzhalter): die vorhandenen Werte werden als
 *    Query an die Basis-URL gehängt. Hinweis: Viele SPA-Oberflächen ignorieren
 *    Query-Parameter an der Wurzel – dann ist der Vorlagen-Modus nötig.
 */
export function buildVehicleUrl(base, vals = {}) {
    let b = String(base || '').trim()
    if (!b) return ''
    if (!/^https?:\/\//i.test(b)) b = 'http://' + b

    const kbaValid = isKbaValid(vals.hsn, vals.tsn)
    const h = kbaValid ? String(vals.hsn).trim() : ''
    const t = kbaValid ? String(vals.tsn).trim() : ''
    const t3 = t.substring(0, 3) // 3-stellige TSN (z. B. für HGS-Data)
    const v = String(vals.vin || '').trim()
    const k = String(vals.ktype || '').trim()

    // Vorlagen-Modus: Platzhalter ersetzen ({tsn3} vor {tsn})
    if (/\{(hsn|tsn|tsn3|vin|kba|ktype)\}/i.test(b)) {
        return b
            .replace(/\{hsn\}/gi, encodeURIComponent(h))
            .replace(/\{tsn3\}/gi, encodeURIComponent(t3))
            .replace(/\{tsn\}/gi, encodeURIComponent(t))
            .replace(/\{vin\}/gi, encodeURIComponent(v))
            .replace(/\{kba\}/gi, encodeURIComponent(h && t ? h + t : ''))
            .replace(/\{ktype\}/gi, encodeURIComponent(k))
    }

    // Fallback: Query anhängen
    const params = new URLSearchParams()
    if (kbaValid) { params.set('hsn', h); params.set('tsn', t) }
    if (v) params.set('vin', v)
    if (k) params.set('ktype', k)
    return b + (b.includes('?') ? '&' : '?') + params.toString()
}

// Rückwärtskompatibler Helfer für mega macs (gleiche Logik, ohne Ktype).
export function buildGutmannUrl(base, hsn, tsn, vin = '') {
    return buildVehicleUrl(base, { hsn, tsn, vin })
}
