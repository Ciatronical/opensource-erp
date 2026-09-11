import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { ShopElement } from '../core/base.js';
import { apiRequest } from '../core/api.js';
import { emit, SHOP_CART_CHANGED } from '../core/bus.js';
import { toNumber, formatPrice } from '../core/money.js';
import { normalizeCart, cartTotals } from '../core/cart-data.js';
import { t } from '../core/i18n.js';

/**
 * <shop-cart billing-page="/rechnung/" canceled-page="/bezahlung-abgebrochen/"></shop-cart>
 * <shop-cart ... invoicing-canceled="true"></shop-cart>
 *
 * Ersetzt shortcodes/cart.html + viewCart()/renderCart()/changeQuantity()/
 * deleteCartPos()/viewProduct() aus cart.js.
 *
 * mode="checkout" ist die Warenkorbspalte der Kassenseite: dieselbe Liste,
 * dieselben Mengenknoepfe, aber ohne den Fuss mit "Zur Kasse" und PayPal —
 * dort steht der Kaufen-Knopf von <shop-checkout>. Der "Jetzt kaufen"-Zweig
 * von renderCart() und der invoicing()-Aufruf sind bewusst NICHT hier
 * gelandet: das alte cart.js fuehrte Warenkorb und Kasse in einer Funktion
 * und importierte dafuer account.js.
 *
 * Der Positionszaehler im Header wird nicht mehr direkt beschrieben
 * (frueher document.getElementById('cart-count')), sondern ueber
 * shop:cart-changed gemeldet.
 */
export class ShopCart extends ShopElement {
  static properties = {
    billingPage: { type: String, attribute: 'billing-page' },
    canceledPage: { type: String, attribute: 'canceled-page' },
    // Bewusst String und nicht Boolean: der Shortcode uebergibt "true"/"false"
    // als Text. Mit type Boolean waere invoicing-canceled="false" wahr, weil
    // Lit dabei nur auf das Vorhandensein des Attributs sieht.
    invoicingCanceled: { type: String, attribute: 'invoicing-canceled' },
    // "cart" (Default) oder "checkout" — siehe Klassenkommentar.
    mode: { type: String },
    checkoutUrl: { type: String, attribute: 'checkout-url' },
    continueUrl: { type: String, attribute: 'continue-url' },
    productUrl: { type: String, attribute: 'product-url' },
    paypalImage: { type: String, attribute: 'paypal-image' },
    heading: { type: String },
    _cart: { state: true },
    _loading: { state: true },
    _error: { state: true },
    _message: { state: true },
    _busy: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      .count {
        font-size: 1.25rem;
        margin: 0 0 1.5rem;
      }
      ul.positions {
        list-style: none;
        margin: 0;
        padding: 0;
      }
      .position {
        display: grid;
        grid-template-columns: minmax(0, 12rem) minmax(0, 1fr);
        gap: 1.5rem;
        align-items: start;
        padding: 1.25rem;
        margin-bottom: 0.75rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        cursor: pointer;
      }
      /* Einspaltig, sobald zwei Spalten nicht mehr sinnvoll sind. Container
         Queries waeren richtiger, sind aber an ein contain-Setup gebunden,
         das der Host von aussen kaputtmachen kann. */
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
      .thumb-empty {
        aspect-ratio: 4 / 3;
        background: var(--shop-input-bg, #f8f9fa);
        border-radius: var(--shop-radius, 0.375rem);
      }
      .title {
        font-size: 1.1rem;
        font-weight: 600;
        margin: 0 0 1rem;
      }
      /* Als Button ausgezeichnet, weil das Ziel erst per API aufgeloest wird
         (getProductLink) — sieht aus wie ein Link, ist aber keiner. */
      .title button {
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
      .line {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem 1rem;
        align-items: center;
        margin-bottom: 0.75rem;
      }
      .line:last-child {
        margin-bottom: 0;
      }
      .line-label {
        font-weight: 600;
      }
      .stepper {
        display: flex;
        align-items: stretch;
      }
      .stepper input {
        width: 5rem;
        text-align: center;
      }
      .stepper button {
        width: 2.5rem;
        cursor: pointer;
      }
      .unit-price {
        font-weight: 300;
      }
      .totals {
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      .totals p {
        margin: 0 0 0.5rem;
      }
      .grand {
        font-size: 1.25rem;
        font-weight: 600;
      }
      .suffix {
        font-size: 0.875rem;
        font-weight: 400;
        margin-left: 0.5rem;
      }
      .note {
        font-weight: 300;
        margin: 1rem 0;
      }
      .paypal img {
        display: block;
        height: 2.5rem;
        width: auto;
      }
      .position[aria-busy='true'] {
        opacity: 0.6;
      }
    `,
  ];

  #timers = new Map();

  constructor() {
    super();
    this.billingPage = '';
    this.canceledPage = '';
    this.invoicingCanceled = 'false';
    this.mode = 'cart';
    this.checkoutUrl = '/kasse/#focus';
    this.continueUrl = '/';
    this.productUrl = '/produkt/';
    this.paypalImage = '/images/paypal-de.png';
    this.heading = '';
    this._cart = null;
    this._loading = true;
    this._error = '';
    this._message = '';
    this._busy = new Set();
  }

  connectedCallback() {
    super.connectedCallback();
    this.#load();
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    for (const timer of this.#timers.values()) clearTimeout(timer);
    this.#timers.clear();
  }

  // ---------------------------------------------------------------- Daten

  async #load() {
    this._loading = true;
    this._error = '';
    try {
      // `lang` wird nicht mehr mitgeschickt: getCart() in shop.cart.php liest
      // ausschliesslich invoicingCanceled. Die Aktion `checkout` ist
      // getCart(true) ohne Parameter (shop.payment.php:4).
      const data = this.#isCheckout
        ? await apiRequest('checkout')
        : await apiRequest('getCart', { invoicingCanceled: this.invoicingCanceled === 'true' });
      this._cart = normalizeCart(data);
      this.#announce();
    } catch (error) {
      this._error = t(error.code || 'SHOP_API_ERROR');
    } finally {
      this._loading = false;
    }
  }

  get #isCheckout() {
    return this.mode === 'checkout';
  }

  /**
   * `cart` liegt dem Ereignis bei, damit <shop-checkout> den Kaufen-Knopf
   * schalten und begin_checkout melden kann, ohne den Warenkorb ein zweites
   * Mal abzufragen. Der Zaehler im Seitenkopf liest weiterhin nur `count`.
   */
  #announce() {
    const count = this._cart ? this._cart.positions.length : 0;
    emit(SHOP_CART_CHANGED, { count, cart: this._cart });
  }

  #setBusy(id, busy) {
    const next = new Set(this._busy);
    if (busy) next.add(id);
    else next.delete(id);
    this._busy = next;
  }

  // ------------------------------------------------------------ Aktionen

  /**
   * Menge lokal setzen (sofortige Rueckmeldung), Server verzoegert.
   * Gibt den tatsaechlich uebernommenen Wert zurueck — der Aufrufer schreibt
   * ihn ins Eingabefeld zurueck. Ohne das bliebe eine abgelehnte Eingabe
   * sichtbar stehen: bei Menge 100 und getippter 200 aendert sich der Zustand
   * nicht, Lit rendert also nicht neu, und im Feld staende weiter 200.
   */
  #setQuantity(pos, quantity) {
    const value = Math.max(1, Math.min(100, Math.round(Number(quantity) || 1)));
    if (value === pos.quantity) return value;
    this._cart = {
      ...this._cart,
      positions: this._cart.positions.map((p) =>
        p.id === pos.id ? { ...p, quantity: value } : p,
      ),
    };
    this.#commitQuantity(pos.id, value);
    return value;
  }

  /** Schnelles Klicken auf +/- soll nicht jeden Zwischenschritt senden. */
  #commitQuantity(id, quantity) {
    clearTimeout(this.#timers.get(id));
    this.#timers.set(
      id,
      setTimeout(() => {
        this.#timers.delete(id);
        this.#sendQuantity(id, quantity);
      }, 350),
    );
  }

  async #sendQuantity(id, quantity) {
    this.#setBusy(id, true);
    try {
      const data = await apiRequest('changeQuantity', { pos: id, quantity });
      this._cart = {
        ...this._cart,
        positions: this._cart.positions.map((p) =>
          p.id === id
            ? { ...p, unitPrice: toNumber(data.unitPrice), totalPrice: toNumber(data.totalPrice) }
            : p,
        ),
        totals: cartTotals(data),
      };
      this._message = '';
    } catch (error) {
      // Der lokale Wert stimmt jetzt womoeglich nicht mehr mit dem Server
      // ueberein — neu laden statt eine Luege stehen zu lassen.
      this._message = t(error.code || 'SHOP_API_ERROR');
      await this.#load();
    } finally {
      this.#setBusy(id, false);
    }
  }

  async #remove(pos) {
    clearTimeout(this.#timers.get(pos.id));
    this.#timers.delete(pos.id);
    this.#setBusy(pos.id, true);
    try {
      const data = await apiRequest('deleteCartPos', { pos: pos.id });
      this._cart = {
        ...this._cart,
        positions: this._cart.positions.filter((p) => p.id !== pos.id),
        totals: cartTotals(data),
      };
      this._message = '';
      this.#announce();
    } catch (error) {
      this._message = t(error.code || 'SHOP_API_ERROR');
    } finally {
      this.#setBusy(pos.id, false);
    }
  }

  async #openProduct(pos) {
    try {
      const data = await apiRequest('getProductLink', { product: pos.referencedId });
      if (data && data.hyperlink) window.location.href = this.productUrl + data.hyperlink;
    } catch (error) {
      this._message = t(error.code || 'SHOP_API_ERROR');
    }
  }

  #cardClick(pos, event) {
    // Klick auf die ganze Karte oeffnet das Produkt — ausser er galt einem
    // Bedienelement. Das alte cart.js hing dafuer an jedem Control ein
    // eigenes stopPropagation().
    const interactive = event
      .composedPath()
      .some((node) => node.tagName && /^(INPUT|BUTTON|A|SELECT|LABEL)$/.test(node.tagName));
    if (interactive) return;
    this.#openProduct(pos);
  }

  // ------------------------------------------------------------- Ausgabe

  render() {
    if (this._loading) {
      return html`<p class=${this.cls('muted')} role="status">${t('cart.loading')}</p>`;
    }
    if (this._error) {
      return html`<div class=${this.cls('alertError')} role="alert">${this._error}</div>`;
    }
    const positions = this._cart ? this._cart.positions : [];
    if (!positions.length) return this.#renderEmpty();

    return html`
      ${this.heading ? html`<h2 class=${this.cls('heading')}>${this.heading}</h2>` : nothing}
      ${this._message
        ? html`<div class=${this.cls('alertError')} role="alert">${this._message}</div>`
        : nothing}
      <p class="count ${this.cls('muted')}">${t('cart.positions')}: ${positions.length}</p>
      <ul class="positions">
        ${repeat(
          positions,
          (pos) => pos.id,
          (pos) => this.#renderPosition(pos),
        )}
      </ul>
      ${this.#renderTotals()} ${this.#renderActions()}
    `;
  }

  /**
   * Leerer Warenkorb. Das alte renderCart() sprang hier per
   * window.location.href = '/' auf die Startseite — auch dann, wenn man
   * gerade die letzte Position geloescht hatte.
   */
  #renderEmpty() {
    return html`
      <p>${t('cart.empty')}</p>
      <p><a class=${this.cls('link')} href=${this.continueUrl}>${t('cart.continue')}</a></p>
    `;
  }

  #renderPosition(pos) {
    const busy = this._busy.has(pos.id);
    const currency = this._cart.currency;
    return html`
      <li
        class="position"
        aria-busy=${busy ? 'true' : 'false'}
        @click=${(event) => this.#cardClick(pos, event)}
      >
        <div>
          ${pos.thumbnail
            ? html`<img class="thumb" src="/images/thumbnails/${pos.thumbnail}" alt="" loading="lazy">`
            : html`<div class="thumb thumb-empty"></div>`}
        </div>
        <div>
          <h3 class="title">
            <button type="button" @click=${() => this.#openProduct(pos)}>${pos.label}</button>
          </h3>

          <div class="line">
            <span class="line-label">${t('cart.quantity')}:</span>
            <span class="stepper">
              <button
                type="button"
                class=${this.cls('button')}
                aria-label=${t('cart.decrease')}
                ?disabled=${busy}
                @click=${() => this.#step(pos, -1)}
              >−</button>
              <input
                type="number"
                min="1"
                max="100"
                step="1"
                class=${this.cls('input')}
                aria-label=${t('cart.quantity')}
                .value=${String(pos.quantity)}
                ?disabled=${busy}
                @change=${(event) => {
                  event.target.value = String(this.#setQuantity(pos, event.target.value));
                }}
              >
              <button
                type="button"
                class=${this.cls('button')}
                aria-label=${t('cart.increase')}
                ?disabled=${busy}
                @click=${() => this.#step(pos, 1)}
              >+</button>
            </span>
            <button
              type="button"
              class=${this.cls('buttonSecondary')}
              ?disabled=${busy}
              @click=${() => this.#remove(pos)}
            >${t('cart.delete')}</button>
          </div>

          <div class="line">
            <span class="line-label">${t('cart.price')}:</span>
            <span>${formatPrice(pos.totalPrice)} ${currency}*</span>
            <span class="unit-price ${this.cls('muted')}"
              >(${formatPrice(pos.unitPrice)} ${currency}* / ${t('cart.perUnit')})</span
            >
          </div>
        </div>
      </li>
    `;
  }

  /**
   * "-" bei Menge 1 loescht die Position. Das alte cart.js loeschte und rief
   * danach zusaetzlich changeQuantity() auf derselben, bereits geloeschten
   * Position auf (cart.js:170).
   */
  #step(pos, delta) {
    if (delta < 0 && pos.quantity <= 1) {
      this.#remove(pos);
      return;
    }
    this.#setQuantity(pos, pos.quantity + delta);
  }

  #renderTotals() {
    const { shipping, netto, total } = this._cart.totals;
    const currency = this._cart.currency;
    return html`
      <div class="totals">
        <p class="line-label">
          ${t('cart.shipping')}: ${formatPrice(shipping)} ${currency}*
        </p>
        <p class="note ${this.cls('muted')}">${t('cart.netNote')}</p>
        <p>
          ${t('cart.netTotal')}: ${formatPrice(netto)} ${currency}
          <span class="suffix">${t('cart.netSuffix')}</span>
        </p>
        <p class="grand">
          ${t('cart.total')}: ${formatPrice(total)} ${currency}
          <span class="suffix">${t('cart.totalSuffix')}</span>
        </p>
      </div>
    `;
  }

  #renderActions() {
    if (this.#isCheckout) return nothing;
    return html`
      <div class="actions">
        <a class=${this.cls('buttonPrimary')} href=${this.checkoutUrl}>${t('cart.checkout')}</a>
        <a class="paypal" href=${this.#paypalUrl()} aria-label=${t('cart.paypal')}>
          <img src=${this.paypalImage} alt=${t('cart.paypal')}>
        </a>
      </div>
    `;
  }

  /** GET-Einstieg des Backends; die Rueckkehr-Seiten kommen vom Shortcode. */
  #paypalUrl() {
    const query = new URLSearchParams({
      action: 'beginPayment',
      bill: this.billingPage,
      canceled: this.canceledPage,
    });
    return `/shop-api/?${query}`;
  }
}

customElements.define('shop-cart', ShopCart);
