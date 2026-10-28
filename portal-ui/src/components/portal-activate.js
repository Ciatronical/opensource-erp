import { html, css, nothing } from 'lit';
import { PortalElement } from '../core/base.js';
import { apiRequest } from '../core/api.js';
import { emit, PORTAL_AUTH_CHANGED } from '../core/bus.js';
import { t } from '../core/i18n.js';
import { passwordInput, passwordFieldStyles } from '../core/password-field.js';
import { shopPath } from '../core/paths.js';

/**
 * <portal-activate></portal-activate>
 * <portal-activate redirect-url="/persönliche-daten/" login-url="/login/"></portal-activate>
 *
 * Zugang für Bestandskunden (dev/shop-konto-freischaltung.md). Zwei Schritte
 * auf einer Seite:
 *
 *  1. Ohne Kennung: der Kunde nennt seine Telefonnummer und die Postleitzahl
 *     (oder seine Kundennummer). Die Antwort ist immer dieselbe — ob die
 *     Nummer bekannt ist, verrät sie nicht.
 *  2. Mit Kennung (?token=… aus dem WhatsApp- oder Mail-Link): der Kunde
 *     sieht, dass es sein Konto ist (Name und Ort maskiert), vergibt
 *     E-Mail-Adresse und Kennwort und ist danach angemeldet.
 *
 * Gebaut für Menschen ohne Computerkenntnisse: wenige Felder je Schritt,
 * Beschriftungen in Alltagssprache, jede Meldung sagt, was als Nächstes zu
 * tun ist, ein großer Knopf.
 */
export class PortalActivate extends PortalElement {
  static properties = {
    token: { type: String },
    redirectUrl: { type: String, attribute: 'redirect-url' },
    loginUrl: { type: String, attribute: 'login-url' },
    /** Kontaktseite — Ausweg, wenn keine Nachricht ankommt */
    contactUrl: { type: String, attribute: 'contact-url' },
    heading: { type: String },
    _step: { state: true },
    _info: { state: true },
    _busy: { state: true },
    _error: { state: true },
    _byNumber: { state: true },
    _sichtbar: { state: true },
    _sichtbar2: { state: true },
  };

  static styles = [
    PortalElement.baseStyles,
    passwordFieldStyles,
    css`
      .intro {
        max-width: var(--shop-form-width, 32rem);
        margin: 0 0 1.5rem;
      }
      .who {
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
        max-width: var(--shop-form-width, 32rem);
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        background: var(--shop-input-bg, #f8f9fa);
      }
      .who strong {
        display: block;
        font-size: 1.1rem;
      }
      .hint {
        font-size: 0.9rem;
        opacity: 0.8;
        margin: 0.35rem 0 0;
      }
      .switch {
        margin-top: 0.5rem;
      }
      .switch button {
        background: none;
        border: 0;
        padding: 0;
        color: inherit;
        text-decoration: underline;
        cursor: pointer;
        font: inherit;
      }
      .shop-message {
        margin-top: 1rem;
        max-width: var(--shop-form-width, 32rem);
      }
      .shop-message:empty {
        display: none;
      }
      .big {
        font-size: 1.1rem;
        padding: 0.75rem 1.5rem;
      }
      .done {
        max-width: var(--shop-form-width, 32rem);
      }
      .honey {
        position: absolute;
        left: -9999px;
        width: 1px;
        height: 1px;
        overflow: hidden;
      }
      .steps {
        margin: 0 0 1rem;
        font-size: 0.9rem;
        opacity: 0.8;
      }
    `,
  ];

  constructor() {
    super();
    this.token = '';
    this.redirectUrl = shopPath('account', '#focus');
    this.loginUrl = shopPath('login');
    this.contactUrl = '';
    this.heading = '';
    // request | sent | token | done
    this._step = 'request';
    this._info = null;
    this._busy = false;
    this._error = '';
    // Zweites Merkmal: Postleitzahl (Vorgabe) oder Kundennummer
    this._byNumber = false;
    this._sichtbar = false;
    this._sichtbar2 = false;
  }

  connectedCallback() {
    super.connectedCallback();
    if (!this.token) {
      const params = new URLSearchParams(window.location.search);
      this.token = (params.get('token') || '').trim();
    }
    if (this.token) {
      this._step = 'token';
      this.#loadInfo();
    }
  }

  async #loadInfo() {
    this._busy = true;
    this._error = '';
    try {
      this._info = await apiRequest('activationInfo', { token: this.token });
    } catch (error) {
      this._info = null;
      this._error = this.#text(error);
    } finally {
      this._busy = false;
    }
  }

  /** Lesbarer Satz zu einem Fehlercode des Backends */
  #text(error) {
    const code = (error && error.code) || 'PORTAL_API_ERROR';
    const key = `activate.error.${code}`;
    const text = t(key);
    return text === key ? t(code) : text;
  }

  render() {
    return html`
      ${this.heading
        ? html`<h2 class=${this.cls('heading')} part="heading">${this.heading}</h2>`
        : nothing}
      ${this._step === 'request' ? this.#renderRequest() : nothing}
      ${this._step === 'sent' ? this.#renderSent() : nothing}
      ${this._step === 'token' ? this.#renderToken() : nothing}
      ${this._step === 'done' ? this.#renderDone() : nothing}
    `;
  }

  #renderRequest() {
    return html`
      <p class="intro">${t('activate.intro')}</p>
      <p class="steps">${t('activate.steps')}</p>
      <form class=${this.cls('form')} part="form" @submit=${this.#onRequest} novalidate>
        <div class="fields">
          <div class="field" part="field">
            <label class=${this.cls('label')} for="phone">${t('activate.phone')}</label>
            <input class=${this.cls('input')} id="phone" name="phone" type="tel" inputmode="tel"
              autocomplete="tel" required placeholder="0171 1234567" />
            <p class="hint">${t('activate.phoneHint')}</p>
          </div>
          ${this._byNumber
            ? html`<div class="field" part="field">
                <label class=${this.cls('label')} for="customernumber">${t('activate.customernumber')}</label>
                <input class=${this.cls('input')} id="customernumber" name="customernumber" type="text"
                  inputmode="numeric" autocomplete="off" required />
                <p class="hint">${t('activate.customernumberHint')}</p>
                <p class="switch"><button type="button" @click=${() => { this._byNumber = false; }}>${t('activate.useZipcode')}</button></p>
              </div>`
            : html`<div class="field" part="field">
                <label class=${this.cls('label')} for="zipcode">${t('activate.zipcode')}</label>
                <input class=${this.cls('input')} id="zipcode" name="zipcode" type="text"
                  inputmode="numeric" autocomplete="postal-code" required maxlength="10" />
                <p class="hint">${t('activate.zipcodeHint')}</p>
                <p class="switch"><button type="button" @click=${() => { this._byNumber = true; }}>${t('activate.useNumber')}</button></p>
              </div>`}
          <div class="honey" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" name="website" type="text" tabindex="-1" autocomplete="off" />
          </div>
        </div>
        <div class="actions">
          <button class="${this.cls('buttonPrimary')} big" part="submit" type="submit" ?disabled=${this._busy}>
            ${this._busy ? t('activate.pending') : t('activate.submit')}
          </button>
        </div>
        <div class="shop-message" role="alert" aria-live="polite">
          ${this._error ? html`<div class=${this.cls('alertError')}>${this._error}</div>` : nothing}
        </div>
      </form>
      <p class="hint">${t('activate.haveAccount')} <a href=${this.loginUrl}>${t('activate.toLogin')}</a></p>
    `;
  }

  #renderSent() {
    return html`
      <div class="done">
        <div class=${this.cls('alertSuccess')} role="status">${t('activate.sent')}</div>
        <p class="intro">${t('activate.sentHint')}</p>
        <p class="hint">${t('activate.sentNothing')}</p>
        <div class="actions">
          <button class=${this.cls('button')} type="button" @click=${() => { this._step = 'request'; this._error = ''; }}>
            ${t('activate.again')}
          </button>
          <a class=${this.cls('buttonSecondary')} href=${this.contactUrl || shopPath('contact')}>${t('activate.contact')}</a>
        </div>
      </div>
    `;
  }

  #renderToken() {
    if (this._busy && !this._info) {
      return html`<p class=${this.cls('muted')}>${t('account.loading')}</p>`;
    }
    if (!this._info) {
      return html`
        <div class="done">
          <div class=${this.cls('alertError')} role="alert">${this._error || t('activate.error.ACTIVATION_INVALID')}</div>
          <div class="actions">
            <button class="${this.cls('buttonPrimary')} big" type="button"
              @click=${() => { this.token = ''; this._info = null; this._error = ''; this._step = 'request'; }}>
              ${t('activate.requestNew')}
            </button>
          </div>
        </div>
      `;
    }
    const wer = [this._info.name, [this._info.zipcode, this._info.city].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    return html`
      <div class="who" part="who">
        <span>${t('activate.yourAccount')}</span>
        <strong>${wer}</strong>
        <p class="hint">${t('activate.yourAccountHint')}</p>
      </div>
      <p class="intro">${t('activate.tokenIntro')}</p>
      <form class=${this.cls('form')} part="form" @submit=${this.#onComplete} novalidate>
        <div class="fields">
          <div class="field" part="field">
            <label class=${this.cls('label')} for="email">${t('activate.email')}</label>
            <input class=${this.cls('input')} id="email" name="email" type="email" inputmode="email"
              autocomplete="email" required .value=${this._info.email || ''} />
            <p class="hint">${t('activate.emailHint')}</p>
          </div>
          <div class="field" part="field">
            <label class=${this.cls('label')} for="password">${t('activate.password')}</label>
            ${passwordInput({
              cls: this.cls('input'),
              id: 'password',
              autocomplete: 'new-password',
              required: true,
              visible: this._sichtbar,
              onToggle: () => { this._sichtbar = !this._sichtbar; },
            })}
            <p class="hint">${t('activate.passwordHint')}</p>
          </div>
          <div class="field" part="field">
            <label class=${this.cls('label')} for="password2">${t('activate.passwordRepeat')}</label>
            ${passwordInput({
              cls: this.cls('input'),
              id: 'password2',
              autocomplete: 'new-password',
              required: true,
              visible: this._sichtbar2,
              onToggle: () => { this._sichtbar2 = !this._sichtbar2; },
            })}
          </div>
        </div>
        <div class="actions">
          <button class="${this.cls('buttonPrimary')} big" part="submit" type="submit" ?disabled=${this._busy}>
            ${this._busy ? t('activate.pendingToken') : t('activate.submitToken')}
          </button>
        </div>
        <div class="shop-message" role="alert" aria-live="polite">
          ${this._error ? html`<div class=${this.cls('alertError')}>${this._error}</div>` : nothing}
        </div>
      </form>
    `;
  }

  #renderDone() {
    return html`
      <div class="done">
        <div class=${this.cls('alertSuccess')} role="status">${t('activate.done')}</div>
        <p class="intro">${t('activate.doneHint')}</p>
        <div class="actions">
          <a class="${this.cls('buttonPrimary')} big" href=${this.redirectUrl}>${t('activate.toAccount')}</a>
        </div>
      </div>
    `;
  }

  async #onRequest(event) {
    event.preventDefault();
    if (this._busy) return;
    const phone = this.$('phone').value.trim();
    const zipcode = this._byNumber ? '' : this.$('zipcode').value.trim();
    const customernumber = this._byNumber ? this.$('customernumber').value.trim() : '';
    if (!phone) {
      this._error = t('activate.error.MISSING_PHONE');
      this.$('phone').focus();
      return;
    }
    if (!zipcode && !customernumber) {
      this._error = t('activate.error.MISSING_ZIPCODE');
      return;
    }
    this._busy = true;
    this._error = '';
    try {
      await apiRequest('requestActivation', {
        phone, zipcode, customernumber,
        website: this.$('website') ? this.$('website').value : '',
      });
      this._step = 'sent';
    } catch (error) {
      this._error = this.#text(error);
    } finally {
      this._busy = false;
    }
  }

  async #onComplete(event) {
    event.preventDefault();
    if (this._busy) return;
    const email = this.$('email').value.trim();
    const password = this.$('password').value;
    const password2 = this.$('password2').value;
    if (!email || !/\S+@\S+\.\S+/.test(email)) {
      this._error = t('activate.error.INVALID_EMAIL');
      this.$('email').focus();
      return;
    }
    if (password.length < 8) {
      this._error = t('activate.passwordShort');
      this.$('password').focus();
      return;
    }
    if (password !== password2) {
      this._error = t('activate.passwordMismatch');
      this.$('password2').focus();
      return;
    }
    this._busy = true;
    this._error = '';
    try {
      await apiRequest('completeActivation', { token: this.token, email, password });
      emit(PORTAL_AUTH_CHANGED, { account: true });
      this._step = 'done';
    } catch (error) {
      this._error = this.#text(error);
    } finally {
      this._busy = false;
    }
  }
}

customElements.define('portal-activate', PortalActivate);
