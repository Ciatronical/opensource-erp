/**
 * Antworten der Warenkorb-Aktionen in eine einheitliche Form bringen.
 *
 * Liegt bewusst neben der Komponente und nicht in ihr: <shop-checkout> liest
 * spaeter dieselben Felder (die Aktion `checkout` liefert denselben Aufbau
 * wie `getCart`), und so laesst sich die Umrechnung ohne Browser pruefen.
 *
 * Zur Formatierung der Betraege siehe core/money.js — das Backend mischt
 * rohe und bereits formatierte Werte, teils innerhalb einer Antwort.
 */
import { toNumber } from './money.js';

/**
 * Summenblock aus getCart, changeQuantity oder deleteCartPos.
 *
 * incShippingCosts entscheidet, welches Paar gilt: unterhalb des
 * Mindestumsatzes die *IncShipping-Werte samt Versandkosten, sonst die
 * Werte ohne. Bei leerem Warenkorb liefert das Backend null — das faellt
 * ueber toNumber() auf 0.
 */
export function cartTotals(data) {
  const incShipping = !!data.incShippingCosts;
  return {
    incShipping,
    shipping: incShipping ? toNumber(data.shippingCosts) : 0,
    netto: toNumber(incShipping ? data.nettoTotalSumIncShipping : data.nettoTotalSum),
    total: toNumber(incShipping ? data.totalSumIncShipping : data.totalSum),
    // Versand nach Versandarten (dev/shop-versand.md, Schritt 6): ok heisst
    // bestellbar; sonst nennt der Status den Grund, und die Kasse sperrt.
    // Aeltere Backends liefern nichts — dann gilt ok.
    shippingStatus: data.shippingStatus || 'ok',
    shippingMethod: data.shippingMethod || '',
    shippingMethodId: data.shippingMethodId === null || data.shippingMethodId === undefined ? null : Number(data.shippingMethodId),
    shippingFree: !!data.shippingFree,
    // ISO-Code des Lieferlands, mit dem das Backend gerechnet hat (leer = unbekannt)
    shippingCountry: data.shippingCountry || '',
    // Versandwahl in der Kasse (dev/shop-versand.md, 2026-10-30): alle
    // Versandarten mit Preis, Verfuegbarkeit und Grund; selected ist die, mit
    // der das Backend rechnet. Leer bei aelteren Backends und ohne Versandarten.
    shippingOptions: (data.shippingOptions || []).map((o) => ({
      id: Number(o.id),
      name: o.name || '',
      note: o.note || '',
      price: o.price === null || o.price === undefined ? null : toNumber(o.price),
      free: !!o.free,
      available: !!o.available,
      reason: o.reason || '',
      selected: !!o.selected,
      // durch eine Artikelzuordnung vorgegeben; requiredBy nennt die Artikel
      forced: !!o.forced,
      requiredBy: o.requiredBy || '',
    })),
  };
}

/** Eine Warenkorbposition aus getCart. */
export function cartPosition(pos) {
  return {
    id: pos.id,
    referencedId: pos.referencedId,
    label: pos.label,
    quantity: Number(pos.quantity) || 1,
    unitPrice: toNumber(pos.unitPrice),
    totalPrice: toNumber(pos.totalPrice),
    thumbnail: pos.thumbnail || null,
    // Fertige Adressen aus den Mustern des HugoShops (products_link,
    // thumbnails_link); leer = kein Link bzw. kein Bild
    productUrl: pos.productUrl || '',
    thumbnailUrl: pos.thumbnailUrl || '',
    // false: nicht mehr im Shop angeboten (V23) — ältere Backends liefern nichts
    offered: pos.offered !== false,
    // Lieferbedingung ("Versandfertig in 4-8 Wochen") und Mindestabnahme
    deliveryTerm: pos.deliveryTerm || '',
    minQuantity: pos.minQuantity === null || pos.minQuantity === undefined ? null : Number(pos.minQuantity),
  };
}

/** Vollstaendige getCart-Antwort. */
export function normalizeCart(data) {
  return {
    currency: data.currency || '',
    positions: (data.positions || []).map(cartPosition),
    totals: cartTotals(data),
    // Kunde (Konto oder Gast) in der Sitzung — Voraussetzung fuer PayPal
    // direkt aus dem Warenkorb
    customer: data.customer === true,
  };
}
