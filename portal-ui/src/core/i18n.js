/**
 * Texte der Widgets. Die Sprache kommt aus <html lang="..."> — die alten
 * versteckten Inputs (#hugoshop-lang) entfallen damit.
 *
 * Die Kataloge liegen je Sprache in einem eigenen Stück (src/i18n/<lang>.js,
 * esbuild macht daraus chunk-<hash>.js): geladen wird nur die Sprache der
 * Seite, bei einer anderen als Deutsch zusätzlich Deutsch als Rückfall für
 * fehlende Schlüssel. Vorher lagen alle Sprachen im geteilten Stück — 41 kB,
 * fast die Hälfte davon, auf jeder Seite.
 *
 * i18nReady() stößt das Laden an; PortalElement (core/base.js) wartet darauf,
 * bevor ein Widget zum ersten Mal rendert. t() ist danach synchron.
 */

const RUECKFALL = 'de';

const kataloge = {
  de: () => import('../i18n/de.js'),
  en: () => import('../i18n/en.js'),
};

let katalog = null;
let rueckfall = null;
let laedt = null;

export function currentLang() {
  const lang = (document.documentElement.lang || RUECKFALL).slice(0, 2).toLowerCase();
  return kataloge[lang] ? lang : RUECKFALL;
}


export function i18nReady() {
  if (laedt) return laedt;
  const lang = currentLang();
  laedt = Promise.all([kataloge[lang](), lang === RUECKFALL ? null : kataloge[RUECKFALL]()])
    .then(([eigener, deutsch]) => {
      katalog = eigener.default;
      rueckfall = deutsch ? deutsch.default : eigener.default;
    })
    .catch((fehler) => {
      console.error('[portal-ui] Texte konnten nicht geladen werden', fehler);
      katalog = {};
      rueckfall = {};
    });
  return laedt;
}

export function t(key) {
  if (!katalog) return key; // vor i18nReady() — rendert kein Widget
  if (key in katalog) return katalog[key];
  if (key in rueckfall) return rueckfall[key];
  return key; // unbekannter Backend-Code: lieber roh anzeigen als verschlucken
}
