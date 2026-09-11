/**
 * Presets — die Übersetzung von Rollen in Klassennamen des jeweiligen
 * CSS-Frameworks.
 *
 * Die Komponenten schreiben NIE einen Framework-Klassennamen direkt in ihr
 * Template, sondern fragen nach einer Rolle: this.cls('input'). Damit ist
 * austauschbar, was sonst über 15 Komponenten verteilt fest verdrahtet wäre.
 *
 * Zusätzlich vergibt jede Rolle immer eine eigene, framework-unabhängige
 * Klasse (shop-input, shop-button-primary, …). Ein eigenes Stylesheet kann
 * daran andocken, ohne dass ein Preset gepflegt werden muss.
 *
 * `detect` findet das Stylesheet des Frameworks auf der Seite, wenn es nicht
 * ausdrücklich konfiguriert ist. Die Datei liegt dann bereits im HTTP-Cache,
 * weil das Dokument sie ohnehin geladen hat.
 *
 * ZWEI ARTEN VON FRAMEWORKS
 * -------------------------
 * Bootstrap und Pure sind semantisch: eine Klasse trägt das Aussehen einer
 * Komponente. Die defaults-Schicht (core/defaults.js) füllt dort Lücken.
 *
 * Tailwind ist ein Utility-Framework: das Aussehen entsteht erst aus einer
 * Kette von Einzelklassen. Zwei Folgen:
 *  - Die Werte hier sind lang und enthalten Farben; sie gehören pro Shop
 *    angepasst (params.shopui.classes, siehe README).
 *  - Tailwinds Preflight setzt input/button im ShadowRoot zurück und
 *    überschreibt damit die defaults-Schicht. Das Tailwind-Preset muss
 *    deshalb VOLLSTÄNDIG sein — es kann sich nicht auf Defaults verlassen.
 */

const EMPTY = {
  form: '',
  heading: '',
  label: '',
  input: '',
  select: '',
  check: '',
  button: '',
  buttonPrimary: '',
  buttonSecondary: '',
  alertError: '',
  alertSuccess: '',
  alertInfo: '',
  link: '',
  muted: '',
};

export const PRESETS = {
  bootstrap5: {
    name: 'bootstrap5',
    detect: 'link[rel~="stylesheet"][href*="bootstrap"]',
    classes: {
      ...EMPTY,
      heading: 'h4',
      label: 'form-label',
      input: 'form-control',
      select: 'form-select',
      check: 'form-check-input',
      button: 'btn btn-outline-dark',
      buttonPrimary: 'btn btn-primary',
      buttonSecondary: 'btn btn-secondary',
      alertError: 'alert alert-danger',
      alertSuccess: 'alert alert-success',
      alertInfo: 'alert alert-primary',
      muted: 'text-secondary',
    },
  },

  pure: {
    name: 'pure',
    detect: 'link[rel~="stylesheet"][href*="pure"]',
    classes: {
      ...EMPTY,
      form: 'pure-form pure-form-stacked',
      input: 'pure-input-1',
      select: 'pure-input-1',
      button: 'pure-button',
      buttonPrimary: 'pure-button pure-button-primary',
      buttonSecondary: 'pure-button',
    },
  },

  /**
   * Bewusst konservative Utilities, die in Tailwind v3 und v4 gleichermaßen
   * existieren (kein ring-*, kein outline-*). Farben sind Platzhalter und
   * gehören pro Shop überschrieben.
   */
  tailwind: {
    name: 'tailwind',
    detect: 'link[rel~="stylesheet"][href*="tailwind"]',
    classes: {
      ...EMPTY,
      heading: 'text-xl font-semibold',
      label: 'block mb-1',
      input:
        'block w-full rounded border border-gray-300 px-3 py-2 text-base focus:border-blue-500',
      select:
        'block w-full rounded border border-gray-300 px-3 py-2 text-base focus:border-blue-500',
      check: 'rounded border-gray-300',
      button:
        'inline-block rounded px-4 py-2 bg-gray-200 text-gray-900 hover:bg-gray-300 disabled:opacity-60',
      buttonPrimary:
        'inline-block rounded px-4 py-2 bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-60',
      buttonSecondary:
        'inline-block rounded px-4 py-2 bg-gray-700 text-white hover:bg-gray-800 disabled:opacity-60',
      alertError: 'rounded border border-red-300 bg-red-50 px-4 py-3 text-red-800',
      alertSuccess: 'rounded border border-green-300 bg-green-50 px-4 py-3 text-green-800',
      alertInfo: 'rounded border border-sky-300 bg-sky-50 px-4 py-3 text-sky-800',
      link: 'underline',
      muted: 'text-gray-500',
    },
  },

  /**
   * Eigenes Stylesheet: keine Framework-Klassen, nur die shop-*-Klassen.
   * Das Stylesheet wird über die Konfiguration angegeben und in jeden
   * ShadowRoot adoptiert.
   */
  custom: {
    name: 'custom',
    detect: null,
    classes: { ...EMPTY },
  },

  /**
   * Gar kein externes Stylesheet — die Komponenten stehen allein mit ihrem
   * eigenen CSS (core/defaults.js + static styles).
   */
  none: {
    name: 'none',
    detect: null,
    classes: { ...EMPTY },
  },
};

export const DEFAULT_PRESET = 'bootstrap5';

export const ROLES = Object.keys(EMPTY);

export function getPreset(name) {
  return PRESETS[name] || PRESETS[DEFAULT_PRESET];
}

/**
 * Preset mit shop-spezifischen Überschreibungen kombinieren.
 * Unbekannte Rollen werden verworfen, damit ein Tippfehler in der
 * Konfiguration nicht still ins Markup durchschlägt.
 */
export function withOverrides(preset, overrides) {
  if (!overrides) return preset;
  const classes = { ...preset.classes };
  const unknown = [];
  for (const [role, value] of Object.entries(overrides)) {
    if (role in classes) classes[role] = String(value);
    else unknown.push(role);
  }
  if (unknown.length && typeof console !== 'undefined') {
    console.warn('[shop-ui] unbekannte Rollen in shopui.classes:', unknown.join(', '));
  }
  return { ...preset, classes };
}
