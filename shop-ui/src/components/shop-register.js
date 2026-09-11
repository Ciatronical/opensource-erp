import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { ShopElement } from '../core/base.js';
import { apiRequest, ApiError, login } from '../core/api.js';
import { emit, SHOP_AUTH_CHANGED } from '../core/bus.js';
import { t } from '../core/i18n.js';

const EMAIL_PATTERN = /\S+@\S+\.\S+/;

/**
 * <shop-register redirect-url="/registriert/" login-url="/login/"></shop-register>
 * <shop-register mode="guest"></shop-register>
 *
 * Ersetzt shortcodes/register-form.html (182 Zeilen Markup) + createAccount()
 * und initAccountRegistration() aus account.js.
 *
 * MODI
 *  - "account" (Default): vollstaendige Registrierung. Legt das Konto an,
 *    meldet an und leitet auf redirect-url.
 *  - "guest": Gast-Bestellung im Kassenprozess. Keine Passwortfelder, kein
 *    eigener Absende-Button — <shop-checkout> ruft validate()/values()/
 *    register() auf und haengt den invoicing-Aufruf daran.
 *    Frueher entstand dieser Modus dadurch, dass invoicing() die Passwort-
 *    felder des Registrierungsformulars per style.display ausblendete
 *    (account.js:675) und initAccountRegistration() auf das Markup zugriff,
 *    das zufaellig auf der Seite lag — deshalb musste checkout.html die
 *    182 Zeilen duplizieren.
 *
 * ACHTUNG Backend: `guest` MUSS ein JSON-Boolean sein. shop.account.php:637
 * funktioniert nur ueber PHP-Bool-Coercion; der String "false" wuerde ein
 * echtes Konto mit user_password = NULL anlegen (Login unmoeglich) und es
 * zusaetzlich als Gast markieren.
 */
export class ShopRegister extends ShopElement {
  static properties = {
    mode: { type: String },
    redirectUrl: { type: String, attribute: 'redirect-url' },
    loginUrl: { type: String, attribute: 'login-url' },
    dataProtectionUrl: { type: String, attribute: 'data-protection-url' },
    heading: { type: String },
    _salutations: { state: true },
    _loading: { state: true },
    _busy: { state: true },
    _error: { state: true },
    _accountExists: { state: true },
    _business: { state: true },
    _otherDelivery: { state: true },
    _dataProtection: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      .block {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      .block-title {
        font-weight: 600;
        margin-bottom: 1rem;
      }
      fieldset {
        border: 0;
        margin: 0;
        padding: 0;
      }
      legend {
        padding: 0;
        margin-bottom: 0.5rem;
      }
      .choice {
        display: flex;
        gap: 1.5rem;
        align-items: center;
      }
      .choice label {
        display: flex;
        gap: 0.4rem;
        align-items: center;
        margin: 0;
      }
      .shop-message {
        margin-top: 1.5rem;
        max-width: var(--shop-form-width, 32rem);
      }
      .hint {
        margin-top: 0.5rem;
      }
    `,
  ];

  constructor() {
    super();
    this.mode = 'account';
    this.redirectUrl = '/registriert/';
    this.loginUrl = '/login/';
    this.dataProtectionUrl = '/datenschutz/';
    this.heading = '';
    this._salutations = [];
    this._loading = true;
    this._busy = false;
    this._error = '';
    this._accountExists = false;
    this._business = false;
    this._otherDelivery = false;
    // Auf "Ja" vorbelegt — wie im alten Formular (register-form.html:138,
    // btn-data-pro-yes war `checked`). Nach Rueckfrage 2026-08-20 bewusst
    // so beibehalten; nicht als Versehen "korrigieren".
    this._dataProtection = true;
  }

  get isGuest() {
    return this.mode === 'guest';
  }

  #selectionsLoaded = false;

  async connectedCallback() {
    await super.connectedCallback();
    if (this.#selectionsLoaded) return;
    this.#selectionsLoaded = true;
    try {
      const data = await apiRequest('getAccountSelections', { lang: document.documentElement.lang });
      this._salutations = (data && data.salutations) || [];
    } catch (error) {
      this._error = t(error.code);
    } finally {
      this._loading = false;
    }
  }

  /**
   * Feldliste — Quelle fuer Rendering, Pflichtpruefung UND Payload.
   * Frueher liefen diese drei ueber `class="register-form"` und die
   * Element-IDs auseinander; hier koennen sie es nicht.
   */
  get #billingFields() {
    return [
      {
        id: 'company-name',
        label: t('register.companyName'),
        autocomplete: 'organization',
        required: true,
        visible: this._business,
      },
      {
        id: 'name',
        label: this._business ? t('register.contactName') : t('register.name'),
        autocomplete: 'name',
        required: true,
      },
      {
        id: 'street',
        label: t('register.street'),
        autocomplete: 'billing street-address',
        required: true,
      },
      {
        id: 'city',
        label: t('register.city'),
        autocomplete: 'billing address-level2',
        required: true,
      },
      {
        id: 'postcode',
        label: t('register.postcode'),
        autocomplete: 'billing postal-code',
        required: true,
      },
      {
        id: 'country',
        label: t('register.country'),
        autocomplete: 'billing country-name',
        required: true,
      },
      { id: 'phone', label: t('register.phone'), autocomplete: 'tel', type: 'tel' },
    ];
  }

  get #shippingFields() {
    return [
      {
        id: 'shipping-name',
        label: t('register.name'),
        autocomplete: 'shipping name',
        required: true,
      },
      {
        id: 'shipping-street',
        label: t('register.street'),
        autocomplete: 'shipping street-address',
        required: true,
      },
      {
        id: 'shipping-city',
        label: t('register.city'),
        autocomplete: 'shipping address-level2',
        required: true,
      },
      {
        id: 'shipping-postcode',
        label: t('register.postcode'),
        autocomplete: 'shipping postal-code',
        required: true,
      },
      {
        id: 'shipping-country',
        label: t('register.country'),
        autocomplete: 'shipping country-name',
        required: true,
      },
      {
        id: 'shipping-phone',
        label: t('register.phone'),
        autocomplete: 'shipping tel',
        type: 'tel',
      },
      // Nur an der Kasse. checkout.html hatte dieses Feld,
      // register-form.html nicht — beide Formulare bleiben so, wie sie waren.
      {
        id: 'shipping-email',
        label: t('register.email'),
        autocomplete: 'shipping email',
        type: 'email',
        visible: this.isGuest,
      },
    ];
  }

  get #credentialFields() {
    const fields = [
      {
        id: 'email',
        label: t('register.email'),
        autocomplete: 'email',
        type: 'email',
        required: true,
      },
    ];
    if (!this.isGuest) {
      fields.push(
        {
          id: 'password',
          label: t('register.password'),
          autocomplete: 'new-password',
          type: 'password',
          required: true,
        },
        {
          id: 'confirm-password',
          label: t('register.passwordRepeat'),
          autocomplete: 'new-password',
          type: 'password',
          required: true,
        }
      );
    }
    return fields;
  }

  #field(field) {
    return html`
      <div class="field" part="field">
        <label class=${this.cls('label')} part="label" for=${field.id}>
          ${field.label}${field.required ? '*' : ''}
        </label>
        <input
          class=${this.cls('input')}
          part="input"
          id=${field.id}
          name=${field.id}
          type=${field.type || 'text'}
          autocomplete=${field.autocomplete || 'off'}
          ?required=${!!field.required}
        />
      </div>
    `;
  }

  /**
   * repeat() mit Schluessel ist hier Pflicht, nicht Geschmack: die Feldliste
   * aendert sich zur Laufzeit (Firmenname erscheint bei "Gewerblich").
   * Unkeyed wuerde Lit die DOM-Knoten nach Index wiederverwenden — der
   * bereits getippte Name landete dann im Firmennamen-Feld, weil der
   * input-Wert nicht gebunden ist.
   */
  #fields(list) {
    const visible = list.filter((f) => f.visible !== false);
    return html`<div class="fields">
      ${repeat(
        visible,
        (f) => f.id,
        (f) => this.#field(f)
      )}
    </div>`;
  }

  render() {
    if (this._loading) return html`<p class=${this.cls('muted')}>…</p>`;

    return html`
      ${this.heading
        ? html`<h2 class=${this.cls('heading')} part="heading">${this.heading}</h2>`
        : nothing}

      <form id="form" class=${this.cls('form')} part="form" @submit=${this.#onSubmit} novalidate>
        <div class="fields">
          <div class="field" part="field">
            <label class=${this.cls('label')} part="label" for="account-type">
              ${t('register.accountType')}*
            </label>
            <select
              class=${this.cls('select')}
              part="select"
              id="account-type"
              name="account-type"
              @change=${(e) => (this._business = e.target.value === 'false')}
            >
              <option value="true">${t('register.private')}</option>
              <option value="false">${t('register.business')}</option>
            </select>
          </div>

          <div class="field" part="field">
            <label class=${this.cls('label')} part="label" for="salutation">
              ${t('register.salutation')}
            </label>
            <select
              class=${this.cls('select')}
              part="select"
              id="salutation"
              name="salutation"
              autocomplete="honorific-prefix"
            >
              <option value=""></option>
              ${this._salutations.map(
                (item) => html`<option value=${item.translation}>${item.translation}</option>`
              )}
            </select>
          </div>
        </div>

        ${this.#fields(this.#billingFields)}

        <div class="block">
          <fieldset>
            <legend>${t('register.sameAddress')}</legend>
            <div class="choice">
              <label>
                <input
                  class=${this.cls('check')}
                  type="radio"
                  name="same-address"
                  .checked=${!this._otherDelivery}
                  @change=${() => (this._otherDelivery = false)}
                />
                ${t('register.yes')}
              </label>
              <label>
                <input
                  class=${this.cls('check')}
                  type="radio"
                  name="same-address"
                  .checked=${this._otherDelivery}
                  @change=${() => (this._otherDelivery = true)}
                />
                ${t('register.no')}
              </label>
            </div>
          </fieldset>

          ${this._otherDelivery
            ? html`
                <div class="block-title" style="margin-top:1.5rem">
                  ${t('register.deliveryAddress')}
                </div>
                ${this.#fields(this.#shippingFields)}
              `
            : nothing}
        </div>

        <div class="block">
          <fieldset>
            <legend>
              <a href=${this.dataProtectionUrl} target="_blank" rel="noopener"
                >${t('register.dataProtectionLabel')}</a
              >
              ${t('register.dataProtection')}
            </legend>
            <div class="choice">
              <label>
                <input
                  class=${this.cls('check')}
                  type="radio"
                  name="data-protection"
                  .checked=${this._dataProtection}
                  @change=${() => (this._dataProtection = true)}
                />
                ${t('register.yes')}
              </label>
              <label>
                <input
                  class=${this.cls('check')}
                  type="radio"
                  name="data-protection"
                  .checked=${!this._dataProtection}
                  @change=${() => (this._dataProtection = false)}
                />
                ${t('register.no')}
              </label>
            </div>
            ${!this._dataProtection
              ? html`<div class="hint ${this.cls('muted')}">
                  ${t('register.dataProtectionHint')}
                </div>`
              : nothing}
          </fieldset>
        </div>

        <div class="block">
          ${this.isGuest
            ? nothing
            : html`<div class="block-title">${t('register.credentials')}</div>`}
          ${this.#fields(this.#credentialFields)}
        </div>

        ${this.isGuest
          ? nothing
          : html`<div class="actions">
              <button
                class=${this.cls('buttonSecondary')}
                part="submit"
                type="submit"
                ?disabled=${this._busy}
              >
                ${this._busy ? t('register.pending') : t('register.submit')}
              </button>
            </div>`}

        <div class="shop-message" role="alert" aria-live="polite">
          ${this._error
            ? html`<div class=${this.cls('alertError')} part="error">
                ${this._error}
                ${this._accountExists
                  ? html` <a href=${this.loginUrl} part="login-link">${t('register.loginLink')}</a>`
                  : nothing}
              </div>`
            : nothing}
        </div>
      </form>
    `;
  }

  // ---- oeffentliche API, von <shop-checkout> im Gast-Modus genutzt ----

  /** Alle sichtbaren Pflichtfelder gefuellt, E-Mail plausibel, Passwoerter gleich. */
  validate() {
    this._error = '';
    this._accountExists = false;

    const groups = [this.#billingFields, this.#credentialFields];
    if (this._otherDelivery) groups.push(this.#shippingFields);

    for (const group of groups) {
      for (const field of group) {
        if (!field.required || field.visible === false) continue;
        const element = this.$(field.id);
        if (element && !element.value.trim()) {
          this._error = t('register.required') + field.label;
          element.focus();
          return false;
        }
      }
    }

    if (!this._dataProtection) {
      this._error = t('register.dataProtectionMissing');
      return false;
    }

    // Die Felder gibt es erst nach getAccountSelections. <shop-checkout>
    // kann seinen Kaufen-Knopf frueher freigeben — ohne diese Klammer liefe
    // die Pruefung dann auf null.
    if (!this.$('email')) return false;

    if (!EMAIL_PATTERN.test(this.$('email').value.trim())) {
      this._error = t('register.emailInvalid');
      this.$('email').focus();
      return false;
    }

    if (!this.isGuest && this.$('password').value !== this.$('confirm-password').value) {
      this._error = t('register.passwordMismatch');
      this.$('confirm-password').focus();
      return false;
    }

    return true;
  }

  /**
   * Payload fuer registerAccount. Alle Schluessel werden immer gesendet —
   * das alte saveAccount() liess leere Felder weg, wodurch das Backend auf
   * undefinierte Indizes lief.
   */
  values() {
    const value = (id) => {
      const element = this.$(id);
      return element ? element.value.trim() : '';
    };

    const data = {
      'account-type': this._business ? 'false' : 'true',
      'company-name': this._business ? value('company-name') : '',
      salutation: value('salutation'),
      name: value('name'),
      street: value('street'),
      city: value('city'),
      postcode: value('postcode'),
      country: value('country'),
      phone: value('phone'),
      email: value('email'),
      // Gast-Konten bekommen kein Passwort (shop.account.php:638).
      password: this.isGuest ? '' : this.$('password').value,
      'add-delivery-address': this._otherDelivery ? 'true' : 'false',
    };

    for (const field of this.#shippingFields) {
      data[field.id] = this._otherDelivery ? value(field.id) : '';
    }
    return data;
  }

  /**
   * Legt das Konto an. `guest` als echtes Boolean — siehe Klassenkommentar.
   * @returns {Promise<object>} Antwort des Backends
   */
  async register() {
    return apiRequest('registerAccount', { ...this.values(), guest: this.isGuest });
  }

  // ---- eigener Absende-Weg (Modus "account") ----

  async #onSubmit(event) {
    event.preventDefault();
    if (this._busy || this.isGuest) return;
    if (!this.validate()) return;

    this._busy = true;
    const email = this.$('email').value.trim();
    const password = this.$('password').value;

    try {
      await this.register();
      // Direkt anmelden, wie bisher nach erfolgreicher Registrierung.
      await login(email, password);
      emit(SHOP_AUTH_CHANGED, { account: true });
      window.location.href = this.redirectUrl || '/';
    } catch (error) {
      this._busy = false;
      if (error instanceof ApiError && error.code === 'ACCOUNT_EXISTS') {
        this._error = t('register.accountExistsHint');
        this._accountExists = true;
      } else {
        this._error = t(error.code);
      }
    }
  }
}

customElements.define('shop-register', ShopRegister);
