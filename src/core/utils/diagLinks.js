// src/core/utils/diagLinks.js
//
// Gemeinsame KBA-Prüfung für die Diagnose-Portale (AAG, HGS-Data).
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
