import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { ShopElement } from '../core/base.js';
import { ShopAccountElement } from '../core/account-base.js';
import { apiRequest } from '../core/api.js';
import { formatPrice } from '../core/money.js';
import { formatDate } from '../core/dates.js';
import { cityLine } from '../core/account-data.js';
import { t } from '../core/i18n.js';

/**
 * <shop-account-orders></shop-account-orders>
 *
 * Ersetzt shortcodes/personal-order.html + personalOrders() aus account.js.
 *
 * Drei Dinge aus dem Vorgaenger sind bewusst nicht uebernommen:
 *
 *  - Der "Zurueck"-Knopf bekam seinen Listener INNERHALB des Klick-Handlers
 *    einer Bestellung. Nach dem dritten geoeffneten Beleg haing er also
 *    dreimal am selben Knopf.
 *  - Die Positionen wurden mit `innerHTML +=` angehaengt und danach
 *    document.querySelectorAll('.product-link') ueber die GANZE Seite
 *    gebunden — bei jedem Oeffnen erneut, auf denselben Elementen.
 *  - Die DOM-IDs der Positionen kamen aus element.id, das die Abfrage in
 *    getInvoice gar nicht liefert: jede Position hiess "procduct-link-
 *    undefined". Hier wird ueber parts_id und Positionsnummer
 *    geschluesselt.
 *
 * Der Rechnungs-Download bleibt ein echtes Formular mit POST auf
 * /shop-api/?action=downloadInvoice — die Antwort ist ein PDF, kein JSON,
 * und soll im Browser als Download landen.
 */
export class ShopAccountOrders extends ShopAccountElement {
  static properties = {
    apiUrl: { type: String, attribute: 'api-url' },
    productUrl: { type: String, attribute: 'product-url' },
    thumbnailUrl: { type: String, attribute: 'thumbnail-url' },
    _orders: { state: true },
    _detail: { state: true },
    _busy: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    ShopAccountElement.accountStyles,
    css`
      .order {
        padding: 1.25rem;
        margin-bottom: 1rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        cursor: pointer;
      }
      .order-title {
        font-size: 1.05rem;
        font-weight: 600;
        margin: 0 0 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--shop-border-color, #dee2e6);
      }
      .order-line {
        margin-bottom: 0.5rem;
      }
      .detail-head {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
      }
      .facts {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.35rem 1rem;
      }
      .facts dt {
        font-weight: 600;
      }
      .facts dd {
        margin: 0;
      }
      .position {
        display: grid;
        grid-template-columns: minmax(0, 10rem) minmax(0, 1fr);
        gap: 1.25rem;
        align-items: start;
        padding: 1rem;
        margin-bottom: 0.75rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
      }
      @media (max-width: 40rem) {
        .position {
          grid-template-columns: minmax(0, 1fr);
        }
      }
      .thumb {
        display: block;
        width: 100%;
        height: auto;
      }
      .position h3 {
        font-size: 1.05rem;
        margin: 0 0 0.75rem;
      }
      /* Sieht aus wie ein Link, ist aber keiner: das Ziel loest erst
         getProductLink auf. */
      .link-button {
        font: inherit;
        color: inherit;
        background: none;
        border: 0;
        padding: 0;
        text-align: left;
        cursor: pointer;
        text-decoration: underline;
        text-underline-offset: 0.2em;
      }
      .note {
        margin-top: 1.5rem;
      }
      form {
        margin-top: 1rem;
      }
    `,
  ];

  constructor() {
    super();
    this.apiUrl = '/shop-api/';
    this.productUrl = '/produkt/';
    this.thumbnailUrl = '/images/thumbnails/';
    this._orders = [];
    this._detail = null;
    this._busy = false;
  }

  async load() {
    const data = await apiRequest('personalOrders');
    this._orders = (data && data.orders) || [];
    this._detail = null;
    // personalOrders liefert keine Anrede.
    this.announce('', (data && data.name) || '');
  }

  // ------------------------------------------------------------ Aktionen

  /**
   * Die ganze Karte ist anklickbar (bequem mit der Maus), die Ueberschrift
   * ist zusaetzlich ein echter Knopf (erreichbar mit der Tastatur). Ein
   * role="button" auf der Karte waere falsch: darin steckt mit dem
   * Download-Formular schon ein Bedienelement.
   *
   * Der Download-Knopf sitzt innerhalb der anklickbaren Bestellkarte. Statt
   * ihm ein eigenes stopPropagation zu verpassen (und das beim naechsten
   * Bedienelement zu vergessen), wird hier gefragt, ob der Klick ueberhaupt
   * der Karte galt.
   */
  #cardClick(event, id) {
    const path = event.composedPath();
    for (const node of path) {
      if (node === event.currentTarget) break;
      const tag = node.tagName;
      if (tag === 'BUTTON' || tag === 'A' || tag === 'INPUT' || tag === 'FORM') return;
    }
    this.#open(id);
  }

  async #open(id) {
    if (this._busy) return;
    this._busy = true;
    this._error = '';
    try {
      this._detail = await apiRequest('personalOrder', { id });
    } catch (error) {
      this._error = t((error && error.code) || 'SHOP_API_ERROR');
      this._state = 'error';
    } finally {
      this._busy = false;
    }
  }

  async #openProduct(partsId) {
    if (!partsId) return;
    try {
      const data = await apiRequest('getProductLink', { product: partsId });
      // Ohne angehaengten Schraegstrich: getProductLink liefert den Link
      // inklusive Fragment ('…-t40h#focus'). Das alte account.js haengte
      // ein '/' an und schob es damit IN das Fragment — die Seite sprang
      // dann nicht mehr an die richtige Stelle. cart.js machte es richtig.
      if (data && data.hyperlink) window.location.href = this.productUrl + data.hyperlink;
    } catch {
      /* Ohne Ziel bleibt die Seite stehen — besser als ein toter Sprung. */
    }
  }

  // -------------------------------------------------------------- Anzeige

  #downloadForm(invoiceId) {
    return html`
      <form action=${`${this.apiUrl}?action=downloadInvoice`} method="post" part="download">
        <input type="hidden" name="payment-id" value=${invoiceId} />
        <input type="hidden" name="content-type" value="application/pdf" />
        <button class=${this.cls('button')} type="submit">${t('orders.download')}</button>
      </form>
    `;
  }

  #renderList() {
    if (!this._orders.length) {
      return html`<p class=${this.cls('muted')} part="empty">${t('orders.empty')}</p>`;
    }
    return html`
      <div part="orders">
        ${repeat(
          this._orders,
          (order) => order.id,
          (order) => html`
            <div
              class="order"
              part="order"
              @click=${(event) => this.#cardClick(event, order.id)}
            >
              <div class="order-title">
                <button class="link-button" @click=${() => this.#open(order.id)}>
                  ${t('orders.from')} ${formatDate(order.date)}
                </button>
              </div>
              <div class="order-line ${this.cls('muted')}">
                ${t('orders.positions')}: ${order.positions}
              </div>
              <div class="order-line">
                <strong>${t('orders.total')}:</strong>
                <strong>${formatPrice(order.amount)} ${order.currency}</strong>
                <span class=${this.cls('muted')}>${t('orders.grossSuffix')}</span>
              </div>
              ${this.#downloadForm(order.id)}
            </div>
          `
        )}
      </div>
    `;
  }

  #renderDetail() {
    const detail = this._detail;
    const invoice = detail.invoice || {};
    const shipping = detail.shipping || {};
    const positions = detail.positions || [];

    return html`
      <div class="actions" style="margin-top:0">
        <button
          class=${this.cls('button')}
          part="back"
          @click=${() => {
            this._detail = null;
          }}
        >
          ${t('orders.back')}
        </button>
      </div>

      <div class="detail-head">
        <div>
          <div class="section" part="section">${t('orders.details')}</div>
          <dl class="facts">
            <dt>${t('orders.number')}</dt>
            <dd>${invoice.invnumber}</dd>
            <dt>${t('orders.date')}</dt>
            <dd>${formatDate(invoice.invdate)}</dd>
            <dt>${t('orders.total')}</dt>
            <dd>${formatPrice(invoice.invtotal)} ${invoice.currency}</dd>
          </dl>
          ${this.#downloadForm(invoice.id)}
        </div>
        <div>
          <div class="section" part="section">${t('orders.deliveryAddress')}</div>
          <div>${shipping.name}</div>
          <div>${shipping.street}</div>
          <div>${cityLine(shipping)}</div>
        </div>
      </div>

      ${repeat(
        positions,
        (position) => `${position.parts_id}-${position.runningnumber}`,
        (position) => html`
          <div class="position" part="position">
            ${position.thumbnail
              ? html`<img
                  class="thumb"
                  part="thumb"
                  src=${this.thumbnailUrl + position.thumbnail}
                  alt=""
                  loading="lazy"
                />`
              : html`<div></div>`}
            <div>
              <h3>
                <button class="link-button" @click=${() => this.#openProduct(position.parts_id)}>
                  ${position.description}
                </button>
              </h3>
              <div class="order-line">
                <strong>${t('orders.quantity')}:</strong> ${position.qty}
              </div>
              <div class="order-line">
                <strong>${t('orders.price')}:</strong>
                ${formatPrice(position.linetotal)} ${invoice.currency}*
                <span class=${this.cls('muted')}>
                  (${formatPrice(position.sellprice)} ${invoice.currency}* /
                  ${t('orders.perUnit')})
                </span>
              </div>
            </div>
          </div>
        `
      )}

      <div class="note ${this.cls('muted')}">${t('orders.grossNote')}</div>
    `;
  }

  renderAccount() {
    return this._detail ? this.#renderDetail() : this.#renderList();
  }
}

customElements.define('shop-account-orders', ShopAccountOrders);
