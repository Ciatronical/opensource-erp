import { html, css } from 'lit';
import { t } from './i18n.js';

/**
 * Passwortfeld mit Auge zum Sichtbarmachen.
 *
 * Das Shop-Frontend hat kein Komponenten-Framework mit fertigen Feldern —
 * jede Komponente baut ihr Markup selbst. Diese Hilfe liefert deshalb nur
 * den Eingabebereich samt Umschalter; Label und Feld-Wrapper bleiben dort,
 * wo sie heute stehen.
 *
 * Den Sichtbar-Zustand haelt die aufrufende Komponente (reaktiver State),
 * damit Lit das Feld beim Umschalten neu zeichnet:
 *
 *   static properties = { _sichtbar: { state: true } }
 *   ...
 *   ${passwordInput({
 *     cls: this.cls('input'),
 *     id: 'password',
 *     visible: this._sichtbar,
 *     onToggle: () => { this._sichtbar = !this._sichtbar },
 *   })}
 */

/** Styles fuer das Feld; gehoert in `static styles` der Komponente */
export const passwordFieldStyles = css`
  .shop-password {
    position: relative;
    display: block;
  }
  .shop-password input {
    /* Platz fuer den Umschalter, damit er den Text nicht ueberdeckt */
    padding-right: 2.75rem;
    width: 100%;
    box-sizing: border-box;
  }
  .shop-password__toggle {
    position: absolute;
    top: 50%;
    right: 0.5rem;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem;
    border: 0;
    background: none;
    color: inherit;
    opacity: 0.6;
    cursor: pointer;
    line-height: 0;
  }
  .shop-password__toggle:hover,
  .shop-password__toggle:focus-visible {
    opacity: 1;
  }
`;

/** Auge — Passwort ist verborgen, Klick zeigt es an */
const augeAn = html`
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
    <circle cx="12" cy="12" r="3" />
  </svg>
`;

/** Durchgestrichenes Auge — Passwort ist sichtbar, Klick verbirgt es */
const augeAus = html`
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
    <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24" />
    <line x1="1" y1="1" x2="23" y2="23" />
  </svg>
`;

/**
 * Rendert ein Passwort-Eingabefeld mit Umschalter
 *
 * @param {object} opts
 * @param {string} opts.cls Klassen fuer das Eingabefeld (ueber this.cls())
 * @param {string} opts.id Feld-Kennung, zugleich name
 * @param {string} [opts.name] Abweichender name
 * @param {string} [opts.autocomplete] autocomplete-Token
 * @param {boolean} [opts.required] Pflichtfeld
 * @param {boolean} [opts.visible] Ist das Passwort gerade sichtbar?
 * @param {Function} opts.onToggle Wird beim Klick auf das Auge gerufen
 * @returns {import('lit').TemplateResult}
 */
export function passwordInput({
  cls,
  id,
  name,
  autocomplete = 'current-password',
  required = false,
  visible = false,
  onToggle,
}) {
  const beschriftung = visible ? t('password.hide') : t('password.show');

  return html`
    <div class="shop-password">
      <input
        class=${cls}
        part="input"
        id=${id}
        name=${name || id}
        type=${visible ? 'text' : 'password'}
        autocomplete=${autocomplete}
        ?required=${required}
      />
      <button
        class="shop-password__toggle"
        part="password-toggle"
        type="button"
        tabindex="-1"
        title=${beschriftung}
        aria-label=${beschriftung}
        @click=${onToggle}
      >
        ${visible ? augeAus : augeAn}
      </button>
    </div>
  `;
}
