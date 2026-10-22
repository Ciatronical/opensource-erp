/**
 * Marker fuer die Diagnose im Browser ("laeuft das Bundle ueberhaupt?").
 *
 * Mehrere Buendel (Kern, Shop, LxCars) teilen sich window.PortalUI: jedes traegt
 * seine Elemente ein, keines ueberschreibt das andere.
 */
export const VERSION = '0.10.0';

export function registerBundle(name, elements) {
  const bisher = (typeof window !== 'undefined' && window.PortalUI) || { version: VERSION, bundles: {}, elements: [] };
  const bundles = { ...bisher.bundles, [name]: elements };
  const alle = [...new Set([...bisher.elements, ...elements])];
  window.PortalUI = Object.freeze({ version: VERSION, bundles: Object.freeze(bundles), elements: Object.freeze(alle) });
}
