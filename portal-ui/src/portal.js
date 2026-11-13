/**
 * Einstiegspunkt des Bündels (portal-widgets.js): Kundenportal, Shop und
 * LxCars in einem.
 *
 * Der Einstieg trägt nur ein, welches Stück zu welchem Element gehört
 * (core/lazy.js); ein Widget wird erst geladen, wenn sein Element auf der
 * Seite steht. Deshalb ist ein gemeinsamer Einstieg für alle Erweiterungen
 * richtig: Was eine Webseite nicht hat, kostet sie nichts, und sie lädt eine
 * Datei statt dreier (vorher portal-widgets.js, shop-widgets.js und
 * lxcars-widgets.js je nach features.json — drei Requests für dreimal 1 kB).
 * Ob Shop oder LxCars auf der Webseite laufen, sagt features.json im Paket
 * (core/features.js): die Widgets zeigen danach Warenkorb oder Fahrzeuge.
 *
 * Die Elemente des Kundenportals heißen portal-*, shop-* tragen nur die
 * Elemente der Shop-Erweiterung. Elemente, die ein Widget in seinem eigenen
 * Template benutzt (die Kasse den Warenkorb und die Registrierung, die
 * Werkzeugsuche den Warenkorb-Knopf), importiert es weiterhin selbst statisch.
 */
import { on, PORTAL_AUTH_CHANGED } from './core/bus.js';
import { refreshContext } from './core/api.js';
import { registerBundle } from './core/bundles.js';
import { hasFeature } from './core/features.js';
import { registerLazy } from './core/lazy.js';

// Übergangslösung für den Warenkorbzähler im Kopf des mitgelieferten Themes.
// Statisch, nicht hinter dem Shop-Schalter als import(): ein dynamischer
// Import hier wäre ein Stück, das der Browser erst nach dem Einstieg entdeckt
// (eine Stufe mehr in der kritischen Anfragekette) — und ohne das Markup des
// Themes tut das Modul ohnehin nichts.
import './legacy/header-cart.js';

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

const portal = {
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
};
const shop = {
  'shop-cart': () => import('./components/shop-cart.js'),
  'shop-add-to-cart': () => import('./components/shop-add-to-cart.js'),
  'shop-product-images': () => import('./components/shop-product-images.js'),
  'shop-checkout': () => import('./components/shop-checkout.js'),
  'shop-invoice': () => import('./components/shop-invoice.js'),
  'shop-search': () => import('./components/shop-search.js'),
  'shop-search-results': () => import('./components/shop-search-results.js'),
  'shop-tool-finder': () => import('./components/shop-tool-finder.js'),
};
const lxcars = {
  'portal-vehicles': () => import('./components/portal-vehicles.js'),
};

registerLazy({ ...portal, ...shop, ...lxcars });

registerBundle('portal', Object.keys(portal));
if (hasFeature('shop')) registerBundle('shop', Object.keys(shop));
if (hasFeature('lxcars')) registerBundle('lxcars', Object.keys(lxcars));
