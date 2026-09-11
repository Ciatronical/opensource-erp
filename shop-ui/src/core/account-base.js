import { html, css, nothing } from 'lit';
import { ShopElement } from './base.js';
import { getContext, refreshContext } from './api.js';
import { on, emit, SHOP_AUTH_CHANGED, SHOP_ACCOUNT_LOADED } from './bus.js';
import { t } from './i18n.js';

/**
 * Basisklasse der Konto-Seiten.
 *
 * WARUM DAS HIER LIEGT
 * --------------------
 * Jede der vier Funktionen in account.js hatte denselben Fehlerzweig:
 *
 *     }, function() {
 *         // Fixup wenn der Benutzer nicht eingeloggt ist oder ein
 *         // schwerer Fehler aufgetreten ist
 *         location.href = '/login/';
 *     })
 *
 * Also: jeder Fehler — abgelaufene Sitzung, Datenbank weg, Tippfehler im
 * SQL — endete auf der Anmeldeseite, ohne Hinweis, was los war. Der Grund
 * dafuer ist echt: das Backend meldet einen nicht angemeldeten Besucher
 * NICHT sauber. personalOverview/personalProfil/personalPayment/
 * accountAddresses haengen die customer_id direkt in den SQL-Text; ist sie
 * NULL, wird daraus "WHERE id = " und die Antwort lautet
 * SHOP_DATABASE_ERROR. personalOrders meldet CUSTOMER_NOT_FOUND. Aus dem
 * Fehlercode allein laesst sich "nicht angemeldet" also nicht ablesen.
 *
 * Deshalb wird hier zuerst getContext() gefragt — das meldet `account`
 * verlaesslich und liegt ohnehin im Cache (core/api.js). Ist niemand
 * angemeldet, wird die Konto-Aktion gar nicht erst abgeschickt: kein
 * SQL-Syntaxfehler im Server-Log fuer jeden anonymen Besucher, und der
 * Besucher sieht einen Hinweis statt eines Sprungs.
 *
 * Unterklassen implementieren load() und renderAccount().
 */
export class ShopAccountElement extends ShopElement {
  static properties = {
    loginUrl: { type: String, attribute: 'login-url' },
    heading: { type: String },
    // 'loading' | 'ready' | 'anonymous' | 'error'
    _state: { state: true },
    _error: { state: true },
  };

  static accountStyles = css`
    .section {
      font-size: 1.15rem;
      font-weight: 600;
      margin: 2.5rem 0 1rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid var(--shop-border-color, #dee2e6);
    }
    .section:first-child {
      margin-top: 0;
    }
    .hint {
      margin: 0 0 1rem;
    }
    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(100%, 18rem), 1fr));
      gap: 1.25rem;
    }
    .card {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      padding: 1.25rem;
      border: 1px solid var(--shop-border-color, #dee2e6);
      border-radius: var(--shop-radius, 0.375rem);
    }
    .card-title {
      font-size: 1.05rem;
      font-weight: 600;
      margin: 0 0 0.5rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid var(--shop-border-color, #dee2e6);
    }
    .card .actions {
      margin-top: auto;
      padding-top: 1rem;
      gap: 0.5rem;
    }
    .shop-message {
      margin-top: 1rem;
      max-width: var(--shop-form-width, 32rem);
    }
    .shop-message:empty {
      display: none;
    }
  `;

  #unsubscribe = null;

  constructor() {
    super();
    this.loginUrl = '/login/';
    this.heading = '';
    this._state = 'loading';
    this._error = '';
  }

  connectedCallback() {
    super.connectedCallback();
    // Nach An- oder Abmeldung in einem anderen Widget auf derselben Seite
    // stimmen die angezeigten Daten nicht mehr.
    //
    // refreshContext() und nicht direkt reload(): der gehaltene Kontext ist
    // in diesem Moment noch der alte. index.js frischt ihn zwar ebenfalls
    // auf, aber die Widgets haengen frueher am Ereignis (sie registrieren
    // sich beim Upgrade, also waehrend des Imports) und kaemen sonst mit
    // dem Stand von vor der Anmeldung zurueck.
    this.#unsubscribe = on(SHOP_AUTH_CHANGED, () => {
      refreshContext()
        .catch(() => {})
        .then(() => this.reload());
    });
    this.reload();
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    if (this.#unsubscribe) this.#unsubscribe();
    this.#unsubscribe = null;
  }

  /** Unterklasse: Daten holen und in eigene Felder legen. Darf werfen. */
  async load() {}

  /** Unterklasse: Markup fuer den Zustand 'ready'. */
  renderAccount() {
    return nothing;
  }

  async reload() {
    this._state = 'loading';
    this._error = '';
    try {
      const context = await getContext();
      if (!context || !context.account) {
        this._state = 'anonymous';
        return;
      }
      await this.load();
      this._state = 'ready';
    } catch (error) {
      // Sitzung koennte zwischen Seitenaufbau und Anfrage abgelaufen sein.
      // Nur wenn das nachweislich so ist, wird daraus 'anonymous' — bei
      // einem Netzfehler bliebe die Frage unbeantwortet, und dann ist der
      // urspruengliche Fehler die ehrlichere Auskunft.
      try {
        const context = await refreshContext();
        if (!context || !context.account) {
          this._state = 'anonymous';
          return;
        }
      } catch {
        /* Kontext nicht erreichbar — es bleibt beim urspruenglichen Fehler. */
      }
      this._error = t((error && error.code) || 'SHOP_API_ERROR');
      this._state = 'error';
    }
  }

  /** Meldet Name und Anrede an <shop-account-nav>. */
  announce(salutation, name) {
    emit(SHOP_ACCOUNT_LOADED, { salutation: salutation || '', name: name || '' });
  }

  render() {
    return html`
      ${this.heading
        ? html`<h2 class=${this.cls('heading')} part="heading">${this.heading}</h2>`
        : nothing}
      ${this._state === 'ready' ? this.renderAccount() : this.renderState()}
    `;
  }

  renderState() {
    if (this._state === 'loading') {
      return html`<p class=${this.cls('muted')} part="loading">${t('account.loading')}</p>`;
    }
    if (this._state === 'anonymous') {
      return html`
        <div part="anonymous">
          <p class="hint">${t('account.anonymous')}</p>
          <a class=${this.cls('buttonPrimary')} part="login-link" href=${this.loginUrl}>
            ${t('account.login')}
          </a>
        </div>
      `;
    }
    return html`
      <div class="shop-message" role="alert">
        <div class=${this.cls('alertError')} part="error">${this._error}</div>
      </div>
      <div class="actions">
        <button class=${this.cls('button')} part="retry" @click=${() => this.reload()}>
          ${t('account.retry')}
        </button>
      </div>
    `;
  }
}
