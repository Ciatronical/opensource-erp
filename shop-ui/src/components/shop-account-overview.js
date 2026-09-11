import { html, css, nothing } from 'lit';
import { ShopElement } from '../core/base.js';
import { ShopAccountElement } from '../core/account-base.js';
import { apiRequest } from '../core/api.js';
import { billingAddress, shiptoAddressUnderscore, cityLine } from '../core/account-data.js';
import { t } from '../core/i18n.js';

/**
 * <shop-account-overview></shop-account-overview>
 *
 * Ersetzt shortcodes/personal-overview.html + personalOverview() aus
 * account.js.
 *
 * Die vier Kacheln sind ein Grid im ShadowRoot, kein verschachteltes
 * row/col-Geflecht mehr. Das geht, weil die Shadow-Grenze hier ueber dem
 * Grid-Container liegt und nicht zwischen ihm und seinen Kindern.
 */
export class ShopAccountOverview extends ShopAccountElement {
  static properties = {
    profileUrl: { type: String, attribute: 'profile-url' },
    passwordUrl: { type: String, attribute: 'password-url' },
    addressUrl: { type: String, attribute: 'address-url' },
    deliveryUrl: { type: String, attribute: 'delivery-url' },
    paymentUrl: { type: String, attribute: 'payment-url' },
    _data: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    ShopAccountElement.accountStyles,
    css`
      .cards {
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr));
      }
      /* description_long kommt aus der Datenbank und ist oft mehrzeilig. */
      .payment-description {
        white-space: pre-line;
      }
    `,
  ];

  constructor() {
    super();
    this.profileUrl = '/persönliches-profil/';
    this.passwordUrl = '';
    this.addressUrl = '/adressen/';
    this.deliveryUrl = '';
    this.paymentUrl = '/zahlungsarten/';
    this._data = null;
  }

  async load() {
    const data = await apiRequest('personalOverview');
    this._data = {
      name: data.name || '',
      email: data.email || '',
      salutation: data.salutation || '',
      billing: billingAddress(data),
      // personalOverview liefert die Standard-Lieferadresse in der
      // Unterschreibweise (shipto_name), accountAddresses ohne Unterstrich.
      shipto: data.shipto_name ? shiptoAddressUnderscore(data) : null,
      payment: data.payment_method || null,
    };
    this.announce(this._data.salutation, this._data.name);
  }

  #card(title, body, links) {
    return html`
      <div class="card" part="card">
        <div class="card-title" part="card-title">${title}</div>
        ${body}
        <div class="actions">
          ${links.map(
            ([label, url]) =>
              html`<a class=${this.cls('button')} part="card-link" href=${url}>${label}</a>`
          )}
        </div>
      </div>
    `;
  }

  renderAccount() {
    const data = this._data;
    if (!data) return nothing;
    const passwordUrl = this.passwordUrl || this.profileUrl;
    const deliveryUrl = this.deliveryUrl || this.addressUrl;

    return html`
      <div class="cards" part="cards">
        ${this.#card(
          t('overview.profile'),
          html`<div>${data.name}</div>
            <div>${data.email}</div>`,
          [
            [t('account.edit'), this.profileUrl],
            [t('overview.changePassword'), passwordUrl],
          ]
        )}
        ${this.#card(
          t('overview.payment'),
          data.payment
            ? html`<div>${data.payment.description}</div>
                <div class="payment-description">${data.payment.description_long}</div>`
            : html`<div>${t('overview.paymentNone')}</div>`,
          [[t('account.edit'), this.paymentUrl]]
        )}
        ${this.#card(
          t('overview.billingAddress'),
          html`<div>${data.billing.street}</div>
            <div>${cityLine(data.billing)}</div>`,
          [[t('account.edit'), this.addressUrl]]
        )}
        ${this.#card(
          t('overview.deliveryAddress'),
          data.shipto
            ? html`<div>${data.shipto.name}</div>
                <div>${data.shipto.street}</div>
                <div>${cityLine(data.shipto)}</div>`
            : html`<div>${t('overview.deliveryIsBilling')}</div>`,
          [[t('account.edit'), deliveryUrl]]
        )}
      </div>
    `;
  }
}

customElements.define('shop-account-overview', ShopAccountOverview);
