/**
 * Theming der Widgets.
 *
 * Aufgaben:
 *  1. herausfinden, welches Preset gilt und welches Stylesheet dazugehört
 *  2. dieses Stylesheet genau einmal laden und als geteilte CSSStyleSheet-
 *     Instanz in jeden ShadowRoot adoptieren
 *  3. den Komponenten die Klassen-Zuordnung des Presets bereitstellen
 *
 * Konfiguration (in dieser Reihenfolge):
 *  - window.ShopUIConfig = { theme, stylesheet }   (vor dem Bundle gesetzt)
 *  - data-Attribute am script-Tag des Bundles      (Hugo-Partial)
 *  - Autoerkennung über das `detect`-Selektor des Presets
 *
 * Klassenselektoren überqueren die Shadow-Grenze nicht — deshalb muss das
 * Stylesheet in JEDEN ShadowRoot hinein, nicht nur ins Dokument.
 */

import { getPreset, withOverrides, DEFAULT_PRESET, PRESETS } from './presets.js';

let pending = null;
let current = PRESETS[DEFAULT_PRESET];

const supportsConstructable =
  typeof CSSStyleSheet !== 'undefined' &&
  'replaceSync' in CSSStyleSheet.prototype &&
  'adoptedStyleSheets' in Document.prototype;

function domReady() {
  // Modul-Skripte laufen zwar erst nach dem Parsen, aber das Bundle könnte
  // auch per dynamischem Import früher geladen werden. Dann wären das
  // script-Tag bzw. der <link> noch nicht im DOM.
  if (document.readyState !== 'loading') return Promise.resolve();
  return new Promise((resolve) =>
    document.addEventListener('DOMContentLoaded', resolve, { once: true })
  );
}

function parseClasses(raw) {
  if (!raw) return null;
  if (typeof raw === 'object') return raw;
  try {
    return JSON.parse(raw);
  } catch {
    console.warn('[shop-ui] data-shop-ui-classes ist kein gueltiges JSON, wird ignoriert');
    return null;
  }
}

/**
 * Preset-Umschaltung per URL — nur zum Testen und nur, wenn die Site sie
 * ausdruecklich freigibt (params.shopui.allowUrlTheme). Sonst koennte jeder
 * Besucher das Aussehen des Shops ueber einen Link veraendern.
 *
 *   /login/?shop-ui-theme=none
 *   /login/?shop-ui-theme=custom&shop-ui-stylesheet=/css/mein-shop-ui.css
 */
function readUrlOverrides(allowed) {
  if (!allowed || typeof location === 'undefined') return {};
  const params = new URLSearchParams(location.search);
  const theme = params.get('shop-ui-theme') || '';
  const stylesheet = params.get('shop-ui-stylesheet') || '';
  const overrides = {};
  if (theme && theme in PRESETS) overrides.theme = theme;
  if (stylesheet) {
    // Nur same-origin: ein fremdes Stylesheet im ShadowRoot koennte ueber
    // Attributselektoren Formularinhalte nach aussen tragen.
    const url = new URL(stylesheet, document.baseURI);
    if (url.origin === location.origin) overrides.stylesheet = url.href;
    else console.warn('[shop-ui] shop-ui-stylesheet ignoriert: nicht same-origin');
  }
  return overrides;
}

function readConfig() {
  const global = (typeof window !== 'undefined' && window.ShopUIConfig) || {};
  const tag = document.querySelector('script[data-shop-ui-theme]');
  const url = readUrlOverrides(tag && tag.dataset.shopUiAllowUrl === 'true');
  return {
    theme: url.theme || global.theme || (tag && tag.dataset.shopUiTheme) || DEFAULT_PRESET,
    stylesheet:
      url.stylesheet || global.stylesheet || (tag && tag.dataset.shopUiStylesheet) || '',
    classes:
      parseClasses(global.classes) || parseClasses(tag && tag.dataset.shopUiClasses) || null,
  };
}

function resolveHref(preset, configured) {
  if (configured) return new URL(configured, document.baseURI).href;
  if (!preset.detect) return null;
  const link = document.querySelector(preset.detect);
  return link ? link.href : null;
}

/**
 * @returns {Promise<{preset: object, sheet: CSSStyleSheet|null, text: string}>}
 */
export function themeReady() {
  if (pending) return pending;

  pending = domReady()
    .then(() => {
      const config = readConfig();
      const preset = withOverrides(getPreset(config.theme), config.classes);
      current = preset;

      const href = resolveHref(preset, config.stylesheet);
      if (!href) return { preset, text: '' };

      return fetch(href, { credentials: 'same-origin' })
        .then((response) => (response.ok ? response.text() : ''))
        .then((text) => ({ preset, text }));
    })
    .then(({ preset, text }) => {
      if (!text) return { preset, sheet: null, text: '' };
      if (!supportsConstructable) return { preset, sheet: null, text };
      const sheet = new CSSStyleSheet();
      sheet.replaceSync(text); // Achtung: @import-Regeln werden dabei verworfen
      return { preset, sheet, text };
    })
    .catch(() => ({ preset: current, sheet: null, text: '' }));

  return pending;
}

/**
 * Legt die uebergebenen Schichten VOR das komponenteneigene CSS in den
 * ShadowRoot — in der angegebenen Reihenfolge. Erwartet wird
 * [defaults, theme]; das Komponenten-CSS von Lit steht bereits im Root und
 * bleibt damit die letzte, gewinnende Schicht.
 */
export function adoptLayers(root, layers) {
  const sheets = [];
  const inline = [];

  for (const layer of layers) {
    if (!layer) continue;
    if (layer.sheet) {
      if (!root.adoptedStyleSheets.includes(layer.sheet)) sheets.push(layer.sheet);
    } else if (layer.text) {
      inline.push(layer.text);
    }
  }

  if (sheets.length) {
    root.adoptedStyleSheets = [...sheets, ...root.adoptedStyleSheets];
  }

  // Fallback fuer Browser ohne konstruierbare Stylesheets: rueckwaerts
  // einfuegen, damit die Reihenfolge der Schichten erhalten bleibt.
  for (const text of inline.reverse()) {
    const style = document.createElement('style');
    style.textContent = text;
    root.insertBefore(style, root.firstChild);
  }
}

/** Aktuell geltendes Preset (nach themeReady() aufgelöst). */
export function preset() {
  return current;
}

const KEBAB = /[A-Z]/g;

/**
 * Klassen für eine Rolle: immer die framework-unabhängige shop-*-Klasse,
 * dahinter die Klassen des Presets (falls es welche vergibt).
 */
export function classesFor(role) {
  const semantic = 'shop-' + role.replace(KEBAB, (c) => '-' + c.toLowerCase());
  const framework = current.classes[role];
  return framework ? semantic + ' ' + framework : semantic;
}
