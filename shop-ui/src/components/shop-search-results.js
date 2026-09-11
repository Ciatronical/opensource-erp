import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { ShopElement } from '../core/base.js';
import { apiRequest } from '../core/api.js';
import { t } from '../core/i18n.js';
import { sameOriginHref, searchTerms } from '../core/links.js';

/**
 * <shop-search-results></shop-search-results>
 *
 * Die Trefferliste auf /suchergebnisse/. Ersetzt moreSearchResults(),
 * fullSearch() und initSearchSlot() aus js/shopwindow/search.js sowie die
 * beiden Modul-Skripte im Inhalt der Seite.
 *
 * ZWEI STUFEN, wie bisher: `moreSearchResults` holt elf Treffer. Sind es mehr
 * als zehn, gibt es einen Knopf, der mit `fullSearch` dreissig weitere ab
 * Position elf nachlaedt und anhaengt.
 *
 * NICHT UEBERNOMMEN
 *  - `a.innerHTML += '<img src="' + item.image + '" alt="' + item.description
 *    + '">…<h2>' + item.description + '</h2>'`. Beschreibung und Bildpfad
 *    kommen aus der Datenbank und wurden als HTML ausgefuehrt.
 *  - `alert()` im Fehlerfall.
 *  - Kein Ladehinweis: zwischen Aufruf und Antwort stand die Seite leer da.
 *  - initSearchSlot() schrieb den Suchbegriff per document.getElementById in
 *    das Kopf-Suchfeld. Das macht jetzt <shop-search> selbst.
 */
export class ShopSearchResults extends ShopElement {
  static properties = {
    terms: { type: String },
    heading: { type: String },
    _items: { state: true },
    _state: { state: true },
    _error: { state: true },
    _more: { state: true },
    _busy: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      .count {
        margin: 0 0 1.5rem;
      }
      ul {
        list-style: none;
        margin: 0;
        padding: 0;
      }
      li {
        margin-bottom: 0.75rem;
      }
      a.hit {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
        gap: 1.5rem;
        align-items: center;
        padding: 1rem 1.5rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        color: inherit;
        text-decoration: none;
      }
      a.hit:hover {
        background: var(--shop-hover-surface, #f8f9fa);
      }
      @media (max-width: 40rem) {
        a.hit {
          grid-template-columns: minmax(0, 1fr);
        }
      }
      img {
        max-width: 100%;
        height: auto;
      }
      .title {
        margin: 0 0 0.5rem;
        font-size: 1.25rem;
      }
      .trail {
        margin: 0;
      }
      .more {
        text-align: center;
        margin-top: 1.5rem;
      }
    `,
  ];

  constructor() {
    super();
    this.terms = '';
    this.heading = '';
    this._items = [];
    this._state = 'loading';
    this._error = '';
    this._more = false;
    this._busy = false;
  }

  connectedCallback() {
    super.connectedCallback();
    if (!this.terms) this.terms = searchTerms(window.location.search);
    this.#load();
  }

  async #load() {
    if (!this.terms) {
      this._state = 'empty';
      return;
    }
    this._state = 'loading';
    this._error = '';
    try {
      const data = await apiRequest('moreSearchResults', { terms: this.terms });
      const items = Array.isArray(data) ? data : [];
      this._items = items;
      // Elf angefragt: mehr als zehn heisst, es gibt noch welche.
      this._more = items.length > 10;
      this._state = items.length ? 'ready' : 'empty';
    } catch (error) {
      this._error = t(error.code || 'SHOP_API_ERROR');
      this._state = 'error';
    }
  }

  async #loadMore() {
    if (this._busy) return;
    this._busy = true;
    try {
      const data = await apiRequest('fullSearch', { terms: this.terms });
      const items = Array.isArray(data) ? data : [];
      this._items = [...this._items, ...items];
      this._more = false;
    } catch (error) {
      this._error = t(error.code || 'SHOP_API_ERROR');
    } finally {
      this._busy = false;
    }
  }

  render() {
    if (this._state === 'loading') {
      return html`<p class=${this.cls('muted')} role="status">${t('results.loading')}</p>`;
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
    if (this._state === 'empty') {
      return html`<div class=${this.cls('alertInfo')} role="status">${t('search.none')}</div>`;
    }

    return html`
      ${this.heading ? html`<h2 class=${this.cls('heading')}>${this.heading}</h2>` : nothing}
      <p class="count ${this.cls('muted')}">${t('results.found')}: ${this._items.length}</p>
      ${this._error
        ? html`<div class=${this.cls('alertError')} role="alert">${this._error}</div>`
        : nothing}
      <ul part="results">
        ${repeat(
          this._items,
          (item, index) => `${item.partnumber || ''}-${index}`,
          (item) => this.#renderHit(item),
        )}
      </ul>
      ${this._more
        ? html`
            <div class="more">
              <button
                type="button"
                class=${this.cls('buttonPrimary')}
                ?disabled=${this._busy}
                @click=${() => this.#loadMore()}
              >${this._busy ? t('results.loading') : t('search.more')}</button>
            </div>
          `
        : nothing}
    `;
  }

  #renderHit(item) {
    const description = String(item.description || '');
    // partnumber, dann die Brotkrumen — dieselbe Zeile wie bisher.
    const trail = [item.partnumber, ...(item.breadcrumbs || [])].filter(Boolean).join(' | ');
    return html`
      <li>
        <a class="hit" part="hit" href=${sameOriginHref(item.hyperlink)}>
          <span>
            ${item.image
              ? html`<img src=${item.image} alt=${description} loading="lazy">`
              : nothing}
          </span>
          <span>
            <h3 class="title">${description}</h3>
            <p class="trail ${this.cls('muted')}">${trail}</p>
          </span>
        </a>
      </li>
    `;
  }
}

customElements.define('shop-search-results', ShopSearchResults);
