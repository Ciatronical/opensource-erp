/**
 * Einstiegspunkt des Kern-Bündels (portal-widgets.js): Kundenportal.
 *
 * Anmeldung, Registrierung, Konto, Kontakt und die Freischaltung eines
 * Bestandskunden. Jede Komponente registriert sich beim Import selbst per
 * customElements.define(). Die Elemente des Kundenportals heißen portal-*;
 * shop-* tragen nur die Elemente der Shop-Erweiterung (shop.js).
 *
 * Der Shop (shop.js) und die Fahrzeuge (lxcars.js) sind eigene Bündel; der
 * Partial der Webseite lädt sie nach features.json dazu.
 */
import { PortalLogin } from './components/portal-login.js';
import { PortalRegister } from './components/portal-register.js';
import { PortalAccountNav } from './components/portal-account-nav.js';
import { PortalAccountOverview } from './components/portal-account-overview.js';
import { PortalAccountProfile } from './components/portal-account-profile.js';
import { PortalAccountPayment } from './components/portal-account-payment.js';
import { PortalAccountOrders } from './components/portal-account-orders.js';
import { PortalAccountAddresses } from './components/portal-account-addresses.js';
import { PortalAccountButtons } from './components/portal-account-buttons.js';
import { PortalContact } from './components/portal-contact.js';
import './components/portal-activate.js';

import { on, PORTAL_AUTH_CHANGED } from './core/bus.js';
import { refreshContext } from './core/api.js';
import { registerBundle } from './core/bundles.js';

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


registerBundle('portal', [
  'portal-login', 'portal-register', 'portal-account-nav', 'portal-account-overview', 'portal-account-profile',
  'portal-account-payment', 'portal-account-orders', 'portal-account-addresses', 'portal-account-buttons',
  'portal-contact', 'portal-activate',
  'portal-login', 'portal-register', 'portal-account-nav', 'portal-account-overview', 'portal-account-profile',
  'portal-account-payment', 'portal-account-orders', 'portal-account-addresses', 'portal-account-buttons',
  'portal-contact',
]);
