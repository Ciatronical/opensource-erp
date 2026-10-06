import { html, css, nothing } from 'lit';
import { ShopElement } from '../core/base.js';
import { apiRequest } from '../core/api.js';
import { formatPrice } from '../core/money.js';
import { sameOriginHref } from '../core/links.js';
import { t } from '../core/i18n.js';
import './shop-add-to-cart.js';

/**
 * <shop-tool-finder cart-url="/warenkorb/"></shop-tool-finder>
 *
 * Werkzeugsuche: Der Besucher gibt HSN/TSN aus dem Fahrzeugschein (Felder
 * 2.1 und 2.2) oder seine Fahrgestellnummer ein und bekommt die
 * Spezialwerkzeuge der Werkstatt, die zu seinem Fahrzeug passen — jeweils
 * zum Mieten und/oder zum Kaufen, mit Warenkorb-Knopf.
 *
 * Die Zuordnung rechnet das Backend (findSpecialTools) aus den
 * Zuordnungsregeln der Werkstatt. Eine fremde Fahrgestellnummer kann es
 * nicht auflösen; dann bittet das Widget um HSN/TSN.
 */
export class ShopToolFinder extends ShopElement {
  static properties = {
    cartUrl: { type: String, attribute: 'cart-url' },
    heading: { type: String },
    intro: { type: String },
    _mode: { state: true },
    _hsn: { state: true },
    _tsn: { state: true },
    _fin: { state: true },
    _engine: { state: true },
    _year: { state: true },
    _busy: { state: true },
    _result: { state: true },
    _error: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      .modes {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
      }
      .fields {
        max-width: none;
        grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
        gap: 1rem;
      }
      .fields.fin {
        grid-template-columns: minmax(16rem, 2fr) minmax(10rem, 1fr);
      }
      label span {
        display: block;
        margin-bottom: 0.25rem;
      }
      .hint {
        margin-top: 0.5rem;
        font-size: 0.9em;
        opacity: 0.8;
      }
      .vehicle {
        margin: 1.5rem 0 1rem;
        padding: 0.75rem 1rem;
        border-left: 4px solid var(--shop-primary, #0d6efd);
        background: var(--shop-muted-surface, #f8f9fa);
      }
      .tools {
        display: grid;
        gap: 1rem;
      }
      .tool {
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        padding: 1rem 1.25rem;
      }
      .tool h3 {
        margin: 0 0 0.25rem;
        font-size: 1.1em;
      }
      .tool .category {
        font-size: 0.85em;
        opacity: 0.75;
      }
      .tool .summary {
        margin: 0.5rem 0 0.75rem;
      }
      .tool .reasons {
        font-size: 0.85em;
        opacity: 0.8;
        margin-bottom: 0.75rem;
      }
      .offers {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
      }
      .offer {
        padding: 0.75rem 1rem;
        border-radius: var(--shop-radius, 0.375rem);
        background: var(--shop-muted-surface, #f8f9fa);
      }
      .offer .kind {
        font-size: 0.8em;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        opacity: 0.7;
      }
      .offer .price {
        font-size: 1.3em;
        font-weight: 600;
        margin: 0.25rem 0;
      }
      .offer .per {
        font-size: 0.85em;
        opacity: 0.8;
      }
      .offer .lent {
        font-style: italic;
      }
      .offer a {
        display: inline-block;
        margin-top: 0.5rem;
        font-size: 0.9em;
      }
      .empty {
        margin-top: 1rem;
        font-style: italic;
      }
    `,
  ];

  constructor() {
    super();
    this.cartUrl = '/warenkorb/#focus';
    this.heading = '';
    this.intro = '';
    this._mode = 'kba';
    this._hsn = '';
    this._tsn = '';
    this._fin = '';
    this._engine = '';
    this._year = '';
    this._busy = false;
    this._result = null;
    this._error = '';
  }

  #setMode(mode) {
    this._mode = mode;
    this._error = '';
  }

  async search(event) {
    if (event) event.preventDefault();
    if (this._busy) return;
    this._error = '';

    const payload = { engine_code: this._engine.trim(), year: Number(this._year) || 0 };
    if (this._mode === 'fin') {
      payload.fin = this._fin.trim();
      if (payload.fin.length < 11) {
        this._error = t('tools.finInvalid');
        return;
      }
    } else {
      payload.hsn = this._hsn.trim();
      payload.tsn = this._tsn.trim();
      if (payload.hsn.length < 3 || payload.tsn.length < 3) {
        this._error = t('tools.kbaMissing');
        return;
      }
    }

    this._busy = true;
    try {
      this._result = await apiRequest('findSpecialTools', payload);
      if (this._result && this._result.fin_unknown && this._result.source !== 'kba') {
        this._error = t('tools.finUnknown');
        this._mode = 'kba';
      }
    } catch (error) {
      this._error = t(error.code || 'SHOP_API_ERROR');
      this._result = null;
    } finally {
      this._busy = false;
    }
  }

  #vehicleLabel() {
    const v = this._result && this._result.vehicle;
    if (!v) return '';
    const meta = [v.fuel_name || v.fuel, v.ccm ? `${v.ccm} ccm` : '', v.kw ? `${v.kw} kW` : '', v.year || '']
      .filter(Boolean)
      .join(' · ');
    return `${v.label || `${v.make || ''} ${v.model || ''}`.trim()}${meta ? ` — ${meta}` : ''}`;
  }

  #renderOffer(kind, offer, tool) {
    if (!offer) return nothing;
    const canOrder = offer.available && tool.status !== 'defective';
    return html`
      <div class="offer">
        <div class="kind">${t(kind === 'rent' ? 'tools.rent' : 'tools.buy')}</div>
        <div class="price">${formatPrice(offer.price_gross)} €</div>
        <div class="per">
          ${kind === 'rent' ? t('tools.rentPer').replace('{days}', tool.rental_days) : t('tools.buyOnce')}
          · ${t('tools.inclVat')}
        </div>
        ${canOrder
          ? html`<shop-add-to-cart
              product=${String(offer.parts_id)}
              button-text=${t(kind === 'rent' ? 'tools.rentNow' : 'tools.buyNow')}
              cart-url=${this.cartUrl}
            ></shop-add-to-cart>`
          : html`<div class="lent">${t(kind === 'rent' && tool.status === 'lent' ? 'tools.lentOut' : 'tools.unavailable')}</div>`}
        ${offer.hyperlink
          ? html`<a href=${sameOriginHref(offer.hyperlink)}>${t('tools.details')}</a>`
          : nothing}
      </div>
    `;
  }

  #renderTool(tool) {
    return html`
      <article class="tool">
        <h3>${tool.name}</h3>
        ${tool.category ? html`<div class="category">${tool.category}</div>` : nothing}
        ${tool.summary || tool.description
          ? html`<p class="summary">${tool.summary || tool.description}</p>`
          : nothing}
        ${tool.reasons && tool.reasons.length
          ? html`<div class="reasons">${t('tools.because')} ${tool.reasons.join(', ')}</div>`
          : nothing}
        <div class="offers">
          ${this.#renderOffer('rent', tool.rent, tool)}
          ${this.#renderOffer('sale', tool.sale, tool)}
        </div>
      </article>
    `;
  }

  render() {
    const r = this._result;
    return html`
      ${this.heading ? html`<h2>${this.heading}</h2>` : nothing}
      <p>${this.intro || t('tools.intro')}</p>

      <div class="modes" role="tablist">
        <button
          type="button"
          class=${this.cls(this._mode === 'kba' ? 'buttonPrimary' : 'button')}
          aria-pressed=${this._mode === 'kba'}
          @click=${() => this.#setMode('kba')}
        >${t('tools.modeKba')}</button>
        <button
          type="button"
          class=${this.cls(this._mode === 'fin' ? 'buttonPrimary' : 'button')}
          aria-pressed=${this._mode === 'fin'}
          @click=${() => this.#setMode('fin')}
        >${t('tools.modeFin')}</button>
      </div>

      <form @submit=${(e) => this.search(e)}>
        ${this._mode === 'fin'
          ? html`
              <div class="fields fin">
                <label>
                  <span>${t('tools.fin')}</span>
                  <input class=${this.cls('input')} .value=${this._fin} maxlength="17" autocomplete="off"
                         placeholder="WVWZZZ…" @input=${(e) => (this._fin = e.target.value.toUpperCase())} />
                </label>
                <label>
                  <span>${t('tools.engineCode')}</span>
                  <input class=${this.cls('input')} .value=${this._engine} maxlength="20"
                         @input=${(e) => (this._engine = e.target.value)} />
                </label>
              </div>
              <div class="hint">${t('tools.finHint')}</div>
            `
          : html`
              <div class="fields">
                <label>
                  <span>${t('tools.hsn')}</span>
                  <input class=${this.cls('input')} .value=${this._hsn} maxlength="4" inputmode="numeric"
                         placeholder="0603" @input=${(e) => (this._hsn = e.target.value)} />
                </label>
                <label>
                  <span>${t('tools.tsn')}</span>
                  <input class=${this.cls('input')} .value=${this._tsn} maxlength="10"
                         placeholder="AFA" @input=${(e) => (this._tsn = e.target.value.toUpperCase())} />
                </label>
                <label>
                  <span>${t('tools.engineCode')}</span>
                  <input class=${this.cls('input')} .value=${this._engine} maxlength="20"
                         @input=${(e) => (this._engine = e.target.value)} />
                </label>
                <label>
                  <span>${t('tools.year')}</span>
                  <input class=${this.cls('input')} .value=${this._year} maxlength="4" inputmode="numeric"
                         placeholder="2008" @input=${(e) => (this._year = e.target.value)} />
                </label>
              </div>
              <div class="hint">${t('tools.kbaHint')}</div>
            `}
        <div class="actions">
          <button type="submit" class=${this.cls('buttonPrimary')} ?disabled=${this._busy}>
            ${this._busy ? t('tools.searching') : t('tools.submit')}
          </button>
        </div>
      </form>

      ${this._error ? html`<div class=${this.cls('alertError')} role="alert">${this._error}</div>` : nothing}

      ${r && r.supported === false ? html`<p class="empty">${t('tools.notSupported')}</p>` : nothing}

      ${r && r.vehicle
        ? html`<div class="vehicle"><strong>${t('tools.yourVehicle')}</strong> ${this.#vehicleLabel()}</div>`
        : nothing}

      ${r && r.vehicle && !r.tools.length ? html`<p class="empty">${t('tools.none')}</p>` : nothing}

      ${r && r.tools.length
        ? html`<div class="tools">${r.tools.map((tool) => this.#renderTool(tool))}</div>`
        : nothing}
    `;
  }
}

customElements.define('shop-tool-finder', ShopToolFinder);
