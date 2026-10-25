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
    // Einkaufsbelege öffnen über die Route ihres Verkaufs-Gegenstücks; die Faktura
    // ermittelt die echte Belegart aus dem Datensatz (resolveFakturaType)
    purchase_invoice: 'faktura-invoice-view',
    purchase_order: 'faktura-order-view',
    request_quotation: 'faktura-quotation-view',
    purchase_delivery_order: 'faktura-delivery-order-view',
    article: 'article-edit',
    vehicle: 'car',
    wiki: 'wiki-read'
});

/**
 * Abbildung Faktura-Dokumenttyp → Listen-Route
 *
 * Ziel, wenn ein Dokument nicht (mehr) existiert und die Faktura-View
 * den Benutzer zurück in die passende Übersicht schicken muss.
 */
export const DOCUMENT_LIST_ROUTE_NAMES = Object.freeze({
    invoice: 'invoice-list',
    purchase_invoice: 'invoice-list',
    invoice_storno: 'invoice-list',
    credit_note: 'credit-note-list',
    order: 'order-list',
    purchase_order: 'order-list',
    quotation: 'quotation-list',
    request_quotation: 'quotation-list',
    delivery_order: 'delivery-order-list',
    purchase_delivery_order: 'delivery-order-list'
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
    // quotation-edit/order-edit sind nur Platzhalter („Seite nicht gefunden"),
    // die Belege öffnet und bearbeitet die Faktura-Ansicht. Einkaufsbelege laufen
    // über die Route des Verkaufs-Gegenstücks, die Faktura ermittelt die echte
    // Belegart aus dem Datensatz. Alle trans_type-Werte von kivitendo (SL/FU.pm).
    sales_quotation: 'faktura-quotation-view',
    request_quotation: 'faktura-quotation-view',
    purchase_quotation_intake: 'faktura-quotation-view',
    sales_order: 'faktura-order-view',
    sales_order_intake: 'faktura-order-view',
    purchase_order: 'faktura-order-view',
    purchase_order_confirmation: 'faktura-order-view',
    sales_delivery_order: 'faktura-delivery-order-view',
    purchase_delivery_order: 'faktura-delivery-order-view',
    supplier_delivery_order: 'faktura-delivery-order-view',
    sales_invoice: 'faktura-invoice-view',
    purchase_invoice: 'faktura-invoice-view',
    vendor_invoice: 'faktura-invoice-view',
    credit_note: 'faktura-credit-note-view'
});

/**
 * Abbildung kivitendo-`trans_type` → Entitätstyp für den Verlauf
 */
export const TRANS_TYPE_ENTITIES = Object.freeze({
    customer: 'customer',
    vendor: 'vendor',
    sales_invoice: 'invoice',
    purchase_invoice: 'purchase_invoice',
    vendor_invoice: 'purchase_invoice',
    credit_note: 'credit_note',
    sales_order: 'order',
    sales_order_intake: 'order',
    purchase_order: 'purchase_order',
    purchase_order_confirmation: 'purchase_order',
    sales_quotation: 'quotation',
    request_quotation: 'request_quotation',
    purchase_quotation_intake: 'request_quotation',
    sales_delivery_order: 'delivery_order',
    purchase_delivery_order: 'purchase_delivery_order',
    supplier_delivery_order: 'purchase_delivery_order'
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
    if (id === undefined || id === null || id === '') return null;
    // Reine Buchungen (kivitendo ar/ap/gl_transaction) zeigt das Journal
    if (LEDGER_TRANS_TYPES[transType]) {
        return { name: 'accounting-bookings', query: { src: LEDGER_TRANS_TYPES[transType], id: Number(id) } };
    }
    const name = TRANS_TYPE_ROUTE_NAMES[transType];
    if (!name) return null;
    return { name, params: { id: Number(id) } };
}

/** kivitendo-`trans_type` reiner Buchungen → Quelle im Journal (accounting-bookings) */
const LEDGER_TRANS_TYPES = Object.freeze({
    ar_transaction: 'ar',
    ap_transaction: 'ap',
    gl_transaction: 'gl'
});
