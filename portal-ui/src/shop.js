/**
 * Einstiegspunkt des Shop-Bündels (shop-widgets.js).
 *
 * Warenkorb, Produktbilder, Kasse, Rechnungsseite, Suche und Werkzeugsuche.
 * Setzt auf das Kern-Bündel auf (Sitzung, Konto, Registrierung für die
 * Gastbestellung); was beide brauchen, liegt im geteilten Stück und läuft nur
 * einmal. Jedes Widget wird erst geladen, wenn sein Element auf der Seite
 * steht (core/lazy.js).
 */
import { registerBundle } from './core/bundles.js';
import { registerLazy } from './core/lazy.js';

// Uebergangsloesung fuer den Header des mitgelieferten Themes.
import './legacy/header-cart.js';

registerLazy({
  'shop-cart': () => import('./components/shop-cart.js'),
  'shop-add-to-cart': () => import('./components/shop-add-to-cart.js'),
  'shop-product-images': () => import('./components/shop-product-images.js'),
  'shop-checkout': () => import('./components/shop-checkout.js'),
  'shop-invoice': () => import('./components/shop-invoice.js'),
  'shop-search': () => import('./components/shop-search.js'),
  'shop-search-results': () => import('./components/shop-search-results.js'),
  'shop-tool-finder': () => import('./components/shop-tool-finder.js'),
});

registerBundle('shop', [
  'shop-cart', 'shop-add-to-cart', 'shop-product-images', 'shop-checkout',
  'shop-invoice', 'shop-search', 'shop-search-results', 'shop-tool-finder',
]);
