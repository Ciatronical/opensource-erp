import { html, css, nothing } from 'lit';
import { ShopElement } from '../core/base.js';
import { apiRequest, getContext, refreshContext, logout } from '../core/api.js';
import { on, emit, SHOP_AUTH_CHANGED, SHOP_CART_CHANGED } from '../core/bus.js';
import { t } from '../core/i18n.js';

/**
 * <shop-account-buttons login-url="/login/" …></shop-account-buttons>
 *
 * Die Schaltflaechen im Seitenkopf: anmelden/abmelden, registrieren/Konto und
 * der Warenkorb mit Zaehler. Ersetzt partials/header/account-buttons.html
 * sowie getContext() und logout() aus js/shopwindow.js.
 *
 * DAMIT ENDET DIE DOPPELTE ANFRAGE
 * Bisher fragten zwei Stellen denselben Sitzungskontext ab: shopwindow.js im
 * window-load-Handler fuer die Knoepfe, und das Bundle fuer die Widgets.
 * Beide schrieben ausserdem in #cart-count — deshalb gab es
 * legacy/header-cart.js, das die Antwort des Themes hinterher korrigierte.
 * Jetzt liest dieses Element den gehaltenen Kontext aus core/api.js und
 * folgt danach nur noch den Ereignissen.
 *
 * BESCHRIFTUNGEN UND ZIELE kommen als Attribute aus Hugo (i18n des Themes,
 * z.B. "personal-data-link"), damit die Wortwahl des Shops erhalten bleibt.
 * Fehlt ein Attribut, greifen die Texte des Bundles.
 *
 * FEHLERFALL: Das alte getContext() blendete bei einer fehlgeschlagenen
 * Anfrage *alle* Knoepfe aus — nach einem Netzfehler stand der Kopf ohne
 * Anmeldung, ohne Registrierung und ohne Warenkorb da. Hier bleibt es beim
 * abgemeldeten Zustand, der fuer die meisten Besucher ohnehin gilt.
 */
export class ShopAccountButtons extends ShopElement {
  static properties = {
    loginUrl: { type: String, attribute: 'login-url' },
    logoutUrl: { type: String, attribute: 'logout-url' },
    registerUrl: { type: String, attribute: 'register-url' },
    accountUrl: { type: String, attribute: 'account-url' },
    cartUrl: { type: String, attribute: 'cart-url' },
    loginLabel: { type: String, attribute: 'login-label' },
    logoutLabel: { type: String, attribute: 'logout-label' },
    registerLabel: { type: String, attribute: 'register-label' },
    accountLabel: { type: String, attribute: 'account-label' },
    cartLabel: { type: String, attribute: 'cart-label' },
    _account: { state: true },
    _count: { state: true },
    _busy: { state: true },
    _hint: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      ul {
        list-style: none;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin: 0;
        padding: 0;
      }
      li {
        position: relative;
      }
      a,
      button {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        white-space: nowrap;
      }
      svg {
        flex: none;
      }
      /* Das Theme setzt die Beschriftung kursiv (fst-italic). */
      .label {
        font-style: italic;
      }
      /* Ersetzt das Bootstrap-Popover des alten Knopfes — der brauchte
         bootstrap.bundle.js, das ein fremdes Theme nicht geladen haben muss. */
      .hint {
        position: absolute;
        top: calc(100% + 0.35rem);
        right: 0;
        z-index: 20;
        padding: 0.5rem 0.75rem;
        border-radius: var(--shop-radius, 0.375rem);
        border: 1px solid var(--shop-border-color, #dee2e6);
        background: var(--shop-surface, #fff);
        color: var(--shop-danger, #dc3545);
        font-weight: 600;
        white-space: nowrap;
      }
    `,
  ];

  #unsubscribe = [];
  #hintTimer = null;

  constructor() {
    super();
    this.loginUrl = '/login/';
    this.logoutUrl = '/logout/';
    this.registerUrl = '/registrieren/';
    this.accountUrl = '/persönliche-daten/';
    this.cartUrl = '/warenkorb/';
    this.loginLabel = '';
    this.logoutLabel = '';
    this.registerLabel = '';
    this.accountLabel = '';
    this.cartLabel = '';
    // Bis der Kontext da ist, gilt der abgemeldete Zustand — so stand es auch
    // im alten Markup (login-button sichtbar, logout-button display:none).
    this._account = false;
    this._count = 0;
    this._busy = false;
    this._hint = false;
  }

  connectedCallback() {
    super.connectedCallback();
    this.#unsubscribe.push(
      on(SHOP_AUTH_CHANGED, (event) => {
        const detail = event.detail || {};
        if (typeof detail.account === 'boolean') this._account = detail.account;
        else this.#read(refreshContext());
      }),
      on(SHOP_CART_CHANGED, (event) => {
        const detail = event.detail || {};
        if (typeof detail.count === 'number') this._count = detail.count;
      }),
    );
    this.#read(getContext());
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    for (const off of this.#unsubscribe) off();
    this.#unsubscribe = [];
    clearTimeout(this.#hintTimer);
  }

  async #read(pending) {
    try {
      const context = await pending;
      if (!context) return;
      this._account = !!context.account;
      this._count = Number(context.cart_pos_count) || 0;
    } catch {
      // Es bleibt beim abgemeldeten Zustand — siehe Klassenkommentar.
    }
  }

  async #logout() {
    if (this._busy) return;
    this._busy = true;
    try {
      await logout();
      emit(SHOP_AUTH_CHANGED, { account: false });
      window.location.href = this.logoutUrl;
    } catch (error) {
      // Das alte logout() zeigte hier ein alert(). Der Knopf bleibt bedienbar,
      // die Meldung steht dort, wo geklickt wurde.
      this._busy = false;
      this.#showHint(t(error.code || 'SHOP_API_ERROR'));
    }
  }

  #showHint(message) {
    clearTimeout(this.#hintTimer);
    this._hint = message;
    this.#hintTimer = setTimeout(() => {
      this._hint = false;
    }, 2500);
  }

  render() {
    return html`
      <ul part="buttons">
        <li>${this._account ? this.#logoutButton() : this.#link(this.loginUrl, this.loginLabel || t('header.login'), this.#unlockIcon(), 'buttonSecondary')}</li>
        <li>
          ${this._account
            ? this.#link(this.accountUrl, this.accountLabel || t('header.account'), this.#personIcon(), 'buttonSecondary')
            : this.#link(this.registerUrl, this.registerLabel || t('header.register'), this.#personIcon(), 'buttonSecondary')}
        </li>
        <li>${this.#cartButton()}</li>
      </ul>
    `;
  }

  #link(href, label, icon, role) {
    return html`
      <a class=${this.cls(role)} part="button" href=${href}>
        ${icon}<span class="label">${label}</span>
      </a>
    `;
  }

  #logoutButton() {
    return html`
      <button
        type="button"
        class=${this.cls('buttonSecondary')}
        part="button"
        ?disabled=${this._busy}
        @click=${() => this.#logout()}
      >
        ${this.#lockIcon()}<span class="label">${this.logoutLabel || t('header.logout')}</span>
      </button>
      ${this._hint ? html`<span class="hint" role="alert">${this._hint}</span>` : nothing}
    `;
  }

  /**
   * Voll: ein Link zum Warenkorb mit Anzahl. Leer: ein Knopf, der genau das
   * sagt. Dieselben zwei Zustaende wie bisher (#cart-button /
   * #empty-cart-button), nur nicht mehr ueber style.display geschaltet.
   */
  #cartButton() {
    const label = this.cartLabel || t('header.cart');
    if (this._count > 0) {
      return html`
        <a class=${this.cls('buttonPrimary')} part="button" href=${this.cartUrl}>
          ${this.#cartCheckIcon()}<span class="label">${label} (${this._count})</span>
        </a>
      `;
    }
    return html`
      <button
        type="button"
        class=${this.cls('buttonSecondary')}
        part="button"
        @click=${() => this.#showHint(t('header.cartEmpty'))}
      >
        ${this.#cartIcon()}<span class="label">${label} (0)</span>
      </button>
      ${this._hint ? html`<span class="hint" role="alert">${this._hint}</span>` : nothing}
    `;
  }

  // ---- Symbole, unveraendert aus account-buttons.html uebernommen ----
  //
  // Jedes Symbol ist EIN vollstaendiges Template. Vorher stand hier ein
  // gemeinsames #svg(paths) und die Pfade kamen als eigenes html`…` hinein —
  // Lit legt ein so eingesetztes Fragment aber als HTML an, nicht im
  // SVG-Namensraum. Die <path>-Elemente landeten damit als unbekannte
  // HTML-Knoten im <svg>: der Rahmen war da, zu sehen war nichts.
  // (Das Lupensymbol in <shop-search> steht aus demselben Grund am Stueck.)

  #unlockIcon() {
    return html`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M11 1a2 2 0 0 0-2 2v4a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h5V3a3 3 0 0 1 6 0v4a.5.5 0 0 1-1 0V3a2 2 0 0 0-2-2M3 8a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1z"/>
      </svg>
    `;
  }

  #lockIcon() {
    return html`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2m3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2M5 8h6a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1"/>
      </svg>
    `;
  }

  #personIcon() {
    return html`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/>
      </svg>
    `;
  }

  #cartCheckIcon() {
    return html`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M11.354 6.354a.5.5 0 0 0-.708-.708L8 8.293 6.854 7.146a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0z"/>
        <path d="M.5 1a.5.5 0 0 0 0 1h1.11l.401 1.607 1.498 7.985A.5.5 0 0 0 4 12h1a2 2 0 1 0 0 4 2 2 0 0 0 0-4h7a2 2 0 1 0 0 4 2 2 0 0 0 0-4h1a.5.5 0 0 0 .491-.408l1.5-8A.5.5 0 0 0 14.5 3H2.89l-.405-1.621A.5.5 0 0 0 2 1zm3.915 10L3.102 4h10.796l-1.313 7zM6 14a1 1 0 1 1-2 0 1 1 0 0 1 2 0m7 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/>
      </svg>
    `;
  }

  #cartIcon() {
    return html`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
        <path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .491.592l-1.5 8A.5.5 0 0 1 13 12H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5M3.102 4l1.313 7h8.17l1.313-7zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4m7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4m-7 1a1 1 0 1 1 0 2 1 1 0 0 1 0-2m7 0a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/>
      </svg>
    `;
  }
}

customElements.define('shop-account-buttons', ShopAccountButtons);
