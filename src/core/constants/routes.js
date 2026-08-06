// src/core/constants/routes.js

/**
 * Abbildung Entitätstyp → Route-Name
 *
 * Einzige Stelle, an der Suchergebnisse, Verlaufseinträge und
 * Wiedervorlage-Verknüpfungen auf konkrete Routen abgebildet werden.
 * Die zugehörigen URLs stehen ausschliesslich in der Routen-Tabelle
 * (src/core/router/index.js) — hier werden nie Pfade gebildet.
 */
export const ENTITY_ROUTE_NAMES = Object.freeze({
    customer: 'change-customer',
    vendor: 'change-vendor',
    invoice: 'faktura-invoice-view',
    order: 'faktura-order-view',
    quotation: 'faktura-quotation-view',
    credit_note: 'faktura-credit-note-view',
    delivery_order: 'faktura-delivery-order-view',
    article: 'article-edit',
    vehicle: 'car',
    wiki: 'wiki-read'
});

/**
 * Abbildung kivitendo-`trans_type` → Route-Name für Wiedervorlagen und Aufgaben
 *
 * Verknüpfungen aus Wiedervorlagen/Aufgaben führen in die Bearbeiten-Ansicht,
 * nicht in die Anzeige-Ansicht der Schnellsuche — deshalb eine eigene Abbildung.
 */
export const TRANS_TYPE_ROUTE_NAMES = Object.freeze({
    customer: 'customer-edit',
    vendor: 'vendor-edit',
    sales_quotation: 'quotation-edit',
    sales_order: 'order-edit',
    sales_invoice: 'faktura-invoice-view'
});

/**
 * Abbildung kivitendo-`trans_type` → Entitätstyp für den Verlauf
 */
export const TRANS_TYPE_ENTITIES = Object.freeze({
    customer: 'customer',
    vendor: 'vendor',
    sales_invoice: 'invoice',
    sales_order: 'order',
    sales_quotation: 'quotation'
});

/**
 * Baut ein Route-Objekt für eine Entität
 *
 * @param {string} type - Entitätstyp (customer, vendor, invoice, ...)
 * @param {number|string} id - ID des Datensatzes
 * @return {Object|null} Route-Objekt für router.push() oder null bei unbekanntem Typ
 */
export function entityRoute(type, id) {
    const name = ENTITY_ROUTE_NAMES[type];
    if (!name || id === undefined || id === null || id === '') return null;
    return { name, params: { id: Number(id) } };
}

/**
 * Baut ein Route-Objekt für eine Wiedervorlage-/Aufgaben-Verknüpfung
 *
 * @param {string} transType - kivitendo-Typ (sales_invoice, sales_order, ...)
 * @param {number|string} id - ID des Datensatzes
 * @return {Object|null} Route-Objekt für router.push() oder null bei unbekanntem Typ
 */
export function transTypeRoute(transType, id) {
    const name = TRANS_TYPE_ROUTE_NAMES[transType];
    if (!name || id === undefined || id === null || id === '') return null;
    return { name, params: { id: Number(id) } };
}
