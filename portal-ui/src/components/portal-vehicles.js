import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { PortalElement } from '../core/base.js';
import { PortalAccountElement } from '../core/account-base.js';
import { apiRequest } from '../core/api.js';
import { formatDate } from '../core/dates.js';
import { t } from '../core/i18n.js';

/**
 * <portal-vehicles></portal-vehicles>
 *
 * Die Fahrzeuge des angemeldeten Kunden aus LxCars: Kennzeichen, Hersteller
 * und Typ, Erstzulassung, Kilometerstand — und der HU-Termin, farbig, wenn
 * er näher rückt oder vorbei ist. Nur Anzeige: geändert wird in der
 * Werkstatt.
 */
export class PortalVehicles extends PortalAccountElement {
  static properties = {
    _vehicles: { state: true },
  };

  static styles = [
    PortalElement.baseStyles,
    PortalAccountElement.accountStyles,
    css`
      .plate {
        display: inline-block;
        font-family: ui-monospace, 'DejaVu Sans Mono', monospace;
        font-size: 1.25rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        padding: 0.15rem 0.6rem;
        border: 2px solid currentColor;
        border-radius: 0.3rem;
        margin-bottom: 0.5rem;
      }
      .model {
        font-weight: 600;
      }
      dl {
        display: grid;
        grid-template-columns: max-content 1fr;
        gap: 0.25rem 1rem;
        margin: 0.5rem 0 0;
      }
      dt {
        opacity: 0.75;
      }
      dd {
        margin: 0;
      }
      .hu {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 999px;
        font-size: 0.9rem;
        font-weight: 600;
      }
      .hu-ok {
        background: #d1e7dd;
        color: #0f5132;
      }
      .hu-soon {
        background: #fff3cd;
        color: #664d03;
      }
      .hu-overdue {
        background: #f8d7da;
        color: #842029;
      }
    `,
  ];

  constructor() {
    super();
    this._vehicles = [];
  }

  async load() {
    const data = await apiRequest('portalVehicles', {});
    this._vehicles = data.vehicles || [];
  }

  #hu(vehicle) {
    if (!vehicle.hu_date) return nothing;
    const overdue = vehicle.hu_overdue === true || vehicle.hu_overdue === 't';
    const soon = vehicle.hu_soon === true || vehicle.hu_soon === 't';
    const klasse = overdue ? 'hu-overdue' : soon ? 'hu-soon' : 'hu-ok';
    const text = overdue ? t('vehicles.huOverdue') : soon ? t('vehicles.huSoon') : t('vehicles.huOk');
    return html`<span class="hu ${klasse}">${formatDate(vehicle.hu_date)} · ${text}</span>`;
  }

  renderAccount() {
    if (!this._vehicles.length) {
      return html`<p class="hint">${t('vehicles.none')}</p>`;
    }
    return html`
      <h3 class="section">${t('vehicles.heading')}</h3>
      <div class="cards">
        ${repeat(this._vehicles, (v) => v.id, (v) => html`
          <div class="card" part="vehicle">
            <span class="plate">${v.plate}</span>
            <span class="model">${[v.make, v.model || v.model_text].filter(Boolean).join(' ')}</span>
            <dl>
              <dt>${t('vehicles.hu')}</dt>
              <dd>${this.#hu(v) || '—'}</dd>
              ${v.first_registration ? html`<dt>${t('vehicles.firstRegistration')}</dt><dd>${formatDate(v.first_registration)}</dd>` : nothing}
              ${v.mileage ? html`<dt>${t('vehicles.mileage')}</dt><dd>${Number(v.mileage).toLocaleString(document.documentElement.lang || 'de')} km</dd>` : nothing}
              ${v.vin ? html`<dt>${t('vehicles.vin')}</dt><dd>${v.vin}</dd>` : nothing}
            </dl>
          </div>
        `)}
      </div>
      <p class="hint">${t('vehicles.hint')}</p>
    `;
  }
}

customElements.define('portal-vehicles', PortalVehicles);
