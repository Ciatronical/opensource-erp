import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { ShopElement } from '../core/base.js';
import { apiRequest } from '../core/api.js';
import { on, SHOP_CART_CHANGED, SHOP_AUTH_CHANGED } from '../core/bus.js';
import { t } from '../core/i18n.js';
import { cityLine } from '../core/account-data.js';
import {
  checkoutAccount,
  shippingPayload,
  guestPayload,
  emptyShipping,
} from '../core/checkout-data.js';
import { gtmBeginCheckout } from '../core/gtm.js';
import './shop-cart.js';
import './shop-register.js';

/**
 * <shop-checkout billing-page="/rechnung/" canceled-page="/bezahlung-abgebrochen/"></shop-checkout>
 *
 * Ersetzt shortcodes/checkout.html (359 Zeilen Markup) sowie
 * billingAndShipping() und invoicing() aus account.js.
 *
 * DIE ZWEI ZWEIGE
 *  - angemeldet: Rechnungsadresse nur zur Ansicht, dazu die Wahl der
 *    Lieferadresse (Standard, eine gespeicherte, oder eine neue).
 *  - Gast: <shop-register mode="guest"> uebernimmt das komplette Formular.
 *    Frueher stand dasselbe Markup ein zweites Mal in checkout.html, und
 *    invoicing() blendete darin die Passwortfelder per style.display aus.
 *
 * DAS KUNDENKONTO WAEHREND DER BESTELLUNG
 * Die alte Seite bot unter dem Gastformular zusaetzlich "Kundenkonto
 * anlegen": derselbe Datensatz, aber mit Passwort, danach Anmeldung und
 * Ruecksprung auf /kasse/. Das bleibt erhalten — der Schalter stellt
 * <shop-register> von "guest" auf "account" um, womit dessen eigener
 * Absende-Weg gilt (anlegen, anmelden, zurueck auf redirect-url). Solange er
 * an ist, verschwindet "Jetzt kaufen": erst das Konto, dann die Bestellung
 * als angemeldeter Kunde. Frueher standen beide Knoepfe gleichzeitig da.
 *
 * PAYPAL steht jetzt bei "Jetzt kaufen" und nicht mehr ueber der Seite
 * (checkout.html:5). Es ist die zweite Zahlungsart, also gehoert es dorthin,
 * wo ueber die Zahlung entschieden wird.
 *
 * WARUM DER WARENKORB EIN EIGENES ELEMENT BLEIBT
 * <shop-cart mode="checkout"> zeigt dieselbe Liste wie /warenkorb/, nur ohne
 * den Fuss. Es meldet den geladenen Warenkorb ueber shop:cart-changed —
 * daraus kommt hier der Zustand des Kaufen-Knopfes und begin_checkout.
 */
export class ShopCheckout extends ShopElement {
  static properties = {
    billingPage: { type: String, attribute: 'billing-page' },
    canceledPage: { type: String, attribute: 'canceled-page' },
    invoiceUrl: { type: String, attribute: 'invoice-url' },
    loginUrl: { type: String, attribute: 'login-url' },
    dataProtectionUrl: { type: String, attribute: 'data-protection-url' },
    paypalImage: { type: String, attribute: 'paypal-image' },
    heading: { type: String },
    _state: { state: true },
    _error: { state: true },
    _account: { state: true },
    _mode: { state: true },
    _selectedId: { state: true },
    _form: { state: true },
    _createAccount: { state: true },
    _busy: { state: true },
    _count: { state: true },
    _accountExists: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      .layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 2rem;
        align-items: start;
      }
      @media (max-width: 62rem) {
        .layout {
          grid-template-columns: minmax(0, 1fr);
        }
      }
      .panel {
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        padding: 1.5rem;
      }
      .section-title {
        margin: 0 0 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--shop-border-color, #dee2e6);
      }
      .block {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      .block:first-of-type {
        margin-top: 0;
        padding-top: 0;
        border-top: 0;
      }
      .block-title {
        font-weight: 600;
        margin-bottom: 0.5rem;
      }
      .address p {
        margin: 0.25rem 0;
      }
      .choose {
        margin-top: 1.5rem;
        max-width: var(--shop-form-width, 32rem);
      }
      .account-switch {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        margin-bottom: 1.5rem;
      }
      .account-switch label {
        margin: 0;
      }
      .buy {
        margin-top: 2rem;
      }
      .note {
        margin-top: 1rem;
      }
      .paypal img {
        display: block;
        max-width: 100%;
        height: auto;
      }
      .shop-message {
        margin-top: 1.5rem;
      }
    `,
  ];

  #unsubscribe = [];
  #checkoutReported = false;

  constructor() {
    super();
    this.billingPage = '';
    this.canceledPage = '';
    this.invoiceUrl = '/rechnung/';
    this.loginUrl = '/login/';
    this.dataProtectionUrl = '/datenschutz/';
    this.paypalImage = '/images/paypal-de.png';
    this.heading = '';
    this._state = 'loading';
    this._error = '';
    this._account = null;
    this._mode = 'saved';
    this._selectedId = '';
    this._form = emptyShipping();
    this._createAccount = false;
    this._busy = false;
    this._accountExists = false;
    // null heisst "noch nicht bekannt" — der Kaufen-Knopf bleibt so lange
    // gesperrt. 0 heisst nachweislich leer.
    this._count = null;
  }

  connectedCallback() {
    super.connectedCallback();
    this.#unsubscribe.push(
      on(SHOP_CART_CHANGED, (event) => this.#cartChanged(event.detail)),
      // Nach dem Anlegen eines Kontos meldet <shop-register> die Anmeldung,
      // bevor es die Seite wechselt. Falls der Wechsel ausbleibt, steht hier
      // trotzdem der richtige Zweig.
      on(SHOP_AUTH_CHANGED, () => this.#load()),
    );
    this.#load();
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    for (const off of this.#unsubscribe) off();
    this.#unsubscribe = [];
  }

  // ---------------------------------------------------------------- Daten

  #cartChanged(detail) {
    if (!detail || typeof detail.count !== 'number') return;
    this._count = detail.count;
    if (detail.cart && !this.#checkoutReported) {
      this.#checkoutReported = true;
      gtmBeginCheckout(detail.cart);
    }
  }

  async #load() {
    this._state = 'loading';
    this._error = '';
    try {
      // `lang` steuert die Anrede-Liste (shop.account.php:1201).
      const data = await apiRequest('billingAndShipping', {
        lang: document.documentElement.lang,
      });
      this._account = checkoutAccount(data);
      this._mode = 'saved';
      this._selectedId = this._account.defaultShipping ? this._account.defaultShipping.id : '';
      this._form = emptyShipping();
      this._state = 'ready';
    } catch (error) {
      this._error = t(error.code || 'SHOP_API_ERROR');
      this._state = 'error';
    }
  }

  // ------------------------------------------------------------ Aktionen

  /** Die Adresse, die gerade gilt — null heisst "wie die Rechnungsadresse". */
  #currentShipping() {
    if (this._mode === 'new') return this._form;
    if (!this._selectedId) return null;
    return (
      this._account.addresses.find((address) => address.id === this._selectedId) ||
      this._account.defaultShipping
    );
  }

  #prefill(id) {
    const address = this._account.addresses.find((a) => a.id === id);
    this._form = address ? { ...address } : emptyShipping();
  }

  #set(field, value) {
    this._form = { ...this._form, [field]: value };
  }

  /** Erstes fehlendes Pflichtfeld der neuen Lieferadresse, sonst ''. */
  #missing() {
    const form = this._form;
    if (!form.name.trim()) return t('register.name');
    if (!form.street.trim()) return t('register.street');
    if (!form.city.trim()) return t('register.city');
    if (!form.zipcode.trim()) return t('register.postcode');
    if (!form.country.trim()) return t('register.country');
    return '';
  }

  async #buy() {
    if (this._busy || this._count === 0) return;
    this._error = '';
    this._accountExists = false;

    const guest = !this._account.registered;
    const register = guest ? this.$('guest') : null;
    let addresses;

    if (guest) {
      if (!register || !register.validate()) return;
      addresses = guestPayload(register.values());
    } else if (this._mode === 'new') {
      const missing = this.#missing();
      if (missing) {
        this._error = t('register.required') + missing;
        return;
      }
      addresses = shippingPayload({ mode: 'new', address: this._form });
    } else {
      addresses = shippingPayload({ mode: 'saved', addressId: this._selectedId });
    }

    this._busy = true;
    try {
      // Reihenfolge wie bisher: erst der Kundendatensatz, dann die Rechnung.
      // Ohne ihn haette die Rechnung keine customer_id.
      if (guest) {
        const created = await register.register();
        // registerAccount legt eine abweichende Lieferadresse bereits an und
        // meldet deren Id zurueck. Ohne sie ginge invoicing() von "neu
        // anlegen" aus und schriebe eine zweite, gleichlautende Zeile.
        if (created && created.shipto_id) {
          addresses.shipping = { default: false, id: String(created.shipto_id) };
        }
      }
      // `lang` wird nicht mitgeschickt: invoicing() in shop.account.php liest
      // nur guest und adresses — und `guest` selbst wertet es nie aus.
      const data = await apiRequest('invoicing', { guest, adresses: addresses });
      window.location.href = this.#invoiceHref(data);
    } catch (error) {
      this._busy = false;
      // Der haeufigste Fall: ein Stammkunde bestellt als Gast mit der
      // E-Mail, unter der er schon ein Konto hat. registerAccount lehnt das
      // ab (shop.account.php:572). Die alte Seite zeigte dazu nur
      // "Das Kundenkonto existiert bereits." ohne Weg nach vorn.
      if (error.code === 'ACCOUNT_EXISTS') {
        this._accountExists = true;
        this._error = t('register.accountExistsHint');
      } else {
        this._error = t(error.code || 'SHOP_API_ERROR');
      }
    }
  }

  /**
   * Ziel nach der Bestellung.
   *
   * mail=error statt eines alert()-Fensters: invoicing() meldet, ob die
   * Rechnungsmail hinausging (email_status). Das alte account.js zeigte dafuer
   * einen Systemdialog, den man wegklickt und danach nicht mehr sieht — die
   * Rechnungsseite weist stattdessen auf den Download hin.
   */
  #invoiceHref(data) {
    const params = new URLSearchParams({ link: (data && data.ar_link) || '' });
    if (!data || data.email_status !== 'success') params.set('mail', 'error');
    return `${this.invoiceUrl}?${params}#focus`;
  }

  #paypalUrl() {
    const query = new URLSearchParams({
      action: 'beginPayment',
      bill: this.billingPage,
      canceled: this.canceledPage,
    });
    return `/shop-api/?${query}`;
  }

  // ------------------------------------------------------------- Ausgabe

  render() {
    if (this._state === 'loading') {
      return html`<p class=${this.cls('muted')} role="status">${t('checkout.loading')}</p>`;
    }
    if (this._state === 'error') {
      return html`
        <div class=${this.cls('alertError')} role="alert">${this._error}</div>
        <p class="actions">
          <button type="button" class=${this.cls('button')} @click=${() => this.#load()}>
            ${t('account.retry')}
          </button>
        </p>
      `;
    }

    return html`
      ${this.heading ? html`<h2 class=${this.cls('heading')}>${this.heading}</h2>` : nothing}
      <div class="layout">
        <section class="panel" part="addresses">
          <h2 class="section-title ${this.cls('heading')}">${t('checkout.addresses')}</h2>
          ${this._account.registered ? this.#renderRegistered() : this.#renderGuest()}
        </section>
        <section part="cart">
          <h2 class="section-title ${this.cls('heading')}">${t('checkout.cart')}</h2>
          <shop-cart mode="checkout" continue-url="/"></shop-cart>
          ${this.#renderBuy()}
        </section>
      </div>
    `;
  }

  // ---- angemeldet ----

  #renderRegistered() {
    const billing = this._account.billing;
    return html`
      <div class="block">
        <p class="block-title">${t('checkout.billing')}</p>
        <div class="address">
          <p>${billing.name}</p>
          <p>${billing.street}</p>
          <p>${cityLine(billing)}</p>
        </div>
      </div>

      <div class="block">
        <p class="block-title">${t('checkout.delivery')}</p>
        ${this._mode === 'new' ? this.#renderNewAddress() : this.#renderPickAddress()}
      </div>
    `;
  }

  #renderPickAddress() {
    const address = this.#currentShipping();
    const defaultId = this._account.defaultShipping ? this._account.defaultShipping.id : '';
    // Die Standardadresse ist selbst eine shipto-Zeile und steckt damit auch
    // in der Liste. Sie einmal auszulassen verhindert, dass sie zweimal zur
    // Auswahl steht — im alten Formular tat sie das.
    const others = this._account.addresses.filter((a) => a.id !== defaultId);

    return html`
      <div class="address">
        ${address
          ? html`
              <p>${address.name}</p>
              <p>${address.street}</p>
              <p>${cityLine(address)}</p>
            `
          : html`<p>${t('checkout.deliveryIsBilling')}</p>`}
      </div>

      ${others.length
        ? html`
            <div class="choose field">
              <label class=${this.cls('label')} for="saved">${t('checkout.saved')}</label>
              <select
                class=${this.cls('select')}
                id="saved"
                @change=${(event) => (this._selectedId = event.target.value)}
              >
                <option value=${defaultId} .selected=${this._selectedId === defaultId}>
                  ${defaultId ? t('checkout.default') : t('checkout.deliveryIsBilling')}
                </option>
                ${repeat(
                  others,
                  (a) => a.id,
                  (a) => html`
                    <option value=${a.id} .selected=${this._selectedId === a.id}>${a.name}</option>
                  `,
                )}
              </select>
            </div>
          `
        : nothing}

      <div class="actions">
        <button
          type="button"
          class=${this.cls('buttonSecondary')}
          @click=${() => {
            this._form = emptyShipping();
            this._mode = 'new';
          }}
        >
          ${t('checkout.newAddress')}
        </button>
      </div>
    `;
  }

  #renderNewAddress() {
    return html`
      <p class="block-title">${t('checkout.newAddressTitle')}</p>

      ${this._account.addresses.length
        ? html`
            <div class="choose field">
              <label class=${this.cls('label')} for="prefill">${t('checkout.prefill')}</label>
              <select
                class=${this.cls('select')}
                id="prefill"
                @change=${(event) => this.#prefill(event.target.value)}
              >
                <option value=""></option>
                ${repeat(
                  this._account.addresses,
                  (a) => a.id,
                  (a) => html`<option value=${a.id}>${a.name}</option>`,
                )}
              </select>
            </div>
          `
        : nothing}

      <div class="fields choose">
        ${this.#input('new-name', t('register.name'), 'name', { required: true, autocomplete: 'shipping name' })}
        ${this.#input('new-street', t('register.street'), 'street', { required: true, autocomplete: 'shipping street-address' })}
        ${this.#input('new-city', t('register.city'), 'city', { required: true, autocomplete: 'shipping address-level2' })}
        ${this.#input('new-postcode', t('register.postcode'), 'zipcode', { required: true, autocomplete: 'shipping postal-code' })}
        ${this.#input('new-country', t('register.country'), 'country', { required: true, autocomplete: 'shipping country-name' })}
        ${this.#input('new-email', t('register.email'), 'email', { type: 'email', autocomplete: 'shipping email' })}
        ${this.#input('new-phone', t('register.phone'), 'phone', { type: 'tel', autocomplete: 'shipping tel' })}
      </div>

      <div class="actions">
        <button
          type="button"
          class=${this.cls('buttonSecondary')}
          @click=${() => {
            this._mode = 'saved';
            this._form = emptyShipping();
          }}
        >
          ${t('checkout.cancel')}
        </button>
      </div>
    `;
  }

  #input(id, label, field, options = {}) {
    return html`
      <div class="field" part="field">
        <label class=${this.cls('label')} part="label" for=${id}>
          ${label}${options.required ? '*' : ''}
        </label>
        <input
          class=${this.cls('input')}
          part="input"
          id=${id}
          name=${id}
          type=${options.type || 'text'}
          autocomplete=${options.autocomplete || 'off'}
          .value=${this._form[field]}
          @input=${(event) => this.#set(field, event.target.value)}
          ?required=${!!options.required}
        />
      </div>
    `;
  }

  // ---- Gast ----

  #renderGuest() {
    return html`
      <div class="account-switch">
        <input
          class=${this.cls('check')}
          type="checkbox"
          id="create-account"
          .checked=${this._createAccount}
          @change=${(event) => (this._createAccount = event.target.checked)}
        />
        <label class=${this.cls('label')} for="create-account">${t('checkout.createAccount')}</label>
      </div>

      <shop-register
        id="guest"
        mode=${this._createAccount ? 'account' : 'guest'}
        login-url=${this.loginUrl}
        data-protection-url=${this.dataProtectionUrl}
        redirect-url=${`${window.location.pathname}#focus`}
      ></shop-register>
    `;
  }

  // ---- Kaufen ----

  #renderBuy() {
    if (this._createAccount) {
      return html`<p class="note ${this.cls('muted')}">${t('checkout.createAccountHint')}</p>`;
    }
    return html`
      <div class="buy">
        ${this._error
          ? html`
              <div class="shop-message ${this.cls('alertError')}" role="alert">
                ${this._error}
                ${this._accountExists
                  ? html`
                      <a class=${this.cls('link')} href=${this.loginUrl}>
                        ${t('register.loginLink')}
                      </a>
                    `
                  : nothing}
              </div>
            `
          : nothing}
        ${this._count === 0
          ? html`<p>${t('checkout.emptyCart')}</p>`
          : html`
              <div class="actions">
                <button
                  type="button"
                  class=${this.cls('buttonPrimary')}
                  ?disabled=${this._busy || this._count === null}
                  @click=${() => this.#buy()}
                >
                  ${this._busy ? t('checkout.buying') : t('checkout.buy')}
                </button>
                <a class="paypal" href=${this.#paypalUrl()} aria-label=${t('cart.paypal')}>
                  <img src=${this.paypalImage} alt=${t('cart.paypal')} />
                </a>
              </div>
              <p class="note ${this.cls('muted')}">${t('checkout.buyNote')}</p>
            `}
      </div>
    `;
  }
}

customElements.define('shop-checkout', ShopCheckout);
