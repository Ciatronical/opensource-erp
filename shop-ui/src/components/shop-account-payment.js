import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { ShopElement } from '../core/base.js';
import { ShopAccountElement } from '../core/account-base.js';
import { apiRequest } from '../core/api.js';
import { t } from '../core/i18n.js';

/**
 * <shop-account-payment></shop-account-payment>
 *
 * Ersetzt shortcodes/personal-payment.html + personalPayment() aus
 * account.js.
 *
 * Gegenueber dem Vorgaenger:
 *  - Schlaegt changePaymentMethod fehl, springt die Auswahl auf den alten
 *    Wert zurueck und es gibt eine Meldung. Vorher blieb der neue Punkt
 *    markiert und der Fehler landete nur in der Konsole — die Anzeige log
 *    also.
 *  - Ein <fieldset> mit <legend> statt loser Radios; sonst weiss ein
 *    Screenreader nicht, wozu die Gruppe gehoert.
 *  - description_long kommt aus der Datenbank und wird als Text gesetzt,
 *    nicht per innerHTML. Zeilenumbrueche bleiben ueber white-space
 *    erhalten.
 */
export class ShopAccountPayment extends ShopAccountElement {
  static properties = {
    _methods: { state: true },
    _selected: { state: true },
    _busy: { state: true },
    _message: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    ShopAccountElement.accountStyles,
    css`
      fieldset {
        border: 0;
        margin: 0;
        padding: 0;
      }
      legend {
        padding: 0;
      }
      .method {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.25rem 0.75rem;
        padding: 1rem 0;
      }
      .method + .method {
        border-top: 1px solid var(--shop-border-color, #dee2e6);
      }
      .method input {
        margin-top: 0.25rem;
      }
      .method label {
        font-weight: 600;
        cursor: pointer;
      }
      .method .description {
        grid-column: 2;
        white-space: pre-line;
      }
    `,
  ];

  constructor() {
    super();
    this._methods = [];
    this._selected = '';
    this._busy = false;
    this._message = null;
  }

  async load() {
    const data = await apiRequest('personalPayment');
    this._methods = (data && data.payment_methods) || [];
    this._selected =
      data && data.default_payment !== null && data.default_payment !== undefined
        ? String(data.default_payment)
        : '';
    // personalPayment liefert keine Anrede — deshalb nur der Name.
    this.announce('', (data && data.name) || '');
  }

  async #choose(id) {
    if (this._busy || id === this._selected) return;
    const previous = this._selected;
    this._selected = id;
    this._busy = true;
    this._message = null;
    try {
      await apiRequest('changePaymentMethod', { payment_id: id });
      this._message = { text: t('payment.saved'), ok: true };
    } catch (error) {
      this._selected = previous;
      this._message = { text: t((error && error.code) || 'SHOP_API_ERROR'), ok: false };
    } finally {
      this._busy = false;
    }
  }

  renderAccount() {
    if (!this._methods.length) {
      return html`<p class=${this.cls('muted')}>${t('payment.none')}</p>`;
    }

    return html`
      <fieldset part="methods">
        <legend class="section" part="section">${t('payment.heading')}</legend>
        ${repeat(
          this._methods,
          (method) => method.id,
          (method) => {
            const id = String(method.id);
            return html`
              <div class="method" part="method">
                <input
                  class=${this.cls('check')}
                  part="radio"
                  type="radio"
                  name="payment-method"
                  id=${`payment-${id}`}
                  value=${id}
                  .checked=${this._selected === id}
                  ?disabled=${this._busy}
                  @change=${() => this.#choose(id)}
                />
                <label class=${this.cls('label')} part="label" for=${`payment-${id}`}>
                  ${method.description}
                </label>
                <div class="description">${method.description_long}</div>
              </div>
            `;
          }
        )}
      </fieldset>

      ${this._message
        ? html`<div class="shop-message" role="status" aria-live="polite">
            <div
              class=${this._message.ok ? this.cls('alertSuccess') : this.cls('alertError')}
              part=${this._message.ok ? 'success' : 'error'}
            >
              ${this._message.text}
            </div>
          </div>`
        : nothing}
    `;
  }
}

customElements.define('shop-account-payment', ShopAccountPayment);
