/**
 * Google-Tag-Ereignisse (dataLayer).
 *
 * Ersetzt die Teile von js/shopwindow/gtm.js, die die Widgets brauchen.
 *
 * EINWILLIGUNG — bewusst unveraendert uebernommen: das alte gtm.js feuert bei
 * 'accepted' UND bei 'declined', nur ein noch gar nicht gefragter Besucher
 * bleibt aussen vor. Praktisch geht bei 'declined' nichts an Google, weil
 * head.html:33 und googletag.html:16 das GTM-Script nur bei 'accepted' laden —
 * der Push landet dann in einem Array, das niemand ausliest. Es bleibt aber
 * eine ueberfluessige Anfrage an /shop-api/. Das zu aendern waere eine
 * Entscheidung des Shopbetreibers, keine der Migration.
 */
import { apiRequest } from './api.js';

function consented() {
  try {
    const choice = localStorage.getItem('cookiesConsent');
    return choice === 'accepted' || choice === 'declined';
  } catch {
    // localStorage kann in strengen Datenschutzeinstellungen werfen.
    return false;
  }
}

// gtmViewItem und gtmAddToCart fragen dieselben Produktdaten ab. Auf einer
// Produktseite waren das bisher zwei identische Anfragen.
const products = new Map();

function productInfo(productId) {
  if (!products.has(productId)) {
    const pending = apiRequest('gtmGetProductInfo', { product_id: productId })
      .then((data) => (data && data.product ? data.product : null))
      .catch(() => {
        products.delete(productId); // Fehlschlag nicht dauerhaft festhalten
        return null;
      });
    products.set(productId, pending);
  }
  return products.get(productId);
}

function push(event) {
  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push(event);
}

function ecommerce(product, quantity) {
  return {
    currency: product.currency || 'EUR',
    value: product.price || 0,
    items: [
      {
        item_name: product.name || '',
        item_id: product.id || '',
        price: product.price || 0,
        item_category: product.category || '',
        quantity,
      },
    ],
  };
}

export async function gtmViewItem(productId) {
  if (!consented()) return;
  const product = await productInfo(productId);
  if (product) push({ event: 'view_item', ecommerce: ecommerce(product, 1) });
}

export async function gtmAddToCart(productId, quantity) {
  if (!consented()) return;
  const product = await productInfo(productId);
  if (product) push({ event: 'add_to_cart', ecommerce: ecommerce(product, quantity || 1) });
}

/**
 * begin_checkout — beim Betreten der Kasse.
 *
 * Bekommt den bereits normalisierten Warenkorb aus core/cart-data.js, nicht
 * die Rohantwort. Das alte gtmCheckout() las product.referenceId, geliefert
 * wird aber referencedId (shop.cart.php:117) — item_id war deshalb in jedem
 * begin_checkout leer.
 */
export function gtmBeginCheckout(cart) {
  if (!consented()) return;
  if (!cart || !cart.positions.length) return;
  push({
    event: 'begin_checkout',
    ecommerce: {
      currency: cart.currency || 'EUR',
      value: cart.totals.total || 0,
      items: cart.positions.map((pos) => ({
        item_name: pos.label || '',
        item_id: pos.referencedId || '',
        price: pos.totalPrice || 0,
        quantity: pos.quantity || 1,
      })),
    },
  });
}

/**
 * E-Mail und Telefonnummer werden gehasht uebergeben (Enhanced Conversions).
 *
 * crypto.subtle gibt es nur im sicheren Kontext. Ueber http — etwa auf einer
 * lokalen Vorschau — warf das alte gtmSha256() eine TypeError mitten in
 * gtmPurchase(), womit auch das purchase-Ereignis ausfiel. Hier bleibt der
 * Hash in dem Fall einfach aus.
 */
async function sha256Hex(value) {
  if (!globalThis.crypto || !globalThis.crypto.subtle) return null;
  try {
    const bytes = new TextEncoder().encode(String(value).trim().toLowerCase());
    const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest))
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');
  } catch {
    return null;
  }
}

/**
 * purchase — auf der Rechnungsseite, mit der Zusammenfassung aus
 * getInvoiceSummary. Die Positionen holt eine eigene Aktion, weil der
 * Warenkorb zu diesem Zeitpunkt bereits geleert ist.
 */
export async function gtmPurchase(summary) {
  if (!consented() || !summary) return;

  if (summary.email) {
    const email = await sha256Hex(summary.email);
    if (email) push({ event: 'emailAvailable', email });
  }
  if (summary.phone) {
    const phone = await sha256Hex(summary.phone);
    if (phone) push({ event: 'phoneAvailable', phone });
  }

  let data;
  try {
    data = await apiRequest('gtmGetPurchasedProducts', { ar_link: summary.ar_link });
  } catch {
    return; // Ein fehlendes Tracking-Ereignis darf die Seite nicht stoeren.
  }
  if (!data || !data.purchased) return;

  push({
    event: 'purchase',
    ecommerce: {
      transaction_id: data.purchased.transaction_id || '',
      currency: data.purchased.currency || 'EUR',
      value: data.purchased.value || 0,
      shipping: data.purchased.shipping || 0,
      items: (data.products || []).map((product) => ({
        item_name: product.name || '',
        item_id: product.id || '',
        price: product.price || 0,
        item_category: product.category || '',
        quantity: product.quantity || 1,
      })),
    },
  });
}
