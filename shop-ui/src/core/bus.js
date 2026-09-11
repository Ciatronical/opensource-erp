/**
 * Ereignisse zwischen Widgets.
 *
 * Ueber Shadow-Grenzen hinweg kann kein Widget mehr in das Markup eines
 * anderen greifen (frueher schrieb cart.js direkt in #cart-count im Header).
 * Zustandswechsel werden deshalb auf document gemeldet, interessierte
 * Widgets hoeren zu.
 */

export const SHOP_AUTH_CHANGED = 'shop:auth-changed';
export const SHOP_CART_CHANGED = 'shop:cart-changed';
export const SHOP_ERROR = 'shop:error';

/**
 * Ein Konto-Widget hat die Stammdaten geladen. Traegt Name und Anrede zur
 * Seitenleiste (<shop-account-nav>), damit die Begruessung dort keine eigene
 * Anfrage braucht — frueher schrieb jede der vier Konto-Funktionen selbst in
 * #nav-box-greeting.
 */
export const SHOP_ACCOUNT_LOADED = 'shop:account-loaded';

export function emit(type, detail = {}) {
  document.dispatchEvent(new CustomEvent(type, { detail, bubbles: true, composed: true }));
}

export function on(type, handler) {
  document.addEventListener(type, handler);
  return () => document.removeEventListener(type, handler);
}
