// src/core/utils/iban.js
// IBAN-Validierung (Format, Länderlänge, MOD-97-Prüfsumme) ohne externe Library.

const COUNTRY_LENGTHS = {
    AT: 20, BE: 16, BG: 22, CH: 21, CY: 28, CZ: 24, DE: 22, DK: 18,
    EE: 20, ES: 24, FI: 18, FR: 27, GB: 22, GR: 27, HR: 21, HU: 28,
    IE: 22, IS: 26, IT: 27, LI: 21, LT: 20, LU: 20, LV: 21, MC: 27,
    MT: 31, NL: 18, NO: 15, PL: 28, PT: 25, RO: 24, SE: 24, SI: 19,
    SK: 24, SM: 27
}

export function normalizeIban(value) {
    if (!value) return ''
    return value.replace(/\s+/g, '').toUpperCase()
}

export function formatIban(value) {
    const iban = normalizeIban(value)
    return iban.replace(/(.{4})/g, '$1 ').trim()
}

/**
 * Prüft eine IBAN auf Format, Länderlänge und MOD-97-Prüfsumme.
 * @returns {{valid: boolean, code?: string, country?: string, expectedLength?: number}}
 *   code: 'empty' | 'format' | 'length' | 'checksum'
 */
export function validateIban(value) {
    const iban = normalizeIban(value)
    if (!iban) return { valid: false, code: 'empty' }

    if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban)) {
        return { valid: false, code: 'format' }
    }
    if (iban.length < 15 || iban.length > 34) {
        return { valid: false, code: 'format' }
    }

    const country = iban.substring(0, 2)
    const expected = COUNTRY_LENGTHS[country]
    if (expected && iban.length !== expected) {
        return { valid: false, code: 'length', country, expectedLength: expected }
    }

    // MOD-97: ersten 4 Zeichen ans Ende, Buchstaben durch Zahlen ersetzen (A=10, ..., Z=35),
    // dann Modulo 97 chunkweise rechnen.
    const rearranged = iban.substring(4) + iban.substring(0, 4)
    const numeric = rearranged.replace(/[A-Z]/g, c => (c.charCodeAt(0) - 55).toString())
    let remainder = ''
    for (let i = 0; i < numeric.length; i++) {
        remainder += numeric[i]
        if (remainder.length >= 9) {
            remainder = (parseInt(remainder, 10) % 97).toString()
        }
    }
    if (parseInt(remainder, 10) % 97 !== 1) {
        return { valid: false, code: 'checksum', country }
    }
    return { valid: true, country }
}

/**
 * Prüft eine BIC oberflächlich (8 oder 11 Zeichen, alphanumerisch).
 * Echte BIC-Verzeichnis-Prüfung machen wir nicht — nur Format.
 */
export function validateBic(value) {
    if (!value) return { valid: true } // BIC ist innerhalb SEPA optional
    const bic = value.replace(/\s+/g, '').toUpperCase()
    if (!/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(bic)) {
        return { valid: false, code: 'format' }
    }
    return { valid: true }
}
