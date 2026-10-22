/**
 * Alle Widgets in einem — für Größenanalyse und Werkzeuge, die einen
 * einzelnen Einstieg erwarten (npm run size).
 *
 * Die Webseiten laden nicht diese Datei, sondern die drei Bündel aus
 * portal.js, shop.js und lxcars.js (scripts/build.mjs).
 */
export * from './portal.js';
import './shop.js';
import './lxcars.js';
