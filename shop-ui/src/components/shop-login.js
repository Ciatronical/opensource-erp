import { html, css, nothing } from 'lit';
import { ShopElement } from '../core/base.js';
import { apiRequest, login } from '../core/api.js';
import { emit, SHOP_AUTH_CHANGED } from '../core/bus.js';
import { t } from '../core/i18n.js';

/**
 * <shop-login redirect-url="/" register-url="/registrieren/"></shop-login>
 *
 * Ersetzt shortcodes/login.html + static/js/shopwindow/login.js.
 *
 * Gegenueber dem Vorgaenger:
 *  - Markup und Verhalten liegen zusammen in der Komponente; kein
 *    document.getElementById() auf fremdes Markup mehr.
 *  - echtes <form> mit autocomplete-Tokens (vorher gab es weder das eine
 *    noch das andere) — Voraussetzung dafuer, dass Browser-Autofill und
 *    Passwortmanager die Felder ueberhaupt erkennen.
 *  - Absenden mit Enter kommt vom Formular selbst, nicht von einem
 *    keypress-Handler auf dem Passwortfeld.
 *  - Keine Framework-Klassen im Template: this.cls() loest die Rollen ueber
 *    das aktive Preset auf (siehe core/presets.js).
 */
export class ShopLogin extends ShopElement {
  static properties = {
    redirectUrl: { type: String, attribute: 'redirect-url' },
    registerUrl: { type: String, attribute: 'register-url' },
    heading: { type: String },
    _busy: { state: true },
    _error: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      .shop-message {
        margin-top: 1rem;
        max-width: var(--shop-form-width, 32rem);
      }
      .shop-message:empty {
        display: none;
      }
      .register-link {
        margin-top: 1.5rem;
      }
    `,
  ];

  constructor() {
    super();
    this.redirectUrl = '/';
    this.registerUrl = '';
    this.heading = '';
    this._busy = false;
    this._error = '';
  }

  render() {
    return html`
      ${this.heading
        ? html`<h2 class=${this.cls('heading')} part="heading">${this.heading}</h2>`
        : nothing}

      <form id="form" class=${this.cls('form')} part="form" @submit=${this.#onSubmit} novalidate>
        <div class="fields">
          <div class="field" part="field">
            <label class=${this.cls('label')} part="label" for="email">${t('login.email')}</label>
            <input
              class=${this.cls('input')}
              part="input"
              id="email"
              name="email"
              type="email"
              autocomplete="username"
              inputmode="email"
              required
            />
          </div>
          <div class="field" part="field">
            <label class=${this.cls('label')} part="label" for="password">
              ${t('login.password')}
            </label>
            <input
              class=${this.cls('input')}
              part="input"
              id="password"
              name="password"
              type="password"
              autocomplete="current-password"
              required
            />
          </div>
        </div>

        <div class="actions">
          <button
            class=${this.cls('buttonSecondary')}
            part="submit"
            type="submit"
            ?disabled=${this._busy}
          >
            ${this._busy ? t('login.pending') : t('login.submit')}
          </button>
        </div>

        <div class="shop-message" role="alert" aria-live="polite">
          ${this._error
            ? html`<div class=${this.cls('alertError')} part="error">${this._error}</div>`
            : nothing}
        </div>
      </form>

      ${this.registerUrl
        ? html`<div class="register-link">
            <a href=${this.registerUrl} part="register-link">${t('login.register')}</a>
          </div>`
        : nothing}
    `;
  }

  firstUpdated() {
    // Fokus nur auf ausdrueckliche Ansage. Ein Widget, das beim Laden
    // ungefragt den Fokus zieht, scrollt die Seite zu sich hin, sobald es
    // unterhalb des sichtbaren Bereichs liegt.
    if (!this.hasAttribute('autofocus')) return;
    const email = this.$('email');
    if (email) email.focus();
  }

  async #onSubmit(event) {
    event.preventDefault();
    if (this._busy) return;

    const email = this.$('email').value.trim();
    const password = this.$('password').value;

    if (!email || !password) {
      this._error = t('login.missing');
      return;
    }

    this._busy = true;
    this._error = '';
    try {
      await login(email, password);
      emit(SHOP_AUTH_CHANGED, { account: true });
      window.location.href = this.redirectUrl || '/';
    } catch (error) {
      this._error = t(error.code);
      this._busy = false;
      this.$('password').value = '';
      this.$('password').focus();
    }
  }
}

customElements.define('shop-login', ShopLogin);
