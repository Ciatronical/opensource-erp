/**
 * Einstiegspunkt des Shop-Bündels (shop-widgets.js).
 *
 * Warenkorb, Kasse, Rechnungsseite, Suche und Werkzeugsuche. Setzt auf das
 * Kern-Bündel auf (Sitzung, Konto, Registrierung für die Gastbestellung);
 * was beide brauchen, liegt im geteilten Stück und läuft nur einmal.
 */
import './components/shop-cart.js';
import './components/shop-add-to-cart.js';
import './components/shop-checkout.js';
import './components/shop-invoice.js';
import './components/shop-search.js';
import './components/shop-search-results.js';
import './components/shop-tool-finder.js';

// Uebergangsloesung fuer den Header des mitgelieferten Themes.
import './legacy/header-cart.js';

import { registerBundle } from './core/bundles.js';

registerBundle('shop', [
  'shop-cart', 'shop-add-to-cart', 'shop-checkout', 'shop-invoice',
  'shop-search', 'shop-search-results', 'shop-tool-finder',
]);
