import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { PortalElement } from '../core/base.js';
import { PortalAccountElement } from '../core/account-base.js';
import { apiRequest } from '../core/api.js';
import { formatPrice } from '../core/money.js';
import { formatDate } from '../core/dates.js';
import { cityLine } from '../core/account-data.js';
import { t } from '../core/i18n.js';
import { sameOriginHref } from '../core/links.js';
import { apiUrl } from '../core/paths.js';

/**
 * <portal-account-documents></portal-account-documents>
 *
 * Die Belege des Kunden: Rechnungen, Aufträge, Angebote, Lieferscheine,
 * Reklamationen — welche davon, sagt die Webseite in OpensourceERP
 * (Firmenkonfiguration → Kundenportal, Vorgabe nur Rechnungen). Das Backend
 * liefert mit der ersten Antwort die eingeschalteten Belegarten (types); gibt
 * es mehr als eine, stehen sie als Reiter über der Liste.
 *
 * Nachfolger von <portal-account-orders>, das nur Rechnungen kannte und sie
 * als „Bestellungen“ zeigte (Erbe des Shops).
 *
 * Der PDF-Download bleibt ein echtes Formular mit POST auf
 * /portal-api/?action=downloadDocument — die Antwort ist ein PDF, kein JSON,
 * und soll im Browser als Download landen. Ob es zur Belegart ein PDF gibt,
 * sagt das Backend mit (pdf); Reklamationen haben keins, dort fehlt der Knopf.
 *
 * Mit der Shop-Erweiterung kommt die Art shop_orders dazu: die Bestellungen
 * des Online-Shops, die dann nicht mehr unter den Rechnungen stehen.
 */
export class PortalAccountDocuments extends PortalAccountElement {
  static properties = {
    apiUrl: { type: String, attribute: 'api-url' },
    /** Belegart beim Aufbau, sonst die erste eingeschaltete */
    type: { type: String },
    _types: { state: true },
    _type: { state: true },
    _documents: { state: true },
    /** Gibt es zur aktuellen Belegart ein PDF? (Antwort des Backends) */
    _pdf: { state: true },
    _detail: { state: true },
    _busy: { state: true },
  };

  static styles = [
    PortalElement.baseStyles,
    PortalAccountElement.accountStyles,
    css`
      .tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin: 0 0 1.25rem;
        padding: 0;
        list-style: none;
      }
      .tab {
        font: inherit;
        cursor: pointer;
        padding: 0.5rem 1rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        background: var(--shop-input-bg, #f8f9fa);
        color: inherit;
      }
      .tab[aria-selected='true'] {
        font-weight: 600;
        background: var(--shop-primary, #0d6efd);
        border-color: var(--shop-primary, #0d6efd);
        color: var(--shop-primary-contrast, #fff);
      }
      .document {
        padding: 1.25rem;
        margin-bottom: 1rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        cursor: pointer;
      }
      .document-title {
        font-size: 1.05rem;
        font-weight: 600;
        margin: 0 0 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--shop-border-color, #dee2e6);
      }
      .document-line {
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
      .position.plain {
        grid-template-columns: minmax(0, 1fr);
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
      /* Knopf, der wie ein Link aussieht (Beleg öffnen) */
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
      .notes {
        white-space: pre-line;
        margin: 0 0 1.5rem;
      }
      form {
        margin-top: 1rem;
      }
    `,
  ];

  constructor() {
    super();
    this.apiUrl = apiUrl();
    this.type = '';
    this._types = [];
    this._type = '';
    this._documents = [];
    this._pdf = true;
    this._detail = null;
    this._busy = false;
  }

  async load() {
    const data = await apiRequest('personalDocuments', this.#typeParam(this._type || this.type));
    this._types = (data && data.types) || [];
    this._type = (data && data.type) || '';
    this._documents = (data && data.documents) || [];
    this._pdf = !data || data.pdf !== false;
    this._detail = null;
    // personalDocuments liefert keine Anrede.
    this.announce('', (data && data.name) || '');
  }

  #typeParam(type) {
    return type ? { type } : {};
  }

  // ------------------------------------------------------------ Aktionen

  async #switchType(type) {
    if (this._busy || type === this._type) return;
    this._busy = true;
    this._error = '';
    try {
      const data = await apiRequest('personalDocuments', { type });
      this._type = (data && data.type) || type;
      this._types = (data && data.types) || this._types;
      this._documents = (data && data.documents) || [];
      this._pdf = !data || data.pdf !== false;
      this._detail = null;
    } catch (error) {
      this._error = t((error && error.code) || 'PORTAL_API_ERROR');
      this._state = 'error';
    } finally {
      this._busy = false;
    }
  }

  /**
   * Die ganze Karte ist anklickbar (bequem mit der Maus), die Ueberschrift
   * ist zusaetzlich ein echter Knopf (erreichbar mit der Tastatur). Ein
   * role="button" auf der Karte waere falsch: darin steckt mit dem
   * Download-Formular schon ein Bedienelement — deshalb wird gefragt, ob der
   * Klick ueberhaupt der Karte galt.
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
      this._detail = await apiRequest('personalDocument', { type: this._type, id });
    } catch (error) {
      this._error = t((error && error.code) || 'PORTAL_API_ERROR');
      this._state = 'error';
    } finally {
      this._busy = false;
    }
  }

  // -------------------------------------------------------------- Anzeige

  #label(key) {
    return t(`documents.${key}.${this._type}`);
  }

  #hasPdf() {
    return this._pdf;
  }

  #downloadForm(id) {
    if (!this.#hasPdf()) return nothing;
    return html`
      <form action=${`${this.apiUrl}?action=downloadDocument`} method="post" part="download">
        <input type="hidden" name="type" value=${this._type} />
        <input type="hidden" name="id" value=${id} />
        <input type="hidden" name="content-type" value="application/pdf" />
        <button class=${this.cls('button')} type="submit">${t('documents.download')}</button>
      </form>
    `;
  }

  #statusLine(status) {
    if (!status) return nothing;
    return html`
      <div class="document-line" part="status">
        ${t('documents.status')}: <strong>${t(`documents.status.${status}`)}</strong>
      </div>
    `;
  }

  /**
   * Lieferstatus einer Shop-Bestellung (dev/shop-bestellstatus.md) — das
   * Backend liefert ihn nur für HugoShop-Rechnungen und nur bei
   * eingeschaltetem shop_delivery_status_show; sonst steht hier nichts.
   */
  #deliveryStatusLine(status) {
    if (!status) return nothing;
    return html`
      <div class="document-line" part="delivery-status">
        ${t('invoice.deliveryStatus')}: <strong>${t(`invoice.deliveryStatus.${status}`)}</strong>
      </div>
    `;
  }

  #amountLine(document) {
    if (document.amount === null || document.amount === undefined) return nothing;
    return html`
      <div class="document-line">
        <strong>${t('documents.total')}:</strong>
        <strong>${formatPrice(document.amount)} ${document.currency}</strong>
        <span class=${this.cls('muted')}>${t('documents.grossSuffix')}</span>
      </div>
    `;
  }

  #renderTabs() {
    if (this._types.length < 2) return nothing;
    return html`
      <div class="tabs" role="tablist" part="tabs">
        ${this._types.map(
          (type) => html`
            <button
              class="tab"
              part="tab"
              role="tab"
              type="button"
              aria-selected=${type === this._type ? 'true' : 'false'}
              ?disabled=${this._busy}
              @click=${() => this.#switchType(type)}
            >
              ${t(`documents.type.${type}`)}
            </button>
          `
        )}
      </div>
    `;
  }

  #renderList() {
    if (!this._documents.length) {
      const text = t('documents.empty').replace('{type}', t(`documents.type.${this._type}`));
      return html`<p class=${this.cls('muted')} part="empty">${text}</p>`;
    }
    return html`
      <div part="documents">
        ${repeat(
          this._documents,
          (document) => document.id,
          (document) => html`
            <div
              class="document"
              part="document"
              @click=${(event) => this.#cardClick(event, document.id)}
            >
              <div class="document-title">
                <button class="link-button" @click=${() => this.#open(document.id)}>
                  ${this.#label('one')} ${document.number} ${t('documents.from')} ${formatDate(document.date)}
                </button>
              </div>
              <div class="document-line ${this.cls('muted')}">
                ${t('documents.positions')}: ${document.positions}
              </div>
              ${this.#statusLine(document.status)}
              ${this.#deliveryStatusLine(document.delivery_status)}
              ${this.#amountLine(document)}
              ${this.#downloadForm(document.id)}
            </div>
          `
        )}
      </div>
    `;
  }

  #renderPosition(position, currency, withPrices) {
    const priced = withPrices && position.sellprice !== null && position.sellprice !== undefined;
    return html`
      <div class="position ${position.thumbnail_url ? '' : 'plain'}" part="position">
        ${position.thumbnail_url
          ? html`<img
              class="thumb"
              part="thumb"
              src=${sameOriginHref(position.thumbnail_url)}
              alt=""
              loading="lazy"
            />`
          : nothing}
        <div>
          <h3>
            ${position.product_url
              ? html`<a href=${sameOriginHref(position.product_url)}>${position.description}</a>`
              : position.description}
          </h3>
          <div class="document-line">
            <strong>${t('documents.quantity')}:</strong> ${position.qty}
            ${position.unit ? html` ${position.unit}` : nothing}
          </div>
          ${position.reason
            ? html`<div class="document-line"><strong>${t('documents.reason')}:</strong> ${position.reason}</div>`
            : nothing}
          ${position.delivery_term
            ? html`<div class="document-line ${this.cls('muted')}">${position.delivery_term}</div>`
            : nothing}
          ${priced
            ? html`
                <div class="document-line">
                  <strong>${t('documents.price')}:</strong>
                  ${formatPrice(position.linetotal)} ${currency}*
                  <span class=${this.cls('muted')}>
                    (${formatPrice(position.sellprice)} ${currency}* / ${t('documents.perUnit')})
                  </span>
                </div>
              `
            : nothing}
        </div>
      </div>
    `;
  }

  #renderDetail() {
    const detail = this._detail;
    const document = detail.document || {};
    const shipping = detail.shipping || {};
    const positions = detail.positions || [];
    const withPrices = document.amount !== null && document.amount !== undefined;

    return html`
      <div class="actions" style="margin-top:0; margin-bottom:1rem">
        <button
          class=${this.cls('button')}
          part="back"
          @click=${() => {
            this._detail = null;
          }}
        >
          ${t('documents.back')}
        </button>
      </div>

      <div class="detail-head">
        <div>
          <div class="section" part="section">${this.#label('one')} ${document.number}</div>
          <dl class="facts">
            <dt>${t('documents.date')}</dt>
            <dd>${formatDate(document.date)}</dd>
            ${document.reqdate
              ? html`<dt>${this.#label('reqdate')}</dt>
                  <dd>${formatDate(document.reqdate)}</dd>`
              : nothing}
            ${document.cusordnumber
              ? html`<dt>${t('documents.customerReference')}</dt>
                  <dd>${document.cusordnumber}</dd>`
              : nothing}
            ${withPrices
              ? html`<dt>${t('documents.total')}</dt>
                  <dd>${formatPrice(document.amount)} ${document.currency}</dd>`
              : nothing}
            ${document.status
              ? html`<dt>${t('documents.status')}</dt>
                  <dd part="status">${t(`documents.status.${document.status}`)}</dd>`
              : nothing}
            ${document.delivery_status
              ? html`<dt>${t('invoice.deliveryStatus')}</dt>
                  <dd part="delivery-status">${t(`invoice.deliveryStatus.${document.delivery_status}`)}</dd>`
              : nothing}
          </dl>
          ${this.#downloadForm(document.id)}
        </div>
        <div>
          <div class="section" part="section">${t('documents.deliveryAddress')}</div>
          <div>${shipping.name}</div>
          <div>${shipping.street}</div>
          <div>${cityLine(shipping)}</div>
        </div>
      </div>

      ${document.notes ? html`<p class="notes" part="notes">${document.notes}</p>` : nothing}

      ${repeat(
        positions,
        (position) => `${position.parts_id}-${position.runningnumber}`,
        (position) => this.#renderPosition(position, document.currency, withPrices)
      )}

      ${withPrices ? html`<div class="note ${this.cls('muted')}">${t('documents.grossNote')}</div>` : nothing}
    `;
  }

  renderAccount() {
    if (this._detail) return this.#renderDetail();
    return html`${this.#renderTabs()}${this.#renderList()}`;
  }
}

customElements.define('portal-account-documents', PortalAccountDocuments);
