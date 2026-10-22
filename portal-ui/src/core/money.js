/**
 * Geldbetraege des Backends lesen und einheitlich ausgeben.
 *
 * Das Backend liefert Betraege UNEINHEITLICH — teils roh aus PostgreSQL,
 * teils schon durch formatPrice() (inc.php:77, number_format($p, 2, ',', '.')):
 *
 *   getCart          positions[].unitPrice/totalPrice  formatiert ("1.234,56")
 *                    totalSum, nettoTotalSum, ...      roh        ("1234.56")
 *   changeQuantity   totalSum, shippingCosts, ...      formatiert
 *                    nettoTotalSum(IncShipping)        roh
 *   deleteCartPos    dito
 *
 * Das alte cart.js schrieb die Werte teils roh, teils durch einen eigenen
 * Intl-Formatter ins Markup — nach einer Mengenaenderung verlor der
 * Nettobetrag deshalb seine Formatierung (cart.js:214). Hier wird jeder
 * Betrag beim Eintreffen in eine Zahl umgewandelt und erst beim Rendern
 * formatiert; welche Aktion ihn geliefert hat, spielt dann keine Rolle mehr.
 */

const LOCALES = { de: 'de-DE', en: 'en-GB' };

/**
 * Backend-Betrag -> Number. Verkraftet beide Schreibweisen.
 *
 * Die Unterscheidung ist eindeutig: formatPrice() setzt immer zwei
 * Nachkommastellen hinter ein Komma, rohe PG-Werte enthalten nie eines.
 * Ein Komma ist damit der sichere Marker fuer die deutsche Schreibweise.
 */
export function toNumber(value) {
  if (value === null || value === undefined || value === '') return 0;
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  const raw = String(value).trim();
  const normalized = raw.includes(',')
    ? raw.replace(/\./g, '').replace(',', '.')
    : raw;
  const parsed = parseFloat(normalized);
  return Number.isFinite(parsed) ? parsed : 0;
}

let formatter = null;

/** Number oder Backend-Betrag -> Anzeigetext in der Sprache des Dokuments. */
export function formatPrice(value) {
  if (!formatter) {
    const lang = (document.documentElement.lang || 'de').slice(0, 2).toLowerCase();
    formatter = new Intl.NumberFormat(LOCALES[lang] || LOCALES.de, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }
  return formatter.format(toNumber(value));
}
