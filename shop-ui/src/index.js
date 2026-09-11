/**
 * Einstiegspunkt des Widget-Bundles.
 *
 * Jede Komponente registriert sich beim Import selbst per
 * customElements.define(). Neue Widgets hier eintragen.
 */
import './components/shop-login.js';
import './components/shop-register.js';
import './components/shop-cart.js';
import './components/shop-add-to-cart.js';
import './components/shop-account-nav.js';
import './components/shop-account-overview.js';
import './components/shop-account-profile.js';
import './components/shop-account-payment.js';
import './components/shop-account-orders.js';
import './components/shop-account-addresses.js';
import './components/shop-checkout.js';
import './components/shop-invoice.js';
import './components/shop-account-buttons.js';
import './components/shop-search.js';
import './components/shop-search-results.js';
import './components/shop-contact.js';

// Uebergangsloesung fuer den Header des mitgelieferten Themes.
import './legacy/header-cart.js';

import { on, SHOP_AUTH_CHANGED } from './core/bus.js';
import { refreshContext } from './core/api.js';

export {
  SHOP_AUTH_CHANGED,
  SHOP_CART_CHANGED,
  SHOP_ACCOUNT_LOADED,
  SHOP_ERROR,
  on,
  emit,
} from './core/bus.js';
export { apiRequest, ApiError, getContext, refreshContext } from './core/api.js';

// Nach An- oder Abmeldung ist der gehaltene Sitzungskontext veraltet.
on(SHOP_AUTH_CHANGED, () => {
  refreshContext().catch(() => {});
});

// Kleiner Marker fuer Diagnose im Browser ("laeuft das Bundle ueberhaupt?").
window.ShopUI = Object.freeze({
  version: '0.9.0',
  elements: [
    'shop-login',
    'shop-register',
    'shop-cart',
    'shop-add-to-cart',
    'shop-account-nav',
    'shop-account-overview',
    'shop-account-profile',
    'shop-account-payment',
    'shop-account-orders',
    'shop-account-addresses',
    'shop-checkout',
    'shop-invoice',
    'shop-account-buttons',
    'shop-search',
    'shop-search-results',
    'shop-contact',
  ],
});
