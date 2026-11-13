/**
 * Laden je Element: Ein Widget kommt erst dann über die Leitung, wenn sein
 * Element auf der Seite steht.
 *
 * Der Einstieg (portal.js) trägt hier nur ein, welche Datei zu welchem
 * Element gehört — als dynamischer Import, den scripts/build.mjs auf die
 * Datei des Widgets (chunk-<hash>.js) umschreibt. Beim Start wird das
 * Dokument einmal abgesucht; danach beobachtet ein MutationObserver, was
 * später dazukommt (die Schnellsuche einer Webseite fügt etwa fertige
 * Trefferlisten mit <shop-add-to-cart> ein).
 *
 * Vorher luden die Einstiege alle Widgets auf jeder Seite — auf einem
 * Blogbeitrag 220 kB JavaScript für einen Kopf mit drei Knöpfen (Lighthouse:
 * „lange Hauptthread-Aufgaben“). Jetzt sind es dort der Kern und das eine
 * Widget.
 *
 * Elemente, die ein Widget in seinem eigenen Template benutzt (die Kasse den
 * Warenkorb und die Registrierung, die Werkzeugsuche den Warenkorb-Knopf),
 * importiert es weiterhin selbst statisch; sie brauchen keinen Eintrag.
 */

const lader = new Map();
let beobachter = null;

function laden(tag) {
  const importieren = lader.get(tag);
  if (!importieren) return;
  lader.delete(tag);
  importieren().catch((fehler) => {
    console.error('[portal-ui] ' + tag + ' konnte nicht geladen werden', fehler);
  });
  if (!lader.size && beobachter) {
    beobachter.disconnect();
    beobachter = null;
  }
}

function absuchen(wurzel) {
  if (!lader.size) return;
  for (const tag of [...lader.keys()]) {
    if (wurzel.localName === tag || (wurzel.querySelector && wurzel.querySelector(tag))) laden(tag);
  }
}

function beobachten() {
  if (beobachter || !lader.size) return;
  beobachter = new MutationObserver((aenderungen) => {
    for (const aenderung of aenderungen) {
      for (const knoten of aenderung.addedNodes) {
        if (knoten.nodeType === 1) absuchen(knoten);
      }
    }
  });
  beobachter.observe(document.documentElement, { childList: true, subtree: true });
}

/**
 * @param {Record<string, () => Promise<unknown>>} elemente Tag → dynamischer Import
 */
export function registerLazy(elemente) {
  for (const [tag, importieren] of Object.entries(elemente)) {
    if (!customElements.get(tag)) lader.set(tag, importieren);
  }
  const start = () => {
    absuchen(document.documentElement);
    beobachten();
  };
  // Modul-Skripte laufen nach dem Parsen; nur ein früher dynamischer Import
  // des Bündels träfe noch auf ein halbes Dokument.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}
