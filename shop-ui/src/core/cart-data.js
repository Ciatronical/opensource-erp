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
  };
}

/** Vollstaendige getCart-Antwort. */
export function normalizeCart(data) {
  return {
    currency: data.currency || '',
    positions: (data.positions || []).map(cartPosition),
    totals: cartTotals(data),
  };
}
