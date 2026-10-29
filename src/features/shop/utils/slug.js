// src/features/shop/utils/slug.js

/**
 * Name einer Produktseite aus einem Text: klein, Umlaute aufgelöst, alles
 * außer Buchstaben und Ziffern wird zum Bindestrich.
 *
 * Nutzen die Artikelmaske (Vorschlag aus der Beschreibung) und die Shop-Karte
 * (angehängte Artikelnummer, wenn der Vorschlag vergeben ist).
 *
 * @param {string} text
 * @returns {string}
 */
export function shopSlug(text) {
    return String(text || '')
        .toLowerCase()
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
}
