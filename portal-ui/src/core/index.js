/**
 * Der Kern als eine Datei (chunk-<hash>.js im Paket): alles unter src/core/
 * samt Lit. Jedes Widget importiert aus dem Kern, der Einstieg auch.
 *
 * scripts/build.mjs baut den Kern aus dieser Datei und schreibt in den
 * Widgets und im Einstieg jeden Import aus src/core/ und aus 'lit' auf die
 * fertige Kern-Datei um — deshalb muss hier alles stehen, was die Widgets
 * importieren, unter seinem eigenen Namen (export *), auch Lits Exporte.
 *
 * Vorher teilte esbuild den Kern selbst auf: je nachdem, welche Widgets ein
 * Modul brauchten, entstanden sieben Stücke (API, Bus, Features, ein Helfer
 * von 111 Byte, Lit, …), auf jeder Seite sieben Requests für zusammen 40 kB.
 * Ein Modul, das mehrere Widgets teilen und das Zustand hält (account-data,
 * cart-data, checkout-data), darf nur einmal auf der Seite sein — darum
 * liegt alles aus src/core/ im Kern, nicht je Widget eine Kopie.
 *
 * Die Kopfknöpfe (<portal-account-buttons>, 5 kB) liegen ebenfalls im Kern:
 * sie stehen auf praktisch jeder Seite einer Webseite mit Kundenportal, und
 * so lädt eine Seite ohne weitere Widgets genau eine Datei.
 */
export * from 'lit';
export { repeat } from 'lit/directives/repeat.js';

export * from './account-base.js';
export * from './account-data.js';
export * from './api.js';
export * from './base.js';
export * from './bundles.js';
export * from './bus.js';
export * from './cart-data.js';
export * from './checkout-data.js';
export * from './dates.js';
export * from './defaults.js';
export * from './features.js';
export * from './gtm.js';
export * from './i18n.js';
export * from './lazy.js';
export * from './links.js';
export * from './money.js';
export * from './password-field.js';
export * from './paths.js';
export * from './presets.js';
export * from './shipping-options.js';
export * from './theme.js';

import '../components/portal-account-buttons.js';
