/**
 * Merkmale der Webseite: was von OpensourceERP auf ihr laeuft.
 *
 * Der Partial portal-ui-assets.html liest features.json aus dem Paket und legt
 * es als JSON im Attribut data-portal-ui-features des ersten Bundle-Scripts ab
 * ({"portal": true, "shop": true, "lxcars": false}). Widgets fragen damit,
 * ob sie Verweise auf Shop oder Fahrzeuge zeigen sollen — ohne eine Anfrage
 * an das Backend.
 */

let gelesen = null;

function parse(raw) {
  if (!raw) return null;
  if (typeof raw === 'object') return raw;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function features() {
  if (gelesen) return gelesen;
  const global = typeof window !== 'undefined' && window.PortalUIConfig ? window.PortalUIConfig.features : null;
  const tag = typeof document !== 'undefined' ? document.querySelector('script[data-portal-ui-features]') : null;
  const werte = parse(global) || parse(tag && tag.dataset.portalUiFeatures) || { portal: true };
  if (tag || global) gelesen = werte;
  return werte;
}

/** Ist ein Merkmal da? Ohne Angabe gilt nur der Kern. */
export function hasFeature(name) {
  return Boolean(features()[name]);
}
