// Fälligkeit der Wartungsfelder eines Fahrzeugs (Zahnriemen Datum/km, Bremsflüssigkeit,
// Wartungsdienst). Eine Logik für Auftrag (Wartung & Service), Werkstatt-Modus und
// Fahrzeugmaske — Daten kommen aus cars_lxcars bzw. oe_ext (siehe docs/features/lxcars.md).
//
// Ein Feld ist „fällig“, wenn
//   - der Monat des Termins erreicht oder überschritten ist (Monatsgenauigkeit, MM/JJJJ;
//     der LxCars-Platzhalter „01/99“ ist damit immer fällig),
//   - der km-Stand des Fahrzeugs den Zahnriemen-km-Stand erreicht hat oder
//   - es von Hand markiert wurde (c_*_due). Ist der Termin/km-Stand ohnehin erreicht,
//     zählt nur das (Hand-Markierung wird weder angezeigt noch angeboten; eine
//     eventuell noch gesetzte Markierung erlischt mit dem nächsten Termin).
// „Bald fällig“: Termin innerhalb der nächsten SOON_MONTHS Monate bzw. weniger als
// SOON_KM km Rest. Steuerkette (c_sk): eine Kette hat weder Termin noch km-Stand —
// beide Zahnriemen-Felder sind ausgeblendet (hidden), ihre Werte bleiben in der DB
// liegen (Irrtum beim Schalter ohne Datenverlust). Fällig wird die Kette nur von
// Hand (z. B. sie klappert): dafür dient die Markierung c_zrd_due, die dann als
// Eintrag „Steuerkette“ (labelKey c_zrd_sk) erscheint.

export const MAINTENANCE_DUE_FIELDS = ['c_zrd', 'c_zrk', 'c_bf', 'c_wd']
export const MAINTENANCE_DUE_FLAGS = { c_zrd: 'c_zrd_due', c_zrk: 'c_zrk_due', c_bf: 'c_bf_due', c_wd: 'c_wd_due' }

export const SOON_MONTHS = 2
export const SOON_KM = 5000


function monthIndex(iso) {
    if (!iso) return null
    const m = String(iso).match(/^(\d{4})-(\d{2})/)
    if (!m) return null
    const year = parseInt(m[1], 10)
    const month = parseInt(m[2], 10)
    if (month < 1 || month > 12) return null
    return year * 12 + (month - 1)
}

function monthLabel(iso) {
    const m = String(iso).match(/^(\d{4})-(\d{2})/)
    return m ? `${m[2]}/${m[1]}` : String(iso)
}

function isTrue(v) {
    return v === true || v === 't' || v === 1 || v === '1'
}

// Steuerkette: kein Termin, kein km — nur die Hand-Markierung (gespeichert in c_zrd_due)
function chainState(manual) {
    return { field: 'c_zrd', labelKey: 'c_zrd_sk', state: manual ? 'due' : 'none', manual, auto: false,
             reasons: manual ? [{ type: 'manual' }] : [], hidden: true }
}

function dateFieldState(field, iso, manual, nowIndex) {
    const idx = monthIndex(iso)
    const reasons = []
    let state = idx === null ? 'none' : 'ok'
    if (idx !== null) {
        const diff = idx - nowIndex
        if (diff <= 0) { state = 'due'; reasons.push({ type: 'date', date: monthLabel(iso), months: -diff }) }
        else if (diff <= SOON_MONTHS) { state = 'soon'; reasons.push({ type: 'dateSoon', date: monthLabel(iso), months: diff }) }
    }
    const auto = reasons.some(r => r.type === 'date')
    if (manual) { state = 'due'; if (!auto) reasons.push({ type: 'manual' }) }
    return { field, labelKey: field, state, manual, auto, reasons, hidden: false }
}

function kmFieldState(field, dueKm, currentKm, manual) {
    const due = Number(dueKm) || null
    const km = Number(currentKm) || null
    const reasons = []
    let state = due ? 'ok' : 'none'
    if (due && km) {
        const rest = due - km
        if (rest <= 0) { state = 'due'; reasons.push({ type: 'km', km, due, over: -rest }) }
        else if (rest <= SOON_KM) { state = 'soon'; reasons.push({ type: 'kmSoon', km, due, rest }) }
    }
    const auto = reasons.some(r => r.type === 'km')
    if (manual) { state = 'due'; if (!auto) reasons.push({ type: 'manual' }) }
    return { field, labelKey: field, state, manual, auto, reasons, hidden: false }
}

/**
 * Fälligkeitszustand aller Wartungsfelder.
 *
 * @param {object} v   Fahrzeug-/Auftragsdaten: c_zrd, c_zrk, c_bf, c_wd, c_*_due,
 *                     km_stand (Auftrag) oder c_km (Fahrzeug) als aktueller km-Stand
 * @param {Date}   today
 * @returns {{ fields: object, items: object[], due: object[], soon: object[], level: 'due'|'soon'|null }}
 *          jedes Feld trägt labelKey (c_zrd | c_zrd_sk | …) für die Beschriftung und hidden (Steuerkette)
 */
export function maintenanceDueState(v = {}, today = new Date()) {
    const nowIndex = today.getFullYear() * 12 + today.getMonth()
    const currentKm = v.km_stand ?? v.c_km
    const chain = isTrue(v.c_sk)

    const fields = chain ? {
        c_zrd: chainState(isTrue(v.c_zrd_due)),
        c_zrk: { field: 'c_zrk', labelKey: 'c_zrk', state: 'none', manual: false, auto: false, reasons: [], hidden: true },
        c_bf:  dateFieldState('c_bf', v.c_bf, isTrue(v.c_bf_due), nowIndex),
        c_wd:  dateFieldState('c_wd', v.c_wd, isTrue(v.c_wd_due), nowIndex)
    } : {
        c_zrd: dateFieldState('c_zrd', v.c_zrd, isTrue(v.c_zrd_due), nowIndex),
        c_zrk: kmFieldState('c_zrk', v.c_zrk, currentKm, isTrue(v.c_zrk_due)),
        c_bf:  dateFieldState('c_bf', v.c_bf, isTrue(v.c_bf_due), nowIndex),
        c_wd:  dateFieldState('c_wd', v.c_wd, isTrue(v.c_wd_due), nowIndex)
    }
    const items = MAINTENANCE_DUE_FIELDS.map(f => fields[f])
    const due = items.filter(i => i.state === 'due')
    const soon = items.filter(i => i.state === 'soon')
    return { fields, items, due, soon, level: due.length ? 'due' : (soon.length ? 'soon' : null) }
}

/**
 * Pflichtfelder beim Abschluss der letzten Arbeitsanweisung (Auftrag, Werkstatt-Modus).
 * Ein Wartungsfeld gilt als ausgefüllt, wenn ein Wert drin steht ODER es von Hand als
 * „fällig“ markiert ist — die Markierung ist eine vollwertige Angabe („Zahnriemen muss
 * gemacht werden“). Bei Steuerkette sind die Zahnriemen-Angaben nicht nötig.
 * @param {object} e oe_ext-Daten (km_stand, c_sk, c_zrd, c_zrk, c_bf, c_wd, c_*_due)
 * @returns {string[]} fehlende Felder in Anzeige-Reihenfolge
 */
export function maintenanceMissingFields(e = {}) {
    const filled = f => !!e[f] || isTrue(e[MAINTENANCE_DUE_FLAGS[f]])
    const missing = []
    if (!e.km_stand) missing.push('km_stand')
    if (!filled('c_bf')) missing.push('c_bf')
    if (!filled('c_wd')) missing.push('c_wd')
    if (!isTrue(e.c_sk)) {
        if (!filled('c_zrd')) missing.push('c_zrd')
        if (!filled('c_zrk')) missing.push('c_zrk')
    }
    return missing
}

/**
 * Einträge für den Hinweis in der Fahrzeugmaske: Zahnriemen Datum und km-Stand bilden
 * einen Eintrag „Zahnriemen“ (Gründe zusammengeführt, „von Hand“ nur einmal), damit
 * der Hinweis in eine Zeile passt. Reihenfolge: fällig vor bald fällig.
 * @returns {{ labelKey: string, state: 'due'|'soon', reasons: object[] }[]}
 */
export function maintenanceDueAlertItems(dueState) {
    const { c_zrd, c_zrk, c_bf, c_wd } = dueState.fields
    const belt = {
        labelKey: c_zrd.labelKey,
        state: [c_zrd.state, c_zrk.state].includes('due') ? 'due' : ([c_zrd.state, c_zrk.state].includes('soon') ? 'soon' : 'none'),
        reasons: c_zrd.reasons.concat(c_zrk.reasons).filter((r, i, all) => r.type !== 'manual' || all.findIndex(x => x.type === 'manual') === i)
    }
    // „von Hand“ ans Ende, Termin/km zuerst
    belt.reasons.sort((a, b) => (a.type === 'manual') - (b.type === 'manual'))
    const items = [belt, c_bf, c_wd].filter(i => i.state === 'due' || i.state === 'soon')
    return items.filter(i => i.state === 'due').concat(items.filter(i => i.state === 'soon'))
}

/**
 * Gründe eines Feldzustands als übersetzte Texte (Tooltip, Hinweis im Fahrzeug).
 * @param {object}   fieldState  Eintrag aus maintenanceDueState().fields
 * @param {Function} t           vue-i18n t()
 * @param {string}   locale      für die km-Formatierung
 */
export function maintenanceDueReasons(fieldState, t, locale = 'de-DE') {
    const km = n => Number(n).toLocaleString(locale)
    return (fieldState?.reasons || []).map(r => {
        switch (r.type) {
            case 'date':     return t('MaintenanceDue.reasons.dateReached', { date: r.date })
            case 'dateSoon': return t('MaintenanceDue.reasons.dateSoon', { date: r.date })
            case 'km':       return t('MaintenanceDue.reasons.kmReached', { km: km(r.km), due: km(r.due) })
            case 'kmSoon':   return t('MaintenanceDue.reasons.kmSoon', { rest: km(r.rest), due: km(r.due) })
            case 'manual':   return t('MaintenanceDue.reasons.manual')
            default:         return ''
        }
    }).filter(Boolean)
}
