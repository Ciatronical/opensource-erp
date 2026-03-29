// src/core/utils/taxnumber-validation.js

/**
 * Validiert eine deutsche Steuernummer
 * 
 * Hinweis: Steuernummern haben keine Prüfziffer wie USt-IDs.
 * Diese Validierung prüft nur das Format, nicht ob die Nummer existiert.
 * 
 * @param {string} taxnumber - Die zu prüfende Steuernummer
 * @returns {object} - {valid: boolean, message: string, schema: string}
 */
export function validateTaxNumber(taxnumber) {
    if (!taxnumber) {
        return { valid: true, message: '', schema: '' }
    }

    // Entferne Leerzeichen, Schrägstriche und Bindestriche
    const clean = taxnumber.replace(/[\s\/\-]/g, '')

    // Prüfe ob nur Ziffern
    if (!/^\d+$/.test(clean)) {
        return {
            valid: false,
            message: 'CONTAINS_NON_DIGITS',
            schema: ''
        }
    }

    // Bundesschema (einheitlich seit 2008): 13 Ziffern
    // Format: 12 345 67890 1234 oder 12345678901234
    // Aufbau: BBBUUUUUUUASP
    // BBB = Bundesfinanzamtsnummer (3 Ziffern)
    // UUUUUUU = Bezirksnummer + laufende Nummer (7 Ziffern)
    // A = Unterscheidungsnummer bei Sonderfällen (1 Ziffer)
    // SP = Sonderziffer (2 Ziffern, 01-99)
    if (clean.length === 13) {
        return {
            valid: true,
            message: '',
            schema: 'Bundesschema'
        }
    }

    // Alte Länderschemata (vor 2008): 10-11 Ziffern
    // Verschiedene Formate je Bundesland
    if (clean.length >= 10 && clean.length <= 11) {
        return {
            valid: true,
            message: '',
            schema: 'Länderschema'
        }
    }

    // Ungültige Länge
    return {
        valid: false,
        message: 'INVALID_LENGTH',
        length: clean.length,
        schema: ''
    }
}

/**
 * Gibt Test-Steuernummern zurück
 */
export const testTaxNumbers = {
    valid: {
        bundesschema: [
            '1234567890123',        // Bundesschema ohne Formatierung
            '12 345 67890 123',     // Bundesschema mit Leerzeichen
            '12/345/67890/123',     // Bundesschema mit Schrägstrichen
        ],
        laenderschema: [
            '1234567890',           // 10 Stellen
            '12345678901',          // 11 Stellen
            '12/345/67890',         // Mit Schrägstrichen
        ]
    },
    invalid: {
        too_short: [
            '123456789',            // Zu kurz (9 Stellen)
        ],
        too_long: [
            '12345678901234',       // Zu lang (14 Stellen)
        ],
        non_digits: [
            '12ABC67890123',        // Buchstaben
            '12-345-67890-12A',     // Buchstabe am Ende
        ]
    }
}
