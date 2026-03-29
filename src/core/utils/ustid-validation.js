// src/core/utils/ustid-validation.js

/**
 * Validiert eine EU-USt-ID nach dem offiziellen Format
 * 
 * @param {string} ustid - Die zu prüfende USt-ID
 * @returns {object} - {valid: boolean, message: string, country: string}
 */
export function validateUstId(ustid) {
    if (!ustid) {
        return { valid: true, message: '', country: '' }
    }

    // Entferne Leerzeichen und Bindestriche
    const cleanUstId = ustid.replace(/[\s\-]/g, '').toUpperCase()

    // Format-Patterns für EU-Länder
    const patterns = {
        AT: /^ATU\d{8}$/,              // Österreich
        BE: /^BE0\d{9}$/,              // Belgien
        BG: /^BG\d{9,10}$/,            // Bulgarien
        CY: /^CY\d{8}[A-Z]$/,          // Zypern
        CZ: /^CZ\d{8,10}$/,            // Tschechien
        DE: /^DE\d{9}$/,               // Deutschland
        DK: /^DK\d{8}$/,               // Dänemark
        EE: /^EE\d{9}$/,               // Estland
        EL: /^EL\d{9}$/,               // Griechenland
        ES: /^ES[A-Z0-9]\d{7}[A-Z0-9]$/, // Spanien
        FI: /^FI\d{8}$/,               // Finnland
        FR: /^FR[A-Z0-9]{2}\d{9}$/,    // Frankreich
        GB: /^GB(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/, // Großbritannien
        HR: /^HR\d{11}$/,              // Kroatien
        HU: /^HU\d{8}$/,               // Ungarn
        IE: /^IE\d[A-Z0-9]\d{5}[A-Z]$/, // Irland
        IT: /^IT\d{11}$/,              // Italien
        LT: /^LT(\d{9}|\d{12})$/,      // Litauen
        LU: /^LU\d{8}$/,               // Luxemburg
        LV: /^LV\d{11}$/,              // Lettland
        MT: /^MT\d{8}$/,               // Malta
        NL: /^NL\d{9}B\d{2}$/,         // Niederlande
        PL: /^PL\d{10}$/,              // Polen
        PT: /^PT\d{9}$/,               // Portugal
        RO: /^RO\d{2,10}$/,            // Rumänien
        SE: /^SE\d{12}$/,              // Schweden
        SI: /^SI\d{8}$/,               // Slowenien
        SK: /^SK\d{10}$/,              // Slowakei
    }

    const countryCode = cleanUstId.substring(0, 2)
    
    if (!patterns[countryCode]) {
        return {
            valid: false,
            message: `Unbekanntes Länderkürzel: ${countryCode}`,
            country: countryCode
        }
    }

    const isValid = patterns[countryCode].test(cleanUstId)

    return {
        valid: isValid,
        message: isValid ? '' : `Ungültiges Format für ${countryCode}-USt-ID`,
        country: countryCode
    }
}

/**
 * Gibt Test-USt-IDs zurück
 */
export const testUstIds = {
    valid: {
        DE: [
            'DE123456789',      // Deutschland - Standard
            'DE 123 456 789',   // Mit Leerzeichen
            'DE-123-456-789',   // Mit Bindestrichen
        ],
        AT: [
            'ATU12345678',      // Österreich
        ],
        FR: [
            'FRXX123456789',    // Frankreich
        ],
        NL: [
            'NL123456789B01',   // Niederlande
        ],
        IT: [
            'IT12345678901',    // Italien
        ],
    },
    invalid: {
        DE: [
            'DE12345678',       // Zu kurz
            'DE1234567890',     // Zu lang
            'DEABC456789',      // Buchstaben statt Zahlen
        ],
        AT: [
            'AT12345678',       // Fehlendes U
            'ATU1234567',       // Zu kurz
        ],
        XX: [
            'XX123456789',      // Unbekanntes Land
        ],
    }
}
