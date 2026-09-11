import { html, css, nothing } from 'lit';
import { repeat } from 'lit/directives/repeat.js';
import { ShopElement } from '../core/base.js';
import { apiRequest } from '../core/api.js';
import { t } from '../core/i18n.js';
import { sameOriginHref, searchTerms } from '../core/links.js';

/**
 * <shop-search results-url="/suchergebnisse/"></shop-search>
 *
 * Das Suchfeld im Seitenkopf samt Vorschlagsliste. Ersetzt
 * partials/header/search.html und initHeaderSearch() aus js/shopwindow.js.
 *
 * WAS ANDERS IST
 *  - Kein innerHTML mehr. Die alte Liste baute jeden Eintrag als
 *    '<strong>' + item.description.substring(…) + '</strong>' + … zusammen —
 *    die Beschreibung kommt aus der Datenbank und wurde damit als HTML
 *    ausgefuehrt.
 *  - Die Hervorhebung sitzt an der Fundstelle. Vorher wurden stur die ersten
 *    n Zeichen fett gesetzt, wobei n die Laenge des Suchworts war; die Suche
 *    trifft aber irgendwo im Text ("bit" in "Bohrer mit Bit").
 *  - Entprellt. Vorher ging pro Tastendruck eine Anfrage an /shop-api/.
 *  - Tastatur und Vorlesehilfen: role="combobox" mit aria-activedescendant,
 *    Escape schliesst. Vorher war die Liste ein <div> ohne Rolle.
 *  - Ein Fehler landete in einem alert(). Jetzt steht er in der Liste.
 *
 * ZU DEN ZIELEN: fastSearch liefert `hyperlink` als vollstaendige Adresse mit
 * Domain (https://sonic24.de/produkt/…). Auf einer anderen Instanz — der
 * Entwicklungsseite etwa — fuehrt der Vorschlag damit aus dem Shop heraus.
 * Deshalb wird bei fremder Herkunft nur der Pfad uebernommen.
 */
export class ShopSearch extends ShopElement {
  static properties = {
    resultsUrl: { type: String, attribute: 'results-url' },
    placeholder: { type: String },
    buttonLabel: { type: String, attribute: 'button-label' },
    info: { type: String },
    minLength: { type: Number, attribute: 'min-length' },
    autoFocus: { type: String, attribute: 'auto-focus' },
    _items: { state: true },
    _open: { state: true },
    _active: { state: true },
    _message: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      .info {
        display: block;
        margin-bottom: 0.5rem;
      }
      .group {
        position: relative;
        display: flex;
      }
      .group input {
        flex: 1 1 auto;
        min-width: 0;
      }
      .icon {
        display: flex;
        align-items: center;
        padding: 0 0.75rem;
        border: 1px solid var(--shop-border-color, #dee2e6);
        border-right: 0;
        border-radius: var(--shop-radius, 0.375rem) 0 0 var(--shop-radius, 0.375rem);
        background: var(--shop-muted-surface, #f8f9fa);
      }
      .group input {
        border-radius: 0;
      }
      .group button {
        border-radius: 0 var(--shop-radius, 0.375rem) var(--shop-radius, 0.375rem) 0;
      }
      /* Entspricht .search-autocomplete-items aus theme.css — die Regel gilt
         im ShadowRoot nicht mehr, also steht sie hier. */
      ul.list {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 99;
        max-height: 360px;
        overflow-y: auto;
        margin: 0;
        padding: 0;
        list-style: none;
        background: var(--shop-surface, #fff);
        border: 1px solid var(--shop-border-color, #d4d4d4);
        border-top: none;
      }
      li[role='option'] {
        padding: 8px;
        cursor: pointer;
      }
      li[role='option']:hover,
      li[aria-selected='true'] {
        background-color: var(--shop-hover-surface, #e9e9e9);
      }
      li.plain {
        padding: 8px;
      }
      .more {
        font-weight: 600;
      }
    `,
  ];

  #timer = null;
  #outside = null;
  #sequence = 0;

  constructor() {
    super();
    this.resultsUrl = '/suchergebnisse/';
    this.placeholder = '';
    this.buttonLabel = '';
    this.info = '';
    this.minLength = 1;
    this.autoFocus = 'false';
    this._items = [];
    this._open = false;
    this._active = -1;
    this._message = '';
  }

  connectedCallback() {
    super.connectedCallback();
    // Klick ausserhalb schliesst die Liste. composedPath() sieht durch die
    // Shadow-Grenze hindurch; ohne das waere jeder Klick "ausserhalb".
    this.#outside = (event) => {
      if (!event.composedPath().includes(this)) this.#close();
    };
    document.addEventListener('click', this.#outside);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    document.removeEventListener('click', this.#outside);
    clearTimeout(this.#timer);
  }

  firstUpdated() {
    // Auf der Ergebnisseite steht der Suchbegriff in der Adresse und gehoert
    // ins Feld. Das machte bisher initSearchSlot() aus search.js ueber
    // document.getElementById('search-input') — im ShadowRoot findet das
    // nichts mehr.
    const terms = searchTerms(window.location.search);
    const input = this.$('search-input');
    if (terms && input) input.value = terms;

    if (this.autoFocus === 'true') {
      if (input) input.focus();
    }
  }

  // ----------------------------------------------------------------- Daten

  #query(terms) {
    clearTimeout(this.#timer);
    this.#timer = setTimeout(() => this.#send(terms), 250);
  }

  async #send(terms) {
    // Antworten koennen sich ueberholen; nur die zuletzt gestellte zaehlt.
    const mine = ++this.#sequence;
    try {
      const data = await apiRequest('fastSearch', { terms });
      if (mine !== this.#sequence) return;
      this._items = Array.isArray(data) ? data : [];
      this._message = '';
      this._active = -1;
      this._open = true;
    } catch (error) {
      if (mine !== this.#sequence) return;
      this._items = [];
      this._message = t(error.code || 'SHOP_API_ERROR');
      this._open = true;
    }
  }

  #close() {
    this._open = false;
    this._active = -1;
  }

  get #terms() {
    const input = this.$('search-input');
    return input ? input.value.trim() : '';
  }

  /** Ziel der Ergebnisseite. */
  #resultsHref(terms) {
    return `${this.resultsUrl}?terms=${encodeURIComponent(terms)}#focus`;
  }


  // --------------------------------------------------------------- Bedienung

  #onInput(event) {
    const value = event.target.value.trim();
    this._message = '';
    if (value.length < Math.max(1, this.minLength)) {
      clearTimeout(this.#timer);
      this.#sequence++;
      this._items = [];
      this.#close();
      return;
    }
    this.#query(value);
  }

  #onKeydown(event) {
    const total = this._items.length + (this._items.length ? 1 : 0);
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (!this._open || !total) return;
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      if (this._active < 0) this._active = step > 0 ? 0 : total - 1;
      else this._active = (this._active + step + total) % total;
      return;
    }
    if (event.key === 'Escape') {
      this.#close();
      return;
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      this.#choose();
    }
  }

  #choose() {
    if (this._open && this._active > -1) {
      if (this._active < this._items.length) {
        this.#open(sameOriginHref(this._items[this._active].hyperlink));
        return;
      }
      this.#submit();
      return;
    }
    this.#submit();
  }

  #open(href) {
    if (href) window.location.href = href;
  }

  #submit() {
    const terms = this.#terms;
    if (!terms) {
      const input = this.$('search-input');
      if (input) input.placeholder = t('search.enterTerm');
      return;
    }
    window.location.href = this.#resultsHref(terms);
  }

  // ---------------------------------------------------------------- Ausgabe

  render() {
    // _open wird erst nach einer Antwort gesetzt — auch die Meldung
    // "keine Ergebnisse" gehoert sichtbar gemacht, wie bisher.
    const open = this._open;
    return html`
      ${this.info ? html`<span class="info ${this.cls('muted')}">${this.info}</span>` : nothing}
      <div class="group">
        <span class="icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/>
          </svg>
        </span>
        <input
          id="search-input"
          class=${this.cls('input')}
          part="input"
          type="search"
          role="combobox"
          aria-expanded=${open ? 'true' : 'false'}
          aria-controls="search-list"
          aria-autocomplete="list"
          aria-activedescendant=${open && this._active > -1 ? `option-${this._active}` : ''}
          aria-label=${t('search.label')}
          placeholder=${this.placeholder || t('search.placeholder')}
          autocomplete="off"
          @input=${this.#onInput}
          @keydown=${this.#onKeydown}
        >
        <button
          type="button"
          class=${this.cls('buttonSecondary')}
          part="button"
          @click=${() => this.#submit()}
        >${this.buttonLabel || t('search.submit')}</button>
        ${open ? this.#renderList() : nothing}
      </div>
    `;
  }

  #renderList() {
    if (this._message || !this._items.length) {
      return html`<ul class="list" id="search-list" role="listbox" aria-label=${t('search.label')}>
        <li class="plain ${this.cls('muted')}">${this._message || t('search.none')}</li>
      </ul>`;
    }
    const more = this._items.length;
    return html`
      <ul class="list" id="search-list" role="listbox" aria-label=${t('search.label')}>
        ${repeat(
          this._items,
          (item, index) => `${item.partnumber || ''}-${index}`,
          (item, index) => html`
            <li
              id="option-${index}"
              role="option"
              aria-selected=${this._active === index ? 'true' : 'false'}
              @click=${() => this.#open(sameOriginHref(item.hyperlink))}
            >${this.#highlight(String(item.description || ''))}</li>
          `,
        )}
        <li
          id="option-${more}"
          class="more"
          role="option"
          aria-selected=${this._active === more ? 'true' : 'false'}
          @click=${() => this.#submit()}
        >${t('search.more')}</li>
      </ul>
    `;
  }

  /** Fundstelle fett, der Rest normal — als Text, nicht als Markup. */
  #highlight(text) {
    const terms = this.#terms;
    if (!terms) return text;
    const at = text.toLowerCase().indexOf(terms.toLowerCase());
    if (at < 0) return text;
    return html`${text.slice(0, at)}<strong>${text.slice(at, at + terms.length)}</strong>${text.slice(at + terms.length)}`;
  }
}

customElements.define('shop-search', ShopSearch);
