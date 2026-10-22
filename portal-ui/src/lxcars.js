/**
 * Einstiegspunkt des LxCars-Bündels (lxcars-widgets.js): die Fahrzeuge des
 * angemeldeten Kunden. Setzt auf das Kern-Bündel auf.
 */
import './components/portal-vehicles.js';

import { registerBundle } from './core/bundles.js';

registerBundle('lxcars', ['portal-vehicles']);
