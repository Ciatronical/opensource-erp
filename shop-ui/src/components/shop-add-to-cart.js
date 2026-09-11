import { html, css, nothing } from 'lit';
import { ShopElement } from '../core/base.js';
import { apiRequest, getContext } from '../core/api.js';
import { emit, on, SHOP_CART_CHANGED } from '../core/bus.js';
import { gtmViewItem, gtmAddToCart } from '../core/gtm.js';
import { t } from '../core/i18n.js';

/**
 * <shop-add-to-cart product="1386" button-text="In den Warenkorb"></shop-add-to-cart>
 *
 * Ersetzt shortcodes/in-cart.html + inCart() aus cart.js. Steht auf jeder
 * Produktseite (~3500).
 *
 * Der Zweitknopf "Warenkorb anzeigen (n)" gehoert zur Komponente, weil er
 * direkt unter dem Hauptknopf sitzt — sein Zaehler kommt aber nicht mehr aus
 * fremdem Markup. Frueher schrieb inCart() in #cart-count, #cart-button,
 * #empty-cart-button, #secondary-cart-button und #secondary-cart-count im
 * Header und in der Produktseite; fehlte eines davon, brach die Funktion mit
 * einem TypeError ab. Jetzt: Anfangswert aus getContext, danach ueber
 * shop:cart-changed — auch wenn ein anderes Widget den Warenkorb aendert.
 */
export class ShopAddToCart extends ShopElement {
  static properties = {
    product: { type: String },
    buttonText: { type: String, attribute: 'button-text' },
    cartUrl: { type: String, attribute: 'cart-url' },
    max: { type: Number },
    _quantity: { state: true },
    _busy: { state: true },
    _message: { state: true },
    _error: { state: true },
    _count: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      .quantity {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
      }
      .quantity input {
        width: 7rem;
      }
      .buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
      }
      button,
      a.secondary {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
      }
      svg {
        flex: none;
      }
      .message {
        margin-bottom: 1rem;
      }
      .count {
        font-style: italic;
      }
    `,
  ];

  #unsubscribe = null;

  constructor() {
    super();
    this.product = '';
    this.buttonText = '';
    this.cartUrl = '/warenkorb/#focus';
    this.max = 100;
    this._quantity = 1;
    this._busy = false;
    this._message = '';
    this._error = '';
    this._count = 0;
  }

  connectedCallback() {
    super.connectedCallback();
    this.#unsubscribe = on(SHOP_CART_CHANGED, (event) => {
      if (event.detail && typeof event.detail.count === 'number') this._count = event.detail.count;
    });
    // Legt nebenbei das Sitzungs-Cookie an, falls es noch fehlt.
    getContext()
      .then((data) => {
        this._count = Number(data.cart_pos_count) || 0;
      })
      .catch(() => {});
    if (this.product) gtmViewItem(this.product);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    if (this.#unsubscribe) this.#unsubscribe();
    this.#unsubscribe = null;
  }

  #setQuantity(value) {
    const quantity = Math.max(1, Math.min(this.max, Math.round(Number(value) || 1)));
    this._quantity = quantity;
    return quantity;
  }

  async add() {
    if (this._busy) return;
    this._busy = true;
    this._error = '';
    this._message = '';
    try {
      const data = await apiRequest('inCart', {
        product: this.product,
        quantity: this._quantity,
      });
      const count = Number(data.cartPosCount) || 0;
      this._count = count;
      this._message = t('addToCart.added');
      emit(SHOP_CART_CHANGED, { count });
      if (count > 0) gtmAddToCart(this.product, this._quantity);
    } catch (error) {
      this._error = t(error.code || 'SHOP_API_ERROR');
    } finally {
      this._busy = false;
    }
  }

  render() {
    return html`
      <div class="quantity">
        <input
          id="quantity"
          type="number"
          min="1"
          max=${this.max}
          step="1"
          class=${this.cls('input')}
          aria-label=${t('addToCart.quantity')}
          .value=${String(this._quantity)}
          @change=${(event) => {
            event.target.value = String(this.#setQuantity(event.target.value));
          }}
        >
        <span>${t('addToCart.unit')}</span>
      </div>

      ${this._error
        ? html`<div class="message ${this.cls('alertError')}" role="alert">${this._error}</div>`
        : nothing}
      ${this._message
        ? html`<div class="message ${this.cls('alertSuccess')}" role="status">${this._message}</div>`
        : nothing}

      <div class="buttons">
        <button
          type="button"
          class=${this.cls('buttonPrimary')}
          ?disabled=${this._busy || !this.product}
          @click=${() => this.add()}
        >
          ${this.#cartPlusIcon()}
          ${this._busy ? t('addToCart.pending') : this.buttonText || t('addToCart.submit')}
        </button>

        ${this._count > 0
          ? html`
              <a class="secondary ${this.cls('buttonSecondary')}" href=${this.cartUrl}>
                ${this.#cartCheckIcon()}
                <span>${t('addToCart.viewCart')}</span>
                <span class="count">(${this._count})</span>
              </a>
            `
          : nothing}
      </div>
    `;
  }

  // Die Icons lagen bisher als Bootstrap-Icons-Pfade direkt im Shortcode.
  // Inline im ShadowRoot: kein Icon-Font, keine Framework-Abhaengigkeit.
  #cartPlusIcon() {
    return html`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
           viewBox="0 0 16 16" aria-hidden="true">
        <path d="M9 5.5a.5.5 0 0 0-1 0V7H6.5a.5.5 0 0 0 0 1H8v1.5a.5.5 0 0 0 1 0V8h1.5a.5.5 0 0 0 0-1H9z"/>
        <path d="M.5 1a.5.5 0 0 0 0 1h1.11l.401 1.607 1.498 7.985A.5.5 0 0 0 4 12h1a2 2 0 1 0 0 4 2 2 0 0 0 0-4h7a2 2 0 1 0 0 4 2 2 0 0 0 0-4h1a.5.5 0 0 0 .491-.408l1.5-8A.5.5 0 0 0 14.5 3H2.89l-.405-1.621A.5.5 0 0 0 2 1zm3.915 10L3.102 4h10.796l-1.313 7zM6 14a1 1 0 1 1-2 0 1 1 0 0 1 2 0m7 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/>
      </svg>
    `;
  }

  #cartCheckIcon() {
    return html`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
           viewBox="0 0 16 16" aria-hidden="true">
        <path d="M11.354 6.354a.5.5 0 0 0-.708-.708L8 8.293 6.854 7.146a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0z"/>
        <path d="M.5 1a.5.5 0 0 0 0 1h1.11l.401 1.607 1.498 7.985A.5.5 0 0 0 4 12h1a2 2 0 1 0 0 4 2 2 0 0 0 0-4h7a2 2 0 1 0 0 4 2 2 0 0 0 0-4h1a.5.5 0 0 0 .491-.408l1.5-8A.5.5 0 0 0 14.5 3H2.89l-.405-1.621A.5.5 0 0 0 2 1zm3.915 10L3.102 4h10.796l-1.313 7zM6 14a1 1 0 1 1-2 0 1 1 0 0 1 2 0m7 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/>
      </svg>
    `;
  }
}

customElements.define('shop-add-to-cart', ShopAddToCart);
