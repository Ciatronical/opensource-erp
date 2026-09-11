import { html, css, nothing } from 'lit';
import { ShopElement } from '../core/base.js';
import { getContext } from '../core/api.js';
import { on, SHOP_ACCOUNT_LOADED, SHOP_AUTH_CHANGED } from '../core/bus.js';
import { greeting } from '../core/account-data.js';
import { t } from '../core/i18n.js';

/**
 * <shop-account-nav current="overview"></shop-account-nav>
 *
 * Ersetzt shortcodes/personal-data-nav.html.
 *
 * Zwei Dinge sind anders als vorher:
 *
 * 1. Der alte Shortcode oeffnete <div class="row"><div class="col-md-3">…
 *    und liess beide Container OFFEN — geschlossen wurden sie erst vom
 *    jeweils folgenden Inhalts-Shortcode. Wer die beiden vertauschte oder
 *    einen davon einzeln benutzte, bekam kaputtes HTML. Hier ist jedes
 *    Element fuer sich vollstaendig; das Zweispalten-Layout macht der
 *    Shortcode shop-account.html drumherum, im Light DOM.
 *
 * 2. Die Begruessung holte sich jede der vier Konto-Funktionen selbst und
 *    schrieb sie in #nav-box-greeting. Jetzt meldet das Panel Name und
 *    Anrede ueber shop:account-loaded — eine Anfrage weniger und kein
 *    Zugriff mehr auf fremdes Markup.
 */
export class ShopAccountNav extends ShopElement {
  static properties = {
    current: { type: String },
    overviewUrl: { type: String, attribute: 'overview-url' },
    profileUrl: { type: String, attribute: 'profile-url' },
    addressUrl: { type: String, attribute: 'address-url' },
    orderUrl: { type: String, attribute: 'order-url' },
    paymentUrl: { type: String, attribute: 'payment-url' },
    // Die alte Navigation kannte die Zahlungsarten nicht; erreichbar war die
    // Seite nur ueber "Bearbeiten" auf der Uebersicht. Der Eintrag laesst
    // sich einschalten, bleibt aber aus, damit sich die Navigation nicht
    // ungefragt aendert.
    showPayment: { type: Boolean, attribute: 'show-payment' },
    _greeting: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      ul {
        list-style: none;
        margin: 0;
        padding: 0;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        overflow: hidden;
      }
      li + li {
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      li {
        padding: 0.75rem 1rem;
      }
      .greeting {
        font-weight: 600;
        background: var(--shop-input-bg, #f8f9fa);
        min-height: 1.5em;
      }
      a {
        color: inherit;
      }
      [aria-current] {
        font-weight: 600;
      }
    `,
  ];

  #unsubscribe = [];

  constructor() {
    super();
    this.current = '';
    this.overviewUrl = '/persönliche-daten/';
    this.profileUrl = '/persönliches-profil/';
    this.addressUrl = '/adressen/';
    this.orderUrl = '/bestellungen/';
    this.paymentUrl = '/zahlungsarten/';
    this.showPayment = false;
    this._greeting = '';
  }

  connectedCallback() {
    super.connectedCallback();
    this.#unsubscribe.push(
      on(SHOP_ACCOUNT_LOADED, (event) => {
        const detail = event.detail || {};
        this._greeting = greeting(detail.salutation, detail.name);
      })
    );
    // Nach dem Abmelden in einem anderen Widget bleibt sonst der Name stehen.
    this.#unsubscribe.push(
      on(SHOP_AUTH_CHANGED, (event) => {
        if (event.detail && event.detail.account === false) this._greeting = '';
      })
    );
    // Ist niemand angemeldet, meldet auch kein Panel etwas — dann bleibt die
    // Zeile leer statt auf eine Begruessung zu warten, die nie kommt.
    getContext()
      .then((context) => {
        if (!context || !context.account) this._greeting = '';
      })
      .catch(() => {});
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    for (const off of this.#unsubscribe) off();
    this.#unsubscribe = [];
  }

  #items() {
    const items = [
      { key: 'overview', url: this.overviewUrl, label: t('account.nav.overview') },
      { key: 'profile', url: this.profileUrl, label: t('account.nav.profile') },
      { key: 'address', url: this.addressUrl, label: t('account.nav.address') },
      { key: 'order', url: this.orderUrl, label: t('account.nav.order') },
    ];
    if (this.showPayment) {
      items.push({ key: 'payment', url: this.paymentUrl, label: t('account.nav.payment') });
    }
    return items;
  }

  render() {
    return html`
      <nav part="nav" aria-label=${t('account.nav.label')}>
        <ul>
          <li class="greeting" part="greeting">
            ${this._greeting ? `${t('account.greeting')}, ${this._greeting}` : nothing}
          </li>
          ${this.#items().map(
            (item) => html`
              <li part="item">
                ${item.key === this.current
                  ? html`<span aria-current="page">${item.label}</span>`
                  : html`<a href=${item.url}>${item.label}</a>`}
              </li>
            `
          )}
        </ul>
      </nav>
    `;
  }
}

customElements.define('shop-account-nav', ShopAccountNav);
