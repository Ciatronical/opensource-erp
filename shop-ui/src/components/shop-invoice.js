import { html, css, nothing } from 'lit';
import { ShopElement } from '../core/base.js';
import { apiRequest, API_URL } from '../core/api.js';
import { t } from '../core/i18n.js';
import { formatPrice } from '../core/money.js';
import { cityLine } from '../core/account-data.js';
import { invoiceLink } from '../core/checkout-data.js';
import { gtmPurchase } from '../core/gtm.js';

/**
 * <shop-invoice></shop-invoice>
 *
 * Die Seite nach der Bestellung (/rechnung/?link=…). Ersetzt
 * shortcodes/invoice.html + getInvoiceSummary() aus account.js.
 *
 * DER LINK IST DER SCHLUESSEL
 * ar_link ist eine UUID aus ar_link_hugoshop und der einzige Nachweis, dass
 * jemand diese Rechnung sehen darf — auch ein Gast ohne Konto kommt so an
 * sein PDF. Fehlt der Parameter, wird gar nicht erst gefragt: das alte
 * getInvoiceSummary() schickte dann ar_link=undefined und bekam
 * INVOICE_LINK_NOT_FOUND, ohne dem Besucher etwas zu sagen — die Seite blieb
 * einfach leer (das ganze Markup stand auf display:none).
 *
 * mail=error setzt <shop-checkout>, wenn invoicing() den Mailversand nicht
 * bestaetigt hat.
 */
export class ShopInvoice extends ShopElement {
  static properties = {
    apiUrl: { type: String, attribute: 'api-url' },
    ordersUrl: { type: String, attribute: 'orders-url' },
    continueUrl: { type: String, attribute: 'continue-url' },
    heading: { type: String },
    _state: { state: true },
    _error: { state: true },
    _summary: { state: true },
    _mailFailed: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      .block {
        margin-top: 2rem;
      }
      .block-title {
        font-weight: 600;
        margin-bottom: 0.5rem;
      }
      .lead {
        font-size: 1.125rem;
      }
      dl.bank {
        display: grid;
        grid-template-columns: minmax(0, 12rem) minmax(0, 1fr);
        gap: 0.35rem 1rem;
        margin: 0;
      }
      dl.bank dt {
        font-weight: 400;
      }
      dl.bank dd {
        margin: 0;
        font-weight: 600;
      }
      @media (max-width: 32rem) {
        dl.bank {
          grid-template-columns: minmax(0, 1fr);
        }
        dl.bank dd {
          margin-bottom: 0.5rem;
        }
      }
      .address p {
        margin: 0.25rem 0;
        font-weight: 600;
      }
      form {
        margin-top: 1rem;
      }
    `,
  ];

  constructor() {
    super();
    this.apiUrl = API_URL;
    this.ordersUrl = '/bestellungen/';
    this.continueUrl = '/';
    this.heading = '';
    this._state = 'loading';
    this._error = '';
    this._summary = null;
    this._mailFailed = false;
  }

  connectedCallback() {
    super.connectedCallback();
    this.#load();
  }

  async #load() {
    const link = invoiceLink(window.location.search);
    this._mailFailed = new URLSearchParams(window.location.search).get('mail') === 'error';
    if (!link) {
      this._state = 'missing';
      return;
    }
    this._state = 'loading';
    this._error = '';
    try {
      const data = await apiRequest('getInvoiceSummary', { ar_link: link });
      this._summary = data;
      this._state = 'ready';
      // Nach dem Rendern und ohne await: ein fehlgeschlagenes Tracking darf
      // die Bestaetigung nicht aufhalten.
      gtmPurchase(data);
    } catch (error) {
      this._error = t(error.code || 'SHOP_API_ERROR');
      this._state = 'error';
    }
  }

  render() {
    if (this._state === 'loading') {
      return html`<p class=${this.cls('muted')} role="status">${t('invoice.loading')}</p>`;
    }
    if (this._state === 'missing') {
      return html`
        <p>${t('invoice.noLink')}</p>
        <p><a class=${this.cls('link')} href=${this.ordersUrl}>${t('invoice.toOrders')}</a></p>
      `;
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

    const summary = this._summary;
    return html`
      ${this.heading ? html`<h2 class=${this.cls('heading')}>${this.heading}</h2>` : nothing}
      ${this._mailFailed
        ? html`<div class=${this.cls('alertError')} role="alert">${t('invoice.mailFailed')}</div>`
        : html`<p class="lead">${t('invoice.mailTo')} ${summary.email}</p>`}
      <p>${t('invoice.downloadHint')}</p>

      <form action=${`${this.apiUrl}?action=downloadInvoiceLink`} method="post" part="download">
        <input type="hidden" name="ar-link" value=${summary.ar_link || ''} />
        <input type="hidden" name="content-type" value="application/pdf" />
        <button class=${this.cls('button')} type="submit">${t('invoice.download')}</button>
      </form>

      ${this.#renderPayment(summary)} ${this.#renderShipping(summary)}
    `;
  }

  /**
   * Drei Zustaende, nicht zwei.
   *
   * Bisher stand hier `paid ? nothing : Bankverbindung`. Das reicht nicht,
   * seit PayPal auch schwebende Zahlungen zulaesst
   * (PAYPAL_PAYMENT_METHOD_PREFERENCE = UNRESTRICTED): der Kunde hat dann
   * bezahlt, das Geld ist nur noch unterwegs. Ihn in dieser Lage zur
   * Ueberweisung aufzufordern, waere die Bitte, ein zweites Mal zu zahlen.
   */
  #renderPayment(summary) {
    if (summary.paid) return nothing;
    if (summary.pending) return this.#renderPending();
    return this.#renderBank(summary);
  }

  /** Zahlung laeuft noch bei PayPal — nichts zu tun, nur zu warten. */
  #renderPending() {
    return html`
      <div class="block" part="pending">
        <div class=${this.cls('alertInfo')} role="status">
          <p class="block-title">${t('invoice.pendingTitle')}</p>
          <p>${t('invoice.pendingHint')}</p>
        </div>
      </div>
    `;
  }

  /** Nur wenn nicht per PayPal bezahlt wurde — dann steht die Ueberweisung an. */
  #renderBank(summary) {
    return html`
      <div class="block" part="bank">
        <p class="block-title">${t('invoice.transferHint')}</p>
        <dl class="bank">
          <dt>${t('invoice.bank')}</dt>
          <dd>${summary.payment_term_bank}</dd>
          <dt>${t('invoice.iban')}</dt>
          <dd>${summary.payment_term_iban}</dd>
          <dt>${t('invoice.bic')}</dt>
          <dd>${summary.payment_term_bic}</dd>
          <dt>${t('invoice.purpose')}</dt>
          <dd>${summary.payment_term_purpose}</dd>
          <dt>${t('invoice.owner')}</dt>
          <dd>${summary.payment_term_account_owner}</dd>
          <dt>${t('invoice.amount')}</dt>
          <dd>
            ${formatPrice(summary.payment_term_amount)} ${summary.payment_term_currency || ''}
          </dd>
        </dl>
      </div>
    `;
  }

  /**
   * Das Backend faellt auf die Rechnungsadresse zurueck, wenn die Rechnung
   * keine shipto_id hat — die Anschrift ist also immer gefuellt.
   */
  #renderShipping(summary) {
    const address = summary.shipping || {};
    return html`
      <div class="block" part="shipping">
        <p class="block-title">${t('invoice.deliveryHint')}</p>
        <div class="address">
          <p>${address.name || ''}</p>
          <p>${address.street || ''}</p>
          <p>${cityLine({ zipcode: address.zipcode || '', city: address.city || '', country: address.country || '' })}</p>
        </div>
      </div>
      <p class="block">
        <a class=${this.cls('link')} href=${this.continueUrl}>${t('cart.continue')}</a>
      </p>
    `;
  }
}

customElements.define('shop-invoice', ShopInvoice);
