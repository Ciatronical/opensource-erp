/**
 * Versandwahl (dev/shop-versand.md, „Versandwahl in der Kasse“): die Liste
 * der Versandarten, aus der der Kunde waehlt — im Warenkorb (<shop-cart>) und
 * in der Kasse (<shop-checkout>) dieselbe Darstellung aus einem Baustein.
 *
 * Die Liste kommt fertig bewertet aus dem Backend (shippingOptions in
 * cart-data.js): nur freigeschaltete oder durch Artikel vorgegebene
 * Versandarten; available/reason sagen, ob sie passt; selected ist die, mit
 * der gerechnet wird. Hier wird nur gezeichnet und die Wahl gemeldet.
 */
import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { formatPrice } from './money.js';
import { t } from './i18n.js';

export const shippingOptionStyles = css`
  .shipping-options {
    list-style: none;
    margin: 0;
    padding: 0;
  }
  .shipping-option {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 0.25rem 0.75rem;
    align-items: start;
    margin: 0 0 0.5rem;
    padding: 0.75rem;
    border: 1px solid var(--shop-border-color, #dee2e6);
    border-radius: var(--shop-radius, 0.375rem);
    cursor: pointer;
  }
  .shipping-option.is-selected {
    outline: 2px solid currentColor;
    outline-offset: -2px;
  }
  .shipping-option.is-disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }
  .shipping-option input {
    margin: 0.2rem 0 0;
  }
  .shipping-option .name,
  .shipping-option .price {
    font-weight: 600;
  }
  .shipping-option .price {
    white-space: nowrap;
  }
  .shipping-option .hint,
  .shipping-option .reason {
    grid-column: 2 / span 2;
    font-size: 0.9em;
  }
  .shipping-single {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
  }
`;

/** Preis einer Versandart als Text; leer, wenn sie nicht passt */
export function shippingPriceText(option, currency) {
  if (!option.available || option.price === null) return '';
  if (option.free) return t('cart.shippingFree');
  if (option.price === 0) return t('checkout.shippingNoCharge');
  return `${formatPrice(option.price)} ${currency}`;
}

/** Landesname zum ISO-Code in der Sprache der Seite; Rueckfall: der Code */
export function countryName(code) {
  if (!code) return '';
  try {
    const lang = document.documentElement.lang || 'de';
    return new Intl.DisplayNames([lang], { type: 'region' }).of(code) || code;
  } catch {
    return code;
  }
}

/**
 * Die Auswahlliste (oder bei nur einer Versandart der Text).
 *
 * @param {object} args
 * @param {Array} args.options     shippingOptions aus cart-data.js
 * @param {string} args.currency   Waehrung des Warenkorbs
 * @param {Function} args.cls      this.cls des Elements (Preset-Klassen)
 * @param {boolean} args.busy      Wahl laeuft gerade — Eingaben gesperrt
 * @param {Function} args.onChoose (id) => void
 * @param {string} args.group      name= der Radio-Gruppe (je Element eindeutig)
 */
export function renderShippingOptions({ options, currency, cls, busy, onChoose, group = 'shipping' }) {
  if (!options || !options.length) return nothing;

  if (options.length === 1) {
    const only = options[0];
    return html`
      <div class="shipping-single">
        <span>${only.name}</span>
        <span>${shippingPriceText(only, currency)}</span>
      </div>
      ${only.note ? html`<p class="note ${cls('muted')}">${only.note}</p>` : nothing}
      ${only.forced && only.requiredBy
        ? html`<p class="note ${cls('muted')}">${t('checkout.shippingForced').replace('{parts}', only.requiredBy)}</p>`
        : nothing}
    `;
  }

  return html`
    <ul class="shipping-options" role="radiogroup">
      ${repeat(
        options,
        (o) => o.id,
        (o) => {
          const classes = ['shipping-option', o.selected ? 'is-selected' : '', o.available ? '' : 'is-disabled']
            .filter(Boolean)
            .join(' ');
          return html`
            <li>
              <label class=${classes}>
                <input
                  class=${cls('check')}
                  type="radio"
                  name=${group}
                  .value=${String(o.id)}
                  .checked=${o.selected}
                  ?disabled=${!o.available || busy}
                  @change=${() => onChoose(o.id)}
                />
                <span class="name">${o.name}</span>
                <span class="price">${shippingPriceText(o, currency)}</span>
                ${o.note ? html`<span class="hint ${cls('muted')}">${o.note}</span>` : nothing}
                ${o.forced && o.requiredBy
                  ? html`<span class="hint ${cls('muted')}">${t('checkout.shippingForced').replace('{parts}', o.requiredBy)}</span>`
                  : nothing}
                ${!o.available && o.reason
                  ? html`<span class="reason">${t(`shipping.reason.${o.reason}`)}</span>`
                  : nothing}
              </label>
            </li>
          `;
        },
      )}
    </ul>
  `;
}
