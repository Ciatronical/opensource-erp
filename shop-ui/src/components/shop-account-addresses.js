import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { ShopElement } from '../core/base.js';
import { ShopAccountElement } from '../core/account-base.js';
import { apiRequest } from '../core/api.js';
import {
  billingAddress,
  shiptoAddress,
  addressList,
  emptyAddress,
  addressPayload,
  cityLine,
  byName,
} from '../core/account-data.js';
import { t } from '../core/i18n.js';

/**
 * <shop-account-addresses></shop-account-addresses>
 *
 * Ersetzt shortcodes/personal-addresses.html (180 Zeilen Markup mit drei
 * fast identischen Formularen) + personalAddresses() und die fuenf
 * Konstruktorfunktionen aus account.js: deliveryAddressesModel,
 * standardDeliveryAddressView, newDeliveryAddressView,
 * editDeliveryAddressView, deliveryAddressListView.
 *
 * Der Kern der Vereinfachung: es gibt EIN Adressformular, das je nach
 * Zustand anlegt oder bearbeitet. Vorher waren "neu" und "bearbeiten" zwei
 * vollstaendige Formulare mit doppelter Validierung, doppelter
 * Feld-Leerung und doppeltem Ein-/Ausblenden ueber style.display.
 *
 * Mitgenommene Fehler des Vorgaengers:
 *  - newDeliveryAddressView.error() griff auf this.response zu, das nie
 *    gesetzt wurde. Beim ersten Serverfehler warf das Formular selbst eine
 *    TypeError — der eigentliche Fehler blieb unsichtbar.
 *  - Die Liste lag in einem Array, das nach shipto_id indiziert wurde
 *    (`this.list[item.shipto_id]`). Bei einer shipto_id von 4711 legte das
 *    ein Array mit 4712 Plaetzen an, und Object.keys() darauf war die
 *    einzige Art, die Laenge zu bestimmen.
 *  - Nach dem Loeschen der Standardadresse wurde takeBillAddress
 *    abgeschickt und das Ergebnis nicht abgewartet; schlug das fehl, zeigte
 *    die Seite trotzdem den neuen Zustand.
 */
export class ShopAccountAddresses extends ShopAccountElement {
  static properties = {
    _billing: { state: true },
    _addresses: { state: true },
    _defaultId: { state: true },
    // null | { mode: 'new' | 'edit', address }
    _form: { state: true },
    _busy: { state: true },
    _billingMessage: { state: true },
    _formMessage: { state: true },
    _listMessage: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    ShopAccountElement.accountStyles,
    css`
      .default-card {
        max-width: 24rem;
      }
      .badge {
        font-size: 0.875rem;
        min-height: 1.25em;
      }
      .address-lines {
        margin-bottom: 0.5rem;
      }
      .cards {
        margin-top: 1.25rem;
      }
      .used-hint {
        font-size: 0.875rem;
      }
    `,
  ];

  constructor() {
    super();
    this._billing = null;
    this._addresses = [];
    this._defaultFallback = null;
    this._defaultId = '';
    this._form = null;
    this._busy = false;
    this._billingMessage = null;
    this._formMessage = null;
    this._listMessage = null;
  }

  async load() {
    const data = await apiRequest('accountAddresses');
    this._billing = billingAddress(data);
    this._addresses = addressList(data.shipping_addresses);
    // accountAddresses liefert die Standardadresse zusaetzlich flach mit
    // (JOIN ueber customer_ext.hugoshop_shipto_id). Normalerweise steht sie
    // ohnehin in shipping_addresses; zeigt shipto_id ausnahmsweise auf eine
    // Zeile mit fremdem trans_id, waere die Kachel sonst leer.
    this._defaultFallback = data.shiptoname
      ? shiptoAddress({ ...data, shipto_id: data.shipto_default })
      : null;
    this._defaultId =
      data.shipto_default === null || data.shipto_default === undefined
        ? ''
        : String(data.shipto_default);
    this._form = null;
    this._billingMessage = null;
    this._formMessage = null;
    this._listMessage = null;
    this.announce(data.salutation, data.name);
  }

  // ------------------------------------------------------------- Hilfen

  #defaultAddress() {
    if (!this._defaultId) return null;
    return (
      this._addresses.find((address) => address.id === this._defaultId) ||
      this._defaultFallback
    );
  }

  #setBilling(key, value) {
    this._billing = { ...this._billing, [key]: value };
  }

  #setForm(key, value) {
    this._form = { ...this._form, address: { ...this._form.address, [key]: value } };
  }

  #message(text, ok) {
    return { text: t(text), ok };
  }

  #renderMessage(message, role = 'alert') {
    if (!message) return nothing;
    return html`
      <div class="shop-message" role=${role} aria-live="polite">
        <div
          class=${message.ok ? this.cls('alertSuccess') : this.cls('alertError')}
          part=${message.ok ? 'success' : 'error'}
        >
          ${message.text}
        </div>
      </div>
    `;
  }

  #input(id, label, value, onInput, options = {}) {
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
          .value=${value}
          @input=${onInput}
          ?required=${!!options.required}
        />
      </div>
    `;
  }

  /**
   * Gibt den Schluessel des ersten fehlenden Pflichtfeldes zurueck, sonst ''.
   * Reihenfolge wie im alten Formular, damit dieselbe Meldung erscheint.
   */
  #missing(address, withName) {
    if (withName && !address.name.trim()) return 'addresses.nameMissing';
    if (!address.street.trim()) return 'addresses.streetMissing';
    if (!address.city.trim()) return 'addresses.cityMissing';
    if (!address.zipcode.trim()) return 'addresses.postcodeMissing';
    if (!address.country.trim()) return 'addresses.countryMissing';
    return '';
  }

  // ------------------------------------------------------------ Aktionen

  async #saveBilling(event) {
    event.preventDefault();
    if (this._busy) return;
    const missing = this.#missing(this._billing, false);
    if (missing) {
      this._billingMessage = this.#message(missing, false);
      return;
    }
    this._busy = true;
    this._billingMessage = null;
    try {
      await apiRequest('updateAddress', {
        street: this._billing.street,
        city: this._billing.city,
        zipcode: this._billing.zipcode,
        country: this._billing.country,
      });
      this._billingMessage = this.#message('addresses.saved', true);
    } catch (error) {
      this._billingMessage = this.#message((error && error.code) || 'SHOP_API_ERROR', false);
    } finally {
      this._busy = false;
    }
  }

  async #saveAddress(event) {
    event.preventDefault();
    if (this._busy || !this._form) return;
    const { mode, address } = this._form;
    const missing = this.#missing(address, true);
    if (missing) {
      this._formMessage = this.#message(missing, false);
      return;
    }

    this._busy = true;
    this._formMessage = null;
    try {
      if (mode === 'new') {
        // newDeliveryAddress liefert nur die neue shipto_id zurueck; die
        // uebrigen Felder stehen bereits im Formular.
        const result = await apiRequest('newDeliveryAddress', addressPayload(address));
        const created = { ...address, id: String((result && result.shipto_id) || ''), used: false };
        this._addresses = [...this._addresses, created].sort(byName);
      } else {
        await apiRequest('updateDeliveryAddress', {
          shipto_id: address.id,
          ...addressPayload(address),
        });
        this._addresses = this._addresses
          .map((item) => (item.id === address.id ? { ...address, used: item.used } : item))
          .sort(byName);
      }
      this._form = null;
    } catch (error) {
      this._formMessage = this.#message((error && error.code) || 'SHOP_API_ERROR', false);
    } finally {
      this._busy = false;
    }
  }

  async #remove(address) {
    if (this._busy) return;
    if (!window.confirm(t('addresses.confirmRemove'))) return;

    this._busy = true;
    this._listMessage = null;
    try {
      // Erst den Verweis loesen, dann die Zeile loeschen — und zwar
      // nacheinander, nicht nebeneinander. In der Produktionsdatenbank
      // steht customer_ext_shipto_id_fkey auf ON DELETE CASCADE: ein
      // DELETE auf shipto reisst dort den Kundendatensatz mit, solange er
      // noch darauf zeigt. Das alte account.js hat takeBillAddress zwar
      // auch abgeschickt, das Ergebnis aber nicht abgewartet.
      if (this._defaultId === address.id) {
        await apiRequest('takeBillAddress');
        this._defaultId = '';
      }
      await apiRequest('removeDeliveryAddress', { shipto_id: address.id });
      this._addresses = this._addresses.filter((item) => item.id !== address.id);
      if (this._form && this._form.address.id === address.id) this._form = null;
    } catch (error) {
      this._listMessage = this.#message((error && error.code) || 'SHOP_API_ERROR', false);
    } finally {
      this._busy = false;
    }
  }

  async #makeDefault(address) {
    if (this._busy) return;
    this._busy = true;
    this._listMessage = null;
    try {
      await apiRequest('standardDeliveryAddress', { shipto_id: address.id });
      this._defaultId = address.id;
    } catch (error) {
      this._listMessage = this.#message((error && error.code) || 'SHOP_API_ERROR', false);
    } finally {
      this._busy = false;
    }
  }

  async #takeBilling() {
    if (this._busy) return;
    this._busy = true;
    this._listMessage = null;
    try {
      await apiRequest('takeBillAddress');
      this._defaultId = '';
    } catch (error) {
      this._listMessage = this.#message((error && error.code) || 'SHOP_API_ERROR', false);
    } finally {
      this._busy = false;
    }
  }

  // -------------------------------------------------------------- Anzeige

  #renderBillingForm() {
    const billing = this._billing;
    return html`
      <form class=${this.cls('form')} part="form" @submit=${this.#saveBilling} novalidate>
        <div class="section" part="section">${t('addresses.billing')}</div>
        <div class="fields">
          ${this.#input(
            'street',
            t('register.street'),
            billing.street,
            (e) => this.#setBilling('street', e.target.value),
            { required: true, autocomplete: 'street-address' }
          )}
          ${this.#input(
            'city',
            t('register.city'),
            billing.city,
            (e) => this.#setBilling('city', e.target.value),
            { required: true, autocomplete: 'address-level2' }
          )}
          ${this.#input(
            'postcode',
            t('register.postcode'),
            billing.zipcode,
            (e) => this.#setBilling('zipcode', e.target.value),
            { required: true, autocomplete: 'postal-code' }
          )}
          ${this.#input(
            'country',
            t('register.country'),
            billing.country,
            (e) => this.#setBilling('country', e.target.value),
            { required: true, autocomplete: 'country-name' }
          )}
        </div>
        <div class="actions">
          <button
            class=${this.cls('buttonSecondary')}
            part="submit"
            type="submit"
            ?disabled=${this._busy}
          >
            ${this._busy ? t('account.saving') : t('account.save')}
          </button>
        </div>
        ${this.#renderMessage(this._billingMessage)}
      </form>
    `;
  }

  #renderDefault() {
    const address = this.#defaultAddress();
    if (!address) {
      return html`<p class="hint">${t('overview.deliveryIsBilling')}</p>`;
    }
    return html`
      <div class="card default-card" part="default-address">
        <div class="card-title">${address.name}</div>
        <div>${address.street}</div>
        <div>${cityLine(address)}</div>
        ${address.email ? html`<div>${address.email}</div>` : nothing}
        ${address.phone ? html`<div>${address.phone}</div>` : nothing}
        <div class="actions">
          <button
            class=${this.cls('button')}
            part="take-billing"
            ?disabled=${this._busy}
            @click=${() => this.#takeBilling()}
          >
            ${t('addresses.takeBilling')}
          </button>
        </div>
      </div>
    `;
  }

  #renderAddressForm() {
    const { mode, address } = this._form;
    return html`
      <form class=${this.cls('form')} part="form" @submit=${this.#saveAddress} novalidate>
        <div class="section" part="section">
          ${mode === 'new' ? t('addresses.new') : t('addresses.edit')}
        </div>
        <div class="fields">
          ${this.#input(
            'delivery-name',
            t('register.name'),
            address.name,
            (e) => this.#setForm('name', e.target.value),
            { required: true, autocomplete: 'name' }
          )}
          ${this.#input(
            'delivery-street',
            t('register.street'),
            address.street,
            (e) => this.#setForm('street', e.target.value),
            { required: true, autocomplete: 'street-address' }
          )}
          ${this.#input(
            'delivery-city',
            t('register.city'),
            address.city,
            (e) => this.#setForm('city', e.target.value),
            { required: true, autocomplete: 'address-level2' }
          )}
          ${this.#input(
            'delivery-postcode',
            t('register.postcode'),
            address.zipcode,
            (e) => this.#setForm('zipcode', e.target.value),
            { required: true, autocomplete: 'postal-code' }
          )}
          ${this.#input(
            'delivery-country',
            t('register.country'),
            address.country,
            (e) => this.#setForm('country', e.target.value),
            { required: true, autocomplete: 'country-name' }
          )}
          ${this.#input(
            'delivery-email',
            t('register.email'),
            address.email,
            (e) => this.#setForm('email', e.target.value),
            { type: 'email', autocomplete: 'email' }
          )}
          ${this.#input(
            'delivery-phone',
            t('register.phone'),
            address.phone,
            (e) => this.#setForm('phone', e.target.value),
            { type: 'tel', autocomplete: 'tel' }
          )}
        </div>
        <div class="actions">
          <button
            class=${this.cls('buttonSecondary')}
            part="submit"
            type="submit"
            ?disabled=${this._busy}
          >
            ${mode === 'new' ? t('addresses.add') : t('addresses.save')}
          </button>
          <button
            class=${this.cls('button')}
            part="cancel"
            type="button"
            @click=${() => {
              this._form = null;
              this._formMessage = null;
            }}
          >
            ${t('account.cancel')}
          </button>
        </div>
        ${this.#renderMessage(this._formMessage)}
      </form>
    `;
  }

  #renderCard(address) {
    const isDefault = address.id === this._defaultId;
    return html`
      <div class="card" part="address">
        <div class="card-title">${address.name}</div>
        <div class="badge ${this.cls('muted')}">
          ${isDefault ? t('addresses.isDefault') : nothing}
        </div>
        <div class="address-lines">
          <div>${address.street}</div>
          <div>${cityLine(address)}</div>
        </div>
        <div class="actions">
          <button
            class=${this.cls('button')}
            part="edit"
            ?disabled=${this._busy}
            @click=${() => {
              this._form = { mode: 'edit', address: { ...address } };
              this._formMessage = null;
            }}
          >
            ${t('account.edit')}
          </button>
          ${address.used
            ? nothing
            : html`<button
                class=${this.cls('button')}
                part="remove"
                ?disabled=${this._busy}
                @click=${() => this.#remove(address)}
              >
                ${t('addresses.remove')}
              </button>`}
          ${isDefault
            ? nothing
            : html`<button
                class=${this.cls('button')}
                part="make-default"
                ?disabled=${this._busy}
                @click=${() => this.#makeDefault(address)}
              >
                ${t('addresses.makeDefault')}
              </button>`}
        </div>
        ${address.used
          ? html`<div class="used-hint ${this.cls('muted')}">${t('addresses.usedHint')}</div>`
          : nothing}
      </div>
    `;
  }

  renderAccount() {
    if (!this._billing) return nothing;

    return html`
      ${this.#renderBillingForm()}

      <div class="section" part="section">${t('addresses.standardDelivery')}</div>
      ${this.#renderDefault()}

      <div class="section" part="section">${t('addresses.available')}</div>
      ${this._form
        ? this.#renderAddressForm()
        : html`<div class="actions" style="margin-top:0">
            <button
              class=${this.cls('button')}
              part="add"
              ?disabled=${this._busy}
              @click=${() => {
                this._form = { mode: 'new', address: emptyAddress() };
                this._formMessage = null;
              }}
            >
              ${t('addresses.add')}
            </button>
          </div>`}
      ${this.#renderMessage(this._listMessage)}
      ${this._addresses.length
        ? html`<div class="cards" part="addresses">
            ${repeat(
              this._addresses,
              (address) => address.id,
              (address) => this.#renderCard(address)
            )}
          </div>`
        : html`<p class="hint ${this.cls('muted')}">${t('addresses.none')}</p>`}
    `;
  }
}

customElements.define('shop-account-addresses', ShopAccountAddresses);
