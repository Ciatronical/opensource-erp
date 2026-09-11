import { LitElement, css } from 'lit';
import { themeReady, adoptLayers, classesFor, preset } from './theme.js';
import { defaultStyles } from './defaults.js';

// Ladevorgang sofort beim Import anstossen, nicht erst beim ersten Widget.
const themeStyles = themeReady();

// Die Default-Schicht als einmalig erzeugtes, geteiltes Stylesheet.
const defaultsLayer = defaultStyles.styleSheet
  ? { sheet: defaultStyles.styleSheet, text: '' }
  : { sheet: null, text: defaultStyles.cssText };

/**
 * Basisklasse aller Shop-Widgets.
 *
 * Regeln, die hier durchgesetzt werden:
 *  - Shadow-DOM (Lit-Default, offen) — IDs sind damit lokal zur Komponente.
 *  - :host bekommt display:block. Ohne das kollabiert die Komponente im
 *    umgebenden Layout, weil Custom Elements per Default display:inline sind.
 *  - box-sizing wird im ShadowRoot neu gesetzt; die globale Regel eines
 *    CSS-Frameworks gilt drinnen nicht.
 *  - Aeussere Layout-Klassen gehoeren an den Host (Light DOM), NICHT hier
 *    hinein. Die Shadow-Grenze darf nie zwischen Grid-Container und Grid-Kind
 *    liegen.
 */
export class ShopElement extends LitElement {
  static baseStyles = css`
    :host {
      display: block;
    }
    :host([hidden]) {
      display: none;
    }
    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    /* Ersetzt das verschachtelte row/col-Muster der alten Shortcodes:
       gestapelte Felder mit Abstand, auf eine maximale Breite begrenzt.
       Bewusst frameworkfrei — CSS Grid braucht kein Framework. */
    .fields {
      display: grid;
      gap: var(--shop-field-gap, 1.5rem);
      max-width: var(--shop-form-width, 32rem);
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: center;
      margin-top: var(--shop-field-gap, 1.5rem);
    }
  `;

  #themed = false;

  async connectedCallback() {
    super.connectedCallback();
    if (this.#themed) return;
    // Reihenfolge ist die Kaskade: defaults < Theme < Komponenten-CSS.
    adoptLayers(this.renderRoot, [defaultsLayer, await themeStyles]);
    this.#themed = true;
    this.requestUpdate();
  }

  // Erst rendern, wenn das Theme-Stylesheet im ShadowRoot liegt — sonst
  // blitzt ungestyltes Markup auf, und this.cls() lieferte noch die Klassen
  // des Default-Presets.
  shouldUpdate(changed) {
    return this.#themed && super.shouldUpdate(changed);
  }

  /**
   * Klassen für eine Rolle, z.B. this.cls('input').
   * Liefert immer die framework-unabhängige shop-*-Klasse plus die Klassen
   * des aktiven Presets. Templates schreiben NIE Framework-Klassen direkt.
   */
  cls(role) {
    return classesFor(role);
  }

  /** Name des aktiven Presets, z.B. für bedingtes Markup. */
  get themeName() {
    return preset().name;
  }

  /** Ersetzt document.getElementById() — sucht im eigenen ShadowRoot. */
  $(id) {
    return this.renderRoot ? this.renderRoot.getElementById(id) : null;
  }
}
