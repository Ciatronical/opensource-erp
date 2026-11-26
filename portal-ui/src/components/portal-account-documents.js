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
 *
 * Mit LxCars trägt ein Auftrag Bilder (images): die Fotos, die die Werkstatt
 * am Auftrag aufgenommen und für den Kunden freigegeben hat. Die Liste nennt
 * ihre Anzahl, die Ansicht zeigt sie als Kacheln; ein Klick öffnet das Bild
 * groß. Die Bilder lädt der Browser selbst per GET über
 * /portal-api/?action=portalOrderImage — das Backend prüft bei jedem Abruf
 * Kunde und Freigabe.
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
    /** Nummer des groß gezeigten Bildes in _detail.images, null = keins */
    _viewer: { state: true },
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
      /* Bilder am Auftrag (LxCars) */
      .images {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 9rem), 1fr));
        gap: 0.75rem;
        margin-bottom: 2rem;
      }
      .image {
        margin: 0;
      }
      .image-button {
        display: block;
        width: 100%;
        padding: 0;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        background: var(--shop-input-bg, #f8f9fa);
        overflow: hidden;
        cursor: zoom-in;
      }
      .image-button img {
        display: block;
        width: 100%;
        aspect-ratio: 1;
        object-fit: cover;
      }
      .image figcaption {
        margin-top: 0.35rem;
        font-size: 0.9rem;
        overflow-wrap: anywhere;
      }
      .viewer {
        padding: 0;
        border: 0;
        background: transparent;
        color: #fff;
        max-width: 100vw;
        max-height: 100vh;
        overflow: visible;
      }
      .viewer::backdrop {
        background: rgba(0, 0, 0, 0.88);
      }
      .viewer figure {
        margin: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.75rem;
      }
      .viewer img {
        display: block;
        max-width: 94vw;
        max-height: 76vh;
        border-radius: var(--shop-radius, 0.375rem);
      }
      .viewer figcaption {
        max-width: 94vw;
        text-align: center;
        overflow-wrap: anywhere;
      }
      .viewer-bar {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.75rem;
      }
      .viewer-button {
        font: inherit;
        font-size: 1.5rem;
        line-height: 1;
        min-width: 2.75rem;
        min-height: 2.75rem;
        border: 1px solid rgba(255, 255, 255, 0.6);
        border-radius: 999px;
        background: rgba(0, 0, 0, 0.4);
        color: #fff;
        cursor: pointer;
      }
      .viewer-count {
        min-width: 4rem;
        text-align: center;
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
    this._viewer = null;
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

  // ------------------------------------------------- Bilder am Auftrag

  #images() {
    return (this._detail && this._detail.images) || [];
  }

  #imageUrl(id, thumb) {
    const sep = this.apiUrl.includes('?') ? '&' : '?';
    return `${this.apiUrl}${sep}action=portalOrderImage&id=${encodeURIComponent(id)}${thumb ? '&thumb=1' : ''}`;
  }

  /**
   * Die Großansicht ist ein <dialog>: der Browser hält den Fokus darin, Esc
   * schließt, und der Rest der Seite ist währenddessen nicht bedienbar.
   */
  async #openViewer(index) {
    this._viewer = index;
    await this.updateComplete;
    const dialog = this.renderRoot.querySelector('dialog.viewer');
    if (dialog && !dialog.open) dialog.showModal();
  }

  #closeViewer() {
    const dialog = this.renderRoot.querySelector('dialog.viewer');
    if (dialog && dialog.open) dialog.close();
    this._viewer = null;
  }

  #stepViewer(step) {
    const count = this.#images().length;
    if (this._viewer === null || count < 2) return;
    this._viewer = (this._viewer + step + count) % count;
  }

  #viewerKey(event) {
    if (event.key === 'ArrowLeft') this.#stepViewer(-1);
    if (event.key === 'ArrowRight') this.#stepViewer(1);
  }

  #renderImages() {
    const images = this.#images();
    if (!images.length) return nothing;
    return html`
      <div class="section" part="section">${t('documents.images')}</div>
      <div class="images" part="images">
        ${repeat(
          images,
          (image) => image.id,
          (image, index) => html`
            <figure class="image" part="image">
              <button
                class="image-button"
                type="button"
                aria-label=${image.caption || t('documents.imageOpen')}
                title=${t('documents.imageOpen')}
                @click=${() => this.#openViewer(index)}
              >
                <img src=${this.#imageUrl(image.id, true)} alt=${image.caption || ''} loading="lazy" />
              </button>
              ${image.caption ? html`<figcaption>${image.caption}</figcaption>` : nothing}
            </figure>
          `
        )}
      </div>
      ${this.#renderViewer(images)}
    `;
  }

  #renderViewer(images) {
    const image = this._viewer === null ? null : images[this._viewer];
    return html`
      <dialog
        class="viewer"
        part="viewer"
        @close=${() => {
          this._viewer = null;
        }}
        @click=${(event) => {
          // Klick neben das Bild (auf den Dialog selbst) schließt
          if (event.target === event.currentTarget) this.#closeViewer();
        }}
        @keydown=${(event) => this.#viewerKey(event)}
      >
        ${image
          ? html`
              <figure>
                <img src=${this.#imageUrl(image.id, false)} alt=${image.caption || ''} />
                ${image.caption ? html`<figcaption>${image.caption}</figcaption>` : nothing}
                <div class="viewer-bar">
                  ${images.length > 1
                    ? html`
                        <button
                          class="viewer-button"
                          type="button"
                          aria-label=${t('documents.imagePrev')}
                          title=${t('documents.imagePrev')}
                          @click=${() => this.#stepViewer(-1)}
                        >
                          ‹
                        </button>
                        <span class="viewer-count">${this._viewer + 1} / ${images.length}</span>
                        <button
                          class="viewer-button"
                          type="button"
                          aria-label=${t('documents.imageNext')}
                          title=${t('documents.imageNext')}
                          @click=${() => this.#stepViewer(1)}
                        >
                          ›
                        </button>
                      `
                    : nothing}
                  <button
                    class="viewer-button"
                    type="button"
                    aria-label=${t('documents.imageClose')}
                    title=${t('documents.imageClose')}
                    @click=${() => this.#closeViewer()}
                  >
                    ×
                  </button>
                </div>
              </figure>
            `
          : nothing}
      </dialog>
    `;
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
              ${Number(document.images) > 0
                ? html`<div class="document-line ${this.cls('muted')}" part="image-count">
                    ${t('documents.images')}: ${document.images}
                  </div>`
                : nothing}
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
            this._viewer = null;
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

      ${this.#renderImages()}

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
