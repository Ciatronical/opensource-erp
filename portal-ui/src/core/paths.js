/**
 * Pfade der Shop-Seiten und des Backend-Einstiegs.
 *
 * Die Widgets kennen keine festen Adressen. Was sie verlinken (Warenkorb,
 * Kasse, Anmeldung, Konto-Seiten …), kommt aus drei Quellen, in dieser
 * Reihenfolge:
 *
 *   1. das Attribut am Element (`cart-url="…"`) — gesetzt vom Shortcode,
 *      wenn die Seite es angibt;
 *   2. die Site-Konfiguration (`params.portalui.paths`), vom Partial
 *      portal-ui-assets.html als JSON im Attribut data-portal-ui-paths des
 *      Bundle-Scripts abgelegt — oder window.PortalUIConfig.paths;
 *   3. die Vorgabe unten, angehängt an den Basispfad des Shops.
 *
 * Der Basispfad erlaubt einen Shop unterhalb einer bestehenden Webseite
 * (https://werkstatt.example/shop/warenkorb/): `base` ist dann "/shop/", und
 * alle Vorgaben hängen daran. Liegt die ganze Hugo-Seite unter einem Pfad
 * (baseURL mit Verzeichnis), setzt der Partial `base` und `site` von selbst.
 *
 * `site` ist der Pfad der Hugo-Seite selbst — dort liegen static/ und damit
 * der Weiterleiter /portal-api/ und die Bilder; er ist vom Basispfad des Shops
 * unabhängig (Shop unter /shop/, Weiterleiter trotzdem an der Wurzel).
 */

/** Seiten relativ zum Basispfad des Shops. Schlüssel klein, wie Hugo Params ablegt. */
const DEFAULTS = {
  home: '',
  cart: 'warenkorb/',
  checkout: 'kasse/',
  invoice: 'rechnung/',
  paymentcanceled: 'bezahlung-abgebrochen/',
  login: 'login/',
  logout: 'logout/',
  register: 'registrieren/',
  registered: 'registriert/',
  account: 'persönliche-daten/',
  profile: 'persönliches-profil/',
  addresses: 'adressen/',
  orders: 'bestellungen/',
  payment: 'zahlungsarten/',
  categories: 'kategorien/',
  searchresults: 'suchergebnisse/',
  contactthanks: 'kontakt-danke/',
  privacy: 'datenschutz/',
  // Kundenportal: Zugang für Bestandskunden, Fahrzeuge (LxCars)
  activate: 'freischalten/',
  // Belege des Kontos (Rechnungen, Aufträge, Angebote, Lieferscheine, Reklamationen)
  documents: 'belege/',
  vehicles: 'fahrzeuge/',
};

let config = null;

function withSlash(path) {
  const value = String(path || '').trim() || '/';
  return value.endsWith('/') ? value : `${value}/`;
}

function parse(raw) {
  if (!raw) return null;
  if (typeof raw === 'object') return raw;
  try {
    return JSON.parse(raw);
  } catch {
    console.warn('[portal-ui] data-portal-ui-paths ist kein gueltiges JSON, wird ignoriert');
    return null;
  }
}

function read() {
  if (config) return config;
  const global = typeof window !== 'undefined' && window.PortalUIConfig ? window.PortalUIConfig.paths : null;
  const tag = typeof document !== 'undefined' ? document.querySelector('script[data-portal-ui-paths]') : null;
  const raw = parse(global) || parse(tag && tag.dataset.portalUiPaths) || {};
  const site = withSlash(raw.site || raw.base || '/');
  const resolved = {
    base: withSlash(raw.base || site),
    site,
    api: raw.api || `${site}portal-api/`,
    pages: raw.pages && typeof raw.pages === 'object' ? raw.pages : {},
  };
  // Ohne Konfiguration im DOM nicht merken: das Bundle kann per dynamischem
  // Import vor dem script-Tag laufen — dann gilt beim naechsten Aufruf die
  // Konfiguration, nicht die Vorgabe.
  if (tag || global || (typeof document !== 'undefined' && document.readyState !== 'loading')) {
    config = resolved;
  }
  return resolved;
}

/**
 * Adresse einer Shop-Seite.
 *
 * @param {string} key Schluessel aus DEFAULTS, z.B. 'cart'
 * @param {string} fragment Anker, z.B. '#focus' — der Container des Themes,
 *                          an den der Browser nach dem Sprung scrollt
 */
export function shopPath(key, fragment = '') {
  const c = read();
  const page = c.pages[key] || `${c.base}${DEFAULTS[key] || ''}`;
  return `${page}${fragment}`;
}

/** Adresse des Weiterleiters zu OpensourceERP (portal-api/). */
export function apiUrl() {
  return read().api;
}

/** Datei unter static/ der Hugo-Seite, z.B. sitePath('images/paypal-de.png'). */
export function sitePath(relative) {
  return `${read().site}${String(relative || '').replace(/^\/+/, '')}`;
}
