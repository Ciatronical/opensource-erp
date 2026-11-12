/**
 * Einstiegspunkt des Kern-Bündels (portal-widgets.js): Kundenportal.
 *
 * Anmeldung, Registrierung, Konto, Kontakt und die Freischaltung eines
 * Bestandskunden. Die Widgets liegen je in einem eigenen Stück und werden
 * erst geladen, wenn ihr Element auf der Seite steht (core/lazy.js); der
 * Einstieg selbst bleibt klein. Die Elemente des Kundenportals heißen
 * portal-*; shop-* tragen nur die Elemente der Shop-Erweiterung (shop.js).
 *
 * Der Shop (shop.js) und die Fahrzeuge (lxcars.js) sind eigene Bündel; der
 * Partial der Webseite lädt sie nach features.json dazu.
 */
import { on, PORTAL_AUTH_CHANGED } from './core/bus.js';
import { refreshContext } from './core/api.js';
import { registerBundle } from './core/bundles.js';
import { registerLazy } from './core/lazy.js';

export {
  PORTAL_AUTH_CHANGED,
  SHOP_CART_CHANGED,
  PORTAL_ACCOUNT_LOADED,
  PORTAL_ERROR,
  on,
  emit,
} from './core/bus.js';
export { apiRequest, ApiError, getContext, refreshContext } from './core/api.js';
export { hasFeature, features } from './core/features.js';

// Nach An- oder Abmeldung ist der gehaltene Sitzungskontext veraltet.
on(PORTAL_AUTH_CHANGED, () => {
  refreshContext().catch(() => {});
});

registerLazy({
  'portal-login': () => import('./components/portal-login.js'),
  'portal-register': () => import('./components/portal-register.js'),
  'portal-account-nav': () => import('./components/portal-account-nav.js'),
  'portal-account-overview': () => import('./components/portal-account-overview.js'),
  'portal-account-profile': () => import('./components/portal-account-profile.js'),
  'portal-account-payment': () => import('./components/portal-account-payment.js'),
  'portal-account-documents': () => import('./components/portal-account-documents.js'),
  'portal-account-addresses': () => import('./components/portal-account-addresses.js'),
  'portal-account-buttons': () => import('./components/portal-account-buttons.js'),
  'portal-contact': () => import('./components/portal-contact.js'),
  'portal-activate': () => import('./components/portal-activate.js'),
});

registerBundle('portal', [
  'portal-login', 'portal-register', 'portal-account-nav', 'portal-account-overview', 'portal-account-profile',
  'portal-account-payment', 'portal-account-documents', 'portal-account-addresses', 'portal-account-buttons',
  'portal-contact', 'portal-activate',
]);
