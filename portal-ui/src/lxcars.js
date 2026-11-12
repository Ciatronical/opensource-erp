/**
 * Einstiegspunkt des LxCars-Bündels (lxcars-widgets.js): die Fahrzeuge des
 * angemeldeten Kunden. Setzt auf das Kern-Bündel auf; das Widget wird erst
 * geladen, wenn sein Element auf der Seite steht (core/lazy.js).
 */
import { registerBundle } from './core/bundles.js';
import { registerLazy } from './core/lazy.js';

registerLazy({
  'portal-vehicles': () => import('./components/portal-vehicles.js'),
});

registerBundle('lxcars', ['portal-vehicles']);
