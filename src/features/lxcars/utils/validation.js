/**
 * KFZ-Feldvalidierungen für das CarEditView
 *
 * Jede Funktion gibt `true` bei Erfolg oder einen i18n-Key zurück.
 * Die Übersetzung erfolgt im Component via :rules.
 */

/**
 * Deutsches KFZ-Kennzeichen
 * Format: 1-3 Buchstaben, Bindestrich, 1-2 Buchstaben, 1-4 Ziffern, optional H/E
 */
const RE_LICENSE_PLATE = /^[A-ZÄÖÜ]{1,3}-[A-Z]{1,2}\d{1,4}[HE]?$/

export function validateLicensePlate(value) {
    if (!value) return true
    const v = value.toUpperCase().trim()
    if (v.length > 9) return 'validation.licensePlateMaxLen'
    if (!RE_LICENSE_PLATE.test(v)) return 'validation.licensePlateFormat'
    return true
}

/**
 * HSN (Herstellerschlüsselnummer) - Feld 2.1 im Fahrzeugschein
 * Exakt 4 Ziffern
 */
const RE_HSN = /^\d{4}$/

export function validateHsn(value) {
    if (!value) return true
    if (!RE_HSN.test(value)) return 'validation.hsnFormat'
    return true
}

/**
 * TSN (Typschlüsselnummer) - Feld 2.2 im Fahrzeugschein
 * 3-10 alphanumerische Zeichen
 */
const RE_TSN = /^[0-9A-Z]{3,10}$/

export function validateTsn(value) {
    if (!value) return true
    const v = value.toUpperCase()
    if (!RE_TSN.test(v)) return 'validation.tsnFormat'
    return true
}

/**
 * Emissionsschlüssel - Feld 14.1 im Fahrzeugschein
 * 4 oder 6 alphanumerische Zeichen
 */
const RE_EMISSION = /^\d{0,2}[0-9A-Z]{4}$/

export function validateEmission(value) {
    if (!value || value === '-') return true
    const v = value.toUpperCase()
    if (!RE_EMISSION.test(v)) return 'validation.emissionFormat'
    return true
}

/**
 * Erstzulassung - darf nicht in der Zukunft liegen und nicht vor 1886
 * (Benz Patent-Motorwagen, erstes Automobil)
 */
const MIN_YEAR = 1886

export function validateFirstRegistration(value) {
    if (!value) return true
    const d = new Date(value)
    if (d.getFullYear() < MIN_YEAR) return 'validation.firstRegTooOld'
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    if (d > today) return 'validation.firstRegFuture'
    return true
}

/**
 * HU-Fälligkeitsprüfung
 * - Überfällig: HU-Monat liegt vor dem aktuellen Monat (Warnung, kein Save-Block)
 * - Zu weit in der Zukunft: > 3 Jahre (Eingabefehler, blockiert Save)
 *   HU-Intervall in DE: 2 Jahre (Neuwagen 3 Jahre), daher max. 3 Jahre realistisch
 */
export function validateHuDate(value) {
    if (!value) return true
    const hu = new Date(value)
    const now = new Date()
    const huEnd = new Date(hu.getFullYear(), hu.getMonth() + 1, 0)
    if (huEnd < new Date(now.getFullYear(), now.getMonth(), 1)) {
        return 'validation.huOverdue'
    }
    const maxFuture = new Date(now.getFullYear() + 3, now.getMonth(), now.getDate())
    if (hu > maxFuture) {
        return 'validation.huFuture'
    }
    return true
}

/**
 * FIN / VIN Prüfziffernberechnung (Modulo 11)
 *
 * Gewichte: zyklisch 2–10 von rechts nach links
 * = [9, 8, 7, 6, 5, 4, 3, 2, 10, 9, 8, 7, 6, 5, 4, 3, 2] von links nach rechts
 */
const VIN_TRANSLITERATION = {
    A: 1, B: 2, C: 3, D: 4, E: 5, F: 6, G: 7, H: 8,
    J: 1, K: 2, L: 3, M: 4, N: 5, P: 7, R: 9,
    S: 2, T: 3, U: 4, V: 5, W: 6, X: 7, Y: 8, Z: 9
}

const VIN_WEIGHTS = [9, 8, 7, 6, 5, 4, 3, 2, 10, 9, 8, 7, 6, 5, 4, 3, 2]

function vinCharToValue(ch) {
    const n = parseInt(ch, 10)
    if (!isNaN(n)) return n
    return VIN_TRANSLITERATION[ch.toUpperCase()] ?? -1
}

export function computeVinCheckDigit(vin) {
    if (!vin || vin.length !== 17) return null
    const upper = vin.toUpperCase()
    if (/[IOQ]/.test(upper)) return null

    let sum = 0
    for (let i = 0; i < 17; i++) {
        const val = vinCharToValue(upper[i])
        if (val < 0) return null
        sum += val * VIN_WEIGHTS[i]
    }
    const remainder = sum % 11
    return remainder === 10 ? 'X' : String(remainder)
}

/**
 * Kurzschreibweise für Datum parsen
 *
 * Eingabe: '1.3.5', '4.2.80', '15.6.23', '01.03.2005', '2005-03-01'
 * Ausgabe: ISO-String 'YYYY-MM-DD' oder null bei ungültiger Eingabe
 *
 * Jahreszahlen werden intelligent aufgelöst:
 * - 1-2 Ziffern: + 2000, falls Zukunft dann - 100
 * - 3-4 Ziffern: unverändert
 */
function buildDate(day, month, year) {
    if (month < 1 || month > 12 || day < 1 || day > 31 || year < 1) return null
    const d = new Date(year, month - 1, day)
    if (d.getFullYear() !== year || d.getMonth() !== month - 1 || d.getDate() !== day) return null
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

export function parseShortDate(input) {
    if (!input) return null
    const trimmed = input.trim()

    // ISO-Format: YYYY-MM-DD
    if (/^\d{4}-/.test(trimmed)) {
        const parts = trimmed.split('-')
        if (parts.length !== 3) return null
        return buildDate(parseInt(parts[2], 10), parseInt(parts[1], 10), parseInt(parts[0], 10))
    }

    // Deutsches Format: D.M.Y oder DD/MM/YYYY (Trennzeichen: . oder /)
    const parts = trimmed.split(/[./]/)
    if (parts.length !== 3) return null

    const day = parseInt(parts[0], 10)
    const month = parseInt(parts[1], 10)
    let year = parseInt(parts[2], 10)

    if (isNaN(day) || isNaN(month) || isNaN(year)) return null

    if (year < 100) {
        const pivotYear = new Date().getFullYear() + 5
        year += 2000
        if (year > pivotYear) year -= 100
    }

    return buildDate(day, month, year)
}

/**
 * ISO-Datum → deutsches Format
 * '2005-03-01' → '01.03.2005'
 */
export function formatDateDE(isoDate) {
    if (!isoDate) return ''
    const m = isoDate.match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (!m) return isoDate
    return `${m[3]}.${m[2]}.${m[1]}`
}

/**
 * Monat/Jahr parsen (für Wartungstermine)
 *
 * Eingabe: '12/28', '12.28', '3/29', '12/2028', '322', '1228', '2028-12', '2028-12-01'
 * Ausgabe: ISO-String 'YYYY-MM-01' oder null bei ungültiger Eingabe
 *
 * Kurzschreibweise ohne Trennzeichen:
 * - 3 Ziffern: erste = Monat, letzte 2 = Jahr (322 → 03/2022)
 * - 4 Ziffern: erste 2 = Monat, letzte 2 = Jahr (1228 → 12/2028)
 */
function buildMonthYear(month, year) {
    if (month < 1 || month > 12) return null
    if (year < 100) {
        const pivotYear = new Date().getFullYear() + 5
        year += 2000
        if (year > pivotYear) year -= 100
    }
    if (year < 1886 || year > 2200) return null
    return `${String(year)}-${String(month).padStart(2, '0')}-01`
}

export function parseMonthYear(input) {
    if (!input) return null
    const trimmed = input.trim()

    // ISO-Format: YYYY-MM oder YYYY-MM-DD
    if (/^\d{4}-/.test(trimmed)) {
        const parts = trimmed.split('-')
        return buildMonthYear(parseInt(parts[1], 10), parseInt(parts[0], 10))
    }

    // Mit Trennzeichen: MM/JJ, MM/JJJJ, MM.JJ, MM.JJJJ
    if (/[./]/.test(trimmed)) {
        const parts = trimmed.split(/[./]/)
        if (parts.length !== 2) return null
        const month = parseInt(parts[0], 10)
        const year = parseInt(parts[1], 10)
        if (isNaN(month) || isNaN(year)) return null
        return buildMonthYear(month, year)
    }

    // Ohne Trennzeichen: nur Ziffern
    if (/^\d{3,4}$/.test(trimmed)) {
        if (trimmed.length === 3) {
            // MJJ: 322 → Monat 3, Jahr 22
            return buildMonthYear(parseInt(trimmed[0], 10), parseInt(trimmed.slice(1), 10))
        }
        // MMJJ: 1228 → Monat 12, Jahr 28
        return buildMonthYear(parseInt(trimmed.slice(0, 2), 10), parseInt(trimmed.slice(2), 10))
    }

    return null
}

/**
 * ISO-Datum → MM/JJJJ Format (für Wartungstermine)
 * '2028-12-01' → '12/2028'
 */
export function formatMonthYear(isoDate) {
    if (!isoDate) return ''
    const m = isoDate.match(/^(\d{4})-(\d{2})/)
    if (!m) return isoDate
    return `${m[2]}/${m[1]}`
}

/**
 * Validierung für Wartungstermine (Monat/Jahr)
 * - Format prüfen
 * - Nicht älter als 2 Jahre in der Vergangenheit
 * - Nicht weiter als 3 Jahre in der Zukunft
 */
export function validateMonthYear(value) {
    if (!value) return true
    const parsed = parseMonthYear(value)
    if (!parsed) return 'validation.monthYearFormat'
    const d = new Date(parsed)
    const now = new Date()
    const minDate = new Date(now.getFullYear() - 2, now.getMonth(), 1)
    if (d < minDate) return 'validation.maintenanceTooOld'
    const maxDate = new Date(now.getFullYear() + 3, now.getMonth(), 1)
    if (d > maxDate) return 'validation.maintenanceTooFar'
    return true
}

/**
 * FIN-Validierung
 * - Exakt 17 Zeichen, A-Z (ohne I, O, Q) und 0-9
 * - Prüfziffer (Pos. 9) wird mit c_finchk verglichen wenn vorhanden
 *
 * Gibt bei Fehler ein Objekt { key, params } oder einen einfachen Key zurück.
 */
const RE_VIN = /^[A-HJ-NPR-Z0-9]{17}$/

export function validateFin(value, checkDigit) {
    if (!value || value === '-') return true
    const v = value.toUpperCase()
    if (v.length !== 17) return 'validation.finLength'
    if (!RE_VIN.test(v)) return 'validation.finChars'

    if (checkDigit && checkDigit !== '-') {
        const computed = computeVinCheckDigit(v)
        if (computed !== null && computed !== checkDigit.toUpperCase()) {
            return { key: 'validation.finCheckDigit', params: { expected: computed } }
        }
    }
    return true
}
